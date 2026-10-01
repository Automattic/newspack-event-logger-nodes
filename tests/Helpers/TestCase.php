<?php
namespace Newspack_Event_Logger_Nodes\Tests;

use Newspack_Nodes\Tests\TestCase as RuntimeTestCase;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\Stats_Asker_Node;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;

abstract class TestCase extends RuntimeTestCase {

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

	/** @var array<string,true> Directories a harness stats file was opened in. */
	private array $stats_table_dirs = [];

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
	 * Run the rest of the test at `$t`: the clock pinned there and the tick
	 * read from it. The substrate's tearDown restores the clock.
	 *
	 * @param int $t Unix timestamp.
	 */
	protected static function clock_at( int $t ): void {
		\Newspack_Nodes\Core::$clock = static fn (): float => (float) $t;
		\Newspack_Nodes\Core::right_now();
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
	 * Re-assert the topology registration bootstrap.php makes. Sibling tests call
	 * Topology_Registry::reset(), which strands every later test that reads a
	 * topology — and ELN topologies `include` ACROSS the plugin boundary
	 * (job-router -> job-intake, which the substrate ships), so BOTH dirs must
	 * resolve. An unresolvable include now throws by design: an empty write set
	 * would read as "no conflict" to the gate and "these logs are orphans" to the
	 * GC. register_plugin()/register_builtin_dir() are idempotent.
	 */
	protected function setUp(): void {
		parent::setUp();
		// No user is current until a test, or the request graph, logs one in.
		unset( $GLOBALS['_current_user_id'] );
		// The monotonic clock tracks the tick, as every test here dates by it.
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::$hrtime_fn   = static fn (): int => (int) ( \Newspack_Nodes\Core::$now * 1e9 );
		\Newspack_Event_Logger_Nodes\Request_Builder_Node::$hrtime_fn = static fn (): int => (int) ( \Newspack_Nodes\Core::$now * 1e9 );
		// The harness registers no RESET_ACTION listener, so drop ELN's memo here.
		\Newspack_Event_Logger_Nodes\Config::reset_local_cache();
		// A loaded topology's stores open their files under the base, as the
		// harness's do; resolved now, while the test's config still reads.
		$this->stats_table_dirs[ \Newspack_Nodes\Bootstrap::base_dir() . '/tables' ]  = true;
		$this->stats_table_dirs[ \Newspack_Nodes\Bootstrap::base_dir() . '/ledgers' ] = true;
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
	 * `k`, `m` and the rest ride in. A line whose VALUE is no entry fails the
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
		$this->use_base_dir( $base, $extras + [ 'num_partitions' => 1, 'min_lifetime' => 86400, 'enable_logging' => true, 'flush_every_line' => true ] );
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ] ];
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
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::$hrtime_fn   = null;
		\Newspack_Event_Logger_Nodes\Request_Builder_Node::$hrtime_fn = null;
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		foreach ( \array_keys( $this->stats_table_dirs ) as $dir ) {
			$this->rmdir_recursive( $dir );
		}
		$this->stats_table_dirs = [];
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
	 * A Stats_Store over this test's stats Ledgers and its `url` Table, as
	 * the flame builder holds its own: each declared by an active topology
	 * and written by a node in this process under partition `$partition`,
	 * then renamed so several partitions' writers share one process and a
	 * reader's mount of the same file keeps the Ledger's own name. With no
	 * topology declaring the partition, the shipped `flame-builder` is
	 * activated to declare it.
	 *
	 * The store asks through `$asker`'s own client, as `configure_stats`
	 * wires one: the builder is named when it has no name and sunk into the
	 * request graph when it has no sink, so its replies reach its `fill()`.
	 * Without one, every store of a test shares the harness asker.
	 *
	 * @param int                     $partition Flame-builder partition.
	 * @param Flame_Builder_Node|null $asker     The builder asking, or the harness.
	 */
	protected function stats_store( int $partition = 0, ?Flame_Builder_Node $asker = null ): Stats_Store {
		return new Stats_Store( ...$this->stats_store_args( $partition, $asker ) );
	}

	/**
	 * Every serializer a size estimate is kept for, one data set each.
	 *
	 * @return array<string,array{0: string}>
	 */
	public static function serializers(): array {
		return [
			'php'      => [ Stats_Store::SERIALIZER_PHP ],
			'igbinary' => [ Stats_Store::SERIALIZER_IGBINARY ],
		];
	}

	/**
	 * Install a fresh in-memory handle configured with `$serializer`, the one
	 * every size estimate then assumes; igbinary skips where it is not loaded.
	 *
	 * @param string $serializer A `Stats_Store::SERIALIZER_*` value.
	 */
	public static function estimate_for( string $serializer ): void {
		if ( Stats_Store::SERIALIZER_IGBINARY === $serializer && ! \function_exists( 'igbinary_serialize' ) ) {
			self::markTestSkipped( 'igbinary is not loaded' );
		}
		\Newspack_Nodes\Core::$memd = new \Newspack_Nodes\Tests\Helpers\InMemoryMemcached();
		\Newspack_Nodes\Core::$memd->setOption( \Memcached::OPT_SERIALIZER, Stats_Store::SERIALIZER_IGBINARY === $serializer ? \Memcached::SERIALIZER_IGBINARY : \Memcached::SERIALIZER_PHP );
	}

