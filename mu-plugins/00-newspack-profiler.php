<?php
/**
 * Plugin Name: Newspack Profiler
 * Description: Times each active plugin's load — duration, new classes, new files — records the moment PHP began the request, catches the PHP errors raised before Event Logger Nodes boots, and lets a hub's block fetch load only the substrate and the logger. Event Logger Nodes writes the measurements to the firehose when it is active; nothing here depends on it.
 * Version: 0.160.0
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
 * Its error handler is registered here, ahead of every plugin, so a warning a
 * plugin raises while it loads is caught; it chains to the handler before it.
 *
 * A hub's fetch of a log block, a POST to the substrate's `/command` route
 * carrying only `raw-logs` messages, loads newspack-nodes and the event logger
 * alone; see `newspack_profiler_lean_plugins()`.
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
 * them a second time. `plugins` waits for the event logger's deferred
 * bootstrap, which writes the rows through `Log_Manager::flush_plugin_loads()`
 * and empties the list.
 *
 * `php_errors` holds each distinct PHP error the handler below caught, by its
 * level, file and line, with its first message, how many times it was raised
 * and when first; past `NEWSPACK_PROFILER_PHP_ERRORS_MAX` keys,
 * `php_errors_dropped` counts the raises left out. An error
 * `wp_trigger_error()` raised in core keeps the files and lines of its stack,
 * which tell one misuse from another and lead the logger to the code that
 * misused core. `Log_Manager::flush_php_errors()` takes the buffer at the
 * same bootstrap and leaves null, which stops the buffering, and pops
 * `php_error_handler` while it is still on top of the stack.
 *
 * @var array{request_time:int,request_ts:float,plugins:list<array{slug:string,start_ts:float,duration_ns:int|float,new_classes:int,new_files:int}>,php_errors:array<string,array{errno:int,message:string,file:string,line:int,silenced:bool,times:int,ts:float,frames:list<array{file:string,line:int}>}>|null,php_errors_dropped:int,php_error_handler:\Closure|null} $newspack_profiler
 */
global $newspack_profiler;
$newspack_profiler = [
	'request_time'       => \hrtime( true ),
	'request_ts'         => \microtime( true ),
	'plugins'            => [],
	'php_errors'         => [],
	'php_errors_dropped' => 0,
	'php_error_handler'  => null,
];

// Query Monitor's levels, every one but the fatals; `App\Php_Errors::MASK`.
\defined( 'NEWSPACK_PROFILER_PHP_ERROR_MASK' ) || \define( 'NEWSPACK_PROFILER_PHP_ERROR_MASK', \E_ALL & ~( \E_ERROR | \E_PARSE | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR ) );

// Distinct errors buffered before the logger boots; past it, only counted.
\defined( 'NEWSPACK_PROFILER_PHP_ERRORS_MAX' ) || \define( 'NEWSPACK_PROFILER_PHP_ERRORS_MAX', 100 );

/** @var callable|null $newspack_profiler_previous The error handler before the one below. */
$newspack_profiler_previous = null;

/**
 * Buffer a PHP error until the event logger boots, then chain to the handler
 * before this one with the same arguments, whatever buffering threw; a
 * throw then propagates.
 *
 * Every level the mask admits is buffered; the logger keeps the ones it
 * records. `silenced` says `error_reporting` left it out, as `@` does.
 *
 * @param int    $errno   The error's level.
 * @param string $errstr  Its message.
 * @param string $errfile The file it was raised in.
 * @param int    $errline The line.
 * @return bool What the handler before answered; false with none, so PHP's own runs.
 */
