<?php
declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Matcher;

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

	public function test_a_query_pattern_targets_only_matching_query_urls(): void {
		// Worker URLs are distinguished ONLY by query (`?job-worker`): a
		// pattern with content after `?` matches that path exactly AND the
		// query by prefix, so workers get their own rule.
		$matcher = new Rule_Matcher( [
			new Rule( 'page', '/jobs/image-to-wordpress?', Rule::ACTION_SKIP ),
			new Rule( 'worker', '/jobs/image-to-wordpress?job-worker', Rule::ACTION_LOG ),
		] );
		$hit = $matcher->match( '/jobs/image-to-wordpress?job-worker' );
		$this->assertSame( 'worker', $hit->id );
		$this->assertTrue( $hit->is_log() );
		// No query → the exact-path rule; a different query too.
		$this->assertSame( 'page', $matcher->match( '/jobs/image-to-wordpress' )->id );
		$this->assertSame( 'page', $matcher->match( '/jobs/image-to-wordpress?preview=1' )->id );
	}

	public function test_query_pattern_matches_query_prefix_case_insensitively(): void {
		$matcher = new Rule_Matcher(
			[ new Rule( 'w', '/jobs/x?JOB-worker', Rule::ACTION_LOG ) ]
		);
		$this->assertSame( 'w', $matcher->match( '/jobs/X?job-worker&extra=1' )->id );
		// The path part is exact, never a prefix.
		$this->assertNull( $matcher->match( '/jobs/x/sub?job-worker' ) );
	}

	public function test_query_pattern_outranks_exact_and_prefix(): void {
		$matcher = new Rule_Matcher( [
			new Rule( 'prefix', '/jobs/', Rule::ACTION_LOG ),
			new Rule( 'exact', '/jobs/x?', Rule::ACTION_LOG ),
			new Rule( 'query', '/jobs/x?job-worker', Rule::ACTION_SKIP ),
		] );
		$hit = $matcher->match( '/jobs/x?job-worker' );
		$this->assertSame( 'query', $hit->id );
		$this->assertTrue( $hit->is_skip() );
	}

	public function test_match_is_cached_per_url(): void {
		$matcher = new Rule_Matcher( [ new Rule( 'root', '/', Rule::ACTION_LOG ) ] );
		$first  = $matcher->match( '/a' );
		$second = $matcher->match( '/a' );
		$this->assertSame( $first, $second );
	}

	public function test_matching_is_case_insensitive(): void {
		// The legacy compile_url_filter regex was case-insensitive (/i); preserve that
		// so a mixed-case pattern or a mixed-case request URL matches as before.
		$matcher = new Rule_Matcher( [
			new Rule( 'root', '/', Rule::ACTION_LOG ),
			new Rule( 'cron', '/WP-Cron', Rule::ACTION_SKIP ),
			new Rule( 'news', '/News?', Rule::ACTION_LOG ),
		] );
		$this->assertSame( 'cron', $matcher->match( '/wp-cron.php' )->id, 'lc URL matches mixed-case skip prefix' );
		$this->assertSame( 'cron', $matcher->match( '/WP-CRON.php' )->id, 'uc URL matches mixed-case skip prefix' );
		$this->assertSame( 'news', $matcher->match( '/news' )->id, 'lc URL matches mixed-case exact' );
		$this->assertSame( 'news', $matcher->match( '/NEWS' )->id, 'uc URL matches mixed-case exact' );
		$this->assertSame( 'root', $matcher->match( '/About' )->id, 'no case-specific rule falls through to /' );
	}

}
