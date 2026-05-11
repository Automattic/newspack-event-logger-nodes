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

		\add_filter(
			'newspack_nodes/base_dir',
			fn () => $this->tmp
		);
		Config::reset();
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
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'init', 'wp_loaded', 'shutdown' ];
			$cfg['custom_events'] = [ 'my_event' => true ];
			return $cfg;
		} );

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
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'my_event', 'init' ];
			$cfg['custom_events'] = [ 'my_event' ];
			return $cfg;
		} );

		$ctrl = new DiscoveryController();
		$resp = $ctrl->get_discovery( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertSame( [ 'init' ], $body['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $body['custom_events'] );
	}

	public function test_extract_string_list_handles_assoc_arrays(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			// Mixed: some assoc, some indexed.
			$cfg['log_events']    = [ 'init' => true, 'shutdown' => true ];
			$cfg['custom_events'] = [];
			return $cfg;
		} );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		// Assoc-shape contributes the keys, not the bool values.
		$this->assertContains( 'init', $body['registered_hooks'] );
		$this->assertContains( 'shutdown', $body['registered_hooks'] );
	}

	public function test_extract_string_list_handles_indexed_arrays(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'a', 'b', 'c' ];
			$cfg['custom_events'] = [];
			return $cfg;
		} );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [ 'a', 'b', 'c' ], $body['registered_hooks'] );
	}

	public function test_extract_string_list_dedupes(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'init', 'shutdown', 'init' ];
			$cfg['custom_events'] = [];
			return $cfg;
		} );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		// Duplicates dropped.
		$this->assertSame( [ 'init', 'shutdown' ], $body['registered_hooks'] );
	}

	public function test_extract_string_list_drops_empty_values(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ '', 'init', '' ];
			$cfg['custom_events'] = [];
			return $cfg;
		} );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [ 'init' ], $body['registered_hooks'] );
	}

	public function test_extract_string_list_returns_empty_for_non_array(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = 'not-an-array';
			$cfg['custom_events'] = 42;
			return $cfg;
		} );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( [], $body['registered_hooks'] );
		$this->assertSame( [], $body['custom_events'] );
	}

	public function test_lag_omitted_when_no_readers_registered(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'init' ];
			$cfg['custom_events'] = [];
			return $cfg;
		} );
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertArrayNotHasKey( 'lag', $body );
	}

	public function test_lag_calculated_when_readers_present(): void {
		// Create the requests.log directory tree so Partition::get_segments() returns
		// something tangible. The lag math then sees a nonzero write position.
		$logs_dir = $this->tmp . '/logs/requests.log/p0';
		\mkdir( $logs_dir, 0755, true );
		// Drop a fake segment file so get_segments() returns a non-empty list.
		\file_put_contents( $logs_dir . '/0.log', \str_repeat( 'X', 100 ) );

		\add_filter(
			'newspack_event_logger_nodes/log_readers',
			static function ( $r ) {
				return [
					'request-workers' => [
						'inputs'  => [ 'requests.log' ],
						'outputs' => [],
					],
				];
			}
		);
		\add_filter(
			'newspack_event_logger_nodes/log_reader_positions',
			static function ( $p ) {
				// Reader at segment 0 offset 0 — full segment is unread.
				return [
					'request-workers' => [
						0 => [ 'requests.log' => [ 'seg' => 0, 'off' => 0 ] ],
					],
				];
			}
		);

		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertArrayHasKey( 'lag', $body );
		$this->assertIsInt( $body['lag'] );
		// Reader at segment 0 offset 0, writer at end of segment 0 (size 100).
		// Lag is exactly the unread bytes: max(0, 100 - 0) = 100.
		$this->assertSame( 100, $body['lag'] );
	}

	public function test_lag_calculated_zero_when_reader_caught_up(): void {
		// No segment file means an empty Partition; calculate_lag() short-circuits
		// for empty segments, so lag stays at 0 unless we synthesize a segment.
		$logs_dir = $this->tmp . '/logs/requests.log/p0';
		\mkdir( $logs_dir, 0755, true );

		\add_filter(
			'newspack_event_logger_nodes/log_readers',
			static function ( $r ) {
				return [
					'request-workers' => [
						'inputs'  => [ 'requests.log' ],
						'outputs' => [],
					],
				];
			}
		);

		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		// No segments → empty inputs → lag should be 0 (initialized) or omitted.
		// Function returns 0 here because readers are present.
		$this->assertSame( 0, $body['lag'] ?? null );
	}

	public function test_lag_skips_readers_with_no_inputs(): void {
		\add_filter(
			'newspack_event_logger_nodes/log_readers',
			static function ( $r ) {
				return [
					'no-inputs' => [
						'inputs'  => [],
						'outputs' => [ 'somewhere.log' ],
					],
				];
			}
		);
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		// Reader has no inputs — lag stays at 0.
		$this->assertSame( 0, $body['lag'] ?? null );
	}

	public function test_lag_skips_readers_with_empty_first_input(): void {
		\add_filter(
			'newspack_event_logger_nodes/log_readers',
			static function ( $r ) {
				return [
					'empty-input' => [
						'inputs'  => [ '' ], // Empty string first input.
						'outputs' => [],
					],
				];
			}
		);
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( 0, $body['lag'] ?? null );
	}

	public function test_lag_skips_readers_with_non_array_inputs(): void {
		\add_filter(
			'newspack_event_logger_nodes/log_readers',
			static function ( $r ) {
				return [
					'bad-input' => [
						'inputs'  => 'not-an-array',
						'outputs' => [],
					],
				];
			}
		);
		$ctrl = new DiscoveryController();
		$body = $ctrl->get_discovery( new \WP_REST_Request() )->get_data();
		$this->assertSame( 0, $body['lag'] ?? null );
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
