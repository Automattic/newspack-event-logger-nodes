<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Request_Flight_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * RequestFlight is a hidden Timer-sibling Node attached to RequestBuilder.
 * Mirrors Perl Tachikoma's InstrumentalityFlight.pm: periodically snapshots
 * the patron's in-progress request map and emits a TM_STRUCT batch to a
 * configured target node (typically a gyroscope partition).
 *
 * Hidden from the topology canvas via the substrate's patron filter
 * (`Node::patron()` non-null → dump_metadata skips it).
 */
#[CoversClass( Request_Flight_Node::class )]
class RequestFlightTest extends TestCase {
	/**
	 * Sink that records every fill() into the by-ref array.
	 *
	 * @param array<int,array> $sink Captured messages, appended to in order.
	 */
	private function capture_sink( array &$sink ): Callback_Node {
		return new Callback_Node( static function ( array &$m ) use ( &$sink ): void {
			$sink[] = $m;
		} );
	}

	public function test_fire_emits_inflight_batch_through_sink_chain(): void {
		// End-to-end: RequestBuilder auto-attaches Flight in its ctor (Task 22),
		// the overridden sink() setter propagates the sink down, and Flight's
		// fire_cb() calls inflight_snapshot() and emits the batch.
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-flight-e2e' );

		$got = [];
		$rb->sink( $this->capture_sink( $got ) );

		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0 ] );
		$rb->cache->set( 'r-2', (object) [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0 ] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$this->assertCount( 1, $got );
		$this->assertSame( Message::TM_STRUCT, $got[0][ Message::TYPE ] );
		$this->assertSame( 'gyroscope_partition', $got[0][ Message::TO ] );
		$this->assertSame( 'inflight', $got[0][ Message::KEY ] );
		$this->assertSame( 'rb-flight-e2e:flight', $got[0][ Message::FROM ] );
		$batch = $got[0][ Message::VALUE ];
		$this->assertCount( 2, $batch );
		$this->assertSame( 'r-1', $batch[0]['rid'] );
		// Default state for a primed request with no stack frames matches
		// legacy InflightTracker (lines 141-143): the unwound-stack default.
		$this->assertSame( 'process', $batch[0]['state'] );
		$this->assertSame( '/a', $batch[0]['url'] );
		$this->assertSame( 'r-2', $batch[1]['rid'] );
	}

	public function test_patron_pointer_round_trips(): void {
		// The patron pointer is what the substrate's dump_metadata reads
		// to hide RequestFlight from the topology canvas — round-tripping
		// it is the test for "hidden via patron". Task 22 wires this
		// automatically inside RequestBuilder's ctor; assert against the
		// auto-attached sibling rather than constructing a parallel one
		// (which would collide on the `:flight` registered node name).
		$patron = new Request_Builder_Node();
		$patron->name( 'rb-patron' );

		$flight = $patron->flight();
		$this->assertSame( $patron, $flight->patron() );
		$this->assertSame( 'rb-patron:flight', $flight->name() );
	}

	public function test_set_interval_reschedules(): void {
		$flight = new Request_Flight_Node();
		$flight->set_interval( 500 );
		$this->assertSame( 500, $flight->interval() );
	}

	public function test_default_interval(): void {
		// Default interval matches Perl InstrumentalityFlight's 1000ms.
		$flight = new Request_Flight_Node();
		$this->assertSame( 1000, $flight->interval() );
	}

	public function test_fire_without_sink_is_safe(): void {
		// In production RequestBuilder propagates its sink to Flight via
		// an overridden sink() setter (Task 22). Before that wire-up, the
		// timer can fire — must not throw.
		$flight = new Request_Flight_Node();
		$flight->name( 'flight-no-sink' );
		$flight->target( 'gyroscope_partition' );
		// No patron, no sink — just confirm fire_cb() doesn't throw.
		$flight->fire_cb();
		$this->assertTrue( true );
	}

	public function test_fire_without_patron_is_safe(): void {
		// Sink wired but no patron attached — emit nothing, do not throw.
		$got    = [];
		$flight = new Request_Flight_Node();
		$flight->name( 'flight-no-patron' );
		$flight->sink( $this->capture_sink( $got ) );
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();
		$this->assertSame( [], $got );
	}

	public function test_fire_without_target_emits_nothing(): void {
		// Patron + sink wired but no target configured (set_inflight_target
		// not yet invoked) — emit nothing. Patron has a non-empty snapshot
		// to confirm the target gate kicks in BEFORE any sink dispatch.
		$rb = new Request_Builder_Node();
		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0 ] );
		$rb->cache->set( 'r-2', (object) [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0 ] );

		$got = [];
		$rb->name( 'patron-with-snap' );
		$rb->sink( $this->capture_sink( $got ) );

		$flight = $rb->flight();
		$flight->name( 'flight-no-target' );
		$flight->fire_cb();

		$this->assertSame( [], $got );
	}

	public function test_fire_with_empty_snapshot_emits_nothing(): void {
		// Patron + sink + target wired but snapshot is empty — no batch.
		$rb = new Request_Builder_Node();

		$got = [];
		$rb->name( 'patron-empty-snap' );
		$rb->sink( $this->capture_sink( $got ) );

		$flight = $rb->flight();
		$flight->name( 'flight-empty-snap' );
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$this->assertSame( [], $got );
	}

	public function test_fire_emits_batch_to_target_when_all_wired(): void {
		// Asserts the wire-level message shape RequestFlight emits
		$rb = new Request_Builder_Node();
        $batch = [
            'r-1' => [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0 ],
            'r-2' => [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0 ]
        ];
		$rb->cache->set( 'r-1', (object) $batch['r-1'] );
		$rb->cache->set( 'r-2', (object) $batch['r-2'] );

		$got = [];
		$rb->name( 'patron-with-batch' );
		$rb->sink( $this->capture_sink( $got ) );

		$flight = $rb->flight();
		$flight->name( 'flight-emits' );
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$this->assertCount( 1, $got );
		$this->assertSame( Message::TM_STRUCT, $got[0][ Message::TYPE ] );
		$this->assertSame( 'gyroscope_partition', $got[0][ Message::TO ] );
		$this->assertSame( 'inflight', $got[0][ Message::KEY ] );
		$this->assertSame( 'flight-emits', $got[0][ Message::FROM ] );
		$this->assertSame( $batch['r-1']['url'],            $got[0][ Message::VALUE ][0]['url'] );
		$this->assertSame( $batch['r-1']['request_method'], $got[0][ Message::VALUE ][0]['method'] );
		$this->assertSame( $batch['r-1']['timestamp'],      $got[0][ Message::VALUE ][0]['start_time'] );
		$this->assertSame( $batch['r-2']['url'],            $got[0][ Message::VALUE ][1]['url'] );
		$this->assertSame( $batch['r-2']['request_method'], $got[0][ Message::VALUE ][1]['method'] );
		$this->assertSame( $batch['r-2']['timestamp'],      $got[0][ Message::VALUE ][1]['start_time'] );
	}
}
