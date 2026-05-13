<?php
/**
 * Newspack Event Logger Nodes (application) configuration.
 *
 * App-level overrides. Substrate keys live in newspack-nodes-config.php.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

// Surface SSE rate-limit rejects (429s) in PHP error_log so operators can
// see when the slot pool is saturated. Cheap default — site-specific
// observability without forcing every deployment to remember to wire it up.
\add_action( 'newspack_event_logger_nodes/sse_rate_limited', function ( $user_id, $class ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	\error_log( "[EventLoggerNodes] SSE 429: user={$user_id} controller={$class}" );
}, 10, 2 );

return [
	'allowed_users'               => [],

	// Pipeline toggles. Strict `=== true` polarity, default OFF.
	'enable_logging'              => true,
	'enable_jobs'                 => false,
	'enable_workers'              => true,
	'enable_aggregator'           => false,

	// Aggregator spoke list (hubs only; spokes leave empty).
	'aggregator_servers'          => [],
	'remote_num_segments'         => 2,
	'remote_segment_size'         => 10 * 1024 * 1024,
	'remote_max_lifespan'         => 3600,
	'aggregator_verify_ssl'       => true,
	'aggregator_require_https'    => true,

	// URL filtering — skip_urls always wins over log_urls.
	'log_urls'                    => [],
	'skip_urls'                   => [
		'/wp-json/newspack-nodes/v1/firehose',
		'/wp-json/newspack-nodes/v1/workers/spawn',
	],

	// Hooks instrumentation.
	'custom_colors'               => [],
	'custom_events'               => [],
	'log_events'                  => [],

	// Auto-tune (0 = disabled).
	'auto_disable_threshold'      => 0,
	'auto_protect_time_threshold' => 0,
	'significant_events'          => [],

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
		'posts_clauses',
		'posts_clauses_request',
		'posts_distinct',
		'posts_distinct_request',
		'posts_fields',
		'posts_fields_request',
		'posts_groupby',
		'posts_groupby_request',
		'posts_join',
		'posts_join_paged',
		'posts_join_request',
		'posts_orderby',
		'posts_orderby_request',
		'posts_pre_query',
		'posts_request',
		'posts_request_ids',
		'posts_results',
		'posts_search',
		'posts_selection',
		'posts_where',
		'posts_where_paged',
		'posts_where_request',
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

	// Topology fleet. `gated_by` is a config-array key, or an array of
	// keys (any-of). Strict `=== true`.
	'topologies'                  => [
		'firehose-workers' => [
			'topology'      => 'topologies/firehose-workers.php',
			'stale_timeout' => 60,
			'gated_by'      => [ 'enable_workers', 'enable_jobs' ],
		],
		'request-workers'  => [
			'topology'      => 'topologies/request-workers.php',
			'stale_timeout' => 60,
			'gated_by'      => 'enable_workers',
		],
		'job-workers'      => [
			'topology'      => 'topologies/job-workers.php',
			'stale_timeout' => 600,
			'gated_by'      => 'enable_jobs',
		],
		'aggregator'       => [
			'topology'       => 'topologies/aggregator.php',
			'num_partitions' => 1,
			'stale_timeout'  => 60,
			'gated_by'       => 'enable_aggregator',
		],
	],
];
