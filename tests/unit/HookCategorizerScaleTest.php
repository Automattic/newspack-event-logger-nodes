<?php
/**
 * HookCategorizerScaleTest: `Hook_Categorizer::categorize()` answers every hook
 * exactly as a per-pattern walk of the merged config does — first override,
 * then the first pattern in config order that matches anywhere in the name,
 * skipping a pattern that is too long, carries a nested quantifier or does not
 * compile — over a seeded 10,000-name list and over operator patterns that use
 * groups, alternation, backreferences and quoting.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Hook_Categorizer::class )]
class HookCategorizerScaleTest extends TestCase {

	/** Names in the seeded list. */
	private const SEEDED = 10000;

	/** Slices the list is checked in, so each case stays inside the time budget. */
	private const SLICES = 10;

	protected function setUp(): void {
		parent::setUp();
		Hook_Categorizer::clear_cache();
		$GLOBALS['_wp_options'] = [];
	}

	protected function tearDown(): void {
		Hook_Categorizer::clear_cache();
		$GLOBALS['_wp_options'] = [];
		parent::tearDown();
	}

	/**
	 * A deterministic list of hook names shaped like a fleet's: names built
	 * from the shipped patterns' literal text, the same with a prefix in front
	 * of them, and names of random words.
	 *
	 * @return list<string>
	 */
	public static function seeded_names(): array {
		$base     = Hook_Categorizer::get_base_config();
		$literals = [];
		foreach ( $base['_patterns'] as $patterns ) {
			foreach ( $patterns as $pattern ) {
				$literals[] = \str_replace( [ '^', '$', '\\d', '\\', '.*', '+', '*' ], [ '', '', '7', '', '', '', '' ], $pattern );
			}
		}
		$words = [ 'kea', 'okapi', 'zeta', 'save', 'post', 'render', 'block', 'cart', 'feed', 'meta', 'query', 'admin', 'rest', 'init', 'loaded', 'wc', 'amp', 'user' ];
		\mt_srand( 4417 );
		$names = [];
		while ( \count( $names ) < self::SEEDED ) {
			$word = $words[ \mt_rand( 0, \count( $words ) - 1 ) ];
			$name = match ( \mt_rand( 0, 3 ) ) {
				0, 1    => $literals[ \mt_rand( 0, \count( $literals ) - 1 ) ] . $word,
				2       => $word . '_' . $literals[ \mt_rand( 0, \count( $literals ) - 1 ) ],
				default => $word . '_' . $words[ \mt_rand( 0, \count( $words ) - 1 ) ] . '_' . \mt_rand( 0, 999 ),
			};
			$names[ $name ] = true;
		}
		return \array_map( 'strval', \array_keys( $names ) );
	}

	/** @return array<string,array{int}> */
	public static function slices(): array {
		$out = [];
		for ( $i = 0; $i < self::SLICES; $i++ ) {
			$out[ "slice {$i}" ] = [ $i ];
		}
		return $out;
	}

	#[DataProvider( 'slices' )]
	public function test_the_shipped_patterns_categorize_a_seeded_fleet_as_the_walk_does( int $slice ): void {
		$names = \array_slice( self::seeded_names(), $slice * ( self::SEEDED / self::SLICES ), self::SEEDED / self::SLICES );

		$this->assert_same_as_the_walk( $names );
	}

	public function test_operator_patterns_categorize_as_the_walk_does(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'patterns'  => [
				'Admin'      => [ 'zeta_(kea|okapi)$' ],
				'Kea Group'  => [ '^save_post|render', '(kea)_\\1', '\\Qcart.\\E', '[invalid(', \str_repeat( 'a', 101 ), '(a+)+', '(?i)^OKAPI_', '(?<=_)feed_', '(*MARK:1)kea_', '(*:2)zeta_mark_', '(*PRUNE:3)okapi_prune_' ],
				'Okapi Tail' => [ 'meta$', '^query_.*_\\d+$', "\x01" ],
				'1234'       => [ '^wc_' ],
				'Slow Okapi' => [ 'a*a*a*a*a*a*a*a*a*c' ],
				'After Slow' => [ 'aab$' ],
			],
			'overrides' => [ 'wp_ajax_zeta' => 'Pinned', 'admin_init' => 'Pinned' ],
		] );
		Hook_Categorizer::clear_cache();
		$names = \array_merge(
			[ 'wp_ajax_zeta', 'admin_init', 'kea_kea', 'okapi_kea', 'xx_cart.zz', 'cartXzz', 'okapi_thing', 'zeta_feed_x', 'query_a_12', 'zeta_kea', 'wc_cart', 'completely_unknown_hook', 'save_post_kea', '', 'kea_zz', 'xx_zeta_mark_yy', 'okapi_prune_zz', \str_repeat( 'a', 40 ) . 'b' ],
			\array_slice( self::seeded_names(), 0, 1000 )
		);

		$this->assert_same_as_the_walk( $names );
	}

	public function test_a_pattern_set_too_large_for_one_regex_categorizes_as_the_walk_does(): void {
		$patterns = [];
		for ( $i = 0; $i < 3000; $i++ ) {
			$patterns[] = 'okapi_' . \str_repeat( 'k', 80 ) . "_{$i}$";
		}
		\update_option( Hook_Categorizer::OPTION_NAME, [ 'patterns' => [ 'Many Okapi' => $patterns, 'Kea Tail' => [ '_kea$' ] ] ] );
		Hook_Categorizer::clear_cache();
		$long = 'okapi_' . \str_repeat( 'k', 80 );

		$this->assert_same_as_the_walk( [ "{$long}_0", "{$long}_1499", "{$long}_2999", "{$long}_3000", 'zeta_kea', 'wp_ajax_kea' ] );
	}

	/**
	 * Each name categorizes as the per-pattern walk over the merged config.
	 *
	 * @param list<string> $names Hook names.
	 */
	private function assert_same_as_the_walk( array $names ): void {
		$config = Hook_Categorizer::get_merged_config();
		$walk   = self::walk_patterns( $config['patterns'] );
		foreach ( $names as $name ) {
			$expected = isset( $config['overrides'][ $name ] ) && \is_string( $config['overrides'][ $name ] )
				? $config['overrides'][ $name ]
				: self::walk( $walk, $name );
			$this->assertSame( (string) $expected, Hook_Categorizer::categorize( $name ), $name );
		}
	}

	/**
	 * The patterns a per-pattern walk would try, in config order, each with
	 * the category it answers: the same three refusals `categorize()` makes.
	 *
	 * @param array<array-key,mixed> $patterns Merged category => patterns.
	 * @return list<array{string,string}> Delimited regex, category.
	 */
	private static function walk_patterns( array $patterns ): array {
		$walk = [];
		foreach ( $patterns as $category => $list ) {
			if ( ! \is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $pattern ) {
				if ( ! \is_string( $pattern ) || \strlen( $pattern ) > Hook_Categorizer::MAX_PATTERN_LENGTH ) {
					continue;
				}
				if ( \preg_match( '/[+*?]\}?\)?[+*?{]/', $pattern ) ) {
					continue;
				}
				$regex = "\x01" . $pattern . "\x01";
				if ( false === @\preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					continue;
				}
				$walk[] = [ $regex, (string) $category ];
			}
		}
		return $walk;
	}

	/**
	 * The first walked pattern matching the name, under the scan's backtrack cap.
	 *
	 * @param list<array{string,string}> $walk Delimited regex, category.
	 * @param string                     $name Hook name.
	 */
	private static function walk( array $walk, string $name ): string {
		$prev = \ini_get( 'pcre.backtrack_limit' );
		\ini_set( 'pcre.backtrack_limit', 10000 ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			foreach ( $walk as [ $regex, $category ] ) {
				if ( \preg_match( $regex, $name ) ) {
					return $category;
				}
			}
		} finally {
			\ini_set( 'pcre.backtrack_limit', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		return 'Other';
	}
}
