<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-firehose-stream-controller.php';

use Newspack_Event_Logger_Nodes\Rest\FirehoseStreamController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Smoke tests — route registration + permission boundary. The streaming loop
 * itself isn't exercised in unit tests (would require a child process for the
 * exit() at the end and a working filesystem with rotated segments).
 */
#[CoversClass( FirehoseStreamController::class )]
class FirehoseStreamControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		PerformanceControllerBase::set_cache( new FakeMemcached() );
		SSEControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		SSEControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_register_routes_mounts_stream_endpoint(): void {
		( new FirehoseStreamController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/stream', $GLOBALS['_rest_routes'] );
	}

	public function test_route_args_include_aggregator_flag(): void {
		( new FirehoseStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream'];
		$this->assertArrayHasKey( 'aggregator', $route['args'] );
		$this->assertSame( false, $route['args']['aggregator']['default'] );
	}

	public function test_route_args_include_partition(): void {
		( new FirehoseStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream'];
		$this->assertArrayHasKey( 'partition', $route['args'] );
		$this->assertSame( 0, $route['args']['partition']['default'] );
	}

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new FirehoseStreamController();
		$GLOBALS['_current_user_can'] = false;
		$result                       = $ctrl->stream_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_permissions_check_allows_admin(): void {
		$ctrl = new FirehoseStreamController();
		$this->assertTrue( $ctrl->stream_permissions_check() );
	}
}
