/**
 * ThemedRoot is the no-box token-provider that puts a console-selected skin's
 * universal tokens (--paper/--ink/--cyan/…) in scope above a standalone ELN
 * dashboard root. It reads the persisted skin once at mount via the shared
 * getStoredTheme and renders a `display:contents` wrapper carrying
 * `topology-app newspack-nodes-theme theme-<slug>`.
 */

import * as React from 'react';
import ThemedRoot from '../ThemedRoot';
import { THEME_STORAGE_KEY } from '@newspack-nodes/shared/theme';
import { renderComponent } from '../../test-helpers/renderHook';

describe( 'ThemedRoot', () => {
	afterEach( () => window.localStorage.clear() );

	it( 'wraps children in a display:contents topology-app token provider', () => {
		const { container, unmount } = renderComponent(
			React.createElement(
				ThemedRoot,
				null,
				React.createElement( 'span', { 'data-testid': 'child' }, 'hi' )
			)
		);
		const wrapper = container.querySelector( '.topology-app' );
		expect( wrapper ).not.toBeNull();
		expect( wrapper.classList.contains( 'newspack-nodes-theme' ) ).toBe(
			true
		);
		expect( wrapper.style.display ).toBe( 'contents' );
		// No font-family override: the skin's --font-mono cascades in (terminal
		// under decorative skins, the Newspack sans via --np-font by default), so
		// the dashboard reskins its typography too.
		expect( wrapper.style.fontFamily ).toBe( '' );
		expect(
			wrapper.querySelector( '[data-testid="child"]' )
		).not.toBeNull();
		unmount();
	} );

	it( 'carries the persisted skin class from getStoredTheme', () => {
		window.localStorage.setItem( THEME_STORAGE_KEY, 'crt' );
		const { container, unmount } = renderComponent(
			React.createElement( ThemedRoot, null, 'x' )
		);
		expect(
			container.querySelector( '.topology-app.theme-crt' )
		).not.toBeNull();
		unmount();
	} );

	it( 'falls back to the default skin class for an absent preference', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ThemedRoot, null, 'x' )
		);
		expect(
			container.querySelector( '.topology-app.theme-newspack' )
		).not.toBeNull();
		unmount();
	} );

	it( 'paints the WP-admin body with the resolved skin surface, restored on unmount', () => {
		// The effect probes --paper-3 from the themed wrapper; jsdom resolves no real
		// CSS, so mock the probe span's computed colour and delegate everything else.
		const real = window.getComputedStyle.bind( window );
		const spy = jest
			.spyOn( window, 'getComputedStyle' )
			.mockImplementation( ( el, pseudo ) =>
				el?.tagName === 'SPAN'
					? { backgroundColor: 'rgb(2, 10, 5)' }
					: real( el, pseudo )
			);
		const { unmount } = renderComponent(
			React.createElement( ThemedRoot, null, 'x' )
		);
		expect( document.body.style.background ).toBe( 'rgb(2, 10, 5)' );
		unmount();
		expect( document.body.style.background ).toBe( '' );
		spy.mockRestore();
	} );
} );
