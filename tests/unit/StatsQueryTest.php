<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Stats_Query;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Builder_Node;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;

/**
 * The query vocabulary over the `flame-facts` rows: a spec of source,
 * groups, filters, measures and buckets becomes one statement over every
 * partition's file, read from the cheapest table that answers it exactly.
 */
#[CoversClass( Stats_Query::class )]
#[CoversClass( Performance_CI_Node::class )]
final class StatsQueryTest extends TestCase {

	use FactsSeeder;

	/** The pinned tick: its bucket opens an hour, so the span's buckets do not. */
	private const NOW = 1_790_003_000;

	protected function setUp(): void {
		parent::setUp();
		Core::$clock = static fn (): float => (float) self::NOW;
		Core::right_now();
		$GLOBALS['_current_user_can'] = true;
		$this->activate_shipped( 'stats-builder', 4 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_current_user_can'] );
		parent::tearDown();
	}

	/** The span's buckets ending at the tick's. */
	private function last_span(): array {
		$now = Bucket::id( self::NOW );
		return [ [ $now - Bucket::window() + 1, $now ] ];
	}

	/** The 24 whole hours ending with the tick's. */
	private function last_hours(): array {
		$hour = Bucket::hour( self::NOW );
		return [ [ ( $hour - 23 ) * 12, $hour * 12 + 11 ] ];
	}

	/** A timings spec over the span and fields the window holds reads the window. */
	public function test_a_spans_category_ranking_reads_the_window_when_every_watermark_stands_at_its_start(): void {
		$span = $this->last_span();
		foreach ( [ 0, 2 ] as $p ) {
			$this->seed_rows( $p, [
				[ 'ignore', 'window_expiry', [ 'bucket' => $span[0][0] - 3 ] ],
				[ 'ignore', 'window_expiry', [ 'bucket' => $span[0][0] - 1 ] ],
				[ 'add', 'category_window', self::window_row( 'hook:ruru_init', 21.5 + $p, 3, 2, $span[0][1] ) ],
				[ 'add', 'category_window', self::window_row( 'hook:gone_init', 500.0, 1, 1, $span[0][0] - 1 ) ],
			] );
		}

		$r = Stats_Query::run( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'worker' => [ 0 ], 'entry' => [ '' ] ], 'measure' => [ 'ms', 'calls' ], 'buckets' => $span, 'now' => self::NOW, 'sort' => 'ms', 'order' => 'desc' ] );

