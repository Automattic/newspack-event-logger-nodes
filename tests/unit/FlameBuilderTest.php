<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Quiet;
use Newspack_Event_Logger_Nodes\Stats_Store;
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
 *     request_method, server_name, country_code, http_from, user_agent,
 *     ja4_hash, is_worker, timestamp,
 *     entries: [ { n, ts, k, m, l, duration_ms, peak_mb }, ... ],
 *     profiles: { state: { entries: { label: [time, count] }, count, time, ts } }
 *   }
 *
 * The flame tree is built from `entries` via LIFO matching of `^(.+?) \(start\)$`
 * / `^(.+?) \(complete\)$` patterns on `k`.
 */
// The overflow tests fill two shard families past their caps: 0.5s under coverage.
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderTest extends TestCase {

	/**
	 * Rows of `long_path()` enough to pass the item budget under either
	 * serializer's estimate: about 660 fit under PHP's, 780 under igbinary's.
	 */
	private const ROWS_PAST_BUDGET = 1000;

	/** A path 1,000 bytes long, so a shard reaches its byte cap in rows a test can afford. */
	private static function long_path( string $path ): string {
		return \str_pad( "{$path}/", 1000, 'x' );
	}

	/** A stored value fits the item budget under the serializer its cap estimated for. */
	private static function assert_within_item_budget( array $value ): void {
		$bytes = Stats_Store::SERIALIZER_IGBINARY === \Newspack_Nodes\Durable_Arm::serializer()
			? \strlen( (string) \igbinary_serialize( $value ) )
			: \strlen( \serialize( $value ) );
		self::assertLessThanOrEqual( Stats_Store::ITEM_BUDGET, $bytes );
	}

	/** An hour inside the retention window. */
	private static function live_hour(): string {
		return \gmdate( 'Y-m-d-H', self::tick() - 3600 );
	}

	/** One five-minute bucket of that hour. */
	private static function live_bucket(): string {
		return self::live_hour() . '-05';
	}

	protected function tearDown(): void {
		Flame_Builder_Node::$usleep_fn = null;
		parent::tearDown();
	}

	/**
	 * Build a completed-request payload for FlameBuilder. Defaults are
	 * production-shaped; tests override only the fields they assert on.
	 *
	 * Timestamp defaults to current time so the FlameBuilder's bucket-key
	 * derivation aligns with the test's local bucket-key assertion
	 * (otherwise the bucket gets pruned by the retention cutoff).
	 */
	private function completed_request( array $overrides = [] ): array {
		$base = [
			'rid'            => 'r' . \uniqid(),
			'url'            => '/post/123',
			'rule_id'        => 'r',
			'duration_ms'    => 100.0,
			'status_code'    => 200,
			'error_status'   => '-',
			'peak_mb'        => 32.0,
			'request_method' => 'GET',
			'server_name'    => 'example.com',
			'country_code'   => 'US',
			'http_from'      => '',
			'user_agent'     => 'curl/7.85',
			'ja4_hash'       => '',
			'is_worker'      => false,
			'timestamp'      => self::tick(),
			'entries'        => [],
			'profiles'       => [],
		];
		return \array_replace( $base, $overrides );
	}

	/**
	 * The builder `flame-builder.tsl` makes asks the Tables that file
	 * declares, in its own graph, through the client `configure_stats`
	 * hands its store: a reader's mount of the file reads what it flushed.
	 */
	public function test_the_shipped_builder_flushes_into_its_declared_tables(): void {
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

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 41.0, 'timestamp' => self::tick() ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( self::tick() );
		$hour   = $this->stats_table_value( Stats_Store::TABLE_AGGREGATE, 0, Stats_Store::key_at( Stats_Store::hourly_parts(), Stats_Store::hour_of( $bucket ) ) );
		$hourly = Core::arr( Core::arr( $hour )[ Stats_Store::slot_of( $bucket ) ] ?? null );
		$this->assertSame( 1, $hourly['count'] ?? null );
		$this->assertEqualsWithDelta( 41.0, $hourly['sum_ms'] ?? null, 1e-6 );
	}

	/**
	 * `flame-builder.tsl` resolves `set_is_hub <eln:is_hub>` from the active
	 * set, and a spoke's `false` must bind as a bool like a hub's `true`: a
	 * spoke that refused the line would not load its builder at all.
	 *
	 * @return array<string,array{0:list<string>,1:bool}>
	 */
	public static function hub_and_spoke_sets(): array {
		return [
			'spoke' => [ [ 'flame-builder' ], false ],
			'hub'   => [ [ 'flame-builder', 'aggregator' ], true ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'hub_and_spoke_sets' )]
	public function test_the_shipped_topology_loads_as_a_spoke_and_as_a_hub( array $active, bool $hub ): void {
		$this->use_scratch_config( $this->make_temp_dir( 'flame-builder-hubness-' ) );
		$this->activate_shipped( 'flame-builder', 1 );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = $active;
		\Newspack_Nodes\Config::reset();
		\Newspack_Event_Logger_Nodes\Config::reset();
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$interpreter = new \Newspack_Nodes\Command_Interpreter_Node();
		$interpreter->name( \Newspack_Nodes\Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );

		\Newspack_Nodes\Topology_Loader::load( 'flame-builder', 0, $interpreter );

		$fb = Core::node( 'flame-builder' );
		$this->assertInstanceOf( Flame_Builder_Node::class, $fb );
		$this->assertSame( $hub, $this->read_private( $fb, 'is_hub' ) );
	}

	public function test_a_reply_from_a_stats_table_is_never_folded_as_a_record(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		$reply                   = Message::new_message();
		$reply[ Message::TYPE ]  = Message::TM_STRUCT;
		$reply[ Message::FROM ]  = Stats_Store::TABLE_URL;
		$reply[ Message::KEY ]   = 'url:c0ffee7731ab';
		$reply[ Message::VALUE ] = $this->completed_request( [] );
		$fb->fill( $reply );
		$fb->flush();
		$this->assertSame( [], $this->get_hour_slot( $store, Stats_Store::hourly_parts(), Stats_Store::bucket_key( self::tick() ) ) );
		$this->assertSame( 0, $fb->counter(), 'a Table reply is not a record the builder counts' );
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

	/**
	 * The buckets a just-now write can land in — two, so a fill straddling a
	 * bucket boundary does not flake. The reader shape for every namespace.
	 *
	 * @return list<string>
	 */
	private function recent_buckets(): array {
		$now = self::tick();
		return [ Stats_Store::bucket_key( $now ), Stats_Store::bucket_key( $now - 300 ) ];
	}

	/**
	 * The hours of `recent_buckets()`: what a slotted reader is asked for.
	 *
	 * @return list<string>
	 */
	private function recent_hours(): array {
		return \array_values( \array_unique( \array_map( Stats_Store::hour_of( ... ), $this->recent_buckets() ) ) );
	}

	/** One dimension's recent series, keyed by bucket. */
	private function dim_series( Stats_Store $store, string $dimension, string $server = '' ): array {
		return $store->get_slots( Stats_Store::dim_parts( $dimension, $server ), $this->recent_hours() );
	}

	/** The recent category series, keyed by bucket. */
	private function cat_series( Stats_Store $store, string $server = '' ): array {
		return $store->get_slots( Stats_Store::cat_parts( $server ), $this->recent_hours() );
	}

	/**
	 * Stored request totals for the buckets a just-now request can land in.
	 *
	 * @return array<string,mixed>
	 */
	private function recent_hourly( Stats_Store $store ): array {
		return $store->get_slots( Stats_Store::hourly_parts(), $this->recent_hours() );
	}

	private function fill_request( Flame_Builder_Node $fb, array $request ): void {
		$this->fill_request_at( $fb, $request, '' );
	}

	/** Fill a record as a Consumer delivers it, its crumb in ID. */
	private function fill_request_at( Flame_Builder_Node $fb, array $request, string $crumb ): void {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::ID ]        = $crumb;
		$message[ Message::VALUE ]     = $request;
		$fb->fill( $message );
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

	private function stats_count( Flame_Builder_Node $fb ): int {
		return (int) $this->get_stats( $fb )['stats_count'];
	}

	public function test_constructor_initializes_empty(): void {
		$fb = new Flame_Builder_Node();
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	public function test_a_builder_with_nothing_pending_is_idle_since_its_last_flush(): void {
		Core::$now = 1790000123.0;
		$fb        = new Flame_Builder_Node();
		$this->assertSame( 1790000123.0, $fb->idle_since() );
	}

	/** A folded record not yet flushed lives in this process alone. */
	public function test_a_builder_holding_a_pending_bucket_is_busy(): void {
		Core::$now = 1790000417.0;
		$fb        = new Flame_Builder_Node();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/kakapo-3391', 'timestamp' => 1790000417 ] ) );
		$this->assertNull( $fb->idle_since() );
	}

	public function test_target_includes_flames_partition_and_auto_tuner(): void {
		// FlameBuilder's flame-write path uses the standard
		// target/sink pair like any other Node connection (set via
		// `connect_node flame-builder flames:partition`). The
		// owned auto-tuner sibling is patron-linked, so the GUI
		// hides it via dump_metadata's filter — no extra edge
		// surfaces from target().
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' );

		$this->assertSame( 'flames:partition', $fb->target() );
	}

	public function test_flame_builder_owns_auto_tuner_sibling(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		// Auto-tuner registered under {patron}:auto-tuner with patron link.
		$at = \Newspack_Nodes\Core::node( 'fb:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_flame_builder_remove_node_cascades_auto_tuner(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertNotNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_rename_cascades_auto_tuner_and_drops_old_name(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->name( 'fb2' );

		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$at = \Newspack_Nodes\Core::node( 'fb2:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_name_null_throws(): void {
		// A named node is committed until remove_node(); name(null) throws.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->expectException( \RuntimeException::class );
		$fb->name( null );
	}

	public function test_name_empty_string_throws(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->expectException( \RuntimeException::class );
		$fb->name( '' );
	}

	public function test_remove_node_unregisters_auto_tuner(): void {
		// remove_node() (not name(null)) tears down the owned auto-tuner sibling.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );

		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb' ) );
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_zero_name_yields_zero_auto_tuner_sibling(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( '0' );

		$at = \Newspack_Nodes\Core::node( '0:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
	}

	public function test_check_name_availability_throws_on_auto_tuner_collision(): void {
		$squatter = new \Newspack_Event_Logger_Nodes\Auto_Tuner_Node();
		$squatter->name( 'fb:auto-tuner' );

		$fb = new Flame_Builder_Node();
		$this->expectException( \RuntimeException::class );
		$fb->name( 'fb' );
	}

	public function test_non_array_value_skipped(): void {
		$fb                        = new Flame_Builder_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = 'not-an-array';
		$fb->fill( $message );
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	public function test_clean_stop_on_the_flame_forward_still_accumulates_stats_and_raises_clean(): void {
		// When the flame-doc forward triggers a cooperative stop (the partition wrote the
		// doc, then pump() signaled it), FlameBuilder must still accumulate the request's
		// stats — its recoverable state — and re-raise as CLEAN, so the Consumer commits
		// past the line instead of replaying it and double-counting the stats.
		$this->set_rule();
		// Accumulation lives on the store's table now, so the stats it is about
		// have somewhere to go; with none wired there is nothing to accumulate
		// INTO, and nothing could ever have been persisted from it either.
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 86400 ) );
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

		$this->assertSame( 1, $this->stats_count( $fb ), 'stats accumulated despite the stop on the flame forward' );
	}

	/**
	 * A record whose flame forward stopped carrying a failure is replayed —
	 * the stop is plain — but its stats were already accumulated, flushed by
	 * the sweep and checkpointed. The successor restores that checkpoint, so
	 * it must count the replayed record once, while still re-forwarding its
	 * flame, which never landed.
	 */
	public function test_a_replayed_record_the_restored_state_counted_is_not_counted_again(): void {
		$this->set_rule();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$first = new Flame_Builder_Node();
		$first->name( 'fb' );
		$first->set_stats_store( $store );
		$first->connect_node( 'flames:partition' );
		$first->sink( new class() extends \Newspack_Nodes\Node {
			public function fill( array $message ): void {
				throw new \Newspack_Nodes\Worker_Should_Stop( 'deadline', 0, new \RuntimeException( 'flames flush failed' ) );
			}
		} );
		$record = $this->completed_request( [ 'url' => '/heron', 'duration_ms' => 419.0 ] );

		$thrown = null;
		try {
			$this->fill_request_at( $first, $record, '7:30412:988' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
			$thrown = $e;
		}
		$this->assertNotNull( $thrown );
		$this->assertFalse( \Newspack_Nodes\Worker_Should_Stop::is_clean( $thrown ), 'the reader must replay it' );
		$first->shutdown_sweep();
		$checkpoint = $first->save_state();
		$first->remove_node();

		$successor = new Flame_Builder_Node();
		$successor->name( 'fb' );
		$successor->set_stats_store( $store );
		$successor->connect_node( 'flames:partition' );
		$flames = new Capture_Sink_Node();
		$successor->sink( $flames );
		$successor->restore_state( $checkpoint );
		$this->fill_request_at( $successor, $record, '7:30412:988' );
		$this->fill_request_at( $successor, $this->completed_request( [ 'url' => '/heron', 'duration_ms' => 23.0 ] ), '7:31400:991' );
		$successor->flush();

		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 2, \array_sum( \array_column( $totals, 'count' ) ), 'the replayed record is counted once' );
		$this->assertEqualsWithDelta( 442.0, \array_sum( \array_column( $totals, 'sum_ms' ) ), 1e-6 );
		$this->assertCount( 2, $flames->captured, 'the replayed flame is forwarded again' );
	}

	/**
	 * An auto-tune emit that throws after the flush wrote its buckets must not
	 * leave them pending: the next flush would add every sum a second time.
	 */
	public function test_an_auto_tune_failure_leaves_no_bucket_to_write_twice(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 57 ] );
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->sink( new class() extends \Newspack_Nodes\Node {
			public int $refusals = 1;
			public function fill( array $message ): void {
				if ( $this->refusals-- > 0 ) {
					throw new \RuntimeException( 'auto-tuner refused the rewrite' );
				}
			}
		} );
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
		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 3, \array_sum( \array_column( $totals, 'count' ) ), 'each request is written once' );
		$this->assertEqualsWithDelta( 633.0, \array_sum( \array_column( $totals, 'sum_ms' ) ), 1e-6 );
	}

	public function test_non_bytestream_message_skipped(): void {
		$fb                        = new Flame_Builder_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_INFO;
		$message[ Message::VALUE ] = $this->completed_request();
		$fb->fill( $message );
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	// --- Flame tree construction ------------------------------------------

	public function test_flame_tree_built_from_entries_with_lifo_matching(): void {
		$fb      = new Flame_Builder_Node();
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
		$fb      = new Flame_Builder_Node();
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
		$fb      = new Flame_Builder_Node();
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
	 * A clean stop flushes the pending buckets and hands the checkpoint
	 * nothing to carry.
	 *
	 * The periodic flush runs inside fill(), on a record arriving five seconds
	 * or more after the last one, and an on-demand worker rarely sees such a
	 * record: it spawns on a backlog, folds it within the second, idles out.
	 * Every record it folded rode `pending` into the checkpoint and out to the
	 * next worker, never reaching the store — gazettenet carried six hours of
	 * URL stats that way while its dashboard read zero. The substrate calls
	 * `shutdown_sweep()` on every clean stop, so that is where they land.
	 */
	public function test_a_clean_stop_flushes_the_pending_buckets_to_the_store(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/signup', 'duration_ms' => 1132.0 ] ) );
		$this->assertSame( [], $this->recent_hourly( $store ), 'a record within the flush window stays pending' );

		$this->assertInstanceOf( \Newspack_Nodes\Shutdown_Sweeper::class, $fb );
		$fb->shutdown_sweep();

		$hourly = $this->recent_hourly( $store );
		$this->assertCount( 1, $hourly );
		$this->assertSame( 1, \array_values( $hourly )[0]['count'] );
		$rows = $store->url_row_sources( \array_keys( $hourly ) );
		$this->assertCount( 1, $rows, 'the URL row landed with the hourly total' );
	}

	public function test_save_state_persists_current_flame_stats_without_a_periodic_flush(): void {
		// stats_cache (per-URL flame trees) must be co-committed with the cursor at
		// save_state, exactly like the bucket aggregates — else a clean recycle advances
		// past messages whose flame data was only in RAM (drained to the store only every
		// FLUSH_INTERVAL_SEC), losing up to a flush window of per-URL flame data.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/x', 'duration_ms' => 100.0 ] ) );

		// No periodic flush() — only the checkpoint's save_state().
		$fb->save_state();

		$stats = $store->url_aggregate( Log_Manager::url_hash( '/x' ) );
		$this->assertNotNull( $stats, 'save_state drains the current flame stats to the store' );
		$this->assertSame( 1, $stats['flame_raw']['count'] );
	}

	// --- Per-URL aggregate (sums-not-means) -------------------------------

	public function test_two_partitions_running_flames_merge_as_one_builder_would_hold_them(): void {
		$p0 = [ 'name' => 'aggregate', 'sum_value' => 610.0, 'count' => 4, 'children' => [
			[ 'name' => 'init hook', 'sum_value' => 200.0, 'ts' => 1790004000, 'children' => [] ],
			[ 'name' => 'cron hook', 'sum_value' => 70.0, 'ts' => 1790000300, 'children' => [] ],
		] ];
		$p1 = [ 'name' => 'aggregate', 'sum_value' => 390.0, 'count' => 3, 'children' => [
			[ 'name' => 'init hook', 'sum_value' => 50.0, 'ts' => 1790003100, 'children' => [
				[ 'name' => 'wp_head hook', 'sum_value' => 20.0, 'ts' => 1790003100, 'children' => [] ],
			] ],
		] ];

		$merged = Flame_Builder_Node::merge_url_flames( $p0, $p1 );

		$this->assertSame( 7, $merged['count'] );
		$this->assertEqualsWithDelta( 1000.0, $merged['sum_value'], 1e-6 );
		$init = $merged['children'][0];
		$this->assertSame( 'init hook', $init['name'] );
		$this->assertEqualsWithDelta( 250.0, $init['sum_value'], 1e-6 );
		$this->assertSame( 1790004000, $init['ts'], 'the newer stamp' );
		$this->assertEqualsWithDelta( 20.0, $init['children'][0]['sum_value'], 1e-6 );
		$this->assertSame( [ 'init hook' ], \array_column( $merged['children'], 'name' ), 'a node an hour behind the newest stamp expires' );
	}

	public function test_per_url_aggregate_sums_durations_across_requests(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$req1 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 100.0 ] );
		$req2 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 200.0 ] );
		$this->fill_request( $fb, $req1 );
		$this->fill_request( $fb, $req2 );

		// Force flush.
		$fb->flush();

		$url_hash = Log_Manager::url_hash( '/x' );
		$stats    = $store->url_aggregate( $url_hash );
		$this->assertNotNull( $stats );
		// flame_raw retains sums; flame is finalized for display.
		$this->assertEqualsWithDelta( 300.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 2, $stats['flame_raw']['count'] );
		// Display: 300/2 = 150
		$this->assertEqualsWithDelta( 150.0, $stats['flame']['value'], 1e-6 );
	}

	public function test_cold_read_restores_current_shape_sums_from_the_store(): void {
		// A fresh builder (cold stats_cache) cold-reads the persisted aggregate back
		// through the flame_raw-restore branch and accumulates onto it. A current-shape
		// (sums) value must survive that branch intact — this pins the live read the
		// deleted EMA→sums migrations sat astride (they only fired on pre-fix values,
		// which no longer exist). Distinct 140/260 → 400 / mean 200, count 2.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );

		$seed = new Flame_Builder_Node();
		$seed->set_stats_store( $store );
		$this->fill_request( $seed, $this->completed_request( [ 'url' => '/cold', 'duration_ms' => 140.0 ] ) );
		$seed->flush();

		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cold', 'duration_ms' => 260.0 ] ) );
		$fb->flush();

		$stats = $store->url_aggregate( Log_Manager::url_hash( '/cold' ) );
		$this->assertNotNull( $stats );
		$this->assertEqualsWithDelta( 400.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 2, $stats['flame_raw']['count'] );
		$this->assertEqualsWithDelta( 200.0, $stats['flame']['value'], 1e-6 );
	}

	public function test_url_index_min_ms_zero_for_untimed_only_url(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// A zero-duration request carries no timing (record_timing false): count
		// increments, timed_count stays 0, so min_ms must persist as 0 — never the sentinel.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/w', 'duration_ms' => 0.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
		$url_hash = Log_Manager::url_hash( '/w' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 0, $index[ $url_hash ]['timed_count'] );
		$this->assertSame( 0, $index[ $url_hash ]['min_ms'] );
	}

	public function test_a_flush_ranks_the_bucket_it_wrote_site_wide_and_per_server(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now = self::tick();
		foreach ( [ 1, 2, 3 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'duration_ms' => 40.0, 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://moa.test/kiwi-8842', 'duration_ms' => 900.0, 'server_name' => 'moa.test', 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$wombat = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$kiwi   = Log_Manager::url_hash( 'https://moa.test/kiwi-8842' );

		$by_count = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertCount( 1, $by_count );
		$this->assertSame( [ $wombat, $kiwi ], \array_column( $by_count[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 3, $by_count[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$this->assertArrayNotHasKey( Stats_Store::ROW_PATH, $by_count[0][1][0][ Stats_Store::RANK_ROW ] );

		$slowest = $store->url_rank_window( [], [ $bucket ], 'avg_ms', 'desc', '' );
		$this->assertSame( [ $kiwi, $wombat ], \array_column( $slowest[0][1], Stats_Store::RANK_HASH ) );

		$by_url = $store->url_rank_window( [], [ $bucket ], 'url', 'asc', '' );
		$this->assertSame( '/kiwi-8842', $by_url[0][1][0][ Stats_Store::RANK_PATH ] );

		// A URL belongs to one site, so a server's list holds its own rows only.
		$moa = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', 'moa.test' );
		$this->assertSame( [ $kiwi ], \array_column( $moa[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 1, $moa[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$this->assertSame( [], $store->url_rank_window( [], [ $bucket ], 'count', 'desc', 'tui.test' ) );
	}

	public function test_a_second_flush_ranks_the_whole_bucket_not_its_own_rows(): void {
		// The list is derived from the STORED bucket after the merge landed,
		// so a URL flushed earlier keeps its place against one flushed now.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		// Mid-bucket, so the second flush's 61 seconds stay inside it.
		$now       = \gmmktime( 14, 31, 0, 9, 22, 2026 );
		Core::$now = $now;
		foreach ( [ 1, 2, 3, 4, 5 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'timestamp' => $now ] ) );
		}
		Core::$now = $now;
		$fb->flush();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/kiwi-8842', 'timestamp' => $now ] ) );
		// Past RANK_EVERY_S, so the second flush ranks rather than waiting.
		Core::$now = $now + 61;
		$fb->flush();

		$list = $store->url_rank_window( [], [ Stats_Store::bucket_key( $now ) ], 'count', 'desc', '' )[0][1];
		$this->assertSame(
			[ Log_Manager::url_hash( 'https://kea.test/wombat-7731' ), Log_Manager::url_hash( 'https://kea.test/kiwi-8842' ) ],
			\array_column( $list, Stats_Store::RANK_HASH )
		);
		$this->assertSame( 5, $list[0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
	}

	public function test_a_flush_ranks_worker_rows_in_their_own_family_from_the_whole_stored_bucket(): void {
		// The second flush lands a reader row alone, so the worker row it
		// ranks beside comes back from the store's worker shard.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now       = \gmmktime( 14, 31, 0, 9, 22, 2026 );
		$cron      = 'https://kea.test/jobs/cron-sweep/7?job';
		Core::$now = $now;
		foreach ( [ 1, 2, 3 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $cron, 'duration_ms' => 610.0, 'is_worker' => true, 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		}
		$fb->flush();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/kiwi-8842', 'duration_ms' => 17.0, 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		Core::$now = $now + 61;
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$list   = static fn ( string $server, array $set ): array => \array_column(
			$store->bucket_get_multi( [ [ Stats_Store::url_rank_parts( 'count', 'desc', $server, false, $set ), $bucket ] ] )[0] ?? [],
			Stats_Store::RANK_ROW,
			Stats_Store::RANK_HASH
		);
		$worker = $list( '', [ Stats_Store::WORKER_SHARD_PREFIX ] );
		$this->assertSame( [ Log_Manager::url_hash( $cron ) ], \array_keys( $worker ), 'the site\'s worker list' );
		$this->assertSame( 3, $worker[ Log_Manager::url_hash( $cron ) ][ Stats_Store::ROW_COUNT ] );
		$this->assertSame( \array_keys( $worker ), \array_keys( $list( 'kea.test', [ Stats_Store::WORKER_SHARD_PREFIX ] ) ), 'and the server\'s' );
		$this->assertSame( [ Log_Manager::url_hash( 'https://kea.test/kiwi-8842' ) ], \array_keys( $list( '', [] ) ), 'the reader list holds no worker row' );
		$record = $store->bucket_get_multi( [ [ Stats_Store::url_header_parts( '', false, [ Stats_Store::WORKER_SHARD_PREFIX ] ), $bucket ] ] )[0] ?? [];
		$this->assertSame( 3, $record[ Stats_Store::HDR_COUNT ] ?? null, 'the worker family\'s record' );
	}

	public function test_a_flush_landing_every_reader_shard_reads_no_extra_shard(): void {
		// Every reader shard freshly written this flush leaves the ranker's
		// gap-fill nothing to read back, so its cost must not creep toward a
		// whole-bucket scan — the common case on a busy (hub) site.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var array<int,array<int,array{0:array<int,string>,1:string}>> */
			public array $reads_log = [];
			public string $watch = '';
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				// The rollup reads the same way; count reads of the FLUSHED bucket.
				foreach ( $reads as [ , $bucket ] ) {
					if ( $this->watch === $bucket ) {
						$this->reads_log[] = $reads;
						break;
					}
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb->set_stats_store( $store );
		$now = self::tick();

		$seen = [];
		for ( $i = 0; \count( $seen ) < Stats_Store::URL_SHARDS; $i++ ) {
			$url   = "https://tui.test/moa-{$i}-9001";
			$shard = Stats_Store::url_shard( Log_Manager::url_hash( $url ) );
			if ( isset( $seen[ $shard ] ) ) {
				continue;
			}
			$seen[ $shard ] = true;
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => $now ] ) );
		}
		$this->assertCount( Stats_Store::URL_SHARDS, $seen, 'every reader shard represented before the flush' );

		$store->reads_log = [];
		$store->watch     = Stats_Store::bucket_key( $now );
		$fb->flush();

		$this->assertCount(
			2,
			$store->reads_log,
			'the server index and flush_writes() alone; the rank gap-fill reads nothing extra'
		);
	}

	public function test_a_worker_only_flush_ranks_its_worker_family_from_what_it_landed(): void {
		// The flush landed the one shard the index names, so the ranking
		// holds the whole bucket and the gap-fill has nothing to read back.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var array<int,array<int,array{0:array<int,string>,1:string,2:array<array-key,mixed>}>> */
			public array $set_log = [];
			/** @var array<int,array<int,array{0:array<int,string>,1:string}>> */
			public array $get_log = [];
			public function bucket_set_multi( array $writes ): array {
				$this->set_log[] = $writes;
				return parent::bucket_set_multi( $writes );
			}
			public string $watch = '';
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				// The rollup reads the same way; count reads of the FLUSHED bucket.
				foreach ( $reads as [ , $bucket ] ) {
					if ( $this->watch === $bucket ) {
						$this->get_log[] = $reads;
						break;
					}
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb->set_stats_store( $store );
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/wren-3312?worker_type', 'is_worker' => true, 'timestamp' => $now ] ) );

		$store->set_log = [];
		$store->get_log = [];
		$store->watch   = Stats_Store::bucket_key( $now );
		$fb->flush();

		$written = [];
		foreach ( \array_merge( ...$store->set_log ) as [ $parts, , $value ] ) {
			$written[ \implode( ':', $parts ) ] = $value;
		}
		$site = static fn ( array $set ): array => \array_column( $written[ \implode( ':', Stats_Store::url_rank_parts( 'count', 'desc', '', false, $set ) ) ] ?? [ [ 'unwritten' ] ], Stats_Store::RANK_HASH );
		$this->assertSame( [ Log_Manager::url_hash( '/wren-3312?worker_type' ) ], $site( [ Stats_Store::WORKER_SHARD_PREFIX ] ), 'the worker family ranks it' );
		$this->assertSame( [], $site( [] ), 'the reader family ranks nothing, and says so' );
		$this->assertCount( 2, $store->get_log, 'the server index and flush_writes() alone; the rank gap-fill never runs' );
	}

	public function test_write_url_ranks_gives_a_server_of_overflow_rows_empty_lists(): void {
		// A server holding only its overflow row has nothing rankable, but the
		// index names it, so it gets its lists, empty: a reader merging the
		// site's lists tells a server ranked idle from one never ranked.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var array<int,array<int,array{0:array<int,string>,1:string,2:array<array-key,mixed>}>> */
			public array $set_log = [];
			public function bucket_set_multi( array $writes ): array {
				$this->set_log[] = $writes;
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );

		$servers = [
			'good.test'  => [ 'hash1' => self::positional_url_row( [ 'path' => '/good-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0 ] ) ],
			'bogus.test' => [ Stats_Store::OTHER_KEY => self::positional_url_row( [ 'count' => 9, 'timed_count' => 9, 'sum_ms' => 90.0 ] ) ],
		];

		( new \ReflectionMethod( $fb, 'write_url_ranks' ) )->invoke(
			$fb, $store, [ '2026-09-22-13-05' => self::by_shard( $servers ) ], false, false
		);

		$servers_written = [];
		foreach ( $store->set_log as $writes ) {
			foreach ( $writes as [ $parts, , $entries ] ) {
				if ( Stats_Store::NS_URLRANK_S === $parts[0] ) {
					$servers_written[ $parts[1] ][] = \count( $entries );
				}
			}
		}
		$this->assertSame( 14, \array_sum( $servers_written[ Stats_Store::server_key( 'good.test' ) ] ?? [] ), 'the real server ranks its one row' );
		$this->assertSame( \array_fill( 0, 14, 0 ), $servers_written[ Stats_Store::server_key( 'bogus.test' ) ] ?? null, 'an overflow-only server gets empty lists' );
	}

	public function test_write_url_ranks_chunks_its_writes_past_the_batch_size(): void {
		// 37 servers x 14 lists each = 518 writes,
		// over WRITE_BATCH_KEYS (500), so the round trip chunks like
		// flush_writes() does rather than sending one unbounded batch.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $set_calls = 0;
			public function bucket_set_multi( array $writes ): array {
				++$this->set_calls;
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );

		$servers = [];
		for ( $i = 0; $i < 37; $i++ ) {
			$servers[ "srv{$i}.test" ] = [
				\sprintf( 'a%011x', $i ) => self::positional_url_row( [ 'path' => "/many-servers-{$i}", 'count' => 1, 'timed_count' => 1, 'sum_ms' => 5.0 ] ),
			];
		}

		( new \ReflectionMethod( $fb, 'write_url_ranks' ) )->invoke(
			$fb, $store, [ '2026-09-22-13-05' => self::by_shard( $servers ) ], false, false
		);

		$this->assertGreaterThan( 1, $store->set_calls, 'a large ranking round trip chunks like flush_writes() does' );
	}

	public function test_an_hour_whose_lists_all_land_is_marked_done(): void {
		// The marker lands beside the lists, so the probe reads the hour as
		// ranked rather than folding it again.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hour = '2026-08-27-13';
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'c6c6c6c6c6c6' ), [
			'c6c6c6c6c6c6' => [ 'url' => '/marked-done-3318', 'count' => 6 ],
		] );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, [ $hour => true ] );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->flush_buckets( $fb, [] );

		$this->assertSame( 6, self::ranked_count( $store, $hour, true ), 'the fixture ranks the hour' );
		$this->assertSame(
			[],
			$this->url_rank_done( $store, $hour ),
			'the marker is present and empty: nothing reads a value here'
		);
	}

	/**
	 * One server's DONE marker for an hour, or null while its hour is unranked.
	 *
	 * @return array<string,mixed>|null
	 */
	private function url_rank_done( Stats_Store $store, string $hour, string $server = self::SEED_SERVER ): ?array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( $server ) ), $hour ] ] )[0];
	}

	public function test_a_refused_ranking_is_logged_once_and_never_retried(): void {
		// Over the item limit never fits, so nothing retries it: the log is
		// the signal that N is wrong. Both tiers, past a refresh and past the
		// bucket's close, with no new write. Seeds distinct from every
		// default: 13 requests into the bucket, 17 into the folded hour.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$at        = \gmmktime( 9, 7, 0, 9, 22, 2026 );
		Core::$now = $at;
		$fb        = new Flame_Builder_Node();
		$store     = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var array<string,int> */
			public array $attempts = [];
			public function bucket_set_multi( array $writes ): array {
				[ $parts, $key ] = $writes[0] ?? [ [ '' ], '' ];
				if ( \in_array( $parts[0], [ self::NS_URLRANK_S, self::NS_URLRANK_HOUR_S ], true ) ) {
					$this->attempts[ $key ] = ( $this->attempts[ $key ] ?? 0 ) + 1;
					return \array_fill( 0, \count( $writes ), false );
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );
		$bucket = Stats_Store::bucket_key( $at );
		$hour   = '2026-09-22-07';
		// Folded with no marker: the roll-up's find, ranked from its rows.
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'd4d4d4d4d4d4' ), [
			'd4d4d4d4d4d4' => [ 'url' => '/refused-hour-4471', 'count' => 17 ],
		] );
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ $hour => true ] );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, [ $hour => true ] );
		// Hour 06 names a server, so its fold has a list to refuse.
		$this->set_url_bucket( $store, '2026-09-22-06-10', [
			'e7e7e7e7e7e7' => [ 'url' => '/refused-fold-6610', 'count' => 11, 'last_seen' => $at - 10000 ],
		] );

		$this->flush_buckets( $fb, [
			$bucket => [ 'url_stats' => [ 'b5b5b5b5b5b5' => self::positional_url_row( [ 'count' => 13, 'last_seen' => $at ] ) ] ],
		] );
		$this->assertSame( 1, $store->attempts[ $bucket ] ?? 0, 'the bucket ranked once' );
		$this->assertSame( 1, $store->attempts[ $hour ] ?? 0, 'the hour ranked once' );
		$this->assertStringContainsString( 'URL rank write refused', $err );

		// Full flushes: the first once the builder is idle also folds the
		// next hour, whose ranking the roll-up must memoize like a landed
		// one rather than re-rank.
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
		foreach ( [ Stats_Store::URL_PAGE_REFRESH_S, 400, 400 + Stats_Store::URL_PAGE_REFRESH_S ] as $later ) {
			Core::$now = $at + $later;
			$fb->flush();
		}
		$this->assertNotSame( $bucket, Stats_Store::bucket_key( $at + 400 ), 'the fixture closes the bucket' );
		$this->assertSame( 1, $store->attempts[ $bucket ], 'the bucket is not ranked again' );
		$this->assertSame( 1, $store->attempts[ $hour ], 'nor the hour' );
		$this->assertSame( 1, $store->attempts['2026-09-22-06'] ?? 0, 'the folded hour ranked once' );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ) );
	}

	public function test_the_ranking_cadence_and_the_memo_prune_read_one_instant(): void {
		// The tick moves across a bucket boundary during the store read, as a
		// right_now() caller inside a flush (a lock, a heartbeat) moves it: a
		// second read would move the prune floor an hour on and drop a memo
		// entry the flush's own instant keeps.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $later = 0;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				Core::$now = $this->later;
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$first        = \gmmktime( 10, 59, 59, 9, 22, 2026 );
		$store->later = $first + 2;
		$due          = Stats_Store::bucket_key( $first );
		$fine_at      = static fn ( int $at ): array => Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), $at ) )['fine'];
		$tail         = $fine_at( $first );
		$edge         = (string) \end( $tail );
		$this->assertNotContains( $edge, $fine_at( $store->later ), 'the tick crosses the floor' );
		$fb->set_stats_store( $store );
		$ranked_at = new \ReflectionProperty( $fb, 'ranked_at' );
		$ranked_at->setValue( $fb, [ $edge => $first - 37 ] );
		( new \ReflectionProperty( $fb, 'rank_pending' ) )->setValue( $fb, [ $due => true ] );

		Core::$now = $first;
		$fb->flush();

		$this->assertSame( $first - 37, $ranked_at->getValue( $fb )[ $edge ] ?? null, 'the prune floor dates from the first instant' );
		$this->assertSame( $first, $ranked_at->getValue( $fb )[ $due ] ?? null, 'the cadence stamp dates from the first instant' );
	}

	public function test_a_flush_stamps_its_rankings_and_names_with_the_tick_it_read_first(): void {
		// The tick moves during the chunk's store read, as a right_now()
		// caller inside a flush moves it; every date the flush writes must
		// still be the instant it began at.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $later = 0;
			public ?int $token_tick = null;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				Core::$now = $this->later;
				return parent::bucket_get_multi( $reads, $failed );
			}
			public function add_url_tokens( array $sets, int $now ): array {
				$this->token_tick = $now;
				return parent::add_url_tokens( $sets, $now );
			}
		};
		$first        = 1_600_000_123;
		$store->later = $first + 61;
		$hash         = 'c6c6c6c6c6c6';
		$bucket       = Stats_Store::bucket_key( $first );
		$fb->set_stats_store( $store );

		Core::$now = $first;
		$this->flush_buckets( $fb, [ $bucket => [
			'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 3, 'last_seen' => $first ] ) ],
			'url_names' => [ $hash => '/tick-once-4417' ],
		] ] );

		$this->assertSame( $store->later, (int) Core::$now, 'the store read did tick the clock' );
		$this->assertSame( $first, ( new \ReflectionProperty( $fb, 'ranked_at' ) )->getValue( $fb )[ $bucket ] ?? null, 'the ranking stamp dates from the first instant' );
		$this->assertSame( $first, $store->token_tick, 'the words date from the first instant' );
	}

	public function test_the_shutdown_sweep_ranks_a_bucket_the_cadence_deferred(): void {
		// The pending memo lives in memory alone and dies with the worker, so
		// the last flush ranks what the cadence was still holding back. Seeds
		// distinct from every default: 6 requests ranked, then 5 more.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hash   = 'b8b8b8b8b8b8';
		$at     = \gmmktime( 11, 7, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$rows   = static fn ( int $count ): array => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $count, 'last_seen' => $at ] ) ] ];

		Core::$now = $at - 10;
		$this->flush_buckets( $fb, [ $bucket => $rows( 6 ) ] );
		Core::$now = $at;
		$this->flush_buckets( $fb, [ $bucket => $rows( 5 ) ] );
		$this->assertSame( 6, self::ranked_count( $store, $bucket, false ), 'ten seconds on is inside the cadence' );

		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
		Core::$now = $at + 3;
		$fb->shutdown_sweep();
		$this->assertSame( 11, self::ranked_count( $store, $bucket, false ), 'the sweep ranks it anyway' );
	}

	public function test_a_flush_dates_every_stage_from_one_read_of_the_tick(): void {
		// The tick moves during the roll-up's read, as a right_now() caller
		// inside a flush moves it; the ranking placed after it must still date
		// from the flush's first instant, or the hour plan and the cadence
		// disagree on which hour has closed.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $later = 0;
			public function url_hours_derived( array $hours, ?bool &$failed = null ): array {
				Core::$now = $this->later;
				return parent::url_hours_derived( $hours, $failed );
			}
		};
		$first        = \gmmktime( 10, 3, 0, 9, 22, 2026 );
		$store->later = $first + 61;
		$bucket       = Stats_Store::bucket_key( $first );
		$fb->set_stats_store( $store );
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [
			$bucket => \array_replace(
				( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
				[ 'url_stats' => [ self::SEED_SERVER => [ 'e4e4e4e4e4e4' => self::positional_url_row( [ 'count' => 13, 'last_seen' => $first ] ) ] ] ]
			),
		] );

		Core::$now = $first;
		$fb->flush();

		$this->assertSame( $store->later, (int) Core::$now, 'the read did tick the clock' );
		$this->assertSame( $first, ( new \ReflectionProperty( $fb, 'ranked_at' ) )->getValue( $fb )[ $bucket ] ?? null );
	}

	/** The top `count desc` entry's count in one site-wide list, or null. */
	private static function ranked_count( Stats_Store $store, string $key, bool $hour ): ?int {
		$list = $store->url_rank_window( $hour ? [ $key ] : [], $hour ? [] : [ $key ], 'count', 'desc', '' );
		return $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null;
	}

	public function test_a_bucket_ranks_once_a_minute_rather_than_once_a_flush(): void {
		// The page cache and the ranked reader look once a minute, so ranking
		// every five-second flush spends twelve rankings on one read. Seeds
		// distinct from every default: counts 4, 7 and 9, one hash.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hash   = 'a4a4a4a4a4a4';
		$at     = \gmmktime( 14, 2, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$count  = function ( Stats_Store $store ) use ( $bucket ): ?int {
			$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
			return $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null;
		};
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 4 );
		$this->assertSame( 4, $count( $store ), 'a bucket never ranked ranks at once' );

		$flush( $at + 20, 7 );
		$flush( $at + 40, 9 );
		$this->assertSame( 4, $count( $store ), 'three flushes inside the minute, one ranking' );
		$this->assertSame( 20, $this->url_bucket_rows( $store, $bucket )[ $hash ]['count'], 'the ROWS still merge every flush' );

		$flush( $at + 61, 1 );
		$this->assertSame( 21, $count( $store ), 'past the minute it ranks the whole stored bucket' );
	}

	public function test_a_bucket_that_has_closed_ranks_on_the_next_flush(): void {
		// A closed bucket is what the reader reads for the rest of the window,
		// so the last writes into it must reach its lists rather than waiting
		// out a cadence nothing will come back for.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hash   = 'b5b5b5b5b5b5';
		$at     = \gmmktime( 14, 4, 50, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 6 );
		// Twenty seconds later, and a bucket boundary has passed.
		$flush( $at + 20, 5 );
		$this->assertNotSame( $bucket, Stats_Store::bucket_key( $at + 20 ), 'the fixture crosses the boundary' );

		$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertSame( 11, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
	}

	/**
	 * A closed bucket's lists and header record live in the fine Table's
	 * file, where a reader's own mount reads them with the builder gone.
	 */
	public function test_a_closed_buckets_lists_and_header_persist_through_the_table(): void {
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 86400 ) );
		$hash   = 'c6c6c6c6c6c6';
		$at     = \gmmktime( 14, 4, 50, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		foreach ( [ $at => 7, $at + 20 => 6 ] as $now => $rows ) {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		}
		unset( $fb );

		$list   = $this->stats_table_value( Stats_Store::TABLE_URL_FINE, 0, Stats_Store::key_at( Stats_Store::url_rank_parts( 'count', 'desc', '', false ), $bucket ) );
		$header = $this->stats_table_value( Stats_Store::TABLE_URL_FINE, 0, Stats_Store::key_at( Stats_Store::url_header_parts( '', false ), $bucket ) );
		$this->assertSame( 13, \Newspack_Nodes\Core::arr( \Newspack_Nodes\Core::arr( $list )[0] ?? null )[ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null, 'the site\'s list, ranked at the close' );
		$this->assertSame( 13, \Newspack_Nodes\Core::arr( $header )[ Stats_Store::HDR_COUNT ] ?? null, 'and its header record' );
	}

	public function test_a_closed_bucket_the_cadence_deferred_ranks_on_a_later_flush(): void {
		// A flush inside `RANK_EVERY_S` of the last ranking defers the bucket
		// and drops its collected rows. Nothing writes into that bucket once
		// it closes, and the closed-bucket clause only fires on a write — so
		// the deferred rows reach the lists through the pending memo or not
		// at all. Seeds distinct from every default: counts 4 and 7.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hash   = 'f8f8f8f8f8f8';
		$at     = \gmmktime( 14, 2, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$count  = static function () use ( $store, $bucket ): ?int {
			$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
			return $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null;
		};
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 4 );
		$flush( $at + 30, 7 );
		$this->assertSame( 4, $count(), 'the second flush is inside the minute, so it defers' );

		// The bucket has closed, and this flush writes nothing into it.
		Core::$now = $at + 400;
		$this->assertNotSame( $bucket, Stats_Store::bucket_key( $at + 400 ), 'the fixture closes the bucket' );
		$this->flush_buckets( $fb, [] );

		$this->assertSame( 11, $count(), 'the deferred rows reach the lists once the bucket closes' );
	}

	/**
	 * A second `configure_stats` rebuilds the store over the same three
	 * Tables, because each target verb refuses any other, so the memos
	 * still describe it: a ranking the cadence deferred before the rebuild
	 * still lands once its bucket closes.
	 */
	public function test_a_store_rebuilt_over_the_same_tables_keeps_the_rankings_owed(): void {
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb ) );
		$hash   = 'e6e6e6e6e6e6';
		$at     = \gmmktime( 16, 12, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};
		$flush( $at, 6 );
		$flush( $at + 20, 9 );

		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		Core::$now = $at + 400;
		$this->flush_buckets( $fb, [] );

		$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertSame( 15, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null );
	}

	public function test_two_intents_for_one_key_compose_before_the_read(): void {
		// Two fine buckets of a FOLDED hour land on one `urls_h` key. Composed
		// as the intents are built, one pre-read serves one write and both
		// rows reach it; a second merge built on the one pre-read value would
		// discard the first's rows outright.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var list<string> */
			public array $hour_writes = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $bucket ] ) {
					if ( self::NS_URLS_HOUR === $parts[0] ) {
						$this->hour_writes[] = self::key_at( $parts, $bucket );
					}
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );
		$hour  = '2026-08-27-13';
		$one   = 'c6c6c6c6c6c6';
		$two   = 'c7c7c7c7c7c7';
		$shard = Stats_Store::url_shard( $one );
		$this->assertSame( $shard, Stats_Store::url_shard( $two ), 'the fixture puts both rows in one shard' );
		// A folded hour holds its shard, empty or not.
		$this->seed_url_hour( $store, $hour, $shard, [] );
		$store->hour_writes = [];
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ $hour => true ] );

		$this->flush_buckets( $fb, [
			$hour . '-05' => [ 'url_stats' => [ $one => self::positional_url_row( [ 'count' => 6, 'last_seen' => 1756300000 ] ) ] ],
			$hour . '-40' => [ 'url_stats' => [ $two => self::positional_url_row( [ 'count' => 8, 'last_seen' => 1756302000 ] ) ] ],
		] );

		$rows = self::named_url_rows( $store->url_hour_sources( [ $hour ], $shard )[0][1] );
		$this->assertSame( 6, $rows[ $one ]['count'] );
		$this->assertSame( 8, $rows[ $two ]['count'] );
		$this->assertSame(
			[ Stats_Store::key_at( Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), $shard ), $hour ) ],
			$store->hour_writes,
			'one key, written once'
		);
	}

	public function test_a_bucket_is_ranked_before_the_next_chunk_is_written(): void {
		// Ranking after the whole flush holds every bucket's merged reader
		// shards until it ends, which is the memory the chunking exists to
		// bound — a replay spanning the window would hold 288 of them. Both
		// buckets sit in the current hour, whose buckets rank.
		Core::$now = \gmmktime( 14, 37, 0, 9, 22, 2026 );
		$fb        = new Flame_Builder_Node();
		$store     = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var list<string> */
			public array $log = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $bucket ] ) {
					$this->log[] = $parts[0] . ':' . $bucket;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );
		$now     = self::tick();
		$earlier = Stats_Store::bucket_key( $now - 300 );
		$later   = Stats_Store::bucket_key( $now );
		$hash    = Log_Manager::url_hash( 'https://kea.test/hoiho-5520' );
		// 520 per-URL dimension keys push the LATER bucket's rows past the
		// 500-key write chunk, so the two buckets rank in different chunks.
		$dims = [];
		for ( $i = 0; $i < 520; ++$i ) {
			$dims[ \sprintf( '%012x', $i ) ] = [ 'status' => [ '2xx' => [ 1, 1.0, 1.0, 1 ] ] ];
		}
		$this->flush_buckets( $fb, [
			$earlier => [
				'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 4, 'last_seen' => $now - 300 ] ) ],
				'url_dim'   => $dims,
			],
			$later => [
				'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 6, 'last_seen' => $now ] ) ],
			],
		] );

		$ranked_first = \array_search( Stats_Store::NS_URLRANK_S . ':' . $earlier, $store->log, true );
		$rows_second  = \array_search( Stats_Store::NS_URLS . ':' . $later, $store->log, true );
		$this->assertIsInt( $ranked_first, 'the first bucket ranked' );
		$this->assertIsInt( $rows_second, 'the second bucket wrote its rows' );
		$this->assertLessThan( $rows_second, $ranked_first, 'and it ranked before that write' );
	}

	public function test_a_buckets_url_writes_are_never_split_across_write_chunks(): void {
		// A bucket's reader-family rows and names are ONE ranking group, and
		// a chunk never splits one: split across two, the bucket ranks off
		// the first chunk's shards alone and the rest never reach its lists.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now = self::tick();
		// 480 per-URL dimension keys, then a bucket whose sixteen reader
		// shards are 32 more: 512 keys over the 500-key chunk.
		$dims = [];
		for ( $i = 0; $i < 480; ++$i ) {
			$dims[ \sprintf( '%012x', 0xd00000 + $i ) ] = [ 'status' => [ '2xx' => [ 1, 1.0, 1.0, 1 ] ] ];
		}
		$rows = [];
		for ( $i = 0; $i < Stats_Store::URL_SHARDS; ++$i ) {
			$rows[ \dechex( $i ) . '1a2b3c4d5e6' ] = self::positional_url_row( [ 'count' => 3, 'last_seen' => $now ] );
		}
		$this->assertCount( Stats_Store::URL_SHARDS, Stats_Store::rows_by_shard( $rows ), 'one row per reader shard' );

		$bucket = Stats_Store::bucket_key( $now );
		$this->flush_buckets( $fb, [
			Stats_Store::bucket_key( $now - 300 ) => [ 'url_dim' => $dims ],
			$bucket                               => [ 'url_stats' => $rows ],
		] );

		$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertCount(
			Stats_Store::URL_SHARDS,
			$list[0][1],
			'every shard of the bucket reached its lists'
		);
	}

	public function test_a_write_that_never_ranks_joins_no_ranking_group(): void {
		// A worker shard or the leaderboard collects nothing for the ranker,
		// so its write forms no group — it neither holds a chunk open nor
		// ranks off the back of one. A folded hour's write goes to its fine
		// bucket, grouped with nothing, and to the hour key.
		$fb      = new Flame_Builder_Node();
		$for     = new \ReflectionMethod( $fb, 'hour_tier_intents' );
		$merge   = static fn ( array $value ): array => $value;
		$refused = static function (): void {};
		$collect = static function ( array $merged ): void {};
		$intents = static fn ( ?\Closure $collects, ?string $server = 'c0ffee42' ): array => \array_map(
			static fn ( array $i ): array => [ $i['parts'], $i['bucket'], $i['landed'], $i['group'] ],
			$for->invoke( $fb, '2026-09-21-11-15', [ 'fine' ], [ 'coarse' ], $merge, $refused, $collects, $server )
		);

		$this->assertSame(
			[ [ [ 'fine' ], '2026-09-21-11-15', null, null ] ],
			$intents( null ),
			'a write that never ranks reports to no one'
		);
		$this->assertSame(
			[ [ [ 'fine' ], '2026-09-21-11-15', $collect, '2026-09-21-11-15 c0ffee42' ] ],
			$intents( $collect ),
			'a ranked write collects, under its (bucket, server) group'
		);

		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ '2026-09-21-11' => true ] );
		$folded = $intents( $collect );
		$this->assertSame(
			[ [ 'fine' ], '2026-09-21-11-15', null, null ],
			$folded[0],
			'a write into a folded hour still reaches its fine bucket, ranked by no one'
		);
		$this->assertSame(
			[ [ 'coarse' ], '2026-09-21-11', null ],
			[ $folded[1][0], $folded[1][1], $folded[1][3] ],
			'and the hour key'
		);
		$this->assertNotNull( $folded[1][2], 'where landing unranks the HOUR' );
		( $folded[1][2] )( [] );
		$this->assertSame(
			[ '2026-09-21-11' => [ 'c0ffee42' => true ] ],
			( new \ReflectionProperty( $fb, 'unranked_hours' ) )->getValue( $fb ),
			'for that server alone'
		);
		$this->assertNull( $intents( null, null )[1][2], 'and a folded write naming no server still reports to no one' );
	}

	public function test_the_buckets_of_one_chunk_gap_fill_in_one_read(): void {
		// The ranker reads back only the shards the flush did not land. One
		// read per BUCKET is a round trip per bucket on a replay; the set
		// ranked together asks once.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $gap_fills = 0;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				$url_only = [] !== $reads;
				foreach ( $reads as [ $parts ] ) {
					if ( self::NS_URLS !== $parts[0] ) {
						$url_only = false;
					}
				}
				$this->gap_fills += $url_only ? 1 : 0;
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb->set_stats_store( $store );
		$now     = self::tick();
		$buckets = [];
		foreach ( [ 0, 300, 600 ] as $back ) {
			// moa.test's stored shard is what each bucket gap-fills.
			$this->set_url_shard( $store, Stats_Store::bucket_key( $now - $back ), 'c', [
				'c0c0c0c0c0c0' => self::positional_url_row( [ 'count' => 1, 'last_seen' => $now - $back ] ),
			], 'moa.test' );
			$buckets[ Stats_Store::bucket_key( $now - $back ) ] = [
				'hourly'    => [ 'count' => 1 ],
				'url_stats' => [
					Log_Manager::url_hash( "https://kea.test/pukeko-{$back}" ) => self::positional_url_row(
						[ 'count' => 2, 'last_seen' => $now - $back ]
					),
				],
			];
		}
		$this->flush_buckets( $fb, $buckets );

		$this->assertSame( 1, $store->gap_fills, 'three buckets, one gap-fill read' );
	}

	public function test_the_fine_tier_writes_its_lists_in_one_batch(): void {
		// Only `url_hours_derived()` reads a sentinel, and it reads the HOUR
		// tier alone, so a fine bucket has nothing to hold its count list back
		// for — and a second round trip per bucket per flush is what it would
		// cost to do so anyway.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $rank_batches = 0;
			public function bucket_set_multi( array $writes ): array {
				if ( self::NS_URLRANK_S === ( $writes[0][0][0] ?? '' ) ) {
					++$this->rank_batches;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/tuatara-9077', 'duration_ms' => 77.0, 'timestamp' => $now ] ) );
		$this->flush_buckets( $fb, [
			Stats_Store::bucket_key( $now ) => [
				'url_stats' => [ 'f9f9f9f9f9f9' => self::positional_url_row( [ 'count' => 9, 'last_seen' => $now ] ) ],
			],
		] );

		$this->assertSame( 1, $store->rank_batches, 'one batch, no sentinel held back' );
		$this->assertNotEmpty( $store->url_rank_window( [], [ Stats_Store::bucket_key( $now ) ], 'count', 'desc', '' ) );
	}

	public function test_url_index_worker_request_now_records_timing(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// Workers now keep per-URL timing on their own ?worker_type row.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/w?reconcile', 'duration_ms' => 100.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
		$url_hash = Log_Manager::url_hash( '/w?reconcile' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 1, $index[ $url_hash ]['timed_count'] );
		$this->assertEqualsWithDelta( 100.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_url_index_min_ms_real_when_timed_request_mixed_in(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// One untimed (worker) + one timed request for the same URL. min_ms must
		// reflect the real timed minimum, not the sentinel or 0.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/m', 'duration_ms' => 100.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/m', 'duration_ms' => 42.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
		$url_hash = Log_Manager::url_hash( '/m' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertEqualsWithDelta( 42.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_a_flush_files_each_servers_rows_under_its_own_key(): void {
		// Per-server data has the server in the KEY: two servers' rows never
		// share one value, so neither competes for the other's shard cap, and
		// a row carries only the path its key does not already say.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now = self::tick();
		foreach ( [ 1, 2, 3 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'server_name' => 'kea.test', 'duration_ms' => 40.0, 'timestamp' => $now ] ) );
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'http://moa.test/kiwi-8842', 'server_name' => 'moa.test', 'duration_ms' => 90.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$wombat = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$kiwi   = Log_Manager::url_hash( 'http://moa.test/kiwi-8842' );
		$kea    = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $wombat ), 'kea.test' ) );
		$moa    = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $kiwi ), 'moa.test' ) );

		$this->assertSame( [ $wombat ], \array_keys( $kea ), 'kea.test\'s key holds kea.test\'s rows alone' );
		$this->assertSame( 3, $kea[ $wombat ]['count'] );
		$this->assertSame( '/wombat-7731', $kea[ $wombat ]['path'], 'the https origin is the key\'s' );
		$this->assertSame( [ $kiwi ], \array_keys( $moa ) );
		$this->assertSame( 'http://moa.test/kiwi-8842', $moa[ $kiwi ]['path'], 'an http URL is kept whole' );
		$this->assertSame(
			[ Stats_Store::server_key( 'kea.test' ) => 'kea.test', Stats_Store::server_key( 'moa.test' ) => 'moa.test' ],
			Stats_Store::index_names( $store->server_index( [], [ $bucket ] )[ $bucket ] ?? [] ),
			'the bucket\'s index names both'
		);
	}

	public function test_the_flush_names_each_servers_shards_in_its_index_entry(): void {
		// Every reader enumerates keys from the index, so the index has to say
		// which of the 32 a server wrote: a server's five minutes fill a few.
		$fb    = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 86400, $fb ) );
		$fb->set_stats_store( $store );
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$kea    = Stats_Store::server_key( 'kea.test' );
		$index  = static fn (): ?array => $store->bucket_get_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket ] ] )[0];
		foreach ( [ '3' => false, 'c' => false, 'w5' => true ] as $shard => $worker ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => self::url_in_shard( 'https://kea.test', (string) $shard ), 'server_name' => 'kea.test', 'is_worker' => $worker, 'timestamp' => $now ] ) );
		}
		$fb->flush();
		$this->assertSame(
			[ $kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => ( 1 << 3 ) | ( 1 << 12 ) | ( 1 << 21 ) ] ],
			$index()
		);

		$added = self::url_in_shard( 'https://kea.test', 'a' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $added, 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		$fb->flush();
		$this->assertSame(
			[ $kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => ( 1 << 3 ) | ( 1 << 10 ) | ( 1 << 12 ) | ( 1 << 21 ) ] ],
			$index(),
			'a later flush ORs its shard in'
		);

		$store->written = [];
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $added, 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		$fb->flush();
		$written = \array_map( Stats_Store::namespace_of( ... ), $store->written );
		$this->assertContains( Stats_Store::NS_URLS, $written, 'the flush wrote' );
		$this->assertNotContains( Stats_Store::NS_URLSRV, $written, 'an unchanged union is no write' );
	}

	public function test_the_ranking_gap_fill_asks_only_for_the_reader_shards_the_index_names(): void {
		// Ranking reads back what the flush did not land, and a shard the
		// index does not name holds nothing to read back.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$moa    = self::url_in_shard( 'https://moa.test', 'c' );
		$this->set_url_shard( $store, $bucket, 'c', [
			Log_Manager::url_hash( $moa ) => self::positional_url_row( [ 'count' => 4, 'timed_count' => 4, 'sum_ms' => 88.0, 'path' => '/moa', 'last_seen' => $now ] ),
		], 'moa.test' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => self::url_in_shard( 'https://kea.test', '3' ), 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		$this->forget_stats_asks();

		$fb->flush();

		$kea_key = Stats_Store::server_key( 'kea.test' );
		$moa_key = Stats_Store::server_key( 'moa.test' );
		$keys    = [ "{$kea_key}:3:{$bucket}", "{$moa_key}:c:{$bucket}" ];
		\sort( $keys );
		$this->assertSame( $keys, $this->asked_url_keys(), 'the flush\'s own shard, then moa.test\'s one' );
		$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' )[0][1];
		$this->assertSame( [ Log_Manager::url_hash( $moa ) ], [ $list[0][ Stats_Store::RANK_HASH ] ], 'moa.test\'s rows still rank' );
		$this->assertCount( 2, $list );
	}

	public function test_the_hour_fold_asks_only_for_the_named_shards_and_writes_every_shard(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hour = \gmdate( 'Y-m-d-H', self::tick() - 3 * 3600 );
		$this->set_url_shard( $store, "{$hour}-05", '3', [ '3a3a3a3a3a3a' => self::positional_url_row( [ 'count' => 6, 'path' => '/kea' ] ) ], 'kea.test' );
		$this->set_url_shard( $store, "{$hour}-10", 'w5', [ '5b5b5b5b5b5b' => self::positional_url_row( [ 'count' => 2, 'worker' => true, 'path' => '/moa' ] ) ], 'moa.test' );
		$this->forget_stats_asks();

		$fb->roll_up_hours( $store, [ 'fine' => [], 'hours' => [ $hour ] ], [ $hour ] );

		$kea  = Stats_Store::server_key( 'kea.test' );
		$moa  = Stats_Store::server_key( 'moa.test' );
		$keys = [ "{$kea}:3:{$hour}-05", "{$moa}:w5:{$hour}-10" ];
		\sort( $keys );
		$this->assertSame( $keys, $this->asked_url_keys(), 'one key per named shard per bucket' );
		$this->assertSame(
			[
				$kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => 0xFFFFFFFF ],
				$moa => [ Stats_Store::SRV_NAME => 'moa.test', Stats_Store::SRV_SHARDS => 0xFFFFFFFF ],
			],
			$store->bucket_get_multi( [ [ Stats_Store::url_srv_parts( true ), $hour ] ] )[0],
			'the hour names every shard it wrote'
		);
		$reads = [];
		foreach ( [ $kea, $moa ] as $key ) {
			foreach ( [ ...Stats_Store::url_shards(), ...Stats_Store::url_shards( true ) ] as $shard ) {
				$reads[] = [ Stats_Store::url_hour_parts( $key, $shard ), $hour ];
			}
		}
		$this->assertNotContains( null, $store->bucket_get_multi( $reads ), 'every shard of both families, empty or not' );
		$this->assertSame( 6, self::named_url_rows( $this->get_url_hour( $store, $hour, '3', 'kea.test' ) )['3a3a3a3a3a3a']['count'] );
	}

	public function test_a_server_past_the_index_cap_is_filed_under_other(): void {
		// Host-header spray would otherwise mint a key set per request: once
		// a bucket names MAX_SERVER_VALUES servers, a new one's rows share the
		// `Other` server key, and the index gains that one entry.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$index  = self::index_of( \array_map( static fn ( int $i ): string => "spray{$i}.test", \range( 0, Stats_Store::MAX_SERVER_VALUES - 1 ) ) );
		$store->bucket_set_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket, $index ] ] );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://late-4471.test/kokako', 'server_name' => 'late-4471.test', 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://spray7.test/y', 'server_name' => 'spray7.test', 'timestamp' => $now ] ) );
		$fb->flush();

		$late    = Log_Manager::url_hash( 'https://late-4471.test/kokako' );
		$shard   = Stats_Store::url_shard( $late );
		$stored  = Stats_Store::index_names( $store->server_index( [], [ $bucket ] )[ $bucket ] );
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $stored );
		$this->assertSame( Stats_Store::OTHER_KEY, $stored[ Stats_Store::server_key( Stats_Store::OTHER_KEY ) ] );
		$this->assertArrayNotHasKey( Stats_Store::server_key( 'late-4471.test' ), $stored );
		$this->assertSame( 1, self::named_url_rows( $this->get_url_shard( $store, $bucket, $shard, Stats_Store::OTHER_KEY ) )[ $late ]['count'] );
		$this->assertSame( [ 'kokako' => [ $late ] ], $store->url_token_sets( [ 'kokako' ], [ Stats_Store::OTHER_KEY ], self::tick() ), 'its tokens are filed where its rows are' );
		$this->assertSame( [], $store->url_token_sets( [ 'kokako' ], [ 'late-4471.test' ], self::tick() ) );
		$spray = Log_Manager::url_hash( 'https://spray7.test/y' );
		$this->assertArrayHasKey( $spray, $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $spray ), 'spray7.test' ), 'a named server keeps its key' );
	}

	public function test_a_row_filed_under_other_keeps_its_host(): void {
		// The path was cut against the request's own server; under `Other`
		// no host joins back on, so the row carries the whole URL.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$index  = self::index_of( \array_map( static fn ( int $i ): string => "spray{$i}.test", \range( 0, Stats_Store::MAX_SERVER_VALUES - 1 ) ) );
		$store->bucket_set_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket, $index ] ] );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://late-4471.test/kokako?page=2', 'server_name' => 'late-4471.test', 'timestamp' => $now ] ) );
		$fb->flush();

		$late = Log_Manager::url_hash( 'https://late-4471.test/kokako?page=2' );
		$rows = $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $late ), Stats_Store::OTHER_KEY );
		$this->assertSame( 'https://late-4471.test/kokako?page=2', $rows[ $late ][ Stats_Store::ROW_PATH ] );
	}

	public function test_a_refused_url_index_write_is_reported(): void {
		// memcached refuses an item over 1MB, and this blob is the largest the
		// schema writes — up to 500 rows, each now carrying its per-server
		// split. A discarded return means the whole bucket's URL index is lost
		// for that partition, and every later read-modify-write of it fails the
		// same way, with nothing said.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );

		$fb      = new Flame_Builder_Node();
		$refused = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public function bucket_set_multi( array $writes ): array {
				return \array_fill( 0, \count( $writes ), false );
			}
		};
		$fb->set_stats_store( $refused );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/too-big', 'duration_ms' => 5.0, 'timestamp' => self::tick() ] ) );
		$fb->flush();

		$this->assertStringContainsString( 'URL index write refused', $err );
	}

	public function test_a_refused_blob_or_name_write_is_tallied(): void {
		// Every other refused write is tallied by namespace; these two were
		// the flush's only writes that went untold.
		$fb      = new Flame_Builder_Node();
		$refused = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public function bucket_set_multi( array $writes ): array {
				$landed = parent::bucket_set_multi( $writes );
				foreach ( $writes as $i => [ $parts ] ) {
					$landed[ $i ] = $landed[ $i ] && self::NS_URL !== $parts[0];
				}
				return $landed;
			}
			public function set_url_names( array $servers ): array {
				return \array_map( static fn (): bool => false, parent::set_url_names( $servers ) );
			}
		};
		$fb->set_stats_store( $refused );

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( $fb ): void {
				$this->fill_request( $fb, $this->completed_request( [ 'url' => '/hoiho/3307', 'duration_ms' => 5.0, 'timestamp' => self::tick() ] ) );
				$this->fill_request( $fb, $this->completed_request( [ 'url' => '/hoiho/3308', 'duration_ms' => 7.0, 'timestamp' => self::tick() ] ) );
				$fb->flush();
			}
		);

		$told = \explode( ' · ', (string) ( self::last_entry_of( $entries, Flame_Tree::STATS_WRITES )['m'] ?? '' ) );
		$this->assertContains( '2 refused url', $told );
		$this->assertContains( '2 refused urlmap', $told );
	}

	public function test_the_server_axis_holds_more_names_than_the_generic_cap(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		// The server picker is built from this axis, and the fleet is an
		// operator input — production runs 24 spokes. Capped with the generic
		// MAX_DIM_VALUES (20) four of them roll into a synthetic `Other` and
		// become unselectable, and which four varies by bucket, so even a
		// listed site loses the buckets it fell out of. Unlike `country` or
		// `ua`, this axis is bounded by the fleet, so it gets its own ceiling.
		$now = self::tick();
		for ( $i = 0; $i < 24; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => "/s{$i}",
				'server_name' => \sprintf( 'spoke%02d.example', $i ),
				'duration_ms' => 5.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$servers = $this->get_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), Stats_Store::bucket_key( $now ) );

		$this->assertArrayNotHasKey( Stats_Store::OTHER_KEY, $servers );
		$this->assertCount( 24, $servers );
	}

	public function test_the_byte_cap_counts_the_overflow_rows_it_writes(): void {
		// Both overflow rows go back in after the fold, so the cap has to
		// reserve their bytes too, or the item ships past the budget by them.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [
			Stats_Store::OTHER_KEY        => [ 'count' => 9, 'timed_count' => 9, 'sum_ms' => 18.0, 'worker' => false, 'last_seen' => $now ],
			Stats_Store::OTHER_WORKER_KEY => [ 'count' => 7, 'timed_count' => 7, 'sum_ms' => 14.0, 'worker' => true, 'last_seen' => $now ],
		];
		$cap = self::ROWS_PAST_BUDGET;
		for ( $i = 0; $i < $cap; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => self::long_path( "/cap{$i}" ), 'count' => $cap + 500 - $i, 'timed_count' => $cap + 500 - $i,
				'sum_ms' => 2.0 * ( $cap + 500 - $i ), 'min_ms' => 2.0, 'max_ms' => 2.0,
				'sum_peak_mb' => 0, 'max_peak_mb' => 0, 'count_2xx' => $cap + 500 - $i,
				'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
				'worker' => false, 'last_seen' => $now,
			];
		}
		// Straight into the shard: an overflow key is not hex, so routing it
		// through the bucket writer would file it under shard '0' instead of
		// the shard whose own tail produced it.
		$this->seed_url_shard( $store, $bucket, 'a', $seed );

		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		self::assert_within_item_budget( $this->get_url_shard( $store, $bucket, 'a' ) );
	}

	public function test_the_overflow_row_keeps_worker_traffic_separate(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// Each population caps its OWN tail now, in its own shard family, so
		// the two overflow rows can no longer be produced by one blob — and a
		// worker share can no longer ride into a header that excludes it.
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [];
		$cap    = self::ROWS_PAST_BUDGET;
		// Alternating, so BOTH families overflow their own cap.
		for ( $i = 0; $i < 2 * $cap; $i++ ) {
			$base = 2 * $cap + 1000 - $i;
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => self::long_path( "/u{$i}" ), 'count' => $base, 'timed_count' => $base,
				'sum_ms' => 2.0 * $base, 'min_ms' => 2.0, 'max_ms' => 2.0,
				'sum_peak_mb' => 0, 'max_peak_mb' => 0, 'count_2xx' => $base,
				'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
				'worker' => 0 === $i % 2, 'last_seen' => $now,
			];
		}
		$this->set_url_bucket( $store, $bucket, $seed );

		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		// One of each: a shard is capped when it is WRITTEN, and each family is
		// written by its own traffic.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 3.0, 'timestamp' => $now, 'is_worker' => true ] ) );
		$fb->flush();

		$shard  = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a' ) );
		$worker = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( 'a', true ) ) );

		$this->assertArrayNotHasKey( Stats_Store::other_key( true ), $shard, 'the reader family folds reader rows only' );
		$this->assertFalse( $shard[ Stats_Store::other_key( false ) ]['worker'] );
		$this->assertTrue( $worker[ Stats_Store::other_key( true ) ]['worker'], 'and the worker family its own' );
		self::assert_within_item_budget( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( 'a', true ) ) );
		self::assert_within_item_budget( $this->get_url_shard( $store, $bucket, 'a' ) );
	}

	public function test_a_url_row_records_whether_its_traffic_was_a_worker(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// `$count_global` keeps workers out of every site-wide aggregate — "one
		// long-running worker would dominate the site-wide averages" — but the
		// per-URL row deliberately keeps their timing, so a header summed from
		// the index inherits them. The row has to say which it is; deriving it
		// from `?worker_type` in the URL text is the substring guess that made
		// `--server` empty the table.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/w?reconcile', 'duration_ms' => 90000.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/reader', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$rows   = $this->url_bucket_rows( $store, $bucket );

		$this->assertTrue( $rows[ Log_Manager::url_hash( '/w?reconcile' ) ]['worker'] );
		$this->assertFalse( $rows[ Log_Manager::url_hash( '/reader' ) ]['worker'] );
	}

	public function test_a_host_header_spray_folds_instead_of_growing_the_axis(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		// @longform `server_name` is `SERVER_NAME`, which under Apache's default
		// `UseCanonicalName Off` is the CLIENT'S Host header — so on a
		// domain-mapped multisite or any catch-all vhost this axis is visitor
		// input, not the fleet. Uncapped, the global `dim:server` item grows
		// without limit until memcached refuses the write, and the URL index
		// mints a key set per name.
		$now   = self::tick();
		$spray = 300;
		for ( $i = 0; $i < $spray; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/xmlrpc.php',
				'server_name' => \sprintf( 'spray%03d.probe.test', $i ),
				'duration_ms' => 4.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$bucket  = Stats_Store::bucket_key( $now );
		$servers = $this->get_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), $bucket );
		$index   = Stats_Store::index_names( $store->server_index( [], [ $bucket ] )[ $bucket ] );
		$hash    = Log_Manager::url_hash( '/xmlrpc.php' );

		$this->assertLessThanOrEqual( Stats_Store::MAX_SERVER_VALUES, \count( $servers ) );
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $index, 'the index names the cap and `Other`' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $servers );
		$this->assertContains( Stats_Store::OTHER_KEY, $index );
		// Folded, not dropped: every request is still counted on both axes.
		$this->assertSame( $spray, \array_sum( \array_column( $servers, Stats_Store::DIM_COUNT ) ) );
		$this->assertSame( $spray, $this->url_bucket_rows( $store, $bucket )[ $hash ]['count'] );
	}

	public function test_a_nameless_producer_is_filed_under_unknown(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// `accumulate_dimensions()` maps an empty server to the literal
		// 'Unknown' on the `server` axis, and the dashboard builds its picker
		// from THAT axis — so WP-CLI and cron traffic put 'Unknown' in the
		// dropdown, and choosing it has to find their rows. The two axes have
		// to agree on the name.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cron', 'server_name' => '', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$hash   = Log_Manager::url_hash( '/cron' );
		$this->assertSame( [ Stats_Store::server_key( 'Unknown' ) => 'Unknown' ], Stats_Store::index_names( $store->server_index( [], [ $bucket ] )[ $bucket ] ) );
		$this->assertSame( 1, self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), 'Unknown' ) )[ $hash ]['count'] );
	}

	public function test_no_single_index_item_holds_the_whole_bucket(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// @longform What sharding actually buys, and it is NOT write
		// amplification: with 16 shards a flush carrying k distinct hashes
		// touches 16 * (1 - (15/16)^k) of them — 7.6 at k=10, 16 at k=100 — so
		// at any real flush width every shard is written and the total bytes
		// are unchanged. What changes is the ITEM: memcached refuses one over
		// its limit and the refused write loses that whole item, so a bucket
		// that lives in one blob is one blob away from losing every URL in it.
		$now = self::tick();
		for ( $i = 0; $i < 320; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url' => "/wide-{$i}", 'duration_ms' => 5.0, 'timestamp' => $now,
			] ) );
		}
		$fb->flush();

		$bucket  = Stats_Store::bucket_key( $now );
		$largest = 0;
		$whole   = 0;
		foreach ( Stats_Store::url_shards() as $shard ) {
			$bytes    = \strlen( \serialize( $this->get_url_shard( $store, $bucket, $shard ) ) );
			$whole   += $bytes;
			$largest  = \max( $largest, $bytes );
		}

		$this->assertGreaterThan( 0, $largest );
		$this->assertLessThan(
			(int) ( $whole / 4 ),
			$largest,
			"the busiest item holds {$largest} of {$whole} bytes — the bucket is not split"
		);
	}

	public function test_a_flush_writes_only_the_shards_its_rows_land_in(): void {
		$fb    = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 86400, $fb ) );
		$fb->set_stats_store( $store );

		// Routing, not a saving: at production flush width every shard is
		// touched anyway (see the test above). This pins that a row goes to the
		// shard its hash names and nowhere else, which is what makes a point
		// read able to skip the other fifteen.
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/one', 'duration_ms' => 5.0, 'timestamp' => $now ] ) );
		$fb->flush();
		$written = \array_values( \array_filter(
			$store->written,
			static fn ( string $key ): bool => Stats_Store::NS_URLS === Stats_Store::namespace_of( $key )
		) );

		$this->assertCount( 1, $written, \implode( ', ', $written ) );
		$this->assertSame(
			Stats_Store::key_at( Stats_Store::url_shard_parts( Stats_Store::server_key( 'example.com' ), Stats_Store::url_shard( Log_Manager::url_hash( '/one' ) ) ), $bucket ),
			$written[0] ?? ''
		);
	}

	public function test_a_stored_url_row_carries_its_path_and_not_its_origin(): void {
		// The server is the key, so the origin on every row of every bucket
		// would say it again. The path is what the row names; the whole URL
		// still lives once in the name table.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$url    = 'https://bend.example/2026/08/31/a-headline-worth-101-bytes/';
		$hash   = Log_Manager::url_hash( $url );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'server_name' => 'bend.example', 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$row = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), 'bend.example' ) )[ $hash ] ?? [];
		$this->assertSame( '/2026/08/31/a-headline-worth-101-bytes/', $row['path'] ?? null );
		$this->assertSame(
			[ $hash => [ 'server' => 'bend.example', 'url' => $url ] ],
			$store->get_url_names( [ $hash ] )
		);
	}

	public function test_worker_traffic_is_indexed_apart_from_reader_traffic(): void {
		// One row per URL merged both kinds behind a `worker` flag, so a URL a
		// job also visits left the default table taking its READER requests
		// with it — and every default read paid for rows it then discarded.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$url    = '/served-both-ways-8261';
		$hash   = Log_Manager::url_hash( $url );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 8.0, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 61.0, 'timestamp' => $now, 'is_worker' => true ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 62.0, 'timestamp' => $now, 'is_worker' => true ] ) );
		$fb->flush();

		$reader = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ) ) );
		$worker = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash, true ) ) );

		$this->assertSame( 1, $reader[ $hash ]['count'] ?? -1, 'the reader row counts reader requests only' );
		$this->assertSame( 2, $worker[ $hash ]['count'] ?? -1, 'and the worker rows are their own index' );
	}

	public function test_the_capped_tail_folds_into_one_other_row(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// A dropped tail would make every total summed from this index a lower
		// bound by however much traffic fell off. Folding it into one row keeps
		// the shard bounded and the totals exact.
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [];
		$cap    = self::ROWS_PAST_BUDGET;
		for ( $i = 0; $i < $cap; $i++ ) {
			// All in one shard, which is where the cap now applies.
			$base = $cap + 500 - $i;
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url'         => self::long_path( "/u{$i}" ),
				// Descending, so the tail is the quietest rows.
				'count'       => $base,
				'timed_count' => $base,
				'sum_ms'      => 2.0 * $base,
				'count_2xx'   => $base,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'min_ms'      => 2.0,
				'max_ms'      => 2.0,
				'sum_peak_mb' => 0,
				'max_peak_mb' => 0,
				'last_seen'   => $now,
			];
		}
		$this->set_url_bucket( $store, $bucket, $seed, 'alpha.example' );

		// A shard is capped when it is WRITTEN, so the flush has to land in it.
		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'server_name' => 'alpha.example', 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$shard = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a', 'alpha.example' ) );
		$other = $shard[ Stats_Store::OTHER_KEY ] ?? null;

		$this->assertNotNull( $other, 'the tail folds into one row' );
		self::assert_within_item_budget( $this->get_url_shard( $store, $bucket, 'a', 'alpha.example' ) );
		// The fold keeps every request: the seeded counts, and the one written.
		$seeded = \array_sum( \array_column( $seed, 'count' ) );
		$this->assertSame( $seeded + 1, \array_sum( \array_column( $shard, 'count' ) ) );
		// And it takes the quietest: every row kept outranks every row folded.
		$kept   = \array_diff_key( $shard, [ Stats_Store::OTHER_KEY => true ] );
		$folded = \array_diff_key( $seed, $kept );
		$this->assertNotSame( [], $folded );
		$this->assertLessThan(
			\min( \array_column( \array_intersect_key( $seed, $kept ), 'count' ) ),
			\max( \array_column( $folded, 'count' ) )
		);
		$this->assertSame( '', $other['path'], 'many URLs, so no one path' );
	}

	public function test_the_url_index_is_filed_by_server_on_a_spoke_too(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( false );

		// The three per-server AGGREGATES are hub-only, and this one looks like
		// it forgot the gate. It did not: the server filter is offered wherever
		// the `server` dimension has values, which is everywhere, so gating
		// this would empty the URL table on every spoke rather than save it
		// anything.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/spoke', 'server_name' => 'lone.example', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$hash   = Log_Manager::url_hash( '/spoke' );
		$this->assertSame( [ Stats_Store::server_key( 'lone.example' ) => 'lone.example' ], Stats_Store::index_names( $store->server_index( [], [ $bucket ] )[ $bucket ] ) );
		$this->assertArrayHasKey( $hash, $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), 'lone.example' ) );
	}

	public function test_a_stored_row_carries_exactly_the_summed_fields(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// The accumulator adds its fields by hand while the persist merge sums
		// `ROW_SUMS`. A field added to one and not the other is dropped or
		// invented silently, so the two are held to the same set here.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/fields', 'server_name' => 'alpha.example', 'duration_ms' => 30.0, 'peak_mb' => 4.0, 'timestamp' => $now, 'status_code' => 500, 'error_status' => 'F' ] ) );
		$fb->flush();

		$row = $this->url_bucket_rows( $store, Stats_Store::bucket_key( $now ) )[ Log_Manager::url_hash( '/fields' ) ];

		// Values, not keys: `sum_entry()` materializes all nine at persist, so a
		// field the accumulator forgot arrives as a plausible 0.
		$this->assertSame(
			[
				'count'       => 1,
				'timed_count' => 1,
				'sum_ms'      => 30.0,
				'sum_peak_mb' => 4.0,
				'count_2xx'   => 0,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 1,
				'errors'      => 1,
			],
			\array_intersect_key( $row, \array_flip( \array_intersect_key( Stats_Store::ROW_FIELD_NAMES, Stats_Store::ROW_SUMS ) ) )
		);
	}

	public function test_url_index_min_ms_persisted_real_survives_later_untimed_flush(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// First flush persists a real min (42) for the URL. A later, separate
		// flush of an untimed-only (worker) request for the same URL must not
		// clobber the already-persisted real min — the write-side timed_count
		// guard protects it across flushes.
		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/p', 'duration_ms' => 42.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/p', 'duration_ms' => 100.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
		$url_hash = Log_Manager::url_hash( '/p' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertEqualsWithDelta( 42.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_flush_persists_hourly_to_memcache(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$req = $this->completed_request( [ 'duration_ms' => 100.0, 'peak_mb' => 32.0 ] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$hourly = $this->recent_hourly( $store );
		$this->assertNotEmpty( $hourly );
		// Some hour bucket has count=1, sum_ms=100, sum_peak_mb=32.
		$bucket = \array_keys( $hourly )[0];
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 100.0, $hourly[ $bucket ]['sum_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 32.0, $hourly[ $bucket ]['sum_peak_mb'], 1e-6 );
	}

	public function test_flush_persists_dimensional_status(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 200 ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 500 ] ) );
		$fb->flush();

		$dim = $this->dim_series( $store, 'status' );
		$this->assertNotEmpty( $dim );
		// Status normalized to "Nxx" form by accumulate_all_stats.
		$bucket = \array_keys( $dim )[0];
		$this->assertSame( 1, $dim[ $bucket ]['2xx'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 1, $dim[ $bucket ]['5xx'][ Stats_Store::DIM_COUNT ] );
	}

	/**
	 * The stats a request contributes must not depend on whether
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

		$plain = $this->stats_for( \array_replace( $base, [ 'entries' => $entries ] ), 0 );
		$rolled = $this->stats_for(
			\array_replace(
				$base,
				[
					'entries' => [],
					'flame'   => Flame_Fold::tree( $fold ),
					'folded'  => true,
				]
			),
			1
		);

		$this->assertSame( $plain, $rolled );
	}

	/**
	 * Every stats namespace one request writes, for the parity comparison
	 * above. Same URL and clock both runs, so any difference is the fold's.
	 *
	 * @param array<string,mixed> $request   Completed-request record.
	 * @param int                 $partition A keyspace of its own, apart from the other run's.
	 * @return array<string,mixed> The persisted stats.
	 */
	private function stats_for( array $request, int $partition ): array {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: $partition, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( $request ) );
		$fb->flush();

		$timestamp = $request['timestamp'];
		$bucket    = Stats_Store::bucket_key( \is_int( $timestamp ) ? $timestamp : self::tick() );
		return [
			'leaderboard' => $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ) ),
			'categories'  => $store->get_slots( Stats_Store::cat_parts( '' ), [ Stats_Store::hour_of( $bucket ) ] ),
			'hourly'      => $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( $bucket ) ] ),
			'urls'        => $this->url_bucket_rows( $store, $bucket ),
		];
	}

	public function test_flush_persists_categories_and_leaderboard(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$req = $this->completed_request( [
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [
				'wpdb' => [
					'time'    => 0.4,
					'count'   => 12,
					'ts'      => $now,
					'entries' => [ 'SELECT' => [ 0.3, 8 ] ],
				],
			],
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		// Global category time series.
		$cats = $this->cat_series( $store );
		$this->assertNotEmpty( $cats );
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertArrayHasKey( 'wpdb', $cats[ $bucket ] );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ] );
		$this->assertEqualsWithDelta( 0.4, $cats[ $bucket ]['wpdb'][ Stats_Store::CAT_MS ], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $cats[ $bucket ]['wpdb'][ Stats_Store::CAT_CALLS ], 1e-6 );

		// Leaderboard bucket holds sums-not-means.
		$lb = $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ) );
		$this->assertSame( 1, $lb['count'] );
		$this->assertArrayHasKey( 'wpdb', $lb['categories'] );
		$this->assertEqualsWithDelta( 0.4, $lb['categories']['wpdb']['sum_time'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $lb['categories']['wpdb']['sum_count'], 1e-6 );
	}

	public function test_timed_out_requests_excluded_from_timing_but_counted(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// error_status='T' means timed-out; duration is synthetic. Must not
		// pollute the timing/leaderboard sums.
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 99999.0, 'error_status' => 'T' ] ) );
		$fb->flush();

		$hourly = $this->recent_hourly( $store );
		// hourly gets count++ and sum_ms is incremented only for has_timing requests.
		// With T-status, count stays 0 (since has_timing is false).
		foreach ( $hourly as $bucket => $stats ) {
			$this->assertSame( 0, $stats['count'], 'timed-out excluded from count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'timed-out excluded from sum_ms' );
		}
	}

	// --- finalize_flame_node ----------------------------------------------

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

	public function test_flush_without_stats_store_does_not_throw(): void {
		// In test mode (no store), flush() still drains state but writes nowhere.
		$fb = new Flame_Builder_Node();
		$this->fill_request( $fb, $this->completed_request() );
		$fb->flush(); // must not throw
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	public function test_finalize_strips_internal_fields(): void {
		$node = [
			'name'      => 'root',
			'sum_value' => 5.0,
			'seen_count' => 1,
			'ts'        => 12345,
			'count'     => 1,
			'children'  => [],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertArrayNotHasKey( 'sum_value', $node );
		$this->assertArrayNotHasKey( 'seen_count', $node );
		$this->assertArrayNotHasKey( 'ts', $node );
	}

	// ── A3: sibling-interpreter + verbs ─────────────────────────────────

	public function test_flame_builder_constructs_sibling_interpreter(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertNotNull( $this->read_private( $fb, 'interpreter' ) );
		$this->assertSame( 'fb:config', $this->read_private( $fb, 'interpreter' )->name() );
	}

	public function test_flame_builder_set_is_hub_verb_round_trips(): void {
		// What a dump owes is REPLAY, not a literal: the argument it emits has
		// to come back through the same verb as the same state.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// The substrate's synthesized toggle handler answers "ok\n".
		$this->assertSame( "ok\n", $this->read_private( $fb, 'interpreter' )->dispatch( 'set_is_hub', [ 'true' ] ) );
		$dump = $fb->dump_config();
		$this->assertMatchesRegularExpression( '/command_node fb:config set_is_hub (\S+)/', $dump );

		\preg_match( '/command_node fb:config set_is_hub (\S+)/', $dump, $m );
		$replayed = new Flame_Builder_Node();
		$replayed->name( 'fb2' );
		$this->read_private( $replayed, 'interpreter' )->dispatch( 'set_is_hub', [ $m[1] ] );
		$this->assertTrue( $this->read_private( $replayed, 'is_hub' ), 'the dumped argument replays as ON' );
	}

	public function test_flame_builder_node_schema_declares_verbs(): void {
		$schema = Flame_Builder_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'set_is_hub', $verb_names );
		$this->assertContains( 'configure_stats', $verb_names );
		// Node-wide auto-tune verbs were removed in favor of per-rule thresholds.
		$this->assertNotContains( 'set_auto_tune', $verb_names );
		$this->assertNotContains( 'set_significant_events', $verb_names );
	}

	// --- Tick clock / maintenance / non-stats setters ---------------------

	public function test_the_tick_drives_bucket_key_derivation(): void {
		// Use a fixed clock so we can pin the bucket key without timing flake.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$fixed = 1_700_000_000; // Stable; floor to 5-min bucket.
		Core::$now = $fixed;

		$req = $this->completed_request( [
			'duration_ms' => 25.0,
			'timestamp'   => $fixed,
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $fixed );
		$hourly = $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( $bucket ) ] );
		$this->assertArrayHasKey( $bucket, $hourly );
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );

	}

	public function test_a_request_is_read_back_from_the_bucket_the_tick_names(): void {
		// The builder clamps a request to the tick, so a test dated from the
		// wall reads a bucket off whenever a boundary falls after setUp.
		$this->shift_tick( -Stats_Store::BUCKET_SECONDS );
		$this->test_url_index_min_ms_zero_for_untimed_only_url();
	}

	public function test_the_tick_flush_is_dated_from_the_tick(): void {
		// The tick's flush reads the tick, not the wall.
		$this->shift_tick( -Stats_Store::BUCKET_SECONDS );
		$this->test_the_tick_flushes_what_fill_folded();
	}

	public function test_the_tick_flushes_what_fill_folded(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0 ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 13.0 ] ) );
		$this->assertSame( [], $this->recent_hourly( $store ), 'fill folds and never flushes' );

		$fb->fire_cb();

		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 2, \array_sum( \array_column( $totals, 'count' ) ), 'the tick flushed both' );
	}

	/**
	 * Truly idle: nothing folded, no auto-tune queued, and a window too short
	 * to hold a closed hour, so nothing is owed a roll-up either. The tick is
	 * mid-hour, since a ten-minute window at :05 reaches the hour before.
	 */
	public function test_a_tick_with_nothing_owed_touches_no_store(): void {
		Core::$now  = \gmmktime( 14, 37, 0, 9, 22, 2026 );
		$store      = new class( ...$this->stats_store_args( 0, 600 ) ) extends Stats_Store {
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				throw new \LogicException( 'an idle tick reached the store' );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		$fb->fire_cb();

		$this->assertSame( [], $this->get_stats( $fb )['pending_buckets'] );
	}

	/**
	 * The read plan is a function of the five-minute bucket alone, so idle
	 * ticks inside one bucket build it once, and the next bucket builds it anew.
	 */
	public function test_idle_ticks_within_one_bucket_build_the_read_plan_once(): void {
		$store = new class( ...$this->stats_store_args( 0, 600 ) ) extends Stats_Store {
			public int $window_reads = 0;
			public function max_lifespan(): int {
				++$this->window_reads;
				return parent::max_lifespan();
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		$start = 1_900_000_000 - ( 1_900_000_000 % Stats_Store::BUCKET_SECONDS );

		foreach ( [ 5, 10, 15, 20 ] as $offset ) {
			Core::$now = $start + $offset;
			$fb->fire_cb();
		}
		$this->assertSame( 1, $store->window_reads, 'four idle ticks in one bucket build one plan' );

		Core::$now = $start + Stats_Store::BUCKET_SECONDS + 5;
		$fb->fire_cb();
		$this->assertSame( 2, $store->window_reads, 'the next bucket builds its own' );
	}

	/**
	 * The periodic flush is the node's own work, not a record's. A store that
	 * throws must leave every record folded and undead-lettered, and raise
	 * from the tick, where the worker exits loudly.
	 */
	public function test_a_failing_flush_raises_from_the_tick_and_poisons_no_record(): void {
		$store = new class( ...$this->stats_store_args( 0, 86400 ) ) extends Stats_Store {
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				throw new \RuntimeException( 'kea store unreachable' );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 211.0 ] ), '4:880:61' );

		$thrown = null;
		try {
			$fb->fire_cb();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}
		$this->assertSame( 'kea store unreachable', $thrown?->getMessage(), 'the tick raises the flush failure' );
	}

	/** A tick that failed leaves the sums for the next one, each counted once. */
	public function test_the_tick_after_a_failed_flush_writes_each_sum_once(): void {
		$store = new class( ...$this->stats_store_args( 0, 86400 ) ) extends Stats_Store {
			public bool $refuse = true;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				if ( $this->refuse ) {
					$this->refuse = false;
					throw new \RuntimeException( 'tui store refused' );
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		foreach ( [ '9:10:40' => 97.0, '9:50:40' => 113.0, '9:90:40' => 131.0 ] as $crumb => $ms ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => $ms ] ), $crumb );
		}
		try {
			$fb->fire_cb();
		} catch ( \RuntimeException ) {
			// The first tick's refusal; the next tick retries.
		}
		$fb->fire_cb();

		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 3, \array_sum( \array_column( $totals, 'count' ) ), 'each request is written once' );
		$this->assertEqualsWithDelta( 341.0, \array_sum( \array_column( $totals, 'sum_ms' ) ), 1e-6 );
	}

	/**
	 * Decisions a sibling's lock held back outlive the records that made them:
	 * the next tick applies them though it folded nothing.
	 */
	public function test_a_tick_with_nothing_folded_applies_the_auto_tune_a_held_lock_left(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$lock       = self::scoped( 'evlog:auto_disable_lock' );
		$mc->add( $lock, 'sibling-4433', 300 );
		$fb      = $this->armed_flame_builder( $this->stats_store( partition: 0, max_lifespan: 600 ) );
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'gannet hook' => [ 'time' => 0.1, 'count' => 211, 'entries' => [] ] ],
		] ) );
		$this->router_tick( 0 );
		$this->assertSame( [ 'gannet' ], $fb->get_auto_tune_state()['disable_hooks']['r'] ?? null, 'the lock held it back' );
		$mc->delete( $lock );

		$this->router_tick( Flame_Builder_Node::FLUSH_INTERVAL_SEC );

		$fired = \array_values( \array_filter( $capture->captured, static fn ( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' ) ) );
		$this->assertCount( 1, $fired, 'the next tick applied what was queued' );
		$this->assertSame( [ 'gannet' ], $fired[0][ Message::VALUE ]['items'] );
	}

	/** An hour that closed after traffic stopped is still rolled up. */
	public function test_a_tick_with_nothing_folded_rolls_up_a_closed_hour(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = $this->armed_flame_builder( $store );
		$fb->sink( new Capture_Sink_Node() );
		$hour = Stats_Store::hour_of( Stats_Store::bucket_key( (int) Core::$now ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/auklet-4436', 'duration_ms' => 23.0 ] ) );
		$this->router_tick( 0 );
		$shard  = Stats_Store::url_shard( Log_Manager::url_hash( '/auklet-4436' ) );
		$rolled = fn (): array => $this->get_url_hour( $store, $hour, $shard );
		$this->assertSame( [], $rolled(), 'the hour was still open' );

		// Past the hour's close, inside the fine tier's lifetime.
		$this->router_tick( 3600 );
		for ( $tick = 0; [] === $rolled() && $tick < 12; $tick++ ) {
			$this->router_tick( Flame_Builder_Node::FLUSH_INTERVAL_SEC );
		}

		$this->assertNotEmpty( $rolled(), 'a later tick folded the closed hour into the coarse tier' );
	}

	/**
	 * The flush runs where production runs it: the Router's tick fires the
	 * armed node, which flushes once per FLUSH_INTERVAL_SEC and lets the ticks
	 * between pass.
	 */
	public function test_the_router_tick_flushes_at_the_flush_cadence(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 600 );
		$this->armed_flame_builder( $store )->sink( new Capture_Sink_Node() );
		$fb = Core::node( 'fb' );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 17.0 ] ) );
		$this->router_tick( 0 );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 19.0 ] ) );
		$this->router_tick( Flame_Builder_Node::FLUSH_INTERVAL_SEC - 1 );
		$between = \array_sum( \array_column( $this->recent_hourly( $store ), 'count' ) );
		$this->router_tick( 1 );

		$this->assertSame( 1, $between, 'a tick inside the interval flushes nothing' );
		$this->assertSame( 2, \array_sum( \array_column( $this->recent_hourly( $store ), 'count' ) ), 'the tick at the interval flushes the rest' );
	}

	/** Round-trip: what `arguments()` was given is what it reads back. */
	public function test_arguments_read_back_what_they_were_given(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$fb->arguments( [ 'petrel-4434', 'skua-4435' ] );

		$this->assertSame( [ 'petrel-4434', 'skua-4435' ], $fb->arguments() );
	}

	/** A builder `fb` armed the way `make_node` arms one, on the Router of its store's request graph. */
	private function armed_flame_builder( Stats_Store $store ): Flame_Builder_Node {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->arguments( [] );
		$fb->set_stats_store( $store );
		return $fb;
	}

	/** Advance the tick by $seconds, then fire the Router's TIMER. */
	private function router_tick( int $seconds ): void {
		Core::$now += $seconds;
		Core::node( \Newspack_Nodes\Node_Names::ROUTER )->fire_cb();
	}

	/** The flush rides the Router tick at its own cadence. */
	public function test_arguments_arm_the_flush_on_the_router_tick(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$fb->arguments( [] );

		$this->assertSame( 'router', $fb->timer_mode() );
		$this->assertSame( Flame_Builder_Node::FLUSH_INTERVAL_SEC * 1000, $fb->interval_ms );
	}

	public function test_set_custom_event_names_dispatches_to_custom_events(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50 ] );
		$fb = new Flame_Builder_Node();
		$fb->set_custom_event_names( [ 'mything', 'other' ] );

		$req = $this->completed_request( [
			'profiles' => [
				// "mything" is in custom event names — routes to disable_custom_events.
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

	public function test_rule_significant_events_suppress_redundant_proposals(): void {
		// An event already declared significant on the governing rule is not
		// re-flagged when its avg-time detection would otherwise promote it.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05, 'significant_events' => [ 'wpdb' ] ] );
		$fb = new Flame_Builder_Node();

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
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'render page' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'render page' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** Only a one-token slug is a plugin load; a custom event ending in "plugin" is the application's. */
	public function test_a_multi_word_custom_event_ending_in_plugin_is_still_promoted(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'sync remote plugin' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'sync remote plugin' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** The candidate is the application's own name, not the collision-free key the accumulator files it under. */
	public function test_a_custom_event_named_total_is_proposed_under_its_own_name(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

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
		$this->set_rule( [ 'auto_disable_threshold' => 50 ] );
		$fb = new Flame_Builder_Node();
		$fb->set_custom_event_names( [ 'render page' ] );

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

	// --- Checkpoint: a flush, and the crumb alone ---------------------------

	public function test_a_checkpoint_writes_every_pending_bucket_and_carries_the_crumb_and_the_data_clock(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$this->fill_request_at( $fb, $this->completed_request( [] ), 'crumb-7731' );

		$this->assertSame( [ 'counted' => 'crumb-7731', 'data_clock' => Stats_Store::bucket_key( self::tick() ), 'data_clock_hour_since' => Core::$now ], $fb->save_state() );
		$this->assertSame( 1, $this->get_hour_slot( $store, Stats_Store::hourly_parts(), Stats_Store::bucket_key( self::tick() ) )['count'] ?? null, 'the open bucket is in its Table' );
	}

	/**
	 * A checkpoint writes the buckets and hands back the crumb even when every
	 * auto-tune emit throws: the decisions wait for the tick, and the cursor
	 * still commits, so the successor counts nothing twice.
	 */
	public function test_a_checkpoint_commits_past_an_auto_tune_emit_that_always_throws(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 57 ] );
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->sink( new class() extends \Newspack_Nodes\Node {
			public function fill( array $message ): void {
				throw new \RuntimeException( 'auto-tuner refused the rewrite' );
			}
		} );
		$noisy = [ 'kestrel hook' => [ 'time' => 3.0, 'count' => 400, 'entries' => [] ] ];
		foreach ( [ '3:100:41', '3:200:42', '3:300:43' ] as $crumb ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 211.0, 'profiles' => $noisy ] ), $crumb );
		}

		$this->assertSame( '3:300:43', $fb->save_state()['counted'] );
		$this->assertSame( 3, \array_sum( \array_column( $this->recent_hourly( $store ), 'count' ) ), 'the buckets are written' );
		$this->expectExceptionMessage( 'auto-tuner refused the rewrite' );
		$fb->flush();
	}

	public function test_a_restore_takes_the_crumb_back_and_counts_the_replay_once(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$fb->restore_state( [ 'counted' => 'crumb-7731' ] );
		$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 61.0 ] ), 'crumb-7731' );
		$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 37.0 ] ), 'crumb-7732' );
		$fb->flush();

		$hourly = $this->get_hour_slot( $store, Stats_Store::hourly_parts(), Stats_Store::bucket_key( self::tick() ) );
		$this->assertSame( 1, $hourly['count'] ?? null, 'the replay is not counted; the next record is' );
		$this->assertEqualsWithDelta( 37.0, $hourly['sum_ms'] ?? null, 1e-6 );
	}

	/**
	 * A plain stop leaves the cursor ON the record whose flame forward failed.
	 * With no sweep before it, the checkpoint alone must write every record
	 * the cursor passed and name the one it sits on, so the successor's replay
	 * counts each record exactly once: none dropped, none twice.
	 */
	public function test_a_plain_stops_checkpoint_replays_neither_dropping_nor_double_counting(): void {
		$this->set_rule();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$first = new Flame_Builder_Node();
		$first->name( 'fb' );
		$first->set_stats_store( $store );
		$first->connect_node( 'flames:partition' );
		$first->sink( new class() extends \Newspack_Nodes\Node {
			public function fill( array $message ): void {
				if ( ++$this->counter > 1 ) {
					throw new \Newspack_Nodes\Worker_Should_Stop( 'deadline', 0, new \RuntimeException( 'flames flush failed' ) );
				}
			}
		} );
		$stopped = $this->completed_request( [ 'url' => '/kea-42', 'duration_ms' => 37.0 ] );
		$this->fill_request_at( $first, $this->completed_request( [ 'url' => '/kea-41', 'duration_ms' => 61.0 ] ), '3:100:41' );
		try {
			$this->fill_request_at( $first, $stopped, '3:200:42' );
			$this->fail( 'expected the plain stop to propagate' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
			$this->assertFalse( \Newspack_Nodes\Worker_Should_Stop::is_clean( $e ), 'the reader replays the record' );
		}
		// As the offsetlog stores it: the carry is plain JSON.
		$checkpoint = \json_decode( (string) \wp_json_encode( $first->save_state() ), true );
		$first->remove_node();

		$this->assertSame( [ 'counted' => '3:200:42', 'data_clock' => Stats_Store::bucket_key( self::tick() ), 'data_clock_hour_since' => Core::$now ], $checkpoint );
		$this->assertSame( 2, \array_sum( \array_column( $this->recent_hourly( $store ), 'count' ) ), 'both records are written before the cursor commits' );

		$successor = new Flame_Builder_Node();
		$successor->name( 'fb' );
		$successor->set_stats_store( $store );
		$successor->connect_node( 'flames:partition' );
		$flames = new Capture_Sink_Node();
		$successor->sink( $flames );
		$successor->restore_state( $checkpoint );
		$this->fill_request_at( $successor, $stopped, '3:200:42' );
		$this->fill_request_at( $successor, $this->completed_request( [ 'url' => '/kea-43', 'duration_ms' => 777.0 ] ), '3:300:43' );
		$successor->flush();

		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 3, \array_sum( \array_column( $totals, 'count' ) ), 'three records, each counted once' );
		$this->assertEqualsWithDelta( 875.0, \array_sum( \array_column( $totals, 'sum_ms' ) ), 1e-6 );
		$this->assertCount( 2, $flames->captured, 'the replayed flame is forwarded again, with the new one' );
	}

	// --- TM_REQUEST verbs (GET_STATS) ------------------------------------

	public function test_get_stats_returns_payload(): void {
		// A low per-rule time threshold promotes both seeded hooks so
		// significant_events_count (now summed across the per-rule cache) is 2.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_is_hub( true );

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
		$this->assertArrayHasKey( 'stats_count', $payload['data'] );
		$this->assertArrayHasKey( 'pending_url_count', $payload['data'] );
		$this->assertArrayHasKey( 'auto_tune_pending_count', $payload['data'] );
		$this->assertTrue( $payload['data']['is_hub'] );
		$this->assertSame( 2, $payload['data']['significant_events_count'] );
	}

	public function test_pending_url_count_reports_distinct_urls(): void {
		$this->set_rule();
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );

		foreach ( [ '/alpha', '/beta', '/gamma' ] as $url ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		}

		// Three distinct URLs — not the ten fixed accumulator keys.
		$this->assertSame( 3, $this->get_stats( $fb )['pending_url_count'] );
	}

	public function test_intern_table_freezes_for_entry_names_at_the_cap(): void {
		$this->set_rule();
		$fb = new TinyInternFlameBuilder();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );

		// One request is enough to reach a cap of three and freeze the table.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/warm' ] ) );
		$stats = $this->get_stats( $fb );
		$this->assertArrayHasKey( 'intern_count', $stats );
		$frozen_at = $stats['intern_count'];

		$entries = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$entries[ "hook_number_{$i}" ] = [ 1.5, 1 ];
		}
		$this->fill_request(
			$fb,
			$this->completed_request(
				[ 'profiles' => [ 'plugins_loaded' => [ 'entries' => $entries, 'count' => 1, 'time' => 30.0 ] ] ]
			)
		);

		// Entry names are the highest-cardinality strings; a frozen table takes none.
		$this->assertSame( $frozen_at, $this->get_stats( $fb )['intern_count'] );
	}

	public function test_a_custom_event_named_total_does_not_corrupt_the_rollup_row(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$this->set_rule();
		$fb->set_stats_store( $store );

		// 'total' is the reserved rollup key; a custom event may be named anything.
		$this->fill_request(
			$fb,
			$this->completed_request(
				[
					'duration_ms' => 250.0,
					'profiles'    => [
						'total'  => [ 'entries' => [], 'count' => 7, 'time' => 40.0 ],
						'wpdb'   => [ 'entries' => [], 'count' => 3, 'time' => 10.0 ],
					],
				]
			)
		);
		$fb->flush();

		$buckets = $this->cat_series( $store );
		$this->assertNotEmpty( $buckets, 'category series written' );
		$bucket = \reset( $buckets );

		// One request in the bucket, whatever the incoming categories are called.
		$this->assertSame( 1, $bucket['total'][ Stats_Store::CAT_REQUESTS ], 'rollup counts requests, not categories' );
		$this->assertEqualsWithDelta( 250.0, $bucket['total'][ Stats_Store::CAT_MS ], 1e-6, 'rollup time is request wall time' );
		$this->assertEqualsWithDelta( 10.0, $bucket['total'][ Stats_Store::CAT_CALLS ], 1e-6, 'rollup counts every category call' );

		// The colliding event still gets its own row, under a distinct key.
		$this->assertArrayHasKey( 'wpdb', $bucket );
		$rows = \array_diff( \array_keys( $bucket ), [ 'total', 'wpdb' ] );
		$this->assertCount( 1, $rows, 'the colliding event keeps a row of its own' );
		$own = $bucket[ \reset( $rows ) ];
		$this->assertEqualsWithDelta( 40.0, $own[ Stats_Store::CAT_MS ], 1e-6 );
		$this->assertEqualsWithDelta( 7.0, $own[ Stats_Store::CAT_CALLS ], 1e-6 );
	}

	public function test_an_unknown_request_verb_is_refused_on_the_error_plane(): void {
		$fb                        = new Flame_Builder_Node();
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
		$fb                        = new Flame_Builder_Node();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_RESPONSE;
		$message[ Message::VALUE ] = 'GET_STATS';
		$fb->sink( $capture );
		$fb->fill( $message );
		$this->assertSame( 0, $this->stats_count( $fb ), 'response not processed as request' );
		$this->assertCount( 1, $capture->captured );
	}

	// --- Auto-tune fire actions + memcache lock ---------------------------

	public function test_apply_auto_tune_emits_messages_via_sink(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

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
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
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
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$capture    = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
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
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$capture    = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		$mc->add( self::scoped( 'evlog:auto_disable_lock' ), 'other-worker', 300 );
		$mono                          = 8_100_000_000_000;
		Quiet::$hrtime_fn              = static function () use ( &$mono ): int {
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

	public function test_apply_auto_tune_with_store_uses_memcache_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
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
			$fb      = new Flame_Builder_Node();
			$capture = new Capture_Sink_Node();
			$fb->name( 'fb' );
			$fb->sink( $capture );
			$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 86400 ) );
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
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
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
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );

		// No auto-tune state set, just a basic flush.
		$fb->flush();

		// The LOCK is what this test is about; the stats live in their Tables.
		$this->assertSame( [], $mc->keys(), 'no lock taken' );
		// No auto-tune emits (only the flush has nothing to emit).
		foreach ( $capture->captured as $m ) {
			$this->assertNotContains( $m[ Message::KEY ], [ 'disable_hooks', 'disable_custom_events', 'add_significant_events' ] );
		}
	}

	public function test_emit_auto_tune_no_op_when_no_sink(): void {
		// Sink-less FlameBuilder still completes flush without crashing.
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// No sink attached.
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'a hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] ) );
		// Should not throw — just drops the emit silently.
		$fb->flush();
		// Auto-tune queues drained after flush.
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [], $state['disable_hooks'] );
	}

	// --- persist_aggregate_stats internals: hourly expiration -------------

	public function test_a_flush_leaves_buckets_it_did_not_fill_alone(): void {
		// Retention is the key's own TTL, so a flush has no reason to touch a
		// bucket it did not fill.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 3600, asker: $fb );
		$fb->set_stats_store( $store );

		$untouched = [ 'count' => 99, 'sum_ms' => 9900, 'sum_peak_mb' => 9 ];
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '1999-01-01-00-00', $untouched );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 5.0 ] ) );
		$fb->flush();

		$this->assertSame( $untouched, $this->get_hour_slot( $store, Stats_Store::hourly_parts(), '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $this->recent_hourly( $store ), 'and the request it did fill landed' );
	}

	/**
	 * A closed hour is folded ONCE into a coarse key, not added to
	 * incrementally: the five-minute write is a full overwrite and is safe to
	 * repeat, while adding into an hour bucket double-counts on a re-flush.
	 */
	public function test_a_request_is_filed_in_the_bucket_it_FINISHED_in(): void {
		// The record reaches the builder at completion, so a long request must
		// land where it ended, not where it began — a 7-minute request started
		// at :58 belongs to the next hour, not the one that has since closed.
		// Seeds distinct from every default: 420s duration, 5-minute buckets.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$start = \gmmktime( 13, 58, 0, 8, 27, 2026 );

		$fb->set_stats_store( $store );
		Core::$now = (float) ( $start + 420 );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/slow-job-9930',
			'timestamp'   => $start,
			'duration_ms' => 420000.0,
			'status_code' => 200,
		] ) );
		$fb->flush();

		$hash  = Log_Manager::url_hash( '/slow-job-9930' );
		$shard = Stats_Store::url_shard( $hash );
		$ended = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-14-05', $shard ) );
		$began = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-13-55', $shard ) );
		$this->assertSame( 1, $ended[ $hash ]['count'] ?? 0, 'filed in the bucket it finished in' );
		$this->assertArrayNotHasKey( $hash, $began, 'and not in the one it started in' );
	}

	public function test_a_respawned_worker_probes_the_coarse_tier_before_it_writes(): void {
		// folded_hours is per-PROCESS and one partition has one worker, so a
		// respawn is the only way the memo goes stale. flush() must probe —
		// which roll_up_hours() does — before placing any row, or the first
		// flush after a respawn writes into fine buckets nothing reads.
		// Seeds distinct from every default: a folded hour of 3, then 8 more.
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hash  = Log_Manager::url_hash( '/respawn-7714' );
		$shard = Stats_Store::url_shard( $hash );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/respawn-7714', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0, 'last_seen' => $now - 7200 ],
		] );
		$folder = new Flame_Builder_Node();
		$folder->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $folder, (int) Core::$now );

		// A DIFFERENT process: same partition, no memory of the fold.
		$fresh = new Flame_Builder_Node();
		$fresh->set_stats_store( $store );
		Core::$now = (float) $now;
		$ref = new \ReflectionProperty( $fresh, 'pending' );
		$ref->setValue( $fresh, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fresh, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ self::SEED_SERVER => [ $hash => self::positional_url_row( [ 'url' => '/respawn-7714', 'count' => 8, 'timed_count' => 8, 'sum_ms' => 240.0, 'last_seen' => $now - 5400 ] ) ] ] ]
		) ] );
		$fresh->flush();

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame(
			11,
			$hour[ $hash ]['count'] ?? 0,
			'the first flush after a respawn must probe before it writes'
		);
	}

	public function test_a_flush_costs_round_trips_by_batch_not_by_url(): void {
		// persist_aggregate_stats() issued one read-modify-write per KEY, and
		// two of its loops are per URL, so a full-window replay decayed as the
		// retention window refilled: ~490 round trips per bucket at 20 distinct
		// URLs, ~2,400 at the 500 cap. The property is that the flush's round
		// trips stop SCALING with URL count — a fixed ceiling would only pin
		// whatever else the flush happens to do today.
		$trips = [];
		foreach ( [ 12 => 0, 48 => 1 ] as $urls => $partition ) {
			$fb = new Flame_Builder_Node();
			$fb->set_stats_store( $this->stats_store( partition: $partition, max_lifespan: 86400 ) );
			for ( $i = 0; $i < $urls; $i++ ) {
				$this->fill_request( $fb, $this->completed_request( [
					'url'         => "/batched-{$i}-6612",
					'duration_ms' => 12.0,
					'status_code' => 200,
				] ) );
			}
			$this->forget_stats_asks();
			$fb->flush();
			$trips[ $urls ] = \count( \Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness::ask_recorder()->asked );
		}

		$this->assertGreaterThan( 0, $trips[12], 'the flush asks its Tables' );
		$this->assertSame( $trips[12], $trips[48], 'four times the URLs, the same round trips' );
	}

	/**
	 * A builder over a fresh store, its tick twenty minutes into the hour
	 * whose start it returns.
	 *
	 * @return array{0: Flame_Builder_Node, 1: Stats_Store, 2: int}
	 */
	private function hour_builder(): array {
		$this->set_rule();
		$start     = \gmmktime( 14, 0, 0, 9, 23, 2026 );
		Core::$now = $start + 20 * 60;
		$store     = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb        = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->sink( new Capture_Sink_Node() );
		return [ $fb, $store, $start ];
	}

	/**
	 * Every flush writes its chart deltas through to the hour keys as well as
	 * the buckets — the site's totals and dimensions, a server's on a hub,
	 * and each URL's — so an hour's keys are whole the moment it closes.
	 */
	public function test_a_flush_writes_its_chart_deltas_through_to_the_hour_keys(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$fb->set_is_hub( true );
		$hour = \gmdate( 'Y-m-d-H', $start );
		$hash = Log_Manager::url_hash( '/post/123' );
		$keys = static fn (): array => [
			self::hour_count( $store->get_slots( Stats_Store::hourly_parts(), [ $hour ] ), 'count' ),
			self::hour_count( $store->get_slots( Stats_Store::dim_parts( 'status', '' ), [ $hour ] ), '5xx' ),
			self::hour_count( $store->get_slots( Stats_Store::dim_parts( 'ua', self::SEED_SERVER ), [ $hour ] ), 'kakapo/2' ),
			self::hour_count( $store->get_slots( Stats_Store::url_dim_parts( $hash ), [ $hour ], 'ua' ), 'kakapo/2' ),
		];
		foreach ( [ 100, 1300 ] as $at ) {
			Core::$now = $start + $at;
			$this->fill_request( $fb, $this->completed_request( [ 'timestamp' => (int) Core::$now, 'status_code' => 503, 'user_agent' => 'kakapo/2', 'duration_ms' => 3719.0 ] ) );
			$fb->fire_cb();
		}
		$open = $keys();

		Core::$now = $start + 3600 + 30;
		$fb->fire_cb();

		$this->assertSame( [ 2, 2, 2, 2 ], $open, 'the totals, a site dimension, a server\'s and a URL\'s, while the hour is open' );
		$this->assertSame( [ 2, 2, 2, 2 ], $keys(), 'and whole at its close' );
	}

	/**
	 * Deployed over a store whose older hours hold their URL index and lists
	 * but no chart hour key, flush after flush: a missing chart key never
	 * refolds an hour, so the index and lists stand as seeded.
	 */
	public function test_a_missing_chart_hour_key_never_refolds_an_hours_url_index(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$row   = [ 'b8823bc1d2ef' => [ 'url' => 'https://' . self::SEED_SERVER . '/numbat-4471', 'count' => 5519 ] ];
		$reads = [];
		foreach ( [ 5, 6 ] as $back ) {
			$hour = \gmdate( 'Y-m-d-H', $start - $back * 3600 );
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'b8823bc1d2ef' ), $row );
			$this->set_url_rank_lists( $store, $hour, $row, true );
			$reads[] = [ Stats_Store::url_srv_parts( true ), $hour ];
			$reads[] = [ Stats_Store::url_rank_parts( 'count', 'desc', '', true ), $hour ];
			$reads[] = [ Stats_Store::url_rank_parts( 'count', 'desc', self::SEED_SERVER, true ), $hour ];
		}
		$seeded = $store->bucket_get_multi( $reads );

		foreach ( \range( 1, 6 ) as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNotContains( null, $seeded );
		$this->assertSame( $seeded, $store->bucket_get_multi( $reads ) );
	}

	/**
	 * A server past the index cap files its rows under `Other` and its
	 * charts' hour keys under its own name. The flush writes those keys
	 * through, so the open bucket is held ranked: ranking it would seed a
	 * list set for every one of the index's servers, which charts nothing.
	 */
	public function test_a_server_past_the_index_cap_gets_its_chart_hour_keys(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$fb->set_is_hub( true );
		$hour   = \gmdate( 'Y-m-d-H', $start );
		$bucket = Stats_Store::bucket_key( (int) Core::$now );
		$index  = self::index_of( \array_map( static fn ( int $i ): string => "spray{$i}.test", \range( 0, Stats_Store::MAX_SERVER_VALUES - 1 ) ) );
		$store->bucket_set_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket, $index ] ] );
		( new \ReflectionProperty( $fb, 'ranked_at' ) )->setValue( $fb, [ $bucket => (int) Core::$now ] );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://late-4471.test/kokako', 'server_name' => 'late-4471.test', 'timestamp' => (int) Core::$now, 'user_agent' => 'kea/7' ] ) );
		$fb->fire_cb();

		$this->assertSame( 1, self::hour_count( $store->get_slots( Stats_Store::dim_parts( 'ua', 'late-4471.test' ), [ $hour ] ), 'kea/7' ) );
	}

	/** A URL first seen in a late record of a closed hour gets that hour's URL chart keys. */
	public function test_a_late_first_seen_url_gets_its_url_hour_keys(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$hour      = \gmdate( 'Y-m-d-H', $start - 3600 );
		$late      = Log_Manager::url_hash( '/takahe-7731' );
		Core::$now = $start - 3600 + 100;
		$this->fill_request( $fb, $this->completed_request( [ 'timestamp' => (int) Core::$now ] ) );
		$fb->fire_cb();
		Core::$now = $start + 30;
		$fb->fire_cb();

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/takahe-7731', 'timestamp' => $start - 3600 + 2900, 'user_agent' => 'weka/3' ] ) );
		$fb->fire_cb();

		$this->assertSame( 1, self::hour_count( $store->get_slots( Stats_Store::url_dim_parts( $late ), [ $hour ], 'ua' ), 'weka/3' ) );
	}

	/** A late record into a folded hour reaches that hour's chart keys as well as its bucket. */
	public function test_a_late_record_reaches_a_folded_hours_chart_keys(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$hour      = \gmdate( 'Y-m-d-H', $start - 3600 );
		Core::$now = $start - 3600 + 100;
		$this->fill_request( $fb, $this->completed_request( [ 'timestamp' => (int) Core::$now, 'country_code' => 'NZ' ] ) );
		$fb->fire_cb();
		Core::$now = $start + 30;
		$fb->fire_cb();

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->fill_request( $fb, $this->completed_request( [ 'timestamp' => $start - 3600 + 2900, 'country_code' => 'NZ' ] ) );
		$fb->fire_cb();

		$this->assertSame( 2, self::hour_count( $store->get_slots( Stats_Store::dim_parts( 'country', '' ), [ $hour ] ), 'NZ' ) );
		$this->assertSame( 2, self::hour_count( $store->get_slots( Stats_Store::hourly_parts(), [ $hour ] ), 'count' ) );
	}

	/**
	 * An older hour missing one shard reads as unfolded, but its fine tier
	 * has expired: the fold writes nothing, and the shards and index it
	 * still holds stand.
	 */
	public function test_an_hour_whose_fine_tier_expired_is_never_folded_empty(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$hour = \gmdate( 'Y-m-d-H', $start - 5 * 3600 );
		$kept = [ 'b8823bc1d2ef' => [ 'url' => 'https://' . self::SEED_SERVER . '/numbat-4471', 'count' => 5519 ] ];
		$lost = [ 'c9934cd2e3f0' => [ 'url' => 'https://' . self::SEED_SERVER . '/quokka-8842', 'count' => 3319 ] ];
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'b8823bc1d2ef' ), $kept );
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'c9934cd2e3f0' ), $lost );
		$store->bucket_forget_multi( [ [ Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), Stats_Store::url_shard( 'c9934cd2e3f0' ) ), $hour ] ] );
		$reads  = [
			[ Stats_Store::url_srv_parts( true ), $hour ],
			[ Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), Stats_Store::url_shard( 'b8823bc1d2ef' ) ), $hour ],
		];
		$seeded = $store->bucket_get_multi( $reads );
		$this->assertSame( [ self::SEED_SERVER ], $store->url_hours_derived( [ $hour ] )[ $hour ], 'no marker, so the stale ranking reads its shards' );

		foreach ( \range( 1, 3 ) as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNotContains( null, $seeded );
		$this->assertSame( $seeded, $store->bucket_get_multi( $reads ) );
	}

	/**
	 * A builder whose roll-up read goes unanswered flushes every tick, since
	 * the hours stay unfolded; with nothing pending it is idle all the same.
	 */
	public function test_an_unanswered_roll_up_leaves_an_idle_builder_idle(): void {
		$store = $this->unanswered_store( '/^urlsrv_h:/' );
		$this->fail_reads( $store, true );
		$this->set_rule();
		Core::$now = 1790000233;
		$fb        = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->sink( new Capture_Sink_Node() );

		foreach ( [ 1790000238, 1790000243 ] as $tick ) {
			Core::$now = $tick;
			$fb->fire_cb();
		}

		$this->assertSame( 1790000233.0, $fb->idle_since() );
	}

	/**
	 * A backlog replayed into an hour this builder never saw open: its site
	 * keys were never written, and the replay's own flush writes them.
	 */
	public function test_a_replay_into_an_hour_its_site_keys_never_held_reaches_them(): void {
		[ $fb, $store, $start ] = $this->hour_builder();
		$hour = \gmdate( 'Y-m-d-H', $start - 3600 );
		foreach ( [ 700, 1900, 3100 ] as $at ) {
			$this->fill_request( $fb, $this->completed_request( [ 'timestamp' => $start - 3600 + $at, 'country_code' => 'TK' ] ) );
		}
		$fb->fire_cb();

		$this->assertSame( 3, self::hour_count( $store->get_slots( Stats_Store::hourly_parts(), [ $hour ] ), 'count' ) );
		$this->assertSame( 3, self::hour_count( $store->get_slots( Stats_Store::dim_parts( 'country', '' ), [ $hour ] ), 'TK' ) );
	}

	/** The wall clock every data-clock test replays behind: 15:07 on 2026-09-23. */
	private const REPLAY_WALL_H = 15;

	/** The replayed hour, two hours behind that wall. */
	private const REPLAY_HOUR = '2026-09-23-13';

	/**
	 * A store that files every write it is asked for as `{ns} {key}`, one
	 * list per flush, so a test counts the flushes that wrote a key.
	 *
	 * @return Stats_Store&object{flushes: list<list<string>>}
	 */
	private function write_recorder( int $max_lifespan ): Stats_Store {
		return new class( ...$this->stats_store_args( 0, $max_lifespan ) ) extends Stats_Store {
			/** @var list<list<string>> */
			public array $flushes = [ [] ];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $key ] ) {
					$this->flushes[ \array_key_last( $this->flushes ) ][] = "{$parts[0]} {$key}";
				}
				return parent::bucket_set_multi( $writes );
			}
		};
	}

	/**
	 * A builder over `write_recorder()`, constructed on the replay wall.
	 *
	 * @param int $max_lifespan The store's window; a day unless a test narrows it.
	 * @return array{0: Flame_Builder_Node, 1: Stats_Store&object{flushes: list<list<string>>}}
	 */
	private function replay_builder( int $max_lifespan = 86400 ): array {
		$this->set_rule();
		Core::$now = \gmmktime( self::REPLAY_WALL_H, 7, 0, 9, 23, 2026 );
		$store     = $this->write_recorder( $max_lifespan );
		$fb        = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->sink( new Capture_Sink_Node() );
		return [ $fb, $store ];
	}

	/**
	 * One flush of a replay: a record of `/replayed-6127` completing at each
	 * `H:MM` of the day, then `flush()` five seconds on.
	 *
	 * @param list<string> $times `H:MM` completion times.
	 */
	private function replay_flush( Flame_Builder_Node $fb, Stats_Store $store, array $times ): void {
		foreach ( $times as $time ) {
			[ $h, $m ] = \array_map( 'intval', \explode( ':', $time ) );
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/replayed-6127',
				'duration_ms' => 37.0,
				'timestamp'   => \gmmktime( $h, $m, 11, 9, 23, 2026 ),
			] ) );
		}
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->flush();
		$store->flushes[] = [];
	}

	/**
	 * The flushes, by position, that wrote a `$ns` key of `$key`.
	 *
	 * @return list<int>
	 */
	private static function flushes_writing( Stats_Store $store, string $ns, string $key ): array {
		$out = [];
		foreach ( $store->flushes as $at => $writes ) {
			foreach ( $writes as $write ) {
				if ( \str_starts_with( $write, "{$ns} " ) && \str_ends_with( $write, " {$key}" ) ) {
					$out[] = $at;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * A replay files an hour two hours behind the wall across four flushes.
	 * The hour waits for the data clock: its buckets take the fine writes
	 * alone, and it folds and ranks once, on the flush after the first write
	 * past it, holding every replayed request.
	 */
	public function test_a_replayed_hour_folds_and_ranks_once_after_the_data_clock_leaves_it(): void {
		[ $fb, $store ] = $this->replay_builder();
		foreach ( [ [ '13:05' ], [ '13:21' ], [ '13:42', '13:44' ], [ '13:57' ] ] as $times ) {
			$this->replay_flush( $fb, $store, $times );
		}
		$this->replay_flush( $fb, $store, [ '14:06' ] );

		$this->assertSame( [], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'no hour key while its data still arrives' );
		$this->assertSame( [], self::flushes_writing( $store, Stats_Store::NS_URLRANK_HOUR_S, self::REPLAY_HOUR ), 'no hour lists either' );

		$this->replay_flush( $fb, $store, [ '14:13' ] );
		$this->replay_flush( $fb, $store, [ '14:24' ] );

		$this->assertSame( [ 5 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'one fold' );
		$this->assertSame( [ 5 ], self::flushes_writing( $store, Stats_Store::NS_URLRANK_HOUR_S, self::REPLAY_HOUR ), 'one rank' );
		$hash = Log_Manager::url_hash( '/replayed-6127' );
		$rows = self::named_url_rows( $this->get_url_hour( $store, self::REPLAY_HOUR, Stats_Store::url_shard( $hash ) ) );
		$this->assertSame( 5, $rows[ $hash ]['count'] ?? 0, 'the fold holds every replayed request' );
	}

	/**
	 * A record into an hour the data clock already passed and folded is a
	 * straggler, and takes the late-write path: one hour-key write and one
	 * re-rank, in its own flush, and none after it.
	 */
	/**
	 * A reprocess of 2026-09-29 09:00-12:00 run the next morning. The one
	 * request that never completes times out on the STREAM, three windows
	 * after it landed, measured to the stream and filed in its own 09-29
	 * bucket; the flame builder then folds and ranks each replayed hour
	 * once, with no late write re-ranking one. On the wall it timed out
	 * twenty hours long, filed in the wall's bucket, and that one record
	 * carried the data clock past every replayed hour.
	 */
	public function test_a_reprocess_times_out_on_the_stream_and_folds_each_hour_once(): void {
		$this->set_rule();
		( new \Newspack_Nodes\Router_Node() )->name( \Newspack_Nodes\Node_Names::ROUTER );
		Core::$now = \gmmktime( 5, 19, 0, 9, 30, 2026 );
		$store     = $this->write_recorder( 86400 );
		$fb        = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->sink( new Capture_Sink_Node() );
		$rb = new \Newspack_Event_Logger_Nodes\Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( [ '500', '3' ] );
		$records = new Capture_Sink_Node();
		$rb->sink( $records );

		$line = static function ( int $n, string $rid, string $k, float $ts, array $extra = [] ) use ( $rb ): void {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = Message::TM_STRUCT;
			$message[ Message::KEY ]   = $rid;
			$message[ Message::VALUE ] = [ 'n' => $n, 'rid' => $rid, 'k' => $k, 'ts' => $ts ] + $extra;
			$rb->fill( $message );
		};
		$drained = 0;
		$drain   = function () use ( $records, $fb, $store, &$drained ): void {
			foreach ( \array_slice( $records->captured, $drained ) as $record ) {
				$this->fill_request( $fb, (array) $record[ Message::VALUE ] );
			}
			$drained = \count( $records->captured );
			$fb->flush();
			$store->flushes[] = [];
		};

		$stall   = \gmmktime( 9, 14, 7, 9, 29, 2026 );
		$stalled = false;
		$end     = \gmmktime( 12, 0, 0, 9, 29, 2026 );
		for ( $at = \gmmktime( 9, 0, 0, 9, 29, 2026 ), $i = 1; $at < $end; $at += 37, $i++ ) {
			if ( ! $stalled && $at >= $stall ) {
				$line( 1, 'r-stall-5521', 'process (start)', $stall );
				$line( 2, 'r-stall-5521', 'request', $stall, [ 'm' => 'GET /stalled-5521' ] );
				$stalled = true;
			}
			$line( 1, "r-replay-{$i}", 'process (start)', $at );
			$line( 2, "r-replay-{$i}", 'request', $at + 0.01, [ 'm' => 'GET /replayed-7309' ] );
			$line( 3, "r-replay-{$i}", 'process (complete)', $at + 0.2, [ 'duration_ms' => 200.0, 'status_code' => 200 ] );
			$rb->fire_cb();
			if ( 0 === $i % 12 ) {
				$drain();
				Core::$now += 50;
			}
		}
		$drain();
		$replayed = \count( $store->flushes );

		$timed_out = \array_values( \array_filter(
			\array_map( static fn ( array $record ): array => (array) $record[ Message::VALUE ], $records->captured ),
			static fn ( array $record ): bool => 'r-stall-5521' === $record['rid']
		) );
		$this->assertCount( 1, $timed_out, 'the stalled request times out once' );
		$this->assertSame( 'T', $timed_out[0]['error_status'] );
		$this->assertSame( 953_000, $timed_out[0]['duration_ms'], 'to 09:30:00, the third boundary after it landed' );
		$this->assertSame( '2026-09-29-09-30', Stats_Store::bucket_key( $stall + 953 ), 'where it completes' );
		foreach ( $store->flushes as $at => $writes ) {
			$this->assertSame( [], \array_values( \array_filter( $writes, static fn ( string $write ): bool => \str_contains( $write, '2026-09-30' ) ) ), "flush {$at} filed nothing in the wall's day" );
		}

		// Quiet, the builder takes the wall and folds the last replayed hour.
		Core::$now += Quiet::AFTER_SEC;
		for ( $flush = 0; $flush < 15; $flush++ ) {
			$fb->flush();
			$store->flushes[] = [];
		}

		foreach ( [ '2026-09-29-09', '2026-09-29-10' ] as $hour ) {
			$this->assertCount( 1, self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, $hour ), "{$hour} folds once" );
			$this->assertCount( 1, self::flushes_writing( $store, Stats_Store::NS_URLRANK_HOUR_S, $hour ), "{$hour} ranks once" );
			$this->assertLessThan( $replayed, self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, $hour )[0], "{$hour} folds as the stream leaves it" );
		}
		$this->assertCount( 1, self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, '2026-09-29-11' ), 'the last hour folds once, on the quiet' );
		$this->assertCount( 1, self::flushes_writing( $store, Stats_Store::NS_URLRANK_HOUR_S, '2026-09-29-11' ), 'and ranks once' );
	}

	/**
	 * A replayed request's categories and tree age on its own completion
	 * time, as they did live: stamped by the wall, a record a day old saw
	 * every category it carried expire on arrival, and a replay kept every
	 * tree node of every replayed hour, all stamped with the one wall hour.
	 */
	public function test_a_replayed_request_ages_its_categories_and_tree_on_its_own_time(): void {
		$this->set_rule();
		Core::$now = \gmmktime( 5, 19, 0, 9, 30, 2026 );
		$fb        = new Flame_Builder_Node();
		$store     = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$done = \gmmktime( 10, 41, 5, 9, 29, 2026 );

		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/replayed-3391',
			'duration_ms' => 250.0,
			'timestamp'   => $done,
			'flame'       => [ 'name' => 'root', 'value' => 250.0, 'children' => [ [ 'name' => 'gull hook', 'value' => 120.0, 'children' => [] ] ] ],
			'profiles'    => [ 'gull hook' => [ 'time' => 0.12, 'count' => 7, 'ts' => $done, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$stats = $store->url_aggregate( Log_Manager::url_hash( '/replayed-3391' ) );
		$this->assertArrayHasKey( 'gull hook', Core::arr( $stats['profiles']['categories'] ?? null ), 'a day-old category survives its own arrival' );
		$this->assertSame( $done, $stats['flame_raw']['children'][0]['ts'] ?? null, 'the tree is stamped with its completion' );
	}

	public function test_a_straggler_into_an_hour_the_data_clock_passed_takes_the_late_path_once(): void {
		[ $fb, $store ] = $this->replay_builder();
		$this->replay_flush( $fb, $store, [ '13:18' ] );
		$this->replay_flush( $fb, $store, [ '14:02' ] );
		$this->replay_flush( $fb, $store, [ '14:09' ] );
		$this->assertSame( [ 2 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'the fixture folds the hour' );

		$this->replay_flush( $fb, $store, [ '13:46', '14:14' ] );
		$this->replay_flush( $fb, $store, [ '14:19' ] );

		$this->assertSame( [ 2, 3 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'the late write, once' );
		$this->assertSame( [ 2, 3 ], self::flushes_writing( $store, Stats_Store::NS_URLRANK_HOUR_S, self::REPLAY_HOUR ), 'its re-rank, once' );
		$hash = Log_Manager::url_hash( '/replayed-6127' );
		$rows = self::named_url_rows( $this->get_url_hour( $store, self::REPLAY_HOUR, Stats_Store::url_shard( $hash ) ) );
		$this->assertSame( 2, $rows[ $hash ]['count'] ?? 0 );
	}

	/**
	 * A builder that has drained nothing for `Quiet::AFTER_SEC` is idle, and
	 * its clock is the wall: an hour the wall closed folds with no record
	 * past it. A second short of that, it is not idle yet.
	 */
	public function test_a_quiet_builder_folds_a_hour_the_wall_closed(): void {
		[ $fb, $store ] = $this->replay_builder();
		$hash  = Log_Manager::url_hash( '/quiet-5903' );
		$shard = Stats_Store::url_shard( $hash );
		$this->seed_url_shard( $store, self::REPLAY_HOUR . '-35', $shard, [
			$hash => [ 'url' => '/quiet-5903', 'count' => 41, 'timed_count' => 41, 'sum_ms' => 902.0 ],
		] );
		$built = (int) Core::$now;
		$quiet = Quiet::AFTER_SEC;

		Core::$now = $built + $quiet - 1;
		$fb->flush();
		$this->assertSame( [], $this->get_url_hour( $store, self::REPLAY_HOUR, $shard ), 'a second short of quiet' );

		Core::$now = $built + $quiet;
		$fb->flush();
		$rows = self::named_url_rows( $this->get_url_hour( $store, self::REPLAY_HOUR, $shard ) );
		$this->assertSame( 41, $rows[ $hash ]['count'] ?? 0, 'quiet long enough, the wall folds it' );
	}

	/**
	 * A restart mid-replay crawls: each record is checkpointed as it lands,
	 * so the builder holds nothing pending at every tick. That is not
	 * idleness, and no hour folds ahead of the data clock.
	 */
	public function test_a_crawl_that_checkpoints_every_record_folds_nothing_early(): void {
		[ $fb, $store ] = $this->replay_builder();
		$fb->name( 'fb' );
		foreach ( [ 8, 23, 39 ] as $minute ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/crawled-2281',
				'duration_ms' => 53.0,
				'timestamp'   => \gmmktime( 13, $minute, 4, 9, 23, 2026 ),
			] ) );
			$fb->save_state();
			Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
			$fb->fire_cb();
		}

		$this->assertSame( [], self::flushes_writing( $store, Stats_Store::NS_URLSRV_HOUR, self::REPLAY_HOUR ), 'nothing folded the crawled hour' );
		$this->assertSame( [], self::flushes_writing( $store, Stats_Store::NS_URLSRV_HOUR, '2026-09-23-14' ), 'nor the hour after it' );
	}

	/**
	 * A replay slower than the fine tier's lifetime would lose its hour's
	 * first buckets before the data clock left it, so an hour the clock has
	 * stood in for all but a bucket of that lifetime folds regardless.
	 */
	public function test_an_hour_the_data_clock_holds_past_the_fine_lifetime_folds(): void {
		[ $fb, $store ] = $this->replay_builder();
		$this->replay_flush( $fb, $store, [ '13:03' ] );
		$deadline = (int) Core::$now + Stats_Store::FINE_TTL_SECONDS - Stats_Store::BUCKET_SECONDS;

		Core::$now = $deadline - 1 - Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->replay_flush( $fb, $store, [ '13:04' ] );
		$this->assertSame( [], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'a second inside the lifetime' );

		Core::$now = $deadline - Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->replay_flush( $fb, $store, [ '13:06' ] );
		$this->assertSame( [ 2 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'at the deadline it folds' );
	}

	/**
	 * Quiet is a duration, measured on the monotonic clock: a wall clock
	 * stepped an hour ahead is no quiet, and a minute of monotonic quiet is,
	 * whatever the wall says.
	 */
	public function test_idleness_is_measured_on_the_monotonic_clock_not_the_wall(): void {
		$mono = 7_300_000_000_000;
		Quiet::$hrtime_fn = static function () use ( &$mono ): int {
			return $mono;
		};
		[ $fb, $store ] = $this->replay_builder();
		$hash  = Log_Manager::url_hash( '/steady-6604' );
		$shard = Stats_Store::url_shard( $hash );
		// The newest hour the stepped wall has closed, which folds first.
		$closed = '2026-09-23-15';
		$this->seed_url_shard( $store, $closed . '-25', $shard, [
			$hash => [ 'url' => '/steady-6604', 'count' => 29, 'timed_count' => 29, 'sum_ms' => 377.0 ],
		] );
		$quiet = Quiet::AFTER_SEC;

		Core::$now += Stats_Store::HOUR_SECONDS;
		$mono      += 7 * 1_000_000_000;
		$fb->flush();
		$this->assertSame( [], $this->get_url_hour( $store, $closed, $shard ), 'the wall stepped an hour; seven seconds passed' );

		$mono += $quiet * 1_000_000_000;
		$fb->flush();
		$rows = self::named_url_rows( $this->get_url_hour( $store, $closed, $shard ) );
		$this->assertSame( 29, $rows[ $hash ]['count'] ?? 0, 'a monotonic minute of quiet folds it, the wall standing still' );
	}

	/**
	 * A builder whose traffic stops at the hour's close folds that hour
	 * inside the next hour's first bucket, the window `lagging()` covers:
	 * its last records drain four seconds into 14:00, and 13 folds by 14:05.
	 */
	public function test_a_builder_quiet_from_the_close_folds_the_hour_inside_the_next_first_bucket(): void {
		$this->set_rule();
		Core::$now = \gmmktime( 13, 56, 0, 9, 23, 2026 );
		$store     = $this->write_recorder( 86400 );
		$fb        = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->sink( new Capture_Sink_Node() );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/closing-4418',
			'duration_ms' => 43.0,
			'timestamp'   => \gmmktime( 13, 59, 57, 9, 23, 2026 ),
		] ) );
		Core::$now = \gmmktime( 14, 0, 4, 9, 23, 2026 );
		$fb->flush();

		$first_bucket_ends = \gmmktime( 14, 5, 0, 9, 23, 2026 );
		while ( Core::$now + Flame_Builder_Node::FLUSH_INTERVAL_SEC < $first_bucket_ends ) {
			Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
			$fb->fire_cb();
		}

		$this->assertNotSame( [], self::flushes_writing( $store, Stats_Store::NS_URLSRV_HOUR, '2026-09-23-13' ), 'folded inside 14:00-14:05' );
	}

	/**
	 * The data clock and the moment it entered its hour ride the checkpoint,
	 * so a successor restoring them still folds that hour at the fine
	 * deadline its predecessor started, and not a second before.
	 */
	public function test_a_restored_builder_folds_the_clocks_hour_at_its_predecessors_deadline(): void {
		[ $fb, $store ] = $this->replay_builder();
		$this->replay_flush( $fb, $store, [ '13:03' ] );
		$deadline = (int) Core::$now + Stats_Store::FINE_TTL_SECONDS - Stats_Store::BUCKET_SECONDS;
		$saved    = $fb->save_state();

		Core::$now = $deadline - 1 - Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$next      = new Flame_Builder_Node();
		$next->set_stats_store( $store );
		$next->sink( new Capture_Sink_Node() );
		$next->restore_state( $saved );
		$this->replay_flush( $next, $store, [ '13:08' ] );
		$this->assertSame( [], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'a second inside the lifetime' );

		Core::$now = $deadline - Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->replay_flush( $next, $store, [ '13:09' ] );
		$this->assertSame( [ 2 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ), 'the successor folds at the deadline' );
	}

	/**
	 * One partition has one writer, so an hour the roll-up found holding no
	 * index stays that way until this process folds it: a replay reads a
	 * waiting hour's index once, not once a flush.
	 */
	public function test_a_waiting_hour_is_probed_once_across_a_replay(): void {
		[ $fb, $store ] = $this->replay_builder( 3 * Stats_Store::HOUR_SECONDS );
		$this->forget_stats_asks();
		foreach ( [ '13:11', '13:26', '13:38', '13:52' ] as $time ) {
			$this->replay_flush( $fb, $store, [ $time ] );
		}

		$probes = \array_filter(
			VerbHarness::ask_recorder()->asked,
			static fn ( array $ask ): bool => \is_string( $ask['value'] ) && \str_starts_with( $ask['value'], 'MGET ' )
				&& \str_contains( $ask['value'], Stats_Store::NS_URLSRV_HOUR . ':2026-09-23-14' )
		);
		$this->assertCount( 1, $probes, 'hour 14 waits on the clock across four flushes' );
	}

	/**
	 * A reader-shard write into a bucket behind the fine floor, which never
	 * ranks, collects nothing and joins no ranking group; into a folded hour
	 * it still forgets its server's marker.
	 */
	public function test_a_shard_write_behind_the_fine_floor_collects_nothing(): void {
		$fb    = new Flame_Builder_Node();
		$for   = new \ReflectionMethod( $fb, 'url_shard_intent' );
		$shard = Stats_Store::url_shard( 'a3b5c7d9e1f2' );
		$rows  = [ 'a3b5c7d9e1f2' => self::positional_url_row( [ 'count' => 19 ] ) ];

		$behind = $for->invoke( $fb, '2026-09-21-11-15', 'kea.test', $shard, $rows, false );
		$this->assertSame( [ null, null ], [ $behind[0]['landed'], $behind[0]['group'] ], 'behind the floor: nothing collected' );

		$ranked = $for->invoke( $fb, '2026-09-21-11-15', 'kea.test', $shard, $rows, true );
		$this->assertNotNull( $ranked[0]['landed'], 'inside the fine tail it collects' );

		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ '2026-09-21-11' => true ] );
		$folded = $for->invoke( $fb, '2026-09-21-11-15', 'kea.test', $shard, $rows, false );
		$this->assertNotNull( $folded[1]['landed'], 'a folded hour still re-ranks' );
	}

	/**
	 * A data clock that jumps two hours in one flush leaves both behind it,
	 * and the fold budget folds both in the flush after.
	 */
	public function test_a_clock_jumping_two_hours_folds_both_in_one_flush(): void {
		[ $fb, $store ] = $this->replay_builder();
		$this->replay_flush( $fb, $store, [ '13:14', '14:29', '15:01' ] );
		$this->replay_flush( $fb, $store, [ '15:03' ] );

		$this->assertSame( [ 1 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, self::REPLAY_HOUR ) );
		$this->assertSame( [ 1 ], self::flushes_writing( $store, Stats_Store::NS_URLS_HOUR, '2026-09-23-14' ) );
	}

	/**
	 * Once a replay stops short of the wall, its last hour waits on the data
	 * clock, and a tick with nothing pending owes the store nothing for it.
	 * The window holds hours 13 and 14 alone, so no older hour owes a fold.
	 */
	public function test_a_tick_behind_a_waiting_hour_touches_no_store(): void {
		[ $fb, $store ] = $this->replay_builder( 3 * Stats_Store::HOUR_SECONDS );
		$fb->name( 'fb' );
		$this->replay_flush( $fb, $store, [ '13:27' ] );
		$this->replay_flush( $fb, $store, [ '14:31' ] );
		$this->replay_flush( $fb, $store, [ '14:33' ] );
		$this->forget_stats_asks();

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();

		$this->assertSame( [], VerbHarness::ask_recorder()->asked, 'hour 14 waits on the clock; the tick owes nothing' );
	}

	/** Seed one bucket's URL rows into the node's pending state and persist them. */
	private function flush_pending( Flame_Builder_Node $fb, string $bucket, array $url_stats ): void {
		$this->flush_buckets( $fb, [ $bucket => [ 'url_stats' => $url_stats ] ] );
	}

	/**
	 * Drive one `persist_aggregate_stats()` over hand-built accumulators. URL
	 * rows are given by hash and filed under `SEED_SERVER`, as a request
	 * carrying the default `server_name` files them.
	 *
	 * @param array<string,array<string,mixed>> $buckets bucket => accumulator overrides.
	 */
	private function flush_buckets( Flame_Builder_Node $fb, array $buckets ): void {
		$pending = [];
		foreach ( $buckets as $bucket => $overrides ) {
			foreach ( [ 'url_stats', 'url_stats_worker' ] as $slot ) {
				if ( isset( $overrides[ $slot ] ) ) {
					$overrides[ $slot ] = [ self::SEED_SERVER => $overrides[ $slot ] ];
				}
			}
			$pending[ $bucket ] = \array_replace(
				( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
				$overrides
			);
		}
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, $pending );
		$store = self::store_of( $fb );
		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );
	}

	/** Run `roll_up_hours()` as an idle builder's flush at `$now` would: every hour ripe. */
	private static function roll_up( Flame_Builder_Node $fb, int $now ): void {
		$store = self::store_of( $fb );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), $now ) );
		$fb->roll_up_hours( $store, $plan, $plan['hours'] );
	}

	/** The oldest bucket of the read plan's fine tail at `$now`. */
	private static function fine_floor( Stats_Store $store, int $now ): string {
		return (string) \end( Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), $now ) )['fine'] );
	}

	/** The store a builder was wired with. */
	private static function store_of( Flame_Builder_Node $fb ): Stats_Store {
		$store = ( new \ReflectionProperty( $fb, 'stats_store' ) )->getValue( $fb );
		self::assertInstanceOf( Stats_Store::class, $store );
		return $store;
	}

	public function test_a_bucket_inside_a_folded_hour_merges_into_the_coarse_key(): void {
		// A replay files a record under its ORIGINAL timestamp. The reader
		// takes the hour key and never re-reads a folded hour's fine buckets,
		// and the hour folds exactly once — so a fine write landing after the
		// fold is stored where nothing will ever read it. Seeds distinct from
		// every default: 7 requests, 210ms, against a folded hour of 3.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash  = Log_Manager::url_hash( '/replayed-8823' );
		$shard = Stats_Store::url_shard( $hash );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/replayed-8823', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0, 'last_seen' => $now - 7200 ],
		] );
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		// Now a replayed record for a DIFFERENT bucket of that same closed hour.
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/replayed-8823', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0, 'last_seen' => $now - 5400 ] ),
		] );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame(
			10,
			$hour[ $hash ]['count'],
			'a write into a folded hour must reach the key the reader actually reads'
		);
		$this->assertSame( 243.0, (float) $hour[ $hash ]['sum_ms'] );
	}

	/**
	 * A store that records every hour-tier ranking it is asked to write.
	 *
	 * @return Stats_Store&object{hour_ranks: list<string>, bucket_ranks: list<string>}
	 */
	private function hour_rank_recorder(): Stats_Store {
		return new class( ...$this->stats_store_args( 0, 86400 ) ) extends Stats_Store {
			/** @var list<string> */
			public array $hour_ranks = [];
			/** @var list<string> */
			public array $bucket_ranks = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $key ] ) {
					if ( self::NS_URLRANK_HOUR_S === $parts[0] ) {
						$this->hour_ranks[] = $key;
					} elseif ( self::NS_URLRANK_S === $parts[0] ) {
						$this->bucket_ranks[] = $key;
					}
				}
				return parent::bucket_set_multi( $writes );
			}
		};
	}

	/**
	 * Fold one hour holding `$rows` in bucket `-05` of shard `$shard`, as of
	 * 15:07 on 2026-08-27, and return the builder that memoized it.
	 *
	 * @param Stats_Store            $store Destination.
	 * @param string                 $shard Shard the rows sit in.
	 * @param array<array-key,mixed> $rows  Named rows by hash.
	 */
	private function folded_builder( Stats_Store $store, string $shard, array $rows ): Flame_Builder_Node {
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, $rows );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );
		$this->assertSame( [ '2026-08-27-13' => true ], \array_intersect_key(
			( new \ReflectionProperty( $fb, 'folded_hours' ) )->getValue( $fb ),
			[ '2026-08-27-13' => true ]
		), 'the fixture folds the hour' );
		return $fb;
	}

	public function test_a_late_write_into_a_folded_hour_holding_its_shard_lands_in_both_tiers(): void {
		$store = $this->hour_rank_recorder();
		$hash  = Log_Manager::url_hash( '/present-hour-4419' );
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/present-hour-4419', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0 ],
		] );
		$store->hour_ranks = [];

		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/present-hour-4419', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0 ] ),
		] );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 10, $hour[ $hash ]['count'] ?? null, 'merged into the hour key' );
		$fine = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-13-40', $shard ) );
		$this->assertSame( 7, $fine[ $hash ]['count'] ?? null, 'and into the fine bucket the hour derives from' );
		$this->assertNotContains( '2026-08-27-13-40', $store->bucket_ranks, 'which no one ranks' );
		$this->assertContains( '2026-08-27-13', $store->hour_ranks, 'the hour re-ranks at once' );
		$site = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( 10, $site[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null, 'its site list counts the late rows' );
	}

	public function test_a_late_write_from_a_new_server_names_it_in_the_folded_hour_and_files_its_hour_keys(): void {
		// The server index is what every hour reader enumerates, so a server
		// the folded hour never named has to join it, or its late rows stay
		// in fine buckets nothing reads. Its hour keys do not exist yet, and
		// the late rows are theirs, so the write files them; the next roll-up
		// re-folds the hour to the same rows.
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hash  = 'c5c5c5c5c5c5';
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/folded-5511', 'count' => 3 ],
		] );
		$late = 'c9c9c9c9c9c9';
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ 'late-7731.test' => [ $late => self::positional_url_row( [ 'count' => 13, 'path' => '/late-7731' ] ) ] ] ]
		) ] );
		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );

		$this->assertContains( 'late-7731.test', Stats_Store::index_names( $store->server_index( [ '2026-08-27-13' ], [] )['2026-08-27-13'] ) );
		$this->assertSame( 13, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', Stats_Store::url_shard( $late ), 'late-7731.test' ) )[ $late ]['count'] ?? null, 'holding the late rows' );

		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 13, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard, 'late-7731.test' ) )[ $late ]['count'] ?? null );
		$this->assertSame( 3, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard ) )[ $hash ]['count'] ?? null );
	}

	public function test_a_late_write_from_a_new_server_is_ranked_in_the_same_flush(): void {
		// The late write names a server the folded hour's index lacked and
		// files its hour keys, which unranks the hour, so the flush that
		// writes it ranks the server's lists and the site's alike.
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hash  = 'c5c5c5c5c5c5';
		$fb    = $this->folded_builder( $store, Stats_Store::url_shard( $hash ), [
			$hash => [ 'url' => '/folded-5511', 'count' => 3 ],
		] );
		$late = 'c9c9c9c9c9c9';
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ 'late-7731.test' => [ $late => self::positional_url_row( [ 'count' => 13, 'path' => '/late-7731' ] ) ] ] ]
		) ] );
		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );

		$late_list = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', 'late-7731.test' );
		$this->assertSame( 13, $late_list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null, 'its hour lists exist' );
		$site = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( [ $late, $hash ], \array_column( $site[0][1] ?? [], Stats_Store::RANK_HASH ), 'the site hour answers ranked' );
	}

	public function test_a_spent_fold_budget_still_memoizes_an_older_folded_hour(): void {
		// Every hour newer than 10:00 is unfolded and the budget folds two of
		// them. Hour 10 is folded and ranked already; unless this flush
		// memoizes it, its late rows reach only a fine bucket nothing reads.
		// Seeds distinct from every default: 5 folded requests, 9 late.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash  = 'e8e8e8e8e8e8';
		$shard = Stats_Store::url_shard( $hash );
		$older = '2026-08-27-10';
		foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $one ) {
			$this->seed_url_hour( $store, $older, $one, $one === $shard ? [
				$hash => [ 'url' => '/older-hour-5162', 'count' => 5 ],
			] : [] );
		}
		$store->bucket_set_multi( [
			[ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $older, [] ],
		] );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );
		$this->assertArrayHasKey( $older, ( new \ReflectionProperty( $fb, 'folded_hours' ) )->getValue( $fb ), 'the probed hour is memoized' );

		$this->flush_pending( $fb, $older . '-20', [
			$hash => self::positional_url_row( [ 'url' => '/older-hour-5162', 'count' => 9 ] ),
		] );

		$hour = self::named_url_rows( $store->url_hour_sources( [ $older ], $shard )[0][1] ?? [] );
		$this->assertSame( 14, $hour[ $hash ]['count'] ?? null, 'the late rows merge into the hour key the reader reads' );
	}

	/**
	 * One closed hour's leaderboard category, as a fine bucket or an
	 * accumulator holds it.
	 *
	 * @return array<string,mixed>
	 */
	private static function lb_sums( int $count, float $sum_time ): array {
		return [
			'count'        => $count,
			'sum_req_time' => $count * 2.0,
			'categories'   => [ 'db' => [ 'samples' => $count, 'sum_time' => $sum_time, 'sum_count' => 2 * $count, 'entries' => [] ] ],
		];
	}

	/** The global leaderboard's key for one hour, or null while missing. */
	private static function lb_hour( Stats_Store $store, string $hour ): ?array {
		return $store->bucket_get_multi( [ [ Stats_Store::lb_parts( '' ), $hour ] ] )[0];
	}

	/**
	 * Flush leaderboard records into each bucket of the hour opening at
	 * `$start`, `n` requests into its `n`th, from a tick inside the hour, as a
	 * builder watching the hour open writes them.
	 */
	private function flush_leaderboard_hour( Flame_Builder_Node $fb, int $start ): void {
		$buckets = [];
		foreach ( Stats_Store::buckets_in_hour( \gmdate( 'Y-m-d-H', $start ) ) as $n => $bucket ) {
			$buckets[ $bucket ] = [ 'leaderboard' => self::lb_sums( $n + 1, ( $n + 1 ) * 10.0 ) ];
		}
		Core::$now = $start + 58 * 60;
		$this->flush_buckets( $fb, $buckets );
	}

	public function test_a_closed_hours_leaderboard_is_held_in_one_hour_key(): void {
		// `build_leaderboard()` reads a closed hour's one key, never its twelve
		// buckets, so the key has to sum every bucket the hour's flushes wrote.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->flush_leaderboard_hour( $fb, \gmmktime( 13, 0, 0, 8, 27, 2026 ) );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$hour = $store->get_leaderboard_hours( [ '2026-08-27-13' ] )['2026-08-27-13'] ?? null;
		$this->assertNotNull( $hour, 'the hour has a coarse leaderboard key' );
		$this->assertSame( 78, $hour['count'], 'the hour sums its twelve buckets' );
		$this->assertSame( 780.0, (float) $hour['categories']['db']['sum_time'] );
	}

	public function test_a_late_leaderboard_record_into_a_folded_hour_reaches_its_hour_key(): void {
		// `build_leaderboard()` takes a closed hour's `lb_h` and skips its fine
		// buckets, so a replay landing only in `lb` never shows. Seeds distinct
		// from every default: 78 requests in the hour, 6 late ones.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$this->flush_leaderboard_hour( $fb, \gmmktime( 13, 0, 0, 8, 27, 2026 ) );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->assertSame( 78, self::lb_hour( $store, '2026-08-27-13' )['count'] ?? null, 'the fixture writes the hour' );

		$this->flush_buckets( $fb, [ '2026-08-27-13-40' => [ 'leaderboard' => self::lb_sums( 6, 60.0 ) ] ] );

		$hour = self::lb_hour( $store, '2026-08-27-13' );
		$this->assertSame( 84, $hour['count'] ?? null, 'the late record reaches the hour key the board reads' );
		$this->assertSame( 840.0, (float) ( $hour['categories']['db']['sum_time'] ?? 0 ) );
	}

	public function test_a_url_blob_the_store_could_not_read_is_never_overwritten(): void {
		// Decision 3: a fresh aggregate over an unread blob would replace the
		// stored one with a single flush's requests. The rest still lands.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hash   = Log_Manager::url_hash( '/kereru/4471' );
		$stored = [ 'flame' => [ 'name' => 'aggregate', 'sum_value' => 4471.0, 'count' => 73, 'children' => [] ], 'profiles' => [ 'count' => 73, 'sum_req_time' => 4471.0, 'categories' => [] ] ];
		$this->assertTrue( $this->set_url_stats( $store, $hash, $stored ) );

		$this->refuse_stats_reads( '/^' . Stats_Store::NS_URL . ':/' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/kereru/4471', 'duration_ms' => 29.0, 'timestamp' => self::tick() ] ) );
		$this->refuse_stats_reads( '' );
		$fb->flush();

		$blob = $store->bucket_get_multi( [ [ [ Stats_Store::NS_URL ], $hash ] ] )[0] ?? null;
		$this->assertSame( 73, $blob['flame']['count'] ?? null, 'the stored aggregate stands' );
		$bucket = Stats_Store::bucket_key( self::tick() );
		$this->assertSame( 1, $this->get_hour_slot( $store, Stats_Store::hourly_parts(), $bucket )['count'] ?? null, 'the request is counted all the same' );
		$this->assertSame( 0, $this->stats_count( $fb ), 'nothing is held for the blob' );
	}

	public function test_a_profiles_folded_tail_outlives_the_next_request_that_reloads_it(): void {
		// The stored profile folds its tail into `Other`; a worker that reloads
		// the blob runs the expiry sweep over it, which reads each `ts`.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now      = (int) Core::$now;
		$profiles = [];
		for ( $c = 0; $c < Stats_Store::MAX_LB_CATEGORIES + 37; $c++ ) {
			$profiles[ "event {$c}" ] = [ 'time' => 3.0 + $c, 'count' => 1, 'ts' => $now, 'entries' => [] ];
		}
		$request = [ 'url' => '/tail/7', 'duration_ms' => 900.0, 'timestamp' => $now, 'profiles' => $profiles ];

		$this->fill_request( $fb, $this->completed_request( $request ) );
		$fb->flush();
		// The flush left no aggregate held, so the next resumes from the blob.
		$this->fill_request( $fb, $this->completed_request( [ 'profiles' => [ 'event 0' => $profiles['event 0'] ] ] + $request ) );
		$fb->flush();

		$hash = Log_Manager::url_hash( '/tail/7' );
		$blob = Core::arr( $store->bucket_get_multi( [ [ [ Stats_Store::NS_URL ], $hash ] ] )[0] ?? null );
		$prof = Core::arr( $blob['profiles'] ?? null );
		$cats = Core::arr( $prof['categories'] ?? null );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $cats, 'the folded tail survives the reload' );
		$this->assertEqualsWithDelta(
			(float) $prof['sum_req_time'],
			\array_sum( \array_column( $cats, 'sum_time' ) ),
			1e-6,
			'every millisecond the requests spent is still in a category'
		);
	}

	public function test_a_flushed_leaderboard_keeps_the_slowest_categories_and_folds_the_rest(): void {
		// Every hook, callback and plugin is a category, 1,198 on one hub,
		// and nothing capped the count; 300 here, each distinct by time.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$sums      = [ 'count' => 300, 'sum_req_time' => 600.0, 'categories' => [] ];
		for ( $c = 0; $c < 300; $c++ ) {
			$sums['categories'][ "cat{$c}" ] = [ 'samples' => 1, 'sum_time' => (float) ( 1000 - $c ), 'sum_count' => 1.0, 'entries' => [] ];
		}

		$this->flush_buckets(
			$fb,
			[
				'2026-08-27-15-05' => [
					'leaderboard'           => $sums,
					'leaderboard_by_server' => [ 'alpha.example' => $sums ],
				],
			]
		);

		$total = \array_sum( \array_column( $sums['categories'], 'sum_time' ) );
		foreach ( [ '', 'alpha.example' ] as $server ) {
			$cats = Core::arr( ( $store->get_leaderboard_hours( [ '2026-08-27-15' ], $server )['2026-08-27-15'] ?? [] )['categories'] ?? null );
			$this->assertCount( Stats_Store::MAX_LB_CATEGORIES, $cats, "scope '{$server}'" );
			$this->assertArrayHasKey( 'cat0', $cats, 'the slowest stays' );
			$this->assertArrayNotHasKey( 'cat299', $cats, 'the quickest folds' );
			$this->assertEqualsWithDelta( $total, \array_sum( \array_column( $cats, 'sum_time' ) ), 1e-6, 'into Other, whole' );
		}
	}

	public function test_a_chunk_whose_batch_read_failed_writes_nothing_and_says_so(): void {
		// Decision 3: stats fail soft. A failed read merged onto [] would
		// write the delta alone over the stored bucket, so the chunk's deltas
		// are dropped instead. Seeds: 4 stored requests, 6 in the flush.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		// The index answers, so it is the leaderboard's read-merge that fails.
		$fb    = new Flame_Builder_Node();
		$store = $this->unanswered_store( '/^lb_h:/', $fb );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->set_leaderboard_hour( $store, '2026-08-27-15', self::lb_sums( 4, 40.0 ) );

		$this->fail_reads( $store, true );
		$this->flush_buckets( $fb, [ '2026-08-27-15-05' => [ 'leaderboard' => self::lb_sums( 6, 60.0 ) ] ] );
		$this->fail_reads( $store, false );

		$hour = $this->get_leaderboard_hour( $store, '2026-08-27-15' );
		$this->assertSame( 4, $hour['count'] ?? null, 'the stored hour keeps its value' );
		$this->assertStringContainsString( 'stats flush read failed', $err, 'the dropped chunk is logged' );
	}

	public function test_a_late_write_re_ranks_its_hour_in_the_same_flush(): void {
		// The hour's lists, the site's among them, count the late rows in the
		// flush that writes them. The forgotten marker is what a stop before
		// the ranking leaves the next worker's roll-up. Seeds: 3 folded, 7 late.
		$store = $this->hour_rank_recorder();
		$hash  = Log_Manager::url_hash( '/marker-gone-7714' );
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/marker-gone-7714', 'count' => 3 ],
		] );
		$store->hour_ranks = [];

		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/marker-gone-7714', 'count' => 7 ] ),
		] );

		$this->assertSame( [ '2026-08-27-13' ], \array_values( \array_unique( $store->hour_ranks ) ), 'ranked in this flush' );
		$this->assertSame( 10, self::ranked_count( $store, '2026-08-27-13', true ), 'ranked from its stored rows' );
		$this->assertSame( [], $this->url_rank_done( $store, '2026-08-27-13' ), 'and its marker written back' );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ) );
	}

	public function test_a_server_left_unmarked_re_ranks_its_hour(): void {
		// The marker is per server, so the next worker's roll-up that finds
		// moa.test's hour unmarked while kea.test's is marked re-ranks the
		// hour from its stored rows, every server of it. Seeds: kea 5, moa 8
		// then 19.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var list<string> */
			public array $ranked = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts ] ) {
					// A server's reader list; the site's names none, the marker `done`.
					if ( self::NS_URLRANK_HOUR_S === $parts[0] && 4 === \count( $parts ) && ! \in_array( $parts[1], [ self::WORKER_SHARD_PREFIX, self::ERRORED_PART ], true ) ) {
						$this->ranked[ $parts[1] ] = $parts[1];
					}
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$now  = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$hour = '2026-08-27-13';
		$kea  = 'a5a5a5a5a5a5';
		$moa  = 'b8b8b8b8b8b8';
		$this->set_url_bucket( $store, $hour . '-05', [ $kea => [ 'url' => 'https://kea.test/kea-5', 'count' => 5, 'last_seen' => $now - 7000 ] ], 'kea.test' );
		$this->set_url_bucket( $store, $hour . '-05', [ $moa => [ 'url' => 'https://moa.test/moa-8', 'count' => 8, 'last_seen' => $now - 7000 ] ], 'moa.test' );
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, $now );
		$this->assertCount( 2, $store->ranked, 'the fold ranks both servers' );

		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $moa ), [ $moa => [ 'url' => 'https://moa.test/moa-8', 'count' => 19 ] ], 'moa.test' );
		$store->bucket_forget_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'moa.test' ) ), $hour ] ] );
		$store->ranked = [];
		$successor     = new Flame_Builder_Node();
		$successor->set_stats_store( $store );
		$successor->flush();

		$this->assertEqualsCanonicalizing(
			[ Stats_Store::server_key( 'kea.test' ), Stats_Store::server_key( 'moa.test' ) ],
			\array_values( $store->ranked ),
			'the hour re-ranks every server it names'
		);
		$this->assertSame( [], $this->url_rank_done( $store, $hour, 'moa.test' ), 'its marker is back' );
		$moa_list = $store->url_rank_window( [ $hour ], [], 'count', 'desc', 'moa.test' );
		$this->assertSame( 19, $moa_list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$site = $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' );
		$this->assertSame( [ $moa, $kea ], \array_column( $site[0][1], Stats_Store::RANK_HASH ), 'the site merges both' );
	}

	public function test_a_late_write_forgets_each_hours_marker_once_a_flush(): void {
		// Rows and names across two buckets and two servers: several hour
		// keys land for one hour, and one delete says what all of them do.
		$store = new class( ...$this->stats_store_args( 0, 86400 ) ) extends Stats_Store {
			/** @var list<list<string>> */
			public array $forgotten = [];
			public function bucket_forget_multi( array $forgets ): void {
				$this->forgotten[] = \array_map( static fn ( array $forget ): string => self::key_at( $forget[0], $forget[1] ), $forgets );
				parent::bucket_forget_multi( $forgets );
			}
		};
		$hash  = 'b6b6b6b6b6b6';
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/once-a-flush-2208', 'count' => 3 ],
		] );
		$late = static fn ( int $count ): array => [ $hash => self::positional_url_row( [ 'count' => $count ] ) ];
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [
			'2026-08-27-13-20' => \array_replace( ( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ), [
				'url_stats' => [ self::SEED_SERVER => $late( 5 ), 'kaka-2208.test' => $late( 11 ) ],
				'url_names' => [ $hash => 'https://kea.test/once-a-flush-2208' ],
			] ),
			'2026-08-27-13-40' => \array_replace( ( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ), [
				'url_stats' => [ self::SEED_SERVER => $late( 7 ) ],
			] ),
		] );

		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );

		$this->assertSame(
			[ [
				'urlrank_sh:2026-08-27-13:done:' . Stats_Store::server_key( self::SEED_SERVER ),
				'urlrank_sh:2026-08-27-13:done:' . Stats_Store::server_key( 'kaka-2208.test' ),
			] ],
			$store->forgotten
		);
	}

	public function test_a_flushed_bucket_below_the_fine_floor_is_not_ranked(): void {
		// Hour 09 is unfolded, so its bucket's write forms a ranking group,
		// but no reader plans it fine-grained. Seeds: 19 requests old, 5 new.
		$store = $this->hour_rank_recorder();
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$old       = '2026-08-27-09-20';
		$new       = '2026-08-27-15-05';

		$this->flush_buckets( $fb, [
			$old => [ 'url_stats' => [ 'a4a4a4a4a4a4' => self::positional_url_row( [ 'count' => 19 ] ) ] ],
			$new => [ 'url_stats' => [ 'a4a4a4a4a4a4' => self::positional_url_row( [ 'count' => 5 ] ) ] ],
		] );

		$this->assertContains( $new, $store->bucket_ranks, 'a bucket in the fine tail ranks' );
		$this->assertNotContains( $old, $store->bucket_ranks, 'one below it does not' );
		$this->assertArrayNotHasKey( $old, ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
	}

	public function test_a_refused_late_write_names_each_key_it_lost(): void {
		// One late write is two keys in two tiers; which one was refused is
		// the operator's next move, so each refusal names its own key.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$store = new class( ...$this->stats_store_args( 0, 86400 ) ) extends Stats_Store {
			public bool $refuse = false;
			public function bucket_set_multi( array $writes ): array {
				$out = parent::bucket_set_multi( $writes );
				foreach ( $writes as $i => [ $parts ] ) {
					if ( $this->refuse && \in_array( $parts[0], [ self::NS_URLS, self::NS_URLS_HOUR ], true ) ) {
						$out[ $i ] = false;
					}
				}
				return $out;
			}
		};
		$hash  = 'c8c8c8c8c8c8';
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/refused-late-6630', 'count' => 3 ],
		] );

		$store->refuse = true;
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'count' => 7 ] ),
		] );

		$srv = Stats_Store::server_key( self::SEED_SERVER );
		$this->assertStringContainsString( "urls:2026-08-27-13-40:{$srv}:{$shard}", $err, 'the fine key' );
		$this->assertStringContainsString( "urls_h:2026-08-27-13:{$srv}:{$shard}", $err, 'and the hour key' );
	}

	public function test_the_rank_memo_prune_floor_is_the_read_plans_fine_tail(): void {
		// At :59 the fine tail runs back to the hour's start, so a bucket 44
		// minutes back is still read fine-grained, and the hour before is not.
		$fb      = new Flame_Builder_Node();
		$store   = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now     = \gmmktime( 15, 59, 0, 8, 27, 2026 );
		$fine    = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), $now ) )['fine'];
		$inside  = '2026-08-27-15-15';
		$outside = '2026-08-27-14-55';
		$this->assertContains( $inside, $fine, 'the fixture bucket is in the fine tail' );
		$this->assertNotContains( $outside, $fine, 'and the other is behind it' );
		$fb->set_stats_store( $store );
		$ranked_at = new \ReflectionProperty( $fb, 'ranked_at' );
		$pending   = new \ReflectionProperty( $fb, 'rank_pending' );
		$pending->setValue( $fb, [ $inside => true, $outside => true ] );

		Core::$now = $now;
		$fb->flush();

		$this->assertSame( $now, $ranked_at->getValue( $fb )[ $inside ] ?? null, 'a bucket the reader plans is ranked' );
		$this->assertArrayNotHasKey( $outside, $ranked_at->getValue( $fb ), 'one it does not is not ranked' );
		$this->assertSame( [], $pending->getValue( $fb ), 'and both leave the pending set' );
	}

	public function test_an_all_digit_hash_merges_into_its_stored_row(): void {
		// A 12-hex-digit URL hash whose digits are all decimal is an INT array
		// key wherever PHP stores it, so the flush's shard merge has to find
		// the stored row under it rather than seeding an empty one beside it.
		// Seeds distinct from every default: 4 stored requests at 88ms against
		// 11 flushed at 275ms.
		$fb     = new Flame_Builder_Node();
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash   = '481602937158';
		$shard  = Stats_Store::url_shard( $hash );
		$bucket = '2026-08-27-13-40';

		$this->seed_url_shard( $store, $bucket, $shard, [
			$hash => [ 'url' => '/numeral-4816', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 88.0 ],
		] );
		$fb->set_stats_store( $store );
		$this->flush_pending( $fb, $bucket, [
			$hash => self::positional_url_row( [ 'url' => '/numeral-4816', 'count' => 11, 'timed_count' => 11, 'sum_ms' => 275.0 ] ),
		] );

		$rows = $this->url_bucket_rows( $store, $bucket );
		$this->assertArrayHasKey( $hash, $rows, 'the row is still reachable by its hash' );
		$this->assertSame( 15, $rows[ $hash ]['count'], 'the flush merged into the stored row' );
		$this->assertSame( 363.0, (float) $rows[ $hash ]['sum_ms'] );
	}

	public function test_a_refused_hour_shard_write_is_logged_by_shard(): void {
		// The fold writes every server's shards in one batch, and the batch
		// answers per write — so a refusal names the shard that was lost, not
		// the hour.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$hash  = Log_Manager::url_hash( 'https://kea.test/dugong-9914' );
		$shard = Stats_Store::url_shard( $hash );
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public string $refuse_shard = '';
			public function bucket_set_multi( array $writes ): array {
				$out = parent::bucket_set_multi( $writes );
				foreach ( $writes as $i => [ $parts ] ) {
					if ( self::NS_URLS_HOUR === $parts[0] && $this->refuse_shard === ( $parts[2] ?? '' ) ) {
						$out[ $i ] = false;
					}
				}
				return $out;
			}
		};
		$store->refuse_shard = $shard;
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			$hash => [ 'url' => 'https://kea.test/dugong-9914', 'count' => 17, 'timed_count' => 17, 'sum_ms' => 340.0, 'last_seen' => 1756300000 ],
		] );

		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertStringContainsString( 'hour fold write refused', $err );
		$this->assertStringContainsString( Stats_Store::key_at( Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), $shard ), '2026-08-27-13' ), $err, 'the refusal names the shard' );
	}

	public function test_a_fold_that_loses_a_write_leaves_its_hour_unmarked(): void {
		// A marker stands for its server's shards, so a fold that lost one
		// writes none, and the next worker's probe finds the hour owed.
		$hash  = Log_Manager::url_hash( 'https://kea.test/kakapo-3113' );
		$shard = Stats_Store::url_shard( $hash );
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public string $refuse_shard = '';
			public function bucket_set_multi( array $writes ): array {
				$landed = parent::bucket_set_multi( $writes );
				foreach ( $writes as $i => [ $parts ] ) {
					$landed[ $i ] = $landed[ $i ] && ! ( self::NS_URLS_HOUR === $parts[0] && $this->refuse_shard === ( $parts[2] ?? '' ) );
				}
				return $landed;
			}
		};
		$store->refuse_shard = $shard;
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			$hash => [ 'url' => 'https://kea.test/kakapo-3113', 'count' => 31, 'timed_count' => 31, 'sum_ms' => 310.0, 'last_seen' => 1756300000 ],
		] );
		$fb->set_stats_store( $store );

		self::roll_up( $fb, \gmmktime( 15, 7, 0, 8, 27, 2026 ) );

		$this->assertNotSame( [], $store->server_index( [ '2026-08-27-13' ], [] ), 'the hour folded' );
		$this->assertNull( $this->url_rank_done( $store, '2026-08-27-13' ), 'with no marker' );
	}

	public function test_a_stale_hour_missing_a_shard_is_folded_again_not_ranked_short(): void {
		// Ranked from the shards it holds, the hour would settle without the
		// lost one's rows; its fine tier still has them, so it folds.
		$kept_url = self::url_in_shard( 'https://kea.test', '3' );
		$lost_url = self::url_in_shard( 'https://kea.test', 'b' );
		$kept     = Log_Manager::url_hash( $kept_url );
		$lost     = Log_Manager::url_hash( $lost_url );
		$store    = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			$kept => [ 'url' => $kept_url, 'count' => 22, 'timed_count' => 22, 'sum_ms' => 220.0 ],
			$lost => [ 'url' => $lost_url, 'count' => 17, 'timed_count' => 17, 'sum_ms' => 170.0 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );
		$hour = '2026-08-27-13';
		$this->assertSame( 17, self::named_url_rows( $this->get_url_hour( $store, $hour, Stats_Store::url_shard( $lost ) ) )[ $lost ]['count'] ?? null, 'the fixture folds both' );
		$store->bucket_forget_multi( [
			[ Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), Stats_Store::url_shard( $lost ) ), $hour ],
			[ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $hour ],
		] );

		$successor = new Flame_Builder_Node();
		$successor->set_stats_store( $store );
		$successor->flush();

		$this->assertSame( 17, self::named_url_rows( $this->get_url_hour( $store, $hour, Stats_Store::url_shard( $lost ) ) )[ $lost ]['count'] ?? null, 'the lost shard is back' );
		$this->assertSame( [], $this->url_rank_done( $store, $hour ), 'and the hour marked' );
		$list = $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' );
		$this->assertSame( [ $kept, $lost ], \array_column( $list[0][1] ?? [], Stats_Store::RANK_HASH ), 'ranked over both' );
	}

	public function test_a_refused_fold_write_is_not_re_folded_next_flush(): void {
		// Nothing retries a refused cache write: the hour is folded whatever
		// its writes answered, so the next flush neither probes nor folds it.
		$hash  = Log_Manager::url_hash( 'https://kea.test/weka-6107' );
		$shard = Stats_Store::url_shard( $hash );
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public string $refuse_shard = '';
			public int $folds           = 0;
			public function bucket_set_multi( array $writes ): array {
				$refused = [];
				foreach ( $writes as $i => [ $parts, $key ] ) {
					if ( self::NS_URLS_HOUR === $parts[0] && $this->refuse_shard === ( $parts[2] ?? '' ) && '2026-08-27-13' === $key ) {
						++$this->folds;
						$refused[ $i ] = false;
					}
				}
				$kept = \array_diff_key( $writes, $refused );
				return \array_replace( \array_combine( \array_keys( $kept ), parent::bucket_set_multi( \array_values( $kept ) ) ), $refused );
			}
		};
		$store->refuse_shard = $shard;
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			$hash => [ 'url' => 'https://kea.test/weka-6107', 'count' => 19, 'timed_count' => 19, 'sum_ms' => 380.0, 'last_seen' => 1756300000 ],
		] );
		$fb->set_stats_store( $store );
		$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		self::roll_up( $fb, $now );
		self::roll_up( $fb, $now + 5 );

		$this->assertSame( 1, $store->folds, 'the refused shard is folded once' );
	}

	public function test_a_stale_hour_ranks_a_server_of_worker_rows_alone(): void {
		// Cron's hour holds worker shards only, which no list ranks; unless the
		// stale ranking names it, its DONE marker never lands and every
		// roll-up finds the hour stale again.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hour  = '2026-08-27-13';
		foreach ( Stats_Store::url_shards() as $shard ) {
			$this->seed_url_hour( $store, $hour, $shard, [], 'kea.test' );
		}
		foreach ( Stats_Store::url_shards( true ) as $shard ) {
			$this->seed_url_hour( $store, $hour, $shard, [], 'kea.test' );
			$this->seed_url_hour( $store, $hour, $shard, [], 'cron-4417.test' );
		}
		$fb->set_stats_store( $store );

		( new \ReflectionMethod( $fb, 'rank_hours_from_store' ) )->invoke( $fb, $store, [ $hour ] );

		$this->assertSame( [], $this->url_rank_done( $store, $hour, 'cron-4417.test' ) );
		$this->assertSame( [], $store->url_hours_derived( [ $hour ] )[ $hour ] );
	}

	/**
	 * A store counting its batch writes, asking through `$asker` when given,
	 * whose reads of a key `$pattern` matches go unanswered once
	 * `fail_reads()` says so.
	 *
	 * @return Stats_Store&object{writes: int, pattern: string}
	 */
	private function unanswered_store( string $pattern, ?Flame_Builder_Node $asker = null ): Stats_Store {
		$store = new class( ...$this->stats_store_args( 0, 86400, $asker ) ) extends Stats_Store {
			public int $writes    = 0;
			public string $pattern = '';
			public function bucket_set_multi( array $writes ): array {
				$this->writes += \count( $writes );
				return parent::bucket_set_multi( $writes );
			}
		};
		$store->pattern = $pattern;
		return $store;
	}

	/**
	 * Refuse, or answer again, every read of a key the store's pattern
	 * matches, as its Table would when it cannot answer.
	 *
	 * @param Stats_Store&object{pattern: string} $store What `unanswered_store()` built.
	 */
	private function fail_reads( Stats_Store $store, bool $failing ): void {
		$this->refuse_stats_reads( $failing ? $store->pattern : '' );
	}

	public function test_a_failed_derived_read_folds_nothing_and_memoizes_nothing(): void {
		// A settled hour read as absent would be folded again from fine
		// buckets long evicted, overwriting its rows with nothing.
		$fb    = new Flame_Builder_Node();
		$store = $this->unanswered_store( '/^urlsrv_h:/', $fb );
		$this->set_url_bucket( $store, '2026-08-27-13-05', [ 'c4c4c4c4c4c4' => [ 'url' => '/settled-4411', 'count' => 29 ] ] );
		$fb->set_stats_store( $store );
		$store->writes = 0;
		$this->fail_reads( $store, true );

		self::roll_up( $fb, \gmmktime( 15, 7, 0, 8, 27, 2026 ) );

		$this->assertSame( 0, $store->writes );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'folded_hours' ) )->getValue( $fb ), 'every hour asked again next flush' );
	}

	/** @return array<string,array{0:string}> */
	public static function fold_reads(): array {
		return [
			'the index' => [ '/^urlsrv:/' ],
			'the rows'  => [ '/^urls:/' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'fold_reads' )]
	public function test_a_fold_whose_read_went_unanswered_writes_nothing( string $pattern ): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->unanswered_store( $pattern, $fb );
		$this->set_url_bucket( $store, '2026-08-27-13-05', [ 'c4c4c4c4c4c4' => [ 'url' => '/unfolded-4412', 'count' => 31 ] ] );
		$fb->set_stats_store( $store );
		$store->writes = 0;
		$this->fail_reads( $store, true );

		$folded = ( new \ReflectionMethod( $fb, 'fold_hour_into_store' ) )->invoke( $fb, $store, '2026-08-27-13', false );

		$this->assertNull( $folded );
		$this->assertSame( 0, $store->writes, 'no hour folded serverless or short' );
	}

	public function test_a_fold_whose_read_went_unanswered_says_so_in_its_span(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->unanswered_store( '/^urls:/', $fb );
		$this->set_url_bucket( $store, '2026-08-27-13-05', [ 'c4c4c4c4c4c4' => [ 'url' => '/unfolded-4413', 'count' => 37 ] ] );
		$fb->set_stats_store( $store );
		$this->fail_reads( $store, true );
		$now           = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$previous    = Core::$clock;
		Core::$clock = static fn (): float => (float) $now;
		try {
			$entries = $this->logged_in( $this->make_temp_dir(), static fn () => self::roll_up( $fb, $now ) );
		} finally {
			Core::$clock = $previous;
		}

		$told = \array_column( self::closes_of( $entries, Flame_Tree::STATS_FOLD ), 'm' );
		$this->assertContains( '2026-08-27-13: missing index · unanswered', $told );
	}

	/** @return array<string,array{0:string}> */
	public static function rank_reads(): array {
		return [
			'the index' => [ '/^urlsrv:/' ],
			'the rows'  => [ '/^urls:/' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'rank_reads' )]
	public function test_a_ranking_whose_read_went_unanswered_leaves_the_bucket_owed( string $pattern ): void {
		$fb     = new Flame_Builder_Node();
		$store  = $this->unanswered_store( $pattern, $fb );
		$now    = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $now - 3 * Stats_Store::BUCKET_SECONDS );
		$this->set_url_bucket( $store, $bucket, [ 'd5d5d5d5d5d5' => [ 'url' => '/owed-5513', 'count' => 37 ] ] );
		$fb->set_stats_store( $store );
		$store->writes = 0;
		$this->fail_reads( $store, true );

		( new \ReflectionMethod( $fb, 'rank_buckets' ) )->invoke( $fb, $store, [ $bucket ], $now );

		$this->assertSame( 0, $store->writes, 'no bucket ranked empty' );
		$this->assertArrayHasKey( $bucket, ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
		$this->assertArrayNotHasKey( $bucket, ( new \ReflectionProperty( $fb, 'ranked_at' ) )->getValue( $fb ) );
	}

	/** @return array<string,array{0:string}> */
	public static function stale_reads(): array {
		return [
			'the index' => [ '/^urlsrv_h:/' ],
			'the rows'  => [ '/^urls_h:/' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'stale_reads' )]
	public function test_a_stale_hour_whose_read_went_unanswered_stays_stale( string $pattern ): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->unanswered_store( $pattern, $fb );
		$hour  = '2026-08-27-13';
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'e6e6e6e6e6e6' ), [ 'e6e6e6e6e6e6' => [ 'url' => '/stale-6614', 'count' => 41 ] ] );
		$fb->set_stats_store( $store );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, [ $hour => true ] );
		$store->writes = 0;
		$this->fail_reads( $store, true );

		( new \ReflectionMethod( $fb, 'rank_hours_from_store' ) )->invoke( $fb, $store, [ $hour ] );

		$this->assertSame( 0, $store->writes );
		$this->assertSame( [ $hour => true ], ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ) );
	}

	public function test_a_flush_whose_index_read_went_unanswered_drops_its_url_rows_alone(): void {
		// Admitted against no index its servers would pass the cap, so the URL
		// rows, index and names wait; nothing else reads the index, and fails
		// soft only where its own read fails. Seeds: 7 requests, 9 on the board.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$fb    = new Flame_Builder_Node();
		$store = $this->unanswered_store( '/^urlsrv:/', $fb );
		$now   = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$hash  = 'f7f7f7f7f7f7';
		$fb->set_stats_store( $store );
		$pending = new \ReflectionProperty( $fb, 'pending' );
		$flush   = function ( array $buckets ) use ( $fb, $pending, $now, $hash ): void {
			$accs = [];
			foreach ( $buckets as $bucket ) {
				$acc                                  = ( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null );
				$acc['url_stats']['kea.test'][ $hash ] = self::positional_url_row( [ 'count' => 7, 'last_seen' => $now, 'path' => '/dropped-7715' ] );
				$acc['url_names'][ $hash ]            = 'https://kea.test/dropped-7715';
				$acc['hourly']                        = [ 'count' => 7, 'sum_ms' => 700.0, 'sum_peak_mb' => 21.0 ];
				$acc['leaderboard']                   = self::lb_sums( 9, 90.0 );
				$accs[ $bucket ]                      = $acc;
			}
			$pending->setValue( $fb, $accs );
			Core::$now = $now;
			$fb->flush();
		};
		$bucket        = Stats_Store::bucket_key( $now );
		$this->fail_reads( $store, true );

		$flush( [ $bucket ] );
		$flush( [ $bucket, Stats_Store::bucket_key( $now - Stats_Store::BUCKET_SECONDS ) ] );

		$this->assertSame( [], $pending->getValue( $fb ), 'nothing held' );
		$this->fail_reads( $store, false );
		$this->assertSame( [], $store->url_row_sources( [ $bucket ] ), 'no row written against a missing index' );
		$this->assertSame( [], $store->server_index( [], [ $bucket ] ), 'nor the index' );
		$this->assertSame( [], $store->get_url_names( [ $hash ] ), 'nor the names' );
		$this->assertSame( 14, $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( $bucket ) ] )[ $bucket ]['count'] ?? null, 'the site totals land, both flushes' );
		$this->assertSame( 27, $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ) )['count'] ?? null, 'and the leaderboard hour, all three bucket writes' );
		$this->assertStringContainsString( ' — 1', $err );
		$this->assertSame( 1, \substr_count( $err, 'stats flush dropped URL rows' ), 'one key whatever the count, so the second is rate-limited' );
	}

	// -------------------------------------------------------------------------
	// Narration: what the builder writes and heals, on its own record.
	// -------------------------------------------------------------------------

	/** The narration categories, every one the builder writes. */
	private const NARRATION = [
		Flame_Tree::STATS_WRITES,
		Flame_Tree::STATS_PROBE,
		Flame_Tree::STATS_FOLD . ' (start)',
		Flame_Tree::STATS_FOLD . ' (complete)',
		Flame_Tree::STATS_RE_RANK . ' (start)',
		Flame_Tree::STATS_RE_RANK . ' (complete)',
		Flame_Tree::STATS_RANK_CLOSE . ' (start)',
		Flame_Tree::STATS_RANK_CLOSE . ' (complete)',
		Flame_Tree::STATS_SWEEP . ' (start)',
		Flame_Tree::STATS_SWEEP . ' (complete)',
	];

	/**
	 * Every hour of the plan at `$now` derived as a steady worker leaves it:
	 * folded, indexed and ranked, the site's lists and records beside one
	 * server's, each server marked DONE.
	 *
	 * @param list<string> $hours The hours to settle.
	 */
	private function settle_hours( Stats_Store $store, array $hours ): void {
		foreach ( $hours as $hour ) {
			foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $shard ) {
				$this->seed_url_hour( $store, $hour, $shard, [] );
			}
			$this->set_url_rank_lists( $store, $hour, [], true );
		}
	}

	/**
	 * Run one worker lifetime — a restore, a flush every five seconds with a
	 * request in each, a checkpoint every thirty, a clean stop — and return
	 * the narration it wrote, with what reached stderr.
	 *
	 * @return array{0: list<array<string,mixed>>, 1: string}
	 */
	private function narrated_lifetime( Flame_Builder_Node $fb, int $from ): array {
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$t           = $from;
		$previous    = Core::$clock;
		Core::$clock = static function () use ( &$t ): float {
			return (float) $t;
		};
		try {
			$entries = $this->logged_in(
				$this->make_temp_dir(),
				function () use ( $fb, $from, &$t ): void {
					$fb->restore_state( [] );
					for ( $t = $from; $t < $from + 595; $t += 5 ) {
						Core::$now = $t;
						$this->fill_request( $fb, $this->completed_request( [ 'url' => '/lifetime-' . ( $t % 7 ), 'timestamp' => $t ] ) );
						$fb->flush();
						if ( 0 === ( $t - $from ) % 30 ) {
							$fb->save_state();
						}
					}
					Core::$now = $t;
					$fb->shutdown_sweep();
				}
			);
		} finally {
			Core::$clock = $previous;
		}
		return [ self::entries_of( $entries, ...self::NARRATION ), $err . \implode( '', \array_column( self::entries_of( $entries, 'stderr' ), 'm' ) ) ];
	}

	/** What a narration line says before its counters: `m` up to the first ` · `. */
	private static function head_of( array $entry ): string {
		return \explode( ' · ', (string) ( $entry['m'] ?? '' ) )[0];
	}

	/** @return array<string,int> Category => lines, a span counted once, by its close. */
	private static function lines_by_category( array $narration ): array {
		$told = \array_filter( $narration, static fn ( array $entry ): bool => ! \str_ends_with( (string) $entry['k'], ' (start)' ) );
		return \array_count_values( \array_map( static fn ( array $entry ): string => \str_replace( ' (complete)', '', (string) $entry['k'] ), $told ) );
	}

	/** The `(complete)` entries of one narration span. */
	private static function closes_of( array $entries, string $span ): array {
		return self::entries_of( $entries, "{$span} (complete)" );
	}

	public function test_a_flush_ranks_its_closed_buckets_in_one_batch_and_one_line(): void {
		// A replay closes many buckets at once: one write round and one span
		// for the chunk, not a transaction and two firehose lines a bucket.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			public int $rank_rounds = 0;
			public function bucket_set_multi( array $writes ): array {
				if ( [] !== \array_filter( $writes, static fn ( array $write ): bool => self::NS_URLRANK_S === $write[0][0] ) ) {
					++$this->rank_rounds;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb->set_stats_store( $store );
		$now         = \gmmktime( 14, 31, 0, 9, 22, 2026 );
		$buckets     = [ '2026-09-22-14-05', '2026-09-22-14-10', '2026-09-22-14-15' ];
		$lists       = [];
		$previous    = Core::$clock;
		Core::$clock = static fn (): float => (float) $now;
		try {
			$entries = $this->logged_in(
				$this->make_temp_dir(),
				function () use ( $fb, $store, $buckets, $now, &$lists ): void {
					Core::$now = $now;
					$pending   = [];
					foreach ( $buckets as $i => $bucket ) {
						$pending[ $bucket ] = [ 'url_stats' => [ \sprintf( 'd%011x', 0x3307 + $i ) => self::positional_url_row( [ 'count' => 7 + $i, 'path' => "/hihi-{$i}" ] ) ] ];
					}
					$this->flush_buckets( $fb, $pending );
					$lists = $store->url_rank_window( [], $buckets, 'count', 'desc', '' );
				}
			);
		} finally {
			Core::$clock = $previous;
		}

		$this->assertSame( 1, $store->rank_rounds, 'the three buckets\' lists in one write' );
		$closes = self::closes_of( $entries, Flame_Tree::STATS_RANK_CLOSE );
		$this->assertCount( 1, $closes );
		$this->assertStringStartsWith( '2026-09-22-14-05..2026-09-22-14-15 · 3 buckets', (string) ( $closes[0]['m'] ?? '' ) );
		$this->assertSame( $buckets, \array_column( $lists, 0 ), 'each bucket ranked' );
	}

	public function test_a_steady_worker_lifetime_narrates_a_couple_dozen_lines(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$from  = \gmmktime( 14, 1, 0, 9, 22, 2026 );
		$this->settle_hours( $store, Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $from ) )['hours'] );
		$fb = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		[ $narration, $err ] = $this->narrated_lifetime( $fb, $from );

		$lines = self::lines_by_category( $narration );
		$this->assertSame( 11, $lines[ Flame_Tree::STATS_WRITES ] ?? 0, 'one summary a refresh, not one a flush, and the stop\'s' );
		$this->assertSame( 1, $lines[ Flame_Tree::STATS_SWEEP ] ?? 0 );
		$this->assertSame( 2, $lines[ Flame_Tree::STATS_RANK_CLOSE ] ?? 0, 'the two buckets that closed' );
		$this->assertArrayNotHasKey( Flame_Tree::STATS_PROBE, $lines, 'no late write unfolded an hour' );
		$this->assertLessThanOrEqual( 35, \array_sum( $lines ), 'a span counted once' );
		$this->assertSame( '', $err, 'nothing reaches the Error Log' );
		$first = $narration[ \array_search( Flame_Tree::STATS_WRITES, \array_column( $narration, 'k' ), true ) ];
		$this->assertMatchesRegularExpression( '/^1 flushes · \d+ writes · /', $first['m'] );
		$this->assertStringContainsString( ' · 56 lists · 56 site lists · 4 records · 4 site records', $first['m'], 'the open bucket\'s first ranking, every list set' );
		$this->assertSame( 1, $first['keep'] );
		$sweep = self::closes_of( $narration, Flame_Tree::STATS_SWEEP )[0];
		$this->assertStringContainsString( '1 ranked', $sweep['m'], 'the bucket the stop still owed' );
		$keys  = \array_column( $narration, 'k' );
		$start = \array_search( Flame_Tree::STATS_SWEEP . ' (start)', $keys, true );
		$told  = \array_keys( $keys, Flame_Tree::STATS_WRITES, true );
		$this->assertTrue( false !== $start && $start < \end( $told ), 'the stop\'s last minute of writes is told inside its span' );
		foreach ( $narration as $entry ) {
			$this->assertSame( [], \array_diff( \array_keys( $entry ), [ 'n', 'k', 'm', 'keep', 'ts', 'duration_ms' ] ), 'the counters ride m, which the record keeps' );
			$this->assertLessThanOrEqual( 3840, \strlen( (string) \wp_json_encode( $entry ) ), 'every entry fits one firehose line' );
		}
	}

	public function test_a_cold_backfill_lifetime_narrates_each_fold_and_stays_under_a_hundred(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$from  = \gmmktime( 14, 1, 0, 9, 22, 2026 );
		$hours = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $from ) )['hours'];
		$fb    = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		[ $narration, $err ] = $this->narrated_lifetime( $fb, $from );

		$folds = self::closes_of( $narration, Flame_Tree::STATS_FOLD );
		$this->assertSame( \count( $hours ), \count( $folds ), 'one span a fold' );
		foreach ( $folds as $fold ) {
			$this->assertMatchesRegularExpression( '/^\S+: missing index$/', self::head_of( $fold ) );
		}
		$this->assertLessThanOrEqual( 100, \count( $narration ) );
		$this->assertLessThanOrEqual( 50, \array_sum( self::lines_by_category( $narration ) ), 'a span counted once' );
		$this->assertSame( '', $err );
	}

	/**
	 * A narration line is a Partition write, so the stop can rise at one the
	 * flush writes: the flush still finishes and empties its accumulators, so
	 * the worker that restores the checkpoint counts nothing twice.
	 */
	public function test_a_stop_a_rank_close_line_raises_waits_for_the_flush(): void {
		$store = new StopArmingStatsStore( ...$this->stats_store_args( 0, 86400 ) );
		$from  = \gmmktime( 14, 1, 0, 9, 22, 2026 );
		$this->settle_hours( $store, Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $from ) )['hours'] );
		$fb = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		$closed      = Stats_Store::bucket_key( $from );
		$open        = Stats_Store::bucket_key( $from + 300 );
		$t           = $from;
		$previous    = Core::$clock;
		Core::$clock = static function () use ( &$t ): float {
			return (float) $t;
		};
		$stop = null;
		try {
			$this->logged_in(
				$this->make_temp_dir(),
				function () use ( $fb, $store, $from, $closed, &$t, &$stop ): void {
					foreach ( [ 0, 5, 300 ] as $at ) {
						$t         = $from + $at;
						Core::$now = $t;
						$this->fill_request_at( $fb, $this->completed_request( [ 'url' => '/stop-7741', 'timestamp' => $t ] ), "0:{$at}:7741" );
						if ( 300 === $at ) {
							$store->stop_at = $closed;
							try {
								( new \ReflectionMethod( $fb, 'fire' ) )->invoke( $fb );
							} catch ( \Newspack_Nodes\Worker_Should_Stop $e ) {
								$stop = $e;
							} finally {
								$store->disarm();
							}
						} else {
							$fb->flush();
						}
					}
				}
			);
			// The Tables date their rows by this clock too, so it holds to the end.
			$successor = new Flame_Builder_Node();
			$successor->sink( new Capture_Sink_Node() );
			$successor->set_stats_store( $store );
			$successor->restore_state( $fb->save_state() );
			$successor->flush();

			$this->assertTrue( $store->armed, 'the closed bucket ranked inside its span' );
			$this->assertNotNull( $stop, 'the stop still leaves' );
			$this->assertSame( 1, $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( $open ) ] )[ $open ]['count'] ?? null, 'the open bucket counted once' );
			$this->assertTrue( \Newspack_Nodes\Worker_Should_Stop::is_clean( $stop ), 'once the flush is done' );
			$this->assertSame( 2, $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( $closed ) ] )[ $closed ]['count'] ?? null );
		} finally {
			Core::$clock = $previous;
		}
	}

	public function test_a_worker_record_keeps_the_narration_counters(): void {
		// The builder files the record's entries through an allowlist that keeps
		// `m` and drops every other producer field: the counters must be in it.
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( $fb, $now ): void {
				Core::$now = $now;
				$this->fill_request( $fb, $this->completed_request( [ 'url' => '/narrated-3301', 'timestamp' => $now ] ) );
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
			[ 'k' => 'request', 'm' => 'POST /wp-json/newspack-nodes/v1/workers/spawn' ],
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
		$this->assertStringContainsString( ' · 1 url blobs', (string) ( $stored['m'] ?? '' ), 'the record shows what the flush counted' );
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
		$fb    = new Flame_Builder_Node();
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
			[ Flame_Tree::STATS_PROBE, static fn (): array => [ '2026-09-22-11 fold: missing index', [ 'ms' => 0.03, 'writes' => 0.06 ] ] ]
		);

		$this->assertSame( '2026-09-22-11 fold: missing index · 0.1 writes', $told[0]['m'] ?? null );
	}

	public function test_an_unfold_line_counts_as_the_fold_that_follows_it(): void {
		// An hour the read did not find folds as `missing index`; one holding
		// an index with a server unmarked is stale; a settled one is neither.
		$fb = new Flame_Builder_Node();
		( new \ReflectionProperty( $fb, 'unfolded' ) )->setValue( $fb, [ 'h1' => true, 'h2' => true, 'h3' => true ] );
		$told = $this->narrated(
			$fb,
			'tell_unfold',
			[
				[ 'h1', 'h2', 'h3' ],
				[ 'h2' => [ 'kea.test' ] ],
			]
		);

		$this->assertSame( 'hour derive (unfold) · 3 hours · 2 unfolded · 1 stale', $told[0]['m'] ?? null );
	}

	public function test_a_late_write_keeps_its_cause_when_the_roll_up_finds_the_marker_gone(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now   = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$hour  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'][2];
		$this->settle_hours( $store, [ $hour ] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $hour ] ] );
		$fb->set_stats_store( $store );
		$stale = new \ReflectionProperty( $fb, 'stale_hours' );
		$stale->setValue( $fb, [ $hour => 'late write' ] );

		self::roll_up( $fb, $now );

		$this->assertSame( 'late write', $stale->getValue( $fb )[ $hour ] ?? null, 'the write that forgot the marker is the cause' );
	}

	public function test_a_hour_a_late_write_folds_first_is_no_unfold(): void {
		// A new server's late rows in an hour this worker never folded take
		// nothing off the memo, so no `unfold` line follows.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ 'late-7733.test' => [ 'c9c9c9c9c9c9' => self::positional_url_row( [ 'count' => 13, 'path' => '/late-7733' ] ) ] ] ]
		) ] );

		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, $now, self::fine_floor( $store, $now ) );

		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'unfolded' ) )->getValue( $fb ) );
	}

	public function test_an_unlogged_worker_builds_no_narration(): void {
		// With no started record the line is never built; its counters reset.
		$fb    = new Flame_Builder_Node();
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

	public function test_an_hour_a_late_write_unfolded_is_probed_again_and_says_why(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hash  = 'c5c5c5c5c5c5';
		$fb    = $this->folded_builder( $store, Stats_Store::url_shard( $hash ), [
			$hash => [ 'url' => '/folded-5512', 'count' => 3 ],
		] );
		$now = (int) Core::$now;

		$previous    = Core::$clock;
		Core::$clock = static fn (): float => (float) $now;
		try {
			$entries = $this->logged_in(
				$this->make_temp_dir(),
				function () use ( $fb, $store, $now ): void {
					( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [ '2026-08-27-13-40' => \array_replace(
						( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
						[ 'url_stats' => [ 'late-7732.test' => [ 'c9c9c9c9c9c9' => self::positional_url_row( [ 'count' => 13, 'path' => '/late-7732' ] ) ] ] ]
					) ] );
					( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, $now, self::fine_floor( $store, $now ) );
					( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
					Core::$now = $now;
					$fb->flush();
				}
			);
		} finally {
			Core::$clock = $previous;
		}

		$heads = \array_map( self::head_of( ... ), self::entries_of( $entries, Flame_Tree::STATS_PROBE ) );
		$this->assertContains( 'hour derive (unfold)', $heads );
	}

	public function test_get_stats_carries_the_narration_not_yet_told(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$fb->flush();
		Core::$now += 5;
		$fb->flush();

		$this->assertSame( 1, $this->get_stats( $fb )['narration'][ Flame_Tree::STATS_WRITES ]['flushes'] ?? null, 'the flush since the last summary' );
	}

	/** The catalog's `reply_shape` names every key `GET_STATS` answers with. */
	public function test_get_stats_reply_shape_names_every_key_it_carries(): void {
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 86400 ) );
		$verb       = \array_column( Flame_Builder_Node::node_schema()['requests'], null, 'name' )['GET_STATS'];
		\preg_match_all( '/\w+/', $verb['reply_shape'], $named );

		$this->assertSame( \array_keys( $this->get_stats( $fb ) ), $named[0] );
	}

	public function test_a_closed_hour_is_ranked_when_it_is_folded(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			'a1a1a1a1a1a1' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 70.0, 'last_seen' => 1756300000 ],
		], 'kea.test' );
		$this->set_url_bucket( $store, '2026-08-27-13-40', [
			'a1a1a1a1a1a1' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 20.0, 'last_seen' => 1756302100 ],
			'b2b2b2b2b2b2' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 4000.0, 'last_seen' => 1756302200 ],
		], 'kea.test' );

		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$count = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertCount( 1, $count, 'the hour has a list' );
		$this->assertSame( [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ], \array_column( $count[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 9, $count[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ], 'ranked over the FOLDED row' );
		$this->assertSame(
			'/kiwi-8842',
			$store->url_rank_window( [ '2026-08-27-13' ], [], 'url', 'asc', '' )[0][1][0][ Stats_Store::RANK_PATH ]
		);
	}

	public function test_an_hour_with_rows_and_names_but_no_lists_is_ranked_from_its_coarse_rows(): void {
		// The shape a release before the rank tier left behind, or an evicted
		// list key. Its fine buckets are gone, so the fold must NOT run again —
		// that would overwrite the hour with nothing — and the lists come
		// from the coarse rows themselves. The FIRST closed hour of the plan,
		// so one flush reaches it whatever `ROLLUP_HOURS_PER_FLUSH` is.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$hash  = 'c3c3c3c3c3c3';
		foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $one ) {
			$this->seed_url_hour( $store, '2026-08-27-13', $one, Stats_Store::url_shard( $hash ) === $one
				? [ $hash => [ 'url' => 'https://moa.test/tui-9913', 'count' => 11, 'timed_count' => 11, 'sum_ms' => 110.0, 'last_seen' => 1756285000 ] ]
				: [], 'moa.test' );
		}

		$fb->set_stats_store( $store );
		Core::$now = $now;
		$fb->flush();

		$rows = $store->url_hour_sources( [ '2026-08-27-13' ], Stats_Store::url_shard( $hash ) );
		$this->assertSame( 11, Core::arr( $rows[0][1][ $hash ] )[ Stats_Store::ROW_COUNT ], 'the coarse rows survive' );
		$this->assertSame( 'moa.test', $rows[0][2], 'under the server the hour\'s index names' );
		$list = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( [ $hash ], \array_column( $list[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( '/tui-9913', $store->url_rank_window( [ '2026-08-27-13' ], [], 'url', 'asc', '' )[0][1][0][ Stats_Store::RANK_PATH ] );
	}

	public function test_a_late_write_into_a_folded_hour_is_re_ranked_by_the_next_worker(): void {
		// The hour is folded AND ranked, so the probe memoizes it done and
		// never revisits it. A replay merges into the coarse rows the reader
		// takes; a list still holding the pre-replay count is a count that
		// shows in the fold and the header but never on a ranked page. The
		// forgotten marker is in the store, so a stop loses no re-rank.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash  = Log_Manager::url_hash( '/restaged-4417' );
		$shard = Stats_Store::url_shard( $hash );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/restaged-4417', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0, 'last_seen' => $now - 7200 ],
		] );
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );
		$folded = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( 3, $folded[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ], 'the fold ranked it' );

		// A replayed record for another bucket of that same closed hour.
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/restaged-4417', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0, 'last_seen' => $now - 5400 ] ),
		] );

		$next = new Flame_Builder_Node();
		$next->set_stats_store( $store );
		$next->flush();

		$list = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertCount( 1, $list, 'the hour still has a list' );
		$this->assertSame( [ $hash ], \array_column( $list[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 10, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
	}

	public function test_a_folded_hour_ranks_its_worker_rows_and_a_late_worker_write_re_ranks_it(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash  = Log_Manager::url_hash( '/jobs/reindex/31?job' );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->seed_url_shard( $store, '2026-08-27-13-05', Stats_Store::url_shard( $hash, true ), [
			$hash => [ 'url' => '/jobs/reindex/31?job', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 2480.0, 'worker' => true, 'last_seen' => $now - 7200 ],
		] );
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );
		$hour_list = static fn (): array => $store->bucket_get_multi( [ [ Stats_Store::url_rank_parts( 'count', 'desc', '', true, [ Stats_Store::WORKER_SHARD_PREFIX ] ), '2026-08-27-13' ] ] )[0] ?? [];
		$this->assertSame( [ $hash ], \array_column( $hour_list(), Stats_Store::RANK_HASH ), 'the fold ranks the worker family' );
		$this->assertSame( 4, $hour_list()[0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );

		$this->flush_buckets( $fb, [ '2026-08-27-13-40' => [ 'url_stats_worker' => [
			$hash => self::positional_url_row( [ 'url' => '/jobs/reindex/31?job', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 5580.0, 'worker' => true, 'last_seen' => $now - 5400 ] ),
		] ] ] );
		$next = new Flame_Builder_Node();
		$next->set_stats_store( $store );
		$next->flush();

		$this->assertSame( 13, $hour_list()[0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null, 'the late worker write took the hour\'s marker, and the next worker re-ranked it from the store' );
	}

	public function test_stale_folded_hours_are_re_ranked_in_two_reads(): void {
		// A replay leaves several folded hours unranked, and the probe finds
		// each. A read PER HOUR is a round trip per hour; the whole set's
		// server index comes in one and its coarse rows in a second. Two
		// hours, because `ROLLUP_HOURS_PER_FLUSH` bounds the stale hours one
		// flush ranks.
		$memd       = new InMemoryMemcached();
		Core::$memd = $memd;
		$fb         = new Flame_Builder_Node();
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$hash  = Log_Manager::url_hash( 'https://kea.test/kokako-2280' );
		$hours = [ '2026-08-27-11', '2026-08-27-12' ];
		foreach ( $hours as $i => $hour ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => 'https://kea.test/kokako-2280', 'count' => 7 + $i, 'last_seen' => 1756290000 + $i ],
			] );
		}
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->forget_stats_asks();
		$this->flush_buckets( $fb, [] );

		$this->assertSame( 2, $this->stats_reads(), 'one index read and one rows read for both hours' );
		foreach ( $hours as $i => $hour ) {
			$list = $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' );
			$this->assertSame( [ $hash ], \array_column( $list[0][1], Stats_Store::RANK_HASH ), $hour );
			$this->assertSame( 7 + $i, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ], $hour );
		}
	}

	public function test_two_buckets_of_one_folded_hour_both_reach_the_coarse_key(): void {
		// Both buckets' rows are keyed on the SAME `urls_h:{server_key}:{shard}:{hour}`
		// item, and the chunk reads it once: a merge built on that one
		// pre-read value discards whatever the intent before it merged in.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		// One shard, so the two intents collide on one key.
		$early = 'd1d1d1d1d1d1';
		$late  = 'd2d2d2d2d2d2';
		$hour  = '2026-08-27-13';
		// A folded hour holds its shard, empty or not.
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $early ), [] );
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ $hour => true ] );

		$this->flush_buckets( $fb, [
			$hour . '-05' => [ 'url_stats' => [ $early => self::positional_url_row( [ 'count' => 3, 'last_seen' => 1756300000 ] ) ] ],
			$hour . '-40' => [ 'url_stats' => [ $late => self::positional_url_row( [ 'count' => 8, 'last_seen' => 1756302100 ] ) ] ],
		] );

		$rows = self::named_url_rows( $store->url_hour_sources( [ $hour ], Stats_Store::url_shard( $early ) )[0][1] );
		$this->assertSame( [ $early, $late ], \array_keys( $rows ), 'both buckets reach the hour key' );
		$this->assertSame( 3, $rows[ $early ]['count'] );
		$this->assertSame( 8, $rows[ $late ]['count'] );
	}

	public function test_rank_only_hours_do_not_spend_the_fold_budget(): void {
		// Every closed hour but the oldest holds rows and names but no lists,
		// so the probe marks each stale and folds nothing. Charging those to
		// the fold budget would starve the one hour that still needs a fold.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$hours = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), $now ) )['hours'];
		// Newest first, so the unfolded hour is the last the probe reaches.
		$unfolded = \array_pop( $hours );
		$this->assertGreaterThanOrEqual( 20, \count( $hours ) );
		foreach ( $hours as $hour ) {
			foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $one ) {
				$this->seed_url_hour( $store, $hour, $one, [] );
			}
		}
		$hash = 'f4f4f4f4f4f4';
		$this->set_url_bucket( $store, $unfolded . '-20', [
			$hash => [ 'url' => 'https://kea.test/takahe-6621', 'count' => 13, 'timed_count' => 13, 'sum_ms' => 390.0, 'last_seen' => $now - 80000 ],
		] );

		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, $now );

		$rows = $store->url_hour_sources( [ $unfolded ], Stats_Store::url_shard( $hash ) );
		$this->assertNotEmpty( $rows, 'the unfolded hour folds on the first flush' );
		$this->assertSame( 13, Core::arr( $rows[0][1][ $hash ] )[ Stats_Store::ROW_COUNT ] );
	}

	public function test_stale_hours_rank_at_most_the_budget_per_flush(): void {
		// A replay spanning the window marks every hour stale, and ranking
		// holds each hour's coarse rows at once: the budget bounds that.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$budget = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'ROLLUP_HOURS_PER_FLUSH' ) )->getValue();
		$hash   = 'a7a7a7a7a7a7';
		$hours  = [ '2026-08-27-06', '2026-08-27-07', '2026-08-27-08', '2026-08-27-09', '2026-08-27-10' ];
		foreach ( $hours as $i => $hour ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => 'https://kea.test/hihi-5519', 'count' => 21 + $i, 'last_seen' => 1756270000 + $i ],
			] );
		}
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		$ranked = static fn (): int => \count( \array_filter(
			$hours,
			static fn ( string $hour ): bool => [] !== $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' )
		) );

		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->flush_buckets( $fb, [] );
		$this->assertSame( $budget, $ranked(), 'one flush ranks the budget' );

		$this->flush_buckets( $fb, [] );
		$this->assertSame( \min( 2 * $budget, \count( $hours ) ), $ranked(), 'the rest wait for the next flush' );

		for ( $i = 0; $i < \count( $hours ); $i++ ) {
			$this->flush_buckets( $fb, [] );
		}
		$this->assertSame( \count( $hours ), $ranked(), 'every stale hour is ranked in the end' );
	}

	public function test_a_stop_ranks_no_more_stale_hours_than_a_flush(): void {
		// Every stale hour is probe-found, so its missing marker is in the
		// store and the next worker's probe finds it again: a stop owes none
		// of them more than the flush's own bound.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$budget = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'ROLLUP_HOURS_PER_FLUSH' ) )->getValue();
		$hash   = 'a9a9a9a9a9a9';
		$hours  = [ '2026-08-27-06', '2026-08-27-07', '2026-08-27-08', '2026-08-27-09', '2026-08-27-10' ];
		foreach ( $hours as $i => $hour ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => 'https://kea.test/pipipi-7102', 'count' => 31 + $i, 'last_seen' => 1756270000 + $i ],
			] );
		}
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$plan      = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), (int) Core::$now ) )['hours'];
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $plan, true ) );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );

		$fb->shutdown_sweep();

		$ranked = \array_filter( $hours, static fn ( string $hour ): bool => null !== self::ranked_count( $store, $hour, true ) );
		$this->assertCount( $budget, $ranked, 'the stop ranks the flush\'s bound' );
		$this->assertCount( \count( $hours ) - $budget, ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ), 'and leaves the rest' );
	}

	/**
	 * The windows the docs cite, and the flushes each takes to close.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function provisional_windows(): array {
		return [
			'the default 12-hour window' => [ 43200, 7 ],
			'the 24-hour read ceiling'   => [ 86400, 13 ],
		];
	}

	/**
	 * A fresh builder owes every planned hour, so a ranked page reads
	 * `provisional` until its roll-up has written each hour's index, two a
	 * flush once its data clock has left them: the first flush sets that
	 * clock, and `ceil( hours / ROLLUP_HOURS_PER_FLUSH )` more close it.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provisional_windows' )]
	public function test_a_fresh_builder_owes_no_hour_after_the_flushes_the_docs_cite( int $window, int $flushes ): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: $window, asker: $fb );
		$fb->set_stats_store( $store );
		$budget = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'ROLLUP_HOURS_PER_FLUSH' ) )->getValue();
		$plan   = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->max_lifespan(), self::tick() ) );
		$this->assertSame( $flushes, (int) \ceil( \count( $plan['hours'] ) / $budget ) + 1, 'the docs cite this window\'s count' );
		$owed = static function () use ( $store, $plan ): array {
			$waiting = [];
			$store->url_headers( $plan['hours'], $plan['fine'], '', [ [] ], $waiting );
			return $waiting;
		};

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/kea-owed-4471', 'timestamp' => self::tick() ] ) );
		for ( $i = 1; $i < $flushes; $i++ ) {
			$fb->flush();
		}
		$this->assertNotSame( [], $owed(), 'one flush short, an hour is still owed' );

		$fb->flush();
		$this->assertSame( [], $owed() );
	}

	public function test_due_buckets_rank_in_bounded_reads(): void {
		// Each due bucket gap-fills sixteen keys a server and holds its row
		// maps until it ranks, so one read of every offered bucket is the
		// unbounded batch the per-chunk ranking exists to prevent. A replay's
		// flushed buckets reach the ranker unpruned, so one bucket more than a
		// write chunk's worth of groups forces a second read.
		$limit = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'WRITE_BATCH_KEYS' ) )->getValue();
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var list<int> */
			public array $shard_reads = [];
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				if ( self::NS_URLS === ( $reads[ \array_key_first( $reads ) ][0][0] ?? '' ) ) {
					$this->shard_reads[] = \count( $reads );
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$at      = \gmmktime( 13, 2, 0, 9, 22, 2026 );
		$count   = \intdiv( $limit, Stats_Store::URL_SHARDS ) + 1;
		$buckets = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$bucket             = Stats_Store::bucket_key( $at - ( ( $i + 1 ) * Stats_Store::BUCKET_SECONDS ) );
			$buckets[ $bucket ] = 23 + $i;
			$this->set_url_bucket( $store, $bucket, [
				'f2f2f2f2f2f2' => [ 'url' => 'https://kea.test/kaka-8830', 'count' => 23 + $i, 'timed_count' => 1, 'sum_ms' => 12.0, 'last_seen' => $at - 60 ],
			] );
		}
		$fb->set_stats_store( $store );

		Core::$now = $at;
		( new \ReflectionMethod( $fb, 'rank_buckets' ) )->invoke( $fb, $store, \array_keys( $buckets ), $at );

		$this->assertGreaterThanOrEqual( 2, \count( $store->shard_reads ), 'more than one read' );
		foreach ( $store->shard_reads as $size ) {
			$this->assertLessThanOrEqual( $limit, $size, 'each read within a write chunk' );
		}
		foreach ( $buckets as $bucket => $n ) {
			$this->assertSame( $n, self::ranked_count( $store, $bucket, false ), $bucket );
		}
	}

	public function test_a_pending_bucket_older_than_the_fine_window_is_never_ranked(): void {
		// Past the fine floor no reader plans the bucket, so ranking it spends
		// a 32-key read and a list write on nothing. Seeds distinct from every
		// default: 17 requests, in the hour before the current one.
		$fb    = new Flame_Builder_Node();
		$store = new class( ...$this->stats_store_args( 0, 86400, $fb ) ) extends Stats_Store {
			/** @var list<string> */
			public array $touched = [];
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				foreach ( $reads as [ , $bucket ] ) {
					$this->touched[] = $bucket;
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ , $bucket ] ) {
					$this->touched[] = $bucket;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$at  = \gmmktime( 16, 4, 0, 9, 22, 2026 );
		$old = Stats_Store::bucket_key( $at - 3 * Stats_Store::BUCKET_SECONDS );
		$this->set_url_bucket( $store, $old, [
			'e5e5e5e5e5e5' => [ 'url' => 'https://kea.test/weka-3391', 'count' => 17, 'timed_count' => 1, 'sum_ms' => 9.0, 'last_seen' => $at - 7000 ],
		] );
		$fb->set_stats_store( $store );
		( new \ReflectionProperty( $fb, 'rank_pending' ) )->setValue( $fb, [ $old => true ] );
		$store->touched = [];

		Core::$now = $at;
		( new \ReflectionMethod( $fb, 'rank_owed' ) )->invoke( $fb, $store, $at, self::fine_floor( $store, $at ) );

		$this->assertNotContains( $old, $store->touched, 'the out-of-window bucket is neither read nor written' );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
	}

	public function test_a_closed_hour_is_rolled_up_into_one_coarse_key(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash  = Log_Manager::url_hash( '/wombat-4471' );
		$shard = Stats_Store::url_shard( $hash );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Two fine buckets of the hour that has just closed.
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0, 'max_ms' => 15.0, 'last_seen' => $now - 7200 ],
		] );
		$this->seed_url_shard( $store, '2026-08-27-13-40', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 90.0, 'max_ms' => 22.0, 'last_seen' => $now - 5400 ],
		] );

		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$rolled = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 10, $rolled[ $hash ]['count'], 'the hour sums its buckets' );
		$this->assertSame( 130.0, (float) $rolled[ $hash ]['sum_ms'] );
		$this->assertSame( 22.0, (float) $rolled[ $hash ]['max_ms'], 'an extreme is a max, not a sum' );
		// The name is not in the row: the fold carries statistics, and the URL
		// name table carries the one copy of what those statistics are about.
		$this->assertSame( [ $hash => [ 'server' => self::SEED_SERVER, 'url' => 'https://' . self::SEED_SERVER . '/wombat-4471' ] ], $store->get_url_names( [ $hash ] ) );
	}

	public function test_a_folded_hour_keeps_each_server_apart(): void {
		// The fold reads each server's fine keys and writes each server's hour
		// keys: a folded hour never merges two servers into one value, and its
		// index names every server its buckets named.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$hash  = Log_Manager::url_hash( '/wombat-4471' );
		$shard = Stats_Store::url_shard( $hash );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0 ],
		], 'web-4471.test' );
		$this->seed_url_shard( $store, '2026-08-27-13-40', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 90.0 ],
		], 'web-8823.test' );

		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 4, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard, 'web-4471.test' ) )[ $hash ]['count'] );
		$this->assertSame( 6, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard, 'web-8823.test' ) )[ $hash ]['count'] );
		$this->assertSame(
			self::index_of( [ 'web-4471.test', 'web-8823.test' ], Stats_Store::every_shard() ),
			$store->server_index( [ '2026-08-27-13' ], [] )['2026-08-27-13'],
			'each server named with every shard the fold wrote'
		);
		$this->assertSame( [], $this->get_url_hour( $store, '2026-08-27-13', 'w3', 'web-4471.test' ), 'every shard of a named server is written, empty or not' );
	}

	public function test_an_hour_naming_too_many_servers_folds_the_quietest_into_other(): void {
		// Each bucket admits MAX_SERVER_VALUES, so twelve of them can name
		// more between them; the hour keeps the busiest and folds the rest
		// into its `Other` server, whose rows stay whole.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		for ( $i = 0; $i < Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$this->seed_url_shard( $store, '2026-08-27-13-05', '0', [
				\sprintf( '0%011x', $i ) => [ 'url' => "/busy-{$i}", 'count' => 5 ],
			], "busy{$i}.test" );
		}
		$this->seed_url_shard( $store, '2026-08-27-13-40', '0', [
			'0fffffffffff' => [ 'url' => '/quiet-4471', 'count' => 1 ],
		], 'quiet-4471.test' );

		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$index = Stats_Store::index_names( $store->server_index( [ '2026-08-27-13' ], [] )['2026-08-27-13'] );
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $index );
		$this->assertArrayNotHasKey( Stats_Store::server_key( 'quiet-4471.test' ), $index );
		$this->assertSame( Stats_Store::OTHER_KEY, $index[ Stats_Store::server_key( Stats_Store::OTHER_KEY ) ] );
		$this->assertSame( 1, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', '0', Stats_Store::OTHER_KEY ) )['0fffffffffff']['count'] );
	}

	public function test_a_row_the_hour_folds_into_other_keeps_its_host(): void {
		// The quiet server's path was cut against its own host; the hour's
		// `Other` server names none, so the row carries the whole URL.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		for ( $i = 0; $i < Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$this->seed_url_shard( $store, '2026-08-27-13-05', '0', [
				\sprintf( '0%011x', $i ) => [ 'url' => "https://busy{$i}.test/busy-{$i}", 'count' => 5 ],
			], "busy{$i}.test" );
		}
		$this->seed_url_shard( $store, '2026-08-27-13-40', '0', [
			'0fffffffffff' => [ 'url' => 'https://quiet-4471.test/quiet-4471?x=9', 'count' => 1 ],
		], 'quiet-4471.test' );
		$this->assertSame( '/quiet-4471?x=9', $this->get_url_shard( $store, '2026-08-27-13-40', '0', 'quiet-4471.test' )['0fffffffffff'][ Stats_Store::ROW_PATH ], 'seeded host-stripped' );

		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$rows = $this->get_url_hour( $store, '2026-08-27-13', '0', Stats_Store::OTHER_KEY );
		$this->assertSame( 'https://quiet-4471.test/quiet-4471?x=9', $rows['0fffffffffff'][ Stats_Store::ROW_PATH ] );
		$busy = $this->get_url_hour( $store, '2026-08-27-13', '0', 'busy3.test' );
		$this->assertSame( '/busy-3', $busy['000000000003'][ Stats_Store::ROW_PATH ], 'a server kept by name keeps its cut path' );
	}

	public function test_a_busy_servers_tail_never_folds_a_quiet_servers_rows(): void {
		// The reason the server is in the key: a busy spoke filling a shard
		// folds only its own tail into its own `Other`, and a quiet spoke's
		// URL in the same shard keeps its row.
		$fb     = new Flame_Builder_Node();
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now    = self::tick();
		$bucket = Stats_Store::bucket_key( $now );

		$seed = [];
		for ( $i = 0; $i < self::ROWS_PAST_BUDGET; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => self::long_path( "/u{$i}" ), 'count' => 7 + $i, 'timed_count' => 7 + $i,
				'sum_ms' => 2.0 * ( 7 + $i ), 'last_seen' => $now,
			];
		}
		$this->seed_url_shard( $store, $bucket, 'a', $seed, 'tail.example' );

		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [
			'url' => $url, 'server_name' => 'live.example', 'duration_ms' => 2.0, 'timestamp' => $now,
		] ) );
		$this->fill_request( $fb, $this->completed_request( [
			'url' => $url, 'server_name' => 'tail.example', 'duration_ms' => 2.0, 'timestamp' => $now,
		] ) );
		$fb->flush();

		$live = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a', 'live.example' ) );
		$this->assertSame( [ Log_Manager::url_hash( $url ) ], \array_keys( $live ), 'the quiet server keeps its row' );
		$tail = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a', 'tail.example' ) );
		self::assert_within_item_budget( $this->get_url_shard( $store, $bucket, 'a', 'tail.example' ) );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $tail, 'and folds its own tail' );
	}

	/**
	 * The per-URL percentiles are GONE, and the duration reservoir with them.
	 *
	 * A stored percentile was never the window's: percentiles do not merge, so
	 * the fold took ONE bucket's and labelled it as the whole retention window.
	 * The honest fixes were a mergeable sketch (+33 to +129 B/row on a row just
	 * cut to 290) or deletion. Nobody sorts by the column, so deletion wins:
	 * it takes 38 B/row off the read AND the whole `url_dur` namespace — up to
	 * `100` floats per row per bucket — off the write.
	 */
	public function test_a_stored_url_row_carries_no_percentiles_or_reservoir(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/pangolin-6142',
				'duration_ms' => 20.0 + $i,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$hash   = Log_Manager::url_hash( '/pangolin-6142' );
		$bucket = Stats_Store::bucket_key( $now );
		$row    = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ) ) )[ $hash ];

		foreach ( [ 'p50_ms', 'p95_ms', 'p99_ms', 'durations' ] as $gone ) {
			$this->assertArrayNotHasKey( $gone, $row, "{$gone} is retired" );
		}
		// The mean is not stored either: it divides by `timed_count`, and the
		// reader owns that rule (decision 2, `Performance_CI_Node::mean_ms`).
		$this->assertArrayNotHasKey( 'avg_ms', $row );
		// The exact extremes stay: they fold from duration_ms and cost 2 fields.
		$this->assertSame( 20.0, $row['min_ms'] );
		$this->assertSame( 24.0, $row['max_ms'] );
		// And nothing writes the reservoir namespace any more.
		$this->assertFalse(
			\method_exists( $store, 'get_url_durations' ),
			'the url_dur namespace is gone, not merely unwritten'
		);
	}

	public function test_a_folded_hour_takes_the_same_row_cap_as_a_bucket(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now   = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Six buckets of distinct URLs, all in one shard, summing to half again
		// what the folded hour's budget holds.
		$per   = (int) \ceil( self::ROWS_PAST_BUDGET / 4 );
		$total = 0;
		foreach ( \array_slice( Stats_Store::buckets_in_hour( '2026-08-27-13' ), 0, 6 ) as $b => $bucket ) {
			$rows = [];
			for ( $i = 0; $i < $per; $i++ ) {
				$rows[ \sprintf( 'a%011x', $b * ( $per + 1 ) + $i ) ] = [
					'url'   => self::long_path( "/row-{$b}-{$i}" ),
					'count' => 3,
				];
				$total += 3;
			}
			$this->seed_url_shard( $store, $bucket, 'a', $rows );
		}

		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], 'a' )[0][1] );
		self::assert_within_item_budget( $store->url_hour_sources( [ '2026-08-27-13' ], 'a' )[0][1] );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $hour, 'the tail folds rather than dropping' );
		$this->assertSame(
			$total,
			\array_sum( \array_column( $hour, 'count' ) ),
			'and the total survives the fold exactly'
		);
	}

	/**
	 * The skip probe reads once for every hour, not once per hour. Steady
	 * state is 23 hours already folded and nothing to do, on a flush that
	 * runs every few seconds — asking per hour paid 23 trips to learn that,
	 * against an API that takes a list (decision 6: per-key `get` is a
	 * latency cliff).
	 */
	public function test_the_rollup_probe_asks_once_for_every_hour(): void {
		$memd       = new InMemoryMemcached();
		Core::$memd = $memd;
		$fb         = new Flame_Builder_Node();
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Every hour already derived — index, rows and lists — so the probe is
		// all this flush does.
		$families = \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) );
		$hours    = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'];
		foreach ( $hours as $hour ) {
			foreach ( $families as $shard ) {
				$this->seed_url_hour( $store, $hour, $shard, [] );
			}
			$this->set_url_rank_lists( $store, $hour, [], true );
		}
		$fb->set_stats_store( $store );

		$this->forget_stats_asks();
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 2, $this->stats_reads(), 'one read for the whole window: its heads, then the rows they name, and nothing folded' );
	}

	/**
	 * A worker that folded an hour is the authority on whether it is folded —
	 * decision 17 puts the fold on the flush path precisely BECAUSE one
	 * partition has one writer. So steady state probes nothing: the probe reads
	 * presence, but `getMulti` fetches and unserializes the VALUES, and the
	 * settled hours are the whole coarse tier.
	 */
	public function test_a_second_flush_does_not_probe_hours_it_folded_itself(): void {
		$memd       = new InMemoryMemcached();
		Core::$memd = $memd;
		$fb         = new Flame_Builder_Node();
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$now        = \gmmktime( 9, 41, 0, 8, 27, 2026 );
		$fb->set_stats_store( $store );

		// First flush: probes, then folds up to its budget.
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );
		$this->forget_stats_asks();

		// Everything it folded, it now knows; only the rest can be probed.
		self::roll_up( $fb, (int) Core::$now );
		self::roll_up( $fb, (int) Core::$now );

		$folded = 0;
		foreach ( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'] as $hour ) {
			$folded += [] !== $store->server_index( [ $hour ], [] ) ? 1 : 0;
		}
		$this->assertGreaterThanOrEqual( 3, $folded, 'each flush folded its budget' );
	}

	/**
	 * An hour with no traffic is still written. A MISSING index reads as an
	 * hour the writer still owes, which the reader serves as lag and the
	 * roll-up folds again every pass.
	 */
	public function test_an_empty_hour_is_still_written_so_it_is_not_read_as_unfolded(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		Core::$now = \gmmktime( 14, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame(
			[ '2026-08-27-13' => [] ],
			$store->server_index( [ '2026-08-27-13' ], [] ),
			'its index is written naming no server, so the read finds it rather than falling back'
		);
	}

	/** The hour still filling has more to come; folding it would freeze it. */
	public function test_the_open_hour_is_not_rolled_up(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( [], $store->server_index( [ '2026-08-27-15' ], [] ) );
	}

	/**
	 * A stored URL row is POSITIONAL — the one test that reads a shard raw, so
	 * the shape is pinned somewhere even though every other test translates at
	 * the seed and read helpers. What it costs, and why, is decision 18's and
	 * the `ROW_*` docblock's; repeating the figure here is a third copy to keep
	 * true.
	 */
	public function test_a_stored_url_row_is_positional(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => 'https://edge-a.example/wombat-4471',
			'duration_ms' => 447.0,
			'timestamp'   => $now,
			'server_name' => 'edge-a.example',
		] ) );
		$fb->flush();

		$hash = Log_Manager::url_hash( 'https://edge-a.example/wombat-4471' );
		// RAW, not through the naming read helper: the shape is the assertion.
		$row  = $this->get_url_shard( $store, Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ), 'edge-a.example' )[ $hash ];
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_string' ) ),
			'no field NAMES in a stored row — that is the whole point'
		);
		$this->assertSame( '/wombat-4471', $row[ Stats_Store::ROW_PATH ], 'the path is the row\'s one string' );
	}

	public function test_url_index_caps_at_500_keeps_top_by_count(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		// 510 distinct URLs in one bucket. They all survive now: the cap is a
		// per-SHARD backstop against an oversized item, not a ceiling on how
		// many URLs a site may have in five minutes — which is what it was when
		// the whole bucket lived in one blob.
		for ( $i = 0; $i < 510; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => "/path-$i",
				'duration_ms' => 1.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$index  = $this->url_bucket_rows( $store, $bucket );
		$this->assertCount( 510, $index );
		foreach ( Stats_Store::url_shards() as $shard ) {
			$this->assertLessThanOrEqual( 500, \count( $this->get_url_shard( $store, $bucket, $shard ) ) );
		}
	}

	// --- merge_and_cap_dimensional Other rollover -------------------------

	public function test_dim_other_rollover_when_too_many_values(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		// 30 distinct user agents → exceeds MAX_DIM_VALUES (20) → Other rollover.
		for ( $i = 0; $i < 30; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'user_agent'  => "UA-$i",
				'duration_ms' => 10.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$dim    = $this->dim_series( $store, 'ua' );
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertArrayHasKey( $bucket, $dim );
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $dim[ $bucket ] ) );
		$this->assertArrayHasKey( 'Other', $dim[ $bucket ], 'low-frequency entries roll into Other' );
		$this->assertGreaterThan( 0, $dim[ $bucket ]['Other'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_a_stored_dimensional_entry_is_positional(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		for ( $i = 0; $i < 2; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'            => '/quartz',
				'request_method' => 'PATCH',
				'duration_ms'    => 41.5,
				'peak_mb'        => 2.25,
				'timestamp'      => $now,
			] ) );
		}
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		// Count, summed ms, summed peak MB, timed count — in DIM_COUNT /
		// DIM_SUM_MS / DIM_SUM_PEAK_MB / DIM_TIMED order, no key names stored.
		$this->assertSame(
			[ 2, 83.0, 4.5, 2 ],
			$this->get_hour_slot( $store, Stats_Store::dim_parts( 'method', '' ), $bucket )['PATCH']
		);
		$this->assertSame(
			[ 2, 83.0, 4.5, 2 ],
			$this->get_hour_slot( $store, Stats_Store::url_dim_parts( Log_Manager::url_hash( '/quartz' ) ), $bucket, 'method' )['PATCH']
		);
	}

	public function test_a_dimension_value_nothing_measured_is_not_stored(): void {
		// Every entry the builder writes is seeded by a request, so its count
		// is at least one. A stored entry in a shape the merge cannot name
		// reads its count as absent: a slot in the cap and a row in a chart
		// legend standing for nothing.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now       = self::tick();
		$bucket    = Stats_Store::bucket_key( $now );
		Core::$now = $now;
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'method', '' ), $bucket, [ 'PATCH' => [ 'c' => 61, 's' => 7.5, 'm' => 3.25 ] ] );
		$this->fill_request( $fb, $this->completed_request( [ 'request_method' => 'PUT', 'duration_ms' => 37.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$stored = $this->get_hour_slot( $store, Stats_Store::dim_parts( 'method', '' ), $bucket );
		$this->assertSame( 1, $stored['PUT'][ Stats_Store::DIM_COUNT ] ?? null, 'the merge ran' );
		$this->assertArrayNotHasKey( 'PATCH', $stored, 'a slot the merge could not name is dropped, not stored as zeros' );
	}

	public function test_a_category_nothing_measured_is_not_stored(): void {
		// `add_cat()` counts a request into every entry it folds, so a written
		// category has seen at least one. Zero means the merge could not name
		// the stored slot, and the entry stands for nothing.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now       = self::tick();
		$bucket    = Stats_Store::bucket_key( $now );
		Core::$now = $now;
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket, [ 'zither render' => [ 't' => 812.5, 'c' => 61, 'n' => 7 ] ] );
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 37.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$stored = $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket );
		$this->assertSame( 1, $stored['total'][ Stats_Store::CAT_REQUESTS ] ?? null, 'the merge ran' );
		$this->assertArrayNotHasKey( 'zither render', $stored, 'a slot the merge could not name is dropped, not stored as zeros' );
	}

	public function test_a_capped_dimension_keeps_its_BUSIEST_values(): void {
		// Ranked by the field NAME on a positional entry, `cap_bucket()`'s sort
		// compares nulls, ties every pair, degrades to insertion order and
		// folds the BUSIEST values into Other — invisible to a cap test that
		// only counts what survived.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		// Seeded busiest-LAST, so insertion order is the wrong answer.
		$now = self::tick();
		for ( $ua = 0; $ua < 15; $ua++ ) {
			for ( $hit = 0; $hit <= $ua; $hit++ ) {
				$this->fill_request( $fb, $this->completed_request( [
					'url'        => '/shared',
					'user_agent' => "ShUA-{$ua}",
					'timestamp'  => $now,
				] ) );
			}
		}
		$fb->flush();

		// The per-URL cap is the tighter of the two, so 15 values cross it.
		$kept = $this->get_hour_slot( $store, Stats_Store::url_dim_parts( Log_Manager::url_hash( '/shared' ) ), Stats_Store::bucket_key( $now ), 'ua' );
		$this->assertArrayHasKey( 'ShUA-14', $kept, 'the busiest value survives the cap' );
		$this->assertArrayNotHasKey( 'ShUA-0', $kept, 'the quietest folds into Other' );
	}

	public function test_url_dim_other_rollover_uses_tighter_cap(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		// 15 distinct UAs on the SAME URL → exceeds MAX_URL_DIM_VALUES (10) → Other rollover.
		for ( $i = 0; $i < 15; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/shared',
				'user_agent'  => "ShUA-$i",
				'duration_ms' => 10.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$url_hash = Log_Manager::url_hash( '/shared' );
		$bucket   = Stats_Store::bucket_key( $now );
		$url_dim  = $this->get_hour_slot( $store, Stats_Store::url_dim_parts( $url_hash ), $bucket, 'ua' );
		$this->assertNotSame( [], $url_dim );
		$this->assertLessThanOrEqual( Stats_Store::MAX_URL_DIM_VALUES, \count( $url_dim ) );
	}

	// --- category caps: Other rollover + total preserved ------------------

	public function test_categories_other_rollover_preserves_total(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		// 60 distinct categories → exceeds MAX_CAT_VALUES (50) → Other rollover.
		$profiles = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$profiles[ "cat$i" ] = [ 'time' => 0.01, 'count' => 1, 'entries' => [] ];
		}
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => $profiles,
		] ) );
		$fb->flush();

		$cats   = $this->cat_series( $store );
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertLessThanOrEqual( Stats_Store::MAX_CAT_VALUES, \count( $cats[ $bucket ] ) );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ], '"total" pseudo-category preserved' );
		$this->assertArrayHasKey( 'Other', $cats[ $bucket ], 'overflow rolls into Other' );
	}

	public function test_a_flush_leaves_category_buckets_it_did_not_fill_alone(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 3600, asker: $fb );
		$fb->set_stats_store( $store );

		$untouched = [
			'total' => self::cat_entry( 99, 99, 99 ),
			'old'   => self::cat_entry( 99, 99, 99 ),
		];
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), '1999-01-01-00-00', $untouched );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 5.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$this->assertSame( $untouched, $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $this->cat_series( $store ), 'and the request it did fill landed' );
	}

	// --- Per-server leaderboard merge + cap (hub mode) --------------------

	public function test_per_server_leaderboard_cap_global_upper_bound(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = self::tick();
		// Generate >100 distinct entry names under one category for one server.
		$entries = [];
		for ( $i = 0; $i < 120; $i++ ) {
			$entries[ "stmt-$i" ] = [ 0.01, 1 ];
		}
		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => 'srv-cap',
			'duration_ms' => 100.0,
			'timestamp'   => $now,
			'profiles'    => [
				'wpdb' => [ 'time' => 0.4, 'count' => 12, 'entries' => $entries ],
			],
		] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$lb_s   = $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ), 'srv-cap' );
		$this->assertArrayHasKey( 'wpdb', $lb_s['categories'] );
		$this->assertLessThanOrEqual(
			Flame_Builder_Node::ENTRY_LIMIT_GLOBAL_UPPER,
			\count( $lb_s['categories']['wpdb']['entries'] )
		);
	}

	public function test_hub_mode_per_server_categories_tracked(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => 'srv-cat',
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [
				'wpdb' => [ 'time' => 0.4, 'count' => 12, 'ts' => $now, 'entries' => [ 'SELECT' => [ 0.3, 8 ] ] ],
			],
		] ) );
		$fb->flush();

		$bucket    = Stats_Store::bucket_key( $now );
		$srv_cats  = $this->cat_series( $store, 'srv-cat' );
		$this->assertArrayHasKey( $bucket, $srv_cats );
		$this->assertArrayHasKey( 'wpdb', $srv_cats[ $bucket ] );
		$this->assertArrayHasKey( 'total', $srv_cats[ $bucket ], 'per-server "total" present' );
		$this->assertEqualsWithDelta( 0.4, $srv_cats[ $bucket ]['wpdb'][ Stats_Store::CAT_MS ], 1e-6 );
	}

	public function test_hub_mode_per_server_dim_skips_server_dim(): void {
		// In hub mode, per-server tracking should be populated for non-server dimensions
		// (status, method, country, etc.) but NOT for the 'server' dimension (redundant).
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name'  => 'srv-x',
			'request_method' => 'POST',
			'duration_ms'  => 25.0,
			'timestamp'    => $now,
		] ) );
		$fb->flush();

		// Per-server dim under 'method' should be populated.
		$dim_method = $this->dim_series( $store, 'method', 'srv-x' );
		$this->assertNotEmpty( $dim_method );

		// Per-server dim under 'server' should be EMPTY (skipped).
		$dim_server = $this->dim_series( $store, 'server', 'srv-x' );
		$this->assertEmpty( $dim_server, "per-server 'server' dim is skipped" );
	}

	// --- Per-URL aggregate reload ------------------------------------------

	public function test_flame_raw_promoted_to_flame_on_reload(): void {
		// Set store with an entry that has flame_raw set (post-flush format).
		$fb       = new Flame_Builder_Node();
		$store    = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$url      = '/promoted';
		$url_hash = Log_Manager::url_hash( $url );
		$this->set_url_stats( $store, $url_hash, [
			'flame_raw' => [
				'name'      => 'aggregate',
				'sum_value' => 300.0,
				'count'     => 3,
				'children'  => [],
			],
			'flame'     => [ /* finalized for display */
				'name'  => 'aggregate',
				'value' => 100.0,
				'count' => 3,
			],
			'profiles'  => [
				'count'        => 0,
				'sum_req_time' => 0.0,
				'categories'   => [],
			],
		] );

		$fb->set_stats_store( $store );

		// Hit the URL once more.
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 100.0,
		] ) );
		$fb->flush();

		$stats = $store->url_aggregate( $url_hash );
		// sum_value should be 300 + 100 = 400 (proves flame_raw was promoted and added to).
		$this->assertEqualsWithDelta( 400.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 4, $stats['flame_raw']['count'] );
	}

	// --- Stack depth safety + edge cases of build_flame_data --------------

	public function test_label_and_detail_attached_to_flame_nodes(): void {
		$fb      = new Flame_Builder_Node();
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
		$this->assertSame( 'wpdb query: SELECT * FROM wp_users WHERE id = 1', $child['detail'] );
	}

	public function test_label_equal_to_detail_skips_detail_field(): void {
		// If label and detail are identical, detail shouldn't be added.
		$fb      = new Flame_Builder_Node();
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
		$this->assertArrayNotHasKey( 'detail', $child, 'detail omitted when equal to label' );
	}

	public function test_store_flame_returns_true_without_target(): void {
		// When target/sink unset, store_flame returns true (aggregation continues)
		// without emitting a message.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 22.0 ] ) );
		$fb->flush();

		// Hourly was still populated → aggregation occurred even without flame write.
		$this->assertNotEmpty( $this->recent_hourly( $store ) );
	}

	// --- Plugin-suffix and callback-suffix exclusions ---------------------

	public function test_plugin_suffix_categories_skipped_from_auto_tune(): void {
		// Categories ending with " plugin" are not eligible for auto-tune.
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

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

	// --- format/parse index edge cases ------------------------------------

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

	// --- GET_STATS payload includes auto-tune queue depth -----------------

	public function test_get_stats_auto_tune_count_reflects_queue(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = new Flame_Builder_Node();
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

	// --- Per-server leaderboard tracks count when hub mode + server set ---

	public function test_per_server_leaderboard_skipped_when_server_name_empty(): void {
		// Hub mode but empty server_name → no per-server data.
		$fb    = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 86400, $fb ) );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => '',
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$this->assertNotEmpty( $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ) ), 'it still counts globally' );
		$hour_keys = static fn ( string $ns ): array => \array_values( \array_filter( $store->written, static fn ( string $key ): bool => \str_starts_with( $key, $ns . ':' ) ) );
		$this->assertNotSame( [], $hour_keys( Stats_Store::NS_LB_HOUR ), 'the global hour is written' );
		$this->assertSame( [], $hour_keys( Stats_Store::NS_LB_S_HOUR ), 'but no per-server scope is created for a nameless server' );
	}

	public function test_a_flush_writes_dim_and_cat_per_bucket_and_leaves_others_alone(): void {
		// Bucket-keyed maps under one key meant every flush rewrote the whole
		// series; retention is the key's own TTL now.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$stale_dim = [ '418' => self::dim_entry( 83, 9.5, 4.5 ) ];
		$stale_cat = [ 'sabbath' => self::cat_entry( 7.5, 61, 3 ) ];
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), '1999-01-01-00-00', $stale_dim );
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), '1999-01-01-00-00', $stale_cat );

		$now = self::tick();
		Core::$now = $now;
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 27.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.25, 'count' => 3, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$this->assertSame( $stale_dim, $store->get_slots( Stats_Store::dim_parts( 'status', '' ), [ Stats_Store::hour_of( '1999-01-01-00-00' ) ] )[ '1999-01-01-00-00' ] ?? [] );
		$this->assertSame( $stale_cat, $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $store->get_slots( Stats_Store::dim_parts( 'status', '' ), [ Stats_Store::hour_of( Stats_Store::bucket_key( $now ) ) ] )[ Stats_Store::bucket_key( $now ) ] ?? [], 'this flush landed' );
	}

	public function test_stats_time_the_request_not_the_flame_that_covers_it(): void {
		// The flame's value is raised to COVER its children so the treemap does
		// not overflow. That is a rendering rule; a stat must stay measured.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = 1_700_000_000;
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 137.0,
			'timestamp'   => $now,
			// A covering value far past the measured duration.
			'flame'       => [ 'name' => 'request', 'value' => 911.0, 'children' => [] ],
		] ) );
		$fb->flush();

		$this->assertEqualsWithDelta(
			137.0,
			$this->get_hour_slot( $store, Stats_Store::hourly_parts(), Stats_Store::bucket_key( $now ) )['sum_ms'] ?? 0.0,
			1e-6,
			'the request took 137ms; 911 is what the flame was stretched to'
		);
	}

	public function test_a_bucket_revisited_before_the_flush_keeps_both_halves(): void {
		// Bucket keys come from the request's START time and records arrive at
		// COMPLETION, so an older bucket is revisited constantly around a boundary.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$early = 1_700_000_000;
		$late  = $early + 300;
		foreach ( [ $early, $late, $early ] as $ts ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 23.0, 'timestamp' => $ts ] ) );
		}
		$fb->flush();

		$this->assertSame(
			2,
			$this->get_hour_slot( $store, Stats_Store::hourly_parts(), Stats_Store::bucket_key( $early ) )['count'] ?? 0,
			'both of the early bucket\'s requests counted'
		);
	}

	/**
	 * Decision 1: everything one flush touches in a time-keyed namespace
	 * shares the prefix `{ns}:{time}`, so it sits together in key order, and a
	 * URL's dimensions are one row a URL-hour, not one a dimension.
	 */
	public function test_a_flush_touches_its_keys_under_one_time_prefix_and_a_url_hour_once(): void {
		$fb    = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 86400, $fb ) );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );
		$now       = self::tick();
		Core::$now = $now;
		$bucket    = Stats_Store::bucket_key( $now );
		$hour      = Stats_Store::hour_of( $bucket );
		$paths     = [ '/kea-3301', '/moa-3302', '/tui-3303', '/weka-3304' ];
		foreach ( $paths as $i => $path ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'            => "https://kea.test{$path}",
				'server_name'    => 'kea.test',
				'timestamp'      => $now,
				'status_code'    => 503,
				'request_method' => 'PATCH',
				'country_code'   => 'NZ',
				'http_from'      => 'bot@kea.test',
				'user_agent'     => "kea-ua/{$i}",
				'ja4_hash'       => 't13d1516h2_8daaf6152771_e5627efa2ab1',
				'profiles'       => [ 'wpdb' => [ 'time' => 0.3, 'count' => 2, 'entries' => [] ] ],
			] ) );
		}
		$this->forget_stats_asks();
		$fb->flush();

		$asked   = $this->asked_keys( Stats_Store::NS_URL_DIM_HOUR );
		$written = \array_values( \array_filter( $store->written, static fn ( string $key ): bool => Stats_Store::NS_URL_DIM_HOUR === Stats_Store::namespace_of( $key ) ) );
		$this->assertCount( \count( $paths ), $written, 'one url_dim_h write a URL-hour, not one a dimension' );
		$this->assertCount( \count( $paths ), $asked, 'and one read' );
		foreach ( [ ...$asked, ...$written ] as $key ) {
			$this->assertStringStartsWith( "url_dim_h:{$hour}:", $key );
		}
		$timeless = [ Stats_Store::NS_URL, Stats_Store::NS_URLMAP ];
		$spaces   = [];
		foreach ( $store->written as $key ) {
			$ns = Stats_Store::namespace_of( $key );
			if ( ! \in_array( $ns, $timeless, true ) ) {
				$spaces[ $ns ] = true;
				$this->assertContains( \explode( ':', $key )[1], [ $hour, $bucket ], "{$key}: the time right after the namespace" );
			}
		}
		$this->assertEqualsCanonicalizing(
			[ 'urls', 'urlsrv', 'urlrank_s', 'urlhdr', 'hourly_h', 'dim_h', 'url_dim_h', 'categories_h', 'url_cat_h', 'lb_h', 'lb_sh' ],
			\array_keys( $spaces ),
			'every time-keyed namespace a hub flush writes'
		);
		$reads = \array_values( \array_filter( $this->asked_keys(), static fn ( string $key ): bool => ! \in_array( Stats_Store::namespace_of( $key ), $timeless, true ) ) );
		$this->assertNotSame( [], $reads );
		foreach ( $reads as $key ) {
			$at = \explode( ':', $key )[1] ?? '';
			if ( isset( $spaces[ Stats_Store::namespace_of( $key ) ] ) ) {
				$this->assertContains( $at, [ $hour, $bucket ], "{$key}: a read of what the flush writes asks its time right after the namespace" );
			} else {
				$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}-\d{2}(-\d{2})?$/D', $at, "{$key}: the time right after the namespace" );
			}
		}
	}

	public function test_each_url_dimension_fills_its_own_member_of_the_url_hour(): void {
		// One row a URL-hour, each dimension a slotted hour inside it.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		Core::$now = $now;
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/dims',
			'duration_ms' => 33.0,
			'timestamp'   => $now,
			'status'      => 503,
			'method'      => 'POST',
		] ) );
		$fb->flush();

		$hash   = Log_Manager::url_hash( '/dims' );
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertNotSame( [], $this->get_hour_slot( $store, Stats_Store::url_dim_parts( $hash ), $bucket, 'status' ) );
		$this->assertNotSame( [], $this->get_hour_slot( $store, Stats_Store::url_dim_parts( $hash ), $bucket, 'method' ), 'each dimension fills its own member' );
		$this->assertSame( [], $this->get_hour_slot( $store, Stats_Store::url_dim_parts( $hash ), $bucket, 'server' ), 'a URL belongs to one server, so it keeps no server axis' );
	}

	public function test_an_accumulated_other_is_not_clobbered_by_the_next_overflow(): void {
		// `Other` sums the evicted tail, so it sorts HIGH and survives into the
		// kept slice — assigning over it discards every earlier overflow.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$open   = self::tick();
		$bucket = Stats_Store::bucket_key( $open );

		// A stored bucket already carrying a fat Other, plus enough values
		// that the next cap has a tail to roll up.
		$values = [ 'Other' => self::dim_entry( 640, 0.0, 0.0 ) ];
		for ( $i = 0; $i <= Stats_Store::MAX_DIM_VALUES; $i++ ) {
			$values[ "v{$i}" ] = self::dim_entry( 100 + $i, 0.0, 0.0 );
		}
		Core::$now = $open;
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), $bucket, $values );
		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 418, 'timestamp' => $open ] ) );
		$fb->flush();

		$after = $store->get_slots( Stats_Store::dim_parts( 'status', '' ), [ Stats_Store::hour_of( $bucket ) ] )[ $bucket ] ?? [];
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $after ), 'still capped' );
		$this->assertGreaterThanOrEqual( 640, $after['Other'][ Stats_Store::DIM_COUNT ] ?? 0, 'the earlier overflow is still counted' );
	}

	// --- Save state after multiple flushes (idempotency) ------------------

	public function test_double_flush_is_idempotent(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$fb->flush();
		$snap_a = $this->recent_hourly( $store );
		// Second flush with nothing pending — should be a no-op for stats.
		$fb->flush();
		$snap_b = $this->recent_hourly( $store );
		$this->assertSame( $snap_a, $snap_b, 'second flush does not double-count' );
	}

	// --- Rule 2: sibling is sunk into the interpreter ---------------------

	public function test_sink_propagates_to_auto_tuner_sibling(): void {
		// make_node auto-sinks FlameBuilder into _command_interpreter; the
		// overridden sink() setter must propagate that sink to the owned
		// auto-tuner sibling so it routes like any other sibling (Rule 2c).
		$fb = new Flame_Builder_Node();
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
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );
		$this->assertSame( $capture, $fb->sink() );
	}

	/** A flush reads the open bucket back from the store and adds to what it holds. */
	public function test_the_flush_reads_an_open_bucket_back_before_adding_to_it(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$bucket = Stats_Store::bucket_key( self::tick() );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), $bucket, [ 'count' => 7719, 'sum_ms' => 3.0, 'sum_peak_mb' => 0 ] );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 419.0 ] ) );
		$fb->flush();

		$this->assertSame( 7720, $this->get_hour_slot( $store, Stats_Store::hourly_parts(), $bucket )['count'] ?? null );
	}

	public function test_save_state_without_partition_does_not_throw(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertSame( [ 'counted' => '', 'data_clock' => '', 'data_clock_hour_since' => 0.0 ], $fb->save_state() );
	}

	public function test_configure_stats_refuses_the_partition_it_no_longer_takes(): void {
		// A topology still passing one fails to load rather than drop it: the
		// verb declares no args, so the substrate's binder refuses the token.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'too many arguments: 1 given, 0 accepted' );
		$this->read_private( $fb, 'interpreter' )->dispatch( 'configure_stats', [ '3' ] );
	}

	public function test_configure_stats_builds_the_store_over_the_named_tables(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertStringNotContainsString( 'configure_stats', $fb->dump_config(), 'inert until configured' );
		$this->name_stats_tables( $fb );

		$result = $this->read_private( $fb, 'interpreter' )->dispatch( 'configure_stats', [] );

		$this->assertSame( 'ok', $result );
		$this->assertStringEndsWith(
			"command_node fb:config set_aggregate_target flame-stats:aggregate\n"
			. "command_node fb:config set_url_target flame-stats:url\n"
			. "command_node fb:config set_url_fine_target flame-stats:url-fine\n"
			. "command_node fb:config configure_stats\n",
			$fb->dump_config(),
			'the Tables replay ahead of the store built over them'
		);
	}

	/** An unnamed Table would take the store's writes to no node at all. */
	public function test_configure_stats_refuses_a_builder_whose_tables_were_never_named(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$interpreter = $this->read_private( $fb, 'interpreter' );
		$interpreter->dispatch( 'set_aggregate_target', [ Stats_Store::TABLE_AGGREGATE ] );

		try {
			$interpreter->dispatch( 'configure_stats', [] );
			$this->fail( 'configure_stats built a store with two Tables unnamed' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'configure_stats: no Table named by set_url_target, set_url_fine_target', $e->getMessage() );
		}
		$this->assertNull( $this->read_private( $fb, 'stats_store' ), 'no store over unnamed Tables' );
	}

	/**
	 * The readers mount each Table by role, so a write under any other name
	 * is one no dashboard reads: another role's Table, a stranger, or none.
	 *
	 * @return array<string,array{0: string, 1: string, 2: string}>
	 */
	public static function refused_stats_tables(): array {
		return [
			'another role\'s Table' => [ 'set_aggregate_target', Stats_Store::TABLE_URL, "set_aggregate_target: 'flame-stats:url' is not flame-stats:aggregate, the Table the performance readers mount" ],
			'a stranger'            => [ 'set_url_fine_target', 'wombat-stats:url-fine-4471', "set_url_fine_target: 'wombat-stats:url-fine-4471' is not flame-stats:url-fine, the Table the performance readers mount" ],
			// The binder refuses a blank for the required target first.
			'none'                  => [ 'set_url_target', '', 'missing required argument: target' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_stats_tables' )]
	public function test_a_stats_table_verb_refuses_a_table_the_readers_do_not_mount( string $verb, string $table, string $refusal ): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $refusal );
		$this->read_private( $fb, 'interpreter' )->dispatch( $verb, [ $table ] );
	}

	/** The console draws a Table edge from what the verbs named, not from a constant. */
	public function test_the_stats_tables_are_display_targets_once_their_verbs_name_them(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->target( 'flames:partition' );
		$this->assertSame( [ 'flames:partition' ], $fb->display_targets(), 'no edge to a Table nothing named' );

		$this->name_stats_tables( $fb );

		$this->assertSame( [ 'flames:partition', ...Stats_Store::TABLES ], $fb->display_targets() );
	}

	/** Each Table verb takes a `node_name`, which the document canvas draws as an edge. */
	public function test_each_stats_table_verb_takes_a_node_name(): void {
		$verbs = \array_column( Flame_Builder_Node::node_schema()['commands'], null, 'name' );
		foreach ( [ 'set_aggregate_target', 'set_url_target', 'set_url_fine_target' ] as $verb ) {
			$this->assertSame( [ 'node_name' ], \array_column( $verbs[ $verb ]['args'] ?? [], 'type' ), $verb );
		}
	}

	/** Name each stats Table through its verb, as `flame-builder.tsl` does. */
	private function name_stats_tables( Flame_Builder_Node $fb ): void {
		$interpreter = $this->read_private( $fb, 'interpreter' );
		foreach ( [
			'set_aggregate_target' => Stats_Store::TABLE_AGGREGATE,
			'set_url_target'       => Stats_Store::TABLE_URL,
			'set_url_fine_target'  => Stats_Store::TABLE_URL_FINE,
		] as $verb => $table ) {
			$this->assertSame( "ok\n", $interpreter->dispatch( $verb, [ $table ] ) );
		}
	}

	// --- Requests excluded from timing ------------------------------------

	/**
	 * An ABORTED request was killed partway — a worker cut off mid-job, or a
	 * gyrobase render whose lease was stolen — so its duration is a fragment of
	 * the real one. Counting it drags the mean down and invents fast
	 * requests that never happened, exactly like the timed-out case above.
	 */
	public function test_aborted_excluded_from_timing(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0, 'error_status' => 'A' ] ) );
		$fb->flush();

		foreach ( $this->recent_hourly( $store ) as $stats ) {
			$this->assertSame( 0, $stats['count'], 'aborted excluded from count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'aborted excluded from sum_ms' );
		}
	}

	public function test_workers_excluded_from_timing(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0, 'is_worker' => true ] ) );
		$fb->flush();

		$hourly = $this->recent_hourly( $store );
		foreach ( $hourly as $bucket => $stats ) {
			$this->assertSame( 0, $stats['count'], 'workers excluded from timing count' );
		}
	}

	public function test_worker_request_records_url_timing_but_no_global(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$req = $this->completed_request( [
			'url'         => '/?cache-cozy',
			'duration_ms' => 40.0,
			'is_worker'   => true,
			'peak_mb'     => 12.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.2, 'count' => 3, 'ts' => $now, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$bucket   = Stats_Store::bucket_key( $now );
		$url_hash = Log_Manager::url_hash( '/?cache-cozy' );

		// Per-URL timing IS kept for the synthetic worker row.
		$index = $this->url_bucket_rows( $store, $bucket );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 1, $index[ $url_hash ]['timed_count'] );
		$this->assertEqualsWithDelta( 40.0, $index[ $url_hash ]['sum_ms'], 1e-6 );

		// Global hourly: no count, no timing, and no peak (closed leak).
		foreach ( $this->recent_hourly( $store ) as $stats ) {
			$this->assertSame( 0, $stats['count'], 'worker excluded from global count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'worker excluded from global timing' );
			$this->assertSame( 0.0, $stats['sum_peak_mb'], 'worker peak leak closed' );
		}

		// Global dimensional: no count and no peak contribution.
		$dim = $this->dim_series( $store, 'status' );
		foreach ( $dim as $vals ) {
			foreach ( $vals as $cell ) {
				$this->assertSame( 0, $cell[ Stats_Store::DIM_COUNT ], 'worker excluded from global dimensional count' );
				$this->assertSame( 0, $cell[ Stats_Store::DIM_SUM_PEAK_MB ], 'worker excluded from global dimensional peak' );
			}
		}

		// Global leaderboard + categories: untouched by the worker.
		$this->assertEmpty( $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ) ) );
		$this->assertEmpty( $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket ) );
	}

	public function test_non_worker_request_records_global_count_and_peak(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/x', 'duration_ms' => 40.0, 'peak_mb' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$hourly = $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( Stats_Store::bucket_key( $now ) ) ] );
		$bucket = \array_keys( $hourly )[0];
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 40.0, $hourly[ $bucket ]['sum_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $hourly[ $bucket ]['sum_peak_mb'], 1e-6 );
	}

	public function test_per_server_tracking_only_when_hub(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );

		$fb_spoke = new Flame_Builder_Node();
		$fb_spoke->set_stats_store( $store );
		$fb_spoke->set_is_hub( false );

		$fb_hub = new Flame_Builder_Node();
		$fb_hub->set_stats_store( $store );
		$fb_hub->set_is_hub( true );

		// Use current time so the bucket alignment between fill and assertion is exact.
		$now = self::tick();
		$req = $this->completed_request( [
			'server_name' => 'srv-a',
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] );

		$this->fill_request( $fb_spoke, $req );
		$fb_spoke->flush();

		// Spoke: per-server bucket should be empty (no per-server tracking).
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertEmpty( $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ), 'srv-a' ) );

		// Hub: per-server bucket should be populated.
		$this->fill_request( $fb_hub, $req );
		$fb_hub->flush();
		$lb_s = $this->get_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ), 'srv-a' );
		$this->assertSame( 1, $lb_s['count'] ?? 0 );
	}

	public function test_disable_uses_the_stamped_rules_threshold(): void {
		// The governing rule's threshold — resolved by the request's stamped
		// rule_id — decides, and the proposal is keyed under that rule id.
		$this->set_rule( [ 'id' => 'loud', 'pattern' => '/loud/', 'auto_disable_threshold' => 100 ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => 'loud',
			'url'      => '/loud/x',
			'profiles' => [ 'noisy_hook hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_hook' ], $state['disable_hooks']['loud'] );
	}

	public function test_no_stamped_rule_applies_no_auto_tune(): void {
		// Stale stamped id + a url that matches no rule ⇒ null rule ⇒ no tuning.
		$this->set_rule( [ 'id' => 'quiet', 'pattern' => '/q/' ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => 'ghost',
			'url'      => '/unmatched',
			'profiles' => [ 'h hook' => [ 'time' => 0.1, 'count' => 999, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['disable_hooks'] );
	}

	public function test_noisy_hook_detection_threshold_zero_disables_check(): void {
		// With threshold 0, no hook ever gets proposed.
		$this->set_rule( [ 'auto_disable_threshold' => 0 ] );
		$fb = new Flame_Builder_Node();

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
		$fb = new Flame_Builder_Node();

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

	public function test_noisy_hook_detection_excludes_worker_requests(): void {
		// Auto-disable is a global signal: worker traffic feeds only its own
		// per-URL row, never the plugin-wide hooks_to_disable decision.
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'is_worker' => true,
			'profiles'  => [
				'wpdb hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['disable_hooks'], 'worker traffic must not drive global hook auto-disable' );
	}

	public function test_callback_categories_skipped_from_auto_tune(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();

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

	public function test_significant_event_detection_picks_up_slow_avg(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] ); // 50ms threshold
		$fb = new Flame_Builder_Node();

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

	/**
	 * The scan pre-filters on a raw column before parsing, so the offsets it
	 * slices with have to come from the writer that laid the line out.
	 */
	public function test_index_column_locates_the_matchable_fields_the_writer_wrote(): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [ 'rid' => 'qq4-flame-rid-8e02', 'url_hash' => 'c0ffee5eeded' ];
		$line = (string) Flame_Builder_Node::format_index_entry(
			$message,
			[ 'segment' => 7, 'offset' => 4096, 'length' => 55 ]
		);

		[ $rid_offset, $rid_length ]   = Flame_Builder_Node::index_column( 'rid' );
		[ $hash_offset, $hash_length ] = Flame_Builder_Node::index_column( 'url_hash' );

		$this->assertSame( 'qq4-flame-rid-8e02', \trim( \substr( $line, $rid_offset, $rid_length ) ) );
		$this->assertSame( 'c0ffee5eeded', \trim( \substr( $line, $hash_offset, $hash_length ) ) );
	}

	public function test_the_flame_index_carries_no_completion_columns(): void {
		// The flame line is rid(32) url_hash(12) segment(6) offset(10) length(8):
		// no timestamp anywhere, so a retention bound cannot read one off it and
		// offset 44 is `segment`, not a time.
		$this->assertSame( [], Flame_Builder_Node::index_completion_columns() );
	}

	public function test_index_column_offers_no_column_for_a_zero_padded_field(): void {
		// `segment` is zero-padded, so its column never equals its parsed int.
		$this->assertSame( [], Flame_Builder_Node::index_column( 'segment' ) );
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

	// --- Category bucket size: rounded milliseconds, positional fields ------

	/** A category bucket the flush wrote reads back as the aggregate it folded. */
	public function test_a_stored_category_bucket_round_trips_its_aggregate(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$bucket = self::live_bucket();
		$stored = [
			'zither render'  => [
				Stats_Store::CAT_MS       => 913.207,
				Stats_Store::CAT_CALLS    => 47,
				Stats_Store::CAT_REQUESTS => 11,
			],
			'quokka dispatch' => [
				Stats_Store::CAT_MS       => 12.049,
				Stats_Store::CAT_CALLS    => 3,
				Stats_Store::CAT_REQUESTS => 2,
			],
		];
		$this->flush_buckets( $fb, [ $bucket => [ 'cat' => $stored ] ] );

		$this->assertSame( $stored, $store->get_slots( Stats_Store::cat_parts( '' ), [ Stats_Store::hour_of( $bucket ) ] )[ $bucket ] ?? null, 'the aggregate survives the round trip unchanged' );
	}

	/**
	 * A category's milliseconds are rounded where the value is stored, so a
	 * full-precision double never reaches the frame.
	 */
	public function test_category_milliseconds_are_stored_at_display_precision(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 500.51303300000006,
			'timestamp'   => $now,
			'profiles'    => [
				'zither render' => [
					'time'    => 41.903852000000015,
					'count'   => 7,
					'ts'      => $now,
					'entries' => [],
				],
			],
		] ) );
		$fb->flush();

		$cats = $this->cat_series( $store )[ Stats_Store::bucket_key( $now ) ];
		$this->assertSame( 41.904, $cats['zither render'][ Stats_Store::CAT_MS ], 'the category time is rounded' );
		$this->assertSame( 500.513, $cats['total'][ Stats_Store::CAT_MS ], 'and so is the rollup' );
		$this->assertSame( 7, $cats['zither render'][ Stats_Store::CAT_CALLS ], 'a count is untouched' );
		$this->assertSame( 1, $cats['zither render'][ Stats_Store::CAT_REQUESTS ], 'and so is a sample count' );
	}

	/**
	 * A category is stored positionally, its milliseconds cut to
	 * `CAT_MS_DECIMALS`: these twenty serialize to 1,214 bytes, where full
	 * double precision takes 1,387 and named fields 1,867.
	 */
	public function test_a_stored_category_bucket_stays_under_its_byte_budget(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );

		$cats = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$cats[ "zither stage {$i}" ] = [
				Stats_Store::CAT_MS       => 913.207 + ( $i / 7 ),
				Stats_Store::CAT_CALLS    => 47 + $i,
				Stats_Store::CAT_REQUESTS => 11 + $i,
			];
		}
		$this->flush_buckets( $fb, [ self::live_bucket() => [ 'cat' => $cats ] ] );

		$stored = $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), self::live_bucket() );
		$this->assertCount( 20, $stored );
		$this->assertLessThanOrEqual( 1250, \strlen( \serialize( $stored ) ), 'twenty categories in one bucket' );
	}

	public function test_a_flush_files_the_tokens_of_the_names_it_wrote(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731' ] ) );
		$fb->flush();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-8842' ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://moa.test/wombat-5510', 'server_name' => 'moa.test' ] ) );
		$fb->flush();

		$this->assertSame(
			[ 'wombat' => [ Log_Manager::url_hash( 'https://moa.test/wombat-5510' ) ] ],
			$store->url_token_sets( [ 'wombat' ], [ 'moa.test' ], self::tick() ),
			'each server files its own'
		);
		$sets = $store->url_token_sets( [ 'wombat', '7731', '8842' ], [ self::SEED_SERVER ], self::tick() ) + $store->url_token_sets( [ 'womb', '884' ], [ self::SEED_SERVER ], self::tick() );
		$a    = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$b    = Log_Manager::url_hash( 'https://kea.test/wombat-8842' );
		$this->assertSame( [ $a, $b ], $sets['wombat'], 'the second flush unions' );
		$this->assertSame( [ $a ], $sets['7731'] );
		$this->assertSame( [ $b ], $sets['8842'] );
		$this->assertArrayNotHasKey( 'womb', $sets, 'no prefix is filed' );
		$this->assertArrayNotHasKey( '884', $sets, 'no prefix is filed' );
	}

	public function test_a_url_files_its_words_once_an_hour_and_again_the_next(): void {
		// Two flushes in one hour name the same URL: one SADD and one name
		// row. The next hour's first flush refreshes both, once more.
		$fb    = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 43_219, $fb ) );
		$fb->set_stats_store( $store );
		$url   = 'https://kea.test/wombat-7731';
		$named = static fn (): int => \count( \array_filter( $store->written, static fn ( string $key ): bool => \str_starts_with( $key, Stats_Store::NS_URLMAP . ':' ) ) );
		$at    = \gmmktime( 14, 7, 31, 9, 22, 2026 );

		foreach ( [ $at, $at + 1_517 ] as $now ) {
			Core::$now = $now;
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => $now ] ) );
			$fb->flush();
		}
		$this->assertSame( 1, $store->token_adds, 'two flushes in one hour add once' );
		$this->assertSame( 1, $named(), 'and write the name once' );

		Core::$now = \gmmktime( 15, 0, 4, 9, 22, 2026 );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => (int) Core::$now ] ) );
		$fb->flush();
		$this->assertSame( 2, $store->token_adds, 'the next hour adds again' );
		$this->assertSame( 2, $named(), 'and refreshes the name' );
	}

	public function test_a_recycled_builder_does_not_refile_inside_the_hour(): void {
		// The stamp rides the URL blob, so a fresh builder with no memory of
		// the last one reads it and adds nothing until the hour turns.
		$first = new Flame_Builder_Node();
		$first->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 43_219, asker: $first ) );
		$url       = 'https://kea.test/wombat-7731';
		$at        = \gmmktime( 14, 7, 31, 9, 22, 2026 );
		Core::$now = $at;
		$this->fill_request( $first, $this->completed_request( [ 'url' => $url, 'timestamp' => $at ] ) );
		$first->flush();

		$fresh = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 43_219, $fresh ) );
		$fresh->set_stats_store( $store );
		Core::$now = $at + 1_811;
		$this->fill_request( $fresh, $this->completed_request( [ 'url' => $url, 'timestamp' => (int) Core::$now ] ) );
		$fresh->flush();

		$this->assertSame( 0, $store->token_adds, 'filed this hour by the builder before it' );
		$this->assertSame( [], \array_values( \array_filter( $store->written, static fn ( string $key ): bool => \str_starts_with( $key, Stats_Store::NS_URLMAP . ':' ) ) ), 'nor is the name written' );
		$this->assertSame(
			[ Stats_Store::server_key( self::SEED_SERVER ) => '2026-09-22-14' ],
			$store->url_aggregate( Log_Manager::url_hash( $url ) )['filed'] ?? null,
			'the blob carries the hour, by the server its words are filed under'
		);
	}

	public function test_a_flushed_url_is_found_by_its_whole_word_and_not_its_start(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 43_219, asker: $fb );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 14, 7, 31, 9, 22, 2026 );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'timestamp' => (int) Core::$now ] ) );
		$fb->flush();

		$this->assertSame(
			[ 'wombat' => [ Log_Manager::url_hash( 'https://kea.test/wombat-7731' ) ] ],
			$store->url_token_sets( [ 'wombat', 'wom' ], [ self::SEED_SERVER ], (int) Core::$now )
		);
	}

	/**
	 * A fatal is an error: `errors_only` keeps the URL whose one record
	 * fataled, though the record carries a 500 and a measured duration. Its
	 * duration still times it, so the row is errored AND timed.
	 */
	public function test_errors_only_keeps_a_url_whose_one_record_fataled(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 43_219 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$memd                   = new InMemoryMemcached();
		$GLOBALS['_current_user_can'] = true;
		$clock                        = Core::$clock;
		Core::$clock                  = static fn (): int => (int) Core::$now;
		try {
			Core::$now = \gmmktime( 14, 7, 31, 9, 22, 2026 );
			$fb        = new Flame_Builder_Node();
			$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 43_219, asker: $fb ) );
			$this->fill_request( $fb, $this->completed_request( [
				'url'          => 'https://kea.test/kakapo-4417',
				'timestamp'    => (int) Core::$now,
				'duration_ms'  => 1540.0,
				'status_code'  => 500,
				'error_status' => 'F',
			] ) );
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => 'https://kea.test/tui-2208',
				'timestamp'   => (int) Core::$now,
				'duration_ms' => 870.0,
				'status_code' => 503,
			] ) );
			$fb->flush();

			foreach ( [ 'ranked' => '--limit=100', 'folded' => '--limit=' . ( Stats_Store::URL_RANK_N + 1 ) ] as $path => $limit ) {
				$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--errors_only=1', $limit ] );
				$this->assertSame( [ Log_Manager::url_hash( 'https://kea.test/kakapo-4417' ) ], \array_column( $page['data'], 'hash' ), "{$path}: the fatal, and not the clean 503" );
				$this->assertSame( 1, $page['data'][0]['errors'], $path );
				$this->assertSame( 1, $page['data'][0]['timed_count'], "{$path}: a fatal's duration is a timing sample" );
				$this->assertSame( 1, $page['totals']['errors'], $path );
			}
		} finally {
			Core::$clock = $clock;
			unset( $GLOBALS['_current_user_can'] );
		}
	}

	public function test_a_url_seen_late_in_its_last_filed_hour_stays_findable_until_its_rows_leave_the_window(): void {
		// Filed at 14:02:07 and last seen at 14:58:41, so its words are never
		// re-added. Its hour-14 rows are read until 02:09:59 the next day, a
		// window of 43,219 s; the words must live that long too, and no later.
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 43_219 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$memd                   = new InMemoryMemcached();
		$GLOBALS['_current_user_can'] = true;
		$clock                        = Core::$clock;
		Core::$clock                  = static fn (): int => (int) Core::$now;
		try {
			$fb = new Flame_Builder_Node();
			$fb->set_stats_store( $this->stats_store( partition: 0, max_lifespan: 43_219, asker: $fb ) );
			$flush_at = function ( int $at, string $url ) use ( $fb ): void {
				Core::$now = $at;
				$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => $at ] ) );
				$fb->flush();
			};
			$flush_at( \gmmktime( 14, 2, 7, 9, 22, 2026 ), 'https://kea.test/wombat-7731' );
			$flush_at( \gmmktime( 14, 58, 41, 9, 22, 2026 ), 'https://kea.test/wombat-7731' );
			// Hour 15's traffic moves the data clock on, so hour 14 folds.
			$flush_at( \gmmktime( 15, 3, 17, 9, 22, 2026 ), 'https://kea.test/kiwi-8842' );
			$flush_at( \gmmktime( 15, 3, 22, 9, 22, 2026 ), 'https://kea.test/kiwi-8842' );
			$wombat = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
			$found  = static function ( int $at ): array {
				Core::$now = $at;
				return \array_column( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wombat' ] )['data'], 'hash' );
			};

			$last = \gmmktime( 2, 9, 59, 9, 23, 2026 );
			$this->assertSame( \gmmktime( 14, 0, 0, 9, 22, 2026 ), Stats_Store::window_start( 43_219, $last ), 'hour 14 is still read' );
			$this->assertSame( [ $wombat ], $found( $last ), 'findable while its last row is read' );
			$this->assertSame( \gmmktime( 15, 0, 0, 9, 22, 2026 ), Stats_Store::window_start( 43_219, $last + 5 ), 'hour 14 has left the window' );
			$this->assertSame( [], $found( $last + 5 ), 'gone once it has' );
		} finally {
			Core::$clock = $clock;
			unset( $GLOBALS['_current_user_can'] );
		}
	}

	public function test_a_flushs_members_share_one_six_hour_bucket(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 43_219, asker: $fb );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 16, 47, 13, 9, 22, 2026 );
		foreach ( [ 'https://kea.test/wombat-7731', 'https://kea.test/kiwi-8842/nest', 'https://moa.test/takahe-5510' ] as $url ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => (int) Core::$now ] ) );
		}
		$this->forget_stats_asks();
		$fb->flush();

		$keys = \array_merge( ...$this->asked_verbs( Stats_Store::NS_URLTOKEN )['SADD'] ?? [ [] ] );
		$this->assertCount( 7, $keys, 'wombat, 7731, kiwi, 8842, nest, takahe, 5510' );
		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( Stats_Store::NS_URLTOKEN . ':2026-09-22-12:', $key );
		}
	}

	public function test_an_all_digit_token_and_hash_round_trip_as_strings(): void {
		// PHP keys an array by INT wherever the key spells a number, so an
		// all-digit path token and the one URL in ~220 whose hash is twelve
		// digits both leave the flush as ints unless something types them back.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$url = 'https://kea319.test/20260922';
		$this->assertSame( '481169627974', Log_Manager::url_hash( $url ), 'the fixture is the all-digit case' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		$fb->flush();

		$this->assertSame( [ '481169627974' ], $store->url_token_sets( [ '20260922' ], [ self::SEED_SERVER ], self::tick() )['20260922'] ?? null );
	}

	public function test_a_second_flush_adds_members_only_for_its_new_pairs_and_reads_nothing_first(): void {
		// The set is never read back and rewritten: a flush adds one member
		// per new (word, URL) pair, blind, and a URL already filed adds none.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86_417, asker: $fb );
		$fb->set_stats_store( $store );
		$old = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$new = Log_Manager::url_hash( 'https://kea.test/wombat-4486' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731' ] ) );
		$fb->flush();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731' ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-4486' ] ) );
		$this->forget_stats_asks();
		$fb->flush();

		$set = static fn ( string $word ): string => Stats_Store::key_at( [ ...Stats_Store::url_token_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $word ], Stats_Store::token_bucket( self::tick() ) );
		$this->assertSame( [ 'SADD' => [ [ $set( 'wombat' ), $set( '4486' ) ] ] ], $this->asked_verbs( Stats_Store::NS_URLTOKEN ), 'one add, and no read' );
		$this->assertSame(
			[
				$set( 'wombat' ) => [ [ $new => self::tick() ], 86_417 + 3_605 ],
				$set( '4486' )   => [ [ $new => self::tick() ], 86_417 + 3_605 ],
			],
			self::token_adds()[0],
			'the new URL alone, living the window, an hour and a flush'
		);
		$this->assertEqualsCanonicalizing( [ $old, $new ], Core::arr( $store->url_token_sets( [ 'wombat' ], [ self::SEED_SERVER ], self::tick() )['wombat'] ?? null ) );
	}

	public function test_a_refused_token_write_is_logged_and_not_written_again(): void {
		// Nothing retries a refused write: the refusal is logged, the URL's
		// blob still carries the hour, and a flush later in it files nothing.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$fb    = new Flame_Builder_Node();
		$store = new RecordingStatsStore( ...$this->stats_store_args( 0, 86400, $fb ) );
		$fb->set_stats_store( $store );
		$url = 'https://kea.test/takahe-4410';
		// Tells the minute's narration, so the tally below stays readable.
		$fb->flush();

		$store->refuse_tokens = true;
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		$fb->flush();
		$this->assertSame( [], $store->url_token_sets( [ 'takahe' ], [ self::SEED_SERVER ], self::tick() ), 'the write was refused' );
		$this->assertStringContainsString( 'token index write refused; 2 word sets left unfiled', $err );
		$this->assertSame( 2, $this->get_stats( $fb )['narration'][ Flame_Tree::STATS_WRITES ][ 'refused ' . Stats_Store::NS_URLTOKEN ] ?? null );

		$store->refuse_tokens = false;
		$store->writes        = [];
		Core::$now           += 41;
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => self::tick() ] ) );
		$fb->flush();

		$this->assertNotContains( 'takahe', $store->writes, 'the refused token is not written again' );
	}

	public function test_a_flush_files_one_set_per_distinct_word_in_batched_adds(): void {
		// 600 URLs sharing `kea` and `kiwi`, each with a word of its own, is
		// 602 sets: added in ceil( 602 / 500 ) SADDs, never one per word, and
		// read by none.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( partition: 0, max_lifespan: 86400, asker: $fb );
		$fb->set_stats_store( $store );
		$stats = [];
		$names = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$hash           = \sprintf( 'e%011x', 0x7731 + $i );
			$stats[ $hash ] = self::positional_url_row( [ 'count' => 3 ] );
			$names[ $hash ] = "https://example.com/kea-{$i}0-kiwi";
		}
		$this->forget_stats_asks();
		$this->flush_buckets( $fb, [ self::live_bucket() => [ 'url_stats' => $stats, 'url_names' => $names ] ] );

		$this->assertSame( [ 'SADD' ], \array_keys( $this->asked_verbs( Stats_Store::NS_URLTOKEN ) ), 'no set is read' );
		$this->assertSame( [ 500, 102 ], \array_map( 'count', self::token_adds() ), 'the sets are added in the flush chunks' );
		$this->assertSame( 602, $this->get_stats( $fb )['narration'][ Flame_Tree::STATS_WRITES ]['tokens'] ?? null, 'one set a distinct word' );
		$own = [ '00', ...\array_map( 'strval', \range( 10, 5990, 10 ) ) ];
		$read = [];
		foreach ( \array_chunk( [ 'kea', 'kiwi', ...$own ], Stats_Store::SEARCH_WORDS_READ ) as $words ) {
			$read += \array_filter( $store->url_token_sets( $words, [ self::SEED_SERVER ], self::tick() ) );
		}
		$this->assertSame( [ 'kea', 'kiwi', ...$own ], \array_map( 'strval', \array_keys( $read ) ), 'each word its set' );
	}

	/**
	 * Each recorded `SADD`: set key => [ member map, ttl ].
	 *
	 * @return list<array<array-key,mixed>>
	 */
	private static function token_adds(): array {
		$adds = [];
		foreach ( VerbHarness::ask_recorder()->asked as $asked ) {
			if ( \is_array( $asked['value'] ) && isset( $asked['value']['SADD'] ) ) {
				$adds[] = Core::arr( $asked['value']['SADD'] );
			}
		}
		return $adds;
	}

	/**
	 * One field summed across an hour's slots, or null where no slot holds
	 * it: a DIM_SUMS value's count by its name, or a totals field.
	 *
	 * @param array<string,array<array-key,mixed>> $slots Bucket => slot, as a reader lays an hour out.
	 * @param string                               $field A dimension value, or `count`.
	 */
	private static function hour_count( array $slots, string $field ): ?int {
		$counts = [];
		foreach ( $slots as $slot ) {
			$held = $slot[ $field ] ?? null;
			if ( null !== $held ) {
				$counts[] = \is_array( $held ) ? Core::num_int( $held[ Stats_Store::DIM_COUNT ] ?? null ) : Core::num_int( $held );
			}
		}
		return [] === $counts ? null : \array_sum( $counts );
	}

	/** A builder over a SQLite-backed store, named and sunk as a worker's is. */
	private function slot_builder( Stats_Store $store ): Flame_Builder_Node {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		return $fb;
	}

	/**
	 * Flush `$requests` requests at 14:`$minute`:11 UTC on 2026-09-29, each
	 * built by `$request`.
	 *
	 * @param list<array{0: int, 1: int}>        $flushes Minute => request count, in order.
	 * @param \Closure(int): array<string,mixed> $request The i-th request's overrides.
	 */
	private function flush_at_minutes( Flame_Builder_Node $fb, array $flushes, \Closure $request ): void {
		foreach ( $flushes as [ $minute, $requests ] ) {
			Core::$now = (float) \gmmktime( 14, $minute, 11, 9, 29, 2026 );
			for ( $i = 0; $i < $requests; $i++ ) {
				$this->fill_request( $fb, $this->completed_request( $request( $i ) ) );
			}
			$fb->flush();
		}
	}

	public function test_a_flush_places_each_bucket_in_its_slot_of_the_hour_value(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = $this->slot_builder( $store );

		$this->flush_at_minutes( $fb, [ [ 37, 41 ], [ 47, 43 ] ], static fn ( int $i ): array => [ 'user_agent' => 'kea-ua/7' ] );

		$hour = $store->bucket_get_multi( [ [ Stats_Store::dim_parts( 'ua', '' ), '2026-09-29-14' ] ] )[0] ?? [];
		$this->assertSame( [ 7, 9 ], \array_keys( $hour ), 'the 14:35 bucket is slot 7, the 14:45 bucket slot 9' );
		$this->assertSame( 41, $hour[7]['kea-ua/7'][ Stats_Store::DIM_COUNT ] ?? null );
		$this->assertSame( 43, $hour[9]['kea-ua/7'][ Stats_Store::DIM_COUNT ] ?? null );
		$this->assertNull( $store->bucket_get_multi( [ [ [ 'dim', 'ua' ], '2026-09-29-14-35' ] ] )[0], 'no fine chart bucket is written' );
	}

	public function test_a_dimension_row_counts_its_timed_requests_apart(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = $this->slot_builder( $store );
		$shape = [ [ 'duration_ms' => 40.0 ], [ 'duration_ms' => 60.0 ], [ 'duration_ms' => 999.0, 'error_status' => 'T' ] ];

		$this->flush_at_minutes( $fb, [ [ 37, 3 ] ], static fn ( int $i ): array => [ 'user_agent' => 'kea-ua/7', ...$shape[ $i ] ] );

		$row = $this->get_hour_slot( $store, Stats_Store::dim_parts( 'ua', '' ), '2026-09-29-14-35' )['kea-ua/7'] ?? [];
		$this->assertSame( 3, $row[ Stats_Store::DIM_COUNT ] ?? null, 'every request counts for volume' );
		$this->assertSame( 2, $row[ Stats_Store::DIM_TIMED ] ?? null, 'the timeout is no sample' );
		$this->assertEqualsWithDelta( 50.0, ( $row[ Stats_Store::DIM_SUM_MS ] ?? 0 ) / ( $row[ Stats_Store::DIM_TIMED ] ?? 1 ), 1e-9 );
	}

	public function test_the_hourly_slot_counts_every_request_its_peak_sums(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = $this->slot_builder( $store );
		$shape = [
			[ 'duration_ms' => 40.0, 'peak_mb' => 10.5 ],
			[ 'duration_ms' => 60.0, 'peak_mb' => 20.5 ],
			[ 'duration_ms' => 999.0, 'peak_mb' => 30.5, 'error_status' => 'T' ],
		];

		$this->flush_at_minutes( $fb, [ [ 37, 3 ] ], static fn ( int $i ): array => $shape[ $i ] );

		$slot = $this->get_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-29-14-35' );
		$this->assertSame( 2, $slot['count'] ?? null, 'the timed requests' );
		$this->assertSame( 3, $slot['requests'] ?? null, 'every request the peak covers' );
		$this->assertEqualsWithDelta( 61.5, $slot['sum_peak_mb'] ?? null, 1e-9 );
	}

	public function test_each_slot_keeps_its_own_cap_of_values(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = $this->slot_builder( $store );

		$this->flush_at_minutes( $fb, [ [ 37, Stats_Store::MAX_DIM_VALUES ] ], static fn ( int $i ): array => [ 'user_agent' => "kea-ua/{$i}" ] );
		$this->flush_at_minutes( $fb, [ [ 47, Stats_Store::MAX_DIM_VALUES ] ], static fn ( int $i ): array => [ 'user_agent' => "weka-ua/{$i}" ] );

		$hour = $store->bucket_get_multi( [ [ Stats_Store::dim_parts( 'ua', '' ), '2026-09-29-14' ] ] )[0] ?? [];
		$this->assertCount( Stats_Store::MAX_DIM_VALUES, $hour[7] ?? [] );
		$this->assertCount( Stats_Store::MAX_DIM_VALUES, $hour[9] ?? [], 'a summed hour would have folded twenty of the forty' );
		$this->assertArrayNotHasKey( 'Other', $hour[9] ?? [] );
	}

	public function test_the_leaderboard_hour_stays_one_sum(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$fb    = $this->slot_builder( $store );

		$this->flush_at_minutes( $fb, [ [ 37, 41 ], [ 47, 43 ] ], static fn ( int $i ): array => [
			'profiles' => [ 'wpdb' => [ 'time' => 0.4, 'count' => 3, 'ts' => (int) Core::$now, 'entries' => [] ] ],
		] );

		$board = $this->get_leaderboard_hour( $store, '2026-09-29-14' );
		$this->assertSame( 84, $board['count'] ?? null, 'both buckets summed into the hour' );
		$this->assertArrayNotHasKey( 7, $board );
		$this->assertNull( $store->bucket_get_multi( [ [ [ 'lb' ], '2026-09-29-14-35' ] ] )[0], 'no fine leaderboard bucket is written' );
	}

	public function test_a_late_write_into_a_folded_hour_missing_its_row_shard_creates_it(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hash  = Log_Manager::url_hash( '/evicted-hour-6071' );
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/evicted-hour-6071', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0 ],
		] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), $shard ), '2026-08-27-13' ] ] );

		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/evicted-hour-6071', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0 ] ),
		] );

		$hour = self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard ) );
		$this->assertSame( 7, $hour[ $hash ]['count'] ?? null, 'the late rows are the missing key\'s rows' );
	}
}

