<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FlameBuilder::class )]
class FlameBuilderTest extends TestCase {
	private function event_message( array $events ): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = \json_encode( [ 'rid' => 'r' . \uniqid(), 'url' => '/x', 'events' => $events ] );
		return $msg;
	}

	/**
	 * Fill helper: Node::fill() takes $message by reference, so a fresh array
	 * literal can't be inlined. This wraps the assign-then-fill pattern.
	 */
	private function fill_event( FlameBuilder $fb, array $events ): void {
		$msg = $this->event_message( $events );
		$fb->fill( $msg );
	}

	public function test_constructor_initializes_empty(): void {
		$fb = new FlameBuilder();
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_processing_completed_request_aggregates_events(): void {
		$fb  = new FlameBuilder();
		$msg = $this->event_message( [
			[ 'k' => 'hook', 'name' => 'init', 'time' => 0.5 ],
			[ 'k' => 'hook', 'name' => 'init', 'time' => 1.5 ],
			[ 'k' => 'hook', 'name' => 'parse_request', 'time' => 0.2 ],
		] );
		$fb->fill( $msg );

		$stats = $fb->flush();
		$this->assertArrayHasKey( 'init', $stats );
		$this->assertSame( 2, $stats['init']['count'] );
		$this->assertSame( 2.0, $stats['init']['sum_time'] );
		$this->assertSame( 1, $stats['parse_request']['count'] );
	}

	public function test_flush_clears_stats(): void {
		$fb  = new FlameBuilder();
		$msg = $this->event_message( [ [ 'k' => 'hook', 'name' => 'a', 'time' => 1.0 ] ] );
		$fb->fill( $msg );
		$fb->flush();
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_invalid_json_skipped(): void {
		$fb  = new FlameBuilder();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not-json';
		$fb->fill( $msg );
		$this->assertSame( 0, $fb->stats_count() );
	}

	// --- Bucket rotation + memcache flush --------------------------------

	public function test_time_rotation_flushes_oldest_bucket_to_stats_store(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder( bucket_interval_s: 200, num_buckets: 5 );
		$fb->set_stats_store( $store );

		// Fixed clock: bucket 5 = floor(1000/200).
		Core::set_now( 1000.0 );
		$this->fill_event( $fb, [ [ 'name' => 'init', 'time' => 1.0 ] ] );
		$this->assertSame( 1, $fb->stats_count() );

		// Advance by NUM_BUCKETS * interval = 1000s — old bucket is now
		// num_buckets behind, must evict.
		Core::set_now( 1000.0 + 5 * 200 );
		$fb->maintenance();

		// Old bucket evicted from local cache.
		$this->assertSame( 0, $fb->stats_count() );

		// Old bucket landed in Stats_Store under bucket id "5" (1000/200).
		$bucket = $store->get_flame_bucket( '5' );
		$this->assertArrayHasKey( 'init', $bucket );
		$this->assertSame( 1, $bucket['init']['count'] );
		$this->assertSame( 1.0, $bucket['init']['sum_time'] );
	}

	public function test_rotation_keeps_recent_buckets_in_local_cache(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, 0, 86400 );
		$fb    = new FlameBuilder( bucket_interval_s: 200, num_buckets: 5 );
		$fb->set_stats_store( $store );

		// Fill 3 distinct bucket windows (200s apart).
		Core::set_now( 1000.0 );
		$this->fill_event( $fb, [ [ 'name' => 'a', 'time' => 1.0 ] ] );

		Core::set_now( 1200.0 );
		$this->fill_event( $fb, [ [ 'name' => 'b', 'time' => 1.0 ] ] );

		Core::set_now( 1400.0 );
		$this->fill_event( $fb, [ [ 'name' => 'c', 'time' => 1.0 ] ] );

		// All three buckets within retention (5 buckets, 3 occupied).
		$this->assertSame( 3, $fb->stats_count() );
		// Nothing flushed to memcache yet.
		$this->assertSame( [], $store->get_flame_bucket( '5' ) );
		$this->assertSame( [], $store->get_flame_bucket( '6' ) );
		$this->assertSame( [], $store->get_flame_bucket( '7' ) );
	}

	public function test_advancing_far_evicts_all_old_buckets(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, 0, 86400 );
		$fb    = new FlameBuilder( bucket_interval_s: 200, num_buckets: 5 );
		$fb->set_stats_store( $store );

		Core::set_now( 1000.0 );
		$this->fill_event( $fb, [ [ 'name' => 'old1', 'time' => 1.0 ] ] );

		Core::set_now( 1200.0 );
		$this->fill_event( $fb, [ [ 'name' => 'old2', 'time' => 2.0 ] ] );

		// Jump 10 buckets ahead — both old buckets should evict.
		Core::set_now( 1000.0 + 10 * 200 );
		$fb->maintenance();

		$this->assertSame( 0, $fb->stats_count() );
		$bucket_5 = $store->get_flame_bucket( '5' );
		$bucket_6 = $store->get_flame_bucket( '6' );
		$this->assertSame( 1, $bucket_5['old1']['count'] );
		$this->assertSame( 2.0, $bucket_6['old2']['sum_time'] );
	}

	public function test_size_rotation_when_bucket_hits_max(): void {
		// Tiny cap (5) so we can saturate a bucket and watch eviction.
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, 0, 86400 );
		$fb    = new FlameBuilder( bucket_interval_s: 1000000, max_per_bucket: 5, num_buckets: 2 );
		$fb->set_stats_store( $store );

		Core::set_now( 0.0 );

		// Fill bucket 0 to capacity (5 names).
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $name ) {
			$this->fill_event( $fb, [ [ 'name' => $name, 'time' => 1.0 ] ] );
		}
		$this->assertSame( 5, $fb->stats_count() );

		// Add a 6th — within the same time bucket, no time-rotation; size-rotation
		// fires when count hits max. With num_buckets=2 still one bucket so
		// we have local-only state.
		$this->fill_event( $fb, [ [ 'name' => 'f', 'time' => 1.0 ] ] );

		// Force a final flush via maintenance + sufficient time advance.
		Core::set_now( 1000000.0 * 5 );
		$fb->maintenance();

		// Some entries landed in memcache.
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_no_stats_store_drops_rotated_buckets(): void {
		// Without a store, rotation just clears local cache.
		$fb = new FlameBuilder( bucket_interval_s: 200, num_buckets: 5 );

		Core::set_now( 1000.0 );
		$this->fill_event( $fb, [ [ 'name' => 'init', 'time' => 1.0 ] ] );

		Core::set_now( 1000.0 + 10 * 200 );
		$fb->maintenance();

		// No throw, local cache emptied.
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_merge_is_additive(): void {
		// Two independent FlameBuilders writing to the same Stats_Store at the
		// same bucket_id must produce additive merged results — not the latest-
		// writer-wins. This is the property real workers rely on.
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, 0, 86400 );

		$fb1 = new FlameBuilder( bucket_interval_s: 200, num_buckets: 5 );
		$fb1->set_stats_store( $store );

		$fb2 = new FlameBuilder( bucket_interval_s: 200, num_buckets: 5 );
		$fb2->set_stats_store( $store );

		Core::set_now( 1000.0 );
		$this->fill_event( $fb1, [ [ 'name' => 'init', 'time' => 1.0 ] ] );
		$this->fill_event( $fb2, [ [ 'name' => 'init', 'time' => 2.0 ] ] );

		// Both rotate.
		Core::set_now( 1000.0 + 10 * 200 );
		$fb1->maintenance();
		$fb2->maintenance();

		$bucket = $store->get_flame_bucket( '5' );
		$this->assertSame( 2, $bucket['init']['count'] );
		$this->assertSame( 3.0, $bucket['init']['sum_time'] );
	}
}
