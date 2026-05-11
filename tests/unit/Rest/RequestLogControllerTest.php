<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\RequestLogController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RequestLogController::class )]
class RequestLogControllerTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']             = [];
		$GLOBALS['_current_user_can']        = true;
		$GLOBALS['_current_user_id']         = 1;
		$GLOBALS['_wp_actions']       = [];
		$GLOBALS['_wp_options']       = [];
		PerformanceControllerBase::set_cache( new FakeMemcached() );

		$this->tmp = '/tmp/request-log-controller-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		\add_filter( 'newspack_nodes/base_dir', fn () => $this->tmp );
		Config::reset();
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions'] = [];
		$this->rmdir_recursive( $this->tmp );
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Write a request envelope into requests.log/p{partition} and emit an
	 * index entry so the controller's scan_index() picks it up.
	 *
	 * @param array $body Request body fields (rid, url, timestamp, etc.).
	 * @param int   $partition Partition number.
	 * @return string The rid that was written.
	 */
	private function write_request( array $body, int $partition = 0 ): string {
		$rid          = $body['rid'];
		$logs_base    = $this->tmp . '/logs/requests.log';
		$segment_dir  = "{$logs_base}/p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$msg[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $msg );

		// Append into segment 0; track byte offsets so we can build the index.
		$seg_path = "{$segment_dir}/0.log";
		$existing = \file_exists( $seg_path ) ? \file_get_contents( $seg_path ) : '';
		$offset   = \strlen( (string) $existing );
		\file_put_contents( $seg_path, $existing . $packed, LOCK_EX );

		$position   = [
			'segment_id' => 0,
			'offset'     => $offset,
			'length'     => \strlen( $packed ),
		];
		$index_line = RequestBuilder::format_index_entry( $packed, $position );
		if ( null !== $index_line && '' !== $index_line ) {
			// Sibling .idx file alongside each segment.
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return $rid;
	}

	public function test_register_routes_registers_list_and_detail(): void {
		( new RequestLogController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/request-log/list', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/request-log/detail/(?P<id>[A-Za-z0-9_-]+)', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_correct_methods(): void {
		( new RequestLogController() )->register_routes();
		$list_route = $GLOBALS['_rest_routes']['newspack-nodes/v1/request-log/list'];
		$this->assertSame( 'GET', $list_route['methods'] );
		$detail_route = $GLOBALS['_rest_routes']['newspack-nodes/v1/request-log/detail/(?P<id>[A-Za-z0-9_-]+)'];
		$this->assertSame( 'GET', $detail_route['methods'] );
	}

	public function test_get_list_returns_data_meta(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_list( new \WP_REST_Request( [ 'limit' => 10 ] ) );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertArrayHasKey( 'meta', $body );
		$this->assertArrayHasKey( 'limit', $body['meta'] );
		$this->assertArrayHasKey( 'scanned', $body['meta'] );
		$this->assertSame( 10, $body['meta']['limit'] );
	}

	public function test_get_list_returns_default_limit_when_unset(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_list( new \WP_REST_Request() );
		$body = $resp->get_data();
		// Unset limit defaults to 0 from null-coalescing through (int).
		$this->assertSame( 0, $body['meta']['limit'] );
	}

	public function test_get_list_returns_indexed_entries(): void {
		// Rids are written into a 32-char fixed field; keep them ≤32 chars
		// so the round-trip through the index parser preserves them exactly.
		$rid1 = $this->write_request(
			[
				'rid'            => 'rid-aa-1234567890123456789012345',
				'url'            => '/page-1',
				'timestamp'      => 1700000100,
				'duration_ms'    => 42,
				'status_code'    => 200,
				'peak_mb'        => 5,
				'request_method' => 'GET',
			]
		);
		$rid2 = $this->write_request(
			[
				'rid'            => 'rid-bb-1234567890123456789012345',
				'url'            => '/page-2',
				'timestamp'      => 1700000200,
				'duration_ms'    => 100,
				'status_code'    => 500,
				'peak_mb'        => 10,
				'request_method' => 'POST',
			]
		);

		$ctrl = new RequestLogController();
		$resp = $ctrl->get_list( new \WP_REST_Request( [ 'limit' => 10 ] ) );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertCount( 2, $body['data'] );

		// Sorted by timestamp DESC — rid2 (1700000200) first.
		$this->assertSame( $rid2, $body['data'][0]['rid'] );
		$this->assertSame( $rid1, $body['data'][1]['rid'] );

		// Verify the documented entry shape.
		$first = $body['data'][0];
		foreach ( [ 'rid', 'url_hash', 'timestamp', 'duration_ms', 'status_code', 'peak_mb', 'method', 'partition' ] as $key ) {
			$this->assertArrayHasKey( $key, $first );
		}
		$this->assertSame( 1700000200, $first['timestamp'] );
		$this->assertSame( 100, $first['duration_ms'] );
		$this->assertSame( 500, $first['status_code'] );
		$this->assertSame( 'POST', $first['method'] );
		$this->assertSame( 0, $first['partition'] );
	}

	public function test_get_list_respects_limit(): void {
		// Write 3 requests; ask for 2. Rids ≤32 chars to round-trip cleanly.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->write_request(
				[
					'rid'            => "rid-{$i}-123456789012345678901234",
					'url'            => "/page-{$i}",
					'timestamp'      => 1700000000 + $i,
					'duration_ms'    => 1,
					'status_code'    => 200,
					'peak_mb'        => 1,
					'request_method' => 'GET',
				]
			);
		}
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_list( new \WP_REST_Request( [ 'limit' => 2 ] ) );
		$body = $resp->get_data();
		$this->assertCount( 2, $body['data'] );
		// Newest first.
		$this->assertSame( 1700000002, $body['data'][0]['timestamp'] );
		$this->assertSame( 1700000001, $body['data'][1]['timestamp'] );
	}

	public function test_get_list_returns_wp_error_when_rate_limited(): void {
		$cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $cache );
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$cache->set( 'newspack_nodes_rate:user_1:' . $window_start, 1000, 70 );

		$ctrl = new RequestLogController();
		$resp = $ctrl->get_list( new \WP_REST_Request( [ 'limit' => 10 ] ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}

	public function test_get_detail_with_id_echoes_id(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request( [ 'id' => 'rid-xyz' ] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( 'rid-xyz', $body['data']['request_id'] );
		$this->assertArrayHasKey( 'entries', $body['data'] );
		$this->assertSame( [], $body['data']['entries'] );
		$this->assertArrayHasKey( 'scanned', $body['meta'] );
	}

	public function test_get_detail_without_id_returns_404(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->data['status'] ?? 0 );
	}

	public function test_get_detail_with_empty_id_returns_404(): void {
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request( [ 'id' => '' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_get_detail_with_known_id_returns_envelope(): void {
		$rid = $this->write_request(
			[
				'rid'            => 'rid-detail-123456789012345678901',
				'url'            => '/found',
				'timestamp'      => 1700000300,
				'duration_ms'    => 25,
				'status_code'    => 200,
				'peak_mb'        => 2,
				'request_method' => 'GET',
				'events'         => [
					[ 'k' => 'process (start)', 'm' => '/found', 'ts' => 1700000300.0 ],
					[ 'k' => 'process (complete)', 'm' => '/found', 'ts' => 1700000300.025 ],
				],
			]
		);
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request( [ 'id' => $rid ] ) );
		$body = $resp->get_data();
		$this->assertSame( $rid, $body['data']['request_id'] );
		$this->assertCount( 2, $body['data']['entries'] );
		// Entries returned in order — the events array as written.
		$this->assertSame( 'process (start)', $body['data']['entries'][0]['k'] );
		$this->assertSame( 'process (complete)', $body['data']['entries'][1]['k'] );
	}

	public function test_get_detail_without_events_wraps_envelope(): void {
		$rid = $this->write_request(
			[
				'rid'            => 'rid-noevt-1234567890123456789012',
				'url'            => '/no-events',
				'timestamp'      => 1700000400,
				'duration_ms'    => 5,
				'status_code'    => 200,
				'peak_mb'        => 1,
				'request_method' => 'GET',
				// no events key
			]
		);
		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request( [ 'id' => $rid ] ) );
		$body = $resp->get_data();
		// When there's no 'events' key, the entire body is wrapped as one entry.
		$this->assertCount( 1, $body['data']['entries'] );
		$this->assertSame( $rid, $body['data']['entries'][0]['rid'] );
		// The synthesized entry should also carry the _partition marker.
		$this->assertSame( 0, $body['data']['entries'][0]['_partition'] );
	}

	public function test_get_detail_returns_wp_error_when_rate_limited(): void {
		$cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $cache );
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$cache->set( 'newspack_nodes_rate:user_1:' . $window_start, 1000, 70 );

		$ctrl = new RequestLogController();
		$resp = $ctrl->get_detail( new \WP_REST_Request( [ 'id' => 'rid-anything' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}

	public function test_register_routes_limit_sanitize_clips(): void {
		( new RequestLogController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/request-log/list']['args'];
		$cb   = $args['limit']['sanitize_callback'];
		$this->assertSame( 1, $cb( 0 ) );
		$this->assertSame( 1, $cb( -50 ) );
		$this->assertSame( 1000, $cb( 999999 ) );
		$this->assertSame( 500, $cb( 500 ) );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new RequestLogController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
