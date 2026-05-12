<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rest\DiscoveryController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Partition;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DiscoveryController::class )]
class DiscoveryControllerTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']             = [];
		$GLOBALS['_current_user_can']        = true;
		$GLOBALS['_current_user_id']         = 1;
		$GLOBALS['_wp_actions']       = [];
		$GLOBALS['_wp_options']       = [];
		PerformanceControllerBase::set_cache( new FakeMemcached() );

		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS.
		$this->tmp = '/tmp/discovery-controller-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );

		$this->use_base_dir( $this->tmp );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions'] = [];
		$this->rmdir_recursive( $this->tmp );
		Config::reset();
		parent::tearDown();
	}

	public function test_register_routes_registers_discovery_endpoint(): void {
		( new DiscoveryController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/discovery', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_get_method(): void {
		( new DiscoveryController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes/v1/discovery'];
		$this->assertSame( 'GET', $route['methods'] );
		$this->assertIsCallable( $route['callback'] );
		$this->assertIsCallable( $route['permission_callback'] );
	}

	public function test_get_discovery_returns_registered_hooks_and_custom_events(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => [ 'init', 'wp_loaded', 'shutdown' ],
			'custom_events' => [ 'my_event' => true ],
		] );

		$ctrl = new DiscoveryController();
		$resp = $ctrl->get_discovery( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();

		$this->assertArrayHasKey( 'registered_hooks', $body );
		$this->assertArrayHasKey( 'custom_events', $body );
		$this->assertSame( [ 'init', 'wp_loaded', 'shutdown' ], $body['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $body['custom_events'] );
	}

	public function test_custom_events_filtered_out_of_registered_hooks(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => [ 'my_event', 'init' ],
			'custom_events' => [ 'my_event' ],
		] );

		$ctrl = new DiscoveryController();
		$resp = $ctrl->get_discovery( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertSame( [ 'init' ], $body['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $body['custom_events'] );
	}

	public function test_extract_string_list_handles_assoc_arrays(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => [ 'init' => true, 'shutdown' => true ],
			'custom_events' => [],
		] );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertContains( 'init', $body['registered_hooks'] );
		$this->assertContains( 'shutdown', $body['registered_hooks'] );
	}

	public function test_extract_string_list_handles_indexed_arrays(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => [ 'a', 'b', 'c' ],
			'custom_events' => [],
		] );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [ 'a', 'b', 'c' ], $body['registered_hooks'] );
	}

	public function test_extract_string_list_dedupes(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => [ 'init', 'shutdown', 'init' ],
			'custom_events' => [],
		] );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [ 'init', 'shutdown' ], $body['registered_hooks'] );
	}

	public function test_extract_string_list_drops_empty_values(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => [ '', 'init', '' ],
			'custom_events' => [],
		] );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [ 'init' ], $body['registered_hooks'] );
	}

	public function test_extract_string_list_returns_empty_for_non_array(): void {
		$this->use_base_dir( $this->tmp, [
			'log_events'    => 'not-an-array',
			'custom_events' => 42,
		] );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [], $body['registered_hooks'] );
		$this->assertSame( [], $body['custom_events'] );
	}

	public function test_response_does_not_include_lag(): void {
		// The lag field was driven by the deleted log_readers /
		// log_reader_positions filters. Discovery no longer reports it.
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertArrayNotHasKey( 'lag', $body );
	}

	public function test_get_discovery_returns_wp_error_when_rate_limited(): void {
		$cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $cache );
		// Pre-poison the rate-limit counter beyond the default quota.
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$cache->set( 'newspack_nodes_rate:user_1:' . $window_start, 1000, 70 );

		$ctrl = new DiscoveryController();
		$resp = $ctrl->get_discovery( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new DiscoveryController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}
}
