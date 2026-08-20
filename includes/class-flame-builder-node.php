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
 * keyed by the bucket the request STARTED in. A record arrives at completion,
 * so around a boundary it routinely lands in a bucket older than the newest one
 * seen — which is why `$pending` is a map rather than one rotating slot.
 * `flush()` merges each bucket into memcache through `Stats_Store` (the
 * 9-namespace schema) at most once per FLUSH_INTERVAL_SEC, capping as it
 * writes, then drops them. Per-URL flame trees take a different route: they
 * live in an `LRU_Cache` and drain through `mirror_url_stats()`.
 *
 * Two side channels hang off that pipeline. `set_stats_target` names a durable
 * Partition that shadows each memcache write once its bucket closes, so a
 * non-Atomic deployment can replay stats after memcache loses them. And the request's governing `Rule`
 * drives auto-tune: hooks that fire too often, custom events to disable, and
 * newly significant events accumulate here and are emitted as messages to the
 * owned `Auto_Tuner_Node` sibling, which rewrites the rule.
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
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds flame trees and the 9-namespace stats schema from completed requests.
 *
 * @phpstan-type Leaderboard_Acc array{count?: int, sum_req_time?: float|int, categories: array<string,array{samples: int,sum_time: float|int,sum_count: float|int,ts?: int,entries: array<string,array<int,float|int>>}>}
 * @phpstan-type Dim_Values array<string,array{c: int,s: float|int,m: float|int}>
 * @phpstan-type Cat_Values array<string,array{t: float|int,c: float|int,n: int}>
 * @phpstan-type Bucket_Acc array{
 *   hourly: array<string,mixed>,
 *   dim: array<string,Dim_Values>,
 *   dim_by_server: array<string,array<string,Dim_Values>>,
 *   url_dim: array<string,array<string,Dim_Values>>,
 *   url_stats: array<string,mixed>,
 *   cat: Cat_Values,
 *   cat_by_server: array<string,Cat_Values>,
 *   cat_by_url: array<string,Cat_Values>,
 *   leaderboard: Leaderboard_Acc,
 *   leaderboard_by_server: array<string,Leaderboard_Acc>
 * }
 */
class Flame_Builder_Node extends Node {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;

	/**
	 * Reserved key of the category time series: the per-bucket ROLLUP row, not a
	 * category. It carries `n` = requests in the bucket, `t` = their summed wall
	 * time, and `c` = the summed call count of every category in them.
	 *
	 * Only `n` has a reader — `mirror_traffic_rank()` reads one bucket's to rank a
	 * URL when the mirror buffer overflows. The dashboard's
	 * `CategoryTimeChart` skips the row outright, so `t` and `c` are published
	 * but unread today.
	 *
	 * Category names come from the ruleset, where a custom event may be called
	 * anything — including this. A colliding name is renamed on the way in
	 * (`collision_free_category()`), because otherwise its samples land in the
	 * rollup and inflate the request count that drives mirror eviction.
	 */
	private const TOTAL_KEY = 'total';

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

	/**
	 * Byte ceiling on the held mirror frames a checkpoint frame carries.
	 *
	 * The offsetlog bounds keyframe COUNT (60), not size, and `add_snapshot_node`
	 * lifts its PIPE_BUF cap to `MAX_LARGE_LINE_SIZE` — past which `Partition_Node`
	 * DROPS the record, and the record is the whole checkpoint: the read cursor and
	 * every snapshot node's state, not just these frames. Meanwhile the per-server
	 * aggregates grow with the spoke count, one server's leaderboard bucket being
	 * ~27KB. So this budget is what keeps a checkpoint far away from that cliff.
	 *
	 * A frame past the budget is re-merged from memcache by the next write to its
	 * bucket, so it is lost only if the process AND memcache both fail before the
	 * bucket closes — the same double failure the mirror exists for.
	 */
	private const MAX_CHECKPOINT_MIRROR_BYTES = 262144;

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
	 * Max URLs kept per bucket in the URL index, ranked by request count.
	 *
	 * "Hourly" in the `Stats_Store` URL-index method names is legacy: the key is
	 * the same bucket key everything else uses.
	 */
	private const MAX_URLS_PER_BUCKET = 500;

	/**
	 * Per-URL namespaces bounded to top-N by traffic when mirrored to the durable
	 * stats partition. Aggregate namespaces are absent from this map and mirror in
	 * full.
	 *
	 * The NS_URL entry (the flame profiles) is the STARTING default only — the
	 * live bound is `$flame_topn` (see `set_flame_topn`), so the key must stay
	 * here for `buffer_mirror_write()` to route NS_URL down the top-N path at all.
	 */
	private const STATS_MIRROR_TOPN = [
		Stats_Store::NS_URL     => 0,    // flame profiles — see $flame_topn
		Stats_Store::NS_URL_DIM => 100,  // per-URL dimensional frames
		Stats_Store::NS_URL_CAT => 100,  // per-URL category frames
	];

	/** @var Auto_Tuner_Node|null Owned sibling — receives auto-tune decisions. */
	private ?Auto_Tuner_Node $auto_tuner = null;

	/** @var (callable(): int)|null Test seam: clock function for bucket-key derivation. */
	private $clock_fn = null;
	/** @var array<string,bool> Custom-event-name set ({name => true}). */
	private array $custom_event_names       = [];
	/** @var array<string,array<string,bool>> rule_id => {event => true} disable decisions. */
	private array $custom_events_to_disable = [];

	/**
	 * Live top-N cap for the per-URL flame-profile mirror (NS_URL) — how many
	 * profiles are shadowed to the durable stats partition, not to memcache,
	 * which always receives them.
	 *
	 * 0 in production: flame profiles are the largest per-URL values, and paying
	 * to make them durable is not worth it, while the per-URL dimensional and
	 * category namespaces still mirror at top-100. `set_flame_topn` raises it;
	 * tests do that to exercise the persisted-profile shape at a non-zero cap.
	 */
	private int $flame_topn = 0;
	/** @var array<string,array<string,bool>> rule_id => {hook => true} disable decisions. */
	private array $hooks_to_disable         = [];

	/** Hub mode: also accumulate the per-server namespaces. Derived from `<eln:is_hub>`. */
	private bool $is_hub                    = false;

	/** Unix time of the last flush(); fill() compares it against FLUSH_INTERVAL_SEC. */
	private float $last_flush_time          = 0.0;
	/** @var array<string,array<string,bool>> rule_id => {event => true} newly promoted. */
	private array $new_significant_events   = [];

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

	/** @var array<string,array{0: array<array-key,mixed>,1: int}> Aggregate mirror writes (kept in full): key => [data, ttl]. */
	private array $stats_mirror_buffer = [];

	/** @var array<string,array<string,array{0: array<array-key,mixed>,1: int}>> Per-URL top-N: ns => key => [data, ttl]. */
	private array $stats_mirror_topn = [];

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
	 * Build the per-URL LRU and the owned auto-tuner.
	 *
	 * The node is inert until `configure_stats` supplies a `Stats_Store`: it still
	 * accumulates and still forwards flames, but nothing reaches memcache.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		$this->last_flush_time = Core::$now ?: Core::right_now();

		// Owned auto-tuner sibling (patron-linked; hidden from the canvas).
		$this->auto_tuner = new Auto_Tuner_Node();
		$this->auto_tuner->patron( $this );

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

		if ( $this->store_flame( $rid, $url_hash, $flame_data ) ) {
			$this->accumulate_all_stats( $url_hash, $flame_data, $profiles, $request );
		}

