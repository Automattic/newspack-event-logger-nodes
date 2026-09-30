<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * The stats schema over Ledgers: what a span appends, and what each reader
 * answers from the rows every partition appended.
 */
#[CoversClass( Stats_Store::class )]
class StatsStoreTest extends TestCase {

	/** The pinned tick, on a five-minute boundary. */
	private const T = 1790000400;

	/** The window every read here takes: an hour back, one bucket ahead. */
	private const FROM = self::T - 3600;
	private const TO   = self::T + 300;

	private const SKU_41 = 'https://kea.test/sku-41';
	private const SKU_43 = 'https://kea.test/sku-43';
	private const SKU_47 = 'https://kea.test/sku-47';

	protected function setUp(): void {
		parent::setUp();
		Core::$clock = static fn (): float => (float) self::T;
		Core::right_now();
	}

	/** A url-rows row's thirteen columns, `ROW_*` order. */
	private static function url_row( int $count, int $timed, float $sum_ms, int $errors = 0, ?float $min = 37.0, ?float $max = 37.0, int $last = self::T ): array {
		return [ $count, $timed, $sum_ms, 12.0 * $count, $count - $errors, 0, 0, $errors, $errors, $min, $max, 12.0, $last ];
	}

	/** The url-rows key of kea.test's reader traffic. */
	private static function readers(): string {
		return Stats_Store::url_rows_key( 'kea.test', false );
	}

