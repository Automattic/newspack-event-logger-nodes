<?php
/**
 * Newspack Nodes uninstall cleanup.
 *
 * Runs ONLY on plugin delete (WordPress defines WP_UNINSTALL_PLUGIN), never on
 * deactivate. Removes every `newspack_event_logger_nodes_` option this plugin created.
 *
 * @package Newspack_Event_Logger_Nodes
 */

if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/includes/uninstall-cleanup.php';

\Newspack_Event_Logger_Nodes\uninstall_cleanup( 'newspack_event_logger_nodes_' );
