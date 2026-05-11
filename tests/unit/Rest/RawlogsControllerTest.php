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
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RawlogsController::class )]
class RawlogsControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_actions']       = [];
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

	public function test_register_routes_uses_get_method_with_args(): void {
		( new RawlogsController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/rawlogs'];
		$this->assertSame( 'GET', $route['methods'] );
		$this->assertIsCallable( $route['callback'] );
		$this->assertIsCallable( $route['permission_callback'] );
		$this->assertArrayHasKey( 'log', $route['args'] );
		$this->assertArrayHasKey( 'interval', $route['args'] );
		$this->assertArrayHasKey( 'positions', $route['args'] );
	}

	public function test_args_clip_interval_to_safe_range(): void {
		( new RawlogsController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/rawlogs']['args'];
		$cb   = $args['interval']['sanitize_callback'];
		$this->assertSame( 100, $cb( 50 ) );      // below floor → clamped
		$this->assertSame( 100, $cb( 0 ) );       // zero clamped to 100
		$this->assertSame( 100, $cb( -50 ) );     // negative clamped
		$this->assertSame( 10000, $cb( 99999 ) ); // above ceiling → clamped
		$this->assertSame( 500, $cb( 500 ) );    // mid-range pass-through
		$this->assertSame( 100, $cb( 100 ) );    // floor accepted
		$this->assertSame( 10000, $cb( 10000 ) ); // ceiling accepted
	}

	public function test_args_positions_sanitizer_trims_and_handles_non_string(): void {
		( new RawlogsController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/rawlogs']['args'];
		$cb   = $args['positions']['sanitize_callback'];
		$this->assertSame( 'abc', $cb( '  abc  ' ) );
		$this->assertSame( '', $cb( '' ) );
		$this->assertSame( '', $cb( null ) );
		$this->assertSame( '', $cb( 42 ) ); // non-string → empty
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

	public function test_transform_line_renders_packed_message_value(): void {
		// Build a packed Message wrapping a structured payload.
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = 1700000000.0;
		$msg[ Message::VALUE ]     = [ 'rid' => 'r1', 'k' => 'process (start)', 'm' => '/x' ];
		$packed                    = Message::packed( $msg );

		$out = RawlogsController::transform_line( \trim( $packed ), 2 );
		$this->assertNotNull( $out );
		$this->assertSame( 2, $out['p'] );
		// The output is the json-encoded VALUE — verify rid is present.
		$this->assertStringContainsString( 'r1', $out['line'] );
		$this->assertStringContainsString( 'process (start)', $out['line'] );
	}

	public function test_transform_line_returns_raw_when_not_a_packed_message(): void {
		// A non-JSON line just passes through (after empty/length checks).
		$out = RawlogsController::transform_line( 'plain text line', 5 );
		$this->assertSame( 5, $out['p'] );
		$this->assertSame( 'plain text line', $out['line'] );
	}

	public function test_transform_line_truncates_after_packed_message_render(): void {
		// Even after packed-Message rendering, oversize output gets truncated.
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = 1700000000.0;
		$msg[ Message::VALUE ]     = [ 'rid' => 'r2', 'big' => \str_repeat( 'X', 2000 ) ];
		$packed                    = Message::packed( $msg );

		$out = RawlogsController::transform_line( \trim( $packed ), 0 );
		$this->assertNotNull( $out );
		$this->assertSame( 1003, \strlen( $out['line'] ) );
		$this->assertStringEndsWith( '...', $out['line'] );
	}

	public function test_sanitize_log_param_normalizes_dot_log(): void {
		$ctrl = new RawlogsController();
		$this->assertSame( 'firehose.log', $ctrl->sanitize_log_param( 'firehose.log' ) );
	}

	public function test_sanitize_log_param_normalizes_keyless_form(): void {
		$ctrl = new RawlogsController();
		// Caller passes 'firehose' (without .log suffix) — controller resolves to firehose.log.
		$this->assertSame( 'firehose.log', $ctrl->sanitize_log_param( 'firehose' ) );
		$this->assertSame( 'jobs.log', $ctrl->sanitize_log_param( 'jobs' ) );
	}

	public function test_sanitize_log_param_falls_back_to_default_when_unknown(): void {
		$ctrl = new RawlogsController();
		// Unknown log file falls back to the default (first entry in the registry).
		$result = $ctrl->sanitize_log_param( 'no-such-log.log' );
		$this->assertNotEmpty( $result );
		$this->assertStringEndsWith( '.log', $result );
	}

	public function test_sanitize_log_param_empty_returns_default(): void {
		$ctrl = new RawlogsController();
		$result = $ctrl->sanitize_log_param( '' );
		$this->assertNotEmpty( $result );
		$this->assertStringEndsWith( '.log', $result );
	}

	public function test_sanitize_log_param_null_returns_default(): void {
		$ctrl = new RawlogsController();
		$result = $ctrl->sanitize_log_param( null );
		$this->assertNotEmpty( $result );
	}

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new RawlogsController();
		$GLOBALS['_current_user_can'] = false;
		$result                       = $ctrl->stream_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_stream_returns_wp_error_when_slot_acquisition_fails(): void {
		// stream() delegates to stream_log → stream_log_run → start_sse_stream.
		// A fail-all FakeMemcached forces acquire_sse_slot() to return false,
		// triggering the WP_Error 429 path. stream_log() forwards the error
		// (bypassing the `exit` happy-path), which is the only way to drive
		// stream() to completion inside a unit test.
		$cache = new FakeMemcached( fail_all: true );
		PerformanceControllerBase::set_cache( $cache );
		SSEControllerBase::set_cache( $cache );

		$req = new \WP_REST_Request();
		$req->set_param( 'log', 'firehose.log' );
		$resp = ( new RawlogsController() )->stream( $req );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'too_many_connections', $resp->get_error_code() );
	}

	public function test_permissions_check_passes_capable(): void {
		$ctrl                         = new RawlogsController();
		$GLOBALS['_current_user_can'] = true;
		$result                       = $ctrl->stream_permissions_check();
		$this->assertTrue( $result );
	}
}
