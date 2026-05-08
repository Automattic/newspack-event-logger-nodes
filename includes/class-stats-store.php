<?php
/**
 * Stats_Store: 10-namespace memcache schema for performance stats.
 *
 * Per-key prefix: `evlog[:salt]:p{N}:{namespace}:...`
 *
 * Namespaces:
 *   hourly      Y-m-d-H buckets, count + sum_ms + sum_peak_mb
 *   lb          5-min global leaderboard buckets, sums-not-means
 *   lb_s        per-server leaderboard, keyed by server
 *   urls        5-min URL index, keyed by URL → {count, sum_req_time, samples}
 *   url         per-URL flame/profile blob (TTL = max(3600, max_lifespan/24))
 *   dim         dimensional time series (status/method/server/...)
 *   url_dim     per-URL dimensional time series
 *   categories  global category time series
 *   url_cat     per-URL category time series
 *   flame_cache rotated FlameBuilder buckets ({event_name => {count, sum_time}})
 *
 * Sums-not-means storage: every aggregate stores raw sums (count + sum_*),
 * so cross-instance and cross-bucket merge is exact addition. Display layer
 * computes means at read time.
 *
 * Caps prevent value-explosion (memcache 1MB per-value limit):
 *   MAX_DIM_VALUES=20, MAX_URL_DIM_VALUES=10, MAX_CAT_VALUES=50.
 * Overflow rolls into the synthetic "Other" bucket.
 *
 * Schema migration: flush_all() rotates an 8-char salt so all existing keys
 * are orphaned (they expire via TTL). Instantaneous regardless of cache size.
 *
 * Failure mode: every op is fail-SOFT — memcache down → return null/empty/false,
 * never throw. SSE-slot fail-CLOSED policy lives at the caller (per-spec asymmetry).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

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
	public const NS_FLAME_CACHE = 'flame_cache';

	public const MAX_CAT_VALUES     = 50;
	public const MAX_DIM_VALUES     = 20;
	public const MAX_URL_DIM_VALUES = 10;

	private const PREFIX_BASE  = 'evlog';
	private const SALT_OPTION  = 'newspack_nodes_stats_salt';
	private const BUCKET_SECS  = 300; // 5-minute buckets.
	private const HOUR_SECS    = 3600;
	private const PREFIX_FLOOR = 3600;

	private Cache_Interface $mc;
	private int $partition;
	private int $max_lifespan;
	private string $prefix;

	public function __construct(
		Cache_Interface $mc,
		int $partition = 0,
		int $max_lifespan = 86400
	) {
		$this->mc           = $mc;
		$this->partition    = $partition;
		$this->max_lifespan = \max( self::PREFIX_FLOOR, $max_lifespan );
		$this->prefix       = $this->compute_prefix();
	}

	private function compute_prefix(): string {
		$salt = '';
		if ( \function_exists( 'get_option' ) ) {
			$salt = (string) \get_option( self::SALT_OPTION, '' );
		}
		return $salt === '' ? self::PREFIX_BASE : self::PREFIX_BASE . ':' . $salt;
	}

	public function ttl(): int {
		return $this->max_lifespan;
	}

	public function ttl_url_stats(): int {
		return \max( self::PREFIX_FLOOR, (int) ( $this->max_lifespan / 24 ) );
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

	private function key( string ...$parts ): string {
		\array_unshift( $parts, $this->prefix, 'p' . $this->partition );
		return \implode( ':', $parts );
	}

	// -------------------------------------------------------------------------
	// Hourly: { Y-m-d-H => {count, sum_ms, sum_peak_mb} }
	// Single key per partition holds the rolling map.
	// -------------------------------------------------------------------------

	public function get_hourly(): array {
		$val = $this->mc->get( $this->key( self::NS_HOURLY ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_hourly( float $req_time_secs, float $peak_mb ): void {
		$cur                                = $this->get_hourly();
		$bucket                             = $this->current_hour_bucket();
		$cur[ $bucket ]                     ??= [ 'count' => 0, 'sum_ms' => 0.0, 'sum_peak_mb' => 0.0 ];
		++$cur[ $bucket ]['count'];
		$cur[ $bucket ]['sum_ms']      += $req_time_secs * 1000.0;
		$cur[ $bucket ]['sum_peak_mb'] += $peak_mb;
		$this->prune_old_hourly( $cur );
		$this->mc->set( $this->key( self::NS_HOURLY ), $cur, $this->ttl() );
	}

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
	// One key per (partition, bucket).
	// -------------------------------------------------------------------------

	public function get_url_bucket( string $bucket ): array {
		$val = $this->mc->get( $this->key( self::NS_URLS, $bucket ) );
		return \is_array( $val ) ? $val : [];
	}

	public function get_url_buckets( array $buckets ): array {
		if ( empty( $buckets ) ) {
			return [];
		}
		$keys = [];
		$map  = [];
		foreach ( $buckets as $b ) {
			$k          = $this->key( self::NS_URLS, $b );
			$keys[]     = $k;
			$map[ $k ]  = $b;
		}
		$results = $this->mc->get_multi( $keys );
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
		$this->mc->set( $this->key( self::NS_URLS, $bucket ), $cur, $this->ttl() );
	}

	// -------------------------------------------------------------------------
	// Per-URL stats blob (flame, profiles, ...). Shorter TTL since per-URL
	// volume can be high.
	// -------------------------------------------------------------------------

	public function get_url_stats( string $url_hash ): ?array {
		$val = $this->mc->get( $this->key( self::NS_URL, $url_hash ) );
		return \is_array( $val ) ? $val : null;
	}

	public function set_url_stats( string $url_hash, array $data ): bool {
		return $this->mc->set( $this->key( self::NS_URL, $url_hash ), $data, $this->ttl_url_stats() );
	}

	// -------------------------------------------------------------------------
	// Leaderboard: 5-min buckets, sums + per-category sums.
	// -------------------------------------------------------------------------

	public function get_leaderboard_bucket( string $bucket ): array {
		$val = $this->mc->get( $this->key( self::NS_LB, $bucket ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_leaderboard( float $req_time, array $categories = [] ): void {
		$bucket = $this->current_url_bucket();
		$cur    = $this->get_leaderboard_bucket( $bucket );
		if ( empty( $cur ) ) {
			$cur = [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
		}
		++$cur['count'];
		$cur['sum_req_time'] += $req_time;
		$this->merge_categories_into( $cur['categories'], $categories );
		$this->mc->set( $this->key( self::NS_LB, $bucket ), $cur, $this->ttl() );
	}

	public function get_server_leaderboard_bucket( string $server, string $bucket ): array {
		$val = $this->mc->get( $this->key( self::NS_LB_S, $server, $bucket ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_server_leaderboard( string $server, float $req_time, array $categories = [] ): void {
		$bucket = $this->current_url_bucket();
		$cur    = $this->get_server_leaderboard_bucket( $server, $bucket );
		if ( empty( $cur ) ) {
			$cur = [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
		}
		++$cur['count'];
		$cur['sum_req_time'] += $req_time;
		$this->merge_categories_into( $cur['categories'], $categories );
		$this->mc->set( $this->key( self::NS_LB_S, $server, $bucket ), $cur, $this->ttl() );
	}

	/**
	 * Merge per-request category samples (one request) into the bucket's running sums.
	 *
	 * Incoming shape: [cat => {time, count, entries: {name => [time, count]}}]
	 * Stored shape:   [cat => {samples, sum_time, sum_count, entries: {name => [sum_time, sum_count, samples]}}]
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
	// One key per (partition, dimension).
	// -------------------------------------------------------------------------

	public function get_dimensional( string $dimension ): array {
		$val = $this->mc->get( $this->key( self::NS_DIM, $dimension ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_dimensional( string $dimension, string $value, float $req_time ): void {
		$cur                 = $this->get_dimensional( $dimension );
		$bucket              = $this->current_url_bucket();
		$cur[ $bucket ]      ??= [];
		$bucket_data         = &$cur[ $bucket ];
		$this->bump_with_cap( $bucket_data, $value, $req_time, self::MAX_DIM_VALUES );
		unset( $bucket_data );
		$this->prune_old_dim( $cur );
		$this->mc->set( $this->key( self::NS_DIM, $dimension ), $cur, $this->ttl() );
	}

	// -------------------------------------------------------------------------
	// Per-URL dimensional: { dim => { bucket => { value => {c, s, m} } } }
	// One key per (partition, url_hash). Cap is tighter (10) since per-URL
	// fan-out is multiplicative across dimensions × buckets.
	// -------------------------------------------------------------------------

	public function get_url_dimensional( string $url_hash ): array {
		$val = $this->mc->get( $this->key( self::NS_URL_DIM, $url_hash ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_url_dimensional( string $url_hash, string $dimension, string $value, float $req_time ): void {
		$cur                                  = $this->get_url_dimensional( $url_hash );
		$bucket                               = $this->current_url_bucket();
		$cur[ $dimension ]                    ??= [];
		$cur[ $dimension ][ $bucket ]         ??= [];
		$bucket_data                          = &$cur[ $dimension ][ $bucket ];
		$this->bump_with_cap( $bucket_data, $value, $req_time, self::MAX_URL_DIM_VALUES );
		unset( $bucket_data );
		$this->mc->set( $this->key( self::NS_URL_DIM, $url_hash ), $cur, $this->ttl() );
	}

	/**
	 * Bump a dimensional value within a bucket, capping at $max total slots.
	 *
	 * "Other" is reserved as one of the $max slots: when an unknown value
	 * arrives and the bucket already has max-1 named entries (or is at max),
	 * the value rolls into Other. Total slot count is bounded at $max.
	 *
	 * "total" is the pseudo-category running grand-total — exempt from the
	 * cap so the running sum is never lost when many distinct values arrive.
	 */
	private function bump_with_cap( array &$bucket_data, string $value, float $req_time, int $max ): void {
		if ( $value !== 'total' && ! isset( $bucket_data[ $value ] ) ) {
			$count = \count( $bucket_data );
			$has_other = isset( $bucket_data['Other'] );
			// At cap, or one slot below cap with Other not yet present: redirect.
			if ( $count >= $max || ( $count >= $max - 1 && ! $has_other ) ) {
				$value = 'Other';
			}
		}
		$bucket_data[ $value ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
		++$bucket_data[ $value ]['c'];
		$bucket_data[ $value ]['s'] += $req_time;
	}

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
	// Categories: { bucket => { cat => {t, c, n} } }
	// -------------------------------------------------------------------------

	public function get_categories(): array {
		$val = $this->mc->get( $this->key( self::NS_CATEGORIES ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_category( string $category, float $time, int $invocations ): void {
		$cur            = $this->get_categories();
		$bucket         = $this->current_url_bucket();
		$cur[ $bucket ] ??= [];
		$bucket_data    = &$cur[ $bucket ];
		$this->bump_category_with_cap( $bucket_data, $category, $time, $invocations, self::MAX_CAT_VALUES );
		unset( $bucket_data );
		$this->prune_old_dim( $cur );
		$this->mc->set( $this->key( self::NS_CATEGORIES ), $cur, $this->ttl() );
	}

	// -------------------------------------------------------------------------
	// Per-URL categories: { bucket => { cat => {t, c, n} } } per url_hash.
	// -------------------------------------------------------------------------

	public function get_url_categories( string $url_hash ): array {
		$val = $this->mc->get( $this->key( self::NS_URL_CAT, $url_hash ) );
		return \is_array( $val ) ? $val : [];
	}

	public function bump_url_category( string $url_hash, string $category, float $time, int $invocations ): void {
		$cur            = $this->get_url_categories( $url_hash );
		$bucket         = $this->current_url_bucket();
		$cur[ $bucket ] ??= [];
		$bucket_data    = &$cur[ $bucket ];
		$this->bump_category_with_cap( $bucket_data, $category, $time, $invocations, self::MAX_CAT_VALUES );
		unset( $bucket_data );
		$this->mc->set( $this->key( self::NS_URL_CAT, $url_hash ), $cur, $this->ttl() );
	}

	/**
	 * Bump a category {t, c, n} entry within a bucket, capping at $max with
	 * "Other" rollover (same semantics as bump_with_cap).
	 *
	 * "total" is the pseudo-category running grand-total — exempt from the
	 * cap so the running sum is never lost when many distinct categories arrive.
	 */
	private function bump_category_with_cap( array &$bucket_data, string $category, float $time, int $invocations, int $max ): void {
		if ( $category !== 'total' && ! isset( $bucket_data[ $category ] ) ) {
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
	// Flame cache: rotated FlameBuilder buckets keyed by bucket_id (e.g.,
	// floor(time/200) string). Each bucket stores {event_name => {count, sum_time}}.
	// One key per (partition, bucket_id). Sums-not-means: cross-bucket merge is
	// exact addition.
	// -------------------------------------------------------------------------

	public function get_flame_bucket( string $bucket_id ): array {
		$val = $this->mc->get( $this->key( self::NS_FLAME_CACHE, $bucket_id ) );
		return \is_array( $val ) ? $val : [];
	}

	public function set_flame_bucket( string $bucket_id, array $data ): bool {
		return $this->mc->set( $this->key( self::NS_FLAME_CACHE, $bucket_id ), $data, $this->ttl() );
	}

	/**
	 * Merge a rotated FlameBuilder bucket into memcache. Additive: if a bucket
	 * already exists at this key (e.g., a previous worker checkpoint flushed
	 * partial data), the new sums add to the existing sums.
	 *
	 * @param string $bucket_id e.g., "floor(time/200)" as a string.
	 * @param array  $data      {event_name => {count:int, sum_time:float}}
	 */
	public function merge_flame_bucket( string $bucket_id, array $data ): bool {
		$existing = $this->get_flame_bucket( $bucket_id );
		foreach ( $data as $name => $entry ) {
			if ( ! \is_string( $name ) || ! \is_array( $entry ) ) {
				continue;
			}
			$existing[ $name ] ??= [ 'count' => 0, 'sum_time' => 0.0 ];
			$existing[ $name ]['count']    += (int) ( $entry['count']    ?? 0 );
			$existing[ $name ]['sum_time'] += (float) ( $entry['sum_time'] ?? 0 );
		}
		return $this->set_flame_bucket( $bucket_id, $existing );
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
