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

	public const NS_HOURLY      = 'hourly';
	public const NS_LB          = 'lb';
	public const NS_LB_S        = 'lb_s';
	public const NS_URLS        = 'urls';
	public const NS_URL         = 'url';
	public const NS_DIM         = 'dim';
	public const NS_URL_DIM     = 'url_dim';
	public const NS_CATEGORIES  = 'categories';
	public const NS_URL_CAT     = 'url_cat';

	public const MAX_CAT_VALUES           = 50;
	public const MAX_DIM_VALUES           = 20;
	public const MAX_URL_DIM_VALUES       = 10;
	public const MAX_DURATIONS_PER_BUCKET = 100;

	private const PREFIX_BASE  = 'evlog';
	private const SALT_OPTION  = 'newspack_event_logger_nodes_stats_salt';
	private const PREFIX_FLOOR = 3600;

	private int $partition;
	private int $max_lifespan;
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
			$salt = \is_scalar( $opt ) ? (string) $opt : '';
		}
		return '' === $salt ? self::PREFIX_BASE : self::PREFIX_BASE . ':' . $salt;
	}

	// -------------------------------------------------------------------------
	// Hourly: { Y-m-d-H => {count, sum_ms, sum_peak_mb} }
	// Single key per partition holds the rolling map.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function get_hourly(): array {
		$val = Core::$memd?->get( $this->key( self::NS_HOURLY ) );
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

	public function ttl(): int {
		return $this->max_lifespan;
	}

	// -------------------------------------------------------------------------
	// URL index: { bucket => { url => {count, sum_req_time, samples} } }
	// Plus an explicit bucket-keyed setter for FlameBuilder's full-bucket merge.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function get_url_bucket( string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URLS, $bucket ) );
		return self::map_or_empty( $val );
	}

	/**
	 * Alias of `get_url_bucket` matching upstream naming.
	 *
	 * @return array<string, mixed>
	 */
	public function get_url_index_hourly( string $bucket ): array {
		return $this->get_url_bucket( $bucket );
	}

	// -------------------------------------------------------------------------
	// Leaderboard: 5-min buckets, sums + per-category sums.
	// -------------------------------------------------------------------------

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

	// -------------------------------------------------------------------------
	// Dimensional: { bucket => { value => {c, s, m} } } per dimension.
	// One key per (partition, dimension) for the global, plus
	// (partition, dimension, server) for the per-server variant.
	// -------------------------------------------------------------------------

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

	// -------------------------------------------------------------------------
	// Per-URL dimensional: { dim => { bucket => { value => {c, s, m} } } }
	// One key per (partition, url_hash). Cap is tighter (10) since per-URL
	// fan-out is multiplicative across dimensions × buckets.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function get_url_dimensional( string $url_hash ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URL_DIM, $url_hash ) );
		return self::map_or_empty( $val );
	}

	// -------------------------------------------------------------------------
	// Categories (global): { bucket => { cat => {t, c, n} } }
	// Plus per-server variants keyed by server hash.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function get_categories(): array {
		$val = Core::$memd?->get( $this->key( self::NS_CATEGORIES ) );
		return self::map_or_empty( $val );
	}

	// -------------------------------------------------------------------------
	// Per-URL categories: { bucket => { cat => {t, c, n} } } per url_hash.
	// -------------------------------------------------------------------------

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
		return (bool) Core::$memd?->set( $this->key( self::NS_HOURLY ), $data, $this->ttl() );
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
		// `?->` yields null when no handle; getMulti yields false on miss — both → [].
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
		return (bool) Core::$memd?->set( $this->key( self::NS_URLS, $bucket ), $data, $this->ttl() );
	}

	// -------------------------------------------------------------------------
	// Per-URL stats blob (flame, profiles, ...). Shorter TTL since per-URL
	// volume can be high.
	// -------------------------------------------------------------------------

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
		return (bool) Core::$memd?->set( $this->key( self::NS_URL, $url_hash ), $data, $this->ttl_url_stats() );
	}

	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_leaderboard_bucket( string $bucket, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_LB, $bucket ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_server_leaderboard_bucket( string $server, string $bucket, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_dimensional( string $dimension, array $data, string $server = '' ): bool {
		$parts = [ self::NS_DIM, $dimension ];
		if ( '' !== $server ) {
			$parts[] = self::server_key( $server );
		}
		return (bool) Core::$memd?->set( $this->key( ...$parts ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_dimensional( string $url_hash, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_URL_DIM, $url_hash ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_categories( array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_CATEGORIES ), $data, $this->ttl() );
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
		return (bool) Core::$memd?->set( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_categories( string $url_hash, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_URL_CAT, $url_hash ), $data, $this->ttl() );
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
		$dst['count']        = (int) ( \is_numeric( $dst['count'] ?? null ) ? $dst['count'] : 0 ) + (int) ( \is_numeric( $src['count'] ?? null ) ? $src['count'] : 0 );
		$dst['sum_req_time'] = (float) ( \is_numeric( $dst['sum_req_time'] ?? null ) ? $dst['sum_req_time'] : 0 ) + (float) ( \is_numeric( $src['sum_req_time'] ?? null ) ? $src['sum_req_time'] : 0 );
		if ( ! isset( $dst['categories'] ) || ! \is_array( $dst['categories'] ) ) {
			$dst['categories'] = [];
		}
		$src_cats = ( isset( $src['categories'] ) && \is_array( $src['categories'] ) ) ? $src['categories'] : [];
		foreach ( $src_cats as $cat => $data ) {
			$data = \is_array( $data ) ? $data : [];
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
			$c['samples']   += (int) ( \is_numeric( $data['samples'] ?? null ) ? $data['samples'] : 0 );
			$c['sum_time']  += (float) ( \is_numeric( $data['sum_time'] ?? null ) ? $data['sum_time'] : 0 );
			$c['sum_count'] += (float) ( \is_numeric( $data['sum_count'] ?? null ) ? $data['sum_count'] : 0 );
			$entries         = ( isset( $data['entries'] ) && \is_array( $data['entries'] ) ) ? $data['entries'] : [];
			foreach ( $entries as $name => $entry ) {
				$entry = \is_array( $entry ) ? $entry : [];
				if ( ! isset( $c['entries'][ $name ] ) ) {
					$c['entries'][ $name ] = [ 0.0, 0.0, 0 ];
				}
				/** @var array{0:float, 1:float, 2:int} $dst_entry */
				$dst_entry      = &$c['entries'][ $name ];
				$dst_entry[0]  += (float) ( \is_numeric( $entry[0] ?? null ) ? $entry[0] : 0 );
				$dst_entry[1]  += (float) ( \is_numeric( $entry[1] ?? null ) ? $entry[1] : 0 );
				$dst_entry[2]  += (int) ( \is_numeric( $entry[2] ?? null ) ? $entry[2] : 0 );
				unset( $dst_entry );
			}
			unset( $c );
		}
	}

	// -------------------------------------------------------------------------
	// Sums-to-display helper (used by dashboards to render bucket-merged data).
	// -------------------------------------------------------------------------

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
			$data      = \is_array( $data ) ? $data : [];
			$samples   = (int) ( \is_numeric( $data['samples'] ?? null ) ? $data['samples'] : 0 );
			$sum_time  = (float) ( \is_numeric( $data['sum_time'] ?? null ) ? $data['sum_time'] : 0 );
			$sum_count = (float) ( \is_numeric( $data['sum_count'] ?? null ) ? $data['sum_count'] : 0 );

			$entries_out = [];
			$entries     = ( isset( $data['entries'] ) && \is_array( $data['entries'] ) ) ? $data['entries'] : [];
			foreach ( $entries as $name => $entry ) {
				$entry     = \is_array( $entry ) ? $entry : [];
				$e2        = $entry[2] ?? null;
				$e_samples = (int) ( \is_numeric( $e2 ) ? $e2 : 0 );
				if ( $e_samples > 0 ) {
					$e0 = $entry[0] ?? null;
					$e1 = $entry[1] ?? null;
					$entries_out[ $name ] = [
						( \is_numeric( $e0 ) ? $e0 : 0 ) / $e_samples,
						( \is_numeric( $e1 ) ? $e1 : 0 ) / $e_samples,
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

	// -------------------------------------------------------------------------
	// Schema migration: salt rotation.
	// -------------------------------------------------------------------------

	public function flush_all(): bool {
		$salt = \bin2hex( \random_bytes( 4 ) );
		if ( \function_exists( 'update_option' ) ) {
			\update_option( self::SALT_OPTION, $salt );
		}
		$this->prefix = self::PREFIX_BASE . ':' . $salt;
		return true;
	}
}
