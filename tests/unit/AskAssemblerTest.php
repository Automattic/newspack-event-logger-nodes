<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Ask_Assembler;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * The Ask brief: the descriptor IS the scope, so each shaper sends that thing
 * plus enough context to explain it — and nothing else.
 */
#[CoversClass( Ask_Assembler::class )]
class AskAssemblerTest extends TestCase {

	private function rule(): Rule {
		return new Rule( 'a1b2c3d4e5f6', '/calendar/today', Rule::ACTION_LOG, 0, 0.0, [], [], [ 'init', 'wp_loaded' ] );
	}

	private function record(): array {
		return [
			'url'         => '/calendar/today?token=hunter2seekrit',
			'duration_ms' => 812.0,
			'status_code' => 200,
			'remote_addr' => '203.0.113.7',
			'user_agent'  => 'Mozilla/5.0 (secret build)',
			'worker_type' => 'flame-builder',
			'entries'     => [
				[ 'n' => 1, 'ts' => 1000.000, 'k' => 'process (start)', 'm' => '' ],
				[ 'n' => 2, 'ts' => 1000.100, 'k' => 'init hook', 'm' => 'a' ],
				[ 'n' => 3, 'ts' => 1000.900, 'k' => 'wp_loaded hook', 'm' => 'b' ],
			],
			'flame'       => [
				'name'     => 'request',
				'value'    => 812.0,
				'children' => [
					[ 'name' => 'init', 'value' => 12.0, 'children' => [] ],
					[
						'name'     => 'wp_loaded',
						'value'    => 790.0,
						'count'    => 3,
						'children' => [
							[ 'name' => 'render_block', 'value' => 700.0, 'count' => 42, 'children' => [] ],
							[ 'name' => 'the_content', 'value' => 60.0, 'children' => [] ],
						],
					],
				],
			],
		];
	}

	public function test_a_url_brief_states_and_re_fetches_its_scope(): void {
		// The brief's numbers are one server's; its `fetch` pointer is how an
		// agent gets the rest. Without the scope on it, following the pointer
		// answers site-wide for the same hash, and nothing in the brief says
		// which of the two contradicting sets it was looking at.
		$brief = Ask_Assembler::for_url(
			[ 'url' => 'https://example.test/a', 'hash' => 'cccccccccccc', 'count' => 2, 'avg_ms' => 130.0 ],
			[],
			null,
			'alpha.example',
			false,
			1_741_000_800
		);

		$this->assertSame( 'alpha.example', $brief['server'] );
		$this->assertSame(
			[ 'hash' => 'cccccccccccc', 'server' => 'alpha.example' ],
			$brief['fetch'][0]['arguments']
		);
	}

	public function test_a_descriptor_parses_into_type_id_and_qualifier(): void {
		$this->assertSame(
			[ 'type' => 'request', 'id' => 'c6x0zgr', 'qualifier' => '3' ],
			Ask_Assembler::parse_descriptor( 'request:c6x0zgr:3' )
		);
		$this->assertSame(
			[ 'type' => 'url', 'id' => '25ecf5606840', 'qualifier' => '' ],
			Ask_Assembler::parse_descriptor( 'url:25ecf5606840' )
		);
	}

	public function test_an_unparseable_descriptor_is_refused(): void {
		$this->assertNull( Ask_Assembler::parse_descriptor( 'nonsense' ) );
		$this->assertNull( Ask_Assembler::parse_descriptor( 'wizard:x' ) );
	}

	public function test_a_request_brief_carries_the_numbers_and_the_findings(): void {
		$brief = Ask_Assembler::for_request( $this->record(), $this->rule() );

		$this->assertSame( 'request', $brief['subject'] );
		$this->assertSame( 812.0, $brief['duration_ms'] );
		$this->assertSame( 200, $brief['status_code'] );
		$this->assertSame( 'a1b2c3d4e5f6', $brief['rule']['id'] );
		$this->assertIsArray( $brief['findings'] );
		$this->assertNotSame( '', $brief['caveat'] );
	}

	public function test_a_request_brief_redacts_the_url_and_drops_the_environment(): void {
		$brief = Ask_Assembler::for_request( $this->record(), $this->rule() );

		$this->assertStringNotContainsString( 'hunter2seekrit', (string) \wp_json_encode( $brief ) );
		$this->assertStringContainsString( '[REDACTED]', $brief['url'] );
		$encoded = (string) \wp_json_encode( $brief );
		$this->assertStringNotContainsString( '203.0.113.7', $encoded, 'no IPs' );
		$this->assertStringNotContainsString( 'secret build', $encoded, 'no user agents' );
		$this->assertSame( 'flame-builder', $brief['env']['worker_type'] );
	}

