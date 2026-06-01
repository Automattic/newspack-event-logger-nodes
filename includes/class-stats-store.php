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
			$salt = (string) \get_option( self::SALT_OPTION, '' );
		}
		return '' === $salt ? self::PREFIX_BASE : self::PREFIX_BASE . ':' . $salt;
	}

	public function ttl(): int {
		return $this->max_lifespan;
	}

	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
	}

	public function partition(): int {
		return $this->partition;
	}

	// -------------------------------------------------------------------------
	// Bucket key helpers — gmdate so cross-server/timezone consistency.
	// -------------------------------------------------------------------------

	public function current_url_bucket(): string {
		$now    = \time();
		$min    = (int) \gmdate( 'i', $now );
		$bucket = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $now ) . '-' . $bucket;
	}

	public function current_hour_bucket(): string {
		return \gmdate( 'Y-m-d-H', \time() );
	}

	/**
	 * 5-min bucket key for a given timestamp. Used by FlameBuilder to align
	 * its in-memory bucket rotation with the memcache key space.
	 */
	public function bucket_key_for( int $timestamp ): string {
		$min        = (int) \gmdate( 'i', $timestamp );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $timestamp ) . '-' . $bucket_min;
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

	private function key( string ...$parts ): string {
		\array_unshift( $parts, $this->prefix, 'p' . $this->partition );
		return \implode( ':', $parts );
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
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_hourly( array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_HOURLY ), $data, $this->ttl() );
	}

	public function bump_hourly( float $req_time_secs, float $peak_mb ): void {
		$cur                                = $this->get_hourly();
		$bucket                             = $this->current_hour_bucket();
		$cur[ $bucket ]                     ??= [ 'count' => 0, 'sum_ms' => 0.0, 'sum_peak_mb' => 0.0 ];
		++$cur[ $bucket ]['count'];
		$cur[ $bucket ]['sum_ms']      += $req_time_secs * 1000.0;
		$cur[ $bucket ]['sum_peak_mb'] += $peak_mb;
		$this->prune_old_hourly( $cur );
		Core::$memd?->set( $this->key( self::NS_HOURLY ), $cur, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $cur
	 */
	private function prune_old_hourly( array &$cur ): void {
		$cutoff   = \time() - $this->max_lifespan;
		$min_hour = \gmdate( 'Y-m-d-H', $cutoff );
		foreach ( \array_keys( $cur ) as $h ) {
			if ( $h < $min_hour ) {
				unset( $cur[ $h ] );
			}
		}
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
		return \is_array( $val ) ? $val : [];
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

	public function bump_url( string $url, float $req_time ): void {
		$bucket = $this->current_url_bucket();
		$cur    = $this->get_url_bucket( $bucket );
		$cur[ $url ] ??= [ 'count' => 0, 'sum_req_time' => 0.0, 'samples' => 0 ];
		++$cur[ $url ]['count'];
		++$cur[ $url ]['samples'];
		$cur[ $url ]['sum_req_time'] += $req_time;
		Core::$memd?->set( $this->key( self::NS_URLS, $bucket ), $cur, $this->ttl() );
	}

	/**
	 * Explicit bucket setter (FlameBuilder's full-bucket overwrite path).
	 *
	 * @param array<string, mixed> $data
	 */
	public function set_url_index_hourly( string $bucket, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_URLS, $bucket ), $data, $this->ttl() );
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
	// Per-URL stats blob (flame, profiles, ...). Shorter TTL since per-URL
	// volume can be high.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
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

	// -------------------------------------------------------------------------
	// Leaderboard: 5-min buckets, sums + per-category sums.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function get_leaderboard_bucket( string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_LB, $bucket ) );
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_leaderboard_bucket( string $bucket, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_LB, $bucket ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $categories
	 */
	public function bump_leaderboard( float $req_time, array $categories = [] ): void {
		$bucket = $this->current_url_bucket();
		$cur    = $this->get_leaderboard_bucket( $bucket );
		if ( empty( $cur ) ) {
			$cur = [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
		}
		++$cur['count'];
		$cur['sum_req_time'] += $req_time;
		$this->merge_categories_into( $cur['categories'], $categories );
		Core::$memd?->set( $this->key( self::NS_LB, $bucket ), $cur, $this->ttl() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_server_leaderboard_bucket( string $server, string $bucket ): array {
		$val = Core::$memd?->get( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ) );
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_server_leaderboard_bucket( string $server, string $bucket, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ), $data, $this->ttl() );
	}

	/**
	 * @param array<string, mixed> $categories
	 */
	public function bump_server_leaderboard( string $server, float $req_time, array $categories = [] ): void {
		$bucket = $this->current_url_bucket();
		$cur    = $this->get_server_leaderboard_bucket( $server, $bucket );
		if ( empty( $cur ) ) {
			$cur = [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
		}
		++$cur['count'];
		$cur['sum_req_time'] += $req_time;
		$this->merge_categories_into( $cur['categories'], $categories );
		Core::$memd?->set( $this->key( self::NS_LB_S, self::server_key( $server ), $bucket ), $cur, $this->ttl() );
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
		$dst['count']        = (int)   ( $dst['count']        ?? 0 ) + (int)   ( $src['count']        ?? 0 );
		$dst['sum_req_time'] = (float) ( $dst['sum_req_time'] ?? 0 ) + (float) ( $src['sum_req_time'] ?? 0 );
		if ( ! isset( $dst['categories'] ) ) {
			$dst['categories'] = [];
		}
		foreach ( ( $src['categories'] ?? [] ) as $cat => $data ) {
			if ( ! isset( $dst['categories'][ $cat ] ) ) {
				$dst['categories'][ $cat ] = [
					'samples'   => 0,
					'sum_time'  => 0.0,
					'sum_count' => 0.0,
					'entries'   => [],
				];
			}
			$c               = &$dst['categories'][ $cat ];
			$c['samples']   += (int)   ( $data['samples']   ?? 0 );
			$c['sum_time']  += (float) ( $data['sum_time']  ?? 0 );
			$c['sum_count'] += (float) ( $data['sum_count'] ?? 0 );
			foreach ( ( $data['entries'] ?? [] ) as $name => $entry ) {
				if ( ! isset( $c['entries'][ $name ] ) ) {
					$c['entries'][ $name ] = [ 0.0, 0.0, 0 ];
				}
				$c['entries'][ $name ][0] += (float) ( $entry[0] ?? 0 );
				$c['entries'][ $name ][1] += (float) ( $entry[1] ?? 0 );
				$c['entries'][ $name ][2] += (int)   ( $entry[2] ?? 0 );
			}
			unset( $c );
		}
	}

	/**
	 * Merge per-request category samples (one request) into the bucket's running sums.
	 *
	 * Incoming shape: [cat => {time, count, entries: {name => [time, count]}}]
	 * Stored shape:   [cat => {samples, sum_time, sum_count, entries: {name => [sum_time, sum_count, samples]}}]
	 * @param array<string, mixed> $dst
	 * @param array<string, mixed> $src
	 */
	private function merge_categories_into( array &$dst, array $src ): void {
		foreach ( $src as $cat => $data ) {
			$dst[ $cat ] ??= [
				'samples'   => 0,
				'sum_time'  => 0.0,
				'sum_count' => 0.0,
				'entries'   => [],
			];
			++$dst[ $cat ]['samples'];
			$dst[ $cat ]['sum_time']  += (float) ( $data['time']  ?? 0 );
			$dst[ $cat ]['sum_count'] += (float) ( $data['count'] ?? 0 );
			foreach ( ( $data['entries'] ?? [] ) as $name => $entry ) {
				$dst[ $cat ]['entries'][ $name ] ??= [ 0.0, 0.0, 0 ];
				$dst[ $cat ]['entries'][ $name ][0] += (float) ( $entry[0] ?? 0 );
				$dst[ $cat ]['entries'][ $name ][1] += (float) ( $entry[1] ?? 0 );
				++$dst[ $cat ]['entries'][ $name ][2];
			}
		}
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
		return \is_array( $val ) ? $val : [];
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

	public function bump_dimensional( string $dimension, string $value, float $req_time ): void {
		$cur                 = $this->get_dimensional( $dimension );
		$bucket              = $this->current_url_bucket();
		$cur[ $bucket ]      ??= [];
		$bucket_data         = &$cur[ $bucket ];
		$this->bump_with_cap( $bucket_data, $value, $req_time, self::MAX_DIM_VALUES );
		unset( $bucket_data );
		$this->prune_old_dim( $cur );
		Core::$memd?->set( $this->key( self::NS_DIM, $dimension ), $cur, $this->ttl() );
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
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_dimensional( string $url_hash, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_URL_DIM, $url_hash ), $data, $this->ttl() );
	}

	public function bump_url_dimensional( string $url_hash, string $dimension, string $value, float $req_time ): void {
		$cur                                  = $this->get_url_dimensional( $url_hash );
		$bucket                               = $this->current_url_bucket();
		$cur[ $dimension ]                    ??= [];
		$cur[ $dimension ][ $bucket ]         ??= [];
		$bucket_data                          = &$cur[ $dimension ][ $bucket ];
		$this->bump_with_cap( $bucket_data, $value, $req_time, self::MAX_URL_DIM_VALUES );
		unset( $bucket_data );
		Core::$memd?->set( $this->key( self::NS_URL_DIM, $url_hash ), $cur, $this->ttl() );
	}

	/**
	 * Bump a dimensional value within a bucket, capping at $max total slots.
	 *
	 * "Other" is reserved as one of the $max slots: when an unknown value
	 * arrives and the bucket already has max-1 named entries (or is at max),
	 * the value rolls into Other. Total slot count is bounded at $max.
	 *
	 * "total" is the pseudo-category running grand-total — exempt from the cap.
	 * @param array<string, mixed> $bucket_data
	 */
	private function bump_with_cap( array &$bucket_data, string $value, float $req_time, int $max ): void {
		if ( 'total' !== $value && ! isset( $bucket_data[ $value ] ) ) {
			$count     = \count( $bucket_data );
			$has_other = isset( $bucket_data['Other'] );
			if ( $count >= $max || ( $count >= $max - 1 && ! $has_other ) ) {
				$value = 'Other';
			}
		}
		$bucket_data[ $value ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
		++$bucket_data[ $value ]['c'];
		$bucket_data[ $value ]['s'] += $req_time;
	}

	/**
	 * @param array<string, mixed> $cur
	 */
	private function prune_old_dim( array &$cur ): void {
		$cutoff     = \time() - $this->max_lifespan;
		$min_bucket = \gmdate( 'Y-m-d-H', $cutoff ) . '-00';
		foreach ( \array_keys( $cur ) as $b ) {
			if ( $b < $min_bucket ) {
				unset( $cur[ $b ] );
			}
		}
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
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_categories( array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_CATEGORIES ), $data, $this->ttl() );
	}

	public function bump_category( string $category, float $time, int $invocations ): void {
		$cur            = $this->get_categories();
		$bucket         = $this->current_url_bucket();
		$cur[ $bucket ] ??= [];
		$bucket_data    = &$cur[ $bucket ];
		$this->bump_category_with_cap( $bucket_data, $category, $time, $invocations, self::MAX_CAT_VALUES );
		unset( $bucket_data );
		$this->prune_old_dim( $cur );
		Core::$memd?->set( $this->key( self::NS_CATEGORIES ), $cur, $this->ttl() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_server_categories( string $server ): array {
		$val = Core::$memd?->get( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ) );
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_server_categories( string $server, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_CATEGORIES, self::server_key( $server ) ), $data, $this->ttl() );
	}

	// -------------------------------------------------------------------------
	// Per-URL categories: { bucket => { cat => {t, c, n} } } per url_hash.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function get_url_categories( string $url_hash ): array {
		$val = Core::$memd?->get( $this->key( self::NS_URL_CAT, $url_hash ) );
		return \is_array( $val ) ? $val : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function set_url_categories( string $url_hash, array $data ): bool {
		return (bool) Core::$memd?->set( $this->key( self::NS_URL_CAT, $url_hash ), $data, $this->ttl() );
	}

	public function bump_url_category( string $url_hash, string $category, float $time, int $invocations ): void {
		$cur            = $this->get_url_categories( $url_hash );
		$bucket         = $this->current_url_bucket();
		$cur[ $bucket ] ??= [];
		$bucket_data    = &$cur[ $bucket ];
		$this->bump_category_with_cap( $bucket_data, $category, $time, $invocations, self::MAX_CAT_VALUES );
		unset( $bucket_data );
		Core::$memd?->set( $this->key( self::NS_URL_CAT, $url_hash ), $cur, $this->ttl() );
	}

	/**
	 * Bump a category {t, c, n} entry within a bucket, capping at $max with
	 * "Other" rollover (same semantics as bump_with_cap).
	 *
	 * "total" is the pseudo-category running grand-total — exempt from the cap.
	 * @param array<string, mixed> $bucket_data
	 */
	private function bump_category_with_cap( array &$bucket_data, string $category, float $time, int $invocations, int $max ): void {
		if ( 'total' !== $category && ! isset( $bucket_data[ $category ] ) ) {
			$count     = \count( $bucket_data );
			$has_other = isset( $bucket_data['Other'] );
			if ( $count >= $max || ( $count >= $max - 1 && ! $has_other ) ) {
				$category = 'Other';
			}
		}
		$bucket_data[ $category ] ??= [ 't' => 0.0, 'c' => 0.0, 'n' => 0 ];
		$bucket_data[ $category ]['t'] += $time;
		$bucket_data[ $category ]['c'] += $invocations;
		++$bucket_data[ $category ]['n'];
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
	 * @param int   $total_count  Total profiled requests.
	 * @param float $sum_req_time Sum of per-request $req_time values.
	 * @param array $sums         Per-category sums keyed by category name.
	 * @return array Display-shaped leaderboard data.
	 * @param array<string, mixed> $sums
	 * @return array<string, mixed>
	 */
	public static function sums_to_display( int $total_count, float $sum_req_time, array $sums ): array {
		$display_cats = [];
		foreach ( $sums as $cat => $data ) {
			$samples   = (int) ( $data['samples'] ?? 0 );
			$sum_time  = (float) ( $data['sum_time'] ?? 0 );
			$sum_count = (float) ( $data['sum_count'] ?? 0 );

			$entries_out = [];
			foreach ( ( $data['entries'] ?? [] ) as $name => $entry ) {
				$e_samples = (int) ( $entry[2] ?? 0 );
				if ( $e_samples > 0 ) {
					$entries_out[ $name ] = [
						( $entry[0] ?? 0 ) / $e_samples,
						( $entry[1] ?? 0 ) / $e_samples,
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
