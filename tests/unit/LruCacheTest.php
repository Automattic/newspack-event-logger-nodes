<?php
/**
 * Tests for Newspack_Event_Logger_Nodes\LruCache.
 *
 * Verifies bucket rotation, LRU promotion, eviction with on_evict callbacks,
 * timed rotation, and serialization round-trips.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\LRU_Cache;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( LRU_Cache::class )]
class LruCacheTest extends TestCase {

	// ── Basic get/set/delete ───────────────────────────────────────────────

	public function test_get_set_basic(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->set( 'key1', 'value1' );
		$this->assertSame( 'value1', $cache->get( 'key1' ) );
	}

	public function test_get_nonexistent_returns_null(): void {
		$cache = new LRU_Cache( 10, 3 );
		$this->assertNull( $cache->get( 'missing' ) );
	}

	public function test_set_overwrites_existing(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->set( 'key', 'old' );
		$cache->set( 'key', 'new' );
		$this->assertSame( 'new', $cache->get( 'key' ) );
	}

	public function test_set_different_value_types(): void {
		$cache = new LRU_Cache( 10, 3 );

		$cache->set( 'int', 42 );
		$cache->set( 'float', 3.14 );
		$cache->set( 'bool', true );
		$cache->set( 'array', [ 1, 2, 3 ] );

		$this->assertSame( 42, $cache->get( 'int' ) );
		$this->assertSame( 3.14, $cache->get( 'float' ) );
		$this->assertTrue( $cache->get( 'bool' ) );
		$this->assertSame( [ 1, 2, 3 ], $cache->get( 'array' ) );
	}

	public function test_set_object_returns_same_reference(): void {
		// LRU spec note: storing objects gives zero-copy mutation since PHP
		// objects are references. Mutating through get() must mutate the stored
		// object too — essential behaviour for the InflightTracker pattern.
		$cache  = new LRU_Cache( 10, 3 );
		$object = new \stdClass();
		$object->count = 0;
		$cache->set( 'k', $object );

		$retrieved = $cache->get( 'k' );
		++$retrieved->count;

		$this->assertSame( 1, $cache->get( 'k' )->count );
		$this->assertSame( $object, $retrieved );
	}

	public function test_delete_removes_entry(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->set( 'key', 'value' );
		$cache->delete( 'key' );
		$this->assertNull( $cache->get( 'key' ) );
	}

	public function test_delete_nonexistent_is_safe(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->delete( 'nope' );
		$this->assertTrue( true );
	}

	public function test_delete_finds_in_old_bucket(): void {
		// Place entry in bucket 0, fill bucket 0 to trigger rotation, then
		// delete the original — must traverse buckets back and remove.
		$cache = new LRU_Cache( 2, 3 );
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		// Bucket 0 full; next set rotates to bucket 1.
		$cache->set( 'c', 3 );

		$cache->delete( 'a' );
		$this->assertNull( $cache->get( 'a' ) );
		$this->assertSame( 2, $cache->get( 'b' ) );
	}

	// ── Bucket rotation + LRU eviction ─────────────────────────────────────

	public function test_lru_eviction_evicts_oldest_bucket_first(): void {
		// bucket_size=3, num_buckets=2 → max ~6 items before oldest bucket
		// evicts. Verify the "least recently used" bucket goes first.
		$cache = new LRU_Cache( 3, 2 );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 );

		// Triggers rotation to bucket 1.
		$cache->set( 'd', 4 );
		$cache->set( 'e', 5 );
		$cache->set( 'f', 6 );

		// Triggers rotation to bucket 2; with num_buckets=2, evicts bucket 0.
		$cache->set( 'g', 7 );

		$this->assertNull( $cache->get( 'a' ), 'oldest bucket evicted' );
		$this->assertNull( $cache->get( 'b' ) );
		$this->assertNull( $cache->get( 'c' ) );
		// Newer items survive.
		$this->assertSame( 4, $cache->get( 'd' ) );
		$this->assertSame( 7, $cache->get( 'g' ) );
	}

	public function test_get_promotes_to_current_bucket(): void {
		// Promotion is the LRU mechanism — re-reading an old entry moves it
		// to the current bucket so it survives subsequent rotations. Without
		// promotion, frequently-read but rarely-written entries would evict
		// after num_buckets rotations regardless of access pattern.
		$cache = new LRU_Cache( 3, 3 );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 );

		// Trigger rotation (now on bucket 1).
		$cache->set( 'd', 4 );

		// Promote 'a' to bucket 1.
		$this->assertSame( 1, $cache->get( 'a' ) );

		// Continue filling to evict bucket 0 — 'a' was promoted, so it
		// should outlast 'b' and 'c'.
		$cache->set( 'e', 5 );
		$cache->set( 'f', 6 );
		$cache->set( 'g', 7 );
		$cache->set( 'h', 8 );
		$cache->set( 'i', 9 );

		$this->assertNull( $cache->get( 'b' ), 'non-promoted entries evict' );
		$this->assertNull( $cache->get( 'c' ) );
	}

	public function test_bucket_rotation_with_single_bucket(): void {
		// num_buckets=1: rotation immediately evicts the only bucket.
		$cache = new LRU_Cache( 2, 1 );
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 ); // Triggers rotation + eviction.

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
		$this->assertSame( 3, $cache->get( 'c' ) );
	}

	public function test_min_bucket_count_of_1(): void {
		// num_buckets clamped to >=1.
		$cache = new LRU_Cache( 5, 0 );
		$cache->set( 'a', 1 );
		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_max_bucket_count_of_100(): void {
		// num_buckets clamped to <=100. Just verifies the clamp doesn't break
		// instantiation — actual bucket count is implementation-internal.
		$cache = new LRU_Cache( 1, 5000 );
		$cache->set( 'a', 1 );
		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_bucket_size_clamped_to_min_1(): void {
		$cache = new LRU_Cache( 0, 3 );
		// Each set immediately triggers rotation since bucket size is clamped to 1.
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 );
		$this->assertSame( 3, $cache->get( 'c' ) );
	}

	// ── on_evict callbacks ─────────────────────────────────────────────────

	public function test_on_evict_callback_called_on_capacity_eviction(): void {
		$evicted = [];
		$cache   = new LRU_Cache( 2, 2 );
		$cache->with_timed_rotation( 999, function ( $k, $v ) use ( &$evicted ) {
			$evicted[ $k ] = $v;
		} );

		// Fill bucket 0 then bucket 1; the third bucket forces eviction of bucket 0.
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 ); // Triggers rotation.
		$cache->set( 'd', 4 );
		$cache->set( 'e', 5 ); // Triggers second rotation, evicts bucket 0.

		$this->assertSame( [ 'a' => 1, 'b' => 2 ], $evicted );
	}

	public function test_evict_bucket_without_callback_safe(): void {
		// No on_evict registered (default constructor) — eviction must not throw.
		$cache = new LRU_Cache( 2, 2 );
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 );
		$cache->set( 'd', 4 );
		$cache->set( 'e', 5 ); // Eviction.

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertSame( 5, $cache->get( 'e' ) );
	}

	// ── Timed rotation ────────────────────────────────────────────────────

	public function test_with_timed_rotation_returns_self(): void {
		$cache = new LRU_Cache( 10, 3 );
		$result = $cache->with_timed_rotation( 1.0, function () {} );
		$this->assertSame( $cache, $result );
	}

	public function test_rotate_if_due_noop_without_timed_rotation(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->set( 'a', 1 );
		$cache->rotate_if_due();
		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_rotate_if_due_rotates_after_interval(): void {
		// Timed rotation reads the cached per-tick clock (production drives this
		// from the drain loop); advance Core::$now to simulate elapsed ticks.
		Core::$now = 500.0;
		$cache     = new LRU_Cache( 100, 2 );
		$evicted   = [];
		$cache->with_timed_rotation( 0.001, function ( $k, $v ) use ( &$evicted ) {
			$evicted[ $k ] = $v;
		} );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );

		Core::$now = 500.002; // +2ms > 1ms interval.
		$cache->rotate_if_due(); // bucket 0 → bucket 1 (no eviction yet, count <= 2).
		Core::$now = 500.004;
		$cache->rotate_if_due(); // bucket 1 → bucket 2 (count > 2, evicts bucket 0).

		$this->assertArrayHasKey( 'a', $evicted );
		$this->assertArrayHasKey( 'b', $evicted );
	}

	public function test_rotate_if_due_does_not_rotate_before_interval(): void {
		$cache = new LRU_Cache( 100, 2 );
		$cache->with_timed_rotation( 10.0, function () {} );

		$cache->set( 'a', 1 );
		$cache->rotate_if_due(); // Shouldn't rotate (10s not elapsed).
		$cache->rotate_if_due();
		$cache->rotate_if_due();

		// 'a' must still be reachable; cache shouldn't have evicted anything.
		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_active_items_survive_timed_rotation(): void {
		$cache   = new LRU_Cache( 100, 3 );
		$evicted = [];
		$cache->with_timed_rotation( 0.001, function ( $k ) use ( &$evicted ) {
			$evicted[] = $k;
		} );

		$cache->set( 'active', 'val' );

		\usleep( 2000 );
		$cache->rotate_if_due();
		// Touch promotes 'active' to the new current bucket.
		$cache->get( 'active' );
		\usleep( 2000 );
		$cache->rotate_if_due();
		\usleep( 2000 );
		$cache->rotate_if_due();

		$this->assertSame( 'val', $cache->get( 'active' ), 'promoted entry survives' );
		$this->assertNotContains( 'active', $evicted );
	}

	// ── Iteration ──────────────────────────────────────────────────────────

	public function test_iterate_returns_all_entries(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->set( 'x', 1 );
		$cache->set( 'y', 2 );
		$cache->set( 'z', 3 );

		$items = [];
		foreach ( $cache->iterate() as $key => $value ) {
			$items[ $key ] = $value;
		}

		$this->assertCount( 3, $items );
		$this->assertSame( 1, $items['x'] );
		$this->assertSame( 2, $items['y'] );
		$this->assertSame( 3, $items['z'] );
	}

	public function test_iterate_empty_cache(): void {
		$cache = new LRU_Cache( 10, 3 );
		$items = [];
		foreach ( $cache->iterate() as $key => $value ) {
			$items[ $key ] = $value;
		}
		$this->assertEmpty( $items );
	}

	public function test_iterate_across_buckets(): void {
		$cache = new LRU_Cache( 2, 3 );
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 ); // rotates
		$cache->set( 'd', 4 );

		$items = [];
		foreach ( $cache->iterate() as $key => $value ) {
			$items[ $key ] = $value;
		}

		$this->assertCount( 4, $items );
	}

	// ── State serialization ────────────────────────────────────────────────

	public function test_get_state_and_restore_state(): void {
		$cache = new LRU_Cache( 5, 3 );
		$cache->set( 'k1', 'v1' );
		$cache->set( 'k2', 'v2' );
		$cache->set( 'k3', 'v3' );

		$state = $cache->get_state();
		$this->assertArrayHasKey( 'buckets', $state );
		$this->assertArrayHasKey( 'current', $state );

		$cache2 = new LRU_Cache( 5, 3 );
		$cache2->restore_state( $state );

		$this->assertSame( 'v1', $cache2->get( 'k1' ) );
		$this->assertSame( 'v2', $cache2->get( 'k2' ) );
		$this->assertSame( 'v3', $cache2->get( 'k3' ) );
	}

	public function test_restore_state_with_empty_state(): void {
		$cache = new LRU_Cache( 5, 3 );
		$cache->set( 'existing', 'data' );

		$cache->restore_state( [] );
		$this->assertNull( $cache->get( 'existing' ) );
	}

	public function test_restore_state_with_invalid_buckets_type(): void {
		// Validation: non-array buckets must be rejected (no state change).
		$cache = new LRU_Cache( 5, 3 );
		$cache->set( 'a', 1 );

		$cache->restore_state( [ 'buckets' => 'not an array', 'current' => 0 ] );
		// Original entry should still be reachable since validation rejected.
		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_restore_state_with_invalid_current_type(): void {
		$cache = new LRU_Cache( 5, 3 );
		$cache->set( 'a', 1 );

		$cache->restore_state( [ 'buckets' => [ 0 => [ 'b' => 2 ] ], 'current' => 'not int' ] );
		$this->assertSame( 1, $cache->get( 'a' ), 'invalid current rejected, original preserved' );
	}

	public function test_restore_state_clamps_current_to_max_key(): void {
		// Out-of-range current must clamp to the highest bucket index.
		$cache = new LRU_Cache( 5, 3 );
		$cache->restore_state( [
			'buckets' => [ 0 => [ 'a' => 1 ], 1 => [ 'b' => 2 ] ],
			'current' => 999,
		] );

		$this->assertSame( 1, $cache->get( 'a' ) );
		$this->assertSame( 2, $cache->get( 'b' ) );
	}

	public function test_restore_state_clamps_negative_current(): void {
		$cache = new LRU_Cache( 5, 3 );
		$cache->restore_state( [
			'buckets' => [ 0 => [ 'a' => 1 ] ],
			'current' => -10,
		] );

		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_get_state_preserves_bucket_structure(): void {
		$cache = new LRU_Cache( 3, 3 );
		for ( $i = 0; $i < 9; $i++ ) {
			$cache->set( "k{$i}", $i );
		}
		$state = $cache->get_state();

		$this->assertGreaterThan( 1, \count( $state['buckets'] ) );
		$this->assertGreaterThan( 0, $state['current'] );
	}

	// ── flush ──────────────────────────────────────────────────────────────

	public function test_flush_clears_all(): void {
		$cache = new LRU_Cache( 10, 3 );
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->flush();

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
	}

	// ── Combined behaviour ─────────────────────────────────────────────────

	public function test_large_number_of_items(): void {
		$cache = new LRU_Cache( 100, 5 );
		for ( $i = 0; $i < 600; $i++ ) {
			$cache->set( "key{$i}", $i );
		}

		// Recent items reachable.
		$this->assertSame( 599, $cache->get( 'key599' ) );
		// Old items evicted.
		$this->assertNull( $cache->get( 'key0' ) );
	}
}
