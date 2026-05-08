<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfUrlsController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfUrlsController::class )]
class PerfUrlsControllerTest extends TestCase {
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

	public function test_register_routes_registers_urls_endpoints(): void {
		( new PerfUrlsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/urls', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/urls/(?P<hash>[a-f0-9]{8,64})', $GLOBALS['_rest_routes'] );
	}

	public function test_get_urls_returns_paginated_shape(): void {
		$ctrl = new PerfUrlsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'sort', 'count' );
		$req->set_param( 'order', 'desc' );
		$req->set_param( 'limit', 10 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'search', '' );
		$req->set_param( 'server', '' );
		$resp = $ctrl->get_urls( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertArrayHasKey( 'total', $body );
		$this->assertArrayHasKey( 'limit', $body );
		$this->assertArrayHasKey( 'offset', $body );
	}

	public function test_get_url_detail_with_invalid_hash_returns_400(): void {
		$ctrl = new PerfUrlsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'hash', 'short' );
		$resp = $ctrl->get_url_detail( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_get_url_detail_returns_404_for_unknown_hash(): void {
		$ctrl = new PerfUrlsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'hash', 'abcdef0123456789' );
		$resp = $ctrl->get_url_detail( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfUrlsController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
