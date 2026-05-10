<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-requests-stream-controller.php';

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\RequestsStreamController;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RequestsStreamController::class )]
class RequestsStreamControllerTest extends TestCase {

	/**
	 * Wrap an entry in a packed Message envelope (positional JSON) — that's
	 * what `transform_line` actually reads off the requests.log tail.
	 */
	private static function packed_entry_line( array $entry ): string {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = $entry;
		return Message::packed( $msg );
	}

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

	public function test_register_routes_mounts_requests_endpoint(): void {
		( new RequestsStreamController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/requests', $GLOBALS['_rest_routes'] );
	}

	public function test_transform_line_drops_invalid_json(): void {
		$this->assertNull( RequestsStreamController::transform_line( 'not-json', 0 ) );
	}

	public function test_transform_line_drops_missing_url(): void {
		$line = self::packed_entry_line( [ 'rid' => 'r1' ] );
		$this->assertNull( RequestsStreamController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_emits_canonical_shape(): void {
		$line = self::packed_entry_line( [
			'rid'            => 'r1',
			'request_method' => 'POST',
			'url'            => '/api',
			'timestamp'      => 1000.0,
			'duration_ms'    => 250,
			'status_code'    => 200,
			'error_status'   => '-',
			'remote_addr'    => '10.0.0.1',
			'user_agent'     => 'TestUA',
		] );
		$out = RequestsStreamController::transform_line( $line, 0 );
		$this->assertSame( 'r1', $out['rid'] );
		$this->assertSame( 'POST', $out['method'] );
		$this->assertSame( '/api', $out['url'] );
		$this->assertEqualsWithDelta( 1000.0, $out['start_time'], 0.001 );
		$this->assertEqualsWithDelta( 1000.25, $out['end_time'], 0.001 );
		$this->assertSame( 250, $out['duration_ms'] );
		$this->assertSame( 200, $out['status_code'] );
		$this->assertSame( 'complete', $out['state'] );
		$this->assertSame( '-', $out['error_status'] );
	}

	public function test_transform_line_truncates_long_url(): void {
		$big   = \str_repeat( 'x', 2500 );
		$line  = self::packed_entry_line( [ 'url' => $big ] );
		$out   = RequestsStreamController::transform_line( $line, 0 );
		$this->assertSame( 2003, \strlen( $out['url'] ) );
		$this->assertStringEndsWith( '...', $out['url'] );
	}

	public function test_transform_line_truncates_long_user_agent(): void {
		$big   = \str_repeat( 'A', 1000 );
		$line  = self::packed_entry_line( [ 'url' => '/x', 'user_agent' => $big ] );
		$out   = RequestsStreamController::transform_line( $line, 0 );
		$this->assertSame( 503, \strlen( $out['user_agent'] ) );
	}

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new RequestsStreamController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->stream_permissions_check() );
	}
}
