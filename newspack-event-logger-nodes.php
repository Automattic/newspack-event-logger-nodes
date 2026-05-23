<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.3.0
 * Requires Plugins: newspack-nodes
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.3.0' );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_DIR' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_URL' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_URL', \function_exists( 'plugin_dir_url' ) ? \plugin_dir_url( __FILE__ ) : '' );
}

// Composer classmap autoloader. Registering it at plugin-file load time
// (not deferred to plugins_loaded) lets the 00-newspack-profiler mu-plugin
// resolve LogManager at priority -10001, where it flushes plugin-load
// events before any plugins_loaded callbacks. The autoloader only
// registers an spl callback — actual class loading stays lazy.
require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

/**
 * Application classes extend `Newspack_Nodes\Node` from the runtime plugin.
 * WordPress loads plugins alphabetically, and `newspack-event-logger-nodes`
 * sorts before `newspack-nodes` — so the runtime isn't available at our
 * plugin-file load time. Defer the runtime-dependent setup (CommandInterpreter
 * registrations, Topology_Registry mounts, App\Core init) to plugins_loaded.
 *
 * (Tests bypass this — they require the runtime explicitly in bootstrap.php.)
 */
$_newspack_event_logger_nodes_load = static function (): void {
	if ( ! \class_exists( '\Newspack_Nodes\Node' ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log(
			'newspack-event-logger-nodes: \Newspack_Nodes\Node missing — newspack-nodes inactive?'
		);
		return;
	}

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

	// Stock-topology dir registration is a single array append with no
	// config dependency, so it stays at boot — anyone calling
	// `Topology_Registry::resolve()` (admin, REST, tests, CLI, supervisor)
	// finds the stock files. The matching `set_user_dir()` requires
	// `Bootstrap::base_dir()` (which hits Config) and is deferred to
	// `$_newspack_event_logger_nodes_register_user_topology_dir` below.
	\Newspack_Nodes\Topology_Registry::register_stock_dir(
		NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
	);

	// Node-class registrations. These are `::class` constants (compile-
	// time FQCN strings) into a static hashmap, so they don't autoload
	// anything — virtually free at boot. They have to be at boot because
	// the topology console's REST schema endpoint (admin, not worker)
	// reads the class registry to populate the palette + per-node
	// inspector. Deferring them to spawn_worker left the editor unable
	// to render any application node — palette only listed substrate
	// nodes, and selecting an existing RequestBuilder showed
	// "No constructor arguments. No verbs registered."
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'AutoTuner',       \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'FlameBuilder',    \Newspack_Event_Logger_Nodes\Flame_Builder_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'HealthCheckTick', \Newspack_Event_Logger_Nodes\Health_Check_Tick_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'JobRouter',       \Newspack_Event_Logger_Nodes\Job_Router_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'JobWorker',       \Newspack_Event_Logger_Nodes\Job_Worker_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'RequestBuilder',  \Newspack_Event_Logger_Nodes\Request_Builder_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'RemoteSource',    \Newspack_Event_Logger_Nodes\Remote_Source_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'StreamMerger',    \Newspack_Event_Logger_Nodes\Stream_Merger_Node::class );
	// Service CIs — discoverable to `$base_ci->make_node(...)`, which
	// constructs + names + sinks each in one step from the
	// `newspack_nodes/request_graph_ready` hook below.
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Discovery_CI',    \Newspack_Event_Logger_Nodes\App\Discovery_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Status_CI',       \Newspack_Event_Logger_Nodes\App\Status_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Settings_CI',     \Newspack_Event_Logger_Nodes\App\Settings_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Logger_CI',       \Newspack_Event_Logger_Nodes\App\Logger_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Events_CI',       \Newspack_Event_Logger_Nodes\App\Events_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Servers_CI',      \Newspack_Event_Logger_Nodes\App\Servers_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Aggregator_CI',   \Newspack_Event_Logger_Nodes\App\Aggregator_CI_Node::class );
	\Newspack_Nodes\Command_Interpreter_Node::register_class( 'Performance_CI',  \Newspack_Event_Logger_Nodes\App\Performance_CI_Node::class );

	// Named formatters are similarly cheap (one map insert each) and the
	// captured closures don't autoload until invoked. `request-index`
	// and `flame-index` are read by `Cli::format_index_entry` when an
	// operator inspects a log offset from the REPL — admin context, not
	// worker-only.
	\Newspack_Nodes\Formatters::register(
		'request-index',
		static fn ( $line, $position, &$data = null )
			=> \Newspack_Event_Logger_Nodes\Request_Builder_Node::format_index_entry( $line, $position, $data )
	);
	\Newspack_Nodes\Formatters::register(
		'flame-index',
		static fn ( $line, $position, &$data = null )
			=> \Newspack_Event_Logger_Nodes\Flame_Builder_Node::format_index_entry( $line, $position, $data )
	);

	// `SettingsSync::init` listens for `update_option` / `add_option` —
	// which can fire from admin saves, REST settings endpoints, CLI,
	// or programmatic callers. All of these should sync to remote
	// spokes, so the hook stays registered at boot.
	\Newspack_Event_Logger_Nodes\Settings_Sync::init();

	// Set the one shared Memcached handle on the substrate Core, then wire
	// the substrate's SSE slot pool (generic rate-limiting) onto SSE_Out's
	// 3-Closure seam so the unified SSE endpoint inherits the concurrency cap.
	newspack_event_logger_nodes_init_memcached();
	\Newspack_Nodes\SSE_Slot_Pool::wire();

	// Hook instrumentation — the whole reason this plugin exists. Runs
	// on every request that gets logged.
	new \Newspack_Event_Logger_Nodes\App\Core();

	if ( \function_exists( 'is_admin' ) && \is_admin() ) {
		new \Newspack_Event_Logger_Nodes\Admin\Admin();
	}
};