		// Periodic flush; cached per-tick clock gates the throttle.
		$now_f = Core::$now ?: Core::right_now();
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
			$now = ( Core::$now ?: Core::right_now() );
			$payload = [
				'stats_count'              => $stats_count,
				'pending_url_count'        => \array_sum( \array_map( static fn ( array $acc ): int => \count( $acc['url_stats'] ), $this->pending ) ),
				'intern_count'             => \count( self::$intern ),
				'pending_buckets'          => \array_keys( $this->pending ),
				'last_flush_age_s'         => $this->last_flush_time > 0 ? (int) ( $now - $this->last_flush_time ) : null,
				'auto_tune_pending_count'  => self::map_total( $this->hooks_to_disable ) + self::map_total( $this->custom_events_to_disable ) + self::map_total( $this->new_significant_events ),
				'is_hub'                   => $this->is_hub,
				'significant_events_count' => self::map_total( $this->significant_events ),
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
	 * persisted, and the caller still aggregates. Hence the unconditional true.
	 *
	 * @param string               $rid        Request ID.
	 * @param string               $url_hash   URL hash.
	 * @param array<string,mixed> $flame_data Flame tree; mutated locally, not by reference.
	 * @return bool Always true — aggregation proceeds whether or not a flame was written.
	 */
	private function store_flame( string $rid, string $url_hash, array $flame_data ): bool {
		// Duplicate-sibling suffixes exist only to survive the merge.
		Flame_Tree::strip_name_suffixes( $flame_data );

		$flame_data['rid']      = $rid;
		$flame_data['url_hash'] = $url_hash;

		if ( '' === $this->target || null === $this->sink ) {
			return true; // Aggregation still happens; just no on-disk flame.
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->target;
		$message[ Message::KEY ]       = $flame_data['rid'];
		$message[ Message::VALUE ]     = $flame_data;
		// Deferred on a stop so the caller still accumulates stats.
		$this->guarded( fn () => $this->sink->fill( $message ) );
		return true;
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
	 * The order is fixed and the numbered sections below follow it: per-URL flame
	 * aggregate (LRU), bucket rotation, per-URL row, hourly totals, the seven
	 * dimensional axes, and finally the profile loop that feeds the leaderboards,
	 * the category time series, and auto-tune.
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
	 * Sums are stored, never means (see AGENTS.md architecture decision 2); the
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
		// Both durations are fictions and would skew every percentile.
		$is_timed_out = \in_array( $error_status, [ 'T', 'A' ], true );
		$is_worker    = ! empty( $request['is_worker'] );
		// Two gates: per-URL rows keep worker timing; global drops workers.
		$record_timing = $duration_ms > 0 && ! $is_timed_out;
		$count_global  = ! $is_worker;
		$now           = $this->now_ts();

		$timestamp_raw = $request['timestamp'] ?? $now;
		$timestamp     = Core::num_int( $timestamp_raw, $now );
		$server_raw    = $request['server_name'] ?? '';
		$server_name   = Core::str( $server_raw );

		$aggregate = $this->accumulate_url_aggregate( $url_hash, $flame_data, $duration_ms, $record_timing, $now );
		// The request STARTED in this bucket; it is reaching us at completion.
		$bucket                     = Stats_Store::bucket_key( $timestamp );
		$this->pending[ $bucket ] ??= self::empty_bucket();
		$acc                        = &$this->pending[ $bucket ];
		$this->accumulate_url_stats( $acc, $url_hash, $request, $duration_ms, $record_timing, $timestamp );
		$this->accumulate_hourly( $acc, $request, $duration_ms, $record_timing, $count_global );
		$this->accumulate_dimensions( $acc, $url_hash, $request, $server_name, $duration_ms, $record_timing, $count_global );

		if ( ! empty( $profiles ) && $record_timing ) {
			$this->accumulate_profiles(
				$acc,
				$url_hash,
				$profiles,
				$request,
				$aggregate,
				$server_name,
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
	 * extremes, status buckets, sampled durations and peak memory.
	 *
	 * @param Bucket_Acc              $acc           The request's bucket accumulator.
	 * @param string                  $url_hash      URL hash of the request.
	 * @param array<array-key,mixed> $request       Full request record.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param int                     $timestamp     The request's timestamp.
	 */
	private function accumulate_url_stats( array &$acc, string $url_hash, array $request, float $duration_ms, bool $record_timing, int $timestamp ): void {
		$url_val = $request['url'] ?? '';
		$url     = Core::str( $url_val );
		if ( '' === $url ) {
			return;
		}
		if ( ! isset( $acc['url_stats'][ $url_hash ] ) ) {
			$acc['url_stats'][ $url_hash ] = [
				'url'         => $url,
				'count'       => 0,
				'timed_count' => 0,
				'sum_ms'      => 0,
				'min_ms'      => PHP_INT_MAX,
				'max_ms'      => 0,
				'last_seen'   => 0,
				'durations'   => [],
				'count_2xx'   => 0,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'sum_peak_mb' => 0,
				'max_peak_mb' => 0,
			];
		}
		/** @var array{url: string, count: int, timed_count: int, sum_ms: float|int, min_ms: float|int, max_ms: float|int, last_seen: int, durations: array<int,float|int>, count_2xx: int, count_3xx: int, count_4xx: int, count_5xx: int, sum_peak_mb: float|int, max_peak_mb: float|int} $us */
		$us = &$acc['url_stats'][ $url_hash ];
		++$us['count'];
		// Per-URL: workers keep timing on their own row.
		if ( $record_timing ) {
			++$us['timed_count'];
			$us['sum_ms'] += $duration_ms;
			$us['max_ms']  = \max( $us['max_ms'], $duration_ms );
		}
		$us['last_seen'] = \max( $us['last_seen'], $timestamp );
		$status_category = self::status_category( $request );
		if ( null !== $status_category ) {
			++$us[ "count_{$status_category}xx" ];
		}
		// Raw durations feed the percentiles; past the cap, Algorithm R.
		if ( $record_timing ) {
			$max_dur      = Stats_Store::MAX_DURATIONS_PER_BUCKET;
			$us['min_ms'] = \min( $us['min_ms'], $duration_ms );
			if ( \count( $us['durations'] ) < $max_dur ) {
				$us['durations'][] = $duration_ms;
			} else {
				$idx = \random_int( 0, \max( 1, $us['timed_count'] ) - 1 );
				if ( $idx < $max_dur ) {
					$us['durations'][ $idx ] = $duration_ms;
				}
			}
		}
		$peak_raw = $request['peak_mb'] ?? 0;
		$peak_mb  = Core::num_float( $peak_raw );
		if ( $peak_mb > 0 ) {
			$us['sum_peak_mb'] += $peak_mb;
			$us['max_peak_mb']  = \max( $us['max_peak_mb'], $peak_mb );
		}
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
	 * @param string                  $server_name   Reporting server, '' when unknown.
	 * @param float                   $duration_ms   Request duration.
	 * @param bool                    $record_timing Whether timing counts.
	 * @param bool                    $count_global  Whether this feeds global stats.
	 */
	private function accumulate_dimensions( array &$acc, string $url_hash, array $request, string $server_name, float $duration_ms, bool $record_timing, bool $count_global ): void {
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
				$val = 'Unknown';
			}
			$val = self::intern( $val );
			// Global: workers contribute nothing — count, timing, AND peak.
			if ( $count_global ) {
				$acc['dim'][ $dim ][ $val ] = self::add_dim( $acc['dim'][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb );
			}

			// Per-server (hub only; skip redundant dim); global drops workers.
			if ( $this->is_hub && '' !== $server_name && 'server' !== $dim && $count_global ) {
				$acc['dim_by_server'][ $server_name ][ $dim ][ $val ] = self::add_dim( $acc['dim_by_server'][ $server_name ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb );
			}

			// Per-URL.
			$acc['url_dim'][ $url_hash ][ $dim ][ $val ] = self::add_dim( $acc['url_dim'][ $url_hash ][ $dim ][ $val ] ?? null, $dim_duration, $dim_peak_mb );
		}
	}

	/**
	 * Fold a request into a dimensional bucket: one more request, its timing,
	 * its peak memory. Seeds the bucket if this is the first.
	 *
	 * @param array{c: int, s: float|int, m: float|int}|null $slot     Bucket, null on first use.
	 * @param float                                          $duration Timing to add, 0 when untimed.
	 * @param float                                          $peak     Peak MB to add.
	 * @return array{c: int, s: float|int, m: float|int} The updated bucket.
	 */
	private static function add_dim( ?array $slot, float $duration, float $peak ): array {
		$slot ??= [ 'c' => 0, 's' => 0, 'm' => 0 ];
		++$slot['c'];
		$slot['s'] += $duration;
		$slot['m'] += $peak;
		return $slot;
	}

	/**
	 * The `Nxx` status bucket a request falls in, or 0 when it has no usable
	 * status code. Both the per-URL `count_Nxx` counters and the `status`
	 * dimension key off this, and they used to derive it separately.
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
	 * Split out of `accumulate_all_stats()`, which had grown to 470 lines of
	 * hand-numbered sections. The auto-tune thresholds resolve here because this
	 * is the only section that reads them.
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
	 * @param string                    $server_name  Reporting server, '' when unknown.
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
		string $server_name,
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

		// Per-server leaderboard (hub mode only). Global: drop workers.
		$slb = null;
		if ( $this->is_hub && '' !== $server_name && $count_global ) {
			$acc['leaderboard_by_server'][ $server_name ] ??= self::empty_leaderboard();
			/** @var Leaderboard_Acc $slb */
			$slb = &$acc['leaderboard_by_server'][ $server_name ];
		}

		foreach ( $profiles as $category => $data ) {
			if ( ! \is_string( $category ) || ! \is_array( $data ) ) {
				continue;
			}
			$category = self::collision_free_category( self::intern( $category ) );

			// Callback and plugin rows are views auto-tune can't act on.
			$is_callback = (bool) \preg_match( '/ @-?\d+$/', $category );
			$is_plugin   = (bool) \preg_match( '/ plugin$/', $category );

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

			// Global per-server category series: drop workers.
			if ( $this->is_hub && '' !== $server_name && $count_global ) {
				$acc['cat_by_server'][ $server_name ][ $category ] = self::add_cat( $acc['cat_by_server'][ $server_name ][ $category ] ?? null, $cat_time, $cat_count );
			}

			$acc['cat_by_url'][ $url_hash ][ $category ] = self::add_cat( $acc['cat_by_url'][ $url_hash ][ $category ] ?? null, $cat_time, $cat_count );

			// Significant-event: avg/call > threshold; workers excluded.
			if ( $auto_tune_active && null !== $lcat && ! $is_callback && ! $is_plugin && $time_threshold > 0 && $lcat['sum_count'] > 0 ) {
				$avg_per_call = $lcat['sum_time'] / $lcat['sum_count'];
				if ( $avg_per_call >= $time_threshold ) {
					$base_name = \explode( ' ', $category, 2 )[0];
					if ( ! isset( $this->significant_events[ $rule_id ][ $base_name ] ) && ! $this->rule_significant( $rule, $base_name ) ) {
						$this->significant_events[ $rule_id ][ $base_name ]     = true;
						$this->new_significant_events[ $rule_id ][ $base_name ] = true;
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
				$base_name = \explode( ' ', $category, 2 )[0];
				if ( isset( $this->custom_event_names[ $base_name ] ) ) {
					$this->custom_events_to_disable[ $rule_id ][ $base_name ] = true;
				} else {
					$this->hooks_to_disable[ $rule_id ][ $base_name ] = true;
				}
			}
			unset( $pcat );
			unset( $lcat );
		}

		// One rollup fold per request, not one per category.
		if ( $count_global ) {
			$acc['cat'][ self::TOTAL_KEY ] = self::add_cat( $acc['cat'][ self::TOTAL_KEY ] ?? null, $duration_ms, $total_calls );
		}
		if ( $this->is_hub && '' !== $server_name && $count_global ) {
			$acc['cat_by_server'][ $server_name ][ self::TOTAL_KEY ] = self::add_cat( $acc['cat_by_server'][ $server_name ][ self::TOTAL_KEY ] ?? null, $duration_ms, $total_calls );
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
	 * wall time and the summed call count of every category in it — so its `n`
	 * stays a request count.
	 *
	 * @param array{t: float|int, c: float|int, n: int}|null $slot  Bucket, null on first use.
	 * @param float                                          $time  Time to add.
	 * @param float                                          $count Call count to add.
	 * @return array{t: float|int, c: float|int, n: int} The updated bucket.
	 */
	private static function add_cat( ?array $slot, float $time, float $count ): array {
		$slot ??= [ 't' => 0, 'c' => 0, 'n' => 0 ];
		$slot['t'] += $time;
		$slot['c'] += $count;
		++$slot['n'];
		return $slot;
	}

	/**
	 * Fold a category sample into a leaderboard bucket (sums, never means —
	 * AGENTS.md decision 2). The per-URL caller stamps `ts` afterwards.
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
	 * Entry names are by far the highest-cardinality strings the node sees, and
	 * they used to intern with no freeze check at all — so the one table the cap
	 * exists to bound was the one table that grew without limit.
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
	 * Whether a name is already a rule-declared significant event.
	 *
	 * @param Rule|null $rule Governing rule, or null when none matched.
	 * @param string    $name Base hook or event name.
	 */
	private function rule_significant( ?Rule $rule, string $name ): bool {
		return null !== $rule && \in_array( $name, $rule->significant_events, true );
	}

	/**
	 * Drain every accumulator and start clean.
	 *
	 * `fill()` calls this at most once per FLUSH_INTERVAL_SEC, and nothing else
	 * does. The accumulators do not need it to survive: `save_state()` co-commits
	 * `$pending` with the read cursor, so a graceful stop carries it across and a
	 * fatal replays from the cursor that last committed it. What a fatal DOES
	 * cost is a double-count — the deltas between the last checkpoint and the
	 * crash are already in memcache and get replayed on top (see the CHANGELOG's
	 * Known section).
	 *
	 * With no `Stats_Store` wired the drain is a no-op against storage: the
	 * accumulators still reset, but nothing is written anywhere.
	 */
	public function flush(): void {
		$this->mirror_url_stats();
		$this->persist_aggregate_stats();
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
	 * No store means no write: the accumulators are dropped by the caller.
	 */
	private function persist_aggregate_stats(): void {
		$stats_store = $this->stats_store;
		if ( null === $stats_store ) {
			return;
		}
		foreach ( $this->pending as $bucket => $acc ) {
			if ( ! empty( $acc['hourly'] ) ) {
				$stats_store->set_hourly_bucket(
					$bucket,
					Stats_Store::add_totals( $stats_store->get_hourly_bucket( $bucket ), $acc['hourly'] )
				);
			}
			if ( ! empty( $acc['url_stats'] ) ) {
				$this->persist_url_index( $stats_store, $bucket, $acc['url_stats'] );
			}
			foreach ( $acc['dim'] as $dim => $values ) {
				$this->persist_dimension( $stats_store, $bucket, $dim, $values );
			}
			// '' is the GLOBAL scope downstream; a nameless server is not one.
			foreach ( $acc['dim_by_server'] as $server => $dims ) {
				if ( '' === $server ) {
					continue;
				}
				foreach ( $dims as $dim => $values ) {
					$this->persist_dimension( $stats_store, $bucket, $dim, $values, $server );
				}
			}
			foreach ( $acc['url_dim'] as $url_hash => $dims ) {
				$this->persist_url_dimensions( $stats_store, $bucket, $url_hash, $dims );
			}
			if ( ! empty( $acc['cat'] ) ) {
				$this->persist_categories( $stats_store, $bucket, $acc['cat'] );
			}
			foreach ( $acc['cat_by_server'] as $server => $cats ) {
				if ( '' === $server ) {
					continue;
				}
				$this->persist_categories( $stats_store, $bucket, $cats, $server );
			}
			foreach ( $acc['cat_by_url'] as $url_hash => $cats ) {
				$this->persist_url_categories( $stats_store, $bucket, $url_hash, $cats );
			}
			if ( ( $acc['leaderboard']['count'] ?? 0 ) > 0 ) {
				$this->persist_leaderboard( $stats_store, $bucket, $acc['leaderboard'] );
			}
			foreach ( $acc['leaderboard_by_server'] as $server => $sums ) {
				if ( '' === $server || ( $sums['count'] ?? 0 ) <= 0 ) {
					continue;
				}
				$this->persist_leaderboard( $stats_store, $bucket, $sums, $server );
			}
		}
	}

	/**
	 * Merge one bucket's per-URL rows into the URL index, capped to the busiest
	 * `MAX_URLS_PER_BUCKET`.
	 *
	 * Percentiles and the mean are recomputed rather than merged: they are
	 * derived from the reservoir-sampled raw durations, which addition cannot
	 * combine.
	 *
	 * @param Stats_Store         $stats_store Destination.
	 * @param string              $bucket      Bucket key.
	 * @param array<string,mixed> $rows        Accumulated rows by url_hash.
	 */
	private function persist_url_index( Stats_Store $stats_store, string $bucket, array $rows ): void {
		/** @var array<string,array<string,mixed>> $existing_urls */
		$existing_urls = $stats_store->get_url_bucket( $bucket );

		foreach ( $rows as $hash => $stats_raw ) {
			$stats = Core::arr( $stats_raw );
			if ( ! isset( $existing_urls[ $hash ] ) ) {
				$existing_urls[ $hash ] = [
					'url'         => $stats['url'] ?? '',
					'count'       => 0,
					'timed_count' => 0,
					'sum_ms'      => 0,
					'min_ms'      => 0,
					'max_ms'      => 0,
					'last_seen'   => 0,
					'durations'   => [],
					'count_2xx'   => 0,
					'count_3xx'   => 0,
					'count_4xx'   => 0,
					'count_5xx'   => 0,
					'sum_peak_mb' => 0,
					'max_peak_mb' => 0,
				];
			}
			/** @var array{url: string, count: int, timed_count: int, sum_ms: float|int, min_ms: float|int, max_ms: float|int, last_seen: int, durations: array<int,float|int>, count_2xx: int, count_3xx: int, count_4xx: int, count_5xx: int, sum_peak_mb: float|int, max_peak_mb: float|int} $e */
			$e                 = &$existing_urls[ $hash ];
			$e['count']       += \is_numeric( $stats['count'] ?? null ) ? $stats['count'] : 0;
			$e['timed_count'] += \is_numeric( $stats['timed_count'] ?? null ) ? $stats['timed_count'] : 0;
			$e['sum_ms']      += \is_numeric( $stats['sum_ms'] ?? null ) ? $stats['sum_ms'] : 0;
			// Fold min_ms from timed buckets only (skip PHP_INT_MAX).
			$s_min_ms          = \is_numeric( $stats['min_ms'] ?? null ) ? $stats['min_ms'] : 0;
			$s_max_ms          = \is_numeric( $stats['max_ms'] ?? null ) ? $stats['max_ms'] : 0;
			$s_last            = \is_numeric( $stats['last_seen'] ?? null ) ? $stats['last_seen'] : 0;
			if ( ( \is_numeric( $stats['timed_count'] ?? null ) ? $stats['timed_count'] : 0 ) > 0 ) {
				$e['min_ms'] = ( 0 === $e['min_ms'] ) ? $s_min_ms : \min( $e['min_ms'], $s_min_ms );
			}
			$e['max_ms']       = \max( $e['max_ms'], $s_max_ms );
			$e['last_seen']    = \max( $e['last_seen'], $s_last );
			$e['count_2xx']   += \is_numeric( $stats['count_2xx'] ?? null ) ? $stats['count_2xx'] : 0;
			$e['count_3xx']   += \is_numeric( $stats['count_3xx'] ?? null ) ? $stats['count_3xx'] : 0;
			$e['count_4xx']   += \is_numeric( $stats['count_4xx'] ?? null ) ? $stats['count_4xx'] : 0;
			$e['count_5xx']   += \is_numeric( $stats['count_5xx'] ?? null ) ? $stats['count_5xx'] : 0;
			$e['sum_peak_mb'] += \is_numeric( $stats['sum_peak_mb'] ?? null ) ? $stats['sum_peak_mb'] : 0;
			$e['max_peak_mb']  = \max( $e['max_peak_mb'], \is_numeric( $stats['max_peak_mb'] ?? null ) ? $stats['max_peak_mb'] : 0 );

			$max_dur     = Stats_Store::MAX_DURATIONS_PER_BUCKET;
			$s_durations = \is_array( $stats['durations'] ?? null ) ? $stats['durations'] : [];
			$merged      = \array_merge( $e['durations'], $s_durations );
			if ( \count( $merged ) > $max_dur ) {
				\shuffle( $merged );
				$merged = \array_slice( $merged, 0, $max_dur );
			}
			$e['durations'] = $merged;
			unset( $e );
		}

		foreach ( $existing_urls as &$url_stat ) {
			if ( ! empty( $url_stat['durations'] ) && \is_array( $url_stat['durations'] ) ) {
				$sorted = $url_stat['durations'];
				\sort( $sorted );
				$n = \count( $sorted );
				$url_stat['p50_ms'] = $sorted[ (int) ( $n * 0.50 ) ] ?? 0;
				$url_stat['p95_ms'] = $sorted[ (int) ( $n * 0.95 ) ] ?? 0;
				$url_stat['p99_ms'] = $sorted[ (int) ( $n * 0.99 ) ] ?? 0;
				$tc_raw = $url_stat['timed_count'] ?? $url_stat['count'] ?? 0;
				$tc     = Core::num_float( $tc_raw );
				$sum_ms = Core::num_float( $url_stat['sum_ms'] ?? null );
				$url_stat['avg_ms'] = $tc > 0 ? $sum_ms / $tc : 0;
			}
		}
		unset( $url_stat );

		if ( \count( $existing_urls ) > self::MAX_URLS_PER_BUCKET ) {
			\uasort( $existing_urls, fn( $a, $b ) => ( \is_numeric( $b['count'] ?? null ) ? $b['count'] : 0 ) <=> ( \is_numeric( $a['count'] ?? null ) ? $a['count'] : 0 ) );
			$existing_urls = \array_slice( $existing_urls, 0, self::MAX_URLS_PER_BUCKET, true );
		}

		$stats_store->set_url_bucket( $bucket, $existing_urls );
	}

	/**
	 * Merge one URL's dimensional bucket — every dimension in one value, each
	 * capped on its own.
	 *
	 * @param Stats_Store            $stats_store Destination.
	 * @param string                 $bucket      Bucket key.
	 * @param string                 $url_hash    12-char URL hash.
	 * @param array<string,mixed>    $dims        Accumulated value maps by dimension.
	 */
	private function persist_url_dimensions( Stats_Store $stats_store, string $bucket, string $url_hash, array $dims ): void {
		$existing = $stats_store->get_url_dimensional_bucket( $url_hash, $bucket );
		foreach ( $dims as $dim => $values ) {
			$existing[ $dim ] = self::cap_dim(
				Stats_Store::sum_fields( Core::arr( $existing[ $dim ] ?? null ), Core::arr( $values ), Stats_Store::DIM_SUMS ),
				Stats_Store::MAX_URL_DIM_VALUES
			);
		}
		$stats_store->set_url_dimensional_bucket( $url_hash, $bucket, Stats_Store::string_keys( $existing ) );
	}

	/**
	 * Merge one URL's category bucket into the store, capped.
	 *
	 * @param Stats_Store         $stats_store Destination.
	 * @param string              $bucket      Bucket key.
	 * @param string              $url_hash    12-char URL hash.
	 * @param array<string,mixed> $cats        Accumulated categories.
	 */
	private function persist_url_categories( Stats_Store $stats_store, string $bucket, string $url_hash, array $cats ): void {
		$existing = Stats_Store::sum_fields( $stats_store->get_url_category_bucket( $url_hash, $bucket ), $cats, Stats_Store::CAT_SUMS );
		$stats_store->set_url_category_bucket( $url_hash, $bucket, self::cap_categories( $existing, Stats_Store::MAX_CAT_VALUES ) );
	}

	/**
	 * Merge one scope's leaderboard bucket into the store, capped.
	 *
	 * @param Stats_Store         $stats_store Destination.
	 * @param string              $bucket      Bucket key.
	 * @param array<string,mixed> $sums        Accumulated sums.
	 * @param string              $server      Reporting server; '' is the global series.
	 */
	private function persist_leaderboard( Stats_Store $stats_store, string $bucket, array $sums, string $server = '' ): void {
		$existing = $stats_store->get_leaderboard_bucket( $bucket, $server );
		if ( empty( $existing ) ) {
			$existing = self::empty_leaderboard();
		}
		Stats_Store::merge_leaderboard_bucket( $existing, $sums );
		self::cap_leaderboard_entries( $existing );
		$stats_store->set_leaderboard_bucket( $bucket, $existing, $server );
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
	 * Merge one scope's dimensional bucket into the store, capped.
	 *
	 * @param Stats_Store            $stats_store Destination.
	 * @param string                 $bucket      Bucket key.
	 * @param string                 $dim         Dimension name.
	 * @param array<array-key,mixed> $values      Accumulated value map.
	 * @param string                 $server      Reporting server; '' is the global series.
	 */
	private function persist_dimension( Stats_Store $stats_store, string $bucket, string $dim, array $values, string $server = '' ): void {
		$existing = Stats_Store::sum_fields( $stats_store->get_dimensional_bucket( $dim, $bucket, $server ), $values, Stats_Store::DIM_SUMS );
		$stats_store->set_dimensional_bucket( $dim, $bucket, self::cap_dim( $existing, Stats_Store::MAX_DIM_VALUES ), $server );
	}

	/**
	 * Merge one scope's category bucket into the store, capped.
	 *
	 * @param Stats_Store            $stats_store Destination.
	 * @param string                 $bucket      Bucket key.
	 * @param array<array-key,mixed> $cats        Accumulated categories.
	 * @param string                 $server      Reporting server; '' is the global series.
	 */
	private function persist_categories( Stats_Store $stats_store, string $bucket, array $cats, string $server = '' ): void {
		$existing = Stats_Store::sum_fields( $stats_store->get_category_bucket( $bucket, $server ), $cats, Stats_Store::CAT_SUMS );
		$stats_store->set_category_bucket( $bucket, self::cap_categories( $existing, Stats_Store::MAX_CAT_VALUES ), $server );
	}

	/**
	 * Cap a dimensional bucket: ranked by request count, no reserved row. Named
	 * so the sort field and the field table cannot be paired wrongly at a call site.
	 *
	 * @param array<array-key,mixed> $values     One bucket's values.
	 * @param int                    $max_values Ceiling on distinct values.
	 * @return array<array-key,mixed>
	 */
	private static function cap_dim( array $values, int $max_values ): array {
		return self::cap_bucket( $values, $max_values, 'c', Stats_Store::DIM_SUMS );
	}

	/**
	 * Cap a category bucket: ranked by time, `total` lifted clear of the ranking.
	 *
	 * @param array<array-key,mixed> $cats       Category buckets.
	 * @param int                    $max_values Categories kept, synthetic slots included.
	 * @return array<array-key,mixed>
	 */
	private static function cap_categories( array $cats, int $max_values ): array {
		return self::cap_bucket( $cats, $max_values, 't', Stats_Store::CAT_SUMS, self::TOTAL_KEY );
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
	 * @param string                 $sort_field Field ranking survivors, descending.
	 * @param array<string,bool>     $fields     Field name => is a whole count.
	 * @param string|null            $reserved   Row held out of the ranking and restored after.
	 * @return array<array-key,mixed>
	 */
	private static function cap_bucket( array $values, int $max_values, string $sort_field, array $fields, ?string $reserved = null ): array {
		if ( \count( $values ) <= $max_values ) {
			return $values;
		}
		$held = null;
		if ( null !== $reserved ) {
			$held = $values[ $reserved ] ?? null;
			unset( $values[ $reserved ] );
		}
		// One slot for `Other`, one more when a reserved row is really there.
		$keep = \max( 0, $max_values - ( null === $held ? 1 : 2 ) );
		\uasort(
			$values,
			fn( $a, $b ) => ( \is_array( $b ) && \is_numeric( $b[ $sort_field ] ?? null ) ? $b[ $sort_field ] : 0 )
				<=> ( \is_array( $a ) && \is_numeric( $a[ $sort_field ] ?? null ) ? $a[ $sort_field ] : 0 )
		);
		$top  = \array_slice( $values, 0, $keep, true );
		$rest = [];
		foreach ( \array_slice( $values, $keep ) as $v ) {
			$rest = Stats_Store::sum_fields( $rest, [ 'Other' => Core::arr( $v ) ], $fields );
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
	private function apply_auto_tune(): void {
		if (
			empty( $this->hooks_to_disable )
			&& empty( $this->custom_events_to_disable )
			&& empty( $this->new_significant_events )
		) {
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

		if ( ! $cache->add( $lock_key, $lock_value, $lock_timeout ) ) {
			return; // Lock held by another worker; retry on next flush.
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
		$rule_ids = \array_unique( \array_merge(
			\array_keys( $this->hooks_to_disable ),
			\array_keys( $this->custom_events_to_disable ),
			\array_keys( $this->new_significant_events )
		) );
		foreach ( $rule_ids as $rule_id ) {
			$this->emit_auto_tune( 'disable_hooks',          $rule_id, \array_keys( $this->hooks_to_disable[ $rule_id ] ?? [] ) );
			$this->emit_auto_tune( 'disable_custom_events',  $rule_id, \array_keys( $this->custom_events_to_disable[ $rule_id ] ?? [] ) );
			$this->emit_auto_tune( 'add_significant_events', $rule_id, \array_keys( $this->new_significant_events[ $rule_id ] ?? [] ) );
		}

		$this->hooks_to_disable         = [];
		$this->custom_events_to_disable = [];
		$this->new_significant_events   = [];
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
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->name . ':auto-tuner';
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
	 * The mirror buffers writes in memory; `flush_stats_mirror()` writes a bucket's
	 * frames at the first save_state() checkpoint after that bucket CLOSES, and
	 * holds the open one until then. The held frames ride the checkpoint into the
	 * offsetlog, so a respawn resumes them; only writes made since the last
	 * checkpoint die with a crash, and the Consumer replays those from its
	 * co-committed cursor.
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
			// Both tiers drop non-string keys themselves; no guard needed here.
			$store->rehydrate = fn ( array $keys ): array =>
				$this->held_frames( $keys, $partition ) + ( $from_partition )( $keys );
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
	 */
	public static function arm_stats_reader( Stats_Store $store ): void {
		self::arm_rehydrate( $store, \trim( Core::as_string( Config::value( 'stats_mirror_node' ), '' ) ) );
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
	 */
	private static function arm_rehydrate( Stats_Store $store, string $name ): void {
		$partition        = $store->partition();
		$store->rehydrate = '' === $name
			? null
			: self::rehydrate_seam(
				static fn (): ?\Newspack_Nodes\Partition_Node => self::mirror_partition( $name, $partition ),
				$partition
			);
	}

	/**
	 * The rehydrate closure over a partition resolver.
	 *
	 * The locator table is memoized in the closure, so a reader pays for ONE
	 * index pass however many of its keys miss — which is what keeps a
	 * leaderboard's hundreds of bucket misses off the retention window.
	 *
	 * @param \Closure(): ?\Newspack_Nodes\Partition_Node $resolve         Where the mirror is.
	 * @param int                                            $partition_index Keyspace the Table's keys sit in.
	 * @return \Closure(array<array-key,mixed>): array<array-key,array{value: array<array-key,mixed>, ttl: int}>
	 */
	private static function rehydrate_seam( \Closure $resolve, int $partition_index ): \Closure {
		$partition = null;
		return static function ( array $keys ) use ( $resolve, $partition_index, &$partition ): array {
			// Retried while null: the node may be built after this one.
			$partition ??= $resolve();
			if ( null === $partition ) {
				return [];
			}
			$locators = $partition->locate_by( self::locate_stats_frame( ... ) );
			$wanted   = [];
			foreach ( $keys as $key ) {
				// The seam is public and untyped; only strings name a key.
				if ( ! \is_string( $key ) ) {
					continue;
				}
				// Frames are filed under the FULL key; the Table asks relative.
				$at = $locators[ Log_Manager::url_hash( Stats_Store::entry_key( $partition_index, $key ) ) ] ?? null;
				if ( null !== $at ) {
					$wanted[ $key ] = $at;
				}
			}
			$now   = Core::right_now();
			$found = [];
			foreach ( $partition->read_many( $wanted ) as $key => $msg ) {
				$frame = self::read_mirror_frame( $msg );
				// A hash collision lands another key's frame; its key says so.
				if ( null === $frame || $frame['key'] !== Stats_Store::entry_key( $partition_index, $key ) ) {
					continue;
				}
				$found[ $key ] = [
					'value' => $frame['data'],
					// What is LEFT of its life, so an expiry stays expired.
					'ttl'   => $frame['ttl'] - (int) ( $now - $frame['ts'] ),
				];
			}
			return $found;
		};
	}

	/**
	 * The mirror partition for one flame-builder partition: the live node when
	 * this process runs the graph, else a detached handle over the directory the
	 * active topology declares for it.
	 *
	 * The dir comes from `Bootstrap::node_dirs()` rather than a rebuilt path
	 * template — the partition token sits wherever the topology puts it, and a
	 * reader that spells the layout itself goes blind the moment it moves.
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
		// The callable, not the name: the name is for the topology round trip.
		$node->with_index( self::format_stats_index_entry( ... ) );
		return $node;
	}

	/**
	 * One mirror frame's fields, or null when the envelope is not one — a
	 * malformed frame must skip, never abort the read repairing a hole.
	 *
	 * @param array<int,mixed> $msg Decoded frame envelope.
	 * @return array{key: string, data: array<array-key,mixed>, ttl: int, ts: float}|null
	 */
	private static function read_mirror_frame( array $msg ): ?array {
		$value = $msg[ Message::VALUE ] ?? null;
		if ( ! \is_array( $value )
			|| ! \is_string( $value['key'] ?? null )
			|| ! \is_array( $value['data'] ?? null )
			|| ! \is_int( $value['ttl'] ?? null )
		) {
			return null;
		}
		return [
			'key'  => $value['key'],
			'data' => $value['data'],
			'ttl'  => $value['ttl'],
			'ts'   => Core::num_float( $msg[ Message::TIMESTAMP ] ?? null ),
		];
	}

	/**
	 * Buffer a mirrored write until the next checkpoint.
	 *
	 * Aggregates are kept in full. The per-URL namespaces are bounded to top-N by
	 * traffic (see STATS_MIRROR_TOPN and `mirror_topn()`), or the buffer would grow
	 * with the site's URL space. The bound counts FRAMES, and a key is one
	 * (URL, bucket) — so with the open bucket held back (`flush_stats_mirror()`)
	 * the survivors are the busiest N URLs of the WHOLE bucket, ranked on its full
	 * counts rather than one checkpoint's. Re-keying on `$key` means the newest
	 * write for a key replaces the older one, so a held key carries the bucket's
	 * latest state, and an evicted one is re-buffered by its next write.
	 *
	 * @param string                  $key  Memcache key being shadowed.
	 * @param array<array-key,mixed> $data Value written.
	 * @param int                     $ttl  TTL the memcache write used.
	 * @param string                  $ns   Stats_Store namespace the key belongs to.
	 */
	private function buffer_mirror_write( string $key, array $data, int $ttl, string $ns ): void {
		if ( ! isset( self::STATS_MIRROR_TOPN[ $ns ] ) ) {
			$this->stats_mirror_buffer[ $key ] = [ $data, $ttl ]; // aggregate: keep all
			return;
		}
		$cap = $this->mirror_topn( $ns );
		if ( 0 === $cap ) {
			return; // NS_URL's production default: rank nothing, keep nothing.
		}
		// Rank flame top-N only among URLs with profiling detail (fwd-compat).
		if ( Stats_Store::NS_URL === $ns && ! self::has_profiling_detail( $data ) ) {
			return;
		}
		$this->stats_mirror_topn[ $ns ][ $key ] = [ $data, $ttl ];
		if ( \count( $this->stats_mirror_topn[ $ns ] ) > $cap ) {
			$this->evict_lowest_rank( $ns );
		}
	}

	/**
	 * Whether a per-URL aggregate carries a flame tree worth mirroring. A URL
	 * with no merged requests would spend a top-N slot on nothing.
	 *
	 * @param array<array-key,mixed> $data Per-URL aggregate value.
	 */
	private static function has_profiling_detail( array $data ): bool {
		$flame = $data['flame'] ?? null;
		return \is_array( $flame ) && ( \is_numeric( $flame['count'] ?? null ) ? (int) $flame['count'] : 0 ) > 0;
	}

	/**
	 * Drop the lowest-ranked buffered write in a namespace. Linear scan — the
	 * namespaces are capped in the low hundreds and this runs once per overflow.
	 *
	 * @param string $ns Namespace to evict from.
	 */
	private function evict_lowest_rank( string $ns ): void {
		$min_key  = null;
		$min_rank = \PHP_INT_MAX;
		foreach ( $this->stats_mirror_topn[ $ns ] as $k => [ $data ] ) {
			$rank = self::mirror_traffic_rank( $data, $ns );
			if ( $rank < $min_rank ) {
				$min_rank = $rank;
				$min_key  = $k;
			}
		}
		if ( null !== $min_key ) {
			unset( $this->stats_mirror_topn[ $ns ][ $min_key ] );
		}
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
	 * The frames `flush_stats_mirror()` HELD — the open bucket, which by design
	 * reaches no durable log until it closes — ride the frame too. That is what
	 * backs the open window: the offsetlog is a bounded ring of at most 60
	 * keyframes, so it costs fixed disk, where `flame-stats` retains for twice
	 * the stats window. Their ranks are not carried; `mirror_traffic_rank()`
	 * derives those from the data on the way back in.
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
		// Co-commit the current flame trees with the cursor, like pending.
		$this->mirror_url_stats();
		$this->flush_stats_mirror();
		return [
			'pending' => $this->pending,
			'mirror'  => $this->checkpoint_mirror(),
		];
	}

	/**
	 * The held frames a checkpoint carries, smallest first, under the byte budget.
	 *
	 * Smallest-first keeps the most keys recoverable per byte, and drops the
	 * biggest — which are the per-server leaderboards, the one axis that grows
	 * with an operator input. Rank is not stored at all — `evict_lowest_rank()`
	 * derives it from the data it already holds.
	 *
	 * @return array{at: int, buffer: array<string,array{0: array<array-key,mixed>, 1: int}>, topn: array<string,array<string,array{0: array<array-key,mixed>, 1: int}>>}
	 */
	private function checkpoint_mirror(): array {
		$held = [];
		foreach ( $this->stats_mirror_buffer as $key => $frame ) {
			$held[] = [ '', $key, $frame, self::frame_bytes( $frame ) ];
		}
		foreach ( $this->stats_mirror_topn as $ns => $frames ) {
			foreach ( $frames as $key => $frame ) {
				$held[] = [ $ns, $key, $frame, self::frame_bytes( $frame ) ];
			}
		}
		\usort( $held, static fn ( array $a, array $b ): int => $a[3] <=> $b[3] );

		$budget = self::MAX_CHECKPOINT_MIRROR_BYTES;
		$out    = [ 'at' => $this->now_ts(), 'buffer' => [], 'topn' => [] ];
		foreach ( $held as $i => [ $ns, $key, $frame, $size ] ) {
			if ( $size > $budget ) {
				$over = \count( $held ) - $i;
				$this->print_less_often( "flame-builder: {$over} held stats frames over the checkpoint budget; they still reach the mirror at bucket close" );
				break;
			}
			$budget -= $size;
			if ( '' === $ns ) {
				$out['buffer'][ $key ] = $frame;
			} else {
				$out['topn'][ $ns ][ $key ] = $frame;
			}
		}
		return $out;
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
	 * Drain the current per-URL flame/profile stats_cache into the store (memcache + the
	 * mirror seam). Shared by flush() and save_state() so the flame trees co-commit with
	 * the cursor at every checkpoint, not only on the FLUSH_INTERVAL_SEC cadence.
	 * set_url_stats overwrites with the full aggregate and does NOT reset stats_cache, so
	 * a save_state drain plus the next flush() is idempotent (no double-count).
	 */
	private function mirror_url_stats(): void {
		$stats_store = $this->stats_store;
		if ( null === $stats_store ) {
			return;
		}
		$now = $this->now_ts();
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
			$stats_store->set_url_stats( $url_hash, $aggregate );
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
	 * The held frames are not undurable, just durable somewhere cheaper: they ride
	 * `save_state()` into the offsetlog, a bounded ring of at most 60 keyframes,
	 * where `flame-stats` retains for twice the stats window. A respawn restores
	 * them and writes them when the bucket closes.
	 *
	 * Aggregates flush in full; the per-URL namespaces flush their bounded top-N.
	 */
	private function flush_stats_mirror(): void {
		if ( '' === $this->stats_partition ) {
			return;
		}
		$partition = $this->resolve_stats_partition();
		if ( null === $partition ) {
			$this->print_less_often( "flame-builder: stats_partition '{$this->stats_partition}' not found at flush" );
			return; // Keep the buffer; retry next checkpoint once the node exists.
		}
		$now = $this->now_ts();
		$this->stats_mirror_buffer = $this->write_closed_frames( $partition, $this->stats_mirror_buffer, $now );
		foreach ( $this->stats_mirror_topn as $ns => $entries ) {
			$this->stats_mirror_topn[ $ns ] = $this->write_closed_frames( $partition, $entries, $now );
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
	 * Write one mirror frame (TM_STRUCT {key,data,ttl}) to the partition.
	 *
	 * Written straight to the partition rather than through the sink, so the
	 * frame lands in the checkpoint regardless of how the graph is wired.
	 *
	 * @param \Newspack_Nodes\Partition_Node $partition Resolved stats partition.
	 * @param string                         $key       Memcache key being shadowed.
	 * @param array<array-key,mixed>        $data      Value written.
	 * @param int                            $ttl       TTL the memcache write used.
	 */
	private function write_mirror_frame( \Newspack_Nodes\Partition_Node $partition, string $key, array $data, int $ttl ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::KEY ]       = $key;
		$msg[ Message::VALUE ]     = [ 'key' => $key, 'data' => $data, 'ttl' => $ttl ];
		$partition->fill( $msg );
	}

	/**
	 * Format one companion-index line for the stats mirror.
	 *
	 * Registered as `stats-index` and installed by
	 * `command_node flame-stats:partition:config with_index stats-index`. Fixed
	 * width so `parse_stats_index()` can slice it by offset: key_hash(12)
	 * segment(6) offset(10) length(8) = 36 bytes.
	 *
	 * The key is hashed because a backend key runs some seventy characters and a
	 * fixed-width line needs a bound; `Log_Manager::url_hash()` is the house
	 * 12-char digest rather than a second hashing convention. A reader files a
	 * located frame under the key the FRAME carries, so a collision costs one
	 * wasted read and can never file a value under a name that is not its own.
	 *
	 * @param array<int,mixed>  $message  The unpacked positional message array.
	 * @param array<string,int> $position Position array with segment, offset, length.
	 * @return string|null Index entry, or null for a frame carrying no key.
	 */
	public static function format_stats_index_entry( array $message, array $position ): ?string {
		$value = $message[ Message::VALUE ] ?? null;
		$key   = \is_array( $value ) ? ( $value['key'] ?? null ) : null;
		if ( ! \is_string( $key ) || '' === $key ) {
			return null;
		}
		return Log_Manager::url_hash( $key )
			. \str_pad( (string) $position['segment'], 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT );
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
	 * same release already resets.
	 *
	 * @api Used by substrate.
	 * @param array<string,mixed> $saved A prior `save_state()` return value.
	 */
	public function restore_state( array $saved ): void {
		$pending = Core::arr( $saved['pending'] ?? null );
		$mirror = Core::arr( $saved['mirror'] ?? null );
		// ADR-18: a lifetime that ran out is a miss, not a resurrection.
		$elapsed                   = \max( 0, $this->now_ts() - Core::num_int( $mirror['at'] ?? null ) );
		$this->stats_mirror_buffer = self::restore_frames( $mirror['buffer'] ?? null, $elapsed );
		$topn                      = Core::arr( $mirror['topn'] ?? null );
		// Re-bound by TRAFFIC; the carry's own order is smallest-first.
		foreach ( self::STATS_MIRROR_TOPN as $ns => $ignored_default ) {
			$restored = self::restore_frames( $topn[ $ns ] ?? null, $elapsed );
			\uasort(
				$restored,
				static fn ( array $a, array $b ): int =>
					self::mirror_traffic_rank( $b[0], $ns ) <=> self::mirror_traffic_rank( $a[0], $ns )
			);
			$this->stats_mirror_topn[ $ns ] = \array_slice( $restored, 0, \max( 0, $this->mirror_topn( $ns ) ), true );
		}
		foreach ( $pending as $bucket => $acc ) {
			// `Y-m-d-H-i` is a string PHP never re-types to int.
			if ( \is_string( $bucket ) && '' !== $bucket && \is_array( $acc ) ) {
				/** @var Bucket_Acc $merged */
				$merged                   = \array_merge( self::empty_bucket(), $acc );
				$this->pending[ $bucket ] = $merged;
			}
		}
	}

	/** Current Unix time, through the test clock seam when one is installed. */
	private function now_ts(): int {
		return null !== $this->clock_fn ? ( $this->clock_fn )() : \time();
	}

	/**
	 * The live top-N cap for a per-URL namespace: the configurable $flame_topn for
	 * NS_URL (the flame profiles), the fixed STATS_MIRROR_TOPN default otherwise.
	 *
	 * @param string $ns One of the STATS_MIRROR_TOPN namespaces.
	 */
	private function mirror_topn( string $ns ): int {
		return Stats_Store::NS_URL === $ns
			? $this->flame_topn
			: self::STATS_MIRROR_TOPN[ $ns ];
	}

	/**
	 * Traffic rank (~request count) for the per-URL namespaces.
	 *
	 * Each namespace stores a different shape, so each derives the count its own
	 * way. The result only has to order URLs against each other.
	 *
	 * @param array<array-key,mixed> $data Value being mirrored.
	 * @param string                  $ns   Namespace it belongs to.
	 */
	private static function mirror_traffic_rank( array $data, string $ns ): int {
		if ( Stats_Store::NS_URL === $ns ) {
			$flame = $data['flame'] ?? null;
			return \is_array( $flame ) && \is_numeric( $flame['count'] ?? null ) ? (int) $flame['count'] : 0;
		}
		if ( Stats_Store::NS_URL_CAT === $ns ) {
			// One bucket: the `total` pseudo-category's sampled requests.
			$total = $data[ self::TOTAL_KEY ] ?? null;
			return \is_array( $total ) && \is_numeric( $total['n'] ?? null ) ? (int) $total['n'] : 0;
		}
		// NS_URL_DIM: one bucket; take the first dimension's counts.
		$sum   = 0;
		$first = \reset( $data );
		if ( \is_array( $first ) ) {
			foreach ( $first as $vd ) {
				$sum += \is_array( $vd ) && \is_numeric( $vd['c'] ?? null ) ? (int) $vd['c'] : 0;
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
			if ( \is_string( $key ) && \is_array( $data ) && $ttl > 0 ) {
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
	 * @param int                    $partition Keyspace those keys sit in; the buffers key absolutely.
	 * @return array<string,array{value: array<array-key,mixed>, ttl: int}>
	 */
	private function held_frames( array $keys, int $partition ): array {
		$found = [];
		foreach ( $keys as $key ) {
			// The seam is public and untyped; only strings name a key.
			if ( ! \is_string( $key ) ) {
				continue;
			}
			$held = Stats_Store::entry_key( $partition, $key );
			$frame = $this->stats_mirror_buffer[ $held ] ?? null;
			foreach ( $this->stats_mirror_topn as $entries ) {
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
	 * Pre-check the owned auto-tuner sibling's `{name}:auto-tuner` slot
	 * alongside the base's own-name + `:config` checks. Chains parent::.
	 *
	 * @api Used by substrate.
	 * @param string $name Name the node is about to take.
	 * @throws \RuntimeException When the sibling slot is already registered.
	 */
	protected function check_name_availability( string $name ): void {
		if ( null !== $this->auto_tuner && null !== \Newspack_Nodes\Core::node( "{$name}:auto-tuner" ) ) {
			throw new \RuntimeException( \esc_html( "node name collision: {$name}:auto-tuner already registered" ) );
		}
		parent::check_name_availability( $name );
	}

	/**
	 * Track the owned auto-tuner sibling as `{name}:auto-tuner`. Only called from
	 * name() with a non-empty $name; sibling teardown lives in remove_node().
	 * Chains parent::.
	 *
	 * @api Used by substrate.
	 * @param string|null $name New node name.
	 */
	protected function set_sibling_names( ?string $name = null ): void {
		$this->auto_tuner?->name( "{$name}:auto-tuner" );
		parent::set_sibling_names( $name );
	}

	/**
	 * Cascade-remove the owned auto-tuner sibling alongside the patron. Full
	 * remove_node (not a bare unregister) so the auto-tuner's own `:config`
	 * interpreter sibling unregisters too and a same-name respawn doesn't collide.
	 *
	 * @api Used by substrate.
	 */
	public function remove_node(): void {
		if ( null !== $this->auto_tuner ) {
			$this->auto_tuner->remove_node();
			$this->auto_tuner = null;
		}
		parent::remove_node();
	}

	/**
	 * Propagate the make_node auto-sink down to the owned auto-tuner sibling, so
	 * it too sinks into `_command_interpreter` like any other sibling (Rule 2c).
	 * Without this the tuner has no sink and its replies go nowhere.
	 *
	 * @api Used by substrate.
	 * @param Node|null $node New sink; omit the argument entirely to read.
	 * @return Node|null The current sink.
	 */
	public function sink( ?Node $node = null ): ?Node {
		if ( \func_num_args() > 0 ) {
			$this->auto_tuner?->sink( $node );
			return parent::sink( $node );
		}
		return parent::sink();
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
	 * Replace the clock used for bucket-key derivation (testing seam).
	 *
	 * @api Used by tests.
	 * @param (callable(): int)|null $fn Clock returning a Unix timestamp; null restores `time()`.
	 */
	public function set_clock( ?callable $fn ): void {
		$this->clock_fn = $fn;
	}

	/**
	 * The queued auto-tune decisions, keyed per rule id.
	 *
	 * @api Used by tests.
	 * @return array<string,array<string,list<string>>> Keys 'hooks', 'custom_events', 'new_significant'.
	 */
	public function get_auto_tune_state(): array {
		$names = static fn( array $set ): array => \array_keys( $set );
		return [
			'hooks'           => \array_map( $names, $this->hooks_to_disable ),
			'custom_events'   => \array_map( $names, $this->custom_events_to_disable ),
			'new_significant' => \array_map( $names, $this->new_significant_events ),
		];
	}

	/**
	 * Surface the stats-mirror partition as a named target so the console draws
	 * the flame-builder → flame-stats:partition edge. Display only: the mirror
	 * writes go straight to the partition at flush (bypassing the sink), so
	 * without this override the partition renders disconnected even while it
	 * fills. What actually gets mirrored is driven by add_snapshot_node +
	 * set_stats_target, not by this method.
	 *
	 * @api Used by substrate.
	 * @param array<int,string>|string|null $value New primary target, or null to read.
	 * @return array<int,string>|string The primary target, plus the stats partition when set.
	 */
	public function target( $value = null ) {
		if ( null !== $value ) {
			return parent::target( $value );
		}
		$primary = parent::target();
		$extras  = [];
		if ( '' !== $this->stats_partition ) {
			$extras[] = $this->stats_partition;
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
		$out = parent::dump_config();
		if ( $this->is_hub ) {
			$out .= $this->config_line( 'set_is_hub', 'true' );
		}
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
			'description' => 'Aggregates per-event count + sum_time into the 9-namespace memcache schema; emits flame JSONL.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'set_is_hub',
					'description' => 'Toggle hub mode (per-server tracking).',
					'args'        => [
						[ 'name' => 'is_hub', 'type' => 'bool', 'required' => true, 'default' => '<eln:is_hub>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $patron */
						$patron = $interpreter->patron();
						// THE bool parse; a local one here rejected yes/on.
						$patron->set_is_hub(
							self::truthy( \trim( Core::as_string( $args[0] ?? '' ) ) )
						);
						return 'ok';
					},
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
