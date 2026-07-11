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

		// Literal prefix, not Admin::OPTION_PREFIX: schema-build skips Admin.
		self::$schema = new Schema(
			'newspack_event_logger_nodes_',
			[
				// -- General -------------------------------------------------
				new Field(
					key: 'enable_logging',
					type: 'bool',
					label: static fn(): string => \__( 'Enable Logging', 'newspack-event-logger-nodes' ),
					section: $general,
					// Cached in LM singleton (every worker) → restart all.
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'enable_logging_callback' ],
					register_args: [],
				),

				// URL/hook/threshold fields are per-rule now — none here.

				// -- Debugging ----------------------------------------------
				new Field(
					key: 'log_memory',
					type: 'bool',
					label: static fn(): string => \__( 'Log Memory', 'newspack-event-logger-nodes' ),
					section: $debugging,
					// Cached in LM per-process singleton (every worker).
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'log_memory_callback' ],
				),
				new Field(
					key: 'flush_every_line',
					type: 'bool',
					label: static fn(): string => \__( 'Flush Every Line', 'newspack-event-logger-nodes' ),
					section: $debugging,
					// Cached in LM per-process singleton (every worker).
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'flush_every_line_callback' ],
				),

				// Overlay-only / fieldless keys: overlaid, no settings field.
				new Field(
					key: 'allowed_users',
					type: 'array_strings',
					ui: false,
				),
				// Per-URL ruleset overlay seeds option; rules editor owns it.
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