if ( \class_exists( '\Newspack_Nodes\Node' ) ) {
	// Loaded after newspack-nodes (later alphabetically, but possible if reordered).
	$_newspack_event_logger_nodes_load();
} else {
	\add_action( 'plugins_loaded', $_newspack_event_logger_nodes_load, 11 );
}

/**
 * Operator-override topology dir. Reads `Bootstrap::base_dir()` (config
 * lookup), so it's deferred to the entrypoints that actually need user
 * overrides to shadow stock — the `newspack_nodes/topologies` filter
 * callback and the `newspack_nodes/spawn_worker` action handler.
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

// Wire the closure to every entrypoint that may read user_dir() before
// the `newspack_nodes/topologies` filter or `spawn_worker` action fires:
// REST handlers (admin "save topology" POST → TopologiesController hits
// user_dir() directly; without this it returned 500 "Topology_Registry
// has no writable user dir.") and admin pages (list/edit UIs need
// describe()/list() to see user overrides). The closure's static guard
// keeps repeated registrations free.
\add_action( 'rest_api_init', $_newspack_event_logger_nodes_register_user_topology_dir );
\add_action( 'admin_init',    $_newspack_event_logger_nodes_register_user_topology_dir );

// One-time autoload-correction sweep for existing installs (guarded; off
// the frontend path). See Config::correct_option_autoload().
\add_action( 'admin_init', [ '\\Newspack_Event_Logger_Nodes\\Config', 'correct_option_autoload' ] );

/**
 * Worker-execution prerequisites that actually autoload meaningful
 * setup: the hub-side k:"job" → k:"remote_job" rewrite filter (forces
 * StreamMerger autoload) and RemoteManager::init (autoload +
 * `add_action` hookups). Only needed inside the spawn_worker action
 * handler before Topology_Loader parses the TSL. Class registrations
 * and named formatters used to live here too, but they're cheap
 * `::class`-string + map-insert operations that the editor REST schema
 * endpoint also needs at boot, so they moved up.
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
 * Topology registration. Reads the substrate's flat `topologies`
 * config list (a flat array of TSL topology names) and emits one
 * descriptor per name, with per-topology metadata sourced from each
 * TSL file's `var` frontmatter via Topology_Registry::frontmatter().
 *
 * The runtime's Bootstrap::expand_workers() reads the resulting
 * array and spawns workers per partition; this plugin file owns the
 * filter wiring. Worker dispatch (the `newspack_nodes/spawn_worker`
 * action handler below) loads each topology via Topology_Loader.
 */
