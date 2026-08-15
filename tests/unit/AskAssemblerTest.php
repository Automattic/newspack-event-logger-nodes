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
			'p95_ms' => 2600.0,
		];
		$requests = [
			[ 'rid' => 'a', 'duration_ms' => 300, 'status_code' => 200, 'partition' => 0 ],
			[ 'rid' => 'b', 'duration_ms' => 2900, 'status_code' => 500, 'partition' => 1 ],
			[ 'rid' => 'c', 'duration_ms' => 1400, 'status_code' => 200, 'partition' => 0 ],
		];

		$brief = Ask_Assembler::for_url( $stats, $requests, [ 'status' => [] ], $this->rule() );

		$this->assertSame( 'url', $brief['subject'] );
		$this->assertStringContainsString( '[REDACTED]', $brief['url'] );
		$this->assertSame( 4210, $brief['stats']['count'] );
		$this->assertSame( [ 'b', 'c', 'a' ], \array_column( $brief['worst_requests'], 'rid' ) );
		$this->assertSame( 'a1b2c3d4e5f6', $brief['rule']['id'] );
	}

	public function test_a_url_with_no_rule_says_so_and_gets_the_cold_start_finding(): void {
		$brief = Ask_Assembler::for_url(
			[ 'hash' => 'ff00', 'url' => '/uncovered', 'count' => 12, 'p95_ms' => 4000.0 ],
			[],
			[],
			null
		);

		$this->assertNull( $brief['rule'] );
		$this->assertSame(
			[ 'insufficient_instrumentation' ],
			\array_column( $brief['findings'], 'kind' )
		);
	}

	public function test_a_category_brief_carries_its_share_and_worst_contributors(): void {
		$categories = [
			'gyrobase' => [ 'avg_time' => 410.0, 'avg_count' => 12.0, 'samples' => 90 ],
			'core'     => [ 'avg_time' => 90.0, 'avg_count' => 400.0, 'samples' => 90 ],
		];

		$brief = Ask_Assembler::for_category( $categories, 'gyrobase' );

		$this->assertSame( 'category', $brief['subject'] );
		$this->assertSame( 'gyrobase', $brief['name'] );
		$this->assertSame( 410.0, $brief['avg_time_ms'] );
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
