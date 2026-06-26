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
		// font-family:inherit neutralizes .topology-app's inherited monospace font
		// so the wrapped product dashboard keeps its sans typography (only the
		// universal tokens + color cascade through to reskin it).
		expect( wrapper.style.fontFamily ).toBe( 'inherit' );
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
} );
