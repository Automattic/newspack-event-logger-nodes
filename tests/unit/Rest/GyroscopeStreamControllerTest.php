<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-inflight-tracker.php';
require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-gyroscope-stream-controller.php';

use Newspack_Event_Logger_Nodes\Rest\GyroscopeStreamController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GyroscopeStreamController::class )]
class GyroscopeStreamControllerTest extends TestCase {

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

	public function test_register_routes_mounts_at_firehose_gyroscope(): void {
		( new GyroscopeStreamController() )->register_routes();
		// SSE-flavor route is at /firehose/gyroscope, distinct from the sync stub at /gyroscope/timeline.
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/gyroscope', $GLOBALS['_rest_routes'] );
		$this->assertArrayNotHasKey( 'newspack-nodes/v1/gyroscope/timeline', $GLOBALS['_rest_routes'] );
	}

	public function test_args_clip_interval_to_safe_range(): void {
		( new GyroscopeStreamController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/gyroscope']['args'];
		$cb   = $args['interval']['sanitize_callback'];
		$this->assertSame( 100, $cb( 50 ) );
		$this->assertSame( 10000, $cb( 99999 ) );
		$this->assertSame( 1500, $cb( 1500 ) );
	}

	public function test_default_interval_is_one_second(): void {
		( new GyroscopeStreamController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/gyroscope']['args'];
		$this->assertSame( 1000, $args['interval']['default'] );
	}

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new GyroscopeStreamController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->stream_permissions_check() );
	}
}
