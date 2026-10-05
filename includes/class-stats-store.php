<?php
/**
 * Stats Store
 *
 * The schema for performance stats, expressed as one small key/value
 * API. Twenty namespaces (`hourly_h`, `dim_h`, `categories_h`, `url_dim_h`,
 * `url_cat_h`, `url_row_h`, `lb_h`, `lb_sh`, `urls`, `urls_h`, `urlsrv`,
 * `urlsrv_h`, `urlrank_s`, `urlrank_sh`, `urlhdr`, `urlhdr_h`, `urltoken`,
 * `urlbucket`, `urlmap`, `url`) live in three SQLite Tables that `flame-builder.tsl` declares, one file per
 * partition. `Flame_Builder_Node` produces every value and
 * `App\Performance_CI_Node` reads them for the dashboards.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Durable_Arm;
use Newspack_Nodes\Table_Client;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats storage in three SQLite Tables, reached by message.
 *
 * Keys are `{namespace}[:{time}][:...]`, the bucket or hour right after the
 * namespace (`key_at()`), inside one partition's Table files, so every
 * flame-builder partition owns a disjoint keyspace and readers fan one store
 * out per partition. A value is a plain array, string-keyed, but three of
 * the entries inside one are POSITIONAL and read through named constants: a
 * stored URL row (`ROW_*`), a category (`CAT_*`) and a dimensional value
 * (`DIM_*`).
 *
 * Retention runs at three lengths, one per Table, each the TTL its `make_node`
 * line declares: `flame-stats:url` holds the high-volume per-URL blob
 * (`url`) for a twenty-fourth of the retention window, `flame-stats:url-fine`
 * holds a FINE `urls`, `urlsrv`, `urlrank_s` or `urlhdr` bucket for its own
 * read window, because the hour tiers answer for it behind the recent tail,
 * and `flame-stats:aggregate` holds every other namespace for the window,
 * floored at the `CHART_HOURS` a chart reads. A write states no TTL of its
 * own, but for the indexes, each living one refresh past what it indexes: a
 * `urltoken` member `max_lifespan()`, the window, and a `urlmap` name or a
 * `urlbucket` member the aggregate Table's TTL. Otherwise `max_lifespan()` is
 * the READ window alone.
 *
 * Bucketing is part of the key schema, so it lives here: `bucket_key()` is the
 * five-minute `Y-m-d-H-i` derivation every producer and reader shares, and
 * `retention_buckets()` is the window the URL index's reader enumerates. A
 * chart namespace keys by the hour instead, its value holding the hour's
 * twelve five-minute slots (`slot_of()`, decision 35).
 *
 * The store asks each Table through its owner's `Table_Client`: MGET, MSET,
 * RM, and SADD and SMEMBERS for the search index, by message, TO the Table's
 * node name, the reply coming back TO the owner. Every exchange stays
 * in-process — the builder's Tables live in its own worker graph, and a
 * reader mounts them into its own request graph —
 * because one value reply may outgrow the 4 KB a message may carry across
 * an IPC hop (ADR-4). Reads and writes fail soft: a Table that does not
 * answer yields `[]`, `null` or `false`, and the dashboards render "no data"
 * instead of an error. Keep it that way; the SSE slot pool is deliberately
 * the opposite, and unifying the two breaks its rate limit.
 *
 * @phpstan-type Url_Header array{0: int, 1: int, 2: float, 3: float, 4: bool, 5: string, 6: int}
 * @phpstan-type Rank_Entry array{0: string, 1: array<array-key,mixed>, 2?: string}
 */
class Stats_Store {

	/** Distinct category values kept per bucket; `Flame_Builder_Node` rolls the overflow into "Other". */
	public const MAX_CAT_VALUES           = 50;
	/**
	 * Categories a leaderboard hour (`lb_h`, `lb_sh`) or a URL's
	 * profile keeps, the slowest first, the rest folded into "Other". A byte
	 * estimate caps it lower when the categories run wide.
	 */
	public const MAX_LB_CATEGORIES        = 200;
	/** Distinct values kept per global dimension bucket but `server`'s, which keeps every host. */
	public const MAX_DIM_VALUES           = 20;

	/** Distinct values kept per per-URL dimension bucket. */
	public const MAX_URL_DIM_VALUES       = 10;
	/**
	 * Bytes any one stored value may take, and what every byte cap derives
	 * from. A SQLite Table sets no item limit, so for a stats value this
	 * bounds what one costs to hold and to read: a worker's memory while its
	 * flush merges the value, and the unserialize every reader pays. It sits
	 * under memcached's 1,048,576-byte item, less a margin for the key and
	 * the framing, because `Rule_Set`'s hook list rides memcached through an
	 * `auto` Table and caps to it too. Uncompressed, because nothing
	 * guarantees production compresses. A producer caps before it writes,
	 * against an estimate `overhead()` makes for the serializer
	 * `Durable_Arm::serializer()` names: igbinary's where `Core::$memd` uses
	 * igbinary, and PHP's `serialize()`, the larger, otherwise or when no
	 * handle is present. A refused set is never how a size is found. A
	 * slotted hour value takes no byte cap: its count caps bound each slot
	 * (decision 35).
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

	/** igbinary: what a stored value takes where the memcached handle uses it. */
	public const SERIALIZER_IGBINARY = 'igbinary';

	/** The dimension naming the reporting server — the axis the picker is built from. */
	public const DIM_SERVER      = 'server';

	/**
	 * The global leaderboard, one key an hour: `lb_h:{Y-m-d-H}`, ONE sum
	 * over the hour, because nothing charts it (decision 35). The heaviest
	 * value the dashboard reads — a category per hook, callback and plugin
	 * the site fires, 1,198 of them on a production hub — so the hour sums
	 * rather than keeping twelve slots of it.
	 */
	public const NS_LB_HOUR      = 'lb_h';
	/** The per-server leaderboard, `lb_sh:{Y-m-d-H}:{server_key}`, summed as `lb_h` is. */
	public const NS_LB_S_HOUR    = 'lb_sh';
	/** Request totals, `hourly_h:{Y-m-d-H}`: twelve slots, each `{count, sum_ms, requests, sum_peak_mb}`. */
	public const NS_HOURLY_HOUR  = 'hourly_h';
	/** The dimensional series, `dim_h:{Y-m-d-H}:{dim}[:{server_key}]`: twelve slots. */
	public const NS_DIM_HOUR     = 'dim_h';
	/** The category series, `categories_h:{Y-m-d-H}[:{server_key}]`: twelve slots. */
	public const NS_CAT_HOUR     = 'categories_h';
	/** One URL's dimensional series, `url_dim_h:{Y-m-d-H}:{hash}`: `{ dim => twelve slots }`. */
	public const NS_URL_DIM_HOUR = 'url_dim_h';
	/** One URL's category series, `url_cat_h:{Y-m-d-H}:{hash}`: twelve slots. */
	public const NS_URL_CAT_HOUR = 'url_cat_h';

	/**
	 * One URL's index row, `url_row_h:{Y-m-d-H}:{server_key}:{hash}`: the
	 * row each family filed in the hour's `urls` shards, in the slot of the
	 * bucket it fell in, so a reader asking about named URLs reads them by
	 * key where the shard holds every URL of their digit. Positional
	 * (decision 18): `URL_ROW_PATH`, then each family's slots under
	 * `url_row_family()`, each slot a row's `ROW_COUNT`..`ROW_WORKER`.
	 */
	public const NS_URL_ROW_HOUR = 'url_row_h';

	/** A `url_row_h` value's path: the `ROW_PATH` its slots' rows omit. */
	public const URL_ROW_PATH = 0;

	/** A `url_row_h` value's reader-family slots. */
	public const URL_ROW_READER = 1;

	/** A `url_row_h` value's worker-family slots. */
	public const URL_ROW_WORKER = 2;

	/** Per-URL stats blob: flame tree and profiles. */
	public const NS_URL         = 'url';
	/**
	 * URL index bucket, one SERVER's rows sharded by the first hex digit of
	 * the url_hash: `urls:{bucket}:{server_key}:{shard}`. Per-server data has
	 * the server in the key, so a busy server's rows never compete with a
	 * quiet one's for a shard's cap. The bucket comes right after the
	 * namespace, as in every time-keyed namespace (`key_at()`). Decision 1.
	 */
	public const NS_URLS        = 'urls';

	/**
	 * The URL index's server index: `urlsrv:{bucket}` => `{ server_key =>
	 * [ server_name, shards ] }`, every server with rows in the bucket, reader
	 * or worker, and the shards it wrote there (`SRV_NAME`, `SRV_SHARDS`).
	 *
	 * The keyspace cannot list itself, so this is what every read of the URL
	 * index, the fold and the ranker enumerate, and they ask for the shards
	 * it names and no other: a key nobody wrote is a miss read for nothing.
	 * Every server is filed under its own name, uncapped.
	 */
	public const NS_URLSRV      = 'urlsrv';

	/** The server index's COARSE tier, `urlsrv_h:{Y-m-d-H}`, folded beside `urls_h`. */
	public const NS_URLSRV_HOUR = 'urlsrv_h';

	/**
	 * A stored server-index entry is positional (decision 18): the server's
	 * name, and the int bitmask of the shards it wrote in that key, bit `i`
	 * reader shard `dechex(i)` and bit `URL_SHARDS + i` worker shard
	 * `w{dechex(i)}`. `shard_mask()` and `shards_in()` translate it.
	 */
	public const SRV_NAME   = 0;
	public const SRV_SHARDS = 1;

	/**
	 * The search index, one set per six-hour bucket per server per whole
	 * word, the word spelled as `term_tokens()` spells it:
	 * `urltoken:{bucket}:{server_key}:{word}`, a set KEY whose members are the
	 * hashes of that server's URLs whose paths carry the word, filed in that
	 * bucket, each valued by the unix second its name was last filed. A
	 * member lives the window and one refresh from its last add
	 * (`filing_ttl()`), so a URL no flush names again leaves the set on its own.
	 */
	public const NS_URLTOKEN = 'urltoken';

	/**
	 * The bucket index, one set per five-minute bucket per server:
	 * `urlbucket:{bucket}:{server_key}`, a set KEY whose members are the
	 * hashes of the URLs that server filed rows for in that bucket, either
	 * family, each valued by the unix second it was last added. A member
	 * lives as long as the `url_row_h` slot it points at, and one refresh
	 * (`filing_ttl()`), so every bucket whose rows are stored can list its
	 * URLs.
	 */
	public const NS_URLBUCKET = 'urlbucket';

	/**
	 * Width of one `urltoken` time bucket: six hours.
	 *
	 * The bucket is the set key's time part, so it trades three costs. A
	 * (word, URL) pair holds one member row in every bucket it was filed in
	 * while the member lives, at most `ceil( window / width ) + 1` of them,
	 * and a search reads that many sets per word per server. Every add of one
	 * flush lands in the current bucket's key range, which is the whole of
	 * what that flush dirties, and a narrower bucket makes that range
	 * denser. An hour costs 13 rows and 13 reads at the 12-hour default
	 * window, and makes every hourly refresh a new row rather than a
	 * rewrite; twelve hours costs 2 of each and spreads a flush across half
	 * a day of members. Six costs 3 rows and 3 reads at the default window,
	 * rewrites a URL's row in place for five hourly refreshes of every six,
	 * keeps a flush inside a quarter of a day, and divides a day, so every
	 * bucket starts at 00, 06, 12 or 18 UTC.
	 */
	public const TOKEN_BUCKET_SECONDS = 6 * self::HOUR_SECONDS;

	/**
	 * Members a read takes from one word's set. A set holding more answers
	 * the Table's over-limit marker and no member, and its word narrows
	 * nothing.
	 */
	public const URL_SEARCH_MAX = 5000;

	/**
	 * Members a read takes from one bucket's set, and the (hash, server)
	 * pairs an unscoped bucket page reads across every set. A bucket's
	 * candidates are read by key like a search's; this limit is higher than
	 * `URL_SEARCH_MAX` by Chris's decision, which accepts a slower page on a
	 * busy hub. A set or a page past it is refused.
	 */
	public const URL_BUCKET_MAX = 10000;

	/**
	 * Words of one search term one read names, longest first, since a
	 * longer word is the rarer (`search_groups()`). Each word read costs up
	 * to `URL_SEARCH_MAX` members for every bucket of every server searched,
	 * all held until the read returns, so a caller's term must not set the
	 * multiplier: three words at 5,000 members in 3 buckets across a
	 * 9-server hub is 405,000 members, where a term of ten read at once
	 * would be 1,350,000. The next three are
	 * read only when every word of the last came back over the limit, which
	 * costs one message a set and no member, so the bound holds for any
	 * term. Three is enough to narrow, because the intersection is at most
	 * its smallest set and the walk checks every word of the term, read or
	 * not, against each candidate's own path.
	 */
	public const SEARCH_WORDS_READ = 3;

	/** Longest word the index files; a longer one is cut to it on both sides. */
	public const TERM_WORD_MAX = 12;

	/** Shortest run of characters that counts as a WORD, filing or matching. */
	public const TERM_WORD_MIN = 2;

	/**
	 * What separates two words, as a character class. The FILING rule and the
	 * MATCHING rule are built from this one spelling, so a term cannot tokenize
	 * on one alphabet and match on another.
	 */
	private const TOKEN_SEP = '[^a-z0-9]';

	/**
	 * The URL index's COARSE tier: `urls_h:{Y-m-d-H}:{server_key}:{shard}`, one
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
	 * `urlrank_s:{bucket}:{server_key}:{sort}:{order}`, and the site's,
	 * the merge of every server's, `urlrank_s:{bucket}:{sort}:{order}`: a
	 * site-wide aggregate is one key (decision 30). The worker family's
	 * lists take one key part more, `WORKER_SHARD_PREFIX`, after the
	 * bucket: `urlrank_s:{bucket}:w:{server_key}:{sort}:{order}` and
	 * `urlrank_s:{bucket}:w:{sort}:{order}`. Fine tier; `TABLE_URL_FINE`.
	 */
	public const NS_URLRANK_S      = 'urlrank_s';
	/**
	 * The coarse tier of `urlrank_s`, folded with `urls_h`:
	 * `urlrank_sh:{Y-m-d-H}:{server_key}:{sort}:{order}` and the site's
	 * `urlrank_sh:{Y-m-d-H}:{sort}:{order}`, beside each server's DONE
	 * marker, `urlrank_sh:{Y-m-d-H}:done:{server_key}`.
	 */
	public const NS_URLRANK_HOUR_S = 'urlrank_sh';

