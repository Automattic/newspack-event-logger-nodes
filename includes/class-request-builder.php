<?php
/**
 * Request Builder
 *
 * Node that builds request profiles from firehose entries.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request builder node class.
 */
class RequestBuilder extends Node {

	/**
	 * Maximum stack depth before request is considered runaway and evicted.
	 * Matches json_decode depth limit (64) with headroom.
	 */
	private const MAX_STACK_DEPTH = 50;

	/**
	 * Maximum entries stored per request (for the detail view Log Entries table).
	 */
	private const MAX_ENTRIES_PER_REQUEST = 50000;

	/**
	 * Max stored message length per entry. Truncate long values (filter args,
	 * callback lists) to keep in-flight request memory bounded.
	 */
	private const MAX_ENTRY_MESSAGE_LENGTH = 1024;

	/**
	 * Max raw payload length for URL/process-start extraction.
	 */
	private const MAX_PAYLOAD_SCAN_LENGTH = 8192;

	/**
	 * Bucket rotation interval in seconds.
	 * 3 buckets x 200s = 600s (10 min) before oldest bucket is evicted.
	 */
	private const BUCKET_ROTATION_S = 200;

	/** Default LRU cache capacity. */
	private const DEFAULT_BUCKET_SIZE = 100;
	private const DEFAULT_NUM_BUCKETS = 3;

	/** @var LruCache In-flight requests, keyed by rid. */
	public $cache;

	/** @var RequestFlight|null Hidden sibling — periodic in-flight snapshots. */
	public ?RequestFlight $flight = null;

	/** @var array<string,callable> Keyword → mutator. Set in constructor. */
	private $state_callbacks;

	/** @var string Named target for error/warning lines (empty = disabled). */
	private $errors_target = '';

	/** @var string Named target for compact-summary completed lines (empty = disabled). */
	private string $completed_target = '';

	/** @var int Process line counter (for tests/debug). */
	private $line_counter = 0;

	public function __construct( int $bucket_size = self::DEFAULT_BUCKET_SIZE, int $num_buckets = self::DEFAULT_NUM_BUCKETS ) {
		$this->cache = ( new LruCache( $bucket_size, $num_buckets ) )
			->with_timed_rotation(
				self::BUCKET_ROTATION_S,
				function ( string $rid, $request ): void {
					$this->evict_request( $rid, $request );
				}
			);
		$this->state_callbacks = $this->build_state_callbacks();

		// Sibling CommandInterpreter for runtime config verbs. See
		// Partition's ctor for the contract; A1 declares one verb
		// (set_errors_target) on the patron's :config CI.
		$ci = new CommandInterpreter();
		$ci->patron( $this );
		$ci->commands( self::config_verbs() );
		$this->attach_interpreter( $ci );

		// Hidden Flight sibling — patron filter hides it from the canvas.
		// Naming happens in the overridden name() setter so the sibling
		// adopts `{patron}:flight` when the patron is named (mirroring the
		// CI sibling's `{patron}:config` propagation in Node::name).
		// Sink wiring also propagates from the overridden sink() setter.
		$this->flight = new RequestFlight();
		$this->flight->patron( $this );
	}

	public function flight(): RequestFlight {
		return $this->flight;
	}

	/**
	 * Override Node::name() so the Flight sibling tracks the patron name.
	 * The CI sibling is handled by the parent (it owns $this->interpreter);
	 * Flight is application-specific and lives outside that mechanism.
	 */
	public function name( ?string $name = null ): string {
		if ( null !== $name ) {
			$result = parent::name( $name );
			if ( null !== $this->flight ) {
				$this->flight->name( $name . ':flight' );
			}
			return $result;
		}
		return parent::name();
	}

	/**
	 * Override Node::sink() so the auto-sink wiring make_node performs on
	 * RequestBuilder also reaches the hidden Flight sibling. Without this,
	 * Flight's $this->sink stays null and its in-flight emits drop on the
	 * floor.
	 */
	public function sink( ?Node $node = null ): ?Node {
		if ( \func_num_args() > 0 ) {
			if ( null !== $this->flight ) {
				$this->flight->sink( $node );
			}
			return parent::sink( $node );
		}
		return parent::sink();
	}

	/**
	 * Set the named target for compact-summary completed-request lines.
	 */
	public function set_completed_target( string $target ): void {
		$this->completed_target = $target;
	}

	/**
	 * Set the named target for error/warning forwarding.
	 */
	public function set_errors_target( string $target ): void {
		$this->errors_target = $target;
	}

