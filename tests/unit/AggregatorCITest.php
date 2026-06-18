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
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Vault;
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
		Vault::get_instance()->reset_cache();
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		Vault::get_instance()->reset_cache();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Seed the substrate Vault directly via its option store so the
	 * status/servers verbs (which read `Vault::get_instance()`) see the
	 * server set under test. `logs` is intentionally NOT stored — the Vault
	 * drops it, matching the substrate public shape.
	 *
	 * @param string               $id     Server id.
	 * @param array<string, mixed> $config Server config (url, enabled, auth_*).
	 */
	private function seed_vault( string $id, array $config ): void {
		$existing            = $GLOBALS['_wp_options'][ Vault::OPTION_KEY ] ?? [];
		$existing[ $id ]     = $config;
		$GLOBALS['_wp_options'][ Vault::OPTION_KEY ] = $existing;
		Vault::get_instance()->reset_cache();
	}

	// ---------------------------------------------------------------------
	// status verb
	// ---------------------------------------------------------------------

	public function test_status_verb_returns_empty_map_when_no_servers(): void {
		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'status' );

		$this->assertSame( [], $result );
	}

	public function test_status_verb_returns_per_server_partition_blocks(): void {
		$this->seed_vault( 'spoke1', [
			'url' => 'https://spoke.example/',
		] );

		Core::$memd->set( 'aggregator_status:spoke1:p0', [ 'state' => 'connected', 'lag' => 1234 ], 60 );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'status' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'spoke1', $result );
		$this->assertSame( 'spoke1', $result['spoke1']['id'] );
		$this->assertStringStartsWith( 'https://spoke.example', $result['spoke1']['url'] );
		$this->assertArrayNotHasKey( 'enabled', $result['spoke1'] );
		$this->assertArrayHasKey( 'partitions', $result['spoke1'] );
		$this->assertArrayHasKey( 0, $result['spoke1']['partitions'] );
		$this->assertSame( 'connected', $result['spoke1']['partitions'][0]['state'] );
		$this->assertSame( 1234, $result['spoke1']['partitions'][0]['lag'] );
	}

	public function test_status_verb_uses_empty_block_on_cache_miss(): void {
		$this->seed_vault( 'spoke2', [
			'url' => 'https://other.example/',
		] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'status' );

		$this->assertArrayHasKey( 'spoke2', $result );
		$this->assertSame( [], $result['spoke2']['partitions'][0] );
		$this->assertArrayNotHasKey( 'enabled', $result['spoke2'] );
	}

	public function test_status_verb_clamps_num_partitions_to_max_16(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 64 ] );
		$this->seed_vault( 'spoke3', [ 'url' => 'https://x.example/' ] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'status' );

		$this->assertArrayHasKey( 'spoke3', $result );
		$this->assertCount( 16, $result['spoke3']['partitions'] );
	}

	public function test_status_verb_clamps_num_partitions_min_1(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 0 ] );
		$this->seed_vault( 'sp', [ 'url' => 'https://x.example/' ] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'status' );

		$this->assertCount( 1, $result['sp']['partitions'] );
	}

	// ---------------------------------------------------------------------
	// health verb
	// ---------------------------------------------------------------------

	public function test_health_verb_reports_cache_available(): void {
		// Core::$memd seeded in setUp().
		$interpreter = new Aggregator_CI_Node();

		$before = \time();
		$result = VerbHarness::fire( $interpreter, 'aggregator', 'health' );
		$after  = \time();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['healthy'] );
		$this->assertTrue( $result['cache'] );
		$this->assertIsInt( $result['timestamp'] );
		$this->assertGreaterThanOrEqual( $before, $result['timestamp'] );
		$this->assertLessThanOrEqual( $after, $result['timestamp'] );
	}

	public function test_health_verb_reports_cache_unavailable_when_memd_null(): void {
		Core::$memd  = null;
		$interpreter = new Aggregator_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'aggregator', 'health' );

		$this->assertTrue( $result['healthy'] );
		$this->assertFalse( $result['cache'] );
	}

	// ---------------------------------------------------------------------
	// servers verb
	// ---------------------------------------------------------------------

	public function test_servers_verb_returns_empty_sequential_array(): void {
		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'servers' );

		$this->assertSame( [], $result );
	}

	public function test_servers_verb_returns_sequential_array_of_public_shapes(): void {
		$this->seed_vault( 'site-a', [
			'url'           => 'https://a.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'secret-pw-1',
		] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'servers' );

		$this->assertIsArray( $result );
		// Sequential (not keyed by id) — distinguishes from Servers_CI.list.
		$this->assertArrayHasKey( 0, $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'site-a', $result[0]['id'] );
		$this->assertSame( 'https://a.example.com', $result[0]['url'] );
		// `enabled` and `logs` are dropped — mirrors the substrate Vault_CI public shape.
		$this->assertArrayNotHasKey( 'enabled', $result[0] );
		$this->assertArrayNotHasKey( 'logs', $result[0] );
		$this->assertTrue( $result[0]['has_credentials'] );
		// Credentials are NOT leaked into the response.
		$this->assertArrayNotHasKey( 'auth_username', $result[0] );
		$this->assertArrayNotHasKey( 'auth_password', $result[0] );
	}

	public function test_servers_verb_reports_no_credentials(): void {
		$this->seed_vault( 'plain', [ 'url' => 'https://plain.example.com' ] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'servers' );

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
		$interpreter                  = new Aggregator_CI_Node();
		$result                       = VerbHarness::fire( $interpreter, 'aggregator', 'status' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_health_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter                  = new Aggregator_CI_Node();
		$result                       = VerbHarness::fire( $interpreter, 'aggregator', 'health' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_servers_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter                  = new Aggregator_CI_Node();
		$result                       = VerbHarness::fire( $interpreter, 'aggregator', 'servers' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// ---------------------------------------------------------------------
	// schema-driven dispatch + Vault reach
	//
	// After the schema migration the three verbs live in node_schema()['commands']
	// with handlers. The status/servers handlers read the substrate
	// `Newspack_Nodes\Vault` singleton directly (no injected registry); the
	// seeded-Vault test proves the dispatched handler actually read the option
	// store, not a fresh/empty view.
	// ---------------------------------------------------------------------

	public function test_node_schema_lists_all_verbs_with_handlers(): void {
		$verbs = [];
		foreach ( Aggregator_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}

		foreach ( [ 'status', 'health', 'servers' ] as $name ) {
			$this->assertArrayHasKey( $name, $verbs, "node_schema must list the '{$name}' verb" );
			$this->assertIsCallable( $verbs[ $name ]['handler'] );
		}
	}

	public function test_all_verbs_declare_no_args(): void {
		// status/health/servers read no $payload/$args — none of their handlers
		// even declare a $payload param, so each verb stays args => [].
		$verbs = [];
		foreach ( Aggregator_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}

		foreach ( [ 'status', 'health', 'servers' ] as $name ) {
			$this->assertSame( [], $verbs[ $name ]['args'], "'{$name}' must declare no args" );
		}
	}

	public function test_servers_verb_reads_the_vault(): void {
		// A server seeded into the substrate Vault must surface in the response,
		// proving the dispatched handler reads `Vault::get_instance()` rather
		// than a fresh/empty view.
		$this->seed_vault( 'sentinel', [ 'url' => 'https://sentinel.example/', 'enabled' => true ] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'servers' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'sentinel', $result[0]['id'] );
	}

	/**
	 * Tachikoma uniform-construction parity: the substrate `make_node` calls
	 * a no-arg ctor. Aggregator_CI now reads the Vault singleton directly (no
	 * injected object dep), so a bare `new Aggregator_CI_Node()` must dispatch
	 * its verbs against the seeded Vault with no further wiring.
	 */
	public function test_constructible_via_no_arg_ctor(): void {
		$this->seed_vault( 'sentinel', [ 'url' => 'https://s.example/', 'enabled' => true ] );

		$interpreter = new Aggregator_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'aggregator', 'servers' );

		$this->assertSame( 'sentinel', $result[0]['id'] );
	}
}
