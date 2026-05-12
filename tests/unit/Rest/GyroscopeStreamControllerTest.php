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
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Bounded subclass: stops the polling loop after N ticks and skips header init
 * so unit tests can drive `stream_run` cleanly.
 *
 * Production `stream_run` seeks every Partition_Reader to 'end' so SSE
 * consumers only see NEW entries written after the connection opens. To
 * exercise the inflight + complete_batch paths, tests append entries to the
 * underlying log file from inside `should_continue_stream` (which fires
 * before each iteration of the loop body) so the reader's next `fgets()` picks
 * them up live.
 */
class TestableGyroscopeStreamController extends GyroscopeStreamController {
	private int $loop_count = 0;
	private int $max_loops  = 0;

	/** @var callable|null Optional: invoked at the start of every tick. */
	private $tick_callback = null;

	protected function init_sse_headers(): void {}

	public function set_max_loops( int $n ): void {
		$this->max_loops  = $n;
		$this->loop_count = 0;
	}

	public function set_tick_callback( ?callable $cb ): void {
		$this->tick_callback = $cb;
	}

	protected function should_continue_stream( array &$context ): bool {
		++$this->loop_count;
		if ( null !== $this->tick_callback ) {
			( $this->tick_callback )( $this->loop_count );
		}
		return $this->loop_count <= $this->max_loops;
	}

	public function public_stream_run( \WP_REST_Request $request ): mixed {
		return $this->stream_run( $request );
	}
}

