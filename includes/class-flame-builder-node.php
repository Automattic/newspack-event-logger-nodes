<?php
/**
 * Flame Builder
 *
 * The flame half of the event logger's request fan-out. A Consumer tails
 * `requests.p{N}` and fills this node with one TM_STRUCT message per
 * completed request; the node turns each into a flame tree (via
 * `Flame_Tree`) and forwards it to the `flames:partition` Partition,
 * stamped with the request's `rid`, its `url_hash` and its `request_at`,
 * the record's own ID in `requests.p{N}`. `Stats_Builder_Node` tails the
 * flames and reads each record where its `request_at` names it, so a
 * request's rows never land before its flame.
 *
 * One side channel hangs off that pipeline: the request's governing `Rule`
 * drives auto-tune. Hooks that fire too often, custom events to disable, and
 * newly significant events accumulate here and are emitted as messages to the
 * owned `Auto_Tuner_Node` sibling, which rewrites the rule. A category's
 * significance is its mean per call over the folds since the last flush, at
 * most `AUTO_TUNE_WINDOW` of them.
 *
 * The builder holds nothing durable: a flame is written as it is built, and
 * a decision waits for the next flush or a clean stop.
 *
 * Worker context: this node runs inside the `flame-builder` (or `complete`)
 * topology. See `topologies/flame-builder.tsl` for the wiring.
 *
 * Quiet and the auto-tune lock's wait are durations on the MONOTONIC clock.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds flame trees from completed requests and runs auto-tune.
 */
class Flame_Builder_Node extends Timer_Node implements Shutdown_Sweeper {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;

	/** Seconds between periodic flush() runs: the cadence of the Router-tick timer. */
	const FLUSH_INTERVAL_SEC = 5;

	/**
	 * Seconds a builder consumes nothing before it is quiet: twelve flushes,
	 * a minute. The request builder consumes on every tick of a replay, and
	 * its window is six minutes, so a minute costs a dead worker's request no
	 * whole window.
	 */
	public const IDLE_AFTER_SEC = 12 * self::FLUSH_INTERVAL_SEC;

	/**
	 * Monotonic clock seam, replacing `hrtime( true )` where a builder times
	 * its own quiet or a wait. Tests reassign it to step the monotonic clock
	 * apart from the wall one. Signature: `function (): int`, nanoseconds.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $hrtime_fn = null;

	/**
	 * The most requests a significance average spans: the sums start again
	 * after this many folds, and at every flush.
	 */
	private const AUTO_TUNE_WINDOW = 1000;

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

	/** The one width `format_index_entry()` writes and `parse_flame_index()` reads. */
	private const INDEX_LINE_BYTES = 68;

	/**
	 * Auto-tune decisions accrued since the last emit: the key `Auto_Tuner_Node`
	 * dispatches on => rule id => {name => true}. Keying by the emit key is what
	 * makes a fourth decision kind one key here and one case there.
	 *
	 * @var array<string,array<string,array<string,bool>>>
	 */
	private array $auto_tune = [ 'disable_hooks' => [], 'disable_custom_events' => [], 'add_significant_events' => [] ];

	/** @var Rule_Set|null Lazily-loaded per-worker ruleset (thresholds are per-rule). */
	private ?Rule_Set $rule_set = null;

	/** @var array<string,array<string,bool>> rule_id => {event => true} known-significant dedupe cache. */
	private array $significant_events = [];

	/**
	 * Each rule's categories, summed ms and calls over the folds since the
	 * sums last started: the significant-event signal's window.
	 *
	 * @var array<string,array<string,array{0:float,1:int}>>
	 */
	private array $category_sums = [];

	/** Folds the sums hold, which start again at `AUTO_TUNE_WINDOW`. */
	private int $folds = 0;