	/**
	 * The writer's header record of one server's `urls` bucket — the URL
	 * table's totals, kept as sums, and a `Url_Sketch` of its URLs —
	 * `urlhdr:{bucket}:{HDR_SHAPE}:{server_key}`, and the site's, the union
	 * of every server's, `urlhdr:{bucket}:{HDR_SHAPE}`; the worker family's
	 * after `WORKER_SHARD_PREFIX`, `urlhdr:{bucket}:{HDR_SHAPE}:w:{server_key}`
	 * and `urlhdr:{bucket}:{HDR_SHAPE}:w`. Fine tier; `TABLE_URL_FINE`.
	 * Written beside the lists, by `ranked_writes()`.
	 */
	public const NS_URLHDR = 'urlhdr';

	/** The coarse tier of `urlhdr`, written beside `urlrank_sh`: `urlhdr_h:{Y-m-d-H}:{HDR_SHAPE}:…`. */
	public const NS_URLHDR_HOUR = 'urlhdr_h';

	/**
	 * A header record is positional (decision 18): four sums over every row
	 * of its set the key holds, whether an overflow row was among them, a
	 * `Url_Sketch` of the rest, the hashes the table counts as URLs, and the
	 * requests that timed out or fataled (`ROW_ERRORS`), which only an errored
	 * set's reader reads.
	 */
	public const HDR_COUNT       = 0;
	public const HDR_TIMED_COUNT = 1;
	public const HDR_SUM_MS      = 2;
	public const HDR_SUM_PEAK_MB = 3;
	public const HDR_HAS_OTHER   = 4;
	public const HDR_URLS        = 5;
	public const HDR_ERRORS      = 6;

	/**
	 * The version of the `HDR_*` layout above. Raise it with any change to
	 * what a position holds: 5 sums `ROW_ERRORS` into `HDR_ERRORS`.
	 */
	public const HDR_VERSION = 5;

	/**
	 * The shape a header record is written in, its layout and its sketch's
	 * precision, and a segment of its key: a record another layout or
	 * `Url_Sketch::PRECISION` wrote reads as MISSING rather than standing.
	 */
	public const HDR_SHAPE = 'v' . self::HDR_VERSION . 'p' . Url_Sketch::PRECISION;

	/**
	 * How long a folded URL page is cached, and how often a bucket's ranked
	 * lists are rewritten. ONE constant, because it is one number: the reader
	 * looks this often, so ranking more often spends rankings nobody reads.
	 * Under traffic a ranked row lags live traffic by up to twice it plus
	 * `Flame_Builder_Node::FLUSH_INTERVAL_SEC`, the flush a ranking waits for.
	 * The flame builder flushes from its Router tick whenever it owes work, a
	 * pending bucket included, so on a partition that goes quiet a deferred
	 * ranking runs at the first tick it comes due.
	 */
	public const URL_PAGE_REFRESH_S = 60;

	/** Entries a fine-tier ranked list keeps. A page past this folds. */
	public const URL_RANK_N      = 200;
	/** Entries an hour-tier ranked list keeps: one list ranks twelve buckets. */
	public const URL_RANK_N_HOUR = 500;

