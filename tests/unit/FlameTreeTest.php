<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * Unit tests for the pure flame-graph algorithm split out of Flame_Builder_Node:
 * tree construction (LIFO span matching), duplicate-sibling numbering, suffix
 * stripping, incremental merge, and finalize. Exercised directly here now that
 * it's a standalone helper.
 */
#[CoversClass( Flame_Tree::class )]
class FlameTreeTest extends TestCase {

	private const NOW = 1_700_000_000;

	private function entry( string $k, array $extra = [] ): array {
		return \array_merge( [ 'k' => $k ], $extra );
	}

	// ----- build_flame_data -----

	public function test_the_expiry_window_has_exactly_one_owner(): void {
		// Flame nodes and the profile categories of the same aggregate must age
		// out on one clock; two private copies could only be held equal by hand.
		$this->assertSame( 3600, Flame_Tree::AGGREGATE_EXPIRY_SEC );
		$this->assertFalse(
			( new \ReflectionClass( \Newspack_Event_Logger_Nodes\Flame_Builder_Node::class ) )
				->hasConstant( 'AGGREGATE_EXPIRY_SEC' ),
			'Flame_Builder_Node must read the window, not keep a copy of it'
		);
	}

	public function test_an_unclosed_span_still_covers_its_completed_children(): void {
		// The request died before `render (complete)` was logged, but the two
		// spans beneath it did finish.
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'render (start)' ),
				$this->entry( 'db (start)' ),
				$this->entry( 'db (complete)', [ 'duration_ms' => 300, 'ts' => self::NOW ] ),
				$this->entry( 'tpl (start)' ),
				$this->entry( 'tpl (complete)', [ 'duration_ms' => 450, 'ts' => self::NOW ] ),
			],
			self::NOW
		);

		$render = $tree['children'][0];
		$this->assertSame( 'render', $render['name'] );
		// The browser prunes on a single value cutoff and assumes a child never
		// exceeds its parent; a 0 here deletes both children with it.
		$this->assertEqualsWithDelta( 750.0, $render['value'], 1e-6 );
		$this->assertEqualsWithDelta( 750.0, $tree['value'], 1e-6 );
	}

	public function test_build_matches_start_and_complete_into_a_span(): void {
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'db (start)' ),
				$this->entry( 'db (complete)', [ 'duration_ms' => 42, 'ts' => self::NOW ] ),
			],
			self::NOW
		);
		$this->assertSame( 'request', $tree['name'] );
		$this->assertSame( 'db', $tree['children'][0]['name'] );
		$this->assertSame( 42, $tree['children'][0]['value'] );
	}

	public function test_build_uses_label_and_detail(): void {
		$tree = Flame_Tree::build_flame_data(
			[ $this->entry( 'hook (start)', [ 'l' => 'init', 'm' => 'the_content' ] ) ],
			self::NOW
		);
		$this->assertSame( 'hook: init', $tree['children'][0]['name'] );
		$this->assertSame( 'hook: the_content', $tree['children'][0]['detail'] );
	}

	public function test_build_skips_non_array_entries(): void {
		$tree = Flame_Tree::build_flame_data( [ 'not-an-array', 42, $this->entry( 'x (start)' ) ], self::NOW );
		$this->assertCount( 1, $tree['children'] );
	}

	public function test_build_ignores_an_orphan_complete(): void {
		$tree = Flame_Tree::build_flame_data(
			[ $this->entry( 'ghost (complete)', [ 'duration_ms' => 9 ] ) ],
			self::NOW
		);
		$this->assertSame( [], $tree['children'] );
	}

	public function test_build_leaves_orphaned_children_when_parent_completes(): void {
		// child outlives parent: parent completes while child is still open → child stays (value 0).
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'parent (start)' ),
				$this->entry( 'child (start)' ),
				$this->entry( 'parent (complete)', [ 'duration_ms' => 5 ] ),
			],
			self::NOW
		);
		$this->assertSame( 'parent', $tree['children'][0]['name'] );
		$this->assertSame( 'child', $tree['children'][0]['children'][0]['name'] );
		$this->assertSame( 0, $tree['children'][0]['children'][0]['value'] );
	}

	public function test_build_caps_stack_depth(): void {
		// 60 nested starts (> MAX_STACK_DEPTH=50): the deepest ones aren't pushed but don't error.
		$entries = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$entries[] = $this->entry( "span{$i} (start)" );
		}
		$tree = Flame_Tree::build_flame_data( $entries, self::NOW );
		$depth = 0;
		$node  = $tree;
		while ( ! empty( $node['children'] ) ) {
			++$depth;
			$node = $node['children'][0];
		}
		$this->assertGreaterThan( 0, $depth );
		$this->assertLessThanOrEqual( 60, $depth );
	}

	public function test_build_numbers_duplicate_siblings(): void {
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'q (start)' ),
				$this->entry( 'q (complete)', [ 'duration_ms' => 1 ] ),
				$this->entry( 'q (start)' ),
				$this->entry( 'q (complete)', [ 'duration_ms' => 2 ] ),
			],
			self::NOW
		);
		$names = \array_map( static fn ( $c ) => $c['name'], $tree['children'] );
		$this->assertSame( [ "q\x001", "q\x002" ], $names );
	}

	// ----- strip_name_suffixes -----

	public function test_strip_removes_null_suffix_recursively(): void {
		$node = [
			'name'     => "a\x001",
			'children' => [ [ 'name' => "b\x002", 'children' => [] ] ],
		];
		Flame_Tree::strip_name_suffixes( $node );
		$this->assertSame( 'a', $node['name'] );
		$this->assertSame( 'b', $node['children'][0]['name'] );
	}

	public function test_strip_stops_at_max_depth(): void {
		$deep = $this->nested_chain( 55, static fn ( int $d ) => [ 'name' => "n{$d}\x001", 'children' => [] ] );
		Flame_Tree::strip_name_suffixes( $deep );
		$this->assertSame( 'n0', $deep['name'] ); // top stripped; recursion bails past depth 50 without error.
	}

	// ----- merge_flame_children_incremental -----

	public function test_merge_adds_new_children_and_sums_existing(): void {
		$existing = [ [ 'name' => 'db', 'sum_value' => 10.0, 'seen_count' => 1, 'ts' => self::NOW, 'children' => [] ] ];
		$incoming = [
			[ 'name' => 'db', 'value' => 5, 'ts' => self::NOW ],
			[ 'name' => 'cache', 'value' => 3, 'ts' => self::NOW ],
		];
		$merged = Flame_Tree::merge_flame_children_incremental( $existing, $incoming, self::NOW );
		$by     = [];
		foreach ( $merged as $c ) {
			$by[ $c['name'] ] = $c;
		}
		$this->assertSame( 15.0, $by['db']['sum_value'] );
		$this->assertSame( 2, $by['db']['seen_count'] );
		$this->assertSame( 3.0, $by['cache']['sum_value'] );
		$this->assertSame( 1, $by['cache']['seen_count'] );
	}

	public function test_merge_recurses_into_children(): void {
		$incoming = [ [ 'name' => 'a', 'value' => 1, 'ts' => self::NOW, 'children' => [ [ 'name' => 'a1', 'value' => 1, 'ts' => self::NOW ] ] ] ];
		$merged   = Flame_Tree::merge_flame_children_incremental( [], $incoming, self::NOW );
		$this->assertSame( 'a1', $merged[0]['children'][0]['name'] );
	}

	public function test_merge_expires_entries_older_than_an_hour(): void {
		$existing = [ [ 'name' => 'stale', 'sum_value' => 1.0, 'seen_count' => 1, 'ts' => self::NOW - 4000, 'children' => [] ] ];
		$merged   = Flame_Tree::merge_flame_children_incremental( $existing, [], self::NOW );
		$this->assertSame( [], $merged );
	}

	public function test_merge_stops_at_max_depth(): void {
		$incoming = [ $this->nested_chain( 55, static fn ( int $d ) => [ 'name' => "d{$d}", 'value' => 1, 'ts' => self::NOW, 'children' => [] ] ) ];
		$merged   = Flame_Tree::merge_flame_children_incremental( [], $incoming, self::NOW );
		$this->assertSame( 'd0', $merged[0]['name'] ); // bails past depth 50 without error.
	}

	// ----- finalize_flame_node -----

	public function test_finalize_converts_sum_to_average(): void {
		$node = [ 'name' => 'db', 'sum_value' => 30.0, 'seen_count' => 3, 'children' => [] ];
		Flame_Tree::finalize_flame_node( $node, 3 );
		$this->assertSame( 10.0, $node['value'] );
		$this->assertArrayNotHasKey( 'sum_value', $node );
		$this->assertArrayNotHasKey( 'seen_count', $node );
	}

	public function test_finalize_defaults_missing_value_to_zero(): void {
		$node = [ 'name' => 'x', 'children' => [] ];
		Flame_Tree::finalize_flame_node( $node, 0 );
		$this->assertSame( 0, $node['value'] );
	}

	public function test_finalize_normalizes_parent_to_at_least_children_sum(): void {
		$node = [
			'name'      => 'p',
			'sum_value' => 2.0,
			'children'  => [
				[ 'name' => 'c1', 'sum_value' => 4.0, 'children' => [] ],
				[ 'name' => 'c2', 'sum_value' => 4.0, 'children' => [] ],
			],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertSame( 8.0, $node['value'] ); // 2 < (4+4) → bumped to children sum.
	}

	public function test_finalize_strips_suffix_and_stops_at_max_depth(): void {
		$deep = $this->nested_chain( 55, static fn ( int $d ) => [ 'name' => "n{$d}\x001", 'value' => 1, 'children' => [] ] );
		Flame_Tree::finalize_flame_node( $deep, 1 );
		$this->assertSame( 'n0', $deep['name'] );
	}

	/**
	 * Build a $depth-deep single-child chain; $make(level) supplies each node's own fields.
	 *
	 * @param callable(int):array<string,mixed> $make
	 * @return array<string,mixed>
	 */
	private function nested_chain( int $depth, callable $make ): array {
		$node = $make( $depth );
		for ( $d = $depth - 1; $d >= 0; $d-- ) {
			$parent             = $make( $d );
			$parent['children'] = [ $node ];
			$node               = $parent;
		}
		return $node;
	}
}
