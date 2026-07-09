<?php
declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rule;
use PHPUnit\Framework\TestCase;

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
	}

	public function test_from_array_defaults_unknown_action_to_skip(): void {
		$rule = Rule::from_array( [ 'id' => 'e5', 'pattern' => '/x', 'action' => 'garbage' ] );
		$this->assertTrue( $rule->is_skip() );
	}

	public function test_minimal_is_a_log_rule_with_no_hooks(): void {
		$rule = Rule::minimal();
		$this->assertSame( '/', $rule->pattern );
		$this->assertTrue( $rule->is_log() );
		$this->assertSame( [], $rule->hooks );
		$this->assertSame( '', $rule->id );
	}

	public function test_pointer_rule_carries_null_hooks(): void {
		$rule = Rule::from_array( [ 'id' => 'f6', 'pattern' => '/heavy/', 'action' => 'log', 'hooks' => null, 'hooks_in' => 'mc' ] );
		$this->assertNull( $rule->hooks );
		$this->assertSame( 'mc', $rule->hooks_in );
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
