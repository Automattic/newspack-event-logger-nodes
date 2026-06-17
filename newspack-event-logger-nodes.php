<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.18.0
 * Author: Automattic
 * Requires Plugins: newspack-nodes
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Text Domain: newspack-event-logger-nodes
 * Domain Path: /languages
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.18.0' );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_DIR' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_URL' ) ) {
	$newspack_event_logger_nodes_url = \function_exists( 'plugin_dir_url' ) ? \plugin_dir_url( __FILE__ ) : '';
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_URL', $newspack_event_logger_nodes_url );
}

// Composer classmap autoloader. Registering it at plugin-file load time
// (not deferred to plugins_loaded) lets the 00-newspack-profiler mu-plugin
// resolve LogManager at priority -10001, where it flushes plugin-load
// events before any plugins_loaded callbacks. The autoloader only
// registers an spl callback — actual class loading stays lazy.
require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

// The substrate-presence check runs DEFERRED, in the bootstrap below (on
// plugins_loaded): ELN sorts before newspack-nodes alphabetically, so the
// substrate classes aren't loaded yet at this file-load point — checking here
// always sees "not active" and would disable a healthy install.

/**
 * Operator-override topology dir. Reads `Bootstrap::base_dir()` (config
 * lookup), so it's invoked from the deferred loader (once the runtime is
 * present) plus every entrypoint that reads user_dir() before the catalog
 * publishes or a worker spawns: `rest_api_init` / `admin_init`, and the
 * `newspack_nodes/before_worker_spawn` listener below. Defined above the
 * deferred loader so the loader can `use` it. Static-guarded — repeats free.
 */
$_newspack_event_logger_nodes_register_user_topology_dir = static function (): void {
	static $registered = false;
	if ( $registered ) {
		return;
	}
	$registered = true;
	\Newspack_Nodes\Topology_Registry::set_user_dir(
		\Newspack_Nodes\Bootstrap::base_dir() . '/topologies'
	);
};

/**
 * Worker-execution prerequisites that actually autoload meaningful
 * setup: the hub-side k:"job" → k:"remote_job" rewrite filter (forces
 * StreamMerger autoload) and RemoteManager::init (autoload +
 * `add_action` hookups). Only needed before a worker's Topology_Loader
 * parses the TSL — invoked from the `newspack_nodes/before_worker_spawn`
 * listener below. Class registrations and named formatters used to live
 * here too, but they're cheap `::class`-string + map-insert operations the
 * editor REST schema endpoint also needs at boot, so they moved up.
 *
 * Every callee is independently idempotent
 * (`register_remote_job_rewrite_filter` and `RemoteManager::init`
 * carry their own static guards), so no outer guard is necessary.
 */
$_newspack_event_logger_nodes_register_worker_runtime = static function (): void {
	\Newspack_Event_Logger_Nodes\Stream_Merger_Node::register_remote_job_rewrite_filter();
	\Newspack_Event_Logger_Nodes\Remote_Manager::init();
};

/**
 * Application classes extend `Newspack_Nodes\Node` from the runtime plugin.
 * WordPress loads plugins alphabetically, and `newspack-event-logger-nodes`
 * sorts before `newspack-nodes` — so the runtime isn't available at our
 * plugin-file load time. Defer the runtime-dependent setup (CommandInterpreter
 * registrations, Topology_Registry mounts, App\Core init) to plugins_loaded.
 *
 * (Tests bypass this — they require the runtime explicitly in bootstrap.php.)
 */
