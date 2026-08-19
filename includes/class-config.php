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
 * `value()` is the accessor callers should reach for: it validates the key
 * against the shared substrate registry and throws on an undeclared one, so a
 * renamed or typo'd key fails loud instead of limping on a `?? default`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;
use Newspack_Nodes\Config_Utils;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Application configuration: file defaults, the WordPress-option overlay on top
 * of them, and the substrate's effective values merged underneath. Every read
 * goes through the memoized `load_config()`; `reset()` drops both layers.
 */
class Config {

	/** Staging option: custom-event names spokes reported to the hub; read by the admin UI. */
	public const OPTION_DISCOVERED_EVENTS = 'newspack_event_logger_nodes_discovered_events';

	/** Staging option: hook names spokes reported to the hub; read by the admin UI. */
	public const OPTION_DISCOVERED_HOOKS = 'newspack_event_logger_nodes_discovered_hooks';

	/**
	 * Cached config (file defaults + WordPress options + substrate values).
	 *
	 * @var array<string,mixed>|null
	 */
	private static $config = null;

	/**
	 * Cached config defaults from files.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $config_defaults = null;

	/**
	 * Memoized `is_hub`. A process-lifetime constant, but deriving it walks
	 * every active topology's graph — cleared by `reset_local_cache()`.
	 *
	 * @var bool|null
	 */
	private static ?bool $is_hub = null;

	/**
	 * Set while `has_hub_topology()` is deriving, to break re-entrancy.
	 *
	 * @var bool
	 */
	private static bool $deriving_is_hub = false;

	/**
	 * Fully-qualified option names that must NOT autoload — the single source of
	 * truth `autoload_for()` answers from. Both are discovery staging options:
	 * they sit outside `Settings_Schema`, so `load_config()` never reads them,
	 * and only admin-facing paths do. Their values grow with the fleet (every
	 * custom event and hook name any spoke reports), so keeping them out of the
	 * per-request `alloptions` blob is the right trade — one targeted read from
	 * the admin beats bloating every frontend request.
	 *
	 * @var array<string,bool>
	 */
	private static $non_autoloaded_options = [
		self::OPTION_DISCOVERED_EVENTS => true,
		self::OPTION_DISCOVERED_HOOKS  => true,
	];

