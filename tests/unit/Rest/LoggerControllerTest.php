<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\LoggerController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( LoggerController::class )]
class LoggerControllerTest extends TestCase {
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

	public function test_register_routes_registers_config_and_hooks(): void {
		( new LoggerController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/logger/config', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/logger/hooks', $GLOBALS['_rest_routes'] );
	}

	public function test_get_config_includes_documented_keys(): void {
		$ctrl = new LoggerController();
		$resp = $ctrl->get_config( new \WP_REST_Request() );
		$body = $resp->get_data();
		// Echoed config should carry the documented defaults from PerformanceControllerBase::load_config().
		$this->assertArrayHasKey( 'num_partitions', $body['data'] );
		$this->assertArrayHasKey( 'memcache_servers', $body['data'] );
	}

	public function test_list_hooks_returns_categories_key(): void {
		$ctrl = new LoggerController();
		$resp = $ctrl->list_hooks( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'hooks', $body['data'] );
		$this->assertArrayHasKey( 'categories', $body['data'] );
	}
}
