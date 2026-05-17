<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\RequestFlight;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Callback;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Task 22 surface area on RequestBuilder:
 *  - hidden RequestFlight sibling attached at construction
 *  - sink() override propagates to Flight
 *  - set_completed_target() + compact-summary secondary emit
 *  - inflight_snapshot() + prime_inflight_for_testing() seam
 *
 * Compact-summary schema mirrors the legacy
 * requests-stream-controller::transform_line output so the schema-parity
 * audit passes.
 */
#[CoversClass( RequestBuilder::class )]
class RequestBuilderCompactSummaryTest extends TestCase {

	/**
	 * Sink that records every fill() into the by-ref array.
	 */
	private function capture_sink( array &$sink ): Callback {
		return new Callback( static function ( array &$m ) use ( &$sink ): void {
			$sink[] = $m;
		} );
	}

	public function test_constructor_attaches_flight_sibling(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$flight = $rb->flight();
		$this->assertInstanceOf( RequestFlight::class, $flight );
		$this->assertSame( 'rb:flight', $flight->name() );
		$this->assertSame( $rb, $flight->patron() );
	}

	public function test_sink_setter_propagates_to_flight(): void {
		$rb   = new RequestBuilder();
		$rb->name( 'rb-sink-prop' );
		$captured = [];
		$sink     = $this->capture_sink( $captured );
		$rb->sink( $sink );
		$this->assertSame( $sink, $rb->flight()->sink() );
		$this->assertSame( $sink, $rb->sink() );
	}

	public function test_set_completed_target_dispatches_compact_summary_on_completion(): void {
		$rb       = new RequestBuilder();
		$rb->name( 'rb-completed-target' );
		$captured = [];
		$rb->sink( $this->capture_sink( $captured ) );
		$rb->set_completed_target( 'completed:tee' );

		$request = (object) [
			'rid'            => 'r-1',
			'url'            => '/path',
			'request_method' => 'GET',
			'timestamp'      => 1000.0,
			'duration_ms'    => 25,
			'status_code'    => 200,
			'error_status'   => '-',
			'remote_addr'    => '127.0.0.1',
			'user_agent'     => 'test',
		];
		$rb->emit_completed_for_testing( $request );

		// The compact emit should show up in addition to whatever the primary emit does.
		$compact = \array_values( \array_filter(
			$captured,
			static fn( $m ): bool => 'completed:tee' === $m[ Message::TO ]
		) );
		$this->assertCount( 1, $compact );
		$this->assertSame( Message::TM_STRUCT, $compact[0][ Message::TYPE ] );
		$summary = $compact[0][ Message::VALUE ];
		$this->assertSame( 'r-1', $summary['rid'] );
		$this->assertSame( 'GET', $summary['method'] );
		$this->assertSame( '/path', $summary['url'] );
		$this->assertSame( 200, $summary['status_code'] );
		$this->assertEqualsWithDelta( 25, $summary['duration_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 1000.0, $summary['start_time'], 1e-9 );
		$this->assertEqualsWithDelta( 1000.025, $summary['end_time'], 1e-9 );
		$this->assertSame( '-', $summary['error_status'] );
		$this->assertSame( '127.0.0.1', $summary['remote_addr'] );
		$this->assertSame( 'test', $summary['user_agent'] );
		$this->assertSame( 'complete', $summary['state'] );
	}

	public function test_compact_summary_clips_url_and_user_agent(): void {
		$rb       = new RequestBuilder();
		$rb->name( 'rb-clip' );
		$captured = [];
		$rb->sink( $this->capture_sink( $captured ) );
		$rb->set_completed_target( 'completed:tee' );

		$long_url = '/x?' . \str_repeat( 'a', 3000 );
		$long_ua  = \str_repeat( 'b', 600 );
		$rb->emit_completed_for_testing(
			(object) [
				'rid'            => 'r',
				'url'            => $long_url,
				'request_method' => 'GET',
				'timestamp'      => 0,
				'duration_ms'    => 0,
				'status_code'    => 0,
				'user_agent'     => $long_ua,
			]
		);
		$compact = \array_values( \array_filter(
			$captured,
			static fn( $m ): bool => 'completed:tee' === $m[ Message::TO ]
		) );
		$this->assertLessThanOrEqual( 2003, \strlen( $compact[0][ Message::VALUE ]['url'] ) );
		$this->assertLessThanOrEqual( 503, \strlen( $compact[0][ Message::VALUE ]['user_agent'] ) );
	}

	public function test_inflight_snapshot_returns_active_requests_in_compact_form(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb-snap' );
		$rb->prime_inflight_for_testing( [
			'r-1' => [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0 ],
			'r-2' => [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0 ],
		] );
		$snap = $rb->inflight_snapshot();
		$this->assertCount( 2, $snap );
		$this->assertSame( 'r-1', $snap[0]['rid'] );
		$this->assertSame( 'active', $snap[0]['state'] );
		$this->assertSame( '/a', $snap[0]['url'] );
		$this->assertSame( 'GET', $snap[0]['method'] );
		$this->assertEqualsWithDelta( 1.0, $snap[0]['start_time'], 1e-9 );
		$this->assertSame( 'r-2', $snap[1]['rid'] );
		$this->assertSame( 'POST', $snap[1]['method'] );
	}
}
