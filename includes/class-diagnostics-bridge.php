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
 *    into a dedicated single-writer `errors.fleet.p0` log so the alert always
 *    reaches the Error Log. That dir is matched by the dashboard's existing
 *    `errors.*` subscription glob (zero UI change) and NOT co-written with the
 *    request-builder's `void_warranty` errors.p* partitions — whose >PIPE_BUF
 *    single-writer appends a foreign atomic append could tear.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Diagnostics_Bridge {

	/**
	 * Producer basename for the fleet-alert log. Registered on
	 * `newspack_nodes/registered_log_producers` so Log_Cleaner keeps its
	 * `{producer}.p{N}` dirs; the writer targets the single `.p0`.
	 */
	public const FLEET_PRODUCER = 'errors.fleet';

	/** @var string Stable synthetic id keying the fleet-alert entries (Message::KEY). */
	private const FLEET_KEY = 'fleet';

	// Probe-log geometry: 1 MiB x 2 segments, keep a day, default cap.
	private const SEGMENT_SIZE = 1048576;
	private const MIN_SEGMENTS = 2;
	private const MAX_SEGMENTS = 2;
	private const MIN_LIFETIME = 86400;
	private const MAX_LIFETIME = 0;

	/**
	 * errors.fleet.p0 write seam (filesystem side-effect). Lazily-defaulted at the
	 * call site to a closure that fills + flushes the real Partition; tests reassign
	 * it to force a throw and prove on_alert swallows it, WITHOUT short-circuiting
	 * the partition-build + fit_to_line path (which stays under real coverage).
	 *
	 * Signature: `function ( Partition_Node $partition, array $packed ): void`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $write_seam = null;

	/** @var Partition_Node|null Lazily-built single-writer errors.fleet.p0, reused across the process. */
	private static ?Partition_Node $errors_partition = null;

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
	 * Direct errors.fleet.p0 write for the no-active-logger case, mirroring the
	 * Request_Builder emit_error entry shape ({ n, k:'alert', m, ts }, KEY=fleet).
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
		$msg                       = self::fit_to_line( $msg );
		if ( null === $msg ) {
			return; // Pathologically unfittable — drop, never emit oversize.
		}
		$write = self::$write_seam ?? self::real_write( ... );
		$write( self::errors_partition(), $msg );
	}

	/**
	 * The default errors.fleet.p0 write (fill + flush) that `$write_seam` stands in
	 * for. A named method so its `array<int, mixed>` packed-message type is declared
	 * for the Partition::fill() contract.
	 *
	 * @param array<int, mixed> $packed Packed alert message.
	 */
	private static function real_write( Partition_Node $partition, array $packed ): void {
		$partition->fill( $packed );
		$partition->flush();
	}

	/**
	 * Fit the entry to the log's physical boundary: the PACKED line (with newline)
	 * must stay under PIPE_BUF or the default-cap Partition drops it. A character
	 * cap is a proxy — JSON escaping packs a multibyte char as up to 6 bytes — so
	 * measure packed_size and halve `m` until it fits. Null when nothing is left to
	 * cut. Mirrors the substrate's Job_Probe_Node::fit_to_line.
	 *
	 * @param array<int, mixed> $message The minted alert message.
	 * @return array<int, mixed>|null The fitting message, or null to drop.
	 */
	private static function fit_to_line( array $message ): ?array {
		while ( Message::packed_size( $message ) + 1 > Partition_Node::MAX_LINE_SIZE ) {
			$value = $message[ Message::VALUE ];
			if ( ! \is_array( $value ) ) {
				return null;
			}
			$m = Core::as_string( $value['m'] ?? '' );
			if ( '' === $m ) {
				return null;
			}
			$value['m']                = \mb_substr( $m, 0, \intdiv( \mb_strlen( $m ), 2 ) );
			$message[ Message::VALUE ] = $value;
		}
		return $message;
	}

	/**
	 * Build (once) the single-writer errors.fleet.p0 Partition. Anonymous: it writes
	 * by dir, needs no Core registration or sink.
	 */
	private static function errors_partition(): Partition_Node {
		if ( null !== self::$errors_partition ) {
			return self::$errors_partition;
		}
		$partition = new Partition_Node();
		$partition->arguments( [
			Config::get_logs_directory() . '/' . self::FLEET_PRODUCER . '.p0',
			(string) self::SEGMENT_SIZE,
			(string) self::MIN_SEGMENTS,
			(string) self::MAX_SEGMENTS,
			(string) self::MIN_LIFETIME,
			(string) self::MAX_LIFETIME,
		] );
		self::$errors_partition = $partition;
		return $partition;
	}

	/**
	 * Drop the cached writer. Only tests need this — the process-lifetime cache is
	 * correct in production (the logs dir is stable).
	 *
	 * @api Used by tests.
	 */
	public static function reset(): void {
		self::$errors_partition?->remove_node();
		self::$errors_partition = null;
	}
}
