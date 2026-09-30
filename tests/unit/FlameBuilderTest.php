<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
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
	 * The builder `flame-builder.tsl` makes appends to the Ledgers that file
	 * declares, in its own graph, through the client `configure_stats`
	 * hands its store: a reader's mount of the file reads what it settled.
	 */
	public function test_the_shipped_builder_settles_into_its_declared_ledgers(): void {
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
		$fb->settle();

		$hourly = $this->hourly_at( $this->stats_reader(), self::tick() );
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

	public function test_a_reply_from_a_stats_store_is_never_folded_as_a_record(): void {
		$store = $this->stats_store( 0 );
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		$reply                   = Message::new_message();
		$reply[ Message::TYPE ]  = Message::TM_STRUCT;
		$reply[ Message::FROM ]  = Stats_Store::TABLE_URL;
		$reply[ Message::KEY ]   = 'c0ffee7731ab';
		$reply[ Message::VALUE ] = $this->completed_request( [] );
		$fb->fill( $reply );
		$fb->settle();
		$this->assertSame( [], $this->hourly_at( $store, self::tick() ) );
		$this->assertSame( 0, $fb->counter(), 'a store\'s reply is not a record the builder counts' );
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

	/** Every read here spans the hour to the tick and the bucket after it. */
	private static function window(): array {
		return [ self::tick() - 3600, self::tick() + 300 ];
	}

	/** A read keyed by bucket start, keyed by `bucket_key()` instead. */
	private static function keyed( array $by_t ): array {
		$out = [];
		foreach ( $by_t as $t => $value ) {
			$out[ Stats_Store::bucket_key( (int) $t ) ] = $value;
		}
		return $out;
	}

	/** The site totals each bucket of the window holds, by bucket key. */
	private function recent_hourly( Stats_Store $store ): array {
		[ $from, $to ] = self::window();
		return self::keyed( $store->totals( $from, $to, true ) );
	}

	/** The site totals of the bucket `$t` falls in; [] when it holds none. */
	private function hourly_at( Stats_Store $store, int $t ): array {
		return $store->totals( Stats_Store::bucket_start( $t ), Stats_Store::bucket_start( $t ) + Stats_Store::BUCKET_SECONDS, true )[ Stats_Store::bucket_start( $t ) ] ?? [];
	}

	/** One dimension's series over the window, by bucket key. */
	private function dim_series( Stats_Store $store, string $dimension, string $server = '' ): array {
		[ $from, $to ] = self::window();
		return self::keyed( $store->dimension( $dimension, $server, $from, $to, true ) );
	}

	/** The category series over the window, by bucket key. */
	private function cat_series( Stats_Store $store, string $server = '' ): array {
		[ $from, $to ] = self::window();
		return self::keyed( $store->categories( $server, $from, $to, true ) );
	}

	/** The leaderboard over the window, as a reader shows it. */
	private function board( Stats_Store $store, string $server = '' ): array {
		[ $from, $to ] = self::window();
		return $store->leaderboard( $server, $from, $to );
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

	public function test_a_builder_with_nothing_pending_is_idle_since_it_was_made(): void {
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
		$fb->set_stats_store( $this->stats_store( 0 ) );
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
		$store = $this->stats_store( 0 );
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
		$successor->settle();

		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 2, \array_sum( \array_column( $totals, 'count' ) ), 'the replayed record is counted once' );
		$this->assertEqualsWithDelta( 442.0, \array_sum( \array_column( $totals, 'sum_ms' ) ), 1e-6 );
		$this->assertCount( 2, $flames->captured, 'the replayed flame is forwarded again' );
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
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$req1 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 100.0 ] );
		$req2 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 200.0 ] );
		$this->fill_request( $fb, $req1 );
		$this->fill_request( $fb, $req2 );

		// Force flush.
		$fb->settle();

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
		$store = $this->stats_store( 0, $fb );

		$seed = new Flame_Builder_Node();
		$seed->set_stats_store( $store );
		$this->fill_request( $seed, $this->completed_request( [ 'url' => '/cold', 'duration_ms' => 140.0 ] ) );
		$seed->settle();

		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cold', 'duration_ms' => 260.0 ] ) );
		$fb->settle();

		$stats = $store->url_aggregate( Log_Manager::url_hash( '/cold' ) );
		$this->assertNotNull( $stats );
		$this->assertEqualsWithDelta( 400.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 2, $stats['flame_raw']['count'] );
		$this->assertEqualsWithDelta( 200.0, $stats['flame']['value'], 1e-6 );
	}

	/** A refused append or blob write is told by what refused it. */
	public function test_a_refused_append_or_blob_write_is_tallied(): void {
		$fb      = new Flame_Builder_Node();
		$refused = new class( ...$this->stats_store_args( 0, $fb ) ) extends Stats_Store {
			public function append_span( array $rows_by_ledger ): array {
				return [ Stats_Store::LEDGER_DIMS => null ] + parent::append_span( $rows_by_ledger );
			}
			public function set_url_aggregates( array $blobs ): array {
				return [];
			}
		};
		$fb->set_stats_store( $refused );

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( $fb ): void {
				$this->fill_request( $fb, $this->completed_request( [ 'url' => '/hoiho/3307', 'duration_ms' => 5.0, 'timestamp' => self::tick() ] ) );
				$this->fill_request( $fb, $this->completed_request( [ 'url' => '/hoiho/3308', 'duration_ms' => 7.0, 'timestamp' => self::tick() ] ) );
				$fb->settle();
				$fb->fire_cb();
			}
		);

		$told = \explode( ' · ', (string) ( self::last_entry_of( $entries, Flame_Tree::STATS_WRITES )['m'] ?? '' ) );
		$this->assertContains( '1 refused stats:dims', $told );
		$this->assertContains( '2 refused url blobs', $told );
	}

	public function test_a_nameless_producer_is_filed_under_unknown(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		// `accumulate_dimensions()` maps an empty server to the literal
		// 'Unknown' on the `server` axis, and the dashboard builds its picker
		// from THAT axis — so WP-CLI and cron traffic put 'Unknown' in the
		// dropdown, and choosing it has to find their rows. The two axes have
		// to agree on the name.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cron', 'server_name' => '', 'duration_ms' => 12.0, 'timestamp' => self::tick() ] ) );
		$fb->settle();

		[ $from, $to ] = self::window();
		$this->assertSame( [ 'Unknown' ], $store->servers( false, $from, $to ) );
		$this->assertSame( 1, $store->url_rows( [ 'Unknown' ], false, [ '/cron' ], false, $from, $to )['/cron']['count'] ?? null );
		$this->assertArrayHasKey( 'Unknown', $store->dimension( Stats_Store::DIM_SERVER, '', $from, $to, false ) );
	}

	public function test_the_url_rows_are_filed_by_server_on_a_spoke_too(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( false );

		// The per-server AGGREGATES are hub-only, and this one looks like it
		// forgot the gate. It did not: the server filter is offered wherever
		// the `server` dimension has values, which is everywhere, so gating
		// this would empty the URL table on every spoke rather than save it
		// anything.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/spoke', 'server_name' => 'lone.example', 'duration_ms' => 12.0, 'timestamp' => self::tick() ] ) );
		$fb->settle();

		[ $from, $to ] = self::window();
		$this->assertSame( [ 'lone.example' ], $store->servers( false, $from, $to ) );
		$this->assertArrayHasKey( '/spoke', $store->url_rows( [ 'lone.example' ], false, null, false, $from, $to ) );
	}

	public function test_flush_persists_hourly_to_memcache(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$req = $this->completed_request( [ 'duration_ms' => 100.0, 'peak_mb' => 32.0 ] );
		$this->fill_request( $fb, $req );
		$fb->settle();

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
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 200 ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 500 ] ) );
		$fb->settle();

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
			'profiles'    => [ 'wpdb' => [ 'time' => 0.4, 'count' => 12, 'ts' => $now, 'entries' => [] ] ],
		];

		// Each files in a bucket of its own, since every partition shares a Ledger.
		$plain  = $this->stats_for( \array_replace( $base, [ 'entries' => $entries, 'timestamp' => $now - 600 ] ), 0 );
		$rolled = $this->stats_for(
			\array_replace(
				$base,
				[
					'entries'   => [],
					'flame'     => Flame_Fold::tree( $fold ),
					'folded'    => true,
					'timestamp' => $now,
				]
			),
			1
		);

		$this->assertSame( $plain, $rolled );
	}

	/**
	 * What one request settled into the bucket it filed in: every read but
	 * its time, so two requests filed in two buckets compare.
	 */
	private function stats_for( array $request, int $partition ): array {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( $partition, $fb );
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( $request ) );
		$fb->settle();

		$from = Stats_Store::bucket_start( $request['timestamp'] );
		$to   = $from + Stats_Store::BUCKET_SECONDS;
		$urls = $store->url_rows( [ 'example.com' ], false, null, false, $from, $to );
		foreach ( $urls as &$row ) {
			unset( $row['last_seen'] );
		}
		unset( $row );
		return [
			'leaderboard' => $store->leaderboard( '', $from, $to ),
			'categories'  => \array_values( $store->categories( '', $from, $to, true ) ),
			'hourly'      => \array_values( $store->totals( $from, $to, true ) ),
			'urls'        => $urls,
		];
	}

	public function test_flush_persists_categories_and_leaderboard(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
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
		$fb->settle();

		// Global category time series.
		$cats   = $this->cat_series( $store );
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertArrayHasKey( 'wpdb', $cats[ $bucket ] );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ] );
		$this->assertEqualsWithDelta( 0.4, $cats[ $bucket ]['wpdb'][ Stats_Store::CAT_MS ], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $cats[ $bucket ]['wpdb'][ Stats_Store::CAT_CALLS ], 1e-6 );

		// The board divides the sums it holds by the requests it profiled.
		$lb = $this->board( $store );
		$this->assertSame( 1, $lb['count'] );
		$this->assertEqualsWithDelta( 0.4, $lb['categories']['wpdb']['time'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $lb['categories']['wpdb']['count'], 1e-6 );
		$this->assertEquals( [ 0.3, 8.0, 1 ], $lb['categories']['wpdb']['entries']['SELECT'] );
	}

	public function test_timed_out_requests_excluded_from_timing_but_counted(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		// error_status='T' means timed-out; duration is synthetic. Must not
		// pollute the timing/leaderboard sums.
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 99999.0, 'error_status' => 'T' ] ) );
		$fb->settle();

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
		$fb->settle(); // must not throw
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
		$fixed       = 1_700_000_000;
		Core::$clock = static fn (): float => (float) $fixed;
		Core::right_now();
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 25.0, 'timestamp' => $fixed ] ) );
		$fb->settle();

		$this->assertSame( [ Stats_Store::bucket_key( $fixed ) ], \array_keys( $this->recent_hourly( $store ) ) );
		$this->assertSame( 1, $this->hourly_at( $store, $fixed )['count'] ?? null );
	}

	public function test_a_request_is_read_back_from_the_bucket_the_tick_names(): void {
		// The builder clamps a request to the tick, so a test dated from the
		// wall reads a bucket off whenever a boundary falls after setUp.
		$this->shift_tick( -Stats_Store::BUCKET_SECONDS );
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/kokako-7731', 'duration_ms' => 0.0, 'timestamp' => self::tick() ] ) );
		$fb->settle();

		$this->assertSame( 1, $this->hourly_at( $store, self::tick() )['requests'] ?? null );
	}

	public function test_a_settle_is_dated_from_the_tick(): void {
		// A settle reads the tick, not the wall.
		$this->shift_tick( -Stats_Store::BUCKET_SECONDS );
		$this->test_a_settle_writes_what_fill_folded();
	}

	public function test_a_settle_writes_what_fill_folded(): void {
		$store = $this->stats_store( 0 );
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0 ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 13.0 ] ) );
		$this->assertSame( [], $this->recent_hourly( $store ), 'fill folds and never writes' );

		$fb->settle();

		$totals = \array_values( $this->recent_hourly( $store ) );
		$this->assertSame( 2, \array_sum( \array_column( $totals, 'count' ) ), 'the settle wrote both' );
	}

	/**
	 * Truly idle: nothing folded and no auto-tune queued, so a tick neither
	 * reads the servers a settle admits nor appends a row.
	 */
	public function test_a_tick_with_nothing_owed_touches_no_store(): void {
		$store = new class( ...$this->stats_store_args( 0 ) ) extends Stats_Store {
			public function append_span( array $rows_by_ledger ): array {
				throw new \LogicException( 'an idle tick appended' );
			}

			public function servers( bool $workers, int $from, int $to ): array {
				throw new \LogicException( 'an idle tick read the servers' );
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
	 * A settle is the checkpoint's work, not a record's. A store that throws
	 * must leave every record folded and undead-lettered, and raise from the
	 * settle, where the checkpoint fails and the worker exits loudly.
	 */
	public function test_a_failing_settle_raises_and_poisons_no_record(): void {
		$store = new class( ...$this->stats_store_args( 0 ) ) extends Stats_Store {
			public function append_span( array $rows_by_ledger ): array {
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
			$fb->settle();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}
		$this->assertSame( 'kea store unreachable', $thrown?->getMessage(), 'the settle raises the store failure' );
	}

	/** @return array<string,array{0:string}> */
	public static function settle_refusals(): array {
		return [
			'the servers read' => [ 'servers' ],
			'the append'       => [ 'append_span' ],
		];
	}

	/**
	 * A settle that failed — on the servers read it admits by, or on the
	 * append — leaves the sums for the next one, each counted once.
	 *
	 * @param string $refusing The store call that refuses once.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'settle_refusals' )]
	public function test_the_settle_after_a_failed_one_writes_each_sum_once( string $refusing ): void {
		$store = new class( ...$this->stats_store_args( 0 ) ) extends Stats_Store {
			public string $refuse = '';

			public function append_span( array $rows_by_ledger ): array {
				$this->refuse_once( 'append_span' );
				return parent::append_span( $rows_by_ledger );
			}

			public function servers( bool $workers, int $from, int $to ): array {
				$this->refuse_once( 'servers' );
				return parent::servers( $workers, $from, $to );
			}

			private function refuse_once( string $call ): void {
				if ( $call === $this->refuse ) {
					$this->refuse = '';
					throw new \RuntimeException( 'tui store refused' );
				}
			}
		};
		$store->refuse = $refusing;
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );

		foreach ( [ '9:10:40' => 97.0, '9:50:40' => 113.0, '9:90:40' => 131.0 ] as $crumb => $ms ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => $ms ] ), $crumb );
		}
		try {
			$fb->settle();
		} catch ( \RuntimeException ) {
			// The first settle's refusal; the next settle retries.
		}
		$fb->settle();

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
		$fb      = $this->armed_flame_builder( $this->stats_store( 0 ) );
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'gannet hook' => [ 'time' => 0.1, 'count' => 211, 'entries' => [] ] ],
		] ) );
		$this->router_tick( 0 );
		$this->assertSame( [ 'gannet' ], $fb->get_auto_tune_state()['disable_hooks']['r'] ?? null, 'the lock held it back' );
		$mc->delete( $lock );

		$this->router_tick( Flame_Builder_Node::AUTO_TUNE_INTERVAL_SEC );

		$fired = \array_values( \array_filter( $capture->captured, static fn ( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' ) ) );
		$this->assertCount( 1, $fired, 'the next tick applied what was queued' );
		$this->assertSame( [ 'gannet' ], $fired[0][ Message::VALUE ]['items'] );
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

	/** The auto-tune emit rides the Router tick at its own cadence. */
	public function test_arguments_arm_the_auto_tune_emit_on_the_router_tick(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$fb->arguments( [] );

		$this->assertSame( 'router', $fb->timer_mode() );
		$this->assertSame( Flame_Builder_Node::AUTO_TUNE_INTERVAL_SEC * 1000, $fb->interval_ms );
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

	// --- Checkpoint: the span settled or carried, and the crumb -------------

	/**
	 * A settle writes the buckets and the checkpoint hands back the crumb even
	 * when every auto-tune emit throws: the emit is the tick's, so the cursor
	 * still commits, and the successor counts nothing twice.
	 */
	public function test_a_settle_commits_past_an_auto_tune_emit_that_always_throws(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 57 ] );
		$store = $this->stats_store( 0 );
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

		$fb->settle();

		$this->assertSame( [ 'counted' => '3:300:43', 'span' => [] ], $fb->save_state() );
		$this->assertSame( 3, \array_sum( \array_column( $this->recent_hourly( $store ), 'count' ) ), 'the buckets are written' );
		$this->expectExceptionMessage( 'auto-tuner refused the rewrite' );
		$fb->fire_cb();
	}

	public function test_a_restore_takes_the_crumb_back_and_counts_the_replay_once(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$fb->restore_state( [ 'counted' => 'crumb-7731' ] );
		$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 61.0 ] ), 'crumb-7731' );
		$this->fill_request_at( $fb, $this->completed_request( [ 'duration_ms' => 37.0 ] ), 'crumb-7732' );
		$fb->settle();

		$hourly = $this->hourly_at( $store, self::tick() );
		$this->assertSame( 1, $hourly['count'] ?? null, 'the replay is not counted; the next record is' );
		$this->assertEqualsWithDelta( 37.0, $hourly['sum_ms'] ?? null, 1e-6 );
	}

	/**
	 * A plain stop leaves the cursor ON the record whose flame forward failed.
	 * Its checkpoint writes nothing: it carries the span holding every record
	 * the cursor passed and names the one it sits on, so the successor's
	 * replay counts each record exactly once: none dropped, none twice.
	 */
	public function test_a_plain_stops_checkpoint_replays_neither_dropping_nor_double_counting(): void {
		$this->set_rule();
		$store = $this->stats_store( 0 );
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

		$this->assertSame( '3:200:42', $checkpoint['counted'] );
		$this->assertSame( 2, $checkpoint['span']['pending'][ Stats_Store::bucket_start( self::tick() ) ]['hourly']['count'] ?? null, 'both records ride the carry' );
		$this->assertSame( [], $this->recent_hourly( $store ), 'and nothing is written before a settle' );

		$successor = new Flame_Builder_Node();
		$successor->name( 'fb' );
		$successor->set_stats_store( $store );
		$successor->connect_node( 'flames:partition' );
		$flames = new Capture_Sink_Node();
		$successor->sink( $flames );
		$successor->restore_state( $checkpoint );
		$this->fill_request_at( $successor, $stopped, '3:200:42' );
		$this->fill_request_at( $successor, $this->completed_request( [ 'url' => '/kea-43', 'duration_ms' => 777.0 ] ), '3:300:43' );
		$successor->settle();

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
		$store = $this->stats_store( 0, $fb );
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
		$fb->settle();

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
		// The tick triggers apply_auto_tune → fire_auto_tune_actions → emit_auto_tune.
		$fb->fire_cb();

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
		$store      = $this->stats_store( 0 );
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
		$store      = $this->stats_store( 0 );
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

	public function test_apply_auto_tune_with_store_uses_memcache_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$store      = $this->stats_store( 0 );

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
		$fb->fire_cb();

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
			$fb->set_stats_store( $this->stats_store( 0 ) );
			$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
			$this->fill_request( $fb, $this->completed_request( [
				'profiles' => [ 'spammy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
			] ) );
			$fb->fire_cb();
			$this->assertSame( [ 'spammy' ], $fb->get_auto_tune_state()['disable_hooks']['r'], 'an APCu-held lock defers the emit' );
			\apcu_delete( $lock );
			$fb->fire_cb();
			$this->assertSame( [], $fb->get_auto_tune_state()['disable_hooks'] );
			$this->assertFalse( \apcu_exists( $lock ), 'the tick released its own lock' );
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
		$store = $this->stats_store( 0 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'spammy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] ) );
		$fb->fire_cb();

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
		$store      = $this->stats_store( 0 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );

		// No auto-tune state set, just a tick.
		$fb->fire_cb();

		// The LOCK is what this test is about; the stats live in their Tables.
		$this->assertSame( [], $mc->keys(), 'no lock taken' );
		// No auto-tune emits (the tick has nothing to emit).
		foreach ( $capture->captured as $m ) {
			$this->assertNotContains( $m[ Message::KEY ], [ 'disable_hooks', 'disable_custom_events', 'add_significant_events' ] );
		}
	}

	public function test_emit_auto_tune_no_op_when_no_sink(): void {
		// Sink-less FlameBuilder still completes its tick without crashing.
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// No sink attached.
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'a hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] ) );
		// A sink-less timer never fires, so run its tick directly: the emit drops.
		( new \ReflectionMethod( $fb, 'fire' ) )->invoke( $fb );
		// Auto-tune queues drained after the tick.
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [], $state['disable_hooks'] );
	}

	// --- Where a settle files: the bucket, the round trips, the stream ------

	public function test_a_request_is_filed_in_the_bucket_it_FINISHED_in(): void {
		// The record reaches the builder at completion, so a long request must
		// land where it ended, not where it began — a 7-minute request started
		// at :58 belongs to the next hour, not the one that has since closed.
		// Seeds distinct from every default: 420s duration, 5-minute buckets.
		$start       = \gmmktime( 13, 58, 0, 8, 27, 2026 );
		Core::$clock = static fn (): float => (float) ( $start + 420 );
		Core::right_now();
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/slow-job-9930',
			'timestamp'   => $start,
			'duration_ms' => 420000.0,
			'status_code' => 200,
		] ) );
		$fb->settle();

		$ended = \gmmktime( 14, 5, 0, 8, 27, 2026 );
		$this->assertSame( 1, $store->url_rows( [ 'example.com' ], false, [ '/slow-job-9930' ], false, $ended, $ended + 300 )['/slow-job-9930']['count'] ?? 0, 'filed in the bucket it finished in' );
		$this->assertSame( [], $store->url_rows( [ 'example.com' ], false, null, false, $start - $start % 300, $ended ), 'and in none before it' );
	}

	public function test_a_settle_costs_round_trips_by_ledger_not_by_url(): void {
		// A settle asks one APPEND a Ledger and one blob MSET a batch, so its
		// round trips do not SCALE with URL count — a fixed ceiling would only
		// pin whatever else the settle happens to do today.
		$trips = [];
		foreach ( [ 12 => 0, 48 => 1 ] as $urls => $partition ) {
			$fb = new Flame_Builder_Node();
			$fb->set_stats_store( $this->stats_store( $partition ) );
			for ( $i = 0; $i < $urls; $i++ ) {
				$this->fill_request( $fb, $this->completed_request( [
					'url'         => "/batched-{$i}-6612",
					'duration_ms' => 12.0,
					'status_code' => 200,
				] ) );
			}
			$this->forget_stats_asks();
			$fb->settle();
			$trips[ $urls ] = \count( \Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness::ask_recorder()->asked );
		}

		$this->assertGreaterThan( 0, $trips[12], 'the settle asks its stores' );
		$this->assertSame( $trips[12], $trips[48], 'four times the URLs, the same round trips' );
	}

	/**
	 * A reprocess of 2026-09-29 09:00-12:00 run the next morning. The one
	 * request that never completes times out on the STREAM, three windows
	 * after it landed, measured to the stream and filed in its own 09-29
	 * bucket, and no settle files anything in the wall's day. On the wall it
	 * timed out twenty hours long and filed in the wall's bucket.
	 */
	public function test_a_reprocess_times_out_on_the_stream_and_files_nothing_in_the_walls_day(): void {
		$this->set_rule();
		( new \Newspack_Nodes\Router_Node() )->name( \Newspack_Nodes\Node_Names::ROUTER );
		$wall        = \gmmktime( 5, 19, 0, 9, 30, 2026 );
		Core::$clock = static fn (): float => (float) $wall;
		Core::right_now();
		$store = $this->stats_store( 0 );
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
			$fb->settle();
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

		$timed_out = \array_values( \array_filter(
			\array_map( static fn ( array $record ): array => (array) $record[ Message::VALUE ], $records->captured ),
			static fn ( array $record ): bool => 'r-stall-5521' === $record['rid']
		) );
		$this->assertCount( 1, $timed_out, 'the stalled request times out once' );
		$this->assertSame( 'T', $timed_out[0]['error_status'] );
		$this->assertSame( 953_000, $timed_out[0]['duration_ms'], 'to 09:30:00, the third boundary after it landed' );
		$this->assertSame( '2026-09-29-09-30', Stats_Store::bucket_key( $stall + 953 ), 'where it completes' );
		// Every row files at its own completion, none in the wall's day.
		$walls_day = \gmmktime( 0, 0, 0, 9, 30, 2026 );
		foreach ( \array_keys( Stats_Store::LEDGER_COLUMNS ) as $ledger ) {
			foreach ( $this->ledger_asks( $ledger ) as [ , $rows ] ) {
				$this->assertSame( [], \array_values( \array_filter( \array_column( $rows, 0 ), static fn ( int $t ): bool => $t >= $walls_day ) ), "{$ledger} filed nothing in the wall's day" );
			}
		}
		$this->assertNotSame( [], $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ), 'the replay filed its rows' );
	}

	/**
	 * A replayed request's categories and tree age on its own completion
	 * time, as they did live: stamped by the wall, a record a day old saw
	 * every category it carried expire on arrival, and a replay kept every
	 * tree node of every replayed hour, all stamped with the one wall hour.
	 */
	public function test_a_replayed_request_ages_its_categories_and_tree_on_its_own_time(): void {
		$this->set_rule();
		$wall        = \gmmktime( 5, 19, 0, 9, 30, 2026 );
		Core::$clock = static fn (): float => (float) $wall;
		Core::right_now();
		$fb        = new Flame_Builder_Node();
		$store     = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$done = \gmmktime( 10, 41, 5, 9, 29, 2026 );

		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/replayed-3391',
			'duration_ms' => 250.0,
			'timestamp'   => $done,
			'flame'       => [ 'name' => 'root', 'value' => 250.0, 'children' => [ [ 'name' => 'gull hook', 'value' => 120.0, 'children' => [] ] ] ],
			'profiles'    => [ 'gull hook' => [ 'time' => 0.12, 'count' => 7, 'ts' => $done, 'entries' => [] ] ],
		] ) );
		$fb->settle();

		$stats = $store->url_aggregate( Log_Manager::url_hash( '/replayed-3391' ) );
		$this->assertArrayHasKey( 'gull hook', Core::arr( $stats['profiles']['categories'] ?? null ), 'a day-old category survives its own arrival' );
		$this->assertSame( $done, $stats['flame_raw']['children'][0]['ts'] ?? null, 'the tree is stamped with its completion' );
	}

	public function test_a_url_blob_the_store_could_not_read_is_never_overwritten(): void {
		// Decision 3: a fresh aggregate over an unread blob would replace the
		// stored one with a single settle's requests. The rest still lands.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$hash   = Log_Manager::url_hash( '/kereru/4471' );
		$stored = [ 'flame' => [ 'name' => 'aggregate', 'sum_value' => 4471.0, 'count' => 73, 'children' => [] ], 'profiles' => [ 'count' => 73, 'sum_req_time' => 4471.0, 'categories' => [] ] ];
		$this->assertSame( [ $hash ], $store->set_url_aggregates( [ $hash => $stored ] ) );

		$this->refuse_stats_reads( '/^' . \preg_quote( Stats_Store::TABLE_URL, '/' ) . '/' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/kereru/4471', 'duration_ms' => 29.0, 'timestamp' => self::tick() ] ) );
		$this->refuse_stats_reads( '' );
		$fb->settle();

		$this->assertSame( 73, $store->url_aggregate( $hash )['flame']['count'] ?? null, 'the stored aggregate stands' );
		$this->assertSame( 1, $this->hourly_at( $store, self::tick() )['count'] ?? null, 'the request is counted all the same' );
		$this->assertSame( 0, $this->stats_count( $fb ), 'nothing is held for the blob' );
	}

	public function test_a_profiles_folded_tail_outlives_the_next_request_that_reloads_it(): void {
		// The stored profile folds its tail into `Other`; a worker that reloads
		// the blob runs the expiry sweep over it, which reads each `ts`.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$now      = (int) Core::$now;
		$profiles = [];
		for ( $c = 0; $c < Stats_Store::MAX_LB_CATEGORIES + 37; $c++ ) {
			$profiles[ "event {$c}" ] = [ 'time' => 3.0 + $c, 'count' => 1, 'ts' => $now, 'entries' => [] ];
		}
		$request = [ 'url' => '/tail/7', 'duration_ms' => 900.0, 'timestamp' => $now, 'profiles' => $profiles ];

		$this->fill_request( $fb, $this->completed_request( $request ) );
		$fb->settle();
		// The settle left no aggregate held, so the next resumes from the blob.
		$this->fill_request( $fb, $this->completed_request( [ 'profiles' => [ 'event 0' => $profiles['event 0'] ] ] + $request ) );
		$fb->settle();

		$hash = Log_Manager::url_hash( '/tail/7' );
		$blob = Core::arr( $store->url_aggregate( $hash ) );
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

	// -------------------------------------------------------------------------
	// Narration: what the builder writes, on its own record.
	// -------------------------------------------------------------------------

	/** The narration categories, every one the builder writes. */
	private const NARRATION = [
		Flame_Tree::STATS_WRITES,
		Flame_Tree::STATS_SWEEP . ' (start)',
		Flame_Tree::STATS_SWEEP . ' (complete)',
	];

	public function test_a_worker_record_keeps_the_narration_counters(): void {
		// The builder files the record's entries through an allowlist that keeps
		// `m` and drops every other producer field: the counters must be in it.
		$store = $this->stats_store( 0 );
		$fb    = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );

		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( $fb, $now ): void {
				Core::$now = $now;
				$this->fill_request( $fb, $this->completed_request( [ 'url' => '/narrated-3301', 'timestamp' => $now ] ) );
				$fb->settle();
				$fb->fire_cb();
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
			[ Flame_Tree::STATS_WRITES, static fn (): array => [ 'kea-7731 appended', [ 'ms' => 0.03, 'rows' => 0.06 ] ] ]
		);

		$this->assertSame( 'kea-7731 appended · 0.1 rows', $told[0]['m'] ?? null );
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

	public function test_get_stats_carries_the_narration_not_yet_told(): void {
		$store = $this->stats_store( 0 );
		$fb    = new Flame_Builder_Node();
		$fb->sink( new Capture_Sink_Node() );
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 14, 22, 0, 9, 22, 2026 );
		$fb->settle();
		$fb->fire_cb();
		Core::$now += \Newspack_Nodes\Consumer_Node::CHECKPOINT_INTERVAL_S;
		$fb->settle();

		$this->assertSame( 1, $this->get_stats( $fb )['narration'][ Flame_Tree::STATS_WRITES ]['settles'] ?? null, 'the settle since the last summary' );
	}

	/** The catalog's `reply_shape` names every key `GET_STATS` answers with. */
	public function test_get_stats_reply_shape_names_every_key_it_carries(): void {
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $this->stats_store( 0 ) );
		$verb       = \array_column( Flame_Builder_Node::node_schema()['requests'], null, 'name' )['GET_STATS'];
		\preg_match_all( '/\w+/', $verb['reply_shape'], $named );

		$this->assertSame( \array_keys( $this->get_stats( $fb ) ), $named[0] );
	}

	// --- Per-server leaderboard entry cap (hub mode) ----------------------

	public function test_per_server_leaderboard_cap_global_upper_bound(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
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
		$fb->settle();

		$lb_s = $this->board( $store, 'srv-cap' );
		$this->assertArrayHasKey( 'wpdb', $lb_s['categories'] );
		$this->assertLessThanOrEqual(
			Flame_Builder_Node::ENTRY_LIMIT_GLOBAL_UPPER,
			\count( $lb_s['categories']['wpdb']['entries'] )
		);
	}

	public function test_hub_mode_per_server_categories_tracked(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
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
		$fb->settle();

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
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name'  => 'srv-x',
			'request_method' => 'POST',
			'duration_ms'  => 25.0,
			'timestamp'    => $now,
		] ) );
		$fb->settle();

		// Per-server dim under 'method' should be populated.
		$dim_method = $this->dim_series( $store, 'method', 'srv-x' );
		$this->assertNotEmpty( $dim_method );

		// Per-server dim under 'server' should be EMPTY (skipped).
		$dim_server = $this->dim_series( $store, 'server', 'srv-x' );
		$this->assertEmpty( $dim_server, "per-server 'server' dim is skipped" );
	}

	// --- Per-URL aggregate reload ------------------------------------------

	public function test_flame_raw_promoted_to_flame_on_reload(): void {
		// Set store with an entry that has flame_raw set (post-settle format).
		$fb       = new Flame_Builder_Node();
		$store    = $this->stats_store( 0, $fb );
		$url      = '/promoted';
		$url_hash = Log_Manager::url_hash( $url );
		$store->set_url_aggregates( [
			$url_hash => [
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
			],
		] );

		$fb->set_stats_store( $store );

		// Hit the URL once more.
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 100.0,
		] ) );
		$fb->settle();

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
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 22.0 ] ) );
		$fb->settle();

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
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => '',
			'duration_ms' => 50.0,
			'timestamp'   => self::tick(),
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->settle();

		$this->assertSame( 1, $this->board( $store )['count'], 'it still counts globally' );
		$keys = \array_unique( \array_column( $this->ledger_asks( Stats_Store::LEDGER_LEADERBOARD )[0][1], 1 ) );
		$this->assertSame( [ Stats_Store::SITE ], \array_values( $keys ), 'but no per-server scope is filed for a nameless server' );
	}

	public function test_stats_time_the_request_not_the_flame_that_covers_it(): void {
		// The flame's value is raised to COVER its children so the treemap does
		// not overflow. That is a rendering rule; a stat must stay measured.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 137.0,
			'timestamp'   => $now,
			// A covering value far past the measured duration.
			'flame'       => [ 'name' => 'request', 'value' => 911.0, 'children' => [] ],
		] ) );
		$fb->settle();

		$this->assertEqualsWithDelta(
			137.0,
			$this->hourly_at( $store, $now )['sum_ms'] ?? 0.0,
			1e-6,
			'the request took 137ms; 911 is what the flame was stretched to'
		);
	}

	public function test_a_bucket_revisited_before_the_flush_keeps_both_halves(): void {
		// Bucket keys come from the request's START time and records arrive at
		// COMPLETION, so an older bucket is revisited constantly around a boundary.
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$early = Stats_Store::bucket_start( self::tick() ) - 600;
		$late  = $early + 300;
		foreach ( [ $early, $late, $early ] as $ts ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 23.0, 'timestamp' => $ts ] ) );
		}
		$fb->settle();

		$this->assertSame(
			2,
			$this->hourly_at( $store, $early )['count'] ?? 0,
			'both of the early bucket\'s requests counted'
		);
	}

	// --- Save state after several settles (idempotency) -------------------

	public function test_double_flush_is_idempotent(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$fb->settle();
		$snap_a = $this->recent_hourly( $store );
		// Second flush with nothing pending — should be a no-op for stats.
		$fb->settle();
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

	public function test_save_state_without_partition_does_not_throw(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertSame( [ 'counted' => '', 'span' => [] ], $fb->save_state() );
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

	/** The Ledgers a settle appends to: every stats Ledger but the word index. */
	private static function written_ledgers(): array {
		return \array_keys( \array_diff_key( Stats_Store::LEDGER_COLUMNS, [ Stats_Store::LEDGER_SEARCH => true ] ) );
	}

	/** Name the url Table and every Ledger a settle writes, as `flame-builder.tsl` does. */
	private function name_stats_targets( Flame_Builder_Node $fb ): void {
		$interpreter = $this->read_private( $fb, 'interpreter' );
		$this->assertSame( "ok\n", $interpreter->dispatch( 'set_url_target', [ Stats_Store::TABLE_URL ] ) );
		foreach ( self::written_ledgers() as $ledger ) {
			$this->assertSame( 'ok', $interpreter->dispatch( 'add_ledger_target', [ $ledger ] ) );
		}
	}

	public function test_configure_stats_builds_the_store_over_the_named_stores(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertStringNotContainsString( 'configure_stats', $fb->dump_config(), 'inert until configured' );
		$this->name_stats_targets( $fb );

		$result = $this->read_private( $fb, 'interpreter' )->dispatch( 'configure_stats', [] );

		$this->assertSame( 'ok', $result );
		$ledger_lines = \implode( '', \array_map( static fn ( string $ledger ): string => "command_node fb:config add_ledger_target {$ledger}\n", self::written_ledgers() ) );
		$this->assertStringEndsWith(
			"command_node fb:config set_url_target flame-stats:url\n"
			. $ledger_lines
			. "command_node fb:config configure_stats\n",
			$fb->dump_config(),
			'the Table and the Ledgers replay ahead of the store built over them'
		);
	}

	/** A Ledger a settle writes and no verb named would take its rows to no node. */
	public function test_configure_stats_refuses_a_ledger_left_unnamed(): void {
		$fb          = new Flame_Builder_Node();
		$interpreter = $this->read_private( $fb, 'interpreter' );
		$fb->name( 'fb' );
		$interpreter->dispatch( 'set_url_target', [ Stats_Store::TABLE_URL ] );
		$interpreter->dispatch( 'add_ledger_target', [ Stats_Store::LEDGER_URL_ROWS ] );

		try {
			$interpreter->dispatch( 'configure_stats', [] );
			$this->fail( 'configure_stats built a store with Ledgers unnamed' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'configure_stats: no Ledger named by add_ledger_target: ' . \implode( ', ', \array_diff( self::written_ledgers(), [ Stats_Store::LEDGER_URL_ROWS ] ) ), $e->getMessage() );
		}
		$this->assertNull( $this->read_private( $fb, 'stats_store' ) );
	}

	/**
	 * A Ledger the builder does not append to is refused: the word index,
	 * which nothing writes, and a name the store does not know.
	 *
	 * @param string $ledger The Ledger named.
	 */
	#[\PHPUnit\Framework\Attributes\TestWith( [ 'stats:search' ] )]
	#[\PHPUnit\Framework\Attributes\TestWith( [ 'stats:wombat-4471' ] )]
	public function test_add_ledger_target_refuses_a_ledger_the_builder_does_not_write( string $ledger ): void {
		$fb = new Flame_Builder_Node();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "add_ledger_target: '{$ledger}' is not a Ledger a settle appends to" );
		$fb->add_ledger_target( $ledger );
	}

	/** An unnamed Table would take the store's writes to no node at all. */
	public function test_configure_stats_refuses_a_builder_whose_table_was_never_named(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		try {
			$this->read_private( $fb, 'interpreter' )->dispatch( 'configure_stats', [] );
			$this->fail( 'configure_stats built a store with its url Table unnamed' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'configure_stats: no Table named by set_url_target', $e->getMessage() );
		}
		$this->assertNull( $this->read_private( $fb, 'stats_store' ), 'no store over an unnamed Table' );
	}

	/** The console draws a Table edge from what the verbs named, not from a constant. */
	public function test_the_stats_stores_are_display_targets_once_configured(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->target( 'flames:partition' );
		$interpreter = $this->read_private( $fb, 'interpreter' );
		$this->name_stats_targets( $fb );
		$this->assertSame( [ 'flames:partition' ], $fb->display_targets(), 'no edge to a store nothing writes' );

		$interpreter->dispatch( 'configure_stats', [] );

		$this->assertSame( [ 'flames:partition', Stats_Store::TABLE_URL, ...self::written_ledgers() ], $fb->display_targets() );
	}

	/** The url Table's verb takes a `node_name`, which the document canvas draws as an edge. */
	public function test_set_url_target_takes_a_node_name(): void {
		$verbs = \array_column( Flame_Builder_Node::node_schema()['commands'], null, 'name' );
		$this->assertSame( [ 'node_name' ], \array_column( $verbs['set_url_target']['args'] ?? [], 'type' ) );
	}

	/** Name each stats Table through its verb, as `flame-builder.tsl` does. */
	public function test_set_url_target_refuses_a_table_the_readers_do_not_mount(): void {
		$fb = new Flame_Builder_Node();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "set_url_target: 'wombat-stats:url-4471' is not flame-stats:url, the Table the performance readers mount" );
		$fb->set_url_target( 'wombat-stats:url-4471' );
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
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0, 'error_status' => 'A' ] ) );
		$fb->settle();

		foreach ( $this->recent_hourly( $store ) as $stats ) {
			$this->assertSame( 0, $stats['count'], 'aborted excluded from count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'aborted excluded from sum_ms' );
		}
	}

	public function test_workers_excluded_from_timing(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0, 'is_worker' => true ] ) );
		$fb->settle();

		$this->assertSame( [], $this->recent_hourly( $store ), 'workers file no site totals' );
	}

	public function test_worker_request_records_url_timing_but_no_global(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
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
		$fb->settle();

		// Per-URL timing IS kept, on the worker family's row.
		[ $from, $to ] = self::window();
		$this->assertSame( [], $store->url_rows( [ 'example.com' ], false, null, false, $from, $to ), 'no reader row' );
		$row = $store->url_rows( [ 'example.com' ], true, [ '/?cache-cozy' ], false, $from, $to )['/?cache-cozy'];
		$this->assertSame( 1, $row['count'] );
		$this->assertSame( 1, $row['timed_count'] );
		$this->assertEqualsWithDelta( 40.0, $row['sum_ms'], 1e-6 );

		// Nothing global: no totals, no dimension, no board, no category.
		$this->assertSame( [], $this->recent_hourly( $store ), 'worker excluded from global totals' );
		$this->assertSame( [], $this->dim_series( $store, 'status' ), 'worker excluded from global dimensions' );
		$this->assertSame( 0, $this->board( $store )['count'] );
		$this->assertSame( [], $this->cat_series( $store ) );
	}

	public function test_non_worker_request_records_global_count_and_peak(): void {
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
		$fb->set_stats_store( $store );

		$now = self::tick();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/x', 'duration_ms' => 40.0, 'peak_mb' => 12.0, 'timestamp' => $now ] ) );
		$fb->settle();

		$hourly = $this->hourly_at( $store, $now );
		$this->assertSame( 1, $hourly['count'] );
		$this->assertEqualsWithDelta( 40.0, $hourly['sum_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $hourly['sum_peak_mb'], 1e-6 );
	}

	public function test_per_server_tracking_only_when_hub(): void {
		$store = $this->stats_store( 0 );

		$fb_spoke = new Flame_Builder_Node();
		$fb_spoke->set_stats_store( $store );
		$fb_spoke->set_is_hub( false );

		$fb_hub = new Flame_Builder_Node();
		$fb_hub->set_stats_store( $store );
		$fb_hub->set_is_hub( true );

		$req = $this->completed_request( [
			'server_name' => 'srv-a',
			'duration_ms' => 50.0,
			'timestamp'   => self::tick(),
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] );

		$this->fill_request( $fb_spoke, $req );
		$fb_spoke->settle();

		// Spoke: no per-server board.
		$this->assertSame( 0, $this->board( $store, 'srv-a' )['count'] );

		// Hub: the per-server board counts it.
		$this->fill_request( $fb_hub, $req );
		$fb_hub->settle();
		$this->assertSame( 1, $this->board( $store, 'srv-a' )['count'] );
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
}

/**
 * A Flame_Builder whose intern table fills after three names, so a test can
 * reach the freeze without pushing 50000 distinct strings through it.
 */
class TinyInternFlameBuilder extends Flame_Builder_Node {
	protected const INTERN_TABLE_LIMIT = 3;
}
