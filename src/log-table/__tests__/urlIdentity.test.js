/**
 * urlHash — the deep-link hash a URL row is stored under, held to PHP's
 * `Url_Parts::identity()` by the one case list both suites read.
 */

import { readFileSync } from 'fs';
import { join } from 'path';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { requestedUrl, rulePath, serverName, urlHash } from '../urlIdentity';

const cases = JSON.parse(
	readFileSync(
		join( __dirname, '../../../tests/fixtures/url-identities.json' ),
		'utf8'
	)
);

test.each( cases )( '%s', ( _label, url, identity ) => {
	expect( urlHash( url ) ).toBe( fnv1a( identity ) );
} );

const rulePaths = JSON.parse(
	readFileSync(
		join( __dirname, '../../../tests/fixtures/rule-paths.json' ),
		'utf8'
	)
);

test.each( rulePaths )( 'rule path: %s', ( _label, url, path ) => {
	expect( rulePath( url ) ).toBe( path );
} );

test( 'a grouped key reads as the URL the request asked for', () => {
	expect(
		requestedUrl( 'https://kea.test/author/sociales?/page/2/&moa=?' )
	).toBe( 'https://kea.test/author/sociales/page/2/?moa=?' );
	expect(
		requestedUrl( 'https://kea.test/author/sociales?/page/2/&cron.p0' )
	).toBe( 'https://kea.test/author/sociales/page/2/?cron.p0' );
	expect( requestedUrl( 'https://kea.test/author/sociales?/' ) ).toBe(
		'https://kea.test/author/sociales/'
	);
	expect(
		requestedUrl( 'https://kea.test/author/sociales?/x%2526y%26z' )
	).toBe( 'https://kea.test/author/sociales/x%26y&z' );
} );

test( 'any other URL reads as it is stored', () => {
	for ( const url of [
		'https://kea.test/moa?v=?&rest_route=/wp/v2',
		'https://kea.test/author/sociales',
		'https://kea.test/jobs/kea?job-worker.p0',
	] ) {
		expect( requestedUrl( url ) ).toBe( url );
	}
	expect( requestedUrl( undefined ) ).toBe( '' );
} );

test( 'an absent URL hashes as the empty string', () => {
	expect( urlHash( undefined ) ).toBe( fnv1a( '' ) );
} );

test( 'a server name folds its ASCII capitals, as PHP Url_Parts::server_name()', () => {
	expect( serverName( 'Example-9907.COM' ) ).toBe( 'example-9907.com' );
	expect( serverName( '\u00c4rger-9907.example' ) ).toBe(
		'\u00c4rger-9907.example'
	);
} );
