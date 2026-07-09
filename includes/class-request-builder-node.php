<?php
/**
 * Request Builder
 *
 * Node that builds request profiles from firehose entries.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request builder node class.
 */
class Request_Builder_Node extends Timer_Node {
	use \Newspack_Nodes\Schema_Reflection;

	/** Default LRU cache capacity. */
	public const DEFAULT_BUCKET_SIZE = 100;
	public const DEFAULT_NUM_BUCKETS = 3;

	/**
	 * Bucket rotation interval in seconds.
	 * 3 buckets x 200s = 600s (10 min) before oldest bucket is evicted.
	 */
	private const BUCKET_ROTATION_S = 200;

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

	/** Maximum stack depth before request is considered runaway and evicted. */
	private const MAX_STACK_DEPTH = 50;

	/** @var LRU_Cache In-flight requests, keyed by rid. */
	public $cache;

	/** @var Request_Flight_Node|null Hidden sibling — periodic in-flight snapshots. */
	public ?Request_Flight_Node $flight = null;

	protected int $bucket_size = self::DEFAULT_BUCKET_SIZE;
	protected int $num_buckets = self::DEFAULT_NUM_BUCKETS;

	/** @var string Named target for compact-summary completed lines (empty = disabled). */
	private string $completed_target = '';

	/** @var string Named target for error/warning lines (empty = disabled). */
	private $errors_target = '';

	/** @var int Process line counter (for tests/debug). */
	private $line_counter = 0;

	/** @var array<string,callable> Keyword → mutator. Set in constructor. */
	private $state_callbacks;

	/**
	 * Tachikoma-parity: no-arg ctor. Positional config arrives via arguments(),
	 * whose override rebuilds the LRU_Cache with the parsed dimensions.
	 *
	 * The Flight sibling and state_callbacks DO NOT depend on the positional
	 * args, so they're set up here in the no-arg ctor — present on every
	 * Request_Builder instance, regardless of whether arguments() is ever called.
	 *
	 * @api Used by substrate.
	 */
	public function __construct() {
		// Initial cache uses schema defaults so the no-arg ctor produces a
		// working instance; arguments() rebuilds with caller-supplied sizes.
		$this->cache = $this->build_cache();
		$this->state_callbacks = $this->build_state_callbacks();

		// Hidden Flight sibling — patron filter hides it from the canvas.
		// Naming happens in the overridden name() setter so the sibling
		// adopts `{patron}:flight` when the patron is named (mirroring the
		// interpreter sibling's `{patron}:config` propagation in Node::name).
		// Sink wiring also propagates from the overridden sink() setter.
		$this->flight = new Request_Flight_Node();
		$this->flight->patron( $this );

		// Rule 2c sibling default: sink into `_command_interpreter` when one is
		// in scope so Flight's emits route there before the topology wires
		// RequestBuilder's own sink. Rule 4: skip when no interpreter exists.
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null !== $ci && null === $this->flight->sink() ) {
			$this->flight->sink( $ci );
		}

