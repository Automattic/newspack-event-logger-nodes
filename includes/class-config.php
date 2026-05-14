<?php
/**
 * Event Logger Configuration
 *
 * Owns application-level keys (logging toggles, URL filters, hook lists, etc.).
 * Substrate keys (base_directory, partitioning, memcache_servers,
 * enable_workers, aggregator_servers) live on `\Newspack_Nodes\Config`.
 *
 * `load_config()` merges substrate values into the returned array so existing
 * callers reading e.g. `$config['base_directory']` continue to work without
 * having to know which Config to ask. Path-resolution helpers delegate to the
 * substrate Config — only one place owns the realpath/symlink check.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_Utils;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration management class.
 */
class Config {
	/**
	 * Cached config (file defaults + WordPress options + substrate values).
	 *
	 * @var array|null
	 */
	private static $config = null;

	/**
	 * Cached full config (includes extended options).
	 *
	 * @var array|null
	 */
	private static $config_full = null;

	/**
	 * Cached config defaults from files.
	 *
	 * @var array|null
	 */
	private static $config_defaults = null;

	/**
	 * Get core option schema - loaded on every request (autoloaded).
	 *
	 * Plugins can add their core options via the
	 * 'newspack_event_logger_nodes_option_schema_core' filter. Core options
	 * are needed for request logging and hook timing.
	 *
	 * @return array Associative array of option_name => type.
	 */
	private static function get_option_schema_core(): array {
		// Application core options (substrate keys live on RuntimeConfig).
		$schema = [
			'enable_logging'              => 'bool',
			'log_urls'                    => 'array_strings',
			'skip_urls'                   => 'array_strings',
			'log_events'                  => 'array_strings',
			'custom_events'               => 'array_strings',
			'log_memory'                  => 'bool',
			'flush_every_line'            => 'bool',
			'significant_events'          => 'array_strings',
			'hook_start_priority'         => 'int',
			'allowed_users'               => 'array_strings',
			'auto_disable_threshold'      => 'int',
			'auto_protect_time_threshold' => 'float',
		];

		// Allow plugins to add their core options.
		if ( \function_exists( 'apply_filters' ) ) {
			$schema = \apply_filters( 'newspack_event_logger_nodes_option_schema_core', $schema );
		}

		return \is_array( $schema ) ? $schema : [];
	}

	/**
	 * Get extended option schema - only loaded for workers/admin (not autoloaded).
	 *
	 * Plugins can add their extended options via the
	 * 'newspack_event_logger_nodes_option_schema_extended' filter. Extended
	 * options are only needed by workers (FlameBuilder) and admin settings.
	 *
	 * @return array Associative array of option_name => type.
	 */
	private static function get_option_schema_extended(): array {
		// Application-level extended options. Substrate-level extended
		// options (memcache_servers) live on RuntimeConfig and are merged
		// into the returned config via load_config().
		$schema = [
			'aggregator_servers' => 'aggregator_servers',
		];

		// Allow plugins to add their extended options.
		if ( \function_exists( 'apply_filters' ) ) {
			$schema = \apply_filters( 'newspack_event_logger_nodes_option_schema_extended', $schema );
		}

		return \is_array( $schema ) ? $schema : [];
	}

	/**
	 * Allowed directories for local config override files.
	 *
	 * Only config files within these directories (or subdirectories) are allowed.
	 */
	private static $allowed_config_dirs = [
		'/usr/src',
	];

