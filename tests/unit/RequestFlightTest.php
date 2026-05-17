<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\RequestFlight;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Callback;
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
#[CoversClass( RequestFlight::class )]
class RequestFlightTest extends TestCase {

	/**
	 * Anonymous patron exposing inflight_snapshot() — what Task 22 will add
	 * to RequestBuilder. Lets these unit tests assert RequestFlight's wire
	 * contract without depending on the eventual patron implementation.
	 */
	private function patron_with_snapshot( array $snapshot ): \Newspack_Nodes\Node {
		return new class( $snapshot ) extends \Newspack_Nodes\Node {
			public function __construct( private array $snapshot ) {}
			public function inflight_snapshot(): array {
				return $this->snapshot;
			}
		};
	}

	/**
	 * Sink that records every fill() into the by-ref array.
	 *
	 * @param array<int,array> $sink Captured messages, appended to in order.
	 */
	private function capture_sink( array &$sink ): Callback {
		return new Callback( static function ( array &$m ) use ( &$sink ): void {
			$sink[] = $m;
		} );
	}

	public function test_fire_emits_inflight_batch_through_sink_chain(): void {
		// The full integration test requires RequestBuilder::inflight_snapshot()
		// + prime_inflight_for_testing(), both added in Task 22. Until then
		// the RequestFlight unit covers configuration; Task 22 will remove
		// this skip and re-enable the end-to-end assertion below.
		$this->markTestIncomplete( 'requires Task 22 patron methods (inflight_snapshot + prime_inflight_for_testing)' );
	}

	public function test_patron_pointer_round_trips(): void {
		// The patron pointer is what the substrate's dump_metadata reads
		// to hide RequestFlight from the topology canvas — round-tripping
		// it is the test for "hidden via patron".
		$patron = new RequestBuilder();
		$patron->name( 'rb' );

		$flight = new RequestFlight();
		$flight->patron( $patron );
		$flight->name( 'rb:flight' );

		$this->assertSame( $patron, $flight->patron() );
	}

	public function test_set_interval_reschedules(): void {
		$flight = new RequestFlight();
		$flight->set_interval( 500 );
		$this->assertSame( 500, $flight->interval() );
	}

	public function test_default_interval(): void {
		// Default interval matches Perl InstrumentalityFlight's 1000ms.
		$flight = new RequestFlight();
		$this->assertSame( 1000, $flight->interval() );
	}

	public function test_fire_without_sink_is_safe(): void {
		// In production RequestBuilder propagates its sink to Flight via
		// an overridden sink() setter (Task 22). Before that wire-up, the
		// timer can fire — must not throw.
		$flight = new RequestFlight();
		$flight->name( 'flight-no-sink' );
		$flight->target( 'gyroscope_partition' );
		// No patron, no sink — just confirm fire_cb() doesn't throw.
		$flight->fire_cb();
		$this->assertTrue( true );
	}

	public function test_fire_without_patron_is_safe(): void {
		// Sink wired but no patron attached — emit nothing, do not throw.
		$got    = [];
		$flight = new RequestFlight();
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
		$got    = [];
		$patron = $this->patron_with_snapshot( [ 'rid-1' => [ 'state' => 'active' ] ] );
		$patron->name( 'patron-with-snap' );

		$flight = new RequestFlight();
		$flight->name( 'flight-no-target' );
		$flight->patron( $patron );
		$flight->sink( $this->capture_sink( $got ) );
		$flight->fire_cb();

		$this->assertSame( [], $got );
	}

	public function test_fire_with_empty_snapshot_emits_nothing(): void {
		// Patron + sink + target wired but snapshot is empty — no batch.
		$got    = [];
		$patron = $this->patron_with_snapshot( [] );
		$patron->name( 'patron-empty-snap' );

		$flight = new RequestFlight();
		$flight->name( 'flight-empty-snap' );
		$flight->patron( $patron );
		$flight->sink( $this->capture_sink( $got ) );
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$this->assertSame( [], $got );
	}

	public function test_fire_emits_batch_to_target_when_all_wired(): void {
		// Asserts the wire-level message shape RequestFlight emits, using
		// an anonymous patron in place of the eventual RequestBuilder
		// inflight_snapshot() Task 22 will add.
		$got    = [];
		$batch  = [
			'rid-1' => [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0, 'state' => 'active' ],
			'rid-2' => [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0, 'state' => 'active' ],
		];
		$patron = $this->patron_with_snapshot( $batch );
		$patron->name( 'patron-with-batch' );

		$flight = new RequestFlight();
		$flight->name( 'flight-emits' );
		$flight->patron( $patron );
		$flight->sink( $this->capture_sink( $got ) );
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$this->assertCount( 1, $got );
		$this->assertSame( Message::TM_STRUCT, $got[0][ Message::TYPE ] );
		$this->assertSame( 'gyroscope_partition', $got[0][ Message::TO ] );
		$this->assertSame( 'inflight', $got[0][ Message::KEY ] );
		$this->assertSame( 'flight-emits', $got[0][ Message::FROM ] );
		$this->assertSame( $batch, $got[0][ Message::VALUE ] );
	}
}
