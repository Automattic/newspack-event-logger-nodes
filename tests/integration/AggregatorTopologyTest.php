<?php
/**
 * aggregator.tsl parse + mount wiring (post-cutover pull graph).
 *
 * The hub topology no longer mounts a Stream_Merger. It ships the firehose
 * Topic sink and the Remote_Job_Rewrite_Node that flips aggregated `k:"job"`
 * lines to `k:"remote_job"` before the Topic write; per-spoke Remote_Source
 * nodes are operator-wired on the console canvas, not in the stock .tsl. Loads
 * the TSL in-process via Topology_Loader against a real
 * CommandInterpreter+Router pair (the same path the worker takes at spawn time),
 * then asserts on Core's node registry.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Remote_Job_Rewrite_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Registry;

class AggregatorTopologyTest extends TestCase {

	private string $tmp = '';

	/** Snapshot of the process-lifetime token-resolver registry, restored in tearDown. */
	private array $saved_resolvers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->tmp             = $this->make_temp_dir( 'aggregator-topology-' );
		$this->saved_resolvers = Core::$config_resolvers;
		Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
		Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\' );
		Command_Interpreter_Node::register_namespace( 'Newspack_Nodes\\' );
		$tmp = $this->tmp;
		Core::register_config_namespace(
			'config',
			static function ( string $key ) use ( $tmp ) {
				static $values = null;
				if ( null === $values ) {
					$values = [
						'logs_dir'      => $tmp . '/logs',
						'offsets_dir'   => $tmp . '/offsets',
						'num_partitions' => '1',
						'segment_size'  => '1048576',
						'min_segments'  => '3',
						'num_segments'  => '5',
						'min_lifetime'  => '120',
						'lifetime'      => '7200',
						'max_segments'  => '11',
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

	private function load_aggregator(): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( '_router' );

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

		Topology_Loader::load( 'aggregator', 0, $interpreter );
		return $interpreter;
	}

	public function test_mounts_firehose_topic_and_remote_job_rewrite(): void {
		$this->load_aggregator();

		$this->assertInstanceOf( Topic_Node::class, Core::node( 'firehose:topic' ) );
		$this->assertInstanceOf( Remote_Job_Rewrite_Node::class, Core::node( 'remote-job-rewrite' ) );
	}

	public function test_does_not_mount_stream_merger(): void {
		$this->load_aggregator();

		$this->assertNull( Core::node( 'stream-merger' ) );
	}

	public function test_remote_job_rewrite_targets_firehose_topic(): void {
		$this->load_aggregator();

		// connect_node sets the logical TO target; every node physically sinks
		// into _command_interpreter, so assert on target().
		$this->assertSame( 'firehose:topic', Core::node( 'remote-job-rewrite' )->target() );
	}

	/**
	 * The firehose Topic must receive the new-order retention geometry
	 * (dir_template num_partitions segment_size min_segments num_segments
	 * min_lifetime lifetime max_segments) from the seeded `<config:*>` tokens.
	 */
	public function test_firehose_topic_carries_new_retention_geometry(): void {
		$this->load_aggregator();

		$topic = Core::node( 'firehose:topic' );
		$this->assertInstanceOf( Topic_Node::class, $topic );
		$this->assertSame( 3, $this->topic_geometry( $topic, 'min_segments' ) );
		$this->assertSame( 5, $this->topic_geometry( $topic, 'num_segments' ) );
		$this->assertSame( 120, $this->topic_geometry( $topic, 'min_lifetime' ) );
		$this->assertSame( 7200, $this->topic_geometry( $topic, 'lifetime' ) );
		$this->assertSame( 11, $this->topic_geometry( $topic, 'max_segments' ) );
	}

	private function topic_geometry( Topic_Node $topic, string $prop ): int {
		return ( new \ReflectionProperty( Topic_Node::class, $prop ) )->getValue( $topic );
	}
}
