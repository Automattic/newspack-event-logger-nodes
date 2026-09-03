<?php
/**
 * Newspack Event Logger Nodes uninstall entry point.
 *
 * WordPress includes this file when an operator deletes the plugin, never when
 * they deactivate it, and defines `WP_UNINSTALL_PLUGIN` before doing so — which
 * is what the guard below tests, so a direct request to the file does nothing.
 *
 * The plugin's own autoloader is registered by its main file, which does not
 * run here, so the cleanup routine is required by hand. It deletes every
 * option row named for the `newspack_event_logger_nodes_` prefix on every
 * site — the ruleset's non-autoloaded `rule_hooks_*` rows among them — and
 * stops there. The substrate's uninstall owns the on-disk runtime tree, and
 * memcache stats expire on their own TTLs.
 *
 * @package Newspack_Event_Logger_Nodes
 */

if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/includes/uninstall-cleanup.php';

\Newspack_Event_Logger_Nodes\uninstall_cleanup( 'newspack_event_logger_nodes_' );
