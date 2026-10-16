<?php
/**
 * Flame Builder
 *
 * The stats fan-out of the event logger. A Consumer tails `requests.p{N}` and
 * fills this node with one TM_STRUCT message per completed request; the node
 * turns each into a flame tree (via `Flame_Tree`), forwards it to the
 * `flames:partition` Partition for the flame viewer, and buffers the rows
 * the request contributes to the `flame-facts` Row_Table
 * (`Flame_Facts_Row_Schema`): its `requests` row, the `urls` and `agents`
 * rows it names, its `requests_hourly` rollup and, when timed, its
 * `timings` and `timings_site` rows.
 *
 * `write_rows()` sends the buffer as one `ROWS` batch, one transaction, every
 * FLUSH_INTERVAL_SEC, at every checkpoint, and as soon as it holds
 * BUFFERED_REQUESTS_LIMIT requests or BUFFERED_OPS_LIMIT ops. A batch names
 * each URL and user agent once. Each request's rollups ride
 * as `then` ops of its `requests` row, applied only when that row inserted,
 * so a replay of records already written adds nothing.
 *
 * The builder also keeps `category_window`, each category's and entry's
 * sums over the last `WINDOW_BUCKETS`: a request adds its timings when its
 * bucket is newer than the window's watermark, and every write subtracts
 * each bucket that left the window, once per `window_expiry` row. With no
 * watermark known, or one older than `timings_site` holds, the window is
 * rebuilt from `timings_site`. The watermark rides the builder's snapshot
 * state, so a restart reads no file for it.
 *
 * One side channel hangs off that pipeline: the request's governing `Rule`
 * drives auto-tune. Hooks that fire too often, custom events to disable, and
 * newly significant events accumulate here and are emitted as messages to the
 * owned `Auto_Tuner_Node` sibling, which rewrites the rule.
 *
 * Worker context: this node runs inside the `flame-builder` (or `complete`)
 * topology. See `topologies/flame-builder.tsl` for the wiring.
 *
 * A record is filed where it finished on its own STREAM clock, clamped to
 * the WALL tick that brought it; `idle_since()` answers the WALL moment of
 * the last write, and the auto-tune lock's wait is a duration on the
 * MONOTONIC clock.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Core;
use Newspack_Nodes\Idle_Reporter;
use Newspack_Nodes\Log_Position;
use Newspack_Nodes\Message;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Table_Client;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds flame trees and the flame-facts rows from completed requests.
 *
 * @phpstan-import-type Row_Op from \Newspack_Nodes\Sqlite_Arm
 */
class Flame_Builder_Node extends Timer_Node implements Shutdown_Sweeper, Idle_Reporter {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;
	use Narration;

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
	 * Error statuses whose duration ends at an interruption rather than the
	 * request's end: a timeout and an abort (decision 24).
	 *
	 * @var list<string>
	 */
	public const UNTIMED_STATUSES = [ 'T', 'A' ];

	/**
	 * The error statuses that count as errors: a timeout and a fatal. An
	 * abort is the logger's own worker stopping and a gap is missing detail.
	 *
	 * @var list<string>
	 */
	public const ERROR_KINDS = [ 'T', 'F' ];

	/** The lowest status code a status class is read from. */
	public const STATUS_MIN = 100;

	/** The highest status code a status class is read from. */
	public const STATUS_MAX = 599;

	/**
	 * Requests the buffer holds before the record reaching the count writes
	 * it, whatever the tick: a replay folds far faster than the tick, so the
	 * count is what bounds a batch and what commits a replay as it goes.
	 */
	public const BUFFERED_REQUESTS_LIMIT = 1000;

	/**
	 * Ops the buffer holds before the record reaching the count writes it:
	 * memory follows ops, about 730 bytes each, and a timed request adds
	 * three per profile category and entry, so this bounds a batch near
	 * 15 MB.
	 */
	public const BUFFERED_OPS_LIMIT = 20_000;

	/**
	 * Monotonic clock seam, replacing `hrtime( true )` where a builder times
	 * its own quiet or a wait. Tests reassign it to step the monotonic clock
	 * apart from the wall one. Signature: `function (): int`, nanoseconds.
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

	/** Unix time of the last periodic flush(), which GET_STATS reports as an age. */
	private float $last_flush_time = 0.0;

