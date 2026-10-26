<?php
/**
 * Diagnostics Bridge
 *
 * Carries three substrate seams into the event-logger: the verb span through
 * `Command_Interpreter_Node::$around_dispatch` (see the architecture guide),
 * the span `Core::span()` opens through `Core::$around_span`, such as a
 * Table's purge or WAL checkpoint, and the `newspack_nodes/stderr` seam.
 * Every line the substrate emits through `Core::_stderr()` — `stderr()`,
 * `print_less_often()`, `stderr_to_seam()` and raw callers such as
 * `Shell_Node` alike — is logged to the ACTIVE request (or job context)
 * under the keyword the seam names. A `stderr` line lands in the request's
 * detail and — because `Request_Builder_Node` routes the `stderr` keyword to
 * its errors target — in the Error Log; a `Core::stderr()` line also reaches
 * the substrate's handler, which error_log()s it. A `stderr_to_seam()` line,
 * such as a worker's routine stop under `worker (stop)`, reaches this seam
 * alone and the request's detail alone. With no started request logger the
 * line is dropped. Fleet alerts do not pass through here: the substrate
 * journals them itself into `alerts.p0`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The verb and span wrappers and the `newspack_nodes/stderr` listener. The
 * deferred bootstrap in `newspack-event-logger-nodes.php` calls `install()`
 * and registers the listener beside it. Stateless.
 */
class Diagnostics_Bridge {

	/**
	 * Wrap whatever `Command_Interpreter_Node::$around_dispatch` and
	 * `Core::$around_span` hold in this plugin's spans, so a wrapper another
	 * plugin assigned first runs inside each.
	 */
	public static function install(): void {
		Command_Interpreter_Node::$around_dispatch = self::around_dispatch( Command_Interpreter_Node::$around_dispatch );
		Core::$around_span                         = self::around_span( Core::$around_span );
	}

	/**
	 * A wrapper that runs each substrate span — through `$inner` when one was
	 * there — as a span on the active request, its `(complete)` `m` the
	 * detail `$describe` reads off the result, and bare when no logger has
	 * started, describing nothing.
	 *
	 * @param \Closure|null $inner The wrapper this one encloses; null runs the work.
	 * @return \Closure(string, \Closure(): mixed, \Closure(mixed): string): mixed The `$around_span` value.
	 */
	public static function around_span( ?\Closure $inner ): \Closure {
		return static function ( string $label, \Closure $work, \Closure $describe ) use ( $inner ): mixed {
			if ( null !== $inner ) {
				$work = static fn (): mixed => $inner( $label, $work, $describe );
			}
			$lm = Log_Manager::started_instance();
			return null === $lm ? $work() : $lm->timed( $label, $work, $describe );
		};
	}

	/**
	 * A wrapper that runs each verb — through `$inner` when one was there —
	 * inside its own span on the active request, and bare when no logger has
	 * started, before anything names the span. The architecture guide states
	 * the span.
	 *
	 * The `(start)` line's `m` is what `$command` renders: the command line
	 * as the REPL echoes it, a declared secret option's value masked. It
	 * renders only once a logger has started, so an unlogged dispatch pays
	 * nothing, and `$inner` is handed it unrendered.
	 *
	 * @param \Closure|null $inner The wrapper this one encloses; null runs the handler.
	 * @return \Closure(Command_Interpreter_Node, string, \Closure(): mixed, \Closure(): string): mixed The `$around_dispatch` value.
	 */
	public static function around_dispatch( ?\Closure $inner ): \Closure {
		return static function ( Command_Interpreter_Node $ci, string $verb, \Closure $run, \Closure $command ) use ( $inner ): mixed {
			if ( null !== $inner ) {
				$run = static fn (): mixed => $inner( $ci, $verb, $run, $command );
			}
			$lm = Log_Manager::started_instance();
			if ( null === $lm ) {
				return $run();
			}
			return $lm->timed(
				Command_Interpreter_Node::shell_name_for( $ci->patron() ?? $ci ) . " {$verb}" . Flame_Tree::COMMAND_SUFFIX,
				$run,
				static fn (): string => 'ok',
				[ 'm' => $command() ]
			);
		};
	}

	/**
	 * Log one substrate stderr line to the active request under the keyword
	 * the seam names; drop it when no logger has started.
	 *
	 * Two substrate guards make the bare one-liner safe. The seam wraps every
	 * listener in its own try/catch, so a throw here cannot break the
	 * last-resort diagnostic path — no local try/catch is needed. And
	 * `Core::$in_stderr` short-circuits re-entry to `error_log()`, so the
	 * `print_less_often()` a failed `Partition_Node` write emits below
	 * `message()` cannot recurse back into this listener.
	 *
	 * @param string $line    The stderr line. `Core::stderr()` prefixes it with
	 *                        the process-identity midfix (host, argv0, pid,
	 *                        uptime); the timestamp prefix is stamped downstream,
	 *                        and a raw caller supplies neither.
	 * @param string $keyword The category it is written under: `stderr`, or a
	 *                        routine line's own, such as `worker (stop)`.
	 */
	public static function on_stderr( string $line, string $keyword ): void {
		Log_Manager::started_instance()?->message( $keyword, [ 'm' => $line ] );
	}
}
