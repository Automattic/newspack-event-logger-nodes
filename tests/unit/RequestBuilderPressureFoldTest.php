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
use Newspack_Event_Logger_Nodes\App\Findings;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
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

	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
	}

	/** A builder wired to a capture sink, with the budget pinned; a successor takes its own name. */
	private function builder( Capture_Sink_Node $sink, string $name = 'request-builder' ): Request_Builder_Node {
		$rb = new Request_Builder_Node();
		$rb->name( $name );
		$rb->sink( $sink );
		$rb->arguments( [ '100', '2', (string) self::BUDGET, (string) self::MAX_PER_REQUEST ] );
		return $rb;
	}

	/** The fold's kept-head bound, read from the node rather than restated. */
	private function fold_head(): int {
		return (int) ( new \ReflectionClassConstant( Request_Builder_Node::class, 'FOLD_KEEP_HEAD' ) )->getValue();
	}

	/** The fold's kept-tail bound; sized for a producer-defined stats flush. */
	private function fold_tail(): int {
		return (int) ( new \ReflectionClassConstant( Request_Builder_Node::class, 'FOLD_KEEP_TAIL' ) )->getValue();
	}

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
		// The bound is the geometry itself — head + marker + tail — not a
		// number that has to be re-guessed whenever either end is resized.
		$this->assertLessThanOrEqual(
			$this->fold_head() + 1 + $this->fold_tail(),
			\count( $record['entries'] )
		);
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
		// A gap in a folded request is a row of its tail like any other, and
		// the record carries it.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = $this->run_request( $rb, 'long', 40 );
		// A jump in `n` is a gap: lines went missing between the two.
		$this->fill( $rb, $n + 25, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'long' );
		$this->assertTrue( $record['folded'] );
		$keys = \array_column( $record['entries'], 'k' );
		$this->assertContains( 'entries (lost)', $keys );
		// The loss lands after the aggregated marker, where the request lost
		// it, never ahead of the middle it follows.
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

	public function test_a_marked_entry_never_folds_however_far_from_the_end(): void {
		// The stats flush is the only place a reader sees a request's cache hit
		// rates, and it lands 11-15 entries from the end of a real render — past
		// any tail worth keeping. Sizing the tail around it is guesswork about a
		// producer's shutdown sequence; the producer marking the line is not.
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = $this->run_request( $rb, 'long', 40 );

		$groups = [ 'metadatacache', 'combinedcache', 'requestcache', 'memcached', 'validation' ];
		foreach ( $groups as $group ) {
			$this->fill( $rb, $n++, 'long', $group, [ 'm' => '4020 l1, 39 apcu, 0 miss', 'keep' => 1 ] );
		}
		// The real closing sequence that pushes them out of reach: pyrobase's
		// own completion, six WordPress shutdown hooks, then the terminals.
		$this->fill( $rb, $n++, 'long', 'pyrobase (complete)', [ 'duration_ms' => 900 ] );
		foreach ( [ 'update_option', 'query', 'updated_option' ] as $hook ) {
			$this->fill( $rb, $n++, 'long', "{$hook} hook (start)" );
			$this->fill( $rb, $n++, 'long', "{$hook} hook (complete)", [ 'duration_ms' => 1 ] );
		}
		$this->fill( $rb, $n++, 'long', 'memory', [ 'm' => '62MB' ] );
		$this->fill( $rb, $n++, 'long', 'resources', [ 'm' => 'utime => 1' ] );
		$this->fill( $rb, $n, 'long', 'process (complete)', [ 'duration_ms' => 900, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'long' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$keys = \array_column( $record['entries'], 'k' );

		foreach ( $groups as $group ) {
			$this->assertContains( $group, $keys, "the {$group} summary must survive the fold" );
		}
		$this->assertSame( 'process (complete)', \end( $keys ), 'and the tail still ends the record' );
	}

	/**
	 * A span opened in the KEPT HEAD frames every row after it, so its
	 * `(complete)` survives the fold however many rows follow it: the rolling
	 * tail never evicts it, and the entry list closes every span the head
	 * opened, as the flame does.
	 */
	public function test_the_close_of_a_span_opened_in_the_head_survives_the_fold(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );

		// The parent's own numbering, then the subprocess restarts at n=1 and
		// the parent resumes where it left off — as a real nested render does.
		$this->fill( $rb, 1, 'nested', 'process (start)', [ 'm' => '1 on host POST /jobs' ] );
		$this->fill( $rb, 2, 'nested', 'request', [ 'm' => 'POST http://x/jobs' ] );
		$this->fill( $rb, 3, 'nested', 'resources', [ 'm' => 'utime => 0' ] );
		$sub = 1;
		$this->fill( $rb, $sub++, 'nested', 'gyrobase (start)' );
		for ( $i = 0; $i < 40; $i++ ) {
			$this->fill( $rb, $sub++, 'nested', 'change (start)' );
			$this->fill( $rb, $sub++, 'nested', 'change (complete)', [ 'duration_ms' => 1 + $i ] );
		}
		$this->fill( $rb, $sub, 'nested', 'gyrobase (complete)', [ 'duration_ms' => 535829 ] );
		// The parent's post-subprocess work: more rows than the tail holds.
		$n = 4;
		$this->fill( $rb, $n++, 'nested', 'nuclear_gyrobase', [ 'm' => 'engine exit 0' ] );
		foreach ( [ 'query', 'query', 'updated_option' ] as $hook ) {
			$this->fill( $rb, $n++, 'nested', "{$hook} hook (start)" );
			$this->fill( $rb, $n++, 'nested', "{$hook} hook (complete)", [ 'duration_ms' => 1 ] );
		}
		$this->fill( $rb, $n++, 'nested', 'memory', [ 'm' => '14MB' ] );
		$this->fill( $rb, $n++, 'nested', 'resources', [ 'm' => 'utime => 1' ] );
		$this->fill( $rb, $n, 'nested', 'process (complete)', [ 'duration_ms' => 536214, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'nested' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$keys = \array_column( $record['entries'], 'k' );

		$this->assertContains( 'gyrobase (start)', $keys, 'the head opened it' );
		$this->assertContains( 'gyrobase (complete)', $keys, 'so the record must close it' );
		$this->assertSame( 'process (complete)', \end( $keys ), 'and the tail still ends the record' );
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
		// 2 opening + 80 save + 1 terminal = 83 raw, less whichever ends survive.
		$merged = 83 - $this->fold_head() - $this->fold_tail();
		$this->assertStringContainsString( "{$merged} entries", $markers[0]['m'] );
		// It sits between them, never at either end.
		$position = \array_search( $markers[0], $entries, true );
		$this->assertGreaterThan( 0, $position );
		$this->assertLessThan( \count( $entries ) - 1, $position );
	}

	public function test_one_runaway_request_folds_on_its_own_cap(): void {
		// The per-request cap folds the request, so its counts and totals
		// cover every line, not only those before the cap.
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

	/**
	 * A head span's close is kept, but it is a row like any other: it lands
	 * where it arrived. A pyrobase render inside `template_redirect` logs its
	 * tail rows BEFORE the hook closes, and a close moved ahead of them puts
	 * them outside the span that holds them.
	 */
	public function test_a_kept_close_lands_where_it_arrived(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$ts   = 3000.0;
		$n    = 1;
		$this->fill( $rb, $n++, 'late', 'process (start)', [ 'm' => '1 on host GET /late', 'ts' => $ts ] );
		$this->fill( $rb, $n++, 'late', 'request', [ 'm' => 'GET http://x/late', 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'late', 'template_redirect hook (start)', [ 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'late', 'pyrobase (start)', [ 'ts' => $ts += 0.001 ] );
		for ( $i = 0; $i < 30; $i++ ) {
			$this->fill( $rb, $n++, 'late', 'save (start)', [ 'ts' => $ts += 0.001 ] );
			$this->fill( $rb, $n++, 'late', 'save (complete)', [ 'duration_ms' => 1, 'ts' => $ts += 0.001 ] );
		}
		$this->fill( $rb, $n++, 'late', 'pyrobase (complete)', [ 'duration_ms' => 64, 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'late', 'widget (start)', [ 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'late', 'widget (complete)', [ 'duration_ms' => 1, 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'late', 'footer (start)', [ 'ts' => $ts += 0.8 ] );
		$this->fill( $rb, $n++, 'late', 'footer (complete)', [ 'duration_ms' => 1, 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'late', 'template_redirect hook (complete)', [ 'duration_ms' => 870, 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n, 'late', 'process (complete)', [ 'duration_ms' => 872, 'status_code' => 200, 'ts' => $ts += 0.001 ] );

		$record = $this->record_for( $sink, 'late' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$keys = \array_column( $record['entries'], 'k' );
		$this->assertGreaterThan(
			\array_search( 'footer (complete)', $keys, true ),
			\array_search( 'template_redirect hook (complete)', $keys, true ),
			'the hook closed after the rows it held'
		);
		$this->assertSame( 'process (complete)', \end( $keys ) );

		$gap = null;
		foreach ( Findings::for_request( $record, new Rule( 'f00dfacecafe', '/late', Rule::ACTION_LOG, 0, 0.0, [], [], [ 'template_redirect' ] ) ) as $finding ) {
			$gap = 'entry_gap' === $finding['kind'] ? $finding : $gap;
		}
		$this->assertNotNull( $gap );
		$this->assertSame( 'template_redirect hook', $gap['metric']['inside'] ?? null );
		$this->assertSame( 'none', $gap['proposal']['action'] );
	}

	/**
	 * A head span can close after the kept head but before the fold fires.
	 * That close is a row past the head like any other, so it is pinned in
	 * the tail, and a later span of the same name keeps its own close.
	 */
	public function test_a_head_span_closed_before_the_fold_keeps_its_close(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$ts   = 4000.0;
		$n    = 1;
		$this->fill( $rb, $n++, 'early', 'process (start)', [ 'm' => '1 on host GET /early', 'ts' => $ts ] );
		$this->fill( $rb, $n++, 'early', 'request', [ 'm' => 'GET http://x/early', 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'early', 'cron_tick hook (start)', [ 'ts' => $ts += 0.001 ] );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->fill( $rb, $n++, 'early', 'stamp (start)', [ 'ts' => $ts += 0.001 ] );
			$this->fill( $rb, $n++, 'early', 'stamp (complete)', [ 'duration_ms' => 1, 'ts' => $ts += 0.001 ] );
		}
		$this->fill( $rb, $n++, 'early', 'cron_tick hook (complete)', [ 'duration_ms' => 9, 'ts' => $ts += 0.001 ] );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->fill( $rb, $n++, 'early', 'save (start)', [ 'ts' => $ts += 0.001 ] );
			$this->fill( $rb, $n++, 'early', 'save (complete)', [ 'duration_ms' => 1, 'ts' => $ts += 0.001 ] );
		}
		$this->fill( $rb, $n++, 'early', 'cron_tick hook (start)', [ 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'early', 'cron_tick hook (complete)', [ 'duration_ms' => 1, 'ts' => $ts += 0.001 ] );
		$this->fill( $rb, $n++, 'early', 'shutdown hook', [ 'ts' => $ts += 0.613 ] );
		$this->fill( $rb, $n, 'early', 'process (complete)', [ 'duration_ms' => 680, 'status_code' => 200, 'ts' => $ts += 0.001 ] );

		$record = $this->record_for( $sink, 'early' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$keys = \array_column( $record['entries'], 'k' );
		$this->assertSame( 2, \count( \array_keys( $keys, 'cron_tick hook (complete)', true ) ), 'each cron_tick span keeps its own close' );

		$gap = null;
		foreach ( Findings::for_request( $record, new Rule( 'beadfeed0123', '/early', Rule::ACTION_LOG, 0, 0.0, [], [], [ 'cron_tick' ] ) ) as $finding ) {
			$gap = 'entry_gap' === $finding['kind'] ? $finding : $gap;
		}
		$this->assertNotNull( $gap );
		$this->assertEqualsWithDelta( 613.0, $gap['metric']['gap_ms'], 0.5 );
		$this->assertArrayNotHasKey( 'inside', $gap['metric'], 'cron_tick closed, so no span holds the window' );
	}

	/**
	 * Log `$pairs` `save` spans, unkept rows the rolling tail evicts first.
	 *
	 * @return int The next unused line number.
	 */
	private function saves( Request_Builder_Node $rb, string $rid, int $n, int $pairs ): int {
		for ( $i = 0; $i < $pairs; $i++ ) {
			$this->fill( $rb, $n++, $rid, 'save (start)' );
			$this->fill( $rb, $n++, $rid, 'save (complete)', [ 'duration_ms' => 1 ] );
		}
		return $n;
	}

	/** The `(complete)` rows a record carries for one base name. */
	private function closes_of( array $record, string $base ): array {
		return \array_values(
			\array_filter( $record['entries'], static fn ( array $e ): bool => "{$base} (complete)" === ( $e['k'] ?? '' ) )
		);
	}

	/**
	 * A head span re-entered past the head — `the_content` inside an excerpt
	 * it renders — keeps its OWN close: the inner pair is a stranger's.
	 */
	public function test_a_reentered_head_span_keeps_its_own_close(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = 1;
		$this->fill( $rb, $n++, 'reenter', 'process (start)', [ 'm' => '1 on host GET /reenter' ] );
		$this->fill( $rb, $n++, 'reenter', 'request', [ 'm' => 'GET http://x/reenter' ] );
		$this->fill( $rb, $n++, 'reenter', 'the_content hook (start)' );
		$n = $this->saves( $rb, 'reenter', $n, 3 );
		$this->fill( $rb, $n++, 'reenter', 'note', [ 'm' => 'head ends' ] );
		$this->fill( $rb, $n++, 'reenter', 'the_content hook (start)' );
		$this->fill( $rb, $n++, 'reenter', 'the_content hook (complete)', [ 'duration_ms' => 77 ] );
		$n = $this->saves( $rb, 'reenter', $n, 12 );
		$this->fill( $rb, $n++, 'reenter', 'the_content hook (complete)', [ 'duration_ms' => 1234 ] );
		$n = $this->saves( $rb, 'reenter', $n, 6 );
		$this->fill( $rb, $n, 'reenter', 'process (complete)', [ 'duration_ms' => 1300, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'reenter' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$this->assertSame( [ 1234 ], \array_column( $this->closes_of( $record, 'the_content hook' ), 'duration_ms' ) );
	}

	/** A name the head opened twice, nested, is two frames to close. */
	public function test_a_name_opened_twice_in_the_head_keeps_both_closes(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = 1;
		$this->fill( $rb, $n++, 'twice', 'process (start)', [ 'm' => '1 on host GET /twice' ] );
		$this->fill( $rb, $n++, 'twice', 'request', [ 'm' => 'GET http://x/twice' ] );
		$this->fill( $rb, $n++, 'twice', 'render_block hook (start)' );
		$this->fill( $rb, $n++, 'twice', 'render_block hook (start)' );
		$n = $this->saves( $rb, 'twice', $n, 3 );
		$n = $this->saves( $rb, 'twice', $n, 8 );
		$this->fill( $rb, $n++, 'twice', 'render_block hook (complete)', [ 'duration_ms' => 55 ] );
		$n = $this->saves( $rb, 'twice', $n, 6 );
		$this->fill( $rb, $n++, 'twice', 'render_block hook (complete)', [ 'duration_ms' => 912 ] );
		$n = $this->saves( $rb, 'twice', $n, 6 );
		$this->fill( $rb, $n, 'twice', 'process (complete)', [ 'duration_ms' => 980, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'twice' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$this->assertSame( [ 55, 912 ], \array_column( $this->closes_of( $record, 'render_block hook' ), 'duration_ms' ) );
	}

	/**
	 * A producer closing a parent first drains every frame still open inside
	 * it as an `(orphaned)` complete, innermost first. A head frame's orphaned
	 * close is still that frame's close, so both survive the fold, and a later
	 * span of the inner name is an ordinary row.
	 */
	public function test_an_orphaned_close_of_a_head_frame_survives_the_fold(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = 1;
		$this->fill( $rb, $n++, 'orphan', 'process (start)', [ 'm' => '1 on host GET /orphan' ] );
		$this->fill( $rb, $n++, 'orphan', 'request', [ 'm' => 'GET http://x/orphan' ] );
		$this->fill( $rb, $n++, 'orphan', 'wp_loaded hook (start)' );
		$this->fill( $rb, $n++, 'orphan', 'widget_init hook (start)' );
		$n = $this->saves( $rb, 'orphan', $n, 11 );
		$this->fill( $rb, $n++, 'orphan', 'widget_init hook (complete)', [ 'm' => '(orphaned)', 'duration_ms' => 318 ] );
		$this->fill( $rb, $n++, 'orphan', 'wp_loaded hook (complete)', [ 'duration_ms' => 340 ] );
		$this->fill( $rb, $n++, 'orphan', 'widget_init hook (start)' );
		$this->fill( $rb, $n++, 'orphan', 'widget_init hook (complete)', [ 'duration_ms' => 6 ] );
		$n = $this->saves( $rb, 'orphan', $n, 6 );
		$this->fill( $rb, $n, 'orphan', 'process (complete)', [ 'duration_ms' => 420, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'orphan' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$this->assertSame( [ 340 ], \array_column( $this->closes_of( $record, 'wp_loaded hook' ), 'duration_ms' ) );
		$this->assertSame( [ 318 ], \array_column( $this->closes_of( $record, 'widget_init hook' ), 'duration_ms' ), 'the head frame\'s orphaned close, and no stranger\'s' );
	}

	/**
	 * A span re-opened past the head and drained as `(orphaned)` when its
	 * parent closes is the re-open's close, not the head frame's.
	 */
	public function test_an_orphaned_reopen_past_the_head_is_not_the_head_frames_close(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = 1;
		$this->fill( $rb, $n++, 'drain', 'process (start)', [ 'm' => '1 on host GET /drain' ] );
		$this->fill( $rb, $n++, 'drain', 'request', [ 'm' => 'GET http://x/drain' ] );
		$this->fill( $rb, $n++, 'drain', 'template_redirect hook (start)' );
		$this->fill( $rb, $n++, 'drain', 'the_content hook (start)' );
		$n = $this->saves( $rb, 'drain', $n, 11 );
		$this->fill( $rb, $n++, 'drain', 'the_content hook (start)' );
		$n = $this->saves( $rb, 'drain', $n, 2 );
		$this->fill( $rb, $n++, 'drain', 'the_content hook (complete)', [ 'm' => '(orphaned)', 'duration_ms' => 41 ] );
		$this->fill( $rb, $n++, 'drain', 'the_content hook (complete)', [ 'm' => '(orphaned)', 'duration_ms' => 707 ] );
		$this->fill( $rb, $n++, 'drain', 'template_redirect hook (complete)', [ 'duration_ms' => 733 ] );
		$n = $this->saves( $rb, 'drain', $n, 6 );
		$this->fill( $rb, $n, 'drain', 'process (complete)', [ 'duration_ms' => 760, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'drain' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$this->assertSame( [ 707 ], \array_column( $this->closes_of( $record, 'the_content hook' ), 'duration_ms' ) );
		$this->assertSame( [ 733 ], \array_column( $this->closes_of( $record, 'template_redirect hook' ), 'duration_ms' ) );
	}

	/**
	 * A checkpoint taken after the fold carries which frames the head left
	 * open, so a close arriving after the restore is still kept.
	 */
	public function test_a_head_frames_close_after_a_restore_survives_the_fold(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = 1;
		$this->fill( $rb, $n++, 'resume', 'process (start)', [ 'm' => '1 on host GET /resume' ] );
		$this->fill( $rb, $n++, 'resume', 'request', [ 'm' => 'GET http://x/resume' ] );
		$this->fill( $rb, $n++, 'resume', 'shutdown hook (start)' );
		$n = $this->saves( $rb, 'resume', $n, 12 );

		$successor = $this->builder( $sink, 'request-builder-respawned' );
		$successor->restore_state( $rb->save_state() );
		$this->fill( $successor, $n++, 'resume', 'shutdown hook (complete)', [ 'duration_ms' => 2718 ] );
		$n = $this->saves( $successor, 'resume', $n, 6 );
		$this->fill( $successor, $n, 'resume', 'process (complete)', [ 'duration_ms' => 2800, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'resume' );
		$this->assertTrue( $record['folded'] ?? false, 'the request must have folded' );
		$this->assertSame( [ 2718 ], \array_column( $this->closes_of( $record, 'shutdown hook' ), 'duration_ms' ) );
	}

	/**
	 * An envelope checkpointed before the fold kept its head frames on the
	 * stack carries `await` and `keep` properties nothing reads. The restore
	 * drops both, so neither reaches the record.
	 */
	public function test_a_restore_drops_the_retired_await_and_keep(): void {
		$sink = new Capture_Sink_Node();
		$rb   = $this->builder( $sink );
		$n    = $this->run_request( $rb, 'retired', 12 );
		$saved = self::with_retired_fields( $rb->save_state(), 'retired' );

		$successor = $this->builder( $sink, 'request-builder-respawned' );
		$successor->restore_state( $saved );
		$this->fill( $successor, $n, 'retired', 'process (complete)', [ 'duration_ms' => 3141, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'retired' );
		$this->assertArrayNotHasKey( 'await', $record );
		$this->assertArrayNotHasKey( 'keep', $record );
	}

	/**
	 * A checkpoint with the two properties a folded envelope carried before
	 * its head frames rode the fold's own stack, added to one envelope.
	 *
	 * @param array<string,mixed> $saved The checkpoint.
	 * @param string              $rid   The envelope to add them to.
	 * @return array<string,mixed>
	 */
	private static function with_retired_fields( array $saved, string $rid ): array {
		$walk = static function ( array $node ) use ( &$walk, $rid ): array {
			foreach ( $node as $key => $value ) {
				if ( ! \is_array( $value ) ) {
					continue;
				}
				if ( $rid === ( $value['rid'] ?? null ) ) {
					$value['await'] = [ 'shutdown hook' => true ];
					$value['keep']  = [ [ 'n' => 99, 'k' => 'shutdown hook (complete)' ] ];
				}
				$node[ $key ] = $walk( $value );
			}
			return $node;
		};
		return $walk( $saved );
	}

	/**
	 * A folded envelope is bounded already, so pressure never folds it again:
	 * a second fold would discard its path map and the marker with it.
	 */
	public function test_pressure_never_refolds_a_folded_envelope(): void {
		$sink = new Capture_Sink_Node();
		$rb   = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->sink( $sink );
		$rb->arguments( [ '100', '2', '15', '24' ] );

		$n = $this->run_request( $rb, 'big', 7 );
		$this->fill( $rb, $n++, 'big', 'save (start)' );
		$this->fill( $rb, $n++, 'big', 'save (complete)', [ 'duration_ms' => 3 ] );
		$this->run_request( $rb, 'small', 2 );
		$this->fill( $rb, $n, 'big', 'process (complete)', [ 'duration_ms' => 640, 'status_code' => 200 ] );

		$record = $this->record_for( $sink, 'big' );
		$this->assertTrue( $record['folded'] ?? false, 'the large request folded once' );
		$this->assertContains( 'entries (aggregated)', \array_column( $record['entries'], 'k' ), 'and kept the marker its fold wrote' );
	}

	/**
	 * A fold keeps `FOLD_KEEP_HEAD` rows, so an envelope holding no more
	 * reclaims nothing: pressure that only small envelopes hold folds none.
	 */
	public function test_pressure_folds_nothing_it_cannot_reclaim(): void {
		$sink = new Capture_Sink_Node();
		$rb   = new Request_Builder_Node();
		$rb->name( 'request-builder' );
		$rb->sink( $sink );
		$rb->arguments( [ '100', '2', '12', '50' ] );

		$a = $this->run_request( $rb, 'brief-a', 3 );
		$b = $this->run_request( $rb, 'brief-b', 2 );
		$this->fill( $rb, $a, 'brief-a', 'process (complete)', [ 'duration_ms' => 19, 'status_code' => 200 ] );
		$this->fill( $rb, $b, 'brief-b', 'process (complete)', [ 'duration_ms' => 23, 'status_code' => 200 ] );

		$this->assertArrayNotHasKey( 'folded', $this->record_for( $sink, 'brief-a' ) );
		$this->assertArrayNotHasKey( 'folded', $this->record_for( $sink, 'brief-b' ) );
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
