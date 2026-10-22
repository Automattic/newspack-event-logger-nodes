<?php
/**
 * Tests for Object_Cache_Counts: what a request asked of the object cache,
 * read from where each known cache keeps the truth.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Object_Cache_Counts;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

require_once \dirname( __DIR__ ) . '/fixtures/object-cache/wp-includes/class-wp-object-cache.php';
require_once \dirname( __DIR__ ) . '/fixtures/object-cache/memcached-drop-in.php';

#[CoversClass( Object_Cache_Counts::class )]
class ObjectCacheCountsTest extends TestCase {

	/** @var mixed The object cache as the test found it. */
	private mixed $saved = null;

	protected function setUp(): void {
		parent::setUp();
		$this->saved = $GLOBALS['wp_object_cache'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_object_cache'] = $this->saved;
		parent::tearDown();
	}

	/** Core's cache, holding the counts it reached. */
	private function core_cache( int $hits, int $misses ): \WP_Object_Cache {
		$cache               = new \WP_Object_Cache();
		$cache->cache_hits   = $hits;
		$cache->cache_misses = $misses;
		return $GLOBALS['wp_object_cache'] = $cache;
	}

	/**
	 * The drop-in after a run of lookups: local answers, single gets that
	 * found or missed in memcached (one invalidated, one also slow), a
	 * get_multiple batch, and adds, which its `cache_misses` counts.
	 */
	private function busy_drop_in( \Memcached_Drop_In_Fixture $cache, int $scale = 1 ): void {
		for ( $i = 0; $i < 9 * $scale; $i++ ) {
			$cache->op( 'get_local', "kea:{$i}", 'posts', 'local' );
		}
		for ( $i = 0; $i < 4 * $scale; $i++ ) {
			$cache->op( 'get', "weka:{$i}", 'options', 'memcache', 0 === $i );
		}
		$cache->op( 'get', 'tui', 'options', 'not_in_memcache' );
		$cache->op( 'get', 'ruru', 'options', 'checkthedatabaseplease' );
		$cache->op( 'get_multiple', [ 'a', 'b', 'c' ], 'terms' );
		for ( $i = 0; $i < 7; $i++ ) {
			$cache->op( 'add', "kaka:{$i}", 'posts' );
		}
	}

	/** Core counts true runtime hits and misses, so they are read as they are. */
	public function test_core_counts_are_read_as_they_are(): void {
		$this->core_cache( 4815, 162 );

		$this->assertSame( '4815 hit, 162 miss', Object_Cache_Counts::row( Object_Cache_Counts::mark( true ) ) );
	}

	/** A mark taken partway counts only what moved after it. */
	public function test_core_counts_since_a_mark_are_the_difference(): void {
		$cache = $this->core_cache( 100, 10 );
		$mark  = Object_Cache_Counts::mark();
		$cache->cache_hits   = 4915;
		$cache->cache_misses = 172;

		$this->assertSame( '4815 hit, 162 miss', Object_Cache_Counts::row( $mark ) );
	}

	/**
	 * Under the drop-in its `cache_hits` and `cache_misses` are its get and
	 * add counters, so neither is read: the local answers come from
	 * `stats['get_local']`, the gets from `stats['get']`, each get's hit or
	 * miss from its recorded comment, slow-op copies left out, and a
	 * get_multiple batch counts its keys, which it records no result for.
	 */
	public function test_the_drop_in_is_read_where_it_keeps_the_truth(): void {
		$cache                      = new \Memcached_Drop_In_Fixture();
		$GLOBALS['wp_object_cache'] = $cache;
		$mark                       = Object_Cache_Counts::mark( true );
		$this->busy_drop_in( $cache );

		$this->assertSame( 6, $cache->cache_hits, 'the fixture aliases cache_hits to the get count' );
		$this->assertSame( '9 local, 6 get, 4 hit, 2 miss, 3 multi', Object_Cache_Counts::row( $mark ) );
	}

	/** A mark partway through the drop-in's run excludes what came before it. */
	public function test_drop_in_counts_since_a_mark_exclude_earlier_operations(): void {
		$cache                      = new \Memcached_Drop_In_Fixture();
		$GLOBALS['wp_object_cache'] = $cache;
		$this->busy_drop_in( $cache, 3 );
		$mark = Object_Cache_Counts::mark();
		$this->busy_drop_in( $cache );

		$this->assertSame( '9 local, 6 get, 4 hit, 2 miss, 3 multi', Object_Cache_Counts::row( $mark ) );
	}

	/** Every bucket of the kind is written, a zero as a zero, in its fixed order. */
	public function test_the_row_shows_every_bucket_zeros_included(): void {
		$this->core_cache( 0, 0 );
		$core = Object_Cache_Counts::row( Object_Cache_Counts::mark( true ) );
		$GLOBALS['wp_object_cache'] = new \Memcached_Drop_In_Fixture();
		$memcached = Object_Cache_Counts::row( Object_Cache_Counts::mark( true ) );

		$this->assertSame( '0 hit, 0 miss', $core );
		$this->assertSame( '0 local, 0 get, 0 hit, 0 miss, 0 multi', $memcached );
	}

	/** Any other cache, counters or not, gets no row. */
	public function test_an_unknown_cache_gets_nothing(): void {
		$GLOBALS['wp_object_cache'] = (object) [ 'cache_hits' => 4815, 'cache_misses' => 162 ];

		$this->assertNull( Object_Cache_Counts::mark() );
		$this->assertNull( Object_Cache_Counts::row( Object_Cache_Counts::mark( true ) ) );
	}

	/** No cache at all is nothing too. */
	public function test_no_cache_gets_nothing(): void {
		$GLOBALS['wp_object_cache'] = null;

		$this->assertNull( Object_Cache_Counts::row( null ) );
		$this->assertNull( Object_Cache_Counts::mark( true ) );
	}

	/** A mark from another kind of cache than the one now installed counts nothing. */
	public function test_a_mark_from_another_cache_counts_nothing(): void {
		$this->core_cache( 100, 10 );
		$mark                       = Object_Cache_Counts::mark();
		$GLOBALS['wp_object_cache'] = new \Memcached_Drop_In_Fixture();

		$this->assertNull( Object_Cache_Counts::row( $mark ) );
	}
}
