<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Sse_Slot_Pool;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Nodes\Rest\Messages_Stream_Controller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * App-side wiring of the substrate's slot-pool seams.
 *
 * The substrate's `Messages_Stream_Controller` exposes three static
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
		Messages_Stream_Controller::$acquire_slot = null;
		Messages_Stream_Controller::$release_slot = null;
		Messages_Stream_Controller::$check_slot   = null;
		Sse_Slot_Pool::$cache                     = null;
	}

	protected function tearDown(): void {
		Messages_Stream_Controller::$acquire_slot = null;
		Messages_Stream_Controller::$release_slot = null;
		Messages_Stream_Controller::$check_slot   = null;
		Sse_Slot_Pool::$cache                     = null;
		parent::tearDown();
	}

	public function test_wire_populates_all_three_seams(): void {
		$this->assertNull( Messages_Stream_Controller::$acquire_slot );
		$this->assertNull( Messages_Stream_Controller::$release_slot );
		$this->assertNull( Messages_Stream_Controller::$check_slot );

		Sse_Slot_Pool::wire();

		$this->assertInstanceOf( \Closure::class, Messages_Stream_Controller::$acquire_slot );
		$this->assertInstanceOf( \Closure::class, Messages_Stream_Controller::$release_slot );
		$this->assertInstanceOf( \Closure::class, Messages_Stream_Controller::$check_slot );
	}

	public function test_acquire_delegates_to_cache_with_partition_arg(): void {
		Sse_Slot_Pool::$cache = new FakeMemcached();
		Sse_Slot_Pool::wire();

		$acquire = Messages_Stream_Controller::$acquire_slot;
		$this->assertNotNull( $acquire );

		// First acquire on the shared browser pool succeeds (slot 0).
		$slot = $acquire( -1 );
		$this->assertSame( 0, $slot );
	}

	public function test_release_returns_slot_to_pool(): void {
		Sse_Slot_Pool::$cache = new FakeMemcached();
		Sse_Slot_Pool::wire();

		$acquire = Messages_Stream_Controller::$acquire_slot;
		$release = Messages_Stream_Controller::$release_slot;

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

		$acquire = Messages_Stream_Controller::$acquire_slot;
		$check   = Messages_Stream_Controller::$check_slot;

		$slot = $acquire( -1 );
		$this->assertTrue( $check( $slot, -1 ) );
	}

	public function test_acquire_returns_false_when_pool_exhausted(): void {
		Sse_Slot_Pool::$cache     = new FakeMemcached();
		Sse_Slot_Pool::$max_slots = 2;
		Sse_Slot_Pool::wire();

		$acquire = Messages_Stream_Controller::$acquire_slot;

		// Fill the pool: two acquires succeed, the third hits the cap.
		$this->assertNotFalse( $acquire( -1 ) );
		$this->assertNotFalse( $acquire( -1 ) );
		$this->assertFalse( $acquire( -1 ) );
	}
}