$_newspack_event_logger_nodes_load = static function () use (
	$_newspack_event_logger_nodes_register_user_topology_dir
): void {
	// No substrate-presence check here: the only caller is the deferred
	// bootstrap, which already class_exists-gates the runtime before calling.
	if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'nodes reqgrep', '\\Newspack_Event_Logger_Nodes\\CLI\\Reqgrep_Command' );
	}

	// App Config invalidates on the substrate's reset broadcast. Must be
	// registered before any substrate Config::reset() fires (supervisor
	// ticks, admin saves, etc.) — cheap, so it stays at boot.
	\add_action(
		\Newspack_Nodes\Config::RESET_ACTION,
		[ \Newspack_Event_Logger_Nodes\Config::class, 'reset_local_cache' ]
	);

	// Job request-context glue. The substrate's Job_Worker_Node fires these
	// around each handler; the event-logger hooks LogManager's suspend +
	// synthetic /jobs/{handler} $_SERVER rewrite (begin) and resume + restore
	// (end). Job_Worker_Node moved to the substrate; this is the app-specific
	// context that stayed behind.
	\add_action( 'newspack_nodes/job_worker/before_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'begin_job_context' ] );
	\add_action( 'newspack_nodes/job_worker/after_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'end_job_context' ] );

	// One-call substrate registration: registers the application namespace and
	// the stock-topology dir. Topologies are NOT plugin-owned — the substrate
	// catalogs every *.tsl in the dir and the active set is the substrate
	// `topologies` config key (operator overlay, else config-file default).
	\Newspack_Nodes\Topology_Registry::register_plugin(
		'Newspack_Event_Logger_Nodes\\',
		NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
	);

	// Set the operator-override dir now (before the catalog publishes / before spawn)
	$_newspack_event_logger_nodes_register_user_topology_dir();

	// Node-class namespace registration for the service CIs. register_plugin
	// above registered the top-level prefix; the `App\` prefix lets
	// `make_node('Discovery_CI')` etc. resolve the service CIs the
	// `request_graph_ready` mount below constructs by short name. The catalog
	// (Classes_CI) scans the composer classmap under these prefixes to populate
	// the topology-console palette + per-node inspector.
	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' );

	// Topology `<eln:KEY>` token resolver. The substrate owns the `config`
	// namespace (logs_dir, num_partitions, segment_size, etc.); these six
	// app-specific keys resolve via Config::resolve_eln_token so the
	// derivation lives in one place (the bootstrap mirrors this).
	\Newspack_Nodes\Core::register_config_namespace(
		'eln',
		[ \Newspack_Event_Logger_Nodes\Config::class, 'resolve_eln_token' ]
	);

	// Named formatters are similarly cheap (one map insert each) and the
	// captured closures don't autoload until invoked. `request-index`
	// and `flame-index` are read by `Cli::format_index_entry` when an
	// operator inspects a log offset from the REPL — admin context, not
	// worker-only.
	// First-class callable refs: the callees' own typed signatures (incl. by-ref $data) flow through.
	\Newspack_Nodes\Formatters::register(
		'request-index',
		\Newspack_Event_Logger_Nodes\Request_Builder_Node::format_index_entry( ... )
	);
	\Newspack_Nodes\Formatters::register(
		'flame-index',
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::format_index_entry( ... )
	);

	// `SettingsSync::init` listens for `update_option` / `add_option` —
	// which can fire from admin saves, REST settings endpoints, CLI,
	// or programmatic callers. All of these should sync to remote
	// spokes, so the hook stays registered at boot.
	\Newspack_Event_Logger_Nodes\Settings_Sync::init();

	// Wire the substrate's SSE slot pool (generic rate-limiting) onto SSE_Out's
	// 3-Closure seam so the unified SSE endpoint inherits the concurrency cap.
	\Newspack_Nodes\SSE_Slot_Pool::wire();

	// Hook instrumentation — the whole reason this plugin exists. Runs
	// on every request that gets logged.
	new \Newspack_Event_Logger_Nodes\App\Core();

	if ( \function_exists( 'is_admin' ) && \is_admin() ) {
		new \Newspack_Event_Logger_Nodes\Admin\Admin();
	}
};

// Substrate-presence-gated bootstrap, deferred to plugins_loaded:11 — by then
// the substrate is loaded (if active), so it wires ELN; it no-ops if the
// substrate isn't present. Can't run at file-load (ELN loads first). Boots
// immediately only if a reorder already loaded the runtime.
$_newspack_event_logger_nodes_bootstrap = static function () use (
	$_newspack_event_logger_nodes_load,
	$_newspack_event_logger_nodes_register_user_topology_dir,
	$_newspack_event_logger_nodes_register_worker_runtime
): void {
	// newspack-nodes loads after ELN (alphabetical); by plugins_loaded:11 it's
	// present when active. No-op if it isn't — `Requires Plugins` keeps the
	// substrate active on WP 6.5+, and this is the graceful fallback otherwise.
	if ( ! \class_exists( '\Newspack_Nodes\Node' ) ) {
		return;
	}

	$_newspack_event_logger_nodes_load();

	// Re-wire the user-override topology dir to entrypoints that read
	// user_dir() before a worker spawns: REST (save-topology POST hits it
	// directly) + admin pages (list/edit see overrides). Static-guarded.
	\add_action( 'rest_api_init', $_newspack_event_logger_nodes_register_user_topology_dir );
	\add_action( 'admin_init',    $_newspack_event_logger_nodes_register_user_topology_dir );

	// One-time autoload-correction sweep for existing installs.
	\add_action( 'admin_init', [ '\\Newspack_Event_Logger_Nodes\\Config', 'correct_option_autoload' ] );

	// Per-worker runtime init, right before a worker's topology loads.
	\add_action(
		'newspack_nodes/before_worker_spawn',
		static function () use (
			$_newspack_event_logger_nodes_register_user_topology_dir,
			$_newspack_event_logger_nodes_register_worker_runtime
		): void {
			$_newspack_event_logger_nodes_register_user_topology_dir(); // override dir before Topology_Loader::resolve.
			$_newspack_event_logger_nodes_register_worker_runtime();    // hub rewrite filter + RemoteManager init.
		}
	);
};

if ( \class_exists( '\Newspack_Nodes\Node' ) ) {
	// Runtime already loaded (reordered to load first) — bootstrap now.
	$_newspack_event_logger_nodes_bootstrap();
} else {
	\add_action( 'plugins_loaded', $_newspack_event_logger_nodes_bootstrap, 11 );
}

/**
 * Producer basenames written by app runtime code (not by any topology
 * Partition node). These exist whenever the plugin is loaded:
 *
 *   firehose   — LogManager writes via Partition::fill() from regular request
 *                code. No topology declares it; it just appears.
 *   jobintake  — JobIntake::queue() writes large jobs the same way.
 *
 * Topology-declared outputs come from `Topology_Registry::basenames_for()`
 * which parses each TSL's `make_node Partition` lines.
 */
const NEWSPACK_EVENT_LOGGER_NODES_RUNTIME_BASENAMES = [ 'firehose', 'jobintake' ];

/**
 * Named function (not a closure) so tests that wipe `$GLOBALS['_wp_actions']`
 * for isolation can re-attach the same callback by name. Appends the app's
 * request-scope producers to the registered-producer set the substrate's
 * `Log_Cleaner` expands (× config num_partitions) into protected
 * `{base}/logs/{producer}.p{N}/` dirs.
 *
 * @param array<int, string> $producers Producers registered by prior contributors.
 * @return array<int, string>
 */
function newspack_event_logger_nodes_register_log_producers( array $producers ): array {
	// firehose (LogManager) and jobintake (JobIntake) are written from request
	// scope, not by a Partition node, so no topology TSL declares them. Without
	// this registration the substrate's log GC would orphan them.
	return \array_values( \array_unique(
		\array_merge( $producers, NEWSPACK_EVENT_LOGGER_NODES_RUNTIME_BASENAMES )
	) );
}
\add_filter(
	'newspack_nodes/registered_log_producers',
	'newspack_event_logger_nodes_register_log_producers'
);

/**
 * Wrap the substrate's cron-backstop supervisor run with a fresh LogManager
 * job context so the 595s tick logs as `/jobs/newspack-nodes` (tagged
 * `worker_type='supervisor'`, which supplies the `?supervisor` URL suffix
 * downstream — no `/supervisor` path segment to double it) instead of as an
 * untagged `/wp-cron.php` request that counts in global averages.
 *
 * The substrate (Bootstrap::run_supervisor_tick) sets the env var BEFORE
 * firing `before_supervisor_run`, so begin_job_context's fresh LogManager
 * picks up worker_type at init. State carries between the two handlers via
 * a closure-bound static so we don't pollute $GLOBALS.
 */
( static function (): void {
	$entered = false;
	\add_action(
		'newspack_nodes/before_supervisor_run',
		static function () use ( &$entered ): void {
			\Newspack_Event_Logger_Nodes\Log_Manager::begin_job_context( 'newspack-nodes' );
			$entered = true;
		}
	);
	\add_action(
		'newspack_nodes/after_supervisor_run',
		static function () use ( &$entered ): void {
			if ( ! $entered ) {
				return;
			}
			\Newspack_Event_Logger_Nodes\Log_Manager::end_job_context();
			$entered = false;
		}
	);
} )();

// Worker dispatch (`newspack_nodes/spawn_worker`) is handled by the substrate's
// own `Topology_Registry::spawn_worker` (registered once in newspack-nodes.php):
// it builds a Worker_Base + loads the topology via Topology_Loader for any worker
// in the active set (ungated by plugin ownership — topologies aren't owned).
// ELN's per-worker runtime init runs from the `before_worker_spawn` listener
// above, which the substrate handler fires just before the topology loads.

/**
 * REST route registration. The runtime's Bootstrap::register_rest_routes wires
 * the SpawnController; we hook into the same `rest_api_init` action to register
 * the application's read endpoints. Each controller registers its own routes
 * under the `newspack-nodes/v1` (or `newspack-nodes-aggregator/v1`) namespace.
 */
// All firehose-stream / per-feed SSE REST controllers were deleted in M6
// — the four browser dashboards consume the substrate's unified
// `/messages/stream` endpoint directly (M6.3-M6.6) and `RemoteSource`
// (cross-server SSE pull) does the same (M6.7). The substrate's
// SSE_Out + Topology_Stream_Controller handle every SSE need now.

/**
 * Service-CommandInterpreter (interpreter) mounting.
 *
 * The substrate's `HTTP_In::dispatch` lazy-builds the
 * request-scope graph (`_router` / `_command_interpreter` / `_http`)
 * then fires `newspack_nodes/request_graph_ready` so applications can
 * mount their CIs through the base interpreter's `make_node()` — which
 * constructs, names, and sinks each node in one atomic step. Without
 * the sink, verb responses (which walk back via TO=FROM) would have no
 * path to the HTTP_In and silently drop.
 *
 * Each CI is a service-shaped CommandInterpreter — verbs are JSON-in,
 * JSON-out. Stateful deps (Cli, ServerRegistry) are constructor-injected;
 * the memcache-backed CIs read the shared `Core::$memd` handle directly.
 *
 * Named function (not a closure) so tests that wipe
 * `$GLOBALS['_wp_actions']` for isolation can re-attach the same callback.
 */
function newspack_event_logger_nodes_mount_service_cis( \Newspack_Nodes\Command_Interpreter_Node $base_interpreter ): void {
	$registry = \Newspack_Event_Logger_Nodes\Server_Registry::get_instance();

	$base_interpreter->make_node( 'Discovery_CI',   'discovery' );
	$base_interpreter->make_node( 'Status_CI',      'status' );
	$base_interpreter->make_node( 'Settings_CI',    'settings' );
	$base_interpreter->make_node( 'Logger_CI',      'logger' );
	$base_interpreter->make_node( 'Events_CI',      'events' );
	// Service CIs that need programmatic deps (the hub-side Server_Registry)
	// use the Tachikoma uniform-construction pattern: `make_node` calls a
	// no-arg ctor + `arguments()` for scalar config; the registry comes in
	// via public-property assignment immediately after, since `arguments()`
	// only handles round-trippable scalar tokens.
	$servers_ci = $base_interpreter->make_node( 'Servers_CI', 'servers' );
	if ( $servers_ci instanceof \Newspack_Event_Logger_Nodes\App\Servers_CI_Node ) {
		$servers_ci->registry = $registry;
	}
	$aggregator_ci = $base_interpreter->make_node( 'Aggregator_CI', 'aggregator' );
	if ( $aggregator_ci instanceof \Newspack_Event_Logger_Nodes\App\Aggregator_CI_Node ) {
		$aggregator_ci->registry = $registry;
	}
	$base_interpreter->make_node( 'Performance_CI', 'performance' );
}
\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );

