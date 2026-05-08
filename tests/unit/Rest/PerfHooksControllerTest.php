<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfHooksController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfHooksController::class )]
class PerfHooksControllerTest extends TestCase {
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

	public function test_register_routes_registers_hooks_routes(): void {
		( new PerfHooksController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/registered-hooks', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/hook-categories', $GLOBALS['_rest_routes'] );
	}

	public function test_get_registered_hooks_returns_categorized_shape(): void {
		$ctrl = new PerfHooksController();
		$resp = $ctrl->get_registered_hooks( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'total_hooks', $body );
		$this->assertArrayHasKey( 'categories', $body );
		$this->assertArrayHasKey( 'hooks_by_category', $body );
	}

	public function test_get_hook_categories_returns_categories_and_config(): void {
		$ctrl = new PerfHooksController();
		$resp = $ctrl->get_hook_categories( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'categories', $body );
		$this->assertArrayHasKey( 'config', $body );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfHooksController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
