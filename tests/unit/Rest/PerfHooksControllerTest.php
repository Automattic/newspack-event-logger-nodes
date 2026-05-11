<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\HookCategorizer;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfHooksController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfHooksController::class )]
class PerfHooksControllerTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		// Reset HookCategorizer caches so tests see fresh state.
		HookCategorizer::clear_cache();
		// Provide a $wp_filter with a known hook so the categorizer has something
		// to categorize.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_filter;
		$wp_filter = [
			'init'           => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_init' ] ]; },
			'wp_loaded'      => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_wp_loaded' ] ]; },
			'admin_menu'     => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_admin_menu' ] ]; },
		];
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_filter;
		$wp_filter = [];
		HookCategorizer::clear_cache();
		parent::tearDown();
	}

	private function rate_limit_key( PerfHooksController $ctrl ): string {
		$ref = new \ReflectionMethod( $ctrl, 'rate_limit_key' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( $ctrl );
	}

	private function trip_rate_limit( PerfHooksController $ctrl ): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$key          = $this->rate_limit_key( $ctrl );
		$this->cache->set( "newspack_nodes_rate:{$key}:{$window_start}", 9999, 120 );
	}

	// ── register_routes ────────────────────────────────────────────────

	public function test_register_routes_registers_hooks_routes(): void {
		( new PerfHooksController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/registered-hooks', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/hook-categories', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_get_method(): void {
		( new PerfHooksController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/registered-hooks'];
		$this->assertSame( 'GET', $route['methods'] );
	}

	// ── get_registered_hooks ─────────────────────────────────────────────

	public function test_get_registered_hooks_returns_categorized_shape(): void {
		$resp = ( new PerfHooksController() )->get_registered_hooks( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'total_hooks', $body );
		$this->assertArrayHasKey( 'categories', $body );
		$this->assertArrayHasKey( 'hooks_by_category', $body );
	}

	public function test_get_registered_hooks_total_matches_summed_buckets(): void {
		$resp = ( new PerfHooksController() )->get_registered_hooks( new \WP_REST_Request() );
		$body = $resp->get_data();
		// Total must equal the sum of all hooks in every category bucket.
		$summed = 0;
		foreach ( $body['hooks_by_category'] as $bucket ) {
			$summed += \count( $bucket );
		}
		$this->assertSame( $body['total_hooks'], $summed );
	}

	public function test_get_registered_hooks_includes_seeded_hooks(): void {
		$body = ( new PerfHooksController() )->get_registered_hooks( new \WP_REST_Request() )->get_data();
		// All seeded hook names should appear somewhere in hooks_by_category.
		$all = [];
		foreach ( $body['hooks_by_category'] as $bucket ) {
			$all = \array_merge( $all, $bucket );
		}
		$this->assertContains( 'init', $all );
		$this->assertContains( 'wp_loaded', $all );
		$this->assertContains( 'admin_menu', $all );
	}

	// ── get_hook_categories ──────────────────────────────────────────────

	public function test_get_hook_categories_returns_categories_and_config(): void {
		$resp = ( new PerfHooksController() )->get_hook_categories( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'categories', $body );
		$this->assertArrayHasKey( 'config', $body );
		$this->assertIsArray( $body['categories'] );
		$this->assertIsArray( $body['config'] );
	}

	public function test_get_hook_categories_config_includes_patterns_and_colors(): void {
		$body = ( new PerfHooksController() )->get_hook_categories( new \WP_REST_Request() )->get_data();
		// Merged config shape (per HookCategorizer::get_merged_config).
		$this->assertArrayHasKey( 'colors', $body['config'] );
		$this->assertArrayHasKey( 'patterns', $body['config'] );
	}

	// ── permissions + rate limiting ─────────────────────────────────────

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfHooksController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_get_registered_hooks_returns_429_when_rate_limited(): void {
		$ctrl = new PerfHooksController();
		$this->trip_rate_limit( $ctrl );
		$resp = $ctrl->get_registered_hooks( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}

	public function test_get_hook_categories_returns_429_when_rate_limited(): void {
		$ctrl = new PerfHooksController();
		$this->trip_rate_limit( $ctrl );
		$resp = $ctrl->get_hook_categories( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}
}
