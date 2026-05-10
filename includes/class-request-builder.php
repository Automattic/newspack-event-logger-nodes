<?php
/**
 * Request Builder
 *
 * Node that builds request profiles from firehose entries.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

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
	private $cache;

	/** @var array<string,callable> Keyword → mutator. Set in constructor. */
	private $state_callbacks;

	/** @var string Named target for error/warning lines (empty = disabled). */
	private $errors_target = '';

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
	}

	/**
	 * Set the named target for error/warning forwarding.
	 */
	public function set_errors_target( string $target ): void {
		$this->errors_target = $target;
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
		if ( ! ( $message[ Message::TYPE ] & Message::TM_STRUCT ) ) {
			return;
		}
		$entry = $message[ Message::VALUE ];
		$this->cache->rotate_if_due();
		if ( ! \is_array( $entry ) ) {
			return;
		}

		$rid = $entry['rid'] ?? null;
		if ( ! \is_string( $rid ) || '' === $rid ) {
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

		// Forward errors and warnings to errors.log.
		if ( 'error' === $keyword || 'warning' === $keyword
			|| \str_ends_with( $keyword, '(error)' )
			|| \str_ends_with( $keyword, '(warning)' )
		) {
			$this->emit_error( $entry );
		}

		if ( isset( $this->state_callbacks[ $keyword ] ) ) {
			$this->state_callbacks[ $keyword ]( $request, $entry );
		} elseif ( \str_ends_with( $keyword, ' (start)' ) ) {
			$label = $entry['l'] ?? '';
			$this->push_stack( $request, \substr( $keyword, 0, -8 ), \is_string( $label ) ? $label : '' );
		} elseif ( \str_ends_with( $keyword, ' (complete)' ) ) {
			$this->pop_stack( $request, \substr( $keyword, 0, -11 ), $entry['duration_ms'] ?? 0, $entry['ts'] ?? 0 );
		}

		// Evict runaway requests immediately.
		if ( $request->is_runaway ?? false ) {
			$this->cache->delete( $rid );
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
			$request->request_method = $parts[0] ?? '';
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

		if ( \count( $request->stack ) > self::MAX_STACK_DEPTH ) {
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
	 * Called by the LruCache eviction callback.
	 *
	 * @param string    $rid     Request ID.
	 * @param \stdClass $request Request object.
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
	 */
	private function emit_request( \stdClass $request ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$right_now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::VALUE ]     = (array) $request;
		parent::fill( $msg );
	}

	/**
	 * Emit an error/warning entry via the named errors_target.
	 *
	 * @param array $entry Decoded entry.
	 */
	private function emit_error( array $entry ): void {
		if ( '' === $this->errors_target || null === $this->sink ) {
			return;
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$right_now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = $this->errors_target;
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
}
