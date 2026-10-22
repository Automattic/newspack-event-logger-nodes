<?php
namespace Newspack_Event_Logger_Nodes\Tests;

use Newspack_Nodes\Tests\TestCase as RuntimeTestCase;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Stats_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;

abstract class TestCase extends RuntimeTestCase {

	/** The Row_Table `stats-builder.tsl` declares with the Flame_Facts schema. */
	protected const FACTS_TABLE = 'flame-facts';

	/**
	 * Scratch tree the `tests/configs/logging-*.php` configs point at: this
	 * process's base, `NEWSPACK_TEST_BASE_DIR`, with `-logging` after it.
	 *
	 * MUST match their `base_directory`: storage nodes refuse a path outside the
	 * runtime tree, and the logging suites write `logs/` under it.
	 *
	 * MUST NOT be the BASELINE config's `base_directory`. The logging suites
	 * `rmdir_recursive()` this path ~40 times mid-run, and the substrate's
	 * `make_temp_dir()` hands out dirs INSIDE the configured base — so sharing
	 * one path puts every other test's live scratch tree under a recursive
	 * delete. `ConfigParityTest` pins both halves.
	 */
	protected static function test_dir(): string {
		return ( \getenv( 'NEWSPACK_TEST_BASE_DIR' ) ?: throw new \LogicException( 'NEWSPACK_TEST_BASE_DIR is unset; tests/bootstrap.php sets it' ) ) . '-logging';
	}

	/** The directory a loaded topology's Tables open their files in. */
	private string $tables_dir = '';

	/** The directory holding the requests logs `record_request()` writes, one `requests.p{N}` each. */
	private ?string $requests_root = null;

	/**
	 * The writers of the requests logs, by directory, removed in tearDown.
	 *
	 * @var array<string,\Newspack_Nodes\Partition_Node>
	 */
	private array $requests_logs = [];

	/**
	 * The ID of each record a requests log wrote, as a Consumer delivers it,
	 * in the order written; `record_request()` takes each as it lands.
	 *
	 * @var list<string>
	 */
	private array $written_at = [];

	/**
	 * The `_wp_test_filters` table at setUp, restored in tearDown. The base
	 * class restores `_wp_actions` alone, and this bootstrap's add_filter()
	 * records each callback in both, so a per-test filter otherwise outlives
	 * its test in the by-priority table AppCoreTest reads.
	 *
	 * @var array<string,array<int,list<callable>>>
	 */
	private array $saved_wp_test_filters = [];

	/**
	 * The moment every seed dates from: the tick the code under test reads,
	 * stamped at setUp. The wall moves on, and a seed dated from it lands a
	 * bucket off whenever a five-minute boundary falls in between.
	 */
	protected static function tick(): int {
		return (int) \Newspack_Nodes\Core::$now;
	}

	/**
	 * Run the rest of the test on a tick `$seconds` off the wall, the state a
	 * bucket boundary between setUp and a wall-dated seed leaves behind.
	 * The substrate's tearDown restores the clock.
	 *
	 * @param int $seconds Offset from the wall; negative runs the tick behind.
	 */
	protected function shift_tick( int $seconds ): void {
		\Newspack_Nodes\Core::$clock = static fn (): float => \microtime( true ) + $seconds;
		\Newspack_Nodes\Core::right_now();
	}

	/**
	 * A builder `fb` armed as `flame-builder.tsl` arms one, its auto-tune
	 * flush on the Router tick, sending every message it sends to `$sink`.
	 *
	 * @param \Newspack_Nodes\Node $sink Where its flames, auto-tune emits and replies land.
	 */
	protected function armed_flame_builder( \Newspack_Nodes\Node $sink ): Flame_Builder_Node {
		if ( null === \Newspack_Nodes\Core::node( \Newspack_Nodes\Node_Names::ROUTER ) ) {
			$router = new \Newspack_Nodes\Router_Node();
			$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		}
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->arguments( [] );
		$fb->sink( $sink );
		return $fb;
	}

