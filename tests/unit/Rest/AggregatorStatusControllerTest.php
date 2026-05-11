<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\AggregatorStatusController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AggregatorStatusController::class )]
class AggregatorStatusControllerTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can']        = true;
		$GLOBALS['_current_user_id']         = 1;
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_actions']       = [];
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
		ServerRegistry::get_instance()->reset_cache();
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		ServerRegistry::get_instance()->reset_cache();
		$GLOBALS['_wp_actions'] = [];
		parent::tearDown();
	}

	public function test_register_routes_registers_status(): void {
		( new AggregatorStatusController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes-aggregator/v1/status', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_get_method_and_callback(): void {
		( new AggregatorStatusController() )->register_routes();
		$route = $GLOBALS['_rest_routes']['newspack-nodes-aggregator/v1/status'];
		$this->assertSame( 'GET', $route['methods'] );
		$this->assertIsCallable( $route['callback'] );
		$this->assertIsCallable( $route['permission_callback'] );
	}

	public function test_get_status_returns_empty_when_no_servers(): void {
		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( [], $resp->get_data() );
	}

	public function test_get_status_returns_per_server_partition_blocks(): void {
		// Seed a server in the WP option so the registry returns it.
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke1' => [
					'url'     => 'https://spoke.example/',
					'enabled' => true,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		// Seed memcache with a status entry for partition 0.
		$this->cache->set(
			'aggregator_status:spoke1:p0',
			[ 'state' => 'connected', 'lag' => 1234 ],
			60
		);

		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'spoke1', $body );

		$this->assertSame( 'spoke1', $body['spoke1']['id'] );
		// URL is escaped (esc_url_raw), but for a clean URL it stays the same.
		$this->assertStringStartsWith( 'https://spoke.example', $body['spoke1']['url'] );
		$this->assertTrue( $body['spoke1']['enabled'] );
		$this->assertArrayHasKey( 'partitions', $body['spoke1'] );
		$this->assertArrayHasKey( 0, $body['spoke1']['partitions'] );
		$this->assertSame( 'connected', $body['spoke1']['partitions'][0]['state'] );
		$this->assertSame( 1234, $body['spoke1']['partitions'][0]['lag'] );
	}

	public function test_get_status_uses_empty_partition_block_when_cache_miss(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke2' => [
					'url'     => 'https://other.example/',
					'enabled' => false,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$body = $resp->get_data();

		$this->assertArrayHasKey( 'spoke2', $body );
		// Cache miss for partition — defaults to empty array, never null.
		$this->assertSame( [], $body['spoke2']['partitions'][0] );
		// `enabled` is preserved.
		$this->assertFalse( $body['spoke2']['enabled'] );
	}

	public function test_get_status_clamps_num_partitions_to_max_16(): void {
		// Force num_partitions to 64; controller must clamp to 16.
		\add_filter(
			'newspack_nodes/config',
			static function ( array $cfg ): array {
				$cfg['num_partitions'] = 64;
				return $cfg;
			}
		);
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke3' => [
					'url'     => 'https://x.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'spoke3', $body );
		// Should report exactly 16 partition blocks, not 64.
		$this->assertCount( 16, $body['spoke3']['partitions'] );
	}

	public function test_get_status_clamps_num_partitions_min_1(): void {
		\add_filter(
			'newspack_nodes/config',
			static function ( array $cfg ): array {
				$cfg['num_partitions'] = 0; // invalid
				return $cfg;
			}
		);
		\update_option(
			ServerRegistry::OPTION_KEY,
			[ 'sp' => [ 'url' => 'https://x.example/', 'enabled' => true ] ]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertCount( 1, $body['sp']['partitions'] );
	}

	public function test_get_status_skips_non_array_server_entries(): void {
		// Hand-poisoned WP option with a non-array entry for one id.
		$GLOBALS['_wp_options'][ ServerRegistry::OPTION_KEY ] = [
			'good' => [ 'url' => 'https://ok.example/', 'enabled' => true ],
			'bad'  => 'not-an-array',
		];
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new AggregatorStatusController();
		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$body = $resp->get_data();
		// 'bad' is dropped during normalization in get_all(), so it never reaches us.
		$this->assertArrayHasKey( 'good', $body );
		$this->assertArrayNotHasKey( 'bad', $body );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new AggregatorStatusController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] ?? 0 );
	}

	public function test_get_status_returns_wp_error_when_rate_limited(): void {
		// Set a very low rate-limit and burn through it.
		$ctrl = new AggregatorStatusController();
		// Fake rate-limit exhaustion by pre-filling counter to a high value.
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$key          = 'newspack_nodes_rate:user_1:' . $window_start;
		// Set to >= 600 (default RATE_LIMIT_REQUESTS).
		$this->cache->set( $key, 1000, 70 );

		$resp = $ctrl->get_status( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}
}
