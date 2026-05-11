<?php
/**
 * Newspack Event Logger Nodes — sample application configuration overlay.
 *
 * This file is loaded at the bottom of `Config::load_config_defaults()`. It
 * holds APPLICATION-level keys only (logging toggles, URL filters, hook
 * lists, custom-event registries, etc.). Substrate keys (`base_directory`,
 * `num_partitions`, `memcache_servers`, `enable_workers`, `aggregator_servers`)
 * live in the substrate plugin's `newspack-nodes-config.php` overlay loaded
 * by `\Newspack_Nodes\Config`.
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

	// ── Topology fleet ─────────────────────────────────────────────────────
	// Worker graphs this plugin contributes to the runtime. Each entry is
	// passed through the `newspack_nodes/topologies` filter; the supervisor
	// spawns one worker per partition per topology.
	//
	// Per-entry keys:
	//   `topology`       (string)  — path to the topology PHP file. Relative
	//                                 paths resolve against the plugin dir;
	//                                 absolute paths are taken as-is so site
	//                                 overrides can ship their own files.
	//   `num_partitions` (?int)    — fleet size for this topology. Omit to
	//                                 inherit the substrate's `num_partitions`
	//                                 (clamped to [1,16]). Aggregator is
	//                                 always a single fan-in regardless.
	//   `stale_timeout`  (int)     — seconds without heartbeat before the
	//                                 supervisor force-respawns this worker.
	//   `gated_by`       (?string) — WP option name that must be truthy for
	//                                 this entry to register. Defaults to ON
	//                                 (option=1) if the option is absent.
	//                                 Used by the aggregator's operator gate.
	//
	// To turn off a shipped topology on a site, override this key in the
	// config file with the entry removed (or with `gated_by` pointing at an
	// off option).
	'topologies'             => [
		'firehose-workers' => [
			'topology'      => 'topologies/firehose-workers.php',
			'stale_timeout' => 60,
		],
		'request-workers'  => [
			'topology'      => 'topologies/request-workers.php',
			'stale_timeout' => 60,
		],
		'job-workers'      => [
			'topology'      => 'topologies/job-workers.php',
			// Long stale_timeout: job handlers (image migration, large
			// evtemplate runs, CDN purges) can block for minutes. With the
			// default 60s, the supervisor would force-respawn the worker
			// mid-handler. 600s matches the legacy newspack-event-jobs
			// reader config.
			'stale_timeout' => 600,
		],
		'aggregator'       => [
			'topology'       => 'topologies/aggregator.php',
			'num_partitions' => 1,
			'stale_timeout'  => 60,
			'gated_by'       => 'newspack_event_logger_nodes_enable_aggregator',
		],
	],

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