	/** Unix time of the last write that held a folded record: `idle_since()`. */
	private float $worked_at = 0.0;

	/** @var Rule_Set|null Lazily-loaded per-worker ruleset (thresholds are per-rule). */
	private ?Rule_Set $rule_set = null;

	/** @var array<string,array<string,bool>> rule_id => {event => true} known-significant dedupe cache. */
	private array $significant_events = [];

	/** Node NAME of the Row_Table the rows are written to ('' = unnamed). */
	private string $facts_target = '';

	/** The builder's asker for its Row_Table. */
	private Table_Client $client;

	/**
	 * The ROWS batch the next write sends: each buffered request's ops, in
	 * the order they were buffered.
	 *
	 * @var list<Row_Op>
	 */
	private array $rows = [];

	/** Requests the batch holds. */
	private int $buffered = 0;

	/** Ops the batch holds, `then` ops included. */
	private int $ops = 0;

	/** @var array<string,int> Batch index of each URL's `urls` op, by url_hash. */
	private array $url_at = [];

	/** @var array<string,int> Batch index of each user agent's `agents` op. */
	private array $ua_at = [];

	/**
	 * Each category's summed ms and calls over the non-worker requests the
	 * batch holds: the significant-event signal's window.
	 *
	 * @var array<string,array{0:float,1:int}>
	 */
	private array $category_sums = [];

	/** The last bucket the category window has expired; null until known. */
	private ?int $window_through = null;

	/** Whether this process has expired the window before its first fold. */
	private bool $window_synced = false;

