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
			'url'         => '/calendar/today',
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

		$this->assertContains( 'dominant_span', $kinds );
		$this->assertNotContains(
			'unattributed',
			$kinds,
			'reading the wrong key made every ordinary request look wholly unmeasured'
		);
		$this->assertNotContains( 'insufficient_instrumentation', $kinds );
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
						'children' => [],
					],
					[
						'name'     => 'query_sql',
						'value'    => 182.0,
						'count'    => 120,
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
		$this->assertStringContainsString( '4%', $found['detail'] );
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

	/** The live case: 175.6ms profiled against a 420-second request. */
	public function test_unattributed_time_is_subtraction(): void {
		$record                = $this->healthy_record();
		$record['duration_ms'] = 420000.0;
		$record['flame']       = [
			'name'     => 'request',
			'value'    => 175.6,
			'children' => [ [ 'name' => 'init', 'value' => 175.6, 'children' => [] ] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'unattributed' );

		$this->assertNotNull( $found );
		$this->assertEqualsWithDelta( 419824.4, $found['metric']['missing_ms'], 0.1 );
		$this->assertEqualsWithDelta( 175.6, $found['metric']['profiled_ms'], 0.1 );
		$this->assertSame( 'subtraction', $found['measured'] );
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

	public function test_time_inside_any_open_span_is_not_a_gap(): void {
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
		$record['duration_ms']       = 420000.0;
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[
				'name'     => 'wp_loaded hook',
				'value'    => 372.0,
				'children' => [ [ 'name' => 'render_block hook', 'value' => 366.0, 'children' => [] ] ],
			],
		];

		$findings = Findings::for_request( $record, null );

		$this->assertNotEmpty( $this->of_kind( $findings, 'unattributed' ) );
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
				[ 'url' => '/calendar/today', 'count' => 4210, 'avg_ms' => 812.0, 'max_ms' => 2600.0, 'max_peak_mb' => 96.0 ],
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
		$this->assertStringContainsString( 'listeners', $found['detail'] );
	}

	/**
	 * A rule binds a custom event by its bare name too, so a labelled one
	 * proposes the base — `render`, not `render: Event.html`.
	 */
	public function test_a_labelled_custom_event_proposes_its_base_name(): void {
		$record = $this->healthy_record();
		$record['flame']['children'] = [
			[ 'name' => 'init hook', 'value' => 12.0, 'children' => [] ],
			[ 'name' => 'render: Event.html', 'value' => 372.0, 'children' => [] ],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertSame( 'add_custom_events', $found['proposal']['action'] );
		$this->assertSame( 'render', $found['proposal']['value'] );
	}

	/** A significant `sql` under query logging wraps the `query` filter's listeners, inside the span. */
	public function test_a_transport_span_the_rule_marks_significant_has_its_listeners_logged(): void {
		$rule = $this->instrumented_rule()->with( [ 'significant_events' => [ 'sql' ], 'log_queries' => true ] );

		$found = $this->of_kind( Findings::for_request( $this->query_record(), $rule ), 'dominant_span' );

		$this->assertSame( 'none', $found['proposal']['action'] );
		$this->assertStringContainsString( 'listeners', $found['detail'] );
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
					'children' => [
						[ 'name' => 'render_block hook', 'value' => 700.0, 'count' => 1, 'children' => [] ],
					],
				],
			],
		];

		$found = $this->of_kind( Findings::for_request( $record, $this->instrumented_rule() ), 'dominant_span' );

		$this->assertNotNull( $found );
		$this->assertSame( 2, $found['metric']['depth'] );
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
		$record                = $this->healthy_record();
		$record['duration_ms'] = 420000.0;
		$record['folded']      = true;
		$record['flame']       = [
			'name'     => 'request',
			'value'    => 175.6,
			'children' => [ [ 'name' => 'init hook', 'value' => 175.6, 'count' => 900, 'children' => [] ] ],
		];
		$record['profiles']    = [
			'init hook' => [ 'count' => 900, 'time' => 175.6, 'entries' => [] ],
		];

		$kinds = $this->kinds( Findings::for_request( $record, $this->instrumented_rule() ) );

		$this->assertSame( 'unattributed', $kinds[0], 'the biggest unexplained number leads' );
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
		$this->assertSame( 'add_custom_events', $found['proposal']['action'] );
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
		$plugins               = [ [ 'name' => 'giant-7734 plugin', 'value' => 560.0, 'children' => [] ] ];
		for ( $i = 0; $i < 14; $i++ ) {
			$plugins[] = [ 'name' => "rest-{$i} plugin", 'value' => 25.0, 'children' => [] ];
		}
		$record['flame_data'] = [
			'name'     => 'request',
			'value'    => 910.0,
			'children' => [ [ 'name' => 'process', 'value' => 910.0, 'children' => $plugins ] ],
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
							'children' => [
								[
									'name'     => 'do_blocks @9',
									'value'    => 56104.6,
									'count'    => 10,
									'children' => [
										[ 'name' => 'sql: WP_Query->get_posts', 'value' => 52105.4, 'count' => 299, 'children' => [] ],
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
}
