<?php
/**
 * PerformanceControllerBase: shared base for REST controllers.
 *
 * Provides the standard surface every newspack-event-logger-nodes REST
 * controller relies on: capability/auth check, partition validation,
 * fixed-window rate limiting, consistent 404 shape, and a config loader
 * that exposes runtime tuning via the `newspack_nodes/config` filter.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Memcached_Cache;
use Newspack_Nodes\Config as RuntimeConfig;

abstract class PerformanceControllerBase {
	/**
	 * Default rate limit window (seconds). Subclasses can override at the call site.
	 */
	public const RATE_LIMIT_WINDOW = 60;

	/**
	 * Default per-window quota. Sized for the dashboard's poll fan-out: a
	 * single open Performance tab fires ~5 endpoints every 2s = 150/min;
	 * multiple tabs (Performance + Workers + Errors + Gyroscope) push that
	 * to several hundred. 600/min keeps room without disabling the gate.
	 */
	public const RATE_LIMIT_REQUESTS = 600;

	/**
	 * Cache used for rate-limit counters. Lazy; production reaches for memcache,
	 * tests inject a FakeMemcached via set_cache().
	 *
	 * @var Cache_Interface|null
	 */
	private static ?Cache_Interface $cache = null;

	abstract public function register_routes(): void;

	/**
	 * Inject the cache driver (used by tests to wire FakeMemcached).
	 */
	public static function set_cache( ?Cache_Interface $cache ): void {
		self::$cache = $cache;
	}

	/**
	 * Lazy memcached factory: defer to filtered servers, fall back to defaults.
	 * Once instantiated, reused for the rest of the request.
	 *
	 * Public so subclasses across the package (Workers, Overview, Aggregator,
	 * etc.) can resolve the same shared instance without reflection. Tests
	 * inject a FakeMemcached via `set_cache()`; once injected, every
	 * `cache()` call returns it.
	 */
	public static function cache(): Cache_Interface {
		if ( null === self::$cache ) {
			$config  = self::load_config();
			$servers = $config['memcache_servers'] ?? Memcached_Cache::DEFAULT_SERVERS;
			if ( ! \is_array( $servers ) ) {
				$servers = Memcached_Cache::DEFAULT_SERVERS;
			}
			self::$cache = new Memcached_Cache( $servers );
		}
		return self::$cache;
	}

	/**
	 * Load runtime configuration via the `newspack_nodes/config` filter.
	 *
	 * Documented defaults — every key is filterable.
	 *  - `num_partitions`    (int)            firehose partition count, default 1
	 *  - `num_segments`      (int)            segments per partition, default 8
	 *  - `segment_size`      (int)            bytes per segment, default 16 MiB
	 *  - `max_lifespan`      (int)            stats retention seconds, default 86400
	 *  - `memcache_servers`  (array<string>)  host:port list, default 127.0.0.1:11211
	 *  - `base_directory`    (string)         filesystem root for runtime state
	 *  - `enable_workers`    (bool)           hub-only; default false
	 *  - `aggregator_servers`(array<array>)   spoke list for hub-side ingest
	 *
	 * Substrate values (`base_directory`, partitioning, `memcache_servers`,
	 * `enable_workers`, `aggregator_servers`) come from
	 * `\Newspack_Nodes\Config::load_config('full')` so deployments that set
	 * `newspack_nodes_*` WordPress options flow through. Documented defaults
	 * here keep the controller-base shape stable when the substrate hasn't
	 * loaded (e.g. early in test bootstrap, or when WordPress options are
	 * unset).
	 *
	 * @return array<string,mixed>
	 */
	public static function load_config(): array {
		$defaults = [
			'num_partitions'     => 1,
			'num_segments'       => 8,
			'segment_size'       => 16 * 1024 * 1024,
			'max_lifespan'       => 86400,
			'memcache_servers'   => Memcached_Cache::DEFAULT_SERVERS,
			'base_directory'     => '/tmp/newspack-nodes',
			'enable_workers'     => false,
			'aggregator_servers' => [],
		];
		// Layer in the substrate's runtime config so deployments that set
		// `newspack_nodes_memcache_servers`, `newspack_nodes_base_directory`,
		// etc. via WP option flow through. The documented defaults above are
		// the floor — substrate values override them; the
		// `newspack_nodes/config` filter has the final say.
		if ( \class_exists( RuntimeConfig::class ) ) {
			$substrate = RuntimeConfig::load_config( 'full' );
			if ( \is_array( $substrate ) ) {
				$defaults = \array_merge( $defaults, $substrate );
			}
		}
		if ( ! \function_exists( 'apply_filters' ) ) {
			return $defaults;
		}
		$filtered = \apply_filters( 'newspack_nodes/config', $defaults );
		return \is_array( $filtered ) ? \array_merge( $defaults, $filtered ) : $defaults;
	}

	public function read_permissions_check(): bool|\WP_Error {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
		}
		return true;
	}

	public function validate_partition( int $partition, int $num_partitions ): int|\WP_Error {
		if ( $partition < 0 || $partition >= $num_partitions ) {
			return new \WP_Error(
				'invalid_partition',
				"Partition $partition out of range [0, $num_partitions)",
				[ 'status' => 400 ]
			);
		}
		return $partition;
	}

	/**
	 * Consistent 404 shape; pass the resource name (e.g. "request rid=abc").
	 */
	protected function not_found_error( string $what ): \WP_Error {
		return new \WP_Error(
			'rest_not_found',
			"Not found: {$what}",
			[ 'status' => 404 ]
		);
	}

	/**
	 * Fixed-window rate limit using a memcached counter.
	 *
	 * Window edges are computed by flooring `time()` to `$window_s`, so all
	 * callers in the same wall-clock window share a counter. The counter
	 * naturally expires when the window does — no scrubber needed.
	 *
	 * Returns true if the call is allowed, WP_Error 429 if it would exceed
	 * the quota. If memcache is unreachable, fail-open: the system should
	 * stay reachable rather than block on a degraded sidecar.
	 *
	 * @param string $key          Identity key (caller decides shape: user_$id / ip_$hash).
	 * @param int    $max_per_window Max allowed in this window (default 60).
	 * @param int    $window_s     Window length in seconds (default 60).
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit( string $key, int $max_per_window = self::RATE_LIMIT_REQUESTS, int $window_s = self::RATE_LIMIT_WINDOW ): bool|\WP_Error {
		$cache = self::cache();
		if ( ! $cache->is_available() ) {
			return true; // Fail-open — better degraded than blocked.
		}
		$now           = \time();
		$window_start  = (int) \floor( $now / $window_s ) * $window_s;
		$cache_key     = "newspack_nodes_rate:{$key}:{$window_start}";
		$ttl           = $window_start + $window_s + 10 - $now;
		if ( $ttl < 1 ) {
			$ttl = $window_s;
		}

		// add() returns false if key exists; that's fine, we just bump it.
		$cache->add( $cache_key, 0, $ttl );
		$count = (int) ( $cache->get( $cache_key ) ?? 0 );

		if ( $count >= $max_per_window ) {
			$retry_after = \max( 1, $window_start + $window_s - $now );
			return new \WP_Error(
				'rate_limit_exceeded',
				"Rate limit exceeded; retry in {$retry_after}s",
				[ 'status' => 429 ]
			);
		}
		$cache->set( $cache_key, $count + 1, $ttl );
		return true;
	}

	/**
	 * Best-effort identity key for rate limiting.
	 *
	 * Logged-in users key on user id; anonymous fall back to a hashed REMOTE_ADDR.
	 */
	protected function rate_limit_key(): string {
		if ( \function_exists( 'get_current_user_id' ) ) {
			$uid = (int) \get_current_user_id();
			if ( $uid > 0 ) {
				return "user_{$uid}";
			}
		}
		// REMOTE_ADDR drives an internal rate-limit bucket only — it's
		// SHA-hashed before being used as a cache key, never echoed back
		// to the user or used for auth. The "user-controlled" warning
		// matters when REMOTE_ADDR feeds an authz decision; here it's
		// just a per-client counter discriminator.
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		return 'ip_' . \substr( \hash( 'sha256', $remote ), 0, 12 );
	}
}
