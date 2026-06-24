<?php
/**
 * Settings_Schema: the application's config declaration — ONE Field per setting.
 *
 * The single source both Config (overlay key-list + autoload sweep) and Admin
 * (register_setting + add_settings_field loops, option_names, delete-on-blank
 * set, reset list) derive from. Replaces the three parallel arrays those two
 * classes used to hand-maintain in lockstep (Config::$option_schema,
 * Admin::$option_names, Admin::$delete_on_blank_options).
 *
 * Substrate keys (base_directory, partitioning, memcache_servers, topologies,
 * and the remote-spoke geometry `remote_*` settings) are owned by the nodes
 * Settings_Schema under `newspack_nodes_*` and reach ELN via the
 * `array_merge(RuntimeConfig::load_config(), …)` layering in Config::load_config
 * — they are NEVER declared here.
 *
 * Labels + section titles are lazy `fn(): string` thunks so building the Schema
 * for overlay_keys() (which a frontend request does via Config) never calls a
 * translation function at load.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Event_Logger_Nodes\Admin\Admin;
use Newspack_Nodes\Config_System\Field;
use Newspack_Nodes\Config_System\Schema;

\defined( 'ABSPATH' ) || exit;

class Settings_Schema {

	/** @var Schema|null Memoized — pure structure (runtime values resolve inside the render callbacks). */
	private static ?Schema $schema = null;

	/** The application settings schema (memoized). */
	public static function get(): Schema {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$general         = 'newspack_event_logger_nodes_general_section';
		$instrumentation = 'newspack_event_logger_nodes_instrumentation_section';
		$workers         = 'newspack_event_logger_nodes_workers_section';
		$debugging       = 'newspack_event_logger_nodes_debugging_section';

		// Literal prefix (matches Admin::OPTION_PREFIX) so building the schema —
		// which a frontend request does via Config::overlay_keys() — never
		// autoloads the admin class just to read a constant. The `Admin::class`
		// callables are compile-time strings; they don't load Admin until invoked
		// in admin context.
		self::$schema = new Schema(
			'newspack_event_logger_nodes_',
			[
				// -- General -------------------------------------------------
				new Field(
					key: 'enable_logging',
					type: 'bool',
					label: static fn(): string => \__( 'Enable Logging', 'newspack-event-logger-nodes' ),
					section: $general,
					// An unchecked box is a real "off" override, not a reset.
					delete_on_blank: false,
					// Cached in the Log_Manager per-process singleton, which every
					// long-lived worker holds → restart every live topology.
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'enable_logging_callback' ],
					register_args: [],
				),

				// -- Instrumentation ----------------------------------------
				new Field(
					key: 'log_urls',
					type: 'array_strings',
					label: static fn(): string => \__( 'Log URLs', 'newspack-event-logger-nodes' ),
					section: $instrumentation,
					delete_on_blank: false,
					// Runtime-only filter; no worker restart.
					restart: [],
					sanitize: [ Admin::class, 'sanitize_array_strings' ],
					render: [ Admin::class, 'log_urls_callback' ],
				),
				new Field(
					key: 'skip_urls',
					type: 'array_strings',
					label: static fn(): string => \__( 'Skip URLs', 'newspack-event-logger-nodes' ),
					section: $instrumentation,
					delete_on_blank: false,
					restart: [],
					sanitize: [ Admin::class, 'sanitize_array_strings' ],
					render: [ Admin::class, 'skip_urls_callback' ],
				),
				new Field(
					key: 'log_events',
					type: 'array_strings',
					label: static fn(): string => \__( 'Log Events', 'newspack-event-logger-nodes' ),
					section: $instrumentation,
					delete_on_blank: false,
					// App\Core in every worker binds these hooks via add_filter at construction — frozen per-process.
					restart: 'all',
					sanitize: [ Admin::class, 'sanitize_array_strings' ],
					render: [ Admin::class, 'log_events_callback' ],
					register_args: [ 'autoload' => false ],
				),
				new Field(
					key: 'custom_events',
					type: 'array_strings',
					label: static fn(): string => \__( 'Custom Events', 'newspack-event-logger-nodes' ),
					section: $instrumentation,
					delete_on_blank: false,
					// Folded into App\Core's instrumented hook set at construction in every worker.
					restart: 'all',
					sanitize: [ Admin::class, 'sanitize_custom_events' ],
					render: [ Admin::class, 'custom_events_callback' ],
					register_args: [ 'autoload' => false ],
				),

				// -- Performance Workers ------------------------------------
				// auto_disable_threshold renders the combined Auto-Tune row
				// (id='auto_tune') for BOTH threshold inputs.
				new Field(
					key: 'auto_disable_threshold',
					type: 'int',
					label: static fn(): string => \__( 'Auto-Tune', 'newspack-event-logger-nodes' ),
					section: $workers,
					id: 'auto_tune',
					// Consumed by Flame_Builder via `cmd flame-builder:config set_auto_tune`.
					restart: [ 'Flame_Builder' ],
					sanitize: [ Admin::class, 'sanitize_int_or_empty' ],
					render: [ Admin::class, 'auto_tune_callback' ],
					register_args: [ 'autoload' => false ],
				),
				// auto_protect_time_threshold IS a registered setting but renders
				// inside the Auto-Tune row above — no own render callback.
				new Field(
					key: 'auto_protect_time_threshold',
					type: 'float',
					section: $workers,
					// Consumed by Flame_Builder via `cmd flame-builder:config set_auto_tune`.
					restart: [ 'Flame_Builder' ],
					sanitize: [ Admin::class, 'sanitize_float_or_empty' ],
					render: null,
					register_args: [ 'autoload' => false ],
				),
				new Field(
					key: 'significant_events',
					type: 'array_strings',
					label: static fn(): string => \__( 'Significant Events', 'newspack-event-logger-nodes' ),
					section: $workers,
					delete_on_blank: false,
					// Cached by App\Core (every worker) AND Flame_Builder — 'all' is the honest superset.
					restart: 'all',
					sanitize: [ Admin::class, 'sanitize_array_strings' ],
					render: [ Admin::class, 'significant_events_callback' ],
					register_args: [ 'autoload' => false ],
				),

				// -- Debugging ----------------------------------------------
				new Field(
					key: 'log_memory',
					type: 'bool',
					label: static fn(): string => \__( 'Log Memory', 'newspack-event-logger-nodes' ),
					section: $debugging,
					delete_on_blank: false,
					// Cached in the Log_Manager per-process singleton (every worker).
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'log_memory_callback' ],
				),
				new Field(
					key: 'flush_every_line',
					type: 'bool',
					label: static fn(): string => \__( 'Flush Every Line', 'newspack-event-logger-nodes' ),
					section: $debugging,
					delete_on_blank: false,
					// Cached in the Log_Manager per-process singleton (every worker).
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'flush_every_line_callback' ],
				),

				// -- Overlay-only / fieldless keys --------------------------
				// Loaded + overlaid, but no settings field (deployment/programmatic).
				new Field(
					key: 'allowed_users',
					type: 'array_strings',
					ui: false,
				),
				new Field(
					key: 'hook_start_priority',
					type: 'int',
					ui: false,
				),
			],
			[
				$general         => [
					'title'    => static fn(): string => \__( 'General', 'newspack-event-logger-nodes' ),
					'callback' => [ Admin::class, 'general_section_callback' ],
				],
				$instrumentation => [
					'title'    => static fn(): string => \__( 'Instrumentation', 'newspack-event-logger-nodes' ),
					'callback' => [ Admin::class, 'instrumentation_section_callback' ],
				],
				$workers         => [
					'title'    => static fn(): string => \__( 'Performance Workers', 'newspack-event-logger-nodes' ),
					'callback' => [ Admin::class, 'workers_section_callback' ],
				],
				$debugging       => [
					'title'    => static fn(): string => \__( 'Debugging', 'newspack-event-logger-nodes' ),
					'callback' => [ Admin::class, 'debugging_section_callback' ],
				],
			]
		);

		return self::$schema;
	}
}
