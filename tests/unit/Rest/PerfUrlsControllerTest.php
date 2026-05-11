<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfUrlsController;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfUrlsController::class )]
class PerfUrlsControllerTest extends TestCase {
	private FakeMemcached $cache;
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		// Drop any leftover filters from sibling tests.
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		// Substrate Config caches; reset so each test sees its own filter overlay.
		\Newspack_Nodes\Config::reset();
		$this->cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
		$this->tmp = $this->make_temp_dir();
		\add_filter(
			'newspack_nodes/config',
			fn( array $cfg ): array => \array_merge( $cfg, [
				'num_partitions' => 1,
				'base_directory' => $this->tmp,
				'max_lifespan'   => 86400,
			] )
		);
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// ── helpers ─────────────────────────────────────────────────────────

	private function store(): Stats_Store {
		return new Stats_Store( $this->cache, 0, 86400 );
	}

	/**
	 * Seed the URL index in the current 5-min bucket with one or more URL stat blobs.
	 * Each entry shape mirrors what FlameBuilder writes:
	 *   $hash => [ 'url' => ..., 'count', 'sum_ms', 'min_ms', 'max_ms', 'p50_ms', 'p95_ms', 'p99_ms', 'sum_peak_mb', 'max_peak_mb', 'last_seen' ]
	 */
	private function seed_url_bucket( array $hash_to_stats, ?string $bucket = null ): string {
		$bucket = $bucket ?? $this->store()->current_url_bucket();
		$this->store()->set_url_index_hourly( $bucket, $hash_to_stats );
		return $bucket;
	}

