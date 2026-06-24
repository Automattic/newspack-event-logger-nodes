/**
 * Regression guard: the performance-dashboard tables must reskin under the
 * hub's decorative themes (CRT etc.) while staying pixel-identical on the
 * STANDALONE Performance Dashboard, where the universal --paper/--ink/--cyan
 * tokens are undefined. The fix uses `var(--token, <light-fallback>)` so the
 * inherited token wins under the hub and the fallback preserves standalone.
 *
 * These are source/structure assertions (the rendered-style checks live in
 * RequestProfile.test.js): RequestProfile co-locates its own widefat-theming
 * stylesheet so the theming travels into every bundle (the hub's
 * current-request bundle imports only current-request.scss, which does not
 * theme the table), and LogEntriesTable's inline --cyan references carry a
 * Cobalt fallback so standalone keeps its accent.
 */

import fs from 'fs';
import path from 'path';

const read = ( rel ) =>
	fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );

describe( 'request-profile table theming', () => {
	it( 'RequestProfile.js imports its co-located request-profile.scss', () => {
		const src = read( 'performance-dashboards/RequestProfile.js' );
		expect( src ).toContain( "import './styles/request-profile.scss';" );
	} );

	it( 'request-profile.scss themes .widefat via the --paper-backed mixin', () => {
		const scss = read(
			'performance-dashboards/styles/request-profile.scss'
		);
		expect( scss ).toContain( '.event-logger-request-profile' );
		expect( scss ).toContain( '@include base.widefat-themed;' );
		// The mixin it pulls in re-skins .widefat through a --paper token.
		const base = read( 'performance-dashboards/styles/base.scss' );
		expect( base ).toMatch(
			/@mixin widefat-themed[\s\S]*\.widefat[\s\S]*var\(--paper/
		);
	} );

	it( 'LogEntriesTable.js gives every inline var(--cyan) a Cobalt fallback', () => {
		const src = read(
			'performance-dashboards/components/LogEntriesTable.js'
		);
		// No bare `var(--cyan)` without a fallback should remain.
		expect( src ).not.toMatch( /var\(--cyan\)/ );
		expect( src ).toContain( 'var(--cyan, #003da5)' );
	} );
} );
