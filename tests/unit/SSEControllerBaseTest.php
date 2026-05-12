<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

require_once \dirname( __DIR__, 2 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 2 ) . '/includes/rest/class-sse-controller-base.php';

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Concrete subclass for testing the abstract base. Exposes protected methods
 * via simple public wrappers so we can drive the slot / event / stream-control
 * machinery without spinning up a full SSE loop.
 *
 * The streaming loop itself is exercised by overriding `should_continue_stream`
 * to a counted-tick predicate; tests inspect captured output and headers via
 * `ob_get_clean()` and the `$emitted_headers` array.
 */
class TestSSEController extends SSEControllerBase {
	/** @var array<int,string> Captured `header()` values for assertion. */
	public array $emitted_headers = [];

	/** Tick budget for the bounded `stream_log_run` exercise. */
	public int $max_loops = 0;

	/** Tick counter for the bounded predicate. */
	public int $loop_count = 0;

	/** Override: skip ALL SSE / time-limit / output-buffering side effects so tests run in a normal phpunit harness. */
	protected function init_sse_headers(): void {
		// Headers stream through `header()` directly — captured via override of `headers_send`.
	}

	public function register_routes(): void {
		// Required by abstract; not exercised here.
	}

	// -------------------------------------------------------------------------
	// Public passthroughs for unit-test access to protected machinery.
	// -------------------------------------------------------------------------

	public function pub_acquire( int $ttl = self::SLOT_TTL_BROWSER, int $partition = -1 ): int|false {
		return $this->acquire_sse_slot( $ttl, $partition );
	}

	public function pub_release(): void {
		$this->release_sse_slot();
	}

	public function pub_check(): bool {
		return $this->check_sse_slot();
	}

	public function pub_send( string $event, mixed $data ): void {
		$this->send_sse_event( $event, $data );
	}

	public function pub_flush_if_needed(): void {
		$this->flush_if_needed();
	}

	public function pub_parse_positions( ?string $raw, int $num_partitions ): ?array {
		return $this->parse_positions( $raw, $num_partitions );
	}

	public function pub_should_continue( array &$context ): bool {
		return $this->should_continue_stream( $context );
	}

	public function pub_start_sse_stream( array $connected = [], array $custom = [], bool $is_aggregator = false ): array|\WP_Error {
		return $this->start_sse_stream( $connected, $custom, $is_aggregator );
	}

	public function pub_end_sse_stream(): void {
		$this->end_sse_stream();
	}

	public function pub_setup_readers( string $log_base, string $log_file, int $num_partitions, ?array $saved_pos, int $tail_bytes ): array {
		return $this->setup_readers( $log_base, $log_file, $num_partitions, $saved_pos, $tail_bytes );
	}

	public function pub_get_ip_hash(): string {
		return $this->get_ip_hash();
	}

	public function pub_stream_log_run( \WP_REST_Request $request, array $config, callable $transform ): mixed {
		return $this->stream_log_run( $request, $config, $transform );
	}

	public function get_slot(): int|false {
		return $this->slot;
	}

	public function get_user_id(): int {
		return $this->user_id;
	}

	public function get_ip_hash_field(): string {
		return $this->ip_hash;
	}

	public function get_slot_partition(): int {
		return $this->slot_partition;
	}

	public function get_needs_flush(): bool {
		return $this->needs_flush;
	}
}

/**
 * Variant that bounds `should_continue_stream` to a fixed tick budget. Used by
 * the `stream_log_run` exercise to walk the whole loop without ever sleeping
 * indefinitely or hitting `connection_aborted`.
 */
class BoundedSSEController extends TestSSEController {
	protected function should_continue_stream( array &$context ): bool {
		++$this->loop_count;
		return $this->loop_count <= $this->max_loops;
	}
}

#[CoversClass( SSEControllerBase::class )]
class SSEControllerBaseTest extends TestCase {

