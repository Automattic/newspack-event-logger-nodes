<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Request_Flight_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * RequestBuilder consumes JSONL firehose lines and emits assembled completed-
 * request docs. The firehose line shape mirrors what LogManager writes:
 *
 *   { "n": int, "rid": str, "k": str, "m": mixed, "l": str, "duration_ms": float, "ts": float }
 *
 * State callbacks fill top-level fields on the in-flight request:
 *  - `process (start)` — initializes the request, populates timestamp/process_id/host
 *  - `process (complete)` — terminal: emits the assembled doc downstream
 *  - `request` — extracts URL + method
 *  - `environment_v2` — extracts REMOTE_ADDR / SERVER_NAME / GEOIP_COUNTRY_CODE / etc.
 *  - `worker_type` — sets is_worker=true
 *  - `memory` — extracts peak_mb
 *
 * Anything else with " (start)" / " (complete)" suffix pushes/pops the LIFO
 * stack and accumulates into profiles{}.
 */
#[CoversClass( Request_Builder_Node::class )]
class RequestBuilderTest extends TestCase {

	/**
	 * The builder registers its maintenance hitchhike in arguments(), which needs
	 * a live _router (as in a real worker). Provide one so arguments()-calling
	 * tests follow the production name -> arguments -> sink lifecycle. Core::reset()
	 * in the parent setUp clears it between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
	}

	/**
	 * Build a firehose-line message for fill().
	 *
	 * @param int    $n      Line number.
	 * @param string $rid    Request ID.
	 * @param string $k      Keyword.
	 * @param array  $extra  Additional fields (m, l, duration_ms, ts, status_code, error_status).
	 */
	private function firehose_msg( int $n, string $rid, string $k, array $extra = [] ): array {
		$entry = \array_merge(
			[ 'n' => $n, 'rid' => $rid, 'k' => $k, 'ts' => 1_700_000_000 ],
			$extra
		);

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		// Producer convention: rid lives in Message::KEY (LogManager since v0.2.17).
		// Tests must stamp it here too — RequestBuilder reads rid from KEY only.
		$msg[ Message::KEY ]       = $rid;
		$msg[ Message::VALUE ]     = $entry;
		return $msg;
	}

	private function fill( Request_Builder_Node $rb, int $n, string $rid, string $k, array $extra = [] ): void {
		$msg = $this->firehose_msg( $n, $rid, $k, $extra );
		$rb->fill( $msg );
	}

	private function captured_request( Capture_Sink_Node $capture, int $i = 0 ): array {
		return (array) $capture->captured[ $i ][ Message::VALUE ];
	}

