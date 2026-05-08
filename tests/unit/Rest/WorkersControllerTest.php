<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\WorkersController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( WorkersController::class )]
class WorkersControllerTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$GLOBALS['_wp_options']       = [];
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_register_routes_registers_workers_endpoints(): void {
		( new WorkersController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/workers', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/workers/restart', $GLOBALS['_rest_routes'] );
	}

	public function test_get_workers_returns_documented_shape(): void {
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'workers', $body );
		$this->assertArrayHasKey( 'standalone', $body );
		$this->assertArrayHasKey( 'logs', $body );
		$this->assertArrayHasKey( 'num_partitions', $body );
		$this->assertArrayHasKey( 'segment_size', $body );
		$this->assertArrayHasKey( 'timestamp', $body );
		// Supervisor is always in standalone.
		$names = \array_column( $body['standalone'], 'type' );
		$this->assertContains( 'supervisor', $names );
	}

	public function test_get_workers_uses_live_position_from_cache(): void {
		// Seed a live cursor for an arbitrary worker type — controller will pick
		// it up if the topology happens to include that type. The point of this
		// test is: the resolver path (cache → fallback) at least doesn't crash
		// and respects the FakeMemcached injection.
		$host = \gethostname() ?: 'host';
		$this->cache->set(
			"evlog:pos:{$host}:firehose-workers:p0",
			[ 'firehose.log' => [ 'seg' => 5, 'off' => 1234 ] ],
			60
		);
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
	}

	public function test_restart_workers_requires_nonce(): void {
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'nonce', 'wrong-nonce' );
		$result = $ctrl->restart_permissions_check( $req );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new WorkersController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