/**
 * A Flame_Builder whose intern table fills after three names, so a test can
 * reach the freeze without pushing 50000 distinct strings through it.
 */
class TinyInternFlameBuilder extends Flame_Builder_Node {
	protected const INTERN_TABLE_LIMIT = 3;
}

/**
 * A Stats_Store that records every key a flush wrote and every word it
 * filed, and can refuse the word filings outright.
 */
class RecordingStatsStore extends Stats_Store {
	/** @var list<string> Words filed since a test last zeroed it. */
	public array $writes = [];

	/** @var list<string> Every key asked to be written since a test last zeroed it. */
	public array $written = [];

	/** @var bool Whether a word filing is refused. */
	public bool $refuse_tokens = false;

	/** @var int `add_url_tokens()` calls since a test last zeroed it: one SADD each. */
	public int $token_adds = 0;

	/**
	 * @param array<int,array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes `[ parts, bucket, data ]`.
	 * @return array<int,bool>
	 */
	public function bucket_set_multi( array $writes ): array {
		foreach ( $writes as [ $parts, $bucket ] ) {
			$this->written[] = Stats_Store::key_at( $parts, $bucket );
		}
		return parent::bucket_set_multi( $writes );
	}

	/**
	 * @param array<array-key,array<array-key,string>> $servers Filed server => hash => URL.
	 * @return array<int,bool>
	 */
	public function set_url_names( array $servers ): array {
		foreach ( $servers as $urls ) {
			foreach ( \array_keys( $urls ) as $hash ) {
				$this->written[] = Stats_Store::key_at( [ Stats_Store::NS_URLMAP ], (string) $hash );
			}
		}
		return parent::set_url_names( $servers );
	}

