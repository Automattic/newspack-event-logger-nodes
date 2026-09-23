<?php
/**
 * Stats Store
 *
 * The memcache schema for performance stats, expressed as one small key/value
 * API. Seventeen namespaces (`hourly`, `lb`, `lb_s`, `lb_h`, `urls`, `urls_h`,
 * `urlsrv`, `urlsrv_h`, `urlrank_s`, `urlrank_sh`, `urltoken`, `urlmap`,
 * `url`, `dim`, `url_dim`, `categories`, `url_cat`) live
 * under the per-partition prefix `evlog:p{N}:`, inside the
 * install scope Cache_Backend owns. `Flame_Builder_Node` produces every value
 * and `App\Performance_CI_Node` reads them for the dashboards.
 *
 * Stats live in memcache alone; nothing here writes durable state. The
 * `$mirror` and `$rehydrate` seams let a caller shadow them to a durable
 * partition without this schema knowing.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats storage using memcache.
 *
 * Keys are `evlog:p{N}:{namespace}[:...]`, so every flame-builder
 * partition owns a disjoint keyspace and readers fan one store out per
 * partition. A value is a plain array, string-keyed, but two of the entries
 * inside one are POSITIONAL and read through named constants: a stored URL row
 * (`ROW_*`) and a category (`CAT_*`).
 *
 * Retention runs at three lengths, one per table ROLE. Every aggregate
 * namespace expires at `ttl()`, the whole retention window. The per-URL blob
 * (`url`) is the high-volume one and takes `ttl_url_stats()`, a twenty-fourth
 * of that floored at an hour. A FINE `urls` or `urlsrv` bucket takes
 * `ttl_url_fine()`, its own read window, because `urls_h` and `urlsrv_h`
 * answer for it behind the recent tail.
 *
 * Bucketing is part of the key schema, so it lives here: `bucket_key()` is the
 * five-minute `Y-m-d-H-i` derivation every producer and reader shares, and
 * `retention_buckets()` is the window a reader enumerates. The `hourly`
 * namespace name misleads: its buckets are five minutes wide, like every other
 * bucketed namespace.
 *
 * Storage is a `Table_Node` per ROLE over one namespace (`evlog:p{N}`), so the
 * substrate owns key scoping and the backend handle. Reads and writes fail soft:
 * `Table_Node::table()` throws without a backing store, so the table is built
 * lazily behind that check and a missing backend yields `[]`, `null`, or `false`
 * — the dashboards render "no data" instead of an error. Keep it that way; the
 * SSE slot pool is deliberately the opposite, and unifying the two breaks its
 * rate limit.
 *
 * Only the per-URL table opts into the Table's accumulator tier, which holds
 * the aggregates `Flame_Builder_Node` is still folding; `backed_by()` hands
 * the other two the `$rehydrate` seam instead. `table()` carries why no table
 * takes both.
 *
 * Flushing is the substrate's one button (`Cache_Backend::rotate_salt()`),
 * which moves the install scope for every plugin at once; this keeps no salt
 * of its own. The scope is memoized per process, so a long-running worker
 * picks up a rotation when it restarts — which the flush handler triggers.
 */
class Stats_Store {

	/** Distinct category values kept per bucket; `Flame_Builder_Node` rolls the overflow into "Other". */
	public const MAX_CAT_VALUES           = 50;
	/**
	 * Categories a leaderboard bucket (`lb`, `lb_s`, `lb_h`) or a URL's
	 * profile keeps, the slowest first, the rest folded into "Other". A byte
	 * estimate caps it lower when the categories run wide.
	 */
	public const MAX_LB_CATEGORIES        = 200;
	/** Distinct values kept per global dimension bucket; see `dim_cap()`. */
	public const MAX_DIM_VALUES           = 20;

	/** Distinct values kept per per-URL dimension bucket. */
	public const MAX_URL_DIM_VALUES       = 10;
	/**
	 * Distinct reporting servers kept wherever the `server` axis is stored — the
	 * global dimension bucket, a URL's dimension bucket, and the URL index's
	 * server index alike. Set far above any fleet — five times the largest hub
	 * in evidence — so no real server folds; what it guards is `SERVER_NAME`
	 * under Apache's default `UseCanonicalName Off`, where the value is the
	 * client's Host header, and in the URL index every name is a set of keys.
	 */
	public const MAX_SERVER_VALUES        = 128;
	/**
	 * Bytes any one stored value may take, and what every byte cap derives
	 * from: memcached's 1,048,576-byte item limit less a margin for the key
	 * and the serializer's framing. Uncompressed, and under PHP's own
	 * `serialize()`, the larger of the two serializers, because nothing
	 * guarantees production compresses or runs igbinary. A producer caps
	 * before it writes; a refused set is never how a size is found.
	 */
	public const ITEM_BUDGET              = 900000;

	/**
	 * Bytes each stored part costs under each serializer, before the strings
	 * it carries: every byte cap estimates a value as these plus `strlen()`.
	 * Measured with twelve-digit counts, doubles at their longest spelling
	 * and no string repeated, which igbinary would store once; the rest is
	 * margin. `url_row` includes its hash key, `flame_node` its key in its
	 * parent's list, and `hook` one name's framing in a rule's hook list.
	 */
	private const OVERHEADS = [
		self::SERIALIZER_PHP      => [
			'url_row'     => 360,
			'lb_category' => 180,
			'lb_entry'    => 100,
			'flame_node'  => 130,
			'hook'        => 20,
		],
		self::SERIALIZER_IGBINARY => [
			'url_row'     => 160,
			'lb_category' => 56,
			'lb_entry'    => 44,
			'flame_node'  => 36,
			'hook'        => 8,
		],
	];

	/** PHP's own `serialize()`: the larger, and what a handle-less estimate assumes. */
	public const SERIALIZER_PHP = 'php';

	/** igbinary, as a memcached built with it may be configured to use. */
	public const SERIALIZER_IGBINARY = 'igbinary';

	/**
	 * The serializer `overhead()` estimates for, read once from
	 * `Core::$memd`'s `Memcached::OPT_SERIALIZER` and memoized here. Tests
	 * assign it to estimate for one serializer, and reset it to null.
	 *
	 * @var self::SERIALIZER_*|null
	 */
	public static ?string $serializer = null;
	/** Category time series, global or per server. */
	public const NS_CATEGORIES  = 'categories';
	/** Dimensional time series, global or per server. */
	public const NS_DIM         = 'dim';
	/** The dimension naming the reporting server — the axis the picker is built from. */
	public const DIM_SERVER     = 'server';

	/** Request totals per bucket; one key per partition. */
	public const NS_HOURLY      = 'hourly';
	/** Global leaderboard bucket. */
	public const NS_LB          = 'lb';
	/** Per-server leaderboard bucket. */
	public const NS_LB_S        = 'lb_s';

	/**
	 * The GLOBAL leaderboard's COARSE tier: `lb_h:{Y-m-d-H}`, one key an hour
	 * holding the same shape a fine bucket holds.
	 *
	 * The leaderboard is the heaviest read the dashboard makes — one category
	 * per hook, callback and plugin the site fires, 1,198 of them on a
	 * production hub, each with its own entry map, across 288 buckets and four
	 * partitions. Decision 17 already answers that shape for `urls`; this is the
	 * same answer for the same reason, and the readers ask at the same two
	 * resolutions. The per-SERVER tier keeps the fine path: a shard count is a
	 * constant the schema chooses, but the servers present in an hour cannot be
	 * enumerated from the keyspace.
	 */
	public const NS_LB_HOUR     = 'lb_h';
	/** Per-URL stats blob: flame tree and profiles. */
	public const NS_URL         = 'url';
	/**
	 * URL index bucket, one SERVER's rows sharded by the first hex digit of
	 * the url_hash: `urls:{server_key}:{shard}:{bucket}`. Per-server data has
	 * the server in the key, so a busy server's rows never compete with a
	 * quiet one's for a shard's cap. The bucket stays LAST, which is what lets
	 * `is_open_bucket()`, expiry and the durable read-through work off the key
	 * alone. Decision 1.
	 */
	public const NS_URLS        = 'urls';

	/**
	 * The URL index's server index: `urlsrv:{bucket}` => `{ server_key =>
	 * server_name }`, every server with rows in the bucket, reader or worker.
	 *
	 * The keyspace cannot list itself, so this is what an unscoped read, the
	 * fold, the probe and the ranker enumerate. Capped at `MAX_SERVER_VALUES`
	 * names: past that a new server's rows go to the `Other` server key
	 * (`admit_servers()`), so a bucket's keys stay bounded whatever Host
	 * headers arrive.
	 */
	public const NS_URLSRV      = 'urlsrv';

	/** The server index's COARSE tier, `urlsrv_h:{Y-m-d-H}`, folded beside `urls_h`. */
	public const NS_URLSRV_HOUR = 'urlsrv_h';

	/**
	 * The search index: `urltoken:{server_key}:{token}` => the hashes of every
	 * URL of one server whose path carries a token, or a token prefix,
	 * spelled so. TTL is the retention window, refreshed by every flush that
	 * names such a URL, so a live token stays and a dead one ages out.
	 */
	public const NS_URLTOKEN = 'urltoken';

	/** Candidates a search takes from the index before it falls back to the fold. */
	public const URL_SEARCH_MAX = 5000;

	/** Longest prefix the index files; a longer term is cut to it on both sides. */
	public const URL_TOKEN_PREFIX_MAX = 12;

	/**
	 * Shortest prefix the index files. A two-character prefix names most of a
	 * real site's URLs, so it saturates at once and narrows nothing the fold
	 * would not; a term token that short is answered by the fold instead.
	 */
	public const URL_TOKEN_PREFIX_MIN = 3;

	/**
	 * Shortest run of characters that counts as a WORD, filing or matching.
	 *
	 * One below `URL_TOKEN_PREFIX_MIN` deliberately: a two-character word is
	 * never FILED, because its one prefix is itself and that prefix names most
	 * of a real site, but `term_matches()` still matches it as a word when the
	 * fold answers the term — so a term of `/at/88` reaches its URLs through
	 * the fold rather than matching nothing at all.
	 */
	public const TERM_WORD_MIN = 2;

	/**
	 * What separates two words, as a character class. The FILING rule and the
	 * MATCHING rule are built from this one spelling, so a term cannot tokenize
	 * on one alphabet and match on another.
	 */
	private const TOKEN_SEP = '[^a-z0-9]';

	/** The one entry a token set holds once it passed `URL_SEARCH_MAX`: no hash spells so. */
	public const TOKEN_SATURATED = '*';

	/**
	 * The URL index's COARSE tier: `urls_h:{server_key}:{shard}:{Y-m-d-H}`, one
	 * key per server per shard per hour holding the same row shape as a fine
	 * bucket.
	 *
	 * The readers distinguish exactly two resolutions — the whole retention
	 * window, and the last complete hour — so five-minute buckets buy precision
	 * at the window's EDGE and nothing else. Behind the recent tail they read as
	 * hours, which at the 24-hour read ceiling is 13 + 23 keys per shard
	 * against 288.
	 */
	public const NS_URLS_HOUR   = 'urls_h';

	/**
	 * The writer's ranked top-N of one server's `urls` bucket, one list per
	 * `URL_SORTS` key and `URL_ORDERS` direction:
	 * `urlrank_s:{server_key}:{sort}:{order}:{bucket}`. Fine tier;
	 * `ROLE_URL_FINE`. No list spans servers: the site's is the merge of
	 * every server's, which `url_rank_window()` makes at read time.
	 */
	public const NS_URLRANK_S      = 'urlrank_s';
	/**
	 * The coarse tier of `urlrank_s`, folded with `urls_h`:
	 * `urlrank_sh:{server_key}:{sort}:{order}:{Y-m-d-H}`, beside each
	 * server's DONE marker, `urlrank_sh:done:{server_key}:{Y-m-d-H}`.
	 */
	public const NS_URLRANK_HOUR_S = 'urlrank_sh';

	/**
	 * How long a folded URL page is cached, and how often a bucket's ranked
	 * lists are rewritten. ONE constant, because it is one number: the reader
	 * looks this often, so ranking more often spends rankings nobody reads.
	 * Under traffic a ranked row lags live traffic by up to twice it plus
	 * `Flame_Builder_Node::FLUSH_INTERVAL_SEC`, the flush a ranking waits for.
	 * The flame builder flushes only from `fill()`, so on a partition that
	 * goes quiet a deferred ranking waits for its next record or the
	 * worker's stop.
	 */
	public const URL_PAGE_REFRESH_S = 60;

	/** Entries a fine-tier ranked list keeps. A page past this folds. */
	public const URL_RANK_N      = 200;
	/** Entries an hour-tier ranked list keeps: one list ranks twelve buckets. */
	public const URL_RANK_N_HOUR = 500;