	/**
	 * Expose every named destination this node actually writes to so
	 * `ls -al`'s TARGET column reflects the full fan-out. Mirrors the
	 * Perl Tachikoma RegexTee::owner pattern: walk the primary target
	 * (which Node::target stores in $this->target) plus the
	 * conditional errors_target / completed_target the topology may have
	 * wired, plus the flight sibling's own target (the periodic in-flight
	 * snapshot stream, typically wired to `gyroscope:partition`).
	 *
	 * Without this override `errors:partition`, `completed:tee`, and the
	 * flight sibling's target would orphan on the topology console (nodes
	 * with `0` count, no inbound edges) even though RequestBuilder /
	 * RequestFlight writes to them.
	 */
	public function target( $value = null ) {
		if ( null !== $value ) {
			return parent::target( $value );
		}
		$primary = parent::target();
		$extras  = [];
		if ( '' !== $this->errors_target ) {
			$extras[] = $this->errors_target;
		}
		if ( '' !== $this->completed_target ) {
			$extras[] = $this->completed_target;
		}
		if ( null !== $this->flight ) {
			$flight_target = $this->flight->target();
			if ( \is_string( $flight_target ) && '' !== $flight_target ) {
				$extras[] = $flight_target;
			}
		}
		if ( ! $extras ) {
			return $primary;
		}
		$all = \is_array( $primary )
			? $primary
			: ( '' !== (string) $primary ? [ $primary ] : [] );
		foreach ( $extras as $e ) {
			if ( ! \in_array( $e, $all, true ) ) {
				$all[] = $e;
			}
		}
		return $all;
	}

	public function cache_size(): int {
		$count = 0;
		foreach ( $this->cache->iterate() as $_ ) {
			++$count;
		}
		return $count;
	}

	/**
	 * Periodic maintenance — drives rotate_if_due even with no inbound traffic.
	 */
	public function maintenance(): void {
		$this->cache->rotate_if_due();
	}

	/**
	 * Save state for persistence.
	 *
	 * Persists the full request cache (including entries and profiles)
	 * so in-flight requests retain trace data across worker restarts.
	 * Orphan eviction is handled by LRU bucket rotation.
	 *
	 * @return array State to persist.
	 */
	public function save_state(): array {
		// Convert objects to arrays for serialization.
		$state = $this->cache->get_state();
		if ( isset( $state['buckets'] ) ) {
			foreach ( $state['buckets'] as &$bucket ) {
				if ( \is_array( $bucket ) ) {
					foreach ( $bucket as $key => &$val ) {
						if ( $val instanceof \stdClass ) {
							$val = (array) $val;
						}
					}
					unset( $val );
				}
			}
			unset( $bucket );
		}
		return [ 'request_cache' => $state ];
	}

	/**
	 * Restore state from save_state(). Rehydrates arrays back into stdClass.
	 *
	 * @param array $saved Saved state from save_state().
	 */
	public function restore_state( array $saved ): void {
		if ( ! isset( $saved['request_cache'] ) ) {
			return;
		}
		$cache_state = $saved['request_cache'];
		if ( isset( $cache_state['buckets'] ) && \is_array( $cache_state['buckets'] ) ) {
			foreach ( $cache_state['buckets'] as &$bucket ) {
				if ( \is_array( $bucket ) ) {
					foreach ( $bucket as $key => &$val ) {
						if ( \is_array( $val ) ) {
							$val = (object) $val;
						}
					}
					unset( $val );
				}
			}
			unset( $bucket );
		}
		$this->cache->restore_state( $cache_state );
	}

	/**
	 * Node entry point: process a single line from firehose.log.
	 *
	 * @param array $message Reference; not mutated.
	 */
	public function fill( array &$message ): void {
		++$this->counter;
		$type = $message[ Message::TYPE ];
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
			return;
		}
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$entry = $message[ Message::VALUE ];
		$this->cache->rotate_if_due();
		if ( ! \is_array( $entry ) ) {
			return;
		}

		$rid = (string) ( $message[ Message::KEY ] ?? '' );
		if ( '' === $rid ) {
			return;
		}

		// Intern keyword strings — json_decode allocates a new string per entry,
		// but most entries share the same ~200 unique keywords. Interning makes
		// all identical strings share one zval, saving ~80 bytes per entry.
		static $intern = [];
		$keyword       = $entry['k'] ?? '';
		if ( ! \is_string( $keyword ) ) {
			return;
		}
		if ( \strlen( $keyword ) <= 256 && \count( $intern ) < 50000 ) {
			$keyword = $intern[ $keyword ] ??= $keyword;
		}
		$n = $entry['n'] ?? 0;
		++$this->line_counter;

		// get() returns the same object instance — mutations happen in place.
		$request = $this->cache->get( $rid );
		if ( null === $request ) {
			if ( 'process (start)' !== $keyword ) {
				return;
			}
			$request = new \stdClass();
			$request->rid = $rid;
			$this->cache->set( $rid, $request );
		}

