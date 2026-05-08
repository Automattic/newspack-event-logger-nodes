<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerfSettingsController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfSettingsController::class )]
class PerfSettingsControllerTest extends TestCase {
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

	public function test_register_routes_registers_settings(): void {
		( new PerfSettingsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/settings', $GLOBALS['_rest_routes'] );
	}

	public function test_update_setting_writes_array_option(): void {
		$ctrl = new PerfSettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'newspack_event_logger_nodes_log_events' );
		$req->set_param( 'value', [ 'init', 'shutdown' ] );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertTrue( $body['updated'] );
		$this->assertSame( [ 'init', 'shutdown' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_update_setting_writes_bool_option(): void {
		$ctrl = new PerfSettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'newspack_event_logger_nodes_log_memory' );
		$req->set_param( 'value', true );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	public function test_update_setting_rejects_unknown_option(): void {
		$ctrl = new PerfSettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'unsafe_arbitrary_option' );
		$req->set_param( 'value', 'x' );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_rejects_negative_threshold(): void {
		$ctrl = new PerfSettingsController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'option', 'newspack_event_logger_nodes_auto_disable_threshold' );
		$req->set_param( 'value', -5 );
		$resp = $ctrl->update_setting( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}
}
