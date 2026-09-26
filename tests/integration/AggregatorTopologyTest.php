<?php
/**
 * aggregator.tsl parse + mount wiring (post-cutover pull graph).
 *
 * The hub topology no longer mounts a Stream_Merger. It ships a `firehose`
 * Vault_Group over group `spoke` (one Remote_Source per spoke, following the
 * Vault on reload), the Remote_Job_Rewrite_Node that flips aggregated
 * `k:"job"` lines to `k:"remote_job"` before the Topic write, and the firehose
 * Topic sink. Loads the TSL in-process via Topology_Loader against a real
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
use Newspack_Nodes\Remote_Source_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Vault;
use Newspack_Nodes\Vault_Group_Node;

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
		Vault::get_instance()->reset_cache();
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

	public function test_mounts_the_firehose_vault_group_with_its_recorded_config(): void {
		$this->load_aggregator();

		$group = Core::node( 'firehose' );
		$this->assertInstanceOf( Vault_Group_Node::class, $group );
		$dump = $group->dump_config();
		$this->assertStringContainsString( "command_node firehose:config assume_clean_shutdown true\n", $dump );
		$this->assertStringContainsString( "command_node firehose:config set_multi_writer true\n", $dump );
	}

	public function test_firehose_group_and_its_spoke_child_target_remote_job_rewrite(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();

		$this->assertSame( 'remote-job-rewrite', Core::node( 'firehose' )->target() );
		$child = Core::node( 'firehose:tw7' );
		$this->assertInstanceOf( Remote_Source_Node::class, $child );
		$this->assertSame( 'remote-job-rewrite', $child->target() );
	}

	public function test_firehose_spoke_child_carries_topology_scoped_offsetlog_and_deadletter(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();

		$args       = Core::node( 'firehose:tw7' )->arguments();
		$offsetlog  = $args[2] ?? '';
		$deadletter = $args[3] ?? '';
		$this->assertStringContainsString( 'aggregator.firehose.tw7.p0', $offsetlog );
		$this->assertStringContainsString( '/offsets/', $offsetlog );
		$this->assertStringContainsString( 'aggregator.firehose.tw7.p0', $deadletter );
		$this->assertStringContainsString( '/deadletter/', $deadletter );
	}

	public function test_firehose_spoke_child_dump_config_shows_replayed_toggles(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();

		$dump = Core::node( 'firehose:tw7' )->dump_config();
		$this->assertStringContainsString( 'assume_clean_shutdown true', $dump );
		$this->assertStringContainsString( 'set_multi_writer true', $dump );
	}

	/**
	 * A Vault id colliding with an already-declared sibling name is skipped
	 * loudly rather than aborting the whole load: the group must be declared
	 * AFTER the node whose name it could collide with, so the collision is the
	 * group's own catch rather than an uncaught `make_node` conflict.
	 */
	public function test_a_vault_id_colliding_with_the_topic_name_is_skipped_not_fatal(): void {
		$this->seed_vault_servers(
			[
				'tw7'   => [ 'url' => 'https://tw7.example', 'group' => 'spoke' ],
				'topic' => [ 'url' => 'https://topic.example', 'group' => 'spoke' ],
			]
		);

		$this->load_aggregator();

		$this->assertInstanceOf( Topic_Node::class, Core::node( 'firehose:topic' ) );
		$this->assertInstanceOf( Remote_Source_Node::class, Core::node( 'firehose:tw7' ) );
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
