<?php
/**
 * Flame Builder
 *
 * The stats fan-out of the event logger. A Consumer tails `requests.p{N}` and
 * fills this node with one TM_STRUCT message per completed request; the node
 * turns each into a flame tree (via `Flame_Tree`), forwards it to the
 * `flames:partition` Partition for the flame viewer, and folds the request into
 * every aggregate the dashboards read.
 *
 * Per-request counters land in `$pending`, one accumulator per 5-minute bucket,
 * keyed by the start of the bucket the request COMPLETED in. The record
 * reaches this node some way behind that moment, so around a boundary it
 * routinely lands in a bucket older than the newest one seen — which is why
 * `$pending` is a map rather than one rotating slot.
 * What the node has folded since it last settled is the SPAN: `$pending` and
 * the per-URL flame trees in `$url_acc`, each folded onto its URL's stored
 * aggregate. The Consumer's interval checkpoint, once a
 * `Consumer_Node::CHECKPOINT_INTERVAL_S`, calls `settle()`, which appends
 * the span's deltas to the stats Ledgers through `Stats_Store` (the stats
 * schema), one row a measurement at its bucket, drains the trees through
 * `drain_url_stats()`, and starts a new span. Nothing is read first and
 * nothing is merged: a reader sums every span's rows. Every other checkpoint
 * — a clean stop, a crawl's per record one — carries the span in the
 * offsetlog through `save_state()`, and the successor's `restore_state()`
 * takes it back, so what one span folded is appended once.
 *
 * One side channel hangs off that pipeline: the request's governing `Rule`
 * drives auto-tune. Hooks that fire too often, custom events to disable, and
 * newly significant events accumulate here and are emitted as messages to the
 * owned `Auto_Tuner_Node` sibling, which rewrites the rule.
 *
 * Worker context: this node runs inside the `flame-builder` (or `complete`)
 * topology. See `topologies/flame-builder.tsl` for the wiring.
 *
 * Two clocks, and a reprocess of the firehose tells them apart. STREAM time
 * is the records' own stamps: what a record is filed under, what its per-URL
 * tree and categories age by. WALL time is the readers' and the store's: the
 * window a reader reads, a Ledger's lifespan, the url Table's TTL, and
 * idleness. A read on the wrong side files a replay a day late or drops it
 * past the lifespan on arrival.
 *
 * | Read                                        | Clock  | Why                                  |
 * |---------------------------------------------|--------|--------------------------------------|
 * | `$timestamp`, the completion, `$pending` key | STREAM | a record is filed where it finished  |
 * | clamp `min( $now, … )`                      | WALL   | readers walk back from the wall      |
 * | tree node `ts`, category cutoff (`$done`)   | STREAM | the per-URL aggregate ages as live   |
 * | `last_modified`, `settled_at`, `worked_at`  | WALL   | a reader's dedup, reports, `idle_since()` |
 * | `apply_auto_tune()`'s lock deadline         | MONO   | a stop's wait, a duration here       |
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Idle_Reporter;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Table_Client;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds flame trees and the stats schema from completed requests.
 *
 * @phpstan-type Leaderboard_Acc array{count?: int, sum_req_time?: float|int, categories: array<array-key,array{samples: int,sum_time: float|int,sum_count: float|int,ts?: int,entries: array<array-key,array<int,float|int>>}>}
 * @phpstan-type Dim_Values array<array-key,array{0: int,1: float|int,2: float|int,3: int}>
 * @phpstan-type Cat_Values array<array-key,array{0: float|int,1: float|int,2: int}>
 * @phpstan-type Bucket_Acc array{
 *   hourly: array<string,mixed>,
 *   dim: array<array-key,Dim_Values>,
 *   dim_by_server: array<array-key,array<array-key,Dim_Values>>,
 *   url_dim: array<array-key,array<array-key,Dim_Values>>,
 *   url_stats: array<array-key,array<array-key,array<int,int|float|null>>>,
 *   url_stats_worker: array<array-key,array<array-key,array<int,int|float|null>>>,
 *   cat: Cat_Values,
 *   cat_by_server: array<array-key,Cat_Values>,
 *   cat_by_url: array<array-key,Cat_Values>,
 *   leaderboard: Leaderboard_Acc,
 *   leaderboard_by_server: array<array-key,Leaderboard_Acc>
 * }
 */
class Flame_Builder_Node extends Timer_Node implements Shutdown_Sweeper, Idle_Reporter {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;
	use Narration;

	/**
	 * Reserved key of the category time series: the per-bucket ROLLUP row, not a
	 * category. It carries `CAT_REQUESTS` = requests in the bucket, `CAT_MS` =
	 * their summed wall time, and `CAT_CALLS` = the summed call count of every
	 * category in them.
	 *
	 * The dashboard's `CategoryTimeChart` skips the row outright, so all three
	 * are published but unread today.
	 *
	 * Category names come from the ruleset, where a custom event may be called
	 * anything — including this. A colliding name is renamed on the way in
	 * (`collision_free_category()`), because otherwise its samples land in the
	 * rollup and inflate its request count.
	 */
	private const TOTAL_KEY = 'total';

	/**
	 * The seven dimensional axes, each as `axis name => request-record field`.
	 *
	 * One table drives all three accumulations of a request — global, per
	 * reporting server, and per URL — so an axis added here appears in all
	 * three. `status_category` is the one field no producer writes;
	 * `accumulate_dimensions()` derives it from the status code first.
	 *
	 * @var array<string,string>
	 */
	const DIM_FIELDS = [
		'status'  => 'status_category',
		'method'  => 'request_method',
		'server'  => 'server_name',
		'country' => 'country_code',
		'from'    => 'http_from',
		'ua'      => 'user_agent',
		'ja4'     => 'ja4_hash',
	];
	/**
	 * Per-category entry caps, applied with hysteresis: a category's entry map is
	 * only sorted and trimmed once it crosses the UPPER bound, and it is trimmed
	 * all the way down to the LOWER one. The gap is what keeps a category sitting
	 * at the cap from paying for a `uasort` on every single request.
	 *
	 * GLOBAL governs the leaderboard (global and per-server); URL governs the
	 * per-URL profile. Ranking is by accumulated `sum_time`, descending.
	 */
	const ENTRY_LIMIT_GLOBAL_LOWER = 50;

	/** Global/per-server leaderboard entry trim trigger. See ENTRY_LIMIT_GLOBAL_LOWER. */
	const ENTRY_LIMIT_GLOBAL_UPPER = 100;

	/** Per-URL profile entry count kept after a trim. See ENTRY_LIMIT_GLOBAL_LOWER. */
	const ENTRY_LIMIT_URL_LOWER    = 20;

	/** Per-URL profile entry trim trigger. See ENTRY_LIMIT_GLOBAL_LOWER. */
	const ENTRY_LIMIT_URL_UPPER    = 40;

	/** Seconds between the Router-tick timer's firings: the auto-tune emit's cadence. */
	const AUTO_TUNE_INTERVAL_SEC = 5;

	/**
	 * Bytes of per-URL flame trees a carried span holds, past which a tree is
	 * left out and its URL keeps the aggregate it last settled.
	 *
	 * Sized from the OUTER limit, not from an observed size: the checkpoint
	 * record is the reader's cursor and every snapshot's state, and
	 * `add_snapshot_node` lifts its PIPE_BUF cap to
	 * `Partition_Node::MAX_LARGE_LINE_SIZE` (32 MiB). This caps the trees
	 * alone, so the crumb and `$pending`, which one span bounds, ride it
	 * uncounted, and half the cliff leaves the other half for them. A staging
	 * hub held 2.1–3.5 MB of trees a checkpoint.
	 *
	 * Protected so a test double can reach the cap without 16 MiB of trees.
	 */
	protected const CARRY_URL_BYTES = 16777216;

	/**
	 * Monotonic clock seam, replacing `hrtime( true )` where a clean stop
	 * times its wait on a sibling's auto-tune lock. Tests reassign it to step
	 * the monotonic clock apart from the wall one.
	 * Signature: `function (): int`, nanoseconds.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $hrtime_fn = null;

	/** How long a clean stop waits for a sibling's auto-tune lock: its expiry. */
	private const AUTO_TUNE_LOCK_WAIT_MS = 5000;

	/** How often that wait re-tries the lock. */
	private const AUTO_TUNE_LOCK_POLL_US = 100000;

	/**
	 * Auto-tune lock poll-sleep seam. Tests reassign it to advance the lock's
	 * own state instead of waiting out a real second.
	 * Signature: `function (int $microseconds): void`
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $usleep_fn = null;

	/**
	 * Cap on the per-process string-intern table. Every dimension value, category
	 * name, and entry name is looked up in that table so repeated names across
	 * requests share one zval instead of one per json_decode. Past the cap the
	 * table freezes — new names are used as-is rather than growing it unbounded.
	 *
	 * Protected so a test double can reach the freeze without pushing 50000
	 * distinct strings through the node.
	 */
	protected const INTERN_TABLE_LIMIT = 50000;

	/**
	 * The value every dimension uses for a producer that reported none.
	 *
	 * The dashboard builds its server picker from the `server` dimension, so
	 * this is a name an operator can select — which means the URL index files
	 * such rows under it too, or picking it empties the table.
	 */
	private const UNKNOWN_VALUE = Stats_Store::UNKNOWN_SERVER;

	/**
	 * Where each RAW-COMPARABLE field sits on an index line, `[offset, length]`.
	 *
	 * Only columns whose trimmed bytes equal the parsed value belong here: a
	 * scan pre-filters on them and parses only on a hit, so a zero-padded
	 * column would answer a question it cannot answer.
	 */
	private const INDEX_COLUMNS = [
		'rid'      => [ 0, 32 ],
		'url_hash' => [ 32, 12 ],
	];

	/**
	 * URL blobs one `MSET` of a settle carries: the batch bounds what one
	 * request serializes while the blobs themselves are held either way.
	 */
	private const WRITE_BATCH_KEYS = 500;

	/**
	 * Auto-tune decisions accrued since the last emit: the key `Auto_Tuner_Node`
	 * dispatches on => rule id => {name => true}. Keying by the emit key is what
	 * makes a fourth decision kind one key here and one case there.
	 *
	 * @var array<string,array<string,array<string,bool>>>
	 */
	private array $auto_tune = [ 'disable_hooks' => [], 'disable_custom_events' => [], 'add_significant_events' => [] ];

	/** @var array<string,bool> Custom-event-name set ({name => true}). */
	private array $custom_event_names = [];

	/** Hub mode: also accumulate the per-server namespaces. Derived from `<eln:is_hub>`. */
	private bool $is_hub = false;

	/** Unix time of the last settle(), which GET_STATS reports as an age. */
	private float $settled_at = 0.0;

	/** Unix time of the last settle that drained a folded record: `idle_since()`. */
	private float $worked_at = 0.0;

	/** Whether this process folded a record no settle wrote and no frame carries. */
	private bool $uncommitted = false;

	/** URL trees the carry has left out of this span so far, told once each. */
	private int $carry_left = 0;

	/** @var array<int,Bucket_Acc> Accumulators by bucket start, drained at settle(). */
	private array $pending = [];

	/**
	 * Each URL's aggregate — flame tree and profile sums — as this span has
	 * folded it onto the stored one, by url_hash: every URL the span touched,
	 * held until settle() writes it, since a dropped entry is a lost fold.
	 *
	 * @var array<array-key,array<array-key,mixed>>
	 */
	private array $url_acc = [];

	/**
	 * The crumb (`Message::ID`) of the last record folded into the
	 * accumulators, the whole of the checkpoint's carry. A plain stop leaves
	 * the reader's cursor ON that record, so a successor restoring this can
	 * tell its replay from new work and count it once (decision 31). '' matches
	 * nothing.
	 */
	private string $counted = '';

	/**
	 * The per-PROCESS string-intern table, shared by every Flame_Builder in the
	 * process — that sharing is the point, and is why this is static.
	 *
	 * @var array<string,string>
	 */
	private static array $intern = [];

	/** Whether `$intern` reached INTERN_TABLE_LIMIT and stopped growing. */
	private static bool $intern_full = false;

