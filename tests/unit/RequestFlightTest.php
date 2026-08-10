<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Request_Flight_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Router_Node;

/**
 * RequestFlight is a hidden Timer-sibling Node attached to RequestBuilder:
 * periodically snapshots the patron's in-progress request map and emits a
 * TM_STRUCT batch to a configured target node (typically a gyroscope
 * partition).
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

	/**
	 * Every in-flight record the sink saw (non-empty KEY carries the rid).
	 *
	 * @param array<int,array> $got
	 * @return array<int,array>
	 */
	private function inflight_messages( array $got ): array {
		return \array_values( \array_filter(
			$got,
			static fn ( $m ): bool => '' !== ( $m[ Message::KEY ] ?? '' ) && \is_array( $m[ Message::VALUE ] ?? null )
		) );
	}

	public function test_fire_emits_one_message_per_inflight_request(): void {
		// Per-record: one TM_STRUCT per in-flight request, KEY=rid (the
		// Tachikoma shape), NOT one batched list — that crossed the 4KB cap.
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-flight-e2e' );

		$got = [];
		$rb->sink( $this->capture_sink( $got ) );

		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0 ] );
		$rb->cache->set( 'r-2', (object) [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0 ] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$inflight = $this->inflight_messages( $got );
		$this->assertCount( 2, $inflight, 'one message per in-flight request' );
		foreach ( $inflight as $m ) {
			$this->assertSame( Message::TM_STRUCT, $m[ Message::TYPE ] );
			$this->assertSame( 'gyroscope_partition', $m[ Message::TO ] );
			$this->assertSame( 'rb-flight-e2e:flight', $m[ Message::FROM ] );
			// The rid lives in KEY ONLY — VALUE carries no duplicate field.
			$this->assertIsArray( $m[ Message::VALUE ] );
			$this->assertArrayNotHasKey( 'rid', $m[ Message::VALUE ] );
		}
		$this->assertSame( [ 'r-1', 'r-2' ], \array_map( static fn ( $m ) => $m[ Message::KEY ], $inflight ) );
		// Default state for a primed request with no stack frames: unwound default.
		$this->assertSame( 'process', $inflight[0][ Message::VALUE ]['state'] );
		$this->assertSame( '/a', $inflight[0][ Message::VALUE ]['url'] );
	}

	public function test_a_worker_request_carries_its_worker_type_in_flight(): void {
		// The completed record appends the worker type so each worker gets its
		// own URL row. In-flight rows read the RAW url and did not, so a job's
		// execution and the admin request that ENQUEUED it — which log the same
		// /jobs/{handler}/{id} URI — collapsed onto one row, and the gyroscope
		// link resolved to whichever came first.
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-worker-url' );

		$got = [];
		$rb->sink( $this->capture_sink( $got ) );

		$rb->cache->set(
			'w-1',
			(object) [
				'url'            => '/jobs/pyrobase-cron/periodical-cron',
				'request_method' => 'POST',
				'timestamp'      => 1.0,
				'is_worker'      => true,
				'worker_type'    => 'job-worker',
			]
		);

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$inflight = $this->inflight_messages( $got );
		$this->assertSame(
			'/jobs/pyrobase-cron/periodical-cron?job-worker',
			$inflight[0][ Message::VALUE ]['url']
		);
	}

	public function test_fire_stamps_a_string_key_for_an_all_digits_rid(): void {
		// PHP coerces the all-digits map key to int; the wire KEY must come
		// back as the STRING rid regardless.
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-numeric-rid' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( '4477', (object) [ 'url' => '/n', 'request_method' => 'GET', 'timestamp' => 1.0 ] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$inflight = $this->inflight_messages( $got );
		$this->assertCount( 1, $inflight );
		$this->assertSame( '4477', $inflight[0][ Message::KEY ] );
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

	public function test_fire_emits_the_per_record_wire_shape(): void {
		// Asserts the per-record wire-level message shape RequestFlight emits.
		$rb  = new Request_Builder_Node();
		$rows = [
			'r-1' => [ 'url' => '/a', 'request_method' => 'GET',  'timestamp' => 1.0 ],
			'r-2' => [ 'url' => '/b', 'request_method' => 'POST', 'timestamp' => 2.0 ],
		];
		$rb->cache->set( 'r-1', (object) $rows['r-1'] );
		$rb->cache->set( 'r-2', (object) $rows['r-2'] );

		$got = [];
		$rb->name( 'patron-with-batch' );
		$rb->sink( $this->capture_sink( $got ) );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$inflight = $this->inflight_messages( $got );
		$this->assertCount( 2, $inflight );
		$this->assertSame( $rows['r-1']['url'],            $inflight[0][ Message::VALUE ]['url'] );
		$this->assertSame( $rows['r-1']['request_method'], $inflight[0][ Message::VALUE ]['method'] );
		$this->assertSame( $rows['r-1']['timestamp'],      $inflight[0][ Message::VALUE ]['start_time'] );
		$this->assertSame( $rows['r-2']['url'],            $inflight[1][ Message::VALUE ]['url'] );
		$this->assertSame( $rows['r-2']['request_method'], $inflight[1][ Message::VALUE ]['method'] );
		$this->assertSame( $rows['r-2']['timestamp'],      $inflight[1][ Message::VALUE ]['start_time'] );
	}

	public function test_delta_off_reemits_unchanged_rows_every_tick(): void {
		// The stock default (delta OFF): a fresh subscriber sees the whole cache
		// within one tick — every row re-emitted each fire regardless of activity.
		Core::$now = 100.0;
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-delta-off' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET', 'timestamp' => 10.0, 'last_log_ts' => 10.0 ] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();
		Core::$now = 200.0;
		$flight->fire_cb();

		$this->assertCount( 2, $this->inflight_messages( $got ), 'delta off re-emits the unchanged row on every tick' );
	}

	public function test_delta_on_suppresses_unchanged_rows_and_advances_the_watermark(): void {
		Core::$now = 100.0;
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-delta-on' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( 'r-1', (object) [ 'url' => '/a', 'request_method' => 'GET', 'timestamp' => 10.0, 'last_log_ts' => 10.0 ] );

		$flight = $rb->flight();
		$flight->set_delta( true );
		$flight->target( 'gyroscope_partition' );

		// First tick: emits (initial watermark 0), advancing the watermark to 100.
		$flight->fire_cb();
		$this->assertCount( 1, $this->inflight_messages( $got ) );

		// Second tick: the row's last_log_ts (10) < watermark (100) → suppressed.
		Core::$now = 200.0;
		$flight->fire_cb();
		$this->assertCount( 1, $this->inflight_messages( $got ), 'unchanged row suppressed under delta' );

		// Advance the row's activity past the watermark → re-emitted next tick.
		$rb->cache->get( 'r-1' )->last_log_ts = 150.0;
		Core::$now = 300.0;
		$flight->fire_cb();
		$this->assertCount( 2, $this->inflight_messages( $got ), 'advanced row re-emitted under delta' );
	}

	public function test_inflight_row_clips_url_and_user_agent_like_the_completed_path(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-clip-inflight' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( 'r-clip', (object) [
			'url'            => '/' . \str_repeat( 'u', 3000 ),
			'user_agent'     => \str_repeat( 'A', 900 ),
			'request_method' => 'GET',
			'timestamp'      => 1.0,
		] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$inflight = $this->inflight_messages( $got );
		$this->assertCount( 1, $inflight );
		$this->assertSame( 2003, \strlen( $inflight[0][ Message::VALUE ]['url'] ) );
		$this->assertSame( 503, \strlen( $inflight[0][ Message::VALUE ]['user_agent'] ) );
	}

	public function test_inflight_row_fits_a_multibyte_payload_under_pipe_buf(): void {
		// gyroscope:partition lost void_warranty; the char clip is only a proxy —
		// a 2000-char multibyte url packs past PIPE_BUF, so the fit must trim it.
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-fit-inflight' );
		$got = [];
		$rb->sink( $this->capture_sink( $got ) );
		$rb->cache->set( 'r-fit', (object) [
			'url'            => '/' . \str_repeat( '错', 3000 ),
			'user_agent'     => \str_repeat( '错', 900 ),
			'request_method' => 'GET',
			'timestamp'      => 1.0,
		] );

		$flight = $rb->flight();
		$flight->target( 'gyroscope_partition' );
		$flight->fire_cb();

		$inflight = $this->inflight_messages( $got );
		$this->assertCount( 1, $inflight, 'the row was fitted + emitted, not dropped' );
		$this->assertLessThanOrEqual(
			Partition_Node::MAX_LINE_SIZE,
			Message::packed_size( $inflight[0] ) + 1,
			'the packed in-flight row + newline fits under PIPE_BUF'
		);
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

		$emitted = $this->inflight_messages( $got );
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
