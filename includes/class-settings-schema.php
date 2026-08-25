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
 * `flush_every_line`. Six more keys overlay the config file with no settings
 * field at all (`ui: false`): `allowed_users`, `rules`, `hook_start_priority`,
 * `custom_colors`, `stats_mirror_node`, and `recommended_log_events`. The URL
 * filters, hook lists, and auto-tune thresholds that were global settings
 * through v0.25 are per-rule fields of the `rules` ruleset now, which the React
 * rules editor owns through the `rules` service CI.
 *
 * Every Field carries its `default:` here, and `newspack-event-logger-nodes-
 * config.php` is a commented ledger of the same values — an override surface,
 * never the definition. A default that lives only in that file is null on every
 * install whose file predates the key, because a deploy preserves the
 * operator's copy.
 */
class Settings_Schema {

	/**
	 * Seed for the per-URL logging ruleset: the read-time default
	 * `Rule_Set::load()` falls back to while the
	 * `newspack_event_logger_nodes_rules` option is absent. Once the rules
	 * editor writes that option, this list stops being consulted.
	 *
	 * `Rule_Matcher` ranks query-bearing patterns above exact patterns (the
	 * trailing `?`) above prefixes, so these five exact skips govern their
	 * endpoints whatever the list order and whatever `/` says. The four
	 * `/wp-json/newspack-nodes/v1/…` routes are the substrate's own command,
	 * SSE, and worker-spawn endpoints; logging them would log the logger.
	 *
	 * No match means skip, and empty means empty: drop the `/` rule and the
	 * site logs nothing. A `log` rule may also carry `hooks`, `custom_events`,
	 * `significant_events`, `auto_disable_threshold`, and
	 * `auto_protect_time_threshold` — see `Rule` for the full shape. A rule's
	 * id is derived from its pattern, so declaring one here is pointless.
	 *
	 * @var list<array<string,string>>
	 */
	private const RULES = [
		[ 'pattern' => '/wp-json/newspack-nodes/v1/command?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/log/stream?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/messages/stream?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/workers/spawn?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-cron.php?', 'action' => 'skip' ],
		[ 'pattern' => '/', 'action' => 'log' ],
	];

	/**
	 * Hook names the settings hook picker stars and its "Recommended" button
	 * selects — that button REPLACES the current selection with this list.
	 * Exposed to JS as `window.newspackNodesRecommendedHooks`.
	 *
	 * Despite the key's name these are hooks, not custom events, and nothing
	 * here binds anything: each rule's own `hooks` list decides what a request
	 * instruments. This is a menu, not an instruction.
	 *
	 * Grouped as lifecycle, scripts and styles, content rendering, query and
	 * posts, taxonomies and terms, REST API, then the admin and plugin
	 * lifecycle hooks worth profiling on a managed host.
	 *
	 * @var list<string>
	 */
	private const RECOMMENDED_LOG_EVENTS = [
		'after_setup_theme',
		'init',
		'parse_query',
		'parse_request',
		'plugins_loaded',
		'pre_get_posts',
		'send_headers',
		'setup_theme',
		'shutdown',
		'template_include',
		'template_redirect',
		'widgets_init',
		'wp',
		'wp_footer',
		'wp_head',
		'wp_loaded',
		'wp_enqueue_scripts',
		'body_class',
		'document_title',
		'document_title_parts',
		'document_title_separator',
		'post_class',
		'the_content',
		'the_permalink',
		'the_posts',
		'found_posts',
		'found_posts_query',
		'query',
		'get_terms',
		'rest_api_init',
		'rest_post_dispatch',
		'rest_pre_dispatch',
		'activated_plugin',
		'admin_enqueue_scripts',
		'admin_footer',
		'admin_init',
		'admin_menu',
		'admin_notices',
		'admin_print_footer_scripts',
		'after_password_reset',
		'authenticate',
		'cron_schedules',
		'deactivate_jetpack-boost/jetpack-boost.php',
		'deactivate_pwa/pwa.php',
		'deactivate_woocommerce-memberships/woocommerce-memberships.php',
		'deactivate_woocommerce/woocommerce.php',
		'deactivate_wordpress-seo/wp-seo.php',
		'deactivated_plugin',
		'enqueue_block_editor_assets',
		'googlesitekit_deactivation',
		'load-plugins.php',
		'load-themes.php',
		'newspack_my_account_version',
		'pre_set_site_transient_update_plugins',
		'updated_option',
		'wp_ajax_woocommerce_load_status_widget',
		'wp_authenticate_user',
		'wp_maybe_auto_update',
		'wp_robots',
		'wp_update_plugins',
		'wp_version_check',
		'wpseo_deactivate',
		'wpseo_indexables_unindexed_calculated',
		'wpseo_saved_indexable',
	];

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
					default: true,
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
					default: false,
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
					default: false,
					sanitize: 'absint',
					render: [ Admin::class, 'flush_every_line_callback' ],
				),

				// ui:false: Config overlays these; no settings field.
				new Field(
					key: 'allowed_users',
					type: 'array_strings',
					ui: false,
					default: [],
				),
				// The rules editor owns this option; config only seeds it.
				new Field(
					key: 'rules',
					type: 'array',
					ui: false,
					default: self::RULES,
				),
				// App\Core registers its hook_start at this priority.
				new Field(
					key: 'hook_start_priority',
					type: 'int',
					ui: false,
					default: -10000,
				),
				// Custom-event name => hex swatch, for the event picker.
				new Field(
					key: 'custom_colors',
					type: 'array',
					ui: false,
					default: [],
				),
				// Durable Partition shadowing memcache stats; '' turns it off.
				new Field(
					key: 'stats_mirror_node',
					type: 'text',
					ui: false,
					default: 'flame-stats:partition',
				),
				// The hook picker's "Recommended" menu; binds nothing itself.
				new Field(
					key: 'recommended_log_events',
					type: 'array_strings',
					ui: false,
					default: self::RECOMMENDED_LOG_EVENTS,
				),
			],
			[
				$general         => [
					'title'    => static fn(): string => \__( 'General', 'newspack-event-logger-nodes' ),
					'callback' => [ Admin::class, 'general_section_callback' ],
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
