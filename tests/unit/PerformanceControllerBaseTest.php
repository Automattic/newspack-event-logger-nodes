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

	public function test_load_config_returns_documented_defaults(): void {
		$config = PerformanceControllerBase::load_config();
		// Substrate config (baseline test config) overlays these; values
		// here reflect the merged result rather than the static defaults.
		$this->assertSame( 1, $config['num_partitions'] );
		$this->assertIsInt( $config['max_lifespan'] );
		$this->assertIsArray( $config['memcache_servers'] );
		$this->assertIsString( $config['base_directory'] );
		$this->assertIsArray( $config['aggregator_servers'] );
		$this->assertFalse( $config['enable_workers'] );
	}

}

class TestableController extends PerformanceControllerBase {
	public function register_routes(): void {}
	public function wrap_not_found( string $what ): \WP_Error {
		return $this->not_found_error( $what );
	}
}
