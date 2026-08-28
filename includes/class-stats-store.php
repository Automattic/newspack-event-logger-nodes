<?php
/**
 * Stats Store
 *
 * The memcache schema for performance stats, expressed as one small key/value
 * API. Nine namespaces (`hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`,
 * `url_dim`, `categories`, `url_cat`) live under the per-partition prefix
 * `evlog:p{N}:`, under the install scope Cache_Backend owns.
 * `Flame_Builder_Node` produces every value;
 * `App\Performance_CI_Node` and the admin flush button consume them.
 *
 * Stats live in memcache alone; this file writes no durable state.
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
 * partition. Values are plain arrays, always keyed by string.
 *
 * Retention: the per-URL blob (`url`) is the high-volume namespace and expires
 * at `ttl_url_stats()` — a twenty-fourth of the lifespan, floored at an hour.
 * Every other namespace expires at `ttl()`.
 *
 * Bucketing is part of the key schema, so it lives here: `bucket_key()` is the
 * five-minute `Y-m-d-H-ii` derivation every producer and reader shares, and
 * `retention_buckets()` is the window a reader enumerates. The `hourly`
 * namespace name is historical rather than descriptive: `hourly` buckets are
 * five minutes wide, like every other bucketed namespace.
 *
 * Storage is a `Table_Node` per TTL over one namespace (`evlog:p{N}`), so the
 * substrate owns key scoping and the backend handle. Reads and writes fail soft:
 * `Table_Node::table()` throws without a backing store, so the table is built
 * lazily behind that check and a missing backend yields `[]`, `null`, or `false`
 * — the dashboards render "no data" instead of an error. Keep it that way; the
 * SSE slot pool is deliberately the opposite, and unifying the two breaks its
 * rate limit.
 *
 * No L1 (`l1_ttl` 0): the one hot reader, `Flame_Builder_Node`, already holds an
 * `LRU_Cache` in front of `get_url_stats()` — and that LRU is an accumulator
 * holding state not yet persisted, not a cache of what is stored, so the Table's
 * write-through L1 cannot replace it. Enabling one here would just add a second
 * cache underneath the first.
 *
 * Flushing is the substrate's one button (`Cache_Backend::rotate_salt()`),
 * which moves the install scope for every plugin at once; this keeps no salt
 * of its own. The scope is memoized per process, so a long-running worker
 * picks up a rotation when it restarts — which the flush handler triggers.
 */
class Stats_Store {

	/** Distinct category values kept per bucket; `Flame_Builder_Node` rolls the overflow into "Other". */
	public const MAX_CAT_VALUES           = 50;
	/** Distinct values kept per global dimension bucket; see `dim_cap()`. */
	public const MAX_DIM_VALUES           = 20;

	/** Raw durations sampled per URL bucket for percentiles; Algorithm R past the cap. */
	public const MAX_DURATIONS_PER_BUCKET = 100;
	/** Distinct values kept per per-URL dimension bucket. */
	public const MAX_URL_DIM_VALUES       = 10;
	/**
	 * Distinct reporting servers kept wherever the `server` axis is stored — the
	 * global dimension bucket, a URL's dimension bucket, and a URL row's `srv`
	 * split alike. Set far above any fleet — five times the largest hub in
	 * evidence, three times the widest per-URL split any test asserts — so no
	 * real server folds; what it guards is `SERVER_NAME` under Apache's default
	 * `UseCanonicalName Off`, where the value is the client's Host header.
	 */
	public const MAX_SERVER_VALUES        = 128;
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
	/** Per-URL stats blob: flame tree and profiles. */
	public const NS_URL         = 'url';
	/**
	 * URL index bucket, sharded by the first hex digit of the url_hash:
	 * `urls:{shard}:{bucket}`. The bucket stays LAST, so `is_open_bucket()`,
	 * expiry and the durable read-through are untouched. Decision 1.
	 */
	public const NS_URLS        = 'urls';
	/**
	 * The URL index's raw duration reservoirs: `url_dur:{shard}:{bucket}`,
	 * `{ url_hash: [samples] }`.
	 *
	 * Beside the index rather than inside them, because it is the WRITER's
	 * working set and no reader wants it: its only use is recomputing
	 * p50/p95/p99 when a later flush folds more requests into the same bucket,
	 * so exactly one bucket in a read window — the open one — has any use for
	 * it, while `url_row_sources()` would hand up to
	 * `MAX_DURATIONS_PER_BUCKET` floats per row to every poll.
	 */
	public const NS_URL_DUR     = 'url_dur';
	/**
	 * The URL index's COARSE tier: `urls_h:{shard}:{Y-m-d-H}`, one key per hour
	 * holding the same row shape as a fine bucket.
	 *
	 * The readers distinguish exactly two resolutions — the whole retention
	 * window, and the last complete hour — so five-minute buckets buy precision
	 * at the window's EDGE and nothing else. Behind the recent tail they read as
	 * hours, which is 13 + 23 keys per shard against 288.
	 */
	public const NS_URLS_HOUR   = 'urls_h';
	/** Per-URL category time series. */
	public const NS_URL_CAT     = 'url_cat';
	/** Per-URL dimensional time series. */
	public const NS_URL_DIM     = 'url_dim';

	/** Key prefix under the install scope. */
	private const PREFIX_BASE  = 'evlog';
	/** Per-URL accumulator geometry; capacity is roughly the product. */
	private const URL_ACCUMULATOR_SIZE    = 1000;
	private const URL_ACCUMULATOR_BUCKETS = 5;

	/** Shortest retention window the stats keyspace works with, in seconds. */
	public const PREFIX_FLOOR = 3600;

	/** Summed fields of one dimensional value => whether it is a whole count. */
	public const DIM_SUMS = [ 'c' => true, 's' => false, 'm' => false ];

	/** Summed fields of one category => whether it is a whole count. */
	public const CAT_SUMS = [ 't' => false, 'c' => true, 'n' => true ];

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