	/**
	 * Seed the flush clock, build the Table asker, and publish the owned
	 * auto-tuner sibling.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		$this->last_flush_time = Core::$now;
		$this->worked_at       = Core::$now;
		$this->client          = new Table_Client( $this );

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
	 * Handle one message: the Row_Table's reply, a TM_REQUEST introspection
	 * verb, or a TM_STRUCT completed-request record tailed off `requests.p{N}`.
	 *
	 * A Table's reply goes to the client first, never to the fold: it is the
	 * answer to an ask a write made, not a record.
	 *
	 * A completed request becomes a flame tree, is forwarded to the flames
	 * partition, and is buffered as rows. A record whose URL names no host
	 * throws `InvalidArgumentException` before any of that, so the Consumer
	 * dead-letters it and reads on. Any other message is dropped. The record
	 * that brings the buffer to BUFFERED_REQUESTS_LIMIT requests or
	 * BUFFERED_OPS_LIMIT ops writes it.
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
		$type = Core::num_int( $message[ Message::TYPE ] );
		if ( ! $this->window_synced && 0 !== ( $type & Message::TM_STRUCT ) && 0 === ( $type & Message::TM_REQUEST ) ) {
			$this->sync_window();
		}
		$this->deferring( fn () => $this->fold_record( $message ) );
	}

	/**
	 * Router-TIMER tick: flush what the buffer holds and the auto-tune
	 * decisions a sibling's lock held back, and expire the window even when
	 * neither waits. A failure raises here, out of the drain, and the worker
	 * exits loudly; its successor replays from the last checkpoint's cursor.
	 * A stop raised inside the flush waits for it to finish, as it does in
	 * `shutdown_sweep()`.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$held = [] !== $this->rows || [] !== \array_filter( $this->auto_tune );
		$this->deferring( fn () => $this->flush() );
		if ( $held ) {
			$this->last_flush_time = Core::$now;
		}
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

		$url = Core::str( $request['url'] ?? '' );
		// A hostless URL or no method throws before any fold; it dead-letters.
		$server = Url_Parts::server_of( $url );
		// Bounded: an invented method must not mint a value of its own.
		$request['request_method'] = Request_Builder_Node::method_label( $request['request_method'] ?? null );

		$rid = Core::str( $request['rid'] ?? '' );
		if ( '' === $rid ) {
			throw new \InvalidArgumentException( \esc_html( "Flame_Builder: a record for {$url} carries no rid" ) );
		}
		$url_hash = Log_Manager::url_hash( $url );
		$entries  = $request['entries'] ?? [];
		if ( ! \is_array( $entries ) ) {
			$entries = [];
		}

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
			Core::num_float( $request['duration_ms'] ?? 0 ),
			Core::num_float( $flame_data['value'] ?? 0 )
		);

		$this->store_flame( $rid, $url_hash, $flame_data );
		// A replayed record's row is ignored by its rid, its rollups with it.
		$this->buffer_request( $request, $rid, Core::str( $message[ Message::ID ] ), $url, $url_hash, $server );
		if ( $this->buffered >= self::BUFFERED_REQUESTS_LIMIT || $this->ops >= self::BUFFERED_OPS_LIMIT ) {
			$this->write_rows();
		}
	}

	/**
	 * The `GET_STATS` reply data, which `answer_request()` sends back: what
	 * the buffer holds and the auto-tune queue depth.
	 *
	 * @return array<string,mixed>
	 */
	private function stats_report(): array {
		return [
			'buffered_requests'        => $this->buffered,
			'buffered_rows'            => \count( $this->rows ),
			'last_flush_age_s'         => $this->last_flush_time > 0 ? (int) ( Core::$now - $this->last_flush_time ) : null,
			'auto_tune_pending_count'  => \array_sum( \array_map( self::map_total( ... ), $this->auto_tune ) ),
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
	 * persisted, and the caller buffers its rows either way.
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
			return;
		}
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::TO ]    = $this->target;
		$message[ Message::KEY ]   = $flame_data['rid'];
		$message[ Message::VALUE ] = $flame_data;
		// Deferred on a stop so the caller still buffers the rows.
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
	 * Buffer one completed request's ops: an `ignore` for its URL and, when it
	 * carries one, its user agent, each the first time the batch meets it,
	 * whose ids the `requests` row takes by batch index, then that row's
	 * `ignore`, whose `then` ops add its hourly rollup
	 * and, when it is timed, a timing row per profile category and entry for
	 * the URL and for the site.
	 *
	 * Filed where it COMPLETED (decision 19): its start plus its duration,
	 * clamped to the tick, since a request cannot have finished after it
	 * reached the builder and a reader walks back from the wall. A timeout's
	 * or an abort's duration is no sample (decision 24), so its row times
	 * nothing and it writes no timing row; workers write their timings too,
	 * under `worker` 1, which a site-wide reader leaves out.
	 *
	 * @param array<array-key,mixed> $request  The record, its method labelled.
	 * @param string                 $rid      Its request id.
	 * @param string                 $crumb    The record's crumb, its place in `requests.p{N}`.
	 * @param string                 $url      The request's URL.
	 * @param string                 $url_hash Its `Log_Manager::url_hash()`.
	 * @param string                 $server   Its host.
	 * @throws \LogicException When no partition is bound, so no row can name one.
	 */
	private function buffer_request( array $request, string $rid, string $crumb, string $url, string $url_hash, string $server ): void {
		$partition = Core::bound_partition() ?? throw new \LogicException( 'Flame_Builder: no partition is bound, so no request row can name one' );
		$duration  = Core::num_float( $request['duration_ms'] ?? 0 );
		$error     = $request['error_status'] ?? '-';
		$timed     = self::timing_counts( $duration, $error );
		$worker    = empty( $request['is_worker'] ) ? 0 : 1;
		$now       = (int) Core::$now;
		$started   = Core::num_int( $request['timestamp'] ?? $now, $now );
		$finished  = \min( $now, self::completed_at( $started, $duration ) );
		$bucket    = Bucket::id( $finished );
		$status    = \is_numeric( $request['status_code'] ?? null ) ? (int) $request['status_code'] : null;
		$method    = Core::str( $request['request_method'] );
		$country   = self::named( $request['country_code'] ?? null );
		$ua        = self::named( $request['user_agent'] ?? null );
		$peak      = \max( 0.0, Core::num_float( $request['peak_mb'] ?? 0 ) );
		$ms        = $timed ? $duration : 0.0;
		$place     = Log_Position::crumb( $crumb );

		$url_at = $this->url_at[ $url_hash ] ??= $this->op( [ 'ignore', 'urls', [ null, $url_hash, $server, Url_Parts::row_path( $url, $server ) ] ] );
		$ids    = [ Flame_Facts_Row_Schema::position( 'requests', 'url_id' ) => $url_at ];
		if ( null !== $ua ) {
			$ids[ Flame_Facts_Row_Schema::position( 'requests', 'ua_id' ) ] = $this->ua_at[ $ua ] ??= $this->op( [ 'ignore', 'agents', [ null, $ua ] ] );
		}
		$then = [
			[ 'add', 'requests_hourly', [ Bucket::hour( $finished ), $server, self::status_class( $status ), $method, $worker, $country ?? '', 1, $timed ? 1 : 0, $ms, $ms, $peak, self::error_counts( $error ) ? 1 : 0 ] ],
		];
		$profiles = $timed ? Core::arr( $request['profiles'] ?? null ) : [];
		$url_id   = [ Flame_Facts_Row_Schema::position( 'timings', 'url_id' ) => $url_at ];
		$timings  = $this->profile_rows( $profiles, $request, $url, 1 === $worker );
		$windowed = $bucket > $this->window_through;
		foreach ( $timings as [ $category, $entry, $listener, $time, $calls ] ) {
			$then[] = [ 'add', 'timings', [ $bucket, $server, null, $category, $entry, $listener, $time, $calls, 1 ], [], $url_id ];
			$then[] = [ 'add', 'timings_site', [ $bucket, $server, $worker, $category, $entry, $listener, $time, $calls, 1 ] ];
			if ( $windowed ) {
				$then[] = [ 'add', 'category_window', [ $category, $entry, $listener, $worker, $time, $calls, 1, $bucket ] ];
			}
		}
		$this->ops += \count( $then );
		$this->op(
			[
				'ignore',
				'requests',
				[
					$rid,
					$bucket,
					(float) $finished,
					$started,
					$partition,
					$place['segment'] ?? null,
					$place['offset'] ?? null,
					$place['length'] ?? null,
					$server,
					null,
					$status,
					$method,
					$country,
					null,
					self::named( $request['ja4_hash'] ?? null ),
					self::named( $request['http_from'] ?? null ),
					$worker,
					[] === $timings ? 0 : 1,
					\in_array( $error, Request_Builder_Node::ERROR_STATUSES, true ) ? $error : null,
					$timed ? $duration : null,
					$peak,
				],
				$then,
				$ids,
			]
		);
		++$this->buffered;
	}

