<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-firehose-controller.php';

use Newspack_Event_Logger_Nodes\Rest\FirehoseController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FirehoseController::class )]
class FirehoseControllerTest extends TestCase {

	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
		SSEControllerBase::set_cache( $this->cache );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		SSEControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_register_routes_mounts_three_endpoints(): void {
		( new FirehoseController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/logs', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/status', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/heartbeat', $GLOBALS['_rest_routes'] );
	}

	public function test_get_logs_returns_keyed_pairs(): void {
		$ctrl = new FirehoseController();
		$resp = $ctrl->get_logs( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertIsArray( $body );
		$this->assertNotEmpty( $body );
		// Each entry has key/label.
		foreach ( $body as $entry ) {
			$this->assertArrayHasKey( 'key', $entry );
			$this->assertArrayHasKey( 'label', $entry );
		}
	}

	public function test_get_default_log_returns_first_filename(): void {
		$default = FirehoseController::get_default_log();
		$this->assertStringEndsWith( '.log', $default );
	}

	public function test_validate_log_name_accepts_known_keys(): void {
		$this->assertTrue( FirehoseController::validate_log_name( 'firehose' ) );
		$this->assertFalse( FirehoseController::validate_log_name( 'bogus' ) );
		$this->assertFalse( FirehoseController::validate_log_name( null ) );
	}

	public function test_sanitize_log_param_returns_known_filename(): void {
		$ctrl = new FirehoseController();
		$this->assertSame( 'firehose.log', $ctrl->sanitize_log_param( 'firehose' ) );
		// .log suffix is stripped before lookup.
		$this->assertSame( 'firehose.log', $ctrl->sanitize_log_param( 'firehose.log' ) );
	}

	public function test_sanitize_log_param_falls_back_to_default(): void {
		$ctrl = new FirehoseController();
		$out  = $ctrl->sanitize_log_param( 'unknown-log' );
		$this->assertStringEndsWith( '.log', $out );
	}

	public function test_get_status_404_on_empty_log(): void {
		$ctrl = new FirehoseController();
		// sanitize_log_param replaces empty with default; force-pass empty by
		// constructing a request with the log already sanitized to ''.
		$req = new \WP_REST_Request( [ 'log' => '' ] );
		$resp = $ctrl->get_status( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'no_logs', $resp->get_error_code() );
	}

	public function test_get_status_returns_partition_summary(): void {
		$ctrl = new FirehoseController();
		$req  = new \WP_REST_Request( [ 'log' => 'firehose.log' ] );
		$resp = $ctrl->get_status( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( 'firehose', $body['log_id'] );
		$this->assertSame( 'firehose.log', $body['log_file'] );
		$this->assertArrayHasKey( 'partitions', $body );
		$this->assertArrayHasKey( 'total_segments', $body );
		$this->assertArrayHasKey( 'total_size', $body );
	}

	public function test_heartbeat_succeeds_when_slot_alive(): void {
		// Acquire a slot via the cache directly (mirroring an SSE controller).
		$ip_hash = \substr( \md5( 'unknown' ), 0, 8 );
		$slot    = $this->cache->acquire_sse_slot( 7, $ip_hash, SSEControllerBase::MAX_SSE_SLOTS, SSEControllerBase::SLOT_TTL_BROWSER, -1 );
		$this->assertSame( 0, $slot );

		$ctrl = new FirehoseController();
		$req  = new \WP_REST_Request( [ 'slot' => 0, 'aggregator' => false, 'partition' => -1 ] );
		$resp = $ctrl->heartbeat( $req );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 0, $body['slot'] );
		$this->assertNull( $body['error'] );
	}

	public function test_heartbeat_reports_slot_expired(): void {
		$ctrl = new FirehoseController();
		// No slot acquired → touch returns false.
		$req  = new \WP_REST_Request( [ 'slot' => 0, 'aggregator' => false, 'partition' => -1 ] );
		$resp = $ctrl->heartbeat( $req );
		$body = $resp->get_data();
		$this->assertFalse( $body['success'] );
		$this->assertSame( 'slot_expired', $body['error'] );
	}

	public function test_heartbeat_aggregator_uses_partition_slot(): void {
		$ip_hash = \substr( \md5( 'unknown' ), 0, 8 );
		$slot    = $this->cache->acquire_sse_slot( 7, $ip_hash, SSEControllerBase::MAX_SSE_SLOTS, SSEControllerBase::SLOT_TTL_AGGREGATOR, 2 );
		$this->assertSame( 0, $slot );

		$ctrl = new FirehoseController();
		$req  = new \WP_REST_Request( [ 'slot' => 0, 'aggregator' => true, 'partition' => 2 ] );
		$resp = $ctrl->heartbeat( $req );
		$this->assertTrue( $resp->get_data()['success'] );

		// Same slot index in a different partition pool would have its own life.
		$req2 = new \WP_REST_Request( [ 'slot' => 0, 'aggregator' => true, 'partition' => 3 ] );
		$resp2 = $ctrl->heartbeat( $req2 );
		$this->assertFalse( $resp2->get_data()['success'] );
	}

	public function test_filter_extends_log_catalog(): void {
		\add_filter(
			'newspack_nodes/firehose_logs',
			static fn ( $logs ) => \array_merge( $logs, [ 'custom' => 'custom.log' ] )
		);
		$logs = FirehoseController::get_available_logs();
		$this->assertArrayHasKey( 'custom', $logs );
		$this->assertSame( 'custom.log', $logs['custom'] );
	}

	public function test_permission_callback_rejects_unauthorized(): void {
		$ctrl                         = new FirehoseController();
		$GLOBALS['_current_user_can'] = false;
		$result                       = $ctrl->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