	/**
	 * Load configuration from disk + WordPress options.
	 *
	 * Merges the substrate config (`Newspack_Nodes\Config::load_config`) so
	 * callers that read substrate keys (`base_directory`, `num_partitions`,
	 * `memcache_servers`, etc.) keep working without having to know about the
	 * layering split.
	 *
	 * @param string $mode 'core' (default) loads only options needed for request logging.
	 *                     'full' loads all options including worker/admin settings.
	 * @return array Configuration array.
	 */
	public static function load_config( string $mode = 'core' ): array {
		$is_full = 'full' === $mode;

		// Return cached config if available.
		if ( $is_full && null !== self::$config_full ) {
			return self::$config_full;
		}
		if ( ! $is_full && null !== self::$config ) {
			return self::$config;
		}

		// Layer substrate config first; application values win on key
		// collisions. Substrate `load_config()` already handles the
		// substrate sample overlay.
		$substrate = \class_exists( RuntimeConfig::class ) ? RuntimeConfig::load_config( $mode ) : [];

		// Load application defaults from disk.
		$config = \array_merge( $substrate, self::load_config_defaults() );

		// Override with WordPress options (with sanitization).
		if ( \defined( 'ABSPATH' ) && \function_exists( 'get_option' ) ) {
			// Always load core options.
			foreach ( self::get_option_schema_core() as $key => $type ) {
				$value = \get_option( "newspack_event_logger_nodes_{$key}" );
				if ( false !== $value && '' !== $value ) {
					$sanitized = self::sanitize_option( $value, $type );
					if ( null !== $sanitized ) {
						$config[ $key ] = $sanitized;
					}
				}
			}

			// Load extended options only for 'full' mode.
			if ( $is_full ) {
				foreach ( self::get_option_schema_extended() as $key => $type ) {
					$value = \get_option( "newspack_event_logger_nodes_{$key}" );
					if ( false !== $value && '' !== $value ) {
						$sanitized = self::sanitize_option( $value, $type );
						if ( null !== $sanitized ) {
							$config[ $key ] = $sanitized;
						}
					}
				}
			}
		}

		// Cache the computed config. Late-loading plugins (alphabetically
		// later in the plugin load order) may add to the option schema via
		// the `newspack_event_logger_nodes_option_schema_core` filter AFTER this
		// call, so the main plugin file hooks a one-shot cache reset on
		// `plugins_loaded` at priority PHP_INT_MIN — see the
		// `register_cache_invalidation` static initializer below. That
		// guarantees post-plugins_loaded reads pick up the full schema
		// without forcing every pre-plugins_loaded caller to re-run the
		// full filter chain.
		if ( $is_full ) {
			self::$config_full = $config;
		} else {
			self::$config = $config;
		}

		return $config;
	}

	/**
	 * Invalidate cached config so the next load_config() call rebuilds with
	 * the complete schema. Called once on plugins_loaded (see register_cache_invalidation).
	 */
	public static function invalidate_cache(): void {
		self::$config          = null;
		self::$config_full     = null;
		self::$config_defaults = null;
	}

	/**
	 * Hook a one-shot cache invalidation on plugins_loaded so that any
	 * schema additions registered by late-loading plugins are picked up by
	 * the next load_config() call. Invoked from the plugin main file.
	 */
	public static function register_cache_invalidation(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		if ( \function_exists( 'add_action' ) ) {
			\add_action( 'plugins_loaded', [ self::class, 'invalidate_cache' ], PHP_INT_MIN );
		}
	}

	/**
	 * Sanitize an option value. Application-specific types (`aggregator_servers`)
	 * are handled here; everything else delegates to Config_Utils.
	 */
	private static function sanitize_option( $value, string $type ) {
		if ( 'aggregator_servers' !== $type ) {
			return Config_Utils::sanitize_option( $value, $type );
		}
		if ( ! \is_array( $value ) ) {
			return null;
		}
		$result = [];
		foreach ( $value as $server_id => $config ) {
			if ( ! \is_array( $config ) ) {
				continue;
			}
			$server_id = Config_Utils::sanitize_string( $server_id );
			if ( empty( $server_id ) ) {
				continue;
			}
			$url = $config['url'] ?? '';
			if ( ! \is_string( $url ) || 0 !== \strpos( $url, 'https://' ) ) {
				continue;
			}
			$result[ $server_id ] = [
				'url'           => \esc_url_raw( $url ),
				'auth_username' => Config_Utils::sanitize_string( $config['auth_username'] ?? '' ),
				'auth_password' => Config_Utils::sanitize_string( $config['auth_password'] ?? '' ),
				'enabled'       => (bool) ( $config['enabled'] ?? true ),
			];
		}
		return $result;
	}

	/**
	 * Load configuration defaults from file only (no WordPress options).
	 *
	 * @return array Configuration defaults from file.
	 */
	public static function load_config_defaults(): array {
		if ( null !== self::$config_defaults ) {
			return self::$config_defaults;
		}

		$config = Config_Utils::load_config_file(
			[],
			\dirname( __DIR__ ) . '/newspack-event-logger-nodes-config.php',
			'Newspack_Event_Logger_Nodes\\Config'
		);
		$local_config_file = \getenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		if ( $local_config_file ) {
			$validated_path = self::validate_config_path( $local_config_file );
			if ( $validated_path ) {
				$config = Config_Utils::load_config_file(
					$config,
					$validated_path,
					'Newspack_Event_Logger_Nodes\\Config'
				);
			}
		}

		self::$config_defaults = $config;

		return self::$config_defaults;
	}

