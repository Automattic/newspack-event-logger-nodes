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
 * shared `Core::$memd` handle is seeded with an in-memory `\Memcached` so the
 * Stats_Store path is exercised without a real memcache server.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Performance_CI_Node::class )]
class PerformanceCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching SettingsCITest / EventsCITest.
		$this->tmp  = '/tmp/performance-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		Core::$memd = new InMemoryMemcached();
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'max_lifespan' => 86400 ] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
		// Reset the hook-categorizer static caches and the WP hook globals
		// so each hooks_* verb test sees a clean room. Mirrors the legacy
		// PerfHooksControllerTest / PerfHooksAvailableControllerTest setUp.
		Hook_Categorizer::clear_cache();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		Hook_Categorizer::clear_cache();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Test helpers — disk-seeded request + flame index entries.
	//
	// Mirror RequestLogControllerTest's `write_request` and the FlameBuilder
	// index layout so the verb's scan_index walk picks up our seeded data.
	// -------------------------------------------------------------------------

	/**
	 * Mirror of Performance_CI::recent_url_buckets so tests can seed the
	 * leaderboard / dim / category buckets the verb's fan-out scans.
	 *
	 * @return array<int,string>
	 */
	private function recent_url_buckets(): array {
		$now = \time();
		$out = [];
		for ( $i = 0; $i < 288; $i++ ) {
			$ts         = $now - ( $i * 300 );
			$min        = (int) \gmdate( 'i', $ts );
			$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
			$out[]      = \gmdate( 'Y-m-d-H', $ts ) . '-' . $bucket_min;
		}
		return \array_values( \array_unique( $out ) );
	}

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
		$index_line = Request_Builder_Node::format_index_entry( $packed, $position );
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
		$index_line = Flame_Builder_Node::format_index_entry( $packed, $position );
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
		$ci     = new Performance_CI_Node();
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
		$store = new Stats_Store( 0, 86400 );
		$store->set_hourly( [
			'2026-05-17-10' => [ 'count' => 4, 'sum_ms' => 2000.0, 'sum_peak_mb' => 40.0 ],
		] );

		$ci     = new Performance_CI_Node();
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
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/articles/123',
				'count'     => 7,
				'sum_ms'    => 350.0,
				'last_seen' => 1700000000,
			],
		] );

		$ci     = new Performance_CI_Node();
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
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'overview' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_overview_verb_includes_global_leaderboard_by_default(): void {
		// OverviewSection (React) renders `overview.global_leaderboard.{categories,total_time,count}`
		// (see components/OverviewSection.js L330-358). Legacy PerfOverviewController::get_overview
		// emits `global_leaderboard` unconditionally (L95-97). The CI verb must match.
		$store   = new Stats_Store( 0, 86400 );
		$buckets = $this->recent_url_buckets();
		// Seed the most-recent bucket so the leaderboard fan-out picks it up.
		$store->set_leaderboard_bucket( $buckets[0], [
			'count'        => 4,
			'sum_req_time' => 0.8,
			'categories'   => [
				'db' => [ 'samples' => 4, 'sum_time' => 0.4, 'sum_count' => 10 ],
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'overview' );

		$this->assertArrayHasKey( 'global_leaderboard', $result );
		$this->assertSame( 4, $result['global_leaderboard']['count'] );
		$this->assertArrayHasKey( 'categories', $result['global_leaderboard'] );
		$this->assertArrayHasKey( 'db', $result['global_leaderboard']['categories'] );
		$this->assertArrayHasKey( 'total_time', $result['global_leaderboard'] );
	}

	public function test_overview_verb_uses_server_leaderboard_when_server_arg_set(): void {
		// `server` arg scopes the leaderboard to that server (legacy L95-97
		// switches to `build_server_leaderboard`). The CI verb must reroute.
		$store   = new Stats_Store( 0, 86400 );
		$buckets = $this->recent_url_buckets();
		$store->set_server_leaderboard_bucket( 'web01', $buckets[0], [
			'count'        => 2,
			'sum_req_time' => 0.2,
			'categories'   => [
				'db' => [ 'samples' => 2, 'sum_time' => 0.1, 'sum_count' => 4 ],
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'overview',
			[ 'server' => 'web01' ]
		);

		// Only the server-scoped leaderboard was seeded — global is empty.
		// `count` should reflect the server-scoped data.
		$this->assertSame( 2, $result['global_leaderboard']['count'] );
	}

	public function test_overview_verb_includes_category_time_series_when_categories_arg_set(): void {
		// Legacy `?categories=1` (L121-125) adds `category_time_series` to the
		// response — global or server-scoped. The dashboard always passes
		// `categories=1` (see usePerformanceApi.js L54) and reads
		// `overviewData.category_time_series` (PerformanceDashboard.js L391).
		$store = new Stats_Store( 0, 86400 );
		$store->set_categories( [
			'2026-05-17-10-00' => [ 'db' => [ 't' => 0.5, 'c' => 4, 'n' => 4 ] ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'overview',
			[ 'categories' => true ]
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
		$this->assertArrayHasKey( '2026-05-17-10-00', $result['category_time_series'] );
		$this->assertSame( 0.5, $result['category_time_series']['2026-05-17-10-00']['db']['t'] );
	}

	public function test_overview_verb_includes_breakdown_time_series_for_single_dim(): void {
		// `?breakdown=server` returns `breakdown_time_series` flat (legacy L111-112).
		// Used by fetchBreakdown (usePerformanceApi.js L186 reads
		// `data.breakdown_time_series`).
		$store = new Stats_Store( 0, 86400 );
		$store->set_dimensional( 'server', [
			'2026-05-17-10-00' => [
				'web01' => [ 'c' => 5, 's' => 0.5, 'm' => 0.1 ],
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'overview',
			[ 'breakdown' => 'server' ]
		);

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 5, $result['breakdown_time_series']['2026-05-17-10-00']['web01']['c'] );
	}

	public function test_overview_verb_includes_breakdowns_map_for_multi_dim(): void {
		// Comma-separated dims return nested `breakdowns: { dim => series }`
		// (legacy L113-118). Used by the dashboard's `breakdownsFor` deduper
		// (PerformanceDashboard.js L342-374), which always sends ≥2 dims so it
		// can rely on the nested shape.
		$store = new Stats_Store( 0, 86400 );
		$store->set_dimensional( 'server', [
			'2026-05-17-10-00' => [ 'web01' => [ 'c' => 5, 's' => 0.5, 'm' => 0.1 ] ],
		] );
		$store->set_dimensional( 'status', [
			'2026-05-17-10-00' => [ '200' => [ 'c' => 4, 's' => 0.4, 'm' => 0.1 ] ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'overview',
			[ 'breakdown' => 'server,status' ]
		);

		$this->assertArrayHasKey( 'breakdowns', $result );
		$this->assertArrayHasKey( 'server', $result['breakdowns'] );
		$this->assertArrayHasKey( 'status', $result['breakdowns'] );
		$this->assertSame( 5, $result['breakdowns']['server']['2026-05-17-10-00']['web01']['c'] );
		$this->assertSame( 4, $result['breakdowns']['status']['2026-05-17-10-00']['200']['c'] );
	}

	public function test_overview_verb_breakdown_filters_unknown_dims(): void {
		// Unknown dim names are filtered out so a typo'd query param can't surface
		// arbitrary memcache reads (legacy L107-108 `in_array(...,DIMENSIONS,true)`).
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'overview',
			[ 'breakdown' => 'nosuchdim' ]
		);

		// No valid dims → no breakdown_time_series, no breakdowns.
		$this->assertArrayNotHasKey( 'breakdown_time_series', $result );
		$this->assertArrayNotHasKey( 'breakdowns', $result );
	}

	public function test_overview_verb_server_scoped_categories_when_both_args(): void {
		// `?server=X&categories=1` should use the per-server categories
		// blob (legacy L122-124 `merge_server_categories_across_partitions`).
		$store = new Stats_Store( 0, 86400 );
		$store->set_server_categories( 'web01', [
			'2026-05-17-10-00' => [ 'db' => [ 't' => 0.2, 'c' => 2, 'n' => 2 ] ],
		] );
		$store->set_categories( [
			'2026-05-17-10-00' => [ 'db' => [ 't' => 9.9, 'c' => 99, 'n' => 99 ] ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'overview',
			[ 'server' => 'web01', 'categories' => true ]
		);

		// Server-scoped data, not the global ones.
		$this->assertSame( 0.2, $result['category_time_series']['2026-05-17-10-00']['db']['t'] );
	}

	// -------------------------------------------------------------------------
	// urls verb
	// -------------------------------------------------------------------------

	public function test_urls_verb_returns_envelope_when_empty(): void {
		$ci     = new Performance_CI_Node();
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
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'urls' );

		$this->assertSame( 50, $result['limit'] );
	}

	public function test_urls_verb_clamps_limit_high(): void {
		// Mirrors `min(1000, max(1, (int)$v))` from legacy sanitize_callback.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'urls',
			[ 'limit' => 5000 ]
		);
		$this->assertSame( 1000, $result['limit'] );
	}

	public function test_urls_verb_paginates_and_sorts(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000002 ],
			'cccccccccccc' => [ 'url' => '/c', 'count' => 3, 'sum_ms' => 300.0, 'last_seen' => 1700000003 ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'urls',
			[ 'sort' => 'count', 'order' => 'desc', 'limit' => 2, 'offset' => 0 ]
		);

		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 2, $result['data'] );
		// Desc by count: /b first (5), /c second (3).
		$this->assertSame( '/b', $result['data'][0]['url'] );
		$this->assertSame( '/c', $result['data'][1]['url'] );
	}

	public function test_urls_verb_filters_by_search_term(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/articles/123', 'count' => 1, 'sum_ms' => 50.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/home', 'count' => 2, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'urls',
			[ 'search' => 'article' ]
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( '/articles/123', $result['data'][0]['url'] );
	}

	public function test_urls_verb_heals_poisoned_min_ms_sentinel(): void {
		// A URL whose every persisted bucket is untimed carries the
		// PHP_INT_MAX sentinel as min_ms (worker / timed-out requests). The
		// display must never surface the sentinel — it heals to 0.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [
				'url'         => '/worker-only',
				'count'       => 7,
				'timed_count' => 0,
				'sum_ms'      => 0.0,
				'min_ms'      => PHP_INT_MAX,
				'max_ms'      => 0.0,
				'last_seen'   => 1700000001,
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'urls', [] );

		$this->assertSame( 1, $result['total'] );
		// JSON round-trip in the verb harness collapses 0.0 → int 0; the
		// poisoned value would survive as a huge number, so a 0 here proves
		// the sentinel was rejected.
		$this->assertSame( 0, $result['data'][0]['min_ms'] );
	}

	public function test_urls_verb_min_ms_unaffected_by_untimed_sibling_bucket(): void {
		// Same URL hash across two buckets: one untimed-only (timed_count 0,
		// min_ms 0 from the write-side guard) and one timed (timed_count 5,
		// min_ms 42). The read merge must fold only the timed bucket so the
		// real minimum survives — an untimed-only sibling must not clamp it to 0.
		$store    = new Stats_Store( 0, 86400 );
		$bucket_b = $store->current_url_bucket();
		$bucket_a = $store->bucket_key_for( \time() - 600 );

		$store->set_url_index_hourly( $bucket_a, [
			'bbbbbbbbbbbb' => [
				'url'         => '/mixed',
				'count'       => 3,
				'timed_count' => 0,
				'sum_ms'      => 0.0,
				'min_ms'      => 0,
				'max_ms'      => 0.0,
				'last_seen'   => 1700000001,
			],
		] );
		$store->set_url_index_hourly( $bucket_b, [
			'bbbbbbbbbbbb' => [
				'url'         => '/mixed',
				'count'       => 5,
				'timed_count' => 5,
				'sum_ms'      => 500.0,
				'min_ms'      => 42,
				'max_ms'      => 120.0,
				'last_seen'   => 1700000002,
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'urls', [] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 42, $result['data'][0]['min_ms'] );
	}

	public function test_urls_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
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
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'not-a-hash' ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_url_detail_verb_returns_not_found_when_unknown_hash(): void {
		// Hash matches the regex but doesn't exist in the URL index — legacy
		// surfaces a 404 with "URL not found".
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'deadbeefcafe' ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_url_detail_verb_returns_stats_and_default_flame(): void {
		$store  = new Stats_Store( 0, 86400 );
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

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'abc123def456' ]
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
		$store  = new Stats_Store( 0, 86400 );
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

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'cafebabe1234' ]
		);

		$this->assertSame( 100, $result['aggregate_flame']['value'] );
		$this->assertSame( 1700001111, $result['last_modified'] );
	}

	public function test_url_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'abc123def456' ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_url_detail_verb_includes_stats_time_series(): void {
		// `stats.time_series` is consumed by UrlDetailView L232/L273 +
		// PerformanceDashboard.js L727-741 (urlRequestsPerSecond computation).
		// Legacy PerfUrlsController::find_url_stats L228 calls `build_url_time_series`
		// which walks the recent buckets keyed by hash. The CI verb must too.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 3,
				'sum_ms'    => 150.0,
				'last_seen' => 1700001000,
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'abc123def456' ]
		);

		$this->assertArrayHasKey( 'time_series', $result['stats'] );
		$this->assertArrayHasKey( $bucket, $result['stats']['time_series'] );
		$this->assertSame( 3, $result['stats']['time_series'][ $bucket ]['count'] );
	}

	public function test_url_detail_verb_includes_breakdown_time_series_when_arg_set(): void {
		// `?breakdown=method` on /urls/{hash} emits `breakdown_time_series`
		// (legacy L195, L177-181). Consumed by fetchUrlBreakdown L213.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 1,
				'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		$store->set_url_dimensional( 'abc123def456', [
			'method' => [
				'2026-05-17-10-00' => [ 'GET' => [ 'c' => 3, 's' => 0.3, 'm' => 0.1 ] ],
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'abc123def456', 'breakdown' => 'method' ]
		);

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 3, $result['breakdown_time_series']['2026-05-17-10-00']['GET']['c'] );
	}

	public function test_url_detail_verb_includes_category_time_series_when_arg_set(): void {
		// `?categories=1` on /urls/{hash} emits `category_time_series`
		// (legacy L196, L184-186). Consumed by UrlDetailView L282-295 +
		// fetchUrlCategories L237.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 1,
				'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		$store->set_url_categories( 'abc123def456', [
			'2026-05-17-10-00' => [ 'db' => [ 't' => 0.2, 'c' => 2, 'n' => 1 ] ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'abc123def456', 'categories' => true ]
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
		$this->assertSame( 0.2, $result['category_time_series']['2026-05-17-10-00']['db']['t'] );
	}

	public function test_url_detail_verb_breakdown_filters_unknown_dims(): void {
		// Unknown dim → no breakdown_time_series (matches legacy L179's
		// `in_array(...,DIMENSIONS,true)` guard).
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [ 'url' => '/x', 'count' => 1, 'sum_ms' => 10.0, 'last_seen' => 1700001000 ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'url_detail',
			[ 'hash' => 'abc123def456', 'breakdown' => 'nosuchdim' ]
		);

		$this->assertArrayNotHasKey( 'breakdown_time_series', $result );
	}

	// -------------------------------------------------------------------------
	// request_search verb
	// -------------------------------------------------------------------------

	public function test_request_search_verb_returns_not_found_when_missing(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_search',
			[ 'rid' => 'no-such-rid' ]
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

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_search',
			[ 'rid' => $rid ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertNotEmpty( $result['url_hash'] );
	}

	public function test_request_search_verb_requires_rid(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'request_search' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'rid required', \strtolower( $result ) );
	}

	public function test_request_search_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_search',
			[ 'rid' => 'whatever' ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// request_detail verb
	// -------------------------------------------------------------------------

	public function test_request_detail_verb_returns_not_found_when_missing(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			[ 'rid' => 'no-such-rid', 'partition' => 0 ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_request_detail_verb_rejects_invalid_partition(): void {
		// num_partitions = 1 (test setUp), partition = 5 is out of range.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			[ 'rid' => 'whatever', 'partition' => 5 ]
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

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			[ 'rid' => $rid, 'partition' => 0 ]
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
		$url_hash = Request_Builder_Node::url_hash( '/with-flame' );
		$this->write_flame( [
			'rid'      => $rid,
			'url_hash' => $url_hash,
			'flame'    => [ 'name' => 'request', 'value' => 12, 'children' => [] ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			[ 'rid' => $rid, 'partition' => 0 ]
		);

		$this->assertArrayHasKey( 'flame_data', $result );
	}

	public function test_request_detail_verb_requires_rid(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			[ 'partition' => 0 ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'rid required', \strtolower( $result ) );
	}

	public function test_request_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_detail',
			[ 'rid' => 'whatever', 'partition' => 0 ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// timing verb
	// -------------------------------------------------------------------------

	public function test_timing_verb_returns_empty_time_series_when_no_data(): void {
		// No hourly buckets seeded — canonical empty envelope.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'timing' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'time_series', $result );
		$this->assertSame( [], $result['time_series'] );
	}

	public function test_timing_verb_returns_merged_hourly_buckets(): void {
		// Two hourly buckets — verb returns them sorted by hour key.
		$store = new Stats_Store( 0, 86400 );
		$store->set_hourly( [
			'2026-05-17-09' => [ 'count' => 3, 'sum_ms' => 600.0, 'sum_peak_mb' => 30.0 ],
			'2026-05-17-10' => [ 'count' => 5, 'sum_ms' => 2500.0, 'sum_peak_mb' => 50.0 ],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'timing' );

		$this->assertCount( 2, $result['time_series'] );
		$this->assertSame( '2026-05-17-09', $result['time_series'][0]['hour'] );
		$this->assertSame( 3, $result['time_series'][0]['count'] );
		$this->assertSame( '2026-05-17-10', $result['time_series'][1]['hour'] );
		$this->assertSame( 5, $result['time_series'][1]['count'] );
	}

	public function test_timing_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'timing' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// dashboard verb
	// -------------------------------------------------------------------------

	public function test_dashboard_verb_returns_overview_and_urls_envelope(): void {
		// No data seeded — verb still returns the canonical nested shape with
		// an overview block plus an empty urls array. Lifted from legacy
		// PerformanceController::get_dashboard, minus the REST data+meta wrapper.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'dashboard' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'overview', $result );
		$this->assertArrayHasKey( 'urls', $result );
		$this->assertIsArray( $result['overview'] );
		$this->assertSame( [], $result['urls'] );
		$this->assertSame( 0, $result['overview']['total_urls'] );
		$this->assertSame( 0, $result['overview']['total_requests'] );
	}

	public function test_dashboard_verb_includes_seeded_urls(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $store->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'dashboardhash' => [
				'url'       => '/dashboard-url',
				'count'     => 11,
				'sum_ms'    => 550.0,
				'last_seen' => 1700002000,
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'dashboard' );

		$this->assertSame( 1, $result['overview']['total_urls'] );
		$this->assertCount( 1, $result['urls'] );
		$this->assertSame( '/dashboard-url', $result['urls'][0]['url'] );
		$this->assertSame( 11, $result['urls'][0]['count'] );
	}

	public function test_dashboard_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'dashboard' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// hooks_registered verb — replaces PerfHooksController::get_registered_hooks.
	// -------------------------------------------------------------------------

	/**
	 * Seed $wp_filter with three known hooks so HookCategorizer has something
	 * to walk. Shared across the hooks_registered cluster — the categorizer
	 * cares about the hook NAMES, not the callback shapes, so a minimal stub
	 * object with a non-empty `callbacks` array is enough.
	 */
	private function seed_wp_filter_with_known_hooks(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_filter;
		$wp_filter = [
			'init'       => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_init' ] ]; },
			'wp_loaded'  => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_wp_loaded' ] ]; },
			'admin_menu' => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_admin_menu' ] ]; },
		];
	}

	public function test_hooks_registered_verb_returns_canonical_shape(): void {
		$this->seed_wp_filter_with_known_hooks();

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_registered' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_hooks', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'hooks_by_category', $result );
	}

	public function test_hooks_registered_verb_total_matches_summed_buckets(): void {
		$this->seed_wp_filter_with_known_hooks();

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_registered' );

		$summed = 0;
		foreach ( $result['hooks_by_category'] as $bucket ) {
			$summed += \count( $bucket );
		}
		$this->assertSame( $result['total_hooks'], $summed );
	}

	public function test_hooks_registered_verb_includes_seeded_hooks(): void {
		$this->seed_wp_filter_with_known_hooks();

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_registered' );

		$all = [];
		foreach ( $result['hooks_by_category'] as $bucket ) {
			$all = \array_merge( $all, $bucket );
		}
		$this->assertContains( 'init', $all );
		$this->assertContains( 'wp_loaded', $all );
		$this->assertContains( 'admin_menu', $all );
	}

	public function test_hooks_registered_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_registered' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// hooks_categories verb — replaces PerfHooksController::get_hook_categories.
	// -------------------------------------------------------------------------

	public function test_hooks_categories_verb_returns_categories_and_config(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_categories' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'config', $result );
		$this->assertIsArray( $result['categories'] );
		$this->assertIsArray( $result['config'] );
	}

	public function test_hooks_categories_verb_config_includes_patterns_and_colors(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_categories' );

		$this->assertArrayHasKey( 'colors', $result['config'] );
		$this->assertArrayHasKey( 'patterns', $result['config'] );
	}

	public function test_hooks_categories_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_categories' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// hooks_available verb — replaces PerfHooksAvailableController::get_available_hooks.
	// -------------------------------------------------------------------------

	public function test_hooks_available_verb_reads_wp_actions(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'init' => 1, 'wp_loaded' => 2 ];
		$wp_filter  = [];

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_available' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hooks', $result );
		$names = \array_column( $result['hooks'], 'name' );
		$this->assertContains( 'init', $names );
		$this->assertContains( 'wp_loaded', $names );

		foreach ( $result['hooks'] as $hook ) {
			$this->assertArrayHasKey( 'name', $hook );
			$this->assertArrayHasKey( 'category', $hook );
			$this->assertArrayHasKey( 'count', $hook );
		}
	}

	public function test_hooks_available_verb_reads_wp_filter_for_unfired_hooks(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [
			'never_fired_filter' => new class { public array $callbacks = [ [ 'cb' => 'x' ] ]; },
		];

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_available' );

		$names = \array_column( $result['hooks'], 'name' );
		$this->assertContains( 'never_fired_filter', $names );
		foreach ( $result['hooks'] as $hook ) {
			if ( 'never_fired_filter' === $hook['name'] ) {
				$this->assertSame( 0, $hook['count'] );
			}
		}
	}

	public function test_hooks_available_verb_excludes_internal_prefixes(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [
			'newspack_nodes/spawn_worker'             => 3,
			'newspack_event_logger_nodes/log_readers' => 1,
			'init'                                    => 1,
		];
		$wp_filter  = [];

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_available' );

		$names = \array_column( $result['hooks'], 'name' );
		$this->assertNotContains( 'newspack_nodes/spawn_worker', $names );
		$this->assertNotContains( 'newspack_event_logger_nodes/log_readers', $names );
		$this->assertContains( 'init', $names );
	}

	public function test_hooks_available_verb_returns_sorted_by_name(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [ 'zeta' => 1, 'alpha' => 1, 'mu' => 1 ];
		$wp_filter  = [];

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_available' );

		$names = \array_column( $result['hooks'], 'name' );
		$this->assertSame( [ 'alpha', 'mu', 'zeta' ], $names );
	}

	public function test_hooks_available_verb_handles_no_hooks_at_all(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_available' );

		$this->assertSame( [], $result['hooks'] );
	}

	public function test_hooks_available_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_available' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// hooks_configure verb — replaces PerfHooksAvailableController::configure_hooks.
	// -------------------------------------------------------------------------

	public function test_hooks_configure_verb_writes_log_events_and_custom_events(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'hooks_configure',
			[
				'hooks'         => [ 'init', 'wp_loaded' ],
				'custom_events' => [ 'my_event' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['hooks_configured'] );
		$this->assertSame( [ 'init', 'wp_loaded' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
		$this->assertSame( [ 'my_event' => true ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] );
	}

	public function test_hooks_configure_verb_sanitizes_strings_skips_empty_and_non_strings(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'hooks_configure',
			[
				'hooks'         => [ 'init', '', 12345, '<b>raw</b>' ],
				'custom_events' => null,
			]
		);

		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( [ 'init', 'raw' ], $saved );
		$this->assertSame( 2, $result['hooks_configured'] );
	}

	public function test_hooks_configure_verb_accepts_only_custom_events(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'hooks_configure',
			[
				'custom_events' => [ 'event_one', 'event_two' ],
			]
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['hooks_configured'] );
		$this->assertSame(
			[ 'event_one' => true, 'event_two' => true ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events']
		);
	}

	public function test_hooks_configure_verb_with_no_data(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'hooks_configure' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['hooks_configured'] );
	}

	public function test_hooks_configure_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'hooks_configure',
			[ 'hooks' => [ 'init' ] ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
		// Confirm no write happened on the rejected path.
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_events', $GLOBALS['_wp_options'] );
	}

	// -------------------------------------------------------------------------
	// config_get verb — replaces PerfConfigController::get_config.
	// -------------------------------------------------------------------------

	public function test_config_get_verb_returns_all_nine_perf_keys(): void {
		// Legacy controller surfaces these nine keys regardless of which are
		// set in WP options — the unset ones come back as zero / empty / false.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'config_get' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'config', $result );
		foreach ( [
			'log_events',
			'custom_events',
			'log_urls',
			'skip_urls',
			'auto_disable_threshold',
			'auto_protect_time_threshold',
			'significant_events',
			'log_memory',
			'flush_every_line',
		] as $key ) {
			$this->assertArrayHasKey( $key, $result['config'] );
		}
	}

	public function test_config_get_verb_reflects_set_options(): void {
		// Seed a handful of options across all four legacy types
		// (array, int, float, bool) — verb returns them coerced.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']                  = [ 'init', 'wp_loaded' ];
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold']      = 1500;
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_protect_time_threshold'] = 2.5;
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory']                  = true;

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'config_get' );

		$this->assertSame( [ 'init', 'wp_loaded' ], $result['config']['log_events'] );
		$this->assertSame( 1500, $result['config']['auto_disable_threshold'] );
		$this->assertEqualsWithDelta( 2.5, $result['config']['auto_protect_time_threshold'], 0.001 );
		$this->assertTrue( $result['config']['log_memory'] );
	}

	public function test_config_get_verb_coerces_types_when_options_empty(): void {
		// Legacy controller defaults: int → 0, float → 0.0, bool → false,
		// arrays → []. Confirm the verb honours each default branch.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'config_get' );

		$this->assertSame( 0, $result['config']['auto_disable_threshold'] );
		$this->assertEquals( 0.0, $result['config']['auto_protect_time_threshold'] );
		$this->assertFalse( $result['config']['log_memory'] );
		$this->assertFalse( $result['config']['flush_every_line'] );
		$this->assertSame( [], $result['config']['log_events'] );
		$this->assertSame( [], $result['config']['custom_events'] );
	}

	public function test_config_get_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'config_get' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// config_update verb — replaces PerfConfigController::update_config.
	// -------------------------------------------------------------------------

	public function test_config_update_verb_writes_supplied_keys_only(): void {
		// Legacy contract: only keys present in the request body are updated;
		// the rest are untouched. Response `updated` lists the keys that were
		// applied.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[
				'log_events'             => [ 'init', 'shutdown' ],
				'auto_disable_threshold' => 1500,
				'log_memory'             => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertContains( 'log_events', $result['updated'] );
		$this->assertContains( 'auto_disable_threshold', $result['updated'] );
		$this->assertContains( 'log_memory', $result['updated'] );
		$this->assertSame( [ 'init', 'shutdown' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
		$this->assertSame( 1500, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
		// Unspecified keys must NOT be written.
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_urls', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_significant_events', $GLOBALS['_wp_options'] );
	}

	public function test_config_update_persists_autoload_per_policy(): void {
		// Hot-path small scalars must be autoloaded (ride the one alloptions
		// query); large list options (log_events / custom_events) stay off
		// autoload so they don't bloat every frontend request's alloptions
		// blob. Config::autoload_for() is the single source of truth; every
		// write path must honor it.
		$GLOBALS['_wp_option_autoload'] = [];
		$ci                             = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[
				'log_memory' => true,            // small scalar → autoloaded
				'log_events' => [ 'init' ],      // large list  → NOT autoloaded
			]
		);

		$this->assertTrue(
			$GLOBALS['_wp_option_autoload']['newspack_event_logger_nodes_log_memory'],
			'small hot-path scalar must be autoloaded'
		);
		$this->assertFalse(
			$GLOBALS['_wp_option_autoload']['newspack_event_logger_nodes_log_events'],
			'large list option must stay off autoload'
		);
	}

	public function test_hooks_configure_keeps_log_events_off_autoload(): void {
		// hooks_configure is a second writer of log_events / custom_events;
		// it must honor the same autoload policy as config_update — the
		// large lists stay off the per-request alloptions blob.
		$GLOBALS['_wp_option_autoload'] = [];
		$ci                             = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'hooks_configure',
			[ 'hooks' => [ 'init', 'shutdown' ], 'custom_events' => [ 'my_event' ] ]
		);

		$this->assertFalse(
			$GLOBALS['_wp_option_autoload']['newspack_event_logger_nodes_log_events']
		);
		$this->assertFalse(
			$GLOBALS['_wp_option_autoload']['newspack_event_logger_nodes_custom_events']
		);
	}

	public function test_config_update_verb_flattens_array_assoc_shape(): void {
		// Legacy `array_assoc` branch: the React tree sends URL lists as
		// `{url: ''}` objects to play nicely with controlled inputs. The
		// controller flattens that into a deduped value array.
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[
				'log_urls' => [
					'/articles'      => '',
					'/home'          => '',
					'duplicate-key'  => 'duplicate-key',
				],
			]
		);

		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls'];
		$this->assertContains( '/articles', $saved );
		$this->assertContains( '/home', $saved );
		// Duplicates collapsed via array_unique.
		$this->assertSame( \count( $saved ), \count( \array_unique( $saved ) ) );
	}

	public function test_config_update_verb_converts_array_bool_indexed_list(): void {
		// Legacy `array_bool` branch: indexed list of strings becomes
		// `{name: true}` map for the custom_events option.
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[
				'custom_events' => [ 'event_one', 'event_two' ],
			]
		);

		$this->assertSame(
			[ 'event_one' => true, 'event_two' => true ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events']
		);
	}

	public function test_config_update_verb_coerces_int_float_bool_types(): void {
		// Each scalar key gets a hard cast to int/float/bool — legacy
		// PerfConfigController::update_config does the same on the way to
		// update_option.
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[
				'auto_disable_threshold'      => '750',
				'auto_protect_time_threshold' => '1.25',
				'log_memory'                  => 1,
				'flush_every_line'            => 0,
			]
		);

		$this->assertSame( 750, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] );
		$this->assertEqualsWithDelta( 1.25, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_protect_time_threshold'], 0.001 );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
		$this->assertFalse( $GLOBALS['_wp_options']['newspack_event_logger_nodes_flush_every_line'] );
	}

	public function test_config_update_verb_no_op_when_no_known_keys(): void {
		// Unknown keys are silently ignored (legacy parity — the loop only
		// considers keys present in CONFIG_MAP). Response should reflect zero
		// updates and no options should be written.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[ 'not_a_real_setting' => 'whatever' ]
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( [], $result['updated'] );
		$this->assertSame( [], $GLOBALS['_wp_options'] );
	}

	public function test_config_update_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'config_update',
			[ 'log_events' => [ 'init' ] ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
		// Confirm no write happened on the rejected path.
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_events', $GLOBALS['_wp_options'] );
	}

	// -------------------------------------------------------------------------
	// settings_update verb — replaces PerfSettingsController::update_setting.
	// Distinct from Settings_CI's `update`: that handles the four substrate
	// integer settings (newspack_nodes_*); this handles the nine perf-tuning
	// options (newspack_event_logger_nodes_*), with the array/int/float/bool
	// type-coerced sanitization regime + suppress_sync guard inherited from
	// the legacy PerfSettingsController.
	// -------------------------------------------------------------------------

	public function test_settings_update_verb_writes_bool_option(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_memory',
				'value'  => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'newspack_event_logger_nodes_log_memory', $result['option'] );
		$this->assertTrue( $result['updated'] );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	public function test_settings_update_verb_writes_int_option(): void {
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_auto_disable_threshold',
				'value'  => 50,
			]
		);

		$this->assertSame( 50, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] );
	}

	public function test_settings_update_verb_writes_float_option(): void {
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_auto_protect_time_threshold',
				'value'  => 1.5,
			]
		);

		$this->assertEqualsWithDelta(
			1.5,
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_protect_time_threshold'],
			0.001
		);
	}

	public function test_settings_update_verb_writes_array_option(): void {
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_events',
				'value'  => [ 'init', 'shutdown' ],
			]
		);

		$this->assertSame(
			[ 'init', 'shutdown' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	public function test_settings_update_verb_array_sanitizes_text_values(): void {
		$ci     = new Performance_CI_Node();
		VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_events',
				'value'  => [ '<b>init</b>', "  trim_me\t" ],
			]
		);

		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( 'init', $saved[0] );
		$this->assertSame( 'trim_me', $saved[1] );
	}

	public function test_settings_update_verb_rejects_unknown_option(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'arbitrary_option',
				'value'  => 'x',
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_int_overflow(): void {
		// MAX_INT_VALUE in legacy PerfSettingsController is 1073741824 (2^30).
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_auto_disable_threshold',
				'value'  => 2 ** 31,
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_negative_int(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_auto_disable_threshold',
				'value'  => -5,
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_non_numeric_int(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_auto_disable_threshold',
				'value'  => 'banana',
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_float_overflow(): void {
		// Float upper bound in legacy controller is 86400 (24h in seconds).
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_auto_protect_time_threshold',
				'value'  => 99999.0,
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_non_array_for_array_option(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_events',
				'value'  => 'not-an-array',
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_excessive_array_count(): void {
		// MAX_EVENTS in legacy is 10000.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_events',
				'value'  => \array_fill( 0, 10001, 'x' ),
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_array_too_deep(): void {
		// Legacy sanitize_array depth cap is 5; 7 levels of nesting trips it.
		$deep = 'value';
		for ( $i = 0; $i < 7; $i++ ) {
			$deep = [ 'nest' => $deep ];
		}

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_events',
				'value'  => $deep,
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_settings_update_verb_requires_option_param(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[ 'value' => true ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'option', \strtolower( $result ) );
	}

	public function test_settings_update_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'settings_update',
			[
				'option' => 'newspack_event_logger_nodes_log_memory',
				'value'  => true,
			]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_memory', $GLOBALS['_wp_options'] );
	}

	// -------------------------------------------------------------------------
	// request_log_list verb — replaces RequestLogController::get_list.
	// -------------------------------------------------------------------------

	public function test_request_log_list_verb_returns_data_meta_envelope(): void {
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_list',
			[ 'limit' => 10 ]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'limit', $result['meta'] );
		$this->assertArrayHasKey( 'scanned', $result['meta'] );
		$this->assertSame( 10, $result['meta']['limit'] );
	}

	public function test_request_log_list_verb_default_limit_is_100(): void {
		// Legacy RequestLogController default — `limit=100`.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'request_log_list' );

		$this->assertSame( 100, $result['meta']['limit'] );
	}

	public function test_request_log_list_verb_clamps_limit_high(): void {
		// Mirrors `min(1000, max(1, (int)$v))` from legacy sanitize_callback.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_list',
			[ 'limit' => 5000 ]
		);

		$this->assertSame( 1000, $result['meta']['limit'] );
	}

	public function test_request_log_list_verb_clamps_limit_low(): void {
		// Floor of 1 for limit values <= 0.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_list',
			[ 'limit' => 0 ]
		);

		$this->assertSame( 1, $result['meta']['limit'] );
	}

	public function test_request_log_list_verb_returns_indexed_entries(): void {
		// Rids ≤32 chars round-trip through the fixed-width .idx field.
		$rid1 = $this->write_request( [
			'rid'            => 'rid-aa-1234567890123456789012345',
			'url'            => '/page-1',
			'timestamp'      => 1700000100,
			'duration_ms'    => 42,
			'status_code'    => 200,
			'peak_mb'        => 5,
			'request_method' => 'GET',
		] );
		$rid2 = $this->write_request( [
			'rid'            => 'rid-bb-1234567890123456789012345',
			'url'            => '/page-2',
			'timestamp'      => 1700000200,
			'duration_ms'    => 100,
			'status_code'    => 500,
			'peak_mb'        => 10,
			'request_method' => 'POST',
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_list',
			[ 'limit' => 10 ]
		);

		$this->assertCount( 2, $result['data'] );
		// Sorted by timestamp DESC — rid2 (1700000200) first.
		$this->assertSame( $rid2, $result['data'][0]['rid'] );
		$this->assertSame( $rid1, $result['data'][1]['rid'] );

		// Verify the documented entry shape.
		$first = $result['data'][0];
		foreach ( [ 'rid', 'url_hash', 'timestamp', 'duration_ms', 'status_code', 'peak_mb', 'method', 'partition' ] as $key ) {
			$this->assertArrayHasKey( $key, $first );
		}
		$this->assertSame( 1700000200, $first['timestamp'] );
		$this->assertSame( 100, $first['duration_ms'] );
		$this->assertSame( 500, $first['status_code'] );
		$this->assertSame( 'POST', $first['method'] );
		$this->assertSame( 0, $first['partition'] );
	}

	public function test_request_log_list_verb_respects_limit(): void {
		// Write 3 requests; ask for 2.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->write_request( [
				'rid'            => "rid-{$i}-123456789012345678901234",
				'url'            => "/page-{$i}",
				'timestamp'      => 1700000000 + $i,
				'duration_ms'    => 1,
				'status_code'    => 200,
				'peak_mb'        => 1,
				'request_method' => 'GET',
			] );
		}

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_list',
			[ 'limit' => 2 ]
		);

		$this->assertCount( 2, $result['data'] );
		$this->assertSame( 1700000002, $result['data'][0]['timestamp'] );
		$this->assertSame( 1700000001, $result['data'][1]['timestamp'] );
	}

	public function test_request_log_list_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'request_log_list' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// request_log_detail verb — replaces RequestLogController::get_detail.
	// -------------------------------------------------------------------------

	public function test_request_log_detail_verb_with_unknown_id_returns_empty_entries(): void {
		// Legacy stub-compatible behavior: missing-but-not-empty rid returns
		// the data envelope with empty `entries` rather than throwing.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_detail',
			[ 'id' => 'rid-xyz' ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'rid-xyz', $result['data']['request_id'] );
		$this->assertArrayHasKey( 'entries', $result['data'] );
		$this->assertSame( [], $result['data']['entries'] );
		$this->assertArrayHasKey( 'scanned', $result['meta'] );
	}

	public function test_request_log_detail_verb_without_id_errors(): void {
		// Empty id is a genuine usage error — legacy controller surfaces 404
		// via not_found_error(). CI verb throws so the central catch turns
		// it into TM_COMMAND|TM_ERROR.
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire( $ci, 'performance', 'request_log_detail' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'id required', \strtolower( $result ) );
	}

	public function test_request_log_detail_verb_with_known_id_returns_envelope(): void {
		// Rid ≤32 chars so fixed-width .idx field preserves it exactly.
		$rid = $this->write_request( [
			'rid'            => 'rid-detail-123456789012345678901',
			'url'            => '/found',
			'timestamp'      => 1700000300,
			'duration_ms'    => 25,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
			'events'         => [
				[ 'k' => 'process (start)', 'm' => '/found', 'ts' => 1700000300.0 ],
				[ 'k' => 'process (complete)', 'm' => '/found', 'ts' => 1700000300.025 ],
			],
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_detail',
			[ 'id' => $rid ]
		);

		$this->assertSame( $rid, $result['data']['request_id'] );
		$this->assertCount( 2, $result['data']['entries'] );
		$this->assertSame( 'process (start)', $result['data']['entries'][0]['k'] );
		$this->assertSame( 'process (complete)', $result['data']['entries'][1]['k'] );
	}

	public function test_request_log_detail_verb_without_events_wraps_envelope(): void {
		// When body has no 'events' key, the body itself is wrapped as a single
		// entry (mirrors legacy RequestLogController::get_detail behavior).
		$rid = $this->write_request( [
			'rid'            => 'rid-noevt-1234567890123456789012',
			'url'            => '/no-events',
			'timestamp'      => 1700000400,
			'duration_ms'    => 5,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );

		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_detail',
			[ 'id' => $rid ]
		);

		$this->assertCount( 1, $result['data']['entries'] );
		$this->assertSame( $rid, $result['data']['entries'][0]['rid'] );
		// Legacy controller marks the synthesized entry with _partition.
		$this->assertSame( 0, $result['data']['entries'][0]['_partition'] );
	}

	public function test_request_log_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$ci,
			'performance',
			'request_log_detail',
			[ 'id' => 'rid-anything' ]
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// ── schema-driven dispatch ──────────────────────────────────────────────

	public function test_node_schema_lists_all_verbs_with_handlers(): void {
		$expected = [
			'overview', 'urls', 'url_detail', 'request_search', 'request_detail',
			'timing', 'dashboard', 'hooks_registered', 'hooks_categories',
			'hooks_available', 'hooks_configure', 'config_get', 'config_update',
			'settings_update', 'request_log_list',
			'request_log_detail',
		];

		$verbs = [];
		foreach ( Performance_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}

		foreach ( $expected as $name ) {
			$this->assertArrayHasKey( $name, $verbs, "node_schema must list the '{$name}' verb" );
			$this->assertIsCallable( $verbs[ $name ]['handler'], "the '{$name}' verb must carry a callable handler" );
		}
	}

	public function test_no_input_verbs_declare_no_args(): void {
		// These verbs read no $payload/$args — they return a fixed shape, so the
		// Inspector fires them immediately with no arg modal.
		$verbs = self::verbs_by_name();
		foreach ( [ 'timing', 'dashboard', 'hooks_registered', 'hooks_categories', 'hooks_available', 'config_get' ] as $name ) {
			$this->assertSame( [], $verbs[ $name ]['args'], "'{$name}' must declare no args" );
		}
	}

	public function test_overview_verb_declares_optional_filters(): void {
		// overview reads server / breakdown / categories (all optional).
		$args = self::args_by_name( 'overview' );
		$this->assertSame( [ 'server', 'breakdown', 'categories' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertSame( 'string', $args['breakdown']['type'] );
		$this->assertSame( 'bool', $args['categories']['type'] );
		foreach ( $args as $arg ) {
			$this->assertFalse( $arg['required'] );
		}
	}

	public function test_urls_verb_declares_sort_paging_filter_args(): void {
		// urls reads sort/order/limit/offset/search/server — all optional.
		$args = self::args_by_name( 'urls' );
		$this->assertSame(
			[ 'sort', 'order', 'limit', 'offset', 'search', 'server' ],
			\array_keys( $args )
		);
		$this->assertSame( 'string', $args['sort']['type'] );
		$this->assertSame( 'string', $args['order']['type'] );
		$this->assertSame( 'int', $args['limit']['type'] );
		$this->assertSame( 50, $args['limit']['default'] );
		$this->assertSame( 'int', $args['offset']['type'] );
		$this->assertSame( 0, $args['offset']['default'] );
		$this->assertSame( 'string', $args['search']['type'] );
		$this->assertSame( 'string', $args['server']['type'] );
		foreach ( $args as $arg ) {
			$this->assertFalse( $arg['required'] );
		}
	}

	public function test_url_detail_verb_declares_required_hash_plus_filters(): void {
		// url_detail requires hash (regex check throws on empty/bad) + optional
		// breakdown/categories.
		$args = self::args_by_name( 'url_detail' );
		$this->assertSame( [ 'hash', 'breakdown', 'categories' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertFalse( $args['breakdown']['required'] );
		$this->assertSame( 'bool', $args['categories']['type'] );
		$this->assertFalse( $args['categories']['required'] );
	}

	public function test_request_search_verb_declares_required_rid(): void {
		// request_search throws 'rid required' when absent → required string.
		$args = self::args_by_name( 'request_search' );
		$this->assertSame( [ 'rid' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['rid']['type'] );
		$this->assertTrue( $args['rid']['required'] );
	}

	public function test_request_detail_verb_declares_required_rid_optional_partition(): void {
		// request_detail throws on empty rid; partition defaults to 0.
		$args = self::args_by_name( 'request_detail' );
		$this->assertSame( [ 'rid', 'partition' ], \array_keys( $args ) );
		$this->assertTrue( $args['rid']['required'] );
		$this->assertSame( 'int', $args['partition']['type'] );
		$this->assertFalse( $args['partition']['required'] );
		$this->assertSame( 0, $args['partition']['default'] );
	}

	public function test_hooks_configure_verb_declares_optional_json_lists(): void {
		// hooks_configure reads hooks + custom_events arrays (both optional).
		$args = self::args_by_name( 'hooks_configure' );
		$this->assertSame( [ 'hooks', 'custom_events' ], \array_keys( $args ) );
		$this->assertSame( 'json', $args['hooks']['type'] );
		$this->assertSame( 'json', $args['custom_events']['type'] );
		foreach ( $args as $arg ) {
			$this->assertFalse( $arg['required'] );
		}
	}

	public function test_config_update_verb_declares_the_nine_optional_options(): void {
		// config_update sweeps CONFIG_MAP — nine optional perf-tuning keys.
		$args = self::args_by_name( 'config_update' );
		$this->assertSame(
			[
				'log_events', 'custom_events', 'log_urls', 'skip_urls',
				'auto_disable_threshold', 'auto_protect_time_threshold',
				'significant_events', 'log_memory', 'flush_every_line',
			],
			\array_keys( $args )
		);
		// array_assoc / array_bool config types surface as json payload fields.
		foreach ( [ 'log_events', 'custom_events', 'log_urls', 'skip_urls', 'significant_events' ] as $name ) {
			$this->assertSame( 'json', $args[ $name ]['type'], "{$name} must be json" );
		}
		$this->assertSame( 'int', $args['auto_disable_threshold']['type'] );
		$this->assertSame( 'float', $args['auto_protect_time_threshold']['type'] );
		$this->assertSame( 'bool', $args['log_memory']['type'] );
		$this->assertSame( 'bool', $args['flush_every_line']['type'] );
		foreach ( $args as $arg ) {
			$this->assertFalse( $arg['required'] );
		}
	}

	public function test_settings_update_verb_declares_required_option_and_value(): void {
		// settings_update throws 'option required' / 'value required' → both required.
		$args = self::args_by_name( 'settings_update' );
		$this->assertSame( [ 'option', 'value' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['option']['type'] );
		$this->assertTrue( $args['option']['required'] );
		// $value is mixed (int|float|bool|array depending on the option) — string
		// is the renderable catch-all the Inspector can collect.
		$this->assertSame( 'string', $args['value']['type'] );
		$this->assertTrue( $args['value']['required'] );
	}

	public function test_request_log_list_verb_declares_optional_limit(): void {
		// request_log_list clamps limit 1..1000, default 100 → optional int.
		$args = self::args_by_name( 'request_log_list' );
		$this->assertSame( [ 'limit' ], \array_keys( $args ) );
		$this->assertSame( 'int', $args['limit']['type'] );
		$this->assertFalse( $args['limit']['required'] );
		$this->assertSame( 100, $args['limit']['default'] );
	}

	public function test_request_log_detail_verb_declares_required_id(): void {
		// request_log_detail throws 'id required' when empty → required string.
		$args = self::args_by_name( 'request_log_detail' );
		$this->assertSame( [ 'id' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['id']['type'] );
		$this->assertTrue( $args['id']['required'] );
	}

	/**
	 * node_schema()['commands'] indexed by verb name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function verbs_by_name(): array {
		$verbs = [];
		foreach ( Performance_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}
		return $verbs;
	}

	/**
	 * A verb's args[] indexed by arg name.
	 *
	 * @param string $verb Verb name.
	 * @return array<string,array<string,mixed>>
	 */
	private static function args_by_name( string $verb ): array {
		$out = [];
		foreach ( self::verbs_by_name()[ $verb ]['args'] as $arg ) {
			$out[ $arg['name'] ] = $arg;
		}
		return $out;
	}
}
