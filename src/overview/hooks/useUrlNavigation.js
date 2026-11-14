/**
 * useUrlNavigation — the Performance page's selection, read from the page
 * state that holds it: the subject `?url=` or `?page_hash=` names, and the
 * request `?request=` names. Each is a Back entry, so the one bar store
 * keeps the address bar and the history in step: a selection the operator
 * makes pushes one entry, and Back or Forward hands the selection the entry
 * it lands on. The hook keeps no history of its own.
 *
 * A subject the loaded rows open, or one the page selected or titled, is
 * selected. Any other subject and any request the page has not opened are
 * a deep link the hook reports as `deepLink` for the page to resolve,
 * because only the server can answer for them and a command belongs beside
 * the state its reply sets. `?search=` it reads once, on mount, leaving the
 * writing to the owner.
 */

import {
	useCallback,
	useLayoutEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import {
	getQueryParam,
	setQueryParams,
} from '@newspack-nodes/shared/utils/queryParams';
import { findSubject, subjectLink } from '../detailSubject';

/**
 * The subject the page state names, or null for none.
 *
 * @param {string} url  The `url` key.
 * @param {string} page The `page_hash` key.
 * @return {?{field: string, hash: string}} The subject.
 */
const linkedOf = ( url, page ) => {
	if ( url ) {
		return { field: 'url', hash: url };
	}
	return page ? { field: 'page', hash: page } : null;
};

/**
 * A subject's key in the hook's records.
 *
 * @param {{field: string, hash: string}} subject The subject.
 * @return {string} `<field>:<hash>`.
 */
const keyOf = ( subject ) => `${ subject.field }:${ subject.hash }`;

/**
 * Hold the selection the page renders, from the page state that names it.
 *
 * The returned object carries the selection — `selectedSubject`, its
 * `{ field, hash, url }`, `url` its title once the page knows one — and
 * `selectedRequest`, a request id — with a setter for each; the setters of
 * one event write one Back entry. It carries `initialSearchQuery`, the
 * `?search=` value read once on mount, which the owner runs and then clears
 * through `setInitialSearchQuery`, and `deepLink`, `{ requestId, subject }`,
 * the part of the link the page has yet to answer, with `clearDeepLink`,
 * which reports it answered: `'request'` drops the rid alone, which is how a
 * rid the server cannot find falls through to the linked subject.
 *
 * @param {Array} urls One page of the URL table's page rows, each opening
 *                     the subject `subjectOfRow()` names.
 * @return {Object} Navigation state and callbacks.
 */
export default function useUrlNavigation( urls ) {
	const [ url ] = usePageState( 'url' );
	const [ page ] = usePageState( 'page_hash' );
	const [ request ] = usePageState( 'request' );

	// Writes of one event, flushed as one Back entry after the render.
	const [ pending, setPending ] = useState( null );
	// Titles the page named, subjects it opened, and links it answered.
	const [ titles, setTitles ] = useState( () => new Map() );
	const [ opened, setOpened ] = useState( () => new Set() );
	const [ answered, setAnswered ] = useState( () => new Set() );
	const [ initialSearchQuery, setInitialSearchQuery ] = useState( () =>
		getQueryParam( 'search' )
	);

	const shown = pending ?? { url, page, request };
	const linked = linkedOf( shown.url, shown.page );

	const selectedSubject = useMemo( () => {
		if ( ! linked ) {
			return null;
		}
		const key = keyOf( linked );
		const title =
			titles.get( key ) ?? findSubject( urls, linked )?.url ?? undefined;
		return undefined !== title || opened.has( key )
			? { ...linked, url: title }
			: null;
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the link keys it
	}, [ shown.url, shown.page, titles, opened, urls ] );

	const selectedRequest =
		shown.request && opened.has( `request:${ shown.request }` )
			? shown.request
			: null;

	const deepLink = useMemo(
		() => ( {
			requestId:
				shown.request &&
				! selectedRequest &&
				! answered.has( `request:${ shown.request }` )
					? shown.request
					: null,
			subject:
				linked && ! selectedSubject && ! answered.has( keyOf( linked ) )
					? linked
					: null,
		} ),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the link keys it
		[
			shown.request,
			selectedRequest,
			shown.url,
			shown.page,
			selectedSubject,
			answered,
		]
	);

	/**
	 * Report the link as answered.
	 *
	 * @param {string} [part] `'request'` for the rid alone; omit for the link.
	 */
	const clearDeepLink = useCallback(
		( part ) => {
			setAnswered( ( held ) => {
				const next = new Set( held );
				if ( shown.request ) {
					next.add( `request:${ shown.request }` );
				}
				if ( 'request' !== part && linked ) {
					next.add( keyOf( linked ) );
				}
				return next;
			} );
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the link keys it
		[ shown.request, shown.url, shown.page ]
	);

	/**
	 * Open a subject, titled as given, or close the selection.
	 *
	 * @param {?Object} subject Its `{ field, hash, url }`, or null.
	 */
	const selectSubject = useCallback( ( subject ) => {
		if ( subject ) {
			const key = keyOf( subject );
			setOpened( ( held ) => new Set( held ).add( key ) );
			if ( undefined !== subject.url ) {
				setTitles( ( held ) =>
					new Map( held ).set( key, subject.url )
				);
			}
		}
		const link = subjectLink( subject );
		setPending( ( held ) => ( {
			...( held ?? {} ),
			url: link.url ?? '',
			page: link.page_hash ?? '',
		} ) );
	}, [] );

	/**
	 * Open a request within the selected subject, or close it.
	 *
	 * @param {?string} rid Request id, or null to close it.
	 */
	const selectRequest = useCallback( ( rid ) => {
		if ( rid ) {
			setOpened( ( held ) => new Set( held ).add( `request:${ rid }` ) );
		}
		setPending( ( held ) => ( { ...( held ?? {} ), request: rid ?? '' } ) );
	}, [] );

	// One event's writes, one Back entry, landing before any passive write.
	useLayoutEffect( () => {
		if ( ! pending ) {
			return;
		}
		const write = { url, page_hash: page, request };
		if ( undefined !== pending.url ) {
			write.url = pending.url;
			write.page_hash = pending.page;
		}
		if ( undefined !== pending.request ) {
			write.request = pending.request;
		}
		setPending( null );
		setQueryParams(
			{
				url: write.url || null,
				page_hash: write.page_hash || null,
				request: write.request || null,
			},
			{ push: true }
		);
	}, [ pending, url, page, request ] );

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
