<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.152.0
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
 *    `$_newspack_event_logger_nodes_load`, run on `plugins_loaded` at
 *    `NEWSPACK_EVENT_LOGGER_NODES_BOOT_PRIORITY` and gated on the substrate
 *    being present AND new enough.
 * 3. Hooks whose actions only ever fire from substrate code — the
 *    `newspack_nodes/` log-producer, stderr, reconcile and request-graph-ready
 *    hooks — register at file scope. Without the substrate the action never
 *    fires, so a presence guard would be dead weight.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.152.0' );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_DIR' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_DIR', \plugin_dir_path( __FILE__ ) );
}
/**
 * The deferred bootstrap's `plugins_loaded` priority: below
 * `hook_start_priority`'s -11000, so a rule naming `plugins_loaded` times
 * every callback after it.
 */
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_BOOT_PRIORITY' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_BOOT_PRIORITY', -11001 );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_URL' ) ) {
	// The test shims define plugin_dir_path() but not plugin_dir_url().
	$newspack_event_logger_nodes_url = \function_exists( 'plugin_dir_url' ) ? \plugin_dir_url( __FILE__ ) : '';
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_URL', $newspack_event_logger_nodes_url );
}

require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

// The substrate PULLS this; a literal name, since this file loads first.
if ( \function_exists( 'add_action' ) ) {
	\add_action(
		'newspack_nodes/declare_config_keys',
		[ \Newspack_Event_Logger_Nodes\Config::class, 'register_config_keys' ]
	);
}

/**
 * Deferred bootstrap: the substrate version gate, then every registration
 * that needs a substrate class, in `newspack_event_logger_nodes_boot()`.
 *
 * Runs on `plugins_loaded` at `NEWSPACK_EVENT_LOGGER_NODES_BOOT_PRIORITY`,
 * once both plugins are loaded: every plugin file is included before
 * `plugins_loaded` fires, so any priority finds the substrate. A missing substrate — or one older than
 * `version_at_least()` itself — returns silently; one below the version floor
 * leaves an admin notice naming both versions and returns, so the plugin goes
 * dormant instead of fataling on an API that isn't there.
 */
$_newspack_event_logger_nodes_load = static function (): void {
	if ( ! \class_exists( '\\Newspack_Nodes\\Bootstrap' ) ) {
		return;
	}
	// @longform Dormant below the 2.122.0 floor. check-substrate-floor.sh holds
	// it to the substrate PHP this plugin calls; a floor that a topology, a
	// stylesheet or a bundle needs is raised by hand. The CHANGELOG records
	// why each raise was made. A floor set too low wires the plugin up against
	// an older substrate and fatals.
	if ( ! \method_exists( '\\Newspack_Nodes\\Bootstrap', 'version_at_least' )
		|| ! \Newspack_Nodes\Bootstrap::version_at_least( '2.122.0', 'Newspack Event Logger Nodes' ) ) {
		return;
	}

	\newspack_event_logger_nodes_boot();
};

\add_action( 'plugins_loaded', $_newspack_event_logger_nodes_load, NEWSPACK_EVENT_LOGGER_NODES_BOOT_PRIORITY );

/**
 * Every registration the deferred bootstrap makes once the substrate is known
 * to be new enough. Named, rather than inline in the closure, so a test can
 * run it past the version gate the harness never reaches.
 *
 * @api Called by the deferred bootstrap on `plugins_loaded`.
 */
