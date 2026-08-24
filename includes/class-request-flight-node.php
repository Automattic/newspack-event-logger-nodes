<?php
/**
 * RequestFlight: hidden sibling Node attached to RequestBuilder. On every Router
 * tick it snapshots the patron's in-progress request map and emits ONE compact
 * TM_STRUCT row per in-flight request to the configured target (the gyroscope
 * partition), which is what the Gyroscope dashboard renders. Its non-public DN
 * ancestor is named in newspack-nodes' docs/tachikoma-lineage.md.
 *
 * Hidden twice over: the substrate's patron filter in dump_metadata drops it
 * from the live canvas, and node_schema's `Hidden` category drops it from the
 * palette. Configuration surfaces on the patron's :config interpreter as
 * `set_inflight_target` (a non-empty target enables snapshots, an empty one
 * stops them) and `set_inflight_delta`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Line_Fitter;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer_Node;

\defined( 'ABSPATH' ) || exit;

/**
 * Timer sibling that puts RequestBuilder's in-flight map on the wire.
 *
 * It holds no copy of that map, and none of the delta toggle either: the fire
 * watermark is the only state, and both the rows and the toggle are read live
 * off the patron at fire time.
 */
class Request_Flight_Node extends Timer_Node {

	/** Wall-clock watermark of the last fire that emitted rows (delta mode). */
	private float $last_fire_ts = 0.0;

	/**
	 * Router-TIMER tick: snapshot the patron's in-flight map and emit ONE
	 * TM_STRUCT message per in-flight request (KEY=rid, the Tachikoma shape) to
	 * the configured gyroscope target — never a single batched list, which
	 * crossed the 4KB cap under load. Timer_Node::fire_cb screens the null-sink
	 * case and the interval pacing before calling this.
	 *
	 * A row that still exceeds the Partition line cap after Line_Fitter halves
	 * `url` and `user_agent` is dropped with a rate-limited warning; Partition
	 * would otherwise drop it silently.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		// Guards also narrow target (string) + sink (Node) for the analyzer.
		if ( ! \is_string( $this->target ) || '' === $this->target || null === $this->sink ) {
			return;
		}
		// Local narrows past the intervening calls (a property would re-widen).
		$sink = $this->sink;
		$rows = $this->inflight_snapshot();
		if ( empty( $rows ) ) {
			return;
		}
		$watermark = $this->last_fire_ts;
		$now       = Core::$now > 0.0 ? Core::$now : Core::right_now();
		$emitted   = false;
		$delta     = $this->delta();
		foreach ( $rows as $rid => $row ) {
			// Delta: skip a row with no activity since the previous fire.
			if ( $delta && Core::as_float( $row['last_log_ts'] ?? 0 ) < $watermark ) {
				continue;
			}
			$message                       = Message::new_message();
			$message[ Message::TYPE ]      = Message::TM_STRUCT;
			$message[ Message::TIMESTAMP ] = Core::$now;
			$message[ Message::FROM ]      = $this->name;
			$message[ Message::TO ]        = $this->target;
			$message[ Message::KEY ]       = (string) $rid;
			$message[ Message::VALUE ]     = $row;
			$fitted = Line_Fitter::fit( $message, [ 'url', 'user_agent' ] );
			if ( null === $fitted ) {
				$this->print_less_often( 'WARNING: dropping oversize in-flight row for ', (string) $rid );
				continue;
			}
			$sink->fill( $fitted );
			$emitted = true;
		}
		// Watermark advances only when rows emitted (reference parity).
		if ( $emitted ) {
			$this->last_fire_ts = $now;
		}
	}

	/**
	 * Snapshot the in-flight request map as compact rows keyed by rid, the form
	 * fire() emits to the gyroscope partition for cross-spoke aggregation.
	 *
	 * Reads the patron's live LRU_Cache — the canonical in-flight map — and
	 * returns an empty array when no Request_Builder patron is attached. Each
	 * row's `state` carries the top-of-stack hook name, `time_ms` the span from
	 * process start to the last log line, `est_ms` that span plus the age of the
	 * tracker timestamp (elapsed time for a request still running), and `lag_ms`
	 * how far the tracker has run past that last line. The rid rides
	 * Message::KEY on the wire, never duplicated in the row. (PHP coerces an
	 * all-digits rid to an int key; fire() casts it back.)
	 *
	 * @return array<array-key,array<string,mixed>> Compact rows keyed by rid.
	 */
	public function inflight_snapshot(): array {
		$patron = $this->patron();
		if ( ! $patron instanceof Request_Builder_Node ) {
			return [];
		}
		$out = [];
		$now = ( Core::$now > 0.0 ? Core::$now : Core::right_now() );
		foreach ( $patron->cache->iterate() as $rid => $request ) {
			if ( ! $request instanceof \stdClass ) {
				continue;
			}
			// Rebuild string-keyed prop map so extract_* gets array<str,mixed>.
			$vars = \get_object_vars( $request );
			$r    = \array_combine( \array_map( '\strval', \array_keys( $vars ) ), \array_values( $vars ) );
			// Process-start ts: mu-profiler wall-clock load (before plugins).
			$ts_v          = $r['timestamp'] ?? 0;
			$start_time    = Core::as_float( $ts_v );
			$last_log_v    = $r['last_log_ts'] ?? $start_time;
			$last_log_ts   = Core::as_float( $last_log_v, $start_time );
			$tracker_v     = $r['tracker_ts'] ?? $now;
			$tracker_ts    = Core::as_float( $tracker_v, $now );
			$time_ms       = ( $last_log_ts - $start_time ) * 1000;
			$age_ms        = ( $now - $tracker_ts ) * 1000;
			$method_v      = $r['request_method'] ?? 'GET';
			$remote_addr_v = $r['remote_addr'] ?? '';
			$user_agent_v  = $r['user_agent'] ?? '';
			// Display clips identical to build_compact_summary (byte-based).
			$url        = Request_Builder_Node::resolved_request_url( $request );
			$url        = Request_Builder_Node::clip( $url, Request_Builder_Node::MAX_URL_LENGTH );
			$user_agent = Core::as_string( $user_agent_v );
			$user_agent = Request_Builder_Node::clip( $user_agent, Request_Builder_Node::MAX_USER_AGENT_LENGTH );
			$out[ Core::as_string( $rid ) ] = [
				'method'      => Core::as_string( $method_v, 'GET' ),
				'url'         => $url,
				'state'       => Request_Builder_Node::extract_state( $r ),
				'what'        => Request_Builder_Node::extract_what( $r ),
				'time_ms'     => \round( $time_ms, 1 ),
				'est_ms'      => \round( $time_ms + $age_ms, 1 ),
				'start_time'  => $start_time,
				'last_log_ts' => $last_log_ts,
				'lag_ms'      => \max( 0, \round( ( $tracker_ts - $last_log_ts ) * 1000, 1 ) ),
				'remote_addr' => Core::as_string( $remote_addr_v ),
				'user_agent'  => $user_agent,
			];
		}
		return $out;
	}

