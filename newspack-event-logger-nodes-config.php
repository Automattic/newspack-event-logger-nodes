<?php
/**
 * Deployment OVERRIDES for Newspack Event Logger Nodes: the operator's copy of
 * the application config.
 *
 * Each of the nine keys `Settings_Schema` declares appears below, commented out
 * beside the default the schema declares in code. Uncomment a line to pin that
 * value on this deployment. Pinning is not the same as leaving a key alone — a
 * pinned value survives a later change to the schema default.
 *
 * Four layers, weakest first: the schema default, this file, the file named by
 * `LOCAL_NEWSPACK_NODES_CONF`, and a stored `newspack_event_logger_nodes_<key>`
 * option. PRESENCE decides the option layer rather than truthiness, so a stored
 * '', [] or false beats both files. Every key here takes one; the settings page
 * writes `enable_logging`, `log_memory` and `flush_every_line`, and the rules
 * editor writes `rules`.
 *
 * `Settings_Schema` is what DECLARES a key; this file only overrides one. A key
 * here that the schema does not declare is an operator typo: it is ignored and
 * reported to stderr, never registered and never thrown, because a deploy
 * copies the deployment's own copy of this file over the shipped path and
 * throwing at `plugins_loaded:-10001` would take down wp-admin with everything
 * else.
 *
 * `ConfigSchemaTest` parses the commented entries below back into an array and
 * holds it to `Settings_Schema::defaults()`, key for key and value for value,
 * so a default changed in one file alone fails the suite.
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
	// A `user_login` allowlist over the `manage_options` gate: a listed user
	// still needs the capability, so a demoted account loses access without
	// an edit here. Empty allows every user who holds it.
	// 'allowed_users' => [],

	// The master switch. Off, `Log_Manager` stays inert and no request is
	// logged; the workers keep running, because this gates the request-side
	// writer and not the topologies.
	// 'enable_logging' => true,

	// Seed for the per-URL logging ruleset: the read-time default
	// `Rule_Set::load()` falls back to while the
	// `newspack_event_logger_nodes_rules` option is absent or corrupt. Once
	// the rules editor writes that option, this list stops being consulted,
	// and editing it changes nothing until `Rule_Set::reset()` deletes the
	// option again.
	// `Rule_Matcher` ranks query-bearing patterns above exact patterns (the
	// trailing `?`) above prefixes, so these five exact skips govern their
	// endpoints whatever the list order and whatever `/` says. The four
	// `/wp-json/newspack-nodes/v1/…` routes are the substrate's own command,
	// SSE, and worker-spawn endpoints; logging them would log the logger.
	// No match means skip, and empty means empty: drop the `/` rule and the
	// site logs nothing. A `log` rule may also carry hook lists, custom and
	// significant event names, the two auto-tune thresholds, and the query,
	// HTTP and hook-trace switches — `Rule` is the full shape. A rule's id is
	// derived from its pattern, so declaring one here is pointless.
	// 'rules' => [
	//	[
	//		'pattern' => '/wp-json/newspack-nodes/v1/command?',
	//		'action'  => 'skip',
	//	],
	//	[
	//		'pattern' => '/wp-json/newspack-nodes/v1/log/stream?',
	//		'action'  => 'skip',
	//	],
	//	[
	//		'pattern' => '/wp-json/newspack-nodes/v1/messages/stream?',
	//		'action'  => 'skip',
	//	],
	//	[
	//		'pattern' => '/wp-json/newspack-nodes/v1/workers/spawn?',
	//		'action'  => 'skip',
	//	],
	//	[
	//		'pattern' => '/wp-cron.php?',
	//		'action'  => 'skip',
	//	],
	//	[
	//		'pattern' => '/',
	//		'action'  => 'log',
	//	],
	// ],

	// Custom-event name => hex swatch. `Config::get_custom_colors()` reads it
	// through the `newspack_event_logger_nodes_custom_colors` filter, so a
	// plugin loading after this one can still register its events, then folds
	// in the events spokes reported to the hub. Every dashboard reads the
	// merged map as `window.eventLoggerCustomColors`; the settings and
	// overview event pickers read it as `window.newspackNodesCustomColors`.
	// Hook-category colors are a different thing entirely: they come from
	// `hook_categories.json`.
	// 'custom_colors' => [],

	// Name of the durable Partition that shadows memcache stats and is read
	// back when memcache misses. `flame-builder.tsl` resolves it as the
	// `<eln:stats_mirror_node>` token and hands it to
	// `Flame_Builder_Node::set_stats_target()`, which treats an empty name as
	// off. The topology already builds `flame-stats:partition` for the job.
	// 'stats_mirror_node' => 'flame-stats:partition',

	// Add `peak_mb`, peak memory in MB, to every entry `Log_Manager`'s
	// `complete()` emits — `(complete)` and `(aborted)` alike.
	// 'log_memory' => false,

	// Flush the firehose Topic after each entry, so a crash keeps what it
	// wrote. The Topic otherwise batches, so the guarantee costs one write
	// per entry.
	// 'flush_every_line' => false,

	// The priority `App\Core` binds `hook_start` at. Its `hook_complete`
	// always binds at `PHP_INT_MAX - 1`, so a lower number here widens the
	// measured span over more of the hook's callbacks.
	// 'hook_start_priority' => -10000,

	// Hook names the settings hook picker stars and its "Recommended" button
	// selects — that button REPLACES the current selection with this list.
	// Exposed to JS as `window.newspackNodesRecommendedHooks`.
	// Despite the key's name these are hooks, not custom events, and nothing
	// here binds anything: each rule's own `hooks` list decides what a request
	// instruments. This is a menu, not an instruction.
	// 'recommended_log_events' => [
	//	// Lifecycle.
	//	'after_setup_theme',
	//	'init',
	//	'parse_query',
	//	'parse_request',
	//	'plugins_loaded',
	//	'pre_get_posts',
	//	'send_headers',
	//	'setup_theme',
	//	'shutdown',
	//	'template_include',
	//	'template_redirect',
	//	'widgets_init',
	//	'wp',
	//	'wp_footer',
	//	'wp_head',
	//	'wp_loaded',
	//	// Scripts & Styles.
	//	'wp_enqueue_scripts',
	//	// Content rendering.
	//	'body_class',
	//	'document_title',
	//	'document_title_parts',
	//	'document_title_separator',
	//	'post_class',
	//	'the_content',
	//	'the_permalink',
	//	'the_posts',
	//	// Query & posts.
	//	'found_posts',
	//	'found_posts_query',
	//	// Taxonomies & terms.
	//	'get_terms',
	//	// REST API.
	//	'rest_api_init',
	//	'rest_post_dispatch',
	//	'rest_pre_dispatch',
	//	// Admin & lifecycle (worth profiling on managed-host sites).
	//	'activated_plugin',
	//	'admin_enqueue_scripts',
	//	'admin_footer',
	//	'admin_init',
	//	'admin_menu',
	//	'admin_notices',
	//	'admin_print_footer_scripts',
	//	'after_password_reset',
	//	'authenticate',
	//	'cron_schedules',
	//	'deactivate_jetpack-boost/jetpack-boost.php',
	//	'deactivate_pwa/pwa.php',
	//	'deactivate_woocommerce-memberships/woocommerce-memberships.php',
	//	'deactivate_woocommerce/woocommerce.php',
	//	'deactivate_wordpress-seo/wp-seo.php',
	//	'deactivated_plugin',
	//	'enqueue_block_editor_assets',
	//	'googlesitekit_deactivation',
	//	'load-plugins.php',
	//	'load-themes.php',
	//	'newspack_my_account_version',
	//	'pre_set_site_transient_update_plugins',
	//	'updated_option',
	//	'wp_ajax_woocommerce_load_status_widget',
	//	'wp_authenticate_user',
	//	'wp_maybe_auto_update',
	//	'wp_robots',
	//	'wp_update_plugins',
	//	'wp_version_check',
	//	'wpseo_deactivate',
	//	'wpseo_indexables_unindexed_calculated',
	//	'wpseo_saved_indexable',
	// ],
];
