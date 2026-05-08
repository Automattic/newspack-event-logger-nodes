<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfOverviewController;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfOverviewController::class )]
class PerfOverviewControllerTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_register_routes_registers_overview(): void {
		( new PerfOverviewController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/overview', $GLOBALS['_rest_routes'] );
	}

	public function test_get_overview_returns_documented_shape_when_empty(): void {
		$ctrl = new PerfOverviewController();
		$resp = $ctrl->get_overview( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		foreach ( [ 'total_urls', 'total_requests', 'global_avg_ms', 'slowest_urls', 'most_requested', 'aggregate_time_series', 'global_leaderboard' ] as $key ) {
			$this->assertArrayHasKey( $key, $body );
		}
		$this->assertSame( 0, $body['total_requests'] );
	}

	public function test_get_overview_aggregates_hourly_buckets(): void {
		$store = new Stats_Store( $this->cache, 0, 86400 );
		$store->bump_hourly( 0.5, 12.3 );
		$store->bump_hourly( 1.0, 16.0 );

		$ctrl = new PerfOverviewController();
		$resp = $ctrl->get_overview( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertGreaterThan( 0, $body['total_requests'] );
	}

	public function test_breakdown_param_adds_breakdown_time_series(): void {
		$ctrl = new PerfOverviewController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'breakdown', 'status' );
		$resp = $ctrl->get_overview( $req );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'breakdown_time_series', $body );
	}

	public function test_categories_param_adds_category_time_series(): void {
		$ctrl = new PerfOverviewController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'categories', true );
		$resp = $ctrl->get_overview( $req );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'category_time_series', $body );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfOverviewController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