		$this->assertSame( 'category_window', $r['table'] );
		$this->assertSame( [ [ 'hook:ruru_init', 45.0, 6 ] ], $r['rows'], 'a row whose last bucket left the window reads as gone' );
	}

	/**
	 * A selection as long as the reader's span, its start meeting the
	 * watermark, still reads `timings_site` unless it ends at the current
	 * bucket: a builder keeping 24 buckets holds sums a reader on 12 never
	 * asked for.
	 */
	public function test_a_span_long_selection_ending_before_the_tick_never_reads_the_window(): void {
		$this->use_min_lifetime( 3600 );
		$now = Bucket::id( self::NOW );
		$this->seed_rows( 0, [
			[ 'ignore', 'window_expiry', [ 'bucket' => $now - 24 ] ],
			[ 'add', 'category_window', self::window_row( 'hook:ruru_init', 500.0, 9, 9, $now ) ],
		] );
		$this->seed_site_timing( [ 'category' => 'hook:ruru_init', 'bucket' => $now - 15, 'ms' => 21.5 ] );

		$r = Stats_Query::run( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'worker' => [ 0 ], 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => [ [ $now - 23, $now - 12 ] ] ] );

		$this->assertSame( 'timings_site', $r['table'] );
		$this->assertSame( [ [ 'hook:ruru_init', 21.5 ] ], $r['rows'] );
	}

	/**
	 * The window is judged against the reply's clock the caller read, never
	 * a second read of the tick: a caller one bucket ahead still reads it.
	 */
	public function test_the_window_ends_at_the_callers_clock(): void {
		$ahead = self::NOW + Bucket::SECONDS;

		$this->assertSame( 'category_window', Stats_Query::table_for( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'worker' => [ 0 ] ], 'measure' => [ 'ms' ], 'buckets' => Bucket::last( Bucket::window(), $ahead ), 'now' => $ahead ] ) );
		$this->assertSame( 'timings_site', Stats_Query::table_for( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'worker' => [ 0 ] ], 'measure' => [ 'ms' ], 'buckets' => Bucket::last( Bucket::window(), $ahead ) ] ), 'a spec naming no clock never reads the window' );
	}

	public function test_a_lagging_watermark_reads_timings_site(): void {
		$span = $this->last_span();
		$this->write_facts( 0, [ [ 'ignore', 'window_expiry', [ $span[0][0] - 1 ] ] ] );
		$this->write_facts( 1, [ [ 'ignore', 'window_expiry', [ $span[0][0] - 2 ] ] ] );
		$this->seed_site_timing( [ 'category' => 'hook:ruru_init', 'ms' => 13.5 ], partition: 1 );

		$r = Stats_Query::run( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => $span ] );

		$this->assertSame( 'timings_site', $r['table'] );
		$this->assertSame( [ [ 'hook:ruru_init', 13.5 ] ], $r['rows'] );
	}

	public function test_a_partition_with_no_expiry_row_reads_timings_site(): void {
		$span = $this->last_span();
		$this->write_facts( 0, [ [ 'ignore', 'window_expiry', [ $span[0][0] - 1 ] ] ] );
		$this->seed_site_timing( [ 'category' => 'hook:ruru_init', 'ms' => 17.5 ], partition: 1 );

		$r = Stats_Query::run( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => $span ] );

		$this->assertSame( 'timings_site', $r['table'] );
		$this->assertSame( [ [ 'hook:ruru_init', 17.5 ] ], $r['rows'] );
	}

	public function test_a_server_scoped_or_shorter_ranking_never_reads_the_window(): void {
		$span = $this->last_span();
		$this->assertSame( 'timings_site', Stats_Query::table_for( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'server' => [ 'kea.example' ] ], 'measure' => [ 'ms' ], 'buckets' => $span, 'now' => self::NOW ] ) );
		$this->assertSame( 'timings_site', Stats_Query::table_for( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [], 'measure' => [ 'ms' ], 'buckets' => [ [ $span[0][0] + 1, $span[0][1] ] ], 'now' => self::NOW ] ) );
		$this->assertSame( 'timings_site', Stats_Query::table_for( [ 'source' => 'timings', 'group' => [ 'bucket', 'category' ], 'filter' => [], 'measure' => [ 'ms' ], 'buckets' => $span, 'now' => self::NOW ] ) );
		$this->assertSame( 'category_window', Stats_Query::table_for( [ 'source' => 'timings', 'group' => [ 'category', 'entry' ], 'filter' => [ 'worker' => [ 0 ] ], 'measure' => [ 'ms' ], 'buckets' => $span, 'now' => self::NOW ] ) );
	}

	private static function spec( array $over ): array {
		return $over + [ 'group' => [], 'filter' => [], 'measure' => [ 'count' ] ];
	}

	/**
	 * The query plan SQLite chooses for a spec, one line per step.
	 *
	 * @param array<string,mixed> $spec A `spec()` result.
	 */
	private static function plan_of( array $spec ): string {
		$checked = ( new \ReflectionMethod( Stats_Query::class, 'checked' ) )->invoke( null, $spec );
		$table   = Stats_Query::table_for( $checked );
		return Row_Table_Node::read(
			Flame_Facts_Row_Schema::class,
			static function ( Row_Reader $reader ) use ( $checked, $table ): string {
				[ $sql, $binds ] = ( new \ReflectionMethod( Stats_Query::class, 'statement' ) )->invoke( null, $checked, $table, $reader->aliases(), false );
				return self::plan_text( $reader, $sql, $binds );
			}
		);
	}

	/**
	 * Each `stats query` span `$work` writes on a started record: its
	 * category, its `l` and its `m`.
	 *
	 * @param \Closure(): void $work What to log.
	 * @return list<array{0:mixed,1:mixed,2:mixed}>
	 */
	private function stats_spans( \Closure $work ): array {
		return self::spans_of( $this->logged_in( $this->make_temp_dir(), $work ), Flame_Tree::STATS_QUERY );
	}

	/** A read is a span labelled with its source, its groups and a page's offset, closing on the rows it read. */
	public function test_a_logged_read_is_a_stats_query_span_naming_its_source_groups_and_offset(): void {
		$spans = $this->stats_spans(
			function (): void {
				$this->seed_site_timings( [ [ 'category' => 'hook:tieke_init', 'ms' => 9.5 ], [ 'category' => 'hook:hoiho_init', 'ms' => 4.5 ], [ 'category' => 'hook:weka_init', 'ms' => 3.5 ] ] );
				$spec = [ 'source' => 'timings', 'group' => [ 'category', 'listener' ], 'filter' => [ 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span(), 'sort' => 'ms' ];
				Stats_Query::run( $spec );
				Stats_Query::run( [ 'offset' => 2 ] + $spec );
				Stats_Query::run( [ 'source' => 'timings', 'filter' => [ 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] );
			}
		);

		$this->assertSame(
			[
				[ 'stats query (start)', 'timings by category,listener', null ],
				[ 'stats query (complete)', null, '3 rows' ],
				[ 'stats query (start)', 'timings by category,listener offset 2', null ],
				[ 'stats query (complete)', null, '1 rows' ],
				[ 'stats query (start)', 'timings', null ],
				[ 'stats query (complete)', null, '1 rows' ],
			],
			$spans
		);
	}

	/** The two request listings are spans too, each named for what it lists. */
	public function test_a_logged_listing_names_the_newest_requests_or_the_rid_it_seeks(): void {
		$spans = $this->stats_spans(
			function (): void {
				$this->seed_requests( [ [ 'rid' => 'rid-kokako-8812', 'url' => 'https://seed.example/kokako-8812/' ], [ 'rid' => 'rid-kakapo-8813', 'url' => 'https://seed.example/kokako-8812/' ] ] );
				Stats_Query::newest_requests( [ 'url' => [ Log_Manager::url_hash( 'https://seed.example/kokako-8812/' ) ] ], $this->last_span(), 7 );
				Stats_Query::request_row( 'rid-kakapo-8813' );
			}
		);

		$this->assertSame(
			[
				[ 'stats query (start)', 'requests newest', null ],
				[ 'stats query (complete)', null, '2 rows' ],
				[ 'stats query (start)', 'requests by rid', null ],
				[ 'stats query (complete)', null, '1 rows' ],
			],
			$spans
		);
	}

	/** A read whose rows did not open says so, never as zero rows. */
	public function test_a_logged_read_whose_rows_did_not_open_closes_unavailable(): void {
		$spans = $this->stats_spans(
			function (): void {
				\update_option( 'newspack_nodes_topologies', [] );
				\Newspack_Nodes\Config::reset();
				Stats_Query::run( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'count' ], 'buckets' => $this->last_span() ] );
			}
		);

		$this->assertSame( [ [ 'stats query (start)', 'requests by server', null ], [ 'stats query (complete)', null, 'unavailable' ] ], $spans );
	}

	public function test_five_xx_by_server_answers_one_row_per_server(): void {
		$this->seed_request( [ 'server' => 'kea.example', 'status' => 503 ] );
		$this->seed_request( [ 'server' => 'egret.example', 'status' => 502 ], partition: 3 );
		$this->seed_request( [ 'server' => 'kea.example', 'status' => 200 ] );

		$r = Stats_Query::run( [ 'source' => 'requests', 'group' => [ 'server' ], 'filter' => [ 'status_class' => [ 5 ] ], 'measure' => [ 'count' ], 'buckets' => $this->last_span(), 'sort' => 'server' ] );

		$this->assertSame( [ 'server', 'count' ], $r['columns'] );
		$this->assertSame( [ [ 'egret.example', 1 ], [ 'kea.example', 1 ] ], $r['rows'] );
		$this->assertSame( 'requests', $r['source'] );
		$this->assertSame( 'requests', $r['table'] );
	}

	/**
	 * Each partition files a name under an id of its own: `kea.example` is
	 * partition 0's first server and partition 3's second, and still groups
	 * as one server, filters as one, and names its URLs, in either the
	 * composed or the whole-row reading.
	 */
	public function test_a_server_filed_under_another_id_in_each_partition_reads_as_one(): void {
		$this->seed_request( [ 'server' => 'kea.example', 'ms' => 120.0 ] );
		$this->seed_request( [ 'server' => 'egret.example', 'ms' => 40.0 ], partition: 3 );
		$this->seed_requests( [ [ 'server' => 'kea.example', 'ms' => 80.0 ], [ 'server' => 'kea.example', 'ms' => 60.0 ] ], partition: 3 );
		$span = $this->last_span();
		$url = Log_Manager::url_hash( 'https://kea.example/seed-path/' );

		$by_server = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'server', 'buckets' => $span ] ) );
		$filtered  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'server' => [ 'kea.example' ] ], 'buckets' => $span ] ) );
		$composed  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'filter' => [ 'server' => [ 'kea.example' ] ], 'buckets' => $span ] ) );
		$whole     = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'filter' => [ 'server' => [ 'kea.example' ] ], 'measure' => [ 'p50_ms' ], 'buckets' => $span ] ) );

		$this->assertSame( [ [ 'egret.example', 1 ], [ 'kea.example', 3 ] ], $by_server['rows'] );
		$this->assertSame( [ [ 3 ] ], $filtered['rows'] );
		$this->assertSame( [ [ $url, 'https://kea.example/seed-path/', 3 ] ], $composed['rows'] );
		$this->assertSame( [ [ $url, 'https://kea.example/seed-path/', 80.0 ] ], $whole['rows'] );
	}

	/**
	 * A URL filter or a search reads the URL's row alone: only a group that
	 * names the URL joins its server, so a missing server row drops nothing
	 * from a count filtered by URL.
	 */
	public function test_only_a_url_group_reads_the_urls_server(): void {
		$url = 'https://pukeko.example/raupo-417';
		$this->seed_request( [ 'server' => 'pukeko.example', 'url' => $url ] );
		$this->seed_request( [ 'server' => 'pukeko.example', 'url' => $url ] );
		( new \PDO( 'sqlite:' . $this->facts_file( 0 ) ) )->exec( 'DELETE FROM servers' );
		$span = $this->last_span();

		$filtered = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'url' => [ Log_Manager::url_hash( $url ) ] ], 'buckets' => $span ] ) );
		$searched = Stats_Query::run( self::spec( [ 'source' => 'requests', 'search' => 'raupo', 'buckets' => $span ] ) );

		$this->assertSame( [ [ 2 ] ], $filtered['rows'] );
		$this->assertSame( [ [ 2 ] ], $searched['rows'] );
	}

	/**
	 * A JA4, a From, a category and an entry filed in a different order in
	 * each partition still group and filter as one name.
	 */
	public function test_names_filed_in_another_order_per_partition_read_as_one(): void {
		$this->seed_request( [ 'ja4' => 't13d_kea', 'from' => 'kea@bot.example' ] );
		$this->seed_request( [ 'ja4' => 't13d_moa', 'from' => 'moa@bot.example' ], partition: 3 );
		$this->seed_request( [ 'ja4' => 't13d_kea', 'from' => 'kea@bot.example' ], partition: 3 );
		$this->seed_site_timings( [ [ 'category' => 'hook:kea_init', 'entry' => 'kea_cb', 'ms' => 7.5 ] ] );
		$this->seed_site_timings( [ [ 'category' => 'hook:moa_init', 'entry' => 'moa_cb', 'ms' => 2.5 ], [ 'category' => 'hook:kea_init', 'entry' => 'kea_cb', 'ms' => 4.0 ] ], partition: 3 );
		$span = $this->last_span();

		$ja4  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'ja4', 'from' ], 'sort' => 'ja4', 'buckets' => $span ] ) );
		$from = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'from' => [ 'kea@bot.example' ] ], 'buckets' => $span ] ) );
		$cats = Stats_Query::run( [ 'source' => 'timings', 'group' => [ 'category', 'entry' ], 'filter' => [ 'entry' => [ 'kea_cb' ] ], 'measure' => [ 'ms' ], 'buckets' => [ [ $span[0][1] - 11, $span[0][1] ] ] ] );

		$this->assertSame( [ [ 't13d_kea', 'kea@bot.example', 2 ], [ 't13d_moa', 'moa@bot.example', 1 ] ], $ja4['rows'] );
		$this->assertSame( [ [ 2 ] ], $from['rows'] );
		$this->assertSame( 'timings_site', $cats['table'] );
		$this->assertSame( [ [ 'hook:kea_init', 'kea_cb', 11.5 ] ], $cats['rows'] );
	}

	public function test_a_missing_status_is_unknown_in_a_status_breakdown(): void {
		$this->seed_request( [ 'status' => null ] );
		$this->seed_request( [ 'status' => 999 ], partition: 1 );
		$this->seed_request( [ 'status' => 404 ] );
		$this->seed_request( [ 'status' => 404 ], partition: 3 );

		$by_status = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'status' ], 'buckets' => $this->last_span() ] ) );
		$by_class  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'status_class' ], 'buckets' => $this->last_span() ] ) );
		$total     = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 404, 2 ], [ 'Unknown', 2 ] ], $by_status['rows'] );
		$this->assertSame( [ [ 4, 2 ], [ 'Unknown', 2 ] ], $by_class['rows'] );
		$this->assertSame( [ [ 4 ] ], $total['rows'], 'every request counts in the total' );
	}

	public function test_a_filter_on_unknown_matches_what_a_group_answers_unknown(): void {
		$this->seed_request( [ 'country' => null, 'ms' => 61.0 ] );
		$this->seed_request( [ 'country' => 'NZ', 'ms' => 17.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'country' => [ 'Unknown' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 61.0 ] ], $r['rows'] );
	}

	public function test_a_negated_filter_keeps_every_row_matching_none_of_its_values_unknown_included(): void {
		$this->seed_request( [ 'country' => null, 'ms' => 61.0 ] );
		$this->seed_request( [ 'country' => 'NZ', 'ms' => 17.0 ] );
		$this->seed_request( [ 'country' => 'AU', 'ms' => 4.0 ], partition: 3 );
		$this->seed_request( [ 'country' => 'SG', 'ms' => 9.0 ] );

		$not_nz  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'country!' => [ 'NZ', 'SG' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );
		$known   = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'country!' => [ 'Unknown' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );
		$crossed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'country' => [ 'NZ', 'AU' ], 'country!' => [ 'AU' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 65.0 ] ], $not_nz['rows'], 'neither NZ nor SG, an Unknown country kept' );
		$this->assertSame( [ [ 30.0 ] ], $known['rows'] );
		$this->assertSame( [ [ 17.0 ] ], $crossed['rows'], 'one of the kept values and none of the negated' );
	}

	public function test_a_negated_integer_filter_binds_its_digits_and_reads_the_rollup(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'count' => 7, 'status_class' => 5 ] );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'country' => 'NZ', 'count' => 5, 'status_class' => 2 ], partition: 3 );
		$this->seed_hourly( [ 'hour' => $hour - 3, 'count' => 3, 'status_class' => 0 ] );
		$spec = self::spec( [ 'source' => 'requests', 'filter' => [ 'status_class!' => [ '5' ] ], 'buckets' => $this->last_hours() ] );

		$r = Stats_Query::run( $spec );

		$this->assertSame( 'requests_hourly', $r['table'] );
		$this->assertSame( [ [ 8 ] ], $r['rows'], 'a 2xx and an Unknown class kept' );
	}

	public function test_a_status_class_filter_reads_its_xx_spelling_in_any_case(): void {
		$this->seed_request( [ 'status' => 503, 'ms' => 3.0 ] );
		$this->seed_request( [ 'status' => 204, 'ms' => 40.0 ], partition: 3 );
		$this->seed_request( [ 'status' => 404, 'ms' => 500.0 ] );

		$kept = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'status_class' => [ '5xx' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );
		$left = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'status_class!' => [ '2XX' ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 3.0 ] ], $kept['rows'] );
		$this->assertSame( [ [ 503.0 ] ], $left['rows'] );
	}

	public function test_a_negated_url_filter_joins_the_urls_it_leaves_out(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kokako/', 'ms' => 33.0 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/hihi/', 'ms' => 900.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'filter' => [ 'url!' => [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( 'https://seed.example/kokako/' ) ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ 900.0 ], \array_column( $r['rows'], 2 ) );
	}

	/**
	 * A filter by a looked-up name reads the rows' own window, never the id
	 * index a lookup row's release seeks, which would walk every row the
	 * name has, past the window.
	 *
	 * Each row: the spec, whether it reads one whole hour, the table it
	 * reads and the release index it must not.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:string,3:string}>
	 */
	public static function name_filters(): array {
		return [
			'server on timings'         => [ [ 'source' => 'timings', 'group' => [ 'url' ], 'filter' => [ 'server' => [ 'kea.example' ] ], 'measure' => [ 'ms' ] ], false, 'timings', 'timings_server_id' ],
			'server on requests_hourly' => [ [ 'source' => 'requests', 'filter' => [ 'server' => [ 'kea.example' ] ], 'measure' => [ 'count' ] ], true, 'requests_hourly', 'requests_hourly_server_id' ],
			'category on timings_site'  => [ [ 'source' => 'timings', 'group' => [ 'bucket' ], 'filter' => [ 'category' => [ 'init hook @10' ] ], 'measure' => [ 'ms' ] ], false, 'timings_site', 'timings_site_category_id' ],
			'entry on timings_site'     => [ [ 'source' => 'timings', 'group' => [ 'bucket' ], 'filter' => [ 'entry' => [ 'kea_init' ] ], 'measure' => [ 'ms' ] ], false, 'timings_site', 'timings_site_entry_id' ],
		];
	}

	/**
	 * @param array<string,mixed> $over
	 */
	#[DataProvider( 'name_filters' )]
	public function test_a_name_filter_reads_the_window_not_the_lookup_id_index( array $over, bool $whole_hour, string $table, string $release_index ): void {
		$hour     = Bucket::hour( (int) Core::$now ) - 1;
		$per_hour = Bucket::HOUR_SECONDS / Bucket::SECONDS;
		$this->seed_timing( [ 'server' => 'kea.example' ] );
		$this->seed_site_timing( [ 'server' => 'kea.example', 'category' => 'init hook @10', 'entry' => 'kea_init' ] );
		$this->seed_hourly( [ 'server' => 'kea.example', 'hour' => $hour ] );
		$spec = self::spec( $over + [ 'buckets' => $whole_hour ? [ [ $hour * $per_hour, ( $hour + 1 ) * $per_hour - 1 ] ] : $this->last_span() ] );

		$this->assertSame( $table, Stats_Query::table_for( $spec ) );
		$this->assertArrayHasKey( $release_index, Flame_Facts_Row_Schema::tables()[ $table ]['indexes'] ?? [], 'the release index exists, so its absence from the plan says something' );
		$this->assertStringNotContainsString( $release_index, self::plan_of( $spec ) );
	}

	/** A server filter on requests seeks the server-led index over the window. */
	public function test_a_server_filter_on_requests_seeks_the_server_led_index(): void {
		$this->seed_request( [ 'server' => 'kea-6101.example', 'ms' => 41.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6101.example', 'ms' => 7.25 ] );
		$plan = self::plan_of( self::spec( [ 'source' => 'requests', 'filter' => [ 'server' => [ 'kea-6101.example' ] ], 'measure' => [ 'count', 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH t USING (COVERING )?INDEX requests_server \(server_id=\? AND bucket>\? AND bucket<\?\)/', $plan );
	}

	public function test_a_server_filter_on_timings_site_seeks_its_server_led_key(): void {
		$this->seed_site_timing( [ 'server' => 'kea-7003.example', 'category' => 'sql', 'ms' => 41.5 ] );
		$this->seed_site_timing( [ 'server' => 'kaka-7003.example', 'category' => 'sql', 'ms' => 7.25 ] );

		$plan = self::plan_of( self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'server' => [ 'kea-7003.example' ], 'worker' => [ 0 ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH t USING .*\(server_id=\? AND bucket>\? AND bucket<\?\)/', $plan );
	}

	public function test_a_server_filter_on_timings_site_answers_the_named_servers_rows(): void {
		$this->seed_site_timing( [ 'server' => 'kea-7005.example', 'category' => 'sql', 'ms' => 41.5 ] );
		$this->seed_site_timing( [ 'server' => 'kea-7005.example', 'category' => 'sql', 'ms' => 3.5 ], partition: 2 );
		$this->seed_site_timing( [ 'server' => 'kaka-7005.example', 'category' => 'sql', 'ms' => 7.25 ] );
		$run = fn ( array $filter ): array => Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => $filter + [ 'entry' => [ '' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) )['rows'];

		$this->assertSame( [ [ 'sql', 45.0 ] ], $run( [ 'server' => [ 'kea-7005.example' ] ] ) );
		$this->assertSame( [ [ 'sql', 7.25 ] ], $run( [ 'server!' => [ 'kea-7005.example' ] ] ), 'a negation answers every other server' );
		$this->assertSame( [], $run( [ 'server' => [ 'moa-7005.example' ] ] ), 'a name no partition holds answers nothing' );
	}

	/** A server's totals and profiled count read `requests_server` alone. */
	public function test_a_scoped_totals_read_covers_on_the_server_led_index(): void {
		$this->seed_request( [ 'server' => 'kea-7001.example', 'ms' => 41.5, 'error' => 'T', 'profiled' => 1 ] );
		$this->seed_request( [ 'server' => 'kaka-7001.example', 'ms' => 7.25 ] );

		$plan = self::plan_of( self::spec( [ 'source' => 'requests', 'group' => [ 'profiled' ], 'filter' => [ 'server' => [ 'kea-7001.example' ], 'worker' => [ 0 ] ], 'measure' => [ 'count', 'timed', 'sum_ms', 'errors' ], 'buckets' => $this->last_span() ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH t USING COVERING INDEX requests_server \(server_id=\? AND bucket>\? AND bucket<\?\)/', $plan );
	}

	/** An unscoped read of the profiled flag, errors, status and completion reads `requests_cover` alone, as the totals and the URL grouping do. */
	public function test_an_unscoped_read_of_errors_and_completion_covers_on_requests_cover(): void {
		$this->seed_request( [ 'server' => 'kea-7002.example', 'ms' => 41.5, 'error' => 'F', 'profiled' => 1 ] );

		$plan = self::plan_of( self::spec( [ 'source' => 'requests', 'group' => [ 'profiled' ], 'filter' => [ 'worker' => [ 0 ] ], 'measure' => [ 'count', 'errors', 'last_seen', 'count_5xx' ], 'buckets' => $this->last_span() ] ) );

		$this->assertMatchesRegularExpression( '/SEARCH t USING COVERING INDEX requests_cover \(bucket>\? AND bucket<\?\)/', $plan );
	}

	/**
	 * A plain server join must still let a read naming no server, or
	 * negating one, walk the window by bucket, on each table joining so:
	 * each row the spec and the bucket-led index it walks.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function unscoped_server_reads(): array {
		$reads = [];
		foreach ( [ 'requests' => [ 'count', 'requests_cover' ], 'timings' => [ 'ms', 'timings_site_bucket' ] ] as $source => [ $measure, $walks ] ) {
			$reads += [
				"servers list on {$source}"    => [ [ 'source' => $source, 'group' => [ 'server' ], 'measure' => [ $measure ], 'sort' => $measure, 'order' => 'desc' ], $walks ],
				"chart by server on {$source}" => [ [ 'source' => $source, 'group' => [ 'bucket', 'server' ], 'measure' => [ $measure ], 'top' => 20, 'top_by' => $measure ], $walks ],
				"negated server on {$source}"  => [ [ 'source' => $source, 'filter' => [ 'server!' => [ 'kea-6104.example' ] ], 'measure' => [ $measure ] ], $walks ],
			];
		}
		return $reads;
	}

	/**
	 * @param array<string,mixed> $over
	 */
	#[DataProvider( 'unscoped_server_reads' )]
	public function test_a_read_naming_no_server_walks_the_window_by_bucket( array $over, string $walks ): void {
		foreach ( [ 'kea-6104.example' => 41.5, 'kaka-6104.example' => 7.25 ] as $server => $ms ) {
			$this->seed_request( [ 'server' => $server, 'ms' => $ms ] );
			$this->seed_site_timing( [ 'server' => $server, 'ms' => $ms ] );
		}
		$plan = self::plan_of( self::spec( $over + [ 'buckets' => $this->last_span() ] ) );

		$this->assertMatchesRegularExpression( "/SEARCH t USING (COVERING )?INDEX {$walks} \\(bucket>\\? AND bucket<\\?\\)/", $plan );
		$this->assertStringNotContainsString( 'server_id=?', $plan );
	}

	/**
	 * The page-filtered reads the URL table and its charts ask, each the
	 * url-led index it reaches the rows by.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function page_filtered_reads(): array {
		return [
			'requests totals'     => [ [ 'source' => 'requests', 'measure' => [ 'count', 'sum_ms' ] ], 'requests_url' ],
			'requests by url'     => [ [ 'source' => 'requests', 'group' => [ 'url' ], 'measure' => [ 'count', 'sum_ms' ] ], 'requests_url' ],
			'requests by bucket'  => [ [ 'source' => 'requests', 'group' => [ 'bucket' ], 'measure' => [ 'count', 'p95_ms' ] ], 'requests_url' ],
			'timings by category' => [ [ 'source' => 'timings', 'group' => [ 'category' ], 'measure' => [ 'ms' ] ], 'timings_url' ],
		];
	}

	/**
	 * A page filter seeks the page's URLs through `urls_page`, then each
	 * URL's rows over the window through the url-led index, rather than
	 * walking the whole window and looking up every row's URL.
	 *
	 * @param array<string,mixed> $over The read.
	 * @param string              $seeks The url-led index it seeks.
	 */
	#[DataProvider( 'page_filtered_reads' )]
	public function test_a_page_filter_seeks_its_urls_then_their_rows( array $over, string $seeks ): void {
		foreach ( [ 'https://kea.example/Hihi-6105/', 'https://kea.example/hihi-6105/?a=?', 'https://kea.example/kaka-6105/' ] as $i => $url ) {
			$this->seed_request( [ 'url' => $url, 'server' => 'kea.example', 'ms' => 12.5 + $i ] );
			$this->seed_timing( [ 'url' => $url, 'server' => 'kea.example', 'ms' => 12.5 + $i ] );
		}
		$plan = self::plan_of( self::spec( $over + [ 'filter' => [ 'page' => [ Log_Manager::url_hash( 'kea.example/hihi-6105/' ) ] ], 'buckets' => $this->last_span() ] ) );

		$this->assertMatchesRegularExpression( '/^SEARCH u USING (COVERING )?INDEX urls_page \(page_hash=\?\)$/m', $plan );
		$this->assertMatchesRegularExpression( "/^SEARCH t USING (COVERING )?INDEX {$seeks} \\(url_id=\\? AND bucket>\\? AND bucket<\\?\\)$/m", $plan );
		$this->assertLessThan( \strpos( $plan, "INDEX {$seeks}" ), \strpos( $plan, 'INDEX urls_page' ), "the page's URLs drive the read:\n{$plan}" );
	}

	public function test_a_server_filter_answers_the_named_servers_rows(): void {
		$this->seed_request( [ 'server' => 'kea-6102.example', 'ms' => 41.5 ] );
		$this->seed_request( [ 'server' => 'kea-6102.example', 'ms' => 3.5 ], partition: 2 );
		$this->seed_request( [ 'server' => 'kaka-6102.example', 'ms' => 7.25 ] );
		$this->seed_request( [ 'server' => 'tui-6102.example', 'ms' => 900.0 ], partition: 3 );
		$run = fn ( array $filter ): array => Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => $filter, 'measure' => [ 'count', 'sum_ms' ], 'buckets' => $this->last_span() ] ) )['rows'];

		$this->assertSame( [ [ 2, 45.0 ] ], $run( [ 'server' => [ 'kea-6102.example' ] ] ) );
		$this->assertSame( [ [ 3, 52.25 ] ], $run( [ 'server' => [ 'kea-6102.example', 'kaka-6102.example' ] ] ), 'two names answer both' );
		$this->assertSame( [ [ 2, 907.25 ] ], $run( [ 'server!' => [ 'kea-6102.example' ] ] ), 'a negation answers every other server' );
		$this->assertSame( [ [ 0, 0.0 ] ], $run( [ 'server' => [ 'moa-6102.example' ] ] ), 'a name no partition holds answers nothing' );
	}

	public function test_a_server_group_still_names_each_server(): void {
		$this->seed_request( [ 'server' => 'kea-6103.example', 'ms' => 41.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6103.example', 'ms' => 7.25 ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'filter' => [ 'server' => [ 'kaka-6103.example' ] ], 'measure' => [ 'count' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'kaka-6103.example', 1 ] ], $r['rows'] );
	}

	public function test_a_negated_filter_on_an_unknown_field_is_refused_naming_the_field(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown requests field kakapo:' );
		Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'kakapo!' => [ 'NZ' ] ], 'buckets' => $this->last_span() ] ) );
	}

	public function test_an_absent_country_agent_ja4_and_from_are_unknown_and_no_error_is_a_dash(): void {
		$this->seed_request( [ 'error' => 'T', 'ms' => null ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'country', 'ua', 'ja4', 'from', 'error' ], 'buckets' => $this->last_span() ] ) );
		$this->seed_request( [ 'country' => 'AU', 'ua' => 'Kakapo/2.0', 'ja4' => 't13d1517h2', 'from' => 'bot@kea.example' ], partition: 3 );
		$named = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'country', 'ua', 'ja4', 'from', 'error' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'T', 1 ] ], $r['rows'] );
		$this->assertSame( [ [ 'AU', 'Kakapo/2.0', 't13d1517h2', 'bot@kea.example', '-', 1 ], [ 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'T', 1 ] ], $named['rows'] );
	}

	/**
	 * A second page of the same groups, in its own order, rides the one
	 * statement: `also` answers it beside `rows`.
	 */
	public function test_a_second_order_of_the_same_groups_answers_as_also(): void {
		foreach ( [ [ 'kea', 3, 20.5 ], [ 'tui', 2, 90.5 ], [ 'weka', 1, 55.5 ] ] as [ $name, $count, $ms ] ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->seed_request( [ 'url' => "https://seed.example/{$name}-6640/", 'ms' => $ms ], partition: $i );
			}
		}
		$hash = static fn ( string $name ): string => Log_Manager::url_hash( "https://seed.example/{$name}-6640/" );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'measure' => [ 'count', 'avg_ms' ], 'buckets' => $this->last_span(), 'sort' => 'count', 'order' => 'desc', 'limit' => 2, 'also' => [ 'sort' => 'avg_ms', 'order' => 'desc', 'limit' => 1 ] ] ) );

		$this->assertSame( [ $hash( 'kea' ), $hash( 'tui' ) ], \array_column( $r['rows'], 0 ) );
		$this->assertSame( [ [ $hash( 'tui' ), 'https://seed.example/tui-6640/', 2, 90.5 ] ], $r['also'] );
	}

	/** An `also` the groups cannot answer, or one beside a top, is refused. */
	public function test_an_also_is_refused_where_it_cannot_page(): void {
		$base = [ 'source' => 'requests', 'group' => [ 'url' ], 'buckets' => $this->last_span() ];
		foreach (
			[
				[ [ 'also' => [ 'sort' => 'p95_ms', 'limit' => 3 ] ], 'unknown also sort p95_ms' ],
				[ [ 'also' => [ 'sort' => 'count', 'order' => 'sideways' ] ], 'unknown also order sideways' ],
				[ [ 'also' => [ 'sort' => 'count', 'limit' => 1001 ] ], 'also limit 1001' ],
				[ [ 'group' => [ 'bucket', 'url' ], 'top' => 2, 'top_by' => 'count', 'also' => [ 'sort' => 'count' ] ], 'top takes no also' ],
			] as [ $over, $needle ]
		) {
			try {
				Stats_Query::run( self::spec( $over + $base ) );
				$this->fail( "refused: {$needle}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $needle, $e->getMessage() );
			}
		}
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

		foreach ( [ $this->last_span(), [ [ ( $hour - 1 ) * 12, $hour * 12 ] ], [ [ ( $hour - 1 ) * 12, $hour * 12 - 1 ], [ $hour * 12 + 1, $hour * 12 + 11 ] ] ] as $buckets ) {
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

		$raw    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'avg_ms' ], 'buckets' => $this->last_span() ] ) );
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

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => $measures, 'buckets' => $this->last_span() ] ) );

		$this->assertSame( $measures, $r['columns'] );
		$this->assertSame( [ [ 3, 2, 120.0, 60.0, 80.0, 40.0, 60.0, 20.0, 30.0, 2, 2, (float) self::NOW - 20.25 ] ], $r['rows'], 'an abort is not an error, and the URL seen twice in two partitions counts once' );
	}

	public function test_status_class_counts_and_worker_measure_each_group(): void {
		foreach ( [ 201, 204, 404, 503, 599, null, 700 ] as $i => $status ) {
			$this->seed_request( [ 'server' => 'kea.example', 'status' => $status ], partition: $i % 4 );
		}
		$this->seed_request( [ 'server' => 'kea.example', 'status' => 302, 'worker' => 1 ], partition: 2 );
		$this->seed_request( [ 'server' => 'egret.example', 'status' => 200 ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'count', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'worker' ], 'sort' => 'server', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( 'requests', $r['table'] );
		$this->assertSame(
			[ [ 'egret.example', 1, 1, 0, 0, 0, 0 ], [ 'kea.example', 8, 2, 1, 1, 2, 1 ] ],
			$r['rows'],
			'a missing status and one past the bounds count in no class'
		);
	}

	public function test_a_field_grouped_twice_is_refused_naming_it(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'method' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket', 'method', 'method' ], 'buckets' => $this->last_span() ] ) );
	}

	public function test_a_name_both_grouped_and_measured_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'worker names both a group and a measure' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'worker' ], 'measure' => [ 'worker' ], 'buckets' => $this->last_span() ] ) );
	}

	public function test_an_untimed_group_answers_null_means_in_either_table(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_request( [ 'ms' => null, 'error' => 'T' ] );
		$this->seed_hourly( [ 'hour' => $hour, 'count' => 2, 'timed' => 0, 'sum_ms' => 0.0, 'max_ms' => 0.0, 'errors' => 2 ] );
		$measures = [ 'count', 'timed', 'sum_ms', 'avg_ms', 'max_ms', 'errors' ];

		$raw    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => $measures, 'buckets' => $this->last_span() ] ) );
		$hourly = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => $measures, 'buckets' => $this->last_hours() ] ) );

		$this->assertSame( [ [ 1, 0, 0.0, null, null, 1 ] ], $raw['rows'] );
		$this->assertSame( [ [ 2, 0, 0.0, null, null, 2 ] ], $hourly['rows'] );
	}

	/**
	 * Seed `sql` as id 1 in partition 0 and as id 3 in partition 3, two
	 * hooks named ahead of it there, on one URL; answer its hash.
	 */
	private function seed_sql_numbered_apart(): string {
		$url = 'https://kea.example/kaka-8812/';
		$this->seed_timing( [ 'url' => $url, 'server' => 'kea.example', 'category' => 'sql', 'ms' => 12.5 ] );
		$this->seed_timing( [ 'url' => $url, 'server' => 'kea.example', 'category' => 'kaka_init hook', 'ms' => 1.5 ], 3 );
		$this->seed_timing( [ 'url' => $url, 'server' => 'kea.example', 'category' => 'tui_init hook', 'ms' => 2.5 ], 3 );
		$this->seed_timing( [ 'url' => $url, 'server' => 'kea.example', 'category' => 'sql', 'ms' => 30.25 ], 3 );
		return Log_Manager::url_hash( $url );
	}

	/** Each partition sums a category by its own id, and the names merge the partitions. */
	public function test_timings_group_by_id_inside_each_partition(): void {
		$spec = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'url' => [ $this->seed_sql_numbered_apart() ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] );

		$rows = Stats_Query::run( $spec )['rows'];

		$this->assertContains( [ 'sql', 42.75 ], $rows );
		$checked = ( new \ReflectionMethod( Stats_Query::class, 'checked' ) )->invoke( null, $spec );
		$sql     = Row_Table_Node::read(
			Flame_Facts_Row_Schema::class,
			static fn ( Row_Reader $reader ): string => ( new \ReflectionMethod( Stats_Query::class, 'statement' ) )->invoke( null, $checked, 'timings', $reader->aliases(), false )[0]
		);
		$this->assertStringContainsString( 't.category_id AS f0', $sql );
	}

	/** A category filter still matches by name in each partition. */
	public function test_a_category_filter_still_matches_by_name(): void {
		$spec = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'url' => [ $this->seed_sql_numbered_apart() ], 'category' => [ 'sql' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] );

		$this->assertSame( [ [ 'sql', 42.75 ] ], Stats_Query::run( $spec )['rows'] );
	}

	public function test_a_url_groups_by_hash_across_partitions_whose_ids_differ(): void {
		$this->seed_request( [ 'url' => 'https://kea.example/egret-1/', 'server' => 'kea.example' ] );
		$this->seed_request( [ 'url' => 'https://kea.example/tui-2/', 'server' => 'kea.example' ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://kea.example/egret-1/', 'server' => 'kea.example' ], partition: 3 );
		$this->seed_request( [ 'url' => 'http://kea.example/plain', 'server' => 'kea.example' ], partition: 3 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'buckets' => $this->last_span() ] ) );

		$expected = [
			[ Url_Parts::hash( 'https://kea.example/egret-1/' ), 'https://kea.example/egret-1/', 2 ],
			[ Url_Parts::hash( 'https://kea.example/tui-2/' ), 'https://kea.example/tui-2/', 1 ],
			[ Url_Parts::hash( 'http://kea.example/plain' ), 'http://kea.example/plain', 1 ],
		];
		\usort( $expected, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );
		$this->assertSame( [ 'url', 'url_name', 'count' ], $r['columns'] );
		$this->assertSame( $expected, $r['rows'], 'url_id 1 is egret in partition 0 and tui in partition 3; rows order by hash' );
	}

	/**
	 * Each partition's `urls` row keeps the first spelling it filed, and a
	 * reader merging partitions names the URL by the least of them, server
	 * and path each on its own: `/` sorts below `h`, so an https path beats
	 * an http one kept whole, and a capital beats its lowercase.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function spellings_across_partitions(): array {
		return [
			'http in one, https in another' => [ 'http://seed.example/Moa-7731/?v=?', 'https://seed.example/moa-7731/?v=?', 'https://seed.example/moa-7731/?v=?' ],
			'two capitalisations'           => [ 'https://seed.example/moa-7731/?v=?', 'https://seed.example/Moa-7731/?v=?', 'https://seed.example/Moa-7731/?v=?' ],
		];
	}

	#[DataProvider( 'spellings_across_partitions' )]
	public function test_a_url_spelled_two_ways_in_two_partitions_reads_as_the_least( string $first, string $second, string $named ): void {
		$this->seed_request( [ 'url' => $first ], partition: 0 );
		$this->seed_request( [ 'url' => $second ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ Log_Manager::url_hash( 'https://seed.example/moa-7731/?v=?' ), $named, 2 ] ], $r['rows'] );
	}

	/**
	 * Each partition groups its own rows, and the outer statement merges the
	 * partials: the answer is the row-level one, from far fewer rows.
	 */
	public function test_a_url_page_merges_per_partition_partials_into_the_row_level_answer(): void {
		foreach ( [ [ 0, 40.5, 200 ], [ 1, 80.5, 503 ], [ 3, 20.0, 200 ], [ 3, 60.5, 404 ] ] as $i => [ $partition, $ms, $status ] ) {
			$this->seed_request( [ 'url' => 'https://seed.example/kokako-7712/', 'ms' => $ms, 'status' => $status, 'peak_mb' => 2.5 + $i ], partition: $partition );
		}
		$this->seed_request( [ 'url' => 'https://seed.example/hihi-7712/', 'ms' => 11.5 ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'measure' => [ 'count', 'timed', 'sum_ms', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'max_peak_mb', 'count_5xx', 'count_4xx' ], 'buckets' => $this->last_span(), 'sort' => 'count', 'order' => 'desc' ] ) );

		$this->assertSame( [ Log_Manager::url_hash( 'https://seed.example/kokako-7712/' ), 'https://seed.example/kokako-7712/', 4, 4, 201.5, 50.375, 20.0, 80.5, 4.0, 5.5, 1, 1 ], $r['rows'][0] );
		$this->assertSame( 11.5, $r['rows'][1][5] );
	}

	/** A percentile or a distinct count reads every partition's rows whole. */
	public function test_a_spec_with_a_percentile_keeps_the_row_level_union(): void {
		foreach ( [ 10.0, 20.0, 30.0, 40.0 ] as $i => $ms ) {
			$this->seed_request( [ 'ms' => $ms ], partition: $i );
		}

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'count', 'p95_ms', 'urls' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 4, 40.0, 1 ] ], $r['rows'] );
	}

	/** The rollup's max and its timed-weighted mean fold across partitions. */
	public function test_an_hourly_max_and_mean_fold_across_partitions(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_hourly( [ 'hour' => $hour, 'count' => 3, 'timed' => 0, 'sum_ms' => 0.0, 'max_ms' => 0.0 ] );
		$this->seed_hourly( [ 'hour' => $hour, 'count' => 2, 'timed' => 2, 'sum_ms' => 90.0, 'max_ms' => 61.5 ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'count', 'max_ms', 'avg_ms' ], 'buckets' => $this->last_hours() ] ) );

		$this->assertSame( 'requests_hourly', $r['table'] );
		$this->assertSame( [ [ 5, 61.5, 45.0 ] ], $r['rows'] );
	}

	/** A group spanning partitions sums, counts and takes the latest across them. */
	public function test_a_group_spanning_partitions_folds_every_request_measure(): void {
		$this->seed_request( [ 'server' => 'kaka.example', 'status' => 302, 'peak_mb' => 3.5, 'finished_at' => self::NOW - 40.5 ] );
		$this->seed_request( [ 'server' => 'kaka.example', 'status' => 301, 'peak_mb' => 6.25, 'finished_at' => self::NOW - 10.25, 'worker' => 1 ], partition: 1 );
		$this->seed_request( [ 'server' => 'kaka.example', 'status' => 204, 'peak_mb' => 1.0, 'finished_at' => self::NOW - 70.0 ], partition: 3 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'sum_peak_mb', 'last_seen', 'count_2xx', 'count_3xx', 'worker' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'kaka.example', 10.75, (float) self::NOW - 10.25, 1, 2, 1 ] ], $r['rows'] );
	}

	/** Either timings table sums a category's partitions before it averages. */
	public function test_timings_fold_a_category_across_partitions(): void {
		foreach ( [ [ 0, 12.5, 3 ], [ 2, 7.5, 5 ] ] as [ $partition, $ms, $calls ] ) {
			$row = [ 'category' => 'ruru-category', 'ms' => $ms, 'calls' => $calls, 'requests' => 2 ];
			$this->seed_site_timing( $row, partition: $partition );
			$this->seed_timing( $row + [ 'url' => 'https://seed.example/ruru-7712/' ], partition: $partition );
		}
		$measure = [ 'ms', 'calls', 'requests', 'avg_ms', 'avg_calls' ];

		$site   = Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'measure' => $measure, 'buckets' => $this->last_span() ] ) );
		$by_url = Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'url', 'category' ], 'measure' => $measure, 'buckets' => $this->last_span() ] ) );

		$this->assertSame( 'timings_site', $site['table'] );
		$this->assertSame( [ [ 'ruru-category', 20.0, 8, 4, 5.0, 2.0 ] ], $site['rows'] );
		$this->assertSame( [ [ Log_Manager::url_hash( 'https://seed.example/ruru-7712/' ), 'https://seed.example/ruru-7712/', 'ruru-category', 20.0, 8, 4, 5.0, 2.0 ] ], $by_url['rows'] );
	}

	/** The owners source sums each owner's site time across partitions, workers out. */
	public function test_owners_sum_site_time_per_owner_leaving_workers_out(): void {
		foreach ( [ 0, 2 ] as $partition ) {
			$this->seed_owner_site_timing( [ 'owner' => 'plugin:tui-7', 'ms' => 31.5, 'calls' => 4, 'requests' => 3 ], partition: $partition );
		}
		$this->seed_owner_site_timing( [ 'owner' => 'theme:moa-9', 'ms' => 12.25, 'calls' => 2, 'requests' => 1 ] );
		$this->seed_owner_site_timing( [ 'owner' => 'plugin:tui-7', 'worker' => 1, 'ms' => 9999.0, 'calls' => 99, 'requests' => 99 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'owners', 'group' => [ 'owner' ], 'filter' => [ 'worker' => [ 0 ] ], 'measure' => [ 'ms', 'calls', 'requests' ], 'buckets' => $this->last_span(), 'sort' => 'ms', 'order' => 'desc' ] ) );

		$this->assertSame( 'owner_timings_site', $r['table'] );
		$this->assertSame( [ [ 'plugin:tui-7', 63.0, 8, 6 ], [ 'theme:moa-9', 12.25, 2, 1 ] ], $r['rows'] );
	}

	/** A URL filter reads the per-URL owner table. */
	public function test_owners_filtered_by_a_url_read_owner_timings(): void {
		$url = 'https://seed.example/weka-3318/';
		$this->seed_owner_timing( [ 'url' => $url, 'owner' => 'plugin:kea-5', 'ms' => 21.75, 'calls' => 5, 'requests' => 2 ], partition: 1 );
		$this->seed_owner_timing( [ 'url' => 'https://seed.example/other/', 'owner' => 'plugin:kea-5', 'ms' => 8888.0 ] );
		$this->seed_owner_site_timing( [ 'owner' => 'plugin:kea-5', 'ms' => 7777.0 ] );
		$spec = self::spec( [ 'source' => 'owners', 'group' => [ 'owner' ], 'filter' => [ 'url' => [ Log_Manager::url_hash( $url ) ] ], 'measure' => [ 'ms', 'calls', 'requests' ], 'buckets' => $this->last_span() ] );

		$r = Stats_Query::run( $spec );

		$this->assertSame( 'owner_timings', $r['table'] );
		$this->assertSame( [ [ 'plugin:kea-5', 21.75, 5, 2 ] ], $r['rows'] );
	}

	/** Only a URL or page, grouped, filtered or searched, leaves the site table. */
	public function test_owners_table_for_follows_the_url_and_page_fields(): void {
		$base = [ 'source' => 'owners', 'measure' => [ 'ms' ], 'buckets' => $this->last_span(), 'now' => self::NOW ];
		$this->assertSame( 'owner_timings_site', Stats_Query::table_for( self::spec( $base + [ 'group' => [ 'owner' ], 'filter' => [ 'worker' => [ 0 ] ] ] ) ) );
		$this->assertSame( 'owner_timings', Stats_Query::table_for( self::spec( $base + [ 'group' => [ 'owner' ], 'filter' => [ 'url' => [ 'abc' ] ] ] ) ) );
		$this->assertSame( 'owner_timings', Stats_Query::table_for( self::spec( $base + [ 'group' => [ 'page', 'owner' ] ] ) ) );
		$this->assertSame( 'owner_timings', Stats_Query::table_for( self::spec( $base + [ 'group' => [ 'owner' ], 'search' => 'weka' ] ) ) );
	}

	/** The per-URL owner table keeps no worker column, so the pair is refused. */
	public function test_owners_refuse_a_worker_filter_beside_a_url(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'holds no worker' );
		Stats_Query::run( self::spec( [ 'source' => 'owners', 'group' => [ 'owner' ], 'filter' => [ 'worker' => [ 0 ], 'url' => [ 'abc' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) );
	}

	/** A field a table lacks is refused naming it. */
	public function test_owners_refuse_a_category_field(): void {
		$this->expectException( \InvalidArgumentException::class );
		Stats_Query::run( self::spec( [ 'source' => 'owners', 'group' => [ 'category' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) );
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

		$asc  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'sort' => 'url_name', 'order' => 'asc', 'buckets' => $this->last_span() ] ) );
		$desc = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'sort' => 'url_name', 'order' => 'desc', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( $named, \array_column( $asc['rows'], 1 ) );
		$this->assertSame( \array_reverse( $named ), \array_column( $desc['rows'], 1 ) );
	}

	public function test_a_url_name_sort_needs_a_url_group(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'url_name' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'url_name', 'buckets' => $this->last_span() ] ) );
	}

	public function test_profiled_groups_and_filters_requests(): void {
		$this->seed_request( [ 'profiled' => 1, 'ms' => 61.5 ] );
		$this->seed_request( [ 'profiled' => 1, 'ms' => 38.5 ], partition: 2 );
		$this->seed_request( [ 'profiled' => 0, 'ms' => 900.0 ] );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'profiled' ], 'measure' => [ 'count', 'sum_ms' ], 'buckets' => $this->last_hours() ] );

		$this->assertSame( 'requests', Stats_Query::table_for( $spec ), 'the hourly rollup keeps no profiled' );
		$this->assertSame( [ [ 0, 1, 900.0 ], [ 1, 2, 100.0 ] ], Stats_Query::run( $spec )['rows'] );
		$this->assertSame( [ [ 2 ] ], Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'profiled' => [ '1' ] ], 'buckets' => $this->last_span() ] ) )['rows'] );
	}

	public function test_a_url_filter_takes_its_hash(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kokako/', 'ms' => 33.0 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/hihi/', 'ms' => 900.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'url' => [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( 'https://seed.example/kokako/' ) ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 33.0 ] ], $r['rows'] );
	}

	public function test_a_url_filter_reads_timings_not_timings_site(): void {
		$url = 'https://seed.example/kiwi-7/';
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'ms' => 42.5, 'calls' => 6, 'requests' => 2 ], partition: 3 );
		$this->seed_timing( [ 'url' => 'https://seed.example/other/', 'category' => 'sql', 'ms' => 7.0 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'ms' => 9999.0, 'calls' => 77, 'requests' => 50 ] );
		$by_url = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'url' => [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( $url ) ] ], 'measure' => [ 'ms', 'calls', 'requests', 'avg_ms', 'avg_calls' ], 'buckets' => $this->last_span() ] );
		$site   = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span(), 'now' => self::NOW ] );

		$this->assertSame( 'timings', Stats_Query::table_for( $by_url ) );
		$this->assertSame( 'category_window', Stats_Query::table_for( $site ) );
		$this->assertSame( [ [ 'sql', 42.5, 6, 2, 21.25, 3.0 ] ], Stats_Query::run( $by_url )['rows'] );
		$this->assertSame( [ [ 'sql', 9999.0 ] ], Stats_Query::run( $site )['rows'] );
	}

	/**
	 * A page groups every case and query shape of one host and path by its
	 * hash, whichever partition filed each URL under whichever id, two of its
	 * URLs sharing one partition, and answers its `page_name` beside it.
	 * Every measure that composes sums by URL id first and still totals the
	 * page exactly; a percentile and the distinct URL count read the rows
	 * whole.
	 */
	public function test_requests_group_by_page_across_partitions(): void {
		$this->seed_request( [ 'url' => 'https://kea.example/Kokako-5521/Times', 'server' => 'kea.example', 'ms' => 30.0 ] );
		$this->seed_request( [ 'url' => 'https://kea.example/tui-5521/', 'server' => 'kea.example', 'ms' => 7.5 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://kea.example/kokako-5521/times?day=?', 'server' => 'kea.example', 'ms' => 50.0 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://kea.example/KOKAKO-5521/TIMES', 'server' => 'kea.example', 'ms' => 10.0, 'status' => 503, 'peak_mb' => 61.5, 'finished_at' => self::NOW - 5.5 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://kea.example/kokako-5521/times', 'server' => 'kea.example', 'ms' => 70.0 ], partition: 2 );
		$kokako  = 'kea.example/kokako-5521/times';
		$tui     = 'kea.example/tui-5521/';
		$opened  = (float) ( Bucket::id( self::NOW ) * Bucket::SECONDS );
		$by_hash = static function ( array $rows ): array {
			\usort( $rows, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );
			return $rows;
		};

		$composed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'page' ], 'measure' => [ 'count', 'timed', 'sum_ms', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'max_peak_mb', 'count_5xx', 'last_seen' ], 'buckets' => $this->last_span() ] ) );
		$whole    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'page' ], 'measure' => [ 'count', 'p50_ms', 'urls' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( 'requests', $composed['table'] );
		$this->assertSame( [ 'page', 'page_name', 'count', 'timed', 'sum_ms', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'max_peak_mb', 'count_5xx', 'last_seen' ], $composed['columns'] );
		$this->assertSame(
			$by_hash(
				[
					[ Log_Manager::url_hash( $kokako ), $kokako, 4, 4, 160.0, 40.0, 10.0, 70.0, 33.375, 61.5, 1, (float) self::NOW - 5.5 ],
					[ Log_Manager::url_hash( $tui ), $tui, 1, 1, 7.5, 7.5, 7.5, 7.5, 24.0, 24.0, 0, $opened ],
				]
			),
			$composed['rows']
		);
		$this->assertSame( $by_hash( [ [ Log_Manager::url_hash( $kokako ), $kokako, 4, 30.0, 2 ], [ Log_Manager::url_hash( $tui ), $tui, 1, 7.5, 1 ] ] ), $whole['rows'], 'three spellings of one URL and a query shape: two URLs' );
	}

	/**
	 * `pages` counts each page once a server, however many shapes, cases and
	 * partitions ask it: a page logged under two spellings of its server is
	 * one page on one server, as the URL table lists it.
	 */
	public function test_pages_counts_each_page_once_a_server(): void {
		$this->seed_request( [ 'url' => 'https://kea.example/Hihi-7718/?a=?', 'server' => 'kea.example' ] );
		$this->seed_request( [ 'url' => 'https://kea.example/hihi-7718/?b=?', 'server' => 'kea.example' ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://kea.example/toutouwai-7718', 'server' => 'kea.example' ], partition: 2 );
		$this->seed_request( [ 'url' => 'https://KEA.example/HIHI-7718/', 'server' => 'KEA.example' ], partition: 3 );

		$site      = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'pages', 'urls' ], 'buckets' => $this->last_span() ] ) );
		$by_server = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'pages' ], 'sort' => 'server', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 2, 4 ] ], $site['rows'] );
		$this->assertSame( [ [ 'kea.example', 2 ] ], $by_server['rows'] );
		$this->assertSame( 'requests', Stats_Query::table_for( self::spec( [ 'source' => 'requests', 'measure' => [ 'pages' ], 'buckets' => $this->last_hours() ] ) ), 'the rollup holds no page' );
	}

	/**
	 * The page-grouped reads whose measures compose, on each source.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function composed_page_groups(): array {
		return [
			'requests'           => [ [ 'source' => 'requests', 'group' => [ 'page' ], 'measure' => [ 'count', 'sum_ms', 'last_seen' ] ] ],
			'requests by bucket' => [ [ 'source' => 'requests', 'group' => [ 'bucket', 'page' ], 'measure' => [ 'count' ] ] ],
			'timings'            => [ [ 'source' => 'timings', 'group' => [ 'page', 'category' ], 'measure' => [ 'ms', 'calls' ] ] ],
		];
	}

	/**
	 * A page group whose measures compose sums each partition's rows by URL
	 * id, then looks each id's page up once, as a `url` group does, rather
	 * than looking a URL up for every row of the window.
	 *
	 * @param array<string,mixed> $over The read.
	 */
	#[DataProvider( 'composed_page_groups' )]
	public function test_a_composed_page_group_sums_by_url_id_before_it_names_the_page( array $over ): void {
		$this->seed_request( [ 'url' => 'https://kea.example/Hoiho-6106/' ] );
		$this->seed_timing( [ 'url' => 'https://kea.example/Hoiho-6106/' ] );

		$plan = self::plan_of( self::spec( $over + [ 'buckets' => $this->last_span() ] ) );

		[ $per_row, $per_id ] = \explode( "\nSCAN g\n", $plan, 2 ) + [ 1 => '' ];
		$this->assertStringNotContainsString( 'SEARCH u ', $per_row, "no row looks its URL up:\n{$plan}" );
		$this->assertStringStartsWith( 'SEARCH u USING INTEGER PRIMARY KEY (rowid=?)', $per_id, "each id looks its URL up once:\n{$plan}" );
	}

	/** A page's name sorts its groups, and only a page group answers one. */
	public function test_a_page_name_sort_orders_by_the_page(): void {
		$pages = [ 'kea.example/weka-6630', 'kea.example/aaa-tui-6630', 'egret.example/moa-6630' ];
		foreach ( $pages as $i => $page ) {
			$this->seed_request( [ 'url' => 'https://' . \strtoupper( $page ), 'server' => \strtok( $page, '/' ) ], partition: $i );
		}
		$by_hash = $pages;
		\usort( $by_hash, static fn ( string $a, string $b ): int => Log_Manager::url_hash( $a ) <=> Log_Manager::url_hash( $b ) );
		$named = [ 'egret.example/moa-6630', 'kea.example/aaa-tui-6630', 'kea.example/weka-6630' ];
		$this->assertNotSame( $named, $by_hash, 'the fixture is void unless hash order differs from name order' );

		$asc  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'page' ], 'sort' => 'page_name', 'order' => 'asc', 'buckets' => $this->last_span() ] ) );
		$desc = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'page' ], 'sort' => 'page_name', 'order' => 'desc', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( $named, \array_column( $asc['rows'], 1 ) );
		$this->assertSame( \array_reverse( $named ), \array_column( $desc['rows'], 1 ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'page_name' );
		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'sort' => 'page_name', 'buckets' => $this->last_span() ] ) );
	}

	/**
	 * A `page` filter takes the page's hash and keeps every URL of that page,
	 * any case or query shape, and a negated one keeps every other; a
	 * timings question naming a page reads `timings`, the table holding URLs.
	 */
	public function test_a_page_filter_keeps_its_urls(): void {
		$upper = 'https://kea.example/Weka-5521/';
		$query = 'https://kea.example/weka-5521/?id=?';
		$other = 'https://kea.example/weka-5521-other/';
		$this->seed_request( [ 'url' => $upper, 'server' => 'kea.example', 'ms' => 20.0 ] );
		$this->seed_request( [ 'url' => $query, 'server' => 'kea.example', 'ms' => 40.0 ], partition: 2 );
		$this->seed_request( [ 'url' => $other, 'server' => 'kea.example', 'ms' => 900.0 ] );
		$this->seed_timing( [ 'url' => $upper, 'server' => 'kea.example', 'category' => 'sql', 'ms' => 12.5 ], partition: 1 );
		$this->seed_timing( [ 'url' => $other, 'server' => 'kea.example', 'category' => 'sql', 'ms' => 99.0 ] );
		$page = Log_Manager::url_hash( 'kea.example/weka-5521/' );

		$kept    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'filter' => [ 'page' => [ $page ] ], 'measure' => [ 'sum_ms' ], 'sort' => 'url_name', 'buckets' => $this->last_span() ] ) );
		$left    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'filter' => [ 'page!' => [ $page ] ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );
		$timings = self::spec( [ 'source' => 'timings', 'group' => [ 'category' ], 'filter' => [ 'page' => [ $page ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] );

		$this->assertSame( [ [ Url_Parts::hash( $upper ), $upper, 20.0 ], [ Url_Parts::hash( $query ), $query, 40.0 ] ], $kept['rows'] );
		$this->assertSame( [ [ Url_Parts::hash( $other ), $other, 900.0 ] ], $left['rows'] );
		$this->assertSame( 'timings', Stats_Query::table_for( $timings ) );
		$this->assertSame( [ [ 'sql', 12.5 ] ], Stats_Query::run( $timings )['rows'] );
	}

	/**
	 * A question naming both a URL and its page joins `urls` once, so its
	 * alias never collides: grouped by both, merged or whole, and listed
	 * under a page filter, where the listing's URL input joins it too.
	 */
	public function test_url_and_page_named_together_join_urls_once(): void {
		$upper = 'https://kea.example/Ruru-5521';
		$lower = 'https://kea.example/ruru-5521?a=?';
		$this->seed_request( [ 'url' => $upper, 'server' => 'kea.example', 'ms' => 10.0 ] );
		$this->seed_request( [ 'url' => $lower, 'server' => 'kea.example', 'ms' => 30.0 ], partition: 1 );
		$this->seed_request( [ 'url' => $lower, 'server' => 'kea.example', 'ms' => 50.0 ], partition: 1 );
		$name = 'kea.example/ruru-5521';
		$page = Log_Manager::url_hash( $name );
		$rows = static function ( float $upper_ms, float $lower_ms ) use ( $page, $name, $upper, $lower ): array {
			$rows = [ [ $page, $name, Url_Parts::hash( $upper ), $upper, $upper_ms ], [ $page, $name, Url_Parts::hash( $lower ), $lower, $lower_ms ] ];
			\usort( $rows, static fn ( array $a, array $b ): int => $a[2] <=> $b[2] );
			return $rows;
		};

		$composed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'page', 'url' ], 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );
		$whole    = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'page', 'url' ], 'filter' => [ 'page' => [ $page ] ], 'measure' => [ 'p50_ms' ], 'buckets' => $this->last_span() ] ) );
		$listed   = Stats_Query::newest_requests( [ 'page' => [ $page ] ], $this->last_span(), 10 );

		$this->assertSame( [ 'page', 'page_name', 'url', 'url_name', 'sum_ms' ], $composed['columns'] );
		$this->assertSame( $rows( 10.0, 80.0 ), $composed['rows'] );
		$this->assertSame( $rows( 10.0, 30.0 ), $whole['rows'] );
		$this->assertSame( 3, \count( $listed ) );
	}

	public function test_site_timings_group_by_worker_entry_and_listener(): void {
		$this->seed_site_timing( [ 'category' => 'init', 'entry' => '', 'worker' => 1, 'listener' => 0, 'ms' => 12.0 ] );
		$this->seed_site_timing( [ 'category' => 'init', 'entry' => 'Kea::boot', 'worker' => 0, 'listener' => 1, 'ms' => 4.5 ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'worker', 'entry', 'listener' ], 'filter' => [ 'category' => [ 'init' ] ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 0, 'Kea::boot', 1, 4.5 ], [ 1, '', 0, 12.0 ] ], $r['rows'] );
	}

	public function test_timings_by_url_hold_no_worker(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'worker' );

		Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'url', 'worker' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) );
	}

	public function test_hour_and_day_group_buckets_by_their_hour_and_utc_day(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 1 ] );
		$this->seed_request( [ 'bucket' => $now ] );
		$this->seed_site_timing( [ 'bucket' => $now - 1, 'ms' => 2.0 ] );
		$this->seed_site_timing( [ 'bucket' => $now, 'ms' => 3.0 ] );

		$requests = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'day', 'hour' ], 'buckets' => $this->last_span() ] ) );
		$timings  = Stats_Query::run( self::spec( [ 'source' => 'timings', 'group' => [ 'hour' ], 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] ) );

		$hour = Bucket::hour( self::NOW );
		$day  = \intdiv( self::NOW, 86400 );
		$this->assertSame( [ [ $day, $hour - 1, 1 ], [ $day, $hour, 1 ] ], $requests['rows'] );
		$this->assertSame( [ [ $hour - 1, 2.0 ], [ $hour, 3.0 ] ], $timings['rows'] );
	}

	public function test_a_search_term_with_fts_syntax_matches_words_only(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kea-or-egret/' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-or-egrets/' ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-egret/' ], partition: 3 );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'search' => 'kea OR "egret*', 'buckets' => $this->last_span() ] );

		$r = Stats_Query::run( $spec );

		$this->assertSame( 'requests', Stats_Query::table_for( $spec ) );
		$this->assertSame( [ [ \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( 'https://seed.example/kea-or-egret/' ), 'https://seed.example/kea-or-egret/', 1 ] ], $r['rows'], 'OR is a word, and a quote or a star no operator' );
	}

	public function test_a_search_narrows_a_measure_with_no_url_group(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/hoiho-41/', 'ms' => 12.0 ], partition: 2 );
		$this->seed_request( [ 'url' => 'https://seed.example/pateke/', 'ms' => 500.0 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'search' => 'HOIHO', 'measure' => [ 'sum_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 12.0 ] ], $r['rows'] );
	}

	public function test_a_search_reads_a_urls_timings(): void {
		$this->seed_timing( [ 'url' => 'https://seed.example/korimako/', 'ms' => 8.25 ] );
		$this->seed_site_timing( [ 'ms' => 600.0 ] );
		$spec = self::spec( [ 'source' => 'timings', 'search' => 'korimako', 'measure' => [ 'ms' ], 'buckets' => $this->last_span() ] );

		$this->assertSame( 'timings', Stats_Query::table_for( $spec ) );
		$this->assertSame( [ [ 8.25 ] ], Stats_Query::run( $spec )['rows'] );
	}

	public function test_a_search_with_no_word_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no word' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'search' => '"* - "', 'buckets' => $this->last_span() ] ) );
	}

	public function test_percentiles_rank_each_groups_timed_requests(): void {
		foreach ( [ 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 1000.0 ] as $i => $ms ) {
			$this->seed_request( [ 'server' => 'kea.example', 'ms' => $ms ], partition: $i % 4 );
		}
		$this->seed_request( [ 'server' => 'kea.example', 'ms' => null, 'error' => 'T' ] );
		$this->seed_request( [ 'server' => 'tui.example', 'ms' => 7.5 ], partition: 1 );
		$this->seed_request( [ 'server' => 'tui.example', 'ms' => null ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'count', 'p50_ms', 'p95_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'kea.example', 11, 50.0, 1000.0 ], [ 'tui.example', 2, 7.5, 7.5 ] ], $r['rows'], 'nearest rank over the timed alone' );
	}

	public function test_a_percentile_with_no_group_ranks_every_row_and_none_timed_is_null(): void {
		$this->seed_request( [ 'ms' => null ] );
		$untimed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'p50_ms' ], 'buckets' => $this->last_span() ] ) );
		$this->seed_request( [ 'ms' => 3.0 ], partition: 3 );
		$this->seed_request( [ 'ms' => 9.0 ], partition: 1 );
		$timed = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'p50_ms', 'p95_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ null ] ], $untimed['rows'] );
		$this->assertSame( [ [ 3.0, 9.0 ] ], $timed['rows'] );
	}

	public function test_p99_is_the_nearest_rank_beyond_p95(): void {
		foreach ( \range( 1, 40 ) as $i ) {
			$this->seed_request( [ 'ms' => $i * 7.5 ], partition: $i % 4 );
		}

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'p95_ms', 'p99_ms' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 285.0, 300.0 ] ], $r['rows'], 'rank 38 and rank 40 of 40' );
	}

	public function test_error_rate_is_exact_across_partitions(): void {
		foreach ( [ 'T', null, null ] as $error ) {
			$this->seed_request( [ 'server' => 'kea.example', 'error' => $error ] );
		}
		foreach ( [ 'F', 'T', null, null, null ] as $error ) {
			$this->seed_request( [ 'server' => 'kea.example', 'error' => $error ], partition: 2 );
		}
		$this->seed_request( [ 'server' => 'tui.example', 'error' => null ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'count', 'errors', 'error_rate' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'kea.example', 8, 3, 0.375 ], [ 'tui.example', 1, 0, 0.0 ] ], $r['rows'], 'not the mean of 1/3 and 2/5' );
	}

	public function test_error_rate_reads_the_hourly_rollup_and_agrees_with_the_rows(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'count' => 6, 'errors' => 1 ] );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'count' => 10, 'errors' => 4 ], partition: 3 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'error_rate' ], 'buckets' => $this->last_hours() ] ) );

		$this->assertSame( 'requests_hourly', $r['table'] );
		$this->assertSame( [ [ 0.3125 ] ], $r['rows'] );
	}

	public function test_error_rate_is_no_top_by_because_it_does_not_add_across_buckets(): void {
		$this->expectExceptionMessage( 'top_by error_rate' );
		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket', 'server' ], 'measure' => [ 'count' ], 'top' => 3, 'top_by' => 'error_rate', 'buckets' => $this->last_span() ] ) );
	}

	public function test_sort_order_limit_and_offset_page_the_rows(): void {
		foreach ( [ 'a.example' => 1, 'b.example' => 4, 'c.example' => 2, 'd.example' => 3 ] as $server => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->seed_request( [ 'server' => $server ], partition: $i % 2 * 3 );
			}
		}

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'count', 'order' => 'desc', 'limit' => 2, 'offset' => 1, 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'd.example', 3 ], [ 'c.example', 2 ] ], $r['rows'] );
	}

	public function test_a_sorted_measure_ranks_null_last_in_either_order(): void {
		$this->seed_request( [ 'server' => 'kea.example', 'ms' => 40.0 ] );
		$this->seed_request( [ 'server' => 'egret.example', 'ms' => null, 'error' => 'T' ], partition: 1 );
		$this->seed_request( [ 'server' => 'weka.example', 'ms' => 90.0 ], partition: 2 );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [ 'avg_ms' ], 'sort' => 'avg_ms', 'buckets' => $this->last_span() ] );

		$this->assertSame( [ 'kea.example', 'weka.example', 'egret.example' ], \array_column( Stats_Query::run( $spec + [ 'order' => 'asc' ] )['rows'], 0 ) );
		$this->assertSame( [ 'weka.example', 'kea.example', 'egret.example' ], \array_column( Stats_Query::run( $spec + [ 'order' => 'desc' ] )['rows'], 0 ) );
	}

	/** Rows a sort ties rank by their groups in the sort's own direction. */
	public function test_a_sorted_url_page_breaks_ties_by_hash_in_the_sorts_direction(): void {
		$hashes = [];
		foreach ( [ 'kakapo-3391', 'hihi-7024', 'toroa-1856' ] as $name ) {
			$url      = "https://seed.example/{$name}/";
			$hashes[] = Log_Manager::url_hash( $url );
			$this->seed_requests( \array_fill( 0, 7, [ 'url' => $url ] ), 2 );
		}
		\sort( $hashes );
		$spec = self::spec( [ 'source' => 'requests', 'group' => [ 'url' ], 'sort' => 'count', 'buckets' => $this->last_span() ] );

		$desc = Stats_Query::run( $spec + [ 'order' => 'desc' ] )['rows'];
		$asc  = Stats_Query::run( $spec + [ 'order' => 'asc' ] )['rows'];

		$this->assertSame( [ 7, 7, 7 ], \array_column( $desc, 2 ), 'the count ties, so the tie is the whole order' );
		$this->assertSame( \array_reverse( $hashes ), \array_column( $desc, 0 ) );
		$this->assertSame( $hashes, \array_column( $asc, 0 ) );
	}

	public function test_with_no_sort_rows_order_by_their_groups(): void {
		$this->seed_request( [ 'server' => 'z.example', 'method' => 'GET' ] );
		$this->seed_request( [ 'server' => 'a.example', 'method' => 'POST' ], partition: 3 );
		$this->seed_request( [ 'server' => 'a.example', 'method' => 'GET' ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server', 'method' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'a.example', 'GET', 1 ], [ 'a.example', 'POST', 1 ], [ 'z.example', 'GET', 1 ] ], $r['rows'] );
	}

	public function test_a_filter_takes_several_values_and_digits_as_integers(): void {
		$this->seed_request( [ 'status' => 301, 'worker' => 1 ] );
		$this->seed_request( [ 'status' => 404 ], partition: 3 );
		$this->seed_request( [ 'status' => 200 ], partition: 3 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'filter' => [ 'status' => [ '301', '404' ], 'worker' => [ '0', '1' ] ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 2 ] ], $r['rows'] );
		$this->assertNull( $r['groups'], 'no top, no tuple count' );
	}

	/**
	 * Under `top` the tuples rank by `top_by` over the whole selection, not
	 * by `count`, and every time row of the top ones answers, rank order
	 * first; `groups` counts every tuple the selection holds.
	 */
	public function test_top_answers_every_bucket_of_the_tuples_top_by_ranks_highest(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_requests( [ [ 'server' => 'kea.example', 'bucket' => $now - 2, 'ms' => 10.0 ], [ 'server' => 'kea.example', 'bucket' => $now - 2, 'ms' => 10.0 ] ] );
		$this->seed_request( [ 'server' => 'kea.example', 'bucket' => $now - 1, 'ms' => 10.0 ], partition: 1 );
		$this->seed_request( [ 'server' => 'egret.example', 'bucket' => $now - 1, 'ms' => 900.0 ], partition: 3 );
		$this->seed_request( [ 'server' => 'tui.example', 'bucket' => $now - 2, 'ms' => 200.0 ], partition: 2 );
		$this->seed_request( [ 'server' => 'tui.example', 'bucket' => $now - 1, 'ms' => 200.0 ] );
		$this->seed_request( [ 'server' => 'weka.example', 'bucket' => $now - 1, 'ms' => 50.0 ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket', 'server' ], 'top' => 2, 'top_by' => 'sum_ms', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ 'bucket', 'server', 'count' ], $r['columns'] );
		$this->assertSame( [ [ $now - 1, 'egret.example', 1 ], [ $now - 2, 'tui.example', 1 ], [ $now - 1, 'tui.example', 1 ] ], $r['rows'] );
		$this->assertSame( 4, $r['groups'] );
	}

	public function test_a_top_tie_breaks_on_the_group_values(): void {
		$this->seed_request( [ 'server' => 'ruru.example', 'ms' => 50.0 ] );
		$this->seed_request( [ 'server' => 'hihi.example', 'ms' => 50.0 ], partition: 3 );
		$this->seed_request( [ 'server' => 'kaka.example', 'ms' => 10.0 ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server', 'bucket' ], 'measure' => [ 'sum_ms' ], 'top' => 1, 'top_by' => 'sum_ms', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'hihi.example', Bucket::id( self::NOW ), 50.0 ] ], $r['rows'] );
		$this->assertSame( 3, $r['groups'] );
	}

	/** A tuple filed by several partitions is one group, ranked whole. */
	public function test_top_counts_a_tuple_across_partitions_once(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_request( [ 'server' => 'kea.example', 'method' => 'GET' ] );
		$this->seed_request( [ 'server' => 'kea.example', 'method' => 'GET' ], partition: 3 );
		$this->seed_request( [ 'server' => 'kea.example', 'method' => 'POST' ], partition: 1 );
		$this->seed_request( [ 'server' => 'tui.example', 'method' => 'GET' ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'hour', 'server', 'method' ], 'top' => 5, 'top_by' => 'count', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ $hour, 'kea.example', 'GET', 2 ], [ $hour, 'kea.example', 'POST', 1 ], [ $hour, 'tui.example', 'GET', 1 ] ], $r['rows'] );
		$this->assertSame( 3, $r['groups'] );
	}

	/**
	 * A tuple's rank is the sum of its buckets' `top_by`, so only a measure
	 * that adds across buckets ranks: a mean, a percentile, an extreme, a
	 * distinct count or a latest stamp would rank by a figure no tuple has.
	 */
	public function test_top_by_a_measure_that_adds_across_buckets_alone(): void {
		foreach ( [ 'requests' => [ 'avg_ms', 'p50_ms', 'p95_ms', 'urls', 'pages', 'max_ms', 'min_ms', 'max_peak_mb', 'avg_peak_mb', 'last_seen', 'worker' ], 'timings' => [ 'avg_ms', 'avg_calls' ] ] as $source => $measures ) {
			foreach ( $measures as $measure ) {
				try {
					Stats_Query::run( self::spec( [ 'source' => $source, 'group' => [ 'bucket', 'server' ], 'measure' => [ 'requests' === $source ? 'count' : 'ms' ], 'top' => 3, 'top_by' => $measure, 'buckets' => $this->last_span() ] ) );
					$this->fail( "refused: {$source} {$measure}" );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( "top_by {$measure}", $e->getMessage() );
					$this->assertStringContainsString( 'adds across buckets', $e->getMessage() );
				}
			}
		}
	}

	public function test_top_by_ranks_by_the_sum_over_the_selection_of_an_additive_measure(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'server' => 'kea.example', 'bucket' => $now - 3, 'error' => 'T', 'ms' => null ] );
		$this->seed_request( [ 'server' => 'kea.example', 'bucket' => $now - 1, 'error' => 'F' ], partition: 2 );
		$this->seed_request( [ 'server' => 'tui.example', 'bucket' => $now - 2, 'error' => 'T', 'ms' => null ], partition: 1 );
		foreach ( [ 0, 1, 3 ] as $partition ) {
			$this->seed_request( [ 'server' => 'weka.example', 'bucket' => $now - 2 ], partition: $partition );
		}

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket', 'server' ], 'measure' => [ 'count', 'errors' ], 'top' => 1, 'top_by' => 'errors', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ $now - 3, 'kea.example', 1, 1 ], [ $now - 1, 'kea.example', 1, 1 ] ], $r['rows'], 'two errors over two buckets outrank one, and three clean requests' );
		$this->assertSame( 3, $r['groups'] );
	}

	public function test_top_over_no_rows_counts_no_groups(): void {
		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket', 'server' ], 'top' => 3, 'top_by' => 'count', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [], $r['rows'] );
		$this->assertSame( 0, $r['groups'] );
	}

	public function test_top_keeps_the_rollup_and_the_site_timings(): void {
		$hour = Bucket::hour( self::NOW );
		$this->seed_hourly( [ 'hour' => $hour - 1, 'status_class' => 5, 'count' => 9 ] );
		$this->seed_hourly( [ 'hour' => $hour - 1, 'status_class' => 2, 'count' => 4 ], partition: 3 );
		$this->seed_hourly( [ 'hour' => $hour - 2, 'status_class' => 3, 'count' => 2 ], partition: 1 );
		$this->seed_site_timing( [ 'category' => 'sql', 'ms' => 40.0 ] );
		$this->seed_site_timing( [ 'category' => 'init', 'ms' => 90.0 ], partition: 2 );
		$hourly  = self::spec( [ 'source' => 'requests', 'group' => [ 'hour', 'status_class' ], 'top' => 2, 'top_by' => 'count', 'buckets' => $this->last_hours() ] );
		$timings = self::spec( [ 'source' => 'timings', 'group' => [ 'bucket', 'category' ], 'measure' => [ 'ms' ], 'top' => 1, 'top_by' => 'ms', 'buckets' => $this->last_span() ] );

		$by_hour = Stats_Query::run( $hourly );
		$by_cat  = Stats_Query::run( $timings );

		$this->assertSame( 'requests_hourly', $by_hour['table'] );
		$this->assertSame( [ [ $hour - 1, 5, 9 ], [ $hour - 1, 2, 4 ] ], $by_hour['rows'] );
		$this->assertSame( 3, $by_hour['groups'] );
		$this->assertSame( 'timings_site', $by_cat['table'] );
		$this->assertSame( [ [ Bucket::id( self::NOW ), 'init', 90.0 ] ], $by_cat['rows'] );
	}

	/** A `top_by` the rollup does not keep reads the request rows. */
	public function test_a_top_by_the_rollup_lacks_reads_requests(): void {
		$this->assertSame( 'requests', Stats_Query::table_for( self::spec( [ 'source' => 'requests', 'group' => [ 'hour', 'server' ], 'top' => 2, 'top_by' => 'p95_ms', 'buckets' => $this->last_hours() ] ) ) );
	}

	public function test_a_top_spec_is_refused_where_it_cannot_rank(): void {
		$base = [ 'source' => 'requests', 'buckets' => $this->last_span(), 'top_by' => 'count', 'top' => 2, 'group' => [ 'bucket', 'server' ] ];
		foreach (
			[
				[ [ 'group' => [ 'server' ] ], 'a time field' ],
				[ [ 'group' => [ 'bucket', 'hour' ] ], 'a non-time field' ],
				[ [ 'limit' => 5 ], 'limit' ],
				[ [ 'offset' => 0 ], 'offset' ],
				[ [ 'sort' => 'count' ], 'sort' ],
				[ [ 'top' => 21 ], 'top 21' ],
				[ [ 'top' => 0 ], 'top 0' ],
				[ [ 'top_by' => 'calls' ], 'calls' ],
				[ [ 'top_by' => null ], 'top_by' ],
				[ [ 'top' => null ], 'top_by count' ],
			] as [ $over, $needle ]
		) {
			try {
				Stats_Query::run( \array_filter( self::spec( $over + $base ), static fn ( mixed $value ): bool => null !== $value ) );
				$this->fail( "refused: {$needle}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $needle, $e->getMessage() );
			}
		}
		$this->assertSame( 20, Stats_Query::MAX_TOP );
	}

	/**
	 * The `performance` CI's answer to `query` and its tokens, through the
	 * JSON wire, which carries a whole float as an integer.
	 */
	private static function query( string ...$tokens ): mixed {
		return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', $tokens );
	}

	/**
	 * `query` reaches back as far as its source keeps rows, the span and an
	 * hour: at a `min_lifetime` of 7,200 seconds, 36 buckets of `requests`,
	 * the three whole hours the hourly rollup answers.
	 */
	public function test_a_query_selects_every_bucket_its_source_keeps(): void {
		$this->use_min_lifetime( 7200 );
		$hour = Bucket::hour( self::NOW );
		$this->seed_hourly( [ 'hour' => $hour - 3, 'count' => 13 ] );
		$this->seed_hourly( [ 'hour' => $hour - 1, 'count' => 4 ], partition: 3 );
		$this->seed_hourly( [ 'hour' => $hour - 4, 'count' => 900 ] );
		$kept = Bucket::key( ( $hour - 3 ) * 12 ) . '..' . Bucket::key( $hour * 12 );

		$reply = self::query( 'requests', 'hour', '--measure=count', "--bucket={$kept}", '--sort=hour' );

		$this->assertSame( 'requests_hourly', $reply['table'] );
		$this->assertSame( [ [ $hour - 3, 13 ], [ $hour - 1, 4 ] ], $reply['rows'] );
		$this->assertSame( $kept, $reply['filters']['bucket'] );
	}

	/** A selection past what its source keeps is refused, naming that bound. */
	public function test_a_query_past_its_sources_retention_is_refused(): void {
		$this->use_min_lifetime( 7200 );
		$latest = Bucket::id( self::NOW );
		foreach ( [ 'requests', 'timings' ] as $source ) {
			$over = Bucket::key( $latest - 36 ) . '..' . Bucket::key( $latest + 1 );

			$reply = self::query( $source, '--measure=' . ( 'requests' === $source ? 'count' : 'ms' ), "--bucket={$over}" );

			$this->assertSame( 'bucket selection holds more than 36 buckets', \trim( Core::as_string( $reply ) ), $source );
		}
	}

	/** The URL table keeps the chart's span whatever the sources keep. */
	public function test_the_url_table_keeps_the_charts_selection(): void {
		$this->use_min_lifetime( 7200 );
		$latest = Bucket::id( self::NOW );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--bucket=' . Bucket::key( $latest - 24 ) . '..' . Bucket::key( $latest + 1 ) ] );

		$this->assertSame( 'bucket selection holds more than 24 buckets', \trim( Core::as_string( $reply ) ) );
	}

	public function test_the_query_verb_spells_buckets_as_keys_and_echoes_its_filters(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 6, 'status' => 503 ] );
		$this->seed_request( [ 'bucket' => $now - 5, 'status' => 204 ], partition: 3 );
		$this->seed_request( [ 'bucket' => $now - 5, 'status' => 302 ], partition: 3 );
		$this->seed_request( [ 'bucket' => $now - 9, 'status' => 204 ] );
		$selection = Bucket::key( $now - 6 ) . '..' . Bucket::key( $now - 4 );

		$reply = self::query( 'requests', 'bucket', '--measure=count', '--measure=max_ms', '--filter=status_class=2', '--filter=status_class=5', "--bucket={$selection}" );

		$this->assertSame(
			[
				'as_of'   => self::NOW,
				'source'  => 'requests',
				'table'   => 'requests',
				'columns' => [ 'bucket', 'count', 'max_ms' ],
				'rows'    => [ [ Bucket::key( $now - 6 ), 1, 100 ], [ Bucket::key( $now - 5 ), 1, 100 ] ],
				'groups'  => null,
				'available' => true,
				'filters' => [
					'filter' => [ 'status_class' => [ '2', '5' ] ],
					'bucket' => $selection,
					'search' => '',
				],
			],
			$reply
		);
	}

	public function test_the_query_verb_reads_the_span_by_default(): void {
		$this->use_min_lifetime( 3900 );
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 13, 'ms' => 1.0 ] );
		$this->seed_request( [ 'bucket' => $now - 12, 'ms' => 2.0 ], partition: 3 );
		$this->seed_request( [ 'bucket' => $now, 'ms' => 4.0 ], partition: 1 );

		$reply = self::query( 'requests', '--measure=sum_ms' );

		$this->assertSame( [ [ 6 ] ], $reply['rows'] );
		$this->assertSame( Bucket::key( $now - 12 ) . '..' . Bucket::key( $now + 1 ), $reply['filters']['bucket'], 'the run closes at the end of the current bucket' );
	}

	public function test_the_query_verb_groups_positionally_and_pages(): void {
		foreach ( [ 'kea.example', 'kea.example', 'tui.example', 'weka.example', 'weka.example', 'weka.example' ] as $i => $server ) {
			$this->seed_request( [ 'server' => $server ], partition: $i % 4 );
		}

		$reply = self::query( 'requests', 'server', '--measure=count', '--sort=count', '--order=desc', '--limit=2', '--offset=1', '--search=seed' );

		$this->assertSame( [ [ 'kea.example', 2 ], [ 'tui.example', 1 ] ], $reply['rows'] );
		$this->assertSame( 'seed', $reply['filters']['search'] );
	}

	public function test_the_query_verb_ranks_under_top_and_answers_groups(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'server' => 'kea.example', 'bucket' => $now - 3, 'ms' => 5.0 ] );
		$this->seed_request( [ 'server' => 'kea.example', 'bucket' => $now - 3, 'ms' => 5.0 ], partition: 3 );
		$this->seed_request( [ 'server' => 'pukeko.example', 'bucket' => $now - 4, 'ms' => 640.0 ], partition: 1 );

		$reply = self::query( 'requests', 'bucket', 'server', '--measure=count', '--top=1', '--top_by=sum_ms' );

		$this->assertSame( [ [ Bucket::key( $now - 4 ), 'pukeko.example', 1 ] ], $reply['rows'] );
		$this->assertSame( 2, $reply['groups'] );
	}

	public function test_the_query_verb_names_the_measures_top_ranks_by(): void {
		$query = \array_values( \array_filter( Performance_CI_Node::node_schema()['commands'], static fn ( array $verb ): bool => 'query' === $verb['name'] ) )[0];
		$top_by = \array_values( \array_filter( $query['args'], static fn ( array $arg ): bool => 'top_by' === $arg['name'] ) )[0];

		foreach ( [ 'count', 'timed', 'sum_ms', 'sum_peak_mb', 'errors', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'ms', 'calls', 'requests' ] as $additive ) {
			$this->assertStringContainsString( "`{$additive}`", $top_by['description'], $additive );
		}
	}

	public function test_the_query_verb_refuses_top_with_a_limit(): void {
		$reply = self::query( 'requests', 'bucket', 'server', '--measure=count', '--top=3', '--top_by=count', '--limit=40' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'limit', $reply );
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
		( new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( self::FACTS_TABLE, 0 ) ) )->exec( 'ALTER TABLE requests RENAME TO requests_moved' );

		$this->expectException( \PDOException::class );
		$this->expectExceptionMessage( 'requests' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );
	}

	/**
	 * The status classes and error kinds a request row is read under are the
	 * ones the builder files its hourly rollup under, so the picker can move
	 * between the two tables without the answer moving.
	 */
	public function test_requests_and_the_hourly_rollup_agree_on_status_class_and_errors(): void {
		$this->assertSame( [ 'T', 'F' ], Stats_Builder_Node::ERROR_KINDS );
		$this->assertSame( [ 100, 599 ], [ Stats_Builder_Node::STATUS_MIN, Stats_Builder_Node::STATUS_MAX ] );
		$sb = $this->armed_stats_builder();
		foreach ( [ [ 99, '-' ], [ 100, 'A' ], [ 599, 'T' ], [ 600, 'F' ], [ null, 'I' ], [ 204, '-' ] ] as $i => [ $status, $error ] ) {
			$this->fill_flame( $sb, $this->completed_request( [ 'rid' => "rid-agree-{$i}", 'status_code' => $status, 'error_status' => $error, 'timestamp' => self::NOW - 4000 ] ) );
		}
		$sb->flush();
		$hour = Bucket::hour( self::NOW - 4000 );
		$spec = [ 'source' => 'requests', 'group' => [ 'status_class' ], 'measure' => [ 'count', 'errors' ] ];

		$raw    = Stats_Query::run( self::spec( $spec + [ 'buckets' => $this->last_span() ] ) );
		$hourly = Stats_Query::run( self::spec( $spec + [ 'buckets' => [ [ $hour * 12, $hour * 12 + 11 ] ] ] ) );

		$this->assertSame( 'requests_hourly', $hourly['table'] );
		$this->assertSame( [ [ 1, 1, 0 ], [ 2, 1, 0 ], [ 5, 1, 1 ], [ 'Unknown', 3, 1 ] ], $raw['rows'] );
		$this->assertSame( $raw['rows'], $hourly['rows'] );
	}

	public function test_an_unknown_measure_is_refused_naming_it(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'mean_wombats' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'mean_wombats' ], 'buckets' => $this->last_span() ] ) );
	}

	public function test_an_unknown_source_is_refused_naming_it(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'burrows' );

		Stats_Query::run( self::spec( [ 'source' => 'burrows', 'buckets' => $this->last_span() ] ) );
	}

	public function test_an_unknown_group_or_filter_field_is_refused_naming_it(): void {
		foreach ( [ [ 'group' => [ 'plumage' ] ], [ 'filter' => [ 'plumage' => [ 'green' ] ] ], [ 'group' => [ 'category' ] ] ] as $over ) {
			try {
				Stats_Query::run( self::spec( $over + [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );
				$this->fail( 'refused: ' . \wp_json_encode( $over ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( \array_key_first( $over['filter'] ?? [] ) ?? $over['group'][0], $e->getMessage() );
			}
		}
	}

	public function test_a_timing_measure_is_not_a_request_measure(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'avg_calls' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'measure' => [ 'avg_calls' ], 'buckets' => $this->last_span() ] ) );
	}

	public function test_a_sort_must_name_an_answered_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'max_ms' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'sort' => 'max_ms', 'buckets' => $this->last_span() ] ) );
	}

	public function test_an_order_is_asc_or_desc(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'sideways' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'order' => 'sideways', 'buckets' => $this->last_span() ] ) );
	}

	public function test_a_limit_or_offset_past_its_bound_is_refused(): void {
		foreach ( [ [ 'limit' => 1001 ], [ 'limit' => 0 ], [ 'offset' => 10001 ], [ 'offset' => -1 ] ] as $over ) {
			try {
				Stats_Query::run( self::spec( $over + [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );
				$this->fail( 'refused: ' . \wp_json_encode( $over ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( (string) \array_key_first( $over ), $e->getMessage() );
			}
		}
	}

	public function test_a_query_names_a_measure(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'measure' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'measure' => [], 'buckets' => $this->last_span() ] ) );
	}

	public function test_no_partition_files_answers_no_rows(): void {
		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ 'count' ], $r['columns'] );
		$this->assertSame( [], $r['rows'] );
		$this->assertTrue( $r['available'], 'no file written yet means no stats, not a stats file that will not open' );
	}

	public function test_a_partition_with_no_file_beside_one_with_rows_answers_their_rows(): void {
		$this->seed_request( [ 'server' => 'tui.example' ], partition: 2 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [ [ 'tui.example', 1 ] ], $r['rows'] );
	}

	public function test_rows_outside_the_buckets_are_not_read(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'bucket' => $now - 31 ] );
		$this->seed_request( [ 'bucket' => $now - 3 ] );
		$this->seed_request( [ 'bucket' => $now - 3 ] );
		$this->seed_request( [ 'bucket' => $now - 1 ] );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'bucket' ], 'buckets' => [ [ $now - 31, $now - 31 ], [ $now - 3, $now - 2 ] ] ] ) );

		$this->assertSame( [ [ $now - 31, 1 ], [ $now - 3, 2 ] ], $r['rows'] );
	}

	public function test_a_stats_file_that_will_not_open_answers_no_rows_and_says_so_once(): void {
		$this->seed_request();
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();
		$logged = \count( Core::$recent_log );

		$first  = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );
		$second = Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );

		$this->assertSame( [], $first['rows'] );
		$this->assertSame( [], $second['rows'] );
		$this->assertFalse( $first['available'] );
		$lines = \array_values( \array_filter( \array_slice( Core::$recent_log, $logged ), static fn ( string $line ): bool => \str_contains( $line, 'performance: stats rows did not open' ) ) );
		$this->assertCount( 1, $lines );
	}

	/** The reader names no table: it reads whichever one its topology declares. */
	public function test_the_rows_are_read_from_the_flame_facts_table_the_topology_declares(): void {
		$this->activate_written( 'kea-ledger-4471', "var num_partitions = 2\nmake_node Row_Table kea-facts-4471 Flame_Facts\n" );
		$this->seed_request( [ 'server' => 'kea.example' ], partition: 1 );

		$r = Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'server' ], 'buckets' => $this->last_span() ] ) );

		$this->assertFileExists( \Newspack_Nodes\Table_Node::file( 'kea-facts-4471', 1 ) );
		$this->assertTrue( $r['available'] );
		$this->assertSame( [ [ 'kea.example', 1 ] ], $r['rows'] );
	}

	public function test_two_flame_facts_tables_are_refused_naming_both(): void {
		$this->activate_written( 'kea-twin-4471', "make_node Row_Table kea-left-4471 Flame_Facts\nmake_node Row_Table kea-right-4471 Flame_Facts\n" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'the active topologies declare 2 Row_Tables of schema Flame_Facts: kea-left-4471, kea-right-4471' );

		Stats_Query::run( self::spec( [ 'source' => 'requests', 'buckets' => $this->last_span() ] ) );
	}

	/**
	 * Activate one topology written here, alone.
	 *
	 * @param string $name Its name.
	 * @param string $tsl  Its body.
	 */
	private function activate_written( string $name, string $tsl ): void {
		$this->stock_topology_dir( 'stats-query-topology-' );
		$this->write_tsl( $name, $tsl );
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ $name ];
		\Newspack_Event_Logger_Nodes\Config::reset();
	}

	public function test_newest_requests_lists_every_partitions_rows_newest_first_up_to_the_limit(): void {
		$now = Bucket::id( self::NOW );
		$url = 'https://seed.example/hihi-3318/';
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-hihi-a', 'bucket' => $now - 6, 'finished_at' => ( $now - 6 ) * 300 + 4.5, 'started_at' => ( $now - 6 ) * 300 - 23, 'segment' => 2, 'offset' => 8817, 'length' => 733, 'status' => 302, 'method' => 'POST', 'ms' => 61.25, 'peak_mb' => 17.5 ], partition: 2 );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-hihi-b', 'bucket' => $now, 'finished_at' => $now * 300 + 9.5, 'status' => null, 'error' => 'T', 'ms' => null, 'peak_mb' => 40.25 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-hihi-c', 'bucket' => $now - 2, 'finished_at' => ( $now - 2 ) * 300 + 1.5 ], partition: 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/other/', 'rid' => 'rid-other', 'bucket' => $now ] );

		$rows = Stats_Query::newest_requests( [ 'url' => [ Log_Manager::url_hash( $url ) ] ], $this->last_span(), 2 );

		$this->assertSame(
			[
				[ 'rid' => 'rid-hihi-b', 'url' => Log_Manager::url_hash( $url ), 'started_at' => $now * 300 - 2, 'finished_at' => $now * 300 + 9.5, 'partition' => 0, 'segment' => null, 'offset' => null, 'length' => null, 'status' => null, 'method' => 'GET', 'error' => 'T', 'ms' => null, 'peak_mb' => 40.25 ],
				[ 'rid' => 'rid-hihi-c', 'url' => Log_Manager::url_hash( $url ), 'started_at' => ( $now - 2 ) * 300 - 2, 'finished_at' => ( $now - 2 ) * 300 + 1.5, 'partition' => 3, 'segment' => null, 'offset' => null, 'length' => null, 'status' => 200, 'method' => 'GET', 'error' => null, 'ms' => 100.0, 'peak_mb' => 24.0 ],
			],
			$rows
		);
		$all = Stats_Query::newest_requests( [ 'url' => [ Log_Manager::url_hash( $url ) ] ], $this->last_span(), 500 );
		$this->assertSame( [ 'rid' => 'rid-hihi-a', 'url' => Log_Manager::url_hash( $url ), 'started_at' => ( $now - 6 ) * 300 - 23, 'finished_at' => ( $now - 6 ) * 300 + 4.5, 'partition' => 2, 'segment' => 2, 'offset' => 8817, 'length' => 733, 'status' => 302, 'method' => 'POST', 'error' => null, 'ms' => 61.25, 'peak_mb' => 17.5 ], $all[2] );
	}

	public function test_a_rids_row_is_read_from_whichever_partition_holds_it(): void {
		$now = Bucket::id( self::NOW );
		$url = 'https://seed.example/ruru-6630/';
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-ruru-6630', 'bucket' => $now - 2015, 'segment' => 4, 'offset' => 5120, 'length' => 377, 'ms' => 88.25 ], partition: 3 );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-ruru-other' ] );

		$found = Stats_Query::request_row( 'rid-ruru-6630' );
		$row   = $found['row'];

		$this->assertTrue( $found['available'] );
		$this->assertSame( [ 'rid-ruru-6630', Log_Manager::url_hash( $url ), 3, 4, 5120, 377, 88.25 ], [ $row['rid'], $row['url'], $row['partition'], $row['segment'], $row['offset'], $row['length'], $row['ms'] ] );
	}

	/** A keyed lookup reads every bucket a row still holds; it invents no window. */
	public function test_a_rids_row_is_found_whatever_its_bucket(): void {
		$this->seed_request( [ 'rid' => 'rid-ruru-aged', 'bucket' => Bucket::id( self::NOW ) - 2500 ] );

		$this->assertSame( 'rid-ruru-aged', Stats_Query::request_row( 'rid-ruru-aged' )['row']['rid'] ?? null );
		$this->assertSame( [ 'available' => true, 'row' => null ], Stats_Query::request_row( 'rid-ruru-never' ) );
	}

	/** An outage is not an absence: the caller can tell them apart. */
	public function test_a_rids_row_from_a_file_that_will_not_open_is_unavailable(): void {
		$this->seed_request( [ 'rid' => 'rid-ruru-dark' ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$this->assertSame( [ 'available' => false, 'row' => null ], Stats_Query::request_row( 'rid-ruru-dark' ) );
	}

	/** A rid filters; it groups nothing and ranks nothing. */
	public function test_a_rid_filters_but_is_not_a_group(): void {
		$this->seed_request( [ 'rid' => 'rid-ruru-6631', 'server' => 'kea.example' ] );
		$this->seed_request( [ 'rid' => 'rid-ruru-6632', 'server' => 'kea.example' ] );

		$listed = Stats_Query::newest_requests( [ 'rid' => [ 'rid-ruru-6632' ] ], $this->last_span(), 5 );

		$this->assertSame( [ 'rid-ruru-6632' ], \array_column( $listed, 'rid' ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown requests field rid' );
		Stats_Query::run( self::spec( [ 'source' => 'requests', 'group' => [ 'rid' ], 'buckets' => $this->last_span() ] ) );
	}

	/** Only a keyed lookup goes without a bucket range. */
	public function test_an_empty_bucket_selection_without_a_rid_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'bucket' );

		Stats_Query::newest_requests( [ 'server' => [ 'kea.example' ] ], [], 5 );
	}

	public function test_newest_requests_reads_only_its_buckets_and_filters(): void {
		$now = Bucket::id( self::NOW );
		$this->seed_request( [ 'rid' => 'rid-pipit-old', 'bucket' => $now - 40, 'error' => 'F' ] );
		$this->seed_request( [ 'rid' => 'rid-pipit-in', 'bucket' => $now - 3, 'error' => 'F' ] );
		$this->seed_request( [ 'rid' => 'rid-pipit-clean', 'bucket' => $now - 3 ] );
		$this->seed_request( [ 'rid' => 'rid-pipit-elsewhere', 'bucket' => $now - 3, 'error' => 'T', 'server' => 'ruru.example' ] );

		$rows = Stats_Query::newest_requests( [ 'server' => [ 'seed.example' ], 'error' => Stats_Builder_Node::ERROR_KINDS ], [ [ $now - 5, $now ] ], 500 );

		$this->assertSame( [ 'rid-pipit-in' ], \array_column( $rows, 'rid' ) );
	}

	public function test_newest_requests_refuses_a_field_the_vocabulary_lacks(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'category' );

		Stats_Query::newest_requests( [ 'category' => [ 'sql' ] ], $this->last_span(), 500 );
	}

	public function test_newest_requests_from_a_file_that_will_not_open_lists_none(): void {
		$this->seed_request();
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$this->assertSame( [], Stats_Query::newest_requests( [], $this->last_span(), 500 ) );
	}
	/**
	 * The ORDER BY a sort takes, over `k` and its tie-break `t`, in each order.
	 *
	 * @return array<string,array{0:string,1:list<list<mixed>>}>
	 */
	public static function rankings(): array {
		return [
			'asc'  => [ 'asc', [ [ 1, 'c' ], [ 2, 'a' ], [ 2, 'b' ], [ null, 'a' ], [ null, 'c' ] ] ],
			'desc' => [ 'desc', [ [ 2, 'b' ], [ 2, 'a' ], [ 1, 'c' ], [ null, 'c' ], [ null, 'a' ] ] ],
		];
	}

	/**
	 * A sort ranks a null last in either order and breaks its ties in its
	 * own direction.
	 *
	 * @param list<list<mixed>> $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'rankings' )]
	public function test_ranking_puts_a_null_last_and_breaks_ties_in_its_direction( string $order, array $expected ): void {
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( "CREATE TABLE r ( k INTEGER, t TEXT ); INSERT INTO r VALUES ( 2, 'b' ), ( NULL, 'a' ), ( 1, 'c' ), ( 2, 'a' ), ( NULL, 'c' )" );

		$rows = $db->query( 'SELECT k, t FROM r ORDER BY ' . Stats_Query::ranking( [ 'k' ], [ 't' ], $order, true ) )->fetchAll( \PDO::FETCH_NUM );

		$this->assertSame( $expected, $rows );
	}

	public function test_a_fold_merges_partials_as_a_query_merges_partitions(): void {
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'CREATE TABLE q ( q_sum_ms REAL, q_timed INTEGER, q_sum_peak_mb REAL, q_count INTEGER ); INSERT INTO q VALUES ( 31.5, 2, 12.25, 4 ), ( 9.75, 1, 3.5, 3 )' );
		[ $avg_ms, $reads ] = Stats_Query::fold( 'requests', 'avg_ms' );
		[ $avg_peak_mb ]    = Stats_Query::fold( 'requests', 'avg_peak_mb' );

		$this->assertSame( [ 'sum_ms', 'timed' ], $reads );
		$this->assertSame( [ [ 13.75, 2.25 ] ], $db->query( "SELECT {$avg_ms}, {$avg_peak_mb} FROM q" )->fetchAll( \PDO::FETCH_NUM ) );
	}

	public function test_a_measure_with_no_fold_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'urls' );

		Stats_Query::fold( 'requests', 'urls' );
	}
}
