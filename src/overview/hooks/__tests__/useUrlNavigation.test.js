/**
 * Tests for useUrlNavigation — keeps URL/request selection in sync
 * with the address bar and history stack.
 *
 * Behaviour covered:
 *   - bootstraps selectedUrl/selectedRequest from ?url= / ?request= on mount
 *   - calls resolveRequestId when ?request= is present without ?url=
 *   - pushes history entries when selection changes after mount
 *   - reacts to popstate (back/forward) to restore selection
 *   - guards against invalid request IDs (rejected, never selected)
 */

import useUrlNavigation from '../useUrlNavigation';
import { renderHook, act } from '../../../test-helpers/renderHook';

const URLS = [
	{ hash: 'aaa', url: '/foo' },
	{ hash: 'bbb', url: '/bar' },
];

function setLocation( href ) {
	// jsdom: use history.replaceState to change window.location.
	window.history.replaceState( {}, '', href );
}

describe( 'useUrlNavigation', () => {
	let pushSpy;

	beforeEach( () => {
		setLocation( 'http://localhost/wp-admin/' );
		pushSpy = jest
			.spyOn( window.history, 'pushState' )
			.mockImplementation( () => {} );
	} );

	afterEach( () => {
		pushSpy.mockRestore();
	} );

	// ── deep links must resolve hashes that are NOT in the loaded page ─────
	//
	// `urls` holds one page of the catalog (50 of ~1000). A deep link to a
	// low-traffic URL — the case deep links are FOR — never appears in it, so
	// searching the list silently opens nothing. Hash deliberately absent from
	// URLS: a hash that happens to be loaded passes either way and proves
	// nothing, which is exactly why this shipped.

	it( 'resolves a ?url= hash that is not in the loaded page', async () => {
		setLocation( 'http://localhost/wp-admin/?url=notinpage' );
		const resolveUrlHash = jest
			.fn()
			.mockResolvedValue( { url: '/deep/link/target.webp' } );

		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS, undefined, resolveUrlHash )
		);
		await act( async () => {} );

		expect( resolveUrlHash ).toHaveBeenCalledWith( 'notinpage' );
		expect( result.current.selectedUrl ).toEqual( {
			hash: 'notinpage',
			url: '/deep/link/target.webp',
		} );
		unmount();
	} );

	it( 'keeps the ?url= intent when a resolve attempt fails', async () => {
		// The graph may not be connected on the first effect pass. Discarding
		// the intent then makes the failure permanent and silent.
		setLocation( 'http://localhost/wp-admin/?url=notinpage' );
		const resolveUrlHash = jest
			.fn()
			.mockResolvedValueOnce( null )
			.mockResolvedValue( { url: '/late/arrival' } );

		// Each poll tick hands the hook a FRESH array — that is the retry
		// signal in production, where urls arrives via useNodeState.
		let tick = [ ...URLS ];
		const { result, rerender, unmount } = renderHook( () =>
			useUrlNavigation( tick, undefined, resolveUrlHash )
		);
		await act( async () => {} );
		expect( result.current.selectedUrl ).toBeNull();

		await act( async () => {
			tick = [ ...URLS ];
			rerender();
		} );
		await act( async () => {} );

		expect( resolveUrlHash.mock.calls.length ).toBeGreaterThan( 1 );
		expect( result.current.selectedUrl ).toEqual( {
			hash: 'notinpage',
			url: '/late/arrival',
		} );
		unmount();
	} );

	it( 'keeps the ?request= intent when the resolver reports failure', async () => {
		setLocation( 'http://localhost/wp-admin/?request=abc123def4567890' );
		const resolveRequestId = jest
			.fn()
			.mockResolvedValueOnce( false )
			.mockResolvedValue( true );

		let tick = [ ...URLS ];
		const { rerender, unmount } = renderHook( () =>
			useUrlNavigation( tick, resolveRequestId )
		);
		await act( async () => {} );

		await act( async () => {
			tick = [ ...URLS ];
			rerender();
		} );
		await act( async () => {} );

		expect( resolveRequestId.mock.calls.length ).toBeGreaterThan( 1 );
		unmount();
	} );

	it( 'starts with no selection and exposes the API surface', () => {
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedUrl ).toBeNull();
		expect( result.current.selectedRequest ).toBeNull();
		expect( typeof result.current.selectUrl ).toBe( 'function' );
		expect( typeof result.current.selectRequest ).toBe( 'function' );
		expect( typeof result.current.updateBrowserUrl ).toBe( 'function' );
		unmount();
	} );

	it( 'bootstraps selectedUrl from ?url= when found in the URLs list', () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedUrl ).toEqual( URLS[ 0 ] );
		unmount();
	} );

	it( 'bootstraps selectedRequest along with selectedUrl', () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa&request=req_123' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedUrl ).toEqual( URLS[ 0 ] );
		expect( result.current.selectedRequest ).toBe( 'req_123' );
		unmount();
	} );

	it( 'ignores a request id with invalid characters', () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa&request=has space' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedRequest ).toBeNull();
		unmount();
	} );

	it( 'calls resolveRequestId when only ?request= is set (no ?url=)', () => {
		setLocation( 'http://localhost/wp-admin/?request=req_xyz' );
		const resolve = jest.fn();
		const { unmount } = renderHook( () =>
			useUrlNavigation( URLS, resolve )
		);
		expect( resolve ).toHaveBeenCalledWith( 'req_xyz' );
		unmount();
	} );

	it( 'pushes a history entry when selection changes after mount', () => {
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		// First effect run is the mount; pushSpy should NOT fire yet.
		expect( pushSpy ).not.toHaveBeenCalled();
		act( () => {
			result.current.selectUrl( URLS[ 1 ] );
		} );
		expect( pushSpy ).toHaveBeenCalled();
		const [ , , newUrl ] = pushSpy.mock.calls[ 0 ];
		expect( newUrl ).toMatch( /url=bbb/ );
		unmount();
	} );

	it( 'updates URL on popstate (back to dashboard with no params)', () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedUrl ).toEqual( URLS[ 0 ] );

		setLocation( 'http://localhost/wp-admin/' );
		act( () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );
		expect( result.current.selectedUrl ).toBeNull();
		expect( result.current.selectedRequest ).toBeNull();
		unmount();
	} );

	it( 'restores selectedRequest=null when popstate moves from request-detail to url-detail', () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa&request=r1' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedRequest ).toBe( 'r1' );

		setLocation( 'http://localhost/wp-admin/?url=aaa' );
		act( () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );
		expect( result.current.selectedRequest ).toBeNull();
		unmount();
	} );

	it( 'updateBrowserUrl pushes only when href actually changes', () => {
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		pushSpy.mockClear();
		// Same as current (nothing changes) → no push.
		act( () => {
			result.current.updateBrowserUrl( {} );
		} );
		expect( pushSpy ).not.toHaveBeenCalled();
		// Setting url= produces a new href → push.
		act( () => {
			result.current.updateBrowserUrl( { url: 'aaa' } );
		} );
		expect( pushSpy ).toHaveBeenCalled();
		unmount();
	} );
} );
