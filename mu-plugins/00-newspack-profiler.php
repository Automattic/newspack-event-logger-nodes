<?php
/**
 * Plugin Name: Newspack Profiler
 * Description: Times each active plugin's load — duration, new classes, new files — and records the moment PHP began the request. Event Logger Nodes writes the measurements to the firehose when it is active; nothing here depends on it.
 * Version: 0.1.0
 * Author: Automattic
 *
 * Two facts are gone by the time a regular plugin can run. WordPress loads
 * plugins in a tight loop and times nothing, so what one plugin's file costs
 * is never recorded anywhere; and the request's real start is long past by the
 * time Log_Manager writes its first line, deep in bootstrap. Only an mu-plugin
 * runs early enough to capture either, and the `00-` prefix sorts this one
 * ahead of every other mu-plugin.
 *
 * Only site-activated plugins are timed. Must-use plugins announce themselves
 * on `mu_plugin_loaded` and network-activated ones on `network_plugin_loaded`;
 * neither hook is bound here.
 *
 * Deploy manually: Copy to wp-content/mu-plugins/00-newspack-profiler.php
 * Remove: Delete the file. No persistent state, no options, no database writes.
 *
 * @package Newspack_Profiler
 */

\defined( 'ABSPATH' ) || exit;

/**
 * The request's start readings, plus one row per timed plugin.
 *
 * `request_time` is a monotonic nanosecond counter, the baseline downstream
 * delta math measures against. `request_ts` is the wall clock at that same
 * moment: Log_Manager stamps it onto the firehose `process (start)` entry, so
 * a request's record begins where PHP began rather than where Log_Manager
 * happened to emit its first line. Log_Manager's constructor consumes and
 * unsets both keys, which is what stops a nested job context from claiming
 * them a second time; `plugins` is left for the flush below.
 *
 * @var array{request_time:int,request_ts:float,plugins:list<array{slug:string,start_ts:float,duration_ns:int|float,new_classes:int,new_files:int}>} $newspack_profiler
 */
global $newspack_profiler;
$newspack_profiler = [
	'request_time' => \hrtime( true ),
	'request_ts'   => \microtime( true ),
	'plugins'      => [],
];

/**
 * Running baseline, threaded by reference through the two callbacks below.
 *
 * `hr`, `classes` and `files` hold the previous `plugin_loaded` firing's
 * readings; each firing differences against them, records the difference, then
 * overwrites them. `base_ts` and `base_hr` are one microtime/hrtime pair taken
 * together, so every plugin's wall-clock start comes from an hrtime delta
 * instead of a second `microtime()` call per plugin. A null `hr` means the
 * baseline has not been taken, and is the flag both callbacks read.
 *
 * @var array{hr:int|null,base_ts:float|null,base_hr:int|null,classes:int,files:int} $newspack_profiler_state
 */
$newspack_profiler_state = [
	'hr'       => null,
	'base_ts'  => null,
	'base_hr'  => null,
	'classes'  => 0,
	'files'    => 0,
];

\add_filter(
	'option_active_plugins',
	/**
	 * Take the baseline the moment before the plugin loop starts.
	 *
	 * `wp_get_active_and_valid_plugins()` reads this option immediately ahead
	 * of the loop, which is the last point still outside every plugin. Reading
	 * the counters at this file's own load instead would charge the rest of
	 * WordPress's bootstrap to the first plugin. Other code reads the option
	 * too, so the null check keeps the earliest firing and ignores the rest.
	 *
	 * @param mixed $plugins The stored `active_plugins` value.
	 * @return mixed The same value, untouched.
	 */
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
	/**
	 * Record what one plugin's file cost: elapsed time, new classes, new files.
	 *
	 * `plugin_loaded` fires after each `include_once`, so the interval since
	 * the previous firing is that plugin's load. It also covers the loop's own
	 * per-plugin work — `wp_register_plugin_realpath()`, `get_plugin_data()`,
	 * textdomain registration — because the measurement is taken between
	 * firings and WordPress offers no signal bracketing the include alone.
	 *
	 * Nothing is recorded while the baseline is null: `pre_option_active_plugins`
	 * can short-circuit `get_option()` before `option_active_plugins` ever
	 * fires, and a difference against a null baseline is meaningless.
	 *
	 * @param string $plugin Absolute path of the plugin file just included.
	 */
	function ( string $plugin ) use ( &$newspack_profiler_state ) {
		/** @var array{request_time:int, request_ts:float, plugins:list<array{slug:string,start_ts:float,duration_ns:int|float,new_classes:int,new_files:int}>} $newspack_profiler */
		global $newspack_profiler;
		if ( null === $newspack_profiler_state['hr'] ) {
			return;
		}

		$now_hr  = \hrtime( true );
		$classes = \count( \get_declared_classes() );
		$files   = \count( \get_included_files() );

		// A single-file plugin sits at the plugins root, where dirname is '.'.
		$slug = \dirname( \plugin_basename( $plugin ) );
		$slug = '.' === $slug ? \basename( $plugin, '.php' ) : $slug;

		// Wall clock at the previous firing: base pair plus its hrtime delta.
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

\add_action(
	'plugins_loaded',
	/**
	 * Write the collected rows to the firehose as start/complete span pairs.
	 *
	 * Priority -10001 sits one below `hook_start_priority`, whose default is
	 * -10000, so these entries precede whatever App\Core's instrumentation
	 * writes for `plugins_loaded` and the log reads in the order the work
	 * happened. Reaching Log_Manager this early requires newspack-event-logger-nodes
	 * to register its Composer autoloader at plugin-file load time rather than
	 * inside its own deferred `plugins_loaded` 11 bootstrap.
	 *
	 * `instance()` builds the logger when nothing else has, and that
	 * construction is what adopts `request_time` and `request_ts` from the
	 * global. The `is_started()` gate then drops everything when the governing
	 * rule did not say `log`.
	 *
	 * Each entry carries its own `ts`, overriding the one `message()` would
	 * stamp: every row was measured before this flush, and the flush moment
	 * would collapse the whole plugin-loading phase onto one instant. The
	 * `(start)` / `(complete)` suffixes are what `Flame_Tree` matches to build
	 * a span, `duration_ms` on the complete is the span's width, and the counts
	 * ride in `m` because that is the field the entry table renders.
	 */
	function () {
		/** @var array{request_time:int, request_ts:float, plugins:list<array{slug:string,start_ts:float,duration_ns:int|float,new_classes:int,new_files:int}>} $newspack_profiler */
		global $newspack_profiler;

		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Log_Manager' ) ) {
			return;
		}

		$lm = \Newspack_Event_Logger_Nodes\Log_Manager::instance();
		if ( ! $lm->is_started() ) {
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
