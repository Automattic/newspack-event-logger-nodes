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

				// -- Instrumentation / Performance Workers ------------------
				// URL filters (log_urls/skip_urls), hook lists
				// (log_events/custom_events/significant_events) and auto-tune
				// thresholds are per-RULE fields inside the
				// `newspack_event_logger_nodes_rules` option, not global
				// settings. No Fields remain in this section.

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
				// The per-URL ruleset. Overlaid so `$config['rules']` seeds an
				// absent option (Rule_Set::load), but the rules editor owns the
				// stored option — it is not a WP Settings API field.
				new Field(
					key: 'rules',
					type: 'array',
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
