/**
 * Guard: the overview dashboard is built on PURE skin tokens. ThemedRoot always
 * wraps it in the `.topology-app.theme-<slug>` provider, so the universal
 * tokens (--paper/--ink/--cyan/--chart-*) are always defined — the dashboard
 * reads them directly. No --np-* fallback, no color-mix lightening, no
 * light/dark/terminal surface split: ONE skinned surface that reskins with
 * every skin (terminal-mono under decorative skins, Newspack-light by default).
 */

import fs from 'fs';
import path from 'path';

const read = ( rel ) =>
	fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );

describe( 'dashboard skin tokens', () => {
	// Strip line comments so assertions check declarations, not prose.
	const tokens = read( 'styles/_tokens.scss' ).replace( /\/\/.*$/gm, '' );

	it( 'maps the surface/ink/accent primitives straight to bare skin tokens', () => {
		expect( tokens ).toMatch( /\$surface:\s*var\(--paper\)\s*;/ );
		expect( tokens ).toMatch( /\$ink:\s*var\(--ink\)\s*;/ );
		expect( tokens ).toMatch( /\$accent:\s*var\(--cyan\)\s*;/ );
	} );

	it( 'carries no --np-* fallback — ThemedRoot guarantees the skin context', () => {
		expect( tokens ).not.toMatch( /var\(\s*--np-/ );
	} );

	it( 'carries no color-mix lightening — status colors are pure skin tokens', () => {
		expect( tokens ).not.toContain( 'color-mix' );
	} );

	it( 'ThemedRoot paints the bare skin surface behind the dashboard', () => {
		const scss = read( 'components/ThemedRoot.scss' );
		expect( scss ).toMatch( /\.topology-app\s*>\s*\.newspack-nodes-theme/ );
		expect( scss ).toMatch( /background:\s*var\(--paper-3\)\s*;/ );
		expect( scss ).not.toMatch( /var\(\s*--np-/ );
	} );

	it( 'RequestProfile co-locates its widefat-theming stylesheet', () => {
		expect( read( 'overview/RequestProfile.js' ) ).toContain(
			"import './styles/request-profile.scss';"
		);
		expect( read( 'overview/styles/request-profile.scss' ) ).toContain(
			'@include base.widefat-themed;'
		);
	} );

	it( 'keeps the reskin rules off the bare .newspack-nodes-theme (the overlay console carries it too)', () => {
		// Scope to the dashboard root, not the bare overlay-console class.
		const scss = read( 'components/ThemedRoot.scss' );
		expect( scss ).not.toMatch( /^\s*\.newspack-nodes-theme\s*\{/m );
	} );

	it( 'settings/_tokens.scss keeps its --np-* fallback chain (settings is NOT reskinned)', () => {
		// Settings is Newspack-fixed (no ThemedRoot), so tokens keep --np-*.
		const settings = read( 'settings/styles/_tokens.scss' );
		expect( settings ).toMatch( /var\(\s*--[\w-]+\s*,\s*var\(\s*--np-/ );
	} );
} );
