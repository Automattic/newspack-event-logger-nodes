<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerformanceController::class )]
class PerformanceControllerTest extends TestCase {
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

	public function test_register_routes_registers_dashboard_and_timing(): void {
		( new PerformanceController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/dashboard', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/timing', $GLOBALS['_rest_routes'] );
	}

	public function test_get_dashboard_returns_overview_shape(): void {
		$ctrl = new PerformanceController();
		$resp = $ctrl->get_dashboard( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'overview', $body['data'] );
		$this->assertArrayHasKey( 'urls', $body['data'] );
	}

	public function test_get_timing_returns_time_series(): void {
		$ctrl = new PerformanceController();
		$resp = $ctrl->get_timing( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'time_series', $body['data'] );
	}

	public function test_permissions_block_unauthorized(): void {
		$ctrl = new PerformanceController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->read_permissions_check() );
	}
}
