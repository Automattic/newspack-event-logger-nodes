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

		$this->assertSame( [], \array_values( \array_diff( [ 'urls', 'agents', 'requests', 'timings', 'timings_site', 'requests_hourly', 'category_window', 'window_expiry', 'urls_fts', 'servers', 'ja4s', 'froms', 'categories', 'entries' ], $opened ) ) );
		$this->assertSame( [ [ 8 ] ], $this->facts( 'PRAGMA user_version' ) );
		$this->assertSame( [ [ 'requests_server' ] ], $this->facts( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'requests_server'" ) );
		$this->assertSame( [ [ 'timings_site_bucket' ] ], $this->facts( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'timings_site_bucket'" ) );
	}

	public function test_v8_declares_the_window_its_expiries_and_the_server_led_keys(): void {
		$tables = self::derived_tables();

		$this->assertSame( 8, Flame_Facts_Row_Schema::version() );
		$this->assertSame( [ 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb', 'profiled', 'error', 'finished_at' ], $tables['requests']['indexes']['requests_cover'] );
		$this->assertSame( [ 'server_id', 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb', 'profiled', 'error' ], $tables['requests']['indexes']['requests_server'] );
		$this->assertArrayNotHasKey( 'requests_server_id', $tables['requests']['indexes'], 'the server-led index serves a server row\'s release' );
		$this->assertSame( [ 'server_id', 'bucket', 'worker', 'category_id', 'entry_id' ], $tables['timings_site']['key'] );
		$this->assertSame( [ 'bucket' ], $tables['timings_site']['indexes']['timings_site_bucket'] );
		$this->assertArrayNotHasKey( 'timings_site_server_id', $tables['timings_site']['indexes'], 'the key leads with server_id' );
		$this->assertSame( [ 'category_id', 'entry_id', 'listener', 'worker', 'ms', 'calls', 'requests', 'last_bucket' ], \array_keys( $tables['category_window']['columns'] ) );
		$this->assertSame( [ 'category_id', 'entry_id', 'worker' ], $tables['category_window']['key'] );
		$this->assertSame( [ 'ms' => 'sum', 'calls' => 'sum', 'requests' => 'sum', 'last_bucket' => 'max' ], $tables['category_window']['add'] );
		$this->assertSame( [ 'last_bucket', Flame_Facts_Row_Schema::TIMINGS_BUCKETS, Bucket::SECONDS ], $tables['category_window']['retain'] );
		$this->assertArrayNotHasKey( 'window_watermark', $tables, 'the newest expiry is the watermark' );
		$this->assertSame( [ 'bucket' ], $tables['window_expiry']['key'] );
		$this->assertSame( 288, Flame_Facts_Row_Schema::WINDOW_BUCKETS );
		$this->assertArrayNotHasKey( 'requests_bucket', $tables['requests']['indexes'] );
	}

	/**
	 * Every table a column names by id is `retained_by` every table holding
	 * that column, and the column leads an index or its table's key.
	 */
	public function test_each_named_table_is_retained_by_every_column_naming_it(): void {
		$tables = self::derived_tables();

		$this->assertSame( [ [ 'requests', 'url_id', 'id' ], [ 'timings', 'url_id', 'id' ] ], $tables['urls']['retained_by'] );
		$this->assertSame( [ [ 'urls', 'server_id', 'id' ], [ 'requests', 'server_id', 'id' ], [ 'timings', 'server_id', 'id' ], [ 'timings_site', 'server_id', 'id' ], [ 'requests_hourly', 'server_id', 'id' ] ], $tables['servers']['retained_by'] );
		$this->assertSame( [ [ 'timings', 'entry_id', 'id' ], [ 'timings_site', 'entry_id', 'id' ], [ 'category_window', 'entry_id', 'id' ] ], $tables['entries']['retained_by'] );
		foreach ( $tables as $table => $spec ) {
			foreach ( \array_intersect_key( Flame_Facts_Row_Schema::NAMED_BY, $spec['columns'] ) as $column => $named ) {
				$leading = [ $spec['key'][0] ?? null, ...\array_column( $spec['indexes'] ?? [], 0 ) ];
				$this->assertContains( $column, $leading, "{$table}.{$column} leads an index or the key" );
			}
		}
	}

	/**
	 * The schema's tables derived afresh: the bootstrap memoizes them before
	 * any test runs, so a test reading the memo never runs the derivation.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function derived_tables(): array {
		( new \ReflectionProperty( Flame_Facts_Row_Schema::class, 'tables' ) )->setValue( null, null );
		return Flame_Facts_Row_Schema::tables();
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
		$old = Bucket::id( self::tick() ) - Flame_Facts_Row_Schema::REQUESTS_BUCKETS - 3;
		$this->seed_request( [ 'server' => 'pipit.example', 'url' => 'https://pipit.example/kahu-31', 'ja4' => 't13d_shared', 'from' => 'pipit@bot.example', 'bucket' => $old ] );
		$this->seed_request( [ 'server' => 'pipit.example', 'url' => 'https://pipit.example/kahu-32', 'ja4' => 't13d_pipit', 'from' => 'pipit@bot.example', 'bucket' => $old ] );
		$this->seed_request( [ 'server' => 'tauhou.example', 'url' => 'https://tauhou.example/kahu-33', 'ja4' => 't13d_shared', 'from' => 'tauhou@bot.example' ] );

		$this->assertSame( 7, $this->purge_facts( 0 ), 'two requests, their two URLs, pipit.example, t13d_pipit and pipit@bot.example' );
		$this->assertSame( [ [ '/kahu-33' ] ], $this->facts( 'SELECT path FROM urls' ) );
		$this->assertSame( [ [ 'tauhou.example' ] ], $this->facts( 'SELECT name FROM servers' ) );
		$this->assertSame( [ [ 't13d_shared' ] ], $this->facts( 'SELECT name FROM ja4s' ) );
		$this->assertSame( [ [ 'tauhou@bot.example' ] ], $this->facts( 'SELECT name FROM froms' ) );
	}

	/** A URL a timing row still names outlives the purge of its last request. */
	public function test_a_url_a_timing_row_names_outlives_its_last_request(): void {
		$this->activate_shipped( 'stats-builder', 1 );
		$url = 'https://pipit.example/riroriro-58';
		$this->seed_request( [ 'server' => 'pipit.example', 'url' => $url, 'bucket' => Bucket::id( self::tick() ) - Flame_Facts_Row_Schema::REQUESTS_BUCKETS - 3 ] );
		$this->seed_timing( [ 'server' => 'pipit.example', 'url' => $url, 'category' => 'hook:riroriro_init', 'ms' => 8.25 ] );

		$this->purge_facts( 0 );

		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ) );
		$this->assertSame( [ [ '/riroriro-58', 8.25 ] ], $this->facts( 'SELECT u.path, t.ms FROM timings t JOIN urls u ON u.id = t.url_id' ) );
	}
}
