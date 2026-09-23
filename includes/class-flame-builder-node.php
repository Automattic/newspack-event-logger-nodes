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
 * `flush()` merges each bucket into memcache through `Stats_Store` (the
 * memcache schema) at most once per FLUSH_INTERVAL_SEC, capping as it writes,
 * then drops them. It also folds each closed hour of the URL index into the
 * coarse `urls_h` tier, which is what keeps a reader off 288 fine buckets per
 * shard. Per-URL flame trees take a different route: they live in the
 * `Stats_Store` accumulator and drain through `mirror_url_stats()`.
 *
 * Two side channels hang off that pipeline. `set_stats_target` names a durable
 * Partition that shadows each memcache write once its bucket closes, so a
 * non-Atomic deployment can replay stats after memcache loses them. And the
 * request's governing `Rule` drives auto-tune: hooks that fire too often,
 * custom events to disable, and newly significant events accumulate here and
 * are emitted as messages to the owned `Auto_Tuner_Node` sibling, which
 * rewrites the rule.
 *
 * Worker context: this node runs inside the `flame-builder` (or `complete`)
 * topology. See `topologies/flame-builder.tsl` for the wiring.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\LRU_Cache;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Shutdown_Sweeper;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds flame trees and the memcache stats schema from completed requests.
 *
 * @phpstan-type Pending_Write array{parts: array<int,string>, bucket: string, merge: \Closure(array<array-key,mixed>): array<array-key,mixed>, refused: \Closure|null, landed: \Closure|null, group: string|null, present: bool}
 * @phpstan-type Leaderboard_Acc array{count?: int, sum_req_time?: float|int, categories: array<string,array{samples: int,sum_time: float|int,sum_count: float|int,ts?: int,entries: array<string,array<int,float|int>>}>}
 * @phpstan-type Dim_Values array<string,array{0: int,1: float|int,2: float|int}>
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
class Flame_Builder_Node extends Node implements Shutdown_Sweeper {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;

	/**
	 * Reserved key of the category time series: the per-bucket ROLLUP row, not a
	 * category. It carries `CAT_REQUESTS` = requests in the bucket, `CAT_MS` =
	 * their summed wall time, and `CAT_CALLS` = the summed call count of every
	 * category in them.
	 *
	 * Only `CAT_REQUESTS` has a reader — `mirror_traffic_rank()` reads one
	 * bucket's to rank a URL when the mirror buffer overflows. The dashboard's
	 * `CategoryTimeChart` skips the row outright, so the other two are published
	 * but unread today.
	 *
	 * Category names come from the ruleset, where a custom event may be called
	 * anything — including this. A colliding name is renamed on the way in
	 * (`collision_free_category()`), because otherwise its samples land in the
	 * rollup and inflate the request count that drives mirror eviction.
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

	/** Minimum seconds between flush() runs; fill() enforces the throttle. */
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
	 * Byte ceiling on the held mirror frames a checkpoint frame carries.
	 *
	 * Sized from the OUTER limit, not from an observed size. The checkpoint
	 * record is the whole of `save_state()` — the read cursor, `pending` and
	 * these frames — and `add_snapshot_node` lifts its PIPE_BUF cap to
	 * `Partition_Node::MAX_LARGE_LINE_SIZE` (32MB), past which the record is
	 * DROPPED, cursor included. This is a policy cap on FRAME bytes alone, so
	 * keys, nesting and `pending` ride it uncounted; half the cliff leaves the
	 * other half for them.
	 *
	 * A budget set from a fixture binds the first time a real site is bigger
	 * than it: a staging hub holds ~1,400 per-URL frames, 2.1–3.5MB a
	 * checkpoint, and a 2MB budget fired the tripwire below on every one of
	 * them, which reports nothing. `mirror_held_bytes` on GET_STATS is where
	 * the trend is read against this number.
	 *
	 * What it costs is DISK, in the offsetlog. That ring bounds keyframe COUNT
	 * (`OFFSETLOG_NUM_SEGMENTS`, 30, which binds at a 30s cadence) and never
	 * bytes, and each keyframe carries a whole checkpoint, so the ring holds 30x
	 * what is HELD — the budget is its ceiling, 480MB, not its size: a hub
	 * holding 3.5MB spends ~100MB.
	 *
	 * A frame past the budget is re-merged from memcache by the next write to its
	 * bucket, so it is lost only if the process AND memcache both fail before the
	 * bucket closes — the same double failure the mirror exists for.
	 */
	private const MAX_CHECKPOINT_MIRROR_BYTES = 16777216;

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
	private const UNKNOWN_VALUE = 'Unknown';

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
	 * Max URLs kept per server per SHARD of a bucket, ranked by request count.
	 *
	 * Sixteen shards, so this admits 16x the URLs per bucket at a sixteenth the
	 * blob — a backstop against an oversized item, not a policy. The tail folds
	 * rather than dropping, so reaching it costs detail, never totals. One
	 * server's rows per key, so a busy server's tail never folds a quiet
	 * one's rows.
	 */
	public const MAX_URLS_PER_SHARD = 2000;

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
	 * Frames one namespace holds before the overflow is written EARLY.
	 *
	 * The buffer's residency is one open bucket — `flush_stats_mirror()` drains
	 * every closed one at each checkpoint — so its size is the distinct keys that
	 * bucket sees, which nothing else bounds: a query-string-heavy URL space, a
	 * crawler or Host-header spray all push it with no ceiling. Reaching this is
	 * a redundant write and never a lost frame: `spill_over_backstop()` writes
	 * the lowest-ranked band to the partition and stops holding it, so a
	 * bucket's later state still lands when its key is written again.
	 *
	 * Ten thousand a namespace is far past any site in evidence — the per-URL
	 * series would need 10,000 distinct URLs inside one five-minute bucket on one
	 * partition, against a production hub whose whole held set measures ~350KB —
	 * and holds the worst case to tens of megabytes against the worker's 80%
	 * memory watermark. `mirror_held_frames` on the GET_STATS payload reports
	 * the WIDEST namespace's held count against this, so the trend is read
	 * before it binds.
	 *
	 * Protected so a test double can reach the spill without pushing ten thousand
	 * distinct URLs through one bucket.
	 *
	 * @var int
	 */
	protected const MAX_HELD_FRAMES = 10000;

	/**
	 * How much of the backstop one spill writes out: a tenth of it.
	 *
	 * The hysteresis between the two bounds, in the shape `trim_entries()`
	 * already uses — stop at the upper, resume at the lower — because the
	 * single threshold it replaces put a full ranking pass on every write past
	 * the bound. A tenth buys a tenfold amortization for one extra partition
	 * write per spilled frame, which is the cost a spill already pays.
	 */
	private const HELD_FRAMES_SPILL_DIVISOR = 10;

	/**
	 * Nanoseconds spent reading the durable mirror for the answer in progress.
	 *
	 * `Performance_CI_Node::dispatch()` zeroes it as each verb begins and
	 * `arm_stats_reader()` stops reading once it passes the configured budget.
	 * The WORKER's own seam (`arm_stats_mirror()`) is unbudgeted — it is
	 * restoring its own state, not answering a poll.
	 */
	private static int $mirror_read_ns = 0;

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
	 * Each bucket this flush wrote rows into, and every server its index
	 * named once this flush's rows were admitted: what ranking gap-fills
	 * from, so a bucket the flush just wrote costs no second index read.
	 * Emptied with `$flushed_rows`.
	 *
	 * @var array<string,array<string,string>>
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
	 * Folded hours the probe found holding rows but some server with no DONE
	 * marker, each until its servers are ranked from those stored rows.
	 * Pruned to the read plan's hours, since no reader plans one the window
	 * passed.
	 *
	 * The store holds the debt, not this: a server missing its marker is
	 * found again by the next probe, so a stop that leaves one loses nothing.
	 *
	 * @var array<string,true>
	 */
	private array $stale_hours = [];

	/**
	 * Folded hours a late write landed in this flush, with the servers whose
	 * DONE markers the flush forgets, once each, so the probe re-ranks them.
	 *
	 * @var array<string,array<array-key,true>> An all-digit server key is an INT key.
	 */
	private array $unranked_hours = [];

	/**
	 * Flushes between full re-probes of the coarse tier.
	 *
	 * The memo says what THIS process folded, which is not the same as what is
	 * still there: `NS_URLS_HOUR` is excluded from the mirror, so an hour
	 * missing a row or name shard — evicted, or its write refused — can never
	 * be rehydrated and would otherwise stay believed-folded for the life of
	 * the worker, leaving the reader on the fallback it re-probes to escape.
	 * The re-probe folds such an hour again from the fine buckets, which the
	 * mirror reads back.
	 */
	private const REPROBE_EVERY_FLUSHES = 60;

	/**
	 * Entries per generation of the named-URL held set: each URL, under the
	 * server its rows are filed as, whose name and tokens are already stored,
	 * against the time they were written.
	 *
	 * A name never changes, so re-storing it every flush would spend the saving
	 * the table exists for; the held set is what makes the write once-per-URL
	 * instead. Eviction only costs a re-write.
	 */
	private const NAMED_URL_BUCKET_SIZE = 2000;

	/** Generations the named-URL held set keeps. See NAMED_URL_BUCKET_SIZE. */
	private const NAMED_URL_BUCKETS     = 4;

	/** @var LRU_Cache `{server_key}:{hash}` => Unix time its name was last stored. */
	private LRU_Cache $named_urls;

	/** Flushes since the memo was last emptied; see REPROBE_EVERY_FLUSHES. */
	private int $folds_since_reprobe = 0;

	/** @var array<string,bool> Custom-event-name set ({name => true}). */
	private array $custom_event_names = [];

	/**
	 * Live top-N cap for the per-URL flame-profile mirror (NS_URL) — how many
	 * profiles are shadowed to the durable stats partition, not to memcache,
	 * which always receives them.
	 *
	 * 0 in production: flame profiles are the largest per-URL values, and paying
	 * to make them durable is not worth it, while the per-URL dimensional and
	 * category namespaces mirror in full at a fraction of the bytes.
	 * `set_flame_topn` raises it; tests do that to exercise the persisted-profile
	 * shape at a non-zero cap.
	 */
	private int $flame_topn = 0;

	/** Hub mode: also accumulate the per-server namespaces. Derived from `<eln:is_hub>`. */
	private bool $is_hub = false;

	/** Unix time of the last flush(); fill() compares it against FLUSH_INTERVAL_SEC. */
	private float $last_flush_time = 0.0;

	/**
	 * Mirror writes buffered until their bucket closes: ns => key => [data, ttl].
	 * One space for every namespace, each holding at most `MAX_HELD_FRAMES` —
	 * NS_URL additionally rank-capped to `mirror_topn()`, which is all that
	 * separates it from the rest.
	 *
	 * @var array<string,array<string,array{0: array<array-key,mixed>,1: int}>>
	 */
	private array $mirror = [];

	/** @var array<string,Bucket_Acc> Accumulators by bucket key, drained at flush(). */
	private array $pending = [];

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

	/**
	 * Name of the durable Partition shadowing the stats store, read back when
	 * memcache misses; '' disables the mirror. Stored as a NAME
	 * and resolved through `Core::node()` at use, so the partition may be built
	 * after this node.
	 */
	private string $stats_partition = '';

	/** @var Stats_Store|null Memcache-backed stats store; null until `configure_stats` runs. */
	private $stats_store = null;