	/**
	 * `stats_store()`'s constructor arguments, for a test that subclasses
	 * Stats_Store to watch or bend one call.
	 *
	 * @return array{0: \Newspack_Nodes\Table_Client, 1: array<string,string>, 2: list<string>}
	 */
	protected function stats_store_args( int $partition = 0, ?Flame_Builder_Node $asker = null ): array {
		$this->declare_stats( $partition );
		$ledgers = [];
		foreach ( \array_keys( Stats_Store::LEDGER_COLUMNS ) as $ledger ) {
			$ledgers[ $ledger ] = $this->stats_ledger_writer( $ledger, $partition );
		}
		$url = [ $this->stats_table_writer( Stats_Store::TABLE_URL, $partition ) ];
		if ( null === $asker ) {
			return [ $this->stats_asker()->client, $ledgers, $url ];
		}
		if ( '' === $asker->name() ) {
			$asker->name( 'flame-builder-' . \spl_object_id( $asker ) );
		}
		if ( null === $asker->sink() ) {
			$asker->sink( VerbHarness::ask_recorder() );
		}
		// The builder's own asker, as `configure_stats` hands it to its store.
		return [ ( new \ReflectionProperty( Flame_Builder_Node::class, 'client' ) )->getValue( $asker ), $ledgers, $url ];
	}

	/**
	 * A Stats_Store reading as a verb reads: every stats Ledger and every
	 * partition's url Table mounted into the request graph, which reads
	 * every partition's writes.
	 */
	protected function stats_reader(): Stats_Store {
		$tables = \Newspack_Nodes\Bootstrap::mount_table( [ Stats_Store::TABLE_URL ] )[ Stats_Store::TABLE_URL ] ?? [];
		return new Stats_Store( $this->stats_asker()->client, \Newspack_Nodes\Bootstrap::mount_ledger( \array_keys( Stats_Store::LEDGER_COLUMNS ) ), \array_values( $tables ) );
	}

	/**
	 * Make sure an active topology declares the stats Ledgers and Table for
	 * `$partition`, activating the shipped `flame-builder` when none does.
	 *
	 * @throws \LogicException When an active topology declares them without that partition.
	 */
	private function declare_stats( int $partition ): void {
		$declared = \Newspack_Nodes\Bootstrap::node_tables( Stats_Store::TABLE_URL );
		if ( isset( $declared[ Stats_Store::TABLE_URL ][ $partition ] ) ) {
			return;
		}
		$active = $GLOBALS['_wp_options']['newspack_nodes_topologies'] ?? [];
		if ( [] !== $active && [ 'flame-builder' ] !== $active ) {
			throw new \LogicException( "the active topologies declare no partition {$partition} of the stats" );
		}
		$this->activate_shipped( 'flame-builder', $partition + 1 );
	}

	/** The harness asker, sinking through the request graph's recorder. */
	private function stats_asker(): Stats_Asker_Node {
		$asker = \Newspack_Nodes\Core::node( 'stats-asker' );
		if ( $asker instanceof Stats_Asker_Node ) {
			return $asker;
		}
		$asker = new Stats_Asker_Node();
		$asker->name( 'stats-asker' );
		$asker->sink( VerbHarness::ask_recorder() );
		return $asker;
	}

	/**
	 * The Ledger node partition `$partition`'s worker writes one stats
	 * Ledger through: opened under the declared name with `<partition>`
	 * bound, as a worker opens it, then renamed.
	 *
	 * @return string The writer's node name.
	 */
	protected function stats_ledger_writer( string $ledger, int $partition ): string {
		$name = "{$ledger}.writer.p{$partition}";
		if ( \Newspack_Nodes\Core::node( $name ) instanceof \Newspack_Nodes\Ledger_Node ) {
			return $name;
		}
		$declaration = \Newspack_Nodes\Bootstrap::node_ledgers( $ledger )[ $ledger ] ?? throw new \LogicException( "no active topology declares {$ledger}" );
		$writer      = $this->bound_to( $partition, static function () use ( $ledger, $declaration ): \Newspack_Nodes\Ledger_Node {
			$writer = new \Newspack_Nodes\Ledger_Node();
			$writer->name( $ledger );
			try {
				$writer->arguments( [ (string) $declaration['segment_seconds'], (string) $declaration['num_segments'], ...$declaration['columns'] ] );
			} catch ( \Throwable $e ) {
				$writer->remove_node();
				throw $e;
			}
			return $writer;
		} );
		$writer->name( $name );
		$writer->sink( VerbHarness::request_graph() );
		$this->stats_table_dirs[ \dirname( \Newspack_Nodes\Ledger_Node::file( $ledger, $partition ) ) ] = true;
		return $name;
	}

