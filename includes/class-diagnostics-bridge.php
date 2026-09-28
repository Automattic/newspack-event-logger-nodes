<?php
/**
 * Diagnostics Bridge
 *
 * Carries two substrate seams into the event-logger: the verb span through
 * `Command_Interpreter_Node::$around_dispatch` (see the architecture guide),
 * and the `newspack_nodes/stderr` seam. Every line the substrate emits
 * through `Core::_stderr()` — `stderr()`, `print_less_often()` and raw
 * callers such as `Shell_Node` alike — is logged to the ACTIVE request (or
 * job context) as a `stderr` entry. That puts it in the request's detail,
 * and — because `Request_Builder_Node` routes the `stderr` keyword to its
 * errors target — in the Error Log. With no started request logger the line
 * is dropped; the substrate's default handler already error_log()s it. Fleet
 * alerts do not pass through here: the substrate journals them itself into
 * `alerts.p0`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The verb wrapper and the `newspack_nodes/stderr` listener. The deferred
 * bootstrap in `newspack-event-logger-nodes.php` calls `install()`; the
 * listener is registered at that file's scope. Stateless.
 */
class Diagnostics_Bridge {

	/**
	 * Wrap whatever `Command_Interpreter_Node::$around_dispatch` holds in the
	 * verb span, so a wrapper another plugin assigned first runs inside it.
	 */
	public static function install(): void {
		Command_Interpreter_Node::$around_dispatch = self::around_dispatch( Command_Interpreter_Node::$around_dispatch );
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
	 * nothing, and `$inner` is handed it unrendered. A substrate that passes
	 * three arguments leaves it null, and `m` is the node's name.
	 *
	 * @param \Closure|null $inner The wrapper this one encloses; null runs the handler.
	 * @return \Closure(Command_Interpreter_Node, string, \Closure(): mixed, (\Closure(): string)|null=): mixed The `$around_dispatch` value.
	 */
	public static function around_dispatch( ?\Closure $inner ): \Closure {
		return static function ( Command_Interpreter_Node $ci, string $verb, \Closure $run, ?\Closure $command = null ) use ( $inner ): mixed {
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
				[ 'm' => null === $command ? $ci->name() : $command() ]
			);
		};
	}

	/**
	 * Log one substrate stderr line to the active request; drop it when no
	 * logger has started.
	 *
	 * Two substrate guards make the bare one-liner safe. The seam wraps every
	 * listener in its own try/catch, so a throw here cannot break the
	 * last-resort diagnostic path — no local try/catch is needed. And
	 * `Core::$in_stderr` short-circuits re-entry to `error_log()`, so the
	 * `print_less_often()` a failed `Partition_Node` write emits below
	 * `message()` cannot recurse back into this listener.
	 *
	 * @param string $line The stderr line. `Core::stderr()` prefixes it with
	 *                     the process-identity midfix (host, argv0, pid,
	 *                     uptime); the timestamp prefix is stamped downstream,
	 *                     and a raw caller supplies neither.
	 */
	public static function on_stderr( string $line ): void {
		Log_Manager::started_instance()?->message( 'stderr', [ 'm' => $line ] );
	}
}
