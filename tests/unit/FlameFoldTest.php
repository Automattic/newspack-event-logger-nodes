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

	public function test_a_merged_parent_is_not_stretched_to_a_later_instance_child(): void {
		// `t` is the EARLIEST instance's start; `value` is EVERY instance's
		// time. Pairing one instance's start with another's child measures the
		// gap between two runs and calls it work. Sum, the rule aggregate
		// trees already follow, is the only honest width for a merged node.
		$entries = [
			$this->at( 'outer (start)', 0 ),
			$this->at( 'outer (complete)', 7.375, [ 'duration_ms' => 7.375 ] ),
			// Same path, four minutes later, and this one has a child.
			$this->at( 'outer (start)', 240_000 ),
			$this->at( 'inner (start)', 240_002 ),
			$this->at( 'inner (complete)', 240_005.625, [ 'duration_ms' => 3.625 ] ),
			$this->at( 'outer (complete)', 240_011.125, [ 'duration_ms' => 11.125 ] ),
		];

		$outer = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];
		$this->assertSame( 'outer', $outer['name'] );
		$this->assertSame( 2, $outer['count'] );
		$this->assertEqualsWithDelta( 0.0, $outer['t'], 1e-6 );
		// 7.375 + 11.125, not 240_002 + 3.625 - 0.
		$this->assertEqualsWithDelta( 18.5, $outer['value'], 1e-6 );
	}

	public function test_a_merged_child_does_not_stretch_its_parent(): void {
		// The parent runs once, so its own extent is honest; the merged CHILD
		// is what has no end, and reading one off it reaches past the request.
		$entries = [
			$this->at( 'outer (start)', 0 ),
			$this->at( 'leaf (start)', 4.25 ),
			$this->at( 'leaf (complete)', 10.5, [ 'duration_ms' => 6.25 ] ),
			$this->at( 'leaf (start)', 180_000 ),
			$this->at( 'leaf (complete)', 180_013.75, [ 'duration_ms' => 13.75 ] ),
			$this->at( 'outer (complete)', 180_020, [ 'duration_ms' => 21.5 ] ),
		];

		$outer = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];
		$this->assertSame( 2, $outer['children'][0]['count'] );
		// Its own 21.5 stands; 4.25 + 20.0 reads the first instance's start
		// against a total spanning both, and overstates the parent by 2.75.
		$this->assertEqualsWithDelta( 21.5, $outer['value'], 1e-6 );
	}

	public function test_a_span_opened_twice_but_closed_once_is_still_merged(): void {
		// `count` rises only on (complete), so two starts and one complete read
		// as a single instance while `t` already holds the FIRST start's offset.
		// close() manufactures that state itself: it splices off every frame
		// above the one it matches, so a span outliving its parent is left
		// open — and free to be opened again later.
		$entries = [
			$this->at( 'p (start)', 10 ),
			$this->at( 'p (complete)', 23.25, [ 'duration_ms' => 13.25 ] ),
			// Second run four minutes on, and only this one has a child.
			$this->at( 'p (start)', 240_000 ),
			$this->at( 'c (start)', 240_002 ),
			$this->at( 'c (complete)', 240_008.75, [ 'duration_ms' => 6.75 ] ),
		];

		$p = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];
		$this->assertSame( 'p', $p['name'] );
		$this->assertSame( 1, $p['count'], 'one of the two runs completed' );
		// Its own 13.25 stands; 240_002 + 6.75 - 10 is the gap between runs.
		$this->assertEqualsWithDelta( 13.25, $p['value'], 1e-6 );
		$this->assertTrue( $p['merged'], 'two starts is merged, whatever count says' );
	}

	public function test_a_state_restored_from_before_starts_existed_is_not_stretched(): void {
		// A checkpoint written by an older worker carries no `starts`, and a
		// missing key reading as 0 would say "one span" — the old extent bug,
		// back for every in-flight request across a deploy. Completions are the
		// floor: a path can never close more often than it opened.
		$state         = Flame_Fold::start( self::ORIGIN );
		$state['root'] = [
			'value'    => 0.0,
			'count'    => 0,
			'max'      => 0.0,
			't'        => null,
			'children' => [
				'outer' => [
					'value'    => 812.5,
					'count'    => 4,
					'max'      => 406.25,
					't'        => 60.25,
					'children' => [
						'inner' => [
							'value'    => 9.5,
							'count'    => 1,
							'max'      => 9.5,
							't'        => 300_000.75,
							'children' => [],
						],
					],
				],
			],
		];

		$outer = Flame_Fold::tree( $state )['children'][0];
		$this->assertSame( 'outer', $outer['name'] );
		$this->assertTrue( $outer['merged'], 'four completions is four spans' );
		// 812.5 stands; 300_000.75 + 9.5 - 60.25 is the gap between two runs.
		$this->assertEqualsWithDelta( 812.5, $outer['value'], 1e-6 );
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
