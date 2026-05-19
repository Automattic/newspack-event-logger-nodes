<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Memcached_Cache;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;

#[Medium]
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

	// =========================================================================
	// Real Memcached_Cache: construction + extension-detection branches
	// =========================================================================

	public function test_default_servers_constant_is_localhost(): void {
		$this->assertSame( [ '127.0.0.1:11211' ], Memcached_Cache::DEFAULT_SERVERS );
	}

	public function test_constructor_with_empty_servers_returns_unavailable(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->is_available() );
	}

	public function test_constructor_parses_host_port_format(): void {
		// Unreachable host:port is fine — Memcached extension defers connect.
		// Whether is_available is true depends on the Memcached extension's
		// addServer return; we just verify construction does not throw.
		$mc = new Memcached_Cache( servers: [ '192.0.2.1:11299' ] );
		$this->assertInstanceOf( Memcached_Cache::class, $mc );
	}

	public function test_constructor_default_port_when_missing(): void {
		// "127.0.0.1" with no port — parser substitutes 11211.
		$mc = new Memcached_Cache( servers: [ '127.0.0.1' ] );
		$this->assertInstanceOf( Memcached_Cache::class, $mc );
	}

	public function test_constructor_handles_empty_host_string(): void {
		// ':11211' explodes to ['', '11211']. Parser keeps the empty host.
		// Construction must not throw (Memcached extension's addServer is forgiving).
		$mc = new Memcached_Cache( servers: [ ':11211' ] );
		$this->assertInstanceOf( Memcached_Cache::class, $mc );
	}

	// =========================================================================
	// is_available, get, set, add, delete, get_multi, flush_all on null memd
	// =========================================================================

	public function test_get_returns_null_when_no_extension(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertNull( $mc->get( 'any-key' ) );
	}

	public function test_set_returns_false_when_no_extension(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->set( 'k', 'v', 60 ) );
	}

	public function test_add_returns_false_when_no_extension(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->add( 'k', 'v', 60 ) );
	}

	public function test_delete_returns_false_when_no_extension(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->delete( 'k' ) );
	}

	public function test_get_multi_returns_empty_when_no_extension(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertSame( [], $mc->get_multi( [ 'a', 'b' ] ) );
	}

	public function test_get_multi_returns_empty_for_empty_keys_array(): void {
		// Empty input short-circuits before touching memd.
		$mc = new Memcached_Cache( servers: [] );
		$this->assertSame( [], $mc->get_multi( [] ) );
	}

	public function test_get_multi_with_live_cache_and_empty_keys(): void {
		// Even with a live connection, empty input must short-circuit to [].
		$mc = $this->make_live_cache();
		$this->assertSame( [], $mc->get_multi( [] ) );
	}

	public function test_flush_all_returns_false_when_no_extension(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->flush_all() );
	}

	// =========================================================================
	// SSE slot fail-CLOSED / fail-OPEN polarity on null memd
	// =========================================================================

	public function test_acquire_sse_slot_fails_closed_without_memcache(): void {
		// Stats path is fail-soft, but SSE slots are fail-CLOSED — without a
		// cache to enforce the rate limit, every new connection is denied.
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->acquire_sse_slot( 1, 'abc12345', 10, 10, -1 ) );
	}

	public function test_check_sse_slot_fails_closed_without_memcache(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->assertFalse( $mc->check_sse_slot( 1, 'abc12345', 0, -1 ) );
	}

	public function test_touch_sse_slot_fails_open_without_memcache(): void {
		// touch is fail-OPEN — heartbeat shouldn't kill a live connection just
		// because memcache went out for lunch.
		$mc = new Memcached_Cache( servers: [] );
		$this->assertTrue( $mc->touch_sse_slot( 1, 'abc12345', 0, 30, -1 ) );
	}

	public function test_release_sse_slot_fails_open_without_memcache(): void {
		// Release is fail-OPEN — slots TTL out anyway.
		$mc = new Memcached_Cache( servers: [] );
		$this->assertTrue( $mc->release_sse_slot( 1, 'abc12345', 0, -1 ) );
	}

	// =========================================================================
	// Live extension paths (skipped when no extension / no server)
	// =========================================================================

	private function make_live_cache(): Memcached_Cache {
		if ( ! \class_exists( '\Memcached' ) && ! \class_exists( '\Memcache' ) ) {
			$this->markTestSkipped( 'Neither Memcached nor Memcache PHP extension is available' );
		}

		// Try the test container's known host first (memcache1:11211), then the
		// default. Skipped silently if neither answers a probe — matches the
		// pre-existing skip behavior but makes live tests actually run inside
		// the dndocker `eve-pyrobase1-1` container.
		$candidates = [ 'memcache1:11211' ];
		if ( $env = \getenv( 'MEMCACHED_TEST_HOST' ) ) {
			\array_unshift( $candidates, (string) $env );
		}
		$mc = null;
		foreach ( $candidates as $host_port ) {
			$candidate = new Memcached_Cache( servers: [ $host_port ] );
			if ( ! $candidate->is_available() ) {
				continue;
			}
			$probe = 'evlog_test_probe_' . \uniqid();
			if ( $candidate->set( $probe, 'ping', 5 ) && 'ping' === $candidate->get( $probe ) ) {
				$candidate->delete( $probe );
				$mc = $candidate;
				break;
			}
		}
		if ( null === $mc ) {
			// Fall back to default servers (127.0.0.1:11211) — last attempt.
			$mc = new Memcached_Cache();
			if ( ! $mc->is_available() ) {
				$this->markTestSkipped( 'No reachable memcached server' );
			}
			$probe = 'evlog_test_probe_' . \uniqid();
			$ok    = $mc->set( $probe, 'ping', 5 );
			if ( ! $ok || 'ping' !== $mc->get( $probe ) ) {
				$this->markTestSkipped( 'No reachable memcached server' );
			}
			$mc->delete( $probe );
		}

		return $mc;
	}

	public function test_live_set_get_roundtrip_string(): void {
		$mc = $this->make_live_cache();

		$key = 'evlog_rt_str_' . \uniqid();
		$this->assertTrue( $mc->set( $key, 'hello', 30 ) );
		$this->assertSame( 'hello', $mc->get( $key ) );
		$mc->delete( $key );
	}

	public function test_live_set_get_roundtrip_complex_value(): void {
		$mc    = $this->make_live_cache();
		$key   = 'evlog_rt_arr_' . \uniqid();
		$value = [ 'count' => 7, 'sum' => 1.5, 'nested' => [ 'k' => 'v' ] ];

		$this->assertTrue( $mc->set( $key, $value, 30 ) );
		$this->assertSame( $value, $mc->get( $key ) );
		$mc->delete( $key );
	}

	public function test_live_get_returns_null_for_missing_key(): void {
		$mc = $this->make_live_cache();
		$this->assertNull( $mc->get( 'evlog_missing_' . \uniqid() ) );
	}

	public function test_live_set_overwrites_existing(): void {
		$mc  = $this->make_live_cache();
		$key = 'evlog_overwrite_' . \uniqid();
		$mc->set( $key, 'first', 30 );
		$mc->set( $key, 'second', 30 );
		$this->assertSame( 'second', $mc->get( $key ) );
		$mc->delete( $key );
	}

	public function test_live_add_succeeds_for_missing_key(): void {
		$mc  = $this->make_live_cache();
		$key = 'evlog_add_new_' . \uniqid();
		$this->assertTrue( $mc->add( $key, 'first', 30 ) );
		$this->assertSame( 'first', $mc->get( $key ) );
		$mc->delete( $key );
	}

	public function test_live_add_fails_for_existing_key(): void {
		$mc  = $this->make_live_cache();
		$key = 'evlog_add_dup_' . \uniqid();
		$mc->set( $key, 'original', 30 );
		$this->assertFalse( $mc->add( $key, 'duplicate', 30 ) );
		$this->assertSame( 'original', $mc->get( $key ) );
		$mc->delete( $key );
	}

	public function test_live_delete_existing_key_returns_true(): void {
		$mc  = $this->make_live_cache();
		$key = 'evlog_del_' . \uniqid();
		$mc->set( $key, 'v', 30 );
		$this->assertTrue( $mc->delete( $key ) );
		$this->assertNull( $mc->get( $key ) );
	}

	public function test_live_delete_nonexistent_returns_false(): void {
		$mc = $this->make_live_cache();
		$this->assertFalse( $mc->delete( 'evlog_neverset_' . \uniqid() ) );
	}

	public function test_live_get_multi_returns_only_found_keys(): void {
		$mc     = $this->make_live_cache();
		$prefix = 'evlog_multi_' . \uniqid() . '_';

		$mc->set( $prefix . 'a', 'alpha', 30 );
		$mc->set( $prefix . 'b', [ 'array' => true ], 30 );

		$result = $mc->get_multi( [ $prefix . 'a', $prefix . 'b', $prefix . 'missing' ] );

		$this->assertArrayHasKey( $prefix . 'a', $result );
		$this->assertSame( 'alpha', $result[ $prefix . 'a' ] );
		$this->assertArrayHasKey( $prefix . 'b', $result );
		$this->assertSame( [ 'array' => true ], $result[ $prefix . 'b' ] );
		$this->assertArrayNotHasKey( $prefix . 'missing', $result );

		$mc->delete( $prefix . 'a' );
		$mc->delete( $prefix . 'b' );
	}

	public function test_live_acquire_release_roundtrip(): void {
		$mc      = $this->make_live_cache();
		$user_id = 9000 + \random_int( 0, 99 );
		$ip_hash = 'r' . \substr( \md5( (string) \uniqid() ), 0, 7 );
		$max     = 2;
		$ttl     = 5;

		$slot1 = $mc->acquire_sse_slot( $user_id, $ip_hash, $max, $ttl, -1 );
		$slot2 = $mc->acquire_sse_slot( $user_id, $ip_hash, $max, $ttl, -1 );
		$slot3 = $mc->acquire_sse_slot( $user_id, $ip_hash, $max, $ttl, -1 );

		$this->assertSame( 0, $slot1 );
		$this->assertSame( 1, $slot2 );
		$this->assertFalse( $slot3, 'Pool of 2 must reject the third acquire' );

		// Slot 0 should now be checkable.
		$this->assertTrue( $mc->check_sse_slot( $user_id, $ip_hash, 0, -1 ) );
		$this->assertTrue( $mc->check_sse_slot( $user_id, $ip_hash, 1, -1 ) );

		// Release frees the slot for re-acquisition.
		$mc->release_sse_slot( $user_id, $ip_hash, 0, -1 );
		$this->assertFalse( $mc->check_sse_slot( $user_id, $ip_hash, 0, -1 ) );

		$slot4 = $mc->acquire_sse_slot( $user_id, $ip_hash, $max, $ttl, -1 );
		$this->assertSame( 0, $slot4 );

		// Cleanup.
		$mc->release_sse_slot( $user_id, $ip_hash, 0, -1 );
		$mc->release_sse_slot( $user_id, $ip_hash, 1, -1 );
	}

	public function test_live_check_sse_slot_false_for_unowned_slot(): void {
		$mc      = $this->make_live_cache();
		$user_id = 9100 + \random_int( 0, 99 );
		$ip_hash = 'c' . \substr( \md5( (string) \uniqid() ), 0, 7 );
		$this->assertFalse( $mc->check_sse_slot( $user_id, $ip_hash, 0, -1 ) );
	}

	public function test_live_touch_extends_existing_slot(): void {
		$mc      = $this->make_live_cache();
		$user_id = 9200 + \random_int( 0, 99 );
		$ip_hash = 't' . \substr( \md5( (string) \uniqid() ), 0, 7 );

		$slot = $mc->acquire_sse_slot( $user_id, $ip_hash, 1, 5, -1 );
		$this->assertSame( 0, $slot );

		// Touch a known-live slot returns true on Memcached extension via
		// native touch(); on Memcache extension via get-then-set.
		$this->assertTrue( $mc->touch_sse_slot( $user_id, $ip_hash, 0, 5, -1 ) );

		$mc->release_sse_slot( $user_id, $ip_hash, 0, -1 );
	}

	public function test_live_touch_returns_false_for_expired_or_unset_slot(): void {
		$mc      = $this->make_live_cache();
		$user_id = 9300 + \random_int( 0, 99 );
		$ip_hash = 'e' . \substr( \md5( (string) \uniqid() ), 0, 7 );

		// Slot was never acquired — touch should return false (slot expired).
		$this->assertFalse( $mc->touch_sse_slot( $user_id, $ip_hash, 0, 5, -1 ) );
	}

	public function test_live_partition_pool_isolated(): void {
		// Per-partition pools have their own keys, so the same (user, ip_hash,
		// slot=0) can be claimed independently in -1, 0, 1.
		$mc      = $this->make_live_cache();
		$user_id = 9400 + \random_int( 0, 99 );
		$ip_hash = 'p' . \substr( \md5( (string) \uniqid() ), 0, 7 );

		$shared = $mc->acquire_sse_slot( $user_id, $ip_hash, 1, 5, -1 );
		$p0     = $mc->acquire_sse_slot( $user_id, $ip_hash, 1, 5, 0 );
		$p1     = $mc->acquire_sse_slot( $user_id, $ip_hash, 1, 5, 1 );

		$this->assertSame( 0, $shared );
		$this->assertSame( 0, $p0 );
		$this->assertSame( 0, $p1 );

		$mc->release_sse_slot( $user_id, $ip_hash, 0, -1 );
		$mc->release_sse_slot( $user_id, $ip_hash, 0, 0 );
		$mc->release_sse_slot( $user_id, $ip_hash, 0, 1 );
	}

	public function test_live_ttl_expires_value(): void {
		$mc  = $this->make_live_cache();
		$key = 'evlog_ttl_' . \uniqid();
		$mc->set( $key, 'expires-soon', 1 );
		$this->assertSame( 'expires-soon', $mc->get( $key ) );

		\sleep( 2 );
		$this->assertNull( $mc->get( $key ), 'Key must be gone after TTL elapses' );
	}

	// =========================================================================
	// SSE slot key shape (private method, exercised via reflection)
	// =========================================================================

	public function test_sse_slot_key_format_browser_pool(): void {
		$mc  = new Memcached_Cache( servers: [] );
		$ref = new \ReflectionMethod( Memcached_Cache::class, 'sse_slot_key' );
		$ref->setAccessible( true );
		$this->assertSame(
			'evlog:sse:42:abcd1234:5',
			$ref->invoke( $mc, 42, 'abcd1234', 5, -1 )
		);
	}

	public function test_sse_slot_key_format_partition_scoped(): void {
		$mc  = new Memcached_Cache( servers: [] );
		$ref = new \ReflectionMethod( Memcached_Cache::class, 'sse_slot_key' );
		$ref->setAccessible( true );
		$this->assertSame(
			'evlog:sse:42:abcd1234:p3:5',
			$ref->invoke( $mc, 42, 'abcd1234', 5, 3 )
		);
	}

	public function test_sse_slot_key_partition_zero_uses_partition_form(): void {
		// Boundary: partition=0 is >= 0 so we use the per-partition form.
		$mc  = new Memcached_Cache( servers: [] );
		$ref = new \ReflectionMethod( Memcached_Cache::class, 'sse_slot_key' );
		$ref->setAccessible( true );
		$this->assertSame(
			'evlog:sse:7:hash:p0:1',
			$ref->invoke( $mc, 7, 'hash', 1, 0 )
		);
	}

	// =========================================================================
	// Legacy Memcache extension path — exercised via reflection.
	//
	// On the test container, the Memcached extension exists, so the constructor
	// always takes the first branch. These tests force-invoke connect_memcache
	// directly to cover its branch + the conditional set/add/get_multi forks.
	// =========================================================================

	private function inject_legacy_memcache( Memcached_Cache $mc, ?object $stub = null ): object {
		$stub = $stub ?? new MemcachedCacheTestLegacyStub();

		$memd_ref = new \ReflectionProperty( Memcached_Cache::class, 'memd' );
		$memd_ref->setAccessible( true );
		$memd_ref->setValue( $mc, $stub );

		$ext_ref = new \ReflectionProperty( Memcached_Cache::class, 'extension' );
		$ext_ref->setAccessible( true );
		$ext_ref->setValue( $mc, 'memcache' );

		return $stub;
	}

	public function test_legacy_memcache_set_uses_four_arg_signature(): void {
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		$this->assertTrue( $mc->set( 'k', 'v', 60 ) );
		// Memcache extension signature: set(key, value, flags, ttl). Stub records
		// the args so we can assert on them.
		$this->assertSame( [ 'k', 'v', 0, 60 ], $stub->last_set_args );
	}

	public function test_legacy_memcache_add_uses_four_arg_signature(): void {
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		$this->assertTrue( $mc->add( 'k', 'v', 60 ) );
		$this->assertSame( [ 'k', 'v', 0, 60 ], $stub->last_add_args );
	}

	public function test_legacy_memcache_get_multi_falls_back_to_serial(): void {
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		$mc->set( 'a', 'alpha', 60 );
		$mc->set( 'b', 'bravo', 60 );

		$result = $mc->get_multi( [ 'a', 'b', 'missing' ] );
		$this->assertSame( [ 'a' => 'alpha', 'b' => 'bravo' ], $result );
	}

	public function test_legacy_memcache_get_returns_null_on_false_sentinel(): void {
		// PHP's Memcache::get returns false for misses; Memcached_Cache must
		// translate that to null so callers see a uniform "missing" signal.
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		$this->assertNull( $mc->get( 'never-set' ) );
	}

	public function test_legacy_memcache_touch_uses_get_then_set_fallback(): void {
		// Legacy Memcache has no native touch(); Memcached_Cache falls back to
		// get-then-set. Verify the fallback hits the wire (set is called with
		// the original value).
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		// Pre-seed slot.
		$mc->set( 'evlog:sse:1:hh:0', 'conn-id', 5 );
		$stub->last_set_args = null;

		$this->assertTrue( $mc->touch_sse_slot( 1, 'hh', 0, 30, -1 ) );
		$this->assertNotNull( $stub->last_set_args );
		$this->assertSame( 'evlog:sse:1:hh:0', $stub->last_set_args[0] );
		$this->assertSame( 'conn-id', $stub->last_set_args[1] );
		$this->assertSame( 30, $stub->last_set_args[3] );
	}

	public function test_legacy_memcache_touch_returns_false_when_slot_missing(): void {
		$mc = new Memcached_Cache( servers: [] );
		$this->inject_legacy_memcache( $mc );

		$this->assertFalse( $mc->touch_sse_slot( 1, 'hh', 0, 30, -1 ) );
	}

	public function test_legacy_memcache_flush_all_returns_true_on_success(): void {
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		$this->assertTrue( $mc->flush_all() );
		$this->assertTrue( $stub->flush_called );
	}

	public function test_legacy_memcache_delete_returns_stub_value(): void {
		$mc   = new Memcached_Cache( servers: [] );
		$stub = $this->inject_legacy_memcache( $mc );

		$this->assertTrue( $mc->delete( 'whatever' ) );
		$this->assertSame( 'whatever', $stub->last_delete_key );
	}

	// =========================================================================
	// from_substrate_config: non-array memcache_servers fallback.
	// =========================================================================

	public function test_from_substrate_config_falls_back_when_servers_not_array(): void {
		// Stuff a non-array value into the substrate config cache so the
		// `! is_array( $servers )` branch fires inside from_substrate_config().
		// The Config sanitizer normally coerces invalid values to an array, but
		// the FROM_SUBSTRATE_CONFIG path defends against a Config that returns
		// any shape (e.g. a config file that does `'memcache_servers' => 'x'`).
		$config_prop = new \ReflectionProperty( \Newspack_Nodes\Config::class, 'config' );
		$config_prop->setAccessible( true );
		$original = $config_prop->getValue();
		try {
			$config_prop->setValue(
				null,
				[ 'memcache_servers' => 'definitely-not-an-array' ]
			);

			$cache = Memcached_Cache::from_substrate_config();
			$this->assertInstanceOf( Memcached_Cache::class, $cache );
			// Whatever the fallback produced, the constructor must not crash.
			$this->assertIsBool( $cache->is_available() );
		} finally {
			$config_prop->setValue( null, $original );
			\Newspack_Nodes\Config::reset();
		}
	}
}

