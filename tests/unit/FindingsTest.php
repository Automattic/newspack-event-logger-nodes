<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Findings;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * The findings detector: arithmetic over a stored record, not inference.
 *
 * Every threshold here is seeded with a value distinct from the constant it
 * tests against, so a detector that ignored its own threshold would still be
 * caught.
 */
#[CoversClass( Findings::class )]
class FindingsTest extends TestCase {

	/** A rule with hooks, so "insufficient instrumentation" does not fire. */
	private function instrumented_rule(): Rule {
		return new Rule( 'a1b2c3d4e5f6', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [], [], [ 'init', 'wp_loaded' ] );
	}

	/** A record whose profiled time accounts for its duration and holds no findings. */
	private function healthy_record(): array {
		return [
			'url'         => 'https://example.test/calendar/today',
			'duration_ms' => 400.0,
			'status_code' => 200,
			'entries'     => [
				[ 'n' => 1, 'ts' => 1000.000, 'k' => 'process (start)', 'm' => '' ],
				[ 'n' => 2, 'ts' => 1000.100, 'k' => 'init hook', 'm' => '' ],
				[ 'n' => 3, 'ts' => 1000.200, 'k' => 'wp_loaded hook', 'm' => '' ],
			],
			'flame'       => [
				'name'     => 'request',
				'value'    => 400.0,
				'children' => [
					[ 'name' => 'init hook', 'value' => 190.0, 'children' => [] ],
					[ 'name' => 'wp_loaded hook', 'value' => 180.0, 'children' => [] ],
				],
			],
		];
	}

	/**
	 * The PRODUCTION record shape: a loaded request carries its tree at
	 * `flame_data` (Performance_CI merges the flames partition in under that
	 * key), and only a FOLDED record ever carries `flame`. Seeding `flame`
	 * alone is what let a detector reading the wrong key look correct.
	 */
	private function loaded_record(): array {
		$record               = $this->healthy_record();
		$record['flame_data'] = $record['flame'];
		unset( $record['flame'] );
		return $record;
	}

	/**
	 * `error_status = 'F'` says a fatal happened; it does not say where. The
	 * runtime resolved the plugin, file and line at the moment it died, so the
	 * finding states them rather than sending anyone to a server log.
	 */
	public function test_a_fatal_names_the_plugin_that_died(): void {
		$record                 = $this->loaded_record();
		$record['status_code']  = 500;
		$record['error_status'] = 'F';
		$record['fatal_error']  = 'Uncaught Error: Undefined constant "USER_SWITCHING_SECURE_COOKIE"';
		$record['fatal_file']   = '/srv/htdocs/wp-content/plugins/user-switching/user-switching.php';
		$record['fatal_line']   = 1585;
		$record['fatal_plugin'] = 'user-switching';

		$findings = Findings::for_request( $record, $this->instrumented_rule() );

		$this->assertSame( 'fatal', $findings[0]['kind'], 'a request that DIED outranks every other finding' );
		$this->assertSame( 'high', $findings[0]['severity'] );
		$this->assertStringContainsString( 'user-switching', $findings[0]['title'] );
		$this->assertStringContainsString( 'USER_SWITCHING_SECURE_COOKIE', $findings[0]['detail'] );
		$this->assertSame( 1585, $findings[0]['metric']['line'] );
	}

	public function test_a_record_that_did_not_die_has_no_fatal_finding(): void {
		$kinds = \array_column(
			Findings::for_request( $this->loaded_record(), $this->instrumented_rule() ),
			'kind'
		);

		$this->assertNotContains( 'fatal', $kinds );
	}

	public function test_a_loaded_record_carries_its_tree_at_flame_data(): void {
		$record = $this->loaded_record();
		$record['flame_data']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
		];

