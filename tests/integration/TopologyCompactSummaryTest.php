<?php
/**
 * Compact-summary fan-out wiring.
 *
 * Verifies that the production topology files wire up the new
 * `completed:tee → completed:partition / gyroscope:partition` fan-out
 * that drives the request-log + gyroscope dashboards, plus the
 * matching :config verbs on request-builder that point its
 * completed-summary and inflight-snapshot outputs at those targets.
 *
 * Loads each affected TSL file in-process via Topology_Loader against a
 * real CommandInterpreter sink (the same path the reconcile pass + worker take
 * at spawn time), then asserts on Core's node registry and on the
 * patron state the :config verbs mutated.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Registry;

class TopologyCompactSummaryTest extends TestCase {

	private string $tmp = '';

	/** Snapshot of the process-lifetime token-resolver registry, restored in tearDown. */
	private array $saved_resolvers = [];

	/**
	 * Per-test scratch dir for Consumer offset files / Partition segment files
	 * the TSL conjures. A pre-existing logs dir from a previous run with
	 * orphan lock dirs (no heartbeat) burns up to ORPHAN_GRACE_S * partitions
	 * seconds in `Lock::try_steal_orphan_or_stale()`, so we always start
	 * fresh.
	 *
	 * Topology_Registry::register_stock_dir is also (re-)called defensively
	 * because the base substrate TestCase resets Core in setUp() but leaves
	 * the registry alone — re-registration on the same path is a no-op.
	 *
	 * `use_scratch_config()` repoints the `<config:*>` directory tokens at that
	 * scratch dir and seeds the retention geometry these tests assert on —
	 * every value distinct from the baseline config's, so a token that stopped
	 * flowing through would fail rather than coincide.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp             = $this->make_temp_dir( 'topology-compact-summary-' );
		$this->saved_resolvers = Core::$config_resolvers;
		Topology_Registry::register_stock_dir(
			\dirname( __DIR__, 2 ) . '/topologies'
		);
		$this->use_scratch_config(
			$this->tmp,
			[
				'min_segments' => '3',
				'num_segments' => '5',
				'min_lifetime' => '120',
				'lifetime'     => '7200',
				'max_segments' => '11',
			]
		);
	}

	protected function tearDown(): void {
		Core::$config_resolvers = $this->saved_resolvers;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Load `<name>.tsl` against an interpreter+Router pair, mirroring the scaffolding
	 * WorkerBase::build_scaffolding() builds at spawn time:
	 *
	 *   _command_interpreter --sink--> _router
	 *
	 * The `cmd request-builder:config set_completed_target completed:tee`
	 * lines in the TSL emit TM_COMMAND messages addressed to
	 * `request-builder:config` — without the Router wiring, those land
	 * back in `_command_interpreter` (which doesn't know about sibling
	 * CIs) and the verb never executes.
	 */
	private function load_topology( string $name ): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( '_router' );

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

		Topology_Loader::load( $name, 0, $interpreter );
		return $interpreter;
	}

	public function test_firehose_workers_and_jobs_registers_compact_summary_fanout(): void {
		$this->load_topology( 'complete' );

		$this->assertNotNull( Core::node( 'completed:tee' ), 'completed:tee should be registered' );
		$this->assertNotNull( Core::node( 'completed:partition' ), 'completed:partition should be registered' );
		$this->assertNotNull( Core::node( 'gyroscope:partition' ), 'gyroscope:partition should be registered' );
	}

	public function test_firehose_workers_only_registers_compact_summary_fanout(): void {
		$this->load_topology( 'complete' );

		$this->assertNotNull( Core::node( 'completed:tee' ), 'completed:tee should be registered' );
		$this->assertNotNull( Core::node( 'completed:partition' ), 'completed:partition should be registered' );
		$this->assertNotNull( Core::node( 'gyroscope:partition' ), 'gyroscope:partition should be registered' );
	}

	public function test_completed_tee_fans_out_to_both_partitions(): void {
		$this->load_topology( 'complete' );

		$tee = Core::node( 'completed:tee' );
		$this->assertNotNull( $tee );
		$targets = $tee->target();
		$this->assertIsArray( $targets, 'Tee should hold an array of targets' );
		$this->assertContains( 'completed:partition', $targets );
		$this->assertContains( 'gyroscope:partition', $targets );
	}

	public function test_request_builder_completed_target_set_to_completed_tee(): void {
		$this->load_topology( 'complete' );

		$rb = Core::node( 'request-builder' );
		$this->assertInstanceOf( Request_Builder_Node::class, $rb );

		$ref = new \ReflectionProperty( Request_Builder_Node::class, 'completed_target' );
		$this->assertSame( 'completed:tee', $ref->getValue( $rb ) );
	}

	public function test_request_builder_flight_target_set_to_gyroscope_partition(): void {
		$this->load_topology( 'complete' );

		$rb = Core::node( 'request-builder' );
		$this->assertInstanceOf( Request_Builder_Node::class, $rb );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
	}

	public function test_dump_config_round_trips_topology_targets(): void {
		$this->load_topology( 'complete' );

		$rb = Core::node( 'request-builder' );
		$this->assertInstanceOf( Request_Builder_Node::class, $rb );
		// dump_config emits one line per setting that differs from its default,
		// straight from the node's state. The topology wires three non-default
		// targets, so the round-trip must reproduce each `cmd` line. The
		// interval is left at its 1000ms default, so it is deliberately NOT
		// emitted (only non-default settings dump).
		$dump = $rb->dump_config();
		$this->assertStringContainsString( 'cmd request-builder:config set_errors_target errors:partition', $dump );
		$this->assertStringContainsString( 'cmd request-builder:config set_completed_target completed:tee', $dump );
		$this->assertStringContainsString( 'cmd request-builder:config set_inflight_target gyroscope:partition', $dump );
		$this->assertStringNotContainsString( 'set_inflight_interval', $dump );
	}

	/**
	 * The `<config:segment_size> <config:min_segments> <config:num_segments>
	 * <config:min_lifetime> <config:lifetime> <config:max_segments>` Partition
	 * line must resolve all new retention tokens from config in the new order.
	 */
	public function test_config_token_partition_carries_new_retention_geometry(): void {
		$this->load_topology( 'complete' );

		$errors = Core::node( 'errors:partition' );
		$this->assertInstanceOf( Partition_Node::class, $errors );
		$this->assertSame( 3, $this->partition_geometry( $errors, 'min_segments' ) );
		$this->assertSame( 5, $this->partition_geometry( $errors, 'num_segments' ) );
		$this->assertSame( 120, $this->partition_geometry( $errors, 'min_lifetime' ) );
		$this->assertSame( 7200, $this->partition_geometry( $errors, 'lifetime' ) );
		$this->assertSame( 11, $this->partition_geometry( $errors, 'max_segments' ) );
	}

	/**
	 * A ≤PIPE_BUF record round-trips through the named partition; a >PIPE_BUF one
	 * drops whole. void_warranty was removed from errors / completed / gyroscope —
	 * the whole family is uniformly ≤PIPE_BUF atomic (each producer fits at the
	 * source). Re-adding a grant would round-trip the oversize record and break this.
	 */
	private function assert_partition_enforces_pipe_buf_cap( string $node ): void {
		$partition = Core::node( $node );
		$this->assertInstanceOf( Partition_Node::class, $partition );

		$small                   = Message::new_message();
		$small[ Message::TYPE ]  = Message::TM_STRUCT;
		$small[ Message::KEY ]   = 'small-cap-731';
		$small[ Message::VALUE ] = [ 'k' => 'x', 'm' => 'sentinel-731' ];
		$this->assertLessThan( Partition_Node::MAX_LINE_SIZE, \strlen( Message::packed( $small ) . "\n" ) );
		$partition->fill( $small );

		$big                   = Message::new_message();
		$big[ Message::TYPE ]  = Message::TM_STRUCT;
		$big[ Message::KEY ]   = 'large-cap-731';
		$big[ Message::VALUE ] = [ 'k' => 'x', 'm' => \str_repeat( 'payload-sentinel-731-', 250 ) ];
		$this->assertGreaterThan( Partition_Node::MAX_LINE_SIZE, \strlen( Message::packed( $big ) . "\n" ) );
		$partition->fill( $big );
		$partition->flush();

		$keys = [];
		foreach ( $partition->get_segments( true ) as $segment ) {
			$bytes = $partition->read_at( (int) $segment['id'], 0, (int) $segment['size'] );
			foreach ( \explode( "\n", $bytes ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				$keys[] = Message::unpacked( $line )[ Message::KEY ];
			}
		}

		$this->assertContains( 'small-cap-731', $keys, "$node: a ≤PIPE_BUF record round-trips" );
		$this->assertNotContains( 'large-cap-731', $keys, "$node: a >PIPE_BUF record drops whole — the cap is enforced (no void_warranty)" );
	}

	public function test_errors_partition_enforces_the_pipe_buf_cap(): void {
		$this->load_topology( 'complete' );
		$this->assert_partition_enforces_pipe_buf_cap( 'errors:partition' );
	}

	public function test_completed_partition_enforces_the_pipe_buf_cap(): void {
		$this->load_topology( 'complete' );
		$this->assert_partition_enforces_pipe_buf_cap( 'completed:partition' );
	}

	public function test_gyroscope_partition_enforces_the_pipe_buf_cap(): void {
		$this->load_topology( 'complete' );
		$this->assert_partition_enforces_pipe_buf_cap( 'gyroscope:partition' );
	}

	/**
	 * The literal `1048576 <config:min_segments> <config:num_segments> 0 0`
	 * Partition line keeps its two trailing zero lifetimes while picking up the
	 * segment counts from config.
	 */
	public function test_literal_zero_partition_carries_new_retention_geometry(): void {
		$this->load_topology( 'complete' );

		$completed = Core::node( 'completed:partition' );
		$this->assertInstanceOf( Partition_Node::class, $completed );
		$this->assertSame( 3, $this->partition_geometry( $completed, 'min_segments' ) );
		$this->assertSame( 5, $this->partition_geometry( $completed, 'num_segments' ) );
		$this->assertSame( 120, $this->partition_geometry( $completed, 'min_lifetime' ) );
		$this->assertSame( 7200, $this->partition_geometry( $completed, 'lifetime' ) );
	}

	private function partition_geometry( Partition_Node $partition, string $prop ): int {
		return ( new \ReflectionProperty( Partition_Node::class, $prop ) )->getValue( $partition );
	}
}
