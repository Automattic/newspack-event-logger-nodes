<?php
/**
 * RequestFlight: hidden sibling Node attached to RequestBuilder. Mirrors
 * Perl Tachikoma's InstrumentalityFlight.pm. Periodically snapshots the
 * patron's in-progress request map and emits a compact-summary batch to
 * the configured target (gyroscope partition).
 *
 * Hidden from the topology editor by the substrate's patron filter in
 * dump_metadata; configuration surfaces on the patron's :config interpreter as
 * `set_inflight_target` (a non-empty target enables snapshots; an empty one stops them).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer_Node;

\defined( 'ABSPATH' ) || exit;

class Request_Flight_Node extends Timer_Node {

	/**
	 * Router-TIMER tick (Timer_Node::fire_cb guards the null-sink case and calls
	 * this). Snapshot the patron's in-flight map and emit one compact batch to the
	 * configured gyroscope target.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$batch = $this->inflight_snapshot();
		if ( empty( $batch ) ) {
			return;
		}
		// Reject Node's array-target form; Flight emits to one named partition.
		if ( ! \is_string( $this->target ) || '' === $this->target ) {
			return;
		}
		// Guard also narrows ?Node -> Node for the analyzer before ->fill().
		if ( null === $this->sink ) {
			return;
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->target;
		$message[ Message::KEY ]       = 'inflight';
		$message[ Message::VALUE ]     = $batch;
		$this->sink->fill( $message );
	}

	/**
	 * Snapshot the in-flight request map as a list of compact rows. Each row's
	 * `state` field carries the top-of-stack hook name. Consumed by RequestFlight's
	 * periodic fire — emitted to the gyroscope partition for cross-spoke
	 * aggregation.
	 *
	 * Reads the live LruCache (the canonical in-flight map);
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function inflight_snapshot(): array {
		$patron = $this->patron();
		if ( ! $patron instanceof Request_Builder_Node ) {
			return [];
		}
		$out = [];
		$now = ( Core::$now > 0.0 ? Core::$now : \microtime( true ) );
		foreach ( $patron->cache->iterate() as $rid => $request ) {
			// Rebuild string-keyed prop map so extract_* gets array<string,mixed>.
			if ( ! $request instanceof \stdClass ) {
				continue;
			}
			$vars = \get_object_vars( $request );
			$r    = \array_combine( \array_map( '\strval', \array_keys( $vars ) ), \array_values( $vars ) );
			// Process-start ts: mu-profiler wall-clock load ts (before plugins load).
			$ts_v          = $r['timestamp'] ?? 0;
			$start_time    = \is_scalar( $ts_v ) ? (float) $ts_v : 0.0;
			$last_log_v    = $r['last_log_ts'] ?? $start_time;
			$last_log_ts   = \is_scalar( $last_log_v ) ? (float) $last_log_v : $start_time;
			$tracker_v     = $r['tracker_ts'] ?? $now;
			$tracker_ts    = \is_scalar( $tracker_v ) ? (float) $tracker_v : $now;
			$time_ms       = ( $last_log_ts - $start_time ) * 1000;
			$age_ms        = ( $now - $tracker_ts ) * 1000;
			$method_v      = $r['request_method'] ?? 'GET';
			$url_v         = $r['url'] ?? '';
			$remote_addr_v = $r['remote_addr'] ?? '';
			$user_agent_v  = $r['user_agent'] ?? '';
			$out[]         = [
				'rid'         => \is_scalar( $rid ) ? (string) $rid : '',
				'method'      => \is_scalar( $method_v ) ? (string) $method_v : 'GET',
				'url'         => \is_scalar( $url_v ) ? (string) $url_v : '',
				'state'       => Request_Builder_Node::extract_state( $r ),
				'what'        => Request_Builder_Node::extract_what( $r ),
				'time_ms'     => \round( $time_ms, 1 ),
				'est_ms'      => \round( $time_ms + $age_ms, 1 ),
				'start_time'  => $start_time,
				'last_log_ts' => $last_log_ts,
				'lag_ms'      => \max( 0, \round( ( $tracker_ts - $last_log_ts ) * 1000, 1 ) ),
				'remote_addr' => \is_scalar( $remote_addr_v ) ? (string) $remote_addr_v : '',
				'user_agent'  => \is_scalar( $user_agent_v ) ? (string) $user_agent_v : '',
			];
		}
		return $out;
	}

	/**
	 * Setting a destination IS what enables snapshots: a non-empty target
	 * registers the Router-TIMER hitchhike (no-arg set_timer), clearing it
	 * stops snapshotting. The snapshot cadence is the Router's tick — there is
	 * no separate interval knob. The hitchhike preconditions (named sibling +
	 * live _router) are the worker's job, same as Request_Builder's arguments().
	 *
	 * @param array<int, string>|string|null $value
	 * @return array<int, string>|string
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
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category' => 'Hidden',
		] );
	}
}
