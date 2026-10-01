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

	/** @var array<string,true> Directories a harness stats Table file was opened in. */
	private array $stats_table_dirs = [];

	/**
	 * The server the URL seed helpers file rows under when a test names none:
	 * the `server_name` a completed request carries by default, so a row a
	 * test seeds and a row the flush writes land under one key.
	 */
	protected const SEED_SERVER = 'example.com';

	/**
	 * The moment every seed dates from: the tick the code under test reads,
	 * stamped at setUp. The wall moves on, and a seed dated from it lands a
	 * bucket off whenever a five-minute boundary falls in between.
	 */
	protected static function tick(): int {
		return (int) \Newspack_Nodes\Core::$now;
	}

	/**
	 * The URL-index keys a test's askers asked of `$ns`, stored
	 * `{ns}:{key}:{server_key}:{shard}`, as `{server_key}:{shard}:{key}`,
	 * sorted, one per ask.
	 *
	 * @param string $ns `Stats_Store::NS_URLS` or `NS_URLS_HOUR`.
	 * @return list<string>
	 */
	protected function asked_url_keys( string $ns = Stats_Store::NS_URLS ): array {
		$out = [];
		foreach ( $this->asked_keys( $ns ) as $key ) {
			if ( 1 === \preg_match( '/^' . \preg_quote( $ns, '/' ) . ':([0-9-]+):([0-9a-f]{8}:w?[0-9a-f])$/', $key, $m ) ) {
				$out[] = "{$m[2]}:{$m[1]}";
			}
		}
		\sort( $out );
		return $out;
	}

	/**
	 * A stored server index naming each of `$servers`, every one with the
	 * same shards: the value `urlsrv` and `urlsrv_h` hold.
	 *
	 * @param list<string> $servers Server names.
	 * @param list<string> $shards  Shard tokens each wrote.
	 * @return array<string,array{0:string,1:int}>
	 */
	protected static function index_of( array $servers, array $shards = [] ): array {
		$out = [];
		foreach ( $servers as $server ) {
			$out[ Stats_Store::server_key( $server ) ] = [
				Stats_Store::SRV_NAME   => $server,
				Stats_Store::SRV_SHARDS => Stats_Store::shard_mask( $shards ),
			];
		}
		return $out;
	}

	/**
	 * A URL under `$origin` whose hash files it in `$shard`, a worker shard
	 * when the token says so.
	 *
	 * @param string $origin Scheme and host, e.g. `https://kea.test`.
	 * @param string $shard  Shard token, as `Stats_Store::url_shards()` spells them.
	 */
	protected static function url_in_shard( string $origin, string $shard ): string {
		$worker = \str_starts_with( $shard, Stats_Store::WORKER_SHARD_PREFIX );
		for ( $i = 0; $i < 10000; $i++ ) {
			$url = "{$origin}/kokako-{$i}";
			if ( Stats_Store::url_shard( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( $url ), $worker ) === $shard ) {
				return $url;
			}
		}
		throw new \RuntimeException( "no URL under {$origin} hashes to shard {$shard}" );
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
	 * Path to a pre-written config file in `tests/configs/`.
	 *
	 * @param string $name Basename without the extension.
	 */
	protected function config_path( string $name ): string {
		return \dirname( __DIR__ ) . '/configs/' . $name . '.php';
	}

	/**
	 * URL rows per bucket, across the shapes `url_row_sources()` returns.
	 *
	 * It returns one pair per shard, and only the OVERFLOW rows are folded here,
	 * because `fold_url_rows()` keeps only the fields that add — a real hash
	 * put through it would come back without `url` or the extremes, and a
	 * later assertion on those would read as a data bug
	 * rather than as the helper. A real straddling hash needs the reader's full
	 * arithmetic, so it FAILS here instead of being quietly halved.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store   The store to read.
	 * @param array<int,string>                        $buckets Bucket keys.
	 * @return array<string,array<array-key,mixed>>
	 */
	protected function url_rows_by_bucket( \Newspack_Event_Logger_Nodes\Stats_Store $store, array $buckets ): array {
		// Both populations in ONE round trip, folded back together the way one
		// row used to be: a reader and a worker row for one hash is not a
		// straddle, and reading them shard by shard is the latency cliff.
		$out = [];
		foreach ( $store->url_row_sources( $buckets, null, true ) as [ $bucket, $rows ] ) {
			foreach ( $rows as $hash => $row ) {
				// `merge_url_row`, not `fold_url_rows`: one hash across two
				// populations is the SAME url, so its extremes still describe it.
				$out[ $bucket ][ $hash ] = isset( $out[ $bucket ][ $hash ] )
					? \Newspack_Event_Logger_Nodes\Stats_Store::merge_url_row(
						\Newspack_Nodes\Core::arr( $out[ $bucket ][ $hash ] ),
						\Newspack_Nodes\Core::arr( $row )
					)
					: $row;
			}
		}
		// Named on the way out: a test asserting on a row should read
		// `['count']`. The stored SHAPE is pinned by the one test that reads a
		// shard raw, not by every test that happens to touch a row.
		foreach ( $out as $bucket => $rows ) {
			$out[ $bucket ] = self::named_url_rows( $rows );
		}
		return $out;
	}

	/**
	 * Every URL row of one bucket, merged across shards.
	 *
	 * A test convenience, deliberately not on `Stats_Store`: production reads a
	 * window through `url_row_sources()` and writes one shard at a time, so a
	 * bucket-level accessor there would be API kept alive only by its tests.
	 *
	 * One bucket of the sibling above, rather than its own shard walk: the
	 * overflow rows carry the SAME key in every shard they were folded in, and
	 * a walk that unions instead of folding keeps whichever shard it reached
	 * first and silently under-reports the rest of the tail.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  The store to read.
	 * @param string                                   $bucket Bucket key.
	 * @return array<array-key,mixed>
	 */
	protected function url_bucket_rows( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $bucket ): array {
		return \Newspack_Nodes\Core::arr( $this->url_rows_by_bucket( $store, [ $bucket ] )[ $bucket ] ?? [] );
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
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::$hrtime_fn = static fn (): int => (int) ( \Newspack_Nodes\Core::$now * 1e9 );
		// The harness registers no RESET_ACTION listener, so drop ELN's memo here.
		\Newspack_Event_Logger_Nodes\Config::reset_local_cache();
		// A loaded topology's Tables open their files under the base, as the
		// harness's do; resolved now, while the test's config still reads.
		$this->stats_table_dirs[ \Newspack_Nodes\Bootstrap::base_dir() . '/tables' ] = true;
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
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::$hrtime_fn = null;
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
	 * A Stats_Store over this test's SQLite stats Tables, as the flame builder
	 * holds its own: each Table declared by an active topology, resolved
	 * through `Bootstrap::node_tables()`, written by a Table node in this
	 * process and asked by message. With no topology declaring partition
	 * `$partition`, the shipped `flame-builder` is activated to declare it.
	 *
	 * The store asks through `$asker`'s own client, as `configure_stats`
	 * wires one: the builder is named when it has no name and sunk into the
	 * request graph when it has no sink, so its replies reach its `fill()`.
	 * Without one, every store of a test shares the harness asker, rebuilt
	 * when a registry reset dropped it.
	 *
	 * @param int                     $partition    Flame-builder partition.
	 * @param int                     $max_lifespan Retention window, in seconds.
	 * @param Flame_Builder_Node|null $asker        The builder asking, or the harness.
	 */
	protected function stats_store( int $partition = 0, int $max_lifespan = 86400, ?Flame_Builder_Node $asker = null ): Stats_Store {
		return new Stats_Store( ...$this->stats_store_args( $partition, $max_lifespan, $asker ) );
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
	 * @return array{0: int, 1: \Newspack_Nodes\Table_Client, 2: array<string,string>}
	 */
	protected function stats_store_args( int $partition = 0, int $max_lifespan = 86400, ?Flame_Builder_Node $asker = null ): array {
		$names = [];
		// Every declared partition's worker has run, or a reader cannot mount.
		foreach ( $this->stats_table_specs( $partition ) as $table => $partitions ) {
			foreach ( $partitions as $p => $spec ) {
				$writer = $this->stats_table_writer( $table, $p, $spec );
				if ( $p === $partition ) {
					$names[ $table ] = $writer;
				}
			}
		}
		if ( null === $asker ) {
			return [ $max_lifespan, $this->stats_asker()->client, $names ];
		}
		if ( '' === $asker->name() ) {
			$asker->name( 'flame-builder-' . \spl_object_id( $asker ) );
		}
		if ( null === $asker->sink() ) {
			$asker->sink( VerbHarness::ask_recorder() );
		}
		// The builder's own asker, as `configure_stats` hands it to its store.
		return [ $max_lifespan, ( new \ReflectionProperty( $asker, 'client' ) )->getValue( $asker ), $names ];
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
	 * Each stats Table's partitions, as the active topologies declare them,
	 * `$partition` among them.
	 *
	 * @return array<string,array<int,array{namespace: string, ttl: int, backend: string}>>
	 * @throws \LogicException When an active topology declares the Tables without that partition.
	 */
	private function stats_table_specs( int $partition ): array {
		$declared = \Newspack_Nodes\Bootstrap::node_tables( ...Stats_Store::TABLES );
		if ( ! isset( $declared[ Stats_Store::TABLE_AGGREGATE ][ $partition ] ) ) {
			$active = $GLOBALS['_wp_options']['newspack_nodes_topologies'] ?? [];
			if ( [] !== $active && [ 'flame-builder' ] !== $active ) {
				throw new \LogicException( "the active topologies declare no partition {$partition} of the stats Tables" );
			}
			$this->activate_shipped( 'flame-builder', $partition + 1 );
			$declared = \Newspack_Nodes\Bootstrap::node_tables( ...Stats_Store::TABLES );
		}
		return $declared;
	}

	/**
	 * The Table node writing one partition of a stats Table: opened under the
	 * declared name with `<partition>` bound, as a worker opens it, then
	 * renamed so one process can hold several partitions' writers and a
	 * reader's mount of the same file keeps its own name.
	 *
	 * @param array{namespace: string, ttl: int, backend: string} $spec The resolved declaration.
	 * @return string The writer's node name.
	 */
	private function stats_table_writer( string $table, int $partition, array $spec ): string {
		$name = self::stats_writer( $table, $partition );
		if ( \Newspack_Nodes\Core::node( $name ) instanceof \Newspack_Nodes\Table_Node ) {
			return $name;
		}
		$bound = \array_key_exists( 'partition', \Newspack_Nodes\Core::$var ) ? [ \Newspack_Nodes\Core::$var['partition'] ] : [];
		\Newspack_Nodes\Core::$var['partition'] = (string) $partition;
		$writer = new \Newspack_Nodes\Table_Node();
		try {
			$writer->name( $table );
			$writer->arguments( [ $spec['namespace'], (string) $spec['ttl'], $spec['backend'] ] );
		} catch ( \Throwable $e ) {
			$writer->remove_node();
			throw $e;
		} finally {
			unset( \Newspack_Nodes\Core::$var['partition'] );
			if ( [] !== $bound ) {
				\Newspack_Nodes\Core::$var['partition'] = $bound[0];
			}
		}
		$writer->name( $name );
		$writer->sink( VerbHarness::request_graph() );
		$this->stats_table_dirs[ \dirname( \Newspack_Nodes\Table_Node::file( $table, $partition ) ) ] = true;
		return $name;
	}

	/** The node a harness store writes one partition of a stats Table through. */
	protected static function stats_writer( string $table, int $partition ): string {
		return \Newspack_Nodes\Table_Node::stem( $table, $partition ) . '.writer';
	}

	/**
	 * Break one partition of a stats Table so every read and write of it
	 * fails, until `mend_stats_table()`: the SQLite analogue of a memcached
	 * that does not answer.
	 */
	protected function break_stats_table( string $table, int $partition = 0 ): void {
		( new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( $table, $partition ) ) )->exec( 'ALTER TABLE kv RENAME TO kv_broken' );
	}

	/** Undo `break_stats_table()`, rows intact. */
	protected function mend_stats_table( string $table, int $partition = 0 ): void {
		( new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( $table, $partition ) ) )->exec( 'ALTER TABLE kv_broken RENAME TO kv' );
	}

	/**
	 * What one partition of a stats Table holds under `$key`, read through a
	 * reader's mount of its file: where a write landed, not what the writer
	 * remembers.
	 */
	protected function stats_table_value( string $table, int $partition, string $key ): mixed {
		$stem = \Newspack_Nodes\Bootstrap::mount_table( [ $table ] )[ $table ][ $partition ];
		$node = \Newspack_Nodes\Core::node( $stem );
		self::assertInstanceOf( \Newspack_Nodes\Table_Node::class, $node );
		return $node->lookup( $key );
	}

	/**
	 * Every key a test's askers — its stores and the CI under test — asked of
	 * one namespace, in order, parsed off the recorded `MGET` requests.
	 *
	 * @param string $ns An `NS_*` namespace; '' takes every key.
	 * @param string $to Only the requests sent TO this node; '' takes every one.
	 * @return list<string>
	 */
	protected function asked_keys( string $ns = '', string $to = '' ): array {
		$out = [];
		foreach ( $this->stats_mgets( $to ) as $keys ) {
			foreach ( $keys as $key ) {
				if ( '' === $ns || \str_starts_with( $key, "{$ns}:" ) ) {
					$out[] = $key;
				}
			}
		}
		return $out;
	}

	/**
	 * The `MGET` requests that asked a key of any of `$namespaces`, one list
	 * each of the keys it asked of them.
	 *
	 * @param string ...$namespaces `NS_*` namespaces.
	 * @return list<list<string>>
	 */
	protected function asked_batches( string ...$namespaces ): array {
		$out = [];
		foreach ( $this->stats_mgets() as $keys ) {
			$of = \array_values( \array_filter( $keys, static fn ( string $key ): bool => \in_array( Stats_Store::namespace_of( $key ), $namespaces, true ) ) );
			if ( [] !== $of ) {
				$out[] = $of;
			}
		}
		return $out;
	}

	/**
	 * The requests a test's askers sent naming a key of any of `$namespaces`,
	 * by verb: one list each of the keys it named of them. A structured
	 * request names its item keys, and `SMEMBERS` the set keys after its
	 * limit.
	 *
	 * @param string ...$namespaces `NS_*` namespaces.
	 * @return array<string,list<list<string>>>
	 */
	protected function asked_verbs( string ...$namespaces ): array {
		$out = [];
		foreach ( VerbHarness::ask_recorder()->asked as $asked ) {
			$value = $asked['value'];
			if ( \is_array( $value ) ) {
				$verb = (string) \array_key_first( $value );
				$keys = \array_map( 'strval', \array_keys( \Newspack_Nodes\Core::arr( $value[ $verb ] ) ) );
			} else {
				$keys = \preg_split( '/\s+/', \trim( $value ), -1, \PREG_SPLIT_NO_EMPTY ) ?: [];
				$verb = (string) \array_shift( $keys );
				if ( 'SMEMBERS' === $verb ) {
					\array_shift( $keys );
				}
			}
			$of = \array_values( \array_filter( $keys, static fn ( string $key ): bool => \in_array( Stats_Store::namespace_of( $key ), $namespaces, true ) ) );
			if ( [] !== $of ) {
				$out[ $verb ][] = $of;
			}
		}
		return $out;
	}

	/** How many `MGET` requests a test's askers sent: a read's round trips, one per Table asked. */
	protected function stats_reads(): int {
		return \count( $this->stats_mgets() );
	}

	/** Forget every request the harness recorded, so a test counts from here. */
	protected function forget_stats_asks(): void {
		VerbHarness::ask_recorder()->asked   = [];
		VerbHarness::ask_recorder()->refused = [];
	}

	/**
	 * Answer every `MGET` or `SMEMBERS` asking a key `$pattern` matches with a
	 * read failure, as a Table that did not answer the batch would; '' answers
	 * every one again.
	 *
	 * @param string $pattern A PCRE pattern over one table-relative key, or ''.
	 */
	protected function refuse_stats_reads( string $pattern ): void {
		VerbHarness::ask_recorder()->refuse = $pattern;
	}

	/**
	 * The keys of each recorded `MGET`, one list per request.
	 *
	 * @param string $to Only the requests sent TO this node; '' takes every one.
	 * @return list<list<string>>
	 */
	private function stats_mgets( string $to = '' ): array {
		$out = [];
		foreach ( VerbHarness::ask_recorder()->asked as $asked ) {
			$value = $asked['value'];
			if ( '' !== $to && $to !== $asked['to'] ) {
				continue;
			}
			$words = \is_string( $value ) ? ( \preg_split( '/\s+/', \trim( $value ), -1, \PREG_SPLIT_NO_EMPTY ) ?: [] ) : [];
			if ( 'MGET' === \array_shift( $words ) ) {
				$out[] = $words;
			}
		}
		return $out;
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
	 * The inverse of `positional_url_row()`, for assertions.
	 *
	 * The read helpers below map through it, so a test asserting on a stored
	 * row reads `['count']` rather than counting indexes. The SHAPE is pinned
	 * separately, by the one test that reads a shard raw.
	 *
	 * @param array<array-key,mixed> $row Stored positional row.
	 * @return array<string,mixed>
	 */
	protected static function named_url_row( array $row ): array {
		$names = \Newspack_Event_Logger_Nodes\Stats_Store::ROW_FIELD_NAMES;
		$out   = [];
		foreach ( $row as $index => $value ) {
			$out[ \is_int( $index ) ? ( $names[ $index ] ?? $index ) : $index ] = $value;
		}
		return $out;
	}

	/**
	 * A map of stored rows, named for assertions. See `named_url_row()`.
	 *
	 * @param array<array-key,mixed> $rows Stored rows by hash.
	 * @return array<array-key,array<string,mixed>>
	 */
	protected static function named_url_rows( array $rows ): array {
		return \array_map(
			static fn ( $row ): array => self::named_url_row( \Newspack_Nodes\Core::arr( $row ) ),
			$rows
		);
	}

	/**
	 * Seed one shard of a bucket from NAMED rows, under one server.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $bucket Bucket key.
	 * @param string                                   $shard  Shard name.
	 * @param array<array-key,mixed>                   $rows   Named rows by hash.
	 * @param string                                   $server The rows' server.
	 */
	protected function seed_url_shard( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $bucket, string $shard, array $rows, string $server = self::SEED_SERVER ): bool {
		return $this->set_url_shard( $store, $bucket, $shard, self::store_url_names( $store, $rows, $server ), $server );
	}

	/**
	 * Seed one shard of a coarse hour from NAMED rows, under one server.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $hour   Hour key.
	 * @param string                                   $shard  Shard name.
	 * @param array<array-key,mixed>                   $rows   Named rows by hash.
	 * @param string                                   $server The rows' server.
	 */
	protected function seed_url_hour( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $hour, string $shard, array $rows, string $server = self::SEED_SERVER ): bool {
		return $this->set_url_hour( $store, $hour, $shard, self::store_url_names( $store, $rows, $server ), $server );
	}

	/**
	 * File the search tokens of named paths under one server, as the flush
	 * does: one member per word per URL, stamped with the tick.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param array<string,string>                     $paths  hash => path.
	 * @param string                                   $server The server the URLs are filed under.
	 */
	protected function set_url_tokens( \Newspack_Event_Logger_Nodes\Stats_Store $store, array $paths, string $server = self::SEED_SERVER ): bool {
		$sets = [];
		foreach ( \Newspack_Event_Logger_Nodes\Stats_Store::token_sets_of( $paths ) as $token => $hashes ) {
			$sets[] = [ \Newspack_Event_Logger_Nodes\Stats_Store::server_key( $server ), (string) $token, $hashes ];
		}
		return ! \in_array( false, $store->add_url_tokens( $sets, self::tick() ), true );
	}

	/**
	 * File hashes under one word of one server, as the flush adds them.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $word   The word, as `term_tokens()` spells it.
	 * @param list<string>                             $hashes URL hashes.
	 * @param string                                   $server The server the URLs are filed under.
	 */
	protected function file_url_token( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $word, array $hashes, string $server = self::SEED_SERVER ): bool {
		return [ true ] === $store->add_url_tokens( [ [ \Newspack_Event_Logger_Nodes\Stats_Store::server_key( $server ), $word, $hashes ] ], self::tick() );
	}

	/**
	 * Saturate one word of one server: file one hash past `URL_SEARCH_MAX`,
	 * none of them a row, so the word narrows no search.
	 */
	protected function saturate_url_token( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $word, string $server = self::SEED_SERVER ): bool {
		$hashes = [];
		for ( $i = 0; $i <= \Newspack_Event_Logger_Nodes\Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$hashes[] = \sprintf( 'f%011x', $i );
		}
		return $this->file_url_token( $store, $word, $hashes, $server );
	}

	/**
	 * Route each named row's `url` to the name table and its path onto the
	 * row, as the flush does, and store the rest.
	 *
	 * A test still says what it means — `'url' => …` beside the counts — and
	 * this puts each half where production does: the whole URL once in
	 * `Stats_Store::NS_URLMAP`, the path the server's key does not imply on
	 * the row.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param array<array-key,mixed>                   $rows   Named rows by hash.
	 * @param string                                   $server The rows' server.
	 * @return array<array-key,array<int,mixed>>
	 */
	private static function store_url_names( \Newspack_Event_Logger_Nodes\Stats_Store $store, array $rows, string $server ): array {
		$names = [];
		$out   = [];
		foreach ( $rows as $hash => $row ) {
			$row = \Newspack_Nodes\Core::arr( $row );
			if ( isset( $row['url'] ) ) {
				$url                     = \Newspack_Nodes\Core::str( $row['url'] );
				$names[ (string) $hash ] = $url;
				$row['path']           ??= \Newspack_Event_Logger_Nodes\Stats_Store::row_path( $url, $server );
				unset( $row['url'] );
			}
			$out[ $hash ] = self::positional_url_row( $row );
		}
		$store->set_url_names( [ $server => $names ] );
		return $out;
	}

	/**
	 * Merge `$server` into one bucket's or hour's server index, as the flush
	 * files it, so an unscoped read finds the rows seeded under it: the
	 * shards named are the ones the seed wrote rows into.
	 *
	 * @param list<string> $shards Shard tokens the seed filed rows in.
	 */
	private static function index_server( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $key, string $server, bool $hour, array $shards ): bool {
		$parts = \Newspack_Event_Logger_Nodes\Stats_Store::url_srv_parts( $hour );
		$index = \Newspack_Event_Logger_Nodes\Stats_Store::merge_index(
			\Newspack_Event_Logger_Nodes\Stats_Store::index_entries( $store->bucket_get_multi( [ [ $parts, $key ] ] )[0] ?? [] ),
			[
				\Newspack_Event_Logger_Nodes\Stats_Store::server_key( $server ) => [
					\Newspack_Event_Logger_Nodes\Stats_Store::SRV_NAME   => $server,
					\Newspack_Event_Logger_Nodes\Stats_Store::SRV_SHARDS => \Newspack_Event_Logger_Nodes\Stats_Store::shard_mask( $shards ),
				],
			]
		);
		return $store->bucket_set_multi( [ [ $parts, $key, $index ] ] )[0];
	}

	/**
	 * A stored URL row from a NAMED one, so a test can say what it means.
	 *
	 * Storage is positional (`Stats_Store::ROW_*`) because `serialize()` writes
	 * every key name into every row; a test seeding one should not have to
	 * count indexes to stay readable. Reverses `ROW_FIELD_NAMES`, so it cannot
	 * drift from the shape it seeds. A row already positional passes through.
	 *
	 * @param array<array-key,mixed> $row Named row, or an already-stored one.
	 * @return array<int,mixed>
	 */
	protected static function positional_url_row( array $row ): array {
		$index = \array_flip( \Newspack_Event_Logger_Nodes\Stats_Store::ROW_FIELD_NAMES );
		$out   = [];
		foreach ( $row as $field => $value ) {
			if ( \is_int( $field ) ) {
				$out[ $field ] = $value;
				continue;
			}
			// The name is not part of the row, and passes through under its own
			// key so `store_url_names()` can file it however deep the call is.
			if ( 'url' === $field ) {
				$out['url'] = $value;
				continue;
			}
			if ( ! isset( $index[ $field ] ) ) {
				throw new \RuntimeException( "no such URL row field: {$field}" );
			}
			$out[ $index[ $field ] ] = $value;
		}
		return $out;
	}

	/**
	 * Seed a whole URL bucket for one server, routing each row to the shard
	 * its hash names.
	 *
	 * A test convenience: production writes one shard at a time, which is the
	 * point of sharding. Writes EVERY shard of the server, because "replace
	 * the bucket" has to clear the ones this data does not reach.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $bucket Bucket key.
	 * @param array<array-key,mixed>                   $data   Whole bucket.
	 * @param string                                   $server The rows' server.
	 * @return bool True when every shard's set landed.
	 */
	protected function set_url_bucket( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $bucket, array $data, string $server = self::SEED_SERVER ): bool {
		$urls = [];
		foreach ( $data as $hash => $row ) {
			// A row seeded with no URL files no words, as no name names it.
			if ( isset( \Newspack_Nodes\Core::arr( $row )['url'] ) ) {
				$urls[ $hash ] = \Newspack_Nodes\Core::str( \Newspack_Nodes\Core::arr( $row )['url'] );
			}
		}
		$ok   = $this->set_url_tokens( $store, \Newspack_Event_Logger_Nodes\Stats_Store::paths_of( $urls ), $server );
		$data = self::store_url_names( $store, $data, $server );
		// Worker rows go to the worker shard family, as the writer files them.
		$split = [ false => [], true => [] ];
		foreach ( $data as $hash => $row ) {
			$row_arr = \Newspack_Nodes\Core::arr( $row );
			$split[ ! empty( $row_arr[ \Newspack_Event_Logger_Nodes\Stats_Store::ROW_WORKER ] ) ][ $hash ] = $row;
		}
		foreach ( [ false, true ] as $worker ) {
			$by_shard = \Newspack_Event_Logger_Nodes\Stats_Store::rows_by_shard( $split[ $worker ], $worker );
			foreach ( \Newspack_Event_Logger_Nodes\Stats_Store::url_shards( $worker ) as $shard ) {
				$ok = $this->set_url_shard( $store, $bucket, $shard, \Newspack_Nodes\Core::arr( $by_shard[ $shard ] ?? null ), $server ) && $ok;
			}
		}
		return $ok;
	}

	/**
	 * Seed every ranked list of one server for one bucket or hour from NAMED
	 * rows, through the production ranker, and name the server in the key's
	 * index so the site's merge reads it. The rows are the LIST's content,
	 * which a test may deliberately seed apart from the stored rows. The
	 * site's lists are that one server's: `set_url_rank_lists_of()` ranks a
	 * key's servers together, as the writer does.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $key    Bucket or hour key.
	 * @param array<array-key,mixed>                   $rows   Named rows by hash, `url` included.
	 * @param bool                                     $hour   The coarse tier.
	 * @param string                                   $server Reporting server.
	 */
	protected function set_url_rank_lists( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $key, array $rows, bool $hour = false, string $server = self::SEED_SERVER ): bool {
		return $this->set_url_rank_lists_of( $store, $key, [ $server => $rows ], $hour );
	}

	/**
	 * Seed every ranked list of several servers for one key from NAMED rows,
	 * ranked together as one ranking of the key writes them, the site's
	 * lists and record included, and name each server in the key's index.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store     Destination.
	 * @param string                                   $key       Bucket or hour key.
	 * @param array<string,array<array-key,mixed>>     $by_server Server => named rows by hash.
	 * @param bool                                     $hour      The coarse tier.
	 */
	protected function set_url_rank_lists_of( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $key, array $by_server, bool $hour = false ): bool {
		$servers = [];
		foreach ( $by_server as $server => $rows ) {
			foreach ( $rows as $hash => $row ) {
				$row         = \Newspack_Nodes\Core::arr( $row );
				$row['path'] = isset( $row['url'] ) ? \Newspack_Event_Logger_Nodes\Stats_Store::row_path( \Newspack_Nodes\Core::str( $row['url'] ), (string) $server ) : '';
				unset( $row['url'] );
				$servers[ $server ][ $hash ] = self::positional_url_row( $row );
			}
			$servers[ $server ] ??= [];
		}
		$writes = \Newspack_Event_Logger_Nodes\Stats_Store::ranked_writes( self::by_shard( $servers ), $hour, $key );
		// As the writer does: each server's DONE marker for the hour beside its lists.
		foreach ( $hour ? \array_keys( $servers ) : [] as $server ) {
			$writes[] = [ \Newspack_Event_Logger_Nodes\Stats_Store::url_rank_done_parts( \Newspack_Event_Logger_Nodes\Stats_Store::server_key( (string) $server ) ), $key, [] ];
		}
		$ok = ! \in_array( false, $store->bucket_set_multi( $writes ), true );
		foreach ( \array_keys( $servers ) as $server ) {
			$ok = self::index_server( $store, $key, (string) $server, $hour, [] ) && $ok;
		}
		return $ok;
	}

	/**
	 * Each server's stored rows as the shard maps `ranked_writes()` takes,
	 * every row in the shard its hash and its family name, as the flush files it.
	 *
	 * @param array<array-key,array<array-key,mixed>> $servers Server => stored rows by hash.
	 * @return array<array-key,array<array-key,array<array-key,mixed>>>
	 */
	protected static function by_shard( array $servers ): array {
		$out = [];
		foreach ( $servers as $server => $rows ) {
			$out[ $server ] = [];
			foreach ( $rows as $hash => $row ) {
				$worker = ! empty( \Newspack_Nodes\Core::arr( $row )[ \Newspack_Event_Logger_Nodes\Stats_Store::ROW_WORKER ] ?? null );
				$out[ $server ][ \Newspack_Event_Logger_Nodes\Stats_Store::url_shard( (string) $hash, $worker ) ][ $hash ] = $row;
			}
		}
		return $out;
	}

	// ── Stats_Store one key at a time ───────────────────────────────────────
	//
	// Production reads and writes in batches; a test seeds or reads one key,
	// over the parts the batch pair takes.

	/**
	 * Overwrite one shard's rows for one coarse hour.
	 *
	 * An EMPTY hour is still written: a missing key means "not rolled up yet",
	 * which is what sends the reader back to the twelve fine buckets.
	 *
	 * @param array<array-key,mixed> $rows The hour's merged rows.
	 */
	protected function set_url_hour( Stats_Store $store, string $hour, string $shard, array $rows, string $server = self::SEED_SERVER ): bool {
		$ok = $store->bucket_set_multi( [ [ Stats_Store::url_hour_parts( Stats_Store::server_key( $server ), $shard ), $hour, $rows ] ] )[0];
		$ok = self::set_url_row_values( $store, $hour, $shard, $rows, $server ) && $ok;
		return self::index_server( $store, $hour, $server, true, [ $shard ] ) && $ok;
	}

	/**
	 * File each row's `url_row_h` value beside its shard, as the flush does:
	 * a bucket's row in its slot of the hour, the path kept once. An hour's
	 * row replaces its family's slots as their one sum, which is what an
	 * hour seeded coarse stands for. The overflow rows name no URL.
	 *
	 * @param string                 $key    Bucket or hour key.
	 * @param string                 $shard  The shard the rows are filed in, which names their family.
	 * @param array<array-key,mixed> $rows   Stored rows by hash.
	 * @param string                 $server The rows' server.
	 */
	private static function set_url_row_values( Stats_Store $store, string $key, string $shard, array $rows, string $server ): bool {
		$hour  = Stats_Store::hour_of( $key );
		$reads = [];
		foreach ( \array_keys( $rows ) as $hash ) {
			if ( ! Stats_Store::is_other_key( (string) $hash ) ) {
				$reads[ (string) $hash ] = [ Stats_Store::url_row_parts( Stats_Store::server_key( $server ), (string) $hash ), $hour ];
			}
		}
		$held   = [] === $reads ? [] : $store->bucket_get_multi( $reads );
		$family = Stats_Store::url_row_family( Stats_Store::is_worker_shard( $shard ) );
		$writes = [];
		foreach ( $reads as $hash => [ $parts ] ) {
			$row   = \Newspack_Nodes\Core::arr( $rows[ $hash ] ?? null );
			$value = $held[ $hash ] ?? [];
			$value[ Stats_Store::URL_ROW_PATH ] = \Newspack_Nodes\Core::str( $row[ Stats_Store::ROW_PATH ] ?? '' );
			unset( $row[ Stats_Store::ROW_PATH ] );
			$value[ $family ] = $key === $hour
				? [ 0 => $row ]
				: [ Stats_Store::slot_of( $key ) => $row ] + \Newspack_Nodes\Core::arr( $value[ $family ] ?? null );
			$writes[] = [ $parts, $hour, $value ];
		}
		return ! \in_array( false, $store->bucket_set_multi( $writes ), true );
	}

	/**
	 * Overwrite one URL's stats blob, under the shorter per-URL TTL.
	 *
	 * @param array<string,mixed> $data Whole blob.
	 */
	protected function set_url_stats( Stats_Store $store, string $url_hash, array $data ): bool {
		return $store->bucket_set_multi( [ [ [ Stats_Store::NS_URL ], $url_hash, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_url_hour( Stats_Store $store, string $hour, string $shard, string $server = self::SEED_SERVER ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_hour_parts( Stats_Store::server_key( $server ), $shard ), $hour ] ] )[0] ?? [];
	}

	/**
	 * One stored category entry, named at the seed so a test never counts
	 * indexes — decision 18's `CAT_SUMS` triple.
	 *
	 * @param float|int $ms       Milliseconds of wall time.
	 * @param int       $calls    Events fired.
	 * @param int       $requests Requests the category appeared in.
	 * @return array<int,float|int>
	 */
	protected static function cat_entry( float|int $ms, int $calls, int $requests ): array {
		return [
			Stats_Store::CAT_MS       => $ms,
			Stats_Store::CAT_CALLS    => $calls,
			Stats_Store::CAT_REQUESTS => $requests,
		];
	}

	/**
	 * One stored dimensional entry, named at the seed so a test never counts
	 * indexes — decision 18's `DIM_SUMS` row.
	 *
	 * @param int       $count Requests the value appeared in.
	 * @param float|int $ms    Summed milliseconds of the timed ones.
	 * @param float|int $peak  Summed peak MB.
	 * @param ?int      $timed Requests whose duration was a sample; null times every one.
	 * @return array<int,float|int>
	 */
	protected static function dim_entry( int $count, float|int $ms = 0, float|int $peak = 0, ?int $timed = null ): array {
		return [
			Stats_Store::DIM_COUNT       => $count,
			Stats_Store::DIM_SUM_MS      => $ms,
			Stats_Store::DIM_SUM_PEAK_MB => $peak,
			Stats_Store::DIM_TIMED       => $timed ?? $count,
		];
	}

	/**
	 * Place one bucket's value in its slot of a slotted hour value, as the
	 * flush places it (decision 35): read the hour, set the slot, write the
	 * hour back.
	 *
	 * @param array<int,string>   $parts     A slotted namespace's key parts.
	 * @param array<string,mixed> $data      The slot's value.
	 * @param ?string             $dimension The dimension of a `url_dim_parts()` row.
	 */
	protected function set_hour_slot( Stats_Store $store, array $parts, string $bucket, array $data, ?string $dimension = null ): bool {
		$hour  = Stats_Store::hour_of( $bucket );
		$value = $store->bucket_get_multi( [ [ $parts, $hour ] ] )[0] ?? [];
		if ( null === $dimension ) {
			$value[ Stats_Store::slot_of( $bucket ) ] = $data;
		} else {
			$value[ $dimension ][ Stats_Store::slot_of( $bucket ) ] = $data;
		}
		return $store->bucket_set_multi( [ [ $parts, $hour, $value ] ] )[0];
	}

	/**
	 * One bucket's slot of a slotted hour value; [] where the hour or the
	 * slot is absent.
	 *
	 * @param array<int,string> $parts     A slotted namespace's key parts.
	 * @param ?string           $dimension The dimension of a `url_dim_parts()` row.
	 * @return array<string,mixed>
	 */
	protected function get_hour_slot( Stats_Store $store, array $parts, string $bucket, ?string $dimension = null ): array {
		$value = $store->bucket_get_multi( [ [ $parts, Stats_Store::hour_of( $bucket ) ] ] )[0] ?? [];
		$hour  = null === $dimension ? $value : \Newspack_Nodes\Core::arr( $value[ $dimension ] ?? null );
		return Stats_Store::string_keys( \Newspack_Nodes\Core::arr( $hour[ Stats_Store::slot_of( $bucket ) ] ?? null ) );
	}

	/** @param array<string,mixed> $data The hour's whole sum. */
	protected function set_leaderboard_hour( Stats_Store $store, string $hour, array $data, string $server = '' ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::lb_parts( $server ), $hour, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_leaderboard_hour( Stats_Store $store, string $hour, string $server = '' ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::lb_parts( $server ), $hour ] ] )[0] ?? [];
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

	/** @return array<string,mixed> */
	protected function get_url_shard( Stats_Store $store, string $bucket, string $shard, string $server = self::SEED_SERVER ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_shard_parts( Stats_Store::server_key( $server ), $shard ), $bucket ] ] )[0] ?? [];
	}

	/**
	 * Overwrite one server's shard of a bucket, and name the server in the
	 * bucket's index as the flush does.
	 *
	 * @param array<array-key,mixed> $rows
	 */
	protected function set_url_shard( Stats_Store $store, string $bucket, string $shard, array $rows, string $server = self::SEED_SERVER ): bool {
		$ok = $store->bucket_set_multi( [ [ Stats_Store::url_shard_parts( Stats_Store::server_key( $server ), $shard ), $bucket, $rows ] ] )[0];
		$ok = self::set_url_row_values( $store, $bucket, $shard, $rows, $server ) && $ok;
		return self::index_server( $store, $bucket, $server, false, [] === $rows ? [] : [ $shard ] ) && $ok;
	}
}