	/**
	 * Delta mode, read live off the patron the same way the rows are — the
	 * `set_inflight_delta` verb writes the patron's field, and this node keeps
	 * no copy to fall out of step with it. Off (full per-tick re-emit) with no
	 * Request_Builder patron attached, which is the no-rows case anyway.
	 */
	private function delta(): bool {
		$patron = $this->patron();
		return $patron instanceof Request_Builder_Node && $patron->inflight_delta();
	}

	/**
	 * Setting a destination IS what enables snapshots: a non-empty target
	 * registers the Router-TIMER hitchhike (no-arg set_timer), clearing it
	 * stops snapshotting. The snapshot cadence is the Router's tick — there is
	 * no separate interval knob. The hitchhike preconditions (named sibling +
	 * live _router) are the worker's job, same as Request_Builder's arguments().
	 *
	 * @param array<int,string>|string|null $value New target; null reads it.
	 * @return array<int,string>|string The target after the call.
	 */
	public function target( $value = null ) {
		if ( null === $value ) {
			return parent::target();
		}
		$result = parent::target( $value );
		if ( \is_string( $value ) && '' !== $value ) {
			$this->set_timer();
		} else {
			$this->stop_timer();
		}
		return $result;
	}

	/**
	 * Hidden from the palette: this is a patron-linked sibling Request_Builder
	 * mounts at runtime, not a node you'd drag onto a topology. The patron
	 * filter in dump_metadata hides it from the LIVE canvas; this hides it
	 * from the palette (whose source is the static class catalog, not the
	 * live registry).
	 *
	 * @api Used by substrate.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category' => 'Hidden',
		] );
	}
}