\add_filter(
	'newspack_nodes/topologies',
	static function ( array $topologies ) use ( $_newspack_event_logger_nodes_register_user_topology_dir ): array {
		$_newspack_event_logger_nodes_register_user_topology_dir();
		// Publish ONLY the application's file-default topologies. The
		// substrate's Bootstrap layers `get_option(newspack_nodes_topologies)`
		// on top to compute the active set — operator overrides live in
		// the WP option and are owned by the substrate. The app's job
		// is just to describe what topologies exist and their default
		// metadata.
		// Topology name list — read MERGED app config (file defaults
		// overlaid by any WP-option override). The substrate's
		// `Bootstrap::get_topologies()` further filters by the
		// substrate-owned `newspack_nodes_topologies` operator-overlay
		// option, but the catalog this filter publishes IS the
		// authoritative "what topologies exist" list — and operators
		// must always be able to override file defaults via WP options.
		$config = \Newspack_Event_Logger_Nodes\Config::load_config();
		$names  = $config['topologies'] ?? [];

		// Partition count — substrate-owned option. Already merged via
		// `\Newspack_Event_Logger_Nodes\Config::load_config()`'s
		// substrate-overlay step (it composes substrate config first),
		// so reading $config['num_partitions'] picks up the operator
		// override for `newspack_nodes_num_partitions` automatically.
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$num_partitions = \max( 1, \min( 16, $num_partitions ) );
		if ( ! \is_array( $names ) ) {
			return $topologies;
		}

		foreach ( $names as $name ) {
			if ( ! \is_string( $name ) || '' === $name ) {
				continue;
			}
			$entry = \Newspack_Nodes\Topology_Registry::synthesize_entry( $name, $num_partitions, 60 );
			if ( null !== $entry ) {
				$topologies[ $name ] = $entry;
			}
		}
		return $topologies;
	}
);

/**
 * Always-expected basenames written by app runtime code (not by any
 * topology Partition node). These exist whenever the plugin is loaded:
 *
 *   firehose.log   — LogManager writes via Partition::fill() from regular
 *                    request code. No topology declares it; it just appears.
 *   jobintake.log  — JobIntake::queue() writes large jobs the same way.
 *
 * Topology-declared outputs come from `Topology_Registry::basenames_for()`
 * which parses each TSL's `make_node Partition` lines.
 */
const NEWSPACK_EVENT_LOGGER_NODES_RUNTIME_BASENAMES = [ 'firehose', 'jobintake' ];

/**
 * Named function (not a closure) so tests that wipe `$GLOBALS['_wp_actions']`
 * for isolation can re-attach the same callback by name. Builds the union of
 * (app runtime basenames) + (every active topology's Partition basenames) +
 * (every active worker's topology basenames). The substrate's `Log_Cleaner`
 * orphans every `{base}/logs/*.log/` directory NOT in the result.
 */
function newspack_event_logger_nodes_expected_log_basenames( array $basenames ): array {
	// Substrate's `Log_Cleaner::expected_basenames()` seeds $basenames with
	// the topology-derived set (every active topology's Partition basenames
	// + every still-running worker's topology's basenames). The app's only
	// job here is appending the runtime-pinned basenames it manages outside
	// the topology graph — `firehose` (LogManager) and `jobintake`
	// (JobIntake) are written from request scope, not by a Partition node,
	// so no topology TSL declares them.
	return \array_values( \array_unique(
		\array_merge( $basenames, NEWSPACK_EVENT_LOGGER_NODES_RUNTIME_BASENAMES )
	) );
}
\add_filter(
	'newspack_nodes/expected_log_basenames',
	'newspack_event_logger_nodes_expected_log_basenames'
);

/**
 * Wrap the substrate's cron-backstop supervisor run with a fresh LogManager
 * job context so the 595s tick logs as `/jobs/newspack-nodes/supervisor`
 * (tagged `worker_type='supervisor'`) instead of as an untagged
 * `/wp-cron.php` request that counts in global averages.
 *
 * The substrate (Bootstrap::run_supervisor_tick) sets the env var BEFORE
 * firing `before_supervisor_run`, so begin_job_context's fresh LogManager
 * picks up worker_type at init. State carries between the two handlers via
 * a closure-bound static so we don't pollute $GLOBALS.
 */
( static function (): void {
	$orig_server = null;
	\add_action(
		'newspack_nodes/before_supervisor_run',
		static function () use ( &$orig_server ): void {
			$orig_server = \Newspack_Event_Logger_Nodes\Job_Worker_Node::begin_job_context( 'newspack-nodes/supervisor' );
		}
	);
	\add_action(
		'newspack_nodes/after_supervisor_run',
		static function () use ( &$orig_server ): void {
			if ( null === $orig_server ) {
				return;
			}
			\Newspack_Event_Logger_Nodes\Job_Worker_Node::end_job_context( $orig_server );
			$orig_server = null;
		}
	);
} )();

/**
 * Hook the runtime's spawn action: when SpawnController fires
 * `newspack_nodes/spawn_worker`, locate the topology config for this {type, partition},
 * build a WorkerBase, and call ->execute() to start the drain loop.
 *
 * The WorkerBase will register a shutdown handler that fires self_respawn() when
 * this PHP process ends — keeping the worker pool alive without external supervision.
 */
