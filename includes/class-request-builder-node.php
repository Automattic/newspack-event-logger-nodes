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
 * line writer and its reader. They are registered as the `request-index`
 * formatter and used by the `performance` CI's lookup verbs.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\LRU_Cache;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
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

	/**
	 * Every non-nominal terminal marker: fatal, timed out, aborted. The index
	 * writer, its reader and the `process (complete)` validator all read this —
	 * three parallel lists is how `A` shipped writable but unreadable.
	 */
	public const ERROR_STATUSES = [ 'F', 'T', 'A', 'I' ];

	/** Markers that close a request. They land even when the sequence broke. */
	private const TERMINAL_KEYWORDS = [ 'process (complete)' => true, 'process (aborted)' => true ];

	/** Default in-flight requests held per LRU bucket. */
	public const DEFAULT_BUCKET_SIZE = 100;

	/** Default number of rotating LRU buckets. */
	public const DEFAULT_NUM_BUCKETS = 3;

	/**
	 * Bucket rotation interval in seconds.
	 * 3 buckets x 200s = 600s (10 min) before oldest bucket is evicted.
	 */
	private const BUCKET_ROTATION_S = 200;

	/** Longest keyword the intern table accepts; longer ones pass through. */
	private const INTERN_MAX_KEY_LENGTH  = 256;

	/** Cap on intern-table entries; past it, keywords pass through uninterned. */
	private const INTERN_TABLE_LIMIT = 50000;

	/**
	 * Default maximum entries stored per REQUEST before it folds on its own.
	 * A faster trigger than the pool budget for the single-runaway case: it
	 * stops one pathological request dominating the envelope pool without
	 * waiting for the next pressure check.
	 */
	public const DEFAULT_MAX_ENTRIES_PER_REQUEST = 20000;

	/**
	 * Default ceiling on entries stored across ALL in-flight requests.
	 *
	 * The per-request cap constrained the wrong quantity: at 50,000 entries an
	 * envelope measures ~18MB, which is fine once and fatal twenty times over
	 * — and twenty concurrent is the workload a long template render creates.
	 * Crossing this folds envelopes (see `relieve_pressure()`) rather than
	 * letting the worker blow past its memory limit unbounded.
	 */
	public const DEFAULT_ENTRY_BUDGET = 50000;

	/**
	 * Appends between pressure checks once folding can no longer help.
	 *
	 * `$appended` only ever climbs, so a check that finds the true total still
	 * over budget would otherwise re-scan on every single entry.
	 */
	private const PRESSURE_CHECK_STRIDE = 1000;

	/**
	 * Max stored message length per entry. Truncate long values (filter args,
	 * callback lists) to keep in-flight request memory bounded.
	 */
	private const MAX_ENTRY_MESSAGE_LENGTH = 1024;

	/**
	 * Max raw payload length for URL/process-start extraction.
	 */
	private const MAX_PAYLOAD_SCAN_LENGTH = 8192;

	/** Max distinct labels tracked per profiled state (bounds runaway memory). */
	private const MAX_PROFILE_ENTRY_LABELS = 1000;

	/** Maximum stack depth before request is considered runaway and evicted. */
	private const MAX_STACK_DEPTH = 50;

	/** @var LRU_Cache In-flight requests, keyed by rid. */
	public $cache;

	/** @var Request_Flight_Node|null Hidden sibling — periodic in-flight snapshots. */
	public ?Request_Flight_Node $flight = null;

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
	 * Raw entries a folded request keeps from each end. The head is how a
	 * request identifies itself (process start, request line, environment) and
	 * the tail is how it ends (stats flush, memory, process complete); both are
	 * bounded, and it is the repetitive middle that costs memory.
	 */
	private const FOLD_KEEP_HEAD = 10;
	private const FOLD_KEEP_TAIL = 10;

	/** @var int `$appended` value that triggers the next pressure check. */
	private int $next_check = self::DEFAULT_ENTRY_BUDGET + 1;

	/** @var string Named target for compact-summary completed lines (empty = disabled). */
	private string $completed_target = '';

	/** @var string Named target for error/warning lines (empty = disabled). */
	private $errors_target = '';

	/** @var string Late-bound node NAME `alert` entries also forward to ('' = off). */
	private $alerts_target = '';

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
		// Schema-default cache so the no-arg ctor works; arguments() rebuilds.
		$this->cache = $this->build_cache();
		$this->state_callbacks = $this->build_state_callbacks();

		// Hidden Flight sibling; name()/sink() overrides propagate its wiring.
		$this->flight = new Request_Flight_Node();
		$this->flight->patron( $this );

		// Rule 2c: default Flight's sink to _command_interpreter when in scope.
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null !== $ci && null === $this->flight->sink() ) {
			$this->flight->sink( $ci );
		}

		parent::__construct();
		// Wire :config interpreter last: handlers read patron() lazily (safe).
		$this->auto_wire_interpreter();
	}

	/**
	 * Store and parse the positional token array via parse_schema_args()
	 * (bucket_size / num_buckets), then rebuild the LRU_Cache with the new
	 * dimensions (here — not the ctor — because it depends on the positional args).
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
	 * plausible-looking lie. A nested render (gyrobase via `proc_open`) restarts
	 * numbering at 1 under the same rid, which `seq_stack` accommodates by
	 * saving the parent's expected value across the subprocess.
	 *
	 * Completion is detected by the `process (complete)` callback setting
	 * `state`; this method then emits and drops the envelope immediately, to get
	 * the state back out of RAM rather than wait for eviction.
	 *
	 * @param array<int,mixed> $message Reference; not mutated.
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
			// Only 'process (start)' opens a request; orphan lines drop.
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
		if ( 'process (start)' === $keyword && $expected > 1 ) {
			$this->print_less_often( 'WARNING: multiple requests with ID: ', $rid );
			$expected = 1;
			$request->expected_n = 1;
		}
		if ( $seq_n < $expected ) {
			$this->print_less_often( 'INFO: duplicate message: expected #', (string) $expected, ', got #', (string) $seq_n, ' on ', $rid );
			return;
		}
		if ( $seq_n > $expected ) {
			$this->print_less_often( 'WARNING: missing message: expected #', (string) $expected, ', got #', (string) $seq_n, ' on ', $rid );
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

			// Truncate 'm' to bound memory (arrays already PIPE_BUF-bounded).
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

			// Folded stays folded: into the path map, never back onto the list.
			$fold = $request->fold ?? null;
			if ( \is_array( $fold ) ) {
				/** @var Fold_State $fold */
				Flame_Fold::add( $fold, $stored );
				$request->fold = $fold;
				$request->tail = self::ring( $request->tail ?? null, $stored );
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
	 * request times out (error_status='T') and is emitted to both requests.log and
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
				// Cache holds stdClass; an is_array test read as "no stall".
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
		$entries[] = [
			'n'  => $gap + 1,
			'ts' => $entry['ts'] ?? 0,
			'k'  => 'entries (lost)',
			'm'  => '' !== $offset
				? "discarded entries after #{$gap} at {$offset}"
				: "discarded entries after #{$gap}",
		];
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
	 * @param string               $rid     Request id — propagated to Message::KEY so
	 *                                      downstream readers can identify the request
	 *                                      without re-parsing the entry payload.
	 * @param \stdClass            $request Active request state supplying authoritative URL context.
	 * @param string               $target  Destination node name ('' = skip).
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
		$message[ Message::TIMESTAMP ] = Core::$now;
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
			$request->stack    = [ [ 'process', '' ] ];
			$request->profiles = [];
		}
		if ( ! \is_array( $request->stack ) ) {
			$request->stack = [];
		}
		if ( ! \is_array( $request->profiles ) ) {
			$request->profiles = [];
		}

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
		if ( ! \is_array( $request->stack ) ) {
			$request->stack = [];
		}
		if ( ! \is_array( $request->profiles ) ) {
			$request->profiles = [];
		}

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
				if ( 'process' === $ancestor ) {
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
	 * The two `set_inflight_*` lines read from the Flight sibling, whose own
	 * config would otherwise never round-trip — it is hidden from the editor.
	 *
	 * @api Used by substrate.
	 * @return string Round-trippable TSL for this node and its Flight sibling.
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( '' !== $this->errors_target ) {
			$out .= "cmd {$this->name}:config set_errors_target {$this->errors_target}\n";
		}
		if ( '' !== $this->alerts_target ) {
			$out .= "cmd {$this->name}:config set_alerts_target {$this->alerts_target}\n";
		}
		if ( '' !== $this->completed_target ) {
			$out .= "cmd {$this->name}:config set_completed_target {$this->completed_target}\n";
		}
		$inflight_target = $this->flight()->target();
		if ( \is_string( $inflight_target ) && '' !== $inflight_target ) {
			$out .= "cmd {$this->name}:config set_inflight_target {$inflight_target}\n";
		}
		if ( $this->flight()->delta() ) {
			$out .= "cmd {$this->name}:config set_inflight_delta 1\n";
		}
		return $out;
	}

	/**
	 * The Flight sibling, narrowed to non-null for callers that require it.
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

		$s['process (start)'] = function ( \stdClass $request, array $entry ): void {
			$payload = $entry['m'] ?? '';
			if ( \is_array( $payload ) ) {
				$payload = $payload['m'] ?? '';
			}
			if ( \is_string( $payload ) && strlen( $payload ) < self::MAX_ENTRY_MESSAGE_LENGTH && \preg_match( '/^(\d+) on (\S+)/', $payload, $m ) ) {
				$request->process_id = $m[1];
				$request->host       = $m[2];
			}
			$request->timestamp   = $entry['ts'] ?? ( Core::$now ?: Core::right_now() );
			$request->stack       = [ [ 'process', '' ] ];
			$request->profiles    = [];
			$request->entries     = [];
			$request->state       = 'process';
			$request->initialized = true;
			$request->gap_after   = 0;
			// Handle operator time-travel gracefully.
			unset( $request->fold, $request->folded, $request->tail );
			$request->rule_id     = \is_string( $entry['rule'] ?? null ) ? $entry['rule'] : '';
		};

		$s['process (complete)'] = function ( \stdClass $request, array $entry ): void {
			$request->duration_ms = $entry['duration_ms'] ?? 0;
			$request->status_code = $entry['status_code'] ?? 0;
			$error_status         = $entry['error_status'] ?? '-';
			$allowed = \array_merge( [ '-' ], self::ERROR_STATUSES );
			if ( ! \is_string( $error_status ) || ! \in_array( $error_status, $allowed, true ) ) {
				$error_status = '-';
			}
			// A hole outranks a nominal finish: the trace is partial, say so.
			$request->error_status = Core::int( $request->gap_after ?? 0, 0 ) > 0 ? 'I' : $error_status;
			$request->state        = 'complete';
		};

		// Duration stops at the abort: not a real sample of the URL's cost.
		$s['process (aborted)'] = function ( \stdClass $request, array $entry ): void {
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
		$request->tail    = [];
		$request->folded  = true;
		return \count( $entries ) - \count( $request->entries );
	}

	/**
	 * Push onto a bounded ring, dropping the oldest past FOLD_KEEP_TAIL.
	 *
	 * @param mixed                $ring  Existing ring, if any.
	 * @param array<string,mixed> $entry Entry to append.
	 * @return list<array<string,mixed>>
	 */
	private static function ring( mixed $ring, array $entry ): array {
		/** @var list<array<string,mixed>> $tail */
		$tail   = \is_array( $ring ) ? $ring : [];
		$tail[] = $entry;
		if ( \count( $tail ) > self::FOLD_KEEP_TAIL ) {
			\array_shift( $tail );
		}
		return $tail;
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
		$url = self::resolved_request_url( $request );
		if ( '' !== $url ) {
			$request->url = $url;
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
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
	 * An unfolded request ships its raw `entries` and nothing changes — which
	 * is also what every record already written to `requests.p*` looks like,
	 * so accepting both shapes downstream is what makes this migration free.
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
		$fold   = $record['fold'] ?? null;
		if ( ! \is_array( $fold ) ) {
			return $record;
		}
		/** @var Fold_State $fold */
		$record['flame']   = Flame_Fold::tree( $fold );
		$record['entries'] = self::head_marker_tail( $record );
		unset( $record['fold'], $record['tail'] );
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
			\array_filter( $tail, static fn ( array $e ): bool => 'entries (lost)' !== ( $e['k'] ?? '' ) )
		);
		$dropped = $fold['count'] - \count( $head ) - $folded_tail;
		if ( $dropped < 1 ) {
			return \array_merge( $head, $tail );
		}
		$last = $head[ \count( $head ) - 1 ] ?? null;
		return \array_merge(
			$head,
			[
				[
					'n'  => Core::int( $last['n'] ?? 0, 0 ) + 1,
					'ts' => $last['ts'] ?? 0,
					'k'  => 'entries (aggregated)',
					'm'  => "{$dropped} entries merged into the flame graph under memory pressure",
				],
			],
			$tail
		);
	}

	/**
	 * Resolve the URL exactly as completed-request outputs do.
	 *
	 * A worker request gets its worker type appended as a bare query string, so
	 * each worker type hashes to its own URL row instead of collapsing into the
	 * endpoint they share.
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
		if ( '' !== $worker_type && '' !== $url && ! \str_contains( $url, '?' ) ) {
			return $url . '?' . $worker_type;
		}
		return $url;
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
		$message[ Message::TIMESTAMP ] = Core::$now;
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
	 * dashboard consumes. URL clipped to 2000 chars + "..." suffix; UA to 500.
	 *
	 * Those clips are for display and are counted in characters, which only
	 * approximates bytes; `Line_Fitter` does the byte-exact fit afterward.
	 *
	 * @param \stdClass $request Completed request envelope.
	 * @return array<string,mixed> The summary, keyed by the wire-contract fields.
	 */
	public function build_compact_summary( \stdClass $request ): array {
		// Decoded request envelope: string-keyed map, mixed-by-design values.
		/** @var array<string,mixed> $r */
		$r = (array) $request;
		// Mixed-by-design (array)/stdClass reads; string casts intentional.
		/** @var int|float|string|bool|null $url_raw */
		$url_raw = $r['url'] ?? '';
		$url     = (string) $url_raw;
		/** @var int|float|string|bool|null $ua_raw */
		$ua_raw = $r['user_agent'] ?? '';
		$ua     = (string) $ua_raw;
		/** @var int|float|string|bool|null $method_raw */
		$method_raw = $r['request_method'] ?? 'GET';
		/** @var int|float|string|bool|null $remote_addr_raw */
		$remote_addr_raw = $r['remote_addr'] ?? '';
		// Preserve native ts/dur type: casting breaks json_encode round-trip.
		/** @var int|float $ts */
		$ts = $r['timestamp'] ?? 0;
		/** @var int|float $dur */
		$dur = $r['duration_ms'] ?? 0;
		// No rid here — it rides Message::KEY on the completed stream.
		return [
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

	/**
	 * The label of whatever the request is doing right now (stack-top slot 1).
	 *
	 * @param array<string,mixed> $request Request envelope as an array.
	 * @return string The label, or '' when the stack carries none.
	 */
	public static function extract_what( array $request ): string {
		return self::extract_stack_top_slot( $request, 1, 'what', '' );
	}

	/**
	 * The state the request is in right now (stack-top slot 0).
	 *
	 * @param array<string,mixed> $request Request envelope as an array.
	 * @return string The state name, defaulting to 'process'.
	 */
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
	 * append-only — 97 columns as of v4 — so the reader slices each field by
	 * constant offset and an older, shorter index still parses. Change a width
	 * and every existing `.idx` on disk decodes as garbage.
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

		// Dynamic \stdClass reads mixed by design; casts intentional.
		$rid_raw = $request->rid ?? '';
		$rid     = Core::str( $rid_raw );
		$url_raw = $request->url ?? '';
		$url     = Core::str( $url_raw );
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
		$error_status = Core::str( $es_raw, '-' );

		if ( $offset > 9999999999 || $length > 99999999 || $segment > 999999 ) {
			return '';
		}

		// peak_mb: 6 chars, integer MB zero-padded (max 999999 MB).
		$peak_mb_int = \min( (int) \round( $peak_mb ), 999999 );

		// method: 1 char code for HTTP method.
		/** @var array<string,string> $method_codes */
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
		$method = $method_codes[ Core::str( $rm_raw, 'GET' ) ] ?? 'G';

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
	 * @throws \RuntimeException When `{name}:flight` is already registered.
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
	 * Request_Builder also reaches the hidden Flight sibling. Without this,
	 * Flight's $this->sink stays null and its in-flight emits drop on the
	 * floor.
	 *
	 * @api Used by substrate.
	 * @param Node|null $node New sink node or null to get current sink.
	 * @return Node|null The current sink.
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
	 * Set the named target `alert` entries also forward to (the fleet journal).
	 *
	 * @param string $target Target node name for alert forwarding.
	 */
	public function set_alerts_target( string $target ): void {
		$this->alerts_target = $target;
	}

	/**
	 * Expose every named destination this node actually writes to so the
	 * console's TARGET column reflects the full fan-out: the primary target plus
	 * the conditional errors_target / alerts_target / completed_target and the
	 * Flight sibling's target. Without this override those partitions render
	 * disconnected on the topology console (no inbound edge) despite being
	 * written to.
	 *
	 * The getter alone widens; the setter still writes only the primary target,
	 * so a round-trip through set-then-get does not fold the extras into it.
	 *
	 * @api Used by substrate.
	 * @param array<int,string>|string|null $value New primary target or null to get current target.
	 * @return array<int,string>|string The primary target, or every destination when extras exist.
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
		if ( '' !== $this->alerts_target ) {
			$extras[] = $this->alerts_target;
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
	 * @return array<string,mixed> State to persist.
	 */
	public function save_state(): array {
		// Convert objects to arrays for serialization.
		$state = $this->cache->get_state();
		if ( isset( $state['buckets'] ) && \is_array( $state['buckets'] ) ) {
			foreach ( $state['buckets'] as &$bucket ) {
				if ( \is_array( $bucket ) ) {
					foreach ( $bucket as &$val ) {
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
	 * @param array<string,mixed> $saved Saved state from save_state().
	 */
	public function restore_state( array $saved ): void {
		if ( ! isset( $saved['request_cache'] ) ) {
			return;
		}
		$cache_state = $saved['request_cache'];
		if ( ! \is_array( $cache_state ) ) {
			return;
		}
		// Persisted cache snapshot: string-keyed by design (LRU_Cache state).
		/** @var array<string,mixed> $cache_state */
		if ( isset( $cache_state['buckets'] ) && \is_array( $cache_state['buckets'] ) ) {
			foreach ( $cache_state['buckets'] as &$bucket ) {
				if ( \is_array( $bucket ) ) {
					foreach ( $bucket as &$val ) {
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
	 * Parse one fixed-width index line written by format_index_entry().
	 *
	 * Columns: rid(32) url_hash(12) timestamp(10) duration_ms(8) status_code(3)
	 * segment(6) offset(10) length(8) — 89 bytes, the v1 line and the minimum
	 * this accepts. Later versions only appended: peak_mb(6) at 89 (v2), method
	 * code(1) at 95 (v3), error_status(1) at 96 (v4). Each tail field is read
	 * only when the line is long enough to hold it, so an index written by an
	 * older version parses without migration.
	 *
	 * @param string $line Index line.
	 * @return array<string,mixed>|null Parsed entry, or null when too short.
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
				/** @var array<string,string> $methods */
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
				if ( \in_array( $c, self::ERROR_STATUSES, true ) ) {
					$entry['error_status'] = $c;
				}
			}

			return $entry;
		}

		return null;
	}

	/**
	 * Declared arguments, `{name}:config` verbs, and TM_REQUEST verbs.
	 *
	 * The `set_inflight_*` verbs configure the Flight sibling, not this node —
	 * Flight is hidden from the topology editor, so its configuration has to
	 * surface on the patron's interpreter to be reachable at all.
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
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = \trim( Core::as_string( $args[0] ?? '' ) );
						// Empty arg clears target (disables secondary emit).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_errors_target( $arg );
						return 'ok';
					},
				],
				[
					'name'        => 'set_alerts_target',
					'description' => 'Forward `alert` keywords to a named partition (the fleet-alert journal).',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = \trim( Core::as_string( $args[0] ?? '' ) );
						// Empty arg clears target (disables secondary emit).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_alerts_target( $arg );
						return 'ok';
					},
				],
				[
					'name'        => 'set_completed_target',
					'description' => 'Emit a compact one-line summary of each completed request to a named partition (in addition to the primary full-doc emit).',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = \trim( Core::as_string( $args[0] ?? '' ) );
						// Empty arg clears target (disables secondary emit).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_completed_target( $arg );
						return 'ok';
					},
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
					'description' => 'Emit only in-flight rows whose activity advanced since the last snapshot tick. Default off re-emits every row each tick (a fresh subscriber sees the whole cache in one tick); a bare/empty or `0` arg disables.',
					'args'        => [
						[ 'name' => 'on', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = \trim( Core::as_string( $args[0] ?? '' ) );
						// Bare/empty/0 disables; any other value enables.
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->flight()->set_delta( '' !== $arg && '0' !== $arg );
						return 'ok';
					},
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
