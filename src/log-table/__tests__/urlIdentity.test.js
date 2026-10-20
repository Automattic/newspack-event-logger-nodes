/**
 * urlHash — the deep-link hash a URL row is stored under, held to PHP's
 * `Url_Parts::identity()` by the one case list both suites read.
 */

import { readFileSync } from 'fs';
import { join } from 'path';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { urlHash } from '../urlIdentity';

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
