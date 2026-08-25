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

	// @longform The hash it can answer, it answers; the RID it reports. The
	// rid alone carries the PARTITION, and selecting a request without one
	// renders "Could not determine the partition for this request" until the
	// answer lands — so the caller selects it, with the partition, not here.
	it( 'selects the ?url= it can answer and REPORTS the ?request=', () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa&request=req_123' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		expect( result.current.selectedUrl ).toEqual( URLS[ 0 ] );
		expect( result.current.selectedRequest ).toBeNull();
		expect( result.current.deepLink.requestId ).toBe( 'req_123' );
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
		setLocation( 'http://localhost/wp-admin/?url=aaa' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		// Select as the caller does once it has the partition; the bootstrap
		// only REPORTS a rid.
		act( () => result.current.selectRequest( 'r1' ) );
		expect( result.current.selectedRequest ).toBe( 'r1' );

		setLocation( 'http://localhost/wp-admin/?url=aaa' );
		act( () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );
		expect( result.current.selectedRequest ).toBeNull();
		unmount();
	} );

	// ── back/forward to a request ─────────────────────────────────────────
	//
	// A popstate carrying a rid re-enters the SAME deep-link path a fresh load
	// takes. The rid alone carries the partition, so selecting it here rendered
	// "Could not determine the partition for this request" on every Forward.

	it( 'reports the rid on a popstate to a request instead of selecting it partition-less', async () => {
		const paged = [ { hash: 'zeta7', url: '/deep/zeta' } ];
		setLocation( 'http://localhost/wp-admin/?url=zeta7' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( paged )
		);
		await act( async () => {} );
		expect( result.current.selectedUrl ).toEqual( paged[ 0 ] );

		setLocation(
			'http://localhost/wp-admin/?url=zeta7&request=q9kfwjrid42'
		);
		await act( async () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );

		expect( result.current.selectedRequest ).toBeNull();
		expect( result.current.deepLink.requestId ).toBe( 'q9kfwjrid42' );
		expect( result.current.selectedUrl ).toEqual( paged[ 0 ] );
		unmount();
	} );

	it( 'pushes no history entry for a popstate-driven request selection', async () => {
		const paged = [ { hash: 'zeta7', url: '/deep/zeta' } ];
		setLocation( 'http://localhost/wp-admin/?url=zeta7' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( paged )
		);
		await act( async () => {} );
		pushSpy.mockClear();

		setLocation(
			'http://localhost/wp-admin/?url=zeta7&request=q9kfwjrid42'
		);
		await act( async () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );
		// The caller answers the intent, partition in hand, as the dashboard
		// does once request_search replies.
		await act( async () => {
			result.current.clearDeepLink();
			result.current.selectRequest( 'q9kfwjrid42' );
		} );

		expect( result.current.selectedRequest ).toBe( 'q9kfwjrid42' );
		expect( pushSpy ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'falls through to the ?url= hash when the caller cannot resolve the rid', async () => {
		const paged = [ { hash: 'zeta7', url: '/deep/zeta' } ];
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( paged )
		);
		await act( async () => {} );

		setLocation(
			'http://localhost/wp-admin/?url=kappa9&request=q9kfwjrid42'
		);
		await act( async () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );
		expect( result.current.deepLink.requestId ).toBe( 'q9kfwjrid42' );

		// Not-found is an answer: the rid drops, the hash gets its turn.
		act( () => result.current.clearDeepLink( 'request' ) );
		expect( result.current.deepLink.requestId ).toBeNull();
		expect( result.current.deepLink.urlHash ).toBe( 'kappa9' );
		unmount();
	} );

	it( 'drops a pending request intent when popstate goes back to the dashboard', async () => {
		const paged = [ { hash: 'zeta7', url: '/deep/zeta' } ];
		setLocation(
			'http://localhost/wp-admin/?url=zeta7&request=q9kfwjrid42'
		);
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( paged )
		);
		await act( async () => {} );
		expect( result.current.deepLink.requestId ).toBe( 'q9kfwjrid42' );

		setLocation( 'http://localhost/wp-admin/' );
		await act( async () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );

		expect( result.current.deepLink.requestId ).toBeNull();
		expect( result.current.deepLink.urlHash ).toBeNull();
		expect( result.current.selectedUrl ).toBeNull();
		unmount();
	} );

	// Answering the hash is not a navigation either: the bar already reads it,
	// and writing the selection ALONE would delete a `?request=` the resolver
	// has not answered yet — and push an entry for the deletion.
	it( 'answers a ?url= without stripping the ?request= still being resolved', async () => {
		setLocation( 'http://localhost/wp-admin/?url=aaa&request=req_9pk4tz' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( URLS )
		);
		await act( async () => {} );

		expect( result.current.selectedUrl ).toEqual( URLS[ 0 ] );
		expect( result.current.deepLink.requestId ).toBe( 'req_9pk4tz' );
		expect( pushSpy ).not.toHaveBeenCalled();
		expect( window.location.search ).toContain( 'request=req_9pk4tz' );
		unmount();
	} );

	// The suppression flag must be spent by the popstate that armed it. A
	// Forward whose hash is already open writes no selection, so an armed flag
	// would sit there until the operator's NEXT change — closing the modal —
	// and swallow the entry that close is owed.
	it( 'pushes for a close that follows a popstate which changed no selection', async () => {
		const paged = [ { hash: 'omicron4', url: '/deep/omicron' } ];
		setLocation( 'http://localhost/wp-admin/?url=omicron4' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( paged )
		);
		await act( async () => {} );
		expect( result.current.selectedUrl ).toEqual( paged[ 0 ] );
		pushSpy.mockClear();

		// Forward into a request of the URL already open: the rid is reported
		// and nothing selected changes, so no flush is coming to spend a flag.
		setLocation(
			'http://localhost/wp-admin/?url=omicron4&request=t8vmqzr51xd'
		);
		await act( async () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );
		expect( result.current.deepLink.requestId ).toBe( 't8vmqzr51xd' );

		// The operator closes the modal before the resolver answers.
		await act( async () => {
			result.current.selectUrl( null );
		} );

		expect( pushSpy ).toHaveBeenCalled();
		const [ , , newUrl ] = pushSpy.mock.calls[ 0 ];
		expect( newUrl ).not.toMatch( /url=omicron4/ );
		unmount();
	} );

	// Back to a hash this page cannot answer must still LEAVE the URL it came
	// from; otherwise Back reads as a no-op with the old modal still up.
	it( 'clears the open URL on a popstate to a different, off-page hash', async () => {
		const paged = [ { hash: 'omicron4', url: '/deep/omicron' } ];
		setLocation( 'http://localhost/wp-admin/?url=omicron4' );
		const { result, unmount } = renderHook( () =>
			useUrlNavigation( paged )
		);
		await act( async () => {} );
		expect( result.current.selectedUrl ).toEqual( paged[ 0 ] );

		setLocation( 'http://localhost/wp-admin/?url=sigma88' );
		await act( async () => {
			window.dispatchEvent( new Event( 'popstate' ) );
		} );

		expect( result.current.selectedUrl ).toBeNull();
		expect( result.current.deepLink.urlHash ).toBe( 'sigma88' );
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
