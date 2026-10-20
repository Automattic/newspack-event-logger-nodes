<?php
/**
 * Url Parts
 *
 * The server a logged URL names, the key its row is filed by, the path a
 * stats row stores, the path a rule matches it by, and the page it belongs
 * to.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

/**
 * Every logged URL carries its host, so a hostless one is refused rather than
 * read as a bare path.
 */
final class Url_Parts {

	/**
	 * Longest `row_path()` kept, in bytes, `…` included. The hash is the
	 * identity and the path is display, so a query-string flood cannot grow
	 * one row past it.
	 */
	public const MAX_PATH_BYTES = 1024;

	/**
	 * The query keys whose value routes a request rather than naming what it
	 * asked for, so `key()` keeps it: a REST route, an admin-ajax action, a
	 * WooCommerce ajax endpoint and a feed each get a row of their own.
	 *
	 * @var array<string,true>
	 */
	private const KEY_ROUTES = [
		'rest_route' => true,
		'action'     => true,
		'wc-ajax'    => true,
		'feed'       => true,
	];

	/**
	 * The query keys `key()` drops, beside every `utm_` key: each names the
	 * link a visitor followed, never what was served, so a page keeps one row
	 * however it was reached.
	 *
	 * @var array<string,true>
	 */
	private const KEY_TRACKING = [
		'fbclid'    => true,
		'gclid'     => true,
		'gbraid'    => true,
		'wbraid'    => true,
		'dclid'     => true,
		'msclkid'   => true,
		'twclid'    => true,
		'ttclid'    => true,
		'li_fat_id' => true,
		'igshid'    => true,
		'yclid'     => true,
		'mc_cid'    => true,
		'mc_eid'    => true,
		'_ga'       => true,
		'_gl'       => true,
		'_hsenc'    => true,
		'_hsmi'     => true,
		'mkt_tok'   => true,
	];

	/**
	 * The server a URL was logged by: its host, which the producer writes
	 * from the server name it serves under, as `server_name()` spells it.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function server_of( string $url ): string {
		return self::server_name( \substr( $url, ...self::authority( $url ) ) );
	}

	/**
	 * A server's name as the rows store it and every reader matches it: the
	 * host lowercased, so its spellings are one server.
	 *
	 * @param string $host A host, in any case.
	 */
	public static function server_name( string $host ): string {
		return \strtolower( $host );
	}

	/**
	 * The key a request's URL is stored by, the record's `url`: its path, and
	 * each key of its query as `name=?`, sorted without regard to case and
	 * deduped, so every request asking the same keys, whatever their values,
	 * order or spelling, has one key, and `identity()` of it picks the row.
	 * A key is the one PHP reads into `$_GET`, the last sent
	 * winning, so `ids[0]` and `ids[1]` are `ids` and `a.b` is `a_b`; it is
	 * written encoded, so no key can spell a separator. A `KEY_ROUTES` key
	 * keeps its value through `route_value()`. Tracking keys are dropped, a
	 * trimmed line's last parameter, which the trim may have cut, is
	 * dropped, and a query left with no key leaves the path alone.
	 *
	 * @param string $request_url The request's absolute URL, query and all.
	 * @param bool   $truncated   Whether the line was trimmed, cutting its last parameter.
	 */
	public static function key( string $request_url, bool $truncated ): string {
		[ $path, $query ] = \explode( '?', $request_url, 2 ) + [ 1 => '' ];
		if ( '' === $query ) {
			return $path;
		}
		$parameters = \explode( '&', $query );
		if ( $truncated ) {
			\array_pop( $parameters );
		}

		$keys = [];
		foreach ( $parameters as $parameter ) {
			[ $name, $value ] = \explode( '=', $parameter, 2 ) + [ 1 => '' ];
			\parse_str( $name . '=', $read_as );
			$key = (string) \array_key_first( $read_as );
			if ( '' === $key || isset( self::KEY_TRACKING[ $key ] ) || \str_starts_with( $key, 'utm_' ) ) {
				continue;
			}
			$keys[ $key ] = \rawurlencode( $key ) . '=' . ( isset( self::KEY_ROUTES[ $key ] ) ? self::route_value( $value ) : '?' );
		}
		if ( [] === $keys ) {
			return $path;
		}
		\ksort( $keys, \SORT_STRING | \SORT_FLAG_CASE );
		return $path . '?' . \implode( '&', $keys );
	}

	/**
	 * A routing key's value as `key()` keeps it: decoded, each all-digit
	 * segment masked, each segment encoded again, so `%2Fwp%2Fv2%2Fposts%2F12`
	 * and `/wp/v2/posts/7` both read `/wp/v2/posts/?` and no value can add a
	 * parameter.
	 *
	 * @param string $value The value as sent.
	 */
	private static function route_value( string $value ): string {
		return \implode(
			'/',
			\array_map(
				static fn( string $segment ): string => \ctype_digit( $segment ) ? '?' : \rawurlencode( $segment ),
				\explode( '/', \urldecode( $value ) )
			)
		);
	}

