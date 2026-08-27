<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.63.1
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Requires Plugins: newspack-nodes
 * Text Domain: newspack-event-logger-nodes
 * Domain Path: /languages
 *
 * Plugin entry point. Everything here is registration; nothing does work at
 * load time.
 *
 * WordPress loads plugins alphabetically, so this plugin loads BEFORE
 * `newspack-nodes` and no substrate class exists while this file runs. That
 * splits registration three ways:
 *
 * 1. Callbacks the substrate PULLS — `newspack_nodes/declare_config_keys` —
 *    hook with a literal action name, because the class holding the constant
 *    is not loaded yet.
 * 2. Everything that touches a substrate class waits inside
 *    `$_newspack_event_logger_nodes_load`, run on `plugins_loaded` priority 11
 *    and gated on the substrate being present AND new enough.
 * 3. Hooks whose actions only ever fire from substrate code — the
 *    `newspack_nodes/` stderr, request-graph-ready and
 *    reconcile-run actions — register at file scope. Without the substrate the
 *    action never fires, so a presence guard would be dead weight.
 *
 * The admin menu and dashboard enqueues also register at file scope, guarding
 * only on the substrate class they call into.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.63.1' );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_DIR' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_URL' ) ) {
	$newspack_event_logger_nodes_url = \function_exists( 'plugin_dir_url' ) ? \plugin_dir_url( __FILE__ ) : '';
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_URL', $newspack_event_logger_nodes_url );
}

require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

// Substrate pulls this; literal name — we load before newspack-nodes.
if ( \function_exists( 'add_action' ) ) {
	\add_action(
		'newspack_nodes/declare_config_keys',
		[ \Newspack_Event_Logger_Nodes\Config::class, 'register_config_keys' ]
	);
}

/**
 * Deferred bootstrap: every registration that needs a substrate class.
 *
 * Runs on `plugins_loaded` priority 11, once both plugins are loaded. Don't
 * lower the priority. A missing substrate returns silently; one below the
 * version floor leaves an admin notice naming both versions and returns, so
 * the plugin goes dormant instead of fataling on an API that isn't there.
 */
