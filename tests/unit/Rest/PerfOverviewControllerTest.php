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
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
		// Pin to one partition so seeded fixtures match what the controller reads.
		\add_filter(
			'newspack_nodes/config',
			static fn( array $cfg ): array => \array_merge( $cfg, [
				'num_partitions' => 1,
				'max_lifespan'   => 86400,
			] )
		);
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		parent::tearDown();
	}

	private function store(): Stats_Store {
		return new Stats_Store( $this->cache, 0, 86400 );
	}

	private function rate_limit_key( PerfOverviewController $ctrl ): string {
		$ref = new \ReflectionMethod( $ctrl, 'rate_limit_key' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( $ctrl );
	}

	private function trip_rate_limit( PerfOverviewController $ctrl ): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$key          = $this->rate_limit_key( $ctrl );
		$this->cache->set( "newspack_nodes_rate:{$key}:{$window_start}", 9999, 120 );
	}

	private function seed_url_bucket( array $hash_to_stats, ?string $bucket = null ): string {
		$bucket = $bucket ?? $this->store()->current_url_bucket();
		$this->store()->set_url_index_hourly( $bucket, $hash_to_stats );
		return $bucket;
	}

	// ── register_routes ────────────────────────────────────────────────

	public function test_register_routes_registers_overview(): void {
		( new PerfOverviewController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/overview', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_args_documented(): void {
		( new PerfOverviewController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/overview']['args'];
		$this->assertArrayHasKey( 'breakdown', $args );
		$this->assertArrayHasKey( 'server', $args );
		$this->assertArrayHasKey( 'categories', $args );
		$this->assertSame( 'string', $args['breakdown']['type'] );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertSame( 'boolean', $args['categories']['type'] );
		$this->assertFalse( $args['categories']['default'] );
	}

	public function test_categories_sanitize_callback(): void {
		( new PerfOverviewController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/overview']['args']['categories']['sanitize_callback'];
		$this->assertTrue( $cb( 'true' ) );
		$this->assertTrue( $cb( 'yes' ) );
		$this->assertFalse( $cb( '0' ) );
	}

	public function test_dimensions_constant_includes_known_dims(): void {
		// Pinned because clients enumerate this list in autocomplete UI.
		$this->assertSame(
			[ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ],
			PerfOverviewController::DIMENSIONS
		);
	}

	// ── get_overview: shape (empty) ─────────────────────────────────────

	public function test_get_overview_documented_shape_when_empty(): void {
		$ctrl = new PerfOverviewController();
		$resp = $ctrl->get_overview( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		foreach ( [
			'total_urls', 'total_requests', 'global_avg_ms', 'global_avg_peak_mb',
			'slowest_urls', 'most_requested', 'aggregate_time_series', 'global_leaderboard',
		] as $key ) {
			$this->assertArrayHasKey( $key, $body );
		}
		$this->assertSame( 0, $body['total_urls'] );
		$this->assertSame( 0, $body['total_requests'] );
		// No division by zero — averages are 0.0 when no data.
		$this->assertSame( 0.0, $body['global_avg_ms'] );
		$this->assertSame( 0.0, $body['global_avg_peak_mb'] );
		$this->assertSame( [], $body['slowest_urls'] );
		$this->assertSame( [], $body['most_requested'] );
		$this->assertSame( [], $body['aggregate_time_series'] );
		// No breakdown / categories requested — those keys absent.
		$this->assertArrayNotHasKey( 'breakdown_time_series', $body );
		$this->assertArrayNotHasKey( 'breakdowns', $body );
		$this->assertArrayNotHasKey( 'category_time_series', $body );
	}

	// ── get_overview: aggregates ─────────────────────────────────────────

	public function test_get_overview_aggregates_hourly_buckets(): void {
		$store = $this->store();
		$store->bump_hourly( 0.5, 12.3 );
		$store->bump_hourly( 1.0, 16.0 );

		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertSame( 2, $body['total_requests'] );
		// Two requests: 500ms + 1000ms = 1500ms / 2 = 750.
		$this->assertEqualsWithDelta( 750.0, $body['global_avg_ms'], 0.01 );
		// 12.3 + 16.0 = 28.3 / 2 = 14.15.
		$this->assertEqualsWithDelta( 14.15, $body['global_avg_peak_mb'], 0.01 );
	}

	public function test_get_overview_total_urls_and_slowest_most_requested(): void {
		$now = \time();
		// p95 ranking: c (200) > a (40) > b (10).  count ranking: b (25) > a (10) > c (5).
		$this->seed_url_bucket( [
			'aaaaaaaa1111' => [ 'url' => '/a', 'count' => 10, 'sum_ms' => 200, 'p95_ms' => 40,  'last_seen' => $now ],
			'bbbbbbbb2222' => [ 'url' => '/b', 'count' => 25, 'sum_ms' => 250, 'p95_ms' => 10,  'last_seen' => $now ],
			'cccccccc3333' => [ 'url' => '/c', 'count' => 5,  'sum_ms' => 500, 'p95_ms' => 200, 'last_seen' => $now ],
		] );

		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertSame( 3, $body['total_urls'] );
		$this->assertCount( 3, $body['slowest_urls'] );
		// slowest = sorted by p95 desc.
		$this->assertSame( 'cccccccc3333', $body['slowest_urls'][0]['hash'] );
		$this->assertSame( 'aaaaaaaa1111', $body['slowest_urls'][1]['hash'] );
		// most_requested = native index order (count desc).
		$this->assertSame( 'bbbbbbbb2222', $body['most_requested'][0]['hash'] );
	}

	public function test_get_overview_top_lists_capped_at_10(): void {
		$now  = \time();
		$seed = [];
		for ( $i = 0; $i < 15; $i++ ) {
			$h          = \str_pad( "h{$i}", 12, 'a' );
			$seed[ $h ] = [ 'url' => "/u{$i}", 'count' => 100 - $i, 'sum_ms' => ( 100 - $i ) * 50, 'p95_ms' => ( 100 - $i ) * 2, 'last_seen' => $now ];
		}
		$this->seed_url_bucket( $seed );

		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertCount( 10, $body['slowest_urls'] );
		$this->assertCount( 10, $body['most_requested'] );
		$this->assertSame( 15, $body['total_urls'] );
	}

	// ── get_overview: breakdown shape switch (single vs multi) ───────────

	public function test_get_overview_single_breakdown_returns_flat_breakdown_time_series(): void {
		$this->store()->set_dimensional( 'status', [
			'2026-01-01-12-00' => [ '200' => [ 'c' => 10, 's' => 100.0, 'm' => 0.0 ] ],
		] );
		$req = new \WP_REST_Request();
		$req->set_param( 'breakdown', 'status' );

		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		// SHAPE PIN: 1 dim → flat `breakdown_time_series`, no `breakdowns` key.
		$this->assertArrayHasKey( 'breakdown_time_series', $body );
		$this->assertArrayNotHasKey( 'breakdowns', $body );
		$this->assertArrayHasKey( '2026-01-01-12-00', $body['breakdown_time_series'] );
		$this->assertSame( 10, $body['breakdown_time_series']['2026-01-01-12-00']['200']['c'] );
	}

	public function test_get_overview_multi_breakdown_returns_nested_breakdowns_map(): void {
		$this->store()->set_dimensional( 'status', [
			'2026-01-01-12-00' => [ '200' => [ 'c' => 7, 's' => 70.0, 'm' => 0.0 ] ],
		] );
		$this->store()->set_dimensional( 'method', [
			'2026-01-01-12-00' => [ 'GET' => [ 'c' => 7, 's' => 70.0, 'm' => 0.0 ] ],
		] );
		$req = new \WP_REST_Request();
		$req->set_param( 'breakdown', 'status,method' );

		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		// SHAPE PIN: 2+ dims → `breakdowns` map, NO flat `breakdown_time_series`.
		$this->assertArrayHasKey( 'breakdowns', $body );
		$this->assertArrayNotHasKey( 'breakdown_time_series', $body );
		$this->assertArrayHasKey( 'status', $body['breakdowns'] );
		$this->assertArrayHasKey( 'method', $body['breakdowns'] );
		$this->assertSame( 7, $body['breakdowns']['status']['2026-01-01-12-00']['200']['c'] );
		$this->assertSame( 7, $body['breakdowns']['method']['2026-01-01-12-00']['GET']['c'] );
	}

	public function test_get_overview_invalid_breakdown_dim_is_dropped(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'breakdown', 'banana' );
		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		// All dims invalid → no breakdown_time_series, no breakdowns.
		$this->assertArrayNotHasKey( 'breakdown_time_series', $body );
		$this->assertArrayNotHasKey( 'breakdowns', $body );
	}

	public function test_get_overview_mixed_valid_and_invalid_breakdown(): void {
		$this->store()->set_dimensional( 'status', [
			'2026-01-01-12-00' => [ '200' => [ 'c' => 1, 's' => 5.0, 'm' => 0.0 ] ],
		] );
		$req = new \WP_REST_Request();
		$req->set_param( 'breakdown', 'status,banana' );
		// One valid dim survives → flat shape (single dim).
		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		$this->assertArrayHasKey( 'breakdown_time_series', $body );
		$this->assertArrayNotHasKey( 'breakdowns', $body );
	}

	// ── get_overview: leaderboards ───────────────────────────────────────

	public function test_get_overview_global_leaderboard_aggregates_buckets(): void {
		$bucket = $this->store()->current_url_bucket();
		$this->store()->set_leaderboard_bucket( $bucket, [
			'count'        => 5,
			'sum_req_time' => 2.0,
			'categories'   => [
				'db' => [ 'samples' => 5, 'sum_time' => 0.5, 'sum_count' => 25, 'entries' => [] ],
			],
		] );

		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertSame( 5, $body['global_leaderboard']['count'] );
		$this->assertEqualsWithDelta( 0.4, $body['global_leaderboard']['total_time'], 0.01 );
		$this->assertArrayHasKey( 'db', $body['global_leaderboard']['categories'] );
		$this->assertEqualsWithDelta( 0.1, $body['global_leaderboard']['categories']['db']['time'], 0.01 ); // 0.5 / 5
		$this->assertEqualsWithDelta( 5.0, $body['global_leaderboard']['categories']['db']['count'], 0.01 ); // 25 / 5
	}

	public function test_get_overview_server_param_uses_server_leaderboard(): void {
		$bucket = $this->store()->current_url_bucket();
		$this->store()->set_server_leaderboard_bucket( 'host-a', $bucket, [
			'count'        => 3,
			'sum_req_time' => 1.5,
			'categories'   => [],
		] );
		// Global leaderboard has different data — make sure server scope wins.
		$this->store()->set_leaderboard_bucket( $bucket, [
			'count'        => 999,
			'sum_req_time' => 999.0,
			'categories'   => [],
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'server', 'host-a' );
		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		$this->assertSame( 3, $body['global_leaderboard']['count'] );
		$this->assertEqualsWithDelta( 0.5, $body['global_leaderboard']['total_time'], 0.01 );
	}

	public function test_get_overview_unknown_server_leaderboard_zero(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'server', 'no-such-host' );
		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		$this->assertSame( 0, $body['global_leaderboard']['count'] );
	}

	// ── get_overview: categories ─────────────────────────────────────────

	public function test_get_overview_categories_param_returns_global_category_time_series(): void {
		$this->store()->set_categories( [
			'2026-01-01-12-00' => [
				'db'  => [ 't' => 100.0, 'c' => 5.0, 'n' => 1 ],
				'ext' => [ 't' => 200.0, 'c' => 10.0, 'n' => 1 ],
			],
		] );
		$req = new \WP_REST_Request();
		$req->set_param( 'categories', true );

		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		$this->assertArrayHasKey( 'category_time_series', $body );
		$this->assertEqualsWithDelta( 100.0, $body['category_time_series']['2026-01-01-12-00']['db']['t'], 0.01 );
	}

	public function test_get_overview_categories_with_server_returns_server_category_series(): void {
		$this->store()->set_server_categories( 'host-a', [
			'2026-01-01-13-00' => [
				'db' => [ 't' => 300.0, 'c' => 15.0, 'n' => 1 ],
			],
		] );
		// Global must not leak through when server scoped.
		$this->store()->set_categories( [
			'2026-01-01-13-00' => [ 'db' => [ 't' => 9999.0, 'c' => 99.0, 'n' => 99 ] ],
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'categories', true );
		$req->set_param( 'server', 'host-a' );
		$body = ( new PerfOverviewController() )->get_overview( $req )->get_data();
		$this->assertEqualsWithDelta( 300.0, $body['category_time_series']['2026-01-01-13-00']['db']['t'], 0.01 );
	}

	// ── get_overview: load_index dual schema (FlameBuilder + StatsAggregator) ─

	public function test_load_index_handles_sum_req_time_seconds_alternate_shape(): void {
		// FlameBuilder schema uses `sum_ms`. StatsAggregator schema uses
		// `sum_req_time` (in seconds). Controller must accept both.
		$now = \time();
		$this->seed_url_bucket( [
			'sumreqtime00' => [
				'url'         => '/x',
				'count'       => 4,
				'sum_req_time' => 2.0,  // seconds → 2000 ms total → 500 avg.
				'last_seen'   => $now,
			],
		] );

		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertSame( 1, $body['total_urls'] );
		$this->assertEqualsWithDelta( 500.0, $body['most_requested'][0]['avg_ms'], 0.01 );
	}

	public function test_load_index_url_keyed_without_url_field(): void {
		// Some legacy bucket entries are keyed by URL string (no inner 'url' field).
		$now    = \time();
		$bucket = $this->store()->current_url_bucket();
		$this->store()->set_url_index_hourly( $bucket, [
			'/legacy-shape' => [
				// no `url` field → controller hashes the key for the synthesized hash.
				'count'  => 3,
				'sum_ms' => 90.0,
				'last_seen' => $now,
			],
		] );
		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertSame( 1, $body['total_urls'] );
		$this->assertSame( '/legacy-shape', $body['most_requested'][0]['url'] );
		$this->assertSame( 12, \strlen( $body['most_requested'][0]['hash'] ) );
	}

	public function test_load_index_min_ms_takes_minimum_across_entries(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'minmstest000' => [
				'url' => '/x', 'count' => 1, 'sum_ms' => 50.0,
				'min_ms' => 5.0, 'max_ms' => 50.0, 'last_seen' => $now,
			],
		] );
		$body = ( new PerfOverviewController() )->get_overview( new \WP_REST_Request() )->get_data();
		$this->assertEqualsWithDelta( 5.0, $body['most_requested'][0]['min_ms'], 0.01 );
		$this->assertEqualsWithDelta( 50.0, $body['most_requested'][0]['max_ms'], 0.01 );
	}

	// ── permissions + rate limit ─────────────────────────────────────────

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfOverviewController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_get_overview_returns_429_when_rate_limited(): void {
		$ctrl = new PerfOverviewController();
		$this->trip_rate_limit( $ctrl );
		$resp = $ctrl->get_overview( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}
}
