<?php
/**
 * Event Logger Configuration
 *
 * Owns application-level keys (logging toggles, debugging flags, the per-URL
 * ruleset, etc.). Substrate keys (base_directory, partitioning, memcache_servers)
 * live on `\Newspack_Nodes\Config`.
 *
 * `load_config()` merges substrate values into the returned array so existing
 * callers reading e.g. `$config['base_directory']` continue to work without
 * having to know which Config to ask. Path-resolution helpers delegate to the
 * substrate Config — only one place owns the realpath/symlink check.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_Utils;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration management class.
 */
class Config {

	/** One-time marker so `correct_option_autoload()` sweeps once per install. */
	public const AUTOLOAD_FIXED_OPTION = 'newspack_event_logger_nodes_autoload_fixed';

	/** Staging option: custom-event names discovered across spokes (admin/health-check only). */
	public const OPTION_DISCOVERED_EVENTS = 'newspack_event_logger_nodes_discovered_events';

	/** Staging option: hook names discovered across spokes (admin/health-check only). */
	public const OPTION_DISCOVERED_HOOKS = 'newspack_event_logger_nodes_discovered_hooks';

	/**
	 * Allowed directories for local config override files.
	 *
	 * Only config files within these directories (or subdirectories) are allowed.
	 *
	 * @var array<int, string>
	 */
	private static $allowed_config_dirs = [
		'/usr/src',
	];

	/**
	 * Cached config (file defaults + WordPress options + substrate values).
	 *
	 * @var array<string, mixed>|null
	 */
	private static $config = null;

	/**
	 * Cached config defaults from files.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $config_defaults = null;

	/**
	 * Fully-qualified option names that must NOT autoload. Single source of
	 * truth for the autoload policy every write path consults via
	 * `autoload_for()`. These are read on every request by `load_config()`,
	 * but their values can grow unbounded (full instrumented-hook maps), so
	 * keeping them out of the per-request `alloptions` blob is the right
	 * trade — one targeted read each beats bloating every frontend request.
	 * `discovered_events` / `discovered_hooks` are admin/health-check-only (not
	 * even in the schema) and are listed here so their writers route through the
	 * same helper.
	 *
	 * @var array<string, bool>
	 */
	private static $non_autoloaded_options = [
		self::OPTION_DISCOVERED_EVENTS => true,
		self::OPTION_DISCOVERED_HOOKS  => true,
	];