	/** The DISPLAY row's split field; a stored row uses `ROW_SRV`. */
	public const URL_SRV_FIELD = 'srv';

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
	 * The eight fields that ADD come FIRST, and in `URL_SRV_SUMS` order, so one
	 * map describes both the row's summed half and the per-server split values
	 * — the split being that row restricted to one server, on the same indexes.
	 *
	 * The READER's row is separate and stays named: it is the display shape,
	 * it crosses the wire as JSON, and `fold_index_row()` is the one place the
	 * two meet. Its SPLIT is not display data at all — every projection strips
	 * it — so it stays positional through the fold and is named only by
	 * `swap_url_server_sums()`, for the one server a read is scoped to.
	 */
	public const ROW_COUNT       = 0;
	public const ROW_TIMED_COUNT = 1;
	public const ROW_SUM_MS      = 2;
	public const ROW_SUM_PEAK_MB = 3;
	public const ROW_COUNT_2XX   = 4;
	public const ROW_COUNT_3XX   = 5;
	public const ROW_COUNT_4XX   = 6;
	public const ROW_COUNT_5XX   = 7;
	public const ROW_URL         = 8;
	public const ROW_MIN_MS      = 9;
	public const ROW_MAX_MS      = 10;
	public const ROW_MAX_PEAK_MB = 11;
	public const ROW_LAST_SEEN   = 12;
	public const ROW_WORKER      = 13;
	public const ROW_DURATIONS   = 14;
	public const ROW_SRV         = 15;
	public const ROW_P50_MS      = 16;
	public const ROW_P95_MS      = 17;
	public const ROW_P99_MS      = 18;

