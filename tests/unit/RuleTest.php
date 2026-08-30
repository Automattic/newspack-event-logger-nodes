<?php
declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger_Nodes\Rule;

final class RuleTest extends TestCase {

	public function test_log_and_skip_predicates(): void {
		$log  = new Rule( 'a1', '/shop/', Rule::ACTION_LOG );
		$skip = new Rule( 'b2', '/wp-cron', Rule::ACTION_SKIP );
		$this->assertTrue( $log->is_log() );
		$this->assertFalse( $log->is_skip() );
		$this->assertTrue( $skip->is_skip() );
		$this->assertFalse( $skip->is_log() );
	}

	public function test_is_exact_is_the_question_mark_terminator(): void {
		$exact  = new Rule( 'a', '/shop/checkout?', Rule::ACTION_LOG );
		$prefix = new Rule( 'b', '/shop/', Rule::ACTION_LOG );
		$this->assertTrue( $exact->is_exact() );
		$this->assertFalse( $prefix->is_exact() );
	}

	public function test_from_array_round_trips_to_array(): void {
		$data = [
			'id'                          => 'c3',
			'pattern'                     => '/shop/',
			'action'                      => 'log',
			'auto_disable_threshold'      => 5000,
			'auto_protect_time_threshold' => 2.5,
			'significant_events'          => [ 'template_redirect' ],
			'custom_events'               => [ 'cache_hit' ],
			'hooks'                       => [ 'init', 'wp' ],
			'hooks_in'                    => 'inline',
			'log_queries'                 => true,
		];
		$rule = Rule::from_array( $data );
		$this->assertSame( $data, $rule->to_array() );
	}

	public function test_from_array_supplies_defaults_and_coerces(): void {
		$rule = Rule::from_array( [ 'id' => 'd4', 'pattern' => '/x', 'action' => 'log' ] );
		$this->assertSame( 0, $rule->auto_disable_threshold );
		$this->assertSame( 0.0, $rule->auto_protect_time_threshold );
		$this->assertSame( [], $rule->significant_events );
		$this->assertSame( [], $rule->custom_events );
		$this->assertSame( [], $rule->hooks );
		$this->assertSame( 'inline', $rule->hooks_in );
		// Query spans need SAVEQUERIES and cost two entries per query, so a
		// rule that says nothing gets none.
		$this->assertFalse( $rule->log_queries );
	}

	public function test_from_array_defaults_unknown_action_to_skip(): void {
		$rule = Rule::from_array( [ 'id' => 'e5', 'pattern' => '/x', 'action' => 'garbage' ] );
		$this->assertTrue( $rule->is_skip() );
	}

	public function test_pointer_rule_carries_null_hooks(): void {
		$rule = Rule::from_array( [ 'id' => 'f6', 'pattern' => '/heavy/', 'action' => 'log', 'hooks' => null, 'hooks_in' => 'mc' ] );
		$this->assertNull( $rule->hooks );
		$this->assertSame( 'mc', $rule->hooks_in );
	}

	public function test_from_array_rejects_a_missing_pattern(): void {
		// Coercing to '/' aliased the log-all baseline's id, so a pattern-less
		// upsert replaced it with a skip rule and killed site-wide logging.
		$this->expectException( \InvalidArgumentException::class );
		Rule::from_array( [ 'id' => 'a1b2c3d4e5f6', 'action' => 'skip' ] );
	}

	public function test_from_array_rejects_an_empty_pattern(): void {
		$this->expectException( \InvalidArgumentException::class );
		Rule::from_array( [ 'id' => 'a1b2c3d4e5f6', 'pattern' => '', 'action' => 'log' ] );
	}

	public function test_from_array_rejects_a_pointer_tier_carrying_inline_hooks(): void {
		$this->expectException( \InvalidArgumentException::class );
		Rule::from_array( [ 'id' => 'f6', 'pattern' => '/tarot/', 'action' => 'log', 'hooks' => [ 'wp_loaded' ], 'hooks_in' => 'mc' ] );
	}

	public function test_from_array_rejects_a_pointer_tier_whose_hooks_key_was_dropped(): void {
		// A pointer entry whose hooks key never arrived — truncated or hand-edited.
		$this->expectException( \InvalidArgumentException::class );
		Rule::from_array( [ 'id' => 'f6', 'pattern' => '/tarot/', 'action' => 'log', 'hooks_in' => 'mc' ] );
	}

	public function test_from_array_reads_scalar_hooks_as_an_empty_inline_list(): void {
		// Only an explicit null names the pointer tier. A hand-edited '' (or any
		// producer that encoded an empty array as one) is a rule with no hooks —
		// reading it as unresolved contradicted inline and dropped the rule.
		$rule = Rule::from_array( [
			'id'                     => 'a1b2c3d4e5f6',
			'pattern'                => '/tarot/',
			'action'                 => 'log',
			'auto_disable_threshold' => 7331,
			'custom_events'          => [ 'tarot_draw' ],
			'hooks'                  => '',
			'hooks_in'               => 'inline',
		] );

		$this->assertSame( [], $rule->hooks );
		$this->assertSame( 'inline', $rule->hooks_in );
		$this->assertTrue( $rule->is_log() );
		$this->assertSame( 7331, $rule->auto_disable_threshold );
		$this->assertSame( [ 'tarot_draw' ], $rule->custom_events );
	}

	public function test_from_array_rejects_unresolved_hooks_on_the_inline_tier(): void {
		$this->expectException( \InvalidArgumentException::class );
		Rule::from_array( [ 'id' => 'f6', 'pattern' => '/tarot/', 'action' => 'log', 'hooks' => null ] );
	}

	public function test_constructor_rejects_an_empty_pattern(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Rule( 'a1b2c3d4e5f6', '', Rule::ACTION_LOG );
	}

	public function test_constructor_rejects_a_pointer_tier_carrying_inline_hooks(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Rule( 'a1b2c3d4e5f6', '/tarot/', Rule::ACTION_LOG, hooks: [ 'wp_loaded' ], hooks_in: Rule::HOOKS_MC );
	}

	public function test_with_id_copies_the_rule_with_a_new_id(): void {
		$rule = Rule::from_array( [ 'id' => 'old', 'pattern' => '/heavy/', 'action' => 'log', 'hooks' => [ 'init', 'wp' ] ] );

		$rekeyed = $rule->with_id( 'new' );

		$this->assertSame( 'new', $rekeyed->id );
		$this->assertSame( 'old', $rule->id, 'the original is untouched' );
		$this->assertSame( '/heavy/', $rekeyed->pattern );
		$this->assertSame( [ 'init', 'wp' ], $rekeyed->hooks );
	}
}