	/** The `--sort` values `urls` accepts, and the names of the ranked lists. */
	public const URL_SORTS  = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'last_updated' ];

	/** The sorts on a measured duration, which rank a URL no timed request reached last. */
	public const TIMED_SORTS = [ 'avg_ms', 'min_ms', 'max_ms' ];
	/** The `--order` values `urls` accepts, and the directions each sort is ranked in. */
	public const URL_ORDERS = [ 'asc', 'desc' ];

	/**
	 * The key part of the errored subset of a family's rows: the rows of a
	 * key whose requests include a timeout or a fatal (`errored_rows()`).
	 */
	public const ERRORED_PART = 'e';

	/**
	 * The list sets each ranked key writes, as the key parts each adds after
	 * the time part: every reader row, the reader rows that errored, every
	 * worker row, and the worker rows that errored. Each set is fourteen
	 * lists and a record per server and the site's, and a reader asks for
	 * the sets its filters name (`rank_sets()`).
	 */
	public const RANK_SETS = [
		[],
		[ self::ERRORED_PART ],
		[ self::WORKER_SHARD_PREFIX ],
		[ self::WORKER_SHARD_PREFIX, self::ERRORED_PART ],
	];

	/** The list the URL header's `slowest` reads, `[ sort, order ]`. */
	public const SLOWEST_LIST = [ 'avg_ms', 'desc' ];

	/**
	 * URL name table: `urlmap:{hash}` => `[ server_name, path ]`.
	 *
	 * The server is the one a hash's rows are filed under, which is what a
	 * hash-only read needs to find them; the path is the row's own
	 * `row_path()` against that server. `get_url_names()` joins the two back
	 * into the URL a reader displays.
	 */
	public const NS_URLMAP      = 'urlmap';

	/** Shortest retention window the stats Tables work with, in seconds. */
	public const MIN_RETENTION_SECONDS = 3600;

	/**
	 * A stored DIMENSIONAL entry is positional, indexed by these — decision 18's
	 * shape, as `CAT_MS` below, on the third value to earn it. `{"c":29,"s":1.0,"m":1.0,"t":27}`
	 * is 31 bytes of JSON where `[29,1,1,27]` is 11, across `dim_h`, `dim_h`-by-server
	 * and `url_dim_h` alike: seven dimensions per URL per five-minute slot.
	 * The names are the row's, `DIM_COUNT` beside `ROW_COUNT` and `DIM_TIMED`
	 * beside `ROW_TIMED_COUNT`, because they are the same measurements: every
	 * request counts for volume, and an average divides the timed ones alone
	 * (decision 24). The entry stays positional to the wire, so no
	 * `DIM_FIELD_NAMES` exists.
	 */
	public const DIM_COUNT       = 0;
	public const DIM_SUM_MS      = 1;
	public const DIM_SUM_PEAK_MB = 2;
	public const DIM_TIMED       = 3;

	/** Summed fields of one dimensional value => whether it is a whole count. */
	public const DIM_SUMS = [
		self::DIM_COUNT       => true,
		self::DIM_SUM_MS      => false,
		self::DIM_SUM_PEAK_MB => false,
		self::DIM_TIMED       => true,
	];

	/**
	 * A stored CATEGORY entry is positional, indexed by these — decision 18's
	 * shape on the second value dense enough to earn it. A category series
	 * spells every one of its entries once per five-minute bucket per scope
	 * across the whole window: `{"t":913.207,"c":47,"n":11}` is 30 bytes of
	 * JSON where `[913.207,47,11]` is 15.
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

	/** The overflow row's worker half — see `other_key()`. */
	public const OTHER_WORKER_KEY = 'Other:worker';

	/** Shards the URL index is spread across — one per hex digit of the hash. */
	public const URL_SHARDS     = 16;

	/**
	 * What makes a shard token name WORKER traffic: `urls:{bucket}:{server_key}:w3`.
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
	 * The nine fields that ADD come FIRST, and in `ROW_SUMS` order, so one
	 * map describes the row's summed half.
	 *
	 * `ROW_FIELD_NAMES` below names every index. Four of the fifteen are not
	 * the counts and sums the rest are: `ROW_TIMED_COUNT` counts only the
	 * requests whose duration was measured, which is what `min_ms` folds from;
	 * `ROW_ERRORS` counts only the requests that timed out or fataled
	 * (`Flame_Builder_Node::error_counts()`), whatever status they answered;
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
	public const ROW_ERRORS      = 8;
	public const ROW_MIN_MS      = 9;
	public const ROW_MAX_MS      = 10;
	public const ROW_MAX_PEAK_MB = 11;
	public const ROW_LAST_SEEN   = 12;
	public const ROW_WORKER      = 13;
	public const ROW_PATH        = 14;

	/**
	 * Longest `ROW_PATH` kept, in bytes, `…` included. The hash is the
	 * identity and the path is display, so a query-string flood cannot grow
	 * one row past it.
	 */
	public const MAX_PATH_BYTES = 1024;

	/**
	 * A ranked-list entry is POSITIONAL (decision 18): the hash, the row's
	 * fourteen numbers `ROW_COUNT`..`ROW_WORKER` and never `ROW_PATH`, and on
	 * the `url` lists alone the URL it ranked by: its `ROW_PATH` on a
	 * server's list, which the server's name joins to the whole URL
	 * (`join_url()`), and the whole URL itself on the site's.
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
		self::ROW_ERRORS      => true,
	];

	/**
	 * Every stored index and what it holds — the ONE place an index becomes a
	 * name. `fold_index_row()` names the row at the storage/display boundary,
	 * and a test helper reverses it to seed a row in names. Nothing else
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
		self::ROW_ERRORS      => 'errors',
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

	/** Seconds an hour key spans. */
	public const HOUR_SECONDS = 3600;

	/**
	 * Five-minute slots one chart hour value holds (decision 35), positional
	 * and indexed by `slot_of()`. BUCKET_MINUTES divides 60, so this is exact.
	 */
	public const SLOTS_PER_HOUR = 60 / self::BUCKET_MINUTES;

	/**
	 * Ceiling on one reader's bucket enumeration (24h at the 300s width).
	 *
	 * This bounds BUCKETS, not keys: the URL index asks for one key per server
	 * per shard per bucket, so a full read costs `URL_SHARDS x` this per
	 * server. That is the trade sharding makes, and it is the right way
	 * round: a point read for one URL costs a single shard, and no value
	 * approaches `ITEM_BUDGET`, which one unsharded blob exceeds.
	 */
	public const MAX_READ_BUCKETS = 288;

	/**
	 * Hour keys a chart reads: the MAX_READ_BUCKETS slots it draws span 24
	 * hours, and trailing from the current bucket they touch one hour more
	 * (decision 35).
	 */
	public const CHART_HOURS = self::MAX_READ_BUCKETS * self::BUCKET_SECONDS / self::HOUR_SECONDS + 1;

	/**
	 * How long a FINE `urls` or `urlsrv` bucket is kept, against
	 * `<eln:stats_ttl>` for the coarse tier that outlives it.
	 *
	 * The tier has exactly two consumers: a reader, which reads the current
	 * hour's buckets and no others (`read_plan()`), and `roll_up_hours()`,
	 * which builds every coarse tier out of a closed hour's fine buckets. It
	 * is the window's EDGE and the fold's input — never a tier to read old
	 * hours from.
	 *
	 * Two hours covers both: the current hour, and the hour just closed for
	 * the fold and for a late write into it. The fold waits on the builder's
	 * data clock, so a replay holds an hour open longer; an hour held for all
	 * but a bucket of `fine_ttl()` folds regardless. At that width the tier
	 * holds 24 buckets a shard, where the coarse tier keeps one per hour for
	 * `<eln:stats_ttl>`, 25 at the 43,200 s `min_lifetime` default.
	 * `fine_ttl()` caps it at the window, so a window under two hours bounds
	 * the fine tier instead.
	 */
	public const FINE_TTL_SECONDS = 7200;

	/** Every namespace but the two below, for the window floored at CHART_HOURS. */
	public const TABLE_AGGREGATE = 'flame-stats:aggregate';

	/** The per-URL blob, `url`, for `<eln:stats_url_ttl>`. */
	public const TABLE_URL = 'flame-stats:url';

	/** The fine tier — `urls`, `urlsrv`, `urlrank_s`, `urlhdr` — for `<eln:stats_url_fine_ttl>`. */
	public const TABLE_URL_FINE = 'flame-stats:url-fine';

	/** Every Table `flame-builder.tsl` declares: what a writer names and a reader mounts. */
	public const TABLES = [ self::TABLE_AGGREGATE, self::TABLE_URL, self::TABLE_URL_FINE ];

	/**
	 * A reader's memo of the server index, `key => index`, for the one
	 * reply the store serves: every shard of a read asks the same buckets,
	 * and each would read the index again. Null, the default, reads every
	 * time, which is what the long-lived writer needs.
	 * `Performance_CI_Node::stats_stores()` sets it to [] on each reply's stores.
	 *
	 * @var array<string,array<string,array{0:string,1:int}>|null>|null
	 */
	public ?array $server_indexes = null;

	/**
	 * A reader's memo of the DONE markers, `{server_key}:{hour} => present`,
	 * for the one reply the store serves: the header, its `slowest` lists and
	 * the ranked page each ask whether an hour is owed. Null, the default,
	 * reads every time. `Performance_CI_Node::stats_stores()` sets it to []
	 * beside `$server_indexes`. A marker a Table left unanswered is never
	 * kept.
	 *
	 * @var array<string,bool>|null
	 */
	public ?array $done_markers = null;

	/** @var int Retention window in seconds, as Config::stats_retention_seconds() floored it. */
	private int $max_lifespan;

	/** @var array<string,string> Declared Table => the node answering for it. */
	private array $table_names;

	/** @var array<string,int>|null Shard token => its bit, built on the first `shard_mask()`. */
	private static ?array $shard_bits = null;

	/** @var array<string,list<string>> Memoized `chart_buckets()`, under the one bucket it ends at. */
	private static array $chart_buckets = [];

	/**
	 * The hourly slot's summed fields; anything else rides through. `count`
	 * and `sum_ms` are the timed requests' (decision 24), and `requests` and
	 * `sum_peak_mb` every request's, since a peak is measured either way.
	 */
	private const HOURLY_SUMS = [ 'count' => true, 'sum_ms' => false, 'requests' => true, 'sum_peak_mb' => false ];

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
	 * @param int                  $max_lifespan Retention window in seconds; callers pass
	 *                                           `Config::stats_retention_seconds()`, which is
	 *                                           where that window is declared.
	 * @param Table_Client         $client       The owner's asker; every exchange is in-process.
	 * @param array<string,string> $table_names  Declared Table => the node answering for it,
	 *                                           for each Table this store may ask.
	 */
	public function __construct( int $max_lifespan, private readonly Table_Client $client, array $table_names ) {
		$this->max_lifespan = $max_lifespan;
		$this->table_names  = $table_names;
	}

	/**
	 * The key parts of a dimensional scope.
	 *
	 * @param string $dimension Dimension name, e.g. `ua`.
	 * @param string $server    Reporting server; '' is the global series.
	 * @return list<string>
	 */
	public static function dim_parts( string $dimension, string $server ): array {
		return '' === $server ? [ self::NS_DIM_HOUR, $dimension ] : [ self::NS_DIM_HOUR, $dimension, self::server_key( $server ) ];
	}

	/**
	 * The key parts of a category scope.
	 *
	 * @param string $server Reporting server; '' is the global series.
	 * @return list<string>
	 */
	public static function cat_parts( string $server ): array {
		return '' === $server ? [ self::NS_CAT_HOUR ] : [ self::NS_CAT_HOUR, self::server_key( $server ) ];
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
	 * @param-out array<string,array<string,array{0:string,1:int}>> $index
	 * @param array<string,array<string,array{0:string,1:int}>>|null $index Set to the index the
	 *                                                                     rows were read under.
	 * @param-out bool $failed
	 * @param ?bool    $failed Set true when a Table left some key of the index or the rows unanswered.
	 * @return list<array{0: string, 1: array<array-key,mixed>, 2: string}>
	 */
	public function url_hour_sources( array $hours, ?string $shard = null, bool $workers = false, string $server = '', ?array &$index = null, ?bool &$failed = null ): array {
		return $this->shard_sources( true, $hours, $shard, $workers, $server, $index, $failed );
	}

	/**
	 * One scope's ranked lists across both tiers, as `[key, entries]` pairs,
	 * in one round trip after the server index's own.
	 *
	 * One list a key a set: a server scope reads that server's where the
	 * key's index names it, and the site reads the site's, which the writer
	 * merged from every server's (`ranked_writes()`), where the index names
	 * any server at all. Every set's lists ride the one round trip, and a
	 * key answers with them end to end: no hash sits in two of the sets a
	 * page reads, since a hash is one family's and the errored sets are read
	 * apart from the others.
	 *
	 * An hour answers only when every set's list stands, since the hour
	 * stands for twelve buckets and a reader serving it ranked must see all
	 * of it; an hour whose index does not name the scope answers with an
	 * empty list, the scope idle in it. An hour the writer still owes
	 * (`waiting_hours()`) answers nothing and is named in `$waiting`. A fine
	 * bucket answers with the list it holds, which is what a ranking not yet
	 * due leaves.
	 *
	 * @param array<int,string>  $hours   Hour keys.
	 * @param array<int,string>  $buckets Bucket keys.
	 * @param string             $sort    A `URL_SORTS` value.
	 * @param string             $order   A `URL_ORDERS` value.
	 * @param string             $server  Reporting server; '' is the site.
	 * @param list<list<string>> $sets    Of `RANK_SETS`, as `rank_sets()` names them.
	 * @param-out list<string>   $waiting
	 * @param list<string>|null  $waiting Set to the hours the writer still owes.
	 * @return list<array{0: string, 1: array<array-key,mixed>}>
	 */
	public function url_rank_window( array $hours, array $buckets, string $sort, string $order, string $server, array $sets = [ [] ], ?array &$waiting = null ): array {
		$tiers = [
			[ true, $hours ],
			[ false, $buckets ],
		];
		$index = $this->scope_index( $hours, $buckets, $server );
		$reads = [];
		foreach ( $tiers as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				foreach ( [] === ( $index[ $key ] ?? [] ) ? [] : $sets as $set ) {
					$reads[] = [ self::url_rank_parts( $sort, $order, $server, $hour, $set ), $key ];
				}
			}
		}
		$done    = $this->done_reads( $hours, $index );
		$values  = $this->bucket_get_multi( [ ...$reads, ...$done ], $failed );
		$waiting = $this->waiting_hours( $hours, $index, $done, \array_slice( $values, \count( $reads ) ), $failed );
		$owed    = \array_flip( $waiting );
		$lists   = [];
		$missing = [];
		foreach ( $reads as $at => [ , $key ] ) {
			if ( null === $values[ $at ] ) {
				$missing[ $key ] = true;
				continue;
			}
			$lists[ $key ] = [ ...$lists[ $key ] ?? [], ...$values[ $at ] ];
		}
		$out = [];
		foreach ( $tiers as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				$whole = isset( $index[ $key ] ) && ! isset( $missing[ $key ] ) && ! isset( $owed[ $key ] );
				if ( $hour ? ! $whole : ! isset( $lists[ $key ] ) ) {
					continue;
				}
				$out[] = [ $key, $lists[ $key ] ?? [] ];
			}
		}
		return $out;
	}

	/**
	 * One scope's header records across both tiers, by key and then by set,
	 * in one round trip after the server index's own. A page counts each
	 * set's overflow row apart, so the sets' records are answered apart.
	 *
	 * A key whose index names no server in the scope answers with the empty
	 * record: the scope idle there, or an hour folded idle. One whose index
	 * names the scope answers with the scope's record — the site's, or the
	 * server's — or null where that record is missing, a HOLE. A key holding
	 * no index is absent: a bucket nothing wrote. An hour the writer still
	 * owes (`waiting_hours()`) is absent too, and named in `$waiting`.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string             $server  Reporting server; '' is the site.
	 * @param list<list<string>> $sets    Of `RANK_SETS`, as `rank_sets()` names them.
	 * @param-out list<string>   $waiting
	 * @param list<string>|null  $waiting Set to the hours the writer still owes.
	 * @return array<string,array<int,Url_Header|null>> Key => each set's record, by its place in `$sets`.
	 */
	public function url_headers( array $hours, array $buckets, string $server, array $sets = [ [] ], ?array &$waiting = null ): array {
		$index = $this->scope_index( $hours, $buckets, $server );
		$out   = [];
		$reads = [];
		$owner = [];
		foreach ( [ [ true, $hours ], [ false, $buckets ] ] as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $index[ $key ] ) ) {
					continue;
				}
				$out[ $key ] = \array_fill( 0, \count( $sets ), self::url_header_of( [] ) );
				foreach ( [] === $index[ $key ] ? [] : $sets as $at => $set ) {
					$reads[] = [ self::url_header_parts( $server, $hour, $set ), $key ];
					$owner[] = $at;
				}
			}
		}
		$done    = $this->done_reads( $hours, $index );
		$values  = $this->bucket_get_multi( [ ...$reads, ...$done ], $failed );
		$waiting = $this->waiting_hours( $hours, $index, $done, \array_slice( $values, \count( $reads ) ), $failed );
		foreach ( $reads as $at => [ , $key ] ) {
			$out[ $key ][ $owner[ $at ] ] = self::url_header_record( $values[ $at ] );
		}
		return \array_diff_key( $out, \array_flip( $waiting ) );
	}

	/**
	 * A stored header record, typed, or null where it is missing or holds no
	 * sketch (`Url_Sketch::is_sketch()`).
	 *
	 * @param array<array-key,mixed>|null $raw A decoded record.
	 * @return Url_Header|null
	 */
	private static function url_header_record( ?array $raw ): ?array {
		$sketch = $raw[ self::HDR_URLS ] ?? null;
		if ( ! \is_string( $sketch ) || ! Url_Sketch::is_sketch( $sketch ) ) {
			return null;
		}
		return [
			self::HDR_COUNT       => Core::num_int( $raw[ self::HDR_COUNT ] ?? null ),
			self::HDR_TIMED_COUNT => Core::num_int( $raw[ self::HDR_TIMED_COUNT ] ?? null ),
			self::HDR_SUM_MS      => Core::num_float( $raw[ self::HDR_SUM_MS ] ?? null ),
			self::HDR_SUM_PEAK_MB => Core::num_float( $raw[ self::HDR_SUM_PEAK_MB ] ?? null ),
			self::HDR_HAS_OTHER   => true === ( $raw[ self::HDR_HAS_OTHER ] ?? null ),
			self::HDR_URLS        => $sketch,
			self::HDR_ERRORS      => Core::num_int( $raw[ self::HDR_ERRORS ] ?? null ),
		];
	}

	/**
	 * The hours of `$hours` the writer still owes the scope: one holding no
	 * index, or one a server it names has no DONE marker for. Such an hour
	 * waits on the builder's data clock, or on the re-rank a late write
	 * owes, so a reader reads it as provisional-empty and never as a hole:
	 * a hole sends the page to the whole-index fold. A read a Table left
	 * unanswered lands here too, the index's or the marker's, and the
	 * provisional, uncached answer is the one decision 3 wants for it. What
	 * this read answered joins `$done_markers`; what it left unanswered does
	 * not, so the next reply asks again.
	 *
	 * @param array<int,string>                                  $hours  Hour keys.
	 * @param array<string,array<string,array{0:string,1:int}>> $index  The scope's index.
	 * @param list<array{0: array<int,string>, 1: string}>       $done   `done_reads()`.
	 * @param array<array<string,mixed>|null>                    $values What each of `$done` read.
	 * @param bool                                               $failed A Table left some key of the batch unanswered.
	 * @return list<string>
	 */
	private function waiting_hours( array $hours, array $index, array $done, array $values, bool $failed ): array {
		$seen     = [];
		$answered = [];
		foreach ( \array_values( $values ) as $at => $marker ) {
			[ $parts, $hour ] = $done[ $at ];
			$key              = "{$parts[2]}:{$hour}";
			$seen[ $key ]     = null !== $marker;
			if ( null !== $marker || ! $failed ) {
				$answered[ $key ] = null !== $marker;
			}
		}
		if ( null !== $this->done_markers ) {
			$this->done_markers = $answered + $this->done_markers;
		}
		$known = $seen + ( $this->done_markers ?? [] );
		$owed  = \array_fill_keys( \array_diff( $hours, \array_keys( $index ) ), true );
		foreach ( $hours as $hour ) {
			foreach ( \array_keys( $index[ $hour ] ?? [] ) as $server_key ) {
				if ( ! ( $known[ "{$server_key}:{$hour}" ] ?? false ) ) {
					$owed[ $hour ] = true;
				}
			}
		}
		return \array_map( 'strval', \array_keys( $owed ) );
	}

	/**
	 * The DONE marker of every server a scope's index names in each hour of
	 * `$hours`, as reads to ride the hour's own list or record read.
	 *
	 * @param array<int,string>                                  $hours Hour keys.
	 * @param array<string,array<string,array{0:string,1:int}>> $index The scope's index.
	 * @return list<array{0: array<int,string>, 1: string}>
	 */
	private function done_reads( array $hours, array $index ): array {
		$reads = [];
		foreach ( $hours as $hour ) {
			foreach ( \array_map( 'strval', \array_keys( $index[ $hour ] ?? [] ) ) as $server_key ) {
				if ( ! isset( $this->done_markers[ "{$server_key}:{$hour}" ] ) ) {
					$reads[] = [ self::url_rank_done_parts( $server_key ), $hour ];
				}
			}
		}
		return $reads;
	}

	/**
	 * Read one scope's slotted hour values in a single round trip and lay
	 * their slots out as the buckets they hold, `{hour}-{MM} => slot`
	 * (decision 35). A slot the hour never filled, or filled with nothing
	 * measured, is absent, as a bucket nothing wrote is.
	 *
	 * @param array<int,string> $parts     A slotted scope's key parts: `hourly_parts()`,
	 *                                     `dim_parts()`, `url_dim_parts()`,
	 *                                     `cat_parts()` or `url_cat_parts()`.
	 * @param array<int,string> $hours     `Y-m-d-H` hour keys.
	 * @param ?string           $dimension The dimension a `url_dim_parts()` row
	 *                                     holds the slotted hour under; null
	 *                                     where the value is the slotted hour.
	 * @return array<string,array<array-key,mixed>> Slot values keyed by bucket.
	 */
	public function get_slots( array $parts, array $hours, ?string $dimension = null ): array {
		$out = [];
		foreach ( $this->lookup_hours( $parts, $hours ) as $hour => $stored ) {
			$buckets = self::buckets_in_hour( $hour );
			$slots   = null === $dimension ? $stored : Core::arr( $stored )[ $dimension ] ?? null;
			foreach ( Core::arr( $slots ) as $slot => $value ) {
				if ( isset( $buckets[ $slot ] ) && \is_array( $value ) && [] !== $value ) {
					$out[ $buckets[ $slot ] ] = $value;
				}
			}
		}
		return $out;
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
	 * Read many leaderboard hours, global or per server: each one sum.
	 *
	 * @param array<int,string> $hours  `Y-m-d-H` hour keys.
	 * @param string            $server Reporting server; '' reads the global board.
	 * @param ?bool             $failed Set true when a Table left some hour unanswered.
	 * @param-out bool          $failed
	 * @return array<string,mixed> Sums keyed by hour; misses absent.
	 */
	public function get_leaderboard_hours( array $hours, string $server = '', ?bool &$failed = null ): array {
		return $this->lookup_hours( self::lb_parts( $server ), $hours, $failed );
	}

	/**
	 * The key parts of a leaderboard scope — the one place the global
	 * and per-server keyspaces differ.
	 *
	 * @param string $server Reporting server; '' for the global board.
	 * @return list<string>
	 */
	public static function lb_parts( string $server ): array {
		return '' === $server ? [ self::NS_LB_HOUR ] : [ self::NS_LB_S_HOUR, self::server_key( $server ) ];
	}

	/**
	 * Read one scope's hour values in a single round trip.
	 *
	 * Decisions 1 and 6, through `bucket_get_multi()`, so it answers a
	 * failed read, and a missing backend, as every read does.
	 *
	 * @param array<int,string> $parts  The scope's key parts.
	 * @param array<int,string> $hours  `Y-m-d-H` hour keys.
	 * @param ?bool             $failed Set true when a Table left some hour unanswered.
	 * @param-out bool          $failed
	 * @return array<string,mixed> Values keyed by hour; misses absent.
	 */
	private function lookup_hours( array $parts, array $hours, ?bool &$failed = null ): array {
		$reads = [];
		foreach ( $hours as $hour ) {
			$reads[] = [ $parts, $hour ];
		}
		$values = $this->bucket_get_multi( $reads, $batch_failed );
		$failed = self::unanswered( $values, $batch_failed );
		$out    = [];
		foreach ( $values as $at => $value ) {
			if ( null !== $value ) {
				$out[ $reads[ $at ][1] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Every source of URL rows for a window, as `[bucket, rows, server]`
	 * triples.
	 *
	 * Triples rather than a merged map: one server's shard is complete for
	 * the hashes it covers, and the caller owns how it combines them. The
	 * shards each index entry names, in one round trip after the index's own.
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
	 * Both tiers share one key geometry — `{ns}:{bucket}:{server_key}:{shard}`
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
	 * @param-out array<string,array<string,array{0:string,1:int}>> $index
	 * @param array<string,array<string,array{0:string,1:int}>>|null $index Set to the index the
	 *                                                                     rows were read under.
	 * @param-out bool $failed
	 * @param ?bool    $failed Set true when a Table left some key of the index or the rows unanswered.
	 * @return list<array{0: string, 1: array<array-key,mixed>, 2: string}>
	 */
	private function shard_sources( bool $hour, array $buckets, ?string $shard, bool $workers, string $server, ?array &$index = null, ?bool &$failed = null ): array {
		$bit   = null === $shard ? null : self::shard_mask( [ $shard ] );
		$index = $hour
			? $this->scope_index( $buckets, [], $server, $index_failed )
			: $this->scope_index( [], $buckets, $server, $index_failed );
		$reads = [];
		$names = [];
		foreach ( $index as $bucket => $servers ) {
			foreach ( $servers as $key => [ self::SRV_NAME => $name, self::SRV_SHARDS => $mask ] ) {
				$shards = null === $bit ? self::shards_in( $mask, $workers ) : ( 0 !== ( $mask & $bit ) ? [ $shard ] : [] );
				foreach ( $shards as $one ) {
					$reads[] = [ $hour ? self::url_hour_parts( $key, $one ) : self::url_shard_parts( $key, $one ), $bucket ];
					$names[] = $name;
				}
			}
		}
		$out    = [];
		$values = $this->bucket_get_multi( $reads, $rows_failed );
		$failed = $index_failed || self::unanswered( $values, $rows_failed );
		foreach ( $values as $at => $value ) {
			if ( null !== $value ) {
				$out[] = [ $reads[ $at ][1], $value, $names[ $at ] ];
			}
		}
		return $out;
	}

	/**
	 * Key parts of one server's shard of the FINE URL index.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @param string $shard      Shard name from `url_shard()`.
	 * @return array<int,string>
	 */
	public static function url_shard_parts( string $server_key, string $shard ): array {
		return [ self::NS_URLS, $server_key, $shard ];
	}

	/**
	 * Key parts of one server's shard of the COARSE hourly URL index.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @param string $shard      Shard name from `url_shard()`.
	 * @return array<int,string>
	 */
	public static function url_hour_parts( string $server_key, string $shard ): array {
		return [ self::NS_URLS_HOUR, $server_key, $shard ];
	}

	/**
	 * The shards a mask sets: the reader family's, and the worker family's too
	 * when `$workers`.
	 *
	 * @param int  $mask    An index entry's `SRV_SHARDS`.
	 * @param bool $workers Include the WORKER shard family.
	 * @return list<string>
	 */
	public static function shards_in( int $mask, bool $workers ): array {
		$out = [];
		foreach ( $workers ? self::every_shard() : self::url_shards() as $bit => $shard ) {
			if ( 0 !== ( $mask & ( 1 << $bit ) ) ) {
				$out[] = $shard;
			}
		}
		return $out;
	}

	/**
	 * The index entries each key of a scope is read under: every entry for
	 * the site, the one naming the server for a server, and none where the
	 * key's index does not name it, which is that server idle in the key.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string            $server  One server; '' is the site.
	 * @param ?bool             $failed  Set true when a Table left some key unanswered.
	 * @param-out bool          $failed
	 * @return array<string,array<string,array{0:string,1:int}>> key => server_key =>
	 *                                                         entry; a key holding no index is absent.
	 */
	private function scope_index( array $hours, array $buckets, string $server, ?bool &$failed = null ): array {
		$index = $this->server_index( $hours, $buckets, $failed );
		if ( '' === $server ) {
			return $index;
		}
		$key = self::server_key( $server );
		return \array_map(
			static fn ( array $entries ): array => \array_intersect_key( $entries, [ $key => true ] ),
			$index
		);
	}

	/**
	 * Which servers each key of both tiers holds rows for, in ONE round trip
	 * through the reader's memo where it has one. An hour key never spells a
	 * bucket key, so the answer and the memo key by the key itself.
	 *
	 * `$failed` says a Table left some keys unanswered. What it answered
	 * is answered and memoized; a key it left unanswered is neither,
	 * and reads as holding no index, which a caller walking it must not take
	 * for servers idle there.
	 *
	 * @param array<int,string> $hours   Hour keys.
	 * @param array<int,string> $buckets Bucket keys.
	 * @param ?bool             $failed  Set true when a Table left some key unanswered.
	 * @param-out bool          $failed
	 * @return array<string,array<string,array{0:string,1:int}>> key => server_key =>
	 *                                                         entry; a key holding no index is absent.
	 */
	public function server_index( array $hours, array $buckets, ?bool &$failed = null ): array {
		$failed = false;
		$reads  = [];
		foreach ( [ [ true, $hours ], [ false, $buckets ] ] as [ $hour, $keys ] ) {
			foreach ( $keys as $key ) {
				if ( ! \array_key_exists( $key, $this->server_indexes ?? [] ) ) {
					$reads[ $key ] = [ self::url_srv_parts( $hour ), $key ];
				}
			}
		}
		$found        = [];
		$batch_failed = false;
		foreach ( [] === $reads ? [] : $this->bucket_get_multi( $reads, $batch_failed ) as $key => $index ) {
			if ( null === $index && $batch_failed ) {
				$failed = true;
				continue;
			}
			$found[ (string) $key ] = null === $index ? null : self::index_entries( $index );
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
	 * The bits a set of shard tokens sets in an index entry's mask.
	 *
	 * @param list<string> $shards Shard tokens, as `url_shard()` spells them.
	 * @throws \LogicException On a token no shard answers to.
	 */
	public static function shard_mask( array $shards ): int {
		// Built once: a search calls this per candidate, thousands per reply.
		$bits = self::$shard_bits ??= \array_flip( self::every_shard() );
		$mask = 0;
		foreach ( $shards as $shard ) {
			$mask |= 1 << ( $bits[ $shard ] ?? throw new \LogicException( "no such shard: {$shard}" ) );
		}
		return $mask;
	}

	/**
	 * Every shard of both families, reader first: a shard's position is its
	 * bit in an index entry's mask.
	 *
	 * @return list<string>
	 */
	public static function every_shard(): array {
		return [ ...self::url_shards(), ...self::url_shards( true ) ];
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
	 * Which servers each of `$hours` holds unranked: two batched reads, the
	 * hour's server index, then the DONE marker of every server it names.
	 *
	 * A marker stands for its server's whole hour — the fold writes it only
	 * when every shard it wrote landed, and a ranking only over every shard
	 * the index names — so no row is fetched to say an hour is settled. An
	 * hour holding no index is absent, unfolded; one whose every server is
	 * marked answers with none. The index goes first, because it says which
	 * markers the second read asks for (decision 6). No chart hour key is
	 * asked after: the flush writes those through, so none is the fold's.
	 *
	 * `$failed` says a Table left some key of either read unanswered, and
	 * then an hour reading as unfolded may be one the read could not see.
	 *
	 * @param array<int,string> $hours  Hour keys to probe.
	 * @param ?bool             $failed Set true when a Table left some key unanswered.
	 * @param-out bool          $failed
	 * @return array<string,list<string>> Hour => the servers its index names
	 *                                    with no marker; only hours holding an index.
	 */
	public function url_hours_derived( array $hours, ?bool &$failed = null ): array {
		$reads = [];
		foreach ( $hours as $hour ) {
			$reads[] = [ self::url_srv_parts( true ), $hour ];
		}
		$read  = $this->bucket_get_multi( $reads, $heads_failed );
		$keys  = [];
		$owner = [];
		$out   = [];
		foreach ( \array_values( $hours ) as $at => $hour ) {
			if ( null === ( $read[ $at ] ?? null ) ) {
				continue;
			}
			$out[ $hour ] = [];
			foreach ( self::index_entries( $read[ $at ] ) as $key => [ self::SRV_NAME => $name ] ) {
				$keys[]  = [ self::url_rank_done_parts( $key ), $hour ];
				$owner[] = $name;
			}
		}
		$values = $this->bucket_get_multi( $keys, $values_failed );
		$failed = self::unanswered( $read, $heads_failed ) || self::unanswered( $values, $values_failed );
		foreach ( $values as $at => $value ) {
			if ( null === $value ) {
				$out[ $keys[ $at ][1] ][] = $owner[ $at ];
			}
		}
		return $out;
	}

	/**
	 * Whether a batch read left any key unanswered: the batch failed and a
	 * key came back null, which the backing could not fill and which is
	 * therefore no absence.
	 *
	 * @param array<array-key,mixed> $values What the read answered, a null for each miss.
	 * @param bool                   $failed The read's `$failed`.
	 */
	public static function unanswered( array $values, bool $failed ): bool {
		return $failed && \in_array( null, $values, true );
	}

	/**
	 * Key parts of one server's DONE marker for an hour:
	 * `urlrank_sh:{hour}:done:{server_key}`.
	 *
	 * A server's hour is fourteen lists, so none of them can stand for the
	 * set. This one tiny key says the server's ranking of the hour ran over
	 * every shard its index names, and rides that ranking's batch whatever
	 * its lists answer; a fold that lost a write leaves it unwritten. `done`
	 * is never a server key, which is hex, so it can collide with no list.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @return array<int,string>
	 */
	public static function url_rank_done_parts( string $server_key ): array {
		return [ self::NS_URLRANK_HOUR_S, 'done', $server_key ];
	}

	/**
	 * Decode a stored server index: re-keyed, because an all-digit server key
	 * arrives as an int, and re-typed, because a truncated, corrupt or
	 * earlier-shaped entry is whatever it decoded to. An entry that is not a
	 * name beside a mask names no server, and is dropped.
	 *
	 * @param array<array-key,mixed> $raw Decoded index.
	 * @return array<string,array{0:string,1:int}> server_key => [ name, shards ].
	 */
	public static function index_entries( array $raw ): array {
		$out = [];
		foreach ( $raw as $key => $entry ) {
			$name   = \is_array( $entry ) ? $entry[ self::SRV_NAME ] ?? null : null;
			$shards = \is_array( $entry ) ? $entry[ self::SRV_SHARDS ] ?? null : null;
			if ( \is_string( $name ) && \is_int( $shards ) ) {
				$out[ (string) $key ] = [ self::SRV_NAME => $name, self::SRV_SHARDS => $shards ];
			}
		}
		return $out;
	}

	/**
	 * Read many buckets across DIFFERENT namespaces in one round trip.
	 *
	 * `lookup_hours()` reads one namespace over many hours; this reads
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
	 * @param ?bool                                                   $failed Set true when a Table
	 *                                                                        asked did not answer.
	 * @param-out bool                                                $failed
	 * @return array<array-key,array<string,mixed>|null> One entry per read, keyed as `$reads` was.
	 */
	public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
		$failed = false;
		if ( [] === $reads ) {
			return [];
		}
		$keys  = [];
		$asked = [];
		foreach ( $reads as $i => [ $parts, $bucket ] ) {
			$keys[ $i ]                              = self::key_at( $parts, $bucket );
			$asked[ $this->table_for( $parts[0] ) ][] = $keys[ $i ];
		}
		$found = [];
		foreach ( $asked as $table => $table_keys ) {
			$found += $this->client->get_multi( $table, $table_keys, $one_failed );
			$failed = $failed || $one_failed;
		}
		$out = [];
		foreach ( $keys as $i => $key ) {
			$value     = $found[ $key ] ?? null;
			$out[ $i ] = \is_array( $value ) ? self::string_keys( $value ) : null;
		}
		return $out;
	}

	/**
	 * Key parts of the URL index's server index.
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
	 * Group named paths by every word each is filed under — the one place
	 * the tokenize-and-group loop is spelled, for the flush and for a test
	 * seeding what the flush would have written.
	 *
	 * @param array<array-key,string> $names hash => path.
	 * @return array<array-key,list<string>> token => hashes.
	 */
	public static function token_sets_of( array $names ): array {
		$out = [];
		foreach ( $names as $hash => $path ) {
			foreach ( self::term_tokens( $path ) as $token ) {
				$out[ $token ][] = (string) $hash;
			}
		}
		return $out;
	}

	/**
	 * The token sets this partition holds for `$servers`, unioned per token
	 * across every bucket of the window (`token_buckets()`), in one
	 * `SMEMBERS` exchange asking each set for `URL_SEARCH_MAX` members.
	 *
	 * WHICH tokens can be answered at all is the schema's to say: `false` is
	 * a token too common to narrow for any server asked — one of its
	 * buckets' sets holds more than `URL_SEARCH_MAX` members, or its buckets
	 * together do — or every token when the read went unanswered, and a
	 * token none of them holds is ABSENT, which is a real answer narrowing
	 * to nothing.
	 *
	 * A member filed before `window_start()` is dropped before anything is
	 * counted: every URL the window reads rows of was filed inside it, so
	 * such a member names no row and would only count toward the limit. A
	 * set the Table answers over-limit counts every live member, those too.
	 *
	 * `$named_by` says which servers' sets named each hash, over every token
	 * answered, which is where a reader finds the hash's rows.
	 *
	 * @param list<string> $tokens   At most `SEARCH_WORDS_READ` tokens, one of
	 *                               `search_groups()`, as `term_tokens()` spells them.
	 * @param list<string> $servers  Server names whose sets to read.
	 * @param int          $now      The reply's tick, which dates the window.
	 * @param-out bool     $failed
	 * @param ?bool        $failed   Set true when the Table left the read unanswered.
	 * @param-out array<string,array<string,true>> $named_by
	 * @param array<string,array<string,true>>|null $named_by Set to hash => the servers naming it.
	 * @return array<string,list<string>|false> token => hashes, or false when
	 *                                          no read can answer it; absent when unheld.
	 * @throws \LogicException On more tokens than one read names.
	 */
	public function url_token_sets( array $tokens, array $servers, int $now, ?bool &$failed = null, ?array &$named_by = null ): array {
		$named_by = [];
		if ( \count( $tokens ) > self::SEARCH_WORDS_READ ) {
			throw new \LogicException( 'Stats_Store::url_token_sets() reads at most ' . self::SEARCH_WORDS_READ . ' words; read a term through search_groups()' );
		}
		$asked = [];
		foreach ( $tokens as $token ) {
			foreach ( $servers as $server ) {
				foreach ( $this->token_buckets( $now ) as $bucket ) {
					$asked[ self::key_at( [ ...self::url_token_parts( self::server_key( $server ) ), $token ], $bucket ) ] = [ $token, $server ];
				}
			}
		}
		$found = $this->client->members( $this->table_for( self::NS_URLTOKEN ), \array_keys( $asked ), self::URL_SEARCH_MAX, $failed );
		if ( $failed ) {
			return \array_fill_keys( $tokens, false );
		}
		// A member filed before the window indexes no row the window reads.
		$floor = self::window_start( $this->max_lifespan, $now );
		$sets  = [];
		foreach ( $found as $key => $members ) {
			[ $token, $server ] = $asked[ (string) $key ];
			$held               = $sets[ $token ][ $server ] ?? [];
			$sets[ $token ][ $server ] = null === $members || false === $held
				? false
				: $held + \array_filter( $members, static fn ( mixed $at ): bool => Core::num_int( $at ) >= $floor );
		}
		$out = [];
		foreach ( $tokens as $token ) {
			if ( ! isset( $sets[ $token ] ) ) {
				continue;
			}
			$union = [];
			foreach ( $sets[ $token ] as $held ) {
				if ( false === $held || \count( $held ) > self::URL_SEARCH_MAX ) {
					$union = false;
					break;
				}
				$union += $held;
			}
			if ( [] !== $union ) {
				$out[ $token ] = false === $union ? false : \array_map( 'strval', \array_keys( $union ) );
			}
			foreach ( false === $union ? [] : $sets[ $token ] as $server => $held ) {
				foreach ( \array_keys( Core::arr( $held ) ) as $hash ) {
					$named_by[ (string) $hash ][ $server ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Every `urltoken` bucket from the start of the window a reader reads
	 * (`window_start()`) to `$now`, oldest first: at most
	 * `ceil( window / TOKEN_BUCKET_SECONDS ) + 1`. A URL whose rows a reader
	 * reads was filed no earlier than the hour those rows fall in, so its
	 * members sit in one of these.
	 *
	 * @param int $now The reply's tick.
	 * @return list<string>
	 */
	public function token_buckets( int $now ): array {
		$buckets = [];
		$last    = self::token_bucket( $now );
		$at      = self::window_start( $this->max_lifespan, $now );
		do {
			$buckets[] = self::token_bucket( $at );
			$at       += self::TOKEN_BUCKET_SECONDS;
		} while ( \end( $buckets ) < $last );
		return $buckets;
	}

	/**
	 * When the window a reader reads begins: the start of the oldest hour
	 * `read_plan()` reads, or of the current hour where it reads none.
	 *
	 * The floor of the window as a TIMESTAMP, for readers that compare against
	 * one rather than against keys — and it is read off the same plan, so
	 * MAX_READ_BUCKETS caps both alike and no reader can bound itself by a
	 * window wider than the one it reads.
	 *
	 * @param int $retention_seconds How far back the window reaches.
	 * @param int $now               Clock, so a test window matches its writer's keys.
	 * @return int Unix timestamp of the oldest read hour's start.
	 */
	public static function window_start( int $retention_seconds, int $now ): int {
		// The plan's hours run back whole and unbroken from the current one.
		$hours = \count( self::read_plan( self::retention_buckets( $retention_seconds, $now ) )['hours'] );
		return $now - $now % self::HOUR_SECONDS - $hours * self::HOUR_SECONDS;
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
	 * What a read of the whole window covers, by TIER: the current hour's
	 * buckets, then every hour before it. Both newest first.
	 *
	 * The fine tier answers the current hour alone, which no hour key covers
	 * yet, and every closed hour is its hour key or nothing: an hour key a
	 * reader misses is the flame builder's to derive again, never the reader's
	 * to rebuild from twelve buckets. So nothing is counted twice. The window
	 * starts on the hour: the oldest hour it holds only part of is not read,
	 * so every hour read is whole, and the URL index's totals and rates sum
	 * whole hour keys exactly — the current hour so far and the whole hours
	 * before it, never an hour key standing in for part of an hour. The
	 * charts and the leaderboard read `CHART_HOURS` keys instead (decision
	 * 35).
	 *
	 * @param list<string> $window The window to split, newest first —
	 *                             `retention_buckets()` at the reply's one clock read.
	 *                             Taken rather than re-enumerated: reading the clock
	 *                             again here is how one reply would straddle a
	 *                             bucket boundary.
	 * @return array{fine: list<string>, hours: list<string>}
	 */
	public static function read_plan( array $window ): array {
		$current = self::hour_of( $window[0] ?? '' );
		$fine    = [];
		$hours   = [];
		foreach ( $window as $bucket ) {
			$hour = self::hour_of( $bucket );
			if ( $hour === $current ) {
				$fine[] = $bucket;
				continue;
			}
			// Keyed: distinctness is structural, order stays newest-first.
			$hours[ $hour ] = ( $hours[ $hour ] ?? 0 ) + 1;
		}
		$whole = \array_filter( $hours, static fn ( int $held ): bool => self::SLOTS_PER_HOUR === $held );
		return [ 'fine' => $fine, 'hours' => \array_map( 'strval', \array_keys( $whole ) ) ];
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
	 * The five-minute buckets a chart draws, newest first: the
	 * `MAX_READ_BUCKETS` (288) ending at the one `$now` falls in, whatever
	 * the retention window. A reply names them as `slots`, and the
	 * dashboard's axis is exactly these.
	 *
	 * Memoized while that bucket is current: one `overview` asks for them
	 * once per series it draws, and each would otherwise spend 288
	 * `gmdate()` calls.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return list<string>
	 */
	public static function chart_buckets( int $now ): array {
		$at = self::bucket_key( $now );
		if ( ! isset( self::$chart_buckets[ $at ] ) ) {
			self::$chart_buckets = [
				$at => \array_map(
					static fn ( int $back ): string => self::bucket_key( $now - $back * self::BUCKET_SECONDS ),
					\range( 0, self::MAX_READ_BUCKETS - 1 )
				),
			];
		}
		return self::$chart_buckets[ $at ];
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
	 * The URLs `$server_keys` filed rows for in `$bucket`, in one `SMEMBERS`
	 * exchange asking each server's set for `URL_BUCKET_MAX` members. A set
	 * holding more answers `false`, too many URLs to read by key, and a read
	 * left unanswered sets `$failed` and answers nothing, since a partial
	 * list would read as the whole bucket.
	 *
	 * @param string       $bucket      A `bucket_key()`, `Y-m-d-H-i`.
	 * @param list<string> $server_keys Each server's `server_key()`.
	 * @param-out bool     $failed
	 * @param ?bool        $failed      Set true when the Table left the read unanswered.
	 * @return array<string,list<string>>|false hash => the server keys naming
	 *                                          it, or false past the limit.
	 */
	public function url_bucket_members( string $bucket, array $server_keys, ?bool &$failed = null ): array|false {
		$asked = [];
		foreach ( $server_keys as $server_key ) {
			$asked[ self::key_at( [ self::NS_URLBUCKET, $server_key ], $bucket ) ] = $server_key;
		}
		$found = $this->client->members( $this->table_for( self::NS_URLBUCKET ), \array_keys( $asked ), self::URL_BUCKET_MAX, $failed );
		if ( $failed ) {
			return [];
		}
		if ( \in_array( null, $found, true ) ) {
			return false;
		}
		$out = [];
		foreach ( $asked as $key => $server_key ) {
			foreach ( \array_keys( $found[ $key ] ?? [] ) as $hash ) {
				$out[ (string) $hash ][] = $server_key;
			}
		}
		return $out;
	}

	/**
	 * File URL hashes under their words (`add_sets()`), in each server's set
	 * for the word in `$now`'s bucket, living the window and one refresh.
	 *
	 * @param list<array{0: string, 1: string, 2: list<string>}> $sets `[ server_key, word, hashes ]`.
	 * @param int                                                $now  When the names were written.
	 * @return array<int,bool> Whether each set landed, in order.
	 */
	public function add_url_tokens( array $sets, int $now ): array {
		$bucket = self::token_bucket( $now );
		$keys   = \array_map( static fn ( array $set ): string => self::key_at( [ ...self::url_token_parts( $set[0] ), $set[1] ], $bucket ), $sets );
		return $this->add_sets( self::NS_URLTOKEN, \array_combine( $keys, \array_column( $sets, 2 ) ), $now, self::filing_ttl( $this->max_lifespan ) );
	}

	/**
	 * Key parts of one server's token sets, ahead of which `key_at()`
	 * places the bucket.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @return array<int,string>
	 */
	public static function url_token_parts( string $server_key ): array {
		return [ self::NS_URLTOKEN, $server_key ];
	}

	/**
	 * The `urltoken` bucket a moment falls in: the start of its
	 * `TOKEN_BUCKET_SECONDS` window, spelled as an hour key is.
	 *
	 * @param int $timestamp Unix seconds.
	 */
	public static function token_bucket( int $timestamp ): string {
		return \gmdate( 'Y-m-d-H', $timestamp - $timestamp % self::TOKEN_BUCKET_SECONDS );
	}

	/**
	 * File URL hashes under the buckets their rows were filed in (`add_sets()`),
	 * living as long as the `url_row_h` slot each indexes, and one refresh.
	 *
	 * @param list<array{0: string, 1: string, 2: list<string>}> $sets `[ bucket, server_key, hashes ]`.
	 * @param int                                                $now  When the sets were written.
	 * @return array<int,bool> Whether each set landed, in order.
	 */
	public function add_url_buckets( array $sets, int $now ): array {
		$keys = \array_map( static fn ( array $set ): string => self::key_at( [ self::NS_URLBUCKET, $set[1] ], $set[0] ), $sets );
		return $this->add_sets( self::NS_URLBUCKET, \array_combine( $keys, \array_column( $sets, 2 ) ), $now, self::filing_ttl( self::aggregate_ttl( $this->max_lifespan ) ) );
	}

	/**
	 * Add each set's hashes in one `SADD`, valued by `$now`, reading nothing
	 * first: a hash filed again refreshes its value and expiry (`filing_ttl()`).
	 *
	 * @param string                     $ns   The namespace whose Table holds the sets.
	 * @param array<string,list<string>> $sets Each set's key => its hashes.
	 * @param int                        $now  Each member's value.
	 * @param int                        $ttl  Each member's lifetime.
	 * @return list<bool> Whether each set landed, in order.
	 */
	private function add_sets( string $ns, array $sets, int $now, int $ttl ): array {
		$members = \array_map( static fn ( array $hashes ): array => \array_fill_keys( $hashes, $now ), $sets );
		$landed  = \array_fill_keys( $this->client->add_members( $this->table_for( $ns ), $members, $ttl ), true );
		return \array_map( static fn ( string $key ): bool => isset( $landed[ $key ] ), \array_keys( $sets ) );
	}

	/**
	 * The PATH of each URL, by hash — what the search index files.
	 *
	 * @param array<array-key,string> $urls hash => URL. An all-digit hash is
	 *                                       an INT key, as PHP makes it.
	 * @return array<string,string> hash => path.
	 * @throws \InvalidArgumentException When a URL has no host.
	 */
	public static function paths_of( array $urls ): array {
		$out = [];
		foreach ( $urls as $hash => $url ) {
			$out[ (string) $hash ] = self::path_of( $url );
		}
		return $out;
	}

	/**
	 * The path a search matches on a stored row: a `ROW_PATH` cut to its
	 * path is one already, and one `row_path()` kept whole is a URL.
	 *
	 * @param string $row_path A row's `ROW_PATH`.
	 * @throws \InvalidArgumentException When a whole URL has no host.
	 */
	public static function row_search_path( string $row_path ): string {
		return \str_starts_with( $row_path, '/' ) ? $row_path : self::path_of( $row_path );
	}

	/**
	 * The PATH of a URL: what a search matches, with no scheme or host.
	 *
	 * The server is the picker's question, so a term matching the host would
	 * make one box ask the dropdown's. The authority ends at whichever
	 * delimiter comes first, so an authority with no path keeps its query on
	 * the path.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function path_of( string $url ): string {
		[ $offset, $length ] = self::authority( $url );
		return \substr( $url, $offset + $length );
	}

	/**
	 * The server a URL was logged by: its host, which the producer writes
	 * from the server name it serves under.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function server_of( string $url ): string {
		return \substr( $url, ...self::authority( $url ) );
	}

	/**
	 * Where a URL's host lies, `[ offset, length ]`: after a `://` that
	 * comes before any `/`, `?` or `#`, and up to the first of them.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException When the URL has no host: no `://`
	 *                                   ahead of its path, or an empty host.
	 */
	private static function authority( string $url ): array {
		$at = \strpos( $url, '://' );
		if ( false !== $at && $at <= \strcspn( $url, '/?#' ) ) {
			$length = \strcspn( $url, '/?#', $at + 3 );
			if ( 0 < $length ) {
				return [ $at + 3, $length ];
			}
		}
		throw new \InvalidArgumentException( "Stats_Store: URL has no host: '{$url}'" );
	}

	/**
	 * One URL's aggregate across every partition's blob: each flame-builder
	 * partition writes the share of the URL's traffic it saw, so the sums add
	 * (decision 2) — the running flames through `merge_url_flames()`, the
	 * profiles as a leaderboard bucket merges — and the reader divides them
	 * once. `last_modified` is the newest partition's flush.
	 *
	 * A key present only when some blob held it: `flame` from a `flame_raw`,
	 * `profiles` from a `profiles`.
	 *
	 * @param array<int,Stats_Store> $stores   Every partition's store.
	 * @param string                 $url_hash 12-char URL hash.
	 * @return array{last_modified:int, flame?:array<array-key,mixed>, profiles?:array<string,mixed>}|null Null when no partition holds one.
	 */
	public static function url_stats( array $stores, string $url_hash ): ?array {
		$found         = false;
		$last_modified = 0;
		$flame         = null;
		$profiles      = null;
		foreach ( $stores as $store ) {
			$blob = $store->url_aggregate( $url_hash );
			if ( null === $blob ) {
				continue;
			}
			$found         = true;
			$last_modified = \max( $last_modified, Core::num_int( $blob['last_modified'] ?? null ) );
			if ( \is_array( $blob['flame_raw'] ?? null ) ) {
				$flame = null === $flame ? $blob['flame_raw'] : Flame_Builder_Node::merge_url_flames( $flame, $blob['flame_raw'] );
			}
			if ( \is_array( $blob['profiles'] ?? null ) ) {
				$sums = self::string_keys( $blob['profiles'] );
				if ( null === $profiles ) {
					$profiles = $sums;
				} else {
					self::merge_leaderboard_bucket( $profiles, $sums );
				}
			}
		}
		if ( ! $found ) {
			return null;
		}
		$stats = [ 'last_modified' => $last_modified ];
		if ( null !== $flame ) {
			$stats['flame'] = Flame_Builder_Node::url_flame_for_display( $flame )[1];
		}
		if ( null !== $profiles ) {
			$stats['profiles'] = self::sums_to_display(
				Core::num_int( $profiles['count'] ?? null ),
				Core::num_float( $profiles['sum_req_time'] ?? null ),
				self::string_keys( Core::arr( $profiles['categories'] ?? null ) )
			);
		}
		return $stats;
	}

	/**
	 * Convert summed leaderboard data to the display shape expected by the frontend.
	 *
	 *  - 'time'    = sum_time  / total_count — avg exclusive cat time per request.
	 *  - 'count'   = sum_count / total_count — avg invocation count per request.
	 *  - entries   are per-appearance averages (sum / samples).
	 *
	 * Over no profiled request each mean is null, as `mean()` answers.
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
				'time'    => self::mean( $sum_time, $total_count ),
				'count'   => self::mean( $sum_count, $total_count ),
				'samples' => $samples,
				'entries' => $entries_out,
			];
		}

		return [
			'count'      => $total_count,
			'total_time' => self::mean( $sum_req_time, $total_count ),
			'categories' => $display_cats,
		];
	}

	/**
	 * A mean over the things that HAVE a value. Dividing by every request
	 * instead would understate it by the unmeasured fraction, and the mean
	 * of none is null: not measured, never 0.
	 *
	 * @param float $sum Summed values.
	 * @param int   $n   How many contributed one.
	 */
	public static function mean( float $sum, int $n ): ?float {
		return $n > 0 ? $sum / $n : null;
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
	 * One URL's stored aggregate as its writer merges onto it: the sums the
	 * flush wrote, where `url_stats()` answers display means.
	 *
	 * @param string    $url_hash 12-char URL hash.
	 * @param-out bool  $failed
	 * @param ?bool     $failed   Set true when the Table did not answer: then the
	 *                            null is no absence, and nothing may replace it.
	 * @return array<array-key,mixed>|null The aggregate, or null on a miss.
	 */
	public function url_aggregate( string $url_hash, ?bool &$failed = null ): ?array {
		$key   = self::key_at( [ self::NS_URL ], $url_hash );
		$value = $this->client->get_multi( $this->table_for( self::NS_URL ), [ $key ], $failed )[ $key ] ?? null;
		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Resolve URL names for the hashes a reader is about to show or locate.
	 *
	 * One `MGET`, like every other reader path (decision 6). Absent
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
			$map[ self::key_at( [ self::NS_URLMAP ], $hash ) ] = $hash;
		}
		$out = [];
		foreach ( $this->client->get_multi( $this->table_for( self::NS_URLMAP ), \array_keys( $map ) ) as $key => $value ) {
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
	 * Record the names of URLs this flush touched. A name serves every
	 * reader turning a hash into a URL, over rows the aggregate Table keeps
	 * its whole TTL, so each lives that TTL and one refresh (`filing_ttl()`).
	 *
	 * One round trip for the whole flush, like every other write here: a name
	 * per key would make the cost per URL, which is what the batch exists to
	 * avoid. The writer decides WHICH names are worth re-writing, since a
	 * name never changes and re-storing it every flush would spend the saving.
	 *
	 * @param array<array-key,array<array-key,string>> $servers Filed server => hash => URL.
	 *                                                          An all-digit key is an INT.
	 * @return array<int,bool> Whether each name landed, one per key written.
	 */
	public function set_url_names( array $servers ): array {
		$items = [];
		foreach ( $servers as $server => $urls ) {
			foreach ( $urls as $hash => $url ) {
				$items[ self::key_at( [ self::NS_URLMAP ], (string) $hash ) ] = [ [ (string) $server, self::row_path( $url, (string) $server ) ], self::filing_ttl( self::aggregate_ttl( $this->max_lifespan ) ) ];
			}
		}
		$landed = \array_fill_keys( $this->client->set_multi( $this->table_for( self::NS_URLMAP ), $items ), true );
		return \array_map( static fn ( string $key ): bool => isset( $landed[ $key ] ), \array_keys( $items ) );
	}

	/**
	 * The aggregate Table's lifetime for a window: the window, never under
	 * the CHART_HOURS a chart reads, so every hour key it draws is still
	 * stored (decision 35); what `<eln:stats_ttl>` gives its Table.
	 * `min_lifetime` sets substrate log retention too, so the charts floor
	 * this instead of raising it.
	 *
	 * @param int $retention_seconds The retention window.
	 */
	public static function aggregate_ttl( int $retention_seconds ): int {
		return \max( self::CHART_HOURS * self::HOUR_SECONDS, $retention_seconds );
	}

	/**
	 * How long a filing lives from its write: as long as the rows it indexes
	 * are read, and one refresh more.
	 *
	 * A URL is filed at most once an hour (`Flame_Builder_Node`'s
	 * `persist_url_names()`), so its last filing can come up to an hour and
	 * a flush before its last row. Living only as long as that row is read
	 * would retire the filing while the row still is.
	 *
	 * @param int $rows_read_for How long a reader reads the rows it indexes.
	 */
	private static function filing_ttl( int $rows_read_for ): int {
		return $rows_read_for + self::HOUR_SECONDS + Flame_Builder_Node::FLUSH_INTERVAL_SEC;
	}

	/**
	 * The `ROW_PATH` of a URL served by `$server`: what the key does not
	 * already say. An https URL whose authority is the server keeps only its
	 * path, which `join_url()` joins back as `https://{server}{path}`; any
	 * other URL, an http one, is kept whole, so no reader shows a scheme it
	 * was not. Cut to `MAX_PATH_BYTES`, the last of them an ellipsis.
	 *
	 * @param string $url    The stored URL.
	 * @param string $server The server whose key the row is filed under.
	 */
	public static function row_path( string $url, string $server ): string {
		$origin = 'https://' . $server;
		$path   = \str_starts_with( $url, $origin . '/' ) ? \substr( $url, \strlen( $origin ) ) : $url;
		if ( \strlen( $path ) <= self::MAX_PATH_BYTES ) {
			return $path;
		}
		return \mb_strcut( $path, 0, self::MAX_PATH_BYTES - \strlen( '…' ), 'UTF-8' ) . '…';
	}

	/**
	 * Write many buckets across DIFFERENT namespaces: one `MSET` per Table,
	 * each under the Table's declared TTL. The reply names every key that
	 * landed, so a caller that logs a specific refusal (a URL shard) still
	 * learns which one.
	 *
	 * @param array<int,array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes `[ parts, bucket, data ]`.
	 * @return array<int,bool> One result per write, in order.
	 */
	public function bucket_set_multi( array $writes ): array {
		if ( [] === $writes ) {
			return [];
		}
		$by_table = [];
		foreach ( $writes as [ $parts, $bucket, $data ] ) {
			$by_table[ $this->table_for( $parts[0] ) ][ self::key_at( $parts, $bucket ) ] = [ $data ];
		}
		$landed = [];
		foreach ( $by_table as $table => $items ) {
			$landed += \array_fill_keys( $this->client->set_multi( $table, $items ), true );
		}
		$out = [];
		foreach ( $writes as $i => [ $parts, $bucket ] ) {
			$out[ $i ] = isset( $landed[ self::key_at( $parts, $bucket ) ] );
		}
		return $out;
	}

	/**
	 * Drop many buckets across DIFFERENT namespaces: one `RM` per Table.
	 *
	 * @param list<array{0: array<int,string>, 1: string}> $forgets `[ parts, bucket ]` pairs.
	 */
	public function bucket_forget_multi( array $forgets ): void {
		$by_table = [];
		foreach ( $forgets as [ $parts, $bucket ] ) {
			$by_table[ $this->table_for( $parts[0] ) ][] = self::key_at( $parts, $bucket );
		}
		foreach ( $by_table as $table => $keys ) {
			$this->client->remove( $table, $keys );
		}
	}

	/**
	 * The key a `[ parts, bucket ]` pair names: the namespace, the bucket,
	 * then the rest of the parts (decision 1). Every key one bucket or hour
	 * touches in a namespace shares one prefix, so a flush's keys sit together
	 * in the Table's key order. A `url` or `urlmap` pair carries a hash where
	 * the time goes and no other part, so its key reads `{ns}:{hash}`.
	 *
	 * @param array<int,string> $parts  Key parts, the namespace first.
	 * @param string            $bucket Bucket or hour key.
	 */
	public static function key_at( array $parts, string $bucket ): string {
		return self::key( $parts[0], $bucket, ...\array_slice( $parts, 1 ) );
	}

	/**
	 * Join the caller's parts into one key, namespace token first. The
	 * partition is not here: each partition's Tables are files of their own.
	 *
	 * @param string ...$parts Namespace token first, then any sub-keys.
	 * @return string Key within a Table.
	 */
	public static function key( string ...$parts ): string {
		return \implode( ':', $parts );
	}

	/**
	 * The node answering for the Table a namespace lives in.
	 *
	 * Two groups leave the aggregate Table. `url` takes its own, for its
	 * shorter TTL. The fine `urls` and `urlsrv` tiers are read at the window's
	 * EDGE and answered behind that by `urls_h` and `urlsrv_h`, so their TTL is
	 * their read window rather than the retention window.
	 *
	 * @param string $ns Namespace, an `NS_*` value.
	 * @throws \LogicException When the Table is not one this store was given.
	 */
	private function table_for( string $ns ): string {
		$table = match ( $ns ) {
			self::NS_URL => self::TABLE_URL,
			// An index outliving the fine rows it names is one nothing reads.
			self::NS_URLS,
			self::NS_URLSRV,
			self::NS_URLRANK_S,
			self::NS_URLHDR    => self::TABLE_URL_FINE,
			default            => self::TABLE_AGGREGATE,
		};
		return $this->table_names[ $table ] ?? throw new \LogicException(
			"Stats_Store: namespace {$ns} lives in Table {$table}, which this store was not given (it holds " . \implode( ', ', \array_keys( $this->table_names ) ) . ')'
		);
	}

	/**
	 * Sum two `{count, sum_ms, requests, sum_peak_mb}` totals — the
	 * `hourly_h` slot's shape. The schema owns the totals, so it owns the
	 * addition over them, as `sums_to_display()` owns the read-time division
	 * over its own. A non-numeric field on either side reads as zero.
	 *
	 * Fields outside the totals ride through from `$a`, which is why the sum
	 * is replaced ONTO it rather than returned on its own: the stored slot is
	 * the caller's, and rebuilding it here would drop a fifth field silently.
	 *
	 * @param array<string,mixed>    $a One side, and the shape that survives.
	 * @param array<array-key,mixed> $b The other, read by name only.
	 * @return array<string,mixed>
	 */
	public static function add_totals( array $a, array $b ): array {
		return self::string_keys( \array_replace( $a, self::sum_entry( $a, $b, self::HOURLY_SUMS ) ) );
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
	 * Re-key a decoded map with string keys. PHP casts numeric-looking keys to
	 * int on decode, so a value read back from a Table is `array-key` typed
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
	 * Every ranked list one tier's merged rows produce, and the header record
	 * beside them, as the `[parts, key, value]` triples `bucket_set_multi()`
	 * takes: for each of `RANK_SETS`, fourteen lists and a record for each
	 * server named, the lists empty where it holds nothing of the set
	 * rankable, so a reader can tell a server ranked idle from one whose
	 * lists are missing; then the set's site lists, each ranked once over
	 * the union of the rows the servers' lists of its sort hold, and its
	 * record, the union of theirs. The TIER sets the bound, so a caller names
	 * which tier it is writing and never the row count twice.
	 *
	 * A site list is exact: URLs are disjoint by server and a tie breaks by
	 * hash, so every entry of a sort's site top-N heads its own server's list
	 * for that sort, and a hash two servers share merges as its rows would
	 * have. Every `url` list ranks the whole URL; a server's stores the path
	 * and the site's the URL, joined as each server's entries enter the
	 * union.
	 *
	 * A record sums EVERY row of its set, where a list ranks none of the
	 * overflow rows, because an overflow row's requests are the site's all
	 * the same. The rows arrive as each server's SHARD maps, so a family is
	 * its shards, and a hash filed in both families stays two rows, one a
	 * family. An errored set holds the family's `errored_rows()`, and ranks
	 * `count` by the errors, as the errors page sorts it.
	 *
	 * The site's lists are the writer's so a site page reads one list a key
	 * rather than merging every server's on every poll: a site list is
	 * `URL_RANK_N` entries, the size of a server's, one item (decision 30).
	 *
	 * @param array<array-key,array<array-key,array<array-key,mixed>>> $servers Server name =>
	 *                                                                         shard => the
	 *                                                                         tier's rows by hash.
	 * @param bool                                                     $hour    The coarse tier.
	 * @param string                                                   $key     Bucket or hour key.
	 * @return list<array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}>
	 */
	public static function ranked_writes( array $servers, bool $hour, string $key ): array {
		$families = [ [], [] ];
		foreach ( $servers as $server => $shards ) {
			$maps = [ [], [] ];
			foreach ( $shards as $shard => $rows ) {
				$maps[ (int) self::is_worker_shard( (string) $shard ) ][] = Core::arr( $rows );
			}
			foreach ( $maps as $worker => $family_maps ) {
				$families[ $worker ][ (string) $server ] = self::merge_shard_rows( ...$family_maps );
			}
		}
		$writes = [];
		foreach ( self::RANK_SETS as $set ) {
			$family  = $families[ (int) \in_array( self::WORKER_SHARD_PREFIX, $set, true ) ];
			$errored = \in_array( self::ERRORED_PART, $set, true );
			\array_push( $writes, ...self::set_ranked_writes( $errored ? \array_map( self::errored_rows( ... ), $family ) : $family, $hour, $key, $set ) );
		}
		return $writes;
	}

	/**
	 * The rows of one key in which each URL errored: those whose requests
	 * include a timeout or a fatal (`ROW_ERRORS`). The one
	 * definition of an errored row, which the writer ranks and the fold
	 * filters each key by. An overflow row stands for many URLs, and no
	 * row test speaks for one.
	 *
	 * @param array<array-key,mixed> $rows One key's stored rows by hash.
	 * @return array<string,array<array-key,mixed>>
	 */
	public static function errored_rows( array $rows ): array {
		$out = [];
		foreach ( $rows as $hash => $raw ) {
			$row = Core::arr( $raw );
			if ( ! self::is_other_key( (string) $hash ) && self::row_errors( $row ) > 0 ) {
				$out[ (string) $hash ] = $row;
			}
		}
		return $out;
	}

	/**
	 * One set's lists and records, as `ranked_writes()` states them.
	 *
	 * @param array<string,array<string,array<array-key,mixed>>> $servers Server name =>
	 *                                                                    the set's rows by hash.
	 * @param bool                                               $hour    The coarse tier.
	 * @param string                                             $key     Bucket or hour key.
	 * @param list<string>                                       $set     One of `RANK_SETS`.
	 * @return list<array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}>
	 */
	private static function set_ranked_writes( array $servers, bool $hour, string $key, array $set ): array {
		$n       = $hour ? self::URL_RANK_N_HOUR : self::URL_RANK_N;
		$errored = \in_array( self::ERRORED_PART, $set, true );
		$writes  = [];
		$records = [];
		$union   = [];
		foreach ( $servers as $server => $rows ) {
			$rankable = \array_diff_key( $rows, [ self::OTHER_KEY => true, self::OTHER_WORKER_KEY => true ] );
			// Each row named by its whole URL, once, for every list it enters.
			$named = [];
			foreach ( self::rank_url_rows( $rankable, $n, $errored, $server ) as $sort => $orders ) {
				foreach ( $orders as $order => $entries ) {
					$writes[] = [ self::url_rank_parts( $sort, $order, $server, $hour, $set ), $key, $entries ];
					// A hash two servers share merges, as its rows would have.
					foreach ( $entries as [ self::RANK_HASH => $hash ] ) {
						$named[ $hash ]                   ??= self::row_with_url( $rankable[ $hash ], $server );
						$held                               = $union[ $sort ][ $order ][ $hash ] ?? null;
						$union[ $sort ][ $order ][ $hash ] = null === $held ? $named[ $hash ] : self::merge_url_row( $held, $named[ $hash ] );
					}
				}
			}
			$record    = self::url_header_of( $rows );
			$records[] = $record;
			$writes[]  = [ self::url_header_parts( $server, $hour, $set ), $key, $record ];
		}
		// Each site list ranks only its own sort's entries of the servers'.
		foreach ( [] === $servers ? [] : self::URL_SORTS as $sort ) {
			foreach ( self::URL_ORDERS as $order ) {
				$site_rows = $union[ $sort ][ $order ] ?? [];
				// The union's paths are whole URLs, which `join_url()` keeps.
				$ranked    = self::rank_values( $site_rows, self::rank_key( $sort, $errored ), '' );
				$writes[]  = [ self::url_rank_parts( $sort, $order, '', $hour, $set ), $key, self::rank_entries( self::rank_cut( $ranked, $order, $n ), $sort, $site_rows ) ];
			}
		}
		if ( [] !== $servers ) {
			$writes[] = [ self::url_header_parts( '', $hour, $set ), $key, self::merge_url_headers( $records ) ];
		}
		return $writes;
	}

	/**
	 * Header records as one: the sums added, the sketches unioned in one
	 * pass, so a URL several saw counts once; of none, the empty record.
	 *
	 * @param list<Url_Header> $records Records.
	 * @return Url_Header
	 */
	public static function merge_url_headers( array $records ): array {
		$out = [
			self::HDR_COUNT       => 0,
			self::HDR_TIMED_COUNT => 0,
			self::HDR_SUM_MS      => 0.0,
			self::HDR_SUM_PEAK_MB => 0.0,
			self::HDR_HAS_OTHER   => false,
			self::HDR_URLS        => Url_Sketch::union( ...\array_column( $records, self::HDR_URLS ) ),
			self::HDR_ERRORS      => 0,
		];
		foreach ( $records as $record ) {
			$out[ self::HDR_COUNT ]       += $record[ self::HDR_COUNT ];
			$out[ self::HDR_TIMED_COUNT ] += $record[ self::HDR_TIMED_COUNT ];
			$out[ self::HDR_SUM_MS ]      += $record[ self::HDR_SUM_MS ];
			$out[ self::HDR_SUM_PEAK_MB ] += $record[ self::HDR_SUM_PEAK_MB ];
			$out[ self::HDR_HAS_OTHER ]    = $out[ self::HDR_HAS_OTHER ] || $record[ self::HDR_HAS_OTHER ];
			$out[ self::HDR_ERRORS ]      += $record[ self::HDR_ERRORS ];
		}
		return $out;
	}

	/**
	 * Key parts of one server's header record, or of the site's,
	 * `HDR_SHAPE` first and the set's parts after it.
	 *
	 * @param string       $server Reporting server; '' is the site.
	 * @param bool         $hour   The coarse tier.
	 * @param list<string> $set    One of `RANK_SETS`; the reader set adds none.
	 * @return array<int,string>
	 */
	public static function url_header_parts( string $server, bool $hour, array $set = [] ): array {
		return [ $hour ? self::NS_URLHDR_HOUR : self::NS_URLHDR, self::HDR_SHAPE, ...$set, ...self::server_part( $server ) ];
	}

	/**
	 * The header record of `$rows`; of none, the empty record every merge of
	 * records starts from.
	 *
	 * @param array<string,array<array-key,mixed>> $rows Reader rows by hash.
	 * @return Url_Header
	 */
	public static function url_header_of( array $rows ): array {
		$count  = 0;
		$timed  = 0;
		$sum_ms = 0.0;
		$peak   = 0.0;
		$other  = false;
		$urls   = [];
		$errors = 0;
		foreach ( $rows as $hash => $row ) {
			$errors += self::row_errors( $row );
			$count  += Core::num_int( $row[ self::ROW_COUNT ] ?? null );
			$timed  += Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null );
			$sum_ms += Core::num_float( $row[ self::ROW_SUM_MS ] ?? null );
			$peak   += Core::num_float( $row[ self::ROW_SUM_PEAK_MB ] ?? null );
			if ( self::is_other_key( $hash ) ) {
				$other = true;
				continue;
			}
			$urls[] = $hash;
		}
		return [
			self::HDR_COUNT       => $count,
			self::HDR_TIMED_COUNT => $timed,
			self::HDR_SUM_MS      => $sum_ms,
			self::HDR_SUM_PEAK_MB => $peak,
			self::HDR_HAS_OTHER   => $other,
			self::HDR_URLS        => Url_Sketch::of( $urls ),
			self::HDR_ERRORS      => $errors,
		];
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
	 * A row whose `ROW_PATH` is its whole URL, as the site's lists rank and
	 * store it; a row with no path keeps none.
	 *
	 * @param array<array-key,mixed> $row    A stored row.
	 * @param string                 $server The server it is filed under.
	 * @return array<array-key,mixed>
	 */
	private static function row_with_url( array $row, string $server ): array {
		$row[ self::ROW_PATH ] = self::join_url( $server, Core::str( $row[ self::ROW_PATH ] ?? '' ) );
		return $row;
	}

	/**
	 * Key parts of one server's ranked list, or of the site's. The
	 * server rides in the KEY, as it does for every per-server value
	 * (decision 30), and so does the list set, before it.
	 *
	 * @param string       $sort   A `URL_SORTS` value.
	 * @param string       $order  A `URL_ORDERS` value.
	 * @param string       $server Reporting server; '' is the site.
	 * @param bool         $hour   The coarse tier.
	 * @param list<string> $set    One of `RANK_SETS`; the reader set adds none.
	 * @return array<int,string>
	 */
	public static function url_rank_parts( string $sort, string $order, string $server, bool $hour, array $set = [] ): array {
		return [ $hour ? self::NS_URLRANK_HOUR_S : self::NS_URLRANK_S, ...$set, ...self::server_part( $server ), $sort, $order ];
	}

	/**
	 * A ranked value's server key part: none for the site.
	 *
	 * @param string $server Reporting server; '' is the site.
	 * @return list<string>
	 */
	private static function server_part( string $server ): array {
		return '' === $server ? [] : [ self::server_key( $server ) ];
	}

	/**
	 * Hash a server name to a key-safe ASCII token (FNV-1a 32-bit hex).
	 * Every per-server key carries it, so a server name cannot break a colon
	 * or put bytes of its own into a key (decision 30).
	 *
	 * @param string $server Server name; '' hashes to ''.
	 * @return string Eight hex digits, or ''.
	 */
	public static function server_key( string $server ): string {
		if ( '' === $server ) {
			return '';
		}
		return \hash( 'fnv1a32', $server );
	}

	/**
	 * Every ranked list of one server's rows: `sort => order => entries`,
	 * each cut to `$n`. A sort's values are taken once and cut both ways.
	 *
	 * The rows arrive filtered — `ranked_writes()` has already dropped what
	 * never ranks — so nothing here walks them a second time.
	 *
	 * @param array<array-key,array<array-key,mixed>> $rows    The server's rows by hash.
	 * @param int                                     $n       Entries per list.
	 * @param bool                                    $errored An errored set, whose `count` ranks by errors.
	 * @param string                                  $server  The server the rows are filed under.
	 * @return array<string,array<string,list<Rank_Entry>>>
	 */
	private static function rank_url_rows( array $rows, int $n, bool $errored, string $server ): array {
		$out = [];
		foreach ( self::URL_SORTS as $sort ) {
			$ranked = self::rank_values( $rows, self::rank_key( $sort, $errored ), $server );
			foreach ( self::URL_ORDERS as $order ) {
				$out[ $sort ][ $order ] = self::rank_entries( self::rank_cut( $ranked, $order, $n ), $sort, $rows );
			}
		}
		return $out;
	}

	/**
	 * The `$n` best hashes of a `rank_values()` in one direction. A tie
	 * breaks by hash, ascending either way, so a cut never depends on the
	 * order rows arrive in and a merge of servers' lists cuts where one list
	 * over all of them would.
	 *
	 * @param array{0: list<string>, 1: list<float|int|string>, 2: list<int>} $ranked `rank_values()`.
	 * @param string                                                           $order  A `URL_ORDERS` value.
	 * @param int                                                              $n      Entries to keep.
	 * @return list<string>
	 */
	private static function rank_cut( array $ranked, string $order, int $n ): array {
		[ $hashes, $values, $measured ] = $ranked;
		return \array_map( static fn ( int $i ): string => $hashes[ $i ], \array_slice( self::rank_order( $measured, $values, $hashes, $order ), 0, $n ) );
	}

	/**
	 * The positions of rows ranked in one direction, best first: a row that
	 * measured what it ranks by ahead of one that did not, in both orders,
	 * then by value, a tie by hash ascending either way, and a row tying on
	 * all three where it arrived. The writer's lists and the reader's fold
	 * both rank through this, so the two agree on every tie.
	 *
	 * @param list<int>              $measured 1 where the row measured what it ranks by, else 0.
	 * @param list<float|int|string> $values   Each row's value, in step.
	 * @param list<string>           $hashes   Each row's hash, in step.
	 * @param string                 $order    A `URL_ORDERS` value.
	 * @return list<int> Positions into the three lists.
	 */
	public static function rank_order( array $measured, array $values, array $hashes, string $order ): array {
		$positions = \array_keys( $hashes );
		// array_multisort sorts in C; usort calls PHP once per comparison.
		\array_multisort( $measured, \SORT_DESC, \SORT_NUMERIC, $values, 'asc' === $order ? \SORT_ASC : \SORT_DESC, \SORT_REGULAR, $hashes, \SORT_ASC, \SORT_STRING, $positions, \SORT_ASC, \SORT_NUMERIC );
		return $positions;
	}

	/**
	 * One ranked list's entries: each hash beside the row it ranked, and on a
	 * `url` list its `ROW_PATH`, the only sort that displays one: the path on
	 * a server's list, the whole URL on the site's.
	 *
	 * @param list<string>           $hashes The list's hashes, in rank order.
	 * @param string                 $sort   A `URL_SORTS` value.
	 * @param array<array-key,mixed> $rows   The rankable rows by hash.
	 * @return list<Rank_Entry>
	 */
	private static function rank_entries( array $hashes, string $sort, array $rows ): array {
		$out = [];
		foreach ( $hashes as $hash ) {
			$row  = Core::arr( $rows[ $hash ] );
			$path = Core::str( $row[ self::ROW_PATH ] ?? '' );
			unset( $row[ self::ROW_PATH ] );
			$entry = [ self::RANK_HASH => $hash, self::RANK_ROW => $row ];
			if ( 'url' === $sort ) {
				$entry[ self::RANK_PATH ] = $path;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * The value a `URL_SORTS` key ranks by: the errors, where an errored
	 * page sorts by `count`, and the key itself everywhere else.
	 *
	 * @param string $sort    A `URL_SORTS` value.
	 * @param bool   $errored The errored rows alone.
	 */
	public static function rank_key( string $sort, bool $errored ): string {
		return $errored && 'count' === $sort ? 'errors' : $sort;
	}

	/**
	 * What one sort ranks rows by: their hashes, values and whether each was
	 * measured, in step, for `rank_cut()` to order either way. An untimed row
	 * measured no duration, so it ranks last on the `TIMED_SORTS` in both
	 * orders, where the fold orders it. `url` ranks the whole URL
	 * (`join_url()`) on every list, so a server's cut is its top-N by URL and
	 * the site's union of them is exact; a row with no path ranks on no `url`
	 * sort.
	 *
	 * @param array<array-key,array<array-key,mixed>> $rows   Rows by hash.
	 * @param string                                  $sort   A `URL_SORTS` value, or `errors` (`rank_key()`).
	 * @param string                                  $server The server the rows' paths join to; '' where
	 *                                                        each is a whole URL already, as in the
	 *                                                        site's union.
	 * @return array{0: list<string>, 1: list<float|int|string>, 2: list<int>}
	 */
	private static function rank_values( array $rows, string $sort, string $server ): array {
		$values   = [];
		$measured = [];
		$timed    = \in_array( $sort, self::TIMED_SORTS, true );
		foreach ( $rows as $hash => $row ) {
			if ( 'url' === $sort && '' === Core::str( $row[ self::ROW_PATH ] ?? '' ) ) {
				continue;
			}
			$values[ $hash ]   = self::url_rank_value( $row, $sort, $server );
			$measured[ $hash ] = ! $timed || Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null ) > 0 ? 1 : 0;
		}
		// An all-digit hash arrives as an INT key.
		return [ \array_map( 'strval', \array_keys( $values ) ), \array_values( $values ), \array_values( $measured ) ];
	}

	/**
	 * The value one bucket's row ranks by for one sort: the bucket's OWN
	 * average for the two means, so a page hit rarely but slowly ranks on how
	 * slow it is rather than on how often it is hit, and its whole URL on
	 * `url`.
	 *
	 * @param array<array-key,mixed> $row    A stored row.
	 * @param string                 $sort   A `URL_SORTS` value, or `errors`.
	 * @param string                 $server The server its path joins to on `url`.
	 */
	private static function url_rank_value( array $row, string $sort, string $server ): float|int|string {
		return match ( $sort ) {
			'count'        => Core::num_int( $row[ self::ROW_COUNT ] ?? null ),
			'errors'       => self::row_errors( $row ),
			'avg_ms'       => Core::num_float( $row[ self::ROW_SUM_MS ] ?? null ) / \max( 1, Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null ) ),
			'min_ms'       => Core::num_float( $row[ self::ROW_MIN_MS ] ?? null ),
			'max_ms'       => Core::num_float( $row[ self::ROW_MAX_MS ] ?? null ),
			'avg_peak_mb'  => Core::num_float( $row[ self::ROW_SUM_PEAK_MB ] ?? null ) / \max( 1, Core::num_int( $row[ self::ROW_COUNT ] ?? null ) ),
			'last_updated' => Core::num_int( $row[ self::ROW_LAST_SEEN ] ?? null ),
			default        => self::join_url( $server, Core::str( $row[ self::ROW_PATH ] ?? '' ) ),
		};
	}

	/**
	 * The URL a `row_path()` was cut from, given the server it was cut
	 * against: the inverse `row_path()` answers to, and the one place the
	 * join is spelled. A whole URL, or no path, comes back as it is, with
	 * or without a server; a server-relative path with none is refused
	 * rather than joined into `https:///…`.
	 *
	 * @param string $server The server the path was cut against; '' where the
	 *                       path is already a whole URL, as on a site list.
	 * @param string $path   The stored path.
	 * @throws \InvalidArgumentException When a server-relative path has no server.
	 */
	public static function join_url( string $server, string $path ): string {
		if ( ! \str_starts_with( $path, '/' ) ) {
			return $path;
		}
		if ( '' === $server ) {
			throw new \InvalidArgumentException( "Stats_Store: no server to join '{$path}' to" );
		}
		return "https://{$server}{$path}";
	}

	/**
	 * The requests of one stored row that timed out or fataled.
	 *
	 * @param array<array-key,mixed> $row A stored row.
	 */
	public static function row_errors( array $row ): int {
		return Core::num_int( $row[ self::ROW_ERRORS ] ?? null );
	}

	/**
	 * One server's shard maps as one map by hash. A hash lives in one shard,
	 * so a later map's row replaces; the overflow row lives in every shard
	 * under one key, so its rows are summed through `fold_url_rows()`.
	 *
	 * @param array<array-key,mixed> ...$maps Shard maps, hash => stored row.
	 * @return array<string,array<array-key,mixed>>
	 */
	public static function merge_shard_rows( array ...$maps ): array {
		$merged = [];
		foreach ( $maps as $map ) {
			foreach ( $map as $raw_hash => $raw ) {
				$hash            = (string) $raw_hash;
				$row             = Core::arr( $raw );
				$merged[ $hash ] = isset( $merged[ $hash ] ) && self::is_other_key( $hash )
					? self::fold_url_rows( $merged[ $hash ], $row )
					: $row;
			}
		}
		return $merged;
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
	 * Whether a row key is one of the overflow rows — either of them.
	 *
	 * @param string $hash A URL row key.
	 */
	public static function is_other_key( string $hash ): bool {
		return self::OTHER_KEY === $hash || self::OTHER_WORKER_KEY === $hash;
	}

	/**
	 * Whether a shard token names the WORKER family.
	 *
	 * @param string $shard Shard token from `url_shard()`.
	 */
	public static function is_worker_shard( string $shard ): bool {
		return \str_starts_with( $shard, self::WORKER_SHARD_PREFIX );
	}

	/**
	 * Whether a name answers a term: every token of the term is a WORD of
	 * it, read the way the index files words, so a candidate whose set named
	 * it for one word still has to carry every other.
	 *
	 * @api The URL walk, for a candidate the token index named.
	 * @param string       $name   The URL's path.
	 * @param list<string> $tokens The term's tokens, as `term_tokens()` spells them.
	 */
	public static function term_matches( string $name, array $tokens ): bool {
		return [] === \array_diff( $tokens, self::term_tokens( $name ) );
	}

	/**
	 * A search term's words, or a path's: distinct lowercase alphanumeric runs
	 * of `TERM_WORD_MIN` characters or more, cut to `TERM_WORD_MAX`, in
	 * source order.
	 *
	 * @param string $text Search term or path.
	 * @return list<string>
	 */
	public static function term_tokens( string $text ): array {
		$out = [];
		foreach ( \preg_split( '/' . self::TOKEN_SEP . '+/', \strtolower( $text ) ) ?: [] as $token ) {
			if ( \strlen( $token ) >= self::TERM_WORD_MIN ) {
				$out[ \substr( $token, 0, self::TERM_WORD_MAX ) ] = true;
			}
		}
		// An all-digit token is an INT key; every reader promises a string.
		return \array_map( 'strval', \array_keys( $out ) );
	}

	/**
	 * The unix second a bucket opens: `bucket_key()` read back, in UTC.
	 *
	 * @param string $bucket A `Y-m-d-H-i` bucket key.
	 * @throws \InvalidArgumentException On a key that does not parse.
	 */
	public static function bucket_start( string $bucket ): int {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d-H-i', $bucket, new \DateTimeZone( 'UTC' ) );
		if ( false === $start ) {
			throw new \InvalidArgumentException( "Stats_Store: not a Y-m-d-H-i bucket key: '{$bucket}'" );
		}
		return $start->getTimestamp();
	}

	/**
	 * A measured figure as a reply or a brief carries it — a mean, or a URL
	 * row's `min_ms` or `max_ms` — the number, or null where nothing was
	 * measured, which a reader shows as unmeasured, never as 0.
	 *
	 * @param mixed $value The figure a row or a brief holds.
	 */
	public static function measured_figure( mixed $value ): ?float {
		return null === $value ? null : Core::num_float( $value );
	}

	/**
	 * The row families a reader asks for, each one list and one record a
	 * key: the reader family, and the worker family beside it on request.
	 *
	 * @param bool $workers Include the worker family.
	 * @return list<bool> Whether each family is the worker's.
	 */
	public static function families( bool $workers ): array {
		return $workers ? [ false, true ] : [ false ];
	}

	/**
	 * The list sets a page reads, of `RANK_SETS`: the reader family's, the
	 * worker family's beside it on request, each errored rows alone when
	 * the page filters to them.
	 *
	 * @param bool $workers Include the worker family.
	 * @param bool $errored Only the rows of the keys each URL errored in.
	 * @return list<list<string>>
	 */
	public static function rank_sets( bool $workers, bool $errored ): array {
		return \array_values( \array_filter(
			self::RANK_SETS,
			static fn ( array $set ): bool => $errored === \in_array( self::ERRORED_PART, $set, true )
				&& ( $workers || ! \in_array( self::WORKER_SHARD_PREFIX, $set, true ) )
		) );
	}

	/**
	 * A term's tokens as the reads that name them: longest first, ties in
	 * term order, `SEARCH_WORDS_READ` to a read. A reader reads the next
	 * group only when every token of the last came back over the limit.
	 *
	 * @param list<string> $tokens Tokens, as `term_tokens()` spells them.
	 * @return list<list<string>>
	 */
	public static function search_groups( array $tokens ): array {
		\usort( $tokens, static fn ( string $a, string $b ): int => \strlen( $b ) <=> \strlen( $a ) );
		return \array_chunk( $tokens, self::SEARCH_WORDS_READ );
	}

	/**
	 * The fine tier's lifetime for a window: FINE_TTL_SECONDS, never past
	 * the window, what `<eln:stats_url_fine_ttl>` gives its Table.
	 *
	 * @param int $retention_seconds The retention window.
	 */
	public static function fine_ttl( int $retention_seconds ): int {
		return \min( $retention_seconds, self::FINE_TTL_SECONDS );
	}

	/**
	 * Key parts of one URL's dimensional series: one row an hour
	 * holding every dimension, each a slotted hour, so a flush writes a URL
	 * once an hour whatever it measured. A reader names the dimension it
	 * draws to `get_slots()`.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<int,string>
	 */
	public static function url_dim_parts( string $url_hash ): array {
		return [ self::NS_URL_DIM_HOUR, $url_hash ];
	}

	/**
	 * Key parts of one URL's index row under the server its rows are filed
	 * under, ahead of which `key_at()` places the hour.
	 *
	 * @param string $server_key The server's `server_key()`.
	 * @param string $url_hash   12-char URL hash.
	 * @return array<int,string>
	 */
	public static function url_row_parts( string $server_key, string $url_hash ): array {
		return [ self::NS_URL_ROW_HOUR, $server_key, $url_hash ];
	}

	/**
	 * The index of a `url_row_h` value holding one family's slots.
	 *
	 * @param bool $worker The worker family.
	 */
	public static function url_row_family( bool $worker ): int {
		return $worker ? self::URL_ROW_WORKER : self::URL_ROW_READER;
	}

	/**
	 * Key parts of one URL's category series.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<int,string>
	 */
	public static function url_cat_parts( string $url_hash ): array {
		return [ self::NS_URL_CAT_HOUR, $url_hash ];
	}

	/**
	 * Key parts of the site-wide request totals.
	 *
	 * @return array<int,string>
	 */
	public static function hourly_parts(): array {
		return [ self::NS_HOURLY_HOUR ];
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
	 * What one stored part costs before its strings, under the serializer a
	 * stats Table stores with — the one place a cap learns it.
	 *
	 * @param string $part An `OVERHEADS` part: `url_row`, `lb_category`,
	 *                     `lb_entry`, `flame_node` or `hook`.
	 * @throws \LogicException When no estimate names the part.
	 */
	public static function overhead( string $part ): int {
		return self::OVERHEADS[ Durable_Arm::serializer() ][ $part ] ?? throw new \LogicException( "no size estimate for a stored {$part}" );
	}

	/**
	 * The slot a bucket fills in its hour's value: its minute over
	 * BUCKET_MINUTES, so `buckets_in_hour( $hour )[ slot_of( $bucket ) ]` is
	 * `$bucket` (decision 35).
	 *
	 * @param string $bucket A `Y-m-d-H-i` bucket key.
	 */
	public static function slot_of( string $bucket ): int {
		return \intdiv( (int) \substr( $bucket, 14, 2 ), self::BUCKET_MINUTES );
	}

	/**
	 * The name each entry of an index files its server under.
	 *
	 * @param array<string,array{0:string,1:int}> $entries server_key => entry.
	 * @return array<string,string> server_key => name.
	 */
	public static function index_names( array $entries ): array {
		return \array_map( static fn ( array $entry ): string => $entry[ self::SRV_NAME ], $entries );
	}

	/**
	 * The index two writes of one key make between them: each server's name
	 * from `$entries`, and its shards the union of both.
	 *
	 * @param array<string,array{0:string,1:int}> $into    The index so far.
	 * @param array<string,array{0:string,1:int}> $entries The entries being written.
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function merge_index( array $into, array $entries ): array {
		foreach ( $entries as $key => [ self::SRV_NAME => $name, self::SRV_SHARDS => $shards ] ) {
			$into[ $key ] = [ self::SRV_NAME => $name, self::SRV_SHARDS => ( $into[ $key ][ self::SRV_SHARDS ] ?? 0 ) | $shards ];
		}
		return $into;
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

	/** The retention window a reader reads, in seconds. */
	public function max_lifespan(): int {
		return $this->max_lifespan;
	}

	/**
	 * The overflow key a row folds into.
	 *
	 * @param bool $worker Whether the folded row is worker traffic.
	 */
	public static function other_key( bool $worker ): string {
		return $worker ? self::OTHER_WORKER_KEY : self::OTHER_KEY;
	}

}