	public function test_a_request_brief_names_the_server_that_served_it(): void {
		// The brief's numbers are scoped by server everywhere else; the env
		// block has to name the same axis or a scoped figure reads as the
		// site's. `host` used to sit here and no longer exists on the record.
		$record                = $this->record();
		$record['server_name'] = 'spoke-17.example';

		$brief = Ask_Assembler::for_request( $record, $this->rule() );

		$this->assertSame( 'spoke-17.example', $brief['env']['server_name'] );
		$this->assertArrayNotHasKey( 'host', $brief['env'] );
	}

	public function test_entries_ride_only_when_the_record_is_small(): void {
		$record = $this->record();
		$this->assertCount( 3, Ask_Assembler::for_request( $record, $this->rule() )['entries'] );

		$record['entries'] = \array_fill(
			0,
			Ask_Assembler::MAX_ENTRIES + 10,
			[ 'n' => 1, 'ts' => 1000.0, 'k' => 'x', 'm' => 'y' ]
		);
		$big = Ask_Assembler::for_request( $record, $this->rule() );

		$this->assertCount( Ask_Assembler::MAX_ENTRIES, $big['entries'] );
		$this->assertTrue( $big['entries_truncated'] );
	}

	public function test_an_entry_payload_is_capped_and_says_so(): void {
		$record                    = $this->record();
		$record['entries'][1]['m'] = \str_repeat( 'z', Ask_Assembler::MAX_ENTRY_CHARS + 500 );

		$brief = Ask_Assembler::for_request( $record, $this->rule() );

		$this->assertSame(
			Ask_Assembler::MAX_ENTRY_CHARS + \mb_strlen( '…(truncated)' ),
			\mb_strlen( $brief['entries'][1]['m'] )
		);
	}

	/**
	 * A byte cut mid-codepoint makes wp_json_encode return false, and an MCP
	 * reply comes back EMPTY rather than as an error.
	 */
	public function test_a_multibyte_payload_survives_the_cap_as_valid_utf8(): void {
		$record                    = $this->record();
		$record['entries'][1]['m'] = \str_repeat( '→', Ask_Assembler::MAX_ENTRY_CHARS + 20 );

		$brief = Ask_Assembler::for_request( $record, $this->rule() );

		$this->assertNotFalse( \wp_json_encode( $brief ) );
		$this->assertTrue( \mb_check_encoding( $brief['entries'][1]['m'], 'UTF-8' ) );
	}

	/** The production key: only a FOLDED record carries `flame`. */
	private function loaded_record(): array {
		$record               = $this->record();
		$record['flame_data'] = $record['flame'];
		unset( $record['flame'] );
		return $record;
	}

	public function test_a_span_resolves_on_a_loaded_record(): void {
		$brief = Ask_Assembler::for_span( $this->loaded_record(), 'wp_loaded', $this->rule() );

		$this->assertNotNull( $brief, 'reading the wrong key made every flame click a dead end' );
		$this->assertSame( 790.0, $brief['ms'] );
	}

	public function test_a_request_brief_summarises_a_loaded_records_flame(): void {
		$brief = Ask_Assembler::for_request( $this->loaded_record(), $this->rule() );

		$this->assertSame( 812.0, $brief['flame']['profiled_ms'] );
		$this->assertSame(
			[ 'wp_loaded', 'init' ],
			\array_column( $brief['flame']['top_level'], 'name' )
		);
	}

	public function test_a_span_brief_carries_its_subtree_siblings_and_parent_total(): void {
		$brief = Ask_Assembler::for_span( $this->record(), 'wp_loaded', $this->rule() );

		$this->assertSame( 'span', $brief['subject'] );
		$this->assertSame( 'wp_loaded', $brief['name'] );
		$this->assertSame( 790.0, $brief['ms'] );
		$this->assertSame( 3, $brief['count'] );
		$this->assertSame( 812.0, $brief['parent_ms'] );
		$this->assertSame( [ 'init' ], \array_column( $brief['siblings'], 'name' ) );
		$this->assertSame(
			[ 'render_block', 'the_content' ],
			\array_column( $brief['subtree'], 'name' )
		);
	}

