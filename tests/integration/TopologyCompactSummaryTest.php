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
	 * Topology_Loader::load no longer takes a `$config` array — `<config:KEY>`
	 * tokens resolve through the substrate's registered `config` namespace.
	 * Override that resolver here so the TSL's `<config:logs_dir>` /
	 * `<config:offsets_dir>` (and the segment/lifespan literals) point at this
	 * test's scratch dir instead of the real base directory — otherwise the
	 * Consumer/Partition nodes open against `/tmp/newspack-nodes` and stall on
	 * orphan lock dirs from prior runs.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp             = $this->make_temp_dir( 'topology-compact-summary-' );
		$this->saved_resolvers = Core::$config_resolvers;
		Topology_Registry::register_stock_dir(
			\dirname( __DIR__, 2 ) . '/topologies'
		);
		$tmp = $this->tmp;
		Core::register_config_namespace(
			'config',
			static function ( string $key ) use ( $tmp ) {
				static $values = null;
				if ( null === $values ) {
					$values = [
						'logs_dir'     => $tmp . '/logs',
						'offsets_dir'  => $tmp . '/offsets',
						'segment_size' => '1048576',
						'num_segments' => '4',
						'max_lifespan' => '3600',
					];
				}
				return $values[ $key ] ?? null;
			}
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

	public function test_dump_config_round_trips_topology_targets(): void {
		$this->load_topology( 'firehose-workers-and-jobs' );

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
}
