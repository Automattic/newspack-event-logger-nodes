<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

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
		$store_p0->set_url_index_hourly( '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
		$store_p1->set_url_index_hourly( '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
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
		$store->set_url_index_hourly( '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
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

	public function test_flush_all_rotates_salt_and_orphans_keys(): void {
		$mc    = $this->seed_memd();
		$store = $this->make_store();
		$store->set_url_index_hourly( '2026-01-01-00-00', [ 'x' => [ 'url' => '/x' ] ] );
		$old_keys = $mc->keys();
		$this->assertNotEmpty( $old_keys );

		$store->flush_all();
		$store->set_url_index_hourly( '2026-01-01-00-00', [ 'y' => [ 'url' => '/y' ] ] );
		$new_keys = $mc->keys();

		$this->assertNotEquals( $old_keys, $new_keys, 'salt rotation should change keys' );
		// The new bump should NOT find the old data because the prefix is different.
		$stats = $store->get_url_bucket( '2026-01-01-00-00' );
		$this->assertArrayNotHasKey( 'x', $stats );
		$this->assertArrayHasKey( 'y', $stats );
	}

	public function test_fail_soft_get_returns_empty_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertSame( [], $store->get_url_bucket( 'any' ) );
		$this->assertNull( $store->get_url_stats( 'any' ) );
		$this->assertSame( [], $store->get_hourly() );
		$this->assertSame( [], $store->get_dimensional( 'status' ) );
	}

	public function test_fail_soft_set_returns_false_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertFalse( $store->set_url_index_hourly( '2026-01-01-00-00', [] ) );
		$this->assertFalse( $store->set_leaderboard_bucket( '2026-01-01-00-00', [] ) );
		$this->assertFalse( $store->set_dimensional( 'status', [] ) );
		$this->assertNull( Core::$memd );
	}

	public function test_get_multi_url_buckets_batches_lookups(): void {
		$store = $this->make_store();
		$bucket = '2026-01-01-00-00';
		$store->set_url_index_hourly( $bucket, [
			'/x' => [ 'url' => '/x' ],
			'/y' => [ 'url' => '/y' ],
		] );

		// get_url_buckets should accept a list and return a map.
		$results = $store->get_url_buckets( [ $bucket, 'nonexistent-bucket' ] );
		$this->assertArrayHasKey( $bucket, $results );
		$this->assertArrayHasKey( '/x', $results[ $bucket ] );
		$this->assertArrayHasKey( '/y', $results[ $bucket ] );
		// Missing bucket should not appear.
		$this->assertArrayNotHasKey( 'nonexistent-bucket', $results );
	}

	public function test_get_url_buckets_returns_empty_when_memd_null(): void {
		Core::$memd = null;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->assertSame( [], $store->get_url_buckets( [ 'a', 'b' ] ) );
	}

	// --- New explicit-bucket setter API (FlameBuilder uses these) ---------

	public function test_set_and_get_hourly_round_trip(): void {
		$store = $this->make_store();
		$store->set_hourly( [ '2026-01-01-00' => [ 'count' => 5, 'sum_ms' => 100, 'sum_peak_mb' => 10 ] ] );
		$h = $store->get_hourly();
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
		$store->set_server_leaderboard_bucket( 'srv-x', '2026-01-01-00-05', [ 'count' => 7, 'sum_req_time' => 3.5, 'categories' => [] ] );
		$lb = $store->get_server_leaderboard_bucket( 'srv-x', '2026-01-01-00-05' );
		$this->assertSame( 7, $lb['count'] );
	}

	public function test_set_and_get_url_index_hourly_round_trip(): void {
		$store = $this->make_store();
		$store->set_url_index_hourly( '2026-01-01-00-00', [ 'hash1' => [ 'url' => '/x', 'count' => 1 ] ] );
		$urls = $store->get_url_index_hourly( '2026-01-01-00-00' );
		$this->assertArrayHasKey( 'hash1', $urls );
	}

	public function test_set_and_get_dimensional_round_trip(): void {
		$store = $this->make_store();
		$store->set_dimensional( 'status', [ '2026-01-01-00-00' => [ '200' => [ 'c' => 5, 's' => 1.5, 'm' => 10 ] ] ] );
		$dim = $store->get_dimensional( 'status' );
		$this->assertSame( 5, $dim['2026-01-01-00-00']['200']['c'] );
	}

	public function test_set_and_get_dimensional_per_server_round_trip(): void {
		$store = $this->make_store();
		$store->set_dimensional( 'method', [ 'b' => [ 'POST' => [ 'c' => 1, 's' => 0, 'm' => 0 ] ] ], 'srv-a' );
		$dim = $store->get_dimensional( 'method', 'srv-a' );
		$this->assertArrayHasKey( 'b', $dim );
		// Different server: empty.
		$this->assertSame( [], $store->get_dimensional( 'method', 'srv-b' ) );
	}

	public function test_set_and_get_url_dimensional_round_trip(): void {
		$store = $this->make_store();
		$store->set_url_dimensional( 'urlhash', [ 'status' => [ 'b' => [ '200' => [ 'c' => 3, 's' => 0, 'm' => 0 ] ] ] ] );
		$dim = $store->get_url_dimensional( 'urlhash' );
		$this->assertSame( 3, $dim['status']['b']['200']['c'] );
	}

	public function test_set_and_get_categories_round_trip(): void {
		$store = $this->make_store();
		$store->set_categories( [ '2026-01-01-00-00' => [ 'wpdb' => [ 't' => 0.5, 'c' => 10, 'n' => 1 ] ] ] );
		$cats = $store->get_categories();
		$this->assertSame( 10, $cats['2026-01-01-00-00']['wpdb']['c'] );
	}

	public function test_set_and_get_server_categories_round_trip(): void {
		$store = $this->make_store();
		$store->set_server_categories( 'srv-x', [ 'b' => [ 'wpdb' => [ 't' => 1, 'c' => 1, 'n' => 1 ] ] ] );
		$cats = $store->get_server_categories( 'srv-x' );
		$this->assertArrayHasKey( 'b', $cats );
		// Different server: empty.
		$this->assertSame( [], $store->get_server_categories( 'srv-y' ) );
	}

	public function test_set_and_get_url_categories_round_trip(): void {
		$store = $this->make_store();
		$store->set_url_categories( 'urlhash', [ 'b' => [ 'wpdb' => [ 't' => 1, 'c' => 1, 'n' => 1 ] ] ] );
		$cats = $store->get_url_categories( 'urlhash' );
		$this->assertArrayHasKey( 'b', $cats );
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

		$data = [ '2026-01-01-00' => [ 'count' => 5 ] ];
		$store->set_hourly( $data );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'evlog:p0:hourly', $captured[0][0] );
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
		$this->assertSame( 'evlog:p0:url:abc', $captured[0][0] );
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

		$store->set_hourly( [ 'x' => [ 'count' => 1 ] ] );
		$store->set_url_index_hourly( 'b', [ 'x' => [ 'url' => '/x' ] ] );
		$store->set_url_stats( 'h', [ 'flame' => [ 'count' => 1 ] ] );
		$store->set_leaderboard_bucket( 'b', [ 'count' => 1 ] );
		$store->set_server_leaderboard_bucket( 'srv', 'b', [ 'count' => 1 ] );
		$store->set_dimensional( 'status', [ '200' => [ 'c' => 1 ] ] );
		$store->set_url_dimensional( 'h', [ 'status' => [ '200' => [ 'c' => 1 ] ] ] );
		$store->set_categories( [ 'total' => [ 'n' => 1 ] ] );
		$store->set_server_categories( 'srv', [ 'total' => [ 'n' => 1 ] ] );
		$store->set_url_categories( 'h', [ 'total' => [ 'n' => 1 ] ] );

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
		$this->assertTrue( $store->set_hourly( [ 'x' => [ 'count' => 1 ] ] ) );
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
		$this->assertFalse( $store->set_hourly( [ 'x' => [ 'count' => 1 ] ] ) );
		$this->assertFalse( $invoked, 'mirror must not fire when the memcache set failed' );
	}

	public function test_restore_writes_into_memcache_under_positive_ttl(): void {
		$store = $this->make_store();
		$data  = [ '2026-01-01-00' => [ 'count' => 9 ] ];
		$this->assertTrue( $store->restore( 'evlog:p0:hourly', $data, 100 ) );
		$this->assertSame( $data, $store->get_hourly() );
	}

	public function test_restore_returns_false_for_non_positive_ttl(): void {
		$store = $this->make_store();
		$this->assertFalse( $store->restore( 'evlog:p0:hourly', [ 'x' => 1 ], 0 ) );
		$this->assertSame( [], $store->get_hourly() );
	}

	public function test_restore_returns_false_for_foreign_prefix(): void {
		$store = $this->make_store();
		$this->assertFalse( $store->restore( 'other:p0:hourly', [ 'x' => 1 ], 100 ) );
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

}
