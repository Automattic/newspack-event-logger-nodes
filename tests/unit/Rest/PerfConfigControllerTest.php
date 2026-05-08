<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerfConfigController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfConfigController::class )]
class PerfConfigControllerTest extends TestCase {
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

	public function test_register_routes_registers_config(): void {
		( new PerfConfigController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/config', $GLOBALS['_rest_routes'] );
	}

	public function test_get_config_returns_documented_keys(): void {
		$ctrl = new PerfConfigController();
		$resp = $ctrl->get_config( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'config', $body );
		$cfg = $body['config'];
		// All 9 keys present.
		foreach ( [ 'log_events', 'custom_events', 'log_urls', 'skip_urls', 'auto_disable_threshold', 'auto_protect_time_threshold', 'significant_events', 'log_memory', 'flush_every_line' ] as $key ) {
			$this->assertArrayHasKey( $key, $cfg );
		}
	}

	public function test_update_config_writes_multiple_options(): void {
		$ctrl = new PerfConfigController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'log_events', [ 'init', 'shutdown' ] );
		$req->set_param( 'auto_disable_threshold', 1500 );
		$req->set_param( 'log_memory', true );
		$resp = $ctrl->update_config( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertContains( 'log_events', $body['updated'] );
		$this->assertContains( 'auto_disable_threshold', $body['updated'] );
		$this->assertContains( 'log_memory', $body['updated'] );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfConfigController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
