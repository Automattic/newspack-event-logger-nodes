<?php
/**
 * AggregatorCITest: unit tests for Aggregator_CI, the M2 service-CI that
 * collapses two legacy controllers (AggregatorController +
 * AggregatorStatusController) which both registered under
 * newspack-nodes-aggregator/v1.
 *
 * Three verbs:
 *   status  — per-server partition status (lifted from
 *             AggregatorStatusController::get_status, the purpose-built
 *             body that AggregatorController's stub delegated to).
 *   health  — cache reachability + timestamp (lifted from
 *             AggregatorController::health).
 *   servers — sequential array of registered servers with public-safe
 *             shape (lifted from AggregatorController::list_servers).
 *             Distinct from Servers_CI.list which returns a keyed map.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Aggregator_CI_Node;
use Newspack_Event_Logger_Nodes\Server_Registry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Aggregator_CI_Node::class )]
class AggregatorCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching ServersCITest / StatusCITest.
		$this->tmp = '/tmp/aggregator-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
		Core::$memd                   = new InMemoryMemcached();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// status verb
	// ---------------------------------------------------------------------

	public function test_status_verb_returns_empty_map_when_no_servers(): void {
		$ci     = new Aggregator_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'aggregator', 'status' );

		$this->assertSame( [], $result );
	}

	public function test_status_verb_returns_per_server_partition_blocks(): void {
		$registry = new Server_Registry();
		$registry->add( 'spoke1', [
			'url'     => 'https://spoke.example/',
			'enabled' => true,
			'logs'    => [ 'firehose.log' ],
		] );
		$registry->reset_cache();

		Core::$memd->set( 'aggregator_status:spoke1:p0', [ 'state' => 'connected', 'lag' => 1234 ], 60 );

		$ci     = new Aggregator_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'aggregator', 'status' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'spoke1', $result );
		$this->assertSame( 'spoke1', $result['spoke1']['id'] );
		$this->assertStringStartsWith( 'https://spoke.example', $result['spoke1']['url'] );
		$this->assertTrue( $result['spoke1']['enabled'] );
		$this->assertArrayHasKey( 'partitions', $result['spoke1'] );
		$this->assertArrayHasKey( 0, $result['spoke1']['partitions'] );
		$this->assertSame( 'connected', $result['spoke1']['partitions'][0]['state'] );
		$this->assertSame( 1234, $result['spoke1']['partitions'][0]['lag'] );
	}

	public function test_status_verb_uses_empty_block_on_cache_miss(): void {
		$registry = new Server_Registry();
		$registry->add( 'spoke2', [
			'url'     => 'https://other.example/',
			'enabled' => false,
			'logs'    => [ 'firehose.log' ],
		] );
		$registry->reset_cache();

		$ci     = new Aggregator_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'aggregator', 'status' );

		$this->assertArrayHasKey( 'spoke2', $result );
		$this->assertSame( [], $result['spoke2']['partitions'][0] );
		$this->assertFalse( $result['spoke2']['enabled'] );
	}

	public function test_status_verb_clamps_num_partitions_to_max_16(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 64 ] );
		$registry = new Server_Registry();
		$registry->add( 'spoke3', [ 'url' => 'https://x.example/', 'enabled' => true ] );
		$registry->reset_cache();

		$ci     = new Aggregator_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'aggregator', 'status' );

		$this->assertArrayHasKey( 'spoke3', $result );
		$this->assertCount( 16, $result['spoke3']['partitions'] );
	}

	public function test_status_verb_clamps_num_partitions_min_1(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 0 ] );
		$registry = new Server_Registry();
		$registry->add( 'sp', [ 'url' => 'https://x.example/', 'enabled' => true ] );
		$registry->reset_cache();

		$ci     = new Aggregator_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'aggregator', 'status' );

		$this->assertCount( 1, $result['sp']['partitions'] );
	}

	// ---------------------------------------------------------------------
	// health verb
	// ---------------------------------------------------------------------

	public function test_health_verb_reports_cache_available(): void {
		// Core::$memd seeded in setUp().
		$ci = new Aggregator_CI_Node( new Server_Registry() );

		$before = \time();
		$result = VerbHarness::fire( $ci, 'aggregator', 'health' );
		$after  = \time();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['healthy'] );
		$this->assertTrue( $result['cache'] );
		$this->assertIsInt( $result['timestamp'] );
		$this->assertGreaterThanOrEqual( $before, $result['timestamp'] );
		$this->assertLessThanOrEqual( $after, $result['timestamp'] );
	}

	public function test_health_verb_reports_cache_unavailable_when_memd_null(): void {
		Core::$memd = null;
		$ci         = new Aggregator_CI_Node( new Server_Registry() );

		$result = VerbHarness::fire( $ci, 'aggregator', 'health' );

		$this->assertTrue( $result['healthy'] );
		$this->assertFalse( $result['cache'] );
	}

	// ---------------------------------------------------------------------
	// servers verb
	// ---------------------------------------------------------------------

	public function test_servers_verb_returns_empty_sequential_array(): void {
		$ci     = new Aggregator_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'aggregator', 'servers' );

		$this->assertSame( [], $result );
	}

	public function test_servers_verb_returns_sequential_array_of_public_shapes(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [
			'url'           => 'https://a.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'secret-pw-1',
			'logs'          => [ 'firehose.log', 'errors.log' ],
		] );
		$registry->reset_cache();

		$ci     = new Aggregator_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'aggregator', 'servers' );

		$this->assertIsArray( $result );
		// Sequential (not keyed by id) — distinguishes from Servers_CI.list.
		$this->assertArrayHasKey( 0, $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'site-a', $result[0]['id'] );
		$this->assertSame( 'https://a.example.com', $result[0]['url'] );
		$this->assertTrue( $result[0]['enabled'] );
		$this->assertSame( [ 'firehose.log', 'errors.log' ], $result[0]['logs'] );
		$this->assertTrue( $result[0]['has_credentials'] );
		// Credentials are NOT leaked into the response.
		$this->assertArrayNotHasKey( 'auth_username', $result[0] );
		$this->assertArrayNotHasKey( 'auth_password', $result[0] );
	}

	public function test_servers_verb_reports_no_credentials(): void {
		$registry = new Server_Registry();
		$registry->add( 'plain', [ 'url' => 'https://plain.example.com' ] );
		$registry->reset_cache();

		$ci     = new Aggregator_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'aggregator', 'servers' );

		$this->assertCount( 1, $result );
		$this->assertFalse( $result[0]['has_credentials'] );
	}

	// ---------------------------------------------------------------------
	// auth-gating
	//
	// Legacy AggregatorController + AggregatorStatusController both call
	// read_permissions_check(), which enforces manage_options on every
	// verb. Aggregator_CI mirrors that — even read-only verbs (status,
	// health, servers) require manage_options.
	// ---------------------------------------------------------------------

	public function test_status_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci    = new Aggregator_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'aggregator', 'status' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_health_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci    = new Aggregator_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'aggregator', 'health' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_servers_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci    = new Aggregator_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'aggregator', 'servers' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// ---------------------------------------------------------------------
	// schema-driven dispatch + stateful-registry reach
	//
	// After the schema migration the three verbs live in node_schema()['verbs']
	// with handlers, and the ctor-injected registry is reached via $self->registry
	// (node_schema is static and can't `use` the ctor arg). The fake-registry test
	// proves the dispatched handler actually read THE INJECTED instance, not a
	// fresh/global one.
	// ---------------------------------------------------------------------

	public function test_node_schema_lists_all_verbs_with_handlers(): void {
		$verbs = [];
		foreach ( Aggregator_CI_Node::node_schema()['verbs'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}

		foreach ( [ 'status', 'health', 'servers' ] as $name ) {
			$this->assertArrayHasKey( $name, $verbs, "node_schema must list the '{$name}' verb" );
			$this->assertIsCallable( $verbs[ $name ]['handler'] );
		}
	}

	public function test_servers_verb_reads_the_injected_registry(): void {
		// Duck-typed Server_Registry whose get_all() returns a sentinel server.
		// If the handler reaches $self->registry (the injected instance) the
		// sentinel surfaces in the response; a handler bound to a different
		// registry would not see it.
		$fake = new class() extends Server_Registry {
			public bool $reached = false;
			public function reset_cache(): void {}
			public function get_all(): array {
				$this->reached = true;
				return [ 'sentinel' => [ 'url' => 'https://sentinel.example/', 'enabled' => true, 'logs' => [] ] ];
			}
			public function is_config_server( string $id ): bool {
				return false;
			}
		};

		$ci     = new Aggregator_CI_Node( $fake );
		$result = VerbHarness::fire( $ci, 'aggregator', 'servers' );

		$this->assertTrue( $fake->reached, 'handler must reach the injected registry via $self->registry' );
		$this->assertCount( 1, $result );
		$this->assertSame( 'sentinel', $result[0]['id'] );
	}
}