	/** @var Rule_Set|null Lazily-loaded per-worker ruleset (thresholds are per-rule). */
	private ?Rule_Set $rule_set = null;
	/** @var array<string,array<string,bool>> rule_id => {event => true} known-significant dedupe cache. */
	private array $significant_events       = [];

	/** @var Stats_Store|null The stats store; null until `configure_stats` runs. */
	private $stats_store = null;

	/** Node NAME of the Table the per-URL blob is written to ('' = unnamed). */
	private string $url_target = '';

	/** @var list<string> The Ledgers `add_ledger_target` named, in order. */
	private array $ledger_targets = [];

	/** The builder's asker for its stats Ledgers and its url Table. */
	private Table_Client $client;

	/**
	 * Seed the settle clock, build the per-URL accumulator, and publish the
	 * owned auto-tuner sibling.
	 *
	 * The node is inert until `configure_stats` supplies a `Stats_Store`: it still
	 * accumulates and still forwards flames, but nothing reaches a stats store.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		$this->settled_at = Core::$now;
		$this->worked_at  = Core::$now;
		$this->client     = new Table_Client( $this, [ Stats_Store::TABLE_URL, ...\array_keys( Stats_Store::LEDGER_COLUMNS ) ] );

		// Owned auto-tuner sibling (patron-linked; hidden from the canvas).
		$auto_tuner = new Auto_Tuner_Node();
		$auto_tuner->patron( $this );
		$this->publish_sibling( 'auto-tuner', $auto_tuner );

		parent::__construct();
		// Wire :config interpreter last: handlers read patron() lazily (safe).
		$this->auto_wire_interpreter();
	}

	/**
	 * Store the tokens and arm the auto-tune emit on the Router tick, every
	 * AUTO_TUNE_INTERVAL_SEC. A node constructed but never given arguments
	 * never fires; `shutdown_sweep()` still emits on a clean stop.
	 *
	 * @api Used by substrate.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string> The tokens as given.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null !== $args ) {
			$this->set_timer( self::AUTO_TUNE_INTERVAL_SEC * 1000 );
		}
		return parent::arguments( $args );
	}

	/**
	 * Handle one message: a stats store's reply, a TM_REQUEST introspection
	 * verb, or a TM_STRUCT completed-request record tailed off `requests.p{N}`.
	 *
	 * A store's reply goes to the client first, never to the fold: it is the
	 * answer to an ask a settle made, not a record.
	 *
	 * A completed request becomes a flame tree, is forwarded to the flames
	 * partition, and is folded into the span. Anything else is dropped.
	 * Nothing here writes a stat: the span settles at the Consumer's interval
	 * checkpoint, so a failed store write fails that checkpoint rather than
	 * being charged to whichever record happened to arrive.
	 *
	 * The message runs inside `deferring()`, which holds a cooperative stop a
	 * downstream forward raises until this message's own bookkeeping is
	 * finished, so the Consumer commits past it rather than replaying it.
	 *
	 * @param array<int,mixed> $message Positional Message array.
	 */
	public function fill( array $message ): void {
		if ( $this->client->accepts( $message ) ) {
			return;
		}
		++$this->counter;
		$this->deferring( fn () => $this->fold_record( $message ) );
	}

	/**
	 * Router-TIMER tick: emit the auto-tune decisions the folds accrued, and
	 * once a minute tell what the settles since wrote. It writes no stat: the
	 * span is the Consumer's to settle. A stop raised inside the emit waits
	 * for it to finish, as it does in `shutdown_sweep()`.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$this->deferring(
			function (): void {
				$this->apply_auto_tune();
				if ( $this->rollup_due( (int) Core::$now ) ) {
					$this->tell_writes();
				}
			}
		);
	}

	/**
	 * Append the span through `Stats_Store` and start a new one: the
	 * Consumer's interval checkpoint calls this, inside its uninterruptible
	 * save and before `save_state()`, so the frame it commits carries an
	 * empty span. An append a Ledger refuses is dropped as decision 3 drops a
	 * failed write, and a failure that raises fails the checkpoint, so its
	 * cursor never commits past records whose deltas were lost.
	 *
	 * @api Used by substrate.
	 */
	public function settle(): void {
		$this->write_pending();
		$this->uncommitted = false;
		$this->carry_left  = 0;
		$this->settled_at  = Core::$now;
	}

	/**
	 * Fold one message; `fill()` documents what each kind becomes.
	 *
	 * @param array<int,mixed> $message Positional Message array.
	 */
	private function fold_record( array $message ): void {
		if ( $this->answer_request( $message ) ) {
			return;
		}
		$type_raw = $message[ Message::TYPE ];
		$type     = Core::int( $type_raw );
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$request = $message[ Message::VALUE ];
		if ( ! \is_array( $request ) ) {
			return;
		}

		$rid_raw  = $request['rid'] ?? '';
		$rid      = Core::str( $rid_raw );
		$url_raw  = $request['url'] ?? '';
		$url_hash = Log_Manager::url_hash( Core::str( $url_raw ) );
		$entries  = $request['entries'] ?? [];
		if ( ! \is_array( $entries ) ) {
			$entries = [];
		}

		$duration_raw = $request['duration_ms'] ?? 0;
		// @longform A record carries EITHER raw entries or — when
		// Request_Builder folded it under memory pressure — the merged tree it
		// built instead. Every record already on disk carries entries, so
		// accepting both shapes is what makes the fold cost no rewrite of
		// history and no dual-write window.
		$prebuilt = $request['flame'] ?? null;
		/** @var array<string,mixed> $flame_data */
		$flame_data          = \is_array( $prebuilt ) && [] !== $prebuilt
			? $prebuilt
			: Flame_Tree::build_flame_data( $entries );
		// Never less than the extent covering already gave its children.
		$flame_data['value'] = \max(
			Core::num_float( $duration_raw ),
			Core::num_float( $flame_data['value'] ?? 0 )
		);

		$profiles = $request['profiles'] ?? [];
		if ( ! \is_array( $profiles ) ) {
			$profiles = [];
		}

		$this->store_flame( $rid, $url_hash, $flame_data );
		// A replay of a counted record re-forwards only its flame.
		$crumb = Core::str( $message[ Message::ID ] );
		if ( '' === $crumb || $crumb !== $this->counted ) {
			$this->accumulate_all_stats( $url_hash, $flame_data, $profiles, $request );
			$this->counted     = $crumb;
			$this->uncommitted = true;
		}

	}

	/**
	 * The `GET_STATS` reply data, which `answer_request()` sends back: the stats
	 * cache, pending buckets and auto-tune queue depth.
	 *
	 * @return array<string,mixed>
	 */
	private function stats_report(): array {
		$stats_count = \count( $this->url_acc );
		$now = Core::$now;
		return [
			'stats_count'              => $stats_count,
			'pending_url_count'        => \array_sum( \array_map( static fn ( array $acc ): int => \array_sum( \array_map( 'count', $acc['url_stats'] ) ), $this->pending ) ),
			'intern_count'             => \count( self::$intern ),
			'pending_buckets'          => \array_keys( $this->pending ),
			'last_settle_age_s'        => $this->settled_at > 0 ? (int) ( $now - $this->settled_at ) : null,
			'auto_tune_pending_count'  => \array_sum( \array_map( self::map_total( ... ), $this->auto_tune ) ),
			'is_hub'                   => $this->is_hub,
			'significant_events_count' => self::map_total( $this->significant_events ),
			'narration'                => $this->tally,
		];
	}