/**
 * Inline stub for the legacy \Memcache extension. Lives in the test file so the
 * production class can be exercised end-to-end without requiring the (now-rare)
 * Memcache PECL extension to actually be loaded.
 *
 * Only methods Memcached_Cache calls on the legacy path are implemented.
 */
class MemcachedCacheTestLegacyStub {
	/** @var array<string,mixed> */
	private array $store = [];
	/** @var array{0:string,1:mixed,2:int,3:int}|null */
	public ?array $last_set_args = null;
	/** @var array{0:string,1:mixed,2:int,3:int}|null */
	public ?array $last_add_args = null;
	public ?string $last_delete_key = null;
	public bool $flush_called = false;

	public function set( string $key, mixed $value, int $flags, int $ttl ): bool {
		$this->last_set_args = [ $key, $value, $flags, $ttl ];
		$this->store[ $key ] = $value;
		return true;
	}

	public function add( string $key, mixed $value, int $flags, int $ttl ): bool {
		$this->last_add_args = [ $key, $value, $flags, $ttl ];
		if ( \array_key_exists( $key, $this->store ) ) {
			return false;
		}
		$this->store[ $key ] = $value;
		return true;
	}

	public function get( string $key ): mixed {
		// Memcache returns false on miss — mirror that for the unit-under-test.
		return \array_key_exists( $key, $this->store ) ? $this->store[ $key ] : false;
	}

	public function delete( string $key ): bool {
		$this->last_delete_key = $key;
		unset( $this->store[ $key ] );
		return true;
	}

	public function flush(): bool {
		$this->flush_called = true;
		$this->store        = [];
		return true;
	}
}
