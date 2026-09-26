<?php
/**
 * hub-control.tsl parse + mount wiring.
 *
 * ELN's hub-control is now an overlay: it `include`s the substrate `settings-sync`
 * topology (Consumer tailing settings.p0, the substrate Settings_Sync_Node with
 * the six-axis remote_* geometry pushes) and layers on
 * the Discovery_Collector_Node plus the three ELN app-key pushes. Loads the TSL
 * in-process via Topology_Loader against a real CommandInterpreter+Router pair
 * (the same path the worker takes at spawn time), then asserts on Core's node
 * registry and the registry the `add_setting` :config verbs mutated.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\HTTP_Out_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Settings_Sync_Node;
use Newspack_Nodes\Tee_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Vault;

class HubControlTopologyTest extends TestCase {

	private string $tmp = '';

	/** Snapshot of the process-lifetime token-resolver registry, restored in tearDown. */
	private array $saved_resolvers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->tmp             = $this->make_temp_dir( 'hub-control-' );
		$this->saved_resolvers = Core::$config_resolvers;
		Topology_Registry::register_stock_dir(
			\dirname( __DIR__, 2 ) . '/topologies'
		);
		$this->use_scratch_config( $this->tmp, [ 'num_segments' => '4' ] );
	}

	protected function tearDown(): void {
		Core::$config_resolvers = $this->saved_resolvers;
		Vault::get_instance()->reset_cache();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	private function load_hub_control(): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( '_router' );

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

		Topology_Loader::load( 'hub-control', 0, $interpreter );
		return $interpreter;
	}

	public function test_hub_control_is_single_instance(): void {
		$front = Topology_Analyzer::frontmatter( 'hub-control' );
		$this->assertArrayHasKey( 'num_partitions', $front );
		$this->assertSame( '1', $front['num_partitions'] );
	}

	public function test_mounts_the_three_pipeline_nodes(): void {
		$this->load_hub_control();

		$this->assertInstanceOf( Consumer_Node::class, Core::node( 'settings:consumer' ) );
		$this->assertInstanceOf( Settings_Sync_Node::class, Core::node( 'settings-sync' ) );
		$this->assertInstanceOf( Discovery_Collector_Node::class, Core::node( 'discovery-collector' ) );
	}

	public function test_connect_node_targets_wire_the_pipeline(): void {
		$this->load_hub_control();

		// connect_node sets the logical TO target (Tachikoma owner); every node
		// physically sinks into _command_interpreter, so assert on target().
		$this->assertSame( 'settings-sync', Core::node( 'settings:consumer' )->target() );
		// Both minters fan out to the `settings` Vault_Group over group `spoke`,
		// which itself sinks its per-spoke HTTP_Out egress to `null` — a spoke
		// replies through allow_replies_to, never through this leg.
		$this->assertSame( [ 'settings' ], Core::node( 'settings-sync' )->target() );
		$this->assertSame( [ 'settings' ], Core::node( 'discovery-collector' )->target() );
		$this->assertSame( 'null', Core::node( 'settings' )->target() );
	}

	public function test_settings_group_records_both_egress_declarations(): void {
		$this->load_hub_control();

		// Neither minter may reply through the other's declaration, so both
		// allow_replies_to calls are recorded on the group and replayed to
		// every spoke's HTTP_Out, present or built later.
		$dump = Core::node( 'settings' )->dump_config();
		$this->assertStringContainsString( "command_node settings:config allow_replies_to settings-sync\n", $dump );
		$this->assertStringContainsString( "command_node settings:config allow_replies_to discovery-collector\n", $dump );
	}

	/**
	 * hub-control's `settings-sync` include already declares `settings:consumer`
	 * before the `settings` Vault_Group, so a colliding Vault id is skipped
	 * loudly rather than aborting the load — no reorder needed here, unlike
	 * aggregator.tsl.
	 */
	public function test_a_vault_id_colliding_with_the_settings_consumer_name_is_skipped_not_fatal(): void {
		$this->seed_vault_servers(
			[
				'tw7'      => [ 'url' => 'https://tw7.example', 'group' => 'spoke' ],
				'consumer' => [ 'url' => 'https://consumer.example', 'group' => 'spoke' ],
			]
		);

		$this->load_hub_control();

		$this->assertInstanceOf( Consumer_Node::class, Core::node( 'settings:consumer' ) );
		$this->assertInstanceOf( HTTP_Out_Node::class, Core::node( 'settings:tw7' ) );
	}

	public function test_settings_spoke_child_dump_config_shows_both_allow_replies_to_entries(): void {
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
		$this->load_hub_control();

		$dump = Core::node( 'settings:tw7' )->dump_config();
		$this->assertStringContainsString( 'allow_replies_to settings-sync', $dump );
		$this->assertStringContainsString( 'allow_replies_to discovery-collector', $dump );
	}

	public function test_settings_sync_registers_all_nine_settings(): void {
		$this->load_hub_control();

		$registry = ( new \ReflectionProperty( Settings_Sync_Node::class, 'registry' ) );
		$map = $registry->getValue( Core::node( 'settings-sync' ) );

		// Six substrate locals (the remote_* geometry keys: segment_size,
		// min_segments, num_segments, min_lifetime, lifetime, max_segments) arrive
		// through the `include settings-sync`; three ELN app keys (rules,
		// log_memory, flush_every_line) come from the overlay. A hub no longer
		// pushes its own num_partitions to its spokes.
		$this->assertCount( 9, $map );
		$this->assertArrayNotHasKey( 'newspack_nodes_num_partitions', $map );
		// Substrate-remap (TO=settings). The remote-spoke geometry options live
		// under `newspack_nodes_remote_*` and each maps TWICE: to the spoke's
		// stripped option AND to its own remote_* copy, so a spoke propagates the
		// value onward to ITS spokes. Registry is a LIST of {to,remote} per local.
		$this->assertSame(
			[
				[ 'to' => 'settings', 'remote' => 'newspack_nodes_num_segments' ],
				[ 'to' => 'settings', 'remote' => 'newspack_nodes_remote_num_segments' ],
			],
			$map['newspack_nodes_remote_num_segments']
		);
		// The reborn remote_max_segments is the spoke's HARD cap → spoke max_segments.
		$this->assertSame(
			[
				[ 'to' => 'settings', 'remote' => 'newspack_nodes_max_segments' ],
				[ 'to' => 'settings', 'remote' => 'newspack_nodes_remote_max_segments' ],
			],
			$map['newspack_nodes_remote_max_segments']
		);
		// Perf (TO=performance, remote = same full option name).
		$this->assertSame(
			[ [ 'to' => 'performance', 'remote' => 'newspack_event_logger_nodes_rules' ] ],
			$map['newspack_event_logger_nodes_rules']
		);
		$this->assertSame(
			[ [ 'to' => 'performance', 'remote' => 'newspack_event_logger_nodes_flush_every_line' ] ],
			$map['newspack_event_logger_nodes_flush_every_line']
		);
	}
}