	/**
	 * Forward one request's flame tree to the flames partition.
	 *
	 * `rid` and `url_hash` are stamped into the payload because the companion
	 * index formatter (`format_index_entry`, registered as `flame-index` and
	 * installed by the topology's `with_index` verb) reads them off VALUE.
	 *
	 * Writing is optional: with no target or no sink the flame is simply not
	 * persisted, and the caller aggregates either way.
	 *
	 * @param string               $rid        Request ID.
	 * @param string               $url_hash   URL hash.
	 * @param array<string,mixed> $flame_data Flame tree; mutated locally, not by reference.
	 */
	private function store_flame( string $rid, string $url_hash, array $flame_data ): void {
		// Duplicate-sibling suffixes exist only to survive the merge.
		Flame_Tree::strip_name_suffixes( $flame_data );

		$flame_data['rid']      = $rid;
		$flame_data['url_hash'] = $url_hash;

		if ( '' === $this->target || null === $this->sink ) {
			return; // Aggregation still happens; just no on-disk flame.
		}
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::TO ]    = $this->target;
		$message[ Message::KEY ]   = $flame_data['rid'];
		$message[ Message::VALUE ] = $flame_data;
		// Deferred on a stop so the caller still accumulates stats.
		$this->guarded( fn () => $this->sink->fill( $message ) );
	}

	/**
	 * Total entries across a rule_id => {name => true} map of pending actions.
	 *
	 * @param array<string,array<string,bool>> $map Pending actions by rule.
	 * @return int Entries summed across every rule.
	 */
	private static function map_total( array $map ): int {
		$total = 0;
		foreach ( $map as $inner ) {
			$total += \count( $inner );
		}
		return $total;
	}

	/**
	 * Fold one completed request into every accumulator.
	 *
	 * The order below is fixed: per-URL flame aggregate, bucket selection,
	 * per-URL row, hourly totals, the seven dimensional axes, and finally the
	 * profile loop that feeds the leaderboards, the category time series, and
	 * auto-tune.
	 *
	 * Two independent gates decide what a request contributes, and conflating
	 * them is the classic bug here:
	 *
	 * - `$record_timing` — the request has a positive duration and did not time
	 *   out. Requests failing it still COUNT; only their timing is dropped.
	 * - `$count_global` — the request did not come from a worker. Worker requests
	 *   keep full timing on their own per-URL row but contribute NOTHING global:
	 *   not count, not timing, not peak memory. Otherwise a long-running worker
	 *   would dominate the site-wide averages.
	 *
	 * Sums are stored, never means (see docs/architecture-decisions.md, decision 2); the
	 * display layer divides at read time so cross-bucket merges stay exact.
	 *
	 * @param string                  $url_hash   URL hash of the request.
	 * @param array<string,mixed>    $flame_data Per-request flame tree; its `value` is a render width, not a measurement.
	 * @param array<array-key,mixed> $profiles   `profiles{}` from the request record.
	 * @param array<array-key,mixed> $request    Full request record.
	 */
	private function accumulate_all_stats( string $url_hash, array $flame_data, array $profiles, array $request ): void {
		// The RECORD's duration; the flame's is raised to cover children.
		$duration_ms  = Core::num_float( $request['duration_ms'] ?? 0 );
		$is_worker    = ! empty( $request['is_worker'] );
		// Two gates: per-URL rows keep worker timing; global drops workers.
		$record_timing = self::timing_counts( $duration_ms, $request['error_status'] ?? '-' );
		$count_global  = ! $is_worker;
		$now           = (int) Core::$now;

		$timestamp_raw = $request['timestamp'] ?? $now;
		// @longform The record reaches us at COMPLETION, so that is when it is
		// filed: a request is a fact about the moment it ended, and a long one
		// filed under its start lands where a reader's window may have left
		// it. A timed-out request carries `due - start` as its duration
		// (Request_Builder measures it to the stream boundary its window fell
		// due at), so this is when live traffic timed it out.
		$started       = Core::num_int( $timestamp_raw, $now );
		// @longform Clamped: a completed request cannot have finished after
		// it reached us, so a skewed spoke clock or a bogus duration must
		// not file into a future bucket — readers read back from now and
		// never would, the written-then-unreadable bug of decision 19.
		$timestamp     = \min( $now, $started + (int) \round( $duration_ms / 1000 ) );
		$server_raw    = $request['server_name'] ?? '';
		// `as_string`, the way the `server` DIMENSION reads it: same axis.
		$server_name   = Core::as_string( $server_raw );
		// The per-server gate, resolved once: '' accumulates none.
		$server_key    = $this->is_hub && $count_global ? $server_name : '';
		$url           = Core::str( $request['url'] ?? '' );

		$aggregate = $this->accumulate_url_aggregate( $url_hash, $flame_data, $duration_ms, $record_timing, $timestamp, $unread );
		// Filed under the bucket it COMPLETED in, not the one it started in.
		$bucket                     = Stats_Store::bucket_start( $timestamp );
		$this->pending[ $bucket ] ??= self::empty_bucket();
		$acc                        = &$this->pending[ $bucket ];
		$this->accumulate_url_stats( $acc, $url, $request, $duration_ms, $record_timing, $timestamp, $server_name, $count_global );
		$this->accumulate_hourly( $acc, $request, $duration_ms, $record_timing, $count_global );
		$this->accumulate_dimensions( $acc, $url, $request, $server_key, $duration_ms, $record_timing, $count_global );

		if ( ! empty( $profiles ) && $record_timing ) {
			$this->accumulate_profiles(
				$acc,
				$url,
				$profiles,
				$request,
				$aggregate,
				$server_key,
				$duration_ms,
				$count_global,
				$timestamp
			);
		}

		unset( $acc );

		if ( $unread ) {
			$this->tally( Flame_Tree::STATS_WRITES, 'unread url blobs' );
			$this->print_less_often( 'stats: a URL blob read failed; its update is skipped' );
			return;
		}
		$this->url_acc[ $url_hash ] = $aggregate;
		$this->tally( Flame_Tree::STATS_WRITES, 'url blobs' );
	}

	/**
	 * Fold the request into the per-URL aggregate: one more request, its timing,
	 * and its flame tree merged into the running one. Sums, never means.
	 *
	 * @param string                  $url_hash       URL hash of the request.
	 * @param array<string,mixed>    $flame_data     Per-request flame tree.
	 * @param float                   $duration_ms    Request duration.
	 * @param bool                    $record_timing  Whether timing counts.
	 * @param int                     $done           The request's completion, which its merged nodes are stamped with.
	 * @param-out bool                $unread
	 * @param ?bool                   $unread         Set true when the stored aggregate
	 *                                                could not be read: then the one
	 *                                                returned starts from nothing, and
	 *                                                must not replace it (decision 3).
	 * @return array<array-key,mixed> The updated aggregate.
	 */
	private function accumulate_url_aggregate( string $url_hash, array $flame_data, float $duration_ms, bool $record_timing, int $done, ?bool &$unread = null ): array {
		$unread = false;
		// Un-drained if held, else what was last persisted for a cold key.
		$cached = $this->url_acc[ $url_hash ] ?? null;
		if ( null === $cached && null !== $this->stats_store ) {
			$cached = $this->stats_store->url_aggregate( $url_hash, $unread );
		}
		$aggregate = \is_array( $cached ) ? $cached : null;
		if ( null === $aggregate ) {
			$aggregate = [
				'flame'    => self::empty_url_flame(),
				'profiles' => [
					'count'        => 0,
					'sum_req_time' => 0.0,
					'categories'   => [],
				],
			];
		}
		// Merging resumes from the un-finalized tree, not the display one.
		if ( isset( $aggregate['flame_raw'] ) ) {
			$aggregate['flame'] = $aggregate['flame_raw'];
			unset( $aggregate['flame_raw'] );
		}

		$flame              = \is_array( $aggregate['flame'] ?? null ) ? $aggregate['flame'] : [];
		$aggregate['flame'] = self::fold_url_flame( $flame, $flame_data, $duration_ms, $record_timing, $done );
		return $aggregate;
	}

	/**
	 * A URL's running flame before any request has merged into it.
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_url_flame(): array {
		return [
			'name'      => 'aggregate',
			'sum_value' => 0.0,
			'count'     => 0,
			'children'  => [],
		];
	}

	/**
	 * Whether a request's duration is a timing sample. A timeout's and an
	 * abort's are fictions, and would skew the mean and the max.
	 *
	 * @param float $duration_ms  The record's duration.
	 * @param mixed $error_status The record's error status, `-` when none.
	 */
	public static function timing_counts( float $duration_ms, mixed $error_status ): bool {
		return $duration_ms > 0 && ! \in_array( $error_status, [ 'T', 'A' ], true );
	}

	/**
	 * Fold one request's flame into a URL's running one: one more request,
	 * and, when its timing counts, its duration and its tree. Sums, never
	 * means; `url_flame_for_display()` divides.
	 *
	 * @api Also rebuilds an expired blob in `Performance_CI_Node`.
	 * @param array<array-key,mixed> $flame         The running flame, un-finalized.
	 * @param array<array-key,mixed> $flame_data    One request's tree.
	 * @param float                  $duration_ms   That request's duration.
	 * @param bool                   $record_timing Per `timing_counts()`.
	 * @param int                    $stamp         Stamp for the merged nodes, whose hour back is the expiry cutoff.
	 * @return array<array-key,mixed>
	 */
	public static function fold_url_flame( array $flame, array $flame_data, float $duration_ms, bool $record_timing, int $stamp ): array {
		$flame['count'] = ( \is_numeric( $flame['count'] ?? null ) ? $flame['count'] : 0 ) + 1;
		// Per-URL: workers keep timing on their own row.
		if ( $record_timing ) {
			$flame['sum_value'] = ( \is_numeric( $flame['sum_value'] ?? null ) ? $flame['sum_value'] : 0 ) + $duration_ms;
			$flame_children     = \is_array( $flame['children'] ?? null ) ? $flame['children'] : [];
			$incoming_children  = \is_array( $flame_data['children'] ?? null ) ? $flame_data['children'] : [];
			$flame['children']  = Flame_Tree::merge_flame_children_incremental( $flame_children, $incoming_children, $stamp );
		}
		return $flame;
	}

	/**
	 * Fold the request into its per-URL row in the pending bucket: counts, timing
	 * extremes, status buckets and peak memory, under the server that served it.
	 *
	 * @param Bucket_Acc              $acc           The request's bucket accumulator.
	 * @param string                  $url           The request's URL; '' files no row, only its server's name.
	 * @param array<array-key,mixed> $request       Full request record.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param int                     $timestamp     Completion time, clamped to now.
	 * @param string                  $server_name   Reporting server, '' when unknown.
	 * @param bool                    $count_global  False for a worker, whose timing this row keeps and every site-wide aggregate drops.
	 */
	private function accumulate_url_stats( array &$acc, string $url, array $request, float $duration_ms, bool $record_timing, int $timestamp, string $server_name, bool $count_global ): void {
		// @longform NOT hub-gated, unlike the three per-server aggregates: the
		// filter is offered wherever the `server` dimension has values, and
		// gating this would empty the URL table on every spoke. A nameless
		// producer is filed as that dimension names it, or the picker offers a
		// name this cannot answer to.
		$server = '' === $server_name ? self::UNKNOWN_VALUE : $server_name;
		// Worker traffic files apart, or a URL's reader rows leave with it.
		$slot                    = $count_global ? 'url_stats' : 'url_stats_worker';
		$acc[ $slot ][ $server ] ??= [];
		if ( '' === $url ) {
			return;
		}
		$acc[ $slot ][ $server ][ $url ] ??= self::empty_url_row();
		/**
		 * Positional; see `Stats_Store::ROW_*`.
		 *
		 * @var array{0: int, 1: int, 2: float|int, 3: float|int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: float|int|null, 10: float|int|null, 11: float|int, 12: int} $us
		 */
		$us              = &$acc[ $slot ][ $server ][ $url ];
		$peak_raw        = $request['peak_mb'] ?? 0;
		$peak_mb         = \max( 0.0, Core::num_float( $peak_raw ) );
		$status_category = self::status_category( $request );

		// Per-URL: workers keep timing on their own row.
		$us[ Stats_Store::ROW_COUNT ]       += 1;
		$us[ Stats_Store::ROW_TIMED_COUNT ] += $record_timing ? 1 : 0;
		$us[ Stats_Store::ROW_SUM_MS ]      += $record_timing ? $duration_ms : 0;
		$us[ Stats_Store::ROW_SUM_PEAK_MB ] += $peak_mb;
		$us[ Stats_Store::ROW_ERRORS ]      += self::error_counts( $request['error_status'] ?? '-' ) ? 1 : 0;
		if ( null !== $status_category ) {
			$us[ Stats_Store::ROW_STATUS_COUNTS[ $status_category ] ] += 1;
		}
		$us[ Stats_Store::ROW_LAST_SEEN ] = \max( $us[ Stats_Store::ROW_LAST_SEEN ], $timestamp );
		if ( $record_timing ) {
			$us[ Stats_Store::ROW_MAX_MS ] = \max( $us[ Stats_Store::ROW_MAX_MS ] ?? $duration_ms, $duration_ms );
			$us[ Stats_Store::ROW_MIN_MS ] = \min( $us[ Stats_Store::ROW_MIN_MS ] ?? $duration_ms, $duration_ms );
		}
		$us[ Stats_Store::ROW_MAX_PEAK_MB ] = \max( $us[ Stats_Store::ROW_MAX_PEAK_MB ], $peak_mb );
		unset( $us );
	}

	/**
	 * Whether a request is an error: it timed out or fataled, whatever status
	 * it answered, and whether or not its duration times it. An abort is the
	 * logger's own worker stopping and a gap is missing detail, so neither is.
	 *
	 * @param mixed $error_status The record's error status, `-` when none.
	 */
	public static function error_counts( mixed $error_status ): bool {
		return \in_array( $error_status, [ 'T', 'F' ], true );
	}

	/**
	 * Fold the request into the pending bucket's site-wide totals. Workers
	 * contribute nothing here — not count, not timing, not peak memory —
	 * or one long-running worker would dominate the site-wide averages.
	 * `count` and `sum_ms` take the timed requests (decision 24); `requests`
	 * and `sum_peak_mb` take every one, the divisor with the sum it divides.
	 *
	 * @param Bucket_Acc              $acc           The request's bucket accumulator.
	 * @param array<array-key,mixed> $request       Full request record.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param bool                    $count_global  Whether this feeds global stats.
	 */
	private function accumulate_hourly( array &$acc, array $request, float $duration_ms, bool $record_timing, bool $count_global ): void {
		$hourly_peak     = $request['peak_mb'] ?? 0;
		$hourly_peak_num = \is_numeric( $hourly_peak ) ? $hourly_peak + 0 : 0;
		$hourly          = $acc['hourly'];
		$hourly          = [
			'count'       => \is_numeric( $hourly['count'] ?? null ) ? $hourly['count'] : 0,
			'sum_ms'      => \is_numeric( $hourly['sum_ms'] ?? null ) ? $hourly['sum_ms'] : 0,
			'requests'    => \is_numeric( $hourly['requests'] ?? null ) ? $hourly['requests'] : 0,
			'sum_peak_mb' => \is_numeric( $hourly['sum_peak_mb'] ?? null ) ? $hourly['sum_peak_mb'] : 0,
		];
		// The bucket is seeded either way; only its contents are gated.
		if ( $count_global ) {
			if ( $record_timing ) {
				++$hourly['count'];
				$hourly['sum_ms'] += $duration_ms;
			}
			++$hourly['requests'];
			$hourly['sum_peak_mb'] += $hourly_peak_num;
		}
		$acc['hourly'] = $hourly;
	}

	/**
	 * Fold the request into each of the seven dimensional axes, three ways:
	 * globally, per reporting server (hub only), and per URL.
	 *
	 * @param Bucket_Acc              $acc           The request's bucket accumulator.
	 * @param string                  $url           The request's URL; '' files no per-URL value.
	 * @param array<array-key,mixed> $request       Full request record.
	 * @param string                  $server_key    Per-server scope, '' to accumulate none.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param bool                    $count_global  Whether this feeds global stats.
	 */
	private function accumulate_dimensions( array &$acc, string $url, array $request, string $server_key, float $duration_ms, bool $record_timing, bool $count_global ): void {
		$status_category = self::status_category( $request );
		if ( null !== $status_category ) {
			// The 'status' axis reads this field; nothing else does.
			$request['status_category'] = "{$status_category}xx";
		}
		$dim_peak_raw = $request['peak_mb'] ?? 0;
		$dim_peak_mb  = Core::num_float( $dim_peak_raw );
		$dim_duration = $record_timing ? $duration_ms : 0;

		foreach ( self::DIM_FIELDS as $dim => $field ) {
			$field_raw = $request[ $field ] ?? '';
			$val       = Core::as_string( $field_raw );
			if ( '' === $val ) {
				$val = self::UNKNOWN_VALUE;
			}
			$val = self::intern( $val );
			// Global: workers contribute nothing — count, timing, AND peak.
			if ( $count_global ) {
				$acc['dim'][ $dim ][ $val ] = self::add_dim( $acc['dim'][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb, $record_timing );
			}

			// Per-server, skipping the dim that would only repeat the scope.
			if ( '' !== $server_key && Stats_Store::DIM_SERVER !== $dim ) {
				$acc['dim_by_server'][ $server_key ][ $dim ][ $val ] = self::add_dim( $acc['dim_by_server'][ $server_key ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb, $record_timing );
			}

			// Per-URL, skipping the server: a URL belongs to one.
			if ( '' === $url || Stats_Store::DIM_SERVER === $dim ) {
				continue;
			}
			$acc['url_dim'][ $url ][ $dim ][ $val ] = self::add_dim( $acc['url_dim'][ $url ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb, $record_timing );
		}
	}

	/**
	 * Fold a request into a dimensional bucket: one more request, its peak
	 * memory, and when its duration is a sample, that duration and one more
	 * timed request (decision 24). Seeds the bucket if this is the first.
	 *
	 * @param array{0: int, 1: float|int, 2: float|int, 3: int}|null $slot     Bucket, null on first use.
	 * @param float                                                   $duration Timing to add, 0 when untimed.
	 * @param float                                                   $peak     Peak MB to add.
	 * @param bool                                                    $timed    Whether the duration is a sample.
	 * @return array{0: int, 1: float|int, 2: float|int, 3: int} The updated bucket.
	 */
	private static function add_dim( ?array $slot, float $duration, float $peak, bool $timed ): array {
		$slot ??= [ Stats_Store::DIM_COUNT => 0, Stats_Store::DIM_SUM_MS => 0, Stats_Store::DIM_SUM_PEAK_MB => 0, Stats_Store::DIM_TIMED => 0 ];
		++$slot[ Stats_Store::DIM_COUNT ];
		$slot[ Stats_Store::DIM_SUM_MS ]      += $duration;
		$slot[ Stats_Store::DIM_SUM_PEAK_MB ] += $peak;
		$slot[ Stats_Store::DIM_TIMED ]       += $timed ? 1 : 0;
		return $slot;
	}

	/**
	 * The `Nxx` status bucket a request falls in, or null when its status code
	 * is outside 200-599.
	 *
	 * One derivation serves both readers, the per-URL `count_Nxx` counters and
	 * the `status` dimension. Two would let a request count in one and not the
	 * other.
	 *
	 * @param array<array-key,mixed> $request Full request record.
	 * @return int<2,5>|null Null when the status code is outside 200-599.
	 */
	private static function status_category( array $request ): ?int {
		$status_code = $request['status_code'] ?? 0;
		$category    = (int) \floor( Core::num_float( $status_code ) / 100 );
		return ( $category >= 2 && $category <= 5 ) ? $category : null;
	}

	/**
	 * Fold a request's profile categories into the per-URL aggregate, the two
	 * leaderboards, the three category time series, and the auto-tune signals.
	 *
	 * The auto-tune thresholds resolve here because this is the only accumulator
	 * that reads them.
	 *
	 * Only ever called for a timed request, so `$duration_ms` is positive. The
	 * `$count_global` gate is passed in rather than re-derived, since deciding it
	 * independently at each accumulate site is the classic bug in this class.
	 *
	 * @param Bucket_Acc                $acc          The request's bucket accumulator.
	 * @param string                    $url          The request's URL; '' files no per-URL series.
	 * @param array<array-key,mixed>   $profiles     `profiles{}` from the request record.
	 * @param array<array-key,mixed>   $request      Full request record.
	 * @param array<array-key,mixed>   $aggregate    Per-URL aggregate, by reference.
	 * @param string                    $server_key   Per-server scope, '' to accumulate none.
	 * @param float                     $duration_ms  Request duration.
	 * @param bool                      $count_global Whether this request feeds global stats.
	 * @param int                       $done         The request's completion, the expiry clock of its categories.
	 */
	private function accumulate_profiles(
		array &$acc,
		string $url,
		array $profiles,
		array $request,
		array &$aggregate,
		string $server_key,
		float $duration_ms,
		bool $count_global,
		int $done
	): void {
		// Resolve the request's governing rule once; no match = tune inert.
		$rule             = $this->rule_for_request( $request );
		$count_threshold  = null !== $rule ? $rule->auto_disable_threshold : 0;
		$time_threshold   = null !== $rule ? $rule->auto_protect_time_threshold : 0.0;
		$rule_id          = null !== $rule ? $rule->id : '';
		$auto_tune_active = null !== $rule && $rule->is_log() && '' !== $rule_id;

		$aggregate_profiles = \is_array( $aggregate['profiles'] ?? null ) ? $aggregate['profiles'] : [];
		$prof_cats          = $aggregate_profiles['categories'] ?? [];
		$aggregate_profiles['categories'] = Core::arr( $prof_cats );
		/** @var Leaderboard_Acc $prof */
		$prof = $aggregate_profiles;
		/** @var Leaderboard_Acc $lb */
		$lb   = &$acc['leaderboard'];

		$req_time = 0.0;

		// The `total` rollup is folded in AFTER the loop, from $total_calls.
		$total_calls = 0.0;

		// Per-server leaderboard.
		$slb = null;
		if ( '' !== $server_key ) {
			$acc['leaderboard_by_server'][ $server_key ] ??= self::empty_leaderboard();
			/** @var Leaderboard_Acc $slb */
			$slb = &$acc['leaderboard_by_server'][ $server_key ];
		}

		foreach ( $profiles as $category => $data ) {
			if ( ! \is_string( $category ) || ! \is_array( $data ) ) {
				continue;
			}
			$as_logged = self::intern( $category );
			$category  = self::collision_free_category( $as_logged );

			// Listener and plugin rows are views auto-tune can't act on.
			$is_callback = Flame_Tree::is_listener_span( $as_logged );
			$is_plugin   = Flame_Tree::is_plugin_load_span( $as_logged );
			$base_name   = Flame_Tree::hook_name( $as_logged );

			$time_raw  = $data['time'] ?? 0;
			$count_raw = $data['count'] ?? 0;
			$ts_raw    = $data['ts'] ?? 0;
			$cat_time  = Core::num_float( $time_raw );
			$cat_count = Core::num_int( $count_raw );
			$cat_ts    = Core::num_int( $ts_raw );
			// Callback time already counts inside its hook's time.
			if ( ! $is_callback ) {
				$req_time += $cat_time;
			}

			// Per-URL category.
			$prof['categories'][ $category ] = self::add_category( $prof['categories'][ $category ] ?? null, $cat_time, $cat_count );
			/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<array-key,array<int,float|int>>} $pcat */
			$pcat       = &$prof['categories'][ $category ];
			$pcat['ts'] = \max( $pcat['ts'] ?? 0, $cat_ts );

			// Global leaderboard category: workers excluded.
			$lcat = null;
			if ( $count_global ) {
				$lb['categories'][ $category ] = self::add_category( $lb['categories'][ $category ] ?? null, $cat_time, $cat_count );
				/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<array-key,array<int,float|int>>} $lcat */
				$lcat = &$lb['categories'][ $category ];
			}

			// Per-server leaderboard.
			if ( null !== $slb ) {
				$slb['categories'][ $category ] = self::add_category( $slb['categories'][ $category ] ?? null, $cat_time, $cat_count );
				/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<array-key,array<int,float|int>>} $scat */
				$scat = &$slb['categories'][ $category ];

				$s_entries = $data['entries'] ?? null;
				if ( ! empty( $s_entries ) && \is_array( $s_entries ) ) {
					foreach ( $s_entries as $s_name => $s_entry_data ) {
						$s_name  = self::intern( (string) $s_name );
						$s_time  = \is_array( $s_entry_data ) && \is_numeric( $s_entry_data[0] ?? null ) ? (float) $s_entry_data[0] : 0.0;
						$s_count = \is_array( $s_entry_data ) && \is_numeric( $s_entry_data[1] ?? null ) ? (float) $s_entry_data[1] : 0.0;
						$scat['entries'][ $s_name ] = self::add_entry( $scat['entries'][ $s_name ] ?? null, $s_time, $s_count );
					}
					self::trim_entries( $scat['entries'], self::ENTRY_LIMIT_GLOBAL_UPPER, self::ENTRY_LIMIT_GLOBAL_LOWER );
				}
				unset( $scat );
			}

			$total_calls += $cat_count;

			// Global category time series (pending): workers excluded.
			if ( $count_global ) {
				$acc['cat'][ $category ] = self::add_cat( $acc['cat'][ $category ] ?? null, $cat_time, $cat_count );
			}

			// Per-server category series.
			if ( '' !== $server_key ) {
				$acc['cat_by_server'][ $server_key ][ $category ] = self::add_cat( $acc['cat_by_server'][ $server_key ][ $category ] ?? null, $cat_time, $cat_count );
			}

			if ( '' !== $url ) {
				$acc['cat_by_url'][ $url ][ $category ] = self::add_cat( $acc['cat_by_url'][ $url ][ $category ] ?? null, $cat_time, $cat_count );
			}

			// Significant-event: avg/call > threshold; workers excluded.
			if ( $auto_tune_active && null !== $lcat && ! $is_callback && ! $is_plugin && $time_threshold > 0 && $lcat['sum_count'] > 0 ) {
				$avg_per_call = $lcat['sum_time'] / $lcat['sum_count'];
				if ( $avg_per_call >= $time_threshold ) {
					if ( ! isset( $this->significant_events[ $rule_id ][ $base_name ] ) && ! $rule->marks_significant( $base_name ) ) {
						$this->significant_events[ $rule_id ][ $base_name ]     = true;
						$this->auto_tune['add_significant_events'][ $rule_id ][ $base_name ] = true;
					}
				}
			}

			// Entry loop (per-URL + global).
			$entries = $data['entries'] ?? null;
			if ( ! empty( $entries ) && \is_array( $entries ) ) {
				foreach ( $entries as $name => $entry_data ) {
					$name        = self::intern( (string) $name );
					$entry_time  = \is_array( $entry_data ) && \is_numeric( $entry_data[0] ?? null ) ? (float) $entry_data[0] : 0.0;
					$entry_count = \is_array( $entry_data ) && \is_numeric( $entry_data[1] ?? null ) ? (float) $entry_data[1] : 0.0;

					$pcat['entries'][ $name ] = self::add_entry( $pcat['entries'][ $name ] ?? null, $entry_time, $entry_count );
					// Global entries skipped for workers (ref null).
					if ( null !== $lcat ) {
						$lcat['entries'][ $name ] = self::add_entry( $lcat['entries'][ $name ] ?? null, $entry_time, $entry_count );
					}
				}

				self::trim_entries( $pcat['entries'], self::ENTRY_LIMIT_URL_UPPER, self::ENTRY_LIMIT_URL_LOWER );
				// Global cap: skipped for workers (ref stays null).
				if ( null !== $lcat ) {
					self::trim_entries( $lcat['entries'], self::ENTRY_LIMIT_GLOBAL_UPPER, self::ENTRY_LIMIT_GLOBAL_LOWER );
				}
			}

			// Noisy detection (global auto-tune signal); workers excluded.
			if ( $auto_tune_active && $count_global && ! $is_callback && ! $is_plugin && $count_threshold > 0 && $cat_count > $count_threshold ) {
				if ( isset( $this->custom_event_names[ $base_name ] ) ) {
					$this->auto_tune['disable_custom_events'][ $rule_id ][ $base_name ] = true;
				} else {
					$this->auto_tune['disable_hooks'][ $rule_id ][ $base_name ] = true;
				}
			}
			unset( $pcat );
			unset( $lcat );
		}

		// One rollup fold per request, not one per category.
		if ( $count_global ) {
			$acc['cat'][ self::TOTAL_KEY ] = self::add_cat( $acc['cat'][ self::TOTAL_KEY ] ?? null, $duration_ms, $total_calls );
		}
		if ( '' !== $server_key ) {
			$acc['cat_by_server'][ $server_key ][ self::TOTAL_KEY ] = self::add_cat( $acc['cat_by_server'][ $server_key ][ self::TOTAL_KEY ] ?? null, $duration_ms, $total_calls );
		}
		if ( '' !== $url ) {
			$acc['cat_by_url'][ $url ][ self::TOTAL_KEY ] = self::add_cat( $acc['cat_by_url'][ $url ][ self::TOTAL_KEY ] ?? null, $duration_ms, $total_calls );
		}

		// Top-level sums: per-URL kept; global leaderboard drops workers.
		$prof['count']        = ( $prof['count']        ?? 0 ) + 1;
		$prof['sum_req_time'] = ( $prof['sum_req_time'] ?? 0 ) + $req_time;
		if ( $count_global ) {
			$lb['count']        = ( $lb['count']        ?? 0 ) + 1;
			$lb['sum_req_time'] = ( $lb['sum_req_time'] ?? 0 ) + $req_time;
		}

		if ( null !== $slb ) {
			$slb['count']        = ( $slb['count']        ?? 0 ) + 1;
			$slb['sum_req_time'] = ( $slb['sum_req_time'] ?? 0 ) + $req_time;
			unset( $slb );
		}

		// Categories carry the producer's stamps: expire them on the record's.
		$cutoff = $done - Flame_Tree::AGGREGATE_EXPIRY_SEC;
		foreach ( $prof['categories'] as $cat => $cd ) {
			if ( ( $cd['ts'] ?? 0 ) < $cutoff ) {
				unset( $prof['categories'][ $cat ] );
			}
		}
		$aggregate['profiles'] = $prof;
		unset( $lb );
	}

	/**
	 * Keep a real category out of the reserved rollup slot.
	 *
	 * @param string $category Incoming category name.
	 * @return string The name to store it under.
	 */
	private static function collision_free_category( string $category ): string {
		return self::TOTAL_KEY === $category ? self::TOTAL_KEY . ' (event)' : $category;
	}

	/**
	 * Fold a category sample into a time-series bucket: time, call count, and
	 * one more sample.
	 *
	 * The `total` pseudo-category folds once per REQUEST, carrying the request's
	 * wall time and the summed call count of every category in it — so its
	 * `CAT_REQUESTS` stays a request count.
	 *
	 * @param array{0: float|int, 1: float|int, 2: int}|null $slot  Bucket, null on first use.
	 * @param float                                          $time  Time to add.
	 * @param float                                          $count Call count to add.
	 * @return array{0: float|int, 1: float|int, 2: int} The updated bucket.
	 */
	private static function add_cat( ?array $slot, float $time, float $count ): array {
		$slot ??= [ Stats_Store::CAT_MS => 0, Stats_Store::CAT_CALLS => 0, Stats_Store::CAT_REQUESTS => 0 ];
		$slot[ Stats_Store::CAT_MS ]    += $time;
		$slot[ Stats_Store::CAT_CALLS ] += $count;
		++$slot[ Stats_Store::CAT_REQUESTS ];
		return $slot;
	}

	/**
	 * Fold a category sample into a leaderboard bucket (sums, never means —
	 * docs/architecture-decisions.md, decision 2). The per-URL caller stamps `ts` afterwards.
	 *
	 * @param array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<array-key,array<int,float|int>>}|null $slot Bucket, null on first use.
	 * @param float $time  Time to add.
	 * @param float $count Call count to add.
	 * @return array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<array-key,array<int,float|int>>} The updated bucket.
	 */
	private static function add_category( ?array $slot, float $time, float $count ): array {
		$slot ??= [ 'samples' => 0, 'sum_time' => 0.0, 'sum_count' => 0.0, 'entries' => [] ];
		++$slot['samples'];
		$slot['sum_time']  += $time;
		$slot['sum_count'] += $count;
		return $slot;
	}

	/**
	 * Fold one named entry into its triple `[ sum_time, sum_count, samples ]`.
	 *
	 * @param array<int,float|int>|null $slot  Entry triple, null on first use.
	 * @param float                      $time  Time to add.
	 * @param float                      $count Call count to add.
	 * @return array<int,float|int> The updated triple.
	 */
	private static function add_entry( ?array $slot, float $time, float $count ): array {
		$slot ??= [ 0.0, 0.0, 0 ];
		$slot[0] += $time;
		$slot[1] += $count;
		++$slot[2];
		return $slot;
	}

	/**
	 * Share one zval for a repeated name, until the table freezes at the cap.
	 *
	 * Every dimension value, category name and entry name goes through here.
	 * Entry names are by far the highest-cardinality strings the node sees, so
	 * the freeze has to cover them: exempt one caller and the cap bounds nothing.
	 *
	 * @param string $name The name to intern.
	 * @return string The shared instance, or `$name` once the table is frozen.
	 */
	private static function intern( string $name ): string {
		if ( self::$intern_full ) {
			return $name;
		}
		$shared = self::$intern[ $name ] ??= $name;
		if ( \count( self::$intern ) >= static::INTERN_TABLE_LIMIT ) {
			self::$intern_full = true;
		}
		return $shared;
	}

	/**
	 * Resolve the rule that governed a request: by stamped id, else url-rematch,
	 * else null.
	 *
	 * A stamped `rule_id` can name a rule the operator has since deleted, so the
	 * URL rematch is a fallback, not an alternative path.
	 *
	 * @param array<array-key,mixed> $request Full request record.
	 * @return Rule|null Null when nothing matches, which leaves auto-tune inert.
	 */
	private function rule_for_request( array $request ): ?Rule {
		$id = \is_string( $request['rule_id'] ?? null ) ? $request['rule_id'] : '';
		if ( '' !== $id ) {
			$rule = $this->rule_set()->rule_by_id( $id );
			if ( null !== $rule ) {
				return $rule;
			}
		}
		$url = \is_string( $request['url'] ?? null ) ? $request['url'] : '';
		return '' !== $url ? $this->rule_set()->matcher()->match( $url ) : null;
	}

	/**
	 * Lazily-loaded ruleset, cached for this worker's lifetime.
	 *
	 * A worker therefore keeps serving the ruleset it booted with; a rule edit
	 * reaches it on the next restart, which the settings restart-planner triggers.
	 */
	private function rule_set(): Rule_Set {
		return $this->rule_set ??= Rule_Set::load();
	}

	/**
	 * Hand the Consumer the crumb and the span to co-commit with its cursor.
	 *
	 * After the interval checkpoint's `settle()` the span is `[]`, so the
	 * regular frame carries no stats. Every other frame — a clean stop's, a
	 * crawl's per-record one, a boot recommit — carries what the span holds:
	 * `pending`, the buckets as folded, and `urls`, each URL's flame tree as
	 * folded onto its stored aggregate, under `CARRY_URL_BYTES`. The crumb
	 * says which record under the cursor is already counted (decision 31).
	 * Nothing here writes, so a stop costs the span no write and the frame
	 * that carries it is the only copy: a successor restores it and settles
	 * it once. What the frame carries keeps no worker up (`idle_since()`).
	 *
	 * @api Used by substrate.
	 * @return array{counted: string, span: array<string,array<array-key,mixed>>}
	 */
	public function save_state(): array {
		$state             = [
			'counted' => $this->counted,
			'span'    => \array_filter( [ 'pending' => $this->pending, 'urls' => $this->carried_urls() ] ),
		];
		$this->uncommitted = false;
		return $state;
	}

	/**
	 * Each URL's flame tree the span holds, by url_hash, as many as fit
	 * `CARRY_URL_BYTES` of JSON. A tree left out keeps its URL at the
	 * aggregate it last settled, and is counted and logged once a span, not
	 * once a frame: a crawl carries the same span at every record. A span
	 * under the budget, the usual one, is measured in one encode.
	 *
	 * @return array<array-key,mixed>
	 */
	private function carried_urls(): array {
		if ( \strlen( (string) \wp_json_encode( $this->url_acc ) ) <= static::CARRY_URL_BYTES ) {
			return $this->url_acc;
		}
		$used = 0;
		$urls = [];
		$left = 0;
		foreach ( $this->url_acc as $url_hash => $aggregate ) {
			$bytes = \strlen( (string) \wp_json_encode( $aggregate ) );
			if ( $used + $bytes > static::CARRY_URL_BYTES ) {
				++$left;
				continue;
			}
			$used             += $bytes;
			$urls[ $url_hash ] = $aggregate;
		}
		if ( $left > $this->carry_left ) {
			$this->tally( Flame_Tree::STATS_WRITES, 'left out of the carry', $left - $this->carry_left );
			$this->print_less_often( 'stats: URL flame trees past the carry budget are left out of the checkpoint', " — {$left}" );
			$this->carry_left = $left;
		}
		return $urls;
	}

	/**
	 * On a clean stop, emit the auto-tune decisions, waiting out their lock.
	 * The substrate runs the sweep before the cursor handoff, while the graph
	 * is intact. It writes no stat: the graceful frame carries the span
	 * unsettled, and the successor that restores it settles it.
	 *
	 * A sibling partition may hold the auto-tune lock at that moment. A tick
	 * leaves the decisions for the next tick; a stop has none, so it waits the
	 * lock out — the lock expires in seconds on its own.
	 *
	 * The sweep runs inside `deferring()`, as a message does, so a stop raised
	 * inside it waits for the sweep to finish.
	 *
	 * @api Used by substrate.
	 */
	public function shutdown_sweep(): void {
		$this->deferring(
			fn () => $this->spanned(
				Flame_Tree::STATS_SWEEP,
				function (): void {
					$this->apply_auto_tune( self::AUTO_TUNE_LOCK_WAIT_MS );
					// The last minute's writes would otherwise go untold.
					$this->tell_writes();
				},
				fn (): array => [ 'stopped', [ 'buckets carried' => \count( $this->pending ) ] ]
			)
		);
	}

	/**
	 * Append every pending bucket's rows and write the per-URL aggregates,
	 * and empty both. What a fatal between these writes and the frame that
	 * settles them costs is a double-count — the successor restores the span
	 * the previous frame carried and settles it again on top (see the
	 * CHANGELOG's Known section).
	 *
	 * With no `Stats_Store` wired the drain is a no-op against storage: the
	 * accumulators still reset, but nothing is written anywhere.
	 */
	private function write_pending(): void {
		$stats_store = $this->stats_store;
		if ( null !== $stats_store ) {
			$rows = self::span_rows( $this->pending, self::admitted( $this->pending, $stats_store, (int) Core::$now ) );
			foreach ( $stats_store->append_span( $rows ) as $ledger => $appended ) {
				if ( null === $appended ) {
					$this->tally( Flame_Tree::STATS_WRITES, "refused {$ledger}" );
					continue;
				}
				$this->tally( Flame_Tree::STATS_WRITES, 'rows', Core::num_int( $appended['stored'] ?? null ) );
				$this->tally( Flame_Tree::STATS_WRITES, 'past the lifespan', Core::num_int( $appended['dropped'] ?? null ) );
			}
			$this->drain_url_stats( $stats_store, (int) Core::$now );
		}
		$this->url_acc = [];
		// A settle with nothing folded is upkeep; it moves no idle mark.
		if ( [] !== $this->pending ) {
			$this->worked_at = Core::$now;
		}
		$this->pending = [];
		$this->tally( Flame_Tree::STATS_WRITES, 'settles' );
	}

	/**
	 * The span's rows, by Ledger: each bucket's measurements, a row apiece
	 * at the bucket's start, and the names its URLs and servers file in
	 * the bucket's hour, each once. A bucket's scopes without a request to
	 * count — its site totals and leaderboard on worker traffic alone —
	 * file none.
	 *
	 * Every server files under the name `admitted()` gave it.
	 *
	 * @param array<int,Bucket_Acc> $pending  The span's buckets, by start.
	 * @param array<string,string>  $admitted Server => the name it files under.
	 * @return array<string,list<array{0:int,1:string,2:string,3:list<int|float|null>}>>
	 */
	private static function span_rows( array $pending, array $admitted ): array {
		$as    = static fn ( string $server ): string => $admitted[ $server ] ?? $server;
		$rows  = [];
		$names = [];
		foreach ( $pending as $t => $acc ) {
			$hour   = $t - $t % Stats_Store::HOUR_SECONDS;
			$hourly = $acc['hourly'];
			if ( Core::num_int( $hourly['requests'] ?? null ) > 0 ) {
				$rows[ Stats_Store::LEDGER_TOTALS ][] = [ $t, Stats_Store::SITE, '', [ Core::num_int( $hourly['count'] ?? null ), Core::num_float( $hourly['sum_ms'] ?? null ), Core::num_int( $hourly['requests'] ), Core::num_float( $hourly['sum_peak_mb'] ?? null ) ] ];
			}
			$dims = [ '' => $acc['dim'] ] + $acc['dim_by_server'];
			foreach ( $dims as $server => $by_dim ) {
				foreach ( $by_dim as $dim => $values ) {
					foreach ( $values as $value => $columns ) {
						$named                              = '' === $server && Stats_Store::DIM_SERVER === $dim ? $as( (string) $value ) : (string) $value;
						$rows[ Stats_Store::LEDGER_DIMS ][] = [ $t, Stats_Store::dim_key( (string) $dim, $as( (string) $server ) ), $named, $columns ];
					}
				}
			}
			$cats = [ '' => $acc['cat'] ] + $acc['cat_by_server'];
			foreach ( $cats as $server => $by_category ) {
				foreach ( $by_category as $category => $columns ) {
					$rows[ Stats_Store::LEDGER_CATEGORIES ][] = [ $t, Stats_Store::server_scope( $as( (string) $server ) ), (string) $category, $columns ];
				}
			}
			$boards = [ '' => $acc['leaderboard'] ] + $acc['leaderboard_by_server'];
			foreach ( $boards as $server => $board ) {
				foreach ( self::board_rows( $t, Stats_Store::server_scope( $as( (string) $server ) ), $board ) as $row ) {
					$rows[ Stats_Store::LEDGER_LEADERBOARD ][] = $row;
				}
			}
			$bucket_urls = [];
			foreach ( [ [ $acc['url_stats'], false ], [ $acc['url_stats_worker'], true ] ] as [ $by_server, $worker ] ) {
				foreach ( $by_server as $server => $urls ) {
					$server  = $as( (string) $server );
					$servers = Stats_Store::servers_key( $worker );
					$names[ "{$hour} {$servers} {$server}" ] = [ $hour, $servers, $server, [] ];
					foreach ( $urls as $url => $columns ) {
						$rows[ Stats_Store::LEDGER_URL_ROWS ][] = [ $t, Stats_Store::url_rows_key( $server, $worker ), (string) $url, \array_values( $columns ) ];
						$bucket_urls[ (string) $url ]           = true;
					}
				}
			}
			foreach ( \array_keys( $bucket_urls ) as $url ) {
				$hash_key                       = Stats_Store::hash_key( Log_Manager::url_hash( Core::as_string( $url ) ) );
				$names[ "{$hour} {$hash_key}" ] = [ $hour, $hash_key, Core::as_string( $url ), [] ];
			}
			foreach ( $acc['url_dim'] as $url => $by_dim ) {
				foreach ( $by_dim as $dim => $values ) {
					foreach ( $values as $value => $columns ) {
						$rows[ Stats_Store::LEDGER_URL_DIMS ][] = [ $t, Stats_Store::url_dim_key( (string) $dim, (string) $url ), (string) $value, $columns ];
					}
				}
			}
			foreach ( $acc['cat_by_url'] as $url => $by_category ) {
				foreach ( $by_category as $category => $columns ) {
					$rows[ Stats_Store::LEDGER_URL_CATS ][] = [ $t, Stats_Store::url_key( (string) $url ), (string) $category, $columns ];
				}
			}
		}
		$rows[ Stats_Store::LEDGER_NAMES ] = \array_values( $names );
		return $rows;
	}

	/**
	 * The name each of the span's servers files under: its own while the
	 * names Ledger holds it over a reader's window or that window has room,
	 * and `Stats_Store::OTHER_KEY` once `MAX_SERVER_VALUES` others are held.
	 * `Other` holds no slot. Two partitions settling at once each admit up
	 * to the room they read.
	 *
	 * @param array<int,Bucket_Acc> $pending The span's buckets, by start.
	 * @param Stats_Store           $store   The store the span appends to.
	 * @param int                   $now     The settle's tick.
	 * @return array<string,string> Server => the name it files under.
	 */
	private static function admitted( array $pending, Stats_Store $store, int $now ): array {
		$servers = [];
		foreach ( $pending as $acc ) {
			foreach ( \array_keys( $acc['url_stats'] + $acc['url_stats_worker'] + ( $acc['dim'][ Stats_Store::DIM_SERVER ] ?? [] ) ) as $server ) {
				$servers[ (string) $server ] = true;
			}
		}
		if ( [] === $servers ) {
			return [];
		}
		$to   = Stats_Store::bucket_start( $now ) + Stats_Store::BUCKET_SECONDS;
		$held = \array_fill_keys( $store->servers( true, $to - Stats_Store::MAX_READ_BUCKETS * Stats_Store::BUCKET_SECONDS, $to ), true );
		unset( $held[ Stats_Store::OTHER_KEY ] );
		$out = [];
		foreach ( \array_keys( $servers ) as $server ) {
			$server = Core::as_string( $server );
			if ( ! isset( $held[ $server ] ) && \count( $held ) >= Stats_Store::MAX_SERVER_VALUES ) {
				$out[ $server ] = Stats_Store::OTHER_KEY;
				continue;
			}
			$held[ $server ] = true;
			$out[ $server ]  = $server;
		}
		return $out;
	}

	/**
	 * One leaderboard scope's rows: the requests it profiled under the
	 * empty member, each category under its name, each entry under
	 * `member( category, entry )`; none where it profiled nothing.
	 *
	 * @param int             $t     The bucket's start.
	 * @param string          $scope `Stats_Store::server_scope()`.
	 * @param Leaderboard_Acc $board The scope's sums.
	 * @return list<array{0:int,1:string,2:string,3:list<int|float>}>
	 */
	private static function board_rows( int $t, string $scope, array $board ): array {
		$count = Core::num_int( $board['count'] ?? null );
		if ( 0 === $count ) {
			return [];
		}
		$rows = [ [ $t, $scope, '', [ $count, Core::num_float( $board['sum_req_time'] ?? null ), 0 ] ] ];
		foreach ( $board['categories'] as $category => $sums ) {
			$rows[] = [ $t, $scope, (string) $category, [ $sums['samples'], $sums['sum_time'], $sums['sum_count'] ] ];
			foreach ( $sums['entries'] as $entry => $triple ) {
				$rows[] = [ $t, $scope, Stats_Store::member( (string) $category, (string) $entry ), [ Core::num_int( $triple[2] ?? null ), Core::num_float( $triple[0] ?? null ), Core::num_float( $triple[1] ?? null ) ] ];
			}
		}
		return $rows;
	}

	/**
	 * Tell `stats writes`: what the settles since the last line appended,
	 * the settles and rows first.
	 */
	private function tell_writes(): void {
		$this->rollup( Flame_Tree::STATS_WRITES, [ 'settles', 'rows' ] );
	}

	/**
	 * Emit the accumulated auto-tune decisions: hooks and custom events to
	 * disable, and events newly promoted to significant.
	 *
	 * The decisions become a ruleset rewrite, and every flame-builder partition
	 * accumulates its own. A shared-tier `add()` with a 5-second expiry serves as a
	 * distributed lock so only one worker rewrites at a time; losing the race is
	 * not an error, the decisions simply survive to the next tick.
	 *
	 * Two escapes skip the lock and fire directly: no `Stats_Store` (the test
	 * configuration) and no shared cache tier (single-process, nothing to
	 * race with).
	 */
	private function apply_auto_tune( int $wait_ms = 0 ): void {
		if ( ! \array_filter( $this->auto_tune ) ) {
			return;
		}

		// No store: fire without the lock dance.
		if ( null === $this->stats_store ) {
			$this->fire_auto_tune_actions();
			return;
		}

		// Memcached, else APCu: the lock engages on an APCu-only host too.
		$cache        = Cache_Backend::shared_first();
		$lock_key     = Cache_Backend::site_key( 'evlog:auto_disable_lock' );
		$lock_timeout = 5;
		$lock_value   = \bin2hex( \random_bytes( 8 ) );

		// No shared tier → skip cross-worker lock; just fire (single-proc).
		if ( null === $cache ) {
			$this->fire_auto_tune_actions();
			return;
		}

		// Held by another worker: retry next tick, or wait out on hrtime.
		$deadline = self::monotonic() + $wait_ms * 1_000_000;
		while ( ! $cache->add( $lock_key, $lock_value, $lock_timeout ) ) {
			if ( self::monotonic() >= $deadline ) {
				return;
			}
			$sleep = self::$usleep_fn ?? static fn ( int $us ) => \usleep( $us );
			$sleep( self::AUTO_TUNE_LOCK_POLL_US );
		}

		try {
			$this->fire_auto_tune_actions();
		} finally {
			// Never release a lock that already expired and was re-taken.
			$current = $cache->get( $lock_key );
			if ( $current === $lock_value ) {
				$cache->delete( $lock_key );
			}
		}
	}

	/**
	 * The monotonic clock in ns, through the `$hrtime_fn` seam: what the
	 * auto-tune lock wait is timed on.
	 */
	private static function monotonic(): int {
		return Core::num_int( ( self::$hrtime_fn ?? static fn (): int => (int) \hrtime( true ) )() );
	}

	/**
	 * Emit one message per decision kind for every rule that accrued any, then
	 * clear the queues. Clearing is unconditional: `emit_auto_tune()` drops an
	 * empty item list and a sink-less node, so nothing here can be retried.
	 */
	private function fire_auto_tune_actions(): void {
		foreach ( $this->auto_tune as $key => $by_rule ) {
			foreach ( $by_rule as $rule_id => $names ) {
				$this->emit_auto_tune( $key, $rule_id, \array_keys( $names ) );
			}
			$this->auto_tune[ $key ] = [];
		}
	}

	/**
	 * Send one auto-tune decision downstream as a TM_STRUCT message.
	 *
	 * TO is `{$this->name}:auto-tuner`, the owned `Auto_Tuner_Node` sibling; the
	 * message travels the ordinary path — sink to `_command_interpreter`, then
	 * Router, which resolves that name. The tuner mutates the rule named by
	 * `rule_id` and persists the whole ruleset through `Rule_Set::save()`. There
	 * is no hub fan-out here: the save records a settings event like any admin
	 * edit, and the substrate's settings-sync graph propagates it or does not.
	 *
	 * @param string             $key     'disable_hooks' | 'disable_custom_events' | 'add_significant_events'
	 * @param string             $rule_id The rule these items were proposed under.
	 * @param array<int,string> $items   Hook/event names — already deduped at the caller.
	 */
	private function emit_auto_tune( string $key, string $rule_id, array $items ): void {
		$sink = $this->sink;
		if ( empty( $items ) || null === $sink ) {
			return;
		}
		// Narrate auto-tune fires so debug_state surfaces them.
		$this->set_state(
			'AUTO_TUNE_FIRED',
			\implode( ' ', [ 'KEY', $key, 'RULE', $rule_id, 'COUNT', \count( $items ) ] )
		);
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->sibling_name( 'auto-tuner' );
		$message[ Message::KEY ]       = $key;
		$message[ Message::VALUE ]     = [
			'rule_id' => $rule_id,
			'items'   => $items,
		];
		$sink->fill( $message );
	}

	/**
	 * Drain the per-URL flame and profile aggregates into the store: each
	 * URL's whole blob overwrites the one its partition stored, in `MSET`s
	 * of `WRITE_BATCH_KEYS`.
	 *
	 * @param Stats_Store $stats_store The wired store.
	 * @param int         $now         The caller's one read of the tick, the `last_modified` stamp.
	 */
	private function drain_url_stats( Stats_Store $stats_store, int $now ): void {
		$blobs = [];
		foreach ( $this->url_acc as $url_hash => $aggregate ) {
			// Half the item for profiles, a quarter for each copy of the tree.
			$aggregate['profiles'] = self::cap_leaderboard( Core::arr( $aggregate['profiles'] ?? null ), \intdiv( Stats_Store::ITEM_BUDGET, 2 ) );
			// Finalized flame for display; keep flame_raw for merging.
			[ $aggregate['flame_raw'], $aggregate['flame'] ] = self::url_flame_for_display( Core::arr( $aggregate['flame'] ?? null ) );
			$aggregate['last_modified'] = $now;
			$blobs[ (string) $url_hash ] = $aggregate;
		}
		foreach ( \array_chunk( $blobs, self::WRITE_BATCH_KEYS, true ) as $chunk ) {
			$landed = $stats_store->set_url_aggregates( $chunk );
			$this->tally( Flame_Tree::STATS_WRITES, 'refused url blobs', \count( $chunk ) - \count( $landed ) );
		}
	}

	/**
	 * A URL's running flame pruned to its quarter of one item, and the same
	 * tree finalized to per-request means for display.
	 *
	 * @api Also rebuilds an expired blob in `Performance_CI_Node`.
	 * @param array<array-key,mixed> $flame The running flame, un-finalized.
	 * @return array{0:array<array-key,mixed>,1:array<array-key,mixed>} The pruned raw tree, then its display form.
	 */
	public static function url_flame_for_display( array $flame ): array {
		$raw     = Flame_Tree::prune_lightest( $flame, \intdiv( Stats_Store::ITEM_BUDGET, 4 ), Stats_Store::overhead( 'flame_node' ) );
		$display = $raw;
		Flame_Tree::finalize_flame_node( $display, Core::num_int( $raw['count'] ?? 0 ) );
		return [ $raw, $display ];
	}

	/**
	 * A leaderboard bucket or a URL's profile as stored: each category's
	 * entries trimmed to the global limit, then the categories kept slowest
	 * first under `MAX_LB_CATEGORIES` and `$room` estimated bytes, the rest
	 * folded into `Other`.
	 *
	 * Same entry hysteresis as accumulation: trim only past UPPER, and trim
	 * to LOWER.
	 *
	 * @param array<array-key,mixed> $bucket Leaderboard bucket or profile.
	 * @param int                    $room   Bytes its categories may take.
	 * @return array<array-key,mixed>
	 */
	private static function cap_leaderboard( array $bucket, int $room ): array {
		$categories = $bucket['categories'] ?? null;
		if ( ! \is_array( $categories ) ) {
			return $bucket;
		}
		foreach ( $categories as &$cat_data ) {
			if ( ! \is_array( $cat_data ) ) {
				continue;
			}
			$entries = $cat_data['entries'] ?? null;
			if ( \is_array( $entries ) ) {
				self::trim_entries( $entries, self::ENTRY_LIMIT_GLOBAL_UPPER, self::ENTRY_LIMIT_GLOBAL_LOWER );
				$cat_data['entries'] = $entries;
			}
		}
		unset( $cat_data );
		$bucket['categories'] = self::cap_bucket(
			$categories,
			Stats_Store::MAX_LB_CATEGORIES,
			'sum_time',
			Stats_Store::LB_CAT_SUMS,
			null,
			self::category_bytes( ... ),
			$room
		);
		return $bucket;
	}

	/**
	 * What one leaderboard or profile category costs stored: its own framing
	 * and name, and each entry's.
	 *
	 * @param mixed      $category The category's sums and entries.
	 * @param int|string $name     Its name.
	 */
	private static function category_bytes( mixed $category, int|string $name ): int {
		$entry = Stats_Store::overhead( 'lb_entry' );
		$bytes = Stats_Store::overhead( 'lb_category' ) + \strlen( (string) $name );
		foreach ( Core::arr( Core::arr( $category )['entries'] ?? null ) as $label => $unused ) {
			$bytes += $entry + \strlen( (string) $label );
		}
		return $bytes;
	}

	/**
	 * Trim an entry map back to `$lower` once it passes `$upper`, keeping the
	 * slowest by `sum_time`. The gap between the two bounds is the hysteresis
	 * that stops a busy category re-sorting on every request.
	 *
	 * Takes `array-key,mixed` because both callers are real: one holds accumulator
	 * state, the other a bucket decoded from a Table whose entries can be any
	 * shape. A non-array entry sorts as zero rather than warning.
	 *
	 * @param array<array-key,mixed> $entries Entry map, by reference.
	 * @param int                    $upper   Count that triggers a trim.
	 * @param int                    $lower   Count to trim back to.
	 */
	private static function trim_entries( array &$entries, int $upper, int $lower ): void {
		if ( \count( $entries ) <= $upper ) {
			return;
		}
		\uasort(
			$entries,
			fn ( $a, $b ) => ( \is_array( $b ) ? ( $b[0] ?? 0 ) : 0 ) <=> ( \is_array( $a ) ? ( $a[0] ?? 0 ) : 0 )
		);
		$entries = \array_slice( $entries, 0, $lower, true );
	}

	/**
	 * Cap a bucket's value map to the top `$max_values`, and to `$room` bytes
	 * where the caller estimates them, rolling the tail into a synthetic
	 * `Other`.
	 *
	 * The dimensional, category and leaderboard caps differ only in what they
	 * sort by, which fields they sum, whether a reserved row (`total`) is
	 * lifted clear of the ranking, and whether their values run wide enough to
	 * need a byte bound — so they are arguments, not three functions.
	 *
	 * Key-agnostic: a decoded bucket can carry int keys (a numeric value name);
	 * the body only ever names `Other` and the caller's reserved row.
	 *
	 * @param array<array-key,mixed> $values     One bucket's values.
	 * @param int                    $max_values Ceiling on distinct values, synthetic slots included.
	 * @param string|int             $sort_field Field ranking survivors, descending.
	 * @param array<array-key,bool>  $fields     Field key => is a whole count.
	 * @param string|null            $reserved   Row held out of the ranking and restored after.
	 * @param ?\Closure(mixed, array-key): int $bytes One value's estimated bytes; null counts none.
	 * @param int                    $room       Bytes the whole map may take.
	 * @return array<array-key,mixed>
	 */
	private static function cap_bucket( array $values, int $max_values, string|int $sort_field, array $fields, ?string $reserved = null, ?\Closure $bytes = null, int $room = \PHP_INT_MAX ): array {
		$bytes ??= static fn (): int => 0;
		if ( self::fitting( $values, $max_values, $room, $bytes ) === \count( $values ) ) {
			return $values;
		}
		$held = null;
		if ( null !== $reserved ) {
			$held = $values[ $reserved ] ?? null;
			unset( $values[ $reserved ] );
		}
		// One slot for the overflow key, one more for a reserved row.
		$keep  = \max( 0, $max_values - ( null === $held ? 1 : 2 ) );
		$room -= $bytes( [], Stats_Store::OTHER_KEY ) + ( null === $held ? 0 : $bytes( $held, (string) $reserved ) );
		\uasort(
			$values,
			fn( $a, $b ) => ( \is_array( $b ) && \is_numeric( $b[ $sort_field ] ?? null ) ? $b[ $sort_field ] : 0 )
				<=> ( \is_array( $a ) && \is_numeric( $a[ $sort_field ] ?? null ) ? $a[ $sort_field ] : 0 )
		);
		$keep = self::fitting( $values, $keep, $room, $bytes );
		$top   = \array_slice( $values, 0, $keep, true );
		$rest  = [];
		$stamp = self::stamp_of( $top[ Stats_Store::OTHER_KEY ] ?? null, null );
		foreach ( \array_slice( $values, $keep ) as $v ) {
			$rest  = Stats_Store::sum_fields( $rest, [ Stats_Store::OTHER_KEY => Core::arr( $v ) ], $fields );
			$stamp = self::stamp_of( $v, $stamp );
		}
		$top = Stats_Store::sum_fields( $top, $rest, $fields );
		// The fold is as fresh as the freshest row it absorbed, or it expires.
		if ( null !== $stamp && isset( $top[ Stats_Store::OTHER_KEY ] ) ) {
			$top[ Stats_Store::OTHER_KEY ] = [ 'ts' => $stamp ] + Core::arr( $top[ Stats_Store::OTHER_KEY ] );
		}
		if ( null !== $held ) {
			$top[ $reserved ] = $held;
		}
		return $top;
	}

	/**
	 * The later of a value's `ts` and `$stamp`: only profile categories carry
	 * one, which the expiry sweep in `accumulate_profiles()` reads.
	 *
	 * @param mixed    $value A capped value.
	 * @param int|null $stamp The latest `ts` so far.
	 */
	private static function stamp_of( mixed $value, ?int $stamp ): ?int {
		$ts = \is_array( $value ) ? ( $value['ts'] ?? null ) : null;
		return null === $ts ? $stamp : \max( $stamp ?? 0, Core::num_int( $ts ) );
	}

	/**
	 * How many of `$values`, in their order, fit under both a count and a
	 * byte estimate. Every cap here is this prefix, so a count cap and a byte
	 * cap are one walk rather than two.
	 *
	 * @param array<array-key,mixed>                 $values Ranked values.
	 * @param int                                    $max    Most values kept.
	 * @param int                                    $room   Bytes the kept values may take.
	 * @param \Closure(mixed, array-key): int $bytes  One value's estimated bytes.
	 * @return int
	 */
	private static function fitting( array $values, int $max, int $room, \Closure $bytes ): int {
		$fit = 0;
		foreach ( $values as $key => $value ) {
			$room -= $bytes( $value, $key );
			if ( $fit >= $max || $room < 0 ) {
				break;
			}
			++$fit;
		}
		return $fit;
	}

	/**
	 * A URL row with nothing folded in: every sum 0, and the duration
	 * extremes null, not measured, until a timed request reaches the row.
	 *
	 * @return array<int,int|float|null>
	 */
	private static function empty_url_row(): array {
		return [
			Stats_Store::ROW_COUNT       => 0,
			Stats_Store::ROW_TIMED_COUNT => 0,
			Stats_Store::ROW_SUM_MS      => 0,
			Stats_Store::ROW_SUM_PEAK_MB => 0,
			Stats_Store::ROW_COUNT_2XX   => 0,
			Stats_Store::ROW_COUNT_3XX   => 0,
			Stats_Store::ROW_COUNT_4XX   => 0,
			Stats_Store::ROW_COUNT_5XX   => 0,
			Stats_Store::ROW_ERRORS      => 0,
			Stats_Store::ROW_MIN_MS      => null,
			Stats_Store::ROW_MAX_MS      => null,
			Stats_Store::ROW_MAX_PEAK_MB => 0,
			Stats_Store::ROW_LAST_SEEN   => 0,
		];
	}

	/** @param string $table The Table the per-URL blob is written to. */
	public function set_url_target( string $table ): void {
		$this->url_target = self::mounted_table( 'set_url_target', Stats_Store::TABLE_URL, $table );
	}

	/**
	 * Name one Ledger a settle appends to, refused unless it is one.
	 *
	 * @param string $ledger The Ledger, as `flame-builder.tsl` declares it.
	 * @throws \InvalidArgumentException For a Ledger no settle writes.
	 */
	public function add_ledger_target( string $ledger ): void {
		if ( ! \in_array( $ledger, self::written_ledgers(), true ) ) {
			throw new \InvalidArgumentException( "add_ledger_target: '{$ledger}' is not a Ledger a settle appends to" );
		}
		if ( ! \in_array( $ledger, $this->ledger_targets, true ) ) {
			$this->ledger_targets[] = $ledger;
		}
	}

	/**
	 * The Table a verb names, refused unless it is the one the readers mount
	 * for that role: `Performance_CI_Node` mounts `Stats_Store::TABLE_URL`,
	 * so a write under any other name is one no dashboard reads.
	 *
	 * @param string $verb    The verb naming it, for the refusal.
	 * @param string $mounted The Table the readers mount for the role.
	 * @param string $table   The Table named.
	 * @return string The Table named.
	 * @throws \InvalidArgumentException When it is not the mounted one.
	 */
	private static function mounted_table( string $verb, string $mounted, string $table ): string {
		if ( $mounted !== $table ) {
			throw new \InvalidArgumentException( "{$verb}: '{$table}' is not {$mounted}, the Table the performance readers mount" );
		}
		return $table;
	}

	/**
	 * Take back the crumb, so the record a plain stop left the cursor on is
	 * replayed without being counted again, and the span a frame carried, so
	 * the next settle writes it. Each bucket is merged over an empty
	 * accumulator, so a carry lacking a key keeps that key's default. A key
	 * the carry lacks reads as the start state a fresh process has. A restored span
	 * alone keeps no worker busy: a stop carries it again.
	 *
	 * @api Used by substrate.
	 * @param array<string,mixed> $saved A prior `save_state()`.
	 */
	public function restore_state( array $saved ): void {
		$this->counted = Core::str( $saved['counted'] ?? null );
		$span          = Core::arr( $saved['span'] ?? null );
		foreach ( Core::arr( $span['pending'] ?? null ) as $bucket => $acc ) {
			// A bucket's start decodes back to the int key it was saved under.
			if ( \is_int( $bucket ) && \is_array( $acc ) ) {
				/** @var Bucket_Acc $merged */
				$merged                   = \array_merge( self::empty_bucket(), $acc );
				$this->pending[ $bucket ] = $merged;
			}
		}
		foreach ( Core::arr( $span['urls'] ?? null ) as $url_hash => $aggregate ) {
			if ( \is_array( $aggregate ) ) {
				$this->url_acc[ $url_hash ] = $aggregate;
			}
		}
	}

	/**
	 * One bucket's empty accumulator. Seeded whole so every accumulate site can
	 * index straight in, and so the leaderboard has its shape rather than [].
	 *
	 * @return Bucket_Acc
	 */
	private static function empty_bucket(): array {
		return [
			'hourly'                => [],
			'dim'                   => [],
			'dim_by_server'         => [],
			'url_dim'               => [],
			'url_stats'             => [],
			'url_stats_worker'      => [],
			'cat'                   => [],
			'cat_by_server'         => [],
			'cat_by_url'            => [],
			'leaderboard'           => self::empty_leaderboard(),
			'leaderboard_by_server' => [],
		];
	}

	/**
	 * One leaderboard scope's empty sums, so an accumulator cannot be seeded
	 * differently from the value it merges into.
	 *
	 * @return Leaderboard_Acc
	 */
	private static function empty_leaderboard(): array {
		return [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
	}

	/**
	 * The store `configure_stats` builds: each Ledger `add_ledger_target`
	 * named, under its own name, which the worker graph's Ledger answers
	 * to, and the url Table `set_url_target` named.
	 *
	 * @throws \LogicException Before `set_url_target`, or before every Ledger
	 *                         a settle writes is named, which would take
	 *                         their rows to no node at all.
	 */
	private function configured_store(): Stats_Store {
		if ( '' === $this->url_target ) {
			throw new \LogicException( 'configure_stats: no Table named by set_url_target' );
		}
		$unnamed = \array_diff( self::written_ledgers(), $this->ledger_targets );
		if ( [] !== $unnamed ) {
			throw new \LogicException( 'configure_stats: no Ledger named by add_ledger_target: ' . \implode( ', ', $unnamed ) );
		}
		return new Stats_Store( $this->client, \array_combine( $this->ledger_targets, $this->ledger_targets ), [ $this->url_target ] );
	}

	/**
	 * The Ledgers a settle appends to: every stats Ledger but the word
	 * index, which nothing writes yet.
	 *
	 * @return list<string>
	 */
	private static function written_ledgers(): array {
		return \array_keys( \array_diff_key( Stats_Store::LEDGER_COLUMNS, [ Stats_Store::LEDGER_SEARCH => true ] ) );
	}

	/**
	 * Fold another partition's running flame for the same URL into this one:
	 * their requests and durations add, and their trees merge as a request's
	 * does, each node keeping the newer stamp. The merge stamp is the newest
	 * either tree holds, so a node an hour behind it expires as it would have
	 * in one builder that saw both partitions' traffic.
	 *
	 * @param array<array-key,mixed> $flame A running flame, un-finalized.
	 * @param array<array-key,mixed> $other Another partition's, the same shape.
	 * @return array<array-key,mixed>
	 */
	public static function merge_url_flames( array $flame, array $other ): array {
		$children = \array_values( Core::arr( $flame['children'] ?? null ) );
		$incoming = \array_values( Core::arr( $other['children'] ?? null ) );
		$newest   = 0;
		foreach ( [ ...$children, ...$incoming ] as $child ) {
			$newest = \max( $newest, Core::num_int( Core::arr( $child )['ts'] ?? null ) );
		}
		$flame['count']     = Core::num_int( $flame['count'] ?? null ) + Core::num_int( $other['count'] ?? null );
		$flame['sum_value'] = Core::num_float( $flame['sum_value'] ?? null ) + Core::num_float( $other['sum_value'] ?? null );
		$flame['children']  = Flame_Tree::merge_flame_children_incremental( $children, $incoming, $newest );
		return $flame;
	}

	/**
	 * Inject the Stats_Store the settle writes through.
	 *
	 * @param Stats_Store $store Store over this worker's stats Ledgers and url Table.
	 */
	public function set_stats_store( Stats_Store $store ): void {
		$this->stats_store = $store;
	}

	/**
	 * The stats stores a configured settle writes past its primary target —
	 * the url Table and the Ledgers the verbs named — so the console draws
	 * an edge to each.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		if ( null === $this->stats_store ) {
			return [];
		}
		return [ $this->url_target, ...$this->ledger_targets ];
	}

	/**
	 * When this node went idle: the last settle that drained a folded record,
	 * or null while a record this process folded is in neither a settle nor
	 * a committed frame, which the Consumer's next checkpoint brings. A span
	 * a frame carries keeps nothing up — a restored one, or one a crawl
	 * checkpointed record by record: a stop carries it again, and an idle
	 * log moves no cursor, so no interval checkpoint would ever settle it.
	 *
	 * @api Used by substrate: `Cooperative_Stop`'s idle scan.
	 */
	public function idle_since(): ?float {
		return $this->uncommitted ? null : $this->worked_at;
	}

	/**
	 * Toggle hub mode, which adds the per-server namespaces to every accumulator.
	 *
	 * @param bool $is_hub True on an aggregating hub.
	 */
	public function set_is_hub( bool $is_hub ): void {
		$this->is_hub = $is_hub;
	}

	/**
	 * Inject the custom-event-names set, which decides whether a noisy category
	 * is queued as a custom event to disable or as a hook to disable.
	 *
	 * Nothing in production calls this, so the set is empty at runtime and every
	 * noisy category is queued as a hook.
	 *
	 * @api Used by tests.
	 * @param array<int,string> $names Custom-event names.
	 */
	public function set_custom_event_names( array $names ): void {
		$this->custom_event_names = [];
		foreach ( $names as $n ) {
			$this->custom_event_names[ $n ] = true;
		}
	}

	/**
	 * The queued auto-tune decisions, by emit key then rule id.
	 *
	 * @api Used by tests.
	 * @return array<string,array<string,list<string>>> The `$auto_tune` map, its name sets listed.
	 */
	public function get_auto_tune_state(): array {
		return \array_map(
			static fn ( array $by_rule ): array => \array_map( static fn ( array $set ): array => \array_keys( $set ), $by_rule ),
			$this->auto_tune
		);
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `command_node {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI). No generic verb recording.
	 *
	 * A verb missing here silently drops its setting on a console serialize →
	 * replay round trip, so a new persistent verb needs a line added. The
	 * Table setter dumps ahead of `configure_stats`, which refuses to run
	 * before it has.
	 *
	 * @api Used by substrate.
	 * @return string TSL lines, newline-terminated.
	 */
	public function dump_config(): string {
		$out = parent::dump_config() . $this->dump_setters() . $this->dump_toggles();
		foreach ( $this->ledger_targets as $ledger ) {
			$out .= $this->config_line( 'add_ledger_target', $ledger );
		}
		if ( null !== $this->stats_store ) {
			$out .= $this->config_line( 'configure_stats' );
		}
		return $out;
	}

	/**
	 * Format one companion-index line for the flames partition.
	 *
	 * Registered as the `flame-index` formatter in the plugin entry point and
	 * installed by `command_node flames:partition:config with_index flame-index`. The
	 * layout is fixed-width so `parse_flame_index()` can slice it by offset:
	 * rid(32) url_hash(12) segment(6) offset(10) length(8) = 68 bytes. Changing a
	 * width here means changing both the parser and every existing index file.
	 *
	 * @param array<int,mixed>  $message  The unpacked positional message array.
	 * @param array<string,int> $position Position array with segment, offset, length.
	 * @return string|null Index entry, or null to skip a record with no rid.
	 */
	public static function format_index_entry( array $message, array $position ): ?string {
		$value = $message[ Message::VALUE ] ?? null;
		if ( ! \is_array( $value ) || empty( $value['rid'] ) ) {
			return null;
		}
		$rid_str      = \is_scalar( $value['rid'] ) ? (string) $value['rid'] : '';
		$url_hash_str = \is_scalar( $value['url_hash'] ?? null ) ? (string) $value['url_hash'] : '';

		return \str_pad( \substr( $rid_str, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash_str, 0, 12 ), 12 )
			. \str_pad( (string) $position['segment'], 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse one flame index line back into its fields — the inverse of
	 * `format_index_entry()`, and bound to the same fixed widths.
	 *
	 * @param string $line Index line.
	 * @return array{rid: string, url_hash: string, segment: int, offset: int, length: int}|null Null when the line is short.
	 */
	public static function parse_flame_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( \strlen( $line ) < 68 ) {
			return null;
		}
		return [
			'rid'        => \trim( \substr( $line, 0, 32 ) ),
			'url_hash'   => \trim( \substr( $line, 32, 12 ) ),
			'segment' => (int) \substr( $line, 44, 6 ),
			'offset'     => (int) \substr( $line, 50, 10 ),
			'length'     => (int) \substr( $line, 60, 8 ),
		];
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
	 * No time at all on a flame line — it is rid, url_hash and a position, and
	 * offset 44 is `segment`. A walk over this index cannot bound itself by
	 * time, and its caller keeps the time budget as its only bound.
	 *
	 * @return array{} Always empty.
	 */
	public static function index_completion_columns(): array {
		return [];
	}

	/**
	 * Declarative schema: the `:config` verbs, the TM_REQUEST verbs, and the
	 * palette metadata. `Schema_Reflection::auto_wire_interpreter()` builds the
	 * `{name}:config` interpreter from the `commands` entries, so a verb added
	 * here needs no wiring — but a persistent one also needs a `dump_config()`
	 * line, or it will not survive a serialize → replay round trip.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Appends each span of per-request stats to the stats Ledgers; emits flame JSONL.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'set_is_hub',
					'description' => 'Toggle hub mode (per-server tracking).',
					'args'        => [
						[ 'name' => 'is_hub', 'type' => 'bool', 'required' => true, 'default' => '<eln:is_hub>' ],
					],
					// Declarative: the substrate synthesizes handler and dump.
					'toggle'      => 'is_hub',
				],
				[
					'name'        => 'set_url_target',
					'description' => 'Name the stats Table the per-URL blob is written to: flame-stats:url, the one the performance readers mount.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'url_target',
				],
				[
					'name'        => 'add_ledger_target',
					'description' => 'Name one stats Ledger a settle appends to; name each, as flame-builder.tsl does.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->add_ledger_target( Core::as_string( $args['target'] ?? '' ) );
						return 'ok';
					},
				],
				[
					'name'        => 'configure_stats',
					'description' => 'Build the Stats_Store over the Ledgers add_ledger_target named and the url Table set_url_target named. Refused until each is named.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_store( $patron->configured_store() );
						return 'ok';
					},
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'Stats cache + pending buckets + auto-tune queue depth.',
					'reply_shape' => '{ stats_count, pending_url_count, intern_count, pending_buckets, last_settle_age_s, auto_tune_pending_count, is_hub, significant_events_count, narration }',
					'handler'     => static fn ( self $node ): array => $node->stats_report(),
				],
			],
		];
	}
}
