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

describe( 'overview skin tokens', () => {
	// Strip line comments so the no-fallback / no-lightening assertions check the
	// actual declarations, not the header prose that describes them.
	const tokens = read( 'overview/styles/_tokens.scss' ).replace(
		/\/\/.*$/gm,
		''
	);

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
		expect( scss ).toMatch( /background:\s*var\(--paper\)\s*;/ );
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

	it( 'gives dark toolbar buttons a hover distinct from their base (the dark-button mixin reads both)', () => {
		const tertiary = tokens
			.match( /\$dark-bg-tertiary:\s*([^;]+);/ )[ 1 ]
			.trim();
		const hover = tokens.match( /\$dark-bg-hover:\s*([^;]+);/ )[ 1 ].trim();
		// dark-button uses $dark-bg-tertiary as the resting bg, $dark-bg-hover on
		// :hover — collapse them and the button loses all hover feedback.
		expect( hover ).not.toBe( tertiary );
	} );

	it( 'keeps the reskin rules off the bare .newspack-nodes-theme (the overlay console carries it too)', () => {
		// The component + form-control block must be scoped to the dashboard root
		// and the detail modal, NOT the bare class the substrate debug-overlay
		// console also mounts under — else our light input bg paints its REPL /
		// settings-panel fields (whose own class sits on an ancestor, not the input).
		const scss = read( 'components/ThemedRoot.scss' );
		expect( scss ).not.toMatch( /^\s*\.newspack-nodes-theme\s*\{/m );
	} );

	it( 'settings/_tokens.scss keeps its --np-* fallback chain (settings is NOT reskinned)', () => {
		// Unlike overview/gyroscope/requests, the settings page is deliberately
		// Newspack-fixed (no ThemedRoot), so its tokens MUST keep the --np-* fallback.
		const settings = read( 'settings/styles/_tokens.scss' );
		expect( settings ).toMatch( /var\(\s*--ink\s*,\s*var\(\s*--np-/ );
	} );
} );
