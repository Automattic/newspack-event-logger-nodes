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
	// Strip line comments so the no-fallback / no-lightening assertions check the
	// actual declarations, not the header prose that describes them. Tokens were
	// lifted to the shared src/styles/_tokens.scss (one copy for every dashboard).
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

	it( 'excludes the inline debug-overlay (.nodes-debug) from the form-control reskin', () => {
		// DebugOverlay renders INLINE inside ThemedRoot (no portal), so the substrate
		// console REPL input (type="text", background:transparent) is a DESCENDANT of
		// `.topology-app > .newspack-nodes-theme`. Block scoping alone does NOT spare
		// it — the descendant reskin (`… input[type="text"]`, specificity 0,3,1) beats
		// the REPL's transparent bg (0,2,0) and paints it light --paper-3 → white-on-
		// white under light skins. Every reskinned form control must opt the overlay
		// subtree out so the overlay owns its own input styling.
		const scss = read( 'components/ThemedRoot.scss' );
		for ( const sel of [
			'input\\[type="text"\\]',
			'input\\[type="search"\\]',
			'input\\[type="number"\\]',
			'textarea',
		] ) {
			expect( scss ).toMatch(
				new RegExp( sel + ':not\\(\\s*\\.nodes-debug' )
			);
		}
	} );

	it( 'excludes the overlay from the button color:inherit reskin (else the dark primary modal button text goes invisible)', () => {
		// `button { color: inherit }` (0,2,1) out-specifies the substrate's
		// `.topology-modal__btn--primary { color: var(--paper) }` (0,1,0) and forces
		// the primary modal button's light text to --ink — dark-on-dark on its --ink
		// fill. The button reskin must opt the overlay subtree out too.
		const scss = read( 'components/ThemedRoot.scss' );
		expect( scss ).toMatch(
			/button:not\(\s*\.nodes-debug \*\s*\)\s*\{\s*color:\s*inherit/
		);
	} );

	it( 'settings/_tokens.scss keeps its --np-* fallback chain (settings is NOT reskinned)', () => {
		// Unlike overview/gyroscope/requests, the settings page is deliberately
		// Newspack-fixed (no ThemedRoot), so its tokens MUST keep the --np-* fallback.
		const settings = read( 'settings/styles/_tokens.scss' );
		expect( settings ).toMatch( /var\(\s*--ink\s*,\s*var\(\s*--np-/ );
	} );
} );
