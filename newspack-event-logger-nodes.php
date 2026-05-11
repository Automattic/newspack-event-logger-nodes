<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.1.0
 * Requires Plugins: newspack-nodes
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.1.0' );
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
		// Runtime missing or deactivated; surface the error once.
		\Newspack_Nodes\Core::print_less_often(
			'newspack-event-logger-nodes: \Newspack_Nodes\Node missing — newspack-nodes inactive?'
		);
		return;
	}

	// Composer classmap autoloader. Class files inside includes/ are scanned
	// at `composer install` / `composer dump-autoload` time and registered
	// with a FQCN-to-path map; classes load on first reference so admin /
	// REST / cli paths each only pay for the code they touch. vendor/
	// ships with the release zip via build-release.sh.
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

	if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'nodes reqgrep', '\\Newspack_Event_Logger_Nodes\\CLI\\ReqgrepCommand' );
	}

	// Register application Node subclasses with the runtime's class_map so
	// topology PHP can construct them via `$interpreter->make_node()`.
	\Newspack_Nodes\CommandInterpreter::register_class( 'AutoTuner',      \Newspack_Event_Logger_Nodes\AutoTuner::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'FlameBuilder',   \Newspack_Event_Logger_Nodes\FlameBuilder::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'JobRouter',      \Newspack_Event_Logger_Nodes\JobRouter::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'JobWorker',      \Newspack_Event_Logger_Nodes\JobWorker::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'RequestBuilder', \Newspack_Event_Logger_Nodes\RequestBuilder::class );
	\Newspack_Nodes\CommandInterpreter::register_class( 'StreamMerger',   \Newspack_Event_Logger_Nodes\StreamMerger::class );

	// Wire one-shot static initializers for the static-mode classes.
	\Newspack_Event_Logger_Nodes\Config::register_cache_invalidation();
	\Newspack_Event_Logger_Nodes\SettingsSync::init();
	\Newspack_Event_Logger_Nodes\RemoteManager::init();
	// HealthCheckExtensions has no init() — RemoteManager::health_check
	// calls process_discovery() directly. AutoTuner is a Node, wired into
	// the request-workers topology.
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
 * Topology registration. Two topologies:
 *  - `firehose-workers` runs N partitions of the worker graph (Tail → Tee →
 *    RequestBuilder/JobRouter → JobWorker).
 *  - `aggregator` runs a single hub-side ingest graph (StreamMerger → Topic).
 *
 * The runtime's Bootstrap::expand_workers() reads the resulting array and
 * spawns workers per partition; this plugin file owns the filter wiring.
 */
\add_filter(
	'newspack_nodes/topologies',
	static function ( array $topologies ): array {
		// Topology fleet is declared as data in
		// newspack-event-logger-nodes-config.php so per-site overrides can
		// add/remove entries without patching the plugin. This filter
		// resolves each entry's path (relative → plugin-rooted), applies
		// `num_partitions` defaults from substrate config so a single
		// setting drives both LogManager (producer) and the worker fleet
		// (consumer) — hardcoding diverges them — and honors `gated_by`
		// so an operator-facing WP option (e.g. enable_aggregator) can
		// keep the supervisor from spawning a topology's workers at all.
		if ( ! \class_exists( '\Newspack_Event_Logger_Nodes\Config' ) ) {
			return $topologies;
		}
		$config         = \Newspack_Event_Logger_Nodes\Config::load_config( 'full' );
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$num_partitions = \max( 1, \min( 16, $num_partitions ) );

		$defs = $config['topologies'] ?? [];
		if ( ! \is_array( $defs ) ) {
			return $topologies;
		}

		foreach ( $defs as $name => $def ) {
			if ( ! \is_string( $name ) || ! \is_array( $def ) || empty( $def['topology'] ) ) {
				continue;
			}
			if ( isset( $def['gated_by'] ) && \is_string( $def['gated_by'] ) ) {
				if ( ! (int) \get_option( $def['gated_by'], 1 ) ) {
					continue;
				}
			}
			$path = (string) $def['topology'];
			if ( '/' !== \substr( $path, 0, 1 ) ) {
				$path = NEWSPACK_EVENT_LOGGER_NODES_DIR . \ltrim( $path, '/' );
			}
			$topologies[ $name ] = [
				'topology'       => $path,
				'num_partitions' => isset( $def['num_partitions'] ) ? (int) $def['num_partitions'] : $num_partitions,
				'stale_timeout'  => isset( $def['stale_timeout'] ) ? (int) $def['stale_timeout'] : 60,
			];
		}
		return $topologies;
	}
);

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
	static function ( string $type, int $partition ): void {
		if ( ! \class_exists( '\Newspack_Nodes\Bootstrap' ) ) {
			return;
		}
		$workers = \Newspack_Nodes\Bootstrap::expand_workers();
		foreach ( $workers as $w ) {
			if ( $w['type'] !== $type || $w['partition'] !== $partition ) {
				continue;
			}
			$base_dir   = (string) \apply_filters( 'newspack_nodes/base_dir', '/tmp/newspack-nodes' );
			$nonce_salt = \defined( 'NONCE_SALT' ) ? \NONCE_SALT : '';
			$supervisor = new \Newspack_Nodes\Supervisor( $base_dir, $nonce_salt );
			$wb         = new \Newspack_Nodes\WorkerBase(
				$base_dir,
				$type,
				$partition,
				stale_timeout: $w['stale_timeout']
			);
			$topology   = require $w['topology'];
			$spawn_url  = \rest_url( 'newspack-nodes/v1/workers/spawn' );
			$token      = $supervisor->generate_spawn_token( \time() );
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
		if ( ! \class_exists( '\Newspack_Event_Logger_Nodes\Rest\StatusController' ) ) {
			return; // Plugin classes not loaded; nothing to register.
		}
		( new \Newspack_Event_Logger_Nodes\Rest\StatusController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\AggregatorController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\AggregatorStatusController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\EventsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerformanceController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\GyroscopeController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\LoggerController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\RequestLogController() )->register_routes();
		// Newly-added: real-shape controllers replacing former stubs.
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
		\wp_localize_script(
			$handle,
			'NewspackNodesData',
			[
				'restUrl'           => $rest_url,
				'aggregatorRestUrl' => $aggregator_rest_url,
				'nonce'             => $nonce,
				'tree'              => $tree,
				'version'           => NEWSPACK_EVENT_LOGGER_NODES_VERSION,
			]
		);

		// Legacy globals the React trees still reference. The dashboards expect
		// these specific names — `eventLoggerDashboards` for REST root + retention,
		// `eventLoggerHookCategories` for color/pattern lookups in formatUtils,
		// `eventLoggerCustomColors` for custom-event color overrides.
		$retention_seconds = 86400;
		if ( \class_exists( '\\Newspack_Nodes\\Config' ) ) {
			$substrate         = \Newspack_Nodes\Config::load_config( 'full' );
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
		$custom_colors = \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' )
			? \Newspack_Event_Logger_Nodes\Config::get_custom_colors()
			: [];
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
		if ( 'performance-logger' === $tree && \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			$cfg                 = \Newspack_Event_Logger_Nodes\Config::load_config( 'full' );
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