	/**
	 * A stats builder `sb` armed as `stats-builder.tsl` arms one, its
	 * partition bound to `$partition`, reading the requests logs
	 * `record_request()` writes and writing that partition of a real
	 * `flame-facts` Row_Table through the request graph.
	 *
	 * @param string $partition The partition the worker is bound to.
	 */
	protected function armed_stats_builder( string $partition = '0' ): Stats_Builder_Node {
		\Newspack_Nodes\Core::$var['partition'] = $partition;
		if ( null === \Newspack_Nodes\Core::node( \Newspack_Nodes\Node_Names::ROUTER ) ) {
			$router = new \Newspack_Nodes\Router_Node();
			$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		}
		$this->current_window_facts();
		$sb = new Stats_Builder_Node();
		$sb->name( 'sb' );
		$sb->arguments( [] );
		// As a worker loading stats-builder.tsl names it: no user is current.
		$user = $GLOBALS['_current_user_id'] ?? null;
		unset( $GLOBALS['_current_user_id'] );
		try {
			$config = \Newspack_Nodes\Core::node( 'sb:config' );
			$config->dispatch( 'set_facts_target', [ self::FACTS_TABLE ] );
			$config->dispatch( 'set_requests_partition', [ $this->requests_partition() ] );
		} finally {
			if ( null !== $user ) {
				$GLOBALS['_current_user_id'] = $user;
			}
		}
		$sb->sink( VerbHarness::request_graph() );
		return $sb;
	}

	/** The `{partition}` path the requests logs `record_request()` writes sit at. */
	protected function requests_partition(): string {
		$this->requests_root ??= $this->make_temp_dir( 'stats-requests-' );
		return $this->requests_root . '/requests.p' . \Newspack_Nodes\Core::PARTITION_TOKEN;
	}

