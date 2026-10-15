<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Stats_Query;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;

/**
 * The query vocabulary over the `flame-facts` rows: a spec of source,
 * groups, filters, measures and buckets becomes one statement over every
 * partition's file, read from the cheapest table that answers it exactly.
 */
#[CoversClass( Stats_Query::class )]
#[CoversClass( Performance_CI_Node::class )]
final class StatsQueryTest extends TestCase {

	use FactsSeeder;

	/** The pinned tick: its bucket opens an hour, so the last 288 do not. */
	private const NOW = 1_790_003_000;

	protected function setUp(): void {
		parent::setUp();
		Core::$clock = static fn (): float => (float) self::NOW;
		Core::right_now();
		$GLOBALS['_current_user_can'] = true;
		$this->activate_shipped( 'flame-builder', 4 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_current_user_can'] );
		parent::tearDown();
	}

	/** The 288 buckets ending at the tick's. */
	private function last_day(): array {
		$now = Bucket::id( self::NOW );
		return [ [ $now - 287, $now ] ];
	}

	/** The 24 whole hours ending with the tick's. */
	private function last_hours(): array {
		$hour = Bucket::hour( self::NOW );
		return [ [ ( $hour - 23 ) * 12, $hour * 12 + 11 ] ];
	}

	private static function spec( array $over ): array {
		return $over + [ 'group' => [], 'filter' => [], 'measure' => [ 'count' ] ];
	}

	public function test_five_xx_by_server_answers_one_row_per_server(): void {
		$this->seed_request( [ 'server' => 'kea.example', 'status' => 503 ] );
		$this->seed_request( [ 'server' => 'egret.example', 'status' => 502 ], partition: 3 );
		$this->seed_request( [ 'server' => 'kea.example', 'status' => 200 ] );

		$r = Stats_Query::run( [ 'source' => 'requests', 'group' => [ 'server' ], 'filter' => [ 'status_class' => [ 5 ] ], 'measure' => [ 'count' ], 'buckets' => $this->last_day(), 'sort' => 'server' ] );

		$this->assertSame( [ 'server', 'count' ], $r['columns'] );
		$this->assertSame( [ [ 'egret.example', 1 ], [ 'kea.example', 1 ] ], $r['rows'] );
		$this->assertSame( 'requests', $r['source'] );
		$this->assertSame( 'requests', $r['table'] );
	}

	public function test_a_missing_status_is_unknown_in_a_status_breakdown(): void {
		$this->seed_request( [ 'status' => null ] );
		$this->seed_request( [ 'status' => 999 ], partition: 1 );
		$this->seed_request( [ 'status' => 404 ] );
		$this->seed_request( [ 'status' => 404 ], partition: 3 );

		$by_status = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'status' ], 'buckets' => $this->last_day() ] ) );
		$by_class  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'status_class' ], 'buckets' => $this->last_day() ] ) );
		$total     = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 404, 2 ], [ 'Unknown', 2 ] ], $by_status['rows'] );
		$this->assertSame( [ [ 4, 2 ], [ 'Unknown', 2 ] ], $by_class['rows'] );
		$this->assertSame( [ [ 4 ] ], $total['rows'], 'every request counts in the total' );
	}

	public function test_a_filter_on_unknown_matches_what_a_group_answers_unknown(): void {
		$this->seed_request( [ 'country' => null, 'ms' => 61.0 ] );
		$this->seed_request( [ 'country' => 'NZ', 'ms' => 17.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'country' => [ 'Unknown' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 61.0 ] ], $r['rows'] );
	}

	public function test_an_absent_country_agent_ja4_and_from_are_unknown_and_no_error_is_a_dash(): void {
		$this->seed_request( [ 'error' => 'T', 'ms' => null ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'country', 'ua', 'ja4', 'from', 'error' ], 'buckets' => $this->last_day() ] ) );
		$this->seed_request( [ 'country' => 'AU', 'ua' => 'Kakapo/2.0', 'ja4' => 't13d1517h2', 'from_header' => 'bot@kea.example' ], partition: 3 );
		$named = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'country', 'ua', 'ja4', 'from', 'error' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'T', 1 ] ], $r['rows'] );
		$this->assertSame( [ [ 'AU', 'Kakapo/2.0', 't13d1517h2', 'bot@kea.example', '-', 1 ], [ 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'T', 1 ] ], $named['rows'] );
	}

	public function test_hourly_fields_read_requests_hourly(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'country' => '', 'count' => 7, 'timed' => 7, 'sum_ms' => 70.0, 'status_class' => 0 ] );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'country' => 'NZ', 'count' => 5, 'timed' => 4, 'sum_ms' => 1000.0, 'status_class' => 5 ], partition: 3 );
		$this->seed_hourly( [ 'hour' => $hour - 30, 'country' => 'NZ', 'count' => 11 ] );
		$this->seed_request( [ 'country' => 'NZ' ] );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'country', 'status_class' ], 'measure' => [ 'count', 'avg_ms' ], 'buckets' => $this->last_hours() ] );

		$r = Stats_Query::run( $spec );

		$this->assertSame( 'requests_hourly', Stats_Query::table_for( $spec ) );
		$this->assertSame( 'requests_hourly', $r['table'] );
		$this->assertSame( [ [ 'NZ', 5, 5, 250.0 ], [ 'Unknown', 'Unknown', 7, 10.0 ] ], $r['rows'], 'a rollup row, never the raw request, and an hour outside the range left out' );
	}

	public function test_a_range_not_on_whole_hours_reads_requests(): void {
		$hour = Bucket::hour( self::NOW );

		foreach ( [ $this->last_day(), [ [ ( $hour - 1 ) * 12, $hour * 12 ] ], [ [ ( $hour - 1 ) * 12, $hour * 12 - 1 ], [ $hour * 12 + 1, $hour * 12 + 11 ] ] ] as $buckets ) {
			$this->assertSame( 'requests', Stats_Query::table_for( self::spec( [ 'source' => 'requests', 'group' => [ 'country' ], 'buckets' => $buckets ] ) ), \wp_json_encode( $buckets ) );
		}
	}

	public function test_a_measure_the_rollup_lacks_reads_requests(): void {
		foreach ( [ 'min_ms', 'p50_ms', 'max_peak_mb', 'urls', 'last_seen' ] as $measure ) {
			$this->assertSame( 'requests', Stats_Query::table_for( self::spec( [ 'source' => 'requests', 'measure' => [ $measure ], 'buckets' => $this->last_hours() ] ) ), $measure );
		}
	}

	public function test_a_ua_group_reads_requests(): void {
		$this->seed_request( [ 'ua' => 'Takahe/4.2' ] );
		$this->seed_request( [ 'ua' => 'Takahe/4.2' ], partition: 3 );
		$this->seed_request();
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'ua' ], 'buckets' => $this->last_hours() ] );

		$r = Stats_Query::run( $spec );

		$this->assertSame( 'requests', Stats_Query::table_for( $spec ) );
		$this->assertSame( [ [ 'Takahe/4.2', 2 ], [ 'Unknown', 1 ] ], $r['rows'] );
	}

	public function test_avg_ms_is_request_weighted(): void {
		$now  = Bucket::id( self::NOW );
		$hour = Bucket::hour( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 1, 'ms' => 100.0 ] );
		foreach ( [ 0, 1, 3 ] as $partition ) {
			$this->seed_request( [ 'bucket' => $now - 2, 'ms' => 300.0 ], partition: $partition );
		}
		$this->seed_hourly( [ 'hour' => $hour - 1, 'count' => 1, 'timed' => 1, 'sum_ms' => 100.0 ] );
		$this->seed_hourly( [ 'hour' => $hour - 3, 'count' => 3, 'timed' => 3, 'sum_ms' => 900.0 ], partition: 3 );

		$raw    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'avg_ms' ], 'buckets' => $this->last_day() ] ) );
		$hourly = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'avg_ms' ], 'buckets' => $this->last_hours() ] ) );

		$this->assertSame( [ [ 250.0 ] ], $raw['rows'] );
		$this->assertSame( 'requests_hourly', $hourly['table'] );
		$this->assertSame( [ [ 250.0 ] ], $hourly['rows'] );
	}

	public function test_the_request_measures(): void {
		$this->seed_request( [ 'ms' => 40.0, 'peak_mb' => 12.0, 'error' => 'F', 'url' => 'https://seed.example/weka/', 'finished_at' => self::NOW - 90.5 ] );
		$this->seed_request( [ 'ms' => null, 'peak_mb' => 30.0, 'error' => 'T', 'url' => 'https://seed.example/weka/', 'finished_at' => self::NOW - 20.25 ], partition: 3 );
		$this->seed_request( [ 'ms' => 80.0, 'peak_mb' => 18.0, 'error' => 'A', 'url' => 'https://seed.example/tui/', 'finished_at' => self::NOW - 50.0 ], partition: 3 );
		$measures = [ 'count', 'timed', 'sum_ms', 'avg_ms', 'max_ms', 'min_ms', 'sum_peak_mb', 'avg_peak_mb', 'max_peak_mb', 'errors', 'urls', 'last_seen' ];

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => $measures, 'buckets' => $this->last_day() ] ) );

		$this->assertSame( $measures, $r['columns'] );
		$this->assertSame( [ [ 3, 2, 120.0, 60.0, 80.0, 40.0, 60.0, 20.0, 30.0, 2, 2, (float) self::NOW - 20.25 ] ], $r['rows'], 'an abort is no error, and the URL seen twice in two partitions counts once' );
	}

	public function test_status_class_counts_and_worker_measure_each_group(): void {
		foreach ( [ 201, 204, 404, 503, 599, null, 700 ] as $i => $status ) {
			$this->seed_request( [ 'server' => 'kea.example', 'status' => $status ], partition: $i % 4 );
		}
		$this->seed_request( [ 'server' => 'kea.example', 'status' => 302, 'worker' => 1 ], partition: 2 );
		$this->seed_request( [ 'server' => 'egret.example', 'status' => 200 ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'count', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'worker' ], 'sort' => 'server', 'buckets' => $this->last_day() ] ) );

		$this->assertSame( 'requests', $r['table'] );
		$this->assertSame(
			[ [ 'egret.example', 1, 1, 0, 0, 0, 0 ], [ 'kea.example', 8, 2, 1, 1, 2, 1 ] ],
			$r['rows'],
			'a missing status and one past the bounds count in no class'
		);
	}

	public function test_a_name_both_grouped_and_measured_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'worker names both a group and a measure' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'worker' ], 'measure' => [ 'worker' ], 'buckets' => $this->last_day() ] ) );
	}

	public function test_an_untimed_group_answers_null_means_in_either_table(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_request( [ 'ms' => null, 'error' => 'T' ] );
		$this->seed_hourly( [ 'hour' => $hour, 'count' => 2, 'timed' => 0, 'sum_ms' => 0.0, 'max_ms' => 0.0, 'errors' => 2 ] );
		$measures = [ 'count', 'timed', 'sum_ms', 'avg_ms', 'max_ms', 'errors' ];

		$raw    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => $measures, 'buckets' => $this->last_day() ] ) );
		$hourly = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => $measures, 'buckets' => $this->last_hours() ] ) );

		$this->assertSame( [ [ 1, 0, 0.0, null, null, 1 ] ], $raw['rows'] );
		$this->assertSame( [ [ 2, 0, 0.0, null, null, 2 ] ], $hourly['rows'] );
	}

	public function test_a_url_groups_by_hash_across_partitions_whose_ids_differ(): void {
		$this->seed_request( [ 'url' => 'https://kea.example/egret-1/', 'server' => 'kea.example' ] );
		$this->seed_request( [ 'url' => 'https://kea.example/tui-2/', 'server' => 'kea.example' ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://kea.example/egret-1/', 'server' => 'kea.example' ], partition: 3 );
		$this->seed_request( [ 'url' => 'http://kea.example/plain', 'server' => 'kea.example' ], partition: 3 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'buckets' => $this->last_day() ] ) );

		$expected = [
			[ Log_Manager::url_hash( 'https://kea.example/egret-1/' ), 'https://kea.example/egret-1/', 2 ],
			[ Log_Manager::url_hash( 'https://kea.example/tui-2/' ), 'https://kea.example/tui-2/', 1 ],
			[ Log_Manager::url_hash( 'http://kea.example/plain' ), 'http://kea.example/plain', 1 ],
		];
		\usort( $expected, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );
		$this->assertSame( [ 'url', 'url_name', 'count' ], $r['columns'] );
		$this->assertSame( $expected, $r['rows'], 'url_id 1 is egret in partition 0 and tui in partition 3; rows order by hash' );
	}

	public function test_a_url_name_sort_orders_by_the_displayed_url(): void {
		$urls = [ 'https://kea.example/weka/', 'https://kea.example/aaa-tui/', 'http://egret.example/moa' ];
		foreach ( $urls as $i => $url ) {
			$this->seed_request( [ 'url' => $url, 'server' => \parse_url( $url, \PHP_URL_HOST ) ], partition: $i );
		}
		$by_hash = $urls;
		\usort( $by_hash, static fn ( string $a, string $b ): int => Log_Manager::url_hash( $a ) <=> Log_Manager::url_hash( $b ) );
		$named = [ 'http://egret.example/moa', 'https://kea.example/aaa-tui/', 'https://kea.example/weka/' ];
		$this->assertNotSame( $named, $by_hash, 'the fixture is void unless hash order differs from name order' );

		$asc  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'sort' => 'url_name', 'order' => 'asc', 'buckets' => $this->last_day() ] ) );
		$desc = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'sort' => 'url_name', 'order' => 'desc', 'buckets' => $this->last_day() ] ) );

		$this->assertSame( $named, \array_column( $asc['rows'], 1 ) );
		$this->assertSame( \array_reverse( $named ), \array_column( $desc['rows'], 1 ) );
	}

	public function test_a_url_name_sort_needs_a_url_group(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'url_name' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'url_name', 'buckets' => $this->last_day() ] ) );
	}

	public function test_profiled_groups_and_filters_requests(): void {
		$this->seed_request( [ 'profiled' => 1, 'ms' => 61.5 ] );
		$this->seed_request( [ 'profiled' => 1, 'ms' => 38.5 ], partition: 2 );
		$this->seed_request( [ 'profiled' => 0, 'ms' => 900.0 ] );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'profiled' ], 'measure' => [ 'count', 'sum_ms' ], 'buckets' => $this->last_hours() ] );

		$this->assertSame( 'requests', Stats_Query::table_for( $spec ), 'the hourly rollup keeps no profiled' );
		$this->assertSame( [ [ 0, 1, 900.0 ], [ 1, 2, 100.0 ] ], Stats_Query::run( $spec )['rows'] );
		$this->assertSame( [ [ 2 ] ], Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'profiled' => [ '1' ] ], 'buckets' => $this->last_day() ] ) )['rows'] );
	}

	public function test_a_url_filter_takes_its_hash(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kokako/', 'ms' => 33.0 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/hihi/', 'ms' => 900.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'url' => [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( 'https://seed.example/kokako/' ) ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 33.0 ] ], $r['rows'] );
	}

	public function test_a_url_filter_reads_timings_not_timings_site(): void {
		$url = 'https://seed.example/kiwi-7/';
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'ms' => 42.5, 'calls' => 6, 'requests' => 2 ], partition: 3 );
		$this->seed_timing( [ 'url' => 'https://seed.example/other/', 'category' => 'sql', 'ms' => 7.0 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'ms' => 9999.0, 'calls' => 77, 'requests' => 50 ] );
		$by_url = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'url' => [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( $url ) ] ], 'measure' => [ 'ms', 'calls', 'requests', 'avg_ms', 'avg_calls' ], 'buckets' => $this->last_day() ] );
		$site   = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_day() ] );

		$this->assertSame( 'timings', Stats_Query::table_for( $by_url ) );
		$this->assertSame( 'timings_site', Stats_Query::table_for( $site ) );
		$this->assertSame( [ [ 'sql', 42.5, 6, 2, 21.25, 3.0 ] ], Stats_Query::run( $by_url )['rows'] );
		$this->assertSame( [ [ 'sql', 9999.0 ] ], Stats_Query::run( $site )['rows'] );
	}

	public function test_site_timings_group_by_worker_entry_and_listener(): void {
		$this->seed_site_timing( [ 'category' => 'init', 'entry' => '', 'worker' => 1, 'listener' => 0, 'ms' => 12.0 ] );
		$this->seed_site_timing( [ 'category' => 'init', 'entry' => 'Kea::boot', 'worker' => 0, 'listener' => 1, 'ms' => 4.5 ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'worker', 'entry', 'listener' ], 'filter' => [ 'category' => [ 'init' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 0, 'Kea::boot', 1, 4.5 ], [ 1, '', 0, 12.0 ] ], $r['rows'] );
	}

	public function test_timings_by_url_hold_no_worker(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'worker' );

		Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'url', 'worker' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_day() ] ) );
	}

	public function test_hour_and_day_group_buckets_by_their_hour_and_utc_day(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 1 ] );
		$this->seed_request( [ 'bucket' => $now ] );
		$this->seed_site_timing( [ 'bucket' => $now - 1, 'ms' => 2.0 ] );
		$this->seed_site_timing( [ 'bucket' => $now, 'ms' => 3.0 ] );

		$requests = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'day', 'hour' ], 'buckets' => $this->last_day() ] ) );
		$timings  = Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'hour' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_day() ] ) );

		$hour = Bucket::hour( self::NOW );
		$day  = \intdiv( self::NOW, 86400 );
		$this->assertSame( [ [ $day, $hour - 1, 1 ], [ $day, $hour, 1 ] ], $requests['rows'] );
		$this->assertSame( [ [ $hour - 1, 2.0 ], [ $hour, 3.0 ] ], $timings['rows'] );
	}

	public function test_a_search_term_with_fts_syntax_matches_words_only(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kea-or-egret/' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-or-egrets/' ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-egret/' ], partition: 3 );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'search' => 'kea OR "egret*', 'buckets' => $this->last_day() ] );

		$r = Stats_Query::run( $spec );

		$this->assertSame( 'requests', Stats_Query::table_for( $spec ) );
		$this->assertSame( [ [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( 'https://seed.example/kea-or-egret/' ), 'https://seed.example/kea-or-egret/', 1 ] ], $r['rows'], 'OR is a word, and a quote or a star no operator' );
	}

	public function test_a_search_narrows_a_measure_with_no_url_group(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/hoiho-41/', 'ms' => 12.0 ], partition: 2 );
		$this->seed_request( [ 'url' => 'https://seed.example/pateke/', 'ms' => 500.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'search' => 'HOIHO', 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 12.0 ] ], $r['rows'] );
	}

	public function test_a_search_reads_a_urls_timings(): void {
		$this->seed_timing( [ 'url' => 'https://seed.example/korimako/', 'ms' => 8.25 ] );
		$this->seed_site_timing( [ 'ms' => 600.0 ] );
		$spec = self::spec( [ 'source' => 'timings', 'search' => 'korimako', 'measure' => [ 'ms' ], 'buckets' => $this->last_day() ] );

		$this->assertSame( 'timings', Stats_Query::table_for( $spec ) );
		$this->assertSame( [ [ 8.25 ] ], Stats_Query::run( $spec )['rows'] );
	}

	public function test_a_search_with_no_word_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no word' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'search' => '"* - "', 'buckets' => $this->last_day() ] ) );
	}

	public function test_percentiles_rank_each_groups_timed_requests(): void {
		foreach ( [ 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 1000.0 ] as $i => $ms ) {
			$this->seed_request( [ 'server' => 'kea.example', 'ms' => $ms ], partition: $i % 4 );
		}
		$this->seed_request( [ 'server' => 'kea.example', 'ms' => null, 'error' => 'T' ] );
		$this->seed_request( [ 'server' => 'tui.example', 'ms' => 7.5 ], partition: 1 );
		$this->seed_request( [ 'server' => 'tui.example', 'ms' => null ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'count', 'p50_ms', 'p95_ms' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 'kea.example', 11, 50.0, 1000.0 ], [ 'tui.example', 2, 7.5, 7.5 ] ], $r['rows'], 'nearest rank over the timed alone' );
	}

	public function test_a_percentile_with_no_group_ranks_every_row_and_none_timed_is_null(): void {
		$this->seed_request( [ 'ms' => null ] );
		$untimed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'p50_ms' ], 'buckets' => $this->last_day() ] ) );
		$this->seed_request( [ 'ms' => 3.0 ], partition: 3 );
		$this->seed_request( [ 'ms' => 9.0 ], partition: 1 );
		$timed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'p50_ms', 'p95_ms' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ null ] ], $untimed['rows'] );
		$this->assertSame( [ [ 3.0, 9.0 ] ], $timed['rows'] );
	}

	public function test_sort_order_limit_and_offset_page_the_rows(): void {
		foreach ( [ 'a.example' => 1, 'b.example' => 4, 'c.example' => 2, 'd.example' => 3 ] as $server => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->seed_request( [ 'server' => $server ], partition: $i % 2 * 3 );
			}
		}

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'count', 'order' => 'desc', 'limit' => 2, 'offset' => 1, 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 'd.example', 3 ], [ 'c.example', 2 ] ], $r['rows'] );
	}

	public function test_a_sorted_measure_ranks_null_last_in_either_order(): void {
		$this->seed_request( [ 'server' => 'kea.example', 'ms' => 40.0 ] );
		$this->seed_request( [ 'server' => 'egret.example', 'ms' => null, 'error' => 'T' ], partition: 1 );
		$this->seed_request( [ 'server' => 'weka.example', 'ms' => 90.0 ], partition: 2 );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'avg_ms' ], 'sort' => 'avg_ms', 'buckets' => $this->last_day() ] );

		$this->assertSame( [ 'kea.example', 'weka.example', 'egret.example' ], \array_column( Stats_Query::run( $spec + [ 'order' => 'asc' ] )['rows'], 0 ) );
		$this->assertSame( [ 'weka.example', 'kea.example', 'egret.example' ], \array_column( Stats_Query::run( $spec + [ 'order' => 'desc' ] )['rows'], 0 ) );
	}

	public function test_with_no_sort_rows_order_by_their_groups(): void {
		$this->seed_request( [ 'server' => 'z.example', 'method' => 'GET' ] );
		$this->seed_request( [ 'server' => 'a.example', 'method' => 'POST' ], partition: 3 );
		$this->seed_request( [ 'server' => 'a.example', 'method' => 'GET' ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server', 'method' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 'a.example', 'GET', 1 ], [ 'a.example', 'POST', 1 ], [ 'z.example', 'GET', 1 ] ], $r['rows'] );
	}

	public function test_a_filter_takes_several_values_and_digits_as_integers(): void {
		$this->seed_request( [ 'status' => 301, 'worker' => 1 ] );
		$this->seed_request( [ 'status' => 404 ], partition: 3 );
		$this->seed_request( [ 'status' => 200 ], partition: 3 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'status' => [ '301', '404' ], 'worker' => [ '0', '1' ] ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 2 ] ], $r['rows'] );
	}

	/**
	 * The `performance` CI's answer to `query` and its tokens, through the
	 * JSON wire, which carries a whole float as an integer.
	 */
	private static function query( string ...$tokens ): mixed {
		return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', $tokens );
	}

	public function test_the_query_verb_spells_buckets_as_keys_and_echoes_its_filters(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 6, 'status' => 503 ] );
		$this->seed_request( [ 'bucket' => $now - 5, 'status' => 204 ], partition: 3 );
		$this->seed_request( [ 'bucket' => $now - 5, 'status' => 302 ], partition: 3 );
		$this->seed_request( [ 'bucket' => $now - 9, 'status' => 204 ] );
		$selection = Bucket::key( $now - 6 ) . '..' . Bucket::key( $now - 5 );

		$reply = self::query( 'requests', 'bucket', '--measure=count', '--measure=max_ms', '--filter=status_class=2', '--filter=status_class=5', "--bucket={$selection}" );

		$this->assertSame(
			[
				'source'  => 'requests',
				'table'   => 'requests',
				'columns' => [ 'bucket', 'count', 'max_ms' ],
				'rows'    => [ [ Bucket::key( $now - 6 ), 1, 100 ], [ Bucket::key( $now - 5 ), 1, 100 ] ],
				'filters' => [
					'filter' => [ 'status_class' => [ '2', '5' ] ],
					'bucket' => $selection,
					'search' => '',
				],
			],
			$reply
		);
	}

	public function test_the_query_verb_reads_the_last_288_buckets_by_default(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 288, 'ms' => 1.0 ] );
		$this->seed_request( [ 'bucket' => $now - 287, 'ms' => 2.0 ], partition: 3 );
		$this->seed_request( [ 'bucket' => $now, 'ms' => 4.0 ], partition: 1 );

		$reply = self::query( 'requests', '--measure=sum_ms' );

		$this->assertSame( [ [ 6 ] ], $reply['rows'] );
		$this->assertSame( Bucket::key( $now - 287 ) . '..' . Bucket::key( $now ), $reply['filters']['bucket'] );
	}

	public function test_the_query_verb_groups_positionally_and_pages(): void {
		foreach ( [ 'kea.example', 'kea.example', 'tui.example', 'weka.example', 'weka.example', 'weka.example' ] as $i => $server ) {
			$this->seed_request( [ 'server' => $server ], partition: $i % 4 );
		}

		$reply = self::query( 'requests', 'server', '--measure=count', '--sort=count', '--order=desc', '--limit=2', '--offset=1', '--search=seed' );

		$this->assertSame( [ [ 'kea.example', 2 ], [ 'tui.example', 1 ] ], $reply['rows'] );
		$this->assertSame( 'seed', $reply['filters']['search'] );
	}

	public function test_a_bucket_filter_takes_keys(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 2, 'ms' => 30.0 ] );
		$this->seed_request( [ 'bucket' => $now - 1, 'ms' => 70.0 ], partition: 3 );

		$reply = self::query( 'requests', '--measure=sum_ms', '--filter=bucket=' . Bucket::key( $now - 1 ) );

		$this->assertSame( [ [ 70 ] ], $reply['rows'] );
	}

	public function test_the_query_verb_refuses_a_future_bucket(): void {
		$reply = self::query( 'requests', '--measure=count', '--bucket=' . Bucket::key( Bucket::id( self::NOW ) + 1 ) );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'in the future', $reply );
	}

	public function test_the_query_verb_refuses_a_filter_with_no_field(): void {
		$reply = self::query( 'requests', '--measure=count', '--filter=kakapo' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'kakapo', $reply );
	}

	/** A value holding a comma, as an agent does, filters back as answered. */
	public function test_a_ua_a_group_answered_filters_back_whole(): void {
		$ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Kea/7';
		$this->seed_request( [ 'ua' => $ua, 'ms' => 12.0 ], partition: 3 );
		$this->seed_request( [ 'ua' => 'Weka/1.2', 'ms' => 40.0 ] );
		$this->seed_request( [ 'ms' => 500.0 ], partition: 1 );
		$answered = self::query( 'requests', 'ua', '--measure=count' );

		$reply = self::query( 'requests', '--measure=sum_ms', '--filter=ua=' . $answered['rows'][0][0], '--filter=ua=Weka/1.2' );

		$this->assertSame( $ua, $answered['rows'][0][0] );
		$this->assertSame( [ [ 52 ] ], $reply['rows'], 'two tokens on one field match either' );
		$this->assertSame( [ 'ua' => [ $ua, 'Weka/1.2' ] ], $reply['filters']['filter'] );
	}

	public function test_the_query_verb_refuses_an_unknown_measure(): void {
		$reply = self::query( 'timings', '--measure=p95_ms' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'p95_ms', $reply );
	}

	public function test_an_empty_bucket_selection_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'bucket' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => [] ] ) );
	}

	/** Only a store that will not open fails soft; a broken read raises. */
	public function test_a_read_that_fails_once_open_propagates(): void {
		$this->seed_request();
		( new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( Flame_Facts_Row_Schema::TABLE, 0 ) ) )->exec( 'ALTER TABLE requests RENAME TO requests_moved' );

		$this->expectException( \PDOException::class );
		$this->expectExceptionMessage( 'requests' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );
	}

	/**
	 * The status classes and error kinds a request row is read under are the
	 * ones the builder files its hourly rollup under, so the picker can move
	 * between the two tables without the answer moving.
	 */
	public function test_requests_and_the_hourly_rollup_agree_on_status_class_and_errors(): void {
		$this->assertSame( [ 'T', 'F' ], Flame_Builder_Node::ERROR_KINDS );
		$this->assertSame( [ 100, 599 ], [ Flame_Builder_Node::STATUS_MIN, Flame_Builder_Node::STATUS_MAX ] );
		$fb = $this->armed_flame_builder();
		foreach ( [ [ 99, '-' ], [ 100, 'A' ], [ 599, 'T' ], [ 600, 'F' ], [ null, 'I' ], [ 204, '-' ] ] as $i => [ $status, $error ] ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => "rid-agree-{$i}", 'status_code' => $status, 'error_status' => $error, 'timestamp' => self::NOW - 4000 ] ), '' );
		}
		$fb->flush();
		$hour = Bucket::hour( self::NOW - 4000 );
		$spec = [ 'source' => 'requests', 'group' => [ 'status_class' ], 'measure' => [ 'count', 'errors' ] ];

		$raw    = Stats_Query::run( self::spec( $spec + [ 'buckets' => $this->last_day() ] ) );
		$hourly = Stats_Query::run( self::spec( $spec + [ 'buckets' => [ [ $hour * 12, $hour * 12 + 11 ] ] ] ) );

		$this->assertSame( 'requests_hourly', $hourly['table'] );
		$this->assertSame( [ [ 1, 1, 0 ], [ 2, 1, 0 ], [ 5, 1, 1 ], [ 'Unknown', 3, 1 ] ], $raw['rows'] );
		$this->assertSame( $raw['rows'], $hourly['rows'] );
	}

	public function test_an_unknown_measure_is_refused_naming_it(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'mean_wombats' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'mean_wombats' ], 'buckets' => $this->last_day() ] ) );
	}

	public function test_an_unknown_source_is_refused_naming_it(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'burrows' );

		Stats_Query::run( self::spec( [ 'source' => 'burrows', 'buckets' => $this->last_day() ] ) );
	}

	public function test_an_unknown_group_or_filter_field_is_refused_naming_it(): void {
		foreach ( [ [ 'group' => [ 'plumage' ] ], [ 'filter' => [ 'plumage' => [ 'green' ] ] ], [ 'group' => [ 'category' ] ] ] as $over ) {
			try {
				Stats_Query::run( self::spec( $over + [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );
				$this->fail( 'refused: ' . \wp_json_encode( $over ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( \array_key_first( $over['filter'] ?? [] ) ?? $over['group'][0], $e->getMessage() );
			}
		}
	}

	public function test_a_timing_measure_is_no_request_measure(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'avg_calls' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'avg_calls' ], 'buckets' => $this->last_day() ] ) );
	}

	public function test_a_sort_must_name_an_answered_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'max_ms' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'max_ms', 'buckets' => $this->last_day() ] ) );
	}

	public function test_an_order_is_asc_or_desc(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'sideways' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'order' => 'sideways', 'buckets' => $this->last_day() ] ) );
	}

	public function test_a_limit_or_offset_past_its_bound_is_refused(): void {
		foreach ( [ [ 'limit' => 1001 ], [ 'limit' => 0 ], [ 'offset' => 10001 ], [ 'offset' => -1 ] ] as $over ) {
			try {
				Stats_Query::run( self::spec( $over + [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );
				$this->fail( 'refused: ' . \wp_json_encode( $over ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( (string) \array_key_first( $over ), $e->getMessage() );
			}
		}
	}

	public function test_a_query_names_a_measure(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'measure' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [], 'buckets' => $this->last_day() ] ) );
	}

	public function test_no_partition_files_answers_no_rows(): void {
		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ 'count' ], $r['columns'] );
		$this->assertSame( [], $r['rows'] );
	}

	public function test_a_partition_with_no_file_beside_one_with_rows_answers_their_rows(): void {
		$this->seed_request( [ 'server' => 'tui.example' ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [ [ 'tui.example', 1 ] ], $r['rows'] );
	}

	public function test_rows_outside_the_buckets_are_not_read(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 288 ] );
		$this->seed_request( [ 'bucket' => $now - 3 ] );
		$this->seed_request( [ 'bucket' => $now - 3 ] );
		$this->seed_request( [ 'bucket' => $now - 1 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket' ], 'buckets' => [ [ $now - 288, $now - 288 ], [ $now - 3, $now - 2 ] ] ] ) );

		$this->assertSame( [ [ $now - 288, 1 ], [ $now - 3, 2 ] ], $r['rows'] );
	}

	public function test_a_stats_file_that_will_not_open_answers_no_rows_and_says_so_once(): void {
		$this->seed_request();
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();
		$logged = \count( Core::$recent_log );

		$first  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );
		$second = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_day() ] ) );

		$this->assertSame( [], $first['rows'] );
		$this->assertSame( [], $second['rows'] );
		$lines = \array_values( \array_filter( \array_slice( Core::$recent_log, $logged ), static fn ( string $line ): bool => \str_contains( $line, 'performance: stats rows did not open' ) ) );
		$this->assertCount( 1, $lines );
	}
}