function newspack_event_logger_nodes_boot(): void {
	if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
		\newspack_event_logger_nodes_register_cli_commands();
	}

	// reset_local_cache, not reset(): reset() would re-enter the substrate.
	\add_action(
		\Newspack_Nodes\Config::RESET_ACTION,
		[ \Newspack_Event_Logger_Nodes\Config::class, 'reset_local_cache' ]
	);
	// After the cache drops, so the open records re-read the saved ruleset.
	\add_action( \Newspack_Nodes\Config::RESET_ACTION, [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'reload_rules' ], 11 );

	// The prefetch job declines before the context filter opens one for it.
	\add_filter( 'newspack_nodes/job_worker/before_job', [ \Newspack_Event_Logger_Nodes\App\Overview_Prefetch::class, 'admit' ], 5, 4 );

	// Give each substrate job its own /jobs/{handler}/{id} request context.
	\add_filter( 'newspack_nodes/job_worker/before_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'begin_job_context_filter' ], 10, 4 );
	\add_action( 'newspack_nodes/job_worker/after_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'end_job_context' ], 10, 3 );

	// The job that stores the Overview's default answers (decision 37).
	\add_filter(
		'newspack_nodes/job_handlers',
		static fn ( mixed $handlers ): array => [ \Newspack_Event_Logger_Nodes\App\Overview_Prefetch::HANDLER => [ \Newspack_Event_Logger_Nodes\App\Overview_Prefetch::class, 'run' ] ] + \Newspack_Nodes\Core::arr( $handlers )
	);

	// A spawn request learns which worker it became after it began logging.
	\add_action( 'newspack_nodes/worker_identified', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'identify_worker' ], 10, 2 );

	// Every dispatched verb opens its own span in the request's record.
	\Newspack_Event_Logger_Nodes\Diagnostics_Bridge::install();

	// Prefix resolves node classes; the dir supplies stock topologies.
	\Newspack_Nodes\Topology_Registry::register_plugin(
		'Newspack_Event_Logger_Nodes\\',
		NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
	);

	// App\ only — the service CIs. Node classes resolve via the prefix above.
	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' );

	// Resolves `<eln:KEY>` .tsl tokens; substrate keys use `<config:KEY>`.
	\Newspack_Nodes\Core::register_config_namespace(
		'eln',
		[ \Newspack_Event_Logger_Nodes\Config::class, 'resolve_eln_token' ]
	);

	// The named callable the flame index leg references; TSL has no closures.
	\Newspack_Nodes\Formatters::register(
		'flame-index',
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::format_index_entry( ... )
	);

	// The settings-sync value resolver; the substrate owns the writer.
	\add_filter(
		'newspack_nodes/settings_sync/value',
		'newspack_event_logger_nodes_resolve_settings_sync_value',
		10,
		2
	);

	// The dashboards call substrate APIs the version floor guarantees.
	\add_action( 'admin_menu', 'newspack_event_logger_nodes_register_admin_menu' );
	\add_action( 'admin_enqueue_scripts', 'newspack_event_logger_nodes_enqueue_dashboards' );

	// The MCP surface; reaching it takes a scoped session credential.
	\add_action(
		'rest_api_init',
		static function (): void {
			( new \Newspack_Event_Logger_Nodes\App\MCP_Controller() )->register_routes();
		}
	);

	// The drop-in's rows go first, ahead of any span App\Core binds.
	\Newspack_Event_Logger_Nodes\Log_Manager::instance()->flush_plugin_loads();
	\Newspack_Event_Logger_Nodes\Log_Manager::instance()->flush_php_errors();
	new \Newspack_Event_Logger_Nodes\App\Core();

	if ( \function_exists( 'is_admin' ) && \is_admin() ) {
		new \Newspack_Event_Logger_Nodes\Admin\Admin();
		\Newspack_Event_Logger_Nodes\Current_Request_Overlay::init();
	}
}

/**
 * Register this plugin's `wp nodes` verbs under the substrate's `nodes` root.
 *
 * @api Called by the deferred bootstrap; tests drive it against a recorder.
 */
function newspack_event_logger_nodes_register_cli_commands(): void {
	\WP_CLI::add_command( 'nodes reqgrep', '\\Newspack_Event_Logger_Nodes\\CLI\\Reqgrep_Command' );
	\WP_CLI::add_command( 'nodes ruleset-bench', '\\Newspack_Event_Logger_Nodes\\CLI\\Ruleset_Bench_Command' );
}

/**
 * Add this plugin's request-scope producers to the substrate's registered set,
 * so the log GC declares — and the Workers dashboard catalogs — the dirs
 * `Log_Manager` writes with no topology Partition node behind them.
 *
 * The registered value is the writer's own dir template, which the substrate
 * expands over the configured partition count. The substrate registers its own
 * the same way, for `Job_Intake`'s feed and the alerts log.
 *
 * @param array<int,string> $producers Producers registered by prior contributors.
 * @return array<int,string> The same set, plus this plugin's firehose template.
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
 * Resolve a synced option's value for `Settings_Sync_Node` at consume time.
 *
 * Two transforms, and every other value passes through untouched. A blank ('')
 * or absent (false) value of one of this plugin's options resolves to its
 * file-backed default, so blanking a field syncs the DEFAULT rather than '',
 * which would fail a spoke's typed sanitization. The default key is the option
 * name with the `newspack_event_logger_nodes_` prefix stripped, looked up in
 * this plugin's config defaults. Every other option passes through: a
 * `newspack_nodes_` option arrives already resolved by `Settings_Sync_Node`.
 * Second, the ruleset arrives hydrated, so a spoke reads every rule's hooks
 * inline instead of a pointer into an option only the hub holds.
 *
 * @api Hooked to `newspack_nodes/settings_sync/value` by the deferred bootstrap.
 * @param mixed  $value  The raw option value Settings_Sync_Node read (default get_option).
 * @param string $option The local WP-option name.
 * @return mixed The resolved value.
 */
