<?php
/**
 * Url Parts
 *
 * The server and the path a logged URL splits into, and the path a stats row
 * stores.
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
	 * The server a URL was logged by: its host, which the producer writes
	 * from the server name it serves under.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function server_of( string $url ): string {
		return \substr( $url, ...self::authority( $url ) );
	}

	/**
	 * The PATH of a URL: what a search matches, with no scheme or host.
	 *
	 * The server is the picker's question, so a term matching the host would
	 * make one box ask the dropdown's. The authority ends at whichever
	 * delimiter comes first, so an authority with no path keeps its query on
	 * the path.
	 *
	 * @param string $url A logged URL, which carries its host.
	 * @throws \InvalidArgumentException When the URL has no host.
	 */
	public static function path_of( string $url ): string {
		[ $offset, $length ] = self::authority( $url );
		return \substr( $url, $offset + $length );
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
	 * The path a row stores for a URL served by `$server`: what the key does
	 * not already say. An https URL whose authority is the server keeps only
	 * its path, which a reader joins back as `https://{server}{path}`; any
	 * other URL, an http one, is kept whole, so no reader shows a scheme it
	 * was not. Cut to `MAX_PATH_BYTES`, the last of them an ellipsis.
	 *
	 * @param string $url    The stored URL.
	 * @param string $server The server whose key the row is filed under.
	 */
	public static function row_path( string $url, string $server ): string {
		$origin = 'https://' . $server;
		$path   = \str_starts_with( $url, $origin . '/' ) ? \substr( $url, \strlen( $origin ) ) : $url;
		if ( \strlen( $path ) <= self::MAX_PATH_BYTES ) {
			return $path;
		}
		return \mb_strcut( $path, 0, self::MAX_PATH_BYTES - \strlen( '…' ), 'UTF-8' ) . '…';
	}
}