	/**
	 * @param list<array{0: string, 1: string, 2: list<string>}> $sets `[ server_key, word, hashes ]`.
	 * @return array<int,bool>
	 */
	public function add_url_tokens( array $sets, int $now ): array {
		++$this->token_adds;
		foreach ( $sets as [ , $word ] ) {
			$this->writes[] = $word;
		}
		return $this->refuse_tokens ? \array_fill( 0, \count( $sets ), false ) : parent::add_url_tokens( $sets, $now );
	}
}

/**
 * A Stats_Store that arms the cooperative stop when a write lands in one
 * bucket, so the next Partition write — a narration line — raises it.
 */
class StopArmingStatsStore extends Stats_Store {
	/** @var string The bucket whose first write arms the stop; '' arms none. */
	public string $stop_at = '';

	/** @var bool Whether the stop was armed. */
	public bool $armed = false;

	/** @var (\Closure(): void)|null The disarm, while armed. */
	private ?\Closure $disarm = null;

	/**
	 * @param array<int,array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes `[ parts, bucket, data ]`.
	 * @return array<int,bool>
	 */
	public function bucket_set_multi( array $writes ): array {
		if ( ! $this->armed && '' !== $this->stop_at && \in_array( $this->stop_at, \array_column( $writes, 1 ), true ) ) {
			$this->armed  = true;
			$this->disarm = FlameBuilderTest::arm_stop_on_next_write();
		}
		return parent::bucket_set_multi( $writes );
	}

	/** Lift the stop, armed or not. */
	public function disarm(): void {
		$this->disarm && ( $this->disarm )();
		$this->disarm = null;
	}
}

