<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * FlameBuilder consumes completed-request JSON docs (the output shape of
 * RequestBuilder), not raw firehose lines.
 *
 * A completed request looks like:
 *   {
 *     rid, url, duration_ms, status_code, error_status, peak_mb,
 *     request_method, country_code, http_from, user_agent,
 *     ja4_hash, is_worker, timestamp,
 *     entries: [ { n, ts, k, m, l, duration_ms, peak_mb }, ... ],
 *     profiles: { state: { entries: { label: [time, count] }, count, time, ts } }
 *   }
 *
 * The flame tree is built from `entries` via LIFO matching of `^(.+?) \(start\)$`
 * / `^(.+?) \(complete\)$` patterns on `k`. FlameFactsTest covers the rows a request
 * writes.
 */
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderTest extends TestCase {

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

	protected function tearDown(): void {
		Flame_Builder_Node::$usleep_fn = null;
		parent::tearDown();
	}

	/**
	 * An armed builder whose every message but a ROWS request lands in the
	 * capture it is returned beside.
	 *
	 * @return array{0: Flame_Builder_Node, 1: Capture_Sink_Node}
	 */
	private function capturing_builder(): array {
		$capture = new Capture_Sink_Node();
		return [ $this->builder_sending_to( $capture ), $capture ];
	}

	/**
	 * A builder constructed bare, as no topology arms one, naming the facts
	 * table whose file holds a current window, so a fold rebuilds nothing.
	 */
	private function unarmed_builder(): Flame_Builder_Node {
		$this->current_window_facts();
		$fb = new Flame_Builder_Node();
		$fb->set_facts_target( self::FACTS_TABLE );
		return $fb;
	}

	/**
	 * The builder `flame-builder.tsl` makes writes, in its own graph, to the
	 * Row_Table that file declares, which `Row_Table_Node::read()` then
	 * reads across the fleet's files.
	 */
	public function test_the_shipped_builder_writes_into_its_declared_row_table(): void {
		$this->use_scratch_config( $this->make_temp_dir( 'flame-builder-tables-' ) );
		$this->activate_shipped( 'flame-builder', 1 );
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$interpreter = new \Newspack_Nodes\Command_Interpreter_Node();
		$interpreter->name( \Newspack_Nodes\Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		\Newspack_Nodes\Topology_Loader::load( 'flame-builder', 0, $interpreter );
		$fb = Core::node( 'flame-builder' );
		$this->assertInstanceOf( Flame_Builder_Node::class, $fb );

		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-kereru-41', 'duration_ms' => 41.0 ] ) );
		$fb->flush();

		$this->assertSame(
			[ [ 'rid-kereru-41', 41.0 ] ],
			\Newspack_Nodes\Row_Table_Node::read( \Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema::class, static fn ( \Newspack_Nodes\Row_Reader $r ): array => $r->rows( 'SELECT rid, ms FROM p0.requests', [] ) )
		);
	}

	/**
	 * Seed the durable ruleset option with a single rule. Defaults to a
	 * log-all rule id 'r' at prefix '/'; overrides customize id/pattern/
	 * thresholds so the request's stamped rule_id resolves to it.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function set_rule( array $overrides = [] ): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [
			\array_replace( [ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ], $overrides ),
		];
	}

	private function fill_request( Flame_Builder_Node $fb, array $request ): void {
		$this->fill_request_at( $fb, $request, '' );
	}

	/**
	 * Read the node's introspection payload through the production GET_STATS
	 * request verb — the same path the dashboard and the REPL read.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stats( Flame_Builder_Node $fb ): array {
		$prev    = $fb->sink();
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'test-probe';
		$message[ Message::VALUE ] = 'GET_STATS';
		$fb->fill( $message );

		$fb->sink( $prev );

		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_RESPONSE ) && ( $type & Message::TM_STRUCT ) ) {
				return $captured[ Message::VALUE ]['data'];
			}
		}
		$this->fail( 'GET_STATS reply not captured' );
	}

	public function test_constructor_initializes_empty(): void {
		$fb = $this->unarmed_builder();
		$this->assertSame( 0, $this->get_stats( $fb )['buffered_requests'] );
	}

	public function test_a_builder_with_nothing_pending_is_idle_since_its_last_flush(): void {
		Core::$now = 1790000123.0;
		$fb        = $this->unarmed_builder();
		$this->assertSame( 1790000123.0, $fb->idle_since() );
	}

	/** A buffered record not yet written lives in this process alone. */
	public function test_a_builder_holding_a_buffered_record_is_busy(): void {
		Core::$now = 1790000417.0;
		$fb        = $this->unarmed_builder();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://example.com/kakapo-3391', 'timestamp' => 1790000417 ] ) );
		$this->assertNull( $fb->idle_since() );
	}

	public function test_target_includes_flames_partition_and_auto_tuner(): void {
		// FlameBuilder's flame-write path uses the standard
		// target/sink pair like any other Node connection (set via
		// `connect_node flame-builder flames:partition`). The
		// owned auto-tuner sibling is patron-linked, so the GUI
		// hides it via dump_metadata's filter — no extra edge
		// surfaces from target().
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' );

		$this->assertSame( 'flames:partition', $fb->target() );
	}

	public function test_flame_builder_owns_auto_tuner_sibling(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );

		// Auto-tuner registered under {patron}:auto-tuner with patron link.
		$at = \Newspack_Nodes\Core::node( 'fb:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_flame_builder_remove_node_cascades_auto_tuner(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->assertNotNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_rename_cascades_auto_tuner_and_drops_old_name(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$fb->name( 'fb2' );

		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$at = \Newspack_Nodes\Core::node( 'fb2:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_name_null_throws(): void {
		// A named node is committed until remove_node(); name(null) throws.
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->expectException( \RuntimeException::class );
		$fb->name( null );
	}

	public function test_name_empty_string_throws(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->expectException( \RuntimeException::class );
		$fb->name( '' );
	}

	public function test_remove_node_unregisters_auto_tuner(): void {
		// remove_node() (not name(null)) tears down the owned auto-tuner sibling.
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );

		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb' ) );
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_zero_name_yields_zero_auto_tuner_sibling(): void {
		$fb = $this->unarmed_builder();
		$fb->name( '0' );

		$at = \Newspack_Nodes\Core::node( '0:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
	}

	public function test_check_name_availability_throws_on_auto_tuner_collision(): void {
		$squatter = new \Newspack_Event_Logger_Nodes\Auto_Tuner_Node();
		$squatter->name( 'fb:auto-tuner' );

		$fb = $this->unarmed_builder();
		$this->expectException( \RuntimeException::class );
		$fb->name( 'fb' );
	}

	public function test_non_array_value_skipped(): void {
		$fb                        = $this->unarmed_builder();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = 'not-an-array';
		$fb->fill( $message );
		$this->assertSame( 0, $this->get_stats( $fb )['buffered_requests'] );
	}

	public function test_clean_stop_on_the_flame_forward_still_buffers_the_rows_and_raises_clean(): void {
		// When the flame-doc forward triggers a cooperative stop (the partition wrote the
		// doc, then pump() signaled it), FlameBuilder must still buffer the request's
		// rows — its recoverable state — and re-raise as CLEAN, so the Consumer commits
		// past the line instead of replaying it.
		$this->set_rule();
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' ); // non-empty target so store_flame forwards.
		$fb->sink( new class extends \Newspack_Nodes\Node {
			public function fill( array $message ): void {
				throw new \Newspack_Nodes\Worker_Should_Stop();
			}
		} );

		try {
			$this->fill_request( $fb, $this->completed_request() );
			$this->fail( 'expected a clean stop to propagate' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop_Clean $e ) {
			$this->addToAssertionCount( 1 );
		}

		$fb->sink( new Capture_Sink_Node() );
		$this->assertSame( 1, $this->get_stats( $fb )['buffered_requests'], 'the rows are buffered despite the stop on the flame forward' );
	}

	/**
	 * A record whose flame forward stopped carrying a failure is replayed —
	 * the stop is plain — but its rows were already buffered, written by the
	 * sweep and checkpointed. Its `requests` row names its rid, so the
	 * successor's replay inserts nothing and adds no rollup, while its flame,
	 * which never landed, is forwarded again.
	 */
	public function test_a_replayed_record_is_counted_once_by_its_rid(): void {
		$this->set_rule();
		$first = $this->builder_sending_to(
			new class() extends \Newspack_Nodes\Node {
				public function fill( array $message ): void {
					throw new \Newspack_Nodes\Worker_Should_Stop( 'deadline', 0, new \RuntimeException( 'flames flush failed' ) );
				}
			}
		);
		$first->connect_node( 'flames:partition' );
		$record = $this->completed_request( [ 'rid' => 'rid-heron-1', 'url' => 'https://example.com/heron', 'duration_ms' => 419.0 ] );

		$thrown = null;
		try {
			$this->fill_request_at( $first, $record, '7:30412:988' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
			$thrown = $e;
		}
		$this->assertNotNull( $thrown );
		$this->assertFalse( \Newspack_Nodes\Worker_Should_Stop::is_clean( $thrown ), 'the reader must replay it' );
		$first->shutdown_sweep();
		$this->assertSame( [], $first->save_state(), 'the checkpoint carries nothing' );
		$first->remove_node();

		[ $successor, $flames ] = $this->capturing_builder();
		$successor->connect_node( 'flames:partition' );
		$this->fill_request_at( $successor, $record, '7:30412:988' );
		$this->fill_request_at( $successor, $this->completed_request( [ 'rid' => 'rid-heron-2', 'url' => 'https://example.com/heron', 'duration_ms' => 23.0 ] ), '7:31400:991' );
		$successor->flush();

		$this->assertSame( [ [ 2, 442.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'the replayed record is counted once' );
		$this->assertCount( 2, $flames->captured, 'the replayed flame is forwarded again' );
	}

	/**
	 * An auto-tune emit that throws after the flush wrote its rows must not
	 * leave them buffered: the next flush would send every one again.
	 */
	public function test_an_auto_tune_failure_leaves_no_row_to_write_twice(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 57 ] );
		$fb = $this->builder_sending_to(
			new class() extends \Newspack_Nodes\Node {
				public int $refusals = 1;
				public function fill( array $message ): void {
					if ( $this->refusals-- > 0 ) {
						throw new \RuntimeException( 'auto-tuner refused the rewrite' );
					}
				}
			}
		);
		$noisy = [ 'kestrel hook' => [ 'time' => 3.0, 'count' => 400, 'entries' => [] ] ];
		for ( $i = 0; $i < 3; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 211.0, 'profiles' => $noisy ] ) );
		}

		$thrown = null;
		try {
			$fb->flush();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}
		$fb->flush();

		$this->assertSame( 'auto-tuner refused the rewrite', $thrown?->getMessage() );
		$this->assertSame( [ [ 3, 633.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'each request is written once' );
	}

	/**
	 * A write the record path runs is the node's work, not the record's: a
	 * Row_Table that refuses it raises a plain stop carrying the refusal, so
	 * the reader neither dead-letters the record nor commits past it, and
	 * the successor replays from it.
	 */
	public function test_a_refused_write_at_the_buffer_limit_stops_the_reader_on_its_record(): void {
		$this->set_rule();
		$tmp   = $this->make_temp_dir( 'flame-builder-drain-' );
		$limit = Flame_Builder_Node::BUFFERED_REQUESTS_LIMIT;
		$topic = new \Newspack_Nodes\Topic_Node();
		// One segment holds every record, so the cursor reads as one offset.
		$topic->arguments( [ "{$tmp}/requests.p{partition}", '1', '4194304' ] );
		for ( $i = 1; $i <= $limit + 3; $i++ ) {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = Message::TM_STRUCT;
			$message[ Message::KEY ]   = "r-ibis-{$i}";
			$message[ Message::VALUE ] = $this->completed_request( [ 'rid' => "r-ibis-{$i}", 'url' => "https://example.com/ibis-{$i}", 'duration_ms' => 53.0 ] );
			$topic->fill( $message );
		}
		$topic->flush();

		$fb = $this->armed_flame_builder();
		$this->break_facts();
		$consumer = new \Newspack_Nodes\Consumer_Node();
		$consumer->arguments( [ "{$tmp}/requests.p0", "{$tmp}/offsets/fb/p0", "{$tmp}/deadletter/fb/p0" ] );
		$consumer->name( 'requests:consumer' );
		$consumer->sink( $fb );

		$thrown = null;
		try {
			$this->pump_consumer( $consumer );
		} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'the refused write stops the worker' );
		$this->assertFalse( \Newspack_Nodes\Worker_Should_Stop::is_clean( $thrown ), 'the reader must replay the record' );
		$this->assertStringContainsString( 'requests', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
		$segments = \glob( "{$tmp}/requests.p0/*.log" ) ?: [];
		$this->assertCount( 1, $segments );
		$lines  = \array_slice( \file( $segments[0] ) ?: [], 0, $limit - 1 );
		$offset = \array_sum( \array_map( 'strlen', $lines ) );
		$this->assertSame(
			\Newspack_Nodes\Log_Position::format( (int) \basename( $segments[0], '.log' ), $offset, null ),
			$consumer->cursor_position(),
			'the cursor stands on the record that reached the limit'
		);
	}

	public function test_non_bytestream_message_skipped(): void {
		$fb                        = $this->unarmed_builder();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_INFO;
		$message[ Message::VALUE ] = $this->completed_request();
		$fb->fill( $message );
		$this->assertSame( 0, $this->get_stats( $fb )['buffered_requests'] );
	}

	public function test_flame_tree_built_from_entries_with_lifo_matching(): void {
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'duration_ms' => 50.0,
			'entries'     => [
				[ 'k' => 'wp_head hook (start)', 'l' => '', 'm' => '' ],
				[ 'k' => 'init (start)', 'l' => '' ],
				[ 'k' => 'init (complete)', 'duration_ms' => 5.0 ],
				[ 'k' => 'wp_head hook (complete)', 'duration_ms' => 25.0 ],
			],
		] );

		$this->fill_request( $fb, $req );

		$this->assertCount( 1, $capture->captured, 'flame_data is written to flames_sink' );
		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'request', $flame['name'] );
		// Root has duration assigned to value at fill().
		$this->assertEqualsWithDelta( 50.0, $flame['value'], 1e-9 );
		// Child for wp_head hook.
		$this->assertNotEmpty( $flame['children'] );
		$wp_head = $flame['children'][0];
		$this->assertSame( 'wp_head hook', $wp_head['name'] );
		$this->assertEqualsWithDelta( 25.0, $wp_head['value'], 1e-9 );
		// Grandchild for init.
		$this->assertNotEmpty( $wp_head['children'] );
		$init = $wp_head['children'][0];
		$this->assertSame( 'init', $init['name'] );
		$this->assertEqualsWithDelta( 5.0, $init['value'], 1e-9 );
	}

	public function test_orphaned_complete_event_ignored(): void {
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'entries' => [
				// "complete" with no preceding "start" — must not crash, must not emit a node.
				[ 'k' => 'unmatched (complete)', 'duration_ms' => 1.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'request', $flame['name'] );
		$this->assertEmpty( $flame['children'] );
	}

	public function test_duplicate_sibling_names_numbered_with_suffix_then_stripped(): void {
		// Two `init (start)` siblings under the root. They get \x00N suffixes
		// during merge for unambiguous tracking, then suffixes get stripped
		// before storage / display.
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'entries' => [
				[ 'k' => 'init (start)' ],
				[ 'k' => 'init (complete)', 'duration_ms' => 2.0 ],
				[ 'k' => 'init (start)' ],
				[ 'k' => 'init (complete)', 'duration_ms' => 3.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertCount( 2, $flame['children'], '2 siblings at root' );
		// After strip_name_suffixes: both children have name "init" with no \x00.
		foreach ( $flame['children'] as $c ) {
			$this->assertSame( 'init', $c['name'], 'suffix stripped before store' );
		}
	}

	/**
	 * A clean stop writes the buffered rows.
	 *
	 * An on-demand worker spawns on a backlog, folds it within the second
	 * and idles out, rarely seeing the tick that writes. The substrate calls
	 * `shutdown_sweep()` on every clean stop, so that is where they land.
	 */
	public function test_a_clean_stop_writes_the_buffered_rows(): void {
		$fb = $this->armed_flame_builder();

		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-signup-1132', 'url' => 'https://example.com/signup', 'duration_ms' => 1132.0 ] ) );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'a record inside the flush window stays buffered' );

		$this->assertInstanceOf( \Newspack_Nodes\Shutdown_Sweeper::class, $fb );
		$fb->shutdown_sweep();

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
	 * the fold's intake, before anything of it is folded or forwarded, and
	 * the builder folds the next.
	 *
	 * @param array<string,mixed> $overrides What breaks the record.
	 * @param string              $refusal   What the throw names.
	 */
	#[DataProvider( 'records_refused_at_intake' )]
	public function test_a_record_refused_at_intake_throws_before_anything_is_folded( array $overrides, string $refusal ): void {
		[ $fb, $flames ] = $this->capturing_builder();
		$fb->connect_node( 'flames:partition' );

		try {
			$this->fill_request_at( $fb, $this->completed_request( [ 'timestamp' => self::tick() ] + $overrides ), '7:100:41' );
			$this->fail( 'the record is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( $refusal, $e->getMessage() );
		}
		$this->assertSame( [], $flames->captured, 'nothing is forwarded' );
		$this->assertSame( 0, $this->get_stats( $fb )['buffered_requests'], 'nothing is buffered' );
		$this->assertSame( [], $fb->save_state(), 'the checkpoint carries nothing' );

		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-kea-7731', 'url' => 'https://kea-7713.test/kea-7731', 'duration_ms' => 29.0, 'timestamp' => self::tick() ] ), '7:200:42' );
		$this->assertContains( 'flames:partition', \array_column( $flames->captured, Message::TO ), 'the next record forwards its flame' );
		$fb->flush();
		$this->assertSame( [ [ 'rid-kea-7731' ] ], $this->facts( 'SELECT rid FROM requests' ), 'and is written' );
	}

	/**
	 * The rows a request contributes must not depend on whether
	 * Request_Builder happened to fold it. This is the highest-risk part of the
	 * pressure fold: a leaderboard that shifts under load is worse than one that
	 * stops.
	 */
	public function test_a_folded_request_contributes_the_same_stats_as_an_unfolded_one(): void {
		$now     = self::tick();
		$origin  = (float) $now;
		$entries = [
			[ 'k' => 'process (start)', 'ts' => $origin ],
		];
		// Three `save` spans and a `db` — the breadth-at-depth-3 shape that
		// makes an envelope big enough to fold in the first place.
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

		$plain = $this->stats_for( \array_replace( $base, [ 'entries' => $entries ] ), 'plain-5521.test' );
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
		Core::node( 'fb' )?->remove_node();
		$fb = $this->armed_flame_builder();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => "https://{$host}/fold-5521" ] + $request ) );
		$fb->flush();

		return [
			'request' => $this->facts( "SELECT r.bucket, r.status, r.ms, r.peak_mb, r.worker FROM requests r JOIN urls u ON u.id = r.url_id JOIN servers s ON s.id = u.server_id WHERE s.name = '{$host}'" ),
			'timings' => $this->facts( "SELECT c.name, e.name, t.listener, t.ms, t.calls, t.requests FROM timings t JOIN servers s ON s.id = t.server_id JOIN categories c ON c.id = t.category_id JOIN entries e ON e.id = t.entry_id WHERE s.name = '{$host}' ORDER BY c.name, e.name" ),
			'hourly'  => $this->facts( "SELECT h.count, h.timed, h.sum_ms, h.max_ms, h.errors FROM requests_hourly h JOIN servers s ON s.id = h.server_id WHERE s.name = '{$host}'" ),
		];
	}

	/** A record whose `entries` are not a list still forwards a flame, with no children. */
	public function test_a_record_whose_entries_are_not_a_list_forwards_an_empty_flame(): void {
		[ $fb, $flames ] = $this->capturing_builder();
		$fb->connect_node( 'flames:partition' );

		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-titi-12', 'duration_ms' => 64.0, 'entries' => 'not-a-list' ] ) );

		$this->assertSame( [], $flames->captured[0][ Message::VALUE ]['children'] );
		$this->assertEqualsWithDelta( 64.0, $flames->captured[0][ Message::VALUE ]['value'], 1e-9 );
	}

	public function test_finalize_normalizes_parent_value_to_at_least_children_sum(): void {
		// floating-point asymmetry: parent is slightly less than child sum.
		$node = [
			'name'      => 'parent',
			'sum_value' => 4.0,
			'count'     => 1,
			'children'  => [
				[ 'name' => 'a', 'sum_value' => 3.0, 'children' => [] ],
				[ 'name' => 'b', 'sum_value' => 3.0, 'children' => [] ],
			],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertEqualsWithDelta( 6.0, $node['value'], 1e-6 );
	}

	/** A flush with nothing buffered asks no Row_Table, named or not. */
	public function test_a_flush_with_nothing_buffered_asks_nothing(): void {
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

		$fb->flush();

		$this->assertSame( [], $capture->captured );
	}

	public function test_finalize_strips_internal_fields(): void {
		$node = [
			'name'      => 'root',
			'sum_value' => 5.0,
			'seen_count' => 1,
			'count'     => 1,
			'children'  => [],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertArrayNotHasKey( 'sum_value', $node );
		$this->assertArrayNotHasKey( 'seen_count', $node );
	}

	public function test_flame_builder_constructs_sibling_interpreter(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->assertNotNull( $this->read_private( $fb, 'interpreter' ) );
		$this->assertSame( 'fb:config', $this->read_private( $fb, 'interpreter' )->name() );
	}

	public function test_the_facts_target_verb_round_trips(): void {
		// What a dump owes is REPLAY, not a literal: the argument it emits has
		// to come back through the same verb as the same state.
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->assertSame( "ok\n", $this->read_private( $fb, 'interpreter' )->dispatch( 'set_facts_target', [ 'facts-kea-4471' ] ) );
		$dump = $fb->dump_config();
		$this->assertMatchesRegularExpression( '/command_node fb:config set_facts_target (\S+)/', $dump );

		\preg_match( '/command_node fb:config set_facts_target (\S+)/', $dump, $m );
		$replayed = $this->unarmed_builder();
		$replayed->name( 'fb2' );
		$this->read_private( $replayed, 'interpreter' )->dispatch( 'set_facts_target', [ $m[1] ] );
		$this->assertSame( 'facts-kea-4471', $this->read_private( $replayed, 'facts_target' ), 'the dumped argument replays' );
	}

	/** The Row_Table the verb named is drawn as an edge the builder writes along. */
	public function test_the_facts_target_is_a_display_target(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' );
		$this->read_private( $fb, 'interpreter' )->dispatch( 'set_facts_target', [ 'facts-kea-4472' ] );

		$this->assertContains( 'facts-kea-4472', $fb->display_targets() );
	}

	public function test_flame_builder_node_schema_declares_verbs(): void {
		$schema = Flame_Builder_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$this->assertSame( [ 'set_facts_target' ], \array_column( $schema['commands'], 'name' ) );
	}

	public function test_the_tick_flush_is_dated_from_the_tick(): void {
		// The tick's flush reads the tick, not the wall.
		$this->shift_tick( -\Newspack_Event_Logger_Nodes\Bucket::SECONDS );
		$this->test_the_tick_flushes_what_fill_folded();
		$this->assertSame( [ [ \Newspack_Event_Logger_Nodes\Bucket::id( self::tick() ), 2 ] ], $this->facts( 'SELECT bucket, count(*) FROM requests GROUP BY bucket' ) );
	}

	public function test_the_tick_flushes_what_fill_folded(): void {
		$fb = $this->armed_flame_builder();

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0 ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 13.0 ] ) );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'fill buffers and never writes' );

		$fb->fire_cb();

		$this->assertSame( [ [ 2, 25.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'the tick wrote both' );
	}

	/** Truly idle: nothing buffered and no auto-tune queued, so a tick asks nothing. */
	public function test_a_tick_with_nothing_owed_asks_nothing(): void {
		[ $fb, $capture ] = $this->capturing_builder();
		$recorder         = VerbHarness::ask_recorder();
		$fb->sink( $recorder );

		$fb->fire_cb();

		$this->assertSame( [], $recorder->asked );
		$this->assertSame( [], $capture->captured );
	}

	/**
	 * The periodic flush is the node's own work, not a record's. A Row_Table
	 * that refuses it must leave every record buffered and undead-lettered,
	 * and the stop it raises leaves the tick, where the worker exits loudly.
	 */
	public function test_a_failing_flush_raises_from_the_tick_and_poisons_no_record(): void {
		$fb = $this->armed_flame_builder();
		$this->break_facts();

		$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 211.0 ] ), '4:880:61' );

		$thrown = null;
		try {
			$fb->fire_cb();
		} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
			$thrown = $e;
		}
		$this->assertSame( 'stats write failed', $thrown?->getMessage(), 'the tick raises the refused write' );
		$this->assertSame( 1, $this->get_stats( $fb )['buffered_requests'], 'the record stays buffered' );
	}

	/** A tick that failed leaves the rows for the next one, each written once. */
	public function test_the_tick_after_a_failed_flush_writes_each_row_once(): void {
		$fb = $this->armed_flame_builder();
		foreach ( [ '9:10:40' => 97.0, '9:50:40' => 113.0, '9:90:40' => 131.0 ] as $crumb => $ms ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => $ms ] ), $crumb );
		}
		$this->break_facts();
		try {
			$this->tick_router( 0 );
		} catch ( \Newspack_Nodes\Worker_Should_Stop ) {
			// The first tick's refusal; the next tick retries.
		}
		$this->mend_facts();
		$this->tick_router( Flame_Builder_Node::FLUSH_INTERVAL_SEC );

		$this->assertSame( [ [ 3, 341.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'each request is written once' );
	}

	/**
	 * Decisions a sibling's lock held back outlive the records that made them:
	 * the next tick applies them though it buffered nothing.
	 */
	public function test_a_tick_with_nothing_buffered_applies_the_auto_tune_a_held_lock_left(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$lock       = self::scoped( 'evlog:auto_disable_lock' );
		$mc->add( $lock, 'sibling-4433', 300 );
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'gannet hook' => [ 'time' => 0.1, 'count' => 211, 'entries' => [] ] ],
		] ) );
		$this->tick_router( 0 );
		$this->assertSame( [ 'gannet' ], $fb->get_auto_tune_state()['disable_hooks']['r'] ?? null, 'the lock held it back' );
		$mc->delete( $lock );

		$this->tick_router( Flame_Builder_Node::FLUSH_INTERVAL_SEC );

		$fired = \array_values( \array_filter( $capture->captured, static fn ( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' ) ) );
		$this->assertCount( 1, $fired, 'the next tick applied what was queued' );
		$this->assertSame( [ 'gannet' ], $fired[0][ Message::VALUE ]['items'] );
	}

	/** Round-trip: what `arguments()` was given is what it reads back. */
	public function test_arguments_read_back_what_they_were_given(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );

		$fb->arguments( [ 'petrel-4434', 'skua-4435' ] );

		$this->assertSame( [ 'petrel-4434', 'skua-4435' ], $fb->arguments() );
	}

	/** The flush rides the Router tick at its own cadence. */
	public function test_arguments_arm_the_flush_on_the_tick_router(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );

		$fb->arguments( [] );

		$this->assertSame( 'router', $fb->timer_mode() );
		$this->assertSame( Flame_Builder_Node::FLUSH_INTERVAL_SEC * 1000, $fb->interval_ms );
	}

	/** A noisy category the governing rule logs as a custom event is disabled as one; any other is a hook. */
	public function test_the_rules_custom_events_dispatch_to_disable_custom_events(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50, 'custom_events' => [ 'mything', 'other' ] ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				// "mything" is one of the rule's custom events: disable_custom_events.
				'mything hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
				// "wp_init" is NOT a custom event — routes to disable_hooks.
				'wp_init hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'mything' ], $state['disable_custom_events']['r'] );
		$this->assertSame( [ 'wp_init' ], $state['disable_hooks']['r'] );
	}

	/**
	 * A platform request tunes a rule written for one of the platform's
	 * per-request paths, however the pattern spells it, and a job context a
	 * rule at `/jobs` or below.
	 *
	 * @return array<string,array{0:string,1:string}> Rule pattern, then the request's URL.
	 */
	public static function platform_rules(): array {
		return [
			'the command route'      => [ '/wp-json/newspack-nodes/v1/command?', 'https://example.com/wp-json/newspack-nodes/v1/command' ],
			'cron, by its query'     => [ '/wp-cron.php?doing_wp_cron', 'https://example.com/wp-cron.php?doing_wp_cron=1792407316' ],
			'the command route, cased' => [ '/WP-JSON/Newspack-Nodes/v1/Command?', 'https://example.com/wp-json/newspack-nodes/v1/command' ],
			'a job, under /jobs'       => [ '/jobs', 'https://example.com/jobs/kiwi-7316/42' ],
			'a job, under its handler' => [ '/jobs/kiwi-7316', 'https://example.com/jobs/kiwi-7316/42' ],
			'a spawned worker'         => [ '/wp-json/newspack-nodes/v1/workers/spawn?', 'https://example.com/wp-json/newspack-nodes/v1/workers/spawn' ],
			'a stream'                 => [ '/wp-json/newspack-nodes/v1/messages/stream?', 'https://example.com/wp-json/newspack-nodes/v1/messages/stream' ],
		];
	}

	#[DataProvider( 'platform_rules' )]
	public function test_a_platform_request_tunes_a_rule_over_platform_paths( string $pattern, string $url ): void {
		$this->set_rule( [ 'pattern' => $pattern, 'auto_disable_threshold' => 37, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = $this->unarmed_builder();

		$this->fill_request( $fb, $this->completed_request( [
			'url'       => $url,
			'is_worker' => true,
			'profiles'  => [
				'noisy_7316 hook' => [ 'time' => 0.1, 'count' => 38, 'entries' => [] ],
				'slow_7316 hook'  => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_7316' ], $state['disable_hooks']['r'] );
		$this->assertSame( [ 'slow_7316' ], $state['add_significant_events']['r'] );
	}

	/**
	 * A platform request tunes nothing under the catch-all, whatever its
	 * path: that rule governs every visitor's page too.
	 *
	 * @return array<string,array{0:string,1:string}> Rule pattern, then the request's URL.
	 */
	public static function untuned_rules(): array {
		return [
			'the catch-all'         => [ '/', 'https://example.com/wp-json/newspack-nodes/v1/workers/spawn' ],
			'a job, under the catch-all' => [ '/', 'https://example.com/jobs/kiwi-7316/42' ],
		];
	}

	#[DataProvider( 'untuned_rules' )]
	public function test_a_platform_request_leaves_the_catch_all_alone( string $pattern, string $url ): void {
		$this->set_rule( [ 'pattern' => $pattern, 'auto_disable_threshold' => 37, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = $this->unarmed_builder();

		$this->fill_request( $fb, $this->completed_request( [
			'url'       => $url,
			'is_worker' => true,
			'profiles'  => [
				'noisy_7316 hook' => [ 'time' => 0.1, 'count' => 38, 'entries' => [] ],
				'slow_7316 hook'  => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [], $state['disable_hooks'] );
		$this->assertSame( [], $state['add_significant_events'] );
	}

	/** A category's mean per call belongs to its rule: another rule's slow calls promote nothing here. */
	public function test_the_significance_mean_is_kept_per_rule(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [
			[ 'id' => 'quick', 'pattern' => '/quick', 'action' => 'log', 'auto_protect_time_threshold' => 50.0 ],
			[ 'id' => 'slow', 'pattern' => '/slow', 'action' => 'log' ],
		];
		$fb = $this->unarmed_builder();

		$this->fill_request( $fb, $this->completed_request( [
			'url'      => 'https://example.com/slow',
			'rule_id'  => 'slow',
			'profiles' => [ 'shared_7316 hook' => [ 'time' => 900.0, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$this->fill_request( $fb, $this->completed_request( [
			'url'      => 'https://example.com/quick',
			'rule_id'  => 'quick',
			'profiles' => [ 'shared_7316 hook' => [ 'time' => 1.0, 'count' => 1, 'entries' => [] ] ],
		] ) );

		$this->assertSame( [], $fb->get_auto_tune_state()['add_significant_events'] );
	}

	public function test_rule_significant_events_suppress_redundant_proposals(): void {
		// An event already declared significant on the governing rule is not
		// re-flagged when its avg-time detection would otherwise promote it.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05, 'significant_events' => [ 'wpdb' ] ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				// avg = 0.2 ≥ 0.05 → would be significant, but already known.
				'wpdb' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['add_significant_events'], 'already-significant event not re-flagged' );
	}

	/** A custom event's name is proposed whole; only the ` hook` suffix is a suffix. */
	public function test_a_multi_word_custom_event_is_proposed_whole(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'render page' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'render page' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** Only a one-token slug is a plugin load; a custom event ending in "plugin" belongs to the application. */
	public function test_a_multi_word_custom_event_ending_in_plugin_is_still_promoted(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'sync remote plugin' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'sync remote plugin' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** The candidate is the application's own name, whatever it is called. */
	public function test_a_custom_event_named_total_is_proposed_under_its_own_name(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'total' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'total' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** The noisy path names a custom event whole too, so the applier can find it. */
	public function test_a_noisy_multi_word_custom_event_is_disabled_whole(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50, 'custom_events' => [ 'render page' ] ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'render page' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'render page' ], $state['disable_custom_events']['r'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_hooks'] );
	}

	/**
	 * A checkpoint writes the rows even when every auto-tune emit throws: the
	 * decisions wait for the tick, and the cursor still commits.
	 */
	public function test_a_checkpoint_commits_past_an_auto_tune_emit_that_always_throws(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 57 ] );
		$fb = $this->builder_sending_to(
			new class() extends \Newspack_Nodes\Node {
				public function fill( array $message ): void {
					throw new \RuntimeException( 'auto-tuner refused the rewrite' );
				}
			}
		);
		$noisy = [ 'kestrel hook' => [ 'time' => 3.0, 'count' => 400, 'entries' => [] ] ];
		foreach ( [ '3:100:41', '3:200:42', '3:300:43' ] as $crumb ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 211.0, 'profiles' => $noisy ] ), $crumb );
		}

		$this->assertSame( [], $fb->save_state(), 'the checkpoint carries nothing' );
		$this->assertSame( [ [ 3 ] ], $this->facts( 'SELECT count FROM requests_hourly' ), 'the rows are written' );
		$this->expectExceptionMessage( 'auto-tuner refused the rewrite' );
		$fb->flush();
	}

	/**
	 * A plain stop leaves the cursor ON the record whose flame forward failed.
	 * With no sweep before it, the checkpoint alone must write every record
	 * the cursor passed, so the successor's replay counts each record exactly
	 * once by its rid: none dropped, none twice.
	 */
	public function test_a_plain_stops_checkpoint_replays_neither_dropping_nor_double_counting(): void {
		$this->set_rule();
		$first = $this->builder_sending_to(
			new class() extends \Newspack_Nodes\Node {
				public function fill( array $message ): void {
					if ( ++$this->counter > 1 ) {
						throw new \Newspack_Nodes\Worker_Should_Stop( 'deadline', 0, new \RuntimeException( 'flames flush failed' ) );
					}
				}
			}
		);
		$first->connect_node( 'flames:partition' );
		$stopped = $this->completed_request( [ 'rid' => 'rid-kea-42', 'url' => 'https://example.com/kea-42', 'duration_ms' => 37.0 ] );
		$this->fill_request_at( $first, $this->completed_request( [ 'rid' => 'rid-kea-41', 'url' => 'https://example.com/kea-41', 'duration_ms' => 61.0 ] ), '3:100:41' );
		try {
			$this->fill_request_at( $first, $stopped, '3:200:42' );
			$this->fail( 'expected the plain stop to propagate' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
			$this->assertFalse( \Newspack_Nodes\Worker_Should_Stop::is_clean( $e ), 'the reader replays the record' );
		}
		$this->assertSame( [], $first->save_state(), 'the checkpoint carries nothing' );
		$first->remove_node();

		$this->assertSame( [ [ 2 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'both records are written before the cursor commits' );

		[ $successor, $flames ] = $this->capturing_builder();
		$successor->connect_node( 'flames:partition' );
		$this->fill_request_at( $successor, $stopped, '3:200:42' );
		$this->fill_request_at( $successor, $this->completed_request( [ 'rid' => 'rid-kea-43', 'url' => 'https://example.com/kea-43', 'duration_ms' => 777.0 ] ), '3:300:43' );
		$successor->flush();

		$this->assertSame( [ [ 3, 875.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'three records, each counted once' );
		$this->assertCount( 2, $flames->captured, 'the replayed flame is forwarded again, with the new one' );
	}

	public function test_get_stats_returns_payload(): void {
		// A low per-rule time threshold promotes both seeded hooks so
		// significant_events_count (summed across the per-rule cache) is 2.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

		// Seed some state; two distinct slow hooks → two significant promotions.
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 30.0,
			'profiles'    => [
				'init'      => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ],
				'wp_loaded' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ],
			],
		] ) );

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_REQUEST;
		$message[ Message::FROM ]      = 'caller';
		$message[ Message::ID ]        = 'req-1';
		$message[ Message::KEY ]       = 'k-1';
		$message[ Message::VALUE ]     = 'GET_STATS';
		$fb->fill( $message );

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
		$this->assertSame( 7, $payload['data']['buffered_rows'], 'its server, URL and agent, its two categories, the empty entry, and its request' );
		$this->assertArrayHasKey( 'auto_tune_pending_count', $payload['data'] );
		$this->assertSame( 2, $payload['data']['significant_events_count'] );
	}

	public function test_an_unknown_request_verb_is_refused_on_the_error_plane(): void {
		$fb                        = $this->unarmed_builder();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'caller';
		$message[ Message::ID ]    = 'req-2';
		$message[ Message::VALUE ] = 'NONSENSE_VERB';
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->fill( $message );
		$reply = $capture->captured[0];
		$this->assertSame( Message::TM_ERROR, $reply[ Message::TYPE ] );
		$this->assertSame( "unknown request verb: NONSENSE_VERB\n", $reply[ Message::VALUE ] );
		$this->assertSame( 'caller', $reply[ Message::TO ] );
		$this->assertSame( 'req-2', $reply[ Message::ID ] );
	}

	/** Any message carrying TM_REQUEST is answered once and never folded as data. */
	public function test_a_request_is_answered_and_never_folded_whatever_else_it_flags(): void {
		$fb                        = $this->unarmed_builder();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_RESPONSE;
		$message[ Message::VALUE ] = 'GET_STATS';
		$fb->sink( $capture );
		$fb->fill( $message );
		$this->assertSame( 0, $this->get_stats( $fb )['buffered_requests'], 'response not processed as request' );
		$this->assertCount( 1, $capture->captured );
	}

	public function test_apply_auto_tune_emits_messages_via_sink(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		[ $fb, $capture ] = $this->capturing_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'noisy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		// flush() triggers apply_auto_tune → fire_auto_tune_actions → emit_auto_tune.
		$fb->flush();

		// Find the auto-tune emit (disable_hooks with non-empty items).
		$auto_tune_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) =>
				'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
				&& \is_array( $m[ Message::VALUE ] ?? null )
				&& ! empty( $m[ Message::VALUE ]['items'] ?? [] )
		);
		$this->assertNotEmpty( $auto_tune_msgs );
		$first = \array_values( $auto_tune_msgs )[0];
		$this->assertSame( 'fb:auto-tuner', $first[ Message::TO ] );
		$this->assertSame( [ 'noisy' ], $first[ Message::VALUE ]['items'] );
		$this->assertSame( 'r', $first[ Message::VALUE ]['rule_id'] );
	}

	/**
	 * The auto-tune decisions fire on a clean stop, through the sweep.
	 *
	 * An on-demand worker folds its backlog and idles out without a periodic
	 * flush, and the decisions live only in this process, so a stop that did
	 * not fire them would drop them with the process.
	 */
	public function test_a_clean_stop_fires_the_auto_tune_decisions(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		[ $fb, $capture ] = $this->capturing_builder();
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'chatty hook' => [ 'time' => 0.1, 'count' => 250, 'entries' => [] ],
			],
		] ) );
		$this->assertSame( [], $capture->captured, 'nothing fires before the sweep' );

		$fb->shutdown_sweep();

		$fired = \array_values( \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		) );
		$this->assertCount( 1, $fired );
		$this->assertSame( [ 'chatty' ], $fired[0][ Message::VALUE ]['items'] );
	}

	/**
	 * A stop waits out a sibling partition's lock rather than dropping the
	 * decisions: a periodic flush can retry on the next one, a stop has none.
	 */
	public function test_a_clean_stop_waits_for_a_held_auto_tune_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		// Another partition holds the lock, and only the seam below releases
		// it. The TTL must outlive the test: the double expires at
		// `time() + ttl` at second granularity, so a one-second hold added
		// near a second boundary is already gone when the stop looks, and the
		// lock is taken on the first try without ever polling.
		$lock = self::scoped( 'evlog:auto_disable_lock' );
		$mc->add( $lock, 'other-worker', 300 );
		// The seam IS the wait: the sibling's hold ends on the first poll, so
		// this proves the stop outwaits a lock without spending real time.
		$polls                          = 0;
		Flame_Builder_Node::$usleep_fn = static function () use ( $mc, $lock, &$polls ): void {
			++$polls;
			$mc->delete( $lock );
		};

		$fb->shutdown_sweep();

		$this->assertSame( 1, $polls, 'the stop polled rather than giving up' );

		$fired = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertNotEmpty( $fired, 'the stop outwaited the lock' );
		$this->assertNotContains( self::scoped( 'evlog:auto_disable_lock' ), $mc->keys() );
	}

	/**
	 * The stop's wait is a duration inside one process, so it runs on the
	 * monotonic clock: a lock never released is given up after the wait's
	 * 5 s of monotonic time, 50 polls of 100 ms, whatever the wall reads.
	 */
	public function test_a_stop_gives_up_a_held_lock_after_its_monotonic_wait(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		$mc->add( self::scoped( 'evlog:auto_disable_lock' ), 'other-worker', 300 );
		$mono                          = 8_100_000_000_000;
		Flame_Builder_Node::$hrtime_fn              = static function () use ( &$mono ): int {
			return $mono;
		};
		$polls                         = 0;
		Flame_Builder_Node::$usleep_fn = static function ( int $us ) use ( &$mono, &$polls ): void {
			++$polls;
			$mono += $us * 1000;
		};

		$fb->shutdown_sweep();

		$this->assertSame( 50, $polls, 'the wait ends on the monotonic deadline' );
		$fired = \array_filter( $capture->captured, static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' ) );
		$this->assertSame( [], $fired, 'the held lock kept the decisions back' );
	}

	public function test_apply_auto_tune_takes_and_releases_the_memcache_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		$fb->flush();

		// Lock should have been added and released (no leftover entry under that key).
		$this->assertNotContains( self::scoped( 'evlog:auto_disable_lock' ), $mc->keys() );

		// And the emit fired through to sink.
		$auto_tune_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertNotEmpty( $auto_tune_msgs );
	}

	/**
	 * With no memcached handle, the lock still holds through APCu.
	 */
	public function test_the_auto_tune_lock_holds_on_an_apcu_only_host(): void {
		Core::$memd                                 = null;
		\Newspack_Nodes\Cache_Backend::$apcu_usable = static fn (): bool => true;
		$lock                                       = self::scoped( 'evlog:auto_disable_lock' );
		\apcu_store( $lock, 'held-by-p3', 60 );
		try {
			[ $fb, $capture ] = $this->capturing_builder();
			$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
			$this->fill_request( $fb, $this->completed_request( [
				'profiles' => [ 'spammy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
			] ) );
			$fb->flush();
			$this->assertSame( [ 'spammy' ], $fb->get_auto_tune_state()['disable_hooks']['r'], 'an APCu-held lock defers the emit' );
			\apcu_delete( $lock );
			$fb->flush();
			$this->assertSame( [], $fb->get_auto_tune_state()['disable_hooks'] );
			$this->assertFalse( \apcu_exists( $lock ), 'the flush released its own lock' );
		} finally {
			\apcu_delete( $lock );
			\Newspack_Nodes\Cache_Backend::$apcu_usable = static fn (): bool => false;
		}
	}

	public function test_apply_auto_tune_skipped_when_lock_held(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		// Pre-occupy the lock as if a sibling worker holds it.
		$mc->add( self::scoped( 'evlog:auto_disable_lock' ), 'someone-else', 60 );
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'spammy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		// No disable_hooks emit because the lock is held by someone else.
		$disable_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertEmpty( $disable_msgs );

		// And the pending queue is still loaded — proves we early-returned, not consumed.
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'spammy' ], $state['disable_hooks']['r'] );
	}

	public function test_apply_auto_tune_no_op_when_queues_empty(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		[ $fb, $capture ] = $this->capturing_builder();

		// No auto-tune state set, just a basic flush.
		$fb->flush();

		$this->assertSame( [], $mc->keys(), 'no lock taken' );
		// No auto-tune emits (only the flush has nothing to emit).
		foreach ( $capture->captured as $m ) {
			$this->assertNotContains( $m[ Message::KEY ], [ 'disable_hooks', 'disable_custom_events', 'add_significant_events' ] );
		}
	}

	public function test_a_worker_record_keeps_the_narration_counters(): void {
		// The builder files the record's entries through an allowlist that keeps
		// `m` and drops every other producer field: the counters must be in it.
		$fb  = $this->armed_flame_builder();
		$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( $fb, $now ): void {
				Core::$now = $now;
				$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://example.com/narrated-3301', 'timestamp' => $now ] ) );
				$fb->flush();
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
	private function narrated( Flame_Builder_Node $fb, string $method, array $args ): array {
		$previous    = Core::$clock;
		$now         = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		Core::$clock = static fn (): float => (float) $now;
		try {
			$entries = $this->logged_in(
				$this->make_temp_dir(),
				static fn (): mixed => ( new \ReflectionMethod( $fb, $method ) )->invoke( $fb, ...$args )
			);
		} finally {
			Core::$clock = $previous;
		}
		return self::entries_of( $entries, ...self::NARRATION );
	}

	public function test_an_unlogged_worker_runs_the_work_bare(): void {
		// No record, no span: the work runs, its line is never described.
		$fb    = $this->unarmed_builder();
		$tally = new \ReflectionProperty( $fb, 'tally' );
		$tally->setValue( $fb, [ Flame_Tree::STATS_SWEEP => [ 'ranked' => 4 ] ] );
		$described = false;

		$result = ( new \ReflectionMethod( $fb, 'spanned' ) )->invoke(
			$fb,
			Flame_Tree::STATS_SWEEP,
			static fn (): int => 4471,
			static function () use ( &$described ): array {
				$described = true;
				return [ '', [] ];
			}
		);

		$this->assertSame( 4471, $result );
		$this->assertFalse( $described );
		$this->assertSame( [], $tally->getValue( $fb ) );
	}

	public function test_a_count_that_rounds_to_zero_is_left_out(): void {
		$told = $this->narrated(
			new Flame_Builder_Node(),
			'narrate',
			[ Flame_Tree::STATS_WRITES, static fn (): array => [ 'rows written', [ 'ms' => 0.03, 'requests' => 0.06 ] ] ]
		);

		$this->assertSame( 'rows written · 0.1 requests', $told[0]['m'] ?? null );
	}

	public function test_an_unlogged_worker_builds_no_narration(): void {
		// With no started record the line is never built; its counters reset.
		$fb    = $this->unarmed_builder();
		$tally = new \ReflectionProperty( $fb, 'tally' );
		$tally->setValue( $fb, [ Flame_Tree::STATS_WRITES => [ 'flushes' => 3 ] ] );
		$built = false;

		( new \ReflectionMethod( $fb, 'narrate' ) )->invoke(
			$fb,
			Flame_Tree::STATS_WRITES,
			static function () use ( &$built ): array {
				$built = true;
				return [ '', [] ];
			}
		);

		$this->assertNull( Log_Manager::started_instance() );
		$this->assertFalse( $built );
		$this->assertSame( [], $tally->getValue( $fb ) );
	}

	public function test_get_stats_carries_the_narration_not_yet_told(): void {
		$fb        = $this->armed_flame_builder();
		Core::$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-tieke-1' ] ) );
		$fb->flush();
		Core::$now += 5;
		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-tieke-2' ] ) );
		$fb->flush();

		$this->assertSame( 1, $this->get_stats( $fb )['narration'][ Flame_Tree::STATS_WRITES ]['flushes'] ?? null, 'the flush since the last summary' );
	}

	/** The catalog's `reply_shape` names every key `GET_STATS` answers with. */
	public function test_get_stats_reply_shape_names_every_key_it_carries(): void {
		$fb   = $this->unarmed_builder();
		$verb = \array_column( Flame_Builder_Node::node_schema()['requests'], null, 'name' )['GET_STATS'];
		\preg_match_all( '/\w+/', $verb['reply_shape'], $named );

		$this->assertSame( \array_keys( $this->get_stats( $fb ) ), $named[0] );
	}

	public function test_label_and_message_attached_to_flame_nodes(): void {
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'duration_ms' => 50.0,
			'entries'     => [
				[
					'k' => 'wpdb query (start)',
					'l' => 'SELECT_USERS',
					'm' => 'SELECT * FROM wp_users WHERE id = 1',
				],
				[ 'k' => 'wpdb query (complete)', 'duration_ms' => 5.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertNotEmpty( $flame['children'] );
		$child = $flame['children'][0];
		$this->assertSame( 'wpdb query: SELECT_USERS', $child['name'] );
		$this->assertSame( 'SELECT * FROM wp_users WHERE id = 1', $child['message'] );
	}

	public function test_label_equal_to_message_skips_message_field(): void {
		// If label and message are identical, message shouldn't be added.
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'duration_ms' => 30.0,
			'entries'     => [
				[ 'k' => 'foo (start)', 'l' => 'same', 'm' => 'same' ],
				[ 'k' => 'foo (complete)', 'duration_ms' => 1.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$child = $capture->captured[0][ Message::VALUE ]['children'][0];
		$this->assertArrayNotHasKey( 'message', $child, 'message omitted when equal to label' );
	}

	public function test_a_builder_with_no_flame_target_still_writes_its_rows(): void {
		// With no target, store_flame forwards nothing and the rows still land.
		$fb = $this->armed_flame_builder();

		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-pukeko-22', 'duration_ms' => 22.0 ] ) );
		$fb->flush();

		$this->assertSame( [ [ 'rid-pukeko-22' ] ], $this->facts( 'SELECT rid FROM requests' ) );
	}

	public function test_plugin_suffix_categories_skipped_from_auto_tune(): void {
		// Categories ending with " plugin" are not eligible for auto-tune.
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'foo plugin' => [ 'time' => 0.5, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['disable_hooks'], 'plugin-suffix categories never proposed' );
		$this->assertEmpty( $state['add_significant_events'], 'plugin-suffix never significant' );
	}

	public function test_finalize_handles_missing_value_field(): void {
		// Node without value or sum_value falls through to 0.
		$node = [
			'name'     => 'orphan',
			'children' => [],
		];
		Flame_Tree::finalize_flame_node( $node, 0 );
		$this->assertSame( 0, $node['value'] );
	}

	public function test_finalize_normalizes_with_only_some_children_sums(): void {
		$node = [
			'name'      => 'parent',
			'sum_value' => 2.0,
			'count'     => 1,
			'children'  => [
				[ 'name' => 'a', 'sum_value' => 5.0, 'children' => [] ],
				[ 'name' => 'b', 'children' => [] ], // No sum_value.
			],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		// Children sum = 5 + 0 = 5; parent had 2 → bumped to 5.
		$this->assertEqualsWithDelta( 5.0, $node['value'], 1e-6 );
	}

	public function test_format_index_entry_truncates_long_rid_and_hash(): void {
		// Long rid + hash should be truncated to 32 and 12 bytes respectively.
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [
			'rid'      => \str_repeat( 'a', 50 ),
			'url_hash' => \str_repeat( 'b', 30 ),
		];
		$position = [ 'segment' => 0, 'offset' => 0, 'length' => 0 ];
		$entry    = Flame_Builder_Node::format_index_entry( $message, $position );
		$this->assertNotNull( $entry );
		$parsed   = Flame_Builder_Node::parse_flame_index( $entry );
		$this->assertSame( 32, \strlen( $parsed['rid'] ) );
		$this->assertSame( 12, \strlen( $parsed['url_hash'] ) );
	}

	public function test_format_index_entry_rejects_non_array_value(): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$message[ Message::VALUE ] = 'just-a-string';
		$position              = [ 'segment' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( Flame_Builder_Node::format_index_entry( $message, $position ) );
	}

	public function test_get_stats_auto_tune_count_reflects_queue(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = $this->unarmed_builder();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

		// Drive both noisy + significant events to populate three queues.
		$req = $this->completed_request( [
			'profiles' => [
				'noisy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
				'slow'       => [ 'time' => 1.0, 'count' => 2,   'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		// Pre-fill queues — confirm via state.
		$state = $fb->get_auto_tune_state();
		$this->assertNotEmpty( $state['disable_hooks'] );
		$this->assertNotEmpty( $state['add_significant_events'] );

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_REQUEST;
		$message[ Message::FROM ]      = 'caller';
		$message[ Message::VALUE ]     = 'GET_STATS';
		$fb->fill( $message );

		// Find the reply.
		$reply = null;
		foreach ( $capture->captured as $m ) {
			$t = $m[ Message::TYPE ];
			if ( ( $t & Message::TM_RESPONSE ) && ( $t & Message::TM_STRUCT ) ) {
				$reply = $m;
				break;
			}
		}
		$this->assertNotNull( $reply );
		$this->assertGreaterThan( 0, $reply[ Message::VALUE ]['data']['auto_tune_pending_count'] );
	}

	public function test_double_flush_is_idempotent(): void {
		$fb = $this->armed_flame_builder();

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$fb->flush();
		$fb->flush();

		$this->assertSame( [ [ 1, 100.0 ] ], $this->facts( 'SELECT count, sum_ms FROM requests_hourly' ), 'the second flush writes nothing again' );
	}

	public function test_sink_propagates_to_auto_tuner_sibling(): void {
		// make_node auto-sinks FlameBuilder into _command_interpreter; the
		// overridden sink() setter must propagate that sink to the owned
		// auto-tuner sibling so it routes like any other sibling (Rule 2c).
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );

		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );

		$at = Core::node( 'fb:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $capture, $at->sink(), 'auto-tuner sibling adopts the interpreter sink' );
	}

	public function test_sink_getter_returns_own_sink(): void {
		// The sink() override must still behave as a plain getter when called
		// with no argument (don't accidentally return the sibling's sink).
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );
		$this->assertSame( $capture, $fb->sink() );
	}

	public function test_save_state_with_nothing_buffered_writes_nothing(): void {
		$fb = $this->unarmed_builder();
		$fb->name( 'fb' );
		$this->assertSame( [], $fb->save_state(), 'the checkpoint carries nothing' );
	}

	public function test_disable_uses_the_stamped_rules_threshold(): void {
		// The governing rule's threshold — resolved by the request's stamped
		// rule_id — decides, and the proposal is keyed under that rule id.
		$this->set_rule( [ 'id' => 'loud', 'pattern' => '/loud/', 'auto_disable_threshold' => 100 ] );
		$fb  = $this->unarmed_builder();
		$req = $this->completed_request( [
			'rule_id'  => 'loud',
			'url'      => 'https://example.com/loud/x',
			'profiles' => [ 'noisy_hook hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_hook' ], $state['disable_hooks']['loud'] );
	}

	/** A stamp naming no rule, and none at all. */
	public static function unresolved_stamps(): array {
		return [
			'no stamp'       => [ '' ],
			'a deleted rule' => [ 'ghost-9271' ],
		];
	}

	/** With no stamped rule the URL decides, and rules match the path, not the absolute URL. */
	#[DataProvider( 'unresolved_stamps' )]
	public function test_an_unstamped_request_matches_its_rule_by_path( string $stamp ): void {
		$this->set_rule( [ 'id' => 'zephyr', 'pattern' => '/zephyr-9271/', 'auto_disable_threshold' => 100 ] );
		$fb  = $this->unarmed_builder();
		$req = $this->completed_request( [
			'rule_id'  => $stamp,
			'url'      => 'https://kea-7713.test/zephyr-9271/x',
			'profiles' => [ 'noisy_hook hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_hook' ], $state['disable_hooks']['zephyr'] ?? null );
	}

	public function test_no_stamped_rule_applies_no_auto_tune(): void {
		// Stale stamped id + a url that matches no rule ⇒ null rule ⇒ no tuning.
		$this->set_rule( [ 'id' => 'quiet', 'pattern' => '/q/' ] );
		$fb  = $this->unarmed_builder();
		$req = $this->completed_request( [
			'rule_id'  => 'ghost',
			'url'      => 'https://example.com/unmatched',
			'profiles' => [ 'h hook' => [ 'time' => 0.1, 'count' => 999, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['disable_hooks'] );
	}

	public function test_noisy_hook_detection_threshold_zero_disables_check(): void {
		// With threshold 0, no hook ever gets proposed.
		$this->set_rule( [ 'auto_disable_threshold' => 0 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				'wpdb' => [ 'time' => 0.4, 'count' => 99999, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['disable_hooks'] );
	}

	public function test_noisy_hook_detection_proposes_when_count_exceeds_threshold(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				// "wpdb" with count > 100 → base name "wpdb" proposed for disable.
				'wpdb hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'wpdb' ], $state['disable_hooks']['r'] );
	}

	public function test_callback_categories_skipped_from_auto_tune(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = $this->unarmed_builder();

		$req = $this->completed_request( [
			'profiles' => [
				// callback frames end with " @N" — not independent events.
				'wpdb @10' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['disable_hooks'], 'callback categories never proposed' );
	}

	/**
	 * A verb's frame and the plugin's own spans are logged whatever the rule
	 * says, so auto-tune neither promotes nor disables them; a transport span,
	 * which `significant_events` gives meaning, still tunes beside them.
	 */
	public function test_command_and_platform_spans_are_skipped_from_auto_tune(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 61, 'auto_protect_time_threshold' => 0.05 ] );
		$fb    = $this->unarmed_builder();
		$heavy = [ 'time' => 16.7, 'count' => 73, 'entries' => [] ];

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'Performance_CI dump_url command' => $heavy,
				Flame_Tree::PREFETCH_ANSWER      => $heavy,
				Flame_Tree::SNAPSHOT_INDEX       => $heavy,
				Flame_Tree::STATS_SWEEP          => $heavy,
				Flame_Tree::SQL_STATE            => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ Flame_Tree::SQL_STATE ], $state['add_significant_events']['r'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_hooks'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_custom_events'] );
	}

	/** No hook list holds a transport, so a noisy one is never disabled. */
	public function test_a_noisy_transport_is_never_disabled(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 61 ] );
		$fb = $this->unarmed_builder();

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				Flame_Tree::SQL_STATE  => [ 'time' => 3.1, 'count' => 452, 'entries' => [] ],
				Flame_Tree::HTTP_STATE => [ 'time' => 2.6, 'count' => 97, 'entries' => [] ],
				'kakapo_render hook'   => [ 'time' => 1.3, 'count' => 88, 'entries' => [] ],
			],
		] ) );

		$this->assertSame( [ 'r' => [ 'kakapo_render' ] ], $fb->get_auto_tune_state()['disable_hooks'] );
	}

	public function test_significant_event_detection_picks_up_slow_avg(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] ); // 50ms threshold
		$fb = $this->unarmed_builder();

		// avg_per_call = sum_time / sum_count = 0.4/2 = 0.2 ≥ 0.05 → significant.
		$req = $this->completed_request( [
			'profiles' => [
				'slow_hook' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'slow_hook' ], $state['add_significant_events']['r'] );
	}

	public function test_format_and_parse_flame_index_round_trip(): void {
		// The formatter receives the unpacked message array; VALUE at index 6.
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::VALUE ]     = [ 'rid' => 'abc', 'url_hash' => 'deadbeef0001' ];
		$position = [ 'segment' => 5, 'offset' => 1024, 'length' => 100 ];
		$entry    = Flame_Builder_Node::format_index_entry( $message, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 68, \strlen( $entry ) );

		$parsed = Flame_Builder_Node::parse_flame_index( $entry );
		$this->assertSame( 'abc', $parsed['rid'] );
		$this->assertSame( 'deadbeef0001', $parsed['url_hash'] );
		$this->assertSame( 5, $parsed['segment'] );
		$this->assertSame( 1024, $parsed['offset'] );
		$this->assertSame( 100, $parsed['length'] );
	}

	public function test_format_index_entry_returns_null_when_rid_missing(): void {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::VALUE ]     = [ 'url_hash' => 'abc' ];
		$position = [ 'segment' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( Flame_Builder_Node::format_index_entry( $message, $position ) );
	}

	public function test_parse_flame_index_returns_null_for_short_lines(): void {
		$this->assertNull( Flame_Builder_Node::parse_flame_index( 'too-short' ) );
	}

	public function test_parse_flame_index_refuses_a_line_one_byte_long(): void {
		$message                   = Message::new_message();
		$message[ Message::VALUE ] = [ 'rid' => 'r9k2', 'url_hash' => 'a1b2c3d4e5f6' ];
		$line = Flame_Builder_Node::format_index_entry( $message, [ 'segment' => 3, 'offset' => 4096, 'length' => 812 ] );

		$this->assertSame( 'r9k2', Flame_Builder_Node::parse_flame_index( $line )['rid'] );
		$this->assertNull( Flame_Builder_Node::parse_flame_index( $line . '5' ) );
	}

	public function test_format_index_entry_handles_deeply_nested_flame(): void {
		// A MAX_STACK_DEPTH (50) deeply-nested flame VALUE: the formatter reads the
		// already-unpacked message array, so there is no json_decode depth to exceed.
		$flame = [ 'name' => 'leaf', 'value' => 1, 'children' => [] ];
		for ( $i = 0; $i < 49; $i++ ) {
			$flame = [ 'name' => "level{$i}", 'value' => 1, 'children' => [ $flame ] ];
		}
		$flame['rid']      = 'deep-rid';
		$flame['url_hash'] = 'deadbeef0001';

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = $flame;

		$position = [ 'segment' => 0, 'offset' => 0, 'length' => 100 ];
		$entry    = Flame_Builder_Node::format_index_entry( $message, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 'deep-rid', Flame_Builder_Node::parse_flame_index( $entry )['rid'] );
	}

	/** A request is filed under its start plus its duration, rounded to the second. */
	public function test_a_request_completes_at_its_start_plus_its_rounded_duration(): void {
		$start = \gmmktime( 13, 39, 59, 10, 4, 2026 );

		$this->assertSame( [ $start + 1, $start, $start + 3 ], [ Flame_Builder_Node::completed_at( $start, 700.0 ), Flame_Builder_Node::completed_at( $start, 499.0 ), Flame_Builder_Node::completed_at( $start, 2500.0 ) ] );
	}
}