function newspack_event_logger_nodes_resolve_settings_sync_value( $value, string $option ) {
	$own = 0 === \strpos( $option, 'newspack_event_logger_nodes_' );
	if ( $own && ( '' === $value || false === $value ) ) {
		$config_key = \substr( $option, \strlen( 'newspack_event_logger_nodes_' ) );
		$value      = \Newspack_Event_Logger_Nodes\Config::load_config_defaults()[ $config_key ] ?? $value;
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
 * backlog wake, lock reconcile, retention, orphan-IPC reaping and every
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
 * `Bootstrap::mount_request_graph()` lazy-builds `_router` and
 * `_command_interpreter`, then fires `newspack_nodes/request_graph_ready` — the
 * first moment a base interpreter exists to hang these off. Every command door
 * goes through it, so the `/command` route and the MCP controller both arrive
 * here. Each shell name resolves to
 * `\Newspack_Event_Logger_Nodes\App\{name}_Node` through the `App\` namespace
 * the deferred bootstrap registered; the second argument is the node name the
 * dashboards address verbs to.
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
 * that fills it comes from `newspack_event_logger_nodes_enqueue_dashboards()`,
 * so a page whose bundle is missing renders empty rather than fataling.
 *
 * The settings page is not here — `Admin\Admin` adds it under Settings.
 *
 * @api Hooked to `admin_menu` by the deferred bootstrap.
 */
function newspack_event_logger_nodes_register_admin_menu(): void {
	if ( ! \function_exists( 'add_menu_page' ) ) {
		return;
	}
	// The substrate owns this gate: MANAGE, narrowed by `allowed_users`.
	if ( ! \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::MANAGE ) ) {
		return;
	}
	$manage_cap = \Newspack_Nodes\Capabilities::cap_for( \Newspack_Nodes\Capabilities::MANAGE );
	// Notices go after `.wp-header-end`; without one, inside the app.
	$header_end           = '<hr class="wp-header-end">';
	$performance_callback = static fn () => print( $header_end . '<div id="event-logger-admin" class="event-logger-admin-page"></div>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded markup.
	\add_menu_page(
		'Event Logger',
		'Event Logger',
		$manage_cap,
		'event-logger-overview',
		$performance_callback,
		'dashicons-chart-line',
		80
	);
	\add_submenu_page(
		'event-logger-overview',
		'Performance Dashboard',
		'Performance',
		$manage_cap,
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
			$manage_cap,
			$slug,
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mount_html is a hardcoded constant string from $dashboards above, not user input.
			static fn () => print( $header_end . $mount_html )
		);
	}
}

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
 * @api Hooked to `admin_enqueue_scripts` by the deferred bootstrap.
 * @param string $hook Current admin page hook suffix; unused, since the `page` slug gates instead.
 */
function newspack_event_logger_nodes_enqueue_dashboards( string $hook ): void {
	if ( ! \function_exists( 'wp_enqueue_script' ) ) {
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
	if ( 'settings' === $tree ) {
		// The per-field reset toggle rides the settings form alone.
		\Newspack_Nodes\Config_System\Field_Reset_Assets::enqueue();
	}

	$rest_url      = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
	$nonce         = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'wp_rest' ) : '';
	$restart_nonce = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'newspack_nodes_restart_worker' ) : '';
	$localized     = [
		'restUrl'      => \esc_url_raw( $rest_url ),
		'nonce'        => $nonce,
		'restartNonce'      => $restart_nonce,
		'tree'              => $tree,
		'version'           => \NEWSPACK_EVENT_LOGGER_NODES_VERSION,
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

	$rest_root = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
	\wp_add_inline_script(
		$handle,
		'window.eventLoggerDashboards = ' . \wp_json_encode( [
			'restUrl' => $rest_root,
			'nonce'   => $nonce,
		] ) . ';'
		. \Newspack_Event_Logger_Nodes\Config::span_palette_js(),
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
