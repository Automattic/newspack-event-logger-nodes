<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerfHooksAvailableController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfHooksAvailableController::class )]
class PerfHooksAvailableControllerTest extends TestCase {
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

	public function test_register_routes_registers_hooks_endpoints(): void {
		( new PerfHooksAvailableController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/hooks/available', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/hooks/configure', $GLOBALS['_rest_routes'] );
	}

	public function test_get_available_hooks_reads_wp_actions(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP global.
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'init' => 1, 'wp_loaded' => 1 ];
		$wp_filter  = [];

		$ctrl = new PerfHooksAvailableController();
		$resp = $ctrl->get_available_hooks( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'hooks', $body );
		$names = \array_column( $body['hooks'], 'name' );
		$this->assertContains( 'init', $names );
		$this->assertContains( 'wp_loaded', $names );

		$wp_actions = [];
		$wp_filter  = [];
	}

	public function test_configure_hooks_updates_options(): void {
		$ctrl = new PerfHooksAvailableController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'hooks', [ 'init', 'wp_loaded' ] );
		$req->set_param( 'custom_events', [ 'my_event' ] );
		$resp = $ctrl->configure_hooks( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( [ 'init', 'wp_loaded' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
		$this->assertSame( [ 'my_event' => true ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfHooksAvailableController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
