/**
 * What a URL row is hashed by, the JS twin of PHP `Url_Parts::identity()`:
 * the stored URL, its worker mark included, lowercased in ASCII alone as
 * PHP's `strtolower()` folds, its `http://` read as `https://`. Both suites
 * read `tests/fixtures/url-identities.json`.
 */

import fnv1a from '@newspack-nodes/shared/utils/fnv1a';

/**
 * A stored URL as its row's identity.
 *
 * @param {string} url A stored URL: a `Url_Parts::key()` with any worker mark.
 * @return {string} The identity a row's hash is taken of.
 */
function urlIdentity( url ) {
	const folded = asciiLower( url );
	return folded.startsWith( 'http://' )
		? `https://${ folded.slice( 'http://'.length ) }`
		: folded;
}

/**
 * The hash a URL row is stored under, PHP's
 * `Log_Manager::url_hash( Url_Parts::identity( $url ) )`, which the Overview
 * deep-links by.
 *
 * @param {?string} url A stored URL, or nothing.
 * @return {string} Its 12-character hash.
 */
export function urlHash( url ) {
	return fnv1a( urlIdentity( url || '' ) );
}

/** A URL's scheme and host, as PHP `Url_Parts::authority()` bounds them. */
const ORIGIN = /^[a-z][a-z\d+.-]*:\/\/[^/?#]*/i;

/** A moved path's escapes read back, PHP `Url_Parts::MOVED_PATH_ESCAPES` flipped. */
const MOVED_PATH_ESCAPES = {
	'%2525': '%25',
	'%2526': '%26',
	'%253D': '%3D',
	'%253d': '%3d',
	'%26': '&',
	'%3D': '=',
};

/**
 * The site path a rule matches a stored URL by, the JS twin of PHP
 * `Url_Parts::rule_path()`: its path alone, and for a grouped key, whose
 * query opens with the path the request asked for, that path read back.
 * Both suites read `tests/fixtures/rule-paths.json`.
 *
 * @param {?string} url A stored URL, or nothing.
 * @return {string} The path a rule matches it by.
 */
export function rulePath( url ) {
	const local = ( url || '' ).replace( ORIGIN, '' );
	const at = local.indexOf( '?' );
	const path = at < 0 ? local : local.slice( 0, at );
	const query = at < 0 ? '' : local.slice( at + 1 );
	if ( ! query.startsWith( '/' ) ) {
		return path;
	}
	const moved = query
		.split( '&' )[ 0 ]
		.replace(
			/%2525|%2526|%253D|%253d|%26|%3D/g,
			( escape ) => MOVED_PATH_ESCAPES[ escape ]
		);
	return path + ( path.endsWith( '/' ) ? moved.slice( 1 ) : moved );
}

/**
 * A stored URL as the request asked for it, for display: a grouped key's
 * moved path put back in its path, ahead of the rest of its query. Any
 * other URL reads as it is stored. The stored URL stays the row's identity.
 *
 * @param {?string} url A stored URL, or nothing.
 * @return {string} The URL to show.
 */
export function requestedUrl( url ) {
	const stored = url || '';
	const origin = stored.match( ORIGIN )?.[ 0 ] ?? '';
	const at = stored.indexOf( '?', origin.length );
	const query = at < 0 ? '' : stored.slice( at + 1 );
	if ( ! query.startsWith( '/' ) ) {
		return stored;
	}
	const keys = query.split( '&' ).slice( 1 );
	return `${ origin }${ rulePath( stored ) }${
		keys.length ? `?${ keys.join( '&' ) }` : ''
	}`;
}

/**
 * A server's name as the rows store it, the JS twin of PHP
 * `Url_Parts::server_name()`: its ASCII capitals folded, as PHP's
 * `strtolower()` folds them, so a server linked in any case is one server.
 *
 * @param {string} name A host.
 * @return {string} The name, lowercased.
 */
export function serverName( name ) {
	return asciiLower( name );
}

/**
 * Text with its ASCII capitals folded and every other character kept, as
 * PHP's `strtolower()` folds.
 *
 * @param {string} text Any text.
 * @return {string} The text, folded.
 */
function asciiLower( text ) {
	return text.replace( /[A-Z]+/g, ( run ) => run.toLowerCase() );
}