	/**
	 * Seed the flush clock, build the named-URL held set, and publish the owned
	 * auto-tuner sibling.
	 *
	 * The node is inert until `configure_stats` supplies a `Stats_Store`: it still
	 * accumulates and still forwards flames, but nothing reaches memcache.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		$this->last_flush_time = Core::$now;
		$this->named_urls      = new LRU_Cache( self::NAMED_URL_BUCKET_SIZE, self::NAMED_URL_BUCKETS );

		// Owned auto-tuner sibling (patron-linked; hidden from the canvas).
		$auto_tuner = new Auto_Tuner_Node();
		$auto_tuner->patron( $this );
		$this->publish_sibling( 'auto-tuner', $auto_tuner );

		parent::__construct();
		// Wire :config interpreter last: handlers read patron() lazily (safe).
		$this->auto_wire_interpreter();
	}

	/**
	 * Handle one message: a TM_REQUEST introspection verb, or a TM_STRUCT
	 * completed-request record tailed off `requests.p{N}`.
	 *
	 * A completed request becomes a flame tree, is forwarded to the flames
	 * partition, and is folded into every accumulator. The flush throttle then
	 * runs at most once per FLUSH_INTERVAL_SEC. Anything else is dropped.
	 *
	 * The `Deferred_Clean_Stop` bracket (`clear_pending_stop()` here,
	 * `raise_pending_stop()` at the end) holds a cooperative stop raised by a
	 * downstream forward until this message's own bookkeeping is finished, so the
	 * Consumer commits past it rather than replaying it.
	 *
	 * @param array<int,mixed> $message Positional Message array.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		// Per-message deferral: clear a stale stop from a prior fill().
		$this->clear_pending_stop();
		$type_raw = $message[ Message::TYPE ];
		$type     = Core::int( $type_raw );
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
			return;
		}
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
		$this->accumulate_all_stats( $url_hash, $flame_data, $profiles, $request );

		// Periodic flush; cached per-tick clock gates the throttle.
		$now_f = Core::$now;
		if ( $now_f - $this->last_flush_time >= self::FLUSH_INTERVAL_SEC ) {
			$this->guarded( fn () => $this->flush() );
			$this->last_flush_time = $now_f;
		}

		$this->raise_pending_stop();
	}

	/**
	 * Answer a TM_REQUEST verb with a TM_STRUCT|TM_RESPONSE reply.
	 *
	 * GET_STATS is the only verb; anything else replies with an `error` payload
	 * rather than throwing. The reply is addressed TO the request's FROM — the
	 * addressing is the correlation — and echoes back ID and KEY.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 * @throws \RuntimeException When no sink is wired, leaving nowhere to reply.
	 */
	private function handle_request( array $message ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Flame_Builder::fill requires a wired sink' );
		}
		$value_raw = $message[ Message::VALUE ];
		$value     = Core::as_string( $value_raw );
		$verb      = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_STATS' === $verb ) {
			$stats_count = \iterator_count( $this->stats_store?->accumulating_url_stats() ?? new \EmptyIterator() );
			$now = Core::$now;
			$payload = [
				'stats_count'              => $stats_count,
				'pending_url_count'        => \array_sum( \array_map( static fn ( array $acc ): int => \array_sum( \array_map( 'count', $acc['url_stats'] ) ), $this->pending ) ),
				'intern_count'             => \count( self::$intern ),
				'pending_buckets'          => \array_keys( $this->pending ),
				'last_flush_age_s'         => $this->last_flush_time > 0 ? (int) ( $now - $this->last_flush_time ) : null,
				'auto_tune_pending_count'  => \array_sum( \array_map( self::map_total( ... ), $this->auto_tune ) ),
				'is_hub'                   => $this->is_hub,
				'significant_events_count' => self::map_total( $this->significant_events ),
				'mirror_held_frames'       => $this->widest_namespace_frames(),
				'mirror_held_bytes'        => \array_sum(
					\array_map( self::frame_bytes( ... ), \array_column( $this->mirror_frames(), 'frame' ) )
				),
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
		$error_status = $request['error_status'] ?? '-';
		// Both durations are fictions and would skew the mean and the max.
		$is_timed_out = \in_array( $error_status, [ 'T', 'A' ], true );
		$is_worker    = ! empty( $request['is_worker'] );
		// Two gates: per-URL rows keep worker timing; global drops workers.
		$record_timing = $duration_ms > 0 && ! $is_timed_out;
		$count_global  = ! $is_worker;
		$now           = (int) Core::$now;

		$timestamp_raw = $request['timestamp'] ?? $now;
		// @longform The record reaches us at COMPLETION, so that is when it is
		// filed: a request is a fact about the moment it ended, and a long one
		// filed under its start lands in a bucket the readers may have closed
		// and folded. An aborted request carries `now - start` as its duration
		// (Request_Builder sets it at eviction), so this is its abort moment.
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

		$aggregate = $this->accumulate_url_aggregate( $url_hash, $flame_data, $duration_ms, $record_timing, $now );
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
				$now
			);
		}

		unset( $acc );

		/** @var array<string,mixed> $aggregate */
		$this->stats_store?->accumulate_url_stats( $url_hash, $aggregate );
	}

	/**
	 * Fold the request into the per-URL aggregate: one more request, its timing,
	 * and its flame tree merged into the running one. Sums, never means.
	 *
	 * @param string                  $url_hash       URL hash of the request.
	 * @param array<string,mixed>    $flame_data     Per-request flame tree.
	 * @param float                   $duration_ms    Request duration.
	 * @param bool                    $record_timing  Whether timing counts.
	 * @param int                     $now            Clock read for this request.
	 * @return array<array-key,mixed> The updated aggregate.
	 */
	private function accumulate_url_aggregate( string $url_hash, array $flame_data, float $duration_ms, bool $record_timing, int $now ): array {
		// Un-drained if held, else what was last persisted for a cold key.
		$cached    = $this->stats_store?->accumulated_url_stats( $url_hash );
		$aggregate = \is_array( $cached ) ? $cached : null;
		if ( null === $aggregate ) {
			$aggregate = [
				'flame'    => [
					'name'      => 'aggregate',
					'sum_value' => 0.0,
					'count'     => 0,
					'children'  => [],
				],
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

		$flame = \is_array( $aggregate['flame'] ?? null ) ? $aggregate['flame'] : [];
		$flame['count'] = ( \is_numeric( $flame['count'] ?? null ) ? $flame['count'] : 0 ) + 1;
		// Per-URL: workers keep timing on their own row.
		if ( $record_timing ) {
			$flame['sum_value'] = ( \is_numeric( $flame['sum_value'] ?? null ) ? $flame['sum_value'] : 0 ) + $duration_ms;
			$flame_children     = \is_array( $flame['children'] ?? null ) ? $flame['children'] : [];
			$incoming_children  = \is_array( $flame_data['children'] ?? null ) ? $flame_data['children'] : [];
			$flame['children']  = Flame_Tree::merge_flame_children_incremental( $flame_children, $incoming_children, $now );
		}
		$aggregate['flame'] = $flame;
		return $aggregate;
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
			[ Stats_Store::ROW_PATH => Stats_Store::row_path( $url, $server_name ) ]
		);
		// The whole URL goes to the URL name table, once, not into every row.
		$acc['url_names'][ $url_hash ] = $url;
		/**
		 * Positional; see `Stats_Store::ROW_*`.
		 *
		 * @var array{0: int, 1: int, 2: float|int, 3: float|int, 4: int, 5: int, 6: int, 7: int, 8: float|int, 9: float|int, 10: float|int, 11: int, 12: bool, 13: string} $us
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
	 * Fold the request into the pending bucket's site-wide totals. Workers
	 * contribute nothing here — not count, not timing, not peak memory —
	 * or one long-running worker would dominate the site-wide averages.
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
			'sum_peak_mb' => \is_numeric( $hourly['sum_peak_mb'] ?? null ) ? $hourly['sum_peak_mb'] : 0,
		];
		// The bucket is seeded either way; only its contents are gated.
		if ( $count_global ) {
			if ( $record_timing ) {
				++$hourly['count'];
				$hourly['sum_ms'] += $duration_ms;
			}
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
				$acc['dim'][ $dim ][ $val ] = self::add_dim( $acc['dim'][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb );
			}

			// Per-server, skipping the dim that would only repeat the scope.
			if ( '' !== $server_key && 'server' !== $dim ) {
				$acc['dim_by_server'][ $server_key ][ $dim ][ $val ] = self::add_dim( $acc['dim_by_server'][ $server_key ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb );
			}

			// Per-URL.
			$acc['url_dim'][ $url_hash ][ $dim ][ $val ] = self::add_dim( $acc['url_dim'][ $url_hash ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb );
		}
	}

	/**
	 * Fold a request into a dimensional bucket: one more request, its timing,
	 * its peak memory. Seeds the bucket if this is the first.
	 *
	 * A slot arriving in any other shape is DISCARDED, not read, for the reason
	 * `add_cat()` states: the checkpoint carries no salt, so the first respawn
	 * after a deploy really does meet a pre-deploy `pending`.
	 *
	 * @param array{0: int, 1: float|int, 2: float|int}|null $slot     Bucket, null on first use.
	 * @param float                                          $duration Timing to add, 0 when untimed.
	 * @param float                                          $peak     Peak MB to add.
	 * @return array{0: int, 1: float|int, 2: float|int} The updated bucket.
	 */
	private static function add_dim( ?array $slot, float $duration, float $peak ): array {
		if ( ! isset( $slot[ Stats_Store::DIM_COUNT ] ) ) {
			$slot = [ Stats_Store::DIM_COUNT => 0, Stats_Store::DIM_SUM_MS => 0, Stats_Store::DIM_SUM_PEAK_MB => 0 ];
		}
		++$slot[ Stats_Store::DIM_COUNT ];
		$slot[ Stats_Store::DIM_SUM_MS ]      += $duration;
		$slot[ Stats_Store::DIM_SUM_PEAK_MB ] += $peak;
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
	 * @param int                       $now          Clock read for this request.
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
		int $now
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

		// Expire old per-URL categories.
		$cutoff = $now - Flame_Tree::AGGREGATE_EXPIRY_SEC;
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
	 * A slot arriving in any other shape is DISCARDED, not read: the offsetlog
	 * checkpoint carries no salt, so the first respawn after a deploy really
	 * does meet a pre-deploy `pending`, and reading it would be a second format
	 * to maintain. What it costs is one worker's un-flushed delta, which
	 * `restore_state()` already declares acceptable.
	 *
	 * @param array{0: float|int, 1: float|int, 2: int}|null $slot  Bucket, null on first use.
	 * @param float                                          $time  Time to add.
	 * @param float                                          $count Call count to add.
	 * @return array{0: float|int, 1: float|int, 2: int} The updated bucket.
	 */
	private static function add_cat( ?array $slot, float $time, float $count ): array {
		if ( ! isset( $slot[ Stats_Store::CAT_MS ] ) ) {
			$slot = [ Stats_Store::CAT_MS => 0, Stats_Store::CAT_CALLS => 0, Stats_Store::CAT_REQUESTS => 0 ];
		}
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
	 * Flush on a clean stop, so the checkpoint carries nothing forward.
	 *
	 * The periodic flush fires inside `fill()`, on a record arriving
	 * FLUSH_INTERVAL_SEC or more after the last flush, and an on-demand worker
	 * rarely sees one: it spawns on a backlog, folds it within the second and
	 * idles out. Without this every record it folded rode `$pending` into the
	 * checkpoint and out to the next worker, never reaching the store. The
	 * substrate runs the sweep before the cursor handoff, while the graph is
	 * intact, so `save_state()` then snapshots an empty `$pending`.
	 *
	 * A sibling partition may hold the auto-tune lock at that moment. A
	 * periodic flush leaves the decisions for the next flush; a stop has none,
	 * so it waits the lock out — the lock expires in seconds on its own. The
	 * rankings the cadence still owes are in memory alone, so this flush
	 * ranks every one of them whatever its stamp. A stale hour gets no more
	 * than a flush's bound: its missing marker is in the store, and the next
	 * worker's probe finds it again.
	 *
	 * @api Used by substrate.
	 */
	public function shutdown_sweep(): void {
		$this->ranked_at = [];
		$this->flush();
		$this->apply_auto_tune( self::AUTO_TUNE_LOCK_WAIT_MS );
	}

	/**
	 * Drain every accumulator and start clean.
	 *
	 * `fill()` calls this at most once per FLUSH_INTERVAL_SEC, and every clean
	 * stop calls it through `shutdown_sweep()`. The accumulators do not need
	 * it to survive a fatal: `save_state()` co-commits `$pending` with the read
	 * cursor, so the replay resumes from the cursor that last committed it.
	 * What a fatal DOES cost is a double-count — the deltas between the last
	 * checkpoint and the crash are already in memcache and get replayed on top
	 * (see the CHANGELOG's Known section).
	 *
	 * With no `Stats_Store` wired the drain is a no-op against storage: the
	 * accumulators still reset, but nothing is written anywhere.
	 *
	 * It reads the tick ONCE and builds the read plan from it once, so the
	 * hour plan, the ranking floor and the cadence agree on which hour closed.
	 */
	public function flush(): void {
		$now = (int) Core::$now;
		$this->mirror_url_stats( $now );
		$stats_store = $this->stats_store;
		if ( null !== $stats_store ) {
			$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( $stats_store->ttl(), $now ) );
			// @longform Roll up FIRST: it is what probes the coarse tier, and
			// `folded_hours` is per-process while one partition has one
			// worker, so a respawn is the only way the memo goes stale.
			// Probing before the writes are placed keeps the first flush after
			// one from leaving rows in fine buckets a folded hour replaced.
			$this->roll_up_hours( $stats_store, $plan );
			// Lexical order IS chronological, which is what bucket_key() buys.
			$this->persist_aggregate_stats( $stats_store, $now, (string) \end( $plan['fine'] ) );
		}
		$this->apply_auto_tune();

		$this->stats_store?->reset_url_stats();
		$this->pending = [];
	}

	/**
	 * Merge every pending bucket into memcache through `Stats_Store`.
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
	 * forgotten before the flush ranks what it owes, so the probe re-ranks it.
	 *
	 * The URL index files each server's rows under the server's own key, so
	 * the flush first reads the server index of every bucket it holds — one
	 * round trip — to decide which servers each bucket admits.
	 *
	 * @param Stats_Store $stats_store Destination.
	 * @param int         $now         The flush's one read of the tick.
	 * @param string      $floor       The oldest bucket of the read plan's
	 *                                 fine tail: nothing older ranks.
	 */
	private function persist_aggregate_stats( Stats_Store $stats_store, int $now, string $floor ): void {
		$intents  = [];
		$unfold   = [];
		$indexes  = $stats_store->server_index( [], \array_map( 'strval', \array_keys( $this->pending ) ) );
		$admitted = [];
		foreach ( $this->pending as $bucket => $acc ) {
			// @longform An all-digit server name is an INT key wherever PHP
			// stores it, so every name read off a key is cast back to string.
			$admitted[ $bucket ] = Stats_Store::admit_servers(
				$indexes[ $bucket ] ?? [],
				\array_map( 'strval', \array_keys( $acc['url_stats'] + $acc['url_stats_worker'] ) )
			);
		}
		foreach ( $this->persist_url_names( $stats_store, $now, $admitted ) as $server => $tokens ) {
			$key = Stats_Store::server_key( (string) $server );
			foreach ( $tokens as $token => $hashes ) {
				self::add_intent( $intents, self::intent(
					Stats_Store::url_token_parts( $key ),
					(string) $token,
					static fn ( array $existing ): array => $stats_store->merge_token_set( $existing, $hashes, $now ),
					function () use ( $hashes ): void {
						$this->print_less_often( 'token index write refused; ' . \count( $hashes ) . ' URLs left unfiled' );
					}
				) );
			}
		}
		foreach ( $this->pending as $bucket => $acc ) {
			foreach ( $this->url_intents( $bucket, $acc, $indexes[ $bucket ] ?? [], $admitted[ $bucket ] ) as $intent ) {
				self::add_intent( $intents, $intent );
			}
			// @longform A server new to the bucket may be new to its folded
			// hour, whose index the late write then names beside no hour
			// lists. Every intent is placed first, so the hour leaves the memo
			// only for the next flush's probe, which ranks it then rather than
			// after `REPROBE_EVERY_FLUSHES`.
			foreach ( $admitted[ $bucket ] as $as ) {
				if ( ! isset( $indexes[ $bucket ][ Stats_Store::server_key( $as ) ] ) ) {
					$unfold[ Stats_Store::hour_of( $bucket ) ] = true;
				}
			}
			if ( ! empty( $acc['hourly'] ) ) {
				$totals = $acc['hourly'];
				self::add_intent( $intents, self::intent(
					Stats_Store::hourly_parts(),
					$bucket,
					static fn ( array $existing ): array => Stats_Store::add_totals(
						Stats_Store::string_keys( $existing ),
						$totals
					)
				) );
			}
			foreach ( $acc['dim'] as $dim => $values ) {
				self::add_intent( $intents, self::dimension_intent( $bucket, $dim, $values, '' ) );
			}
			// '' is the GLOBAL scope downstream; a nameless server is not one.
			foreach ( $acc['dim_by_server'] as $server => $dims ) {
				if ( '' === $server ) {
					continue;
				}
				foreach ( $dims as $dim => $values ) {
					self::add_intent( $intents, self::dimension_intent( $bucket, $dim, $values, $server ) );
				}
			}
			foreach ( $acc['url_dim'] as $url_hash => $dims ) {
				self::add_intent( $intents, self::url_dimensions_intent( $bucket, (string) $url_hash, $dims ) );
			}
			if ( ! empty( $acc['cat'] ) ) {
				self::add_intent( $intents, self::categories_intent( $bucket, $acc['cat'], '' ) );
			}
			foreach ( $acc['cat_by_server'] as $server => $cats ) {
				if ( '' === $server ) {
					continue;
				}
				self::add_intent( $intents, self::categories_intent( $bucket, $cats, $server ) );
			}
			foreach ( $acc['cat_by_url'] as $url_hash => $cats ) {
				self::add_intent( $intents, self::url_categories_intent( $bucket, (string) $url_hash, $cats ) );
			}
			$boards = [ '' => $acc['leaderboard'] ];
			foreach ( $acc['leaderboard_by_server'] as $server => $sums ) {
				if ( '' !== $server ) {
					$boards[ $server ] = $sums;
				}
			}
			foreach ( $boards as $server => $sums ) {
				if ( ( $sums['count'] ?? 0 ) <= 0 ) {
					continue;
				}
				foreach ( $this->leaderboard_intents( $bucket, $sums, $server ) as $intent ) {
					self::add_intent( $intents, $intent );
				}
			}
		}
		$this->folded_hours = \array_diff_key( $this->folded_hours, $unfold );
		// @longform Ranked per CHUNK, not after the flush: the collectors hold
		// every merged reader shard of every bucket still waiting to rank, and
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
		foreach ( $this->unranked_hours as $hour => $keys ) {
			foreach ( \array_keys( $keys ) as $key ) {
				$stats_store->bucket_forget( Stats_Store::url_rank_done_parts( (string) $key ), $hour );
			}
		}
		$this->unranked_hours = [];
		$this->rank_owed( $stats_store, $now, $floor );
	}

	/**
	 * One bucket's URL index intents: each family's rows filed under the
	 * server `Stats_Store::admit_servers()` admits them as, one intent per
	 * server per shard, and the index naming them.
	 *
	 * @param string               $bucket   Bucket key.
	 * @param Bucket_Acc           $acc      The bucket's accumulator.
	 * @param array<string,string> $index    The bucket's stored server index.
	 * @param array<string,string> $admitted Server => the name its rows are filed under.
	 * @return list<Pending_Write>
	 */
	private function url_intents( string $bucket, array $acc, array $index, array $admitted ): array {
		$entries = [];
		$out     = [];
		foreach ( [ 0 => $acc['url_stats'], 1 => $acc['url_stats_worker'] ] as $worker => $servers ) {
			$filed = [];
			foreach ( $servers as $server => $rows ) {
				$as           = $admitted[ (string) $server ];
				$filed[ $as ] = isset( $filed[ $as ] ) ? self::merge_url_rows( $filed[ $as ], $rows ) : $rows;
			}
			foreach ( $filed as $as => $rows ) {
				$name = \strval( $as );
				$entries[ Stats_Store::server_key( $name ) ] = $name;
				foreach ( Stats_Store::rows_by_shard( $rows, 1 === $worker ) as $shard => $shard_rows ) {
					\array_push( $out, ...$this->url_shard_intent( $bucket, $name, \strval( $shard ), $shard_rows ) );
				}
			}
		}
		if ( [] === $entries ) {
			return [];
		}
		$this->flushed_index[ $bucket ] = $index + $entries;
		return [ ...$out, ...$this->url_srv_intents( $bucket, $entries ) ];
	}

	/**
	 * How one bucket's newly filed servers join its stored index: a union,
	 * through the same tier choice the rows make.
	 *
	 * @param string               $bucket  Bucket key.
	 * @param array<string,string> $entries server_key => name.
	 * @return list<Pending_Write>
	 */
	private function url_srv_intents( string $bucket, array $entries ): array {
		return $this->hour_tier_intents(
			$bucket,
			Stats_Store::url_srv_parts( false ),
			Stats_Store::url_srv_parts( true ),
			static fn ( array $existing ): array => \array_replace( Stats_Store::string_map( $existing ), $entries ),
			function ( string $key ): void {
				$this->print_less_often( 'URL server index write refused; its servers\' rows are unreachable unscoped', " — {$key}" );
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
	 * worker's probe, which finds each missing marker again.
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
	 * bucket's servers come from what this flush filed, else from its stored
	 * index, read for every such bucket in one round trip.
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
		$indexes = $this->flushed_index + ( [] === $unknown ? [] : $stats_store->server_index( [], $unknown ) );
		// A group is one (server, bucket) pair, a shard read apiece.
		$budget = \intdiv( self::WRITE_BATCH_KEYS, Stats_Store::URL_SHARDS );
		$chunk  = [];
		$size   = 0;
		foreach ( $due as $bucket ) {
			$servers = \array_values( \array_unique( [
				...\array_values( $indexes[ $bucket ] ?? [] ),
				...\array_map( 'strval', \array_keys( $this->flushed_rows[ $bucket ] ?? [] ) ),
			] ) );
			if ( [] !== $chunk && $size + \count( $servers ) > $budget ) {
				$this->rank_bucket_chunk( $stats_store, $chunk, $now );
				$chunk = [];
				$size  = 0;
			}
			$chunk[ $bucket ] = $servers;
			$size            += \max( 1, \count( $servers ) );
		}
		if ( [] !== $chunk ) {
			$this->rank_bucket_chunk( $stats_store, $chunk, $now );
		}
	}

	/**
	 * Rank each bucket over its whole STORED content: the reader-family rows
	 * this flush landed, plus every shard of every server it did not, read
	 * back for the whole chunk in ONE round trip — a read per bucket would be
	 * a round trip per bucket on a replay. A deferred bucket landed none, so
	 * it gap-fills all sixteen of every server.
	 *
	 * @param Stats_Store                      $stats_store Source and destination.
	 * @param array<string,list<string>>       $chunk       Due bucket => its servers,
	 *                                                      at most one write chunk's
	 *                                                      worth of ranking groups.
	 * @param int                              $now         The caller's one read of the tick.
	 */
	private function rank_bucket_chunk( Stats_Store $stats_store, array $chunk, int $now ): void {
		$reads = [];
		$owner = [];
		foreach ( $chunk as $bucket => $servers ) {
			foreach ( $servers as $server ) {
				$landed = $this->flushed_rows[ $bucket ][ $server ] ?? [];
				foreach ( \array_diff( Stats_Store::url_shards(), \array_keys( $landed ) ) as $shard ) {
					$reads[] = [ Stats_Store::url_shard_parts( Stats_Store::server_key( $server ), $shard ), $bucket ];
					$owner[] = [ $bucket, $server ];
				}
			}
		}
		$read_maps = [];
		foreach ( [] === $reads ? [] : $stats_store->bucket_get_multi( $reads ) as $at => $value ) {
			if ( null !== $value ) {
				[ $bucket, $server ]               = $owner[ $at ];
				$read_maps[ $bucket ][ $server ][] = $value;
			}
		}
		foreach ( $chunk as $bucket => $servers ) {
			$rows = [];
			foreach ( $servers as $server ) {
				$rows[ $server ] = \array_replace(
					[],
					...\array_values( $this->flushed_rows[ $bucket ][ $server ] ?? [] ),
					...( $read_maps[ $bucket ][ $server ] ?? [] )
				);
			}
			$this->write_url_ranks( $stats_store, $bucket, $rows, false );
			$this->ranked_at[ $bucket ] = $now;
			unset( $this->rank_pending[ $bucket ] );
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
	 * Store the names of URLs this flush saw, once each per server they are
	 * filed under.
	 *
	 * A stored row carries the hash alone, so the name table is what a reader
	 * resolves a displayed page through. Held pairs are skipped until half the
	 * retention window has passed, which re-writes a name that is still in use
	 * well before its own TTL retires it.
	 *
	 * Each name also files the search index of the server its rows are filed
	 * under — its own, or `Other` past the index cap — which is why the memo
	 * holds the pair. A path costs one read-merge-write key per prefix of
	 * every word in it, `sum( min( word, 12 ) - 2 )` over the words of three
	 * characters or more: six for `/wombat-7731`, nine for
	 * `/blog/2026/my-post-title`, and 30 for a path of three words of twelve
	 * characters or more. That is paid once per URL per half-window, not once
	 * per flush.
	 *
	 * @param Stats_Store                        $stats_store The wired store.
	 * @param int                                $now         The flush's one read of the tick.
	 * @param array<string,array<string,string>> $admitted    Bucket => server => the
	 *                                                        name its rows are filed under.
	 * @return array<array-key,array<array-key,list<string>>> server => token => hashes,
	 *                                                        for the names written. An
	 *                                                        all-digit token is an INT key.
	 */
	private function persist_url_names( Stats_Store $stats_store, int $now, array $admitted ): array {
		$refresh = \max( 1, (int) ( $stats_store->max_lifespan() / 2 ) );
		$due     = [];
		$filed   = [];
		foreach ( $this->pending as $bucket => $acc ) {
			foreach ( [ $acc['url_stats'], $acc['url_stats_worker'] ] as $servers ) {
				foreach ( $servers as $server => $rows ) {
					$as = $admitted[ $bucket ][ (string) $server ];
					foreach ( \array_keys( $rows ) as $hash ) {
						if ( ! isset( $acc['url_names'][ $hash ] ) ) {
							continue;
						}
						$held    = Stats_Store::server_key( $as ) . ':' . $hash;
						$written = $this->named_urls->get( $held );
						if ( null !== $written && $now - Core::num_int( $written ) < $refresh ) {
							continue;
						}
						$url                            = $acc['url_names'][ $hash ];
						$due[ $hash ]                   = $url;
						$filed[ $as ][ (string) $hash ] = $url;
						$this->named_urls->set( $held, $now );
					}
				}
			}
		}
		$stats_store->set_url_names( $due );
		return \array_map(
			static fn ( array $names ): array => Stats_Store::token_sets_of( Stats_Store::paths_of( $names ) ),
			$filed
		);
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
	 * A `present` intent whose read MISSED writes nothing and forgets its key
	 * instead: the key is derived, its source took the same write, and the
	 * reprobe re-folds a missing key from that source. One whose write was
	 * REFUSED is forgotten the same way, since its source took the late rows.
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
		foreach ( self::chunk_intents( $intents ) as $chunk ) {
			$reads = [];
			foreach ( $chunk as $key => $intent ) {
				$reads[ $key ] = [ $intent['parts'], $intent['bucket'] ];
			}
			$existing = $stats_store->bucket_get_multi( $reads, $failed );
			if ( $failed ) {
				$this->print_less_often( 'stats flush read failed; a chunk\'s deltas are dropped', ' — ' . \count( $chunk ) . ' keys' );
				continue;
			}
			$writes   = [];
			$groups   = [];
			foreach ( $chunk as $key => $intent ) {
				if ( $intent['present'] && null === $existing[ $key ] ) {
					$stats_store->bucket_forget( $intent['parts'], $intent['bucket'] );
					continue;
				}
				if ( null !== $intent['group'] ) {
					$groups[ $intent['group'] ] = true;
				}
				$read  = $existing[ $key ] ?? [];
				$value = ( $intent['merge'] )( $read );
				// @longform A merge that changed nothing is not a write: it
				// would cost a round trip and refresh a TTL the value has not
				// earned, so a key nothing adds to ages out on the one it has.
				if ( $value === $read ) {
					( $intent['landed'] ?? null )?->__invoke( $value );
					continue;
				}
				$writes[ $key ] = [ $intent['parts'], $intent['bucket'], $value ];
			}
			$keys = \array_keys( $writes );
			foreach ( $stats_store->bucket_set_multi( \array_values( $writes ) ) as $at => $landed ) {
				$key    = $keys[ $at ];
				$intent = $chunk[ $key ];
				if ( $landed ) {
					( $intent['landed'] ?? null )?->__invoke( $writes[ $key ][2] );
					continue;
				}
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
	 * A group is one bucket's reader-family row and name writes — 32 keys at
	 * most — and it ranks when its last one is answered, so a group split
	 * across chunks would rank the bucket twice and read back every shard the
	 * second chunk still held.
	 *
	 * @param array<string,Pending_Write> $intents Pending writes, by cache key.
	 * @return list<array<string,Pending_Write>>
	 */
	private static function chunk_intents( array $intents ): array {
		$units = [];
		foreach ( $intents as $key => $intent ) {
			// A group's keys travel together; everything else is a unit of one.
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
	 * Fold every closed hour that has not been folded yet into its coarse key.
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
	 * folded; an hour holding every derived key but some server's DONE
	 * marker, which a late write that lands forgets, is never folded again,
	 * but joins `stale_hours`, whose lists the flush ranks from those stored
	 * rows. Only a fold spends the budget; such an hour
	 * spends none, and a spent budget stops the folds but never the probe, so
	 * every hour the probe found folded is memoized in the same flush.
	 *
	 * @param Stats_Store                                    $stats_store Source and destination.
	 * @param array{fine: list<string>, hours: list<string>} $plan        The flush's read plan.
	 */
	public function roll_up_hours( Stats_Store $stats_store, array $plan ): void {
		// @longform Drop what left the window, so the memo cannot outgrow it —
		// and empty it outright now and then, because an evicted hour is not
		// re-foldable while this process still believes it folded one.
		if ( ++$this->folds_since_reprobe >= self::REPROBE_EVERY_FLUSHES ) {
			$this->folds_since_reprobe = 0;
			$this->folded_hours        = [];
		}
		$planned            = \array_flip( $plan['hours'] );
		$this->folded_hours = \array_intersect_key( $this->folded_hours, $planned );
		// No reader plans an hour the window passed; its lists are owed no one.
		$this->stale_hours = \array_intersect_key( $this->stale_hours, $planned );
		$unknown = \array_values( \array_diff( $plan['hours'], \array_keys( $this->folded_hours ) ) );
		// @longform ONE round trip, and only for hours this process did not
		// fold itself. The probe reads presence but `getMulti` fetches and
		// unserializes the VALUES, so probing the settled hours would pull
		// the whole coarse tier off memcache twelve times a minute — the
		// tier that exists so a READER does not have to. It asks about every
		// derived key an hour holds: an hour missing any of them, evicted or
		// forgotten by a late write, otherwise reads as settled forever.
		$found  = [] === $unknown ? [] : $stats_store->url_hours_derived( $unknown );
		$budget = self::ROLLUP_HOURS_PER_FLUSH;
		foreach ( $unknown as $hour ) {
			// @longform A partial fold — a crash between shards — reads as
			// unfolded and is simply redone, which costs a repeat and cannot
			// corrupt: the fold overwrites rather than adding.
			$folded   = ! empty( $found[ $hour ]['folded'] );
			$unranked = $found[ $hour ]['unranked'] ?? [];
			// @longform Folded with a server unmarked: one whose marker was
			// evicted or refused, or one a late write landed in. Its fine
			// buckets may be gone, so folding again would overwrite it with
			// nothing; its lists come from the coarse rows, which is what they
			// are derived from anyway.
			if ( $folded ) {
				if ( [] !== $unranked ) {
					$this->stale_hours[ $hour ] = true;
				}
				$this->folded_hours[ $hour ] = true;
				continue;
			}
			// Past the budget a fold waits; the probe goes on.
			if ( $budget <= 0 ) {
				continue;
			}
			$this->fold_hour_into_store( $stats_store, $hour );
			$this->folded_hours[ $hour ] = true;
			--$budget;
		}
	}

	/**
	 * Fold one hour's fine buckets into its coarse rows, server index and
	 * lists.
	 *
	 * Every server the hour's buckets name is folded apart, every shard of
	 * both families written for it, empty or not, so the probe can tell a
	 * folded hour from a missing key. The rows arrive in one round trip per
	 * write chunk however many servers the hour holds.
	 *
	 * A refused write is logged and not retried by the flush. The caller
	 * memoizes the hour folded, and an hour left missing a row shard is
	 * re-derived at the next reprobe, like an evicted one.
	 *
	 * @param Stats_Store $stats_store Source and destination.
	 * @param string      $hour        Hour key.
	 */
	private function fold_hour_into_store( Stats_Store $stats_store, string $hour ): void {
		$buckets = Stats_Store::buckets_in_hour( $hour );
		$shards  = \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) );
		$index   = [];
		foreach ( $stats_store->server_index( [], $buckets ) as $named ) {
			$index += $named;
		}
		$reads = [];
		$owner = [];
		foreach ( $index as $key => $server ) {
			foreach ( $shards as $shard ) {
				foreach ( $buckets as $bucket ) {
					$reads[] = [ Stats_Store::url_shard_parts( $key, $shard ), $bucket ];
					$owner[] = [ $server, $shard ];
				}
			}
		}
		$rows = \array_fill_keys( \array_values( $index ), [] );
		foreach ( \array_chunk( $reads, self::WRITE_BATCH_KEYS, true ) as $chunk ) {
			foreach ( $stats_store->bucket_get_multi( $chunk ) as $at => $value ) {
				if ( null !== $value ) {
					[ $server, $shard ]        = $owner[ $at ];
					$rows[ $server ][ $shard ] = self::merge_url_rows( $rows[ $server ][ $shard ] ?? [], $value );
				}
			}
		}
		// One write list: a fold's round trips become one per chunk.
		$writes = [
			[ Stats_Store::lb_hour_parts(), $hour, self::fold_hour_leaderboard( $stats_store, $hour ) ],
		];
		$ranked = [];
		$named  = [];
		foreach ( self::cap_hour_servers( $rows ) as $server => $server_rows ) {
			$key           = Stats_Store::server_key( (string) $server );
			$named[ $key ] = (string) $server;
			// @longform Per SHARD, and not regroupable: each shard carries its
			// own `Other` overflow row, which a merge by hash would collapse.
			foreach ( $shards as $shard ) {
				// Capped ONCE, after twelve buckets rather than after each.
				$shard_rows = self::cap_url_rows( $server_rows[ $shard ] ?? [] );
				$writes[]   = [ Stats_Store::url_hour_parts( $key, $shard ), $hour, $shard_rows ];
				// The lists rank the READER family; a worker row never ranks.
				if ( ! self::is_worker_shard( $shard ) ) {
					$ranked[ (string) $server ] = ( $ranked[ (string) $server ] ?? [] ) + Stats_Store::string_keys( $shard_rows );
				}
			}
		}
		$writes[] = [ Stats_Store::url_srv_parts( true ), $hour, $named ];
		foreach ( \array_chunk( $writes, self::WRITE_BATCH_KEYS ) as $chunk ) {
			// @longform The batch answers per write, so a refusal names the
			// KEY that was lost rather than the hour holding its candidates:
			// which shard is over the item limit is the operator's next move.
			foreach ( $stats_store->bucket_set_multi( $chunk ) as $at => $ok ) {
				if ( $ok ) {
					continue;
				}
				$this->print_less_often(
					'hour fold write refused; a shard is over the cache item limit',
					' — ' . Stats_Store::key( ...[ ...$chunk[ $at ][0], $chunk[ $at ][1] ] )
				);
			}
		}
		$this->write_url_ranks( $stats_store, $hour, $ranked, true );
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
				$rows[ Stats_Store::OTHER_KEY ][ $shard ] = self::merge_url_rows( $rows[ Stats_Store::OTHER_KEY ][ $shard ] ?? [], $shard_rows );
			}
			unset( $rows[ $server ] );
		}
		return $rows;
	}

	/**
	 * Rank each stale hour of `$hours` from the coarse rows it already holds,
	 * every server its index names, `ROLLUP_HOURS_PER_FLUSH` hours at a time.
	 *
	 * Each chunk's rows arrive after one index read in ONE round trip: a read
	 * per hour is a round trip per hour on a replay. Each ranked hour leaves
	 * the stale set, its lists landed or refused.
	 *
	 * @param Stats_Store  $stats_store Source and destination.
	 * @param list<string> $hours       Hour keys.
	 */
	private function rank_hours_from_store( Stats_Store $stats_store, array $hours ): void {
		foreach ( \array_chunk( $hours, self::ROLLUP_HOURS_PER_FLUSH ) as $chunk ) {
			$rows = [];
			foreach ( $stats_store->url_hour_sources( $chunk ) as [ $hour, $shard_rows, $server ] ) {
				$rows[ $hour ][ $server ] = ( $rows[ $hour ][ $server ] ?? [] ) + Stats_Store::string_keys( $shard_rows );
			}
			foreach ( $chunk as $hour ) {
				$this->write_url_ranks( $stats_store, $hour, $rows[ $hour ] ?? [], true );
				unset( $this->stale_hours[ $hour ] );
			}
		}
	}

	/**
	 * Overwrite every ranked list of one bucket or hour from its merged rows,
	 * fourteen for each server named. An overwrite, not a merge — the lists
	 * are derived from the stored rows, which one partition's one worker
	 * just wrote.
	 *
	 * Every caller merges each server's shards by hash first: they are
	 * disjoint by hash, so one union IS the per-shard union.
	 *
	 * The tier picks its own list depth, the way `cap_dim()` picks its field
	 * table: a pairing that can only go one way is not a parameter.
	 *
	 * A refused list is logged and not retried: the lists are top-N bounded
	 * to fit, so a refusal is a wrong N, and a transient failure heals at
	 * the ranking the next write into the key brings. On the hour tier each
	 * server's DONE marker, which is what `url_hours_derived()` probes, rides
	 * the same batch whatever the lists answer, so no re-probe ranks the hour
	 * again; the reader serves an hour ranked only where every list is present.
	 *
	 * @param Stats_Store                             $stats_store Destination.
	 * @param string                                  $key         Bucket or hour key.
	 * @param array<array-key,array<array-key,mixed>> $servers     Server => the tier's merged rows by hash.
	 * @param bool                                    $hour        The coarse tier.
	 */
	private function write_url_ranks( Stats_Store $stats_store, string $key, array $servers, bool $hour ): void {
		$writes = Stats_Store::ranked_writes( $servers, $hour, $key );
		// Nothing probes the fine tier; the marker's PRESENCE is the fact.
		foreach ( $hour ? \array_keys( $servers ) : [] as $server ) {
			$writes[] = [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( (string) $server ) ), $key, [] ];
		}
		$landed = true;
		foreach ( \array_chunk( $writes, self::WRITE_BATCH_KEYS ) as $chunk ) {
			$landed = ! \in_array( false, $stats_store->bucket_set_multi( $chunk ), true ) && $landed;
		}
		if ( ! $landed ) {
			$this->print_less_often( 'URL rank write refused; a ranked list is over the cache item limit', " — {$key}" );
		}
	}

	/**
	 * One hour's twelve fine leaderboard buckets, merged into the hour's.
	 *
	 * The heaviest read the dashboard makes, folded once at write time instead
	 * of 288 times per poll — decision 17's answer for `urls`, applied to the
	 * namespace that costs more than it does. The same shape a fine bucket
	 * holds, so ONE `build_leaderboard()` fold serves both tiers.
	 *
	 * @param Stats_Store $stats_store Source and destination.
	 * @param string      $hour        Hour key.
	 * @return array<string,mixed>
	 */
	private static function fold_hour_leaderboard( Stats_Store $stats_store, string $hour ): array {
		$merged = [];
		foreach ( $stats_store->get_leaderboard_buckets( Stats_Store::buckets_in_hour( $hour ) ) as $row ) {
			if ( \is_array( $row ) ) {
				Stats_Store::merge_leaderboard_bucket( $merged, Stats_Store::string_keys( $row ) );
			}
		}
		return $merged;
	}

	/**
	 * How one server's shard of accumulated rows folds into its stored bucket.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param string                 $server The server the rows are filed under.
	 * @param string                 $shard  Shard name from `Stats_Store::url_shard()`.
	 * @param array<array-key,mixed> $rows   That shard's accumulated rows.
	 * @return list<Pending_Write>
	 */
	private function url_shard_intent( string $bucket, string $server, string $shard, array $rows ): array {
		$key = Stats_Store::server_key( $server );
		return $this->hour_tier_intents(
			$bucket,
			Stats_Store::url_shard_parts( $key, $shard ),
			Stats_Store::url_hour_parts( $key, $shard ),
			static fn ( array $existing ): array => self::cap_url_rows(
				self::merge_url_rows( $existing, $rows )
			),
			// @longform The largest blob the schema writes. memcached refuses
			// an item over its limit, and a discarded return loses this
			// server's whole shard of the bucket — again on every later merge.
			function ( string $key ) use ( $rows ): void {
				// Rows, not bytes: sizing re-serialises a megabyte a flush.
				$this->print_less_often(
					'URL index write refused in ' . Stats_Store::namespace_of( $key ) . '; a shard is over the cache item limit and its rows are lost',
					\sprintf( ' — %s, %d rows', $key, \count( $rows ) )
				);
			},
			self::is_worker_shard( $shard ) ? null : function ( array $merged ) use ( $bucket, $server, $shard ): void {
				$this->flushed_rows[ $bucket ][ $server ][ $shard ] = $merged;
			},
			$key
		);
	}

	/**
	 * Whether a shard holds the WORKER family, whose rows never rank.
	 *
	 * @param string $shard Shard name from `Stats_Store::url_shard()`.
	 */
	private static function is_worker_shard( string $shard ): bool {
		return \str_starts_with( $shard, Stats_Store::WORKER_SHARD_PREFIX );
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
	 * Cap one server's shard of rows.
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
		$keep = self::MAX_URLS_PER_SHARD - \count( $other );
		if ( \count( $rows ) > $keep ) {
			\uasort(
				$rows,
				static fn ( $a, $b ) => Core::num_int( Core::arr( $b )[ Stats_Store::ROW_COUNT ] ?? null )
					<=> Core::num_int( Core::arr( $a )[ Stats_Store::ROW_COUNT ] ?? null )
			);
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
	 * How one URL's dimensional bucket folds — every dimension in one value,
	 * each capped on its own.
	 *
	 * @param string              $bucket   Bucket key.
	 * @param string              $url_hash 12-char URL hash.
	 * @param array<string,mixed> $dims     Accumulated value maps by dimension.
	 * @return Pending_Write
	 */
	private static function url_dimensions_intent( string $bucket, string $url_hash, array $dims ): array {
		return self::intent(
			Stats_Store::url_dim_parts( $url_hash ),
			$bucket,
			static function ( array $existing ) use ( $dims ): array {
				foreach ( $dims as $dim => $values ) {
					$merged = Stats_Store::sum_fields( Core::arr( $existing[ $dim ] ?? null ), Core::arr( $values ), Stats_Store::DIM_SUMS );
					$existing[ $dim ] = self::cap_dim( $merged, Stats_Store::dim_cap( $dim, Stats_Store::MAX_URL_DIM_VALUES ) );
				}
				return Stats_Store::string_keys( $existing );
			}
		);
	}

	/**
	 * How one URL's category bucket folds, capped.
	 *
	 * @param string              $bucket   Bucket key.
	 * @param string              $url_hash 12-char URL hash.
	 * @param array<string,mixed> $cats     Accumulated categories.
	 * @return Pending_Write
	 */
	private static function url_categories_intent( string $bucket, string $url_hash, array $cats ): array {
		return self::intent(
			Stats_Store::url_cat_parts( $url_hash ),
			$bucket,
			static fn ( array $existing ): array => self::fold_categories( $existing, $cats )
		);
	}

	/**
	 * How one scope's leaderboard bucket folds, capped.
	 *
	 * The global series has an hour tier, `lb_h`, so its write takes the same
	 * late-write rule the URL index does; a server scope has none.
	 *
	 * @param string              $bucket Bucket key.
	 * @param array<string,mixed> $sums   Accumulated sums.
	 * @param string              $server Reporting server; '' is the global series.
	 * @return list<Pending_Write>
	 */
	private function leaderboard_intents( string $bucket, array $sums, string $server ): array {
		$merge = static function ( array $existing ) use ( $sums ): array {
			$existing = Stats_Store::string_keys( $existing );
			if ( empty( $existing ) ) {
				$existing = self::empty_leaderboard();
			}
			Stats_Store::merge_leaderboard_bucket( $existing, $sums );
			self::cap_leaderboard_entries( $existing );
			return $existing;
		};
		return '' === $server
			? $this->hour_tier_intents( $bucket, Stats_Store::lb_parts( '' ), Stats_Store::lb_hour_parts(), $merge, null, null )
			: [ self::intent( Stats_Store::lb_parts( $server ), $bucket, $merge ) ];
	}

	/**
	 * One write into an hour-derived namespace: into its fine bucket, and
	 * once its hour has folded, into the hour key too while that key exists.
	 *
	 * An hour key is derived, so a late write — a replay, an ingest, a spoke
	 * feeding backlog past its caught-up peers — goes to the source it is
	 * derived from, and to the derived copy only where that copy exists. The
	 * reader takes a folded hour's key and never its fine buckets, so the
	 * hour-key merge is what it sees now; the fine write is what a re-fold
	 * reproduces the hour from, late rows included, and it keeps the fine
	 * TTL like any fold read that re-warms the bucket. Merged into a MISSING
	 * hour key, the rows would recreate it holding themselves alone, which
	 * the probe reads as folded; `flush_writes()` forgets it instead.
	 *
	 * Only a write that collects for the ranker ranks, and it is one
	 * server's. Into an unfolded hour it names its ranking GROUP, the
	 * (bucket, server) pair; into a folded one its bucket sits behind the
	 * fine tail, and its hour-key write, once it lands, forgets that server's
	 * DONE marker so the probe re-ranks its hour from its rows.
	 *
	 * @param string                                                   $bucket  Bucket key.
	 * @param array<int,string>                                        $fine    Namespace prefix in the fine tier.
	 * @param array<int,string>                                        $coarse  Namespace prefix in the hour tier.
	 * @param \Closure(array<array-key,mixed>): array<array-key,mixed> $merge   Fold.
	 * @param ?\Closure(string): void                                  $refused Called with the key a refused set lost.
	 * @param ?\Closure(array<array-key,mixed>): void                  $collect What a ranked write collects; null
	 *                                                                          where nothing ranks.
	 * @param ?string                                                  $server_key The server a collecting
	 *                                                                             write's rows are filed under.
	 * @return list<Pending_Write>
	 */
	private function hour_tier_intents( string $bucket, array $fine, array $coarse, \Closure $merge, ?\Closure $refused, ?\Closure $collect, ?string $server_key = null ): array {
		$hour = Stats_Store::hour_of( $bucket );
		if ( ! isset( $this->folded_hours[ $hour ] ) ) {
			return [ self::intent( $fine, $bucket, $merge, $refused, $collect, null === $collect ? null : "{$bucket} {$server_key}" ) ];
		}
		$unrank = null === $collect ? null : function () use ( $hour, $server_key ): void {
			$this->unranked_hours[ $hour ][ (string) $server_key ] = true;
		};
		return [
			self::intent( $fine, $bucket, $merge, $refused ),
			self::intent( $coarse, $hour, $merge, $refused, $unrank, null, true ),
		];
	}

	/**
	 * Cap each leaderboard category's entries to the global limit (sorted by sum_time).
	 *
	 * Same hysteresis as accumulation: trim only past UPPER, and trim to LOWER.
	 *
	 * @param array<string,mixed> $bucket Leaderboard bucket, modified in place.
	 */
	private static function cap_leaderboard_entries( array &$bucket ): void {
		$categories = $bucket['categories'] ?? null;
		if ( ! \is_array( $categories ) ) {
			return;
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
		$bucket['categories'] = $categories;
	}

	/**
	 * Trim an entry map back to `$lower` once it passes `$upper`, keeping the
	 * slowest by `sum_time`. The gap between the two bounds is the hysteresis
	 * that stops a busy category re-sorting on every request.
	 *
	 * Takes `array-key,mixed` because both callers are real: one holds accumulator
	 * state, the other a bucket decoded from memcache whose entries can be any
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
	 * How one dimension's bucket folds, capped.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param string                 $dim    Dimension name.
	 * @param array<array-key,mixed> $values Accumulated values.
	 * @param string                 $server Reporting server; '' is the global series.
	 * @return Pending_Write
	 */
	private static function dimension_intent( string $bucket, string $dim, array $values, string $server ): array {
		return self::intent(
			Stats_Store::dim_parts( $dim, $server ),
			$bucket,
			static fn ( array $existing ): array => self::cap_dim(
				Stats_Store::sum_fields( $existing, $values, Stats_Store::DIM_SUMS ),
				Stats_Store::dim_cap( $dim, Stats_Store::MAX_DIM_VALUES )
			)
		);
	}

	/**
	 * How one scope's category bucket folds, capped.
	 *
	 * @param string                 $bucket Bucket key.
	 * @param array<array-key,mixed> $cats   Accumulated categories.
	 * @param string                 $server Reporting server; '' is the global series.
	 * @return Pending_Write
	 */
	private static function categories_intent( string $bucket, array $cats, string $server ): array {
		return self::intent(
			Stats_Store::cat_parts( $server ),
			$bucket,
			static fn ( array $existing ): array => self::fold_categories( $existing, $cats )
		);
	}

	/**
	 * File one intent under its cache KEY, composing onto whatever is already
	 * there — the merges in sequence, the hooks chained.
	 *
	 * Two fine buckets of a folded hour land on one `urls_h` key, and composed
	 * here one pre-read serves one write: a second merge built on the same
	 * pre-read value would discard the first's rows outright. The held
	 * intent's `present` stands: only an hour-tier intent is present, and no
	 * fine intent shares its key.
	 *
	 * @param array<string,Pending_Write> $intents The flush's intents, by key.
	 * @param Pending_Write               $intent  The intent to file.
	 */
	private static function add_intent( array &$intents, array $intent ): void {
		$key  = Stats_Store::key( ...[ ...$intent['parts'], $intent['bucket'] ] );
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
			$held['group'] ?? $intent['group'],
			$held['present']
		);
	}

	/**
	 * One pending write: where it goes, and how to fold this flush's numbers
	 * onto whatever is already there.
	 *
	 * @param array<int,string>                        $parts   Namespace prefix.
	 * @param string                                   $bucket  Bucket (or hour) key.
	 * @param \Closure(array<array-key,mixed>): array<array-key,mixed> $merge Fold.
	 * @param ?\Closure(string): void                  $refused Called with the key a rejected set lost.
	 * @param ?\Closure(array<array-key,mixed>): void  $landed  Called with the merged value once the set landed.
	 * @param ?string                                  $group   The ranking group this write belongs to,
	 *                                                          which is the bucket for a fine-tier URL
	 *                                                          row or name write and null for everything else.
	 * @param bool                                     $present Write only where the key already
	 *                                                          exists: a derived key's late write.
	 * @return Pending_Write
	 */
	private static function intent( array $parts, string $bucket, \Closure $merge, ?\Closure $refused = null, ?\Closure $landed = null, ?string $group = null, bool $present = false ): array {
		return [
			'parts'   => $parts,
			'bucket'  => $bucket,
			'merge'   => $merge,
			'refused' => $refused,
			'landed'  => $landed,
			'group'   => $group,
			'present' => $present,
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
	 * Cap a bucket's value map to the top `$max_values`, rolling the tail into a
	 * synthetic `Other`.
	 *
	 * The dimensional and category caps differ only in what they sort by, which
	 * fields they sum, and whether a reserved row (`total`) is lifted clear of the
	 * ranking — so they are arguments, not two functions.
	 *
	 * Key-agnostic: a decoded bucket can carry int keys (a numeric value name);
	 * the body only ever names `Other` and the caller's reserved row.
	 *
	 * @param array<array-key,mixed> $values     One bucket's values.
	 * @param int                    $max_values Ceiling on distinct values, synthetic slots included.
	 * @param string|int             $sort_field Field ranking survivors, descending.
	 * @param array<array-key,bool>  $fields     Field key => is a whole count.
	 * @param string|null            $reserved   Row held out of the ranking and restored after.
	 * @return array<array-key,mixed>
	 */
	private static function cap_bucket( array $values, int $max_values, string|int $sort_field, array $fields, ?string $reserved = null ): array {
		if ( \count( $values ) <= $max_values ) {
			return $values;
		}
		$held = null;
		if ( null !== $reserved ) {
			$held = $values[ $reserved ] ?? null;
			unset( $values[ $reserved ] );
		}
		// One slot for the overflow key, one more for a reserved row.
		$keep = \max( 0, $max_values - ( null === $held ? 1 : 2 ) );
		\uasort(
			$values,
			fn( $a, $b ) => ( \is_array( $b ) && \is_numeric( $b[ $sort_field ] ?? null ) ? $b[ $sort_field ] : 0 )
				<=> ( \is_array( $a ) && \is_numeric( $a[ $sort_field ] ?? null ) ? $a[ $sort_field ] : 0 )
		);
		$top  = \array_slice( $values, 0, $keep, true );
		$rest = [];
		foreach ( \array_slice( $values, $keep ) as $v ) {
			$rest = Stats_Store::sum_fields( $rest, [ Stats_Store::OTHER_KEY => Core::arr( $v ) ], $fields );
		}
		$top = Stats_Store::sum_fields( $top, $rest, $fields );
		if ( null !== $held ) {
			$top[ $reserved ] = $held;
		}
		return $top;
	}

	/**
	 * Emit the accumulated auto-tune decisions: hooks and custom events to
	 * disable, and events newly promoted to significant.
	 *
	 * The decisions become a ruleset rewrite, and every flame-builder partition
	 * accumulates its own. A memcache `add()` with a 5-second expiry serves as a
	 * distributed lock so only one worker rewrites at a time; losing the race is
	 * not an error, the decisions simply survive to the next flush.
	 *
	 * Two escapes skip the lock and fire directly: no `Stats_Store` (the test
	 * configuration) and no shared memcache handle (single-process, nothing to
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

		$cache        = Core::$memd;
		$lock_key     = Cache_Backend::site_key( 'evlog:auto_disable_lock' );
		$lock_timeout = 5;
		$lock_value   = \bin2hex( \random_bytes( 8 ) );

		// No shared handle → skip cross-worker lock; just fire (single-proc).
		if ( null === $cache ) {
			$this->fire_auto_tune_actions();
			return;
		}

		// Lock held by another worker: retry on the next flush, or wait it out.
		$deadline = \microtime( true ) + $wait_ms / 1000;
		while ( ! $cache->add( $lock_key, $lock_value, $lock_timeout ) ) {
			if ( \microtime( true ) >= $deadline ) {
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
	 * Inject the Stats_Store and re-arm the mirror seam on it.
	 *
	 * @param Stats_Store|null $store Store to write through, or null to go inert.
	 */
	public function set_stats_store( ?Stats_Store $store ): void {
		$this->stats_store = $store;
		// Every one of these names keys in the OLD store's keyspace.
		$this->folded_hours  = [];
		$this->stale_hours   = [];
		$this->flushed_rows  = [];
		$this->flushed_index = [];
		$this->ranked_at     = [];
		$this->rank_pending  = [];
		$this->arm_stats_mirror();
	}

	/**
	 * Name the durable Partition that shadows stats writes (via the store's
	 * mirror seam) and is read back whenever memcache misses. For deployments
	 * where memcache is volatile; disabled when the name is empty.
	 *
	 * Stores the name only — the node is resolved by name lazily at flush/reload
	 * (like add_snapshot_node), so this verb can't fail on a not-yet-built node
	 * whose make_node comes later in a console-serialized override. The partition
	 * lifts its own 4KB PIPE_BUF cap via `command_node <name>:config void_warranty` in the
	 * topology, alongside its make_node.
	 *
	 * See `flush_stats_mirror()` for when a frame is written versus held.
	 *
	 * @param string $name Partition node name; '' disables the mirror.
	 */
	public function set_stats_target( string $name ): void {
		$this->stats_partition = \trim( $name );
		$this->arm_stats_mirror();
	}

	/**
	 * Arm (or disarm) the store's mirror seam from the current store + partition
	 * name. Called from BOTH setters so store and partition can be configured in
	 * either order and a configure_stats re-run re-arms the fresh store. Needs
	 * only the store — the partition node is resolved by name lazily at use.
	 */
	private function arm_stats_mirror(): void {
		$store = $this->stats_store;
		if ( null === $store ) {
			return;
		}
		$store->mirror = '' === $this->stats_partition ? null : $this->buffer_mirror_write( ... );
		self::arm_rehydrate( $store, $this->stats_partition );
		$from_partition = $store->rehydrate;
		if ( null !== $from_partition ) {
			$partition        = $store->partition();
			// Unbudgeted, so a null is only an unresolved partition: a miss.
			$store->rehydrate = fn ( array $keys ): array =>
				$this->held_frames( $keys, $partition ) + ( ( $from_partition )( $keys ) ?? [] );
		}
	}

	/**
	 * Arm `$store` to read the configured stats mirror without a live graph.
	 *
	 * The dashboard reads in a web request, where no Flame_Builder exists to arm
	 * the seam — so the store resolves the mirror from the topology instead. An
	 * unconfigured mirror leaves it memcache-only, exactly as before one existed.
	 *
	 * @api Readers building a Stats_Store outside the worker graph.
	 * @param Stats_Store $store Store whose read seam is armed.
	 */
	public static function arm_stats_reader( Stats_Store $store ): void {
		self::arm_rehydrate( $store, \trim( Core::as_string( Config::value( 'stats_mirror_node' ), '' ) ) );
		$seam = $store->rehydrate;
		if ( null === $seam ) {
			return;
		}
		// Absences walked for are remembered; a refused namespace is not.
		$store->absence = static fn ( string $key ): int => self::mirrors_key( $key ) ? $store->absence_holds( $key ) : 0;
		// num_int: arithmetic, and a corrupt value must read as OFF.
		$budget_ns        = 1_000_000 * \max( 0, Core::num_int( Config::value( 'stats_mirror_read_budget_ms' ) ) );
		// Null, not []: a read that did not look is no absence to remember.
		$store->rehydrate = static function ( array $keys ) use ( $seam, $budget_ns ): ?array {
			if ( self::$mirror_read_ns >= $budget_ns ) {
				return null;
			}
			$at    = \hrtime( true );
			$found = $seam( $keys );
			self::$mirror_read_ns += \hrtime( true ) - $at;
			return $found;
		};
	}

	/**
	 * Point `$store`'s read seam at the named mirror, or unarm it when unnamed.
	 *
	 * ONE body for both callers. The worker names the mirror through
	 * `set_stats_target` and a reader through config, but it is the same key —
	 * and `mirror_partition()` already prefers the live node — so both want the
	 * same resolution rather than two. Reading through a detached handle before
	 * the worker's own node exists is safe precisely because it is read-only;
	 * the WRITE path keeps `resolve_stats_partition()`, which never falls back.
	 *
	 * @param Stats_Store $store Store whose read seam is set.
	 * @param string      $name  Mirror partition node name; '' unarms the seam.
	 */
	private static function arm_rehydrate( Stats_Store $store, string $name ): void {
		$partition        = $store->partition();
		$store->rehydrate = '' === $name
			? null
			: self::rehydrate_seam(
				static fn (): ?\Newspack_Nodes\Partition_Node => self::mirror_partition( $name, $partition ),
				$partition,
				$store
			);
	}

	/**
	 * The rehydrate closure over a partition resolver.
	 *
	 * The keys are resolved through `Partition_Node::locate_by()`, which is
	 * bounded by them: it walks only for keys nobody has looked up yet and
	 * memoizes what it searched for as well as what it found, so a
	 * leaderboard's hundreds of bucket misses cost one pass between them
	 * rather than one each — and never a table of the whole partition.
	 *
	 * @param \Closure(): ?\Newspack_Nodes\Partition_Node $resolve         Where the mirror is.
	 * @param int                                            $partition_index Keyspace the Table's keys sit in.
	 * @param Stats_Store                                    $store           Sizes what is handed back, by window.
	 * @return \Closure(array<array-key,mixed>): ?array<array-key,array{value: array<array-key,mixed>, ttl: int}>
	 *         Null when the mirror could not be looked at, which is no absence.
	 */
	private static function rehydrate_seam( \Closure $resolve, int $partition_index, Stats_Store $store ): \Closure {
		$partition = null;
		$resolved  = false;
		return static function ( array $keys ) use ( $resolve, $partition_index, $store, &$partition, &$resolved ): ?array {
			// Frames are filed under the durable key; the Table asks relative.
			$hashes = [];
			foreach ( $keys as $key ) {
				// The seam is public and untyped; only strings name a key.
				if ( ! \is_string( $key ) ) {
					continue;
				}
				if ( ! self::mirrors_key( $key ) ) {
					continue;
				}
				$hashes[ $key ] = Log_Manager::url_hash( Stats_Store::entry_key( $partition_index, $key ) );
			}
			// Nothing this mirror can hold: no walk, no partition to resolve.
			if ( [] === $hashes ) {
				return [];
			}
			// Once: null is no mirror declared; a late node resolves detached.
			if ( ! $resolved ) {
				$partition = $resolve();
				$resolved  = true;
			}
			if ( null === $partition ) {
				return null;
			}
			// Bounded: otherwise a locator per key in the WHOLE partition.
			$locators = $partition->locate_by(
				self::locate_stats_frame( ... ),
				\array_values( $hashes )
			);
			$positions = [];
			foreach ( $hashes as $key => $hash ) {
				$at = $locators[ $hash ] ?? null;
				if ( null !== $at ) {
					$positions[ $key ] = $at;
				}
			}
			// The tick: a read-back must not move the reply's own clock.
			$now   = Core::$now;
			$found = [];
			foreach ( $partition->read_many( $positions ) as $key => $msg ) {
				$frame = self::read_mirror_frame( $msg );
				// A hash collision lands another key's frame; its key says so.
				if ( null === $frame || $frame['key'] !== Stats_Store::entry_key( $partition_index, $key ) ) {
					continue;
				}
				// What is left of the RETENTION window, not of the cache TTL.
				$left = $store->window_remaining( $key, (int) $now );
				if ( $left <= 0 ) {
					continue;
				}
				$found[ $key ] = [
					'value' => $frame['data'],
					'ttl'   => $left,
				];
			}
			return $found;
		};
	}

	/**
	 * One mirror frame's fields, or null when the envelope is not one — a
	 * malformed frame must skip, never abort the read repairing a hole.
	 *
	 * @param array<int,mixed> $msg Decoded frame envelope.
	 * @return array{key: string, data: array<array-key,mixed>, ttl: int, ts: float}|null
	 */
	private static function read_mirror_frame( array $msg ): ?array {
		$key   = $msg[ Message::KEY ] ?? null;
		$value = $msg[ Message::VALUE ] ?? null;
		if ( ! \is_string( $key ) || '' === $key
			|| ! \is_array( $value )
			|| ! \is_array( $value['data'] ?? null )
			|| ! \is_int( $value['ttl'] ?? null )
		) {
			return null;
		}
		return [
			'key'  => $key,
			'data' => $value['data'],
			'ttl'  => $value['ttl'],
			'ts'   => Core::num_float( $msg[ Message::TIMESTAMP ] ?? null ),
		];
	}

	/**
	 * Buffer a mirrored write until the next checkpoint.
	 *
	 * Every namespace is kept in full: a key is one (URL, bucket), and with the
	 * open bucket held back (`flush_stats_mirror()`) what lands is that bucket's
	 * whole and final state for every key it saw. Re-keying on `$key` means the
	 * newest write for a key replaces the older one.
	 *
	 * Two bounds sit on top, and only one of them drops a frame. `mirror_topn()`
	 * rank-caps NS_URL, whose profiles are the largest per-URL values and whose
	 * cap is an operator's (`set_flame_topn`). `MAX_HELD_FRAMES` bounds what the
	 * buffer may HOLD, and its overflow is written early rather than dropped.
	 *
	 * @param string                  $key  Durable key the frame is filed under.
	 * @param array<array-key,mixed> $data Value written.
	 * @param int                     $ttl  TTL the memcache write used.
	 * @param string                  $ns   Stats_Store namespace the key belongs to.
	 */
	private function buffer_mirror_write( string $key, array $data, int $ttl, string $ns ): void {
		$cap = $this->mirror_topn( $ns );
		if ( 0 === $cap ) {
			return; // 0 keeps nothing: NS_URLS_HOUR always, NS_URL by default.
		}
		// A URL with no merged requests would spend a slot on nothing.
		if ( Stats_Store::NS_URL === $ns && static::mirror_traffic_rank( $data, $ns ) <= 0 ) {
			return;
		}
		$this->mirror[ $ns ][ $key ] = [ $data, $ttl ];
		if ( \count( $this->mirror[ $ns ] ) > $cap ) {
			$this->evict_lowest_rank( $ns );
		}
		if ( \count( $this->mirror[ $ns ] ) > static::MAX_HELD_FRAMES ) {
			$this->spill_over_backstop( $ns, $key );
		}
	}

	/**
	 * Write the lowest-ranked BAND of buffered frames NOW and stop holding them
	 * — the held-frame backstop doing its job, so memory is bounded and nothing
	 * is lost.
	 *
	 * The partition keeps only the last frame for a key, so an early copy of an
	 * open bucket is superseded by the write that closes it: the cost is one
	 * redundant record per frame, which is exactly what holding the bucket was
	 * saving.
	 *
	 * A BAND rather than one frame, because ranking the buffer is a pass over
	 * it and the buffer PINS at the bound under exactly the traffic the bound
	 * exists for — a crawler, or a query-string spray of unique URLs. Spilling
	 * one frame per write puts that pass on every write past the bound, which
	 * is quadratic in the spray length inside the worker whose failure mode
	 * this bound was added to prevent. Spilling `HELD_FRAMES_SPILL_DIVISOR` of
	 * the bound leaves that much headroom to refill before the next pass, so
	 * the pass is amortized across the band. Ranking a frame does not settle
	 * once — a request merging into a key changes it — so a rank-ordered
	 * structure would pay per write instead, which is the cost being removed.
	 *
	 * With no partition there is nothing to spill INTO, and the reading is
	 * `flush_stats_mirror()`'s: a name that does not resolve may resolve next
	 * checkpoint, so nothing already HELD is discarded. What the backstop
	 * refuses instead is the arrival — new work, at the entry, whose value is
	 * still in memcache and whose bucket's next write re-offers it. Loud
	 * either way.
	 *
	 * @param string $ns      Namespace to spill from.
	 * @param string $arrived Key whose arrival crossed the bound.
	 */
	private function spill_over_backstop( string $ns, string $arrived ): void {
		$partition = $this->resolve_stats_partition();
		if ( null === $partition ) {
			unset( $this->mirror[ $ns ][ $arrived ] );
			$this->print_less_often( "stats_partition '{$this->stats_partition}' not found; refusing {$ns} frames over the backstop" );
			return;
		}
		$keep  = static::MAX_HELD_FRAMES - \max( 1, \intdiv( static::MAX_HELD_FRAMES, self::HELD_FRAMES_SPILL_DIVISOR ) );
		$ranks = [];
		foreach ( $this->mirror[ $ns ] as $k => [ $data ] ) {
			$ranks[ $k ] = static::mirror_traffic_rank( $data, $ns );
		}
		\asort( $ranks );
		foreach ( \array_slice( \array_keys( $ranks ), 0, \count( $ranks ) - $keep ) as $key ) {
			[ $data, $ttl ] = $this->mirror[ $ns ][ $key ];
			$this->write_mirror_frame( $partition, $key, $data, $ttl );
			unset( $this->mirror[ $ns ][ $key ] );
		}
		$this->print_less_often(
			\sprintf(
				'held stats frames at the backstop; open buckets are being written early — %s over %d frames',
				$ns,
				static::MAX_HELD_FRAMES
			)
		);
	}

	/**
	 * Drop the lowest-ranked buffered write in a namespace — the rank cap doing
	 * its job, so the frame never reaches the durable mirror.
	 *
	 * @param string $ns Namespace to evict from.
	 */
	private function evict_lowest_rank( string $ns ): void {
		$key = $this->lowest_rank_key( $ns );
		if ( null !== $key ) {
			unset( $this->mirror[ $ns ][ $key ] );
		}
	}

	/**
	 * The lowest-ranked key a namespace is holding, or null when it holds none.
	 * Linear scan, run once per overflow.
	 *
	 * @param string $ns Namespace to scan.
	 */
	private function lowest_rank_key( string $ns ): ?string {
		$min_key  = null;
		$min_rank = \PHP_INT_MAX;
		foreach ( $this->mirror[ $ns ] as $k => [ $data ] ) {
			$rank = static::mirror_traffic_rank( $data, $ns );
			if ( $rank < $min_rank ) {
				$min_rank = $rank;
				$min_key  = $k;
			}
		}
		return $min_key;
	}

	/**
	 * Snapshot the in-flight bucket for the Consumer's checkpoint.
	 *
	 * The topology names this node in the requests-Consumer's `add_snapshot_node`,
	 * so the returned array is co-committed with the read offset: a respawned
	 * worker resumes the partial buckets instead of losing them. Draining the
	 * flame trees and the closed buckets' mirror frames here — not only on the
	 * FLUSH_INTERVAL_SEC cadence — is what makes that commit whole.
	 *
	 * The frames `flush_stats_mirror()` held ride it too, without their ranks —
	 * `mirror_traffic_rank()` derives those on the way back in.
	 *
	 * This also WRITES — it drains the per-URL LRU to memcache and appends to the
	 * stats partition before returning. The substrate calls `save_state()` as a
	 * pure reader; this node borrows it as the pre-commit hook the contract does
	 * not otherwise offer, because the frames have to land before the cursor does.
	 *
	 * @api Used by substrate.
	 * @return array<string,mixed>
	 */
	public function save_state(): array {
		$now = (int) Core::$now;
		// Co-commit the current flame trees with the cursor, like pending.
		$this->mirror_url_stats( $now );
		$this->flush_stats_mirror( $now );
		return [
			'pending' => $this->pending,
			'mirror'  => $this->checkpoint_mirror( $now ),
		];
	}

	/**
	 * The held frames a checkpoint carries, smallest first, under the byte budget.
	 *
	 * Smallest-first keeps the most keys recoverable per byte, and drops the
	 * biggest — which are the per-server leaderboards, the one axis that grows
	 * with an operator input. Rank is not stored at all — `lowest_rank_key()`
	 * derives it from the data it already holds.
	 *
	 * The ordering is by ENTRY COUNT, which is free, rather than by encoded
	 * bytes, which is not: encoding every held frame to sort them measured the
	 * whole buffer to carry a budget's worth of it. A frame is a map of small
	 * numeric entries, so its count tracks its size closely — and the ordering
	 * only has to be APPROXIMATE, because the budget below is enforced against
	 * REAL encoded bytes as they accumulate. A proxy that mis-orders two frames
	 * costs a slightly worse packing, never an oversize record; there is no
	 * reason to restore the full encode.
	 *
	 * @param int $now `save_state()`'s one read of the tick, the `at` stamp.
	 * @return array{at: int, frames: array<string,array<string,array{0: array<array-key,mixed>, 1: int}>>}
	 */
	private function checkpoint_mirror( int $now ): array {
		$held = $this->mirror_frames();
		\usort( $held, static fn ( array $a, array $b ): int => \count( $a['frame'][0] ) <=> \count( $b['frame'][0] ) );

		$remaining = self::MAX_CHECKPOINT_MIRROR_BYTES;
		$out    = [ 'at' => $now, 'frames' => [] ];
		foreach ( $held as $i => [ 'ns' => $ns, 'key' => $key, 'frame' => $frame ] ) {
			$size = self::frame_bytes( $frame );
			if ( $size > $remaining ) {
				// Ascending, so the widest held frame is the last of them.
				$widest = $held[ \count( $held ) - 1 ];
				$this->print_less_often(
					'held stats frames over the checkpoint budget; they still reach the mirror at bucket close',
					\sprintf(
						' — %d of %d frames dropped; budget %d bytes, %d carried; widest held %s/%s at %d bytes',
						\count( $held ) - $i,
						\count( $held ),
						self::MAX_CHECKPOINT_MIRROR_BYTES,
						self::MAX_CHECKPOINT_MIRROR_BYTES - $remaining,
						$widest['ns'],
						$widest['key'],
						self::frame_bytes( $widest['frame'] )
					)
				);
				break;
			}
			$remaining                   -= $size;
			$out['frames'][ $ns ][ $key ] = $frame;
		}
		return $out;
	}

	/**
	 * The mirror frames being held, flattened out of their namespaces.
	 *
	 * One builder, two readers: the pack above, and the introspection payload.
	 * Neither is handed a SIZE — the pack measures only what it reaches, and
	 * `mirror_held_bytes` is asked for by an operator rather than every thirty
	 * seconds, so it pays for the exact total where it is wanted.
	 *
	 * @return list<array{ns: string, key: string, frame: array{0: array<array-key,mixed>, 1: int}}>
	 */
	private function mirror_frames(): array {
		$held = [];
		foreach ( $this->mirror as $ns => $frames ) {
			foreach ( $frames as $key => $frame ) {
				$held[] = [ 'ns' => $ns, 'key' => $key, 'frame' => $frame ];
			}
		}
		return $held;
	}

	/**
	 * Frames held in the WIDEST single namespace — the number `MAX_HELD_FRAMES`
	 * is against.
	 *
	 * The bound is per namespace, so a cross-namespace total cannot warn about
	 * it: six namespaces holding five thousand each read as thirty thousand
	 * with nothing near the bound, while one spilling on every write reads as
	 * ten thousand and fifty.
	 */
	private function widest_namespace_frames(): int {
		$widest = 0;
		foreach ( $this->mirror as $frames ) {
			$widest = \max( $widest, \count( $frames ) );
		}
		return $widest;
	}

	/**
	 * What one held frame will cost the checkpoint. An unencodable frame reads as
	 * unbounded, not as zero — zero would sort it first and always carry it.
	 *
	 * @param array{0: array<array-key,mixed>, 1: int} $frame Buffered [data, ttl].
	 */
	private static function frame_bytes( array $frame ): int {
		$json = \wp_json_encode( $frame );
		return false === $json ? \PHP_INT_MAX : \strlen( $json );
	}

	/**
	 * Drain the per-URL flame and profile aggregates into the store — memcache
	 * and the mirror seam both.
	 *
	 * `flush()` and `save_state()` share it, so the flame trees co-commit with
	 * the read cursor at every checkpoint rather than only on the
	 * FLUSH_INTERVAL_SEC cadence. The batched `NS_URL` write overwrites with
	 * the whole aggregate and leaves the accumulator standing, so a
	 * `save_state()` drain followed by the next `flush()` double-counts nothing.
	 *
	 * @param int $now The caller's one read of the tick, the `last_modified` stamp.
	 */
	private function mirror_url_stats( int $now ): void {
		$stats_store = $this->stats_store;
		if ( null === $stats_store ) {
			return;
		}
		$writes = [];
		foreach ( $stats_store->accumulating_url_stats() as $url_hash => $aggregate ) {
			if ( ! \is_array( $aggregate ) ) {
				continue;
			}
			/** @var array<string,mixed> $aggregate */
			// Finalized flame for display; keep flame_raw for merging.
			$flame                  = \is_array( $aggregate['flame'] ?? null ) ? $aggregate['flame'] : [];
			$count_raw              = $flame['count'] ?? 0;
			$total_count            = Core::num_int( $count_raw );
			$aggregate['flame_raw'] = $flame;
			Flame_Tree::finalize_flame_node( $flame, $total_count );
			$aggregate['flame']         = $flame;
			$aggregate['last_modified'] = $now;
			// @longform One write per URL is one ROUND TRIP per URL, which is
			// the cost this whole flush path is batched to avoid. Chunked on
			// `flush_writes()`'s budget, which bounds what one `store_multi`
			// serializes; the aggregates themselves are alive either way,
			// because the accumulator this drains is already holding them.
			$writes[] = [ [ Stats_Store::NS_URL ], $url_hash, $aggregate ];
		}
		foreach ( \array_chunk( $writes, self::WRITE_BATCH_KEYS ) as $chunk ) {
			$stats_store->bucket_set_multi( $chunk );
		}
	}

	/**
	 * Write the buffered mirror frames whose bucket has CLOSED, and hold the rest.
	 *
	 * The partition keeps only the last frame for a key, so writing the bucket
	 * currently being accumulated into — once per checkpoint, ten times over a
	 * bucket's life — is nine redundant copies of a value that is still growing.
	 * A held key is re-keyed by every later write, so what finally lands is the
	 * bucket's whole and final state. Near-once rather than exactly-once: a
	 * request that started before a boundary completes after it, and re-writes
	 * the bucket it belongs to, which is by then closed.
	 *
	 * A held frame is not undurable, just durable somewhere cheaper: it rides
	 * `save_state()` into the offsetlog, a bounded ring of at most 60 keyframes,
	 * and a respawn writes it when the bucket closes.
	 *
	 * Every namespace flushes in full: what a buffer holds is what the closing
	 * bucket saw, less whatever the backstop already wrote early.
	 *
	 * @param int $now `save_state()`'s one read of the tick.
	 */
	private function flush_stats_mirror( int $now ): void {
		if ( '' === $this->stats_partition ) {
			return;
		}
		$partition = $this->resolve_stats_partition();
		if ( null === $partition ) {
			$this->print_less_often( "stats_partition '{$this->stats_partition}' not found at flush" );
			return; // Keep the buffer; retry next checkpoint once the node exists.
		}
		foreach ( $this->mirror as $ns => $entries ) {
			$this->mirror[ $ns ] = $this->write_closed_frames( $partition, $entries, $now );
		}
	}

	/**
	 * Write every frame in one buffer whose bucket has closed, and return what is
	 * still held.
	 *
	 * @template T of array{0: array<array-key,mixed>, 1: int}
	 * @param \Newspack_Nodes\Partition_Node $partition Resolved stats partition.
	 * @param array<string,T>                $buffer    Frames by key.
	 * @param int                            $now       Clock deciding which bucket is open.
	 * @return array<string,T> The frames whose bucket is still open.
	 */
	private function write_closed_frames( \Newspack_Nodes\Partition_Node $partition, array $buffer, int $now ): array {
		foreach ( $buffer as $key => [ $data, $ttl ] ) {
			if ( Stats_Store::is_open_bucket( $key, $now ) ) {
				continue;
			}
			$this->write_mirror_frame( $partition, $key, $data, $ttl );
			unset( $buffer[ $key ] );
		}
		return $buffer;
	}

	/** Resolve the named stats partition to its live node, or null when disabled / not-yet-built. */
	private function resolve_stats_partition(): ?\Newspack_Nodes\Partition_Node {
		if ( '' === $this->stats_partition ) {
			return null;
		}
		$node = Core::node( $this->stats_partition );
		return $node instanceof \Newspack_Nodes\Partition_Node ? $node : null;
	}

	/**
	 * Write one mirror frame (TM_STRUCT {data,ttl}, keyed by `Message::KEY`) to
	 * the partition.
	 *
	 * Written straight to the partition rather than through the sink, so the
	 * frame lands in the checkpoint regardless of how the graph is wired.
	 *
	 * The key rides in `Message::KEY` alone; a copy inside VALUE would repeat
	 * it in every frame for nothing.
	 *
	 * @param \Newspack_Nodes\Partition_Node $partition Resolved stats partition.
	 * @param string                         $key       Durable key the frame is filed under.
	 * @param array<array-key,mixed>        $data      Value written.
	 * @param int                            $ttl       TTL the memcache write used.
	 */
	private function write_mirror_frame( \Newspack_Nodes\Partition_Node $partition, string $key, array $data, int $ttl ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::KEY ]       = $key;
		$msg[ Message::VALUE ]     = [ 'data' => $data, 'ttl' => $ttl ];
		$partition->fill( $msg );
	}

	/**
	 * The app's half of `locate_by()`: where the record for a stats-index line
	 * sits, keyed by the hash that line carries.
	 *
	 * @param string $line Index line.
	 * @return array{key: string, offset: int, length: int}|null
	 */
	private static function locate_stats_frame( string $line ): ?array {
		$entry = self::parse_stats_index( $line );
		return null === $entry ? null : [
			'key'    => $entry['key_hash'],
			'offset' => $entry['offset'],
			'length' => $entry['length'],
		];
	}

	/**
	 * Parse one stats-index line back into its fields — the inverse of
	 * `format_stats_index_entry()`, and bound to the same fixed widths.
	 *
	 * @param string $line Index line.
	 * @return array{key_hash: string, segment: int, offset: int, length: int}|null Null when the line is short.
	 */
	public static function parse_stats_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( \strlen( $line ) < 36 ) {
			return null;
		}
		return [
			'key_hash' => \substr( $line, 0, 12 ),
			'segment'  => (int) \substr( $line, 12, 6 ),
			'offset'   => (int) \substr( $line, 18, 10 ),
			'length'   => (int) \substr( $line, 28, 8 ),
		];
	}

	/**
	 * Restore the in-flight buckets a previous worker checkpointed.
	 *
	 * Each bucket is merged over an empty accumulator, so a save from an older
	 * shape that lacks a key keeps that key's default instead of leaving it unset.
	 * A frame an older worker wrote under a different shape is skipped, costing
	 * one worker's un-flushed delta once — far less than the retention window the
	 * same release already resets. The URL rows are held to their shape here,
	 * the one door a checkpoint comes through: see `restored_url_rows()`.
	 *
	 * @api Used by substrate.
	 * @param array<string,mixed> $saved A prior `save_state()` return value.
	 */
	public function restore_state( array $saved ): void {
		$pending = Core::arr( $saved['pending'] ?? null );
		$mirror = Core::arr( $saved['mirror'] ?? null );
		// An unmerged delta, not durable: a spent one is dropped.
		$elapsed                   = \max( 0, (int) Core::$now - Core::num_int( $mirror['at'] ?? null ) );
		foreach ( Core::arr( $mirror['frames'] ?? null ) as $ns_raw => $carried ) {
			$ns       = Core::as_string( $ns_raw );
			$restored = self::restore_frames( $carried, $elapsed );
			$cap      = \max( 0, \min( $this->mirror_topn( $ns ), static::MAX_HELD_FRAMES ) );
			if ( \count( $restored ) > $cap ) {
				// Re-bound by TRAFFIC; the carry's own order is smallest-first.
				\uasort(
					$restored,
					static fn ( array $a, array $b ): int =>
						static::mirror_traffic_rank( $b[0], $ns ) <=> static::mirror_traffic_rank( $a[0], $ns )
				);
				$restored = \array_slice( $restored, 0, $cap, true );
			}
			$this->mirror[ $ns ] = $restored;
		}
		foreach ( $pending as $bucket => $acc ) {
			// `Y-m-d-H-i` is a string PHP never re-types to int.
			if ( \is_string( $bucket ) && '' !== $bucket && \is_array( $acc ) ) {
				/** @var Bucket_Acc $merged */
				$merged                     = \array_merge( self::empty_bucket(), $acc );
				$merged['url_stats']        = self::restored_url_rows( $merged['url_stats'] );
				$merged['url_stats_worker'] = self::restored_url_rows( $merged['url_stats_worker'] );
				$this->pending[ $bucket ]   = $merged;
			}
		}
	}

	/**
	 * A checkpoint's URL rows, kept only where they are `server => hash =>
	 * row` and each row names its path, and completed over an empty row so
	 * the accumulator indexes every field unguarded.
	 *
	 * A checkpoint in any other shape — rows keyed by hash, carrying a
	 * per-server split — would read its hashes as servers. No migration: its
	 * URL delta is dropped, once.
	 *
	 * @param array<array-key,mixed> $servers A restored `url_stats` slot.
	 * @return array<array-key,array<array-key,mixed>>
	 */
	private static function restored_url_rows( array $servers ): array {
		$out = [];
		foreach ( $servers as $server => $rows ) {
			foreach ( Core::arr( $rows ) as $hash => $row ) {
				if ( \is_array( $row ) && \is_string( $row[ Stats_Store::ROW_PATH ] ?? null ) ) {
					$out[ $server ][ $hash ] = \array_replace( self::empty_url_row( PHP_INT_MAX ), $row );
				}
			}
		}
		return $out;
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
			Stats_Store::ROW_MIN_MS      => $min_ms,
			Stats_Store::ROW_MAX_MS      => 0,
			Stats_Store::ROW_MAX_PEAK_MB => 0,
			Stats_Store::ROW_LAST_SEEN   => 0,
			Stats_Store::ROW_WORKER      => false,
			Stats_Store::ROW_PATH        => '',
		];
	}

	/**
	 * Whether the mirror can hold a key's namespace AT ALL.
	 *
	 * `buffer_mirror_write()` drops a derived namespace, so reading one back can
	 * only walk the whole index and find nothing — and `locate_by()` has no
	 * early stop for a key that is absent, so each such read is a full pass. A
	 * cold dashboard poll asks for hundreds of coarse-tier keys.
	 *
	 * NS_URL is rank-capped rather than derived, and that cap is a runtime verb
	 * (`set_flame_topn`), so it is never a refusal this can be sure of.
	 *
	 * @param string $key Table-relative entry key.
	 */
	private static function mirrors_key( string $key ): bool {
		$ns = Stats_Store::namespace_of( $key );
		return Stats_Store::NS_URL === $ns || ! Stats_Store::is_derived( $ns );
	}

	/**
	 * The live RANK cap on one namespace's buffered frames: `$flame_topn` for
	 * NS_URL (the flame profiles), 0 for a DERIVED namespace, which keeps
	 * nothing at all, and no rank for everything else — where both
	 * per-URL series and every aggregate land. `MAX_HELD_FRAMES` is the
	 * separate bound on what those may HOLD.
	 *
	 * @param string $ns Namespace a frame was written under.
	 */
	private function mirror_topn( string $ns ): int {
		if ( Stats_Store::NS_URL === $ns ) {
			return $this->flame_topn;
		}
		// An aggregate namespace keeps all; a derived one keeps none.
		return Stats_Store::is_derived( $ns ) ? 0 : \PHP_INT_MAX;
	}

	/**
	 * Traffic rank (~request count) for the per-URL namespaces.
	 *
	 * Each namespace stores a different shape, so each derives the count its own
	 * way. The result only has to order URLs against each other.
	 *
	 * Reached through `static::` and protected so a test double can COUNT the
	 * reads — the backstop's cost is a complexity claim, and wall clock is not
	 * evidence for one. An override delegates to this body, which still runs.
	 *
	 * @param array<array-key,mixed> $data Value being mirrored.
	 * @param string                  $ns   Namespace it belongs to.
	 */
	protected static function mirror_traffic_rank( array $data, string $ns ): int {
		if ( Stats_Store::NS_URL === $ns ) {
			$flame = $data['flame'] ?? null;
			return \is_array( $flame ) && \is_numeric( $flame['count'] ?? null ) ? (int) $flame['count'] : 0;
		}
		if ( Stats_Store::NS_URL_CAT === $ns ) {
			// One bucket: the `total` pseudo-category's sampled requests.
			$total = $data[ self::TOTAL_KEY ] ?? null;
			return \is_array( $total ) && \is_numeric( $total[ Stats_Store::CAT_REQUESTS ] ?? null )
				? (int) $total[ Stats_Store::CAT_REQUESTS ]
				: 0;
		}
		// NS_URL_DIM: one bucket; take the first dimension's counts.
		$sum   = 0;
		$first = \reset( $data );
		if ( \is_array( $first ) ) {
			foreach ( $first as $vd ) {
				$sum += \is_array( $vd ) && \is_numeric( $vd[ Stats_Store::DIM_COUNT ] ?? null ) ? (int) $vd[ Stats_Store::DIM_COUNT ] : 0;
			}
		}
		return $sum;
	}

	/**
	 * Held mirror frames from a checkpoint frame, coerced back to `[data, ttl]`.
	 * A malformed entry is dropped rather than aborting the whole restore.
	 *
	 * @param mixed $saved   One buffer out of the checkpoint's `mirror` map.
	 * @param int   $elapsed Seconds since the checkpoint was written, taken off each TTL.
	 * @return array<string,array{0: array<array-key,mixed>, 1: int}>
	 */
	private static function restore_frames( mixed $saved, int $elapsed ): array {
		$out = [];
		foreach ( Core::arr( $saved ) as $key => $frame ) {
			$frame = Core::arr( $frame );
			$data  = $frame[0] ?? null;
			$ttl   = Core::num_int( $frame[1] ?? null ) - $elapsed;
			// A carry that is not an absolute key matches no lookup; drop it.
			if ( \is_string( $key ) && Stats_Store::is_mirror_key( $key ) && \is_array( $data ) && $ttl > 0 ) {
				$out[ $key ] = [ $data, $ttl ];
			}
		}
		return $out;
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
	 * One leaderboard scope's empty sums, so an accumulator cannot be seeded
	 * differently from the value it merges into.
	 *
	 * @return Leaderboard_Acc
	 */
	private static function empty_leaderboard(): array {
		return [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
	}

	/**
	 * The mirror partition for one flame-builder partition: the live node when
	 * this process runs the graph, else a detached handle over the directory the
	 * active topology declares for it.
	 *
	 * The dir comes from `Bootstrap::node_dirs()` rather than a rebuilt path
	 * template — the partition token sits wherever the topology puts it, and a
	 * reader that spells the layout itself goes blind the moment it moves.
	 *
	 * @param string $name      Mirror partition node name.
	 * @param int    $partition Which of that node's partitions to open.
	 * @return \Newspack_Nodes\Partition_Node|null Null when the topology declares no dir for it.
	 */
	private static function mirror_partition( string $name, int $partition ): ?\Newspack_Nodes\Partition_Node {
		$live = Core::node( $name );
		if ( $live instanceof \Newspack_Nodes\Partition_Node ) {
			return $live;
		}
		$dir = \Newspack_Nodes\Bootstrap::node_dirs( $name )[ $partition ] ?? '';
		if ( '' === $dir ) {
			return null;
		}
		// Read-only: the topology, not this handle, owns the mirror's geometry.
		$node = new \Newspack_Nodes\Partition_Node();
		$node->arguments( [ $dir ] );
		return $node;
	}

	/**
	 * Format one companion-index line for the stats mirror.
	 *
	 * Registered as `stats-index` and installed by
	 * `command_node flame-stats:partition:config with_index stats-index`. Fixed
	 * width so `parse_stats_index()` can slice it by offset: key_hash(12)
	 * segment(6) offset(10) length(8) = 36 bytes.
	 *
	 * The key is hashed because a fixed-width line needs a bound;
	 * `Log_Manager::url_hash()` is the house
	 * 12-char digest rather than a second hashing convention. A reader files a
	 * located frame under the key the FRAME's `Message::KEY` carries, so a
	 * collision costs one wasted read and can never file a value under a name
	 * that is not its own.
	 *
	 * @param array<int,mixed>  $message  The unpacked positional message array.
	 * @param array<string,int> $position Position array with segment, offset, length.
	 * @return string|null Index entry, or null for a frame carrying no key.
	 */
	public static function format_stats_index_entry( array $message, array $position ): ?string {
		$key = $message[ Message::KEY ] ?? null;
		if ( ! \is_string( $key ) || '' === $key ) {
			return null;
		}
		return Log_Manager::url_hash( $key )
			. \str_pad( (string) $position['segment'], 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Start a fresh mirror read budget.
	 *
	 * The budget bounds ONE answer, so the reader resets it where an answer
	 * begins — `Performance_CI_Node::dispatch()`. Without that, one poll's
	 * spend would blind every later poll a long-lived process serves.
	 *
	 * @api The dashboard reader, once per inbound command.
	 */
	public static function reset_mirror_read_budget(): void {
		self::$mirror_read_ns = 0;
	}

	/**
	 * Whether this answer's mirror read budget is spent.
	 *
	 * A reader that reached the budget answered null for every mirror read
	 * after it, so the fold it produced is missing whatever those reads held.
	 * That page is the answer for now, not one to keep.
	 *
	 * @api The dashboard reader, before it caches a page.
	 */
	public static function mirror_budget_spent(): bool {
		return self::$mirror_read_ns >= 1_000_000 * \max( 0, Core::num_int( Config::value( 'stats_mirror_read_budget_ms' ) ) );
	}

	/**
	 * Run one read on a mirror read budget of its own, then resume the
	 * caller's accounting where it stood.
	 *
	 * Naming a page is an answer of its own: it runs after the index walk
	 * that spends the command's budget, and a page of counts against blank
	 * URLs is no page. Resuming the spend afterwards is what keeps a walk
	 * that FOLLOWS the read — `ask_category` names a context, then walks —
	 * from inheriting a budget it did not have.
	 *
	 * @api The dashboard reader, around a point read that follows a walk.
	 * @template T
	 * @param \Closure(): T $read The read.
	 * @return T What the read returned.
	 */
	public static function with_own_mirror_read_budget( \Closure $read ): mixed {
		$spent                = self::$mirror_read_ns;
		self::$mirror_read_ns = 0;
		try {
			return $read();
		} finally {
			self::$mirror_read_ns = $spent;
		}
	}

	/**
	 * The buffered frames for `$keys` that no durable log holds yet.
	 *
	 * The open bucket is deliberately withheld from the mirror, so these buffers
	 * are its ONLY copy besides memcache — and `persist_aggregate_stats()` reads
	 * a bucket back before adding to it. Without this tier an eviction mid-bucket
	 * reads as empty and the merge restarts the bucket from zero.
	 *
	 * Held frames win over the partition: they are what was written last.
	 *
	 * @param array<array-key,mixed> $keys      Keys the Table missed on, relative to its namespace.
	 * @param int                    $partition Keyspace those keys sit in; the buffer keys absolutely.
	 * @return array<string,array{value: array<array-key,mixed>, ttl: int}>
	 */
	private function held_frames( array $keys, int $partition ): array {
		$found = [];
		foreach ( $keys as $key ) {
			// The seam is public and untyped; only strings name a key.
			if ( ! \is_string( $key ) ) {
				continue;
			}
			$held  = Stats_Store::entry_key( $partition, $key );
			$frame = null;
			foreach ( $this->mirror as $entries ) {
				$frame ??= $entries[ $held ] ?? null;
			}
			if ( null !== $frame ) {
				$found[ $key ] = [ 'value' => $frame[0], 'ttl' => $frame[1] ];
			}
		}
		return $found;
	}

	/**
	 * Set the per-URL flame-profile mirror cap (NS_URL top-N): how many profiled
	 * URLs are shadowed to the durable stats partition, ranked by traffic. 0, the
	 * production default, mirrors none. Memcache is unaffected either way.
	 *
	 * The `set_flame_topn` verb description calls the target "memcache"; the
	 * target is the partition, which a memcache miss then reads back.
	 *
	 * @param int $n Top-N cap; negatives clamp to 0.
	 */
	public function set_flame_topn( int $n ): void {
		$this->flame_topn = \max( 0, $n );
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
	 * The stats-mirror partition, so the console draws the flame-builder →
	 * flame-stats:partition edge. Display only: the mirror writes go straight to
	 * the partition at flush (bypassing the sink), so without this it renders
	 * disconnected even while it fills. What actually gets mirrored is driven by
	 * add_snapshot_node + set_stats_target, not by this method.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		return [ $this->stats_partition ];
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `command_node {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI). No generic verb recording.
	 *
	 * A verb missing here silently drops its setting on a console serialize →
	 * replay round trip, so a new persistent verb needs a line added.
	 *
	 * @api Used by substrate.
	 * @return string TSL lines, newline-terminated.
	 */
	public function dump_config(): string {
		$out = parent::dump_config() . $this->dump_toggles();
		if ( null !== $this->stats_store ) {
			$out .= $this->config_line( 'configure_stats', (string) $this->stats_store->partition() );
		}
		if ( '' !== $this->stats_partition ) {
			$out .= $this->config_line( 'set_stats_target', $this->stats_partition );
		}
		if ( 0 !== $this->flame_topn ) {
			$out .= $this->config_line( 'set_flame_topn', (string) $this->flame_topn );
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
	 * time, and its caller keeps the entry budget as its only bound.
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
			'description' => 'Aggregates per-event count + sum_time into the memcache stats schema; emits flame JSONL.',
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
					'name'        => 'configure_stats',
					'description' => 'Build the Stats_Store from substrate config (memcache + retention).',
					'args'        => [
						[ 'name' => 'partition', 'type' => 'int', 'required' => true, 'default' => '<partition>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						// Pre-tokenized args; non-numeric fails loud, never p0.
						$arg = \trim( Core::as_string( $args[0] ?? '' ) );
						if ( ! \is_numeric( $arg ) ) {
							return 'usage: configure_stats <partition>';
						}
						$partition = (int) $arg;

						// The store reads Core::$memd.
						$stats_store = new \Newspack_Event_Logger_Nodes\Stats_Store(
							$partition,
							\Newspack_Event_Logger_Nodes\Config::stats_retention_seconds()
						);

						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_store( $stats_store );
						return 'ok';
					},
				],
				[
					'name'        => 'set_stats_target',
					'description' => 'Mirror stats writes to a durable Partition and read them back when memcache misses.',
					'args'        => [ [ 'name' => 'target', 'type' => 'node_name', 'required' => false, 'default' => '' ] ],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = Core::as_string( $args[0] ?? '' );
						// Store name; resolve lazily (empty=disabled).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_target( \trim( $arg ) );
						return 'ok';
					},
				],
				[
					'name'        => 'set_flame_topn',
					'description' => 'Cap how many per-URL flame profiles mirror to memcache (top-N by traffic). 0 (default) disables the flame-profile mirror.',
					'args'        => [ [ 'name' => 'n', 'type' => 'int', 'required' => false, 'default' => 0 ] ],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = Core::as_string( $args[0] ?? '' );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_flame_topn( (int) \trim( $arg ) );
						return 'ok';
					},
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'Stats cache + pending buckets + auto-tune queue depth.',
					'reply_shape' => '{ stats_count, pending_url_count, intern_count, pending_buckets, last_flush_age_s, auto_tune_pending_count, is_hub, significant_events_count }',
				],
			],
		];
	}
}
