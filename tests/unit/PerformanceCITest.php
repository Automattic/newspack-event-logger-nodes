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
 * Substrate config (num_partitions, min_lifetime, base_directory) is seeded
 * via TestCase::use_base_dir(), matching SettingsCITest / EventsCITest. The
 * shared `Core::$memd` handle is seeded with an in-memory `\Memcached` so the
 * Stats_Store path is exercised without a real memcache server.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Settings_Event_Writer;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

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
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 86400 ] );
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
		// The disk verbs resolve their partitions from the ACTIVE topology's
		// declaration, so the tests need one active. One worker keeps every
		// existing partition-0 assertion true. AFTER the $wp_filter reset above
		// — it registers the catalog filter.
		$this->activate_shipped_topology( 'performance', 1 );
	}

	protected function tearDown(): void {
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
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
	// Stats-retention window: sourced from the substrate `min_lifetime` key
	// (the [138]-renamed name for the min-retention age the readers already
	// read as `max_lifespan`), NOT the deleted `max_lifespan` key.
	// -------------------------------------------------------------------------

	/** stats_stores() reads the retention window from the substrate `min_lifetime` config key. */
	public function test_stats_stores_retention_reads_min_lifetime_config_key(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 4321 ] );

		$method = new \ReflectionMethod( Performance_CI_Node::class, 'stats_stores' );
		/** @var array<int,Stats_Store> $stores */
		$stores = $method->invoke( null );

		$this->assertNotEmpty( $stores );
		$this->assertSame( 4321, $stores[0]->ttl() );
	}

	/** The retired `max_lifespan` key no longer feeds the window — it falls back to the default. */
	public function test_stats_stores_retention_ignores_retired_max_lifespan_key(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'max_lifespan' => 4321 ] );

		$method = new \ReflectionMethod( Performance_CI_Node::class, 'stats_stores' );
		/** @var array<int,Stats_Store> $stores */
		$stores = $method->invoke( null );

		$this->assertNotEmpty( $stores );
		$this->assertSame(
			43200,
			$stores[0]->ttl(),
			'retired substrate key must no longer feed the stats window'
		);
	}

	// -------------------------------------------------------------------------
	// Test helpers — disk-seeded request + flame index entries.
	//
	// Mirror RequestLogControllerTest's `write_request` and the FlameBuilder
	// index layout so the verb's scan_index walk picks up our seeded data.
	// -------------------------------------------------------------------------

	private function current_url_bucket(): string {
		return Stats_Store::bucket_key( \time() );
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
			'segment' => 0,
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
			'segment' => 0,
			'offset'     => $offset,
			'length'     => \strlen( $packed ),
		];
		$index_line = Flame_Builder_Node::format_index_entry( $message, $position );
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return $rid;
	}

	/**
	 * Seed a firehose partition segment with packed Message envelopes (rid at
	 * Message::KEY, entry hash at Message::VALUE) — newline-delimited, the layout
	 * the substrate Consumer line-splits. Mirrors Log_Manager's on-disk shape.
	 *
	 * @param int                              $partition Partition index.
	 * @param array<int, array<string, mixed>> $entries   Firehose entry hashes (each carries `rid`).
	 */
	private function write_firehose( int $partition, array $entries ): void {
		$dir = $this->tmp . "/logs/firehose.p{$partition}";
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0755, true );
		}
		$buffer = '';
		foreach ( $entries as $entry ) {
			$message                       = Message::new_message();
			$message[ Message::TYPE ]      = Message::TM_STRUCT;
			$message[ Message::TIMESTAMP ] = (float) ( $entry['ts'] ?? \time() );
			$message[ Message::KEY ]       = (string) ( $entry['rid'] ?? '' );
			$message[ Message::VALUE ]     = $entry;
			$buffer                       .= Message::packed( $message ) . "\n";
		}
		\file_put_contents( "{$dir}/0.log", $buffer, LOCK_EX );
	}

	public function test_overview_verb_returns_empty_shape_when_no_data(): void {
		// No URL buckets seeded — verb still returns the canonical envelope
		// with zeroed totals + empty leaderboard.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_requests'] );
		$this->assertEquals( 0.0, $result['global_avg_ms'] );
		$this->assertEquals( 0.0, $result['global_avg_peak_mb'] );
		$this->assertSame( [], $result['aggregate_time_series'] );
		// The URL-set facts belong to the `urls` verb now.
		$this->assertArrayNotHasKey( 'total_urls', $result );
		$this->assertArrayNotHasKey( 'slowest_urls', $result );
		$this->assertArrayNotHasKey( 'most_requested', $result );
	}

	public function test_overview_verb_aggregates_hourly_totals(): void {
		// Seed an hourly bucket — verb totals should add up across the
		// merged time_series array.
		$store = new Stats_Store( 0, 86400 );
		// Inside the reader's enumerated window: buckets are keys now, not a blob.
		$now = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$store->set_hourly_bucket( $bucket, [ 'count' => 4, 'sum_ms' => 2000.0, 'sum_peak_mb' => 40.0 ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertSame( 4, $result['total_requests'] );
		$this->assertEquals( 500.0, $result['global_avg_ms'] );
		$this->assertEquals( 10.0, $result['global_avg_peak_mb'] );
		$this->assertCount( 1, $result['aggregate_time_series'] );
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
		// Seed the most-recent bucket so the leaderboard fan-out picks it up.
		$store->set_leaderboard_bucket( $this->current_url_bucket(), [
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
		$store->set_leaderboard_bucket( $this->current_url_bucket(), [
			'count'        => 2,
			'sum_req_time' => 0.2,
			'categories'   => [
				'db' => [ 'samples' => 2, 'sum_time' => 0.1, 'sum_count' => 4 ],
			],
		], 'web01' );

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
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_category_bucket( $bucket, [ 'db' => [ 't' => 0.5, 'c' => 4, 'n' => 4 ] ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--categories'
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
		$this->assertArrayHasKey( $bucket, $result['category_time_series'] );
		$this->assertSame( 0.5, $result['category_time_series'][ $bucket ]['db']['t'] );
	}

	public function test_overview_verb_includes_breakdown_time_series_for_single_dim(): void {
		// `?breakdown=server` returns `breakdown_time_series` flat (legacy L111-112).
		// Used by fetchBreakdown (usePerformanceApi.js L186 reads
		// `data.breakdown_time_series`).
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_dimensional_bucket( 'server', $bucket, [ 'web01' => [ 'c' => 5, 's' => 0.5, 'm' => 0.1 ] ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=server'
		);

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 5, $result['breakdown_time_series'][ $bucket ]['web01']['c'] );
	}

	public function test_overview_verb_includes_breakdowns_map_for_multi_dim(): void {
		// Comma-separated dims return nested `breakdowns: { dim => series }`
		// (legacy L113-118). Used by the dashboard's `breakdownsFor` deduper
		// (PerformanceDashboard.js L342-374), which always sends ≥2 dims so it
		// can rely on the nested shape.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_dimensional_bucket( 'server', $bucket, [ 'web01' => [ 'c' => 5, 's' => 0.5, 'm' => 0.1 ] ] );
		$store->set_dimensional_bucket( 'status', $bucket, [ '200' => [ 'c' => 4, 's' => 0.4, 'm' => 0.1 ] ] );

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
		$this->assertSame( 5, $result['breakdowns']['server'][ $bucket ]['web01']['c'] );
		$this->assertSame( 4, $result['breakdowns']['status'][ $bucket ]['200']['c'] );
	}

	public function test_overview_server_scope_keeps_the_global_server_dimension(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_dimensional_bucket( 'server', $bucket, [
			'edge-amber.example'  => [ 'c' => 37, 's' => 3700.0, 'm' => 259.0 ],
			'edge-violet.example' => [ 'c' => 11, 's' => 1430.0, 'm' => 99.0 ],
		] );
		$store->set_dimensional_bucket( 'status', $bucket, [ '2xx' => [ 'c' => 37, 's' => 3700.0, 'm' => 259.0 ] ], 'edge-amber.example' );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=edge-amber.example --breakdown=server,status'
		);

		$this->assertSame( 37, $result['breakdowns']['server'][ $bucket ]['edge-amber.example']['c'] );
		$this->assertSame( 11, $result['breakdowns']['server'][ $bucket ]['edge-violet.example']['c'] );
		$this->assertSame( 37, $result['breakdowns']['status'][ $bucket ]['2xx']['c'] );
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
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_category_bucket( $bucket, [ 'db' => [ 't' => 0.2, 'c' => 2, 'n' => 2 ] ], 'web01' );
		$store->set_category_bucket( $bucket, [ 'db' => [ 't' => 9.9, 'c' => 99, 'n' => 99 ] ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=web01 --categories'
		);

		// Server-scoped data, not the global ones.
		$this->assertSame( 0.2, $result['category_time_series'][ $bucket ]['db']['t'] );
	}

	public function test_urls_verb_returns_envelope_when_empty(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'limit', $result );
		$this->assertArrayHasKey( 'offset', $result );
		$this->assertSame( [], $result['data'] );
		$this->assertSame( 0, $result['rows'] );
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
		$this->set_url_bucket( $store, $bucket, [
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

		$this->assertSame( 3, $result['rows'] );
		$this->assertCount( 2, $result['data'] );
		// Desc by count: /b first (5), /c second (3).
		$this->assertSame( '/b', $result['data'][0]['url'] );
		$this->assertSame( '/c', $result['data'][1]['url'] );
	}

	public function test_urls_verb_filters_by_search_term(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
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

		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( '/articles/123', $result['data'][0]['url'] );
	}

	public function test_a_later_bucket_supplies_the_url_an_earlier_one_omitted(): void {
		$url    = 'https://okgazette.example/jobs/filmtimes/import-film-times';
		$hash   = Log_Manager::url_hash( $url );
		$store  = new Stats_Store( 0, 86400 );
		$older  = Stats_Store::bucket_key( \time() - 600 );
		$newer  = $this->current_url_bucket();
		// Buckets merge newest-first, so the one REACHED FIRST is the one
		// without a URL — the order that pins the row blank.
		$this->set_url_bucket( $store, $newer, [
			$hash => [ 'count' => 2, 'sum_ms' => 40.0, 'last_seen' => 2 ],
		] );
		$this->set_url_bucket( $store, $older, [
			$hash => [ 'url' => $url, 'count' => 5, 'sum_ms' => 100.0, 'last_seen' => 1 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			$hash
		);

		$this->assertSame( $url, $result['stats']['url'] );
		$this->assertSame( 7, $result['stats']['count'] );
	}

	public function test_url_detail_returns_recent_matching_requests(): void {
		// url_detail's `requests` slice walks requests.log for entries whose
		// url_hash matches. Seed the URL in the memcache index AND two on-disk
		// requests so the collect + dedup walk runs (not the empty-result skip).
		$url    = '/recent-list';
		$hash   = Log_Manager::url_hash( $url );
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
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

	public function test_urls_verb_scopes_rows_to_the_selected_server(): void {
		// A URL row carries `srv`, its own server dimension, so the table can be
		// scoped: only URLs that server served survive, and their counts are
		// that server's, not every server's.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [
				'url' => '/reviews/941', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 900.0, 'last_seen' => 1700000003,
				'srv' => [ 'alpha.example' => [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 8.0 ], 'beta.example' => [ 'count' => 7, 'timed_count' => 7, 'sum_ms' => 640.0, 'sum_peak_mb' => 21.0 ] ],
			],
			'dddddddddddd' => [
				'url' => '/events/88', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 122.0, 'last_seen' => 1700000004,
				'srv' => [ 'beta.example' => [ 'count' => 4, 'timed_count' => 4, 'sum_ms' => 122.0, 'sum_peak_mb' => 12.0 ] ],
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--server=alpha.example'
		);

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( '/reviews/941', $result['data'][0]['url'] );
		$this->assertSame( 2, $result['data'][0]['count'] );
		$this->assertEqualsWithDelta( 130.0, $result['data'][0]['avg_ms'], 1e-6 );
	}

	public function test_urls_verb_scopes_the_status_counts_too(): void {
		// Scoping `count` alone would leave `count_2xx..5xx` describing every
		// server, so a scoped row could report more classified requests than it
		// had — and `errors_only`, which is `count` minus those four, would read
		// negative and hide the row. The split carries the row's SUMMED fields.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [
				'url' => '/mixed', 'count' => 9, 'count_2xx' => 6, 'count_5xx' => 3, 'sum_ms' => 900.0, 'last_seen' => 1700000003,
				'srv' => [ 'alpha.example' => [ 'count' => 2, 'timed_count' => 2, 'count_2xx' => 1, 'count_5xx' => 1, 'sum_ms' => 260.0 ] ],
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--server=alpha.example'
		);

		$this->assertSame( 2, $result['data'][0]['count'] );
		$this->assertSame( 1, $result['data'][0]['count_2xx'] );
		$this->assertSame( 1, $result['data'][0]['count_5xx'] );
	}

	public function test_urls_verb_totals_answer_for_the_filtered_set(): void {
		// The Overview header renders these numbers, so they must describe the
		// set the table lists — under the server AND search filters both.
		// Reading a global total beside a filtered table is what put
		// `0 Unique URLs` next to 33,049 requests.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [
				'url' => '/reviews/941', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 900.0, 'sum_peak_mb' => 36.0, 'last_seen' => 1700000003,
				'srv' => [ 'alpha.example' => [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 9.0 ] ],
			],
			'dddddddddddd' => [
				'url' => '/reviews/88', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 122.0, 'sum_peak_mb' => 12.0, 'last_seen' => 1700000004,
				'srv' => [ 'alpha.example' => [ 'count' => 3, 'timed_count' => 3, 'sum_ms' => 90.0, 'sum_peak_mb' => 7.5 ] ],
			],
			'eeeeeeeeeeee' => [
				'url' => '/events/7', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 20.0, 'last_seen' => 1700000005,
				'srv' => [ 'alpha.example' => [ 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 20.0 ] ],
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--server=alpha.example --search=/reviews/'
		);

		$this->assertSame( 2, $result['totals']['urls'] );
		$this->assertSame( 5, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 70.0, $result['totals']['avg_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 3.3, $result['totals']['avg_peak_mb'], 1e-6 );
	}

	public function test_swapping_a_non_array_split_drops_the_row(): void {
		// Tested directly, because the index merge normalizes a split through
		// `sum_fields()` before any reader sees it — the hazard is for a caller
		// handing this public method a raw row. `sum_fields()` SKIPS a non-array
		// value, so an `isset` guard would leave the swap empty and `array_merge`
		// would hand back the SITE's counts wearing one server's name.
		$row = [ 'url' => '/corrupt', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 900.0, 'srv' => [ 'alpha.example' => 'not-an-array' ] ];

		$this->assertNull( Stats_Store::swap_url_server_sums( $row, 'alpha.example' ) );
	}

	public function test_url_detail_time_series_follows_the_server_scope(): void {
		// The modal's stats are one server's; its chart is drawn from the same
		// payload. An unscoped series under a scoped count is the same defect
		// this change removed from the header, moved to the chart beneath it.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [
				'url' => '/charted', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 900.0, 'last_seen' => 1700000003,
				'srv' => [
					'alpha.example' => [ 'count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 8.0 ],
					'beta.example'  => [ 'count' => 7, 'timed_count' => 7, 'sum_ms' => 640.0, 'sum_peak_mb' => 21.0 ],
				],
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'cccccccccccc --server=alpha.example'
		);

		$this->assertSame( 2, $result['stats']['count'] );
		$this->assertSame( 2, $result['stats']['time_series'][ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 260.0, $result['stats']['time_series'][ $bucket ]['sum_ms'], 1e-6 );
	}

	public function test_ask_accepts_the_context_it_declares_as_an_option(): void {
		// The verb declares `context`, so a caller writing `--context=` is
		// following the schema. Reading context only from the positionals meant
		// that caller got "missing context" for doing exactly what it said.
		$rid  = 'ctxrid0000000000';
		$this->write_request( [
			'rid' => $rid, 'url' => 'https://example.test/x', 'duration_ms' => 10.0,
			'entries' => [ [ 'k' => 'span', 'n' => 'wp_loaded', 'd' => 5.0 ] ],
			'profiles' => [ 'wpdb' => [ 'time' => 0.004, 'count' => 2, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			"category:wpdb --context=request:{$rid}:0"
		);

		// 'request', not 'recent window': only the context path reaches the
		// per-request board, so this distinguishes the two.
		$this->assertSame( 'request', $result['scope'] );
	}

	public function test_ask_category_brief_honours_the_server_scope(): void {
		// The Time Breakdown an operator clicks Ask from renders
		// `build_leaderboard( $server )`, so the brief behind it has to read the
		// same leaderboard. Quoting the whole site's category time under a
		// surface stamped with one server's name is the defect this fixed for
		// `url:` — it has to hold for every descriptor that reads a scoped set.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_leaderboard_bucket( $bucket, [
			'count'        => 4,
			'sum_req_time' => 1.0,
			'categories'   => [ 'wpdb' => [ 'samples' => 4, 'sum_time' => 4.0, 'sum_count' => 8, 'entries' => [] ] ],
		], 'alpha.example' );
		$store->set_leaderboard_bucket( $bucket, [
			'count'        => 40,
			'sum_req_time' => 10.0,
			'categories'   => [ 'wpdb' => [ 'samples' => 40, 'sum_time' => 400.0, 'sum_count' => 80, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'category:wpdb --server=alpha.example'
		);

		$this->assertEqualsWithDelta( 1.0, $result['avg_time_ms'], 1e-6 );
		$this->assertSame( 'recent window on alpha.example', $result['scope'] );
	}

	public function test_ask_url_brief_honours_the_server_scope(): void {
		// `pageFacts` stamps the active filters onto every surface it emits, so
		// a brief that answered site-wide would hand an agent unscoped numbers
		// labelled as one server's — a worse failure than not scoping at all,
		// because the label makes it quotable.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [
				'url' => '/asked', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 1800.0, 'last_seen' => 1700000003,
				'srv' => [ 'alpha.example' => [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 500.0 ] ],
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'url:cccccccccccc --server=alpha.example'
		);

		$this->assertSame( 2, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 250.0, $result['stats']['avg_ms'], 1e-6 );
	}

	public function test_ask_url_brief_carries_the_measured_average(): void {
		// The brief is the number an agent quotes, so it reads a DISPLAY row —
		// the loader emits sums and leaves the means to the projection, and a
		// reader that takes the loader's output raw reports a confident 0.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/asked', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 1000.0, 'last_seen' => 1700000003 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'url:cccccccccccc'
		);

		$this->assertEqualsWithDelta( 250.0, $result['stats']['avg_ms'], 1e-6 );
	}

	public function test_url_detail_scopes_to_the_selected_server(): void {
		// The row that opens this modal is the selected server's; the modal has
		// to answer for the same server, or one click turns a scoped table into
		// a site-wide average under the same URL and the same instant, on two
		// surfaces too far apart to notice the disagreement.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [
				'url' => '/mixed', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 900.0, 'last_seen' => 1700000003,
				'srv' => [ 'alpha.example' => [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0 ] ],
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'cccccccccccc --server=alpha.example'
		);

		$this->assertSame( 2, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 130.0, $result['stats']['avg_ms'], 1e-6 );
	}

	public function test_urls_verb_echoes_the_filters_it_applied(): void {
		// The totals beside these are narrower than the site, and a number
		// whose scope is not stated is a number that will be read as the site's.
		// The verb echoes what it actually applied, so the Ask brief describes
		// the panel rather than guessing at it.
		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=alpha.example --search=/reviews/ --errors_only'
		);

		$this->assertSame(
			[
				'server'          => 'alpha.example',
				'search'          => '/reviews/',
				'errors_only'     => true,
				'include_workers' => false,
			],
			$result['filters']
		);
	}

	public function test_urls_verb_leaves_worker_traffic_out_by_default(): void {
		// `$count_global` keeps workers out of every site-wide aggregate — one
		// long-running job would otherwise dominate the averages — and the
		// header sums THIS index, so the default view has to agree. The table
		// hides them too, or the header stops describing what is listed.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reader', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 48.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/w?reconcile', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 180000.0, 'worker' => true, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 4, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 12.0, $result['totals']['avg_ms'], 1e-6 );
		$this->assertSame( [ '/reader' ], \array_column( $result['data'], 'url' ) );
	}

	public function test_urls_verb_shows_worker_traffic_when_asked(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reader', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 48.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/w?reconcile', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 180000.0, 'worker' => true, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--include_workers'
		);

		$this->assertSame( 2, $result['totals']['urls'] );
		$this->assertSame( 6, $result['totals']['requests'] );
		$this->assertTrue( $result['filters']['include_workers'] );
	}

	public function test_urls_verb_rate_skips_the_still_filling_bucket(): void {
		// Req/s averages the 12 COMPLETE buckets: 3,600 requests over an hour is
		// 1/s. The newest bucket is still accumulating, so counting it would drag
		// every rate down — the dashboard's own rates have always dropped it, and
		// the server has to drop the same one to answer the same question.
		$store = new Stats_Store( 0, 86400 );
		$now   = \time();
		$this->set_url_bucket( $store, Stats_Store::bucket_key( $now - 600 ), [
			'cccccccccccc' => [ 'url' => '/rate', 'count' => 3600, 'timed_count' => 3600, 'sum_ms' => 3600.0, 'last_seen' => $now - 600 ],
		] );
		$this->set_url_bucket( $store, Stats_Store::bucket_key( $now ), [
			'cccccccccccc' => [ 'url' => '/rate', 'count' => 99000, 'timed_count' => 99000, 'sum_ms' => 99000.0, 'last_seen' => $now ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 102600, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 1.0, $result['totals']['requests_per_second'], 1e-9 );
	}

	public function test_urls_verb_filters_errors_only_and_totals_match(): void {
		// "Errors" = requests no status bucket classified: timeouts and fatals.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [
				'url' => '/clean', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 90.0, 'last_seen' => 1700000001,
				'count_2xx' => 7, 'count_3xx' => 1, 'count_4xx' => 1, 'count_5xx' => 0,
			],
			'bbbbbbbbbbbb' => [
				'url' => '/timeouts', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 60.0, 'last_seen' => 1700000002,
				'count_2xx' => 2, 'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
			],
			'cccccccccccc' => [
				'url' => '/also-clean', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0, 'last_seen' => 1700000003,
				'count_2xx' => 4, 'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--errors_only=1'
		);

		// The footer reads `total`; it must count what is actually rendered.
		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( '/timeouts', $result['data'][0]['url'] );
	}

	public function test_urls_verb_search_drops_the_folded_aggregate_row(): void {
		// The folded row stands for many URLs, so a search cannot know whether
		// its contents match. It is identified by `aggregate` — seeded here
		// with matching url text, which must NOT be enough to include it.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [
				'url' => '/reviews/spring', 'count' => 11, 'timed_count' => 11, 'sum_ms' => 220.0,
				'last_seen' => 1700000401, 'count_2xx' => 11,
			],
			'bbbbbbbbbbbb' => [
				'url' => '/archive/2019', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 75.0,
				'last_seen' => 1700000402, 'count_2xx' => 5,
			],
			Stats_Store::OTHER_KEY => [
				'url' => '/reviews/folded', 'count' => 613, 'timed_count' => 613, 'sum_ms' => 9195.0,
				'last_seen' => 1700000403, 'count_2xx' => 613,
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'urls', '--search=/reviews/' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( '/reviews/spring', $result['data'][0]['url'] );
		// The 613 folded requests must not reach a scoped total.
		$this->assertSame( 11, $result['totals']['requests'] );
	}

	public function test_urls_verb_heals_poisoned_min_ms_sentinel(): void {
		// A URL whose every persisted bucket is untimed carries the
		// PHP_INT_MAX sentinel as min_ms (worker / timed-out requests). The
		// display must never surface the sentinel — it heals to 0.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
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

		$this->assertSame( 1, $result['totals']['urls'] );
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
		$bucket_a = Stats_Store::bucket_key( \time() - 600 );

		$this->set_url_bucket( $store, $bucket_a, [
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
		$this->set_url_bucket( $store, $bucket_b, [
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

		$this->assertSame( 1, $result['totals']['urls'] );
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

	public function test_a_split_row_elsewhere_does_not_silence_the_scope_guard(): void {
		// The first post-deploy flush gives ONE row a split while every
		// pre-deploy bucket keeps split-less rows for the rest of the window.
		// An index-wide test therefore stops firing almost immediately, which
		// is nearly the whole transition rather than none of it. The row
		// answers for itself.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'facade99beef' => [
				'url'         => '/legacy/no-split',
				'count'       => 37,
				'timed_count' => 37,
				'sum_ms'      => 1480.0,
				'last_seen'   => 1700000999,
			],
			'beefcafe1234' => [
				'url'         => '/fresh/with-split',
				'count'       => 11,
				'timed_count' => 11,
				'sum_ms'      => 220.0,
				'last_seen'   => 1700000999,
				'srv'         => [ 'edge-19' => [ 'count' => 11, 'timed_count' => 11, 'sum_ms' => 220.0 ] ],
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'facade99beef --server=edge-19'
		);

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'not found', \strtolower( $result ) );
		$this->assertStringContainsString( 'edge-19', $result );
	}

	public function test_the_other_row_is_marked_as_an_aggregate(): void {
		// It stands for many URLs, so it is not one: its key is not a url_hash
		// and `url_detail` cannot answer for it. The row says so rather than
		// leaving the table to offer a link that errors.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc'         => [ 'url' => '/real', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 300.0, 'last_seen' => 1700000003 ],
			Stats_Store::OTHER_KEY => [ 'count' => 40, 'timed_count' => 40, 'sum_ms' => 4000.0, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$rows = [];
		foreach ( $result['data'] as $row ) {
			$rows[ $row['hash'] ] = $row;
		}
		$this->assertTrue( $rows[ Stats_Store::OTHER_KEY ]['aggregate'] );
		$this->assertFalse( $rows['cccccccccccc']['aggregate'] );
		// Its REQUESTS count — that is the point of folding rather than
		// dropping — but it is not itself a unique URL.
		$this->assertSame( 43, $result['totals']['requests'] );
		$this->assertSame( 1, $result['totals']['urls'] );
	}

	public function test_the_worker_overflow_row_is_an_aggregate_too(): void {
		// The fold emits TWO overflow rows, because one cannot answer a filter
		// about a set that mixes worker and reader traffic. A reader testing
		// only the first key renders the second as an ordinary clickable row —
		// with no URL, counted as a unique URL, and answering a click with
		// "invalid hash format".
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'dddddddddddd'                => [ 'url' => '/real', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 700.0, 'last_seen' => 1700000003 ],
			Stats_Store::OTHER_WORKER_KEY => [ 'count' => 91, 'timed_count' => 91, 'sum_ms' => 9100.0, 'worker' => true, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--include_workers=1' );

		$rows = [];
		foreach ( $result['data'] as $row ) {
			$rows[ $row['hash'] ] = $row;
		}
		$this->assertTrue( $rows[ Stats_Store::OTHER_WORKER_KEY ]['aggregate'] );
		$this->assertSame( 98, $result['totals']['requests'] );
		$this->assertSame( 1, $result['totals']['urls'] );
	}

	public function test_the_errors_filter_does_not_admit_the_whole_folded_tail(): void {
		// The overflow row stands for hundreds of URLs, and the predicate is a
		// ROW test: one timeout anywhere in that tail makes the row look like
		// an erroring URL, and every non-error request it folded then lands in
		// an errors-only total. A folded row cannot answer a per-URL question.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'0badc0de1234'         => [ 'url' => '/erroring', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0, 'count_2xx' => 4, 'last_seen' => 1700000003 ],
			Stats_Store::OTHER_KEY => [ 'count' => 900, 'timed_count' => 900, 'sum_ms' => 9000.0, 'count_2xx' => 899, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only=1' );

		$this->assertSame( 5, $result['totals']['requests'] );
		$this->assertSame( 1, $result['totals']['urls'] );
	}

	public function test_a_url_row_carries_no_seconds_field_fallback(): void {
		// `sum_req_time` is the leaderboard's field, in SECONDS. A URL row has
		// carried `sum_ms` for releases; reading the other one as a fallback
		// multiplies a leaderboard-shaped value by 1000 onto a URL's mean.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'5ec0d5fa11ba' => [ 'url' => '/seconds-era', 'count' => 4, 'sum_req_time' => 8.0, 'last_seen' => 1700000003 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$row = $result['data'][0] ?? [];
		$this->assertSame( '/seconds-era', $row['url'] ?? '' );
		$this->assertSame( 0.0, (float) ( $row['avg_ms'] ?? -1 ), 'no milliseconds are invented from it' );
	}

	public function test_a_row_with_no_timed_count_divides_by_nothing(): void {
		// `timed_count` has ridden every URL row for releases. Falling back to
		// the whole count for a row without it invents a denominator from a
		// shape nothing writes.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'facade0ffee1' => [ 'url' => '/untimed', 'count' => 5, 'sum_ms' => 750.0, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 0.0, (float) ( $result['totals']['avg_ms'] ?? -1 ) );
	}

	public function test_the_mean_divides_by_timed_requests_not_by_every_request(): void {
		// Every other fixture seeds timed_count === count, so both denominators
		// agree and neither discriminates. Here they differ: 400ms over the 4
		// requests that recorded a duration is 100, over all 10 it is 40.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'd1ff3d3n0m1n' => [
				'url'         => '/mostly-timed-out',
				'count'       => 10,
				'timed_count' => 4,
				'sum_ms'      => 400.0,
				'last_seen'   => 1700000005,
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertEqualsWithDelta( 100.0, $result['totals']['avg_ms'], 0.01 );
		$this->assertEqualsWithDelta( 100.0, $result['data'][0]['avg_ms'], 0.01 );
		$this->assertSame( 10, $result['totals']['requests'] );
	}

	public function test_url_detail_says_the_scope_is_unanswerable_not_missing(): void {
		// A pre-upgrade index carries no per-server split, so a scoped read of
		// a URL that plainly exists projects to nothing. Reporting that as
		// "URL not found" sends the reader hunting a URL they are looking at;
		// for one retention window after deploy the honest answer is that this
		// index cannot answer THAT question yet.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'facade99beef' => [
				'url'         => '/legacy/no-split',
				'count'       => 37,
				'timed_count' => 37,
				'sum_ms'      => 1480.0,
				'p95_ms'      => 64.0,
				'last_seen'   => 1700000999,
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'facade99beef --server=edge-19'
		);

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'not found', \strtolower( $result ) );
		$this->assertStringContainsString( 'edge-19', $result );
	}

	public function test_url_detail_verb_returns_stats_and_default_flame(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/articles/777',
				'count'     => 9,
				'timed_count' => 9, 'sum_ms'    => 450.0,
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
		$this->set_url_bucket( $store, $bucket, [
			'cafebabe1234' => [
				'url'       => '/x',
				'count'     => 1,
				'timed_count' => 1, 'sum_ms'    => 10.0,
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
		// UrlDetailView's chart.
		// Legacy PerfUrlsController::find_url_stats L228 calls `build_url_time_series`
		// which walks the recent buckets keyed by hash. The interpreter verb must too.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 3,
				'timed_count' => 3, 'sum_ms'    => 150.0,
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
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 1,
				'timed_count' => 1, 'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		$bucket = $this->current_url_bucket();
		$store->set_url_dimensional_bucket( 'abc123def456', $bucket, [ 'method' => [ 'GET' => [ 'c' => 3, 's' => 0.3, 'm' => 0.1 ] ] ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456 --breakdown=method'
		);

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 3, $result['breakdown_time_series'][ $bucket ]['GET']['c'] );
	}

	public function test_url_detail_verb_includes_category_time_series_when_arg_set(): void {
		// `?categories=1` on /urls/{hash} emits `category_time_series`
		// (legacy L196, L184-186). Consumed by UrlDetailView L282-295 +
		// fetchUrlCategories L237.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 1,
				'timed_count' => 1, 'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		$bucket = $this->current_url_bucket();
		$store->set_url_category_bucket( 'abc123def456', $bucket, [ 'db' => [ 't' => 0.2, 'c' => 2, 'n' => 1 ] ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'url_detail',
			'abc123def456 --categories'
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
		$this->assertSame( 0.2, $result['category_time_series'][ $bucket ]['db']['t'] );
	}

	public function test_url_detail_verb_breakdown_filters_unknown_dims(): void {
		// Unknown dim → no breakdown_time_series (matches legacy L179's
		// `in_array(...,DIMENSIONS,true)` guard).
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [ 'url' => '/x', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 10.0, 'last_seen' => 1700001000 ],
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
	// Partition span: the dashboard reads a topology's OWN worker count, not
	// the global `num_partitions`. The hub runs four workers on a global 1, so
	// a reader looping to the global sees a quarter of the fleet. Both tests
	// activate the SHIPPED topologies so a renamed node fails them.
	// -------------------------------------------------------------------------

	private function activate_shipped_topology( string $name, int $num_partitions ): void {
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		// ELN's own dir first; the substrate's behind it, for `include topic-probe`.
		\Newspack_Nodes\Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
		\Newspack_Nodes\Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ) use ( $name, $num_partitions ): array {
				$topologies[ $name ] = [ 'topology' => $name, 'num_partitions' => $num_partitions, 'stale_timeout' => 60 ];
				return $topologies;
			}
		);
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ $name ];
		\Newspack_Nodes\Config::reset();
	}

	public function test_request_search_spans_the_topologys_own_worker_count(): void {
		// Global num_partitions stays 1; performance runs 4. A rid living in p2 is
		// invisible to a reader that loops to the global.
		$this->activate_shipped_topology( 'performance', 4 );
		$rid = $this->write_request(
			[
				'rid'            => 'rid-high-partition-000000000001',
				'url'            => '/deep',
				'timestamp'      => 1700000400,
				'duration_ms'    => 20,
				'status_code'    => 200,
				'peak_mb'        => 3,
				'request_method' => 'GET',
			],
			2
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_search', $rid );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['partition'] );
	}

	public function test_request_search_tries_the_rids_own_partition_first(): void {
		// This rid hashes to 3 of 4. Seeded in BOTH 3 and 0, an ascending scan
		// returns 0 — only hash-first returns 3.
		$this->activate_shipped_topology( 'performance', 4 );
		$body = [
			'rid'            => 'rid-hash-order-0000000000000001',
			'url'            => '/hashed',
			'timestamp'      => 1700000400,
			'duration_ms'    => 20,
			'status_code'    => 200,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		];
		$this->assertSame( 3, \Newspack_Nodes\Partition_Node::hash_to_partition( $body['rid'], 4 ) );
		$this->write_request( $body, 0 );
		$rid = $this->write_request( $body, 3 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_search', $rid );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['partition'] );
	}

	public function test_request_detail_says_not_found_when_no_topology_is_active(): void {
		// Nothing declares requests:partition, so there is no partition set to
		// be outside of. "invalid partition" blames the caller for a request
		// that is merely unfindable.
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Nodes\Config::reset();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_detail', 'some-rid' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_stats_stores_span_the_topologys_own_worker_count(): void {
		// flame-builder writes its stats to memcache keyed by worker index, so
		// the store fan-out has to follow the topology count, not the global.
		$this->activate_shipped_topology( 'performance', 3 );

		$method = new \ReflectionMethod( Performance_CI_Node::class, 'stats_stores' );
		/** @var array<int,Stats_Store> $stores */
		$stores = $method->invoke( null );

		$this->assertCount( 3, $stores );
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
	// request_grep verb (server-side recent-firehose pattern search)
	// -------------------------------------------------------------------------

	public function test_request_grep_returns_matching_request_summary(): void {
		// One completed request whose URL matches, plus a non-matching one.
		$this->write_firehose( 0, [
			[ 'rid' => 'grepR1', 'k' => 'process (start)', 'm' => '12345 on host', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'grepR1', 'k' => 'request', 'm' => 'GET /calendar/today?x=1', 'ts' => 1700000000.0, 'n' => 2 ],
			[ 'rid' => 'grepR1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.4, 'n' => 3, 'duration_ms' => 400 ],
			[ 'rid' => 'grepNoise', 'k' => 'request', 'm' => 'GET /feed', 'ts' => 1700000001.0, 'n' => 1 ],
			[ 'rid' => 'grepNoise', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000001.2, 'n' => 2 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'request_grep', '/calendar' );

		$this->assertIsArray( $result );
		$this->assertSame( 'recent', $result['scope'] );
		$this->assertSame( '/calendar', $result['pattern'] );
		$this->assertSame( 1, $result['scanned_partitions'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertCount( 1, $result['results'] );

		$summary = $result['results'][0];
		$this->assertSame( 'grepR1', $summary['rid'] );
		$this->assertSame( '/calendar/today', $summary['url'] );
		$this->assertSame( 'GET', $summary['method'] );
		$this->assertGreaterThanOrEqual( 1, $summary['match_count'] );
		$this->assertStringContainsString( '/calendar', $summary['first_match_excerpt'] );
	}

	public function test_request_grep_truncates_at_result_limit(): void {
		// Three matching completed requests; --limit=2 → 2 results + truncated.
		$entries = [];
		foreach ( [ 'gA', 'gB', 'gC' ] as $i => $rid ) {
			$entries[] = [ 'rid' => $rid, 'k' => 'request', 'm' => "GET /match/{$rid}", 'ts' => 1700000000.0 + $i, 'n' => 1 ];
			$entries[] = [ 'rid' => $rid, 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.5 + $i, 'n' => 2 ];
		}
		$this->write_firehose( 0, $entries );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'request_grep', '/match --limit=2' );

		$this->assertCount( 2, $result['results'] );
		$this->assertTrue( $result['truncated'] );
	}

	/** `max(1, (int) 'abc')` answers one result and calls it the whole match set. */
	public function test_request_grep_refuses_a_malformed_limit(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'lim1', 'k' => 'request', 'm' => 'GET /match/a', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'lim1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.5, 'n' => 2 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_grep', '/match --limit=abc' );

		$this->assertIsString( $result, 'a malformed --limit must not silently return one row' );
		$this->assertStringContainsString( 'limit', $result );
	}

	public function test_request_grep_empty_when_no_match(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'z1', 'k' => 'request', 'm' => 'GET /other', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'z1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.2, 'n' => 2 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'request_grep', '/nonexistent' );

		$this->assertSame( [], $result['results'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 1, $result['scanned_partitions'] );
	}

	public function test_request_grep_requires_pattern(): void {
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'request_grep' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'pattern required', \strtolower( $result ) );
	}

	public function test_request_grep_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter                  = new Performance_CI_Node();
		$result                       = VerbHarness::fire( $interpreter, 'performance', 'request_grep', '/x' );

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

	/**
	 * A malformed --partition casts to 0, so the verb answers for a partition
	 * the operator never named — and where the rid is absent, blames the rid.
	 */
	public function test_request_detail_verb_refuses_a_malformed_partition(): void {
		$rid = $this->write_request( [
			'rid'         => 'rid-malformed-partition-flag',
			'url'         => '/p0-only',
			'timestamp'   => 1700000600,
			'duration_ms' => 12,
			'events'      => [ [ 'k' => 'process (start)', 'm' => '/p0-only', 'ts' => 1700000600.0 ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'request_detail',
			$rid . ' --partition=abc'
		);

		$this->assertIsString( $result, 'a malformed --partition must not answer for p0' );
		$this->assertStringContainsString( 'partition', \strtolower( $result ) );
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

	public function test_request_detail_carries_the_findings_for_that_record(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-findings-1234567890123456789',
			'url'            => '/slow-thing',
			'timestamp'      => 1700000700,
			'duration_ms'    => 9000,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
			'flame'          => [
				'name'     => 'request',
				'value'    => 40.0,
				'children' => [ [ 'name' => 'init', 'value' => 40.0, 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_detail', $rid );

		$this->assertIsArray( $result );
		$this->assertContains(
			'unattributed',
			\array_column( $result['findings'], 'kind' ),
			'40ms profiled of a 9-second request is subtraction, not inference'
		);
		$this->assertStringContainsString( 'SQL', $result['caveat'] );
	}

	// -------------------------------------------------------------------------
	// ask verb
	// -------------------------------------------------------------------------

	public function test_ask_refuses_a_descriptor_outside_the_vocabulary(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'wizard:x' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown descriptor', \strtolower( $result ) );
	}

	public function test_ask_requires_a_descriptor(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', '' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'descriptor required', \strtolower( $result ) );
	}

	/**
	 * A stored record's url is ABSOLUTE and query-stripped, while rules are
	 * path patterns — so re-deriving the rule by matching that url found
	 * nothing, not even a catch-all `/`. The record already carries the answer
	 * the request itself resolved.
	 */
	public function test_request_detail_resolves_the_rule_the_record_recorded(): void {
		\update_option(
			Rule_Set::OPTION_RULES,
			[
				[
					'id'      => Rule_Set::id_for( '/' ),
					'pattern' => '/',
					'action'  => 'log',
					'hooks'   => [ 'init' ],
				],
			]
		);
		Rule_Set::reset();

		$rid = $this->write_request( [
			'rid'            => 'rid-rule-12345678901234567890123',
			'url'            => 'https://example.test/some/path',
			'rule_id'        => Rule_Set::id_for( '/' ),
			'timestamp'      => 1700001000,
			'duration_ms'    => 90,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertNotNull( $result['rule'], 'the record named its rule; nothing had to be re-derived' );
		$this->assertSame( '/', $result['rule']['pattern'] );
	}

	public function test_ask_assembles_a_request_brief(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-req-123456789012345678',
			'url'            => '/asked-about',
			'timestamp'      => 1700000800,
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

		$this->assertIsArray( $result );
		$this->assertSame( 'request', $result['subject'] );
		$this->assertSame( '/asked-about', $result['url'] );
		$this->assertEquals( 120.0, $result['duration_ms'] );
	}

	public function test_ask_resolves_a_span_through_its_request_context(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-span-12345678901234567',
			'url'            => '/asked-span',
			'timestamp'      => 1700000900,
			'duration_ms'    => 500,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
			'flame'          => [
				'name'     => 'request',
				'value'    => 500.0,
				'children' => [ [ 'name' => 'wp_loaded', 'value' => 480.0, 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			[ 'span:wp_loaded', "request:{$rid}:0" ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'span', $result['subject'] );
		$this->assertSame( 'wp_loaded', $result['name'] );
		$this->assertEquals( 480.0, $result['ms'] );
	}

	public function test_a_span_without_its_request_context_is_refused(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'span:wp_loaded' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'needs its request', \strtolower( $result ) );
	}

	public function test_ask_assembles_a_url_brief(): void {
		$hash  = Log_Manager::url_hash( '/asked-url' );
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [
				'url'       => '/asked-url',
				'count'     => 7,
				'timed_count' => 7, 'sum_ms'    => 6300.0,
				'last_seen' => 1700000000,
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" );

		$this->assertIsArray( $result );
		$this->assertSame( 'url', $result['subject'] );
		$this->assertSame( '/asked-url', $result['url'] );
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
		$url_hash = Log_Manager::url_hash( '/with-flame' );
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
		$flame['url_hash'] = Log_Manager::url_hash( '/with-deep-flame' );
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

	public function test_hooks_registered_ships_category_descriptions(): void {
		// The descriptions used to be a hand-written map in HookSelectorModal.js
		// covering 24 of the 63 categories this config declares — and users can
		// add more. They travel with the taxonomy that owns them now.
		$this->seed_wp_filter_with_known_hooks();

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'hooks_registered' );

		$this->assertArrayHasKey( 'category_descriptions', $result );
		$this->assertSame(
			'Core request lifecycle',
			$result['category_descriptions']['Lifecycle'] ?? null
		);
		// Every described category is a real one.
		foreach ( \array_keys( $result['category_descriptions'] ) as $category ) {
			$this->assertArrayHasKey( $category, $result['categories'] );
		}
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

	public function test_decode_array_value_rejects_non_json(): void {
		// A synced array-option value is JSON on the wire — Settings_Sync_Node
		// scalarizes arrays via wp_json_encode unconditionally. A non-JSON value is
		// a contract violation, explicitly rejected to [] (NOT csv-split into a
		// 2-element list — the old "legacy senders" comma fallback was unreachable).
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'decode_array_value' );
		$this->assertSame( [], $ref->invoke( null, 'zebra.example,quux.example' ) );
	}

	public function test_array_option_json_preserves_associative_keys(): void {
		// A JSON array-option value is json_decoded and sanitize_settings_array keeps
		// the keys — the old csv-split flattened an assoc map to a list of "1"s.
		$decode = new \ReflectionMethod( Performance_CI_Node::class, 'decode_array_value' );
		$sanitize = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );

		$decoded = $decode->invoke( null, (string) \json_encode( [ 'advancedemail' => true, 'amazons3' => true ] ) );

		$this->assertSame(
			[ 'advancedemail' => true, 'amazons3' => true ],
			$sanitize->invoke( null, $decoded, 'array' )
		);
	}

	public function test_set_verb_re_tiers_a_synced_heavy_ruleset(): void {
		// The synced ruleset arrives hook-hydrated; set() must route it through
		// Rule_Set::apply_synced so a heavy rule re-tiers to THIS site's durable
		// option locally, not a raw update_option that bloats autoloaded OPTION_RULES.
		$big  = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$rule = ( new Rule( 'big', '/heavy/', Rule::ACTION_LOG, hooks: $big ) )->to_array();
		$args = \Newspack_Nodes\Command_Args::format(
			[ 'newspack_event_logger_nodes_rules', (string) \json_encode( [ $rule ] ) ],
			[]
		);

		$interpreter = new Performance_CI_Node();
		VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		// Keyed by the PATTERN-derived id, not the 'big' the wire supplied.
		$option = Rule_Set::hooks_option_name( Rule_Set::id_for( '/heavy/' ) );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ $option ], 'the heavy rule\'s hooks must land in a local durable option' );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 'mc', $stored['hooks_in'], 'OPTION_RULES must hold a small pointer, not the inline blob' );
		$this->assertNull( $stored['hooks'] );
	}

	public function test_set_verb_carries_a_pointer_rules_null_hooks_through_sanitizing(): void {
		// A hub sends a heavy rule as a POINTER — `hooks: null`, `hooks_in: mc` —
		// whenever hydrate_array() could not inline it. Dropping the null left a
		// map Rule::from_array now refuses outright, so a normal settings push
		// could only ever fail: the spoke kept its last-good ruleset and the hub
		// never converged. Null is inert; it must survive the sanitizer.
		$big = \array_map( fn( $i ) => "hook_dowser_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 4 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( Rule_Set::id_for( '/dowser/' ), '/dowser/', Rule::ACTION_LOG, hooks: $big ) ] );
		$option = Rule_Set::hooks_option_name( Rule_Set::id_for( '/dowser/' ) );

		$pointer = [
			'id'                     => Rule_Set::id_for( '/dowser/' ),
			'pattern'                => '/dowser/',
			'action'                 => 'log',
			'auto_disable_threshold' => 7331,
			'hooks'                  => null,
			'hooks_in'               => 'mc',
		];
		$args = \Newspack_Nodes\Command_Args::format(
			[ 'newspack_event_logger_nodes_rules', (string) \json_encode( [ $pointer ] ) ],
			[]
		);

		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', $args );

		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 7331, $stored['auto_disable_threshold'], 'the push must APPLY, not be refused' );
		$this->assertSame( 'mc', $stored['hooks_in'] );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ $option ], 'and the spoke keeps its durable hooks' );
	}

	public function test_sanitize_settings_array_keeps_an_explicit_null(): void {
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_array' );

		$this->assertSame(
			[ 'hooks' => null, 'pattern' => '/dowser/' ],
			$ref->invoke( null, [ 'hooks' => null, 'pattern' => '/dowser/' ] )
		);
	}

	public function test_sanitize_settings_array_still_drops_an_object(): void {
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_array' );

		$this->assertSame( [ 'pattern' => '/dowser/' ], $ref->invoke( null, [ 'blob' => new \stdClass(), 'pattern' => '/dowser/' ] ) );
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

	public function test_array_option_json_preserves_nested_structure(): void {
		// A nested map recurses one level and keeps the structure (sanitize_array
		// recursion branch), not flattened or rejected. Tested on the sanitizer
		// directly — the ruleset option itself now re-tiers on set().
		$sanitize = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );

		$this->assertSame(
			[ 'group' => [ 'inner' => 'val' ] ],
			$sanitize->invoke( null, [ 'group' => [ 'inner' => 'val' ] ], 'array' )
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
			[ 'newspack_event_logger_nodes_rules', (string) \json_encode( $deep ) ],
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
		// An empty value is not JSON (json_decode('') is null) → explicitly rejected to [].
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_rules ""'
		);

		$this->assertSame( [], $GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] );
	}

	public function test_sanitize_settings_value_rejects_non_array_and_unknown_type(): void {
		// Two reject paths unreachable through `set` (which always hands an array
		// for array-typed options and only ever a whitelisted type): a non-array
		// value for the array branch, and an unrecognized type.
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );

		$this->assertNull( $ref->invoke( null, 'not-an-array', 'array' ) );
		$this->assertNull( $ref->invoke( null, 'whatever', 'nonexistent-type' ) );
	}

	// ── urls verb fallbacks + server filter ─────────────────────────────────

	public function test_urls_verb_falls_back_to_defaults_on_invalid_sort_and_order(): void {
		// Out-of-whitelist sort/order silently fall back to count/desc.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/only-host', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 30.0, 'last_seen' => 1700000001 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=bogus --order=bogus'
		);

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( '/only-host', $result['data'][0]['url'] );
	}

	public function test_urls_verb_sorts_ascending(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
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

	// ── load_index bucket contract ──────────────────────────────────────────

	/**
	 * The bucket's inner key IS the hash `Log_Manager::url_hash()` stamped on
	 * the record. Deriving a different one indexes the row under a hash no rid
	 * lookup can ever produce, so `request_search` hands the dashboard a hash
	 * `url_detail` refuses — the URL is in the index and unreachable anyway.
	 */
	public function test_index_keys_a_row_by_its_bucket_key_never_a_derived_hash(): void {
		$url    = 'https://sevendaysvt.example/jobs/filmtimes/import-film-times';
		$hash   = Log_Manager::url_hash( $url );
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		// No 'url' key — the shape the sha256 fallback existed to cover.
		$this->set_url_bucket( $store, $bucket, [
			$hash => [ 'count' => 3, 'timed_count' => 3, 'sum_ms' => 900.0, 'last_seen' => 1700000000 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			$hash
		);

		$this->assertSame( $hash, $result['stats']['hash'] );
		// No `url` field in the row: blank, never the key standing in for one.
		$this->assertSame( '', $result['stats']['url'] );
		$this->assertEqualsWithDelta( 300.0, $result['stats']['avg_ms'], 0.01 );
	}

	public function test_urls_verb_folds_min_ms_across_two_timed_buckets(): void {
		// Two timed buckets for the same hash: the read merge must take the min of
		// both bucket minima (the second fold hits the min() branch).
		$store    = new Stats_Store( 0, 86400 );
		$hash     = 'abcabcabc123';
		$bucket_a = Stats_Store::bucket_key( \time() - 600 );
		$bucket_b = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket_a, [
			$hash => [ 'url' => '/m', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 200.0, 'min_ms' => 80, 'max_ms' => 100.0, 'last_seen' => 1 ],
		] );
		$this->set_url_bucket( $store, $bucket_b, [
			$hash => [ 'url' => '/m', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 300.0, 'min_ms' => 30, 'max_ms' => 120.0, 'last_seen' => 2 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 30, $result['data'][0]['min_ms'] );
	}

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
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
			// Each fire() builds a fresh request-scope graph; reset Core between
			// the two so the second _router/_command_interpreter don't collide.
			VerbHarness::reset();
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
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
		// urls reads sort/order/limit/offset/search/server/errors_only and
		// include_workers — all optional. `include_workers` opts IN because its
		// default EXCLUDES, unlike `errors_only`, which opts in to narrow.
		$args = self::args_by_name( 'urls' );
		$this->assertSame(
			[ 'sort', 'order', 'limit', 'offset', 'search', 'server', 'errors_only', 'include_workers' ],
			\array_keys( $args )
		);
		$this->assertSame( 'bool', $args['include_workers']['type'] );
		$this->assertFalse( $args['include_workers']['default'] );
		$this->assertSame( 'bool', $args['errors_only']['type'] );
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
		// breakdown/server/categories. `server` scopes it the way it scopes the
		// table this modal opens from.
		$args = self::args_by_name( 'url_detail' );
		$this->assertSame( [ 'hash', 'breakdown', 'server', 'categories' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertFalse( $args['breakdown']['required'] );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertFalse( $args['server']['required'] );
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

	/**
	 * The dashboard reads in a web request, where no Flame_Builder exists to arm
	 * the mirror — so its stores must reach the durable frames on their own.
	 */
	public function test_the_leaderboard_recovers_an_evicted_bucket_from_the_mirror(): void {
		$this->activate_shipped_topology( 'performance', 1 );
		Core::$memd = new \Newspack_Nodes\Tests\Helpers\InMemoryMemcached();

		$dir = \Newspack_Nodes\Bootstrap::node_dirs( 'flame-stats:partition' )[0] ?? '';
		$this->assertNotSame( '', $dir, 'the shipped topology declares a mirror partition' );

		$bucket = Stats_Store::bucket_key( \time() );
		$hash   = 'ab12cd34ef56';
		$key    = Stats_Store::entry_key( 0, 'urls:' . Stats_Store::url_shard( $hash ) . ':' . $bucket );
		$rows   = [ $hash => [ 'url' => 'https://example.test/jobs/import-film-times', 'count' => 2194 ] ];

		$mirror = new \Newspack_Nodes\Partition_Node();
		$mirror->arguments( [ $dir, '67108864' ] );
		$mirror->void_warranty();
		$mirror->with_index( Flame_Builder_Node::format_stats_index_entry( ... ) );
		$msg                         = Message::new_message();
		$msg[ Message::TYPE ]        = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ]   = \time();
		$msg[ Message::KEY ]         = $key;
		$msg[ Message::VALUE ]       = [ 'key' => $key, 'data' => $rows, 'ttl' => 43200 ];
		$mirror->fill( $msg );
		$mirror->flush();

		// Nothing in memcache: the bucket was evicted, as on a busy host.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertIsArray( $result );
		$urls = \array_column( $result['data'] ?? [], 'url' );
		$this->assertContains( 'https://example.test/jobs/import-film-times', $urls );
	}

	// ── disk-walk fan-out, index-row shape, leaderboard arithmetic ───────────

	/**
	 * The flame lookup fans across EVERY flame partition: the builder writes to
	 * whichever one it is wired into, so a flame for a p0 request can land in p2.
	 */
	public function test_request_detail_merges_a_flame_written_to_another_partition(): void {
		$this->activate_shipped_topology( 'performance', 3 );
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-elsewhere-00000000001',
			'url'            => '/flame-in-p2',
			'timestamp'      => 1700003100,
			'duration_ms'    => 27,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
		] );
		$this->write_flame(
			[
				'rid'      => $rid,
				'url_hash' => Log_Manager::url_hash( '/flame-in-p2' ),
				'flame'    => [ 'name' => 'request', 'value' => 27, 'children' => [] ],
			],
			2
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_detail', $rid );

		$this->assertIsArray( $result );
		$this->assertSame( 27, $result['flame_data']['flame']['value'] );
	}

	/**
	 * The recent-requests walk collects across every request partition, and the
	 * partition index it stamps is the one the entry was READ from.
	 */
	public function test_url_detail_collects_recent_requests_from_every_partition(): void {
		$this->activate_shipped_topology( 'performance', 3 );
		$url   = '/spread-across-partitions';
		$hash  = Log_Manager::url_hash( $url );
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 2, 'timed_count' => 2, 'sum_ms' => 61.0, 'last_seen' => 1700003300 ],
		] );
		$this->write_request(
			[
				'rid'            => 'rid-spread-p0-000000000000001',
				'url'            => $url,
				'timestamp'      => 1700003200,
				'duration_ms'    => 29,
				'status_code'    => 200,
				'peak_mb'        => 2,
				'request_method' => 'GET',
			],
			0
		);
		$this->write_request(
			[
				'rid'            => 'rid-spread-p2-000000000000001',
				'url'            => $url,
				'timestamp'      => 1700003300,
				'duration_ms'    => 32,
				'status_code'    => 503,
				'peak_mb'        => 6,
				'request_method' => 'POST',
			],
			2
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( 2, $result['requests'] );
		$this->assertSame( 'rid-spread-p2-000000000000001', $result['requests'][0]['rid'] );
		$this->assertSame( 2, $result['requests'][0]['partition'] );
		$this->assertSame( 0, $result['requests'][1]['partition'] );
	}

	/**
	 * The loader emits the recency fields under the names the projection and the
	 * dashboard read — `recent_count` / `last_updated`, never the bucket's own
	 * `recent` / `last_seen` — plus the `aggregate` flag and a defaulted min_ms.
	 */
	public function test_the_index_row_carries_the_projections_field_names(): void {
		$recent_bucket = Stats_Store::retention_buckets( 86400, \time() )[1];
		$store         = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $recent_bucket, [
			'c0ffee123456' => [
				'url'         => '/named-fields',
				'count'       => 9,
				'timed_count' => 9,
				'sum_ms'      => 333.0,
				'min_ms'      => 17,
				'last_seen'   => 1711111111,
			],
		] );

		$rows = Performance_CI_Node::load_index_default();

		$row = \array_values( \array_filter( $rows, static fn ( $r ) => 'c0ffee123456' === $r['hash'] ) )[0] ?? null;
		$this->assertIsArray( $row );
		$this->assertSame( 1711111111, $row['last_updated'] );
		$this->assertSame( 9, $row['recent_count'] );
		$this->assertSame( 17.0, $row['min_ms'] );
		$this->assertFalse( $row['aggregate'] );
		$this->assertArrayNotHasKey( 'last_seen', $row, 'the bucket name must not reach the projection' );
		$this->assertArrayNotHasKey( 'recent', $row, 'the bucket name must not reach the projection' );
	}

	/** A row with no timed bucket to fold reports min_ms 0 rather than a missing key. */
	public function test_the_index_row_defaults_min_ms_when_nothing_timed_folded(): void {
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'facade000777' => [
				'url'         => '/never-timed',
				'count'       => 6,
				'timed_count' => 0,
				'min_ms'      => 91,
				'last_seen'   => 1711111222,
			],
		] );

		$rows = Performance_CI_Node::load_index_default();

		$row = \array_values( \array_filter( $rows, static fn ( $r ) => 'facade000777' === $r['hash'] ) )[0] ?? null;
		$this->assertIsArray( $row );
		$this->assertSame( 0.0, $row['min_ms'], 'an untimed bucket must not fold its min_ms sentinel' );
	}

	/**
	 * The leaderboard reader sums each category's own totals across buckets and
	 * leaves `entries` alone — the dashboard never reads a global category's
	 * entries, and folding them would carry every appearance in the window.
	 */
	public function test_the_leaderboard_sums_categories_without_folding_entries(): void {
		$window = Stats_Store::retention_buckets( 86400, \time() );
		$store  = new Stats_Store( 0, 86400 );
		$store->set_leaderboard_bucket( $window[0], [
			'count'        => 4,
			'sum_req_time' => 8.0,
			'categories'   => [
				'db' => [
					'samples'   => 3,
					'sum_time'  => 1.5,
					'sum_count' => 6.0,
					'entries'   => [ 'wpdb::query' => [ 0.9, 3.0, 3 ] ],
				],
			],
		] );
		$store->set_leaderboard_bucket( $window[1], [
			'count'        => 6,
			'sum_req_time' => 12.0,
			'categories'   => [
				'db' => [
					'samples'   => 4,
					'sum_time'  => 2.5,
					'sum_count' => 10.0,
					'entries'   => [ 'wpdb::get_row' => [ 1.1, 4.0, 4 ] ],
				],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$board = $result['global_leaderboard'];
		$this->assertSame( 10, $board['count'] );
		$this->assertSame( 7, $board['categories']['db']['samples'] );
		$this->assertEqualsWithDelta( 0.4, $board['categories']['db']['time'], 0.0001 );
		$this->assertEqualsWithDelta( 1.6, $board['categories']['db']['count'], 0.0001 );
		$this->assertSame( [], $board['categories']['db']['entries'], 'the reader must not fold per-entry appearances' );
	}

}