/**
 * Hand the substrate's Workers_CI the shared `\Memcached` handle for live
 * cursor-position reads (`Cli::live_position` → `->get()`). Null falls back
 * to on-disk offsetlog reads. The SSE-slot heartbeat verb refreshes via
 * `Sse_Slot_Pool::touch` against `Core::$memd`, independent of this filter.
 */
\add_filter(
	'newspack_nodes/workers_cache',
	static function ( $cache ) {
		return $cache ?? \Newspack_Nodes\Core::$memd;
	}
);

/**
 * Top-level "Event Logger" admin menu and dashboard submenus.
 *
 * Top-level link goes DIRECTLY to the Performance dashboard — no landing
 * page. The first add_submenu_page entry uses the same slug as the parent
 * so it overrides WordPress's auto-generated duplicate "Event Logger"
 * submenu (which would otherwise clutter the submenu list with a redundant
 * link to the parent page).
 *
 * Each submenu prints the React mount-element div the corresponding tree's
 * index.js hooks onto. Trees are enqueued conditionally per-page below so
 * loading wp-admin doesn't drag in 7 dashboard bundles every request.
 *
 * Settings DOES NOT live here — `includes/admin/class-admin.php` registers it
 * under the standard WordPress Settings menu via `add_options_page`. That
 * keeps configuration where users expect it (Settings) while leaving the
 * dashboard navigation focused on telemetry views.
 *
 * Dashboard mount IDs (from src/<tree>/index.js):
 *   event-aggregator        → #event-aggregator-status
 *   performance-dashboards  → #event-logger-admin, #event-logger-errors
 *   performance-gyroscope   → #event-logger-gyroscope
 *   performance-request-log → #event-logger-stream
 *
 * Workers + Raw Logs mount under the substrate's "Nodes" top-level menu;
 * their React bundle is owned by newspack-nodes/src/event-dashboards.
 */
