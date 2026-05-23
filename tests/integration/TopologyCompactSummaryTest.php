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
 * real CommandInterpreter sink (the same path supervisor + worker take
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
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Registry;

class TopologyCompactSummaryTest extends TestCase {

	private string $tmp = '';

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
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir( 'topology-compact-summary-' );
		Topology_Registry::register_stock_dir(
			\dirname( __DIR__, 2 ) . '/topologies'
		);
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Load `<name>.tsl` against a CI+Router pair, mirroring the scaffolding
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

		$ci = new Command_Interpreter_Node();
		$ci->name( '_command_interpreter' );
		$ci->sink( $router );

		Topology_Loader::load( $name, 0, $ci, [
			'logs_dir'      => $this->tmp . '/logs',
			'offsets_dir'   => $this->tmp . '/offsets',
			'segment_size'  => '1048576',
			'num_segments'  => '4',
			'max_lifespan'  => '3600',
		] );
		return $ci;
	}

	public function test_firehose_workers_and_jobs_registers_compact_summary_fanout(): void {
		$this->load_topology( 'firehose-workers-and-jobs' );

		$this->assertNotNull( Core::node( 'completed:tee' ), 'completed:tee should be registered' );
		$this->assertNotNull( Core::node( 'completed:partition' ), 'completed:partition should be registered' );
		$this->assertNotNull( Core::node( 'gyroscope:partition' ), 'gyroscope:partition should be registered' );
	}

	public function test_firehose_workers_only_registers_compact_summary_fanout(): void {
		$this->load_topology( 'firehose-workers-only' );

		$this->assertNotNull( Core::node( 'completed:tee' ), 'completed:tee should be registered' );
		$this->assertNotNull( Core::node( 'completed:partition' ), 'completed:partition should be registered' );
		$this->assertNotNull( Core::node( 'gyroscope:partition' ), 'gyroscope:partition should be registered' );
	}

	public function test_completed_tee_fans_out_to_both_partitions(): void {
		$this->load_topology( 'firehose-workers-and-jobs' );

		$tee = Core::node( 'completed:tee' );
		$this->assertNotNull( $tee );
		$targets = $tee->target();
		$this->assertIsArray( $targets, 'Tee should hold an array of targets' );
		$this->assertContains( 'completed:partition', $targets );
		$this->assertContains( 'gyroscope:partition', $targets );
	}

	public function test_request_builder_completed_target_set_to_completed_tee(): void {
		$this->load_topology( 'firehose-workers-and-jobs' );

		$rb = Core::node( 'request-builder' );
		$this->assertInstanceOf( Request_Builder_Node::class, $rb );

		$ref = new \ReflectionProperty( Request_Builder_Node::class, 'completed_target' );
		$ref->setAccessible( true );
		$this->assertSame( 'completed:tee', $ref->getValue( $rb ) );
	}

	public function test_request_builder_flight_target_set_to_gyroscope_partition(): void {
		$this->load_topology( 'firehose-workers-and-jobs' );

		$rb = Core::node( 'request-builder' );
		$this->assertInstanceOf( Request_Builder_Node::class, $rb );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
	}

	public function test_set_inflight_interval_verb_was_invoked_from_topology(): void {
		$this->load_topology( 'firehose-workers-and-jobs' );

		$rb = Core::node( 'request-builder' );
		$this->assertInstanceOf( Request_Builder_Node::class, $rb );
		// The default interval matches the spec value (1000ms), so the
		// `interval()` getter alone can't tell us whether the topology
		// line fired. dump_config replays mark_verb_invoked entries —
		// if the TSL ran `cmd request-builder:config set_inflight_interval 1000`,
		// the dump should contain that line verbatim.
		$this->assertStringContainsString(
			'cmd request-builder:config set_inflight_interval 1000',
			$rb->dump_config()
		);
	}
}