#[CoversClass( GyroscopeStreamController::class )]
class GyroscopeStreamControllerTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
		PerformanceControllerBase::set_cache( new FakeMemcached() );
		SSEControllerBase::set_cache( new FakeMemcached() );
		$this->tmp_dir = $this->make_temp_dir( 'gyro-stream-' );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		SSEControllerBase::set_cache( null );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->rmdir_recursive( $this->tmp_dir );
		parent::tearDown();
	}

	private function pin_log_base( int $num_partitions = 1 ): void {
		$this->use_base_dir( $this->tmp_dir, [ 'num_partitions' => $num_partitions ] );
	}

	private function packed_entry( array $entry ): string {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = $entry;
		return Message::packed( $msg );
	}

	private function write_firehose_segment( int $partition, int $segment_id, array $entries ): void {
		$dir = "{$this->tmp_dir}/logs/firehose.log/p{$partition}";
		\mkdir( $dir, 0755, true );
		$body = '';
		foreach ( $entries as $e ) {
			$body .= $this->packed_entry( $e ) . "\n";
		}
		\file_put_contents( "{$dir}/{$segment_id}.log", $body );
	}

	private function append_firehose_entry( int $partition, int $segment_id, array $entry ): void {
		$path = "{$this->tmp_dir}/logs/firehose.log/p{$partition}/{$segment_id}.log";
		\file_put_contents( $path, $this->packed_entry( $entry ) . "\n", FILE_APPEND );
	}

	// =========================================================================
	// Route registration
	// =========================================================================

	public function test_register_routes_mounts_at_firehose_gyroscope(): void {
		( new GyroscopeStreamController() )->register_routes();
		// SSE-flavor route is at /firehose/gyroscope, distinct from the sync stub at /gyroscope/timeline.
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/gyroscope', $GLOBALS['_rest_routes'] );
		$this->assertArrayNotHasKey( 'newspack-nodes/v1/gyroscope/timeline', $GLOBALS['_rest_routes'] );
	}

	public function test_route_uses_get_method(): void {
		( new GyroscopeStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/gyroscope'];
		$this->assertSame( 'GET', $route['methods'] );
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

	public function test_namespace_within_allowed_endpoint_prefixes(): void {
		$this->assertContains(
			GyroscopeStreamController::NAMESPACE,
			SSEControllerBase::ALLOWED_ENDPOINT_PREFIXES
		);
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new GyroscopeStreamController();
		$GLOBALS['_current_user_can'] = false;
		$this->assertInstanceOf( \WP_Error::class, $ctrl->stream_permissions_check() );
	}

	public function test_permissions_check_allows_admin(): void {
		$this->assertTrue( ( new GyroscopeStreamController() )->stream_permissions_check() );
	}

	// =========================================================================
	// stream_run: rate-limit path.
	// =========================================================================

	public function test_stream_run_returns_wp_error_when_rate_limited(): void {
		$this->pin_log_base();
		$cache = SSEControllerBase::cache();
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$cache->add( "evlog:sse:7:" . \substr( \md5( '127.0.0.1' ), 0, 8 ) . ":{$i}", 'occupied', 60 );
		}

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 0 );
		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1000 );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );
	}

	// =========================================================================
	// stream_run: connected + config events.
	// =========================================================================

	public function test_stream_run_emits_connected_then_config(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 1 );
		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1000 );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		$out    = \ob_get_clean();

		$this->assertNull( $result );
		// Order matters: connected first (with slot), then config (with num_partitions+interval).
		$pos_connected = \strpos( $out, "event: connected\n" );
		$pos_config    = \strpos( $out, "event: config\n" );
		$this->assertNotFalse( $pos_connected );
		$this->assertNotFalse( $pos_config );
		$this->assertLessThan( $pos_config, $pos_connected, 'connected must precede config' );

		// Config event payload reflects num_partitions + interval.
		$this->assertStringContainsString( '"num_partitions":1', $out );
		$this->assertStringContainsString( '"interval":1000', $out );
	}

	// =========================================================================
	// stream_run: inflight event emitted at digest interval.
	//
	// Production `stream_run` seeks readers to 'end' so SSE consumers only see
	// new entries. We append a `request` line on the second tick, after setup
	// has run; the next `fgets()` will pick it up and InflightTracker will
	// include the rid in its `inflight` digest.
	// =========================================================================

	public function test_stream_run_emits_inflight_event(): void {
		$this->pin_log_base();
		// Pre-create empty segment (seek-to-end against an empty file is fine).
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 8 );
		// On tick 2, after setup is done, append a real entry.
		$ctrl->set_tick_callback( function ( int $tick ): void {
			if ( 2 === $tick ) {
				$this->append_firehose_entry( 0, 0, [
					'k'   => 'request',
					'rid' => 'rinflight',
					'm'   => 'GET /test',
					'ts'  => 1700000000,
				] );
			}
		} );

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1 );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// The inflight event must be emitted at least once.
		$this->assertStringContainsString( "event: inflight\n", $out );
		// Payload reports requests + count + time fields.
		$this->assertStringContainsString( '"requests":', $out );
		$this->assertStringContainsString( '"count":', $out );
		$this->assertStringContainsString( '"time":', $out );
	}

	// =========================================================================
	// stream_run: complete_batch event flushed when a request completes.
	// =========================================================================

	public function test_stream_run_emits_complete_batch_when_request_completes(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 12 );
		$ctrl->set_tick_callback( function ( int $tick ): void {
			if ( 2 === $tick ) {
				$this->append_firehose_entry( 0, 0, [
					'k'   => 'request',
					'rid' => 'rdone',
					'm'   => 'GET /done',
					'ts'  => 1700000000,
				] );
			}
			if ( 4 === $tick ) {
				$this->append_firehose_entry( 0, 0, [
					'k'           => 'process (complete)',
					'rid'         => 'rdone',
					'ts'          => 1700000001,
					'duration_ms' => 100,
					'status_code' => 200,
				] );
			}
		} );

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1 );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		$this->assertStringContainsString( "event: complete_batch\n", $out );
		// The completed entry should reference rdone.
		$this->assertStringContainsString( '"rid":"rdone"', $out );
	}

	// =========================================================================
	// stream_run: skips inflight requests for /firehose/gyroscope endpoint itself.
	// =========================================================================

	public function test_stream_run_inflight_excludes_self_polling(): void {
		// InflightTracker hardcodes /firehose/gyroscope as a skip URL so the
		// dashboard's own polls don't appear in the active list.
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 10 );
		$ctrl->set_tick_callback( function ( int $tick ): void {
			if ( 2 === $tick ) {
				$this->append_firehose_entry( 0, 0, [
					'k'   => 'request',
					'rid' => 'rself',
					'm'   => 'GET /firehose/gyroscope',
					'ts'  => 1700000000,
				] );
			}
			if ( 3 === $tick ) {
				$this->append_firehose_entry( 0, 0, [
					'k'   => 'request',
					'rid' => 'rother',
					'm'   => 'GET /api/foo',
					'ts'  => 1700000001,
				] );
			}
		} );

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1 );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// Self-polling rid filtered out; other rid should surface.
		$this->assertStringContainsString( '"rid":"rother"', $out );
		$this->assertStringNotContainsString( '"rid":"rself"', $out );
	}

	// =========================================================================
	// stream_run: multi-partition fan-in.
	// =========================================================================

	public function test_stream_run_reads_from_all_partitions(): void {
		$this->pin_log_base( 2 );
		// Pre-create empty segments for both partitions.
		$this->write_firehose_segment( 0, 0, [] );
		$this->write_firehose_segment( 1, 0, [] );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 12 );
		$ctrl->set_tick_callback( function ( int $tick ): void {
			if ( 2 === $tick ) {
				$this->append_firehose_entry( 0, 0, [
					'k'   => 'request',
					'rid' => 'rp0',
					'm'   => 'GET /p0',
					'ts'  => 1700000000,
				] );
				$this->append_firehose_entry( 1, 0, [
					'k'   => 'request',
					'rid' => 'rp1',
					'm'   => 'GET /p1',
					'ts'  => 1700000001,
				] );
			}
		} );

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1 );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// Both partitions' rids surface in the inflight digest.
		$this->assertStringContainsString( '"rid":"rp0"', $out );
		$this->assertStringContainsString( '"rid":"rp1"', $out );
		// num_partitions=2 in the config event.
		$this->assertStringContainsString( '"num_partitions":2', $out );
	}

	// =========================================================================
	// stream_run: releases slot in `finally`.
	// =========================================================================

	public function test_stream_run_releases_slot_on_completion(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 1 );
		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1000 );

		\ob_start();
		$ctrl->public_stream_run( $req );
		\ob_get_clean();

		$cache   = SSEControllerBase::cache();
		$ip_hash = \substr( \md5( '127.0.0.1' ), 0, 8 );
		$this->assertNull( $cache->get( "evlog:sse:7:{$ip_hash}:0" ), 'slot must be released on stream completion' );
	}

	// =========================================================================
	// stream_run: no segments means readers are null but loop still settles.
	// =========================================================================

	public function test_stream_run_handles_empty_partition(): void {
		$this->pin_log_base();
		\mkdir( "{$this->tmp_dir}/logs/firehose.log/p0", 0755, true );

		$ctrl = new TestableGyroscopeStreamController();
		$ctrl->set_max_loops( 2 );
		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1000 );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		$out    = \ob_get_clean();

		// No crash; connected event still emitted.
		$this->assertNull( $result );
		$this->assertStringContainsString( "event: connected\n", $out );
		$this->assertStringContainsString( "event: config\n", $out );
	}
}
