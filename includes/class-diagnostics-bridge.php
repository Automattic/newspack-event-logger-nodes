<?php
/**
 * Diagnostics Bridge
 *
 * Carries the substrate's `newspack_nodes/stderr` seam into the event-logger:
 * every substrate stderr() / print_less_often() line is logged to the ACTIVE
 * request (or job context) as a `stderr` entry, so it shows in that request's
 * detail and — because `Request_Builder_Node` routes the `stderr` keyword to
 * its errors target — in the Error Log. With no started request logger the line
 * is dropped; the substrate's default handler already error_log()s it. Fleet
 * alerts do not pass through here: the substrate journals them itself into
 * `alerts.p0`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `newspack_nodes/stderr` listener, registered at file scope in
 * `newspack-event-logger-nodes.php`. Stateless — one static entry point.
 */
class Diagnostics_Bridge {

	/**
	 * Log one substrate stderr line to the active request; drop it when no
	 * logger has started.
	 *
	 * Two substrate guards make the bare one-liner safe. The seam wraps every
	 * listener in its own try/catch, so a throw here cannot break the
	 * last-resort diagnostic path — no local try/catch is needed. And
	 * `Core::$in_stderr` short-circuits re-entry to `error_log()`, so the
	 * `Core::print_less_often()` that `Log_Manager::message()` emits on an
	 * oversize payload cannot recurse back into this listener.
	 *
	 * @param string $line The stderr line. `Core::stderr()` prefixes it with the
	 *                     process-identity midfix (host, argv0, pid, uptime);
	 *                     the timestamp prefix lands downstream, and a raw
	 *                     `Core::_stderr()` caller such as `Shell_Node` supplies
	 *                     neither.
	 */
	public static function on_stderr( string $line ): void {
		Log_Manager::started_instance()?->message( 'stderr', [ 'm' => $line ] );
	}
}