	/**
	 * Write a v4 fixed-width index entry to {tmp}/logs/requests.log/p{partition}/0.idx,
	 * with a packed Message in the matching .log file at the recorded offset+length
	 * so `read_at()` returns valid data for the request body.
	 */
	private function write_request_to_partition( int $partition, string $rid, string $url_hash, array $body ): void {
		$dir = "{$this->tmp}/logs/requests.log/p{$partition}";
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0755, true );
		}

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$msg[ Message::KEY ]       = $body['url'] ?? '';
		$msg[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $msg ) . "\n";
		$len                       = \strlen( $packed );
		$offset                    = \file_exists( "{$dir}/0.log" ) ? (int) \filesize( "{$dir}/0.log" ) : 0;
		\file_put_contents( "{$dir}/0.log", $packed, \FILE_APPEND );

		// v4 idx: 32 rid + 12 url_hash + 10 timestamp + 8 dur + 3 status + 6 segid + 10 offset + 8 length + 6 peak + 1 method + 1 error_status.
		$timestamp    = (int) ( $body['timestamp'] ?? \time() );
		$duration_ms  = (int) ( $body['duration_ms'] ?? 0 );
		$status_code  = (int) ( $body['status_code'] ?? 200 );
		$peak_mb      = (int) \round( (float) ( $body['peak_mb'] ?? 0 ) );
		$method       = ( $body['request_method'] ?? 'GET' )[0] ?? 'G';
		$error_status = (string) ( $body['error_status'] ?? '-' );

		$idx = \str_pad( \substr( $rid, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash, 0, 12 ), 12 )
			. \str_pad( (string) $timestamp, 10, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $duration_ms, 8, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $status_code, 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $offset, 10, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $len, 8, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $peak_mb, 6, '0', \STR_PAD_LEFT )
			. $method
			. $error_status
			. "\n";
		\file_put_contents( "{$dir}/0.idx", $idx, \FILE_APPEND );
	}

	private function get_urls_request( array $params = [] ): \WP_REST_Request {
		$req = new \WP_REST_Request();
		$req->set_param( 'sort',   $params['sort']   ?? 'count' );
		$req->set_param( 'order',  $params['order']  ?? 'desc' );
		$req->set_param( 'limit',  $params['limit']  ?? 50 );
		$req->set_param( 'offset', $params['offset'] ?? 0 );
		$req->set_param( 'search', $params['search'] ?? '' );
		$req->set_param( 'server', $params['server'] ?? '' );
		return $req;
	}

	// ── register_routes ────────────────────────────────────────────────

	public function test_register_routes_registers_urls_endpoints(): void {
		( new PerfUrlsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/urls', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/urls/(?P<hash>[a-f0-9]{8,64})', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_urls_args_have_documented_defaults(): void {
		( new PerfUrlsController() )->register_routes();
		$args = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/urls']['args'];
		$this->assertSame( 'count', $args['sort']['default'] );
		$this->assertSame( 'desc',  $args['order']['default'] );
		$this->assertSame( 50,      $args['limit']['default'] );
		$this->assertSame( 0,       $args['offset']['default'] );
		$this->assertSame( '',      $args['search']['default'] );
		$this->assertSame( '',      $args['server']['default'] );
	}

	public function test_limit_sanitize_caps_at_1000_and_floor_at_1(): void {
		( new PerfUrlsController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/urls']['args']['limit']['sanitize_callback'];
		$this->assertSame( 50,   $cb( 50 ) );
		$this->assertSame( 1000, $cb( 5000 ) );
		$this->assertSame( 1,    $cb( 0 ) );
		$this->assertSame( 1,    $cb( -10 ) );
	}

	public function test_offset_sanitize_caps_at_10000_and_floor_at_0(): void {
		( new PerfUrlsController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/urls']['args']['offset']['sanitize_callback'];
		$this->assertSame( 100,    $cb( 100 ) );
		$this->assertSame( 10000,  $cb( 99999 ) );
		$this->assertSame( 0,      $cb( -50 ) );
	}

	public function test_categories_sanitize_callback_filters_var(): void {
		( new PerfUrlsController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/urls/(?P<hash>[a-f0-9]{8,64})']['args']['categories']['sanitize_callback'];
		$this->assertTrue( $cb( 'true' ) );
		$this->assertTrue( $cb( '1' ) );
		$this->assertFalse( $cb( '0' ) );
		$this->assertFalse( $cb( 'false' ) );
	}

	// ── get_urls: shape + pagination + sort + filter ────────────────────

	public function test_get_urls_returns_paginated_shape(): void {
		$resp = ( new PerfUrlsController() )->get_urls( $this->get_urls_request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertArrayHasKey( 'total', $body );
		$this->assertArrayHasKey( 'limit', $body );
		$this->assertArrayHasKey( 'offset', $body );
		$this->assertSame( 50, $body['limit'] );
		$this->assertSame( 0,  $body['offset'] );
	}

	public function test_get_urls_aggregates_seeded_index(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => 'https://example.com/a', 'count' => 10, 'sum_ms' => 200, 'min_ms' => 5,  'max_ms' => 50,  'p95_ms' => 40,  'sum_peak_mb' => 50, 'max_peak_mb' => 8, 'last_seen' => $now ],
			'bbbbbbbbbbbb' => [ 'url' => 'https://example.com/b', 'count' => 25, 'sum_ms' => 750, 'min_ms' => 10, 'max_ms' => 80,  'p95_ms' => 60,  'sum_peak_mb' => 75, 'max_peak_mb' => 12, 'last_seen' => $now ],
			'cccccccccccc' => [ 'url' => 'https://api.example.com/c', 'count' => 5, 'sum_ms' => 500, 'min_ms' => 50, 'max_ms' => 200, 'p95_ms' => 150, 'sum_peak_mb' => 25, 'max_peak_mb' => 6, 'last_seen' => $now ],
		] );

		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request() )->get_data();
		$this->assertSame( 3, $body['total'] );
		$this->assertCount( 3, $body['data'] );
		// Default sort=count desc: bbbb (25) > aaaa (10) > cccc (5).
		$this->assertSame( 'bbbbbbbbbbbb', $body['data'][0]['hash'] );
		$this->assertSame( 'aaaaaaaaaaaa', $body['data'][1]['hash'] );
		$this->assertSame( 'cccccccccccc', $body['data'][2]['hash'] );
	}

	public function test_get_urls_sort_by_avg_ms_asc(): void {
		$now = \time();
		// avg_ms is sum_ms / count.  a=20, b=30, c=100.
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 10, 'sum_ms' => 200,  'sum_peak_mb' => 50, 'last_seen' => $now ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 25, 'sum_ms' => 750,  'sum_peak_mb' => 75, 'last_seen' => $now ],
			'cccccccccccc' => [ 'url' => '/c', 'count' => 5,  'sum_ms' => 500,  'sum_peak_mb' => 25, 'last_seen' => $now ],
		] );

		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'sort' => 'avg_ms', 'order' => 'asc' ] ) )->get_data();
		$this->assertSame( 'aaaaaaaaaaaa', $body['data'][0]['hash'] ); // 20
		$this->assertSame( 'bbbbbbbbbbbb', $body['data'][1]['hash'] ); // 30
		$this->assertSame( 'cccccccccccc', $body['data'][2]['hash'] ); // 100
	}

	public function test_get_urls_invalid_sort_falls_back_to_count(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 10, 'sum_ms' => 200, 'last_seen' => $now ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 25, 'sum_ms' => 750, 'last_seen' => $now ],
		] );
		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'sort' => 'sql_injection_here' ] ) )->get_data();
		// Defaults to count desc → bbbb first.
		$this->assertSame( 'bbbbbbbbbbbb', $body['data'][0]['hash'] );
	}

	public function test_get_urls_invalid_order_falls_back_to_desc(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 10, 'sum_ms' => 200, 'last_seen' => $now ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 25, 'sum_ms' => 750, 'last_seen' => $now ],
		] );
		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'order' => 'sideways' ] ) )->get_data();
		$this->assertSame( 'bbbbbbbbbbbb', $body['data'][0]['hash'] );
	}

	public function test_get_urls_search_filters_by_url_substring(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => 'https://example.com/foo/bar', 'count' => 10, 'sum_ms' => 200, 'last_seen' => $now ],
			'bbbbbbbbbbbb' => [ 'url' => 'https://other.com/baz',       'count' => 25, 'sum_ms' => 750, 'last_seen' => $now ],
		] );

		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'search' => 'foo' ] ) )->get_data();
		$this->assertSame( 1, $body['total'] );
		$this->assertSame( 'aaaaaaaaaaaa', $body['data'][0]['hash'] );
	}

	public function test_get_urls_search_is_case_insensitive(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => 'https://Example.com/Foo', 'count' => 10, 'sum_ms' => 200, 'last_seen' => $now ],
		] );
		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'search' => 'EXAMPLE' ] ) )->get_data();
		$this->assertSame( 1, $body['total'] );
	}

	public function test_get_urls_server_filters_by_url_substring(): void {
		$now = \time();
		$this->seed_url_bucket( [
			'aaaaaaaaaaaa' => [ 'url' => 'https://api.example.com/x', 'count' => 10, 'sum_ms' => 200, 'last_seen' => $now ],
			'bbbbbbbbbbbb' => [ 'url' => 'https://other.com/y',       'count' => 5,  'sum_ms' => 100, 'last_seen' => $now ],
		] );
		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'server' => 'api.example.com' ] ) )->get_data();
		$this->assertSame( 1, $body['total'] );
		$this->assertSame( 'aaaaaaaaaaaa', $body['data'][0]['hash'] );
	}

	public function test_get_urls_pagination_offset_and_limit(): void {
		$now = \time();
		$seed = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$h          = \str_pad( 'h' . $i, 12, 'x' );
			$seed[ $h ] = [ 'url' => "/page{$i}", 'count' => 100 - $i, 'sum_ms' => 100 * ( 100 - $i ), 'last_seen' => $now ];
		}
		$this->seed_url_bucket( $seed );

		$body = ( new PerfUrlsController() )->get_urls( $this->get_urls_request( [ 'limit' => 2, 'offset' => 1 ] ) )->get_data();
		$this->assertSame( 5, $body['total'] );      // total reflects pre-pagination
		$this->assertCount( 2, $body['data'] );      // sliced
		$this->assertSame( 1, $body['offset'] );
		$this->assertSame( 2, $body['limit'] );
	}

	// ── get_url_detail: hash validation + 404 ───────────────────────────

	public function test_get_url_detail_with_invalid_hash_returns_400(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'hash', 'short' );
		$resp = ( new PerfUrlsController() )->get_url_detail( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_hash', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? 0 );
	}

	public function test_get_url_detail_with_uppercase_hash_returns_400(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'hash', 'ABCDEF12' );
		$resp = ( new PerfUrlsController() )->get_url_detail( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_hash', $resp->get_error_code() );
	}

	public function test_get_url_detail_returns_404_for_unknown_hash(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'hash', 'abcdef0123456789' );
		$resp = ( new PerfUrlsController() )->get_url_detail( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->data['status'] ?? 0 );
	}

	// ── get_url_detail: full happy path with seeded data ────────────────

	public function test_get_url_detail_returns_stats_and_aggregate_flame(): void {
		$now  = \time();
		$hash = 'abc123def456';
		$this->seed_url_bucket( [
			$hash => [
				'url' => 'https://example.com/p', 'count' => 10, 'sum_ms' => 200,
				'min_ms' => 5, 'max_ms' => 50, 'p50_ms' => 20, 'p95_ms' => 40, 'p99_ms' => 48,
				'sum_peak_mb' => 80, 'max_peak_mb' => 12, 'last_seen' => $now,
			],
		] );
		$this->store()->set_url_stats( $hash, [
			'flame' => [ 'name' => 'request', 'value' => 200, 'children' => [ [ 'name' => 'init', 'value' => 50 ] ] ],
			'profiles' => [ 'db' => [ 'count' => 5 ] ],
			'last_modified' => $now,
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$resp = ( new PerfUrlsController() )->get_url_detail( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();

		$this->assertArrayHasKey( 'stats', $body );
		$this->assertSame( $hash, $body['stats']['hash'] );
		$this->assertSame( 'https://example.com/p', $body['stats']['url'] );
		$this->assertSame( 10, $body['stats']['count'] );
		$this->assertEqualsWithDelta( 20.0, $body['stats']['avg_ms'], 0.001 );
		// p95 is computed as float at the controller; assert with delta to avoid
		// strict-equality false-positives between 40 and 40.0.
		$this->assertEqualsWithDelta( 40, $body['stats']['p95_ms'], 0.001 );

		$this->assertArrayHasKey( 'aggregate_flame', $body );
		$this->assertSame( 'request', $body['aggregate_flame']['name'] );
		$this->assertSame( 200, $body['aggregate_flame']['value'] );

		$this->assertArrayHasKey( 'aggregate_profiles', $body );
		$this->assertSame( [ 'db' => [ 'count' => 5 ] ], $body['aggregate_profiles'] );

		$this->assertSame( $now, $body['last_modified'] );

		// No breakdown / categories param → those keys are null.
		$this->assertNull( $body['breakdown_time_series'] );
		$this->assertNull( $body['category_time_series'] );

		// Stats include time_series array (built from buckets).
		$this->assertArrayHasKey( 'time_series', $body['stats'] );
		$this->assertIsArray( $body['stats']['time_series'] );
	}

	public function test_get_url_detail_default_aggregate_flame_when_no_url_stats(): void {
		$now  = \time();
		$hash = 'aaaaaaaa1111';
		$this->seed_url_bucket( [
			$hash => [ 'url' => '/x', 'count' => 1, 'sum_ms' => 5, 'last_seen' => $now ],
		] );
		// NOTE: no set_url_stats — find_url_aggregate returns null.

		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		// Default aggregate_flame shape when no URL stats exist.
		$this->assertSame( 'aggregate', $body['aggregate_flame']['name'] );
		$this->assertSame( 0, $body['aggregate_flame']['value'] );
		$this->assertSame( [], $body['aggregate_flame']['children'] );
		$this->assertNull( $body['aggregate_profiles'] );
		$this->assertSame( 0, $body['last_modified'] );
	}

	public function test_get_url_detail_breakdown_param_returns_breakdown_time_series(): void {
		$now  = \time();
		$hash = 'aaaaaaaa1234';
		$this->seed_url_bucket( [ $hash => [ 'url' => '/x', 'count' => 1, 'sum_ms' => 5, 'last_seen' => $now ] ] );
		$this->store()->set_url_dimensional( $hash, [
			'status' => [
				'2026-01-01-12-00' => [ '200' => [ 'c' => 5, 's' => 100.0, 'm' => 0.0 ] ],
			],
			'method' => [
				'2026-01-01-12-00' => [ 'GET' => [ 'c' => 5, 's' => 100.0, 'm' => 0.0 ] ],
			],
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$req->set_param( 'breakdown', 'status' );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		$this->assertNotNull( $body['breakdown_time_series'] );
		$this->assertArrayHasKey( '2026-01-01-12-00', $body['breakdown_time_series'] );
		$this->assertSame( 5, $body['breakdown_time_series']['2026-01-01-12-00']['200']['c'] );
	}

	public function test_get_url_detail_invalid_breakdown_returns_null_series(): void {
		$now  = \time();
		$hash = 'aaaaaaaa9999';
		$this->seed_url_bucket( [ $hash => [ 'url' => '/x', 'count' => 1, 'sum_ms' => 5, 'last_seen' => $now ] ] );
		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$req->set_param( 'breakdown', 'banana' );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		// Invalid breakdown is rejected — series stays null.
		$this->assertNull( $body['breakdown_time_series'] );
	}

	public function test_get_url_detail_categories_param_returns_category_time_series(): void {
		$now  = \time();
		$hash = 'aaaaaaaacccc';
		$this->seed_url_bucket( [ $hash => [ 'url' => '/x', 'count' => 1, 'sum_ms' => 5, 'last_seen' => $now ] ] );
		$this->store()->set_url_categories( $hash, [
			'2026-01-01-12-00' => [
				'db' => [ 't' => 50.0, 'c' => 3.0, 'n' => 1 ],
			],
		] );
		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$req->set_param( 'categories', true );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		$this->assertNotNull( $body['category_time_series'] );
		$this->assertArrayHasKey( '2026-01-01-12-00', $body['category_time_series'] );
		$this->assertEqualsWithDelta( 50.0, $body['category_time_series']['2026-01-01-12-00']['db']['t'], 0.001 );
	}

	public function test_get_url_detail_with_recent_requests_from_partition(): void {
		$now  = \time();
		$hash = 'aaa1bbb2c000';
		$this->seed_url_bucket( [
			$hash => [ 'url' => '/page', 'count' => 1, 'sum_ms' => 50, 'last_seen' => $now ],
		] );

		$rid = 'rid-001-aaa';
		$this->write_request_to_partition( 0, $rid, $hash, [
			'rid' => $rid, 'url' => '/page', 'timestamp' => $now,
			'duration_ms' => 50, 'status_code' => 200, 'request_method' => 'GET',
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		$this->assertNotEmpty( $body['requests'] );
		$this->assertSame( $rid, $body['requests'][0]['rid'] );
		$this->assertSame( 200,  $body['requests'][0]['status_code'] );
		$this->assertSame( 0,    $body['requests'][0]['partition'] );
	}

	public function test_get_url_detail_error_status_filter(): void {
		$now  = \time();
		$hash = 'eee0fff1d000';
		$this->seed_url_bucket( [
			$hash => [ 'url' => '/page', 'count' => 1, 'sum_ms' => 50, 'last_seen' => $now ],
		] );

		// Two requests for the same URL: one OK, one Failed.
		$this->write_request_to_partition( 0, 'rid-ok-aa', $hash, [
			'rid' => 'rid-ok-aa', 'url' => '/page', 'timestamp' => $now,
			'duration_ms' => 50, 'status_code' => 200, 'request_method' => 'GET', 'error_status' => '-',
		] );
		$this->write_request_to_partition( 0, 'rid-fail', $hash, [
			'rid' => 'rid-fail', 'url' => '/page', 'timestamp' => $now + 1,
			'duration_ms' => 60, 'status_code' => 500, 'request_method' => 'GET', 'error_status' => 'F',
		] );

		// Filter to F-only.
		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$req->set_param( 'error_status', 'F' );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		$this->assertCount( 1, $body['requests'] );
		$this->assertSame( 'rid-fail', $body['requests'][0]['rid'] );
		$this->assertSame( 'F',       $body['requests'][0]['error_status'] );
	}

	public function test_get_url_detail_error_status_filter_accepts_csv(): void {
		$now  = \time();
		$hash = 'ccc1ddd2e000';
		$this->seed_url_bucket( [
			$hash => [ 'url' => '/page', 'count' => 1, 'sum_ms' => 50, 'last_seen' => $now ],
		] );
		$this->write_request_to_partition( 0, 'rid-fail', $hash, [
			'rid' => 'rid-fail', 'url' => '/page', 'timestamp' => $now,
			'duration_ms' => 60, 'status_code' => 500, 'request_method' => 'GET', 'error_status' => 'F',
		] );
		$this->write_request_to_partition( 0, 'rid-tout', $hash, [
			'rid' => 'rid-tout', 'url' => '/page', 'timestamp' => $now + 1,
			'duration_ms' => 5000, 'status_code' => 504, 'request_method' => 'GET', 'error_status' => 'T',
		] );
		$this->write_request_to_partition( 0, 'rid-okay', $hash, [
			'rid' => 'rid-okay', 'url' => '/page', 'timestamp' => $now + 2,
			'duration_ms' => 50, 'status_code' => 200, 'request_method' => 'GET', 'error_status' => '-',
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'hash', $hash );
		$req->set_param( 'error_status', 'F,T' );
		$body = ( new PerfUrlsController() )->get_url_detail( $req )->get_data();
		$this->assertCount( 2, $body['requests'] );
		$rids = \array_column( $body['requests'], 'rid' );
		$this->assertContains( 'rid-fail', $rids );
		$this->assertContains( 'rid-tout', $rids );
		$this->assertNotContains( 'rid-okay', $rids );
	}

	// ── permissions + rate limiting ─────────────────────────────────────

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfUrlsController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	private function rate_limit_key( PerfUrlsController $ctrl ): string {
		$ref = new \ReflectionMethod( $ctrl, 'rate_limit_key' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( $ctrl );
	}

	private function trip_rate_limit( PerfUrlsController $ctrl ): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$key          = $this->rate_limit_key( $ctrl );
		$this->cache->set( "newspack_nodes_rate:{$key}:{$window_start}", 9999, 120 );
	}

	public function test_get_urls_returns_429_when_rate_limited(): void {
		$ctrl = new PerfUrlsController();
		$this->trip_rate_limit( $ctrl );

		$resp = $ctrl->get_urls( $this->get_urls_request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}

	public function test_get_url_detail_returns_429_when_rate_limited(): void {
		$ctrl = new PerfUrlsController();
		$this->trip_rate_limit( $ctrl );

		$req = new \WP_REST_Request();
		$req->set_param( 'hash', 'abcdef0123456789' );
		$resp = $ctrl->get_url_detail( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}
}
