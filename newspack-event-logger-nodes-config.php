<?php
/**
 * Newspack Event Logger Nodes (application) configuration defaults.
 *
 * The file layer beneath this plugin's settings. `Config::load_config_defaults()`
 * requires this array; the file named by the `LOCAL_NEWSPACK_NODES_CONF`
 * environment variable overlays it; and for the keys `Settings_Schema` declares
 * — `enable_logging`, `log_memory`, `flush_every_line`, `allowed_users`,
 * `rules`, `hook_start_priority` — a stored `newspack_event_logger_nodes_*`
 * option beats both. The remaining keys (`custom_colors`, `stats_mirror_node`,
 * `recommended_log_events`) carry no option and no settings field, so this file
 * is the only place an operator sets them.
 *
 * Every key here is registered with the shared substrate registry by
 * `Config::register_config_keys()`, and that registration is what lets
 * `Config::value()` return it: an undeclared key throws instead of limping on a
 * default. Adding a key to this array is therefore also declaring it.
 *
 * Substrate keys — `base_directory`, the partition geometry, `memcache_servers`,
 * the active `topologies` list, the remote-spoke settings — belong to
 * `newspack-nodes-config.php`, and `Config::load_config()` merges their
 * effective values underneath these.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return [
	// user_login allowlist narrowing manage_options; empty narrows nothing.
	'allowed_users'               => [],

	// Master switch: off leaves Log_Manager inert; workers still run.
	'enable_logging'              => true,

	/**
	 * Seed for the per-URL logging ruleset: the read-time default
	 * `Rule_Set::load()` falls back to while the
	 * `newspack_event_logger_nodes_rules` option is absent. Once the rules
	 * editor writes that option, this list stops being consulted — editing it
	 * then changes nothing until the option is deleted.
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
	 */
	'rules'                       => [
		[ 'pattern' => '/wp-json/newspack-nodes/v1/command?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/log/stream?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/messages/stream?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/workers/spawn?', 'action' => 'skip' ],
		[ 'pattern' => '/wp-cron.php?', 'action' => 'skip' ],
		[ 'pattern' => '/', 'action' => 'log' ],
	],

	/**
	 * Custom-event name => hex swatch, offered by the settings custom-event
	 * picker as `window.newspackNodesCustomColors`. `Config::get_custom_colors()`
	 * reads it through the `newspack_event_logger_nodes_custom_colors` filter,
	 * so a plugin loading after this one can still register its events, then
	 * folds in the events spokes reported to the hub. Hook-category colors are
	 * a different thing entirely: they come from `hook_categories.json`.
	 */
	'custom_colors'               => [],

	/**
	 * Name of the durable Partition that shadows memcache stats and is read
	 * back when memcache misses. `flame-builder.tsl` resolves it as the
	 * `<eln:stats_mirror_node>` token and hands it to
	 * `Flame_Builder_Node::set_stats_target()`, which treats an empty name as
	 * off. The topology already builds `flame-stats:partition` for the job.
	 */
	'stats_mirror_node'           => 'flame-stats:partition',

	// Append peak memory (MB) to every `complete` log entry.
	'log_memory'                  => false,

	// Flush the firehose Topic per line, so a crash keeps what it wrote.
	'flush_every_line'            => false,

	// Priority App\Core binds hook_start at; complete binds PHP_INT_MAX-1.
	'hook_start_priority'         => -10000,

	/**
	 * Hook names the settings hook picker stars and its "Recommended" button
	 * selects — that button REPLACES the current selection with this list.
	 * Exposed to JS as `window.newspackNodesRecommendedHooks`.
	 *
	 * Despite the key's name these are hooks, not custom events, and nothing
	 * here binds anything: each rule's own `hooks` list decides what a request
	 * instruments. This is a menu, not an instruction.
	 */
	'recommended_log_events'      => [
		// Lifecycle.
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

		// Scripts & Styles.
		'wp_enqueue_scripts',

		// Content rendering.
		'body_class',
		'document_title',
		'document_title_parts',
		'document_title_separator',
		'post_class',
		'the_content',
		'the_permalink',
		'the_posts',

		// Query & posts.
		'found_posts',
		'found_posts_query',
		'query',

		// Taxonomies & terms.
		'get_terms',

		// REST API.
		'rest_api_init',
		'rest_post_dispatch',
		'rest_pre_dispatch',

		// Admin & lifecycle (commonly worth profiling on managed-host sites).
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
	],
];
