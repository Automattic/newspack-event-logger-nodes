<?php
/**
 * hub-control.tsl parse + mount wiring.
 *
 * ELN's hub-control is now an overlay: it `include`s the substrate `settings-sync`
 * topology (Consumer tailing settings.p0, the substrate Settings_Sync_Node with
 * the six-axis remote_* geometry pushes, and the shared spokes:tee) and layers on
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
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Settings_Sync_Node;
use Newspack_Nodes\Tee_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Registry;

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
		$front = Topology_Registry::frontmatter( 'hub-control' );
		$this->assertArrayHasKey( 'num_partitions', $front );
		$this->assertSame( '1', $front['num_partitions'] );
	}

	public function test_mounts_the_four_pipeline_nodes(): void {
		$this->load_hub_control();

		$this->assertInstanceOf( Consumer_Node::class, Core::node( 'settings:consumer' ) );
		$this->assertInstanceOf( Settings_Sync_Node::class, Core::node( 'settings-sync' ) );
		$this->assertInstanceOf( Tee_Node::class, Core::node( 'spokes:tee' ) );
		$this->assertInstanceOf( Discovery_Collector_Node::class, Core::node( 'discovery-collector' ) );
	}

	public function test_connect_node_targets_wire_the_pipeline(): void {
		$this->load_hub_control();

		// connect_node sets the logical TO target (Tachikoma owner); every node
		// physically sinks into _command_interpreter, so assert on target().
		$this->assertSame( 'settings-sync', Core::node( 'settings:consumer' )->target() );
		$this->assertSame( 'spokes:tee', Core::node( 'settings-sync' )->target() );
		$this->assertSame( 'spokes:tee', Core::node( 'discovery-collector' )->target() );
	}

	public function test_settings_sync_registers_all_ten_settings(): void {
		$this->load_hub_control();

		$registry = ( new \ReflectionProperty( Settings_Sync_Node::class, 'registry' ) );
		$map = $registry->getValue( Core::node( 'settings-sync' ) );

		// Seven substrate locals (num_partitions + the SIX remote_* geometry keys:
		// segment_size, min_segments, num_segments, min_lifetime, lifetime,
		// max_segments) arrive through the `include settings-sync`; three ELN app
		// keys (rules, log_memory, flush_every_line) come from the overlay.
		$this->assertCount( 10, $map );
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
		$this->assertSame(
			[ [ 'to' => 'settings', 'remote' => 'newspack_nodes_num_partitions' ] ],
			$map['newspack_nodes_num_partitions']
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