		parent::__construct();
		// Wire the sibling :config interpreter from node_schema()['commands']
		// handlers (static; read $interpreter->patron() lazily, so end-placement is fine).
		$this->auto_wire_interpreter();
	}

	/**
	 * Store the raw string, parse positional tokens via parse_schema_args()
	 * (bucket_size / num_buckets), then rebuild the LRU_Cache with the new
	 * dimensions (here — not the ctor — because it depends on the positional args).
	 *
	 * @api Used by substrate.
	 * @param string|null $args
	 * @return string
	 */
	public function arguments( ?string $args = null ): string {
		if ( null === $args ) {
			return parent::arguments();
		}
		$this->parse_schema_args( $args );
		$this->cache = $this->build_cache();
		$this->set_timer();
		return $args;
	}

	/**
	 * Node entry point: process a single line from firehose.log.
	 *
	 * @param array<int, mixed> $message Reference; not mutated.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		$type_raw = $message[ Message::TYPE ];
		$type     = \is_scalar( $type_raw ) ? (int) $type_raw : 0;
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
		// Decoded firehose entry: string-keyed payload (json_decode assoc map).
		/** @var array<string, mixed> $entry */

		$key_raw = $message[ Message::KEY ] ?? '';
		$rid     = \is_scalar( $key_raw ) ? (string) $key_raw : '';
		if ( '' === $rid ) {
			return;
		}

		// Intern keyword strings — json_decode allocates a new string per entry,
		// but most entries share the same ~200 unique keywords. Interning makes
		// all identical strings share one zval, saving ~80 bytes per entry.
		/** @var array<string, string> $intern */
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
			$request->expected_n = 1;
			$this->cache->set( $rid, $request );
		}
		// The cache only ever stores the \stdClass built above for a given rid.
		/** @var \stdClass $request */

		// Per-request sequence validation (ported from Tachikoma InstrumentalityGrail):
		// surface orphaned mid-stream lines, dupes/reorders, and rid reuse instead of
		// silently corrupting assembly. NB: a lost *trailing* line (e.g. the terminal
		// `process (complete)`) has no later n to reveal a gap — that case is the
		// evict-timeout path below, and is prevented by the Consumer seal-grace.
		$seq_n = \is_scalar( $n ) ? (int) $n : 0;

		// Nested subprocess sequence: nuclear-gyrobase shells out to the Perl engine
		// (proc_open), and the child emits its OWN n-sequence (restarting at 1) inside
		// the parent request's stream under the SAME rid. Stash the parent's expected n
		// on `gyrobase (start)` (reset to the nested sequence) and restore it on
		// `gyrobase (complete)` below — so neither the nested restart nor the parent's
		// resume reads as a gap/dup. A stack handles sequential or nested renders.
		if ( 'gyrobase (start)' === $keyword ) {
			$stack               = \is_array( $request->seq_stack ?? null ) ? $request->seq_stack : [];
			$stack[]             = \is_int( $request->expected_n ?? null ) ? $request->expected_n : 1;
			$request->seq_stack  = $stack;
			$request->expected_n = 1;
		}

		$expected = \is_int( $request->expected_n ?? null ) ? $request->expected_n : 1;
		if ( 'process (start)' === $keyword && $expected > 1 ) {
			$this->print_less_often( 'WARNING: multiple requests with ID: ' . $rid );
			$expected = 1;
			$request->expected_n = 1;
		}
		if ( $seq_n < $expected ) {
			$this->print_less_often( 'INFO: duplicate message: expected #' . $expected . ', got #' . $seq_n . ' on ' . $rid );
			return;
		}
		if ( $seq_n > $expected ) {
			$this->print_less_often( 'WARNING: missing message: expected #' . $expected . ', got #' . $seq_n . ' on ' . $rid );
			return;
		}
		$request->expected_n = $seq_n + 1;

		// End of the nested subprocess sequence: pop back to the parent's expected n.
		if ( 'gyrobase (complete)' === $keyword && \is_array( $request->seq_stack ?? null ) && [] !== $request->seq_stack ) {
			$stack               = $request->seq_stack;
			$popped              = \array_pop( $stack );
			$request->expected_n = \is_int( $popped ) ? $popped : 1;
			$request->seq_stack  = $stack;
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
			$dur_v = $entry['duration_ms'] ?? 0;
			$ts_v  = $entry['ts'] ?? 0;
			$this->pop_stack(
				$request,
				\substr( $keyword, 0, -11 ),
				\is_scalar( $dur_v ) ? (float) $dur_v : 0.0,
				\is_scalar( $ts_v ) ? (float) $ts_v : 0.0
			);
		}

		// Track per-line activity timestamps for the inflight snapshot's
		// time_ms / est_ms / lag_ms derivation.
		$ts_log_v             = $entry['ts'] ?? 0;
		$request->last_log_ts = \is_scalar( $ts_log_v ) ? (float) $ts_log_v : 0.0;
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

		// Dynamic \stdClass property: list of stored per-entry records.
		/** @var list<array<string, mixed>> $entries */
		$entries = \is_array( $request->entries ?? null ) ? $request->entries : [];
		if ( isset( $request->entries ) && \count( $entries ) < self::MAX_ENTRIES_PER_REQUEST ) {
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

			$entries[]          = $stored;
			$request->entries   = $entries;
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
	 * Router-TIMER tick. Drives the cache's idle rotation so a stalled in-flight
	 * request times out (error_status='T') and is emitted to both requests.log and
	 * the completed target even on a partition with no inbound firehose traffic.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$this->cache->rotate_if_due();
	}

	/** @param array<int, mixed> $message Incoming command Message. */
	private function handle_request( array $message ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Request_Builder::fill requires a wired sink' );
		}
		$value_raw = $message[ Message::VALUE ];
		$value     = \is_scalar( $value_raw ) ? (string) $value_raw : '';
		$verb      = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_CACHE' === $verb ) {
			$now     = (int) Core::$now;
			$samples = [];
			$oldest_rid = null;
			$oldest_ts  = $now;
			$count      = 0;
			foreach ( $this->cache->iterate() as $rid => $request ) {
				++$count;
				$created = 0;
				if ( \is_array( $request ) ) {
					$proc      = $request['process'] ?? null;
					$created_v = ( \is_array( $proc ) ? ( $proc['ts_start'] ?? null ) : null ) ?? ( $request['ts'] ?? 0 );
					$created   = \is_scalar( $created_v ) ? (int) $created_v : 0;
				}
				if ( $created > 0 && $created < $oldest_ts ) {
					$oldest_ts  = $created;
					$oldest_rid = $rid;
				}
				if ( \count( $samples ) < 5 ) {
					$samples[] = \is_scalar( $rid ) ? (string) $rid : '';
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
		$this->sink->fill( $reply );
	}

	/**
	 * Emit an error/warning entry via the named errors_target.
	 *
	 * @param array<string, mixed> $entry Decoded entry.
	 * @param string $rid   Request id — propagated to Message::KEY so
	 *                      downstream readers can identify the request
	 *                      without re-parsing the entry payload.
	 */
	private function emit_error( array $entry, string $rid ): void {
		if ( '' === $this->errors_target || null === $this->sink ) {
			return;
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->errors_target;
		$message[ Message::KEY ]       = $rid;
		$message[ Message::VALUE ]     = $entry;
		$this->sink->fill( $message );
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
		if ( ! \is_array( $request->stack ) ) {
			$request->stack = [];
		}
		if ( ! \is_array( $request->profiles ) ) {
			$request->profiles = [];
		}

		// References (not copies) so frame/profile mutations write through to the
		// \stdClass properties in place — copy-into-local + write-back would
		// copy-on-write the whole stack + profiles map on every push.
		/** @var list<array{0: string, 1: string}> $stack */
		$stack = &$request->stack;
		// Dynamic \stdClass property: per-state profile records keyed by state name.
		/** @var array<string, array{entries: array<string, array{0: float, 1: int}>, count: int, time: float, ts: float}> $profiles */
		$profiles = &$request->profiles;

		// Stop appending once we've hit the stack-depth cap — keeps memory
		// bounded for runaway requests we deliberately keep visible in the
		// inflight snapshot.
		if ( \count( $stack ) >= self::MAX_STACK_DEPTH ) {
			$request->is_runaway = true;
			return;
		}
		if ( ! isset( $profiles[ $state ] ) ) {
			$profiles[ $state ] = [
				'entries' => [],
				'count'   => 0,
				'time'    => 0,
				'ts'      => 0,
			];
		}

		$stack[] = [ $state, $label ];

		$profile = &$profiles[ $state ];
		if ( $label && \count( $profile['entries'] ) < 1000 && ! isset( $profile['entries'][ $label ] ) ) {
			$profile['entries'][ $label ] = [ 0, 0 ];
		}
		unset( $profile );

		if ( \count( $stack ) >= self::MAX_STACK_DEPTH ) {
			$request->is_runaway = true;
		}
		// No write-back: $stack / $profiles are references to the properties.
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
		if ( ! \is_array( $request->stack ) ) {
			$request->stack = [];
		}
		if ( ! \is_array( $request->profiles ) ) {
			$request->profiles = [];
		}

		// References (not copies): mutate the \stdClass property arrays in place.
		// A copy-into-local + write-back would copy-on-write the whole stack +
		// profiles map on every pop.
		/** @var list<array{0: string, 1: string}> $stack */
		$stack = &$request->stack;
		/** @var array<string, array{entries: array<string, array{0: float, 1: int}>, count: int, time: float, ts: float}> $profiles */
		$profiles = &$request->profiles;

		$last_idx = \count( $stack ) - 1;
		$frame    = $stack[ $last_idx ];

		if ( $frame[0] === $state ) {
			// Fast path: matched top of stack (the common case).
			$label = $frame[1];
			\array_pop( $stack );
		} else {
			// Slow path: mismatched close — search backward and unwind.
			$found_idx = false;
			for ( $i = $last_idx - 1; $i >= 0; $i-- ) {
				if ( $stack[ $i ][0] === $state ) {
					$found_idx = $i;
					break;
				}
			}
			if ( false === $found_idx ) {
				return;
			}

			$label = $stack[ $found_idx ][1];
			\array_splice( $stack, $found_idx );
		}

		if ( isset( $profiles[ $state ] ) ) {
			$profile          = &$profiles[ $state ];
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
		if ( ! empty( $stack ) && ! self::is_callback_state( $state ) ) {
			for ( $j = \count( $stack ) - 1; $j >= 0; $j-- ) {
				$ancestor_frame = $stack[ $j ];
				$ancestor       = $ancestor_frame[0];
				if ( 'process' === $ancestor ) {
					break;
				}
				if ( isset( $profiles[ $ancestor ] ) ) {
					$profiles[ $ancestor ]['time'] -= $time;

					$ancestor_label = $ancestor_frame[1];
					if ( $ancestor_label && isset( $profiles[ $ancestor ]['entries'][ $ancestor_label ] ) ) {
						$profiles[ $ancestor ]['entries'][ $ancestor_label ][0] -= $time;
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

		// No write-back: $stack / $profiles are references to the properties.
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
	 * Construct the LRU_Cache with the current bucket_size / num_buckets,
	 * wired with the eviction callback. Shared between the ctor (defaults)
	 * and arguments() (post-schema-walk).
	 * 
	 * @return LRU_Cache The constructed cache instance.
	 */
	private function build_cache(): LRU_Cache {
		return ( new LRU_Cache( $this->bucket_size, $this->num_buckets ) )
			->with_timed_rotation(
				self::BUCKET_ROTATION_S,
				function ( string $rid, $request ): void {
					$this->evict_request( $rid, $request );
				}
			);
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `cmd {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI). No generic verb recording.
	 *
	 * @api Used by substrate.
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( '' !== $this->errors_target ) {
			$out .= "cmd {$this->name}:config set_errors_target {$this->errors_target}\n";
		}
		if ( '' !== $this->completed_target ) {
			$out .= "cmd {$this->name}:config set_completed_target {$this->completed_target}\n";
		}
		$inflight_target = $this->flight()->target();
		if ( \is_string( $inflight_target ) && '' !== $inflight_target ) {
			$out .= "cmd {$this->name}:config set_inflight_target {$inflight_target}\n";
		}
		return $out;
	}

	public function flight(): Request_Flight_Node {
		if ( null === $this->flight ) {
			throw new \RuntimeException( 'flight sibling not constructed' );
		}
		return $this->flight;
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
			$request->rule_id     = \is_string( $entry['rule'] ?? null ) ? $entry['rule'] : '';
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
			if ( ! \is_string( $message ) ) {
				return;
			}
			if ( \strlen( $message ) < self::MAX_PAYLOAD_SCAN_LENGTH && \preg_match( '/^(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CLI)\s+(.+)$/', $message, $m ) ) {
				// Strip query string — URL hash already ignores it for merging,
				// and keeping it wastes memory and makes the URL table noisy.
				$request->url = \explode( '?', $m[1], 2 )[0];
			}
			$parts                   = \explode( ' ', $message, 2 );
			$request->request_method = $parts[0];
		};

		$s['environment_v3'] = function ( \stdClass $request, array $entry ): void {
			$raw = $entry['m'] ?? null;
			if ( ! \is_array( $raw ) ) {
				return;
			}
			// The v3 `m` is a JSON object, so its keys are strings — make that
			// explicit so the typed env_str() lookups below type-check.
			$env = [];
			foreach ( $raw as $k => $v ) {
				$env[ (string) $k ] = $v;
			}
			// REMOTE_ADDR, when present, wins outright (empty/invalid ⇒ ''); XFF is
			// consulted only when REMOTE_ADDR is entirely absent.
			$remote = self::env_str( $env, 'REMOTE_ADDR' );
			if ( '' !== $remote ) {
				$ip = \trim( $remote );
				$request->remote_addr = \filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
			} else {
				$xff = self::env_str( $env, 'HTTP_X_FORWARDED_FOR' );
				if ( '' !== $xff ) {
					$ip = \trim( \explode( ',', $xff, 2 )[0] );
					if ( \filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						$request->remote_addr = $ip;
					}
				}
			}
			$user_agent = self::env_str( $env, 'HTTP_USER_AGENT' );
			if ( '' !== $user_agent ) {
				$request->user_agent = $user_agent;
			}
			$server_name = self::env_str( $env, 'SERVER_NAME' );
			if ( '' !== $server_name ) {
				$request->server_name = $server_name;
			}
			$country_code = self::env_str( $env, 'GEOIP_COUNTRY_CODE' );
			if ( '' !== $country_code ) {
				$request->country_code = $country_code;
			}
			$http_from = self::env_str( $env, 'HTTP_FROM' );
			if ( '' !== $http_from ) {
				$request->http_from = $http_from;
			}
			$ja4_hash = self::env_str( $env, 'HTTP_X_JA4_HASH' );
			if ( '' !== $ja4_hash ) {
				$request->ja4_hash = $ja4_hash;
			}
			$worker_type = self::env_str( $env, 'NEWSPACK_NODES_WORKER_TYPE' );
			if ( '' !== $worker_type ) {
				// Capture the value so the worker gets its own ?worker_type URL row.
				$request->is_worker   = true;
				$request->worker_type = \preg_replace( '/[^a-z0-9_-]/i', '', $worker_type ) ?? '';
			}
		};

		$s['memory'] = function ( \stdClass $request, array $entry ): void {
			$m = $entry['m'] ?? [];
			if ( \is_array( $m ) && isset( $m['peak'] ) && \is_scalar( $m['peak'] ) ) {
				$request->peak_mb = (float) $m['peak'];
			}
		};

		return $s;
	}

	/**
	 * Read a curated environment_v3 field as a string ('' when absent or non-string).
	 *
	 * @param array<string, mixed> $env Curated env map from the environment_v3 entry.
	 * @param string               $key Field name.
	 */
	private static function env_str( array $env, string $key ): string {
		$value = $env[ $key ] ?? '';
		return \is_string( $value ) ? $value : '';
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
		$now = \time();
		// Dynamic \stdClass property is mixed by design; the int cast is intentional.
		/** @var int|float|string $ts_raw */
		$ts_raw                 = $request->timestamp ?? $now;
		$start_ts               = (int) $ts_raw;
		$request->error_status  = 'T';
		$request->duration_ms   = ( $now - $start_ts ) * 1000;
		$request->status_code   = $request->status_code ?? 0;
		$request->state         = 'complete';
		$url                    = \is_string( $request->url ?? null ) ? $request->url : '';
		$this->print_less_often( 'WARNING: trace timed out on ' . $rid . ' (' . $url . ') after ' . $request->duration_ms . 'ms' );
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
	 * 
	 * @param \stdClass $request Completed request envelope.
	 */
	public function emit_request( \stdClass $request ): void {
		// Workers get their own URL row (?worker_type) so warm/supervisor/job hits
		// don't merge onto the real URL. One mutation here -> index line, compact
		// summary, and stats all read the same effective URL. Real query is already
		// stripped upstream, so the '?' guard only blocks a double-append.
		$worker_type = \is_string( $request->worker_type ?? null ) ? $request->worker_type : '';
		$url         = \is_string( $request->url ?? null ) ? $request->url : '';
		if ( '' !== $worker_type && '' !== $url && ! \str_contains( $url, '?' ) ) {
			$request->url = $url . '?' . $worker_type;
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		// Dynamic \stdClass property is mixed by design; the string cast is intentional.
		/** @var int|float|string $rid_raw */
		$rid_raw                   = $request->rid ?? '';
		$message[ Message::KEY ]       = (string) $rid_raw;
		$message[ Message::VALUE ]     = (array) $request;
		parent::fill( $message );
		$this->emit_compact_summary( $request );
	}

	/**
	 * Fire the secondary compact-summary emit. Silent no-op when the
	 * topology hasn't wired completed_target or a sink isn't attached.
	 *
	 * @param \stdClass $request Completed request envelope.
	 */
	private function emit_compact_summary( \stdClass $request ): void {
		if ( '' === $this->completed_target || null === $this->sink ) {
			return;
		}
		$summary                   = $this->build_compact_summary( $request );
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->completed_target;
		$message[ Message::KEY ]       = $summary['rid'];
		$message[ Message::VALUE ]     = $summary;
		$this->sink->fill( $message );
	}

	/**
	 * Build an HTTP-access-log-style compact summary from a completed
	 * request envelope. The schema is a fixed wire contract the request-log
	 * dashboard consumes. URL clipped to 2000 chars + "..." suffix; UA to 500.
	 *
	 * @param \stdClass $request Completed request envelope.
	 * @return array<string,mixed>
	 */
	public function build_compact_summary( \stdClass $request ): array {
		// Decoded request envelope: string-keyed map with mixed-by-design values.
		/** @var array<string, mixed> $r */
		$r = (array) $request;
		// Mixed-by-design (array)/stdClass reads; the string casts are intentional.
		/** @var int|float|string|bool|null $url_raw */
		$url_raw = $r['url'] ?? '';
		$url     = (string) $url_raw;
		/** @var int|float|string|bool|null $ua_raw */
		$ua_raw = $r['user_agent'] ?? '';
		$ua     = (string) $ua_raw;
		/** @var int|float|string|bool|null $rid_raw */
		$rid_raw = $r['rid'] ?? '';
		/** @var int|float|string|bool|null $method_raw */
		$method_raw = $r['request_method'] ?? 'GET';
		/** @var int|float|string|bool|null $remote_addr_raw */
		$remote_addr_raw = $r['remote_addr'] ?? '';
		// Preserve native numeric type for ts and dur — do not cast them.
		// json_encode strips trailing `.0`, so an int-valued float round-trips
		// as int through the wire; keeping the native type is what makes the
		// encode/decode round-trip stable.
		/** @var int|float $ts */
		$ts = $r['timestamp'] ?? 0;
		/** @var int|float $dur */
		$dur = $r['duration_ms'] ?? 0;
		return [
			'rid'          => (string) $rid_raw,
			'method'       => (string) $method_raw,
			'url'          => \strlen( $url ) > 2000 ? \substr( $url, 0, 2000 ) . '...' : $url,
			'start_time'   => $ts,
			'end_time'     => $ts + ( $dur / 1000 ),
			'duration_ms'  => $dur,
			'status_code'  => $r['status_code'] ?? 0,
			'state'        => 'complete',
			'error_status' => $r['error_status'] ?? '-',
			'remote_addr'  => (string) $remote_addr_raw,
			'user_agent'   => \strlen( $ua ) > 500 ? \substr( $ua, 0, 500 ) . '...' : $ua,
		];
	}

	/** @param array<string, mixed> $request Completed request record. */
	public static function extract_what( array $request ): string {
		return self::extract_stack_top_slot( $request, 1, 'what', '' );
	}

	/** @param array<string, mixed> $request Completed request record. */
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
	 * Format index entry callback for Partition::with_index().
	 *
	 * @param array<int, mixed>  $message  The unpacked message array; VALUE is index 6.
	 * @param array<string, int> $position Position array.
	 * @return string|null Index entry or null.
	 */
	public static function format_index_entry( array $message, array $position ): ?string {
		$value = $message[ Message::VALUE ] ?? null;
		if ( ! \is_array( $value ) || empty( $value['url'] ) ) {
			return null;
		}
		// Decoded request envelope: string-keyed map with mixed-by-design values.
		/** @var array<string, mixed> $value */
		$request = (object) $value;

		// Dynamic \stdClass property reads are mixed by design; the casts are intentional.
		$rid_raw = $request->rid ?? '';
		$rid     = \is_string( $rid_raw ) ? $rid_raw : '';
		$url_raw = $request->url ?? '';
		$url     = \is_string( $url_raw ) ? $url_raw : '';
		$url_hash     = Log_Manager::url_hash( $url );
		/** @var int|float|string $ts_raw */
		$ts_raw      = $request->timestamp ?? \time();
		$timestamp   = (int) $ts_raw;
		/** @var int|float|string $dur_raw */
		$dur_raw     = $request->duration_ms ?? 0;
		$duration_ms = (int) $dur_raw;
		/** @var int|float|string $status_raw */
		$status_raw  = $request->status_code ?? 0;
		$status_code = (int) $status_raw;
		/** @var int|float|string $peak_raw */
		$peak_raw     = $request->peak_mb ?? 0;
		$peak_mb      = (float) $peak_raw;
		$segment   = $position['segment'];
		$offset       = $position['offset'];
		$length       = $position['length'];
		$es_raw       = $request->error_status ?? '-';
		$error_status = \is_string( $es_raw ) ? $es_raw : '-';

		if ( $offset > 9999999999 || $length > 99999999 || $segment > 999999 ) {
			return '';
		}

		// peak_mb: 6 chars, integer MB zero-padded (max 999999 MB).
		$peak_mb_int = \min( (int) \round( $peak_mb ), 999999 );

		// method: 1 char code for HTTP method.
		/** @var array<string, string> $method_codes */
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
		$rm_raw = $request->request_method ?? 'GET';
		$method = $method_codes[ \is_string( $rm_raw ) ? $rm_raw : 'GET' ] ?? 'G';

		return \str_pad( \substr( $rid, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash, 0, 12 ), 12 )
			. \str_pad( (string) $timestamp, 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) \min( $duration_ms, 99999999 ), 8, '0', STR_PAD_LEFT )
			. \str_pad( (string) \min( $status_code, 999 ), 3, '0', STR_PAD_LEFT )
			. \str_pad( (string) $segment, 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $offset, 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $length, 8, '0', STR_PAD_LEFT )
			. \str_pad( (string) $peak_mb_int, 6, '0', STR_PAD_LEFT )
			. $method
			. $error_status;
	}

	/**
	 * Pre-check the `{name}:flight` sibling name for collisions before the base
	 * commits a rename. Flight is application-specific; the parent handles the
	 * :config interpreter sibling.
	 *
	 * @api Used by substrate.
	 * @param string $name Proposed new name for this node.
	 */
	protected function check_name_availability( string $name ): void {
		if ( null !== $this->flight && null !== Core::node( "{$name}:flight" ) ) {
			throw new \RuntimeException( \esc_html( "node name collision: {$name}:flight already registered" ) );
		}
		parent::check_name_availability( $name );
	}

	/**
	 * Track the patron name on the Flight sibling as `{name}:flight`. Only called
	 * from name() with a non-empty $name; sibling teardown lives in remove_node().
	 * Mirrors Node::set_sibling_names for the :config interpreter.
	 *
	 * @api Used by substrate.
	 * @param string|null $name New name for this node, or null to skip renaming
	 */
	protected function set_sibling_names( ?string $name = null ): void {
		$this->flight?->name( "{$name}:flight" );
		parent::set_sibling_names( $name );
	}

	/**
	 * Unregister the Flight sibling on teardown so a name-recycle doesn't collide with an orphan.
	 *
	 * @api Used by substrate.
	 */
	public function remove_node(): void {
		if ( null !== $this->flight ) {
			$this->flight->remove_node();
		}
		parent::remove_node();
	}

	/**
	 * Override Node::sink() so the auto-sink wiring make_node performs on
	 * RequestBuilder also reaches the hidden Flight sibling. Without this,
	 * Flight's $this->sink stays null and its in-flight emits drop on the
	 * floor.
	 *
	 * @api Used by substrate.
	 * @param Node|null $node New sink node or null to get current sink.
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
	 *
	 * @param string $target Target node name for completed-request lines.
	 */
	public function set_completed_target( string $target ): void {
		$this->completed_target = $target;
	}

	/**
	 * Set the named target for error/warning forwarding.
	 * 
	 * @param string $target Target node name for error/warning forwarding.
	 */
	public function set_errors_target( string $target ): void {
		$this->errors_target = $target;
	}

	/**
	 * Expose every named destination this node actually writes to so the
	 * console's TARGET column reflects the full fan-out: the primary target plus
	 * the conditional errors_target / completed_target and the flight sibling's
	 * target. Without this override those partitions render disconnected on the
	 * topology console (no inbound edge) despite being written to.
	 *
	 * @api Used by substrate.
	 * @param array<int, string>|string|null $value New primary target or null to get current target.
	 * @return array<int, string>|string
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
			: ( '' !== $primary ? [ $primary ] : [] );
		foreach ( $extras as $e ) {
			if ( ! \in_array( $e, $all, true ) ) {
				$all[] = $e;
			}
		}
		return $all;
	}

	/**
	 * Save state for persistence.
	 *
	 * Persists the full request cache (including entries and profiles)
	 * so in-flight requests retain trace data across worker restarts.
	 * Orphan eviction is handled by LRU bucket rotation.
	 *
	 * @api Used by substrate.
	 * @return array<string, mixed> State to persist.
	 */
	public function save_state(): array {
		// Convert objects to arrays for serialization.
		$state = $this->cache->get_state();
		if ( isset( $state['buckets'] ) && \is_array( $state['buckets'] ) ) {
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
	 * @api Used by substrate.
	 * @param array<string, mixed> $saved Saved state from save_state().
	 */
	public function restore_state( array $saved ): void {
		if ( ! isset( $saved['request_cache'] ) ) {
			return;
		}
		$cache_state = $saved['request_cache'];
		if ( ! \is_array( $cache_state ) ) {
			return;
		}
		// Persisted cache snapshot: string-keyed by design (LRU_Cache::get_state()).
		/** @var array<string, mixed> $cache_state */
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
	 * Parse request index entry.
	 *
	 * @param string $line Index line.
	 * @return array<string, mixed>|null Parsed entry or null.
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
				'segment'  => (int) \substr( $line, 65, 6 ),
				'offset'      => (int) \substr( $line, 71, 10 ),
				'length'      => (int) \substr( $line, 81, 8 ),
			];

			// peak_mb field appended in v2 format (position 89, 6 chars).
			if ( $len >= 95 ) {
				$entry['peak_mb'] = (int) \substr( $line, 89, 6 );
			}

			// method field appended in v3 format (position 95, 1 char).
			if ( $len >= 96 ) {
				/** @var array<string, string> $methods */
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

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Assembles per-request firehose lines into completed-request docs; emits errors to a named partition.',
			'arguments'        => [
				[ 'name' => 'bucket_size', 'type' => 'int', 'default' => self::DEFAULT_BUCKET_SIZE ],
				[ 'name' => 'num_buckets', 'type' => 'int', 'default' => self::DEFAULT_NUM_BUCKETS ],
			],
			'commands'       => [
				[
					'name'        => 'set_errors_target',
					'description' => 'Forward error/warning keywords to a named partition.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \trim( $args );
						// Empty arg clears the target (disables the secondary emit).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_errors_target( $args );
						return 'ok';
					},
				],
				[
					'name'        => 'set_completed_target',
					'description' => 'Emit a compact one-line summary of each completed request to a named partition (in addition to the primary full-doc emit).',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \trim( $args );
						// Empty arg clears the target (disables the secondary emit).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_completed_target( $args );
						return 'ok';
					},
				],
				[
					'name'        => 'set_inflight_target',
					'description' => 'Emit periodic in-flight request snapshots (on the Router tick) to a named partition (typically the gyroscope) via the hidden Flight sibling. Setting a target enables snapshots; an empty arg clears it and stops them.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \trim( $args );
						// Flight::target() registers the Router-TIMER hitchhike for a
						// non-empty target and stops it for an empty one.
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->flight()->target( $args );
						return 'ok';
					},
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
