<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\GyroscopeController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GyroscopeController::class )]
class GyroscopeControllerTest extends TestCase {
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

	public function test_register_routes_registers_timeline(): void {
		( new GyroscopeController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/gyroscope/timeline', $GLOBALS['_rest_routes'] );
	}

	public function test_get_timeline_with_request_id_echoes_id(): void {
		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => 'rid-abc' ] ) );
		$body = $resp->get_data();
		$this->assertSame( 'rid-abc', $body['data']['request_id'] );
		$this->assertArrayHasKey( 'events', $body['data'] );
	}

	public function test_get_timeline_without_id_returns_empty_events(): void {
		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertSame( [], $body['data']['events'] );
	}

	public function test_permissions_block_unauthorized(): void {
		$ctrl = new GyroscopeController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->read_permissions_check() );
	}
}
