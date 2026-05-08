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
};

if ( \class_exists( '\Newspack_Nodes\Node' ) ) {
	// Loaded after newspack-nodes (later alphabetically, but possible if reordered).
	$_newspack_event_logger_nodes_load();
} else {
	\add_action( 'plugins_loaded', $_newspack_event_logger_nodes_load, 11 );
}

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
