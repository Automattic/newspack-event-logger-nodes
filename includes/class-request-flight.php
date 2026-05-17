<?php
/**
 * RequestFlight: hidden sibling Node attached to RequestBuilder. Mirrors
 * Perl Tachikoma's InstrumentalityFlight.pm. Periodically snapshots the
 * patron's in-progress request map and emits a compact-summary batch to
 * the configured target (gyroscope partition).
 *
 * Hidden from the topology editor by the substrate's patron filter in
 * dump_metadata; configuration surfaces on the patron's :config CI as
 * `set_inflight_target` / `set_inflight_interval`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer;

\defined( 'ABSPATH' ) || exit;

class RequestFlight extends Timer {
	private const DEFAULT_INTERVAL_MS = 1000;

	private int $interval_ms = self::DEFAULT_INTERVAL_MS;

	public function set_interval( int $ms ): void {
		$this->interval_ms = $ms;
		$this->set_timer( $ms );
	}

	public function interval(): int {
		return $this->interval_ms;
	}

	public function fire_cb(): void {
		$patron = $this->patron();
		if ( null === $patron || ! \method_exists( $patron, 'inflight_snapshot' ) ) {
			return;
		}
		$batch = $patron->inflight_snapshot();
		if ( empty( $batch ) ) {
			return;
		}
		// Target is typed string|array<string> on Node; RequestFlight only
		// emits to a single named partition (the gyroscope), so reject the
		// array form rather than silently stringifying it ("Array").
		if ( ! \is_string( $this->target ) || '' === $this->target ) {
			return;
		}
		if ( null === $this->sink ) {
			// Sink propagates from RequestBuilder via overridden sink() in Task 22.
			return;
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = $this->target;
		$msg[ Message::KEY ]       = 'inflight';
		$msg[ Message::VALUE ]     = $batch;
		$this->sink->fill( $msg );
	}
}
