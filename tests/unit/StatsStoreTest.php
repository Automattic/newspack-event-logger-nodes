<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

#[CoversClass( Stats_Store::class )]
class StatsStoreTest extends TestCase {

	/** Seed the shared handle and hand it back for introspection. */
	private function seed_memd(): InMemoryMemcached {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		return $mc;
	}

	private function make_store( int $partition = 0, int $max_lifespan = 86400 ): Stats_Store {
		if ( null === Core::$memd ) {
			$this->seed_memd();
		}
		return new Stats_Store( partition: $partition, max_lifespan: $max_lifespan );
	}

	public function test_a_fine_url_bucket_expires_with_its_read_window(): void {
		// 288 fine buckets a shard were held for the whole window while the
		// read plan looks at thirteen of them plus the rest of their hour. The
		// unread remainder is the largest thing this schema puts in a 512MB
		// cache, and the coarse tier already answers for it.
		$mc    = $this->seed_memd();
		$store = $this->make_store( max_lifespan: 86400 );
		$this->set_url_bucket( $store, '2026-08-14-12-05', [ 'a1b2c3d4e5f6' => [ 'count' => 3 ] ] );
		$this->set_url_hour( $store, '2026-08-14-12', 'a', [ 'a1b2c3d4e5f6' => [ Stats_Store::ROW_COUNT => 3 ] ] );

		$now  = \time();
		$srv  = Stats_Store::server_key( self::SEED_SERVER );
		$fine = 0;
		$hour = 0;
		foreach ( $mc->expiries() as $key => $expires ) {
			if ( \str_contains( $key, ':' . Stats_Store::NS_URLS . ":{$srv}:a:" ) ) {
				$fine = $expires - $now;
			}
			if ( \str_contains( $key, ':' . Stats_Store::NS_URLS_HOUR . ":{$srv}:a:" ) ) {
				$hour = $expires - $now;
			}
		}
		$this->assertGreaterThan( 0, $fine, 'the fine bucket was found' );
		$this->assertLessThanOrEqual( Stats_Store::FINE_TTL_SECONDS, $fine, 'a fine bucket outlives its readers by hours, not a day' );
		$this->assertGreaterThan( Stats_Store::FINE_TTL_SECONDS, $hour, 'the coarse tier still carries the window' );
	}

	public function test_the_row_index_tables_cover_every_index_contiguously(): void {
		// `fold_index_row()` reads ROW_FIELD_NAMES[ $index ] unguarded, per
		// row, on the hot read path, and the two tables are hand-matched.
		// Decision 18's "the eight that ADD come FIRST" is otherwise only
		// prose: a ninth summed field appended past the end works and
		// falsifies it silently. The path is the one string, and it is last.
		$this->assertSame( \range( 0, 13 ), \array_keys( Stats_Store::ROW_FIELD_NAMES ) );
		$this->assertSame( \range( 0, 7 ), \array_keys( Stats_Store::ROW_SUMS ) );
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
		$this->assertSame( 'https://kea.test/a', Stats_Store::row_path( 'https://kea.test/a', '' ), 'an unnamed server implies nothing' );
		$this->assertSame( '/bare-7731', Stats_Store::row_path( '/bare-7731', 'kea.test' ) );
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
		// index set and a fourth field appended past the end moves this.
		$this->assertSame( \range( 0, 2 ), \array_keys( Stats_Store::DIM_SUMS ) );
	}

	public function test_a_merged_entry_keeps_only_what_its_field_table_names(): void {
		// The positional switch is the first change to depend on the stated
		// invariant, and it was not true: the merged entry kept `$into`'s own
		// keys too. A pre-deploy `{t,c,n}` entry summed with a positional one
		// became a six-key hybrid that `json_encode` emits as an OBJECT —
		// larger than either shape, and re-mirrored that way for the bucket's
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
		// competed for one item's budget. The bucket stays LAST in the key, so
		// `is_open_bucket()` and the durable read-through are untouched.
		$this->seed_memd();
		$store = $this->make_store( partition: 2 );
		$hash  = 'a1b2c3d4e5f6';
		$other = '0f0f0f0f0f0f';

		$this->set_url_bucket( $store, '2026-08-14-12-05', [
			$hash  => [ 'url' => '/a', 'count' => 3 ],
			$other => [ 'url' => '/b', 'count' => 5 ],
		] );

		$table = \Newspack_Nodes\Table_Node::table( Stats_Store::namespace_for( 2 ), 60 );
		$srv   = Stats_Store::server_key( self::SEED_SERVER );
		$this->assertSame(
			[ $hash => [ 'count' => 3, 'path' => '/a' ] ],
			self::named_url_rows( \Newspack_Nodes\Core::arr( $table->lookup( Stats_Store::NS_URLS . ":{$srv}:a:2026-08-14-12-05" ) ) ),
			'a row lands in its server\'s shard its hash names'
		);
		$this->assertSame(
			[ $other => [ 'count' => 5, 'path' => '/b' ] ],
			self::named_url_rows( \Newspack_Nodes\Core::arr( $table->lookup( Stats_Store::NS_URLS . ":{$srv}:0:2026-08-14-12-05" ) ) )
		);
	}

