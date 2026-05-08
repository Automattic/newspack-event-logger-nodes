<?php
/**
 * RequestBuilder: assembles request lifecycles from JSONL firehose entries.
 *
 * Faithful port of `Newspack_Performance_Workers\Cron\RequestBuilder` adapted
 * for the Node-with-fill substrate. The legacy class was a static handler
 * driven by LogReader; here it's a Node subclass with `fill( array &$message )`
 * as the entry point, and the legacy `$context` becomes private instance fields.
 *
 * Input shape: TM_BYTESTREAM messages whose VALUE is a JSONL line from
 * firehose.log:
 *   { "n": int, "rid": str, "k": str, "m": mixed, "l": str, "duration_ms": float, "ts": float }
 *
 * Match by keyword `k`:
 *  - State callbacks ('process (start)' / 'process (complete)' / 'request' /
 *    'environment_v2' / 'worker_type' / 'memory') populate top-level fields.
 *  - '* (start)' pushes onto a LIFO stack (state, label).
 *  - '* (complete)' pops with mismatch unwind, subtracts child time from
 *    ancestors (with the callback exception), and updates `profiles[$state]`.
 *  - 'error' / 'warning' / '* (error)' / '* (warning)' get forwarded to the
 *    errors-log sink.
 *
 * On `state === 'complete'` and non-empty `url`, emit the assembled request
 * (JSON-encoded) downstream via TM_BYTESTREAM. LRU eviction emits orphans as
 * `error_status='T'` (timed out).
 *
 * Constants match upstream exactly: 100×3 LRU, 200s rotation, 50 max stack
 * depth, 50000 max entries per request, 1024 max entry message length, etc.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class RequestBuilder extends Node {

	/**
	 * Maximum stack depth before request is considered runaway and evicted.
	 * Matches json_decode depth limit (64) with headroom.
	 */
	public const MAX_STACK_DEPTH = 50;

	/**
	 * Maximum entries stored per request (for the detail view Log Entries table).
	 */
	public const MAX_ENTRIES_PER_REQUEST = 50000;

	/**
	 * Max stored message length per entry. Truncate long values (filter args,
	 * callback lists) to keep in-flight request memory bounded.
	 */
	public const MAX_ENTRY_MESSAGE_LENGTH = 1024;

	/**
	 * Max raw payload length for URL/process-start extraction.
	 */
	public const MAX_PAYLOAD_SCAN_LENGTH = 8192;

	/**
	 * Bucket rotation interval in seconds.
	 * 3 buckets x 200s = 600s (10 min) before oldest bucket is evicted.
	 */
	public const BUCKET_ROTATION_S = 200;

	/** Default LRU cache capacity (per upstream). */
	public const DEFAULT_BUCKET_SIZE = 100;
	public const DEFAULT_NUM_BUCKETS = 3;

	/** @var LruCache In-flight requests, keyed by rid. */
	private LruCache $cache;

	/** @var array<string,callable> Keyword → mutator. Set in constructor. */
	private array $state_callbacks;

	/** Sink for error/warning lines (separate from main sink). */
	private ?Node $errors_sink = null;

	/** Process line counter (mirrors LogManager's `n`); kept here for tests/debug. */
	private int $line_counter = 0;

	public function __construct( int $bucket_size = self::DEFAULT_BUCKET_SIZE, int $num_buckets = self::DEFAULT_NUM_BUCKETS ) {
		$this->cache = ( new LruCache( $bucket_size, $num_buckets ) )
			->with_timed_rotation(
				self::BUCKET_ROTATION_S,
				function ( string $rid, $request ): void {
					$this->evict_request( $rid, $request );
				}
			);
		$this->state_callbacks = $this->build_state_callbacks();
	}

	/**
	 * Inject a separate sink for error/warning forwarding (the errors.log
	 * Partition or Topic). When unset, error/warning lines are still processed
	 * by state callbacks (same as a normal entry), just never duplicated.
	 */
	public function set_errors_sink( ?Node $sink ): void {
		$this->errors_sink = $sink;
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
	 * Topology runners can call this on a Timer tick or directly per drain.
	 */
	public function maintenance(): void {
		$this->cache->rotate_if_due();
	}

	/**
	 * Save state for persistence. Persists the in-flight request cache so
	 * orphan eviction is handled by LRU bucket rotation across worker restarts.
	 *
	 * @return array<string,mixed>
	 */
	public function save_state(): array {
		// Convert objects to arrays for serialization. The LRU stores stdClass
		// instances (fast in-place mutation); we serialize them as arrays so a
		// later restore_state can rehydrate via (object) cast.
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
	 * Restore state from `save_state`. Rehydrates arrays back into stdClass.
	 *
	 * @param array<string,mixed> $saved Saved state from save_state().
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
	 * Node entry point: parse JSONL, dispatch to state callback or stack op,
	 * accumulate into the in-flight request, emit on complete.
	 *
	 * @param array $message Reference; not mutated.
	 */
	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$line = (string) $message[ Message::VALUE ];
		$this->cache->rotate_if_due();

		$entry = \json_decode( $line, true, 64 );
		if ( ! \is_array( $entry ) || \json_last_error() !== JSON_ERROR_NONE ) {
			return;
		}

		$rid = $entry['rid'] ?? null;
		if ( ! \is_string( $rid ) || '' === $rid ) {
			return;
		}

		// Intern keyword strings — most entries share the same ~200 unique
		// keywords. Interning makes identical strings share one zval.
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
			$request      = new \stdClass();
			$request->rid = $rid;
			$this->cache->set( $rid, $request );
		}

		// Forward errors and warnings to errors.log sink.
		if (
			'error' === $keyword
			|| 'warning' === $keyword
			|| ( \is_string( $keyword ) && (
				$this->ends_with( $keyword, '(error)' )
				|| $this->ends_with( $keyword, '(warning)' )
			) )
		) {
			$this->emit_error( $line );
		}

		if ( isset( $this->state_callbacks[ $keyword ] ) ) {
			$this->state_callbacks[ $keyword ]( $request, $entry );
		} elseif ( $this->ends_with( $keyword, ' (start)' ) ) {
			$label = $entry['l'] ?? '';
			$this->push_stack( $request, \substr( $keyword, 0, -8 ), \is_string( $label ) ? $label : '' );
		} elseif ( $this->ends_with( $keyword, ' (complete)' ) ) {
			$this->pop_stack(
				$request,
				\substr( $keyword, 0, -11 ),
				(float) ( $entry['duration_ms'] ?? 0 ),
				(float) ( $entry['ts'] ?? 0 )
			);
		}

		// Evict runaway requests immediately (set by push_stack on overflow).
		if ( $request->is_runaway ?? false ) {
			$this->cache->delete( $rid );
			return;
		}

		// Append to entries[] up to the cap, then mark truncated.
		if ( isset( $request->entries ) && \count( $request->entries ) < self::MAX_ENTRIES_PER_REQUEST ) {
			$stored = [
				'n'  => $n,
				'ts' => $entry['ts'] ?? 0,
				'k'  => $keyword,
			];

			// Truncate string 'm' to bound per-entry memory. Array messages
			// are already bounded by PIPE_BUF (4KB) at the firehose writer.
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
			// Write immediately to get state out of RAM. Skip URL-less requests
			// (e.g. CLI bootstrap with no REQUEST_URI) — they're not addressable.
			if ( ! empty( $request->url ) ) {
				$this->emit_request( $request );
			}
			$this->cache->delete( $rid );
		}
	}

	/**
	 * Build the state-callback table. Mirrors upstream RequestBuilder lines 117-202.
	 *
	 * Each callback receives (\stdClass $request, array $entry) and mutates
	 * top-level fields on $request. The callback must NOT touch the stack —
	 * stack manipulation happens in the dispatcher's `* (start)` / `* (complete)`
	 * fallback paths.
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
			if ( \is_string( $payload ) && \strlen( $payload ) < self::MAX_ENTRY_MESSAGE_LENGTH && \preg_match( '/^(\d+) on (\S+)/', $payload, $m ) ) {
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
			if ( \is_string( $message ) && \strlen( $message ) < self::MAX_PAYLOAD_SCAN_LENGTH && \preg_match( '/^(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CLI)\s+(.+)$/', $message, $m ) ) {
				// Strip query string — URL hash already ignores it for merging,
				// and keeping it wastes memory and makes the URL table noisy.
				$request->url = \explode( '?', $m[1], 2 )[0];
			}
			$parts                   = \explode( ' ', \is_string( $message ) ? $message : '', 2 );
			$request->request_method = $parts[0] ?? '';
		};

		$s['environment_v2'] = function ( \stdClass $request, array $entry ): void {
			$message = $entry['m'] ?? '';
			if ( ! \is_string( $message ) || \strlen( $message ) > 8192 ) {
				return;
			}
			if ( \preg_match( '/^REMOTE_ADDR => "(.+)"$/', $message, $m ) ) {
				$ip                   = \trim( $m[1] );
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
			} elseif ( \preg_match( '/^EVENT_LOGGER_WORKER_TYPE => ".+"$/', $message ) ) {
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
	 * Stack frames are [state, label] pairs. Mirrors upstream push_stack.
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
		unset( $profile );

		if ( \count( $request->stack ) > self::MAX_STACK_DEPTH ) {
			$request->is_runaway = true;
		}
	}

	/**
	 * Pop state from request stack.
	 *
	 * Fast path: top of stack matches → pop. Slow path: search backward, splice.
	 * Subtracts child time from ancestors with the callback exception
	 * (callbacks are breakdowns of their parent hook's time, so callback
	 * completion does NOT subtract from the hook).
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
			unset( $profile );
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
	 */
	private static function is_callback_state( string $state ): bool {
		$at_pos = \strrpos( $state, ' @' );
		return false !== $at_pos && \ctype_digit( \substr( $state, $at_pos + 2 ) );
	}

	/**
	 * Handle a single evicted request from LRU bucket rotation.
	 *
	 * Incomplete requests get written with error_status=T and a synthetic
	 * duration computed from their start timestamp.
	 *
	 * @param string    $rid     Request ID.
	 * @param mixed     $request Request object (LRU stores stdClass).
	 */
	private function evict_request( string $rid, $request ): void {
		if ( ! ( $request instanceof \stdClass ) || empty( $request->url ) ) {
			return;
		}
		if ( 'complete' === ( $request->state ?? '' ) ) {
			return;
		}
		$now                   = \time();
		$start_ts              = (int) ( $request->timestamp ?? $now );
		$request->error_status = 'T';
		$request->duration_ms  = ( $now - $start_ts ) * 1000;
		$request->status_code  = $request->status_code ?? 0;
		$request->state        = 'complete';
		$this->emit_request( $request );
	}

	/**
	 * Emit a completed request as a TM_BYTESTREAM message to the main sink.
	 */
	private function emit_request( \stdClass $request ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg[ Message::TIMESTAMP ] = Core::$right_now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::VALUE ]     = (string) \json_encode( $request );
		$this->sink?->fill( $msg );
	}

	/**
	 * Emit an error/warning JSONL line to the errors-log sink (if configured).
	 *
	 * @param string $line Original JSONL line.
	 */
	private function emit_error( string $line ): void {
		if ( null === $this->errors_sink ) {
			return;
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg[ Message::TIMESTAMP ] = Core::$right_now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::VALUE ]     = $line;
		$this->errors_sink->fill( $msg );
	}

	/**
	 * Polyfill for str_ends_with — keep PHP 7.4 compat in case anyone tries.
	 */
	private function ends_with( string $haystack, string $needle ): bool {
		$nlen = \strlen( $needle );
		if ( 0 === $nlen ) {
			return true;
		}
		return $nlen <= \strlen( $haystack ) && 0 === \substr_compare( $haystack, $needle, -$nlen );
	}

	// -------------------------------------------------------------------------
	// Static index helpers (used by Partition::with_index callback path or by
	// callers that compute their own companion index).
	// -------------------------------------------------------------------------

	/**
	 * Format a 97-byte fixed-width index entry for a written request line.
	 *
	 * Layout (97 bytes):
	 *   rid          32 (space-padded right)
	 *   url_hash     12 (space-padded right)
	 *   timestamp    10 (zero-padded left, decimal seconds)
	 *   duration_ms  8  (zero-padded left, decimal ms)
	 *   status_code  3  (zero-padded left, capped at 999)
	 *   segment_id   6  (zero-padded left)
	 *   offset       10 (zero-padded left)
	 *   length       8  (zero-padded left)
	 *   peak_mb      6  (zero-padded left, integer MB)
	 *   method       1  (single-char code)
	 *   error_status 1  ('-' / 'F' / 'T')
	 *
	 * @param string                  $line     The JSON line written.
	 * @param array{segment_id:int,offset:int,length:int} $position
	 * @param \stdClass|array|null    $data     Pre-decoded data; populated on first use to skip a redundant decode.
	 * @return string|null The 97-byte entry, '' on overflow, or null to skip (no URL).
	 */
	public static function format_index_entry( string $line, array $position, &$data = null ): ?string {
		if ( $data instanceof \stdClass ) {
			$request = $data;
		} else {
			$decoded = $data ?? \json_decode( $line, true, 64 );
			if ( ! \is_array( $decoded ) || empty( $decoded['url'] ) ) {
				return null;
			}
			$request = (object) $decoded;
		}

		if ( empty( $request->url ) ) {
			return null;
		}

		$rid          = (string) ( $request->rid ?? '' );
		$url_hash     = self::url_hash( (string) $request->url );
		$timestamp    = (int) ( $request->timestamp ?? \time() );
		$duration_ms  = (int) ( $request->duration_ms ?? 0 );
		$status_code  = (int) ( $request->status_code ?? 0 );
		$peak_mb      = (float) ( $request->peak_mb ?? 0 );
		$segment_id   = (int) $position['segment_id'];
		$offset       = (int) $position['offset'];
		$length       = (int) $position['length'];
		$error_status = $request->error_status ?? '-';

		if ( $offset > 9999999999 || $length > 99999999 || $segment_id > 999999 ) {
			return '';
		}

		// peak_mb: 6 chars, integer MB zero-padded (max 999999 MB).
		$peak_mb_int = \min( (int) \round( $peak_mb ), 999999 );

		// method: 1 char code.
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
	 * Parse a request index entry (v2/v3/v4 backward-compat).
	 *
	 * @param string $line Index line (with optional trailing newline).
	 * @return array<string,mixed>|null Parsed entry or null if too short.
	 */
	public static function parse_request_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		$len  = \strlen( $line );

		if ( $len < 89 ) {
			return null;
		}

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
			$code            = \substr( $line, 95, 1 );
			$entry['method'] = $methods[ $code ] ?? $code;
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

	/**
	 * URL hash — 12-char FNV-1a (two passes).
	 *
	 * Strips query string before hashing so two requests to the same path with
	 * different query strings collapse to the same bucket. Deterministic.
	 */
	public static function url_hash( string $url ): string {
		$str   = \explode( '?', $url, 2 )[0];
		if ( '' === $str ) {
			$str = $url;
		}
		$hash1 = self::fnv1a32( $str );
		$hash2 = self::fnv1a32( $str, $hash1 ^ 0x811c9dc5 );
		return \sprintf( '%08x%04x', $hash1, $hash2 & 0xFFFF );
	}

	/**
	 * FNV-1a 32-bit hash (& 0xFFFFFFFF for 32-bit-PHP compat).
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
}