	/**
	 * The site path a rule matches a stored URL by: its path alone, with no
	 * scheme or host. A rule matches the URI a request sent, and the stored
	 * query is not that: `key()` masked its values, and the builder may have
	 * appended a worker mark. The authority ends at whichever delimiter comes
	 * first, so a host never swallows a query.
	 *
	 * @param string $url A stored URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function rule_path( string $url ): string {
		return self::path_from( $url, \array_sum( self::authority( $url ) ) );
	}

	/**
	 * The page a stored URL belongs to: its host and path, lowercased, with
	 * the query and any worker mark dropped, so every case, query shape and
	 * scheme of one address shares a page.
	 *
	 * @param string $url A stored URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function page( string $url ): string {
		[ $start ] = self::authority( $url );
		return \strtolower( self::path_from( $url, $start ) );
	}

	/**
	 * The URL from `$start` to its first `?`, or its end.
	 *
	 * @param string $url   A stored URL.
	 * @param int    $start Where to start reading.
	 */
	private static function path_from( string $url, int $start ): string {
		return \substr( $url, $start, \strcspn( $url, '?', $start ) );
	}

	/**
	 * Where a URL's host lies, `[ offset, length ]`: after a `://` that
	 * comes before any `/`, `?` or `#`, and up to the first of them.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException When the URL has no host: no `://`
	 *                                   ahead of its path, or an empty host.
	 */
	private static function authority( string $url ): array {
		$at = \strpos( $url, '://' );
		if ( false !== $at && $at <= \strcspn( $url, '/?#' ) ) {
			$length = \strcspn( $url, '/?#', $at + 3 );
			if ( 0 < $length ) {
				return [ $at + 3, $length ];
			}
		}
		throw new \InvalidArgumentException( "Url_Parts: URL has no host: '{$url}'" );
	}

	/**
	 * The hash a URL row is stored under: `Log_Manager::url_hash()` of the
	 * stored URL's `identity()`. JS `urlHash()` is its twin.
	 *
	 * @param string $url A stored URL.
	 */
	public static function hash( string $url ): string {
		return Log_Manager::url_hash( self::identity( $url ) );
	}

	/**
	 * What a URL row is hashed by: a stored URL, a `key()` with any worker
	 * mark, lowercased (ASCII only), its `http://` read as `https://`, so its
	 * case and scheme variants share a row. JS `urlIdentity()` is its twin.
	 *
	 * @param string $url A stored URL.
	 */
	public static function identity( string $url ): string {
		$url = \strtolower( $url );
		return \str_starts_with( $url, 'http://' ) ? 'https://' . \substr( $url, \strlen( 'http://' ) ) : $url;
	}

	/**
	 * The path a row stores for a URL served by `$server`: what the key does
	 * not already say. An https URL whose authority is the server, in any
	 * case, keeps only its path, which a reader joins back as `https://{server}{path}`; any
	 * other URL, an http one, is kept whole, so no reader shows a scheme it
	 * was not. Cut to `MAX_PATH_BYTES`, the last of them an ellipsis.
	 *
	 * @param string $url    The stored URL.
	 * @param string $server The server whose key the row is filed under.
	 */
	public static function row_path( string $url, string $server ): string {
		$origin = 'https://' . $server;
		$path   = 0 === \strncasecmp( $url, $origin . '/', \strlen( $origin ) + 1 ) ? \substr( $url, \strlen( $origin ) ) : $url;
		if ( \strlen( $path ) <= self::MAX_PATH_BYTES ) {
			return $path;
		}
		return \mb_strcut( $path, 0, self::MAX_PATH_BYTES - \strlen( '…' ), 'UTF-8' ) . '…';
	}

	/**
	 * The URL a `row_path()` was cut from, given the server it was cut
	 * against: the inverse `row_path()` answers to, and the one place the
	 * join is spelled. A whole URL, or no path, comes back as it is, with
	 * or without a server; a server-relative path with none is refused
	 * rather than joined into `https:///…`.
	 *
	 * @param string $server The server the path was cut against; '' where the
	 *                       path is already a whole URL, as on a site list.
	 * @param string $path   The stored path.
	 * @throws \InvalidArgumentException When a server-relative path has no server.
	 */
	public static function join_url( string $server, string $path ): string {
		if ( ! \str_starts_with( $path, '/' ) ) {
			return $path;
		}
		if ( '' === $server ) {
			throw new \InvalidArgumentException( "Url_Parts: no server to join '{$path}' to" );
		}
		return "https://{$server}{$path}";
	}
}
