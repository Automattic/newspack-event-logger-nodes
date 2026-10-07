<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Event_Logger_Nodes\Url_Sketch;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

#[CoversClass( Stats_Store::class )]
class StatsStoreTest extends TestCase {

	/** The Tables' declared window is the one make_store() builds with. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']['newspack_nodes_min_lifetime'] = 86400;
	}

	public function test_a_bucket_round_trips_through_its_own_sqlite_table(): void {
		$store  = $this->stats_store( 3, 86400 );
		$bucket = Stats_Store::bucket_key( self::tick() );
		$this->assertTrue( $this->set_hour_slot( $store, Stats_Store::hourly_parts(), $bucket, [ 'count' => 4127, 'sum_ms' => 3.0, 'sum_peak_mb' => 2.0 ] ) );
		$this->assertSame( 4127, $this->get_hour_slot( $store, Stats_Store::hourly_parts(), $bucket )['count'] );
		$this->assertFileExists( \Newspack_Nodes\Bootstrap::base_dir() . '/tables/flame-stats:aggregate.p3.sqlite' );
		$this->assertTrue( $this->set_url_stats( $store, 'c0ffee7731ab', [ 'flame' => [ 'count' => 9 ] ] ) );
		$this->assertSame( 9, $store->url_aggregate( 'c0ffee7731ab' )['flame']['count'], 'url: reads its own Table' );
		$this->assertSame( [ 'url:c0ffee7731ab' ], $this->asked_keys( Stats_Store::NS_URL ) );
	}

	/** A store given a subset of the Tables refuses a namespace outside it by name. */
	public function test_a_namespace_outside_the_tables_a_store_was_given_throws(): void {
		[ $window, $client, $names ] = $this->stats_store_args( 3, 86400 );
		$store = new Stats_Store( $window, $client, [ Stats_Store::TABLE_AGGREGATE => $names[ Stats_Store::TABLE_AGGREGATE ] ] );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'namespace url lives in Table flame-stats:url, which this store was not given (it holds flame-stats:aggregate)' );
		$store->url_aggregate( 'c0ffee7731ab' );
	}

	public function test_a_broken_table_reads_as_unanswered_never_as_empty(): void {
		$store  = $this->stats_store( 3, 86400 );
		$bucket = Stats_Store::bucket_key( self::tick() );
		$this->break_stats_table( Stats_Store::TABLE_AGGREGATE, 3 );
		$store->bucket_get_multi( [ [ Stats_Store::hourly_parts(), $bucket ] ], $failed );
		$this->assertTrue( $failed );
	}

	public function test_the_row_index_tables_cover_every_index_contiguously(): void {
		// `fold_index_row()` reads ROW_FIELD_NAMES[ $index ] unguarded, per
		// row, on the hot read path, and the two tables are hand-matched.
		// Decision 18's "the nine that ADD come FIRST" is otherwise only
		// prose: a tenth summed field appended past the end works and
		// falsifies it silently. The path is the one string, and it is last.
		$this->assertSame( \range( 0, 14 ), \array_keys( Stats_Store::ROW_FIELD_NAMES ) );
		$this->assertSame( \range( 0, 8 ), \array_keys( Stats_Store::ROW_SUMS ) );
		$this->assertSame( 'path', Stats_Store::ROW_FIELD_NAMES[ Stats_Store::ROW_PATH ] );
	}

	public function test_a_row_path_drops_the_https_origin_of_its_own_server(): void {
		// The server is in the KEY, so a value repeating it pays for nothing.
		// Only an https origin naming the row's own server is implied: any
		// other scheme, or a host the key does not name, is kept whole, so a
		// reader rebuilding `https://{server}{path}` never shows a wrong URL.
		$this->assertSame( '/kakapo-4417?q=9', Stats_Store::row_path( 'https://kea.test/kakapo-4417?q=9', 'kea.test' ) );
		$this->assertSame( 'http://kea.test/kakapo-4417', Stats_Store::row_path( 'http://kea.test/kakapo-4417', 'kea.test' ) );
		$this->assertSame( 'https://moa.test/weka-1308', Stats_Store::row_path( 'https://moa.test/weka-1308', 'kea.test' ) );
		$this->assertSame( 'https://kea.test?q=3', Stats_Store::row_path( 'https://kea.test?q=3', 'kea.test' ), 'no path to stand alone' );
	}

	public function test_a_row_path_is_capped_with_an_ellipsis(): void {
		// The hash is the identity and the path is display, so a query-string
		// flood cannot grow one row past the cap.
		$long = 'https://kea.test/' . \str_repeat( 'takahe-', 400 );
		$path = Stats_Store::row_path( $long, 'kea.test' );
		$this->assertSame( Stats_Store::MAX_PATH_BYTES, \strlen( $path ) );
		$this->assertStringEndsWith( '…', $path );
		$this->assertStringStartsWith( '/takahe-takahe-', $path );
		$this->assertSame( '/short-5521', Stats_Store::row_path( 'https://kea.test/short-5521', 'kea.test' ) );
	}

	public function test_the_category_entry_is_positional_and_contiguous(): void {
		// The same mechanism for the second value decision 18 governs: every
		// CAT_SUMS field ADDS, so the table is the whole index set, and a
		// fourth field appended past the end has to move the assertion.
		$this->assertSame( \range( 0, 2 ), \array_keys( Stats_Store::CAT_SUMS ) );
	}

	public function test_the_dimensional_entry_is_positional_and_contiguous(): void {
		// Decision 18's THIRD positional value, and the one its reopen clause
		// had queued. Every DIM_SUMS field ADDS, so the table is the whole
		// index set and a fifth field appended past the end moves this.
		$this->assertSame( \range( 0, 3 ), \array_keys( Stats_Store::DIM_SUMS ) );
	}

	public function test_a_merged_entry_keeps_only_what_its_field_table_names(): void {
		// The positional switch is the first change to depend on the stated
		// invariant, and it was not true: the merged entry kept `$into`'s own
		// keys too. A pre-deploy `{t,c,n}` entry summed with a positional one
		// became a six-key hybrid that `json_encode` emits as an OBJECT —
		// larger than either shape, and re-written that way for the bucket's
		// life. The old shape is DISCARDED, never translated.
		$merged = Stats_Store::sum_fields(
			[ 'zither render' => [ 't' => 7.5, 'c' => 61, 'n' => 3 ] ],
			[ 'zither render' => self::cat_entry( 2.25, 4, 1 ) ],
			Stats_Store::CAT_SUMS
		);

		$this->assertSame( '{"zither render":[2.25,4,1]}', \wp_json_encode( $merged ) );
	}

	public function test_the_url_index_is_sharded_by_url_hash(): void {
		// One blob per bucket was what every cap in this schema was defending:
		// the whole thing is read-modify-written on each five-second flush and
		// unserialized whole on each poll, so rows and splits both
		// competed for one item's budget.
		$store = $this->stats_store( partition: 2 );
		$hash  = 'a1b2c3d4e5f6';
		$other = '0f0f0f0f0f0f';

		$this->set_url_bucket( $store, '2026-08-14-12-05', [
			$hash  => [ 'url' => 'https://example.com/a', 'count' => 3 ],
			$other => [ 'url' => 'https://example.com/b', 'count' => 5 ],
		] );

		$srv = Stats_Store::server_key( self::SEED_SERVER );
		$this->assertSame(
			[ $hash => [ 'count' => 3, 'path' => '/a' ] ],
			self::named_url_rows( \Newspack_Nodes\Core::arr( $this->stats_table_value( Stats_Store::TABLE_URL_FINE, 2, Stats_Store::NS_URLS . ":2026-08-14-12-05:{$srv}:a" ) ) ),
			'a row lands in its server\'s shard its hash names'
		);
		$this->assertSame(
			[ $other => [ 'count' => 5, 'path' => '/b' ] ],
			self::named_url_rows( \Newspack_Nodes\Core::arr( $this->stats_table_value( Stats_Store::TABLE_URL_FINE, 2, Stats_Store::NS_URLS . ":2026-08-14-12-05:{$srv}:0" ) ) )
		);
	}

	public function test_the_url_index_keys_by_server_and_the_index_names_them(): void {
		// One server's rows per key: a busy spoke cannot fold a quiet one's
		// URLs into `Other`, and a scoped read is its own keys. The index is
		// what an unscoped read enumerates, and each pair says whose it is.
		$store  = $this->stats_store();
		$bucket = '2026-08-14-12-05';
		$this->set_url_bucket( $store, $bucket, [ 'a1b2c3d4e5f6' => [ 'url' => 'https://kea.test/kea-41', 'count' => 41 ] ], 'kea.test' );
		$this->set_url_bucket( $store, $bucket, [ 'a9a9a9a9a9a9' => [ 'url' => 'https://moa.test/moa-6', 'count' => 6 ] ], 'moa.test' );

		$this->assertSame(
			[ $bucket => self::index_of( [ 'kea.test', 'moa.test' ], [ 'a' ] ) ],
			$store->server_index( [], [ $bucket, '2026-08-14-12-00' ] ),
			'each server beside the one shard its rows fell in'
		);

		$counts = static function ( array $sources ): array {
			$out = [];
			foreach ( $sources as [ , $rows, $server ] ) {
				foreach ( $rows as $hash => $row ) {
					$out[ $server ][ $hash ] = $row[ Stats_Store::ROW_COUNT ];
				}
			}
			return $out;
		};
		$this->assertSame(
			[ 'kea.test' => [ 'a1b2c3d4e5f6' => 41 ], 'moa.test' => [ 'a9a9a9a9a9a9' => 6 ] ],
			$counts( $store->url_row_sources( [ $bucket ], 'a' ) ),
			'an unscoped read reaches every server the index names'
		);

		$this->forget_stats_asks();
		$this->assertSame(
			[ 'moa.test' => [ 'a9a9a9a9a9a9' => 6 ] ],
			$counts( $store->url_row_sources( [ $bucket ], 'a', false, 'moa.test' ) ),
			'a scoped read is that server\'s keys alone'
		);
		$this->assertSame( 2, $this->stats_reads(), 'the index, then the shards it names' );
	}

	public function test_the_whole_window_is_still_one_round_trip(): void {
		// Decision 1: "one `lookup_multi` serves them all"; decision 6: per-key
		// gets are the latency cliff a dashboard read exists to avoid. Sharding
		// multiplies KEYS, which memcached is built for — it must not multiply
		// ROUND TRIPS, which is what a loop of multi-gets per shard would do.
		$store = $this->stats_store();
		$this->set_url_bucket( $store, '2026-08-14-12-05', [
			'a1b2c3d4e5f6' => [ 'url' => 'https://example.com/a', 'count' => 3 ],
			'0f0f0f0f0f0f' => [ 'url' => 'https://example.com/b', 'count' => 5 ],
		] );

		$this->forget_stats_asks();
		$sources         = $store->url_row_sources( [ '2026-08-14-12-05', '2026-08-14-12-00' ] );

		// The server index is the one read the keyspace adds before it.
		$this->assertSame( 2, $this->stats_reads(), 'the index, then every server\'s every shard in one round trip' );
		$this->assertNotEmpty( $sources );
	}

	public function test_writing_a_whole_bucket_replaces_the_whole_bucket(): void {
		// "Whole bucket, replacing what is stored" has to mean every shard, not
		// only the ones the new data names — otherwise a second seed leaves the
		// first one's rows live in a shard it never mentioned, and a test reads
		// both as if they arrived together.
		$store = $this->stats_store();
		$this->set_url_bucket( $store, '2026-08-14-12-05', [ 'a1b2c3d4e5f6' => [ 'url' => 'https://example.com/a', 'count' => 3 ] ] );

		$this->set_url_bucket( $store, '2026-08-14-12-05', [ '0f0f0f0f0f0f' => [ 'url' => 'https://example.com/b', 'count' => 5 ] ] );

		$this->assertSame( [], $this->get_url_shard( $store, '2026-08-14-12-05', 'a' ) );
		$this->assertSame(
			[ '0f0f0f0f0f0f' => [ 'count' => 5, 'path' => '/b' ] ],
			self::named_url_rows( $this->get_url_shard( $store, '2026-08-14-12-05', '0' ) )
		);
	}

	public function test_the_read_plan_splits_the_window_into_fine_buckets_and_hours(): void {
		// No hour key covers the current hour yet, so it is read in buckets;
		// every hour before it reads as its hour key: 8 + 24 keys per shard
		// at :37 rather than 289.
		$now  = \gmmktime( 14, 37, 0, 8, 27, 2026 );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) );

		$this->assertCount( 8, $plan['fine'] );
		$this->assertSame( '2026-08-27-14-35', $plan['fine'][0], 'newest first, floored to the width' );
		$this->assertSame( '2026-08-27-14-00', \end( $plan['fine'] ) );
		// The hours behind them, newest first, the current hour not among
		// them, or its traffic would be counted twice.
		$this->assertSame( '2026-08-27-13', $plan['hours'][0] );
		$this->assertSame( 23, \count( $plan['hours'] ) );
		// The window starts on the hour: the oldest hour it holds only part
		// of is not read, so every hour read is whole and exact.
		$this->assertSame( '2026-08-26-15', \end( $plan['hours'] ) );
	}

	/** At 11:07 a twelve-hour window reads hour 00 whole, and none of 23. */
	public function test_the_window_starts_on_the_hour(): void {
		$now  = \gmmktime( 11, 7, 0, 9, 29, 2026 );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 43200, $now ) );

		$this->assertSame( '2026-09-29-00', \end( $plan['hours'] ) );
		$this->assertNotContains( '2026-09-28-23', $plan['hours'] );
		$this->assertSame( \gmmktime( 0, 0, 0, 9, 29, 2026 ), Stats_Store::window_start( 43200, $now ) );
	}

	/**
	 * The plan must COVER the window from its first whole hour on. Reading it
	 * at two resolutions is the point; reading part of it at neither is a
	 * hole, and a hole here is traffic missing from every `urls` and
	 * `dump_url` answer — silently, and by an amount that breathes with the
	 * clock. Nor may it read past the window: the hours it reads are whole.
	 */
	public function test_the_read_plan_covers_every_bucket_in_the_window(): void {
		// Every minute of an hour, because the hole is a function of the
		// minute: none at :00, and eleven buckets of it at :59.
		foreach ( [ 0, 4, 7, 22, 37, 44, 55, 59 ] as $minute ) {
			$now    = \gmmktime( 14, $minute, 0, 8, 27, 2026 );
			$window = Stats_Store::retention_buckets( 86400, $now );
			$plan   = Stats_Store::read_plan( $window );

			$read = $plan['fine'];
			foreach ( $plan['hours'] as $hour ) {
				$read = \array_merge( $read, Stats_Store::buckets_in_hour( $hour ) );
			}
			$first = Stats_Store::bucket_key( Stats_Store::window_start( 86400, $now ) );
			$this->assertSame(
				[],
				\array_values( \array_filter( \array_diff( $window, $read ), static fn ( string $bucket ): bool => $bucket >= $first ) ),
				"buckets in the window that no tier reads, at :{$minute}"
			);
			$this->assertSame( [], \array_values( \array_diff( $read, $window ) ), "buckets read past the window, at :{$minute}" );
		}
	}

	public function test_an_hour_names_the_twelve_buckets_it_covers(): void {
		$buckets = Stats_Store::buckets_in_hour( '2026-08-27-13' );

		$this->assertCount( 12, $buckets );
		$this->assertSame( '2026-08-27-13-00', $buckets[0] );
		$this->assertSame( '2026-08-27-13-55', \end( $buckets ) );
	}

	public function test_hour_sources_read_the_coarse_tier_in_one_round_trip(): void {
		$store = $this->stats_store();
		$this->set_url_hour( $store, '2026-08-27-13', 'a', [ 'a1b2c3d4e5f6' => [ 'url' => 'https://example.com/a', 'count' => 9 ] ] );

		$this->forget_stats_asks();
		$sources         = $store->url_hour_sources( [ '2026-08-27-13', '2026-08-27-12' ], 'a' );

		$this->assertSame( 2, $this->stats_reads(), 'the hour index, then the rows' );
		$this->assertSame( [ [ '2026-08-27-13', [ 'a1b2c3d4e5f6' => [ 'url' => 'https://example.com/a', 'count' => 9 ] ], self::SEED_SERVER ] ], $sources );
	}

	public function test_reading_the_whole_index_merges_every_shard(): void {
		$store = $this->stats_store();
		$this->set_url_bucket( $store, '2026-08-14-12-05', [
			'a1b2c3d4e5f6' => [ 'url' => 'https://example.com/a', 'count' => 3 ],
			'0f0f0f0f0f0f' => [ 'url' => 'https://example.com/b', 'count' => 5 ],
		] );

		$rows = $this->url_rows_by_bucket( $store, [ '2026-08-14-12-05' ] )['2026-08-14-12-05'];

		$this->assertCount( 2, $rows );
		$this->assertSame( 3, $rows['a1b2c3d4e5f6']['count'] );
		$this->assertSame( 5, $rows['0f0f0f0f0f0f']['count'] );
	}

	public function test_a_readers_mount_reads_what_the_store_wrote(): void {
		// A value the store writes must be readable by a reader that mounts
		// the declared Table's file on its own, under the namespace-first key.
		$store = $this->stats_store( partition: 3 );

		$this->assertTrue( $this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-08-14-12-35', [ 'count' => 7391 ] ) );

		$this->assertSame(
			[ 7 => [ 'count' => 7391 ] ],
			$this->stats_table_value( Stats_Store::TABLE_AGGREGATE, 3, Stats_Store::NS_HOURLY_HOUR . ':2026-08-14-12' ),
			'the reader\'s mount reads back exactly what Stats_Store wrote'
		);
	}

	public function test_every_read_is_empty_when_no_table_answers(): void {
		// Fail-soft is the contract: a Table that does not answer reads as
		// nothing and writes nothing, and nothing throws (decision 3).
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->set_url_stats( $store, 'abc', [ 'count' => 41 ] );
		$this->break_stats_tables( 0 );

		$this->assertSame( [], $this->get_hour_slot( $store, Stats_Store::hourly_parts(), 'x' ) );
		$this->assertSame( [], $this->url_rows_by_bucket( $store, [ 'b1', 'b2' ] ) );
		$this->assertNull( $store->url_aggregate( 'abc' ) );
		$this->assertFalse( $this->set_hour_slot( $store, Stats_Store::hourly_parts(), 'x', [ 'count' => 1 ] ), 'a write no Table took reports false' );
	}

	/** Break every stats Table of one partition. */
	private function break_stats_tables( int $partition ): void {
		foreach ( Stats_Store::TABLES as $table ) {
			$this->break_stats_table( $table, $partition );
		}
	}

	/** Decision 1: the namespace is the key's first segment, and the store is what reads it. */
	public function test_namespace_of_reads_the_keys_first_segment(): void {
		$this->assertSame( Stats_Store::NS_LB_HOUR, Stats_Store::namespace_of( Stats_Store::NS_LB_HOUR . ':2026-02-03-04' ) );
		$this->assertSame( 'urls', Stats_Store::namespace_of( 'urls:0a1b2c3d:7:2026-02-03-04-05' ) );
		$this->assertSame( 'hourly_h', Stats_Store::namespace_of( 'hourly_h' ) );
	}

	public function test_namespace_constants_exist(): void {
		$this->assertSame( 'hourly_h', Stats_Store::NS_HOURLY_HOUR );
		$this->assertSame( 'lb_h', Stats_Store::NS_LB_HOUR );
		$this->assertSame( 'lb_sh', Stats_Store::NS_LB_S_HOUR );
		$this->assertSame( 'urls', Stats_Store::NS_URLS );
		$this->assertSame( 'url', Stats_Store::NS_URL );
		$this->assertSame( 'dim_h', Stats_Store::NS_DIM_HOUR );
		$this->assertSame( 'url_dim_h', Stats_Store::NS_URL_DIM_HOUR );
		$this->assertSame( 'categories_h', Stats_Store::NS_CAT_HOUR );
		$this->assertSame( 'url_cat_h', Stats_Store::NS_URL_CAT_HOUR );
	}

	public function test_caps_constants(): void {
		$this->assertSame( 50, Stats_Store::MAX_CAT_VALUES );
		$this->assertSame( 20, Stats_Store::MAX_DIM_VALUES );
		$this->assertSame( 10, Stats_Store::MAX_URL_DIM_VALUES );
	}

	public function test_url_aggregate_round_trip(): void {
		$store = $this->stats_store();
		$this->assertNull( $store->url_aggregate( 'urlhash-x' ) );
		$this->set_url_stats( $store, 'urlhash-x', [ 'flame' => [ 1, 2, 3 ] ] );
		$this->assertSame(
			[ 'flame' => [ 1, 2, 3 ] ],
			$store->url_aggregate( 'urlhash-x' )
		);
	}

	public function test_url_stats_hands_out_the_profile_as_per_request_means(): void {
		$store = $this->stats_store();
		$this->set_url_stats( $store, 'urlhash-m', [
			'last_modified' => 1790000417,
			'profiles'      => [
				'count'        => 4,
				'sum_req_time' => 200.0,
				'categories'   => [ 'wpdb' => [ 'samples' => 4, 'sum_time' => 80.0, 'sum_count' => 12, 'entries' => [] ] ],
			],
		] );
		$stats = Stats_Store::url_stats( [ $store ], 'urlhash-m' );
		$this->assertSame( 1790000417, $stats['last_modified'] );
		$this->assertArrayNotHasKey( 'flame', $stats, 'no blob held a running flame' );
		$this->assertSame( 4, $stats['profiles']['count'] );
		$this->assertEqualsWithDelta( 50.0, $stats['profiles']['total_time'], 1e-6 );
		$this->assertEqualsWithDelta( 20.0, $stats['profiles']['categories']['wpdb']['time'], 1e-6 );
		$this->assertEqualsWithDelta( 3.0, $stats['profiles']['categories']['wpdb']['count'], 1e-6 );
	}

	public function test_url_stats_hands_out_no_profile_over_no_profiled_request(): void {
		// The flush seeds a fresh blob's profile at count 0; a request with no
		// profile, or an untimed one, leaves it there.
		$store = $this->stats_store( partition: 0 );
		$this->set_url_stats( $store, 'urlhash-z', [
			'last_modified' => 1790000533,
			'profiles'      => [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ],
		] );
		$this->set_url_stats( $this->stats_store( partition: 1 ), 'urlhash-z', [
			'profiles' => [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ],
		] );

		$stats = Stats_Store::url_stats( [ $store, $this->stats_store( partition: 1 ) ], 'urlhash-z' );

		$this->assertSame( 1790000533, $stats['last_modified'] );
		$this->assertArrayNotHasKey( 'profiles', $stats );
	}

	public function test_url_stats_is_null_when_no_partition_holds_the_url(): void {
		$this->assertNull( Stats_Store::url_stats( [ $this->stats_store( partition: 0 ), $this->stats_store( partition: 1 ) ], 'urlhash-none' ) );
	}

	public function test_each_partition_keeps_its_own_keyspace(): void {
		$store_p0 = $this->stats_store( partition: 0 );
		$store_p1 = $this->stats_store( partition: 1 );
		$this->set_hour_slot( $store_p0, Stats_Store::hourly_parts(), '2026-01-01-00-00', [ 'count' => 11 ] );
		$this->set_hour_slot( $store_p1, Stats_Store::hourly_parts(), '2026-01-01-00-00', [ 'count' => 29 ] );

		$this->assertSame( 11, $this->get_hour_slot( $store_p0, Stats_Store::hourly_parts(), '2026-01-01-00-00' )['count'] );
		$this->assertSame( 29, $this->get_hour_slot( $store_p1, Stats_Store::hourly_parts(), '2026-01-01-00-00' )['count'] );
	}

	public function test_fail_soft_get_returns_empty_when_no_table_answers(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->break_stats_tables( 0 );
		$this->assertSame( [], $this->url_bucket_rows( $store, 'any' ) );
		$this->assertNull( $store->url_aggregate( 'any' ) );
		$this->assertSame( [], $this->get_hour_slot( $store, Stats_Store::hourly_parts(), 'any' ) );
		$this->assertSame( [], $this->get_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), 'b1' ) );
	}

	public function test_fail_soft_set_returns_false_when_no_table_answers(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->break_stats_tables( 0 );
		$this->assertFalse( $this->set_url_bucket( $store, '2026-01-01-00-00', [] ) );
		$this->assertFalse( $this->set_leaderboard_hour( $store, '2026-01-01-00', [] ) );
		$this->assertFalse( $this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), 'b1', [] ) );
	}

	public function test_get_multi_url_buckets_batches_lookups(): void {
		$store = $this->stats_store();
		$bucket = '2026-01-01-00-00';
		$this->set_url_bucket( $store, $bucket, [
			'/x' => [ 'url' => 'https://example.com/x' ],
			'/y' => [ 'url' => 'https://example.com/y' ],
		] );

		// get_url_buckets should accept a list and return a map.
		$results = $this->url_rows_by_bucket( $store, [ $bucket, 'nonexistent-bucket' ] );
		$this->assertArrayHasKey( $bucket, $results );
		$this->assertArrayHasKey( '/x', $results[ $bucket ] );
		$this->assertArrayHasKey( '/y', $results[ $bucket ] );
		// Missing bucket should not appear.
		$this->assertArrayNotHasKey( 'nonexistent-bucket', $results );
	}

	public function test_get_url_buckets_returns_empty_when_no_table_answers(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->break_stats_tables( 0 );
		$this->assertSame( [], $this->url_rows_by_bucket( $store, [ 'a', 'b' ] ) );
	}

	// --- New explicit-bucket setter API (FlameBuilder uses these) ---------

	public function test_set_and_get_hourly_round_trip(): void {
		$store = $this->stats_store();
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-01-01-00-05', [ 'count' => 5, 'sum_ms' => 100, 'sum_peak_mb' => 10 ] );
		$h = $store->get_slots( Stats_Store::hourly_parts(), [ Stats_Store::hour_of( '2026-01-01-00-05' ) ] );
		$this->assertSame( 5, $h['2026-01-01-00-05']['count'] );
	}

	public function test_set_and_get_leaderboard_hour_round_trip(): void {
		$store = $this->stats_store();
		$this->set_leaderboard_hour( $store, '2026-01-01-00', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$lb = $this->get_leaderboard_hour( $store, '2026-01-01-00' );
		$this->assertSame( 3, $lb['count'] );
		$this->assertEqualsWithDelta( 1.5, $lb['sum_req_time'], 1e-9 );
	}

	public function test_set_and_get_server_leaderboard_hour_round_trip(): void {
		$store = $this->stats_store();
		$this->set_leaderboard_hour( $store, '2026-01-01-00', [ 'count' => 7, 'sum_req_time' => 3.5, 'categories' => [] ], 'srv-x' );
		$lb = $this->get_leaderboard_hour( $store, '2026-01-01-00', 'srv-x' );
		$this->assertSame( 7, $lb['count'] );
	}

	public function test_set_and_get_url_bucket_round_trip(): void {
		$store = $this->stats_store();
		$this->set_url_bucket( $store, '2026-01-01-00-00', [ 'hash1' => [ 'url' => 'https://example.com/x', 'count' => 1 ] ] );
		$urls = $this->url_bucket_rows( $store, '2026-01-01-00-00' );
		$this->assertArrayHasKey( 'hash1', $urls );
	}

	public function test_server_key_static_helper_is_deterministic(): void {
		$h1 = Stats_Store::server_key( 'srv-a.example.com' );
		$h2 = Stats_Store::server_key( 'srv-a.example.com' );
		$this->assertSame( $h1, $h2 );
		$this->assertSame( 8, \strlen( $h1 ) );
		// Different inputs → different hashes.
		$h3 = Stats_Store::server_key( 'srv-b.example.com' );
		$this->assertNotSame( $h1, $h3 );
	}

	public function test_server_key_is_the_fnv1a32_every_stored_key_carries(): void {
		// Stored keys carry it, so a new spelling of the hash orphans them.
		$this->assertSame( '41a5c9d3', Stats_Store::server_key( 'srv-a.example.com' ) );
		$this->assertSame( '627292da', Stats_Store::server_key( 'spray042.probe.test' ) );
		$this->assertSame( '1f323231', Stats_Store::server_key( "\u{e9}\0x" ) );
	}

	public function test_server_key_empty_string_returns_empty(): void {
		$this->assertSame( '', Stats_Store::server_key( '' ) );
	}

	public function test_merge_leaderboard_bucket_static_is_additive(): void {
		$dst = [ 'count' => 1, 'sum_req_time' => 0.5, 'categories' => [] ];
		$src = [
			'count'        => 2,
			'sum_req_time' => 1.0,
			'categories'   => [
				'wpdb' => [
					'samples'   => 2,
					'sum_time'  => 0.4,
					'sum_count' => 12,
					'entries'   => [ 'SELECT' => [ 0.3, 8, 1 ] ],
				],
			],
		];
		Stats_Store::merge_leaderboard_bucket( $dst, $src );
		$this->assertSame( 3, $dst['count'] );
		$this->assertEqualsWithDelta( 1.5, $dst['sum_req_time'], 1e-9 );
		$this->assertSame( 2, $dst['categories']['wpdb']['samples'] );
		$this->assertEqualsWithDelta( 0.4, $dst['categories']['wpdb']['sum_time'], 1e-9 );
		// Entry merge.
		$this->assertEqualsWithDelta( 0.3, $dst['categories']['wpdb']['entries']['SELECT'][0], 1e-9 );
	}

	// --- Batched bucket reads ---------------------------------------------

	public function test_leaderboard_hours_read_in_one_round_trip(): void {
		$store = $this->stats_store();
		$this->set_leaderboard_hour( $store, '2026-01-01-01', [ 'count' => 31, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$this->set_leaderboard_hour( $store, '2026-01-01-03', [ 'count' => 74, 'sum_req_time' => 2.5, 'categories' => [] ] );
		$this->forget_stats_asks();

		$rows = $store->get_leaderboard_hours( [ '2026-01-01-01', '2026-01-01-02', '2026-01-01-03' ] );

		$this->assertSame( [ '2026-01-01-01', '2026-01-01-03' ], \array_keys( $rows ), 'absent hours omitted, present ones keyed by hour' );
		$this->assertSame( 74, $rows['2026-01-01-03']['count'] );
		$this->assertSame( 1, $this->stats_reads(), 'one round trip' );
	}

	public function test_leaderboard_hours_scope_to_a_server_when_asked(): void {
		$store = $this->stats_store();
		$this->set_leaderboard_hour( $store, '2026-01-01-01', [ 'count' => 31, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$this->set_leaderboard_hour( $store, '2026-01-01-01', [ 'count' => 88, 'sum_req_time' => 3.5, 'categories' => [] ], 'spoke-a' );

		$this->assertSame( 88, $store->get_leaderboard_hours( [ '2026-01-01-01' ], 'spoke-a' )['2026-01-01-01']['count'] );
		$this->assertSame( 31, $store->get_leaderboard_hours( [ '2026-01-01-01' ] )['2026-01-01-01']['count'] );
	}

	public function test_add_totals_sums_the_request_totals(): void {
		// The totals are summed in four places in three dialects; the schema
		// owns the arithmetic, like sums_to_display() owns the read-time division.
		$this->assertSame(
			[ 'count' => 11, 'sum_ms' => 91.5, 'requests' => 13, 'sum_peak_mb' => 7.25 ],
			Stats_Store::add_totals(
				[ 'count' => 4, 'sum_ms' => 31.5, 'requests' => 5, 'sum_peak_mb' => 2.25 ],
				[ 'count' => 7, 'sum_ms' => 60.0, 'requests' => 8, 'sum_peak_mb' => 5.0 ]
			)
		);
	}

	public function test_add_totals_keeps_fields_outside_the_totals(): void {
		// The stored bucket is not owned by this function; rebuilding it from
		// its four keys would drop a fifth silently, at every flush, forever.
		$this->assertSame(
			[ 'peak_url' => '/slow', 'count' => 9, 'sum_ms' => 12.0, 'requests' => 0, 'sum_peak_mb' => 0.0 ],
			Stats_Store::add_totals( [ 'peak_url' => '/slow', 'count' => 4 ], [ 'count' => 5, 'sum_ms' => 12.0 ] )
		);
	}

	public function test_add_totals_treats_a_missing_or_junk_side_as_zero(): void {
		$this->assertSame(
			[ 'count' => 3, 'sum_ms' => 8.5, 'requests' => 0, 'sum_peak_mb' => 0.0 ],
			Stats_Store::add_totals( [], [ 'count' => 3, 'sum_ms' => '8.5', 'sum_peak_mb' => 'nope' ] )
		);
	}

	public function test_leaderboard_hour_round_trips_per_server(): void {
		$store = $this->stats_store();
		$this->set_leaderboard_hour( $store, '2026-01-01-00', [ 'count' => 61 ], 'web07' );

		$this->assertSame( 61, ( $store->get_leaderboard_hours( [ '2026-01-01-00' ], 'web07' )['2026-01-01-00'] ?? [] )['count'] );
		$this->assertSame( [], $this->get_leaderboard_hour( $store, '2026-01-01-00' ), 'the global scope is a different key' );
	}

	public function test_dimensional_bucket_round_trips_global_and_per_server(): void {
		// Every slotted namespace is keyed with the hour LAST, so one
		// lookup_hours() batch serves them all.
		$store = $this->stats_store();
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), '2026-02-03-04-05', [ '503' => self::dim_entry( 47, 12.5, 3.0 ) ] );
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', 'web07' ), '2026-02-03-04-05', [ '503' => self::dim_entry( 91, 1.0, 1.0 ) ] );

		$this->assertSame( 47, $this->get_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), '2026-02-03-04-05' )['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 91, $this->get_hour_slot( $store, Stats_Store::dim_parts( 'status', 'web07' ), '2026-02-03-04-05' )['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame(
			47,
			$store->get_slots( Stats_Store::dim_parts( 'status', '' ), [ Stats_Store::hour_of( '2026-02-03-04-05' ) ] )['2026-02-03-04-05']['503'][ Stats_Store::DIM_COUNT ],
			'the batch read returns the same value keyed by bucket'
		);
	}

	public function test_category_bucket_round_trips_global_and_per_server(): void {
		$store = $this->stats_store();
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), '2026-02-03-04-05', [ 'wpdb' => self::cat_entry( 8.5, 23, 4 ) ] );
		$this->set_hour_slot( $store, Stats_Store::cat_parts( 'web07' ), '2026-02-03-04-05', [ 'wpdb' => self::cat_entry( 1.5, 66, 2 ) ] );

		$this->assertSame( 23, $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), '2026-02-03-04-05' )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame( 66, $this->get_hour_slot( $store, Stats_Store::cat_parts( 'web07' ), '2026-02-03-04-05' )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame(
			23,
			$store->get_slots( Stats_Store::cat_parts( '' ), [ Stats_Store::hour_of( '2026-02-03-04-05' ) ] )['2026-02-03-04-05']['wpdb'][ Stats_Store::CAT_CALLS ]
		);
	}

	public function test_url_category_bucket_round_trips(): void {
		$store = $this->stats_store();
		$this->set_hour_slot( $store, Stats_Store::url_cat_parts( 'ab12cd34ef56' ), '2026-02-03-04-05', [ 'wpdb' => self::cat_entry( 3.5, 57, 2 ) ] );

		$this->assertSame( 57, $this->get_hour_slot( $store, Stats_Store::url_cat_parts( 'ab12cd34ef56' ), '2026-02-03-04-05' )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame(
			57,
			$store->get_slots( Stats_Store::url_cat_parts( 'ab12cd34ef56' ), [ Stats_Store::hour_of( '2026-02-03-04-05' ) ] )['2026-02-03-04-05']['wpdb'][ Stats_Store::CAT_CALLS ]
		);
	}

	public function test_a_scope_is_a_different_keyspace_not_a_shared_one(): void {
		// '' vs a named server, and one URL vs another, must not collide.
		$store  = $this->stats_store();
		$bucket = '2026-02-03-04-05';
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket, [ 'wpdb' => self::cat_entry( 0, 13, 1 ) ] );
		$this->set_hour_slot( $store, Stats_Store::url_cat_parts( 'aaaaaaaaaaaa' ), $bucket, [ 'wpdb' => self::cat_entry( 0, 77, 1 ) ] );

		$this->assertSame( [], $store->get_slots( Stats_Store::cat_parts( 'web07' ), [ Stats_Store::hour_of( $bucket ) ] )[ $bucket ] ?? [] );
		$this->assertSame( [], $store->get_slots( Stats_Store::url_cat_parts( 'bbbbbbbbbbbb' ), [ Stats_Store::hour_of( $bucket ) ] )[ $bucket ] ?? [] );
		$this->assertSame( 13, ( $this->get_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket ) )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame( 77, ( $store->get_slots( Stats_Store::url_cat_parts( 'aaaaaaaaaaaa' ), [ Stats_Store::hour_of( $bucket ) ] )[ $bucket ] ?? [] )['wpdb'][ Stats_Store::CAT_CALLS ] );
	}

	public function test_bucket_key_floors_to_the_bucket_width(): void {
		// 12:47 UTC belongs to the 12:45 bucket.
		$this->assertSame( '2026-02-03-12-45', Stats_Store::bucket_key( \gmmktime( 12, 47, 33, 2, 3, 2026 ) ) );
	}

	/** A bucket opens on the UTC second `bucket_key()` floors to, whatever the zone. */
	public function test_a_bucket_starts_on_the_utc_second_its_key_names(): void {
		$zone = \date_default_timezone_get();
		\date_default_timezone_set( 'Pacific/Chatham' );
		try {
			$this->assertSame( \gmmktime( 13, 35, 0, 10, 4, 2026 ), Stats_Store::bucket_start( '2026-10-04-13-35' ) );
			$this->assertSame( '2026-12-31-23-55', Stats_Store::bucket_key( Stats_Store::bucket_start( '2026-12-31-23-55' ) ) );
		} finally {
			\date_default_timezone_set( $zone );
		}
	}

	/**
	 * A key that does not parse as `Y-m-d-H-i` is refused rather than read
	 * as the epoch.
	 *
	 * @param string $bucket The refused key.
	 */
	#[DataProvider( 'unparsed_buckets' )]
	public function test_a_bucket_key_that_does_not_parse_has_no_start( string $bucket ): void {
		$this->expectException( \InvalidArgumentException::class );
		Stats_Store::bucket_start( $bucket );
	}

	/** @return array<string,array{0:string}> */
	public static function unparsed_buckets(): array {
		return [
			'garbage'       => [ 'kakapo' ],
			'trailing data' => [ '2026-10-04-13-35x' ],
			'an hour key'   => [ '2026-10-04-13' ],
			'empty'         => [ '' ],
		];
	}

	/**
	 * `tests/fixtures/bucket-selection.json` lists the selections both
	 * languages spell: a canonical spelling and its keys read each other
	 * back, a loose spelling parses to sorted keys with no repeat, and a
	 * malformed one is refused.
	 *
	 * @return array<string,mixed>
	 */
	private static function selection_cases(): array {
		return Core::arr( \json_decode( (string) \file_get_contents( __DIR__ . '/../fixtures/bucket-selection.json' ), true ) );
	}

	/** @return array<string,array{0:list<string>,1:string}> */
	public static function canonical_selections(): array {
		$out = [];
		foreach ( Core::arr( self::selection_cases()['canonical'] ?? null ) as $case ) {
			$case                                     = Core::arr( $case );
			$out[ Core::as_string( $case['spelling'] ) ] = [ \array_values( \array_map( Core::as_string( ... ), Core::arr( $case['keys'] ) ) ), Core::as_string( $case['spelling'] ) ];
		}
		return $out;
	}

	/** @return array<string,array{0:string,1:list<string>}> */
	public static function loose_selections(): array {
		$out = [];
		foreach ( Core::arr( self::selection_cases()['normalizes'] ?? null ) as $case ) {
			$case                                     = Core::arr( $case );
			$out[ Core::as_string( $case['spelling'] ) ] = [ Core::as_string( $case['spelling'] ), \array_values( \array_map( Core::as_string( ... ), Core::arr( $case['keys'] ) ) ) ];
		}
		return $out;
	}

	/** @return array<string,array{0:string}> */
	public static function refused_selections(): array {
		$out = [];
		foreach ( Core::arr( self::selection_cases()['refused'] ?? null ) as $spelling ) {
			$out[ Core::as_string( $spelling ) ] = [ Core::as_string( $spelling ) ];
		}
		return $out;
	}

	/**
	 * A canonical spelling parses to its keys, and its keys spell it.
	 *
	 * @param list<string> $keys     The selected bucket keys, ascending.
	 * @param string       $spelling Their canonical spelling.
	 */
	#[DataProvider( 'canonical_selections' )]
	public function test_a_canonical_selection_round_trips( array $keys, string $spelling ): void {
		$this->assertSame( $keys, Stats_Store::bucket_selection( $spelling ) );
		$this->assertSame( $spelling, Stats_Store::bucket_spelling( $keys ) );
	}

	/**
	 * A loose spelling parses to its keys ascending, each once.
	 *
	 * @param string       $spelling A non-canonical spelling.
	 * @param list<string> $keys     The keys it names.
	 */
	#[DataProvider( 'loose_selections' )]
	public function test_a_loose_selection_normalizes( string $spelling, array $keys ): void {
		$this->assertSame( $keys, Stats_Store::bucket_selection( $spelling ) );
	}

	/**
	 * A spelling naming no five-minute key, a run ending before it starts,
	 * or an empty run is refused.
	 *
	 * @param string $spelling The refused spelling.
	 */
	#[DataProvider( 'refused_selections' )]
	public function test_a_malformed_selection_is_refused( string $spelling ): void {
		$this->expectException( \InvalidArgumentException::class );
		Stats_Store::bucket_selection( $spelling );
	}

	/**
	 * A selection holds at most `MAX_READ_BUCKETS` keys: a run of 288 parses,
	 * and one bucket more is refused naming the limit, before any run that
	 * long is enumerated.
	 */
	public function test_a_selection_past_the_read_limit_is_refused(): void {
		$first = \gmmktime( 6, 40, 0, 10, 3, 2026 );
		$last  = Stats_Store::bucket_key( $first + ( Stats_Store::MAX_READ_BUCKETS - 1 ) * Stats_Store::BUCKET_SECONDS );

		$this->assertCount( 288, Stats_Store::bucket_selection( "2026-10-03-06-40..{$last}" ) );
		foreach ( [ '2026-10-03-06-35..' . $last, "2026-10-03-06-40..{$last},2026-10-05-23-55", '2026-10-03-06-40..2126-10-03-06-40' ] as $over ) {
			try {
				Stats_Store::bucket_selection( $over );
				$this->fail( "{$over} parsed" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'bucket selection holds more than 288 buckets', $e->getMessage() );
			}
		}
	}

	/** The read limit is the one the shared fixture names, which JS holds to. */
	public function test_the_read_limit_is_the_fixtures(): void {
		$this->assertSame( self::selection_cases()['max'] ?? null, Stats_Store::MAX_READ_BUCKETS );
	}

	/** A refusal names the piece of the spelling it could not read. */
	public function test_a_refused_selection_names_what_it_could_not_read(): void {
		foreach ( [
			'2026-10-05-16-55,2026-10-05-16-57' => 'bucket 2026-10-05-16-57 must be a five-minute key, Y-m-d-H-i in UTC',
			'2026-10-05-16-55,kea'              => 'bucket kea must be a five-minute key, Y-m-d-H-i in UTC',
			'2026-10-05-17-10..2026-10-05-16-55' => 'bucket run 2026-10-05-17-10..2026-10-05-16-55 ends before it starts',
		] as $spelling => $message ) {
			try {
				Stats_Store::bucket_selection( $spelling );
				$this->fail( "{$spelling} parsed" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage() );
			}
		}
	}

	/**
	 * The buckets a chart draws are the 288 ending at the one `$now` falls
	 * in, newest first, whatever the retention window.
	 */
	public function test_the_chart_buckets_are_the_288_ending_at_the_current_one(): void {
		$buckets = Stats_Store::chart_buckets( \gmmktime( 9, 12, 41, 10, 5, 2026 ) );

		$this->assertCount( Stats_Store::MAX_READ_BUCKETS, $buckets );
		$this->assertSame( [ '2026-10-05-09-10', '2026-10-05-09-05' ], \array_slice( $buckets, 0, 2 ) );
		$this->assertSame( '2026-10-04-09-15', $buckets[ Stats_Store::MAX_READ_BUCKETS - 1 ] );
		$this->assertSame( '2026-10-05-09-15', Stats_Store::chart_buckets( \gmmktime( 9, 17, 3, 10, 5, 2026 ) )[0], 'the next bucket is not served the memo' );
	}

	public function test_the_read_window_is_derived_from_retention(): void {
		// The window is what retention can hold, not a fixed 24h.
		$now     = \gmmktime( 12, 47, 33, 2, 3, 2026 );
		$buckets = Stats_Store::retention_buckets( 7200, $now ); // 2h: no default is near it

		$this->assertCount( 25, $buckets, '2h of 5-minute buckets, plus the open one' );
		$this->assertContains( Stats_Store::bucket_key( $now ), $buckets, 'newest' );
		$this->assertContains( Stats_Store::bucket_key( $now - 7200 ), $buckets, 'oldest in retention' );
	}

	public function test_the_read_window_stays_bounded_past_the_cap(): void {
		// The bound is what keeps one get_multi bounded regardless of how long
		// an install configures retention.
		$buckets = Stats_Store::retention_buckets( 259200, \gmmktime( 12, 47, 33, 2, 3, 2026 ) );

		$this->assertCount( Stats_Store::MAX_READ_BUCKETS, $buckets );
	}

	public function test_a_url_name_is_its_server_and_the_path_its_row_carries(): void {
		// The server is the locator a hash-only read needs; the path follows
		// the row's rule, and display joins the two back through one join.
		$store      = $this->stats_store( 0, 86400 );
		$store->set_url_names( [
			'kea.example'          => [
				'a1a1a1a1a1a1' => 'https://kea.example/kakapo-7731?x=7',
				'b2b2b2b2b2b2' => 'http://kea.example/plain-3319',
			],
			Stats_Store::OTHER_KEY => [ 'c3c3c3c3c3c3' => 'https://moa.example/weka-5521' ],
		] );

		$this->assertSame(
			[ 'kea.example', '/kakapo-7731?x=7' ],
			$store->bucket_get_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a1a1a1a1a1a1' ] ] )[0],
			'the origin is the server; the value carries the server and the path'
		);
		$this->assertSame(
			[
				'a1a1a1a1a1a1' => [ 'server' => 'kea.example', 'url' => 'https://kea.example/kakapo-7731?x=7' ],
				'b2b2b2b2b2b2' => [ 'server' => 'kea.example', 'url' => 'http://kea.example/plain-3319' ],
				'c3c3c3c3c3c3' => [ 'server' => Stats_Store::OTHER_KEY, 'url' => 'https://moa.example/weka-5521' ],
			],
			$store->get_url_names( [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2', 'c3c3c3c3c3c3' ] )
		);
	}

	public function test_a_urls_path_drops_its_scheme_and_host_alone(): void {
		// An authority with no path is the case that bites: everything after
		// the host is the path, or the host swallows a query.
		$this->assertSame(
			[ '/reports', '/?cache-cozy', '?q=1', '' ],
			\array_map(
				[ Stats_Store::class, 'path_of' ],
				[ 'https://alpha.test/reports', 'https://alpha.test/?cache-cozy', 'https://alpha.test?q=1', 'https://alpha.test' ]
			)
		);
	}

	/** Every logged URL carries its host, so a hostless one is refused, never read as a bare path. */
	public function test_a_hostless_url_is_refused(): void {
		foreach ( [ '/bare-7731', '/r?to=https://kea.test/aisle-9', 'https:///no-host-7731', '' ] as $url ) {
			foreach ( [ 'path_of', 'server_of' ] as $reader ) {
				try {
					Stats_Store::$reader( $url );
					$this->fail( "{$reader}( '{$url}' ) answered" );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( "no host: '{$url}'", $e->getMessage() );
				}
			}
		}
	}

	/** A URL's server is its host. */
	public function test_a_urls_server_is_its_host(): void {
		$this->assertSame( 'kea.test', Stats_Store::server_of( 'https://kea.test/sku-41?q=9' ) );
		$this->assertSame( 'wren.test', Stats_Store::server_of( 'http://wren.test?q=3' ) );
		$this->assertSame( 'kea.test', Stats_Store::server_of( 'https://kea.test' ) );
	}

	/** A row cut to its path is searched as it is; one kept whole is a URL, searched by its path. */
	public function test_a_rows_search_path_is_its_url_path(): void {
		$this->assertSame( '/kakapo-4417?q=9', Stats_Store::row_search_path( '/kakapo-4417?q=9' ) );
		$this->assertSame( '/kakapo-4417', Stats_Store::row_search_path( 'http://kea.test/kakapo-4417' ) );
		$this->assertSame( '?q=3', Stats_Store::row_search_path( 'https://kea.test?q=3' ) );
	}

	public function test_a_mean_over_no_requests_is_null(): void {
		$this->assertNull( Stats_Store::mean( 12.5, 0 ) );
		$this->assertEqualsWithDelta( 2.5, Stats_Store::mean( 12.5, 5 ), 1e-9 );
	}

	public function test_a_leaderboard_of_no_profiled_request_has_no_means(): void {
		$display = Stats_Store::sums_to_display( 0, 0.0, [ 'wpdb' => [ 'sum_time' => 9.0, 'sum_count' => 4.0, 'samples' => 3 ] ] );

		$this->assertNull( $display['total_time'] );
		$this->assertNull( $display['categories']['wpdb']['time'] );
		$this->assertNull( $display['categories']['wpdb']['count'] );
		$this->assertEqualsWithDelta( 9.0, Stats_Store::sums_to_display( 3, 27.0, [] )['total_time'], 1e-9 );
	}

	public function test_sums_to_display_converts_running_sums_to_avg(): void {
		$sums = [
			'wpdb' => [
				'samples'   => 4,
				'sum_time'  => 1.6,
				'sum_count' => 16,
				'entries'   => [ 'SELECT' => [ 1.2, 12, 4 ] ],
			],
		];
		$display = Stats_Store::sums_to_display( 4, 4.0, $sums );
		$this->assertSame( 4, $display['count'] );
		$this->assertEqualsWithDelta( 1.0, $display['total_time'], 1e-9 );
		// time = sum_time / total_count = 1.6/4 = 0.4
		$this->assertEqualsWithDelta( 0.4, $display['categories']['wpdb']['time'], 1e-9 );
		$this->assertEqualsWithDelta( 4.0, $display['categories']['wpdb']['count'], 1e-9 );
		// entries[name] = [sum/samples, sum/samples, samples]
		$this->assertEqualsWithDelta( 0.3, $display['categories']['wpdb']['entries']['SELECT'][0], 1e-9 );
		$this->assertEqualsWithDelta( 3.0, $display['categories']['wpdb']['entries']['SELECT'][1], 1e-9 );
	}

	public function test_merge_leaderboard_bucket_coerces_missing_and_non_numeric_to_zero(): void {
		$dst = [];
		$src = [
			'count'        => 'not-a-number',
			'sum_req_time' => null,
			'categories'   => [
				'wpdb' => [
					'samples'   => [ 'nope' ],
					'sum_time'  => '0.5',
					'sum_count' => false,
					'entries'   => [ 'SELECT' => [ 'x', 2, null ] ],
				],
			],
		];
		Stats_Store::merge_leaderboard_bucket( $dst, $src );
		$this->assertSame( 0, $dst['count'] );
		$this->assertEqualsWithDelta( 0.0, $dst['sum_req_time'], 1e-9 );
		$this->assertSame( 0, $dst['categories']['wpdb']['samples'] );
		$this->assertEqualsWithDelta( 0.5, $dst['categories']['wpdb']['sum_time'], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $dst['categories']['wpdb']['sum_count'], 1e-9 );
		// Numeric-string entry[0] coerces; non-numeric entry[0]/entry[2] coerce to 0.
		$this->assertEqualsWithDelta( 0.0, $dst['categories']['wpdb']['entries']['SELECT'][0], 1e-9 );
		$this->assertEqualsWithDelta( 2.0, $dst['categories']['wpdb']['entries']['SELECT'][1], 1e-9 );
		$this->assertSame( 0, $dst['categories']['wpdb']['entries']['SELECT'][2] );
	}

	public function test_sums_to_display_skips_entries_with_zero_samples(): void {
		$sums = [
			'wpdb' => [
				'samples'   => 2,
				'sum_time'  => '1.0',
				'sum_count' => null,
				'entries'   => [
					'ZERO'    => [ 5.0, 5.0, 0 ],
					'NAN'     => [ 'x', 'y', 3 ],
				],
			],
		];
		$display = Stats_Store::sums_to_display( 2, 2.0, $sums );
		// count/time from total_count=2: sum_count(null->0)/2=0, sum_time(1.0)/2=0.5.
		$this->assertEqualsWithDelta( 0.5, $display['categories']['wpdb']['time'], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $display['categories']['wpdb']['count'], 1e-9 );
		// Zero-sample entry is dropped; non-numeric sums coerce to 0 over 3 samples.
		$this->assertArrayNotHasKey( 'ZERO', $display['categories']['wpdb']['entries'] );
		$this->assertEqualsWithDelta( 0.0, $display['categories']['wpdb']['entries']['NAN'][0], 1e-9 );
		$this->assertSame( 3, $display['categories']['wpdb']['entries']['NAN'][2] );
	}

	public function test_a_url_read_asks_only_for_shard_keys(): void {
		// The unsharded shape a pre-sharding release wrote is no longer read.
		// A read that still asks for it burns a key per bucket per poll,
		// forever, for a namespace nothing writes.
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$bucket = Stats_Store::bucket_key( 1_700_000_000 );
		$store->bucket_set_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket, self::index_of( [ 'kea.test' ], [ 'a' ] ) ] ] );
		$this->forget_stats_asks();

		$store->url_row_sources( [ $bucket ], null, false, 'kea.test' );

		$this->assertSame( [ 'urls:' . $bucket . ':' . Stats_Store::server_key( 'kea.test' ) . ':a' ], $this->asked_keys( Stats_Store::NS_URLS ), 'the shard the index names for the server, and neither the unsharded key nor a shard no server owns' );
	}

	public function test_folding_keeps_the_newest_last_seen(): void {
		// `sum_entry()` returns `$into` PLUS the summed fields, so merging it
		// over the computed head puts `$into`'s own `last_seen` back — pinning
		// the overflow row's timestamp to whichever row folded first.
		$first  = [ 'count' => 3, 'last_seen' => 1700000100 ];
		$second = [ 'count' => 4, 'last_seen' => 1700000900 ];

		$folded = self::named_url_row( Stats_Store::fold_url_rows(
			self::positional_url_row( $first ),
			self::positional_url_row( $second )
		) );

		$this->assertSame( 1700000900, $folded['last_seen'] );
		$this->assertSame( 7, $folded['count'] );
	}

	public function test_folding_keeps_worker_true_once_either_side_is(): void {
		$folded = self::named_url_row( Stats_Store::fold_url_rows(
			self::positional_url_row( [ 'count' => 1, 'worker' => true ] ),
			self::positional_url_row( [ 'count' => 1 ] )
		) );

		$this->assertTrue( $folded['worker'] );
	}

	public function test_an_overflow_row_names_no_path(): void {
		// `Other` stands for many URLs, so no one path speaks for it.
		$folded = self::named_url_row( Stats_Store::fold_url_rows(
			self::positional_url_row( [ 'count' => 2, 'path' => '/kea-2' ] ),
			self::positional_url_row( [ 'count' => 5, 'path' => '/moa-5' ] )
		) );

		$this->assertSame( '', $folded['path'] );
		$this->assertSame( 7, $folded['count'] );
	}

	public function test_merging_one_url_keeps_its_path_from_either_side(): void {
		// Merge order varies, and a row seeded before its first request has
		// no path yet: whichever side names it wins.
		$merged = self::named_url_row( Stats_Store::merge_url_row(
			self::positional_url_row( [ 'count' => 3, 'path' => '' ] ),
			self::positional_url_row( [ 'count' => 4, 'path' => '/weka-1308' ] )
		) );
		$this->assertSame( '/weka-1308', $merged['path'] );
		$this->assertSame( 7, $merged['count'] );

		$kept = self::named_url_row( Stats_Store::merge_url_row(
			self::positional_url_row( [ 'count' => 3, 'path' => '/weka-1308' ] ),
			self::positional_url_row( [ 'count' => 1 ] )
		) );
		$this->assertSame( '/weka-1308', $kept['path'] );
	}

	public function test_the_coarse_hour_round_trips_through_its_own_getter(): void {
		// set_url_hour()'s other half: a writer merging into a folded hour
		// reads it the way get_url_shard() reads the fine tier.
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$rows       = [ 'hash-4471' => [ Stats_Store::ROW_COUNT => 6, Stats_Store::ROW_MAX_MS => 44.71 ] ];

		$this->assertTrue( $this->set_url_hour( $store, '2026-08-27-13', 'a', $rows ) );
		$this->assertSame( $rows, $this->get_url_hour( $store, '2026-08-27-13', 'a' ) );
		$this->assertSame( [], $this->get_url_hour( $store, '2026-08-27-14', 'a' ), 'an unfolded hour reads empty' );
	}
	// ── batched writes: the flush's cost is its KEY COUNT ───────────────────

	public function test_bucket_writes_batch_and_read_back_under_their_own_keys(): void {
		// The write half of lookup_bucket_sets(). Seeds distinct from every
		// default: three dimensions, counts 61/62/63, one bucket.
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$writes     = [];
		foreach ( [ 'status' => 61, 'server' => 62, 'plugin' => 63 ] as $dim => $n ) {
			$writes[] = [ Stats_Store::dim_parts( $dim, '' ), '2026-08-27-13', [ 'v' => self::dim_entry( $n ) ] ];
		}

		$this->assertSame( [ true, true, true ], $store->bucket_set_multi( $writes ) );

		foreach ( [ 'status' => 61, 'server' => 62, 'plugin' => 63 ] as $dim => $n ) {
			$this->assertSame(
				$n,
				$store->bucket_get_multi( [ [ Stats_Store::dim_parts( $dim, '' ), '2026-08-27-13' ] ] )[0]['v'][ Stats_Store::DIM_COUNT ] ?? null,
				"{$dim} must read back what the batch wrote"
			);
		}
	}

	public function test_a_batched_read_tells_a_stored_empty_value_from_a_miss(): void {
		// Positional: the caller merges result[i] onto writes[i], so a miss
		// has to hold its slot. It also has to be TELLABLE from a value that
		// is there and empty — an hour folded with no rows is written empty,
		// and a probe reading that as absence folds it again forever.
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$store->bucket_set_multi( [
			[ Stats_Store::dim_parts( 'status', '' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 61 ) ] ],
			[ Stats_Store::dim_parts( 'server', '' ), '2026-08-27-13-05', [] ],
		] );

		$read = $store->bucket_get_multi( [
			[ Stats_Store::dim_parts( 'plugin', '' ), '2026-08-27-13-05' ],
			[ Stats_Store::dim_parts( 'status', '' ), '2026-08-27-13-05' ],
			[ Stats_Store::dim_parts( 'server', '' ), '2026-08-27-13-05' ],
		] );

		$this->assertCount( 3, $read, 'one entry per request, misses included' );
		$this->assertNull( $read[0], 'a miss reads null, in its own slot' );
		$this->assertSame( 61, $read[1]['v'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( [], $read[2], 'a stored empty value is empty, not absent' );
	}

	public function test_an_empty_batch_is_a_no_op(): void {
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->assertSame( [], $store->bucket_set_multi( [] ) );
		$this->assertSame( [], $store->bucket_get_multi( [] ) );
	}
	public function test_a_refused_key_is_named_and_the_rest_land(): void {
		// The reply names each key that landed, so a caller that logs a
		// specific refusal — an oversized URL shard — learns which one. A key
		// holding whitespace is one no Table takes; two good keys beside it.
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );

		$results = $store->bucket_set_multi( [
			[ Stats_Store::dim_parts( 'status', '' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 61 ) ] ],
			[ Stats_Store::url_dim_parts( 'refuse me' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 62 ) ] ],
			[ Stats_Store::dim_parts( 'plugin', '' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 63 ) ] ],
		] );

		$this->assertSame( [ true, false, true ], $results, 'the refusal must be identified, not averaged' );
		$this->assertSame( 61, $store->bucket_get_multi( [ [ Stats_Store::dim_parts( 'status', '' ), '2026-08-27-13-05' ] ] )[0]['v'][ Stats_Store::DIM_COUNT ] ?? null, 'a good key still lands' );
	}

	public function test_rank_parts_carry_the_sort_the_order_and_the_server_key(): void {
		$this->assertSame(
			[ 'urlrank_s', Stats_Store::server_key( 'kea.test' ), 'url', 'desc' ],
			Stats_Store::url_rank_parts( 'url', 'desc', 'kea.test', false )
		);
		$this->assertSame(
			[ 'urlrank_sh', Stats_Store::server_key( 'kea.test' ), 'url', 'desc' ],
			Stats_Store::url_rank_parts( 'url', 'desc', 'kea.test', true )
		);
		$this->assertSame(
			[ 'urlrank_sh', 'done', Stats_Store::server_key( 'kea.test' ) ],
			Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'kea.test' ) ),
			'each server marks its hour ranked under a key no server key can spell'
		);
	}

	public function test_a_hash_filed_in_both_families_ranks_once_in_each(): void {
		$hash   = 'a4417ce0fa11';
		$writes = Stats_Store::ranked_writes( [ 'kea.test' => [
			Stats_Store::url_shard( $hash )       => [ $hash => self::positional_url_row( [ 'count' => 23, 'path' => '/kakapo-4417' ] ) ],
			Stats_Store::url_shard( $hash, true ) => [ $hash => self::positional_url_row( [ 'count' => 61, 'worker' => true, 'path' => '/kakapo-4417' ] ) ],
		] ], false, '2026-09-22-14-05' );
		$lists = self::ranked_lists( $writes );
		$count = static fn ( string $scope ): int => $lists[ $scope ]['count:desc'][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ];

		$this->assertSame( 23, $count( Stats_Store::server_key( 'kea.test' ) ), 'the reader row, not the worker one written over it' );
		$this->assertSame( 61, $count( 'w:' . Stats_Store::server_key( 'kea.test' ) ) );
	}

	public function test_the_errored_set_ranks_the_rows_that_errored_and_counts_by_errors(): void {
		// A timeout carries no duration: its row ranks last on the timed
		// sorts, and counts toward `count` and `errors`, never `avg_ms`. A
		// row of clean 5xx responses errs no more than the 2xx one does.
		$row    = static fn ( array $named ): array => self::positional_url_row( $named );
		$kea    = [
			'a1c1ea0a1c1e'         => $row( [ 'count' => 50, 'count_2xx' => 50, 'timed_count' => 50, 'sum_ms' => 500.0, 'path' => '/clean-5050' ] ),
			'f5003a1ea503'         => $row( [ 'count' => 3, 'count_5xx' => 3, 'timed_count' => 3, 'sum_ms' => 930.0, 'path' => '/moa-5003' ] ),
			'b2308df1ab90'         => $row( [ 'count' => 13, 'count_2xx' => 11, 'count_5xx' => 1, 'errors' => 2, 'timed_count' => 12, 'sum_ms' => 2860.0, 'sum_peak_mb' => 26.0, 'min_ms' => 55.0, 'max_ms' => 480.0, 'path' => '/weka-1308' ] ),
			'c3913e02bc01'         => $row( [ 'count' => 4, 'errors' => 4, 'sum_peak_mb' => 12.0, 'path' => '/tui-9913' ] ),
			Stats_Store::OTHER_KEY => $row( [ 'count' => 30, 'count_2xx' => 20, 'errors' => 10 ] ),
			'd4417f13cd12'         => $row( [ 'count' => 9, 'count_2xx' => 6, 'errors' => 3, 'timed_count' => 9, 'sum_ms' => 810.0, 'worker' => true, 'path' => '/jobs/digest/5?job' ] ),
		];
		$writes = Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $kea ] ), false, '2026-09-29-14-05' );
		$lists  = self::ranked_lists( $writes );
		$hashes = static fn ( array $entries ): array => \array_column( $entries, Stats_Store::RANK_HASH );
		$scope  = 'e:' . Stats_Store::server_key( 'kea.test' );

		$this->assertSame( [ 'c3913e02bc01', 'b2308df1ab90' ], $hashes( $lists[ $scope ]['count:desc'] ), 'ranked by errors, 4 over 2, not by count' );
		$this->assertSame( [ 'b2308df1ab90', 'c3913e02bc01' ], $hashes( $lists[ $scope ]['avg_ms:desc'] ), 'the timeout ranks last' );
		$this->assertSame( [ 'b2308df1ab90', 'c3913e02bc01' ], $hashes( $lists[ $scope ]['min_ms:asc'] ) );
		$this->assertSame( [ 'b2308df1ab90', 'c3913e02bc01' ], $hashes( $lists['e']['max_ms:asc'] ), 'the site\'s errored list' );
		$this->assertSame( [ 'd4417f13cd12' ], $hashes( $lists[ 'w:e:' . Stats_Store::server_key( 'kea.test' ) ]['count:desc'] ) );
		$this->assertSame( 'c3913e02bc01', \array_slice( $hashes( $lists[ Stats_Store::server_key( 'kea.test' ) ]['avg_ms:asc'] ), -1 )[0], 'every set ranks an untimed row last on a timed sort' );

		$records = [];
		foreach ( $writes as [ $parts, , $value ] ) {
			$records[ \implode( ':', $parts ) ] = $value;
		}
		$this->assertSame(
			[
				Stats_Store::HDR_COUNT       => 17,
				Stats_Store::HDR_TIMED_COUNT => 12,
				Stats_Store::HDR_SUM_MS      => 2860.0,
				Stats_Store::HDR_SUM_PEAK_MB => 38.0,
				Stats_Store::HDR_HAS_OTHER   => false,
				Stats_Store::HDR_URLS        => Url_Sketch::of( [ 'b2308df1ab90', 'c3913e02bc01' ] ),
				Stats_Store::HDR_ERRORS      => 6,
			],
			$records[ \implode( ':', Stats_Store::url_header_parts( 'kea.test', false, [ 'e' ] ) ) ] ?? null,
			'the errored rows alone, and their errors'
		);
	}

	public function test_each_list_set_takes_key_parts_of_its_own_and_moves_no_reader_key(): void {
		$kea = Stats_Store::server_key( 'kea.test' );
		$this->assertSame( [ 'urlrank_s', 'w', $kea, 'max_ms', 'asc' ], Stats_Store::url_rank_parts( 'max_ms', 'asc', 'kea.test', false, [ 'w' ] ) );
		$this->assertSame( [ 'urlrank_sh', 'e', 'max_ms', 'asc' ], Stats_Store::url_rank_parts( 'max_ms', 'asc', '', true, [ 'e' ] ) );
		$this->assertSame( [ 'urlrank_s', 'w', 'e', $kea, 'max_ms', 'asc' ], Stats_Store::url_rank_parts( 'max_ms', 'asc', 'kea.test', false, [ 'w', 'e' ] ) );
		$this->assertSame( [ 'urlrank_sh', 'max_ms', 'asc' ], Stats_Store::url_rank_parts( 'max_ms', 'asc', '', true ), 'the reader\'s site list keeps its key' );
		$this->assertSame( [ 'urlhdr', Stats_Store::HDR_SHAPE, 'w', 'e', $kea ], Stats_Store::url_header_parts( 'kea.test', false, [ 'w', 'e' ] ) );
		$this->assertSame( [ 'urlhdr_h', Stats_Store::HDR_SHAPE, 'e' ], Stats_Store::url_header_parts( '', true, [ 'e' ] ) );
		$this->assertSame( [ 'urlhdr', Stats_Store::HDR_SHAPE, $kea ], Stats_Store::url_header_parts( 'kea.test', false ), 'the reader\'s record keeps its key' );
		$this->assertSame( [ [], [ 'e' ], [ 'w' ], [ 'w', 'e' ] ], Stats_Store::RANK_SETS, 'every key writes four' );
		$this->assertSame( [ [] ], Stats_Store::rank_sets( false, false ), 'a reader asks for the sets its filters name' );
		$this->assertSame( [ [], [ 'w' ] ], Stats_Store::rank_sets( true, false ) );
		$this->assertSame( [ [ 'e' ] ], Stats_Store::rank_sets( false, true ) );
		$this->assertSame( [ [ 'e' ], [ 'w', 'e' ] ], Stats_Store::rank_sets( true, true ) );
	}

	public function test_a_tie_breaks_by_hash_whatever_order_the_rows_arrive_in(): void {
		// Four rows, three of them tied at 40ms, and the list keeps three.
		// They arrive in REVERSE hash order: a cut that kept source order
		// would keep f6 and e5, and a merge of two servers' lists could not
		// reproduce which equal row the site's page shows.
		$row  = static fn ( float $sum_ms, int $last_seen ): array => self::positional_url_row(
			[ 'count' => 5, 'timed_count' => 1, 'sum_ms' => $sum_ms, 'min_ms' => $sum_ms, 'max_ms' => $sum_ms, 'last_seen' => $last_seen ]
		);
		$rows = [
			'f6f6f6f6f6f6' => $row( 40.0, 1758500043 ),
			'e5e5e5e5e5e5' => $row( 40.0, 1758500042 ),
			'd4d4d4d4d4d4' => $row( 40.0, 1758500041 ),
			'a7a7a7a7a7a7' => $row( 90.0, 1758500044 ),
		];
		$lists  = self::rank_url_rows( $rows, 3 );
		$hashes = static fn ( array $entries ): array => \array_column( $entries, Stats_Store::RANK_HASH );
		$this->assertSame( [ 'a7a7a7a7a7a7', 'd4d4d4d4d4d4', 'e5e5e5e5e5e5' ], $hashes( $lists['avg_ms']['desc'] ) );
		$this->assertSame( [ 'd4d4d4d4d4d4', 'e5e5e5e5e5e5', 'f6f6f6f6f6f6' ], $hashes( $lists['avg_ms']['asc'] ) );
	}

	public function test_an_all_digit_hash_ranks_as_a_string(): void {
		// PHP stores a decimal-string key as an INT, so a hash of twelve
		// digits reaches the ranker as one; the entry the reader matches on
		// must still carry the hash as the string it is everywhere else.
		$hash   = '123456789012';
		$row    = self::positional_url_row( [ 'count' => 17, 'timed_count' => 2, 'sum_ms' => 34.0, 'min_ms' => 15.0, 'max_ms' => 19.0, 'last_seen' => 1758500017, 'path' => '/digits-1701' ] );
		$writes = Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => [ $hash => $row ] ] ), false, '2026-09-22-14-05' );
		$lists = self::ranked_lists( $writes )[ Stats_Store::server_key( 'kea.test' ) ];
		$this->assertCount( 14, $lists );
		foreach ( $lists as $list => $entries ) {
			$this->assertSame( [ $hash ], \array_column( $entries, Stats_Store::RANK_HASH ), $list );
		}
	}

	public function test_ranking_cuts_every_list_to_n_in_both_directions(): void {
		$rows = [
			'a1a1a1a1a1a1' => self::positional_url_row( [ 'count' => 30, 'timed_count' => 3, 'sum_ms' => 300.0, 'sum_peak_mb' => 60.0, 'min_ms' => 50.0, 'max_ms' => 150.0, 'last_seen' => 1758500030, 'path' => '/tui' ] ),
			'b2b2b2b2b2b2' => self::positional_url_row( [ 'count' => 10, 'timed_count' => 1, 'sum_ms' => 700.0, 'sum_peak_mb' => 5.0, 'min_ms' => 700.0, 'max_ms' => 700.0, 'last_seen' => 1758500010, 'path' => '/kea' ] ),
			'c3c3c3c3c3c3' => self::positional_url_row( [ 'count' => 20, 'timed_count' => 4, 'sum_ms' => 80.0, 'sum_peak_mb' => 40.0, 'min_ms' => 10.0, 'max_ms' => 40.0, 'last_seen' => 1758500020, 'path' => '/moa' ] ),
		];
		$lists = self::rank_url_rows( $rows, 2 );

		$hashes = static fn ( array $entries ): array => \array_column( $entries, Stats_Store::RANK_HASH );
		$this->assertSame( [ 'a1a1a1a1a1a1', 'c3c3c3c3c3c3' ], $hashes( $lists['count']['desc'] ) );
		$this->assertSame( [ 'b2b2b2b2b2b2', 'c3c3c3c3c3c3' ], $hashes( $lists['count']['asc'] ) );
		// The bucket's AVERAGE ranks, so the rarely hit slow page comes first.
		$this->assertSame( [ 'b2b2b2b2b2b2', 'a1a1a1a1a1a1' ], $hashes( $lists['avg_ms']['desc'] ) );
		$this->assertSame( [ 'c3c3c3c3c3c3', 'a1a1a1a1a1a1' ], $hashes( $lists['min_ms']['asc'] ) );
		$this->assertSame( [ 'b2b2b2b2b2b2', 'a1a1a1a1a1a1' ], $hashes( $lists['max_ms']['desc'] ) );
		$this->assertSame( [ 'a1a1a1a1a1a1', 'c3c3c3c3c3c3' ], $hashes( $lists['avg_peak_mb']['desc'] ) );
		$this->assertSame( [ 'a1a1a1a1a1a1', 'c3c3c3c3c3c3' ], $hashes( $lists['last_updated']['desc'] ) );
		$this->assertSame( [ 'b2b2b2b2b2b2', 'c3c3c3c3c3c3' ], $hashes( $lists['url']['asc'] ) );
		$this->assertSame( '/kea', $lists['url']['asc'][0][ Stats_Store::RANK_PATH ] );
		$this->assertArrayNotHasKey( Stats_Store::RANK_PATH, $lists['count']['desc'][0] );
		$this->assertSame( 14, \array_sum( \array_map( 'count', $lists ) ) );
		$this->assertSame( \array_keys( $rows )[0], $lists['count']['desc'][0][ Stats_Store::RANK_HASH ] );
		$this->assertSame( 30, $lists['count']['desc'][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$this->assertArrayNotHasKey( Stats_Store::ROW_PATH, $lists['url']['asc'][0][ Stats_Store::RANK_ROW ], 'the path rides beside the row, never in it' );
	}

	public function test_ranked_writes_rank_each_named_server_from_its_own_rows(): void {
		// Each server's lists come from its own rows, and the site's merge
		// them, so a site page reads one list a key. An overflow row and a worker
		// row never rank, but a server holding only those still gets its
		// lists, empty, so a reader can tell a ranked server from a missing one.
		$rows   = [
			'kea.test'  => [
				'a7a7a7a7a7a7'         => self::positional_url_row( [ 'count' => 41, 'timed_count' => 41, 'sum_ms' => 410.0, 'path' => '/kea-41' ] ),
				'b8b8b8b8b8b8'         => self::positional_url_row( [ 'count' => 13, 'path' => '/kea-13' ] ),
				Stats_Store::OTHER_KEY => self::positional_url_row( [ 'count' => 9 ] ),
			],
			'moa.test'  => [
				'c9c9c9c9c9c9' => self::positional_url_row( [ 'count' => 6, 'timed_count' => 6, 'sum_ms' => 60.0, 'path' => '/moa-6' ] ),
			],
			'weka.test' => [
				'd1d1d1d1d1d1' => self::positional_url_row( [ 'count' => 5, 'worker' => true, 'path' => '/cron' ] ),
			],
		];
		$bucket = '2026-09-22-14-05';

		$lists  = self::ranked_lists( Stats_Store::ranked_writes( self::by_shard( $rows ), false, $bucket ) );
		$counts = static fn ( array $entries ): array => \array_combine(
			\array_column( $entries, Stats_Store::RANK_HASH ),
			\array_map(
				static fn ( array $entry ): int => \Newspack_Nodes\Core::num_int( $entry[ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ),
				$entries
			)
		);

		$this->assertSame(
			[
				Stats_Store::server_key( 'kea.test' ),
				Stats_Store::server_key( 'moa.test' ),
				Stats_Store::server_key( 'weka.test' ),
				'',
				'e:' . Stats_Store::server_key( 'kea.test' ),
				'e:' . Stats_Store::server_key( 'moa.test' ),
				'e:' . Stats_Store::server_key( 'weka.test' ),
				'e',
				'w:' . Stats_Store::server_key( 'kea.test' ),
				'w:' . Stats_Store::server_key( 'moa.test' ),
				'w:' . Stats_Store::server_key( 'weka.test' ),
				'w',
				'w:e:' . Stats_Store::server_key( 'kea.test' ),
				'w:e:' . Stats_Store::server_key( 'moa.test' ),
				'w:e:' . Stats_Store::server_key( 'weka.test' ),
				'w:e',
			],
			\array_keys( $lists ),
			'each set: every server named, then the site'
		);
		$this->assertSame(
			[ 'a7a7a7a7a7a7' => 41, 'b8b8b8b8b8b8' => 13, 'c9c9c9c9c9c9' => 6 ],
			$counts( $lists['']['count:desc'] ),
			'the site\'s is every server\'s, merged'
		);
		$this->assertSame(
			[ 'a7a7a7a7a7a7' => 41, 'b8b8b8b8b8b8' => 13 ],
			$counts( $lists[ Stats_Store::server_key( 'kea.test' ) ]['count:desc'] )
		);
		$this->assertSame(
			[ 'c9c9c9c9c9c9' => 6 ],
			$counts( $lists[ Stats_Store::server_key( 'moa.test' ) ]['count:desc'] )
		);
		$this->assertSame( [], $lists[ Stats_Store::server_key( 'weka.test' ) ]['count:desc'], 'a worker-only server ranks no reader row' );
		$this->assertSame( [ 'd1d1d1d1d1d1' => 5 ], $counts( $lists[ 'w:' . Stats_Store::server_key( 'weka.test' ) ]['count:desc'] ), 'its worker row ranks in its own family' );
		$this->assertSame( [ 'd1d1d1d1d1d1' => 5 ], $counts( $lists['w']['count:desc'] ), 'and in the worker family\'s site list' );
		$this->assertSame( [], $lists[ 'w:' . Stats_Store::server_key( 'kea.test' ) ]['count:desc'], 'a server with no worker rows ranks its worker family empty' );
		$this->assertSame( '/kea-13', $lists[ Stats_Store::server_key( 'kea.test' ) ]['url:asc'][0][ Stats_Store::RANK_PATH ] );
	}

	public function test_a_url_list_ranks_the_whole_url_and_a_servers_stores_its_path(): void {
		// Path order is the reverse of URL order across the servers, and an
		// http URL kept whole sorts after its server's paths but before its
		// https URLs, so a list ranked on the stored path fails both. A
		// server's list ranks the whole URL and stores the path, so its cut is
		// its top-N by URL and the site's union of them is exact.
		$rows  = [
			'zeta.example'  => [
				'a3117c0ffee1' => self::positional_url_row( [ 'count' => 3, 'path' => '/a-3117' ] ),
				'b5531c0ffee3' => self::positional_url_row( [ 'count' => 5, 'path' => 'http://zeta.example/m-5531' ] ),
			],
			'alpha.example' => [
				'f4229c0ffee2' => self::positional_url_row( [ 'count' => 4, 'path' => '/z-4229' ] ),
			],
		];
		$lists = self::ranked_lists( Stats_Store::ranked_writes( self::by_shard( $rows ), false, '2026-09-22-14-05' ) );
		$named = static fn ( array $entries ): array => \array_column( $entries, Stats_Store::RANK_PATH, Stats_Store::RANK_HASH );
		$site  = [
			'b5531c0ffee3' => 'http://zeta.example/m-5531',
			'f4229c0ffee2' => 'https://alpha.example/z-4229',
			'a3117c0ffee1' => 'https://zeta.example/a-3117',
		];
		$zeta  = [
			'b5531c0ffee3' => 'http://zeta.example/m-5531',
			'a3117c0ffee1' => '/a-3117',
		];

		$this->assertSame( $site, $named( $lists['']['url:asc'] ), 'the site list, ascending' );
		$this->assertSame( \array_reverse( $site ), $named( $lists['']['url:desc'] ), 'the site list, descending' );
		$this->assertSame( $zeta, $named( $lists[ Stats_Store::server_key( 'zeta.example' ) ]['url:asc'] ), 'a server\'s list, ascending' );
		$this->assertSame( \array_reverse( $zeta ), $named( $lists[ Stats_Store::server_key( 'zeta.example' ) ]['url:desc'] ), 'a server\'s list, descending' );
	}

	public function test_a_hash_two_servers_share_takes_the_url_of_the_one_naming_it(): void {
		// The first server's row names no path, so it ranks on its count list
		// alone; the site's `url` list keeps the hash under the second's URL.
		$rows  = [
			'kea.example' => [ 'd7741c0ffee5' => self::positional_url_row( [ 'count' => 7, 'path' => '' ] ) ],
			'moa.example' => [ 'd7741c0ffee5' => self::positional_url_row( [ 'count' => 2, 'path' => '/ruru-7741' ] ) ],
		];
		$lists = self::ranked_lists( Stats_Store::ranked_writes( self::by_shard( $rows ), false, '2026-09-22-14-05' ) );

		$this->assertSame(
			[ 'd7741c0ffee5' => 'https://moa.example/ruru-7741' ],
			\array_column( $lists['']['url:asc'], Stats_Store::RANK_PATH, Stats_Store::RANK_HASH )
		);
	}

	public function test_a_servers_url_list_cuts_at_its_top_n_by_url(): void {
		// One http row among https paths: by path it ranks last and the cut
		// drops it, by URL first. The site list can only hold what the
		// server's list kept, so a cut on the path would lose it there too.
		$rows = [
			'zeta.example' => [
				'b5531c0ffee3' => self::positional_url_row( [ 'count' => 5, 'path' => 'http://zeta.example/m-5531' ] ),
				'a3117c0ffee1' => self::positional_url_row( [ 'count' => 3, 'path' => '/a-3117' ] ),
				'c6643c0ffee4' => self::positional_url_row( [ 'count' => 6, 'path' => '/q-6643' ] ),
			],
		];
		$lists = self::rank_url_rows( $rows['zeta.example'], 2, 'zeta.example' );

		$this->assertSame( [ 'b5531c0ffee3', 'a3117c0ffee1' ], \array_column( $lists['url']['asc'], Stats_Store::RANK_HASH ) );
		$this->assertSame( [ 'http://zeta.example/m-5531', '/a-3117' ], \array_column( $lists['url']['asc'], Stats_Store::RANK_PATH ), 'stored as the path' );
	}

	public function test_ranked_writes_keep_a_header_record_of_every_reader_row(): void {
		// The Other row never ranks, but its requests are real: the header
		// sums it and says it was there. A worker row is no reader row.
		$kea    = [
			'a7a7a7a7a7a7'         => self::positional_url_row( [ 'count' => 41, 'timed_count' => 37, 'errors' => 3, 'sum_ms' => 410.5, 'sum_peak_mb' => 82.25, 'path' => '/kea-41' ] ),
			'b8b8b8b8b8b8'         => self::positional_url_row( [ 'count' => 13, 'timed_count' => 11, 'errors' => 2, 'sum_ms' => 130.25, 'sum_peak_mb' => 26.5, 'path' => '/kea-13' ] ),
			Stats_Store::OTHER_KEY => self::positional_url_row( [ 'count' => 9, 'timed_count' => 7, 'errors' => 1, 'sum_ms' => 63.75, 'sum_peak_mb' => 4.5 ] ),
			'e5e5e5e5e5e5'         => self::positional_url_row( [ 'count' => 1000, 'timed_count' => 1000, 'errors' => 7, 'sum_ms' => 9e6, 'worker' => true, 'path' => '/cron' ] ),
		];
		$moa    = [ 'c9c9c9c9c9c9' => self::positional_url_row( [ 'count' => 6, 'timed_count' => 5, 'errors' => 5, 'sum_ms' => 60.125, 'sum_peak_mb' => 3.0, 'path' => '/moa-6' ] ) ];
		$writes = Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $kea, 'moa.test' => $moa ] ), false, '2026-09-22-14-05' );

		$record = static function ( string $server ) use ( $writes ): array {
			foreach ( $writes as [ $parts, $key, $value ] ) {
				if ( Stats_Store::url_header_parts( $server, false ) === $parts ) {
					self::assertSame( '2026-09-22-14-05', $key );
					return $value;
				}
			}
			self::fail( "no header record for '{$server}'" );
		};
		$this->assertSame(
			[
				Stats_Store::HDR_COUNT       => 63,
				Stats_Store::HDR_TIMED_COUNT => 55,
				Stats_Store::HDR_SUM_MS      => 604.5,
				Stats_Store::HDR_SUM_PEAK_MB => 113.25,
				Stats_Store::HDR_HAS_OTHER   => true,
				Stats_Store::HDR_URLS        => Url_Sketch::of( [ 'a7a7a7a7a7a7', 'b8b8b8b8b8b8' ] ),
				Stats_Store::HDR_ERRORS      => 6,
			],
			$record( 'kea.test' )
		);
		$this->assertFalse( $record( 'moa.test' )[ Stats_Store::HDR_HAS_OTHER ] );
		$this->assertSame(
			[
				Stats_Store::HDR_COUNT       => 69,
				Stats_Store::HDR_TIMED_COUNT => 60,
				Stats_Store::HDR_SUM_MS      => 664.625,
				Stats_Store::HDR_SUM_PEAK_MB => 116.25,
				Stats_Store::HDR_HAS_OTHER   => true,
				Stats_Store::HDR_URLS        => Url_Sketch::of( [ 'a7a7a7a7a7a7', 'b8b8b8b8b8b8', 'c9c9c9c9c9c9' ] ),
				Stats_Store::HDR_ERRORS      => 11,
			],
			$record( '' ),
			'the site record is the union of the servers\''
		);
		$this->assertSame( Stats_Store::NS_URLHDR_HOUR, Stats_Store::url_header_parts( 'kea.test', true )[0] );
		$worker = [];
		foreach ( $writes as [ $parts, , $value ] ) {
			$worker[ \implode( ':', $parts ) ] = $value;
		}
		$this->assertSame(
			[
				Stats_Store::HDR_COUNT       => 1000,
				Stats_Store::HDR_TIMED_COUNT => 1000,
				Stats_Store::HDR_SUM_MS      => 9e6,
				Stats_Store::HDR_SUM_PEAK_MB => 0.0,
				Stats_Store::HDR_HAS_OTHER   => false,
				Stats_Store::HDR_URLS        => Url_Sketch::of( [ 'e5e5e5e5e5e5' ] ),
				Stats_Store::HDR_ERRORS      => 7,
			],
			$worker[ \implode( ':', Stats_Store::url_header_parts( 'kea.test', false, [ Stats_Store::WORKER_SHARD_PREFIX ] ) ) ] ?? null,
			'the worker row is its own family\'s record'
		);
		$this->assertSame( 1000, $worker[ \implode( ':', Stats_Store::url_header_parts( '', false, [ Stats_Store::WORKER_SHARD_PREFIX ] ) ) ][ Stats_Store::HDR_COUNT ] ?? null );
		$this->assertSame( 0, $worker[ \implode( ':', Stats_Store::url_header_parts( 'moa.test', false, [ Stats_Store::WORKER_SHARD_PREFIX ] ) ) ][ Stats_Store::HDR_COUNT ] ?? null, 'moa.test holds no worker row: its record is the empty one' );
	}

	public function test_ranked_writes_carry_one_triple_per_list_of_each_server(): void {
		$rows   = [
			'a7a7a7a7a7a7' => self::positional_url_row( [ 'count' => 47, 'timed_count' => 2, 'sum_ms' => 88.0, 'sum_peak_mb' => 17.0, 'min_ms' => 41.0, 'max_ms' => 47.0, 'last_seen' => 1758500047, 'path' => '/kakapo-4417' ] ),
			'b8b8b8b8b8b8' => self::positional_url_row( [ 'count' => 13, 'timed_count' => 1, 'sum_ms' => 19.0, 'sum_peak_mb' => 3.0, 'min_ms' => 19.0, 'max_ms' => 19.0, 'last_seen' => 1758500013, 'path' => '/weka-1308' ] ),
		];
		$writes = Stats_Store::ranked_writes( self::by_shard( [ 'takahe.test' => $rows ] ), true, '2026-09-22-14' );

		$expected = [];
		foreach ( [ [], [ 'e' ], [ 'w' ], [ 'w', 'e' ] ] as $set ) {
			foreach ( [ 'takahe.test', '' ] as $scope ) {
				foreach ( Stats_Store::URL_SORTS as $sort ) {
					foreach ( Stats_Store::URL_ORDERS as $order ) {
						$expected[] = Stats_Store::url_rank_parts( $sort, $order, $scope, true, $set );
					}
				}
				$expected[] = Stats_Store::url_header_parts( $scope, true, $set );
			}
		}
		$this->assertSame( $expected, \array_column( $writes, 0 ), 'each set: the server\'s fourteen and its record, then the site\'s' );
		$this->assertCount( 4 * ( 15 + 15 ), $writes, 'four list sets, and no more' );
		$this->assertSame( \array_fill( 0, 120, '2026-09-22-14' ), \array_column( $writes, 1 ) );
		// The entries are the ranker's, list for list.
		$this->assertSame(
			self::rank_url_rows( $rows, Stats_Store::URL_RANK_N_HOUR )['count']['desc'],
			$writes[1][2]
		);
	}

	public function test_the_site_list_is_the_merge_of_every_servers_list(): void {
		// URLs are disjoint by server, so the site's top-N is the top-N of the
		// union of each server's top-N. 150 URLs a server is 300 in the
		// bucket: the merge has to cut to URL_RANK_N exactly as a list ranked
		// over the union would, ties and all. The writer keeps it, so a site
		// read takes one list a key, standing with its servers' gone.
		$store      = $this->stats_store( partition: 2, max_lifespan: 86400 );
		$bucket     = '2026-09-22-14-05';
		$servers    = [];
		$union      = [];
		// kea.test comes first but hashes last, so a tie cut in source order
		// keeps a different row than one cut by hash.
		foreach ( [ 'kea.test' => 0xb0000, 'moa.test' => 0xa0000 ] as $server => $base ) {
			for ( $i = 0; $i < 150; $i++ ) {
				$hash = \sprintf( '%012x', $base + $i );
				// Every seventh count repeats, so ties cross the two servers.
				$row                         = self::positional_url_row( [ 'count' => 3 + ( $i % 7 ) * 11, 'timed_count' => 1, 'sum_ms' => 7.0 + $i, 'path' => "/{$server}-{$i}" ] );
				$servers[ $server ][ $hash ] = $row;
				// The site's `url` list ranks each row's whole URL.
				$union[ $hash ]                             = $row;
				$union[ $hash ][ Stats_Store::ROW_PATH ] = "https://{$server}/{$server}-{$i}";
			}
		}
		$store->bucket_set_multi( Stats_Store::ranked_writes( self::by_shard( $servers ), false, $bucket ) );
		$store->bucket_set_multi( [ [
			Stats_Store::url_srv_parts( false ),
			$bucket,
			self::index_of( [ 'kea.test', 'moa.test' ] ),
		] ] );
		$oracle = self::ranked_lists( Stats_Store::ranked_writes( self::by_shard( [ 'site.test' => $union ] ), false, $bucket ) )[ Stats_Store::server_key( 'site.test' ) ];
		foreach ( [ 'kea.test', 'moa.test' ] as $server ) {
			foreach ( Stats_Store::URL_SORTS as $sort ) {
				foreach ( Stats_Store::URL_ORDERS as $order ) {
					$store->bucket_forget_multi( [ [ Stats_Store::url_rank_parts( $sort, $order, $server, false ), $bucket ] ] );
				}
			}
		}

		foreach ( [ 'count:desc', 'count:asc', 'url:asc', 'avg_ms:desc' ] as $list ) {
			[ $sort, $order ] = \explode( ':', $list );
			$merged           = $store->url_rank_window( [], [ $bucket ], $sort, $order, '' );
			$this->assertCount( Stats_Store::URL_RANK_N, $merged[0][1], $list );
			$this->assertSame( $oracle[ $list ], $merged[0][1], $list );
		}
		$top = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' )[0][1];
		for ( $i = 1; $i < \count( $top ); $i++ ) {
			[ $a, $b ] = [ $top[ $i - 1 ], $top[ $i ] ];
			if ( $a[ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] === $b[ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ) {
				$this->assertLessThan( 0, \strcmp( $a[ Stats_Store::RANK_HASH ], $b[ Stats_Store::RANK_HASH ] ), 'a tie breaks by hash' );
			}
		}
	}

	public function test_a_site_hour_answers_only_while_its_site_list_stands(): void {
		// The hour tier stands for twelve buckets, so an hour missing its
		// list is one the reader must not serve as ranked. A fine bucket
		// answers with the lists it has.
		$store      = $this->stats_store( partition: 2, max_lifespan: 86400 );
		$index      = self::index_of( [ 'kea.test', 'moa.test' ] );
		$rows       = [
			'kea.test' => [ 'a1a1a1a1a1a1' => self::positional_url_row( [ 'count' => 23, 'path' => '/kea-23' ] ) ],
			'moa.test' => [ 'b2b2b2b2b2b2' => self::positional_url_row( [ 'count' => 31, 'path' => '/moa-31' ] ) ],
		];
		$store->bucket_set_multi( [
			...Stats_Store::ranked_writes( self::by_shard( $rows ), true, '2026-09-22-13' ),
			...Stats_Store::ranked_writes( self::by_shard( $rows ), false, '2026-09-22-14-05' ),
			[ Stats_Store::url_srv_parts( true ), '2026-09-22-13', $index ],
			[ Stats_Store::url_srv_parts( false ), '2026-09-22-14-05', $index ],
			[ Stats_Store::url_srv_parts( true ), '2026-09-22-12', [] ],
		] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_rank_parts( 'count', 'desc', '', true ), '2026-09-22-13' ] ] );

		$this->assertSame(
			[ [ '2026-09-22-12', [] ] ],
			$store->url_rank_window( [ '2026-09-22-13', '2026-09-22-12' ], [], 'count', 'desc', '' ),
			'hour 13 lost its site list; hour 12 is folded idle'
		);
		$fine = $store->url_rank_window( [], [ '2026-09-22-14-05' ], 'count', 'desc', '' );
		$this->assertSame( [ 'b2b2b2b2b2b2', 'a1a1a1a1a1a1' ], \array_column( $fine[0][1], Stats_Store::RANK_HASH ) );
	}

	public function test_ranked_writes_cut_each_list_at_the_bound_of_its_own_tier(): void {
		// 201 rows: the fine tier keeps 200 of them, the coarse tier all 201.
		$rows = [];
		for ( $i = 0; $i < 201; $i++ ) {
			$rows[ \sprintf( '%012x', 0xa70000 + $i ) ] = self::positional_url_row( [ 'count' => 17 + $i ] );
		}
		$fine = Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $rows ] ), false, '2026-09-22-14-05' );
		$hour = Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $rows ] ), true, '2026-09-22-14' );
		$this->assertCount( Stats_Store::URL_RANK_N, $fine[1][2] );
		$this->assertCount( 201, $hour[1][2] );
	}

	public function test_an_all_digit_hash_ranks_as_a_string_in_server_and_site_lists(): void {
		// PHP files an all-digit key as an int, and the reader matches hashes as strings.
		$rows   = [ 112233445566 => self::positional_url_row( [ 'count' => 29, 'timed_count' => 3, 'sum_ms' => 87.0, 'path' => '/kakapo-29' ] ) ];
		$writes = Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $rows ] ), false, '2026-09-22-14-05' );

		foreach ( [ 'kea.test', '' ] as $scope ) {
			foreach ( $writes as [ $parts, , $entries ] ) {
				if ( Stats_Store::url_rank_parts( 'count', 'desc', $scope, false ) === $parts ) {
					$this->assertSame( '112233445566', $entries[0][ Stats_Store::RANK_HASH ], "scope '{$scope}'" );
				}
			}
		}
	}

	public function test_paths_of_names_each_url_by_its_path(): void {
		// An all-digit hash arrives as an INT key, and a name blob is
		// `hash => path` with string keys both ways.
		$this->assertSame(
			[ '112233445566' => '/kakapo-4417', 'c9c9c9c9c9c9' => '/weka-1308?q=7' ],
			Stats_Store::paths_of( [
				112233445566   => 'https://kea.test/kakapo-4417',
				'c9c9c9c9c9c9' => 'http://moa.test/weka-1308?q=7',
			] )
		);
	}

	public function test_ranking_ranks_untimed_rows_last_and_skips_unnamed_rows_on_url(): void {
		// An untimed row still counts, but it measured no duration, so it
		// ranks last on every timed sort in both orders, as the fold orders
		// it; a row with no path has no url to rank by. The overflow rows
		// that never rank are asserted through `ranked_writes()`.
		$rows = [
			'e5e5e5e5e5e5' => self::positional_url_row( [ 'count' => 40, 'timed_count' => 0, 'sum_ms' => 0.0, 'min_ms' => 0.0 ] ),
			'f6f6f6f6f6f6' => self::positional_url_row( [ 'count' => 3, 'timed_count' => 3, 'sum_ms' => 30.0, 'min_ms' => 10.0, 'max_ms' => 10.0, 'path' => '' ] ),
		];
		$lists = self::rank_url_rows( $rows, 10 );
		$hashes = static fn ( array $entries ): array => \array_column( $entries, Stats_Store::RANK_HASH );
		$this->assertSame( [ 'e5e5e5e5e5e5', 'f6f6f6f6f6f6' ], $hashes( $lists['count']['desc'] ) );
		foreach ( [ 'avg_ms', 'min_ms', 'max_ms' ] as $sort ) {
			foreach ( [ 'asc', 'desc' ] as $order ) {
				$this->assertSame( [ 'f6f6f6f6f6f6', 'e5e5e5e5e5e5' ], $hashes( $lists[ $sort ][ $order ] ), "the untimed row last on {$sort} {$order}" );
			}
		}
		$this->assertSame( [], $lists['url']['asc'], 'no path, no url rank' );
	}

	public function test_rank_sources_read_the_lists_of_the_named_scope(): void {
		$store      = $this->stats_store( partition: 2, max_lifespan: 86400 );
		$entries    = [ [ 'a1a1a1a1a1a1', self::positional_url_row( [ 'count' => 30 ] ) ] ];
		// 14-10 holds kea.test's list but no index naming it.
		$store->bucket_set_multi( [
			[ Stats_Store::url_rank_parts( 'count', 'desc', 'kea.test', false ), '2026-09-22-14-05', $entries ],
			[ Stats_Store::url_rank_parts( 'count', 'desc', 'kea.test', false ), '2026-09-22-14-10', $entries ],
			[ Stats_Store::url_srv_parts( false ), '2026-09-22-14-05', self::index_of( [ 'kea.test' ] ) ],
		] );
		$this->assertSame(
			[ [ '2026-09-22-14-05', $entries ] ],
			$store->url_rank_window( [], [ '2026-09-22-14-05', '2026-09-22-14-10' ], 'count', 'desc', 'kea.test' )
		);
		$this->assertSame( [], $store->url_rank_window( [], [ '2026-09-22-14-10' ], 'count', 'desc', '' ), 'no index names kea.test' );
	}

	public function test_index_entries_keeps_only_a_name_beside_a_shard_mask(): void {
		// A decoded index is neither typed nor keyed as stored: PHP hands back
		// an all-digit key as an int, and a truncated, corrupt or earlier-shaped
		// entry can carry anything at all. Such an entry names no server.
		$this->assertSame(
			[ '12345678' => [ Stats_Store::SRV_NAME => 'kakapo.test', Stats_Store::SRV_SHARDS => 9 ] ],
			Stats_Store::index_entries( [
				'a1a1a1a1' => 'kakapo.test',
				'b2b2b2b2' => [ 'kakapo.test' ],
				'c3c3c3c3' => [ 5, 3 ],
				'd4d4d4d4' => [ 'kakapo.test', 'x' ],
				12345678   => [ 'kakapo.test', 9 ],
			] )
		);
	}

	public function test_a_shard_mask_sets_one_bit_per_shard_and_reads_back_by_family(): void {
		$mask = Stats_Store::shard_mask( [ '3', 'c', 'w5' ] );

		$this->assertSame( ( 1 << 3 ) | ( 1 << 12 ) | ( 1 << 21 ), $mask );
		$this->assertSame( [ '3', 'c' ], Stats_Store::shards_in( $mask, false ), 'the reader family alone' );
		$this->assertSame( [ '3', 'c', 'w5' ], Stats_Store::shards_in( $mask, true ) );
	}

	/**
	 * A ranking orders the measured rows first in either direction, then by
	 * value, a tie by hash ascending either way, and a row that ties on all
	 * three keeps its arrival position. Hashes compare as bytes: by number,
	 * `9e0000000001` is 90 and would rank ahead of `100000000000`.
	 */
	public function test_rank_order_ranks_measured_first_then_value_then_hash_then_arrival(): void {
		$measured = [ 0, 1, 1, 1, 1, 1, 1, 1 ];
		$values   = [ 900.5, 41.25, 73, 41.25, 73, 73, 58.5, 58.5 ];
		$hashes   = [ 'a0f1', 'e7c2', 'b3d4', 'c9a8', 'b3d4', '05e6', '9e0000000001', '100000000000' ];

		$this->assertSame( [ 5, 2, 4, 7, 6, 3, 1, 0 ], Stats_Store::rank_order( $measured, $values, $hashes, 'desc' ) );
		$this->assertSame( [ 3, 1, 7, 6, 5, 2, 4, 0 ], Stats_Store::rank_order( $measured, $values, $hashes, 'asc' ) );
	}

	public function test_merge_index_takes_the_new_name_and_ors_the_masks(): void {
		$kea = Stats_Store::server_key( 'kea.test' );
		$moa = Stats_Store::server_key( 'moa.test' );
		$this->assertSame(
			[
				$kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => 0b1011 ],
				$moa => [ Stats_Store::SRV_NAME => 'moa.test', Stats_Store::SRV_SHARDS => 0b0100 ],
			],
			Stats_Store::merge_index(
				[ $kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => 0b0011 ] ],
				[
					$kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => 0b1001 ],
					$moa => [ Stats_Store::SRV_NAME => 'moa.test', Stats_Store::SRV_SHARDS => 0b0100 ],
				]
			)
		);
	}

	/**
	 * Seed kea.test in shard 3 and moa.test in shards b and e of two buckets,
	 * the sparse shape a real server's five minutes has.
	 *
	 * @return list<string> The buckets.
	 */
	private function seed_sparse_servers( Stats_Store $store ): array {
		$buckets = [ '2026-08-14-12-05', '2026-08-14-12-10' ];
		foreach ( $buckets as $bucket ) {
			$this->set_url_shard( $store, $bucket, '3', [ '3c3c3c3c3c3c' => [ Stats_Store::ROW_COUNT => 7 ] ], 'kea.test' );
			$this->set_url_shard( $store, $bucket, 'b', [ 'b1b1b1b1b1b1' => [ Stats_Store::ROW_COUNT => 5 ] ], 'moa.test' );
			$this->set_url_shard( $store, $bucket, 'e', [ 'e2e2e2e2e2e2' => [ Stats_Store::ROW_COUNT => 2 ] ], 'moa.test' );
		}
		return $buckets;
	}

	public function test_an_unscoped_read_asks_only_for_the_shards_the_index_names(): void {
		// A key the index does not name is a miss read for nothing: sixteen
		// asks a server a bucket where one has rows is how a poll spent its
		// budget.
		$store      = $this->stats_store();
		$buckets    = $this->seed_sparse_servers( $store );
		$this->forget_stats_asks();

		$sources = $store->url_row_sources( $buckets );

		$kea = Stats_Store::server_key( 'kea.test' );
		$moa = Stats_Store::server_key( 'moa.test' );
		$this->assertSame(
			self::sorted( [
				"{$kea}:3:{$buckets[0]}", "{$moa}:b:{$buckets[0]}", "{$moa}:e:{$buckets[0]}",
				"{$kea}:3:{$buckets[1]}", "{$moa}:b:{$buckets[1]}", "{$moa}:e:{$buckets[1]}",
			] ),
			$this->asked_url_keys(),
			'one key per shard a server wrote, not sixteen per server'
		);
		$this->assertCount( 6, $sources );
	}

	public function test_a_scoped_read_asks_only_for_its_servers_named_shards(): void {
		$store      = $this->stats_store();
		$buckets    = $this->seed_sparse_servers( $store );
		$this->forget_stats_asks();

		$store->url_row_sources( $buckets, null, false, 'moa.test' );

		$moa = Stats_Store::server_key( 'moa.test' );
		$this->assertSame(
			self::sorted( [ "{$moa}:b:{$buckets[0]}", "{$moa}:e:{$buckets[0]}", "{$moa}:b:{$buckets[1]}", "{$moa}:e:{$buckets[1]}" ] ),
			$this->asked_url_keys()
		);

		$this->forget_stats_asks();
		$this->assertSame( [], $store->url_row_sources( $buckets, 'c', false, 'moa.test' ), 'a shard the server never wrote' );
		$this->assertSame( [], $this->asked_url_keys(), 'is not asked for' );
	}

	public function test_a_scoped_rank_window_reads_an_hour_its_index_does_not_name_as_idle(): void {
		// The hour is ranked whole for every server it names; one it does not
		// name served nothing that hour, which is a list of nothing rather
		// than a list missing.
		$store      = $this->stats_store();
		$hour       = '2026-09-22-13';
		$this->set_url_rank_lists( $store, $hour, [ 'a4410ce0fa19' => [ 'url' => 'https://kea.test/kereru-41', 'count' => 41 ] ], true, 'kea.test' );

		$this->assertSame( [ [ $hour, [] ] ], $store->url_rank_window( [ $hour ], [], 'count', 'desc', 'moa.test' ) );
		$this->assertSame( [], $store->url_rank_window( [ '2026-09-22-12' ], [], 'count', 'desc', 'moa.test' ), 'an hour holding no index is still a hole' );
	}

	public function test_the_derived_probe_asks_the_index_and_markers_alone(): void {
		// A marker stands for its server's shards (the fold writes it only
		// when they all land), so the probe fetches no row.
		$store      = $this->stats_store();
		$hour       = '2026-09-21-15';
		$kea        = Stats_Store::server_key( 'kea.test' );
		$store->bucket_set_multi( [
			[ Stats_Store::url_hour_parts( $kea, 'd' ), $hour, [] ],
			[ Stats_Store::url_srv_parts( true ), $hour, [ $kea => [ Stats_Store::SRV_NAME => 'kea.test', Stats_Store::SRV_SHARDS => Stats_Store::shard_mask( [ 'd' ] ) ] ] ],
		] );
		$this->forget_stats_asks();

		$this->assertSame( [ $hour => [ 'kea.test' ] ], $store->url_hours_derived( [ $hour ] ) );
		$this->assertSame( [], $this->asked_url_keys( Stats_Store::NS_URLS_HOUR ) );
		$this->assertSame(
			[ [ Stats_Store::key_at( Stats_Store::url_rank_done_parts( $kea ), $hour ) ] ],
			$this->asked_batches( Stats_Store::NS_URLRANK_HOUR_S )
		);
	}
	/**
	 * @param list<string> $keys
	 * @return list<string>
	 */
	private static function sorted( array $keys ): array {
		\sort( $keys );
		return $keys;
	}

	public function test_the_derived_probe_reports_rows_and_lists_apart(): void {
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		// Hours 07 and 09 hold every key of the one server their index names;
		// 08 holds a chart hour key and no index. A chart key is no URL fold's
		// business: the flush writes it through.
		self::fold_url_hours( $store, [ '2026-09-21-07', '2026-09-21-09' ], [ 'kea.test' ] );
		$store->bucket_set_multi( [
			[ Stats_Store::lb_parts( '' ), '2026-09-21-07', [] ],
			[ Stats_Store::lb_parts( '' ), '2026-09-21-08', [] ],
		] );
		$this->assertSame(
			[
				'2026-09-21-07' => [ 'kea.test' ],
				'2026-09-21-09' => [ 'kea.test' ],
			],
			$store->url_hours_derived( [ '2026-09-21-07', '2026-09-21-08', '2026-09-21-09' ] )
		);
	}

	public function test_the_derived_probe_names_each_server_of_an_hour_left_unranked(): void {
		// The marker is per server: kea.test's hour is ranked and moa.test's
		// is not, so the probe names moa.test alone and the ranker re-ranks
		// that server's hour rather than every server's.
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		self::fold_url_hours( $store, [ '2026-09-21-11' ], [ 'kea.test', 'moa.test' ] );
		$this->set_url_rank_lists_of( $store, [ '2026-09-21-11' ], [ 'kea.test' => [] ], true );
		$this->assertSame(
			[ '2026-09-21-11' => [ 'moa.test' ] ],
			$store->url_hours_derived( [ '2026-09-21-11' ] )
		);

		$this->set_url_rank_lists_of( $store, [ '2026-09-21-11' ], [ 'moa.test' => [] ], true );
		$this->assertSame(
			[ '2026-09-21-11' => [] ],
			$store->url_hours_derived( [ '2026-09-21-11' ] )
		);
	}

	public function test_a_record_of_another_layout_at_the_same_precision_reads_missing(): void {
		// Same precision, fields in another order: read by position it would
		// stand, its sums swapped. Here, the layout keyed by precision alone.
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$bucket = '2026-09-21-22-40';
		$rows   = [ 'a1a1a1a1a1a1' => self::positional_url_row( [ 'count' => 29, 'path' => '/kea-29' ] ) ];
		$store->bucket_set_multi( [
			...Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $rows ] ), false, $bucket ),
			[ Stats_Store::url_srv_parts( false ), $bucket, self::index_of( [ 'kea.test' ] ) ],
		] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_header_parts( '', false ), $bucket ] ] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_header_parts( 'kea.test', false ), $bucket ] ] );
		$older = [ 29, 437.0, 23, 11.5, false, Url_Sketch::of( [ 'a1a1a1a1a1a1' ] ) ];
		$by_precision = 'p' . Url_Sketch::PRECISION;
		$store->bucket_set_multi( [
			[ [ Stats_Store::NS_URLHDR, $by_precision ], $bucket, $older ],
			[ [ Stats_Store::NS_URLHDR, $by_precision, Stats_Store::server_key( 'kea.test' ) ], $bucket, $older ],
		] );

		$this->assertSame( [ $bucket => [ null ] ], $store->url_headers( [], [ $bucket ], 'kea.test' ) );
	}

	public function test_a_record_of_another_shape_reads_missing(): void {
		// A PRECISION change leaves the records it wrote before in place; read
		// by length they fold every header. Here, the records the release
		// before this one wrote, at its key.
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$bucket = '2026-09-21-22-40';
		$rows   = [ 'a1a1a1a1a1a1' => self::positional_url_row( [ 'count' => 29, 'path' => '/kea-29' ] ) ];
		$store->bucket_set_multi( [
			...Stats_Store::ranked_writes( self::by_shard( [ 'kea.test' => $rows ] ), false, $bucket ),
			[ Stats_Store::url_srv_parts( false ), $bucket, self::index_of( [ 'kea.test' ] ) ],
		] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_header_parts( '', false ), $bucket ] ] );
		$store->bucket_forget_multi( [ [ Stats_Store::url_header_parts( 'kea.test', false ), $bucket ] ] );
		$older = [ 29, 29, 0.0, 0.0, false, \str_repeat( "\1", 4096 ) ];
		$store->bucket_set_multi( [
			[ [ Stats_Store::NS_URLHDR ], $bucket, $older ],
			[ [ Stats_Store::NS_URLHDR, Stats_Store::server_key( 'kea.test' ) ], $bucket, $older ],
		] );

		$this->assertSame( [ $bucket => [ null ] ], $store->url_headers( [], [ $bucket ], 'kea.test' ) );
	}

	public function test_a_record_holding_raw_registers_reads_missing(): void {
		// The v3 layout stored the registers raw. The shape in the key moves
		// with the layout, and a raw sketch under it is no sketch either.
		$this->assertSame( 'v5p14', Stats_Store::HDR_SHAPE );
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$bucket = '2026-09-29-15-35';
		$store->bucket_set_multi( [
			[ Stats_Store::url_srv_parts( false ), $bucket, self::index_of( [ 'kea.test' ] ) ],
			[ Stats_Store::url_header_parts( 'kea.test', false ), $bucket, [ 31, 31, 0.0, 0.0, false, \str_repeat( "\3", Url_Sketch::BYTES ), 4 ] ],
		] );

		$this->assertSame( [ $bucket => [ null ] ], $store->url_headers( [], [ $bucket ], 'kea.test' ) );
	}

	public function test_a_reader_memoizes_no_index_a_failed_read_missed(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hour  = '2026-09-21-19';
		self::fold_url_hours( $store, [ $hour ], [ 'kea.test' ] );
		$store->server_indexes = [];
		$this->break_stats_table( Stats_Store::TABLE_AGGREGATE );

		$this->assertSame( [], $store->server_index( [ $hour ], [], $failed ) );
		$this->assertTrue( $failed );
		$this->mend_stats_table( Stats_Store::TABLE_AGGREGATE );
		$this->assertSame( [ Stats_Store::server_key( 'kea.test' ) ], \array_keys( $store->server_index( [ $hour ], [] )[ $hour ] ?? [] ), 'the next read asks again' );
	}

	public function test_a_bucket_read_no_table_answered_reads_as_failed(): void {
		// Every read answers a Table that did not alike: nothing, and failed.
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$this->set_leaderboard_hour( $store, '2026-09-21-19', [ 'count' => 43 ] );
		$this->break_stats_table( Stats_Store::TABLE_AGGREGATE );

		$this->assertSame( [], $store->get_leaderboard_hours( [ '2026-09-21-19' ], '', $failed ) );
		$this->assertTrue( $failed );
	}

	public function test_the_derived_read_says_when_a_table_left_it_unanswered(): void {
		$store = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$hour  = '2026-09-21-19';
		self::fold_url_hours( $store, [ $hour ], [ 'kea.test' ] );
		$this->break_stats_table( Stats_Store::TABLE_AGGREGATE );

		$store->url_hours_derived( [ $hour ], $failed );

		$this->assertTrue( $failed );
	}

	public function test_the_fine_rank_tiers_live_in_the_fine_table_and_the_hour_tiers_do_not(): void {
		// A list outliving the fine rows it ranks is one nothing reads, so it
		// lives where they do; the hour's lists live with the hour's rows.
		$store  = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$bucket = '2026-09-22-14-35';
		$hour   = '2026-09-22-14';
		$fine   = [ Stats_Store::url_rank_parts( 'count', 'desc', 'kea.test', false ), Stats_Store::url_header_parts( 'kea.test', false ) ];
		$coarse = [ Stats_Store::url_rank_parts( 'count', 'desc', 'kea.test', true ), Stats_Store::url_header_parts( 'kea.test', true ) ];
		$store->bucket_set_multi( [
			[ $fine[0], $bucket, [ 'kea-4471' ] ],
			[ $coarse[0], $hour, [ 'kea-4471' ] ],
			[ $fine[1], $bucket, [ 4471 ] ],
			[ $coarse[1], $hour, [ 4471 ] ],
		] );

		foreach ( $fine as $parts ) {
			$key = Stats_Store::key_at( $parts, $bucket );
			$this->assertNotNull( $this->stats_table_value( Stats_Store::TABLE_URL_FINE, 0, $key ), "{$key} in the fine Table" );
			$this->assertNull( $this->stats_table_value( Stats_Store::TABLE_AGGREGATE, 0, $key ), "{$key} nowhere else" );
		}
		foreach ( $coarse as $parts ) {
			$key = Stats_Store::key_at( $parts, $hour );
			$this->assertNotNull( $this->stats_table_value( Stats_Store::TABLE_AGGREGATE, 0, $key ), "{$key} in the aggregate Table" );
			$this->assertNull( $this->stats_table_value( Stats_Store::TABLE_URL_FINE, 0, $key ), "{$key} nowhere else" );
		}
	}

	public function test_term_tokens_are_lowercase_alphanumeric_runs_of_two_or_more(): void {
		$this->assertSame( [ 'wombat', '7731', 'kea', 'json' ], Stats_Store::term_tokens( '/Wombat-7731/kea/a/?json=1' ) );
		$this->assertSame( [ 'internationa' ], Stats_Store::term_tokens( 'Internationalization' ) );
		$this->assertSame( [], Stats_Store::term_tokens( '-/-' ) );
	}

	public function test_term_matches_reads_a_term_the_way_the_index_files_it(): void {
		$path = '/kakapo/nest-9317';
		$this->assertTrue( Stats_Store::term_matches( $path, [ 'kakapo' ] ) );
		$this->assertTrue( Stats_Store::term_matches( '/KAKAPO/nest-9317', [ 'kakapo' ] ), 'the name is lowercased' );
		$this->assertTrue( Stats_Store::term_matches( $path, [ 'kakapo', 'nest' ] ), 'every token, in any order' );
		$this->assertFalse( Stats_Store::term_matches( $path, [ 'kakapo', 'weka' ] ), 'one token missing refuses' );
		// The index files whole words, so a token is a whole word or nothing.
		$this->assertFalse( Stats_Store::term_matches( $path, [ 'kaka' ] ), 'a word PREFIX never matches' );
		$this->assertFalse( Stats_Store::term_matches( $path, [ '317' ] ), 'nor does an infix' );
		$this->assertTrue( Stats_Store::term_matches( '/internationalization', [ 'internationa' ] ), 'a long word, cut as it is filed' );
	}

	public function test_a_path_is_filed_under_exactly_its_whole_words(): void {
		// A two-character word is filed; one character is no word; a word
		// past TERM_WORD_MAX is filed as its first TERM_WORD_MAX characters.
		$this->assertSame(
			[ 'wombat', '7731', 'at', 'internationa' ],
			\array_map( 'strval', \array_keys( Stats_Store::token_sets_of( [ 'aa11bb22cc33' => '/wombat-7731/at/x/internationalization/wombat' ] ) ) )
		);
		$this->assertSame( 12, Stats_Store::TERM_WORD_MAX );
	}

	public function test_token_sets_of_groups_every_named_path_by_token(): void {
		$this->assertSame(
			[
				'tui'  => [ 'aa11bb22cc33', 'ab12cd34ef56' ],
				'tuis' => [ 'dd44ee55ff66' ],
			],
			Stats_Store::token_sets_of( [
				'aa11bb22cc33' => '/tui',
				'dd44ee55ff66' => '/tuis',
				'ab12cd34ef56' => '/tui/9',
			] )
		);
	}

	/**
	 * A filed word is a set of members: each URL's hash, valued by the tick
	 * its name was written at, living the retention window, an hour and a
	 * flush from that add.
	 */
	public function test_a_filed_member_carries_its_tick_and_lives_the_window_an_hour_and_a_flush(): void {
		[ $window, $client, $names ] = $this->stats_store_args( 3, 7_411 );
		$store = new Stats_Store( $window, $client, $names );
		$set   = Stats_Store::key_at( [ ...Stats_Store::url_token_parts( Stats_Store::server_key( 'kea.test' ) ), 'kokako' ], Stats_Store::token_bucket( 1_600_000_321 ) );
		$read  = static fn (): array => $client->members( $names[ Stats_Store::TABLE_AGGREGATE ], [ $set ], 10 );
		$clock = Core::$clock;
		try {
			Core::$clock = static fn (): int => 1_700_000_321;
			$this->assertSame( [ true ], $store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'a1a1a1a1a1a1' ] ] ], 1_600_000_321 ) );
			$this->assertSame( [ $set => [ 'a1a1a1a1a1a1' => 1_600_000_321 ] ], $read() );

			Core::$clock = static fn (): int => 1_700_000_321 + 7_411 + 3_604;
			$this->assertSame( [ $set => [ 'a1a1a1a1a1a1' => 1_600_000_321 ] ], $read(), 'a second short of the window, an hour and a flush' );
			Core::$clock = static fn (): int => 1_700_000_321 + 7_411 + 3_605;
			$this->assertSame( [], $read(), 'the window, an hour and a flush from its add retire it' );
		} finally {
			Core::$clock = $clock;
		}
	}

	/** Filing a URL again refreshes its member's stamp and expiry, and a new URL joins the set. */
	public function test_a_refiled_url_refreshes_its_member_and_a_new_one_joins(): void {
		[ $window, $client, $names ] = $this->stats_store_args( 3, 7_411 );
		$store = new Stats_Store( $window, $client, $names );
		$key   = Stats_Store::server_key( 'kea.test' );
		$set   = Stats_Store::key_at( [ ...Stats_Store::url_token_parts( $key ), 'kokako' ], Stats_Store::token_bucket( 1_600_000_100 ) );
		$clock = Core::$clock;
		try {
			Core::$clock = static fn (): int => 1_700_000_000;
			$store->add_url_tokens( [ [ $key, 'kokako', [ 'a1a1a1a1a1a1' ] ] ], 1_600_000_100 );
			Core::$clock = static fn (): int => 1_700_005_000;
			$store->add_url_tokens( [ [ $key, 'kokako', [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ] ] ], 1_600_005_200 );
			Core::$clock = static fn (): int => 1_700_000_000 + 7_411 + 3_605;

			$this->assertSame(
				[ $set => [ 'a1a1a1a1a1a1' => 1_600_005_200, 'b2b2b2b2b2b2' => 1_600_005_200 ] ],
				$client->members( $names[ Stats_Store::TABLE_AGGREGATE ], [ $set ], 10 ),
				'the first add\'s window has passed; the second add\'s has not'
			);
		} finally {
			Core::$clock = $clock;
		}
	}

	public function test_a_word_set_key_carries_its_six_hour_bucket_after_the_namespace(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$this->forget_stats_asks();
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'a1a1a1a1a1a1' ] ] ], \gmmktime( 16, 47, 13, 9, 22, 2026 ) );

		$this->assertSame( 21_600, Stats_Store::TOKEN_BUCKET_SECONDS );
		$this->assertSame( '2026-09-22-12', Stats_Store::token_bucket( \gmmktime( 16, 47, 13, 9, 22, 2026 ) ) );
		$this->assertSame(
			[ 'SADD' => [ [ 'urltoken:2026-09-22-12:' . Stats_Store::server_key( 'kea.test' ) . ':kokako' ] ] ],
			$this->asked_verbs( Stats_Store::NS_URLTOKEN )
		);
	}

	public function test_a_search_finds_a_url_filed_in_an_earlier_bucket_of_the_window(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'a1a1a1a1a1a1' ] ] ], \gmmktime( 8, 13, 5, 9, 22, 2026 ) );
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'b2b2b2b2b2b2' ] ] ], \gmmktime( 16, 2, 41, 9, 22, 2026 ) );

		$sets = $store->url_token_sets( [ 'kokako' ], [ 'kea.test' ], \gmmktime( 16, 47, 13, 9, 22, 2026 ) );

		$this->assertEqualsCanonicalizing( [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ], Core::arr( $sets['kokako'] ?? null ), 'the 06 bucket\'s member beside the 12 bucket\'s' );
	}

	public function test_a_url_filed_only_in_a_bucket_behind_the_window_is_not_found(): void {
		// A member still alive in a bucket the window has left is not read:
		// the long-lived store files it, the 12-hour window's search skips it.
		$long  = $this->stats_store( partition: 3, max_lifespan: 172_811 );
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$long->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'a1a1a1a1a1a1' ] ] ], \gmmktime( 20, 13, 5, 9, 21, 2026 ) );
		$this->forget_stats_asks();

		$sets = $store->url_token_sets( [ 'kokako' ], [ 'kea.test' ], \gmmktime( 16, 47, 13, 9, 22, 2026 ) );

		$this->assertSame( [], $sets );
		$key = static fn ( string $bucket ): string => "urltoken:{$bucket}:" . Stats_Store::server_key( 'kea.test' ) . ':kokako';
		$this->assertSame(
			[ 'SMEMBERS' => [ [ $key( '2026-09-22-00' ), $key( '2026-09-22-06' ), $key( '2026-09-22-12' ) ] ] ],
			$this->asked_verbs( Stats_Store::NS_URLTOKEN ),
			'the three buckets the window covers, one exchange'
		);
	}

	public function test_a_word_over_the_limit_in_any_bucket_narrows_nothing(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$over  = [];
		for ( $i = 0; $i <= Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$over[] = \sprintf( 'e%011x', $i );
		}
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', $over ] ], \gmmktime( 8, 13, 5, 9, 22, 2026 ) );
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'b2b2b2b2b2b2' ] ] ], \gmmktime( 16, 2, 41, 9, 22, 2026 ) );

		$this->assertSame( [ 'kokako' => false ], $store->url_token_sets( [ 'kokako' ], [ 'kea.test' ], \gmmktime( 16, 47, 13, 9, 22, 2026 ) ) );
	}

	public function test_a_word_whose_buckets_together_pass_the_limit_narrows_nothing(): void {
		// Each bucket alone is under URL_SEARCH_MAX; their union is one past it.
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$half  = \intdiv( Stats_Store::URL_SEARCH_MAX, 2 );
		$early = [];
		$late  = [];
		for ( $i = 0; $i < $half; $i++ ) {
			$early[] = \sprintf( 'e%011x', $i );
			$late[]  = \sprintf( 'f%011x', $i );
		}
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', $early ] ], \gmmktime( 8, 13, 5, 9, 22, 2026 ) );
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', $late ] ], \gmmktime( 16, 2, 41, 9, 22, 2026 ) );
		$now = \gmmktime( 16, 47, 13, 9, 22, 2026 );
		$this->assertCount( Stats_Store::URL_SEARCH_MAX, Core::arr( $store->url_token_sets( [ 'kokako' ], [ 'kea.test' ], $now )['kokako'] ?? null ), 'the max itself narrows' );

		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', [ 'd4d4d4d4d4d4' ] ] ], \gmmktime( 11, 21, 9, 9, 22, 2026 ) );

		$this->assertSame( [ 'kokako' => false ], $store->url_token_sets( [ 'kokako' ], [ 'kea.test' ], $now ) );
	}

	public function test_a_member_filed_before_the_window_starts_is_no_candidate_and_counts_toward_no_limit(): void {
		// At 02:10:04 the window starts at 15:00. The 14:37:51 member is still
		// alive in a bucket the window reads, but no row it indexes is read:
		// without it the word holds exactly URL_SEARCH_MAX and still narrows.
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$clock = Core::$clock;
		try {
			$file = static function ( int $at, array $hashes ) use ( $store ): void {
				Core::$clock = static fn (): int => $at;
				$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kokako', $hashes ] ], $at );
			};
			$fresh = [];
			for ( $i = 0; $i < Stats_Store::URL_SEARCH_MAX; $i++ ) {
				$fresh[] = \sprintf( 'f%011x', $i );
			}
			$file( \gmmktime( 14, 37, 51, 9, 22, 2026 ), [ 'a1a1a1a1a1a1' ] );
			$file( \gmmktime( 20, 13, 5, 9, 22, 2026 ), $fresh );
			$now         = \gmmktime( 2, 10, 4, 9, 23, 2026 );
			Core::$clock = static fn (): int => $now;

			$this->assertSame( [ '2026-09-22-12', '2026-09-22-18', '2026-09-23-00' ], $store->token_buckets( $now ), 'the stale member\'s bucket is read' );
			$this->assertSame( $fresh, $store->url_token_sets( [ 'kokako' ], [ 'kea.test' ], $now )['kokako'] ?? null );
		} finally {
			Core::$clock = $clock;
		}
	}

	/**
	 * A bucket set names each URL its server filed rows for in that bucket,
	 * one set per server, and a reader learns which servers named each hash.
	 */
	public function test_a_bucket_set_names_each_url_under_the_servers_that_filed_it(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$kea   = Stats_Store::server_key( 'kea.test' );
		$moa   = Stats_Store::server_key( 'moa.test' );
		$this->forget_stats_asks();

		$landed = $store->add_url_buckets(
			[
				[ '2026-10-04-13-35', $kea, [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ] ],
				[ '2026-10-04-13-35', $moa, [ 'b2b2b2b2b2b2', '481169627974' ] ],
			],
			\gmmktime( 13, 41, 7, 10, 4, 2026 )
		);

		$this->assertSame( [ true, true ], $landed );
		$this->assertSame(
			[ 'SADD' => [ [ "urlbucket:2026-10-04-13-35:{$kea}", "urlbucket:2026-10-04-13-35:{$moa}" ] ] ],
			$this->asked_verbs( Stats_Store::NS_URLBUCKET ),
			'one add, and no read'
		);
		$this->assertSame(
			[
				'a1a1a1a1a1a1' => [ $kea => [ '2026-10-04-13' ] ],
				'b2b2b2b2b2b2' => [ $kea => [ '2026-10-04-13' ], $moa => [ '2026-10-04-13' ] ],
				'481169627974' => [ $moa => [ '2026-10-04-13' ] ],
			],
			$store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea, $moa ], $failed )
		);
		$this->assertFalse( $failed );
		$this->assertSame(
			[ 'a1a1a1a1a1a1' => [ $kea => [ '2026-10-04-13' ] ], 'b2b2b2b2b2b2' => [ $kea => [ '2026-10-04-13' ] ] ],
			$store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea ] ),
			'only the servers asked'
		);
	}

	/**
	 * Each set answers in its own place, so a refused one is named and the
	 * sets beside it still land. A key holding whitespace is one no Table
	 * takes.
	 */
	public function test_a_refused_set_answers_false_in_its_place(): void {
		$store = $this->stats_store( partition: 2, max_lifespan: 43_219 );
		$kea   = Stats_Store::server_key( 'kea.test' );

		$landed = $store->add_url_buckets(
			[
				[ '2026-10-04-13-35', $kea, [ 'a1a1a1a1a1a1' ] ],
				[ 'refuse me', $kea, [ 'b2b2b2b2b2b2' ] ],
				[ '2026-10-04-13-40', $kea, [ 'c3c3c3c3c3c3' ] ],
			],
			\gmmktime( 13, 41, 7, 10, 4, 2026 )
		);

		$this->assertSame( [ true, false, true ], $landed );
		$this->assertSame( [ 'c3c3c3c3c3c3' => [ $kea => [ '2026-10-04-13' ] ] ], $store->url_bucket_members( [ '2026-10-04-13-40' ], [ $kea ] ) );
	}

	/**
	 * A URL a selection's buckets name is one hash carrying each server that
	 * named it, with the hours whose buckets named it there, newest first,
	 * each once.
	 */
	public function test_a_selection_names_each_url_once_with_the_hours_naming_it(): void {
		$store = $this->stats_store( partition: 1, max_lifespan: 43_219 );
		$kea   = Stats_Store::server_key( 'kea.test' );
		$moa   = Stats_Store::server_key( 'moa.test' );
		$store->add_url_buckets(
			[
				[ '2026-10-04-14-05', $kea, [ 'b2b2b2b2b2b2' ] ],
				[ '2026-10-04-13-35', $kea, [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ] ],
				[ '2026-10-04-13-50', $kea, [ 'b2b2b2b2b2b2' ] ],
				[ '2026-10-04-13-50', $moa, [ 'b2b2b2b2b2b2', 'd4d4d4d4d4d4' ] ],
			],
			\gmmktime( 14, 7, 11, 10, 4, 2026 )
		);
		$this->forget_stats_asks();

		$members = $store->url_bucket_members( [ '2026-10-04-14-05', '2026-10-04-13-50', '2026-10-04-13-35' ], [ $kea, $moa ], $failed );

		$this->assertFalse( $failed );
		$this->assertEqualsCanonicalizing( [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2', 'd4d4d4d4d4d4' ], \array_keys( $members ) );
		$this->assertSame( [ $kea => [ '2026-10-04-14', '2026-10-04-13' ], $moa => [ '2026-10-04-13' ] ], $members['b2b2b2b2b2b2'] );
		$this->assertSame( [ $kea => [ '2026-10-04-13' ] ], $members['a1a1a1a1a1a1'] );
		$this->assertSame(
			[
				'SMEMBERS' => [
					[ "urlbucket:2026-10-04-14-05:{$kea}", "urlbucket:2026-10-04-14-05:{$moa}" ],
					[ "urlbucket:2026-10-04-13-50:{$kea}", "urlbucket:2026-10-04-13-50:{$moa}", "urlbucket:2026-10-04-13-35:{$kea}", "urlbucket:2026-10-04-13-35:{$moa}" ],
				],
			],
			$this->asked_verbs( Stats_Store::NS_URLBUCKET ),
			'one exchange an hour, and no page past it'
		);
	}

	public function test_another_buckets_set_answers_nothing_for_this_one(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$kea   = Stats_Store::server_key( 'kea.test' );
		$store->add_url_buckets( [ [ '2026-10-04-13-40', $kea, [ 'a1a1a1a1a1a1' ] ] ], \gmmktime( 13, 44, 2, 10, 4, 2026 ) );

		$this->assertSame( [], $store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea ], $failed ) );
		$this->assertFalse( $failed, 'a set never filed is an answer, not a failure' );
		$this->assertSame( [ 'a1a1a1a1a1a1' => [ $kea => [ '2026-10-04-13' ] ] ], $store->url_bucket_members( [ '2026-10-04-13-40' ], [ $kea ] ) );
	}

	/**
	 * A bucket member lives as long as the aggregate Table keeps the
	 * `url_row_h` rows it indexes, an hour and a flush more: at a 7,411 s
	 * window that is 90,000 + 3,605 s, far past a word's 7,411 + 3,605.
	 */
	public function test_a_bucket_member_lives_the_aggregate_tables_ttl_an_hour_and_a_flush(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 7_411 );
		$kea   = Stats_Store::server_key( 'kea.test' );
		$clock = Core::$clock;
		$at    = static function ( int $offset ): void {
			Core::$clock = static fn (): int => 1_700_000_321 + $offset;
		};
		try {
			$at( 0 );
			$store->add_url_buckets( [ [ '2026-10-04-13-35', $kea, [ 'a1a1a1a1a1a1' ] ] ], 1_700_000_321 );
			$at( 7_411 + 3_605 );
			$this->assertSame( [ 'a1a1a1a1a1a1' => [ $kea => [ '2026-10-04-13' ] ] ], $store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea ] ), 'past a word\'s lifetime' );
			$at( 90_000 + 3_604 );
			$this->assertSame( [ 'a1a1a1a1a1a1' => [ $kea => [ '2026-10-04-13' ] ] ], $store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea ] ), 'a second short' );
			$at( 90_000 + 3_605 );
			$this->assertSame( [], $store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea ] ), 'retired' );
		} finally {
			Core::$clock = $clock;
		}
	}

	/**
	 * A bucket's set is read whole however many URLs it names: 12,345
	 * members, past what one `SMEMBERS` answers, come back beside another
	 * server's, the hour's sets asked in one exchange and only the set over
	 * the limit paged after it. A read the Table left unanswered is a failure, `[]`
	 * with `$failed` set, never a short list.
	 */
	public function test_a_bucket_set_is_read_whole_and_an_unanswered_read_fails(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 43_219 );
		$kea   = Stats_Store::server_key( 'kea.test' );
		$moa   = Stats_Store::server_key( 'moa.test' );
		$named = \array_map( static fn ( int $i ): string => \sprintf( 'e%011x', $i ), \range( 1, 12_345 ) );
		$store->add_url_buckets(
			[
				[ '2026-10-04-13-35', $kea, $named ],
				[ '2026-10-04-13-35', $moa, [ 'b2b2b2b2b2b2' ] ],
			],
			\gmmktime( 13, 41, 7, 10, 4, 2026 )
		);

		$this->forget_stats_asks();

		$members = $store->url_bucket_members( [ '2026-10-04-13-35' ], [ $kea, $moa ], $failed );

		$this->assertFalse( $failed, 'the Table answered every page' );
		$this->assertSame(
			[
				'SMEMBERS' => [ [ "urlbucket:2026-10-04-13-35:{$kea}", "urlbucket:2026-10-04-13-35:{$moa}" ] ],
				'SSCAN'    => [ [ "urlbucket:2026-10-04-13-35:{$kea}" ], [ "urlbucket:2026-10-04-13-35:{$kea}" ] ],
			],
			$this->asked_verbs( Stats_Store::NS_URLBUCKET ),
			'the hour in one exchange, then pages for the set past its limit alone'
		);
		// A count, not the map: a passing assertion exports its whole value.
		$this->assertSame( 12_346, \count( $members ) );
		$this->assertSame( [ $kea => [ '2026-10-04-13' ] ], $members['e00000003039'] );
		$this->assertSame( [ $moa => [ '2026-10-04-13' ] ], $members['b2b2b2b2b2b2'] );

		$this->refuse_stats_reads( '/^urlbucket:/' );
		$this->assertSame( [], $store->url_bucket_members( [ '2026-10-04-13-35' ], [ $moa ], $failed ) );
		$this->assertTrue( $failed, 'a read the Table left unanswered' );
	}

	/**
	 * A URL name lives its Table's TTL, an hour and a flush from its write:
	 * the aggregate Table keeps rows at least the 25 hours a chart reads, and
	 * a reader resolving one of them must still find its name. At a 7,411 s
	 * window that is 90,000 + 3,605 s, far past the words' 7,411 + 3,605.
	 */
	public function test_a_url_name_lives_its_tables_ttl_an_hour_and_a_flush(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 7_411 );
		$clock = Core::$clock;
		$at    = static function ( int $offset ): void {
			Core::$clock = static fn (): int => 1_700_000_321 + $offset;
		};
		try {
			$at( 0 );
			$store->set_url_names( [ 'kea.test' => [ 'a1a1a1a1a1a1' => 'https://kea.test/kokako-7731' ] ] );
			$at( 41_113 );
			$this->assertSame( [ 'a1a1a1a1a1a1' ], \array_keys( $store->get_url_names( [ 'a1a1a1a1a1a1' ] ) ), 'unseen past the search window, and still named' );
			$at( 90_000 + 3_604 );
			$this->assertSame( [ 'a1a1a1a1a1a1' ], \array_keys( $store->get_url_names( [ 'a1a1a1a1a1a1' ] ) ), 'a second short' );
			$at( 90_000 + 3_605 );
			$this->assertSame( [], $store->get_url_names( [ 'a1a1a1a1a1a1' ] ), 'retired' );
		} finally {
			Core::$clock = $clock;
		}
	}

	public function test_token_sets_read_the_tokens_the_store_holds_in_one_members_exchange(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 86400 );
		$store->add_url_tokens(
			[
				[ Stats_Store::server_key( 'kea.test' ), 'womb', [ 'a1a1a1a1a1a1' ] ],
				[ Stats_Store::server_key( 'moa.test' ), 'womb', [ 'b2b2b2b2b2b2' ] ],
				[ Stats_Store::server_key( 'tui.test' ), 'womb', [ 'c3c3c3c3c3c3' ] ],
			],
			1_700_000_000
		);
		$this->forget_stats_asks();
		$this->assertSame(
			[ 'womb' => [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ] ],
			$store->url_token_sets( [ 'womb', 'kiwi' ], [ 'kea.test', 'moa.test' ], 1_700_000_000 ),
			'the servers named, unioned; tui.test is not asked'
		);
		$keys = [];
		foreach ( [ [ 'womb', 'kea.test' ], [ 'womb', 'moa.test' ], [ 'kiwi', 'kea.test' ], [ 'kiwi', 'moa.test' ] ] as [ $word, $server ] ) {
			foreach ( $store->token_buckets( 1_700_000_000 ) as $bucket ) {
				$keys[] = Stats_Store::key( Stats_Store::NS_URLTOKEN, $bucket, Stats_Store::server_key( $server ), $word );
			}
		}
		$this->assertCount( 20, $keys, 'a day\'s window at 22:13 spans five buckets' );
		$this->assertSame(
			[ 'SMEMBERS' => [ $keys ] ],
			$this->asked_verbs( Stats_Store::NS_URLTOKEN ),
			'every bucket of every word of every server in one exchange, never one a server'
		);
		$asked = \Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness::ask_recorder()->asked;
		$this->assertStringStartsWith( 'SMEMBERS ' . Stats_Store::URL_SEARCH_MAX . ' ', (string) \end( $asked )['value'], 'a set past the max answers over, not its members' );
		$this->assertSame( [ 'womb' => [ 'b2b2b2b2b2b2' ] ], $store->url_token_sets( [ 'womb' ], [ 'moa.test' ], 1_700_000_000 ) );
		$this->assertSame( [], $store->url_token_sets( [ 'womb' ], [], 1_700_000_000 ), 'no server, nothing held' );
	}

	public function test_filing_many_words_is_one_add_and_no_read(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 86400 );
		$this->forget_stats_asks();

		$landed = $store->add_url_tokens(
			[
				[ Stats_Store::server_key( 'kea.test' ), 'wombat', [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ] ],
				[ Stats_Store::server_key( 'kea.test' ), '7731', [ 'a1a1a1a1a1a1' ] ],
				[ Stats_Store::server_key( 'moa.test' ), 'wombat', [ 'c3c3c3c3c3c3' ] ],
			],
			1_700_000_000
		);

		$this->assertSame( [ true, true, true ], $landed );
		$verbs = $this->asked_verbs( Stats_Store::NS_URLTOKEN );
		$this->assertSame( [ 'SADD' ], \array_keys( $verbs ), 'written blind: nothing is read first' );
		$this->assertCount( 1, $verbs['SADD'] );
	}

	public function test_forgetting_many_keys_asks_each_table_once(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 86400 );
		$this->forget_stats_asks();

		$store->bucket_forget_multi( [
			[ Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'kea.test' ) ), '2026-09-29-11' ],
			[ Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'moa.test' ) ), '2026-09-29-11' ],
			[ Stats_Store::url_header_parts( 'kea.test', false ), '2026-09-29-11-35' ],
		] );

		$removes = \array_values( \array_filter(
			\Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness::ask_recorder()->asked,
			static fn ( array $asked ): bool => \is_string( $asked['value'] ) && \str_starts_with( $asked['value'], 'RM ' )
		) );
		$this->assertCount( 2, $removes, 'one RM for the aggregate Table, one for the fine' );
		$this->assertSame( 2, \substr_count( (string) $removes[0]['value'], ':2026-09-29-11:done:' ) );
	}

	public function test_a_token_read_that_goes_unanswered_answers_no_token(): void {
		// Decision 3: an unanswered set is no absence, so no token narrows.
		$store = $this->stats_store( partition: 3, max_lifespan: 86400 );
		$store->add_url_tokens( [ [ Stats_Store::server_key( 'kea.test' ), 'kereru', [ 'a1a1a1a1a1a1' ] ] ], 1_700_000_000 );
		$this->refuse_stats_reads( '/^' . Stats_Store::NS_URLTOKEN . ':/' );

		$sets = $store->url_token_sets( [ 'kereru', 'hoiho' ], [ 'kea.test' ], 1_700_000_000, $failed );

		$this->assertSame( [ 'kereru' => false, 'hoiho' => false ], $sets );
		$this->assertTrue( $failed );
	}

	public function test_a_word_past_the_search_max_narrows_nothing_and_a_two_character_one_reads_its_set(): void {
		// A set of more than URL_SEARCH_MAX members reads null, over the
		// limit, and narrows nothing; one at the max still does. A
		// two-character word is filed and read like any other.
		$store = $this->stats_store( partition: 3, max_lifespan: 86400 );
		$over  = [];
		$at    = [];
		for ( $i = 0; $i <= Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$over[] = \sprintf( 'e%011x', $i );
			$at[]   = \sprintf( 'f%011x', $i );
		}
		\array_pop( $at );
		$store->add_url_tokens(
			[
				// One server's set past the max is the word's answer across both.
				[ Stats_Store::server_key( 'kea.test' ), 'wombat', $over ],
				[ Stats_Store::server_key( 'moa.test' ), 'wombat', [ 'd4d4d4d4d4d4' ] ],
				[ Stats_Store::server_key( 'kea.test' ), 'at', [ 'e5e5e5e5e5e5' ] ],
				[ Stats_Store::server_key( 'kea.test' ), 'takahe', $at ],
			],
			1_700_000_000
		);

		$sets = $store->url_token_sets( [ 'at', 'wombat', 'takahe' ], [ 'moa.test', 'kea.test' ], 1_700_000_000 );

		$this->assertSame( [ 'at', 'wombat', 'takahe' ], \array_keys( $sets ) );
		$this->assertSame( [ 'e5e5e5e5e5e5' ], $sets['at'] );
		$this->assertFalse( $sets['wombat'], 'URL_SEARCH_MAX + 1 members narrow nothing' );
		$this->assertCount( Stats_Store::URL_SEARCH_MAX, Core::arr( $sets['takahe'] ), 'the max itself still narrows' );
	}

	/** A term's words are read longest first, ties in term order, three at a time. */
	public function test_search_groups_are_the_longest_words_first_three_at_a_time(): void {
		$this->assertSame( 3, Stats_Store::SEARCH_WORDS_READ );
		$this->assertSame(
			[ [ 'category', 'kakapo', 'blog' ], [ '2026', 'post', 'ox' ], [ 'x7' ] ],
			Stats_Store::search_groups( [ 'blog', '2026', 'category', 'post', 'kakapo', 'ox', 'x7' ] )
		);
		$this->assertSame( [], Stats_Store::search_groups( [] ) );
	}

	/** One read names one group at most, so no caller's term sets the read's multiplier. */
	public function test_a_read_of_more_words_than_one_group_is_refused(): void {
		$store = $this->stats_store( partition: 3, max_lifespan: 86400 );
		$this->forget_stats_asks();

		try {
			$store->url_token_sets( [ 'kakapo', 'takahe', 'kiwi', 'kea' ], [ 'kea.test' ], 1_700_000_000 );
			$this->fail( 'four words read at once' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'reads at most 3 words', $e->getMessage() );
		}
		$this->assertSame( [], $this->asked_verbs( Stats_Store::NS_URLTOKEN ), 'refused before any read' );
	}

	/**
	 * `ranked_writes()` lists as server key, '' for the site's, =>
	 * `sort:order` => entries, leaving out the header records beside them.
	 *
	 * @param list<array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes
	 * @return array<string,array<string,array<array-key,mixed>>>
	 */
	private static function ranked_lists( array $writes ): array {
		$lists = [];
		foreach ( $writes as [ $parts, , $entries ] ) {
			if ( \in_array( $parts[0], [ Stats_Store::NS_URLHDR, Stats_Store::NS_URLHDR_HOUR ], true ) ) {
				continue;
			}
			// Between the namespace and the sort: the family part, then the server key.
			$scope = \implode( ':', \array_slice( $parts, 1, -2 ) );
			$lists[ $scope ][ $parts[ \count( $parts ) - 2 ] . ':' . $parts[ \count( $parts ) - 1 ] ] = $entries;
		}
		return $lists;
	}

	/**
	 * The private one-scope ranker, cut to `$n` rather than a tier's bound.
	 *
	 * @param array<string,array<array-key,mixed>> $rows   One scope's rows by hash.
	 * @param int                                  $n      Entries per list.
	 * @param string                               $server The server the rows are filed under.
	 * @return array<string,array<string,list<array<int,mixed>>>>
	 */
	private static function rank_url_rows( array $rows, int $n, string $server = 'kea.test' ): array {
		/** @var array<string,array<string,list<array<int,mixed>>>> */
		return ( new \ReflectionMethod( Stats_Store::class, 'rank_url_rows' ) )->invoke( null, $rows, $n, false, $server );
	}

	public function test_the_estimate_follows_the_serializer_the_handle_is_configured_with(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY );
		$igbinary = Stats_Store::overhead( 'url_row' );

		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP );
		$php = Stats_Store::overhead( 'url_row' );
		$this->assertGreaterThan( $igbinary, $php, 'serialize() spells a row longer' );

		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY );
		$this->assertSame( $igbinary, Stats_Store::overhead( 'url_row' ), 'read on every estimate, never memoized' );
		Core::$memd = null;
		$this->assertSame( $php, Stats_Store::overhead( 'url_row' ), 'no handle estimates the larger' );
	}

	public function test_an_unknown_part_has_no_estimate(): void {
		$this->expectException( \LogicException::class );
		Stats_Store::overhead( 'no_such_part' );
	}

	/**
	 * A hash lives in one shard, but every shard carries its own overflow row
	 * under the one key, so merging a server's shards sums those and keeps
	 * the rest.
	 */
	public function test_merge_shard_rows_sums_every_shards_overflow_row(): void {
		$row = static fn ( int $count, float $ms ): array => [ Stats_Store::ROW_COUNT => $count, Stats_Store::ROW_TIMED_COUNT => $count, Stats_Store::ROW_SUM_MS => $ms ];

		$merged = Stats_Store::merge_shard_rows(
			[ 'a7713' => $row( 3, 30.0 ), Stats_Store::OTHER_KEY => $row( 70, 700.0 ) ],
			[ 'b7713' => $row( 5, 50.0 ), Stats_Store::OTHER_KEY => $row( 13, 130.0 ) ]
		);

		$this->assertSame( 83, $merged[ Stats_Store::OTHER_KEY ][ Stats_Store::ROW_COUNT ] );
		$this->assertEqualsWithDelta( 830.0, $merged[ Stats_Store::OTHER_KEY ][ Stats_Store::ROW_SUM_MS ], 0.001 );
		$this->assertSame( 3, $merged['a7713'][ Stats_Store::ROW_COUNT ] );
		$this->assertSame( 5, $merged['b7713'][ Stats_Store::ROW_COUNT ] );
		$this->assertSame( 91, Stats_Store::url_header_of( $merged )[ Stats_Store::HDR_COUNT ] );
	}

	public function test_a_bucket_names_its_slot_in_its_hour(): void {
		$this->assertSame( 12, Stats_Store::SLOTS_PER_HOUR );
		$this->assertSame( 25, Stats_Store::CHART_HOURS );
		$this->assertSame( [ 0, 7, 11 ], \array_map( Stats_Store::slot_of( ... ), [ '2026-09-29-14-00', '2026-09-29-14-35', '2026-09-29-14-55' ] ) );
		$this->assertSame( '2026-09-29-14-35', Stats_Store::buckets_in_hour( '2026-09-29-14' )[ Stats_Store::slot_of( '2026-09-29-14-35' ) ] );
	}

	public function test_a_slotted_hour_reads_back_as_the_buckets_it_holds(): void {
		$store = $this->stats_store( 3, 86400 );
		$store->bucket_set_multi( [ [ Stats_Store::dim_parts( 'ua', '' ), '2026-09-29-14', [
			7  => [ 'kea-ua/7' => self::dim_entry( 41, 4.1, 0.4 ) ],
			9  => [ 'kea-ua/7' => self::dim_entry( 43, 4.3, 0.4 ) ],
			10 => [],
		] ] ] );

		$got = $store->get_slots( Stats_Store::dim_parts( 'ua', '' ), [ '2026-09-29-14', '2026-09-29-13' ] );

		$this->assertSame( [ '2026-09-29-14-35', '2026-09-29-14-45' ], \array_keys( $got ), 'a slot never filled, or filled with nothing, is no bucket' );
		$this->assertSame( 43, $got['2026-09-29-14-45']['kea-ua/7'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_a_url_hour_is_one_row_holding_every_dimension(): void {
		$store = $this->stats_store( 3, 86400 );
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'c0ffee7731ab' ), '2026-09-29-14-35', [ 'kea-ua/7' => self::dim_entry( 41, 4.1, 0.4 ) ], 'ua' );
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'c0ffee7731ab' ), '2026-09-29-14-40', [ '5xx' => self::dim_entry( 43, 4.3, 0.4 ) ], 'status' );

		$this->assertSame(
			[
				'ua'     => [ 7 => [ 'kea-ua/7' => self::dim_entry( 41, 4.1, 0.4 ) ] ],
				'status' => [ 8 => [ '5xx' => self::dim_entry( 43, 4.3, 0.4 ) ] ],
			],
			$this->stats_table_value( Stats_Store::TABLE_AGGREGATE, 3, 'url_dim_h:2026-09-29-14:c0ffee7731ab' ),
			'one row a URL-hour, each dimension its own slotted hour inside'
		);
		$this->assertSame(
			[ '2026-09-29-14-35' => [ 'kea-ua/7' => self::dim_entry( 41, 4.1, 0.4 ) ] ],
			$store->get_slots( Stats_Store::url_dim_parts( 'c0ffee7731ab' ), [ '2026-09-29-14' ], 'ua' ),
			'a read names the dimension it draws'
		);
	}

	/**
	 * Decision 1: every key one bucket or hour touches in a namespace sits
	 * together in key order, the time part right after the namespace, and
	 * a key with no time part keeps its shape.
	 */
	public function test_every_time_keyed_namespace_puts_its_time_after_the_namespace(): void {
		$bucket = '2026-09-29-14-35';
		$hour   = '2026-09-29-14';
		$kea    = Stats_Store::server_key( 'kea.test' );
		$shape  = Stats_Store::HDR_SHAPE;
		$cases  = [
			"urls:{$bucket}:{$kea}:w3"                 => [ Stats_Store::url_shard_parts( $kea, 'w3' ), $bucket ],
			"urls_h:{$hour}:{$kea}:a"                  => [ Stats_Store::url_hour_parts( $kea, 'a' ), $hour ],
			"urlsrv:{$bucket}"                         => [ Stats_Store::url_srv_parts( false ), $bucket ],
			"urlsrv_h:{$hour}"                         => [ Stats_Store::url_srv_parts( true ), $hour ],
			"urlrank_s:{$bucket}:w:e:{$kea}:max_ms:asc" => [ Stats_Store::url_rank_parts( 'max_ms', 'asc', 'kea.test', false, [ 'w', 'e' ] ), $bucket ],
			"urlrank_sh:{$hour}:url:desc"              => [ Stats_Store::url_rank_parts( 'url', 'desc', '', true ), $hour ],
			"urlrank_sh:{$hour}:done:{$kea}"           => [ Stats_Store::url_rank_done_parts( $kea ), $hour ],
			"urlhdr:{$bucket}:{$shape}:w:{$kea}"       => [ Stats_Store::url_header_parts( 'kea.test', false, [ 'w' ] ), $bucket ],
			"urlhdr_h:{$hour}:{$shape}:e"              => [ Stats_Store::url_header_parts( '', true, [ 'e' ] ), $hour ],
			"dim_h:{$hour}:ua:{$kea}"                  => [ Stats_Store::dim_parts( 'ua', 'kea.test' ), $hour ],
			"lb_h:{$hour}"                             => [ Stats_Store::lb_parts( '' ), $hour ],
			"lb_sh:{$hour}:{$kea}"                     => [ Stats_Store::lb_parts( 'kea.test' ), $hour ],
			"categories_h:{$hour}:{$kea}"              => [ Stats_Store::cat_parts( 'kea.test' ), $hour ],
			"hourly_h:{$hour}"                         => [ Stats_Store::hourly_parts(), $hour ],
			"url_cat_h:{$hour}:c0ffee7731ab"           => [ Stats_Store::url_cat_parts( 'c0ffee7731ab' ), $hour ],
			"url_dim_h:{$hour}:c0ffee7731ab"           => [ Stats_Store::url_dim_parts( 'c0ffee7731ab' ), $hour ],
			'urlmap:c0ffee7731ab'                      => [ [ Stats_Store::NS_URLMAP ], 'c0ffee7731ab' ],
			'url:c0ffee7731ab'                         => [ [ Stats_Store::NS_URL ], 'c0ffee7731ab' ],
		];
		foreach ( $cases as $expected => [ $parts, $at ] ) {
			$this->assertSame( $expected, Stats_Store::key_at( $parts, $at ) );
		}

		$store = $this->stats_store( 5, 86400 );
		$store->bucket_set_multi( [ [ Stats_Store::lb_parts( 'kea.test' ), $hour, [ 'count' => 67 ] ] ] );
		$this->assertSame( [ 'count' => 67 ], $this->stats_table_value( Stats_Store::TABLE_AGGREGATE, 5, "lb_sh:{$hour}:{$kea}" ), 'a write lands under the key the pair names' );
	}

	public function test_a_leaderboard_hour_reads_back_as_one_sum(): void {
		$store = $this->stats_store( 3, 86400 );
		$this->set_leaderboard_hour( $store, '2026-09-29-14', [ 'count' => 84, 'sum_req_time' => 8.4, 'categories' => [] ] );

		$this->assertSame( 84, $store->get_leaderboard_hours( [ '2026-09-29-14' ] )['2026-09-29-14']['count'] ?? null );
	}
}
