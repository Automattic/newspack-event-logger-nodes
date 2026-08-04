<?php
/**
 * Settings_Schema: the application's config declaration — ONE Field per setting.
 *
 * The single source both Config and Admin derive from. Config takes the overlay
 * key-list and the option prefix; Admin takes the register_setting targets, the
 * add_settings_field loop, the reset set, the delete-on-blank subset, and the
 * per-option worker-restart class. Neither keeps a hand-written parallel list
 * any more — declare a setting here once and every view of it follows.
 *
 * Substrate keys (base_directory, partitioning, memcache_servers, topologies,
 * and the remote-spoke geometry `remote_*` settings) belong to the nodes
 * Settings_Schema under `newspack_nodes_*`. Config::load_config imports their
 * effective values after removing ELN-owned names, so each plugin's option
 * namespace stays authoritative. They are NEVER declared here.
 *
 * Labels and section titles are lazy `fn(): string` thunks: building the Schema
 * for overlay_keys() — which a frontend request does through Config — must never
 * call a translation function at load.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Event_Logger_Nodes\Admin\Admin;
use Newspack_Nodes\Config_System\Field;
use Newspack_Nodes\Config_System\Schema;

\defined( 'ABSPATH' ) || exit;

/**
 * The application's Field/Schema declaration.
 *
 * Three settings render as checkboxes — `enable_logging`, `log_memory`, and
 * `flush_every_line`. Three more keys overlay the config file with no settings
 * field at all (`ui: false`): `allowed_users`, `rules`, and
 * `hook_start_priority`. The URL filters, hook lists, and auto-tune thresholds
 * that were global settings through v0.25 are per-rule fields of the `rules`
 * ruleset now, which the React rules editor owns through the `rules` service CI.
 */
class Settings_Schema {

	/** @var Schema|null Memoized — pure structure (runtime values resolve inside the render callbacks). */
	private static ?Schema $schema = null;

	/**
	 * The application settings schema, built on first call and memoized for the
	 * process. Building it reads no option, calls no translation function, and
	 * loads no Admin class, so the frontend path through Config pays only for
	 * the array construction.
	 */
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
					// Cached in the Log_Manager singleton: restart all workers.
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
					// Cached in the Log_Manager singleton: restart all workers.
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'log_memory_callback' ],
				),
				new Field(
					key: 'flush_every_line',
					type: 'bool',
					label: static fn(): string => \__( 'Flush Every Line', 'newspack-event-logger-nodes' ),
					section: $debugging,
					// Cached in the Log_Manager singleton: restart all workers.
					restart: 'all',
					sanitize: 'absint',
					render: [ Admin::class, 'flush_every_line_callback' ],
				),

				// ui:false: Config overlays these; no settings field.
				new Field(
					key: 'allowed_users',
					type: 'array_strings',
					ui: false,
				),
				// The rules editor owns this option; config only seeds it.
				new Field(
					key: 'rules',
					type: 'array',
					ui: false,
				),
				// App\Core registers its hook_start at this priority.
				new Field(
					key: 'hook_start_priority',
					type: 'int',
					ui: false,
				),
			],
			// Instrumentation and Workers have no field; they never render.
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