	/** A span is only actionable through the rule governing its request's URL. */
	public function test_a_span_brief_carries_the_rule_the_edit_would_land_on(): void {
		$brief = Ask_Assembler::for_span( $this->record(), 'render_block', $this->rule() );

		$this->assertSame( 'a1b2c3d4e5f6', $brief['rule']['id'] );
		$this->assertSame( '/calendar/today', $brief['rule']['pattern'] );
	}

	public function test_an_unknown_span_is_refused(): void {
		$this->assertNull( Ask_Assembler::for_span( $this->record(), 'no_such_hook', $this->rule() ) );
	}

	public function test_an_entry_brief_carries_its_neighbours_and_both_gaps(): void {
		$brief = Ask_Assembler::for_entry( $this->record(), 3 );

		$this->assertSame( 'entry', $brief['subject'] );
		$this->assertSame( 'wp_loaded hook', $brief['entry']['k'] );
		$this->assertEqualsWithDelta( 800.0, $brief['gap_before_ms'], 0.1 );
		$this->assertNull( $brief['gap_after_ms'] );
		$this->assertSame(
			[ 'process (start)', 'init hook' ],
			\array_column( $brief['neighbours'], 'k' ),
			'NEIGHBOURS entries either side, and this one has none after it'
		);
	}

	public function test_an_unknown_entry_is_refused(): void {
		$this->assertNull( Ask_Assembler::for_entry( $this->record(), 99 ) );
	}

	public function test_a_url_brief_carries_stats_and_the_worst_recent_requests(): void {
		$stats = [
			'hash'   => '25ecf5606840',
			'url'    => '/calendar/today?token=hunter2seekrit',
			'count'  => 4210,
			'avg_ms' => 812.0,
			
		];
		$requests = [
			[ 'rid' => 'a', 'duration_ms' => 300, 'status_code' => 200, 'partition' => 0 ],
			[ 'rid' => 'b', 'duration_ms' => 2900, 'status_code' => 500, 'partition' => 1 ],
			[ 'rid' => 'c', 'duration_ms' => 1400, 'status_code' => 200, 'partition' => 0 ],
		];

		$brief = Ask_Assembler::for_url( $stats, $requests, $this->rule(), '', false, 1_741_000_800 );

		$this->assertSame( 'url', $brief['subject'] );
		$this->assertStringContainsString( '[REDACTED]', $brief['url'] );
		$this->assertSame( 4210, $brief['stats']['count'] );
		$this->assertSame( [ 'b', 'c', 'a' ], \array_column( $brief['worst_requests'], 'rid' ) );
		$this->assertSame( 'a1b2c3d4e5f6', $brief['rule']['id'] );
	}

	public function test_a_url_brief_says_when_the_request_scan_stopped_short(): void {
		// The worst-five are drawn from whatever the index walk reached, so a
		// brief quoting them without that caveat sounds like the whole record.
		// Seven requests: the list is ALWAYS cut to five, and the flag is
		// about the walk behind it, so a completed scan still reads false.
		$stats    = [ 'url' => 'https://example.test/slow', 'hash' => 'e9e9e9e9e9e9', 'count' => 7, 'avg_ms' => 511.0 ];
		$requests = [];
		foreach ( \range( 1, 7 ) as $n ) {
			$requests[] = [ 'rid' => "r{$n}", 'duration_ms' => 100.0 * $n, 'status_code' => 200, 'partition' => 0 ];
		}

		$complete = Ask_Assembler::for_url( $stats, $requests, null, 'delta.example', false, 1_741_000_800 );
		$stopped  = Ask_Assembler::for_url( $stats, [], null, 'delta.example', true, 1_741_000_800 );

		$this->assertCount( Ask_Assembler::WORST_REQUESTS, $complete['worst_requests'] );
		$this->assertFalse( $complete['scan_stopped_early'] );
		$this->assertTrue( $stopped['scan_stopped_early'] );
		$this->assertArrayNotHasKey( 'worst_requests_truncated', $complete );
	}

	public function test_a_url_brief_names_the_window_its_requests_were_drawn_from(): void {
		// An empty list reads two ways — no traffic, or no traffic since the
		// window opened — and only the reply itself can tell them apart.
		$brief = Ask_Assembler::for_url(
			[ 'url' => 'https://example.test/quiet', 'hash' => 'b7b7b7b7b7b7', 'count' => 0 ],
			[],
			null,
			'zeta.example',
			false,
			1_741_000_800
		);

		$this->assertSame( 1_741_000_800, $brief['requests_window_start'] );
	}

