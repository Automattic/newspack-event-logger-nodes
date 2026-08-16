/**
 * useUrlNavigation — the Performance Dashboard's address bar.
 *
 * The dashboard's selection lives here: which URL is open (`selectedUrl`) and
 * which of its requests (`selectedRequest`). The hook keeps that selection and
 * the query string (`?url=`, `?request=`, `?search=`) in step, so every view is
 * a shareable link and Back/Forward walks the views the operator visited.
 *
 * Traffic runs both ways. Inbound, on mount, the query params seed the
 * selection. Only the server can answer for a `?url=` hash outside the loaded
 * page of the catalog, or for a `?request=` carrying no `?url=`, so the owner
 * supplies the resolvers. Outbound, every later selection change pushes a
 * history entry; popstate restores the selection without pushing one back.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';

/**
 * Get URL parameters from the current page URL.
 *
 * @return {URLSearchParams} URL search params.
 */
const getUrlParams = () => new URLSearchParams( window.location.search );

/**
 * Validate request ID format (alphanumeric, underscore, hyphen).
 *
 * A request id read from the query string is echoed back into the address bar
 * and sent to the server, so anything outside the id alphabet is rejected here
 * rather than selected.
 *
 * @param {string} id Request ID to validate.
 * @return {boolean} True if valid.
 */
const isValidRequestId = ( id ) =>
	typeof id === 'string' && /^[a-zA-Z0-9_-]+$/.test( id );

/**
 * Write query params into the browser URL and push a history entry.
 *
 * A falsy value deletes its key instead of writing an empty one, which is how
 * a selection is cleared. An href that already matches pushes nothing, so a
 * caller writing the same params twice never stacks duplicate history entries.
 * Keys the caller omits are left alone.
 *
 * @param {Object} params Query params to set; a falsy value deletes the key.
 */
const updateBrowserUrl = ( params ) => {
	const url = new URL( window.location.href );
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value ) {
			url.searchParams.set( key, value );
		} else {
			url.searchParams.delete( key );
		}
	} );
	const newUrl = url.toString();
	if ( newUrl !== window.location.href ) {
		window.history.pushState( { ...params }, '', newUrl );
	}
};

/**
 * Custom hook for URL navigation state and browser history management.
 *
 * The returned object carries the selection — `selectedUrl`, a `{hash, url}`
 * object, and `selectedRequest`, a request id — with a setter for each. It also
 * carries `initialSearchQuery`, the `?search=` value read once on mount: the
 * owner runs that search and then clears the value through
 * `setInitialSearchQuery`, or it runs on every render. `updateBrowserUrl` is the
 * module helper, handed out for callers that must write a param this hook does
 * not own, `search` being the only one.
 *
 * A `?url=` / `?request=` deep link is an INTENT this hook holds and reports:
 * it resolves one it can answer from the loaded page itself, and otherwise
 * hands the caller `deepLink` — `{ requestId, urlHash }` — and `clearDeepLink`.
 * The caller asks the server, because asking is a command and the commands
 * belong beside the state their replies set.
 *
 * @param {Array} urls One page of the URL catalog; each entry carries a `hash`
 *                     and a `url`.
 * @return {Object} Navigation state and callbacks.
 */
