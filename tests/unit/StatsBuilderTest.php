<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Stats_Query;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Builder_Node;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\Capture_Sink_Node;

/**
 * The stats builder folds each flame's requests record into the
 * `flame-facts` Row_Table: the record is read where the flame's
 * `request_at` names it in `requests.p{N}`, and becomes one `requests` row
 * naming its URL, server and agent, with its hourly and timing rollups and
 * its share of the category window, written in one ROWS batch per flush.
 *
 * The category window equals timings_site summed over the last
 * `Bucket::window()` buckets, across adds, expiries, replays and restarts;
 * each process rebuilds it before its first fold.
 */
#[CoversClass( Stats_Builder_Node::class )]
final class StatsBuilderTest extends TestCase {

	use FactsSeeder;

	/** A tick 30 seconds into its bucket, where the window tests run. */
	private const NOW = 1_790_006_430;

	/** The narration categories, every one the builder writes. */
	private const NARRATION = [
		Flame_Tree::STATS_WRITES,
		Flame_Tree::STATS_SWEEP . ' (start)',
		Flame_Tree::STATS_SWEEP . ' (complete)',
	];

	/** A worker's partition is bound before its graph builds; a record names it. */
	protected function setUp(): void {
		parent::setUp();
		Core::$var['partition'] = '0';
	}

	/**
	 * A builder constructed bare, as no topology arms one, naming the facts
	 * table whose file holds a current window, so a fold rebuilds nothing.
	 */
	private function unarmed_builder(): Stats_Builder_Node {
		$this->current_window_facts();
		$sb = new Stats_Builder_Node();
		$sb->set_facts_target( self::FACTS_TABLE );
		$sb->set_requests_partition( $this->requests_partition() );
		return $sb;
	}

	/**
	 * Read the node's introspection payload through the production GET_STATS
	 * request verb — the same path the dashboard and the REPL read.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stats( Stats_Builder_Node $sb ): array {
		$prev    = $sb->sink();
		$capture = new Capture_Sink_Node();
		$sb->sink( $capture );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'test-probe';
		$message[ Message::VALUE ] = 'GET_STATS';
		$sb->fill( $message );

		$sb->sink( $prev );

		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_RESPONSE ) && ( $type & Message::TM_STRUCT ) ) {
				return $captured[ Message::VALUE ]['data'];
			}
		}
		$this->fail( 'GET_STATS reply not captured' );
	}

	public function test_constructor_initializes_empty(): void {
		$sb    = $this->unarmed_builder();
		$stats = $this->get_stats( $sb );
		$this->assertSame( 0, $stats['buffered_requests'] );
		$this->assertSame( 0, $stats['records_gone'] );
	}

	public function test_a_builder_with_nothing_pending_is_idle_since_its_last_flush(): void {
		Core::$now = 1790000123.0;
		$sb        = $this->unarmed_builder();
		$this->assertSame( 1790000123.0, $sb->idle_since() );
	}

	/** A buffered record not yet written lives in this process alone. */
	public function test_a_builder_holding_a_buffered_record_is_busy(): void {
		Core::$now = 1790000417.0;
		$sb        = $this->unarmed_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://example.com/kakapo-3391', 'timestamp' => 1790000417 ] ) );
		$this->assertNull( $sb->idle_since() );
	}

	public function test_non_array_value_skipped(): void {
		$sb                        = $this->unarmed_builder();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = 'not-an-array';
		$sb->fill( $message );
		$this->assertSame( 0, $this->get_stats( $sb )['buffered_requests'] );
	}

	public function test_non_struct_message_skipped(): void {
		$sb                        = $this->unarmed_builder();
		$message                   = self::flame_at( $this->completed_request(), $this->record_request( $this->completed_request() ) );
		$message[ Message::TYPE ]  = Message::TM_INFO;
		$sb->fill( $message );
		$this->assertSame( 0, $this->get_stats( $sb )['buffered_requests'] );
	}

	/**
	 * A write the record path runs is the node's work, not the record's: a
	 * Row_Table that refuses it raises a plain stop carrying the refusal, so
	 * the reader neither dead-letters the flame nor commits past it, and the
	 * successor replays from it.
	 */
	public function test_a_refused_write_at_the_buffer_limit_stops_the_reader_on_its_flame(): void {
		$tmp   = $this->make_temp_dir( 'stats-builder-drain-' );
		$limit = Stats_Builder_Node::BUFFERED_REQUESTS_LIMIT;
		$topic = new Topic_Node();
		// One segment holds every flame, so the cursor reads as one offset.
		$topic->arguments( [ "{$tmp}/flames.p{partition}", '1', '4194304' ] );
		for ( $i = 1; $i <= $limit + 3; $i++ ) {
			$request = $this->completed_request( [ 'rid' => "r-ibis-{$i}", 'url' => "https://example.com/ibis-{$i}", 'duration_ms' => 53.0 ] );
			$topic->fill( self::flame_at( $request, $this->record_request( $request ) ) );
		}
		$topic->flush();

		$sb = $this->armed_stats_builder();
		$this->break_facts();
		$consumer = new Consumer_Node();
		$consumer->arguments( [ "{$tmp}/flames.p0", "{$tmp}/offsets/sb/p0", "{$tmp}/deadletter/sb/p0" ] );
		$consumer->name( 'flames:consumer' );
		$consumer->sink( $sb );

		$thrown = null;
		try {
			$this->pump_consumer( $consumer );
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'the refused write stops the worker' );
		$this->assertFalse( Worker_Should_Stop::is_clean( $thrown ), 'the reader must replay the flame' );
		$this->assertStringContainsString( 'requests', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
		$segments = \glob( "{$tmp}/flames.p0/*.log" ) ?: [];
		$this->assertCount( 1, $segments );
		$lines  = \array_slice( \file( $segments[0] ) ?: [], 0, $limit - 1 );
		$offset = \array_sum( \array_map( 'strlen', $lines ) );
		$this->assertSame(
			\Newspack_Nodes\Log_Position::format( (int) \basename( $segments[0], '.log' ), $offset, null ),
			$consumer->cursor_position(),
			'the cursor stands on the flame that reached the limit'
		);
	}

	/**
	 * A clean stop writes the buffered rows.
	 *
	 * An on-demand worker spawns on a backlog, folds it within the second
	 * and idles out, rarely seeing the tick that writes. The substrate calls
	 * `shutdown_sweep()` on every clean stop, so that is where they land.
	 */
	public function test_a_clean_stop_writes_the_buffered_rows(): void {
		$sb = $this->armed_stats_builder();

		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-signup-1132', 'url' => 'https://example.com/signup', 'duration_ms' => 1132.0 ] ) );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'a record inside the flush window stays buffered' );

		$this->assertInstanceOf( \Newspack_Nodes\Shutdown_Sweeper::class, $sb );
		$sb->shutdown_sweep();

		$this->assertSame( [ [ 'rid-signup-1132', 1132.0 ] ], $this->facts( 'SELECT rid, ms FROM requests' ) );
		$this->assertSame( [ [ 1 ] ], $this->facts( 'SELECT count FROM requests_hourly' ), 'the hourly rollup landed with it' );
	}

	/**
	 * Records a request line always fills in, broken one field at a time.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function records_refused_at_intake(): array {
		return [
			'naming no host'     => [ [ 'url' => '/unknown-7731' ], '/unknown-7731' ],
			'carrying no method' => [ [ 'url' => 'https://kea-7713.test/tui-7731', 'request_method' => '' ], 'no method' ],
		];
	}

	/**
	 * A record whose URL names no host, or that carries no method, throws at
	 * the fold's intake, before anything of it is buffered, and the builder
	 * folds the next.
	 *
	 * @param array<string,mixed> $overrides What breaks the record.
	 * @param string              $refusal   What the throw names.
	 */
	#[DataProvider( 'records_refused_at_intake' )]
	public function test_a_record_refused_at_intake_throws_before_anything_is_folded( array $overrides, string $refusal ): void {
		$sb = $this->armed_stats_builder();

		try {
			$this->fill_flame( $sb, $this->completed_request( [ 'timestamp' => self::tick() ] + $overrides ) );
			$this->fail( 'the record is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( $refusal, $e->getMessage() );
		}
		$this->assertSame( 0, $this->get_stats( $sb )['buffered_requests'], 'nothing is buffered' );
		$this->assertSame( [], $sb->save_state(), 'the checkpoint carries nothing' );

		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-kea-7731', 'url' => 'https://kea-7713.test/kea-7731', 'duration_ms' => 29.0, 'timestamp' => self::tick() ] ) );
		$sb->flush();
		$this->assertSame( [ [ 'rid-kea-7731' ] ], $this->facts( 'SELECT rid FROM requests' ), 'the next is written' );
	}

	/**
	 * The rows a request contributes must not depend on whether
	 * Request_Builder happened to fold it: a leaderboard that shifts under
	 * load is worse than one that stops.
	 */
	public function test_a_folded_request_contributes_the_same_stats_as_an_unfolded_one(): void {
		$now     = self::tick();
		$origin  = (float) $now;
		$entries = [
			[ 'k' => 'process (start)', 'ts' => $origin ],
		];
		foreach ( [ 7.0, 13.0, 5.0 ] as $i => $ms ) {
			$entries[] = [ 'k' => 'save (start)', 'ts' => $origin + ( $i * 0.05 ) ];
			$entries[] = [ 'k' => 'save (complete)', 'ts' => $origin + ( $i * 0.05 ) + $ms / 1000, 'duration_ms' => $ms ];
		}
		$entries[] = [ 'k' => 'db (start)', 'ts' => $origin + 0.2 ];
		$entries[] = [ 'k' => 'db (complete)', 'ts' => $origin + 0.24, 'duration_ms' => 40.0 ];
		$entries[] = [ 'k' => 'process (complete)', 'ts' => $origin + 0.3, 'duration_ms' => 300.0 ];

		$fold = Flame_Fold::start( $origin );
		foreach ( $entries as $entry ) {
			Flame_Fold::add( $fold, $entry );
		}

		$base = [
			'duration_ms' => 300.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.4, 'count' => 12, 'ts' => $now, 'entries' => [] ] ],
		];

		$plain  = $this->stats_for( \array_replace( $base, [ 'entries' => $entries ] ), 'plain-5521.test' );
		$rolled = $this->stats_for(
			\array_replace(
				$base,
				[
					'entries' => [],
					'flame'   => Flame_Fold::tree( $fold ),
					'folded'  => true,
				]
			),
			'rolled-5521.test'
		);

		$this->assertSame( $plain, $rolled );
	}