	public function test_a_url_brief_cannot_be_built_without_naming_that_window(): void {
		// Same reason the scan's ending is required: a narrower number that
		// does not say so reads as the site's.
		$this->expectException( \ArgumentCountError::class );
		Ask_Assembler::for_url(
			[ 'url' => 'https://example.test/quiet', 'hash' => 'd0d0d0d0d0d0', 'count' => 3 ],
			[],
			null,
			'eta.example',
			false
		);
	}

	public function test_a_url_brief_cannot_be_built_without_stating_how_its_scan_ended(): void {
		// A completeness claim nobody made is the one answer that reassures.
		$this->expectException( \ArgumentCountError::class );
		Ask_Assembler::for_url(
			[ 'url' => 'https://example.test/quiet', 'hash' => 'd0d0d0d0d0d0', 'count' => 3 ],
			[],
			null,
			'epsilon.example'
		);
	}

	/**
	 * A tree holds duplicate siblings apart with a hidden suffix, so four
	 * `query hook` children render as four identical rows that say nothing.
	 * Folded, the one row that carries signal is legible.
	 */
	public function test_a_span_brief_folds_repeated_children_into_one_row(): void {
		$record = [
			'flame' => [
				'name'     => 'request',
				'value'    => 100.0,
				'children' => [
					[
						'name'     => 'component',
						'value'    => 10.5,
						'children' => [
							[ 'name' => 'query hook', 'value' => 0.25, 'count' => 1, 'children' => [] ],
							[ 'name' => 'query hook', 'value' => 0.75, 'count' => 1, 'children' => [] ],
							[ 'name' => 'component_include', 'value' => 0.4, 'count' => 1, 'children' => [] ],
						],
					],
				],
			],
		];

		$brief = Ask_Assembler::for_span( $record, 'component', $this->rule() );

		$this->assertSame(
			[ 'query hook', 'component_include' ],
			\array_column( $brief['subtree'], 'name' )
		);
		$this->assertSame( 1.0, $brief['subtree'][0]['ms'] );
		$this->assertSame( 2, $brief['subtree'][0]['count'] );
	}

	/**
	 * The request brief folds duplicate siblings, so a span brief that reports
	 * only the first occurrence contradicts it — and the missing time lands
	 * nowhere, which reads as "the sibling is the problem".
	 */
	public function test_a_span_brief_folds_the_span_it_is_about(): void {
		$record = [
			'flame' => [
				'name'     => 'request',
				'value'    => 100.0,
				'children' => [
					[ 'name' => 'query hook', 'value' => 10.0, 'count' => 1, 'children' => [ [ 'name' => 'sql', 'value' => 4.0, 'children' => [] ] ] ],
					[ 'name' => 'query hook', 'value' => 20.0, 'count' => 2, 'children' => [ [ 'name' => 'sql', 'value' => 9.0, 'children' => [] ] ] ],
					[ 'name' => 'render', 'value' => 50.0, 'children' => [] ],
				],
			],
		];

		$brief = Ask_Assembler::for_span( $record, 'query hook', $this->rule() );

		$this->assertSame( 30.0, $brief['ms'], 'all three calls, as the request brief counts them' );
		$this->assertSame( 3, $brief['count'] );
		$this->assertSame( [ 'render' ], \array_column( $brief['siblings'], 'name' ) );
		$this->assertSame( 13.0, $brief['subtree'][0]['ms'], 'the subtree spans every occurrence' );
	}

