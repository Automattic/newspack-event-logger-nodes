<?php
/**
 * Event Logger configuration: this plugin's config layers, read as one map.
 *
 * `Settings_Schema` is the definition of both the declared set and every
 * default. `newspack-event-logger-nodes-config.php` and
 * `LOCAL_NEWSPACK_NODES_CONF` are override surfaces layered on top of it, so a
 * key the schema does not declare is never registered and `value()` refuses it;
 * a stray in the shipped file is reported besides — see
 * `note_unrecognized_keys()`.
 * Substrate keys (`base_directory`, the partition geometry, `memcache_servers`,
 * `topologies`) belong to `\Newspack_Nodes\Config`.
 *
 * `load_config()` merges the substrate's effective values into what it returns,
 * so a caller reading `$config['base_directory']` never has to know which Config
 * owns the key, and the path helpers delegate outright — one place owns the
 * realpath/symlink check behind them.
 *
 * Reach for `value()`: it validates the key against the shared substrate
 * registry and throws on an undeclared one, so a renamed or typo'd key fails
 * loud instead of limping on a `?? default`.
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
 * Application configuration: schema defaults, the config files over them, the
 * WordPress-option overlay over those, and the substrate's effective values for
 * every key this plugin does not own. Every read goes through the memoized
 * `load_config()`. `reset()` clears this class and the substrate's cache
 * together; `reset_local_cache()` clears only this one.
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
	 * Memoized defaults WITHOUT the WordPress-option overlay: schema defaults,
	 * the shipped config file over them, `LOCAL_NEWSPACK_NODES_CONF` over that.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $config_defaults = null;

	/**
	 * Keys the shipped config named that no Field declares. Reported, never
	 * thrown — see `note_unrecognized_keys()`.
	 *
	 * @var list<string>
	 */
	private static array $unrecognized = [];

	/**
	 * Shipped-config read seam. Tests reassign to inject a file that is not on
	 * disk, which is the only way to exercise an operator's stale key.
	 * Signature: `function (array $base): array`
	 *
	 * @var (\Closure(array<string,mixed>): array<string,mixed>)|null
	 */
	public static ?\Closure $read_shipped_config = null;

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
	 * The event-name-to-color map the dashboards render their swatches from.
	 *
	 * Applies the `newspack_event_logger_nodes_custom_colors` filter lazily, so
	 * plugins that load after Event Logger can still register their events, then
	 * folds in the events spokes reported to the hub — offered to the operator,
	 * not selected — and sorts the map for the picker. Discovery stages a
	 * reported event as a presence flag (`name => true`), so it carries no color
	 * of its own and takes the default swatch here.
	 *
	 * @return array<string,mixed> Event name to color, in case-insensitive
	 *                            natural key order.
	 */
	public static function get_custom_colors(): array {
		/** @var array<string,mixed> $colors */
		$colors = Core::arr( self::value( 'custom_colors' ) );

		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'newspack_event_logger_nodes_custom_colors', $colors );
			// A non-array filter return drops every configured color to [].
			/** @var array<string,mixed> $colors */
			$colors = Core::arr( $filtered );
		}

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

		\ksort( $colors, SORT_NATURAL | SORT_FLAG_CASE );

		return $colors;
	}

	/**
	 * `<eln:KEY>` topology-token resolver: the owned-key list plus each key's
	 * derivation. The plugin's `register_config_namespace` call and
	 * `tests/bootstrap.php` both route through here, so the two resolve alike.
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
	 * @return mixed Resolved value, or null when `eln` does not own $key or the
	 *               merged config carries no value for it.
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
	 * (each key comes from its owning plugin's defaults + option overlay).
	 *
	 * @api
	 * @param string $key Config key, declared by this plugin or the substrate.
	 * @return mixed The key's merged value, or null when it is declared but unset.
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
	 * empty array when the substrate is absent — nothing to layer onto — and
	 * does NOT memoize that, because this plugin sorts ahead of `newspack-nodes`
	 * and a caller reading too early must get the real map on its next read.
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
	 * Whether any active topology makes this install a hub, memoized because
	 * deriving it walks every active topology's graph. `reset_local_cache()`
	 * drops the memo; the guard below covers re-entrancy rather than cost.
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
	 * Derive hub-ness from the active topologies: an `aggregator` topology by
	 * name or include, or any graph carrying a `Remote_Source` node.
	 *
	 * Two signals, because neither covers both shapes. The stock `aggregator`
	 * ships NO `Remote_Source` nodes — the operator wires them on the console
	 * canvas — so only its name gives it away. A deployment that forks the stock
	 * file to change an argument renames it, and no name in a chain of renamed
	 * forks says `aggregator`, so only its wired readers give that one away.
	 * Matching on the name alone reads such a hub as a spoke and turns its
	 * per-server stats off.
	 *
	 * @return bool True when either signal fires.
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
	 * Keys the shipped config named that no Field declares. Forces the defaults
	 * load, so a caller need not have read the config first.
	 *
	 * @api
	 * @return list<string>
	 */
	public static function unrecognized_keys(): array {
		self::load_config_defaults();
		return self::$unrecognized;
	}

	/**
	 * Configuration WITHOUT the WordPress option overlay: the schema's code
	 * defaults, overridden by the shipped config file, then by an operator's
	 * LOCAL_NEWSPACK_NODES_CONF. The schema is the definition; both files are
	 * override surfaces.
	 *
	 * The local path is validated before it is `require`d, and an unusable path
	 * throws rather than silently leaving the site on defaults. An absent
	 * substrate yields an unmemoized empty array, as `load_config()` explains.
	 *
	 * @return array<string,mixed> Configuration defaults from code + files.
	 * @throws \RuntimeException If an explicit local config path or value tree is invalid.
	 */
	public static function load_config_defaults(): array {
		if ( null !== self::$config_defaults ) {
			return self::$config_defaults;
		}

		if ( ! \class_exists( '\Newspack_Nodes\Config_Utils' ) ) {
			return [];
		}

		// Code defaults first; a file only overrides values it names.
		$read   = self::$read_shipped_config ?? self::shipped_config_reader();
		$config = $read( Settings_Schema::get()->defaults() );
		self::note_unrecognized_keys( $config );
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
	 * Record and log an operator's stray key WITHOUT throwing.
	 *
	 * `setup/newspack-event-logger-nodes.sh` copies the deployment's own config
	 * over the shipped path, so this file belongs to the operator. The profiler
	 * drop-in builds `Log_Manager` at `plugins_loaded:-10001`, which loads this
	 * config, so a throw would take down every request, wp-admin
	 * included, the day a key is renamed — recoverable only over SSH. Loud means
	 * visible: stderr once per key set, which is this plugin's whole reporting
	 * surface. It has no Site Health of its own.
	 *
	 * @param array<string,mixed> $config Schema defaults + config-file contents.
	 */
	private static function note_unrecognized_keys( array $config ): void {
		self::$unrecognized = self::unknown_keys( $config );
		if ( [] === self::$unrecognized ) {
			return;
		}
		Core::print_less_often(
			'newspack-event-logger-nodes: unrecognized config key(s) ignored: '
				. \implode( ', ', self::$unrecognized )
		);
	}

	/**
	 * Keys in $config that no Field declares. Public because it answers about
	 * ANY array: the ledger test reconstructs the shipped file's commented-out
	 * keys and holds them to the schema through here.
	 *
	 * @param array<string,mixed> $config Any config array.
	 * @return list<string>
	 */
	public static function unknown_keys( array $config ): array {
		return \array_values(
			\array_diff( \array_keys( $config ), Settings_Schema::get()->overlay_keys() )
		);
	}

	/**
	 * The real read the `$read_shipped_config` seam replaces: the shipped
	 * `newspack-event-logger-nodes-config.php` layered over the given defaults.
	 *
	 * @return \Closure(array<string,mixed>): array<string,mixed>
	 */
	private static function shipped_config_reader(): \Closure {
		return static function ( array $base ): array {
			/** @var array<string,mixed> $base */
			return Config_Utils::load_config_file(
				$base,
				\dirname( __DIR__ ) . '/newspack-event-logger-nodes-config.php',
				'Newspack_Event_Logger_Nodes\\Config'
			);
		};
	}

	/**
	 * Drop every memo, here and in the substrate, so the next `load_config()`
	 * rebuilds the layered view from scratch.
	 *
	 * The substrate fires `Newspack_Nodes\Config::RESET_ACTION`, which the
	 * listener the deferred loader registers catches to invalidate THIS class
	 * via `reset_local_cache()` — calling `reset()` from inside that listener
	 * would loop back into the substrate.
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
		self::$unrecognized    = [];
	}

	/**
	 * Declare this plugin's config keys — the `Settings_Schema` overlay keys, and
	 * NOT the config file's. Deriving from the file makes an operator's typo
	 * self-declaring: the misspelling becomes valid, the real key quietly falls
	 * back to its default, and nothing says so.
	 *
	 * The plugin file hooks this to the substrate's DECLARE_ACTION at its own
	 * file scope, so the substrate PULLS the declaration whenever it derives its
	 * declared set — on the first read, and again after a `Config::reset()`
	 * re-arms that derive. Declaring from the deferred loader instead would come
	 * too late: the profiler's first log line reads config at
	 * `plugins_loaded:-10001`, ahead of the loader's `plugins_loaded:11`, and
	 * `value()` would throw on a real key.
	 */
	public static function register_config_keys(): void {
		if ( ! \class_exists( RuntimeConfig::class ) ) {
			return;
		}
		RuntimeConfig::register_keys( Settings_Schema::get()->overlay_keys() );
	}

	/**
	 * Whether a given option should be written with `autoload=true`. The write
	 * paths that touch settings options — `Performance_CI_Node`'s `set` verb and
	 * `Discovery_Collector_Node`'s staging writes — ask here instead of
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
	 * The logs directory, `{base_directory}/logs`.
	 *
	 * Delegates to the substrate, which owns the base directory and the
	 * canonical-path check, so an application caller asks one Config for
	 * everything and only one place resolves a path.
	 *
	 * @api
	 * @return string Validated absolute path to the logs directory.
	 * @throws \RuntimeException If the directory cannot be created or fails the substrate's canonical-path check.
	 */
	public static function get_logs_directory(): string {
		return RuntimeConfig::get_logs_directory();
	}

	/**
	 * The locks directory, `{base_directory}/locks`.
	 *
	 * Delegates to the substrate for the same reason `get_logs_directory()`
	 * does: one owner for the base directory and the canonical-path check.
	 *
	 * @api
	 * @return string Validated absolute path to the locks directory.
	 * @throws \RuntimeException If the directory cannot be created or fails the substrate's canonical-path check.
	 */
	public static function get_locks_directory(): string {
		return RuntimeConfig::get_locks_directory();
	}

}
