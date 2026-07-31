/**
 * Guard: Event Logger consumes the canonical Nodes token API instead of
 * carrying a second token/base appearance layer.
 */

import fs from 'fs';
import path from 'path';

const SRC = path.join( __dirname, '..' );
const read = ( rel ) => fs.readFileSync( path.join( SRC, rel ), 'utf8' );

describe( 'dashboard skin tokens', () => {
	it( 'forwards the shared token and mixin APIs from both bases', () => {
		for ( const file of [
			'styles/base.scss',
			'settings/styles/base.scss',
		] ) {
			const source = read( file );
			expect( source ).toContain(
				'@forward "@newspack-nodes/shared/styles/tokens";'
			);
			expect( source ).toContain(
				'@forward "@newspack-nodes/shared/styles/mixins";'
			);
		}
	} );

	it( 'has no Event Logger token partial', () => {
		expect( fs.existsSync( path.join( SRC, 'styles/_tokens.scss' ) ) ).toBe(
			false
		);
		expect(
			fs.existsSync( path.join( SRC, 'settings/styles/_tokens.scss' ) )
		).toBe( false );
	} );

	it( 'keeps reskin rules off the bare theme class', () => {
		expect( read( 'components/ThemedRoot.scss' ) ).not.toMatch(
			/^\s*\.newspack-nodes-theme\s*\{/m
		);
	} );
} );
