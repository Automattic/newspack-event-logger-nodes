<?php
/**
 * RequestFlight: hidden sibling Node attached to RequestBuilder. Mirrors
 * Perl Tachikoma's InstrumentalityFlight.pm. Periodically snapshots the
 * patron's in-progress request map and emits a compact-summary batch to
 * the configured target (gyroscope partition).
 *
 * Hidden from the topology editor by the substrate's patron filter in
 * dump_metadata; configuration surfaces on the patron's :config interpreter as
 * `set_inflight_target` / `set_inflight_interval`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer_Node;

\defined( 'ABSPATH' ) || exit;

class Request_Flight_Node extends Timer_Node {
	private const DEFAULT_INTERVAL_MS = 1000;

	private int $interval_ms = self::DEFAULT_INTERVAL_MS;

	/**
	 * Hidden from the palette: this is a patron-linked sibling Request_Builder
	 * mounts at runtime, not a node you'd drag onto a topology. The patron
	 * filter in dump_metadata hides it from the LIVE canvas; this hides it
	 * from the palette (whose source is the static class catalog, not the
	 * live registry).
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category' => 'Hidden',
		] );
	}

	public function set_interval( int $ms ): void {
		$this->interval_ms = $ms;
		$this->set_timer( $ms );
	}

	public function interval(): int {
		return $this->interval_ms;
	}

	public function fire_cb(): void {
		$batch = $this->inflight_snapshot();
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
			$r = (array) $request;
			// Process-start ts — the EARLIEST point PHP began handling this
			// request. LogManager stamps `process (start)` with the
			// mu-profiler's wall-clock load ts (captured before any plugins
			// load), so this is the real request-start time the operator
			// cares about.
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
				'lag_ms'      => \round( ( $tracker_ts - $last_log_ts ) * 1000, 1 ),
				'remote_addr' => \is_scalar( $remote_addr_v ) ? (string) $remote_addr_v : '',
				'user_agent'  => \is_scalar( $user_agent_v ) ? (string) $user_agent_v : '',
			];
		}
		return $out;
	}
}