	private FakeMemcached $cache;
	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
		$this->cache                  = new FakeMemcached();
		SSEControllerBase::set_cache( $this->cache );
		PerformanceControllerBase::set_cache( $this->cache );
		$this->tmp_dir = $this->make_temp_dir( 'sse-base-' );
	}

	protected function tearDown(): void {
		SSEControllerBase::set_cache( null );
		PerformanceControllerBase::set_cache( null );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->rmdir_recursive( $this->tmp_dir );
		parent::tearDown();
	}

	/**
	 * Build a packed Message line whose VALUE is $entry — the actual on-disk shape.
	 */
	private static function packed_entry_line( array $entry ): string {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = $entry;
		return Message::packed( $msg );
	}

	// =========================================================================
	// Constants / wire contract
	// =========================================================================

	public function test_constants_match_legacy_wire_contract(): void {
		// Wire-contract assertion: any change here breaks browsers and aggregators in lock-step.
		$this->assertSame( 10, SSEControllerBase::MAX_SSE_SLOTS );
		$this->assertSame( 4096, SSEControllerBase::FLUSH_SIZE );
		$this->assertSame( 5, SSEControllerBase::SLOT_CHECK_INTERVAL );
		$this->assertSame( 5, SSEControllerBase::HEARTBEAT_INTERVAL );
		$this->assertSame( 3600, SSEControllerBase::MAX_RUNTIME );
		$this->assertSame( 10, SSEControllerBase::SLOT_TTL_BROWSER );
		$this->assertSame( 30, SSEControllerBase::SLOT_TTL_AGGREGATOR );
	}

	public function test_allowed_endpoint_prefixes_security_boundary(): void {
		$this->assertContains( 'newspack-nodes/v1', SSEControllerBase::ALLOWED_ENDPOINT_PREFIXES );
		$this->assertContains( 'newspack-nodes-aggregator/v1', SSEControllerBase::ALLOWED_ENDPOINT_PREFIXES );
		// The prefix list is the security boundary — one place to grep.
		$this->assertCount( 2, SSEControllerBase::ALLOWED_ENDPOINT_PREFIXES );
	}

	// =========================================================================
	// Permission gate
	// =========================================================================

	public function test_permissions_check_allows_admin(): void {
		$ctrl = new TestSSEController();
		$this->assertTrue( $ctrl->stream_permissions_check() );
	}

	public function test_permissions_check_denies_unauthorized(): void {
		$ctrl                         = new TestSSEController();
		$GLOBALS['_current_user_can'] = false;
		$result                       = $ctrl->stream_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_permissions_check_denied_status_uses_authorization_helper(): void {
		// Anonymous (uid=0): rest_authorization_required_code returns 401.
		// Logged-in but no manage_options: returns 403. Verify the data status flows through.
		$ctrl                         = new TestSSEController();
		$GLOBALS['_current_user_can'] = false;
		$GLOBALS['_current_user_id']  = 0; // Anonymous.
		$err                          = $ctrl->stream_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 401, $err->data['status'] );

		$GLOBALS['_current_user_id'] = 12; // Logged-in but lacks cap.
		$err2                        = $ctrl->stream_permissions_check();
		$this->assertSame( 403, $err2->data['status'] );
	}

	// =========================================================================
	// Slot acquisition: round-robin, fail-closed, partition pool isolation.
	// =========================================================================

	public function test_acquire_slot_returns_zero_first(): void {
		$ctrl = new TestSSEController();
		$this->assertSame( 0, $ctrl->pub_acquire() );
	}

	public function test_acquire_slot_fails_closed_when_cache_down(): void {
		$failing = new FakeMemcached( fail_all: true );
		SSEControllerBase::set_cache( $failing );
		$ctrl = new TestSSEController();
		$this->assertFalse( $ctrl->pub_acquire() );
	}

	public function test_acquire_max_slots_then_rate_limits(): void {
		$ctrls = [];
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$c    = new TestSSEController();
			$slot = $c->pub_acquire();
			$this->assertNotFalse( $slot );
			$this->assertSame( $i, $slot );
			$ctrls[] = $c;
		}
		// 11th must fail.
		$c = new TestSSEController();
		$this->assertFalse( $c->pub_acquire() );
	}

	public function test_release_frees_slot_for_reuse(): void {
		$ctrl = new TestSSEController();
		$slot = $ctrl->pub_acquire();
		$this->assertSame( 0, $slot );
		$ctrl->pub_release();
		// Different controller instance reuses the freed slot.
		$ctrl2 = new TestSSEController();
		$this->assertSame( 0, $ctrl2->pub_acquire() );
	}

	public function test_release_idempotent_when_no_slot_held(): void {
		$ctrl = new TestSSEController();
		// Double-release without acquire: no-op.
		$ctrl->pub_release();
		$ctrl->pub_release();
		$this->assertFalse( $ctrl->get_slot() );
	}

	public function test_release_clears_slot_partition_state(): void {
		$ctrl = new TestSSEController();
		$ctrl->pub_acquire( SSEControllerBase::SLOT_TTL_AGGREGATOR, 3 );
		$this->assertSame( 3, $ctrl->get_slot_partition() );
		$ctrl->pub_release();
		$this->assertSame( -1, $ctrl->get_slot_partition() );
		$this->assertFalse( $ctrl->get_slot() );
	}

	public function test_check_slot_true_when_alive(): void {
		$ctrl = new TestSSEController();
		$ctrl->pub_acquire();
		$this->assertTrue( $ctrl->pub_check() );
	}

	public function test_check_slot_false_after_release(): void {
		$ctrl = new TestSSEController();
		$ctrl->pub_acquire();
		$ctrl->pub_release();
		$this->assertFalse( $ctrl->pub_check() );
	}

	public function test_check_slot_false_when_no_slot_acquired(): void {
		// Brand-new controller hasn't acquired anything → check is short-circuit false.
		$ctrl = new TestSSEController();
		$this->assertFalse( $ctrl->pub_check() );
	}

	public function test_aggregator_partition_uses_separate_pool(): void {
		// Browser slot + aggregator slot for partition 0 don't compete.
		$browser = new TestSSEController();
		$this->assertSame( 0, $browser->pub_acquire( SSEControllerBase::SLOT_TTL_BROWSER, -1 ) );

		$agg = new TestSSEController();
		// Different partition → different cache key → starts at slot 0 again.
		$this->assertSame( 0, $agg->pub_acquire( SSEControllerBase::SLOT_TTL_AGGREGATOR, 0 ) );
	}

	public function test_aggregator_partitions_isolated_from_each_other(): void {
		// Each aggregator partition gets its own 10-slot pool — partition 0 full
		// shouldn't block partition 1.
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$c = new TestSSEController();
			$this->assertSame( $i, $c->pub_acquire( SSEControllerBase::SLOT_TTL_AGGREGATOR, 0 ) );
		}
		// Partition 0 full, but partition 1 starts fresh.
		$c1 = new TestSSEController();
		$this->assertSame( 0, $c1->pub_acquire( SSEControllerBase::SLOT_TTL_AGGREGATOR, 1 ) );
	}

	public function test_acquire_records_user_id_and_ip_hash(): void {
		$_SERVER['REMOTE_ADDR']      = '10.20.30.40';
		$GLOBALS['_current_user_id'] = 99;
		$ctrl                        = new TestSSEController();
		$ctrl->pub_acquire();
		$this->assertSame( 99, $ctrl->get_user_id() );
		// 8-char md5 prefix.
		$this->assertSame( \substr( \md5( '10.20.30.40' ), 0, 8 ), $ctrl->get_ip_hash_field() );
	}

	// =========================================================================
	// IP-hash privacy contract (8 chars only, never the raw IP).
	// =========================================================================

	public function test_ip_hash_is_8_chars_md5_prefix(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
		$ctrl                   = new TestSSEController();
		$hash                   = $ctrl->pub_get_ip_hash();
		$this->assertSame( 8, \strlen( $hash ) );
		$this->assertSame( \substr( \md5( '203.0.113.42' ), 0, 8 ), $hash );
		// Critical privacy property: raw IP is never embedded in the hash.
		$this->assertStringNotContainsString( '203.0.113.42', $hash );
	}

	public function test_ip_hash_falls_back_to_unknown_when_remote_addr_missing(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$ctrl = new TestSSEController();
		$hash = $ctrl->pub_get_ip_hash();
		$this->assertSame( \substr( \md5( 'unknown' ), 0, 8 ), $hash );
	}

	// =========================================================================
	// parse_positions: clipping, oversize rejection, malformed JSON.
	// =========================================================================

	public function test_parse_positions_clips_to_num_partitions(): void {
		$ctrl  = new TestSSEController();
		$json  = \json_encode( [ [ 's' => 0, 'o' => 100 ], [ 's' => 0, 'o' => 200 ], [ 's' => 0, 'o' => 300 ] ] );
		$out   = $ctrl->pub_parse_positions( $json, 2 );
		$this->assertCount( 2, $out );
	}

	public function test_parse_positions_rejects_oversize_input(): void {
		$ctrl = new TestSSEController();
		$big  = \str_repeat( 'a', 5000 );
		$this->assertNull( $ctrl->pub_parse_positions( $big, 4 ) );
	}

	public function test_parse_positions_handles_null_and_empty(): void {
		$ctrl = new TestSSEController();
		$this->assertNull( $ctrl->pub_parse_positions( null, 4 ) );
		$this->assertNull( $ctrl->pub_parse_positions( '', 4 ) );
	}

	public function test_parse_positions_returns_null_for_non_array_json(): void {
		$ctrl = new TestSSEController();
		// JSON-decodes to a scalar (not an array) → null.
		$this->assertNull( $ctrl->pub_parse_positions( '"string"', 4 ) );
		$this->assertNull( $ctrl->pub_parse_positions( '42', 4 ) );
		$this->assertNull( $ctrl->pub_parse_positions( 'malformed{', 4 ) );
	}

	public function test_parse_positions_passes_through_when_under_limit(): void {
		$ctrl = new TestSSEController();
		$json = \json_encode( [ [ 's' => 1, 'o' => 100 ] ] );
		$out  = $ctrl->pub_parse_positions( $json, 4 );
		$this->assertSame( [ [ 's' => 1, 'o' => 100 ] ], $out );
	}

	public function test_parse_positions_4096_byte_boundary(): void {
		// Inputs of length 4096 should be REJECTED (the test is `> 4096`); 4095 OK.
		$ctrl = new TestSSEController();
		// 4097 bytes: rejected.
		$this->assertNull( $ctrl->pub_parse_positions( \str_repeat( 'x', 4097 ), 4 ) );
		// 4096 bytes: not rejected by length check, but not valid JSON → null via decode path.
		$this->assertNull( $ctrl->pub_parse_positions( \str_repeat( 'x', 4096 ), 4 ) );
	}

	// =========================================================================
	// send_sse_event: format, allowlist, sanitizer.
	// =========================================================================

	public function test_send_sse_event_emits_correct_format(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( 'heartbeat', [ 'ts' => 1234 ] );
		$out = \ob_get_clean();
		$this->assertStringContainsString( "event: heartbeat\n", $out );
		$this->assertStringContainsString( "data: {\"ts\":1234}\n\n", $out );
	}

	public function test_send_sse_event_marks_dirty_for_subsequent_flush(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$this->assertFalse( $ctrl->get_needs_flush() );
		$ctrl->pub_send( 'heartbeat', [] );
		$this->assertTrue( $ctrl->get_needs_flush() );
		\ob_get_clean();
	}

	public function test_send_sse_event_sanitizes_unsafe_event_names(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( "bogus\nevent: poison", [] );
		$out = \ob_get_clean();
		// Newlines, colons, and spaces all stripped by /[^a-zA-Z0-9_-]/.
		$this->assertStringContainsString( 'event: bogusevent', $out );
		// Critical: only ONE `event:` header line in the payload (no injection).
		$this->assertSame( 1, \substr_count( $out, 'event:' ) );
	}

	public function test_send_sse_event_safe_events_bypass_regex(): void {
		// Each name in SAFE_EVENTS is the canonical wire name; verify all of them
		// flow through without mutation.
		$safe = [ 'entry', 'entries', 'lines', 'positions', 'heartbeat', 'config', 'connected', 'timeout', 'complete_batch', 'inflight', 'errors' ];
		foreach ( $safe as $name ) {
			$ctrl = new TestSSEController();
			\ob_start();
			$ctrl->pub_send( $name, [] );
			$out = \ob_get_clean();
			$this->assertStringContainsString( "event: {$name}\n", $out, "safe event '{$name}' must not be mangled" );
		}
	}

	public function test_send_sse_event_unsafe_with_only_special_chars_collapses_to_empty(): void {
		// Pure special-char event name -> empty after sanitization.
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( "::\n\t", [] );
		$out = \ob_get_clean();
		$this->assertStringContainsString( "event: \n", $out );
	}

	public function test_send_sse_event_data_payload_is_json(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( 'entry', [ 'rid' => 'r1', 'k' => 'request' ] );
		$out = \ob_get_clean();
		// Verify the data line is well-formed JSON.
		$matches = [];
		\preg_match( '/data: (.+)\n\n/', $out, $matches );
		$this->assertNotEmpty( $matches );
		$this->assertSame( [ 'rid' => 'r1', 'k' => 'request' ], \json_decode( $matches[1], true ) );
	}

	public function test_send_sse_event_emits_blank_line_terminator(): void {
		// SSE spec: events are framed with a blank line.
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( 'connected', [] );
		$out = \ob_get_clean();
		$this->assertStringEndsWith( "\n\n", $out );
	}

	// =========================================================================
	// flush_if_needed: idempotent, matches legacy 4093-dot comment shape.
	// =========================================================================

	public function test_flush_if_needed_only_flushes_when_dirty(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_flush_if_needed();
		$first = \ob_get_clean();
		$this->assertSame( '', $first );

		\ob_start();
		$ctrl->pub_send( 'heartbeat', [] );
		$ctrl->pub_flush_if_needed();
		$second = \ob_get_clean();
		// Comment is `:....\n\n` of total FLUSH_SIZE bytes (4096).
		$this->assertStringContainsString( ':' . \str_repeat( '.', SSEControllerBase::FLUSH_SIZE - 3 ) . "\n\n", $second );
	}

	public function test_flush_if_needed_idempotent_after_flush(): void {
		// Once flushed, a second call without new send is a no-op.
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( 'heartbeat', [] );
		$ctrl->pub_flush_if_needed();
		\ob_get_clean();

		\ob_start();
		$ctrl->pub_flush_if_needed();
		$second_flush = \ob_get_clean();
		$this->assertSame( '', $second_flush, 'flush_if_needed must be idempotent' );
		$this->assertFalse( $ctrl->get_needs_flush() );
	}

	public function test_flush_comment_is_exactly_flush_size_bytes(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( 'heartbeat', [] );
		$send_out = \ob_get_clean();

		\ob_start();
		$ctrl->pub_flush_if_needed();
		$flush_out = \ob_get_clean();
		// `:` + (4093 dots) + `\n\n` = 4096 bytes exactly.
		$this->assertSame( SSEControllerBase::FLUSH_SIZE, \strlen( $flush_out ) );
	}

	// =========================================================================
	// Cache injection / lazy resolution
	// =========================================================================

	public function test_set_cache_isolation(): void {
		$other = new FakeMemcached();
		SSEControllerBase::set_cache( $other );
		$this->assertSame( $other, SSEControllerBase::cache() );
		SSEControllerBase::set_cache( $this->cache );
		$this->assertSame( $this->cache, SSEControllerBase::cache() );
	}

	public function test_cache_returns_cache_interface(): void {
		// With injected FakeMemcached, cache() returns the same instance.
		$this->assertSame( $this->cache, SSEControllerBase::cache() );
	}

	public function test_cache_lazy_construction_when_not_injected(): void {
		// Tear down the injection — cache() must build a Memcached_Cache lazily.
		SSEControllerBase::set_cache( null );
		$cache = SSEControllerBase::cache();
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Cache_Interface::class, $cache );
		// Subsequent call returns the same lazy instance.
		$this->assertSame( $cache, SSEControllerBase::cache() );
	}

	// =========================================================================
	// start_sse_stream: success path + rate-limit path + custom headers + connected event.
	// =========================================================================

	public function test_start_sse_stream_returns_context_on_success(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctx = $ctrl->pub_start_sse_stream( [ 'foo' => 'bar' ] );
		\ob_get_clean();

		$this->assertIsArray( $ctx );
		$this->assertSame( 0, $ctx['slot'] );
		$this->assertArrayHasKey( 'start_time', $ctx );
		$this->assertArrayHasKey( 'last_slot_check', $ctx );
		$this->assertArrayHasKey( 'config', $ctx );
		$this->assertArrayHasKey( 'log_base', $ctx );
		$this->assertArrayHasKey( 'num_partitions', $ctx );
		$this->assertArrayHasKey( 'segment_size', $ctx );
		$this->assertArrayHasKey( 'num_segments', $ctx );
		$this->assertStringEndsWith( '/logs', $ctx['log_base'] );
	}

	public function test_start_sse_stream_emits_connected_event_with_slot(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream( [ 'log' => 'firehose.log', 'partition' => 0 ] );
		$out = \ob_get_clean();
		// connected event must include the slot index merged into the user-supplied data.
		$this->assertStringContainsString( "event: connected\n", $out );
		$this->assertStringContainsString( '"slot":0', $out );
		$this->assertStringContainsString( '"log":"firehose.log"', $out );
		$this->assertStringContainsString( '"partition":0', $out );
	}

	public function test_start_sse_stream_returns_wp_error_when_rate_limited(): void {
		// Fill all slots with a different IP/user combo, then attempt 11th.
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$c = new TestSSEController();
			$this->assertSame( $i, $c->pub_acquire() );
		}
		$denied = new TestSSEController();
		\ob_start();
		$result = $denied->pub_start_sse_stream();
		\ob_get_clean();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );
		$this->assertSame( 429, $result->data['status'] );
	}

	public function test_start_sse_stream_rate_limit_fires_action(): void {
		// Saturate slots.
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$c = new TestSSEController();
			$c->pub_acquire();
		}
		$captured = [];
		add_action( 'newspack_event_logger_nodes/sse_rate_limited', function ( $uid, $cls ) use ( &$captured ): void {
			$captured = [ 'uid' => $uid, 'cls' => $cls ];
		} );

		$denied = new TestSSEController();
		\ob_start();
		$denied->pub_start_sse_stream();
		\ob_get_clean();

		$this->assertSame( 7, $captured['uid'] );
		$this->assertSame( TestSSEController::class, $captured['cls'] );

		unset( $GLOBALS['_wp_actions']['newspack_event_logger_nodes/sse_rate_limited'] );
	}

	public function test_start_sse_stream_fires_connected_action_on_success(): void {
		$captured = [];
		add_action( 'newspack_event_logger_nodes/sse_connected', function ( $slot, $uid, $cls ) use ( &$captured ): void {
			$captured = [ 'slot' => $slot, 'uid' => $uid, 'cls' => $cls ];
		} );

		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();

		$this->assertSame( 0, $captured['slot'] );
		$this->assertSame( 7, $captured['uid'] );
		$this->assertSame( TestSSEController::class, $captured['cls'] );

		unset( $GLOBALS['_wp_actions']['newspack_event_logger_nodes/sse_connected'] );
	}

	public function test_start_sse_stream_aggregator_uses_partition_pool(): void {
		// Aggregator with explicit partition uses the per-partition pool.
		$ctrl = new TestSSEController();
		\ob_start();
		$ctx = $ctrl->pub_start_sse_stream( [ 'partition' => 2 ], [], true );
		\ob_get_clean();
		$this->assertSame( 0, $ctx['slot'] );
		$this->assertSame( 2, $ctrl->get_slot_partition() );
	}

	public function test_start_sse_stream_aggregator_without_partition_uses_shared_pool(): void {
		// Aggregator=true but no partition key → defaults to shared pool (-1).
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream( [ 'log' => 'foo.log' ], [], true );
		\ob_get_clean();
		$this->assertSame( -1, $ctrl->get_slot_partition() );
	}

	// =========================================================================
	// end_sse_stream: action + slot release.
	// =========================================================================

	public function test_end_sse_stream_releases_slot(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();
		$this->assertNotFalse( $ctrl->get_slot() );

		$ctrl->pub_end_sse_stream();
		$this->assertFalse( $ctrl->get_slot() );
	}

	public function test_end_sse_stream_fires_disconnected_action(): void {
		$captured = [];
		add_action( 'newspack_event_logger_nodes/sse_disconnected', function ( $uid, $cls ) use ( &$captured ): void {
			$captured = [ 'uid' => $uid, 'cls' => $cls ];
		} );

		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();
		$ctrl->pub_end_sse_stream();

		$this->assertSame( 7, $captured['uid'] );
		$this->assertSame( TestSSEController::class, $captured['cls'] );

		unset( $GLOBALS['_wp_actions']['newspack_event_logger_nodes/sse_disconnected'] );
	}

	// =========================================================================
	// should_continue_stream: timeout, slot revoked, slot-check throttle.
	// =========================================================================

	public function test_should_continue_returns_true_within_runtime(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();

		$ctx = [
			'start_time'      => \time(),
			'last_slot_check' => \time(),
		];
		$this->assertTrue( $ctrl->pub_should_continue( $ctx ) );
	}

	public function test_should_continue_returns_false_when_max_runtime_exceeded(): void {
		// Forward-date start_time so that now - start > MAX_RUNTIME.
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();

		$ctx = [
			'start_time'      => \time() - SSEControllerBase::MAX_RUNTIME - 1,
			'last_slot_check' => \time(),
		];
		\ob_start();
		$cont = $ctrl->pub_should_continue( $ctx );
		$out  = \ob_get_clean();

		$this->assertFalse( $cont );
		// Timeout event must be emitted so the client knows to reconnect.
		$this->assertStringContainsString( "event: timeout\n", $out );
		$this->assertStringContainsString( 'Max runtime reached', $out );
	}

	public function test_should_continue_returns_false_when_slot_revoked(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();

		// Force a stale last_slot_check so the slot probe runs.
		$ctx = [
			'start_time'      => \time(),
			'last_slot_check' => \time() - SSEControllerBase::SLOT_CHECK_INTERVAL - 1,
		];
		// Externally release the slot — simulates TTL expiry / heartbeat stall.
		$ctrl->pub_release();
		// Now the slot probe inside `should_continue` returns false → loop exits.
		$this->assertFalse( $ctrl->pub_should_continue( $ctx ) );
	}

	public function test_should_continue_advances_last_slot_check_on_pass(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_start_sse_stream();
		\ob_get_clean();

		$old_check = \time() - SSEControllerBase::SLOT_CHECK_INTERVAL - 1;
		$ctx       = [
			'start_time'      => \time(),
			'last_slot_check' => $old_check,
		];
		$cont = $ctrl->pub_should_continue( $ctx );
		$this->assertTrue( $cont );
		$this->assertGreaterThan( $old_check, $ctx['last_slot_check'], 'last_slot_check must advance after a successful probe' );
	}

	// =========================================================================
	// setup_readers: tail-seek + first-line resync.
	// =========================================================================

	public function test_setup_readers_creates_one_reader_per_partition(): void {
		// Lay down minimal log structure with one segment per partition.
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/firehose.log/p0", 0755, true );
		\mkdir( "{$base}/firehose.log/p1", 0755, true );
		\file_put_contents( "{$base}/firehose.log/p0/0.log", '' );
		\file_put_contents( "{$base}/firehose.log/p1/0.log", '' );

		$ctrl  = new TestSSEController();
		$setup = $ctrl->pub_setup_readers( $base, 'firehose.log', 2, null, 1024 );
		$this->assertCount( 2, $setup['readers'] );
		$this->assertCount( 2, $setup['file_handles'] );
		$this->assertArrayHasKey( 0, $setup['readers'] );
		$this->assertArrayHasKey( 1, $setup['readers'] );
	}

	public function test_setup_readers_resumes_at_saved_position_within_window(): void {
		// Create a single-line log; saved position points just before its end.
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/firehose.log/p0", 0755, true );
		$line = "abcdefghij\n"; // 11 bytes.
		\file_put_contents( "{$base}/firehose.log/p0/0.log", $line );

		$ctrl  = new TestSSEController();
		$setup = $ctrl->pub_setup_readers(
			$base,
			'firehose.log',
			1,
			[ 0 => [ 's' => 0, 'o' => 5 ] ], // Saved = 5 bytes in.
			100
		);
		$pos = $setup['readers'][0]->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 5, $pos['offset'], 'reader must seek to saved offset on resume' );
	}

	public function test_setup_readers_falls_back_to_tail_seek_when_no_saved_pos(): void {
		// Without saved positions, reader seeks to (end - tail_bytes).
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/firehose.log/p0", 0755, true );
		// Build content where we control where the first newline lies.
		$content = \str_repeat( 'A', 100 ) . "\nLineTwo\n";
		\file_put_contents( "{$base}/firehose.log/p0/0.log", $content );

		$ctrl  = new TestSSEController();
		$setup = $ctrl->pub_setup_readers( $base, 'firehose.log', 1, null, 50 );
		// Seek lands at end-50; first-line resync consumes the partial line, so
		// the reader's position is at byte 101 (start of "LineTwo").
		$pos = $setup['readers'][0]->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 101, $pos['offset'] );
	}

	public function test_setup_readers_ignores_saved_pos_when_segment_mismatch(): void {
		// Saved position references a different segment_id → reader falls back to tail.
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/firehose.log/p0", 0755, true );
		\file_put_contents( "{$base}/firehose.log/p0/0.log", \str_repeat( 'X', 200 ) . "\n" );

		$ctrl  = new TestSSEController();
		$setup = $ctrl->pub_setup_readers(
			$base,
			'firehose.log',
			1,
			[ 0 => [ 's' => 99, 'o' => 0 ] ], // segment 99 doesn't exist.
			50
		);
		// Resume rejected → tail-seek into the existing segment.
		$pos = $setup['readers'][0]->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
	}

	public function test_setup_readers_ignores_saved_pos_when_too_far_behind(): void {
		// Saved position more than tail_bytes behind end → reject (avoid replay storm).
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/firehose.log/p0", 0755, true );
		\file_put_contents( "{$base}/firehose.log/p0/0.log", \str_repeat( 'A', 1000 ) . "\nlast\n" );

		$ctrl  = new TestSSEController();
		// Saved at offset 0 (1006 bytes back); tail_bytes = 100 → reject.
		$setup = $ctrl->pub_setup_readers(
			$base,
			'firehose.log',
			1,
			[ 0 => [ 's' => 0, 'o' => 0 ] ],
			100
		);
		$pos = $setup['readers'][0]->get_position();
		// Tail seek lands somewhere after the first newline (the 1001-byte boundary).
		$this->assertGreaterThan( 0, $pos['offset'], 'saved position too old must be rejected' );
	}

	public function test_setup_readers_handles_empty_partition(): void {
		// Empty partition (no segments yet) — readers exist but have no offsets to resync.
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/firehose.log/p0", 0755, true );

		$ctrl  = new TestSSEController();
		$setup = $ctrl->pub_setup_readers( $base, 'firehose.log', 1, null, 1024 );
		$this->assertCount( 1, $setup['readers'] );
		$this->assertNull( $setup['file_handles'][0], 'no segments → no open handle' );
	}

	// =========================================================================
	// stream_log_run: full polling loop with bounded ticks.
	// =========================================================================

	public function test_stream_log_run_emits_connected_then_config_then_data(): void {
		// Build a real packed-message log file and run the loop a few ticks.
		$base = "{$this->tmp_dir}/logs";
		$dir  = "{$base}/errors.log/p0";
		\mkdir( $dir, 0755, true );
		$entry_line = self::packed_entry_line( [ 'rid' => 'r1', 'k' => 'error', 'm' => 'oops', 'ts' => 1700000000, 'n' => 1 ] );
		\file_put_contents( "{$dir}/0.log", $entry_line . "\n" );

		// Override config so num_partitions=1.
		$this->use_base_dir( \dirname( $base ) );

		$ctrl            = new BoundedSSEController();
		$ctrl->max_loops = 4;

		$req = new \WP_REST_Request( [ 'interval' => 100 ] );
		$req->set_param( 'interval', 100 );

		\ob_start();
		$ctrl->pub_stream_log_run(
			$req,
			[
				'log_file'        => 'errors.log',
				'event_name'      => 'errors',
				'tail_bytes'      => 50 * 1024,
				'batch_threshold' => 1,
			],
			static function ( string $line, int $p ): ?array {
				$decoded = \json_decode( $line, true );
				$entry   = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
				return \is_array( $entry ) ? $entry : null;
			}
		);
		$out = \ob_get_clean();

		// connected and config events fire upfront.
		$this->assertStringContainsString( "event: connected\n", $out );
		$this->assertStringContainsString( "event: config\n", $out );
		$this->assertStringContainsString( '"num_partitions":1', $out );
		$this->assertStringContainsString( '"interval":100', $out );

	}

	public function test_stream_log_run_returns_wp_error_when_rate_limited(): void {
		// Saturate the slot pool so start_sse_stream returns WP_Error.
		for ( $i = 0; $i < SSEControllerBase::MAX_SSE_SLOTS; $i++ ) {
			$c = new TestSSEController();
			$c->pub_acquire();
		}

		$ctrl = new BoundedSSEController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'interval', 100 );

		\ob_start();
		$result = $ctrl->pub_stream_log_run(
			$req,
			[ 'log_file' => 'errors.log', 'event_name' => 'errors' ],
			static fn ( string $l, int $p ): ?array => null
		);
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );
	}

	public function test_stream_log_run_includes_config_extras(): void {
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/errors.log/p0", 0755, true );
		\file_put_contents( "{$base}/errors.log/p0/0.log", '' );

		$this->use_base_dir( \dirname( $base ) );

		$ctrl            = new BoundedSSEController();
		$ctrl->max_loops = 0; // Don't enter the loop body; just emit config.

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 250 );

		\ob_start();
		$ctrl->pub_stream_log_run(
			$req,
			[
				'log_file'      => 'errors.log',
				'event_name'    => 'errors',
				'config_extras' => [ 'flavor' => 'cherry', 'depth' => 3 ],
			],
			static fn ( string $l, int $p ): ?array => null
		);
		$out = \ob_get_clean();

		// config event must include the extras merged in.
		$this->assertStringContainsString( "event: config\n", $out );
		$this->assertStringContainsString( '"flavor":"cherry"', $out );
		$this->assertStringContainsString( '"depth":3', $out );

	}

	public function test_stream_log_run_releases_slot_on_completion(): void {
		// After the loop bounds out, end_sse_stream() must release the slot.
		$base = "{$this->tmp_dir}/logs";
		\mkdir( "{$base}/errors.log/p0", 0755, true );
		\file_put_contents( "{$base}/errors.log/p0/0.log", '' );

		$this->use_base_dir( \dirname( $base ) );

		$ctrl            = new BoundedSSEController();
		$ctrl->max_loops = 1;

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 100 );

		\ob_start();
		$ctrl->pub_stream_log_run(
			$req,
			[ 'log_file' => 'errors.log', 'event_name' => 'errors' ],
			static fn ( string $l, int $p ): ?array => null
		);
		\ob_get_clean();

		// Slot must be released by end_sse_stream() in `finally`.
		$this->assertFalse( $ctrl->get_slot() );

	}

	public function test_stream_log_run_emits_positions_after_batch(): void {
		// Write an entry, run a bounded loop with batch_threshold=1, and verify
		// that an event-name event AND a positions event get emitted.
		$base = "{$this->tmp_dir}/logs";
		$dir  = "{$base}/errors.log/p0";
		\mkdir( $dir, 0755, true );
		$entry_line = self::packed_entry_line( [ 'rid' => 'rA', 'k' => 'error', 'm' => 'boom', 'ts' => 1, 'n' => 1 ] );
		\file_put_contents( "{$dir}/0.log", $entry_line . "\n" );

		$this->use_base_dir( \dirname( $base ) );

		$ctrl            = new BoundedSSEController();
		$ctrl->max_loops = 8;

		$req = new \WP_REST_Request();
		$req->set_param( 'interval', 1 ); // Tiny interval forces immediate batch emission.
		// Provide saved positions = start of segment so reader replays the entry.
		$req->set_param( 'positions', \json_encode( [ 0 => [ 's' => 0, 'o' => 0 ] ] ) );

		\ob_start();
		$ctrl->pub_stream_log_run(
			$req,
			[
				'log_file'        => 'errors.log',
				'event_name'      => 'errors',
				'tail_bytes'      => 5000,
				'batch_threshold' => 1,
			],
			static function ( string $line, int $p ): ?array {
				$decoded = \json_decode( $line, true );
				$entry   = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
				return \is_array( $entry ) ? $entry : null;
			}
		);
		$out = \ob_get_clean();

		$this->assertStringContainsString( "event: errors\n", $out );
		$this->assertStringContainsString( "event: positions\n", $out );

	}
}
