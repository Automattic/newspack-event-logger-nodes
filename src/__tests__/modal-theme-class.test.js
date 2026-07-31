/**
 * Regression guard: @wordpress/components <Modal>s portal to document.body,
 * escaping the themed dashboard root, so they must carry the theme classes
 * themselves or their token references resolve to nothing.
 *
 * Every modal opts into the canonical modal/theme/UI contract. Overview adds
 * the non-layout skin root; settings modals stay on the standalone product
 * palette. Neither context borrows `.topology-app`.
 */

import fs from 'fs';
import path from 'path';

const read = ( rel ) =>
	fs.readFileSync( path.join( __dirname, '..', rel ), 'utf8' );

describe( 'dashboard Modal theme class', () => {
	it( 'overview PerformanceDashboard Modal carries the full skin context', () => {
		const src = read( 'overview/PerformanceDashboard.js' );
		expect( src ).toContain(
			'event-logger-performance-modal newspack-nodes-modal newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( src ).not.toContain( 'getStoredTheme' );
		expect( src ).not.toMatch(
			/className=.*topology-app.*event-logger-performance-modal/
		);
	} );

	// Shared modal chrome owns the close-button paint in every skin.
	it.each( [
		'overview/styles/modal.scss',
		'rules/rule-edit-modal.scss',
		'settings/styles/custom-event-selector.scss',
		'settings/styles/hook-selector.scss',
	] )( '%s: does not repaint the modal close button', ( rel ) => {
		expect( read( rel ) ).not.toMatch(
			/\.components-modal__header\s*>?\s*button\s*\{[^}]*(?:color|background|border|box-shadow|outline)\s*:/
		);
	} );

	it.each( [
		'settings/settings/HookSelectorModal.js',
		'settings/settings/CustomEventSelectorModal.js',
	] )( '%s: the Modal carries the standalone product contract', ( rel ) => {
		const src = read( rel );
		expect( src ).toMatch(
			/event-logger-[\w-]+-modal newspack-nodes-modal newspack-nodes-theme newspack-nodes-ui \$\{ className \}/
		);
		expect( src ).not.toMatch( /className=.*topology-app/ );
	} );
} );
