<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-firehose-controller.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-rawlogs-controller.php';

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\RawlogsController;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RawlogsController::class )]
class RawlogsControllerTest extends TestCase {

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

	public function test_register_routes_mounts_rawlogs_endpoint(): void {
		( new RawlogsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/rawlogs', $GLOBALS['_rest_routes'] );
	}

	public function test_args_clip_interval_to_safe_range(): void {
		( new RawlogsController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/rawlogs']['args'];
		$cb   = $args['interval']['sanitize_callback'];
		$this->assertSame( 100, $cb( 50 ) );
		$this->assertSame( 10000, $cb( 99999 ) );
		$this->assertSame( 500, $cb( 500 ) );
	}

	public function test_transform_line_truncates_oversize_lines(): void {
		$line = \str_repeat( 'x', 1500 );
		$out  = RawlogsController::transform_line( $line, 0 );
		$this->assertSame( 0, $out['p'] );
		$this->assertSame( 1003, \strlen( $out['line'] ) );
		$this->assertStringEndsWith( '...', $out['line'] );
	}

	public function test_transform_line_skips_empty(): void {
		$this->assertNull( RawlogsController::transform_line( '', 0 ) );
	}

	public function test_transform_line_emits_partition_label(): void {
		$out = RawlogsController::transform_line( 'hello', 3 );
		$this->assertSame( [ 'p' => 3, 'line' => 'hello' ], $out );
	}

	public function test_sanitize_log_param_normalizes_dot_log(): void {
		$ctrl = new RawlogsController();
		$this->assertSame( 'firehose.log', $ctrl->sanitize_log_param( 'firehose.log' ) );
	}

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new RawlogsController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->stream_permissions_check() );
	}
}
