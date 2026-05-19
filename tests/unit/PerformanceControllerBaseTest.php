<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerformanceControllerBase::class )]
class PerformanceControllerBaseTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Reset shared cache between tests so rate-limit counters don't bleed.
		PerformanceControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_read_permissions_check_requires_capability(): void {
		$ctrl = new TestableController();
		$GLOBALS['_current_user_can'] = false;
		$result = $ctrl->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_read_permissions_check_passes_when_capable(): void {
		$ctrl = new TestableController();
		$GLOBALS['_current_user_can'] = true;
		$this->assertTrue( $ctrl->read_permissions_check() );
	}

	public function test_validate_partition_accepts_in_range(): void {
		$ctrl = new TestableController();
		$this->assertSame( 2, $ctrl->validate_partition( 2, 4 ) );
	}

	public function test_validate_partition_rejects_out_of_range(): void {
		$ctrl = new TestableController();
		$result = $ctrl->validate_partition( 10, 4 );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_validate_partition_rejects_negative(): void {
		$ctrl = new TestableController();
		$result = $ctrl->validate_partition( -1, 4 );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_check_rate_limit_allows_within_quota(): void {
		$ctrl = new TestableController();
		// 60 default — first call always succeeds.
		$result = $ctrl->check_rate_limit( 'user_1' );
		$this->assertTrue( $result );
	}

	public function test_check_rate_limit_blocks_after_quota(): void {
		$ctrl = new TestableController();
		// Burn through a small quota; the 4th must come back as WP_Error.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $ctrl->check_rate_limit( 'user_42', 3, 60 ) );
		}
		$blocked = $ctrl->check_rate_limit( 'user_42', 3, 60 );
		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'rate_limit_exceeded', $blocked->get_error_code() );
		$this->assertSame( 429, $blocked->data['status'] ?? 0 );
	}

	public function test_check_rate_limit_separate_keys_dont_share_quota(): void {
		$ctrl = new TestableController();
		// Burn user_a; user_b should still be allowed.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $ctrl->check_rate_limit( 'user_a', 3, 60 ) );
		}
		$this->assertInstanceOf( \WP_Error::class, $ctrl->check_rate_limit( 'user_a', 3, 60 ) );
		$this->assertTrue( $ctrl->check_rate_limit( 'user_b', 3, 60 ) );
	}

	public function test_check_rate_limit_fails_open_when_cache_unavailable(): void {
		PerformanceControllerBase::set_cache( new FakeMemcached( fail_all: true ) );
		$ctrl = new TestableController();
		// Cache is unreachable; we should still allow the call (degraded > blocked).
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertTrue( $ctrl->check_rate_limit( 'user_x', 1, 60 ) );
		}
	}

	public function test_not_found_error_returns_consistent_shape(): void {
		$ctrl = new TestableController();
		$err = $ctrl->wrap_not_found( 'request rid=missing' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'rest_not_found', $err->get_error_code() );
		$this->assertSame( 404, $err->data['status'] ?? 0 );
		$this->assertStringContainsString( 'request rid=missing', $err->get_error_message() );
	}

	public function test_cache_lazy_factory_uses_substrate_config_when_uninjected(): void {
		// With set_cache(null), the next cache() call must build a real
		// Memcached_Cache from substrate config. Stuff an empty
		// `memcache_servers` directly into the substrate Config cache via
		// reflection so the assertion sees the empty-servers fixture
		// regardless of what env LogManagerTest tearDown or anyone else
		// may have left in place. Setting LOCAL_NEWSPACK_NODES_CONF here
		// would leak into later tests (RemoteManager picks up the test
		// config's `num_segments=2` and the test fixture's WP option
		// `=42` is overlaid by the app's load_config_defaults, breaking
		// downstream assertions).
		$config_prop = new \ReflectionProperty( \Newspack_Nodes\Config::class, 'config' );
		$config_prop->setAccessible( true );
		$original = $config_prop->getValue();
		try {
			$config_prop->setValue( null, [ 'memcache_servers' => [] ] );

			PerformanceControllerBase::set_cache( null );
			$cache = PerformanceControllerBase::cache();
			$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Memcached_Cache::class, $cache );
			$this->assertFalse( $cache->is_available(), 'empty memcache_servers means unavailable' );
		} finally {
			$config_prop->setValue( null, $original );
			\Newspack_Nodes\Config::reset();
		}
	}

	public function test_cache_returns_injected_instance_on_subsequent_calls(): void {
		// Once injected, cache() must return the same instance — no re-fetch.
		$fake = new FakeMemcached();
		PerformanceControllerBase::set_cache( $fake );
		$this->assertSame( $fake, PerformanceControllerBase::cache() );
		$this->assertSame( $fake, PerformanceControllerBase::cache() );
	}

	public function test_check_rate_limit_falls_back_to_window_when_ttl_underflows(): void {
		// The defensive `if ( $ttl < 1 ) $ttl = $window_s;` branch is unreachable
		// with sane inputs, but a negative window_s makes window_start land
		// ahead of $now and produces a sub-1 ttl. Real callers never pass a
		// negative window, but the defensive branch must still behave
		// correctly: the call should be allowed (fail-open within a single
		// window) and not blow up.
		$ctrl = new TestableController();
		$result = $ctrl->check_rate_limit( 'user_ttl', 5, -60 );
		$this->assertTrue( $result );
	}

	public function test_rate_limit_key_uses_user_id_when_logged_in(): void {
		$GLOBALS['_current_user_id'] = 99;
		$ctrl = new TestableController();
		$this->assertSame( 'user_99', $ctrl->wrap_rate_limit_key() );
		unset( $GLOBALS['_current_user_id'] );
	}

	public function test_rate_limit_key_uses_hashed_ip_for_anonymous(): void {
		// uid=0 means anonymous — must fall through to REMOTE_ADDR hash branch.
		$GLOBALS['_current_user_id'] = 0;
		$_SERVER['REMOTE_ADDR']      = '203.0.113.42';
		$ctrl = new TestableController();

		$key = $ctrl->wrap_rate_limit_key();
		$this->assertStringStartsWith( 'ip_', $key );
		// Hash prefix is deterministic — sha256('203.0.113.42')[:12].
		$expected = 'ip_' . \substr( \hash( 'sha256', '203.0.113.42' ), 0, 12 );
		$this->assertSame( $expected, $key );

		unset( $GLOBALS['_current_user_id'], $_SERVER['REMOTE_ADDR'] );
	}

	public function test_rate_limit_key_falls_back_to_unknown_when_no_remote_addr(): void {
		// Anonymous + REMOTE_ADDR unset — the `'unknown'` literal must be
		// hashed, not the result of accessing an undefined index.
		$GLOBALS['_current_user_id'] = 0;
		unset( $_SERVER['REMOTE_ADDR'] );
		$ctrl = new TestableController();

		$key      = $ctrl->wrap_rate_limit_key();
		$expected = 'ip_' . \substr( \hash( 'sha256', 'unknown' ), 0, 12 );
		$this->assertSame( $expected, $key );

		unset( $GLOBALS['_current_user_id'] );
	}

}

class TestableController extends PerformanceControllerBase {
	public function register_routes(): void {}
	public function wrap_not_found( string $what ): \WP_Error {
		return $this->not_found_error( $what );
	}
	public function wrap_rate_limit_key(): string {
		return $this->rate_limit_key();
	}
}
