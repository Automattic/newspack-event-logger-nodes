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
import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';

const URLS = [
	{ hash: 'aaa', url: '/foo' },
	{ hash: 'bbb', url: '/bar' },
];

function setLocation( href ) {
	// jsdom navigates via window.history.replaceState — the only way to
	// change window.location.search / .href without crashing on the
	// non-configurable Location getter.
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
