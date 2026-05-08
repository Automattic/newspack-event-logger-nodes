<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Stats_Store::class )]
class StatsStoreTest extends TestCase {

	private function make_store( ?FakeMemcached $mc = null, int $partition = 0, int $max_lifespan = 86400 ): Stats_Store {
		$mc ??= new FakeMemcached();
		return new Stats_Store( $mc, partition: $partition, max_lifespan: $max_lifespan );
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

	public function test_bump_url_aggregates_count_and_sum_req_time(): void {
		$mc    = new FakeMemcached();
		$store = $this->make_store( $mc );
		$store->bump_url( '/x', 0.5 );
		$store->bump_url( '/x', 1.5 );
		$bucket = $store->current_url_bucket();
		$stats  = $store->get_url_bucket( $bucket );
		$this->assertSame( 2, $stats['/x']['count'] );
		$this->assertSame( 2.0, $stats['/x']['sum_req_time'] );
		$this->assertSame( 2, $stats['/x']['samples'] );
	}

	public function test_bump_url_for_distinct_urls_keeps_them_separate(): void {
		$store = $this->make_store();
		$store->bump_url( '/a', 0.1 );
		$store->bump_url( '/b', 0.2 );
		$store->bump_url( '/a', 0.3 );
		$bucket = $store->current_url_bucket();
		$stats  = $store->get_url_bucket( $bucket );
		$this->assertSame( 2, $stats['/a']['count'] );
		$this->assertSame( 1, $stats['/b']['count'] );
		$this->assertEqualsWithDelta( 0.4, $stats['/a']['sum_req_time'], 1e-9 );
	}

	public function test_get_url_bucket_returns_empty_when_missing(): void {
		$store = $this->make_store();
		$this->assertSame( [], $store->get_url_bucket( '2020-01-01-00-00' ) );
	}

	public function test_bump_leaderboard_aggregates_count_and_sum_req_time(): void {
		$store = $this->make_store();
		$store->bump_leaderboard( 1.0 );
		$store->bump_leaderboard( 0.5 );
		$bucket = $store->current_url_bucket();
		$lb     = $store->get_leaderboard_bucket( $bucket );
		$this->assertSame( 2, $lb['count'] );
		$this->assertEqualsWithDelta( 1.5, $lb['sum_req_time'], 1e-9 );
	}

	public function test_bump_leaderboard_with_categories_writes_sums(): void {
		$store = $this->make_store();
		$store->bump_leaderboard(
			1.0,
			[
				'wpdb'  => [ 'time' => 0.4, 'count' => 12, 'entries' => [ 'SELECT' => [ 0.3, 8 ] ] ],
				'hooks' => [ 'time' => 0.1, 'count' => 50, 'entries' => [] ],
			]
		);
		$bucket = $store->current_url_bucket();
		$lb     = $store->get_leaderboard_bucket( $bucket );
		$this->assertSame( 1, $lb['count'] );
		$this->assertSame( 1, $lb['categories']['wpdb']['samples'] );
		$this->assertEqualsWithDelta( 0.4, $lb['categories']['wpdb']['sum_time'], 1e-9 );
		$this->assertEqualsWithDelta( 12.0, $lb['categories']['wpdb']['sum_count'], 1e-9 );
		// entries[name] = [sum_time, sum_count, samples]
		$this->assertEqualsWithDelta( 0.3, $lb['categories']['wpdb']['entries']['SELECT'][0], 1e-9 );
		$this->assertEqualsWithDelta( 8.0, $lb['categories']['wpdb']['entries']['SELECT'][1], 1e-9 );
		$this->assertSame( 1, $lb['categories']['wpdb']['entries']['SELECT'][2] );
	}

	public function test_bump_per_server_leaderboard_isolates_servers(): void {
		$store = $this->make_store();
		$store->bump_server_leaderboard( 'srv-a', 1.0 );
		$store->bump_server_leaderboard( 'srv-b', 2.0 );
		$bucket = $store->current_url_bucket();
		$a      = $store->get_server_leaderboard_bucket( 'srv-a', $bucket );
		$b      = $store->get_server_leaderboard_bucket( 'srv-b', $bucket );
		$this->assertSame( 1, $a['count'] );
		$this->assertSame( 1, $b['count'] );
		$this->assertEqualsWithDelta( 1.0, $a['sum_req_time'], 1e-9 );
		$this->assertEqualsWithDelta( 2.0, $b['sum_req_time'], 1e-9 );
	}

	public function test_bump_dimensional_aggregates_per_value(): void {
		$store = $this->make_store();
		$store->bump_dimensional( 'status', '200', 0.5 );
		$store->bump_dimensional( 'status', '200', 0.7 );
		$store->bump_dimensional( 'status', '500', 0.1 );
		$dim = $store->get_dimensional( 'status' );
		// Dim is bucketed: { bucket => { value => { c, s, m } } }
		$bucket = $store->current_url_bucket();
		$this->assertSame( 2, $dim[ $bucket ]['200']['c'] );
		$this->assertSame( 1, $dim[ $bucket ]['500']['c'] );
		$this->assertEqualsWithDelta( 1.2, $dim[ $bucket ]['200']['s'], 1e-9 );
	}

	public function test_dimensional_overflow_rolls_into_other(): void {
		$store = $this->make_store();
		// 25 distinct values — exceeds MAX_DIM_VALUES (20).
		for ( $i = 0; $i < 25; $i++ ) {
			$store->bump_dimensional( 'ua', "browser-$i", 0.1 );
		}
		$dim    = $store->get_dimensional( 'ua' );
		$bucket = $store->current_url_bucket();
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $dim[ $bucket ] ) );
		$this->assertArrayHasKey( 'Other', $dim[ $bucket ] );
	}

	public function test_url_dimensional_overflow_rolls_into_other(): void {
		$store = $this->make_store();
		// 15 distinct values for the same URL — exceeds MAX_URL_DIM_VALUES (10).
		for ( $i = 0; $i < 15; $i++ ) {
			$store->bump_url_dimensional( 'urlhash', 'method', "M-$i", 0.1 );
		}
		$all    = $store->get_url_dimensional( 'urlhash' );
		$bucket = $store->current_url_bucket();
		$this->assertLessThanOrEqual( Stats_Store::MAX_URL_DIM_VALUES, \count( $all['method'][ $bucket ] ) );
		$this->assertArrayHasKey( 'Other', $all['method'][ $bucket ] );
	}

	public function test_categories_overflow_rolls_into_other(): void {
		$store = $this->make_store();
		for ( $i = 0; $i < 60; $i++ ) {
			$store->bump_category( "cat-$i", 0.05, 1 );
		}
		$cats   = $store->get_categories();
		$bucket = $store->current_url_bucket();
		$this->assertLessThanOrEqual( Stats_Store::MAX_CAT_VALUES, \count( $cats[ $bucket ] ) );
		$this->assertArrayHasKey( 'Other', $cats[ $bucket ] );
	}

	public function test_categories_total_pseudo_category_exempt_from_cap(): void {
		// Spec: 'total' is the pseudo-category preserved before sorting — exempt from cap.
		// Bump 'total' first, then overflow with many distinct cats. 'total' must survive,
		// overflow rolls into 'Other'.
		$store = $this->make_store();
		$store->bump_category( 'total', 1.0, 5 );
		for ( $i = 0; $i < 60; $i++ ) {
			$store->bump_category( "cat-$i", 0.05, 1 );
		}
		$cats   = $store->get_categories();
		$bucket = $store->current_url_bucket();
		$this->assertArrayHasKey( 'total', $cats[ $bucket ], "'total' must be preserved across cap overflow" );
		$this->assertArrayHasKey( 'Other', $cats[ $bucket ], "Overflow must roll into 'Other'" );
		$this->assertEqualsWithDelta( 1.0, $cats[ $bucket ]['total']['t'], 1e-9 );
		$this->assertEqualsWithDelta( 5.0, $cats[ $bucket ]['total']['c'], 1e-9 );
	}

	public function test_categories_total_admitted_after_cap_reached(): void {
		// 'total' arriving AFTER the cap is full must still be admitted (not rolled into Other).
		$store = $this->make_store();
		for ( $i = 0; $i < 60; $i++ ) {
			$store->bump_category( "cat-$i", 0.05, 1 );
		}
		$store->bump_category( 'total', 2.5, 7 );
		$cats   = $store->get_categories();
		$bucket = $store->current_url_bucket();
		$this->assertArrayHasKey( 'total', $cats[ $bucket ], "'total' must be admitted even after cap is reached" );
		$this->assertEqualsWithDelta( 2.5, $cats[ $bucket ]['total']['t'], 1e-9 );
	}

	public function test_dimensional_total_pseudo_category_exempt_from_cap(): void {
		// 'total' must also survive the dim cap (MAX_DIM_VALUES=20).
		$store = $this->make_store();
		for ( $i = 0; $i < 25; $i++ ) {
			$store->bump_dimensional( 'ua', "browser-$i", 0.1 );
		}
		$store->bump_dimensional( 'ua', 'total', 3.0 );
		$dim    = $store->get_dimensional( 'ua' );
		$bucket = $store->current_url_bucket();
		$this->assertArrayHasKey( 'total', $dim[ $bucket ], "'total' must be admitted even after dim cap is reached" );
		$this->assertEqualsWithDelta( 3.0, $dim[ $bucket ]['total']['s'], 1e-9 );
	}

	public function test_bump_hourly_accumulates_count_and_time(): void {
		$store = $this->make_store();
		$store->bump_hourly( 0.5, 25.0 );
		$store->bump_hourly( 1.0, 50.0 );
		$hourly = $store->get_hourly();
		$bucket = $store->current_hour_bucket();
		$this->assertSame( 2, $hourly[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 1500.0, $hourly[ $bucket ]['sum_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 75.0, $hourly[ $bucket ]['sum_peak_mb'], 1e-9 );
	}

	public function test_set_and_get_url_stats_under_partition_namespace(): void {
		$store = $this->make_store( partition: 3 );
		$store->set_url_stats( 'urlhash-x', [ 'flame' => [ [ 'a', 0, 1 ] ] ] );
		$this->assertSame(
			[ 'flame' => [ [ 'a', 0, 1 ] ] ],
			$store->get_url_stats( 'urlhash-x' )
		);
	}

	public function test_keys_include_partition(): void {
		$mc       = new FakeMemcached();
		$store_p0 = $this->make_store( $mc, partition: 0 );
		$store_p1 = $this->make_store( $mc, partition: 1 );
		$store_p0->bump_url( '/x', 0.1 );
		$store_p1->bump_url( '/x', 0.2 );
		$keys = $mc->keys();
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
		$mc    = new FakeMemcached();
		$store = $this->make_store( $mc );
		$store->bump_url( '/x', 0.1 );
		$keys = $mc->keys();
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
		$mc    = new FakeMemcached();
		$store = $this->make_store( $mc );
		$store->bump_url( '/x', 1.0 );
		$old_keys = $mc->keys();
		$this->assertNotEmpty( $old_keys );

		$store->flush_all();
		$store->bump_url( '/y', 1.0 );
		$new_keys = $mc->keys();

		$this->assertNotEquals( $old_keys, $new_keys, 'salt rotation should change keys' );
		// The new bump should NOT find the old data because the prefix is different.
		$bucket = $store->current_url_bucket();
		$stats  = $store->get_url_bucket( $bucket );
		$this->assertArrayNotHasKey( '/x', $stats );
		$this->assertArrayHasKey( '/y', $stats );
	}

	public function test_fail_soft_get_returns_empty(): void {
		$mc    = new FakeMemcached( fail_all: true );
		$store = $this->make_store( $mc );
		$this->assertSame( [], $store->get_url_bucket( 'any' ) );
		$this->assertNull( $store->get_url_stats( 'any' ) );
		$this->assertSame( [], $store->get_hourly() );
		$this->assertSame( [], $store->get_dimensional( 'status' ) );
	}

	public function test_fail_soft_bump_swallows_failure(): void {
		$mc    = new FakeMemcached( fail_all: true );
		$store = $this->make_store( $mc );
		// Must not throw.
		$store->bump_url( '/x', 0.5 );
		$store->bump_leaderboard( 0.5 );
		$store->bump_dimensional( 'status', '200', 0.5 );
		$store->bump_category( 'wpdb', 0.5, 1 );
		$store->bump_hourly( 0.5, 10.0 );
		$this->assertSame( 0, $mc->count() );
	}

	public function test_get_multi_url_buckets_batches_lookups(): void {
		$mc    = new FakeMemcached();
		$store = $this->make_store( $mc );
		$store->bump_url( '/x', 0.5 );
		$store->bump_url( '/y', 0.5 );
		$bucket = $store->current_url_bucket();

		// get_url_buckets should accept a list and return a map.
		$results = $store->get_url_buckets( [ $bucket, 'nonexistent-bucket' ] );
		$this->assertArrayHasKey( $bucket, $results );
		$this->assertArrayHasKey( '/x', $results[ $bucket ] );
		$this->assertArrayHasKey( '/y', $results[ $bucket ] );
		// Missing bucket should not appear.
		$this->assertArrayNotHasKey( 'nonexistent-bucket', $results );
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

	public function test_cache_accessor_returns_underlying_cache(): void {
		$mc    = new FakeMemcached();
		$store = $this->make_store( $mc );
		$this->assertSame( $mc, $store->cache() );
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

	public function test_bucket_key_for_returns_5min_floor(): void {
		$store = $this->make_store();
		// gmmktime so we control the UTC clock the bucket_key_for derives from.
		$ts  = \gmmktime( 12, 34, 56, 5, 8, 2026 );
		$key = $store->bucket_key_for( $ts );
		// Format: Y-m-d-H-MM where MM is 5-min floor of 34 = 30.
		$this->assertSame( '2026-05-08-12-30', $key );
	}
}
