<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\ServersController;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ServersControllerCrudTest extends TestCase {
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

	public function test_full_crud_round_trip(): void {
		$ctrl = new ServersController();

		// CREATE.
		$create_req = new \WP_REST_Request();
		$create_req->set_param( 'id', 'spoke-a' );
		$create_req->set_param( 'url', 'https://spoke-a.example.com' );
		$create_req->set_param( 'auth_username', 'user' );
		$create_req->set_param( 'auth_password', 'pass' );
		$create_req->set_param( 'enabled', true );
		$create_req->set_param( 'logs', [ 'firehose.log' ] );
		$create_resp = $ctrl->create_item( $create_req );
		$this->assertInstanceOf( \WP_REST_Response::class, $create_resp );
		$this->assertSame( 201, $create_resp->get_status() );

		// READ.
		$get_req = new \WP_REST_Request();
		$get_req->set_param( 'id', 'spoke-a' );
		$get_resp = $ctrl->get_item( $get_req );
		$this->assertInstanceOf( \WP_REST_Response::class, $get_resp );
		$body = $get_resp->get_data();
		$this->assertSame( 'spoke-a', $body['id'] );
		// rtrim normalization in ServerRegistry::validate_config — accept either
		// the canonical form (no trailing slash) or unchanged form depending on
		// whether the registry stripped the slash.
		$this->assertSame( 'https://spoke-a.example.com', $body['url'] );
		$this->assertTrue( $body['has_credentials'] );

		// UPDATE.
		$update_req = new \WP_REST_Request();
		$update_req->set_param( 'id', 'spoke-a' );
		$update_req->set_param( 'enabled', false );
		$update_resp = $ctrl->update_item( $update_req );
		$this->assertInstanceOf( \WP_REST_Response::class, $update_resp );

		$verify = $ctrl->get_item( $get_req );
		$this->assertFalse( $verify->get_data()['enabled'] );

		// LIST.
		$list_resp = $ctrl->get_items( new \WP_REST_Request() );
		$list_body = $list_resp->get_data();
		$this->assertArrayHasKey( 'spoke-a', $list_body );

		// DELETE.
		$delete_req = new \WP_REST_Request();
		$delete_req->set_param( 'id', 'spoke-a' );
		$delete_resp = $ctrl->delete_item( $delete_req );
		$this->assertInstanceOf( \WP_REST_Response::class, $delete_resp );

		$gone = $ctrl->get_item( $get_req );
		$this->assertInstanceOf( \WP_Error::class, $gone );
	}

	public function test_create_duplicate_returns_409(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke-x' );
		$req->set_param( 'url', 'https://spoke-x.example.com' );
		$req->set_param( 'enabled', true );
		$req->set_param( 'logs', [ 'firehose.log' ] );

		$first  = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );

		$second = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 409, $second->data['status'] ?? 0 );
	}
}