		$kinds = $this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) );

		$this->assertContains( 'dominant_span', $kinds, 'reading the wrong key made every ordinary request look wholly unmeasured' );
		$this->assertNotContains( 'insufficient_instrumentation', $kinds );
	}

	/**
	 * The live hub timeout (86cnt4d8763r2jvw7rmxpsy77y2sm7o6), shaped as
	 * stored: the trace ran 467ms into a nested render and then nothing more
	 * arrived until the builder timed it out 856 seconds after it opened.
	 */
	private function timed_out_record(): array {
		$record                 = $this->loaded_record();
		$record['error_status'] = 'T';
		$record['timestamp']    = 6000.0;
		$record['duration_ms']  = 856000.0;
		$record['entries']      = [
			[ 'n' => 1, 'ts' => 6000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 6000.001, 'k' => 'request', 'm' => 'GET https://kea.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 6000.002, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 4, 'ts' => 6000.104, 'k' => 'template_redirect hook (start)', 'm' => '' ],
			[ 'n' => 1, 'ts' => 6000.410, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 6000.467, 'k' => 'include (start)', 'l' => '/Macros/Global.html', 'm' => '' ],
		];
		$record['last_log_ts']  = 6000.467;
		return $record;
	}

	/**
	 * A timed-out record says where its trace stopped: the last entry, the
	 * spans still open there, and when the builder gave up on it. It names
	 * no cause, because a killed or hung process and a lost log tail leave
	 * the same record.
	 */
	public function test_a_timed_out_record_says_where_its_logging_stopped(): void {
		$findings = Findings::for_request( $this->timed_out_record(), $this->instrumented_rule() );
		$found    = $this->of_kind( $findings, 'stopped' );

		$this->assertSame( 'stopped', $findings[0]['kind'], 'nothing else on a timed-out record outranks where it stopped' );
		$this->assertSame( 'high', $found['severity'] );
		$this->assertEqualsWithDelta( 467.0, $found['metric']['last_entry_ms'], 1e-6 );
		$this->assertSame( 'include (start)', $found['metric']['last_entry'] );
		$this->assertSame( 'template_redirect hook › gyrobase › include: /Macros/Global.html', $found['metric']['open'] );
		$this->assertSame( 856000.0, $found['metric']['evicted_after_ms'] );
		$this->assertStringContainsString( 'stopped logging 467.0ms in', $found['title'] );
		$this->assertStringNotContainsString( 'killed', $found['title'] );
		$this->assertStringContainsString( 'lost log tail', $found['detail'] );
		$this->assertArrayNotHasKey( 'proposal', $found );
	}

	/** Nothing past the request's opening rows places no stop: only the time is known. */
	public function test_a_record_silent_after_its_opening_rows_has_no_known_stop_point(): void {
		$record                = $this->timed_out_record();
		$record['entries']     = \array_slice( $record['entries'], 0, 3 );
		$record['last_log_ts'] = 6000.268;

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'stopped' );

		$this->assertSame( [ 'last_entry_ms', 'last_line_ms', 'evicted_after_ms' ], \array_keys( $found['metric'] ) );
		$this->assertEqualsWithDelta( 2.0, $found['metric']['last_entry_ms'], 1e-6, 'the opening sample\'s own time' );
		$this->assertEqualsWithDelta( 268.0, $found['metric']['last_line_ms'], 1e-6 );
		$this->assertStringContainsString( 'unknown', $found['title'] );
		$this->assertStringNotContainsString( 'resources', $found['title'] );
	}

	/** Rows past the opening ones place the stop, whatever the rule binds. */
	public function test_a_hookless_rules_record_still_places_its_stop(): void {
		$found = $this->of_kind(
			Findings::for_request( $this->timed_out_record(), new Rule( 'ffff11112222', '/calendar/today', Rule::ACTION_LOG ) ),
			'stopped'
		);

		$this->assertSame( 'include (start)', $found['metric']['last_entry'] ?? null );
	}

	/**
	 * The profiler's plugin rows are written after the opening rows but stamped
	 * with when each plugin loaded, before the `request` line: a stop is never
	 * placed earlier than that line.
	 */
	public function test_back_dated_plugin_rows_place_no_stop(): void {
		$record                = $this->timed_out_record();
		$record['entries']     = [
			...\array_slice( $record['entries'], 0, 3 ),
			[ 'n' => 4, 'ts' => 5999.950, 'k' => 'jetpack plugin (start)', 'm' => '' ],
			[ 'n' => 5, 'ts' => 5999.990, 'k' => 'jetpack plugin (complete)', 'm' => '', 'duration_ms' => 40.0 ],
		];
		// The builder keeps the latest stamp it has seen.
		$record['last_log_ts'] = 6000.002;

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'stopped' );

		$this->assertArrayNotHasKey( 'last_entry', $found['metric'] );
		$this->assertStringContainsString( 'unknown', $found['title'] );
		$this->assertEqualsWithDelta( 2.0, $found['metric']['last_entry_ms'], 1e-6 );
		$this->assertGreaterThanOrEqual( $found['metric']['last_entry_ms'], $found['metric']['last_line_ms'] );
	}

	/**
	 * A runaway's builder keeps stamping `last_log_ts` after it stops storing
	 * entries, so the stored stop and the last line seen are two facts, each
	 * with its own time.
	 */
	public function test_a_runaway_reports_its_stored_stop_and_its_last_line_apart(): void {
		$record                = $this->timed_out_record();
		$record['last_log_ts'] = 6005.250;

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'stopped' );

		$this->assertSame( 'include (start)', $found['metric']['last_entry'] );
		$this->assertEqualsWithDelta( 467.0, $found['metric']['last_entry_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 5250.0, $found['metric']['last_line_ms'], 1e-6 );
		$this->assertStringContainsString( 'stopped logging 467.0ms in', $found['title'] );
	}

	/**
	 * An aborted record's producer drained its open spans as `(orphaned)`
	 * completes before the terminal, so the stop point sits ahead of that
	 * drain and the spans it closed are the ones that were open.
	 */
	public function test_an_aborted_record_reads_its_stop_ahead_of_the_drain(): void {
		$record                 = $this->loaded_record();
		$record['error_status'] = 'A';
		$record['timestamp']    = 6000.0;
		$record['duration_ms']  = 912.0;
		$record['entries']      = [
			[ 'n' => 1, 'ts' => 6000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 6000.002, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 3, 'ts' => 6000.100, 'k' => 'job (start)', 'l' => 'cache_cozy', 'm' => '' ],
			[ 'n' => 4, 'ts' => 6000.310, 'k' => 'http (start)', 'l' => 'Cache_Cozy::warm', 'm' => 'https://example.test/' ],
			[ 'n' => 5, 'ts' => 6000.911, 'k' => 'http (complete)', 'm' => '(orphaned)', 'duration_ms' => 601.0 ],
			[ 'n' => 6, 'ts' => 6000.911, 'k' => 'job (complete)', 'm' => '(orphaned)', 'duration_ms' => 811.0 ],
			[ 'n' => 7, 'ts' => 6000.911, 'k' => 'memory', 'm' => [ 'peak' => '40MB' ] ],
			[ 'n' => 8, 'ts' => 6000.912, 'k' => 'resources', 'm' => 'utime => 0.090000, stime => 0.020000' ],
			[ 'n' => 9, 'ts' => 6000.912, 'k' => 'process (aborted)', 'm' => '' ],
		];
		$record['last_log_ts']  = 6000.912;

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'stopped' );

		$this->assertSame( 'http (start)', $found['metric']['last_entry'] );
		$this->assertEqualsWithDelta( 310.0, $found['metric']['last_entry_ms'], 1e-6 );
		$this->assertSame( 'job: cache_cozy › http: Cache_Cozy::warm', $found['metric']['open'] );
		$this->assertSame( 912.0, $found['metric']['aborted_after_ms'] );
		$this->assertArrayNotHasKey( 'evicted_after_ms', $found['metric'] );
	}

	public function test_a_record_that_finished_has_no_stopped_finding(): void {
		$record                = $this->timed_out_record();
		$record['error_status'] = '-';

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'stopped' ) );
	}

	/** Where a trace stopped is the whole finding, so a record that cannot say fails loud. */
	public function test_a_timed_out_record_without_its_last_stamp_is_refused(): void {
		$record = $this->timed_out_record();
		unset( $record['last_log_ts'] );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'last_log_ts' );
		Findings::for_request( $record, $this->instrumented_rule() );
	}

	/**
	 * A timed-out duration ends where the builder gave up (decision 24), so no
	 * share of it is a finding; what reads timestamps and rule facts still runs.
	 */
	public function test_a_timed_out_record_reports_no_share_of_its_duration(): void {
		$record               = $this->timed_out_record();
		$record['flame_data'] = [
			'name'     => 'request',
			'value'    => 856000.0,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 856000.0,
					'children' => [
						[ 'name' => 'template_redirect hook', 'value' => 700000.0, 'children' => [] ],
						[ 'name' => 'giant-7734 plugin', 'value' => 250000.0, 'children' => [] ],
					],
				],
			],
		];
		$record['profiles']   = [ 'the_content hook' => [ 'count' => 340, 'time' => 90000.0, 'entries' => [] ] ];

		$kinds = $this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) );

		$this->assertNotContains( 'dominant_span', $kinds );
		$this->assertNotContains( 'plugin_load', $kinds );
		$this->assertNotContains( 'repetition', $kinds );
		$this->assertContains( 'entry_gap', $kinds, 'a gap is a timestamp difference, which a timeout does not falsify' );
	}

	/** The cold start on a timed-out record leaves the eviction boundary to `stopped`. */
	public function test_a_timed_out_cold_start_carries_no_duration(): void {
		$found = $this->of_kind(
			Findings::for_request( $this->timed_out_record(), new Rule( 'ffff11112222', '/calendar/today', Rule::ACTION_LOG ) ),
			'insufficient_instrumentation'
		);

		$this->assertNotNull( $found );
		$this->assertArrayNotHasKey( 'duration_ms', $found['metric'] );
	}

	/** @param list<array<string,mixed>> $findings */
	private function kinds( array $findings ): array {
		return \array_column( $findings, 'kind' );
	}

	private function of_kind( array $findings, string $kind ): ?array {
		foreach ( $findings as $finding ) {
			if ( $kind === $finding['kind'] ) {
				return $finding;
			}
		}
		return null;
	}

	public function test_a_healthy_record_yields_nothing(): void {
		$this->assertSame(
			[],
			Findings::for_request( $this->healthy_record(), $this->instrumented_rule() )
		);
	}

	public function test_a_dominant_span_is_named_with_its_share(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [ [ 'name' => 'render_block hook', 'value' => 366.0, 'children' => [] ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'render_block hook', $found['metric']['name'] );
		$this->assertSame( 'wp_loaded hook › render_block hook', $found['metric']['path'] );
		$this->assertArrayNotHasKey( 'i', $found['metric'], 'no entry opens this span, so none is named' );
		$this->assertEqualsWithDelta( 0.915, $found['metric']['share'], 0.001 );
		$this->assertSame( 'flame', $found['measured'] );
		$this->assertSame( 'a1b2c3d4e5f6', $found['rule_id'] );
		// A hook span carries no shape table, so the metric names none.
		$this->assertArrayNotHasKey( 'shape', $found['metric'] );
	}

	public function test_a_dominant_query_span_names_the_statement_it_spent_most_on(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'sql: WP_Query->get_posts',
				'value'    => 372.0,
				'count'    => 667,
				'max'      => 9.5,
				'children' => [],
				'shapes'   => [
					'SELECT * FROM wp_posts WHERE post_type = ?' => [ 4, 41.0 ],
					'SELECT * FROM wp_postmeta WHERE post_id IN (?)' => [ 663, 331.0 ],
				],
			],
		];

		$metric = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' )['metric'];

		$this->assertSame( 'SELECT * FROM wp_postmeta WHERE post_id IN (?)', $metric['shape'] );
		$this->assertSame( 663, $metric['shape_calls'] );
		$this->assertEqualsWithDelta( 331.0, $metric['shape_ms'], 1e-6 );
	}

	public function test_an_unfolded_record_names_the_statement_from_its_entries(): void {
		// Its entries still hold every statement, but a brief ships sixty of
		// them; the dominant finding is what has to carry the answer.
		$slow    = 'SELECT wp_posts.ID FROM wp_posts WHERE wp_posts.ID NOT IN (?)';
		$fast    = 'SELECT option_value FROM wp_options WHERE option_name = ?';
		$entries = [ [ 'n' => 1, 'ts' => 3000.0, 'k' => 'process (start)', 'm' => '' ] ];
		$ts      = 3000.0;
		for ( $i = 0; $i < 6; $i++ ) {
			$ms        = 0 === $i % 3 ? 3.0 : 150.0;
			$entries[] = [ 'n' => 2, 'ts' => $ts += 0.001, 'k' => 'sql (start)', 'l' => 'WP_Query->get_posts', 'm' => '' ];
			$entries[] = [ 'n' => 3, 'ts' => $ts += $ms / 1000, 'k' => 'sql (complete)', 'm' => 0 === $i % 3 ? $fast : $slow, 'duration_ms' => $ms ];
		}
		$entries[] = [ 'n' => 4, 'ts' => $ts += 0.01, 'k' => 'process (complete)', 'm' => '', 'duration_ms' => ( $ts - 3000.0 ) * 1000 ];

		$record                = $this->healthy_record();
		$record['duration_ms'] = ( $ts - 3000.0 ) * 1000;
		$record['entries']     = $entries;
		unset( $record['flame'] );
		$record['flame_data']  = Flame_Tree::build_flame_data( $entries );
		Flame_Tree::strip_name_suffixes( $record['flame_data'] );

		$metric = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' )['metric'] ?? [];

		$this->assertSame( 'sql: WP_Query->get_posts', $metric['name'] ?? null );
		$this->assertSame( $slow, $metric['shape'] ?? null );
		$this->assertSame( 4, $metric['shape_calls'] ?? null );
		$this->assertEqualsWithDelta( 600.0, $metric['shape_ms'] ?? 0.0, 1e-6 );
	}

	public function test_a_gap_after_the_fold_still_reports_once_the_head_spans_close(): void {
		// Decision 21 keeps the close of every span the head left open, so
		// the tail is back at the request's own level by the time it gaps.
		$record            = $this->healthy_record();
		$record['folded']  = true;
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.010, 'k' => 'plugins_loaded hook (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.020, 'k' => 'entries (aggregated)', 'm' => '9 merged' ],
			[ 'n' => 4, 'ts' => 2000.500, 'k' => 'plugins_loaded hook (complete)', 'm' => '', 'duration_ms' => 490.0 ],
			[ 'n' => 5, 'ts' => 2000.600, 'k' => 'init hook', 'm' => '' ],
			[ 'n' => 6, 'ts' => 2002.100, 'k' => 'wp_loaded hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'init hook', $found['metric']['after'] ?? null );
	}

	public function test_an_unfolded_record_names_a_late_slow_statement(): void {
		// Nothing is stored, so the live fold's memory budget has no work to
		// do here — keeping it buckets a statement that first appears late.
		$record  = $this->healthy_record();
		$pad     = \str_repeat( 'x', 480 );
		$entries = [ [ 'n' => 1, 'ts' => 4000.0, 'k' => 'process (start)', 'm' => '' ] ];
		$ts      = 4000.0;
		for ( $i = 0; $i < 90; $i++ ) {
			$entries[] = [ 'n' => 2, 'ts' => $ts += 0.001, 'k' => 'sql (start)', 'l' => "caller{$i}", 'm' => '' ];
			$entries[] = [ 'n' => 3, 'ts' => $ts += 0.001, 'k' => 'sql (complete)', 'm' => "SELECT {$i} {$pad}", 'duration_ms' => 1.0 ];
		}
		$late      = 'SELECT * FROM wp_posts WHERE post_name = ?';
		$entries[] = [ 'n' => 4, 'ts' => $ts += 0.001, 'k' => 'sql (start)', 'l' => 'heavy', 'm' => '' ];
		$entries[] = [ 'n' => 5, 'ts' => $ts += 2.0, 'k' => 'sql (complete)', 'm' => $late, 'duration_ms' => 2000.0 ];
		$entries[] = [ 'n' => 6, 'ts' => $ts += 0.001, 'k' => 'process (complete)', 'm' => '' ];

		$record['duration_ms'] = ( $ts - 4000.0 ) * 1000;
		$record['entries']     = $entries;
		unset( $record['flame'] );
		$record['flame_data'] = Flame_Tree::build_flame_data( $entries );
		Flame_Tree::strip_name_suffixes( $record['flame_data'] );

		$metric = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' )['metric'] ?? [];

		$this->assertSame( 'sql: heavy', $metric['name'] ?? null );
		$this->assertSame( $late, $metric['shape'] ?? null );
	}

	/** The proposal for a span you cannot see inside adds detail, never removes it. */
	public function test_a_dominant_span_proposes_more_visibility(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'more', $found['proposal']['direction'] );
		$this->assertSame( 'significant_events', $found['proposal']['field'] );
		$this->assertSame( 'wp_loaded', $found['proposal']['value'] );
	}

	public function test_repetition_reports_the_call_count(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'the_content hook' => [ 'count' => 340, 'time' => 30.0, 'entries' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertNotNull( $found );
		$this->assertSame( 340, $found['metric']['count'] );
		$this->assertSame( 'the_content hook', $found['metric']['name'] );
	}

	/**
	 * A repeat of a hook the rule already marks significant carries no share
	 * of its listeners, so the proposal's verdict reads alone: no number, and
	 * no claim about where the rest of the time is.
	 */
	public function test_repetition_of_a_significant_hook_gives_only_the_verdict(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'the_content hook' => [ 'count' => 340, 'time' => 30.0, 'entries' => [] ],
		];
		$rule = $this->instrumented_rule()->with( [ 'significant_events' => [ 'the_content' ] ] );

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'repetition' );

		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertSame( 'It is already a significant event; no rule edit reaches further.', $found['proposal']['why'] );
	}

	public function test_repetition_reads_the_records_own_exclusive_time(): void {
		// `Flame_Tree` never writes `count` — only `Flame_Fold` does — so on an
		// UNFOLDED record every node defaulted to 1 and this finding could not
		// fire at all. 3,997 of 4,000 sampled records are unfolded, and all but
		// three carry `profiles`, where the builder already subtracted each
		// state's children from it. No flame counts here, deliberately.
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'query hook' => [ 'count' => 9_400, 'time' => 1.75, 'entries' => [] ],
			'restapi'    => [ 'count' => 88, 'time' => 246.5, 'entries' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertNotNull( $found );
		$this->assertSame( 'restapi', $found['metric']['name'] );
		$this->assertSame( 88, $found['metric']['count'] );
		$this->assertEqualsWithDelta( 246.5, $found['metric']['self_ms'], 1e-6 );
		// Pin the source and the divisor: inverting them would ship green.
		$this->assertSame( 'profiles', $found['measured'] );
		$this->assertEqualsWithDelta( 2.801, $found['metric']['each_ms'], 1e-3 );
	}

	public function test_a_dominant_span_reports_what_it_spends_in_its_own_body(): void {
		// A wrapper holds ~all of the time and spends almost none of it. The
		// inclusive share alone named the engine and sent the reader nowhere.
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[
				'name'     => 'pyrobase',
				'value'    => 396.0,
				'count'    => 1,
				'children' => [
					[
						'name'     => 'restapi',
						'value'    => 198.0,
						'count'    => 62,
						'max'      => 7.25,
						'children' => [],
					],
					[
						'name'     => 'query_sql',
						'value'    => 182.0,
						'count'    => 120,
						'max'      => 3.5,
						'children' => [],
					],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'pyrobase', $found['metric']['name'] );
		// 396.0 held, 380.0 of it inside its two children.
		$this->assertEqualsWithDelta( 16.0, $found['metric']['self_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 0.04, $found['metric']['self_share'], 1e-6 );
		// restapi alone reaches half of it, so its 50% is not the body's split.
		$this->assertStringContainsString( 'It spends 4% of the request in its own body', $found['detail'] );
		$this->assertStringContainsString( 'holds 50% of it', $found['detail'] );
	}

	/** Named children holding all it contains state its split, so it is said once. */
	public function test_children_named_whole_state_the_split_once(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [
					[ 'name' => 'render_block hook', 'value' => 131.0, 'children' => [] ],
					[ 'name' => 'the_content hook', 'value' => 117.0, 'children' => [] ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( [ 'render_block hook', 'the_content hook' ], \array_column( $found['metric']['explained'], 'name' ) );
		// 248ms of 372ms is 67%; its body's 124ms of the request would be 31%.
		$this->assertStringContainsString( 'hold 67% of it', $found['detail'] );
		$this->assertSame( 1, \substr_count( $found['detail'], '%' ) );
	}

	public function test_children_named_in_part_keep_the_body_sentence_when_the_percents_round_alike(): void {
		$record                      = $this->healthy_record();
		$record['duration_ms']       = 1100.0;
		$record['flame']['value']    = 1100.0;
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 50.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 1000.0,
				'children' => [
					[ 'name' => 'render_block hook', 'value' => 400.0, 'children' => [] ],
					[ 'name' => 'the_content hook', 'value' => 346.0, 'children' => [] ],
					[ 'name' => 'wp_footer hook', 'value' => 8.0, 'children' => [] ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		// 746ms named and 754ms contained both round to 75%; 8ms is unnamed.
		$this->assertSame( [ 'render_block hook', 'the_content hook' ], \array_column( $found['metric']['explained'], 'name' ) );
		$this->assertStringContainsString( 'hold 75% of it', $found['detail'] );
		// Its body's 246ms of the 1100ms request is 22%.
		$this->assertStringContainsString( 'It spends 22% of the request in its own body', $found['detail'] );
	}

	public function test_an_already_significant_custom_event_is_not_credited_with_listeners(): void {
		// Marking a CUSTOM event significant does nothing but keep it from
		// being auto-disabled — the application logs the span itself, so there
		// are no listeners to go and read.
		// Position 6 is significant_events; position 8 is hooks.
		$rule                        = new Rule( 'c0ffeeba5e12', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [ 'pyrobase' ], [], [ 'init' ] );
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[
				'name'     => 'pyrobase',
				'value'    => 372.5,
				'count'    => 1,
				'children' => [],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertStringNotContainsString( 'listener', $found['detail'] );
		$this->assertStringContainsString( 'auto-disabled', $found['detail'] );
		// The proposal says the same thing twice over, and was wrong twice.
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringNotContainsString( 'listener', $found['proposal']['why'] );
	}

	/**
	 * A custom event's profile key is its KIND — `function`, `include`,
	 * `macro` — and the flame names each span by its label. The live hub
	 * request's `function` profile counted 167 calls across 41 functions,
	 * none called 50 times: nothing there repeated.
	 */
	public function test_a_custom_kind_whose_labels_each_stay_under_the_threshold_is_quiet(): void {
		$entries = [];
		for ( $i = 0; $i < 41; $i++ ) {
			$entries[ "fn{$i}" ] = [ 3.7, 4 ];
		}
		$entries['bestofyearurl'] = [ 10.0, Findings::REPETITION_COUNT - 1 ];
		$record                   = $this->healthy_record();
		$record['profiles']       = [
			'function' => [ 'count' => 213, 'time' => 161.7, 'entries' => $entries ],
		];

		$this->assertNotContains(
			'repetition',
			$this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) )
		);
	}

	/** One label past the threshold is the repeat, named by kind and label as the flame names it. */
	public function test_repetition_of_a_custom_kind_names_the_label_that_repeated(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'function' => [
				'count'   => 233,
				'time'    => 132.5,
				'entries' => [
					'componentwrap' => [ 33.0, 112 ],
					'bestofyearurl' => [ 61.5, 77 ],
					'adjusturl'     => [ 38.0, 44 ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertNotNull( $found );
		$this->assertSame( 'function: bestofyearurl', $found['metric']['name'] );
		$this->assertSame( 77, $found['metric']['count'] );
		$this->assertEqualsWithDelta( 61.5, $found['metric']['self_ms'], 1e-6 );
		$this->assertStringStartsWith( 'function: bestofyearurl fired 77 times', $found['title'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/**
	 * A query's label names the caller that ran it, and each caller is judged
	 * on its own: 600 queries spread over fifteen callers repeat under none.
	 */
	public function test_queries_spread_over_many_callers_repeat_under_none(): void {
		$callers = [];
		for ( $i = 0; $i < 15; $i++ ) {
			$callers[ "Caller{$i}->load" ] = [ 19.4, 40 ];
		}
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'sql' => [ 'count' => 600, 'time' => 291.0, 'entries' => $callers ],
		];
		$rule = $this->instrumented_rule()->with( [ 'log_queries' => true ] );

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $rule ), 'repetition' ) );
	}

	/**
	 * Calls a per-label profile tallied under no label — unlabelled, or past
	 * the builder's label cap — still repeated, under the bare state.
	 */
	public function test_unlabelled_calls_of_a_custom_kind_repeat_as_the_bare_state(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'function' => [
				'count'   => 201,
				'time'    => 93.0,
				'entries' => [ 'byline' => [ 5.5, 1 ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertNotNull( $found );
		$this->assertSame( 'function', $found['metric']['name'] );
		$this->assertSame( 200, $found['metric']['count'] );
		$this->assertEqualsWithDelta( 87.5, $found['metric']['self_ms'], 1e-6 );
	}

	/** A listener's name IS its callable, so its profile counts as one span. */
	public function test_repetition_of_a_listener_counts_the_listener(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'Image_CDN::filter_the_content @10' => [
				'count'   => 130,
				'time'    => 44.25,
				'entries' => [ 'stray' => [ 44.25, 130 ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'Image_CDN::filter_the_content @10', $found['metric']['name'] );
		$this->assertSame( 130, $found['metric']['count'] );
	}

	/**
	 * A traced hook labels each firing with its caller, and the caller that
	 * fired it per item is the repeat; marking the hook is what shows it.
	 */
	public function test_repetition_of_a_traced_hook_names_the_caller_that_repeated(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'the_content hook' => [
				'count'   => 212,
				'time'    => 47.5,
				'entries' => [
					'Theme->render_card'    => [ 30.0, 150 ],
					'Widget->render_teaser' => [ 17.5, 62 ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'the_content hook: Theme->render_card', $found['metric']['name'] );
		$this->assertSame( 150, $found['metric']['count'] );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertSame( 'the_content', $found['proposal']['value'] );
	}

	public function test_a_repeat_that_spends_nothing_is_not_the_finding(): void {
		// Exclusive time can go negative when a record's spans do not add up:
		// 1 of 20,844 live profile states is, `include` at -336,176ms across
		// 11,349 calls. A repeat holding nothing is not a cost, and a negative
		// is a broken record rather than a slow one.
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'include'    => [ 'count' => 11_349, 'time' => -336_176.5, 'entries' => [] ],
			'query hook' => [ 'count' => 240, 'time' => 0.0, 'entries' => [] ],
		];

		$this->assertNotContains(
			'repetition',
			$this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) )
		);
	}

	/** A count past the threshold holding a sliver of the request is no finding (eln 7ea9's `sql ×136`). */
	public function test_a_repeat_holding_a_sliver_of_the_request_is_quiet(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 10540.0;
		$record['profiles']    = [ 'sql' => [ 'count' => 136, 'time' => 6.3, 'entries' => [] ] ];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/** Eln 296vb's `sql ×1125` holds 7.2% of the request, past `REPETITION_SHARE`. */
	public function test_a_repeat_holding_a_share_of_the_request_reports(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 10540.0;
		$record['profiles']    = [ 'sql' => [ 'count' => 1125, 'time' => 757.9, 'entries' => [] ] ];

		$this->assertSame( 1125, $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' )['metric']['count'] ?? null );
	}

	/**
	 * Few calls holding a quarter of the request repeat whatever their count:
	 * hub 2yoz's `Event_$_Time` ran 40 times for 3633ms of 8968ms.
	 */
	public function test_few_calls_holding_a_quarter_of_the_request_repeat(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 8968.4;
		$record['profiles']    = [
			'fieldvalues' => [ 'count' => 40, 'time' => 3633.0, 'entries' => [ 'Event_$_Time' => [ 3633.0, 40 ] ] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'fieldvalues: Event_$_Time', $found['metric']['name'] ?? null );
	}

	public function test_few_calls_holding_less_than_a_quarter_are_quiet(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 8968.4;
		$record['profiles']    = [
			'fieldvalues' => [ 'count' => 40, 'time' => 1800.0, 'entries' => [ 'Event_$_Time' => [ 1800.0, 40 ] ] ],
		];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/** One span is one finding: the dominant span already names the repeat. */
	public function test_a_repeat_the_dominant_span_names_is_not_reported_twice(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'the_content hook', 'value' => 372.0, 'children' => [] ],
		];
		$record['profiles']          = [ 'the_content hook' => [ 'count' => 340, 'time' => 372.0, 'entries' => [] ] ];

		$findings = Findings::for_request( $record, $this->instrumented_rule() );

		$this->assertNotNull( $this->of_kind( $findings, 'dominant_span' ) );
		$this->assertNull( $this->of_kind( $findings, 'repetition' ) );
	}

	/** A body that rounds to all of the request claims no remainder inside it. */
	public function test_a_dominant_span_spending_all_of_it_claims_no_remainder(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[
				'name'     => 'pyrobase',
				'value'    => 399.0,
				'children' => [ [ 'name' => 'restapi', 'value' => 0.5, 'children' => [] ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'pyrobase', $found['metric']['name'] );
		$this->assertStringNotContainsString( 'the rest is inside', $found['detail'] );
	}

	public function test_a_span_below_the_repetition_threshold_is_quiet(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'the_content hook' => [
				'count' => Findings::REPETITION_COUNT - 1,
				'time'  => 30.0,
				'entries' => [],
			],
		];

		$this->assertNotContains(
			'repetition',
			$this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) )
		);
	}

	/**
	 * A flame value is a rendering artifact (decision 12), so a share divides
	 * by the request's measured duration: a span holding 93% of a root that
	 * fell short of the duration holds 37% of the request, and no subtraction
	 * between the two is reported as unmeasured time.
	 */
	public function test_a_share_divides_by_the_requests_duration(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 1000.0;
		$record['flame']       = [
			'name'     => 'request',
			'value'    => 400.0,
			'children' => [
				[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
				[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
			],
		];

		$this->assertSame( [], Findings::for_request( $record, $this->instrumented_rule() ) );
	}

	public function test_a_dominant_title_names_its_share_of_the_request(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'wp_loaded hook holds 93% of the request', $found['title'] );
	}

	/**
	 * A record carrying no tree is rebuilt from its entries through the same
	 * fold that names an unfolded record's statements, so it still gets its
	 * share findings.
	 */
	public function test_a_flameless_record_is_read_from_its_entries(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 900.0;
		$record['entries']     = [
			[ 'n' => 1, 'ts' => 7000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 7000.020, 'k' => 'wp_loaded hook (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 7000.830, 'k' => 'wp_loaded hook (complete)', 'm' => '', 'duration_ms' => 810.0 ],
			[ 'n' => 4, 'ts' => 7000.900, 'k' => 'process (complete)', 'm' => '', 'duration_ms' => 900.0 ],
		];
		unset( $record['flame'] );

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'wp_loaded hook', $found['metric']['name'] ?? null );
		$this->assertEqualsWithDelta( 0.9, $found['metric']['share'] ?? 0.0, 1e-9 );
	}

	/** The cold start reports what the rule and the record hold, and no flame total. */
	public function test_the_cold_start_metric_carries_no_flame_total(): void {
		$found = $this->of_kind(
			Findings::for_request( $this->healthy_record(), new Rule( 'ffff11112222', '/calendar/today', Rule::ACTION_LOG ) ),
			'insufficient_instrumentation'
		);

		$this->assertSame( [ 'duration_ms', 'spans', 'hooks' ], \array_keys( $found['metric'] ) );
	}

	public function test_an_entry_gap_reports_where_it_opened(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'init hook', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2003.700, 'k' => 'wp_loaded hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertNotNull( $found );
		$this->assertEqualsWithDelta( 3650.0, $found['metric']['gap_ms'], 0.1 );
		$this->assertSame( 'init hook', $found['metric']['after'] );
		$this->assertSame( 'wp_loaded hook', $found['metric']['before'] );
	}

	/**
	 * The two `resources` samples bracket the window, so its CPU time bounds
	 * how much of the gap the process spent working, assuming nothing about
	 * where inside the bracket that CPU fell.
	 */
	public function test_a_gap_inside_the_resource_samples_is_split_by_cpu(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.002, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000, maxrss => 81234' ],
			[ 'n' => 3, 'ts' => 2000.050, 'k' => 'init hook', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2003.700, 'k' => 'wp_loaded hook', 'm' => '' ],
			[ 'n' => 5, 'ts' => 2003.750, 'k' => 'resources', 'm' => 'utime => 3.540000, stime => 0.010000, maxrss => 91234' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertEqualsWithDelta( 3402.0, $found['metric']['on_cpu_min_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 3500.0, $found['metric']['on_cpu_max_ms'], 1e-6 );
		$this->assertStringContainsString( 'this process was on CPU for at least 3.4s of it', $found['detail'] );
		$this->assertStringContainsString( 'was not on CPU for at least 150.0ms of it', $found['detail'] );
		$this->assertStringContainsString( 'child process', $found['detail'] );
	}

	/** A window the two samples do not bracket has no CPU figure at all. */
	public function test_a_gap_outside_the_resource_samples_has_no_cpu_split(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.700, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 3, 'ts' => 2000.750, 'k' => 'init hook', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.760, 'k' => 'resources', 'm' => 'utime => 0.050000, stime => 0.010000' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertEqualsWithDelta( 700.0, $found['metric']['gap_ms'], 1e-6 );
		$this->assertArrayNotHasKey( 'on_cpu_min_ms', $found['metric'] );
		$this->assertStringNotContainsString( 'CPU', $found['detail'] );
	}

	public function test_a_gap_with_one_resource_sample_has_no_cpu_split(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.002, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 3, 'ts' => 2000.700, 'k' => 'init hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertArrayNotHasKey( 'on_cpu_max_ms', $found['metric'] );
	}

	public function test_a_round_trip_is_not_a_gap(): void {
		// Nothing logs between a query's own start and complete: that window
		// IS the query, measured as its duration, and no hook can bracket it.
		// The gap outside it is the one a hook could explain.
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'sql (start)', 'l' => 'Yoast\\WP\\Lib\\ORM::execute', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.880, 'k' => 'sql (complete)', 'm' => 'SELECT ?', 'duration_ms' => 830.4 ],
			[ 'n' => 4, 'ts' => 2001.500, 'k' => 'wp_loaded hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertNotNull( $found );
		$this->assertSame( 'sql (complete)', $found['metric']['after'] );
		$this->assertEqualsWithDelta( 620.0, $found['metric']['gap_ms'], 0.1 );
	}

	/**
	 * The live hub request: the theme's `require_once` runs inside
	 * `template_redirect`, and the nested engine's own producer opens
	 * `gyrobase` 306ms later. That window sits inside an open span, but no
	 * span measures it — the hook's duration covers it only as unexplained
	 * self time.
	 */
	public function test_a_gap_inside_an_open_span_before_its_first_child_reports(): void {
		$found = $this->of_kind( Findings::for_request( $this->hub_record(), $this->instrumented_rule() ), 'entry_gap' );

		$this->assertNotNull( $found );
		$this->assertEqualsWithDelta( 306.0, $found['metric']['gap_ms'], 0.1 );
		$this->assertSame( 'template_redirect hook (start)', $found['metric']['after'] );
		$this->assertSame( 'gyrobase (start)', $found['metric']['before'] );
		$this->assertSame( 'template_redirect hook', $found['metric']['inside'] );
		$this->assertStringContainsString( 'inside template_redirect hook', $found['title'] );
	}

	/**
	 * The engine's start-up is the measured `gyrobase init` span, so a window
	 * ending where the engine opens its own log is an ordinary gap: a record
	 * whose producer wrote no init span left it unmeasured.
	 */
	public function test_a_window_ending_at_the_engine_start_is_an_ordinary_gap(): void {
		$findings = Findings::for_request( $this->hub_record(), $this->instrumented_rule() );

		$gap = $this->of_kind( $findings, 'entry_gap' );
		$this->assertStringNotContainsString( 'start-up', $gap['detail'] );
		$this->assertStringStartsWith( 'Nothing instrumented ran in that window.', $gap['detail'] );
		$this->assertStringNotContainsString( 'nested engine', $gap['proposal']['why'] );
		$this->assertSame( 'none', $this->of_kind( $findings, 'dominant_span' )['proposal']['action'] );
		$this->assertNull( $this->of_kind( $findings, 'repetition' ) );
		$this->assertNotContains( 'add_hooks', \array_column( \array_column( $findings, 'proposal' ), 'action' ) );
	}

	/** A measured `gyrobase init` span leaves the engine's start-up no gap. */
	public function test_a_measured_engine_init_leaves_no_start_up_gap(): void {
		$record            = $this->hub_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 5000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.104, 'k' => 'template_redirect hook (start)', 'l' => 'require_once', 'm' => '' ],
			[ 'n' => 3, 'ts' => 5000.109, 'k' => 'gyrobase init (start)', 'm' => '' ],
			[ 'n' => 1, 'ts' => 5000.408, 'k' => 'gyrobase init (complete)', 'm' => '', 'duration_ms' => 299.0 ],
			[ 'n' => 2, 'ts' => 5000.410, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 5000.530, 'k' => 'include (start)', 'l' => '/Macros/Global.html', 'm' => '' ],
			[ 'n' => 4, 'ts' => 5000.650, 'k' => 'include (complete)', 'm' => '', 'duration_ms' => 120.0 ],
			[ 'n' => 5, 'ts' => 5000.700, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 290.0 ],
			[ 'n' => 4, 'ts' => 5000.720, 'k' => 'template_redirect hook (complete)', 'm' => '', 'duration_ms' => 616.0 ],
			[ 'n' => 5, 'ts' => 5000.740, 'k' => 'process (complete)', 'm' => '' ],
		];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' ) );
	}

	/** The window after the nested engine closes its log holds its exit. */
	public function test_a_gap_after_the_nested_engine_closes_is_its_exit(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 5000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.010, 'k' => 'template_redirect hook (start)', 'l' => 'require_once', 'm' => '' ],
			[ 'n' => 1, 'ts' => 5000.020, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.040, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 20.0 ],
			[ 'n' => 3, 'ts' => 5000.420, 'k' => 'template_redirect hook (complete)', 'm' => '', 'duration_ms' => 410.0 ],
			[ 'n' => 4, 'ts' => 5000.430, 'k' => 'process (complete)', 'm' => '' ],
		];

		$gap = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'gyrobase (complete)', $gap['metric']['after'] );
		$this->assertStringContainsString( 'exit', $gap['detail'] );
		$this->assertSame( 'none', $gap['proposal']['action'] );
	}

	/**
	 * A hook whose own time is the nested engine's exit gains nothing from
	 * being marked significant: its one listener spawned the engine, and
	 * timing that listener re-measures the same window.
	 */
	public function test_a_dominant_span_whose_own_time_is_the_engine_exit_proposes_nothing(): void {
		$found = $this->of_kind( Findings::for_request( $this->engine_hook_record( 500.0 ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'template_redirect hook: require_once', $found['metric']['name'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'exit', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'start-up', $found['proposal']['why'] );
	}

	/**
	 * A window before the engine opens its log is no edge: own time that only
	 * an unmeasured start-up fills still asks to see inside the hook.
	 */
	public function test_an_unmeasured_engine_start_up_lends_no_edge(): void {
		$found = $this->of_kind( Findings::for_request( $this->engine_hook_record( 500.0, false ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
	}

	/** Own time well past the engine's window still asks to see inside the hook. */
	public function test_a_dominant_span_with_own_time_beyond_the_engine_window_proposes_visibility(): void {
		$found = $this->of_kind( Findings::for_request( $this->engine_hook_record( 1200.0 ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
	}

	/**
	 * A hook holding a nested render whose engine took 120ms to start, as its
	 * `gyrobase init` span measures, rendered for 120ms and took 20ms to close
	 * its log, with `$hook_ms` of the request inside the hook.
	 *
	 * @param float $hook_ms The hook's value.
	 * @param bool  $init    Whether the producer wrote the `gyrobase init` span.
	 */
	private function engine_hook_record( float $hook_ms, bool $init = true ): array {
		$record                = $this->loaded_record();
		$record['duration_ms'] = $hook_ms / 0.7;
		$record['entries']     = [
			[ 'n' => 1, 'ts' => 5000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.104, 'k' => 'template_redirect hook (start)', 'l' => 'require_once', 'm' => '' ],
			...( $init ? [
				[ 'n' => 3, 'ts' => 5000.110, 'k' => 'gyrobase init (start)', 'm' => '' ],
				[ 'n' => 1, 'ts' => 5000.230, 'k' => 'gyrobase init (complete)', 'm' => '', 'duration_ms' => 120.0 ],
			] : [] ),
			[ 'n' => $init ? 2 : 1, 'ts' => 5000.232, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => $init ? 3 : 2, 'ts' => 5000.352, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 120.0 ],
			[ 'n' => $init ? 4 : 3, 'ts' => 5000.372, 'k' => 'template_redirect hook (complete)', 'm' => '', 'duration_ms' => $hook_ms ],
		];
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => $record['duration_ms'],
			'children' => [
				[
					'name'     => 'template_redirect hook: require_once',
					'value'    => $hook_ms,
					'children' => [
						...( $init ? [ [ 'name' => 'gyrobase init', 'value' => 120.0, 'children' => [] ] ] : [] ),
						[ 'name' => 'gyrobase', 'value' => 120.0, 'children' => [] ],
					],
				],
			],
		];
		return $record;
	}

	/**
	 * A transport's own time IS its measurement: a listener a significant
	 * `sql` logs inside the round trip does not turn the wait into a gap.
	 */
	public function test_a_round_trip_with_a_listener_inside_is_not_a_gap(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'sql (start)', 'l' => 'WP_Query->get_posts', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.051, 'k' => 'Rewriter::query @10 (start)', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.053, 'k' => 'Rewriter::query @10 (complete)', 'm' => '', 'duration_ms' => 2.0 ],
			[ 'n' => 5, 'ts' => 2000.373, 'k' => 'sql (complete)', 'm' => 'SELECT ?', 'duration_ms' => 323.0 ],
			[ 'n' => 6, 'ts' => 2000.400, 'k' => 'process (complete)', 'm' => '' ],
		];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' ) );
	}

	/**
	 * Past the fold marker the record is head, marker and a tail picked out
	 * of a longer run, so two rows side by side there were not side by side
	 * when logged. Only a window whose `n` steps by one is trusted.
	 */
	public function test_a_window_between_spliced_rows_is_not_a_gap(): void {
		$record            = $this->healthy_record();
		$record['folded']  = true;
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.001, 'k' => 'request', 'm' => 'GET https://kea.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 2000.010, 'k' => 'template_redirect hook (start)', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.020, 'k' => 'render (start)', 'l' => 'Event.html', 'm' => '' ],
			[ 'n' => 5, 'ts' => 2000.030, 'k' => 'render (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 6, 'ts' => 2000.030, 'k' => 'entries (aggregated)', 'm' => '93 entries merged under memory pressure' ],
			[ 'n' => 88, 'ts' => 2005.000, 'k' => 'template_redirect hook (complete)', 'm' => '', 'duration_ms' => 4990.0 ],
			[ 'n' => 131, 'ts' => 2012.000, 'k' => 'wp_footer hook (start)', 'm' => '' ],
			[ 'n' => 132, 'ts' => 2012.010, 'k' => 'wp_footer hook (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 133, 'ts' => 2012.020, 'k' => 'process (complete)', 'm' => '' ],
		];

		$findings = Findings::for_request( $record, $this->instrumented_rule() );

		$this->assertNull( $this->of_kind( $findings, 'entry_gap' ) );
		$this->assertSame( 'trim_hooks', $this->of_kind( $findings, 'truncation' )['proposal']['action'] );
		$this->assertNotContains( 'add_hooks', \array_column( \array_column( $findings, 'proposal' ), 'action' ) );
	}

	/**
	 * A record that timed out never closes its spans, so the widest window
	 * inside one is judged when the record ends.
	 */
	public function test_a_span_the_record_never_closes_still_reports_its_gap(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.010, 'k' => 'template_redirect hook (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.020, 'k' => 'render (start)', 'l' => 'Event.html', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.030, 'k' => 'render (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 5, 'ts' => 2000.467, 'k' => 'warning', 'm' => 'slow include' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertNotNull( $found );
		$this->assertEqualsWithDelta( 437.0, $found['metric']['gap_ms'], 0.1 );
		$this->assertSame( 'template_redirect hook', $found['metric']['inside'] );
	}

	/** A close names its span and closes every span still open inside it. */
	public function test_a_close_ends_every_span_still_open_inside_it(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.010, 'k' => 'outer (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.020, 'k' => 'unclosed (start)', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.030, 'k' => 'inner (start)', 'm' => '' ],
			[ 'n' => 5, 'ts' => 2000.040, 'k' => 'inner (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 6, 'ts' => 2000.050, 'k' => 'outer (complete)', 'm' => '', 'duration_ms' => 40.0 ],
			[ 'n' => 7, 'ts' => 2000.690, 'k' => 'wp_footer hook', 'm' => '' ],
			[ 'n' => 8, 'ts' => 2000.700, 'k' => 'process (complete)', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertEqualsWithDelta( 640.0, $found['metric']['gap_ms'], 0.1 );
		$this->assertArrayNotHasKey( 'inside', $found['metric'], 'both spans closed, so the window is the request\'s own' );
		$this->assertSame( 'none', $found['proposal']['action'], 'no hook is known to run at the request\'s own level' );
		$this->assertArrayNotHasKey( 'hooks', $found['proposal'] );
	}

	/** A body holding nearly all of its own span claims no remainder inside it. */
	public function test_a_body_holding_its_whole_span_claims_no_remainder(): void {
		$record                      = $this->healthy_record();
		$record['duration_ms']       = 1000.0;
		$record['flame']             = [
			'name'     => 'request',
			'value'    => 1000.0,
			'children' => [
				[
					'name'     => 'wp_loaded hook',
					'value'    => 700.0,
					'children' => [ [ 'name' => 'render_block hook', 'value' => 2.0, 'children' => [] ] ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'wp_loaded hook', $found['metric']['name'] );
		$this->assertStringNotContainsString( 'the rest is inside', $found['detail'] );
	}

	/** The nested engine's CPU is its own process's, which the PHP samples never count. */
	public function test_a_gap_inside_the_nested_engine_has_no_cpu_split(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.002, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 1, 'ts' => 2000.010, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.020, 'k' => 'include (start)', 'l' => '/a.html', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.030, 'k' => 'include (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 4, 'ts' => 2000.630, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 620.0 ],
			[ 'n' => 3, 'ts' => 2000.640, 'k' => 'resources', 'm' => 'utime => 0.090000, stime => 0.010000' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'gyrobase', $found['metric']['inside'] );
		$this->assertArrayNotHasKey( 'on_cpu_min_ms', $found['metric'] );
		$this->assertStringNotContainsString( 'CPU', $found['detail'] );
	}

	/** Only the engine runs inside the dominant span lend it their edges. */
	public function test_another_spans_engine_run_lends_the_dominant_span_no_edges(): void {
		$record                = $this->engine_hook_record( 1200.0 );
		$record['entries'][]   = [ 'n' => 4, 'ts' => 5000.740, 'k' => 'wp_footer hook (start)', 'm' => '' ];
		$record['entries'][]   = [ 'n' => 1, 'ts' => 5001.440, 'k' => 'gyrobase (start)', 'm' => '' ];
		$record['entries'][]   = [ 'n' => 2, 'ts' => 5001.450, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 10.0 ];
		$record['entries'][]   = [ 'n' => 5, 'ts' => 5001.460, 'k' => 'wp_footer hook (complete)', 'm' => '', 'duration_ms' => 720.0 ];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'template_redirect hook: require_once', $found['metric']['name'] );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
	}

	/** Under `MIN_DURATION_MS` no share is a finding, a repeat's included. */
	public function test_a_repeat_in_a_request_too_short_to_share_is_quiet(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 40.0;
		$record['profiles']    = [ 'the_content hook' => [ 'count' => 340, 'time' => 30.0, 'entries' => [] ] ];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/**
	 * Past the fold marker the record is spliced, so the rows either side of
	 * a nested render's close were not neighbours, and the hours between them
	 * lend the span around it no exit edge.
	 */
	public function test_an_engine_edge_across_the_fold_marker_lends_no_edge(): void {
		$record                = $this->engine_hook_record( 1200.0 );
		$record['folded']      = true;
		$record['entries']     = [
			[ 'n' => 1, 'ts' => 5000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.104, 'k' => 'template_redirect hook (start)', 'l' => 'require_once', 'm' => '' ],
			[ 'n' => 3, 'ts' => 5000.110, 'k' => 'entries (aggregated)', 'm' => '40 entries merged under memory pressure' ],
			[ 'n' => 1, 'ts' => 5000.410, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.710, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 300.0 ],
			[ 'n' => 9, 'ts' => 5004.740, 'k' => 'template_redirect hook (complete)', 'm' => '', 'duration_ms' => 1200.0 ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
	}

	/** A record with no gap never reads its CPU samples, so a malformed one costs nothing. */
	public function test_a_gapless_record_never_parses_its_cpu_samples(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.002, 'k' => 'resources', 'm' => 'utime => 0,128355, stime => 0,000000' ],
			[ 'n' => 3, 'ts' => 2000.100, 'k' => 'init hook', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.120, 'k' => 'resources', 'm' => 'utime => 0,138355, stime => 0,000000' ],
		];

		$this->assertSame( [], Findings::for_request( $record, $this->instrumented_rule() ) );
	}

	/**
	 * The profiler's plugin rows are written after the opening rows and
	 * stamped before them, so a window is measured from the latest stamp seen:
	 * a back-dated row neither stretches nor invents one.
	 */
	public function test_back_dated_rows_stretch_no_gap(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.030, 'k' => 'request', 'm' => 'GET https://example.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 2000.031, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 4, 'ts' => 2000.010, 'k' => 'jetpack plugin (start)', 'm' => '' ],
			[ 'n' => 5, 'ts' => 2000.020, 'k' => 'jetpack plugin (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 6, 'ts' => 2000.330, 'k' => 'init hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'resources', $found['metric']['after'] );
		$this->assertEqualsWithDelta( 299.0, $found['metric']['gap_ms'], 1e-6 );
		$this->assertSame( 2, $found['metric']['from_i'] );
	}

	/** A record resolved to no tree is not folded again from its entries. */
	public function test_a_resolved_empty_tree_is_not_refolded(): void {
		$record = [
			'flame_data' => [],
			'entries'    => [
				[ 'n' => 1, 'ts' => 1.0, 'k' => 'init hook (start)', 'm' => '' ],
				[ 'n' => 2, 'ts' => 1.1, 'k' => 'init hook (complete)', 'm' => '', 'duration_ms' => 100.0 ],
			],
		];

		$this->assertSame( [], Findings::flame_of( $record ) );
	}

	/** A record whose widest gap sits inside `the_content`, after its one child closed. */
	private function content_gap_record(): array {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.010, 'k' => 'the_content hook (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.020, 'k' => 'render_block hook (start)', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.030, 'k' => 'render_block hook (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 5, 'ts' => 2000.430, 'k' => 'the_content hook (complete)', 'm' => '', 'duration_ms' => 420.0 ],
			[ 'n' => 6, 'ts' => 2000.440, 'k' => 'process (complete)', 'm' => '' ],
		];
		return $record;
	}

	/** A gap inside a span asks to see inside that span, as a dominant span would. */
	public function test_a_gap_inside_a_span_proposes_seeing_inside_it(): void {
		$found = $this->of_kind( Findings::for_request( $this->content_gap_record(), $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'the_content hook', $found['metric']['inside'] );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertSame( 'the_content', $found['proposal']['value'] );
	}

	/** A dominant span names the entry that first opened it, so a reader can ask about it. */
	public function test_a_dominant_span_names_the_entry_that_opened_it(): void {
		$record                = $this->content_gap_record();
		$record['duration_ms'] = 500.0;
		$record['entries']     = [ [ 'n' => 0, 'ts' => 1999.999, 'k' => 'environment_v3', 'm' => [] ], ...$record['entries'] ];
		unset( $record['flame'] );
		$record['flame_data'] = Flame_Tree::build_flame_data( $record['entries'] );

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 2, $found['metric']['i'], 'the index counts the environment row' );
		$this->assertSame( 'the_content hook', $found['metric']['path'] );
	}

	/** A gap names its rows by their position in the record and when it opened. */
	public function test_a_gap_names_its_rows_and_when_it_opened(): void {
		$found = $this->of_kind( Findings::for_request( $this->content_gap_record(), $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 3, $found['metric']['from_i'] );
		$this->assertSame( 4, $found['metric']['to_i'] );
		$this->assertEqualsWithDelta( 30.0, $found['metric']['at_ms'], 1e-6 );
	}

	/** One edit, proposed once: the dominant span already asks to see inside it. */
	public function test_a_gap_inside_the_dominant_span_leaves_the_proposal_to_it(): void {
		$record                      = $this->content_gap_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'the_content hook', 'value' => 372.0, 'children' => [] ],
		];

		$findings = Findings::for_request( $record, $this->instrumented_rule() );

		$this->assertSame( 'mark_significant', $this->of_kind( $findings, 'dominant_span' )['proposal']['action'] );
		$gap = $this->of_kind( $findings, 'entry_gap' );
		$this->assertSame( 'none', $gap['proposal']['action'] );
		$this->assertStringContainsString( 'dominant', $gap['proposal']['why'] );
	}

	/** The lifecycle bracket the cold start proposes splits the request first. */
	public function test_a_gap_under_a_hookless_rule_leaves_the_proposal_to_the_bracket(): void {
		$findings = Findings::for_request( $this->content_gap_record(), new Rule( 'ffff11112222', '/calendar/today', Rule::ACTION_LOG ) );

		$this->assertSame( Findings::LIFECYCLE_BRACKET, $this->of_kind( $findings, 'insufficient_instrumentation' )['proposal']['hooks'] );
		$this->assertSame( 'none', $this->of_kind( $findings, 'entry_gap' )['proposal']['action'] );
	}

	/**
	 * The profiler writes its plugin rows after the `request` line, stamped
	 * earlier, so logging plugin loads lights nothing in the window before it.
	 */
	public function test_a_gap_before_the_request_line_proposes_nothing(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.380, 'k' => 'request', 'm' => 'GET https://example.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 2000.390, 'k' => 'init hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/** A span holding only point entries is a leaf: its duration measures it whole. */
	public function test_a_leaf_span_split_by_a_point_entry_is_not_a_gap(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'render (start)', 'l' => 'Event.html', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.380, 'k' => 'warning', 'm' => 'slow include' ],
			[ 'n' => 4, 'ts' => 2000.720, 'k' => 'render (complete)', 'm' => '', 'duration_ms' => 670.0 ],
			[ 'n' => 5, 'ts' => 2000.740, 'k' => 'process (complete)', 'm' => '' ],
		];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' ) );
	}

	/**
	 * The live hub request (guwl6an5lu24qxh8wlr8l06cuwo9mm67), as stored: the
	 * theme's `require_once` runs inside `template_redirect`, the nested
	 * engine's own producer opens `gyrobase` 306ms later, and its `function`
	 * profile counts 41 functions, none called 50 times.
	 */
	private function hub_record(): array {
		$functions = [ 'googlerichsnippets.content' => [ 111.0, 1 ], 'bestofyearurl' => [ 10.0, 32 ], 'adjusturl' => [ 10.3, 20 ] ];
		for ( $i = 0; $i < 38; $i++ ) {
			$functions[ "fn{$i}" ] = [ 2.25, 3 ];
		}
		$record                = $this->loaded_record();
		$record['duration_ms'] = 1663.0;
		$record['profiles']    = [ 'function' => [ 'count' => 167, 'time' => 216.8, 'entries' => $functions ] ];
		$record['entries']     = [
			[ 'n' => 1, 'ts' => 5000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.104, 'k' => 'template_redirect hook (start)', 'l' => 'require_once', 'm' => '' ],
			[ 'n' => 1, 'ts' => 5000.410, 'k' => 'gyrobase (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 5000.530, 'k' => 'include (start)', 'l' => '/Macros/Global.html', 'm' => '' ],
			[ 'n' => 3, 'ts' => 5000.650, 'k' => 'include (complete)', 'm' => '', 'duration_ms' => 120.0 ],
			[ 'n' => 4, 'ts' => 5000.700, 'k' => 'gyrobase (complete)', 'm' => '', 'duration_ms' => 290.0 ],
			[ 'n' => 3, 'ts' => 5000.720, 'k' => 'template_redirect hook (complete)', 'm' => '', 'duration_ms' => 616.0 ],
			[ 'n' => 4, 'ts' => 5000.740, 'k' => 'process (complete)', 'm' => '' ],
		];
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 1663.0,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 1663.0,
					'children' => [
						[
							'name'     => 'template_redirect hook: require_once',
							'value'    => 1529.0,
							'children' => [
								[
									'name'     => 'gyrobase',
									'value'    => 1178.0,
									'children' => [
										[ 'name' => 'include: /Macros/Global.html', 'value' => 121.0, 'children' => [] ],
										[ 'name' => 'macro: pageheader', 'value' => 216.0, 'children' => [] ],
										[ 'name' => 'include: /Responsive/Grids/Resp-Two-Col-1.html', 'value' => 731.0, 'children' => [] ],
									],
								],
							],
						],
					],
				],
			],
		];
		return $record;
	}

	public function test_a_leaf_spans_own_window_is_not_a_gap(): void {
		// A hook with one slow callback and nothing instrumented inside it
		// has the same shape as a query: its own start and complete, far
		// apart. The span measured that time; nothing went unlogged.
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'the_content hook (start)', 'm' => '' ],
			[ 'n' => 3, 'ts' => 2000.950, 'k' => 'the_content hook (complete)', 'm' => '', 'duration_ms' => 900.0 ],
			[ 'n' => 4, 'ts' => 2001.000, 'k' => 'process (complete)', 'm' => '' ],
		];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' ) );
	}

	public function test_a_gap_across_the_fold_marker_is_not_a_gap(): void {
		// The merged entries ARE that window. Reading it as idle time also had
		// the record proposing MORE hooks while `truncation` proposed fewer.
		$record            = $this->healthy_record();
		$record['folded']  = true;
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'entries (aggregated)', 'm' => '87074 entries merged under memory pressure' ],
			[ 'n' => 3, 'ts' => 2355.940, 'k' => 'gyrobase (complete)', 'm' => 'logged 87080 messages' ],
		];

		$this->assertNotContains(
			'entry_gap',
			$this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) ),
			'the fold marker explains the window; truncation already reports it'
		);
	}

	public function test_a_real_gap_still_reports_in_a_folded_record(): void {
		// Only the window the marker covers is exempt, not the whole record.
		$record            = $this->healthy_record();
		$record['folded']  = true;
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'entries (aggregated)', 'm' => 'merged' ],
			// The marker's own gap is the WIDEST, so the old code reported it.
			[ 'n' => 3, 'ts' => 2009.000, 'k' => 'init hook', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2013.800, 'k' => 'wp_loaded hook', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertNotNull( $found );
		$this->assertSame( 'init hook', $found['metric']['after'], 'the 8950ms marker pair is skipped, not merely out-ranked' );
		$this->assertEqualsWithDelta( 4800.0, $found['metric']['gap_ms'], 0.1 );
	}

	public function test_a_gap_below_the_threshold_is_quiet(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.100, 'k' => 'init hook', 'm' => '' ],
		];

		$this->assertNotContains(
			'entry_gap',
			$this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) )
		);
	}

	public function test_a_folded_record_says_so(): void {
		$record            = $this->healthy_record();
		$record['folded']  = true;
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 1000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 1000.010, 'k' => 'entries (aggregated)', 'm' => '812 entries merged under memory pressure' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'truncation' );

		$this->assertNotNull( $found );
		$this->assertStringContainsString( 'absence of evidence', $found['detail'] );
	}

	public function test_no_rule_at_all_is_the_first_class_cold_start_finding(): void {
		$found = $this->of_kind( Findings::for_request( $this->healthy_record(), null ), 'insufficient_instrumentation' );

		$this->assertNotNull( $found );
		$this->assertNull( $found['rule_id'] );
		$this->assertSame( 'create_rule', $found['proposal']['action'] );
		$this->assertSame( '/calendar/today', $found['proposal']['pattern'] );
	}

	/**
	 * A record stamped with a rule this ruleset does not hold is a provenance
	 * finding, not an instrumentation one: the site ran a ruleset this hub
	 * never pushed, or the rule was deleted since. Calling it "no rule
	 * governs" sends the operator to the rule editor, where no edit reaches it.
	 */
	public function test_a_stamped_rule_this_ruleset_lacks_is_named_not_called_ungoverned(): void {
		$record            = $this->healthy_record();
		$record['rule_id'] = 'cbcdd45b2cba';
		$findings          = Findings::for_request( $record, null );
		$found             = $this->of_kind( $findings, 'unresolved_rule' );

		$this->assertNotNull( $found );
		$this->assertSame( 'cbcdd45b2cba', $found['rule_id'] );
		$this->assertStringContainsString( 'cbcdd45b2cba', $found['title'] );
		$this->assertArrayNotHasKey( 'proposal', $found );
		$this->assertNull( $this->of_kind( $findings, 'insufficient_instrumentation' ) );
	}

	/**
	 * One record, one rule id, and no edit to propose: every finding on a
	 * stamped-miss record carries the stamp and drops its proposal, since a
	 * rule this ruleset does not hold is nothing the editor can act on.
	 */
	public function test_every_finding_on_a_stamped_miss_record_carries_the_stamp_and_no_proposal(): void {
		$record                      = $this->healthy_record();
		$record['rule_id']           = 'cbcdd45b2cba';
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [ [ 'name' => 'render_block hook', 'value' => 366.0, 'children' => [] ] ],
			],
		];
		$record['folded']            = true;
		$findings = Findings::for_request( $record, null );

		$this->assertNotEmpty( $this->of_kind( $findings, 'truncation' ) );
		$dominant = $this->of_kind( $findings, 'dominant_span' );
		$this->assertNotNull( $dominant );
		// What the unknown rule logged inside the span is not known here.
		$this->assertStringNotContainsString( 'listeners', $dominant['detail'] );
		foreach ( $findings as $finding ) {
			$this->assertSame( 'cbcdd45b2cba', $finding['rule_id'], $finding['kind'] );
			$this->assertArrayNotHasKey( 'proposal', $finding, $finding['kind'] );
		}
	}

	/**
	 * A well-instrumented rule against a request that produced no spans — a
	 * fast 404, a request that bailed early — is NOT a rule registering no
	 * hooks, and saying so at severity high is a factual falsehood that
	 * proposes adding hooks the rule already has.
	 */
	public function test_a_rule_with_hooks_and_no_spans_is_not_called_hookless(): void {
		$record          = $this->loaded_record();
		$record['flame_data'] = [ 'name' => 'request', 'value' => 0.0, 'children' => [] ];

		$found = $this->of_kind(
			Findings::for_request( $record, $this->instrumented_rule() ),
			'insufficient_instrumentation'
		);

		$this->assertNotNull( $found );
		$this->assertStringNotContainsString( 'registers no hooks', $found['title'] );
		$this->assertStringNotContainsString( 'registers no hooks', $found['proposal']['why'] );
		$this->assertSame( 2, $found['metric']['hooks'], 'the rule it names has two' );
	}

	/**
	 * A rule can instrument a request WITHOUT naming a single hook: significant
	 * events measure the interior just as well, and the flame proves it. Keying
	 * "no interior" on the hook list alone made a record carrying 295 spans and
	 * a five-deep tree report at severity high that nothing inside it was
	 * measured, in the same finding whose own numbers line said `spans=295`.
	 */
	public function test_a_rule_instrumenting_by_significant_events_is_not_called_hookless(): void {
		$by_events = new Rule(
			'0b01f4ec2288',
			'/wp-admin/post.php?post=3663570',
			Rule::ACTION_LOG,
			0,
			0.0,
			[ 'render_block', 'the_content', 'pre_get_posts', 'shutdown' ],
			[],
			[]
		);

		$this->assertNull(
			$this->of_kind(
				Findings::for_request( $this->healthy_record(), $by_events ),
				'insufficient_instrumentation'
			)
		);
	}

	/**
	 * A folded record's `truncation` proposes FEWER logged events, so
	 * `cold_start` cannot answer the same record with the LIFECYCLE BRACKET —
	 * six hooks across the whole request, the contradiction decision 13 records
	 * for `entry_gap`, arriving by a second route.
	 *
	 * Narrower than "never propose more": a real gap still reports in a folded
	 * record (`entry_gap`) and asks for ONE hook at a named place, which is a
	 * different claim from instrumenting everything and is deliberate.
	 */
	public function test_a_folded_record_with_an_interior_asks_for_no_lifecycle_bracket(): void {
		$record           = $this->healthy_record();
		$record['folded'] = true;
		$by_events        = new Rule(
			'0b01f4ec2288',
			'/wp-admin/post.php?post=3663570',
			Rule::ACTION_LOG,
			0,
			0.0,
			[ 'render_block', 'the_content' ],
			[],
			[]
		);

		$findings = Findings::for_request( $record, $by_events );

		$this->assertNull(
			$this->of_kind( $findings, 'insufficient_instrumentation' ),
			'a folded record with 295 spans has an interior; asking to bracket the whole request contradicts its own trim proposal'
		);
		foreach ( $findings as $finding ) {
			$this->assertNotSame(
				Findings::LIFECYCLE_BRACKET,
				$finding['proposal']['hooks'] ?? null,
				'the whole-request bracket cannot ride a record that folded'
			);
		}
	}

	public function test_a_rule_registering_no_hooks_proposes_the_lifecycle_bracket(): void {
		$bare = new Rule( 'ffff11112222', '/calendar/today', Rule::ACTION_LOG );

		$found = $this->of_kind( Findings::for_request( $this->healthy_record(), $bare ), 'insufficient_instrumentation' );

		$this->assertNotNull( $found );
		$this->assertStringContainsString( 'registers no hooks', $found['title'] );
		$this->assertSame( 'ffff11112222', $found['rule_id'] );
		$this->assertSame( 'add_hooks', $found['proposal']['action'] );
		$this->assertSame( Findings::LIFECYCLE_BRACKET, $found['proposal']['hooks'] );
		$this->assertSame( 'more', $found['proposal']['direction'] );
	}

	/**
	 * The logger binds its rule inside `plugins_loaded`, so a bracket naming
	 * that hook times nothing; `setup_theme` is the first phase it can see.
	 */
	public function test_the_bracket_opens_on_the_first_hook_the_logger_can_time(): void {
		$this->assertSame(
			[ 'setup_theme', 'init', 'wp_loaded', 'template_redirect', 'wp_head', 'shutdown' ],
			Findings::LIFECYCLE_BRACKET
		);
	}

	/** A rule's pattern is a path, so an absolute URL as one would match nothing. */
	public function test_a_rule_to_create_is_patterned_on_the_path(): void {
		$record        = $this->healthy_record();
		$record['url'] = 'https://example.test/calendar/today';

		$found = $this->of_kind( Findings::for_request( $record, null ), 'insufficient_instrumentation' );

		$this->assertSame( '/calendar/today', $found['proposal']['pattern'] );
	}

	/**
	 * The bisect subdivides only the phase that held the time — proposing forty
	 * hooks is what this exists to avoid.
	 */
	public function test_the_next_round_narrows_on_the_phase_that_held_the_time(): void {
		$record = $this->healthy_record();
		$bracket = new Rule(
			'ffff11112222',
			'/calendar/today',
			Rule::ACTION_LOG,
			0,
			0.0,
			[],
			[],
			Findings::LIFECYCLE_BRACKET
		);
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $bracket ), 'dominant_span' );

		$this->assertSame( 'wp_loaded', $found['proposal']['value'] );
		$this->assertStringContainsString( 'Marking wp_loaded a significant event', $found['proposal']['why'] );
	}

	/** Every proposal that adds instrumentation names what removes it again. */
	public function test_an_adding_proposal_names_its_own_removal(): void {
		$found = $this->of_kind(
			Findings::for_request( $this->healthy_record(), new Rule( 'ffff11112222', '/calendar/today', Rule::ACTION_LOG ) ),
			'insufficient_instrumentation'
		);

		$this->assertNotSame( '', $found['proposal']['undo'] );
	}

	public function test_a_url_with_no_rule_reports_what_is_known(): void {
		$found = $this->of_kind(
			Findings::for_url(
				[ 'url' => 'https://example.test/calendar/today', 'count' => 4210, 'avg_ms' => 812.0, 'max_ms' => 2600.0, 'max_peak_mb' => 96.0 ],
				null
			),
			'insufficient_instrumentation'
		);

		$this->assertNotNull( $found );
		$this->assertSame( 4210, $found['metric']['count'] );
		$this->assertSame( 2600.0, $found['metric']['max_ms'] );
		$this->assertSame( 'create_rule', $found['proposal']['action'] );
	}

	/**
	 * A span is only a HOOK when it says so. `App\Core::hook_start()` names a
	 * hook span `<hook> hook`; everything else on the flame is either a custom
	 * event the application logged itself or a wrapped listener, and
	 * `bind_current_scope()` leaves a significant event naming one of those
	 * unbound. Proposing it anyway sends the reader to change a setting that
	 * cannot do anything.
	 */
	public function test_a_dominant_custom_event_is_not_proposed_as_significant(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'include: /Responsive/Grids/Resp-One-Col-1.html',
				'value'    => 372.0,
				'children' => [],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertStringNotContainsString( 'listeners', $found['detail'] );
		$this->assertStringContainsString( 'custom event', $found['proposal']['why'] );
	}

	public function test_a_dominant_hook_is_still_proposed_as_significant(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
	}

	/**
	 * With hook tracing on, `App\Core::hook_start()` labels the hook span with
	 * its caller, and the frame is named `<hook> hook: <caller>`. It is the
	 * same hook, so the proposal names the hook the rule can bind, never the
	 * caller.
	 */
	public function test_a_traced_hook_span_is_still_a_hook(): void {
		$found = $this->of_kind( Findings::for_request( $this->traced_hook_record(), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertSame( 'significant_events', $found['proposal']['field'] );
		$this->assertSame( 'the_content', $found['proposal']['value'] );
		$this->assertStringStartsWith( 'Marking the_content a significant event', $found['proposal']['why'] );
	}

	/** The rule names the hook bare; the frame carries the caller; they are one span. */
	public function test_a_traced_hook_the_rule_marks_significant_proposes_nothing(): void {
		$rule = $this->instrumented_rule()->with( [ 'significant_events' => [ 'the_content' ] ] );

		$found = $this->of_kind( Findings::for_request( $this->traced_hook_record(), $rule ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'its own time', $found['detail'] );
		$this->assertStringNotContainsString( 'read those', $found['detail'] );
	}

	/**
	 * A significant hook whose wrapped listeners hold under half of it spends
	 * the rest in its own time: listeners the logger cannot wrap and the
	 * dispatch around them. The detail states the share they hold, measured,
	 * and names that own time, never the listeners; the proposal gives only
	 * the verdict, so the two never say the same sentence.
	 */
	public function test_a_significant_hook_whose_own_time_dominates_names_the_unwrapped_listeners(): void {
		$rule                        = $this->instrumented_rule()->with( [ 'significant_events' => [ 'template_redirect' ] ] );
		$record                      = $this->healthy_record();
		$record['duration_ms']       = 8317.0;
		$record['flame']['value']    = 8317.0;
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 23.0, 'children' => [] ],
			[
				'name'     => 'template_redirect hook',
				'value'    => 8191.0,
				'children' => [ [ 'name' => 'Redirect_Manager::maybe_redirect @10', 'value' => 57.0, 'children' => [] ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertSame( 'template_redirect hook', $found['metric']['name'] );
		$this->assertArrayNotHasKey( 'explained', $found['metric'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
		// 57ms of listeners inside 8,191ms rounds to 1%.
		$this->assertStringContainsString( 'hold 1% of it', $found['detail'] );
		$this->assertStringContainsString( 'its own time', $found['detail'] );
		$this->assertStringContainsString( 'by reference', $found['detail'] );
		$this->assertStringContainsString( 'start priority', $found['detail'] );
		$this->assertStringNotContainsString( 'read those', $found['detail'] );
		$this->assertSame( 'It is already a significant event; no rule edit reaches further.', $found['proposal']['why'] );
		$this->assertStringNotContainsString( $found['proposal']['why'], $found['detail'] );
		$this->assertSame( 1, \substr_count( $found['detail'], '%' ) );
	}

	/**
	 * The share the logged spans hold is of the FRAME, and the detail states
	 * the frame's split once. The hook holds 65% of the request and its
	 * listeners 37% of it: 24% against the request, 0% with no children.
	 */
	public function test_a_significant_hooks_logged_share_is_of_the_hook_and_said_once(): void {
		$rule                        = $this->instrumented_rule()->with( [ 'significant_events' => [ 'template_redirect' ] ] );
		$record                      = $this->healthy_record();
		$record['duration_ms']       = 2417.0;
		$record['flame']['value']    = 2417.0;
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 23.0, 'children' => [] ],
			[
				'name'     => 'template_redirect hook',
				'value'    => 1571.0,
				'children' => [
					[ 'name' => 'Redirect_Manager::maybe_redirect @10', 'value' => 412.0, 'children' => [] ],
					[ 'name' => 'WPSEO_Redirect::handle @20', 'value' => 169.0, 'children' => [] ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertSame( 'template_redirect hook', $found['metric']['name'] );
		$this->assertArrayNotHasKey( 'chain', $found['metric'] );
		$this->assertArrayNotHasKey( 'explained', $found['metric'] );
		// 581ms of listeners inside 1,571ms rounds to 37%.
		$this->assertStringContainsString( 'hold 37% of it', $found['detail'] );
		$this->assertSame( 1, \substr_count( $found['detail'], '%' ) );
	}

	/**
	 * A `SPAN_ADVICE` detail is prose, never a format string: the two rows
	 * stating the held share name it as `{held}`, followed by a literal `%`
	 * that `sprintf()` would read as the `% o` conversion, rendering 37 as
	 * `{held}45f it`. The render tests above show that share intact.
	 */
	public function test_span_advice_names_the_share_in_the_two_rows_that_state_it(): void {
		/** @var array<string,array<string,string>> $advice */
		$advice  = ( new \ReflectionClassConstant( Findings::class, 'SPAN_ADVICE' ) )->getValue();
		$holding = \array_filter( $advice, static fn ( array $row ): bool => \str_contains( $row['detail'], '{held}' ) );

		$this->assertSame( [ 'significant:hook', 'significant:transport' ], \array_keys( $holding ) );
		foreach ( $holding as $row ) {
			$this->assertStringContainsString( 'hold {held}% of it', $row['detail'] );
		}
	}

	/**
	 * Below a chain the frame sentence is the stop's split and the leading
	 * one the dominant span's, so each is said once and both stand.
	 */
	public function test_a_chain_states_the_dominant_spans_body_and_the_stops_split(): void {
		$rule                        = $this->instrumented_rule()->with( [ 'significant_events' => [ 'the_content' ] ] );
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [
					[
						'name'     => 'the_content hook',
						'value'    => 220.0,
						'children' => [ [ 'name' => 'Image_CDN::filter_the_content @10', 'value' => 50.0, 'children' => [] ] ],
					],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertSame( 'wp_loaded hook', $found['metric']['name'] );
		$this->assertSame( [ 'the_content hook' ], \array_column( $found['metric']['chain'], 'name' ) );
		// 152ms of 400ms in its own body; 220ms holds 55%; 50ms of 220ms is 23%.
		$this->assertStringContainsString( 'It spends 38% of the request in its own body', $found['detail'] );
		$this->assertStringContainsString( 'Inside it, the_content hook holds 55% of the request.', $found['detail'] );
		$this->assertStringContainsString( 'hold 23% of it', $found['detail'] );
	}

	/**
	 * A custom event in the flame was logged because the application logged
	 * it, so proposing to enable it changes nothing; no rule edit reaches
	 * further inside it than the application already logs.
	 */
	public function test_a_dominant_custom_event_proposes_no_rule_edit(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'render: Event.html', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertSame( 'none', $found['proposal']['direction'] );
		$this->assertArrayNotHasKey( 'field', $found['proposal'] );
		$this->assertSame( '', $found['proposal']['undo'] );
		$this->assertStringContainsString( 'render is a custom event', $found['proposal']['why'] );
		$this->assertStringContainsString( 'no rule edit reaches inside it', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'enabled on this rule', $found['proposal']['why'] );
		// A childless span has no children to send the reader to.
		$this->assertStringNotContainsString( 'children', $found['detail'] );
		$this->assertStringNotContainsString( 'children', $found['proposal']['why'] );
	}

	/**
	 * The live hub request: `gyrobase` holds 71% of the request, and one grid
	 * include holds most of `gyrobase`. The finding follows the time into it
	 * instead of asking for visibility the record already has.
	 */
	public function test_a_dominant_span_one_child_holds_descends_into_it(): void {
		$grid = 'include: /Responsive/Grids/Resp-Two-Col-1.html';

		$found = $this->of_kind( Findings::for_request( $this->hub_record(), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'gyrobase', $found['metric']['name'] );
		$this->assertSame( [ [ 'name' => $grid, 'ms' => 731.0, 'share' => 731.0 / 1663.0 ] ], $found['metric']['chain'] );
		$this->assertStringContainsString( "Inside it, {$grid} holds 44% of the request.", $found['detail'] );
		$this->assertStringNotContainsString( 'appears only where', $found['detail'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'custom event', $found['proposal']['why'] );
	}

	/**
	 * A hook whose nested spans hold most of it has a visible interior too:
	 * marking it significant is not the next step, its heaviest child is.
	 */
	public function test_a_dominant_hook_whose_children_hold_it_proposes_no_visibility(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [
					[ 'name' => 'render_block hook', 'value' => 158.0, 'children' => [] ],
					[ 'name' => 'the_content hook', 'value' => 137.0, 'children' => [] ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'wp_loaded hook', $found['metric']['name'] );
		$this->assertSame( [ 'render_block hook', 'the_content hook' ], \array_column( $found['metric']['explained'], 'name' ) );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringNotContainsString( 'invisible', $found['detail'] );
	}

	/** A body holding most of its own time keeps asking for visibility. */
	public function test_a_dominant_hook_spending_most_in_its_body_still_proposes_visibility(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [ [ 'name' => 'render_block hook', 'value' => 141.0, 'children' => [] ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertArrayNotHasKey( 'explained', $found['metric'] );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
	}

	/** A significant `sql` under query logging wraps the `query` filter's listeners, inside the span. */
	public function test_a_transport_span_the_rule_marks_significant_has_its_listeners_logged(): void {
		$rule = $this->instrumented_rule()->with( [ 'significant_events' => [ 'sql' ], 'log_queries' => true ] );

		$found = $this->of_kind( Findings::for_request( $this->query_record(), $rule ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'round trip', $found['detail'] );
		$this->assertStringNotContainsString( 'read those', $found['detail'] );
	}

	/**
	 * A significant query span whose listeners hold under half of it spends
	 * the rest on the round trip, so the detail states the share they hold,
	 * measured, and sends the reader to the statement it names, never to the
	 * listeners on the `query` filter; the proposal gives only the verdict.
	 */
	public function test_a_significant_query_span_whose_self_time_dominates_points_at_the_round_trip(): void {
		$rule                        = $this->instrumented_rule()->with( [ 'significant_events' => [ 'sql' ], 'log_queries' => true ] );
		$statement                   = 'SELECT COUNT(*), SUM(CRC32(CONCAT_WS(?, ID, post_modified))) FROM wp_posts WHERE ID BETWEEN ? AND ?';
		$record                      = $this->healthy_record();
		$record['duration_ms']       = 600611.0;
		$record['flame']['value']    = 600611.0;
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 87.0, 'children' => [] ],
			[
				'name'     => 'sql: Automattic\\Jetpack\\Sync\\Replicastore\\Table_Checksum->calculate_checksum',
				'value'    => 600424.0,
				'count'    => 3,
				'max'      => 600301.0,
				'children' => [ [ 'name' => 'Query_Monitor::filter_query @10', 'value' => 40.0, 'children' => [] ] ],
				'shapes'   => [ $statement => [ 3, 600384.0 ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertSame( $statement, $found['metric']['shape'] );
		$this->assertArrayNotHasKey( 'explained', $found['metric'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'round trip', $found['detail'] );
		$this->assertStringContainsString( 'statement', $found['detail'] );
		$this->assertStringNotContainsString( 'read those', $found['detail'] );
		$this->assertStringContainsString( '`query` filter', $found['proposal']['why'] );
		$this->assertStringContainsString( 'no rule edit reaches further', $found['proposal']['why'] );
		foreach ( [ 'round trip', 'statement', '%' ] as $repeated ) {
			$this->assertStringNotContainsString( $repeated, $found['proposal']['why'] );
		}
	}

	/** A rule that does not log the span cannot wrap its listeners: the edit to propose is the flag, not the mark. */
	public function test_a_transport_span_under_a_rule_not_logging_it_is_not_proposed_as_significant(): void {
		$found = $this->of_kind( Findings::for_request( $this->query_record(), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'log_transport', $found['proposal']['action'] );
		$this->assertSame( 'log_queries', $found['proposal']['field'] );
		$this->assertSame( 'log_queries', $found['proposal']['value'] );
		$this->assertStringContainsString( 'log_queries', $found['proposal']['why'] );
	}

	/** The rule lists `sql` but does not log the span: nothing is wrapped, and re-proposing `sql` would change nothing. */
	public function test_a_transport_span_marked_significant_without_its_logging_is_not_read_as_wrapped(): void {
		$rule = $this->instrumented_rule()->with( [ 'significant_events' => [ 'sql' ] ] );

		$found = $this->of_kind( Findings::for_request( $this->query_record(), $rule ), 'dominant_span' );

		$this->assertSame( 'log_transport', $found['proposal']['action'] );
		$this->assertSame( 'log_queries', $found['proposal']['field'] );
		$this->assertStringContainsString( 'not wrapped', $found['detail'] );
	}

	/** `healthy_record()` with one query span holding the time. */
	private function query_record(): array {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'sql: WP_Query->get_posts', 'value' => 372.0, 'children' => [] ],
		];
		return $record;
	}

	/** `healthy_record()` with one traced hook frame holding the time. */
	private function traced_hook_record(): array {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'the_content hook: Yoast\\WP\\SEO\\Builders\\Indexable_Link_Builder->build',
				'value'    => 372.0,
				'children' => [],
			],
		];
		return $record;
	}

	/**
	 * A wrapped listener is the finest grain this logger has — it only exists
	 * because its hook is ALREADY significant, so there is nothing left to
	 * switch on.
	 */
	public function test_a_dominant_listener_span_proposes_nothing_to_enable(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'Image_CDN::filter_the_content @10', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/**
	 * A listener registered at a NEGATIVE priority is still a listener —
	 * `add_action( 'init', $cb, -10 )` labels its span `Foo::bar @-10`. A
	 * pattern without the sign reads it as a custom event and hands back advice
	 * about enabling application logging for a WordPress callback.
	 */
	public function test_a_negative_priority_listener_is_still_a_listener(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'Image_CDN::filter_the_content @-10', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/**
	 * A rule stores the bare hook name and the span carries the ` hook`
	 * suffix, so a rule listing `wp_loaded` already covers the span named
	 * `wp_loaded hook` — proposing it again is advice to do nothing.
	 */
	public function test_a_hook_already_significant_without_the_suffix_is_recognised(): void {
		$rule   = new Rule( 'a1b2c3d4e5f6', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [ 'wp_loaded' ], [], [ 'init', 'wp_loaded' ] );
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wp_loaded hook', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/**
	 * A hook the rule ALREADY marks significant has its listeners in the
	 * record — telling the reader to mark it again is advice to do nothing,
	 * which `dominant_span` learned and `repetition` has to know too.
	 */
	public function test_repetition_of_an_already_significant_hook_proposes_nothing(): void {
		$rule   = new Rule( 'a1b2c3d4e5f6', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [ 'the_content' ], [], [ 'init', 'wp_loaded' ] );
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'the_content hook' => [ 'count' => 340, 'time' => 30.0, 'entries' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'repetition' );

		$this->assertNotNull( $found );
		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	public function test_repetition_of_a_custom_event_is_not_proposed_as_significant(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'function: byline' => [ 'count' => 340, 'time' => 30.0, 'entries' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		// Without this the assertion below passes on a null finding.
		$this->assertNotNull( $found );
		$this->assertNotSame( 'mark_significant', $found['proposal']['action'] );
	}

	/**
	 * The logger binds ONLY the hooks the governing rule names. Claiming it
	 * hooks `all` tells a model everything is instrumented, which turns every
	 * absence into evidence — the opposite of what the caveat is for.
	 */
	public function test_the_caveat_does_not_claim_the_logger_hooks_all(): void {
		$this->assertStringNotContainsString( '`all`', Findings::caveat() );
		$this->assertStringContainsString( 'rule', Findings::caveat() );
	}

	/** A model handed a profiled/duration ratio with no caveat will invent a cause. */
	public function test_the_caveat_calls_unlogged_time_unmeasured(): void {
		$this->assertStringContainsString( 'Unlogged time is unmeasured, not idle.', Findings::caveat() );
		$this->assertStringNotContainsString( 'Unattributed', Findings::caveat() );
	}

	public function test_the_caveat_names_what_is_not_measured(): void {
		$caveat = Findings::caveat();

		$this->assertStringContainsString( 'SQL', $caveat );
		$this->assertStringNotContainsString( 'SQL, outbound HTTP', $caveat );
	}

	/**
	 * Outbound HTTP is timed only under a rule's `log_http`, as SQL is only
	 * under `log_queries`. A caveat claiming every call is timed tells a model
	 * a request with no HTTP span made none, when its rule simply never asked.
	 */
	public function test_the_caveat_makes_http_timing_the_rules_choice(): void {
		$caveat = Findings::caveat();

		$this->assertStringNotContainsString( 'every outbound HTTP request', $caveat );
		$this->assertStringContainsString( 'outbound HTTP requests under the rule\'s HTTP logging', $caveat );
	}

	/**
	 * A span inside a same-name ancestor is common — a `render_block` nested in
	 * a `render_block`. The repeat is the ancestor's, so the leaf must read as
	 * inside it, never as having run the ancestor's count at its per-call time.
	 */
	public function test_a_leaf_inside_a_same_name_repeat_is_not_credited_with_its_count(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 1000.0;
		$record['flame']       = [
			'name'     => 'request',
			'value'    => 1000.0,
			'children' => [
				[
					'name'     => 'render_block hook',
					'value'    => 900.0,
					'count'    => 5,
					'max'      => 190.0,
					'children' => [
						[ 'name' => 'render_block hook', 'value' => 700.0, 'count' => 1, 'children' => [] ],
					],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'render_block hook › render_block hook', $found['metric']['path'] );
		$this->assertArrayNotHasKey( 'depth', $found['metric'] );
		$this->assertStringContainsString( 'inside render_block hook ×5', $found['title'] );
		$this->assertStringNotContainsString( 'across 5 calls', $found['title'] );
	}

	/** An HTTP span is recorded on every request, so its advice must not presume a rule governs it. */
	#[DataProvider( 'transport_spans' )]
	public function test_a_transport_span_under_no_rule_claims_no_rule( string $span ): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => $span, 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, null ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertStringNotContainsString( 'rule', $found['proposal']['why'] );
	}

	/**
	 * A rule with query logging records every query as a span (decision 22).
	 * A caveat denying SQL outright contradicts the query findings it rides
	 * beside, and a model handed both believes the caveat.
	 */
	public function test_the_caveat_does_not_deny_the_query_spans_a_rule_can_record(): void {
		$caveat = Findings::caveat();

		$this->assertStringNotContainsString( 'does not see SQL', $caveat );
		$this->assertStringContainsString( 'query logging', $caveat );
	}

	public function test_findings_come_back_worst_first(): void {
		$record             = $this->healthy_record();
		$record['folded']   = true;
		$record['flame']    = [
			'name'     => 'request',
			'value'    => 400.0,
			'children' => [ [ 'name' => 'init hook', 'value' => 375.6, 'count' => 900, 'max' => 1.75, 'children' => [] ] ],
		];
		$record['profiles'] = [
			'the_content hook' => [ 'count' => 900, 'time' => 50.0, 'entries' => [] ],
		];

		$kinds = $this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) );

		$this->assertSame( 'dominant_span', $kinds[0], 'the high finding leads' );
		$this->assertContains( 'truncation', $kinds );
		$this->assertContains( 'repetition', $kinds );
	}

	/**
	 * The logger's own query and HTTP spans (decisions 20 and 22), named for
	 * their caller as the flame names every labelled span.
	 *
	 * @return array<string,array{string}>
	 */
	public static function transport_spans(): array {
		return [
			'query' => [ 'sql: WP_Query->get_posts' ],
			'http'  => [ 'http: Jetpack_Client->remote_request' ],
		];
	}

	/** @return array<string,array{string,string,string}> Span, the significant event that names it, the filter it wraps. */
	public static function transport_filters(): array {
		return [
			'query' => [ 'sql: WP_Query->get_posts', 'sql', 'query' ],
			'http'  => [ 'http: Jetpack_Client->remote_request', 'http', 'pre_http_request' ],
		];
	}

	/**
	 * A query or an HTTP call is the logger's own span, not an event the
	 * application logs: calling it a custom event proposes a rule edit that
	 * changes nothing. Marking it significant wraps the filter's listeners.
	 */
	#[DataProvider( 'transport_filters' )]
	public function test_a_dominant_query_or_http_span_proposes_marking_it_significant( string $span, string $event, string $filter ): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => $span, 'value' => 372.0, 'children' => [] ],
		];

		$rule = $this->instrumented_rule()->with( [ 'log_queries' => true, 'log_http' => true ] );

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertSame( $event, $found['proposal']['value'] );
		$this->assertStringContainsString( $filter, $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'custom event', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'custom event', $found['detail'] );
	}

	/**
	 * The profiler's `<slug> plugin` span is one plugin file's load: no rule
	 * edit reaches inside it, and calling it a custom event proposes one.
	 */
	public function test_a_dominant_plugin_load_span_names_the_load_and_proposes_nothing(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'wpseo-premium plugin', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertArrayNotHasKey( 'field', $found['proposal'] );
		$this->assertStringContainsString( 'wpseo-premium plugin', $found['proposal']['why'] );
		$this->assertStringContainsString( 'load', $found['detail'] );
		$this->assertStringNotContainsString( 'custom event', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'custom event', $found['detail'] );
		$this->assertNull(
			$this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'plugin_load' ),
			'one dominant load is the dominant span, reported once'
		);
	}

	/**
	 * A verb's span is a FRAME: the verb's time, with the spans it ran inside.
	 * It is logged whatever the rule, so the finding sends the reader to its
	 * children and proposes no rule edit.
	 */
	public function test_a_dominant_command_span_sends_the_reader_to_its_children(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'Performance_CI urls command',
				'value'    => 372.0,
				'children' => [ [ 'name' => Flame_Tree::URL_FOLD, 'value' => 41.0, 'children' => [] ] ],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Performance_CI urls command', $found['metric']['name'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertArrayNotHasKey( 'field', $found['proposal'] );
		$this->assertSame( '', $found['proposal']['undo'] );
		$this->assertStringContainsString( 'Performance_CI urls command', $found['proposal']['why'] );
		$this->assertStringContainsString( 'children', $found['proposal']['why'] );
		$this->assertStringContainsString( 'verb', $found['detail'] );
		$this->assertStringNotContainsString( 'Performance_CI_Node', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'custom event', $found['detail'] );
	}

	/** The URL read's steps are leaves the platform times; no rule edit reaches inside. */
	public function test_a_dominant_url_read_step_proposes_nothing(): void {
		foreach ( [ Flame_Tree::URL_PAGE_CACHE, Flame_Tree::URL_HEADER_CACHE, Flame_Tree::URL_FOLD ] as $step ) {
			$record = $this->healthy_record();
			$record['flame']['children'] = [
				[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
				[ 'name' => $step, 'value' => 372.0, 'children' => [] ],
			];

			$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

			$this->assertNotNull( $found, $step );
			$this->assertSame( 'none', $found['proposal']['action'], $step );
			$this->assertStringContainsString( 'Performance_CI_Node', $found['proposal']['why'], $step );
			$this->assertStringNotContainsString( 'command', $found['proposal']['why'], $step );
			$this->assertStringNotContainsString( 'custom event', $found['detail'], $step );
		}
	}

	/** The builders' own upkeep spans are told whatever the rule; no edit reaches them. */
	public function test_a_dominant_upkeep_span_proposes_nothing(): void {
		foreach ( [ Flame_Tree::STATS_FOLD, Flame_Tree::STATS_SWEEP, Flame_Tree::REQUESTS_CHECKPOINT ] as $step ) {
			$record = $this->healthy_record();
			$record['flame']['children'] = [
				[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
				[ 'name' => $step, 'value' => 372.0, 'children' => [] ],
			];

			$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

			$this->assertNotNull( $found, $step );
			$this->assertSame( 'none', $found['proposal']['action'], $step );
			$this->assertStringContainsString( 'upkeep', $found['proposal']['why'], $step );
			$this->assertStringNotContainsString( 'custom event', $found['detail'], $step );
		}
	}

	/**
	 * Only a single-token slug is the profiler's: an application event whose
	 * name merely ends in "plugin" is still the application's own.
	 */
	public function test_a_multi_word_custom_event_ending_in_plugin_stays_custom(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'sync remote plugin', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertStringContainsString( 'custom event', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'plugin file', $found['detail'] );
	}

	/**
	 * An El Sol request, shaped as stored: the request's own `process` frame
	 * under the root, holding thirty-five plugin loads and nothing else.
	 *
	 * @param float $load_ms Milliseconds each of the three named plugins took.
	 */
	private function plugin_bootstrap_record( float $load_ms ): array {
		$record                = $this->loaded_record();
		$record['duration_ms'] = 137.3;
		$plugins               = [
			[ 'name' => 'newspack-plugin plugin', 'value' => $load_ms * 2, 'children' => [] ],
			[ 'name' => 'wordpress-seo plugin', 'value' => $load_ms * 1.5, 'children' => [] ],
			[ 'name' => 'jetpack plugin', 'value' => $load_ms, 'children' => [] ],
		];
		for ( $i = 0; $i < 32; $i++ ) {
			$plugins[] = [ 'name' => "minor-{$i} plugin", 'value' => 0.25, 'children' => [] ];
		}
		$record['flame_data'] = [
			'name'     => 'request',
			'value'    => 137.3,
			'children' => [ [ 'name' => 'process', 'value' => 137.3, 'children' => $plugins ] ],
		];
		return $record;
	}

	/**
	 * `process` is the request's own frame, so it holds all of every request:
	 * reporting it as the dominant span says nothing and proposes a no-op.
	 */
	public function test_the_request_frame_is_never_the_dominant_span(): void {
		$findings = Findings::for_request( $this->plugin_bootstrap_record( 6.2 ), $this->instrumented_rule() );

		$this->assertNull( $this->of_kind( $findings, 'dominant_span' ) );
	}

	/**
	 * No one plugin holds 60%, but together their loads are a third of the
	 * request: the finding names the total and the heaviest three.
	 */
	public function test_plugin_loads_holding_a_large_share_are_named_heaviest_first(): void {
		$found = $this->of_kind(
			Findings::for_request( $this->plugin_bootstrap_record( 6.2 ), $this->instrumented_rule() ),
			'plugin_load'
		);

		$this->assertNotNull( $found );
		$this->assertSame( 35, $found['metric']['plugins'] );
		$this->assertEqualsWithDelta( 35.9, $found['metric']['ms'], 0.01 );
		$this->assertSame(
			[ 'newspack-plugin', 'wordpress-seo', 'jetpack' ],
			\array_column( $found['metric']['heaviest'], 'plugin' )
		);
		$this->assertStringContainsString( '35 plugins', $found['title'] );
		$this->assertStringContainsString( 'newspack-plugin 12.4ms', $found['detail'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/**
	 * One load dominating the profiled time is the dominant span's to name,
	 * but when the OTHER loads alone still hold a large share of the request,
	 * that cost belongs to no dominant span and is still reported.
	 */
	public function test_the_rest_of_the_loads_are_reported_beside_a_dominant_one(): void {
		$record                = $this->loaded_record();
		$record['duration_ms'] = 1200.0;
		$plugins               = [ [ 'name' => 'giant-7734 plugin', 'value' => 760.0, 'children' => [] ] ];
		for ( $i = 0; $i < 14; $i++ ) {
			$plugins[] = [ 'name' => "rest-{$i} plugin", 'value' => 25.0, 'children' => [] ];
		}
		$record['flame_data'] = [
			'name'     => 'request',
			'value'    => 1200.0,
			'children' => [ [ 'name' => 'process', 'value' => 1200.0, 'children' => $plugins ] ],
		];

		$findings = Findings::for_request( $record, $this->instrumented_rule() );

		$this->assertSame( 'giant-7734 plugin', $this->of_kind( $findings, 'dominant_span' )['metric']['name'] );
		$found = $this->of_kind( $findings, 'plugin_load' );
		$this->assertNotNull( $found );
		// The dominant load is reported once, by the dominant span.
		$this->assertSame( 14, $found['metric']['plugins'] );
		$this->assertEqualsWithDelta( 350.0, $found['metric']['ms'], 0.01 );
		$this->assertNotContains( 'giant-7734', \array_column( $found['metric']['heaviest'], 'plugin' ) );
	}

	public function test_plugin_loads_holding_a_small_share_are_not_a_finding(): void {
		$found = $this->of_kind(
			Findings::for_request( $this->plugin_bootstrap_record( 0.9 ), $this->instrumented_rule() ),
			'plugin_load'
		);

		$this->assertNull( $found );
	}

	/** The query span is the logger's own: the proposal is `sql` as a significant event, never a custom one. */
	public function test_repetition_of_the_query_span_proposes_it_as_significant_not_custom(): void {
		$record             = $this->healthy_record();
		$record['profiles'] = [
			'sql' => [ 'count' => 586, 'time' => 53018.2, 'entries' => [] ],
		];
		$rule = $this->instrumented_rule()->with( [ 'log_queries' => true ] );

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'repetition' );

		$this->assertNotNull( $found );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertSame( 'sql', $found['proposal']['value'] );
	}

	/**
	 * A BDN post-edit load, folded: the query holds 80% of the request, but it
	 * holds it because the editor's REST preload rendered the content ten
	 * times. Naming the leaf alone points at the query; the repeat is the cost.
	 */
	public function test_a_dominant_span_names_the_repeated_parent_that_multiplies_it(): void {
		$revisions             = 'the_content hook: WP_REST_Revisions_Controller->prepare_item_for_response';
		$record                = $this->healthy_record();
		$record['duration_ms'] = 64832.6;
		$record['flame']       = [
			'name'     => 'request',
			'value'    => 64832.6,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 64832.6,
					'count'    => 1,
					'children' => [
						[
							'name'     => $revisions,
							'value'    => 59384.1,
							'count'    => 10,
							'max'      => 6120.4,
							'children' => [
								[
									'name'     => 'do_blocks @9',
									'value'    => 56104.6,
									'count'    => 10,
									'max'      => 5833.0,
									'children' => [
										[ 'name' => 'sql: WP_Query->get_posts', 'value' => 52105.4, 'count' => 299, 'max' => 1850.0, 'children' => [] ],
									],
								],
							],
						],
						[ 'name' => 'init hook', 'value' => 104.4, 'count' => 1, 'children' => [] ],
					],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'sql: WP_Query->get_posts', $found['metric']['name'] );
		$this->assertSame( $revisions, $found['metric']['repeat']['name'] );
		$this->assertSame( 10, $found['metric']['repeat']['count'] );
		$this->assertStringContainsString( $revisions, $found['title'] );
		$this->assertStringContainsString( '×10', $found['title'] );
	}

	/**
	 * A same-name group is a repeat only when no one member holds the
	 * request: hub 2yoz's eight siblings, one holding 7784 of 7942ms, are one
	 * slow call beside seven quick ones, not eight calls at 992.7ms each.
	 */
	public function test_a_group_one_member_dominates_is_no_repeat(): void {
		$members = [ [ 'name' => 'fieldvalues', 'value' => 7784.0, 'children' => [] ] ];
		for ( $i = 0; $i < 7; $i++ ) {
			$members[] = [ 'name' => 'fieldvalues', 'value' => 22.6, 'children' => [] ];
		}
		$record                = $this->loaded_record();
		$record['duration_ms'] = 8968.4;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 8968.4,
			'children' => [ [ 'name' => 'process', 'value' => 8968.4, 'children' => $members ] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'fieldvalues', $found['metric']['name'] );
		$this->assertArrayNotHasKey( 'repeat', $found['metric'] );
		$this->assertStringNotContainsString( 'across 8 calls', $found['title'] );
	}

	/**
	 * Three loop siblings of 0.8, 12.5 and 3751.8ms: the slowest holds 99.6%
	 * of the request beside two quick calls, so nothing repeated.
	 */
	public function test_one_slow_call_beside_two_quick_ones_is_no_repeat(): void {
		$loops = [];
		foreach ( [ 0.8, 12.5, 3751.8 ] as $ms ) {
			$loops[] = [ 'name' => 'loop', 'value' => $ms, 'children' => [] ];
		}
		$record                = $this->loaded_record();
		$record['duration_ms'] = 4180.0;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 4180.0,
			'children' => [ [ 'name' => 'process', 'value' => 4180.0, 'children' => $loops ] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'loop', $found['metric']['name'] );
		$this->assertArrayNotHasKey( 'repeat', $found['metric'] );
		$this->assertStringNotContainsString( 'ran 3 times', $found['detail'] );
		$this->assertStringNotContainsString( 'across 3 calls', $found['title'] );
	}

	/** Two uploads of 1015.3 and 1017.5ms are alike, so they are one repeat. */
	public function test_two_alike_calls_are_a_repeat(): void {
		$uploads = [];
		foreach ( [ 1015.3, 1017.5 ] as $ms ) {
			$uploads[] = [ 'name' => 'fileupload', 'value' => $ms, 'children' => [] ];
		}
		$record                = $this->loaded_record();
		$record['duration_ms'] = 2500.0;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 2500.0,
			'children' => [ [ 'name' => 'process', 'value' => 2500.0, 'children' => $uploads ] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'fileupload', $found['metric']['name'] );
		$this->assertSame( 2, $found['metric']['repeat']['count'] ?? null );
		$this->assertStringContainsString( 'ran 2 times', $found['detail'] );
	}

	/**
	 * Two calls are alike up to a 5x ratio: most of the time in one call is a
	 * slow call, not per-item work. 60 and 11ms (5.45x) are no repeat.
	 */
	public function test_two_calls_past_the_similar_ratio_are_no_repetition(): void {
		$this->assertNull( $this->of_kind( Findings::for_request( $this->two_call_record( 60.0, 11.0 ), $this->instrumented_rule() ), 'repetition' ) );
	}

	/** 54 and 11ms (4.9x) are still alike, so the pair repeats. */
	public function test_two_calls_within_the_similar_ratio_repeat(): void {
		$found = $this->of_kind( Findings::for_request( $this->two_call_record( 54.0, 11.0 ), $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'sql: Pair_Report->rows', $found['metric']['name'] ?? null );
		$this->assertSame( 2, $found['metric']['count'] ?? null );
	}

	/** A profile counting calls beside one flame frame has no pair to compare, so it repeats. */
	public function test_a_profile_counting_calls_beside_one_frame_repeats(): void {
		$record                = $this->repeat_record( $this->frames( 'sql: Lone_Report->rows', [ 90.0 ] ) );
		$record['duration_ms'] = 250.0;
		$record['flame_data']['value']                = 250.0;
		$record['flame_data']['children'][0]['value'] = 250.0;
		$record['flame_data']['children'][0]['children'][0]['value'] = 90.0;
		$record['profiles']    = [ 'sql' => [ 'count' => 7, 'time' => 90.0, 'entries' => [ 'Lone_Report->rows' => [ 90.0, 7 ] ] ] ];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 7, $found['metric']['count'] ?? null );
	}

	/**
	 * A request holding two same-name calls of the given durations.
	 *
	 * @param float $first  One call's milliseconds.
	 * @param float $second The other's.
	 * @return array<string,mixed>
	 */
	private function two_call_record( float $first, float $second ): array {
		$record                = $this->repeat_record( $this->frames( 'sql: Pair_Report->rows', [ $first, $second ] ) );
		$record['duration_ms'] = 250.0;
		$record['flame_data']['value']                = 250.0;
		$record['flame_data']['children'][0]['value'] = 250.0;
		$record['flame_data']['children'][0]['children'][0]['value'] = $first + $second;
		$record['profiles']    = [ 'sql' => [ 'count' => 2, 'time' => $first + $second, 'entries' => [ 'Pair_Report->rows' => [ $first + $second, 2 ] ] ] ];
		return $record;
	}

	/**
	 * The same load UNFOLDED, which is how nearly every record arrives: a
	 * stored tree keeps each render as its own sibling, so no single one holds
	 * 60% and the detector fell back to the whole request. Siblings sharing a
	 * name are one repeat at every depth, so this names the same leaf and the
	 * same multiplier the folded record does.
	 */
	public function test_same_name_siblings_in_a_stored_tree_dominate_as_one_repeat(): void {
		$revisions = 'the_content hook: WP_REST_Revisions_Controller->prepare_item_for_response';
		$renders   = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$renders[] = [
				'name'     => $revisions,
				'value'    => 5938.4,
				'children' => [ [ 'name' => 'sql: WP_Query->get_posts', 'value' => 5210.5, 'children' => [] ] ],
			];
		}
		$record                = $this->loaded_record();
		$record['duration_ms'] = 64832.6;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 64832.6,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 64832.6,
					'children' => [ ...$renders, [ 'name' => 'init hook', 'value' => 5448.6, 'children' => [] ] ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 'sql: WP_Query->get_posts', $found['metric']['name'] );
		$this->assertEqualsWithDelta( 52105.0, $found['metric']['ms'], 0.5 );
		$this->assertSame( $revisions, $found['metric']['repeat']['name'] );
		$this->assertSame( 10, $found['metric']['repeat']['count'] );
		$this->assertEqualsWithDelta( 59384.0, $found['metric']['repeat']['ms'], 0.5 );
	}

	/**
	 * A post save as stored: the link builder holds 82% of the request,
	 * `do_blocks` two thirds of the builder, and two of the queries inside
	 * `do_blocks` most of it, though neither alone holds half.
	 *
	 * @param list<array<string,mixed>> $inside What runs inside `do_blocks`.
	 */
	private function descent_record( array $inside ): array {
		$record                = $this->loaded_record();
		$record['duration_ms'] = 3125.0;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 3125.0,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 3125.0,
					'children' => [
						[
							'name'     => 'the_content hook: Indexable_Link_Builder->build',
							'value'    => 2562.5,
							'children' => [
								[ 'name' => 'do_blocks @9', 'value' => 1718.0, 'children' => $inside ],
								[ 'name' => 'Image_CDN::filter_the_content @999999', 'value' => 836.0, 'children' => [] ],
							],
						],
					],
				],
			],
		];
		return $record;
	}

	/** Leaf frames of one name, one per call. */
	private function frames( string $name, array $values ): array {
		return \array_map(
			static fn ( float $ms ): array => [ 'name' => $name, 'value' => $ms, 'children' => [] ],
			$values
		);
	}

	/** The time is followed down to where no one call holds half its parent, and the calls there are named. */
	public function test_a_dominant_span_descends_to_the_calls_that_explain_it(): void {
		$queries = $this->frames( 'sql: WP_Query->get_posts', [ 640.0, 590.0, 95.0, 41.5, 22.0 ] );

		$found = $this->of_kind( Findings::for_request( $this->descent_record( $queries ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'the_content hook: Indexable_Link_Builder->build', $found['metric']['name'] );
		$this->assertSame( [ [ 'name' => 'do_blocks @9', 'ms' => 1718.0, 'share' => 1718.0 / 3125.0 ] ], $found['metric']['chain'] );
		$this->assertSame(
			[ [ 'name' => 'sql: WP_Query->get_posts', 'ms' => 1388.5, 'count' => 5, 'share' => 1388.5 / 3125.0, 'max' => 640.0 ] ],
			$found['metric']['explained']
		);
		$this->assertArrayNotHasKey( 'child', $found['metric'] );
		$this->assertStringContainsString( 'Inside it, do_blocks @9 holds 55% of the request.', $found['detail'] );
		$this->assertStringContainsString( 'sql: WP_Query->get_posts ×5 1.4s (slowest 640.0ms) holds 81% of it.', $found['detail'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
	}

	/** Each level holding half its parent in one call is a step of the chain. */
	public function test_a_dominant_span_descends_through_every_level_one_call_holds(): void {
		$render = [
			'name'     => 'render_block hook',
			'value'    => 1300.0,
			'children' => [
				...$this->frames( 'sql: Term_Query->get_terms', [ 410.0 ] ),
				...$this->frames( 'sql: WP_Query->get_posts', [ 385.0 ] ),
				...$this->frames( 'wptexturize @10', [ 50.0 ] ),
			],
		];

		$found = $this->of_kind( Findings::for_request( $this->descent_record( [ $render ] ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( [ 'do_blocks @9', 'render_block hook' ], \array_column( $found['metric']['chain'], 'name' ) );
		$this->assertSame( [ 'sql: Term_Query->get_terms', 'sql: WP_Query->get_posts' ], \array_column( $found['metric']['explained'], 'name' ) );
		$this->assertStringContainsString( 'Inside it, do_blocks @9 holds 55% of the request; inside that, render_block hook holds 42%.', $found['detail'] );
		$this->assertStringNotContainsString( 'Most of it', $found['detail'] );
	}

	/** Where the descent stops on a body nothing inside explains, the proposal is to see inside THAT frame. */
	public function test_a_descent_stopping_on_an_unexplained_body_proposes_seeing_inside_it(): void {
		$render = [ 'name' => 'render_block hook', 'value' => 1300.0, 'children' => $this->frames( 'wptexturize @10', [ 120.0 ] ) ];

		$found = $this->of_kind( Findings::for_request( $this->descent_record( [ $render ] ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertArrayNotHasKey( 'explained', $found['metric'] );
		$this->assertSame( 'mark_significant', $found['proposal']['action'] );
		$this->assertSame( 'render_block', $found['proposal']['value'] );
	}

	/**
	 * A 4s request whose `do_blocks` holds 52% of it, short of dominant, so
	 * no dominant span names the frames inside it.
	 *
	 * @param list<array<string,mixed>> $inside What runs inside `do_blocks`.
	 */
	private function repeat_record( array $inside ): array {
		$record                = $this->loaded_record();
		$record['duration_ms'] = 4000.0;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 4000.0,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 4000.0,
					'children' => [ [ 'name' => 'do_blocks @9', 'value' => 2100.0, 'children' => $inside ] ],
				],
			],
		];
		return $record;
	}

	/**
	 * The same post save's queries: `sql` ran 172 times across three callers,
	 * and two of `WP_Query->get_posts`' 31 calls hold 84% of its time. That is
	 * two slow queries beside quick ones, and no caller repeats.
	 */
	public function test_a_few_slow_calls_of_a_caller_are_not_repetition(): void {
		$quick              = \array_fill( 0, 29, 310.0 / 29 );
		$record             = $this->repeat_record( $this->frames( 'sql: WP_Query->get_posts', [ 900.0, 780.0, ...$quick ] ) );
		$record['profiles'] = [
			'sql' => [
				'count'   => 172,
				'time'    => 2036.5,
				'entries' => [
					'WP_Query->get_posts'       => [ 1990.0, 31 ],
					'Yoast\\WP\\Lib\\ORM::execute' => [ 27.5, 118 ],
					'WP_Term_Query->get_terms'  => [ 19.0, 23 ],
				],
			],
		];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/** Many calls of one caller at a similar cost are the repeat, named by that caller. */
	public function test_many_similar_calls_of_one_caller_repeat_under_its_name(): void {
		$record             = $this->repeat_record( $this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 96, 7.5 ) ) );
		$record['profiles'] = [
			'sql' => [
				'count'   => 100,
				'time'    => 730.0,
				'entries' => [
					'Term_Cache->prime' => [ 720.0, 96 ],
					'update_option'     => [ 10.0, 4 ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'sql: Term_Cache->prime', $found['metric']['name'] ?? null );
		$this->assertSame( 96, $found['metric']['count'] );
		$this->assertStringStartsWith( 'sql: Term_Cache->prime fired 96 times', $found['title'] );
	}

	/**
	 * A row logged late but stamped inside a window splits it: the gaps are
	 * either side of it, by timestamp, not across it.
	 */
	public function test_a_late_logged_row_stamped_inside_a_window_splits_it(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.010, 'k' => 'request', 'm' => 'GET https://example.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 2000.020, 'k' => 'init hook (start)', 'm' => '' ],
			[ 'n' => 4, 'ts' => 2000.030, 'k' => 'init hook (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 5, 'ts' => 2000.900, 'k' => 'wp_loaded hook (start)', 'm' => '' ],
			[ 'n' => 6, 'ts' => 2000.905, 'k' => 'wp_loaded hook (complete)', 'm' => '', 'duration_ms' => 5.0 ],
			[ 'n' => 7, 'ts' => 2000.480, 'k' => 'deferred', 'm' => '' ],
			[ 'n' => 8, 'ts' => 2000.910, 'k' => 'process (complete)', 'm' => '' ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertEqualsWithDelta( 450.0, $found['metric']['gap_ms'], 1e-6 );
		$this->assertSame( 'init hook (complete)', $found['metric']['after'] );
		$this->assertSame( 'deferred', $found['metric']['before'] );
		$this->assertSame( [ 3, 6 ], [ $found['metric']['from_i'], $found['metric']['to_i'] ] );
	}

	/**
	 * The profiler's rows are logged after the opening rows and stamped
	 * before them, so the window ahead of the first plugin load is found
	 * among them, not across the request line.
	 */
	public function test_a_window_among_back_dated_rows_is_measured_by_their_stamps(): void {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.600, 'k' => 'request', 'm' => 'GET https://example.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 2000.601, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 4, 'ts' => 2000.400, 'k' => 'jetpack plugin (start)', 'm' => '' ],
			[ 'n' => 5, 'ts' => 2000.420, 'k' => 'jetpack plugin (complete)', 'm' => '', 'duration_ms' => 20.0 ],
			[ 'n' => 6, 'ts' => 2000.640, 'k' => 'init hook (start)', 'm' => '' ],
			[ 'n' => 7, 'ts' => 2000.650, 'k' => 'init hook (complete)', 'm' => '', 'duration_ms' => 10.0 ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertEqualsWithDelta( 400.0, $found['metric']['gap_ms'], 1e-6 );
		$this->assertSame( 'process (start)', $found['metric']['after'] );
		$this->assertSame( 'jetpack plugin (start)', $found['metric']['before'] );
	}

	/**
	 * The window from the logger's opening rows to the first span it bound
	 * is the rest of `plugins_loaded`, which the logger boots inside.
	 *
	 * @param float $first_hook When the first bound span opens, seconds past 2000.
	 * @param float $later_hook When a later one opens.
	 */
	private function boot_record( float $first_hook, float $later_hook ): array {
		$record            = $this->healthy_record();
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.090, 'k' => 'request', 'm' => 'GET https://example.test/calendar/today' ],
			[ 'n' => 3, 'ts' => 2000.0901, 'k' => 'resources', 'm' => 'utime => 0.040000, stime => 0.010000' ],
			[ 'n' => 4, 'ts' => 2000.030, 'k' => 'S3-Uploads plugin (start)', 'm' => '' ],
			[ 'n' => 5, 'ts' => 2000.040, 'k' => 'S3-Uploads plugin (complete)', 'm' => '', 'duration_ms' => 10.0 ],
			[ 'n' => 6, 'ts' => 2000 + $first_hook, 'k' => 'setup_theme hook (start)', 'm' => '' ],
			[ 'n' => 7, 'ts' => 2000 + $first_hook + 0.001, 'k' => 'setup_theme hook (complete)', 'm' => '', 'duration_ms' => 1.0 ],
			[ 'n' => 8, 'ts' => 2000 + $later_hook, 'k' => 'wp_loaded hook (start)', 'm' => '' ],
			[ 'n' => 9, 'ts' => 2000 + $later_hook + 0.001, 'k' => 'wp_loaded hook (complete)', 'm' => '', 'duration_ms' => 1.0 ],
		];
		return $record;
	}

	public function test_the_loggers_boot_window_says_no_rule_edit_can_time_it(): void {
		$found = $this->of_kind( Findings::for_request( $this->boot_record( 0.520, 0.530 ), $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'resources', $found['metric']['after'] );
		$this->assertSame( 'setup_theme hook (start)', $found['metric']['before'] );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'plugins_loaded', $found['proposal']['why'] );
		$this->assertStringContainsString( 'priority 11', $found['proposal']['why'] );
	}

	public function test_a_later_request_level_gap_is_no_boot_window(): void {
		$found = $this->of_kind( Findings::for_request( $this->boot_record( 0.120, 0.700 ), $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'wp_loaded hook (start)', $found['metric']['before'] );
		$this->assertStringNotContainsString( 'plugins_loaded', $found['proposal']['why'] );
	}

	/** Uniform calls inside the stop frame are one name, with its whole count and time. */
	public function test_explaining_calls_merge_by_name(): void {
		$inside = [ ...$this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 450, 3.0 ) ), ...$this->frames( 'wptexturize @10', [ 80.0 ] ) ];

		$found = $this->of_kind( Findings::for_request( $this->descent_record( $inside ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame(
			[ [ 'name' => 'sql: Term_Cache->prime', 'ms' => 1350.0, 'count' => 450, 'share' => 1350.0 / 3125.0, 'max' => 3.0 ] ],
			$found['metric']['explained']
		);
		$this->assertStringContainsString( 'sql: Term_Cache->prime ×450 1.4s (slowest 3.0ms) holds 79% of it.', $found['detail'] );
	}

	/** Only the names needed to explain the frame are named, and no more than a few. */
	public function test_explaining_names_stop_at_the_cap(): void {
		$inside = [];
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $at => $suffix ) {
			$inside[] = [ 'name' => "sql: Caller_{$suffix}->load", 'value' => 205.0 - $at, 'children' => [] ];
		}

		$found = $this->of_kind( Findings::for_request( $this->descent_record( $inside ), $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( [ 'sql: Caller_a->load', 'sql: Caller_b->load', 'sql: Caller_c->load' ], \array_column( $found['metric']['explained'], 'name' ) );
		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'hold 36% of do_blocks @9', $found['proposal']['why'] );
		$this->assertStringNotContainsString( 'most', $found['proposal']['why'] );
	}

	/** A descent that ends on a query frame names the statement that frame spent most on. */
	public function test_a_descent_ending_on_a_query_names_its_statement(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 2000.0;
		$record['flame']       = [
			'name'     => 'request',
			'value'    => 2000.0,
			'children' => [
				[
					'name'     => 'the_content hook',
					'value'    => 1700.0,
					'children' => [
						[
							'name'     => 'sql: WP_Query->get_posts',
							'value'    => 1100.0,
							'count'    => 3,
							'max'      => 950.0,
							'children' => [],
							'shapes'   => [
								'SELECT * FROM wp_posts WHERE ID = ?' => [ 2, 1040.0 ],
								'SELECT 1'                           => [ 1, 60.0 ],
							],
						],
					],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'the_content hook', $found['metric']['name'] );
		$this->assertArrayNotHasKey( 'shape', $found['metric'], 'the dominant span ran no statement' );
		$this->assertSame( 'sql: WP_Query->get_posts', $found['metric']['chain'][0]['name'] );
		$this->assertSame( 'SELECT * FROM wp_posts WHERE ID = ?', $found['metric']['chain'][0]['shape'] );
		$this->assertSame( 2, $found['metric']['chain'][0]['shape_calls'] );
	}

	/** A folded frame carries its slowest call; one without it is a broken record. */
	public function test_a_folded_frame_without_its_slowest_call_is_refused(): void {
		$record                      = $this->healthy_record();
		$record['flame']['children'] = [ [ 'name' => 'render_block hook', 'value' => 340.0, 'count' => 4, 'children' => [] ] ];

		$this->expectException( \UnexpectedValueException::class );
		Findings::for_request( $record, $this->instrumented_rule() );
	}

	/**
	 * Five seconds in one call beside 500 calls of 4ms are not calls alike:
	 * the slow one is the dominant span's to name, so no repeat is claimed.
	 */
	public function test_calls_unlike_each_other_are_no_repetition(): void {
		$record                = $this->repeat_record( $this->frames( 'sql: Slow_Report->rows', [ 5000.0, ...\array_fill( 0, 500, 4.0 ) ] ) );
		$record['duration_ms'] = 12000.0;
		$record['flame_data']['value']                = 12000.0;
		$record['flame_data']['children'][0]['value'] = 12000.0;
		$record['flame_data']['children'][0]['children'][0]['value'] = 7000.0;
		$record['profiles']    = [ 'sql' => [ 'count' => 501, 'time' => 7000.0, 'entries' => [ 'Slow_Report->rows' => [ 7000.0, 501 ] ] ] ];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/**
	 * Calls are compared by frame value, children included, so one call
	 * holding a three-second query is unlike ninety-nine 6ms ones.
	 */
	public function test_calls_are_compared_by_frame_value_children_included(): void {
		$cards = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$cards[] = [
				'name'     => 'function: card',
				'value'    => 0 === $i ? 3006.0 : 6.0,
				'children' => 0 === $i ? [ [ 'name' => 'sql: Feed->fetch', 'value' => 3000.0, 'children' => [] ] ] : [],
			];
		}
		$record                = $this->repeat_record( $cards );
		$record['duration_ms'] = 10000.0;
		$record['flame_data']['value']                = 10000.0;
		$record['flame_data']['children'][0]['value'] = 10000.0;
		$record['flame_data']['children'][0]['children'][0]['value'] = 3700.0;
		$record['profiles']    = [ 'function' => [ 'count' => 100, 'time' => 600.0, 'entries' => [ 'card' => [ 600.0, 100 ] ] ] ];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/** Lifecycle hooks the rule omits run inside a window ending at a later one: adding them splits it. */
	public function test_a_boot_window_past_omitted_bracket_hooks_proposes_them(): void {
		$record                      = $this->boot_record( 0.520, 0.530 );
		$record['entries'][5]['k']   = 'template_redirect hook (start)';
		$record['entries'][6]['k']   = 'template_redirect hook (complete)';
		$rule                        = new Rule( 'a1b2c3d4e5f6', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [], [], [ 'template_redirect', 'wp_loaded' ] );

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'entry_gap' );

		$this->assertSame( 'add_hooks', $found['proposal']['action'] );
		$this->assertSame( [ 'setup_theme', 'init' ], $found['proposal']['hooks'] );
		$this->assertSame(
			'Before template_redirect the rule omits the lifecycle hooks setup_theme, init; whichever of them fire inside this window will split it.',
			$found['proposal']['why']
		);
	}

	/** A worker's record holds no web request's boot, so the window says nothing about it. */
	public function test_a_worker_records_first_window_is_no_boot_window(): void {
		$record              = $this->boot_record( 0.520, 0.530 );
		$record['is_worker'] = true;

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'setup_theme hook (start)', $found['metric']['before'] );
		$this->assertStringNotContainsString( 'plugins_loaded', $found['proposal']['why'] );
	}

	/** A window ending at a command span is the verb's, not the lifecycle's. */
	public function test_a_window_ending_at_a_command_is_no_boot_window(): void {
		$record                    = $this->boot_record( 0.520, 0.530 );
		$record['entries'][5]['k'] = 'Performance_CI_Node urls command (start)';
		$record['entries'][6]['k'] = 'Performance_CI_Node urls command (complete)';

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'entry_gap' );

		$this->assertSame( 'Performance_CI_Node urls command (start)', $found['metric']['before'] );
		$this->assertStringNotContainsString( 'plugins_loaded', $found['proposal']['why'] );
	}

	/** A window after a logged bracket hook names the hooks the rule omits between the two. */
	public function test_a_window_between_bracket_hooks_names_both_ends(): void {
		$record                    = $this->boot_record( 0.120, 0.700 );
		$record['entries'][7]['k'] = 'shutdown hook (start)';
		$record['entries'][8]['k'] = 'shutdown hook (complete)';
		$rule                      = new Rule( 'a1b2c3d4e5f6', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [], [], [ 'setup_theme', 'shutdown' ] );

		$found = $this->of_kind( Findings::for_request( $record, $rule ), 'entry_gap' );

		$this->assertSame( [ 'init', 'wp_loaded', 'template_redirect', 'wp_head' ], $found['proposal']['hooks'] );
		$this->assertStringStartsWith( 'Between setup_theme and shutdown the rule omits', $found['proposal']['why'] );
	}

	/**
	 * A name nested in itself — blocks inside a block — counts its outermost
	 * frame alone, so the outer frame holding its inner ones is not read as
	 * a slow call beside them.
	 */
	public function test_a_name_nested_in_itself_still_repeats(): void {
		$outer              = [
			'name'     => 'render_block hook',
			'value'    => 527.0,
			'children' => $this->frames( 'render_block hook', \array_fill( 0, 50, 10.4 ) ),
		];
		$record             = $this->repeat_record( [ $outer ] );
		$record['profiles'] = [ 'render_block hook' => [ 'count' => 51, 'time' => 527.0, 'entries' => [] ] ];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'render_block hook', $found['metric']['name'] ?? null );
		$this->assertSame( 51, $found['metric']['count'] );
	}

	/**
	 * A name the dominant span explains is not on its chain, and the finding
	 * counts it inside one frame only: its request-wide repeat still reports.
	 */
	public function test_an_explained_name_still_repeats(): void {
		$record             = $this->descent_record( $this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 450, 3.0 ) ) );
		$record['profiles'] = [ 'sql' => [ 'count' => 450, 'time' => 1350.0, 'entries' => [ 'Term_Cache->prime' => [ 1350.0, 450 ] ] ] ];

		$findings = Findings::for_request( $record, $this->instrumented_rule() );

		$this->assertSame( 'sql: Term_Cache->prime', $this->of_kind( $findings, 'dominant_span' )['metric']['explained'][0]['name'] );
		$repetition = $this->of_kind( $findings, 'repetition' );
		$this->assertSame( 'sql: Term_Cache->prime', $repetition['metric']['name'] ?? null );
		$this->assertSame( 'none', $repetition['proposal']['action'], 'one name, one piece of advice' );
		$this->assertStringContainsString( 'The dominant span names it inside do_blocks @9; read that finding before changing the rule.', $repetition['detail'] );
	}

	/** A name on the dominant span's chain is that finding's to tell. */
	public function test_a_name_on_the_chain_is_left_to_the_dominant_span(): void {
		$record             = $this->descent_record( $this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 450, 3.0 ) ) );
		$record['profiles'] = [ 'do_blocks @9' => [ 'count' => 60, 'time' => 368.0, 'entries' => [] ] ];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/**
	 * A group block wrapping forty blocks, beside nine more: the wrapper's
	 * own time is what is left once the blocks inside it come off, so the
	 * fifty calls are alike and repeat.
	 */
	public function test_a_wrapper_of_its_own_name_counts_its_own_time(): void {
		$wrapper            = [
			'name'     => 'render_block hook',
			'value'    => 450.0,
			'children' => $this->frames( 'render_block hook', \array_fill( 0, 40, 10.0 ) ),
		];
		$record             = $this->repeat_record( [ $wrapper, ...$this->frames( 'render_block hook', \array_fill( 0, 9, 10.0 ) ) ] );
		$record['profiles'] = [ 'render_block hook' => [ 'count' => 50, 'time' => 540.0, 'entries' => [] ] ];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'render_block hook', $found['metric']['name'] ?? null );
		$this->assertSame( 50, $found['metric']['count'] );
	}

	/**
	 * One 1000ms leaf beside nine wrappers of ninety-nine frames: the leaf is
	 * the slowest call by its own time, which no estimate from the merged
	 * group may shrink, so the calls are unlike.
	 */
	public function test_a_slow_leaf_beside_wrappers_is_no_repetition(): void {
		$blocks = [ [ 'name' => 'render_block hook', 'value' => 1000.0, 'children' => [] ] ];
		for ( $i = 0; $i < 9; $i++ ) {
			$blocks[] = [ 'name' => 'render_block hook', 'value' => 1000.0, 'children' => $this->frames( 'render_block hook', \array_fill( 0, 10, 99.0 ) ) ];
		}
		$record                = $this->loaded_record();
		$record['duration_ms'] = 40000.0;
		$record['flame_data']  = [
			'name'     => 'request',
			'value'    => 40000.0,
			'children' => [
				[
					'name'     => 'process',
					'value'    => 40000.0,
					'children' => [ [ 'name' => 'do_blocks @9', 'value' => 21000.0, 'children' => $blocks ] ],
				],
			],
		];
		$record['profiles']    = [ 'render_block hook' => [ 'count' => 100, 'time' => 2200.0, 'entries' => [] ] ];

		$this->assertNull( $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' ) );
	}

	/** A repeat the reader can act on takes the slot ahead of one the dominant span explains. */
	public function test_an_actionable_repeat_outranks_an_explained_one(): void {
		$record             = $this->descent_record( $this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 450, 3.0 ) ) );
		$record['profiles'] = [
			'sql' => [
				'count'   => 610,
				'time'    => 1750.0,
				'entries' => [
					'Term_Cache->prime' => [ 1350.0, 450 ],
					'Options->get'      => [ 400.0, 160 ],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'sql: Options->get', $found['metric']['name'] ?? null );
		$this->assertSame( 'log_transport', $found['proposal']['action'] );
	}

	/** Most of a name's calls outside the explained frame are this finding's to act on. */
	public function test_an_explained_name_mostly_called_elsewhere_keeps_its_proposal(): void {
		$record = $this->descent_record( $this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 450, 3.0 ) ) );
		$record['flame_data']['children'][0]['children'][] = [
			'name'     => 'wp_footer hook',
			'value'    => 1000.0,
			'children' => $this->frames( 'sql: Term_Cache->prime', \array_fill( 0, 2000, 0.5 ) ),
		];
		$record['profiles'] = [ 'sql' => [ 'count' => 2450, 'time' => 2350.0, 'entries' => [ 'Term_Cache->prime' => [ 2350.0, 2450 ] ] ] ];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'repetition' );

		$this->assertSame( 'sql: Term_Cache->prime', $found['metric']['name'] ?? null );
		$this->assertSame( 'log_transport', $found['proposal']['action'] );
		$this->assertStringNotContainsString( 'dominant span names it', $found['detail'] );
	}
}