	/**
	 * Get custom colors with filter applied (for admin UI).
	 *
	 * This method applies the newspack_event_logger_nodes_custom_colors filter lazily,
	 * allowing plugins that load after Event Logger to register their events.
	 *
	 * @return array<string, mixed> Associative array of event_name => hex_color.
	 */
	public static function get_custom_colors(): array {
		$config = self::load_config();
		$raw    = $config['custom_colors'] ?? [];
		/** @var array<string, mixed> $colors */
		$colors = \is_array( $raw ) ? $raw : [];

		// Apply filter to allow plugins to register custom events.
		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'newspack_event_logger_nodes_custom_colors', $colors );
			// Validate filter return type (may return any type); color maps are string-keyed by design.
			/** @var array<string, mixed> $colors */
			$colors = \is_array( $filtered ) ? $filtered : [];
		}

		// Merge discovered events from remote spokes (available but not selected).
		if ( \function_exists( 'get_option' ) ) {
			$discovered = \get_option( self::OPTION_DISCOVERED_EVENTS, [] );
			if ( \is_array( $discovered ) ) {
				foreach ( $discovered as $event => $color ) {
					$event = (string) $event;
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
	 * Load configuration from disk + WordPress options.
	 *
	 * Merges the substrate config (`Newspack_Nodes\Config::load_config`) so
	 * callers that read substrate keys (`base_directory`, `num_partitions`,
	 * `memcache_servers`, etc.) keep working without having to know about
	 * the layering split.
	 *
	 * @return array<string, mixed> Configuration array.
	 */
	public static function load_config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		if ( ! \class_exists( '\Newspack_Nodes\Config_Utils' ) ) {
			return [];
		}

		// Layer substrate config first; application values win on key
		// collisions. Substrate `load_config()` already handles the
		// substrate sample overlay.
		$substrate = \class_exists( RuntimeConfig::class ) ? RuntimeConfig::load_config() : [];
		$defaults  = \array_merge( $substrate, self::load_config_defaults() );

		// Presence-based overlay: a stored option (even '' / [] / false / 0) wins
		// over the file default; only an absent option falls back. Shared rule —
		// see Config_System\Options_Overlay. The overlay key-set is every
		// non-empty-key field in the single Settings_Schema.
		$schema = Settings_Schema::get();
		$config = \Newspack_Nodes\Config_System\Options_Overlay::apply(
			$defaults,
			$schema->overlay_keys(),
			$schema->prefix()
		);

		self::$config = $config;

		return $config;
	}

	/**
	 * Load configuration defaults from file only (no WordPress options).
	 *
	 * @return array<string, mixed> Configuration defaults from file.
	 */
	public static function load_config_defaults(): array {
		if ( null !== self::$config_defaults ) {
			return self::$config_defaults;
		}

		if ( ! \class_exists( '\Newspack_Nodes\Config_Utils' ) ) {
			return [];
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
	 * `<eln:KEY>` topology-token resolver — owned-keys list + per-key
	 * derivation. Used both by the plugin's `register_config_namespace`
	 * call and by `tests/bootstrap.php` so both paths resolve identically.
	 *
	 * Returns null for keys this plugin doesn't own (substrate keys fall
	 * back to the `<config:KEY>` namespace). The substrate wraps the
	 * return in `(string) ($value ?? '')`, so bools surface as '1' / ''
	 * and arrays must be flattened here (no `(string) array`).
	 *
	 * @param string $key Token key after the `eln:` prefix.
	 * @return mixed|null Resolved value, or null if not owned by `eln`.
	 */
	public static function resolve_eln_token( string $key ) {
		/** @var array<string, bool> $own */
		static $own = [
			'is_hub'            => true,
			'stats_mirror_node' => true,
		];
		if ( ! isset( $own[ $key ] ) ) {
			return null;
		}
		$config = self::load_config();

		if ( 'is_hub' === $key ) {
			// A hub is a site whose active topologies include `aggregator`;
			// the substrate's `(string)` wrap surfaces the bool as '1' / ''.
			return \in_array( 'aggregator', \array_keys( \Newspack_Nodes\Bootstrap::get_topologies() ), true );
		}

		return $config[ $key ] ?? null;
	}

	/**
	 * One-time autoload-correction sweep, applying `autoload_for()` to every
	 * application option (schema keys + the explicitly-non-autoloaded set).
	 * Fixes existing installs that persisted these with the wrong flag, once,
	 * guarded by a marker. Hooked on `admin_init`; no-op on WP < 6.6. The
	 * substrate runs its own sweep for the `newspack_nodes_*` keys.
	 */
	public static function correct_option_autoload(): void {
		if ( ! \function_exists( 'wp_set_option_autoload' ) ) {
			return;
		}
		if ( ! empty( \get_option( self::AUTOLOAD_FIXED_OPTION ) ) ) {
			return;
		}
		$options = \array_keys( self::$non_autoloaded_options );
		$schema  = Settings_Schema::get();
		foreach ( $schema->overlay_keys() as $key ) {
			$options[] = $schema->prefix() . $key;
		}
		foreach ( \array_unique( $options ) as $option ) {
			\wp_set_option_autoload( $option, self::autoload_for( $option ) );
		}
		\update_option( self::AUTOLOAD_FIXED_OPTION, '1', false );
	}

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
	 * Get the logs directory path ({base}/logs).
	 *
	 * @api
	 * @return string Validated absolute path to logs directory.
	 */
	public static function get_logs_directory(): string {
		return RuntimeConfig::get_logs_directory();
	}

	/**
	 * Get the locks directory path ({base}/locks).
	 *
	 * @api
	 * @return string Validated absolute path to locks directory.
	 */
	public static function get_locks_directory(): string {
		return RuntimeConfig::get_locks_directory();
	}

	/**
	 * Get the offsets directory path ({base}/offsets).
	 *
	 * @api
	 * @return string Validated absolute path to offsets directory.
	 */
	public static function get_offsets_directory(): string {
		return RuntimeConfig::get_offsets_directory();
	}

}
