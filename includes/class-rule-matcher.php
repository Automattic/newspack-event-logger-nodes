<?php
/**
 * Most-specific-first rule matcher: query-bearing, then exact, then prefix.
 *
 * `Log_Manager` resolves exactly one governing `Rule` per request through this
 * class, and that rule's action decides whether the request is logged at all.
 * List order must therefore never sway the outcome — specificity alone does.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolves the one rule governing a URL out of a fixed rule list. Matching is
 * order-independent (specificity governs) and cached per normalized URL for
 * this object's lifetime, which `Log_Manager` makes one request long; a reader
 * outside a request builds a matcher per lookup and gets no reuse.
 * An empty rule set matches nothing, and nothing matched means skip: there is
 * no implicit log-all baseline.
 */
final class Rule_Matcher {

	/** @var array<string,Rule|null> Normalized-URL => match cache; a miss caches null. */
	private array $cache = [];

	/** @var Rule[] Sorted most-specific first. */
	private array $rules;

	/**
	 * Most-specific-first: query-bearing patterns (exact path + query prefix)
	 * outrank exact paths, which outrank prefixes; length breaks ties. Length
	 * only ever settles a tie WITHIN a rank, so this is not longest-prefix-wins.
	 * Sorting once here is what lets `match()` stop at its first hit.
	 *
	 * @param Rule[] $rules Unsorted rule list.
	 */
	public function __construct( array $rules ) {
		$rank = static function ( Rule $r ): int {
			if ( '' !== self::pattern_query( $r->pattern ) ) {
				return 0;
			}
			return $r->is_exact() ? 1 : 2;
		};
		\usort(
			$rules,
			static function ( Rule $a, Rule $b ) use ( $rank ): int {
				return $rank( $a ) <=> $rank( $b )
					?: \strlen( $b->pattern ) <=> \strlen( $a->pattern );
			}
		);
		$this->rules = $rules;
	}

	/**
	 * The governing rule for a URL. Matching is case-insensitive: the target and
	 * the patterns are compared lowercased. Three pattern forms:
	 *  - `/blog`            — path prefix (query ignored)
	 *  - `/about?`          — exact path (query ignored)
	 *  - `/jobs/x?job-work` — exact path AND query prefix (worker URLs)
	 *
	 * A prefix matches by string, not by path segment, so `/wp-cron` governs
	 * `/wp-cron.php` and `/shop` governs `/shopping`. A pattern's query is a
	 * raw string prefix too, so `/jobs/x?job-worker` governs
	 * `/jobs/x?job-worker&n=1` and not the same two parameters reversed.
	 *
	 * Patterns are written as site paths, so a caller holding an absolute URL
	 * passes its path, as `Performance_CI_Node::rule_for_url()` does. A scheme
	 * and host ahead of the path match no path-leading pattern.
	 *
	 * The rule list is already sorted most-specific-first, so the first pattern
	 * that matches governs. Results cache under the normalized `path?query`,
	 * misses included.
	 *
	 * @param string $url Request path, plus an optional query string.
	 * @return Rule|null The governing rule, or null when no pattern matches, which skips the request.
	 */
	public function match( string $url ): ?Rule {
		[ $t_path, $t_query ] = self::split( $url );
		$key                  = "{$t_path}?{$t_query}";
		if ( \array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}
		$hit = null;
		foreach ( $this->rules as $rule ) {
			[ $p_path, $p_query ] = self::split( $rule->pattern );
			if ( '' !== $p_query ) {
				if ( $t_path === $p_path && \str_starts_with( $t_query, $p_query ) ) {
					$hit = $rule;
					break;
				}
				continue;
			}
			if ( $rule->is_exact() ) {
				if ( $t_path === $p_path ) {
					$hit = $rule;
					break;
				}
				continue;
			}
			if ( \str_starts_with( $t_path, $p_path ) ) {
				$hit = $rule;
				break;
			}
		}
		$this->cache[ $key ] = $hit;
		return $hit;
	}

	/** A pattern's query part ('' for prefix/exact forms), lowercased. */
	private static function pattern_query( string $pattern ): string {
		return self::split( $pattern )[1];
	}

	/**
	 * Split a URL or pattern into lowercased [path, query] ('' = no query).
	 *
	 * A trailing `?` — the exact-path marker — therefore yields an empty query.
	 *
	 * @param string $url Request URI or rule pattern.
	 * @return array{0:string,1:string} Lowercased path, then query.
	 */
	private static function split( string $url ): array {
		$parts = \explode( '?', $url, 2 );
		return [ \strtolower( $parts[0] ), \strtolower( $parts[1] ?? '' ) ];
	}
}
