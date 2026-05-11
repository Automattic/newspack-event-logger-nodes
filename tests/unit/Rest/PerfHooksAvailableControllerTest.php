<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\HookCategorizer;
use Newspack_Event_Logger_Nodes\Rest\PerfHooksAvailableController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfHooksAvailableController::class )]
class PerfHooksAvailableControllerTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		HookCategorizer::clear_cache();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];

		$this->cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];
		HookCategorizer::clear_cache();
		parent::tearDown();
	}

	private function rate_limit_key( PerfHooksAvailableController $ctrl ): string {
		$ref = new \ReflectionMethod( $ctrl, 'rate_limit_key' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( $ctrl );
	}

	private function trip_rate_limit( PerfHooksAvailableController $ctrl ): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$key          = $this->rate_limit_key( $ctrl );
		$this->cache->set( "newspack_nodes_rate:{$key}:{$window_start}", 9999, 120 );
	}

	// ── register_routes ────────────────────────────────────────────────

	public function test_register_routes_registers_hooks_endpoints(): void {
		( new PerfHooksAvailableController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/hooks/available', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/hooks/configure', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_correct_methods(): void {
		( new PerfHooksAvailableController() )->register_routes();
		$this->assertSame( 'GET',  $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/hooks/available']['methods'] );
		$this->assertSame( 'POST', $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/hooks/configure']['methods'] );
	}

	// ── get_available_hooks ──────────────────────────────────────────────

	public function test_get_available_hooks_reads_wp_actions(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'init' => 1, 'wp_loaded' => 2 ];
		$wp_filter  = [];

		$body = ( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data();
		$this->assertArrayHasKey( 'hooks', $body );
		$names = \array_column( $body['hooks'], 'name' );
		$this->assertContains( 'init', $names );
		$this->assertContains( 'wp_loaded', $names );

		// Each hook entry should expose name/category/count.
		foreach ( $body['hooks'] as $hook ) {
			$this->assertArrayHasKey( 'name', $hook );
			$this->assertArrayHasKey( 'category', $hook );
			$this->assertArrayHasKey( 'count', $hook );
		}
	}

	public function test_get_available_hooks_reads_wp_filter_for_unfired_hooks(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [
			'never_fired_filter' => new class { public array $callbacks = [ [ 'cb' => 'x' ] ]; },
		];

		$body = ( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data();
		$names = \array_column( $body['hooks'], 'name' );
		$this->assertContains( 'never_fired_filter', $names );
		// count is 0 since it's not in wp_actions.
		foreach ( $body['hooks'] as $hook ) {
			if ( 'never_fired_filter' === $hook['name'] ) {
				$this->assertSame( 0, $hook['count'] );
			}
		}
	}

	public function test_get_available_hooks_count_from_wp_actions_takes_precedence(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'init' => 5 ];
		$wp_filter  = [
			'init' => new class { public array $callbacks = [ [ 'cb' => 'x' ] ]; },
		];

		$body = ( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data();
		foreach ( $body['hooks'] as $hook ) {
			if ( 'init' === $hook['name'] ) {
				$this->assertSame( 5, $hook['count'] );
			}
		}
	}

	public function test_get_available_hooks_excludes_custom_events_via_config(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'my_custom_event' => 1, 'init' => 2 ];
		$wp_filter  = [];

		// PerformanceControllerBase::load_config() reads via the
		// `newspack_nodes/config` filter — that's how callers inject the
		// custom_events list at runtime.
		\add_filter(
			'newspack_nodes/config',
			static fn( array $cfg ): array => \array_merge( $cfg, [
				'custom_events' => [ 'my_custom_event' => true ],
			] )
		);

		$body  = ( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data();
		$names = \array_column( $body['hooks'], 'name' );
		$this->assertNotContains( 'my_custom_event', $names );
		$this->assertContains( 'init', $names );

		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
	}

	public function test_get_available_hooks_custom_events_handles_indexed_array_form(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'event_a' => 1, 'event_b' => 1 ];
		$wp_filter  = [];

		// Indexed array form: name lives in the value, not the key.
		\add_filter(
			'newspack_nodes/config',
			static fn( array $cfg ): array => \array_merge( $cfg, [
				'custom_events' => [ 'event_a' ],
			] )
		);

		$names = \array_column(
			( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data()['hooks'],
			'name'
		);
		$this->assertNotContains( 'event_a', $names );
		$this->assertContains( 'event_b', $names );

		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
	}

	public function test_get_available_hooks_returns_sorted_by_name(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'zeta' => 1, 'alpha' => 1, 'mu' => 1 ];
		$wp_filter  = [];

		$body  = ( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data();
		$names = \array_column( $body['hooks'], 'name' );
		$this->assertSame( [ 'alpha', 'mu', 'zeta' ], $names );
	}

	public function test_get_available_hooks_handles_no_hooks_at_all(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];

		$body = ( new PerfHooksAvailableController() )->get_available_hooks( new \WP_REST_Request() )->get_data();
		$this->assertSame( [], $body['hooks'] );
	}

	// ── configure_hooks ──────────────────────────────────────────────────

	public function test_configure_hooks_writes_log_events_and_custom_events(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'hooks', [ 'init', 'wp_loaded' ] );
		$req->set_param( 'custom_events', [ 'my_event' ] );

		$resp = ( new PerfHooksAvailableController() )->configure_hooks( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 3, $body['hooks_configured'] );
		$this->assertSame( [ 'init', 'wp_loaded' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
		$this->assertSame( [ 'my_event' => true ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] );
	}

	public function test_configure_hooks_sanitizes_strings_skips_empty_and_non_strings(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'hooks', [ 'init', '', 12345, '<b>raw</b>' ] );
		$req->set_param( 'custom_events', null );

		$body = ( new PerfHooksAvailableController() )->configure_hooks( $req )->get_data();
		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( [ 'init', 'raw' ], $saved );
		$this->assertSame( 2, $body['hooks_configured'] );
	}

	public function test_configure_hooks_with_only_custom_events(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'hooks', null );
		$req->set_param( 'custom_events', [ 'event_one', 'event_two' ] );

		$body = ( new PerfHooksAvailableController() )->configure_hooks( $req )->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 2, $body['hooks_configured'] );
		$this->assertSame(
			[ 'event_one' => true, 'event_two' => true ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events']
		);
	}

	public function test_configure_hooks_with_no_data(): void {
		$req  = new \WP_REST_Request();
		$body = ( new PerfHooksAvailableController() )->configure_hooks( $req )->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 0, $body['hooks_configured'] );
	}

	// ── permissions + rate limiting ─────────────────────────────────────

	public function test_admin_permissions_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfHooksAvailableController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] ?? 0 );
	}

	public function test_admin_permissions_check_passes_for_admin(): void {
		$GLOBALS['_current_user_can'] = true;
		$result = ( new PerfHooksAvailableController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function test_get_available_hooks_returns_429_when_rate_limited(): void {
		$ctrl = new PerfHooksAvailableController();
		$this->trip_rate_limit( $ctrl );
		$resp = $ctrl->get_available_hooks( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}

	public function test_configure_hooks_returns_429_when_rate_limited(): void {
		$ctrl = new PerfHooksAvailableController();
		$this->trip_rate_limit( $ctrl );
		$resp = $ctrl->configure_hooks( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}
}