	/**
	 * Read the in-flight cache count via the production GET_CACHE request
	 * verb (the same path the dashboard reads), not a test-only accessor.
	 */
	private function cache_size( Request_Builder_Node $rb ): int {
		$prev    = $rb->sink();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_REQUEST;
		$msg[ Message::FROM ]  = 'test-probe';
		$msg[ Message::VALUE ] = 'GET_CACHE';
		$rb->fill( $msg );

		$rb->sink( $prev );

		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_RESPONSE ) && ( $type & Message::TM_STRUCT ) ) {
				return (int) $captured[ Message::VALUE ]['data']['pending_count'];
			}
		}
		$this->fail( 'GET_CACHE reply not captured' );
	}

	// --- Basic lifecycle --------------------------------------------------

	public function test_constructor_initializes_empty_cache(): void {
		$rb = new Request_Builder_Node();
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_first_line_must_be_process_start(): void {
		$rb = new Request_Builder_Node();
		// Random non-start line for an unseen rid is silently dropped.
		$this->fill( $rb, 1, 'unknown', 'init (start)', [ 'l' => '' ] );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_process_start_creates_in_flight_request(): void {
		$rb = new Request_Builder_Node();
		$this->fill( $rb, 1, 'r1', 'process (start)', [
			'm' => '12345 on test-host',
			'l' => '',
		] );
		$this->assertSame( 1, $this->cache_size( $rb ) );
	}

	public function test_complete_with_url_emits_assembled_request(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)', [ 'm' => '99 on host', 'l' => '' ] );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /post/123' ] );
		$this->fill( $rb, 3, 'r1', 'process (complete)', [ 'duration_ms' => 50.0, 'status_code' => 200 ] );

		$this->assertCount( 1, $capture->captured );
		$req = $this->captured_request( $capture );
		$this->assertSame( 'r1', $req['rid'] );
		$this->assertSame( '/post/123', $req['url'] );
		$this->assertSame( 'GET', $req['request_method'] );
		$this->assertEqualsWithDelta( 50.0, $req['duration_ms'], 1e-9 );
		$this->assertSame( 200, $req['status_code'] );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_complete_without_url_skipped(): void {
		// CLI bootstrap with no REQUEST_URI: process completes but request{} never
		// fired, so url is empty. Don't emit (not addressable).
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'r1', 'process (complete)', [ 'duration_ms' => 10.0, 'status_code' => 200 ] );

		$this->assertCount( 0, $capture->captured );
	}

	public function test_non_array_value_silently_dropped(): void {
		$rb                    = new Request_Builder_Node();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = 'not-an-array';
		$rb->fill( $msg );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_missing_rid_silently_dropped(): void {
		$rb                    = new Request_Builder_Node();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'k' => 'process (start)', 'ts' => 1 ];
		$rb->fill( $msg );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	// --- State callback extraction ----------------------------------------

	public function test_environment_v2_extracts_remote_addr(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'REMOTE_ADDR => "1.2.3.4"' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)', [ 'duration_ms' => 1.0 ] );

		$req = $this->captured_request( $capture );
		$this->assertSame( '1.2.3.4', $req['remote_addr'] );
	}

	public function test_environment_v2_invalid_remote_addr_becomes_empty(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'REMOTE_ADDR => "not-an-ip"' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( '', $req['remote_addr'] );
	}

	public function test_environment_v2_falls_back_to_x_forwarded_for(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		// REMOTE_ADDR comes through invalid → remote_addr stays empty.
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'REMOTE_ADDR => ""' ] );
		// XFF picks up the real IP.
		$this->fill( $rb, 4, 'r1', 'environment_v2', [ 'm' => 'HTTP_X_FORWARDED_FOR => "5.6.7.8, 9.9.9.9"' ] );
		$this->fill( $rb, 5, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( '5.6.7.8', $req['remote_addr'] );
	}

	public function test_environment_v2_extracts_server_name_country_user_agent_ja4(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'SERVER_NAME => "example.com"' ] );
		$this->fill( $rb, 4, 'r1', 'environment_v2', [ 'm' => 'GEOIP_COUNTRY_CODE => "US"' ] );
		$this->fill( $rb, 5, 'r1', 'environment_v2', [ 'm' => 'HTTP_USER_AGENT => "curl/7.0"' ] );
		$this->fill( $rb, 6, 'r1', 'environment_v2', [ 'm' => 'HTTP_X_JA4_HASH => "deadbeef"' ] );
		$this->fill( $rb, 7, 'r1', 'environment_v2', [ 'm' => 'HTTP_FROM => "from@example"' ] );
		$this->fill( $rb, 8, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( 'example.com', $req['server_name'] );
		$this->assertSame( 'US', $req['country_code'] );
		$this->assertSame( 'curl/7.0', $req['user_agent'] );
		$this->assertSame( 'deadbeef', $req['ja4_hash'] );
		$this->assertSame( 'from@example', $req['http_from'] );
	}

	public function test_worker_type_marks_request_as_worker(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'NEWSPACK_NODES_WORKER_TYPE => "stream-merger"' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertTrue( $req['is_worker'] );
	}

	public function test_memory_extracts_peak_mb(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'memory', [ 'm' => [ 'peak' => '32MB', 'end' => '24MB' ] ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertEqualsWithDelta( 32.0, $req['peak_mb'], 1e-9 );
	}

	public function test_request_strips_query_string_from_url(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /post/123?foo=bar&baz=qux' ] );
		$this->fill( $rb, 3, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( '/post/123', $req['url'] );
	}

	public function test_process_start_extracts_process_id_and_host(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)', [ 'm' => '12345 on test-host.lan', 'l' => '' ] );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( '12345', $req['process_id'] );
		$this->assertSame( 'test-host.lan', $req['host'] );
	}

	// --- Stack / profiles -------------------------------------------------

	public function test_lifo_stack_pushes_pops_with_profile_aggregation(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'wp_head hook (start)', [ 'l' => '' ] );
		$this->fill( $rb, 4, 'r1', 'wp_head hook (complete)', [ 'duration_ms' => 25.0 ] );
		$this->fill( $rb, 5, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertArrayHasKey( 'profiles', $req );
		$this->assertArrayHasKey( 'wp_head hook', $req['profiles'] );
		$this->assertEqualsWithDelta( 25.0, $req['profiles']['wp_head hook']['time'], 1e-9 );
		$this->assertSame( 1, $req['profiles']['wp_head hook']['count'] );
	}

	public function test_callback_completion_does_not_subtract_from_parent_hook(): void {
		// Callback frames (' @N') represent breakdowns of their parent hook's
		// time. So complete-of-callback must not subtract from the parent hook.
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'the_content hook (start)', [ 'l' => '' ] );
		$this->fill( $rb, 4, 'r1', 'the_content @10 (start)', [ 'l' => '' ] );
		$this->fill( $rb, 5, 'r1', 'the_content @10 (complete)', [ 'duration_ms' => 5.0 ] );
		$this->fill( $rb, 6, 'r1', 'the_content hook (complete)', [ 'duration_ms' => 20.0 ] );
		$this->fill( $rb, 7, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		// the_content hook must keep its full 20.0 (callback @10 didn't subtract).
		$this->assertEqualsWithDelta( 20.0, $req['profiles']['the_content hook']['time'], 1e-9 );
	}

	public function test_mismatched_complete_searches_backward_and_unwinds(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'outer (start)', [ 'l' => '' ] );
		$this->fill( $rb, 4, 'r1', 'inner (start)', [ 'l' => '' ] );
		// Skip inner-complete, jump straight to outer-complete: stack mismatch.
		$this->fill( $rb, 5, 'r1', 'outer (complete)', [ 'duration_ms' => 10.0 ] );
		$this->fill( $rb, 6, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertArrayHasKey( 'outer', $req['profiles'] );
		// outer kept its 10.0; "inner" was unwound.
		$this->assertEqualsWithDelta( 10.0, $req['profiles']['outer']['time'], 1e-9 );
	}

	public function test_runaway_stack_keeps_request_visible(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		// Push 60 nested starts (> MAX_STACK_DEPTH=50).
		for ( $i = 0; $i < 60; $i++ ) {
			$this->fill( $rb, $i + 3, 'r1', "deep_$i (start)", [ 'l' => '' ] );
		}

		// Runaway requests stay visible in the cache (matches legacy
		// InflightTracker + the Perl gyroscope) — the SchemaParityAudit
		// test_inflight_snapshot_surfaces_runaway_requests_like_legacy
		// is the contract for this. push_stack caps the stack depth, so
		// memory stays bounded even with the runaway request retained.
		$this->assertSame( 1, $this->cache_size( $rb ) );
	}

	public function test_truncation_when_entries_exceed_max(): void {
		// Reduce MAX_ENTRIES_PER_REQUEST? It's a constant. Fast smoke test:
		// Fill 100 entries (well below 50000) and ensure no truncation marker.
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		for ( $i = 0; $i < 100; $i++ ) {
			$this->fill( $rb, $i + 3, 'r1', 'noise', [ 'm' => "msg-$i" ] );
		}
		$this->fill( $rb, 200, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertArrayNotHasKey( 'truncated', $req );
		$this->assertCount( 103, $req['entries'] ); // start + request + 100 noise + complete
	}

	// --- Errors sink ------------------------------------------------------

	public function test_error_keyword_forwarded_to_errors_target(): void {
		// Errors and completed-request messages now go through the SAME sink
		// (the routed path); they're distinguished by the message TO field —
		// errors carry TO=errors_target, completed-requests carry TO=target.
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->connect_node( 'main:target' );
		$rb->set_errors_target( 'errors:target' );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'error', [ 'm' => 'something broke' ] );
		$this->fill( $rb, 4, 'r1', 'warning', [ 'm' => 'deprecation' ] );
		$this->fill( $rb, 5, 'r1', 'process (complete)' );

		$by_to = [];
		foreach ( $capture->captured as $m ) {
			$by_to[ $m[ Message::TO ] ][] = $m;
		}
		$this->assertCount( 2, $by_to['errors:target'] ?? [], 'error + warning forwarded' );
		$this->assertCount( 1, $by_to['main:target'] ?? [], 'main target only got the completed request' );
	}

	public function test_suffix_error_warning_keywords_also_forwarded(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->set_errors_target( 'errors:target' );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'wpdb (error)', [ 'm' => 'mysql gone away' ] );
		$this->fill( $rb, 4, 'r1', 'something (warning)', [ 'm' => 'deprecated api' ] );
		$this->fill( $rb, 5, 'r1', 'process (complete)' );

		$errors = \array_filter(
			$capture->captured,
			fn ( $m ) => 'errors:target' === $m[ Message::TO ]
		);
		$this->assertCount( 2, $errors );
	}

	// --- Idle timeout via the builder's own Router-hitchhike timer --------

	/**
	 * Age the cache's rotation clock so the next rotate_if_due() fires a rotation,
	 * letting a test drive timed (idle) rotation deterministically without sleeping.
	 */
	private function force_rotation_due( \Newspack_Event_Logger_Nodes\LRU_Cache $cache ): void {
		$ro       = new \ReflectionObject( $cache );
		$interval = $ro->getProperty( 'rotate_interval' );
		$interval->setAccessible( true );
		$last = $ro->getProperty( 'last_rotation' );
		$last->setAccessible( true );
		$last->setValue( $cache, \microtime( true ) - (float) $interval->getValue( $cache ) - 1.0 );
	}

	public function test_builder_timer_times_out_stalled_request_with_no_traffic(): void {
		// A stalled in-flight request (opened with a URL, never completes) must
		// time out from the request-builder's OWN periodic timer — no further
		// firehose line ever arrives to drive rotate_if_due() via fill(). The
		// builder hitchhikes the Router's TIMER; fire() drives maintenance(). Here
		// we call its fire_cb() directly (exactly what the Router calls each tick).
		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( '100 2' ); // bucket_size=100 (no capacity rotation), num_buckets=2.
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /a' ] );

		$this->force_rotation_due( $rb->cache );
		$rb->fire_cb();
		$this->force_rotation_due( $rb->cache );
		$rb->fire_cb();

		$primary = null;
		foreach ( $capture->captured as $m ) {
			if ( '' === ( $m[ Message::TO ] ?? '' ) ) {
				$primary = (array) $m[ Message::VALUE ];
			}
		}
		$this->assertNotNull( $primary, 'stalled request timed out to requests.log on a builder timer tick' );
		$this->assertSame( 'r1', $primary['rid'] );
		$this->assertSame( 'T', $primary['error_status'] );
	}

	public function test_builder_timeout_is_also_sent_to_completed_target(): void {
		// A timed-out request must ALSO reach completed:tee so the gyroscope reaps
		// it (and the aggregator sees the resolution), carrying error_status='T'.
		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( '100 2' );
		$rb->set_completed_target( 'completed:tee' );
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /a' ] );

		$this->force_rotation_due( $rb->cache );
		$rb->fire_cb();
		$this->force_rotation_due( $rb->cache );
		$rb->fire_cb();

		$completed = null;
		foreach ( $capture->captured as $m ) {
			if ( 'completed:tee' === ( $m[ Message::TO ] ?? '' ) ) {
				$completed = (array) $m[ Message::VALUE ];
			}
		}
		$this->assertNotNull( $completed, 'timed-out request mirrored to the completed target' );
		$this->assertSame( 'r1', $completed['rid'] );
		$this->assertSame( 'T', $completed['error_status'] );
	}

	public function test_builder_maintenance_hitchhikes_router_timer(): void {
		// With a live _router present, wiring the named builder registers it on the
		// Router's TIMER event via no-arg set_timer() (hitchhike). A Router tick then
		// drives the builder's maintenance(), timing out a stalled request — proving
		// the request-builder rides the Router's 1s cadence rather than its own slot.
		$router = Core::node( Node_Names::ROUTER );

		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( '100 2' ); // named + _router present => hitchhike registers here.
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /a' ] );

		$this->force_rotation_due( $rb->cache );
		$router->fire_cb();
		$this->force_rotation_due( $rb->cache );
		$router->fire_cb();

		$primary = null;
		foreach ( $capture->captured as $m ) {
			if ( '' === ( $m[ Message::TO ] ?? '' ) ) {
				$primary = (array) $m[ Message::VALUE ];
			}
		}
		$this->assertNotNull( $primary, 'Router TIMER tick drove the builder maintenance timeout' );
		$this->assertSame( 'r1', $primary['rid'] );
		$this->assertSame( 'T', $primary['error_status'] );
	}

	// --- LRU eviction (timed-out) -----------------------------------------

	public function test_lru_eviction_emits_orphan_with_error_status_t(): void {
		// bucket_size=1, num_buckets=2: one item per bucket, two buckets retained.
		// After r2's set, r1's bucket is the oldest and gets evicted.
		$rb      = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( "1 2" );
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		// r1: opens with URL, never completes — destined to be the orphan.
		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /a' ] );

		// r2 set forces oldest bucket out (which holds r1).
		$this->fill( $rb, 1, 'r2', 'process (start)' );

		// r1 evicted as timed-out orphan; r2 still in cache.
		$this->assertCount( 1, $capture->captured );
		$evicted = $this->captured_request( $capture, 0 );
		$this->assertSame( 'r1', $evicted['rid'] );
		$this->assertSame( 'T', $evicted['error_status'] );
		$this->assertSame( 'complete', $evicted['state'] );
	}

	// --- save / restore state --------------------------------------------

	public function test_save_and_restore_round_trip(): void {
		$rb1     = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb1->sink( $capture );

		$this->fill( $rb1, 1, 'r1', 'process (start)' );
		$this->fill( $rb1, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$saved = $rb1->save_state();

		$rb2 = new Request_Builder_Node();
		$rb2->sink( $capture );
		$rb2->restore_state( $saved );

		// Continuing on rb2 must complete r1 and emit it.
		$this->fill( $rb2, 3, 'r1', 'process (complete)' );

		$this->assertCount( 1, $capture->captured );
		$req = $this->captured_request( $capture );
		$this->assertSame( '/x', $req['url'] );
	}

	// --- url_hash + index format -----------------------------------------

	public function test_url_hash_deterministic_strips_query(): void {
		$h1 = Request_Builder_Node::url_hash( '/post/123' );
		$h2 = Request_Builder_Node::url_hash( '/post/123?foo=bar' );
		$this->assertSame( $h1, $h2, 'query string ignored' );
		$this->assertSame( 12, \strlen( $h1 ) );
		// Determinism: same input produces same hash across calls.
		$h3 = Request_Builder_Node::url_hash( '/post/123' );
		$this->assertSame( $h1, $h3 );
	}

	public function test_format_index_entry_round_trip(): void {
		$req = [
			'rid'            => 'abcdef',
			'url'            => '/post/123',
			'timestamp'      => 1_700_000_000,
			'duration_ms'    => 50,
			'status_code'    => 200,
			'peak_mb'        => 32,
			'request_method' => 'GET',
			'error_status'   => '-',
		];
		// $line is the packed Message wire format (positional JSON); VALUE at index 6.
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = $req;
		$line     = Message::packed( $msg );
		$position = [ 'segment_id' => 5, 'offset' => 1024, 'length' => 100 ];

		$entry = Request_Builder_Node::format_index_entry( $line, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 97, \strlen( $entry ) );

		$parsed = Request_Builder_Node::parse_request_index( $entry );
		$this->assertSame( 'abcdef', $parsed['rid'] );
		$this->assertSame( 1_700_000_000, $parsed['timestamp'] );
		$this->assertSame( 50, $parsed['duration_ms'] );
		$this->assertSame( 200, $parsed['status_code'] );
		$this->assertSame( 5, $parsed['segment_id'] );
		$this->assertSame( 1024, $parsed['offset'] );
		$this->assertSame( 100, $parsed['length'] );
		$this->assertSame( 32, $parsed['peak_mb'] );
		$this->assertSame( 'GET', $parsed['method'] );
	}

	public function test_format_index_entry_returns_null_for_missing_url(): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = [ 'rid' => 'x' ];
		$line     = Message::packed( $msg );
		$position = [ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( Request_Builder_Node::format_index_entry( $line, $position ) );
	}

	public function test_parse_request_index_handles_v2_v3_v4_field_lengths(): void {
		// 89-char minimum (v1).
		$v1 = \str_pad( 'rid_only', 32 ) . \str_pad( 'urlh', 12 ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT );
		$this->assertSame( 89, \strlen( $v1 ) );
		$parsed = Request_Builder_Node::parse_request_index( $v1 );
		$this->assertNotNull( $parsed );
		$this->assertArrayNotHasKey( 'peak_mb', $parsed );

		// v2 adds 6 chars peak_mb.
		$v2 = $v1 . \str_pad( '32', 6, '0', \STR_PAD_LEFT );
		$parsed_v2 = Request_Builder_Node::parse_request_index( $v2 );
		$this->assertSame( 32, $parsed_v2['peak_mb'] );
		$this->assertArrayNotHasKey( 'method', $parsed_v2 );

		// v3 adds 1-char method code.
		$v3 = $v2 . 'G';
		$parsed_v3 = Request_Builder_Node::parse_request_index( $v3 );
		$this->assertSame( 'GET', $parsed_v3['method'] );
		$this->assertArrayNotHasKey( 'error_status', $parsed_v3 );

		// v4 adds 1-char error status.
		$v4 = $v3 . 'F';
		$parsed_v4 = Request_Builder_Node::parse_request_index( $v4 );
		$this->assertSame( 'F', $parsed_v4['error_status'] );
	}

	// --- Maintenance ------------------------------------------------------

	public function test_maintenance_drives_cache_rotation(): void {
		$rb = new Request_Builder_Node();
		// No throw — just exercise the path.
		$rb->maintenance();
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	// ── A1: sibling-interpreter + node_schema ─────────────────────────

	public function test_request_builder_constructs_sibling_interpreter(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'req_builder' );

		$sibling = $rb->interpreter();
		$this->assertNotNull( $sibling );
		$this->assertSame( 'req_builder:config', $sibling->name() );
		$this->assertSame( $rb, $sibling->patron() );
	}

	public function test_request_builder_set_errors_target_verb_records_and_dumps(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'req_builder' );

		$result = $rb->interpreter()->dispatch( 'set_errors_target', 'errors:partition' );
		$this->assertSame( 'ok', $result );

		$dump = $rb->dump_config();
		$this->assertStringContainsString( 'cmd req_builder:config set_errors_target errors:partition', $dump );
	}

	public function test_request_builder_set_errors_target_verb_empty_clears_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'req_builder' );

		// Seed.
		$this->assertSame( 'ok', $rb->interpreter()->dispatch( 'set_errors_target', 'errors:partition' ) );
		// Empty arg now clears the target instead of rejecting (live reconfiguration).
		$result = $rb->interpreter()->dispatch( 'set_errors_target' );
		$this->assertSame( 'ok', $result );
		$p = ( new \ReflectionObject( $rb ) )->getProperty( 'errors_target' );
		$p->setAccessible( true );
		$this->assertSame( '', $p->getValue( $rb ) );
	}

	public function test_request_builder_node_schema_declares_verb(): void {
		$schema = Request_Builder_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'set_errors_target', $verb_names );
	}

	// --- target() override fan-out ----------------------------------------

	public function test_target_returns_primary_only_when_errors_target_empty(): void {
		$rb = new Request_Builder_Node();
		$rb->connect_node( 'main:target' );
		// No set_errors_target → primary is returned untouched.
		$this->assertSame( 'main:target', $rb->target() );
	}

	public function test_target_appends_errors_target_when_primary_is_string(): void {
		$rb = new Request_Builder_Node();
		$rb->connect_node( 'main:target' );
		$rb->set_errors_target( 'errors:target' );

		$result = $rb->target();
		$this->assertIsArray( $result );
		$this->assertContains( 'main:target', $result );
		$this->assertContains( 'errors:target', $result );
	}

	public function test_target_appends_errors_target_when_primary_is_empty(): void {
		// Primary target unset, errors_target set → result is just [errors_target].
		$rb = new Request_Builder_Node();
		$rb->set_errors_target( 'errors:target' );

		$result = $rb->target();
		$this->assertIsArray( $result );
		$this->assertSame( [ 'errors:target' ], $result );
	}

	public function test_target_does_not_duplicate_errors_target_already_in_array(): void {
		// Primary is already an array containing errors_target — must not duplicate.
		$rb = new Request_Builder_Node();
		$rb->target( [ 'main:target', 'errors:target' ] );
		$rb->set_errors_target( 'errors:target' );

		$result = $rb->target();
		$this->assertSame( [ 'main:target', 'errors:target' ], $result );
	}

	public function test_inflight_snapshot_start_time_uses_process_start_not_request_bind(): void {
		// With the 00-newspack-profiler mu-plugin live, LogManager stamps the
		// `process (start)` keyword with the real mu-plugin-load wall-clock ts
		// (microtime captured before any plugins load). The `request` keyword
		// fires later, once the URL is known.
		//
		// inflight_snapshot.start_time must reflect the EARLIEST point the PHP
		// process began handling this request (the process-start ts), not the
		// later URL-bind ts — that's what "how long has this been in flight?"
		// means to the operator.
		$rb = new Request_Builder_Node();
		$this->fill( $rb, 1, 'r1', 'process (start)', [ 'ts' => 1700000000.000 ] );
		$this->fill( $rb, 2, 'r1', 'request',         [ 'ts' => 1700000001.500, 'm' => 'GET /x' ] );

		$snap = $rb->flight->inflight_snapshot();
		$this->assertCount( 1, $snap );
		$this->assertSame( 1700000000.000, $snap[0]['start_time'] );
	}

	public function test_target_appends_flight_inflight_target(): void {
		// `set_inflight_target` stores the target on the hidden RequestFlight
		// sibling (`$patron->flight()->target($args)`), not on RequestBuilder
		// directly. Without surfacing it through target(), the topology
		// console's live view misses the request-builder → gyroscope:partition
		// edge — even though edit view (which reads the TSL) shows it correctly.
		$rb = new Request_Builder_Node();
		$rb->connect_node( 'main:target' );
		$rb->set_errors_target( 'errors:target' );
		$rb->flight()->target( 'gyroscope:partition' );

		$result = $rb->target();
		$this->assertIsArray( $result );
		$this->assertContains( 'main:target', $result );
		$this->assertContains( 'errors:target', $result );
		$this->assertContains( 'gyroscope:partition', $result );
	}

	public function test_target_does_not_duplicate_flight_target_already_in_array(): void {
		// If the flight sibling's target is already in the primary array,
		// don't duplicate it in the union.
		$rb = new Request_Builder_Node();
		$rb->target( [ 'main:target', 'gyroscope:partition' ] );
		$rb->flight()->target( 'gyroscope:partition' );

		$this->assertSame( [ 'main:target', 'gyroscope:partition' ], $rb->target() );
	}

	public function test_target_omits_flight_target_when_unset(): void {
		// Empty flight target — no contribution to the union.
		$rb = new Request_Builder_Node();
		$rb->connect_node( 'main:target' );
		$rb->flight()->target( '' );

		$this->assertSame( 'main:target', $rb->target() );
	}

	public function test_target_setter_passes_through_to_parent(): void {
		$rb     = new Request_Builder_Node();
		$result = $rb->target( 'new:target' );
		// Setter returns the stored value (Node::target's storage).
		$this->assertSame( 'new:target', $result );
		// And subsequent get reflects the new primary.
		$this->assertSame( 'new:target', $rb->target() );
	}

	// --- evict_request() early-return paths -------------------------------

	public function test_lru_eviction_skips_request_with_empty_url(): void {
		// Open r1 with NO `request` keyword → url stays empty → evict_request
		// hits the `empty( $request->url )` early return.
		$rb      = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( "1 2" );
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		// Force r1 out by setting r2 (no request keyword → also has no url).
		$this->fill( $rb, 1, 'r2', 'process (start)' );

		// Neither was emitted: both had empty url, evict_request bailed early.
		$this->assertCount( 0, $capture->captured );
	}

	public function test_lru_eviction_skips_already_completed_request(): void {
		// In normal operation a completed request is delete()'d from the cache
		// before LRU could evict it, but evict_request still gates on state to
		// guard the path. Stuff a complete-state stdClass directly into the
		// cache via restore_state and force a rotation.
		$rb      = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( "1 2" );
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		// Seed cache with a complete request via restore_state.
		$rb->restore_state(
			[
				'request_cache' => [
					'buckets' => [
						0 => [
							'r1' => [
								'rid'   => 'r1',
								'url'   => '/done',
								'state' => 'complete',
							],
						],
					],
					'current' => 0,
				],
			]
		);

		// Push two new rids: bucket_size=1, num_buckets=2 means the bucket
		// containing r1 ends up oldest and evicts. (Two new entries fill the
		// next buckets and push beyond num_buckets.)
		$this->fill( $rb, 1, 'r2', 'process (start)' );
		$this->fill( $rb, 1, 'r3', 'process (start)' );

		// Completed r1 was evicted but evict_request short-circuited on state
		// === 'complete'; no emission.
		$this->assertCount( 0, $capture->captured );
	}

	// --- handle_request (TM_REQUEST) --------------------------------------

	private function request_msg( string $verb, string $from = 'asker', string $id = 'req-1' ): array {
		$msg                      = Message::new_message();
		$msg[ Message::TYPE ]     = Message::TM_REQUEST;
		$msg[ Message::FROM ]     = $from;
		$msg[ Message::ID ]       = $id;
		$msg[ Message::KEY ]      = '';
		$msg[ Message::VALUE ]    = $verb;
		return $msg;
	}

	public function test_handle_request_get_cache_returns_empty_payload_on_empty_cache(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->name( 'rb' );

		$msg = $this->request_msg( 'GET_CACHE' );
		$rb->fill( $msg );

		$this->assertCount( 1, $capture->captured );
		$reply = $capture->captured[0];
		$this->assertSame(
			Message::TM_RESPONSE | Message::TM_STRUCT,
			$reply[ Message::TYPE ]
		);
		$this->assertSame( 'rb', $reply[ Message::FROM ] );
		$this->assertSame( 'asker', $reply[ Message::TO ] );
		$this->assertSame( 'req-1', $reply[ Message::ID ] );
		$payload = $reply[ Message::VALUE ];
		$this->assertSame( 'GET_CACHE', $payload['verb'] );
		$this->assertSame( 0, $payload['data']['pending_count'] );
		$this->assertNull( $payload['data']['oldest_rid'] );
		$this->assertSame( 0, $payload['data']['oldest_age_s'] );
		$this->assertSame( [], $payload['data']['sample'] );
	}

	public function test_handle_request_get_cache_reports_pending_count_and_sample(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->name( 'rb' );

		// Open seven in-flight requests (no `process (complete)`).
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->fill( $rb, $i, "rid-$i", 'process (start)' );
		}

		$msg = $this->request_msg( 'GET_CACHE' );
		$rb->fill( $msg );

		// Discard early captured emits (this test doesn't emit any since no
		// `process (complete)`). The GET_CACHE reply is the only message.
		$this->assertCount( 1, $capture->captured );
		$payload = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'GET_CACHE', $payload['verb'] );
		$this->assertSame( 7, $payload['data']['pending_count'] );
		// Cache iterator yields newest first; sample caps at 5.
		$this->assertCount( 5, $payload['data']['sample'] );
		// Sample values are rid strings (not arbitrary stdClass objects).
		foreach ( $payload['data']['sample'] as $sample ) {
			$this->assertIsString( $sample );
		}
		// Each sample rid is one of the seven we opened.
		$opened = [ 'rid-1', 'rid-2', 'rid-3', 'rid-4', 'rid-5', 'rid-6', 'rid-7' ];
		foreach ( $payload['data']['sample'] as $sample ) {
			$this->assertContains( $sample, $opened );
		}
	}

	public function test_handle_request_get_cache_increments_line_counter(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->name( 'rb' );

		// Three real lines bump line_counter; the GET_CACHE request goes
		// through the TM_REQUEST branch and does NOT bump it.
		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'process (complete)' );

		$msg = $this->request_msg( 'GET_CACHE' );
		$rb->fill( $msg );

		// The TM_REQUEST response is the last captured message (after the
		// `process (complete)` emission).
		$last    = $capture->captured[ \count( $capture->captured ) - 1 ];
		$payload = $last[ Message::VALUE ];
		$this->assertSame( 'GET_CACHE', $payload['verb'] );
		$this->assertSame( 3, $payload['data']['line_counter'] );
	}

	public function test_handle_request_with_request_response_flag_is_ignored(): void {
		// A reply (TM_STRUCT|TM_RESPONSE, no TM_REQUEST) bypasses fill()'s
		// TM_REQUEST gate, so it's never re-dispatched as a request.
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->name( 'rb' );

		$msg                      = Message::new_message();
		$msg[ Message::TYPE ]     = Message::TM_STRUCT | Message::TM_RESPONSE;
		$msg[ Message::FROM ]     = 'asker';
		$msg[ Message::ID ]       = 'req-1';
		$msg[ Message::VALUE ]    = 'GET_CACHE';
		$rb->fill( $msg );

		// Neither dispatched as a request nor as a TM_STRUCT entry (no flag).
		$this->assertCount( 0, $capture->captured );
	}

	public function test_handle_request_unknown_verb_returns_error_payload(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->name( 'rb' );

		$msg = $this->request_msg( 'WHATEVER_NOT_REAL' );
		$rb->fill( $msg );

		$this->assertCount( 1, $capture->captured );
		$payload = $capture->captured[0][ Message::VALUE ];
		// Verb is upper-cased — anything else is a bug in handle_request.
		$this->assertSame( 'WHATEVER_NOT_REAL', $payload['verb'] );
		$this->assertArrayHasKey( 'error', $payload['data'] );
		$this->assertStringContainsString( 'unknown request verb', $payload['data']['error'] );
		$this->assertStringContainsString( 'WHATEVER_NOT_REAL', $payload['data']['error'] );
	}

	public function test_handle_request_lowercase_verb_uppercased(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );
		$rb->name( 'rb' );

		// Lowercased verb still routes to GET_CACHE via strtoupper.
		$msg = $this->request_msg( 'get_cache' );
		$rb->fill( $msg );

		$payload = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'GET_CACHE', $payload['verb'] );
		$this->assertArrayHasKey( 'pending_count', $payload['data'] );
	}

	// --- fill(): non-string keyword / non-struct messages -----------------

	public function test_fill_non_struct_message_silently_dropped(): void {
		// Pure TM_BYTESTREAM (no TM_STRUCT flag) is ignored: VALUE is presumed
		// to be a string, not an array.
		$rb                    = new Request_Builder_Node();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::KEY ]   = 'r1';
		$msg[ Message::VALUE ] = 'raw line';
		$rb->fill( $msg );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_fill_non_string_keyword_silently_dropped(): void {
		// Entry has a non-string `k` field (corrupt firehose line). Must drop
		// before push/pop/state callbacks, not crash.
		$rb                    = new Request_Builder_Node();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'r1';
		$msg[ Message::VALUE ] = [ 'n' => 1, 'rid' => 'r1', 'k' => [ 'not', 'a', 'string' ] ];
		$rb->fill( $msg );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_fill_stores_peak_mb_and_label_on_entry(): void {
		// Stored entries carry optional duration_ms / peak_mb / l (label) fields
		// when present on the source line.
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill(
			$rb,
			3,
			'r1',
			'wpdb',
			[ 'm' => 'SELECT ...', 'l' => 'SELECT', 'duration_ms' => 4.2, 'peak_mb' => 31.5 ]
		);
		$this->fill( $rb, 4, 'r1', 'process (complete)', [ 'duration_ms' => 50.0 ] );

		$req = $this->captured_request( $capture );
		// Find the `wpdb` entry.
		$wpdb_entry = null;
		foreach ( $req['entries'] as $entry ) {
			if ( 'wpdb' === ( $entry['k'] ?? '' ) ) {
				$wpdb_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $wpdb_entry );
		$this->assertSame( 'SELECT', $wpdb_entry['l'] );
		$this->assertEqualsWithDelta( 4.2, $wpdb_entry['duration_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 31.5, $wpdb_entry['peak_mb'], 1e-9 );
	}

	public function test_fill_truncates_long_string_m_to_max_entry_message_length(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$long = \str_repeat( 'A', 2048 );
		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'noise', [ 'm' => $long ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$found = null;
		foreach ( $req['entries'] as $e ) {
			if ( 'noise' === ( $e['k'] ?? '' ) ) {
				$found = $e;
				break;
			}
		}
		$this->assertNotNull( $found );
		// MAX_ENTRY_MESSAGE_LENGTH = 1024.
		$this->assertSame( 1024, \strlen( $found['m'] ) );
	}

	// --- process (complete): error_status validation ----------------------

	public function test_process_complete_invalid_error_status_normalized_to_dash(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		// Bogus multi-char error_status falls back to '-'.
		$this->fill(
			$rb,
			3,
			'r1',
			'process (complete)',
			[ 'duration_ms' => 1.0, 'error_status' => 'BOGUS' ]
		);

		$req = $this->captured_request( $capture );
		$this->assertSame( '-', $req['error_status'] );
	}

	public function test_process_complete_unknown_single_char_error_status_normalized_to_dash(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		// Single char but not in [-, F, T] → fall back.
		$this->fill(
			$rb,
			3,
			'r1',
			'process (complete)',
			[ 'duration_ms' => 1.0, 'error_status' => 'X' ]
		);

		$req = $this->captured_request( $capture );
		$this->assertSame( '-', $req['error_status'] );
	}

	public function test_process_complete_accepts_f_status(): void {
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill(
			$rb,
			3,
			'r1',
			'process (complete)',
			[ 'duration_ms' => 1.0, 'error_status' => 'F' ]
		);

		$req = $this->captured_request( $capture );
		$this->assertSame( 'F', $req['error_status'] );
	}

	// --- restore_state() defensive branches -------------------------------

	public function test_restore_state_no_request_cache_key_noop(): void {
		// Saved state without the expected 'request_cache' key is a no-op
		// (used to be a fatal `undefined index`).
		$rb = new Request_Builder_Node();
		$rb->restore_state( [] );
		$this->assertSame( 0, $this->cache_size( $rb ) );
	}

	public function test_restore_state_with_array_request_rehydrates_to_object(): void {
		// save_state converted stdClass → array; restore_state must rehydrate.
		// Round-trip via the public surface: save → swap to a new builder →
		// restore → continue.
		$rb1     = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb1->sink( $capture );

		$this->fill( $rb1, 1, 'r1', 'process (start)' );
		$this->fill( $rb1, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$saved = $rb1->save_state();

		// Confirm save_state converted the in-flight request to an array (since
		// restore_state's rehydrate branch is the one we're targeting).
		$bucket0 = $saved['request_cache']['buckets'][0] ?? [];
		$this->assertIsArray( $bucket0['r1'] ?? null, 'save_state converts stdClass to array' );

		$rb2 = new Request_Builder_Node();
		$rb2->sink( $capture );
		$rb2->restore_state( $saved );

		// If the rehydrate worked, completing on rb2 emits an assembled doc.
		$this->fill( $rb2, 3, 'r1', 'process (complete)' );
		$this->assertCount( 1, $capture->captured );
		$req = $this->captured_request( $capture );
		$this->assertSame( '/x', $req['url'] );
	}

	// --- format_index_entry: size bounds + method codes -------------------

	private function format_index_for( array $value, array $position ): ?string {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = $value;
		$line                  = Message::packed( $msg );
		return Request_Builder_Node::format_index_entry( $line, $position );
	}

	public function test_format_index_entry_returns_empty_when_offset_exceeds_cap(): void {
		$out = $this->format_index_for(
			[ 'rid' => 'r1', 'url' => '/x' ],
			// 10_000_000_000 > 9_999_999_999 ceiling.
			[ 'segment_id' => 0, 'offset' => 10_000_000_000, 'length' => 100 ]
		);
		$this->assertSame( '', $out );
	}

	public function test_format_index_entry_returns_empty_when_length_exceeds_cap(): void {
		$out = $this->format_index_for(
			[ 'rid' => 'r1', 'url' => '/x' ],
			// 100_000_000 > 99_999_999 ceiling.
			[ 'segment_id' => 0, 'offset' => 0, 'length' => 100_000_000 ]
		);
		$this->assertSame( '', $out );
	}

	public function test_format_index_entry_returns_empty_when_segment_id_exceeds_cap(): void {
		$out = $this->format_index_for(
			[ 'rid' => 'r1', 'url' => '/x' ],
			// 1_000_000 > 999_999 ceiling.
			[ 'segment_id' => 1_000_000, 'offset' => 0, 'length' => 100 ]
		);
		$this->assertSame( '', $out );
	}

	public function test_format_index_entry_returns_null_when_value_is_not_array(): void {
		// A packed message where VALUE is a string (TM_BYTESTREAM-shaped wire).
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'raw';
		$line                  = Message::packed( $msg );
		$this->assertNull(
			Request_Builder_Node::format_index_entry(
				$line,
				[ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ]
			)
		);
	}

	public function test_format_index_entry_clamps_peak_mb_at_999999(): void {
		$line = $this->format_index_for(
			[ 'rid' => 'big', 'url' => '/x', 'peak_mb' => 5_000_000.0 ],
			[ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ]
		);
		$this->assertNotNull( $line );
		$this->assertNotSame( '', $line );
		// peak_mb field: 6 chars starting at position 89.
		$peak_segment = \substr( $line, 89, 6 );
		$this->assertSame( '999999', $peak_segment );
	}

	public function test_format_index_entry_encodes_method_codes(): void {
		$cases = [
			'GET'     => 'G',
			'POST'    => 'P',
			'HEAD'    => 'H',
			'DELETE'  => 'D',
			'PUT'     => 'U',
			'PATCH'   => 'A',
			'OPTIONS' => 'O',
			'CLI'     => 'C',
		];
		foreach ( $cases as $method => $code ) {
			$line = $this->format_index_for(
				[ 'rid' => 'r1', 'url' => '/x', 'request_method' => $method ],
				[ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ]
			);
			$this->assertNotNull( $line, "method=$method produces a line" );
			$this->assertNotSame( '', $line );
			// Method code: 1 char at position 95.
			$this->assertSame( $code, \substr( $line, 95, 1 ), "method=$method → code=$code" );
		}
	}

	public function test_format_index_entry_unknown_method_falls_back_to_get_code(): void {
		$line = $this->format_index_for(
			[ 'rid' => 'r1', 'url' => '/x', 'request_method' => 'WEIRDVERB' ],
			[ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ]
		);
		$this->assertNotNull( $line );
		$this->assertSame( 'G', \substr( $line, 95, 1 ) );
	}

	public function test_format_index_entry_writes_error_status_t_when_timed_out(): void {
		$line = $this->format_index_for(
			[ 'rid' => 'r1', 'url' => '/x', 'error_status' => 'T' ],
			[ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ]
		);
		$this->assertNotNull( $line );
		// error_status: 1 char at position 96.
		$this->assertSame( 'T', \substr( $line, 96, 1 ) );
	}

	// --- parse_request_index defensive paths ------------------------------

	public function test_parse_request_index_returns_null_for_short_line(): void {
		$this->assertNull( Request_Builder_Node::parse_request_index( 'too-short' ) );
		$this->assertNull( Request_Builder_Node::parse_request_index( \str_repeat( 'x', 88 ) ) );
	}

	public function test_parse_request_index_v3_unknown_method_code_falls_back_to_literal_char(): void {
		// v3 layout with an unknown 1-char method code: parse should fall back
		// to the raw character (per the static $methods table's `?? \substr(...)`).
		$base = \str_pad( 'rid', 32 ) . \str_pad( 'urlh', 12 ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 6, '0', \STR_PAD_LEFT );
		$v3   = $base . 'Z';
		$this->assertSame( 96, \strlen( $v3 ) );
		$parsed = Request_Builder_Node::parse_request_index( $v3 );
		$this->assertNotNull( $parsed );
		$this->assertSame( 'Z', $parsed['method'] );
	}

	public function test_parse_request_index_v4_invalid_error_status_dropped(): void {
		// v4-length line but error_status char is neither 'F' nor 'T' → field
		// is omitted from the parsed array.
		$base = \str_pad( 'rid', 32 ) . \str_pad( 'urlh', 12 ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 6, '0', \STR_PAD_LEFT );
		$v4   = $base . 'G' . '-';
		$this->assertSame( 97, \strlen( $v4 ) );
		$parsed = Request_Builder_Node::parse_request_index( $v4 );
		$this->assertNotNull( $parsed );
		// '-' is not in [F, T] → no error_status key.
		$this->assertArrayNotHasKey( 'error_status', $parsed );
	}

	public function test_parse_request_index_strips_trailing_newline(): void {
		// Lines on disk are JSONL — newline-terminated. parse_request_index
		// should rtrim before measuring length.
		$base = \str_pad( 'rid_nl', 32 ) . \str_pad( 'urlh', 12 ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT );
		$parsed = Request_Builder_Node::parse_request_index( $base . "\n" );
		$this->assertNotNull( $parsed );
		$this->assertSame( 'rid_nl', $parsed['rid'] );
	}

	// --- emit_error: silent on missing target / missing sink --------------

	public function test_emit_error_silent_when_errors_target_unset(): void {
		// No set_errors_target call → emit_error early-returns; the completed
		// request still emits.
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'error', [ 'm' => 'boom' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		// Only the completed-request emission; the error line did NOT bounce
		// to an errors target.
		$this->assertCount( 1, $capture->captured );
		$req = $this->captured_request( $capture );
		$this->assertSame( '/x', $req['url'] );
	}

	public function test_emit_error_silent_when_sink_is_null(): void {
		// errors_target set but no sink wired → emit_error early-returns.
		$rb = new Request_Builder_Node();
		$rb->set_errors_target( 'errors:target' );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		// Must not crash even though sink is null.
		$this->fill( $rb, 3, 'r1', 'error', [ 'm' => 'boom' ] );
		// Cache still holds r1 since complete hasn't arrived.
		$this->assertSame( 1, $this->cache_size( $rb ) );
	}

	// --- environment_v2 long message guard --------------------------------

	public function test_environment_v2_skipped_when_message_exceeds_8192_bytes(): void {
		// Lines longer than 8192 bytes are silently dropped (DoS guard).
		$rb      = new Request_Builder_Node();
		$capture = new Capture_Sink_Node();
		$rb->sink( $capture );

		$huge = 'REMOTE_ADDR => "' . \str_repeat( '1', 9000 ) . '"';
		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => $huge ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		// remote_addr never set because the line was skipped before the regex.
		$this->assertArrayNotHasKey( 'remote_addr', $req );
	}

	// ------------------------------------------------------------------------
	// Tachikoma-parity arguments() migration (Task 9).
	// ------------------------------------------------------------------------

	/**
	 * No-arg ctor leaves bucket_size / num_buckets at their schema defaults
	 * and builds the LRU_Cache with those defaults; arguments() walks
	 * node_schema and reassigns the knobs from positional tokens, then the
	 * override rebuilds the cache with the new values.
	 */
	public function test_constructible_via_no_arg_ctor_and_arguments_setter(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->arguments( '7 5' );
		$ref = new \ReflectionClass( $rb );
		$this->assertSame( 7, $ref->getProperty( 'bucket_size' )->getValue( $rb ) );
		$this->assertSame( 5, $ref->getProperty( 'num_buckets' )->getValue( $rb ) );
		// Cache rebuilt with the new dimensions.
		$cache = $rb->cache;
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\LRU_Cache::class, $cache );
		$cref = new \ReflectionClass( $cache );
		$this->assertSame( 7, $cref->getProperty( 'bucket_size' )->getValue( $cache ) );
		$this->assertSame( 5, $cref->getProperty( 'num_buckets' )->getValue( $cache ) );
	}

	/**
	 * Empty-string arguments() no-ops: the cache stays as the no-arg-ctor
	 * cache (DEFAULT_BUCKET_SIZE × DEFAULT_NUM_BUCKETS). Flight sibling
	 * mounted regardless of args.
	 */
	public function test_no_arg_ctor_builds_cache_with_defaults_and_mounts_flight(): void {
		$rb = new Request_Builder_Node();
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\LRU_Cache::class, $rb->cache );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Request_Flight_Node::class, $rb->flight );
	}

	// --- name() sibling cascade (flight) ----------------------------------

	public function test_name_registers_flight_sibling_as_name_flight(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$this->assertSame( 'rb:flight', $rb->flight()->name() );
		$this->assertSame( $rb->flight(), Core::node( 'rb:flight' ) );
	}

	public function test_name_rename_moves_flight_sibling(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'first' );
		$this->assertSame( $rb->flight(), Core::node( 'first:flight' ) );
		$rb->name( 'second' );
		$this->assertNull( Core::node( 'first:flight' ) );
		$this->assertSame( $rb->flight(), Core::node( 'second:flight' ) );
	}

	public function test_name_null_throws(): void {
		// A named node is committed until remove_node(); name(null) throws.
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$this->expectException( \RuntimeException::class );
		$rb->name( null );
	}

	public function test_name_empty_string_throws(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$this->expectException( \RuntimeException::class );
		$rb->name( '' );
	}

	public function test_remove_node_unregisters_flight_sibling(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$this->assertSame( $rb->flight(), Core::node( 'rb:flight' ) );
		$rb->remove_node();
		$this->assertNull( Core::node( 'rb' ) );
		$this->assertNull( Core::node( 'rb:flight' ) );
	}

	public function test_name_getter_leaves_flight_sibling_intact(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$rb->name(); // getter — must not touch the flight sibling.
		$this->assertSame( $rb->flight(), Core::node( 'rb:flight' ) );
	}

	public function test_name_zero_registers_flight_as_zero_flight(): void {
		// Perl length() presence: '0' IS a value, so the node names literally "0"
		// and the flight sibling becomes "0:flight".
		$rb = new Request_Builder_Node();
		$rb->name( '0' );
		$this->assertSame( '0', $rb->name() );
		$this->assertSame( $rb, Core::node( '0' ) );
		$this->assertSame( '0:flight', $rb->flight()->name() );
		$this->assertSame( $rb->flight(), Core::node( '0:flight' ) );
	}

	public function test_check_name_availability_detects_flight_sibling_collision(): void {
		// A pre-existing `x:flight` node blocks naming a RequestBuilder `x`.
		$blocker = new Request_Flight_Node();
		$blocker->name( 'x:flight' );
		$rb = new Request_Builder_Node();
		$this->expectException( \RuntimeException::class );
		$rb->name( 'x' );
	}

	public function test_collision_throws_before_mutation_leaves_flight_unregistered(): void {
		// A failed rename (collision on the new flight name) must not have
		// renamed the flight sibling toward the colliding name.
		$blocker = new Request_Flight_Node();
		$blocker->name( 'taken:flight' );
		$rb = new Request_Builder_Node();
		try {
			$rb->name( 'taken' );
			$this->fail( 'expected collision exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertNull( Core::node( 'taken' ) );
			$this->assertSame( $blocker, Core::node( 'taken:flight' ) );
		}
	}
}