	/**
	 * Validate a config-override path against the application's allowed
	 * directories (plus the plugin dir itself as a fallback). Wraps
	 * Config_Utils::validate_config_path with the application's list.
	 */
	private static function validate_config_path( string $path ): ?string {
		$dirs = [ ...self::$allowed_config_dirs, \dirname( __DIR__ ) ];
		return Config_Utils::validate_config_path( $path, $dirs, 'Newspack_Event_Logger_Nodes\\Config' );
	}

	/**
	 * Get custom colors with filter applied (for admin UI).
	 *
	 * This method applies the newspack_event_logger_nodes_custom_colors filter lazily,
	 * allowing plugins that load after Event Logger to register their events.
	 *
	 * @return array Associative array of event_name => hex_color.
	 */
	public static function get_custom_colors(): array {
		$config = self::load_config();
		$colors = $config['custom_colors'] ?? [];

		// Apply filter to allow plugins to register custom events.
		if ( \function_exists( 'apply_filters' ) ) {
			$colors = \apply_filters( 'newspack_event_logger_nodes_custom_colors', $colors );
			// Validate filter return type (may return any type).
			if ( ! \is_array( $colors ) ) {
				$colors = [];
			}
		}

		// Merge discovered events from remote spokes (available but not selected).
		if ( \function_exists( 'get_option' ) ) {
			$discovered = \get_option( 'newspack_event_logger_nodes_discovered_events', [] );
			if ( \is_array( $discovered ) ) {
				foreach ( $discovered as $event => $color ) {
					if ( ! isset( $colors[ $event ] ) ) {
						$colors[ $event ] = \is_string( $color ) ? $color : '#ffa726';
					}
				}
			}
		}

		// Sort alphabetically so events are easier to find in the UI.
		\ksort( $colors, SORT_NATURAL | SORT_FLAG_CASE );

		return $colors;
	}

	/**
	 * Reset cached config - call before load_config() to get fresh values.
	 *
	 * Resets the substrate Config too so the layered view rebuilds from scratch.
	 */
	public static function reset(): void {
		self::$config          = null;
		self::$config_full     = null;
		self::$config_defaults = null;
		if ( \class_exists( RuntimeConfig::class ) ) {
			RuntimeConfig::reset();
		}
	}

	/**
	 * Ensure a directory path exists and is canonical.
	 *
	 * Delegates to the substrate Config so realpath/symlink validation lives
	 * in one place.
	 *
	 * @param string $path Directory path to ensure.
	 * @return string Validated canonical path.
	 * @throws \RuntimeException If path cannot be created or is not canonical.
	 */
	public static function ensure_path( string $path ): string {
		return RuntimeConfig::ensure_path( $path );
	}

	/**
	 * Get the validated base directory path.
	 *
	 * Delegates to the substrate Config — `base_directory` is a substrate key.
	 *
	 * @return string Validated absolute path to base directory.
	 */
	public static function get_base_directory(): string {
		return RuntimeConfig::get_base_directory();
	}

	/**
	 * Get the logs directory path ({base}/logs).
	 *
	 * @return string Validated absolute path to logs directory.
	 */
	public static function get_logs_directory(): string {
		return RuntimeConfig::get_logs_directory();
	}

	/**
	 * Get the locks directory path ({base}/locks).
	 *
	 * @return string Validated absolute path to locks directory.
	 */
	public static function get_locks_directory(): string {
		return RuntimeConfig::get_locks_directory();
	}

	/**
	 * Get the offsets directory path ({base}/offsets).
	 *
	 * @return string Validated absolute path to offsets directory.
	 */
	public static function get_offsets_directory(): string {
		return RuntimeConfig::get_offsets_directory();
	}

	/**
	 * Force-restart any worker locks whose names start with one of the given
	 * group prefixes. Delegates to the substrate Config.
	 *
	 * @param string[] $groups Group-name prefixes to match against lock-dir basenames.
	 */
	public static function kill_readers( array $groups ): void {
		RuntimeConfig::kill_readers( $groups );
	}

}
