<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SettingsController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SettingsController::class )]
class SettingsControllerTest extends TestCase {
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

	public function test_register_routes_registers_settings_endpoint(): void {
		( new SettingsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/settings', $GLOBALS['_rest_routes'] );
	}

	public function test_update_setting_writes_whitelisted_int(): void {
		$ctrl = new SettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'newspack_nodes_num_partitions' );
		$req->set_param( 'value', 8 );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( 'newspack_nodes_num_partitions', $body['option'] );
		$this->assertTrue( $body['updated'] );
		$this->assertSame( 8, $GLOBALS['_wp_options']['newspack_nodes_num_partitions'] );
	}

	public function test_update_setting_rejects_unknown_option(): void {
		$ctrl = new SettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'not_in_allowlist' );
		$req->set_param( 'value', 'whatever' );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_rejects_negative_int(): void {
		$ctrl = new SettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'newspack_nodes_num_partitions' );
		$req->set_param( 'value', -5 );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_max_lifespan_allows_zero(): void {
		$ctrl = new SettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'newspack_nodes_max_lifespan' );
		$req->set_param( 'value', 0 );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 0, $GLOBALS['_wp_options']['newspack_nodes_max_lifespan'] );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new SettingsController() )->update_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
