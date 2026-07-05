<?php
declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Matcher;
use PHPUnit\Framework\TestCase;

final class RuleMatcherTest extends TestCase {

	public function test_longest_prefix_wins_regardless_of_order(): void {
		$matcher = new Rule_Matcher( [
			new Rule( 'root', '/', Rule::ACTION_LOG ),
			new Rule( 'shop', '/shop/', Rule::ACTION_LOG ),
			new Rule( 'checkout', '/shop/checkout/', Rule::ACTION_LOG ),
		] );
		$this->assertSame( 'checkout', $matcher->match( '/shop/checkout/pay' )->id );
		$this->assertSame( 'shop', $matcher->match( '/shop/cart' )->id );
		$this->assertSame( 'root', $matcher->match( '/about' )->id );
	}

	public function test_exact_beats_equal_length_prefix(): void {
		$matcher = new Rule_Matcher( [
			new Rule( 'prefix', '/shop/', Rule::ACTION_LOG ),  // strlen 6
			new Rule( 'exact', '/shop?', Rule::ACTION_LOG ),   // strlen 6, exact
		] );
		// Normalized '/shop' -> '/shop?' : the exact rule matches it exactly.
		$this->assertSame( 'exact', $matcher->match( '/shop' )->id );
		// '/shop/x' -> '/shop/x?' : exact '/shop?' does NOT match; prefix '/shop/' does.
		$this->assertSame( 'prefix', $matcher->match( '/shop/x' )->id );
	}

	public function test_no_match_returns_null(): void {
		$matcher = new Rule_Matcher( [ new Rule( 'shop', '/shop/', Rule::ACTION_LOG ) ] );
		$this->assertNull( $matcher->match( '/about' ) );
	}

	public function test_skip_rule_is_returned_when_it_is_most_specific(): void {
		$matcher = new Rule_Matcher( [
			new Rule( 'root', '/', Rule::ACTION_LOG ),
			new Rule( 'cron', '/wp-cron', Rule::ACTION_SKIP ),
		] );
		$hit = $matcher->match( '/wp-cron.php' );
		$this->assertSame( 'cron', $hit->id );
		$this->assertTrue( $hit->is_skip() );
	}

	public function test_query_string_is_stripped_before_matching(): void {
		$matcher = new Rule_Matcher( [ new Rule( 'shop', '/shop/', Rule::ACTION_LOG ) ] );
		$this->assertSame( 'shop', $matcher->match( '/shop/cart?coupon=X' )->id );
	}

	public function test_match_is_cached_per_url(): void {
		$matcher = new Rule_Matcher( [ new Rule( 'root', '/', Rule::ACTION_LOG ) ] );
		$first  = $matcher->match( '/a' );
		$second = $matcher->match( '/a' );
		$this->assertSame( $first, $second );
	}

	public function test_normalize_appends_question_terminator(): void {
		$this->assertSame( '/shop?', Rule_Matcher::normalize( '/shop?x=1' ) );
		$this->assertSame( '/shop?', Rule_Matcher::normalize( '/shop' ) );
	}
}
