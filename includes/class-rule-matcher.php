<?php
/**
 * Longest-prefix rule matcher.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

/**
 * Built once per request from the autoloaded rule list. Matching is
 * order-independent (specificity governs) and cached per normalized URL.
 */
final class Rule_Matcher {

	/** @var Rule[] Sorted most-specific first. */
	private array $rules;

	/** @var array<string, Rule|null> Normalized-URL => match cache. */
	private array $cache = [];

	/**
	 * @param Rule[] $rules Unsorted rule list.
	 */
	public function __construct( array $rules ) {
		\usort(
			$rules,
			static function ( Rule $a, Rule $b ): int {
				if ( $a->is_exact() !== $b->is_exact() ) {
					return $a->is_exact() ? -1 : 1;
				}
				return \strlen( $b->pattern ) <=> \strlen( $a->pattern );
			}
		);
		$this->rules = $rules;
	}

	/**
	 * The governing rule for a URL, or null when nothing matches (⇒ skip).
	 * Matching is case-insensitive (target + patterns are compared lowercased).
	 */
	public function match( string $url ): ?Rule {
		$target = self::normalize( $url );
		if ( \array_key_exists( $target, $this->cache ) ) {
			return $this->cache[ $target ];
		}
		$hit = null;
		foreach ( $this->rules as $rule ) {
			$pattern = \strtolower( $rule->pattern );
			if ( $rule->is_exact() ) {
				if ( $target === $pattern ) {
					$hit = $rule;
					break;
				}
				continue;
			}
			if ( \str_starts_with( $target, $pattern ) ) {
				$hit = $rule;
				break;
			}
		}
		$this->cache[ $target ] = $hit;
		return $hit;
	}

	/**
	 * Normalize exactly as Log_Manager historically did: drop the query string,
	 * re-append a single '?' terminator so an exact 'pattern?' matches the path.
	 * Lowercased — the legacy compile_url_filter regex matched case-insensitively.
	 */
	public static function normalize( string $url ): string {
		return \strtolower( \explode( '?', $url, 2 )[0] ) . '?';
	}
}
