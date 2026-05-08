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

// Require runtime classes (will be added one per task).
// require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'includes/class-request-builder.php';