	/**
	 * Get custom colors with filter applied (for admin UI).
	 *
	 * Applies the `newspack_event_logger_nodes_custom_colors` filter lazily, so
	 * plugins that load after Event Logger can still register their events, then
	 * folds in the events spokes reported to the hub — offered to the operator,
	 * not selected — and sorts the map for the picker.
	 *
	 * @return array<string,mixed> Associative array of event_name => hex_color.
	 */
	public static function get_custom_colors(): array {
		/** @var array<string,mixed> $colors */
		$colors = Core::arr( self::value( 'custom_colors' ) );

		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'newspack_event_logger_nodes_custom_colors', $colors );
			// Validate filter return (any type); color maps are string-keyed.
			/** @var array<string,mixed> $colors */
			$colors = Core::arr( $filtered );
		}

		// Merge discovered events from remote spokes (available, not selected).
		if ( \function_exists( 'get_option' ) ) {
			$discovered = \get_option( self::OPTION_DISCOVERED_EVENTS, [] );
			if ( \is_array( $discovered ) ) {
				foreach ( $discovered as $event => $color ) {
					$event = (string) $event;
					if ( ! isset( $colors[ $event ] ) ) {
						$colors[ $event ] = Core::str( $color, '#ffa726' );
					}
				}
			}
		}

		// Sort alphabetically so events are easier to find in the UI.
		\ksort( $colors, SORT_NATURAL | SORT_FLAG_CASE );

		return $colors;
	}

	/**
	 * `<eln:KEY>` topology-token resolver — owned-keys list + per-key
	 * derivation. Used both by the plugin's `register_config_namespace`
	 * call and by `tests/bootstrap.php` so both paths resolve identically.
	 *
	 * Returns null for keys this plugin doesn't own; substrate keys are
	 * addressed under the `<config:KEY>` namespace instead. Null is not a
	 * value — `Core::resolve_config_token()` treats it as unresolvable and
	 * throws in strict mode (schema-arg defaults) or warns and yields '' in
	 * non-strict mode. A scalar return is cast with `(string)`, so bools
	 * surface as '1' / ''; a non-scalar is unresolvable too, so an
	 * array-valued key must be flattened to a scalar here.
	 *
	 * @param string $key Token key after the `eln:` prefix.
	 * @return mixed|null Resolved value, or null if not owned by `eln`.
	 */
	public static function resolve_eln_token( string $key ) {
		/** @var array<string,bool> $own */
		static $own = [
			'is_hub'                 => true,
			'stats_mirror_node'      => true,
			'stats_mirror_lifetime'  => true,
		];
		if ( ! isset( $own[ $key ] ) ) {
			return null;
		}
		if ( 'is_hub' === $key ) {
			return self::has_hub_topology();
		}

		// Derived, never a constant: a widened stats window widens this too.
		if ( 'stats_mirror_lifetime' === $key ) {
			return (string) ( 2 * self::stats_retention_seconds() );
		}

		return self::load_config()[ $key ] ?? null;
	}

	/**
	 * THE retention window every stats consumer sizes itself by — the memcache
	 * TTLs and the dashboards' time axis both come from here.
	 *
	 * It is the substrate's `min_lifetime`, floored: a legal `min_lifetime` of 0
	 * ("keep nothing extra") is neither a usable TTL nor a drawable axis.
	 *
	 * @api
	 * @return int Retention window in seconds, at least Stats_Store::PREFIX_FLOOR.
	 */
	public static function stats_retention_seconds(): int {
		return \max( Stats_Store::PREFIX_FLOOR, Core::num_int( self::value( 'min_lifetime' ) ) );
	}

	/**
	 * Fail-loud single-key read over THIS plugin's merged config, validated
	 * against the shared substrate registry: an undeclared key throws instead of
	 * limping on a `?? default`. A declared key resolves off the merged config
	 * (each key comes from its owning plugin's defaults + option overlay);
	 * declared-but-unset returns null.
	 *
	 * @api
	 * @param string $key Config key, declared by this plugin or the substrate.
	 * @return mixed
	 * @throws \RuntimeException If $key is not declared by any registered schema.
	 */
	public static function value( string $key ): mixed {
		if ( ! RuntimeConfig::is_declared( $key ) ) {
			throw new \RuntimeException(
				\sprintf( "unknown config key '%s'", \esc_html( $key ) )
			);
		}
		$config = self::load_config();
		return \array_key_exists( $key, $config ) ? $config[ $key ] : null;
	}

	/**
	 * Load configuration from disk + WordPress options.
	 *
	 * Merges the substrate config (`Newspack_Nodes\Config::load_config`) so
	 * callers that read substrate keys (`base_directory`, `num_partitions`,
	 * `memcache_servers`, etc.) keep working without having to know about
	 * the layering split. Substrate values lose to this plugin's own schema
	 * keys, so a name collision resolves in favor of the owner.
	 *
	 * The result is memoized for the process; `reset()` clears it. Returns an
	 * empty array when the substrate is absent — nothing to layer onto.
	 *
	 * @return array<string,mixed> Configuration array.
	 * @throws \RuntimeException If an explicit local config path or value tree is invalid.
	 */
	public static function load_config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		if ( ! \class_exists( '\Newspack_Nodes\Config_Utils' ) ) {
			return [];
		}

		// Import effective substrate values without overriding ELN-owned keys.
		$schema           = Settings_Schema::get();
		$application_keys = \array_fill_keys( $schema->overlay_keys(), true );
		$substrate        = \class_exists( RuntimeConfig::class ) ? RuntimeConfig::load_config() : [];
		$substrate        = \array_diff_key( $substrate, $application_keys );
		$defaults         = \array_merge( self::load_config_defaults(), $substrate );

		// Presence overlay: stored option (even ''/[]/false/0) beats default.
		$config = \Newspack_Nodes\Config_System\Options_Overlay::apply(
			$defaults,
			$schema->overlay_keys(),
			$schema->prefix()
		);

		self::$config = $config;

		return $config;
	}

	/**
	 * Whether any active topology makes this install a hub.
	 *
	 * Two signals, because neither covers both shapes. The stock `aggregator`
	 * ships NO `Remote_Source` nodes — the operator wires them on the console
	 * canvas — so it is only recognisable by name. A deployment that forks the
	 * stock file to change an argument renames it, so its include chain never
	 * says `aggregator`, and only its wired readers give it away.
	 *
	 * Name-matching alone is what silently turned per-server stats off on a
	 * hub running `aggregator-hub` → `aggregator-fdn` → `aggregator-fanout`.
	 *
	 * @return bool True when an active topology aggregates from spokes.
	 */
	private static function has_hub_topology(): bool {
		if ( null !== self::$is_hub ) {
			return self::$is_hub;
		}
		// @longform
		// Re-entrancy, not just caching: graph_for() resolves the config
		// tokens in a `set_*target` line, so a topology naming <eln:is_hub>
		// there would recurse through here until PHP died. flame-builder.tsl
		// already carries `set_is_hub <eln:is_hub>`, spared only because that
		// verb misses the analyzer's `^set_\w*target$` match — one rename
		// away. Claiming "not a hub" while deriving breaks the cycle.
		if ( self::$deriving_is_hub ) {
			return false;
		}
		self::$deriving_is_hub = true;
		try {
			self::$is_hub = self::derive_hub_topology();
		} finally {
			self::$deriving_is_hub = false;
		}
		return self::$is_hub;
	}

	/**
	 * The uncached derivation. Two signals, because neither covers both
	 * shapes — see {@see has_hub_topology()}.
	 *
	 * @return bool True when an active topology aggregates from spokes.
	 */
	private static function derive_hub_topology(): bool {
		foreach ( \array_keys( \Newspack_Nodes\Bootstrap::get_topologies() ) as $active ) {
			$name = \Newspack_Nodes\Core::as_string( $active );
			try {
				// includes() THROWS on a bad .tsl; token resolution must not.
				if ( 'aggregator' === $name
					|| \in_array( 'aggregator', Topology_Analyzer::includes( $name ), true ) ) {
					return true;
				}
				foreach ( Topology_Analyzer::graph_for( $name )['nodes'] as $node ) {
					if ( 'Remote_Source' === ( $node['type'] ?? '' ) ) {
						return true;
					}
				}
			} catch ( \RuntimeException $e ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * Declare this plugin's config keys (schema overlay keys ∪ config-file default
	 * keys) with the shared substrate registry. Hooked to the substrate's
	 * DECLARE_ACTION from the plugin file, so the substrate PULLS the declaration
	 * whenever it derives its declared set — including after a Config::reset(),
	 * which wipes the registry. Declaring once at boot instead would lose these on
	 * the next reload, and would come too late for the profiler's first log line
	 * (plugins_loaded:-10001, ahead of this plugin's loader).
	 */
	public static function register_config_keys(): void {
		if ( ! \class_exists( RuntimeConfig::class ) ) {
			return;
		}
		RuntimeConfig::register_keys( Settings_Schema::get()->overlay_keys() );
		RuntimeConfig::register_keys( \array_keys( self::load_config_defaults() ) );
	}

	/**
	 * Load configuration defaults from file only (no WordPress options).
	 *
	 * Reads the bundled `newspack-event-logger-nodes-config.php`, then overlays
	 * the file named by the `LOCAL_NEWSPACK_NODES_CONF` environment variable
	 * when one is set. That path is validated before it is `require`d, and an
	 * unusable path throws rather than silently leaving the site on defaults.
	 *
	 * @return array<string,mixed> Configuration defaults from file.
	 * @throws \RuntimeException If an explicit local config path or value tree is invalid.
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
		if ( false !== $local_config_file && '' !== $local_config_file ) {
			$validated_path = Config_Utils::validate_config_path(
				$local_config_file,
				'Newspack_Event_Logger_Nodes\\Config'
			);
			if ( null === $validated_path ) {
				throw new \RuntimeException(
					'LOCAL_NEWSPACK_NODES_CONF does not name a canonical readable PHP config file'
				);
			}
			$config = Config_Utils::load_config_file(
				$config,
				$validated_path,
				'Newspack_Event_Logger_Nodes\\Config'
			);
		}

		self::$config_defaults = $config;

		return self::$config_defaults;
	}

	/**
	 * Reset cached config - call before load_config() to get fresh values.
	 *
	 * Resets the substrate Config too so the layered view rebuilds from
	 * scratch. The substrate fires `Newspack_Nodes\Config::RESET_ACTION`, which
	 * the listener the deferred loader registers catches to invalidate THIS
	 * class via `reset_local_cache()` — calling `reset()` from inside that
	 * listener would loop back into the substrate.
	 */
	public static function reset(): void {
		self::reset_local_cache();
		if ( \class_exists( RuntimeConfig::class ) ) {
			RuntimeConfig::reset();
		}
	}

	/**
	 * Clear this class's static cache only — no fan-out. This is what the
	 * substrate's reset listener calls; call `reset()` instead when the
	 * substrate's own cache should go with it.
	 */
	public static function reset_local_cache(): void {
		self::$config          = null;
		self::$config_defaults = null;
		self::$is_hub          = null;
	}

	/**
	 * Whether a given option should be written with `autoload=true`. The write
	 * paths that touch settings options — `Performance_CI_Node`'s `set_setting`
	 * verb and `Discovery_Collector_Node`'s staging writes — ask here instead of
	 * passing a literal, so hot-path scalars stay on the single alloptions query
	 * and the fleet-sized staging options stay off it. The ruleset options are
	 * not covered: `Rule_Set` owns its own inline/pointer tiering.
	 *
	 * @param string $option Fully-qualified option name.
	 * @return bool True to autoload.
	 */
	public static function autoload_for( string $option ): bool {
		return ! isset( self::$non_autoloaded_options[ $option ] );
	}

	/**
	 * Get the logs directory path ({base}/logs).
	 *
	 * @api
	 * @return string Validated absolute path to logs directory.
	 * @throws \RuntimeException If the directory cannot be created or fails the substrate's canonical-path check.
	 */
	public static function get_logs_directory(): string {
		return RuntimeConfig::get_logs_directory();
	}

	/**
	 * Get the locks directory path ({base}/locks).
	 *
	 * @api
	 * @return string Validated absolute path to locks directory.
	 * @throws \RuntimeException If the directory cannot be created or fails the substrate's canonical-path check.
	 */
	public static function get_locks_directory(): string {
		return RuntimeConfig::get_locks_directory();
	}

	/**
	 * Get the offsets directory path ({base}/offsets).
	 *
	 * @api
	 * @return string Validated absolute path to offsets directory.
	 * @throws \RuntimeException If the directory cannot be created or fails the substrate's canonical-path check.
	 */
	public static function get_offsets_directory(): string {
		return RuntimeConfig::get_offsets_directory();
	}

}
