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
