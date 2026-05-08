<?php
/**
 * Newspack Event Logger Nodes — sample configuration overlay.
 *
 * This file is loaded at the bottom of `Config::load_config_defaults()` after
 * the runtime's `newspack_nodes/base_dir` filter has seeded `base_directory`,
 * and BEFORE WordPress-option overrides (`newspack_event_logger_nodes_*`).
 *
 * To override locally without editing this file, point the env var
 * `LOCAL_NEWSPACK_NODES_CONF` at a `.php` file inside `/usr/src/...` or this
 * plugin's own directory and have it `return` an array of overrides. See
 * `Config::validate_config_path()` for the security envelope.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return [
	// ── Access control ─────────────────────────────────────────────────────
	// Restrict admin UI and REST API to these usernames. Empty array = allow
	// any user with manage_options. SECURITY: usernames are case-sensitive,
	// rebound at user creation, and lost on rename — for stronger
	// authorization, prefer user IDs. Kept as a username list for parity
	// with the legacy plugin's setting.
	'allowed_users'          => [],

	// ── Logging toggles ────────────────────────────────────────────────────
	'enable_logging'         => true,
	'enable_jobs'            => true,
	'enable_workers'         => true,

	// ── Directories ────────────────────────────────────────────────────────
	// Default flows from the runtime's `newspack_nodes/base_dir` filter
	// (`/tmp/newspack-nodes` unless overridden). Set explicitly here only if
	// this plugin needs a different root from the runtime substrate.
	// 'base_directory'      => '/tmp/newspack-nodes',

	// ── Memcache (extended; loaded only in 'full' mode) ───────────────────
	// Same default as `Memcached_Cache::DEFAULT_SERVERS`. Override via WP
	// option `newspack_event_logger_nodes_memcache_servers` (newline-
	// separated `host:port`).
	'memcache_servers'       => [
		'127.0.0.1:11211',
	],

	// ── Partitioning + retention ───────────────────────────────────────────
	// `num_partitions`: parallelism factor (CRC32-keyed). Capped at 16.
	// `num_segments`:   segments retained per partition (count cap).
	// `segment_size`:   max bytes per segment before rotation (64MB default).
	// `max_lifespan`:   minimum retention in seconds. Segments are deleted
	//                   only when both over `num_segments` AND older than
	//                   `max_lifespan`. Set to 0 for pure count-based
	//                   retention.
	'num_partitions'         => 1,
	'num_segments'           => 2,
	'segment_size'           => 64 * 1024 * 1024,
	'max_lifespan'           => 86400,

	// ── Remote aggregation (hub/spoke) ─────────────────────────────────────
	// Empty `aggregator_servers` = hub mode disabled (this node is a spoke
	// or standalone). Spokes don't need aggregator config; the hub pulls
	// from them via SSE using credentials stored in ServerRegistry.
	'aggregator_servers'     => [],
	'remote_num_segments'    => 2,
	'remote_segment_size'    => 10 * 1024 * 1024,
	'remote_max_lifespan'    => 3600,
	'aggregator_verify_ssl'  => true,
	'aggregator_allow_http'  => false,

	// ── URL filtering (substring match, not regex) ─────────────────────────
	// `skip_urls` is checked first and always wins over `log_urls`.
	'log_urls'               => [],
	'skip_urls'              => [
		'/wp-json/newspack-nodes/v1/firehose',
		'/wp-json/newspack-nodes/v1/workers/spawn',
	],

	// ── Custom-event registry ──────────────────────────────────────────────
	// `custom_colors`: registry of hooks → flame-graph hex colors. Plugins
	// add via the `newspack_event_logger_nodes_custom_colors` filter.
	// `custom_events`: opt-in subset (assoc keys). `is_enabled()` checks
	// `isset($custom_events[$keyword])`, so names must be KEYS.
	'custom_colors'          => [],
	'custom_events'          => [],

	// ── Hooks to time ──────────────────────────────────────────────────────
	// Empty by default; populate via the admin UI's "Select Recommended"
	// (which copies from `recommended_log_events` below) or via WP option.
	'log_events'             => [],

	// ── Debug toggles ──────────────────────────────────────────────────────
	// `log_memory`:       append peak_mb to every complete() entry.
	// `flush_every_line`: flush write buffers per line (survives OOM/crash).
	'log_memory'             => false,
	'flush_every_line'       => false,

	// Priority for hook_start registration. Negative captures earlier than
	// any normal callback at that hook.
	'hook_start_priority'    => -10000,

	// Recommended hook set used by the admin "Select Recommended" button.
	// Mirrors the legacy plugin's curated set; trim to taste in production.
	'recommended_log_events' => [
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

		// Admin / lifecycle / other.
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
		'deactivated_plugin',
		'enqueue_block_editor_assets',
		'load-plugins.php',
		'load-themes.php',
		'pre_set_site_transient_update_plugins',
		'updated_option',
		'wp_authenticate_user',
		'wp_maybe_auto_update',
		'wp_robots',
		'wp_update_plugins',
		'wp_version_check',
	],
];
