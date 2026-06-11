<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Request_Flight_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
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
	 * Flight registers its snapshot hitchhike when a target is set, which needs a
	 * live _router (as in a real worker). Provide one; Core::reset() in the parent
	 * setUp clears it between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
	}

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

	public function test_setting_target_enables_snapshot_hitchhike(): void {
		// Setting the inflight target IS what enables snapshots: it registers Flight
		// on the Router's TIMER (no-arg set_timer hitchhike). A Router tick then
		// drives fire() -> snapshot emit. There is no separate interval verb.
		$router = Core::node( Node_Names::ROUTER );

		$rb = new Request_Builder_Node();
		$rb->name( 'rb-en' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET', 'timestamp' => 1.0 ] );

		$rb->flight()->target( 'gyroscope_partition' );

		$router->fire_cb();

		$emitted = \array_values(
			\array_filter( $got, static fn( $m ) => 'inflight' === ( $m[ Message::KEY ] ?? '' ) )
		);
		$this->assertCount( 1, $emitted, 'setting the target drove a Router-tick snapshot emit' );
		$this->assertSame( 'gyroscope_partition', $emitted[0][ Message::TO ] );
	}

	public function test_clearing_target_stops_snapshot_hitchhike(): void {
		// Clearing the target disables snapshots (stop_timer / unregister), so a
		// later Router tick emits nothing.
		$router = Core::node( Node_Names::ROUTER );

		$rb = new Request_Builder_Node();
		$rb->name( 'rb-dis' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET', 'timestamp' => 1.0 ] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->target( '' );

		$router->fire_cb();

		$emitted = \array_filter( $got, static fn( $m ) => 'inflight' === ( $m[ Message::KEY ] ?? '' ) );
		$this->assertSame( [], $emitted, 'cleared target must stop the snapshot hitchhike' );
	}
}
