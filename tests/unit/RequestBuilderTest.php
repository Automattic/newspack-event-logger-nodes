<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\CaptureSink;
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
#[CoversClass( RequestBuilder::class )]
class RequestBuilderTest extends TestCase {

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
		$msg[ Message::VALUE ]     = $entry;
		return $msg;
	}

	private function fill( RequestBuilder $rb, int $n, string $rid, string $k, array $extra = [] ): void {
		$msg = $this->firehose_msg( $n, $rid, $k, $extra );
		$rb->fill( $msg );
	}

	private function captured_request( CaptureSink $capture, int $i = 0 ): array {
		return (array) $capture->captured[ $i ][ Message::VALUE ];
	}

	// --- Basic lifecycle --------------------------------------------------

	public function test_constructor_initializes_empty_cache(): void {
		$rb = new RequestBuilder();
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_first_line_must_be_process_start(): void {
		$rb = new RequestBuilder();
		// Random non-start line for an unseen rid is silently dropped.
		$this->fill( $rb, 1, 'unknown', 'init (start)', [ 'l' => '' ] );
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_process_start_creates_in_flight_request(): void {
		$rb = new RequestBuilder();
		$this->fill( $rb, 1, 'r1', 'process (start)', [
			'm' => '12345 on test-host',
			'l' => '',
		] );
		$this->assertSame( 1, $rb->cache_size() );
	}

	public function test_complete_with_url_emits_assembled_request(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_complete_without_url_skipped(): void {
		// CLI bootstrap with no REQUEST_URI: process completes but request{} never
		// fired, so url is empty. Don't emit (not addressable).
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'r1', 'process (complete)', [ 'duration_ms' => 10.0, 'status_code' => 200 ] );

		$this->assertCount( 0, $capture->captured );
	}

	public function test_non_array_value_silently_dropped(): void {
		$rb                    = new RequestBuilder();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = 'not-an-array';
		$rb->fill( $msg );
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_missing_rid_silently_dropped(): void {
		$rb                    = new RequestBuilder();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'k' => 'process (start)', 'ts' => 1 ];
		$rb->fill( $msg );
		$this->assertSame( 0, $rb->cache_size() );
	}

	// --- State callback extraction ----------------------------------------

	public function test_environment_v2_extracts_remote_addr(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'REMOTE_ADDR => "1.2.3.4"' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)', [ 'duration_ms' => 1.0 ] );

		$req = $this->captured_request( $capture );
		$this->assertSame( '1.2.3.4', $req['remote_addr'] );
	}

	public function test_environment_v2_invalid_remote_addr_becomes_empty(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'REMOTE_ADDR => "not-an-ip"' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( '', $req['remote_addr'] );
	}

	public function test_environment_v2_falls_back_to_x_forwarded_for(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'environment_v2', [ 'm' => 'NEWSPACK_NODES_WORKER_TYPE => "stream-merger"' ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertTrue( $req['is_worker'] );
	}

	public function test_memory_extracts_peak_mb(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$this->fill( $rb, 3, 'r1', 'memory', [ 'm' => [ 'peak' => '32MB', 'end' => '24MB' ] ] );
		$this->fill( $rb, 4, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertEqualsWithDelta( 32.0, $req['peak_mb'], 1e-9 );
	}

	public function test_request_strips_query_string_from_url(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /post/123?foo=bar&baz=qux' ] );
		$this->fill( $rb, 3, 'r1', 'process (complete)' );

		$req = $this->captured_request( $capture );
		$this->assertSame( '/post/123', $req['url'] );
	}

	public function test_process_start_extracts_process_id_and_host(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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

	public function test_runaway_stack_evicts_request(): void {
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$this->fill( $rb, 1, 'r1', 'process (start)' );
		$this->fill( $rb, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		// Push 60 nested starts (> MAX_STACK_DEPTH=50).
		for ( $i = 0; $i < 60; $i++ ) {
			$this->fill( $rb, $i + 3, 'r1', "deep_$i (start)", [ 'l' => '' ] );
		}

		// Once is_runaway flips true, the request gets evicted from cache.
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_truncation_when_entries_exceed_max(): void {
		// Reduce MAX_ENTRIES_PER_REQUEST? It's a constant. Fast smoke test:
		// Fill 100 entries (well below 50000) and ensure no truncation marker.
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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
		$rb      = new RequestBuilder();
		$capture = new CaptureSink();
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

	// --- LRU eviction (timed-out) -----------------------------------------

	public function test_lru_eviction_emits_orphan_with_error_status_t(): void {
		// bucket_size=1, num_buckets=2: one item per bucket, two buckets retained.
		// After r2's set, r1's bucket is the oldest and gets evicted.
		$rb      = new RequestBuilder( bucket_size: 1, num_buckets: 2 );
		$capture = new CaptureSink();
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
		$rb1     = new RequestBuilder();
		$capture = new CaptureSink();
		$rb1->sink( $capture );

		$this->fill( $rb1, 1, 'r1', 'process (start)' );
		$this->fill( $rb1, 2, 'r1', 'request', [ 'm' => 'GET /x' ] );
		$saved = $rb1->save_state();

		$rb2 = new RequestBuilder();
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
		$h1 = RequestBuilder::url_hash( '/post/123' );
		$h2 = RequestBuilder::url_hash( '/post/123?foo=bar' );
		$this->assertSame( $h1, $h2, 'query string ignored' );
		$this->assertSame( 12, \strlen( $h1 ) );
		// Determinism: same input produces same hash across calls.
		$h3 = RequestBuilder::url_hash( '/post/123' );
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

		$entry = RequestBuilder::format_index_entry( $line, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 97, \strlen( $entry ) );

		$parsed = RequestBuilder::parse_request_index( $entry );
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
		$this->assertNull( RequestBuilder::format_index_entry( $line, $position ) );
	}

	public function test_parse_request_index_handles_v2_v3_v4_field_lengths(): void {
		// 89-char minimum (v1).
		$v1 = \str_pad( 'rid_only', 32 ) . \str_pad( 'urlh', 12 ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT ) . \str_pad( '0', 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT ) . \str_pad( '0', 10, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 8, '0', \STR_PAD_LEFT );
		$this->assertSame( 89, \strlen( $v1 ) );
		$parsed = RequestBuilder::parse_request_index( $v1 );
		$this->assertNotNull( $parsed );
		$this->assertArrayNotHasKey( 'peak_mb', $parsed );

		// v2 adds 6 chars peak_mb.
		$v2 = $v1 . \str_pad( '32', 6, '0', \STR_PAD_LEFT );
		$parsed_v2 = RequestBuilder::parse_request_index( $v2 );
		$this->assertSame( 32, $parsed_v2['peak_mb'] );
		$this->assertArrayNotHasKey( 'method', $parsed_v2 );

		// v3 adds 1-char method code.
		$v3 = $v2 . 'G';
		$parsed_v3 = RequestBuilder::parse_request_index( $v3 );
		$this->assertSame( 'GET', $parsed_v3['method'] );
		$this->assertArrayNotHasKey( 'error_status', $parsed_v3 );

		// v4 adds 1-char error status.
		$v4 = $v3 . 'F';
		$parsed_v4 = RequestBuilder::parse_request_index( $v4 );
		$this->assertSame( 'F', $parsed_v4['error_status'] );
	}

	// --- Maintenance ------------------------------------------------------

	public function test_maintenance_drives_cache_rotation(): void {
		$rb = new RequestBuilder();
		// No throw — just exercise the path.
		$rb->maintenance();
		$this->assertSame( 0, $rb->cache_size() );
	}
}
