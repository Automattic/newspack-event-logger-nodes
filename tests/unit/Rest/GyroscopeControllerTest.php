<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rest\GyroscopeController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GyroscopeController::class )]
class GyroscopeControllerTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']             = [];
		$GLOBALS['_current_user_can']        = true;
		$GLOBALS['_current_user_id']         = 1;
		$GLOBALS['_wp_actions']       = [];
		$GLOBALS['_wp_options']       = [];
		PerformanceControllerBase::set_cache( new FakeMemcached() );

		$this->tmp = '/tmp/gyroscope-controller-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );

		\add_filter(
			'newspack_nodes/base_dir',
			fn () => $this->tmp
		);
		Config::reset();
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions'] = [];
		$this->rmdir_recursive( $this->tmp );
		Config::reset();
		parent::tearDown();
	}

	public function test_register_routes_registers_timeline(): void {
		( new GyroscopeController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/gyroscope/timeline', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_get_method(): void {
		( new GyroscopeController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/gyroscope/timeline'];
		$this->assertSame( 'GET', $route['methods'] );
		$this->assertIsCallable( $route['callback'] );
		$this->assertIsCallable( $route['permission_callback'] );
		$this->assertArrayHasKey( 'request_id', $route['args'] );
	}

	public function test_get_timeline_with_request_id_echoes_id(): void {
		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => 'rid-abc' ] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'rid-abc', $body['data']['request_id'] );
		$this->assertArrayHasKey( 'events', $body['data'] );
		$this->assertArrayHasKey( 'meta', $body );
		$this->assertArrayHasKey( 'scanned', $body['meta'] );
	}

	public function test_get_timeline_without_id_returns_empty_events(): void {
		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( [ 'events' => [] ], $body['data'] );
		$this->assertSame( [], $body['meta'] );
	}

	public function test_get_timeline_with_empty_string_id_returns_empty_events(): void {
		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => '' ] ) );
		$body = $resp->get_data();
		$this->assertSame( [ 'events' => [] ], $body['data'] );
	}

	public function test_get_timeline_with_unknown_id_returns_no_events_with_meta(): void {
		// Create the requests.log dir but no actual entries — scan finds nothing.
		\mkdir( $this->tmp . '/logs/requests.log/p0', 0755, true );

		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => 'no-such-rid' ] ) );
		$body = $resp->get_data();
		$this->assertSame( 'no-such-rid', $body['data']['request_id'] );
		$this->assertSame( [], $body['data']['events'] );
		$this->assertSame( 0, $body['meta']['scanned'] );
	}

	public function test_get_timeline_returns_events_when_request_found(): void {
		// 32-char rid so the fixed-width index round-trip preserves it exactly.
		$rid          = 'rid-found-123456789012345678901';
		$logs_base    = $this->tmp . '/logs/requests.log';
		$partition    = 0;
		$segment_dir  = "{$logs_base}/p{$partition}";
		\mkdir( $segment_dir, 0755, true );

		// Build a request body with events. The body is a packed Message
		// (positional 7-element array); the VALUE field carries the request payload.
		// Use non-zero fractional parts so JSON round-trip preserves the float type
		// (json_encode(1700000000.0) writes "1700000000" which decodes as int).
		$events_payload = [
			[ 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.01, 'n' => 1 ],
			[ 'k' => 'init', 'm' => 'middle', 'ts' => 1700000000.05, 'n' => 2 ],
			[ 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1, 'n' => 3 ],
		];
		$request_body = [
			'rid'          => $rid,
			'url'          => '/test',
			'timestamp'    => 1700000000,
			'duration_ms'  => 100,
			'status_code'  => 200,
			'peak_mb'      => 1,
			'events'       => $events_payload,
			'request_method' => 'GET',
		];
		// Packed as Message::VALUE.
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = 1700000000.1;
		$msg[ Message::VALUE ]     = $request_body;
		$packed_line               = Message::packed( $msg );
		// Write to segment 0.
		\file_put_contents( "{$segment_dir}/0.log", $packed_line );

		// Build the index file using format_index_entry. The Partition's
		// scan_index will read the index file at /idx/0.log.
		$position = [
			'segment_id' => 0,
			'offset'     => 0,
			'length'     => \strlen( $packed_line ),
		];
		// format_index_entry takes the raw packed line, position, and an out-data.
		$index_line = \Newspack_Event_Logger_Nodes\RequestBuilder::format_index_entry( $packed_line, $position );
		$this->assertNotNull( $index_line, 'index line should be formed' );
		\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n" );

		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => $rid ] ) );
		$body = $resp->get_data();
		$this->assertSame( $rid, $body['data']['request_id'] );
		$this->assertSame( $events_payload, $body['data']['events'] );
		// scanned reports >0 since we hit the index.
		$this->assertGreaterThanOrEqual( 1, $body['meta']['scanned'] );
	}

	public function test_get_timeline_treats_request_without_events_as_single_envelope(): void {
		$rid          = 'rid-noevents-1234567890123456789';
		$logs_base    = $this->tmp . '/logs/requests.log';
		$partition    = 0;
		$segment_dir  = "{$logs_base}/p{$partition}";
		\mkdir( $segment_dir, 0755, true );

		// Body has no 'events' key — controller should wrap the body itself
		// as a single event entry.
		$request_body = [
			'rid'            => $rid,
			'url'            => '/no-events',
			'timestamp'      => 1700000000,
			'duration_ms'    => 50,
			'status_code'    => 200,
			'request_method' => 'GET',
			// no 'events' key
		];
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = 1700000000.0;
		$msg[ Message::VALUE ]     = $request_body;
		$packed_line               = Message::packed( $msg );
		\file_put_contents( "{$segment_dir}/0.log", $packed_line );

		$position = [
			'segment_id' => 0,
			'offset'     => 0,
			'length'     => \strlen( $packed_line ),
		];
		$index_line = \Newspack_Event_Logger_Nodes\RequestBuilder::format_index_entry( $packed_line, $position );
		$this->assertNotNull( $index_line );
		\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n" );

		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => $rid ] ) );
		$body = $resp->get_data();
		$this->assertSame( $rid, $body['data']['request_id'] );
		// events should contain the single envelope (the entire body) wrapped.
		$this->assertCount( 1, $body['data']['events'] );
		$this->assertSame( $request_body, $body['data']['events'][0] );
	}

	public function test_get_timeline_with_rid_search_doesnt_match_other_rids(): void {
		$other_rid    = 'rid-other-aaaa1234567890123456';
		$logs_base    = $this->tmp . '/logs/requests.log';
		$segment_dir  = "{$logs_base}/p0";
		\mkdir( $segment_dir, 0755, true );

		$body_payload = [
			'rid'            => $other_rid,
			'url'            => '/x',
			'timestamp'      => 1700000000,
			'request_method' => 'GET',
		];
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = 1700000000.0;
		$msg[ Message::VALUE ]     = $body_payload;
		$packed_line               = Message::packed( $msg );
		\file_put_contents( "{$segment_dir}/0.log", $packed_line );

		$idx_dir = "{$logs_base}/p0/idx";
		\mkdir( $idx_dir, 0755, true );
		$position = [
			'segment_id' => 0,
			'offset'     => 0,
			'length'     => \strlen( $packed_line ),
		];
		$index_line = \Newspack_Event_Logger_Nodes\RequestBuilder::format_index_entry( $packed_line, $position );
		$this->assertNotNull( $index_line );
		\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n" );

		$ctrl = new GyroscopeController();
		// Search for a DIFFERENT rid (also ≤32 chars).
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => 'something-else-abcdefghijklmn' ] ) );
		$body = $resp->get_data();
		$this->assertSame( [], $body['data']['events'] );
		// scanned should be 1 (we read the one index entry but it didn't match).
		$this->assertSame( 1, $body['meta']['scanned'] );
	}

	public function test_get_timeline_returns_wp_error_when_rate_limited(): void {
		$cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $cache );
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$cache->set( 'newspack_nodes_rate:user_1:' . $window_start, 1000, 70 );

		$ctrl = new GyroscopeController();
		$resp = $ctrl->get_timeline( new \WP_REST_Request( [ 'request_id' => 'rid-anything' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}

	public function test_permissions_block_unauthorized(): void {
		$ctrl = new GyroscopeController();
		$GLOBALS['_current_user_can'] = false;
		$result = $ctrl->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}
}
