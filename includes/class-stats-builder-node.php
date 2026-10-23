<?php
/**
 * Stats Builder
 *
 * The stats writer of the event logger. A Consumer tails `flames.p{N}` and
 * fills this node with one TM_STRUCT flame per completed request; the flame's
 * `request_at` names the place of the request's record in `requests.p{N}`,
 * which the node reads through a private Partition reader over the directory
 * `set_requests_partition` names. It buffers the rows the record contributes
 * to the `flame-facts` Row_Table (`Flame_Facts_Row_Schema`): its `requests`
 * row, the `urls`, `agents`, `servers`, `ja4s` and `froms` rows it names, its
 * `requests_hourly` rollup and, when timed, its `timings`, `timings_page`
 * and `timings_site` rows, which name their `categories` and `entries` rows,
 * and its page's `pages` row.
 *
 * `flush()` sends the buffer as one `ROWS` batch, one transaction, every
 * FLUSH_INTERVAL_SEC, at every checkpoint, and as soon as it holds
 * BUFFERED_REQUESTS_LIMIT requests or BUFFERED_OPS_LIMIT ops. A batch names
 * each URL and each lookup name once, and a row takes their ids by batch
 * index. Each request's rollups ride as `then` ops of its `requests` row,
 * applied only when that row inserted, so a replay of records already
 * written adds nothing.
 *
 * The builder also keeps `category_window`, each server's sums per
 * category and entry over the last `Bucket::window()`, and `urls_window`,
 * each URL's request sums and extremes over the same buckets: a request
 * adds its timings and its URL's row when its bucket is newer than the
 * windows' watermark, and every write subtracts each bucket that left the
 * windows, once per `window_expiry` row, recomputing a URL's extreme where
 * the bucket held it. The watermark is the newest bucket `window_expiry`
 * holds, read once, before the first fold. With none, or one older than
 * `timings_site` holds, the windows are rebuilt from `timings_site` and
 * `requests`; one past the windows' start, a span grown since, expires
 * nothing until the start passes it.
 *
 * A flame whose record is gone — no `request_at`, a place that does not
 * parse, or a read that finds nothing — is skipped and counted, never
 * dead-lettered: `requests.p{N}` prunes sooner than `flames.p{N}`, so a
 * replay meets many. A record whose KEY is another request's is a corrupt
 * place, and throws.
 *
 * A record is filed where it finished on its own STREAM clock, clamped to
 * the WALL tick that brought it; `idle_since()` answers the WALL moment of
 * the last write.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Idle_Reporter;
use Newspack_Nodes\Log_Position;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Client;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the flame-facts rows from the records completed requests' flames name.
 *
 * @phpstan-import-type Row_Op from \Newspack_Nodes\Sqlite_Arm
 */
class Stats_Builder_Node extends Timer_Node implements Shutdown_Sweeper, Idle_Reporter {
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;
	use Narration;

	/** Seconds between periodic flush() runs: the cadence of the Router-tick timer. */
	const FLUSH_INTERVAL_SEC = 5;

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

	/** The status classes the URL window counts, each in its own column. */
	private const WINDOW_CLASSES = [ 2, 3, 4, 5 ];

	/** The URL window's columns an expiry recomputes rather than subtracts. */
	private const URL_EXTREMES = [ 'min_ms', 'max_ms', 'max_peak_mb' ];

	/** Bound variables one statement may hold on SQLite before 3.32. */
	private const SQLITE_MAX_BINDS = 999;

	/** URLs one recompute read names: the limit less its bucket range's two. */
	private const URLS_PER_READ = self::SQLITE_MAX_BINDS - 2;

	/** Ops one rebuild batch holds at most: a fifth of BUFFERED_OPS_LIMIT. */
	private const REBUILD_OPS = self::BUFFERED_OPS_LIMIT / 5;

	/**
	 * URLs one rebuild batch covers: a URL holds a window row per worker
	 * flag, and each row takes at most a spend and a set.
	 */
	private const URLS_PER_REBUILD = self::REBUILD_OPS / 4;

	/** The predicate naming the URLs whose ids run between two binds. */
	private const URL_RANGE = 'url_id BETWEEN ? AND ?';

	/** The next `?` category window rows by key after `( ?, ?, ?, ? )`. */
	private const CATEGORY_PAGE = 'SELECT server_id, category_id, entry_id, listener, worker, ms, calls, requests, last_bucket FROM %s.category_window WHERE ( server_id, category_id, entry_id, worker ) > ( ?, ?, ?, ? ) ORDER BY server_id, category_id, entry_id, worker LIMIT ?';

	/**
	 * Server `?`'s next `?` `timings_site` groups after `( ?, ?, ?, ? )`, each
	 * summed over the buckets from `?` through `?`, read through the key.
	 */
	private const GROUP_PAGE = 'SELECT server_id, category_id, entry_id, listener, worker, TOTAL( ms ), SUM( calls ), SUM( requests ), MAX( bucket ) FROM %s.timings_site WHERE server_id = ? AND bucket BETWEEN ? AND ? AND ( category_id, entry_id, listener, worker ) > ( ?, ?, ?, ? ) GROUP BY category_id, entry_id, listener, worker ORDER BY category_id, entry_id, listener, worker LIMIT ?';

	/**
	 * Requests the buffer holds before the record reaching the count writes
	 * it, whatever the tick: a replay folds far faster than the tick, so the
	 * count is what bounds a batch and what commits a replay as it goes.
	 */
	public const BUFFERED_REQUESTS_LIMIT = 1000;

	/**
	 * Ops the buffer holds before the record reaching the count writes it:
	 * memory follows ops, about 730 bytes each, and a timed request adds
	 * four per profile category and entry, so this bounds a batch near
	 * 15 MB.
	 */
	public const BUFFERED_OPS_LIMIT = 20_000;

	/**
	 * Categories a timed request writes to the timing tables, its dearest by
	 * self time with their entries; the rest are a URL's long tail, about 5%
	 * of the time on the staging URL that forced the cut (decision 38). The
	 * request's own record keeps every one.
	 */
	public const TIMED_CATEGORIES = 100;

	/** Unix time of the last write that held a folded record, which GET_STATS reports as an age. */
	private float $worked_at = 0.0;

	/** Node NAME of the Row_Table the rows are written to ('' = unnamed). */
	private string $facts_target = '';

	/** The `requests.p{partition}` directory the flames' records are read from ('' = unnamed). */
	private string $requests_partition = '';