		// Forward errors and warnings to errors.log. Pass the rid so the
		// emitted Message carries it in KEY — errors.log readers (and any
		// future StreamMerger forwarders) need it for the same reasons the
		// firehose does.
		if ( 'error' === $keyword || 'warning' === $keyword
			|| \str_ends_with( $keyword, '(error)' )
			|| \str_ends_with( $keyword, '(warning)' )
		) {
			$this->emit_error( $entry, $rid );
		}

		if ( isset( $this->state_callbacks[ $keyword ] ) ) {
			$this->state_callbacks[ $keyword ]( $request, $entry );
		} elseif ( \str_ends_with( $keyword, ' (start)' ) ) {
			$label = $entry['l'] ?? '';
			$this->push_stack( $request, \substr( $keyword, 0, -8 ), \is_string( $label ) ? $label : '' );
		} elseif ( \str_ends_with( $keyword, ' (complete)' ) ) {
			$this->pop_stack( $request, \substr( $keyword, 0, -11 ), $entry['duration_ms'] ?? 0, $entry['ts'] ?? 0 );
		}

		// Track per-line activity timestamps for the inflight snapshot's
		// time_ms / est_ms / lag_ms derivation (matches legacy
		// InflightTracker::process lines 88-90).
		$request->last_log_ts = (float) ( $entry['ts'] ?? 0 );
		$request->tracker_ts  = \microtime( true );

		// Runaway requests stay visible in the cache so inflight_snapshot
		// surfaces them — matches the Perl gyroscope, which displays
		// over-depth requests reliably. Memory is still bounded: push_stack
		// stops growing the stack at MAX_STACK_DEPTH (see the guard at
		// push_stack line 504-506), and the LRU bucket rotation will
		// eventually evict the runaway via evict_request (which stamps
		// error_status=T and emits to the completed pipeline).
		if ( $request->is_runaway ?? false ) {
			return;
		}

		if ( isset( $request->entries ) && \count( $request->entries ) < self::MAX_ENTRIES_PER_REQUEST ) {
			$stored = [
				'n'  => $n,
				'ts' => $entry['ts'] ?? 0,
				'k'  => $keyword,
			];

			// Truncate string 'm' to bound per-entry memory.
			// Array messages are already bounded by PIPE_BUF (4KB) at the firehose writer.
			$m = $entry['m'] ?? '';
			if ( \is_string( $m ) && \strlen( $m ) > self::MAX_ENTRY_MESSAGE_LENGTH ) {
				$m = \substr( $m, 0, self::MAX_ENTRY_MESSAGE_LENGTH );
			}
			$stored['m'] = $m;

			if ( isset( $entry['l'] ) ) {
				$stored['l'] = $entry['l'];
			}
			if ( isset( $entry['duration_ms'] ) ) {
				$stored['duration_ms'] = $entry['duration_ms'];
			}
			if ( isset( $entry['peak_mb'] ) ) {
				$stored['peak_mb'] = $entry['peak_mb'];
			}

			$request->entries[] = $stored;
		} elseif ( isset( $request->entries ) && empty( $request->truncated ) ) {
			$request->truncated = true;
		}

