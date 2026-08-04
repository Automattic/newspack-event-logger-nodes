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
 * @param {Array}    urls               One page of the URL catalog; each entry
 *                                      carries a `hash` and a `url`.
 * @param {Function} [resolveRequestId] Optional async (rid) -> boolean. Called
 *                                      when `?request=` is set but `?url=`
 *                                      isn't — owner resolves the URL hash and
 *                                      selects both. Return false to report a
 *                                      miss so the intent is held for a retry.
 * @param {Function} [resolveUrlHash]   Optional async (hash) -> {url}|null. The
 *                                      url is display-ready: the resolver owns
 *                                      the unknown-URL fallback, not this hook.
 *                                      Answers for a hash outside the loaded
 *                                      page. Null holds the intent.
 * @return {Object} Navigation state and callbacks.
 */
export default function useUrlNavigation(
	urls,
	resolveRequestId,
	resolveUrlHash
) {
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
	// One resolve in flight at a time; the effect re-runs on every urls tick.
	const resolveInFlight = useRef( false );

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

	// @longform Restore selection from ?url= / ?request=.
	//
	// The intent is held until a resolve SETTLES, never cleared on the way in.
	// Clearing early made one failed attempt permanent and silent: the graph
	// may not be connected on the first pass, and `urls` is one page of the
	// catalog, so a deep-linked low-traffic hash is never in it. The effect
	// re-runs on each urls tick, which is the retry.
	useEffect( () => {
		if ( ! initialUrlHash && ! initialRequestId ) {
			return;
		}
		if ( resolveInFlight.current ) {
			return;
		}

		const settle = () => {
			setInitialUrlHash( null );
			setInitialRequestId( null );
		};

		if ( initialUrlHash ) {
			const urlObj = urls.find( ( u ) => u.hash === initialUrlHash );
			if ( urlObj ) {
				selectUrl( urlObj );
				if ( initialRequestId ) {
					selectRequest( initialRequestId );
				}
				settle();
				return;
			}
			// Outside the loaded page: only the server can answer.
			if ( ! resolveUrlHash ) {
				return;
			}
			resolveInFlight.current = true;
			Promise.resolve( resolveUrlHash( initialUrlHash ) )
				.then( ( data ) => {
					if ( ! data ) {
						return; // Keep the intent; the next tick retries.
					}
					selectUrl( { hash: initialUrlHash, url: data.url } );
					if ( initialRequestId ) {
						selectRequest( initialRequestId );
					}
					settle();
				} )
				.finally( () => {
					resolveInFlight.current = false;
				} );
			return;
		}

		// ?request= without ?url=: the resolver finds the URL and selects both.
		if ( resolveRequestId ) {
			resolveInFlight.current = true;
			Promise.resolve( resolveRequestId( initialRequestId ) )
				.then( ( ok ) => {
					if ( false !== ok ) {
						setInitialRequestId( null );
					}
				} )
				.finally( () => {
					resolveInFlight.current = false;
				} );
		}
	}, [
		urls,
		initialUrlHash,
		initialRequestId,
		selectUrl,
		selectRequest,
		resolveRequestId,
		resolveUrlHash,
	] );

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
	};
}
