/**
 * Tests for usePageVisibility — tracks document.visibilityState and
 * updates on the visibilitychange event.
 */

import usePageVisibility from '../usePageVisibility';
import { renderHook, act } from './renderHook';

describe( 'usePageVisibility', () => {
	let visibilityValue = 'visible';

	beforeEach( () => {
		visibilityValue = 'visible';
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => visibilityValue,
		} );
	} );

	function setVisibility( value ) {
		visibilityValue = value;
		act( () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
	}

	it( 'returns true when the document starts visible', () => {
		const { result, unmount } = renderHook( usePageVisibility );
		expect( result.current ).toBe( true );
		unmount();
	} );

	it( 'returns false when the document starts hidden', () => {
		visibilityValue = 'hidden';
		const { result, unmount } = renderHook( usePageVisibility );
		expect( result.current ).toBe( false );
		unmount();
	} );

	it( 'updates when visibilityState flips to hidden', () => {
		const { result, unmount } = renderHook( usePageVisibility );
		expect( result.current ).toBe( true );
		setVisibility( 'hidden' );
		expect( result.current ).toBe( false );
		unmount();
	} );

	it( 'updates back to visible after a hidden flip', () => {
		const { result, unmount } = renderHook( usePageVisibility );
		setVisibility( 'hidden' );
		setVisibility( 'visible' );
		expect( result.current ).toBe( true );
		unmount();
	} );

	it( 'detaches its listener on unmount (no leaks)', () => {
		const removeSpy = jest.spyOn( document, 'removeEventListener' );
		const { unmount } = renderHook( usePageVisibility );
		unmount();
		const matchingCalls = removeSpy.mock.calls.filter(
			( call ) => call[ 0 ] === 'visibilitychange'
		);
		expect( matchingCalls.length ).toBeGreaterThan( 0 );
		removeSpy.mockRestore();
	} );
} );