$newspack_profiler['php_error_handler'] = static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$newspack_profiler_previous ): bool {
	/** @var array{php_errors:array<string,array{errno:int,message:string,file:string,line:int,silenced:bool,times:int,ts:float,frames:list<array{file:string,line:int}>}>|null,php_errors_dropped:int} $newspack_profiler */
	global $newspack_profiler;
	try {
		if ( \is_array( $newspack_profiler['php_errors'] ) ) {
			$key    = "{$errno}\0{$errfile}\0{$errline}";
			$frames = [];
			if ( \in_array( $errno, [ \E_USER_WARNING, \E_USER_NOTICE, \E_USER_DEPRECATED ], true ) && \str_ends_with( $errfile, '/wp-includes/functions.php' ) ) {
				// From trigger_error() outward, which the replay walks.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- locates the misuse.
				foreach ( \debug_backtrace( \DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) {
					if ( isset( $frame['file'] ) ) {
						$frames[] = [
							'file' => $frame['file'],
							'line' => $frame['line'] ?? 0,
						];
						$key     .= "\0{$frame['file']}:" . ( $frame['line'] ?? 0 );
					}
				}
			}
			if ( isset( $newspack_profiler['php_errors'][ $key ] ) ) {
				++$newspack_profiler['php_errors'][ $key ]['times'];
			} elseif ( \count( $newspack_profiler['php_errors'] ) < \NEWSPACK_PROFILER_PHP_ERRORS_MAX ) {
				$newspack_profiler['php_errors'][ $key ] = [
					'errno'    => $errno,
					'message'  => $errstr,
					'file'     => $errfile,
					'line'     => $errline,
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reads the level; sets nothing.
					'silenced' => 0 === ( \error_reporting() & $errno ),
					'times'    => 1,
					'ts'       => \microtime( true ),
					'frames'   => $frames,
				];
			} else {
				++$newspack_profiler['php_errors_dropped'];
			}
		}
	} finally {
		$answer = null !== $newspack_profiler_previous && false !== $newspack_profiler_previous( $errno, $errstr, $errfile, $errline );
	}
	return $answer;
};
// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- catches load-time errors.
$newspack_profiler_previous = \set_error_handler( $newspack_profiler['php_error_handler'], \NEWSPACK_PROFILER_PHP_ERROR_MASK );

\add_action(
	'plugins_loaded',
	/**
	 * Stop buffering once every plugin has loaded, unless the event logger
	 * took the buffer at its bootstrap, and leave the stack while still on
	 * top of it; beneath a handler installed over it, it only chains.
	 */
	static function (): void {
		/** @var array{php_errors:array<string,mixed>|null,php_error_handler:\Closure} $newspack_profiler */
		global $newspack_profiler;
		if ( ! \is_array( $newspack_profiler['php_errors'] ) ) {
			return;
		}
		$newspack_profiler['php_errors'] = null;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- reads the top, which PHP offers no getter for.
		$top = \set_error_handler( static fn (): bool => false );
		\restore_error_handler();
		if ( $top === $newspack_profiler['php_error_handler'] ) {
			\restore_error_handler();
		}
	},
	\PHP_INT_MAX
);

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

if ( ! \function_exists( 'newspack_profiler_lean_plugins' ) ) {
	/**
	 * The plugins a request loads. A hub fetching a block of a log sends a
	 * POST to the substrate's `/command` route in which every message goes to
	 * `raw-logs`. That request loads two plugins: the substrate, which
	 * answers it, and the event logger, which logs it. Each is matched by its
	 * file name, whatever directory holds it. The request loads none of the
	 * site's other plugins, which cost a WP Cloud site about a second to start
	 * and serve nothing the request asks for. Either of the two may be absent:
	 * the event logger boots silently without the substrate, and when the
	 * site has neither, the route does not exist and the request answers 404.
	 * Every other request loads every active plugin.
	 *
	 * Skipping a plugin removes all of its behaviour, any restriction it
	 * places on REST requests included; the substrate's own signature check
	 * still decides every message. The match is exact, so that no other
	 * script runs without the site's plugins. WordPress's front controller
	 * must serve the request, with no path info: the request has loaded
	 * `wp-blog-header.php` from `ABSPATH` before the plugin loop, wherever
	 * the server says the script sits. That holds on WP Cloud, which serves
	 * core's `index.php` through the docroot's `__wp__` symlink, and on a
	 * site whose `index.php` sits at the home path above WordPress's own
	 * directory. Core's `wp-signup.php` and `wp-activate.php` load the header
	 * only after `wp-load.php` has run the loop, and the path below keeps any
	 * other script that loads it first off the match. The path must be the
	 * install's own `/wp-json` route under pretty permalinks, or the
	 * install's root or its `index.php` with `rest_route`. The body must be
	 * the substrate's `text/plain`, which PHP never parses as a form. The
	 * body is read only when all of that holds, and a line that is not a
	 * packed message addressed to `raw-logs` loads the whole site.
	 *
	 * @param list<string>         $plugins The active plugins, as `active_plugins` lists them.
	 * @param array<array-key,mixed> $server The request's `$_SERVER`.
	 * @param \Closure():string    $body    Reads the request body.
	 * @param string               $abspath WordPress's own directory, `ABSPATH`.
	 * @param \Closure():list<string> $loaded Lists the files PHP has loaded, as `get_included_files()` does.
	 * @return list<string> The plugins to load, in the site's order.
	 */
	function newspack_profiler_lean_plugins( array $plugins, array $server, \Closure $body, string $abspath, \Closure $loaded ): array {
		$field = static fn ( string $key ): string => \is_string( $server[ $key ] ?? null ) ? $server[ $key ] : '';
		$home  = \get_option( 'home' );
		$root  = \rtrim( (string) \wp_parse_url( \is_string( $home ) ? $home : '', \PHP_URL_PATH ), '/' );
		if ( 'POST' !== $field( 'REQUEST_METHOD' )
			|| '' !== $field( 'PATH_INFO' )
			|| ! \str_starts_with( \strtolower( $field( 'CONTENT_TYPE' ) ), 'text/plain' ) ) {
			return $plugins;
		}
		$route = '/newspack-nodes/v1/command';
		$uri   = $field( 'REQUEST_URI' );
		$path  = (string) \wp_parse_url( $uri, \PHP_URL_PATH );
		\parse_str( (string) \wp_parse_url( $uri, \PHP_URL_QUERY ), $query );
		// Under plain permalinks, WordPress renders a page for a /wp-json path.
		$by_path  = '' !== \get_option( 'permalink_structure' ) && "{$root}/wp-json{$route}" === $path && [] === $query;
		$by_query = \in_array( $path, [ "{$root}/", "{$root}/index.php" ], true ) && $route === ( $query['rest_route'] ?? null );
		if ( ! $by_path && ! $by_query ) {
			return $plugins;
		}
		$wordpress = \realpath( $abspath );
		if ( false === $wordpress || ! \in_array( \rtrim( $wordpress, '/' ) . '/wp-blog-header.php', $loaded(), true ) ) {
			return $plugins;
		}
		$lines = \array_filter( \explode( "\n", $body() ), static fn ( string $line ): bool => '' !== \trim( $line ) );
		if ( [] === $lines ) {
			return $plugins;
		}
		foreach ( $lines as $line ) {
			$message = \json_decode( $line, true );
			// TO sits at index 3; the Message class has not loaded yet.
			if ( ! \is_array( $message ) || 'raw-logs' !== ( $message[3] ?? null ) ) {
				return $plugins;
			}
		}
		// Match each plugin by file name, whatever directory holds it.
		return \array_values( \array_filter( $plugins, static fn ( string $plugin ): bool => \in_array( \basename( $plugin ), [ 'newspack-nodes.php', 'newspack-event-logger-nodes.php' ], true ) ) );
	}
}

/**
 * Request-body seam for the narrowing below: replaces the read of
 * `php://input`. Tests assign a closure before loading the drop-in.
 * Signature: `function (): string`.
 *
 * @var \Closure():string|null $newspack_profiler_body
 */
global $newspack_profiler_body;
// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile -- the request body, not a remote file.
$newspack_profiler_body ??= static fn (): string => (string) \file_get_contents( 'php://input' );

/**
 * Loaded-files seam for the narrowing below: replaces `get_included_files()`.
 * Tests assign a closure before loading the drop-in.
 * Signature: `function (): list<string>`.
 *
 * @var \Closure():list<string>|null $newspack_profiler_loaded
 */
global $newspack_profiler_loaded;
$newspack_profiler_loaded ??= static fn (): array => \get_included_files();

\add_filter(
	'option_active_plugins',
	/**
	 * Narrow the read of `active_plugins` that WordPress's plugin loop makes,
	 * from inside `wp_get_active_and_valid_plugins()`, during a hub's fetch;
	 * see `newspack_profiler_lean_plugins()`. Only that read is narrowed. A
	 * read from anywhere else, an mu-plugin's or a plugin's, returns the
	 * stored list, so a write built from it keeps every plugin the site
	 * activated.
	 *
	 * @param mixed $plugins The stored `active_plugins` value.
	 * @return mixed The plugins to load.
	 */
	static function ( $plugins ) {
		/**
		 * @var \Closure():string       $newspack_profiler_body
		 * @var \Closure():list<string> $newspack_profiler_loaded
		 */
		global $newspack_profiler_body, $newspack_profiler_loaded;
		// Every read passes here; only a POST pays for the backtrace.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		if ( ! \is_array( $plugins ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return $plugins;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- finds the plugin loop's read.
		$callers = \array_column( \debug_backtrace( \DEBUG_BACKTRACE_IGNORE_ARGS, 12 ), 'function' );
		if ( ! \in_array( 'wp_get_active_and_valid_plugins', $callers, true ) ) {
			return $plugins;
		}
		return \newspack_profiler_lean_plugins( \array_values( \array_filter( $plugins, 'is_string' ) ), $_SERVER, $newspack_profiler_body, \ABSPATH, $newspack_profiler_loaded );
	},
	2
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