	/**
	 * Every row one request writes, for the parity comparison above. Same
	 * clock both runs, each under a host of its own, so any difference
	 * comes from the fold.
	 *
	 * @param array<string,mixed> $request Completed-request record.
	 * @param string              $host    The run's host.
	 * @return array<string,list<list<mixed>>> The rows, by table.
	 */
	private function stats_for( array $request, string $host ): array {
		Core::node( 'sb' )?->remove_node();
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => "https://{$host}/fold-5521" ] + $request ) );
		$sb->flush();

		return [
			'request' => $this->facts( "SELECT r.bucket, r.status, r.ms, r.peak_mb, r.worker FROM requests r JOIN urls u ON u.id = r.url_id JOIN servers s ON s.id = u.server_id WHERE s.name = '{$host}'" ),
			'timings' => $this->facts( "SELECT c.name, e.name, t.listener, t.ms, t.calls, t.requests FROM timings t JOIN servers s ON s.id = t.server_id JOIN categories c ON c.id = t.category_id JOIN entries e ON e.id = t.entry_id WHERE s.name = '{$host}' ORDER BY c.name, e.name" ),
			'hourly'  => $this->facts( "SELECT h.count, h.timed, h.sum_ms, h.max_ms, h.errors FROM requests_hourly h JOIN servers s ON s.id = h.server_id WHERE s.name = '{$host}'" ),
		];
	}

	/** A flush with nothing buffered asks no Row_Table, named or not. */
	public function test_a_flush_with_nothing_buffered_asks_nothing(): void {
		$sb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$sb->name( 'sb' );
		$sb->sink( $capture );

		$sb->flush();

		$this->assertSame( [], $capture->captured );
	}

	public function test_stats_builder_constructs_sibling_interpreter(): void {
		$sb = $this->unarmed_builder();
		$sb->name( 'sb' );
		$this->assertSame( 'sb:config', $this->read_private( $sb, 'interpreter' )->name() );
	}

	public function test_the_facts_target_verb_round_trips(): void {
		// What a dump owes is REPLAY, not a literal: the argument it emits has
		// to come back through the same verb as the same state.
		$sb = $this->unarmed_builder();
		$sb->name( 'sb' );
		$this->assertSame( "ok\n", $this->read_private( $sb, 'interpreter' )->dispatch( 'set_facts_target', [ 'facts-kea-4471' ] ) );
		$dump = $sb->dump_config();
		$this->assertMatchesRegularExpression( '/command_node sb:config set_facts_target (\S+)/', $dump );

		\preg_match( '/command_node sb:config set_facts_target (\S+)/', $dump, $m );
		$replayed = $this->unarmed_builder();
		$replayed->name( 'sb2' );
		$this->read_private( $replayed, 'interpreter' )->dispatch( 'set_facts_target', [ $m[1] ] );
		$this->assertSame( 'facts-kea-4471', $this->read_private( $replayed, 'facts_target' ), 'the dumped argument replays' );
	}

	/**
	 * The requests partition dumps as it was given, `{partition}` and all,
	 * and the replayed builder reads its bound partition's records through it.
	 */
	public function test_the_requests_partition_verb_round_trips_through_dump_config(): void {
		$root = $this->make_temp_dir( 'stats-requests-kokako-' );
		$sb   = $this->unarmed_builder();
		$sb->name( 'sb' );
		$this->read_private( $sb, 'interpreter' )->dispatch( 'set_requests_partition', [ "{$root}/requests.p{partition}" ] );
		$dump = $sb->dump_config();
		\preg_match( '/command_node sb:config set_requests_partition (\S+)/', $dump, $m );
		$this->assertSame( "{$root}/requests.p{partition}", $m[1] ?? null );
		$sb->remove_node();
		// The facts table opened on partition 0; partition 3 opens its own.
		Core::node( self::FACTS_TABLE )?->remove_node();

		$replayed = $this->armed_stats_builder( '3' );
		// As a worker replays the dump: no user is current.
		unset( $GLOBALS['_current_user_id'] );
		$this->read_private( $replayed, 'interpreter' )->dispatch( 'set_requests_partition', [ $m[1] ] );
		$request = $this->completed_request( [ 'rid' => 'rid-kokako-3', 'url' => 'https://kea.example/kokako-3' ] );
		$log     = new \Newspack_Nodes\Partition_Node();
		$log->arguments( [ "{$root}/requests.p3" ] );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = 'rid-kokako-3';
		$message[ Message::VALUE ] = $request;
		$log->fill( $message );
		$log->remove_node();
		$length = \filesize( "{$root}/requests.p3/0.log" );
		$replayed->fill( self::flame_at( $request, "0:0:{$length}" ) );
		$replayed->flush();

		$this->assertSame( [ [ 'rid-kokako-3', 3 ] ], $this->facts( 'SELECT rid, partition FROM requests', '3' ), 'the path resolved at the bound partition' );
	}

	/** Naming another requests partition reads from it, not from the first. */
	public function test_a_renamed_requests_partition_is_read_from_then_on(): void {
		$sb    = $this->armed_stats_builder();
		$first = $this->completed_request( [ 'rid' => 'rid-tieke-41', 'url' => 'https://kea.example/tieke-41' ] );
		$this->fill_flame( $sb, $first );

		$root = $this->make_temp_dir( 'stats-requests-tieke-' );
		$sb->set_requests_partition( "{$root}/requests.p{partition}" );
		$second = $this->completed_request( [ 'rid' => 'rid-tieke-42', 'url' => 'https://kea.example/tieke-42-' . \str_repeat( 'x', 40 ) ] );
		$log    = new \Newspack_Nodes\Partition_Node();
		$log->arguments( [ "{$root}/requests.p0" ] );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = 'rid-tieke-42';
		$message[ Message::VALUE ] = $second;
		$log->fill( $message );
		$log->remove_node();
		$sb->fill( self::flame_at( $second, '0:0:' . \filesize( "{$root}/requests.p0/0.log" ) ) );
		$sb->flush();

		$this->assertSame( [ [ 'rid-tieke-41' ], [ 'rid-tieke-42' ] ], $this->facts( 'SELECT rid FROM requests ORDER BY rid' ) );
	}

	/** A flame arriving before any requests partition is named stops the worker, naming the verb. */
	public function test_a_flame_with_no_requests_partition_named_raises_a_plain_stop(): void {
		$this->current_window_facts();
		$sb = new Stats_Builder_Node();
		$sb->set_facts_target( self::FACTS_TABLE );
		$request = $this->completed_request( [ 'rid' => 'rid-pateke-1' ] );

		$thrown = null;
		try {
			$sb->fill( self::flame_at( $request, $this->record_request( $request ) ) );
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertStringContainsString( 'set_requests_partition', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertSame( 0, $this->get_stats( $sb )['records_gone'], 'nothing is counted gone' );
	}

	/** Removing the builder releases its private requests reader. */
	public function test_remove_node_releases_the_requests_reader(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hihi-77' ] ) );
		$reader = \WeakReference::create( $this->read_private( $sb, 'requests' ) );
		$this->assertNotNull( $reader->get(), 'the fold built the reader' );

		$sb->remove_node();
		\gc_collect_cycles();

		$this->assertNull( $reader->get() );
	}

	/** The reader is private: it takes no name in the node table. */
	public function test_the_requests_reader_is_never_published(): void {
		$sb     = $this->armed_stats_builder();
		$before = \array_keys( Core::$nodes_by_name );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hihi-78' ] ) );

		$this->assertSame( $before, \array_keys( Core::$nodes_by_name ) );
	}

	/** The Row_Table the verb named is drawn as an edge the builder writes along. */
	public function test_the_facts_target_is_a_display_target(): void {
		$sb = $this->unarmed_builder();
		$sb->name( 'sb' );
		$this->read_private( $sb, 'interpreter' )->dispatch( 'set_facts_target', [ 'facts-kea-4472' ] );

		$this->assertContains( 'facts-kea-4472', $sb->display_targets() );
	}

	public function test_stats_builder_node_schema_declares_verbs(): void {
		$schema = Stats_Builder_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$this->assertSame( [ 'set_facts_target', 'set_requests_partition' ], \array_column( $schema['commands'], 'name' ) );
	}

	public function test_the_tick_flush_is_dated_from_the_tick(): void {
		// The tick's flush reads the tick, not the wall.
		$this->shift_tick( -Bucket::SECONDS );
		$this->test_the_tick_flushes_what_fill_folded();
		$this->assertSame( [ [ Bucket::id( self::tick() ), 2 ] ], $this->facts( 'SELECT bucket, count(*) FROM requests GROUP BY bucket' ) );
	}

	public function test_the_tick_flushes_what_fill_folded(): void {
		$sb = $this->armed_stats_builder();

		$this->fill_flame( $sb, $this->completed_request( [ 'duration_ms' => 12.0 ] ) );
		$this->fill_flame( $sb, $this->completed_request( [ 'duration_ms' => 13.0 ] ) );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'fill buffers and never writes' );

		$sb->fire_cb();

		$this->assertSame( [ [ 2, 25.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'the tick wrote both' );
	}

	/** Truly idle: nothing buffered and a current window, so a tick asks nothing. */
	public function test_a_tick_with_nothing_owed_asks_nothing(): void {
		$sb       = $this->armed_stats_builder();
		$recorder = VerbHarness::ask_recorder();
		$sb->sink( $recorder );

		$sb->fire_cb();

		$this->assertSame( [], $recorder->asked );
	}

	/**
	 * The periodic flush is the node's own work, not a record's. A Row_Table
	 * that refuses it must leave every record buffered and undead-lettered,
	 * and the stop it raises leaves the tick, where the worker exits loudly.
	 */
	public function test_a_failing_flush_raises_from_the_tick_and_poisons_no_record(): void {
		$sb = $this->armed_stats_builder();
		$this->break_facts();

		$this->fill_flame( $sb, $this->completed_request( [ 'duration_ms' => 211.0 ] ) );

		$thrown = null;
		try {
			$sb->fire_cb();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}
		$this->assertSame( 'stats write failed', $thrown?->getMessage(), 'the tick raises the refused write' );
		$this->assertSame( 1, $this->get_stats( $sb )['buffered_requests'], 'the record stays buffered' );
	}

	/** A tick that failed leaves the rows for the next one, each written once. */
	public function test_the_tick_after_a_failed_flush_writes_each_row_once(): void {
		$sb = $this->armed_stats_builder();
		foreach ( [ 97.0, 113.0, 131.0 ] as $ms ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'duration_ms' => $ms ] ) );
		}
		$this->break_facts();
		try {
			$this->tick_router( 0 );
		} catch ( Worker_Should_Stop ) {
			// The first tick's refusal; the next tick retries.
		}
		$this->mend_facts();
		$this->tick_router( Stats_Builder_Node::FLUSH_INTERVAL_SEC );

		$this->assertSame( [ [ 3, 341.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'each request is written once' );
	}

	/** Round-trip: what `arguments()` was given is what it reads back. */
	public function test_arguments_read_back_what_they_were_given(): void {
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$sb = $this->unarmed_builder();
		$sb->name( 'sb' );

		$sb->arguments( [ 'petrel-4434', 'skua-4435' ] );

		$this->assertSame( [ 'petrel-4434', 'skua-4435' ], $sb->arguments() );
	}

	/** The flush rides the Router tick at its own cadence. */
	public function test_arguments_arm_the_flush_on_the_tick_router(): void {
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$sb = $this->unarmed_builder();
		$sb->name( 'sb' );

		$sb->arguments( [] );

		$this->assertSame( 'router', $sb->timer_mode() );
		$this->assertSame( Stats_Builder_Node::FLUSH_INTERVAL_SEC * 1000, $sb->interval_ms );
	}

	public function test_get_stats_returns_payload(): void {
		$sb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$sb->name( 'sb' );
		$sb->sink( $capture );

		$this->fill_flame( $sb, $this->completed_request( [
			'duration_ms' => 30.0,
			'profiles'    => [
				'init'      => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ],
				'wp_loaded' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ],
			],
		] ) );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'caller';
		$message[ Message::ID ]    = 'req-1';
		$message[ Message::KEY ]   = 'k-1';
		$message[ Message::VALUE ] = 'GET_STATS';
		$sb->fill( $message );

		$reply = null;
		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_RESPONSE ) && ( $type & Message::TM_STRUCT ) ) {
				$reply = $captured;
				break;
			}
		}
		$this->assertNotNull( $reply, 'GET_STATS response emitted' );
		$this->assertSame( 'caller', $reply[ Message::TO ], 'reply addresses original FROM' );
		$this->assertSame( 'req-1', $reply[ Message::ID ], 'reply carries original ID' );

		$payload = $reply[ Message::VALUE ];
		$this->assertSame( 'GET_STATS', $payload['verb'] );
		$this->assertSame( 1, $payload['data']['buffered_requests'] );
		$this->assertSame( 9, $payload['data']['buffered_rows'], 'its server, URL, page and agent, its two categories, the empty entry, the unmeasured owner, and its request' );
		$this->assertSame( 0, $payload['data']['records_gone'] );
	}

	public function test_an_unknown_request_verb_is_refused_on_the_error_plane(): void {
		$sb                        = $this->unarmed_builder();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'caller';
		$message[ Message::ID ]    = 'req-2';
		$message[ Message::VALUE ] = 'NONSENSE_VERB';
		$sb->name( 'sb' );
		$sb->sink( $capture );
		$sb->fill( $message );
		$reply = $capture->captured[0];
		$this->assertSame( Message::TM_ERROR, $reply[ Message::TYPE ] );
		$this->assertSame( "unknown request verb: NONSENSE_VERB\n", $reply[ Message::VALUE ] );
		$this->assertSame( 'caller', $reply[ Message::TO ] );
		$this->assertSame( 'req-2', $reply[ Message::ID ] );
	}

	/** Any message carrying TM_REQUEST is answered once and never folded as data. */
	public function test_a_request_is_answered_and_never_folded_whatever_else_it_flags(): void {
		$sb                        = $this->unarmed_builder();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_STRUCT;
		$message[ Message::VALUE ] = 'GET_STATS';
		$sb->sink( $capture );
		$sb->fill( $message );
		$this->assertSame( 0, $this->get_stats( $sb )['buffered_requests'], 'response not processed as request' );
		$this->assertCount( 1, $capture->captured );
	}

	public function test_a_worker_record_keeps_the_narration_counters(): void {
		// The builder files the record's entries through an allowlist that keeps
		// `m` and drops every other producer field: the counters must be in it.
		$sb        = $this->armed_stats_builder();
		$now       = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		Core::$now = $now;
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://example.com/narrated-3301', 'timestamp' => $now ] ) );

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			static function () use ( $sb, $now ): void {
				Core::$now = $now;
				$sb->flush();
			}
		);
		$told = self::entries_of( $entries, Flame_Tree::STATS_WRITES )[0];

		$rb      = new \Newspack_Event_Logger_Nodes\Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$n = 0;
		foreach ( [
			[ 'k' => 'process (start)', 'm' => '4471 on host', 'l' => '' ],
			[ 'k' => 'request', 'm' => 'POST https://kea.test/wp-json/newspack-nodes/v1/workers/spawn' ],
			[ 'k' => $told['k'], 'm' => $told['m'], 'keep' => 1 ],
			[ 'k' => 'process (complete)', 'duration_ms' => 595000.0, 'status_code' => 200 ],
		] as $entry ) {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = Message::TM_STRUCT;
			$message[ Message::KEY ]   = 'worker-7731';
			$message[ Message::VALUE ] = $entry + [ 'n' => ++$n, 'rid' => 'worker-7731', 'ts' => $now ];
			$rb->fill( $message );
		}
		$stored = [];
		foreach ( $capture->captured as $captured ) {
			foreach ( Core::arr( $captured[ Message::VALUE ] )['entries'] ?? [] as $entry ) {
				if ( Flame_Tree::STATS_WRITES === ( $entry['k'] ?? null ) ) {
					$stored = $entry;
				}
			}
		}
		$this->assertStringContainsString( ' · 1 requests', (string) ( $stored['m'] ?? '' ), 'the record shows what the flush counted' );
	}

	/**
	 * Invoke a private narration method under a started record, with the
	 * clock pinned, and return the narration it wrote.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function narrated( Stats_Builder_Node $sb, string $method, array $args ): array {
		$previous    = Core::$clock;
		$now         = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		Core::$clock = static fn (): float => (float) $now;
		try {
			$entries = $this->logged_in(
				$this->make_temp_dir(),
				static fn (): mixed => ( new \ReflectionMethod( $sb, $method ) )->invoke( $sb, ...$args )
			);
		} finally {
			Core::$clock = $previous;
		}
		return self::entries_of( $entries, ...self::NARRATION );
	}

	public function test_an_unlogged_worker_runs_the_work_bare(): void {
		// No record, no span: the work runs, its line is never described.
		$sb    = $this->unarmed_builder();
		$tally = new \ReflectionProperty( $sb, 'tally' );
		$tally->setValue( $sb, [ Flame_Tree::STATS_SWEEP => [ 'ranked' => 4 ] ] );
		$described = false;

		$result = ( new \ReflectionMethod( $sb, 'spanned' ) )->invoke(
			$sb,
			Flame_Tree::STATS_SWEEP,
			static fn (): int => 4471,
			static function () use ( &$described ): array {
				$described = true;
				return [ '', [] ];
			}
		);

		$this->assertSame( 4471, $result );
		$this->assertFalse( $described );
		$this->assertSame( [], $tally->getValue( $sb ) );
	}

	public function test_a_count_that_rounds_to_zero_is_left_out(): void {
		$told = $this->narrated(
			new Stats_Builder_Node(),
			'narrate',
			[ Flame_Tree::STATS_WRITES, static fn (): array => [ 'rows written', [ 'ms' => 0.03, 'requests' => 0.06 ] ] ]
		);

		$this->assertSame( 'rows written · 0.1 requests', $told[0]['m'] ?? null );
	}

	public function test_an_unlogged_worker_builds_no_narration(): void {
		// With no started record the line is never built; its counters reset.
		$sb    = $this->unarmed_builder();
		$tally = new \ReflectionProperty( $sb, 'tally' );
		$tally->setValue( $sb, [ Flame_Tree::STATS_WRITES => [ 'flushes' => 3 ] ] );
		$built = false;

		( new \ReflectionMethod( $sb, 'narrate' ) )->invoke(
			$sb,
			Flame_Tree::STATS_WRITES,
			static function () use ( &$built ): array {
				$built = true;
				return [ '', [] ];
			}
		);

		$this->assertNull( Log_Manager::started_instance() );
		$this->assertFalse( $built );
		$this->assertSame( [], $tally->getValue( $sb ) );
	}

	public function test_get_stats_carries_the_narration_not_yet_told(): void {
		$sb        = $this->armed_stats_builder();
		Core::$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-tieke-1' ] ) );
		$sb->flush();
		Core::$now += 5;
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-tieke-2' ] ) );
		$sb->flush();

		$this->assertSame( 1, $this->get_stats( $sb )['narration'][ Flame_Tree::STATS_WRITES ]['flushes'] ?? null, 'the flush since the last summary' );
	}

	/** The catalog's `reply_shape` names every key `GET_STATS` answers with. */
	public function test_get_stats_reply_shape_names_every_key_it_carries(): void {
		$sb   = $this->unarmed_builder();
		$verb = \array_column( Stats_Builder_Node::node_schema()['requests'], null, 'name' )['GET_STATS'];
		\preg_match_all( '/\w+/', $verb['reply_shape'], $named );

		$this->assertSame( \array_keys( $this->get_stats( $sb ) ), $named[0] );
	}

	public function test_double_flush_is_idempotent(): void {
		$sb = $this->armed_stats_builder();

		$this->fill_flame( $sb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$sb->flush();
		$sb->flush();

		$this->assertSame( [ [ 1, 100.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'the second flush writes nothing again' );
	}

	public function test_save_state_with_nothing_buffered_writes_nothing(): void {
		$sb = $this->unarmed_builder();
		$sb->name( 'sb' );
		$this->assertSame( [], $sb->save_state(), 'the checkpoint carries nothing' );
	}

	/**
	 * `$count` categories `cat-000` onward, dearest first: `cat-000` at
	 * `$count` ms down to the last at 1 ms, each with two entries.
	 *
	 * @param int $count Categories.
	 * @return array<string,array{time:float,count:int,entries:array<string,array{0:float,1:int}>}>
	 */
	private static function ranked_profiles( int $count ): array {
		$profiles = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$name              = \sprintf( 'cat-%03d', $i );
			$profiles[ $name ] = [
				'time'    => (float) ( $count - $i ),
				'count'   => 3,
				'entries' => [ "{$name} caller-a" => [ 0.5, 1 ], "{$name} caller-b" => [ 0.25, 2 ] ],
			];
		}
		return $profiles;
	}

	/**
	 * The category names one request writes to `timings` and to
	 * `timings_site`, and the entries `timings` holds, each run under a
	 * host of its own.
	 *
	 * @param array<string,mixed> $profiles The record's `profiles{}`.
	 * @param string              $host     The run's host.
	 * @return array{timings: list<string>, site: list<string>, entries: list<string>}
	 */
	private function written_categories( array $profiles, string $host ): array {
		Core::node( 'sb' )?->remove_node();
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => "https://{$host}/cap-7741", 'profiles' => $profiles ] ) );
		$sb->flush();
		$names = fn ( string $table ): array => \array_column( $this->facts( "SELECT DISTINCT c.name FROM {$table} t JOIN servers s ON s.id = t.server_id JOIN categories c ON c.id = t.category_id WHERE s.name = '{$host}' ORDER BY c.name" ), 0 );
		return [
			'timings' => $names( 'timings' ),
			'site'    => $names( 'timings_site' ),
			'entries' => \array_column( $this->facts( "SELECT e.name FROM timings t JOIN servers s ON s.id = t.server_id JOIN entries e ON e.id = t.entry_id WHERE s.name = '{$host}' AND e.name <> '' ORDER BY e.name" ), 0 ),
		];
	}

	/** Past `TIMED_CATEGORIES`, a request's cheapest categories and their entries are not written. */
	public function test_a_request_writes_its_hundred_dearest_categories(): void {
		$written = $this->written_categories( self::ranked_profiles( 101 ), 'cap-101.test' );

		$kept = \array_keys( self::ranked_profiles( 100 ) );
		$this->assertSame( $kept, $written['timings'] );
		$this->assertSame( $kept, $written['site'] );
		$this->assertNotContains( 'cat-100 caller-a', $written['entries'] );
		$this->assertContains( 'cat-099 caller-b', $written['entries'] );
	}

	/** A request at the cap writes every category. */
	public function test_a_request_with_a_hundred_categories_writes_them_all(): void {
		$written = $this->written_categories( self::ranked_profiles( 100 ), 'cap-100.test' );

		$this->assertCount( 100, $written['timings'] );
		$this->assertCount( 200, $written['entries'] );
	}

	/** A tie at the cut keeps the earlier name, whatever order the record lists them in. */
	public function test_a_tie_at_the_cut_keeps_the_earlier_name(): void {
		$profiles             = self::ranked_profiles( 100 );
		$profiles['cat-099b'] = $profiles['cat-099'];

		$listed   = $this->written_categories( $profiles, 'tie-listed.test' );
		$reversed = $this->written_categories( \array_reverse( $profiles, true ), 'tie-reversed.test' );

		$this->assertContains( 'cat-099', $listed['timings'] );
		$this->assertNotContains( 'cat-099b', $listed['timings'] );
		$this->assertSame( $listed['timings'], $reversed['timings'] );
	}

	/**
	 * A Query Monitor timer overlays its hook's time, as a listener does, so
	 * its rows carry the overlay flag every profiled-time sum leaves out.
	 */
	public function test_a_query_monitor_row_carries_the_overlay_flag(): void {
		$rows = $this->stats_for(
			[
				'profiles' => [
					'kea_7746 hook'      => [ 'time' => 100.0, 'count' => 1, 'entries' => [] ],
					Flame_Tree::QM_STATE => [ 'time' => 30.0, 'count' => 1, 'entries' => [] ],
				],
			],
			'overlay-7746.test'
		)['timings'];

		$flags = \array_column( $rows, 2, 0 );
		$this->assertSame( 0, (int) $flags['kea_7746 hook'] );
		$this->assertSame( 1, (int) $flags[ Flame_Tree::QM_STATE ] );
	}

	/** A listener ranks by its own time, so it is written though its hook is cut. */
	public function test_a_kept_listener_writes_without_its_hook(): void {
		$profiles                       = self::ranked_profiles( 150 );
		$profiles['kakapo_render hook'] = [ 'time' => 0.5, 'count' => 1, 'entries' => [] ];
		$listener                       = Flame_Tree::listener_name( 'kakapo_cb', 10 );
		$profiles[ $listener ]          = [ 'time' => 148.5, 'count' => 1, 'entries' => [] ];

		$written = $this->written_categories( $profiles, 'listener-cap.test' );

		$this->assertContains( $listener, $written['timings'] );
		$this->assertNotContains( 'kakapo_render hook', $written['timings'] );
	}

	/** A request is filed under its start plus its duration, rounded to the second. */
	public function test_a_request_completes_at_its_start_plus_its_rounded_duration(): void {
		$start = \gmmktime( 13, 39, 59, 10, 4, 2026 );

		$this->assertSame( [ $start + 1, $start, $start + 3 ], [ Stats_Builder_Node::completed_at( $start, 700.0 ), Stats_Builder_Node::completed_at( $start, 499.0 ), Stats_Builder_Node::completed_at( $start, 2500.0 ) ] );
	}

	/** A host logged in two spellings is one server. */
	public function test_a_hosts_spellings_file_one_server(): void {
		$sb = $this->armed_stats_builder();
		foreach ( [ 'https://Example-9907.COM/Moa?a=1', 'https://example-9907.com/weka' ] as $i => $request_url ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-9907{$i}", 'url' => Url_Parts::key( $request_url, false ), 'request_url' => $request_url ] ) );
		}
		$sb->flush();

		$this->assertSame( [ [ 'example-9907.com', 2 ] ], $this->facts( 'SELECT s.name, count(r.rid) FROM servers s JOIN requests r ON r.server_id = s.id GROUP BY s.id' ) );
		$this->assertSame( [ [ '/Moa?a=?' ], [ '/weka' ] ], $this->facts( 'SELECT path FROM urls ORDER BY path' ), 'each path under its server in any case' );
	}

	public function test_urls_carry_their_page(): void {
		$sb = $this->armed_stats_builder();
		foreach ( [ 'https://Kea.example/Kea-5521?a=1', 'https://kea.example/kea-5521?b=2' ] as $i => $url ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-552{$i}", 'url' => $url ] ) );
		}
		$sb->flush();

		$this->assertSame(
			[ [ 'kea.example/kea-5521', Log_Manager::url_hash( 'kea.example/kea-5521' ) ], [ 'kea.example/kea-5521', Log_Manager::url_hash( 'kea.example/kea-5521' ) ] ],
			$this->facts( 'SELECT page, page_hash FROM urls ORDER BY id' )
		);
		$this->assertSame( [ [ 'urls_page' ] ], $this->facts( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'urls_page'" ) );
	}

	/**
	 * Requests whose keys differ only in case or scheme are one URL row, its
	 * hash the identity's, its path as the first request spelled it.
	 */
	public function test_a_urls_case_and_scheme_variants_file_one_row(): void {
		$sb   = $this->armed_stats_builder();
		$sent = [ 'https://kea.example/Cincinnati/EventSearch?v=1', 'https://kea.example/cincinnati/eventsearch?v=2', 'http://kea.example/CINCINNATI/eventsearch?v=3' ];
		foreach ( $sent as $i => $request_url ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-4402{$i}", 'url' => Url_Parts::key( $request_url, false ), 'request_url' => $request_url ] ) );
			// A flush apiece forgets the URL's id, so each insert reaches the table.
			$sb->flush();
		}

		$this->assertSame(
			[ [ Log_Manager::url_hash( 'https://kea.example/cincinnati/eventsearch?v=?' ), '/Cincinnati/EventSearch?v=?', 3 ] ],
			$this->facts( 'SELECT u.hash, u.path, count(r.rid) FROM urls u JOIN requests r ON r.url_id = u.id GROUP BY u.id' )
		);
	}

	/**
	 * A URL row shows the spelling and scheme of the first request it filed:
	 * the `urls` insert is an `ignore`, and `row_path()` keeps an http URL
	 * whole.
	 *
	 * @return array<string,array{0:list<string>,1:string}>
	 */
	public static function first_seen(): array {
		return [
			'https, then http' => [ [ 'https://kea.example/Weka-4402/', 'http://kea.example/weka-4402/' ], '/Weka-4402/' ],
			'http, then https' => [ [ 'http://kea.example/Weka-4402/', 'https://kea.example/weka-4402/' ], 'http://kea.example/Weka-4402/' ],
			'http alone'       => [ [ 'http://kea.example/Weka-4402/' ], 'http://kea.example/Weka-4402/' ],
		];
	}

	/**
	 * @param list<string> $sent The request URLs, in the order they arrive.
	 * @param string       $path The path the row shows.
	 */
	#[DataProvider( 'first_seen' )]
	public function test_a_url_rows_path_is_its_first_requests_spelling_and_scheme( array $sent, string $path ): void {
		$sb = $this->armed_stats_builder();
		foreach ( $sent as $i => $request_url ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-4403{$i}", 'url' => Url_Parts::key( $request_url, false ), 'request_url' => $request_url ] ) );
			$sb->flush();
		}

		$this->assertSame( [ [ $path ] ], $this->facts( 'SELECT path FROM urls' ) );
	}

	public function test_one_request_writes_its_row_and_rollups(): void {
		$sb    = $this->armed_stats_builder();
		$url   = 'https://kea.example/egret-6617/';
		$crumb = $this->fill_flame(
			$sb,
			$this->completed_request(
				[
					'rid'          => 'rid-3301',
					'url'          => $url,
					'status_code'  => 503,
					'duration_ms'  => 842.5,
					'peak_mb'      => 61,
					'country_code' => 'NZ',
					'user_agent'   => 'Takahe/4.2',
					'profiles'     => [ 'sql' => [ 'time' => 120.25, 'count' => 9, 'entries' => [ 'SELECT kea' => [ 80.5, 4 ] ] ] ],
				]
			)
		);
		$sb->flush();
		$place = \Newspack_Nodes\Log_Position::crumb( $crumb );

		$this->assertSame( [ [ 'rid-3301', 503, 'NZ', 842.5, 61.0, $place['segment'], $place['offset'], $place['length'], 0, 'GET', null, 1 ] ], $this->facts( 'SELECT rid, status, country, ms, peak_mb, segment, "offset", length, worker, method, error, profiled FROM requests' ), 'a record with no error files none; its timing rows mark it profiled; its place is its record\'s' );
		$this->assertGreaterThan( 0, $place['length'] );
		$this->assertSame( [ [ Log_Manager::url_hash( $url ), 'kea.example', '/egret-6617/' ] ], $this->facts( 'SELECT u.hash, s.name, u.path FROM urls u JOIN servers s ON s.id = u.server_id' ) );
		$this->assertSame( [ [ 'Takahe/4.2' ] ], $this->facts( 'SELECT name FROM agents' ) );
		$this->assertSame( [ [ 1 ] ], $this->facts( 'SELECT count(*) FROM requests r JOIN urls u ON u.id = r.url_id JOIN agents a ON a.id = r.ua_id' ), 'the row names its URL and agent by id' );
		$this->assertSame( [ [ 'sql', '', 120.25, 9, 1 ], [ 'sql', 'SELECT kea', 80.5, 4, 1 ] ], $this->facts( 'SELECT c.name, e.name, t.ms, t.calls, t.requests FROM timings t JOIN categories c ON c.id = t.category_id JOIN entries e ON e.id = t.entry_id ORDER BY e.name' ) );
		$this->assertSame( [ [ 5, 1, 1, 842.5, 842.5, 61.0, 0 ] ], $this->facts( 'SELECT status_class, count, timed, sum_ms, max_ms, sum_peak_mb, errors FROM requests_hourly' ), 'a 503 is not an error: errors are T and F' );
		$this->assertSame( [ [ Bucket::hour( self::tick() ), 'kea.example', 'GET', 0, 'NZ' ] ], $this->facts( 'SELECT h.hour, s.name, h.method, h.worker, h.country FROM requests_hourly h JOIN servers s ON s.id = h.server_id' ) );
	}

	/**
	 * A record carries what the builder files it under: its bucket, its
	 * partition, its error kind, and a NULL for each field it left empty.
	 */
	public function test_a_request_row_files_its_place_and_leaves_absent_fields_null(): void {
		$sb = $this->armed_stats_builder( '5' );
		$this->fill_flame(
			$sb,
			$this->completed_request(
				[
					'rid'          => 'rid-9902',
					'url'          => 'https://kea.example/tui-5521',
					'status_code'  => null,
					'error_status' => 'T',
					'duration_ms'  => 30_000.0,
					'timestamp'    => self::tick() - 100,
					'country_code' => '',
					'user_agent'   => '',
					'ja4_hash'     => 't13d1516h2',
					'http_from'    => 'bot@kea.example',
					'peak_mb'      => -3,
				]
			)
		);
		$sb->flush();

		$this->assertSame(
			[ [ Bucket::id( self::tick() - 70 ), (float) ( self::tick() - 70 ), self::tick() - 100, 5, null, null, null, null, 't13d1516h2', 'bot@kea.example', 'T', 0.0 ] ],
			$this->facts( 'SELECT r.bucket, r.finished_at, r.started_at, r.partition, r.status, r.country, r.ua_id, r.ms, j.name, f.name, r.error, r.peak_mb FROM requests r JOIN ja4s j ON j.id = r.ja4_id JOIN froms f ON f.id = r.from_id', '5' ),
			'a timeout keeps the start its record carries, though its duration is not a sample'
		);
		$this->assertSame( [ [ 0, '', 1, 0, 0.0, 0.0, 1 ] ], $this->facts( 'SELECT status_class, country, count, timed, sum_ms, max_ms, errors FROM requests_hourly', '5' ), 'a timeout is counted, untimed, and an error' );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM agents', '5' ) );
	}

	/**
	 * A request names its server, JA4 and From by id, each name filed once
	 * in its lookup table, and its URL and hour name the same server row.
	 */
	public function test_a_request_names_its_server_ja4_and_from_by_id(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-kokako-1', 'url' => 'https://kokako.example/ruru-4410', 'ja4_hash' => 't13d1516h2_kokako', 'http_from' => 'crawler@kokako.example' ] ) );
		$sb->flush();

		$this->assertSame( [ [ 'kokako.example' ] ], $this->facts( 'SELECT name FROM servers' ) );
		$this->assertSame( [ [ 't13d1516h2_kokako' ] ], $this->facts( 'SELECT name FROM ja4s' ) );
		$this->assertSame( [ [ 'crawler@kokako.example' ] ], $this->facts( 'SELECT name FROM froms' ) );
		$this->assertSame(
			[ [ 'kokako.example', 't13d1516h2_kokako', 'crawler@kokako.example', 'kokako.example', 'kokako.example' ] ],
			$this->facts( 'SELECT sv.name, j.name, f.name, us.name, hs.name FROM requests r JOIN servers sv ON sv.id = r.server_id JOIN ja4s j ON j.id = r.ja4_id JOIN froms f ON f.id = r.from_id JOIN urls u ON u.id = r.url_id JOIN servers us ON us.id = u.server_id JOIN requests_hourly h JOIN servers hs ON hs.id = h.server_id' )
		);
	}

	/**
	 * A second request of the same server, JA4 and From, in the batch or the
	 * next, files no second lookup row and names the first's ids.
	 */
	public function test_a_repeated_name_files_no_second_lookup_row(): void {
		$sb   = $this->armed_stats_builder();
		$same = [ 'url' => 'https://kokako.example/ruru-4411', 'ja4_hash' => 't13d1516h2_ruru', 'http_from' => 'ruru@kokako.example' ];
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-ruru-a' ] + $same ) );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-ruru-b' ] + $same ) );
		$sb->flush();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-ruru-c' ] + $same ) );
		$sb->flush();

		$this->assertSame( [ [ 1, 1, 1 ] ], $this->facts( 'SELECT ( SELECT count(*) FROM servers ), ( SELECT count(*) FROM ja4s ), ( SELECT count(*) FROM froms )' ) );
		$this->assertSame( [ [ 3, 1, 1, 1 ] ], $this->facts( 'SELECT count(*), count( DISTINCT server_id ), count( DISTINCT ja4_id ), count( DISTINCT from_id ) FROM requests' ) );
	}

	/** A request naming no JA4 and no From files null ids and no lookup row. */
	public function test_an_absent_ja4_and_from_file_null_ids(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hihi-1', 'url' => 'https://kokako.example/hihi', 'ja4_hash' => '', 'http_from' => '' ] ) );
		$sb->flush();

		$this->assertSame( [ [ null, null ] ], $this->facts( 'SELECT ja4_id, from_id FROM requests' ) );
		$this->assertSame( [ [ 0, 0 ] ], $this->facts( 'SELECT ( SELECT count(*) FROM ja4s ), ( SELECT count(*) FROM froms )' ) );
	}

	/**
	 * A profiled request's timing and window rows name each category and
	 * entry by id, both timing tables naming the server's.
	 */
	public function test_a_profiled_requests_timing_rows_name_their_category_and_entry_by_id(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://kokako.example/tieke-88', 'profiles' => [ 'sql' => [ 'time' => 12.5, 'count' => 3, 'entries' => [ 'SELECT tieke' => [ 7.25, 2 ] ] ] ] ] ) );
		$sb->flush();

		$named = [ [ 'sql', '' ], [ 'sql', 'SELECT tieke' ] ];
		$this->assertSame( [ [ 'sql' ] ], $this->facts( 'SELECT name FROM categories' ) );
		$this->assertSame( [ [ '' ], [ 'SELECT tieke' ] ], $this->facts( 'SELECT name FROM entries ORDER BY name' ) );
		foreach ( [ 'timings', 'timings_site' ] as $table ) {
			$this->assertSame( [ [ 'kokako.example', 'sql', '' ], [ 'kokako.example', 'sql', 'SELECT tieke' ] ], $this->facts( "SELECT sv.name, c.name, e.name FROM {$table} t JOIN servers sv ON sv.id = t.server_id JOIN categories c ON c.id = t.category_id JOIN entries e ON e.id = t.entry_id ORDER BY e.name" ), $table );
		}
		$this->assertSame( $named, $this->facts( 'SELECT c.name, e.name FROM category_window t JOIN categories c ON c.id = t.category_id JOIN entries e ON e.id = t.entry_id ORDER BY e.name' ) );
	}

	/**
	 * Three timed requests over two shapes of one page in one bucket, and one
	 * shape in the bucket before, beside another page: each with its own
	 * category, its overlay listener and an entry.
	 *
	 * @return array{0:string,1:string} The page's hash, and the other page's.
	 */
	private function fold_a_page_of_two_shapes( Stats_Builder_Node $sb ): array {
		$listener = Flame_Tree::listener_name( 'tieke_cb', 10 );
		$fold     = function ( string $rid, string $url, int $finished, float $sql, int $calls, float $cb ) use ( $sb, $listener ): void {
			$this->fill_flame(
				$sb,
				$this->completed_request(
					[
						'rid'         => $rid,
						'url'         => $url,
						'timestamp'   => $finished - 1,
						'duration_ms' => 1000.0,
						'profiles'    => [
							'sql'     => [ 'time' => $sql, 'count' => $calls, 'entries' => [ 'SELECT tieke' => [ $sql / 4, 1 ] ] ],
							$listener => [ 'time' => $cb, 'count' => 1, 'entries' => [] ],
						],
					]
				)
			);
		};
		$fold( 'rid-tieke-q1', 'https://kokako.example/Tieke-88/?q=1', self::NOW, 12.5, 3, 2.25 );
		$fold( 'rid-tieke-b1', 'https://kokako.example/tieke-88/', self::NOW, 30.5, 5, 4.75 );
		$fold( 'rid-tieke-q2', 'https://kokako.example/tieke-88/?q=2', self::NOW, 8.0, 2, 1.5 );
		$fold( 'rid-tieke-old', 'https://kokako.example/tieke-88/', self::NOW - Bucket::SECONDS, 40.25, 7, 6.5 );
		$fold( 'rid-kea-other', 'https://kokako.example/kea-89/', self::NOW, 900.5, 90, 99.5 );
		$sb->flush();
		return [ Log_Manager::url_hash( 'kokako.example/tieke-88/' ), Log_Manager::url_hash( 'kokako.example/kea-89/' ) ];
	}

	/**
	 * A page's shapes sum into one `timings_page` row per category, entry
	 * and listener per bucket, its page a `pages` row filed once.
	 */
	public function test_a_pages_shapes_sum_into_one_page_row_per_category_and_entry_per_bucket(): void {
		$this->window_world();
		[ $page, $other ] = $this->fold_a_page_of_two_shapes( $this->window_builder() );
		$now              = Bucket::id( self::NOW );
		$listener         = Flame_Tree::listener_name( 'tieke_cb', 10 );

		$this->assertSame( [ [ 'kokako.example/kea-89/', $other ], [ 'kokako.example/tieke-88/', $page ] ], $this->facts( 'SELECT page, page_hash FROM pages ORDER BY page' ) );
		$this->assertSame(
			[
				[ $now - 1, 'sql', '', 0, 40.25, 7, 1 ],
				[ $now - 1, 'sql', 'SELECT tieke', 0, 40.25 / 4, 1, 1 ],
				[ $now - 1, $listener, '', 1, 6.5, 1, 1 ],
				[ $now, 'sql', '', 0, 51.0, 10, 3 ],
				[ $now, 'sql', 'SELECT tieke', 0, 51.0 / 4, 3, 3 ],
				[ $now, $listener, '', 1, 8.5, 3, 3 ],
			],
			$this->facts( "SELECT t.bucket, c.name, e.name, t.listener, t.ms, t.calls, t.requests FROM timings_page t JOIN pages p ON p.id = t.page_id JOIN servers sv ON sv.id = t.server_id JOIN categories c ON c.id = t.category_id JOIN entries e ON e.id = t.entry_id WHERE p.page_hash = '{$page}' AND sv.name = 'kokako.example' ORDER BY t.bucket, c.name, e.name" )
		);
	}

	/**
	 * A page's breakdown read from `timings_page` is its shapes' `timings`
	 * summed, bucket by bucket.
	 */
	public function test_a_page_breakdown_from_its_rollup_equals_its_shapes_timings_summed(): void {
		$this->window_world();
		[ $page ] = $this->fold_a_page_of_two_shapes( $this->window_builder() );
		$spec     = [ 'source' => 'timings', 'filter' => [ 'page' => [ $page ] ], 'measure' => [ 'ms', 'calls', 'requests' ], 'buckets' => Bucket::last( 3, self::NOW ), 'sort' => 'ms', 'order' => 'desc' ];

		$rollup = Stats_Query::run( [ 'group' => [ 'bucket', 'category', 'entry', 'listener' ] ] + $spec );
		$shapes = Stats_Query::run( [ 'group' => [ 'url', 'bucket', 'category', 'entry', 'listener' ] ] + $spec );
		$summed = [];
		foreach ( $shapes['rows'] as [ , , $bucket, $category, $entry, $listener, $ms, $calls, $requests ] ) {
			$key            = "{$bucket} {$category} {$entry} {$listener}";
			$summed[ $key ] = [ $bucket, $category, $entry, $listener, ( $summed[ $key ][4] ?? 0.0 ) + $ms, ( $summed[ $key ][5] ?? 0 ) + $calls, ( $summed[ $key ][6] ?? 0 ) + $requests ];
		}
		$by_key = static fn ( array $rows ): array => \array_combine( \array_map( static fn ( array $row ): string => \implode( ' ', \array_slice( $row, 0, 4 ) ), $rows ), $rows );

		$this->assertSame( [ 'timings_page', 'timings' ], [ $rollup['table'], $shapes['table'] ] );
		$this->assertCount( 6, $rollup['rows'] );
		$this->assertEqualsWithDelta( $by_key( \array_values( $summed ) ), $by_key( $rollup['rows'] ), 1e-9 );
	}

	/**
	 * A name reads back byte for byte, and names differing only in case or
	 * spacing file apart.
	 */
	public function test_names_differing_in_case_or_spacing_file_apart_and_read_back_byte_for_byte(): void {
		$sb       = $this->armed_stats_builder();
		$profiles = [
			'Hook:Kea_Init'  => [ 'time' => 2.5, 'count' => 1, 'entries' => [] ],
			'hook:kea_init'  => [ 'time' => 3.5, 'count' => 1, 'entries' => [] ],
			'hook:kea_init ' => [ 'time' => 4.5, 'count' => 1, 'entries' => [] ],
		];
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-mohua-1', 'url' => 'https://kokako.example/mohua', 'ja4_hash' => 'T13d_Mohua', 'profiles' => $profiles ] ) );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-mohua-2', 'url' => 'https://kokako.example/mohua', 'ja4_hash' => 't13d_mohua' ] ) );
		$sb->flush();

		$this->assertSame( [ [ 'Hook:Kea_Init', 2.5 ], [ 'hook:kea_init', 3.5 ], [ 'hook:kea_init ', 4.5 ] ], $this->facts( 'SELECT c.name, t.ms FROM timings t JOIN categories c ON c.id = t.category_id ORDER BY c.name' ) );
		$this->assertSame( [ [ 'rid-mohua-1', 'T13d_Mohua' ], [ 'rid-mohua-2', 't13d_mohua' ] ], $this->facts( 'SELECT r.rid, j.name FROM requests r JOIN ja4s j ON j.id = r.ja4_id ORDER BY r.rid' ) );
	}

	/**
	 * A completion past the tick that brought the record is clamped to it;
	 * the start comes from the record itself.
	 */
	public function test_a_clamped_row_keeps_its_records_own_start(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-6614', 'timestamp' => self::tick() - 17, 'duration_ms' => 58_400.0 ] ) );
		$sb->flush();

		$this->assertSame( [ [ (float) self::tick(), self::tick() - 17, 58_400.0 ] ], $this->facts( 'SELECT finished_at, started_at, ms FROM requests' ) );
	}

	/**
	 * A replay through a second builder over the same file adds nothing: its
	 * request rows are kept, and no rollup or window row sums them twice.
	 */
	public function test_a_replay_through_a_second_builder_adds_nothing(): void {
		$flames = [];
		foreach ( [
			$this->completed_request( [ 'rid' => 'rid-weka-1', 'url' => 'https://kea.example/weka', 'duration_ms' => 61.0, 'profiles' => [ 'sql' => [ 'time' => 7.5, 'count' => 3, 'entries' => [ 'SELECT weka' => [ 5.5, 2 ] ] ] ] ] ),
			$this->completed_request( [ 'rid' => 'rid-weka-2', 'url' => 'https://kea.example/weka', 'duration_ms' => 44.0, 'profiles' => [ 'sql' => [ 'time' => 2.25, 'count' => 1, 'entries' => [] ] ] ] ),
		] as $request ) {
			$flames[] = self::flame_at( $request, $this->record_request( $request ) );
		}
		$rollups = fn (): array => [
			'requests_hourly' => $this->facts( 'SELECT count, timed, sum_ms, max_ms FROM requests_hourly' ),
			'timings'         => $this->facts( 'SELECT e.name, t.ms, t.calls, t.requests FROM timings t JOIN entries e ON e.id = t.entry_id ORDER BY e.name' ),
			'timings_site'    => $this->facts( 'SELECT e.name, t.ms, t.calls, t.requests FROM timings_site t JOIN entries e ON e.id = t.entry_id ORDER BY e.name' ),
			'timings_page'    => $this->facts( 'SELECT p.page, e.name, t.ms, t.calls, t.requests FROM timings_page t JOIN pages p ON p.id = t.page_id JOIN entries e ON e.id = t.entry_id ORDER BY e.name' ),
			'pages'           => $this->facts( 'SELECT id, page, page_hash FROM pages' ),
			'category_window' => $this->facts( 'SELECT e.name, w.ms, w.calls, w.requests FROM category_window w JOIN entries e ON e.id = w.entry_id ORDER BY e.name' ),
		];
		$first = $this->armed_stats_builder();
		foreach ( $flames as $flame ) {
			$first->fill( $flame );
		}
		$first->flush();
		$first->remove_node();
		$once = $rollups();

		$successor = $this->armed_stats_builder();
		foreach ( $flames as $flame ) {
			$successor->fill( $flame );
		}
		$successor->flush();

		$this->assertSame( [ [ 2, 2, 105.0, 61.0 ] ], $once['requests_hourly'] );
		$this->assertSame( [ [ '', 9.75, 4, 2 ], [ 'SELECT weka', 5.5, 2, 1 ] ], $once['category_window'] );
		$this->assertSame( [ [ 'kea.example/weka', '', 9.75, 4, 2 ], [ 'kea.example/weka', 'SELECT weka', 5.5, 2, 1 ] ], $once['timings_page'] );
		$this->assertCount( 1, $once['pages'] );
		$this->assertSame( $once, $rollups(), 'the replay summed nothing twice' );
		$this->assertSame( [ [ 2 ] ], $this->facts( 'SELECT count(*) FROM requests' ) );
	}

	public function test_a_worker_request_files_worker_one_in_timings_site(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://kea.example/jobs/kiwi-7', 'is_worker' => true, 'duration_ms' => 912.0, 'profiles' => [ 'cron' => [ 'time' => 410.5, 'count' => 6, 'entries' => [] ] ] ] ) );
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://kea.example/kiwi-8', 'duration_ms' => 33.0, 'profiles' => [ 'cron' => [ 'time' => 1.5, 'count' => 2, 'entries' => [] ] ] ] ) );
		$sb->flush();

		$this->assertSame( [ [ 0, 'cron', 1.5, 2, 1 ], [ 1, 'cron', 410.5, 6, 1 ] ], $this->facts( 'SELECT t.worker, c.name, t.ms, t.calls, t.requests FROM timings_site t JOIN categories c ON c.id = t.category_id ORDER BY t.worker' ) );
		$this->assertSame( [ [ 0 ], [ 1 ] ], $this->facts( 'SELECT worker FROM requests ORDER BY worker' ) );
		$this->assertSame( [ [ 0, 1 ], [ 1, 1 ] ], $this->facts( 'SELECT worker, count FROM requests_hourly ORDER BY worker' ) );
	}

	public function test_a_listener_category_is_marked(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame(
			$sb,
			$this->completed_request(
				[
					'url'      => 'https://kea.example/kakapo-3',
					'profiles' => [
						'save_post'                => [ 'time' => 14.0, 'count' => 2, 'entries' => [] ],
						'Kea_Hooks::save @10'      => [ 'time' => 9.0, 'count' => 2, 'entries' => [] ],
						'Kea_Hooks::rollback @-90' => [ 'time' => 3.0, 'count' => 1, 'entries' => [] ],
					],
				]
			)
		);
		$sb->flush();

		$expected = [ [ 'Kea_Hooks::rollback @-90', 1 ], [ 'Kea_Hooks::save @10', 1 ], [ 'save_post', 0 ] ];
		$this->assertSame( $expected, $this->facts( 'SELECT c.name, t.listener FROM timings t JOIN categories c ON c.id = t.category_id ORDER BY c.name' ) );
		$this->assertSame( $expected, $this->facts( 'SELECT c.name, t.listener FROM timings_site t JOIN categories c ON c.id = t.category_id ORDER BY c.name' ) );
	}

	/** A profile category whose name is not a string or whose data is not a map files no timing row. */
	public function test_a_malformed_profile_category_files_no_timing_row(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame(
			$sb,
			$this->completed_request(
				[
					'url'      => 'https://kea.example/tieke-9',
					'profiles' => [
						'sql'  => 'seven ms',
						41     => [ 'time' => 2.0, 'count' => 1, 'entries' => [] ],
						'http' => [ 'time' => 6.5, 'count' => 3, 'entries' => [] ],
					],
				]
			)
		);
		$sb->flush();

		$this->assertSame( [ [ 'http', 6.5, 3 ] ], $this->facts( 'SELECT c.name, t.ms, t.calls FROM timings t JOIN categories c ON c.id = t.category_id' ) );
	}

	/** An untimed request files its request row and its hour, and no timing. */
	public function test_an_untimed_request_files_no_timing_rows(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://kea.example/moa', 'error_status' => 'A', 'duration_ms' => 5_000.0, 'profiles' => [ 'sql' => [ 'time' => 4.0, 'count' => 1, 'entries' => [] ] ] ] ) );
		$sb->flush();

		$this->assertSame( [ [ null, 0 ] ], $this->facts( 'SELECT ms, profiled FROM requests' ), 'a profile on an untimed request profiles nothing' );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM timings' ) );
		$this->assertSame( [ [ 1, 0, 0 ] ], $this->facts( 'SELECT count, timed, errors FROM requests_hourly' ), 'an abort is not an error' );
	}

	/** @return array<string,array{0:float,1:int}> Owners as the request builder settles them on a 41.5 ms request. */
	private static function settled_owners(): array {
		return [ 'kea-7746' => [ 25.5, 1 ], 'core' => [ 4.5, 3 ], '(unowned)' => [ 6.0, 0 ], '(unmeasured)' => [ 5.5, 0 ] ];
	}

	/** The rows come off the record's measured owners; the flame's raised 725 ms is not read (decision 12). */
	public function test_a_timed_record_files_one_row_per_owner_per_url_and_site(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'url' => 'https://kokako.example/hihi-31', 'duration_ms' => 41.5, 'owners' => self::settled_owners() ] ) );
		$sb->flush();

		$expected = [ [ '(unmeasured)', 5.5, 0, 1 ], [ '(unowned)', 6.0, 0, 1 ], [ 'core', 4.5, 3, 1 ], [ 'kea-7746', 25.5, 1, 1 ] ];
		$this->assertSame( $expected, $this->facts( 'SELECT o.name, t.ms, t.calls, t.requests FROM owner_timings t JOIN owners o ON o.id = t.owner_id ORDER BY o.name' ) );
		$this->assertSame( $expected, $this->facts( 'SELECT o.name, t.ms, t.calls, t.requests FROM owner_timings_site t JOIN owners o ON o.id = t.owner_id ORDER BY o.name' ) );
		$this->assertSame( [ [ 'kokako.example', '/hihi-31' ] ], \array_unique( $this->facts( 'SELECT sv.name, u.path FROM owner_timings t JOIN urls u ON u.id = t.url_id JOIN servers sv ON sv.id = t.server_id' ), SORT_REGULAR ) );
		$this->assertSame( [ [ 0 ] ], \array_unique( $this->facts( 'SELECT worker FROM owner_timings_site' ), SORT_REGULAR ) );
		$this->assertSame( [ [ 1 ] ], $this->facts( 'SELECT count(*) FROM requests WHERE bucket IN ( SELECT DISTINCT bucket FROM owner_timings )' ) );
	}

	public function test_a_record_carrying_no_owners_files_no_owner_rows(): void {
		$request = $this->completed_request( [ 'duration_ms' => 17.25 ] );
		unset( $request['owners'] );
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $request );
		$sb->flush();

		$this->assertSame( [ [ 0, 0 ] ], $this->facts( 'SELECT ( SELECT count(*) FROM owner_timings ), ( SELECT count(*) FROM owner_timings_site )' ) );
	}

	public function test_an_untimed_request_files_no_owner_rows(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'error_status' => 'A', 'duration_ms' => 5_000.0, 'owners' => self::settled_owners() ] ) );
		$sb->flush();

		$this->assertSame( [ [ 0, 0, 0 ] ], $this->facts( 'SELECT ( SELECT count(*) FROM owner_timings ), ( SELECT count(*) FROM owner_timings_site ), ( SELECT count(*) FROM owners )' ) );
	}

	public function test_a_replayed_flame_leaves_each_owner_row_at_one_request(): void {
		$request = $this->completed_request( [ 'rid' => 'rid-hihi-1', 'duration_ms' => 41.5, 'owners' => self::settled_owners() ] );
		$message = self::flame_at( $request, $this->record_request( $request ) );
		$first   = $this->armed_stats_builder();
		$first->fill( $message );
		$first->flush();
		$first->remove_node();
		$successor = $this->armed_stats_builder();
		$successor->fill( $message );
		$successor->flush();

		$this->assertSame( [ [ 4, 1, 1 ] ], $this->facts( 'SELECT count(*), min(requests), max(requests) FROM owner_timings' ) );
		$this->assertSame( [ [ 4, 1, 1 ] ], $this->facts( 'SELECT count(*), min(requests), max(requests) FROM owner_timings_site' ) );
		$this->assertSame( [ [ 25.5 ] ], $this->facts( "SELECT t.ms FROM owner_timings t JOIN owners o ON o.id = t.owner_id WHERE o.name = 'kea-7746'" ) );
	}

	/** A timed request whose profile files no timing row is not a profiled request. */
	public function test_a_timed_request_with_no_timing_rows_is_not_profiled(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hoiho-1', 'url' => 'https://kea.example/hoiho', 'duration_ms' => 73.5, 'profiles' => [] ] ) );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hoiho-2', 'url' => 'https://kea.example/hoiho', 'duration_ms' => 81.5, 'profiles' => [ 'sql' => 'nine ms' ] ] ) );
		$sb->flush();

		$this->assertSame( [ [ 'rid-hoiho-1', 73.5, 0 ], [ 'rid-hoiho-2', 81.5, 0 ] ], $this->facts( 'SELECT rid, ms, profiled FROM requests ORDER BY rid' ) );
	}

	/**
	 * Requests to one URL from one agent in one batch name one `servers`, one
	 * `urls` and one `agents` row, and the next batch names them again.
	 */
	public function test_a_batch_names_each_url_and_agent_once(): void {
		$sb       = $this->armed_stats_builder();
		$recorder = VerbHarness::ask_recorder();
		$sb->sink( $recorder );
		$weka = [ 'url' => 'https://kea.example/weka-3302', 'user_agent' => 'Weka/2.7' ];
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-weka-a' ] + $weka ) );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-weka-b' ] + $weka ) );
		$sb->flush();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-weka-c' ] + $weka ) );
		$sb->flush();

		$tables = \array_map( static fn ( array $asked ): array => \array_column( $asked['value']['ROWS'], 1 ), $recorder->asked );
		$this->assertSame( [ [ 'servers', 'urls', 'agents', 'owners', 'requests', 'requests' ], [ 'servers', 'urls', 'agents', 'owners', 'requests' ] ], $tables );
		$this->assertSame( [ [ 3, 1, 1 ] ], $this->facts( 'SELECT count(*), count( DISTINCT url_id ), count( DISTINCT ua_id ) FROM requests' ) );
	}

	/** A record with no rid could name no request row: it is refused before anything is buffered. */
	public function test_a_record_with_no_rid_is_refused(): void {
		$sb = $this->armed_stats_builder();

		try {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => '', 'url' => 'https://kea.example/hoiho-41' ] ) );
			$this->fail( 'the record is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'no rid', $e->getMessage() );
		}
		$this->assertSame( [], $sb->save_state(), 'the checkpoint carries nothing' );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM urls' ) );
	}

	/**
	 * Memory scales with ops, not requests: a batch whose requests carry wide
	 * profiles is written once its ops reach the budget, long before a
	 * thousand requests.
	 */
	public function test_a_batch_reaching_the_op_budget_writes_before_the_request_limit(): void {
		$sb      = $this->armed_stats_builder();
		$entries = [];
		for ( $e = 0; $e < 37; $e++ ) {
			$entries[ "SELECT kakariki {$e}" ] = [ 0.5, 1 ];
		}
		// Its URL, page, request, hour and URL window row, 38 timing rows four times, two owner rows.
		$per  = 5 + 4 * 38 + 2;
		// Once per batch: the server, the category, 38 entries and the owner.
		$need = (int) \ceil( ( Stats_Builder_Node::BUFFERED_OPS_LIMIT - 41 ) / $per );
		$fill = fn ( int $i ) => $this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-kakariki-{$i}", 'url' => "https://kea.example/kakariki-{$i}", 'user_agent' => '', 'profiles' => [ 'sql' => [ 'time' => 18.5, 'count' => 37, 'entries' => $entries ] ] ] ) );
		for ( $i = 1; $i < $need; $i++ ) {
			$fill( $i );
		}
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'nothing written below the budget' );

		$fill( $need );

		$this->assertLessThan( Stats_Builder_Node::BUFFERED_REQUESTS_LIMIT, $need );
		$this->assertSame( 20_000, Stats_Builder_Node::BUFFERED_OPS_LIMIT );
		$this->assertSame( [ [ $need ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'the record reaching the budget wrote the batch' );
	}

	public function test_a_thousand_buffered_requests_flush_before_the_timer(): void {
		$sb    = $this->armed_stats_builder();
		$limit = Stats_Builder_Node::BUFFERED_REQUESTS_LIMIT;
		for ( $i = 1; $i < $limit; $i++ ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-ruru-{$i}", 'url' => "https://kea.example/ruru-{$i}", 'duration_ms' => 23.0 ] ) );
		}
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'nothing written below the limit' );

		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-ruru-{$limit}", 'url' => "https://kea.example/ruru-{$limit}", 'duration_ms' => 23.0 ] ) );

		$this->assertSame( 1000, $limit );
		$this->assertSame( [ [ $limit ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'the record reaching the limit wrote the batch' );
	}

	public function test_the_router_tick_writes_at_the_flush_cadence(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hihi-1', 'duration_ms' => 17.0 ] ) );
		$this->tick_router( 0 );
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-hihi-2', 'duration_ms' => 19.0 ] ) );
		$this->tick_router( Stats_Builder_Node::FLUSH_INTERVAL_SEC - 1 );
		$between = $this->facts( 'SELECT count(*) FROM requests' );
		$this->tick_router( 1 );

		$this->assertSame( 5, Stats_Builder_Node::FLUSH_INTERVAL_SEC );
		$this->assertSame( [ [ 1 ] ], $between, 'a tick inside the interval writes nothing' );
		$this->assertSame( [ [ 2 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'the tick at the interval writes the rest' );
	}

	public function test_the_checkpoint_writes_the_rows_and_carries_nothing(): void {
		$sb = $this->armed_stats_builder();
		$this->fill_flame( $sb, $this->completed_request( [ 'rid' => 'rid-pipipi' ] ) );

		$this->assertSame( [], $sb->save_state(), 'the checkpoint carries nothing' );
		$this->assertSame( [ [ 'rid-pipipi' ] ], $this->facts( 'SELECT rid FROM requests' ), 'the checkpoint wrote what it passed' );
	}

	/**
	 * A ROWS write the Row_Table refuses stops the worker plainly, carrying
	 * the refusal: the flames reader commits no checkpoint past the flames
	 * the buffer held, and dead-letters none of them.
	 */
	public function test_a_failed_rows_write_stops_the_worker_without_committing(): void {
		$tmp   = $this->make_temp_dir( 'stats-builder-refused-' );
		$sb    = $this->armed_stats_builder();
		$topic = new Topic_Node();
		$topic->arguments( [ "{$tmp}/flames.p{partition}", '1', '1048576' ] );
		foreach ( [ 'rid-toutouwai-1', 'rid-toutouwai-2' ] as $rid ) {
			$request = $this->completed_request( [ 'rid' => $rid, 'url' => 'https://kea.example/toutouwai', 'duration_ms' => 71.0 ] );
			$topic->fill( self::flame_at( $request, $this->record_request( $request ) ) );
		}
		$topic->flush();
		$consumer = $this->flames_consumer( $tmp, $sb );
		$this->break_facts();

		$thrown = null;
		try {
			$this->pump_consumer( $consumer );
			$consumer->checkpoint();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'the refused write stops the worker' );
		$this->assertFalse( Worker_Should_Stop::is_clean( $thrown ), 'the reader must replay the flames' );
		$this->assertStringContainsString( 'stats write failed', $thrown->getMessage() );
		$this->assertStringContainsString( 'requests', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertLessThanOrEqual( 0, $this->read_private( $consumer, 'checkpoint_offset' ), 'no checkpoint commits past the flames' );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
		$this->assertSame( 2, $this->get_stats( $sb )['buffered_requests'], 'the buffer is kept' );
	}

	/** `flames:consumer` over `{$tmp}/flames.p0`, feeding `$sb` and checkpointing it. */
	private function flames_consumer( string $tmp, Stats_Builder_Node $sb ): Consumer_Node {
		$consumer = new Consumer_Node();
		$consumer->arguments( [ "{$tmp}/flames.p0", "{$tmp}/offsets/sb/p0", "{$tmp}/deadletter/sb/p0" ] );
		$consumer->name( 'flames:consumer' );
		$consumer->sink( $sb );
		$consumer->add_snapshot_node( 'sb' );
		return $consumer;
	}

	/**
	 * Flames whose records cannot be read, each a way a record goes: its
	 * segment pruned, its place torn, its place unreadable, no place at all.
	 *
	 * @return array<string,array{0:\Closure(string): (string|null)}>
	 */
	public static function places_gone(): array {
		return [
			'a pruned segment' => [ static fn ( string $crumb ): string => '417:0:' . \Newspack_Nodes\Log_Position::crumb( $crumb )['length'] ],
			'a torn read'      => [ static fn ( string $crumb ): string => \preg_replace( '/:(\d+)$/', ':41', $crumb ) ],
			'an unparsed place' => [ static fn ( string $crumb ): string => 'kea-gone-417' ],
			'no place'         => [ static fn ( string $crumb ): ?string => null ],
		];
	}

	/**
	 * A flame whose record is gone is skipped and counted, and the flames
	 * reader commits past it rather than dead-letter it.
	 *
	 * @param \Closure(string): (string|null) $place The flame's `request_at`, from its record's real one.
	 */
	#[DataProvider( 'places_gone' )]
	public function test_a_flame_whose_record_is_gone_is_skipped_and_counted( \Closure $place ): void {
		$tmp   = $this->make_temp_dir( 'stats-builder-gone-' );
		$sb    = $this->armed_stats_builder();
		$topic = new Topic_Node();
		$topic->arguments( [ "{$tmp}/flames.p{partition}", '1', '1048576' ] );
		$gone = $this->completed_request( [ 'rid' => 'rid-moa-gone', 'url' => 'https://kea.example/moa-gone' ] );
		$topic->fill( self::flame_at( $gone, $place( $this->record_request( $gone ) ) ) );
		$kept = $this->completed_request( [ 'rid' => 'rid-moa-kept', 'url' => 'https://kea.example/moa-kept' ] );
		$topic->fill( self::flame_at( $kept, $this->record_request( $kept ) ) );
		$topic->flush();
		$consumer = $this->flames_consumer( $tmp, $sb );

		$this->pump_consumer( $consumer );
		$consumer->checkpoint();

		$this->assertSame( [ [ 'rid-moa-kept' ] ], $this->facts( 'SELECT rid FROM requests' ), 'the gone record wrote nothing; the next was written' );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
		$this->assertSame( \filesize( "{$tmp}/flames.p0/0.log" ), $this->read_private( $consumer, 'checkpoint_offset' ), 'the cursor committed past both' );
		$stats = $this->get_stats( $sb );
		$this->assertSame( 1, $stats['records_gone'] );
	}

	/**
	 * A requests directory that does not exist is a misnamed one, since a
	 * flame exists only after its record landed: the worker stops naming it,
	 * and the flames reader commits nothing past the flame.
	 */
	public function test_a_misnamed_requests_directory_stops_the_worker_naming_it(): void {
		$tmp   = $this->make_temp_dir( 'stats-builder-misnamed-' );
		$sb    = $this->armed_stats_builder();
		$typo  = \dirname( $this->requests_partition() ) . '/request.p{partition}';
		$sb->set_requests_partition( $typo );
		$topic = new Topic_Node();
		$topic->arguments( [ "{$tmp}/flames.p{partition}", '1', '1048576' ] );
		$request = $this->completed_request( [ 'rid' => 'rid-hoiho-typo', 'url' => 'https://kea.example/hoiho-typo' ] );
		$topic->fill( self::flame_at( $request, $this->record_request( $request ) ) );
		$topic->flush();
		$consumer = $this->flames_consumer( $tmp, $sb );

		$thrown = null;
		try {
			$this->pump_consumer( $consumer );
			$consumer->checkpoint();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertSame( 'stats read failed', $thrown->getMessage() );
		$this->assertStringContainsString( \dirname( $typo ) . '/request.p0', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertLessThanOrEqual( 0, $this->read_private( $consumer, 'checkpoint_offset' ), 'no checkpoint commits past the flame' );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
		$this->assertSame( 0, $this->get_stats( $sb )['records_gone'], 'a misnamed directory is not a gone record' );
	}

	/**
	 * A row times the request its record carries, never the flame's value,
	 * which is a rendering artifact (decision 12).
	 */
	public function test_a_row_times_the_records_duration_not_the_flames_value(): void {
		$sb      = $this->armed_stats_builder();
		$request = $this->completed_request( [ 'rid' => 'rid-kotuku-356', 'url' => 'https://kea.example/kotuku-356', 'duration_ms' => 356.0 ] );
		$flame   = self::flame_at( $request, $this->record_request( $request ) );
		$this->assertSame( 725.0, $flame[ Message::VALUE ]['value'], 'the flame carries a value of its own' );

		$sb->fill( $flame );
		$sb->flush();

		$this->assertSame( [ [ 356.0 ] ], $this->facts( 'SELECT ms FROM requests' ) );
		$this->assertSame( [ [ 356.0, 356.0 ] ], $this->facts( 'SELECT sum_ms, max_ms FROM requests_hourly' ) );
	}

	/**
	 * Gone records are tallied in the `stats writes` narration. A run of them
	 * warns at its start, naming its first rid, and the warning is throttled
	 * on its text alone, so a second run inside the throttle's window stays
	 * quiet and one after it warns again.
	 */
	public function test_a_run_of_gone_records_warns_at_its_start_throttled_by_text(): void {
		$sb     = $this->armed_stats_builder();
		$off    = static fn ( string $rid ): array => self::flame_at( [ 'rid' => $rid, 'url' => "https://kea.example/{$rid}" ], '903:0:71' );
		$found  = $this->completed_request( [ 'rid' => 'rid-kaki-3', 'url' => 'https://kea.example/kaki-3' ] );
		$at     = $this->record_request( $found );
		$later  = $this->completed_request( [ 'rid' => 'rid-kaki-5', 'url' => 'https://kea.example/kaki-5' ] );
		$at_too = $this->record_request( $later );
		Core::$recent_log = [];

		$sb->fill( $off( 'rid-kaki-1' ) );
		$sb->fill( $off( 'rid-kaki-2' ) );
		$sb->fill( self::flame_at( $found, $at ) );
		$sb->fill( $off( 'rid-kaki-4' ) );
		Core::$now += Core::$log_timeout + 1;
		Core::prune_logs();
		$sb->fill( self::flame_at( $later, $at_too ) );
		$sb->fill( $off( 'rid-kaki-6' ) );

		$warned = \array_values( \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'records are gone' ) ) );
		$this->assertCount( 2, $warned, 'the run inside the window is throttled' );
		$this->assertStringContainsString( 'from rid rid-kaki-1', $warned[0] );
		$this->assertStringContainsString( 'from rid rid-kaki-6', $warned[1] );
		$this->assertSame( [], \array_filter( \array_keys( Core::$recent_log_timers ), static fn ( string $key ): bool => \str_contains( $key, 'rid-kaki' ) ), 'no rid keys the throttle' );
		$stats = $this->get_stats( $sb );
		$this->assertSame( 4, $stats['records_gone'] );
		$this->assertSame( 4, $stats['narration'][ Flame_Tree::STATS_WRITES ]['records gone'] ?? null );
		$this->assertSame( 2, $stats['buffered_requests'] );
	}

	/**
	 * A tally of gone records reaches the narration on the rollup cadence
	 * though no flush has rows to write.
	 */
	public function test_gone_records_alone_are_told_on_the_rollup(): void {
		$sb  = $this->armed_stats_builder();
		$now = \gmmktime( 9, 41, 0, 10, 21, 2026 );
		// The requests directory exists once a record lands in it.
		$this->record_request( $this->completed_request( [ 'rid' => 'rid-weweia-0' ] ) );
		Core::$now = $now;
		foreach ( [ 'rid-weweia-1', 'rid-weweia-2', 'rid-weweia-3' ] as $rid ) {
			$sb->fill( self::flame_at( [ 'rid' => $rid, 'url' => "https://kea.example/{$rid}" ], '611:0:73' ) );
		}

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			static function () use ( $sb, $now ): void {
				Core::$now = $now;
				$sb->flush();
			}
		);

		$told = self::entries_of( $entries, Flame_Tree::STATS_WRITES );
		$this->assertCount( 1, $told );
		$this->assertStringContainsString( '3 records gone', (string) ( $told[0]['m'] ?? '' ) );
		$this->assertSame( 0, $this->get_stats( $sb )['buffered_rows'], 'no rows were held' );
	}

	/** The path of the requests segment a record's ID names, in partition 0. */
	private function segment_of( string $crumb ): string {
		$segment = \Newspack_Nodes\Log_Position::crumb( $crumb )['segment'] ?? throw new \LogicException( "no segment in {$crumb}" );
		return \dirname( $this->requests_partition() ) . "/requests.p0/{$segment}.log";
	}

	/**
	 * A segment that exists but will not read is a read failure, not a gone
	 * record: the worker stops plainly and the flames reader replays it.
	 */
	public function test_an_unreadable_segment_stops_the_worker_and_replays(): void {
		$tmp     = $this->make_temp_dir( 'stats-builder-unreadable-' );
		$sb      = $this->armed_stats_builder();
		$topic   = new Topic_Node();
		$topic->arguments( [ "{$tmp}/flames.p{partition}", '1', '1048576' ] );
		$request = $this->completed_request( [ 'rid' => 'rid-kakapo-0600', 'url' => 'https://kea.example/kakapo-0600' ] );
		$crumb   = $this->record_request( $request );
		$topic->fill( self::flame_at( $request, $crumb ) );
		$topic->flush();
		$consumer = $this->flames_consumer( $tmp, $sb );
		$segment  = $this->segment_of( $crumb );

		\chmod( $segment, 0000 );
		$thrown = null;
		try {
			$this->pump_consumer( $consumer );
			$consumer->checkpoint();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		} finally {
			\chmod( $segment, 0644 );
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertSame( 'stats read failed', $thrown->getMessage() );
		$this->assertStringContainsString( $segment, (string) $thrown->getPrevious()?->getMessage() );
		$this->assertLessThanOrEqual( 0, $this->read_private( $consumer, 'checkpoint_offset' ), 'no checkpoint commits past the flame' );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
		$this->assertSame( 0, $this->get_stats( $sb )['records_gone'], 'an unreadable record is not a gone one' );
	}

	/** A record whose segment was deleted is gone. */
	public function test_a_record_whose_segment_was_deleted_is_gone(): void {
		$sb      = $this->armed_stats_builder();
		$request = $this->completed_request( [ 'rid' => 'rid-tuturuatu-1', 'url' => 'https://kea.example/tuturuatu-1' ] );
		$crumb   = $this->record_request( $request );
		\unlink( $this->segment_of( $crumb ) );

		$sb->fill( self::flame_at( $request, $crumb ) );

		$this->assertSame( 1, $this->get_stats( $sb )['records_gone'] );
		$this->assertSame( 0, $this->get_stats( $sb )['buffered_requests'] );
	}

	/**
	 * A place past the end of a segment that exists names bytes the segment
	 * never held, as after a segment id is reused: gone, not a read failure.
	 */
	public function test_a_place_past_the_end_of_its_segment_is_gone(): void {
		$sb      = $this->armed_stats_builder();
		$request = $this->completed_request( [ 'rid' => 'rid-pihoihoi-1', 'url' => 'https://kea.example/pihoihoi-1' ] );
		$crumb   = $this->record_request( $request );
		$past    = \filesize( $this->segment_of( $crumb ) ) + 4091;

		$sb->fill( self::flame_at( $request, "0:{$past}:83" ) );

		$this->assertSame( 1, $this->get_stats( $sb )['records_gone'] );
	}

	/**
	 * A record whose KEY is another request's is a corrupt place, not a
	 * pruned one: the fold throws, and the flames reader dead-letters the
	 * flame and reads on.
	 */
	public function test_a_record_keyed_to_another_rid_dead_letters_the_flame(): void {
		$tmp   = $this->make_temp_dir( 'stats-builder-misplaced-' );
		$sb    = $this->armed_stats_builder();
		$topic = new Topic_Node();
		$topic->arguments( [ "{$tmp}/flames.p{partition}", '1', '1048576' ] );
		$other = $this->completed_request( [ 'rid' => 'rid-kea-other', 'url' => 'https://kea.example/kea-other' ] );
		$theirs = $this->record_request( $other );
		$topic->fill( self::flame_at( $this->completed_request( [ 'rid' => 'rid-kea-mine', 'url' => 'https://kea.example/kea-mine' ] ), $theirs ) );
		$topic->flush();
		$consumer = $this->flames_consumer( $tmp, $sb );

		$this->pump_consumer( $consumer );

		$this->assertSame( 1, $consumer->list_deadletter( 10 )['total'] );
		$this->assertSame( 0, $this->get_stats( $sb )['records_gone'], 'a misplaced record is not a gone one' );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'rid-kea-other' );
		$sb->fill( self::flame_at( $this->completed_request( [ 'rid' => 'rid-kea-mine' ] ), $theirs ) );
	}

	/**
	 * Pin the clock 30 seconds into a bucket and resolve the Row_Table the
	 * shipped topologies declare, as the window tests run.
	 */
	private function window_world(): void {
		Core::$now = (float) self::NOW;
		$this->use_scratch_config( $this->make_temp_dir( 'category-window-' ) );
		$this->activate_shipped( 'stats-builder', 1 );
	}

	/**
	 * A builder in its own graph beside the Row_Table, both answering the
	 * Router as a topology wires them, with no window watermark seeded.
	 */
	private function window_builder(): Stats_Builder_Node {
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$table = new Row_Table_Node();
		$table->name( self::FACTS_TABLE );
		$table->arguments( [ 'Flame_Facts' ] );
		$table->sink( $interpreter );
		$sb = new Stats_Builder_Node();
		$sb->name( 'stats-builder' );
		$sb->set_facts_target( self::FACTS_TABLE );
		$sb->set_requests_partition( $this->requests_partition() );
		$sb->sink( $interpreter );
		return $sb;
	}

	/**
	 * A second builder over the first one's file, as a respawned worker
	 * runs: the first gone, the graph and its Row_Table kept.
	 */
	private function restarted( Stats_Builder_Node $sb ): Stats_Builder_Node {
		$sink = $sb->sink();
		$sb->remove_node();
		$next = new Stats_Builder_Node();
		$next->name( 'stats-builder' );
		$next->set_facts_target( self::FACTS_TABLE );
		$next->set_requests_partition( $this->requests_partition() );
		$next->sink( $sink );
		return $next;
	}

	/** Mark `$bucket` expired in partition 0's file, so it is the watermark. */
	private function seed_watermark( int $bucket ): void {
		$this->write_facts( 0, [ [ 'ignore', 'window_expiry', [ $bucket ] ] ] );
	}

	/** One timed request on `$server` whose profile spends `$ms` in `$category`. */
	private function fold( Stats_Builder_Node $sb, string $rid, string $category, float $ms, int $finished, string $server = 'example.com' ): void {
		$this->fill_flame(
			$sb,
			$this->completed_request(
				[
					'rid'         => $rid,
					'url'         => "https://{$server}/post/123",
					'timestamp'   => $finished - 1,
					'duration_ms' => 1000.0,
					'profiles'    => [ $category => [ 'time' => $ms, 'count' => 2, 'entries' => [] ] ],
				]
			)
		);
	}

	/** The window's live rows as category => [ ms, calls, requests ]. */
	private function window(): array {
		$w    = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT MAX( bucket ) FROM p0.window_expiry', [] ) );
		$rows = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT c.name, w.ms, w.calls, w.requests FROM p0.category_window AS w JOIN p0.categories AS c ON c.id = w.category_id JOIN p0.entries AS e ON e.id = w.entry_id WHERE w.last_bucket > ? AND w.requests > 0 AND e.name = ? ORDER BY c.name', [ $w[0][0], '' ] ) );
		$out  = [];
		foreach ( $rows as [ $category, $ms, $calls, $requests ] ) {
			$out[ $category ] = [ $ms, $calls, $requests ];
		}
		return $out;
	}

	/** The window's live rows as server => category => [ ms, calls, requests ]. */
	private function window_by_server(): array {
		$w    = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT MAX( bucket ) FROM p0.window_expiry', [] ) );
		$rows = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT s.name, c.name, w.ms, w.calls, w.requests FROM p0.category_window AS w JOIN p0.servers AS s ON s.id = w.server_id JOIN p0.categories AS c ON c.id = w.category_id JOIN p0.entries AS e ON e.id = w.entry_id WHERE w.last_bucket > ? AND w.requests > 0 AND e.name = ? ORDER BY s.name, c.name', [ $w[0][0], '' ] ) );
		$out  = [];
		foreach ( $rows as [ $server, $category, $ms, $calls, $requests ] ) {
			$out[ $server ][ $category ] = [ $ms, $calls, $requests ];
		}
		return $out;
	}

	/** Every window row as stored, zeroed and negative rows included. */
	private function raw_window(): array {
		return Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT w.ms, w.calls, w.requests FROM p0.category_window AS w JOIN p0.categories AS c ON c.id = w.category_id ORDER BY c.name', [] ) );
	}

	/**
	 * Each server's sums stay its own: a rebuild files `timings_site` per
	 * server, a fold adds to its server's row, an expiry subtracts each
	 * server's own share of the bucket leaving, and a rebuild after the folds
	 * reproduces each server's rows.
	 */
	public function test_the_category_window_keeps_each_servers_sums(): void {
		$this->window_world();
		$oldest = Bucket::id( self::NOW ) - Bucket::window() + 1;
		$this->seed_rows(
			0,
			[
				[ 'add', 'timings_site', [ 'bucket' => $oldest, 'server' => 'kea.example', 'worker' => 0, 'category' => 'hook:kaka_init', 'entry' => '', 'listener' => 0, 'ms' => 30.5, 'calls' => 4, 'requests' => 2 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $oldest + 3, 'server' => 'kea.example', 'worker' => 0, 'category' => 'hook:kaka_init', 'entry' => '', 'listener' => 0, 'ms' => 2.0, 'calls' => 1, 'requests' => 1 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $oldest, 'server' => 'tui.example', 'worker' => 0, 'category' => 'hook:kaka_init', 'entry' => '', 'listener' => 0, 'ms' => 6.25, 'calls' => 1, 'requests' => 1 ] ],
			]
		);
		$sb = $this->window_builder();
		$sb->flush();

		$this->assertSame( [ 'kea.example' => [ 'hook:kaka_init' => [ 32.5, 5, 3 ] ], 'tui.example' => [ 'hook:kaka_init' => [ 6.25, 1, 1 ] ] ], $this->window_by_server(), 'the rebuild files each server apart' );

		$this->fold( $sb, 'rid-kea-1', 'hook:kaka_init', 12.5, self::NOW, 'kea.example' );
		$this->fold( $sb, 'rid-tui-1', 'hook:kaka_init', 7.25, self::NOW, 'tui.example' );
		$sb->flush();

		$this->assertSame( [ 'kea.example' => [ 'hook:kaka_init' => [ 45.0, 7, 4 ] ], 'tui.example' => [ 'hook:kaka_init' => [ 13.5, 3, 2 ] ] ], $this->window_by_server(), 'each fold adds to its own server' );

		Core::$now += Bucket::SECONDS;
		$sb->flush();

		$this->assertSame( [ 'kea.example' => [ 'hook:kaka_init' => [ 14.5, 3, 2 ] ], 'tui.example' => [ 'hook:kaka_init' => [ 7.25, 2, 1 ] ] ], $this->window_by_server(), 'the expiry subtracts each server\'s own share' );

		// A row only a rebuild negates, so the restart below proves it rebuilt.
		self::rows_into( Core::node( self::FACTS_TABLE ), self::facts_ops( [ [ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, Bucket::id( (int) Core::$now ) ) ] ] ) );
		Core::$now += ( Bucket::RETAIN_SLACK + 1 ) * Bucket::SECONDS;
		$this->restarted( $sb )->flush();

		$this->assertSame( [ 'kea.example' => [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], 'tui.example' => [ 'hook:kaka_init' => [ 7.25, 2, 1 ] ] ], $this->window_by_server(), 'a rebuild after the folds files each server\'s folds apart' );
	}

	public function test_a_folded_request_adds_its_categories_to_the_window(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-1', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );
	}

	public function test_a_bucket_leaving_the_window_is_subtracted_once_across_a_restarts_replay(): void {
		$this->window_world();
		$sb    = $this->window_builder();
		$first = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold( $sb, 'rid-kaka-2', 'hook:kaka_init', 12.5, $first );
		$sb->flush();

		Core::$now += Bucket::SECONDS;
		$sb->flush();
		$next = $this->restarted( $sb );
		$this->fold( $next, 'rid-kaka-2', 'hook:kaka_init', 12.5, $first );
		$next->flush();

		$this->assertSame( [], $this->window() );
		$this->assertSame( [ [ 0.0, 0, 0 ] ], $this->raw_window(), 'the replay neither adds nor subtracts twice' );
	}

	public function test_an_idle_tick_expires_the_bucket_leaving_the_window(): void {
		$this->window_world();
		$sb    = $this->window_builder();
		$first = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold( $sb, 'rid-kaka-8', 'hook:kaka_init', 12.5, $first );
		$sb->flush();

		Core::$now += Bucket::SECONDS;
		$sb->fire_cb();

		$this->assertSame( [], $this->window(), 'a tick with nothing buffered still moves the window' );
	}

	/** A builder that never receives a flame still expires the window on its tick. */
	public function test_a_builder_given_no_flames_expires_the_window_on_its_tick(): void {
		$this->window_world();
		$bucket = Bucket::id( self::NOW ) - Bucket::window() + 1;
		$this->seed_rows(
			0,
			[
				[ 'add', 'timings_site', [ 'bucket' => $bucket, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:pukeko_init', 'entry' => '', 'listener' => 0, 'ms' => 41.5, 'calls' => 3, 'requests' => 2 ] ],
				[ 'add', 'category_window', self::window_row( 'hook:pukeko_init', 41.5, 3, 2, $bucket ) ],
			]
		);
		$this->seed_watermark( $bucket - 1 );
		$sb = $this->window_builder();
		$this->assertSame( [ 'hook:pukeko_init' => [ 41.5, 3, 2 ] ], $this->window() );

		Core::$now += Bucket::SECONDS;
		$sb->fire_cb();

		$this->assertSame( [], $this->window(), 'the bucket leaving the window was subtracted' );
		$this->assertSame( [ [ 0.0, 0, 0 ] ], $this->raw_window() );
	}

	public function test_a_record_older_than_the_watermark_adds_nothing(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-3', 'hook:kaka_init', 12.5, self::NOW - Bucket::window() * Bucket::SECONDS );
		$sb->flush();

		$this->assertSame( [], $this->window() );
	}

	public function test_a_replayed_record_adds_nothing_twice(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-4', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();
		$this->fold( $sb, 'rid-kaka-4', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );
	}

	public function test_a_start_with_no_state_rebuilds_the_window_from_timings_site(): void {
		$this->window_world();
		$bucket = Bucket::id( self::NOW );
		$this->seed_rows(
			0,
			[
				[ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, $bucket ) ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - 3, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 30.5, 'calls' => 4, 'requests' => 2 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - Bucket::window() + 1, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 5.25, 'calls' => 3, 'requests' => 1 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - Bucket::window(), 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 70.0, 'calls' => 1, 'requests' => 1 ] ],
			]
		);

		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-5', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ], 'hook:tui_init' => [ 35.75, 7, 3 ] ], $this->window(), 'the stale row reads as gone, the bucket outside the window adds nothing' );
	}

	/**
	 * A fresh builder resumes at the file's watermark when the span has not
	 * changed, with one read: no rebuild negates a row `timings_site` does
	 * not hold, and a fold in the same bucket reads nothing more.
	 */
	public function test_a_fresh_builder_resumes_at_the_files_watermark_with_one_read(): void {
		$this->window_world();
		$this->seed_stale_window_row();
		$this->seed_watermark( Bucket::id( self::NOW ) - Bucket::window() );
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-6', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();
		\chmod( $this->facts_file( 0 ), 0000 );
		try {
			$this->fold( $sb, 'rid-kaka-7', 'hook:kaka_init', 3.5, self::NOW );
			$sb->flush();
		} finally {
			\chmod( $this->facts_file( 0 ), 0644 );
		}

		$this->assertSame( [ 'hook:kaka_init' => [ 16.0, 4, 2 ], 'hook:stale_init' => [ 999.0, 9, 9 ] ], $this->window(), 'no rebuild negated the row timings_site does not hold, and no read followed the first' );
	}

	public function test_save_state_writes_the_buffer_and_carries_nothing(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-7', 'hook:kaka_init', 12.5, self::NOW );

		$this->assertSame( [], $sb->save_state() );
		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );
	}

	public function test_a_record_in_a_bucket_the_file_expired_adds_nothing_after_a_restart(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-9', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();
		Core::$now += Bucket::SECONDS;
		$sb->flush();

		$next = $this->restarted( $sb );
		$this->fold( $next, 'rid-kaka-10', 'hook:kaka_init', 7.0, self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS );
		$next->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window(), 'a record in a bucket the file already expired adds nothing' );
	}

	public function test_a_rebuild_marks_its_watermark_so_a_restart_rebuilds_nothing(): void {
		$this->window_world();
		$bucket = Bucket::id( self::NOW );
		$oldest = $bucket - Bucket::window() + 1;
		$this->seed_rows(
			0,
			[
				[ 'add', 'timings_site', [ 'bucket' => $oldest, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 30.5, 'calls' => 4, 'requests' => 2 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $oldest - 1, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 6.0, 'calls' => 1, 'requests' => 1 ] ],
			]
		);
		$sb = $this->window_builder();
		$sb->flush();
		// A sum the window does not hold, which only a second rebuild would add.
		self::rows_into( Core::node( self::FACTS_TABLE ), self::facts_ops( [ [ 'add', 'timings_site', [ 'bucket' => $oldest, 'server' => 'tui.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 4.0, 'calls' => 1, 'requests' => 1 ] ] ] ) );

		$this->restarted( $sb )->flush();

		$this->assertSame( [ 'hook:tui_init' => [ 30.5, 4, 2 ] ], $this->window(), 'the restart resumed at the rebuild\'s marks' );
	}

	/** A watermark further back than `timings_site` keeps past the span rebuilds. */
	public function test_a_watermark_past_the_retained_slack_rebuilds(): void {
		$this->window_world();
		$this->seed_stale_window_row();
		$this->seed_watermark( Bucket::id( self::NOW ) - Bucket::window() - Bucket::RETAIN_SLACK - 1 );
		$this->window_builder()->flush();

		$this->assertSame( [], $this->window(), 'the rebuild negates the row timings_site does not hold' );
	}

	/** A watermark inside the retained slack subtracts the buckets leaving, one by one. */
	public function test_a_watermark_inside_the_retained_slack_subtracts_per_bucket(): void {
		$this->window_world();
		$this->seed_stale_window_row();
		$this->seed_watermark( Bucket::id( self::NOW ) - Bucket::window() - Bucket::RETAIN_SLACK );
		$this->window_builder()->flush();

		$this->assertSame( [ 'hook:stale_init' => [ 999.0, 9, 9 ] ], $this->window(), 'the expiry touches only the buckets leaving' );
	}

	/**
	 * Lowering `min_lifetime` from 7,200 to 3,600 expires the buckets that
	 * left the 12 at the next start, one by one: no rebuild runs, so a row
	 * `timings_site` does not hold survives.
	 */
	public function test_a_shrunk_span_expires_the_buckets_that_left_it(): void {
		$this->use_min_lifetime( 7200 );
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-25', 'hook:kaka_init', 7.25, self::NOW - 20 * Bucket::SECONDS );
		$this->fold( $sb, 'rid-kaka-26', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();
		self::rows_into( Core::node( self::FACTS_TABLE ), self::facts_ops( [ [ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, Bucket::id( self::NOW ) ) ] ] ) );
		$this->assertSame( [ 'hook:kaka_init' => [ 19.75, 4, 2 ], 'hook:stale_init' => [ 999.0, 9, 9 ] ], $this->window() );

		$this->use_min_lifetime( 3600 );
		$this->restarted( $sb )->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ], 'hook:stale_init' => [ 999.0, 9, 9 ] ], $this->window() );
	}

	public function test_a_buffer_written_before_a_failing_expiry_stays_and_replays_once(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-11', 'hook:kaka_init', 12.5, self::NOW );
		Core::$now += Bucket::SECONDS;
		// The writer holds its file open; only the expiry's new reader fails.
		\chmod( $this->facts_file( 0 ), 0000 );
		$thrown = null;
		try {
			$sb->flush();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		} finally {
			\chmod( $this->facts_file( 0 ), 0644 );
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertInstanceOf( \PDOException::class, $thrown->getPrevious(), 'the expiry\'s read failed, not the write' );
		$this->assertSame( [ [ 1 ] ], $this->request_count(), 'the buffer was written before the expiry failed' );
		$this->assertNull( $sb->idle_since(), 'the buffer is kept' );

		$this->fold( $sb, 'rid-kaka-11', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();

		$this->assertNotNull( $sb->idle_since(), 'the kept batch and the replay are written' );
		$this->assertSame( [ [ 1 ] ], $this->request_count(), 'the request row exists once' );
		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window(), 'the replay adds nothing twice' );
	}

	/** The `requests` rows partition 0's file holds. */
	private function request_count(): array {
		return Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT count(*) FROM p0.requests', [] ) );
	}

	public function test_a_fill_whose_sync_fails_raises_a_plain_stop_and_buffers_nothing(): void {
		$this->window_world();
		$sb = new Stats_Builder_Node();
		$sb->name( 'sb-kaka' );
		$sb->set_requests_partition( $this->requests_partition() );
		$sb->sink( new Capture_Sink_Node() );

		$thrown = null;
		try {
			$this->fold( $sb, 'rid-kaka-12', 'hook:kaka_init', 12.5, self::NOW );
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertStringContainsString( 'stats window failed', $thrown->getMessage() );
		$this->assertStringContainsString( 'set_facts_target', (string) $thrown->getPrevious()?->getMessage(), 'no Row_Table was named to read the watermark from' );
		$this->assertNotNull( $sb->idle_since(), 'nothing is buffered' );
	}

	public function test_a_due_expiry_with_no_row_table_named_fails_naming_the_verb(): void {
		$this->window_world();
		$sb = new Stats_Builder_Node();
		$sb->name( 'sb-kaka' );
		$sb->sink( new Capture_Sink_Node() );

		$thrown = null;
		try {
			$sb->flush();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown );
		$this->assertStringContainsString( 'set_facts_target', (string) $thrown->getPrevious()?->getMessage() );
	}

	/**
	 * At a 24-bucket span the window's oldest bucket is subtracted when it
	 * leaves, though the builder sat twelve buckets past it and a sweep ran
	 * first: retention keeps the span and an hour more.
	 */
	public function test_a_bucket_leaving_a_24_bucket_span_is_subtracted_before_retention_purges_it(): void {
		$this->use_min_lifetime( 7200 );
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-21', 'hook:kaka_init', 12.5, self::NOW - 23 * Bucket::SECONDS );
		$sb->flush();
		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );

		Core::$now += 12 * Bucket::SECONDS;
		$this->sweep_at_the_tick();
		$sb->flush();

		$this->assertSame( [], $this->window() );
		$this->assertSame( [ [ 0.0, 0, 0 ] ], $this->raw_window(), 'the sweep left the bucket for the expiry to subtract' );
	}

	/**
	 * Raising `min_lifetime` from 3,600 to 7,200 rebuilds nothing: builders
	 * restarted across bucket boundaries keep the window the 12-bucket one
	 * left and add what is newer, readers read `timings_site` meanwhile,
	 * and once the window's start passes the old watermark it equals
	 * `timings_site` summed over it and readers read it again.
	 */
	public function test_a_grown_span_catches_up_without_a_rebuild_across_restarts(): void {
		$this->use_min_lifetime( 3600 );
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold( $sb, 'rid-kaka-22', 'hook:kaka_init', 7.25, self::NOW - 20 * Bucket::SECONDS );
		$this->fold( $sb, 'rid-kaka-23', 'hook:kaka_init', 3.5, self::NOW - 5 * Bucket::SECONDS );
		$this->fold( $sb, 'rid-kaka-24', 'hook:kaka_init', 12.5, self::NOW );
		$sb->flush();
		$this->assertSame( [ [ 16.0, 4, 2 ] ], $this->raw_window(), 'twenty buckets back is outside a 12-bucket window' );

		$this->use_min_lifetime( 7200 );
		$next = $this->restarted( $sb );
		$next->flush();
		$this->assertSame( [ [ 16.0, 4, 2 ] ], $this->raw_window(), 'no rebuild adds the bucket the shorter window expired' );
		$this->assertSame( 'timings_site', $this->span_ranking()['table'] );

		Core::$now += 5 * Bucket::SECONDS;
		$next = $this->restarted( $next );
		$this->fold( $next, 'rid-kaka-25', 'hook:kaka_init', 2.25, (int) Core::$now );
		$next->flush();
		Core::$now += 4 * Bucket::SECONDS;
		$next = $this->restarted( $next );
		$next->flush();
		$this->assertSame( [ [ 18.25, 6, 3 ] ], $this->raw_window() );
		$this->assertSame( 'timings_site', $this->span_ranking()['table'] );

		Core::$now += 4 * Bucket::SECONDS;
		$next->flush();
		$ranking = $this->span_ranking();
		$this->assertSame( [ [ 18.25, 6, 3 ] ], $this->raw_window(), 'timings_site over the 24 buckets: the three newest requests' );
		$this->assertSame( 'category_window', $ranking['table'] );
		$this->assertSame( [ [ 'hook:kaka_init', 18.25 ] ], $ranking['rows'] );
	}

	/** A clock stepping back across a bucket boundary rebuilds nothing. */
	public function test_a_backward_step_across_a_bucket_boundary_rebuilds_nothing(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$sb->flush();
		self::rows_into( Core::node( self::FACTS_TABLE ), self::facts_ops( [ [ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, Bucket::id( self::NOW ) ) ] ] ) );

		Core::$now -= Bucket::SECONDS;
		$sb->flush();

		$this->assertSame( [ [ 999.0, 9, 9 ] ], $this->raw_window(), 'no rebuild negated the row timings_site does not hold' );
	}

	/** The URL the URL-window tests time, its query a shape. */
	private const KEA_URL = 'https://kea.example/tui?x=1';

	/** The URL window's columns from `count` on, in declared order. */
	private const URL_WINDOW_SUMS = 'w.count, w.timed, w.count_2xx, w.count_3xx, w.count_4xx, w.count_5xx, w.errors, w.sum_ms, w.sum_peak_mb, w.min_ms, w.max_ms, w.max_peak_mb, w.last_seen, w.last_bucket';

	/**
	 * One request on `$url` finishing in `$finished`'s bucket, `$ms` long.
	 *
	 * @param array<string,mixed> $over Record fields beside its rid, URL, start and duration.
	 */
	private function fold_url( Stats_Builder_Node $sb, string $rid, string $url, float $ms, int $finished, array $over = [] ): void {
		$this->fill_flame( $sb, $this->completed_request( \array_replace( [ 'rid' => $rid, 'url' => $url, 'timestamp' => $finished - 2, 'duration_ms' => $ms ], $over ) ) );
	}

	/**
	 * The URL window's live rows, those moved after the watermark, as
	 * `path worker` => its columns from `count` on.
	 *
	 * @return array<string,list<mixed>>
	 */
	private function url_window(): array {
		return $this->url_rows( 'SELECT u.path, w.worker, ' . self::URL_WINDOW_SUMS . ' FROM p0.urls_window AS w JOIN p0.urls AS u ON u.id = w.url_id WHERE w.last_bucket > ( SELECT MAX( bucket ) FROM p0.window_expiry ) ORDER BY u.path, w.worker' );
	}

	/**
	 * The same columns summed from `requests` over the buckets after the
	 * watermark: what the URL window must equal.
	 *
	 * @return array<string,list<mixed>>
	 */
	private function url_window_from_requests(): array {
		return $this->url_rows(
			"SELECT u.path, r.worker, COUNT( * ), COUNT( r.ms ), COUNT( CASE WHEN r.status BETWEEN 200 AND 299 THEN 1 END ), COUNT( CASE WHEN r.status BETWEEN 300 AND 399 THEN 1 END ), COUNT( CASE WHEN r.status BETWEEN 400 AND 499 THEN 1 END ), COUNT( CASE WHEN r.status BETWEEN 500 AND 599 THEN 1 END ), COUNT( CASE WHEN r.error IN ( 'T', 'F' ) THEN 1 END ), TOTAL( r.ms ), TOTAL( r.peak_mb ), MIN( r.ms ), MAX( r.ms ), MAX( r.peak_mb ), MAX( r.finished_at ), MAX( r.bucket )
			FROM p0.requests AS r JOIN p0.urls AS u ON u.id = r.url_id WHERE r.bucket > ( SELECT MAX( bucket ) FROM p0.window_expiry ) GROUP BY u.path, r.worker ORDER BY u.path, r.worker"
		);
	}

	/**
	 * Every URL window row as stored, live or not, as `path worker` => its
	 * columns from `count` on.
	 *
	 * @return array<string,list<mixed>>
	 */
	private function raw_url_window(): array {
		return $this->url_rows( 'SELECT u.path, w.worker, ' . self::URL_WINDOW_SUMS . ' FROM p0.urls_window AS w JOIN p0.urls AS u ON u.id = w.url_id ORDER BY u.path, w.worker' );
	}

	/**
	 * Rows led by a path and a worker flag, keyed by both.
	 *
	 * @return array<string,list<mixed>>
	 */
	private function url_rows( string $sql ): array {
		$out = [];
		foreach ( Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( $sql, [] ) ) as $row ) {
			$out[ "{$row[0]} {$row[1]}" ] = \array_slice( $row, 2 );
		}
		return $out;
	}

	/** The watermark partition 0's file holds. */
	private function url_watermark(): int {
		return Core::as_int( $this->facts( 'SELECT MAX( bucket ) FROM window_expiry' )[0][0] );
	}

	/**
	 * Each status class counts in its own column, an error by its kind, and
	 * an untimed request counts without reaching the extremes or `timed`.
	 */
	public function test_a_request_adds_its_url_window_row(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold_url( $sb, 'rid-tui-1', self::KEA_URL, 413.25, self::NOW, [ 'status_code' => 204, 'peak_mb' => 41.5 ] );
		$this->fold_url( $sb, 'rid-tui-2', self::KEA_URL, 97.5, self::NOW, [ 'status_code' => 302, 'peak_mb' => 18.25 ] );
		$this->fold_url( $sb, 'rid-tui-3', self::KEA_URL, 150.75, self::NOW, [ 'status_code' => 404, 'peak_mb' => 22.5 ] );
		$this->fold_url( $sb, 'rid-tui-4', self::KEA_URL, 220.5, self::NOW, [ 'status_code' => 503, 'peak_mb' => 30.0, 'error_status' => 'F' ] );
		$this->fold_url( $sb, 'rid-tui-5', self::KEA_URL, 913.5, self::NOW, [ 'status_code' => 504, 'peak_mb' => 12.0, 'error_status' => 'T' ] );
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertCount( 1, $window );
		$this->assertSame( [ 5, 4, 1, 1, 1, 2, 2, 882.0, 124.25, 97.5, 413.25, 41.5 ], \array_slice( \reset( $window ), 0, 12 ), 'the timeout counts, an error, but times nothing' );
	}

	/** A late bucket and a replayed rid each add nothing to the URL window. */
	public function test_a_request_at_the_watermark_adds_nothing_to_the_url_window(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold_url( $sb, 'rid-tui-6', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$sb->flush();
		$this->fold_url( $sb, 'rid-tui-7', 'https://kea.example/late', 97.5, self::NOW - Bucket::window() * Bucket::SECONDS );
		$this->fold_url( $sb, 'rid-tui-6', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$sb->flush();

		$this->assertSame( $this->url_window_from_requests(), $this->url_window() );
		$raw = $this->raw_url_window();
		$this->assertCount( 1, $raw, 'the late URL files no window row' );
		$this->assertSame( [ 1, 1, 1, 0, 0, 0, 0, 413.25, 41.5, 413.25, 413.25, 41.5 ], \array_slice( \reset( $raw ), 0, 12 ), 'the replay added nothing' );
	}

	public function test_expiring_a_bucket_subtracts_its_url_sums(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-8', self::KEA_URL, 150.75, $oldest, [ 'status_code' => 404, 'peak_mb' => 22.5 ] );
		$this->fold_url( $sb, 'rid-tui-9', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$this->fold_url( $sb, 'rid-tui-10', self::KEA_URL, 97.5, self::NOW, [ 'peak_mb' => 18.25 ] );
		$sb->flush();

		Core::$now += Bucket::SECONDS;
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertSame( [ 2, 2, 2, 0, 0, 0, 0, 510.75, 59.75, 97.5, 413.25, 41.5 ], \array_slice( \reset( $window ), 0, 12 ) );
	}

	/** The expiring bucket held the max, the min and the peak; each recomputes. */
	public function test_expiring_a_urls_extreme_recomputes_it(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-11', self::KEA_URL, 913.5, $oldest, [ 'peak_mb' => 88.75 ] );
		$this->fold_url( $sb, 'rid-tui-12', self::KEA_URL, 12.25, $oldest, [ 'peak_mb' => 9.5 ] );
		$this->fold_url( $sb, 'rid-tui-13', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$this->fold_url( $sb, 'rid-tui-14', self::KEA_URL, 97.5, self::NOW, [ 'peak_mb' => 18.25 ] );
		$sb->flush();
		$before = $this->url_window();
		$this->assertSame( [ 12.25, 913.5, 88.75 ], \array_slice( \reset( $before ), 9, 3 ) );

		Core::$now += Bucket::SECONDS;
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertSame( [ 2, 2, 2, 0, 0, 0, 0, 510.75, 59.75, 97.5, 413.25, 41.5 ], \array_slice( \reset( $window ), 0, 12 ) );
	}

	/**
	 * Two buckets leaving in one flush recompute in turn: the max the first
	 * leaves behind sits in the second, and the min in the second alone.
	 */
	public function test_expiring_two_buckets_at_once_recomputes_through_both(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-15', self::KEA_URL, 913.5, $oldest, [ 'peak_mb' => 88.75 ] );
		$this->fold_url( $sb, 'rid-tui-16', self::KEA_URL, 12.25, $oldest + Bucket::SECONDS, [ 'peak_mb' => 9.5 ] );
		$this->fold_url( $sb, 'rid-tui-17', self::KEA_URL, 600.5, $oldest + Bucket::SECONDS, [ 'peak_mb' => 50.25 ] );
		$this->fold_url( $sb, 'rid-tui-18', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$this->fold_url( $sb, 'rid-tui-19', self::KEA_URL, 97.5, self::NOW, [ 'peak_mb' => 18.25 ] );
		$sb->flush();

		Core::$now += 2 * Bucket::SECONDS;
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertSame( [ 2, 2, 2, 0, 0, 0, 0, 510.75, 59.75, 97.5, 413.25, 41.5 ], \array_slice( \reset( $window ), 0, 12 ) );
	}

	/**
	 * An expiry batch written again, its `window_expiry` rows already there,
	 * applies neither its negations nor the sets recomputing an extreme,
	 * which would overwrite a request added since.
	 */
	public function test_a_replayed_url_expiry_applies_nothing_twice(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-27', self::KEA_URL, 913.5, $oldest, [ 'peak_mb' => 88.75 ] );
		$this->fold_url( $sb, 'rid-tui-28', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$sb->flush();
		Core::$now += Bucket::SECONDS;
		$through = Bucket::id( (int) Core::$now ) - Bucket::window();
		$batch   = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => ( new \ReflectionMethod( Stats_Builder_Node::class, 'window_expiries' ) )->invoke( null, $r, $through, $through, Bucket::id( (int) Core::$now ) ) );
		$this->assertContains( 'set', \array_column( $batch[0][3], 0 ), 'the batch recomputes the max it removes' );
		$sb->flush();
		// A newer request a replayed set would overwrite.
		$this->fold_url( $sb, 'rid-tui-29', self::KEA_URL, 97.5, (int) Core::$now, [ 'peak_mb' => 18.25 ] );
		$sb->flush();
		$expired = $this->raw_url_window();

		self::rows_into( Core::node( self::FACTS_TABLE ), $batch );

		$this->assertSame( $expired, $this->raw_url_window() );
		$this->assertSame( $this->url_window_from_requests(), $this->url_window() );
	}

	public function test_expiring_a_urls_last_request_hides_its_row(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-20', 'https://kea.example/gone', 150.75, $oldest, [ 'peak_mb' => 22.5 ] );
		$this->fold_url( $sb, 'rid-tui-21', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$sb->flush();

		Core::$now += Bucket::SECONDS;
		$sb->flush();

		$this->assertSame( $this->url_window_from_requests(), $this->url_window() );
		$this->assertArrayNotHasKey( '/gone 0', $this->url_window() );
		$gone = $this->raw_url_window()['/gone 0'];
		$this->assertSame( [ 0, 0, 0, 0.0, 0.0, null, null, null ], [ $gone[0], $gone[1], $gone[2], $gone[7], $gone[8], $gone[9], $gone[10], $gone[11] ], 'its sums are spent and its extremes recompute over nothing' );
		$this->assertLessThanOrEqual( $this->url_watermark(), $gone[13], 'its last bucket is at or below the watermark' );
	}

	public function test_an_untimed_url_reads_null_extremes(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold_url( $sb, 'rid-tui-22', self::KEA_URL, 913.5, self::NOW, [ 'status_code' => 504, 'error_status' => 'T', 'peak_mb' => 12.0 ] );
		$this->fold_url( $sb, 'rid-tui-23', self::KEA_URL, 0.0, self::NOW, [ 'peak_mb' => 9.5 ] );
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertSame( [ 2, 0, 1, 0, 0, 1, 1, 0.0, 21.5, null, null, 12.0 ], \array_slice( \reset( $window ), 0, 12 ) );
	}

	/** With its watermark dropped, a restart rebuilds the URL window exactly. */
	public function test_a_rebuild_reproduces_the_url_window(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-24', self::KEA_URL, 913.5, $oldest, [ 'status_code' => 404, 'peak_mb' => 88.75 ] );
		$this->fold_url( $sb, 'rid-tui-25', self::KEA_URL, 413.25, self::NOW, [ 'peak_mb' => 41.5 ] );
		$this->fold_url( $sb, 'rid-tui-26', 'https://kea.example/weka', 97.5, self::NOW, [ 'is_worker' => true, 'peak_mb' => 18.25 ] );
		$sb->flush();
		$kept = $this->url_window();
		$this->assertCount( 2, $kept );

		$pdo = new \PDO( 'sqlite:' . $this->facts_file( 0 ) );
		$pdo->exec( 'UPDATE urls_window SET count = count + 7, sum_ms = sum_ms + 999.0, min_ms = 1.5, max_ms = 9999.0' );
		$pdo->exec( 'DELETE FROM window_expiry' );
		$pdo = null;
		$this->restarted( $sb )->flush();

		$this->assertSame( $kept, $this->url_window() );
		$this->assertSame( $this->url_window_from_requests(), $this->url_window() );
	}

	/** An expiry's per-URL sums seek the bucket-led covering index. */
	public function test_the_url_expiry_read_seeks_requests_cover(): void {
		$this->window_world();
		$this->seed_requests( [ [ 'url' => self::KEA_URL, 'server' => 'kea.example', 'ms' => 413.25 ] ] );
		$sql  = Core::as_string( ( new \ReflectionMethod( Stats_Builder_Node::class, 'url_expiry_sql' ) )->invoke( null ) );
		$plan = Row_Table_Node::read( Flame_Facts_Row_Schema::class, fn ( Row_Reader $r ): string => self::plan_text( $r, \sprintf( $sql, 'p0' ), [ 1, 2 ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH p0\.requests USING COVERING INDEX requests_cover \(bucket>\? AND bucket<\?\)/', $plan );
		$this->assertMatchesRegularExpression( '/SEARCH w USING INDEX sqlite_autoindex_urls_window_1 \(url_id=\? AND worker=\?\)/', $plan );
	}

	/** A recompute reads the moved URLs' rows in the window through `requests_url`. */
	public function test_the_url_recompute_read_seeks_requests_url(): void {
		$this->window_world();
		$this->seed_requests( [ [ 'url' => self::KEA_URL, 'server' => 'kea.example', 'ms' => 413.25 ] ] );
		$sql  = Core::as_string( ( new \ReflectionMethod( Stats_Builder_Node::class, 'url_window_sql' ) )->invoke( null, 2 ) );
		$plan = Row_Table_Node::read( Flame_Facts_Row_Schema::class, fn ( Row_Reader $r ): string => self::plan_text( $r, \sprintf( $sql, 'p0' ), [ 1, 2, 3, \PHP_INT_MAX ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH p0\.requests USING COVERING INDEX requests_url \(url_id=\? AND bucket>\? AND bucket<\?\)/', $plan );
	}

	/** A rebuild reads every URL's rows in the window through `requests_cover`. */
	public function test_the_url_rebuild_read_seeks_requests_cover(): void {
		$this->window_world();
		$this->seed_requests( [ [ 'url' => self::KEA_URL, 'server' => 'kea.example', 'ms' => 413.25 ] ] );
		$sql  = Core::as_string( ( new \ReflectionMethod( Stats_Builder_Node::class, 'url_window_sql' ) )->invoke( null, 0 ) );
		$plan = Row_Table_Node::read( Flame_Facts_Row_Schema::class, fn ( Row_Reader $r ): string => self::plan_text( $r, \sprintf( $sql, 'p0' ), [ 1, \PHP_INT_MAX ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH p0\.requests USING COVERING INDEX requests_cover \(bucket>\? AND bucket<\?\)/', $plan );
	}

	/**
	 * A rebuild spends every row it does not set, so a URL last seen at or
	 * before the new watermark, which retention still keeps, adds its next
	 * request onto nothing.
	 */
	public function test_a_rebuild_spends_the_rows_requests_no_longer_name(): void {
		$this->use_min_lifetime( 3600 );
		$this->window_world();
		$sb = $this->window_builder();
		$this->fold_url( $sb, 'rid-tui-30', self::KEA_URL, 220.5, self::NOW, [ 'status_code' => 404, 'peak_mb' => 30.25 ] );
		$sb->flush();

		Core::$now += ( Bucket::window() + 1 ) * Bucket::SECONDS;
		$sb->flush();
		$this->sweep_at_the_tick();
		$this->assertSame( [], $this->url_window() );
		$hidden = $this->raw_url_window();
		$this->assertSame( [ 0, 0, 0, 0, 0, 0, 0, 0.0, 0.0, null, null, null ], \array_slice( \reset( $hidden ), 0, 12 ), 'the rebuild spent the row it hides' );

		$this->fold_url( $sb, 'rid-tui-31', self::KEA_URL, 150.75, (int) Core::$now, [ 'peak_mb' => 12.5 ] );
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertSame( [ 1, 1, 1, 0, 0, 0, 0, 150.75, 12.5, 150.75, 150.75, 12.5 ], \array_slice( \reset( $window ), 0, 12 ) );
	}

	/**
	 * A request filed in a bucket the clock then steps back from stays in its
	 * URL's row when an expiry recomputes that row's extreme behind it.
	 */
	public function test_a_backward_step_keeps_a_future_request_in_its_url_row(): void {
		$this->window_world();
		$sb     = $this->window_builder();
		$oldest = self::NOW - ( Bucket::window() - 1 ) * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-32', self::KEA_URL, 913.5, $oldest, [ 'peak_mb' => 88.75 ] );
		$this->fold_url( $sb, 'rid-tui-33', self::KEA_URL, 97.5, self::NOW, [ 'peak_mb' => 18.25 ] );
		$sb->flush();

		Core::$now += 2 * Bucket::SECONDS;
		$this->fold_url( $sb, 'rid-tui-34', self::KEA_URL, 600.5, (int) Core::$now, [ 'peak_mb' => 50.25 ] );
		Core::$now -= Bucket::SECONDS;
		$sb->flush();

		$window = $this->url_window();
		$this->assertSame( $this->url_window_from_requests(), $window );
		$this->assertSame( [ 2, 2, 2, 0, 0, 0, 0, 698.0, 68.5, 97.5, 600.5, 50.25 ], \array_slice( \reset( $window ), 0, 12 ) );
	}

	/**
	 * A rebuild behind a bucket the clock stepped back from keeps that
	 * bucket's timings and requests in both windows.
	 */
	public function test_a_rebuild_behind_a_future_bucket_keeps_it(): void {
		$this->window_world();
		$sb = $this->window_builder();
		$sb->flush();
		Core::$now += 2 * Bucket::SECONDS;
		$this->fold( $sb, 'rid-kaka-27', 'hook:kaka_init', 6.75, (int) Core::$now );
		Core::$now -= 2 * Bucket::SECONDS;
		$sb->flush();
		( new \PDO( 'sqlite:' . $this->facts_file( 0 ) ) )->exec( 'DELETE FROM window_expiry' );

		$this->restarted( $sb )->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 6.75, 2, 1 ] ], $this->window() );
		$this->assertSame( $this->url_window_from_requests(), $this->url_window() );
		$this->assertCount( 1, $this->url_window() );
	}

	/**
	 * The category ranking over the span ending at the tick's bucket, as
	 * the Overview asks it, dated by the tick.
	 *
	 * @return array<string,mixed>
	 */
	private function span_ranking(): array {
		$now = (int) Core::$now;
		return Stats_Query::run( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'worker' => [ 0 ], 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => Bucket::last( Bucket::window(), $now ), 'now' => $now ] );
	}

	/**
	 * A record replayed from older than retention files rows in a bucket no
	 * selection over the rows can name, and the next sweep removes them.
	 */
	public function test_a_record_replayed_from_past_retention_is_unreachable_and_swept(): void {
		$this->use_min_lifetime( 7200 );
		$this->window_world();
		$sb = $this->window_builder();
		$old = self::NOW - 40 * Bucket::SECONDS;
		$this->fold( $sb, 'rid-kaka-24', 'hook:kaka_init', 12.5, $old );
		$sb->flush();
		$this->assertSame( [ [ 1 ] ], $this->request_count(), 'the replay re-created its row' );

		$reach = Stats_Query::retention( 'requests' );
		$this->assertSame( 36, $reach );
		$this->assertSame( [ [ 0 ] ], Stats_Query::run( [ 'source' => 'requests', 'group' => [], 'filter' => [], 'measure' => [ 'count' ], 'buckets' => Bucket::last( $reach, self::NOW ) ] )['rows'] );
		try {
			Bucket::selection( Bucket::key( Bucket::id( $old ) ) . '..' . Bucket::key( Bucket::id( self::NOW ) + 1 ), self::NOW, $reach );
			$this->fail( 'a selection reached a bucket past retention' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'bucket selection holds more than 36 buckets', $e->getMessage() );
		}

		$this->sweep_at_the_tick();

		$this->assertSame( [ [ 0 ] ], $this->request_count() );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM timings_site' ) );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests_hourly' ) );
	}

	/**
	 * A `min_lifetime` short of one bucket fails the stats builder's
	 * topology at load, naming the key.
	 *
	 * @param int $seconds The refused lifetime.
	 */
	#[DataProvider( 'spans_short_of_a_bucket' )]
	public function test_a_span_short_of_a_bucket_fails_the_builder_at_boot( int $seconds ): void {
		$this->window_world();
		$this->use_min_lifetime( $seconds );
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "min_lifetime {$seconds} is below the 300-second floor of one stats bucket" );
		\Newspack_Nodes\Topology_Loader::load( 'stats-builder', 1, $interpreter );
	}

	/** @return array<string,array{0:int}> */
	public static function spans_short_of_a_bucket(): array {
		return [
			'zero'           => [ 0 ],
			'a second short' => [ 299 ],
		];
	}

	/** One retention pass over the builder's Row_Table, dated by the pinned tick. */
	private function sweep_at_the_tick(): void {
		$at          = (float) Core::$now;
		Core::$clock = static fn (): float => $at;
		Core::node( self::FACTS_TABLE )->purge( 1000 );
	}

	/** A window row timings_site holds nothing for, newer than any watermark. */
	private function seed_stale_window_row(): void {
		$this->seed_rows( 0, [ [ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, Bucket::id( self::NOW ) ) ] ] );
	}
}
