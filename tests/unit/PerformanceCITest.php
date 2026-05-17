<?php
/**
 * PerformanceCITest: unit tests for Performance_CI, the M2 service-CI that
 * replaces the legacy performance dashboard REST controllers.
 *
 * Task 8 covers the first 5 of 19 planned verbs — the dashboard cluster:
 *   overview        — high-level stats across all partitions (lifted from
 *                     PerfOverviewController::get_overview).
 *   urls            — paginated/sortable URL list (lifted from
 *                     PerfUrlsController::get_urls).
 *   url_detail      — single-URL detail including aggregate flame data
 *                     (lifted from PerfUrlsController::get_url_detail).
 *   request_search  — locate a request by rid across partitions (lifted from
 *                     PerfRequestsController::search_request).
 *   request_detail  — full request + flame data for a known {rid, partition}
 *                     (lifted from PerfRequestsController::get_request).
 *
 * Substrate config (num_partitions, max_lifespan, base_directory) is seeded
 * via TestCase::use_base_dir(), matching SettingsCITest / EventsCITest. The
 * Cache_Interface dep is stubbed via FakeMemcached so the Stats_Store path
 * is exercised without a real memcache server.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Performance_CI;
use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Performance_CI::class )]
class PerformanceCITest extends TestCase {
	private string $tmp;
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching SettingsCITest / EventsCITest.
		$this->tmp   = '/tmp/performance-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->cache = new FakeMemcached();
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'max_lifespan' => 86400 ] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Test helpers — disk-seeded request + flame index entries.
	//
	// Mirror RequestLogControllerTest's `write_request` and the FlameBuilder
	// index layout so the verb's scan_index walk picks up our seeded data.
	// -------------------------------------------------------------------------

	private function write_request( array $body, int $partition = 0 ): string {
		$rid          = $body['rid'];
		$logs_base    = $this->tmp . '/logs/requests.log';
		$segment_dir  = "{$logs_base}/p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$msg[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $msg );

		$seg_path = "{$segment_dir}/0.log";
		$existing = \file_exists( $seg_path ) ? \file_get_contents( $seg_path ) : '';
		$offset   = \strlen( (string) $existing );
		\file_put_contents( $seg_path, $existing . $packed, LOCK_EX );

		$position   = [
			'segment_id' => 0,
			'offset'     => $offset,
			'length'     => \strlen( $packed ),
		];
		$index_line = RequestBuilder::format_index_entry( $packed, $position );
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return $rid;
	}

	private function write_flame( array $body, int $partition = 0 ): string {
		$rid          = $body['rid'];
		$logs_base    = $this->tmp . '/logs/flames.log';
		$segment_dir  = "{$logs_base}/p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$msg[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $msg );

		$seg_path = "{$segment_dir}/0.log";
		$existing = \file_exists( $seg_path ) ? \file_get_contents( $seg_path ) : '';
		$offset   = \strlen( (string) $existing );
		\file_put_contents( $seg_path, $existing . $packed, LOCK_EX );

		$position   = [
			'segment_id' => 0,
			'offset'     => $offset,
			'length'     => \strlen( $packed ),
		];
		$index_line = FlameBuilder::format_index_entry( $packed, $position );
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return $rid;
	}

	// -------------------------------------------------------------------------
	// overview verb
	// -------------------------------------------------------------------------

	public function test_overview_verb_returns_empty_shape_when_no_data(): void {
		// No URL buckets seeded — verb still returns the canonical envelope
		// with zeroed totals + empty leaderboard.
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'overview' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_urls'] );
		$this->assertSame( 0, $result['total_requests'] );
		$this->assertEquals( 0.0, $result['global_avg_ms'] );
		$this->assertEquals( 0.0, $result['global_avg_peak_mb'] );
		$this->assertSame( [], $result['slowest_urls'] );
		$this->assertSame( [], $result['most_requested'] );
		$this->assertSame( [], $result['aggregate_time_series'] );
	}

	public function test_overview_verb_aggregates_hourly_totals(): void {
		// Seed an hourly bucket — verb totals should add up across the
		// merged time_series array.
		$store = new Stats_Store( $this->cache, 0, 86400 );
		$store->set_hourly( [
			'2026-05-17-10' => [ 'count' => 4, 'sum_ms' => 2000.0, 'sum_peak_mb' => 40.0 ],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'overview' );

		$this->assertSame( 4, $result['total_requests'] );
		$this->assertEquals( 500.0, $result['global_avg_ms'] );
		$this->assertEquals( 10.0, $result['global_avg_peak_mb'] );
		$this->assertCount( 1, $result['aggregate_time_series'] );
	}

	public function test_overview_verb_includes_url_index_count(): void {
		// Seed a URL bucket so the index walks pick something up. Bucket key
		// is whatever Stats_Store::current_url_bucket returns "now" so the
		// recent-bucket scan finds it.
		$store  = new Stats_Store( $this->cache, 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/articles/123',
				'count'     => 7,
				'sum_ms'    => 350.0,
				'last_seen' => 1700000000,
			],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'overview' );

		$this->assertSame( 1, $result['total_urls'] );
		$this->assertCount( 1, $result['most_requested'] );
		$this->assertSame( '/articles/123', $result['most_requested'][0]['url'] );
		$this->assertSame( 7, $result['most_requested'][0]['count'] );
	}

	public function test_overview_verb_rejects_unauthorized(): void {
		// Legacy controller gates every verb via read_permissions_check ==
		// manage_options. Performance_CI matches that.
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'overview' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// urls verb
	// -------------------------------------------------------------------------

	public function test_urls_verb_returns_envelope_when_empty(): void {
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'urls' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'limit', $result );
		$this->assertArrayHasKey( 'offset', $result );
		$this->assertSame( [], $result['data'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_urls_verb_default_limit_is_50(): void {
		// Legacy controller default — `limit=50` from sanitize_callback default.
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'urls' );

		$this->assertSame( 50, $result['limit'] );
	}

	public function test_urls_verb_clamps_limit_high(): void {
		// Mirrors `min(1000, max(1, (int)$v))` from legacy sanitize_callback.
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'urls',
			(string) \wp_json_encode( [ 'limit' => 5000 ] )
		);
		$this->assertSame( 1000, $result['limit'] );
	}

	public function test_urls_verb_paginates_and_sorts(): void {
		$store  = new Stats_Store( $this->cache, 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000002 ],
			'cccccccccccc' => [ 'url' => '/c', 'count' => 3, 'sum_ms' => 300.0, 'last_seen' => 1700000003 ],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'urls',
			(string) \wp_json_encode( [ 'sort' => 'count', 'order' => 'desc', 'limit' => 2, 'offset' => 0 ] )
		);

		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 2, $result['data'] );
		// Desc by count: /b first (5), /c second (3).
		$this->assertSame( '/b', $result['data'][0]['url'] );
		$this->assertSame( '/c', $result['data'][1]['url'] );
	}

	public function test_urls_verb_filters_by_search_term(): void {
		$store  = new Stats_Store( $this->cache, 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/articles/123', 'count' => 1, 'sum_ms' => 50.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/home', 'count' => 2, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'urls',
			(string) \wp_json_encode( [ 'search' => 'article' ] )
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( '/articles/123', $result['data'][0]['url'] );
	}

	public function test_urls_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'urls' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// url_detail verb
	// -------------------------------------------------------------------------

	public function test_url_detail_verb_rejects_invalid_hash(): void {
		// Legacy `get_url_detail` returns invalid_hash 400 when hash regex fails.
		// We surface that as a verb error string (CI errors are string-encoded).
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			(string) \wp_json_encode( [ 'hash' => 'not-a-hash' ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_url_detail_verb_returns_not_found_when_unknown_hash(): void {
		// Hash matches the regex but doesn't exist in the URL index — legacy
		// surfaces a 404 with "URL not found".
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			(string) \wp_json_encode( [ 'hash' => 'deadbeefcafe' ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_url_detail_verb_returns_stats_and_default_flame(): void {
		$store  = new Stats_Store( $this->cache, 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/articles/777',
				'count'     => 9,
				'sum_ms'    => 450.0,
				'p95_ms'    => 80.0,
				'last_seen' => 1700000999,
			],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			(string) \wp_json_encode( [ 'hash' => 'abc123def456' ] )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertArrayHasKey( 'requests', $result );
		$this->assertArrayHasKey( 'aggregate_flame', $result );
		$this->assertSame( '/articles/777', $result['stats']['url'] );
		$this->assertSame( 9, $result['stats']['count'] );
		// No flame seeded → default empty-tree shape.
		$this->assertSame( 'aggregate', $result['aggregate_flame']['name'] );
		$this->assertSame( 0, $result['aggregate_flame']['value'] );
		$this->assertSame( [], $result['aggregate_flame']['children'] );
		$this->assertSame( [], $result['requests'] );
	}

	public function test_url_detail_verb_includes_aggregate_flame_when_seeded(): void {
		$store  = new Stats_Store( $this->cache, 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'cafebabe1234' => [
				'url'       => '/x',
				'count'     => 1,
				'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		// Per-URL flame stats blob lives at NS_URL keyed by url_hash.
		$store->set_url_stats( 'cafebabe1234', [
			'flame'         => [ 'name' => 'aggregate', 'value' => 100, 'children' => [ [ 'name' => 'a', 'value' => 50 ] ] ],
			'last_modified' => 1700001111,
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			(string) \wp_json_encode( [ 'hash' => 'cafebabe1234' ] )
		);

		$this->assertSame( 100, $result['aggregate_flame']['value'] );
		$this->assertSame( 1700001111, $result['last_modified'] );
	}

	public function test_url_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			(string) \wp_json_encode( [ 'hash' => 'abc123def456' ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// request_search verb
	// -------------------------------------------------------------------------

	public function test_request_search_verb_returns_not_found_when_missing(): void {
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_search',
			(string) \wp_json_encode( [ 'rid' => 'no-such-rid' ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_request_search_verb_locates_known_rid(): void {
		// Seed one request — search should return {rid, partition, url_hash}.
		// Rid must be ≤32 chars or it gets truncated when written into the
		// fixed-width .idx field and the round-trip lookup fails.
		$rid = $this->write_request( [
			'rid'            => 'rid-search-12345678901234567890',
			'url'            => '/searchable',
			'timestamp'      => 1700000400,
			'duration_ms'    => 20,
			'status_code'    => 200,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_search',
			(string) \wp_json_encode( [ 'rid' => $rid ] )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertNotEmpty( $result['url_hash'] );
	}

	public function test_request_search_verb_requires_rid(): void {
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire( $ci, 'performance', 'request_search' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'rid required', \strtolower( $result ) );
	}

	public function test_request_search_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_search',
			(string) \wp_json_encode( [ 'rid' => 'whatever' ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// request_detail verb
	// -------------------------------------------------------------------------

	public function test_request_detail_verb_returns_not_found_when_missing(): void {
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			(string) \wp_json_encode( [ 'rid' => 'no-such-rid', 'partition' => 0 ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_request_detail_verb_rejects_invalid_partition(): void {
		// num_partitions = 1 (test setUp), partition = 5 is out of range.
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			(string) \wp_json_encode( [ 'rid' => 'whatever', 'partition' => 5 ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid partition', \strtolower( $result ) );
	}

	public function test_request_detail_verb_returns_body_for_known_rid(): void {
		// Rid must be ≤32 chars so the round-trip through the .idx fixed-width
		// field doesn't drop characters and break the lookup.
		$rid = $this->write_request( [
			'rid'            => 'rid-detail-12345678901234567890',
			'url'            => '/detailed',
			'timestamp'      => 1700000500,
			'duration_ms'    => 33,
			'status_code'    => 201,
			'peak_mb'        => 4,
			'request_method' => 'POST',
			'events'         => [
				[ 'k' => 'process (start)', 'm' => '/detailed', 'ts' => 1700000500.0 ],
			],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			(string) \wp_json_encode( [ 'rid' => $rid, 'partition' => 0 ] )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( '/detailed', $result['url'] );
		$this->assertSame( 201, $result['status_code'] );
		$this->assertNotEmpty( $result['url_hash'] );
		$this->assertArrayHasKey( 'events', $result );
		$this->assertCount( 1, $result['events'] );
	}

	public function test_request_detail_verb_merges_flame_data_when_present(): void {
		// Rid must be ≤32 chars (fixed-width .idx field) so the lookup matches.
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-123456789012345678901',
			'url'            => '/with-flame',
			'timestamp'      => 1700000600,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );
		// Flame entry indexed by rid + url_hash; FlameBuilder writes the
		// flame body at Message::VALUE alongside the index entry.
		$url_hash = RequestBuilder::url_hash( '/with-flame' );
		$this->write_flame( [
			'rid'      => $rid,
			'url_hash' => $url_hash,
			'flame'    => [ 'name' => 'request', 'value' => 12, 'children' => [] ],
		] );

		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			(string) \wp_json_encode( [ 'rid' => $rid, 'partition' => 0 ] )
		);

		$this->assertArrayHasKey( 'flame_data', $result );
	}

	public function test_request_detail_verb_requires_rid(): void {
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			(string) \wp_json_encode( [ 'partition' => 0 ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'rid required', \strtolower( $result ) );
	}

	public function test_request_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI( $this->cache );
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			(string) \wp_json_encode( [ 'rid' => 'whatever', 'partition' => 0 ] )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}
}
