<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\RequestLogController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RequestLogController::class )]
class RequestLogControllerTest extends TestCase {
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

	public function test_register_routes_registers_list_and_detail(): void {
		( new RequestLogController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/request-log/list', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/request-log/detail/(?P<id>[A-Za-z0-9_-]+)', $GLOBALS['_rest_routes'] );
	}

	public function test_get_list_returns_data_meta(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_list( new \WP_REST_Request( [ 'limit' => 10 ] ) );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertArrayHasKey( 'meta', $body );
	}

	public function test_get_detail_with_id_echoes_id(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request( [ 'id' => 'rid-xyz' ] ) );
		$body = $resp->get_data();
		$this->assertSame( 'rid-xyz', $body['data']['request_id'] );
		$this->assertArrayHasKey( 'entries', $body['data'] );
	}

	public function test_get_detail_without_id_returns_404(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->data['status'] ?? 0 );
	}
}
