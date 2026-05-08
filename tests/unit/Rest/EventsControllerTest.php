<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\EventsController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( EventsController::class )]
class EventsControllerTest extends TestCase {
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

	public function test_register_routes_registers_recent_and_stats(): void {
		( new EventsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/events/recent', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/events/stats', $GLOBALS['_rest_routes'] );
	}

	public function test_get_recent_returns_data_meta_shape(): void {
		$ctrl = new EventsController();
		$req  = new \WP_REST_Request( [ 'limit' => 50 ] );
		$resp = $ctrl->get_recent( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertArrayHasKey( 'meta', $body );
	}

	public function test_get_stats_returns_time_series_key(): void {
		$ctrl = new EventsController();
		$resp = $ctrl->get_stats( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'time_series', $body['data'] );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$ctrl = new EventsController();
		$GLOBALS['_current_user_can'] = false;
		$result = $ctrl->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
