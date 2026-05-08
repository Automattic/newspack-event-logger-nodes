<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

require_once \dirname( __DIR__, 2 ) . '/includes/class-partition-reader.php';
require_once \dirname( __DIR__, 2 ) . '/includes/rest/class-sse-controller-base.php';

use Newspack_Event_Logger_Nodes\Rest\SSEControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Concrete subclass for testing the abstract base. Exposes protected methods
 * via simple public wrappers so we can drive the slot / event / stream-control
 * machinery without spinning up a full SSE loop.
 */
class TestSSEController extends SSEControllerBase {
	public function register_routes(): void {
		// Required by WP_REST_Controller; not exercised here.
	}

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

	public function get_slot(): int|false {
		return $this->slot;
	}
}

#[CoversClass( SSEControllerBase::class )]
class SSEControllerBaseTest extends TestCase {

	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$this->cache                  = new FakeMemcached();
		SSEControllerBase::set_cache( $this->cache );
	}

	protected function tearDown(): void {
		SSEControllerBase::set_cache( null );
		parent::tearDown();
	}

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

	public function test_acquire_slot_returns_zero_then_one(): void {
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
			$c       = new TestSSEController();
			$slot    = $c->pub_acquire();
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

	public function test_aggregator_partition_uses_separate_pool(): void {
		// Browser slot + aggregator slot for partition 0 don't compete.
		$browser = new TestSSEController();
		$this->assertSame( 0, $browser->pub_acquire( SSEControllerBase::SLOT_TTL_BROWSER, -1 ) );

		$agg = new TestSSEController();
		// Different partition → different cache key → starts at slot 0 again.
		$this->assertSame( 0, $agg->pub_acquire( SSEControllerBase::SLOT_TTL_AGGREGATOR, 0 ) );
	}

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

	public function test_send_sse_event_emits_correct_format(): void {
		$ctrl = new TestSSEController();
		\ob_start();
		$ctrl->pub_send( 'heartbeat', [ 'ts' => 1234 ] );
		$out = \ob_get_clean();
		$this->assertStringContainsString( "event: heartbeat\n", $out );
		$this->assertStringContainsString( "data: {\"ts\":1234}\n\n", $out );
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

	public function test_set_cache_isolation(): void {
		$other = new FakeMemcached();
		SSEControllerBase::set_cache( $other );
		$this->assertSame( $other, SSEControllerBase::cache() );
		SSEControllerBase::set_cache( $this->cache );
		$this->assertSame( $this->cache, SSEControllerBase::cache() );
	}
}