		if ( 'complete' === ( $request->state ?? '' ) ) {
			// Write immediately to get state out of RAM.
			if ( ! empty( $request->url ) ) {
				$this->emit_request( $request );
			}
			$this->cache->delete( $rid );
		}
	}

	/**
	 * Build the state-callback table.
	 *
	 * @return array<string,callable>
	 */
	private function build_state_callbacks(): array {
		$s = [];

		$s['process (start)'] = function ( \stdClass $request, array $entry ): void {
			$payload = $entry['m'] ?? '';
			if ( \is_array( $payload ) ) {
				$payload = $payload['m'] ?? '';
			}
			if ( \is_string( $payload ) && strlen( $payload ) < self::MAX_ENTRY_MESSAGE_LENGTH && \preg_match( '/^(\d+) on (\S+)/', $payload, $m ) ) {
				$request->process_id = $m[1];
				$request->host       = $m[2];
			}
			$request->timestamp   = $entry['ts'] ?? \microtime( true );
			$request->stack       = [ [ 'process', '' ] ];
			$request->profiles    = [];
			$request->entries     = [];
			$request->state       = 'process';
			$request->initialized = true;
		};

		$s['process (complete)'] = function ( \stdClass $request, array $entry ): void {
			$request->duration_ms = $entry['duration_ms'] ?? 0;
			$request->status_code = $entry['status_code'] ?? 0;
			$error_status         = $entry['error_status'] ?? '-';
			if ( ! \is_string( $error_status ) || 1 !== \strlen( $error_status ) || ! \in_array( $error_status, [ '-', 'F', 'T' ], true ) ) {
				$error_status = '-';
			}
			$request->error_status = $error_status;
			$request->state        = 'complete';
		};

		$s['request'] = function ( \stdClass $request, array $entry ): void {
			$message = $entry['m'] ?? '';
			if ( \strlen( $message ) < self::MAX_PAYLOAD_SCAN_LENGTH && \preg_match( '/^(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CLI)\s+(.+)$/', $message, $m ) ) {
				// Strip query string — URL hash already ignores it for merging,
				// and keeping it wastes memory and makes the URL table noisy.
				$request->url = \explode( '?', $m[1], 2 )[0];
			}
			$parts                   = \explode( ' ', $message, 2 );
			$request->request_method = $parts[0];
		};

		$s['environment_v2'] = function ( \stdClass $request, array $entry ): void {
			$message = $entry['m'] ?? '';
			if ( \strlen( $message ) > 8192 ) {
				return;
			}
			if ( \preg_match( '/^REMOTE_ADDR => "(.+)"$/', $message, $m ) ) {
				$ip = \trim( $m[1] );
				$request->remote_addr = \filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
			} elseif ( \preg_match( '/^HTTP_USER_AGENT => "(.+)"$/', $message, $m ) ) {
				$request->user_agent = $m[1];
			} elseif ( \preg_match( '/^HTTP_X_FORWARDED_FOR => "(.+)"$/', $message, $m ) ) {
				if ( empty( $request->remote_addr ) ) {
					$parts = \explode( ',', $m[1], 2 );
					$ip    = \trim( $parts[0] );
					if ( \filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						$request->remote_addr = $ip;
					}
				}
			} elseif ( \preg_match( '/^SERVER_NAME => "(.+)"$/', $message, $m ) ) {
				$request->server_name = $m[1];
			} elseif ( \preg_match( '/^GEOIP_COUNTRY_CODE => "(.+)"$/', $message, $m ) ) {
				$request->country_code = $m[1];
			} elseif ( \preg_match( '/^HTTP_FROM => "(.+)"$/', $message, $m ) ) {
				$request->http_from = $m[1];
			} elseif ( \preg_match( '/^HTTP_X_JA4_HASH => "(.+)"$/', $message, $m ) ) {
				$request->ja4_hash = $m[1];
			} elseif ( \preg_match( '/^NEWSPACK_NODES_WORKER_TYPE => ".+"$/', $message ) ) {
				$request->is_worker = true;
			}
		};

		$s['worker_type'] = function ( \stdClass $request, array $entry ): void {
			$request->is_worker = true;
		};

		$s['memory'] = function ( \stdClass $request, array $entry ): void {
			$m = $entry['m'] ?? [];
			if ( \is_array( $m ) && isset( $m['peak'] ) ) {
				$request->peak_mb = (float) $m['peak'];
			}
		};

		return $s;
	}

	/**
	 * Push state onto request stack.
	 *
	 * Stack frames are [ state, label ] pairs.
	 *
	 * @param \stdClass $request Request object.
	 * @param string    $state   State name (e.g. "wp_head hook").
	 * @param string    $label   Stable label for aggregation (the 'l' field).
	 */
	private function push_stack( \stdClass $request, string $state, string $label ): void {
		if ( ! isset( $request->stack ) ) {
			$request->stack    = [ [ 'process', '' ] ];
			$request->profiles = [];
		}

		// Stop appending once we've hit the stack-depth cap — keeps memory
		// bounded for runaway requests we deliberately keep visible in the
		// inflight snapshot.
		if ( \count( $request->stack ) >= self::MAX_STACK_DEPTH ) {
			$request->is_runaway = true;
			return;
		}

		if ( ! isset( $request->profiles[ $state ] ) ) {
			$request->profiles[ $state ] = [
				'entries' => [],
				'count'   => 0,
				'time'    => 0,
				'ts'      => 0,
			];
		}

		$request->stack[] = [ $state, $label ];

		$profile = &$request->profiles[ $state ];
		if ( $label && \count( $profile['entries'] ) < 1000 && ! isset( $profile['entries'][ $label ] ) ) {
			$profile['entries'][ $label ] = [ 0, 0 ];
		}

		if ( \count( $request->stack ) >= self::MAX_STACK_DEPTH ) {
			$request->is_runaway = true;
		}
	}

	/**
	 * Pop state from request stack.
	 *
	 * @param \stdClass $request Request object.
	 * @param string    $state   State name to match.
	 * @param float     $time    Duration in ms.
	 * @param float     $ts      Timestamp.
	 */
	private function pop_stack( \stdClass $request, string $state, float $time, float $ts = 0 ): void {
		if ( $request->is_runaway ?? false ) {
			return;
		}

		if ( empty( $request->stack ) ) {
			return;
		}

		$last_idx = \count( $request->stack ) - 1;
		$frame    = $request->stack[ $last_idx ];

		if ( $frame[0] === $state ) {
			// Fast path: matched top of stack (the common case).
			$label = $frame[1];
			\array_pop( $request->stack );
		} else {
			// Slow path: mismatched close — search backward and unwind.
			$found_idx = false;
			for ( $i = $last_idx - 1; $i >= 0; $i-- ) {
				if ( $request->stack[ $i ][0] === $state ) {
					$found_idx = $i;
					break;
				}
			}
			if ( false === $found_idx ) {
				return;
			}

			$label = $request->stack[ $found_idx ][1];
			\array_splice( $request->stack, $found_idx );
		}

		if ( isset( $request->profiles[ $state ] ) ) {
			$profile          = &$request->profiles[ $state ];
			$profile['time'] += $time;
			++$profile['count'];
			$profile['ts'] = \max( $profile['ts'], $ts );

			if ( $label && isset( $profile['entries'][ $label ] ) ) {
				$profile['entries'][ $label ][0] += $time;
				++$profile['entries'][ $label ][1];
			}
		}

		// Subtract child time from ancestors to avoid double-counting.
		// Callbacks (contain " @N") are breakdowns of their parent hook's time,
		// so callback completion does NOT subtract from the hook.
		// Non-callback children subtract from BOTH the callback (if inside one)
		// AND the callback's parent hook.
		if ( ! empty( $request->stack ) && ! self::is_callback_state( $state ) ) {
			for ( $j = \count( $request->stack ) - 1; $j >= 0; $j-- ) {
				$ancestor_frame = $request->stack[ $j ];
				$ancestor       = $ancestor_frame[0];
				if ( 'process' === $ancestor ) {
					break;
				}
				if ( isset( $request->profiles[ $ancestor ] ) ) {
					$request->profiles[ $ancestor ]['time'] -= $time;

					$ancestor_label = $ancestor_frame[1];
					if ( $ancestor_label && isset( $request->profiles[ $ancestor ]['entries'][ $ancestor_label ] ) ) {
						$request->profiles[ $ancestor ]['entries'][ $ancestor_label ][0] -= $time;
					}
					// If we just subtracted from a callback, continue to also
					// subtract from its parent hook. Stop after the first
					// non-callback ancestor.
					if ( ! self::is_callback_state( $ancestor ) ) {
						break;
					}
				}
			}
		}
	}

	/**
	 * Check if a state label is a callback (ends with " @N").
	 *
	 * @param string $state State label.
	 * @return bool True if callback state.
	 */
	private static function is_callback_state( string $state ): bool {
		$at_pos = \strrpos( $state, ' @' );
		return false !== $at_pos && \ctype_digit( \substr( $state, $at_pos + 2 ) );
	}

	/**
	 * Handle a single evicted request from LRU bucket rotation.
	 *
	 * Incomplete requests get written with error_status=T.
	 * Called by the LruCache eviction callback — LruCache stores mixed
	 * values, so the runtime type isn't guaranteed by the signature; the
	 * instanceof gate is the real validation.
	 *
	 * @param string $rid     Request ID.
	 * @param mixed  $request Request object (expected \stdClass).
	 */
	private function evict_request( string $rid, $request ): void {
		if ( ! ( $request instanceof \stdClass ) || empty( $request->url ) ) {
			return;
		}
		if ( 'complete' === ( $request->state ?? '' ) ) {
			return;
		}
		$now                    = \time();
		$start_ts               = (int) ( $request->timestamp ?? $now );
		$request->error_status  = 'T';
		$request->duration_ms   = ( $now - $start_ts ) * 1000;
		$request->status_code   = $request->status_code ?? 0;
		$request->state         = 'complete';
		$this->emit_request( $request );
	}

	/**
	 * Emit a completed request as a TM_STRUCT message to the main sink.
	 *
	 * KEY = rid so downstream readers / aggregator forwarders can identify
	 * the request without decoding VALUE. RequestBuilder still stamps
	 * `rid` into the request struct itself; KEY is the wire-level breadcrumb.
	 *
	 * Also fires the secondary compact-summary emit (no-op when
	 * completed_target is unset) so a topology that wires both the full
	 * doc and the one-line summary gets both with one source call.
	 */
	public function emit_request( \stdClass $request ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::KEY ]       = (string) ( $request->rid ?? '' );
		$msg[ Message::VALUE ]     = (array) $request;
		parent::fill( $msg );
		$this->emit_compact_summary( $request );
	}

	/**
	 * Build an HTTP-access-log-style compact summary from a completed
	 * request envelope. Schema mirrors legacy
	 * requests-stream-controller::transform_line so the schema-parity
	 * audit passes. URL clipped to 2000 chars + "..." suffix; UA to 500.
	 *
	 * @param \stdClass|array $request Completed request envelope.
	 * @return array<string,mixed>
	 */
	public function build_compact_summary( $request ): array {
		$r   = (array) $request;
		$url = (string) ( $r['url'] ?? '' );
		$ua  = (string) ( $r['user_agent'] ?? '' );
		// Preserve native numeric type for ts and dur so the wire format is
		// byte-for-byte equivalent to legacy transform_line (which never
		// cast). json_encode strips trailing `.0`, so an int-valued float
		// round-trips as int through the wire — the SchemaParityAudit asserts
		// that on the unpacked side.
		$ts  = $r['timestamp'] ?? 0;
		$dur = $r['duration_ms'] ?? 0;
		return [
			'rid'          => (string) ( $r['rid'] ?? '' ),
			'method'       => (string) ( $r['request_method'] ?? 'GET' ),
			'url'          => \strlen( $url ) > 2000 ? \substr( $url, 0, 2000 ) . '...' : $url,
			'start_time'   => $ts,
			'end_time'     => $ts + ( $dur / 1000 ),
			'duration_ms'  => $dur,
			'status_code'  => $r['status_code'] ?? 0,
			'state'        => 'complete',
			'error_status' => $r['error_status'] ?? '-',
			'remote_addr'  => (string) ( $r['remote_addr'] ?? '' ),
			'user_agent'   => \strlen( $ua ) > 500 ? \substr( $ua, 0, 500 ) . '...' : $ua,
		];
	}

	/**
	 * Fire the secondary compact-summary emit. Silent no-op when the
	 * topology hasn't wired completed_target or a sink isn't attached.
	 *
	 * @param \stdClass|array $request Completed request envelope.
	 */
	private function emit_compact_summary( $request ): void {
		if ( '' === $this->completed_target || null === $this->sink ) {
			return;
		}
		$summary                   = $this->build_compact_summary( $request );
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = $this->completed_target;
		$msg[ Message::KEY ]       = $summary['rid'];
		$msg[ Message::VALUE ]     = $summary;
		$this->sink->fill( $msg );
	}

	public static function extract_what( array $request ): string {
		return self::extract_stack_top_slot( $request, 1, 'what', '' );
	}

	public static function extract_state( array $request ): string {
		return self::extract_stack_top_slot( $request, 0, 'state', 'process' );
	}

	/**
	 * Read the stack-top frame slot for an in-flight request — derive both
	 * `state` (slot 0) and `what` (slot 1) from the top of the request's
	 * hook stack, defaulting to `[ 'process', '' ]` when the stack is empty.
	 *
	 * The fallback chain: stack-top slot → explicit named field (for test
	 * seams that prime fields without driving the stack) → static default.
	 *
	 * @param array<string,mixed> $request   Request envelope as an array.
	 * @param int                 $slot      Frame slot (0 = state, 1 = what).
	 * @param string              $fallback_field Explicit field name to fall back on.
	 * @param string              $default   Static default if neither source has a value.
	 */
	private static function extract_stack_top_slot( array $request, int $slot, string $fallback_field, string $default ): string {
		$stack = $request['stack'] ?? null;
		if ( \is_array( $stack ) && [] !== $stack ) {
			$top = $stack[ \array_key_last( $stack ) ];
			if ( \is_array( $top ) && isset( $top[ $slot ] ) && \is_string( $top[ $slot ] ) ) {
				return $top[ $slot ];
			}
		}
		if ( isset( $request[ $fallback_field ] ) && \is_string( $request[ $fallback_field ] ) ) {
			return $request[ $fallback_field ];
		}
		return $default;
	}

	/**
	 * Emit an error/warning entry via the named errors_target.
	 *
	 * @param array  $entry Decoded entry.
	 * @param string $rid   Request id — propagated to Message::KEY so
	 *                      downstream readers can identify the request
	 *                      without re-parsing the entry payload.
	 */
	private function emit_error( array $entry, string $rid ): void {
		if ( '' === $this->errors_target || null === $this->sink ) {
			return;
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = $this->errors_target;
		$msg[ Message::KEY ]       = $rid;
		$msg[ Message::VALUE ]     = $entry;
		$this->sink->fill( $msg );
	}

	/**
	 * Format index entry callback for Partition::with_index().
	 *
	 * @param string            $line     The JSON line written.
	 * @param array             $position Position array.
	 * @param \stdClass|array|null $data  Pre-decoded data (avoids re-parsing $line).
	 * @return string|null Index entry or null.
	 */
	public static function format_index_entry( string $line, array $position, &$data = null ): ?string {
		// $line is the packed Message (positional JSON); VALUE is index 6.
		$decoded = \json_decode( $line, true, 64 );
		$value   = $decoded[ Message::VALUE ] ?? null;
		if ( ! \is_array( $value ) || empty( $value['url'] ) ) {
			return null;
		}
		$request = (object) $value;

		$rid          = $request->rid ?? '';
		$url_hash     = self::url_hash( $request->url );
		$timestamp    = (int) ( $request->timestamp ?? \time() );
		$duration_ms  = (int) ( $request->duration_ms ?? 0 );
		$status_code  = (int) ( $request->status_code ?? 0 );
		$peak_mb      = (float) ( $request->peak_mb ?? 0 );
		$segment_id   = $position['segment_id'];
		$offset       = $position['offset'];
		$length       = $position['length'];
		$error_status = $request->error_status ?? '-';

		if ( $offset > 9999999999 || $length > 99999999 || $segment_id > 999999 ) {
			return '';
		}

		// peak_mb: 6 chars, integer MB zero-padded (max 999999 MB).
		$peak_mb_int = \min( (int) \round( $peak_mb ), 999999 );

		// method: 1 char code for HTTP method.
		static $method_codes = [
			'GET'     => 'G',
			'POST'    => 'P',
			'HEAD'    => 'H',
			'DELETE'  => 'D',
			'PUT'     => 'U',
			'PATCH'   => 'A',
			'OPTIONS' => 'O',
			'CLI'     => 'C',
		];
		$method = $method_codes[ $request->request_method ?? 'GET' ] ?? 'G';

		return \str_pad( \substr( $rid, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash, 0, 12 ), 12 )
			. \str_pad( (string) $timestamp, 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) \min( $duration_ms, 99999999 ), 8, '0', STR_PAD_LEFT )
			. \str_pad( (string) \min( $status_code, 999 ), 3, '0', STR_PAD_LEFT )
			. \str_pad( (string) $segment_id, 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $offset, 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $length, 8, '0', STR_PAD_LEFT )
			. \str_pad( (string) $peak_mb_int, 6, '0', STR_PAD_LEFT )
			. $method
			. $error_status;
	}

	/**
	 * FNV-1a 32-bit hash.
	 *
	 * @param string $str  Input string.
	 * @param int    $seed Offset basis.
	 * @return int 32-bit hash.
	 */
	private static function fnv1a32( string $str, int $seed = 2166136261 ): int {
		$hash = $seed;
		$len  = \strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash ^= \ord( $str[ $i ] );
			$hash  = ( $hash * 16777619 ) & 0xFFFFFFFF;
		}
		return $hash;
	}

	/**
	 * URL hash - 12-char FNV-1a hash.
	 *
	 * @param string $url URL to hash.
	 * @return string 12-character hex hash.
	 */
	public static function url_hash( string $url ): string {
		$str   = \explode( '?', $url, 2 )[0] ?: $url;
		$hash1 = self::fnv1a32( $str );
		$hash2 = self::fnv1a32( $str, $hash1 ^ 0x811c9dc5 );
		return \sprintf( '%08x%04x', $hash1, $hash2 & 0xFFFF );
	}

	/**
	 * Parse request index entry.
	 *
	 * @param string $line Index line.
	 * @return array|null Parsed entry or null.
	 */
	public static function parse_request_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		$len  = \strlen( $line );

		if ( $len >= 89 ) {
			$entry = [
				'rid'         => \trim( \substr( $line, 0, 32 ) ),
				'url_hash'    => \trim( \substr( $line, 32, 12 ) ),
				'timestamp'   => (int) \substr( $line, 44, 10 ),
				'duration_ms' => (int) \substr( $line, 54, 8 ),
				'status_code' => (int) \substr( $line, 62, 3 ),
				'segment_id'  => (int) \substr( $line, 65, 6 ),
				'offset'      => (int) \substr( $line, 71, 10 ),
				'length'      => (int) \substr( $line, 81, 8 ),
			];

			// peak_mb field appended in v2 format (position 89, 6 chars).
			if ( $len >= 95 ) {
				$entry['peak_mb'] = (int) \substr( $line, 89, 6 );
			}

			// method field appended in v3 format (position 95, 1 char).
			if ( $len >= 96 ) {
				static $methods = [
					'G' => 'GET',
					'P' => 'POST',
					'H' => 'HEAD',
					'D' => 'DELETE',
					'U' => 'PUT',
					'A' => 'PATCH',
					'O' => 'OPTIONS',
					'C' => 'CLI',
				];
				$entry['method'] = $methods[ \substr( $line, 95, 1 ) ] ?? \substr( $line, 95, 1 );
			}

			// error_status field appended in v4 format (position 96, 1 char).
			if ( $len >= 97 ) {
				$c = \substr( $line, 96, 1 );
				if ( 'F' === $c || 'T' === $c ) {
					$entry['error_status'] = $c;
				}
			}

			return $entry;
		}

		return null;
	}

	/**
	 * Per-class verb table for the sibling `:config` CI. Resolved
	 * per-instance via `$ci->patron()` at dispatch time.
	 *
	 * @return array<string,callable>
	 */
	private static function config_verbs(): array {
		static $verbs = null;
		if ( null === $verbs ) {
			$verbs = [
				'set_errors_target' => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \trim( $args );
					// Empty arg clears the target (disables the secondary emit).
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->set_errors_target( $args );
					$patron->mark_verb_invoked( 'set_errors_target', $args );
					return 'ok';
				},
				'set_completed_target' => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \trim( $args );
					// Empty arg clears the target (disables the secondary emit).
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->set_completed_target( $args );
					$patron->mark_verb_invoked( 'set_completed_target', $args );
					return 'ok';
				},
				'set_inflight_target' => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \trim( $args );
					// Empty arg clears Flight's target — its fire_cb early-returns
					// on the target check, disabling the periodic snapshot emit.
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->flight()->target( $args );
					$patron->mark_verb_invoked( 'set_inflight_target', $args );
					return 'ok';
				},
				'set_inflight_interval' => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \trim( $args );
					if ( ! \ctype_digit( $args ) ) {
						return 'usage: set_inflight_interval <ms>';
					}
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->flight()->set_interval( (int) $args );
					$patron->mark_verb_invoked( 'set_inflight_interval', $args );
					return 'ok';
				},
			];
		}
		return $verbs;
	}

	/**
	 * Manifest for the topology console's palette + form rendering.
	 * See Node::node_schema() for the shape contract.
	 */
	private function handle_request( array $message ): void {
		$value = (string) $message[ Message::VALUE ];
		$verb  = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_CACHE' === $verb ) {
			$now     = (int) Core::$now;
			$samples = [];
			$oldest_rid = null;
			$oldest_ts  = $now;
			$count      = 0;
			foreach ( $this->cache->iterate() as $rid => $request ) {
				++$count;
				$created = is_array( $request ) ? (int) ( $request['process']['ts_start'] ?? $request['ts'] ?? 0 ) : 0;
				if ( $created > 0 && $created < $oldest_ts ) {
					$oldest_ts  = $created;
					$oldest_rid = $rid;
				}
				if ( count( $samples ) < 5 ) {
					$samples[] = (string) $rid;
				}
			}
			$payload = [
				'pending_count' => $count,
				'oldest_rid'    => $oldest_rid,
				'oldest_age_s'  => null !== $oldest_rid ? $now - $oldest_ts : 0,
				'sample'        => $samples,
				'line_counter'  => $this->line_counter,
			];
		} else {
			$payload = [ 'error' => "unknown request verb: {$verb}" ];
		}

		$reply                   = Message::new_message();
		$reply[ Message::TYPE ]  = Message::TM_STRUCT | Message::TM_RESPONSE;
		$reply[ Message::FROM ]  = $this->name;
		$reply[ Message::TO ]    = $message[ Message::FROM ];
		$reply[ Message::ID ]    = $message[ Message::ID ];
		$reply[ Message::KEY ]   = $message[ Message::KEY ];
		$reply[ Message::VALUE ] = [ 'verb' => $verb, 'data' => $payload ];
		$this->sink?->fill( $reply );
	}

	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Assembles per-request firehose lines into completed-request docs; emits errors to a named partition.',
			'ctor'        => [
				[ 'name' => 'bucket_size', 'type' => 'int', 'default' => self::DEFAULT_BUCKET_SIZE ],
				[ 'name' => 'num_buckets', 'type' => 'int', 'default' => self::DEFAULT_NUM_BUCKETS ],
			],
			'verbs'       => [
				[
					'name'        => 'set_errors_target',
					'description' => 'Forward error/warning keywords to a named partition.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
				],
				[
					'name'        => 'set_completed_target',
					'description' => 'Emit a compact one-line summary of each completed request to a named partition (in addition to the primary full-doc emit).',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
				],
				[
					'name'        => 'set_inflight_target',
					'description' => 'Periodically emit an in-flight request snapshot to a named partition (typically the gyroscope) via the hidden Flight sibling.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
				],
				[
					'name'        => 'set_inflight_interval',
					'description' => 'Set the Flight sibling timer interval (milliseconds) between in-flight snapshot emissions.',
					'args'        => [
						[ 'name' => 'ms', 'type' => 'int', 'required' => true ],
					],
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_CACHE',
					'description' => 'In-flight request count + oldest pending rid + sample.',
					'reply_shape' => '{ pending_count, oldest_rid, oldest_age_s, sample, line_counter }',
				],
			],
		];
	}
}