	/**
	 * The Table node writing one partition of a stats Table, opened and
	 * renamed as a Ledger's writer is.
	 *
	 * @return string The writer's node name.
	 */
	private function stats_table_writer( string $table, int $partition ): string {
		$name = \Newspack_Nodes\Table_Node::stem( $table, $partition ) . '.writer';
		if ( \Newspack_Nodes\Core::node( $name ) instanceof \Newspack_Nodes\Table_Node ) {
			return $name;
		}
		$spec   = \Newspack_Nodes\Bootstrap::node_tables( $table )[ $table ][ $partition ];
		$writer = $this->bound_to( $partition, static function () use ( $table, $spec ): \Newspack_Nodes\Table_Node {
			$writer = new \Newspack_Nodes\Table_Node();
			$writer->name( $table );
			try {
				$writer->arguments( [ $spec['namespace'], (string) $spec['ttl'], $spec['backend'] ] );
			} catch ( \Throwable $e ) {
				$writer->remove_node();
				throw $e;
			}
			return $writer;
		} );
		$writer->name( $name );
		$writer->sink( VerbHarness::request_graph() );
		$this->stats_table_dirs[ \dirname( \Newspack_Nodes\Table_Node::file( $table, $partition ) ) ] = true;
		return $name;
	}

	/**
	 * Run `$open` with `<partition>` bound to `$partition`, as a worker's
	 * Shell binds it, and whatever was bound before restored after.
	 *
	 * @template T
	 * @param \Closure(): T $open Opens a node.
	 * @return T
	 */
	private function bound_to( int $partition, \Closure $open ): mixed {
		$bound = \array_key_exists( 'partition', \Newspack_Nodes\Core::$var ) ? [ \Newspack_Nodes\Core::$var['partition'] ] : [];
		\Newspack_Nodes\Core::$var['partition'] = (string) $partition;
		try {
			return $open();
		} finally {
			unset( \Newspack_Nodes\Core::$var['partition'] );
			if ( [] !== $bound ) {
				\Newspack_Nodes\Core::$var['partition'] = $bound[0];
			}
		}
	}

	/**
	 * The requests a test's askers sent TO a stats Ledger, one per request,
	 * as `[ verb, argument ]`: an APPEND's rows, or a read's query.
	 *
	 * @param string $ledger The Ledger, by its declared name; a writer or a
	 *                       mount answering it counts.
	 * @return list<array{0: string, 1: mixed}>
	 */
	protected function ledger_asks( string $ledger ): array {
		$out = [];
		foreach ( VerbHarness::ask_recorder()->asked as $asked ) {
			$to = \preg_replace( '/\.writer\.p\d+$/', '', $asked['to'] );
			if ( $ledger === $to && \is_array( $asked['value'] ) ) {
				$verb  = (string) \array_key_first( $asked['value'] );
				$out[] = [ $verb, $asked['value'][ $verb ] ];
			}
		}
		return $out;
	}

	/** Forget every request the harness recorded, so a test counts from here. */
	protected function forget_stats_asks(): void {
		VerbHarness::ask_recorder()->asked   = [];
		VerbHarness::ask_recorder()->refused = [];
	}

	/**
	 * Answer every read request TO a node `$pattern` matches with the
	 * TM_ERROR its store sends when the read fails; '' answers every one
	 * again.
	 *
	 * @param string $pattern A PCRE pattern over the node a request is TO, or ''.
	 */
	protected function refuse_stats_reads( string $pattern ): void {
		VerbHarness::ask_recorder()->refuse = $pattern;
	}

	/**
	 * Count the topology-catalog reads from here on. `newspack_nodes/topologies`
	 * fires once per catalog read, which no memo spares, so the count is the
	 * store builds and mirror resolutions a path pays.
	 *
	 * @return \Closure(): int The reads so far.
	 */
	protected static function count_catalog_reads(): \Closure {
		$reads = 0;
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ) use ( &$reads ): array {
				++$reads;
				return $topologies;
			}
		);
		return static function () use ( &$reads ): int {
			return $reads;
		};
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
	 * A name-table series decoded to `bucket => name => entry`, the entry
	 * positional as stored, so an assertion names its field by constant.
	 *
	 * @param array{names: list<string>, buckets: array<string,list<list<int|float>>>} $wire
	 * @return array<string,array<string,list<int|float>>>
	 */
	protected static function wire_series( array $wire ): array {
		$out = [];
		foreach ( $wire['buckets'] as $bucket => $rows ) {
			foreach ( $rows as $row ) {
				$out[ $bucket ][ $wire['names'][ $row[0] ] ] = \array_slice( $row, 1 );
			}
		}
		return $out;
	}
}
