<?php
/**
 * Stats Store
 *
 * The memcache schema for performance stats, expressed as one small key/value
 * API. Nine namespaces (`hourly`, `lb`, `lb_s`, `urls`, `url`, `dim`,
 * `url_dim`, `categories`, `url_cat`) live under the per-partition prefix
 * `evlog[:salt]:p{N}:`. `Flame_Builder_Node` produces every value;
 * `App\Performance_CI_Node` and the admin flush button consume them.
 *
 * Stats live in memcache alone — the only durable state this file writes is the
 * salt option that prefixes the keys.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats storage using memcache.
 *
 * Keys are `evlog[:salt]:p{N}:{namespace}[:...]`, so every flame-builder
 * partition owns a disjoint keyspace and readers fan one store out per
 * partition. Values are plain arrays, always keyed by string.
 *
 * Retention: the per-URL blob (`url`) is the high-volume namespace and expires
 * at `ttl_url_stats()` — a twenty-fourth of the lifespan, floored at an hour.
 * Every other namespace expires at `ttl()`.
 *
 * Bucketing belongs to the producer; this class stores whatever key it is
 * handed. `Flame_Builder_Node` buckets everything — `hourly` included — in
 * five-minute keys of the form `Y-m-d-H-ii`, so the `hourly` namespace and the
 * `get_url_index_hourly()` name are historical rather than descriptive.
 *
 * Reads and writes fail soft: `Core::$memd` is reached through `?->`, so a
 * missing handle yields `[]`, `null`, or `false` and the dashboards render "no
 * data" instead of an error. Keep it that way — the SSE slot pool is
 * deliberately the opposite, and unifying the two breaks its rate limit.
 *
 * The prefix is computed once, in the constructor. A `flush_all()` salt
 * rotation therefore reaches a long-running worker only when it restarts.
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

	/** Key prefix ahead of the salt. */
	private const PREFIX_BASE  = 'evlog';
	/** Floor, in seconds, under both `ttl()` and `ttl_url_stats()`. */
	private const PREFIX_FLOOR = 3600;
	/** Option holding the rotatable salt; rotating it orphans every key. */
	private const SALT_OPTION  = 'newspack_event_logger_nodes_stats_salt';

	/**
	 * Mirror seam — when set, invoked `(string $key, array $data, int $ttl, string $ns)`
	 * after each memcache write that landed, so a durable partition can shadow
	 * stats for cold-boot replay. The namespace lets the mirror route aggregates
	 * vs. the bounded per-URL namespaces. Null (default) = zero overhead.
	 * `Flame_Builder_Node::arm_stats_mirror()` is the only wiring. Signature:
	 * `function(string $key, array $data, int $ttl, string $ns): void`.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $mirror = null;
	/** @var int Retention window in seconds, floored at PREFIX_FLOOR. */
	private int $max_lifespan;

	/** @var int Flame-builder partition whose keyspace this store owns. */
	private int $partition;
	/** @var string Key prefix, frozen at construction. */
	private string $prefix;

	/**
	 * @param int $partition    Flame-builder partition to read and write.
	 * @param int $max_lifespan Retention window in seconds, seeded from the
	 *                          substrate `min_lifetime` config key.
	 */
	public function __construct(
		int $partition = 0,
		int $max_lifespan = 86400
	) {
		$this->partition    = $partition;
		$this->max_lifespan = \max( self::PREFIX_FLOOR, $max_lifespan );
		$this->prefix       = $this->compute_prefix();
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
		$val = Core::$memd?->get( $this->key( self::NS_URLS, $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read the partition's request totals: `{ bucket => { count, sum_ms,
	 * sum_peak_mb } }`. One key holds the whole retention window, so a dashboard
	 * gets every bucket in a single round-trip.
	 *
	 * @return array<string,mixed> Totals by bucket, [] on miss.
	 */
	public function get_hourly(): array {
		$val = Core::$memd?->get( $this->key( self::NS_HOURLY ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one global leaderboard bucket: `{ count, sum_req_time, categories: {
	 * cat => { samples, sum_time, sum_count, entries } } }`. Sums, never means —
	 * `sums_to_display()` divides at read time so cross-bucket and
	 * cross-partition merges stay exact addition.
	 *
	 * @param string $bucket Bucket key.
	 * @return array<string,mixed> Bucket sums, [] on miss.
	 */
	public function get_leaderboard_bucket( string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_LB, $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one leaderboard bucket for a single reporting server. Same shape as
	 * `get_leaderboard_bucket()`.
	 *
	 * @param string $server Server name; hashed into the key.
	 * @param string $bucket Bucket key.
	 * @return array<string,mixed> Bucket sums, [] on miss.
	 */
	public function get_server_leaderboard_bucket( string $server, string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ) );
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
		$val = Core::$memd?->get( $this->key( ...$parts ) );
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
		$val = Core::$memd?->get( $this->key( self::NS_URL_DIM, $url_hash ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read the global category series: `{ bucket => { cat => { t, c, n } } }` —
	 * summed time in ms, summed invocation count, and requests sampled.
	 *
	 * @return array<string,mixed> Series by bucket, [] on miss.
	 */
	public function get_categories(): array {
		$val = Core::$memd?->get( $this->key( self::NS_CATEGORIES ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Read one URL's category series. Same shape as `get_categories()`.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array<string,mixed> Series by bucket, [] on miss.
	 */
	public function get_url_categories( string $url_hash ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URL_CAT, $url_hash ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Overwrite the partition's request totals.
	 *
	 * @param array<string,mixed> $data Totals keyed by bucket.
	 * @return bool True when the set landed.
	 */
	public function set_hourly( array $data ): bool {
		return $this->store( $this->key( self::NS_HOURLY ), $data, $this->ttl(), self::NS_HOURLY );
	}

	/**
	 * Read many `urls` buckets in one round-trip. Per-key gets across a retention
	 * window are a latency cliff on the dashboards; this is the path they use.
	 *
	 * @param array<int,string> $buckets Bucket keys.
	 * @return array<string,mixed> Bucket contents keyed by bucket; misses absent.
	 */
	public function get_url_buckets( array $buckets ): array {
		if ( empty( $buckets ) ) {
			return [];
		}
		$keys = [];
		$map  = [];
		foreach ( $buckets as $b ) {
			$k         = $this->key( self::NS_URLS, $b );
			$keys[]    = $k;
			$map[ $k ] = $b;
		}
		// `?->` null (no handle); getMulti false on miss — both → [].
		$results = Core::$memd?->getMulti( $keys ) ?: [];
		$out     = [];
		foreach ( $results as $k => $v ) {
			if ( \is_array( $v ) && isset( $map[ $k ] ) ) {
				$out[ $map[ $k ] ] = $v;
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
		$val = Core::$memd?->get( $this->key( self::NS_URL, $url_hash ) );
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

	/** Retention for the high-volume `url` namespace: a day's worth cut to a 24th, floored at an hour. */
	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
	}

	/**
	 * Overwrite one global leaderboard bucket.
	 *
	 * @param string               $bucket Bucket key.
	 * @param array<string,mixed> $data   Merged bucket sums.
	 * @return bool True when the set landed.
	 */
	public function set_leaderboard_bucket( string $bucket, array $data ): bool {
		return $this->store( $this->key( self::NS_LB, $bucket ), $data, $this->ttl(), self::NS_LB );
	}

	/**
	 * Overwrite one leaderboard bucket for a single reporting server.
	 *
	 * @param string               $server Server name; hashed into the key.
	 * @param string               $bucket Bucket key.
	 * @param array<string,mixed> $data   Merged bucket sums.
	 * @return bool True when the set landed.
	 */
	public function set_server_leaderboard_bucket( string $server, string $bucket, array $data ): bool {
		return $this->store( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ), $data, $this->ttl(), self::NS_LB_S );
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
		$val = Core::$memd?->get( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ) );
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

	/** Retention window, in seconds, for every namespace but `url`. */
	public function ttl(): int {
		return $this->max_lifespan;
	}

	/**
	 * Join a memcache key from the prefix, the partition, and the caller's parts.
	 *
	 * @param string ...$parts Namespace token first, then any sub-keys.
	 * @return string Full key.
	 */
	private function key( string ...$parts ): string {
		\array_unshift( $parts, $this->prefix, 'p' . $this->partition );
		return \implode( ':', $parts );
	}

	/**
	 * Write to memcache, then (if wired AND the set landed) shadow the same write
	 * to the mirror seam — a rejected/failed set must not be durably recorded and
	 * resurrected on cold boot.
	 *
	 * @param string               $key  Full memcache key.
	 * @param array<string,mixed> $data Value to store.
	 * @param int                  $ttl  Expiry in seconds.
	 * @param string               $ns   Namespace routing hint for the mirror.
	 * @return bool True when the set landed.
	 */
	private function store( string $key, array $data, int $ttl, string $ns ): bool {
		$ok = (bool) Core::$memd?->set( $key, $data, $ttl );
		if ( $ok && null !== $this->mirror ) {
			( $this->mirror )( $key, $data, $ttl, $ns );
		}
		return $ok;
	}

	/**
	 * Replay a mirrored write straight into memcache under a (decayed) TTL. Guards
	 * ttl>0 and the current prefix (a rotated salt orphans the mirror, like it
	 * orphans memcache).
	 *
	 * The write bypasses the mirror seam: this restores what the mirror already
	 * holds, and re-shadowing it would append a duplicate frame.
	 *
	 * @param string               $key  Full memcache key, prefix included.
	 * @param array<string,mixed> $data Mirrored value.
	 * @param int                  $ttl  Remaining seconds; <= 0 is refused.
	 * @return bool True when the set landed.
	 */
	public function restore( string $key, array $data, int $ttl ): bool {
		if ( $ttl <= 0 || ! \str_starts_with( $key, $this->prefix . ':' ) ) {
			return false;
		}
		return (bool) Core::$memd?->set( $key, $data, $ttl );
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

	/**
	 * Rotate the salt, orphaning every existing key at once — the schema migration
	 * and the emergency flush share this one mechanism. Nothing is deleted; the
	 * orphans age out on their own TTLs.
	 *
	 * This store picks up the new prefix immediately, but a worker that built its
	 * store earlier keeps writing the old one until it restarts.
	 *
	 * @return bool Always true; the rotation cannot fail short of a fatal.
	 */
	public function flush_all(): bool {
		$salt = \bin2hex( \random_bytes( 4 ) );
		if ( \function_exists( 'update_option' ) ) {
			\update_option( self::SALT_OPTION, $salt );
		}
		$this->prefix = self::PREFIX_BASE . ':' . $salt;
		return true;
	}

	/**
	 * Build the key prefix from the current salt. Outside WordPress — CLI and
	 * unit tests, where `get_option` is absent — the prefix stays unsalted, which
	 * keeps every such caller on one keyspace.
	 */
	private function compute_prefix(): string {
		$salt = '';
		if ( \function_exists( 'get_option' ) ) {
			$opt  = \get_option( self::SALT_OPTION, '' );
			$salt = Core::as_string( $opt );
		}
		return '' === $salt ? self::PREFIX_BASE : self::PREFIX_BASE . ':' . $salt;
	}
}