\add_action(
	'newspack_nodes/spawn_worker',
	static function ( string $type, int $partition ) use (
		$_newspack_event_logger_nodes_register_user_topology_dir,
		$_newspack_event_logger_nodes_register_worker_runtime
	): void {
		$_newspack_event_logger_nodes_register_user_topology_dir();
		$_newspack_event_logger_nodes_register_worker_runtime();
		$workers = \Newspack_Nodes\Bootstrap::expand_workers();
		foreach ( $workers as $w ) {
			if ( $w['type'] !== $type || $w['partition'] !== $partition ) {
				continue;
			}
			// Via substrate Bootstrap (not raw filter) so the config-file
			// overlay wins. Otherwise the worker process spawns under the
			// filter default while dashboards/CLI read from the file value.
			$base_dir   = \Newspack_Nodes\Bootstrap::base_dir();
			$nonce_salt = \defined( 'NONCE_SALT' ) ? \NONCE_SALT : '';
			$supervisor = new \Newspack_Nodes\Supervisor( $base_dir, $nonce_salt );
			$wb         = new \Newspack_Nodes\Worker_Base(
				$base_dir,
				$type,
				$partition,
				stale_timeout: $w['stale_timeout']
			);

			// `topology` is now a TSL topology name. WorkerBase::execute
			// expects a callable of shape `($ci, $partition): void`;
			// build one that invokes Topology_Loader against the worker's
			// CommandInterpreter with the live merged config.
			$topology_name = (string) $w['topology'];
			$config        = \Newspack_Event_Logger_Nodes\Config::load_config();
			// Pre-derived `<config:logs_dir>` and `<config:offsets_dir>`
			// since topologies use them frequently; let topology authors
			// write the short form rather than
			// `<config:base_directory>/logs` everywhere.
			if ( ! isset( $config['logs_dir'] ) && isset( $config['base_directory'] ) ) {
				$config['logs_dir'] = \rtrim( (string) $config['base_directory'], '/' ) . '/logs';
			}
			if ( ! isset( $config['offsets_dir'] ) && isset( $config['base_directory'] ) ) {
				$config['offsets_dir'] = \rtrim( (string) $config['base_directory'], '/' ) . '/offsets';
			}
			$topology = static function (
				\Newspack_Nodes\Command_Interpreter_Node $ci,
				int $partition_arg
			) use ( $topology_name, $config ): void {
				\Newspack_Nodes\Topology_Loader::load(
					$topology_name,
					$partition_arg,
					$ci,
					$config
				);
			};

			$spawn_url = \rest_url( 'newspack-nodes/v1/workers/spawn' );
			$token     = $supervisor->generate_spawn_token( \time() );
			$wb->execute( $topology, $spawn_url, $token );
			break;
		}
	},
	10,
	2
);

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
// SSE_Out + Topology_Stream_Controller handle every
// SSE need now.

/**
 * Build the one shared `\Memcached` handle from the substrate's
 * `memcache_servers` config (host:port strings; defaults to 127.0.0.1:11211)
 * and stash it on `\Newspack_Nodes\Core::$memd`. Left null if the PECL
 * `\Memcached` class is absent or no server registers — every consumer's
 * null-safe `Core::$memd?->...` then fails soft.
 */
function newspack_event_logger_nodes_init_memcached(): void {
	if ( ! \class_exists( '\Memcached' ) ) {
		return;
	}
	$config  = \Newspack_Nodes\Config::load_config();
	$servers = $config['memcache_servers'] ?? [ '127.0.0.1:11211' ];
	if ( ! \is_array( $servers ) || empty( $servers ) ) {
		$servers = [ '127.0.0.1:11211' ];
	}
	$memd = new \Memcached();
	foreach ( $servers as $server ) {
		$parts = \explode( ':', (string) $server );
		$memd->addServer( $parts[0], (int) ( $parts[1] ?? 11211 ) );
	}
	\Newspack_Nodes\Core::$memd = empty( $memd->getServerList() ) ? null : $memd;
}