$_newspack_event_logger_nodes_load = static function (): void {
	if ( ! \class_exists( '\\Newspack_Nodes\\Bootstrap' ) ) {
		return;
	}
	// @longform Dormant when too old; 2.35.0 = Table_Node::backed_by(), which
	// Stats_Store and Rule_Set both read their durable tier through; 2.31.0 was
	// Capabilities::TUNE (every verb
	// here declares a role) plus Bootstrap::mount_request_graph(), which the
	// MCP controller builds its graph with — below it every MCP call fatals on
	// an undefined method and every `tune` verb throws "unknown capability
	// role". (2.25.0 was the prior floor: \Newspack_Nodes\Line_Fitter, which
	// Request_Builder and Request_Flight fit their emits through; 2.21.0 before
	// that, for Table_Node::store()/forget().)
	// WordPress does not order plugin updates.
	if ( ! \method_exists( '\\Newspack_Nodes\\Bootstrap', 'version_at_least' )
		|| ! \Newspack_Nodes\Bootstrap::version_at_least( '2.35.0', 'Newspack Event Logger Nodes' ) ) {
		return;
	}

	if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'nodes reqgrep', '\\Newspack_Event_Logger_Nodes\\CLI\\Reqgrep_Command' );
		\WP_CLI::add_command( 'nodes ruleset-bench', '\\Newspack_Event_Logger_Nodes\\CLI\\Ruleset_Bench_Command' );
	}

	// reset_local_cache, not reset(): reset() would re-enter the substrate.
	\add_action(
		\Newspack_Nodes\Config::RESET_ACTION,
		[ \Newspack_Event_Logger_Nodes\Config::class, 'reset_local_cache' ]
	);

	// Give each substrate job its own /jobs/{handler}/{id} request context.
	\add_filter( 'newspack_nodes/job_worker/before_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'begin_job_context_filter' ], 10, 4 );
	\add_action( 'newspack_nodes/job_worker/after_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'end_job_context' ], 10, 3 );

	// Prefix resolves node classes; the dir supplies stock topologies.
	\Newspack_Nodes\Topology_Registry::register_plugin(
		'Newspack_Event_Logger_Nodes\\',
		NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
	);

	// App\ only — the service CIs. Node classes resolve via the prefix above.
	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' );

	// Resolves `<eln:KEY>` .tsl tokens; substrate keys use the `config` ns.
	\Newspack_Nodes\Core::register_config_namespace(
		'eln',
		[ \Newspack_Event_Logger_Nodes\Config::class, 'resolve_eln_token' ]
	);

	// Named callables .tsl index legs reference; TSL has no closures.
	\Newspack_Nodes\Formatters::register(
		'request-index',
		\Newspack_Event_Logger_Nodes\Request_Builder_Node::format_index_entry( ... )
	);
	\Newspack_Nodes\Formatters::register(
		'flame-index',
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::format_index_entry( ... )
	);
	\Newspack_Nodes\Formatters::register(
		'stats-index',
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::format_stats_index_entry( ... )
	);

	// Settings-sync value-resolver filter (writer now lives in the substrate).
	\add_filter(
		'newspack_nodes/settings_sync/value',
		'newspack_event_logger_nodes_resolve_settings_sync_value',
		10,
		2
	);

	// The MCP surface; reaching it takes a scoped session credential.
	\add_action(
		'rest_api_init',
		static function (): void {
			( new \Newspack_Event_Logger_Nodes\App\MCP_Controller() )->register_routes();
		}
	);

	new \Newspack_Event_Logger_Nodes\App\Core();

	if ( \function_exists( 'is_admin' ) && \is_admin() ) {
		new \Newspack_Event_Logger_Nodes\Admin\Admin();
		\Newspack_Event_Logger_Nodes\Current_Request_Overlay::init();
	}
};

\add_action( 'plugins_loaded', $_newspack_event_logger_nodes_load, 11 );

/**
 * Add this plugin's request-scope producers to the substrate's registered set,
 * so the log GC declares — and the Workers dashboard catalogs — the dirs
 * `Log_Manager` writes with no topology Partition node behind them.
 *
 * The registered value is the writer's own dir template, expanded by the
 * substrate over the configured partition count. `jobintake` is `Job_Intake`'s,
 * substrate code that registers itself.
 *
 * @param array<int,string> $producers Producers registered by prior contributors.
 * @return array<int,string>
 */
function newspack_event_logger_nodes_register_log_producers( array $producers ): array {
	return \array_values( \array_unique( \array_merge(
		$producers,
		[ \Newspack_Event_Logger_Nodes\Log_Manager::firehose_dir_template() ]
	) ) );
}
\add_filter(
	'newspack_nodes/registered_log_producers',
	'newspack_event_logger_nodes_register_log_producers'
);

/**
 * Resolve a synced option's value for Settings_Sync_Node at consume time.
 *
 * Ported from the legacy Settings_Sync::maybe_queue_static_sync empty→default
 * logic: a blank ('') or absent (false) value for a synced option resolves to
 * its file-backed default — so blanking a field syncs the *default* rather than
 * '' (which would fail a spoke's typed sanitization). The default key is the
 * option name with the `newspack_event_logger_nodes_` / `newspack_nodes_` prefix
 * stripped, looked up in the OWNING config's defaults (`newspack_nodes_` keys —
 * including `remote_*` spoke geometry, which has its own defaults distinct from
 * the hub's — live in the substrate \Newspack_Nodes\Config, the rest in ELN's).
 * Non-blank values and non-synced options pass through unchanged.
 *
 * @api Hooked to `newspack_nodes/settings_sync/value` by the deferred bootstrap.
 * @param mixed  $value  The raw option value Settings_Sync_Node read (default get_option).
 * @param string $option The local WP-option name.
 * @return mixed The resolved value.
 */
function newspack_event_logger_nodes_resolve_settings_sync_value( $value, string $option ) {
	if ( '' === $value || false === $value ) {
		// Route to the OWNING config's defaults (substrate keys aren't ELN's).
		if ( 0 === \strpos( $option, 'newspack_event_logger_nodes_' ) ) {
			$config_key = \substr( $option, \strlen( 'newspack_event_logger_nodes_' ) );
			$defaults   = \Newspack_Event_Logger_Nodes\Config::load_config_defaults();
		} elseif ( 0 === \strpos( $option, 'newspack_nodes_' ) ) {
			$config_key = \substr( $option, \strlen( 'newspack_nodes_' ) );
			$defaults   = \Newspack_Nodes\Config::load_config_defaults();
		} else {
			$config_key = $option;
			$defaults   = \Newspack_Event_Logger_Nodes\Config::load_config_defaults();
		}
		$value = $defaults[ $config_key ] ?? $value;
	}

	// Inline pointer hooks so the ruleset ships hook-complete to spokes.
	if ( \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES === $option && \is_array( $value ) ) {
		$value = \Newspack_Event_Logger_Nodes\Rule_Set::hydrate_array( $value );
	}

	return $value;
}

/**
 * Give the substrate's minute-cadence reconciliation pass its own request
 * context, so everything it logs during `Bootstrap::reconcile_fleet()` — spawn,
 * lock reconcile, retention, orphan-IPC reaping and every
 * `newspack_nodes/periodic` subscriber — lands in a `/jobs/newspack-nodes`
 * request instead of bleeding into whatever WP-Cron request happened to host it.
 *
 * The shared `$entered` flag keeps the pair honest: an `after` with no matching
 * `before` would resume a context this never suspended, and pop a `$_SERVER`
 * snapshot it never pushed.
 */
( static function (): void {
	$entered = false;
	\add_action(
		'newspack_nodes/before_reconcile',
		static function () use ( &$entered ): void {
			\Newspack_Event_Logger_Nodes\Log_Manager::begin_job_context( 'newspack-nodes' );
			$entered = true;
		}
	);
	\add_action(
		'newspack_nodes/after_reconcile',
		static function () use ( &$entered ): void {
			if ( ! $entered ) {
				return;
			}
			\Newspack_Event_Logger_Nodes\Log_Manager::end_job_context();
			$entered = false;
		}
	);
} )();

// @longform Bridge substrate stderr diagnostics into the Error Log. File-scope
// like the hooks above: the action never fires without the substrate, so no
// presence guard is needed. See Diagnostics_Bridge.
\add_action( 'newspack_nodes/stderr', [ \Newspack_Event_Logger_Nodes\Diagnostics_Bridge::class, 'on_stderr' ] );

/**
 * Mount the three application service CIs onto the request-scope command
 * interpreter the substrate has just built.
 *
 * `HTTP_In_Node::dispatch()` lazy-builds `_router` / `_command_interpreter` /
 * `_http` and then fires `newspack_nodes/request_graph_ready`, which is the
 * first moment a base interpreter exists to hang these off. Each shell name
 * resolves to `\Newspack_Event_Logger_Nodes\App\{name}_Node` through the `App\`
 * namespace the deferred bootstrap registered; the second argument is the node
 * name the dashboards address verbs to.
 *
 * @param \Newspack_Nodes\Command_Interpreter_Node $base_interpreter Request-scope CI.
 */
function newspack_event_logger_nodes_mount_service_cis( \Newspack_Nodes\Command_Interpreter_Node $base_interpreter ): void {
	$base_interpreter->make_node( 'Discovery_CI',   'discovery' );
	$base_interpreter->make_node( 'Performance_CI', 'performance' );
	$base_interpreter->make_node( 'Rules_CI',       'rules' );
}
\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );

/**
 * Register the Event Logger admin menu: a top-level Performance page plus one
 * submenu per React dashboard. Every callback prints a bare mount div; the tree
 * that fills it comes from the `admin_enqueue_scripts` closure below, so a page
 * whose bundle is missing renders empty rather than fataling.
 *
 * The settings page is not here — `Admin\Admin` adds it under Settings.
 */
\add_action(
	'admin_menu',
	static function (): void {
		if ( ! \function_exists( 'add_menu_page' ) ) {
			return;
		}
		if ( ! \class_exists( '\\Newspack_Nodes\\Bootstrap' ) ) {
			return;
		}
		$performance_callback = static fn () => print( '<div id="event-logger-admin" class="event-logger-admin-page"></div>' );
		\add_menu_page(
			'Event Logger',
			'Event Logger',
			'manage_options',
			'event-logger-overview',
			$performance_callback,
			'dashicons-chart-line',
			80
		);
		\add_submenu_page(
			'event-logger-overview',
			'Performance Dashboard',
			'Performance',
			'manage_options',
			'event-logger-overview',
			$performance_callback
		);
		$dashboards = [
			'event-logger-errors'      => [ 'Error Log', 'Errors', '<div id="event-logger-errors" class="event-logger-admin-page"></div>' ],
			'event-logger-gyroscope'   => [ 'Gyroscope', 'Gyroscope', '<div id="event-logger-gyroscope" class="event-logger-gyroscope-page"></div>' ],
			'event-logger-requests'    => [ 'Request Log', 'Request Log', '<div id="event-logger-stream" class="event-logger-stream-page"></div>' ],
		];
		foreach ( $dashboards as $slug => [ $title, $menu_title, $mount_html ] ) {
			\add_submenu_page(
				'event-logger-overview',
				$title,
				$menu_title,
				'manage_options',
				$slug,
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mount_html is a hardcoded constant string from $dashboards above, not user input.
			static fn () => print( $mount_html )
			);
		}
	}
);

