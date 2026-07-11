<?php
/**
 * Newspack Event Logger Nodes (application) configuration.
 *
 * App-level overrides. Substrate keys live in newspack-nodes-config.php.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return [
	// Deployment override: restrict admin UI to these usernames.
	'allowed_users'               => [],

	// Logging on/off (app-level; distinct from substrate's topologies list).
	'enable_logging'              => true,

	// Per-URL ruleset seed; skips out-specify '/' (worker IPC + wp-cron).
	'rules'                       => [
		[ 'pattern' => '/wp-json/newspack-nodes/v1/command', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/messages/stream', 'action' => 'skip' ],
		[ 'pattern' => '/wp-json/newspack-nodes/v1/workers/spawn', 'action' => 'skip' ],
		[ 'pattern' => '/wp-cron.php', 'action' => 'skip' ],
		[ 'pattern' => '/', 'action' => 'log' ],
	],

	// Hook categorization colors.
	'custom_colors'               => [],

	// Mirror stats to a durable partition (off by default; set the node name).
	'stats_mirror_node'           => '',

	// Debug.
	'log_memory'                  => false,
	'flush_every_line'            => false,
	'hook_start_priority'         => -10000,

	// Recommended hooks populated by the admin "Select Recommended" button.
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
