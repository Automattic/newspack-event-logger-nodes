/**
 * Regression guard: @wordpress/components <Modal>s portal to document.body,
 * escaping the themed dashboard root, so they must carry the theme classes
 * themselves or their token references resolve to nothing.
 *
 * The contract diverges by page:
 *   - The OVERVIEW dashboard is reskinned onto the PURE universal tokens, so its
 *     Modal needs the full `.topology-app … theme-<slug>` context (which DEFINES
 *     those tokens), keyed off the stored skin.
 *   - The SETTINGS modals live on the un-reskinned settings page (still --np-*),
 *     so `newspack-nodes-theme` alone is correct for them.
 */

import fs from 'fs';
import path from 'path';

const read = ( rel ) =>
	fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );

describe( 'dashboard Modal theme class', () => {
	it( 'overview PerformanceDashboard Modal carries the full skin context', () => {
		const src = read( 'overview/PerformanceDashboard.js' );
		expect( src ).toMatch(
			/topology-app newspack-nodes-theme theme-\$\{ getStoredTheme\(\) \} event-logger-performance-modal/
		);
		expect( src ).toContain(
			"import { getStoredTheme } from '@newspack-nodes/shared/theme';"
		);
	} );

	it.each( [
		'settings/settings/HookSelectorModal.js',
		'settings/settings/CustomEventSelectorModal.js',
	] )( '%s: the Modal carries newspack-nodes-theme', ( rel ) => {
		// Static base classes + a forwarded `${ className }` (empty on the settings
		// page; the dashboard passes its skin classes so the picker gets themed).
		expect( read( rel ) ).toMatch(
			/event-logger-[\w-]+-modal newspack-nodes-theme \$\{ className \}/
		);
	} );
} );