	/**
	 * Summed fields of a URL row and of its per-server split => whether each is
	 * a whole count. ONE map for both, which the index order above is chosen to
	 * allow. Only fields that ADD: the extremes and the percentiles come off a
	 * sampled reservoir, so a scoped row keeps the URL's own.
	 */
	public const URL_SRV_SUMS = [
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
	 * name. `fold_index_row()` names the row at the storage/display boundary
	 * and `swap_url_server_sums()` names one server's split;
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
		self::ROW_URL         => 'url',
		self::ROW_MIN_MS      => 'min_ms',
		self::ROW_MAX_MS      => 'max_ms',
		self::ROW_MAX_PEAK_MB => 'max_peak_mb',
		self::ROW_LAST_SEEN   => 'last_seen',
		self::ROW_WORKER      => 'worker',
		self::ROW_DURATIONS   => 'durations',
		self::ROW_SRV         => 'srv',
		self::ROW_P50_MS      => 'p50_ms',
		self::ROW_P95_MS      => 'p95_ms',
		self::ROW_P99_MS      => 'p99_ms',
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
	 * How far ahead of our clock a producer's bucket still counts as open.
	 *
	 * A hub takes each record's own timestamp, so a spoke running slightly fast
	 * writes a bucket we have not reached. Holding those is right. Holding them
	 * without a ceiling is not: one corrupt timestamp would pin its frames until
	 * that year arrives. Past this, a skewed producer pays redundant last-wins
	 * copies instead — which is a cost, where the other is a leak. One
	 * `Request_Builder` eviction window under its DEFAULT declaration, the same
	 * lateness the shipped pipeline tolerates; a topology that declares another
	 * bucket count warns that this no longer measures it.
	 */
	private const MAX_FUTURE_SKEW_SEC = Request_Builder_Node::DEFAULT_EVICTION_WINDOW_SEC;

	/**
	 * Ceiling on one reader's bucket enumeration (24h at the 300s width).
	 *
	 * This bounds BUCKETS, not keys: the URL index asks for one key per shard
	 * per bucket, so a full read costs `URL_SHARDS x` this — 4,608 keys in one
	 * `lookup_multi` at the ceiling. That is the trade sharding makes, and it
	 * is the right way round: a point read for one URL costs a single shard,
	 * and no item approaches memcached's 1MB limit, where the unsharded blob
	 * exceeded it outright once rows carried a `srv` split.
	 */
	public const MAX_READ_BUCKETS = 288;

	/**
	 * Fine buckets a read keeps: the twelve `RECENT_BUCKETS` a "last hour" rate
	 * divides by, plus the one still filling that the rate drops.
	 */
	public const FINE_BUCKETS = 13;

	/** The two tables this store keeps, by ROLE — their TTLs coincide at the floor. */
	private const ROLE_AGGREGATE = 'aggregate';
	private const ROLE_URL       = 'url';

	/**
	 * Mirror seam — when set, invoked `(string $key, array $data, int $ttl, string $ns)`
	 * after each memcache write that landed, so a durable partition can shadow
	 * stats for a later read-back. The namespace lets the mirror route aggregates
	 * vs. the bounded per-URL namespaces. Null (default) = zero overhead.
	 * `Flame_Builder_Node::arm_stats_mirror()` is the only wiring. Signature:
	 * `function(string $key, array $data, int $ttl, string $ns): void`.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $mirror = null;

	/**
	 * Rehydrate seam — the read counterpart of `$mirror`, invoked with the
	 * keys a read missed on. Handed to every Table as its durable backing, so a
	 * miss falls through to the mirror and lands back in memcache without any
	 * caller here knowing. Null (default) leaves the tables memcache-only.
	 * `Flame_Builder_Node::arm_stats_mirror()` is the only wiring.
	 *
	 * @var (\Closure(array<array-key,mixed>): array<array-key,array{value: mixed, ttl?: int}>)|null
	 */
	public ?\Closure $rehydrate = null;
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
	 * units for three fields. A URL row is 19 fields across three PHP files.
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
	 * Read many of one URL's dimensional buckets in a single round-trip.
	 *
	 * @param string            $url_hash 12-char URL hash.
	 * @param array<int,string> $buckets  Bucket keys.
	 * @return array<string,mixed> Dimension maps keyed by bucket; misses absent.
	 */
	public function get_url_dimensional_buckets( string $url_hash, array $buckets ): array {
		return $this->lookup_buckets( [ self::NS_URL_DIM, $url_hash ], $buckets );
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
	 * Read many of one URL's category buckets in a single round-trip.
	 *
	 * @param string            $url_hash 12-char URL hash.
	 * @param array<int,string> $buckets  Bucket keys.
	 * @return array<string,mixed> Category maps keyed by bucket; misses absent.
	 */
	public function get_url_category_buckets( string $url_hash, array $buckets ): array {
		return $this->lookup_buckets( [ self::NS_URL_CAT, $url_hash ], $buckets );
	}

	/**
	 * Overwrite one request-total bucket. Per-bucket, like the leaderboard: the
	 * bucket is the unit that changes, so it is the unit that gets written.
	 *
	 * @param string              $bucket Bucket key.
	 * @param array<string,mixed> $data   Totals for the bucket.
	 * @return bool True when the set landed.
	 */
	public function set_hourly_bucket( string $bucket, array $data ): bool {
		return $this->bucket_set( [ self::NS_HOURLY ], $bucket, $data );
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
		$at      = \strrpos( $key, ':' );
		$bucket  = false === $at ? $key : \substr( $key, $at + 1 );
		$opened  = self::bucket_key( $now );
		// Shape comes from bucket_key() itself, never a second spelling of it.
		return \strlen( $bucket ) === \strlen( $opened )
			&& $bucket >= $opened
			&& $bucket <= self::bucket_key( $now + self::MAX_FUTURE_SKEW_SEC );
	}

	/**
	 * The bucket a timestamp falls in: `Y-m-d-H-mm` UTC, floored to
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
	 *                             `retention_buckets()`, or the caller's memo of it.
	 *                             Taken rather than re-enumerated: the memo exists so
	 *                             one response cannot straddle a bucket boundary, and
	 *                             reading the clock again here is how it would.
	 * @return array{fine: list<string>, hours: list<string>}
	 */
	public static function read_plan( array $window ): array {
		$fine = \array_slice( $window, 0, self::FINE_BUCKETS );
		// @longform The hour the fine tail lands IN is read fine-grained to its
		// END, not to wherever the tail stopped. Handing it to the coarse tier
		// instead would read it twice; leaving it out read the rest of that
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
	private static function hour_of( string $bucket ): string {
		return \substr( $bucket, 0, 13 );
	}

	/**
	 * Read one shard's coarse hours. Same pair shape as `url_row_sources()`, so
	 * one fold serves both tiers.
	 *
	 * @param array<int,string> $hours Hour keys.
	 * @param ?string           $shard One shard, or null for every shard.
	 * @return list<array{0: string, 1: array<array-key,mixed>}>
	 */
	public function url_hour_sources( array $hours, ?string $shard = null ): array {
		$shards   = null === $shard ? self::url_shards() : [ $shard ];
		$prefixes = [];
		foreach ( $shards as $one ) {
			$prefixes[] = [ self::NS_URLS_HOUR, $one ];
		}
		return $this->lookup_bucket_sets( $prefixes, $hours );
	}

	/**
	 * Overwrite one shard's rows for one coarse hour.
	 *
	 * An EMPTY hour is still written: a missing key means "not rolled up yet",
	 * which is what sends the reader back to the twelve fine buckets, and an
	 * hour with no traffic must not look like one that has not been folded.
	 *
	 * @param string                 $hour  Hour key.
	 * @param string                 $shard Shard name from `url_shard()`.
	 * @param array<array-key,mixed> $rows  The hour's merged rows.
	 * @return bool True when the set landed.
	 */
	public function set_url_hour( string $hour, string $shard, array $rows ): bool {
		return $this->bucket_set( [ self::NS_URLS_HOUR, $shard ], $hour, $rows );
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
	 * Every source of URL rows for a window, as `[bucket, rows]` pairs.
	 *
	 * Pairs rather than a merged map: one shard's rows are complete for the
	 * hashes they cover, and the caller owns how it combines shards. All
	 * sixteen, in one round-trip — decision 14: one unscoped read serves every
	 * scope a request asks for.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @param ?string            $shard   Read ONE shard, for a reader asking about a
	 *                                    single URL: `url_shard()` is the first hex
	 *                                    digit of its hash, so one URL is in one
	 *                                    shard and the other fifteen are dead weight.
	 *                                    Null reads every shard, which is what a
	 *                                    reader rendering the whole table wants — and
	 *                                    what an older consumer keeps by not passing it.
	 * @return list<array{0: string, 1: array<array-key,mixed>}>
	 */
	public function url_row_sources( array $buckets, ?string $shard = null ): array {
		$shards   = null === $shard ? self::url_shards() : [ $shard ];
		$prefixes = [];
		foreach ( $shards as $one ) {
			$prefixes[] = [ self::NS_URLS, $one ];
		}
		return $this->lookup_bucket_sets( $prefixes, $buckets );
	}

	/**
	 * Every shard the URL index is spread across.
	 *
	 * @return list<string>
	 */
	public static function url_shards(): array {
		return \array_map( 'dechex', \range( 0, self::URL_SHARDS - 1 ) );
	}

	/**
	 * Read one category bucket: `{ cat => { t, c, n } }`.
	 *
	 * @param string $bucket Bucket key.
	 * @param string $server Reporting server; '' reads the global series.
	 * @return array<string,mixed> The bucket, [] on miss.
	 */
	public function get_category_bucket( string $bucket, string $server = '' ): array {
		return $this->bucket_get( self::cat_parts( $server ), $bucket );
	}

	/**
	 * Read one dimension's bucket: `{ value => { c, s, m } }`.
	 *
	 * @param string $dimension Dimension name.
	 * @param string $bucket    Bucket key.
	 * @param string $server    Reporting server; '' reads the global series.
	 * @return array<string,mixed> The bucket, [] on miss.
	 */
	public function get_dimensional_bucket( string $dimension, string $bucket, string $server = '' ): array {
		return $this->bucket_get( self::dim_parts( $dimension, $server ), $bucket );
	}

	/**
	 * Read one leaderboard bucket, global or per server.
	 *
	 * @param string $bucket Bucket key.
	 * @param string $server Reporting server; '' reads the global series.
	 * @return array<string,mixed> Bucket sums, [] on miss.
	 */
	public function get_leaderboard_bucket( string $bucket, string $server = '' ): array {
		return $this->bucket_get( self::lb_parts( $server ), $bucket );
	}

	/**
	 * Read one URL's category bucket. Same shape as `get_category_bucket()`.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @param string $bucket   Bucket key.
	 * @return array<string,mixed> The bucket, [] on miss.
	 */
	public function get_url_category_bucket( string $url_hash, string $bucket ): array {
		return $this->bucket_get( [ self::NS_URL_CAT, $url_hash ], $bucket );
	}

	/**
	 * Read one URL's dimensional bucket: `{ dim => { value => { c, s, m } } }`.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @param string $bucket   Bucket key.
	 * @return array<string,mixed> The bucket, [] on miss.
	 */
	public function get_url_dimensional_bucket( string $url_hash, string $bucket ): array {
		return $this->bucket_get( [ self::NS_URL_DIM, $url_hash ], $bucket );
	}

	/**
	 * Read one shard of one bucket — what a writer read-modify-writes.
	 *
	 * @param string $bucket Bucket key.
	 * @param string $shard  Shard name from `url_shard()`.
	 * @return array<string,mixed>
	 */
	public function get_url_shard( string $bucket, string $shard ): array {
		return $this->bucket_get( [ self::NS_URLS, $shard ], $bucket );
	}

	/**
	 * Write one shard of one bucket.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param string                 $shard  Shard name from `url_shard()`.
	 * @param array<array-key,mixed> $rows   That shard's rows.
	 */
	public function set_url_shard( string $bucket, string $shard, array $rows ): bool {
		return $this->bucket_set( [ self::NS_URLS, $shard ], $bucket, $rows );
	}

	/**
	 * Read one shard's duration reservoirs. Writer-only; see `NS_URL_DUR`.
	 *
	 * @param string $bucket Bucket key.
	 * @param string $shard  Shard name from `url_shard()`.
	 * @return array<array-key,mixed> `{ url_hash: [samples] }`, [] on miss.
	 */
	public function get_url_durations( string $bucket, string $shard ): array {
		return $this->bucket_get( [ self::NS_URL_DUR, $shard ], $bucket );
	}

	/**
	 * Read MANY of one shard's duration reservoirs in one round trip — the
	 * batched form the hour fold wants, which reads twelve at a time.
	 *
	 * A miss matters more here than elsewhere: `NS_URL_DUR` is excluded from
	 * the mirror, so a missed key can never be rehydrated and the read-through
	 * walks the whole index to its last line before giving up. Twelve of those
	 * share one walk; twelve separate gets pay twelve.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @param string            $shard   Shard name from `url_shard()`.
	 * @return array<string,mixed> `{ url_hash: [samples] }` by bucket; misses absent.
	 */
	public function get_url_duration_buckets( array $buckets, string $shard ): array {
		return $this->lookup_buckets( [ self::NS_URL_DUR, $shard ], $buckets );
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
	 * Read several namespace prefixes across the same buckets in ONE round-trip.
	 *
	 * Decisions 1 and 6. Pairs, not a map: two prefixes can hold one bucket.
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
				$map[ $this->key( ...[ ...$parts, $bucket ] ) ] = $bucket;
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
	 * Overwrite one shard's duration reservoirs.
	 *
	 * @param string                 $bucket    Bucket key.
	 * @param string                 $shard     Shard name from `url_shard()`.
	 * @param array<array-key,mixed> $durations `{ url_hash: [samples] }`.
	 * @return bool True when the set landed.
	 */
	public function set_url_durations( string $bucket, string $shard, array $durations ): bool {
		return $this->bucket_set( [ self::NS_URL_DUR, $shard ], $bucket, $durations );
	}

	/**
	 * Group URL rows by the shard their hash names.
	 *
	 * The routing rule in one place.
	 *
	 * @param array<array-key,mixed> $rows Rows by url_hash.
	 * @return array<array-key,array<array-key,mixed>>
	 */
	public static function rows_by_shard( array $rows ): array {
		$by_shard = [];
		foreach ( $rows as $hash => $row ) {
			$by_shard[ self::url_shard( (string) $hash ) ][ $hash ] = $row;
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
	 */
	public static function url_shard( string $url_hash ): string {
		$first = \strtolower( \substr( $url_hash, 0, 1 ) );
		return \ctype_xdigit( $first ) ? $first : '0';
	}

	/**
	 * Read one request-total bucket: `{ count, sum_ms, sum_peak_mb }`.
	 *
	 * @param string $bucket Bucket key.
	 * @return array<string,mixed> Totals for the bucket, [] on miss.
	 */
	public function get_hourly_bucket( string $bucket ): array {
		return $this->bucket_get( [ self::NS_HOURLY ], $bucket );
	}

	/**
	 * Read one bucket of a namespace. Every bucketed namespace puts the bucket
	 * LAST, so scope (server, URL, dimension) is a key prefix.
	 *
	 * @param array<int,string> $parts  Namespace prefix parts, before the bucket.
	 * @param string            $bucket Bucket key.
	 * @return array<string,mixed> The bucket, [] on miss.
	 */
	private function bucket_get( array $parts, string $bucket ): array {
		$val = $this->lookup( $this->key( ...[ ...$parts, $bucket ] ) );
		return \is_array( $val ) ? self::string_keys( $val ) : [];
	}

	/**
	 * Read one URL's stats blob — flame tree, profiles, last_modified. Whole, not
	 * summable: readers take the first partition that has it rather than merging.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<array-key,mixed>|null Blob, or null on miss.
	 */
	public function get_url_stats( string $url_hash ): ?array {
		$val = $this->lookup( $this->key( self::NS_URL, $url_hash ) );
		return \is_array( $val ) ? $val : null;
	}

	/** Read one key through the table; null (no backend) and miss both read empty. */
	private function lookup( string $key ): mixed {
		return $this->table( self::ROLE_AGGREGATE )?->lookup( $key );
	}

	/**
	 * Overwrite one URL's stats blob, under the shorter per-URL TTL.
	 *
	 * @param string               $url_hash 12-char URL hash.
	 * @param array<string,mixed> $data     Whole blob.
	 * @return bool True when the set landed.
	 */
	public function set_url_stats( string $url_hash, array $data ): bool {
		return $this->store( $this->key( self::NS_URL, $url_hash ), $data, $this->ttl_url_stats(), self::NS_URL );
	}

	/**
	 * Overwrite one leaderboard bucket.
	 *
	 * @param string              $bucket Bucket key.
	 * @param array<string,mixed> $data   Merged bucket sums.
	 * @param string              $server Reporting server; '' writes the global series.
	 * @return bool True when the set landed.
	 */
	public function set_leaderboard_bucket( string $bucket, array $data, string $server = '' ): bool {
		return $this->bucket_set( self::lb_parts( $server ), $bucket, $data );
	}

	/**
	 * The namespace prefix for a leaderboard scope — the one place the global
	 * and per-server keyspaces differ.
	 *
	 * @param string $server Reporting server; '' for the global series.
	 * @return list<string>
	 */
	private static function lb_parts( string $server ): array {
		return '' === $server ? [ self::NS_LB ] : [ self::NS_LB_S, self::server_key( $server ) ];
	}

	/**
	 * Overwrite one dimension's series, global or per server.
	 *
	 * @param string                 $dimension Dimension name.
	 * @param string                 $bucket    Bucket key.
	 * @param array<array-key,mixed> $data      The bucket's value map.
	 * @param string                 $server    Reporting server; '' writes the global series.
	 * @return bool True when the set landed.
	 */
	public function set_dimensional_bucket( string $dimension, string $bucket, array $data, string $server = '' ): bool {
		return $this->bucket_set( self::dim_parts( $dimension, $server ), $bucket, $data );
	}

	/**
	 * The namespace prefix for a dimensional scope.
	 *
	 * @param string $dimension Dimension name, e.g. `ua`.
	 * @param string $server    Reporting server; '' is the global series.
	 * @return list<string>
	 */
	private static function dim_parts( string $dimension, string $server ): array {
		return '' === $server ? [ self::NS_DIM, $dimension ] : [ self::NS_DIM, $dimension, self::server_key( $server ) ];
	}

	/**
	 * Overwrite one URL's dimensional bucket, every dimension in one value.
	 *
	 * @param string              $url_hash 12-char URL hash.
	 * @param string              $bucket   Bucket key.
	 * @param array<string,mixed> $data     Value maps keyed by dimension.
	 * @return bool True when the set landed.
	 */
	public function set_url_dimensional_bucket( string $url_hash, string $bucket, array $data ): bool {
		return $this->bucket_set( [ self::NS_URL_DIM, $url_hash ], $bucket, $data );
	}

	/**
	 * Overwrite one category bucket.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param array<array-key,mixed> $data   The bucket's category map.
	 * @param string                 $server Reporting server; '' writes the global series.
	 * @return bool True when the set landed.
	 */
	public function set_category_bucket( string $bucket, array $data, string $server = '' ): bool {
		return $this->bucket_set( self::cat_parts( $server ), $bucket, $data );
	}

	/**
	 * The namespace prefix for a category scope.
	 *
	 * @param string $server Reporting server; '' is the global series.
	 * @return list<string>
	 */
	private static function cat_parts( string $server ): array {
		return '' === $server ? [ self::NS_CATEGORIES ] : [ self::NS_CATEGORIES, self::server_key( $server ) ];
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
	 * Overwrite one URL's category bucket.
	 *
	 * @param string                 $url_hash 12-char URL hash.
	 * @param string                 $bucket   Bucket key.
	 * @param array<array-key,mixed> $data     The bucket's category map.
	 * @return bool True when the set landed.
	 */
	public function set_url_category_bucket( string $url_hash, string $bucket, array $data ): bool {
		return $this->bucket_set( [ self::NS_URL_CAT, $url_hash ], $bucket, $data );
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
		return $this->store( $this->key( ...[ ...$parts, $bucket ] ), $data, $this->ttl(), $parts[0] );
	}

	/**
	 * Write to memcache, then (if wired AND the set landed) shadow the same write
	 * to the mirror seam — a rejected/failed set must not be durably recorded and
	 * resurrected by a later read-back.
	 *
	 * @param string                 $key  Full memcache key.
	 * @param array<array-key,mixed> $data Value to store.
	 * @param int                  $ttl  Expiry in seconds.
	 * @param string               $ns   Namespace routing hint for the mirror.
	 * @return bool True when the set landed.
	 */
	private function store( string $key, array $data, int $ttl, string $ns ): bool {
		$role = $ttl === $this->ttl_url_stats() ? self::ROLE_URL : self::ROLE_AGGREGATE;
		$ok   = (bool) $this->table( $role )?->store( $key, $data );
		if ( $ok && null !== $this->mirror ) {
			// The mirror records the full backend key, which is the Table's.
			( $this->mirror )( self::entry_key( $this->partition, $key ), $data, $ttl, $ns );
		}
		return $ok;
	}

	/**
	 * Full backend key for one entry — what the mirror records its frames under.
	 *
	 * @param int    $partition Flame-builder partition.
	 * @param string $key       Entry key within the namespace.
	 */
	public static function entry_key( int $partition, string $key ): string {
		return Table_Node::entry_key( self::namespace_for( $partition ), $key );
	}

	/**
	 * Per-URL aggregate accumulator: the un-drained value for a url_hash, or what
	 * was last persisted when none is held.
	 *
	 * @api Flame_Builder_Node's per-URL accumulation.
	 * @param string $url_hash URL hash.
	 */
	public function accumulated_url_stats( string $url_hash ): mixed {
		return $this->table( self::ROLE_URL )?->accumulated( $this->key( self::NS_URL, $url_hash ) );
	}

	/**
	 * Fold a per-URL aggregate into the accumulator, without persisting it.
	 *
	 * @param string              $url_hash URL hash.
	 * @param array<string,mixed> $data     Aggregate to hold.
	 */
	public function accumulate_url_stats( string $url_hash, array $data ): void {
		$this->table( self::ROLE_URL )?->accumulate( $this->key( self::NS_URL, $url_hash ), $data );
	}

	/**
	 * Join a memcache key from the prefix, the partition, and the caller's parts,
	 * scoped to this INSTALL by the substrate.
	 *
	 * Stats live in memcache alone and two installs share one server on Atomic,
	 * so the bare `evlog:p0:hourly` was the same key for both — a co-tenant's
	 * request volume landing in this install's dashboard.
	 *
	 * @param string ...$parts Namespace token first, then any sub-keys.
	 * @return string Full key.
	 */
	private function key( string ...$parts ): string {
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
	 * The Table this store reads and writes, memoized per TTL.
	 *
	 * Two TTLs are in play — `ttl()` for the aggregates and `ttl_url_stats()` for
	 * the bounded per-URL blobs — and a Table's TTL is fixed at construction, so
	 * each gets its own instance over the SAME namespace. Built lazily behind the
	 * backend check because `Table_Node::table()` throws without one, and every
	 * method here has to fail soft instead.
	 *
	 * No L1: Flame_Builder already caches `get_url_stats()` behind its own
	 * `LRU_Cache`, so one here would sit redundantly underneath it.
	 *
	 * The per-URL table is deliberately NOT backed: `accumulated()` falls
	 * through to `lookup()` on every request, and `flame_topn` is 0 in
	 * production, so backing it would pay an index scan per cold URL for a
	 * frame that is never written.
	 *
	 * @param string $role ROLE_AGGREGATE or ROLE_URL; each resolves its own TTL.
	 *        Keyed by role, never by TTL: the two coincide at PREFIX_FLOOR, and a
	 *        shared table would hand aggregate reads the deliberately unbacked one.
	 */
	private function table( string $role ): ?Table_Node {
		if ( null === \Newspack_Nodes\Cache_Backend::shared_first() ) {
			return null;
		}
		if ( ! isset( $this->tables[ $role ] ) ) {
			$is_url = self::ROLE_URL === $role;
			$table  = Table_Node::table(
				self::namespace_for( $this->partition ),
				$is_url ? $this->ttl_url_stats() : $this->ttl()
			);
			if ( $is_url ) {
				$table->accumulator( self::URL_ACCUMULATOR_SIZE, self::URL_ACCUMULATOR_BUCKETS );
			} else {
				// Indirection: the seam is re-armed after a table is memoized.
				$table->backed_by(
					fn ( array $keys ): array => null !== $this->rehydrate ? ( $this->rehydrate )( $keys ) : []
				);
			}
			$this->tables[ $role ] = $table;
		}
		return $this->tables[ $role ];
	}

	/** Retention window, in seconds, for every namespace but `url`. */
	public function ttl(): int {
		return $this->max_lifespan;
	}

	/** Retention for the high-volume `url` namespace: a day's worth cut to a 24th, floored at an hour. */
	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
	}

	/**
	 * Table namespace owning one partition's keyspace.
	 *
	 * @api Tests and the mirror derive backend keys from it.
	 * @param int $partition Flame-builder partition.
	 */
	public static function namespace_for( int $partition ): string {
		return self::PREFIX_BASE . ':p' . $partition;
	}

	/**
	 * Add one URL row into another, for the synthetic overflow row only.
	 *
	 * Only the fields that ADD (`URL_SRV_SUMS`) plus `last_seen`: a percentile
	 * over unrelated URLs describes nothing. The split folds with it, so a
	 * scoped total is exact for the same reason the site's is.
	 *
	 * Both sides are EXPANDED first: a collapsed split says "the row's own
	 * numbers", and `$out`'s counts are already both rows added together, so
	 * the expansion has to read `$into` and `$row` while they still stand
	 * apart. `sum_fields()` skips a non-array, so an unexpanded null is not a
	 * zero — it is that host deleted from the merge with no error anywhere.
	 *
	 * @param array<array-key,mixed> $into The row so far, [] on first fold.
	 * @param array<array-key,mixed> $row  The row being folded in.
	 * @return array<array-key,mixed>
	 */
	public static function fold_url_rows( array $into, array $row ): array {
		// Expanded BEFORE the sum: `$out`'s count is both rows added together.
		$into_srv = self::expand_sole_server( $into, Core::arr( $into[ self::ROW_SRV ] ?? null ) );
		$row_srv  = self::expand_sole_server( $row, Core::arr( $row[ self::ROW_SRV ] ?? null ) );
		// AFTER the sum: it returns `$into`, which carries its own `last_seen`.
		$out                        = self::sum_entry( $into, $row, self::URL_SRV_SUMS );
		$out[ self::ROW_WORKER ]    = ! empty( $into[ self::ROW_WORKER ] ) || ! empty( $row[ self::ROW_WORKER ] );
		$out[ self::ROW_LAST_SEEN ] = \max(
			Core::num_int( $into[ self::ROW_LAST_SEEN ] ?? null ),
			Core::num_int( $row[ self::ROW_LAST_SEEN ] ?? null )
		);

		$out[ self::ROW_SRV ] = self::sum_fields( $into_srv, $row_srv, self::URL_SRV_SUMS );
		return $out;
	}

	/**
	 * Sum two `{count, sum_ms, sum_peak_mb}` totals. The write-side counterpart
	 * of `sums_to_display()`: the schema owns the triple, so it owns the
	 * arithmetic over it, and a non-numeric field on either side reads as zero.
	 *
	 * Fields outside the triple ride through from `$a`: the stored bucket is the
	 * caller's, and rebuilding it here would drop a fourth field silently.
	 *
	 * @param array<string,mixed>    $a One side, and the shape that survives.
	 * @param array<array-key,mixed> $b The other, read by name only.
	 * @return array<string,mixed>
	 */
	public static function add_totals( array $a, array $b ): array {
		return self::string_keys( self::sum_entry( $a, $b, self::HOURLY_SUMS ) );
	}

	/**
	 * Additive merge of one leaderboard bucket's sums into another (modifying $dst).
	 *
	 * Used by FlameBuilder at persist time to combine the current flush's bucket
	 * with the already-persisted bucket of the same key. Static so callers can
	 * use it without an instance.
	 * @param array<string,mixed> $dst
	 * @param array<string,mixed> $src
	 */
	public static function merge_leaderboard_bucket( array &$dst, array $src ): void {
		$dst  = self::string_keys( self::sum_entry( $dst, $src, self::LB_SUMS ) );
		$cats = Core::arr( $dst['categories'] ?? null );
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
	 * Merge one stored URL row into another for the SAME url.
	 *
	 * Both tiers of the write path fold this rule — a flush into its bucket,
	 * twelve buckets into their hour — so it lives once, beside the field table
	 * it reads. Sums add, extremes take the larger, `min_ms` folds only from
	 * TIMED buckets (0 is "nothing folded yet"), and whichever side names the
	 * `url` wins, because merge order varies.
	 *
	 * Percentiles are not merged here: they come off the reservoir, through
	 * `apply_percentiles()`. Contrast `fold_url_rows()`, which folds DIFFERENT
	 * urls into an overflow row and therefore keeps only what adds.
	 *
	 * Both sides are EXPANDED first: a collapsed split says "the row's own
	 * numbers", and `$out`'s counts are already both rows added together, so
	 * the expansion has to read `$into` and `$row` while they still stand
	 * apart. `sum_fields()` skips a non-array, so an unexpanded null is not a
	 * zero — it is that host deleted from the merge with no error anywhere.
	 *
	 * @param array<array-key,mixed> $into The row so far.
	 * @param array<array-key,mixed> $row  The row being folded in.
	 * @return array<array-key,mixed>
	 */
	public static function merge_url_row( array $into, array $row ): array {
		// Expanded BEFORE the sum: `$out`'s count is both rows added together.
		$into_srv = self::expand_sole_server( $into, Core::arr( $into[ self::ROW_SRV ] ?? null ) );
		$row_srv  = self::expand_sole_server( $row, Core::arr( $row[ self::ROW_SRV ] ?? null ) );
		$out      = self::sum_entry( $into, $row, self::URL_SRV_SUMS );
		if ( '' === Core::str( $out[ self::ROW_URL ] ?? '' ) ) {
			$out[ self::ROW_URL ] = Core::str( $row[ self::ROW_URL ] ?? '' );
		}
		$out[ self::ROW_MAX_MS ]      = \max( Core::num_float( $out[ self::ROW_MAX_MS ] ?? null ), Core::num_float( $row[ self::ROW_MAX_MS ] ?? null ) );
		$out[ self::ROW_MAX_PEAK_MB ] = \max( Core::num_float( $out[ self::ROW_MAX_PEAK_MB ] ?? null ), Core::num_float( $row[ self::ROW_MAX_PEAK_MB ] ?? null ) );
		$out[ self::ROW_LAST_SEEN ]   = \max( Core::num_int( $out[ self::ROW_LAST_SEEN ] ?? null ), Core::num_int( $row[ self::ROW_LAST_SEEN ] ?? null ) );
		$out[ self::ROW_WORKER ]      = ! empty( $out[ self::ROW_WORKER ] ) || ! empty( $row[ self::ROW_WORKER ] );
		if ( Core::num_int( $row[ self::ROW_TIMED_COUNT ] ?? null ) > 0 ) {
			$held                    = Core::num_float( $out[ self::ROW_MIN_MS ] ?? null );
			$row_min                 = Core::num_float( $row[ self::ROW_MIN_MS ] ?? null );
			$out[ self::ROW_MIN_MS ] = 0.0 === $held ? $row_min : \min( $held, $row_min );
		}
		$out[ self::ROW_SRV ] = self::sum_fields( $into_srv, $row_srv, self::URL_SRV_SUMS );
		return $out;
	}

	/**
	 * Sum `$fields` from `$incoming` into `$into`, entry by entry. The one merge
	 * both the dimensional (`c,s,m`) and category (`t,c,n`) series share.
	 *
	 * Only `$fields` survive — unlike `add_totals()`, which lets a field outside
	 * its triple ride through. Every shape here is closed (`{c,s,m}`, `{t,c,n}`),
	 * so there is nothing to carry; a shape that grows a field adds it to the
	 * table rather than relying on passthrough.
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
	 * The inverse of `collapse_sole_server()`: a collapsed host takes the row's
	 * own summed fields back. Every merge of a stored split calls it FIRST —
	 * `merge_url_row()`, `fold_url_rows()` and
	 * `Performance_CI_Node::fold_index_row()` — because `sum_fields()` skips a
	 * non-array and would drop the host rather than fold a zero.
	 *
	 * @param array<array-key,mixed> $row   The row the split belongs to.
	 * @param array<array-key,mixed> $split That row's stored split.
	 * @return array<array-key,mixed>
	 */
	public static function expand_sole_server( array $row, array $split ): array {
		foreach ( $split as $server => $sums ) {
			if ( null !== $sums ) {
				continue;
			}
			$split[ $server ] = self::sum_entry( [], $row, self::URL_SRV_SUMS );
		}
		return $split;
	}

	/**
	 * Sum `$fields` from one entry into another. What `sum_fields()` does per
	 * key, reachable directly by the callers holding a single row — those were
	 * inventing a throwaway map key to get at it, then unwrapping the result.
	 *
	 * @param array<array-key,mixed>  $into    The entry so far.
	 * @param array<array-key,mixed>  $from    The entry being added.
	 * @param array<array-key,bool>   $fields  Field => whether it is a whole count.
	 * @return array<array-key,mixed>
	 */
	public static function sum_entry( array $into, array $from, array $fields ): array {
		foreach ( $fields as $field => $is_count ) {
			$into[ $field ] = $is_count
				? Core::num_int( $into[ $field ] ?? null ) + Core::num_int( $from[ $field ] ?? null )
				: Core::num_float( $into[ $field ] ?? null ) + Core::num_float( $from[ $field ] ?? null );
		}
		return $into;
	}

	/**
	 * A split of ONE server that served every request the row counted is the
	 * row restated, so it stores the host name against `null`.
	 *
	 * ~33 bytes where the positional sums are ~112, on the field that was over
	 * half the whole index read: on a fleet of disjoint sites every URL is
	 * served by exactly one host, so this is the common row, not the rare one.
	 *
	 * COUNT alone decides it. Every `URL_SRV_SUMS` field is summed into the row
	 * and into the split from the same increment, so a matching count means the
	 * other seven match by construction — comparing floats would be the same
	 * question asked less reliably.
	 *
	 * `expand_sole_server()` is the inverse, and EVERY merge — reader or
	 * writer — applies it before summing: `sum_fields()` skips a non-array
	 * value, so an unexpanded null takes that server's whole history out of a
	 * scoped read silently. `merge_url_row()` and `fold_url_rows()` do it for
	 * both sides; `Performance_CI_Node::fold_index_row()` does it on the read.
	 *
	 * @param array<array-key,mixed> $row   The row the split belongs to.
	 * @param array<array-key,mixed> $split That row's per-server split, capped.
	 * @return array<array-key,mixed>
	 */
	public static function collapse_sole_server( array $row, array $split ): array {
		if ( 1 !== \count( $split ) ) {
			return $split;
		}
		$server = \array_key_first( $split );
		$sums   = Core::arr( $split[ $server ] );
		$same   = Core::num_int( $sums[ self::ROW_COUNT ] ?? null )
			=== Core::num_int( $row[ self::ROW_COUNT ] ?? null );
		return $same ? [ $server => null ] : $split;
	}

	/**
	 * Swap a URL row's SUMMED fields for one server's, or null when that server
	 * never served the URL. `''` strips the split and returns the row as it
	 * stands, so one call covers the unscoped case too.
	 *
	 * The row it returns is not yet in one scope: its means and its recent-window
	 * share are still the site's, and `Performance_CI_Node::project_row()`
	 * finishes it. WHICH fields swap is the schema's; the division is the
	 * reader's (decision 2).
	 *
	 * This is also the SPLIT's one crossing from indexes into display names, and
	 * the only one it needs: no reader ever displays the split, so it stays
	 * positional through the whole fold and eight names are spelled here, for
	 * the one selected server, rather than for every server of every stored row.
	 *
	 * @param array<array-key,mixed> $row    A merged URL row carrying its split.
	 * @param string                 $server Reporting server; '' scopes to none.
	 * @return array<array-key,mixed>|null
	 */
	public static function swap_url_server_sums( array $row, string $server ): ?array {
		$split = Core::arr( $row[ self::URL_SRV_FIELD ] ?? null );
		unset( $row[ self::URL_SRV_FIELD ] );
		if ( '' === $server ) {
			return $row;
		}
		// `is_array`: a non-array leaves the swap empty and widens the row.
		if ( ! \is_array( $split[ $server ] ?? null ) ) {
			return null;
		}
		$sums = Core::arr( $split[ $server ] );
		foreach ( self::URL_SRV_SUMS as $index => $is_count ) {
			$row[ self::ROW_FIELD_NAMES[ $index ] ] = $is_count
				? Core::num_int( $sums[ $index ] ?? null )
				: Core::num_float( $sums[ $index ] ?? null );
		}
		return $row;
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
	 * Stamp a row's percentiles from its duration reservoir.
	 *
	 * Percentiles do not merge, so both tiers recompute them from the samples
	 * they hold rather than from any row's own figures. An empty reservoir
	 * leaves the row alone: a row nothing timed keeps what it was stored with.
	 *
	 * @param array<array-key,mixed> $row     The row to stamp.
	 * @param array<array-key,mixed> $samples That row's reservoir.
	 * @return array<array-key,mixed>
	 */
	public static function apply_percentiles( array $row, array $samples ): array {
		if ( [] === $samples ) {
			return $row;
		}
		\sort( $samples );
		$n                       = \count( $samples );
		$row[ self::ROW_P50_MS ] = $samples[ (int) ( $n * 0.50 ) ] ?? 0;
		$row[ self::ROW_P95_MS ] = $samples[ (int) ( $n * 0.95 ) ] ?? 0;
		$row[ self::ROW_P99_MS ] = $samples[ (int) ( $n * 0.99 ) ] ?? 0;
		return $row;
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
	 * Whether a row key is one of the overflow rows — either of them.
	 *
	 * @param string $hash A URL row key.
	 */
	public static function is_other_key( string $hash ): bool {
		return self::OTHER_KEY === $hash || self::OTHER_WORKER_KEY === $hash;
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
	 * @param int                   $total_count  Total profiled requests.
	 * @param float                 $sum_req_time Sum of per-request $req_time values.
	 * @param array<string,mixed>  $sums         Per-category sums keyed by category name.
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

}
