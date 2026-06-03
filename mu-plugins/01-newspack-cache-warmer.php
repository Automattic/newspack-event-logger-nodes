<?php
/**
 * Plugin Name: Newspack Cache Warmer
 * Description: Standalone refresh-ahead warmer — keeps the homepage's caches hot out-of-band so no visitor pays the cold render. Self-contained drop-in: no dependency on any plugin.
 * Version: 0.1.0
 * Author: Automattic
 *
 * Deploy: copy to wp-content/mu-plugins/01-newspack-cache-warmer.php. Drop-in,
 * not a plugin — runs regardless of which plugins are active. Persistent state
 * is one cron event + one secret option.
 *
 * @package Newspack_Cache_Warmer
 */

namespace Newspack_Cache_Warmer;

\defined( 'ABSPATH' ) || exit;

/**
 * Wraps the real object cache: reads on allowlisted "cold" groups miss; every
 * write (and every other method/property) passes through. Swapped in for
 * $GLOBALS['wp_object_cache'] only inside the warmer's own loopback render so
 * the site rebuilds those groups and writes fresh entries straight to live
 * memcached — no key replication, no cold window.
 */
class Cold_Read_Object_Cache {

	/**
	 * The real object cache being wrapped. Untyped at runtime so it accepts any
	 * drop-in's cache class (and the unit-test double); \WP_Object_Cache for
	 * static analysis so the delegated method calls resolve.
	 *
	 * @var \WP_Object_Cache
	 */
	private $real;

	/** @var array<string, true> Cold groups as a set for O(1) membership checks. */
	private array $cold;

	/**
	 * @param \WP_Object_Cache $real        The real object cache being wrapped.
	 * @param array<string>    $cold_groups Cache groups whose reads must miss.
	 */
	public function __construct( $real, array $cold_groups ) {
		$this->real = $real;
		$this->cold = \array_fill_keys( $cold_groups, true );
	}

	/**
	 * Single-key read; misses on a cold group, else delegates (keeping $found by-ref).
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Whether to force a refetch.
	 * @param mixed      $found Set by reference to whether the key was found.
	 * @return mixed
	 */
	public function get( $key, $group = '', $force = false, &$found = null ) {
		if ( isset( $this->cold[ $group ] ) ) {
			$found = false;
			return false;
		}
		return $this->real->get( $key, $group, $force, $found );
	}

	/**
	 * Multi-key read (WP 5.5+); misses every key on a cold group.
	 *
	 * @param array<int|string> $keys  Cache keys.
	 * @param string            $group Cache group.
	 * @param bool              $force Whether to force a refetch.
	 * @return array<int|string, mixed>
	 */
	public function get_multiple( $keys, $group = '', $force = false ) {
		if ( isset( $this->cold[ $group ] ) ) {
			return \array_fill_keys( $keys, false );
		}
		return $this->real->get_multiple( $keys, $group, $force );
	}

	/**
	 * Write-through so the warm render's fresh entries replace the live ones in place.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Cache group.
	 * @param int        $expire TTL in seconds.
	 * @return bool
	 */
	public function set( $key, $data, $group = '', $expire = 0 ) {
		return $this->real->set( $key, $data, $group, $expire );
	}

	/**
	 * All other methods (add/replace/delete/incr/decr/flush/add_global_groups/…) pass through.
	 *
	 * @param string       $name Method name.
	 * @param array<mixed> $args Arguments.
	 * @return mixed
	 */
	public function __call( $name, $args ) {
		return $this->real->{$name}( ...$args );
	}

	/**
	 * Property reads (->cache_hits, ->global_groups, …) delegate to the real cache.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->real->{$name};
	}

	/**
	 * Property writes land on the real cache, not a dynamic prop on the decorator.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 */
	public function __set( $name, $value ): void {
		$this->real->{$name} = $value;
	}

	/**
	 * @param string $name Property name.
	 */
	public function __isset( $name ): bool {
		return isset( $this->real->{$name} );
	}

	/**
	 * @param string $name Property name.
	 */
	public function __unset( $name ): void {
		unset( $this->real->{$name} );
	}
}

/**
 * Refresh-ahead warmer. On its own loopback hit it installs the cold-read
 * decorator (at drop-in load, before any plugin reads the cache). The recurring
 * cron that fires the loopback is NOT auto-scheduled — schedule it manually
 * where you want it to run:
 *   wp cron event schedule eln_cache_warmer_tick now eln_cache_warmer_minute
 * This drop-in registers both the handler (so the event is runnable) and its
 * own `eln_cache_warmer_minute` recurrence (so scheduling doesn't depend on any
 * other plugin's cron interval being loaded).
 */
class Cache_Warmer {

	/** Option holding the loopback secret. */
	private const SECRET_OPTION = 'eln_cache_warmer_secret';

	/** Cron hook the warmer ticks on (schedule it manually via `wp cron`). */
	public const CRON_HOOK = 'eln_cache_warmer_tick';

	/** Self-owned 60s recurrence so scheduling never depends on another plugin. */
	public const CRON_SCHEDULE = 'eln_cache_warmer_minute';

	/** Single-flight lock so a slow render can't be lapped by the next tick. */
	private const LOCK = 'eln_cache_warmer_lock';