	/** The private reader over that directory, built on first use. */
	private ?Partition_Node $requests = null;

	/** Flames skipped because their record is gone, over the node's life. */
	private int $records_gone = 0;

	/** Whether the last flame's record was gone: a run of them warns once. */
	private bool $in_gone_run = false;

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

	/** @var array<string,int> Batch index of each page's `pages` op, by its hash. */
	private array $page_at = [];

	/** @var array<string,array<array-key,int>> Batch index of each lookup name's op, by column. */
	private array $name_at = [];

	/** The last bucket the category window has expired; null until read. */
	private ?int $window_through = null;

	/**
	 * Seed the flush clock and build the Table asker.
	 *
	 * @api Used by substrate
	 */
	public function __construct() {
		$this->worked_at = Core::$now;
		$this->client    = new Table_Client( $this );
		parent::__construct();
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
	 * verb, or a TM_STRUCT flame tailed off `flames.p{N}`.
	 *
	 * A Table's reply goes to the client first, never to the fold: it is the
	 * answer to an ask a write made, not a record.
	 *
	 * A flame's record is read and buffered as rows. A record keyed to
	 * another rid, or whose URL does not name a host, throws
	 * `InvalidArgumentException` before any of that, so the Consumer
	 * dead-letters the flame and reads on. Any other message is dropped.
	 * The flame that brings the buffer to BUFFERED_REQUESTS_LIMIT requests
	 * or BUFFERED_OPS_LIMIT ops writes it.
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
		$this->deferring( fn () => $this->fold_flame( $message ) );
	}

	/**
	 * Router-TIMER tick: flush what the buffer holds, and expire the window
	 * even when nothing waits. A failure raises here, out of the drain, and
	 * the worker exits loudly; its successor replays from the last
	 * checkpoint's cursor. A stop raised inside the flush waits for it to
	 * finish, as it does in `shutdown_sweep()`.
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
	private function fold_flame( array $message ): void {
		if ( $this->answer_request( $message ) ) {
			return;
		}
		if ( ! ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) ) {
			return;
		}
		$flame = $message[ Message::VALUE ];
		if ( ! \is_array( $flame ) ) {
			return;
		}
		// The watermark is read, and the window expired, before the first fold.
		if ( null === $this->window_through ) {
			self::stopping( 'stats window failed', $this->expire_window( ... ) );
		}
		$request_at = Core::as_string( $flame['request_at'] ?? '' );
		$place      = Log_Position::crumb( $request_at );
		$request    = $this->record_at( $place, $request_at, Core::as_string( $flame['rid'] ?? '' ) );
		if ( null === $place || null === $request ) {
			return;
		}

		[ $request, $url, $server, $rid ] = Request_Builder_Node::checked_record( $request );
		// A replayed record's row is ignored by its rid, its rollups with it.
		$this->buffer_request( $request, $rid, $place, $url, Url_Parts::hash( $url ), $server );
		if ( $this->buffered >= self::BUFFERED_REQUESTS_LIMIT || $this->ops >= self::BUFFERED_OPS_LIMIT ) {
			$this->flush();
		}
	}

	/**
	 * The request record a flame names at `$place`, or null when it is gone,
	 * which this counts: the place did not parse, `record_message()` found
	 * no record, or the record holds no request.
	 *
	 * @param array{segment:int,offset:int,length:int}|null $place      The record's place, parsed from `$request_at`.
	 * @param string                                        $request_at The record's ID, `segment:offset:length`.
	 * @param string                                        $rid        The flame's rid, which the record's KEY must be.
	 * @return array<array-key,mixed>|null The record's VALUE.
	 * @throws \InvalidArgumentException When the record is keyed to another rid.
	 */
	private function record_at( ?array $place, string $request_at, string $rid ): ?array {
		$record = null === $place ? null : $this->record_message( $place );
		if ( null !== $record && $rid !== Core::as_string( $record[ Message::KEY ] ) ) {
			throw new \InvalidArgumentException( \esc_html( "Stats_Builder: the record at {$request_at} is " . Core::as_string( $record[ Message::KEY ] ) . "'s, not {$rid}'s" ) );
		}
		$request = $record[ Message::VALUE ] ?? null;
		if ( \is_array( $request ) ) {
			$this->in_gone_run = false;
			return $request;
		}
		++$this->records_gone;
		$this->tally( Flame_Tree::STATS_WRITES, 'records gone' );
		if ( ! $this->in_gone_run ) {
			$this->in_gone_run = true;
			Core::print_less_often( 'Stats_Builder: flames whose records are gone are skipped', " from rid {$rid}" );
		}
		return null;
	}

