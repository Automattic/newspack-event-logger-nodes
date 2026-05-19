/**
 * Tests for useAdminMenuWidth — measures the width of WordPress's
 * #adminmenuwrap, re-measuring on body class mutations (fold/unfold)
 * and on window resize.
 */

import useAdminMenuWidth from '../useAdminMenuWidth';
import { renderHook, act } from './renderHook';

describe( 'useAdminMenuWidth', () => {
	let menuEl;
	let currentWidth = 160;

	beforeEach( () => {
		menuEl = document.createElement( 'div' );
		menuEl.id = 'adminmenuwrap';
		Object.defineProperty( menuEl, 'offsetWidth', {
			configurable: true,
			get: () => currentWidth,
		} );
		document.body.appendChild( menuEl );
		currentWidth = 160;
	} );

	afterEach( () => {
		menuEl.remove();
	} );

	it( 'returns the initial offsetWidth on mount', () => {
		const { result, unmount } = renderHook( useAdminMenuWidth );
		expect( result.current ).toBe( 160 );
		unmount();
	} );

	it( 'returns 0 when there is no #adminmenuwrap', () => {
		menuEl.remove();
		const { result, unmount } = renderHook( useAdminMenuWidth );
		expect( result.current ).toBe( 0 );
		unmount();
	} );

	it( 're-measures when the window emits resize', () => {
		const { result, unmount } = renderHook( useAdminMenuWidth );
		currentWidth = 36;
		act( () => {
			window.dispatchEvent( new Event( 'resize' ) );
		} );
		expect( result.current ).toBe( 36 );
		unmount();
	} );

	it( 'detaches the resize listener on unmount', () => {
		const removeSpy = jest.spyOn( window, 'removeEventListener' );
		const { unmount } = renderHook( useAdminMenuWidth );
		unmount();
		const matches = removeSpy.mock.calls.filter(
			( call ) => call[ 0 ] === 'resize'
		);
		expect( matches.length ).toBeGreaterThan( 0 );
		removeSpy.mockRestore();
	} );
} );
