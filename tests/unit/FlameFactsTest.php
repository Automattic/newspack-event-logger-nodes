<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;

/**
 * The `flame-facts` Row_Table `Flame_Facts_Row_Schema` declares: the tables
 * and indexes a file opens with, the window's sums, and what a purge
 * releases. StatsBuilderTest covers the rows a request writes.
 */
#[CoversClass( Flame_Facts_Row_Schema::class )]
final class FlameFactsTest extends TestCase {
	use FactsSeeder;

	public function test_a_row_table_opens_every_declared_table_at_the_schema_version(): void {
		Core::$var['partition'] = '0';
		$this->current_window_facts();

		$opened = \array_merge( ...$this->facts( "SELECT name FROM sqlite_master WHERE type = 'table'" ) );

		$this->assertSame( [], \array_values( \array_diff( [ 'urls', 'agents', 'requests', 'timings', 'timings_site', 'requests_hourly', 'category_window', 'window_expiry', 'urls_fts', 'servers', 'ja4s', 'froms', 'categories', 'entries', 'owners', 'owner_timings', 'owner_timings_site', 'pages', 'timings_page' ], $opened ) ) );
		$this->assertSame( [ [ 11 ] ], $this->facts( 'PRAGMA user_version' ) );
		$this->assertSame( [ [ 'requests_server' ] ], $this->facts( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'requests_server'" ) );
		$this->assertSame( [ [ 'timings_site_bucket' ] ], $this->facts( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'timings_site_bucket'" ) );
	}

	/**
	 * A page's timings sum across its URLs into `timings_page`, keyed as
	 * `timings` with the page in the URL's place, the page a `pages` row;
	 * `requests_url` carries every column a page's stats read.
	 */
	public function test_v10_declares_the_page_rollup_and_a_covering_url_index(): void {
		$tables = Flame_Facts_Row_Schema::tables();

		$this->assertSame( 'pages', Flame_Facts_Row_Schema::NAMED_BY['page_id'] );
		$this->assertSame( [ 'id', 'page', 'page_hash' ], \array_keys( $tables['pages']['columns'] ) );
		$this->assertSame( [ 'page_hash' ], $tables['pages']['key'] );
		$this->assertSame( [ [ 'timings_page', 'page_id', 'id' ] ], $tables['pages']['retained_by'] );
		$this->assertSame( [ 'bucket', 'server_id', 'page_id', 'category_id', 'entry_id', 'listener', 'ms', 'calls', 'requests' ], \array_keys( $tables['timings_page']['columns'] ) );
		$this->assertSame( [ 'bucket', 'server_id', 'page_id', 'category_id', 'entry_id' ], $tables['timings_page']['key'] );
		$this->assertSame( $tables['timings']['add'], $tables['timings_page']['add'] );
		$this->assertSame( [ 'timings_page_page' => [ 'page_id', 'bucket' ] ], \array_intersect_key( $tables['timings_page']['indexes'], [ 'timings_page_page' => true ] ) );
		$this->assertSame( $tables['timings']['retain'], $tables['timings_page']['retain'] );
		$this->assertSame( [ 'bucket', 'server_id', 'url_id', 'category_id', 'entry_id', 'listener', 'ms', 'calls', 'requests' ], \array_keys( $tables['timings']['columns'] ) );
		$this->assertSame( [ 'bucket', 'server_id', 'url_id', 'category_id', 'entry_id' ], $tables['timings']['key'] );
		$this->assertSame( [ 'timings_url', 'timings_server_id', 'timings_category_id', 'timings_entry_id' ], \array_keys( $tables['timings']['indexes'] ) );
		$this->assertSame( [ 'url_id', 'bucket' ], $tables['timings']['indexes']['timings_url'] );
		$this->assertSame( [ 'bucket', 'server_id', 'worker', 'category_id', 'entry_id', 'listener', 'ms', 'calls', 'requests' ], \array_keys( $tables['timings_site']['columns'] ) );
		$this->assertSame( [ 'timings_site_bucket', 'timings_site_category_id', 'timings_site_entry_id' ], \array_keys( $tables['timings_site']['indexes'] ) );
		$this->assertSame( [ 'url_id', 'bucket', 'worker', 'server_id', 'ms', 'status', 'peak_mb', 'profiled', 'error', 'finished_at' ], $tables['requests']['indexes']['requests_url'] );
	}

	public function test_the_window_its_expiries_and_the_server_led_keys(): void {
		$tables = Flame_Facts_Row_Schema::tables();

		$this->assertSame( 'owners', Flame_Facts_Row_Schema::NAMED_BY['owner_id'] );
		$this->assertSame( [ 'bucket', 'server_id', 'url_id', 'owner_id', 'ms', 'calls', 'requests' ], \array_keys( $tables['owner_timings']['columns'] ) );
		$this->assertSame( [ 'bucket', 'server_id', 'url_id', 'owner_id' ], $tables['owner_timings']['key'] );
		$this->assertSame( [ 'ms' => 'sum', 'calls' => 'sum', 'requests' => 'sum' ], $tables['owner_timings']['add'] );
		$this->assertSame( [ 'url_id', 'bucket' ], $tables['owner_timings']['indexes']['owner_timings_url'] );
		$this->assertSame( $tables['timings']['retain'], $tables['owner_timings']['retain'] );
		$this->assertSame( [ 'bucket', 'server_id', 'worker', 'owner_id', 'ms', 'calls', 'requests' ], \array_keys( $tables['owner_timings_site']['columns'] ) );
		$this->assertSame( [ 'server_id', 'bucket', 'worker', 'owner_id' ], $tables['owner_timings_site']['key'] );
		$this->assertSame( $tables['timings_site']['retain'], $tables['owner_timings_site']['retain'] );
		$this->assertSame( [ [ 'owner_timings', 'owner_id', 'id' ], [ 'owner_timings_site', 'owner_id', 'id' ] ], $tables['owners']['retained_by'] );
		$this->assertSame( [ 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb', 'profiled', 'error', 'finished_at' ], $tables['requests']['indexes']['requests_cover'] );
		$this->assertSame( [ 'server_id', 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb', 'profiled', 'error' ], $tables['requests']['indexes']['requests_server'] );
		$this->assertArrayNotHasKey( 'requests_server_id', $tables['requests']['indexes'], 'the server-led index serves a server row\'s release' );
		$this->assertSame( [ 'server_id', 'bucket', 'worker', 'category_id', 'entry_id' ], $tables['timings_site']['key'] );
		$this->assertSame( [ 'bucket' ], $tables['timings_site']['indexes']['timings_site_bucket'] );
		$this->assertArrayNotHasKey( 'timings_site_server_id', $tables['timings_site']['indexes'], 'the key leads with server_id' );
		$this->assertSame( [ 'ms' => 'sum', 'calls' => 'sum', 'requests' => 'sum', 'last_bucket' => 'max' ], $tables['category_window']['add'] );
		$this->assertArrayNotHasKey( 'window_watermark', $tables, 'the newest expiry is the watermark' );
		$this->assertSame( [ 'bucket' ], $tables['window_expiry']['key'] );
		$this->assertArrayNotHasKey( 'requests_bucket', $tables['requests']['indexes'] );
	}

	/**
	 * The window keeps each server's sums apart, its key led by the server
	 * as `timings_site`'s is, so a server's leaderboard seeks its own rows.
	 */
	public function test_v11_keys_the_category_window_by_server(): void {
		$tables = Flame_Facts_Row_Schema::tables();

		$this->assertSame( 11, Flame_Facts_Row_Schema::version() );
		$this->assertSame( [ 'server_id', 'category_id', 'entry_id', 'listener', 'worker', 'ms', 'calls', 'requests', 'last_bucket' ], \array_keys( $tables['category_window']['columns'] ) );
		$this->assertSame( [ 'server_id', 'category_id', 'entry_id', 'worker' ], $tables['category_window']['key'] );
		$this->assertContains( [ 'category_window', 'server_id', 'id' ], $tables['servers']['retained_by'] );
		$this->assertArrayNotHasKey( 'category_window_server_id', $tables['category_window']['indexes'], 'the key leads with server_id' );
	}

	/**
	 * Every stats table keeps the span and an hour of slack, read from
	 * `min_lifetime` each time the tables are asked for: 24 buckets and 12 at
	 * 7,200 seconds, `requests_hourly` the 3 hours covering those 36; 36 and
	 * 12 at 10,800, and the 4 hours covering those 48.
	 */
	public function test_each_stats_table_keeps_the_span_and_an_hour_of_slack(): void {
		foreach ( [ 7200 => [ 36, 3 ], 10800 => [ 48, 4 ] ] as $lifetime => [ $buckets, $hours ] ) {
			$this->use_min_lifetime( $lifetime );
			$tables = Flame_Facts_Row_Schema::tables();

			foreach ( [ 'requests', 'timings', 'timings_site', 'timings_page', 'window_expiry' ] as $table ) {
				$this->assertSame( [ 'bucket', $buckets, Bucket::SECONDS ], $tables[ $table ]['retain'], "{$table} at {$lifetime}" );
			}
			$this->assertSame( [ 'last_bucket', $buckets, Bucket::SECONDS ], $tables['category_window']['retain'] );
			$this->assertSame( [ 'hour', $hours, Bucket::HOUR_SECONDS ], $tables['requests_hourly']['retain'] );
		}
	}

	/** A `min_lifetime` short of one bucket declares no tables, naming the key. */
	public function test_a_span_short_of_a_bucket_declares_no_tables(): void {
		$this->use_min_lifetime( 299 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'min_lifetime 299 is below the 300-second floor of one stats bucket' );
		Flame_Facts_Row_Schema::tables();
	}

	/**
	 * Every table a column names by id is `retained_by` every table holding
	 * that column, and the column leads an index or its table's key.
	 */
	public function test_each_named_table_is_retained_by_every_column_naming_it(): void {
		$tables = Flame_Facts_Row_Schema::tables();

		$this->assertSame( [ [ 'requests', 'url_id', 'id' ], [ 'timings', 'url_id', 'id' ], [ 'owner_timings', 'url_id', 'id' ] ], $tables['urls']['retained_by'] );
		$this->assertSame( [ [ 'urls', 'server_id', 'id' ], [ 'requests', 'server_id', 'id' ], [ 'timings', 'server_id', 'id' ], [ 'timings_site', 'server_id', 'id' ], [ 'timings_page', 'server_id', 'id' ], [ 'owner_timings', 'server_id', 'id' ], [ 'owner_timings_site', 'server_id', 'id' ], [ 'category_window', 'server_id', 'id' ], [ 'requests_hourly', 'server_id', 'id' ] ], $tables['servers']['retained_by'] );
		$this->assertSame( [ [ 'timings', 'entry_id', 'id' ], [ 'timings_site', 'entry_id', 'id' ], [ 'timings_page', 'entry_id', 'id' ], [ 'category_window', 'entry_id', 'id' ] ], $tables['entries']['retained_by'] );
		foreach ( $tables as $table => $spec ) {
			foreach ( \array_intersect_key( Flame_Facts_Row_Schema::NAMED_BY, $spec['columns'] ) as $column => $named ) {
				$leading = [ $spec['key'][0] ?? null, ...\array_column( $spec['indexes'] ?? [], 0 ) ];
				$this->assertContains( $column, $leading, "{$table}.{$column} leads an index or the key" );
			}
		}
	}

	public function test_a_window_row_folds_its_sums_and_keeps_its_latest_bucket(): void {
		$this->activate_shipped( 'stats-builder', 1 );
		$this->seed_rows(
			0,
			[
				[ 'add', 'category_window', self::window_row( 'hook:kea_init', 17.5, 3, 1, 5_966_101 ) ],
				[ 'add', 'category_window', self::window_row( 'hook:kea_init', 4.25, 2, 1, 5_966_099 ) ],
			]
		);

		$rows = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT ms, calls, requests, last_bucket FROM p0.category_window', [] ) );

		$this->assertSame( [ [ 21.75, 5, 2, 5_966_101 ] ], $rows );
	}

	/**
	 * Purging a URL's last request releases the URL, then the server only it
	 * named, then the JA4 and the From no other request names; a JA4 a
	 * newer request names stays.
	 */
	public function test_purging_a_urls_last_request_cascades_to_the_names_only_it_named(): void {
		$this->activate_shipped( 'stats-builder', 1 );
		$old = Bucket::id( self::tick() ) - Bucket::window() - Bucket::RETAIN_SLACK - 3;
		$this->seed_request( [ 'server' => 'pipit.example', 'url' => 'https://pipit.example/kahu-31', 'ja4' => 't13d_shared', 'from' => 'pipit@bot.example', 'bucket' => $old ] );
		$this->seed_request( [ 'server' => 'pipit.example', 'url' => 'https://pipit.example/kahu-32', 'ja4' => 't13d_pipit', 'from' => 'pipit@bot.example', 'bucket' => $old ] );
		$this->seed_request( [ 'server' => 'tauhou.example', 'url' => 'https://tauhou.example/kahu-33', 'ja4' => 't13d_shared', 'from' => 'tauhou@bot.example' ] );

		$this->assertSame( 7, $this->purge_facts( 0 ), 'two requests, their two URLs, pipit.example, t13d_pipit and pipit@bot.example' );
		$this->assertSame( [ [ '/kahu-33' ] ], $this->facts( 'SELECT path FROM urls' ) );
		$this->assertSame( [ [ 'tauhou.example' ] ], $this->facts( 'SELECT name FROM servers' ) );
		$this->assertSame( [ [ 't13d_shared' ] ], $this->facts( 'SELECT name FROM ja4s' ) );
		$this->assertSame( [ [ 'tauhou@bot.example' ] ], $this->facts( 'SELECT name FROM froms' ) );
	}

	/** Purging a page's last timing row releases its `pages` row; a newer page's stays. */
	public function test_purging_a_pages_last_timing_releases_the_page(): void {
		$this->activate_shipped( 'stats-builder', 1 );
		$old = Bucket::id( self::tick() ) - Bucket::window() - Bucket::RETAIN_SLACK - 3;
		$this->seed_timing( [ 'server' => 'pipit.example', 'url' => 'https://pipit.example/kahu-61?q=1', 'bucket' => $old, 'ms' => 4.5 ] );
		$this->seed_timing( [ 'server' => 'pipit.example', 'url' => 'https://pipit.example/tieke-62', 'ms' => 6.75 ] );

		$this->purge_facts( 0 );

		$this->assertSame( [ [ 'pipit.example/tieke-62', 6.75 ] ], $this->facts( 'SELECT p.page, t.ms FROM timings_page t JOIN pages p ON p.id = t.page_id' ) );
		$this->assertSame( [ [ 'pipit.example/tieke-62' ] ], $this->facts( 'SELECT page FROM pages' ) );
	}

	/** A URL a timing row still names outlives the purge of its last request. */
	public function test_a_url_a_timing_row_names_outlives_its_last_request(): void {
		$this->activate_shipped( 'stats-builder', 1 );
		$url = 'https://pipit.example/riroriro-58';
		$this->seed_request( [ 'server' => 'pipit.example', 'url' => $url, 'bucket' => Bucket::id( self::tick() ) - Bucket::window() - Bucket::RETAIN_SLACK - 3 ] );
		$this->seed_timing( [ 'server' => 'pipit.example', 'url' => $url, 'category' => 'hook:riroriro_init', 'ms' => 8.25 ] );

		$this->purge_facts( 0 );

		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ) );
		$this->assertSame( [ [ '/riroriro-58', 8.25 ] ], $this->facts( 'SELECT u.path, t.ms FROM timings t JOIN urls u ON u.id = t.url_id' ) );
	}
}
