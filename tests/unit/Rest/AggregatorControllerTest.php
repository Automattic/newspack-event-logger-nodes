<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\AggregatorController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AggregatorController::class )]
class AggregatorControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		PerformanceControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_register_routes_registers_three_endpoints(): void {
		( new AggregatorController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes-aggregator/v1/status', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes-aggregator/v1/servers', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes-aggregator/v1/health', $GLOBALS['_rest_routes'] );
	}

	public function test_get_status_returns_well_formed_response(): void {
		$ctrl = new AggregatorController();
		$req  = new \WP_REST_Request();
		$resp = $ctrl->get_status( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$data = $resp->get_data();
		// Real-shape response: an associative array keyed by server id.
		// Empty when no servers are configured (test default).
		$this->assertIsArray( $data );
	}

	public function test_list_servers_returns_array(): void {
		$ctrl = new AggregatorController();
		$req  = new \WP_REST_Request();
		$resp = $ctrl->list_servers( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertIsArray( $resp->get_data() );
	}

	public function test_health_reports_cache_status(): void {
		$ctrl = new AggregatorController();
		$resp = $ctrl->health( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'healthy', $body );
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertTrue( $body['healthy'] );
	}

	public function test_permission_callback_rejects_unauthorized(): void {
		$ctrl = new AggregatorController();
		$GLOBALS['_current_user_can'] = false;
		$result = $ctrl->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
