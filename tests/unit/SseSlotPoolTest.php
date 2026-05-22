<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Sse_Slot_Pool;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Nodes\Rest\SSE_Out;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * App-side wiring of the substrate's slot-pool seams.
 *
 * The substrate's `SSE_Out` exposes three static
 * Closure properties (acquire / release / check). The app side wires
 * them to its `Memcached_Cache` instance via `Sse_Slot_Pool::wire()`.
 *
 * Tests pin:
 *   * `wire()` populates all three closures (the substrate seams flip
 *     from null → Closure).
 *   * The acquire closure delegates to `Cache_Interface::acquire_sse_slot`
 *     with sensible defaults (per-partition TTL, max-slots constant).
 *   * Release / check delegate symmetrically.
 *
 * A `FakeMemcached` is wired in via `Sse_Slot_Pool::$cache` so the
 * closures stay deterministic without touching real memcache.
 */
#[CoversClass( Sse_Slot_Pool::class )]
class SseSlotPoolTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		SSE_Out::$acquire_slot = null;
		SSE_Out::$release_slot = null;
		SSE_Out::$check_slot   = null;
		Sse_Slot_Pool::$cache                     = null;
	}

	protected function tearDown(): void {
		SSE_Out::$acquire_slot = null;
		SSE_Out::$release_slot = null;
		SSE_Out::$check_slot   = null;
		Sse_Slot_Pool::$cache                     = null;
		parent::tearDown();
	}

	public function test_wire_populates_all_three_seams(): void {
		$this->assertNull( SSE_Out::$acquire_slot );
		$this->assertNull( SSE_Out::$release_slot );
		$this->assertNull( SSE_Out::$check_slot );

		Sse_Slot_Pool::wire();

		$this->assertInstanceOf( \Closure::class, SSE_Out::$acquire_slot );
		$this->assertInstanceOf( \Closure::class, SSE_Out::$release_slot );
		$this->assertInstanceOf( \Closure::class, SSE_Out::$check_slot );
	}

	public function test_acquire_delegates_to_cache_with_partition_arg(): void {
		Sse_Slot_Pool::$cache = new FakeMemcached();
		Sse_Slot_Pool::wire();

		$acquire = SSE_Out::$acquire_slot;
		$this->assertNotNull( $acquire );

		// First acquire on the shared browser pool succeeds (slot 0).
		$slot = $acquire( -1 );
		$this->assertSame( 0, $slot );
	}

	public function test_release_returns_slot_to_pool(): void {
		Sse_Slot_Pool::$cache = new FakeMemcached();
		Sse_Slot_Pool::wire();

		$acquire = SSE_Out::$acquire_slot;
		$release = SSE_Out::$release_slot;

		$slot = $acquire( -1 );
		$this->assertSame( 0, $slot );

		// After release, the same slot index should be re-acquirable.
		$release( $slot, -1 );
		$reacquired = $acquire( -1 );
		$this->assertSame( 0, $reacquired );
	}

	public function test_check_returns_true_for_held_slot(): void {
		Sse_Slot_Pool::$cache = new FakeMemcached();
		Sse_Slot_Pool::wire();

		$acquire = SSE_Out::$acquire_slot;
		$check   = SSE_Out::$check_slot;

		$slot = $acquire( -1 );
		$this->assertTrue( $check( $slot, -1 ) );
	}

	public function test_check_never_refreshes_ttl_only_checks(): void {
		// INVARIANT: the SSE slot TTL is refreshed EXCLUSIVELY by the client's
		// periodic `workers/heartbeat` poke. The substrate calls `$check_slot`
		// once per drain iteration, but it must NEVER touch the TTL — a
		// stream's own drain loop is not proof the browser is still alive, and
		// refresh-on-check would let a zombie/abandoned connection hold a slot
		// forever, defeating the slot pool's rate-limit invariant. `$check_slot`
		// only ASKS whether the slot is still ours; when the client stops
		// heart-beating the TTL lapses and this returns false, terminating the
		// stream.
		$recorder = new class extends FakeMemcached {
			public int $touch_calls = 0;
			public int $check_calls = 0;
			public function touch_sse_slot( int $user_id, string $ip_hash, int $slot, int $ttl = 10, int $partition = -1 ): bool {
				$this->touch_calls++;
				return parent::touch_sse_slot( $user_id, $ip_hash, $slot, $ttl, $partition );
			}
			public function check_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
				$this->check_calls++;
				return parent::check_sse_slot( $user_id, $ip_hash, $slot, $partition );
			}
		};
		Sse_Slot_Pool::$cache = $recorder;
		Sse_Slot_Pool::wire();

		$acquire = SSE_Out::$acquire_slot;
		$check   = SSE_Out::$check_slot;

		$slot = $acquire( -1 );
		$this->assertNotFalse( $slot );

		$this->assertTrue( $check( $slot, -1 ) );
		$this->assertSame(
			0,
			$recorder->touch_calls,
			'check_slot must NEVER refresh the TTL — only the client heartbeat may'
		);
		$this->assertSame(
			1,
			$recorder->check_calls,
			'check_slot must check (not touch) whether the slot is still held'
		);
	}

	public function test_acquire_returns_false_when_pool_exhausted(): void {
		Sse_Slot_Pool::$cache     = new FakeMemcached();
		Sse_Slot_Pool::$max_slots = 2;
		Sse_Slot_Pool::wire();

		$acquire = SSE_Out::$acquire_slot;

		// Fill the pool: two acquires succeed, the third hits the cap.
		$this->assertNotFalse( $acquire( -1 ) );
		$this->assertNotFalse( $acquire( -1 ) );
		$this->assertFalse( $acquire( -1 ) );
	}
}