	public function test_a_span_appends_once_to_each_ledger_it_holds_rows_for(): void {
		$store = $this->stats_store( 3 );

		$store->append_span(
			[
				Stats_Store::LEDGER_TOTALS => [ [ self::T, Stats_Store::SITE, '', [ 3, 111.0, 4, 48.0 ] ] ],
				Stats_Store::LEDGER_DIMS   => [ [ self::T, 'status', '2xx', [ 2, 74.0, 24.0, 2 ] ] ],
				Stats_Store::LEDGER_NAMES  => [],
			]
		);

		$this->assertSame( [ [ 'APPEND', [ [ self::T, Stats_Store::SITE, '', [ 3, 111.0, 4, 48.0 ] ] ] ] ], $this->ledger_asks( Stats_Store::LEDGER_TOTALS ) );
		$this->assertCount( 1, $this->ledger_asks( Stats_Store::LEDGER_DIMS ) );
		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_NAMES ), 'a Ledger with no rows is not asked' );
		$this->assertSame( [ 'count' => 3, 'sum_ms' => 111.0, 'requests' => 4, 'sum_peak_mb' => 48.0 ], $store->totals( self::FROM, self::TO, false ) );
	}

	public function test_two_partitions_append_to_one_ledger_and_a_read_sums_both(): void {
		$p3 = $this->stats_store( 3 );
		$p5 = $this->stats_store( 5 );

		$p3->append_span( [ Stats_Store::LEDGER_TOTALS => [ [ self::T, Stats_Store::SITE, '', [ 3, 111.0, 4, 48.0 ] ] ] ] );
		$p5->append_span( [ Stats_Store::LEDGER_TOTALS => [ [ self::T, Stats_Store::SITE, '', [ 2, 59.0, 2, 24.0 ] ], [ self::T - 300, Stats_Store::SITE, '', [ 1, 41.0, 1, 7.0 ] ] ] ] );

		$this->assertSame( [ 'count' => 6, 'sum_ms' => 211.0, 'requests' => 7, 'sum_peak_mb' => 79.0 ], $p3->totals( self::FROM, self::TO, false ) );
		$this->assertSame(
			[
				self::T - 300 => [ 'count' => 1, 'sum_ms' => 41.0, 'requests' => 1, 'sum_peak_mb' => 7.0 ],
				self::T       => [ 'count' => 5, 'sum_ms' => 170.0, 'requests' => 6, 'sum_peak_mb' => 72.0 ],
			],
			$p5->totals( self::FROM, self::TO, true ),
			'by bucket, oldest first'
		);
		$this->assertSame( [ 'count' => 5, 'sum_ms' => 170.0, 'requests' => 6, 'sum_peak_mb' => 72.0 ], $p3->totals( self::T, self::TO, false ), 'the window bounds the read' );
	}

	public function test_a_dimension_reads_per_bucket_and_scopes_to_a_server(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_DIMS => [
					[ self::T, 'status', '5xx', [ 1, 37.0, 12.0, 1 ] ],
					[ self::T, 'status', '2xx', [ 2, 74.0, 24.0, 2 ] ],
					[ self::T - 300, 'status', '2xx', [ 1, 29.0, 9.0, 1 ] ],
					[ self::T, Stats_Store::dim_key( 'status', 'kea.test' ), '2xx', [ 7, 31.0, 5.0, 6 ] ],
				],
			]
		);

		$this->assertEquals(
			[
				self::T - 300 => [ '2xx' => [ 1, 29.0, 9.0, 1 ] ],
				self::T       => [ '2xx' => [ 2, 74.0, 24.0, 2 ], '5xx' => [ 1, 37.0, 12.0, 1 ] ],
			],
			$store->dimension( 'status', '', self::FROM, self::TO, true )
		);
		$this->assertEquals( [ '2xx' => [ 7, 31.0, 5.0, 6 ] ], $store->dimension( 'status', 'kea.test', self::FROM, self::TO, false ) );
	}

	public function test_categories_read_per_bucket_for_the_site_or_one_server(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_CATEGORIES => [
					[ self::T, Stats_Store::SITE, 'wpdb', [ 41.5, 12, 3 ] ],
					[ self::T, Stats_Store::server_scope( 'kea.test' ), 'wpdb', [ 9.25, 2, 1 ] ],
				],
			]
		);

		$this->assertEquals( [ self::T => [ 'wpdb' => [ 41.5, 12, 3 ] ] ], $store->categories( '', self::FROM, self::TO, true ) );
		$this->assertEquals( [ 'wpdb' => [ 9.25, 2, 1 ] ], $store->categories( 'kea.test', self::FROM, self::TO, false ) );
	}

	public function test_the_leaderboard_divides_its_sums_for_display(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_LEADERBOARD => [
					[ self::T, Stats_Store::SITE, '', [ 4, 200.0, 0 ] ],
					[ self::T, Stats_Store::SITE, 'wpdb', [ 4, 80.0, 12 ] ],
					[ self::T, Stats_Store::SITE, Stats_Store::member( 'wpdb', 'SELECT kea' ), [ 2, 30.0, 6 ] ],
					[ self::T, Stats_Store::server_scope( 'kea.test' ), '', [ 1, 17.0, 0 ] ],
				],
			]
		);

		$board = $store->leaderboard( '', self::FROM, self::TO );
		$this->assertSame( 4, $board['count'] );
		$this->assertEqualsWithDelta( 50.0, $board['total_time'], 1e-9 );
		$this->assertEqualsWithDelta( 20.0, $board['categories']['wpdb']['time'], 1e-9 );
		$this->assertEqualsWithDelta( 3.0, $board['categories']['wpdb']['count'], 1e-9 );
		$this->assertSame( 4, $board['categories']['wpdb']['samples'] );
		$this->assertEquals( [ 15.0, 3.0, 2 ], $board['categories']['wpdb']['entries']['SELECT kea'], 'an entry is a mean over its samples' );
		$this->assertSame( 1, $store->leaderboard( 'kea.test', self::FROM, self::TO )['count'] );
	}

	public function test_a_url_page_ranks_a_scope_by_one_column(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_URL_ROWS => [
					[ self::T, self::readers(), self::SKU_41, self::url_row( 3, 3, 111.0, 1 ) ],
					[ self::T, self::readers(), self::SKU_43, self::url_row( 5, 4, 88.0 ) ],
					[ self::T, Stats_Store::url_rows_key( 'kea.test', true ), self::SKU_47, self::url_row( 9, 9, 900.0 ) ],
				],
			]
		);

		$page = $store->url_page( [ 'kea.test' ], false, 'count', 'desc', 50, 0, false, self::FROM, self::TO );
		$this->assertSame( 2, $page['total'] );
		$this->assertSame( [ self::SKU_43, self::SKU_41 ], \array_column( $page['rows'], 'url' ) );
		$this->assertSame( 5, $page['rows'][0]['count'] );
		$this->assertSame( 4, $page['rows'][0]['timed_count'] );
		$this->assertSame( 88.0, $page['rows'][0]['sum_ms'] );

		$errored = $store->url_page( [ 'kea.test' ], false, 'errors', 'desc', 50, 0, true, self::FROM, self::TO );
		$this->assertSame( [ self::SKU_41 ], \array_column( $errored['rows'], 'url' ), 'errors only keeps the URL that errored' );
		$this->assertSame( 1, $errored['total'] );

		$all = $store->url_page( [ 'kea.test' ], true, 'count', 'desc', 1, 1, false, self::FROM, self::TO );
		$this->assertSame( 3, $all['total'], 'workers join the scope' );
		$this->assertSame( [ self::SKU_43 ], \array_column( $all['rows'], 'url' ), 'paged by limit and offset' );
	}

	public function test_a_url_page_ranks_by_a_ratio_and_the_scope_totals_by_key(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_URL_ROWS => [
					[ self::T, self::readers(), self::SKU_41, self::url_row( 3, 3, 111.0, 1 ) ],
					[ self::T, self::readers(), self::SKU_43, self::url_row( 5, 4, 88.0 ) ],
					[ self::T, Stats_Store::url_rows_key( 'kea.test', true ), self::SKU_47, self::url_row( 9, 9, 900.0 ) ],
				],
			]
		);

		$page = $store->url_page( [ 'kea.test' ], false, [ 'sum_ms', 'timed_count' ], 'desc', 50, 0, false, self::FROM, self::TO );
		$this->assertSame( [ self::SKU_41, self::SKU_43 ], \array_column( $page['rows'], 'url' ), '37 ms a request ahead of 22' );

		$totals = $store->scope_totals( [ 'kea.test' ], false, false, self::FROM, self::TO );
		$this->assertSame( [ 8, 7, 199.0, 1 ], [ $totals['count'], $totals['timed_count'], $totals['sum_ms'], $totals['errors'] ] );
		$this->assertSame( [ [ 'SUM', 'k' ] ], \array_map( static fn ( array $ask ): array => [ $ask[0], $ask[1]['group'] ?? null ], \array_values( \array_filter( $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ), static fn ( array $ask ): bool => 'SUM' === $ask[0] ) ) ) );
	}

	/** Errors-only reads the buckets a URL errored in, and none it ran clean. */
	public function test_errored_rows_keep_only_the_buckets_each_url_errored_in(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_URL_ROWS => [
					[ self::T, self::readers(), self::SKU_41, self::url_row( 3, 3, 111.0, 1 ) ],
					[ self::T - 300, self::readers(), self::SKU_41, self::url_row( 40, 40, 1600.0 ) ],
					[ self::T - 300, self::readers(), self::SKU_43, self::url_row( 5, 4, 88.0 ) ],
				],
			]
		);

		$rows = $store->url_rows( [ 'kea.test' ], false, null, true, self::FROM, self::TO );
		$page = $store->url_page( [ 'kea.test' ], false, 'count', 'desc', 50, 0, true, self::FROM, self::TO );

		$this->assertSame( [ self::SKU_41 ], \array_keys( $rows ) );
		$this->assertSame( 3, $rows[ self::SKU_41 ]['count'], 'the clean bucket\'s 40 are not errored traffic' );
		$this->assertSame( [ [ self::SKU_41, 3 ] ], \array_map( static fn ( array $row ): array => [ $row['url'], $row['count'] ], $page['rows'] ) );
	}

	public function test_url_rows_merge_every_server_and_partition_a_url_was_filed_under(): void {
		$p3 = $this->stats_store( 3 );
		$p5 = $this->stats_store( 5 );
		$p3->append_span( [ Stats_Store::LEDGER_URL_ROWS => [ [ self::T, self::readers(), self::SKU_41, self::url_row( 3, 3, 111.0, 1, 29.0, 41.0 ) ] ] ] );
		$p5->append_span(
			[
				Stats_Store::LEDGER_URL_ROWS => [
					[ self::T - 300, Stats_Store::url_rows_key( 'wren.test', false ), self::SKU_41, self::url_row( 2, 0, 0.0, 0, null, null, self::T - 17 ) ],
					[ self::T, self::readers(), self::SKU_43, self::url_row( 1, 0, 0.0, 0, null, null ) ],
				],
			]
		);

		$rows = $p3->url_rows( [ 'kea.test', 'wren.test' ], false, [ self::SKU_41 ], false, self::FROM, self::TO );
		$this->assertSame( [ self::SKU_41 ], \array_keys( $rows ) );
		$this->assertSame( 5, $rows[ self::SKU_41 ]['count'] );
		$this->assertSame( 3, $rows[ self::SKU_41 ]['timed_count'] );
		$this->assertSame( 1, $rows[ self::SKU_41 ]['errors'] );
		$this->assertSame( 29.0, $rows[ self::SKU_41 ]['min_ms'], 'an untimed row lends no minimum' );
		$this->assertSame( 41.0, $rows[ self::SKU_41 ]['max_ms'] );
		$this->assertSame( self::T, $rows[ self::SKU_41 ]['last_seen'] );

		$every = $p5->url_rows( [ 'kea.test' ], false, null, false, self::FROM, self::TO );
		$this->assertSame( [ self::SKU_41, self::SKU_43 ], \array_keys( $every ), 'no URL named reads every URL of the scope' );
		$this->assertNull( $every[ self::SKU_43 ]['min_ms'], 'a URL nothing timed has no minimum' );
		$this->assertNull( $every[ self::SKU_43 ]['max_ms'], 'nor a maximum' );

		$ranks = [];
		foreach ( [ 'asc', 'desc' ] as $order ) {
			$ranks[ $order ] = \array_column( $p5->url_page( [ 'kea.test' ], false, 'min_ms', $order, 50, 0, false, self::FROM, self::TO )['rows'], 'url' );
		}
		$this->assertSame( [ 'asc' => [ self::SKU_41, self::SKU_43 ], 'desc' => [ self::SKU_41, self::SKU_43 ] ], $ranks, 'a URL never measured ranks last either way' );
	}

	public function test_a_urls_breakdown_and_categories_read_by_the_url(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_URL_DIMS => [
					[ self::T, Stats_Store::url_dim_key( 'status', self::SKU_41 ), '5xx', [ 1, 37.0, 12.0, 1 ] ],
					[ self::T, Stats_Store::url_dim_key( 'ua', self::SKU_41 ), "Kea\tBot 7", [ 3, 111.0, 36.0, 3 ] ],
					[ self::T, Stats_Store::url_dim_key( 'status', self::SKU_43 ), '2xx', [ 5, 88.0, 60.0, 4 ] ],
				],
				Stats_Store::LEDGER_URL_CATS => [
					[ self::T - 300, Stats_Store::url_key( self::SKU_41 ), 'wpdb', [ 41.5, 12, 3 ] ],
				],
			]
		);

		$this->assertEquals( [ self::T => [ '5xx' => [ 1, 37.0, 12.0, 1 ] ] ], $store->url_breakdown( self::SKU_41, 'status', self::FROM, self::TO ) );
		$this->assertEquals( [ self::T => [ "Kea\tBot 7" => [ 3, 111.0, 36.0, 3 ] ] ], $store->url_breakdown( self::SKU_41, 'ua', self::FROM, self::TO ), 'a value may hold a tab' );
		$reads = \array_column( $this->ledger_asks( Stats_Store::LEDGER_URL_DIMS ), 1 );
		$this->assertSame( [ [ Stats_Store::url_dim_key( 'status', self::SKU_41 ) ], [ Stats_Store::url_dim_key( 'ua', self::SKU_41 ) ] ], \array_column( \array_slice( $reads, 1 ), 'ks' ), 'one dimension is one key' );
		$this->assertEquals( [ self::T - 300 => [ 'wpdb' => [ 41.5, 12, 3 ] ] ], $store->url_categories( self::SKU_41, self::FROM, self::TO ) );
	}

	public function test_a_url_key_holds_no_whitespace_and_names_one_url(): void {
		$this->assertSame( 'https://kea.test/sku%2041%09x', Stats_Store::url_key( "https://kea.test/sku 41\tx" ) );
		$this->assertSame( 'ua:https://kea.test/sku%2041', Stats_Store::url_dim_key( 'ua', 'https://kea.test/sku 41' ) );
		$this->assertSame( self::SKU_41, Stats_Store::url_key( self::SKU_41 ) );
	}

	public function test_the_names_ledger_answers_a_hash_and_the_servers_that_filed_rows(): void {
		$store = $this->stats_store( 3 );
		$store->append_span(
			[
				Stats_Store::LEDGER_NAMES => [
					[ self::T, Stats_Store::hash_key( Log_Manager::url_hash( self::SKU_41 ) ), self::SKU_41, [] ],
					[ self::T, Stats_Store::servers_key( false ), 'kea.test', [] ],
					[ self::T, Stats_Store::servers_key( true ), 'wren.test', [] ],
				],
			]
		);

		$this->assertSame( self::SKU_41, $store->url_of( Log_Manager::url_hash( self::SKU_41 ), self::FROM, self::TO ) );
		$this->assertNull( $store->url_of( 'c0ffee7731ab', self::FROM, self::TO ) );
		$this->assertSame( [ 'kea.test' ], $store->servers( false, self::FROM, self::TO ) );
		$this->assertSame( [ 'kea.test', 'wren.test' ], $store->servers( true, self::FROM, self::TO ) );
	}

	public function test_a_read_a_ledger_refuses_answers_empty_and_says_so(): void {
		$store = $this->stats_store( 3 );
		$store->append_span( [ Stats_Store::LEDGER_TOTALS => [ [ self::T, Stats_Store::SITE, '', [ 3, 111.0, 4, 48.0 ] ] ] ] );
		$this->assertFalse( $store->unanswered() );

		$this->refuse_stats_reads( '/^stats:totals/' );

		$this->assertSame( [ 'count' => 0, 'sum_ms' => 0.0, 'requests' => 0, 'sum_peak_mb' => 0.0 ], $store->totals( self::FROM, self::TO, false ) );
		$this->assertTrue( $store->unanswered() );
	}

	public function test_url_stats_sums_every_partitions_blob_and_hands_out_means(): void {
		$p3 = $this->stats_store( 3 );
		$p5 = $this->stats_store( 5 );
		$p3->set_url_aggregates( [ 'c0ffee7731ab' => [ 'last_modified' => self::T - 29, 'profiles' => [ 'count' => 3, 'sum_req_time' => 150.0, 'categories' => [ 'wpdb' => [ 'samples' => 3, 'sum_time' => 60.0, 'sum_count' => 9, 'entries' => [] ] ] ] ] ] );
		$p5->set_url_aggregates( [ 'c0ffee7731ab' => [ 'last_modified' => self::T - 17, 'profiles' => [ 'count' => 1, 'sum_req_time' => 50.0, 'categories' => [ 'wpdb' => [ 'samples' => 1, 'sum_time' => 20.0, 'sum_count' => 3, 'entries' => [] ] ] ] ] ] );
		[ $client, $ledgers, $url_p3 ] = $this->stats_store_args( 3 );
		$reader                        = new Stats_Store( $client, $ledgers, [ ...$url_p3, ...$this->stats_store_args( 5 )[2] ] );

		$stats = $reader->url_stats( 'c0ffee7731ab' );
		$this->assertSame( self::T - 17, $stats['last_modified'] );
		$this->assertSame( 4, $stats['profiles']['count'] );
		$this->assertEqualsWithDelta( 50.0, $stats['profiles']['total_time'], 1e-9 );
		$this->assertEqualsWithDelta( 20.0, $stats['profiles']['categories']['wpdb']['time'], 1e-9 );
		$this->assertNull( $reader->url_stats( 'badc0ffee123' ) );
		$this->assertSame( self::T - 29, $p3->url_aggregate( 'c0ffee7731ab' )['last_modified'], 'a writer reads its own partition' );
	}

	public function test_the_ledger_columns_are_the_positional_constants_named(): void {
		$this->assertSame( \range( 0, 12 ), \array_keys( Stats_Store::ROW_FIELD_NAMES ) );
		$this->assertSame( \range( 0, 8 ), \array_keys( Stats_Store::ROW_SUMS ) );
		$this->assertSame( \range( 0, 2 ), \array_keys( Stats_Store::CAT_SUMS ) );
		$this->assertSame( \range( 0, 3 ), \array_keys( Stats_Store::DIM_SUMS ) );
		$this->assertSame( [ 'count', 'timed_count', 'sum_ms', 'sum_peak_mb', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'errors', 'min_ms:min', 'max_ms:max', 'max_peak_mb:max', 'last_seen:max' ], Stats_Store::LEDGER_COLUMNS[ Stats_Store::LEDGER_URL_ROWS ] );
	}

	public function test_server_key_is_the_fnv1a32_every_stored_key_carries(): void {
		$this->assertSame( '41a5c9d3', Stats_Store::server_key( 'srv-a.example.com' ) );
		$this->assertSame( '627292da', Stats_Store::server_key( 'spray042.probe.test' ) );
		$this->assertSame( 'site', Stats_Store::server_scope( '' ) );
		$this->assertSame( 'srv:41a5c9d3', Stats_Store::server_scope( 'srv-a.example.com' ) );
		$this->assertSame( 'status:41a5c9d3', Stats_Store::dim_key( 'status', 'srv-a.example.com' ) );
		$this->assertSame( 'r:41a5c9d3', Stats_Store::url_rows_key( 'srv-a.example.com', false ) );
		$this->assertSame( 'w:41a5c9d3', Stats_Store::url_rows_key( 'srv-a.example.com', true ) );
	}

	public function test_bucket_key_floors_to_the_bucket_width(): void {
		$this->assertSame( '2026-09-21-14-20', Stats_Store::bucket_key( self::T + 299 ) );
		$this->assertSame( self::T, Stats_Store::bucket_start( self::T + 299 ) );
	}

	public function test_sums_to_display_converts_running_sums_to_avg(): void {
		$display = Stats_Store::sums_to_display( 4, 4.0, [ 'wpdb' => [ 'samples' => 4, 'sum_time' => 1.6, 'sum_count' => 16, 'entries' => [ 'SELECT' => [ 1.2, 12, 4 ] ] ] ] );
		$this->assertSame( 4, $display['count'] );
		$this->assertEqualsWithDelta( 1.0, $display['total_time'], 1e-9 );
		$this->assertEqualsWithDelta( 0.4, $display['categories']['wpdb']['time'], 1e-9 );
		$this->assertEqualsWithDelta( 4.0, $display['categories']['wpdb']['count'], 1e-9 );
		$this->assertEqualsWithDelta( 0.3, $display['categories']['wpdb']['entries']['SELECT'][0], 1e-9 );
		$this->assertEqualsWithDelta( 3.0, $display['categories']['wpdb']['entries']['SELECT'][1], 1e-9 );
	}

	public function test_sums_to_display_skips_entries_with_zero_samples(): void {
		$display = Stats_Store::sums_to_display( 2, 2.0, [ 'wpdb' => [ 'samples' => 2, 'sum_time' => '1.0', 'sum_count' => null, 'entries' => [ 'ZERO' => [ 5.0, 5.0, 0 ] ] ] ] );
		$this->assertEqualsWithDelta( 0.5, $display['categories']['wpdb']['time'], 1e-9 );
		$this->assertSame( [], $display['categories']['wpdb']['entries'] );
	}

	public function test_merge_leaderboard_bucket_coerces_missing_and_non_numeric_to_zero(): void {
		$dst = [];
		Stats_Store::merge_leaderboard_bucket( $dst, [ 'count' => 'x', 'categories' => [ 'wpdb' => [ 'samples' => [ 'nope' ], 'sum_time' => '0.5', 'entries' => [ 'SELECT' => [ 'x', 2, null ] ] ] ] ] );
		$this->assertSame( 0, $dst['count'] );
		$this->assertEqualsWithDelta( 0.5, $dst['categories']['wpdb']['sum_time'], 1e-9 );
		$this->assertSame( [ 0.0, 2.0, 0 ], $dst['categories']['wpdb']['entries']['SELECT'] );
	}

	public function test_term_tokens_are_lowercase_alphanumeric_runs_of_two_or_more(): void {
		$this->assertSame( [ 'wombat', '7731', 'kea', 'json' ], Stats_Store::term_tokens( '/Wombat-7731/kea/a/?json=1' ) );
		$this->assertSame( [ 'internationa' ], Stats_Store::term_tokens( 'Internationalization' ) );
		$this->assertSame( [], Stats_Store::term_tokens( '-/-' ) );
	}

	public function test_term_matches_reads_a_term_as_whole_words(): void {
		$path = '/kakapo/nest-9317';
		$this->assertTrue( Stats_Store::term_matches( '/KAKAPO/nest-9317', 'kakapo', [ 'kakapo' ] ) );
		$this->assertTrue( Stats_Store::term_matches( $path, 'kakapo nest', [ 'kakapo', 'nest' ] ) );
		$this->assertFalse( Stats_Store::term_matches( $path, 'kakapo weka', [ 'kakapo', 'weka' ] ) );
		$this->assertFalse( Stats_Store::term_matches( $path, 'kaka', [ 'kaka' ] ), 'a word prefix never matches' );
		$this->assertTrue( Stats_Store::term_matches( $path, 'o/n', [] ), 'a term with no word is a substring' );
	}

	public function test_a_urls_path_drops_its_scheme_and_host_alone(): void {
		$this->assertSame( '/sku-41?q=9', Stats_Store::path_of( 'https://kea.test/sku-41?q=9' ) );
		$this->assertSame( '?q=3', Stats_Store::path_of( 'https://kea.test?q=3' ) );
		$this->assertSame( '/bare-7731', Stats_Store::path_of( '/bare-7731' ) );
	}

	public function test_the_estimate_follows_the_serializer_the_handle_is_configured_with(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY );
		$igbinary = Stats_Store::overhead( 'flame_node' );
		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP );
		$php = Stats_Store::overhead( 'flame_node' );
		$this->assertGreaterThan( $igbinary, $php );
		Core::$memd = null;
		$this->assertSame( $php, Stats_Store::overhead( 'flame_node' ), 'no handle estimates the larger' );
	}

	public function test_an_unknown_part_has_no_estimate(): void {
		$this->expectException( \LogicException::class );
		Stats_Store::overhead( 'url_row' );
	}
}