	/**
	 * Write `$request` to the bound partition's requests log as the request
	 * builder does, its KEY the rid.
	 *
	 * @param array<string,mixed> $request The completed request.
	 * @return string The record's ID, `segment:offset:length`.
	 */
	protected function record_request( array $request ): string {
		$dir = \Newspack_Nodes\Core::resolve_partition( $this->requests_partition(), (int) \Newspack_Nodes\Core::bound_partition(), null );
		if ( ! isset( $this->requests_logs[ $dir ] ) ) {
			$log = new \Newspack_Nodes\Partition_Node();
			// One wide segment: the test config's 1 KB ones prune as they rotate.
			$log->arguments( [ $dir, (string) ( 64 << 20 ) ] );
			$log->void_warranty();
			$log->with_index(
				function ( array $message, array $position ): ?string {
					$this->written_at[] = \Newspack_Nodes\Log_Position::format( $position['segment'], $position['offset'], $position['length'] );
					return null;
				}
			);
			$this->requests_logs[ $dir ] = $log;
		}
		$message                                       = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::TIMESTAMP ] = \Newspack_Nodes\Core::$now;
		$message[ \Newspack_Nodes\Message::KEY ]       = \Newspack_Nodes\Core::as_string( $request['rid'] ?? '' );
		$message[ \Newspack_Nodes\Message::VALUE ]     = $request;
		$this->requests_logs[ $dir ]->fill( $message );
		$this->requests_logs[ $dir ]->flush();
		// No worker tails the log; a wake would re-read the wall on the tick.
		\Newspack_Nodes\Partition_Node::forget_pending_wakes();
		return \array_pop( $this->written_at ) ?? throw new \LogicException( 'the requests log wrote no record' );
	}

	/**
	 * The flame the flame builder sends for `$request`: its tree, stamped with
	 * the rid, the URL hash and the place of its record. The tree's value is
	 * 725 ms whatever the record's duration, as a rendering artifact differs
	 * from the timed request (decision 12), so a row read off the flame shows.
	 *
	 * @param array<string,mixed> $request    The completed request.
	 * @param string|null         $request_at The record's ID; null for a flame carrying none.
	 * @return array<int,mixed> A TM_STRUCT message.
	 */
	protected static function flame_at( array $request, ?string $request_at ): array {
		$rid   = \Newspack_Nodes\Core::as_string( $request['rid'] ?? '' );
		$flame = [
			'name'     => 'request',
			'value'    => 725.0,
			'children' => [],
			'rid'      => $rid,
			'url_hash' => \Newspack_Event_Logger_Nodes\Url_Parts::hash( \Newspack_Nodes\Core::as_string( $request['url'] ?? '' ) ),
		];
		if ( null !== $request_at ) {
			$flame['request_at'] = $request_at;
		}
		$message                                   = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::KEY ]   = $rid;
		$message[ \Newspack_Nodes\Message::VALUE ] = $flame;
		return $message;
	}

	/**
	 * Write `$request`'s record, then fill `$sb` with the flame naming it.
	 *
	 * @param Stats_Builder_Node  $sb      The builder.
	 * @param array<string,mixed> $request The completed request.
	 * @return string The record's ID.
	 */
	protected function fill_flame( Stats_Builder_Node $sb, array $request ): string {
		$crumb = $this->record_request( $request );
		$sb->fill( self::flame_at( $request, $crumb ) );
		return $crumb;
	}

	/**
	 * The `flame-facts` Row_Table, answering the request graph, its bound
	 * partition's file holding a window expired through the current tick, so
	 * a builder's first fold reads that watermark and rebuilds nothing.
	 */
	protected function current_window_facts(): \Newspack_Nodes\Row_Table_Node {
		$table = \Newspack_Nodes\Core::node( self::FACTS_TABLE );
		if ( ! $table instanceof \Newspack_Nodes\Row_Table_Node ) {
			$table = new \Newspack_Nodes\Row_Table_Node();
			$table->name( self::FACTS_TABLE );
			$table->arguments( [ 'Flame_Facts' ] );
			// Mounting the graph logs a user in; the caller's stands instead.
			$user = $GLOBALS['_current_user_id'] ?? null;
			$table->sink( VerbHarness::request_graph() );
			unset( $GLOBALS['_current_user_id'] );
			if ( null !== $user ) {
				$GLOBALS['_current_user_id'] = $user;
			}
		}
		self::rows_into( $table, [ [ 'ignore', 'window_expiry', [ self::current_window_through() ] ] ] );
		return $table;
	}

	/** The watermark a window expired through the current tick holds. */
	protected static function current_window_through(): int {
		return \Newspack_Event_Logger_Nodes\Bucket::id( (int) \Newspack_Nodes\Core::$now ) - \Newspack_Event_Logger_Nodes\Bucket::window();
	}

	/**
	 * Send one ROWS batch straight to a Row_Table, catching its reply here
	 * rather than on the table's sink, and fail on a refusal.
	 *
	 * @param \Newspack_Nodes\Row_Table_Node $table The table.
	 * @param list<array<int,mixed>>        $batch The ops.
	 */
	protected static function rows_into( \Newspack_Nodes\Row_Table_Node $table, array $batch ): void {
		$sink    = $table->sink();
		$capture = new \Newspack_Nodes\Tests\Capture_Sink_Node();
		$table->sink( $capture );
		$request                                   = \Newspack_Nodes\Message::new_message();
		$request[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_REQUEST | \Newspack_Nodes\Message::TM_STRUCT;
		$request[ \Newspack_Nodes\Message::FROM ]  = 'facts-seeder';
		$request[ \Newspack_Nodes\Message::VALUE ] = [ 'ROWS' => $batch ];
		try {
			$table->fill( $request );
		} finally {
			if ( null !== $sink ) {
				$table->sink( $sink );
			}
		}
		$reply = $capture->captured[0] ?? throw new \LogicException( 'the facts table answered no ROWS' );
		if ( 0 !== ( \Newspack_Nodes\Core::num_int( $reply[ \Newspack_Nodes\Message::TYPE ] ) & \Newspack_Nodes\Message::TM_ERROR ) ) {
			throw new \LogicException( 'the facts table refused ROWS: ' . \Newspack_Nodes\Core::as_string( $reply[ \Newspack_Nodes\Message::VALUE ] ) );
		}
	}

	/**
	 * Rows a statement reads from partition `$partition` of the `flame-facts`
	 * file, each as a list.
	 *
	 * @param string $sql       The statement.
	 * @param string $partition The partition's file.
	 * @return list<list<mixed>>
	 */
	protected function facts( string $sql, string $partition = '0' ): array {
		$pdo = new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( self::FACTS_TABLE, (int) $partition ) );
		return \array_map( 'array_values', $pdo->query( $sql )->fetchAll( \PDO::FETCH_NUM ) );
	}

	/** Make every write to the `flame-facts` file's `requests` table fail, until `mend_facts()`. */
	protected function break_facts(): void {
		( new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( self::FACTS_TABLE, 0 ) ) )->exec( 'ALTER TABLE requests RENAME TO requests_broken' );
	}

	/** Undo `break_facts()`, rows intact. */
	protected function mend_facts(): void {
		( new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( self::FACTS_TABLE, 0 ) ) )->exec( 'ALTER TABLE requests_broken RENAME TO requests' );
	}

	/** Advance the tick by $seconds, then fire the Router's TIMER. */
	protected function tick_router( int $seconds ): void {
		\Newspack_Nodes\Core::$now += $seconds;
		\Newspack_Nodes\Core::node( \Newspack_Nodes\Node_Names::ROUTER )->fire_cb();
	}

	/**
	 * A completed request as the request builder writes one: production
	 * shaped, overridden only where a test asserts.
	 *
	 * @param array<string,mixed> $overrides Fields the test names.
	 * @return array<string,mixed>
	 */
	protected function completed_request( array $overrides = [] ): array {
		return \array_replace(
			[
				'rid'            => 'r' . \uniqid(),
				'url'            => 'https://example.com/post/123',
				'rule_id'        => 'r',
				'duration_ms'    => 100.0,
				'status_code'    => 200,
				'error_status'   => '-',
				'peak_mb'        => 32.0,
				'request_method' => 'GET',
				'country_code'   => 'US',
				'http_from'      => '',
				'user_agent'     => 'curl/7.85',
				'ja4_hash'       => '',
				'is_worker'      => false,
				'timestamp'      => self::tick(),
				'entries'        => [],
				'profiles'       => [],
			],
			$overrides
		);
	}

	/** Fill a record as a Consumer delivers it, its crumb in ID. */
	protected function fill_request_at( Flame_Builder_Node $fb, array $request, string $crumb ): void {
		$message                                   = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::ID ]    = $crumb;
		$message[ \Newspack_Nodes\Message::VALUE ] = $request;
		$fb->fill( $message );
	}

	/**
	 * Path to a pre-written config file in `tests/configs/`.
	 *
	 * @param string $name Basename without the extension.
	 */
	protected function config_path( string $name ): string {
		return \dirname( __DIR__ ) . '/configs/' . $name . '.php';
	}

	/**
	 * Open a logged request at `$uri` under one `/kea/` log rule, on the
	 * `logging-capture` config, which writes every line straight to disk for
	 * `logged_entries()` to read, in segments too large for a test to rotate. The caller's tearDown undoes the config,
	 * the rules option and REQUEST_URI.
	 *
	 * @param string              $uri  The request's REQUEST_URI, under `/kea/`.
	 * @param array<string,mixed> $rule The rule's fields beside its pattern and action.
	 */
	protected function start_logged_request( string $uri = '/kea/7746', array $rule = [] ): \Newspack_Event_Logger_Nodes\Log_Manager {
		$this->rmdir_recursive( self::test_dir() );
		@\mkdir( self::test_dir() . '/logs', 0755, true );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-capture' ) );
		$rule = \Newspack_Event_Logger_Nodes\Rule::from_array( [ 'id' => 'a1b2c3d4e5f6', 'pattern' => '/kea/', 'action' => 'log' ] + $rule );
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [ $rule->to_array() ];
		$_SERVER['REQUEST_URI'] = $uri;
		\Newspack_Event_Logger_Nodes\Config::reset();
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		$lm = \Newspack_Event_Logger_Nodes\Log_Manager::instance();
		self::assertTrue( $lm->is_started(), 'the /kea/ rule logs the request' );
		return $lm;
	}

	/**
	 * Finish the logged request and read back every entry it wrote.
	 *
	 * @return list<array<string,mixed>>
	 */
	protected function logged_entries(): array {
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		return self::firehose_entries( self::test_dir() );
	}

	/**
	 * Re-assert the topology registration bootstrap.php makes. Sibling tests call
	 * Topology_Registry::reset(), which strands every later test that reads a
	 * topology — and ELN topologies `include` ACROSS the plugin boundary
	 * (job-router -> job-intake, which the substrate ships), so BOTH dirs must
	 * resolve. An unresolvable include throws by design: an empty write set
	 * would read as "no conflict" to the gate and "these logs are orphans" to the
	 * GC. register_plugin()/register_builtin_dir() are idempotent.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved_wp_test_filters = $GLOBALS['_wp_test_filters'] ?? [];
		// No user is current until a test, or the request graph, logs one in.
		unset( $GLOBALS['_current_user_id'] );
		// The monotonic clock tracks the tick, as every test here dates by it.
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::$hrtime_fn = static fn (): int => (int) ( \Newspack_Nodes\Core::$now * 1e9 );
		// The harness registers no RESET_ACTION listener, so drop ELN's memo here.
		\Newspack_Event_Logger_Nodes\Config::reset_local_cache();
		// Resolved now, while the test's config still reads.
		$this->tables_dir = \Newspack_Nodes\Bootstrap::base_dir() . '/tables';
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
		\Newspack_Nodes\Topology_Registry::register_builtin_dir(
			\dirname( __DIR__, 3 ) . '/newspack-nodes/topologies'
		);
	}

	/**
	 * Every firehose entry written under a base directory's `logs/`, in
	 * partition and segment order: each packed Message's VALUE, the entry hash
	 * `k`, `m` and the rest ride in. A line whose VALUE is not an entry fails the
	 * test, because a producer regression would otherwise drop out unseen.
	 *
	 * @param string $base     The base directory the logger wrote under.
	 * @param bool   $with_rid Set `rid` from the Message KEY, where the wire keeps it.
	 * @return list<array<string,mixed>>
	 */
	protected static function firehose_entries( string $base, bool $with_rid = false ): array {
		$entries = [];
		$files   = \glob( "{$base}/logs/firehose.p*/*.log" ) ?: [];
		// Natural order: `p2` before `p10`, segment `999` before `1000`.
		\sort( $files, \SORT_NATURAL );
		foreach ( $files as $file ) {
			foreach ( \array_filter( \explode( "\n", (string) \file_get_contents( $file ) ) ) as $line ) {
				$message = \Newspack_Nodes\Message::unpacked( $line );
				$value   = $message[ \Newspack_Nodes\Message::VALUE ] ?? null;
				self::assertIsArray( $value, "a line in {$file} carries no firehose entry" );
				if ( $with_rid ) {
					$value['rid'] = (string) ( $message[ \Newspack_Nodes\Message::KEY ] ?? '' );
				}
				$entries[] = $value;
			}
		}
		return $entries;
	}

	/**
	 * Make the next Partition write raise the cooperative stop, as a lost lock
	 * would. Returns the disarm.
	 *
	 * @return \Closure(): void
	 */
	public static function arm_stop_on_next_write(): \Closure {
		$framework = \Newspack_Nodes\Event_Framework::instance();
		$predicate = new \ReflectionProperty( \Newspack_Nodes\Event_Framework::class, 'continue_predicate' );
		$last_pump = new \ReflectionProperty( \Newspack_Nodes\Event_Framework::class, 'last_pump' );
		$predicate->setValue( $framework, static fn (): bool => false );
		$last_pump->setValue( $framework, 0.0 );
		return static fn () => $predicate->setValue( $framework, null );
	}

	/**
	 * Run `$work` inside a started request log over the runtime tree at
	 * `$base`, then return every firehose entry it wrote.
	 *
	 * @param string              $base   The runtime tree the log writes under.
	 * @param \Closure(): mixed   $work   What to log.
	 * @param array<string,mixed> $extras Config beside logging, as `use_base_dir()` takes it.
	 * @return list<array<string,mixed>>
	 */
	protected function logged_in( string $base, \Closure $work, array $extras = [] ): array {
		$this->use_base_dir( $base, $extras + [ 'num_partitions' => 1, 'min_lifetime' => 86400, 'enable_logging' => true ] );
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'flush_every_line' => true ] ];
		$server                 = $_SERVER;
		$_SERVER['REQUEST_URI'] = '/perf-probe-7731';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		self::assertTrue( \Newspack_Event_Logger_Nodes\Log_Manager::instance()->is_started(), 'the request logs' );
		try {
			$work();
		} finally {
			\Newspack_Event_Logger_Nodes\Log_Manager::reset();
			$_SERVER = $server;
		}
		return self::firehose_entries( $base );
	}

	/**
	 * The `(start)` and `(complete)` lines of the named spans among
	 * `$entries`, in order, each as its category, `l` and `m`.
	 *
	 * @param list<array<string,mixed>> $entries  What `firehose_entries()` read.
	 * @param string                    ...$spans Span names.
	 * @return list<array{0:mixed,1:mixed,2:mixed}>
	 */
	protected static function spans_of( array $entries, string ...$spans ): array {
		$categories = [];
		foreach ( $spans as $span ) {
			\array_push( $categories, "{$span} (start)", "{$span} (complete)" );
		}
		return \array_map(
			static fn ( array $entry ): array => [ $entry['k'], $entry['l'] ?? null, $entry['m'] ?? null ],
			self::entries_of( $entries, ...$categories )
		);
	}

	/**
	 * The entries whose category `k` is one of `$categories`, in order.
	 *
	 * @param list<array<string,mixed>> $entries       What `firehose_entries()` read.
	 * @param string                    ...$categories Categories to keep.
	 * @return list<array<string,mixed>>
	 */
	protected static function entries_of( array $entries, string ...$categories ): array {
		return \array_values( \array_filter(
			$entries,
			static fn ( array $entry ): bool => \in_array( $entry['k'] ?? null, $categories, true )
		) );
	}

	/**
	 * The last entry of one category, or null when none was written.
	 *
	 * @param list<array<string,mixed>> $entries  What `firehose_entries()` read.
	 * @param string                    $category The category `k`.
	 * @return array<string,mixed>|null
	 */
	protected static function last_entry_of( array $entries, string $category ): ?array {
		$matches = self::entries_of( $entries, $category );
		return [] === $matches ? null : $matches[ \count( $matches ) - 1 ];
	}

	/**
	 * Drop the process-wide Log_Manager any test left behind.
	 *
	 * The substrate's `newspack_nodes/stderr` seam feeds every stderr line to
	 * `Diagnostics_Bridge`, which writes it through a STARTED logger, and
	 * `message()` re-reads `Core::right_now()`. A logger one test starts and
	 * never resets therefore re-pins `Core::$now` to the wall clock under
	 * every later test that pins it and then trips a `print_less_often()`.
	 */
	protected function tearDown(): void {
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::$hrtime_fn = null;
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		foreach ( $this->requests_logs as $log ) {
			$log->remove_node();
		}
		$this->requests_logs = [];
		$this->rmdir_recursive( $this->tables_dir );
		$GLOBALS['_wp_test_filters'] = $this->saved_wp_test_filters;
		parent::tearDown();
	}

	/**
	 * ELN-specific default prefix so app temp dirs live in their OWN namespace,
	 * not the substrate's `newspack-nodes-test-`: each suite's run-coverage.sh
	 * sweeps its own prefix's stale dirs, and a shared prefix would put one
	 * suite's dirs in the other's sweep. Inherits the parent's PID +
	 * more-entropy uniqueness and auto-cleanup.
	 */
	protected function make_temp_dir( string $prefix = 'newspack-event-logger-nodes-test-' ): string {
		return parent::make_temp_dir( $prefix );
	}

	/**
	 * Point the `config` token namespace at a per-test scratch tree.
	 *
	 * Topology TSL resolves `<config:KEY>` through the substrate's registered
	 * `config` namespace. Tests that load a topology in-process override it so
	 * the Consumer/Partition nodes open under their own scratch dir instead of
	 * the shared base directory, where orphan lock dirs from prior runs burn
	 * ORPHAN_GRACE_S * partitions seconds in `Lock::try_steal_orphan_or_stale()`.
	 *
	 * Only the three directory keys are replaced; everything else defers to the
	 * substrate resolver, which also answers the `<config:KEY>` tokens node
	 * schemas carry as argument DEFAULTS — a hand-listed key set goes stale the
	 * next time a schema grows one. An unanswerable key throws rather than
	 * resolving empty: a null there silently built `/combined.firehose.p0` out
	 * of a missing `deadletter_dir`.
	 *
	 * Callers must restore `Core::$config_resolvers` in tearDown.
	 *
	 * @param string                $tmp       Scratch dir, inside the configured base.
	 * @param array<string, string> $overrides Per-test values for any config key.
	 */
	protected function use_scratch_config( string $tmp, array $overrides = [] ): void {
		\Newspack_Nodes\Config::register_token_namespace();
		$substrate = \Newspack_Nodes\Core::$config_resolvers['config'];
		$values    = \array_merge(
			[
				'logs_dir'       => $tmp . '/logs',
				'offsets_dir'    => $tmp . '/offsets',
				'deadletter_dir' => $tmp . '/deadletter',
			],
			$overrides
		);
		\Newspack_Nodes\Core::register_config_namespace(
			'config',
			static function ( string $key ) use ( $values, $substrate ): string {
				$value = $values[ $key ] ?? $substrate( $key );
				if ( null === $value ) {
					throw new \RuntimeException(
						\sprintf( 'test config namespace cannot resolve %s', $key )
					);
				}
				return (string) $value;
			}
		);
	}

	/**
	 * Same as the substrate helper but also resets the application Config
	 * cache so its merged result picks up the new file.
	 */
	protected function use_base_dir( string $dir, array $extras = [] ): void {
		parent::use_base_dir( $dir, $extras );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			\Newspack_Event_Logger_Nodes\Config::reset();
		}
	}

	/**
	 * Store the substrate's `min_lifetime` as its option, the layer that
	 * outranks every config file, and drop both configs' memos, so
	 * `Bucket::window()` reads it through this plugin's `Config`.
	 *
	 * @param int $seconds The lifetime; the stats span is it in whole buckets.
	 */
	protected function use_min_lifetime( int $seconds ): void {
		$GLOBALS['_wp_options']['newspack_nodes_min_lifetime'] = $seconds;
		\Newspack_Event_Logger_Nodes\Config::reset();
	}

	/**
	 * Activate one SHIPPED topology, alone, across `$num_partitions` workers,
	 * so a test reads the graph the release carries and a renamed node fails
	 * it. ELN's own dir resolves first, the substrate's behind it for
	 * `include topic-probe`.
	 *
	 * @param string $name           Topology name, as its `.tsl` file is named.
	 * @param int    $num_partitions Worker count the catalog entry declares.
	 */
	protected function activate_shipped( string $name, int $num_partitions ): void {
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		\Newspack_Nodes\Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
		\Newspack_Nodes\Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ) use ( $name, $num_partitions ): array {
				$topologies[ $name ] = [ 'topology' => $name, 'num_partitions' => $num_partitions, 'stale_timeout' => 60 ];
				return $topologies;
			}
		);
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ $name ];
		\Newspack_Event_Logger_Nodes\Config::reset();
	}

	/**
	 * The install-scoped address of a logical memcache key.
	 *
	 * Tests assert on real keys, and the scope is not theirs to spell — deriving
	 * it here is what stops a prefix change from needing 30 edits, and what
	 * keeps a test from passing on a prefix mismatch instead of the thing it
	 * means to check.
	 */
	protected static function scoped( string $logical ): string {
		return \Newspack_Nodes\Cache_Backend::site_key( $logical );
	}

	/**
	 * Run the deferred bootstrap, then `$body`, then put back every static the
	 * bootstrap registers into.
	 *
	 * @param \Closure(): void $body What to run while the registrations stand.
	 */
	protected static function with_deferred_bootstrap( \Closure $body ): void {
		$formatters = new \ReflectionProperty( \Newspack_Nodes\Formatters::class, 'registry' );
		$saved      = [
			'actions'    => $GLOBALS['_wp_actions'],
			'filters'    => $GLOBALS['_wp_test_filters'] ?? [],
			'around'     => \Newspack_Nodes\Command_Interpreter_Node::$around_dispatch,
			'resolvers'  => \Newspack_Nodes\Core::$config_resolvers,
			'formatters' => $formatters->getValue(),
		];
		try {
			\newspack_event_logger_nodes_boot();
			$body();
		} finally {
			$GLOBALS['_wp_actions']                                    = $saved['actions'];
			$GLOBALS['_wp_test_filters']                               = $saved['filters'];
			\Newspack_Nodes\Command_Interpreter_Node::$around_dispatch = $saved['around'];
			\Newspack_Nodes\Core::$config_resolvers                    = $saved['resolvers'];
			$formatters->setValue( null, $saved['formatters'] );
		}
	}
}
