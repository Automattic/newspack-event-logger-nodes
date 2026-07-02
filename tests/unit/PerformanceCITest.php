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
use Newspack_Nodes\Settings_Event_Writer;
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
		Settings_Event_Writer::$append_seam = null;
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
			$out[] = $this->bucket_key_for( $now - ( $i * 300 ) );
		}
		return \array_values( \array_unique( $out ) );
	}

	private function current_url_bucket(): string {
		return $this->bucket_key_for( \time() );
	}

	private function bucket_key_for( int $timestamp ): string {
		$min        = (int) \gmdate( 'i', $timestamp );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $timestamp ) . '-' . $bucket_min;
	}

	private function write_request( array $body, int $partition = 0 ): string {
		$rid          = $body['rid'];
		$segment_dir  = $this->tmp . "/logs/requests.p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$message[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $message );

		$seg_path = "{$segment_dir}/0.log";
		$existing = \file_exists( $seg_path ) ? \file_get_contents( $seg_path ) : '';
		$offset   = \strlen( (string) $existing );
		\file_put_contents( $seg_path, $existing . $packed, LOCK_EX );

		$position   = [
			'segment_id' => 0,
			'offset'     => $offset,
			'length'     => \strlen( $packed ),
		];
		$index_line = Request_Builder_Node::format_index_entry( $message, $position );
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return $rid;
	}

	private function write_flame( array $body, int $partition = 0 ): string {
		$rid          = $body['rid'];
		$segment_dir  = $this->tmp . "/logs/flames.p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$message[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $message );

		$seg_path = "{$segment_dir}/0.log";
		$existing = \file_exists( $seg_path ) ? \file_get_contents( $seg_path ) : '';
		$offset   = \strlen( (string) $existing );
		\file_put_contents( $seg_path, $existing . $packed, LOCK_EX );

		$position   = [
			'segment_id' => 0,
			'offset'     => $offset,
			'length'     => \strlen( $packed ),
		];
		$index_line = Flame_Builder_Node::format_index_entry( $message, $position );
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
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

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
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/articles/123',
				'count'     => 7,
				'sum_ms'    => 350.0,
				'last_seen' => 1700000000,
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertSame( 1, $result['total_urls'] );
		$this->assertCount( 1, $result['most_requested'] );
		$this->assertSame( '/articles/123', $result['most_requested'][0]['url'] );
		$this->assertSame( 7, $result['most_requested'][0]['count'] );
	}

	public function test_overview_verb_rejects_unauthorized(): void {
		// Legacy controller gates every verb via read_permissions_check ==
		// manage_options. Performance_CI matches that.
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_overview_verb_includes_global_leaderboard_by_default(): void {
		// OverviewSection (React) renders `overview.global_leaderboard.{categories,total_time,count}`
		// (see components/OverviewSection.js L330-358). Legacy PerfOverviewController::get_overview
		// emits `global_leaderboard` unconditionally (L95-97). The interpreter verb must match.
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertArrayHasKey( 'global_leaderboard', $result );
		$this->assertSame( 4, $result['global_leaderboard']['count'] );
		$this->assertArrayHasKey( 'categories', $result['global_leaderboard'] );
		$this->assertArrayHasKey( 'db', $result['global_leaderboard']['categories'] );
		$this->assertArrayHasKey( 'total_time', $result['global_leaderboard'] );
	}

	public function test_overview_verb_uses_server_leaderboard_when_server_arg_set(): void {
		// `server` arg scopes the leaderboard to that server (legacy L95-97
		// switches to `build_server_leaderboard`). The interpreter verb must reroute.
		$store   = new Stats_Store( 0, 86400 );
		$buckets = $this->recent_url_buckets();
		$store->set_server_leaderboard_bucket( 'web01', $buckets[0], [
			'count'        => 2,
			'sum_req_time' => 0.2,
			'categories'   => [
				'db' => [ 'samples' => 2, 'sum_time' => 0.1, 'sum_count' => 4 ],
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=web01'
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--categories'
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=server'
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=server,status'
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
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=nosuchdim'
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=web01 --categories'
		);

		// Server-scoped data, not the global ones.
		$this->assertSame( 0.2, $result['category_time_series']['2026-05-17-10-00']['db']['t'] );
	}

	// -------------------------------------------------------------------------
	// urls verb
	// -------------------------------------------------------------------------

	public function test_urls_verb_returns_envelope_when_empty(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

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
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 50, $result['limit'] );
	}

	public function test_urls_verb_clamps_limit_high(): void {
		// Mirrors `min(1000, max(1, (int)$v))` from legacy sanitize_callback.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--limit=5000'
		);
		$this->assertSame( 1000, $result['limit'] );
	}

	public function test_urls_verb_paginates_and_sorts(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000002 ],
			'cccccccccccc' => [ 'url' => '/c', 'count' => 3, 'sum_ms' => 300.0, 'last_seen' => 1700000003 ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=count --order=desc --limit=2 --offset=0'
		);

		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 2, $result['data'] );
		// Desc by count: /b first (5), /c second (3).
		$this->assertSame( '/b', $result['data'][0]['url'] );
		$this->assertSame( '/c', $result['data'][1]['url'] );
	}

	public function test_urls_verb_filters_by_search_term(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/articles/123', 'count' => 1, 'sum_ms' => 50.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/home', 'count' => 2, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--search=article'
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( '/articles/123', $result['data'][0]['url'] );
	}

	public function test_urls_verb_heals_poisoned_min_ms_sentinel(): void {
		// A URL whose every persisted bucket is untimed carries the
		// PHP_INT_MAX sentinel as min_ms (worker / timed-out requests). The
		// display must never surface the sentinel — it heals to 0.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

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
		$bucket_b = $this->current_url_bucket();
		$bucket_a = $this->bucket_key_for( \time() - 600 );

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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 42, $result['data'][0]['min_ms'] );
	}

	public function test_urls_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// url_detail verb
	// -------------------------------------------------------------------------

	public function test_url_detail_verb_rejects_invalid_hash(): void {
		// Legacy `get_url_detail` returns invalid_hash 400 when hash regex fails.
		// We surface that as a verb error string (interpreter errors are string-encoded).
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'not-a-hash'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_url_detail_verb_returns_not_found_when_unknown_hash(): void {
		// Hash matches the regex but doesn't exist in the URL index — legacy
		// surfaces a 404 with "URL not found".
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'deadbeefcafe'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_url_detail_verb_returns_stats_and_default_flame(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/articles/777',
				'count'     => 9,
				'sum_ms'    => 450.0,
				'p95_ms'    => 80.0,
				'last_seen' => 1700000999,
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456'
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
		$bucket = $this->current_url_bucket();
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'cafebabe1234'
		);

		$this->assertSame( 100, $result['aggregate_flame']['value'] );
		$this->assertSame( 1700001111, $result['last_modified'] );
	}

	public function test_url_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_url_detail_verb_includes_stats_time_series(): void {
		// `stats.time_series` is consumed by UrlDetailView L232/L273 +
		// PerformanceDashboard.js L727-741 (urlRequestsPerSecond computation).
		// Legacy PerfUrlsController::find_url_stats L228 calls `build_url_time_series`
		// which walks the recent buckets keyed by hash. The interpreter verb must too.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 3,
				'sum_ms'    => 150.0,
				'last_seen' => 1700001000,
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456'
		);

		$this->assertArrayHasKey( 'time_series', $result['stats'] );
		$this->assertArrayHasKey( $bucket, $result['stats']['time_series'] );
		$this->assertSame( 3, $result['stats']['time_series'][ $bucket ]['count'] );
	}

	public function test_url_detail_verb_includes_breakdown_time_series_when_arg_set(): void {
		// `?breakdown=method` on /urls/{hash} emits `breakdown_time_series`
		// (legacy L195, L177-181). Consumed by fetchUrlBreakdown L213.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456 --breakdown=method'
		);

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 3, $result['breakdown_time_series']['2026-05-17-10-00']['GET']['c'] );
	}

	public function test_url_detail_verb_includes_category_time_series_when_arg_set(): void {
		// `?categories=1` on /urls/{hash} emits `category_time_series`
		// (legacy L196, L184-186). Consumed by UrlDetailView L282-295 +
		// fetchUrlCategories L237.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456 --categories'
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
		$this->assertSame( 0.2, $result['category_time_series']['2026-05-17-10-00']['db']['t'] );
	}

	public function test_url_detail_verb_breakdown_filters_unknown_dims(): void {
		// Unknown dim → no breakdown_time_series (matches legacy L179's
		// `in_array(...,DIMENSIONS,true)` guard).
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'abc123def456' => [ 'url' => '/x', 'count' => 1, 'sum_ms' => 10.0, 'last_seen' => 1700001000 ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456 --breakdown=nosuchdim'
		);

		$this->assertArrayNotHasKey( 'breakdown_time_series', $result );
	}

	// -------------------------------------------------------------------------
	// request_search verb
	// -------------------------------------------------------------------------

	public function test_request_search_verb_returns_not_found_when_missing(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_search',
			'no-such-rid'
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_search',
			$rid
		);

		$this->assertIsArray( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertNotEmpty( $result['url_hash'] );
	}

	public function test_request_search_verb_requires_rid(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'request_search' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'rid required', \strtolower( $result ) );
	}

	public function test_request_search_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_search',
			'whatever'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// request_detail verb
	// -------------------------------------------------------------------------

	public function test_request_detail_verb_returns_not_found_when_missing(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			'no-such-rid'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_request_detail_verb_rejects_invalid_partition(): void {
		// num_partitions = 1 (test setUp), partition = 5 is out of range.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			'whatever --partition=5'
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			$rid
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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			$rid
		);

		$this->assertArrayHasKey( 'flame_data', $result );
	}

	public function test_request_detail_verb_merges_flame_data_at_max_stack_depth(): void {
		// A MAX_STACK_DEPTH (50) flame nests ~2 JSON levels per span. Both the
		// write-side index formatter and this read path used depth-64 decodes,
		// so deep flames were written but never indexed or returned.
		$rid = $this->write_request( [
			'rid'            => 'rid-deep-flame-12345678901234567',
			'url'            => '/with-deep-flame',
			'timestamp'      => 1700000700,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );

		$flame = [ 'name' => 'leaf', 'value' => 1, 'children' => [] ];
		for ( $i = 0; $i < 49; $i++ ) {
			$flame = [ 'name' => "level{$i}", 'value' => 1, 'children' => [ $flame ] ];
		}
		$flame['rid']      = $rid;
		$flame['url_hash'] = Request_Builder_Node::url_hash( '/with-deep-flame' );
		$this->write_flame( $flame );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			$rid
		);

		$this->assertArrayHasKey( 'flame_data', $result );
		$node = $result['flame_data'];
		for ( $depth = 0; \is_array( $node ) && ! empty( $node['children'] ); $depth++ ) {
			$node = $node['children'][0];
		}
		$this->assertSame( 49, $depth, 'deep flame should round-trip intact' );
	}

	public function test_request_detail_verb_requires_rid(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			'--partition=0'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'rid required', \strtolower( $result ) );
	}

	public function test_request_detail_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'request_detail',
			'whatever'
		);

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

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'hooks_registered' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_hooks', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'hooks_by_category', $result );
	}

	public function test_hooks_registered_verb_total_matches_summed_buckets(): void {
		$this->seed_wp_filter_with_known_hooks();

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'hooks_registered' );

		$summed = 0;
		foreach ( $result['hooks_by_category'] as $bucket ) {
			$summed += \count( $bucket );
		}
		$this->assertSame( $result['total_hooks'], $summed );
	}

	public function test_hooks_registered_verb_includes_seeded_hooks(): void {
		$this->seed_wp_filter_with_known_hooks();

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'hooks_registered' );

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
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'hooks_registered' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// set verb (normalized positional receiver) — nine-option whitelist +
	// array/int/float/bool type-coerced sanitization. Positional
	// `set <option> <value>`.
	// -------------------------------------------------------------------------

	public function test_set_verb_writes_array_option_csv_split(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_log_urls "a.com,b.com"'
		);

		$this->assertSame(
			[ 'a.com', 'b.com' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls']
		);
	}

	public function test_set_verb_preserves_associative_array_via_json(): void {
		// custom_events is an associative map (event_name => true). Settings_Sync_Node
		// now ships array options as JSON (built through Command_Args::format, so the
		// quotes are escaped on the wire); the receiver must json_decode it and keep
		// the keys — the old csv-split flattened it to a meaningless list of "1"s.
		$args = \Newspack_Nodes\Command_Args::format(
			[
				'newspack_event_logger_nodes_custom_events',
				(string) \json_encode( [ 'advancedemail' => true, 'amazons3' => true ] ),
			],
			[]
		);

		$interpreter = new Performance_CI_Node();
		VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		$this->assertSame(
			[ 'advancedemail' => true, 'amazons3' => true ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events']
		);
	}

	public function test_set_verb_writes_float_option(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_auto_protect_time_threshold 1.5'
		);

		$this->assertEqualsWithDelta(
			1.5,
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_protect_time_threshold'],
			0.001
		);
	}

	public function test_set_verb_rejects_unknown_option(): void {
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'arbitrary_option x'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown', \strtolower( $result ) );
		$this->assertArrayNotHasKey( 'arbitrary_option', $GLOBALS['_wp_options'] );
	}

	public function test_set_verb_writes_valid_int_option(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_auto_disable_threshold 42'
		);

		$this->assertSame( 42, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] );
	}

	public function test_set_verb_rejects_non_numeric_int(): void {
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_auto_disable_threshold notanumber'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', \strtolower( $result ) );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_auto_disable_threshold', $GLOBALS['_wp_options'] );
	}

	public function test_set_verb_rejects_out_of_range_int(): void {
		// > SETTINGS_INT_MAX (2^30) → rejected.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_auto_disable_threshold 2000000000'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', \strtolower( $result ) );
	}

	public function test_set_verb_rejects_non_numeric_float(): void {
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_auto_protect_time_threshold notafloat'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', \strtolower( $result ) );
	}

	public function test_set_verb_rejects_out_of_range_float(): void {
		// > SETTINGS_FLOAT_MAX (86400s) → rejected.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_auto_protect_time_threshold 99999'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', \strtolower( $result ) );
	}

	public function test_set_verb_coerces_bool_option(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_log_memory 1'
		);

		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	public function test_set_verb_preserves_nested_array_via_json(): void {
		// A nested map must recurse one level and keep the structure (sanitize_array
		// recursion branch), not flatten or reject it.
		$args = \Newspack_Nodes\Command_Args::format(
			[
				'newspack_event_logger_nodes_custom_events',
				(string) \json_encode( [ 'group' => [ 'inner' => 'val' ] ] ),
			],
			[]
		);

		$interpreter = new Performance_CI_Node();
		VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		$this->assertSame(
			[ 'group' => [ 'inner' => 'val' ] ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events']
		);
	}

	public function test_set_verb_rejects_array_nested_too_deep(): void {
		// SETTINGS_ARRAY_DEPTH is 5; a 7-level-deep map blows the depth cap and the
		// whole sanitize returns null → rejected.
		$deep = 'leaf';
		for ( $i = 0; $i < 7; $i++ ) {
			$deep = [ 'n' => $deep ];
		}
		$args = \Newspack_Nodes\Command_Args::format(
			[ 'newspack_event_logger_nodes_custom_events', (string) \json_encode( $deep ) ],
			[]
		);

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', \strtolower( $result ) );
	}

	public function test_set_verb_requires_option(): void {
		// No positional args → 'option required'.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'set' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'option required', \strtolower( $result ) );
	}

	public function test_set_verb_empty_array_value_yields_empty_list(): void {
		// An empty value decodes (json fails → csv split of '') to [].
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_log_urls ""'
		);

		$this->assertSame( [], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls'] );
	}

	public function test_sanitize_settings_value_rejects_non_array_and_unknown_type(): void {
		// Two reject paths unreachable through `set` (which always hands an array
		// for array-typed options and only ever a whitelisted type): a non-array
		// value for the array branch, and an unrecognized type.
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );
		$ref->setAccessible( true );

		$this->assertNull( $ref->invoke( null, 'not-an-array', 'array' ) );
		$this->assertNull( $ref->invoke( null, 'whatever', 'nonexistent-type' ) );
	}

	// ── urls verb fallbacks + server filter ─────────────────────────────────

	public function test_urls_verb_falls_back_to_defaults_on_invalid_sort_and_order(): void {
		// Out-of-whitelist sort/order silently fall back to count/desc; an
		// unmatched server filter empties the result.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/only-host', 'count' => 3, 'sum_ms' => 30.0, 'last_seen' => 1700000001 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=bogus --order=bogus --server=nomatchhost'
		);

		// Server filter removed the only row.
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['data'] );
	}

	public function test_urls_verb_sorts_ascending(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=count --order=asc'
		);

		// Ascending by count: /b (1) before /a (5).
		$this->assertSame( '/b', $result['data'][0]['url'] );
		$this->assertSame( '/a', $result['data'][1]['url'] );
	}

	// ── overview categories flag (string-valued flag) ───────────────────────

	public function test_overview_categories_flag_accepts_explicit_truthy_value(): void {
		// `--categories=1` exercises the string-valued flag resolution (not the
		// bare `--categories` true). The categories slice is added even with no data.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--categories=1'
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
	}

	// ── load_index StatsAggregator-shaped buckets ───────────────────────────

	public function test_overview_handles_url_string_keyed_buckets_with_sum_req_time(): void {
		// A StatsAggregator-style bucket keys by URL string directly (no embedded
		// `url` field) and carries `sum_req_time` (seconds) instead of `sum_ms`.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			'/aggregator-style' => [ 'count' => 4, 'sum_req_time' => 0.8, 'last_seen' => 1700000000 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertSame( 1, $result['total_urls'] );
		$this->assertSame( '/aggregator-style', $result['most_requested'][0]['url'] );
		// sum_ms = sum_req_time*1000 = 800; avg over 4 = 200ms.
		$this->assertEqualsWithDelta( 200.0, $result['most_requested'][0]['avg_ms'], 0.01 );
	}

	public function test_urls_verb_folds_min_ms_across_two_timed_buckets(): void {
		// Two timed buckets for the same hash: the read merge must take the min of
		// both bucket minima (the second fold hits the min() branch).
		$store    = new Stats_Store( 0, 86400 );
		$hash     = 'abcabcabc123';
		$bucket_a = $this->bucket_key_for( \time() - 600 );
		$bucket_b = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket_a, [
			$hash => [ 'url' => '/m', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 200.0, 'min_ms' => 80, 'max_ms' => 100.0, 'last_seen' => 1 ],
		] );
		$store->set_url_index_hourly( $bucket_b, [
			$hash => [ 'url' => '/m', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 300.0, 'min_ms' => 30, 'max_ms' => 120.0, 'last_seen' => 2 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 30, $result['data'][0]['min_ms'] );
	}

	// ── find_recent_requests_for_url (disk walk for a known URL) ─────────────

	public function test_url_detail_returns_recent_matching_requests(): void {
		// url_detail's `requests` slice walks requests.log for entries whose
		// url_hash matches. Seed the URL in the memcache index AND two on-disk
		// requests so the collect + dedup walk runs (not the empty-result skip).
		$url    = '/recent-list';
		$hash   = Request_Builder_Node::url_hash( $url );
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			$hash => [ 'url' => $url, 'count' => 2, 'sum_ms' => 32.0, 'last_seen' => 1700002000 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-recent-a-1234567890123456',
			'url'            => $url,
			'timestamp'      => 1700001000,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );
		$this->write_request( [
			'rid'            => 'rid-recent-b-1234567890123456',
			'url'            => $url,
			'timestamp'      => 1700002000,
			'duration_ms'    => 20,
			'status_code'    => 500,
			'peak_mb'        => 3,
			'request_method' => 'POST',
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'url_detail', $hash );

		$this->assertCount( 2, $result['requests'] );
		// Sorted by timestamp DESC → the newest (b, ts 1700002000) leads.
		$this->assertSame( 'rid-recent-b-1234567890123456', $result['requests'][0]['rid'] );
	}

	public function test_url_detail_time_series_accepts_sum_req_time_buckets(): void {
		// build_url_time_series accepts StatsAggregator buckets that carry
		// `sum_req_time` (seconds) rather than `sum_ms`.
		$hash   = 'deadbeef0001';
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_url_index_hourly( $bucket, [
			$hash => [ 'url' => '/agg-detail', 'count' => 2, 'sum_req_time' => 0.4, 'last_seen' => 1700000000 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'url_detail', $hash );

		$this->assertArrayHasKey( $bucket, $result['stats']['time_series'] );
		// sum_ms derived from sum_req_time*1000 = 400.
		$this->assertEqualsWithDelta( 400.0, $result['stats']['time_series'][ $bucket ]['sum_ms'], 0.01 );
	}

	// ── shared memoized index read (slice_verb decomposition) ───────────────

	public function test_index_memo_is_per_request_not_shared_across_instances(): void {
		// The memo is per-CI-instance (per request). A fresh Performance_CI_Node
		// re-reads — two dispatches on two instances each load once. This guards
		// against a static memo leaking stale stats across requests.
		$calls    = 0;
		$original = Performance_CI_Node::$load_index;
		Performance_CI_Node::$load_index = static function () use ( &$calls, $original ) {
			++$calls;
			return ( $original ?? [ Performance_CI_Node::class, 'load_index_default' ] )();
		};

		try {
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );
			// Each fire() builds a fresh request-scope graph; reset Core between
			// the two so the second _router/_command_interpreter don't collide.
			VerbHarness::reset();
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );
			$this->assertSame( 2, $calls, 'each request (instance) must re-read the index' );
		} finally {
			Performance_CI_Node::$load_index = $original;
		}
	}

	// ── schema-driven dispatch ──────────────────────────────────────────────

	public function test_node_schema_lists_all_verbs_with_handlers(): void {
		$expected = [
			'overview', 'urls', 'url_detail', 'request_search', 'request_detail',
			'hooks_registered', 'set',
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
		foreach ( [ 'hooks_registered' ] as $name ) {
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

	public function test_set_verb_declares_required_option_and_value(): void {
		// set throws 'option required' / 'value required' → both required.
		$args = self::args_by_name( 'set' );
		$this->assertSame( [ 'option', 'value' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['option']['type'] );
		$this->assertTrue( $args['option']['required'] );
		// $value is mixed (int|float|bool|array depending on the option) — string
		// is the renderable catch-all the Inspector can collect.
		$this->assertSame( 'string', $args['value']['type'] );
		$this->assertTrue( $args['value']['required'] );
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
