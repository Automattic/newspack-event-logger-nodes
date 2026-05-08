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
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-memcached-cache.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-stats-store.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-lru-cache.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-request-builder.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-flame-builder.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-stats-aggregator.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-job-router.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-job-worker.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-stream-merger.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-server-registry.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-settings-sync.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-performance-controller-base.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-status-controller.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-aggregator-controller.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-events-controller.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-performance-controller.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-gyroscope-controller.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-logger-controller.php';
	require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/rest/class-request-log-controller.php';
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
		$topologies['firehose-workers'] = [
			'topology'       => NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies/firehose-workers.php',
			'num_partitions' => 4,
			'stale_timeout'  => 60,
		];
		$topologies['aggregator'] = [
			'topology'       => NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies/aggregator.php',
			'num_partitions' => 1,
			'stale_timeout'  => 60,
		];
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
		( new \Newspack_Event_Logger_Nodes\Rest\EventsController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\PerformanceController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\GyroscopeController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\LoggerController() )->register_routes();
		( new \Newspack_Event_Logger_Nodes\Rest\RequestLogController() )->register_routes();
	}
);

/**
 * Admin menu mounting. Top-level "Event Logger" page + 8 submenu pages
 * (one per dashboard surface). Each submenu prints the mount-element div
 * the corresponding tree's index.js hooks onto.
 *
 * Mount IDs (sourced from src/<tree>/index.js):
 *   event-aggregator      → #event-aggregator-status
 *   event-dashboards      → #event-logger-workers, #event-logger-rawlogs
 *   performance-dashboards → #event-logger-admin, #event-logger-errors
 *   performance-gyroscope → #event-logger-gyroscope
 *   performance-logger    → settings page (mounts via TagInputField data-id)
 *   performance-request-log → #event-logger-stream
 */
\add_action(
	'admin_menu',
	static function (): void {
		if ( ! \function_exists( 'add_menu_page' ) ) {
			return;
		}
		\add_menu_page(
			'Event Logger',
			'Event Logger',
			'manage_options',
			'newspack-nodes',
			static fn() => print( '<div class="wrap"><h1>Event Logger</h1><p>Select a dashboard from the submenu.</p></div>' ),
			'dashicons-chart-line',
			80
		);
		\add_submenu_page(
			'newspack-nodes',
			'Performance Dashboard',
			'Performance',
			'manage_options',
			'newspack-nodes-performance',
			static fn() => print( '<div id="event-logger-admin" class="wrap"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Error Log',
			'Errors',
			'manage_options',
			'newspack-nodes-errors',
			static fn() => print( '<div id="event-logger-errors"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Workers',
			'Workers',
			'manage_options',
			'newspack-nodes-workers',
			static fn() => print( '<div id="event-logger-workers" class="wrap"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Raw Logs',
			'Raw Logs',
			'manage_options',
			'newspack-nodes-rawlogs',
			static fn() => print( '<div id="event-logger-rawlogs" class="wrap"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Gyroscope',
			'Gyroscope',
			'manage_options',
			'newspack-nodes-gyroscope',
			static fn() => print( '<div id="event-logger-gyroscope" class="wrap"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Request Log',
			'Request Log',
			'manage_options',
			'newspack-nodes-stream',
			static fn() => print( '<div id="event-logger-stream" class="wrap"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Aggregator',
			'Aggregator',
			'manage_options',
			'newspack-nodes-aggregator',
			static fn() => print( '<div id="event-aggregator-status" class="wrap"></div>' )
		);
		\add_submenu_page(
			'newspack-nodes',
			'Logger Settings',
			'Settings',
			'manage_options',
			'newspack-nodes-settings',
			static fn() => print( '<div id="newspack-nodes-settings" class="wrap"><h1>Logger Settings</h1></div>' )
		);
	}
);

/**
 * Map admin page slug → built React tree directory under build/.
 * Each tree's index.js is enqueued only when on its own admin page —
 * unconditionally enqueueing all 7 trees on every admin page would
 * make wp-admin pages noticeably slower for no benefit.
 */
\add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( ! \function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		// Hook → React tree directory mapping. Hook suffix is the slug after
		// the menu prefix; admin_page_loader.php gives us "toplevel_page_X" for
		// the top-level slug and "event-logger_page_X" for submenus.
		$hook_to_tree = [
			'toplevel_page_newspack-nodes'                  => null, // landing page, no JS
			'event-logger_page_newspack-nodes-performance'  => 'performance-dashboards',
			'event-logger_page_newspack-nodes-errors'       => 'performance-dashboards',
			'event-logger_page_newspack-nodes-workers'      => 'event-dashboards',
			'event-logger_page_newspack-nodes-rawlogs'      => 'event-dashboards',
			'event-logger_page_newspack-nodes-gyroscope'    => 'performance-gyroscope',
			'event-logger_page_newspack-nodes-stream'       => 'performance-request-log',
			'event-logger_page_newspack-nodes-aggregator'   => 'event-aggregator',
			'event-logger_page_newspack-nodes-settings'     => 'performance-logger',
		];
		// Some WP versions slugify "Event Logger" → "event-logger"; also tolerate
		// the variant with full menu title.
		if ( ! \array_key_exists( $hook, $hook_to_tree ) ) {
			return;
		}
		$tree = $hook_to_tree[ $hook ];
		if ( $tree === null ) {
			return;
		}

		$asset_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.js";
		$asset_url  = NEWSPACK_EVENT_LOGGER_NODES_URL . "build/{$tree}/index.js";
		if ( ! \file_exists( $asset_path ) ) {
			// Built artifact missing — nothing to enqueue. Don't error; deploys
			// without `npm run build` should not crash the admin.
			return;
		}

		$handle  = "newspack-nodes-{$tree}";
		$version = \filemtime( $asset_path ) ?: NEWSPACK_EVENT_LOGGER_NODES_VERSION;
		$deps    = [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ];

		\wp_enqueue_script( $handle, $asset_url, $deps, $version, true );

		// Inject REST namespace + nonce so the tree can call apiFetch with auth.
		\wp_localize_script(
			$handle,
			'NewspackNodesData',
			[
				'restUrl'             => \function_exists( 'rest_url' ) ? \rest_url( 'newspack-nodes/v1/' ) : '/wp-json/newspack-nodes/v1/',
				'aggregatorRestUrl'   => \function_exists( 'rest_url' ) ? \rest_url( 'newspack-nodes-aggregator/v1/' ) : '/wp-json/newspack-nodes-aggregator/v1/',
				'nonce'               => \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'wp_rest' ) : '',
				'tree'                => $tree,
				'version'             => NEWSPACK_EVENT_LOGGER_NODES_VERSION,
			]
		);
	}
);
