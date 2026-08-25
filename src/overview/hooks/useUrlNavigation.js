/**
 * useUrlNavigation — the Performance Dashboard's address bar.
 *
 * The dashboard's selection lives here: which URL is open (`selectedUrl`) and
 * which of its requests (`selectedRequest`). The hook keeps that selection and
 * the query string (`?url=`, `?request=`, `?search=`) in step, so every view is
 * a shareable link and Back/Forward walks the views the operator visited.
 *
 * Traffic runs both ways. Inbound, the query params seed the selection — on
 * mount and again on every Back/Forward, because the address bar IS the link.
 * Only the server can answer for a `?url=` hash outside the loaded page of the
 * catalog, or for a `?request=`, whose rid alone carries the partition, so the
 * owner supplies the resolvers. Outbound, every later selection change pushes a
 * history entry — every one the OPERATOR makes, that is. A selection the address
 * bar itself dictated is not written back, and the suppression is armed by the
 * same comparison that decides whether the selection moves at all, so the flush
 * it belongs to is the one that spends it.
 */

import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';

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
 * The catalog entry for a hash, when the loaded page holds one.
 *
 * @param {Array}   urls One page of the URL catalog.
 * @param {?string} hash The hash to resolve.
 * @return {?Object} The entry carrying that hash, or null.
 */
const findUrl = ( urls, hash ) =>
	( hash && urls.find( ( u ) => u.hash === hash ) ) || null;

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
 * belong beside the state their replies set. An explicit `selectUrl` /
 * `selectRequest` SUPERSEDES a link still being resolved: it cancels the intent,
 * so a reply landing afterwards answers nothing and is dropped by the caller.
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
	const isAddressBarNavigation = useRef( false );
	const isInitialMount = useRef( true );

	// The committed selection, for the handlers that run outside render.
	const selectedUrlRef = useRef( null );
	const selectedRequestRef = useRef( null );
	const urlsRef = useRef( urls );
	selectedUrlRef.current = selectedUrl;
	selectedRequestRef.current = selectedRequest;
	urlsRef.current = urls;

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

	/**
	 * Open a URL, or close the selection.
	 *
	 * @param {Object|null} url URL object carrying a `hash`, or null to clear.
	 */
	const selectUrl = useCallback(
		( url ) => {
			// @longform An explicit selection SUPERSEDES a link still being
			// resolved: the operator has said what they want, so the reply
			// must not reopen what they closed nor yank them off what they
			// opened. Reporting a link goes through the raw setters, so this
			// cancels only what the operator or a resolver asks for.
			clearDeepLink();
			setSelectedUrl( url );
		},
		[ clearDeepLink ]
	);

	/**
	 * Open a request within the selected URL, or close it.
	 *
	 * @param {string|null} rid Request id, or null to clear.
	 */
	const selectRequest = useCallback(
		( rid ) => {
			clearDeepLink();
			setSelectedRequest( rid );
		},
		[ clearDeepLink ]
	);

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
		const urlObj = findUrl( urls, initialUrlHash );
		if ( ! urlObj ) {
			return;
		}
		// @longform Answering the link is not a navigation, so it suppresses
		// the write-back: the bar already reads this hash, and writing the
		// selection alone would DELETE a `?request=` still being resolved and
		// push an entry for the deletion. Armed by the same comparison that
		// decides whether anything commits, so the flush spends what it armed.
		isAddressBarNavigation.current = urlObj !== selectedUrlRef.current;
		setSelectedUrl( urlObj );
		// @longform The RID is REPORTED, not selected: it alone carries the
		// partition, and selecting a request without one makes the detail
		// modal render "Could not determine the partition for this request"
		// until the answer lands. The caller selects it WITH the partition.
		setInitialUrlHash( null );
	}, [ urls, initialUrlHash ] );

	// The part of the link nothing has answered yet.
	const deepLink = useMemo(
		() => ( { requestId: initialRequestId, urlHash: initialUrlHash } ),
		[ initialRequestId, initialUrlHash ]
	);

	// Update browser URL when selection changes.
	useEffect( () => {
		// Skip when the address bar drove this — it already says this.
		if ( isAddressBarNavigation.current ) {
			isAddressBarNavigation.current = false;
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

	// @longform Browser back/forward re-enters through the deep-link path a
	// fresh load takes: the address bar is the link, so the rid is REPORTED
	// and the hash is answered from the loaded page where it can be.
	// Selecting the rid here instead left it without the partition it alone
	// carries, and Forward rendered "Could not determine the partition for
	// this request" on a request a reload of the same URL opened fine.
	useEffect( () => {
		const handlePopState = () => {
			const params = getUrlParams();
			const urlHash = params.get( 'url' );
			const rawRequestId = params.get( 'request' );
			const requestId =
				rawRequestId && isValidRequestId( rawRequestId )
					? rawRequestId
					: null;

			// @longform What the loaded page can answer is answered HERE, in
			// the same flush: resolved a commit later instead, the write-back
			// runs in between on a selection that is only half the link. The
			// hash it cannot answer selects nothing — leaving the URL the
			// operator came from open would make Back read as a no-op.
			const nextUrl =
				selectedUrlRef.current?.hash === urlHash
					? selectedUrlRef.current
					: findUrl( urlsRef.current, urlHash );

			// @longform Armed only when this popstate really moves the
			// selection, so the flush that move causes is what spends it.
			// Armed unconditionally it outlived a Forward that selected
			// nothing — the rid is REPORTED, never selected — and the
			// operator's next change, closing the modal, went unpushed.
			isAddressBarNavigation.current =
				nextUrl !== selectedUrlRef.current ||
				null !== selectedRequestRef.current;

			// Raw setters: reporting the link must not cancel it.
			setSelectedUrl( nextUrl );
			setSelectedRequest( null );
			setInitialUrlHash( nextUrl ? null : urlHash );
			setInitialRequestId( requestId );
		};

		window.addEventListener( 'popstate', handlePopState );
		return () => window.removeEventListener( 'popstate', handlePopState );
	}, [] );

	return {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
		// @longform The part of the link this hook could not answer by
		// itself. Memoized because callers key effects on it: a fresh
		// literal each render re-fires whatever asks about it.
		deepLink,
		clearDeepLink,
	};
}