/**
 * Service-CommandInterpreter (CI) mounting.
 *
 * The substrate's `HTTP_In::dispatch` lazy-builds the
 * request-scope graph (`_router` / `_command_interpreter` / `_http`)
 * then fires `newspack_nodes/request_graph_ready` so applications can
 * mount their CIs through the base CI's `make_node()` — which
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
function newspack_event_logger_nodes_mount_service_cis( \Newspack_Nodes\Command_Interpreter_Node $base_ci ): void {
	$registry = \Newspack_Event_Logger_Nodes\Server_Registry::get_instance();

	$base_ci->make_node( 'Discovery_CI',   'discovery' );
	$base_ci->make_node( 'Status_CI',      'status' );
	$base_ci->make_node( 'Settings_CI',    'settings' );
	$base_ci->make_node( 'Logger_CI',      'logger' );
	$base_ci->make_node( 'Events_CI',      'events' );
	$base_ci->make_node( 'Servers_CI',     'servers',     $registry );
	$base_ci->make_node( 'Aggregator_CI',  'aggregator',  $registry );
	$base_ci->make_node( 'Performance_CI', 'performance' );
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
		// Workers + Raw Logs are substrate dashboards now — they register
		// their own submenu pages under the "Nodes" top-level via
		// newspack-nodes/includes/admin/class-admin.php::register_event_dashboard_pages.
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
		$page    = isset( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';
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

		$asset_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.js";
		$asset_url  = NEWSPACK_EVENT_LOGGER_NODES_URL . "build/{$tree}/index.js";
		if ( ! \file_exists( $asset_path ) ) {
			return;
		}

		$handle  = "newspack-nodes-{$tree}";
		$version = \filemtime( $asset_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION;
		$deps    = [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ];
		\wp_enqueue_script( $handle, $asset_url, $deps, $version, true );

		// Enqueue the matching stylesheet (wp-scripts emits index.css alongside
		// index.js when SCSS is imported). Without this, dashboards render
		// unstyled.
		$css_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.css";
		$css_url  = NEWSPACK_EVENT_LOGGER_NODES_URL . "build/{$tree}/index.css";
		if ( \file_exists( $css_path ) ) {
			$css_version = \filemtime( $css_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION;
			\wp_enqueue_style( $handle, $css_url, [ 'wp-components' ], $css_version );
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
				$settings_css_version = \filemtime( $settings_css_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION;
				\wp_enqueue_style( 'newspack-nodes-aggregator-settings', $settings_css_url, [], $settings_css_version );
			}
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
			'restUrl'           => $rest_url,
			'aggregatorRestUrl' => $aggregator_rest_url,
			'nonce'             => $nonce,
			'restartNonce'      => $restart_nonce,
			'tree'              => $tree,
			'version'           => NEWSPACK_EVENT_LOGGER_NODES_VERSION,
		];
		\wp_localize_script( $handle, 'NewspackNodesData', $localized );

		// Legacy globals the React trees still reference. The dashboards expect
		// these specific names — `eventLoggerDashboards` for REST root + retention,
		// `eventLoggerHookCategories` for color/pattern lookups in formatUtils,
		// `eventLoggerCustomColors` for custom-event color overrides.
		$retention_seconds = 86400;
		if ( \class_exists( '\\Newspack_Nodes\\Config' ) ) {
			$substrate         = \Newspack_Nodes\Config::load_config();
			$retention_seconds = (int) ( $substrate['max_lifespan'] ?? 86400 );
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
			// on the Remote Servers section. M5.2 cutover — was a raw jQuery
			// script under `assets/`; now a wp-scripts build entry so the
			// `@newspack-nodes/runtime` alias resolves and the 4 CRUD verbs
			// dispatch through the shared CommandClient against the unified
			// `/command` endpoint (M2 Servers_CI add/update/delete/test).
			// jQuery stays for DOM glue but the actual transport runs through
			// the same singleton dashboards use.
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
				$deps = \array_values( \array_unique( \array_merge(
					[ 'jquery' ],
					\is_array( $asset_meta['dependencies'] ?? null ) ? $asset_meta['dependencies'] : []
				) ) );
				$version = (string) ( $asset_meta['version'] ?? ( \filemtime( $aggregator_js_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION ) );
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
				\wp_localize_script(
					$agg_handle,
					'eventAggregatorAdmin',
					[
						'i18n' => [
							'testing'       => \__( 'Testing...', 'newspack-event-logger-nodes' ),
							'success'       => \__( 'Connected!', 'newspack-event-logger-nodes' ),
							'failed'        => \__( 'Failed', 'newspack-event-logger-nodes' ),
							'adding'        => \__( 'Adding...', 'newspack-event-logger-nodes' ),
							'added'         => \__( 'Server added! Reloading...', 'newspack-event-logger-nodes' ),
							'error'         => \__( 'Error', 'newspack-event-logger-nodes' ),
							'confirmRemove' => \__( 'Are you sure you want to remove this server?', 'newspack-event-logger-nodes' ),
						],
					]
				);
			}
		}
	}
);
