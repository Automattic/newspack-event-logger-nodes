/**
 * Tests for useUrlNavigation — keeps URL/request selection in sync
 * with the address bar and history stack.
 *
 * Behaviour covered:
 *   - bootstraps selectedUrl/selectedRequest from ?url= / ?request= on mount
 *   - reports a ?url= / ?request= it cannot answer from the loaded page
 *   - pushes history entries when selection changes after mount
 *   - reacts to popstate (back/forward) to restore selection
 *   - guards against invalid request IDs (rejected, never selected)
 */

import useUrlNavigation from '../useUrlNavigation';
import { Core } from '@newspack-nodes/runtime';
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
		Core.reset();
		setLocation( 'http://localhost/wp-admin/' );
		pushSpy = jest
			.spyOn( window.history, 'pushState' )
			.mockImplementation( () => {} );
	} );

	afterEach( () => {
		pushSpy.mockRestore();
	} );

	// ── a deep link this hook can answer, and one it cannot ───────────────
	//
	// `urls` holds ONE page of the catalog (50 of ~1000), so a deep link to a
	// low-traffic URL — the case deep links are FOR — is usually not in it.
	// This hook answers what it can from the page and REPORTS the rest; asking
	// the server is a command, and the retry, the one-at-a-time latch and the
	// backoff that used to live here are what a retried read already does.

	it( 'resolves a ?url= hash that IS in the loaded page, with no intent left over', async () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );

		expect( result.current.selectedUrl ).toEqual( {
			hash: 'aaa',
			url: '/foo',
		} );
		expect( result.current.deepLink.urlHash ).toBeNull();
		unmount();
	} );

	it( 'reports a ?url= hash that is NOT in the loaded page instead of selecting nothing', async () => {
		setLocation( 'http://localhost/wp-admin/?url=notinpage' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );

		expect( result.current.selectedUrl ).toBeNull();
		expect( result.current.deepLink.urlHash ).toBe( 'notinpage' );
		unmount();
	} );

	it( 'reports a ?request= with no ?url=, since the rid answers both', async () => {
		setLocation( 'http://localhost/wp-admin/?request=abc123def4567890' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );

		expect( result.current.deepLink.requestId ).toBe( 'abc123def4567890' );
		expect( result.current.deepLink.urlHash ).toBeNull();
		unmount();
	} );

	// The intent is HELD until the caller says it is answered — a resolver
	// that has not replied yet must not silently lose the link.
	it( 'holds the reported intent until clearDeepLink', async () => {
		setLocation( 'http://localhost/wp-admin/?url=notinpage' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );
		expect( result.current.deepLink.urlHash ).toBe( 'notinpage' );

		act( () => result.current.clearDeepLink() );
		expect( result.current.deepLink.urlHash ).toBeNull();
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

	// The rid is the more specific key: it answers BOTH the url hash and the
	// partition. Trusting ?url= alone left the partition unresolved, so the
	// request detail never fetched and the modal rendered empty. A link
	// carrying a rid therefore reports it EVEN when the hash is on this page.
	it( 'reports the request id even when ?url= is one this page could answer', async () => {
		setLocation(
			'http://localhost/wp-admin/?url=aaa&request=c6x0zgr0903wgw6lcylyvxm47ov7fwem'
		);
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );

		expect( result.current.deepLink.requestId ).toBe(
			'c6x0zgr0903wgw6lcylyvxm47ov7fwem'
		);
		unmount();
	} );

	// An invalid rid is refused at the door, so nothing downstream asks.
	it( 'reports no request id for one that is not rid-shaped', async () => {
		setLocation( 'http://localhost/wp-admin/?request=not%20a%20rid%21' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );
		expect( result.current.deepLink.requestId ).toBeNull();
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
