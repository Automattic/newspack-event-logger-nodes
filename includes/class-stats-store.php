<?php
/**
 * Stats Store
 *
 * Memcache-based storage for performance stats. Uses a 9-namespace schema
 * (hourly, lb, lb_s, urls, url, dim, url_dim, categories, url_cat) keyed by
 * `evlog[:salt]:p{N}:{namespace}:...`.
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
 */
class Stats_Store {

	public const MAX_CAT_VALUES           = 50;
	public const MAX_DIM_VALUES           = 20;
	public const MAX_DURATIONS_PER_BUCKET = 100;
	public const MAX_URL_DIM_VALUES       = 10;
	public const NS_CATEGORIES  = 'categories';
	public const NS_DIM         = 'dim';

	public const NS_HOURLY      = 'hourly';
	public const NS_LB          = 'lb';
	public const NS_LB_S        = 'lb_s';
	public const NS_URL         = 'url';
	public const NS_URLS        = 'urls';
	public const NS_URL_CAT     = 'url_cat';
	public const NS_URL_DIM     = 'url_dim';

	private const PREFIX_BASE  = 'evlog';
	private const PREFIX_FLOOR = 3600;
	private const SALT_OPTION  = 'newspack_event_logger_nodes_stats_salt';

	/**
	 * Mirror seam — when set, invoked `(string $key, array $data, int $ttl, string $ns)`
	 * AFTER each memcache write so a durable partition can shadow stats for
	 * cold-boot replay. The namespace lets the mirror route aggregates vs. the
	 * bounded per-URL namespaces. Null (default) = zero overhead. Signature:
	 * `function(string $key, array $data, int $ttl, string $ns): void`.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $mirror = null;
	private int $max_lifespan;

	private int $partition;
	private string $prefix;

	public function __construct(
		int $partition = 0,
		int $max_lifespan = 86400
	) {
		$this->partition    = $partition;
		$this->max_lifespan = \max( self::PREFIX_FLOOR, $max_lifespan );
		$this->prefix       = $this->compute_prefix();
	}

	private function compute_prefix(): string {
		$salt = '';
		if ( \function_exists( 'get_option' ) ) {
			$opt  = \get_option( self::SALT_OPTION, '' );
			$salt = Core::as_string( $opt );
		}
		return '' === $salt ? self::PREFIX_BASE : self::PREFIX_BASE . ':' . $salt;
	}

	/**
	 * Alias of `get_url_bucket` matching upstream naming.
	 *
	 * @return array<string, mixed>
	 */
	public function get_url_index_hourly( string $bucket ): array {
		return $this->get_url_bucket( $bucket );
	}

	// URL index: { bucket => { url => {count, sum_req_time, samples} } }.