	/**
	 * Publish the owned auto-tuner sibling.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		// Owned auto-tuner sibling (patron-linked; hidden from the canvas).
		$auto_tuner = new Auto_Tuner_Node();
		$auto_tuner->patron( $this );
		$this->publish_sibling( 'auto-tuner', $auto_tuner );

		parent::__construct();
	}

	/**
	 * Store the tokens and arm the periodic flush on the Router tick, every
	 * FLUSH_INTERVAL_SEC. A node constructed but never given arguments never
	 * fires; `shutdown_sweep()` still emits its decisions on a clean stop.
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
	 * Handle one message: a TM_REQUEST introspection verb, or a TM_STRUCT
	 * completed-request record tailed off `requests.p{N}`.
	 *
	 * A completed request becomes a flame tree, is forwarded to the flames
	 * partition, and tunes its rule. A record whose URL does not name a host
	 * throws `InvalidArgumentException` before any of that, so the Consumer
	 * dead-letters it and reads on. Any other message is dropped.
	 *
	 * The message runs inside `deferring()`, which holds a cooperative stop a
	 * downstream forward raises until this message's own bookkeeping is
	 * finished, so the Consumer commits past it rather than replaying it.
	 *
	 * @param array<int,mixed> $message Positional Message array.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		$this->deferring( fn () => $this->fold_record( $message ) );
	}

	/**
	 * Router-TIMER tick: start the sums again and emit the auto-tune
	 * decisions. A failure raises here, out of the drain, and the worker
	 * exits loudly. A stop raised inside the flush waits for it to finish,
	 * as it does in `shutdown_sweep()`.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$this->deferring( fn () => $this->flush() );
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
		if ( ! ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) ) {
			return;
		}
		$request = $message[ Message::VALUE ];
		if ( ! \is_array( $request ) ) {
			return;
		}

		[ $request, $url, , $rid ] = Request_Builder_Node::checked_record( $request );
		$entries = $request['entries'] ?? [];
		if ( ! \is_array( $entries ) ) {
			$entries = [];
		}
		// @longform A record carries EITHER raw entries or — when
		// Request_Builder folded it under memory pressure — the merged tree it
		// built instead. Every record already on disk carries entries, so
		// accepting both shapes frees the fold from costing a rewrite of
		// history or a dual-write window.
		$prebuilt = $request['flame'] ?? null;
		/** @var array<string,mixed> $flame_data */
		$flame_data          = \is_array( $prebuilt ) && [] !== $prebuilt
			? $prebuilt
			: Flame_Tree::build_flame_data( $entries );
		// Never less than the extent covering already gave its children.
		$flame_data['value'] = \max(
			Core::num_float( $request['duration_ms'] ?? 0 ),
			Core::num_float( $flame_data['value'] ?? 0 )
		);

