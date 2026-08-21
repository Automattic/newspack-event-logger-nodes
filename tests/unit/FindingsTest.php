<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Findings;
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
		$this->assertSame( 'wp_loaded hook', $found['proposal']['value'] );
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

	public function test_a_gap_across_the_fold_marker_is_not_a_gap(): void {
		// The merged entries ARE that window. Reading it as idle time also had
		// the record proposing MORE hooks while `truncation` proposed fewer.
		$record            = $this->healthy_record();
		$record['folded']  = true;
		$record['entries'] = [
			[ 'n' => 1, 'ts' => 2000.000, 'k' => 'process (start)', 'm' => '' ],
			[ 'n' => 2, 'ts' => 2000.050, 'k' => 'entries (aggregated)', 'm' => '87074 entries merged into the flame graph under memory pressure' ],
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
			[ 'n' => 2, 'ts' => 1000.010, 'k' => 'entries (aggregated)', 'm' => '812 entries merged into the flame graph under memory pressure' ],
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

		$this->assertSame( 'wp_loaded hook', $found['proposal']['value'] );
		$this->assertStringContainsString( 'wp_loaded hook', $found['proposal']['why'] );
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
				[ 'url' => '/calendar/today', 'count' => 4210, 'avg_ms' => 812.0, 'p95_ms' => 2600.0, 'max_peak_mb' => 96.0 ],
				null
			),
			'insufficient_instrumentation'
		);

		$this->assertNotNull( $found );
		$this->assertSame( 4210, $found['metric']['count'] );
		$this->assertSame( 2600.0, $found['metric']['p95_ms'] );
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
	 * `bind_current_scope()` accepts a significant event with or without the
	 * ` hook` suffix, so a rule listing `wp_loaded` already covers the span
	 * named `wp_loaded hook` — proposing it again is advice to do nothing.
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
		$this->assertStringContainsString( 'outbound', $caveat );
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
}
