<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Memcached_Cache;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Memcached_Cache::class )]
class MemcachedCacheTest extends TestCase {

	public function test_fake_memcached_satisfies_interface(): void {
		$mc = new FakeMemcached();
		$this->assertTrue( $mc->is_available() );
		$this->assertTrue( $mc->set( 'k', 'v', 60 ) );
		$this->assertSame( 'v', $mc->get( 'k' ) );
	}

	public function test_get_returns_null_when_key_missing(): void {
		$mc = new FakeMemcached();
		$this->assertNull( $mc->get( 'missing' ) );
	}

	public function test_set_and_get_roundtrip_for_arrays(): void {
		$mc = new FakeMemcached();
		$mc->set( 'k', [ 'count' => 7, 'sum' => 1.5 ], 60 );
		$this->assertSame( [ 'count' => 7, 'sum' => 1.5 ], $mc->get( 'k' ) );
	}

	public function test_get_multi_returns_only_found_keys(): void {
		$mc = new FakeMemcached();
		$mc->set( 'a', 1, 60 );
		$mc->set( 'c', 3, 60 );
		$result = $mc->get_multi( [ 'a', 'b', 'c' ] );
		$this->assertSame( [ 'a' => 1, 'c' => 3 ], $result );
	}

	public function test_delete_removes_key(): void {
		$mc = new FakeMemcached();
		$mc->set( 'k', 'v', 60 );
		$this->assertTrue( $mc->delete( 'k' ) );
		$this->assertNull( $mc->get( 'k' ) );
	}

	public function test_add_only_succeeds_when_key_missing(): void {
		$mc = new FakeMemcached();
		$this->assertTrue( $mc->add( 'k', 'first', 60 ) );
		$this->assertFalse( $mc->add( 'k', 'second', 60 ) );
		$this->assertSame( 'first', $mc->get( 'k' ) );
	}

	public function test_flush_all_clears_everything(): void {
		$mc = new FakeMemcached();
		$mc->set( 'a', 1, 60 );
		$mc->set( 'b', 2, 60 );
		$this->assertTrue( $mc->flush_all() );
		$this->assertSame( [], $mc->keys() );
	}

	public function test_fail_all_returns_failure_sentinels(): void {
		$mc = new FakeMemcached( fail_all: true );
		$this->assertFalse( $mc->is_available() );
		$this->assertFalse( $mc->set( 'k', 'v', 60 ) );
		$this->assertNull( $mc->get( 'k' ) );
		$this->assertSame( [], $mc->get_multi( [ 'a', 'b' ] ) );
		$this->assertFalse( $mc->delete( 'k' ) );
		$this->assertFalse( $mc->flush_all() );
	}

	public function test_memcached_cache_class_exists(): void {
		$this->assertTrue(
			\class_exists( '\Newspack_Event_Logger_Nodes\Memcached_Cache' ),
			'Memcached_Cache must be loaded from the deferred plugin loader'
		);
	}

	public function test_memcached_cache_returns_null_when_no_extension(): void {
		// Memcached_Cache::get on an unconfigured instance must NOT throw —
		// stats path is fail-SOFT.
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->is_available() );
		$this->assertNull( $mc->get( 'any' ) );
		$this->assertFalse( $mc->set( 'any', 'v', 60 ) );
		$this->assertSame( [], $mc->get_multi( [ 'a', 'b' ] ) );
	}

	// -------------------------------------------------------------------------
	// SSE Slot tests (Cache_Interface contract)
	// -------------------------------------------------------------------------

	public function test_sse_slot_key_shape_browser(): void {
		$mc  = new FakeMemcached();
		$key = $mc->sse_slot_key( 7, 'abcd1234', 0, -1 );
		$this->assertSame( 'evlog:sse:7:abcd1234:0', $key );
	}

	public function test_sse_slot_key_shape_per_partition(): void {
		$mc  = new FakeMemcached();
		$key = $mc->sse_slot_key( 7, 'abcd1234', 3, 2 );
		$this->assertSame( 'evlog:sse:7:abcd1234:p2:3', $key );
	}

	public function test_acquire_first_slot_returns_zero(): void {
		$mc = new FakeMemcached();
		$this->assertSame( 0, $mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 ) );
	}

	public function test_acquire_returns_increasing_slot_indices(): void {
		$mc = new FakeMemcached();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $i, $mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 ) );
		}
	}

	public function test_acquire_returns_false_when_pool_exhausted(): void {
		$mc = new FakeMemcached();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( $i, $mc->acquire_sse_slot( 7, 'abcd1234', 3, 10, -1 ) );
		}
		$this->assertFalse( $mc->acquire_sse_slot( 7, 'abcd1234', 3, 10, -1 ) );
	}

	public function test_acquire_fails_closed_when_cache_unavailable(): void {
		$mc = new FakeMemcached( fail_all: true );
		$this->assertFalse( $mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 ) );
	}

	public function test_check_sse_slot_true_after_acquire(): void {
		$mc = new FakeMemcached();
		$mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 );
		$this->assertTrue( $mc->check_sse_slot( 7, 'abcd1234', 0, -1 ) );
	}

	public function test_check_sse_slot_false_for_unowned_slot(): void {
		$mc = new FakeMemcached();
		$this->assertFalse( $mc->check_sse_slot( 7, 'abcd1234', 0, -1 ) );
	}

	public function test_check_sse_slot_fails_closed_when_cache_unavailable(): void {
		$mc = new FakeMemcached( fail_all: true );
		$this->assertFalse( $mc->check_sse_slot( 7, 'abcd1234', 0, -1 ) );
	}

	public function test_release_clears_slot(): void {
		$mc = new FakeMemcached();
		$mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 );
		$this->assertTrue( $mc->release_sse_slot( 7, 'abcd1234', 0, -1 ) );
		$this->assertFalse( $mc->check_sse_slot( 7, 'abcd1234', 0, -1 ) );
	}

	public function test_release_fails_open_when_cache_unavailable(): void {
		$mc = new FakeMemcached( fail_all: true );
		$this->assertTrue( $mc->release_sse_slot( 7, 'abcd1234', 0, -1 ) );
	}

	public function test_touch_extends_ttl(): void {
		$mc = new FakeMemcached();
		$mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 );
		$this->assertTrue( $mc->touch_sse_slot( 7, 'abcd1234', 0, 30, -1 ) );
	}

	public function test_touch_fails_when_slot_already_expired(): void {
		$mc = new FakeMemcached();
		$this->assertFalse( $mc->touch_sse_slot( 7, 'abcd1234', 0, 30, -1 ) );
	}

	public function test_touch_fails_open_when_cache_unavailable(): void {
		$mc = new FakeMemcached( fail_all: true );
		$this->assertTrue( $mc->touch_sse_slot( 7, 'abcd1234', 0, 30, -1 ) );
	}

	public function test_partition_pool_isolated_from_browser_pool(): void {
		$mc = new FakeMemcached();
		// Both can acquire slot 0 because their cache keys differ.
		$this->assertSame( 0, $mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, -1 ) );
		$this->assertSame( 0, $mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, 0 ) );
		$this->assertSame( 0, $mc->acquire_sse_slot( 7, 'abcd1234', 10, 10, 1 ) );
	}

	public function test_real_memcached_cache_slot_key_constants(): void {
		$ref = new \ReflectionClassConstant( Memcached_Cache::class, 'SSE_SLOT_TTL' );
		$this->assertSame( 10, $ref->getValue() );
	}
}