	/**
	 * Append one op to the batch and count it.
	 *
	 * @param Row_Op $op The op.
	 * @return int Its batch index, which a later op's `ids` names.
	 */
	private function op( array $op ): int {
		$this->rows[] = $op;
		++$this->ops;
		return \count( $this->rows ) - 1;
	}

	/**
	 * A record field as a row stores it: its string, or null when it is empty
	 * or no scalar, so a reader spells every absence one way.
	 *
	 * @param mixed $value The field.
	 */
	private static function named( mixed $value ): ?string {
		$name = Core::as_string( $value );
		return '' === $name ? null : $name;
	}

	/**
	 * The second a request completed at, which files its row in a bucket:
	 * its start plus its duration rounded to the second. The builder clamps
	 * it to the tick that brought the record.
	 *
	 * @param int   $started     The record's start, in unix seconds.
	 * @param float $duration_ms The record's duration.
	 */
	public static function completed_at( int $started, float $duration_ms ): int {
		return $started + (int) \round( $duration_ms / 1000 );
	}

	/**
	 * Whether a request's duration is a timing sample. A timeout's and an
	 * abort's are fictions, and would skew the mean and the max.
	 *
	 * @param float $duration_ms  The record's duration.
	 * @param mixed $error_status The record's error status, `-` when none.
	 */
	public static function timing_counts( float $duration_ms, mixed $error_status ): bool {
		return $duration_ms > 0 && ! \in_array( $error_status, self::UNTIMED_STATUSES, true );
	}

	/**
	 * The status class an hourly row keys on: the code's hundreds digit from
	 * STATUS_MIN to STATUS_MAX, else 0, the key's spelling of an absent class.
	 *
	 * @param int|null $status The record's status code, null when absent.
	 */
	private static function status_class( ?int $status ): int {
		return null !== $status && $status >= self::STATUS_MIN && $status <= self::STATUS_MAX ? \intdiv( $status, 100 ) : 0;
	}

