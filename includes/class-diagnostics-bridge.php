<?php
/**
 * Diagnostics Bridge
 *
 * Carries the substrate's `newspack_nodes/stderr` seam into the event-logger:
 * every substrate stderr() / print_less_often() line is logged to the ACTIVE
 * request (or job context) as a `stderr` entry so it shows in that request's
 * detail and — via Request_Builder — the Error Log. With no started request
 * logger the line is dropped (the substrate's default handler already
 * error_log()s it). Fleet alerts no longer pass through here: the substrate
 * journals them itself into `alerts.p0`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Diagnostics_Bridge {

	/**
	 * `newspack_nodes/stderr` listener: log the line to the active request when a
	 * started logger exists; otherwise drop it. (The substrate's stderr seam wraps
	 * this in its own throw-guard, so no local try/catch is needed here.)
	 *
	 * @param string $line The stderr line (already timestamp-prefixed by the substrate).
	 */
	public static function on_stderr( string $line ): void {
		Log_Manager::started_instance()?->message( 'stderr', [ 'm' => $line ] );
	}
}
