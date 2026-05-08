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
}
