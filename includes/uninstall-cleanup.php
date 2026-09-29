<?php
/**
 * Uninstall option-cleanup helpers.
 *
 * Loaded only from `uninstall.php`, which WordPress runs on plugin delete and
 * which calls `uninstall_cleanup( 'newspack_event_logger_nodes_' )`. The file
 * declares functions, not classes, so the classmap autoloader never maps it and
 * it costs nothing at runtime.
 *
 * Two sweeps: every option row the plugin wrote, and the three stats Tables'
 * SQLite files. The rest of the on-disk state — logs, locks, offsets, IPC —
 * lives under the substrate's base directory, and the `newspack-nodes`
 * uninstall removes that tree, which leaves `tables/` behind.
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
 *                       the caller's current site decides which table. The
 *                       signature leaves it untyped on purpose: no `wpdb` class
 *                       is declared in a test process, so the unit test passes
 *                       an anonymous double a type hint would reject.
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
 * Delete the stats Tables' SQLite files: each partition's file, and the WAL
 * files SQLite keeps beside it, named by the substrate's `Table_Node::file()`
 * across every partition a fleet can run.
 *
 * Only under a base directory this install configured. The schema default
 * is the one every unconfigured install on a host shares, and so are the
 * files under it, as the substrate's own uninstall holds. Nothing to do
 * where the substrate is not loaded, since only it can name the files.
 *
 * @param list<string> $tables The stats Tables, `Stats_Store::TABLES`.
 * @return int Files deleted.
 */
function delete_stats_tables( array $tables ): int {
	if ( ! \class_exists( '\Newspack_Nodes\Table_Node' ) ) {
		return 0;
	}
	$base = \Newspack_Nodes\Config::configured_base_directory();
	if ( '' === $base || \Newspack_Nodes\Settings_Schema::get()->defaults()['base_directory'] === $base ) {
		return 0;
	}
	$deleted = 0;
	foreach ( $tables as $table ) {
		for ( $partition = 0; $partition < \Newspack_Nodes\Spawn_Coordinator::MAX_PARTITIONS; $partition++ ) {
			$file = \Newspack_Nodes\Table_Node::file( $table, $partition );
			foreach ( [ $file, "{$file}-wal", "{$file}-shm" ] as $path ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- one-time uninstall of this plugin's own stats files.
				if ( \is_file( $path ) && \unlink( $path ) ) {
					++$deleted;
				}
			}
		}
	}
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