\add_action(
	'admin_menu',
	static function (): void {
		if ( ! \function_exists( 'add_menu_page' ) ) {
			return;
		}
		$performance_callback = static fn () => print( '<div id="event-logger-admin" class="event-logger-admin-page"></div>' );
		\add_menu_page(
			'Event Logger',
			'Event Logger',
			'manage_options',
			'newspack-nodes-performance',
			$performance_callback,
			'dashicons-chart-line',
			80
		);
		// First submenu MUST have slug == parent slug to override the auto-
		// duplicate "Event Logger" entry WordPress generates by default.
		\add_submenu_page(
			'newspack-nodes-performance',
			'Performance Dashboard',
			'Performance',
			'manage_options',
			'newspack-nodes-performance',
			$performance_callback
		);
		$dashboards = [
			'newspack-nodes-errors'      => [ 'Error Log', 'Errors', '<div id="event-logger-errors" class="event-logger-admin-page"></div>' ],
			'newspack-nodes-gyroscope'   => [ 'Gyroscope', 'Gyroscope', '<div id="event-logger-gyroscope" class="event-logger-gyroscope-page"></div>' ],
			'newspack-nodes-stream'      => [ 'Request Log', 'Request Log', '<div id="event-logger-stream" class="event-logger-stream-page"></div>' ],
		];
		// Aggregator submenu is gated on the `Enable Aggregator` checkbox
		// in the Event Logger Settings → Remote Servers section. Default
		// OFF — fresh installs aren't hubs. When unchecked, the dashboard
		// would just show "No spokes configured" so hiding the menu entry
		// is the right UX. Resolve via Config::load_config() (file + WP
		// option merged) so docker-admin's `enable_aggregator => true` in
		// the deployed config-file overrides the absent WP option row
		// (skip_default_writes deletes the row whenever the user-saved
		// value matches the file default, so missing-option IS the steady
		// state on every aggregator-by-default site).
		$cfg = \Newspack_Event_Logger_Nodes\Config::load_config();
		if ( ! empty( $cfg['enable_aggregator'] ) ) {
			$dashboards['newspack-nodes-aggregator'] = [ 'Aggregator', 'Aggregator', '<div id="event-aggregator-status"></div>' ];
		}
		foreach ( $dashboards as $slug => [ $title, $menu_title, $mount_html ] ) {
			\add_submenu_page(
				'newspack-nodes-performance',
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
 * Map admin page slug → built React tree directory under build/. Each tree's
 * index.js is enqueued only when on its own admin page; loading all 7 on
 * every wp-admin page would slow the admin globally for no benefit.
 *
 * Built artifacts live at `build/<tree>/index.js`. Missing build dirs are
 * tolerated silently — a deploy without `npm run build` should not crash
 * wp-admin.
 */
\add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( ! \function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		// Match on the `page` query arg rather than the WP-generated hookname:
		// hookname format depends on the parent menu slug + admin-side context
		// and gets brittle when the parent renames. The `page` arg is the
		// stable contract.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin-page dispatch, no form data processed.
		$page = isset( $_GET['page'] ) && \is_string( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';
		$page_to_tree = [
			'newspack-nodes-performance'             => 'performance-dashboards',
			'newspack-nodes-errors'                  => 'performance-dashboards',
			'newspack-nodes-gyroscope'               => 'performance-gyroscope',
			'newspack-nodes-stream'                  => 'performance-request-log',
			'newspack-nodes-aggregator'              => 'event-aggregator',
			'newspack-event-logger-nodes'            => 'performance-logger',
		];
		if ( ! \array_key_exists( $page, $page_to_tree ) ) {
			return;
		}
		$tree = $page_to_tree[ $page ];

		// The substrate is a hard dependency (loaded by admin_enqueue_scripts time);
		// route the script + index.css + NewspackNodesData localize through its
		// shared registrar, keeping the per-tree extras below.
		if ( ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
			return;
		}

		// Inject REST namespace + nonce so the React tree can call apiFetch
		// with proper auth headers.
		// `restUrl` is the bare `/wp-json/` REST root; consumers append their
		// own namespace (the SSE path-builder in event-dashboards' useFirehose-
		// Connection composes `${restUrl}newspack-nodes/v1/firehose/...`).
		$rest_url            = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
		$aggregator_rest_url = \function_exists( 'rest_url' ) ? \rest_url( 'newspack-nodes-aggregator/v1/' ) : '/wp-json/newspack-nodes-aggregator/v1/';
		$nonce               = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'wp_rest' ) : '';
		// Worker-restart endpoints require a second nonce keyed to a
		// distinct action so a leaked wp_rest nonce can't be used to
		// force-restart workers. WorkerStatus.js reads `restartNonce`
		// and passes it as the `nonce` body param; the server checks via
		// `wp_verify_nonce( $nonce, 'newspack_nodes_restart_worker' )`.
		$restart_nonce       = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'newspack_nodes_restart_worker' ) : '';
		$localized           = [
			// Escaped to match the substrate registrar's restUrl contract; this
			// array is reused for the aggregator-admin bundle's direct localize too.
			'restUrl'           => \esc_url_raw( $rest_url ),
			'aggregatorRestUrl' => $aggregator_rest_url,
			'nonce'             => $nonce,
			'restartNonce'      => $restart_nonce,
			'tree'              => $tree,
			// Runtime version — feeds the shared newspack-nodes Header/overlay (the runtime), not this plugin; no fallback (ELN loads after nodes on plugins_loaded pri 11).
			'version'           => \NEWSPACK_NODES_VERSION,
		];

		$handle = \Newspack_Nodes\Admin\Admin::enqueue_react_page(
			[
				'handle'           => "newspack-nodes-{$tree}",
				'page'             => $page,
				'dir'              => NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}",
				'url'              => NEWSPACK_EVENT_LOGGER_NODES_URL . "build/{$tree}",
				'version_fallback' => NEWSPACK_EVENT_LOGGER_NODES_VERSION,
				'localize'         => $localized,
			]
		);
		if ( null === $handle ) {
			return;
		}

		// Settings page also gets the aggregator-settings.css — legacy plugin
		// enqueued it separately to style `.event-logger-settings-wrap` form
		// elements. The performance-logger CSS already covers most of this,
		// but loading the dedicated settings CSS preserves byte-for-byte parity
		// with the legacy markup.
		if ( 'performance-logger' === $tree ) {
			$settings_css_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . 'build/event-aggregator-settings/settings.css';
			$settings_css_url  = NEWSPACK_EVENT_LOGGER_NODES_URL . 'build/event-aggregator-settings/settings.css';
			if ( \file_exists( $settings_css_path ) ) {
				$settings_css_version = (string) ( \filemtime( $settings_css_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION );
				\wp_enqueue_style( 'newspack-nodes-aggregator-settings', $settings_css_url, [], $settings_css_version );
			}
		}

		// Legacy globals the React trees still reference. The dashboards expect
		// these specific names — `eventLoggerDashboards` for REST root + retention,
		// `eventLoggerHookCategories` for color/pattern lookups in formatUtils,
		// `eventLoggerCustomColors` for custom-event color overrides.
		$retention_seconds = 86400;
		if ( \class_exists( '\\Newspack_Nodes\\Config' ) ) {
			$substrate = \Newspack_Nodes\Config::load_config();
			/** @var int|float|string|bool|null $raw_lifespan */
			$raw_lifespan      = $substrate['max_lifespan'] ?? 86400;
			$retention_seconds = (int) $raw_lifespan;
		}
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
		// Legacy `restUrl` is the bare `/wp-json/` root — JS appends
		// `newspack-nodes/v1/firehose/...` itself, so don't pre-namespace it.
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

		// Settings tree extras: the HookSelectorModal reads
		// `window.newspackNodesRecommendedHooks` to highlight the curated hook
		// set, and CustomEventSelectorModal reads `window.newspackNodesCustomColors`
		// for the registered custom-event registry. Both come from the merged
		// config so any plugin-registered events (via filters) flow through
		// without redeploying.
		if ( 'performance-logger' === $tree ) {
			$cfg                 = \Newspack_Event_Logger_Nodes\Config::load_config();
			$recommended         = $cfg['recommended_log_events'] ?? [];
			$recommended         = \is_array( $recommended ) ? \array_values( \array_filter( $recommended, 'is_string' ) ) : [];
			$custom_colors       = \Newspack_Event_Logger_Nodes\Config::get_custom_colors();
			\wp_add_inline_script(
				$handle,
				'window.newspackNodesRecommendedHooks = ' . \wp_json_encode( $recommended ) . ';'
				. 'window.newspackNodesCustomColors = ' . \wp_json_encode( $custom_colors ) . ';',
				'before'
			);

			// Aggregator admin JS: powers the Test/Toggle/Remove/Add buttons
			// on the Remote Servers section.
			$aggregator_js_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . 'build/aggregator-admin/index.js';
			$aggregator_js_url  = NEWSPACK_EVENT_LOGGER_NODES_URL . 'build/aggregator-admin/index.js';
			$aggregator_asset_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . 'build/aggregator-admin/index.asset.php';
			if ( \file_exists( $aggregator_js_path ) ) {
				$agg_handle = 'newspack-event-logger-nodes-aggregator-admin';
				// Merge wp-scripts auto-detected deps (wp-element etc.) with
				// the jQuery global the DOM-event handlers use — jQuery is
				// global-injected, not import-detected.
				$asset_meta = \file_exists( $aggregator_asset_path )
					? include $aggregator_asset_path
					: [ 'dependencies' => [], 'version' => NEWSPACK_EVENT_LOGGER_NODES_VERSION ];
				if ( ! \is_array( $asset_meta ) ) {
					$asset_meta = [ 'dependencies' => [], 'version' => NEWSPACK_EVENT_LOGGER_NODES_VERSION ];
				}
				/** @var array<string> $detected_deps */
				$detected_deps = \is_array( $asset_meta['dependencies'] ?? null ) ? $asset_meta['dependencies'] : [];
				$deps          = \array_values( \array_unique( \array_merge(
					[ 'jquery' ],
					$detected_deps
				) ) );
				// wp-scripts writes `version` as a string; coerce the (mixed) include value to a scalar before cast.
				$asset_version = $asset_meta['version'] ?? null;
				$asset_version = \is_scalar( $asset_version ) ? $asset_version : null;
				$version       = (string) ( $asset_version ?? ( \filemtime( $aggregator_js_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION ) );
				\wp_enqueue_script(
					$agg_handle,
					$aggregator_js_url,
					$deps,
					$version,
					true
				);
				// The shared CommandClient reads `window.NewspackNodesData` for
				// REST root + nonce; localize it on this handle too so the
				// aggregator-admin bundle has it when there's no parallel
				// React dashboard bundle on the page.
				\wp_localize_script( $agg_handle, 'NewspackNodesData', $localized );
			}
		}
	}
);
