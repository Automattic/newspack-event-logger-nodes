<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\AggregatorStatusController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AggregatorStatusController::class )]
class AggregatorStatusControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		PerformanceControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_register_routes_registers_status(): void {
		( new AggregatorStatusController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes-aggregator/v1/status', $GLOBALS['_rest_routes'] );
	}

	public function test_get_status_returns_empty_when_no_servers(): void {
		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( [], $resp->get_data() );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new AggregatorStatusController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
