/**
 * URL Navigation Hook
 *
 * Manages URL/request selection state and browser history synchronization.
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
 * @param {string} id Request ID to validate.
 * @return {boolean} True if valid.
 */
const isValidRequestId = ( id ) =>
	typeof id === 'string' && /^[a-zA-Z0-9_-]+$/.test( id );

/**
 * Update the browser URL and push to history for back button support.
 *
 * @param {Object} params Parameters to set.
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
 * @param {Array} urls Array of URL objects with hash property.
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

	// Wrapper to select URL.
	const selectUrl = useCallback( ( url ) => {
		setSelectedUrl( url );
	}, [] );

	// Wrapper to select request.
	const selectRequest = useCallback( ( rid ) => {
		setSelectedRequest( rid );
	}, [] );

	// Restore state from URL parameters when URLs are loaded.
	useEffect( () => {
		if ( urls.length > 0 && initialUrlHash ) {
			const urlObj = urls.find( ( u ) => u.hash === initialUrlHash );
			if ( urlObj ) {
				selectUrl( urlObj );
				// If there's also a request ID in the URL, set it after URL loads.
				if ( initialRequestId ) {
					selectRequest( initialRequestId );
				}
			}
			// Clear initial values so this only runs once.
			setInitialUrlHash( null );
			setInitialRequestId( null );
		}
	}, [ urls, initialUrlHash, initialRequestId, selectUrl, selectRequest ] );

	// Update browser URL when selection changes.
	useEffect( () => {
		// Skip if this change was triggered by popstate - URL is already correct.
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

	// Handle browser back/forward button.
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
