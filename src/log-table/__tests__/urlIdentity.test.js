/**
 * urlHash — the deep-link hash a URL row is stored under, held to PHP's
 * `Url_Parts::identity()` by the one case list both suites read.
 */

import { readFileSync } from 'fs';
import { join } from 'path';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { serverName, urlHash } from '../urlIdentity';

const cases = JSON.parse(
	readFileSync(
		join( __dirname, '../../../tests/fixtures/url-identities.json' ),
		'utf8'
	)
);

test.each( cases )( '%s', ( _label, url, identity ) => {
	expect( urlHash( url ) ).toBe( fnv1a( identity ) );
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
