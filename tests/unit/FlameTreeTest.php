<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Nodes\Core;
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

	/**
	 * Request start as the firehose actually carries it: `microtime( true )`,
	 * unix seconds with a fraction. The fraction is the point — a whole second
	 * here would pass against the truncating `num_int()` this replaced.
	 */
	private const ORIGIN = 1_700_000_000.125;

	private function entry( string $k, array $extra = [] ): array {
		return \array_merge( [ 'k' => $k ], $extra );
	}

	/** An entry stamped $offset_ms into the request, as Log_Manager stamps it. */
	private function at( string $k, float $offset_ms, array $extra = [] ): array {
		return $this->entry( $k, [ 'ts' => self::ORIGIN + $offset_ms / 1000 ] + $extra );
	}

	// ----- build_flame_data: request-relative start times -----

	public function test_a_message_of_zero_is_a_message(): void {
		// Any message but '' is a message, as any label but '' is a label.
		$tree = Flame_Tree::build_flame_data(
			[
				[ 'k' => 'hook (start)', 'm' => '0', 'ts' => 1_700_000_000.0 ],
				[ 'k' => 'hook (complete)', 'duration_ms' => 1, 'ts' => 1_700_000_000.001 ],
			]
		);
		$this->assertSame( '0', $tree['children'][0]['message'] ?? null );
	}

	public function test_build_stamps_each_span_with_its_offset_from_the_request_start(): void {
		$tree = Flame_Tree::build_flame_data(
			[
				$this->at( 'process (start)', 0 ),
				$this->at( 'db (start)', 250.5 ),
				$this->at( 'db (complete)', 300.5, [ 'duration_ms' => 50 ] ),
			]
		);

		$this->assertSame( 0.0, $tree['t'] );
		$process = $tree['children'][0];
		$this->assertSame( 0.0, $process['t'] );
		// Sub-second, and not a multiple of anything: truncation shows up here.
		$this->assertSame( 250.5, $process['children'][0]['t'] );
	}

	public function test_build_positions_count_the_entries_whatever_keys_they_arrived_under(): void {
		// The table and the brief number rows 0.., so the frames must too.
		$tree = Flame_Tree::build_flame_data(
			[
				7  => $this->entry( 'process (start)', [ 'n' => 1 ] ),
				12 => $this->entry( 'sql (start)', [ 'n' => 2 ] ),
				30 => $this->entry( 'sql (complete)', [ 'n' => 3 ] ),
			]
		);
		$process = $tree['children'][0];
		$this->assertSame( 0, $process['i'] );
		$this->assertSame( 1, $process['children'][0]['i'] );
	}

	public function test_build_stamps_each_span_with_the_position_of_the_entry_that_opened_it(): void {
		// A nested render restarts n at 1 under the same rid, so n repeats; the
		// position in the record is what the table finds a clicked frame's row by.
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'publication', [ 'n' => 1 ] ),
				$this->entry( 'process (start)', [ 'n' => 2 ] ),
				$this->entry( 'gyrobase (start)', [ 'n' => 1 ] ),
				$this->entry( 'sql (start)', [ 'n' => 2, 'l' => 'QM_DB->query' ] ),
				$this->entry( 'sql (complete)', [ 'n' => 3, 'm' => 'SELECT slow', 'duration_ms' => 18659 ] ),
				$this->entry( 'gyrobase (complete)', [ 'n' => 4 ] ),
				$this->entry( 'sql (start)', [ 'n' => 3, 'l' => 'QM_DB->query' ] ),
				$this->entry( 'sql (complete)', [ 'n' => 4, 'm' => 'UPDATE fast', 'duration_ms' => 0.3 ] ),
			]
		);
		$this->assertArrayNotHasKey( 'i', $tree );
		$process  = $tree['children'][0];
		$gyrobase = $process['children'][0];
		$this->assertSame( 1, $process['i'] );
		$this->assertSame( 2, $gyrobase['i'] );
		$this->assertSame( 3, $gyrobase['children'][0]['i'] );
		$this->assertSame( 6, $process['children'][1]['i'] );
		$this->assertArrayNotHasKey( 'n', $process, 'n repeats across producers; it names no row' );
	}

	public function test_build_omits_the_offset_entirely_when_no_entry_is_timestamped(): void {
		$tree = Flame_Tree::build_flame_data(
			[ $this->entry( 'db (start)' ), $this->entry( 'db (complete)', [ 'duration_ms' => 7 ] ) ]
		);
		$this->assertArrayNotHasKey( 't', $tree );
		$this->assertArrayNotHasKey( 't', $tree['children'][0] );
	}

	public function test_build_omits_the_offset_for_an_untimed_span_in_a_timed_request(): void {
		// An absent timestamp is unknown, never a negative offset from the origin.
		$tree = Flame_Tree::build_flame_data(
			[ $this->at( 'process (start)', 0 ), $this->entry( 'ghost (start)' ) ]
		);
		$this->assertSame( 0.0, $tree['t'] );
		$this->assertArrayNotHasKey( 't', $tree['children'][0]['children'][0] );
	}

	public function test_an_unclosed_span_covers_to_its_last_childs_end_not_their_sum(): void {
		// 20ms and 50ms of work either side of a 70ms hole: the sum is 70, the
		// extent is 150. Covering by the sum hides exactly the hole a viewer
		// opened the graph to find.
		$tree = Flame_Tree::build_flame_data(
			[
				$this->at( 'process (start)', 0 ),
				$this->at( 'render (start)', 0 ),
				$this->at( 'db (start)', 10 ),
				$this->at( 'db (complete)', 30, [ 'duration_ms' => 20 ] ),
				$this->at( 'tpl (start)', 100 ),
				$this->at( 'tpl (complete)', 150, [ 'duration_ms' => 50 ] ),
			]
		);
		$render = $tree['children'][0]['children'][0];
		$this->assertSame( 'render', $render['name'] );
		$this->assertEqualsWithDelta( 150.0, $render['value'], 1e-6 );
	}

	public function test_overlapping_children_still_fit_inside_their_parent(): void {
		// Two spans that overlap have no honest side-by-side layout, and their
		// extent (300) is SMALLER than their combined width (500). d3 scales
		// children by the parent's value, so covering by the extent would paint
		// them past its right edge.
		$tree = Flame_Tree::build_flame_data(
			[
				$this->at( 'process (start)', 0 ),
				$this->at( 'render (start)', 0 ),
				$this->at( 'outer (start)', 0 ),
				$this->at( 'outer (complete)', 300, [ 'duration_ms' => 300 ] ),
				$this->at( 'orphan (start)', 100 ),
				$this->at( 'orphan (complete)', 300, [ 'duration_ms' => 200 ] ),
			]
		);
		$render = $tree['children'][0]['children'][0];
		$this->assertEqualsWithDelta( 500.0, $render['value'], 1e-6 );
	}

	public function test_the_origin_is_the_earliest_entry_not_the_first_listed(): void {
		// One out-of-order entry must not push every earlier span to a negative
		// offset — there is no such thing as before the request started.
		$tree = Flame_Tree::build_flame_data(
			[
				$this->at( 'late (start)', 900 ),
				$this->at( 'early (start)', 0 ),
			]
		);
		$this->assertSame( 900.0, $tree['children'][0]['t'] );
		$this->assertSame( 0.0, $tree['children'][0]['children'][0]['t'] );
	}

	public function test_merge_never_carries_a_start_offset_into_the_aggregate(): void {
		// Merged siblings span many requests, so no single offset is true of them.
		$merged = Flame_Tree::merge_flame_children_incremental(
			[],
			[ [ 'name' => 'db', 'value' => 5, 't' => 250.5 ] ],
			self::NOW
		);
		$this->assertArrayNotHasKey( 't', $merged[0] );
		$this->assertSame( self::NOW, $merged[0]['ts'] );
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
			]
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
			]
		);
		$this->assertSame( 'request', $tree['name'] );
		$this->assertSame( 'db', $tree['children'][0]['name'] );
		$this->assertSame( 42, $tree['children'][0]['value'] );
	}

	public function test_build_keeps_the_raw_start_message_beside_the_name(): void {
		$tree = Flame_Tree::build_flame_data(
			[ $this->entry( 'hook (start)', [ 'l' => 'init', 'm' => 'the_content' ] ) ]
		);
		$this->assertSame( 'hook: init', $tree['children'][0]['name'] );
		$this->assertSame( 'the_content', $tree['children'][0]['message'] );
	}

	/** A start that carried no message takes the one its complete carries. */
	public function test_a_start_without_a_message_takes_the_completes(): void {
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'sql (start)', [ 'l' => 'WP_Query->get_posts' ] ),
				$this->entry( 'sql (complete)', [ 'duration_ms' => 7, 'm' => 'returned 4417 rows' ] ),
			]
		);
		$this->assertSame( 'returned 4417 rows', $tree['children'][0]['message'] );
	}

	/** The start's own message outranks the complete's. */
	public function test_the_start_message_outranks_the_completes(): void {
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'sql (start)', [ 'l' => 'WP_Query->get_posts', 'm' => 'SELECT ID FROM wp_posts' ] ),
				$this->entry( 'sql (complete)', [ 'duration_ms' => 7, 'm' => 'returned 4417 rows' ] ),
			]
		);
		$this->assertSame( 'SELECT ID FROM wp_posts', $tree['children'][0]['message'] );
	}

	/** A message that only repeats the label, at either end, is no message. */
	public function test_a_message_repeating_the_label_is_dropped(): void {
		$tree = Flame_Tree::build_flame_data(
			[
				$this->entry( 'sql (start)', [ 'l' => 'kaka-5521', 'm' => 'kaka-5521' ] ),
				$this->entry( 'sql (complete)', [ 'duration_ms' => 7, 'm' => 'kaka-5521' ] ),
			]
		);
		$this->assertArrayNotHasKey( 'message', $tree['children'][0] );
	}

	public function test_build_skips_non_array_entries(): void {
		$tree = Flame_Tree::build_flame_data( [ 'not-an-array', 42, $this->entry( 'x (start)' ) ] );
		$this->assertCount( 1, $tree['children'] );
	}

	public function test_build_ignores_an_orphan_complete(): void {
		$tree = Flame_Tree::build_flame_data(
			[ $this->entry( 'ghost (complete)', [ 'duration_ms' => 9 ] ) ]
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
			]
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
		$tree = Flame_Tree::build_flame_data( $entries );
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
			]
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
		$existing = [ [ 'name' => 'db', 'sum_value' => 10.0, 'ts' => self::NOW, 'children' => [] ] ];
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
		$this->assertSame( 3.0, $by['cache']['sum_value'] );
		// No per-node tally: finalize divides by the aggregate's request count.
		$this->assertArrayNotHasKey( 'seen_count', $by['db'] );
	}

	public function test_merge_keeps_the_newest_ts_when_an_older_record_arrives(): void {
		$live     = self::NOW + 913;
		$lagging  = $live - 7200;
		$existing = [ [ 'name' => 'db', 'sum_value' => 10.0, 'ts' => $live, 'children' => [ [ 'name' => 'q', 'sum_value' => 2.0, 'ts' => $live, 'children' => [] ] ] ] ];
		$incoming = [ [ 'name' => 'db', 'value' => 5, 'children' => [ [ 'name' => 'q', 'value' => 1 ] ] ] ];
		$merged   = Flame_Tree::merge_flame_children_incremental( $existing, $incoming, $lagging );
		$this->assertSame( 15.0, $merged[0]['sum_value'] );
		$this->assertSame( $live, $merged[0]['ts'] );
		$this->assertSame( $live, $merged[0]['children'][0]['ts'] );
		// A live record touching neither node keeps both at its cutoff.
		$kept = Flame_Tree::merge_flame_children_incremental( $merged, [], $live + 60 );
		$this->assertSame( 'db', $kept[0]['name'] );
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

	/** `base_name()` inverts `node_name()`: the span's own name, without the label it was logged with. */
	public function test_base_name_inverts_node_name(): void {
		$this->assertSame( 'the_content hook', Flame_Tree::base_name( Flame_Tree::node_name( 'the_content hook', 'Yoast\\WP\\SEO\\Builders\\Indexable_Link_Builder->build' ) ) );
		$this->assertSame( 'sql', Flame_Tree::base_name( 'sql: WP_Query->get_posts' ) );
		$this->assertSame( 'wp_loaded hook', Flame_Tree::base_name( 'wp_loaded hook' ) );
	}

	/**
	 * `base_name()` is the substrate's `spanBaseName()` in PHP. The one case
	 * list is the substrate's `tests/fixtures/span-base-names.json`, read from
	 * the sibling checkout as the bootstrap reads its test helpers, and the
	 * substrate's own jest suite holds the JS function to it.
	 */
	#[DataProvider( 'span_base_name_provider' )]
	public function test_base_name_matches_the_shared_case_list( string $name, string $expected ): void {
		$this->assertSame( $expected, Flame_Tree::base_name( $name ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function span_base_name_provider(): array {
		$cases = \json_decode( (string) \file_get_contents( \dirname( __DIR__, 3 ) . '/newspack-nodes/tests/fixtures/span-base-names.json' ), true );
		\assert( \is_array( $cases ) );
		$out = [];
		foreach ( $cases as $case ) {
			$out[ (string) $case[0] ] = [ (string) $case[1], (string) $case[2] ];
		}
		return $out;
	}

	/** A hook span is known by its base name's suffix, beside the transport and plugin classifiers. */
	public function test_is_hook_span_reads_the_suffix(): void {
		$this->assertTrue( Flame_Tree::is_hook_span( 'wp_loaded hook' ) );
		$this->assertFalse( Flame_Tree::is_hook_span( 'wp_loaded' ) );
		$this->assertFalse( Flame_Tree::is_hook_span( 'sync remote plugin' ) );
	}

	/** A verb's span is `<class> <verb> command`, read by its base's suffix. */
	public function test_is_command_span_reads_the_suffix(): void {
		$this->assertTrue( Flame_Tree::is_command_span( 'Performance_CI urls' . Flame_Tree::COMMAND_SUFFIX ) );
		$this->assertTrue( Flame_Tree::is_command_span( 'Rules_CI dump command: traced' ) );
		$this->assertFalse( Flame_Tree::is_command_span( 'Performance_CI urls' ) );
		$this->assertFalse( Flame_Tree::is_command_span( 'wp_loaded hook' ) );
	}

	/** Every platform span, spelled out, so a dropped or added row fails here. */
	private const PLATFORM_SPANS = [
		'url page cache', 'url header cache', 'url fold', 'url rank lists',
		'stats writes', 'stats rank close', 'stats probe', 'stats fold', 'stats re-rank', 'stats sweep',
		'requests writes', 'requests checkpoint', 'requests restore', 'requests expire',
	];

	/**
	 * Nested pairs, derived from the code: `Performance_CI_Node` builds the URL
	 * page inside `read_through_page( URL_PAGE_CACHE )`, whose build folds
	 * (`URL_FOLD`) and reads the header through `URL_HEADER_CACHE`, which folds
	 * too; `Flame_Builder_Node::shutdown_sweep()` spans `STATS_SWEEP` around a
	 * flush, which spans a rank close, folds and re-ranks and tells the writes.
	 */
	private const NESTED_PAIRS = [
		[ 'url page cache', 'url header cache' ],
		[ 'url page cache', 'url fold' ],
		[ 'url header cache', 'url fold' ],
		[ 'stats sweep', 'stats rank close' ],
		[ 'stats sweep', 'stats fold' ],
		[ 'stats sweep', 'stats re-rank' ],
		[ 'stats sweep', 'stats writes' ],
	];

	/** The base `hook_categories.json` colours, keyed by category. */
	private function base_colors(): array {
		return Hook_Categorizer::get_base_config()['_colors'];
	}

	private function channel( int $value ): float {
		$c = $value / 255;
		return 0.04045 >= $c ? $c / 12.92 : \pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	/** @return array{0: int, 1: int, 2: int} */
	private function rgb( string $hex ): array {
		$hex = \ltrim( $hex, '#' );
		if ( 3 === \strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return [ \hexdec( \substr( $hex, 0, 2 ) ), \hexdec( \substr( $hex, 2, 2 ) ), \hexdec( \substr( $hex, 4, 2 ) ) ];
	}

	private function wcag_luminance( string $hex ): float {
		[ $r, $g, $b ] = $this->rgb( $hex );
		return 0.2126 * $this->channel( $r ) + 0.7152 * $this->channel( $g ) + 0.0722 * $this->channel( $b );
	}

	private function contrast( string $one, string $two ): float {
		$a = $this->wcag_luminance( $one );
		$b = $this->wcag_luminance( $two );
		return ( \max( $a, $b ) + 0.05 ) / ( \min( $a, $b ) + 0.05 );
	}

	/** @return array{0: float, 1: float, 2: float} CIE L*a*b* (D65). */
	private function lab( string $hex ): array {
		[ $r, $g, $b ] = \array_map( fn ( int $v ): float => $this->channel( $v ), $this->rgb( $hex ) );
		$x = ( 0.4124 * $r + 0.3576 * $g + 0.1805 * $b ) / 0.95047;
		$y = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		$z = ( 0.0193 * $r + 0.1192 * $g + 0.9505 * $b ) / 1.08883;
		$f = static fn ( float $t ): float => 0.008856 < $t ? $t ** ( 1 / 3 ) : 7.787 * $t + 16 / 116;
		return [ 116 * $f( $y ) - 16, 500 * ( $f( $x ) - $f( $y ) ), 200 * ( $f( $y ) - $f( $z ) ) ];
	}

	private function delta_e( string $one, string $two ): float {
		$a = $this->lab( $one );
		$b = $this->lab( $two );
		return \sqrt( ( $a[0] - $b[0] ) ** 2 + ( $a[1] - $b[1] ) ** 2 + ( $a[2] - $b[2] ) ** 2 );
	}

	/** The ink `flameColors.js` `pickLabelColor()` puts on a fill, read from its source. */
	private function label_ink( string $fill ): string {
		$source = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/src/overview/flameColors.js' );
		$this->assertSame( 1, \preg_match( "/DARK_TEXT = '(#[0-9a-f]{6})'/i", $source, $dark ), 'flameColors.js DARK_TEXT' );
		$this->assertSame( 1, \preg_match( "/LIGHT_TEXT = '(#[0-9a-f]{6})'/i", $source, $light ), 'flameColors.js LIGHT_TEXT' );
		$this->assertSame( 1, \preg_match( '/LUMINANCE_THRESHOLD = ([0-9.]+);/', $source, $threshold ), 'flameColors.js LUMINANCE_THRESHOLD' );
		// The PHP replica below must track the JS brightness formula.
		$this->assertSame(
			1,
			\preg_match( '/\( ([0-9.]+) \* r \+ ([0-9.]+) \* g \+ ([0-9.]+) \* b \) \/ 255/', $source, $coefficients ),
			'flameColors.js relativeLuminance'
		);
		$this->assertSame( [ '0.2126', '0.7152', '0.0722' ], \array_slice( $coefficients, 1 ), 'the replica below uses these coefficients' );
		[ $r, $g, $b ] = $this->rgb( $fill );
		$brightness    = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;
		return $brightness > (float) $threshold[1] ? $dark[1] : $light[1];
	}

	public function test_the_platform_names_are_exactly_these_fourteen(): void {
		$names = \array_keys( Flame_Tree::platform_colors() );
		$spans = self::PLATFORM_SPANS;
		\sort( $names );
		\sort( $spans );
		$this->assertSame( $spans, $names );
	}

	/** Every platform category ships in the JSON, and the builders' own carry a description and no pattern. */
	public function test_every_platform_category_ships_in_hook_categories_json(): void {
		$base = Hook_Categorizer::get_base_config();
		foreach ( [ 'Event Logger', 'Event Logger Page Cache', 'Event Logger Cache', 'Event Logger Fold', 'Event Logger Shutdown' ] as $category ) {
			$this->assertArrayHasKey( $category, $base['_colors'] );
			$this->assertNotEmpty( $base['_descriptions'][ $category ] ?? '', "{$category} is described" );
			$this->assertArrayNotHasKey( $category, $base['_patterns'], "{$category} claims no hook" );
			$this->assertCount( 1, \array_keys( $base['_colors'], $base['_colors'][ $category ], true ), "{$category}'s colour is no other category's" );
		}
		$colors = Flame_Tree::platform_colors();
		$this->assertSame( $base['_colors']['Event Logger Page Cache'], $colors['url page cache'] );
		$this->assertSame( $base['_colors']['Event Logger Fold'], $colors['url fold'] );
		$this->assertSame( $base['_colors']['Event Logger'], $colors['url rank lists'] );
		$this->assertSame( $base['_colors']['Event Logger Cache'], $colors['url header cache'] );
		$this->assertSame( $base['_colors']['Event Logger Shutdown'], $colors['stats sweep'] );
		$this->assertSame( $base['_colors']['Event Logger'], $colors['stats writes'] );
	}

	public function test_every_platform_fill_holds_four_and_a_half_to_one_under_its_flame_label(): void {
		foreach ( Flame_Tree::platform_colors() as $span => $fill ) {
			$this->assertGreaterThanOrEqual( 4.5, $this->contrast( $fill, $this->label_ink( $fill ) ), "{$span} {$fill}" );
		}
	}

	public function test_nested_platform_spans_differ_by_twenty_delta_e_or_more(): void {
		$colors = Flame_Tree::platform_colors();
		foreach ( self::NESTED_PAIRS as [ $outer, $inner ] ) {
			$this->assertGreaterThanOrEqual( 20.0, $this->delta_e( $colors[ $outer ], $colors[ $inner ] ), "{$outer} / {$inner}" );
		}
	}

	public function test_no_platform_colour_is_the_command_spans(): void {
		$this->assertNotContains( '#905665', \array_map( 'strtolower', Flame_Tree::platform_colors() ) );
	}

	/** An operator recolouring a category recolours its spans; a custom colour of the span's own name still wins. */
	public function test_a_user_recolour_reaches_the_span_and_a_custom_colour_overrides_it(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [ 'colors' => [ 'Event Logger Page Cache' => '#123ABC' ] ] );
		Hook_Categorizer::clear_cache();
		$custom = static fn ( array $colors ): array => [ Flame_Tree::URL_HEADER_CACHE => '#ABC123' ] + $colors;
		\add_filter( 'newspack_event_logger_nodes_custom_colors', $custom );
		try {
			$this->assertSame( '#123ABC', Flame_Tree::platform_colors()[ Flame_Tree::URL_PAGE_CACHE ] );
			$this->assertSame( '#123ABC', Config::span_colors()[ Flame_Tree::URL_PAGE_CACHE ] );
			$this->assertSame( '#ABC123', Config::span_colors()[ Flame_Tree::URL_HEADER_CACHE ], 'the custom colour wins' );
		} finally {
			\delete_option( Hook_Categorizer::OPTION_NAME );
			Hook_Categorizer::clear_cache();
			\remove_filter( 'newspack_event_logger_nodes_custom_colors', $custom );
		}
	}

	/**
	 * One source for colours: a recolour reaches the published hook categories
	 * and a platform span alike. Only what the browser reads ships: merged
	 * colours and the BASE patterns, never an operator's own patterns, which the
	 * browser would compile without `categorize()`'s guards.
	 */
	public function test_a_user_recolour_reaches_the_published_global_and_the_platform_spans(): void {
		\update_option(
			Hook_Categorizer::OPTION_NAME,
			[
				'colors'    => [ 'Lifecycle' => '#0A1B2C', 'Event Logger Page Cache' => '#2C1B0A' ],
				'patterns'  => [ 'Lifecycle' => [ '^operator_only_' ] ],
				'overrides' => [ 'some_hook' => 'Lifecycle' ],
			]
		);
		Hook_Categorizer::clear_cache();
		try {
			$this->assertSame( 1, \preg_match( '/window\.eventLoggerHookCategories = (\{.*\});window\.eventLoggerCustomColors/s', Config::span_palette_js(), $m ) );
			$published = \json_decode( $m[1], true );
			$this->assertSame( [ '_colors', '_patterns' ], \array_keys( $published ), 'only what the browser reads' );
			$this->assertSame( '#0A1B2C', $published['_colors']['Lifecycle'], 'the hook colour source' );
			$this->assertSame( '#2C1B0A', $published['_colors']['Event Logger Page Cache'] );
			$this->assertSame( $published['_colors']['Event Logger Page Cache'], Flame_Tree::platform_colors()[ Flame_Tree::URL_PAGE_CACHE ] );
			$this->assertSame( Hook_Categorizer::get_base_config()['_patterns'], $published['_patterns'], 'base patterns only' );
			$this->assertStringNotContainsString( 'operator_only_', $m[1] );
		} finally {
			\delete_option( Hook_Categorizer::OPTION_NAME );
			Hook_Categorizer::clear_cache();
		}
	}

	/**
	 * `platform_colors()` runs during page build, so it never throws: a span
	 * whose category has no string colour is skipped and draws the default
	 * grey. A failed taxonomy read is logged by `Hook_Categorizer`, which is the
	 * signal; a shipped-taxonomy gap is caught by the JSON tests above.
	 */
	public function test_a_platform_span_without_a_category_colour_is_skipped_not_thrown(): void {
		$without = $this->base_colors();
		unset( $without['Event Logger Cache'] );
		Hook_Categorizer::$read_file = static fn ( string $path ): string => (string) \wp_json_encode( [ '_colors' => $without, '_patterns' => [] ] );
		Hook_Categorizer::clear_cache();
		try {
			$colors = Flame_Tree::platform_colors();
			$this->assertArrayNotHasKey( Flame_Tree::URL_HEADER_CACHE, $colors );
			$this->assertSame( $without['Event Logger'], $colors[ Flame_Tree::STATS_WRITES ] );
			$this->assertArrayNotHasKey( Flame_Tree::URL_HEADER_CACHE, Config::span_colors() );
		} finally {
			Hook_Categorizer::$read_file = null;
			Hook_Categorizer::clear_cache();
		}

		// A taxonomy that failed to read leaves every platform span grey, without a throw.
		Core::set_stderr_handler( static function (): void {} );
		Core::$recent_log_timers = [];
		Hook_Categorizer::$read_file = static fn ( string $path ): string => '{ not json';
		Hook_Categorizer::clear_cache();
		try {
			$this->assertSame( [], Flame_Tree::platform_colors() );
		} finally {
			Hook_Categorizer::$read_file = null;
			Hook_Categorizer::clear_cache();
		}
	}

	/** The builders' own upkeep spans — never their point lines, nor a reader's. */
	public function test_platform_span_kind_names_the_builders_upkeep(): void {
		foreach ( [ Flame_Tree::STATS_FOLD . ' (complete)', Flame_Tree::STATS_RE_RANK, Flame_Tree::STATS_RANK_CLOSE, Flame_Tree::STATS_SWEEP, Flame_Tree::REQUESTS_CHECKPOINT, Flame_Tree::REQUESTS_RESTORE ] as $span ) {
			$this->assertSame( 'upkeep', Flame_Tree::platform_span_kind( $span ), $span );
		}
		foreach ( [ Flame_Tree::STATS_WRITES, Flame_Tree::STATS_PROBE, Flame_Tree::REQUESTS_WRITES, Flame_Tree::REQUESTS_EXPIRE, 'stats folds' ] as $span ) {
			$this->assertNull( Flame_Tree::platform_span_kind( $span ), $span );
		}
	}

	/** The URL read's three steps are its spans — never its point events, nor a verb. */
	public function test_platform_span_kind_names_the_url_reads_three_steps(): void {
		foreach ( [ Flame_Tree::URL_PAGE_CACHE, Flame_Tree::URL_HEADER_CACHE, Flame_Tree::URL_FOLD . ': 3' ] as $span ) {
			$this->assertSame( 'url_read', Flame_Tree::platform_span_kind( $span ), $span );
		}
		foreach ( [ Flame_Tree::URL_RANK_LISTS, 'Discovery_CI get command', 'url folds', 'wp_loaded hook', 'sql' ] as $span ) {
			$this->assertNull( Flame_Tree::platform_span_kind( $span ), $span );
		}
	}

	/** The name the minter builds is the name the classifier reads, sign included. */
	public function test_listener_name_is_what_is_listener_span_reads(): void {
		$this->assertTrue( Flame_Tree::is_listener_span( Flame_Tree::listener_name( 'Image_CDN::filter_the_content', -5 ) ) );
		$this->assertSame( 'Image_CDN::filter_the_content @999999', Flame_Tree::listener_name( 'Image_CDN::filter_the_content', 999999 ) );
	}

	/** What a node costs a prune before its name, distinct from every real estimate. */
	private const NODE_BYTES = 97;

	/** What `prune_lightest()` estimates a tree costs: each node's overhead and name. */
	private static function tree_bytes( array $node ): int {
		$bytes = self::NODE_BYTES + \strlen( $node['name'] );
		foreach ( $node['children'] as $child ) {
			$bytes += self::tree_bytes( $child );
		}
		return $bytes;
	}

	/** An aggregate node as the merge leaves one. */
	private static function agg( string $name, float $sum, array $children = [] ): array {
		return [ 'name' => $name, 'sum_value' => $sum, 'ts' => self::NOW, 'children' => $children ];
	}

	public function test_a_prune_drops_the_lightest_leaves_first_and_keeps_the_root(): void {
		$light = self::agg( 'light parent', 7.0, [ self::agg( 'light a', 3.0 ), self::agg( 'light b', 4.0 ) ] );
		$root  = self::agg(
			'aggregate',
			900.0,
			[ $light, self::agg( 'heavy parent', 800.0, [ self::agg( 'heavy a', 500.0 ), self::agg( 'heavy b', 300.0 ) ] ) ]
		);
		// One byte short of room for everything but the light subtree.
		$budget = self::tree_bytes( $root ) - self::tree_bytes( $light ) - 1;

		$root = Flame_Tree::prune_lightest( $root, $budget, self::NODE_BYTES );

		$this->assertLessThanOrEqual( $budget, self::tree_bytes( $root ) );
		$this->assertSame( 'aggregate', $root['name'] );
		$this->assertSame( [ 'heavy parent' ], \array_column( $root['children'], 'name' ), 'the light subtree went whole' );
		$this->assertSame( [ 'heavy a' ], \array_column( $root['children'][0]['children'], 'name' ), 'then the lighter heavy leaf' );
		$this->assertSame( [ 0 ], \array_keys( $root['children'][0]['children'] ), 'the survivors are a list again' );
	}

	public function test_a_prune_takes_a_bared_parent_as_a_leaf_in_its_turn(): void {
		$root   = self::agg( 'aggregate', 10.0, [ self::agg( 'parent', 1.0, [ self::agg( 'child', 1.0 ) ] ), self::agg( 'heavy', 9.0 ) ] );
		$budget = self::NODE_BYTES * 2 + \strlen( 'aggregate' ) + \strlen( 'heavy' );

		$root = Flame_Tree::prune_lightest( $root, $budget, self::NODE_BYTES );

		$this->assertSame( [ 'heavy' ], \array_column( $root['children'], 'name' ) );
	}
}