	/**
	 * The message at `$place` in the requests log, or null when it is gone:
	 * its segment pruned, the place past the segment's end, or bytes that do
	 * not unpack.
	 *
	 * @param array{segment:int,offset:int,length:int} $place The record's place.
	 * @return array<int,mixed>|null The unpacked message.
	 * @throws Worker_Should_Stop When the segment holds the place but will not read, so the flame replays.
	 */
	private function record_message( array $place ): ?array {
		$reader = $this->requests();
		$bytes  = self::stopping(
			'stats read failed',
			static function () use ( $reader, $place ): string {
				$bytes   = $reader->read_at( $place['segment'], $place['offset'], $place['length'] );
				$segment = $reader->get_segment_path( $place['segment'] );
				\clearstatcache( true, $segment );
				if ( '' === $bytes && \is_file( $segment ) && \filesize( $segment ) >= $place['offset'] + $place['length'] ) {
					throw new \RuntimeException( \esc_html( "Stats_Builder: {$segment} holds the record but would not read" ) );
				}
				return $bytes;
			}
		);
		try {
			return '' === $bytes ? null : Message::unpacked( $bytes );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * The private reader over the `requests.p{partition}` directory, built
	 * on first use and never named, so no other node can reach it.
	 *
	 * @throws Worker_Should_Stop When no directory is named, it does not exist, or it will not open, so the flame replays.
	 */
	private function requests(): Partition_Node {
		return $this->requests ??= self::stopping(
			'stats read failed',
			function (): Partition_Node {
				if ( '' === $this->requests_partition ) {
					throw new \LogicException( 'Stats_Builder: no requests partition named by set_requests_partition' );
				}
				$reader = new Partition_Node();
				$reader->arguments( [ $this->requests_partition ] );
				// A flame follows its record: a missing directory is misnamed.
				if ( ! \is_dir( $reader->partition_dir() ) ) {
					throw new \LogicException( 'Stats_Builder: no requests partition at ' . $reader->partition_dir() );
				}
				return $reader;
			}
		);
	}

	/**
	 * Buffer one completed request's ops: an `ignore` for its server, its URL
	 * and, when it carries each, its user agent, JA4 and From, and, when it is
	 * timed, for each profile category, entry and owner, each the first time
	 * the batch meets it, whose ids the rows take by batch index; then the
	 * request's `ignore`, whose `then` ops add its hourly rollup and, when it
	 * is timed, a timing row per profile category and entry for the URL, its
	 * page and the site, an owner row per owner the record measured,
	 * `Request_Builder_Node::owners_of()`, for the URL and for the site, and
	 * its share of the category window and of its URL's window row.
	 *
	 * Filed where it COMPLETED (decision 19): its start plus its duration,
	 * clamped to the tick, since a request cannot have finished after it
	 * reached the builder and a reader walks back from the wall. A timeout's
	 * or an abort's duration is not a sample (decision 24), so its row times
	 * nothing and it does not write a timing row; workers write their timings
	 * too, under `worker` 1, which a site-wide reader leaves out.
	 *
	 * @param array<array-key,mixed> $request  The record, its method labelled.
	 * @param string                 $rid      Its request id.
	 * @param array{segment:int,offset:int,length:int} $place The record's place in `requests.p{N}`.
	 * @param string                 $url      The request's URL.
	 * @param string                 $url_hash The hash of its identity, `Url_Parts::hash()`.
	 * @param string                 $server   Its host.
	 * @throws \LogicException When no partition is bound, so a row can not name one.
	 */
	private function buffer_request( array $request, string $rid, array $place, string $url, string $url_hash, string $server ): void {
		$partition = Core::bound_partition() ?? throw new \LogicException( 'Stats_Builder: no partition is bound, so a request row can not name one' );
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
		$ja4       = self::named( $request['ja4_hash'] ?? null );
		$from      = self::named( $request['http_from'] ?? null );
		$peak      = \max( 0.0, Core::num_float( $request['peak_mb'] ?? 0 ) );
		$ms        = $timed ? $duration : 0.0;

		$server_at = $this->named_at( 'server_id', $server );
		$url_at    = $this->url_at[ $url_hash ] ??= $this->op( [ 'ignore', 'urls', self::url_row( $url, $server ), [], self::ids_at( 'urls', [ 'server_id' => $server_at ] ) ] );
		$ids       = self::ids_at(
			'requests',
			[
				'server_id' => $server_at,
				'url_id'    => $url_at,
				'ua_id'     => $this->named_at( 'ua_id', $ua ),
				'ja4_id'    => $this->named_at( 'ja4_id', $ja4 ),
				'from_id'   => $this->named_at( 'from_id', $from ),
			]
		);
		$then      = [
			[ 'add', 'requests_hourly', [ Bucket::hour( $finished ), null, self::status_class( $status ), $method, $worker, $country ?? '', 1, $timed ? 1 : 0, $ms, $ms, $peak, self::error_counts( $error ) ? 1 : 0 ], [], self::ids_at( 'requests_hourly', [ 'server_id' => $server_at ] ) ],
		];
		$profiles  = $timed ? Core::arr( $request['profiles'] ?? null ) : [];
		$timings   = self::profile_rows( $profiles );
		$windowed  = $bucket > $this->window_through;
		$site      = [ 'server_id' => $server_at ];
		$by_url    = $site + [ 'url_id' => $url_at ];
		// A page no timing row names would never be released by a purge.
		$by_page = [] === $timings ? $site : $site + [ 'page_id' => $this->page_op_at( $url_at ) ];
		foreach ( $timings as $category => $entries ) {
			$category_at = [ 'category_id' => $this->named_at( 'category_id', $category ) ];
			foreach ( $entries as [ $entry, $listener, $time, $calls ] ) {
				$named  = $category_at + [ 'entry_id' => $this->named_at( 'entry_id', $entry ) ];
				$then[] = [ 'add', 'timings', [ $bucket, null, null, null, null, $listener, $time, $calls, 1 ], [], self::ids_at( 'timings', $named + $by_url ) ];
				$then[] = [ 'add', 'timings_page', [ $bucket, null, null, null, null, $listener, $time, $calls, 1 ], [], self::ids_at( 'timings_page', $named + $by_page ) ];
				$then[] = [ 'add', 'timings_site', [ $bucket, null, $worker, null, null, $listener, $time, $calls, 1 ], [], self::ids_at( 'timings_site', $named + $site ) ];
				if ( $windowed ) {
					$then[] = [ ...self::window_op( [ null, null, null, $listener, $worker, $time, $calls, 1 ], 1, $bucket ), [], self::ids_at( 'category_window', $named + $site ) ];
				}
			}
		}
		foreach ( $timed ? Request_Builder_Node::owners_of( $request ) : [] as [ $owner, $owner_ms, $calls ] ) {
			$named  = [ 'owner_id' => $this->named_at( 'owner_id', $owner ) ];
			$then[] = [ 'add', 'owner_timings', [ $bucket, null, null, null, $owner_ms, $calls, 1 ], [], self::ids_at( 'owner_timings', $named + $by_url ) ];
			$then[] = [ 'add', 'owner_timings_site', [ $bucket, null, $worker, null, $owner_ms, $calls, 1 ], [], self::ids_at( 'owner_timings_site', $named + $site ) ];
		}
		if ( $windowed ) {
			$class  = self::status_class( $status );
			$sample = $timed ? $duration : null;
			$then[] = [ ...self::url_window_op( [ null, $worker, 1, $timed ? 1 : 0, ...\array_map( static fn ( int $counted ): int => $counted === $class ? 1 : 0, self::WINDOW_CLASSES ), self::error_counts( $error ) ? 1 : 0, $ms, $peak, $sample, $sample, $peak, (float) $finished ], 1, $bucket ), [], self::ids_at( 'urls_window', [ 'url_id' => $url_at ] ) ];
		}
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
					$place['segment'],
					$place['offset'],
					$place['length'],
					null,
					null,
					$status,
					$method,
					$country,
					null,
					null,
					null,
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
	 * The batch index of the `ignore` filing `$name` in the lookup table
	 * `Flame_Facts_Row_Schema::NAMED_BY` names for `$column`, appended the
	 * first time the batch names it; null for no name.
	 *
	 * @param string      $column A column naming a lookup row by its id.
	 * @param string|null $name   The name, exactly as the record carries it.
	 */
	private function named_at( string $column, ?string $name ): ?int {
		if ( null === $name ) {
			return null;
		}
		return $this->name_at[ $column ][ $name ] ??= $this->op( [ 'ignore', Flame_Facts_Row_Schema::NAMED_BY[ $column ], [ null, $name ] ] );
	}

	/**
	 * The batch index of the `ignore` filing in `pages` the page the `urls`
	 * op at `$url_at` names, appended the first time the batch names it.
	 *
	 * @param int $url_at The batch index of the request's `urls` op.
	 */
	private function page_op_at( int $url_at ): int {
		$url  = Core::arr( $this->rows[ $url_at ][2] );
		$page = Core::as_string( $url[ Flame_Facts_Row_Schema::position( 'urls', 'page' ) ] );
		$hash = Core::as_string( $url[ Flame_Facts_Row_Schema::position( 'urls', 'page_hash' ) ] );
		return $this->page_at[ $hash ] ??= $this->op( [ 'ignore', 'pages', [ null, $page, $hash ] ] );
	}

	/**
	 * Append one op to the batch and count it, with its `then` ops.
	 *
	 * @param Row_Op $op The op.
	 * @return int Its batch index, which a later op's `ids` names.
	 */
	private function op( array $op ): int {
		$this->rows[] = $op;
		$this->ops   += 1 + \count( $op[3] ?? [] );
		return \count( $this->rows ) - 1;
	}

	/**
	 * An op's `ids` map: each column's position in `$table` to the batch
	 * index whose id fills it, a column naming no op can not be left null.
	 *
	 * @param string                  $table  The table the op writes.
	 * @param array<string,int|null> $columns Batch indexes by column.
	 * @return array<int,int>
	 */
	private static function ids_at( string $table, array $columns ): array {
		$ids = [];
		foreach ( $columns as $column => $at ) {
			if ( null !== $at ) {
				$ids[ Flame_Facts_Row_Schema::position( $table, $column ) ] = $at;
			}
		}
		return $ids;
	}

	/**
	 * The positional values of a `urls` row, in the schema's column order:
	 * the id the table assigns, the hash of the URL's identity, the server id
	 * the caller fills, the stored path, the page and the page's hash.
	 *
	 * @api FactsSeeder, so a seeded row is the row this builder writes.
	 * @param string $url    The request's URL.
	 * @param string $server Its host.
	 * @return list<int|string|null>
	 */
	public static function url_row( string $url, string $server ): array {
		$page = Url_Parts::page( $url );
		return [ null, Url_Parts::hash( $url ), null, Url_Parts::row_path( $url, $server ), $page, Log_Manager::url_hash( $page ) ];
	}

	/**
	 * A record field as a row stores it: its string, or null when it is empty
	 * or cannot be represented as a scalar, so a reader spells every absence one way.
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
	 * A timed request's profile as timing rows, by category: each of its
	 * `TIMED_CATEGORIES` dearest categories by self time, ties by name, with
	 * its own row, entry '', and one per entry, each saying whether the
	 * category is a listener span, whose time already counts inside its
	 * hook's.
	 *
	 * @param array<array-key,mixed> $profiles `profiles{}` from the record.
	 * @return array<string,list<array{0:string,1:int,2:float,3:int}>> Category => entry, listener, ms, calls.
	 */
	private static function profile_rows( array $profiles ): array {
		$times = [];
		foreach ( $profiles as $category => $data ) {
			if ( \is_string( $category ) && \is_array( $data ) ) {
				$times[ $category ] = Core::num_float( $data['time'] ?? 0 );
			}
		}
		$names = \array_keys( $times );
		\array_multisort( \array_values( $times ), \SORT_DESC, \SORT_NUMERIC, $names, \SORT_ASC, \SORT_STRING );
		$rows = [];
		foreach ( \array_slice( $names, 0, self::TIMED_CATEGORIES ) as $category ) {
			$data = Core::arr( $profiles[ $category ] );
			// The column flags an overlay: its time is already its parent's.
			$listener            = Flame_Tree::is_overlay_span( $category ) ? 1 : 0;
			$rows[ $category ][] = [ '', $listener, $times[ $category ], Core::num_int( $data['count'] ?? 0 ) ];
			foreach ( Core::arr( $data['entries'] ?? null ) as $name => $entry ) {
				$entry               = Core::arr( $entry );
				$rows[ $category ][] = [ (string) $name, $listener, Core::num_float( $entry[0] ?? 0 ), Core::num_int( $entry[1] ?? 0 ) ];
			}
		}
		return $rows;
	}

	/**
	 * Write the buffer before the Consumer commits its cursor; the checkpoint
	 * carries nothing. A record the cursor replays is counted once by its
	 * `requests` row's rid (decision 31), and an expiry it replays once by
	 * its `window_expiry` row. A write that fails raises, and the cursor
	 * commits nothing past the records it held. In crawl mode the Consumer
	 * checkpoints every message, so this runs per message until
	 * `CHECKPOINT_INTERVAL_S` of crash-free progress ends the crawl.
	 *
	 * @api Used by substrate.
	 * @return array{}
	 */
	public function save_state(): array {
		$this->flush();
		return [];
	}

	/**
	 * Flush on a clean stop. The substrate runs the sweep before the cursor
	 * handoff, while the graph is intact.
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
					// The last minute's writes would otherwise go untold.
					$this->tell_writes();
				},
				fn (): array => [ 'stopped', [] ]
			)
		);
	}

	/**
	 * Send the buffer as one `ROWS` batch to the Row_Table `set_facts_target`
	 * named, one transaction, then expire the window, and start clean.
	 *
	 * `fire()` calls this once per FLUSH_INTERVAL_SEC, every checkpoint runs
	 * it before its cursor commits, and every clean stop through
	 * `shutdown_sweep()`, so nothing the cursor has passed is left
	 * unwritten; an empty buffer still expires, so an idle tick moves the
	 * window too.
	 *
	 * A refused or unanswered batch, or any other failure, a stop included,
	 * raises a plain stop carrying it, and the buffer stays: the Consumer
	 * neither dead-letters the record in hand nor commits past it, and the
	 * successor replays every record the batch held.
	 *
	 * @throws Worker_Should_Stop Carrying whatever failed the write (ADR-14).
	 */
	public function flush(): void {
		$held = [] !== $this->rows;
		self::stopping(
			'stats write failed',
			function () use ( $held ): void {
				if ( $held ) {
					$this->client->write_rows( $this->facts_table(), $this->rows );
				}
				$this->expire_window();
			}
		);
		if ( $held ) {
			$this->tally( Flame_Tree::STATS_WRITES, 'flushes' );
			$this->tally( Flame_Tree::STATS_WRITES, 'requests', $this->buffered );
			$this->rows      = [];
			$this->buffered  = 0;
			$this->ops       = 0;
			$this->url_at    = [];
			$this->page_at   = [];
			$this->name_at   = [];
			$this->worked_at = Core::$now;
		}
		// Once a minute, not once a flush; gone records alone count.
		if ( isset( $this->tally[ Flame_Tree::STATS_WRITES ] ) && $this->rollup_due( (int) Core::$now ) ) {
			$this->tell_writes();
		}
	}

	/**
	 * What `$body` answers, raising whatever it throws as a plain stop that
	 * says `$why` and carries it, so the record in hand replays.
	 *
	 * @template T
	 * @param string        $why  What failed: `stats <x> failed`.
	 * @param \Closure(): T $body The work.
	 * @return T
	 * @throws Worker_Should_Stop Carrying whatever `$body` threw.
	 */
	private static function stopping( string $why, \Closure $body ): mixed {
		try {
			return $body();
		} catch ( \Throwable $e ) {
			throw Worker_Should_Stop::combine( [ new Worker_Should_Stop( $why ), $e ] );
		}
	}

	/**
	 * Move the windows to end at the current bucket: subtract each bucket
	 * that left them in one ROWS batch, or rebuild them from `timings_site`
	 * and `requests`, `window_rebuild()`, when the file holds no watermark or
	 * `timings_site` may no longer hold the gap. A watermark at or past the
	 * windows' start, a span grown since they were kept, expires nothing
	 * until the start passes it. The last batch's `window_expiry` rows carry
	 * the new watermark; a refusal raises and the watermark stays.
	 *
	 * @throws \RuntimeException When `min_lifetime` is short of one bucket.
	 */
	private function expire_window(): void {
		$now     = Bucket::id( (int) Core::$now );
		$through = $now - Bucket::window();
		if ( null === $this->window_through ) {
			$this->window_through = self::watermark( $this->own_reader() );
		}
		if ( null !== $this->window_through && $this->window_through >= $through ) {
			return;
		}
		if ( null === $this->window_through || $this->window_through < $through - Bucket::RETAIN_SLACK ) {
			$this->window_rebuild( $through );
		} else {
			$this->write_read( fn ( Row_Reader $reader ): array => self::window_expiries( $reader, $this->window_through + 1, $through ) );
		}
		$this->window_through = $through;
	}

	/**
	 * The newest bucket this partition's file has expired; null for none.
	 *
	 * @param Row_Reader $reader The file's reader, from `own_reader()`.
	 */
	private static function watermark( Row_Reader $reader ): ?int {
		$newest = self::own_rows( $reader, Flame_Facts_Row_Schema::WATERMARK_QUERY, [] )[0][0] ?? null;
		return null === $newest ? null : Core::as_int( $newest );
	}

	/**
	 * One `ignore` per bucket from `$first` through `$last`, each carrying as
	 * `then` ops the negation of that bucket's `timings_site` sums per server
	 * and its URL window ops, `url_expiries()`, so a replayed expiry
	 * subtracts nothing twice.
	 *
	 * @param Row_Reader $reader The file's reader, from `own_reader()`.
	 * @param int        $first  The first bucket to expire.
	 * @param int        $last   The last.
	 * @return list<Row_Op>
	 */
	private static function window_expiries( Row_Reader $reader, int $first, int $last ): array {
		$sums = self::own_rows(
			$reader,
			'SELECT bucket, server_id, category_id, entry_id, listener, worker, TOTAL( ms ), SUM( calls ), SUM( requests ) FROM %s.timings_site WHERE bucket BETWEEN ? AND ? GROUP BY bucket, server_id, category_id, entry_id, listener, worker',
			[ $first, $last ]
		);
		$then = \array_fill_keys( \range( $first, $last ), [] );
		foreach ( $sums as $row ) {
			$bucket            = Core::as_int( $row[0] );
			$then[ $bucket ][] = self::window_op( \array_slice( $row, 1 ), -1, $bucket );
		}
		foreach ( self::url_expiries( $reader, $first, $last ) as $bucket => $ops ) {
			\array_push( $then[ $bucket ], ...$ops );
		}
		$batch = [];
		foreach ( $then as $bucket => $negations ) {
			$batch[] = [ 'ignore', 'window_expiry', [ $bucket ], $negations ];
		}
		return $batch;
	}

	/**
	 * Each bucket's URL window ops, from one read of `requests` over the
	 * buckets: per URL and worker flag, the negation of the bucket's sums,
	 * then, where the bucket held the row's stored minimum, maximum or peak,
	 * a `set` of the whole row from the requests after it,
	 * `url_window_sets()`, spent where none remains. The buckets run oldest
	 * first, each comparing the extremes the last one left.
	 *
	 * @param Row_Reader $reader The file's reader, from `own_reader()`.
	 * @param int        $first  The first bucket to expire.
	 * @param int        $last   The last.
	 * @return array<int,list<Row_Op>> By bucket.
	 */
	private static function url_expiries( Row_Reader $reader, int $first, int $last ): array {
		$by_bucket = [];
		foreach ( self::own_rows( $reader, self::url_expiry_sql(), [ $first, $last ] ) as $row ) {
			$by_bucket[ Core::as_int( $row[0] ) ][] = $row;
		}
		\ksort( $by_bucket );
		$width = \count( Flame_Facts_Row_Schema::tables()['urls_window']['columns'] );
		$at    = Flame_Facts_Row_Schema::position( 'urls_window', self::URL_EXTREMES[0] );
		$seen  = Flame_Facts_Row_Schema::position( 'urls_window', 'last_seen' );
		$held  = [];
		$ops   = [];
		foreach ( $by_bucket as $bucket => $read ) {
			$moved = [];
			foreach ( $read as $row ) {
				// The bucket, its sums, then the stored extremes.
				$sums             = \array_slice( $row, 1, $width - 1 );
				$key              = self::url_key( $sums );
				$ops[ $bucket ][] = self::url_window_op( $sums, -1, $bucket );
				$held[ $key ]   ??= \array_slice( $row, $width );
				foreach ( $held[ $key ] as $i => $extreme ) {
					if ( null !== $extreme && null !== $sums[ $at + $i ] && Core::num_float( $extreme ) === Core::num_float( $sums[ $at + $i ] ) ) {
						$moved[ $key ] = $sums;
					}
				}
			}
			$sets = [];
			foreach ( \array_chunk( \array_values( \array_unique( \array_map( Core::as_int( ... ), \array_column( $moved, 0 ) ) ) ), self::URLS_PER_READ ) as $chunk ) {
				$sets += self::url_window_sets( $reader, self::urls_named( \count( $chunk ) ), $chunk, $bucket );
			}
			foreach ( $moved as $key => $sums ) {
				$set              = $sets[ $key ] ?? self::url_window_spent( $sums[0], $sums[1], $sums[ $seen ], $bucket );
				$ops[ $bucket ][] = $set;
				$held[ $key ]     = \array_slice( $set[2], $at, \count( self::URL_EXTREMES ) );
			}
		}
		return $ops;
	}

	/**
	 * Per bucket, URL and worker flag from `?` through `?`, the bucket's
	 * `url_sums()` and the extremes the `urls_window` row stores, read
	 * through `requests_cover`, which leads with the bucket.
	 */
	private static function url_expiry_sql(): string {
		return 'SELECT g.*, ' . \implode( ', ', \array_map( static fn ( string $column ): string => "w.{$column}", self::URL_EXTREMES ) ) . ' FROM ( SELECT bucket, '
			. self::url_sums()
			. ' FROM %1$s.requests WHERE bucket BETWEEN ? AND ? GROUP BY bucket, worker, url_id ) AS g LEFT JOIN %1$s.urls_window AS w ON w.url_id = g.url_id AND w.worker = g.worker';
	}

	/**
	 * Rebuild both windows exactly, whatever they held and whatever retention
	 * purged first, in ROWS batches of at most REBUILD_OPS ops written in
	 * turn, each from one read: the category window rows negated, then
	 * `timings_site` summed over every bucket after `$through` added, then
	 * the URL window, URLS_PER_REBUILD URLs at a time. The last batch marks
	 * the `RETAIN_SLACK` buckets ending at `$through` expired, the last of
	 * them the watermark, so a reader falls back until the windows are
	 * whole, and a rebuild stopped between batches runs again whole. Every
	 * bucket after `$through` counts, one a clock stepped back from
	 * included, as the adds counted it.
	 *
	 * @param int $through The bucket the rebuilt windows start after.
	 */
	private function window_rebuild( int $through ): void {
		$this->paged( self::category_spends( ... ), [ -1, -1, -1, -1 ] );
		$this->paged( static fn ( Row_Reader $reader, array $after ): array => self::category_sums( $reader, $after, $through ), [ 0, -1, -1, -1, -1 ] );
		$this->paged( static fn ( Row_Reader $reader, array $after ): array => self::url_rebuild( $reader, $after, $through ), [ 0 ] );
	}

	/**
	 * Write each batch `$page` builds, from the cursor `$after` on, until it
	 * answers no next cursor.
	 *
	 * @param \Closure(Row_Reader, list<int>): array{0:list<Row_Op>, 1:list<int>|null} $page  A batch and the cursor after it.
	 * @param list<int>                                                               $after The first cursor.
	 */
	private function paged( \Closure $page, array $after ): void {
		do {
			$next = null;
			$this->write_read(
				static function ( Row_Reader $reader ) use ( $page, $after, &$next ): array {
					[ $batch, $next ] = $page( $reader, $after );
					return $batch;
				}
			);
			$after = $next;
		} while ( null !== $after );
	}

	/**
	 * Write the batch `$read` builds from a fresh reader, the reader freed
	 * first: SQLite keeps a file a write locks open for reuse.
	 *
	 * @param \Closure(Row_Reader): list<Row_Op> $read Builds the batch.
	 */
	private function write_read( \Closure $read ): void {
		$batch = $read( $this->own_reader() );
		$this->client->write_rows( $this->facts_table(), $batch );
	}

	/**
	 * The negation of the next REBUILD_OPS category window rows by key
	 * after `$after`, each keeping its own `last_bucket`, and the last key
	 * read; none when fewer remain.
	 *
	 * @param Row_Reader $reader The file's reader, from `own_reader()`.
	 * @param list<int>  $after  The last key read: server, category, entry and worker ids.
	 * @return array{0:list<Row_Op>, 1:list<int>|null}
	 */
	private static function category_spends( Row_Reader $reader, array $after ): array {
		$rows = self::own_rows( $reader, self::CATEGORY_PAGE, [ ...$after, self::REBUILD_OPS ] );
		$last = \end( $rows );
		return [
			\array_map( static fn ( array $row ): array => self::window_op( $row, -1, Core::as_int( $row[8] ) ), $rows ),
			\count( $rows ) < self::REBUILD_OPS || false === $last ? null : \array_map( Core::as_int( ... ), [ $last[0], $last[1], $last[2], $last[4] ] ),
		];
	}

	/**
	 * The next REBUILD_OPS of one server's `timings_site` groups after
	 * `$after`, each summed over every bucket after `$through`, as adds,
	 * and the cursor after them: the last group read, or the next server's
	 * first once the server is done; none after the last server.
	 *
	 * @param Row_Reader $reader  The file's reader, from `own_reader()`.
	 * @param list<int>  $after   The server id, then the last category, entry, listener and worker ids read.
	 * @param int        $through The bucket the rebuilt window starts after.
	 * @return array{0:list<Row_Op>, 1:list<int>|null}
	 */
	private static function category_sums( Row_Reader $reader, array $after, int $through ): array {
		[ $server ] = $after;
		$rows       = self::own_rows( $reader, self::GROUP_PAGE, [ $server, $through + 1, \PHP_INT_MAX, ...\array_slice( $after, 1 ), self::REBUILD_OPS ] );
		$last = \end( $rows );
		if ( \count( $rows ) < self::REBUILD_OPS || false === $last ) {
			$next = self::own_rows( $reader, 'SELECT id FROM %s.servers WHERE id > ? ORDER BY id LIMIT 1', [ $server ] )[0][0] ?? null;
			$last = null === $next ? null : [ $next, -1, -1, -1, -1 ];
		}
		return [
			\array_map( static fn ( array $row ): array => self::window_op( $row, 1, Core::as_int( $row[8] ) ), $rows ),
			null === $last ? null : \array_map( Core::as_int( ... ), \array_slice( $last, 0, 5 ) ),
		];
	}

	/**
	 * The URL window rows of the next URLS_PER_REBUILD URLs by id after
	 * `$after`, rebuilt, and the last id they reach; none once they reach the
	 * last URL, when the batch also marks the windows expired through
	 * `$through`. Each row last moved at or before `$through` is spent, so a
	 * revived URL's next request adds onto nothing, where a negation could
	 * not clear its extremes, then each row the requests after `$through`
	 * name is `set` whole from them.
	 *
	 * @param Row_Reader $reader  The file's reader, from `own_reader()`.
	 * @param list<int>  $after   The last URL id an earlier batch covered.
	 * @param int        $through The bucket the rebuilt window starts after.
	 * @return array{0:list<Row_Op>, 1:list<int>|null}
	 */
	private static function url_rebuild( Row_Reader $reader, array $after, int $through ): array {
		$id    = self::own_rows( $reader, 'SELECT id FROM %s.urls WHERE id > ? ORDER BY id LIMIT 1 OFFSET ?', [ $after[0], self::URLS_PER_REBUILD - 1 ] )[0][0] ?? null;
		$range = [ $after[0] + 1, null === $id ? \PHP_INT_MAX : Core::as_int( $id ) ];
		$batch = [];
		foreach ( self::own_rows( $reader, 'SELECT url_id, worker, last_seen, last_bucket FROM %s.urls_window WHERE ' . self::URL_RANGE . ' AND last_bucket <= ?', [ ...$range, $through ] ) as [ $url, $worker, $seen, $bucket ] ) {
			$batch[] = self::url_window_spent( $url, $worker, $seen, Core::as_int( $bucket ) );
		}
		\array_push( $batch, ...\array_values( self::url_window_sets( $reader, self::URL_RANGE, $range, $through ) ) );
		foreach ( null === $id ? \range( $through - Bucket::RETAIN_SLACK + 1, $through ) : [] as $bucket ) {
			$batch[] = [ 'ignore', 'window_expiry', [ $bucket ] ];
		}
		return [ $batch, null === $id ? null : [ $range[1] ] ];
	}

	/**
	 * A `set` of each URL window row `requests` names in any bucket after
	 * `$after`, by `url_id worker`, read whole, for the URLs `$urls` names.
	 *
	 * @param Row_Reader $reader The file's reader, from `own_reader()`.
	 * @param string     $urls   The predicate naming the URLs.
	 * @param list<int>  $binds  Its binds.
	 * @param int        $after  The bucket the requests are read after.
	 * @return array<string,Row_Op>
	 */
	private static function url_window_sets( Row_Reader $reader, string $urls, array $binds, int $after ): array {
		$sets = [];
		foreach ( self::own_rows( $reader, self::url_window_sql( $urls ), [ ...$binds, $after + 1, \PHP_INT_MAX ] ) as $row ) {
			$sets[ self::url_key( $row ) ] = [ 'set', 'urls_window', self::url_window_op( \array_slice( $row, 0, -1 ), 1, Core::as_int( \end( $row ) ) )[2] ];
		}
		return $sets;
	}

	/**
	 * A URL window row's key, `url_id worker`, from a row led by both.
	 *
	 * @param list<mixed> $row The row.
	 */
	private static function url_key( array $row ): string {
		return Core::as_string( $row[0] ) . ' ' . Core::as_string( $row[1] );
	}

	/**
	 * A `set` of a URL window row with nothing left in it: no counts, no
	 * sums, no extremes, its last moments kept.
	 *
	 * @param mixed $url    Its URL id.
	 * @param mixed $worker Its worker flag.
	 * @param mixed $seen   Its last finish.
	 * @param int   $last   Its last bucket.
	 * @return Row_Op
	 */
	private static function url_window_spent( mixed $url, mixed $worker, mixed $seen, int $last ): array {
		return [ 'set', 'urls_window', self::url_window_op( [ $url, $worker, 0, 0, 0, 0, 0, 0, 0, 0.0, 0.0, null, null, null, $seen ], 1, $last )[2] ];
	}

	/**
	 * Per URL and worker flag, the `url_sums()` and newest bucket of the
	 * requests from `?` through `?` of the URLs `$urls` names, its binds
	 * first, through `requests_url`. Both bucket bounds earn the seek; a
	 * caller wanting every later bucket binds `PHP_INT_MAX`.
	 *
	 * @param string $urls The predicate naming the URLs: `urls_named()`, or URL_RANGE.
	 */
	private static function url_window_sql( string $urls ): string {
		return 'SELECT ' . self::url_sums() . ", MAX( bucket ) FROM %1\$s.requests WHERE {$urls} AND bucket BETWEEN ? AND ? GROUP BY url_id, worker";
	}

	/**
	 * The predicate naming `$count` URLs by id, one bind each.
	 *
	 * @param int $count URLs it names.
	 */
	private static function urls_named( int $count ): string {
		return 'url_id IN ( ' . \implode( ', ', \array_fill( 0, $count, '?' ) ) . ' )';
	}

	/**
	 * A `requests` read's URL window sums per URL and worker flag, in
	 * `url_window_op()`'s order: count, timed, the status classes, errors,
	 * the summed ms and peak, the timed extremes, the peak and the last
	 * finish.
	 */
	private static function url_sums(): string {
		$classes = \implode( ', ', \array_map( static fn ( int $class ): string => "COUNT( CASE WHEN status / 100 = {$class} THEN 1 END )", self::WINDOW_CLASSES ) );
		$errors  = "'" . \implode( "', '", self::ERROR_KINDS ) . "'";
		return "url_id, worker, COUNT( * ), COUNT( ms ), {$classes}, COUNT( CASE WHEN error IN ( {$errors} ) THEN 1 END ), TOTAL( ms ), TOTAL( peak_mb ), MIN( ms ), MAX( ms ), MAX( peak_mb ), MAX( finished_at )";
	}

	/**
	 * A `category_window` add of one server's sums for a category and entry,
	 * added at `$sign` 1 and subtracted at -1, its `last_bucket` `$last`. The
	 * op's `ids` map fills any null id.
	 *
	 * @param list<mixed> $sums Server id, category id, entry id, listener, worker, ms, calls, requests.
	 * @param int         $sign 1 or -1.
	 * @param int         $last The bucket the row last moved in.
	 * @return Row_Op
	 */
	private static function window_op( array $sums, int $sign, int $last ): array {
		[ $server, $category, $entry, $listener, $worker, $ms, $calls, $requests ] = $sums;
		return [ 'add', 'category_window', [ null === $server ? null : Core::as_int( $server ), null === $category ? null : Core::as_int( $category ), null === $entry ? null : Core::as_int( $entry ), Core::as_int( $listener ), Core::as_int( $worker ), $sign * Core::num_float( $ms ), $sign * Core::as_int( $calls ), $sign * Core::as_int( $requests ), $last ] ];
	}

	/**
	 * A `urls_window` add of one URL's sums for a worker flag, added at
	 * `$sign` 1 and subtracted at -1, its `last_bucket` `$last`. A
	 * subtraction carries no extremes, which the null-safe folds leave as
	 * stored. The op's `ids` map fills a null URL id.
	 *
	 * @param list<mixed> $sums URL id, worker, count, timed, the 2xx to 5xx counts, errors, ms, peak, min ms, max ms, max peak, last finish.
	 * @param int         $sign 1 or -1.
	 * @param int         $last The bucket the row last moved in.
	 * @return Row_Op
	 */
	private static function url_window_op( array $sums, int $sign, int $last ): array {
		[ $url, $worker, $count, $timed, $count_2xx, $count_3xx, $count_4xx, $count_5xx, $errors, $ms, $peak, $min, $max, $max_peak, $seen ] = $sums;
		$extreme = static fn ( mixed $value ): ?float => $sign < 0 || null === $value ? null : Core::num_float( $value );
		return [ 'add', 'urls_window', [ null === $url ? null : Core::as_int( $url ), Core::as_int( $worker ), $sign * Core::as_int( $count ), $sign * Core::as_int( $timed ), $sign * Core::as_int( $count_2xx ), $sign * Core::as_int( $count_3xx ), $sign * Core::as_int( $count_4xx ), $sign * Core::as_int( $count_5xx ), $sign * Core::as_int( $errors ), $sign * Core::num_float( $ms ), $sign * Core::num_float( $peak ), $extreme( $min ), $extreme( $max ), $extreme( $max_peak ), Core::num_float( $seen ), $last ] ];
	}

	/**
	 * One reader over this partition's file of the Row_Table
	 * `set_facts_target` named, which attaches nothing before the file exists.
	 *
	 * @throws \LogicException When no partition is bound, or no table is named.
	 * @throws \PDOException When the file will not open.
	 */
	private function own_reader(): Row_Reader {
		$partition = Core::bound_partition() ?? throw new \LogicException( 'Stats_Builder: no partition is bound, so no window can be read' );
		return Sqlite_Arm::open_rows( [ $partition => Table_Node::file( $this->facts_table(), $partition ) ], Flame_Facts_Row_Schema::version() );
	}

	/**
	 * Rows of a statement over the file `own_reader()` attached, its alias
	 * the `%s`; none when it attached none.
	 *
	 * @param Row_Reader        $reader The reader.
	 * @param string            $sql    The statement.
	 * @param list<scalar|null> $binds  Its binds.
	 * @return list<list<mixed>>
	 */
	private static function own_rows( Row_Reader $reader, string $sql, array $binds ): array {
		$alias = $reader->aliases()[0] ?? null;
		return null === $alias ? [] : $reader->rows( \sprintf( $sql, $alias ), $binds );
	}

	/**
	 * The Row_Table `set_facts_target` named, which every write needs.
	 *
	 * @throws \LogicException When the verb named none.
	 */
	private function facts_table(): string {
		return '' === $this->facts_target ? throw new \LogicException( 'Stats_Builder: no Row_Table named by set_facts_target' ) : $this->facts_target;
	}

	/**
	 * Tell `stats writes`: what the flushes since the last line wrote, the
	 * flushes and requests first.
	 */
	private function tell_writes(): void {
		$this->rollup( Flame_Tree::STATS_WRITES, [ 'flushes', 'requests' ] );
	}

	/**
	 * The `GET_STATS` reply data, which `answer_request()` sends back: what
	 * the buffer holds and the flames skipped for a gone record.
	 *
	 * @return array<string,mixed>
	 */
	private function stats_report(): array {
		return [
			'buffered_requests' => $this->buffered,
			'buffered_rows'     => \count( $this->rows ),
			'last_flush_age_s'  => $this->worked_at > 0 ? (int) ( Core::$now - $this->worked_at ) : null,
			'records_gone'      => $this->records_gone,
			'narration'         => $this->tally,
		];
	}

	/**
	 * Release the private requests reader with the node.
	 *
	 * @api Used by substrate.
	 */
	public function remove_node(): void {
		$reader         = $this->requests;
		$this->requests = null;
		Worker_Should_Stop::raise( Worker_Should_Stop::attempt( fn () => $reader?->remove_node(), fn () => parent::remove_node() ) );
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
	 * The `set_requests_partition` verb's entry point, which the declared
	 * setter calls by name. The path keeps its `{partition}`, which the
	 * reader resolves at the bound partition when it is built; a reader over
	 * the last path is released.
	 *
	 * @param string $path The `requests.p{partition}` directory.
	 */
	public function set_requests_partition( string $path ): void {
		$this->requests?->remove_node();
		$this->requests           = null;
		$this->requests_partition = $path;
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
		return [] === $this->rows ? $this->worked_at : null;
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `command_node {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI).
	 *
	 * @api Used by substrate.
	 * @return string TSL lines, newline-terminated.
	 */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters();
	}

	/**
	 * Declarative schema: the `:config` verbs, the TM_REQUEST verb, and the
	 * palette metadata. `Schema_Reflection::auto_wire_interpreter()` builds the
	 * `{name}:config` interpreter from the `commands` entries, and each
	 * `setter` dumps itself through `dump_setters()`.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Reads the record each flame names in requests.p{N} and writes its rows and rollups to a Flame_Facts Row_Table.',
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
				[
					'name'        => 'set_requests_partition',
					'description' => 'Name the requests.p{partition} directory each flame\'s request_at is read from; {partition} resolves at the bound partition.',
					'args'        => [
						[ 'name' => 'path', 'type' => 'string', 'required' => true ],
					],
					// Declarative: the substrate trims, assigns and dumps it.
					'setter'      => 'requests_partition',
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'What the buffer holds and the flames skipped because their record is gone.',
					'reply_shape' => '{ buffered_requests, buffered_rows, last_flush_age_s, records_gone, narration }',
					'handler'     => static fn ( self $node ): array => $node->stats_report(),
				],
			],
		];
	}
}
