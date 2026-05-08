<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\StatsAggregator;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StatsAggregator::class )]
class StatsAggregatorTest extends TestCase {

	private function make_msg( array $payload ): array {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ]     = \json_encode( $payload );
		return $msg;
	}

	/** Wrapper to call fill() with a temporary so we don't trigger
	 * "Only variables should be passed by reference". */
	private function fill( StatsAggregator $sa, array $payload ): void {
		$msg = $this->make_msg( $payload );
		$sa->fill( $msg );
	}

	private function make_store_aggregator( ?FakeMemcached $mc = null ): array {
		$mc        = $mc ?? new FakeMemcached();
		$store     = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$sa        = new StatsAggregator( $store );
		return [ $store, $sa, $mc ];
	}

	public function test_constructor_accepts_stats_store(): void {
		[ , $sa ] = $this->make_store_aggregator();
		$this->assertSame( 0, $sa->url_count() );
	}

	public function test_constructor_works_without_store_for_legacy_in_memory_mode(): void {
		$sa = new StatsAggregator();
		$this->assertSame( 0, $sa->url_count() );

		// Legacy in-memory aggregation still works (no store given).
		$msg1 = $this->make_msg( [ 'url' => '/x', 'req_time' => 0.5 ] );
		$msg2 = $this->make_msg( [ 'url' => '/x', 'req_time' => 1.5 ] );
		$sa->fill( $msg1 );
		$sa->fill( $msg2 );
		$stats = $sa->flush();
		$this->assertSame( 2, $stats['/x']['count'] );
		$this->assertSame( 2.0, $stats['/x']['sum_req_time'] );
	}

	public function test_fill_pushes_url_counter_to_store(): void {
		[ $store, $sa ] = $this->make_store_aggregator();

		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 0.7 ] );

		$bucket = $store->current_url_bucket();
		$stats  = $store->get_url_bucket( $bucket );
		$this->assertSame( 1, $stats['/x']['count'] );
		$this->assertEqualsWithDelta( 0.7, $stats['/x']['sum_req_time'], 1e-9 );
	}

	public function test_fill_pushes_leaderboard_counter_to_store(): void {
		[ $store, $sa ] = $this->make_store_aggregator();

		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 0.7 ] );
		$this->fill( $sa, [ 'url' => '/y', 'req_time' => 0.3 ] );

		$bucket = $store->current_url_bucket();
		$lb     = $store->get_leaderboard_bucket( $bucket );
		$this->assertSame( 2, $lb['count'] );
		$this->assertEqualsWithDelta( 1.0, $lb['sum_req_time'], 1e-9 );
	}

	public function test_fill_pushes_hourly_counter_to_store(): void {
		[ $store, $sa ] = $this->make_store_aggregator();

		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 0.7, 'peak_mb' => 12.0 ] );

		$hourly = $store->get_hourly();
		$bucket = $store->current_hour_bucket();
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 700.0, $hourly[ $bucket ]['sum_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 12.0, $hourly[ $bucket ]['sum_peak_mb'], 1e-9 );
	}

	public function test_fill_with_categories_pushes_to_leaderboard(): void {
		[ $store, $sa ] = $this->make_store_aggregator();

		$this->fill(
			$sa,
			[
				'url'        => '/x',
				'req_time'   => 1.0,
				'categories' => [
					'wpdb' => [
						'time'    => 0.4,
						'count'   => 12,
						'entries' => [ 'SELECT' => [ 0.3, 8 ] ],
					],
				],
			]
		);

		$bucket = $store->current_url_bucket();
		$lb     = $store->get_leaderboard_bucket( $bucket );
		$this->assertSame( 1, $lb['categories']['wpdb']['samples'] );
		$this->assertEqualsWithDelta( 0.4, $lb['categories']['wpdb']['sum_time'], 1e-9 );
	}

	public function test_fill_skips_non_bytestream(): void {
		[ , $sa, $mc ] = $this->make_store_aggregator();

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_INFO;
		$msg[ Message::VALUE ]     = \json_encode( [ 'url' => '/x', 'req_time' => 0.5 ] );
		$sa->fill( $msg );

		$this->assertSame( 0, $mc->count() );
	}

	public function test_fill_skips_invalid_payload(): void {
		[ , $sa, $mc ] = $this->make_store_aggregator();

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ]     = 'not json';
		$sa->fill( $msg );

		$msg2                       = Message::new_message();
		$msg2[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg2[ Message::VALUE ]     = \json_encode( [ 'no_url' => true ] );
		$sa->fill( $msg2 );

		$this->assertSame( 0, $mc->count() );
	}

	public function test_fill_with_status_pushes_dimensional(): void {
		[ $store, $sa ] = $this->make_store_aggregator();

		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 0.5, 'status' => 200 ] );
		$this->fill( $sa, [ 'url' => '/y', 'req_time' => 0.6, 'status' => 500 ] );

		$dim    = $store->get_dimensional( 'status' );
		$bucket = $store->current_url_bucket();
		$this->assertSame( 1, $dim[ $bucket ]['200']['c'] );
		$this->assertSame( 1, $dim[ $bucket ]['500']['c'] );
	}

	public function test_fill_pushes_per_server_leaderboard_when_server_present(): void {
		[ $store, $sa ] = $this->make_store_aggregator();

		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 1.0, 'server' => 'srv-a' ] );

		$bucket = $store->current_url_bucket();
		$lb     = $store->get_server_leaderboard_bucket( 'srv-a', $bucket );
		$this->assertSame( 1, $lb['count'] );
	}

	public function test_aggregates_per_url_counter_legacy(): void {
		// Backwards compat: the existing test must still pass with no Stats_Store.
		$sa = new StatsAggregator();
		$msg1 = $this->make_msg( [ 'url' => '/x', 'req_time' => 0.5 ] );
		$msg2 = $this->make_msg( [ 'url' => '/x', 'req_time' => 1.5 ] );
		$sa->fill( $msg1 );
		$sa->fill( $msg2 );

		$stats = $sa->flush();
		$this->assertArrayHasKey( '/x', $stats );
		$this->assertSame( 2, $stats['/x']['count'] );
		$this->assertSame( 2.0, $stats['/x']['sum_req_time'] );
	}

	public function test_separate_urls_tracked_separately_legacy(): void {
		$sa = new StatsAggregator();
		foreach ( [ '/a', '/b', '/a', '/c' ] as $url ) {
			$this->fill( $sa, [ 'url' => $url, 'req_time' => 1.0 ] );
		}
		$stats = $sa->flush();
		$this->assertSame( 3, \count( $stats ) );
		$this->assertSame( 2, $stats['/a']['count'] );
		$this->assertSame( 1, $stats['/b']['count'] );
	}

	public function test_flush_clears_state_legacy(): void {
		$sa = new StatsAggregator();
		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 1.0 ] );
		$sa->flush();
		$this->assertSame( 0, $sa->url_count() );
	}

	public function test_fail_soft_does_not_throw(): void {
		$mc    = new FakeMemcached( fail_all: true );
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$sa    = new StatsAggregator( $store );

		// Must not throw despite memcache being down.
		$this->fill( $sa, [ 'url' => '/x', 'req_time' => 0.5, 'status' => 200 ] );
		$this->assertSame( 1, $sa->counter() );
	}
}
