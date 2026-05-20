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
	 * Cached config defaults from files.
	 *
	 * @var array|null
	 */
	private static $config_defaults = null;

	/**
	 * Option schema — every key loaded on every `load_config()` call.
	 */
	private static $option_schema = [
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
		// NOTE: aggregator_servers is intentionally NOT in this per-request
		// schema. It holds encrypted spoke credentials, is admin/hub-only,
		// and is read lazily by ServerRegistry (get_wp_servers() + the file
		// default via load_config_defaults()). Putting it here would mean a
		// get_option + sanitize of that blob on every frontend request for a
		// value load_config() never returns to any consumer.
	];

	/**
	 * Fully-qualified option names that must NOT autoload. Single source of
	 * truth for the autoload policy every write path consults via
	 * `autoload_for()`. These are read on every request by `load_config()`,
	 * but their values can grow unbounded (full instrumented-hook maps), so
	 * keeping them out of the per-request `alloptions` blob is the right
	 * trade — one targeted read each beats bloating every frontend request.
	 * `discovered_events` is admin/health-check-only (not even in the schema)
	 * and is listed here so its writers route through the same helper.
	 */
	private static $non_autoloaded_options = [
		'newspack_event_logger_nodes_log_events'        => true,
		'newspack_event_logger_nodes_custom_events'     => true,
		'newspack_event_logger_nodes_discovered_events' => true,
	];

	/**
	 * Whether a given option should be written with `autoload=true`. Every
	 * `update_option()` call for an application option routes through this so
	 * the hot-path scalars stay on the single alloptions query and the large
	 * list options stay off it — consistently, regardless of which write path
	 * (admin save, Performance_CI verbs, AutoTuner, health-check) fires.
	 */
	public static function autoload_for( string $option ): bool {
		return ! isset( self::$non_autoloaded_options[ $option ] );
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
	 * `memcache_servers`, etc.) keep working without having to know about
	 * the layering split.
	 *
	 * @return array Configuration array.
	 */
	public static function load_config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		// Layer substrate config first; application values win on key
		// collisions. Substrate `load_config()` already handles the
		// substrate sample overlay.
		$substrate = \class_exists( RuntimeConfig::class ) ? RuntimeConfig::load_config() : [];
		$config    = \array_merge( $substrate, self::load_config_defaults() );

		if ( \defined( 'ABSPATH' ) && \function_exists( 'get_option' ) ) {
			foreach ( self::$option_schema as $key => $type ) {
				$value = \get_option( "newspack_event_logger_nodes_{$key}" );
				if ( false === $value || '' === $value ) {
					continue;
				}
				$sanitized = Config_Utils::sanitize_option( $value, $type );
				if ( null !== $sanitized ) {
					$config[ $key ] = $sanitized;
				}
			}
		}

		self::$config = $config;

		return $config;
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
	 * Resets the substrate Config too so the layered view rebuilds from
	 * scratch. The substrate fires `newspack_nodes/config_reset`, which our
	 * listener (registered at plugin load) catches to invalidate THIS class
	 * via `reset_local_cache()` — calling `reset()` directly from inside
	 * that listener would loop back into the substrate.
	 */
	public static function reset(): void {
		self::reset_local_cache();
		if ( \class_exists( RuntimeConfig::class ) ) {
			RuntimeConfig::reset();
		}
	}

	/**
	 * Clear this class's static cache only — no fan-out.
	 */
	public static function reset_local_cache(): void {
		self::$config          = null;
		self::$config_defaults = null;
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