	public function test_the_url_index_keys_by_server_and_the_index_names_them(): void {
		// One server's rows per key: a busy spoke cannot fold a quiet one's
		// URLs into `Other`, and a scoped read is its own keys. The index is
		// what an unscoped read enumerates, and each pair says whose it is.
		$mc     = $this->seed_memd();
		$store  = $this->make_store();
		$bucket = '2026-08-14-12-05';
		$this->set_url_bucket( $store, $bucket, [ 'a1b2c3d4e5f6' => [ 'url' => 'https://kea.test/kea-41', 'count' => 41 ] ], 'kea.test' );
		$this->set_url_bucket( $store, $bucket, [ 'a9a9a9a9a9a9' => [ 'url' => 'https://moa.test/moa-6', 'count' => 6 ] ], 'moa.test' );

		$this->assertSame(
			[ $bucket => [ Stats_Store::server_key( 'kea.test' ) => 'kea.test', Stats_Store::server_key( 'moa.test' ) => 'moa.test' ] ],
			$store->server_index( [], [ $bucket, '2026-08-14-12-00' ] )
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

		$mc->multi_calls = 0;
		$this->assertSame(
			[ 'moa.test' => [ 'a9a9a9a9a9a9' => 6 ] ],
			$counts( $store->url_row_sources( [ $bucket ], 'a', false, 'moa.test' ) ),
			'a scoped read is that server\'s keys alone'
		);
		$this->assertSame( 1, $mc->multi_calls, 'and needs no index to find them' );
	}

	public function test_a_server_past_the_cap_is_admitted_as_other(): void {
		// The cap bounds the KEYS a bucket writes whatever Host headers
		// arrive: a named server keeps its key, a new one takes a free slot,
		// and past MAX_SERVER_VALUES the rest share the `Other` key.
		$index = [];
		for ( $i = 0; $i < Stats_Store::MAX_SERVER_VALUES - 1; $i++ ) {
			$name                                    = \sprintf( 'spoke%03d.test', $i );
			$index[ Stats_Store::server_key( $name ) ] = $name;
		}
		$index[ Stats_Store::server_key( Stats_Store::OTHER_KEY ) ] = Stats_Store::OTHER_KEY;

		$this->assertSame(
			[ 'spoke007.test' => 'spoke007.test', 'late-1.test' => 'late-1.test', 'late-2.test' => Stats_Store::OTHER_KEY ],
			Stats_Store::admit_servers( $index, [ 'spoke007.test', 'late-1.test', 'late-2.test' ] ),
			'the Other key holds no slot of the 128'
		);
	}

	public function test_the_whole_window_is_still_one_round_trip(): void {
		// Decision 1: "one `lookup_multi` serves them all"; decision 6: per-key
		// gets are the latency cliff a dashboard read exists to avoid. Sharding
		// multiplies KEYS, which memcached is built for — it must not multiply
		// ROUND TRIPS, which is what a loop of multi-gets per shard would do.
		$mc    = $this->seed_memd();
		$store = $this->make_store();
		$this->set_url_bucket( $store, '2026-08-14-12-05', [
			'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 3 ],
			'0f0f0f0f0f0f' => [ 'url' => '/b', 'count' => 5 ],
		] );

		$mc->multi_calls = 0;
		$sources         = $store->url_row_sources( [ '2026-08-14-12-05', '2026-08-14-12-00' ] );

		// The server index is the one read the keyspace adds before it.
		$this->assertSame( 2, $mc->multi_calls, 'the index, then every server\'s every shard in one round trip' );
		$this->assertNotEmpty( $sources );
	}

	public function test_writing_a_whole_bucket_replaces_the_whole_bucket(): void {
		// "Whole bucket, replacing what is stored" has to mean every shard, not
		// only the ones the new data names — otherwise a second seed leaves the
		// first one's rows live in a shard it never mentioned, and a test reads
		// both as if they arrived together.
		$this->seed_memd();
		$store = $this->make_store();
		$this->set_url_bucket( $store, '2026-08-14-12-05', [ 'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 3 ] ] );

		$this->set_url_bucket( $store, '2026-08-14-12-05', [ '0f0f0f0f0f0f' => [ 'url' => '/b', 'count' => 5 ] ] );

		$this->assertSame( [], $this->get_url_shard( $store, '2026-08-14-12-05', 'a' ) );
		$this->assertSame(
			[ '0f0f0f0f0f0f' => [ 'count' => 5, 'path' => '/b' ] ],
			self::named_url_rows( $this->get_url_shard( $store, '2026-08-14-12-05', '0' ) )
		);
	}

	public function test_the_read_plan_splits_the_window_into_fine_buckets_and_hours(): void {
		// The readers need TWO resolutions — the whole window, and the last
		// complete hour — so the window edge is the only thing five-minute
		// buckets buy, and only at the recent end. Everything behind the recent
		// tail reads as hours: 13 + 23 keys per shard rather than 288.
		$now  = \gmmktime( 14, 37, 0, 8, 27, 2026 );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) );

		// FINE_BUCKETS is a FLOOR: the tail runs to the end of the hour it
		// lands in, so that hour is read at one resolution rather than half at
		// each. At :37 that is 13 + the seven below 13-35.
		$this->assertCount( 20, $plan['fine'] );
		$this->assertSame( '2026-08-27-14-35', $plan['fine'][0], 'newest first, floored to the width' );
		$this->assertSame( '2026-08-27-13-00', \end( $plan['fine'] ) );
		// The hours behind them, newest first. The hour the fine tail reaches
		// into is NOT among them, or its traffic would be counted twice.
		$this->assertSame( '2026-08-27-12', $plan['hours'][0] );
		$this->assertSame( 23, \count( $plan['hours'] ) );
		// The oldest is WHOLE, so the window's far edge is hour-granular and
		// rounds outward — a 24h read may carry up to 59 extra minutes rather
		// than drop real traffic. Five-minute precision there bought nothing.
		$this->assertSame( '2026-08-26-14', \end( $plan['hours'] ) );
	}

	/**
	 * The plan must COVER the window. Reading it at two resolutions is the
	 * point; reading part of it at neither is a hole, and a hole here is
	 * traffic missing from every `urls` and `dump_url` answer — silently,
	 * and by an amount that breathes with the clock.
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
			$this->assertSame(
				[],
				\array_values( \array_diff( $window, $read ) ),
				"buckets in the window that no tier reads, at :{$minute}"
			);
		}
	}

	public function test_an_hour_names_the_twelve_buckets_it_covers(): void {
		$buckets = Stats_Store::buckets_in_hour( '2026-08-27-13' );

		$this->assertCount( 12, $buckets );
		$this->assertSame( '2026-08-27-13-00', $buckets[0] );
		$this->assertSame( '2026-08-27-13-55', \end( $buckets ) );
	}

	public function test_hour_sources_read_the_coarse_tier_in_one_round_trip(): void {
		$mc    = $this->seed_memd();
		$store = $this->make_store();
		$this->set_url_hour( $store, '2026-08-27-13', 'a', [ 'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 9 ] ] );

		$mc->multi_calls = 0;
		$sources         = $store->url_hour_sources( [ '2026-08-27-13', '2026-08-27-12' ], 'a' );

		$this->assertSame( 2, $mc->multi_calls, 'the hour index, then the rows' );
		$this->assertSame( [ [ '2026-08-27-13', [ 'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 9 ] ], self::SEED_SERVER ] ], $sources );
	}

	public function test_reading_the_whole_index_merges_every_shard(): void {
		$this->seed_memd();
		$store = $this->make_store();
		$this->set_url_bucket( $store, '2026-08-14-12-05', [
			'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 3 ],
			'0f0f0f0f0f0f' => [ 'url' => '/b', 'count' => 5 ],
		] );

		$rows = $this->url_rows_by_bucket( $store, [ '2026-08-14-12-05' ] )['2026-08-14-12-05'];

		$this->assertCount( 2, $rows );
		$this->assertSame( 3, $rows['a1b2c3d4e5f6']['count'] );
		$this->assertSame( 5, $rows['0f0f0f0f0f0f']['count'] );
	}

	public function test_reads_and_writes_go_through_a_table(): void {
		// The port: Stats_Store is a Table_Node consumer, not a second raw-handle
		// cache. A value it writes must be readable through a Table on the same
		// namespace and key — which is only true once the key derivation is the
		// Table's, not Stats_Store's own.
		$this->seed_memd();
		$store = $this->make_store( partition: 3 );

		$this->assertTrue( $this->set_hourly_bucket( $store, '2026-08-14T12', [ 'count' => 7391 ] ) );

		$table = \Newspack_Nodes\Table_Node::table( Stats_Store::namespace_for( 3 ), 60 );
		$this->assertSame(
			[ 'count' => 7391 ],
			$table->lookup( Stats_Store::NS_HOURLY . ':2026-08-14T12' ),
			'the Table reads back exactly what Stats_Store wrote'
		);
	}

	public function test_a_refused_write_is_not_mirrored(): void {
		// The mirror shadows stats durably for cold-boot replay, so a write the
		// backend refused must not be recorded — it would be resurrected as
		// though it had landed.
		$this->seed_memd();
		Core::$memd = new class() extends InMemoryMemcached {
			public function set( $key, $value, $expiration = 0 ): bool {
				return false;
			}
		};
		$store    = $this->make_store();
		$mirrored = [];
		$store->mirror = function ( string $key, array $data, int $ttl, string $ns ) use ( &$mirrored ): void {
			$mirrored[] = $ns;
		};

		$this->assertFalse( $this->set_hourly_bucket( $store, 'x', [ 'count' => 1 ] ), 'a refused write reports false' );
		$this->assertSame( [], $mirrored, 'and is not shadowed' );
	}

	public function test_every_read_is_empty_with_no_cache_backend(): void {
		// Fail-soft is the contract: Table_Node::table() THROWS without a
		// backing store, so the port must build lazily behind that check.
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$this->assertSame( [], $this->get_hourly_bucket( $store, 'x' ) );
		$this->assertSame( [], $this->url_rows_by_bucket( $store, [ 'b1', 'b2' ] ) );
		$this->assertNull( $store->get_url_stats( 'abc' ) );
		$this->assertFalse( $this->set_hourly_bucket( $store, 'x', [ 'count' => 1 ] ), 'a write with no backend reports false' );
	}

	public function test_stats_keys_are_scoped_to_this_install(): void {
		// Stats live in memcache alone, and two installs share one server on
		// Atomic. Unscoped, `evlog:p0:hourly` is the SAME key for both, so a
		// co-tenant's request volume lands in this install's dashboard.
		$mc = $this->seed_memd();
		// Own the shim here; the bootstrap's comes back at the end, since a
		// later suite in the same process asks it for esc_like().
		$previous_wpdb   = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $prefix      = 'wp_';
			public string $base_prefix = 'wp_';
		};
		\Newspack_Nodes\Cache_Backend::$site = '';

		try {
			$this->set_hourly_bucket( $this->make_store(), '2026-01-01-00', [ "count" => 7719 ] );
			$mine = $mc->keys();

			$GLOBALS['wpdb']->base_prefix       = 'wpco_tenant_';
			\Newspack_Nodes\Cache_Backend::$site = '';
			$this->set_hourly_bucket( $this->make_store(), '2026-01-01-00', [ "count" => 1 ] );
		} finally {
			\Newspack_Nodes\Cache_Backend::$site = '';
			$GLOBALS['wpdb']                     = $previous_wpdb;
		}

		$this->assertNotEmpty( $mine );
		$this->assertSame( [], \array_intersect( $mine, \array_diff( $mc->keys(), $mine ) ) );
		foreach ( $mc->keys() as $key ) {
			$this->assertStringStartsWith( 'newspack_nodes:', $key );
		}
	}

	/** Decision 1: the namespace is the key's first segment, and the store is what reads it. */
	public function test_namespace_of_reads_the_keys_first_segment(): void {
		$this->assertSame( Stats_Store::NS_LB_HOUR, Stats_Store::namespace_of( Stats_Store::NS_LB_HOUR . ':2026-02-03-04' ) );
		$this->assertSame( 'urls', Stats_Store::namespace_of( 'urls:0a1b2c3d:7:2026-02-03-04-05' ) );
		$this->assertSame( 'hourly', Stats_Store::namespace_of( 'hourly' ) );
	}

	/** The bucket is the key's last segment, whatever sits between it and the namespace. */
	public function test_bucket_of_reads_the_keys_last_segment(): void {
		$this->assertSame( '2026-02-03-04-05', Stats_Store::bucket_of( 'lb_s:web07:2026-02-03-04-05' ) );
		$this->assertSame( '2026-02-03-04', Stats_Store::bucket_of( Stats_Store::NS_LB_HOUR . ':2026-02-03-04' ) );
		$this->assertSame( 'hourly', Stats_Store::bucket_of( 'hourly' ) );
	}

	public function test_namespace_constants_exist(): void {
		$this->assertSame( 'hourly', Stats_Store::NS_HOURLY );
		$this->assertSame( 'lb', Stats_Store::NS_LB );
		$this->assertSame( 'lb_s', Stats_Store::NS_LB_S );
		$this->assertSame( 'urls', Stats_Store::NS_URLS );
		$this->assertSame( 'url', Stats_Store::NS_URL );
		$this->assertSame( 'dim', Stats_Store::NS_DIM );
		$this->assertSame( 'url_dim', Stats_Store::NS_URL_DIM );
		$this->assertSame( 'categories', Stats_Store::NS_CATEGORIES );
		$this->assertSame( 'url_cat', Stats_Store::NS_URL_CAT );
	}

	public function test_caps_constants(): void {
		$this->assertSame( 50, Stats_Store::MAX_CAT_VALUES );
		$this->assertSame( 20, Stats_Store::MAX_DIM_VALUES );
		$this->assertSame( 10, Stats_Store::MAX_URL_DIM_VALUES );
	}

	public function test_ttl_url_stats_floors_at_one_hour(): void {
		$store_short = $this->make_store( max_lifespan: 60 );
		$this->assertSame( 3600, $store_short->ttl_url_stats() );
	}

	public function test_ttl_url_stats_uses_max_lifespan_div_24(): void {
		$store = $this->make_store( max_lifespan: 86400 );
		$this->assertSame( 3600, $store->ttl_url_stats() );

		$store_long = $this->make_store( max_lifespan: 7 * 86400 );
		$this->assertSame( 25200, $store_long->ttl_url_stats() );
	}

	public function test_get_url_stats_round_trip(): void {
		$store = $this->make_store();
		$this->assertNull( $store->get_url_stats( 'urlhash-x' ) );
		$this->set_url_stats( $store, 'urlhash-x', [ 'flame' => [ 1, 2, 3 ] ] );
		$this->assertSame(
			[ 'flame' => [ 1, 2, 3 ] ],
			$store->get_url_stats( 'urlhash-x' )
		);
	}

	public function test_get_url_stats_hands_out_the_profile_as_per_request_means(): void {
		$store = $this->make_store();
		$this->set_url_stats( $store, 'urlhash-m', [
			'count'    => 4,
			'profiles' => [
				'count'        => 4,
				'sum_req_time' => 200.0,
				'categories'   => [ 'wpdb' => [ 'samples' => 4, 'sum_time' => 80.0, 'sum_count' => 12, 'entries' => [] ] ],
			],
		] );
		$stats = $store->get_url_stats( 'urlhash-m' );
		$this->assertSame( 4, $stats['count'] );
		$this->assertSame( 4, $stats['profiles']['count'] );
		$this->assertEqualsWithDelta( 50.0, $stats['profiles']['total_time'], 1e-6 );
		$this->assertEqualsWithDelta( 20.0, $stats['profiles']['categories']['wpdb']['time'], 1e-6 );
		$this->assertEqualsWithDelta( 3.0, $stats['profiles']['categories']['wpdb']['count'], 1e-6 );
	}

	public function test_keys_include_partition(): void {
		$mc       = $this->seed_memd();
		$store_p0 = $this->make_store( partition: 0 );
		$store_p1 = $this->make_store( partition: 1 );
		$this->set_url_bucket( $store_p0, '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
		$this->set_url_bucket( $store_p1, '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
		$keys   = $mc->keys();
		$has_p0 = false;
		$has_p1 = false;
		foreach ( $keys as $k ) {
			if ( \str_contains( $k, ':p0:' ) ) {
				$has_p0 = true;
			}
			if ( \str_contains( $k, ':p1:' ) ) {
				$has_p1 = true;
			}
		}
		$this->assertTrue( $has_p0 && $has_p1, "Expected both partition prefixes in keys: " . \implode( ',', $keys ) );
	}

	public function test_keys_include_namespace(): void {
		$mc    = $this->seed_memd();
		$store = $this->make_store();
		$this->set_url_bucket( $store, '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
		$keys          = $mc->keys();
		$found_urls_ns = false;
		foreach ( $keys as $k ) {
			if ( \str_contains( $k, ':urls:' ) ) {
				$found_urls_ns = true;
				break;
			}
		}
		$this->assertTrue( $found_urls_ns, "Expected ':urls:' namespace in key: " . \implode( ',', $keys ) );
	}
	public function test_fail_soft_get_returns_empty_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertSame( [], $this->url_bucket_rows( $store, 'any' ) );
		$this->assertNull( $store->get_url_stats( 'any' ) );
		$this->assertSame( [], $this->get_hourly_bucket( $store, 'any' ) );
		$this->assertSame( [], $this->get_dimensional_bucket( $store, 'status', 'b1' ) );
	}

	public function test_fail_soft_set_returns_false_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertFalse( $this->set_url_bucket( $store, '2026-01-01-00-00', [] ) );
		$this->assertFalse( $this->set_leaderboard_bucket( $store, '2026-01-01-00-00', [] ) );
		$this->assertFalse( $this->set_dimensional_bucket( $store, 'status', 'b1', [] ) );
		$this->assertNull( Core::$memd );
	}

	public function test_get_multi_url_buckets_batches_lookups(): void {
		$store = $this->make_store();
		$bucket = '2026-01-01-00-00';
		$this->set_url_bucket( $store, $bucket, [
			'/x' => [ 'url' => '/x' ],
			'/y' => [ 'url' => '/y' ],
		] );

		// get_url_buckets should accept a list and return a map.
		$results = $this->url_rows_by_bucket( $store, [ $bucket, 'nonexistent-bucket' ] );
		$this->assertArrayHasKey( $bucket, $results );
		$this->assertArrayHasKey( '/x', $results[ $bucket ] );
		$this->assertArrayHasKey( '/y', $results[ $bucket ] );
		// Missing bucket should not appear.
		$this->assertArrayNotHasKey( 'nonexistent-bucket', $results );
	}

	public function test_get_url_buckets_returns_empty_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertSame( [], $this->url_rows_by_bucket( $store, [ 'a', 'b' ] ) );
	}

	// --- New explicit-bucket setter API (FlameBuilder uses these) ---------

	public function test_set_and_get_hourly_round_trip(): void {
		$store = $this->make_store();
		$this->set_hourly_bucket( $store, '2026-01-01-00', [ 'count' => 5, 'sum_ms' => 100, 'sum_peak_mb' => 10 ] );
		$h = $store->get_hourly_buckets( [ '2026-01-01-00' ] );
		$this->assertSame( 5, $h['2026-01-01-00']['count'] );
	}

	public function test_set_and_get_leaderboard_bucket_round_trip(): void {
		$store = $this->make_store();
		$this->set_leaderboard_bucket( $store, '2026-01-01-00-05', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$lb = $this->get_leaderboard_bucket( $store, '2026-01-01-00-05' );
		$this->assertSame( 3, $lb['count'] );
		$this->assertEqualsWithDelta( 1.5, $lb['sum_req_time'], 1e-9 );
	}

	public function test_set_and_get_server_leaderboard_bucket_round_trip(): void {
		$store = $this->make_store();
		$this->set_leaderboard_bucket( $store, '2026-01-01-00-05', [ 'count' => 7, 'sum_req_time' => 3.5, 'categories' => [] ], 'srv-x' );
		$lb = $this->get_leaderboard_bucket( $store, '2026-01-01-00-05', 'srv-x' );
		$this->assertSame( 7, $lb['count'] );
	}

	public function test_set_and_get_url_bucket_round_trip(): void {
		$store = $this->make_store();
		$this->set_url_bucket( $store, '2026-01-01-00-00', [ 'hash1' => [ 'url' => '/x', 'count' => 1 ] ] );
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

	// --- Mirror seam + restore() ------------------------------------------

	public function test_entry_key_is_the_durable_key_and_carries_no_install_scope(): void {
		// The mirror files every frame under this key, and the mirror is what
		// outlives `wp nodes memcache flush`. A key carrying the install scope
		// is orphaned by the very rotation it exists to survive. It carries the
		// partition and the entry and nothing else — no scope, and no version
		// component, which is a migration by another name.
		// Partition 3 and an odd bucket, so no default satisfies it by accident.
		$key = Stats_Store::NS_HOURLY . ':2026-03-04-05';
		$this->assertSame( 'evlog:p3:' . $key, Stats_Store::entry_key( 3, $key ) );
		$this->assertTrue( Stats_Store::is_mirror_key( Stats_Store::entry_key( 3, $key ) ) );
		$this->assertFalse( Stats_Store::is_mirror_key( 'newspack_nodes:v3:4f82f2fc5124:table:evlog:p3:' . $key ), 'a scoped cache key is not a mirror key' );
		$this->assertFalse( Stats_Store::is_mirror_key( $key ), 'a key relative to its namespace is not a mirror key' );
		// The durable key carries the partition and nothing before it. A key
		// with anything else in that position is not one this mirror writes,
		// which is what keeps a carry from another key shape out of the buffer
		// rather than held and re-filed under a key no lookup reaches.
		$this->assertFalse( Stats_Store::is_mirror_key( 'evlog:x9:p3:' . $key ), 'another segment in the partition slot is not a mirror key' );
	}

	public function test_set_hourly_invokes_mirror_with_key_data_ttl_ns(): void {
		$store    = $this->make_store();
		$captured = [];
		$store->mirror = static function ( string $key, array $data, int $ttl, string $ns ) use ( &$captured ): void {
			$captured[] = [ $key, $data, $ttl, $ns ];
		};

		$data = [ 'count' => 5 ];
		$this->set_hourly_bucket( $store, '2026-01-01-00', $data );

		$this->assertCount( 1, $captured );
		$this->assertSame( Stats_Store::entry_key( 0, Stats_Store::NS_HOURLY . ':2026-01-01-00' ), $captured[0][0] );
		$this->assertSame( $data, $captured[0][1] );
		$this->assertSame( 86400, $captured[0][2] );
		$this->assertSame( Stats_Store::NS_HOURLY, $captured[0][3] );
	}

	public function test_set_url_stats_mirrors_with_url_key_url_ttl_and_ns(): void {
		$store    = $this->make_store();
		$captured = [];
		$store->mirror = static function ( string $key, array $data, int $ttl, string $ns ) use ( &$captured ): void {
			$captured[] = [ $key, $data, $ttl, $ns ];
		};

		$data = [ 'flame' => [ 1, 2, 3 ] ];
		$this->set_url_stats( $store, 'abc', $data );

		$this->assertCount( 1, $captured );
		$this->assertSame( Stats_Store::entry_key( 0, Stats_Store::NS_URL . ':abc' ), $captured[0][0] );
		$this->assertSame( $data, $captured[0][1] );
		$this->assertSame( $store->ttl_url_stats(), $captured[0][2] );
		$this->assertSame( Stats_Store::NS_URL, $captured[0][3] );
	}

	public function test_every_setter_passes_its_namespace_to_mirror(): void {
		$store    = $this->make_store();
		$captured = [];
		$store->mirror = static function ( string $key, array $data, int $ttl, string $ns ) use ( &$captured ): void {
			$captured[] = $ns;
		};

		$this->set_hourly_bucket( $store, 'x', [ 'count' => 1 ] );
		$this->set_url_shard( $store, 'b', '0', [ 'x' => [ 'url' => '/x' ] ] );
		$this->set_url_stats( $store, 'h', [ 'flame' => [ 'count' => 1 ] ] );
		$this->set_leaderboard_bucket( $store, 'b', [ 'count' => 1 ] );
		$this->set_leaderboard_bucket( $store, 'b', [ 'count' => 1 ], 'srv' );
		$this->set_dimensional_bucket( $store, 'status', 'b', [ '200' => self::dim_entry( 1 ) ] );
		$this->set_url_dimensional_bucket( $store, 'h', 'b', [ 'status' => [ '200' => self::dim_entry( 1 ) ] ] );
		$this->set_category_bucket( $store, 'b', [ 'total' => [ 'n' => 1 ] ] );
		$this->set_category_bucket( $store, 'b', [ 'total' => [ 'n' => 1 ] ], 'srv' );
		$this->set_url_category_bucket( $store, 'h', 'b', [ 'total' => [ 'n' => 1 ] ] );

		$this->assertSame(
			[
				Stats_Store::NS_HOURLY,
				Stats_Store::NS_URLS,
				Stats_Store::NS_URLSRV,
				Stats_Store::NS_URL,
				Stats_Store::NS_LB,
				Stats_Store::NS_LB_S,
				Stats_Store::NS_DIM,
				Stats_Store::NS_URL_DIM,
				Stats_Store::NS_CATEGORIES,
				Stats_Store::NS_CATEGORIES,
				Stats_Store::NS_URL_CAT,
			],
			$captured
		);
	}

	public function test_set_hourly_returns_true_with_null_mirror(): void {
		$store = $this->make_store();
		$this->assertNull( $store->mirror );
		$this->assertTrue( $this->set_hourly_bucket( $store, 'x', [ 'count' => 1 ] ) );
	}

	public function test_store_skips_mirror_when_memcache_set_fails(): void {
		Core::$memd = new class() extends InMemoryMemcached {
			public function set( string $key, mixed $value, int $expiration = 0 ): bool {
				return false;
			}
		};
		$store   = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$invoked = false;
		$store->mirror = static function ( string $key, array $data, int $ttl, string $ns ) use ( &$invoked ): void {
			$invoked = true;
		};
		$this->assertFalse( $this->set_hourly_bucket( $store, 'x', [ 'count' => 1 ] ) );
		$this->assertFalse( $invoked, 'mirror must not fire when the memcache set failed' );
	}

	/**
	 * Arm the rehydrate seam with one canned entry, in the Table backing shape.
	 *
	 * @param array<string,mixed> $value Entry payload.
	 */
	private function arm_entry( Stats_Store $store, string $key, array $value, int $ttl ): void {
		$store->rehydrate = static fn ( array $keys ): array => \in_array( $key, $keys, true )
			? [ $key => [ 'value' => $value, 'ttl' => $ttl ] ]
			: [];
	}

	public function test_leaderboard_buckets_read_in_one_round_trip(): void {
		$store = $this->make_store();
		$this->set_leaderboard_bucket( $store, 'b1', [ 'count' => 31, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$this->set_leaderboard_bucket( $store, 'b3', [ 'count' => 74, 'sum_req_time' => 2.5, 'categories' => [] ] );

		$rows = $store->get_leaderboard_buckets( [ 'b1', 'b2', 'b3' ] );

		$this->assertSame( [ 'b1', 'b3' ], \array_keys( $rows ), 'absent buckets omitted, present ones keyed by bucket' );
		$this->assertSame( 74, $rows['b3']['count'] );
	}

	public function test_leaderboard_buckets_scope_to_a_server_when_asked(): void {
		$store = $this->make_store();
		$this->set_leaderboard_bucket( $store, 'b1', [ 'count' => 31, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$this->set_leaderboard_bucket( $store, 'b1', [ 'count' => 88, 'sum_req_time' => 3.5, 'categories' => [] ], 'spoke-a' );

		$this->assertSame( 88, $store->get_leaderboard_buckets( [ 'b1' ], 'spoke-a' )['b1']['count'] );
		$this->assertSame( 31, $store->get_leaderboard_buckets( [ 'b1' ] )['b1']['count'] );
	}

	public function test_the_backing_survives_a_window_that_matches_the_per_url_one(): void {
		// At min_lifetime <= 3600 the aggregate and per-URL TTLs are BOTH 3600.
		// Memoizing a table by its TTL then hands the aggregate reads the
		// per-URL table, which is deliberately unbacked — and the mirror goes
		// silently unreadable on exactly the installs that shortened retention.
		$store = $this->make_store( max_lifespan: 3600 );
		$this->arm_entry( $store, Stats_Store::NS_HOURLY . ':2026-02-03-04-05', [ 'count' => 42 ], 900 );

		$this->assertSame( [ 'count' => 42 ], $this->get_hourly_bucket( $store, '2026-02-03-04-05' ) );
	}

	/**
	 * An absence the mirror answered is remembered, for as long as it holds.
	 *
	 * A bucket the mirror has no frame for costs the walk its whole index to
	 * say so, and a sparse server has such buckets in every window; asked on
	 * every poll, they spend the read budget before the series is reached. A
	 * bucket that closed gains no frame, so its absence holds for the window;
	 * the open bucket's frame may still land, so its absence holds only
	 * briefly. A key that is no bucket, `urlmap`, holds briefly too.
	 */
	public function test_absences_are_not_remembered_unless_a_reader_asks_for_it(): void {
		// The writer's own folds read a bucket once; a marker there would
		// only compete with the write that follows.
		$store = $this->make_store();
		$asked = 0;
		$store->rehydrate = static function ( array $keys ) use ( &$asked ): array {
			++$asked;
			return [];
		};
		$this->assertSame( [], $store->get_leaderboard_buckets( [ '2026-01-01-00-00' ] ) );
		$this->assertSame( [], $store->get_leaderboard_buckets( [ '2026-01-01-00-00' ] ) );
		$this->assertSame( 2, $asked );
	}

	public function test_a_writer_reads_a_readers_marker_as_an_ordinary_miss(): void {
		// The worker's store shares the key and holds no absences; a marker a
		// dashboard left must not be its miss, or an evicted open bucket
		// merges from nothing instead of from the held-frame tier.
		$reader = $this->make_store();
		$reader->rehydrate = static fn ( array $keys ): array => [];
		$reader->absence   = static fn ( string $key ): int => 20;
		$open = Stats_Store::bucket_key( self::tick() );
		$this->assertSame( [], $reader->get_leaderboard_buckets( [ $open ] ) );

		$writer = $this->make_store();
		$writer->rehydrate = static fn ( array $keys ): array => [ 'lb:' . $open => [ 'value' => [ 'count' => 47, 'sum_req_time' => 2.0, 'categories' => [] ], 'ttl' => 60 ] ];
		$this->assertSame( 47, $writer->get_leaderboard_buckets( [ $open ] )[ $open ]['count'] ?? null, 'the held frame answers through the marker' );
	}

	public function test_an_absent_closed_bucket_is_not_asked_of_the_mirror_again(): void {
		$store = $this->make_store( max_lifespan: 7200 );
		$asked = [];
		$store->rehydrate = static function ( array $keys ) use ( &$asked ): array {
			$asked[] = $keys;
			return [];
		};
		$store->absence = static fn ( string $key ): int => $store->absence_holds( $key );
		$now    = self::tick();
		$closed = Stats_Store::bucket_key( $now - 3600 );
		$open   = Stats_Store::bucket_key( $now );
		// The cache double dates an expiry from the wall.
		$wall   = \time();

		$this->assertSame( [], $store->get_leaderboard_buckets( [ $closed, $open ] ) );
		$this->assertSame( [], $store->get_leaderboard_buckets( [ $closed, $open ] ) );
		// Both were asked once; the closed one holds for the window, the open
		// one only briefly, since its frame may still land.
		$this->assertSame( [ [ 'lb:' . $closed, 'lb:' . $open ] ], $asked );
		$expiries = Core::$memd->expiries();
		$brief    = $expiries[ self::cache_key( 0, 'lb:' . $open ) ] ?? 0;
		$this->assertEqualsWithDelta( $wall + Stats_Store::ABSENCE_HOLD_SECONDS, $brief, 2, 'the open bucket\'s absence holds briefly' );
		$held     = $expiries[ self::cache_key( 0, 'lb:' . $closed ) ] ?? 0;
		// The window is 7200s from the bucket's start, an hour ago.
		$this->assertEqualsWithDelta( $wall - ( $now % Stats_Store::BUCKET_SECONDS ) - 3600 + 7200, $held, 2 + Stats_Store::BUCKET_SECONDS, 'held for what is left of the window, not the table lifetime' );
	}

	public function test_the_open_bucket_is_the_one_the_tick_names(): void {
		// `absence_holds()` reads the tick; two buckets ahead of the wall, a
		// bucket dated from the wall is already closed to it.
		$this->shift_tick( 2 * Stats_Store::BUCKET_SECONDS );
		$this->test_an_absent_closed_bucket_is_not_asked_of_the_mirror_again();
	}

	public function test_an_absence_read_does_not_move_the_tick(): void {
		// `Core::right_now()` WRITES `Core::$now`, and a fold asks this once
		// per absent key: a fresh read would re-pin the tick the reply is
		// dated from, and at a bucket boundary rebuild its read window.
		$store     = $this->make_store();
		$pinned    = 1_600_000_000.5;
		$previous  = Core::$now;
		Core::$now = $pinned;
		try {
			$store->absence_holds( Stats_Store::NS_HOURLY . ':2026-01-01-00' );
			$this->assertSame( $pinned, Core::$now, 'the reader takes the tick and never re-pins it' );
		} finally {
			Core::$now = $previous;
		}
	}

	public function test_a_miss_is_filled_from_the_durable_backing(): void {
		$store = $this->make_store();
		$value = [ 'count' => 9 ];
		$this->arm_entry( $store, Stats_Store::NS_HOURLY . ':2026-01-01-00', $value, 100 );

		$this->assertSame( $value, $this->get_hourly_bucket( $store, '2026-01-01-00' ) );
	}

	/**
	 * A frame whose CACHE lifetime is spent is still filled from the mirror.
	 *
	 * The TTL a frame was written with bounds memcache — the fine tier is the
	 * largest thing this schema puts in a 512MB one — and says nothing about
	 * how long the data is available. The mirror retains for twice the stats
	 * window, and refusing a spent frame made it useless for exactly the tier
	 * whose cache lifetime is shortest: an evicted `urls_h` could never be
	 * rebuilt from the fine buckets decision 17 says it derives from.
	 */
	public function test_an_entry_whose_cache_lifetime_is_spent_is_still_filled(): void {
		$store = $this->make_store();
		$this->arm_entry( $store, Stats_Store::NS_HOURLY . ':x', [ 'count' => 1 ], 0 );

		$this->assertSame( [ 'count' => 1 ], $this->get_hourly_bucket( $store, 'x' ) );
	}

	public function test_the_backing_answers_in_the_stores_own_keyspace(): void {
		// The Table applies the namespace, so a backing cannot reach another
		// scope — the guard the old full-key seam needed is gone by construction.
		$store = $this->make_store();
		$this->arm_entry( $store, 'other:p0:hourly:x', [ 'count' => 1 ], 100 );

		$this->assertSame( [], $this->get_hourly_bucket( $store, 'x' ), 'a key this store never asked for fills nothing' );
	}

	public function test_add_totals_sums_the_request_triple(): void {
		// The triple is summed in four places in three dialects; the schema owns
		// the arithmetic, like sums_to_display() owns the read-time division.
		$this->assertSame(
			[ 'count' => 11, 'sum_ms' => 91.5, 'sum_peak_mb' => 7.25 ],
			Stats_Store::add_totals(
				[ 'count' => 4, 'sum_ms' => 31.5, 'sum_peak_mb' => 2.25 ],
				[ 'count' => 7, 'sum_ms' => 60.0, 'sum_peak_mb' => 5.0 ]
			)
		);
	}

	public function test_add_totals_keeps_fields_outside_the_triple(): void {
		// The stored bucket is not owned by this function; rebuilding it from
		// three keys would drop a fourth silently, at every flush, forever.
		$this->assertSame(
			[ 'peak_url' => '/slow', 'count' => 9, 'sum_ms' => 12.0, 'sum_peak_mb' => 0.0 ],
			Stats_Store::add_totals( [ 'peak_url' => '/slow', 'count' => 4 ], [ 'count' => 5, 'sum_ms' => 12.0 ] )
		);
	}

	public function test_add_totals_treats_a_missing_or_junk_side_as_zero(): void {
		$this->assertSame(
			[ 'count' => 3, 'sum_ms' => 8.5, 'sum_peak_mb' => 0.0 ],
			Stats_Store::add_totals( [], [ 'count' => 3, 'sum_ms' => '8.5', 'sum_peak_mb' => 'nope' ] )
		);
	}

	public function test_leaderboard_bucket_round_trips_per_server(): void {
		// One getter/setter pair with a server scope, matching the plural
		// get_leaderboard_buckets() sibling that already takes one.
		$store = $this->make_store();
		$this->set_leaderboard_bucket( $store, '2026-01-01-00-05', [ 'count' => 61 ], 'web07' );

		$this->assertSame( 61, ( $store->get_leaderboard_buckets( [ '2026-01-01-00-05' ], 'web07'  )[ '2026-01-01-00-05' ] ?? [] )['count'] );
		$this->assertSame( [], $this->get_leaderboard_bucket( $store, '2026-01-01-00-05' ), 'the global scope is a different key' );
	}

	public function test_dimensional_bucket_round_trips_global_and_per_server(): void {
		// Every bucketed namespace is keyed with the bucket LAST, so one
		// lookup_buckets() batch serves them all.
		$store = $this->make_store();
		$this->set_dimensional_bucket( $store, 'status', '2026-02-03-04-05', [ '503' => self::dim_entry( 47, 12.5, 3.0 ) ] );
		$this->set_dimensional_bucket( $store, 'status', '2026-02-03-04-05', [ '503' => self::dim_entry( 91, 1.0, 1.0 ) ], 'web07' );

		$this->assertSame( 47, $this->get_dimensional_bucket( $store, 'status', '2026-02-03-04-05' )['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 91, $this->get_dimensional_bucket( $store, 'status', '2026-02-03-04-05', 'web07' )['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame(
			47,
			$store->get_dimensional_buckets( 'status', [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['503'][ Stats_Store::DIM_COUNT ],
			'the batch read returns the same value keyed by bucket'
		);
	}

	public function test_category_bucket_round_trips_global_and_per_server(): void {
		$store = $this->make_store();
		$this->set_category_bucket( $store, '2026-02-03-04-05', [ 'wpdb' => self::cat_entry( 8.5, 23, 4 ) ] );
		$this->set_category_bucket( $store, '2026-02-03-04-05', [ 'wpdb' => self::cat_entry( 1.5, 66, 2 ) ], 'web07' );

		$this->assertSame( 23, $this->get_category_bucket( $store, '2026-02-03-04-05' )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame( 66, $this->get_category_bucket( $store, '2026-02-03-04-05', 'web07' )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame(
			23,
			$store->get_category_buckets( [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['wpdb'][ Stats_Store::CAT_CALLS ]
		);
	}

	public function test_url_dimensional_bucket_holds_every_dimension_for_one_bucket(): void {
		// Bucket last, dims inside the value: 288 keys per URL rather than a
		// dimension-by-bucket cross-product.
		$store = $this->make_store();
		$this->set_url_dimensional_bucket( $store, 'ab12cd34ef56', '2026-02-03-04-05', [
			'status' => [ '503' => self::dim_entry( 29, 1.0, 1.0 ) ],
			'method' => [ 'POST' => self::dim_entry( 31, 2.0, 2.0 ) ],
		] );

		$got = $this->get_url_dimensional_bucket( $store, 'ab12cd34ef56', '2026-02-03-04-05' );
		$this->assertSame( 29, $got['status']['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 31, $got['method']['POST'][ Stats_Store::DIM_COUNT ] );
	}

	/** The store chose the bucket-major blob, so the store cuts one dimension out of it. */
	public function test_url_dimension_buckets_project_one_dimension_out_of_the_blob(): void {
		$store = $this->make_store();
		$this->set_url_dimensional_bucket( $store, 'ab12cd34ef56', '2026-02-03-04-05', [
			'status' => [ '503' => self::dim_entry( 29, 1.0, 1.0 ) ],
			'method' => [ 'POST' => self::dim_entry( 31, 2.0, 2.0 ) ],
		] );
		$this->set_url_dimensional_bucket( $store, 'ab12cd34ef56', '2026-02-03-04-10', [
			'method' => [ 'GET' => self::dim_entry( 37, 3.0, 3.0 ) ],
		] );

		$got = $store->get_url_dimension_buckets( 'ab12cd34ef56', 'status', [ '2026-02-03-04-05', '2026-02-03-04-10', '2026-02-03-04-15' ] );

		$this->assertSame( [ '2026-02-03-04-05' ], \array_keys( $got ), 'a bucket without the dimension, or absent, is no row' );
		$this->assertSame( [ 503 ], \array_keys( $got['2026-02-03-04-05'] ) );
		$this->assertSame( 29, $got['2026-02-03-04-05']['503'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_url_category_bucket_round_trips(): void {
		$store = $this->make_store();
		$this->set_url_category_bucket( $store, 'ab12cd34ef56', '2026-02-03-04-05', [ 'wpdb' => self::cat_entry( 3.5, 57, 2 ) ] );

		$this->assertSame( 57, $this->get_url_category_bucket( $store, 'ab12cd34ef56', '2026-02-03-04-05' )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame(
			57,
			$store->get_url_category_buckets( 'ab12cd34ef56', [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['wpdb'][ Stats_Store::CAT_CALLS ]
		);
	}

	public function test_a_scope_is_a_different_keyspace_not_a_shared_one(): void {
		// '' vs a named server, and one URL vs another, must not collide.
		$store = $this->make_store();
		$this->set_category_bucket( $store, 'b1', [ 'wpdb' => self::cat_entry( 0, 13, 1 ) ] );
		$this->set_url_category_bucket( $store, 'aaaaaaaaaaaa', 'b1', [ 'wpdb' => self::cat_entry( 0, 77, 1 ) ] );

		$this->assertSame( [], $store->get_category_buckets( [ 'b1' ], 'web07' )[ 'b1' ] ?? [] );
		$this->assertSame( [], $store->get_url_category_buckets( 'bbbbbbbbbbbb', [ 'b1' ] )[ 'b1' ] ?? [] );
		$this->assertSame( 13, ( $this->get_category_bucket( $store, 'b1' ) )['wpdb'][ Stats_Store::CAT_CALLS ] );
		$this->assertSame( 77, ( $store->get_url_category_buckets( 'aaaaaaaaaaaa', [ 'b1' ]  )[ 'b1' ] ?? [] )['wpdb'][ Stats_Store::CAT_CALLS ] );
	}

	public function test_every_bucketed_write_lands_under_the_retention_ttl(): void {
		// Retention IS the key's own TTL since the hand-rolled cutoff passes went
		// away — a write at the wrong TTL never expires, or expires 24x early,
		// and no other assertion in the suite would notice.
		$mc    = $this->seed_memd();
		$store = $this->make_store( max_lifespan: 7200 ); // 2h: distinct from every default

		$this->set_hourly_bucket( $store, 'b1', [ 'count' => 3 ] );
		$this->set_leaderboard_bucket( $store, 'b1', [ 'count' => 3 ] );
		$this->set_leaderboard_bucket( $store, 'b1', [ 'count' => 3 ], 'web07' );
		$this->set_url_shard( $store, 'b1', '0', [ 'h' => [ 'count' => 3 ] ] );
		$this->set_dimensional_bucket( $store, 'status', 'b1', [ '503' => self::dim_entry( 3 ) ] );
		$this->set_dimensional_bucket( $store, 'status', 'b1', [ '503' => self::dim_entry( 3 ) ], 'web07' );
		$this->set_category_bucket( $store, 'b1', [ 'db' => [ 'n' => 3 ] ] );
		$this->set_category_bucket( $store, 'b1', [ 'db' => [ 'n' => 3 ] ], 'web07' );
		$this->set_url_dimensional_bucket( $store, 'h', 'b1', [ 'status' => [ '503' => self::dim_entry( 3 ) ] ] );
		$this->set_url_category_bucket( $store, 'h', 'b1', [ 'db' => [ 'n' => 3 ] ] );

		$expiries = $mc->expiries();
		$this->assertCount( 11, $expiries, 'each scope is its own key, the server index among them' );
		$this->assertCount( 1, \array_unique( \array_values( $expiries ) ), 'all at one expiry' );
		$this->assertEqualsWithDelta( 7200, \reset( $expiries ) - \time(), 2, 'the retention TTL' );
	}

	public function test_bucket_key_floors_to_the_bucket_width(): void {
		// 12:47 UTC belongs to the 12:45 bucket.
		$this->assertSame( '2026-02-03-12-45', Stats_Store::bucket_key( \gmmktime( 12, 47, 33, 2, 3, 2026 ) ) );
	}

	public function test_the_open_bucket_is_recognised_by_its_key_suffix(): void {
		// ADR-1 puts the bucket LAST in every bucketed key, which is what lets a
		// caller decide openness from the key alone.
		$now  = \gmmktime( 12, 47, 33, 2, 3, 2026 );
		$open = Stats_Store::bucket_key( $now );

		$this->assertTrue( Stats_Store::is_open_bucket( "evlog:p0:url_dim:9f21ab04cd77:{$open}", $now ) );
		$this->assertFalse( Stats_Store::is_open_bucket( 'evlog:p0:url_dim:9f21ab04cd77:2026-02-03-12-40', $now ) );
		$this->assertFalse( Stats_Store::is_open_bucket( 'evlog:p0:url:9f21ab04cd77', $now ), 'url is unbucketed' );
		// A producer whose clock runs slightly ahead writes a bucket we have not
		// reached; a broken one writes a bucket we must not hold forever.
		$this->assertTrue( Stats_Store::is_open_bucket( 'evlog:p0:hourly:2026-02-03-12-50', $now ), 'a near-future bucket is open' );
		$this->assertFalse( Stats_Store::is_open_bucket( 'evlog:p0:hourly:2100-01-01-00-00', $now ), 'a broken clock is not held' );
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
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( 0, 86400 );
		$store->set_url_names( [
			'kea.example'          => [
				'a1a1a1a1a1a1' => 'https://kea.example/kakapo-7731?x=7',
				'b2b2b2b2b2b2' => 'http://kea.example/plain-3319',
			],
			Stats_Store::OTHER_KEY => [ 'c3c3c3c3c3c3' => 'https://moa.example/weka-5521' ],
			'Unknown'              => [ 'd4d4d4d4d4d4' => '/tui-8812' ],
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
				'd4d4d4d4d4d4' => [ 'server' => 'Unknown', 'url' => '/tui-8812' ],
			],
			$store->get_url_names( [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2', 'c3c3c3c3c3c3', 'd4d4d4d4d4d4' ] )
		);
	}

	public function test_a_urls_path_drops_its_scheme_and_host_alone(): void {
		// An authority with no path is the case that bites: everything after
		// the host is the path, or the host swallows a query.
		$this->assertSame(
			[ '/reports', '/?cache-cozy', '?q=1', '', '/a-bare-path', '' ],
			\array_map(
				[ Stats_Store::class, 'path_of' ],
				[ 'https://alpha.test/reports', 'https://alpha.test/?cache-cozy', 'https://alpha.test?q=1', 'https://alpha.test', '/a-bare-path', '' ]
			)
		);
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
		Core::$memd = new class() extends InMemoryMemcached {
			/** @var array<int,string> */
			public array $asked = [];

			public function getMulti( array $keys, int $get_flags = 0 ): array|false {
				foreach ( $keys as $key ) {
					$this->asked[] = (string) $key;
				}
				return parent::getMulti( $keys, $get_flags );
			}
		};
		$store  = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$bucket = Stats_Store::bucket_key( 1_700_000_000 );

		$store->url_row_sources( [ $bucket ], null, false, 'kea.test' );

		/** @var array<int,string> $asked */
		$asked = Core::$memd->asked;
		$this->assertNotContains( self::cache_key( 0, 'urls:' . $bucket ), $asked, 'the unsharded key is never asked for' );
		$this->assertNotContains( self::cache_key( 0, 'urls:a:' . $bucket ), $asked, 'nor a shard no server owns' );
		$this->assertContains(
			self::cache_key( 0, 'urls:' . Stats_Store::server_key( 'kea.test' ) . ':a:' . $bucket ),
			$asked,
			'every shard of the server is'
		);
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
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$rows       = [ 'hash-4471' => [ Stats_Store::ROW_COUNT => 6, Stats_Store::ROW_MAX_MS => 44.71 ] ];

		$this->assertTrue( $this->set_url_hour( $store, '2026-08-27-13', 'a', $rows ) );
		$this->assertSame( $rows, $this->get_url_hour( $store, '2026-08-27-13', 'a' ) );
		$this->assertSame( [], $this->get_url_hour( $store, '2026-08-27-14', 'a' ), 'an unfolded hour reads empty' );
	}
	// ── batched writes: the flush's cost is its KEY COUNT ───────────────────

	public function test_bucket_writes_batch_and_read_back_under_their_own_keys(): void {
		// The write half of lookup_bucket_sets(). Seeds distinct from every
		// default: three dimensions, counts 61/62/63, one bucket.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$writes     = [];
		foreach ( [ 'status' => 61, 'server' => 62, 'plugin' => 63 ] as $dim => $n ) {
			$writes[] = [ Stats_Store::dim_parts( $dim, '' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( $n ) ] ];
		}

		$this->assertSame( [ true, true, true ], $store->bucket_set_multi( $writes ) );

		foreach ( [ 'status' => 61, 'server' => 62, 'plugin' => 63 ] as $dim => $n ) {
			$this->assertSame(
				$n,
				$this->get_dimensional_bucket( $store, $dim, '2026-08-27-13-05' )['v'][ Stats_Store::DIM_COUNT ],
				"{$dim} must read back what the batch wrote"
			);
		}
	}

	public function test_a_batched_read_tells_a_stored_empty_value_from_a_miss(): void {
		// Positional: the caller merges result[i] onto writes[i], so a miss
		// has to hold its slot. It also has to be TELLABLE from a value that
		// is there and empty — an hour folded with no rows is written empty,
		// and a probe reading that as absence folds it again forever.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
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

	/**
	 * The fine tier answers the LAST HOUR and feeds the fold. Nothing else.
	 *
	 * `RECENT_BUCKETS` twelve buckets are one hour, and `roll_up_hours()` builds
	 * the coarse tiers out of a closed hour's fine buckets. It was never a tier
	 * to read old hours from, so the fallback for an unfolded hour reaches at
	 * most the hour immediately behind the fine tail — the one the fold has not
	 * caught yet — and no further.
	 */
	public function test_the_hour_behind_the_fine_tail_still_answers(): void {
		$store = new Stats_Store( 0, 86400 );
		$hour  = \gmdate( 'Y-m-d-H', \time() - 3600 );

		$this->assertSame(
			Stats_Store::buckets_in_hour( $hour ),
			Stats_Store::unfolded_hour_buckets( $hour, [ $hour, \gmdate( 'Y-m-d-H', \time() - 7200 ) ] )
		);
	}

	public function test_the_fine_fallback_answers_the_grace_hour_and_reports_a_hole(): void {
		// The rule every tier reader shares: an hour with no coarse key has
		// not been folded YET, and only the grace hour — the one the fold may
		// simply not have caught — is answered from its fine buckets. The two
		// facts are independent, so one call reports both and each caller
		// reads the half it acts on.
		$hours = [ '2026-09-22-14', '2026-09-22-13', '2026-09-22-12' ];

		$this->assertSame(
			[
				'buckets' => [],
				'holes'   => [],
			],
			Stats_Store::fine_fallback( $hours, \array_fill_keys( $hours, true ) ),
			'every hour folded, so nothing falls back'
		);
		$this->assertSame(
			[
				'buckets' => Stats_Store::buckets_in_hour( '2026-09-22-14' ),
				'holes'   => [],
			],
			Stats_Store::fine_fallback( $hours, [ '2026-09-22-13' => true, '2026-09-22-12' => true ] )
		);
		$this->assertSame(
			[
				'buckets' => [],
				'holes'   => [ '2026-09-22-13' ],
			],
			Stats_Store::fine_fallback( $hours, [ '2026-09-22-14' => true, '2026-09-22-12' => true ] ),
			'an hour behind the grace hour that no coarse key answers is a HOLE'
		);
		$this->assertSame(
			[
				'buckets' => Stats_Store::buckets_in_hour( '2026-09-22-14' ),
				'holes'   => [ '2026-09-22-13' ],
			],
			Stats_Store::fine_fallback( $hours, [ '2026-09-22-12' => true ] ),
			'a hole behind it does not cost the grace hour its buckets'
		);
	}

	/** An hour further back than that is the coarse tier's job, whatever its age. */
	public function test_an_hour_behind_the_grace_hour_is_never_read_finely(): void {
		$store  = new Stats_Store( 0, 86400 );
		$grace  = \gmdate( 'Y-m-d-H', \time() - 3600 );
		$behind = \gmdate( 'Y-m-d-H', \time() - 7200 );

		$this->assertSame( [], Stats_Store::unfolded_hour_buckets( $behind, [ $grace, $behind ] ) );
	}

	/** Two hours is the fold's margin, and the tier is sized to it. */
	/**
	 * What a re-materialized entry is warmed for: time left in the WINDOW.
	 *
	 * The TTL a frame was written with bounds the cache and decays from the
	 * write, so a spent one says nothing about how long the data is still read.
	 * The window does, and it is a pure function of the bucket key.
	 */
	public function test_window_remaining_decays_with_the_bucket_age(): void {
		$store = new Stats_Store( 0, 86400 );
		$now   = \time();

		$two_hours = 'hourly:' . Stats_Store::bucket_key( $now - 7200 );
		$this->assertEqualsWithDelta( 79200, $store->window_remaining( $two_hours, $now ), 310, 'a two-hour-old bucket has 22h left' );

		$old_hour = 'urls_h:3:' . \gmdate( 'Y-m-d-H', $now - ( 23 * 3600 ) );
		$this->assertGreaterThan( 0, $store->window_remaining( $old_hour, $now ), 'a 23-hour-old hour is still inside the window' );
		$this->assertLessThan( 7200, $store->window_remaining( $old_hour, $now ), 'with about an hour left, not a fresh one' );
	}

	/**
	 * A re-warmed entry is bounded by its ROLE's TTL, not by retention alone.
	 *
	 * `ttl_url_fine()` is a memcache FOOTPRINT bound: 24 buckets a shard rather
	 * than 288. Sizing a rehydrated fine bucket by the retention window alone
	 * warmed it for up to twelve times that and put the whole 288 back in the
	 * cache the two-hour tier exists to keep out.
	 */
	public function test_window_remaining_never_exceeds_the_role_ttl(): void {
		$store = new Stats_Store( 0, 86400 );
		$now   = \time();

		$fine = 'urls:0a1b2c3d:3:' . Stats_Store::bucket_key( $now - 600 );
		$this->assertSame( 7200, $store->window_remaining( $fine, $now ), 'a fine bucket is warmed for its own tier' );

		$index = 'urlsrv:' . Stats_Store::bucket_key( $now - 600 );
		$this->assertSame( 7200, $store->window_remaining( $index, $now ), 'the server index rides the same tier' );
	}

	/** Past the window there is nothing left, and nothing should warm it. */
	public function test_window_remaining_is_zero_past_retention(): void {
		$store = new Stats_Store( 0, 86400 );
		$now   = \time();
		$gone  = 'urls:3:' . Stats_Store::bucket_key( $now - ( 48 * 3600 ) );

		$this->assertSame( 0, $store->window_remaining( $gone, $now ) );
	}

	/** `url` and `urlmap` key on a hash, not a bucket, so both keep their role's TTL. */
	public function test_window_remaining_gives_a_hash_keyed_entry_the_whole_window(): void {
		$store = new Stats_Store( 0, 86400 );

		$this->assertSame( 86400, $store->window_remaining( 'urlmap:ab12cd34ef56', \time() ) );
		$this->assertSame( 3600, $store->window_remaining( 'url:ab12cd34ef56', \time() ), 'the per-URL flame role keeps its own' );
	}

	public function test_the_fine_tier_is_kept_for_two_hours(): void {
		$this->assertSame( 7200, ( new Stats_Store( 0, 86400 ) )->ttl_url_fine() );
	}

	public function test_an_empty_batch_is_a_no_op(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertSame( [], $store->bucket_set_multi( [] ) );
		$this->assertSame( [], $store->bucket_get_multi( [] ) );
	}
	public function test_a_refused_batch_falls_back_to_one_key_at_a_time(): void {
		// Neither backend reports success per KEY, so a caller that logs a
		// specific refusal — an oversized URL shard — only learns which one
		// because the batch is re-sent singly. One poisoned key, two good.
		Core::$memd = new class() extends InMemoryMemcached {
			public function set( string $key, mixed $value, int $expiration = 0 ): bool {
				return \str_contains( $key, 'refuse-me' ) ? false : parent::set( $key, $value, $expiration );
			}
		};
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$results = $store->bucket_set_multi( [
			[ Stats_Store::dim_parts( 'status', '' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 61 ) ] ],
			[ Stats_Store::url_dim_parts( 'refuse-me' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 62 ) ] ],
			[ Stats_Store::dim_parts( 'plugin', '' ), '2026-08-27-13-05', [ 'v' => self::dim_entry( 63 ) ] ],
		] );

		$this->assertSame( [ true, false, true ], $results, 'the refusal must be identified, not averaged' );
		$this->assertSame( 61, $this->get_dimensional_bucket( $store, 'status', '2026-08-27-13-05' )['v'][ Stats_Store::DIM_COUNT ], 'a good key still lands' );
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
		$writes = Stats_Store::ranked_writes( [ 'kea.test' => [ $hash => $row ] ], false, '2026-09-22-14-05' );
		$this->assertNotEmpty( $writes );
		foreach ( $writes as [ $parts, , $entries ] ) {
			$this->assertSame( [ $hash ], \array_column( $entries, Stats_Store::RANK_HASH ), \implode( ':', $parts ) );
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
		// Each server's lists come from its own rows and there is no site
		// list: the reader merges the servers'. An overflow row and a worker
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

		$lists  = self::ranked_lists( Stats_Store::ranked_writes( $rows, false, $bucket ) );
		$counts = static fn ( array $entries ): array => \array_combine(
			\array_column( $entries, Stats_Store::RANK_HASH ),
			\array_map(
				static fn ( array $entry ): int => \Newspack_Nodes\Core::num_int( $entry[ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ),
				$entries
			)
		);

		$this->assertSame(
			[ Stats_Store::server_key( 'kea.test' ), Stats_Store::server_key( 'moa.test' ), Stats_Store::server_key( 'weka.test' ) ],
			\array_keys( $lists ),
			'every server named, and no site scope'
		);
		$this->assertSame(
			[ 'a7a7a7a7a7a7' => 41, 'b8b8b8b8b8b8' => 13 ],
			$counts( $lists[ Stats_Store::server_key( 'kea.test' ) ]['count:desc'] )
		);
		$this->assertSame(
			[ 'c9c9c9c9c9c9' => 6 ],
			$counts( $lists[ Stats_Store::server_key( 'moa.test' ) ]['count:desc'] )
		);
		$this->assertSame( [], $lists[ Stats_Store::server_key( 'weka.test' ) ]['count:desc'], 'a worker-only server ranks nothing' );
		$this->assertSame( '/kea-13', $lists[ Stats_Store::server_key( 'kea.test' ) ]['url:asc'][0][ Stats_Store::RANK_PATH ] );
	}

	public function test_ranked_writes_carry_one_triple_per_list_of_each_server(): void {
		$rows   = [
			'a7a7a7a7a7a7' => self::positional_url_row( [ 'count' => 47, 'timed_count' => 2, 'sum_ms' => 88.0, 'sum_peak_mb' => 17.0, 'min_ms' => 41.0, 'max_ms' => 47.0, 'last_seen' => 1758500047, 'path' => '/kakapo-4417' ] ),
			'b8b8b8b8b8b8' => self::positional_url_row( [ 'count' => 13, 'timed_count' => 1, 'sum_ms' => 19.0, 'sum_peak_mb' => 3.0, 'min_ms' => 19.0, 'max_ms' => 19.0, 'last_seen' => 1758500013, 'path' => '/weka-1308' ] ),
		];
		$writes = Stats_Store::ranked_writes( [ 'takahe.test' => $rows ], true, '2026-09-22-14' );

		$expected = [];
		foreach ( Stats_Store::URL_SORTS as $sort ) {
			foreach ( Stats_Store::URL_ORDERS as $order ) {
				$expected[] = Stats_Store::url_rank_parts( $sort, $order, 'takahe.test', true );
			}
		}
		$this->assertSame( $expected, \array_column( $writes, 0 ), 'the server\'s fourteen, nothing else' );
		$this->assertSame( \array_fill( 0, 14, '2026-09-22-14' ), \array_column( $writes, 1 ) );
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
		// over the union would, ties and all.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 2, max_lifespan: 86400 );
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
				$union[ $hash ]              = $row;
			}
		}
		$store->bucket_set_multi( Stats_Store::ranked_writes( $servers, false, $bucket ) );
		$store->bucket_set_multi( [ [
			Stats_Store::url_srv_parts( false ),
			$bucket,
			[ Stats_Store::server_key( 'kea.test' ) => 'kea.test', Stats_Store::server_key( 'moa.test' ) => 'moa.test' ],
		] ] );
		$oracle = self::ranked_lists( Stats_Store::ranked_writes( [ 'site.test' => $union ], false, $bucket ) )[ Stats_Store::server_key( 'site.test' ) ];

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

	public function test_a_site_hour_answers_only_when_every_server_it_names_is_ranked(): void {
		// The hour tier stands for twelve buckets, so a list missing for one
		// server is an hour the reader must not serve as ranked. A fine
		// bucket answers with the lists it has, as a stale site list did.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 2, max_lifespan: 86400 );
		$index      = [ Stats_Store::server_key( 'kea.test' ) => 'kea.test', Stats_Store::server_key( 'moa.test' ) => 'moa.test' ];
		$rows       = [ 'kea.test' => [ 'a1a1a1a1a1a1' => self::positional_url_row( [ 'count' => 23, 'path' => '/kea-23' ] ) ] ];
		$store->bucket_set_multi( [
			...Stats_Store::ranked_writes( $rows, true, '2026-09-22-13' ),
			...Stats_Store::ranked_writes( $rows, false, '2026-09-22-14-05' ),
			[ Stats_Store::url_srv_parts( true ), '2026-09-22-13', $index ],
			[ Stats_Store::url_srv_parts( false ), '2026-09-22-14-05', $index ],
			[ Stats_Store::url_srv_parts( true ), '2026-09-22-12', [] ],
		] );

		$this->assertSame(
			[ [ '2026-09-22-12', [] ] ],
			$store->url_rank_window( [ '2026-09-22-13', '2026-09-22-12' ], [], 'count', 'desc', '' ),
			'moa.test has no list for hour 13; hour 12 is folded idle'
		);
		$fine = $store->url_rank_window( [], [ '2026-09-22-14-05' ], 'count', 'desc', '' );
		$this->assertSame( [ 'a1a1a1a1a1a1' ], \array_column( $fine[0][1], Stats_Store::RANK_HASH ) );
	}

	public function test_ranked_writes_cut_each_list_at_the_bound_of_its_own_tier(): void {
		// 201 rows: the fine tier keeps 200 of them, the coarse tier all 201.
		$rows = [];
		for ( $i = 0; $i < 201; $i++ ) {
			$rows[ \sprintf( '%012x', 0xa70000 + $i ) ] = self::positional_url_row( [ 'count' => 17 + $i ] );
		}
		$fine = Stats_Store::ranked_writes( [ 'kea.test' => $rows ], false, '2026-09-22-14-05' );
		$hour = Stats_Store::ranked_writes( [ 'kea.test' => $rows ], true, '2026-09-22-14' );
		$this->assertCount( Stats_Store::URL_RANK_N, $fine[1][2] );
		$this->assertCount( 201, $hour[1][2] );
	}

	public function test_paths_of_names_each_url_by_its_path_and_drops_the_unnamed(): void {
		// An all-digit hash arrives as an INT key, and a name blob is
		// `hash => path` with string keys both ways.
		$this->assertSame(
			[ '112233445566' => '/kakapo-4417', 'c9c9c9c9c9c9' => '/weka-1308?q=7' ],
			Stats_Store::paths_of( [
				112233445566   => 'https://kea.test/kakapo-4417',
				'c9c9c9c9c9c9' => '/weka-1308?q=7',
				'd1d1d1d1d1d1' => '',
			] )
		);
	}

	public function test_ranking_skips_untimed_rows_on_timed_sorts_and_unnamed_rows_on_url(): void {
		// The two skips this owns. The overflow and worker rows that never
		// rank at all are asserted through `ranked_writes()`, which is where
		// they are filtered.
		$rows = [
			'e5e5e5e5e5e5' => self::positional_url_row( [ 'count' => 40, 'timed_count' => 0, 'sum_ms' => 0.0, 'min_ms' => 0.0 ] ),
			'f6f6f6f6f6f6' => self::positional_url_row( [ 'count' => 3, 'timed_count' => 3, 'sum_ms' => 30.0, 'min_ms' => 10.0, 'max_ms' => 10.0, 'path' => '' ] ),
		];
		$lists = self::rank_url_rows( $rows, 10 );
		$hashes = static fn ( array $entries ): array => \array_column( $entries, Stats_Store::RANK_HASH );
		$this->assertSame( [ 'e5e5e5e5e5e5', 'f6f6f6f6f6f6' ], $hashes( $lists['count']['desc'] ) );
		$this->assertSame( [ 'f6f6f6f6f6f6' ], $hashes( $lists['min_ms']['asc'] ) );
		$this->assertSame( [ 'f6f6f6f6f6f6' ], $hashes( $lists['avg_ms']['desc'] ) );
		$this->assertSame( [], $lists['url']['asc'], 'no path, no url rank' );
	}

	public function test_rank_sources_read_the_lists_of_the_named_scope(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 2, max_lifespan: 86400 );
		$entries    = [ [ 'a1a1a1a1a1a1', self::positional_url_row( [ 'count' => 30 ] ) ] ];
		$store->bucket_set_multi( [
			[ Stats_Store::url_rank_parts( 'count', 'desc', 'kea.test', false ), '2026-09-22-14-05', $entries ],
		] );
		$this->assertSame(
			[ [ '2026-09-22-14-05', $entries ] ],
			$store->url_rank_window( [], [ '2026-09-22-14-05', '2026-09-22-14-10' ], 'count', 'desc', 'kea.test' )
		);
		$this->assertSame( [], $store->url_rank_window( [], [ '2026-09-22-14-05' ], 'count', 'desc', '' ), 'no index names kea.test' );
	}

	public function test_string_map_restores_the_string_keys_and_values_the_server_index_declares(): void {
		// The index is `server_key => name` both ways, and a decoded one is
		// neither: PHP hands back an all-digit key as an int, and a truncated
		// or corrupt entry can carry anything at all.
		$this->assertSame(
			[ '12345678' => 'kakapo.test', 'a7a7a7a7' => '' ],
			Stats_Store::string_map( [ 12345678 => 'kakapo.test', 'a7a7a7a7' => [ 'nope' ] ] )
		);
	}

	public function test_the_derived_probe_reports_rows_and_lists_apart(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		// Hours 07 and 09 hold every key of the one server their index names;
		// 09 has no leaderboard hour.
		foreach ( [ '2026-09-21-07', '2026-09-21-09' ] as $hour ) {
			self::seed_folded_hour( $store, $hour, [ 'kea.test' ] );
		}
		$store->bucket_set_multi( [
			[ Stats_Store::lb_hour_parts(), '2026-09-21-07', [] ],
			[ Stats_Store::lb_hour_parts(), '2026-09-21-08', [] ],
		] );
		$this->assertSame(
			[
				'2026-09-21-07' => [ 'folded' => true, 'unranked' => [ 'kea.test' ] ],
				'2026-09-21-08' => [ 'folded' => false, 'unranked' => [] ],
				'2026-09-21-09' => [ 'folded' => false, 'unranked' => [ 'kea.test' ] ],
			],
			$store->url_hours_derived( [ '2026-09-21-07', '2026-09-21-08', '2026-09-21-09' ] )
		);
	}

	public function test_the_derived_probe_names_each_server_of_an_hour_left_unranked(): void {
		// The marker is per server: kea.test's hour is ranked and moa.test's
		// is not, so the probe names moa.test alone and the ranker re-ranks
		// that server's hour rather than every server's.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		self::seed_folded_hour( $store, '2026-09-21-11', [ 'kea.test', 'moa.test' ] );
		$store->bucket_set_multi( [
			[ Stats_Store::lb_hour_parts(), '2026-09-21-11', [] ],
			[ Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'kea.test' ) ), '2026-09-21-11', [] ],
		] );
		$this->assertSame(
			[ '2026-09-21-11' => [ 'folded' => true, 'unranked' => [ 'moa.test' ] ] ],
			$store->url_hours_derived( [ '2026-09-21-11' ] )
		);

		$store->bucket_set_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'moa.test' ) ), '2026-09-21-11', [] ] ] );
		$this->assertSame(
			[ '2026-09-21-11' => [ 'folded' => true, 'unranked' => [] ] ],
			$store->url_hours_derived( [ '2026-09-21-11' ] )
		);
	}

	public function test_an_hour_missing_one_server_s_shard_is_not_folded(): void {
		// The index says which servers an hour holds, so every one of their
		// keys has to be there: a shard evicted or refused for one server is
		// an hour the fold has to redo, not one a reader trusts.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hour       = '2026-09-21-13';
		self::seed_folded_hour( $store, $hour, [ 'kea.test', 'moa.test' ] );
		$store->bucket_set_multi( [ [ Stats_Store::lb_hour_parts(), $hour, [] ] ] );
		$this->assertTrue( $store->url_hours_derived( [ $hour ] )[ $hour ]['folded'] );

		$store->bucket_forget( Stats_Store::url_hour_parts( Stats_Store::server_key( 'moa.test' ), 'w7' ), $hour );

		$this->assertFalse( $store->url_hours_derived( [ $hour ] )[ $hour ]['folded'] );
	}

	public function test_the_fine_rank_tiers_take_the_fine_ttl(): void {
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$m     = new \ReflectionMethod( $store, 'ttl_for' );
		$this->assertSame( $store->ttl_url_fine(), $m->invoke( $store, Stats_Store::NS_URLRANK_S ) );
		$this->assertSame( $store->ttl(), $m->invoke( $store, Stats_Store::NS_URLRANK_HOUR_S ) );
	}

	public function test_term_tokens_are_lowercase_alphanumeric_runs_of_two_or_more(): void {
		$this->assertSame( [ 'wombat', '7731', 'kea', 'json' ], Stats_Store::term_tokens( '/Wombat-7731/kea/a/?json=1' ) );
		$this->assertSame( [ 'internationa' ], Stats_Store::term_tokens( 'Internationalization' ) );
		$this->assertSame( [], Stats_Store::term_tokens( '-/-' ) );
	}

	public function test_term_matches_reads_a_term_the_way_the_index_files_it(): void {
		$path = '/kakapo/nest-9317';
		$this->assertTrue( Stats_Store::term_matches( $path, 'kaka', [ 'kaka' ] ) );
		$this->assertTrue( Stats_Store::term_matches( '/KAKAPO/nest-9317', 'kaka', [ 'kaka' ] ), 'the name is lowercased' );
		$this->assertTrue( Stats_Store::term_matches( $path, 'kakapo nest', [ 'kakapo', 'nest' ] ), 'every token, in any order' );
		$this->assertFalse( Stats_Store::term_matches( $path, 'kakapo weka', [ 'kakapo', 'weka' ] ), 'one token missing refuses' );
		// The separator class is the index's: a token begins a word or nothing.
		$this->assertFalse( Stats_Store::term_matches( $path, '317', [ '317' ] ), 'a word INFIX never matches' );
		$this->assertTrue( Stats_Store::term_matches( $path, '931', [ '931' ] ), 'a word PREFIX does, as the index files it' );
		$this->assertTrue( Stats_Store::term_matches( $path, 'o/n', [] ), 'a term with no token is a substring' );
	}

	public function test_url_tokens_are_every_prefix_of_every_token(): void {
		// From THREE: a two-character prefix names most of a real site, so it
		// saturates at once and answers nothing the fold could not.
		$this->assertSame(
			[ 'wom', 'womb', 'womba', 'wombat', '773', '7731' ],
			Stats_Store::url_tokens( '/wombat-7731' )
		);
		$this->assertSame( 10, \count( Stats_Store::url_tokens( '/internationalization' ) ), 'cut at the prefix ceiling' );
		$this->assertSame( [ 'kea' ], Stats_Store::url_tokens( '/kea/kea' ), 'deduplicated' );
		$this->assertSame( [], Stats_Store::url_tokens( '/at/88' ), 'a two-character word files nothing' );
	}

	public function test_token_sets_of_groups_every_named_path_by_token(): void {
		$this->assertSame(
			[
				'tui'  => [ 'aa11bb22cc33', 'dd44ee55ff66' ],
				'tuis' => [ 'dd44ee55ff66' ],
			],
			Stats_Store::token_sets_of( [
				'aa11bb22cc33' => '/tui',
				'dd44ee55ff66' => '/tuis',
			] )
		);
	}

	public function test_a_token_set_unions_and_drops_a_hash_a_window_has_passed_over(): void {
		// A set of three, nowhere near the cap: the dead entry still goes,
		// because a reader names every hash it holds and counts each against
		// `URL_SEARCH_MAX`. Deciding costs one pass over the stamps, which is
		// less than the prune it decides on.
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now   = 1_700_000_000;

		$this->assertSame(
			[ 'a1a1a1a1a1a1' => $now, 'b2b2b2b2b2b2' => $now ],
			$store->merge_token_set(
				[ 'a1a1a1a1a1a1' => $now - 90, 'c3c3c3c3c3c3' => $now - 86_401 ],
				[ 'b2b2b2b2b2b2', 'a1a1a1a1a1a1' ],
				$now
			),
			'this flush restamps what it names and the window retires the rest'
		);
	}

	public function test_a_token_set_at_the_cap_prunes_the_dead_rather_than_saturating(): void {
		// The cap is the other trigger, and the cheaper one: a flush that
		// would pass it prunes without scanning the stamps at all.
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now   = 1_700_000_000;
		$dead  = [];
		for ( $i = 0; $i < Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$dead[ \sprintf( '%012x', 0xd0000 + $i ) ] = $now - 86_401;
		}

		$this->assertSame(
			[ 'b2b2b2b2b2b2' => $now ],
			$store->merge_token_set( $dead, [ 'b2b2b2b2b2b2' ], $now ),
			'a set nothing has named for a window makes room rather than saturating'
		);
	}

	public function test_a_token_set_saturates_when_its_live_count_passes_the_cap(): void {
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now   = 1_700_000_000;
		$live  = [];
		for ( $i = 0; $i < Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$live[ \sprintf( '%012x', 0xe0000 + $i ) ] = $now - 90;
		}

		$this->assertSame(
			[ Stats_Store::TOKEN_SATURATED => $now ],
			$store->merge_token_set( $live, [ 'b2b2b2b2b2b2' ], $now )
		);
	}

	public function test_a_live_sentinel_is_returned_unchanged_so_its_key_keeps_its_ttl(): void {
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now   = 1_700_000_000;

		$this->assertSame(
			[ Stats_Store::TOKEN_SATURATED => $now - 90 ],
			$store->merge_token_set( [ Stats_Store::TOKEN_SATURATED => $now - 90 ], [ 'e5e5e5e5e5e5' ], $now ),
			'flush_writes() skips a write whose value equals what it read'
		);
	}

	public function test_token_sets_read_the_tokens_the_store_holds(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 3, max_lifespan: 86400 );
		// Stored as `hash => last named`; the reader takes the hashes alone.
		$store->bucket_set_multi( [
			[ Stats_Store::url_token_parts( Stats_Store::server_key( 'kea.test' ) ), 'womb', [ 'a1a1a1a1a1a1' => 1_700_000_000 ] ],
			[ Stats_Store::url_token_parts( Stats_Store::server_key( 'moa.test' ) ), 'womb', [ 'b2b2b2b2b2b2' => 1_700_000_000 ] ],
			[ Stats_Store::url_token_parts( Stats_Store::server_key( 'tui.test' ) ), 'womb', [ 'c3c3c3c3c3c3' => 1_700_000_000 ] ],
		] );
		$this->assertSame(
			[ 'womb' => [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ] ],
			$store->url_token_sets( [ 'womb', 'kiwi' ], [ 'kea.test', 'moa.test' ] ),
			'the servers named, unioned; tui.test is not asked'
		);
		$this->assertSame( [ 'womb' => [ 'b2b2b2b2b2b2' ] ], $store->url_token_sets( [ 'womb' ], [ 'moa.test' ] ) );
		$this->assertSame( [], $store->url_token_sets( [ 'womb' ], [] ), 'no server, nothing held' );
	}

	public function test_the_store_answers_a_saturated_token_and_a_sub_floor_one_alike(): void {
		// Which tokens can be ANSWERED is the schema's to say: a saturated set
		// narrows nothing, and a token below URL_TOKEN_PREFIX_MIN was never
		// filed, so no read can answer either. Both come back false, an unheld
		// token is absent, and a held one is its hashes.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 3, max_lifespan: 86400 );
		// One server's set saturated is the token's answer across both.
		$store->bucket_set_multi( [
			[ Stats_Store::url_token_parts( Stats_Store::server_key( 'kea.test' ) ), 'wom', [ Stats_Store::TOKEN_SATURATED => 1_700_000_000 ] ],
			[ Stats_Store::url_token_parts( Stats_Store::server_key( 'moa.test' ) ), 'wom', [ 'd4d4d4d4d4d4' => 1_700_000_000 ] ],
			[ Stats_Store::url_token_parts( Stats_Store::server_key( 'kea.test' ) ), 'takahe', [ 'b2b2b2b2b2b2' => 1_700_000_000 ] ],
		] );

		$this->assertSame(
			[ 'at' => false, 'wom' => false, 'takahe' => [ 'b2b2b2b2b2b2' ] ],
			$store->url_token_sets( [ 'at', 'wom', 'takahe', 'kiwi' ], [ 'moa.test', 'kea.test' ] )
		);
	}

	public function test_a_sub_floor_token_is_refused_without_a_read(): void {
		// No read can answer it, so asking for one is a round trip spent to
		// learn what URL_TOKEN_PREFIX_MIN already says.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( partition: 3, max_lifespan: 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $asked = [];
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				foreach ( $reads as [ , $bucket ] ) {
					$this->asked[] = (string) $bucket;
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};

		$this->assertSame( [ 'at' => false ], $store->url_token_sets( [ 'at', 'takahe' ], [ 'kea.test' ] ), 'takahe is unheld, so absent' );
		$this->assertSame( [ 'takahe' ], $store->asked, 'only the token a read could answer' );
	}

	/**
	 * `ranked_writes()` triples as server key => `sort:order` => entries.
	 *
	 * @param list<array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes
	 * @return array<string,array<string,array<array-key,mixed>>>
	 */
	private static function ranked_lists( array $writes ): array {
		$lists = [];
		foreach ( $writes as [ $parts, , $entries ] ) {
			$lists[ $parts[1] ][ $parts[ \count( $parts ) - 2 ] . ':' . $parts[ \count( $parts ) - 1 ] ] = $entries;
		}
		return $lists;
	}

	/**
	 * The private one-scope ranker, cut to `$n` rather than a tier's bound.
	 *
	 * @param array<string,array<array-key,mixed>> $rows One scope's rows by hash.
	 * @param int                                  $n    Entries per list.
	 * @return array<string,array<string,list<array<int,mixed>>>>
	 */
	private static function rank_url_rows( array $rows, int $n ): array {
		/** @var array<string,array<string,list<array<int,mixed>>>> */
		return ( new \ReflectionMethod( Stats_Store::class, 'rank_url_rows' ) )->invoke( null, $rows, $n );
	}

	/**
	 * What a fold leaves for one hour: the index naming `$servers`, and
	 * every shard of both families written, empty, for each of them.
	 *
	 * @param list<string> $servers Server names.
	 */
	private static function seed_folded_hour( Stats_Store $store, string $hour, array $servers ): void {
		$index  = [];
		$writes = [];
		foreach ( $servers as $server ) {
			$key           = Stats_Store::server_key( $server );
			$index[ $key ] = $server;
			foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $shard ) {
				$writes[] = [ Stats_Store::url_hour_parts( $key, $shard ), $hour, [] ];
			}
		}
		$writes[] = [ Stats_Store::url_srv_parts( true ), $hour, $index ];
		$store->bucket_set_multi( $writes );
	}

	public function test_the_estimate_follows_the_serializer_the_handle_is_configured_with(): void {
		$mc = $this->seed_memd();
		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY );
		$igbinary = Stats_Store::overhead( 'url_row' );
		$this->assertSame( Stats_Store::SERIALIZER_IGBINARY, Stats_Store::$serializer );

		Stats_Store::$serializer = null;
		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP );
		$php = Stats_Store::overhead( 'url_row' );
		$this->assertSame( Stats_Store::SERIALIZER_PHP, Stats_Store::$serializer );
		$this->assertGreaterThan( $igbinary, $php, 'serialize() spells a row longer' );

		$mc->setOption( \Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY );
		$this->assertSame( $php, Stats_Store::overhead( 'url_row' ), 'read once, then memoized' );
	}

	public function test_with_no_handle_the_estimate_is_the_larger_serializers(): void {
		Core::$memd = null;

		Stats_Store::overhead( 'url_row' );

		$this->assertSame( Stats_Store::SERIALIZER_PHP, Stats_Store::$serializer );
	}

	public function test_an_unknown_part_has_no_estimate(): void {
		$this->expectException( \LogicException::class );
		Stats_Store::overhead( 'no_such_part' );
	}
}
