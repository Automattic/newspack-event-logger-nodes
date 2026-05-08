<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-errors-stream-controller.php';

use Newspack_Event_Logger_Nodes\Rest\ErrorsStreamController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ErrorsStreamController::class )]
class ErrorsStreamControllerTest extends TestCase {

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

	public function test_register_routes_mounts_errors_endpoint(): void {
		( new ErrorsStreamController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/errors', $GLOBALS['_rest_routes'] );
	}

	public function test_transform_line_drops_invalid_json(): void {
		$this->assertNull( ErrorsStreamController::transform_line( 'not json', 0 ) );
	}

	public function test_transform_line_drops_missing_rid(): void {
		$line = \json_encode( [ 'k' => 'error', 'm' => 'fail' ] );
		$this->assertNull( ErrorsStreamController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_emits_canonical_shape(): void {
		$line = \json_encode( [
			'rid' => 'r1',
			'ts'  => 1700000000.5,
			'k'   => 'error',
			'm'   => 'something failed',
			'n'   => 7,
		] );
		$out = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame(
			[
				'rid' => 'r1',
				'ts'  => 1700000000.5,
				'k'   => 'error',
				'm'   => 'something failed',
				'n'   => 7,
			],
			$out
		);
	}

	public function test_transform_line_truncates_long_message(): void {
		$big   = \str_repeat( 'x', 2000 );
		$line  = \json_encode( [ 'rid' => 'r1', 'm' => $big ] );
		$out   = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 1003, \strlen( $out['m'] ) );
		$this->assertStringEndsWith( '...', $out['m'] );
	}

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new ErrorsStreamController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->stream_permissions_check() );
	}
}
