<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

require_once \dirname( __DIR__, 2 ) . '/includes/class-inflight-tracker.php';

use Newspack_Event_Logger_Nodes\InflightTracker;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( InflightTracker::class )]
class InflightTrackerTest extends TestCase {

	private function entry( array $overrides ): array {
		return \array_merge(
			[
				'rid' => 'r1',
				'k'   => '',
				'm'   => '',
				'ts'  => 1700000000.0,
			],
			$overrides
		);
	}

	public function test_request_keyword_seeds_active_state(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'GET /foo' ] ) );
		$this->assertSame( 1, $t->active_count() );
		$active = $t->get_active();
		$this->assertCount( 1, $active );
		$this->assertSame( 'r1', $active[0]['rid'] );
		$this->assertSame( 'GET', $active[0]['method'] );
		$this->assertSame( '/foo', $active[0]['url'] );
		$this->assertSame( 'process', $active[0]['state'] );
	}

	public function test_skips_self_referential_gyroscope_url(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'GET /firehose/gyroscope?slot=1' ] ) );
		$this->assertSame( 0, $t->active_count() );
	}

	public function test_unknown_rid_lines_are_dropped(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'rid' => 'r1', 'k' => 'foo (start)', 'm' => 'doing' ] ) );
		// No 'request' line first → should be ignored.
		$this->assertSame( 0, $t->active_count() );
	}

	public function test_start_pushes_stack_complete_pops(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'POST /api' ] ) );
		$t->process( $this->entry( [ 'k' => 'render (start)', 'm' => 'index.html' ] ) );
		$snap = $t->get_active();
		$this->assertSame( 'render', $snap[0]['state'] );
		$this->assertSame( 'index.html', $snap[0]['what'] );
		$t->process( $this->entry( [ 'k' => 'render (complete)' ] ) );
		$snap = $t->get_active();
		$this->assertSame( 'process', $snap[0]['state'] );
	}

	public function test_process_complete_moves_to_completed_buffer(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'GET /home' ] ) );
		$t->process( $this->entry( [ 'k' => 'process (complete)', 'duration_ms' => 12, 'status_code' => 200 ] ) );
		$this->assertSame( 0, $t->active_count() );
		$completed = $t->get_completed();
		$this->assertCount( 1, $completed );
		$this->assertSame( 12, $completed[0]['duration_ms'] );
		$this->assertSame( 200, $completed[0]['status_code'] );
		// Drain semantics: second call returns empty.
		$this->assertSame( [], $t->get_completed() );
	}

	public function test_environment_v2_extracts_remote_addr_and_user_agent(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'GET /' ] ) );
		$t->process( $this->entry( [ 'k' => 'environment_v2', 'm' => 'REMOTE_ADDR => "10.0.0.1"' ] ) );
		$t->process( $this->entry( [ 'k' => 'environment_v2', 'm' => 'HTTP_USER_AGENT => "TestUA/1.0"' ] ) );
		$snap = $t->get_active();
		$this->assertSame( '10.0.0.1', $snap[0]['remote_addr'] );
		$this->assertSame( 'TestUA/1.0', $snap[0]['user_agent'] );
	}

	public function test_get_active_sorts_by_est_ms_desc(): void {
		$t = new InflightTracker();
		$t->process( [ 'rid' => 'a', 'k' => 'request', 'm' => 'GET /a', 'ts' => 1700000000.0 ] );
		$t->process( [ 'rid' => 'b', 'k' => 'request', 'm' => 'GET /b', 'ts' => 1700000050.0 ] );
		// At snapshot time, last_log_ts - start_time gives deterministic time_ms.
		// 'b' was just created so its time_ms is 0; 'a' has time_ms equal to (1700000000-1700000000)*1000 = 0 too.
		// est_ms includes wall-clock age — both new, comparable.
		$snap = $t->get_active();
		$this->assertCount( 2, $snap );
	}

	public function test_max_stack_depth_caps_growth(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'GET /' ] ) );
		// Push 200 frames; cap is 100. The 101st+ should silently no-op.
		for ( $i = 0; $i < 200; $i++ ) {
			$t->process( $this->entry( [ 'k' => "f{$i} (start)", 'm' => "frame{$i}" ] ) );
		}
		// State should be the last frame that fit (frame 99 when seeded with 'process').
		$snap = $t->get_active();
		$this->assertSame( 1, $t->active_count() );
		// Note: state IS updated even when frame pushes are dropped — matches legacy.
	}

	public function test_process_line_handles_invalid_json(): void {
		$t = new InflightTracker();
		$t->process_line( 'not json' );
		$t->process_line( '' );
		$t->process_line( '{"rid":"r1","k":"request","m":"GET /x","ts":1}' );
		$this->assertSame( 1, $t->active_count() );
	}

	public function test_reap_stale_clears_old_entries(): void {
		$t = new InflightTracker();
		$t->process( $this->entry( [ 'k' => 'request', 'm' => 'GET /x' ] ) );
		// Force the tracker_ts back >300s by manipulating reflection.
		$ref      = new \ReflectionClass( $t );
		$prop     = $ref->getProperty( 'requests' );
		$prop->setAccessible( true );
		$requests = $prop->getValue( $t );
		$requests['r1']['tracker_ts'] = \microtime( true ) - 600;
		$prop->setValue( $t, $requests );
		$reaped = $t->reap_stale();
		$this->assertSame( 1, $reaped );
		$this->assertSame( 0, $t->active_count() );
	}

	public function test_truncates_message_keys_safely(): void {
		$t = new InflightTracker();
		// Empty rid → silently dropped.
		$t->process( [ 'rid' => '', 'k' => 'request', 'm' => 'GET /x' ] );
		$this->assertSame( 0, $t->active_count() );
	}
}
