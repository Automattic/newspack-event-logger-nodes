<?php
/**
 * Plugin Name: Newspack Profiler
 * Description: MU-plugin that captures plugin load timing with class/file counts. Provides data invisible to WP Cloud APM. Consumed by Event Logger Nodes when available.
 * Version: 0.1.0
 * Author: Automattic
 *
 * Deploy manually: Copy to wp-content/mu-plugins/00-newspack-profiler.php
 * Remove: Delete the file. No persistent state, no options, no database writes.
 *
 * @package Newspack_Profiler
 */

\defined( 'ABSPATH' ) || exit;

global $newspack_profiler;
$newspack_profiler = [
	// hrtime: nanosecond counter, monotonic, used downstream for delta math.
	'request_time' => \hrtime( true ),
	// microtime: wall-clock equivalent of the same moment. Consumed by
	// Log_Manager to stamp the firehose `process (start)` ts (so
	// RequestBuilder's inflight_snapshot.start_time reflects the real
	// PHP-request start, not the Log_Manager-emit time deep in WP bootstrap).
	'request_ts'   => \microtime( true ),
	'plugins'      => [],
];

// ──────────────────────────────────────────────────────────────────────────────
// Plugin load timing with class and file snapshots
// ──────────────────────────────────────────────────────────────────────────────

/**
 * State for tracking between plugin_loaded calls.
 */
// Capture one microtime/hrtime pair at init. Derive all future wall-clock
// timestamps from hrtime deltas to avoid extra syscalls.
$newspack_profiler_state = [
	'hr'       => null,
	'base_ts'  => null,
	'base_hr'  => null,
	'classes'  => 0, // Overwritten by option_active_plugins filter before first use.
	'files'    => 0,
];

\add_filter(
	'option_active_plugins',
	function ( $plugins ) use ( &$newspack_profiler_state ) {
		if ( null === $newspack_profiler_state['hr'] ) {
			$newspack_profiler_state['base_ts'] = \microtime( true );
			$newspack_profiler_state['base_hr'] = \hrtime( true );
			$newspack_profiler_state['hr']      = $newspack_profiler_state['base_hr'];
			$newspack_profiler_state['classes'] = \count( \get_declared_classes() );
			$newspack_profiler_state['files']   = \count( \get_included_files() );
		}
		return $plugins;
	},
	1
);

\add_action(
	'plugin_loaded',
	function ( $plugin ) use ( &$newspack_profiler_state ) {
		global $newspack_profiler;
		if ( null === $newspack_profiler_state['hr'] ) {
			return;
		}

		$now_hr  = \hrtime( true );
		$classes = \count( \get_declared_classes() );
		$files   = \count( \get_included_files() );

		$slug = \dirname( \plugin_basename( $plugin ) );
		$slug = '.' === $slug ? \basename( $plugin, '.php' ) : $slug;

		// Derive wall-clock from base pair + hrtime delta.
		$start_ts = (float) $newspack_profiler_state['base_ts']
			+ ( (float) $newspack_profiler_state['hr'] - (float) $newspack_profiler_state['base_hr'] ) / 1e9;

		$newspack_profiler['plugins'][] = [
			'slug'        => $slug,
			'start_ts'    => $start_ts,
			'duration_ns' => $now_hr - $newspack_profiler_state['hr'],
			'new_classes' => $classes - $newspack_profiler_state['classes'],
			'new_files'   => $files - $newspack_profiler_state['files'],
		];

		$newspack_profiler_state['hr']      = $now_hr;
		$newspack_profiler_state['classes'] = $classes;
		$newspack_profiler_state['files']   = $files;
	},
	1
);

// The option_active_plugins filter initializes the baseline when WordPress
// calls get_option('active_plugins') inside wp_get_active_and_valid_plugins(),
// right before the plugin loading loop. No need to trigger it early here —
// that would attribute WordPress bootstrap overhead to the first plugin.

// ──────────────────────────────────────────────────────────────────────────────
// Flush to Event Logger Nodes
// ──────────────────────────────────────────────────────────────────────────────

// Flush deferred plugin load events BEFORE hook_start's callback wrapping.
// App\Core registers at start_priority (default 1, configurable down to e.g.
// -10000); we flush at -10001 so plugin load events appear in the log before
// any plugins_loaded callbacks, matching reality. Requires that
// newspack-event-logger-nodes register its composer autoloader at plugin-file
// load time (not deferred to plugins_loaded 11), so Log_Manager is
// autoload-resolvable here.
\add_action(
	'plugins_loaded',
	function () {
		global $newspack_profiler;

		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Log_Manager' ) ) {
			return;
		}

		$lm = \Newspack_Event_Logger_Nodes\Log_Manager::instance();
		if ( ! $lm->enabled ) {
			return;
		}

		foreach ( $newspack_profiler['plugins'] as $plugin ) {
			$duration_ms = \round( $plugin['duration_ns'] / 1000000, 3 );
			$duration_s  = $plugin['duration_ns'] / 1000000000;
			$lm->message( "{$plugin['slug']} plugin (start)", [
				'ts' => $plugin['start_ts'],
			] );
			$lm->message( "{$plugin['slug']} plugin (complete)", [
				'ts'          => $plugin['start_ts'] + $duration_s,
				'duration_ms' => $duration_ms,
				'm'           => "{$duration_ms}ms, {$plugin['new_classes']} classes, {$plugin['new_files']} files",
			] );
		}
	},
	-10001
);
