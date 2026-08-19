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
 * namespace and the `get_url_index_hourly()` name are historical rather than
 * descriptive — both are five-minute buckets.
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
	/** Distinct values kept per global dimension bucket. */
	public const MAX_DIM_VALUES           = 20;
	/** Raw durations sampled per URL bucket for percentiles; Algorithm R past the cap. */
	public const MAX_DURATIONS_PER_BUCKET = 100;
	/** Distinct values kept per per-URL dimension bucket. */
	public const MAX_URL_DIM_VALUES       = 10;
	/** Category time series, global or per server. */
	public const NS_CATEGORIES  = 'categories';
	/** Dimensional time series, global or per server. */
	public const NS_DIM         = 'dim';

	/** Request totals per bucket; one key per partition. */
	public const NS_HOURLY      = 'hourly';
	/** Global leaderboard bucket. */
	public const NS_LB          = 'lb';
	/** Per-server leaderboard bucket. */
	public const NS_LB_S        = 'lb_s';
	/** Per-URL stats blob: flame tree and profiles. */
	public const NS_URL         = 'url';
	/** URL index bucket. */
	public const NS_URLS        = 'urls';
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

	/** Bucket width in minutes — the granularity every bucketed namespace is keyed at. */
	private const BUCKET_MINUTES = 5;

	/** Ceiling on one reader's bucket enumeration, so get_multi stays bounded (24h). */
	public const MAX_READ_BUCKETS = 288;

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
	 * @var (\Closure(list<string>): array<array-key,array{value: mixed, ttl?: int}>)|null
	 */
	public ?\Closure $rehydrate = null;
	/** @var int Retention window in seconds, as Config::stats_retention_seconds() floored it. */
	private int $max_lifespan;

	/** @var array<string,Table_Node> Table per ROLE, over one namespace. */
	private array $tables = [];

	/** @var int Flame-builder partition whose keyspace this store owns. */
	private int $partition;
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
	 * Read one `urls` bucket. Identical to `get_url_bucket()`, under the name
	 * `Flame_Builder_Node` writes and reads through.
	 *
	 * @param string $bucket Bucket key.
	 * @return array<string,mixed> Bucket contents, [] on miss.
	 */
	public function get_url_index_hourly( string $bucket ): array {
		return $this->get_url_bucket( $bucket );
	}

	/**
	 * Read one `urls` bucket: `{ url_hash => { url, count, timed_count, sum_ms,
	 * min_ms, max_ms, avg_ms, p50_ms, p95_ms, p99_ms, durations, count_2xx,
	 * count_3xx, count_4xx, count_5xx, sum_peak_mb, max_peak_mb, last_seen } }`.
	 *
	 * @param string $bucket Bucket key.
	 * @return array<string,mixed> Bucket contents, [] on miss.
	 */
	public function get_url_bucket( string $bucket ): array {
		$val = $this->lookup( $this->key( self::NS_URLS, $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one request-total bucket: `{ count, sum_ms, sum_peak_mb }`.
	 *
	 * @param string $bucket Bucket key.
	 * @return array<string,mixed> Totals for the bucket, [] on miss.
	 */
	public function get_hourly_bucket( string $bucket ): array {
		$val = $this->lookup( $this->key( self::NS_HOURLY, $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one leaderboard bucket: `{ count, sum_req_time, categories: {
	 * cat => { samples, sum_time, sum_count, entries } } }`. Sums, never means —
	 * `sums_to_display()` divides at read time so cross-bucket and
	 * cross-partition merges stay exact addition.
	 *
	 * @param string $bucket Bucket key.
	 * @param string $server Reporting server; '' reads the global series.
	 * @return array<string,mixed> Bucket sums, [] on miss.
	 */
	public function get_leaderboard_bucket( string $bucket, string $server = '' ): array {
		$val = $this->lookup( $this->key( ...[ ...self::lb_parts( $server ), $bucket ] ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one dimension's series: `{ bucket => { value => { c, s, m } } }` —
	 * request count, summed duration in ms, summed peak MB per distinct value.
	 *
	 * @param string $dimension Dimension name, e.g. `ua`.
	 * @param string $server    Reporting server; '' reads the global series.
	 * @return array<string,mixed> Series by bucket, [] on miss.
	 */
	public function get_dimensional( string $dimension, string $server = '' ): array {
		$parts = [ self::NS_DIM, $dimension ];
		if ( '' !== $server ) {
			$parts[] = self::server_key( $server );
		}
		$val = $this->lookup( $this->key( ...$parts ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one URL's dimensional series, every dimension in one value:
	 * `{ dim => { bucket => { value => { c, s, m } } } }`.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<string,mixed> Series by dimension, [] on miss.
	 */
	public function get_url_dimensional( string $url_hash ): array {
		$val = $this->lookup( $this->key( self::NS_URL_DIM, $url_hash ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read the global category series: `{ bucket => { cat => { t, c, n } } }` —
	 * summed time in ms, summed invocation count, and requests sampled.
	 *
	 * @return array<string,mixed> Series by bucket, [] on miss.
	 */
	public function get_categories(): array {
		$val = $this->lookup( $this->key( self::NS_CATEGORIES ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one URL's category series. Same shape as `get_categories()`.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<string,mixed> Series by bucket, [] on miss.
	 */
	public function get_url_categories( string $url_hash ): array {
		$val = $this->lookup( $this->key( self::NS_URL_CAT, $url_hash ) );
		return self::map_or_empty( $val );
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
		return $this->store( $this->key( self::NS_HOURLY, $bucket ), $data, $this->ttl(), self::NS_HOURLY );
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
		$width = self::BUCKET_MINUTES * 60;
		$count = \min( (int) \ceil( $retention_seconds / $width ) + 1, self::MAX_READ_BUCKETS );
		$out   = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = self::bucket_key( $now - ( $i * $width ) );
		}
		return $out;
	}

	/**
	 * The bucket a timestamp falls in: `Y-m-d-H-mm` UTC, floored to
	 * BUCKET_MINUTES (which must divide 60). Lexical order is chronological
	 * order, which is what lets expiry compare keys with `<` against a cutoff.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function bucket_key( int $timestamp ): string {
		return \gmdate( 'Y-m-d-H-i', $timestamp - ( $timestamp % ( self::BUCKET_MINUTES * 60 ) ) );
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
	 * Read many `urls` buckets.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @return array<string,mixed> Bucket contents keyed by bucket; misses absent.
	 */
	public function get_url_buckets( array $buckets ): array {
		return $this->lookup_buckets( [ self::NS_URLS ], $buckets );
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
		if ( empty( $buckets ) ) {
			return [];
		}
		$map = [];
		foreach ( $buckets as $bucket ) {
			$map[ $this->key( ...[ ...$parts, $bucket ] ) ] = $bucket;
		}
		// No table (no backend) reads as empty, like a miss.
		$out = [];
		foreach ( $this->table( self::ROLE_AGGREGATE )?->lookup_multi( \array_keys( $map ) ) ?? [] as $key => $value ) {
			if ( \is_array( $value ) && isset( $map[ $key ] ) ) {
				$out[ $map[ $key ] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Explicit bucket setter (FlameBuilder's full-bucket overwrite path).
	 *
	 * @param string               $bucket Bucket key.
	 * @param array<string,mixed> $data   Whole bucket, replacing what is stored.
	 * @return bool True when the set landed.
	 */
	public function set_url_index_hourly( string $bucket, array $data ): bool {
		return $this->store( $this->key( self::NS_URLS, $bucket ), $data, $this->ttl(), self::NS_URLS );
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
		$parts = self::lb_parts( $server );
		return $this->store( $this->key( ...[ ...$parts, $bucket ] ), $data, $this->ttl(), $parts[0] );
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
	 * @param string               $dimension Dimension name.
	 * @param array<string,mixed> $data      Series keyed by bucket.
	 * @param string               $server    Reporting server; '' writes the global series.
	 * @return bool True when the set landed.
	 */
	public function set_dimensional( string $dimension, array $data, string $server = '' ): bool {
		$parts = [ self::NS_DIM, $dimension ];
		if ( '' !== $server ) {
			$parts[] = self::server_key( $server );
		}
		return $this->store( $this->key( ...$parts ), $data, $this->ttl(), self::NS_DIM );
	}

	/**
	 * Overwrite one URL's dimensional series, every dimension in one value.
	 *
	 * @param string               $url_hash 12-char URL hash.
	 * @param array<string,mixed> $data     Series keyed by dimension.
	 * @return bool True when the set landed.
	 */
	public function set_url_dimensional( string $url_hash, array $data ): bool {
		return $this->store( $this->key( self::NS_URL_DIM, $url_hash ), $data, $this->ttl(), self::NS_URL_DIM );
	}

	/**
	 * Overwrite the global category series.
	 *
	 * @param array<string,mixed> $data Series keyed by bucket.
	 * @return bool True when the set landed.
	 */
	public function set_categories( array $data ): bool {
		return $this->store( $this->key( self::NS_CATEGORIES ), $data, $this->ttl(), self::NS_CATEGORIES );
	}

	/**
	 * Read one server's category series. Same shape as `get_categories()`; the
	 * server token extends the `categories` key rather than opening a namespace.
	 *
	 * @param string $server Server name; hashed into the key.
	 * @return array<string,mixed> Series by bucket, [] on miss.
	 */
	public function get_server_categories( string $server ): array {
		$val = $this->lookup( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Coerce a memcache get() result (mixed) to a string-keyed map, [] on miss.
	 *
	 * Every namespace stores a string-keyed map; re-key with (string) casts so
	 * the static type is array<string,mixed> (is_array alone leaves keys mixed).
	 *
	 * @param mixed $val
	 * @return array<string,mixed>
	 */
	private static function map_or_empty( $val ): array {
		if ( ! \is_array( $val ) ) {
			return [];
		}
		$out = [];
		foreach ( $val as $k => $v ) {
			$out[ (string) $k ] = $v;
		}
		return $out;
	}

	/** Read one key through the table; null (no backend) and miss both read empty. */
	private function lookup( string $key ): mixed {
		return $this->table( self::ROLE_AGGREGATE )?->lookup( $key );
	}

	/**
	 * Overwrite one server's category series.
	 *
	 * @param string               $server Server name; hashed into the key.
	 * @param array<string,mixed> $data   Series keyed by bucket.
	 * @return bool True when the set landed.
	 */
	public function set_server_categories( string $server, array $data ): bool {
		return $this->store( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ), $data, $this->ttl(), self::NS_CATEGORIES );
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
		$hash = 2166136261;
		$len  = \strlen( $server );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash ^= \ord( $server[ $i ] );
			$hash  = ( $hash * 16777619 ) & 0xFFFFFFFF;
		}
		return \sprintf( '%08x', $hash );
	}

	/**
	 * Overwrite one URL's category series.
	 *
	 * @param string               $url_hash 12-char URL hash.
	 * @param array<string,mixed> $data     Series keyed by bucket.
	 * @return bool True when the set landed.
	 */
	public function set_url_categories( string $url_hash, array $data ): bool {
		return $this->store( $this->key( self::NS_URL_CAT, $url_hash ), $data, $this->ttl(), self::NS_URL_CAT );
	}

	/**
	 * Write to memcache, then (if wired AND the set landed) shadow the same write
	 * to the mirror seam — a rejected/failed set must not be durably recorded and
	 * resurrected by a later read-back.
	 *
	 * @param string               $key  Full memcache key.
	 * @param array<string,mixed> $data Value to store.
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
	 * Flame_Builder folds every request into this and drains it through
	 * `set_url_stats()` on its own cadence, so the tier holds state that is not
	 * yet stored — which is why the Table's accumulator, and not a read cache,
	 * is what it wants.
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
	 * @longform Stats live in memcache alone and two installs share one server
	 * on Atomic, so the bare `evlog:p0:hourly` was the same key for both — a
	 * co-tenant's request volume landing in this install's dashboard.
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
		return \array_merge(
			$a,
			[
				'count'       => Core::num_int( $a['count'] ?? null ) + Core::num_int( $b['count'] ?? null ),
				'sum_ms'      => Core::num_float( $a['sum_ms'] ?? null ) + Core::num_float( $b['sum_ms'] ?? null ),
				'sum_peak_mb' => Core::num_float( $a['sum_peak_mb'] ?? null ) + Core::num_float( $b['sum_peak_mb'] ?? null ),
			]
		);
	}

	/** Partition this store reads and writes. */
	public function partition(): int {
		return $this->partition;
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
		$dst['count']        = Core::num_int( $dst['count'] ?? null ) + Core::num_int( $src['count'] ?? null );
		$dst['sum_req_time'] = Core::num_float( $dst['sum_req_time'] ?? null ) + Core::num_float( $src['sum_req_time'] ?? null );
		if ( ! isset( $dst['categories'] ) || ! \is_array( $dst['categories'] ) ) {
			$dst['categories'] = [];
		}
		$src_cats = ( isset( $src['categories'] ) && \is_array( $src['categories'] ) ) ? $src['categories'] : [];
		foreach ( $src_cats as $cat => $data ) {
			$data = Core::arr( $data );
			if ( ! isset( $dst['categories'][ $cat ] ) ) {
				$dst['categories'][ $cat ] = [
					'samples'   => 0,
					'sum_time'  => 0.0,
					'sum_count' => 0.0,
					'entries'   => [],
				];
			}
			/** @var array{samples:int, sum_time:float, sum_count:float, entries:array<array-key,mixed>} $c */
			$c               = &$dst['categories'][ $cat ];
			$c['samples']   += Core::num_int( $data['samples'] ?? null );
			$c['sum_time']  += Core::num_float( $data['sum_time'] ?? null );
			$c['sum_count'] += Core::num_float( $data['sum_count'] ?? null );
			$entries         = ( isset( $data['entries'] ) && \is_array( $data['entries'] ) ) ? $data['entries'] : [];
			foreach ( $entries as $name => $entry ) {
				$entry = Core::arr( $entry );
				if ( ! isset( $c['entries'][ $name ] ) ) {
					$c['entries'][ $name ] = [ 0.0, 0.0, 0 ];
				}
				/** @var array{0:float, 1:float, 2:int} $dst_entry */
				$dst_entry      = &$c['entries'][ $name ];
				$dst_entry[0]  += Core::num_float( $entry[0] ?? null );
				$dst_entry[1]  += Core::num_float( $entry[1] ?? null );
				$dst_entry[2]  += Core::num_int( $entry[2] ?? null );
				unset( $dst_entry );
			}
			unset( $c );
		}
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
