<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.2.31
 * Requires Plugins: newspack-nodes
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.2.31' );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_DIR' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_URL' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_URL', \function_exists( 'plugin_dir_url' ) ? \plugin_dir_url( __FILE__ ) : '' );
}

/**
 * Application classes extend `Newspack_Nodes\Node` from the runtime plugin.
 * WordPress loads plugins alphabetically, and `newspack-event-logger-nodes`
 * sorts before `newspack-nodes` — so the runtime isn't available at our
 * plugin-file load time. Defer requires to plugins_loaded.
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

	// Composer classmap autoloader. One file include; classes still load
	// lazily on first reference, so admin / REST / cli paths only pay for
	// code they actually touch.
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

	if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'nodes reqgrep', '\\Newspack_Event_Logger_Nodes\\CLI\\ReqgrepCommand' );
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

	// `SettingsSync::init` listens for `update_option` / `add_option` —
	// which can fire from admin saves, REST settings endpoints, CLI,
	// or programmatic callers. All of these should sync to remote
	// spokes, so the hook stays registered at boot.
	\Newspack_Event_Logger_Nodes\SettingsSync::init();

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

/**
 * Worker-execution prerequisites: Node-class registrations, named
 * formatters, hub-side k:"job" → k:"remote_job" rewrite filter,
 * RemoteManager's worker-internal hook registrations. Only needed
 * inside the spawn_worker action handler before Topology_Loader parses
 * the TSL. Every callee is independently idempotent
 * (`register_class` / `Formatters::register` are last-write-wins,
 * `register_remote_job_rewrite_filter` and `RemoteManager::init` carry
 * their own static guards), so no outer guard is necessary.
 */