export default function useUrlNavigation( urls ) {
	// Selection state.
	const [ selectedUrl, setSelectedUrl ] = useState( null );
	const [ selectedRequest, setSelectedRequest ] = useState( null );

	// Initial URL parameters (read once on mount).
	const [ initialUrlHash, setInitialUrlHash ] = useState( () =>
		getUrlParams().get( 'url' )
	);
	const [ initialRequestId, setInitialRequestId ] = useState( () => {
		const rid = getUrlParams().get( 'request' );
		return rid && isValidRequestId( rid ) ? rid : null;
	} );
	const [ initialSearchQuery, setInitialSearchQuery ] = useState( () =>
		getUrlParams().get( 'search' )
	);

	// Refs for navigation state tracking.
	const isPopstateNavigation = useRef( false );
	const isInitialMount = useRef( true );

	/**
	 * Open a URL, or close the selection.
	 *
	 * @param {Object|null} url URL object carrying a `hash`, or null to clear.
	 */
	const selectUrl = useCallback( ( url ) => {
		setSelectedUrl( url );
	}, [] );

	/**
	 * Open a request within the selected URL, or close it.
	 *
	 * @param {string|null} rid Request id, or null to clear.
	 */
	const selectRequest = useCallback( ( rid ) => {
		setSelectedRequest( rid );
	}, [] );

	// @longform Restore the selection a `?url=` / `?request=` link asks for.
	//
	// What this hook can answer, it answers: a hash already on the loaded page
	// needs no round trip. Anything else is reported as an intent, because
	// resolving it means asking the server, and a command belongs beside the
	// state its reply sets — held here it would need a resolver threaded in,
	// a one-at-a-time latch and a backoff, all of which a retried read already
	// does.
	useEffect( () => {
		if ( ! initialUrlHash ) {
			return;
		}
		const urlObj = urls.find( ( u ) => u.hash === initialUrlHash );
		if ( ! urlObj ) {
			return;
		}
		selectUrl( urlObj );
		if ( initialRequestId ) {
			selectRequest( initialRequestId );
		}
		// The RID alone carries the partition, so it stays reported.
		setInitialUrlHash( null );
	}, [ urls, initialUrlHash, initialRequestId, selectUrl, selectRequest ] );

	/**
	 * Report the link as answered. `'request'` drops only the rid, which is
	 * how a rid the server cannot find falls THROUGH to the `?url=` hash
	 * instead of being re-asked forever.
	 *
	 * @param {string} [part] `'request'` for the rid alone; omit for the link.
	 */
	const clearDeepLink = useCallback( ( part ) => {
		setInitialRequestId( null );
		if ( 'request' !== part ) {
			setInitialUrlHash( null );
		}
	}, [] );

	// Update browser URL when selection changes.
	useEffect( () => {
		// Skip if change was triggered by popstate — URL is already correct.
		if ( isPopstateNavigation.current ) {
			isPopstateNavigation.current = false;
			return;
		}

		// Skip on initial mount - don't push history on page reload.
		if ( isInitialMount.current ) {
			isInitialMount.current = false;
			return;
		}

		updateBrowserUrl( {
			url: selectedUrl?.hash || null,
			request: selectedRequest || null,
		} );
	}, [ selectedUrl, selectedRequest ] );

	// @longform Browser back/forward. Popstate resolves a hash against the
	// loaded page only, never through resolveUrlHash, so stepping back to a
	// hash that has paged out leaves selectedUrl on the previous URL while
	// the address bar reads the new one. Only mount reaches the server.
	useEffect( () => {
		const handlePopState = () => {
			const params = getUrlParams();
			const urlHash = params.get( 'url' );
			const rawRequestId = params.get( 'request' );
			const requestId =
				rawRequestId && isValidRequestId( rawRequestId )
					? rawRequestId
					: null;

			// Mark that we're handling a popstate - don't push new history.
			isPopstateNavigation.current = true;

			if ( ! urlHash ) {
				// Back to dashboard - close modal.
				selectUrl( null );
				selectRequest( null );
			} else if ( ! requestId ) {
				// Back to URL detail from request detail.
				const urlObj = urls.find( ( u ) => u.hash === urlHash );
				if ( urlObj ) {
					selectUrl( urlObj );
				}
				selectRequest( null );
			} else {
				// Navigate to specific request.
				const urlObj = urls.find( ( u ) => u.hash === urlHash );
				if ( urlObj ) {
					selectUrl( urlObj );
				}
				selectRequest( requestId );
			}
		};

		window.addEventListener( 'popstate', handlePopState );
		return () => window.removeEventListener( 'popstate', handlePopState );
	}, [ urls, selectUrl, selectRequest ] );

	return {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
		// The part of the link this hook could not answer by itself.
		deepLink: { requestId: initialRequestId, urlHash: initialUrlHash },
		clearDeepLink,
	};
}
