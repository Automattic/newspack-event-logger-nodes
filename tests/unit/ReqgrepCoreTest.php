<?php
/**
 * Tests for Reqgrep_Core — the shared rid-grouping / pattern-matching engine
 * that both `wp nodes reqgrep` and the `request_grep` performance-CI verb
 * consume. Pins the grouping + match semantics (exact-rid short-circuit,
 * regex-against-line, history bootstrap, complete-fires-on-complete) so the
 * CLI and dashboard agree byte-for-byte on which lines belong to which request.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Nodes\LRU_Cache;
use Newspack_Event_Logger_Nodes\Reqgrep_Core;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Reqgrep_Core::class )]
class ReqgrepCoreTest extends TestCase {

	/**
	 * @param array<int,array{rid:string,lines:array<int,string>}> $completed Collected on-complete calls (by ref).
	 */
	private function make_core(
		string $pattern,
		array &$completed,
		int $bucket_size = 250,
		int $num_buckets = 10,
		?callable $on_miss = null
	): Reqgrep_Core {
		$inflight = new LRU_Cache( 100, 3 );
		return new Reqgrep_Core(
			$pattern,
			$inflight,
			$bucket_size,
			$num_buckets,
			function ( array $lines, string $rid ) use ( &$completed ): void {
				$completed[] = [ 'rid' => $rid, 'lines' => $lines ];
			},
			$on_miss
		);
	}

	private function push( Reqgrep_Core $core, string $rid, string $key, string $line, int $n = 1 ): void {
		$core->push( [ 'n' => $n, 'k' => $key ], $rid, $line );
	}

	public function test_completed_matching_request_fires_on_complete_with_all_lines(): void {
		$completed = [];
		$core      = $this->make_core( '/calendar', $completed );
		$this->push( $core, 'reqA', 'process (start)', 'start /calendar', 1 );
		$this->push( $core, 'reqA', 'init', 'init middle', 2 );
		$this->push( $core, 'reqA', 'process (complete)', 'complete /calendar', 3 );

		$this->assertCount( 1, $completed );
		$this->assertSame( 'reqA', $completed[0]['rid'] );
		$this->assertCount( 3, $completed[0]['lines'] );
	}

	public function test_an_aborted_request_completes_like_any_other_terminal(): void {
		// `process (aborted)` is a terminal in `Request_Builder_Node::TERMINAL_KEYWORDS`
		// too. Firing only on `(complete)` left every lease-killed request out of the
		// dashboard's `request_grep` reply entirely, and mislabelled `[incomplete]` in
		// the CLI — the one request an operator greps for.
		$completed = [];
		$core      = $this->make_core( '/calendar', $completed );
		$this->push( $core, 'reqAbort', 'process (start)', 'start /calendar', 1 );
		$this->push( $core, 'reqAbort', 'render', 'render /calendar', 2 );
		$this->push( $core, 'reqAbort', 'process (aborted)', 'aborted /calendar', 3 );

		$this->assertCount( 1, $completed );
		$this->assertSame( 'reqAbort', $completed[0]['rid'] );
		$this->assertCount( 3, $completed[0]['lines'] );
	}

	public function test_on_complete_reports_a_line_capped_request_as_clipped(): void {
		// A request over MAX_LINES_PER_REQUEST drops its tail — on_complete
		// must say so (3rd arg) so callers never report a clipped count as full.
		$calls = [];
		$core  = $this->make_core_with_clip( '/calendar', $calls );
		$this->push( $core, 'reqBig', 'process (start)', 'start /calendar', 1 );
		for ( $i = 0; $i < Reqgrep_Core::MAX_LINES_PER_REQUEST + 5; $i++ ) {
			$this->push( $core, 'reqBig', 'hook', "line {$i} /calendar", $i + 2 );
		}
		$this->push( $core, 'reqBig', 'process (complete)', 'complete /calendar', 99 );

		$this->assertCount( 1, $calls );
		$this->assertTrue( $calls[0]['clipped'], 'the line cap fired — the caller must know' );
		$this->assertCount( Reqgrep_Core::MAX_LINES_PER_REQUEST, $calls[0]['lines'] );
	}

	public function test_on_complete_reports_an_uncapped_request_as_not_clipped(): void {
		$calls = [];
		$core  = $this->make_core_with_clip( '/calendar', $calls );
		$this->push( $core, 'reqSmall', 'process (start)', 'start /calendar', 1 );
		$this->push( $core, 'reqSmall', 'process (complete)', 'complete /calendar', 2 );

		$this->assertCount( 1, $calls );
		$this->assertFalse( $calls[0]['clipped'] );
	}

	/**
	 * @param array<int,array{rid:string,lines:array<int,string>,clipped:bool}> $calls Collected on-complete calls (by ref).
	 */
	private function make_core_with_clip( string $pattern, array &$calls ): Reqgrep_Core {
		return new Reqgrep_Core(
			$pattern,
			new LRU_Cache( 100, 3 ),
			250,
			10,
			function ( array $lines, string $rid, bool $clipped = false ) use ( &$calls ): void {
				$calls[] = [
					'rid'     => $rid,
					'lines'   => $lines,
					'clipped' => $clipped,
				];
			},
			null
		);
	}

	public function test_non_matching_request_never_completes(): void {
		$completed = [];
		$core      = $this->make_core( '/calendar', $completed );
		$this->push( $core, 'reqB', 'process (start)', 'start /other', 1 );
		$this->push( $core, 'reqB', 'process (complete)', 'complete /other', 2 );

		$this->assertSame( [], $completed );
	}

	public function test_exact_rid_short_circuit_tracks_without_text_match(): void {
		// Pattern equals the rid — request is tracked even though no line text matches.
		$completed = [];
		$core      = $this->make_core( 'exactRid42', $completed );
		$this->push( $core, 'exactRid42', 'process (start)', 'no-text-match-here', 1 );
		$this->push( $core, 'exactRid42', 'process (complete)', 'still-no-match', 2 );

		$this->assertCount( 1, $completed );
		$this->assertSame( 'exactRid42', $completed[0]['rid'] );
	}

	public function test_history_bootstrap_recovers_earlier_lines_on_late_match(): void {
		// The start line does NOT match; a LATER line does. History backfills the start.
		$completed = [];
		$core      = $this->make_core( 'late-token', $completed );
		$this->push( $core, 'reqC', 'process (start)', 'start GET /home', 1 );
		$this->push( $core, 'reqC', 'init', 'init late-token appears', 2 );
		$this->push( $core, 'reqC', 'process (complete)', 'complete', 3 );

		$this->assertCount( 1, $completed );
		$this->assertCount( 3, $completed[0]['lines'] );
		$this->assertStringContainsString( 'GET /home', $completed[0]['lines'][0] );
	}

	public function test_on_history_miss_fires_when_late_match_has_no_history(): void {
		$missed    = 0;
		$completed = [];
		$core      = $this->make_core(
			'target-late',
			$completed,
			1,
			2,
			function () use ( &$missed ): void {
				++$missed;
			}
		);

		// Fill both history buckets with non-matching noise (count(history) >= num_buckets).
		for ( $i = 0; $i < 4; $i++ ) {
			$this->push( $core, "noise-{$i}", 'init', "noise /x {$i}", 1 );
		}

		// A matching rid arriving at n=5 (not the first line) with no history → miss.
		$this->push( $core, 'target-late', 'init', 'target-late arrives late', 5 );

		$this->assertSame( 1, $missed );
	}
}