	/**
	 * @return array<string, mixed>
	 */
	public function get_url_bucket( string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URLS, $bucket ) );
		return self::map_or_empty( $val );
	}

	private function key( string ...$parts ): string {
		\array_unshift( $parts, $this->prefix, 'p' . $this->partition );
		return \implode( ':', $parts );
	}

	/**
	 * Coerce a memcache get() result (mixed) to a string-keyed map, [] on miss.
	 *
	 * Every namespace stores a string-keyed map; re-key with (string) casts so
	 * the static type is array<string, mixed> (is_array alone leaves keys mixed).
	 *
	 * @param mixed $val
	 * @return array<string, mixed>
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

	// Hourly: { Y-m-d-H => {count, sum_ms, sum_peak_mb} } (one key/partition).

	/**
	 * @return array<string, mixed>
	 */
	public function get_hourly(): array {
		$val = Core::$memd?->get( $this->key( self::NS_HOURLY ) );
		return self::map_or_empty( $val );
	}

	// Leaderboard: 5-min buckets, sums + per-category sums.

	/**
	 * @return array<string, mixed>
	 */
	public function get_leaderboard_bucket( string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_LB, $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_server_leaderboard_bucket( string $server, string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Hash a server name to a key-safe ASCII token (FNV-1a 32-bit hex).
	 * Used for `lb_s` / `dim:_:srv` keys so server names don't break colons.
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

	// Dimensional: { bucket => { value => {c, s, m} } } per dimension.

	/**
	 * @return array<string, mixed>
	 */
	public function get_dimensional( string $dimension, string $server = '' ): array {
		$parts = [ self::NS_DIM, $dimension ];
		if ( '' !== $server ) {
			$parts[] = self::server_key( $server );
		}
		$val = Core::$memd?->get( $this->key( ...$parts ) );
		return self::map_or_empty( $val );
	}

	// Per-URL dimensional: { dim => { bucket => { value => {c, s, m} } } }.

	/**
	 * @return array<string, mixed>
	 */
	public function get_url_dimensional( string $url_hash ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URL_DIM, $url_hash ) );
		return self::map_or_empty( $val );
	}

	// Categories (global): per-bucket category sums (keys t, c, n).

	/**
	 * @return array<string, mixed>
	 */
	public function get_categories(): array {
		$val = Core::$memd?->get( $this->key( self::NS_CATEGORIES ) );
		return self::map_or_empty( $val );
	}

	// Per-URL categories: { bucket => { cat => {t, c, n} } } per url_hash.

	/**
	 * @return array<string, mixed>
	 */
	public function get_url_categories( string $url_hash ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URL_CAT, $url_hash ) );
		return self::map_or_empty( $val );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_hourly( array $data ): bool {
		return $this->store( $this->key( self::NS_HOURLY ), $data, $this->ttl(), self::NS_HOURLY );
	}

	/**
	 * Write to memcache, then (if wired AND the set landed) shadow the same write
	 * to the mirror seam — a rejected/failed set must not be durably recorded and
	 * resurrected on cold boot.
	 *
	 * @param array<string, mixed> $data
	 * @param string               $ns   Namespace routing hint for the mirror.
	 */
	private function store( string $key, array $data, int $ttl, string $ns ): bool {
		$ok = (bool) Core::$memd?->set( $key, $data, $ttl );
		if ( $ok && null !== $this->mirror ) {
			( $this->mirror )( $key, $data, $ttl, $ns );
		}
		return $ok;
	}

	public function ttl(): int {
		return $this->max_lifespan;
	}

	/**
	 * @param array<int, string> $buckets
	 * @return array<string, mixed>
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
	 * @param array<string, mixed> $data
	 */
	public function set_url_index_hourly( string $bucket, array $data ): bool {
		return $this->store( $this->key( self::NS_URLS, $bucket ), $data, $this->ttl(), self::NS_URLS );
	}

	// Per-URL stats blob (flame, profiles, ...); shorter TTL (high volume).

	/**
	 * @return array<array-key, mixed>|null
	 */
	public function get_url_stats( string $url_hash ): ?array {
		$val = Core::$memd?->get( $this->key( self::NS_URL, $url_hash ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_stats( string $url_hash, array $data ): bool {
		return $this->store( $this->key( self::NS_URL, $url_hash ), $data, $this->ttl_url_stats(), self::NS_URL );
	}

	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_leaderboard_bucket( string $bucket, array $data ): bool {
		return $this->store( $this->key( self::NS_LB, $bucket ), $data, $this->ttl(), self::NS_LB );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_server_leaderboard_bucket( string $server, string $bucket, array $data ): bool {
		return $this->store( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ), $data, $this->ttl(), self::NS_LB_S );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_dimensional( string $dimension, array $data, string $server = '' ): bool {
		$parts = [ self::NS_DIM, $dimension ];
		if ( '' !== $server ) {
			$parts[] = self::server_key( $server );
		}
		return $this->store( $this->key( ...$parts ), $data, $this->ttl(), self::NS_DIM );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_dimensional( string $url_hash, array $data ): bool {
		return $this->store( $this->key( self::NS_URL_DIM, $url_hash ), $data, $this->ttl(), self::NS_URL_DIM );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_categories( array $data ): bool {
		return $this->store( $this->key( self::NS_CATEGORIES ), $data, $this->ttl(), self::NS_CATEGORIES );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_server_categories( string $server ): array {
		$val = Core::$memd?->get( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ) );
		return self::map_or_empty( $val );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_server_categories( string $server, array $data ): bool {
		return $this->store( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ), $data, $this->ttl(), self::NS_CATEGORIES );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_categories( string $url_hash, array $data ): bool {
		return $this->store( $this->key( self::NS_URL_CAT, $url_hash ), $data, $this->ttl(), self::NS_URL_CAT );
	}

	/**
	 * Replay a mirrored write straight into memcache under a (decayed) TTL. Guards
	 * ttl>0 and the current prefix (a rotated salt orphans the mirror, like it
	 * orphans memcache).
	 *
	 * @param array<string, mixed> $data
	 */
	public function restore( string $key, array $data, int $ttl ): bool {
		if ( $ttl <= 0 || ! \str_starts_with( $key, $this->prefix . ':' ) ) {
			return false;
		}
		return (bool) Core::$memd?->set( $key, $data, $ttl );
	}

	public function partition(): int {
		return $this->partition;
	}

	/**
	 * Additive merge of one leaderboard bucket's sums into another (modifying $dst).
	 *
	 * Used by FlameBuilder at persist time to combine the current flush's bucket
	 * with the already-persisted bucket of the same key. Static so callers can
	 * use it without an instance.
	 * @param array<string, mixed> $dst
	 * @param array<string, mixed> $src
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
			/** @var array{samples:int, sum_time:float, sum_count:float, entries:array<array-key, mixed>} $c */
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

	// Sums-to-display helper (dashboards render bucket-merged data).

	/**
	 * Convert summed leaderboard data to the display shape expected by the frontend.
	 *
	 *  - 'time'    = sum_time  / total_count — avg exclusive cat time per request.
	 *  - 'count'   = sum_count / total_count — avg invocation count per request.
	 *  - entries   are per-appearance averages (sum / samples).
	 *
	 * @param int                   $total_count  Total profiled requests.
	 * @param float                 $sum_req_time Sum of per-request $req_time values.
	 * @param array<string, mixed>  $sums         Per-category sums keyed by category name.
	 * @return array<string, mixed> Display-shaped leaderboard data.
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

	// Schema migration: salt rotation.

	public function flush_all(): bool {
		$salt = \bin2hex( \random_bytes( 4 ) );
		if ( \function_exists( 'update_option' ) ) {
			\update_option( self::SALT_OPTION, $salt );
		}
		$this->prefix = self::PREFIX_BASE . ':' . $salt;
		return true;
	}
}
