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

	private function fill( Request_Builder_Node $rb, int $n, string $rid, string $k, array $extra = [], string $position = '' ): void {
		$entry                     = \array_merge( [ 'n' => $n, 'rid' => $rid, 'k' => $k, 'ts' => 1_700_000_000 ], $extra );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = $rid;
		// Consumer stamps segment:offset:length here; '' if nothing durable read it.
		$message[ Message::ID ]    = $position;
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

	/**
	 * The last request the builder actually finished, or null when it finished
	 * none — which is the interesting case for a broken sequence.
	 *
	 * @param Request_Builder_Node $rb The builder under test.
	 * @return array<string, mixed>|null
	 */
	private function last_emitted( Request_Builder_Node $rb ): ?array {
		$captured = $rb->sink()->captured;
		if ( [] === $captured ) {
			return null;
		}
		$value = \end( $captured )[ Message::VALUE ];
		return \is_array( $value ) ? $value : null;
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

	/**
	 * A request whose sequence broke still closes on `process (complete)`.
	 *
	 * Holding it back leaves a half-built request in the LRU until a bucket
	 * rotates it out — minutes of memory for something already finished, and it
	 * surfaces as `T`, telling the reader it timed out when it did not.
	 */
	public function test_a_gapped_request_still_completes(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_end', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'seq_end', 'request', [ 'm' => 'GET /c' ] );
		// #3 never arrives; everything after it is out of sequence for good.
		$this->fill( $rb, 4, 'seq_end', 'info', [ 'm' => 'past the gap' ] );
		$this->fill( $rb, 5, 'seq_end', 'process (complete)', [ 'duration_ms' => 9.0, 'status_code' => 200 ] );

		$summary = $this->last_emitted( $rb );
		$this->assertNotNull( $summary, 'the request was emitted rather than left in the cache' );
		$this->assertSame( 'complete', $summary['state'] );
	}

	/**
	 * The hole is a line in the trace, at the point the entries went missing.
	 *
	 * With no resync there is exactly one hole and it is always at the tail, so
	 * the marker sits between the last in-order entry and the terminal one —
	 * where a reader scrolling the trace meets it, rather than as a header
	 * badge they have to correlate against a number.
	 */
	public function test_a_gapped_request_carries_a_marker_where_the_entries_went_missing(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_flag', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'seq_flag', 'request', [ 'm' => 'GET /d' ] );
		$this->fill( $rb, 7, 'seq_flag', 'info', [ 'm' => 'past the gap' ] );
		$this->fill( $rb, 8, 'seq_flag', 'process (complete)', [ 'duration_ms' => 9.0, 'status_code' => 200 ] );

		$emitted  = $this->last_emitted( $rb );
		$keywords = \array_column( $emitted['entries'], 'k' );
		$this->assertSame(
			[ 'process (start)', 'request', 'entries (lost)', 'process (complete)' ],
			$keywords,
			'the marker sits between the last good entry and the terminal one'
		);

		// #3 is the one that broke the sequence; #2 is the last that arrived in
		// order, and so where a re-read of the firehose starts.
		$marker = $emitted['entries'][2];
		$this->assertSame( 3, $marker['n'], 'it occupies the missing entry\'s slot, not a used one' );
		$this->assertSame(
			'discarded entries after #2',
			$marker['m'],
			'names the resume point, and does not claim the rest went unsent'
		);

		// The list view has no trace to put a line in, so it still needs the flag.
		$this->assertSame( 'I', $emitted['error_status'], 'flagged incomplete, not a clean finish' );
	}

	/**
	 * The marker carries where the last good entry sits on disk.
	 *
	 * Consumer stamps Message::ID as segment:offset:length, so naming the last
	 * in-order entry's ID puts the line that broke the sequence one seek away —
	 * the same coordinates the dead-letter log prints.
	 */
	public function test_the_marker_names_the_position_of_the_last_good_entry(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_at', 'process (start)', [ 'm' => '1 on h', 'l' => '' ], '0:58746100:120' );
		$this->fill( $rb, 2, 'seq_at', 'request', [ 'm' => 'GET /f' ], '0:58746220:127' );
		$this->fill( $rb, 9, 'seq_at', 'info', [ 'm' => 'past the gap' ], '0:58746600:110' );
		$this->fill( $rb, 10, 'seq_at', 'process (complete)', [ 'duration_ms' => 3.0, 'status_code' => 200 ], '0:58746710:99' );

		$marker = $this->last_emitted( $rb )['entries'][2];
		$this->assertSame(
			'discarded entries after #2 at 0:58746220:127',
			$marker['m'],
			'the position is #2 — the last in order — not the out-of-sequence line that followed'
		);
	}

	/** An intact request gets no marker and keeps its nominal status. */
	public function test_an_intact_request_is_not_marked(): void {
		$rb = $this->builder();
		$this->fill( $rb, 1, 'seq_clean', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'seq_clean', 'request', [ 'm' => 'GET /e' ] );
		$this->fill( $rb, 3, 'seq_clean', 'process (complete)', [ 'duration_ms' => 4.0, 'status_code' => 200 ] );

		$emitted = $this->last_emitted( $rb );
		$this->assertNotContains( 'entries (lost)', \array_column( $emitted['entries'], 'k' ) );
		$this->assertSame( '-', $emitted['error_status'] );
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

	/**
	 * A worker cut off mid-job (or a gyrobase render whose lease was stolen)
	 * leaves a half-built request in the cache. Without a terminal marker it
	 * sits until the LRU rotates it out — and meanwhile the successor has
	 * restarted the same job and is building a SECOND entry for it. The abort
	 * line is terminal like `process (complete)`, so the entry is emitted and
	 * evicted at once, but stamped `A` so nothing reads it as a clean finish.
	 */
	public function test_process_aborted_is_terminal_and_stamps_error_status_a(): void {
		$sink = new Capture_Sink_Node();
		$rb   = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->sink( $sink );

		$this->fill( $rb, 1, 'killed', 'process (start)', [ 'm' => '1 on h', 'l' => '' ] );
		$this->fill( $rb, 2, 'killed', 'request', [ 'm' => 'GET /slow' ] );
		$this->fill( $rb, 3, 'killed', 'process (aborted)', [ 'duration_ms' => 900.0, 'status_code' => 0 ] );

		$docs = [];
		foreach ( $sink->captured as $m ) {
			$value = $m[ Message::VALUE ];
			if ( \is_array( $value ) && isset( $value['error_status'] ) ) {
				$docs[] = $value;
			}
		}

		$this->assertNotEmpty( $docs, 'an aborted request is emitted, not left for the LRU' );
		$this->assertSame( 'A', $docs[0]['error_status'] );
		$this->assertSame( '/slow', $docs[0]['url'] );
	}
}
