<?php
/**
 * Request Builder
 *
 * Assembles the firehose's per-line stream back into whole requests. Every
 * PHP request writes a numbered sequence of small JSON lines (`process
 * (start)`, `request`, `environment_v3`, one pair per instrumented hook,
 * `process (complete)`) tagged with its request id; nothing on disk holds a
 * request as a unit. This node is where that unit exists: it keys an
 * in-flight `\stdClass` envelope per rid in an `LRU_Cache`, folds each
 * arriving line into it, and emits the finished envelope downstream.
 *
 * Four streams leave here, all optional but the first:
 *
 * - the full completed-request doc to the primary sink (`requests.p{N}`);
 * - a compact one-line summary to `completed_target`;
 * - `error` / `warning` / `stderr` lines to `errors_target`, and `alert`
 *   lines to `alerts_target`;
 * - periodic in-flight snapshots, emitted by the hidden `Request_Flight_Node`
 *   sibling this node constructs and wires.
 *
 * Requests that never complete are not lost: the cache's timed bucket
 * rotation evicts them, and eviction writes them out with `error_status='T'`.
 *
 * The two static index helpers, `format_index_entry()` and
 * `parse_request_index()`, are a matched pair — a fixed-width companion-index
 * line writer and its reader. The writer is registered as the `request-index`
 * formatter, and the `performance` CI's lookup verbs read through both.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\LRU_Cache;
use Newspack_Nodes\Line_Fitter;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folds firehose lines into per-request envelopes and emits them when complete.
 *
 * A `Timer_Node` rather than a plain `Node`: the router tick drives the idle
 * bucket rotation that times out stalled requests, so a partition receiving no
 * traffic still flushes what it is holding.
 *
 * @phpstan-import-type Fold_State from Flame_Fold
 */
class Request_Builder_Node extends Timer_Node {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;

	/** Discarded on overflow: the entries between this and the last one are gone. */
	public const LOST_MARKER_KEY = 'entries (lost)';

	/** Merged by the pressure fold: those entries are in the flame tree instead. */
	public const FOLD_MARKER_KEY = 'entries (aggregated)';

	/**
	 * Entry keys standing in for entries this node removed. Either way the
	 * interval one covers is missing DETAIL, not idle time, so no consumer may
	 * reason across it. This node mints them, so it owns the vocabulary.
	 *
	 * @var list<string>
	 */
	public const SEQUENCE_BREAK_KEYS = [ self::LOST_MARKER_KEY, self::FOLD_MARKER_KEY ];

	/**
	 * Every non-nominal terminal marker: fatal, timed out, aborted, incomplete.
	 * The index writer, its reader and the `process (complete)` validator all
	 * read this one list, because three parallel copies is how a status becomes
	 * writable but unreadable.
	 */
	public const ERROR_STATUSES = [ 'F', 'T', 'A', 'I' ];

	/**
	 * The index line's 1-char method column, written here and read back through
	 * `array_flip()`. Two hand-kept tables is how a method added to one side
	 * decodes as a bare letter on the other.
	 */
	private const METHOD_CODES = [
		'GET'     => 'G',
		'POST'    => 'P',
		'HEAD'    => 'H',
		'DELETE'  => 'D',
		'PUT'     => 'U',
		'PATCH'   => 'A',
		'OPTIONS' => 'O',
		'CLI'     => 'C',
	];

	/**
	 * Where each RAW-COMPARABLE field sits on an index line, `[offset, length]`.
	 *
	 * Only columns whose trimmed bytes equal the parsed value belong here: a
	 * scan pre-filters on them and parses only on a hit, so a zero-padded or
	 * coded column would answer a question it cannot answer.
	 */
	private const INDEX_COLUMNS = [
		'rid'      => [ 0, 32 ],
		'url_hash' => [ 32, 12 ],
	];

	/**
	 * The two columns a line's COMPLETION is read from: the zero-padded start
	 * time, and the duration in milliseconds that closes it.
	 *
	 * Their own table, not INDEX_COLUMNS rows: zero padding is what makes the
	 * raw bytes sort the way the numbers do, and that is a different property
	 * from the equality the pre-filter columns promise.
	 */
	private const COMPLETION_COLUMNS = [ [ 44, 10 ], [ 54, 8 ] ];

	/** Markers that close a request. They land even when the sequence broke. */
	public const TERMINAL_KEYWORDS = [ Log_Manager::REQUEST_COMPLETE => true, Log_Manager::REQUEST_ABORTED => true ];

	/** Default in-flight requests held per LRU bucket. */
	public const DEFAULT_BUCKET_SIZE = 100;

	/** Default number of rotating LRU buckets. */
	public const DEFAULT_NUM_BUCKETS = 3;

	/** Bucket rotation interval in seconds; see DEFAULT_EVICTION_WINDOW_SEC below. */
	private const BUCKET_ROTATION_S = 200;

	/**
	 * The longest a request stays in flight under the DEFAULT declaration.
	 *
	 * Timed rotation evicts the oldest bucket, and `evict_request()` writes
	 * whatever is still open as timed out, so no request outlives every bucket.
	 * `Stats_Store::MAX_FUTURE_SKEW_SEC` borrows that magnitude as the lateness
	 * it tolerates from a producer's clock.
	 *
	 * It measures `DEFAULT_NUM_BUCKETS`, not `$this->num_buckets`, because a
	 * constant cannot follow a per-topology declaration — so a topology that
	 * declares another count says so through `build_cache()` rather than
	 * leaving the borrow quietly wrong.
	 */
	public const DEFAULT_EVICTION_WINDOW_SEC = self::DEFAULT_NUM_BUCKETS * self::BUCKET_ROTATION_S;

	/** Longest keyword the intern table accepts; longer ones pass through. */
	private const INTERN_MAX_KEY_LENGTH  = 256;

	/** Cap on intern-table entries; past it, keywords pass through uninterned. */
	private const INTERN_TABLE_LIMIT = 50000;

	/** Default entries one REQUEST holds before it folds itself. */
	public const DEFAULT_MAX_ENTRIES_PER_REQUEST = 20000;

	/** Default entries held across ALL in-flight requests; ~18MB each at 50,000. */
	public const DEFAULT_ENTRY_BUDGET = 50000;

	/**
	 * Appends between pressure checks once folding can no longer help.
	 *
	 * `$appended` only ever climbs, so a check that finds the true total still
	 * over budget would otherwise re-scan on every single entry.
	 */
	private const PRESSURE_CHECK_STRIDE = 1000;

	/** The one width `format_index_entry()` writes and `parse_request_index()` reads. */
	private const INDEX_LINE_BYTES = 97;

	/** Longest `request` line the URL regex scans; past it, no URL is read. */
	private const MAX_PAYLOAD_SCAN_LENGTH = 8192;

	/** Max distinct labels tracked per profiled state (bounds runaway memory). */
	private const MAX_PROFILE_ENTRY_LABELS = 1000;

	/**
	 * Open spans one request may hold before it is marked runaway. A runaway
	 * stays visible in the in-flight view and stores no further entries; it
	 * leaves on the ordinary bucket rotation, like every other request.
	 */
	private const MAX_STACK_DEPTH = 50;

	/** @var LRU_Cache In-flight requests, keyed by rid. */
	public $cache;

	/** @var Request_Flight_Node|null Hidden sibling — periodic in-flight snapshots. */
	public ?Request_Flight_Node $flight = null;

	/** @var bool Snapshot only rows whose activity advanced; read by Flight's fire(). */
	private bool $inflight_delta = false;

	/** @var int Positional arg 0 — in-flight requests per LRU bucket. */
	protected int $bucket_size = self::DEFAULT_BUCKET_SIZE;

	/** @var int Positional arg 1 — rotating LRU buckets; oldest is evicted. */
	protected int $num_buckets = self::DEFAULT_NUM_BUCKETS;

	/** @var int Positional arg 2 — entries held across ALL in-flight requests. */
	protected int $entry_budget = self::DEFAULT_ENTRY_BUDGET;

	/** @var int Entries one request holds before folding itself. */
	protected int $max_entries_per_request = self::DEFAULT_MAX_ENTRIES_PER_REQUEST;

	/**
	 * @var int Entries held across every in-flight request. An estimate, and
	 *          deliberately one that only over-counts — a request that
	 *          completes takes its entries with it and nothing decrements
	 *          here. The check replaces it with the truth, so drift costs a
	 *          scan and never a missed fold.
	 */
	private int $appended = 0;

	/**
	 * Raw entries a folded request keeps from its head — how a request
	 * identifies itself: process start, request line, environment.
	 */
	private const FOLD_KEEP_HEAD = 10;