	/** Drop-in bootstrap: install on a warm loopback + register the cron handler + recurrence. */
	public static function register(): void {
		self::maybe_install_for_request();
		\add_action( self::CRON_HOOK, [ self::class, 'run_tick' ] );
		\add_filter( 'cron_schedules', [ self::class, 'register_cron_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval -- intentional 60s warmer cadence (matches the substrate supervisor's minute tick).
	}

	/**
	 * Register the warmer's own 60s cron interval so `wp cron event schedule
	 * eln_cache_warmer_tick now eln_cache_warmer_minute` works standalone —
	 * no reliance on newspack-nodes' `newspack_nodes_minute` being loaded.
	 *
	 * @param array<string, mixed> $schedules Existing cron schedules.
	 * @return array<string, mixed>
	 */
	public static function register_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = [
				'interval' => 60,
				'display'  => 'Every Minute (Cache Warmer)',
			];
		}
		return $schedules;
	}

	/** Install the cold-read decorator iff this request is the warmer's own loopback. */
	public static function maybe_install_for_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the secret param IS the auth.
		if ( ! isset( $_GET['eln_cache_warm'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the secret param IS the auth.
		if ( ! self::is_warm_request( $_GET, self::secret() ) ) {
			return;
		}
		// Tag the request so the profiler/RequestBuilder drops this (intentionally
		// expensive) render from timing stats, the way worker requests are excluded.
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'cache-warmer';
		// Let the loopback reach the real homepage rather than an access-gate
		// login page (e.g. the Password Protected plugin would 302 it otherwise).
		\add_filter( 'password_protected_is_active', static fn (): bool => false );
		self::install_cold_cache();
	}

	/**
	 * Object-cache groups the warm render forces cold so the site rebuilds them.
	 *
	 * @return array<int, string>
	 */
	public static function cold_groups(): array {
		return (array) \apply_filters(
			'eln_cache_warmer_cold_groups',
			[ 'newspack_blocks', 'transient', 'site-transient' ]
		);
	}

	/**
	 * Whether this request is the warmer's own loopback hit (secret matches).
	 *
	 * @param array<string, mixed> $get             The request query params ($_GET).
	 * @param string               $expected_secret The stored warmer secret.
	 * @return bool
	 */
	public static function is_warm_request( array $get, string $expected_secret ): bool {
		// Empty secret never matches, so an unconfigured site can't be warm-tricked.
		if ( '' === $expected_secret || ! isset( $get['eln_cache_warm'] ) || ! \is_string( $get['eln_cache_warm'] ) ) {
			return false;
		}
		return \hash_equals( $expected_secret, $get['eln_cache_warm'] );
	}

	/** Swap the live object cache for the cold-read decorator (process-local, idempotent). */
	public static function install_cold_cache(): void {
		if (
			! isset( $GLOBALS['wp_object_cache'] )
			|| $GLOBALS['wp_object_cache'] instanceof Cold_Read_Object_Cache
		) {
			return;
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate, process-local swap for the warm render only.
		$GLOBALS['wp_object_cache'] = new Cold_Read_Object_Cache(
			$GLOBALS['wp_object_cache'],
			self::cold_groups()
		);
	}

	/** The shared secret gating warm requests; generated once and stored non-autoloaded. */
	public static function secret(): string {
		$secret = (string) \get_option( self::SECRET_OPTION, '' );
		if ( '' === $secret ) {
			$secret = \bin2hex( \random_bytes( 16 ) );
			\update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/** The loopback URL the cron hits (homepage + secret param). */
	public static function warm_url(): string {
		return \add_query_arg( 'eln_cache_warm', self::secret(), \home_url( '/' ) );
	}

	/** Cron callback: fire the blocking loopback warm render (single-flight). */
	public static function run_tick(): void {
		// Single-flight: skip if a prior render is still in flight (the lock
		// also auto-expires, so a crashed render can't wedge the warmer).
		if ( \get_transient( self::LOCK ) ) {
			return;
		}
		\set_transient( self::LOCK, 1, 60 );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- portable loopback; vip_safe_* may be absent off-VIP and caps the timeout below our cold render.
		$result = \wp_remote_get(
			self::warm_url(),
			[
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- background cron must outlast the ~6.5s cold render.
				'timeout'   => 20,
				'blocking'  => true,
				// Verify TLS by default (the loopback hits the public home_url host);
				// only self-signed-cert dev environments opt out via the filter.
				'sslverify' => (bool) \apply_filters( 'eln_cache_warmer_sslverify', true ),
			]
		);
		\delete_transient( self::LOCK );

		// Surface loopback failures (e.g. an unroutable host) instead of swallowing them.
		if ( \is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( '[eln-cache-warmer] loopback failed: ' . $result->get_error_message() );
		}
	}
}

// Install the cold-read decorator if this request is the warmer's own loopback
// (before any plugin reads the cache) and register the cron handler so a
// manually-scheduled `eln_cache_warmer_tick` is runnable. Tests define
// NEWSPACK_CACHE_WARMER_SKIP_BOOT to load the classes alone.
if ( ! \defined( 'NEWSPACK_CACHE_WARMER_SKIP_BOOT' ) ) {
	Cache_Warmer::register();
}
