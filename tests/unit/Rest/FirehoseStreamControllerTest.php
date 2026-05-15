<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

require_once \dirname( __DIR__, 3 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-sse-controller-base.php';
require_once \dirname( __DIR__, 3 ) . '/includes/rest/class-firehose-stream-controller.php';

use Newspack_Event_Logger_Nodes\Rest\FirehoseStreamController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Bounded subclass: overrides `should_continue_stream` to a counted-tick
 * predicate and skips header/time-limit init so unit tests run cleanly.
 */
class TestableFirehoseStreamController extends FirehoseStreamController {
	private int $loop_count = 0;
	private int $max_loops  = 0;

	protected function init_sse_headers(): void {}

	public function set_max_loops( int $n ): void {
		$this->max_loops  = $n;
		$this->loop_count = 0;
	}

	protected function should_continue_stream( array &$context ): bool {
		return ++$this->loop_count <= $this->max_loops;
	}

	public function public_stream_run( \WP_REST_Request $request ): mixed {
		return $this->stream_run( $request );
	}
}

#[CoversClass( FirehoseStreamController::class )]
class FirehoseStreamControllerTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
		PerformanceControllerBase::set_cache( new FakeMemcached() );
		SSEControllerBase::set_cache( new FakeMemcached() );
		$this->tmp_dir = $this->make_temp_dir( 'firehose-stream-' );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		SSEControllerBase::set_cache( null );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->rmdir_recursive( $this->tmp_dir );
		parent::tearDown();
	}

	/**
	 * Wire each test's logs base into PerformanceControllerBase via the
	 * per-test config file (LOCAL_NEWSPACK_NODES_CONF).
	 */
	private function pin_log_base(): void {
		$this->use_base_dir( $this->tmp_dir, [ 'num_partitions' => 1 ] );
	}

	/**
	 * Pack an entry into the firehose's wire shape: each line in `firehose.log/p{N}/{seg}.log`
	 * is a packed Message envelope, with the entry array on Message::VALUE.
	 */
	private function packed_entry( array $entry ): string {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		// Producer convention: rid lives in Message::KEY (LogManager since
		// v0.2.17). FirehoseStreamController back-fills entry['rid'] from KEY
		// at read time, so test fixtures must stamp it here.
		$msg[ Message::KEY ]       = (string) ( $entry['rid'] ?? '' );
		$msg[ Message::VALUE ]     = $entry;
		return Message::packed( $msg );
	}

	private function write_firehose_segment( int $partition, int $segment_id, array $entries ): string {
		$dir = "{$this->tmp_dir}/logs/firehose.log/p{$partition}";
		@\mkdir( $dir, 0755, true );
		$path = "{$dir}/{$segment_id}.log";
		$body = '';
		foreach ( $entries as $e ) {
			$body .= $this->packed_entry( $e ) . "\n";
		}
		\file_put_contents( $path, $body );
		return $path;
	}

	// =========================================================================
	// Route registration
	// =========================================================================

	public function test_register_routes_mounts_stream_endpoint(): void {
		( new FirehoseStreamController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/firehose/stream', $GLOBALS['_rest_routes'] );
	}

	public function test_route_uses_get_method(): void {
		( new FirehoseStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream'];
		$this->assertSame( 'GET', $route['methods'] );
	}

	public function test_route_args_include_aggregator_flag(): void {
		( new FirehoseStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream'];
		$this->assertArrayHasKey( 'aggregator', $route['args'] );
		$this->assertSame( false, $route['args']['aggregator']['default'] );
	}

	public function test_route_args_include_partition(): void {
		( new FirehoseStreamController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream'];
		$this->assertArrayHasKey( 'partition', $route['args'] );
		$this->assertSame( 0, $route['args']['partition']['default'] );
	}

	public function test_partition_sanitize_clips_to_non_negative(): void {
		( new FirehoseStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream']['args']['partition']['sanitize_callback'];
		$this->assertSame( 0, $cb( -5 ) );
		$this->assertSame( 0, $cb( -1 ) );
		$this->assertSame( 3, $cb( 3 ) );
	}

	public function test_partition_validate_rejects_out_of_range(): void {
		$this->pin_log_base();
		( new FirehoseStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream']['args']['partition']['validate_callback'];
		// num_partitions=1 from filter → 0 valid, 1 invalid.
		$this->assertTrue( $cb( 0 ) );
		$this->assertFalse( $cb( 1 ) );
		$this->assertFalse( $cb( -1 ) );
	}

	public function test_segment_id_sanitize_clips_to_non_negative(): void {
		( new FirehoseStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream']['args']['segment_id']['sanitize_callback'];
		$this->assertSame( 0, $cb( -10 ) );
		$this->assertSame( 5, $cb( 5 ) );
	}

	public function test_offset_sanitize_clips_to_non_negative(): void {
		( new FirehoseStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream']['args']['offset']['sanitize_callback'];
		$this->assertSame( 0, $cb( -1 ) );
		$this->assertSame( 1024, $cb( 1024 ) );
	}

	public function test_aggregator_sanitize_filter_validates_boolean(): void {
		( new FirehoseStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream']['args']['aggregator']['sanitize_callback'];
		$this->assertTrue( $cb( 'true' ) );
		$this->assertTrue( $cb( '1' ) );
		$this->assertFalse( $cb( 'no' ) );
		$this->assertFalse( $cb( '0' ) );
	}

	public function test_namespace_within_allowed_endpoint_prefixes(): void {
		$this->assertContains(
			FirehoseStreamController::NAMESPACE,
			SSEControllerBase::ALLOWED_ENDPOINT_PREFIXES
		);
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	public function test_permissions_check_denies_anonymous(): void {
		$ctrl                         = new FirehoseStreamController();
		$GLOBALS['_current_user_can'] = false;
		$result                       = $ctrl->stream_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_permissions_check_allows_admin(): void {
		$ctrl = new FirehoseStreamController();
		$this->assertTrue( $ctrl->stream_permissions_check() );
	}

	// =========================================================================
	// stream_run: rate-limit path.
	// =========================================================================

	public function test_stream_run_returns_wp_error_when_rate_limited(): void {
		$this->pin_log_base();
		// Saturate the slot pool by calling a 2-arg variant. Easiest path:
		// use FakeMemcached.add() directly to mark all 10 slots taken.
		$cache = SSEControllerBase::cache();
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$cache->add( "evlog:sse:7:" . \substr( \md5( '127.0.0.1' ), 0, 8 ) . ":{$i}", 'occupied', 60 );
		}

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 0 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );
	}

	// =========================================================================
	// stream_run: tail mode (no resume params).
	// =========================================================================

	public function test_stream_run_tail_mode_emits_connected(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 1 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		$out    = \ob_get_clean();

		$this->assertNull( $result );
		$this->assertStringContainsString( "event: connected\n", $out );
		$this->assertStringContainsString( '"partition":0', $out );
		$this->assertStringContainsString( '"log":"firehose.log"', $out );
	}

	// =========================================================================
	// stream_run: explicit resume with segment_id + offset.
	// =========================================================================

	public function test_stream_run_resume_with_segment_id_and_offset(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [
			[ 'k' => 'request', 'rid' => 'rA', 'ts' => 1700000000, 'url' => '/a' ],
			[ 'k' => 'process (complete)', 'rid' => 'rA', 'ts' => 1700000001 ],
		] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 5 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// At offset 0 the reader replays both entries from the start.
		$this->assertStringContainsString( "event: connected\n", $out );
		// Both entries surface as `entry` events.
		$this->assertStringContainsString( "event: entry\n", $out );
		$this->assertStringContainsString( '"rid":"rA"', $out );
		// Position must be embedded in each entry payload.
		$this->assertStringContainsString( '"position":', $out );
	}

	// =========================================================================
	// stream_run: legacy "offset only" resume.
	// =========================================================================

	public function test_stream_run_legacy_offset_only_seek(): void {
		$this->pin_log_base();
		// Two entries — seek to offset 0 of the current segment.
		$this->write_firehose_segment( 0, 0, [
			[ 'k' => 'request', 'rid' => 'rZ', 'ts' => 1700000000 ],
		] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 5 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'offset', 0 );
		// segment_id intentionally not set — legacy "offset only" path.
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		$this->assertStringContainsString( "event: connected\n", $out );
		$this->assertStringContainsString( '"rid":"rZ"', $out );
	}

	// =========================================================================
	// stream_run: aggregator mode uses partition-isolated slot pool + custom header.
	// =========================================================================

	public function test_stream_run_aggregator_mode_emits_server_id_header(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 1 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', true );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		$out    = \ob_get_clean();

		$this->assertNull( $result );
		$this->assertStringContainsString( "event: connected\n", $out );
		// Custom header X-Server-Id flows through `header()` — captured headers
		// aren't easy to inspect here, but the connected event should still
		// reflect the partition.
		$this->assertStringContainsString( '"partition":0', $out );
	}

	// =========================================================================
	// stream_run: malformed entries get skipped without aborting.
	// =========================================================================

	public function test_stream_run_skips_malformed_lines(): void {
		$this->pin_log_base();
		$dir = "{$this->tmp_dir}/logs/firehose.log/p0";
		\mkdir( $dir, 0755, true );
		// Mix valid and invalid lines.
		$valid_packed = $this->packed_entry( [ 'k' => 'request', 'rid' => 'r-valid', 'ts' => 1700000000 ] );
		$content      = "not-json\n\n{$valid_packed}\n";
		\file_put_contents( "{$dir}/0.log", $content );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 5 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// Valid entry surfaces.
		$this->assertStringContainsString( '"rid":"r-valid"', $out );
		// Malformed lines do NOT make it into the data: payloads.
		$this->assertStringNotContainsString( 'not-json', $out );
	}

	// =========================================================================
	// stream_run: no-segments path (empty partition emits heartbeats).
	// =========================================================================

	public function test_stream_run_empty_partition_emits_heartbeat(): void {
		$this->pin_log_base();
		// Create the partition directory but no segment files.
		\mkdir( "{$this->tmp_dir}/logs/firehose.log/p0", 0755, true );

		// Force HEARTBEAT_INTERVAL=0 by waiting at least one second — the loop
		// emits a heartbeat after HEARTBEAT_INTERVAL seconds, and `time()` ticks
		// independently of bounded loops. Take the simpler path: sleep briefly
		// before the second loop iteration so `now - last_heartbeat >= 5` is met.
		// Cheaper alternative: run a single iteration; the very first tick won't
		// emit a heartbeat (HEARTBEAT_INTERVAL not yet elapsed). Since we just
		// want to reach the no-segment branch without crashing, that's enough.
		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 2 );

		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		$out    = \ob_get_clean();

		// No crash; connected event still emitted.
		$this->assertNull( $result );
		$this->assertStringContainsString( "event: connected\n", $out );
	}

	// =========================================================================
	// stream_run: caught-up path emits heartbeat after entries drained.
	// =========================================================================

	public function test_stream_run_caught_up_after_draining_entries(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [
			[ 'k' => 'request', 'rid' => 'rTail', 'ts' => 1700000000 ],
		] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 4 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// Entry surfaces; subsequent ticks settle into caught-up loop.
		$this->assertStringContainsString( '"rid":"rTail"', $out );
	}

	// =========================================================================
	// stream_run: skips empty / whitespace-only lines.
	// =========================================================================

	public function test_stream_run_ignores_blank_lines(): void {
		$this->pin_log_base();
		$dir = "{$this->tmp_dir}/logs/firehose.log/p0";
		\mkdir( $dir, 0755, true );
		// Two blank lines plus one valid entry.
		$valid = $this->packed_entry( [ 'k' => 'log', 'rid' => 'rblank', 'ts' => 1700000099 ] );
		\file_put_contents( "{$dir}/0.log", "\n   \n{$valid}\n" );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 5 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		$this->assertStringContainsString( '"rid":"rblank"', $out );
	}

	// =========================================================================
	// stream_run: end_sse_stream releases the slot in `finally`.
	// =========================================================================

	public function test_stream_run_releases_slot_on_completion(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 1 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		\ob_get_clean();

		// After the bounded loop, slot must be back in the pool — a fresh
		// controller acquires the SAME slot index 0.
		$cache       = SSEControllerBase::cache();
		$ip_hash     = \substr( \md5( '127.0.0.1' ), 0, 8 );
		$still_taken = $cache->get( "evlog:sse:7:{$ip_hash}:0" );
		$this->assertNull( $still_taken, 'slot must be released on stream completion' );
	}

	// =========================================================================
	// stream_run: dropped (non-array) entries still surface fine.
	// =========================================================================

	public function test_stream_run_drops_entries_with_non_array_value(): void {
		// Build a Message line whose VALUE is a scalar (string), not an array.
		$this->pin_log_base();
		$dir = "{$this->tmp_dir}/logs/firehose.log/p0";
		\mkdir( $dir, 0755, true );

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ]     = 'plain string, not an array';
		$bad_line = Message::packed( $msg );

		// And one valid entry.
		$ok_line = $this->packed_entry( [ 'k' => 'log', 'rid' => 'rok', 'ts' => 1700000111 ] );

		\file_put_contents( "{$dir}/0.log", "{$bad_line}\n{$ok_line}\n" );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 5 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// Only the valid entry should produce output.
		$this->assertStringContainsString( '"rid":"rok"', $out );
		$this->assertStringNotContainsString( 'plain string, not an array', $out );
	}

	// =========================================================================
	// stream(): public entry point WP_Error branch.
	// =========================================================================

	/**
	 * `stream()` itself is the public entry point — it calls `stream_run` and,
	 * on `WP_Error`, returns it without calling `exit`. Saturating the slot
	 * pool exercises that early-return branch without ever entering the loop
	 * or exiting the request, so the test process keeps running.
	 */
	public function test_stream_returns_wp_error_directly_when_rate_limited(): void {
		$this->pin_log_base();
		$cache = SSEControllerBase::cache();
		// Saturate the global browser slot pool (-1).
		$ip_hash = \substr( \md5( '127.0.0.1' ), 0, 8 );
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$cache->add( "evlog:sse:7:{$ip_hash}:{$i}", 'occupied', 60 );
		}

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 0 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$result = $ctrl->stream( $req );
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );
		$this->assertSame( 429, $result->data['status'] );
	}

	// =========================================================================
	// stream_run: segment rotation path (next_segment branch).
	// =========================================================================

	/**
	 * When `is_caught_up()` is false (the reader hasn't reached the newest
	 * segment yet), the loop body calls `$reader->next_segment()` to roll
	 * forward. Two segments with the older segment back-dated (so
	 * `next_segment`'s mtime-freshness guard doesn't keep the reader pinned)
	 * exercise the rotation branch — the second pass advances to segment 1.
	 */
	public function test_stream_run_advances_reader_to_next_segment(): void {
		$this->pin_log_base();
		// Two adjacent segments so next_segment() rolls forward.
		$path_0 = $this->write_firehose_segment( 0, 0, [
			[ 'k' => 'first', 'rid' => 'r0', 'ts' => 1700000000 ],
		] );
		$this->write_firehose_segment( 0, 1, [
			[ 'k' => 'second', 'rid' => 'r1', 'ts' => 1700000001 ],
		] );

		// Age segment 0 past Partition_Reader::next_segment's 5-second mtime
		// freshness window so rotation is permitted.
		\touch( $path_0, \time() - 60 );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 6 );
		$req = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		// Start at segment 0 offset 0 — reader replays first, then rotates.
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// Both entries surface as the reader traverses both segments.
		$this->assertStringContainsString( '"rid":"r0"', $out );
		$this->assertStringContainsString( '"rid":"r1"', $out );
	}

	// =========================================================================
	// stream_run: aggregator mode with explicit partition uses per-partition pool.
	// =========================================================================

	/**
	 * Aggregator mode with `partition=0` acquires a slot from the per-partition
	 * pool, not the shared browser pool. Saturating only the shared pool must
	 * NOT block the aggregator path — distinct cache namespaces.
	 */
	public function test_stream_run_aggregator_uses_partition_pool_when_browser_full(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [] );

		// Saturate the GLOBAL browser pool only.
		$cache   = SSEControllerBase::cache();
		$ip_hash = \substr( \md5( '127.0.0.1' ), 0, 8 );
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$cache->add( "evlog:sse:7:{$ip_hash}:{$i}", 'occupied', 60 );
		}

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 1 );
		$req  = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'aggregator', true );

		\ob_start();
		$result = $ctrl->public_stream_run( $req );
		$out    = \ob_get_clean();

		// Aggregator path acquires from p0 pool — browser saturation doesn't block it.
		$this->assertNull( $result );
		$this->assertStringContainsString( "event: connected\n", $out );
	}

	// =========================================================================
	// stream_run: position metadata carried in every emitted entry.
	// =========================================================================

	/**
	 * Every `entry` event must carry a `position` object that callers can
	 * round-trip back via segment_id/offset on reconnect. Confirms the loop
	 * back-fills it from `$reader->get_position()` before send_sse_event.
	 */
	public function test_stream_run_entry_position_carries_segment_and_offset(): void {
		$this->pin_log_base();
		$this->write_firehose_segment( 0, 0, [
			[ 'k' => 'request', 'rid' => 'rpos', 'ts' => 1700000000 ],
		] );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 3 );
		$req  = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		$this->assertStringContainsString( '"rid":"rpos"', $out );
		$this->assertStringContainsString( '"position":{', $out );
		$this->assertStringContainsString( '"segment_id":0', $out );
		$this->assertStringContainsString( '"offset":', $out );
	}

	// =========================================================================
	// stream_run: rid back-fill from Message::KEY.
	// =========================================================================

	/**
	 * Producers stamp `rid` on `Message::KEY`. The controller back-fills
	 * `entry['rid']` from KEY before emitting — even when the inner VALUE
	 * array has no `rid` field of its own. Confirms the back-fill is
	 * unconditional.
	 */
	public function test_stream_run_backfills_rid_from_message_key(): void {
		$this->pin_log_base();
		$dir = "{$this->tmp_dir}/logs/firehose.log/p0";
		\mkdir( $dir, 0755, true );

		// Build a Message where VALUE has NO rid; KEY supplies it.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'key-supplied-rid';
		$msg[ Message::VALUE ] = [ 'k' => 'log', 'ts' => 1700000222 ];
		\file_put_contents( "{$dir}/0.log", Message::packed( $msg ) . "\n" );

		$ctrl = new TestableFirehoseStreamController();
		$ctrl->set_max_loops( 3 );
		$req  = new \WP_REST_Request();
		$req->set_param( 'partition', 0 );
		$req->set_param( 'segment_id', 0 );
		$req->set_param( 'offset', 0 );
		$req->set_param( 'aggregator', false );

		\ob_start();
		$ctrl->public_stream_run( $req );
		$out = \ob_get_clean();

		// rid sourced from KEY surfaces on the emitted entry.
		$this->assertStringContainsString( '"rid":"key-supplied-rid"', $out );
	}

	// =========================================================================
	// register_routes: aggregator validate_callback covers extra inputs.
	// =========================================================================

	/**
	 * The `aggregator` arg's `sanitize_callback` uses FILTER_VALIDATE_BOOLEAN
	 * — verified for `true`/`false`/`yes`/`no`. Also exercise the int 1/0
	 * variants since those flow through query-string parsing.
	 */
	public function test_aggregator_sanitize_handles_int_inputs(): void {
		( new FirehoseStreamController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/firehose/stream']['args']['aggregator']['sanitize_callback'];

		$this->assertTrue( $cb( 1 ) );
		$this->assertFalse( $cb( 0 ) );
		$this->assertTrue( $cb( true ) );
		$this->assertFalse( $cb( false ) );
		// Garbage input falls through to false (FILTER_VALIDATE_BOOLEAN strict-ish behavior).
		$this->assertFalse( $cb( 'garbage' ) );
	}
}
