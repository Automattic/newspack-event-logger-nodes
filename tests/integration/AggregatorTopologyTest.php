<?php
/**
 * aggregator.tsl parse + mount wiring (post-cutover pull graph).
 *
 * The hub topology mounts no Stream_Merger. It ships a `spokes` Vault_Group
 * over group `spoke` (one Remote_Source broker per spoke, following the Vault
 * on reload, each carrying the spoke's firehose and `sources/php` on one
 * connection), the Remote_Job_Rewrite_Node that flips aggregated `k:"job"`
 * lines to `k:"remote_job"` before the Topic write, the firehose Topic sink,
 * and `php-errors.p0` with the hub's own error-log Tail beside it. Loads the
 * TSL in-process via Topology_Loader against a real
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

	/** php's `error_log` before the test pointed it into scratch. */
	private string $saved_error_log = '';

	protected function setUp(): void {
		parent::setUp();
		$this->tmp             = $this->make_temp_dir( 'aggregator-topology-' );
		$this->saved_resolvers = Core::$config_resolvers;
		$this->saved_error_log = (string) \ini_get( 'error_log' );
		\ini_set( 'error_log', "{$this->tmp}/php-errors-4194.log" );
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
		\ini_set( 'error_log', $this->saved_error_log );
		Core::$config_resolvers = $this->saved_resolvers;
		Vault::get_instance()->reset_cache();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	private function load_aggregator( int $partition = 0 ): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( '_router' );

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

		Topology_Loader::load( 'aggregator', $partition, $interpreter );
		return $interpreter;
	}

	public function test_mounts_firehose_topic_and_remote_job_rewrite(): void {
		$this->load_aggregator();

		$this->assertInstanceOf( Topic_Node::class, Core::node( 'firehose:topic' ) );
		$this->assertInstanceOf( Remote_Job_Rewrite_Node::class, Core::node( 'remote-job-rewrite' ) );
	}

	public function test_mounts_the_spokes_vault_group_with_its_recorded_config(): void {
		$this->load_aggregator();

		$group = Core::node( 'spokes' );
		$this->assertInstanceOf( Vault_Group_Node::class, $group );
		$dump = $group->dump_config();
		$this->assertStringContainsString( "command_node spokes:config assume_clean_shutdown true\n", $dump );
		$this->assertStringContainsString( "command_node spokes:config set_multi_writer true\n", $dump );
	}

	public function test_each_spoke_reads_its_firehose_and_php_errors_into_their_own_targets(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();
		Core::node( 'spokes:tw7' )->fire();

		$firehose = Core::node( 'spokes:tw7:firehose.p0' );
		$php      = Core::node( 'spokes:tw7:sources:php' );
		$this->assertInstanceOf( \Newspack_Nodes\Remote_Consumer_Node::class, $firehose );
		$this->assertInstanceOf( \Newspack_Nodes\Remote_Consumer_Node::class, $php );
		$this->assertSame( 'remote-job-rewrite', $firehose->target() );
		$this->assertSame( 'php-errors:partition', $php->target() );
	}

	public function test_each_spoke_reader_keeps_a_topology_scoped_cursor_under_the_spokes_root(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();
		Core::node( 'spokes:tw7' )->fire();

		$this->assertStringEndsWith( '/aggregator.tw7/firehose.p0', $this->read_private( Core::node( 'spokes:tw7:firehose.p0' ), 'offsetlog_dir' ) );
		$this->assertStringEndsWith( '/aggregator.tw7/sources:php', $this->read_private( Core::node( 'spokes:tw7:sources:php' ), 'offsetlog_dir' ) );
	}

	public function test_the_hub_tails_its_own_php_error_log_into_php_errors(): void {
		$this->load_aggregator();

		$tail = Core::node( 'php-errors:tail' );
		$this->assertInstanceOf( \Newspack_Nodes\File_Tail_Node::class, $tail );
		$this->assertSame( (string) \ini_get( 'error_log' ), $this->read_private( $tail, 'source_file' ) );
		$this->assertSame( 'php-errors:partition', $tail->target() );
		$this->assertInstanceOf( \Newspack_Nodes\Partition_Node::class, Core::node( 'php-errors:partition' ) );
	}

	/**
	 * Worker p2 of a multi-partition hub carries the spoke's `firehose.p2`
	 * alone: `sources/php` and the hub's own error log are read once, on p0.
	 */
	public function test_off_partition_zero_a_worker_reads_its_own_firehose_partition_alone(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator( 2 );
		Core::node( 'spokes:tw7' )->fire();

		$this->assertSame( 'remote-job-rewrite', Core::node( 'spokes:tw7:firehose.p2' )?->target() );
		$this->assertNull( Core::node( 'spokes:tw7:sources:php' ) );
		$this->assertNull( Core::node( 'spokes:tw7:firehose.p0' ) );
		$tail = Core::node( 'php-errors:tail' );
		$this->assertSame( 0, $tail->interval_ms, 'the hub tails its own log on p0 alone' );
		$this->assertNotNull( $tail->idle_since() );
	}

	/**
	 * Worker p0 alone writes `php-errors.p0`, so its 4 KB cap is lifted: a
	 * database error line past PIPE_BUF, FROM trail and all, still lands.
	 */
	public function test_a_php_error_line_past_pipe_buf_lands_in_php_errors(): void {
		$this->load_aggregator();
		$line                      = 'PHP Warning:  WordPress database error ' . \str_repeat( 'q', 6144 ) . "\n";
		$message                   = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_BYTESTREAM;
		$message[ \Newspack_Nodes\Message::FROM ]  = 'spokes:tw7:sources:php/sources/php';
		$message[ \Newspack_Nodes\Message::VALUE ] = $line;

		$partition = Core::node( 'php-errors:partition' );
		$partition->fill( $message );

		$this->assertSame( [ $line ], $this->read_partition_values( $partition ) );
	}

	/**
	 * A spoke joining on RELOAD is built as the load builds one: worker p2
	 * reads its `firehose.p2` and leaves `sources/php` to p0.
	 */
	public function test_a_spoke_joining_on_reload_off_partition_zero_reads_its_firehose_alone(): void {
		$this->load_aggregator( 2 );
		Vault::get_instance()->add( 'tw8', [ 'url' => 'https://tw8.example', 'group' => 'spoke' ] );

		Core::node( 'spokes' )->update_graph();
		Core::node( 'spokes:tw8' )->fire();

		$this->assertSame( 'remote-job-rewrite', Core::node( 'spokes:tw8:firehose.p2' )?->target() );
		$this->assertNull( Core::node( 'spokes:tw8:sources:php' ) );
	}

	public function test_a_spoke_child_dump_config_shows_replayed_toggles(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();

		$dump = Core::node( 'spokes:tw7' )->dump_config();
		$this->assertStringContainsString( 'assume_clean_shutdown true', $dump );
		$this->assertStringContainsString( 'set_multi_writer true', $dump );
	}

	/**
	 * A Vault id colliding with a declared node name fails the load, naming the
	 * id: `config` would take the group's own `spokes:config` interpreter, so
	 * the group unwinds whole, its other children with it.
	 */
	public function test_a_vault_id_colliding_with_the_groups_interpreter_fails_the_load(): void {
		$this->seed_vault_servers(
			[
				'tw7'    => [ 'url' => 'https://tw7.example', 'group' => 'spoke' ],
				'config' => [ 'url' => 'https://config.example', 'group' => 'spoke' ],
			]
		);

		$caught = $this->caught( fn () => $this->load_aggregator(), 'a colliding Vault id must fail the load' );
		$this->assertStringContainsString( 'building Vault id config', $caught->getMessage() );

		$this->assertSame( [ null, null, null ], [ Core::node( 'spokes' ), Core::node( 'spokes:config' ), Core::node( 'spokes:tw7' ) ] );
	}

	/**
	 * A colliding id the group meets on reload escapes, and every other spoke
	 * the group holds or gains stays built beside the node it collided with.
	 */
	public function test_a_vault_id_colliding_on_reload_escapes_and_keeps_the_other_children(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_aggregator();
		Vault::get_instance()->add( 'config', [ 'url' => 'https://config.example', 'group' => 'spoke' ] );
		Vault::get_instance()->add( 'tw8', [ 'url' => 'https://tw8.example', 'group' => 'spoke' ] );

		$caught = $this->caught( fn () => Core::node( 'spokes' )->update_graph(), 'a colliding Vault id must escape the reload' );
		$this->assertStringContainsString( 'building Vault id config', $caught->getMessage() );

		$this->assertInstanceOf( Remote_Source_Node::class, Core::node( 'spokes:tw7' ) );
		$this->assertInstanceOf( Remote_Source_Node::class, Core::node( 'spokes:tw8' ) );
		$this->assertInstanceOf( Command_Interpreter_Node::class, Core::node( 'spokes:config' ) );
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
