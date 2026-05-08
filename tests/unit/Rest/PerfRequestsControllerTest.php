<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfRequestsController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfRequestsController::class )]
class PerfRequestsControllerTest extends TestCase {
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

	public function test_register_routes_registers_search_and_detail(): void {
		( new PerfRequestsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/requests/search/(?P<rid>[a-zA-Z0-9_-]{1,128})', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/requests/(?P<rid>[a-zA-Z0-9_-]{1,128})', $GLOBALS['_rest_routes'] );
	}

	public function test_search_request_returns_404_for_unknown_rid(): void {
		$ctrl = new PerfRequestsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'rid', 'no-such-rid' );
		$resp = $ctrl->search_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_get_request_returns_404_for_invalid_partition(): void {
		$ctrl = new PerfRequestsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'rid', 'rid-zzz' );
		$req->set_param( 'partition', 999 );
		$resp = $ctrl->get_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_get_request_returns_404_for_unknown_rid(): void {
		$ctrl = new PerfRequestsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'rid', 'rid-zzz' );
		$req->set_param( 'partition', 0 );
		$resp = $ctrl->get_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfRequestsController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