$_newspack_event_logger_nodes_register_worker_runtime = static function (): void {
	\Newspack_Nodes\CommandInterpreter::register_class( 'AutoTuner',       \Newspack_Event_Logger_Nodes\AutoTuner::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'FlameBuilder',    \Newspack_Event_Logger_Nodes\FlameBuilder::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'HealthCheckTick', \Newspack_Event_Logger_Nodes\HealthCheckTick::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'JobRouter',       \Newspack_Event_Logger_Nodes\JobRouter::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'JobWorker',       \Newspack_Event_Logger_Nodes\JobWorker::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'RequestBuilder',  \Newspack_Event_Logger_Nodes\RequestBuilder::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'RemoteSource',    \Newspack_Event_Logger_Nodes\RemoteSource::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'StreamMerger',    \Newspack_Event_Logger_Nodes\StreamMerger::class );

	\Newspack_Nodes\Formatters::register(
		'request-index',
		static fn ( $line, $position, &$data = null )
			=> \Newspack_Event_Logger_Nodes\RequestBuilder::format_index_entry( $line, $position, $data )
	);
	\Newspack_Nodes\Formatters::register(
		'flame-index',
		static fn ( $line, $position, &$data = null )
			=> \Newspack_Event_Logger_Nodes\FlameBuilder::format_index_entry( $line, $position, $data )
	);

	\Newspack_Event_Logger_Nodes\StreamMerger::register_remote_job_rewrite_filter();
	\Newspack_Event_Logger_Nodes\RemoteManager::init();
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
 * Declare the log streams this application owns so the substrate's
 * "Total Log Storage" estimate can multiply them in. Each log lays out as
 * `{base}/logs/{name}.log/p{N}/{segment_id}.log` and obeys the same
 * segment_size × num_segments × num_partitions geometry, so the count alone
 * is enough — the substrate handles the arithmetic.
 *
 * Streams:
 *   firehose.log   — LogManager input
 *   jobintake.log  — JobIntake input (large jobs)
 *   requests.log   — RequestBuilder output
 *   errors.log     — RequestBuilder error output
 *   jobs.log       — JobRouter output
 *   flames.log     — FlameBuilder output
 */
\add_filter(
	'newspack_nodes/num_logs',
	static fn ( int $count ): int => $count + 6
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
			$orig_server = \Newspack_Event_Logger_Nodes\JobWorker::begin_job_context( 'newspack-nodes/supervisor' );
		}
	);
	\add_action(
		'newspack_nodes/after_supervisor_run',
		static function () use ( &$orig_server ): void {
			if ( null === $orig_server ) {
				return;
			}
			\Newspack_Event_Logger_Nodes\JobWorker::end_job_context( $orig_server );
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
			$wb         = new \Newspack_Nodes\WorkerBase(
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
				\Newspack_Nodes\CommandInterpreter $ci,
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
\add_action(
	'rest_api_init',
	static function (): void {
		( new \Newspack_Event_Logger_Nodes\Rest\StatusController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\AggregatorController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\AggregatorStatusController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\EventsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerformanceController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\GyroscopeController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\LoggerController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\RequestLogController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\FirehoseController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\DiscoveryController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\SettingsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfSettingsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfConfigController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfHooksAvailableController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfHooksController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfOverviewController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfUrlsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerfRequestsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\WorkersController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\ServersController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\FirehoseStreamController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\RawlogsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\ErrorsStreamController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\GyroscopeStreamController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\RequestsStreamController() )->register_routes();
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
 *   event-dashboards        → #event-logger-workers, #event-logger-rawlogs
 *   performance-dashboards  → #event-logger-admin, #event-logger-errors
 *   performance-gyroscope   → #event-logger-gyroscope
 *   performance-request-log → #event-logger-stream
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
			'newspack-nodes-workers'     => [ 'Workers', 'Workers', '<div id="event-logger-workers" class="event-logger-workers-page"></div>' ],
			'newspack-nodes-rawlogs'     => [ 'Raw Logs', 'Raw Logs', '<div id="event-logger-rawlogs" class="event-logger-rawlogs-page"></div>' ],
			'newspack-nodes-gyroscope'   => [ 'Gyroscope', 'Gyroscope', '<div id="event-logger-gyroscope" class="event-logger-gyroscope-page"></div>' ],
			'newspack-nodes-stream'      => [ 'Request Log', 'Request Log', '<div id="event-logger-stream" class="event-logger-stream-page"></div>' ],
		];
		// Aggregator submenu is gated on the same option that gates the
		// topology — when the aggregator is disabled there's nothing
		// meaningful to show under it, and a menu entry pointing at a
		// dashboard for a topology that isn't running is misleading.
		if ( (int) \get_option( 'newspack_event_logger_nodes_enable_aggregator', 1 ) ) {
			$dashboards['newspack-nodes-aggregator'] = [ 'Aggregator', 'Aggregator', '<div id="event-aggregator-status"></div>' ];
		}
		foreach ( $dashboards as $slug => [ $title, $menu_title, $mount_html ] ) {
			\add_submenu_page(
				'newspack-nodes-performance',
				$title,
				$menu_title,
				'manage_options',
				$slug,
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
		$page    = isset( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';
		$page_to_tree = [
			'newspack-nodes-performance'             => 'performance-dashboards',
			'newspack-nodes-errors'                  => 'performance-dashboards',
			'newspack-nodes-workers'                 => 'event-dashboards',
			'newspack-nodes-rawlogs'                 => 'event-dashboards',
			'newspack-nodes-gyroscope'               => 'performance-gyroscope',
			'newspack-nodes-stream'                  => 'performance-request-log',
			'newspack-nodes-aggregator'              => 'event-aggregator',
			'newspack-event-logger-nodes'            => 'performance-logger',
		];
		if ( ! \array_key_exists( $page, $page_to_tree ) ) {
			return;
		}
		$tree = $page_to_tree[ $page ];
		if ( null === $tree ) {
			return;
		}

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
			// on the Remote Servers section. Ported verbatim from the legacy
			// newspack-event-aggregator/assets/admin.js — jQuery-based, no
			// build step. REST namespace base injected as `restUrl`; the JS
			// appends `servers/...` per call.
			$aggregator_js_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . 'assets/aggregator-admin.js';
			$aggregator_js_url  = NEWSPACK_EVENT_LOGGER_NODES_URL . 'assets/aggregator-admin.js';
			if ( \file_exists( $aggregator_js_path ) ) {
				$agg_handle = 'newspack-event-logger-nodes-aggregator-admin';
				\wp_enqueue_script(
					$agg_handle,
					$aggregator_js_url,
					[ 'jquery' ],
					\filemtime( $aggregator_js_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION,
					true
				);
				\wp_localize_script(
					$agg_handle,
					'eventAggregatorAdmin',
					[
						'restUrl' => \function_exists( 'rest_url' ) ? \rest_url( 'newspack-nodes/v1/' ) : '/wp-json/newspack-nodes/v1/',
						'nonce'   => $nonce,
						'i18n'    => [
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
