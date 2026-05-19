/**
 * Tests for fnv1a — 12-hex-char FNV-1a hash used to route URLs to
 * partitions identically in JS and PHP.
 *
 * The helper runs two passes with different seeds and concatenates 8
 * hex chars from pass 1 with 4 hex chars from pass 2.
 */

import fnv1a from '../fnv1a';

describe( 'fnv1a', () => {
	it( 'returns a 12-character hex string', () => {
		const out = fnv1a( 'hello' );
		expect( out ).toHaveLength( 12 );
		expect( out ).toMatch( /^[0-9a-f]{12}$/ );
	} );

	it( 'is deterministic for the same input', () => {
		expect( fnv1a( '/wp-admin/edit.php' ) ).toBe(
			fnv1a( '/wp-admin/edit.php' )
		);
	} );

	it( 'produces different hashes for different inputs', () => {
		expect( fnv1a( 'a' ) ).not.toBe( fnv1a( 'b' ) );
	} );

	it( 'handles the empty string without crashing', () => {
		const out = fnv1a( '' );
		expect( out ).toHaveLength( 12 );
		expect( out ).toMatch( /^[0-9a-f]{12}$/ );
	} );

	it( 'is sensitive to character order', () => {
		expect( fnv1a( 'ab' ) ).not.toBe( fnv1a( 'ba' ) );
	} );

	it( 'is sensitive to case', () => {
		expect( fnv1a( 'Hello' ) ).not.toBe( fnv1a( 'hello' ) );
	} );

	it( 'handles long inputs', () => {
		const long = 'x'.repeat( 5000 );
		const out = fnv1a( long );
		expect( out ).toMatch( /^[0-9a-f]{12}$/ );
	} );
} );
