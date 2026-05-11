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
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ErrorsStreamController::class )]
class ErrorsStreamControllerTest extends TestCase {

	/**
	 * Wrap an entry in a packed Message envelope (positional JSON) — that's
	 * what `transform_line` actually reads off the firehose tail.
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

	// =========================================================================
	// Route registration
	// =========================================================================

	public function test_register_routes_mounts_errors_endpoint(): void {
		( new ErrorsStreamController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/errors', $GLOBALS['_rest_routes'] );
	}

	public function test_route_uses_get_method(): void {
		( new ErrorsStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors'];
		$this->assertSame( 'GET', $route['methods'] );
	}

	public function test_route_args_include_interval_default(): void {
		( new ErrorsStreamController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args'];
		$this->assertArrayHasKey( 'interval', $args );
		$this->assertSame( 1000, $args['interval']['default'] );
	}

	public function test_interval_sanitize_clips_below_minimum(): void {
		( new ErrorsStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args']['interval']['sanitize_callback'];
		$this->assertSame( 100, $cb( 0 ) );
		$this->assertSame( 100, $cb( 50 ) );
		$this->assertSame( 100, $cb( -100 ) );
	}

	public function test_interval_sanitize_clips_above_maximum(): void {
		( new ErrorsStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args']['interval']['sanitize_callback'];
		$this->assertSame( 10000, $cb( 99999 ) );
		$this->assertSame( 10000, $cb( 10001 ) );
	}

	public function test_interval_sanitize_passes_through_in_range(): void {
		( new ErrorsStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args']['interval']['sanitize_callback'];
		$this->assertSame( 1500, $cb( 1500 ) );
		$this->assertSame( 100, $cb( 100 ) );
		$this->assertSame( 10000, $cb( 10000 ) );
	}

	public function test_route_args_include_positions(): void {
		( new ErrorsStreamController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args'];
		$this->assertArrayHasKey( 'positions', $args );
		$this->assertSame( '', $args['positions']['default'] );
	}

	public function test_positions_sanitize_trims_whitespace(): void {
		( new ErrorsStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args']['positions']['sanitize_callback'];
		$this->assertSame( 'foo', $cb( '  foo  ' ) );
		$this->assertSame( '', $cb( '' ) );
	}

	public function test_positions_sanitize_returns_empty_for_non_string(): void {
		( new ErrorsStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/errors']['args']['positions']['sanitize_callback'];
		$this->assertSame( '', $cb( 123 ) );
		$this->assertSame( '', $cb( null ) );
		$this->assertSame( '', $cb( [] ) );
	}

	// =========================================================================
	// transform_line: the core wire-shape contract.
	// =========================================================================

	public function test_transform_line_drops_invalid_json(): void {
		$this->assertNull( ErrorsStreamController::transform_line( 'not json', 0 ) );
	}

	public function test_transform_line_drops_non_array_decoded(): void {
		// `42` is valid JSON but not an array — should be dropped.
		$this->assertNull( ErrorsStreamController::transform_line( '42', 0 ) );
		$this->assertNull( ErrorsStreamController::transform_line( '"string"', 0 ) );
	}

	public function test_transform_line_drops_when_value_missing(): void {
		// Valid JSON array but Message::VALUE index missing → entry null → drop.
		$this->assertNull( ErrorsStreamController::transform_line( '[1,2,3]', 0 ) );
	}

	public function test_transform_line_drops_missing_rid(): void {
		$line = self::packed_entry_line( [ 'k' => 'error', 'm' => 'fail' ] );
		$this->assertNull( ErrorsStreamController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_drops_empty_rid(): void {
		// `empty()` semantics: '' / '0' / null drop.
		$line = self::packed_entry_line( [ 'rid' => '', 'k' => 'error' ] );
		$this->assertNull( ErrorsStreamController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_drops_when_entry_not_array(): void {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not an array';
		$line                  = Message::packed( $msg );
		$this->assertNull( ErrorsStreamController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_emits_canonical_shape(): void {
		$line = self::packed_entry_line( [
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

	public function test_transform_line_supplies_defaults_for_missing_fields(): void {
		// Entry has rid only — other fields default to '', 0, 0.
		$line = self::packed_entry_line( [ 'rid' => 'rmin' ] );
		$out  = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 'rmin', $out['rid'] );
		$this->assertSame( 0, $out['ts'] );
		$this->assertSame( '', $out['k'] );
		$this->assertSame( '', $out['m'] );
		$this->assertSame( 0, $out['n'] );
	}

	public function test_transform_line_truncates_long_message(): void {
		$big   = \str_repeat( 'x', 2000 );
		$line  = self::packed_entry_line( [ 'rid' => 'r1', 'm' => $big ] );
		$out   = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 1003, \strlen( $out['m'] ) );
		$this->assertStringEndsWith( '...', $out['m'] );
		// First 1000 chars must be from the original (no munging).
		$this->assertSame( \str_repeat( 'x', 1000 ), \substr( $out['m'], 0, 1000 ) );
	}

	public function test_transform_line_message_at_boundary_not_truncated(): void {
		// 1000-char message: at the boundary, no truncation expected.
		$exactly_1000 = \str_repeat( 'y', 1000 );
		$line         = self::packed_entry_line( [ 'rid' => 'r1', 'm' => $exactly_1000 ] );
		$out          = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 1000, \strlen( $out['m'] ) );
		$this->assertStringEndsNotWith( '...', $out['m'] );
	}

	public function test_transform_line_message_just_over_boundary_truncated(): void {
		// 1001 chars → must truncate to 1003 (1000 + '...').
		$over = \str_repeat( 'z', 1001 );
		$line = self::packed_entry_line( [ 'rid' => 'r1', 'm' => $over ] );
		$out  = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 1003, \strlen( $out['m'] ) );
	}

	public function test_transform_line_passes_non_string_message_through(): void {
		// `m` field is sometimes a number / array — only string truncation applies;
		// non-string values flow through unchanged (the React tree handles display).
		$line = self::packed_entry_line( [ 'rid' => 'r1', 'm' => [ 'nested' => 'value' ] ] );
		$out  = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( [ 'nested' => 'value' ], $out['m'] );
	}

	public function test_transform_line_passes_numeric_message_through(): void {
		$line = self::packed_entry_line( [ 'rid' => 'r1', 'm' => 42 ] );
		$out  = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 42, $out['m'] );
	}

	public function test_transform_line_partition_arg_is_unused(): void {
		// transform_line ignores the partition argument — same input → same output.
		$line = self::packed_entry_line( [ 'rid' => 'r1', 'k' => 'error' ] );
		$this->assertSame(
			ErrorsStreamController::transform_line( $line, 0 ),
			ErrorsStreamController::transform_line( $line, 99 )
		);
	}

	public function test_transform_line_preserves_high_precision_timestamp(): void {
		$line = self::packed_entry_line( [ 'rid' => 'r1', 'ts' => 1700000000.123456 ] );
		$out  = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertEqualsWithDelta( 1700000000.123456, $out['ts'], 0.000001 );
	}

	public function test_transform_line_preserves_zero_n(): void {
		// `n` should be 0 by default but explicitly-zero must survive.
		$line = self::packed_entry_line( [ 'rid' => 'r1', 'n' => 0 ] );
		$out  = ErrorsStreamController::transform_line( $line, 0 );
		$this->assertSame( 0, $out['n'] );
	}

	// =========================================================================
	// Permission / namespace boundary
	// =========================================================================

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new ErrorsStreamController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->stream_permissions_check() );
	}

	public function test_permissions_check_allows_admin(): void {
		$ctrl = new ErrorsStreamController();
		$this->assertTrue( $ctrl->stream_permissions_check() );
	}

	public function test_namespace_within_allowed_endpoint_prefixes(): void {
		// Security boundary: ErrorsStreamController must mount under a prefix on
		// the SSE allowlist. If someone changes NAMESPACE without updating the
		// allowlist this test catches the divergence.
		$this->assertContains(
			ErrorsStreamController::NAMESPACE,
			SSEControllerBase::ALLOWED_ENDPOINT_PREFIXES
		);
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

		$resp = ( new ErrorsStreamController() )->stream( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'too_many_connections', $resp->get_error_code() );
	}
}
