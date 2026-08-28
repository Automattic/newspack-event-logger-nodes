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

	public function test_the_row_index_tables_cover_every_index_contiguously(): void {
		// `fold_index_row()` and `swap_url_server_sums()` both read
		// ROW_FIELD_NAMES[ $index ] unguarded, per row, on the hot read path,
		// and the two tables are hand-matched. Decision 18's "the eight that
		// ADD come FIRST" is otherwise only prose: a ninth summed field
		// appended past the end works and falsifies it silently.
		$this->assertSame( \range( 0, 14 ), \array_keys( Stats_Store::ROW_FIELD_NAMES ) );
		$this->assertSame( \range( 0, 7 ), \array_keys( Stats_Store::URL_SRV_SUMS ) );
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
		$this->assertSame(
			[ $hash => [ 'url' => '/a', 'count' => 3 ] ],
			self::named_url_rows( \Newspack_Nodes\Core::arr( $table->lookup( Stats_Store::NS_URLS . ':a:2026-08-14-12-05' ) ) ),
			'a row lands in the shard its hash names'
		);
		$this->assertSame(
			[ $other => [ 'url' => '/b', 'count' => 5 ] ],
			self::named_url_rows( \Newspack_Nodes\Core::arr( $table->lookup( Stats_Store::NS_URLS . ':0:2026-08-14-12-05' ) ) )
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

		$this->assertSame( 1, $mc->multi_calls, 'every shard in one round trip' );
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

		$this->assertSame( [], $store->get_url_shard( '2026-08-14-12-05', 'a' ) );
		$this->assertSame(
			[ '0f0f0f0f0f0f' => [ 'url' => '/b', 'count' => 5 ] ],
			self::named_url_rows( $store->get_url_shard( '2026-08-14-12-05', '0' ) )
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
	 * traffic missing from every `urls` and `url_detail` answer — silently,
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
		$store->set_url_hour( '2026-08-27-13', 'a', [ 'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 9 ] ] );

		$mc->multi_calls = 0;
		$sources         = $store->url_hour_sources( [ '2026-08-27-13', '2026-08-27-12' ], 'a' );

		$this->assertSame( 1, $mc->multi_calls );
		$this->assertSame( [ [ '2026-08-27-13', [ 'a1b2c3d4e5f6' => [ 'url' => '/a', 'count' => 9 ] ] ] ], $sources );
	}

	public function test_a_url_row_read_is_never_scoped_to_a_server(): void {
		// @longform Decision 14: the SERVER scope is a projection over the
		// merged row, never a filter before the merge — scoping the loader by
		// it makes the memo a per-scope cache, and a poll that asks scoped and
		// unscoped pays the whole read twice. The one narrowing this takes is
		// the SHARD, which the same decision names as a constant the schema
		// chooses rather than an input it does not control: one URL lives in
		// one shard, and the memo stays whole because a request holding the
		// index answers from it instead of reading again.
		$params = ( new \ReflectionMethod( Stats_Store::class, 'url_row_sources' ) )->getParameters();
		$this->assertSame( [ 'buckets', 'shard' ], \array_map( static fn ( $p ) => $p->getName(), $params ) );
		$this->assertTrue( $params[1]->isOptional(), 'the whole index stays the default' );
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

		$this->assertTrue( $store->set_hourly_bucket( '2026-08-14T12', [ 'count' => 7391 ] ) );

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

		$this->assertFalse( $store->set_hourly_bucket( 'x', [ 'count' => 1 ] ), 'a refused write reports false' );
		$this->assertSame( [], $mirrored, 'and is not shadowed' );
	}

	public function test_every_read_is_empty_with_no_cache_backend(): void {
		// Fail-soft is the contract: Table_Node::table() THROWS without a
		// backing store, so the port must build lazily behind that check.
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$this->assertSame( [], $store->get_hourly_bucket( 'x' ) );
		$this->assertSame( [], $this->url_rows_by_bucket( $store, [ 'b1', 'b2' ] ) );
		$this->assertNull( $store->get_url_stats( 'abc' ) );
		$this->assertFalse( $store->set_hourly_bucket( 'x', [ 'count' => 1 ] ), 'a write with no backend reports false' );
	}

	public function test_stats_keys_are_scoped_to_this_install(): void {
		// Stats live in memcache alone, and two installs share one server on
		// Atomic. Unscoped, `evlog:p0:hourly` is the SAME key for both, so a
		// co-tenant's request volume lands in this install's dashboard.
		$mc = $this->seed_memd();
		// The suite nulls globals between tests; own the shim here.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix      = 'wp_';
			public string $base_prefix = 'wp_';
		};
		\Newspack_Nodes\Cache_Backend::$site = '';

		$this->make_store()->set_hourly_bucket( '2026-01-01-00', [ "count" => 7719 ] );
		$mine = $mc->keys();

		$GLOBALS['wpdb']->base_prefix       = 'wpco_tenant_';
		\Newspack_Nodes\Cache_Backend::$site = '';
		$this->make_store()->set_hourly_bucket( '2026-01-01-00', [ "count" => 1 ] );

		\Newspack_Nodes\Cache_Backend::$site = '';

		$this->assertNotEmpty( $mine );
		$this->assertSame( [], \array_intersect( $mine, \array_diff( $mc->keys(), $mine ) ) );
		foreach ( $mc->keys() as $key ) {
			$this->assertStringStartsWith( 'newspack_nodes:', $key );
		}
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
		$store->set_url_stats( 'urlhash-x', [ 'flame' => [ 1, 2, 3 ] ] );
		$this->assertSame(
			[ 'flame' => [ 1, 2, 3 ] ],
			$store->get_url_stats( 'urlhash-x' )
		);
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
		$this->assertSame( [], $store->get_hourly_bucket( 'any' ) );
		$this->assertSame( [], $store->get_dimensional_bucket( 'status', 'b1' ) );
	}

	public function test_fail_soft_set_returns_false_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertFalse( $this->set_url_bucket( $store, '2026-01-01-00-00', [] ) );
		$this->assertFalse( $store->set_leaderboard_bucket( '2026-01-01-00-00', [] ) );
		$this->assertFalse( $store->set_dimensional_bucket( 'status', 'b1', [] ) );
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
		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 5, 'sum_ms' => 100, 'sum_peak_mb' => 10 ] );
		$h = $store->get_hourly_buckets( [ '2026-01-01-00' ] );
		$this->assertSame( 5, $h['2026-01-01-00']['count'] );
	}

	public function test_set_and_get_leaderboard_bucket_round_trip(): void {
		$store = $this->make_store();
		$store->set_leaderboard_bucket( '2026-01-01-00-05', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$lb = $store->get_leaderboard_bucket( '2026-01-01-00-05' );
		$this->assertSame( 3, $lb['count'] );
		$this->assertEqualsWithDelta( 1.5, $lb['sum_req_time'], 1e-9 );
	}

	public function test_set_and_get_server_leaderboard_bucket_round_trip(): void {
		$store = $this->make_store();
		$store->set_leaderboard_bucket( '2026-01-01-00-05', [ 'count' => 7, 'sum_req_time' => 3.5, 'categories' => [] ], 'srv-x' );
		$lb = $store->get_leaderboard_bucket( '2026-01-01-00-05', 'srv-x' );
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

	public function test_set_hourly_invokes_mirror_with_key_data_ttl_ns(): void {
		$store    = $this->make_store();
		$captured = [];
		$store->mirror = static function ( string $key, array $data, int $ttl, string $ns ) use ( &$captured ): void {
			$captured[] = [ $key, $data, $ttl, $ns ];
		};

		$data = [ 'count' => 5 ];
		$store->set_hourly_bucket( '2026-01-01-00', $data );

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
		$store->set_url_stats( 'abc', $data );

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

		$store->set_hourly_bucket( 'x', [ 'count' => 1 ] );
		$store->set_url_shard( 'b', '0', [ 'x' => [ 'url' => '/x' ] ] );
		$store->set_url_stats( 'h', [ 'flame' => [ 'count' => 1 ] ] );
		$store->set_leaderboard_bucket( 'b', [ 'count' => 1 ] );
		$store->set_leaderboard_bucket( 'b', [ 'count' => 1 ], 'srv' );
		$store->set_dimensional_bucket( 'status', 'b', [ '200' => [ 'c' => 1 ] ] );
		$store->set_url_dimensional_bucket( 'h', 'b', [ 'status' => [ '200' => [ 'c' => 1 ] ] ] );
		$store->set_category_bucket( 'b', [ 'total' => [ 'n' => 1 ] ] );
		$store->set_category_bucket( 'b', [ 'total' => [ 'n' => 1 ] ], 'srv' );
		$store->set_url_category_bucket( 'h', 'b', [ 'total' => [ 'n' => 1 ] ] );

		$this->assertSame(
			[
				Stats_Store::NS_HOURLY,
				Stats_Store::NS_URLS,
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
		$this->assertTrue( $store->set_hourly_bucket( 'x', [ 'count' => 1 ] ) );
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
		$this->assertFalse( $store->set_hourly_bucket( 'x', [ 'count' => 1 ] ) );
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
		$store->set_leaderboard_bucket( 'b1', [ 'count' => 31, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$store->set_leaderboard_bucket( 'b3', [ 'count' => 74, 'sum_req_time' => 2.5, 'categories' => [] ] );

		$rows = $store->get_leaderboard_buckets( [ 'b1', 'b2', 'b3' ] );

		$this->assertSame( [ 'b1', 'b3' ], \array_keys( $rows ), 'absent buckets omitted, present ones keyed by bucket' );
		$this->assertSame( 74, $rows['b3']['count'] );
	}

	public function test_leaderboard_buckets_scope_to_a_server_when_asked(): void {
		$store = $this->make_store();
		$store->set_leaderboard_bucket( 'b1', [ 'count' => 31, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$store->set_leaderboard_bucket( 'b1', [ 'count' => 88, 'sum_req_time' => 3.5, 'categories' => [] ], 'spoke-a' );

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

		$this->assertSame( [ 'count' => 42 ], $store->get_hourly_bucket( '2026-02-03-04-05' ) );
	}

	public function test_a_miss_is_filled_from_the_durable_backing(): void {
		$store = $this->make_store();
		$value = [ 'count' => 9 ];
		$this->arm_entry( $store, Stats_Store::NS_HOURLY . ':2026-01-01-00', $value, 100 );

		$this->assertSame( $value, $store->get_hourly_bucket( '2026-01-01-00' ) );
	}

	public function test_an_entry_whose_lifetime_ran_out_is_not_filled(): void {
		$store = $this->make_store();
		$this->arm_entry( $store, Stats_Store::NS_HOURLY . ':x', [ 'count' => 1 ], 0 );

		$this->assertSame( [], $store->get_hourly_bucket( 'x' ) );
	}

	public function test_the_backing_answers_in_the_stores_own_keyspace(): void {
		// The Table applies the namespace, so a backing cannot reach another
		// scope — the guard the old full-key seam needed is gone by construction.
		$store = $this->make_store();
		$this->arm_entry( $store, 'other:p0:hourly:x', [ 'count' => 1 ], 100 );

		$this->assertSame( [], $store->get_hourly_bucket( 'x' ), 'a key this store never asked for fills nothing' );
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
		$store->set_leaderboard_bucket( '2026-01-01-00-05', [ 'count' => 61 ], 'web07' );

		$this->assertSame( 61, ( $store->get_leaderboard_buckets( [ '2026-01-01-00-05' ], 'web07'  )[ '2026-01-01-00-05' ] ?? [] )['count'] );
		$this->assertSame( [], $store->get_leaderboard_bucket( '2026-01-01-00-05' ), 'the global scope is a different key' );
	}

	public function test_dimensional_bucket_round_trips_global_and_per_server(): void {
		// Every bucketed namespace is keyed with the bucket LAST, so one
		// lookup_buckets() batch serves them all.
		$store = $this->make_store();
		$store->set_dimensional_bucket( 'status', '2026-02-03-04-05', [ '503' => [ 'c' => 47, 's' => 12.5, 'm' => 3.0 ] ] );
		$store->set_dimensional_bucket( 'status', '2026-02-03-04-05', [ '503' => [ 'c' => 91, 's' => 1.0, 'm' => 1.0 ] ], 'web07' );

		$this->assertSame( 47, $store->get_dimensional_bucket( 'status', '2026-02-03-04-05' )['503']['c'] );
		$this->assertSame( 91, $store->get_dimensional_bucket( 'status', '2026-02-03-04-05', 'web07' )['503']['c'] );
		$this->assertSame(
			47,
			$store->get_dimensional_buckets( 'status', [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['503']['c'],
			'the batch read returns the same value keyed by bucket'
		);
	}

	public function test_category_bucket_round_trips_global_and_per_server(): void {
		$store = $this->make_store();
		$store->set_category_bucket( '2026-02-03-04-05', [ 'wpdb' => [ 't' => 8.5, 'c' => 23, 'n' => 4 ] ] );
		$store->set_category_bucket( '2026-02-03-04-05', [ 'wpdb' => [ 't' => 1.5, 'c' => 66, 'n' => 2 ] ], 'web07' );

		$this->assertSame( 23, $store->get_category_bucket( '2026-02-03-04-05' )['wpdb']['c'] );
		$this->assertSame( 66, $store->get_category_bucket( '2026-02-03-04-05', 'web07' )['wpdb']['c'] );
		$this->assertSame(
			23,
			$store->get_category_buckets( [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['wpdb']['c']
		);
	}

	public function test_url_dimensional_bucket_holds_every_dimension_for_one_bucket(): void {
		// Bucket last, dims inside the value: 288 keys per URL rather than a
		// dimension-by-bucket cross-product.
		$store = $this->make_store();
		$store->set_url_dimensional_bucket( 'ab12cd34ef56', '2026-02-03-04-05', [
			'status' => [ '503' => [ 'c' => 29, 's' => 1.0, 'm' => 1.0 ] ],
			'method' => [ 'POST' => [ 'c' => 31, 's' => 2.0, 'm' => 2.0 ] ],
		] );

		$got = $store->get_url_dimensional_bucket( 'ab12cd34ef56', '2026-02-03-04-05' );
		$this->assertSame( 29, $got['status']['503']['c'] );
		$this->assertSame( 31, $got['method']['POST']['c'] );
		$this->assertSame(
			29,
			$store->get_url_dimensional_buckets( 'ab12cd34ef56', [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['status']['503']['c']
		);
	}

	public function test_url_category_bucket_round_trips(): void {
		$store = $this->make_store();
		$store->set_url_category_bucket( 'ab12cd34ef56', '2026-02-03-04-05', [ 'wpdb' => [ 't' => 3.5, 'c' => 57, 'n' => 2 ] ] );

		$this->assertSame( 57, $store->get_url_category_bucket( 'ab12cd34ef56', '2026-02-03-04-05' )['wpdb']['c'] );
		$this->assertSame(
			57,
			$store->get_url_category_buckets( 'ab12cd34ef56', [ '2026-02-03-04-05' ] )['2026-02-03-04-05']['wpdb']['c']
		);
	}

	public function test_a_scope_is_a_different_keyspace_not_a_shared_one(): void {
		// '' vs a named server, and one URL vs another, must not collide.
		$store = $this->make_store();
		$store->set_category_bucket( 'b1', [ 'wpdb' => [ 'c' => 13 ] ] );
		$store->set_url_category_bucket( 'aaaaaaaaaaaa', 'b1', [ 'wpdb' => [ 'c' => 77 ] ] );

		$this->assertSame( [], $store->get_category_buckets( [ 'b1' ], 'web07' )[ 'b1' ] ?? [] );
		$this->assertSame( [], $store->get_url_category_buckets( 'bbbbbbbbbbbb', [ 'b1' ] )[ 'b1' ] ?? [] );
		$this->assertSame( 13, ( $store->get_category_bucket( 'b1' ) )['wpdb']['c'] );
		$this->assertSame( 77, ( $store->get_url_category_buckets( 'aaaaaaaaaaaa', [ 'b1' ]  )[ 'b1' ] ?? [] )['wpdb']['c'] );
	}

	public function test_every_bucketed_write_lands_under_the_retention_ttl(): void {
		// Retention IS the key's own TTL since the hand-rolled cutoff passes went
		// away — a write at the wrong TTL never expires, or expires 24x early,
		// and no other assertion in the suite would notice.
		$mc    = $this->seed_memd();
		$store = $this->make_store( max_lifespan: 7200 ); // 2h: distinct from every default

		$store->set_hourly_bucket( 'b1', [ 'count' => 3 ] );
		$store->set_leaderboard_bucket( 'b1', [ 'count' => 3 ] );
		$store->set_leaderboard_bucket( 'b1', [ 'count' => 3 ], 'web07' );
		$store->set_url_shard( 'b1', '0', [ 'h' => [ 'count' => 3 ] ] );
		$store->set_dimensional_bucket( 'status', 'b1', [ '503' => [ 'c' => 3 ] ] );
		$store->set_dimensional_bucket( 'status', 'b1', [ '503' => [ 'c' => 3 ] ], 'web07' );
		$store->set_category_bucket( 'b1', [ 'db' => [ 'n' => 3 ] ] );
		$store->set_category_bucket( 'b1', [ 'db' => [ 'n' => 3 ] ], 'web07' );
		$store->set_url_dimensional_bucket( 'h', 'b1', [ 'status' => [ '503' => [ 'c' => 3 ] ] ] );
		$store->set_url_category_bucket( 'h', 'b1', [ 'db' => [ 'n' => 3 ] ] );

		$expiries = $mc->expiries();
		$this->assertCount( 10, $expiries, 'each scope is its own key' );
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

		$store->url_row_sources( [ $bucket ] );

		$unsharded = Stats_Store::entry_key( 0, 'urls:' . $bucket );
		/** @var array<int,string> $asked */
		$asked = Core::$memd->asked;
		$this->assertNotContains( $unsharded, $asked, 'the unsharded key is never asked for' );
		$this->assertContains(
			Stats_Store::entry_key( 0, 'urls:a:' . $bucket ),
			$asked,
			'every shard is'
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
		$folded = Stats_Store::fold_url_rows(
			[ 'count' => 1, 'worker' => true ],
			[ 'count' => 1 ]
		);

		$this->assertTrue( $folded['worker'] );
	}

	// ── decision 14: the split's one crossing into display names ────────────

	public function test_a_scoped_read_swaps_in_that_server_s_own_sums(): void {
		// The row totals 9 requests; edge-77 served 4 of them. A scoped read
		// must report the SERVER's numbers under the row's own field names,
		// and must not leak the split itself. Seeds distinct from every
		// default and from each other, so a swap that misses reads wrong.
		$row = [
			Stats_Store::ROW_COUNT       => 9,
			Stats_Store::ROW_TIMED_COUNT => 9,
			Stats_Store::ROW_SUM_MS      => 990.0,
			Stats_Store::ROW_SUM_PEAK_MB => 45.0,
			Stats_Store::ROW_URL         => '/scoped-6612',
			Stats_Store::URL_SRV_FIELD   => [
				'edge-77' => [
					Stats_Store::ROW_COUNT       => 4,
					Stats_Store::ROW_TIMED_COUNT => 4,
					Stats_Store::ROW_SUM_MS      => 480.0,
					Stats_Store::ROW_SUM_PEAK_MB => 20.0,
				],
			],
		];

		$scoped = Stats_Store::swap_url_server_sums( $row, 'edge-77' );
		$this->assertNotNull( $scoped );
		$this->assertSame( 4, $scoped['count'], "the server's count, not the row's" );
		$this->assertSame( 480.0, (float) $scoped['sum_ms'] );
		$this->assertSame( '/scoped-6612', $scoped[ Stats_Store::ROW_URL ], 'unswapped fields survive' );
		$this->assertArrayNotHasKey( Stats_Store::URL_SRV_FIELD, $scoped, 'the split never crosses the wire' );
	}

	public function test_a_row_that_never_saw_the_server_scopes_to_nothing(): void {
		// Not zero: absent. A row the server never served must leave the
		// filtered table AND its totals, or every URL reads as site-wide.
		$row = [
			Stats_Store::ROW_COUNT     => 9,
			Stats_Store::URL_SRV_FIELD => [ 'edge-77' => [ Stats_Store::ROW_COUNT => 9 ] ],
		];
		$this->assertNull( Stats_Store::swap_url_server_sums( $row, 'edge-99' ) );
	}

	public function test_an_unscoped_read_returns_the_row_without_its_split(): void {
		$row = [ Stats_Store::ROW_COUNT => 9, Stats_Store::URL_SRV_FIELD => [ 'edge-77' => [] ] ];
		$whole = Stats_Store::swap_url_server_sums( $row, '' );
		$this->assertSame( 9, $whole[ Stats_Store::ROW_COUNT ] );
		$this->assertArrayNotHasKey( Stats_Store::URL_SRV_FIELD, $whole );
	}

	public function test_the_coarse_hour_round_trips_through_its_own_getter(): void {
		// set_url_hour()'s other half: a writer merging into a folded hour
		// reads it the way get_url_shard() reads the fine tier.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$rows       = [ 'hash-4471' => [ Stats_Store::ROW_COUNT => 6, Stats_Store::ROW_URL => '/hourly-4471' ] ];

		$this->assertTrue( $store->set_url_hour( '2026-08-27-13', 'a', $rows ) );
		$this->assertSame( $rows, $store->get_url_hour( '2026-08-27-13', 'a' ) );
		$this->assertSame( [], $store->get_url_hour( '2026-08-27-14', 'a' ), 'an unfolded hour reads empty' );
	}
}