	/**
	 * Whether a request is an error: its status is one of ERROR_KINDS,
	 * whatever status code it answered, and whether or not its duration
	 * times it.
	 *
	 * @param mixed $error_status The record's error status, `-` when none.
	 */
	public static function error_counts( mixed $error_status ): bool {
		return \in_array( $error_status, self::ERROR_KINDS, true );
	}

	/**
	 * A timed request's profile as timing rows: each category, entry '', and
	 * each of its entries, with whether the category is a listener span,
	 * whose time already counts inside its hook's. A non-worker request's
	 * categories feed the auto-tune checks of its governing rule: the
	 * stamped one, else the one its URL matches.
	 *
	 * @param array<array-key,mixed> $profiles `profiles{}` from the record.
	 * @param array<array-key,mixed> $request  The record.
	 * @param string                 $url      Its URL, which a rule matches by path.
	 * @param bool                   $worker   Whether a worker served it.
	 * @return list<array{0:string,1:string,2:int,3:float,4:int}> Category, entry, listener, ms, calls.
	 */
	private function profile_rows( array $profiles, array $request, string $url, bool $worker ): array {
		if ( [] === $profiles ) {
			return [];
		}
		$rules = $this->rule_set();
		$rule  = $rules->rule_by_id( Core::as_string( $request['rule_id'] ?? '' ) ) ?? $rules->for_url( $url );
		$tunes = ! $worker && null !== $rule && $rule->is_log() && '' !== $rule->id;
		$rows  = [];
		foreach ( $profiles as $category => $data ) {
			if ( ! \is_string( $category ) || ! \is_array( $data ) ) {
				continue;
			}
			$listener = Flame_Tree::is_listener_span( $category ) ? 1 : 0;
			$time     = Core::num_float( $data['time'] ?? 0 );
			$calls    = Core::num_int( $data['count'] ?? 0 );
			$rows[]   = [ $category, '', $listener, $time, $calls ];
			foreach ( Core::arr( $data['entries'] ?? null ) as $name => $entry ) {
				$entry  = Core::arr( $entry );
				$rows[] = [ $category, (string) $name, $listener, Core::num_float( $entry[0] ?? 0 ), Core::num_int( $entry[1] ?? 0 ) ];
			}
			if ( $tunes ) {
				$this->tune( $rule, $category, $time, $calls );
			}
		}
		return $rows;
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
	 * Run a non-worker category through its rule's two auto-tune checks.
	 * Significant: the category's average per call over every non-worker
	 * request the batch holds reaches the time threshold. Noisy: this
	 * request called it more often than the count threshold, and it is
	 * disabled as a custom event when the rule logs it as one, else as a
	 * hook. A listener or a
	 * plugin-load span is a view auto-tune cannot act on.
	 *
	 * @param Rule   $rule     The request's governing log rule.
	 * @param string $category The category as logged.
	 * @param float  $time     Its ms in this request.
	 * @param int    $calls    Its calls in this request.
	 */
	private function tune( Rule $rule, string $category, float $time, int $calls ): void {
		$sums                               = $this->category_sums[ $category ] ?? [ 0.0, 0 ];
		$sums                               = [ $sums[0] + $time, $sums[1] + $calls ];
		$this->category_sums[ $category ] = $sums;
		if ( Flame_Tree::is_listener_span( $category ) || Flame_Tree::is_plugin_load_span( $category ) ) {
			return;
		}
		$base = Flame_Tree::hook_name( $category );
		$at   = $rule->auto_protect_time_threshold;
		if ( $at > 0 && $sums[1] > 0 && $sums[0] / $sums[1] >= $at
			&& ! isset( $this->significant_events[ $rule->id ][ $base ] ) && ! $rule->marks_significant( $base ) ) {
			$this->significant_events[ $rule->id ][ $base ]                    = true;
			$this->auto_tune['add_significant_events'][ $rule->id ][ $base ] = true;
		}
		if ( $rule->auto_disable_threshold > 0 && $calls > $rule->auto_disable_threshold ) {
			$kind                                        = \in_array( $base, $rule->custom_events, true ) ? 'disable_custom_events' : 'disable_hooks';
			$this->auto_tune[ $kind ][ $rule->id ][ $base ] = true;
		}
	}

	/**
	 * Write the buffer and expire the window before the Consumer commits its
	 * cursor, and carry the watermark: the offsetlog holds it across restarts,
	 * so no start reads the file for it. A record the cursor replays is counted
	 * once by its `requests` row's rid (decision 31), and an expiry it replays
	 * once by its `window_expiry` row. Only the durable half of a flush runs
	 * here: an auto-tune emit that throws would keep the cursor from
	 * committing, so its decisions wait for the tick. A write that fails
	 * raises, and the cursor commits nothing past the records it held. In
	 * crawl mode the Consumer checkpoints every message, so this runs per
	 * message until `CHECKPOINT_INTERVAL_S` of crash-free progress ends the
	 * crawl.
	 *
	 * @api Used by substrate.
	 * @return array{window_through?:int}
	 */
	public function save_state(): array {
		$this->write_rows();
		return null === $this->window_through ? [] : [ 'window_through' => $this->window_through ];
	}

	/**
	 * Flush on a clean stop, waiting out the auto-tune lock. The substrate
	 * runs the sweep before the cursor handoff, while the graph is intact.
	 *
	 * A sibling partition may hold the auto-tune lock at that moment. A
	 * periodic flush leaves the decisions for the next flush; a stop has none,
	 * so it waits the lock out — the lock expires in seconds on its own.
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
					$this->flush();
					$this->apply_auto_tune( self::AUTO_TUNE_LOCK_WAIT_MS );
					// The last minute's writes would otherwise go untold.
					$this->tell_writes();
				},
				fn (): array => [ 'stopped', [] ]
			)
		);
	}

	/**
	 * Write the buffer, then emit the auto-tune decisions.
	 *
	 * `fire()` calls this once per FLUSH_INTERVAL_SEC and every clean stop
	 * through `shutdown_sweep()`; a checkpoint runs `write_rows()` alone. The
	 * buffer empties BEFORE the auto-tune emit: its rows are written by then,
	 * and a throw there must not hand them to the next flush again.
	 */
	public function flush(): void {
		$this->write_rows();
		$this->apply_auto_tune();
	}

	/**
	 * Send the buffer as one `ROWS` batch to the Row_Table `set_facts_target`
	 * named, one transaction, then expire the window, and start clean. Every
	 * checkpoint runs it before its cursor commits, so nothing the cursor has
	 * passed is left unwritten; an empty buffer still expires, so an idle
	 * tick moves the window too.
	 *
	 * A refused or unanswered batch, or any other failure, a stop included,
	 * raises a plain stop carrying it, and the buffer stays: the Consumer
	 * neither dead-letters the record in hand nor commits past it, and the
	 * successor replays every record the batch held.
	 *
	 * @throws Worker_Should_Stop Carrying whatever failed the write (ADR-14).
	 */
	private function write_rows(): void {
		$held = [] !== $this->rows;
		try {
			if ( $held ) {
				$this->client->write_rows( $this->facts_table(), $this->rows );
			}
			$this->expire_window();
		} catch ( \Throwable $e ) {
			throw Worker_Should_Stop::combine( [ new Worker_Should_Stop( 'stats write failed' ), $e ] );
		}
		if ( ! $held ) {
			return;
		}
		$this->tally( Flame_Tree::STATS_WRITES, 'flushes' );
		$this->tally( Flame_Tree::STATS_WRITES, 'requests', $this->buffered );
		$this->rows          = [];
		$this->buffered      = 0;
		$this->ops           = 0;
		$this->url_at        = [];
		$this->ua_at         = [];
		$this->category_sums = [];
		$this->worked_at     = Core::$now;
		// Once a minute rather than once a flush: a handful a lifetime.
		if ( $this->rollup_due( (int) Core::$now ) ) {
			$this->tell_writes();
		}
	}

	/**
	 * Expire the window before the first fold, so the gate reads a watermark
	 * no newer bucket has passed.
	 *
	 * @throws Worker_Should_Stop Carrying whatever failed, so the record replays.
	 */
	private function sync_window(): void {
		try {
			$this->expire_window();
		} catch ( \Throwable $e ) {
			throw Worker_Should_Stop::combine( [ new Worker_Should_Stop( 'stats window failed' ), $e ] );
		}
		$this->window_synced = true;
	}

	/**
	 * Move the window to end at the current bucket: subtract each bucket that
	 * left it, or rebuild it from `timings_site` when no watermark is known or
	 * `timings_site` may no longer hold the gap. One ROWS batch, its watermark
	 * last; a refusal raises and the watermark stays.
	 */
	private function expire_window(): void {
		$now     = Bucket::id( (int) Core::$now );
		$through = $now - Flame_Facts_Row_Schema::WINDOW_BUCKETS;
		if ( null !== $this->window_through && $this->window_through >= $through ) {
			return;
		}
		$table   = $this->facts_table();
		$batch   = null === $this->window_through || $this->window_through < $now - Flame_Facts_Row_Schema::TIMINGS_BUCKETS
			? $this->window_rebuild( $through, $now )
			: $this->window_expiries( $this->window_through + 1, $through );
		$batch[] = [ 'add', 'window_watermark', [ Flame_Facts_Row_Schema::WINDOW, $through ] ];
		$this->client->write_rows( $table, $batch );
		$this->window_through = $through;
	}

	/**
	 * One `ignore` per bucket from `$first` through `$last`, each carrying the
	 * negation of that bucket's `timings_site` sums as `then` ops.
	 *
	 * @param int $first The first bucket to expire.
	 * @param int $last  The last.
	 * @return list<Row_Op>
	 */
	private function window_expiries( int $first, int $last ): array {
		$sums = $this->own_rows(
			'SELECT bucket, category, entry, listener, worker, TOTAL( ms ), SUM( calls ), SUM( requests ) FROM %s.timings_site WHERE bucket BETWEEN ? AND ? GROUP BY bucket, category, entry, listener, worker',
			[ $first, $last ]
		);
		$then = \array_fill_keys( \range( $first, $last ), [] );
		foreach ( $sums as [ $bucket, $category, $entry, $listener, $worker, $ms, $calls, $requests ] ) {
			$bucket            = Core::as_int( $bucket );
			$then[ $bucket ][] = [ 'add', 'category_window', [ Core::as_string( $category ), Core::as_string( $entry ), Core::as_int( $listener ), Core::as_int( $worker ), -Core::num_float( $ms ), -Core::as_int( $calls ), -Core::as_int( $requests ), $bucket ] ];
		}
		$batch = [];
		foreach ( $then as $bucket => $negations ) {
			$batch[] = [ 'ignore', 'window_expiry', [ $bucket ], $negations ];
		}
		return $batch;
	}

	/**
	 * Every window row negated, then `timings_site` summed over the buckets
	 * after `$through` through `$now`: the window rebuilt exactly, whatever it
	 * held and whatever retention purged first. Each bucket `timings_site`
	 * still holds at or before `$through` is marked expired, so a watermark
	 * restored behind the rebuild subtracts none of them.
	 *
	 * @param int $through The bucket the rebuilt window starts after.
	 * @param int $now     The current bucket.
	 * @return list<Row_Op>
	 */
	private function window_rebuild( int $through, int $now ): array {
		$batch = [];
		foreach ( $this->own_rows( 'SELECT category, entry, listener, worker, ms, calls, requests, last_bucket FROM %s.category_window', [] ) as [ $category, $entry, $listener, $worker, $ms, $calls, $requests, $last ] ) {
			$batch[] = [ 'add', 'category_window', [ Core::as_string( $category ), Core::as_string( $entry ), Core::as_int( $listener ), Core::as_int( $worker ), -Core::num_float( $ms ), -Core::as_int( $calls ), -Core::as_int( $requests ), Core::as_int( $last ) ] ];
		}
		$sums = $this->own_rows(
			'SELECT category, entry, listener, worker, TOTAL( ms ), SUM( calls ), SUM( requests ), MAX( bucket ) FROM %s.timings_site WHERE bucket BETWEEN ? AND ? GROUP BY category, entry, listener, worker',
			[ $through + 1, $now ]
		);
		foreach ( $sums as [ $category, $entry, $listener, $worker, $ms, $calls, $requests, $last ] ) {
			$batch[] = [ 'add', 'category_window', [ Core::as_string( $category ), Core::as_string( $entry ), Core::as_int( $listener ), Core::as_int( $worker ), Core::num_float( $ms ), Core::as_int( $calls ), Core::as_int( $requests ), Core::as_int( $last ) ] ];
		}
		foreach ( \range( $now - Flame_Facts_Row_Schema::TIMINGS_BUCKETS + 1, $through ) as $bucket ) {
			$batch[] = [ 'ignore', 'window_expiry', [ $bucket ] ];
		}
		return $batch;
	}

	/**
	 * The Row_Table `set_facts_target` named, which every write needs.
	 *
	 * @throws \LogicException When the verb named none.
	 */
	private function facts_table(): string {
		return '' === $this->facts_target ? throw new \LogicException( 'Flame_Builder: no Row_Table named by set_facts_target' ) : $this->facts_target;
	}

	/**
	 * Rows of a statement over this partition's file alone, its alias the
	 * `%s`; none before the file exists.
	 *
	 * @param string            $sql   The statement.
	 * @param list<scalar|null> $binds Its binds.
	 * @return list<list<mixed>>
	 * @throws \LogicException When no partition is bound.
	 */
	private function own_rows( string $sql, array $binds ): array {
		$alias = 'p' . ( Core::bound_partition() ?? throw new \LogicException( 'Flame_Builder: no partition is bound, so no window can be read' ) );
		return Row_Table_Node::read(
			Flame_Facts_Row_Schema::class,
			static fn ( Row_Reader $reader ): array => \in_array( $alias, $reader->aliases(), true ) ? $reader->rows( \sprintf( $sql, $alias ), $binds ) : []
		);
	}

	/**
	 * Tell `stats writes`: what the flushes since the last line wrote, the
	 * flushes and requests first.
	 */
	private function tell_writes(): void {
		$this->rollup( Flame_Tree::STATS_WRITES, [ 'flushes', 'requests' ] );
	}

	/**
	 * Whether `IDLE_AFTER_SEC` of monotonic time has passed since `$mark`.
	 *
	 * Quiet is a duration inside one process, so it is measured on `hrtime()`,
	 * never the wall: a wall clock stepped forward is no quiet.
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
	 * @throws \RuntimeException With no sink, which the write before it needed too.
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
	 * Take the watermark the Consumer's last commit carried, and expire the
	 * window again before the next fold, since a seek may restore one behind
	 * the file's.
	 *
	 * @api Used by substrate.
	 * @param array<array-key,mixed> $state What `save_state()` returned.
	 */
	public function restore_state( array $state ): void {
		$this->window_through = isset( $state['window_through'] ) ? Core::as_int( $state['window_through'] ) : null;
		$this->window_synced  = false;
	}

	/**
	 * The `set_facts_target` verb's entry point, which the declared setter
	 * calls by name.
	 *
	 * @param string $table The Row_Table the rows are written to.
	 */
	public function set_facts_target( string $table ): void {
		$this->facts_target = $table;
	}

	/**
	 * The Row_Table the verb named, which the flush writes past its primary
	 * target, so the console draws an edge to it.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		return [ $this->facts_target ];
	}

	/**
	 * When this node went idle: the last write that held a folded record
	 * while nothing is buffered, or null while a record waits for the next
	 * write.
	 *
	 * @api Used by substrate: `Cooperative_Stop`'s idle scan.
	 */
	public function idle_since(): ?float {
		return 0 === $this->buffered ? $this->worked_at : null;
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
	 * replay round trip, so a new persistent verb needs a line added.
	 *
	 * @api Used by substrate.
	 * @return string TSL lines, newline-terminated.
	 */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters();
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
	 * Declarative schema: the `:config` verb, the TM_REQUEST verb, and the
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
			'description' => 'Writes each completed request\'s rows and rollups to a Flame_Facts Row_Table; emits flame JSONL.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'set_facts_target',
					'description' => 'Name the Row_Table, declared with the Flame_Facts schema, every flush writes its rows to: flame-facts.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'facts_target',
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'What the buffer holds and the auto-tune queue depth.',
					'reply_shape' => '{ buffered_requests, buffered_rows, last_flush_age_s, auto_tune_pending_count, significant_events_count, narration }',
					'handler'     => static fn ( self $node ): array => $node->stats_report(),
				],
			],
		];
	}
}
