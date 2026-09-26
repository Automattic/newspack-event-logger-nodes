<?php
/**
 * hub.tsl parse + mount wiring.
 *
 * hub.tsl composes aggregator (firehose) and hub-control (settings/discovery)
 * into one worker via `include aggregator` + `include hub-control`. Because
 * frontmatter is read from the top-level file only, the composite pins its own
 * `num_partitions = 1` rather than relying on hub-control's, which `include`
 * skips. Loads the TSL in-process via Topology_Loader against a real
 * CommandInterpreter+Router pair, then asserts on Core's node registry and on
 * the frontmatter/synthesis Bootstrap reads before ever spawning a worker.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Event_Logger_Nodes\Remote_Job_Rewrite_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\HTTP_Out_Node;
use Newspack_Nodes\Lock_Node;
use Newspack_Nodes\Null_Node;
use Newspack_Nodes\Remote_Source_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Settings_Sync_Node;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Vault;
use Newspack_Nodes\Vault_Group_Node;

class HubTopologyTest extends TestCase {

	private string $tmp = '';

	/** Snapshot of the process-lifetime token-resolver registry, restored in tearDown. */
	private array $saved_resolvers = [];

	/** Snapshot of the process-lifetime security ratchet, restored in tearDown. */
	private ?int $saved_secure_level = null;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp                = $this->make_temp_dir( 'hub-topology-' );
		$this->saved_resolvers    = Core::$config_resolvers;
		$this->saved_secure_level = Core::$secure_level;
		Core::$secure_level       = null;
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
		$this->seed_vault( 'tw7', [ 'url' => 'https://tw7.example', 'group' => 'spoke' ] );
	}

	protected function tearDown(): void {
		Core::$config_resolvers = $this->saved_resolvers;
		Core::$secure_level     = $this->saved_secure_level;
		Vault::get_instance()->reset_cache();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	private function load_hub(): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( '_router' );

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

		Topology_Loader::load( 'hub', 0, $interpreter );
		return $interpreter;
	}

	public function test_frontmatter_pins_num_partitions_to_one(): void {
		$front = Topology_Analyzer::frontmatter( 'hub' );
		$this->assertArrayHasKey( 'num_partitions', $front );
		$this->assertSame( '1', $front['num_partitions'] );
	}

	public function test_synthesis_honours_the_pin_over_a_nondefault_global(): void {
		$entry = Topology_Registry::synthesize_entry( 'hub', 3, Lock_Node::STALE_TIMEOUT, 0 );
		$this->assertNotNull( $entry );
		// The global num_partitions passed in here (3) is what a worker fleet
		// would otherwise mount per topology; the pin must win regardless.
		$this->assertSame( 1, $entry['num_partitions'] );
	}

	public function test_mounts_the_firehose_leg(): void {
		$this->load_hub();

		$this->assertInstanceOf( Vault_Group_Node::class, Core::node( 'firehose' ) );
		$this->assertInstanceOf( Remote_Job_Rewrite_Node::class, Core::node( 'remote-job-rewrite' ) );
		$this->assertInstanceOf( Topic_Node::class, Core::node( 'firehose:topic' ) );
		$this->assertInstanceOf( Remote_Source_Node::class, Core::node( 'firehose:tw7' ) );
	}

	public function test_mounts_the_settings_leg(): void {
		$this->load_hub();

		$this->assertInstanceOf( Settings_Sync_Node::class, Core::node( 'settings-sync' ) );
		$this->assertInstanceOf( Discovery_Collector_Node::class, Core::node( 'discovery-collector' ) );
		$this->assertInstanceOf( Vault_Group_Node::class, Core::node( 'settings' ) );
		$this->assertInstanceOf( Null_Node::class, Core::node( 'null' ) );
	}

	public function test_settings_group_shares_the_same_spoke_membership_as_firehose(): void {
		$this->load_hub();

		// firehose and settings are two independent Vault_Group nodes, both
		// over group `spoke`, so the one seeded `tw7` entry builds a child in
		// each — an HTTP_Out here, a Remote_Source on firehose.
		$this->assertInstanceOf( HTTP_Out_Node::class, Core::node( 'settings:tw7' ) );
	}

	public function test_frontmatter_pins_on_demand_idle_to_zero(): void {
		$front = Topology_Analyzer::frontmatter( 'hub' );
		$this->assertArrayHasKey( 'on_demand_idle', $front );
		$this->assertSame( '0', $front['on_demand_idle'] );
	}

	public function test_secure_is_honoured_only_at_the_top_level(): void {
		$this->load_hub();

		// aggregator.tsl and hub-control.tsl each end in `secure`, but both are
		// `include`d — only hub.tsl's own trailing `secure` line runs, climbing
		// the ratchet from unset to exactly 1 rather than to 2.
		$this->assertSame( 1, Core::$secure_level );
	}
}
