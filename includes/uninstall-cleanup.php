<?php
/**
 * Uninstall option-cleanup helpers.
 *
 * Loaded only from `uninstall.php`, which WordPress runs on plugin delete and
 * which calls `uninstall_cleanup( 'newspack_event_logger_nodes_' )`. The file
 * declares functions, not classes, so the classmap autoloader never maps it and
 * it costs nothing at runtime.
 *
 * The sweep is options-only by design. On-disk state — logs, locks, offsets,
 * IPC — lives under the substrate's base directory, and the `newspack-nodes`
 * uninstall removes that tree; memcache stats carry TTLs and expire on their
 * own.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types = 1 );

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

/**
 * Delete every option row for a prefix, plus its transient variants.
 *
 * Transients are option rows too (`_transient_<name>` and
 * `_transient_timeout_<name>`), so sweeping all three stubs stays options-only.
 * Selecting by prefix keeps the cleanup complete as options come and go, and it
 * catches the autoload=off rows a hardcoded list misses — the ruleset's
 * per-rule `newspack_event_logger_nodes_rule_hooks_*` options among them.
 * Rows go through `delete_option()` rather than one bulk DELETE, so the options
 * cache and the `delete_option` hooks stay in step.
 *
 * @param \wpdb  $wpdb   WordPress database handle. Reads `$wpdb->options`, so
 *                       the caller's current site decides which table.
 * @param string $prefix Option-name prefix, e.g. `newspack_event_logger_nodes_`.
 * @return int Number of option rows deleted.
 */
function delete_prefixed_options( $wpdb, string $prefix ): int {
	$deleted = 0;
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; the LIKE prefix is esc_like-escaped and contains no user input.
	foreach ( [ $prefix, '_transient_' . $prefix, '_transient_timeout_' . $prefix ] as $stub ) {
		$sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '" . $wpdb->esc_like( $stub ) . "%'";
		foreach ( $wpdb->get_col( $sql ) as $name ) {
			if ( \is_string( $name ) ) {
				\delete_option( $name );
				++$deleted;
			}
		}
	}
	// phpcs:enable
	return $deleted;
}

/**
 * Delete all prefixed options, iterating every site on multisite.
 *
 * `switch_to_blog()` repoints `$wpdb->options` at each site's table, so one pass
 * per site sweeps the whole network; `'number' => 0` lifts the default 100-site
 * cap that would otherwise leave the rest behind. Network-wide (`sitemeta`)
 * options need no pass — this plugin stores none.
 *
 * @param string $prefix Option-name prefix.
 * @return void
 */
function uninstall_cleanup( string $prefix ): void {
	global $wpdb;
	/** @var \wpdb $wpdb */

	if ( \is_multisite() ) {
		foreach ( \get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			\switch_to_blog( $site_id );
			delete_prefixed_options( $wpdb, $prefix );
			\restore_current_blog();
		}
		return;
	}
	delete_prefixed_options( $wpdb, $prefix );
}
