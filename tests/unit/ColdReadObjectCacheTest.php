<?php
/**
 * Tests for Newspack_Cache_Warmer\Cold_Read_Object_Cache.
 *
 * The cache-warmer's object-cache decorator. During an out-of-band warm
 * render we swap this in for $GLOBALS['wp_object_cache'] so that reads on
 * allowlisted "cold" groups always miss (forcing Newspack to rebuild every
 * block / re-run every ES query instead of serving stale HTML), while every
 * write passes straight through to the real object cache — so the freshly
 * rendered entries land in live memcached under their own correct keys with
 * a fresh timestamp. No key replication, no cold window.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Cache_Warmer\Cold_Read_Object_Cache;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Cold_Read_Object_Cache::class )]
class ColdReadObjectCacheTest extends TestCase {

	/**
	 * Minimal array-backed WP_Object_Cache double: group-namespaced get/set
	 * honoring the by-ref $found contract wp_cache_get() relies on, plus a
	 * passthrough-only method to prove __call delegation.
	 */
	private function fake_real_cache(): object {
		// Real WP_Object_Cache carries arbitrary public props (cache_hits,
		// global_groups, …); allow them on the double so property-delegation
		// tests don't trip PHP 8.4's dynamic-property deprecation.
		return new #[\AllowDynamicProperties] class() {
			public array $store    = [];
			public array $deleted  = [];
			public function get( $key, $group = '', $force = false, &$found = null ) {
				if ( isset( $this->store[ $group ][ $key ] ) ) {
					$found = true;
					return $this->store[ $group ][ $key ];
				}
				$found = false;
				return false;
			}
			public function set( $key, $data, $group = '', $expire = 0 ) {
				$this->store[ $group ][ $key ] = $data;
				return true;
			}
			public function delete( $key, $group = '' ) {
				$this->deleted[] = "$group/$key";
				unset( $this->store[ $group ][ $key ] );
				return true;
			}
		};
	}

	public function test_get_misses_on_cold_group_even_when_real_has_value(): void {
		$real = $this->fake_real_cache();
		$real->set( 'np_cached_block_abc_0', 'stale-html', 'newspack_blocks' );

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertFalse( $cold->get( 'np_cached_block_abc_0', 'newspack_blocks', false, $found ) );
		$this->assertFalse( $found, 'cold-group read must report not-found so callers treat it as a miss' );
	}

	public function test_get_misses_on_a_prefixed_cold_group(): void {
		// Newspack's block cache splits into per-page / feed group variants
		// (`newspack_blocks-post-{ID}`, `newspack_blocks-feed`); a static-Page
		// homepage uses `newspack_blocks-post-{ID}`. Cooling the base group must
		// cool those derived groups too, or the homepage keeps hitting the cache.
		$real = $this->fake_real_cache();
		$real->set( 'np_cached_block_abc_0', 'stale-html', 'newspack_blocks-post-42' );

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertFalse( $cold->get( 'np_cached_block_abc_0', 'newspack_blocks-post-42', false, $found ) );
		$this->assertFalse( $found );
	}

	public function test_get_does_not_cool_a_group_lacking_the_separator(): void {
		// Prefix match requires the `-` separator, so an unrelated group that
		// merely starts with a cold name still passes through.
		$real = $this->fake_real_cache();
		$real->set( 'k', 'warm', 'newspack_blocksx' );

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertSame( 'warm', $cold->get( 'k', 'newspack_blocksx' ) );
	}

	public function test_get_multiple_misses_on_a_prefixed_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$result = $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks-feed' );

		$this->assertSame( [ 'k1' => false, 'k2' => false ], $result );
	}

	public function test_get_passes_through_on_warm_group(): void {
		$real = $this->fake_real_cache();
		$real->set( 'alloptions', [ 'a' => 1 ], 'options' );

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertSame( [ 'a' => 1 ], $cold->get( 'alloptions', 'options', false, $found ) );
		$this->assertTrue( $found, 'warm-group read must delegate to the real cache untouched' );
	}

	public function test_set_writes_through_to_real_cache(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$cold->set( 'np_cached_block_abc_0', 'fresh-html', 'newspack_blocks', 120 );

		$this->assertSame( 'fresh-html', $real->store['newspack_blocks']['np_cached_block_abc_0'] );
	}

	public function test_get_multiple_misses_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$result = $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks' );

		$this->assertSame( [ 'k1' => false, 'k2' => false ], $result );
	}

	public function test_unknown_methods_delegate_to_real_cache(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		// delete is not overridden — it must pass through via __call.
		$cold->delete( 'k', 'newspack_blocks' );

		$this->assertSame( [ 'newspack_blocks/k' ], $real->deleted );
	}

	public function test_property_reads_delegate_to_real_cache(): void {
		// WP core / drop-ins read $wp_object_cache->cache_hits, ->global_groups, etc.
		$real              = $this->fake_real_cache();
		$real->cache_hits  = 7;
		$real->global_groups = [ 'users' => true ];

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertSame( 7, $cold->cache_hits );
		$this->assertSame( [ 'users' => true ], $cold->global_groups );
		$this->assertTrue( isset( $cold->cache_hits ) );
		$this->assertFalse( isset( $cold->nonexistent ) );
	}

	public function test_property_writes_delegate_to_real_cache(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$cold->cache_hits = 9;

		$this->assertSame( 9, $real->cache_hits );
	}
}
