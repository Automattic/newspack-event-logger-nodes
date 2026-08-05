<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Per-request `n`-sequence validation, plus the timed-out-trace log signal on
 * eviction.
 * Detects mid-stream orphans, dupes, reordering, and rid reuse — the correctness
 * guard complementing the Consumer seal-grace fix.
 */
#[CoversClass( Request_Builder_Node::class )]
class RequestBuilderValidationTest extends TestCase {
	private string $log = '';

	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
		$this->log = '';
		Core::set_stderr_handler( function ( string $m ): void {
			$this->log .= $m;
		} );
	}

	private function fill( Request_Builder_Node $rb, int $n, string $rid, string $k, array $extra = [] ): void {
		$entry                     = \array_merge( [ 'n' => $n, 'rid' => $rid, 'k' => $k, 'ts' => 1_700_000_000 ], $extra );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = $rid;
		$message[ Message::VALUE ] = $entry;
		$rb->fill( $message );
	}

	private function builder( array $args = [] ): Request_Builder_Node {
		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		if ( [] !== $args ) {
			$rb->arguments( $args );
		}
		$rb->sink( new Capture_Sink_Node() );
		return $rb;
	}

	public function test_in_order_sequence_emits_no_validation_log(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_ok', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'seq_ok', 'request', [ 'm' => 'GET /a' ] );
		$this->fill( $rb, 3, 'seq_ok', 'process (complete)', [ 'duration_ms' => 5.0, 'status_code' => 200 ] );

		$this->assertStringNotContainsString( 'missing message', $this->log );
		$this->assertStringNotContainsString( 'duplicate message', $this->log );
		$this->assertStringNotContainsString( 'multiple requests', $this->log );
	}

	public function test_gap_emits_missing_message_warning_and_recovers(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_gap', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		// n=3 with expected n=2 → a mid-stream line was orphaned.
		$this->fill( $rb, 3, 'seq_gap', 'info', [ 'm' => 'skipped' ] );
		$this->assertStringContainsString( 'WARNING: missing message: expected #2, got #3 on seq_gap', $this->log );

		// The skipped line did not advance expected: the real #2 still lands.
		$this->fill( $rb, 2, 'seq_gap', 'request', [ 'm' => 'GET /b' ] );
		$this->assertStringNotContainsString( 'expected #3, got #2', $this->log, '#2 arriving after the gap is in-order, not a dup' );
	}

	public function test_regressed_n_emits_duplicate_info(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_dup', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'seq_dup', 'request', [ 'm' => 'GET /c' ] );
		// Re-delivered #2 (expected #3 now) → duplicate, INFO not WARNING.
		$this->fill( $rb, 2, 'seq_dup', 'request', [ 'm' => 'GET /c' ] );

		$this->assertStringContainsString( 'INFO: duplicate message: expected #3, got #2 on seq_dup', $this->log );
	}

	public function test_second_process_start_on_live_rid_warns_multiple_requests(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_reuse', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'seq_reuse', 'request', [ 'm' => 'GET /d' ] );
		// A fresh process (start) for an id still in flight = rid reuse.
		$this->fill( $rb, 1, 'seq_reuse', 'process (start)', [ 'm' => '2 on h', 'l' => '' ] );

		$this->assertStringContainsString( 'WARNING: multiple requests with ID: seq_reuse', $this->log );
	}

	public function test_nested_gyrobase_subsequence_is_tracked_independently(): void {
		// Nuclear-gyrobase shells out to the Perl engine (proc_open); the subprocess
		// emits its OWN n-sequence (1..N) inside the parent request's stream under the
		// SAME rid. gyrobase (start) stashes the parent's expected n and resets to the
		// nested sequence; gyrobase (complete) restores it — so neither the nested
		// lines nor the parent's resume trip the validator.
		$rb      = $this->builder();
		$capture = $rb->sink();

		$this->fill( $rb, 1, 'nuke', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'nuke', 'request', [ 'm' => 'GET /film' ] );
		$this->fill( $rb, 3, 'nuke', 'newspack_nuclear_gyrobase_init (start)', [ 'l' => '' ] );

		// Nested subprocess sequence — restarts at 1 under the same rid. Deliberately
		// longer than the parent's remaining lines so the nested tail (n=4) does NOT
		// line up with the parent's resume (n=4): a broken restore would leave
		// expected=5 and the parent's n=4 would trip a duplicate — this is what makes
		// the stack pop load-bearing, not coincidentally contiguous.
		$this->fill( $rb, 1, 'nuke', 'gyrobase (start)', [ 'l' => '' ] );
		$this->fill( $rb, 2, 'nuke', 'publication', [ 'm' => 'bend' ] );
		$this->fill( $rb, 3, 'nuke', 'access', [ 'm' => 'allowed' ] );
		$this->fill( $rb, 4, 'nuke', 'gyrobase (complete)', [ 'duration_ms' => 1015.0 ] );

		// Parent resumes at its own next n (4, the value stashed before the nested block).
		$this->fill( $rb, 4, 'nuke', 'newspack_nuclear_gyrobase_init (complete)', [ 'duration_ms' => 1390.0 ] );
		$this->fill( $rb, 5, 'nuke', 'process (complete)', [ 'duration_ms' => 1400.0, 'status_code' => 200 ] );

		$this->assertStringNotContainsString( 'missing message', $this->log, 'nested/parent sequences must not read as gaps' );
		$this->assertStringNotContainsString( 'duplicate message', $this->log, 'the nested restart at n=1 must not read as a dup' );
		$this->assertStringNotContainsString( 'multiple requests', $this->log );

		// The request finalized cleanly (process (complete) reached).
		$this->assertNotEmpty( $capture->captured, 'request assembled and emitted' );
		$this->assertSame( 'nuke', $capture->captured[0][ Message::VALUE ]['rid'] );
	}

	public function test_evicted_incomplete_request_logs_trace_timed_out(): void {
		// bucket_size=1, num_buckets=2 → r_to's bucket is evicted when the next rid sets.
		$rb = $this->builder( [ '1', '2' ] );
		$this->fill( $rb, 1, 'r_to', 'process (start)' );
		$this->fill( $rb, 2, 'r_to', 'request', [ 'm' => 'GET /timeout' ] );

		$this->fill( $rb, 1, 'r_next', 'process (start)' ); // forces r_to out.

		$this->assertStringContainsString( 'WARNING: trace timed out on r_to', $this->log );
	}
}
