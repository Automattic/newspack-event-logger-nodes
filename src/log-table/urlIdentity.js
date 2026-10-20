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
	const folded = url.replace( /[A-Z]+/g, ( run ) => run.toLowerCase() );
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