		$this->store_flame( $rid, Url_Parts::hash( $url ), Core::str( $message[ Message::ID ] ), $flame_data );
		$this->tune_profiles( $request, $url );
		if ( ++$this->folds >= self::AUTO_TUNE_WINDOW ) {
			$this->restart_sums();
		}
	}

	/**
	 * The `GET_STATS` reply data, which `answer_request()` sends back: the
	 * auto-tune queue depth.
	 *
	 * @return array<string,int>
	 */
	private function stats_report(): array {
		return [
			'auto_tune_pending_count'  => \array_sum( \array_map( self::map_total( ... ), $this->auto_tune ) ),
			'significant_events_count' => self::map_total( $this->significant_events ),
		];
	}

	/**
	 * Forward one request's flame tree to the flames partition.
	 *
	 * `rid` and `url_hash` are stamped into the payload because the companion
	 * index formatter (`format_index_entry`, registered as `flame-index` and
	 * installed by the topology's `with_index` verb) reads them off VALUE;
	 * `request_at` because the stats builder reads the record there.
	 *
	 * Writing is optional: without a target or sink the flame is simply not
	 * persisted, and the caller tunes its rule either way.
	 *
	 * @param string               $rid        Request ID.
	 * @param string               $url_hash   URL hash.
	 * @param string               $request_at The record's ID, `segment:offset:length`.
	 * @param array<string,mixed> $flame_data Flame tree; mutated locally, not by reference.
	 */
	private function store_flame( string $rid, string $url_hash, string $request_at, array $flame_data ): void {
		// Duplicate-sibling suffixes exist only to survive the merge.
		Flame_Tree::strip_name_suffixes( $flame_data );

		$flame_data['rid']        = $rid;
		$flame_data['url_hash']   = $url_hash;
		$flame_data['request_at'] = $request_at;

		if ( '' === $this->target || null === $this->sink ) {
			return;
		}
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::TO ]    = $this->target;
		$message[ Message::KEY ]   = $flame_data['rid'];
		$message[ Message::VALUE ] = $flame_data;
		// Deferred on a stop so the caller still tunes the rule.
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
	 * Feed a timed request's every profile category to the auto-tune checks
	 * of its governing rule, the stamped one, else the one its URL matches.
	 * A worker's feed only a rule written for the platform's own work, a
	 * platform route or `/jobs` and below (`Log_Manager::serves_platform()`):
	 * under the catch-all, a worker's hooks would tune what every visitor's
	 * page binds. A timeout's or an abort's profile is not a sample.
	 *
	 * @param array<array-key,mixed> $request The record.
	 * @param string                 $url     Its URL, which a rule matches by path.
	 */
	private function tune_profiles( array $request, string $url ): void {
		$timed    = Stats_Builder_Node::timing_counts( Core::num_float( $request['duration_ms'] ?? 0 ), $request['error_status'] ?? '-' );
		$profiles = $timed ? Core::arr( $request['profiles'] ?? null ) : [];
		if ( [] === $profiles ) {
			return;
		}
		$rules = $this->rule_set();
		$rule  = $rules->rule_by_id( Core::as_string( $request['rule_id'] ?? '' ) ) ?? $rules->for_url( $url );
		if ( null === $rule || ! $rule->is_log() || '' === $rule->id
			|| ( ! empty( $request['is_worker'] ) && ! Log_Manager::serves_platform( $rule->pattern ) ) ) {
			return;
		}
		foreach ( $profiles as $category => $data ) {
			if ( \is_string( $category ) && \is_array( $data ) ) {
				$this->tune( $rule, $category, Flame_Tree::span_kind( $category ), Core::num_float( $data['time'] ?? 0 ), Core::num_int( $data['count'] ?? 0 ) );
			}
		}
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
	 * Run a category through its rule's two auto-tune checks. Significant:
	 * the category's average per call over every fold since the sums
	 * started that tunes that rule reaches the time threshold;
	 * `tune_profiles()` decides which requests tune it, and a rule at
	 * `/jobs` or below can mix a job's calls with a page's there. Noisy:
	 * this request called it more often than the count threshold, and it is
	 * disabled as a custom event when the rule logs it as one, else as a
	 * hook. Only a span the rule binds by name is one auto-tune acts on, so
	 * any other is neither summed nor judged (`Flame_Tree::RULE_BOUND_KINDS`).
	 * A transport is promoted but never disabled: no hook list holds it.
	 *
	 * @param Rule   $rule     The request's governing log rule.
	 * @param string $category The category as logged.
	 * @param string $kind     Its `Flame_Tree::span_kind()`.
	 * @param float  $time     Its ms in this request.
	 * @param int    $calls    Its calls in this request.
	 */
	private function tune( Rule $rule, string $category, string $kind, float $time, int $calls ): void {
		if ( ! \in_array( $kind, Flame_Tree::RULE_BOUND_KINDS, true ) ) {
			return;
		}
		$sums = $this->category_sums[ $rule->id ][ $category ] ?? [ 0.0, 0 ];
		$sums = [ $sums[0] + $time, $sums[1] + $calls ];

		$this->category_sums[ $rule->id ][ $category ] = $sums;
		$base = Flame_Tree::hook_name( $category );
		$at   = $rule->auto_protect_time_threshold;
		if ( $at > 0 && $sums[1] > 0 && $sums[0] / $sums[1] >= $at
			&& ! isset( $this->significant_events[ $rule->id ][ $base ] ) && ! $rule->marks_significant( $base ) ) {
			$this->significant_events[ $rule->id ][ $base ]                    = true;
			$this->auto_tune['add_significant_events'][ $rule->id ][ $base ] = true;
		}
		if ( 'transport' !== $kind && $rule->auto_disable_threshold > 0 && $calls > $rule->auto_disable_threshold ) {
			$kind                                        = \in_array( $base, $rule->custom_events, true ) ? 'disable_custom_events' : 'disable_hooks';
			$this->auto_tune[ $kind ][ $rule->id ][ $base ] = true;
		}
	}

	/**
	 * Emit the auto-tune decisions on a clean stop, waiting out the lock. The
	 * substrate runs the sweep before the cursor handoff, while the graph is
	 * intact.
	 *
	 * A sibling partition may hold the auto-tune lock at that moment. A
	 * periodic flush leaves the decisions for the next flush; a stop has none,
	 * so it waits the lock out — the lock expires in seconds on its own.
	 *
	 * The sweep runs inside `deferring()`, as a message does, so a stop raised
	 * inside it waits for the emit to finish.
	 *
	 * @api Used by substrate.
	 */
	public function shutdown_sweep(): void {
		$this->deferring( fn () => $this->apply_auto_tune( self::AUTO_TUNE_LOCK_WAIT_MS ) );
	}

	/**
	 * Start the sums again, then emit the auto-tune decisions. `fire()` calls
	 * this once per FLUSH_INTERVAL_SEC.
	 */
	public function flush(): void {
		$this->restart_sums();
		$this->apply_auto_tune();
	}

	/**
	 * Start the significant-event sums again.
	 */
	private function restart_sums(): void {
		$this->category_sums = [];
		$this->folds         = 0;
	}

	/**
	 * Whether `IDLE_AFTER_SEC` of monotonic time has passed since `$mark`.
	 *
	 * Quiet is a duration inside one process, so it is measured on `hrtime()`,
	 * never the wall: a wall clock stepped forward does not count as quiet.
	 *
	 * @param int $mark A `monotonic()` the builder stamped when it last worked.
	 */
	public static function quiet_since( int $mark ): bool {
		return self::monotonic() - $mark >= self::IDLE_AFTER_SEC * 1_000_000_000;
	}

	/**
	 * Emit the accumulated auto-tune decisions: hooks and custom events to
	 * disable, and events newly promoted to significant.
	 *
	 * The decisions become a ruleset rewrite, and every flame-builder partition
	 * accumulates its own. A shared-tier `add()` with a 5-second expiry serves as a
	 * distributed lock so only one worker rewrites at a time; losing the race is
	 * not an error, the decisions simply survive to the next flush. With no
	 * shared cache tier there is nothing to race with, and the decisions fire
	 * without the lock.
	 *
	 * @param int $wait_ms How long to wait out a held lock; 0 leaves it to the next flush.
	 */
	private function apply_auto_tune( int $wait_ms = 0 ): void {
		if ( ! \array_filter( $this->auto_tune ) ) {
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
	 * The monotonic clock in ns, through the `$hrtime_fn` seam: the mark a
	 * builder stamps when it works, and the clock any wait inside one is
	 * timed on.
	 */
	public static function monotonic(): int {
		return Core::num_int( ( self::$hrtime_fn ?? static fn (): int => (int) \hrtime( true ) )() );
	}

	/**
	 * Emit one message per decision kind for every rule that accrued any, then
	 * clear the queues.
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
	 * @throws \RuntimeException With no sink to carry it.
	 */
	private function emit_auto_tune( string $key, string $rule_id, array $items ): void {
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
		$this->require_sink()->fill( $message );
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
	 * `format_index_entry()`, and bound to the same fixed widths. A line of
	 * any other length is a truncated, half-written or run-together record.
	 *
	 * @param string $line Index line.
	 * @return array{rid: string, url_hash: string, segment: int, offset: int, length: int}|null Null when the line is not `INDEX_LINE_BYTES` long.
	 */
	public static function parse_flame_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( self::INDEX_LINE_BYTES !== \strlen( $line ) ) {
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
	 * Declarative schema: the TM_REQUEST verb and the palette metadata.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Builds each completed request\'s flame tree and forwards it, naming its record, to the flames partition; runs auto-tune.',
			'arguments'   => [],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'The auto-tune queue depth.',
					'reply_shape' => '{ auto_tune_pending_count, significant_events_count }',
					'handler'     => static fn ( self $node ): array => $node->stats_report(),
				],
			],
		];
	}
}