/**
 * Enqueue the React tree for whichever Event Logger page is rendering.
 *
 * `$page_to_tree` maps the admin page slug to its `build/{tree}` bundle and
 * doubles as the page gate: an unlisted slug enqueues nothing. The four
 * dashboards style against the substrate's graph sheet; the settings tree needs
 * only the base UI sheet.
 *
 * The substrate's `enqueue_react_page()` performs the mechanics — bundle
 * manifest, CSS sidecar, `NewspackNodesData` localize — and returns the script
 * handle, or null when it declined (wrong page, or no built bundle). The
 * `window.*` payloads below bind to that handle, so they only ship when the
 * bundle did.
 *
 * @param string $hook Current admin page hook suffix; the slug gate is used instead.
 */
\add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( ! \function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		if ( ! \class_exists( '\\Newspack_Nodes\\Bootstrap' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin-page dispatch, no form data processed.
		$page = isset( $_GET['page'] ) && \is_string( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';
		$page_to_tree = [
			'event-logger-overview'                  => 'overview',
			'event-logger-errors'                    => 'error-log',
			'event-logger-gyroscope'                 => 'gyroscope',
			'event-logger-requests'                  => 'requests',
			'newspack-event-logger-nodes'            => 'settings',
		];
		if ( ! \array_key_exists( $page, $page_to_tree ) ) {
			return;
		}
		$tree = $page_to_tree[ $page ];
		$style_deps = [ 'wp-components', 'newspack-nodes-ui' ];
		if ( \in_array( $tree, [ 'overview', 'error-log', 'gyroscope', 'requests' ], true ) ) {
			$style_deps = [ 'wp-components', 'newspack-nodes-graph' ];
		}

		$rest_url      = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
		$nonce         = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'wp_rest' ) : '';
		$restart_nonce = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'newspack_nodes_restart_worker' ) : '';
		$localized     = [
			'restUrl'      => \esc_url_raw( $rest_url ),
			'nonce'        => $nonce,
			'restartNonce'      => $restart_nonce,
			'tree'              => $tree,
			'version'           => \NEWSPACK_NODES_VERSION,
		];

		$handle = \Newspack_Nodes\Admin\Admin::enqueue_react_page(
			[
				'handle'           => "newspack-nodes-{$tree}",
				'page'             => $page,
				'dir'              => NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}",
				'url'              => NEWSPACK_EVENT_LOGGER_NODES_URL . "build/{$tree}",
				'version_fallback' => NEWSPACK_EVENT_LOGGER_NODES_VERSION,
				'style_deps'       => $style_deps,
				'localize'         => $localized,
			]
		);
		if ( null === $handle ) {
			return;
		}

		// Dashboards size their time axis from the retention window.
		$retention_seconds = \Newspack_Event_Logger_Nodes\Config::stats_retention_seconds();
		$hook_categories = [ '_colors' => [], '_patterns' => [] ];
		$hook_categories_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . 'hook_categories.json';
		if ( \file_exists( $hook_categories_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local plugin-bundled file, not a remote URL.
			$decoded = \json_decode( (string) \file_get_contents( $hook_categories_path ), true );
			if ( \is_array( $decoded ) ) {
				$hook_categories = $decoded;
			}
		}
		$custom_colors = \Newspack_Event_Logger_Nodes\Config::get_custom_colors();
		$rest_root = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
		\wp_add_inline_script(
			$handle,
			'window.eventLoggerDashboards = ' . \wp_json_encode( [
				'restUrl'           => $rest_root,
				'nonce'             => $nonce,
				'retentionSeconds'  => $retention_seconds,
			] ) . ';'
			. 'window.eventLoggerHookCategories = ' . \wp_json_encode( $hook_categories ) . ';'
			. 'window.eventLoggerCustomColors = ' . \wp_json_encode( $custom_colors ) . ';',
			'before'
		);

		// settings + overview both render pickers from these window lists.
		if ( 'settings' === $tree || 'overview' === $tree ) {
			$recommended         = \Newspack_Event_Logger_Nodes\Config::value( 'recommended_log_events' );
			$recommended         = \is_array( $recommended ) ? \array_values( \array_filter( $recommended, 'is_string' ) ) : [];
			$custom_colors       = \Newspack_Event_Logger_Nodes\Config::get_custom_colors();
			\wp_add_inline_script(
				$handle,
				'window.newspackNodesRecommendedHooks = ' . \wp_json_encode( $recommended ) . ';'
				. 'window.newspackNodesCustomColors = ' . \wp_json_encode( $custom_colors ) . ';',
				'before'
			);
		}
	}
);
