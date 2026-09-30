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
 * keyed by the bucket the request COMPLETED in. The record reaches this node
 * some way behind that moment, so around a boundary it routinely lands in a
 * bucket older than the newest one seen — which is why `$pending` is a map
 * rather than one rotating slot.
 * `flush()` merges each bucket into the stats Tables through `Stats_Store`
 * (the stats schema) at most once per FLUSH_INTERVAL_SEC, capping as it writes,
 * then drops them. It also folds each hour of the URL index its data clock
 * has left into the coarse `urls_h` tier, which is what keeps a reader off
 * 288 fine buckets per shard. Per-URL flame trees take a different route:
 * they are held in `$url_acc`, folded onto each URL's stored aggregate, and
 * drain through `drain_url_stats()`.
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
 * tree and categories age by, and what folds. WALL time is the readers' and
 * the store's: the window a reader reads, the ranking cadence its page cache
 * sets, every TTL, and idleness. A read on the wrong side files a replay a
 * day late or expires it on arrival.
 *
 * | Read                                        | Clock  | Why                                  |
 * |---------------------------------------------|--------|--------------------------------------|
 * | `$timestamp`, the completion, `$pending` key | STREAM | a record is filed where it finished  |
 * | clamp `min( $now, … )`                      | WALL   | readers walk back from the wall      |
 * | tree node `ts`, category cutoff (`$done`)   | STREAM | the per-URL aggregate ages as live   |
 * | `$data_clock`, `foldable()`'s hours         | STREAM | an hour folds once the data left it  |
 * | `foldable()`'s quiet, `worked_at_hr`        | MONO   | a duration inside this process       |
 * | `foldable()`'s `$expiring`                  | WALL   | the fine keys' TTL runs on the wall  |
 * | `plan_at()`, the read plan and fine floor   | WALL   | the window a reader reads            |
 * | `rank_due()`, `$current`, `ranked_at`       | WALL   | the page cache's refresh, the reader's open bucket |
 * | `persist_url_names()`, `drain_url_stats()` hour | WALL | the filing refresh against the TTL |
 * | `persist_url_tokens()`                      | WALL   | a member's lifetime and a search's window |
 * | `last_modified`, `last_flush_time`, `worked_at` | WALL | a reader's dedup, reports, `idle_since()` |
 * | `apply_auto_tune()`'s lock deadline         | MONO   | a stop's wait, a duration here       |
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Idle_Reporter;
use Newspack_Nodes\LRU_Cache;
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
 * @phpstan-type Pending_Write array{parts: array<int,string>, bucket: string, merge: \Closure(array<array-key,mixed>): array<array-key,mixed>, refused: \Closure|null, landed: \Closure|null, group: string|null}
 * @phpstan-type Leaderboard_Acc array{count?: int, sum_req_time?: float|int, categories: array<string,array{samples: int,sum_time: float|int,sum_count: float|int,ts?: int,entries: array<string,array<int,float|int>>}>}
 * @phpstan-type Dim_Values array<string,array{0: int,1: float|int,2: float|int,3: int}>
 * @phpstan-type Cat_Values array<string,array{0: float|int,1: float|int,2: int}>
 * @phpstan-type Bucket_Acc array{
 *   hourly: array<string,mixed>,
 *   dim: array<string,Dim_Values>,
 *   dim_by_server: array<string,array<string,Dim_Values>>,
 *   url_dim: array<array-key,array<string,Dim_Values>>,
 *   url_stats: array<array-key,array<array-key,mixed>>,
 *   url_stats_worker: array<array-key,array<array-key,mixed>>,
 *   url_names: array<array-key,string>,
 *   cat: Cat_Values,
 *   cat_by_server: array<string,Cat_Values>,
 *   cat_by_url: array<array-key,Cat_Values>,
 *   leaderboard: Leaderboard_Acc,
 *   leaderboard_by_server: array<string,Leaderboard_Acc>
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

	/** Seconds between periodic flush() runs: the cadence of the Router-tick timer. */
	const FLUSH_INTERVAL_SEC = 5;

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
	 * Keys per read/write batch in a flush. Bounds the held set: one chunk is
	 * at most one shard's worth of rows, which is the largest value the schema
	 * writes. Raise only against a measured peak.
	 */
	private const WRITE_BATCH_KEYS = 500;

	/**
	 * Closed hours one flush folds into the coarse tier, and stale hours it
	 * ranks. A bound, not a cadence: steady state has at most one hour to
	 * fold, and this is what keeps a cold start's backfill, or a replay's
	 * re-ranking, off a single flush. One number for both, because each holds
	 * one hour's coarse rows in memory per unit spent.
	 */
	private const ROLLUP_HOURS_PER_FLUSH = 2;

	/**
	 * Auto-tune decisions accrued since the last emit: the key `Auto_Tuner_Node`
	 * dispatches on => rule id => {name => true}. Keying by the emit key is what
	 * makes a fourth decision kind one key here and one case there.
	 *
	 * @var array<string,array<string,array<string,bool>>>
	 */
	private array $auto_tune = [ 'disable_hooks' => [], 'disable_custom_events' => [], 'add_significant_events' => [] ];

	/**
	 * Hours this worker has folded into the coarse tier, pruned to the window.
	 *
	 * Decision 17 puts the fold on the flush path precisely BECAUSE one
	 * partition has one writer — so the process that folded an hour is the
	 * authority on whether it is folded, and steady state probes nothing. A
	 * restart empties it and pays one probe, which re-adopts a predecessor's
	 * work.
	 *
	 * @var array<string,bool>
	 */
	private array $folded_hours = [];

	/**
	 * The read plan last built, with the bucket it was built in. The plan is a
	 * function of that bucket and the store's TTL alone, so every tick and
	 * flush inside one bucket shares it; `set_stats_store()` drops it.
	 *
	 * @var array{bucket: string, plan: array{fine: list<string>, hours: list<string>}}|null
	 */
	private ?array $plan_memo = null;

	/**
	 * The merged rows the fine-tier intents landed this flush, by bucket then
	 * server then READER shard then hash — never worker-family, which never
	 * ranks. The covered-shard set falls out of the keys, so ranking reads
	 * back only the shards missing here, and a bucket with none landed is not
	 * ranked at all: the lists are a pure function of the stored reader rows.
	 * Emptied after ranking.
	 *
	 * @var array<string,array<string,array<string,array<array-key,mixed>>>>
	 */
	private array $flushed_rows = [];

	/**
	 * Each bucket this flush wrote rows into, and the index it holds once
	 * this flush's entries were merged in: what ranking gap-fills from, so a
	 * bucket the flush just wrote costs no second index read. Emptied with
	 * `$flushed_rows`.
	 *
	 * @var array<string,array<string,array{0:string,1:int}>>
	 */
	private array $flushed_index = [];

	/**
	 * When each bucket was last ranked, pruned to the fine window.
	 *
	 * @var array<string,int>
	 */
	private array $ranked_at = [];

	/**
	 * Buckets a flush wrote rows into that have not been ranked since,
	 * pruned the same way.
	 *
	 * The closed-bucket clause fires on a WRITE, and a bucket whose last
	 * writes landed inside the cadence gets none after it closes — so
	 * without this its final rows would never reach its lists.
	 *
	 * @var array<string,bool>
	 */
	private array $rank_pending = [];

	/**
	 * Folded hours the roll-up found with a server unranked, each until its
	 * servers are ranked from the stored rows, and pruned to the read plan's
	 * hours, each beside the cause that made it stale, which its `stats re-rank`
	 * span names. The store holds the debt, not this: the next worker's
	 * roll-up finds it again, so a stop that leaves one loses nothing.
	 *
	 * @var array<string,string>
	 */
	private array $stale_hours = [];

	/**
	 * Folded hours a late write landed in this flush, with the servers whose
	 * DONE markers the flush forgets, once each, so the hour ranks again.
	 *
	 * @var array<string,array<array-key,true>> An all-digit server key is an INT key.
	 */
	private array $unranked_hours = [];

	/**
	 * Hours a late write took off the fold memo (`persist_aggregate_stats()`'s
	 * `$unfold`), until the roll-up reads them again and says so as a
	 * `stats probe` line: an `unfold`.
	 *
	 * @var array<string,true>
	 */
	private array $unfolded = [];

	/** @var array<string,bool> Custom-event-name set ({name => true}). */
	private array $custom_event_names = [];

	/** Hub mode: also accumulate the per-server namespaces. Derived from `<eln:is_hub>`. */
	private bool $is_hub = false;

	/** Unix time of the last periodic flush(), which GET_STATS reports as an age. */
	private float $last_flush_time = 0.0;

	/** Unix time of the last flush that drained a folded record: `idle_since()`. */
	private float $worked_at = 0.0;

	/** The same moment on the monotonic clock, in ns: what quiet is measured from. */
	private int $worked_at_hr = 0;

	/**
	 * The data clock: the newest bucket a flush that drained records wrote,
	 * '' before the first. `foldable()` folds an hour once it has left it.
	 */
	private string $data_clock = '';

	/** Unix time the data clock entered its hour: when that hour was first written. */
	private float $data_clock_hour_since = 0.0;

	/**
	 * Hours the roll-up read holding no index and has not folded, each
	 * waiting on the data clock or the fold budget. One partition has one
	 * writer, so such an hour gains an index only when this process folds
	 * it: the roll-up reads it once, not once a flush.
	 *
	 * @var array<string,true>
	 */
	private array $absent_hours = [];

	/** @var array<string,Bucket_Acc> Accumulators by bucket key, drained at flush(). */
	private array $pending = [];

	/** Per-URL aggregates one accumulator bucket holds before it rotates. */
	private const URL_ACCUMULATOR_SIZE = 1000;

	/** Accumulator buckets retained; capacity is roughly the product. */
	private const URL_ACCUMULATOR_BUCKETS = 5;

	/**
	 * Each URL's aggregate — flame tree and profile sums — as this flush has
	 * folded it onto the stored one, by url_hash; drained at flush().
	 */
	private LRU_Cache $url_acc;

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

	/** Node NAME of the Table every other namespace is written to ('' = unnamed). */
	private string $aggregate_target = '';

	/** Node NAME of the Table the per-URL blob is written to ('' = unnamed). */
	private string $url_target = '';

	/** Node NAME of the Table the fine URL tier is written to ('' = unnamed). */
	private string $url_fine_target = '';

	/** The builder's asker for its three stats Tables. */
	private Table_Client $client;

	/**
	 * Seed the flush clock, build the per-URL accumulator, and publish the
	 * owned auto-tuner sibling.
	 *
	 * The node is inert until `configure_stats` supplies a `Stats_Store`: it still
	 * accumulates and still forwards flames, but nothing reaches a stats Table.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		$this->last_flush_time = Core::$now;
		$this->worked_at       = Core::$now;
		$this->worked_at_hr    = Quiet::mark();
		$this->url_acc         = new LRU_Cache( self::URL_ACCUMULATOR_SIZE, self::URL_ACCUMULATOR_BUCKETS );
		$this->client          = new Table_Client( $this, Stats_Store::TABLES );

		// Owned auto-tuner sibling (patron-linked; hidden from the canvas).
		$auto_tuner = new Auto_Tuner_Node();
		$auto_tuner->patron( $this );
		$this->publish_sibling( 'auto-tuner', $auto_tuner );

		parent::__construct();
		// Wire :config interpreter last: handlers read patron() lazily (safe).
		$this->auto_wire_interpreter();
	}

	/**
	 * Store the tokens and arm the periodic flush on the Router tick, every
	 * FLUSH_INTERVAL_SEC. A node constructed but never given arguments never
	 * fires; `shutdown_sweep()` still flushes it on a clean stop.
	 *
	 * @api Used by substrate.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string> The tokens as given.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null !== $args ) {
			$this->set_timer( self::FLUSH_INTERVAL_SEC * 1000 );
		}
		return parent::arguments( $args );
	}

	/**
	 * Handle one message: a stats Table's reply, a TM_REQUEST introspection
	 * verb, or a TM_STRUCT completed-request record tailed off `requests.p{N}`.
	 *
	 * A Table's reply goes to the client first, never to the fold: it is the
	 * answer to an ask the flush made, not a record.
	 *
	 * A completed request becomes a flame tree, is forwarded to the flames
	 * partition, and is folded into every accumulator. Anything else is dropped.
	 * Nothing here flushes: the flush is the node's own periodic work, run from
	 * `fire()`, so a failed store write raises from the tick rather than being
	 * charged to whichever record happened to arrive.
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
	 * Router-TIMER tick: flush whatever the last flush left owed. A failure
	 * raises here, out of the drain, and the worker exits loudly; its successor
	 * replays from the last checkpoint's cursor. With nothing owed the tick
	 * flushes nothing: it formats one bucket key and diffs the read plan's
	 * hours against those rolled up, the plan built once per bucket. A stop
	 * raised inside the flush waits for it to finish, as it does in
	 * `shutdown_sweep()`.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		if ( $this->flush_owed( (int) Core::$now ) ) {
			$this->deferring( fn () => $this->flush() );
			$this->last_flush_time = Core::$now;
		}
	}

	/**
	 * Whether `flush()` has work at `$now`, read from memory alone. Folded
	 * records are one kind; the rest outlive them: auto-tune decisions a
	 * sibling's lock held back, buckets waiting to rank, stale hours owed
	 * their lists, and an hour of the read plan the data clock has left that
	 * this process has not rolled up.
	 *
	 * @param int $now The tick.
	 */
	private function flush_owed( int $now ): bool {
		if ( [] !== $this->pending || [] !== \array_filter( $this->auto_tune )
			|| [] !== $this->rank_pending || [] !== $this->stale_hours ) {
			return true;
		}
		$stats_store = $this->stats_store;
		if ( null === $stats_store ) {
			return false;
		}
		$hours = $this->foldable( $stats_store, $this->plan_at( $stats_store, $now )['hours'], $now );
		return [] !== \array_diff( $hours, \array_keys( $this->folded_hours ) );
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
			$this->counted = $crumb;
		}

	}

	/**
	 * The `GET_STATS` reply data, which `answer_request()` sends back: the stats
	 * cache, pending buckets and auto-tune queue depth.
	 *
	 * @return array<string,mixed>
	 */
	private function stats_report(): array {
		$stats_count = \iterator_count( $this->url_acc->iterate() );
		$now = Core::$now;
		return [
			'stats_count'              => $stats_count,
			'pending_url_count'        => \array_sum( \array_map( static fn ( array $acc ): int => \array_sum( \array_map( 'count', $acc['url_stats'] ) ), $this->pending ) ),
			'intern_count'             => \count( self::$intern ),
			'pending_buckets'          => \array_keys( $this->pending ),
			'last_flush_age_s'         => $this->last_flush_time > 0 ? (int) ( $now - $this->last_flush_time ) : null,
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
	 * The order below is fixed: per-URL flame aggregate (LRU), bucket selection,
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
		// filed under its start lands in a bucket the readers may have closed
		// and folded. A timed-out request carries `due - start` as its
		// duration (Request_Builder measures it to the stream boundary its
		// window fell due at), so this is when live traffic timed it out.
		$started       = Core::num_int( $timestamp_raw, $now );
		// @longform Clamped: a completed request cannot have finished after
		// it reached us, so a skewed spoke clock or a bogus duration must
		// not file into a future bucket — readers walk backwards from now
		// and never would, the written-then-unreadable bug of decision 19.
		$timestamp     = \min( $now, $started + (int) \round( $duration_ms / 1000 ) );
		$server_raw    = $request['server_name'] ?? '';
		// `as_string`, the way the `server` DIMENSION reads it: same axis.
		$server_name   = Core::as_string( $server_raw );
		// The per-server gate, resolved once: '' accumulates none.
		$server_key    = $this->is_hub && $count_global ? $server_name : '';

		$aggregate = $this->accumulate_url_aggregate( $url_hash, $flame_data, $duration_ms, $record_timing, $timestamp, $unread );
		// Filed under the bucket it COMPLETED in, not the one it started in.
		$bucket                     = Stats_Store::bucket_key( $timestamp );
		$this->pending[ $bucket ] ??= self::empty_bucket();
		$acc                        = &$this->pending[ $bucket ];
		$this->accumulate_url_stats( $acc, $url_hash, $request, $duration_ms, $record_timing, $timestamp, $server_name, $count_global );
		$this->accumulate_hourly( $acc, $request, $duration_ms, $record_timing, $count_global );
		$this->accumulate_dimensions( $acc, $url_hash, $request, $server_key, $duration_ms, $record_timing, $count_global );

		if ( ! empty( $profiles ) && $record_timing ) {
			$this->accumulate_profiles(
				$acc,
				$url_hash,
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
		$this->url_acc->set( $url_hash, $aggregate );
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
		$cached = $this->url_acc->get( $url_hash );
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
	 * @param string                  $url_hash      URL hash of the request.
	 * @param array<array-key,mixed> $request       Full request record.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param int                     $timestamp     Completion time, clamped to now.
	 * @param string                  $server_name   Reporting server, '' when unknown.
	 * @param bool                    $count_global  False for a worker, whose timing this row keeps and every site-wide aggregate drops.
	 */
	private function accumulate_url_stats( array &$acc, string $url_hash, array $request, float $duration_ms, bool $record_timing, int $timestamp, string $server_name, bool $count_global ): void {
		$url_val = $request['url'] ?? '';
		$url     = Core::str( $url_val );
		if ( '' === $url ) {
			return;
		}
		// @longform NOT hub-gated, unlike the three per-server aggregates: the
		// filter is offered wherever the `server` dimension has values, and
		// gating this would empty the URL table on every spoke. A nameless
		// producer is filed as that dimension names it, or the picker offers a
		// name this cannot answer to.
		$server = '' === $server_name ? self::UNKNOWN_VALUE : $server_name;
		// Worker traffic indexes apart, or a URL's reader rows leave with it.
		$slot = $count_global ? 'url_stats' : 'url_stats_worker';
		// @longform array_replace, NOT array_merge: the row is positional,
		// and merge RENUMBERS integer keys rather than overwriting them.
		$acc[ $slot ][ $server ][ $url_hash ] ??= \array_replace(
			self::empty_url_row( PHP_INT_MAX ),
			[ Stats_Store::ROW_PATH => Stats_Store::row_path( $url, $server ) ]
		);
		// The whole URL goes to the URL name table, once, not into every row.
		$acc['url_names'][ $url_hash ] = $url;
		/**
		 * Positional; see `Stats_Store::ROW_*`.
		 *
		 * @var array{0: int, 1: int, 2: float|int, 3: float|int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: float|int, 10: float|int, 11: float|int, 12: int, 13: bool, 14: string} $us
		 */
		$us              = &$acc[ $slot ][ $server ][ $url_hash ];
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
		// Recorded, not read out of the URL text — that guess empties a table.
		$us[ Stats_Store::ROW_WORKER ]    = $us[ Stats_Store::ROW_WORKER ] || ! $count_global;
		if ( $record_timing ) {
			$us[ Stats_Store::ROW_MAX_MS ] = \max( $us[ Stats_Store::ROW_MAX_MS ], $duration_ms );
			$us[ Stats_Store::ROW_MIN_MS ] = \min( $us[ Stats_Store::ROW_MIN_MS ], $duration_ms );
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
	 * @param string                  $url_hash      URL hash of the request.
	 * @param array<array-key,mixed> $request       Full request record.
	 * @param string                  $server_key    Per-server scope, '' to accumulate none.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param bool                    $count_global  Whether this feeds global stats.
	 */
	private function accumulate_dimensions( array &$acc, string $url_hash, array $request, string $server_key, float $duration_ms, bool $record_timing, bool $count_global ): void {
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
			if ( Stats_Store::DIM_SERVER === $dim ) {
				continue;
			}
			$acc['url_dim'][ $url_hash ][ $dim ][ $val ] = self::add_dim( $acc['url_dim'][ $url_hash ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb, $record_timing );
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
	 * @param string                    $url_hash     URL hash of the request.
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
		string $url_hash,
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
			/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string,array<int,float|int>>} $pcat */
			$pcat       = &$prof['categories'][ $category ];
			$pcat['ts'] = \max( $pcat['ts'] ?? 0, $cat_ts );

			// Global leaderboard category: workers excluded.
			$lcat = null;
			if ( $count_global ) {
				$lb['categories'][ $category ] = self::add_category( $lb['categories'][ $category ] ?? null, $cat_time, $cat_count );
				/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string,array<int,float|int>>} $lcat */
				$lcat = &$lb['categories'][ $category ];
			}

			// Per-server leaderboard.
			if ( null !== $slb ) {
				$slb['categories'][ $category ] = self::add_category( $slb['categories'][ $category ] ?? null, $cat_time, $cat_count );
				/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string,array<int,float|int>>} $scat */
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

			$acc['cat_by_url'][ $url_hash ][ $category ] = self::add_cat( $acc['cat_by_url'][ $url_hash ][ $category ] ?? null, $cat_time, $cat_count );

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
		$acc['cat_by_url'][ $url_hash ][ self::TOTAL_KEY ] = self::add_cat( $acc['cat_by_url'][ $url_hash ][ self::TOTAL_KEY ] ?? null, $duration_ms, $total_calls );

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
	 * @param array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string,array<int,float|int>>}|null $slot Bucket, null on first use.
	 * @param float $time  Time to add.
	 * @param float $count Call count to add.
	 * @return array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string,array<int,float|int>>} The updated bucket.
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
	 * Write the pending buckets, then hand the Consumer the crumb and the
	 * data clock to co-commit with its cursor.
	 *
	 * Every pending bucket, the open one too, is written to the Tables or
	 * dropped as decision 3 drops a failed chunk or a refused write, the same
	 * fail-soft loss a periodic flush takes; the carry comes back either way,
	 * and the checkpoint carries no stats. The crumb says which record under
	 * the cursor is already counted (decision 31). The data clock and the
	 * moment it entered its hour describe data this write already drained,
	 * so a successor restoring them folds nothing early, and still folds the
	 * clock's hour at the fine deadline its predecessor started. Only the durable half of a
	 * flush runs here: an auto-tune emit that throws would keep the cursor
	 * from committing, so its decisions wait for the tick. In crawl mode the
	 * Consumer checkpoints every message, so this runs per message until
	 * `CHECKPOINT_INTERVAL_S` of crash-free progress ends the crawl. The
	 * writes run inside the reader's uninterruptible save, so a stop due at
	 * one raises once the cursor commits.
	 *
	 * @api Used by substrate.
	 * @return array{counted: string, data_clock: string, data_clock_hour_since: float}
	 */
	public function save_state(): array {
		$this->write_pending();
		return [
			'counted'               => $this->counted,
			'data_clock'            => $this->data_clock,
			'data_clock_hour_since' => $this->data_clock_hour_since,
		];
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
			'url_names'             => [],
			'cat'                   => [],
			'cat_by_server'         => [],
			'cat_by_url'            => [],
			'leaderboard'           => self::empty_leaderboard(),
			'leaderboard_by_server' => [],
		];
	}

	/**
	 * Flush on a clean stop, ranking everything still owed and waiting out the
	 * auto-tune lock. The substrate runs the sweep before the cursor handoff,
	 * while the graph is intact.
	 *
	 * A sibling partition may hold the auto-tune lock at that moment. A
	 * periodic flush leaves the decisions for the next flush; a stop has none,
	 * so it waits the lock out — the lock expires in seconds on its own. The
	 * rankings the cadence still owes are in memory alone, so this flush
	 * ranks every one of them whatever its stamp. A stale hour gets no more
	 * than a flush's bound: its missing marker is in the store, and the next
	 * worker's roll-up finds it again.
	 *
	 * The sweep runs inside `deferring()`, as a message does, so a stop raised
	 * inside it waits for the flush to finish.
	 *
	 * @api Used by substrate.
	 */
	public function shutdown_sweep(): void {
		$this->deferring(
			fn () => $this->spanned(
				Flame_Tree::STATS_SWEEP,
				function (): void {
					$this->ranked_at = [];
					$this->flush();
					$this->apply_auto_tune( self::AUTO_TUNE_LOCK_WAIT_MS );
					// The last minute's writes would otherwise go untold.
					$this->tell_writes();
				},
				fn (): array => [
					'stopped',
					[
						'ranked'      => \count( $this->ranked_at ),
						'owed'        => \count( $this->rank_pending ),
						'stale hours' => \count( $this->stale_hours ),
					],
				]
			)
		);
	}

	/**
	 * Drain every accumulator and start clean, then emit the auto-tune
	 * decisions.
	 *
	 * `fire()` calls this once per FLUSH_INTERVAL_SEC and every clean stop
	 * through `shutdown_sweep()`; a checkpoint runs `write_pending()` alone.
	 * The accumulators empty BEFORE the auto-tune emit: its sums are written
	 * by then, and a throw there must not hand them to the next flush again.
	 */
	public function flush(): void {
		$this->write_pending();
		$this->apply_auto_tune();
	}

	/**
	 * The durable half of a flush: write every pending bucket and the per-URL
	 * aggregates, and start clean. Every checkpoint runs it before its cursor
	 * commits, so nothing the cursor has passed is left unwritten. What a
	 * fatal costs is a double-count — the deltas between the last checkpoint
	 * and the crash are already in the Tables and get replayed on top (see
	 * the CHANGELOG's Known section).
	 *
	 * With no `Stats_Store` wired the drain is a no-op against storage: the
	 * accumulators still reset, but nothing is written anywhere.
	 *
	 * It reads the tick ONCE and takes that tick's read plan through
	 * `plan_at()`, memoized per bucket. `flush_owed()` asks for it on a tick,
	 * and this method asks on every path; whichever asked first inside the
	 * bucket built it, so the hour plan, the ranking floor and the cadence
	 * agree on which hour closed.
	 */
	private function write_pending(): void {
		$now         = (int) Core::$now;
		$stats_store = $this->stats_store;
		if ( null !== $stats_store ) {
			$plan = $this->plan_at( $stats_store, $now );
			// @longform Roll up FIRST: it is what reads the coarse tier, and
			// `folded_hours` is per-process while one partition has one
			// worker, so a respawn is the only way the memo goes stale.
			// Reading before the writes are placed keeps the first flush after
			// one from leaving rows in fine buckets a folded hour replaced.
			$this->roll_up_hours( $stats_store, $plan, $this->foldable( $stats_store, $plan['hours'], $now ) );
			// Lexical order IS chronological, which is what bucket_key() buys.
			$filed = $this->persist_aggregate_stats( $stats_store, $now, (string) \end( $plan['fine'] ) );
			$this->drain_url_stats( $stats_store, $now, $filed );
		}
		$this->url_acc->flush();
		// A flush with nothing folded is upkeep, which never keeps a worker up.
		if ( [] !== $this->pending ) {
			$this->worked_at    = Core::$now;
			$this->worked_at_hr = Quiet::mark();
			$newest             = \max( \array_map( 'strval', \array_keys( $this->pending ) ) );
			if ( $newest > $this->data_clock ) {
				if ( Stats_Store::hour_of( $newest ) !== Stats_Store::hour_of( $this->data_clock ) ) {
					$this->data_clock_hour_since = Core::$now;
				}
				$this->data_clock = $newest;
			}
		}
		$this->pending = [];
		$this->tally( Flame_Tree::STATS_WRITES, 'flushes' );
		// Once a minute rather than once a flush: a handful a lifetime.
		if ( $this->rollup_due( $now ) ) {
			$this->tell_writes();
		}
	}

	/**
	 * Tell `stats writes`: what the flushes since the last line wrote, the
	 * flushes, writes and unchanged merges first.
	 */
	private function tell_writes(): void {
		$this->rollup( Flame_Tree::STATS_WRITES, [ 'flushes', 'writes', 'unchanged' ] );
	}

	/**
	 * The read plan for `$now`'s bucket, built on the first ask inside it.
	 *
	 * @param Stats_Store $stats_store Whose TTL bounds the window.
	 * @param int         $now         The tick.
	 * @return array{fine: list<string>, hours: list<string>}
	 */
	private function plan_at( Stats_Store $stats_store, int $now ): array {
		$bucket = Stats_Store::bucket_key( $now );
		if ( null === $this->plan_memo || $bucket !== $this->plan_memo['bucket'] ) {
			$this->plan_memo = [
				'bucket' => $bucket,
				'plan'   => Stats_Store::read_plan( Stats_Store::retention_buckets( $stats_store->max_lifespan(), $now ) ),
			];
		}
		return $this->plan_memo['plan'];
	}

	/**
	 * The hours of `$hours` a roll-up may fold: those the data clock has left.
	 *
	 * The data clock is the newest bucket a draining flush wrote, so a replay
	 * folds each hour once, after its records arrive, where the wall clock
	 * would fold it at once and send every later record down the late-write
	 * path. The clock that decides is the one earlier flushes left: this
	 * flush's rows land after the roll-up, so the fold reads them next time.
	 *
	 * A builder that has drained nothing for `Quiet::AFTER_SEC` is idle, and
	 * its clock is the wall, so a quiet builder still folds the hours that
	 * pass. An empty `pending` alone is no sign: a checkpoint drains it, and
	 * a crawl checkpoints every record.
	 *
	 * The clock's own hour also folds once it has held it for all but a
	 * bucket of the fine lifetime, whose first buckets would otherwise expire
	 * unread under a replay slower than that.
	 *
	 * @param Stats_Store  $stats_store Whose window bounds the fine lifetime.
	 * @param list<string> $hours       The read plan's hours, newest first.
	 * @param int          $now         The flush's one read of the tick.
	 * @return list<string>
	 */
	private function foldable( Stats_Store $stats_store, array $hours, int $now ): array {
		if ( [] === $this->pending && Quiet::since( $this->worked_at_hr ) ) {
			return $hours;
		}
		$clock    = Stats_Store::hour_of( $this->data_clock );
		$lifetime = Stats_Store::fine_ttl( $stats_store->max_lifespan() ) - Stats_Store::BUCKET_SECONDS;
		$expiring = $now - $this->data_clock_hour_since >= $lifetime;
		return \array_values( \array_filter(
			$hours,
			static fn ( string $hour ): bool => $hour < $clock || ( $expiring && $hour === $clock )
		) );
	}

	/**
	 * Merge every pending bucket into the stats Tables through `Stats_Store`.
	 *
	 * Each namespace follows the same read-merge-cap-write shape: pull the
	 * existing value, add this worker's sums to it, cap, and set. Merging by
	 * addition is what lets several PARTITIONS write the same bucket without
	 * coordination — hence sums, never means. Not several writers on one
	 * partition: read-then-write is not atomic, and one partition has one worker.
	 * Retention is the key's own TTL, so nothing expires buckets by hand.
	 *
	 * No store means no call: the accumulators are dropped by the caller.
	 *
	 * A late write that landed in an hour key leaves that hour's DONE marker
	 * forgotten and the hour stale before the flush ranks what it owes, so
	 * this flush re-ranks it, the site's lists with its servers'; the marker
	 * is what a stop before that ranking leaves the next worker's roll-up.
	 *
	 * The URL index files each server's rows under the server's own key, so
	 * the flush first reads the server index of every bucket it holds — one
	 * round trip — to decide which servers each bucket admits. A bucket whose
	 * index no Table answered drops its URL rows, index and names, as
	 * a failed write chunk's deltas are dropped (decision 3): admitted against
	 * no index its servers would pass the cap, and held for the Table the
	 * accumulator would grow without bound through an outage. Its other
	 * deltas read no index, so they land, or fail on their own chunk's read.
	 *
	 * @param Stats_Store $stats_store Destination.
	 * @param int         $now         The flush's one read of the tick.
	 * @param string      $floor       The oldest bucket of the read plan's
	 *                                 fine tail: nothing older ranks.
	 * @return array<array-key,array<array-key,string>> The names it filed,
	 *                                                  as `persist_url_names()` returns them.
	 */
	private function persist_aggregate_stats( Stats_Store $stats_store, int $now, string $floor ): array {
		$unfold   = [];
		$indexes  = $stats_store->server_index( [], \array_map( 'strval', \array_keys( $this->pending ) ), $failed );
		$dropped  = $failed ? \array_diff_key( $this->pending, $indexes ) : [];
		if ( [] !== $dropped ) {
			$this->tally( Flame_Tree::STATS_WRITES, 'url buckets dropped', \count( $dropped ) );
			$this->print_less_often( 'stats flush dropped URL rows whose index no Table answered', ' — ' . \count( $dropped ) );
		}
		$admitted = [];
		foreach ( \array_diff_key( $this->pending, $dropped ) as $bucket => $acc ) {
			// @longform An all-digit server name is an INT key wherever PHP
			// stores it, so every name read off a key is cast back to string.
			$admitted[ $bucket ] = Stats_Store::admit_servers(
				$indexes[ $bucket ] ?? [],
				\array_map( 'strval', \array_keys( $acc['url_stats'] + $acc['url_stats_worker'] ) )
			);
		}
		$filed = $this->persist_url_names( $stats_store, $now, $admitted );
		$this->persist_url_tokens( $stats_store, $filed, $now );
		$intents = [];
		foreach ( $this->pending as $bucket => $acc ) {
			// Only a bucket its index answered files URL rows.
			$ranks = $bucket >= $floor;
			foreach ( isset( $admitted[ $bucket ] ) ? $this->url_intents( $bucket, $acc, $indexes[ $bucket ] ?? [], $admitted[ $bucket ], $ranks ) : [] as $intent ) {
				self::add_intent( $intents, $intent );
			}
			// @longform A server new to the bucket may be new to its folded
			// hour, whose index the late write then names beside no hour
			// lists. Every intent is placed first, so the hour leaves the memo
			// only for the next flush's roll-up, which ranks it then.
			foreach ( $admitted[ $bucket ] ?? [] as $as ) {
				if ( ! isset( $indexes[ $bucket ][ Stats_Store::server_key( $as ) ] ) ) {
					$unfold[ Stats_Store::hour_of( $bucket ) ] = true;
				}
			}
			if ( ! empty( $acc['hourly'] ) ) {
				self::add_intent( $intents, self::hourly_intent( $bucket, $acc['hourly'] ) );
			}
			foreach ( $acc['dim'] as $dim => $values ) {
				self::add_intent( $intents, self::dimension_intent( Stats_Store::dim_parts( $dim, '' ), $bucket, $dim, $values, Stats_Store::MAX_DIM_VALUES, null ) );
			}
			foreach ( $acc['dim_by_server'] as $server => $dims ) {
				foreach ( $dims as $dim => $values ) {
					self::add_intent( $intents, self::dimension_intent( Stats_Store::dim_parts( $dim, $server ), $bucket, $dim, $values, Stats_Store::MAX_DIM_VALUES, null ) );
				}
			}
			foreach ( $acc['url_dim'] as $url_hash => $dims ) {
				foreach ( $dims as $dim => $values ) {
					self::add_intent( $intents, self::dimension_intent( Stats_Store::url_dim_parts( (string) $url_hash ), $bucket, $dim, $values, Stats_Store::MAX_URL_DIM_VALUES, $dim ) );
				}
			}
			if ( ! empty( $acc['cat'] ) ) {
				self::add_intent( $intents, self::categories_intent( Stats_Store::cat_parts( '' ), $bucket, $acc['cat'] ) );
			}
			foreach ( $acc['cat_by_server'] as $server => $cats ) {
				self::add_intent( $intents, self::categories_intent( Stats_Store::cat_parts( $server ), $bucket, $cats ) );
			}
			foreach ( $acc['cat_by_url'] as $url_hash => $cats ) {
				self::add_intent( $intents, self::categories_intent( Stats_Store::url_cat_parts( (string) $url_hash ), $bucket, $cats ) );
			}
			$boards = [ '' => $acc['leaderboard'] ] + $acc['leaderboard_by_server'];
			foreach ( $boards as $server => $sums ) {
				if ( ( $sums['count'] ?? 0 ) > 0 ) {
					self::add_intent( $intents, self::leaderboard_intent( $bucket, $sums, $server ) );
				}
			}
		}
		// Only an hour a late write took OFF the memo is read again for it.
		$this->unfolded    += \array_intersect_key( $unfold, $this->folded_hours );
		$this->folded_hours = \array_diff_key( $this->folded_hours, $unfold );
		// @longform Ranked per CHUNK, not after the flush: the collectors hold
		// every merged shard of every bucket still waiting to rank, and
		// a replay spanning the window would hold the whole window at once —
		// which is the memory the chunking exists to bound. A bucket ranks once
		// every one of its server groups has landed.
		$owner = [];
		foreach ( $intents as $intent ) {
			if ( null !== $intent['group'] ) {
				$owner[ $intent['group'] ] = $intent['bucket'];
			}
		}
		$left = \array_count_values( $owner );
		$this->flush_writes( $stats_store, $intents, function ( array $groups ) use ( $stats_store, $now, $floor, $owner, &$left ): void {
			$done = [];
			foreach ( $groups as $group ) {
				$bucket = $owner[ $group ];
				if ( 0 === --$left[ $bucket ] ) {
					$done[] = $bucket;
				}
			}
			$this->rank_flushed_buckets( $stats_store, $done, $now, $floor );
		} );
		// A bucket a failed chunk left short ranks from what the store holds.
		$this->rank_flushed_buckets( $stats_store, \array_map( 'strval', \array_keys( $this->flushed_rows ) ), $now, $floor );
		$this->flushed_index = [];
		$forgets             = [];
		foreach ( $this->unranked_hours as $hour => $keys ) {
			foreach ( \array_keys( $keys ) as $key ) {
				$forgets[] = [ Stats_Store::url_rank_done_parts( (string) $key ), $hour ];
			}
			// Its lists, the site's too, no longer count its rows: rank it now.
			$this->stale_hours[ $hour ] = 'late write';
			$this->tally( Flame_Tree::STATS_WRITES, 'done forgets', \count( $keys ) );
		}
		if ( [] !== $forgets ) {
			$stats_store->bucket_forget_multi( $forgets );
		}
		$this->unranked_hours = [];
		$this->rank_owed( $stats_store, $now, $floor );
		return $filed;
	}

	/**
	 * File each server's written names in the search index: one member per
	 * distinct word of each name's path (`Stats_Store::path_of()`), valued by
	 * the tick the name was written at. Blind adds, one `SADD` per
	 * `WRITE_BATCH_KEYS` sets, because a member needs no read to union.
	 *
	 * @param Stats_Store                              $stats_store Destination.
	 * @param array<array-key,array<array-key,string>> $names       server => hash => URL.
	 * @param int                                      $now         The flush's one read of the tick.
	 */
	private function persist_url_tokens( Stats_Store $stats_store, array $names, int $now ): void {
		$sets = [];
		foreach ( $names as $server => $urls ) {
			$key = Stats_Store::server_key( (string) $server );
			foreach ( Stats_Store::token_sets_of( Stats_Store::paths_of( $urls ) ) as $token => $hashes ) {
				$sets[] = [ $key, (string) $token, $hashes ];
			}
		}
		$this->tally( Flame_Tree::STATS_WRITES, 'tokens', \count( $sets ) );
		foreach ( \array_chunk( $sets, self::WRITE_BATCH_KEYS ) as $chunk ) {
			$refused = \count( \array_keys( $stats_store->add_url_tokens( $chunk, $now ), false, true ) );
			if ( $refused > 0 ) {
				$this->tally( Flame_Tree::STATS_WRITES, 'refused ' . Stats_Store::NS_URLTOKEN, $refused );
				$this->print_less_often( 'token index write refused; ' . $refused . ' word sets left unfiled' );
			}
		}
	}

	/**
	 * One bucket's URL index intents: each family's rows filed under the
	 * server `Stats_Store::admit_servers()` admits them as, one intent per
	 * server per shard, and the index naming each server with the shards it
	 * filed, both families in one mask.
	 *
	 * @param string                              $bucket   Bucket key.
	 * @param Bucket_Acc                          $acc      The bucket's accumulator.
	 * @param array<string,array{0:string,1:int}> $index    The bucket's stored server index.
	 * @param array<string,string>                $admitted Server => the name its rows are filed under.
	 * @param bool                                $ranks    The bucket sits in the fine tail, so it ranks.
	 * @return list<Pending_Write>
	 */
	private function url_intents( string $bucket, array $acc, array $index, array $admitted, bool $ranks ): array {
		$entries = [];
		$out     = [];
		foreach ( [ 0 => $acc['url_stats'], 1 => $acc['url_stats_worker'] ] as $worker => $servers ) {
			$filed = [];
			foreach ( $servers as $server => $rows ) {
				$as           = $admitted[ (string) $server ];
				$rows         = self::refile_rows( $rows, (string) $server, $as );
				$filed[ $as ] = isset( $filed[ $as ] ) ? self::merge_url_rows( $filed[ $as ], $rows ) : $rows;
			}
			foreach ( $filed as $as => $rows ) {
				$name   = \strval( $as );
				$shards = Stats_Store::rows_by_shard( $rows, 1 === $worker );
				foreach ( $shards as $shard => $shard_rows ) {
					\array_push( $out, ...$this->url_shard_intent( $bucket, $name, \strval( $shard ), $shard_rows, $ranks ) );
				}
				$entries = Stats_Store::merge_index( $entries, [
					Stats_Store::server_key( $name ) => [
						Stats_Store::SRV_NAME   => $name,
						Stats_Store::SRV_SHARDS => Stats_Store::shard_mask( \array_map( 'strval', \array_keys( $shards ) ) ),
					],
				] );
			}
		}
		if ( [] === $entries ) {
			return [];
		}
		$this->flushed_index[ $bucket ] = Stats_Store::merge_index( $index, $entries );
		return [ ...$out, ...$this->url_srv_intents( $bucket, $entries ) ];
	}

	/**
	 * How one bucket's newly filed servers join its stored index: a union of
	 * names and shards, through the same tier choice the rows make.
	 *
	 * @param string                              $bucket  Bucket key.
	 * @param array<string,array{0:string,1:int}> $entries server_key => entry.
	 * @return list<Pending_Write>
	 */
	private function url_srv_intents( string $bucket, array $entries ): array {
		return $this->hour_tier_intents(
			$bucket,
			Stats_Store::url_srv_parts( false ),
			Stats_Store::url_srv_parts( true ),
			static fn ( array $existing ): array => Stats_Store::merge_index( Stats_Store::index_entries( $existing ), $entries ),
			function ( string $key ): void {
				$this->print_less_often( 'URL server index write refused; no reader or hour fold finds the rows it names', " — {$key}" );
			},
			null
		);
	}

	/**
	 * Offer every bucket this chunk finished writing to the ranker, and drop
	 * all of them from the collectors.
	 *
	 * A bucket that is not due keeps nothing: its rows are in the store, the
	 * ranker remembers it as pending, and the ranking that does happen
	 * gap-fills them back with everything else that landed meanwhile. A
	 * bucket of worker rows alone collected nothing and is not offered, and
	 * neither is one below the fine floor, which no reader plans.
	 *
	 * @param Stats_Store  $stats_store Source and destination.
	 * @param list<string> $buckets     Buckets whose row writes have all landed.
	 * @param int          $now         The flush's one read of the tick.
	 * @param string       $floor       The read plan's oldest fine bucket.
	 */
	private function rank_flushed_buckets( Stats_Store $stats_store, array $buckets, int $now, string $floor ): void {
		$this->rank_buckets(
			$stats_store,
			\array_values( \array_filter(
				$buckets,
				fn ( string $bucket ): bool => $bucket >= $floor && isset( $this->flushed_rows[ $bucket ] )
			) ),
			$now
		);
		$drop                = \array_flip( $buckets );
		$this->flushed_rows  = \array_diff_key( $this->flushed_rows, $drop );
		$this->flushed_index = \array_diff_key( $this->flushed_index, $drop );
	}

	/**
	 * Prune both bucket memos below the fine floor — the oldest bucket the
	 * read plan's fine tail reads — then offer every deferred bucket left and
	 * the stale hours to the ranker.
	 *
	 * Runs at the end of every flush, because a deferred bucket comes due by
	 * the CLOCK: once it closes nothing writes into it again, so no chunk
	 * ever completes for it and `rank_flushed_buckets()` is never reached.
	 * A flush that places no write at all still runs this. Pruning first
	 * keeps a bucket no reader plans from being ranked. A stale hour has
	 * no cadence: at most `ROLLUP_HOURS_PER_FLUSH` of them rank per flush,
	 * a stop's included, and the rest wait for the next flush or the next
	 * worker's roll-up, which finds each missing marker again.
	 *
	 * @param Stats_Store $stats_store Source and destination.
	 * @param int         $now         The flush's one read of the tick.
	 * @param string      $floor       The read plan's oldest fine bucket.
	 */
	private function rank_owed( Stats_Store $stats_store, int $now, string $floor ): void {
		foreach ( \array_keys( $this->ranked_at + $this->rank_pending ) as $key ) {
			if ( $key < $floor ) {
				unset( $this->ranked_at[ $key ], $this->rank_pending[ $key ] );
			}
		}
		$this->rank_buckets( $stats_store, \array_keys( $this->rank_pending ), $now );
		$this->rank_hours_from_store(
			$stats_store,
			\array_slice( \array_keys( $this->stale_hours ), 0, self::ROLLUP_HOURS_PER_FLUSH )
		);
	}

	/**
	 * Rank each bucket that is DUE, a write chunk's worth of ranking groups
	 * at a time, so the rows held at once stay what one flush chunk holds.
	 *
	 * Every offered bucket is pending until it is ranked, and a ranking is
	 * stamped and ends it whether its lists landed or were refused; a refusal
	 * is logged and never retried. `rank_due()` decides which rank now. A
	 * bucket's index entries come from what this flush filed, else from its
	 * stored index, read for every such bucket in one round trip. A bucket
	 * whose index a Table left unanswered stays pending and unstamped.
	 *
	 * @param Stats_Store  $stats_store Source and destination.
	 * @param list<string> $buckets     Buckets offered for ranking.
	 * @param int          $now         The caller's one read of the tick, for
	 *                                  the due test and the stamp.
	 */
	private function rank_buckets( Stats_Store $stats_store, array $buckets, int $now ): void {
		$due     = [];
		$current = Stats_Store::bucket_key( $now );
		foreach ( $buckets as $bucket ) {
			$this->rank_pending[ $bucket ] = true;
			if ( $this->rank_due( $bucket, $now, $current ) ) {
				$due[] = $bucket;
			}
		}
		$unknown = \array_values( \array_diff( $due, \array_map( 'strval', \array_keys( $this->flushed_index ) ) ) );
		$failed  = false;
		$read    = [] === $unknown ? [] : $stats_store->server_index( [], $unknown, $failed );
		// A bucket whose index went unanswered is no bucket to rank empty.
		$due     = $failed ? \array_values( \array_diff( $due, \array_diff( $unknown, \array_keys( $read ) ) ) ) : $due;
		$indexes = $this->flushed_index + $read;
		// A group is a (server, bucket) pair: one read a shard, either family.
		$budget = \intdiv( self::WRITE_BATCH_KEYS, \count( Stats_Store::every_shard() ) );
		$chunk  = [];
		$size   = 0;
		foreach ( $due as $bucket ) {
			$entries = $indexes[ $bucket ] ?? [];
			if ( [] !== $chunk && $size + \count( $entries ) > $budget ) {
				$this->rank_bucket_chunk( $stats_store, $chunk, $now );
				$chunk = [];
				$size  = 0;
			}
			$chunk[ $bucket ] = $entries;
			$size            += \max( 1, \count( $entries ) );
		}
		if ( [] !== $chunk ) {
			$this->rank_bucket_chunk( $stats_store, $chunk, $now );
		}
	}

	/**
	 * Rank each bucket over its whole STORED content: the rows of both
	 * families this flush landed, plus every other shard each index entry
	 * names, read back for the whole chunk in ONE round trip — a read per
	 * bucket would be a round trip per bucket on a replay — and the chunk's
	 * lists written in its write batches, told as one `stats rank close`
	 * span. A deferred bucket landed none, so it gap-fills every shard its
	 * entries name. A bucket with a shard a Table left
	 * unanswered ranks nothing and stays pending, since its rows are short
	 * (decision 3).
	 *
	 * @param Stats_Store                                        $stats_store Source and destination.
	 * @param array<string,array<string,array{0:string,1:int}>> $chunk       Due bucket => its index
	 *                                                                        entries, at most one write
	 *                                                                        chunk's worth of ranking groups.
	 * @param int                                                $now         The caller's one read of the tick.
	 */
	private function rank_bucket_chunk( Stats_Store $stats_store, array $chunk, int $now ): void {
		$reads = [];
		$owner = [];
		foreach ( $chunk as $bucket => $entries ) {
			foreach ( $entries as $key => [ Stats_Store::SRV_NAME => $server, Stats_Store::SRV_SHARDS => $mask ] ) {
				$landed = $this->flushed_rows[ $bucket ][ $server ] ?? [];
				foreach ( \array_diff( Stats_Store::shards_in( $mask, true ), \array_keys( $landed ) ) as $shard ) {
					$reads[] = [ Stats_Store::url_shard_parts( $key, $shard ), $bucket ];
					$owner[] = [ $bucket, $server, $shard ];
				}
			}
		}
		$read_maps = [];
		$short     = [];
		$failed    = false;
		foreach ( [] === $reads ? [] : $stats_store->bucket_get_multi( $reads, $failed ) as $at => $value ) {
			[ $bucket, $server, $shard ] = $owner[ $at ];
			if ( null !== $value ) {
				$read_maps[ $bucket ][ $server ][ $shard ] = $value;
			} elseif ( $failed ) {
				$short[ $bucket ] = true;
			}
		}
		$ranked = [];
		foreach ( \array_diff_key( $chunk, $short ) as $bucket => $entries ) {
			foreach ( Stats_Store::index_names( $entries ) as $server ) {
				// Disjoint: the gap-fill read the shards that did not land.
				$ranked[ $bucket ][ $server ] = ( $this->flushed_rows[ $bucket ][ $server ] ?? [] ) + ( $read_maps[ $bucket ][ $server ] ?? [] );
			}
			$ranked[ $bucket ] ??= [];
		}
		if ( [] === $ranked ) {
			return;
		}
		$keys = \array_map( 'strval', \array_keys( $ranked ) );
		$this->spanned(
			Flame_Tree::STATS_RANK_CLOSE,
			fn (): array => $this->write_url_ranks( $stats_store, $ranked, false, false ),
			static fn ( array $ranks ): array => [
				1 === \count( $keys ) ? $keys[0] : \min( $keys ) . '..' . \max( $keys ),
				[
					'buckets'   => \count( $keys ),
					'servers'   => \array_sum( \array_map( 'count', $ranked ) ),
					'rows'      => \array_sum( \array_map( self::rows_in( ... ), $ranked ) ),
					'gap reads' => \count( $reads ),
				] + $ranks,
			],
			// The open bucket's rankings are the summary's to count.
			\min( $keys ) < Stats_Store::bucket_key( $now )
		);
		foreach ( $keys as $ranked_bucket ) {
			$this->tally( Flame_Tree::STATS_WRITES, 'buckets ranked' );
			$this->ranked_at[ $ranked_bucket ] = $now;
			unset( $this->rank_pending[ $ranked_bucket ] );
		}
	}

	/**
	 * Whether a bucket is due for ranking.
	 *
	 * Due when it was never ranked, when its stamp is
	 * `Stats_Store::URL_PAGE_REFRESH_S` old — the page cache holds a ranked
	 * page that long, so ranking every five-second flush spends twelve
	 * rankings on one read — or when the bucket has closed since it was
	 * last ranked; `shutdown_sweep()` empties the stamps, so everything is
	 * due before a stop. A closed bucket is what the reader reads for the
	 * rest of the window, so its last writes must reach its lists rather
	 * than wait out the cadence.
	 *
	 * @param string $key     Bucket key.
	 * @param int    $now     The caller's one read of the tick.
	 * @param string $current The bucket `$now` falls in, which the caller
	 *                        computes once for every key it tests.
	 */
	private function rank_due( string $key, int $now, string $current ): bool {
		$stamp = $this->ranked_at[ $key ] ?? null;
		return null === $stamp
			|| $now - $stamp >= Stats_Store::URL_PAGE_REFRESH_S
			|| ( $key < $current && Stats_Store::bucket_key( $stamp ) <= $key );
	}

	/**
	 * Store the names of URLs this flush saw, and file their words, at most
	 * once an hour per server they are filed under.
	 *
	 * The name table is what a reader displays a row's whole URL through,
	 * and what a hash-only read finds the row's server by. Each name also
	 * files the search index of the server its rows are filed under — its
	 * own, or `Other` past the index cap. A path costs one member per
	 * distinct word: two for `/wombat-7731`, five for
	 * `/blog/2026/my-post-title`.
	 *
	 * A name never changes and a member lives the retention window from its
	 * last add, so re-filing them every flush would only refresh their
	 * expiry. Each URL's blob carries `filed`: server key => the hour the
	 * URL was last filed under it. A pair stamped with the current hour is
	 * skipped, and `drain_url_stats()` stamps the ones this returns into the
	 * blob it writes anyway, so the rule outlives the worker and costs no
	 * read and no write of its own. A blob without the stamp, or a URL whose
	 * blob is not held, reads as never filed. So a URL's last filing can come
	 * up to an hour and a flush before its last row, which is why its words
	 * and name live one refresh past what their readers read
	 * (`Stats_Store::filing_ttl()`). A hash filed under two
	 * servers in one flush keeps the last name written.
	 *
	 * @param Stats_Store                        $stats_store The wired store.
	 * @param int                                $now         The flush's one read of the tick.
	 * @param array<string,array<string,string>> $admitted    Bucket => server => the
	 *                                                        name its rows are filed under.
	 * @return array<array-key,array<array-key,string>> server => hash => URL, for the names written.
	 */
	private function persist_url_names( Stats_Store $stats_store, int $now, array $admitted ): array {
		$hour    = Stats_Store::hour_of( Stats_Store::bucket_key( $now ) );
		$stamped = [];
		// Iterated, not got: a get promotes, and a rotation evicts undrained.
		foreach ( $this->url_acc->iterate() as $hash => $blob ) {
			$stamped[ (string) $hash ] = Core::arr( Core::arr( $blob )['filed'] ?? null );
		}
		$filed = [];
		foreach ( \array_intersect_key( $this->pending, $admitted ) as $bucket => $acc ) {
			foreach ( [ $acc['url_stats'], $acc['url_stats_worker'] ] as $servers ) {
				foreach ( $servers as $server => $rows ) {
					$as  = $admitted[ $bucket ][ (string) $server ];
					$key = Stats_Store::server_key( $as );
					foreach ( \array_keys( $rows ) as $hash ) {
						if ( ! isset( $acc['url_names'][ $hash ] ) ) {
							continue;
						}
						if ( $hour !== ( $stamped[ (string) $hash ][ $key ] ?? null ) ) {
							$filed[ $as ][ (string) $hash ] = $acc['url_names'][ $hash ];
						}
					}
				}
			}
		}
		$landed = $stats_store->set_url_names( $filed );
		$this->tally( Flame_Tree::STATS_WRITES, 'names', \array_sum( \array_map( 'count', $filed ) ) );
		$this->tally( Flame_Tree::STATS_WRITES, 'refused ' . Stats_Store::NS_URLMAP, \count( \array_keys( $landed, false, true ) ) );
		return $filed;
	}

	/**
	 * Read, merge and write every pending key in batches.
	 *
	 * The flush's cost is its KEY COUNT, not its bytes: two of the loops above
	 * are per URL, so the fuller the retention window, the more round trips a
	 * replay costs. The reader batches through `lookup_bucket_sets()`; this is
	 * the write half. Chunked because batching trades round trips for held
	 * memory, and a full-window read peaks near 160MB — the prize is thousands
	 * of round trips becoming a handful, not becoming one. Still one read and
	 * one write per chunk: intents sharing an ITEM are folded into a single
	 * write, and every one of them hears the result.
	 *
	 * A chunk whose batch read FAILED writes nothing at all (decision 3):
	 * merged onto a read that never happened, every write would replace a
	 * stored bucket with this flush's delta alone. Its deltas are dropped.
	 *
	 * @param Stats_Store                 $stats_store Destination.
	 * @param array<string,Pending_Write> $intents     Pending writes, by cache key.
	 * @param ?\Closure(list<string>): void $after_chunk Called with the ranking
	 *                                                 groups the chunk completed.
	 */
	private function flush_writes( Stats_Store $stats_store, array $intents, ?\Closure $after_chunk = null ): void {
		$this->tally( Flame_Tree::STATS_WRITES, 'intents', \count( $intents ) );
		foreach ( self::chunk_intents( $intents ) as $chunk ) {
			$reads = [];
			foreach ( $chunk as $key => $intent ) {
				$reads[ $key ] = [ $intent['parts'], $intent['bucket'] ];
			}
			$existing = $stats_store->bucket_get_multi( $reads, $failed );
			$this->tally( Flame_Tree::STATS_WRITES, 'reads' );
			if ( $failed ) {
				$this->tally( Flame_Tree::STATS_WRITES, 'failed chunks' );
				$this->tally( Flame_Tree::STATS_WRITES, 'dropped keys', \count( $chunk ) );
				$this->print_less_often( 'stats flush read failed; a chunk\'s deltas are dropped', ' — ' . \count( $chunk ) . ' keys' );
				continue;
			}
			$writes   = [];
			$groups   = [];
			foreach ( $chunk as $key => $intent ) {
				if ( null !== $intent['group'] ) {
					$groups[ $intent['group'] ] = true;
				}
				$read  = $existing[ $key ] ?? [];
				$value = ( $intent['merge'] )( $read );
				// @longform A merge that changed nothing is not a write: it
				// would cost a round trip and refresh a TTL the value has not
				// earned, so a key nothing adds to ages out on the one it has.
				if ( $value === $read ) {
					$this->tally( Flame_Tree::STATS_WRITES, 'unchanged' );
					( $intent['landed'] ?? null )?->__invoke( $value );
					continue;
				}
				$writes[ $key ] = [ $intent['parts'], $intent['bucket'], $value ];
			}
			$keys = \array_keys( $writes );
			$this->tally( Flame_Tree::STATS_WRITES, 'writes', \count( $writes ) );
			foreach ( $stats_store->bucket_set_multi( \array_values( $writes ) ) as $at => $landed ) {
				$key    = $keys[ $at ];
				$intent = $chunk[ $key ];
				if ( $landed ) {
					( $intent['landed'] ?? null )?->__invoke( $writes[ $key ][2] );
					continue;
				}
				$this->tally( Flame_Tree::STATS_WRITES, "refused {$intent['parts'][0]}" );
				( $intent['refused'] ?? null )?->__invoke( $key );
			}
			if ( null !== $after_chunk ) {
				$after_chunk( \array_keys( $groups ) );
			}
		}
	}

	/**
	 * Cut the intents into write batches without splitting a ranking GROUP.
	 *
	 * A group is one server's row writes in one bucket, both families —
	 * thirty-two keys — and it ranks when its last one is answered, so a group
	 * split across chunks would rank the bucket twice and read back every
	 * shard the second chunk still held.
	 *
	 * @param array<string,Pending_Write> $intents Pending writes, by cache key.
	 * @return list<array<string,Pending_Write>>
	 */
	private static function chunk_intents( array $intents ): array {
		$units = [];
		foreach ( $intents as $key => $intent ) {
			$unit                   = null === $intent['group'] ? "key\0{$key}" : "group\0{$intent['group']}";
			$units[ $unit ][ $key ] = $intent;
		}
		$chunks = [];
		$chunk  = [];
		foreach ( $units as $unit ) {
			if ( [] !== $chunk && \count( $chunk ) + \count( $unit ) > self::WRITE_BATCH_KEYS ) {
				$chunks[] = $chunk;
				$chunk    = [];
			}
			$chunk += $unit;
		}
		if ( [] !== $chunk ) {
			$chunks[] = $chunk;
		}
		return $chunks;
	}

	/**
	 * Fold every hour the data clock has left that has not been folded yet
	 * into its coarse key.
	 *
	 * The readers distinguish two resolutions — the whole window, and the last
	 * complete hour — so five-minute buckets behind the recent tail are read at
	 * a granularity nothing asks for. Folding them to hours takes a read from
	 * 288 keys per shard to 36. It runs on flush, bounded to
	 * `ROLLUP_HOURS_PER_FLUSH` so a cold start backfills over several flushes
	 * instead of stalling one, and it fills the whole window rather than only
	 * the hour that just closed — which is what makes a fresh deploy reach the
	 * cheap read without a 24-hour ramp.
	 *
	 * A fold is idempotent because it OVERWRITES from the fine buckets. Adding
	 * into an hour incrementally would double-count every re-flush. It writes
	 * the hour's ranked lists in the same pass, from the shard rows it just
	 * folded, and each server's DONE marker once every write landed. An hour
	 * holding an index with some server unmarked, as `url_hours_derived()`
	 * reports it, is not folded here but joins `stale_hours`: the ranking
	 * that settles it reads every shard the index names, and folds the hour
	 * again where one is missing. One with every server marked is settled.
	 * Only a fold spends the fold budget; a stale hour spends none, and a
	 * spent budget stops the folds but never the reading, so every hour found
	 * folded is memoized in the same flush.
	 *
	 * Only an hour of `$foldable` folds. The rest of the plan is still read,
	 * so a respawn adopts what its predecessor folded before it writes, but
	 * an unfolded hour the data clock has not left waits: its records may
	 * still arrive, and folding it now would send every one of them down
	 * the late-write path. An hour read holding no index is not read again
	 * until it folds (`$absent_hours`).
	 *
	 * @param Stats_Store                                    $stats_store Source and destination.
	 * @param array{fine: list<string>, hours: list<string>} $plan        The flush's read plan.
	 * @param list<string>                                   $foldable    The plan hours `foldable()` names.
	 */
	public function roll_up_hours( Stats_Store $stats_store, array $plan, array $foldable ): void {
		// Drop what left the window, so the memo cannot outgrow it.
		$planned            = \array_flip( $plan['hours'] );
		$this->folded_hours = \array_intersect_key( $this->folded_hours, $planned );
		// No reader plans an hour the window passed; its lists are owed no one.
		$this->stale_hours = \array_intersect_key( $this->stale_hours, $planned );
		$this->unfolded     = \array_intersect_key( $this->unfolded, $planned );
		$this->absent_hours = \array_intersect_key( $this->absent_hours, $planned );
		$owed               = \array_diff( $plan['hours'], \array_keys( $this->folded_hours ) );
		$unknown            = \array_values( \array_diff( $owed, \array_keys( $this->absent_hours ) ) );
		// Only for hours this process did not fold: an index and its markers.
		$failed = false;
		$found  = [] === $unknown ? [] : $stats_store->url_hours_derived( $unknown, $failed );
		$budget = self::ROLLUP_HOURS_PER_FLUSH;
		$ripe   = \array_flip( $foldable );
		// A settled hour it could not see would read unfolded: ask next flush.
		if ( $failed ) {
			return;
		}
		$this->tell_unfold( $unknown, $found );
		foreach ( $owed as $hour ) {
			// @longform Folded with a server unmarked: one whose fold lost a
			// write, or one a late write landed in. Its fine buckets may be
			// gone, so folding again here could overwrite it with less; its
			// lists come from the coarse rows, which is what they are derived
			// from anyway, and that ranking checks the rows are whole.
			if ( \array_key_exists( $hour, $found ) ) {
				if ( [] !== $found[ $hour ] ) {
					// A late write that forgot the marker is the truer cause.
					$this->stale_hours[ $hour ] ??= 'DONE missing';
				}
				$this->folded_hours[ $hour ] = true;
				continue;
			}
			$this->absent_hours[ $hour ] = true;
			// Past the budget, or ahead of the data clock, a fold waits.
			if ( $budget <= 0 || ! isset( $ripe[ $hour ] ) ) {
				continue;
			}
			--$budget;
			// A crash between shards leaves no index, so the hour folds again.
			$fold = $this->spanned(
				Flame_Tree::STATS_FOLD,
				fn (): ?array => $this->fold_hour_into_store( $stats_store, $hour, false ),
				static fn ( ?array $fold ): array => [ "{$hour}: missing index", $fold ?? [ 'unanswered' => true ] ]
			);
			if ( null === $fold ) {
				continue;
			}
			$this->folded_hours[ $hour ] = true;
			unset( $this->absent_hours[ $hour ] );
		}
	}

	/**
	 * Say that the roll-up read again hours a late write took off the fold
	 * memo, as a `stats probe` line with the `unfold` trigger: how many, and
	 * what the read found of them.
	 *
	 * @param list<string>               $unknown The hours it read.
	 * @param array<string,list<string>> $found   What it found: hour => servers unmarked.
	 */
	private function tell_unfold( array $unknown, array $found ): void {
		$again = \array_intersect_key( $this->unfolded, \array_flip( $unknown ) );
		if ( [] === $again ) {
			return;
		}
		$this->unfolded = \array_diff_key( $this->unfolded, $again );
		$this->narrate(
			Flame_Tree::STATS_PROBE,
			static function () use ( $again, $found ): array {
				// Unfound is unfolded; it folds once the clock has left it.
				$unfolded = 0;
				$stale    = 0;
				foreach ( \array_keys( $again ) as $hour ) {
					if ( ! \array_key_exists( $hour, $found ) ) {
						++$unfolded;
					} elseif ( [] !== $found[ $hour ] ) {
						++$stale;
					}
				}
				return [
					'hour derive (unfold)',
					[
						'hours'    => \count( $again ),
						'unfolded' => $unfolded,
						'stale'    => $stale,
					],
				];
			}
		);
	}

	/**
	 * Rank each stale hour of `$hours` from the coarse rows it already holds,
	 * every server its index names, `ROLLUP_HOURS_PER_FLUSH` hours at a time.
	 *
	 * Each chunk reads every shard the index names, both families, in ONE
	 * round trip after the index's own: a read per hour is a round trip per
	 * hour on a replay. The index seeds every server it names, one of worker
	 * rows alone included, whose ranking is empty lists and the DONE marker
	 * that settles its hour. An hour missing a shard its index names holds
	 * short rows, so it folds again from the fine tier rather than settling
	 * short. Each ranked or folded hour leaves the stale set; a chunk whose
	 * read a Table left unanswered does nothing and stays stale (decision 3).
	 *
	 * @param Stats_Store  $stats_store Source and destination.
	 * @param list<string> $hours       Hour keys.
	 */
	private function rank_hours_from_store( Stats_Store $stats_store, array $hours ): void {
		foreach ( \array_chunk( $hours, self::ROLLUP_HOURS_PER_FLUSH ) as $chunk ) {
			$index = $stats_store->server_index( $chunk, [], $index_failed );
			$rows  = [];
			$reads = [];
			$owner = [];
			foreach ( $index as $hour => $entries ) {
				foreach ( $entries as $key => [ Stats_Store::SRV_NAME => $server, Stats_Store::SRV_SHARDS => $mask ] ) {
					$rows[ $hour ][ $server ] = [];
					foreach ( Stats_Store::shards_in( $mask, true ) as $shard ) {
						$reads[] = [ Stats_Store::url_hour_parts( $key, $shard ), $hour ];
						$owner[] = [ $hour, $server, $shard ];
					}
				}
			}
			$values = $stats_store->bucket_get_multi( $reads, $rows_failed );
			// Ranked from rows it could not read, the hour would settle short.
			if ( $index_failed || Stats_Store::unanswered( $values, $rows_failed ) ) {
				continue;
			}
			$short = [];
			foreach ( $values as $at => $value ) {
				[ $hour, $server, $shard ] = $owner[ $at ];
				if ( null === $value ) {
					$short[ $hour ] = true;
				} else {
					$rows[ $hour ][ $server ][ $shard ] = $value;
				}
			}
			foreach ( $chunk as $hour ) {
				if ( isset( $short[ $hour ] ) ) {
					$fold = $this->spanned(
						Flame_Tree::STATS_FOLD,
						fn (): ?array => $this->fold_hour_into_store( $stats_store, $hour, true ),
						static fn ( ?array $fold ): array => [ "{$hour}: missing shard", $fold ?? [ 'unanswered' => true ] ]
					);
					if ( null !== $fold ) {
						unset( $this->stale_hours[ $hour ] );
					}
					continue;
				}
				$servers = $rows[ $hour ] ?? [];
				$cause   = $this->stale_hours[ $hour ] ?? 'stale';
				$this->spanned(
					Flame_Tree::STATS_RE_RANK,
					fn (): array => $this->write_url_ranks( $stats_store, [ $hour => $servers ], true, true ),
					static fn ( array $ranks ): array => [
						"{$hour}: {$cause}",
						[
							'servers' => \count( $servers ),
							'rows'    => self::rows_in( $servers ),
							'writes'  => $ranks['writes'],
							'refused' => $ranks['refused'],
						],
					]
				);
				unset( $this->stale_hours[ $hour ] );
			}
		}
	}

	/**
	 * Fold one hour's fine buckets into its coarse rows, server index and
	 * lists.
	 *
	 * Every server the hour's buckets name is folded apart from the shards
	 * each bucket's index names for it, and every shard of both families is
	 * written for it, empty or not, so the hour's index names all of them
	 * and the roll-up can tell a folded hour from a missing key. The rows
	 * arrive in one round trip per write chunk however many servers the
	 * hour holds.
	 *
	 * A refused write is logged and not retried by the flush. The caller
	 * memoizes the hour folded, and the fold writes no DONE marker, so the
	 * next worker's roll-up finds the hour owed and the ranking that settles
	 * it, missing a shard, folds it again. A read a Table leaves
	 * unanswered — the index or a row shard — folds nothing (decision 3):
	 * folded short, the hour would read settled with its rows lost, so it
	 * stays unfolded for the next flush. An hour that holds an index and
	 * whose buckets now name no server writes nothing: its fine tier has
	 * expired, and overwriting from it would empty the index, the rows and
	 * the lists the hour still holds.
	 *
	 * @param Stats_Store $stats_store Source and destination.
	 * @param string      $hour        Hour key.
	 * @param bool        $held        The hour holds an index, so it was folded before.
	 * @return array{servers: int, rows: int, writes: int, refused: int}|null What
	 *         the fold wrote, for its `stats fold` span; null where it folded nothing.
	 */
	private function fold_hour_into_store( Stats_Store $stats_store, string $hour, bool $held ): ?array {
		$shards  = Stats_Store::every_shard();
		$names   = [];
		$reads   = [];
		$owner   = [];
		$indexes = $stats_store->server_index( [], Stats_Store::buckets_in_hour( $hour ), $failed );
		if ( $failed ) {
			return null;
		}
		foreach ( $indexes as $bucket => $entries ) {
			foreach ( $entries as $key => [ Stats_Store::SRV_NAME => $server, Stats_Store::SRV_SHARDS => $mask ] ) {
				$names[ $key ] ??= $server;
				foreach ( Stats_Store::shards_in( $mask, true ) as $shard ) {
					$reads[] = [ Stats_Store::url_shard_parts( $key, $shard ), $bucket ];
					$owner[] = [ $names[ $key ], $shard ];
				}
			}
		}
		if ( $held && [] === $names ) {
			return [ 'servers' => 0, 'rows' => 0, 'writes' => 0, 'refused' => 0 ];
		}
		$rows = \array_fill_keys( \array_values( $names ), [] );
		foreach ( \array_chunk( $reads, self::WRITE_BATCH_KEYS, true ) as $chunk ) {
			$values = $stats_store->bucket_get_multi( $chunk, $chunk_failed );
			if ( Stats_Store::unanswered( $values, $chunk_failed ) ) {
				return null;
			}
			foreach ( $values as $at => $value ) {
				if ( null !== $value ) {
					[ $server, $shard ]        = $owner[ $at ];
					$rows[ $server ][ $shard ] = self::merge_url_rows( $rows[ $server ][ $shard ] ?? [], $value );
				}
			}
		}
		$writes = [];
		$ranked = [];
		$named  = [];
		$every  = Stats_Store::shard_mask( $shards );
		foreach ( self::cap_hour_servers( $rows ) as $server => $server_rows ) {
			$key           = Stats_Store::server_key( (string) $server );
			$named[ $key ] = [ Stats_Store::SRV_NAME => (string) $server, Stats_Store::SRV_SHARDS => $every ];
			// @longform Per SHARD, and not regroupable: each shard carries its
			// own `Other` overflow row, which a merge by hash would collapse.
			foreach ( $shards as $shard ) {
				// Capped ONCE, after twelve buckets rather than after each.
				$shard_rows = self::cap_url_rows( $server_rows[ $shard ] ?? [] );
				$writes[]   = [ Stats_Store::url_hour_parts( $key, $shard ), $hour, $shard_rows ];
				$ranked[ (string) $server ][ $shard ] = $shard_rows;
			}
		}
		$writes[] = [ Stats_Store::url_srv_parts( true ), $hour, $named ];
		$refused  = 0;
		foreach ( \array_chunk( $writes, self::WRITE_BATCH_KEYS ) as $chunk ) {
			// @longform The batch answers per write, so a refusal names the
			// KEY that was lost rather than the hour holding its candidates.
			foreach ( $stats_store->bucket_set_multi( $chunk ) as $at => $ok ) {
				if ( $ok ) {
					continue;
				}
				++$refused;
				$this->print_less_often(
					'hour fold write refused; a shard is lost',
					' — ' . Stats_Store::key_at( $chunk[ $at ][0], $chunk[ $at ][1] )
				);
			}
		}
		// A marker stands for every shard, so a fold that lost one writes none.
		$ranks = $this->write_url_ranks( $stats_store, [ $hour => $ranked ], true, 0 === $refused );
		return [
			'servers' => \count( $named ),
			'rows'    => self::rows_in( $ranked ),
			'writes'  => \count( $writes ) + $ranks['writes'],
			'refused' => $refused + $ranks['refused'],
		];
	}

	/**
	 * One hour's servers, bounded as a bucket's index is: past
	 * `MAX_SERVER_VALUES` the quietest fold, shard by shard, into the `Other`
	 * server, whose rows keep every request.
	 *
	 * @param array<array-key,array<string,array<array-key,mixed>>> $rows Server => shard => merged rows.
	 * @return array<array-key,array<string,array<array-key,mixed>>>
	 */
	private static function cap_hour_servers( array $rows ): array {
		$traffic = [];
		foreach ( $rows as $server => $shards ) {
			if ( Stats_Store::OTHER_KEY === (string) $server ) {
				continue;
			}
			$traffic[ $server ] = 0;
			foreach ( $shards as $shard_rows ) {
				$traffic[ $server ] += \array_sum( \array_map(
					static fn ( $row ): int => Core::num_int( Core::arr( $row )[ Stats_Store::ROW_COUNT ] ?? null ),
					$shard_rows
				) );
			}
		}
		if ( \count( $traffic ) <= Stats_Store::MAX_SERVER_VALUES ) {
			return $rows;
		}
		\arsort( $traffic );
		foreach ( \array_slice( \array_keys( $traffic ), Stats_Store::MAX_SERVER_VALUES ) as $server ) {
			foreach ( $rows[ $server ] as $shard => $shard_rows ) {
				$shard_rows                               = self::refile_rows( $shard_rows, (string) $server, Stats_Store::OTHER_KEY );
				$rows[ Stats_Store::OTHER_KEY ][ $shard ] = self::merge_url_rows( $rows[ Stats_Store::OTHER_KEY ][ $shard ] ?? [], $shard_rows );
			}
			unset( $rows[ $server ] );
		}
		return $rows;
	}

	/**
	 * Rows moving from one server's key to another's, each path re-cut for
	 * the server it now sits under.
	 *
	 * @param array<array-key,mixed> $rows Rows by url_hash.
	 * @param string                 $from The server they were filed under.
	 * @param string                 $to   The server they are filed under now.
	 * @return array<array-key,mixed>
	 */
	private static function refile_rows( array $rows, string $from, string $to ): array {
		if ( $from === $to ) {
			return $rows;
		}
		foreach ( $rows as $hash => $row ) {
			$row                          = Core::arr( $row );
			$row[ Stats_Store::ROW_PATH ] = Stats_Store::refile_path( Core::str( $row[ Stats_Store::ROW_PATH ] ?? '' ), $from, $to );
			$rows[ $hash ]                = $row;
		}
		return $rows;
	}

	/**
	 * Overwrite every ranked list of each key — a bucket or an hour — from
	 * its shard maps, for each row family fourteen and a header record for
	 * each server named and for the site, the `Stats_Store::ranked_writes()`
	 * batch of every key written a chunk at a time. An overwrite, not a
	 * merge — the lists are derived from the stored rows, which one
	 * partition's one worker just wrote.
	 *
	 * The tier picks its own list depth, the way `cap_dim()` picks its field
	 * table: a pairing that can only go one way is not a parameter.
	 *
	 * A refused list is logged and not retried here: the lists are top-N
	 * bounded to fit, so a refusal is a wrong N, and a transient failure
	 * heals at the ranking the next write into the key brings. A marked hour
	 * carries each server's DONE marker in the same batch whatever the lists
	 * answer, and `url_hours_derived()` decides whether the hour ranks again.
	 *
	 * @param Stats_Store                                              $stats_store Destination.
	 * @param array<array-key,array<array-key,array<array-key,array<array-key,mixed>>>> $keys Bucket or
	 *                                                                                        hour key =>
	 *                                                                                        server =>
	 *                                                                                        shard => rows.
	 * @param bool                                                     $hour        The coarse tier.
	 * @param bool                                                     $mark        Write each server's DONE
	 *                                                                              marker: the caller vouches
	 *                                                                              every shard is whole.
	 * @return array{writes: int, lists: int, records: int, refused: int} What the batch
	 *         carried, and how much of it was refused.
	 */
	private function write_url_ranks( Stats_Store $stats_store, array $keys, bool $hour, bool $mark ): array {
		$writes = [];
		$named  = 0;
		$site   = 0;
		foreach ( $keys as $key => $servers ) {
			\array_push( $writes, ...Stats_Store::ranked_writes( $servers, $hour, (string) $key ) );
			foreach ( $mark ? \array_keys( $servers ) : [] as $server ) {
				$writes[] = [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( (string) $server ) ), (string) $key, [] ];
			}
			// `ranked_writes()`' fixed shape, counted rather than classified.
			$named += \count( $servers );
			$site  += [] === $servers ? 0 : 1;
		}
		// Every set ranks every server named.
		$records = \count( Stats_Store::RANK_SETS ) * $named;
		$sites   = \count( Stats_Store::RANK_SETS ) * $site;
		$each    = \count( Stats_Store::URL_SORTS ) * \count( Stats_Store::URL_ORDERS );
		$this->tally( Flame_Tree::STATS_WRITES, 'lists', $each * $records );
		$this->tally( Flame_Tree::STATS_WRITES, 'site lists', $each * $sites );
		$this->tally( Flame_Tree::STATS_WRITES, 'records', $records );
		$this->tally( Flame_Tree::STATS_WRITES, 'site records', $sites );
		$this->tally( Flame_Tree::STATS_WRITES, 'markers', $mark ? $named : 0 );
		$out = [ 'writes' => \count( $writes ), 'lists' => $each * ( $records + $sites ), 'records' => $records + $sites, 'refused' => 0 ];
		foreach ( \array_chunk( $writes, self::WRITE_BATCH_KEYS ) as $chunk ) {
			foreach ( $stats_store->bucket_set_multi( $chunk ) as $at => $ok ) {
				if ( ! $ok ) {
					++$out['refused'];
					$this->tally( Flame_Tree::STATS_WRITES, "refused {$chunk[ $at ][0][0]}" );
				}
			}
		}
		if ( $out['refused'] > 0 ) {
			$this->print_less_often( 'URL rank write refused', ' — ' . \implode( ' ', \array_keys( $keys ) ) );
		}
		return $out;
	}

	/**
	 * The rows a ranking holds, over one key's servers' shard maps.
	 *
	 * @param array<array-key,array<array-key,array<array-key,mixed>>> $servers Server => shard => rows.
	 */
	private static function rows_in( array $servers ): int {
		return \array_sum( \array_map( static fn ( array $shards ): int => \array_sum( \array_map( 'count', $shards ) ), $servers ) );
	}

	/**
	 * How one server's shard of accumulated rows folds into its stored bucket.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param string                 $server The server the rows are filed under.
	 * @param string                 $shard  Shard name from `Stats_Store::url_shard()`.
	 * @param array<array-key,mixed> $rows   That shard's accumulated rows.
	 * @param bool                   $ranks  The bucket sits in the fine tail, so the shard collects.
	 * @return list<Pending_Write>
	 */
	private function url_shard_intent( string $bucket, string $server, string $shard, array $rows, bool $ranks ): array {
		$key = Stats_Store::server_key( $server );
		return $this->hour_tier_intents(
			$bucket,
			Stats_Store::url_shard_parts( $key, $shard ),
			Stats_Store::url_hour_parts( $key, $shard ),
			static fn ( array $existing ): array => self::cap_url_rows(
				self::merge_url_rows( $existing, $rows )
			),
			// A discarded refusal loses this server's shard of the bucket.
			function ( string $key ) use ( $rows ): void {
				// Rows, not bytes: sizing re-serialises a megabyte a flush.
				$this->print_less_often(
					'URL index write refused in ' . Stats_Store::namespace_of( $key ) . '; its rows are lost',
					\sprintf( ' — %s, %d rows', $key, \count( $rows ) )
				);
			},
			$ranks ? function ( array $merged ) use ( $bucket, $server, $shard ): void {
				$this->flushed_rows[ $bucket ][ $server ][ $shard ] = $merged;
			} : null,
			$key
		);
	}

	/**
	 * Add one bucket's rows into a row map, seeding a row the map lacks.
	 *
	 * Both writers of the URL index need this: the flush merges a shard's
	 * accumulated rows into the stored ones, and the fold merges an hour's
	 * twelve buckets into each other. Capping stays at the call site, because
	 * the fold caps ONCE after all twelve rather than after each.
	 *
	 * @param array<array-key,mixed> $into Row map merged into.
	 * @param array<array-key,mixed> $rows Rows to add, by url_hash.
	 * @return array<array-key,mixed>
	 */
	private static function merge_url_rows( array $into, array $rows ): array {
		foreach ( $rows as $key => $stats_raw ) {
			// An all-digit hash arrives as an int array key; cast back.
			$hash          = (string) $key;
			$row           = Core::arr( $into[ $hash ] ?? null ) ?: self::empty_url_row();
			$into[ $hash ] = Stats_Store::merge_url_row( $row, Core::arr( $stats_raw ) );
		}
		return $into;
	}

	/**
	 * Cap one server's shard of rows by estimated bytes, busiest first.
	 *
	 * Both tiers store the same shape, so both take the same ceiling — and the
	 * HOUR needs it more than the bucket does, because it folds twelve buckets'
	 * URL sets into one key.
	 *
	 * @param array<array-key,mixed> $rows One shard's rows, by url_hash.
	 * @return array<array-key,mixed>
	 */
	private static function cap_url_rows( array $rows ): array {
		// @longform The tail FOLDS rather than dropping: every total on the
		// dashboard is summed from this index, so anything discarded here comes
		// off those numbers silently. Two synthetic KEYS, one per population,
		// so the two shard families never fold their tails into each other.
		$other = [
			Stats_Store::OTHER_KEY        => Core::arr( $rows[ Stats_Store::OTHER_KEY ] ?? null ),
			Stats_Store::OTHER_WORKER_KEY => Core::arr( $rows[ Stats_Store::OTHER_WORKER_KEY ] ?? null ),
		];
		unset( $rows[ Stats_Store::OTHER_KEY ], $rows[ Stats_Store::OTHER_WORKER_KEY ] );
		// The overflow rows go back in below either way: the cap counts them.
		$per_row = Stats_Store::overhead( 'url_row' );
		$room    = Stats_Store::ITEM_BUDGET - \count( $other ) * $per_row;
		$bytes   = static fn ( mixed $stored ): int => $per_row
			+ \strlen( Core::str( Core::arr( $stored )[ Stats_Store::ROW_PATH ] ?? '' ) );
		if ( self::fitting( $rows, \PHP_INT_MAX, $room, $bytes ) < \count( $rows ) ) {
			\uasort(
				$rows,
				static fn ( $a, $b ) => Core::num_int( Core::arr( $b )[ Stats_Store::ROW_COUNT ] ?? null )
					<=> Core::num_int( Core::arr( $a )[ Stats_Store::ROW_COUNT ] ?? null )
			);
			$keep = self::fitting( $rows, \PHP_INT_MAX, $room, $bytes );
			foreach ( \array_slice( $rows, $keep, null, true ) as $row ) {
				$row           = Core::arr( $row );
				$key           = Stats_Store::other_key( ! empty( $row[ Stats_Store::ROW_WORKER ] ) );
				$other[ $key ] = Stats_Store::fold_url_rows( $other[ $key ], $row );
			}
			$rows = \array_slice( $rows, 0, $keep, true );
		}
		foreach ( $other as $key => $row ) {
			if ( [] !== $row ) {
				$rows[ $key ] = $row;
			}
		}
		return $rows;
	}

	/**
	 * How the site's request totals for one bucket fold into its slot.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param array<array-key,mixed> $totals Accumulated totals.
	 * @return Pending_Write
	 */
	private static function hourly_intent( string $bucket, array $totals ): array {
		return self::slot_intent(
			Stats_Store::hourly_parts(),
			$bucket,
			static fn ( array $existing ): array => Stats_Store::add_totals( Stats_Store::string_keys( $existing ), $totals )
		);
	}

	/**
	 * How one dimension's bucket folds into its slot, capped per slot: a
	 * site's, a server's or a URL's, by `$parts`.
	 *
	 * @param array<int,string>      $parts  `dim_parts()` or `url_dim_parts()`.
	 * @param string                 $bucket Bucket key.
	 * @param string                 $dim    Dimension name.
	 * @param array<array-key,mixed> $values Accumulated values.
	 * @param int                    $cap    Values a slot keeps on every axis but `server`.
	 * @param ?string                $member The member of a URL-hour row holding
	 *                                       the slotted hour, `$dim` for
	 *                                       `url_dim_parts()`; null where the
	 *                                       value is the slotted hour.
	 * @return Pending_Write
	 */
	private static function dimension_intent( array $parts, string $bucket, string $dim, array $values, int $cap, ?string $member ): array {
		return self::slot_intent(
			$parts,
			$bucket,
			static fn ( array $existing ): array => self::cap_dim(
				Stats_Store::sum_fields( $existing, $values, Stats_Store::DIM_SUMS ),
				Stats_Store::dim_cap( $dim, $cap )
			),
			$member
		);
	}

	/**
	 * How one scope's category bucket folds into its slot, capped per slot:
	 * a site's, a server's or a URL's, by `$parts`.
	 *
	 * @param array<int,string>      $parts  `cat_parts()` or `url_cat_parts()`.
	 * @param string                 $bucket Bucket key.
	 * @param array<array-key,mixed> $cats   Accumulated categories.
	 * @return Pending_Write
	 */
	private static function categories_intent( array $parts, string $bucket, array $cats ): array {
		return self::slot_intent( $parts, $bucket, static fn ( array $existing ): array => self::fold_categories( $existing, $cats ) );
	}

	/**
	 * One write into a slotted hour value: `$merge` folds the bucket's own
	 * slot, so slot `Stats_Store::slot_of( $bucket )` holds exactly what a
	 * five-minute bucket would, under the same caps (decision 35). Two
	 * buckets of one hour compose onto one key through `add_intent()`.
	 *
	 * @param array<int,string>                                        $parts  A slotted namespace's key parts.
	 * @param string                                                   $bucket Bucket key.
	 * @param \Closure(array<array-key,mixed>): array<array-key,mixed> $merge  The bucket's own fold.
	 * @param ?string                                                  $member The member of a URL-hour row
	 *                                                                         holding the slotted hour; null
	 *                                                                         where the value is the hour.
	 * @return Pending_Write
	 */
	private static function slot_intent( array $parts, string $bucket, \Closure $merge, ?string $member = null ): array {
		$slot = Stats_Store::slot_of( $bucket );
		$fold = static function ( array $hour ) use ( $slot, $merge ): array {
			$hour[ $slot ] = $merge( Core::arr( $hour[ $slot ] ?? null ) );
			return $hour;
		};
		return self::intent(
			$parts,
			Stats_Store::hour_of( $bucket ),
			null === $member ? $fold : static function ( array $row ) use ( $member, $fold ): array {
				$row[ $member ] = $fold( Core::arr( $row[ $member ] ?? null ) );
				return $row;
			}
		);
	}

	/**
	 * How one scope's leaderboard folds into its hour: one sum, never
	 * slotted, because nothing charts it (decision 35).
	 *
	 * @param string              $bucket Bucket key.
	 * @param array<string,mixed> $sums   Accumulated sums.
	 * @param string              $server Reporting server; '' is the global board.
	 * @return Pending_Write
	 */
	private static function leaderboard_intent( string $bucket, array $sums, string $server ): array {
		return self::intent(
			Stats_Store::lb_parts( $server ),
			Stats_Store::hour_of( $bucket ),
			static function ( array $existing ) use ( $sums ): array {
				$existing = Stats_Store::string_keys( $existing );
				if ( empty( $existing ) ) {
					$existing = self::empty_leaderboard();
				}
				Stats_Store::merge_leaderboard_bucket( $existing, $sums );
				return self::cap_leaderboard( $existing, Stats_Store::ITEM_BUDGET );
			}
		);
	}

	/**
	 * One write into an hour-derived namespace: into its fine bucket, and
	 * once its hour has folded, into the hour key too, missing or not.
	 *
	 * A late write — a replay, an ingest, a spoke feeding backlog past its
	 * caught-up peers — goes to the fine bucket a re-fold reads and to the
	 * hour key the reader takes. The Tables are durable, so an hour key the
	 * write finds missing is one the fold never wrote, and the late rows are
	 * that key's rows.
	 *
	 * Only one server's row write ranks, of either family. Into an unfolded
	 * hour it collects for the ranker, and names its ranking GROUP, the
	 * (bucket, server) pair, only where its bucket sits in the fine tail.
	 * Into a folded hour its bucket sits behind that tail, and its hour-key
	 * write, once it lands, forgets that server's DONE marker so the flush
	 * re-ranks its hour from its rows.
	 *
	 * @param string                                                   $bucket  Bucket key.
	 * @param array<int,string>                                        $fine    Key parts in the fine tier.
	 * @param array<int,string>                                        $coarse  Key parts in the hour tier.
	 * @param \Closure(array<array-key,mixed>): array<array-key,mixed> $merge   Fold.
	 * @param ?\Closure(string): void                                  $refused Called with the key a refused set lost.
	 * @param ?\Closure(array<array-key,mixed>): void                  $collect What a write in the fine tail
	 *                                                                          collects; null where its bucket
	 *                                                                          ranks nothing.
	 * @param ?string                                                  $server_key The server a row write's
	 *                                                                             rows are filed under; null
	 *                                                                             where nothing ranks.
	 * @return list<Pending_Write>
	 */
	private function hour_tier_intents( string $bucket, array $fine, array $coarse, \Closure $merge, ?\Closure $refused, ?\Closure $collect, ?string $server_key = null ): array {
		$hour = Stats_Store::hour_of( $bucket );
		if ( ! isset( $this->folded_hours[ $hour ] ) ) {
			return [ self::intent( $fine, $bucket, $merge, $refused, $collect, null === $collect ? null : "{$bucket} {$server_key}" ) ];
		}
		$unrank = null === $server_key ? null : function () use ( $hour, $server_key ): void {
			$this->unranked_hours[ $hour ][ $server_key ] = true;
		};
		return [
			self::intent( $fine, $bucket, $merge, $refused ),
			self::intent( $coarse, $hour, $merge, $refused, $unrank ),
		];
	}

	/**
	 * File one intent under its cache KEY, composing onto whatever is already
	 * there — the merges in sequence, the hooks chained.
	 *
	 * Two fine buckets of a folded hour land on one `urls_h` key, and composed
	 * here one pre-read serves one write: a second merge built on the same
	 * pre-read value would discard the first's rows outright.
	 *
	 * @param array<string,Pending_Write> $intents The flush's intents, by key.
	 * @param Pending_Write               $intent  The intent to file.
	 */
	private static function add_intent( array &$intents, array $intent ): void {
		$key  = Stats_Store::key_at( $intent['parts'], $intent['bucket'] );
		$held = $intents[ $key ] ?? null;
		if ( null === $held ) {
			$intents[ $key ] = $intent;
			return;
		}
		$intents[ $key ] = self::intent(
			$intent['parts'],
			$intent['bucket'],
			static fn ( array $value ): array => ( $intent['merge'] )( ( $held['merge'] )( $value ) ),
			self::both( $held['refused'], $intent['refused'] ),
			self::both( $held['landed'], $intent['landed'] ),
			$held['group'] ?? $intent['group']
		);
	}

	/**
	 * One pending write: where it goes, and how to fold this flush's numbers
	 * onto whatever is already there.
	 *
	 * @param array<int,string>                        $parts   Key parts, the namespace first.
	 * @param string                                   $bucket  Bucket (or hour) key.
	 * @param \Closure(array<array-key,mixed>): array<array-key,mixed> $merge Fold.
	 * @param ?\Closure(string): void                  $refused Called with the key a rejected set lost.
	 * @param ?\Closure(array<array-key,mixed>): void  $landed  Called with the merged value once the set landed.
	 * @param ?string                                  $group   The ranking group this write belongs to,
	 *                                                          which is the bucket for a fine-tier URL
	 *                                                          row or name write and null for everything else.
	 * @return Pending_Write
	 */
	private static function intent( array $parts, string $bucket, \Closure $merge, ?\Closure $refused = null, ?\Closure $landed = null, ?string $group = null ): array {
		return [
			'parts'   => $parts,
			'bucket'  => $bucket,
			'merge'   => $merge,
			'refused' => $refused,
			'landed'  => $landed,
			'group'   => $group,
		];
	}

	/**
	 * Both hooks as one, forwarding whatever the caller passes; null where
	 * there is nothing to chain.
	 *
	 * @param ?\Closure $first  The hook already held.
	 * @param ?\Closure $second The hook composing onto it.
	 */
	private static function both( ?\Closure $first, ?\Closure $second ): ?\Closure {
		if ( null === $first || null === $second ) {
			return $first ?? $second;
		}
		return static function ( mixed ...$args ) use ( $first, $second ): void {
			$first( ...$args );
			$second( ...$args );
		};
	}

	/**
	 * Cap a dimensional bucket: ranked by request count, no reserved row. Named
	 * so the sort field and the field table cannot be paired wrongly at a call
	 * site. Only measured entries are ranked; `fold_categories()` holds its
	 * own to the same rule on `CAT_REQUESTS`.
	 *
	 * @param array<array-key,mixed> $values     One bucket's values.
	 * @param int                    $max_values Ceiling on distinct values.
	 * @return array<array-key,mixed>
	 */
	private static function cap_dim( array $values, int $max_values ): array {
		return self::cap_bucket( Stats_Store::measured( $values, Stats_Store::DIM_COUNT ), $max_values, Stats_Store::DIM_COUNT, Stats_Store::DIM_SUMS );
	}

	/**
	 * A category bucket's STORED shape: this flush's numbers summed onto what is
	 * already there, capped by time with `total` lifted clear of the ranking,
	 * and each entry's `CAT_MS` rounded to display precision.
	 *
	 * Rounding comes LAST because the cap re-sums its tail into `Other`, and a
	 * sum of rounded doubles is not itself rounded.
	 *
	 * @param array<array-key,mixed> $existing What the bucket already holds.
	 * @param array<array-key,mixed> $cats     This flush's accumulated categories.
	 * @return array<array-key,mixed>
	 */
	private static function fold_categories( array $existing, array $cats ): array {
		$measured = Stats_Store::measured( Stats_Store::sum_fields( $existing, $cats, Stats_Store::CAT_SUMS ), Stats_Store::CAT_REQUESTS );
		$capped = self::cap_bucket(
			$measured,
			Stats_Store::MAX_CAT_VALUES,
			Stats_Store::CAT_MS,
			Stats_Store::CAT_SUMS,
			self::TOTAL_KEY
		);
		foreach ( $capped as $name => $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$entry[ Stats_Store::CAT_MS ] = \round(
				Core::num_float( $entry[ Stats_Store::CAT_MS ] ?? null ),
				Stats_Store::CAT_MS_DECIMALS
			);
			$capped[ $name ] = $entry;
		}
		return $capped;
	}

	/**
	 * Emit the accumulated auto-tune decisions: hooks and custom events to
	 * disable, and events newly promoted to significant.
	 *
	 * The decisions become a ruleset rewrite, and every flame-builder partition
	 * accumulates its own. A shared-tier `add()` with a 5-second expiry serves as a
	 * distributed lock so only one worker rewrites at a time; losing the race is
	 * not an error, the decisions simply survive to the next flush.
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

		// Held by another worker: retry next flush, or wait out on hrtime.
		$deadline = Quiet::mark() + $wait_ms * 1_000_000;
		while ( ! $cache->add( $lock_key, $lock_value, $lock_timeout ) ) {
			if ( Quiet::mark() >= $deadline ) {
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
	 * Drain the per-URL flame and profile aggregates into the store. The
	 * batched `NS_URL` write overwrites with the whole aggregate.
	 *
	 * The blob of each URL this flush filed is stamped with the hour, under
	 * each server it was filed under (`persist_url_names()`); a stamp from an
	 * earlier hour skips nothing, so it is dropped.
	 *
	 * @param Stats_Store                              $stats_store The wired store.
	 * @param int                                      $now         The caller's one read of the tick, the `last_modified` stamp.
	 * @param array<array-key,array<array-key,string>> $filed       server => hash => URL, the names this flush filed.
	 */
	private function drain_url_stats( Stats_Store $stats_store, int $now, array $filed ): void {
		$hour   = Stats_Store::hour_of( Stats_Store::bucket_key( $now ) );
		$stamps = [];
		foreach ( $filed as $server => $urls ) {
			foreach ( \array_keys( $urls ) as $hash ) {
				$stamps[ (string) $hash ][ Stats_Store::server_key( (string) $server ) ] = $hour;
			}
		}
		$writes = [];
		foreach ( $this->url_acc->iterate() as $url_hash => $aggregate ) {
			if ( ! \is_array( $aggregate ) ) {
				continue;
			}
			/** @var array<string,mixed> $aggregate */
			// Half the item for profiles, a quarter for each copy of the tree.
			$aggregate['profiles'] = self::cap_leaderboard( Core::arr( $aggregate['profiles'] ?? null ), \intdiv( Stats_Store::ITEM_BUDGET, 2 ) );
			// Finalized flame for display; keep flame_raw for merging.
			[ $aggregate['flame_raw'], $aggregate['flame'] ] = self::url_flame_for_display( Core::arr( $aggregate['flame'] ?? null ) );
			$aggregate['last_modified'] = $now;
			if ( isset( $stamps[ (string) $url_hash ] ) ) {
				$aggregate['filed'] = $stamps[ (string) $url_hash ] + \array_filter( Core::arr( $aggregate['filed'] ?? null ), static fn ( mixed $at ): bool => $hour === $at );
			}
			// @longform One write per URL is one ROUND TRIP per URL, which is
			// the cost this whole flush path is batched to avoid. Chunked on
			// `flush_writes()`'s budget, which bounds what one `store_multi`
			// serializes; the aggregates themselves are alive either way,
			// because the accumulator this drains is already holding them.
			$writes[] = [ [ Stats_Store::NS_URL ], (string) $url_hash, $aggregate ];
		}
		foreach ( \array_chunk( $writes, self::WRITE_BATCH_KEYS ) as $chunk ) {
			$landed = $stats_store->bucket_set_multi( $chunk );
			$this->tally( Flame_Tree::STATS_WRITES, 'refused ' . Stats_Store::NS_URL, \count( \array_keys( $landed, false, true ) ) );
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
	 * A zero-valued URL index row.
	 *
	 * Both sides of the write path seed one, differing only in the `min_ms`
	 * sentinel: the accumulator folds with `min()` and needs a ceiling, the
	 * persisted row starts at 0 and is guarded by `timed_count`. Two literals
	 * would make every new field a four-place edit, and one omission a silent
	 * undefined index on a per-request path.
	 *
	 * @param float|int $min_ms Starting minimum; `PHP_INT_MAX` where `min()` folds it.
	 * @return array<int,mixed>
	 */
	private static function empty_url_row( float|int $min_ms = 0 ): array {
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
			Stats_Store::ROW_MIN_MS      => $min_ms,
			Stats_Store::ROW_MAX_MS      => 0,
			Stats_Store::ROW_MAX_PEAK_MB => 0,
			Stats_Store::ROW_LAST_SEEN   => 0,
			Stats_Store::ROW_WORKER      => false,
			Stats_Store::ROW_PATH        => '',
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

	/** @param string $table The Table every other namespace is written to. */
	public function set_aggregate_target( string $table ): void {
		$this->aggregate_target = self::mounted_table( 'set_aggregate_target', Stats_Store::TABLE_AGGREGATE, $table );
	}

	/** @param string $table The Table the per-URL blob is written to. */
	public function set_url_target( string $table ): void {
		$this->url_target = self::mounted_table( 'set_url_target', Stats_Store::TABLE_URL, $table );
	}

	/** @param string $table The Table the fine URL tier is written to. */
	public function set_url_fine_target( string $table ): void {
		$this->url_fine_target = self::mounted_table( 'set_url_fine_target', Stats_Store::TABLE_URL_FINE, $table );
	}

	/**
	 * The Table a verb names, refused unless it is the one the readers mount
	 * for that role: `Performance_CI_Node` mounts `Stats_Store::TABLES` by
	 * role, so a write under any other name is one no dashboard reads.
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
	 * Inject the Stats_Store the flush writes through.
	 *
	 * Only the read plan is dropped, for the retention window it was built
	 * over. A second `configure_stats` builds over the same three Tables,
	 * since each target verb refuses any other, so every other memo still
	 * describes the store, and the rankings the pending memo owes live in
	 * memory alone.
	 *
	 * @param Stats_Store $store Store over this worker's stats Tables.
	 */
	public function set_stats_store( Stats_Store $store ): void {
		$this->stats_store = $store;
		$this->plan_memo   = null;
	}

	/**
	 * Take back the crumb, so the record a plain stop left the cursor on is
	 * replayed without being counted again, and the data clock, so the fine
	 * deadline outlives the recycle. A key the carry lacks reads as the
	 * start state a fresh process has.
	 *
	 * @api Used by substrate.
	 * @param array<string,mixed> $saved A prior `save_state()`.
	 */
	public function restore_state( array $saved ): void {
		$this->counted               = Core::str( $saved['counted'] ?? null );
		$this->data_clock            = Core::str( $saved['data_clock'] ?? null );
		$this->data_clock_hour_since = Core::num_float( $saved['data_clock_hour_since'] ?? null );
	}

	/**
	 * The stats Tables the verbs named, which the flush writes past its
	 * primary target, so the console draws an edge to each.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		return [ $this->aggregate_target, $this->url_target, $this->url_fine_target ];
	}

	/**
	 * When this node went idle: the last flush that drained a folded record
	 * while nothing is pending, or null while one waits for the next flush.
	 * An upkeep flush — a roll-up read a Table left unanswered repeats one
	 * every tick — leaves it where it was.
	 *
	 * @api Used by substrate: `Cooperative_Stop`'s idle scan.
	 */
	public function idle_since(): ?float {
		return [] === $this->pending ? $this->worked_at : null;
	}

	/**
	 * Each declared stats Table => the node its verb named: the map the
	 * store asks through.
	 *
	 * @return array<string,string>
	 * @throws \LogicException When a verb never named its Table, which would
	 *                         take that Table's writes to no node at all.
	 */
	private function stats_tables(): array {
		$named   = [
			'set_aggregate_target' => $this->aggregate_target,
			'set_url_target'       => $this->url_target,
			'set_url_fine_target'  => $this->url_fine_target,
		];
		$unnamed = \array_keys( $named, '', true );
		if ( [] !== $unnamed ) {
			throw new \LogicException( 'configure_stats: no Table named by ' . \implode( ', ', $unnamed ) );
		}
		return [
			Stats_Store::TABLE_AGGREGATE => $this->aggregate_target,
			Stats_Store::TABLE_URL       => $this->url_target,
			Stats_Store::TABLE_URL_FINE  => $this->url_fine_target,
		];
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
	 * Table setters dump ahead of `configure_stats`, which refuses to run
	 * before they have.
	 *
	 * @api Used by substrate.
	 * @return string TSL lines, newline-terminated.
	 */
	public function dump_config(): string {
		$out = parent::dump_config() . $this->dump_setters() . $this->dump_toggles();
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
			'description' => 'Aggregates per-event count + sum_time into the stats Tables; emits flame JSONL.',
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
					'name'        => 'set_aggregate_target',
					'description' => 'Name the stats Table every namespace but the per-URL ones is written to: flame-stats:aggregate, the one the performance readers mount.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'aggregate_target',
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
					'name'        => 'set_url_fine_target',
					'description' => 'Name the stats Table the fine URL tier is written to: flame-stats:url-fine, the one the performance readers mount.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'url_fine_target',
				],
				[
					'name'        => 'configure_stats',
					'description' => 'Build the Stats_Store over the three Tables the set_*_target verbs named, with the retention window. Refused until all three are named.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_store(
							new Stats_Store(
								Config::stats_retention_seconds(),
								$patron->client,
								$patron->stats_tables()
							)
						);
						return 'ok';
					},
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'Stats cache + pending buckets + auto-tune queue depth.',
					'reply_shape' => '{ stats_count, pending_url_count, intern_count, pending_buckets, last_flush_age_s, auto_tune_pending_count, is_hub, significant_events_count, narration }',
					'handler'     => static fn ( self $node ): array => $node->stats_report(),
				],
			],
		];
	}
}