	/**
	 * One name appears under several parents, and a depth-first search finds
	 * whichever comes first — on a real record that was `pre_get_posts hook`
	 * at 9ms under `process`, while the sixteen under `do_blocks` held 2266ms
	 * of a 3.3s request. The brief pointed away from its own answer.
	 */
	public function test_a_span_brief_reports_the_parent_holding_the_time(): void {
		$record = [
			'flame' => [
				'name'     => 'request',
				'value'    => 940.0,
				'children' => [
					[
						'name'     => 'boot',
						'value'    => 7.0,
						'children' => [
							[ 'name' => 'query hook', 'value' => 3.0, 'count' => 1, 'children' => [] ],
							[ 'name' => 'boot_cheap', 'value' => 1.0, 'children' => [] ],
						],
					],
					[
						'name'     => 'render',
						'value'    => 910.0,
						'children' => [
							[ 'name' => 'query hook', 'value' => 400.0, 'count' => 1, 'children' => [ [ 'name' => 'sql', 'value' => 380.0, 'children' => [] ] ] ],
							[ 'name' => 'query hook', 'value' => 500.0, 'count' => 1, 'children' => [ [ 'name' => 'sql', 'value' => 470.0, 'children' => [] ] ] ],
							[ 'name' => 'markup', 'value' => 6.0, 'children' => [] ],
						],
					],
				],
			],
		];

		$brief = Ask_Assembler::for_span( $record, 'query hook', $this->rule() );

		$this->assertSame( 900.0, $brief['ms'], 'the group that holds the time, not the first one found' );
		$this->assertSame( 2, $brief['count'] );
		$this->assertSame( 'render', $brief['parent'] );
		$this->assertSame( [ 'markup' ], \array_column( $brief['siblings'], 'name' ) );
		$this->assertSame( 850.0, $brief['subtree'][0]['ms'] );
		$this->assertSame( 3.0, $brief['elsewhere']['ms'], 'what the chosen parent leaves out' );
		$this->assertSame( 1, $brief['elsewhere']['count'] );
		$this->assertSame( [ 'boot' ], $brief['elsewhere']['parents'] );
	}

	public function test_a_span_brief_keeps_only_the_slowest_children(): void {
		$children = [];
		for ( $i = 1; $i <= 9; $i++ ) {
			$children[] = [ 'name' => "child{$i}", 'value' => (float) $i, 'children' => [] ];
		}
		$record = [
			'flame' => [
				'name'     => 'request',
				'value'    => 100.0,
				'children' => [ [ 'name' => 'component', 'value' => 45.0, 'children' => $children ] ],
			],
		];

		$brief = Ask_Assembler::for_span( $record, 'component', $this->rule() );

		$this->assertCount( Ask_Assembler::TOP_SPANS, $brief['subtree'] );
		$this->assertSame( 'child9', $brief['subtree'][0]['name'] );
	}

	/**
	 * The series is what `url_detail` returns, and nothing renders it here —
	 * it was the largest thing in the brief and the only unbounded one.
	 */
	public function test_a_url_brief_leaves_the_time_series_to_the_verb_that_owns_it(): void {
		$brief = Ask_Assembler::for_url(
			[ 'hash' => '25ecf5606840', 'url' => '/calendar', 'count' => 12 ],
			[],
			$this->rule(),
			'',
			false,
			1_741_000_800
		);

		$this->assertArrayNotHasKey( 'breakdown', $brief );
		$this->assertSame(
			[ 'performance_url_detail' ],
			\array_column( $brief['fetch'], 'tool' )
		);
		$this->assertSame(
			[ 'hash' => '25ecf5606840' ],
			$brief['fetch'][0]['arguments']
		);
	}

	public function test_worst_requests_name_themselves_without_their_storage_coordinates(): void {
		$brief = Ask_Assembler::for_url(
			[ 'hash' => 'ff00', 'url' => '/calendar', 'count' => 2 ],
			[
				[
					'rid'          => 'w0rst1',
					'duration_ms'  => 9100.4,
					'status_code'  => 500,
					'error_status' => 'F',
					'partition'    => 3,
					'segment'      => 10,
					'offset'       => 11769941,
					'length'       => 91551,
				],
			],
			$this->rule(),
			'',
			false,
			1_741_000_800
		);

		$this->assertSame(
			[ 'rid', 'partition', 'duration_ms', 'status_code', 'error_status' ],
			\array_keys( $brief['worst_requests'][0] )
		);
	}

	/**
	 * A roster of every custom event the rule enables is the same mistake the
	 * hook list already avoids: dozens of names nothing renders.
	 */
	public function test_a_rule_rides_as_counts_rather_than_rosters(): void {
		$rule  = new Rule(
			'a1b2c3d4e5f6',
			'/calendar/today',
			Rule::ACTION_LOG,
			0,
			0.0,
			[ 'init' ],
			[ 'loop', 'query_sql', 'component_include' ],
			[ 'init', 'wp_loaded' ]
		);
		$brief = Ask_Assembler::for_request( $this->record(), $rule );

		$this->assertArrayNotHasKey( 'custom_events', $brief['rule'] );
		$this->assertSame( 3, $brief['rule']['custom_event_count'] );
		$this->assertSame( [ 'init' ], $brief['rule']['significant_events'] );
	}

