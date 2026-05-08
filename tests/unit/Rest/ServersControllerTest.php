<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\ServersController;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ServersController::class )]
class ServersControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		ServerRegistry::get_instance()->reset_cache();
		PerformanceControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		ServerRegistry::get_instance()->reset_cache();
		parent::tearDown();
	}

	public function test_register_routes_registers_all_endpoints(): void {
		( new ServersController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/servers', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/servers/(?P<id>[a-zA-Z0-9_-]{1,64})', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/servers/(?P<id>[a-zA-Z0-9_-]{1,64})/test', $GLOBALS['_rest_routes'] );
	}

	public function test_get_items_returns_empty_when_no_servers(): void {
		$ctrl = new ServersController();
		$resp = $ctrl->get_items( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( [], $resp->get_data() );
	}

	public function test_get_item_returns_404_for_unknown_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'unknown' );
		$resp = $ctrl->get_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_create_item_rejects_invalid_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', '' ); // Invalid.
		$req->set_param( 'url', 'https://example.com' );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_create_item_rejects_non_https_url(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke1' );
		$req->set_param( 'url', 'http://example.com' );
		$req->set_param( 'enabled', true );
		$req->set_param( 'logs', [ 'firehose.log' ] );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_validate_logs_rejects_bad_filenames(): void {
		$ctrl = new ServersController();
		$this->assertTrue( $ctrl->validate_logs( [ 'firehose.log', 'jobs.log' ] ) );
		$this->assertFalse( $ctrl->validate_logs( [ '../etc/passwd' ] ) );
		$this->assertFalse( $ctrl->validate_logs( [ 'no-extension' ] ) );
	}

	public function test_sanitize_password_caps_at_256(): void {
		$ctrl = new ServersController();
		$out  = $ctrl->sanitize_password( \str_repeat( 'a', 500 ) );
		$this->assertSame( 256, \strlen( $out ) );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new ServersController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