	/** The `--sort` values `urls` accepts, and the names of the ranked lists. */
	public const URL_SORTS  = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'last_updated' ];
	/** The `--order` values `urls` accepts, and the directions each sort is ranked in. */
	public const URL_ORDERS = [ 'asc', 'desc' ];

	/**
	 * URL name table: `urlmap:{hash}` => `[ server_name, path ]`.
	 *
	 * The server is the one a hash's rows are filed under, which is what a
	 * hash-only read needs to find them; the path is the row's own
	 * `row_path()` against that server. `get_url_names()` joins the two back
	 * into the URL a reader displays.
	 */
	public const NS_URLMAP      = 'urlmap';

	/** Per-URL category time series. */
	public const NS_URL_CAT     = 'url_cat';
	/** Per-URL dimensional time series. */
	public const NS_URL_DIM     = 'url_dim';

	/** Key prefix under the install scope. */
	private const PREFIX_BASE  = 'evlog';

	/** Per-URL aggregates one accumulator bucket holds before it rotates. */
	private const URL_ACCUMULATOR_SIZE    = 1000;
	/** Accumulator buckets retained; capacity is roughly the product. */
	private const URL_ACCUMULATOR_BUCKETS = 5;

	/** Shortest retention window the stats keyspace works with, in seconds. */
	public const PREFIX_FLOOR = 3600;

	/**
	 * A stored DIMENSIONAL entry is positional, indexed by these — decision 18's
	 * shape, as `CAT_MS` below, on the third value to earn it. `{"c":29,"s":1.0,"m":1.0}`
	 * is 24 bytes of JSON where `[29,1,1]` is 8, across `dim`, `dim`-by-server
	 * and `url_dim` alike: seven dimensions per URL per five-minute bucket, and
	 * the mirror carries every frame. The names are the row's, `DIM_COUNT` beside
	 * `ROW_COUNT`, because they are the same three measurements, and the entry
	 * stays positional to the wire, so no `DIM_FIELD_NAMES` exists.
	 */
	public const DIM_COUNT       = 0;
	public const DIM_SUM_MS      = 1;
	public const DIM_SUM_PEAK_MB = 2;

	/** Summed fields of one dimensional value => whether it is a whole count. */
	public const DIM_SUMS = [ self::DIM_COUNT => true, self::DIM_SUM_MS => false, self::DIM_SUM_PEAK_MB => false ];

	/**
	 * A stored CATEGORY entry is positional, indexed by these — decision 18's
	 * shape on the second value dense enough to earn it. A category series
	 * spells every one of its entries once per five-minute bucket per scope,
	 * and the stats mirror carries the whole window: `{"t":913.207,"c":47,"n":11}`
	 * is 30 bytes of JSON where `[913.207,47,11]` is 15.
	 *
	 * **Never a bare index**, exactly as `ROW_COUNT` and its neighbours below.
	 *
	 * There is no `CAT_FIELD_NAMES` because nothing names these: every field
	 * ADDS, so `CAT_SUMS` is already the whole index set, and
	 * `Performance_CI_Node::compact_category_series()` — the one
	 * storage/display boundary the series has — emits a positional wire row
	 * too. A name table with no naming site is a second shape waiting to drift.
	 *
	 * `CAT_MS` is milliseconds of wall time, `CAT_CALLS` the events fired, and
	 * `CAT_REQUESTS` the requests the category appeared in. The reserved
	 * `total` row holds the request's own wall time instead, so its
	 * `CAT_REQUESTS` is a request count.
	 */
	public const CAT_MS       = 0;
	public const CAT_CALLS    = 1;
	public const CAT_REQUESTS = 2;

	/**
	 * Decimal places `CAT_MS` is stored at. These are milliseconds a chart draws
	 * as seconds-per-second or as a mean, so a microsecond is already past
	 * anything rendered, and a full-precision double spends 12 more bytes per
	 * entry saying it.
	 */
	public const CAT_MS_DECIMALS = 3;

	/** Summed fields of one category => whether it is a whole count. */
	public const CAT_SUMS = [ self::CAT_MS => false, self::CAT_CALLS => true, self::CAT_REQUESTS => true ];

	/**
	 * The synthetic key every capped namespace rolls its overflow into.
	 *
	 * Decision 1. Safe as a URL row key: a url_hash is 12 hex characters.
	 */
	public const OTHER_KEY = 'Other';

	/** The server a request whose producer named none is filed under. */
	public const UNKNOWN_SERVER = 'Unknown';

	/** The overflow row's worker half — see `other_key()`. */
	public const OTHER_WORKER_KEY = 'Other:worker';

	/** Shards the URL index is spread across — one per hex digit of the hash. */
	public const URL_SHARDS     = 16;

	/**
	 * What makes a shard token name WORKER traffic: `urls:w3:{bucket}`.
	 *
	 * Cron, WP-CLI and job requests are a separate population, not a predicate
	 * over one — the table excludes them by default, so a shared index makes
	 * every ordinary read carry rows it then discards, and a URL a job also
	 * visits leaves that table carrying its reader requests with it. The
	 * shard token is opaque to every key builder below it, so the split needs
	 * no namespace of its own and no second read plan.
	 */
	public const WORKER_SHARD_PREFIX = 'w';

	/**
	 * A STORED URL row is a positional array, indexed by these — the shape
	 * `Message` uses and for the same reason. `serialize()` writes every key
	 * NAME into every row, so `s:11:"timed_count";` costs 18 bytes to say what
	 * `i:1;` says in 4, and a read pays that once per row per field. Measured
	 * on live rows: 672 B/row named against 398 positional, 40.9%.
	 *
	 * **Never a bare index.** These constants are what buy back the
	 * readability the shape spends, and a raw `$row[3]` is the worst literal
	 * there is — unreadable AND silently mis-typeable.
	 *
	 * The eight fields that ADD come FIRST, and in `ROW_SUMS` order, so one
	 * map describes the row's summed half.
	 *
	 * `ROW_FIELD_NAMES` below names every index. Three of the fourteen are not
	 * the counts and sums the rest are: `ROW_TIMED_COUNT` counts only the
	 * requests whose duration was measured, which is what `min_ms` folds from;
	 * `ROW_WORKER` is a boolean saying the row counts worker traffic; and
	 * `ROW_PATH` is the URL as `row_path()` shortens it, the row's one string.
	 *
	 * The READER's row is separate and stays named: it is the display shape,
	 * it crosses the wire as JSON, and `fold_index_row()` is the one place the
	 * two meet.
	 */
	public const ROW_COUNT       = 0;
	public const ROW_TIMED_COUNT = 1;
	public const ROW_SUM_MS      = 2;
	public const ROW_SUM_PEAK_MB = 3;
	public const ROW_COUNT_2XX   = 4;
	public const ROW_COUNT_3XX   = 5;
	public const ROW_COUNT_4XX   = 6;
	public const ROW_COUNT_5XX   = 7;
	public const ROW_MIN_MS      = 8;
	public const ROW_MAX_MS      = 9;
	public const ROW_MAX_PEAK_MB = 10;
	public const ROW_LAST_SEEN   = 11;
	public const ROW_WORKER      = 12;
	public const ROW_PATH        = 13;

	/**
	 * Longest `ROW_PATH` kept, in bytes, `…` included. The hash is the
	 * identity and the path is display, so a query-string flood cannot grow
	 * one row past it.
	 */
	public const MAX_PATH_BYTES = 1024;

	/**
	 * A ranked-list entry is POSITIONAL (decision 18): the hash, the row's
	 * thirteen numbers `ROW_COUNT`..`ROW_WORKER` and never `ROW_PATH`, and on
	 * the `url` lists alone the path it ranked by.
	 */
	public const RANK_HASH = 0;
	public const RANK_ROW  = 1;
	public const RANK_PATH = 2;

	/**
	 * Summed fields of a URL row => whether each is a whole count. Only fields
	 * that ADD: `min_ms` and `max_ms` are extremes.
	 */
	public const ROW_SUMS = [
		self::ROW_COUNT       => true,
		self::ROW_TIMED_COUNT => true,
		self::ROW_SUM_MS      => false,
		self::ROW_SUM_PEAK_MB => false,
		self::ROW_COUNT_2XX   => true,
		self::ROW_COUNT_3XX   => true,
		self::ROW_COUNT_4XX   => true,
		self::ROW_COUNT_5XX   => true,
	];

	/**
	 * Every stored index and what it holds — the ONE place an index becomes a
	 * name. `fold_index_row()` names the row at the storage/display boundary;
	 * dndocker's `tools/stats-shard-fields.php` reads it to label bytes per
	 * field; a test helper reverses it to seed a row in names. Nothing else
	 * should need it, and a stored row is never indexed through it in
	 * production.
	 */
	public const ROW_FIELD_NAMES = [
		self::ROW_COUNT       => 'count',
		self::ROW_TIMED_COUNT => 'timed_count',
		self::ROW_SUM_MS      => 'sum_ms',
		self::ROW_SUM_PEAK_MB => 'sum_peak_mb',
		self::ROW_COUNT_2XX   => 'count_2xx',
		self::ROW_COUNT_3XX   => 'count_3xx',
		self::ROW_COUNT_4XX   => 'count_4xx',
		self::ROW_COUNT_5XX   => 'count_5xx',
		self::ROW_MIN_MS      => 'min_ms',
		self::ROW_MAX_MS      => 'max_ms',
		self::ROW_MAX_PEAK_MB => 'max_peak_mb',
		self::ROW_LAST_SEEN   => 'last_seen',
		self::ROW_WORKER      => 'worker',
		self::ROW_PATH        => 'path',
	];

	/** Status class (2..5) to the row index counting it. */
	public const ROW_STATUS_COUNTS = [
		2 => self::ROW_COUNT_2XX,
		3 => self::ROW_COUNT_3XX,
		4 => self::ROW_COUNT_4XX,
		5 => self::ROW_COUNT_5XX,
	];

	/** Bucket width in minutes — the granularity every bucketed namespace is keyed at. */
	private const BUCKET_MINUTES = 5;

	/** The same width in seconds — the geometry every rate over these buckets divides by. */
	public const BUCKET_SECONDS = self::BUCKET_MINUTES * 60;

	/**
	 * Seconds an absence holds for a key whose frame may still land: the open
	 * bucket, or a key that is no bucket. Long enough that a polling dashboard
	 * walks for it a few times a minute rather than every poll.
	 */
	public const ABSENCE_HOLD_SECONDS = 20;

	/**
	 * How far ahead of our clock a producer's bucket still counts as open.
	 *
	 * A hub takes each record's own timestamp, so a spoke running slightly fast
	 * writes a bucket we have not reached. Holding those is right. Holding them
	 * without a ceiling is not: one corrupt timestamp would pin its frames until
	 * that year arrives. Past this, a skewed producer pays redundant last-wins
	 * copies instead — which is a cost, where the other is a leak. One
	 * `Request_Builder` eviction window under its DEFAULT declaration, the same
	 * lateness the shipped pipeline tolerates; a topology that declares another
	 * bucket count warns that this stops matching it.
	 */
	private const MAX_FUTURE_SKEW_SEC = Request_Builder_Node::DEFAULT_EVICTION_WINDOW_SEC;

	/**
	 * Ceiling on one reader's bucket enumeration (24h at the 300s width).
	 *
	 * This bounds BUCKETS, not keys: the URL index asks for one key per server
	 * per shard per bucket, so a full read costs `URL_SHARDS x` this per
	 * server. That is the trade sharding makes, and it is the right way
	 * round: a point read for one URL costs a single shard, and no item
	 * approaches memcached's 1MB limit, which one unsharded blob exceeds.
	 */
	public const MAX_READ_BUCKETS = 288;

	/**
	 * Fine buckets a read keeps: the twelve `RECENT_BUCKETS` a "last hour" rate
	 * divides by, plus the one still filling that the rate drops.
	 */
	public const FINE_BUCKETS = 13;

	/**
	 * How long a FINE `urls` or `urlsrv` bucket is kept, against
	 * `min_lifetime` for the coarse tier that outlives it.
	 *
	 * The tier has exactly two consumers: `RECENT_BUCKETS` twelve buckets, which
	 * are the last-hour rate, and `roll_up_hours()`, which builds every coarse
	 * tier out of a closed hour's fine buckets. It is the window's EDGE and the
	 * fold's input — never a tier to read old hours from, which is what
	 * `unfolded_hour_buckets()` holds the readers to.
	 *
	 * Two hours covers both: the read plan asks for `FINE_BUCKETS` plus the rest
	 * of their hour, just under two at the worst minute, and the fold folds an
	 * hour within a re-probe of it closing. At that width the tier holds 24
	 * buckets a shard, where the coarse tier holds one per hour of the
	 * retention window — twelve at the 43,200 s `min_lifetime` default.
	 * `ttl_url_fine()` caps it at that window, so a window under two hours
	 * bounds the fine tier instead.
	 */
	public const FINE_TTL_SECONDS = 7200;

	/** Every namespace but `url` and the fine `urls`/`urlsrv` buckets; TTL is `ttl()`. */
	private const ROLE_AGGREGATE = 'aggregate';
	/** The per-URL blob; TTL is `ttl_url_stats()`, and it accumulates. */
	private const ROLE_URL       = 'url';
	/** A fine `urls` or `urlsrv` bucket; TTL is `ttl_url_fine()`, its own read window. */
	private const ROLE_URL_FINE  = 'url_fine';

	/**
	 * Mirror seam — invoked after each memcache write that landed, so a durable
	 * partition can shadow stats for a later read-back. The namespace lets the
	 * mirror route aggregates apart from the bounded per-URL namespaces. Null,
	 * the default, costs nothing. `Flame_Builder_Node::arm_stats_mirror()` is the
	 * only production wiring; tests assign a recording closure in its place.
	 * Signature: `function (string $key, array $data, int $ttl, string $ns): void`.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $mirror = null;

	/**
	 * Rehydrate seam — the read counterpart of `$mirror`, invoked with the
	 * keys a read missed on. Handed to every Table as its durable backing, so a
	 * miss falls through to the mirror and lands back in memcache without any
	 * caller here knowing. Null (default) leaves the tables memcache-only.
	 * `Flame_Builder_Node::arm_stats_mirror()` wires the worker's and
	 * `arm_stats_reader()` a reader's.
	 *
	 * @var (\Closure(array<array-key,mixed>): ?array<array-key,array{value: mixed, ttl?: int}>)|null
	 */
	public ?\Closure $rehydrate = null;

	/**
	 * Whether an absence the mirror answered is remembered, and for how long:
	 * null remembers none. A READER's concern — a dashboard polls the same
	 * window and a sparse server has buckets the mirror holds no frame for,
	 * each a full walk to say so — and never the writer's, whose own folds
	 * read a bucket once and whose writes must not compete with a marker.
	 * `Flame_Builder_Node::arm_stats_reader()` sets it to `absence_holds()`
	 * for a namespace the mirror can hold and to 0 for one it refuses.
	 * Signature: `function (string $key): int`, seconds. Read when a table is
	 * built, so it is set before the first read, as `arm_stats_reader()` does.
	 *
	 * @var (\Closure(string): int)|null
	 */
	public ?\Closure $absence = null;

	/**
	 * A reader's memo of the server index, `key => index`, for the one
	 * reply the store serves: every shard of an unscoped read asks the same
	 * buckets, and each would read the index again. Null, the default, reads
	 * every time, which is what the long-lived writer needs.
	 * `Performance_CI_Node::stats_stores()` sets it to [] on each reply's stores.
	 *
	 * @var array<string,array<string,string>|null>|null
	 */
	public ?array $server_indexes = null;

	/** @var int Retention window in seconds, as Config::stats_retention_seconds() floored it. */
	private int $max_lifespan;

	/** @var array<string,Table_Node> Table per ROLE, over one namespace. */
	private array $tables = [];

	/** @var int Flame-builder partition whose keyspace this store owns. */
	private int $partition;

	/** The hourly bucket's summed fields; anything else rides through. */
	private const HOURLY_SUMS = [ 'count' => true, 'sum_ms' => false, 'sum_peak_mb' => false ];

	/** The leaderboard bucket's own summed fields. */
	private const LB_SUMS = [ 'count' => true, 'sum_req_time' => false ];

	/** One leaderboard category's summed fields. */
	public const LB_CAT_SUMS = [ 'samples' => true, 'sum_time' => false, 'sum_count' => false ];

	/**
	 * One category entry's positional triple: time, count, samples.
	 *
	 * Positional with no constants, deliberately, and the one carve-out from
	 * decision 18: a closed 3-tuple read by `sums_to_display()` beside it and
	 * by `RequestProfile.js`, so naming it means naming it twice in two deploy
	 * units for three fields. A URL row is 15 fields across three PHP files.
	 */
	private const LB_ENTRY_SUMS = [ 0 => false, 1 => false, 2 => true ];
	/**
	 * @param int $partition    Flame-builder partition to read and write.
	 * @param int $max_lifespan Retention window in seconds; callers pass
	 *                          `Config::stats_retention_seconds()`, which is
	 *                          where that window is declared.
	 */
	public function __construct(
		int $partition,
		int $max_lifespan
	) {
		$this->partition    = $partition;
		$this->max_lifespan = $max_lifespan;
	}

	/**
	 * Read many of one dimension's buckets in a single round-trip.
	 *
	 * @param string            $dimension Dimension name.
	 * @param array<int,string> $buckets   Bucket keys.
	 * @param string            $server    Reporting server; '' reads the global series.
	 * @return array<string,mixed> Value maps keyed by bucket; misses absent.
	 */
	public function get_dimensional_buckets( string $dimension, array $buckets, string $server = '' ): array {
		return $this->lookup_buckets( self::dim_parts( $dimension, $server ), $buckets );
	}

	/**
	 * The namespace prefix for a dimensional scope.
	 *
	 * @param string $dimension Dimension name, e.g. `ua`.
	 * @param string $server    Reporting server; '' is the global series.
	 * @return list<string>
	 */
	public static function dim_parts( string $dimension, string $server ): array {
		return '' === $server ? [ self::NS_DIM, $dimension ] : [ self::NS_DIM, $dimension, self::server_key( $server ) ];
	}

	/**
	 * One dimension of a URL's buckets in a single round-trip, cut out of the
	 * bucket-major blob here, where its layout was chosen.
	 *
	 * @param string            $url_hash  12-char URL hash.
	 * @param string            $dimension One of DIMENSIONS.
	 * @param array<int,string> $buckets   Bucket keys.
	 * @return array<string,array<array-key,mixed>> Value => entry, keyed by bucket; a bucket without the dimension is absent.
	 */
	public function get_url_dimension_buckets( string $url_hash, string $dimension, array $buckets ): array {
		$series = [];
		foreach ( $this->lookup_buckets( self::url_dim_parts( $url_hash ), $buckets ) as $bucket => $dims ) {
			$values = Core::arr( $dims )[ $dimension ] ?? null;
			if ( \is_array( $values ) ) {
				$series[ $bucket ] = $values;
			}
		}
		return $series;
	}

	/**
	 * Namespace prefix for one URL's dimensional series.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<int,string>
	 */
	public static function url_dim_parts( string $url_hash ): array {
		return [ self::NS_URL_DIM, $url_hash ];
	}

	/**
	 * Read many category buckets in a single round-trip.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string            $server  Reporting server; '' reads the global series.
	 * @return array<string,mixed> Category maps keyed by bucket; misses absent.
	 */
	public function get_category_buckets( array $buckets, string $server = '' ): array {
		return $this->lookup_buckets( self::cat_parts( $server ), $buckets );
	}

	/**
	 * The namespace prefix for a category scope.
	 *
	 * @param string $server Reporting server; '' is the global series.
	 * @return list<string>
	 */
	public static function cat_parts( string $server ): array {
		return '' === $server ? [ self::NS_CATEGORIES ] : [ self::NS_CATEGORIES, self::server_key( $server ) ];
	}

	/**
	 * Read many of one URL's category buckets in a single round-trip.
	 *
	 * @param string            $url_hash 12-char URL hash.
	 * @param array<int,string> $buckets  Bucket keys.
	 * @return array<string,mixed> Category maps keyed by bucket; misses absent.
	 */
	public function get_url_category_buckets( string $url_hash, array $buckets ): array {
		return $this->lookup_buckets( self::url_cat_parts( $url_hash ), $buckets );
	}

	/**
	 * Namespace prefix for one URL's category series.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<int,string>
	 */
	public static function url_cat_parts( string $url_hash ): array {
		return [ self::NS_URL_CAT, $url_hash ];
	}

	/**
	 * Every bucket key inside a retention window, newest first — what a reader
	 * enumerates to walk a bucketed namespace. Static because the window turns
	 * on retention alone: asking an instance means building the whole store
	 * fan-out to read one integer.
	 *
	 * @param int $retention_seconds How far back to enumerate.
	 * @param int $now               Clock, so a test window matches its writer's keys.
	 * @return list<string>
	 */
	public static function retention_buckets( int $retention_seconds, int $now ): array {
		$count = self::window_bucket_count( $retention_seconds );
		$out   = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = self::bucket_key( $now - ( $i * self::BUCKET_SECONDS ) );
		}
		return $out;
	}

	/**
	 * When the oldest bucket `retention_buckets()` enumerates begins.
	 *
	 * The floor of the window as a TIMESTAMP, for readers that compare against
	 * one rather than against keys — and it is read off the same bucket count,
	 * so MAX_READ_BUCKETS caps both alike and no reader can bound itself by a
	 * window wider than the one it enumerates.
	 *
	 * @param int $retention_seconds How far back the window reaches.
	 * @param int $now               Clock, so a test window matches its writer's keys.
	 * @return int Unix timestamp of the oldest enumerated bucket's start.
	 */
	public static function window_start( int $retention_seconds, int $now ): int {
		return $now - ( $now % self::BUCKET_SECONDS )
			- ( ( self::window_bucket_count( $retention_seconds ) - 1 ) * self::BUCKET_SECONDS );
	}

	/**
	 * Buckets one window spans: a bucket per width, plus the partial one `now`
	 * sits in, capped at MAX_READ_BUCKETS.
	 *
	 * @param int $retention_seconds How far back the window reaches.
	 * @return int Buckets to enumerate, at most MAX_READ_BUCKETS.
	 */
	private static function window_bucket_count( int $retention_seconds ): int {
		return \min( (int) \ceil( $retention_seconds / self::BUCKET_SECONDS ) + 1, self::MAX_READ_BUCKETS );
	}

	/**
	 * Whether a full entry key names a bucket that can still be written to.
	 *
	 * The bucket is the LAST key component in every bucketed namespace, so the
	 * bucket is read off the key; the unbucketed `url` namespace ends in a URL
	 * hash, which is not bucket-shaped and is never open.
	 *
	 * The window is bounded at BOTH ends. Not equality, because a producer's
	 * clock can run slightly ahead and a bucket we have not reached has not
	 * finished either; not open-ended, because a broken clock would then pin its
	 * frames in memory indefinitely (MAX_FUTURE_SKEW_SEC). Lexical order IS
	 * chronological order here, which is what `bucket_key()` buys.
	 *
	 * @param string $key Full entry key, as the mirror seam receives it.
	 * @param int    $now Clock, so a test window matches its writer's keys.
	 */
	public static function is_open_bucket( string $key, int $now ): bool {
		$bucket = self::bucket_of( $key );
		$opened = self::bucket_key( $now );
		// Shape comes from bucket_key() itself, never a second spelling of it.
		return \strlen( $bucket ) === \strlen( $opened )
			&& $bucket >= $opened
			&& $bucket <= self::bucket_key( $now + self::MAX_FUTURE_SKEW_SEC );
	}

	/**
	 * The bucket a timestamp falls in: `Y-m-d-H-i` UTC, floored to
	 * BUCKET_MINUTES (which must divide 60). Lexical order is chronological
	 * order, which is what lets expiry compare keys with `<` against a cutoff.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function bucket_key( int $timestamp ): string {
		return \gmdate( 'Y-m-d-H-i', $timestamp - ( $timestamp % self::BUCKET_SECONDS ) );
	}

	/**
	 * What a read of the whole window covers, by TIER: the recent fine buckets,
	 * then the closed hours behind them. Both newest first.
	 *
	 * The hours stop where the fine tail begins, so nothing is counted twice —
	 * the hour the fine tail reaches into is NOT in `hours`, and the fine tail
	 * is what covers it. The OLDEST hour is whole, so the window's far edge is
	 * hour-granular and rounds outward: a 24h read may carry up to 59 extra
	 * minutes rather than drop traffic inside its own window. Retention is a
	 * floor, and five-minute precision at that edge answered no question.
	 *
	 * @param list<string> $window The window to split, newest first —
	 *                             `retention_buckets()` at the reply's one clock read.
	 *                             Taken rather than re-enumerated: reading the clock
	 *                             again here is how one reply would straddle a
	 *                             bucket boundary.
	 * @return array{fine: list<string>, hours: list<string>}
	 */
	public static function read_plan( array $window ): array {
		$fine = \array_slice( $window, 0, self::FINE_BUCKETS );
		// @longform The hour the fine tail lands IN is read fine-grained to its
		// END, not to wherever the tail stopped. Handing it to the coarse tier
		// instead would read it twice; leaving it out reads the rest of that
		// hour at NEITHER resolution, and that hole is a function of the
		// minute — nothing at :00, eleven buckets of it at :59. So
		// FINE_BUCKETS is a floor, and the boundary does the rest.
		$covered = self::hour_of( (string) \end( $fine ) );
		$hours   = [];
		foreach ( \array_slice( $window, self::FINE_BUCKETS ) as $bucket ) {
			$hour = self::hour_of( $bucket );
			if ( $hour === $covered ) {
				$fine[] = $bucket;
				continue;
			}
			// Keyed: distinctness is structural, order stays newest-first.
			$hours[ $hour ] = true;
		}
		return [ 'fine' => $fine, 'hours' => \array_keys( $hours ) ];
	}

	/**
	 * The hour a bucket key falls in — its own leading `Y-m-d-H`.
	 *
	 * @param string $bucket A `Y-m-d-H-i` bucket key.
	 */
	public static function hour_of( string $bucket ): string {
		return \substr( $bucket, 0, 13 );
	}

	/**
	 * Read one shard's coarse hours. Same shape as `url_row_sources()`, so
	 * one fold serves both tiers.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param ?string           $shard   One shard, or null for every shard.
	 * @param bool              $workers Include the WORKER shard family, whose
	 *                                   rows the default table excludes. Ignored
	 *                                   when one shard is named, since the token
	 *                                   already says which family it belongs to.
	 * @param string            $server One server's rows; '' every server the
	 *                                  hour's index names.
	 * @return list<array{0: string, 1: array<array-key,mixed>, 2: string}>
	 */
	public function url_hour_sources( array $hours, ?string $shard = null, bool $workers = false, string $server = '' ): array {
		return $this->shard_sources( true, $hours, $shard, $workers, $server );
	}

	/**
	 * One scope's ranked lists across both tiers, as `[key, entries]` pairs,
	 * in one round trip after the server index's own.
	 *
	 * A server scope reads that server's list and no index. The site reads
	 * the index of every key and merges the lists of every server it names
	 * into the list a ranking over all of their rows would have written:
	 * URLs are disjoint by server, so the site's top-N lies inside the
	 * union of the servers' top-Ns.
	 *
	 * An hour answers only when every server its index names has its list,
	 * since the hour stands for twelve buckets and a reader serving it
	 * ranked must see all of it. A fine bucket answers with the lists it
	 * holds, which is what a ranking not yet due leaves.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string            $sort    A `URL_SORTS` value.
	 * @param string            $order   A `URL_ORDERS` value.
	 * @param string            $server  Reporting server; '' merges every server's.
	 * @return list<array{0: string, 1: array<array-key,mixed>}>
	 */
	public function url_rank_window( array $hours, array $buckets, string $sort, string $order, string $server ): array {
		$tiers = [
			[ true, $hours ],
			[ false, $buckets ],
		];
		$index = $this->scope_index( $hours, $buckets, $server );
		$reads = [];
		foreach ( $tiers as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				foreach ( $index[ $key ] ?? [] as $name ) {
					$reads[] = [ self::url_rank_parts( $sort, $order, $name, $hour ), $key ];
				}
			}
		}
		$lists   = [];
		$missing = [];
		foreach ( [] === $reads ? [] : $this->bucket_get_multi( $reads ) as $at => $entries ) {
			$key = $reads[ $at ][1];
			if ( null === $entries ) {
				$missing[ $key ] = true;
				continue;
			}
			$lists[ $key ][] = $entries;
		}
		$out = [];
		foreach ( $tiers as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				$whole = isset( $index[ $key ] ) && ! isset( $missing[ $key ] );
				if ( $hour ? ! $whole : ! isset( $lists[ $key ] ) ) {
					continue;
				}
				$out[] = [
					$key,
					'' === $server
						? self::merge_rank_lists( $lists[ $key ] ?? [], $sort, $order, $hour )
						: $lists[ $key ][0],
				];
			}
		}
		return $out;
	}

	/**
	 * The site's list for one key from each server's list for it: the list
	 * `ranked_writes()` would have written over every server's rows at once.
	 *
	 * Exact while no hour names more than `MAX_SERVER_VALUES` servers: URLs
	 * are then disjoint by server and a tie breaks by hash, so every entry of
	 * the site's top-N already heads its own server's list. A hash two
	 * servers share merges, as its rows would have. Past the cap, admission
	 * to `Other` varies by bucket, so a server admitted by name in one bucket
	 * and under `Other` in another holds one URL in two lists, each cut on
	 * its own share, and can rank short. Search has the matching gap: a
	 * server the hour folds into its `Other` has its tokens filed under its
	 * own name, which that hour's index no longer names.
	 *
	 * @param list<array<array-key,mixed>> $lists One list per server.
	 * @param string                       $sort  A `URL_SORTS` value.
	 * @param string                       $order A `URL_ORDERS` value.
	 * @param bool                         $hour  The coarse tier, which sets the bound.
	 * @return list<array<int,mixed>>
	 */
	private static function merge_rank_lists( array $lists, string $sort, string $order, bool $hour ): array {
		$rows = [];
		foreach ( $lists as $entries ) {
			foreach ( $entries as $raw ) {
				$entry = Core::arr( $raw );
				$hash  = Core::as_string( $entry[ self::RANK_HASH ] ?? '' );
				$row   = Core::arr( $entry[ self::RANK_ROW ] ?? null );
				$row[ self::ROW_PATH ] = Core::str( $entry[ self::RANK_PATH ] ?? '' );
				$rows[ $hash ]         = isset( $rows[ $hash ] ) ? self::merge_url_row( $rows[ $hash ], $row ) : $row;
			}
		}
		return self::rank_list( $rows, $sort, $order, $hour ? self::URL_RANK_N_HOUR : self::URL_RANK_N );
	}

	/**
	 * Merge one stored URL row into another for the SAME url.
	 *
	 * Both tiers of the write path fold this rule — a flush into its bucket,
	 * twelve buckets into their hour — so it lives once, beside the field table
	 * it reads. Sums add, extremes take the larger, `min_ms` folds only from
	 * TIMED buckets (0 is "nothing folded yet"), and whichever side names the
	 * path wins, because merge order varies.
	 *
	 * Contrast `fold_url_rows()`, which folds DIFFERENT urls into an overflow
	 * row and therefore keeps only what adds.
	 *
	 * @param array<array-key,mixed> $into The row so far.
	 * @param array<array-key,mixed> $row  The row being folded in.
	 * @return array<array-key,mixed>
	 */
	public static function merge_url_row( array $into, array $row ): array {
		// Read off `$into`, never `$out`: only ROW_SUMS survives the sum.
		$out = self::sum_entry( $into, $row, self::ROW_SUMS );
		$out[ self::ROW_MAX_MS ]      = \max( Core::num_float( $into[ self::ROW_MAX_MS ] ?? null ), Core::num_float( $row[ self::ROW_MAX_MS ] ?? null ) );
		$out[ self::ROW_MAX_PEAK_MB ] = \max( Core::num_float( $into[ self::ROW_MAX_PEAK_MB ] ?? null ), Core::num_float( $row[ self::ROW_MAX_PEAK_MB ] ?? null ) );
		$out[ self::ROW_LAST_SEEN ]   = \max( Core::num_int( $into[ self::ROW_LAST_SEEN ] ?? null ), Core::num_int( $row[ self::ROW_LAST_SEEN ] ?? null ) );
		$out[ self::ROW_WORKER ]      = ! empty( $into[ self::ROW_WORKER ] ) || ! empty( $row[ self::ROW_WORKER ] );
		// Verbatim, so an unfolded 0 stays the int the empty row seeded.
		$out[ self::ROW_MIN_MS ] = $into[ self::ROW_MIN_MS ] ?? 0;
		if ( Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null ) > 0 ) {
			$held                    = Core::num_float( $out[ self::ROW_MIN_MS ] );
			$row_min                 = Core::num_float( $row[ self::ROW_MIN_MS ] ?? null );
			$out[ self::ROW_MIN_MS ] = 0.0 === $held ? $row_min : \min( $held, $row_min );
		}
		$path                  = Core::str( $into[ self::ROW_PATH ] ?? '' );
		$out[ self::ROW_PATH ] = '' === $path ? Core::str( $row[ self::ROW_PATH ] ?? '' ) : $path;
		return $out;
	}

	/**
	 * Read many request-total buckets: `{ bucket => { count, sum_ms, sum_peak_mb } }`.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @return array<string,mixed> Totals keyed by bucket; misses absent.
	 */
	public function get_hourly_buckets( array $buckets ): array {
		return $this->lookup_buckets( [ self::NS_HOURLY ], $buckets );
	}

	/**
	 * Read many COARSE leaderboard hours in a single round-trip.
	 *
	 * @param array<int,string> $hours Hour keys.
	 * @return array<string,mixed> Bucket sums keyed by hour; misses absent.
	 */
	public function get_leaderboard_hours( array $hours ): array {
		return $this->lookup_buckets( self::lb_hour_parts(), $hours );
	}

	/**
	 * Read many leaderboard buckets, global or per server.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string            $server  Reporting server; '' reads the global series.
	 * @return array<string,mixed> Bucket sums keyed by bucket; misses absent.
	 */
	public function get_leaderboard_buckets( array $buckets, string $server = '' ): array {
		return $this->lookup_buckets( self::lb_parts( $server ), $buckets );
	}

	/**
	 * The namespace prefix for a leaderboard scope — the one place the global
	 * and per-server keyspaces differ.
	 *
	 * @param string $server Reporting server; '' for the global series.
	 * @return list<string>
	 */
	public static function lb_parts( string $server ): array {
		return '' === $server ? [ self::NS_LB ] : [ self::NS_LB_S, self::server_key( $server ) ];
	}

	/**
	 * Read many buckets of one namespace in a single round-trip.
	 *
	 * A dashboard walks the whole retention window — hundreds of buckets — and
	 * per-key gets across it are the latency cliff this exists to avoid.
	 *
	 * @param array<int,string> $parts   Namespace prefix parts, before the bucket.
	 * @param array<int,string> $buckets Bucket keys.
	 * @return array<string,mixed> Values keyed by bucket; misses absent.
	 */
	private function lookup_buckets( array $parts, array $buckets ): array {
		$out = [];
		foreach ( $this->lookup_bucket_sets( [ $parts ], $buckets ) as [ $bucket, $value ] ) {
			$out[ $bucket ] = $value;
		}
		return $out;
	}

	/**
	 * Read several namespace prefixes across the same buckets in ONE round-trip,
	 * as `[bucket, value]` pairs.
	 *
	 * Decisions 1 and 6. Which prefix answered is not carried, because no
	 * caller needs it: every read is of one prefix. Keyed internally by the
	 * cache key, so one prefix cannot shadow another's bucket.
	 *
	 * @param array<int,array<int,string>> $prefix_sets Namespace prefix parts, before the bucket.
	 * @param array<int,string>            $buckets     Bucket keys.
	 * @return list<array{0: string, 1: array<array-key,mixed>}>
	 */
	private function lookup_bucket_sets( array $prefix_sets, array $buckets ): array {
		if ( empty( $buckets ) || empty( $prefix_sets ) ) {
			return [];
		}
		$map = [];
		foreach ( $prefix_sets as $parts ) {
			foreach ( $buckets as $bucket ) {
				$map[ self::key( ...[ ...$parts, $bucket ] ) ] = $bucket;
			}
		}
		// No table (no backend) reads as empty, like a miss.
		$out = [];
		foreach ( $this->table( self::ROLE_AGGREGATE )?->lookup_multi( \array_keys( $map ) ) ?? [] as $key => $value ) {
			if ( \is_array( $value ) && isset( $map[ $key ] ) ) {
				$out[] = [ $map[ $key ], $value ];
			}
		}
		return $out;
	}

	/**
	 * Every source of URL rows for a window, as `[bucket, rows, server]`
	 * triples.
	 *
	 * Triples rather than a merged map: one server's shard is complete for
	 * the hashes it covers, and the caller owns how it combines them. Every
	 * shard of every server the index names in one round trip after the
	 * index's own; a scoped read names its server's keys and reads no index.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @param ?string           $shard   Read ONE shard, for a reader asking about a
	 *                                   single URL: `url_shard()` is the first hex
	 *                                   digit of its hash, so one URL is in one
	 *                                   shard and the other fifteen are dead weight.
	 *                                   Null reads every shard, which is what a
	 *                                   reader rendering the whole table wants.
	 * @param bool              $workers Include the WORKER shard family, whose
	 *                                   rows the default table excludes. Ignored
	 *                                   when one shard is named, since the token
	 *                                   already says which family it belongs to.
	 * @param string            $server  One server's rows; '' every server the
	 *                                   bucket's index names.
	 * @return list<array{0: string, 1: array<array-key,mixed>, 2: string}>
	 */
	public function url_row_sources( array $buckets, ?string $shard = null, bool $workers = false, string $server = '' ): array {
		return $this->shard_sources( false, $buckets, $shard, $workers, $server );
	}

	/**
	 * Read one tier of the URL index over many buckets, as `[bucket, rows,
	 * server]` triples.
	 *
	 * Both tiers share one key geometry — `{ns}:{server_key}:{shard}:{bucket}`
	 * — across two populations, so they share one reader and the two public
	 * wrappers name which tier each caller means. A second copy of this is how
	 * a tier comes to read a shard set the other one does not.
	 *
	 * @param bool              $hour    The coarse tier.
	 * @param array<int,string> $buckets Bucket or hour keys.
	 * @param ?string           $shard   One shard, or null for every shard.
	 * @param bool              $workers Include the WORKER shard family; ignored
	 *                                   when one shard is named, whose token
	 *                                   already says which family it belongs to.
	 * @param string            $server  One server; '' reads the index for all.
	 * @return list<array{0: string, 1: array<array-key,mixed>, 2: string}>
	 */
	private function shard_sources( bool $hour, array $buckets, ?string $shard, bool $workers, string $server ): array {
		$shards = null === $shard
			? ( $workers ? \array_merge( self::url_shards(), self::url_shards( true ) ) : self::url_shards() )
			: [ $shard ];
		$index = $hour
			? $this->scope_index( $buckets, [], $server )
			: $this->scope_index( [], $buckets, $server );
		$reads = [];
		$names = [];
		foreach ( $index as $bucket => $servers ) {
			foreach ( $servers as $key => $name ) {
				foreach ( $shards as $one ) {
					$reads[] = [ $hour ? self::url_hour_parts( $key, $one ) : self::url_shard_parts( $key, $one ), $bucket ];
					$names[] = $name;
				}
			}
		}
		$out = [];
		foreach ( $this->bucket_get_multi( $reads ) as $at => $value ) {
			if ( null !== $value ) {
				$out[] = [ $reads[ $at ][1], $value, $names[ $at ] ];
			}
		}
		return $out;
	}

	/**
	 * Namespace prefix for one server's shard of the FINE URL index.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @param string $shard      Shard name from `url_shard()`.
	 * @return array<int,string>
	 */
	public static function url_shard_parts( string $server_key, string $shard ): array {
		return [ self::NS_URLS, $server_key, $shard ];
	}

	/**
	 * The servers each key of a scope is read under: one server's own name
	 * for every key, or for the site whatever each key's index names.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string            $server  One server; '' reads the index.
	 * @return array<string,array<string,string>> key => server_key => name;
	 *                                            a key the site's index lacks is absent.
	 */
	private function scope_index( array $hours, array $buckets, string $server ): array {
		return '' === $server
			? $this->server_index( $hours, $buckets )
			: \array_fill_keys( [ ...$hours, ...$buckets ], [ self::server_key( $server ) => $server ] );
	}

	/**
	 * Which servers each key of both tiers holds rows for, in ONE round trip
	 * through the reader's memo where it has one. An hour key never spells a
	 * bucket key, so the answer and the memo key by the key itself.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param array<int,string> $buckets Bucket keys.
	 * @return array<string,array<string,string>> key => server_key => name;
	 *                                            a key holding no index is absent.
	 */
	public function server_index( array $hours, array $buckets ): array {
		$reads = [];
		foreach ( [ [ true, $hours ], [ false, $buckets ] ] as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				if ( ! \array_key_exists( $key, $this->server_indexes ?? [] ) ) {
					$reads[ $key ] = [ self::url_srv_parts( $hour ), $key ];
				}
			}
		}
		$found = [];
		foreach ( [] === $reads ? [] : $this->bucket_get_multi( $reads ) as $key => $index ) {
			$found[ (string) $key ] = null === $index ? null : self::string_map( $index );
		}
		if ( null !== $this->server_indexes ) {
			$this->server_indexes = $found + $this->server_indexes;
		}
		$out = [];
		foreach ( [ ...$hours, ...$buckets ] as $key ) {
			$index = $found[ $key ] ?? $this->server_indexes[ $key ] ?? null;
			if ( null !== $index ) {
				$out[ $key ] = $index;
			}
		}
		return $out;
	}

	/**
	 * Which name each server's rows are filed under in a bucket whose index
	 * is `$index`: its own while the index names it or has room, `OTHER_KEY`
	 * once the index holds `MAX_SERVER_VALUES` others. The `Other` entry
	 * holds no slot, so a bucket writes at most one server key past the cap.
	 *
	 * @param array<string,string> $index The bucket's stored index.
	 * @param list<string>         $names Servers with rows to file.
	 * @return array<string,string> name => the name its rows are filed under.
	 */
	public static function admit_servers( array $index, array $names ): array {
		unset( $index[ self::server_key( self::OTHER_KEY ) ] );
		$out = [];
		foreach ( $names as $name ) {
			$key = self::server_key( $name );
			if ( ! isset( $index[ $key ] ) && \count( $index ) >= self::MAX_SERVER_VALUES ) {
				$out[ $name ] = self::OTHER_KEY;
				continue;
			}
			$index[ $key ] = $name;
			$out[ $name ]  = $name;
		}
		return $out;
	}

	/**
	 * What the derived tiers hold for each of `$hours`, in two round trips.
	 *
	 * `folded` is the hour's server index, every shard of both populations
	 * for each server it names, and the global leaderboard's hour: a server
	 * missing a shard is an hour whose rows no reader sees whole, a missing
	 * leaderboard hour is one the board skips, and the fold is what would
	 * otherwise never revisit either. `unranked` names each server the index
	 * names whose DONE marker is missing — the one key its ranking writes
	 * beside its lists whatever they answer. The index and the leaderboard go
	 * first, because the index says which keys the second read asks for.
	 * The probe runs on every flush, and decision 6 is what keeps that
	 * affordable.
	 *
	 * @param array<int,string> $hours Hour keys to probe.
	 * @return array<string,array{folded: bool, unranked: list<string>}> Only hours holding something.
	 */
	public function url_hours_derived( array $hours ): array {
		$reads = [];
		foreach ( $hours as $hour ) {
			$reads[] = [ self::url_srv_parts( true ), $hour ];
			$reads[] = [ self::lb_hour_parts(), $hour ];
		}
		$heads  = $this->bucket_get_multi( $reads );
		$shards = \array_merge( self::url_shards(), self::url_shards( true ) );
		$keys   = [];
		$owner  = [];
		foreach ( \array_values( $hours ) as $at => $hour ) {
			foreach ( self::string_map( $heads[ 2 * $at ] ?? [] ) as $key => $name ) {
				$keys[]  = [ self::url_rank_done_parts( $key ), $hour ];
				$owner[] = $name;
				foreach ( $shards as $shard ) {
					$keys[]  = [ self::url_hour_parts( $key, $shard ), $hour ];
					$owner[] = null;
				}
			}
		}
		$missing  = [];
		$unranked = [];
		foreach ( $this->bucket_get_multi( $keys ) as $at => $value ) {
			if ( null !== $value ) {
				continue;
			}
			$hour = $keys[ $at ][1];
			if ( null === $owner[ $at ] ) {
				$missing[ $hour ] = true;
			} else {
				$unranked[ $hour ][] = $owner[ $at ];
			}
		}
		$out = [];
		foreach ( \array_values( $hours ) as $at => $hour ) {
			[ $index, $board ] = \array_slice( $heads, 2 * $at, 2 );
			if ( null !== $index || null !== $board ) {
				$out[ $hour ] = [
					'folded'   => null !== $index && null !== $board && ! isset( $missing[ $hour ] ),
					'unranked' => $unranked[ $hour ] ?? [],
				];
			}
		}
		return $out;
	}

	/**
	 * Namespace prefix for one server's shard of the COARSE hourly URL index.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @param string $shard      Shard name from `url_shard()`.
	 * @return array<int,string>
	 */
	public static function url_hour_parts( string $server_key, string $shard ): array {
		return [ self::NS_URLS_HOUR, $server_key, $shard ];
	}

	/**
	 * Namespace prefix of one server's DONE marker for an hour:
	 * `urlrank_sh:done:{server_key}:{hour}`.
	 *
	 * A server's hour is fourteen lists, so none of them can stand for the
	 * set. This one tiny key says the server's ranking of the hour ran, and
	 * rides that ranking's batch whatever its lists answer; a refused list is
	 * not retried, and the ranked reader folds an hour whose list it finds
	 * missing. `done` is never a server key, which is hex, so it can collide
	 * with no list.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @return array<int,string>
	 */
	public static function url_rank_done_parts( string $server_key ): array {
		return [ self::NS_URLRANK_HOUR_S, 'done', $server_key ];
	}

	/**
	 * Re-key AND re-type a decoded string map, as the server index is.
	 * `string_keys()` for the key, because an all-digit key arrives as an int;
	 * `Core::str()` for the value, because a truncated or corrupt entry is
	 * whatever it decoded to and every reader of the map promises a string.
	 *
	 * @param array<array-key,mixed> $map Decoded map.
	 * @return array<string,string>
	 */
	public static function string_map( array $map ): array {
		return \array_map( static fn ( $value ): string => Core::str( $value ), self::string_keys( $map ) );
	}

	/**
	 * Every shard the URL index is spread across.
	 *
	 * @param bool $worker Name the WORKER shard family instead of the default one.
	 * @return list<string>
	 */
	public static function url_shards( bool $worker = false ): array {
		$prefix = $worker ? self::WORKER_SHARD_PREFIX : '';
		return \array_map(
			static fn ( int $i ): string => $prefix . \dechex( $i ),
			\range( 0, self::URL_SHARDS - 1 )
		);
	}

	/**
	 * Namespace prefix for the coarse global leaderboard.
	 *
	 * @return list<string>
	 */
	public static function lb_hour_parts(): array {
		return [ self::NS_LB_HOUR ];
	}

	/**
	 * Namespace prefix of the URL index's server index.
	 *
	 * @param bool $hour The coarse tier.
	 * @return array<int,string>
	 */
	public static function url_srv_parts( bool $hour ): array {
		return [ $hour ? self::NS_URLSRV_HOUR : self::NS_URLSRV ];
	}

	/**
	 * Group URL rows by the shard their hash names.
	 *
	 * The routing rule in one place.
	 *
	 * @param array<array-key,mixed> $rows   Rows by url_hash.
	 * @param bool                   $worker Route into the WORKER shard family.
	 * @return array<array-key,array<array-key,mixed>>
	 */
	public static function rows_by_shard( array $rows, bool $worker = false ): array {
		$by_shard = [];
		foreach ( $rows as $hash => $row ) {
			$by_shard[ self::url_shard( (string) $hash, $worker ) ][ $hash ] = $row;
		}
		return $by_shard;
	}

	/**
	 * The shard a URL row lives in.
	 *
	 * The first hex digit of the hash, which `Log_Manager::url_hash()` makes
	 * uniform — so a point read knows its shard without consulting anything.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @param bool   $worker   Name the shard in the WORKER family.
	 * @return string Shard token, as `url_shards()` spells them.
	 */
	public static function url_shard( string $url_hash, bool $worker = false ): string {
		$first = \strtolower( \substr( $url_hash, 0, 1 ) );
		return ( $worker ? self::WORKER_SHARD_PREFIX : '' ) . ( \ctype_xdigit( $first ) ? $first : '0' );
	}

	/**
	 * Group named paths by every token each is filed under — the one place
	 * the tokenize-and-group loop is spelled, for the flush and for a test
	 * seeding what the flush would have written.
	 *
	 * @param array<array-key,string> $names hash => path.
	 * @return array<array-key,list<string>> token => hashes.
	 */
	public static function token_sets_of( array $names ): array {
		$out = [];
		foreach ( $names as $hash => $path ) {
			foreach ( self::url_tokens( $path ) as $token ) {
				$out[ $token ][] = (string) $hash;
			}
		}
		return $out;
	}

	/**
	 * Every key a path is filed under: each prefix of each of its tokens, so
	 * a term typed halfway still names it.
	 *
	 * @param string $path The URL's path, as `split_url()` returns it.
	 * @return list<string>
	 */
	public static function url_tokens( string $path ): array {
		$out = [];
		foreach ( self::term_tokens( $path ) as $token ) {
			for ( $len = self::URL_TOKEN_PREFIX_MIN, $max = \strlen( $token ); $len <= $max; $len++ ) {
				$out[ \substr( $token, 0, $len ) ] = true;
			}
		}
		return \array_map( 'strval', \array_keys( $out ) );
	}

	/**
	 * A search term's tokens, or a path's: lowercase alphanumeric runs of
	 * `TERM_WORD_MIN` characters or more, cut to `URL_TOKEN_PREFIX_MAX`, in
	 * source order.
	 *
	 * @param string $text Search term or path.
	 * @return list<string>
	 */
	public static function term_tokens( string $text ): array {
		$out = [];
		foreach ( \preg_split( '/' . self::TOKEN_SEP . '+/', \strtolower( $text ) ) ?: [] as $token ) {
			if ( \strlen( $token ) >= self::TERM_WORD_MIN ) {
				$out[ \substr( $token, 0, self::URL_TOKEN_PREFIX_MAX ) ] = true;
			}
		}
		// An all-digit token is an INT key; every reader promises a string.
		return \array_map( 'strval', \array_keys( $out ) );
	}

	/**
	 * The token sets this partition holds for `$servers`, unioned per token,
	 * in one round trip.
	 *
	 * WHICH tokens can be answered at all is the schema's to say, so a caller
	 * tests neither floor nor sentinel: `false` is a token no read can answer —
	 * one shorter than `URL_TOKEN_PREFIX_MIN`, refused here rather than asked
	 * for, or one whose set has saturated for any server asked — and a token
	 * none of them holds is ABSENT, which is a real answer narrowing to nothing.
	 *
	 * @param list<string> $tokens  Tokens, as `term_tokens()` spells them.
	 * @param list<string> $servers Server names whose sets to read.
	 * @return array<string,list<string>|false> token => hashes, or false when
	 *                                          no read can answer it; absent when unheld.
	 */
	public function url_token_sets( array $tokens, array $servers ): array {
		$sets  = [];
		$reads = [];
		foreach ( $tokens as $token ) {
			if ( \strlen( $token ) < self::URL_TOKEN_PREFIX_MIN ) {
				$sets[ $token ] = false;
				continue;
			}
			foreach ( $servers as $server ) {
				$reads[] = [ self::url_token_parts( self::server_key( $server ) ), $token ];
			}
		}
		foreach ( $this->bucket_get_multi( $reads ) as $at => $set ) {
			$token = $reads[ $at ][1];
			if ( null === $set || false === ( $sets[ $token ] ?? null ) ) {
				continue;
			}
			// The hashes are the KEYS; the stamps are the writer's alone.
			$sets[ $token ] = isset( $set[ self::TOKEN_SATURATED ] )
				? false
				: ( $sets[ $token ] ?? [] ) + $set;
		}
		$out = [];
		foreach ( $tokens as $token ) {
			if ( isset( $sets[ $token ] ) ) {
				$out[ $token ] = false === $sets[ $token ] ? false : \array_map( 'strval', \array_keys( $sets[ $token ] ) );
			}
		}
		return $out;
	}

	/**
	 * Read many buckets across DIFFERENT namespaces in one round trip.
	 *
	 * `lookup_bucket_sets()` reads one namespace over many buckets; this reads
	 * an arbitrary mix, which is what a flush touches. Every read keeps its own
	 * slot, under the key `$reads` carried, because a caller merges `result[i]`
	 * onto `reads[i]` and a collapsed miss would land every later merge on the
	 * wrong key.
	 *
	 * A MISS is null and a stored value is itself, `[]` included: an hour folded
	 * with no rows is written empty, and a probe reading that as absence folds
	 * it again for the rest of the window.
	 *
	 * A read that never happened is null too, so a caller merging onto the
	 * result passes `$failed` and reads nothing into a null while it is set.
	 *
	 * @param array<array-key,array{0: array<int,string>, 1: string}> $reads  `[ parts, bucket ]` pairs.
	 * @param ?bool                                                   $failed Set true when no cache
	 *                                                                        backend answered the batch.
	 * @param-out bool                                                $failed
	 * @return array<array-key,array<string,mixed>|null> One entry per read, keyed as `$reads` was.
	 */
	public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
		$failed = false;
		if ( [] === $reads ) {
			return [];
		}
		$keys = [];
		foreach ( $reads as $i => [ $parts, $bucket ] ) {
			$keys[ $i ] = self::key( ...[ ...$parts, $bucket ] );
		}
		$table  = $this->table( self::ROLE_AGGREGATE );
		$failed = null === $table;
		$found  = $table?->lookup_multi( \array_values( \array_unique( $keys ) ), $failed ) ?? [];
		$out   = [];
		foreach ( $keys as $i => $key ) {
			$value     = $found[ $key ] ?? null;
			$out[ $i ] = \is_array( $value ) ? self::string_keys( $value ) : null;
		}
		return $out;
	}

	/**
	 * Namespace prefix of one server's token sets.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @return array<int,string>
	 */
	public static function url_token_parts( string $server_key ): array {
		return [ self::NS_URLTOKEN, $server_key ];
	}

	/**
	 * Read one URL's stats blob — flame tree, profiles, last_modified. Whole, not
	 * summable: readers take the first partition that has it rather than merging.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<array-key,mixed>|null Blob, or null on miss.
	 */
	public function get_url_stats( string $url_hash ): ?array {
		$val = $this->lookup( self::key( self::NS_URL, $url_hash ) );
		if ( ! \is_array( $val ) ) {
			return null;
		}
		// The profile is stored as sums; every reader wants per-request means.
		$profiles = Core::arr( $val['profiles'] ?? null );
		if ( [] !== $profiles ) {
			$val['profiles'] = self::sums_to_display(
				Core::num_int( $profiles['count'] ?? 0 ),
				Core::num_float( $profiles['sum_req_time'] ?? 0 ),
				self::string_keys( Core::arr( $profiles['categories'] ?? null ) )
			);
		}
		return $val;
	}

	/**
	 * Convert summed leaderboard data to the display shape expected by the frontend.
	 *
	 *  - 'time'    = sum_time  / total_count — avg exclusive cat time per request.
	 *  - 'count'   = sum_count / total_count — avg invocation count per request.
	 *  - entries   are per-appearance averages (sum / samples).
	 *
	 * An entry whose sample count is zero is dropped rather than divided. Past a
	 * hundred entries a category keeps only its fifty slowest, ranked by average
	 * exclusive time, so one pathological category cannot flood a payload.
	 *
	 * @param int                 $total_count  Total profiled requests.
	 * @param float               $sum_req_time Sum of per-request $req_time values.
	 * @param array<string,mixed> $sums         Per-category sums keyed by category name.
	 * @return array<string,mixed> Display-shaped leaderboard data.
	 */
	public static function sums_to_display( int $total_count, float $sum_req_time, array $sums ): array {
		$display_cats = [];
		foreach ( $sums as $cat => $data ) {
			$data      = Core::arr( $data );
			$samples   = Core::num_int( $data['samples'] ?? null );
			$sum_time  = Core::num_float( $data['sum_time'] ?? null );
			$sum_count = Core::num_float( $data['sum_count'] ?? null );

			$entries_out = [];
			$entries     = ( isset( $data['entries'] ) && \is_array( $data['entries'] ) ) ? $data['entries'] : [];
			foreach ( $entries as $name => $entry ) {
				$entry     = Core::arr( $entry );
				$e_samples = Core::num_int( $entry[2] ?? null );
				if ( $e_samples > 0 ) {
					$entries_out[ $name ] = [
						Core::num_float( $entry[0] ?? null ) / $e_samples,
						Core::num_float( $entry[1] ?? null ) / $e_samples,
						$e_samples,
					];
				}
			}

			if ( \count( $entries_out ) > 100 ) {
				\uasort( $entries_out, fn( $a, $b ) => $b[0] <=> $a[0] );
				$entries_out = \array_slice( $entries_out, 0, 50, true );
			}

			$display_cats[ $cat ] = [
				'time'    => $total_count > 0 ? $sum_time / $total_count : 0.0,
				'count'   => $total_count > 0 ? $sum_count / $total_count : 0.0,
				'samples' => $samples,
				'entries' => $entries_out,
			];
		}

		return [
			'count'      => $total_count,
			'total_time' => $total_count > 0 ? $sum_req_time / $total_count : 0.0,
			'categories' => $display_cats,
		];
	}

	/**
	 * Read one key through the table; no backend and a miss both read as null.
	 *
	 * @param string $key Entry key below the Table's namespace.
	 * @return mixed The stored value, or null.
	 */
	private function lookup( string $key ): mixed {
		return $this->table( self::ROLE_AGGREGATE )?->lookup( $key );
	}

	/**
	 * Resolve URL names for the hashes a reader is about to show or locate.
	 *
	 * One `lookup_multi`, like every other reader path (decision 6). Absent
	 * hashes are simply missing from the result: a name can expire while its
	 * rows are still in the window, and a row with no name is still a row.
	 *
	 * @param array<int,string> $hashes 12-char URL hashes.
	 * @return array<string,array{server:string,url:string}> hash => the server
	 *                                                       its rows are filed
	 *                                                       under, and its URL.
	 */
	public function get_url_names( array $hashes ): array {
		if ( [] === $hashes ) {
			return [];
		}
		$map = [];
		foreach ( $hashes as $hash ) {
			$map[ self::key( self::NS_URLMAP, $hash ) ] = $hash;
		}
		$out = [];
		foreach ( $this->table( self::ROLE_AGGREGATE )?->lookup_multi( \array_keys( $map ) ) ?? [] as $key => $value ) {
			$stored = Core::arr( $value );
			$server = $stored[0] ?? null;
			$path   = $stored[1] ?? null;
			if ( isset( $map[ $key ] ) && self::is_url_name( $server, $path ) ) {
				$out[ $map[ $key ] ] = [ 'server' => $server, 'url' => self::join_url( $server, $path ) ];
			}
		}
		return $out;
	}

	/**
	 * The URL a `row_path()` was cut from, given the server it was cut
	 * against: the inverse `row_path()` answers to, and the one place a
	 * reader spells the join.
	 *
	 * @param string $server The server the path was cut against.
	 * @param string $path   The stored path.
	 */
	private static function join_url( string $server, string $path ): string {
		return self::names_host( $server ) && \str_starts_with( $path, '/' ) ? "https://{$server}{$path}" : $path;
	}

	/**
	 * Whether a stored `urlmap` value is `[ server_name, path ]`. A server
	 * name never holds `/`, `?` or `#`, so an entry whose first element does
	 * is the old `[ path, origin ]` shape, and reads as no name at all.
	 *
	 * @param mixed $server The stored first element.
	 * @param mixed $path   The stored second element.
	 * @phpstan-assert-if-true non-empty-string $server
	 * @phpstan-assert-if-true non-empty-string $path
	 */
	private static function is_url_name( mixed $server, mixed $path ): bool {
		return \is_string( $server ) && '' !== $server && false === \strpbrk( $server, '/?#' )
			&& \is_string( $path ) && '' !== $path;
	}

	/**
	 * Record the names of URLs this flush touched.
	 *
	 * One round trip for the whole flush, like every other write here: a name
	 * per key would make the cost per URL, which is what the batch exists to
	 * avoid. Wrapped in a list because the mirror and the memcache table both
	 * carry arrays; the writer decides WHICH names are worth re-writing, since
	 * a name never changes and re-storing it every flush would spend the saving.
	 *
	 * @param array<array-key,array<array-key,string>> $servers Filed server => hash => URL.
	 *                                                          An all-digit key is an INT.
	 * @return void
	 */
	public function set_url_names( array $servers ): void {
		$writes = [];
		foreach ( $servers as $server => $urls ) {
			foreach ( $urls as $hash => $url ) {
				$writes[] = [ [ self::NS_URLMAP ], (string) $hash, [ (string) $server, self::row_path( $url, (string) $server ) ] ];
			}
		}
		$this->bucket_set_multi( $writes );
	}

	/**
	 * Write many buckets across DIFFERENT namespaces in one round trip.
	 *
	 * Neither cache backend reports success per KEY, so a refused batch is
	 * re-sent one key at a time — a caller that logs a specific refusal (a
	 * URL shard) still learns which one, and the slow path only runs when
	 * something actually failed.
	 *
	 * @param array<int,array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes `[ parts, bucket, data ]`.
	 * @return array<int,bool> One result per write, in order.
	 */
	public function bucket_set_multi( array $writes ): array {
		if ( [] === $writes ) {
			return [];
		}
		// One batch per ROLE
		$values = [];
		foreach ( $writes as [ $parts, $bucket, $data ] ) {
			$values[ $this->role_for( $parts[0] ) ][ self::key( ...[ ...$parts, $bucket ] ) ] = $data;
		}
		$landed = true;
		foreach ( $values as $role => $batch ) {
			$landed = true === $this->table( $role )?->store_multi( $batch ) && $landed;
		}
		if ( $landed ) {
			// Shadowed only once the set landed, as `store()` does.
			if ( null !== $this->mirror ) {
				foreach ( $writes as [ $parts, $bucket, $data ] ) {
					$key = self::key( ...[ ...$parts, $bucket ] );
					( $this->mirror )( self::entry_key( $this->partition, $key ), $data, $this->ttl_for( $parts[0] ), $parts[0] );
				}
			}
			return \array_fill( 0, \count( $writes ), true );
		}
		$out = [];
		foreach ( $writes as $i => [ $parts, $bucket, $data ] ) {
			$out[ $i ] = $this->bucket_set( $parts, $bucket, $data );
		}
		return $out;
	}

	/**
	 * Overwrite one bucket of a namespace. The namespace token leads `$parts`,
	 * so it is also what routes the mirror.
	 *
	 * @param array<int,string>      $parts  Namespace prefix parts, before the bucket.
	 * @param string                 $bucket Bucket key.
	 * @param array<array-key,mixed> $data   The bucket.
	 * @return bool True when the set landed.
	 */
	private function bucket_set( array $parts, string $bucket, array $data ): bool {
		return $this->store( self::key( ...[ ...$parts, $bucket ] ), $data, $this->ttl_for( $parts[0] ), $parts[0] );
	}

	/**
	 * Write to memcache, then (if wired AND the set landed) shadow the same write
	 * to the mirror seam — a rejected/failed set must not be durably recorded and
	 * resurrected by a later read-back.
	 *
	 * @param string                 $key  Entry key below the Table's namespace.
	 * @param array<array-key,mixed> $data Value to store.
	 * @param int                    $ttl  Expiry in seconds, for the mirror only —
	 *                                     the Table holds its role's own.
	 * @param string                 $ns   Namespace routing hint for the mirror.
	 * @return bool True when the set landed.
	 */
	private function store( string $key, array $data, int $ttl, string $ns ): bool {
		$ok = (bool) $this->table( $this->role_for( $ns ) )?->store( $key, $data );
		if ( $ok && null !== $this->mirror ) {
			// The mirror records the durable key, which no salt rotation moves.
			( $this->mirror )( self::entry_key( $this->partition, $key ), $data, $ttl, $ns );
		}
		return $ok;
	}

	/**
	 * Durable key for one entry — what the mirror records its frames under.
	 *
	 * Deliberately NOT the Table's cache key: that one carries the install
	 * scope, and the scope moves on every salt rotation. The mirror exists to
	 * outlive `wp nodes memcache flush`, so its key carries the partition and
	 * the entry and nothing else, and a rotation orphans nothing on disk.
	 *
	 * No version component either: a frame in a shape the merge does not name
	 * sums to a zero count `measured()` drops, or ages out with its retention
	 * window — decision 5 names which namespaces take which.
	 *
	 * @param int    $partition Flame-builder partition.
	 * @param string $key       Entry key within the namespace.
	 * @return string `evlog:p{N}:{key}`, stable across salt rotations.
	 */
	public static function entry_key( int $partition, string $key ): string {
		return self::namespace_for( $partition ) . ':' . $key;
	}

	/**
	 * The `ROW_PATH` of a URL served by `$server`: what the key does not
	 * already say. An https URL whose authority is the server, when the
	 * server names a host, keeps only its path, which `join_url()` joins back
	 * as `https://{server}{path}`; any other
	 * URL is kept whole, so no reader shows a scheme or host it was not. Cut
	 * to `MAX_PATH_BYTES`, the last of them an ellipsis.
	 *
	 * @param string $url    The stored URL.
	 * @param string $server The server whose key the row is filed under.
	 */
	public static function row_path( string $url, string $server ): string {
		$origin = 'https://' . $server;
		$path   = self::names_host( $server ) && \str_starts_with( $url, $origin . '/' ) ? \substr( $url, \strlen( $origin ) ) : $url;
		if ( \strlen( $path ) <= self::MAX_PATH_BYTES ) {
			return $path;
		}
		return \mb_strcut( $path, 0, self::MAX_PATH_BYTES - \strlen( '…' ), 'UTF-8' ) . '…';
	}

	/**
	 * Whether a server name is a host a path joins back onto: the overflow
	 * and nameless servers stand for many hosts or none.
	 *
	 * @param string $server A server name rows are filed under.
	 */
	private static function names_host( string $server ): bool {
		return '' !== $server && self::OTHER_KEY !== $server && self::UNKNOWN_SERVER !== $server;
	}

	/**
	 * What one stored part costs before its strings, under the serializer
	 * memcached is configured with — the one place a cap learns it.
	 *
	 * @param string $part An `OVERHEADS` part: `url_row`, `lb_category`,
	 *                     `lb_entry`, `flame_node` or `hook`.
	 * @throws \LogicException When no estimate names the part.
	 */
	public static function overhead( string $part ): int {
		self::$serializer ??= self::configured_serializer();
		return self::OVERHEADS[ self::$serializer ][ $part ] ?? throw new \LogicException( "no size estimate for a stored {$part}" );
	}

	/**
	 * The serializer `Core::$memd` stores with: igbinary when it is configured
	 * so, PHP's otherwise, and PHP's with no handle, being the larger.
	 *
	 * @return self::SERIALIZER_*
	 */
	private static function configured_serializer(): string {
		$memd = Core::$memd;
		return null !== $memd && \defined( '\Memcached::SERIALIZER_IGBINARY' )
			&& \Memcached::SERIALIZER_IGBINARY === $memd->getOption( \Memcached::OPT_SERIALIZER )
			? self::SERIALIZER_IGBINARY
			: self::SERIALIZER_PHP;
	}

	/**
	 * Drop one bucket of a namespace from memcache. Nothing reaches the
	 * mirror: every caller forgets a derived key, which the mirror never holds.
	 *
	 * @param array<int,string> $parts  Namespace prefix parts, before the bucket.
	 * @param string            $bucket Bucket or hour key.
	 */
	public function bucket_forget( array $parts, string $bucket ): void {
		$this->table( $this->role_for( $parts[0] ) )?->forget( self::key( ...[ ...$parts, $bucket ] ) );
	}

	/**
	 * Whether a key is an absolute mirror key rather than one relative to its
	 * namespace — what the checkpoint carry keeps, and what a reader may file a
	 * frame as.
	 *
	 * @param string $key A key read back from a checkpoint or a frame.
	 */
	public static function is_mirror_key( string $key ): bool {
		return \str_starts_with( $key, self::mirror_prefix() );
	}

	/**
	 * Per-URL aggregate accumulator: the un-drained value for a url_hash, or the
	 * last persisted one when the accumulator holds none.
	 *
	 * @api Flame_Builder_Node's per-URL accumulation.
	 * @param string $url_hash URL hash.
	 * @return mixed The held aggregate, the last persisted one when none is
	 *               held, or null with no cache backend.
	 */
	public function accumulated_url_stats( string $url_hash ): mixed {
		return $this->table( self::ROLE_URL )?->accumulated( self::key( self::NS_URL, $url_hash ) );
	}

	/**
	 * Fold a per-URL aggregate into the accumulator, without persisting it.
	 *
	 * @param string              $url_hash URL hash.
	 * @param array<string,mixed> $data     Aggregate to hold.
	 */
	public function accumulate_url_stats( string $url_hash, array $data ): void {
		$this->table( self::ROLE_URL )?->accumulate( self::key( self::NS_URL, $url_hash ), $data );
	}

	/**
	 * Join the caller's parts into one entry key, namespace token first.
	 *
	 * The `evlog:p{N}` prefix and the install scope are NOT here: the Table
	 * carries them, through `namespace_for()` and `Table_Node::entry_key()`, and
	 * the mirror's durable key carries the prefix with no scope (`entry_key()`). That
	 * scoping is what keeps two installs sharing one memcached server — an Atomic
	 * pair — off each other's `hourly` key, which is otherwise a co-tenant's
	 * request volume in this install's dashboard.
	 *
	 * @param string ...$parts Namespace token first, then any sub-keys.
	 * @return string Entry key, below the Table's namespace.
	 */
	public static function key( string ...$parts ): string {
		return \implode( ':', $parts );
	}

	/**
	 * Walk the accumulating per-URL aggregates, keyed by url_hash, for a drain.
	 *
	 * @return iterable<string,mixed>
	 */
	public function accumulating_url_stats(): iterable {
		$table = $this->table( self::ROLE_URL );
		if ( null === $table ) {
			return;
		}
		$prefix = self::NS_URL . ':';
		foreach ( $table->accumulating() as $key => $value ) {
			yield \substr( $key, \strlen( $prefix ) ) => $value;
		}
	}

	/** Drop the per-URL accumulator. */
	public function reset_url_stats(): void {
		$this->table( self::ROLE_URL )?->reset();
	}

	/**
	 * The Table this store reads and writes, memoized per ROLE.
	 *
	 * Three TTLs are in play — `ttl()` for the aggregates, `ttl_url_stats()` for
	 * the bounded per-URL blobs and `ttl_url_fine()` for a fine `urls` bucket —
	 * and a Table's TTL is fixed at construction, so each role gets its own
	 * instance over the SAME namespace. Built lazily behind the backend check
	 * because `Table_Node::table()` throws without one, and every method here has
	 * to fail soft instead.
	 *
	 * The per-URL table takes the accumulator and no backing; the other two take
	 * the backing and no accumulator. `accumulated()` falls through to `lookup()`
	 * on every request, and `flame_topn` is 0 in production, so backing the
	 * per-URL table would pay an index scan per cold URL for a frame that is
	 * never written.
	 *
	 * @param string $role ROLE_AGGREGATE, ROLE_URL or ROLE_URL_FINE; each resolves
	 *        its own TTL. Keyed by role, never by TTL: all three coincide at
	 *        PREFIX_FLOOR, and a shared table would hand aggregate reads the
	 *        deliberately unbacked one.
	 * @return ?Table_Node Null with no cache backend, which every caller reads as
	 *                     a miss.
	 */
	private function table( string $role ): ?Table_Node {
		if ( null === \Newspack_Nodes\Cache_Backend::shared_first() ) {
			return null;
		}
		if ( ! isset( $this->tables[ $role ] ) ) {
			$is_url = self::ROLE_URL === $role;
			$table  = Table_Node::table(
				self::namespace_for( $this->partition ),
				match ( $role ) {
					self::ROLE_URL      => $this->ttl_url_stats(),
					self::ROLE_URL_FINE => $this->ttl_url_fine(),
					default             => $this->ttl(),
				}
			);
			if ( $is_url ) {
				$table->accumulator( self::URL_ACCUMULATOR_SIZE, self::URL_ACCUMULATOR_BUCKETS );
			} else {
				// Read seam indirect (re-armed later); absence seam as is.
				$table->backed_by(
					fn ( array $keys ): ?array => null !== $this->rehydrate ? ( $this->rehydrate )( $keys ) : [],
					$this->absence
				);
			}
			$this->tables[ $role ] = $table;
		}
		return $this->tables[ $role ];
	}

	/**
	 * Table namespace owning one partition's keyspace.
	 *
	 * @api Tests derive cache keys from it; the mirror's durable key opens with it too.
	 * @param int $partition Flame-builder partition.
	 */
	public static function namespace_for( int $partition ): string {
		return self::mirror_prefix() . $partition;
	}

	/**
	 * The head every durable key opens with — `namespace_for()`'s, minus the
	 * partition, so the test and the writer cannot drift apart.
	 */
	private static function mirror_prefix(): string {
		return self::PREFIX_BASE . ':p';
	}

	/**
	 * How long an absence the mirror answered for `$key` holds.
	 *
	 * A closed bucket gains no frame, so its absence holds for what is left of
	 * the window, and the walk that found nothing is not repeated all window
	 * long — a sparse server has such buckets in every window, and asked on
	 * every poll they spent the read budget before the series was reached. The
	 * open bucket's frame may still land, and a key that is no bucket may be
	 * written any time, so those hold only `ABSENCE_HOLD_SECONDS`. A bucket
	 * counts as closed one further bucket after its span, which covers the
	 * flush that writes it.
	 *
	 * @api The `$absence` seam, per key the mirror did not return.
	 * @param string $key Table-relative entry key.
	 * @return int Seconds the absence holds; 0 holds none.
	 */
	public function absence_holds( string $key ): int {
		// The tick, never a fresh read.
		$now    = (int) Core::$now;
		$bucket = self::bucket_span( $key );
		if ( null === $bucket || $bucket[0] + $bucket[1] + self::BUCKET_SECONDS > $now ) {
			return self::ABSENCE_HOLD_SECONDS;
		}
		return $this->window_remaining( $key, $now );
	}

	/**
	 * How long a re-materialized entry is warmed for: what is left of the
	 * RETENTION window, bounded by its own role's TTL.
	 *
	 * The TTL it was written with bounds the CACHE and decays from the WRITE, so
	 * a spent one says nothing about how long the data is still READ: the window
	 * does, and it is a pure function of the bucket key, which is the last
	 * segment and sorts chronologically. Zero or less means genuinely past
	 * retention — nothing asks for it, and nothing should warm it.
	 *
	 * The role's TTL is the other bound and is not the same statement.
	 * `ttl_url_fine()` is a memcache FOOTPRINT: 24 buckets a shard rather than
	 * 288, because decision 17's coarse tier answers for everything behind the
	 * edge. Warming a rehydrated fine bucket for the whole window instead puts
	 * all 288 back in the cache that tier exists to keep out — up to twelve
	 * times its footprint, for buckets no reader asks for.
	 *
	 * The HOUR branch is unreached from that seam today, because `NS_URLS_HOUR`
	 * is a DERIVED namespace and is filtered out before this is asked.
	 * It stays because the alternative is worse than dead: without it an hour
	 * key falls to the hash-keyed branch and reports a FULL role TTL for a
	 * bucket most of whose window is spent, the moment that policy changes.
	 *
	 * @api The mirror seam, sizing what it hands back.
	 * @param string $key Table-RELATIVE entry key: `<ns>:…:<bucket>`.
	 * @param int    $now Clock, so one answer cannot straddle a boundary.
	 * @return int Seconds remaining, 0 when the key names no readable bucket.
	 */
	public function window_remaining( string $key, int $now ): int {
		$role   = $this->ttl_for( self::namespace_of( $key ) );
		$bucket = self::bucket_span( $key );
		if ( null === $bucket ) {
			// `url` and `urlmap` key on a hash; neither is bucket-shaped.
			return $role;
		}
		return \min( $role, \max( 0, ( $bucket[0] + $this->max_lifespan ) - $now ) );
	}

	/**
	 * The bucket a key names — its start and its span in seconds — or null for
	 * a key that names none.
	 *
	 * ISO 8601 through `strtotime()`, because that function reads many
	 * non-dates as dates, `x` included; the shape is pinned first.
	 *
	 * @param string $key Table-relative entry key: `<ns>:…:<bucket>`.
	 * @return array{0: int, 1: int}|null
	 */
	private static function bucket_span( string $key ): ?array {
		$bucket = self::bucket_of( $key );
		if ( \preg_match( '/^(\d{4}-\d{2}-\d{2})-(\d{2})-(\d{2})$/D', $bucket, $m ) ) {
			$stamp = \strtotime( "{$m[1]}T{$m[2]}:{$m[3]}:00+00:00" );
			$span  = self::BUCKET_SECONDS;
		} elseif ( \preg_match( '/^(\d{4}-\d{2}-\d{2})-(\d{2})$/D', $bucket, $m ) ) {
			$stamp = \strtotime( "{$m[1]}T{$m[2]}:00:00+00:00" );
			$span  = 3600;
		} else {
			return null;
		}
		return false === $stamp ? null : [ $stamp, $span ];
	}

	/**
	 * The bucket a key names: its last segment, whatever sits between.
	 *
	 * @param string $key `…:<bucket>` — a bare segment answers itself.
	 */
	public static function bucket_of( string $key ): string {
		$at = \strrpos( $key, ':' );
		return false === $at ? $key : \substr( $key, $at + 1 );
	}

	/**
	 * A table-relative key's namespace: its first segment (decision 1).
	 *
	 * @param string $key `<ns>:…` — a bare namespace answers itself.
	 */
	public static function namespace_of( string $key ): string {
		return \explode( ':', $key, 2 )[0];
	}

	/**
	 * How long a namespace's value is kept — the role's own TTL.
	 *
	 * @param string $ns Namespace, an `NS_*` value.
	 */
	private function ttl_for( string $ns ): int {
		return match ( $this->role_for( $ns ) ) {
			self::ROLE_URL      => $this->ttl_url_stats(),
			self::ROLE_URL_FINE => $this->ttl_url_fine(),
			default             => $this->ttl(),
		};
	}

	/** Retention for a FINE `urls` or `urlsrv` bucket: its read window, never the whole one. */
	public function ttl_url_fine(): int {
		return \min( $this->max_lifespan, self::FINE_TTL_SECONDS );
	}

	/** Retention for the high-volume `url` namespace: the retention window cut to a 24th, floored at an hour. */
	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
	}

	/**
	 * Which table a namespace is written through.
	 *
	 * Two groups leave the aggregate table. `url` takes its own, for the
	 * accumulator tier and `ttl_url_stats()`. The fine `urls` and `urlsrv`
	 * tiers are read at the window's EDGE and answered behind that by `urls_h`
	 * and `urlsrv_h`, so their TTL is their read window rather than the
	 * retention window.
	 *
	 * @param string $ns Namespace, an `NS_*` value.
	 */
	private function role_for( string $ns ): string {
		return match ( $ns ) {
			self::NS_URL => self::ROLE_URL,
			// An index outliving the fine rows it names is one nothing reads.
			self::NS_URLS,
			self::NS_URLSRV,
			self::NS_URLRANK_S => self::ROLE_URL_FINE,
			default            => self::ROLE_AGGREGATE,
		};
	}

	/**
	 * Add one URL row into another, for the synthetic overflow row only.
	 *
	 * Only the fields that ADD (`ROW_SUMS`) plus `last_seen`: an extreme over
	 * unrelated URLs describes nothing, and neither does one path, so the
	 * overflow row's is ''.
	 *
	 * @param array<array-key,mixed> $into The row so far, [] on first fold.
	 * @param array<array-key,mixed> $row  The row being folded in.
	 * @return array<array-key,mixed>
	 */
	public static function fold_url_rows( array $into, array $row ): array {
		// AFTER the sum: it returns `$into`, which carries its own `last_seen`.
		$out                        = self::sum_entry( $into, $row, self::ROW_SUMS );
		$out[ self::ROW_WORKER ]    = ! empty( $into[ self::ROW_WORKER ] ) || ! empty( $row[ self::ROW_WORKER ] );
		$out[ self::ROW_LAST_SEEN ] = \max(
			Core::num_int( $into[ self::ROW_LAST_SEEN ] ?? null ),
			Core::num_int( $row[ self::ROW_LAST_SEEN ] ?? null )
		);
		$out[ self::ROW_PATH ]      = '';
		return $out;
	}

	/**
	 * Sum two `{count, sum_ms, sum_peak_mb}` totals — the `hourly` namespace's
	 * shape. The schema owns the triple, so it owns the addition over it, as
	 * `sums_to_display()` owns the read-time division over its own. A non-numeric
	 * field on either side reads as zero.
	 *
	 * Fields outside the triple ride through from `$a`, which is why the sum is
	 * replaced ONTO it rather than returned on its own: the stored bucket is
	 * the caller's — a merged time series carries the `hour` it is keyed by —
	 * and rebuilding it here would drop a fourth field silently.
	 *
	 * @param array<string,mixed>    $a One side, and the shape that survives.
	 * @param array<array-key,mixed> $b The other, read by name only.
	 * @return array<string,mixed>
	 */
	public static function add_totals( array $a, array $b ): array {
		return self::string_keys( \array_replace( $a, self::sum_entry( $a, $b, self::HOURLY_SUMS ) ) );
	}

	/**
	 * Merge one leaderboard bucket's sums into another, in place.
	 *
	 * `Flame_Builder_Node` combines the current flush's bucket with the already
	 * persisted bucket of the same key. Three shapes nest here and each has its
	 * own field table: the bucket (`LB_SUMS`), a category inside it
	 * (`LB_CAT_SUMS`) and one of that category's entries (`LB_ENTRY_SUMS`).
	 *
	 * @param array<string,mixed> $dst The bucket so far; rewritten in place.
	 * @param array<string,mixed> $src The bucket being merged in.
	 */
	public static function merge_leaderboard_bucket( array &$dst, array $src ): void {
		// Read BEFORE the sum: `sum_entry()` keeps only what LB_SUMS names.
		$cats = Core::arr( $dst['categories'] ?? null );
		$dst  = self::string_keys( self::sum_entry( $dst, $src, self::LB_SUMS ) );
		foreach ( Core::arr( $src['categories'] ?? null ) as $cat => $data ) {
			$data    = Core::arr( $data );
			$current = Core::arr( $cats[ $cat ] ?? null );
			$entries = Core::arr( $current['entries'] ?? null );
			foreach ( Core::arr( $data['entries'] ?? null ) as $name => $entry ) {
				$entries[ $name ] = self::sum_entry(
					Core::arr( $entries[ $name ] ?? null ),
					Core::arr( $entry ),
					self::LB_ENTRY_SUMS
				);
			}
			$cats[ $cat ]            = self::sum_entry( $current, $data, self::LB_CAT_SUMS );
			$cats[ $cat ]['entries'] = $entries;
		}
		$dst['categories'] = $cats;
	}

	/**
	 * Sum `$fields` from `$incoming` into `$into`, entry by entry. The one merge
	 * the dimensional (`DIM_SUMS`) and category (`CAT_SUMS`) series share, and
	 * it reads a field key rather than a name, so a positional table works here
	 * exactly as a named one does.
	 *
	 * Only `$fields` survive — unlike `add_totals()`, which lets a field outside
	 * its triple ride through. Every shape here is closed, so there is nothing
	 * to carry; a shape that grows a field adds it to the table rather than
	 * relying on passthrough. `sum_entry()` rebuilds each entry from the table,
	 * so an entry arriving in a shape the table does not name is DISCARDED
	 * rather than hybridised with the current one.
	 *
	 * @param array<array-key,mixed> $into     Running totals.
	 * @param array<array-key,mixed> $incoming Inbound entries.
	 * @param array<array-key,bool>  $fields   Field key => is a whole count.
	 * @return array<string,mixed> The totals, string-keyed for the store.
	 */
	public static function sum_fields( array $into, array $incoming, array $fields ): array {
		$out = self::string_keys( $into );
		foreach ( $incoming as $key => $stats ) {
			if ( \is_array( $stats ) ) {
				$out[ (string) $key ] = self::sum_entry( Core::arr( $out[ (string) $key ] ?? null ), $stats, $fields );
			}
		}
		return $out;
	}

	/**
	 * Sum `$fields` from one entry into another — what `sum_fields()` does per
	 * key, reachable directly by a caller holding a single row rather than a map.
	 *
	 * The entry it returns is built from `$fields` and nothing else, so a key
	 * either side carries outside the table is DISCARDED — which is what makes
	 * `sum_fields()`'s invariant true. A caller wanting a field the table does
	 * not name puts it back itself, beside the reason it survives.
	 *
	 * @param array<array-key,mixed> $into   The entry so far.
	 * @param array<array-key,mixed> $from   The entry being added.
	 * @param array<array-key,bool>  $fields Field => whether it is a whole count.
	 * @return array<array-key,mixed> The `$fields` keys, summed.
	 */
	public static function sum_entry( array $into, array $from, array $fields ): array {
		$out = [];
		foreach ( $fields as $field => $is_count ) {
			$out[ $field ] = $is_count
				? Core::num_int( $into[ $field ] ?? null ) + Core::num_int( $from[ $field ] ?? null )
				: Core::num_float( $into[ $field ] ?? null ) + Core::num_float( $from[ $field ] ?? null );
		}
		return $out;
	}

	/**
	 * What answers for every hour the coarse tier did not: the fine buckets
	 * that stand in, and the hours nothing stands in for.
	 *
	 * Every tier reader asks this one question of a plan, and the two facts
	 * are independent, so both come back and each caller reads the half it
	 * acts on. A folded hour's fine buckets are NOT read — they outlive the
	 * fold, and taking both counts the hour twice — and only the GRACE hour
	 * falls back, so an older uncovered hour is a HOLE. A fold tolerates one
	 * and comes up short there; the ranked reader cannot, because serving a
	 * window an hour short as ranked is worse than folding.
	 *
	 * @api `Performance_CI_Node`'s `ranked_page()`, `walk_shard_tiers()` and
	 *      `build_leaderboard()`.
	 * @param list<string>        $hours   The plan's hours, newest first.
	 * @param array<string,mixed> $covered Hours the coarse tier answered, keyed.
	 * @return array{buckets: list<string>, holes: list<string>}
	 */
	public static function fine_fallback( array $hours, array $covered ): array {
		$out   = [];
		$holes = [];
		foreach ( $hours as $hour ) {
			if ( isset( $covered[ $hour ] ) ) {
				continue;
			}
			$buckets = self::unfolded_hour_buckets( $hour, $hours );
			if ( [] === $buckets ) {
				$holes[] = $hour;
				continue;
			}
			$out = \array_merge( $out, $buckets );
		}
		return [
			'buckets' => $out,
			'holes'   => $holes,
		];
	}

	/**
	 * The fine buckets that answer for an unfolded hour.
	 *
	 * The fine tier answers the last hour and feeds the fold; it is not a tier
	 * to read old hours from. So the fallback reaches the GRACE hour — the one
	 * immediately behind the fine tail, which the fold may simply not have
	 * caught yet — and stops. Everything older is the coarse tier's.
	 *
	 * All twelve, whatever their age against `ttl_url_fine()`. That TTL bounds
	 * the CACHE — the fine tier is the largest thing this schema puts in a
	 * 512MB one — and says nothing about how long the data is available: `urls`
	 * and `urlsrv` mirror in full, the mirror retains for twice the stats
	 * window, and a spent remainder re-warms rather than reading as a miss.
	 * That is what lets decision 17 leave the coarse tier UNMIRRORED — an
	 * evicted hour is rebuilt from buckets that outlive it — and it needs
	 * `Table_Node::read_through()` to keep serving a record whose cache
	 * lifetime ran out.
	 *
	 * @api `fine_fallback()`, which is how every reader asks it.
	 * @param string       $hour  A `Y-m-d-H` hour key.
	 * @param list<string> $hours The plan's hours, newest first; `$hour` is
	 *                            read finely only when it leads them.
	 * @return list<string>
	 */
	public static function unfolded_hour_buckets( string $hour, array $hours ): array {
		return $hour === ( $hours[0] ?? null ) ? self::buckets_in_hour( $hour ) : [];
	}

	/**
	 * The fine buckets one hour covers, oldest first.
	 *
	 * @param string $hour A `Y-m-d-H` hour key.
	 * @return list<string>
	 */
	public static function buckets_in_hour( string $hour ): array {
		$out = [];
		for ( $m = 0; $m < 60; $m += self::BUCKET_MINUTES ) {
			$out[] = $hour . \sprintf( '-%02d', $m );
		}
		return $out;
	}

	/**
	 * Every ranked list one tier's merged rows produce, as the
	 * `[parts, key, entries]` triples `bucket_set_multi()` takes: fourteen for
	 * each server named, empty where it holds nothing rankable, so a reader
	 * can tell a server ranked idle from one whose lists are missing. The
	 * TIER sets the bound, so a caller names which tier it is writing and
	 * never the row count twice.
	 *
	 * No list spans servers: `url_rank_window()` merges the site's.
	 *
	 * @param array<array-key,array<array-key,mixed>> $servers Server name =>
	 *                                                         the tier's merged rows by hash.
	 * @param bool                                    $hour    The coarse tier.
	 * @param string                                  $key     Bucket or hour key.
	 * @return list<array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}>
	 */
	public static function ranked_writes( array $servers, bool $hour, string $key ): array {
		$n      = $hour ? self::URL_RANK_N_HOUR : self::URL_RANK_N;
		$writes = [];
		foreach ( $servers as $server => $rows ) {
			$rankable = [];
			foreach ( $rows as $raw_hash => $raw ) {
				$hash = (string) $raw_hash;
				$row  = Core::arr( $raw );
				if ( self::ranks( $hash, $row ) ) {
					$rankable[ $hash ] = $row;
				}
			}
			foreach ( self::rank_url_rows( $rankable, $n ) as $sort => $orders ) {
				foreach ( $orders as $order => $entries ) {
					$writes[] = [ self::url_rank_parts( $sort, $order, (string) $server, $hour ), $key, $entries ];
				}
			}
		}
		return $writes;
	}

	/**
	 * Namespace prefix of one server's ranked list. The server rides in the
	 * KEY: a list is `URL_RANK_N` rows, which is the bound decision 14 named
	 * as what would make the key-prefix form affordable.
	 *
	 * @param string $sort   A `URL_SORTS` value.
	 * @param string $order  A `URL_ORDERS` value.
	 * @param string $server Reporting server.
	 * @param bool   $hour   The coarse tier.
	 * @return array<int,string>
	 */
	public static function url_rank_parts( string $sort, string $order, string $server, bool $hour ): array {
		return [ $hour ? self::NS_URLRANK_HOUR_S : self::NS_URLRANK_S, self::server_key( $server ), $sort, $order ];
	}

	/**
	 * Hash a server name to a key-safe ASCII token (FNV-1a 32-bit hex).
	 * Used for `lb_s` / `dim:_:srv` keys so server names don't break colons.
	 *
	 * @param string $server Server name; '' hashes to ''.
	 * @return string Eight hex digits, or ''.
	 */
	public static function server_key( string $server ): string {
		if ( '' === $server ) {
			return '';
		}
		return \sprintf( '%08x', Log_Manager::fnv1a32( $server ) );
	}

	/**
	 * Every ranked list of ONE server's rows: `sort => order => entries`, each
	 * cut to `$n`.
	 *
	 * The rows arrive filtered — `ranked_writes()` has already dropped what
	 * never ranks — so nothing here walks them a second time.
	 *
	 * @param array<array-key,array<array-key,mixed>> $rows One server's rows by hash.
	 * @param int                                     $n    Entries per list.
	 * @return array<string,array<string,list<array<int,mixed>>>>
	 */
	private static function rank_url_rows( array $rows, int $n ): array {
		$out = [];
		foreach ( self::URL_SORTS as $sort ) {
			foreach ( self::URL_ORDERS as $order ) {
				$out[ $sort ][ $order ] = self::rank_list( $rows, $sort, $order, $n );
			}
		}
		return $out;
	}

	/**
	 * One list: the `$n` best rows on one sort in one direction, as entries.
	 * An untimed row ranks on no timed sort, and a row with no path ranks on
	 * no `url` sort. A tie breaks by hash, ascending either way, so a cut
	 * never depends on the order rows arrive in and a merge of servers'
	 * lists cuts where one list over all of them would.
	 *
	 * @param array<array-key,array<array-key,mixed>> $rows  Rows by hash.
	 * @param string                                  $sort  A `URL_SORTS` value.
	 * @param string                                  $order A `URL_ORDERS` value.
	 * @param int                                     $n     Entries to keep.
	 * @return list<array<int,mixed>>
	 */
	private static function rank_list( array $rows, string $sort, string $order, int $n ): array {
		$timed  = \in_array( $sort, [ 'avg_ms', 'min_ms', 'max_ms' ], true );
		$values = [];
		foreach ( $rows as $hash => $row ) {
			if ( $timed && Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null ) <= 0 ) {
				continue;
			}
			if ( 'url' === $sort && '' === Core::str( $row[ self::ROW_PATH ] ?? '' ) ) {
				continue;
			}
			$values[ $hash ] = self::url_rank_value( $row, $sort );
		}
		$sign = 'asc' === $order ? 1 : -1;
		\uksort(
			$values,
			static fn ( int|string $a, int|string $b ): int => ( $sign * ( $values[ $a ] <=> $values[ $b ] ) ) ?: \strcmp( (string) $a, (string) $b )
		);
		return self::rank_entries( \array_keys( \array_slice( $values, 0, $n, true ) ), $sort, $rows );
	}

	/**
	 * One ranked list's entries: each hash beside the row it ranked, and the
	 * path too on a `url` list, which is the only sort that displays one.
	 *
	 * @param list<array-key>        $hashes The list's hashes, in rank order.
	 * @param string                 $sort   A `URL_SORTS` value.
	 * @param array<array-key,mixed> $rows   The rankable rows by hash.
	 * @return list<array<int,mixed>>
	 */
	private static function rank_entries( array $hashes, string $sort, array $rows ): array {
		$out = [];
		foreach ( $hashes as $hash ) {
			$row = Core::arr( $rows[ $hash ] );
			$path = Core::str( $row[ self::ROW_PATH ] ?? '' );
			unset( $row[ self::ROW_PATH ] );
			// An all-digit hash arrives as an INT key.
			$entry = [ self::RANK_HASH => (string) $hash, self::RANK_ROW => $row ];
			if ( 'url' === $sort ) {
				$entry[ self::RANK_PATH ] = $path;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * The value one bucket's row ranks by for one sort: the bucket's OWN
	 * average for the two means, so a page hit rarely but slowly ranks on how
	 * slow it is rather than on how often it is hit.
	 *
	 * @param array<array-key,mixed> $row  A stored row.
	 * @param string                 $sort A `URL_SORTS` value.
	 */
	private static function url_rank_value( array $row, string $sort ): float|int|string {
		return match ( $sort ) {
			'count'        => Core::num_int( $row[ self::ROW_COUNT ] ?? null ),
			'avg_ms'       => Core::num_float( $row[ self::ROW_SUM_MS ] ?? null ) / \max( 1, Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null ) ),
			'min_ms'       => Core::num_float( $row[ self::ROW_MIN_MS ] ?? null ),
			'max_ms'       => Core::num_float( $row[ self::ROW_MAX_MS ] ?? null ),
			'avg_peak_mb'  => Core::num_float( $row[ self::ROW_SUM_PEAK_MB ] ?? null ) / \max( 1, Core::num_int( $row[ self::ROW_COUNT ] ?? null ) ),
			'last_updated' => Core::num_int( $row[ self::ROW_LAST_SEEN ] ?? null ),
			default        => Core::str( $row[ self::ROW_PATH ] ?? '' ),
		};
	}

	/**
	 * Whether a stored row ranks at all: not an overflow row, not a worker's.
	 *
	 * Spelled once, because every scope reads it — a server holding only rows
	 * that never rank would otherwise get a list of nothing.
	 *
	 * @param string                 $hash The row's key.
	 * @param array<array-key,mixed> $row  The stored row.
	 */
	private static function ranks( string $hash, array $row ): bool {
		return ! self::is_other_key( $hash ) && empty( $row[ self::ROW_WORKER ] );
	}

	/**
	 * Whether a row key is one of the overflow rows — either of them.
	 *
	 * @param string $hash A URL row key.
	 */
	public static function is_other_key( string $hash ): bool {
		return self::OTHER_KEY === $hash || self::OTHER_WORKER_KEY === $hash;
	}

	/**
	 * The PATH of each URL, by hash — what the search index files. A hash
	 * whose URL is '' is absent from the map rather than named '': nothing
	 * named it, so nothing can find it.
	 *
	 * @param array<array-key,string> $urls hash => URL. An all-digit hash is
	 *                                       an INT key, as PHP makes it.
	 * @return array<string,string> hash => path.
	 */
	public static function paths_of( array $urls ): array {
		$out = [];
		foreach ( $urls as $hash => $url ) {
			if ( '' !== $url ) {
				$out[ (string) $hash ] = self::path_of( $url );
			}
		}
		return $out;
	}

	/**
	 * The PATH of a URL: what a search matches, with no scheme or host.
	 *
	 * The server is the picker's question, so a term matching the host would
	 * make one box ask the dropdown's. A URL carrying no scheme is all path,
	 * which is what a producer with no `SERVER_NAME` writes. The authority
	 * ends at whichever delimiter comes first, so an authority with no path
	 * keeps its query on the path.
	 *
	 * @param string $url A URL, or a row's path.
	 */
	public static function path_of( string $url ): string {
		$at = \strpos( $url, '://' );
		if ( false === $at ) {
			return $url;
		}
		$host = $at + 3;
		return \substr( $url, $host + \strcspn( $url, '/?#', $host ) );
	}

	/**
	 * Union one flush's hashes into a token's set, as `hash => last named`.
	 *
	 * The stamp per hash is what lets the set SHRINK: every entry a retention
	 * window has passed over is dropped, so a URL that has gone quiet stops
	 * holding a slot and stops being named to a reader that counts it against
	 * `URL_SEARCH_MAX`. A hash leaves a set when its URL stops being named,
	 * not when the key dies.
	 *
	 * The walk is decided before it runs, because re-reading a set of four
	 * entry by entry on every flush of every token of every URL is the cost
	 * the cap is there to bound. A flush that would pass the cap prunes
	 * outright, and is tested first so it skips the scan; otherwise one pass
	 * over the stamps says whether the prune has anything to do, stopping at
	 * the first expired one.
	 *
	 * Past the cap the set is the sentinel under its own stamp, and the reader
	 * folds. A live sentinel is returned UNCHANGED rather than restamped, so
	 * `flush_writes()` skips the write, the key keeps the TTL it had, and the
	 * token rebuilds live-only when that expires.
	 *
	 * @param array<array-key,mixed> $existing The stored set, `hash => ts`.
	 * @param list<string>           $hashes   This flush's.
	 * @param int                    $now      Unix seconds this flush is at.
	 * @return array<string,int> hash => last named.
	 */
	public function merge_token_set( array $existing, array $hashes, int $now ): array {
		$set    = self::string_keys( $existing );
		$oldest = $now - $this->ttl();
		if ( \count( $set ) + \count( $hashes ) > self::URL_SEARCH_MAX || self::holds_expired( $set, $oldest ) ) {
			$set = \array_filter( $set, static fn ( $seen ): bool => Core::num_int( $seen ) > $oldest );
		}
		if ( isset( $set[ self::TOKEN_SATURATED ] ) ) {
			return [ self::TOKEN_SATURATED => Core::num_int( $set[ self::TOKEN_SATURATED ] ) ];
		}
		foreach ( $hashes as $hash ) {
			$set[ $hash ] = $now;
		}
		return \count( $set ) > self::URL_SEARCH_MAX
			? [ self::TOKEN_SATURATED => $now ]
			: \array_map( static fn ( $seen ): int => Core::num_int( $seen ), $set );
	}

	/**
	 * Whether a token set holds a stamp the window has passed over.
	 *
	 * The prune's own predicate, asked before the prune: it stops at the
	 * first expired entry, so a live set costs a walk and no allocation
	 * where the prune costs both.
	 *
	 * @param array<string,mixed> $set    The stored set, `hash => ts`.
	 * @param int                 $oldest The oldest stamp still live.
	 */
	private static function holds_expired( array $set, int $oldest ): bool {
		foreach ( $set as $seen ) {
			if ( Core::num_int( $seen ) <= $oldest ) {
				return true;
			}
		}
		return false;
	}

	/** Retention window, in seconds, for every namespace but `url`. */
	public function ttl(): int {
		return $this->max_lifespan;
	}

	/**
	 * Re-key a decoded map with string keys. PHP casts numeric-looking keys to
	 * int on decode, so a value read back from the cache is `array-key` typed
	 * even though every namespace stores a string-keyed map; the setters and the
	 * merge helpers want that guarantee back.
	 *
	 * @param array<array-key,mixed> $map Decoded value.
	 * @return array<string,mixed>
	 */
	public static function string_keys( array $map ): array {
		$out = [];
		foreach ( $map as $key => $value ) {
			$out[ (string) $key ] = $value;
		}
		return $out;
	}

	/**
	 * Whether a namespace is DERIVED from something the store already holds.
	 *
	 * Membership is the whole fact, and it sits beside `role_for()` because it
	 * is the same kind of statement about a namespace: each of these is
	 * re-derivable, so a durable copy would store one thing twice. The three
	 * coarse tiers are re-folded from the mirrored fine buckets, the two
	 * ranked lists are re-ranked from those rows on the next flush or fold,
	 * and a token set is rewritten whenever its URL is next named. Every other
	 * namespace is stored — `url` included, which is bounded by a RANK cap
	 * instead (`Flame_Builder_Node::set_flame_topn()`, 0 until an operator
	 * raises it).
	 *
	 * @api The durable stats mirror, deciding what it keeps and what it reads.
	 * @param string $ns Namespace, an `NS_*` value.
	 */
	public static function is_derived( string $ns ): bool {
		return match ( $ns ) {
			self::NS_URLS_HOUR,
			self::NS_URLSRV_HOUR,
			self::NS_LB_HOUR,
			self::NS_URLRANK_S,
			self::NS_URLRANK_HOUR_S,
			self::NS_URLTOKEN => true,
			default           => false,
		};
	}

	/**
	 * Whether a name answers a term: every token of the term begins a WORD of
	 * it, or the whole term appears when the term has no token at all.
	 *
	 * The index files word prefixes, so the fold it falls back to has to read
	 * a term the same way. Matching a substring here instead would make `77`
	 * name `/wombat-1177` through the fold and not through the index, so
	 * which rows a search returned would turn on whether some other token
	 * happened to have saturated.
	 *
	 * @api The fold, for a candidate the token index already named.
	 * @param string       $name   The URL's path.
	 * @param string       $term   The lowercased search term.
	 * @param list<string> $tokens The term's tokens, as `term_tokens()` spells them.
	 */
	public static function term_matches( string $name, string $term, array $tokens ): bool {
		$name = \strtolower( $name );
		if ( [] === $tokens ) {
			return \str_contains( $name, $term );
		}
		foreach ( $tokens as $token ) {
			if ( 1 !== \preg_match( '/(?:^|' . self::TOKEN_SEP . ')' . \preg_quote( $token, '/' ) . '/', $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The entries of a bucket that measured anything. An entry in a shape a
	 * sum table does not name reads its count as absent, and a zero count is
	 * a slot with nothing in it: no request, no chart row. Writer and reader
	 * drop it alike, until the flush ages it out.
	 *
	 * @param array<array-key,mixed> $values      Entries keyed by value name.
	 * @param int                    $count_field The entry's count index.
	 * @return array<array-key,mixed> The entries with a count above zero.
	 */
	public static function measured( array $values, int $count_field ): array {
		return \array_filter(
			$values,
			static fn ( $entry ): bool => \is_array( $entry ) && Core::num_int( $entry[ $count_field ] ?? null ) > 0
		);
	}

	/** The retention window every TTL here derives from, in seconds. */
	public function max_lifespan(): int {
		return $this->max_lifespan;
	}

	/**
	 * Namespace prefix for the site-wide request totals.
	 *
	 * @return array<int,string>
	 */
	public static function hourly_parts(): array {
		return [ self::NS_HOURLY ];
	}

	/**
	 * The overflow key a row folds into.
	 *
	 * @param bool $worker Whether the folded row is worker traffic.
	 */
	public static function other_key( bool $worker ): string {
		return $worker ? self::OTHER_WORKER_KEY : self::OTHER_KEY;
	}

	/**
	 * Values kept in one dimension's bucket.
	 *
	 * The server axis gets `MAX_SERVER_VALUES` rather than the caller's generic
	 * cap: it is the picker's contents, so a fleet-sized axis must survive whole.
	 * It is capped all the same — `SERVER_NAME` is the client's Host header under
	 * Apache's default `UseCanonicalName Off`, so it is visitor input like any
	 * other axis, just one no real fleet reaches the ceiling of.
	 *
	 * @param string $dimension The dimension being capped.
	 * @param int    $cap       Ceiling for every other axis.
	 */
	public static function dim_cap( string $dimension, int $cap ): int {
		return self::DIM_SERVER === $dimension ? self::MAX_SERVER_VALUES : $cap;
	}

	/** Partition this store reads and writes. */
	public function partition(): int {
		return $this->partition;
	}

}