	/** The entry list is capped, so the record's own address rides along. */
	public function test_a_request_brief_says_how_an_agent_fetches_it_again(): void {
		$brief = Ask_Assembler::for_request(
			[ 'rid' => 'c6x0zgrq1w9v', 'url' => '/x' ] + $this->record(),
			$this->rule()
		);

		$this->assertSame(
			[ [ 'tool' => 'performance_request_detail', 'arguments' => [ 'rid' => 'c6x0zgrq1w9v' ] ] ],
			$brief['fetch']
		);
	}

	public function test_a_span_brief_says_how_an_agent_fetches_it_again(): void {
		$brief = Ask_Assembler::for_span( $this->record(), 'wp_loaded', $this->rule(), 'request:c6x0zgr:3' );

		$this->assertSame( 'performance_ask', $brief['fetch'][0]['tool'] );
		$this->assertSame(
			[ 'descriptor' => 'span:wp_loaded', 'context' => 'request:c6x0zgr:3' ],
			$brief['fetch'][0]['arguments']
		);
	}

	public function test_a_url_with_no_rule_says_so_and_gets_the_cold_start_finding(): void {
		$brief = Ask_Assembler::for_url(
			[ 'hash' => 'ff00', 'url' => '/uncovered', 'count' => 12, 'avg_ms' => 4000.0 ],
			[],
			null,
			'',
			false,
			1_741_000_800
		);

		$this->assertNull( $brief['rule'] );
		$this->assertSame(
			[ 'insufficient_instrumentation' ],
			\array_column( $brief['findings'], 'kind' )
		);
	}

	/**
	 * The rows are `Stats_Store::sums_to_display()`'s — `time` and `count`,
	 * already divided by the window's request count. Reading anything else
	 * reports a category the dashboard shows at 90ms as 0ms.
	 */
	public function test_a_category_brief_carries_its_share_and_worst_contributors(): void {
		$categories = [
			'gyrobase' => [ 'time' => 410.0, 'count' => 12.0, 'samples' => 90 ],
			'core'     => [ 'time' => 90.0, 'count' => 400.0, 'samples' => 90 ],
		];

		$brief = Ask_Assembler::for_category( $categories, 'gyrobase' );

		$this->assertSame( 'category', $brief['subject'] );
		$this->assertSame( 'gyrobase', $brief['name'] );
		$this->assertSame( 410.0, $brief['avg_time_ms'] );
		$this->assertSame( 12.0, $brief['avg_count'] );
		$this->assertEqualsWithDelta( 0.82, $brief['share'], 0.01 );
		$this->assertSame( [ 'core' ], \array_column( $brief['others'], 'name' ) );
	}

	/**
	 * A category row inside a request shows THAT request's profile, so a brief
	 * answering with site-wide numbers describes something else entirely —
	 * and a category present here but absent from the recent global window
	 * made a visible row a dead click.
	 */
	public function test_a_category_in_a_request_answers_from_that_request(): void {
		$record               = $this->record();
		$record['profiles']   = [
			'gyrobase' => [ 'time' => 410.0, 'count' => 12, 'entries' => [] ],
			'core'     => [ 'time' => 90.0, 'count' => 400, 'entries' => [] ],
		];

		$brief = Ask_Assembler::for_request_category( $record, 'gyrobase' );

		$this->assertSame( 'category', $brief['subject'] );
		$this->assertSame( 'request', $brief['scope'] );
		$this->assertSame( 410.0, $brief['avg_time_ms'] );
		$this->assertEqualsWithDelta( 0.82, $brief['share'], 0.01 );
		$this->assertSame( [ 'core' ], \array_column( $brief['others'], 'name' ) );
	}

	public function test_a_global_category_brief_says_it_is_global(): void {
		$brief = Ask_Assembler::for_category(
			[ 'gyrobase' => [ 'avg_time' => 410.0 ], 'core' => [ 'avg_time' => 90.0 ] ],
			'gyrobase'
		);

		$this->assertSame( 'recent window', $brief['scope'] );
	}

	public function test_a_category_absent_from_the_request_is_refused(): void {
		$this->assertNull( Ask_Assembler::for_request_category( $this->record(), 'gyrobase' ) );
	}

	public function test_an_unknown_category_is_refused(): void {
		$this->assertNull( Ask_Assembler::for_category( [ 'core' => [] ], 'gyrobase' ) );
	}
}
