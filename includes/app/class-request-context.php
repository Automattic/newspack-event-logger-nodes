<?php
/**
 * Request context — the one entry naming what WordPress made of a logged web
 * request: the template it included, the rewrite rule and query it matched,
 * the main query's vars, and the conditionals the main query answers true.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Nodes\Core as RuntimeCore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures the final `template_include` and writes the `request_context`
 * entry at shutdown, once a request.
 *
 * Its `m` is a map of up to five keys, each present only when WordPress set
 * it: `template`, the included file relative to wp-content, else to the
 * install's root, else its basename, so no absolute path leaves the site;
 * `matched_rule` and `matched_query`, the second redacted as a URL's query is;
 * `query_vars`, the main query's vars less those WordPress left empty, shaped
 * as hook arguments are, so a value survives only under an allowlisted key;
 * and `conditionals`, the `is_*` flags the main query holds true.
 *
 * The entry fits `Log_Manager::MAX_DATA_SIZE` by its own hand, because
 * `message()` drops an array `m` whole: past `BUDGET` it drops query vars from
 * the end, then halves `matched_query`, `matched_rule` and `template` in that
 * order, then drops conditionals from the end, and marks the map `trimmed`. It is an entry of its own, so it never
 * competes with `process (complete)` for room.
 */
class Request_Context {

	/**
	 * Bytes the map's encoding may take: the cap less the room `n`, `k` and
	 * `ts` take around it, with margin.
	 */
	private const BUDGET = 3600;

	/** Characters a string field keeps before the fit runs. */
	private const FIELD_MAX = 512;

	/** The string fields the fit halves, most expendable first. */
	private const SACRIFICE = [ 'matched_query', 'matched_rule', 'template' ];

	/** @var string The final `template_include`, '' until it fires. */
	private string $template = '';

	/**
	 * `shutdown`: write the context into the web request's record. A job
	 * context's record is not the request whose globals these are, and a
	 * request WordPress routed nowhere — a CLI run, a worker loop — has
	 * nothing to say, so neither gets an entry.
	 */
	public function write(): void {
		$lm = Log_Manager::started_instance();
		if ( null === $lm || Log_Manager::in_job_context() ) {
			return;
		}
		$context = $this->context();
		if ( [] !== $context ) {
			$lm->message( Log_Manager::REQUEST_CONTEXT, [ 'm' => self::fit( $context ) ] );
		}
	}

	/**
	 * The map cut to `BUDGET`, query vars first, then the strings in
	 * `SACRIFICE` order, then the conditionals, marked `trimmed` when
	 * anything went.
	 *
	 * @param array<string,mixed> $context The map.
	 * @return array<string,mixed>
	 */
	private static function fit( array $context ): array {
		while ( self::size( $context ) > self::BUDGET ) {
			$context['trimmed'] = true;
			$vars               = RuntimeCore::arr( $context['query_vars'] ?? null, [] );
			if ( [] !== $vars ) {
				\array_pop( $vars );
				$context['query_vars'] = $vars;
				continue;
			}
			unset( $context['query_vars'] );
			foreach ( self::SACRIFICE as $field ) {
				$text = RuntimeCore::as_string( $context[ $field ] ?? '', '' );
				if ( '' !== $text ) {
					$context[ $field ] = \mb_substr( $text, 0, \intdiv( \mb_strlen( $text ), 2 ) );
					continue 2;
				}
			}
			$flags = RuntimeCore::arr( $context['conditionals'] ?? null, [] );
			\array_pop( $flags );
			$context['conditionals'] = $flags;
		}
		return $context;
	}

	/**
	 * The map's bytes as `Log_Manager::message()` encodes it.
	 *
	 * @param array<string,mixed> $context The map.
	 */
	private static function size( array $context ): int {
		return \strlen( (string) \wp_json_encode( $context, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR ) );
	}

	/**
	 * The map, each key only where WordPress set something.
	 *
	 * @return array<string,mixed>
	 */
	private function context(): array {
		$wp       = $GLOBALS['wp'] ?? null;
		$wp_query = $GLOBALS['wp_query'] ?? null;
		$query    = \is_object( $wp ) ? RuntimeCore::as_string( $wp->matched_query ?? '', '' ) : '';
		$context  = [
			'template'      => self::relative( $this->template ),
			'matched_rule'  => \is_object( $wp ) ? RuntimeCore::as_string( $wp->matched_rule ?? '', '' ) : '',
			'matched_query' => '' === $query ? '' : \substr( Log_Manager::redact_url( "?{$query}" ), 1 ),
		];
		$context = \array_map( static fn ( string $field ): string => \mb_substr( $field, 0, self::FIELD_MAX ), \array_filter( $context, static fn ( string $field ): bool => '' !== $field ) );
		if ( \is_object( $wp_query ) ) {
			$vars = \array_filter(
				RuntimeCore::arr( $wp_query->query_vars ?? null, [] ),
				static fn ( mixed $value ): bool => '' !== $value && null !== $value && [] !== $value
			);
			if ( [] !== $vars ) {
				$context['query_vars'] = Core::shaped_argument( $vars );
			}
			$true = \array_keys( \array_filter( \get_object_vars( $wp_query ), static fn ( mixed $flag, string $name ): bool => true === $flag && \str_starts_with( $name, 'is_' ), ARRAY_FILTER_USE_BOTH ) );
			if ( [] !== $true ) {
				$context['conditionals'] = $true;
			}
		}
		return $context;
	}

	/**
	 * A template file as the entry names it: relative to wp-content, else to
	 * the install's root, else its basename.
	 *
	 * @param string $path The included file.
	 */
	private static function relative( string $path ): string {
		foreach ( [ \trailingslashit( WP_CONTENT_DIR ), ABSPATH ] as $root ) {
			if ( '' !== $path && \str_starts_with( $path, $root ) ) {
				return \substr( $path, \strlen( $root ) );
			}
		}
		return \basename( $path );
	}

	/**
	 * What `App\Core` binds on a logged request: the template at the last
	 * priority, so it is the one WordPress includes, and the writer at
	 * shutdown's last, after a rule's `shutdown` span has closed.
	 *
	 * @return list<array{0: string, 1: callable, 2: int, 3: int}> Hook, callback, priority, accepted args.
	 */
	public function listeners(): array {
		return [
			[ 'template_include', [ $this, 'template' ], PHP_INT_MAX, 1 ],
			[ 'shutdown', [ $this, 'write' ], PHP_INT_MAX, 0 ],
		];
	}

	/**
	 * `template_include`: keep the template, and pass it through.
	 *
	 * @param mixed $template The file WordPress will include.
	 * @return mixed The same file.
	 */
	public function template( mixed $template ): mixed {
		$this->template = RuntimeCore::as_string( $template, '' );
		return $template;
	}
}
