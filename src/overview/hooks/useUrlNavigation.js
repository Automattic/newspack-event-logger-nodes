/**
 * useUrlNavigation — the Performance Dashboard's address bar.
 *
 * The dashboard's selection lives here: which subject is open
 * (`selectedSubject`, as `detailSubject` describes it) and which of its
 * requests (`selectedRequest`). The hook keeps that selection and the
 * `?url=` or `?page_hash=` beside `?request=` in step, so every view is a
 * shareable link and Back/Forward walks the views the operator visited.
 * `?search=` it only reads, once on mount, leaving the writing to the owner.
 *
 * Traffic runs both ways. Inbound, the query params seed the selection — on
 * mount and again on every Back/Forward, because the address bar IS the link.
 * Only the server can answer for a subject no loaded row of the catalog
 * opens, or for a `?request=`, whose rid names its URL, so the owner supplies
 * the resolvers. Outbound, every later selection change pushes a
 * history entry — every one the OPERATOR makes, that is. A selection the address
 * bar itself dictated is not written back, and the suppression is armed by the
 * same comparison that decides whether the selection moves at all, so the flush
 * it belongs to is the one that spends it.
 */

import {
	useState,
	useEffect,
	useLayoutEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';
import {
	getQueryParam,
	setQueryParams,
} from '@newspack-nodes/shared/utils/queryParams';
import {
	findSubject,
	linkedSubject,
	sameSubject,
	subjectLink,
} from '../detailSubject';

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
 * Hold the selection the dashboard renders, and the address bar that names it.
 *
 * The returned object carries the selection — `selectedSubject`, its
 * `{ field, hash, url }`, `url` its title when the row it came from names one
 * — and `selectedRequest`, a request id — with a setter for each. It also
 * carries `initialSearchQuery`, the `?search=` value read once on mount: the
 * owner runs that search and then clears the value through
 * `setInitialSearchQuery`, or it runs on every render.
 *
 * A subject or `?request=` deep link is an INTENT this hook holds and reports:
 * it resolves one it can answer from the loaded page itself, and otherwise
 * hands the caller `deepLink` — `{ requestId, subject }`, the subject as
 * `{ field, hash }` — and `clearDeepLink`.
 * The caller asks the server, because asking is a command and the commands
 * belong beside the state their replies set. An explicit `selectSubject` /
 * `selectRequest` SUPERSEDES a link still being resolved: it cancels the intent,
 * so a reply landing afterwards answers nothing and is dropped by the caller.
 *
 * @param {Array} urls One page of the URL table's page rows, each opening
 *                     the subject `subjectOfRow()` names.
 * @return {Object} Navigation state and callbacks.
 */
export default function useUrlNavigation( urls ) {
	// Selection state.
	const [ selectedSubject, setSelectedSubject ] = useState( null );
	const [ selectedRequest, setSelectedRequest ] = useState( null );

	// @longform Seeded from the query string on mount, these hold the part of
	// the link nothing has answered yet: popstate re-seeds the subject and the
	// rid, while the search query is read once and the owner clears it.
	const [ initialSubject, setInitialSubject ] = useState( linkedSubject );
	const [ initialRequestId, setInitialRequestId ] = useState( () => {
		const rid = getQueryParam( 'request' );
		return rid && isValidRequestId( rid ) ? rid : null;
	} );
	const [ initialSearchQuery, setInitialSearchQuery ] = useState( () =>
		getQueryParam( 'search' )
	);

	// Each makes the write-back effect below skip a flush, and it clears them.
	const isAddressBarNavigation = useRef( false );
	const isInitialMount = useRef( true );

	// @longform What the popstate listener reads. It registers once, so it
	// would otherwise close over the first render's selection and catalog
	// page; assigning during render keeps it reading what is on screen.
	const selectedSubjectRef = useRef( null );
	const selectedRequestRef = useRef( null );
	const urlsRef = useRef( urls );
	selectedSubjectRef.current = selectedSubject;
	selectedRequestRef.current = selectedRequest;
	urlsRef.current = urls;

	/**
	 * Report the link as answered. `'request'` drops only the rid, which is
	 * how a rid the server cannot find falls THROUGH to the linked subject
	 * instead of being re-asked forever.
	 *
	 * @param {string} [part] `'request'` for the rid alone; omit for the link.
	 */
	const clearDeepLink = useCallback( ( part ) => {
		setInitialRequestId( null );
		if ( 'request' !== part ) {
			setInitialSubject( null );
		}
	}, [] );

	/**
	 * Open a subject, or close the selection.
	 *
	 * @param {Object|null} subject Its `{ field, hash, url }`, or null.
	 */
	const selectSubject = useCallback(
		( subject ) => {
			// @longform An explicit selection SUPERSEDES a link still being
			// resolved: the operator has said what they want, so the reply
			// must not reopen what they closed nor yank them off what they
			// opened. Reporting a link goes through the raw setters, so this
			// cancels only what the operator or a resolver asks for.
			clearDeepLink();
			setSelectedSubject( subject );
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

	// @longform Restore the selection a subject / `?request=` link asks for.
	//
	// What this hook can answer, it answers: a subject a loaded row opens
	// needs no round trip. Anything else is reported as an intent, because
	// resolving it means asking the server, and a command belongs beside the
	// state its reply sets — held here it would need a resolver threaded in,
	// a one-at-a-time latch and a backoff, all of which a retried read already
	// does.
	useEffect( () => {
		const found = findSubject( urls, initialSubject );
		if ( ! found ) {
			return;
		}
		// @longform Answering the link is not a navigation, so it suppresses
		// the write-back: the bar already reads this subject, and writing the
		// selection alone would DELETE a `?request=` still being resolved and
		// push an entry for the deletion. Armed by the same comparison that
		// decides whether anything commits, so the flush spends what it armed.
		isAddressBarNavigation.current = found !== selectedSubjectRef.current;
		setSelectedSubject( found );
		// @longform The subject is answered, so it stops being an intent. The
		// RID beside it stays REPORTED: the caller resolves it, which answers
		// the URL it was made on, and selects the two together.
		setInitialSubject( null );
	}, [ urls, initialSubject ] );

	// @longform The link's unanswered part, packaged for the caller. Memoized
	// because the caller keys an effect on it: a fresh literal each render
	// re-fires the deep-link read.
	const deepLink = useMemo(
		() => ( { requestId: initialRequestId, subject: initialSubject } ),
		[ initialRequestId, initialSubject ]
	);

	// @longform Write the operator's selection back as a history entry. A
	// layout effect, so the push lands before any passive replaceState write
	// on the page, and those write into the entry it opened.
	useLayoutEffect( () => {
		// Skip when the address bar drove the change — it already says so.
		if ( isAddressBarNavigation.current ) {
			isAddressBarNavigation.current = false;
			return;
		}

		// Skip the mount flush — the bar already says what loaded.
		if ( isInitialMount.current ) {
			isInitialMount.current = false;
			return;
		}

		// The pushed state is never read back: popstate re-reads the bar.
		setQueryParams(
			{
				...subjectLink( selectedSubject ),
				request: selectedRequest || null,
			},
			{ push: true }
		);
	}, [ selectedSubject, selectedRequest ] );

	// @longform Browser back/forward re-enters through the deep-link path a
	// fresh load takes: the address bar is the link, so the rid is REPORTED
	// and the subject is answered from the loaded page where it can be, so
	// Forward opens a request exactly as a reload of the same URL does.
	useEffect( () => {
		const handlePopState = () => {
			const linked = linkedSubject();
			const rawRequestId = getQueryParam( 'request' );
			const requestId =
				rawRequestId && isValidRequestId( rawRequestId )
					? rawRequestId
					: null;

			// @longform What the loaded page can answer is answered HERE, in
			// the same flush: resolved a commit later instead, the write-back
			// runs in between on a selection that is only half the link. The
			// subject it cannot answer selects nothing — leaving the window
			// the operator came from open would make Back read as a no-op.
			const nextSubject = sameSubject(
				selectedSubjectRef.current,
				linked
			)
				? selectedSubjectRef.current
				: findSubject( urlsRef.current, linked );

			// @longform Armed only when this popstate really moves the
			// selection, so the flush that move causes is what spends it.
			// Armed unconditionally it outlives a Forward that selects
			// nothing — the rid is REPORTED, never selected — and the
			// operator's next change, closing the modal, goes unpushed.
			isAddressBarNavigation.current =
				nextSubject !== selectedSubjectRef.current ||
				null !== selectedRequestRef.current;

			// Raw setters: reporting the link must not cancel it.
			setSelectedSubject( nextSubject );
			setSelectedRequest( null );
			setInitialSubject( nextSubject ? null : linked );
			setInitialRequestId( requestId );
		};

		window.addEventListener( 'popstate', handlePopState );
		return () => window.removeEventListener( 'popstate', handlePopState );
	}, [] );

	return {
		selectedSubject,
		selectedRequest,
		selectSubject,
		selectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		deepLink,
		clearDeepLink,
	};
}