	/**
	 * Raw entries a folded request keeps from its tail — how it ends: stats
	 * flush, memory, process complete. Both ends are bounded, and it is the
	 * repetitive middle that costs memory.
	 */
	private const FOLD_KEEP_TAIL = 10;

	/** @var int `$appended` value that triggers the next pressure check. */
	private int $next_check = self::DEFAULT_ENTRY_BUDGET + 1;

	/** @var string Named target for compact-summary completed lines (empty = disabled). */
	private string $completed_target = '';

	/** @var string Named target for error/warning lines (empty = disabled). */
	private $errors_target = '';

	/** @var string Late-bound node NAME `alert` entries also forward to ('' = off). */
	private $alerts_target = '';

	/** @var int Firehose lines seen since start; reported by `GET_CACHE`. */
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
		// Schema-default cache so the no-arg ctor works; arguments() rebuilds.
		$this->cache = $this->build_cache();
		$this->state_callbacks = $this->build_state_callbacks();

		// Hidden Flight sibling; the publish cascades its name and sink.
		$flight = new Request_Flight_Node();
		$flight->patron( $this );

		// Rule 2c: default Flight's sink to _command_interpreter when in scope.
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null !== $ci && null === $flight->sink() ) {
			$flight->sink( $ci );
		}

		$this->publish_sibling( 'flight', $flight );
		$this->flight = $flight;

		parent::__construct();
		// Wire :config interpreter last: handlers read patron() lazily (safe).
		$this->auto_wire_interpreter();
	}

	/**
	 * Store and parse the positional token array via parse_schema_args()
	 * (bucket_size, num_buckets, entry_budget, max_entries_per_request), then
	 * rebuild the LRU_Cache with the new dimensions and re-arm the pressure
	 * check at the new budget. The rebuild lives here, not in the constructor,
	 * because it depends on the positional args. Either budget declared at 0 or
	 * below clamps to 1.
	 *
	 * Calling this also arms the router-tick timer, so a node constructed but
	 * never given arguments never fires.
	 *
	 * @api Used by substrate.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string> The tokens as given.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return parent::arguments();
		}
		$this->parse_schema_args( $args );
		$this->entry_budget            = \max( 1, $this->entry_budget );
		$this->max_entries_per_request = \max( 1, $this->max_entries_per_request );
		$this->cache        = $this->build_cache();
		$this->next_check   = $this->entry_budget + 1;
		$this->set_timer();
		return $args;
	}

	/**
	 * Node entry point: fold one firehose line into its request envelope.
	 *
	 * TM_REQUEST messages are commands and divert to `handle_request()`.
	 * Everything else must be TM_STRUCT with a decoded entry in VALUE and the
	 * request id in KEY; anything else is dropped silently.
	 *
	 * The line's `n` field is a per-request sequence number, and this method is
	 * the only place it is checked. A line numbered below the expected value is
	 * a duplicate, above it a gap; both are logged and dropped, so a request
	 * whose middle is missing stops accumulating rather than reporting a
	 * plausible-looking lie. A terminal marker is the one exception on the gap
	 * side: it lands anyway, or the request strands in the cache until eviction.
	 * A nested render (gyrobase via `proc_open`) restarts numbering at 1 under
	 * the same rid, which `seq_stack` accommodates by saving the parent's
	 * expected value across the subprocess.
	 *
	 * A request completes when a terminal callback — `process (complete)` or
	 * `process (aborted)` — sets `state`; this method then emits the envelope
	 * and drops it immediately, to get the state back out of RAM rather than
	 * wait for eviction.
	 *
	 * @param array<int,mixed> $message The firehose line or command to fold in.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		// Per-message deferral: clear a stale stop from a prior fill().
		$this->clear_pending_stop();
		$type_raw = $message[ Message::TYPE ];
		$type     = Core::as_int( $type_raw );
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
		/** @var array<string,mixed> $entry */

		$key_raw = $message[ Message::KEY ] ?? '';
		$rid     = Core::as_string( $key_raw );
		if ( '' === $rid ) {
			return;
		}

		// Intern keywords: dedupe json_decode's per-entry strings to one zval.
		/** @var array<string,string> $intern */
		static $intern = [];
		$keyword       = $entry['k'] ?? '';
		if ( ! \is_string( $keyword ) ) {
			return;
		}
		if ( \strlen( $keyword ) <= self::INTERN_MAX_KEY_LENGTH && \count( $intern ) < self::INTERN_TABLE_LIMIT ) {
			$keyword = $intern[ $keyword ] ??= $keyword;
		}
		$n = $entry['n'] ?? 0;
		++$this->line_counter;

		// get() returns the same object instance — mutations happen in place.
		$request = $this->cache->get( $rid );
		if ( null === $request ) {
			// Only the request opener opens a request; orphan lines drop.
			if ( Log_Manager::REQUEST_START !== $keyword ) {
				return;
			}
			$request = new \stdClass();
			$request->rid = $rid;
			$request->expected_n = 1;
			$this->cache->set( $rid, $request );
		}
		// The cache only ever stores the \stdClass built above for a given rid.
		/** @var \stdClass $request */

		// Validate per-request seq n: catch orphans, dupes, reorders, reuse.
		$seq_n = Core::as_int( $n );

		// Nested render (proc_open) restarts n=1 same rid; stack saves parent.
		if ( 'gyrobase (start)' === $keyword ) {
			$stack               = \is_array( $request->seq_stack ?? null ) ? $request->seq_stack : [];
			$stack[]             = \is_int( $request->expected_n ?? null ) ? $request->expected_n : 1;
			$request->seq_stack  = $stack;
			$request->expected_n = 1;
		}

		$expected = \is_int( $request->expected_n ?? null ) ? $request->expected_n : 1;
		if ( Log_Manager::REQUEST_START === $keyword && $expected > 1 ) {
			$this->print_less_often( 'WARNING: multiple requests with ID: ', $rid );
			$expected = 1;
			$request->expected_n = 1;
		}
		// @longform The rid alone does not find the trace. ID is the consumer's
		// segment:offset:length — the seek back onto the log — and FROM names
		// whose stream to seek in.
		$whence = ' on ' . $rid . ' from ' . Core::as_string( $message[ Message::FROM ] ?? '' ) . ' at ' . Core::as_string( $message[ Message::ID ] ?? '' );
		if ( $seq_n < $expected ) {
			$this->print_less_often( 'INFO: duplicate message: expected #', (string) $expected, ', got #', (string) $seq_n, $whence );
			return;
		}
		if ( $seq_n > $expected ) {
			$this->print_less_often( 'WARNING: missing message: expected #', (string) $expected, ', got #', (string) $seq_n, $whence );
			// First hole only: a later one names the last line dropped.
			if ( 0 === Core::int( $request->gap_after ?? 0, 0 ) ) {
				$request->gap_after = $expected - 1;
			}
			// Terminal markers still land, or the request strands in the LRU.
			if ( ! isset( self::TERMINAL_KEYWORDS[ $keyword ] ) ) {
				return;
			}
			$this->store_gap_entry( $request, $entry );
		}
		$request->expected_n = $seq_n + 1;
		// Consumer stamps ID seg:offset:length — the seek back onto the log.
		$request->last_position = Core::as_string( $message[ Message::ID ] ?? '' );

		// End of nested subprocess sequence: pop back to parent's expected n.
		if ( 'gyrobase (complete)' === $keyword && \is_array( $request->seq_stack ?? null ) && [] !== $request->seq_stack ) {
			$stack               = $request->seq_stack;
			$popped              = \array_pop( $stack );
			$request->expected_n = Core::int( $popped, 1 );
			$request->seq_stack  = $stack;
		}

		// Alerts route to the journal; onward fan-out is topology wiring.
		if ( 'alert' === $keyword ) {
			$this->emit_entry( $entry, $rid, $request, $this->alerts_target );
		} elseif ( 'error' === $keyword || 'warning' === $keyword
			|| 'stderr' === $keyword
			|| \str_ends_with( $keyword, '(error)' )
			|| \str_ends_with( $keyword, '(warning)' )
		) {
			$this->emit_entry( $entry, $rid, $request, $this->errors_target );
		}

		if ( isset( $this->state_callbacks[ $keyword ] ) ) {
			$this->state_callbacks[ $keyword ]( $request, $entry );
		} elseif ( \str_ends_with( $keyword, ' (start)' ) ) {
			$label = $entry['l'] ?? '';
			$this->push_stack( $request, \substr( $keyword, 0, -8 ), Core::str( $label ) );
		} elseif ( \str_ends_with( $keyword, ' (complete)' ) ) {
			$dur_v = $entry['duration_ms'] ?? 0;
			$ts_v  = $entry['ts'] ?? 0;
			$this->pop_stack(
				$request,
				\substr( $keyword, 0, -11 ),
				Core::as_float( $dur_v ),
				Core::as_float( $ts_v )
			);
		}

		// Per-line activity timestamps for the inflight snapshot's *_ms derive.
		$ts_log_v             = $entry['ts'] ?? 0;
		$request->last_log_ts = Core::as_float( $ts_log_v );
		$request->tracker_ts  = Core::$now ?: Core::right_now();

		// Runaways stay visible (Perl gyroscope parity); still evicted+bounded.
		if ( $request->is_runaway ?? false ) {
			$this->raise_pending_stop();
			return;
		}

		// Dynamic \stdClass property: list of stored per-entry records.
		/** @var list<array<string,mixed>> $entries */
		$entries = \is_array( $request->entries ?? null ) ? $request->entries : [];
		if ( isset( $request->entries ) ) {
			$stored = [
				'n'  => $n,
				'ts' => $entry['ts'] ?? 0,
				'k'  => $keyword,
			];

			$stored['m'] = $entry['m'] ?? '';

			if ( isset( $entry['l'] ) ) {
				$stored['l'] = $entry['l'];
			}
			if ( isset( $entry['duration_ms'] ) ) {
				$stored['duration_ms'] = $entry['duration_ms'];
			}
			if ( isset( $entry['peak_mb'] ) ) {
				$stored['peak_mb'] = $entry['peak_mb'];
			}
			// Allowlist: a producer field named nowhere here is dropped.
			if ( isset( $entry['caller'] ) ) {
				$stored['caller'] = Core::as_string( $entry['caller'], '' );
			}
			// Log_Manager trimmed this entry to fit; the reader says so.
			if ( ! empty( $entry['truncated'] ) ) {
				$stored['truncated'] = true;
			}
			// The producer's fold exemption; see is_kept().
			if ( ! empty( $entry['keep'] ) ) {
				$stored['keep'] = 1;
			}

			// Folded stays folded: into the path map, never back onto the list.
			$fold = $request->fold ?? null;
			if ( \is_array( $fold ) ) {
				/** @var Fold_State $fold */
				Flame_Fold::add( $fold, $stored );
				$request->fold = $fold;
				// Consume the await first: a marked close is still a close.
				$closes = self::closes_head_span( $request, $stored );
				if ( $closes || self::is_kept( $stored ) ) {
					$request->keep = self::bucket( $request->keep ?? null, $stored, null );
				} else {
					$request->tail = self::bucket( $request->tail ?? null, $stored, self::FOLD_KEEP_TAIL );
				}
			} else {
				$entries[]        = $stored;
				$request->entries = $entries;
				++$this->appended;
				if ( \count( $entries ) >= $this->max_entries_per_request ) {
					// This one is the runaway; fold IT, not the pool's largest.
					$this->appended -= $this->fold_request( $request );
				} elseif ( $this->appended >= $this->next_check ) {
					$this->relieve_pressure();
				}
			}
		}

		if ( 'complete' === ( $request->state ?? '' ) ) {
			// Write immediately to get state out of RAM.
			if ( ! empty( $request->url ) ) {
				$this->emit_request( $request );
			}
			$this->cache->delete( $rid );
		}

		$this->raise_pending_stop();
	}

	/**
	 * Router-TIMER tick. Drives the cache's idle rotation so a stalled in-flight
	 * request times out (error_status='T') and reaches both the primary sink and
	 * the completed target even on a partition with no inbound firehose traffic.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$this->cache->rotate_if_due();
	}

	/**
	 * Answer a TM_REQUEST verb with a TM_STRUCT reply addressed back to FROM.
	 *
	 * `GET_CACHE` reports in-flight depth for the REPL and dashboards. An
	 * unknown verb still gets a reply, carrying an `error` payload.
	 *
	 * @param array<int,mixed> $message Incoming command Message.
	 * @throws \RuntimeException When no sink is wired to carry the reply.
	 */
	private function handle_request( array $message ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Request_Builder::fill requires a wired sink' );
		}
		$value_raw = $message[ Message::VALUE ];
		$value     = Core::as_string( $value_raw );
		$verb      = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_CACHE' === $verb ) {
			$now     = (int) Core::$now;
			$samples = [];
			$oldest_rid = null;
			$oldest_ts  = $now;
			$count      = 0;
			foreach ( $this->cache->iterate() as $rid => $request ) {
				++$count;
				// Cache holds stdClass; an is_array test reads "no stall".
				$created = $request instanceof \stdClass
					? Core::as_int( $request->timestamp ?? 0 )
					: 0;
				if ( $created > 0 && $created < $oldest_ts ) {
					$oldest_ts  = $created;
					$oldest_rid = $rid;
				}
				if ( \count( $samples ) < 5 ) {
					$samples[] = Core::as_string( $rid );
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
	 * Mark the hole in the trace itself, where a reader will meet it.
	 *
	 * With no resync there is exactly one hole and it is always at the tail:
	 * entries run 1..gap_after, everything after is dropped, and the terminal
	 * marker lands last. So this row sits between the two — the point the
	 * entries actually went missing.
	 *
	 * @param \stdClass            $request Request being closed.
	 * @param array<string,mixed> $entry   The terminal entry, for its timestamp.
	 */
	private function store_gap_entry( \stdClass $request, array $entry ): void {
		// Entries is the kept HEAD; the tail is where the request ended.
		$folded = \is_array( $request->tail ?? null );
		/** @var list<array<string,mixed>> $entries */
		$entries = $folded
			? $request->tail
			: ( \is_array( $request->entries ?? null ) ? $request->entries : [] );
		if ( ! $folded && \count( $entries ) >= $this->max_entries_per_request ) {
			return;
		}
		$gap       = Core::int( $request->gap_after ?? 0, 0 );
		$offset    = Core::as_string( $request->last_position ?? '' );
		$lost      = '' !== $offset
			? "discarded entries after #{$gap} at {$offset}"
			: "discarded entries after #{$gap}";
		$entries[] = [ 'n' => $gap + 1, 'ts' => $entry['ts'] ?? 0, 'k' => self::LOST_MARKER_KEY, 'm' => $lost ];
		if ( $folded ) {
			$request->tail = $entries;
			return;
		}
		$request->entries = $entries;
	}

	/**
	 * Emit an error/warning/alert entry via a named target partition.
	 *
	 * The entry's own `url` / `method` are discarded and restamped from the
	 * request envelope: the envelope resolved them once, authoritatively, and a
	 * stale copy on the line would disagree with the completed-request doc.
	 *
	 * @param array<string,mixed> $entry   Decoded entry.
	 * @param string              $rid     Request id — propagated to Message::KEY so
	 *                                     downstream readers can identify the request
	 *                                     without re-parsing the entry payload.
	 * @param \stdClass           $request Active request state supplying authoritative URL context.
	 * @param string              $target  Destination node name ('' = skip).
	 */
	private function emit_entry( array $entry, string $rid, \stdClass $request, string $target ): void {
		if ( '' === $target || null === $this->sink ) {
			return;
		}
		unset( $entry['url'], $entry['method'] );
		$url = self::resolved_request_url( $request );
		if ( '' !== $url ) {
			$entry['url'] = $url;
		}
		$method = \is_string( $request->request_method ?? null ) ? $request->request_method : '';
		if ( '' !== $method ) {
			$entry['method'] = $method;
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $target;
		$message[ Message::KEY ]       = $rid;
		$message[ Message::VALUE ]     = $entry;
		$fitted = Line_Fitter::fit( $message, [ 'm', 'url' ] );
		if ( null === $fitted ) {
			$this->print_less_often( 'WARNING: dropping oversize error entry for ', $rid );
			return;
		}
		$this->guarded( fn () => $this->sink->fill( $fitted ) );
	}

	/**
	 * Push state onto request stack.
	 *
	 * Stack frames are [ state, label ] pairs. Pushing also opens the state's
	 * profile record, so a state that never closes still appears in the profile
	 * with a zero duration instead of vanishing.
	 *
	 * Reaching MAX_STACK_DEPTH marks the request runaway rather than truncating
	 * it: fill() keeps a runaway visible in the in-flight view (Perl gyroscope
	 * parity) but stops storing entries for it, so memory stays bounded.
	 *
	 * @param \stdClass $request Request object.
	 * @param string    $state   State name (e.g. "wp_head hook").
	 * @param string    $label   Stable label for aggregation (the 'l' field).
	 */
	private function push_stack( \stdClass $request, string $state, string $label ): void {
		if ( ! isset( $request->stack ) ) {
			$request->stack    = [ [ Log_Manager::REQUEST_LABEL, '' ] ];
			$request->profiles = [];
		}
		self::normalize_stack( $request );

		// References, not copies: mutate \stdClass arrays in place (avoid COW).
		/** @var list<array{0: string,1: string}> $stack */
		$stack = &$request->stack;
		// Dynamic \stdClass property: per-state profile records keyed by state.
		/** @var array<string,array{entries: array<string,array{0: float,1: int}>,count: int,time: float,ts: float}> $profiles */
		$profiles = &$request->profiles;

		// Stop at stack-depth cap: bound memory for runaways kept visible.
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
		if ( $label && \count( $profile['entries'] ) < self::MAX_PROFILE_ENTRY_LABELS && ! isset( $profile['entries'][ $label ] ) ) {
			$profile['entries'][ $label ] = [ 0, 0 ];
		}
		unset( $profile );

		if ( \count( $stack ) >= self::MAX_STACK_DEPTH ) {
			$request->is_runaway = true;
		}
		// No write-back: $stack / $profiles are references to the properties.
	}

	/**
	 * Pop state from request stack and fold its duration into the profiles.
	 *
	 * Closes rarely misbehave, but when one does the frame it names may sit
	 * below the top — an inner state that never closed. The slow path searches
	 * backward for the matching frame and splices away everything above it,
	 * discarding the unclosed inners rather than letting them shadow the stack
	 * for the rest of the request. A close naming no frame at all is ignored.
	 *
	 * Profile time is EXCLUSIVE: after crediting the closing state, the same
	 * duration is subtracted from its ancestors, so a hook's number is its own
	 * work and not its children's. Callback states (" @N") are the exception in
	 * both directions — they neither trigger the subtraction nor stop it, since
	 * a callback's time is already part of the hook that dispatched it.
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
		self::normalize_stack( $request );

		// References, not copies: mutate \stdClass arrays in place (avoid COW).
		/** @var list<array{0: string,1: string}> $stack */
		$stack = &$request->stack;
		/** @var array<string,array{entries: array<string,array{0: float,1: int}>,count: int,time: float,ts: float}> $profiles */
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

		// Subtract child time from ancestors; callbacks (" @N") don't subtract.
		if ( ! empty( $stack ) && ! self::is_callback_state( $state ) ) {
			for ( $j = \count( $stack ) - 1; $j >= 0; $j-- ) {
				$ancestor_frame = $stack[ $j ];
				$ancestor       = $ancestor_frame[0];
				if ( Log_Manager::REQUEST_LABEL === $ancestor ) {
					break;
				}
				if ( isset( $profiles[ $ancestor ] ) ) {
					$profiles[ $ancestor ]['time'] -= $time;

					$ancestor_label = $ancestor_frame[1];
					if ( $ancestor_label && isset( $profiles[ $ancestor ]['entries'][ $ancestor_label ] ) ) {
						$profiles[ $ancestor ]['entries'][ $ancestor_label ][0] -= $time;
					}
					// Subtract up to first non-callback ancestor, then stop.
					if ( ! self::is_callback_state( $ancestor ) ) {
						break;
					}
				}
			}
		}

		// No write-back: $stack / $profiles are references to the properties.
	}

	/**
	 * Coerce `stack` and `profiles` to arrays so the callers can take
	 * references. A restored envelope carries whatever the checkpoint held.
	 *
	 * @param \stdClass $request Request object, mutated in place.
	 */
	private static function normalize_stack( \stdClass $request ): void {
		if ( ! \is_array( $request->stack ?? null ) ) {
			$request->stack = [];
		}
		if ( ! \is_array( $request->profiles ?? null ) ) {
			$request->profiles = [];
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
	 * Construct the LRU_Cache with the current bucket_size / num_buckets,
	 * wired with the eviction callback. Shared between the ctor (defaults)
	 * and arguments() (post-schema-walk).
	 *
	 * The timed rotation is what makes a stalled request time out; its eviction
	 * callback is `evict_request()`, which writes the request out as timed out.
	 * A bucket count off the default moves the eviction window with it, and
	 * `DEFAULT_EVICTION_WINDOW_SEC` cannot follow — so the declaration that
	 * moved it is where that gets said.
	 *
	 * @return LRU_Cache The constructed cache instance.
	 */
	private function build_cache(): LRU_Cache {
		if ( self::DEFAULT_NUM_BUCKETS !== $this->num_buckets ) {
			$this->print_less_often(
				'WARNING: eviction window is now ',
				(string) ( $this->num_buckets * self::BUCKET_ROTATION_S ),
				's, not the ',
				(string) self::DEFAULT_EVICTION_WINDOW_SEC,
				's other code borrows'
			);
		}
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
	 * `command_node {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI). No generic verb recording.
	 *
	 * `set_inflight_target` reads the Flight sibling, which owns the target as
	 * a base-Node property — its config would otherwise never round-trip, being
	 * hidden from the editor — and teardown empties the slot, leaving nothing
	 * to dump. `set_inflight_delta` is this node's own toggle, so `dump_toggles()`
	 * emits it whether or not the sibling is still there.
	 *
	 * @api Used by substrate.
	 * @return string Round-trippable TSL for this node and its Flight sibling.
	 */
	public function dump_config(): string {
		$out             = parent::dump_config() . $this->dump_setters() . $this->dump_toggles();
		$inflight_target = $this->flight_target();
		if ( '' !== $inflight_target ) {
			$out .= $this->config_line( 'set_inflight_target', $inflight_target );
		}
		return $out;
	}
	/**
	 * Build the state-callback table: keyword → mutator on the request envelope.
	 *
	 * These are the keywords with dedicated meaning. Any other keyword ending in
	 * " (start)" or " (complete)" falls through to the generic stack push/pop in
	 * fill(), which is how arbitrary instrumented hooks are profiled without
	 * being enumerated here.
	 *
	 * @return array<string,callable>
	 */
	private function build_state_callbacks(): array {
		$s = [];

		$s[ Log_Manager::REQUEST_START ] = function ( \stdClass $request, array $entry ): void {
			$request->timestamp   = $entry['ts'] ?? ( Core::$now ?: Core::right_now() );
			$request->stack       = [ [ Log_Manager::REQUEST_LABEL, '' ] ];
			$request->profiles    = [];
			$request->entries     = [];
			// A state name, not the span label: it pairs with 'complete' below.
			$request->state       = 'process';
			$request->gap_after   = 0;
			// Handle operator time-travel gracefully.
			unset( $request->fold, $request->folded, $request->tail, $request->keep, $request->await );
			$request->rule_id     = \is_string( $entry['rule'] ?? null ) ? $entry['rule'] : '';
		};

		$s[ Log_Manager::REQUEST_COMPLETE ] = function ( \stdClass $request, array $entry ): void {
			$request->duration_ms = $entry['duration_ms'] ?? 0;
			$request->status_code = $entry['status_code'] ?? 0;
			$error_status         = $entry['error_status'] ?? '-';
			$allowed = \array_merge( [ '-' ], self::ERROR_STATUSES );
			if ( ! \is_string( $error_status ) || ! \in_array( $error_status, $allowed, true ) ) {
				$error_status = '-';
			}
			// A hole outranks a nominal finish: the trace is partial, say so.
			$request->error_status = Core::int( $request->gap_after ?? 0, 0 ) > 0 ? 'I' : $error_status;
			self::carry_fatal( $request, $entry );
			$request->state        = 'complete';
		};

		// Duration stops at the abort: not a real sample of the URL's cost.
		$s[ Log_Manager::REQUEST_ABORTED ] = function ( \stdClass $request, array $entry ): void {
			$request->duration_ms  = $entry['duration_ms'] ?? 0;
			$request->status_code  = $entry['status_code'] ?? 0;
			$request->error_status = 'A';
			$request->state        = 'complete';
		};

		$s['request'] = function ( \stdClass $request, array $entry ): void {
			$message = $entry['m'] ?? '';
			if ( ! \is_string( $message ) ) {
				return;
			}
			if ( \strlen( $message ) < self::MAX_PAYLOAD_SCAN_LENGTH && \preg_match( '/^(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CLI)\s+(.+)$/', $message, $m ) ) {
				// Strip query: URL rows aggregate per path; '?' marks workers.
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
			// v3 `m` keys are strings — cast so typed env_str() type-checks.
			$env = [];
			foreach ( $raw as $k => $v ) {
				$env[ (string) $k ] = $v;
			}
			// REMOTE_ADDR wins if present; XFF only when it is absent.
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
				// Capture value so worker gets its own ?worker_type URL row.
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
	 * @param array<string,mixed> $env Curated env map from the environment_v3 entry.
	 * @param string               $key Field name.
	 * @return string The field value, or '' when absent or non-string.
	 */
	private static function env_str( array $env, string $key ): string {
		$value = $env[ $key ] ?? '';
		return Core::str( $value );
	}

	/**
	 * Move a fatal's own detail off the terminal entry and onto the record.
	 *
	 * `Log_Manager::write_terminal()` resolves the message, file, line, type and
	 * offending plugin from `error_get_last()` at the one moment PHP still
	 * knows them. `error_status = 'F'` alone says a fatal happened and nothing
	 * about where, which is a whole debugging session of difference.
	 *
	 * @param \stdClass              $request Record under assembly.
	 * @param array<array-key,mixed> $entry   The terminal entry.
	 */
	private static function carry_fatal( \stdClass $request, array $entry ): void {
		if ( ! isset( $entry['fatal_error'] ) ) {
			return;
		}
		$request->fatal_error  = Core::str( $entry['fatal_error'], '' );
		$request->fatal_file   = Core::str( $entry['fatal_file'] ?? '', '' );
		$request->fatal_line   = Core::int( $entry['fatal_line'] ?? 0, 0 );
		$request->fatal_type   = Core::int( $entry['fatal_type'] ?? 0, 0 );
		$request->fatal_plugin = Core::str( $entry['fatal_plugin'] ?? '', '' );
	}

	/**
	 * Handle a single evicted request from LRU bucket rotation.
	 *
	 * An incomplete request is written out with error_status='T' and a duration
	 * measured to eviction time — a trace that stops mid-request is a finding,
	 * not a gap. A request already marked complete, or one that never carried a
	 * URL, is dropped instead.
	 *
	 * Called by the LRU_Cache eviction callback. LRU_Cache stores mixed values,
	 * so the runtime type isn't guaranteed by the signature; the instanceof gate
	 * is the real validation.
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
		// Dynamic \stdClass property is mixed by design; int cast intentional.
		/** @var int|float|string $ts_raw */
		$ts_raw                 = $request->timestamp ?? $now;
		$start_ts               = (int) $ts_raw;
		$request->error_status  = 'T';
		$request->duration_ms   = ( $now - $start_ts ) * 1000;
		$request->status_code   = $request->status_code ?? 0;
		$request->state         = 'complete';
		$url                    = \is_string( $request->url ?? null ) ? $request->url : '';
		$this->print_less_often( 'WARNING: trace timed out on ', $rid, ' (', $url, ') after ', (string) $request->duration_ms, 'ms' );
		$this->emit_request( $request );
	}

	/**
	 * Fold in-flight envelopes until the entries held across all of them are
	 * back under budget.
	 *
	 * Runs off an estimate that only ever over-counts, so this is where the
	 * truth gets recomputed: one pass over the LRU sums what is actually held,
	 * which both corrects the drift and finds the envelope to fold. That pass
	 * is O(in-flight requests) — a few hundred `count()` calls — and it only
	 * happens at the budget, so its cost does not matter.
	 *
	 * The LARGEST envelope folds, not all of them: a short request keeps full
	 * chronology while the long template render pays for the pressure it
	 * created. Folding reclaims rather than merely capping — 18MB of entries
	 * becomes a path map of a few hundred nodes, immediately.
	 */
	private function relieve_pressure(): void {
		$total = 0;
		/** @var array<string,\stdClass> $live */
		$live = [];
		/** @var array<string,int> $sizes */
		$sizes = [];
		foreach ( $this->cache->iterate() as $rid => $request ) {
			if ( ! $request instanceof \stdClass || ! \is_array( $request->entries ?? null ) ) {
				continue;
			}
			$live[ $rid ]  = $request;
			$sizes[ $rid ] = \count( $request->entries );
			$total        += $sizes[ $rid ];
		}

		// @longform Mutate the handles iterate() just gave us. Going back
		// through get() and set() would PROMOTE each envelope into the newest
		// bucket and can rotate the cache, evicting an unrelated request and
		// stamping it timed out — from inside an append, of all places.
		while ( $total > $this->entry_budget && [] !== $sizes ) {
			$rid = (string) \array_search( \max( $sizes ), $sizes, true );
			unset( $sizes[ $rid ] );
			$total -= $this->fold_request( $live[ $rid ] );
		}

		// Back off only when folding could not get us under the budget.
		$this->appended   = $total;
		$this->next_check = $total > $this->entry_budget
			? $total + self::PRESSURE_CHECK_STRIDE
			: $this->entry_budget + 1;
	}

	/**
	 * Replay one envelope's stored entries through the merging stack machine
	 * and drop the raw list. The request keeps logging; every later entry
	 * folds straight into the same path map, so its cost stops tracking
	 * message volume. A folded request cannot un-fold and must not try.
	 *
	 * @param \stdClass $request In-flight envelope, mutated in place.
	 * @return int Entries reclaimed.
	 */
	private function fold_request( \stdClass $request ): int {
		/** @var list<array<string,mixed>> $entries */
		$entries = \is_array( $request->entries ?? null ) ? $request->entries : [];
		$started = $request->timestamp ?? null;
		$fold    = Flame_Fold::start( \is_numeric( $started ) ? (float) $started : null );
		foreach ( $entries as $stored ) {
			Flame_Fold::add( $fold, $stored );
		}
		$request->fold    = $fold;
		$request->entries = \array_slice( $entries, 0, self::FOLD_KEEP_HEAD );
		$request->await   = self::open_in_head( $request->entries );
		$kept             = \array_values( \array_filter(
			\array_slice( $entries, self::FOLD_KEEP_HEAD ),
			static fn ( array $e ): bool => self::is_kept( $e )
		) );
		$request->keep    = $kept;
		$request->tail    = [];
		$request->folded  = true;
		return \count( $entries ) - \count( $request->entries ) - \count( $kept );
	}

	/**
	 * The spans the kept head leaves OPEN, as a base-name set.
	 *
	 * A span opened in the head frames every row the reader sees after it, so
	 * its `(complete)` is structure, not detail — losing it to the rolling tail
	 * costs the record its shape. This is not the guess `is_kept()` refuses to
	 * make: the fold just chose the head, so it knows exactly which frames it
	 * left open, and there are at most `FOLD_KEEP_HEAD` of them.
	 *
	 * The request's own frame is exempt, as it is for the display's severed-span
	 * prune: `process (complete)` is the terminal and has to END the record,
	 * and `keep` is spliced in BEFORE the tail.
	 *
	 * @param list<array<string,mixed>> $head The kept head.
	 * @return array<string,true> Base names still open, as a set.
	 */
	private static function open_in_head( array $head ): array {
		$open = [];
		foreach ( $head as $entry ) {
			$keyword = Core::as_string( $entry['k'] ?? '' );
			if ( \str_ends_with( $keyword, ' (start)' ) ) {
				$open[] = \substr( $keyword, 0, -8 );
				continue;
			}
			if ( ! \str_ends_with( $keyword, ' (complete)' ) ) {
				continue;
			}
			$base = \substr( $keyword, 0, -11 );
			for ( $i = \count( $open ) - 1; $i >= 0; $i-- ) {
				if ( $open[ $i ] === $base ) {
					// Pop everything above it, as the merged tree does.
					\array_splice( $open, $i );
					break;
				}
			}
		}
		unset( $open[ \array_search( Log_Manager::REQUEST_LABEL, $open, true ) ] );
		return \array_fill_keys( $open, true );
	}

	/**
	 * Whether this entry closes a span the kept head left open, consuming the
	 * await so a later same-named span takes the rolling tail like any other.
	 *
	 * @param \stdClass           $request In-flight envelope, mutated.
	 * @param array<string,mixed> $entry   Stored entry.
	 * @return bool True when it closed one, which routes it into `keep`.
	 */
	private static function closes_head_span( \stdClass $request, array $entry ): bool {
		$await = \is_array( $request->await ?? null ) ? $request->await : [];
		if ( [] === $await ) {
			return false;
		}
		$keyword = Core::as_string( $entry['k'] ?? '' );
		if ( ! \str_ends_with( $keyword, ' (complete)' ) ) {
			return false;
		}
		$base = \substr( $keyword, 0, -11 );
		if ( ! isset( $await[ $base ] ) ) {
			return false;
		}
		unset( $await[ $base ] );
		$request->await = $await;
		return true;
	}

	/**
	 * Whether the producer marked this entry to survive a fold.
	 *
	 * A summary line is not repetitive middle: a render's stats flush carries
	 * the request's cache hit rates and nothing else does, and on a real render
	 * it lands 11-15 entries from the end — past any tail worth keeping. Sizing
	 * the tail around it would be a guess about someone else's shutdown
	 * sequence, and both halves of that guess belong to the producer: how many
	 * stats groups were active, and which hooks fired after them. So the
	 * producer marks the line instead, and the fold honours the mark.
	 *
	 * @param array<string,mixed> $entry Stored entry.
	 * @return bool True when the producer marked the line.
	 */
	private static function is_kept( array $entry ): bool {
		return ! empty( $entry['keep'] );
	}

	/**
	 * Append an entry to a folded request's bucket, dropping the oldest past
	 * $cap. A null cap leaves the bucket unbounded, which the keep bucket is on
	 * purpose: a producer marks summaries, and a producer that marks everything
	 * has made its own envelope large. Capping it there would reintroduce the
	 * silent loss the mark exists to prevent.
	 *
	 * @param mixed               $existing Existing bucket, if any.
	 * @param array<string,mixed> $entry    Entry to append.
	 * @param int|null            $cap      Entries kept, or null for unbounded.
	 * @return list<array<string,mixed>>
	 */
	private static function bucket( mixed $existing, array $entry, ?int $cap ): array {
		/** @var list<array<string,mixed>> $bucket */
		$bucket   = \is_array( $existing ) ? $existing : [];
		$bucket[] = $entry;
		if ( null !== $cap && \count( $bucket ) > $cap ) {
			\array_shift( $bucket );
		}
		return $bucket;
	}

	/**
	 * Emit a completed request as a TM_STRUCT message to the main sink.
	 *
	 * KEY = rid so downstream readers / aggregator forwarders can identify
	 * the request without decoding VALUE. Request_Builder still stamps
	 * `rid` into the request struct itself; KEY is the wire-level breadcrumb.
	 *
	 * Also fires the secondary compact-summary emit (no-op when
	 * completed_target is unset) so a topology that wires both the full
	 * doc and the one-line summary gets both with one source call.
	 *
	 * Both callers reach this method — the completion path in fill() and the
	 * timeout path in evict_request().
	 *
	 * @param \stdClass $request Completed request envelope.
	 */
	public function emit_request( \stdClass $request ): void {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::FROM ]      = $this->name;
		// Dynamic \stdClass prop is mixed by design; string cast intentional.
		/** @var int|float|string $rid_raw */
		$rid_raw                   = $request->rid ?? '';
		$message[ Message::KEY ]       = (string) $rid_raw;
		$message[ Message::VALUE ]     = self::record_of( $request );
		$this->guarded( fn () => parent::fill( $message ) );
		$this->emit_compact_summary( $request );
	}

	/**
	 * The wire record for one completed envelope.
	 *
	 * An unfolded request ships its raw `entries`; readers accept that shape
	 * and the folded one alike, because both are produced live.
	 *
	 * A folded one ships the merged tree in `flame`, its kept head and tail
	 * rejoined around an `entries (aggregated)` marker. Each merged node
	 * carries its instance `count`, summed `value` and the offset `t` it first
	 * started at, which is what lets the detail view render them as log rows
	 * on a real clock. The live fold state never goes on the wire — it is
	 * scaffolding, and several times the size of its result.
	 *
	 * @param \stdClass $request Completed request envelope.
	 * @return array<string,mixed> The record.
	 */
	private static function record_of( \stdClass $request ): array {
		/** @var array<string,mixed> $record */
		$record = (array) $request;
		$url    = self::resolved_request_url( $request );
		if ( '' !== $url ) {
			$record['url'] = $url;
		}
		$fold   = $record['fold'] ?? null;
		if ( ! \is_array( $fold ) ) {
			return $record;
		}
		/** @var Fold_State $fold */
		$record['flame']   = Flame_Fold::tree( $fold );
		$record['entries'] = self::head_marker_tail( $record );
		unset( $record['fold'], $record['tail'], $record['await'] );
		// @longform `entries` is NOT cleared here: the fold already emptied it,
		// and what lands in it afterwards is the `entries (lost)` marker, which
		// announces a gap in the very requests whose trace is least worth
		// trusting. Wiping the list would delete exactly that warning.
		return $record;
	}

	/**
	 * Rejoin a folded request's kept ends around a marker for the middle.
	 *
	 * Without the marker a head running straight into a tail reads as a short
	 * request that simply did little — the opposite of what happened.
	 *
	 * @param array<string,mixed> $record Folded record, `fold` still present.
	 * @return list<array<string,mixed>>
	 */
	private static function head_marker_tail( array $record ): array {
		/** @var list<array<string,mixed>> $head */
		$head = \is_array( $record['entries'] ?? null ) ? $record['entries'] : [];
		/** @var list<array<string,mixed>> $tail */
		$tail = \is_array( $record['tail'] ?? null ) ? $record['tail'] : [];
		/** @var Fold_State $fold */
		$fold = $record['fold'];
		// Synthetic rows never folded, so they are not in the fold's count.
		$folded_tail = \count(
			\array_filter( $tail, static fn ( array $e ): bool => self::LOST_MARKER_KEY !== ( $e['k'] ?? '' ) )
		);
		/** @var list<array<string,mixed>> $kept */
		$kept    = \is_array( $record['keep'] ?? null ) ? $record['keep'] : [];
		$dropped = $fold['count'] - \count( $head ) - $folded_tail - \count( $kept );
		if ( $dropped < 1 ) {
			return \array_merge( $head, $kept, $tail );
		}
		$last   = $head[ \count( $head ) - 1 ] ?? null;
		$merged = "{$dropped} entries merged under memory pressure";
		$marker = [ 'n' => Core::int( $last['n'] ?? 0, 0 ) + 1, 'ts' => $last['ts'] ?? 0, 'k' => self::FOLD_MARKER_KEY, 'm' => $merged ];
		return \array_merge( $head, [ $marker ], $kept, $tail );
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
		$summary = $this->build_compact_summary( $request );
		$rid     = Core::as_string( $request->rid ?? '' );
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->completed_target;
		$message[ Message::KEY ]       = $rid;
		$message[ Message::VALUE ]     = $summary;
		$fitted = Line_Fitter::fit( $message, [ 'url', 'user_agent' ] );
		if ( null === $fitted ) {
			$this->print_less_often( 'WARNING: dropping oversize completed summary for ', $rid );
			return;
		}
		$this->guarded( fn () => $this->sink->fill( $fitted ) );
	}

	/**
	 * Build an HTTP-access-log-style compact summary from a completed
	 * request envelope. The schema is a fixed wire contract the request-log
	 * dashboard consumes; `emit_compact_summary()` fits its two free-text
	 * fields, `url` and `user_agent`, to the line budget on the way out.
	 *
	 * @param \stdClass $request Completed request envelope.
	 * @return array<string,mixed> The summary, keyed by the wire-contract fields.
	 */
	public function build_compact_summary( \stdClass $request ): array {
		// Decoded request envelope: string-keyed map, mixed-by-design values.
		/** @var array<string,mixed> $r */
		$r = (array) $request;
		// Preserve native ts/dur type: casting breaks json_encode round-trip.
		/** @var int|float $ts */
		$ts = $r['timestamp'] ?? 0;
		/** @var int|float $dur */
		$dur = $r['duration_ms'] ?? 0;
		// No rid here — it rides Message::KEY on the completed stream.
		return [
			'method'       => Core::as_string( $r['request_method'] ?? 'GET' ),
			'url'          => self::resolved_request_url( $request ),
			'start_time'   => $ts,
			'end_time'     => $ts + ( $dur / 1000 ),
			'duration_ms'  => $dur,
			'status_code'  => $r['status_code'] ?? 0,
			'state'        => 'complete',
			'error_status' => $r['error_status'] ?? '-',
			'remote_addr'  => Core::as_string( $r['remote_addr'] ?? '' ),
			'user_agent'   => Core::as_string( $r['user_agent'] ?? '' ),
		];
	}

	/**
	 * Resolve the URL exactly as completed-request outputs do.
	 *
	 * A worker request gets its worker type appended as a bare query parameter,
	 * so each worker type hashes to its own URL row instead of collapsing into
	 * the endpoint they share. A URL that already carries a query gets it too,
	 * with the right separator: skipping it there puts worker traffic on the
	 * VISITOR's row, and one row carries one `worker` flag — so the default
	 * worker filter drops that URL's visitor traffic along with it.
	 *
	 * @api `Request_Flight_Node` resolves in-flight URLs through this too — the
	 *      completed record and the gyroscope row MUST agree, or a job's
	 *      execution and the request that enqueued it (same `/jobs/{handler}/{id}`
	 *      URI) collapse onto one URL row.
	 * @param \stdClass $request Request envelope.
	 * @return string The resolved URL, or '' when the envelope carries none.
	 */
	public static function resolved_request_url( \stdClass $request ): string {
		$url         = \is_string( $request->url ?? null ) ? $request->url : '';
		$worker_type = \is_string( $request->worker_type ?? null ) ? $request->worker_type : '';
		if ( '' === $worker_type || '' === $url ) {
			return $url;
		}
		return $url . ( \str_contains( $url, '?' ) ? '&' : '?' ) . $worker_type;
	}

	/**
	 * The label of the innermost open span, for the in-flight gyroscope row.
	 *
	 * @param array<string,mixed> $request Request envelope as an array.
	 * @return string The stack top's label, or '' when it carries none.
	 */
	public static function extract_what( array $request ): string {
		return self::extract_stack_top_slot( $request, 1, 'what', '' );
	}

	/**
	 * The name of the innermost open span, for the in-flight gyroscope row.
	 * A request inside nothing else reads as 'process', its own frame.
	 *
	 * @param array<string,mixed> $request Request envelope as an array.
	 * @return string The stack top's state name, or 'process'.
	 */
	public static function extract_state( array $request ): string {
		return self::extract_stack_top_slot( $request, 0, 'state', 'process' );
	}

	/**
	 * Read the stack-top frame slot for an in-flight request — derive both
	 * `state` (slot 0) and `what` (slot 1) from the top of the request's
	 * hook stack, defaulting to `[ 'process', '' ]` when the stack is empty.
	 *
	 * The stack answers first. An envelope carrying none — restored, or built
	 * by hand — falls back to the named field, and then to the default.
	 *
	 * @param array<string,mixed> $request        Request envelope as an array.
	 * @param int                 $slot           Frame slot (0 = state, 1 = what).
	 * @param string              $fallback_field Explicit field name to fall back on.
	 * @param string              $default        Static default if neither source has a value.
	 * @return string The slot's value, the fallback field, or the default.
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
	 * Save state for persistence.
	 *
	 * Persists the full request cache (including entries and profiles)
	 * so in-flight requests retain trace data across worker restarts.
	 * Orphan eviction is handled by LRU bucket rotation.
	 *
	 * @api Used by substrate.
	 * @return array<string,mixed> State to persist.
	 */
	public function save_state(): array {
		$fn = static fn ( $val ) => $val instanceof \stdClass ? (array) $val : $val;
		return [ 'request_cache' => self::map_buckets( $this->cache->get_state(), $fn ) ];
	}

	/**
	 * Restore state from save_state(). Rehydrates arrays back into stdClass.
	 *
	 * @api Used by substrate.
	 * @param array<string,mixed> $saved Saved state from save_state().
	 */
	public function restore_state( array $saved ): void {
		$cache_state = $saved['request_cache'] ?? null;
		if ( ! \is_array( $cache_state ) ) {
			return;
		}
		// Persisted cache snapshot: string-keyed by design (LRU_Cache state).
		/** @var array<string,mixed> $cache_state */
		$fn = static fn ( $val ) => \is_array( $val ) ? (object) $val : $val;
		$this->cache->restore_state( self::map_buckets( $cache_state, $fn ) );
	}

	/**
	 * Apply $fn to every request held in a cache-state bucket. Serialization
	 * converts the envelopes one way and rehydration back; only $fn differs.
	 *
	 * @param array<string,mixed> $state Cache state from/for LRU_Cache.
	 * @param callable            $fn    Per-request converter.
	 * @return array<string,mixed> The state with converted requests.
	 */
	private static function map_buckets( array $state, callable $fn ): array {
		if ( ! \is_array( $state['buckets'] ?? null ) ) {
			return $state;
		}
		foreach ( $state['buckets'] as &$bucket ) {
			if ( \is_array( $bucket ) ) {
				$bucket = \array_map( $fn, $bucket );
			}
		}
		unset( $bucket );
		return $state;
	}

	/**
	 * The conditional routes this node writes past its primary target, so the
	 * console's TARGET column reflects the full fan-out. Without them those
	 * partitions render disconnected (no inbound edge) despite being written to.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		return [ $this->errors_target, $this->alerts_target, $this->completed_target, $this->flight_target() ];
	}

	/**
	 * The Flight sibling's target, or `''` when the sibling or its target is
	 * absent — read by the dump and by the fan-out getter, both of which have
	 * to survive a torn-down sibling.
	 */
	private function flight_target(): string {
		return Core::str( $this->flight?->target() );
	}

	/**
	 * Parse one fixed-width index line written by format_index_entry().
	 *
	 * Columns: rid(32) url_hash(12) timestamp(10) duration_ms(8) status_code(3)
	 * segment(6) offset(10) length(8) peak_mb(6) method(1) error_status(1) —
	 * one width, the one `format_index_entry()` writes. A shorter line is a
	 * truncated or half-written record, and is refused.
	 *
	 * @param string $line Index line.
	 * @return array<string,mixed>|null Parsed entry, or null when malformed.
	 */
	public static function parse_request_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( \strlen( $line ) < self::INDEX_LINE_BYTES ) {
			return null;
		}
		$entry = [
			'rid'         => \trim( \substr( $line, 0, 32 ) ),
			'url_hash'    => \trim( \substr( $line, 32, 12 ) ),
			'timestamp'   => (int) \substr( $line, 44, 10 ),
			'duration_ms' => (int) \substr( $line, 54, 8 ),
			'status_code' => (int) \substr( $line, 62, 3 ),
			'segment'     => (int) \substr( $line, 65, 6 ),
			'offset'      => (int) \substr( $line, 71, 10 ),
			'length'      => (int) \substr( $line, 81, 8 ),
			'peak_mb'     => (int) \substr( $line, 89, 6 ),
			'method'      => self::method_names()[ \substr( $line, 95, 1 ) ] ?? \substr( $line, 95, 1 ),
		];
		$status = \substr( $line, 96, 1 );
		if ( \in_array( $status, self::ERROR_STATUSES, true ) ) {
			$entry['error_status'] = $status;
		}
		return $entry;
	}

	/**
	 * `METHOD_CODES` inverted, memoized — the reader runs per index line, and
	 * a second hand-written table is a lockstep hazard the round-trip test
	 * would only catch after someone edited one side.
	 *
	 * @return array<string,string>
	 */
	private static function method_names(): array {
		/** @var array<string,string> $names */
		static $names = [];
		if ( [] === $names ) {
			$names = \array_flip( self::METHOD_CODES );
		}
		return $names;
	}

	/**
	 * Where a raw-comparable field sits on the line this class writes.
	 *
	 * The scan that reads these lines compares ONE column before parsing, and
	 * the offsets it slices with have to come from the writer that laid the
	 * line out. A field with no such column answers `[]`, and its caller falls
	 * back to the parse.
	 *
	 * @param string $field Index-entry field name.
	 * @return array{0:int,1:int}|array{} Offset and length, or [] when none.
	 */
	public static function index_column( string $field ): array {
		return self::INDEX_COLUMNS[ $field ] ?? [];
	}

	/**
	 * Where a line's COMPLETION is read from, for a walk that bounds itself by
	 * time rather than by a match: the start column, then the duration column
	 * in milliseconds. A format carrying no time answers `[]`, and its caller
	 * keeps the entry budget as its only bound.
	 *
	 * Completion, not start: this class appends a line when the request ENDS,
	 * so start is the one time on the line a walk cannot order itself by — a
	 * request that ran for hours carries one from outside any recent window.
	 *
	 * @return array{0:array{0:int,1:int},1:array{0:int,1:int}} Start, then duration.
	 */
	public static function index_completion_columns(): array {
		return self::COMPLETION_COLUMNS;
	}

	/**
	 * Drop the slot the base cascade just tore down, so nothing hands back a
	 * Flight node whose name, sink and patron are cleared.
	 *
	 * @api Reached through the substrate's teardown cascade.
	 */
	public function remove_node(): void {
		parent::remove_node();
		$this->flight = null;
	}

	/**
	 * The Flight sibling, narrowed to non-null for the two `set_inflight_*`
	 * verbs, which cannot honour a setting with no sibling to hold it.
	 * Throwing IS how a verb refuses here — the substrate wraps a handler
	 * throw as `TM_COMMAND|TM_ERROR`, where a refusal string would report
	 * success for a command that applied nothing.
	 *
	 * A torn-down builder never reaches it: teardown unregisters the `:config`
	 * interpreter and drops its patron link, so the verbs refuse one step
	 * earlier and identically to every other `patron()` handler in the tree.
	 * Introspection runs on whatever the registry hands a sweep, so
	 * `dump_config()` reads the nullable `flight_target()` instead.
	 *
	 * @return Request_Flight_Node The hidden in-flight snapshot sibling.
	 * @throws \RuntimeException When the constructor did not build the sibling.
	 */
	public function flight(): Request_Flight_Node {
		if ( null === $this->flight ) {
			throw new \RuntimeException( 'flight sibling not constructed' );
		}
		return $this->flight;
	}

	/**
	 * Drop every in-flight request, returning how many went.
	 *
	 * Recovery, not eviction: the entries are DISCARDED, not emitted as timed
	 * out. A cache that needs this is holding requests that will never complete,
	 * potentially thousands of them carrying up to max_entries_per_request
	 * entries each — answering a wedged fleet with that write storm is worse
	 * than losing docs for requests already known to be dead. Ordinary ageing
	 * still runs through eviction, which does emit.
	 *
	 * @return int Requests dropped.
	 */
	public function purge_cache(): int {
		$dropped = \iterator_count( $this->cache->iterate() );
		$this->cache->flush();
		return $dropped;
	}

	/**
	 * Format index entry callback for Partition::with_index().
	 *
	 * Registered as the `request-index` formatter; `parse_request_index()` is
	 * the reader for the lines this writes. The layout is fixed-width and
	 * append-only, `INDEX_LINE_BYTES` wide, so the reader slices each field by
	 * constant offset and refuses anything shorter. Change a width and every
	 * existing `.idx` on disk decodes as garbage.
	 *
	 * Two cases skip indexing, both by the substrate's null-or-'' contract: a
	 * record with no URL, and a position too large for its column — an offset,
	 * length, or segment that would overflow its width is dropped rather than
	 * written truncated, which would decode as a valid but wrong seek.
	 *
	 * @param array<int,mixed>  $message  The unpacked message array; VALUE is index 6.
	 * @param array<string,int> $position Position array.
	 * @return string|null Index entry or null.
	 */
	public static function format_index_entry( array $message, array $position ): ?string {
		$value = $message[ Message::VALUE ] ?? null;
		if ( ! \is_array( $value ) || empty( $value['url'] ) ) {
			return null;
		}
		// Decoded request envelope: string-keyed map, mixed-by-design values.
		/** @var array<string,mixed> $value */
		$request = (object) $value;

		$rid          = Core::str( $request->rid ?? '' );
		$url          = Core::str( $request->url ?? '' );
		$url_hash     = Log_Manager::url_hash( $url );
		$timestamp    = Core::as_int( $request->timestamp ?? \time() );
		$duration_ms  = Core::as_int( $request->duration_ms ?? 0 );
		$status_code  = Core::as_int( $request->status_code ?? 0 );
		$peak_mb      = Core::as_float( $request->peak_mb ?? 0 );
		$error_status = Core::str( $request->error_status ?? '-', '-' );
		$segment      = $position['segment'];
		$offset       = $position['offset'];
		$length       = $position['length'];

		if ( $offset > 9999999999 || $length > 99999999 || $segment > 999999 ) {
			return '';
		}

		// peak_mb: 6 chars, integer MB zero-padded (max 999999 MB).
		$peak_mb_int = \min( (int) \round( $peak_mb ), 999999 );

		$rm_raw = $request->request_method ?? 'GET';
		$method = self::METHOD_CODES[ Core::str( $rm_raw, 'GET' ) ] ?? 'G';

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

	/** @param string $target Node name, or '' to stop emitting summaries. */
	public function set_completed_target( string $target ): void {
		$this->completed_target = $target;
	}

	/** @param string $target Node name, or '' to stop forwarding errors. */
	public function set_errors_target( string $target ): void {
		$this->errors_target = $target;
	}

	/** @param string $target Journal node name, or '' to stop forwarding alerts. */
	public function set_alerts_target( string $target ): void {
		$this->alerts_target = $target;
	}

	/**
	 * Delta mode for the hidden Flight sibling. The setting lives HERE, not on
	 * the sibling: Flight is the node that reads it, but this is the node whose
	 * verb sets it and whose `dump_config()` has to replay it, and a sibling is
	 * derived state a teardown takes with it.
	 *
	 * This IS the `set_inflight_delta` verb: `Schema_Reflection` synthesizes a
	 * handler that calls it dynamically, so nothing references it statically.
	 *
	 * @param bool $on True to emit only rows whose activity advanced since the last tick.
	 */
	public function set_inflight_delta( bool $on ): void {
		$this->inflight_delta = $on;
	}

	/** Delta mode; read live by `Request_Flight_Node::fire()`, which holds no copy. */
	public function inflight_delta(): bool {
		return $this->inflight_delta;
	}

	/**
	 * Declared arguments, `{name}:config` verbs, and TM_REQUEST verbs.
	 *
	 * The `set_inflight_*` verbs configure in-flight snapshotting, whose work
	 * the hidden Flight sibling does — Flight is hidden from the topology
	 * editor, so the knobs have to surface on this node's interpreter to be
	 * reachable at all.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed> Schema consumed by `Command_Interpreter_Node`.
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Assembles per-request firehose lines into completed-request docs; emits errors to a named partition.',
			'arguments'        => [
				[ 'name' => 'bucket_size', 'type' => 'int', 'default' => self::DEFAULT_BUCKET_SIZE, 'description' => 'Max in-flight requests held per LRU bucket before rotating to a fresh one (default 100).' ],
				[ 'name' => 'num_buckets', 'type' => 'int', 'default' => self::DEFAULT_NUM_BUCKETS, 'description' => 'Number of rotating LRU buckets for in-flight requests; the oldest is evicted when full (capacity ~ bucket_size x num_buckets, default 3).' ],
				[ 'name' => 'entry_budget', 'type' => 'int', 'default' => self::DEFAULT_ENTRY_BUDGET, 'description' => 'Log entries held across ALL in-flight requests before the largest is folded to aggregated paths (default 50000, ~18MB).' ],
				[ 'name' => 'max_entries_per_request', 'type' => 'int', 'default' => self::DEFAULT_MAX_ENTRIES_PER_REQUEST, 'description' => 'Entries ONE request holds before it folds itself — a faster trigger than the pool budget for a single runaway (default 20000).' ],
			],
			'commands'       => [
				[
					'name'        => 'set_errors_target',
					'description' => 'Forward error/warning keywords to a named partition.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'errors_target',
				],
				[
					'name'        => 'set_alerts_target',
					'description' => 'Forward `alert` keywords to a named partition (the fleet-alert journal).',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'alerts_target',
				],
				[
					'name'        => 'set_completed_target',
					'description' => 'Emit a compact one-line summary of each completed request to a named partition (in addition to the primary full-doc emit).',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'completed_target',
				],
				[
					'name'        => 'set_inflight_target',
					'description' => 'Emit periodic in-flight request snapshots (on the Router tick) to a named partition (typically the gyroscope) via the hidden Flight sibling. Setting a target enables snapshots; an empty arg clears it and stops them.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = \trim( Core::as_string( $args[0] ?? '' ) );
						// target() arms Router-TIMER hitchhike; empty stops it.
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->flight()->target( $arg );
						return 'ok';
					},
				],
				[
					'name'        => 'set_inflight_delta',
					'description' => 'Emit only in-flight rows whose activity advanced since the last snapshot tick. Default off re-emits every row each tick (a fresh subscriber sees the whole cache in one tick); a bare/empty, `0`, `false` or `off` arg disables.',
					'args'        => [
						[ 'name' => 'on', 'type' => 'bool', 'required' => false ],
					],
					// Declarative: the substrate synthesizes handler and dump.
					'toggle'      => 'inflight_delta',
				],
				[
					'name'        => 'purge',
					'action'      => true,
					'description' => 'Drop every in-flight request. Operator recovery for a wedged cache — the requests are discarded, NOT emitted as timed out; ordinary ageing still emits.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $patron */
						$patron = $interpreter->patron();
						return \sprintf( 'purged %d in-flight requests', $patron->purge_cache() );
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
