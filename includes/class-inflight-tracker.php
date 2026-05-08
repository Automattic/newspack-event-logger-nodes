<?php
/**
 * Inflight Tracker.
 *
 * Real-time request state tracking for the Gyroscope SSE stream. Walks raw
 * firehose entries, maintains a per-rid stack of `(label, what)` frames keyed
 * by `(start)` / `(complete)` keyword suffixes, and emits "active" + "completed"
 * snapshots on demand.
 *
 * Verbatim port of `Newspack_Performance_Gyroscope\InflightTracker`.
 *
 * Caps and timeouts (Pattern 40 — bounded memory under hostile input):
 *  - MAX_REQUESTS    10 000  active rids
 *  - MAX_COMPLETED    5 000  buffered completed entries (drained per get_completed())
 *  - MAX_STACK_DEPTH    100  frames per rid
 *  - STALE_TIMEOUT      300s wall-clock since last log line
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class InflightTracker {

	/** Pattern 40: bounded memory under hostile input. */
	private const MAX_REQUESTS    = 10000;
	private const MAX_COMPLETED   = 5000;
	private const MAX_STACK_DEPTH = 100;
	private const STALE_TIMEOUT   = 300;

	/**
	 * Pre-compiled regex (Efficiency Principle 7: pre-compute at setup).
	 * The bind below is via `self::REGEX_*` in `process()`; PHP caches the
	 * pattern on first preg_match call so subsequent calls are JIT-cached.
	 */
	private const REGEX_REQUEST     = '/^(GET|POST|PUT|DELETE|PATCH)\s+(.+)$/';
	private const REGEX_REMOTE_ADDR = '/^REMOTE_ADDR => "(.+)"$/';
	private const REGEX_USER_AGENT  = '/^HTTP_USER_AGENT => "(.+)"$/';
	private const REGEX_START       = '/^(.+?) \(start\)$/';
	private const REGEX_COMPLETE    = '/^(.+?) \(complete\)$/';

	/** @var array<string,array<string,mixed>> */
	private array $requests = [];

	/** @var array<int,array<string,mixed>> */
	private array $completed = [];

	/**
	 * URLs to silently skip when seen as request keywords. Self-skip filter for
	 * the Gyroscope endpoint itself — the SSE stream IS in-flight while serving
	 * the firehose, but reporting it would create a self-referential row.
	 *
	 * @var array<string>
	 */
	private array $skip_urls = [ '/firehose/gyroscope' ];

	/**
	 * Process a single decoded firehose entry.
	 *
	 * @param array<string,mixed> $entry Decoded JSON line.
	 */
	public function process( array $entry ): void {
		$rid = $entry['rid'] ?? null;
		if ( ! $rid ) {
			return;
		}

		$keyword = $entry['k'] ?? '';
		$message = $entry['m'] ?? '';
		$ts      = $entry['ts'] ?? 0;

		if ( 'request' === $keyword && $message && \preg_match( self::REGEX_REQUEST, $message, $m ) ) {
			$url = $m[2];
			foreach ( $this->skip_urls as $skip ) {
				if ( false !== \strpos( $url, $skip ) ) {
					return;
				}
			}
			if ( \count( $this->requests ) >= self::MAX_REQUESTS ) {
				\array_shift( $this->requests );
			}
			$this->requests[ $rid ] = [
				'rid'         => $rid,
				'method'      => $m[1],
				'url'         => $url,
				'start_time'  => $ts,
				'last_log_ts' => $ts,
				'tracker_ts'  => \microtime( true ),
				'state'       => 'process',
				'what'        => '',
				'stack'       => [ [ 'process', '' ] ],
				'remote_addr' => '',
				'user_agent'  => '',
			];
			return;
		}

		if ( ! isset( $this->requests[ $rid ] ) ) {
			return;
		}

		$request                = &$this->requests[ $rid ];
		$request['last_log_ts'] = $ts;
		$request['tracker_ts']  = \microtime( true );

		if ( 'environment_v2' === $keyword && $message ) {
			if ( \preg_match( self::REGEX_REMOTE_ADDR, $message, $m ) ) {
				$request['remote_addr'] = $m[1];
			} elseif ( \preg_match( self::REGEX_USER_AGENT, $message, $m ) ) {
				$request['user_agent'] = $m[1];
			}
			return;
		}

		if ( 'process (complete)' === $keyword ) {
			$request['state']       = 'complete';
			$request['duration_ms'] = $entry['duration_ms'] ?? 0;
			$request['status_code'] = $entry['status_code'] ?? 0;
			$request['end_time']    = $ts;
			if ( \count( $this->completed ) >= self::MAX_COMPLETED ) {
				\array_shift( $this->completed );
			}
			$this->completed[] = $request;
			unset( $this->requests[ $rid ] );
			return;
		}

		$label  = $keyword;
		$action = null;
		if ( \preg_match( self::REGEX_START, $keyword, $m ) ) {
			$label  = $m[1];
			$action = 'start';
		} elseif ( \preg_match( self::REGEX_COMPLETE, $keyword, $m ) ) {
			$label  = $m[1];
			$action = 'complete';
		}

		if ( 'start' === $action ) {
			if ( \count( $request['stack'] ) < self::MAX_STACK_DEPTH ) {
				$request['stack'][] = [ $label, $message ];
			}
			$request['state'] = $label;
			$request['what']  = $message;
		}

		if ( 'complete' === $action ) {
			for ( $i = \count( $request['stack'] ) - 1; $i >= 0; $i-- ) {
				if ( $request['stack'][ $i ][0] === $label ) {
					\array_splice( $request['stack'], $i );
					break;
				}
			}
			$top              = \end( $request['stack'] ) ?: [ 'process', '' ];
			$request['state'] = $top[0];
			$request['what']  = $top[1];
		}
	}

	/**
	 * Process a raw line directly (convenience for SSE controllers).
	 *
	 * @param string $line Raw JSON line.
	 */
	public function process_line( string $line ): void {
		$entry = \json_decode( $line, true, 64 );
		if ( \is_array( $entry ) ) {
			$this->process( $entry );
		}
	}

	/**
	 * Snapshot of currently-in-flight requests. Stale entries (no log line in
	 * STALE_TIMEOUT seconds) get reaped before snapshotting.
	 *
	 * @param float $since_update Only emit rids whose tracker_ts >= this microtime.
	 * @return array<int,array<string,mixed>> Sorted by est_ms descending.
	 */
	public function get_active( float $since_update = 0 ): array {
		$now     = \microtime( true );
		$timeout = self::STALE_TIMEOUT;
		$result  = [];

		foreach ( $this->requests as $rid => $req ) {
			if ( $now - $req['tracker_ts'] > $timeout ) {
				unset( $this->requests[ $rid ] );
				continue;
			}
			if ( $since_update > 0 && $req['tracker_ts'] < $since_update ) {
				continue;
			}

			$time_ms = ( $req['last_log_ts'] - $req['start_time'] ) * 1000;
			$age_ms  = ( $now - $req['tracker_ts'] ) * 1000;

			$result[] = [
				'rid'         => $req['rid'],
				'method'      => $req['method'],
				'url'         => $req['url'],
				'state'       => $req['state'],
				'what'        => $req['what'],
				'time_ms'     => \round( $time_ms, 1 ),
				'est_ms'      => \round( $time_ms + $age_ms, 1 ),
				'start_time'  => $req['start_time'],
				'last_log_ts' => $req['last_log_ts'],
				'lag_ms'      => \round( ( $req['tracker_ts'] - $req['last_log_ts'] ) * 1000, 1 ),
				'remote_addr' => $req['remote_addr'],
				'user_agent'  => $req['user_agent'],
			];
		}

		\usort( $result, static fn ( $a, $b ) => $b['est_ms'] <=> $a['est_ms'] );
		return $result;
	}

	/**
	 * Drain the completed-request buffer. Each call empties the buffer and
	 * returns the entries — callers get an "everything since last call" batch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_completed(): array {
		$result          = $this->completed;
		$this->completed = [];
		return $result;
	}

	/**
	 * Reap stale active rids without snapshotting. Gives callers an out-of-band
	 * way to clean memory between snapshots.
	 *
	 * @return int Number of rids reaped.
	 */
	public function reap_stale(): int {
		$now     = \microtime( true );
		$timeout = self::STALE_TIMEOUT;
		$reaped  = 0;
		foreach ( $this->requests as $rid => $req ) {
			if ( $now - $req['tracker_ts'] > $timeout ) {
				unset( $this->requests[ $rid ] );
				++$reaped;
			}
		}
		return $reaped;
	}

	/**
	 * Test helper / introspection: count of active requests being tracked.
	 */
	public function active_count(): int {
		return \count( $this->requests );
	}

	/**
	 * Test helper / introspection: count of buffered completed entries.
	 */
	public function completed_count(): int {
		return \count( $this->completed );
	}
}
