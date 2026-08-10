<?php
/**
 * Tests for the memory bound across in-flight requests: Request_Builder folds
 * the largest envelope to aggregated paths once the entries it holds across
 * ALL of them cross the budget.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;

#[CoversClass( Request_Builder_Node::class )]
class RequestBuilderPressureFoldTest extends TestCase {

	/** Small enough to cross in a test, distinct from the 50000 default. */
	private const BUDGET = 40;

	/** Per-request cap, distinct from BUDGET and from the 50000 default. */
	private const MAX_PER_REQUEST = 24;

	private float $saved_now = 0.0;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_now = Core::$now;
		( new Router_Node() )->name( Node_Names::ROUTER );
	}

	protected function tearDown(): void {
		Core::$now = $this->saved_now;
		parent::tearDown();
	}

	/** A builder wired to a capture sink, with the budget pinned. */
	private function builder( Capture_Sink_Node $sink ): Request_Builder_Node {
		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->sink( $sink );
		$rb->arguments( [ '100', '2', (string) self::BUDGET, (string) self::MAX_PER_REQUEST ] );
		return $rb;
	}

	/**
	 * Feed one firehose line.
	 *
	 * @param array<string,mixed> $extra Additional entry fields.
	 */
	private function fill( Request_Builder_Node $rb, int $n, string $rid, string $k, array $extra = [] ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = $rid;
		$message[ Message::VALUE ] = \array_merge(
			[ 'n' => $n, 'rid' => $rid, 'k' => $k, 'ts' => 1_700_000_000.125 ],
			$extra
		);
		$rb->fill( $message );
	}

	/**
	 * Open a request and log $pairs `save` spans into it.
	 *
	 * @return int The next unused line number.
	 */
	private function run_request( Request_Builder_Node $rb, string $rid, int $pairs, int $n = 1 ): int {
		$this->fill( $rb, $n++, $rid, 'process (start)', [ 'm' => "1 on host GET /{$rid}" ] );
		$this->fill( $rb, $n++, $rid, 'request', [ 'm' => "GET http://x/{$rid}" ] );
		for ( $i = 0; $i < $pairs; $i++ ) {
			$this->fill( $rb, $n++, $rid, 'save (start)' );
			$this->fill( $rb, $n++, $rid, 'save (complete)', [ 'duration_ms' => 1 + $i ] );
		}
		return $n;
	}

	/** The record emitted for a request, by rid. */
	private function record_for( Capture_Sink_Node $sink, string $rid ): array {
		foreach ( $sink->captured as $message ) {
			if ( $rid === ( $message[ Message::KEY ] ?? '' ) && \is_array( $message[ Message::VALUE ] ) ) {
				return $message[ Message::VALUE ];
			}
		}
		$this->fail( "no record emitted for {$rid}" );
	}

	public function test_the_largest_envelope_folds_when_the_pool_crosses_its_budget(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );

		// Two in flight: a short one and a long one. Only the long one should
		// pay — a small request keeps its full chronology.
		$short_n = $this->run_request( $rb, 'short', 2 );
		$long_n  = $this->run_request( $rb, 'long', 40 );

		$this->fill( $rb, $short_n, 'short', 'process (complete)', [ 'duration_ms' => 12, 'status_code' => 200 ] );
		$this->fill( $rb, $long_n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$long  = $this->record_for( $sink, 'long' );
		$short = $this->record_for( $sink, 'short' );

		$this->assertTrue( $long['folded'] ?? false, 'the long request should have folded' );
		// Reclaimed, not merely capped: 82 raw entries down to the bounded
		// head + marker + tail, nowhere near what it held.
		$this->assertLessThan( 60, \count( $long['entries'] ), 'folding must RECLAIM, not merely stop growing' );
		$this->assertArrayNotHasKey( 'folded', $short );
		$this->assertNotEmpty( $short['entries'], 'a small request keeps full detail' );
	}

	public function test_a_folded_record_carries_the_merged_tree_the_entries_became(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n = $this->run_request( $rb, 'long', 40 );
		$this->fill( $rb, $n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'long' );
		$this->assertTrue( $record['flame']['folded'] ?? false );
		$save = $record['flame']['children'][0]['children'][0];
		$this->assertSame( 'save', $save['name'] );
		// 40 instances in ONE node — the whole point: cost is O(paths).
		$this->assertSame( 40, $save['count'] );
		// 1 + 2 + ... + 40.
		$this->assertEqualsWithDelta( 820.0, $save['value'], 1e-6 );
		$this->assertEqualsWithDelta( 40.0, $save['max'], 1e-6 );
	}

	public function test_the_live_fold_state_never_reaches_the_wire(): void {
		// It is scaffolding — an open-span stack and a name-keyed map, several
		// times the size of the tree it produces.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n = $this->run_request( $rb, 'long', 40 );
		$this->fill( $rb, $n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$this->assertArrayNotHasKey( 'fold', $this->record_for( $sink, 'long' ) );
	}

	public function test_a_folded_request_keeps_folding_rather_than_growing_again(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$next = $this->run_request( $rb, 'long', 40 );

		// Another 30 pairs AFTER the fold: they must land in the path map.
		for ( $i = 0; $i < 30; $i++ ) {
			$this->fill( $rb, $next++, 'long', 'save (start)' );
			$this->fill( $rb, $next++, 'long', 'save (complete)', [ 'duration_ms' => 100 ] );
		}
		$this->fill( $rb, $next, 'long', 'process (complete)', [ 'duration_ms' => 5000, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'long' );
		// Still bounded after 30 more pairs: the ends are kept, not the middle.
		$this->assertLessThan( 25, \count( $record['entries'] ) );
		$save = $record['flame']['children'][0]['children'][0];
		$this->assertSame( 70, $save['count'] );
		$this->assertEqualsWithDelta( 3820.0, $save['value'], 1e-6 );
	}

	public function test_the_merged_tree_folds_by_path_and_counts_what_it_merged(): void {
		// One node per path, not per instance: 40 `save` calls listed
		// separately crowd out every other path and say nothing the count does
		// not. The tree is the only structure — there is no parallel span list.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n = $this->run_request( $rb, 'long', 40 );
		$this->fill( $rb, $n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$record  = $this->record_for( $sink, 'long' );
		$process = $record['flame']['children'][0];
		$this->assertSame( 'process', $process['name'] );
		$this->assertCount( 1, $process['children'], 'the 40 saves are one node' );

		$save = $process['children'][0];
		$this->assertSame( 'save', $save['name'] );
		$this->assertSame( 40, $save['count'] );
		// 1 + 2 + ... + 40, inclusive as the log shows durations.
		$this->assertEqualsWithDelta( 820.0, $save['value'], 1e-6 );
		$this->assertNotNull( $save['t'], 'the log stamps its rows from this' );
	}

	public function test_a_folded_request_still_reports_entries_it_lost_to_a_gap(): void {
		// The fold empties `entries`; the gap marker is what lands there
		// afterwards, and it announces a break in the very trace least worth
		// trusting. Wiping the list on the way to the wire would delete it.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = $this->run_request( $rb, 'long', 40 );
		// A jump in `n` is a gap: lines went missing between the two.
		$this->fill( $rb, $n + 25, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'long' );
		$this->assertTrue( $record['folded'] );
		$keys = \array_column( $record['entries'], 'k' );
		$this->assertContains( 'entries (lost)', $keys );
		// AFTER the aggregated marker, not before it: the request stopped at
		// its tail, and `entries` is the kept HEAD, so appending there filed
		// the loss chronologically ahead of the middle it announces.
		$this->assertGreaterThan(
			\array_search( 'entries (aggregated)', $keys, true ),
			\array_search( 'entries (lost)', $keys, true )
		);
	}

	public function test_a_folded_record_keeps_the_head_and_tail_entries(): void {
		// The head is how a request identifies itself and the tail is how it
		// ends; both are bounded and are the lines a reader needs most. Only
		// the repetitive middle is what makes an envelope cost memory.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = $this->run_request( $rb, 'long', 40 );
		$this->fill( $rb, $n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$entries = $this->record_for( $sink, 'long' )['entries'];
		$keys    = \array_column( $entries, 'k' );

		$this->assertSame( 'process (start)', $keys[0], 'the head must survive the fold' );
		$this->assertSame( 'request', $keys[1] );
		$this->assertSame( 'process (complete)', \end( $keys ), 'the tail must survive the fold' );
	}

	public function test_a_folded_record_marks_the_middle_it_aggregated_away(): void {
		// A head running straight into a tail would read as a short request.
		// The marker says how many lines are missing and where they went.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = $this->run_request( $rb, 'long', 40 );
		$this->fill( $rb, $n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$entries = $this->record_for( $sink, 'long' )['entries'];
		$markers = \array_values(
			\array_filter( $entries, static fn ( array $e ): bool => 'entries (aggregated)' === ( $e['k'] ?? '' ) )
		);

		$this->assertCount( 1, $markers );
		// 2 opening + 80 save + 1 terminal = 83 merged, less the 10 + 10 kept.
		$this->assertStringContainsString( '63 entries', $markers[0]['m'] );
		// It sits between them, never at either end.
		$position = \array_search( $markers[0], $entries, true );
		$this->assertGreaterThan( 0, $position );
		$this->assertLessThan( \count( $entries ) - 1, $position );
	}

	public function test_one_runaway_request_folds_on_its_own_cap(): void {
		// The per-request cap used to just STOP recording — everything past it
		// was dropped for a `truncated` flag nothing surfaced. Folding keeps
		// the counts and totals for the whole request instead.
		//
		// A budget high enough that the pool never trips: this must be the
		// per-request cap doing the work, not pressure.
		$sink = new Capture_Sink_Node();
		$rb   = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->sink( $sink );
		$rb->arguments( [ '100', '2', '100000', (string) self::MAX_PER_REQUEST ] );

		$n = $this->run_request( $rb, 'solo', 30 );
		$this->fill( $rb, $n, 'solo', 'process (complete)', [ 'duration_ms' => 700, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'solo' );
		$this->assertTrue( $record['folded'] ?? false, 'the cap must fold, not truncate' );
		$this->assertArrayNotHasKey( 'truncated', $record );
		// All 30 saves counted, including the ones past the cap.
		$save = $record['flame']['children'][0]['children'][0];
		$this->assertSame( 30, $save['count'] );
	}

	public function test_an_unpressured_pool_ships_the_shape_it_always_did(): void {
		// The common path must be byte-for-byte what shipped before: full
		// chronology, raw entries, no flame, no flag.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n = $this->run_request( $rb, 'tiny', 3 );
		$this->fill( $rb, $n, 'tiny', 'process (complete)', [ 'duration_ms' => 20, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'tiny' );
		$this->assertArrayNotHasKey( 'flame', $record );
		$this->assertArrayNotHasKey( 'folded', $record );
		$this->assertArrayNotHasKey( 'spans', $record );
		// process (start), request, 3 x (save start + complete), process (complete).
		$this->assertCount( 9, $record['entries'] );
	}
}
