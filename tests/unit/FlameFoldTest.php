<?php
/**
 * Tests for Flame_Fold: the resumable, merging variant of the flame stack
 * machine that a pressured Request_Builder folds an envelope through.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Flame_Fold::class )]
class FlameFoldTest extends TestCase {

	/** Fractional, so a whole-second truncation anywhere shows up. */
	private const ORIGIN = 1_700_000_000.125;

	/** An entry stamped $offset_ms into the request, as Log_Manager stamps it. */
	private function at( string $k, float $offset_ms, array $extra = [] ): array {
		return [ 'k' => $k, 'ts' => self::ORIGIN + $offset_ms / 1000 ] + $extra;
	}

	/** Feed a whole list through a fresh fold state. */
	private function fold( array $entries ): array {
		$state = Flame_Fold::start( self::ORIGIN );
		foreach ( $entries as $entry ) {
			Flame_Fold::add( $state, $entry );
		}
		return $state;
	}

	public function test_repeated_siblings_collapse_into_one_counted_node(): void {
		// 900 `save:` spans under one parent is the shape that blows the
		// envelope up; folded they cost one node, not 900.
		$entries = [ $this->at( 'render (start)', 0 ) ];
		for ( $i = 0; $i < 3; $i++ ) {
			$entries[] = $this->at( 'save (start)', 10 + $i * 20 );
			$entries[] = $this->at( 'save (complete)', 20 + $i * 20, [ 'duration_ms' => 3 + $i ] );
		}

		$render = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];
		$this->assertCount( 1, $render['children'] );
		$save = $render['children'][0];
		$this->assertSame( 'save', $save['name'] );
		$this->assertSame( 3, $save['count'] );
		// 3 + 4 + 5 summed, 5 the worst.
		$this->assertEqualsWithDelta( 12.0, $save['value'], 1e-6 );
		$this->assertEqualsWithDelta( 5.0, $save['max'], 1e-6 );
	}

	public function test_distinct_labels_stay_distinct(): void {
		$tree = Flame_Fold::tree( $this->fold(
			[
				$this->at( 'hook (start)', 0, [ 'l' => 'init' ] ),
				$this->at( 'hook (complete)', 5, [ 'duration_ms' => 5 ] ),
				$this->at( 'hook (start)', 5, [ 'l' => 'shutdown' ] ),
				$this->at( 'hook (complete)', 9, [ 'duration_ms' => 4 ] ),
			]
		) );
		$names = \array_map( static fn ( $c ) => $c['name'], $tree['children'] );
		$this->assertSame( [ 'hook: init', 'hook: shutdown' ], $names );
	}

	public function test_a_folded_envelope_keeps_folding(): void {
		// The whole point of folding under pressure: cost stops tracking
		// message volume, so a 50-minute run costs what a 5-minute one does.
		$fold = $this->fold(
			[
				$this->at( 'render (start)', 0 ),
				$this->at( 'save (start)', 1 ),
				$this->at( 'save (complete)', 2, [ 'duration_ms' => 1 ] ),
			]
		);
		Flame_Fold::add( $fold, $this->at( 'save (start)', 3 ) );
		Flame_Fold::add( $fold, $this->at( 'save (complete)', 9, [ 'duration_ms' => 6 ] ) );

		$save = Flame_Fold::tree( $fold )['children'][0]['children'][0];
		$this->assertSame( 2, $save['count'] );
		$this->assertEqualsWithDelta( 7.0, $save['value'], 1e-6 );
	}

	public function test_pairing_survives_the_seam_between_two_folds(): void {
		// A span open when the fold began must still match its complete —
		// this is what disqualified decimation, so it cannot break here.
		$fold = $this->fold( [ $this->at( 'render (start)', 0 ) ] );
		Flame_Fold::add( $fold, $this->at( 'render (complete)', 400, [ 'duration_ms' => 400 ] ) );

		$render = Flame_Fold::tree( $fold )['children'][0];
		$this->assertSame( 1, $render['count'] );
		$this->assertEqualsWithDelta( 400.0, $render['value'], 1e-6 );
	}

	public function test_a_parent_covers_the_extent_its_positioned_children_span(): void {
		// Positions make gaps visible, and the browser fills them with spacers,
		// so children + spacers reach the EXTENT — not their summed value. A
		// parent sized to the sum alone is overflowed by its own children:
		// treemapDice scales by (x1-x0)/parent.value and the frames render past
		// its right edge, over every sibling. Flame_Tree::cover_children() got
		// this rule when positions arrived; the merging fold needs it too.
		$tree = Flame_Fold::tree( $this->fold(
			[
				$this->at( 'outer (start)', 0 ),
				$this->at( 'db (start)', 0 ),
				$this->at( 'db (complete)', 0.1, [ 'duration_ms' => 100 ] ),
				// Opens 8s later (the helper takes MILLISECONDS): 7.9s of gap
				// the summed value knows nothing about.
				$this->at( 'http (start)', 8000 ),
				$this->at( 'http (complete)', 8050, [ 'duration_ms' => 50 ] ),
				$this->at( 'outer (complete)', 8060, [ 'duration_ms' => 200 ] ),
			]
		) );

		$outer = $tree['children'][0];
		$this->assertSame( 'outer', $outer['name'] );
		// db starts at 0 and http ends at 8000 + 50; the sum is only 150.
		$this->assertEqualsWithDelta( 8050.0, $outer['value'], 1e-6 );
	}

	public function test_a_merged_node_carries_the_offset_it_first_started_at(): void {
		// The log renders these nodes as rows and needs a real clock position
		// for each; without one the view cannot gap them and a "+Xms" caption
		// is a timestamp in all but name.
		$entries = [];
		foreach ( [ 3, 41, 7 ] as $i => $ms ) {
			$entries[] = $this->at( 'save (start)', 100 + $i * 50 );
			$entries[] = $this->at( 'save (complete)', 100 + $i * 50 + $ms, [ 'duration_ms' => $ms ] );
		}

		$tree = Flame_Fold::tree( $this->fold( $entries ) );
		$save = $tree['children'][0];
		$this->assertSame( 'save', $save['name'] );
		$this->assertSame( 3, $save['count'] );
		// The EARLIEST of the three, not the last-seen or the slowest.
		$this->assertEqualsWithDelta( 100.0, $save['t'], 1e-6 );
	}

	public function test_a_merged_node_counts_its_instances_and_totals_them(): void {
		// Merging spends the sequence; the node buys back "how many, when did
		// they start, and what did they cost altogether".
		$entries = [];
		foreach ( [ 3, 41, 7 ] as $i => $ms ) {
			$entries[] = $this->at( 'save (start)', 100 + $i * 50 );
			$entries[] = $this->at( 'save (complete)', 100 + $i * 50 + $ms, [ 'duration_ms' => $ms ] );
		}

		$children = Flame_Fold::tree( $this->fold( $entries ) )['children'];
		$this->assertCount( 1, $children, 'three instances of one path are one node' );
		$this->assertSame( 3, $children[0]['count'] );
		// Inclusive total, as every duration the log shows is: 3 + 41 + 7.
		$this->assertEqualsWithDelta( 51.0, $children[0]['value'], 1e-6 );
		$this->assertEqualsWithDelta( 100.0, $children[0]['t'], 1e-6 );
	}

	public function test_an_orphan_complete_is_dropped_not_guessed_at(): void {
		$tree = Flame_Fold::tree( $this->fold( [ $this->at( 'ghost (complete)', 5, [ 'duration_ms' => 9 ] ) ] ) );
		$this->assertSame( [], $tree['children'] );
	}

	public function test_a_parent_covers_the_children_that_did_finish(): void {
		// The browser prunes on one value cutoff and assumes a child never
		// exceeds its parent; an unclosed span at 0 deletes its subtree.
		$tree = Flame_Fold::tree( $this->fold(
			[
				$this->at( 'render (start)', 0 ),
				$this->at( 'db (start)', 1 ),
				$this->at( 'db (complete)', 301, [ 'duration_ms' => 300 ] ),
			]
		) );
		// 301, not 300: `db` starts 1ms in, so covering it means spanning to
		// its END. Sized to the child's value alone the parent is 1ms short,
		// and with spacers filling the gap the child renders past its edge.
		$this->assertEqualsWithDelta( 301.0, $tree['children'][0]['value'], 1e-6 );
		$this->assertEqualsWithDelta( 301.0, $tree['value'], 1e-6 );
	}

	public function test_nesting_deeper_than_the_stack_ceiling_does_not_run_away(): void {
		$entries = [];
		for ( $i = 0; $i < 70; $i++ ) {
			$entries[] = $this->at( "span{$i} (start)", $i );
		}
		$tree  = Flame_Fold::tree( $this->fold( $entries ) );
		$depth = 0;
		$node  = $tree;
		while ( ! empty( $node['children'] ) ) {
			++$depth;
			$node = $node['children'][0];
		}
		$this->assertGreaterThan( 0, $depth );
		$this->assertLessThanOrEqual( 70, $depth );
	}

	public function test_the_root_has_no_offset_but_its_spans_do(): void {
		// `request` is a synthetic wrapper that never opened, so it has no
		// start of its own; every real span does, and the detail view stamps
		// its log rows from them.
		$tree = Flame_Fold::tree( $this->fold(
			[
				$this->at( 'save (start)', 40 ),
				$this->at( 'save (complete)', 45, [ 'duration_ms' => 5 ] ),
			]
		) );
		$this->assertNull( $tree['t'] );
		$this->assertNotNull( $tree['children'][0]['t'] );
	}
}
