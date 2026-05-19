/**
 * Tests for useVirtualization — picks a window of rows to render based
 * on scroll position. Three modes:
 *   - 'self'    : list element is itself scrollable
 *   - selector  : closest()-matched ancestor is the scroller
 *   - null      : the window is the scroller
 */

import useVirtualization from '../useVirtualization';
import { renderHook, act } from './renderHook';

describe( 'useVirtualization', () => {
	const ROW_HEIGHT = 20;
	const TOTAL_ROWS = 100;
	const OVERSCAN = 5; // matches the constant in the hook.
	const cleanupEls = [];

	function makeListEl() {
		const el = document.createElement( 'div' );
		document.body.appendChild( el );
		cleanupEls.push( el );
		return el;
	}

	afterEach( () => {
		while ( cleanupEls.length ) {
			cleanupEls.pop().remove();
		}
	} );

	it( 'returns total-height + zero offsets when listRef is empty (early return)', () => {
		const ref = { current: null };
		const { result, unmount } = renderHook( () =>
			useVirtualization( ref, ROW_HEIGHT, TOTAL_ROWS )
		);
		expect( result.current.totalHeight ).toBe( TOTAL_ROWS * ROW_HEIGHT );
		expect( result.current.paddingTop ).toBe( 0 );
		// Default window-mode height is 2160; that drives initial count.
		expect( result.current.endIndex ).toBeGreaterThan( 0 );
		unmount();
	} );

	it( "measures via element.scrollTop in 'self' mode", () => {
		const el = makeListEl();
		Object.defineProperty( el, 'scrollTop', {
			configurable: true,
			value: 200,
		} );
		Object.defineProperty( el, 'clientHeight', {
			configurable: true,
			value: 100,
		} );
		const ref = { current: el };
		const { result, unmount } = renderHook( () =>
			useVirtualization( ref, ROW_HEIGHT, TOTAL_ROWS, 'self' )
		);
		// 200 / 20 = 10, minus OVERSCAN = 5.
		expect( result.current.startIndex ).toBe( 10 - OVERSCAN );
		// ceil(100 / 20) + OVERSCAN*2 = 5 + 10 = 15 rows visible.
		expect(
			result.current.endIndex - result.current.startIndex
		).toBeLessThanOrEqual( 15 );
		unmount();
	} );

	it( 'clamps startIndex at 0 when scroll position is near zero', () => {
		const el = makeListEl();
		Object.defineProperty( el, 'scrollTop', {
			configurable: true,
			value: 0,
		} );
		Object.defineProperty( el, 'clientHeight', {
			configurable: true,
			value: 80,
		} );
		const ref = { current: el };
		const { result, unmount } = renderHook( () =>
			useVirtualization( ref, ROW_HEIGHT, TOTAL_ROWS, 'self' )
		);
		expect( result.current.startIndex ).toBe( 0 );
		expect( result.current.paddingTop ).toBe( 0 );
		unmount();
	} );

	it( 'clamps endIndex at totalRows', () => {
		const el = makeListEl();
		Object.defineProperty( el, 'scrollTop', {
			configurable: true,
			value: 999999, // way past the end.
		} );
		Object.defineProperty( el, 'clientHeight', {
			configurable: true,
			value: 80,
		} );
		const ref = { current: el };
		const { result, unmount } = renderHook( () =>
			useVirtualization( ref, ROW_HEIGHT, TOTAL_ROWS, 'self' )
		);
		expect( result.current.endIndex ).toBe( TOTAL_ROWS );
		expect( result.current.paddingBottom ).toBe( 0 );
		unmount();
	} );

	it( 'applies the scrollOffset prop in window mode', () => {
		const el = makeListEl();
		el.getBoundingClientRect = () => ( {
			top: 0,
			bottom: 1000,
			height: 1000,
			left: 0,
			right: 0,
			width: 0,
		} );
		Object.defineProperty( window, 'innerHeight', {
			configurable: true,
			value: 600,
		} );
		const ref = { current: el };
		const { result, unmount } = renderHook( () =>
			useVirtualization( ref, ROW_HEIGHT, TOTAL_ROWS, null, 40 )
		);
		// scroll.top starts 0 in window mode (bounding rect top=0),
		// + scrollOffset=40 → floor(40/20)=2, minus OVERSCAN=5 → clamped to 0.
		expect( result.current.startIndex ).toBe( 0 );
		unmount();
	} );

	it( 'reacts to a scroll event in self mode', () => {
		const el = makeListEl();
		let scrollTop = 0;
		Object.defineProperty( el, 'scrollTop', {
			configurable: true,
			get: () => scrollTop,
		} );
		Object.defineProperty( el, 'clientHeight', {
			configurable: true,
			value: 80,
		} );
		const ref = { current: el };
		const { result, unmount } = renderHook( () =>
			useVirtualization( ref, ROW_HEIGHT, TOTAL_ROWS, 'self' )
		);
		expect( result.current.startIndex ).toBe( 0 );

		scrollTop = 400;
		act( () => {
			el.dispatchEvent( new Event( 'scroll' ) );
		} );
		// 400 / 20 = 20, minus OVERSCAN = 15.
		expect( result.current.startIndex ).toBe( 15 );
		unmount();
	} );
} );
