<?php
/**
 * Diagnostics Bridge
 *
 * Carries the substrate's application-agnostic diagnostic seams into the
 * event-logger's firehose / Error Log:
 *
 *  - `newspack_nodes/stderr` — every substrate stderr() / print_less_often()
 *    line; logged to the ACTIVE request (or job context) as a `stderr` entry so
 *    it shows in that request's detail and — via Request_Builder — the Error Log.
 *    With no started request logger the line is dropped (the substrate's default
 *    handler already error_log()s it; a parallel durable sink for unmatched
 *    contexts is not wanted).
 *  - `newspack_nodes/alert` — each rate-limited fleet-health alert. It rides the
 *    active request logger when one is started; otherwise (the supervisor tick,
 *    where the logger is un-started / rule-gated / root) it is written DIRECTLY
 *    into the errors family, KEY='fleet' hash-routing to `errors.p{N}` — the same
 *    dirs the request-builder writes and the dashboard's `errors.*` glob covers
 *    (zero UI change). Every append in that family is now ≤PIPE_BUF atomic
 *    (Request_Builder and this bridge both fit via `Line_Fitter`, and no
 *    errors partition lifts the cap), so two writers on one dir is safe.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Topic_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Diagnostics_Bridge {

	/** @var string Stable synthetic id keying the fleet-alert entries (Message::KEY). */
	private const FLEET_KEY = 'fleet';

	/**
	 * errors-family write seam (filesystem side-effect). Lazily-defaulted at the
	 * call site to a closure that fills + flushes the real Topic; tests reassign it
	 * to force a throw and prove on_alert swallows it, WITHOUT short-circuiting the
	 * topic-build + Line_Fitter path (which stays under real coverage).
	 *
	 * Signature: `function ( Topic_Node $topic, array $packed ): void`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $write_seam = null;

	/** @var Topic_Node|null Lazily-built anonymous errors.p{partition} Topic, reused across the process. */
	private static ?Topic_Node $errors_topic = null;

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

	/**
	 * `newspack_nodes/alert` listener: one call per current alert. Throw-safe — the
	 * upstream `Alerts::emit` do_action is unguarded, so a throw here would unwind
	 * the whole supervisor tick and starve the sibling `supervisor_periodic`
	 * listeners (cron checks) during exactly the degraded conditions that fire
	 * alerts. Swallow to error_log, like the substrate's stderr-seam guard.
	 *
	 * @param array<string, mixed> $alert Substrate alert: { key, severity, message, ... }.
	 */
	public static function on_alert( array $alert ): void {
		try {
			$message = Core::as_string( $alert['message'] ?? '' );
			$logger  = Log_Manager::started_instance();
			if ( null !== $logger ) {
				$logger->alert( $message );
				return;
			}
			self::write_alert_to_errors( $message );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( 'newspack_nodes/alert bridge failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Direct errors.p{N} write for the no-active-logger case, mirroring the
	 * Request_Builder emit_error entry shape ({ n, k:'alert', m, ts }, KEY=fleet).
	 * KEY='fleet' routes the Topic to one partition the dashboard's `errors.*` glob
	 * covers.
	 *
	 * @param string $message Alert message.
	 */
	private static function write_alert_to_errors( string $message ): void {
		$entry                     = [ 'n' => 1, 'k' => 'alert', 'm' => $message, 'ts' => \microtime( true ) ];
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::KEY ]       = self::FLEET_KEY;
		$msg[ Message::VALUE ]     = $entry;
		$msg                       = Line_Fitter::fit( $msg, [ 'm' ] );
		if ( null === $msg ) {
			// Defensively dead ('m' always shrinks to fit); loud for parity.
			Core::print_less_often( 'DiagnosticsBridge: dropped an unfittable alert ', $message );
			return;
		}
		$write = self::$write_seam ?? self::real_write( ... );
		$write( self::errors_topic(), $msg );
	}

	/**
	 * The default errors-family write (fill + flush) that `$write_seam` stands in
	 * for. A named method so its `array<int, mixed>` packed-message type is declared
	 * for the Topic::fill() contract.
	 *
	 * @param array<int, mixed> $packed Packed alert message.
	 */
	private static function real_write( Topic_Node $topic, array $packed ): void {
		$topic->fill( $packed );
		$topic->flush();
	}

	/**
	 * Build (once) the anonymous errors.p{partition} Topic. KEY-routed by
	 * hash_to_partition, geometry matching the request-builder topology's
	 * errors:partition args (segment/retention from Config). Anonymous: it writes
	 * by dir, needs no Core registration or sink.
	 */
	private static function errors_topic(): Topic_Node {
		if ( null !== self::$errors_topic ) {
			return self::$errors_topic;
		}
		$num_partitions = \max( 1, Core::as_int( Config::value( 'num_partitions' ) ) );
		$topic          = new Topic_Node();
		$topic->arguments( [
			Config::get_logs_directory() . '/errors.p{partition}',
			(string) $num_partitions,
			(string) Core::as_int( Config::value( 'segment_size' ) ),
			(string) Core::as_int( Config::value( 'min_segments' ) ),
			(string) Core::as_int( Config::value( 'max_segments' ) ),
			(string) Core::as_int( Config::value( 'min_lifetime' ) ),
			(string) Core::as_int( Config::value( 'max_lifetime' ) ),
		] );
		self::$errors_topic = $topic;
		return $topic;
	}

	/**
	 * Drop the cached writer. Only tests need this — the process-lifetime cache is
	 * correct in production (the logs dir is stable).
	 *
	 * @api Used by tests.
	 */
	public static function reset(): void {
		self::$errors_topic?->remove_node();
		self::$errors_topic = null;
	}
}
