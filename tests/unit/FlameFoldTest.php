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
use Newspack_Event_Logger_Nodes\Flame_Tree;
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

	public function test_a_merged_node_keeps_the_key_and_label_it_was_logged_with(): void {
		// The tree merges on `key: label`; the rows a folded request shows
		// are built from the entry's own two fields, carried as logged.
		$tree = Flame_Fold::tree(
			$this->fold(
				[
					$this->at( 'the_content hook (start)', 0, [ 'l' => 'wp_trim_excerpt' ] ),
					$this->at( 'the_content hook (complete)', 5, [ 'duration_ms' => 5 ] ),
					$this->at( 'the_content hook (start)', 6, [ 'l' => 'wp_trim_excerpt' ] ),
					$this->at( 'the_content hook (complete)', 9, [ 'duration_ms' => 3 ] ),
				]
			)
		);
		$node = $tree['children'][0];
		$this->assertSame( 'request', $tree['k'] );
		$this->assertSame( 'the_content hook: wp_trim_excerpt', $node['name'] );
		$this->assertSame( 'the_content hook', $node['k'] ?? null );
		$this->assertSame( 'wp_trim_excerpt', $node['l'] ?? null );
	}

	public function test_a_folded_frame_is_named_as_an_unfolded_one(): void {
		// One naming rule for both trees; a label of "0" is still a label.
		$entries = [
			$this->at( 'hook (start)', 0, [ 'l' => '0' ] ),
			$this->at( 'hook (complete)', 5, [ 'duration_ms' => 5 ] ),
		];
		$folded   = Flame_Fold::tree( $this->fold( $entries ) )['children'][0]['name'];
		$unfolded = Flame_Tree::build_flame_data( $entries )['children'][0]['name'];
		$this->assertSame( $unfolded, $folded );
		$this->assertSame( 'hook: 0', $folded );
	}

	public function test_a_merged_node_keeps_the_key_and_label_it_first_opened_with(): void {
		// `a` labelled `b` and an unlabelled `a: b` merge on one name; the node
		// keeps the first pair rather than flipping on every open.
		$tree = Flame_Fold::tree(
			$this->fold(
				[
					$this->at( 'a (start)', 0, [ 'l' => 'b' ] ),
					$this->at( 'a (complete)', 1, [ 'duration_ms' => 1 ] ),
					$this->at( 'a: b (start)', 2 ),
					$this->at( 'a: b (complete)', 3, [ 'duration_ms' => 1 ] ),
				]
			)
		);
		$node = $tree['children'][0];
		$this->assertSame( [ 'a', 'b' ], [ $node['k'], $node['l'] ] );
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
			'k'        => 'request',
			'l'        => '',
			'value'    => 0.0,
			'count'    => 0,
			'max'      => 0.0,
			't'        => null,
			'children' => [
				'outer' => [
					'k'        => 'outer',
					'l'        => '',
					'value'    => 812.5,
					'count'    => 4,
					'max'      => 406.25,
					't'        => 60.25,
					'children' => [
						'inner' => [
							'k'        => 'inner',
							'l'        => '',
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

	public function test_a_node_restored_without_its_key_and_label_emits_neither(): void {
		// A request still folding across a deploy restores nodes that never
		// recorded them; they are left off rather than defaulted or warned on.
		$state         = Flame_Fold::start( self::ORIGIN );
		$state['root'] = [
			'k'        => 'request',
			'l'        => '',
			'value'    => 0.0,
			'count'    => 0,
			'starts'   => 0,
			'max'      => 0.0,
			't'        => null,
			'children' => [
				'outer' => [
					'value'    => 4.5,
					'count'    => 1,
					'starts'   => 1,
					'max'      => 4.5,
					't'        => 1.5,
					'children' => [],
				],
			],
		];

		$outer = Flame_Fold::tree( $state )['children'][0];
		$this->assertSame( 'outer', $outer['name'] );
		$this->assertArrayNotHasKey( 'k', $outer );
		$this->assertArrayNotHasKey( 'l', $outer );
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

	public function test_a_merged_query_node_keeps_every_shape_it_ran(): void {
		$shapes  = [
			'SELECT * FROM wp_posts WHERE ID = ?',
			'SELECT * FROM wp_postmeta WHERE post_id IN (?)',
		];
		$entries = [];
		foreach ( [ 0, 1, 0, 0 ] as $i => $which ) {
			$entries[] = $this->at( 'sql (start)', $i * 10, [ 'l' => 'WP_Query->get_posts' ] );
			$entries[] = $this->at(
				'sql (complete)',
				$i * 10 + 5,
				[ 'duration_ms' => 2 + $i, 'm' => $shapes[ $which ] ]
			);
		}

		$node = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];

		$this->assertSame( 'sql: WP_Query->get_posts', $node['name'] );
		$this->assertSame( 4, $node['count'] );
		// Count and summed ms per shape: 2 + 4 + 5 against 3.
		$this->assertSame(
			[
				$shapes[0] => [ 3, 11.0 ],
				$shapes[1] => [ 1, 3.0 ],
			],
			$node['shapes']
		);
	}

	public function test_a_hook_span_carries_no_shapes(): void {
		// A hook's message is prose of any size and any cardinality; only a
		// query shape and a redacted URL are bounded by construction.
		$node = Flame_Fold::tree(
			$this->fold(
				[
					$this->at( 'the_content hook (start)', 0, [ 'l' => 'wp_trim_excerpt' ] ),
					$this->at( 'the_content hook (complete)', 5, [ 'duration_ms' => 9, 'm' => 'a paragraph of post content' ] ),
				]
			)
		)['children'][0];

		$this->assertArrayNotHasKey( 'shapes', $node );
	}

	public function test_an_http_node_keeps_the_url_its_span_opened_with(): void {
		// The URL is on the START; the complete carries the status code, so
		// reading the close alone builds a table of `200`s.
		$entries = [];
		foreach ( [ 0, 1 ] as $i ) {
			$entries[] = $this->at( 'http (start)', $i * 10, [ 'l' => 'wp_remote_get', 'm' => 'https://api.test/v1/items' ] );
			$entries[] = $this->at( 'http (complete)', $i * 10 + 5, [ 'duration_ms' => 4 + $i, 'm' => '200' ] );
		}

		$node = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];

		$this->assertSame( [ 'https://api.test/v1/items' => [ 2, 9.0 ] ], $node['shapes'] );
	}

	public function test_the_worst_statement_survives_a_full_table(): void {
		// First-seen-wins would bury it: the slow one arrives last, and the
		// table is what `Findings` names the dominant span's statement from.
		$entries = [];
		for ( $i = 0; $i <= Flame_Fold::MAX_SHAPES; $i++ ) {
			$entries[] = $this->at( 'sql (start)', $i * 10, [ 'l' => 'WP_Query->get_posts' ] );
			$entries[] = $this->at(
				'sql (complete)',
				$i * 10 + 5,
				[ 'duration_ms' => $i < Flame_Fold::MAX_SHAPES ? 1.0 : 400.0, 'm' => "SELECT c{$i} FROM wp_posts WHERE ID = ?" ]
			);
		}

		$shapes = Flame_Fold::tree( $this->fold( $entries ) )['children'][0]['shapes'];

		$slowest = 'SELECT c' . Flame_Fold::MAX_SHAPES . ' FROM wp_posts WHERE ID = ?';
		$this->assertSame( [ 1, 400.0 ], $shapes[ $slowest ] ?? null );
		$this->assertCount( Flame_Fold::MAX_SHAPES, $shapes, 'the overflow bucket counts inside the cap' );
		// Two of the cheap ones were evicted into it, whole.
		$this->assertSame( [ 2, 2.0 ], $shapes[ Flame_Fold::SHAPES_OVERFLOW ] );
	}

	public function test_the_record_stops_taking_new_shapes_once_its_budget_is_spent(): void {
		// The per-node caps bound a node; nothing bounds a record, whose node
		// count is O(distinct paths) — 117 transport nodes on the heaviest
		// real folded record here, so a per-node worst case multiplies.
		$each    = \str_repeat( 'x', Flame_Fold::MAX_SHAPE_BYTES );
		$nodes   = (int) \ceil( Flame_Fold::MAX_RECORD_SHAPE_BYTES / Flame_Fold::MAX_SHAPE_BYTES ) + 2;
		$entries = [];
		for ( $i = 0; $i < $nodes; $i++ ) {
			$entries[] = $this->at( 'sql (start)', $i, [ 'l' => "caller{$i}" ] );
			$entries[] = $this->at( 'sql (complete)', $i + 0.5, [ 'duration_ms' => 1.0, 'm' => "SELECT {$i} {$each}" ] );
		}

		$children = Flame_Fold::tree( $this->fold( $entries ) )['children'];

		$first = $children[0]['shapes'];
		$last  = $children[ $nodes - 1 ]['shapes'];
		$this->assertArrayNotHasKey( Flame_Fold::SHAPES_OVERFLOW, $first, 'the budget was whole when this one ran' );
		$this->assertSame( [ 1, 1.0 ], $last[ Flame_Fold::SHAPES_OVERFLOW ], 'past the budget only the bucket takes them' );
		$this->assertCount( 1, $last );
	}

	public function test_statements_the_bucket_swallowed_cost_the_record_nothing(): void {
		// One caller running hundreds of cheap distinct statements stores none
		// of them past its cap; charging the record for each would strip every
		// other node's table — measured at 7.1x the bytes actually stored.
		$entries = [];
		$at      = 0.0;
		for ( $i = 0; $i < 400; $i++ ) {
			$entries[] = $this->at( 'sql (start)', $at, [ 'l' => 'chatty' ] );
			$entries[] = $this->at( 'sql (complete)', ++$at, [ 'duration_ms' => 0.01, 'm' => "SELECT {$i} FROM wp_options WHERE option_name = ?" ] );
		}
		$entries[] = $this->at( 'sql (start)', ++$at, [ 'l' => 'ordinary' ] );
		$entries[] = $this->at( 'sql (complete)', ++$at, [ 'duration_ms' => 9.0, 'm' => 'SELECT * FROM wp_posts WHERE ID = ?' ] );

		$nodes = Flame_Fold::tree( $this->fold( $entries ) )['children'];
		$last  = $nodes[ \count( $nodes ) - 1 ];

		$this->assertSame( 'sql: ordinary', $last['name'] );
		$this->assertSame( [ 1, 9.0 ], $last['shapes']['SELECT * FROM wp_posts WHERE ID = ?'] ?? null );
	}

	public function test_a_frame_restored_without_its_shape_takes_no_http_status_code(): void {
		// A worker restarting mid-request leaves a checkpoint frame with no
		// shape; for http the complete's `m` is the status code, not the URL.
		$state = Flame_Fold::start( self::ORIGIN );
		Flame_Fold::add( $state, $this->at( 'http (start)', 0, [ 'l' => 'wp_remote_get', 'm' => 'https://api.test/v1' ] ) );
		foreach ( \array_keys( $state['stack'] ) as $i ) {
			unset( $state['stack'][ $i ]['shape'] );
		}
		Flame_Fold::add( $state, $this->at( 'http (complete)', 5, [ 'duration_ms' => 42.0, 'm' => '200' ] ) );

		$this->assertArrayNotHasKey( 'shapes', Flame_Fold::tree( $state )['children'][0] );
	}

	public function test_a_cut_shape_stays_valid_utf8(): void {
		// The cut lands mid-character on a multibyte statement; a torn
		// sequence would break every JSON encode the record passes through.
		$entries = [
			$this->at( 'sql (start)', 0, [ 'l' => 'unicode' ] ),
			$this->at( 'sql (complete)', 5, [ 'duration_ms' => 1.0, 'm' => \str_repeat( 'é', Flame_Fold::MAX_SHAPE_BYTES ) ] ),
		];

		$shapes = Flame_Fold::tree( $this->fold( $entries ) )['children'][0]['shapes'];
		$cut    = (string) \array_key_first( $shapes );

		$this->assertSame( 1, \preg_match( '//u', $cut ), 'the cut tore a character in half' );
		$this->assertLessThanOrEqual( Flame_Fold::MAX_SHAPE_BYTES, \strlen( $cut ) );
		// Trimming the whole trailing run, rather than the torn sequence,
		// leaves the bucket's own key and loses the statement into it.
		$this->assertNotSame( Flame_Fold::SHAPES_OVERFLOW, $cut );
		$this->assertGreaterThan( Flame_Fold::MAX_SHAPE_BYTES - 8, \strlen( $cut ) );
	}

	public function test_the_shape_table_caps_its_keys_and_their_length(): void {
		$entries = [];
		for ( $i = 0; $i < Flame_Fold::MAX_SHAPES + 3; $i++ ) {
			$entries[] = $this->at(
				'http (start)',
				$i * 10,
				[ 'l' => 'wp_remote_get', 'm' => 'https://api.test/' . \str_repeat( "p{$i}/", 400 ) ]
			);
			$entries[] = $this->at( 'http (complete)', $i * 10 + 5, [ 'duration_ms' => 7, 'm' => '200' ] );
		}

		$shapes = Flame_Fold::tree( $this->fold( $entries ) )['children'][0]['shapes'];

		// The bucket is one of the capped keys, never an extra; the `200`s
		// every complete carried are not a shape and never reach it.
		$this->assertCount( Flame_Fold::MAX_SHAPES, $shapes );
		$this->assertSame( [ 4, 28.0 ], $shapes[ Flame_Fold::SHAPES_OVERFLOW ] );
		foreach ( \array_keys( $shapes ) as $key ) {
			$this->assertLessThanOrEqual( Flame_Fold::MAX_SHAPE_BYTES, \strlen( $key ) );
		}
	}

	public function test_the_bucket_never_evicts_a_statement_to_make_its_own_room(): void {
		// Its slot is reserved inside the cap, so folding INTO it costs a row
		// nothing — the budget spends itself through this path on every record.
		$shapes = [];
		for ( $i = 0; $i < Flame_Fold::MAX_SHAPES - 1; $i++ ) {
			Flame_Fold::fold_shape( $shapes, "SELECT {$i}", 1, 1.0 );
		}

		$took = Flame_Fold::fold_shape( $shapes, Flame_Fold::SHAPES_OVERFLOW, 1, 50.0 );

		$this->assertCount( Flame_Fold::MAX_SHAPES, $shapes );
		$this->assertSame( [ 1, 1.0 ], $shapes['SELECT 0'] ?? null, 'a real row paid for the bucket' );
		// Its own row, charged like any other; never a refund from an eviction.
		$this->assertGreaterThan( 0, $took );
	}

	public function test_a_wire_table_carries_numbers_a_reader_can_use(): void {
		// The browser destructures each row; a row the wire left as anything
		// but [ calls, ms ] takes down the whole request-detail render. A
		// checkpoint restored through JSON is where a stringy row comes from.
		$state = $this->fold(
			[
				$this->at( 'sql (start)', 0, [ 'l' => 'q' ] ),
				$this->at( 'sql (complete)', 5, [ 'duration_ms' => 3, 'm' => 'SELECT ?' ] ),
			]
		);
		$state['root']['children']['sql: q']['shapes'] = [ 'SELECT ?' => [ '4', '9.5' ] ];

		$row = Flame_Fold::tree( $state )['children'][0]['shapes']['SELECT ?'];

		$this->assertSame( 4, $row[0] );
		$this->assertSame( 9.5, $row[1] );
	}

	public function test_a_spent_budget_still_counts_a_statement_already_kept(): void {
		// A row the node holds costs no new bytes, so bucketing its repeats
		// saves nothing and understates the statement a finding names.
		$each    = \str_repeat( 'x', Flame_Fold::MAX_SHAPE_BYTES );
		$kept    = 'SELECT kept FROM wp_posts WHERE ID = ?';
		$entries = [
			$this->at( 'sql (start)', 0, [ 'l' => 'ordinary' ] ),
			$this->at( 'sql (complete)', 1, [ 'duration_ms' => 5.0, 'm' => $kept ] ),
		];
		$at      = 2.0;
		$spenders = (int) \ceil( Flame_Fold::MAX_RECORD_SHAPE_BYTES / Flame_Fold::MAX_SHAPE_BYTES ) + 2;
		for ( $i = 0; $i < $spenders; $i++ ) {
			$entries[] = $this->at( 'sql (start)', $at, [ 'l' => "caller{$i}" ] );
			$entries[] = $this->at( 'sql (complete)', ++$at, [ 'duration_ms' => 1.0, 'm' => "SELECT {$i} {$each}" ] );
		}
		// The budget is spent by now; this repeat is already on the node.
		$entries[] = $this->at( 'sql (start)', ++$at, [ 'l' => 'ordinary' ] );
		$entries[] = $this->at( 'sql (complete)', ++$at, [ 'duration_ms' => 7.0, 'm' => $kept ] );

		$ordinary = Flame_Fold::tree( $this->fold( $entries ) )['children'][0];

		$children = Flame_Fold::tree( $this->fold( $entries ) )['children'];
		$last     = $children[ \count( $children ) - 1 ];

		$this->assertSame( 'sql: ordinary', $ordinary['name'] );
		$this->assertSame( [ 2, 12.0 ], $ordinary['shapes'][ $kept ] ?? null );
		$this->assertArrayNotHasKey( Flame_Fold::SHAPES_OVERFLOW, $ordinary['shapes'] );
		// The premise: the spenders really did exhaust the budget, so the
		// repeat above was kept by the guard and not by a budget still whole.
		$this->assertArrayHasKey( Flame_Fold::SHAPES_OVERFLOW, $last['shapes'] );
	}

	public function test_a_spent_budget_still_lets_a_full_table_trade_evenly(): void {
		// An even trade costs the record nothing, so the worst statement still
		// displaces the cheapest — which is the whole point of eviction.
		$shapes = [];
		for ( $i = 0; $i < Flame_Fold::MAX_SHAPES - 1; $i++ ) {
			Flame_Fold::fold_shape( $shapes, "SELECT {$i}", 1, 0.5 );
		}

		$took = Flame_Fold::fold_shape( $shapes, 'SELECT !', 1, 2000.0, true );

		$this->assertSame( [ 1, 2000.0 ], $shapes['SELECT !'] ?? null );
		$this->assertCount( Flame_Fold::MAX_SHAPES, $shapes );
		// Key for key, plus the bucket row the eviction made. Exact accounting
		// is what keeps a spent record from growing through this path.
		$this->assertSame( \strlen( Flame_Fold::SHAPES_OVERFLOW ) + 128, $took );
	}

	public function test_a_spent_budget_refuses_a_trade_that_would_grow_the_record(): void {
		// While spent, the worst statement may still displace the cheapest —
		// but only where it costs nothing. A longer key would carry the record
		// past its budget one full table at a time, measured at 3.2x.
		$shapes = [];
		for ( $i = 0; $i < Flame_Fold::MAX_SHAPES - 1; $i++ ) {
			Flame_Fold::fold_shape( $shapes, "SELECT {$i}", 1, 0.5 );
		}
		$long = 'SELECT ' . \str_repeat( 'c', 200 );

		$took = Flame_Fold::fold_shape( $shapes, $long, 1, 2000.0, true );

		$this->assertArrayNotHasKey( $long, $shapes );
		$this->assertSame( [ 1, 2000.0 ], $shapes[ Flame_Fold::SHAPES_OVERFLOW ] ?? null );
		$this->assertLessThanOrEqual( \strlen( Flame_Fold::SHAPES_OVERFLOW ) + 128, $took );
	}

	public function test_the_shapes_a_record_holds_stay_inside_its_budget(): void {
		// The invariant the caps exist for, over the stream that breaks it:
		// many callers, short cheap statements filling each table, then long
		// slow ones that would each trade a short key for a long one.
		$entries = [];
		$at      = 0.0;
		for ( $caller = 0; $caller < 40; $caller++ ) {
			for ( $i = 0; $i < 30; $i++ ) {
				$long      = $i > 22 ? \str_repeat( 'c', 400 ) : '';
				$entries[] = $this->at( 'sql (start)', $at, [ 'l' => "caller{$caller}" ] );
				$entries[] = $this->at(
					'sql (complete)',
					++$at,
					[ 'duration_ms' => $i > 22 ? 900.0 : 0.5, 'm' => "SELECT {$caller}:{$i} {$long}" ]
				);
			}
		}

		$held = 0;
		$walk = static function ( array $node ) use ( &$walk, &$held ): void {
			foreach ( \array_keys( $node['shapes'] ?? [] ) as $shape ) {
				$held += \strlen( (string) $shape );
			}
			foreach ( $node['children'] ?? [] as $child ) {
				$walk( $child );
			}
		};
		$walk( Flame_Fold::tree( $this->fold( $entries ) ) );

		$this->assertGreaterThan( 0, $held );
		$this->assertLessThanOrEqual( Flame_Fold::MAX_RECORD_SHAPE_BYTES, $held );
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
