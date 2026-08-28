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
use PHPUnit\Framework\Attributes\Medium;
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
#[Medium]
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

	/** Bytes already appended per scratch segment path — the append offset. */
	private array $segment_bytes = [];
	/**
	 * Append one record to a scratch `{log}.p{N}` segment plus its index, and
	 * return its rid. Requests and flames differ only in the log name and the
	 * formatter, whose signatures match.
	 *
	 * APPENDS. Rewriting the whole segment per record made seeding O(n^2) in
	 * bytes, which the cap tests pay 500 times over; the offset ledger is what
	 * lets the write be an append without re-reading to find the end.
	 *
	 * @param string   $log       Log basename ('requests' | 'flames').
	 * @param callable $format    `format_index_entry( array $message, array $position ): ?string`.
	 * @param array    $body      Record VALUE.
	 * @param int      $partition Partition index.
	 * @return string The record's rid.
	 */
	private function write_indexed( string $log, callable $format, array $body, int $partition ): string {
		$segment_dir = $this->tmp . "/logs/{$log}.p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$message[ Message::VALUE ]     = $body;
		$packed                        = Message::packed( $message );

		$seg_path = "{$segment_dir}/0.log";
		$offset   = $this->segment_bytes[ $seg_path ] ?? 0;
		\file_put_contents( $seg_path, $packed, FILE_APPEND | LOCK_EX );
		$this->segment_bytes[ $seg_path ] = $offset + \strlen( $packed );

		$index_line = $format(
			$message,
			[ 'segment' => 0, 'offset' => $offset, 'length' => \strlen( $packed ) ]
		);
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return Core::as_string( $body['rid'] ?? '' );
	}

	private function write_request( array $body, int $partition = 0 ): string {
		return $this->write_indexed( 'requests', Request_Builder_Node::format_index_entry( ... ), $body, $partition );
	}

	private function write_flame( array $body, int $partition = 0 ): string {
		return $this->write_indexed( 'flames', Flame_Builder_Node::format_index_entry( ... ), $body, $partition );
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

	public function test_overview_verb_answers_one_dimension_in_the_breakdowns_map(): void {
		// One dimension is the same shape as five. Answered flat instead, the
		// key the reader looks the dimension up by is absent, and a reader
		// that reads an absent key as "still in flight" waits forever.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$store->set_dimensional_bucket( 'country', $bucket, [ 'PT' => [ 'c' => 23, 's' => 2.3, 'm' => 0.7 ] ] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=country'
		);

		$this->assertSame( 23, $result['breakdowns']['country'][ $bucket ]['PT']['c'] );
		$this->assertArrayNotHasKey( 'breakdown_time_series', $result );
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

	public function test_overview_verb_refuses_an_unknown_breakdown_dimension(): void {
		// An unknown dimension still never reaches a memcache key. Dropped
		// silently it answered about the dimensions it recognized instead,
		// which reads on the client as a dimension that never arrives; a
		// chart option added without its dimension has to say so.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=server,viewport'
		);

		$this->assertStringContainsString( 'invalid breakdown dimension: viewport', $result );
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

	/**
	 * `url_detail` asks about ONE URL, and one URL lives in exactly one shard —
	 * `Stats_Store::url_shard()` is the first hex digit of its hash. Reaching it
	 * through the whole merged index made the modal pay the URL TABLE's fan-out:
	 * on the staging hub that is 18,432 keys and 54 MB to answer about one row.
	 */
	public function test_url_detail_reads_only_the_shard_its_hash_names(): void {
		$memd  = Core::$memd;
		$store = new Stats_Store( 0, 86400 );
		// Two rows, deliberately in DIFFERENT shards: the first hex digit is
		// the shard, so `a…` and `b…` cannot share one.
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => '/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
			'b8823bc1d2ef' => [ 'url' => '/quokka-8823', 'count' => 17, 'sum_ms' => 411.0, 'timed_count' => 17 ],
		] );
		$memd->multi_keys = 0;
		$result           = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'a4471ab0c0de'
		);
		$one_shard = $memd->multi_keys;

		$this->assertSame( '/wombat-4471', $result['stats']['url'] );
		$this->assertSame( 31, $result['stats']['count'] );

		// Against what the whole table costs — a ratio rather than a count, so
		// this says "one shard, not sixteen" whatever the read plan's width is.
		Core::cleanup_all_nodes();
		$memd->multi_keys = 0;
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame(
			$memd->multi_keys,
			$one_shard * Stats_Store::URL_SHARDS,
			'url_detail must point-read the hash\'s shard, not the whole index'
		);
	}

	/**
	 * Decision 14's real guard: a request that already holds the whole index
	 * answers about one URL FROM it. Point-reading unconditionally would make
	 * the memo a per-scope cache and pay the fan-out twice for one poll — which
	 * is the defect that put "one unscoped read" in the decision to begin with.
	 */
	public function test_a_request_holding_the_index_does_not_read_again_for_one_row(): void {
		$memd  = Core::$memd;
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => '/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
		] );
		$node = new Performance_CI_Node();

		// The table first: one fan-out, and the merged index is now in hand.
		VerbHarness::fire( $node, 'performance', 'urls' );
		// The harness mounts a backbone per fire; the NODE is what carries the
		// memo across the two verbs of one request.
		Core::cleanup_all_nodes();
		$memd->multi_keys = 0;
		$detail           = VerbHarness::fire( $node, 'performance', 'url_detail', 'a4471ab0c0de' );

		$this->assertSame( '/wombat-4471', $detail['stats']['url'] );
		$this->assertSame( 0, $memd->multi_keys, 'the index was already read for this request' );
	}

	/**
	 * The reader takes the coarse hour where one has been folded, and falls
	 * back to that hour's twelve fine buckets where one has not — which is what
	 * makes a fresh deploy, and an hour a worker was down for, self-healing
	 * rather than a hole in the table.
	 */
	public function test_the_index_reads_a_folded_hour_and_falls_back_where_none_was_folded(): void {
		$store = new Stats_Store( 0, 86400 );
		$hash  = 'a4471ab0c0de';
		$shard = Stats_Store::url_shard( $hash );
		$plan  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, \time() ) );
		// The newest closed hour is folded; the one behind it never was.
		$this->seed_url_hour( $store, $plan['hours'][0], $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 70.0 ],
		] );
		$this->seed_url_shard( $store, Stats_Store::buckets_in_hour( $plan['hours'][1] )[3], $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );

		$row = Performance_CI_Node::load_row_default( $hash );

		$this->assertNotNull( $row );
		$this->assertSame( 12, $row['count'], 'the folded hour and the unfolded one both counted' );
	}

	/**
	 * The coarse tier is DERIVED, so it is deliberately not mirrored — which
	 * only holds if losing an hour costs nothing. Evict one and the reader
	 * must answer from the fine buckets it was folded from, which ARE
	 * mirrored. That fallback is the whole reason the tier needs no durability
	 * of its own.
	 */
	public function test_an_evicted_hour_is_answered_from_the_buckets_it_was_folded_from(): void {
		$store = new Stats_Store( 0, 86400 );
		$hash  = 'a4471ab0c0de';
		$shard = Stats_Store::url_shard( $hash );
		$hour  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, \time() ) )['hours'][0];
		$this->seed_url_shard( $store, Stats_Store::buckets_in_hour( $hour )[4], $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 23, 'timed_count' => 23, 'sum_ms' => 460.0 ],
		] );
		$this->seed_url_hour( $store, $hour, $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 23, 'timed_count' => 23, 'sum_ms' => 460.0 ],
		] );
		$this->assertSame( 23, Performance_CI_Node::load_row_default( $hash )['count'] );

		// Gone, the way memcache drops an item under pressure.
		Core::$memd->delete( Stats_Store::entry_key( 0, Stats_Store::NS_URLS_HOUR . ":{$shard}:{$hour}" ) );

		$this->assertSame(
			23,
			Performance_CI_Node::load_row_default( $hash )['count'],
			'the fine buckets answer for an hour that is no longer folded'
		);
	}

	/**
	 * All sixteen shards or none. `roll_up_hours()` folds a hour shard by
	 * shard and treats it as done only when every shard has a key — but the
	 * reader treated ONE key anywhere as the whole hour, so a fold that died
	 * between shards (or a shard whose write was refused) made the reader skip
	 * the fine-bucket fallback for the shards that had none. Their traffic left
	 * the window, and for a refused write it left it for good.
	 */
	public function test_a_partly_folded_hour_falls_back_rather_than_half_reading_it(): void {
		$store = new Stats_Store( 0, 86400 );
		$hour  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, \time() ) )['hours'][0];
		$bucket = Stats_Store::buckets_in_hour( $hour )[6];
		// Two URLs in two different shards; both have fine buckets.
		foreach ( [ 'a4471ab0c0de' => '/wombat-4471', 'b8823bc1d2ef' => '/quokka-8823' ] as $hash => $url ) {
			$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => $url, 'count' => 9, 'timed_count' => 9, 'sum_ms' => 90.0 ],
			] );
		}
		// Only ONE shard got its coarse key — the fold died after the first.
		$this->seed_url_hour( $store, $hour, 'a', [
			'a4471ab0c0de' => [ 'url' => '/wombat-4471', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 90.0 ],
		] );

		$rows = [];
		foreach ( Performance_CI_Node::load_index_default() as $row ) {
			$rows[ Core::as_string( $row['hash'] ) ] = Core::as_int( $row['count'] );
		}

		$this->assertSame( 9, $rows['b8823bc1d2ef'] ?? 0, 'the unfolded shard falls back to its buckets' );
		$this->assertSame( 9, $rows['a4471ab0c0de'] ?? 0, 'and the folded one is not counted twice' );
	}

	/**
	 * And a folded hour must not be counted TWICE — once coarse, once from the
	 * fine buckets it was folded from. Those buckets outlive the fold.
	 */
	public function test_a_folded_hour_is_not_counted_again_from_its_fine_buckets(): void {
		$store = new Stats_Store( 0, 86400 );
		$hash  = 'a4471ab0c0de';
		$shard = Stats_Store::url_shard( $hash );
		$hour  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, \time() ) )['hours'][0];
		$this->seed_url_shard( $store, Stats_Store::buckets_in_hour( $hour )[2], $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );
		$this->seed_url_hour( $store, $hour, $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );

		$row = Performance_CI_Node::load_row_default( $hash );

		$this->assertSame( 5, $row['count'], 'the fold replaces its buckets, it does not add to them' );
	}

	/**
	 * The whole point, as a number: with every closed hour folded, a read of
	 * the URL index costs `fine + hours` keys per shard — between 36 and 47
	 * depending on where the clock sits in the hour, against the 288
	 * five-minute buckets it used to enumerate. On a four-partition hub that
	 * is around 2,300 keys against 18,432, and 54 MB it no longer reads.
	 */
	public function test_a_folded_window_reads_two_tiers_not_every_bucket(): void {
		$memd = Core::$memd;
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, \time() ) );
		$store = new Stats_Store( 0, 86400 );
		foreach ( $plan['hours'] as $hour ) {
			foreach ( Stats_Store::url_shards() as $shard ) {
				$this->seed_url_hour( $store, $hour, $shard, [] );
			}
		}

		$memd->multi_keys = 0;
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$per_shard = \count( $plan['fine'] ) + \count( $plan['hours'] );
		$this->assertLessThan( 48, $per_shard, 'two tiers, not 288 buckets' );
		$this->assertSame(
			$per_shard * Stats_Store::URL_SHARDS,
			$memd->multi_keys,
			'a folded window reads no fine bucket behind the recent tail'
		);
	}

	public function test_url_detail_returns_recent_matching_requests(): void {
		// url_detail's `requests` slice walks requests.log for entries whose
		// url_hash matches. Seed the URL in the memcache index AND two on-disk
		// requests so the collect + dedup walk runs (not the empty-result skip).
		// Timestamps ride the clock: the walk stops at the retention floor, so a
		// fixed epoch would put the whole fixture behind it.
		$url    = '/recent-list';
		$hash   = Log_Manager::url_hash( $url );
		$now    = \time();
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			$hash => [ 'url' => $url, 'count' => 2, 'sum_ms' => 32.0, 'last_seen' => $now - 623 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-recent-a-1234567890123456',
			'url'            => $url,
			'timestamp'      => $now - 1817,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );
		$this->write_request( [
			'rid'            => 'rid-recent-b-1234567890123456',
			'url'            => $url,
			'timestamp'      => $now - 623,
			'duration_ms'    => 20,
			'status_code'    => 500,
			'peak_mb'        => 3,
			'request_method' => 'POST',
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'url_detail', $hash );

		$this->assertCount( 2, $result['requests'] );
		// Sorted by timestamp DESC → the newest (b) leads.
		$this->assertSame( 'rid-recent-b-1234567890123456', $result['requests'][0]['rid'] );
	}

	/**
	 * The watermark stop reads COMPLETION, not start. The index is appended
	 * when a request ENDS, so a reverse walk is completion-descending and start
	 * is not monotone along it — `Request_Builder_Node::index_completion_columns()`
	 * says so in the class that writes the line, and the floor branch beside
	 * this one already obeys it.
	 *
	 * Comparing start ends the partition at the first long-running request,
	 * dropping every row that completed after it. The merge then advances past
	 * them, so they never come back — on a URL that IS the long-running one, an
	 * import or a cron endpoint, that is every poll.
	 */
	public function test_url_detail_since_stops_on_completion_not_start(): void {
		$url   = '/long-running';
		$hash  = Log_Manager::url_hash( $url );
		$now   = \time();
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 2, 'sum_ms' => 700010.0, 'last_seen' => $now ],
		] );
		// Appended in COMPLETION order, as the builder appends them. The long
		// one finishes LAST, so the reverse walk meets it first.
		$this->write_request( [
			'rid'            => \sprintf( '%032x', 0xC01 ),
			'url'            => $url,
			'timestamp'      => $now - 300,
			'duration_ms'    => 10,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );
		$this->write_request( [
			'rid'            => \sprintf( '%032x', 0xC00 ),
			'url'            => $url,
			// Starts well below the watermark; completes well above it.
			'timestamp'      => $now - 900,
			'duration_ms'    => 700000,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			[ $hash, '--since=' . ( $now - 450 ) ]
		);
		$rids = \array_column( $result['requests'], 'rid' );

		$this->assertContains(
			\sprintf( '%032x', 0xC00 ),
			$rids,
			'a request that COMPLETED above the watermark must be returned'
		);
		$this->assertContains(
			\sprintf( '%032x', 0xC01 ),
			$rids,
			'and it must not end the partition on top of everything behind it'
		);
	}

	public function test_url_detail_since_still_reads_later_partitions(): void {
		$this->activate_shipped_topology( 'performance', 2 );
		$url   = '/two-partitions';
		$hash  = Log_Manager::url_hash( $url );
		$now   = \time();
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 2, 'sum_ms' => 20.0, 'last_seen' => $now ],
		] );
		// p0 holds only ground the browser has; p1 holds something newer.
		$this->write_request( [
			'rid'            => \sprintf( '%032x', 0xB00 ),
			'url'            => $url,
			'timestamp'      => $now - 900,
			'duration_ms'    => 10,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		], 0 );
		$this->write_request( [
			'rid'            => \sprintf( '%032x', 0xB01 ),
			'url'            => $url,
			'timestamp'      => $now - 300,
			'duration_ms'    => 10,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		], 1 );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			[ $hash, '--since=' . ( $now - 450 ) ]
		);

		$this->assertSame(
			[ \sprintf( '%032x', 0xB01 ) ],
			\array_column( $result['requests'], 'rid' ),
			'p0 hitting the watermark must not skip p1'
		);
	}

	public function test_url_detail_reports_a_scan_that_stopped_before_reaching_the_url(): void {
		// A low-traffic URL among high-traffic neighbours: the index walk spends
		// its whole entry budget on the newer lines and never reaches the one
		// matching entry. An empty list then says "no requests", which is a lie
		// — the truth is that the scan stopped, and the payload has to say so.
		$url    = '/buried-under-neighbours';
		$hash   = Log_Manager::url_hash( $url );
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 37.0, 'last_seen' => \time() - 2311 ],
		] );
		// Inside the window, so the BUDGET is the only thing that can stop the walk.
		$this->write_request( [
			'rid'            => 'rid-buried-1234567890123456789',
			'url'            => $url,
			'timestamp'      => \time() - 2311,
			'duration_ms'    => 37,
			'status_code'    => 418,
			'peak_mb'        => 5,
			'request_method' => 'GET',
		] );
		// Newer than that entry and more numerous than the budget. Short lines:
		// the budget counts entries scanned, not bytes, and a line under the
		// fixed width parses as no entry.
		\file_put_contents(
			$this->tmp . '/logs/requests.p0/0.idx',
			\str_repeat( "x\n", Performance_CI_Node::MAX_INDEX_ENTRIES + 1 ),
			FILE_APPEND | LOCK_EX
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertSame( [], $result['requests'], 'the budget ran out before the matching entry' );
		$this->assertTrue( $result['scan_stopped_early'], 'a stopped scan is not an empty result' );
	}

	public function test_url_detail_calls_a_full_request_list_complete_not_truncated(): void {
		// The per-URL cap ends the walk with the answer in hand; only the entry
		// budget running out is truncation. Seeding one past the cap proves the
		// early exit is not reported as a stopped scan.
		$url   = '/at-the-request-cap';
		$hash  = Log_Manager::url_hash( $url );
		$limit = (int) ( new \ReflectionClassConstant( Performance_CI_Node::class, 'RECENT_REQUEST_LIMIT' ) )->getValue();
		$now   = \time();
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => $limit + 1, 'sum_ms' => 64.0, 'last_seen' => $now - 907 ],
		] );
		for ( $i = 0; $i <= $limit; $i++ ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-cap-%024d', $i ),
				'url'            => $url,
				'timestamp'      => $now - 1408 + $i,
				'duration_ms'    => 64,
				'status_code'    => 203,
				'peak_mb'        => 7,
				'request_method' => 'GET',
			] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( $limit, $result['requests'] );
		$this->assertFalse( $result['scan_stopped_early'], 'reaching the per-URL cap is a complete answer' );
		// And it keeps the NEWEST end. Walking forward from the oldest, the cap
		// fires on the oldest matches, so the panel would show the start of a
		// busy URL's history sorted descending to look convincing.
		$rids = \array_column( $result['requests'], 'rid' );
		$this->assertContains( \sprintf( 'rid-cap-%024d', $limit ), $rids, 'the newest request is missing' );
		$this->assertNotContains( \sprintf( 'rid-cap-%024d', 0 ), $rids, 'the oldest should have fallen off' );
	}

	// -------------------------------------------------------------------------
	// Retention edge: the walk stops where an answer could no longer be.
	//
	// An index line is appended at its request's COMPLETION, so completion is
	// what the stop compares — `timestamp` is the START, and a long request
	// carries one from far outside the window. The line alone cannot end the
	// walk either: on a hub, append order is the spokes' arrival order, so the
	// stop also needs the segment's index to have gone untouched since the
	// window opened, which is the one clock the reader owns.
	// -------------------------------------------------------------------------

	/** A retention window narrow enough to place seeded entries either side of it. */
	private const SCAN_RETENTION = 7200;

	/** Backdate a seeded segment's index, the way a segment closed hours ago reads. */
	private function close_segment_index( int $seconds_ago, int $partition = 0, int $segment = 0 ): void {
		$path = $this->tmp . "/logs/requests.p{$partition}/{$segment}.idx";
		\touch( $path, \time() - $seconds_ago );
		\clearstatcache( true, $path );
	}

	public function test_url_detail_names_the_window_its_request_list_was_drawn_from(): void {
		// The list stops at the window, so an empty one is only empty OF that
		// window — a reply that does not say which reads as the site's whole
		// record. The number is the walk's own floor, not a rounded hour.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/named-window-6205';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 47.0, 'last_seen' => $now - 62 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertSame(
			Stats_Store::window_start( self::SCAN_RETENTION, $now ),
			$result['requests_window_start'],
			'the reply has to name the window its list is of'
		);
	}

	public function test_a_long_running_request_does_not_end_the_url_walk(): void {
		// A request logging every few minutes stays in flight indefinitely and
		// appends at completion, so its START can precede the window by hours.
		// Completion is what the window asks about, and this one completed
		// inside it — the matching entry behind it is still reachable.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/behind-a-long-runner-5182';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 58.0, 'last_seen' => $now - 211 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-inside-the-window-771300000',
			'url'            => $url,
			'timestamp'      => $now - 211,
			'duration_ms'    => 58,
			'status_code'    => 206,
			'peak_mb'        => 9,
			'request_method' => 'GET',
		] );
		// Started 3h ago, ran for 2h46m — the shape of a cron job, and far
		// longer than any bucket rotation would have held it in flight.
		$this->write_request( [
			'rid'            => 'rid-the-long-runner-883100000000',
			'url'            => '/a-long-running-job-6624',
			'timestamp'      => $now - 10800,
			'duration_ms'    => 10000000,
			'status_code'    => 201,
			'peak_mb'        => 4,
			'request_method' => 'GET',
		] );
		$this->close_segment_index( 60 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( 1, $result['requests'], 'a start time outside the window is not an ending' );
		$this->assertSame( 'rid-inside-the-window-771300000', $result['requests'][0]['rid'] );
	}

	public function test_the_url_walk_stops_at_a_closed_segment_whose_newest_line_completed_first(): void {
		// Nothing has been appended to this segment since the window opened,
		// and its newest line completed before it — so every line behind it
		// completed earlier still, and the walk ends rather than reading them.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/behind-the-retention-edge-8813';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 71.0, 'last_seen' => $now - 137 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-past-the-edge-55190000000000',
			'url'            => $url,
			'timestamp'      => $now - 137,
			'duration_ms'    => 71,
			'status_code'    => 207,
			'peak_mb'        => 11,
			'request_method' => 'GET',
		] );
		// Appended after it, and finished well before the window opened.
		$this->write_request( [
			'rid'            => 'rid-the-edge-marker-661900000000',
			'url'            => '/an-unrelated-neighbour-2277',
			'timestamp'      => $now - 9413,
			'duration_ms'    => 12,
			'status_code'    => 204,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );
		$this->close_segment_index( 8000 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertSame( [], $result['requests'], 'the walk read past the retention edge' );
		$this->assertFalse( $result['scan_stopped_early'], 'the retention edge is not a spent budget' );
	}

	public function test_an_out_of_window_line_in_a_live_segment_does_not_end_the_url_walk(): void {
		// The hub's route: every spoke's requests land in ONE partition in
		// ARRIVAL order, so a spoke reconnecting after a lag replays hours-old
		// lines between live ones. A segment still being appended to can hold
		// an in-window row behind any of them.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/behind-a-replayed-spoke-4409';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 33.0, 'last_seen' => $now - 96 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-live-traffic-4471000000000',
			'url'            => $url,
			'timestamp'      => $now - 96,
			'duration_ms'    => 33,
			'status_code'    => 208,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		] );
		$this->write_request( [
			'rid'            => 'rid-the-replayed-line-2960000000',
			'url'            => '/a-lagging-spoke-3318',
			'timestamp'      => $now - 21600,
			'duration_ms'    => 17,
			'status_code'    => 204,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( 1, $result['requests'], 'arrival order is no ordering of completions' );
		$this->assertSame( 'rid-live-traffic-4471000000000', $result['requests'][0]['rid'] );
	}

	public function test_a_line_carrying_no_readable_time_does_not_end_the_url_walk(): void {
		// A zero or non-numeric time column casts to 0, older than every floor.
		// Only a line that parses as a completion may end a walk — otherwise one
		// unreadable line ends a whole partition, silently and totally.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/behind-an-unreadable-line-9047';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 84.0, 'last_seen' => $now - 319 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-behind-the-junk-line-99310',
			'url'            => $url,
			'timestamp'      => $now - 319,
			'duration_ms'    => 84,
			'status_code'    => 205,
			'peak_mb'        => 13,
			'request_method' => 'GET',
		] );
		// Full width, so the length check passes and the time column reads 0.
		\file_put_contents(
			$this->tmp . '/logs/requests.p0/0.idx',
			\str_repeat( '0', 97 ) . "\n",
			FILE_APPEND | LOCK_EX
		);
		$this->close_segment_index( 8000 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( 1, $result['requests'], 'an unreadable time is no completion' );
	}

	public function test_a_line_too_short_to_carry_a_time_does_not_end_the_url_walk(): void {
		// The floor slices the raw column in place, and a slice off the end of a
		// short line reads as 0 — the oldest time there is. One malformed line
		// must not truncate the walk behind it; only the budget bounds junk.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/behind-a-short-line-9047';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 84.0, 'last_seen' => $now - 319 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-behind-the-short-line-99310',
			'url'            => $url,
			'timestamp'      => $now - 319,
			'duration_ms'    => 84,
			'status_code'    => 205,
			'peak_mb'        => 13,
			'request_method' => 'GET',
		] );
		\file_put_contents( $this->tmp . '/logs/requests.p0/0.idx', "zz\n", FILE_APPEND | LOCK_EX );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( 1, $result['requests'], 'a malformed line is no retention edge' );
	}

	public function test_the_url_walk_returns_every_entry_inside_the_window(): void {
		// Nothing reaches the edge, so the bound is invisible.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = \time();
		$url  = '/wholly-inside-the-window-3352';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 3, 'sum_ms' => 96.0, 'last_seen' => $now - 43 ],
		] );
		foreach ( [ 6011, 2903, 43 ] as $i => $ago ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-inside-%020d', $i ),
				'url'            => $url,
				'timestamp'      => $now - $ago,
				'duration_ms'    => 32,
				'status_code'    => 202,
				'peak_mb'        => 6,
				'request_method' => 'GET',
			] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertCount( 3, $result['requests'] );
		$this->assertFalse( $result['scan_stopped_early'] );
	}

	public function test_the_url_scan_refuses_a_line_that_only_carries_the_matched_column(): void {
		// The walk compares the raw url_hash column before parsing. A line that
		// carries the column but is too short to be an index entry is not a
		// request, and the pre-filter must not turn it into one.
		$url  = '/column-lookalike-4417';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( new Stats_Store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 19.0, 'last_seen' => 1700005000 ],
		] );
		$dir = $this->tmp . '/logs/requests.p0';
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0755, true );
		}
		\file_put_contents(
			"{$dir}/0.idx",
			\str_pad( 'rid-imposter-9931', 32 ) . \str_pad( $hash, 12 ) . "\n",
			FILE_APPEND | LOCK_EX
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_detail', $hash );

		$this->assertSame( [], $result['requests'], 'a short line is no entry, whatever its first 44 bytes read' );
		$this->assertFalse( $result['scan_stopped_early'] );
	}

	public function test_the_flame_scan_matches_the_rid_column_and_only_that_rid(): void {
		// Same pre-filter, the other index format: its own writer owns the
		// offsets, so a flame belonging to a neighbouring rid stays unmatched.
		$rid = $this->write_request( [
			'rid'         => 'rid-flame-column-773311',
			'url'         => '/flame-column',
			'timestamp'   => 1700005100,
			'duration_ms' => 41,
		] );
		$this->write_flame( [
			'rid'   => 'rid-flame-column-OTHER1',
			'flame' => [ 'name' => 'wrong', 'value' => 3, 'children' => [] ],
		] );
		$this->write_flame( [
			'rid'   => $rid,
			'flame' => [ 'name' => 'right', 'value' => 41, 'children' => [] ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_detail', $rid );

		$this->assertSame( 'right', $result['flame_data']['flame']['name'] );
	}

	public function test_request_search_names_a_spent_budget_rather_than_a_missing_rid(): void {
		// An incomplete search reported as a definite negative sends an
		// operator after a retention bug that does not exist.
		$this->fill_request_index_past_the_budget();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_search', 'rid-never-reached-6f21' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'budget spent', \strtolower( $result ) );
	}

	public function test_request_detail_names_a_spent_budget_rather_than_a_missing_rid(): void {
		$this->fill_request_index_past_the_budget();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'request_detail', 'rid-never-reached-8c04' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'budget spent', \strtolower( $result ) );
	}

	/**
	 * Bury p0's request index under more entries than the scan budget allows.
	 * Short lines: the budget counts entries scanned, not bytes.
	 */
	private function fill_request_index_past_the_budget(): void {
		// One real record first: a segment with no `.log` is no segment.
		$this->write_request( [
			'rid'         => 'rid-buried-under-the-budget',
			'url'         => '/buried-under-the-budget',
			'timestamp'   => 1700005200,
			'duration_ms' => 15,
		] );
		\file_put_contents(
			$this->tmp . '/logs/requests.p0/0.idx',
			\str_repeat( "x\n", Performance_CI_Node::MAX_INDEX_ENTRIES + 1 ),
			FILE_APPEND | LOCK_EX
		);
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
		// value, so an `isset` guard would name the eight sums off nothing and
		// report ZERO for a server that did serve the URL.
		$row = [ 'url' => '/corrupt', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 900.0, 'srv' => [ 'alpha.example' => 'not-an-array' ] ];

		$this->assertNull( Stats_Store::swap_url_server_sums( $row, 'alpha.example' ) );
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

	/**
	 * A stored row carrying an index this version does not know is IGNORED,
	 * never read as something else. It replaces a guard against reading the
	 * leaderboard's `sum_req_time` — a SECONDS field — as a URL row's `sum_ms`
	 * and multiplying it onto a mean: a positional row has no room for a
	 * foreign name, but a newer writer's extra index is the same hazard.
	 */
	public function test_a_url_row_ignores_an_index_it_does_not_know(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( '5ec0d5fa11ba' ), [
			'5ec0d5fa11ba' => self::positional_url_row(
				[ 'url' => '/seconds-era', 'count' => 4, 'last_seen' => 1700000003 ]
			) + [ 99 => 8.0 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$row = $result['data'][0] ?? [];
		$this->assertSame( '/seconds-era', $row['url'] ?? '' );
		$this->assertSame( 0.0, (float) ( $row['avg_ms'] ?? -1 ), 'no milliseconds are invented from it' );
	}

	/**
	 * The collapse turns ABSENCE into meaning, and the two states are adjacent
	 * with opposite answers: `null` says "this server's numbers ARE the row's",
	 * while a host missing from the split says "never served here" and drops
	 * the row. Both halves are pinned, because one reading of the other is a
	 * URL that silently leaves a filtered table or a wrong number in it.
	 */
	public function test_a_collapsed_split_resolves_to_the_rows_own_numbers(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( 'c0119b5ed001' ), [
			'c0119b5ed001' => self::positional_url_row( [
				'url'         => '/collapsed-6142',
				'count'       => 9,
				'timed_count' => 9,
				'sum_ms'      => 819.0,
				'last_seen'   => 1700000009,
				'srv'         => [ 'sole-host.example' => null ],
			] ),
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=sole-host.example'
		);
		$row = $result['data'][0] ?? [];

		$this->assertSame( '/collapsed-6142', $row['url'] ?? '' );
		$this->assertSame( 9, $row['count'] ?? -1, 'the collapsed host gets the row back' );
		$this->assertEqualsWithDelta( 91.0, $row['avg_ms'] ?? -1.0, 1e-6 );
	}

	/** The adjacent state: absent is not null, and still drops the row. */
	public function test_a_host_absent_from_a_collapsed_split_drops_the_row(): void {
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( 'c0119b5ed001' ), [
			'c0119b5ed001' => self::positional_url_row( [
				'url'         => '/collapsed-6142',
				'count'       => 9,
				'timed_count' => 9,
				'sum_ms'      => 819.0,
				'last_seen'   => 1700000009,
				'srv'         => [ 'sole-host.example' => null ],
			] ),
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=never-served.example'
		);

		$this->assertSame( [], $result['data'] ?? [ 'not-empty' ] );
	}

	/**
	 * And the fold must ADD two collapsed buckets rather than skip them:
	 * `sum_fields()` ignores a non-array value, so a collapse the reader does
	 * not expand takes the server's whole history with it.
	 */
	public function test_two_collapsed_buckets_merge_to_the_summed_row(): void {
		$store   = new Stats_Store( 0, 86400 );
		$buckets = Stats_Store::retention_buckets( 86400, \time() );
		foreach ( [ [ $buckets[1], 4, 364.0 ], [ $buckets[2], 5, 455.0 ] ] as [ $bucket, $count, $ms ] ) {
			$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( 'c0119b5ed001' ), [
				'c0119b5ed001' => self::positional_url_row( [
					'url'         => '/collapsed-6142',
					'count'       => $count,
					'timed_count' => $count,
					'sum_ms'      => $ms,
					'last_seen'   => 1700000009,
					'srv'         => [ 'sole-host.example' => null ],
				] ),
			] );
		}

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=sole-host.example'
		);
		$row = $result['data'][0] ?? [];

		$this->assertSame( 9, $row['count'] ?? -1, 'both buckets, not one and not none' );
	}

	/** Seed one URL whose row carries a per-server split. */
	private function seed_split_row(): void {
		$store = new Stats_Store( 0, 86400 );
		$this->seed_url_shard( $store, $this->current_url_bucket(), Stats_Store::url_shard( '5p117c0de991' ), [
			'5p117c0de991' => self::positional_url_row( [
				'url'         => '/split-3907',
				'count'       => 5,
				'timed_count' => 5,
				'sum_ms'      => 650.0,
				'last_seen'   => 1700000007,
				'srv'         => [
					'edge-3907.example' => self::positional_url_row(
						[ 'count' => 3, 'timed_count' => 3, 'sum_ms' => 390.0 ]
					),
					'edge-8823.example' => self::positional_url_row(
						[ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0 ]
					),
				],
			] ),
		] );
	}

	/**
	 * Decision 18 rests the positional split on it never crossing to the wire.
	 * A `srv` on the reply is integer keys the browser cannot read, and JSON
	 * takes them happily — no assertion elsewhere would notice.
	 */
	public function test_an_unscoped_reply_row_carries_no_stored_split(): void {
		$this->seed_split_row();

		$row = ( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' )['data'][0] ) ?? [];

		$this->assertSame( '/split-3907', $row['url'] ?? '' );
		$this->assertArrayNotHasKey( Stats_Store::URL_SRV_FIELD, $row );
		$this->assertArrayNotHasKey( 'srv_recent', $row );
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_int' ) ),
			'no positional key rides out to the wire'
		);
	}

	/** And the scoped read, which is the path that NAMES the split. */
	public function test_a_scoped_reply_row_carries_no_stored_split(): void {
		$this->seed_split_row();

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=edge-3907.example'
		);
		$row = $result['data'][0] ?? [];

		$this->assertSame( '/split-3907', $row['url'] ?? '' );
		$this->assertSame( 3, $row['count'] ?? -1, 'the scoped sums arrive under NAMES' );
		$this->assertArrayNotHasKey( Stats_Store::URL_SRV_FIELD, $row );
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_int' ) ),
			'no positional key rides out to the wire'
		);
	}

	public function test_a_bool_at_a_count_index_contributes_nothing(): void {
		// A stored row carries a BOOL at ROW_WORKER, so a shifted index puts one
		// where a count is read. The lenient family folds `true` as 1 and the
		// number is wrong with nothing to show for it; the validated family
		// takes the default, which is what `Core`'s own rule asks for on an
		// arithmetic path. Seeded at 2, distinct from the 0 default and from
		// the 1 the lenient cast would produce.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( 'b001a7c0un7' ), [
			'b001a7c0un7' => self::positional_url_row(
				[ 'url' => '/bool-at-a-count', 'count' => 2, 'last_seen' => 1700000005 ]
			) + [ Stats_Store::ROW_COUNT_4XX => true ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$row = $result['data'][0] ?? [];
		$this->assertSame( '/bool-at-a-count', $row['url'] ?? '' );
		$this->assertSame( 2, $row['count'] ?? -1, 'the real count is untouched' );
		$this->assertSame( 0, $row['count_4xx'] ?? -1, 'and a bool adds nothing to a count' );
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

	public function test_url_detail_stats_carry_the_header_figures_and_no_series(): void {
		// The modal's chart is drawn from `breakdown_time_series`, which the
		// dropdown always asks for, so a second undifferentiated series bought
		// a first paint nobody chose at the price of a full shard scan.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a5c9e30b1f42' => [
				'url'         => '/reviews/first',
				'count'       => 7,
				'timed_count' => 7,
				'sum_ms'      => 917.0,
				'last_seen'   => 1700001000,
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'a5c9e30b1f42'
		);

		$this->assertArrayNotHasKey( 'time_series', $result['stats'] );
		$this->assertSame( '/reviews/first', $result['stats']['url'] );
		$this->assertSame( 7, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 131.0, $result['stats']['avg_ms'], 1e-6 );
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

	public function test_url_breakdown_answers_the_series_alone(): void {
		// The chart polls this every five minutes and keeps only the series, so
		// the verb behind it must not drag the index walk `url_detail` runs.
		$store  = new Stats_Store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'e71b04ac9d33' => [ 'url' => '/breakdown-only', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 84.0, 'last_seen' => 1700006000 ],
		] );
		$store->set_url_dimensional_bucket( 'e71b04ac9d33', $bucket, [ 'status' => [ '503' => [ 'c' => 9, 's' => 1.7, 'm' => 0.4 ] ] ] );
		$detail = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_detail',
			'e71b04ac9d33 --breakdown=status'
		);
		// Each fire() builds a fresh request-scope graph; reset between them,
		// keeping the memcache the seeded buckets live in.
		$memd       = Core::$memd;
		VerbHarness::reset();
		Core::$memd = $memd;

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_breakdown',
			'e71b04ac9d33 --breakdown=status'
		);

		$this->assertSame( $detail['breakdown_time_series'], $result['breakdown_time_series'] );
		$this->assertSame( 9, $result['breakdown_time_series'][ $bucket ]['503']['c'] );
		$this->assertArrayNotHasKey( 'requests', $result );
		$this->assertArrayNotHasKey( 'stats', $result );
		$this->assertArrayNotHasKey( 'aggregate_flame', $result );
	}

	public function test_url_breakdown_refuses_a_dimension_it_cannot_answer(): void {
		// A required argument that silently answers nothing leaves the chart
		// spinning; url_detail can drop the key because it has a payload.
		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_breakdown',
			'e71b04ac9d33 --breakdown=nosuchdim'
		);

		$this->assertIsString( $result );
		// Named, because the caller's own spelling is what it has to fix.
		$this->assertStringContainsString( 'invalid breakdown dimension: nosuchdim', $result );
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
		Performance_CI_Node::$load_index = static function ( ?string $shard ) use ( &$calls, $original ) {
			++$calls;
			return ( $original ?? [ Performance_CI_Node::class, 'load_index_default' ] )( $shard );
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
			'overview', 'urls', 'url_detail', 'url_breakdown', 'request_search',
			'request_detail', 'hooks_registered', 'set',
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
		// breakdown/server/categories/since. `server` scopes it the way it
		// scopes the table this modal opens from; `since` tails the request
		// list. A read-but-undeclared option is absent from `help`, from the
		// palette and from the MCP tools/list schema, so the list is pinned.
		$args = self::args_by_name( 'url_detail' );
		$this->assertSame( [ 'hash', 'breakdown', 'server', 'categories', 'since' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertFalse( $args['breakdown']['required'] );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertFalse( $args['server']['required'] );
		$this->assertSame( 'bool', $args['categories']['type'] );
		$this->assertFalse( $args['categories']['required'] );
		$this->assertSame( 'int', $args['since']['type'] );
		$this->assertFalse( $args['since']['required'] );
	}

	public function test_url_breakdown_verb_declares_both_of_its_arguments_required(): void {
		// Its whole answer is one dimension of one URL; neither has a default.
		$args = self::args_by_name( 'url_breakdown' );
		$this->assertSame( [ 'hash', 'breakdown' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertSame( 'string', $args['breakdown']['type'] );
		$this->assertTrue( $args['breakdown']['required'] );
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
		// A mirrored frame holds the STORED shape, which is positional.
		$rows   = [
			$hash => self::positional_url_row(
				[ 'url' => 'https://example.test/jobs/import-film-times', 'count' => 2194 ]
			),
		];

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
		$now   = \time();
		$store = new Stats_Store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 2, 'timed_count' => 2, 'sum_ms' => 61.0, 'last_seen' => $now - 742 ],
		] );
		$this->write_request(
			[
				'rid'            => 'rid-spread-p0-000000000000001',
				'url'            => $url,
				'timestamp'      => $now - 1409,
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
				'timestamp'      => $now - 742,
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
