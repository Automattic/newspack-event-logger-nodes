/* global requestAnimationFrame */
/**
 * Performance page — the composed page and the URL and request window over
 * it.
 *
 * What the page shows is its layout: the blocks of the
 * `newspack-event-logger-nodes/performance` context, which read the page's
 * sources and its page state (`./sources`). What stands outside the layout
 * is what no block owns: the header controls (the Ask trigger, the request
 * search and its results, the refresh cadence and, for a `tune` reader,
 * **Edit layout**), the page's facts, and the window a URL row, a `?url=`,
 * `?page_hash=` or `?request=` link, or a search opens, so a link opens it
 * on any layout. A URL table opens it through the page it stands on, whose
 * `openSubject`, `selectedSubject` and `busySubject` this page provides.
 *
 * The window's body is a composed layout too: the URL window's is the
 * `url-detail` context's, the request window's the `request-detail`
 * context's, each standing for as long as its view does, under a status line
 * while its detail loads or is refused. Their blocks read the window's page
 * state and sources (`./blocks/url-window`, `./blocks/request-window`), and
 * open a request and sort the list through `selectRequest`, `requestSort`
 * and `sortRequests`.
 * The window's header actions are its chrome — the figures, Ask, the
 * logging-rule button and Back — and a `tune` reader finds **Edit layout**
 * beside them, which edits the window's layout on the subject it stands on.
 * A load editing the URL window's layout drops any request the link names
 * and opens the editor on the URL window. While its editor holds a change,
 * every leave of the window asks first: its close, Back to the URL, the
 * browser's Back, a search and a table row.
 *
 * The window's own controls are page state under a `url_` prefix, which a
 * shut window resets, as `docs/dashboards.md` lists. The page runs every
 * command whose reply sets that state: the `?url=` / `?page_hash=` and
 * `?request=` deep-link resolvers, the title lookup for a hash the loaded
 * table page does not carry, the search box's exact-rid lookup and its
 * pattern search, and the ruleset reads and writes behind "Log this URL". It
 * preserves the window's scroll position across the URL-detail ↔
 * request-detail switch, and holds its `overview` sources still while the
 * window stands or the `?` picker is armed, since a repaint under a
 * stationary pointer swaps the row aimed at.
 */

import {
	memo,
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { SKIN_ROOT_CLASSES } from '@newspack-nodes/shared/components/ThemedRoot';
import { __, sprintf } from '@wordpress/i18n';

import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import ComposedPageFrame from '@newspack-nodes/shared/composition/page/ComposedPageFrame';
import { editTarget } from '@newspack-nodes/shared/composition/page/editMode';
import {
	PageStateProvider,
	usePageState,
} from '@newspack-nodes/shared/composition/page/pageState';
import {
	SourcesProvider,
	useSource,
	useSourcesPaused,
} from '@newspack-nodes/shared/sources';
import {
	fieldValue,
	namedFields,
	display,
} from '@newspack-nodes/shared/blocks/sourceBlock';
import { DEFAULT_CHART_METRIC, GROUPS, SORT_ORDERS } from './constants';
import {
	DEFAULT_REQUEST_SORT,
	REQUEST_SORT_FIELDS,
	nextSort,
} from './components/RequestTable';
import { useQueryParamChoice } from '@newspack-nodes/shared/hooks/useQueryParamState';
import {
	BLOCK_VIEWS,
	NO_CHIPS,
	RULES_CI,
	SERVER,
	WINDOW_BREAKDOWN,
} from './sources';
import { useFilter, useSite, useUrlsParams } from './blocks/pageQuestions';
import { useRequestDetail, useUrlDetail } from './blocks/windowQuestions';
import {
	PERFORMANCE_CONTEXT,
	PERFORMANCE_PAGE,
	PERFORMANCE_PAGES,
	REQUEST_WINDOW,
	REQUEST_WINDOW_CONTEXT,
	URL_WINDOW,
	URL_WINDOW_CONTEXT,
} from './performancePageData';
import { composedPage } from '../composedPageData';
import useUrlNavigation from './hooks/useUrlNavigation';
import {
	findSubject,
	linkedSubject,
	sameSubject,
	subjectArgs,
	subjectDescriptor,
	subjectLink,
	subjectOfRow,
} from './detailSubject';
import {
	holdPop,
	setQueryParams,
} from '@newspack-nodes/shared/utils/queryParams';
import { requestedUrl, rulePath } from '../log-table/urlIdentity';
import { useDismissable } from '@newspack-nodes/shared/hooks/useDismissable';
import AskPanel, { AskButton, useAsk } from './components/AskPanel';
import PerformanceHeader, {
	SearchFindings,
} from './components/PerformanceHeader';
import { pageFacts, factsJson } from './pageFacts';
import RuleEditModal from '../rules/RuleEditModal';
import { BLANK_RULE } from '../rules/constants';

import { withoutChips } from './chartSlots';

/**
 * The stand-in title for a URL whose hash is selected but whose name has not
 * arrived. `canLogUrl` compares against this exact string, so it stays the one
 * place the placeholder is spelled: put the hash here instead and the modal
 * offers a logging rule for a URL whose name has not arrived.
 *
 * @return {string} The translated placeholder.
 */
const UNKNOWN_URL = () => __( 'Unknown URL', 'newspack-event-logger-nodes' );

import './styles/modal.scss';
import './styles/tables.scss';
import './styles/charts.scss';

/** A window's layout, rendered again only when what it stands on moves. */
const WindowFrame = memo( ComposedPageFrame );

/** A held click has no element of its own, so only Escape dismisses it. */
const NO_ELEMENT = { current: null };

/**
 * A one-request click held until its request opens, which Escape cancels.
 *
 * @param {Object}     props          Component props.
 * @param {() => void} props.onCancel Cancels the held click.
 * @return {null} Nothing: it only listens.
 */
function HeldClick( { onCancel } ) {
	useDismissable( NO_ELEMENT, onCancel );
	return null;
}

/**
 * A search box with nothing answered under it: the lookup's miss or the grep's
 * error, the grep's rows and whether the server capped them, and the lines it
 * skipped as unparseable, undefined until a grep answers.
 */
const NO_SEARCH_ANSWER = {
	error: null,
	results: null,
	truncated: false,
	unparseableLines: undefined,
};

/** A query the search box looks up as one exact request id. */
const RID_SHAPE = /^[a-zA-Z0-9_-]+$/;

/**
 * Matched-request cap the request search sends with `grep_requests`. It
 * matches the command's own default, and the server clamps anything larger
 * to 50.
 */
const GREP_RESULT_LIMIT = 20;

/**
 * The window's header figures, each one `eln/url-detail` declares shown for
 * its answer.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.model The `eln/url-detail` model.
 * @return {import('react').ReactElement} The figures.
 */
function WindowStats( { model } ) {
	return (
		<div className="event-logger-header-stats newspack-nodes-stats-grid">
			{ namedFields( 'eln/url-detail', [], 'value', model ).map(
				( field ) => (
					<span key={ field.key } className="newspack-nodes-stat">
						{ display( field, fieldValue( field, model ) ) }
						<small className="newspack-nodes-stat-label">
							{ field.label }
						</small>
					</span>
				)
			) }
		</div>
	);
}

/**
 * The Performance page: the providers its layout, its chrome and its window
 * share, so every reader of one source on the page shares its one fetch.
 *
 * @param {Object}                    props                      Component props.
 * @param {(message: string) => void} props.onError              Ask-failure reporter, handed straight to `useAsk`, which calls it with the reason the ask failed.
 * @param {?Element}                  [props.headerControlsSlot] Shell header slot to portal the controls into; null while it is pending, undefined renders them inline.
 * @return {import('react').ReactElement} The page.
 */
export default function PerformancePage( props ) {
	return (
		<PageStateProvider>
			<SourcesProvider>
				<PerformanceSurface { ...props } />
			</SourcesProvider>
		</PageStateProvider>
	);
}

/**
 * The page under its providers: its header controls, its facts, its layout
 * or, on an edit load for a curator, the layout's editor, and the window.
 *
 * @param {Object}                    props                      Component props.
 * @param {(message: string) => void} props.onError              Ask-failure reporter.
 * @param {?Element}                  [props.headerControlsSlot] Shell header slot.
 * @return {import('react').ReactElement} The page.
 */
function PerformanceSurface( { onError, headerControlsSlot } ) {
	const { canTune, canManage, compositions } = composedPage();
	// Editing the URL window's layout opens no request: drop it unread.
	useState( () => {
		if (
			canTune &&
			URL_WINDOW ===
				editTarget( window.location.search, PERFORMANCE_PAGES )?.page
		) {
			setQueryParams( { request: null } );
		}
	} );
	const urlsSlice = useSource( 'eln/urls', useUrlsParams() ).model;
	const { servers: serverNames } = useSite();

	// @longform The server filter is page-wide scope. A server linked in other
	// capitals is the one the list names, and one the list does not name is
	// dropped once the list can judge it.
	const [ linkedServer, setServerFilter ] = useFilter( 'server' );
	const serverFilter =
		! serverNames?.length || serverNames.includes( linkedServer )
			? linkedServer
			: '';
	useEffect( () => {
		if ( serverFilter !== linkedServer ) {
			setServerFilter( serverFilter );
		}
	}, [ serverFilter, linkedServer, setServerFilter ] );

	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchAnswer, setSearchAnswer ] = useState( NO_SEARCH_ANSWER );

	// Read, never depended on: these keep the callbacks below stable.
	const selectSubjectRef = useRef(
		/** @type {( url: Object|null ) => void} */ ( () => {} )
	);
	const selectRequestRef = useRef(
		/** @type {( rid: string|null ) => void} */ ( () => {} )
	);
	// The catalog is a fresh array on every poll; `applyFoundRequest` is not.
	const urlsRef = useRef( [] );

	const urls = useMemo( () => urlsSlice?.data ?? [], [ urlsSlice?.data ] );
	// The filtered set's own numbers, computed once, server-side.
	const urlTotals = urlsSlice?.totals ?? null;
	// The same set's slowest, and what the set is, as the server applied it.
	const urlSlowest = urlsSlice?.slowest ?? null;
	const urlFilters = urlsSlice?.filters ?? null;
	urlsRef.current = urls;
	const {
		selectedSubject,
		selectedRequest,
		selectSubject,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		deepLink,
		clearDeepLink,
	} = useUrlNavigation( urls );

	// The window's state, held here: the modal unmounts for every request.
	const windowOpen = !! (
		selectedSubject ||
		deepLink.subject ||
		deepLink.requestId
	);
	// The list's order outlives the window, kept off the bar while it is shut.
	const sortParam = { written: windowOpen };
	const [ sortField, setSortField ] = useQueryParamChoice(
		'url_sort',
		REQUEST_SORT_FIELDS,
		DEFAULT_REQUEST_SORT.field,
		sortParam
	);
	const [ sortDir, setSortDir ] = useQueryParamChoice(
		'url_order',
		SORT_ORDERS,
		DEFAULT_REQUEST_SORT.dir,
		sortParam
	);
	const requestSort = useMemo(
		() => ( { field: sortField, dir: sortDir } ),
		[ sortField, sortDir ]
	);
	/**
	 * Sort the URL window's list by one column, as `nextSort()` orders it.
	 *
	 * @param {string} field Field key to sort by.
	 */
	const sortRequests = useCallback(
		( field ) => {
			const next = nextSort( requestSort, field );
			setSortDir( next.dir );
			setSortField( next.field );
		},
		[ requestSort, setSortField, setSortDir ]
	);
	// What a window leave asks while the window's editor holds a change.
	const windowGuard = useRef( null );
	/**
	 * Hold every leave of the window — its close, Back to the URL, the
	 * browser's Back, a search or a table row — to what `guard` answers,
	 * until the returned release runs.
	 *
	 * @param {(next: {leave: () => void}) => boolean} guard What a leave is told.
	 * @return {() => void} Withdraws it.
	 */
	const guardWindowLeave = useCallback( ( guard ) => {
		windowGuard.current = guard;
		const releasePop = holdPop(
			( incoming, proceed ) => ! guard( { leave: proceed } )
		);
		return () => {
			releasePop();
			if ( windowGuard.current === guard ) {
				windowGuard.current = null;
			}
		};
	}, [] );
	/**
	 * Leave the window as `leave` does, once its guard, if one stands, agrees;
	 * where it asks the curator first, `leave` runs on a Discard alone.
	 *
	 * @param {() => void} leave The leave.
	 */
	const leaveWindow = useCallback( ( leave ) => {
		const guard = windowGuard.current;
		if ( ! guard || guard( { leave } ) ) {
			leave();
		}
	}, [] );
	// The window's narrowing and chart, which a new or shut window resets.
	const [ detailBucket, setDetailBucket ] = usePageState( 'url_buckets', {
		writes: true,
	} );
	const [ detailChips, setDetailChips ] = usePageState( 'url_chips', {
		writes: true,
	} );
	const [ , setDetailMetric ] = usePageState( 'url_metric', {
		writes: true,
	} );
	const [ , setDetailBreakdown ] = usePageState( 'url_breakdown', {
		writes: true,
	} );
	const [ , setDetailThenBy ] = usePageState( 'url_then', {
		writes: true,
	} );
	// Same values, same object: the graph re-asks on a new one.
	const detailFilters = useMemo(
		() => ( { bucket: detailBucket } ),
		[ detailBucket ]
	);
	// @longform The window's reads, asking nothing until its subject answers:
	// the URL's answer stays on screen while a narrowed one is asked, but
	// never under another subject's title, and a hash no command may carry
	// shows as the window's refusal instead of asking. Its blocks read the
	// same keys, so one fetch serves the header and the body.
	const urlDetailSlice = useUrlDetail( !! selectedSubject );
	const requestDetailSlice = useRequestDetail( !! selectedRequest );
	const urlDetail = urlDetailSlice.data;
	const requestDetail = requestDetailSlice.data;
	// The URL window's model on screen, which a one-request click records.
	const urlDetailSliceRef = useRef( urlDetailSlice );
	urlDetailSliceRef.current = urlDetailSlice;
	/**
	 * Reset the window for a URL opened or closed: its bucket and chips as
	 * given, its chart and metric on the defaults. The request sort stays.
	 *
	 * @param {string}   bucket The bucket selection's spelling; '' for none.
	 * @param {string[]} chips  The chips, or `NO_CHIPS`.
	 */
	const resetDetail = useCallback(
		( bucket, chips ) => {
			setDetailBucket( bucket );
			setDetailChips( chips );
			setDetailBreakdown( WINDOW_BREAKDOWN );
			setDetailThenBy( '' );
			setDetailMetric( DEFAULT_CHART_METRIC );
		},
		[
			setDetailBucket,
			setDetailChips,
			setDetailBreakdown,
			setDetailThenBy,
			setDetailMetric,
		]
	);
	// A shut window forgets how it was narrowed, however it shut.
	useEffect( () => {
		if ( ! windowOpen ) {
			resetDetail( '', NO_CHIPS );
		}
	}, [ windowOpen, resetDetail ] );

	selectSubjectRef.current = selectSubject;
	selectRequestRef.current = baseSelectRequest;
	// @longform What is open RIGHT NOW, for the reply handlers below: a reply
	// must not yank the operator back to a URL they have already left. Written
	// where the selection is MADE as well as on render, because a reply can
	// land before React has re-rendered the selection that preceded it.
	const selectedSubjectRef = useRef( selectedSubject );
	selectedSubjectRef.current = selectedSubject;
	/**
	 * Select a subject, recording it in `selectedSubjectRef` in the same call.
	 *
	 * @param {?Object} subject The `{field, hash, url}` to open, or null to close.
	 */
	const selectSubjectNow = useCallback( ( subject ) => {
		selectedSubjectRef.current = subject;
		selectSubjectRef.current( subject );
	}, [] );

	// @longform One picker, several doors. A `url:` or `request:` brief is
	// about what is selected NOW, as `dump_url` is. The page's own brief is
	// about the rows on screen, so it takes the echoed `urlFilters` — the
	// filters those rows were fetched under — and the live pick only until
	// the first reply. An open URL modal lists under its own chips and
	// bucket, so a brief asked there narrows as its list does. Above the
	// graph, which holds its poll while armed.
	const askFilters = useMemo(
		() =>
			selectedSubject
				? {
						...urlFilters,
						...detailFilters,
						filter: detailChips,
				  }
				: urlFilters,
		[ selectedSubject, urlFilters, detailFilters, detailChips ]
	);
	const ask = useAsk( { onError, serverFilter, urlFilters: askFilters } );

	// The page holds still while the picker is armed.
	useSourcesPaused( !! ( selectedSubject || selectedRequest || ask.active ), [
		GROUPS.overview,
	] );

	// Where the URL detail was scrolled to, restored when the request closes.
	const urlDetailScrollRef = useRef( 0 );
	// @longform A one-request row just opened, until its window answers: its
	// subject, and the window's model at the click, which answers another URL.
	const [ oneRequest, setOneRequest ] = useState( null );

	/**
	 * Open one of the URL's requests inside the modal, or return to the URL
	 * detail.
	 *
	 * @param {?string} rid The request id to open, or null to go back.
	 */
	const selectRequest = useCallback(
		( rid ) => {
			if ( rid ) {
				// Entering request detail — save current scroll position.
				const modalContent = document.querySelector(
					'.event-logger-performance-modal .components-modal__content'
				);
				if ( modalContent ) {
					urlDetailScrollRef.current = modalContent.scrollTop;
				}
			}
			baseSelectRequest( rid );
		},
		[ baseSelectRequest ]
	);

	/**
	 * Open the subject a row names, leaving behind whatever request was open
	 * inside the previous one. A row picked from a
	 * narrowed table opens narrowed the same way: on the bucket and the chips
	 * its rows were fetched under, the echo rather than the live pick, less a
	 * chip on the modal's own field, since a chip on another URL or page
	 * would chart that one instead. A row of one request goes on to that
	 * request once the window names its rid.
	 *
	 * @param {Object} row The row: a page or a shape, with its `count`.
	 */
	const openSubject = useCallback(
		( row ) =>
			leaveWindow( () => {
				const subject = subjectOfRow( row );
				setOneRequest(
					1 === row.count
						? { subject, before: urlDetailSliceRef.current }
						: null
				);
				selectRequest( null );
				const chips = withoutChips(
					urlFilters?.filter ?? [],
					subject.field
				);
				resetDetail(
					urlFilters?.bucket ?? '',
					chips.length ? chips : NO_CHIPS
				);
				selectSubjectNow( subject );
			} ),
		[
			leaveWindow,
			selectRequest,
			selectSubjectNow,
			urlFilters,
			resetDetail,
		]
	);

	// @longform A row of one request opens that request, its rid named by the
	// window's own dump_url, only when that answer counts exactly one; any
	// other answer, or a refusal, paints the window. Until then the window is
	// held unpainted, the row spins with aria-busy, and Escape cancels and
	// drops ?url=. An answer names its hash in `stats`; a refusal names none,
	// but the Current gate passes only the held ask's, so any refusal
	// published since the click is its.
	useEffect( () => {
		if ( ! oneRequest ) {
			return;
		}
		const { subject, before } = oneRequest;
		if ( ! sameSubject( selectedSubject, subject ) || selectedRequest ) {
			setOneRequest( null );
			return;
		}
		if ( urlDetailSlice !== before && urlDetailSlice?.error ) {
			setOneRequest( null );
			return;
		}
		if ( urlDetail?.stats?.hash !== subject.hash ) {
			return;
		}
		setOneRequest( null );
		// The row may be stale; the answer says whether it is one request.
		const rid =
			1 === urlDetail.stats.count ? urlDetail.requests?.[ 0 ]?.rid : null;
		if ( rid ) {
			selectRequest( rid );
		}
	}, [
		oneRequest,
		urlDetail,
		urlDetailSlice,
		selectedSubject,
		selectedRequest,
		selectRequest,
	] );
	const holdingWindow =
		null !== oneRequest &&
		sameSubject( selectedSubject, oneRequest.subject ) &&
		! selectedRequest;
	/**
	 * Cancel a held click: the window never opens, and the bar drops `?url=`.
	 */
	const cancelHold = useCallback( () => {
		setOneRequest( null );
		selectSubject( null );
	}, [ selectSubject ] );

	/**
	 * Ask `dump_url` for the title of a hash the loaded catalog page does not
	 * carry.
	 *
	 * A hash becomes a title in two steps that need no pairing: select what is
	 * already known, then ask for the name. The reply names the subject it
	 * answered, which is how the guard below tells whose title it is.
	 */
	const { run: lookupUrl } = useCommandOnce( {
		group: GROUPS.url,
		ci: SERVER,
		command: 'dump_url',
		scope: 'url-lookup',
		retry: true,
		onDone: ( { result, subject } ) => {
			const url = result?.stats?.url;
			const current = selectedSubjectRef.current;
			// @longform Only the selection this answers: the operator may
			// have opened another URL while it was in flight, and yanking
			// them back to the one they left beats an untitled hash.
			if ( url && current && subject === subjectArgs( current )[ 0 ] ) {
				selectSubjectNow( { ...current, url } );
			}
		},
	} );

	/**
	 * Open a request the server has just located, showing it now and letting the
	 * title fill in when `lookupUrl` answers. It opens inside `linked`, the
	 * subject a link named beside it, and inside its own URL when `linked` is null.
	 *
	 * @param {string}  rid      The request id that was found.
	 * @param {Object}  data     The `search_requests` reply, carrying `url_hash`.
	 * @param {?Object} [linked] The `{ field, hash }` the link opens it under.
	 */
	const applyFoundRequest = useCallback(
		( rid, data, linked = null ) => {
			const found = linked ?? { field: 'url', hash: data.url_hash };
			const known = findSubject( urlsRef.current, found );
			// @longform A rid names ONE request; the server filter is a
			// browsing scope, and `dump_url` honours it. A search landing
			// outside it would ask for a row the scope excludes and answer
			// "URL not found" for a URL plainly on screen. The navigation
			// wins, and the select resets so nothing is hidden.
			setServerFilter( '' );
			selectSubjectNow( known ?? { ...found, url: UNKNOWN_URL() } );
			selectRequestRef.current( rid );
			if ( ! known ) {
				lookupUrl( subjectArgs( found ) );
			}
		},
		[ lookupUrl, selectSubjectNow, setServerFilter ]
	);

	/**
	 * Whether a `search_requests` reply located the request.
	 *
	 * @param {?Object} data The reply payload.
	 * @return {boolean} True when it carries the URL hash.
	 */
	const found = ( data ) => Boolean( data?.url_hash );

	/**
	 * Resolve a `?request=` deep link. Only the server can answer for a rid,
	 * whose URL nothing on the page knows.
	 *
	 * A RETRIED read: an undelivered send is asked again while the link still
	 * stands, and a not-found is an answer that ends it.
	 */
	const { run: askDeepLinkRequest } = useCommandOnce( {
		group: GROUPS.request,
		ci: SERVER,
		command: 'search_requests',
		scope: 'request-deeplink',
		retry: true,
		onDone: ( { result, args } ) => {
			if ( deepLinkRef.current.requestId !== args[ 0 ] ) {
				return;
			}
			if ( found( result ) ) {
				// The bar still names the window the request was opened in.
				applyFoundRequest( args[ 0 ], result, linkedSubject() );
				clearDeepLinkRef.current();
				return;
			}
			// Not-found is an ANSWER; let the linked subject have its turn.
			clearDeepLinkRef.current( 'request' );
		},
	} );

	/**
	 * Resolve a `?url=` or `?page_hash=` deep link, which needs the server
	 * whenever the subject is missing from the loaded catalog page, and so
	 * titles it.
	 */
	const { run: askDeepLinkSubject } = useCommandOnce( {
		group: GROUPS.url,
		ci: SERVER,
		command: 'dump_url',
		scope: 'url-deeplink',
		retry: true,
		onDone: ( { result, subject } ) => {
			const linked = deepLinkRef.current.subject;
			if ( ! linked || subject !== subjectArgs( linked )[ 0 ] ) {
				return;
			}
			selectSubjectNow( {
				...linked,
				url: result?.stats?.url || UNKNOWN_URL(),
			} );
			clearDeepLinkRef.current();
		},
	} );

	const clearDeepLinkRef = useRef( clearDeepLink );
	clearDeepLinkRef.current = clearDeepLink;
	// @longform Apply only what is STILL asked for. Both resolvers are retried
	// reads, so their reply lands well after the operator may have closed the
	// modal or walked Back — and an answer to a cancelled question reopened
	// what they left, pushing a history entry over the one they walked to.
	const deepLinkRef = useRef( deepLink );
	deepLinkRef.current = deepLink;

	// The rid answers its URL's hash; the linked subject is the fallback.
	useEffect( () => {
		if ( deepLink.requestId ) {
			askDeepLinkRequest( formatCommandArgs( [ deepLink.requestId ] ) );
			return;
		}
		if ( deepLink.subject ) {
			askDeepLinkSubject( subjectArgs( deepLink.subject ) );
		}
	}, [ deepLink, askDeepLinkRequest, askDeepLinkSubject ] );

	/**
	 * Look one exact request id up in the request index and open it. A RETRIED
	 * read: a second lookup supersedes the first, and a miss is an answer that
	 * fills the search box's error instead of being re-asked.
	 */
	const {
		run: searchForRequest,
		abandon: abandonRequestSearch,
		pending: requestSearchPending,
	} = useCommandOnce( {
		group: GROUPS.request,
		ci: SERVER,
		command: 'search_requests',
		scope: 'request-search',
		retry: true,
		onDone: ( { result, args } ) => {
			if ( ! found( result ) ) {
				setSearchAnswer( {
					...NO_SEARCH_ANSWER,
					error: sprintf(
						// translators: %s: the request ID that was searched for.
						__(
							'Request "%s" not found — prefix with / to search recent traffic',
							'newspack-event-logger-nodes'
						),
						args[ 0 ]
					),
				} );
				return;
			}
			leaveWindow( () => {
				applyFoundRequest( args[ 0 ], result );
				// A narrowed window could hide the very request searched for.
				resetDetail( '', NO_CHIPS );
				setSearchQuery( '' );
				// Drops ?search= too; the selection's push then adds nothing.
				setQueryParams(
					{
						search: null,
						...subjectLink( {
							field: 'url',
							hash: result.url_hash,
						} ),
						request: args[ 0 ],
					},
					{ push: true }
				);
			} );
		},
	} );

	/**
	 * Run the exact-id search: from the box, from a `?search=` link, or from
	 * a pattern-search row, which carries the rid alone, while the URL hash
	 * the modal needs comes only from `search_requests`. The last
	 * answer clears, so a stale error or list never sits under it in flight.
	 *
	 * @param {string} rid The request id to look up.
	 */
	const searchRequest = useCallback(
		( rid ) => {
			if ( ! rid || ! rid.trim() ) {
				return;
			}
			setSearchAnswer( NO_SEARCH_ANSWER );
			searchForRequest( formatCommandArgs( [ rid.trim() ] ) );
		},
		[ searchForRequest ]
	);

	/**
	 * Scan the recent firehose window for a pattern, capped at
	 * `GREP_RESULT_LIMIT` matching requests. No match reports through the search
	 * box's error line, so an empty list never sits there unexplained.
	 */
	const {
		run: requestGrep,
		abandon: abandonGrep,
		pending: grepPending,
	} = useCommandOnce( {
		group: GROUPS.performance,
		ci: SERVER,
		command: 'grep_requests',
		scope: 'grep-requests',
		// A search pattern is free text the operator typed, not an identity.
		subjectOf: () => null,
		onDone: ( { result, error } ) => {
			const results = result?.results ?? [];
			const matched = results.length > 0;
			setSearchAnswer( {
				error: matched
					? null
					: error ||
					  __(
							'No matches in recent traffic',
							'newspack-event-logger-nodes'
					  ),
				results: matched ? results : null,
				truncated: matched && !! result?.truncated,
				unparseableLines: result?.unparseable_lines,
			} );
		},
	} );

	/**
	 * Pattern-search recent firehose traffic and render the matching-request
	 * list. The pattern rides by name, so one opening `--` is never an option.
	 *
	 * @param {string} pattern The search pattern.
	 */
	const patternSearch = useCallback(
		( pattern ) => {
			setSearchAnswer( NO_SEARCH_ANSWER );
			requestGrep(
				formatCommandArgs( [], {
					pattern,
					limit: GREP_RESULT_LIMIT,
				} )
			);
		},
		[ requestGrep ]
	);

	/**
	 * Route the search box's submit. A rid-shaped token is an exact request
	 * lookup against the index; anything else — a URL or free text — is a
	 * pattern search over recent traffic.
	 *
	 * @param {string} query The raw search input.
	 */
	const handleSearch = useCallback(
		( query ) => {
			const trimmed = ( query || '' ).trim();
			if ( ! trimmed ) {
				return;
			}
			if ( RID_SHAPE.test( trimmed ) ) {
				searchRequest( trimmed );
			} else {
				patternSearch( trimmed );
			}
		},
		[ searchRequest, patternSearch ]
	);

	/**
	 * Take the search box's text. Any edit abandons the search in flight, a
	 * clicked row's lookup included, so its answer fills nothing; emptying the
	 * box also drops the last answer, which answers nothing under a blank box.
	 *
	 * @param {string} query The box's new text.
	 */
	const changeSearchQuery = useCallback(
		( query ) => {
			setSearchQuery( query );
			abandonRequestSearch();
			abandonGrep();
			if ( '' === query ) {
				setSearchAnswer( NO_SEARCH_ANSWER );
			}
		},
		[ abandonRequestSearch, abandonGrep ]
	);

	// Inline "Log this URL" state: the open draft, the ruleset, and the error.
	const [ ruleDraft, setRuleDraft ] = useState( null );
	// The whole ruleset as the server last reported it; null until it answers.
	const [ rules, setRules ] = useState( null );
	const [ ruleError, setRuleError ] = useState( null );

	// @longform A one-shape page's row names no URL, so its window is named by
	// what dump_url answers for its hash, never by another URL's answer.
	const ruleUrl =
		selectedSubject?.url ||
		( urlDetail?.stats?.hash === selectedSubject?.hash
			? urlDetail?.stats?.url
			: undefined );
	const canLogUrl = !! ruleUrl && UNKNOWN_URL() !== ruleUrl;
	// The path a rule matches it by; '?' marks it exact.
	const exactPattern = `${ rulePath( ruleUrl ) }?`;

	/**
	 * Read the whole ruleset. A retried READ, and `existingRule` is DERIVED from
	 * the answer on every render rather than copied into state, so the button's
	 * label and the draft it opens always agree with the ruleset last read.
	 */
	const { run: dumpRules } = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'dump',
		retry: true,
		onDone: ( { result } ) => setRules( result?.rules ?? null ),
	} );
	const existingRule =
		canLogUrl && ! selectedRequest && rules
			? rules.find( ( r ) => r.pattern === exactPattern ) ?? null
			: null;

	// A new URL is a new rule question: drop the old draft and re-read.
	useEffect( () => {
		setRuleError( null );
		setRuleDraft( null );
		if ( canLogUrl && ! selectedRequest ) {
			dumpRules( [] );
		}
	}, [ ruleUrl, exactPattern, canLogUrl, selectedRequest, dumpRules ] );

	/**
	 * Open `RuleEditModal` on the exact rule for this URL, or on a blank draft
	 * seeded with its pattern when the URL does not match any rule.
	 */
	const openRuleEditor = useCallback( () => {
		if ( ! canLogUrl ) {
			return;
		}
		setRuleError( null );
		setRuleDraft(
			existingRule ?? {
				...BLANK_RULE,
				pattern: exactPattern,
				action: 'log',
			}
		);
	}, [ canLogUrl, existingRule, exactPattern ] );

	/**
	 * Write one rule. The reply closes the editor or fills the banner, and a
	 * success re-reads the ruleset rather than patching the copy in hand.
	 */
	const { run: upsertRule } = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'upsert',
		sendsOnRemoval: true,
		// The rule DOCUMENT is the first token; the rule it names is the id.
		subjectOf: ( [ rule ] ) => JSON.parse( rule ).id ?? null,
		onDone: ( { result, error } ) => {
			setRuleDraft( null );
			if ( result ) {
				dumpRules( [] );
				return;
			}
			setRuleError(
				error ||
					__(
						'Could not save the logging rule.',
						'newspack-event-logger-nodes'
					)
			);
		},
	} );
	/**
	 * Save the open draft, sending the rule as one JSON document.
	 *
	 * @param {Object} draft The rule as `RuleEditModal` hands it back.
	 */
	const saveRule = useCallback(
		( draft ) => upsertRule( [ JSON.stringify( draft ) ] ),
		[ upsertRule ]
	);

	/**
	 * Delete one rule by id, on the same reply contract as `upsertRule`.
	 */
	const { run: removeRule } = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'delete',
		sendsOnRemoval: true,
		onDone: ( { result, error } ) => {
			setRuleDraft( null );
			if ( result ) {
				dumpRules( [] );
				return;
			}
			setRuleError(
				error ||
					__(
						'Could not delete the logging rule.',
						'newspack-event-logger-nodes'
					)
			);
		},
	} );
	/**
	 * Delete the rule the open draft names. The editor offers this only for a
	 * draft that already has an id, so a blank "Log this URL" draft cannot.
	 */
	const deleteRule = useCallback(
		() => removeRule( [ ruleDraft?.id ] ),
		[ removeRule, ruleDraft ]
	);

	// Handle initial search query from URL parameter.
	useEffect( () => {
		if ( initialSearchQuery ) {
			searchRequest( initialSearchQuery );
			setInitialSearchQuery( null );
		}
	}, [ initialSearchQuery, searchRequest, setInitialSearchQuery ] );

	// Manage modal scroll when switching URL detail ↔ request detail.
	useEffect( () => {
		const modalContent = document.querySelector(
			'.event-logger-performance-modal .components-modal__content'
		);
		if ( ! modalContent ) {
			return;
		}
		if ( selectedRequest ) {
			modalContent.scrollTop = 0;
		} else {
			requestAnimationFrame( () => {
				modalContent.scrollTop = urlDetailScrollRef.current;
			} );
		}
	}, [ selectedRequest ] );

	const standing = {
		openSubject,
		selectedSubject,
		busySubject: holdingWindow ? oneRequest.subject : null,
	};

	// The request window's log publishes its `reveal` here for the flame.
	const revealRef = useRef( null );
	// A link straight to a request asks no URL chart while it is being found.
	const windowAsks = ! deepLink.requestId;
	// One object while its inputs hold: no poll behind it re-renders a block.
	const windowStanding = useMemo(
		() => ( {
			selectRequest,
			revealRef,
			requestSort,
			sortRequests,
			windowAsks,
		} ),
		[ selectRequest, requestSort, sortRequests, windowAsks ]
	);
	// The window header's box its layout menu goes in, once it mounts.
	const [ windowSlot, setWindowSlot ] = useState( null );
	/**
	 * A window's layout, standing on the window and its header's box.
	 *
	 * @param {string} context The window's context.
	 * @param {string} page    Its name, which `?edit=` names.
	 * @param {string} group   The surface its commands are sent for.
	 * @return {import('react').ReactElement} The layout.
	 */
	const windowLayout = ( context, page, group ) => (
		<WindowFrame
			context={ context }
			page={ page }
			pages={ PERFORMANCE_PAGES }
			group={ group }
			canTune={ canTune }
			canManage={ canManage }
			layout={ compositions[ context ] }
			views={ BLOCK_VIEWS }
			standing={ windowStanding }
			headerControlsSlot={ windowSlot }
			guardLeave={ guardWindowLeave }
		/>
	);
	// The box closing each header's actions, which the layout menu fills.
	const windowSlotBox = (
		<div ref={ setWindowSlot } style={ { display: 'contents' } } />
	);

	return (
		<div className="event-logger-performance-dashboard">
			{ /* Facts only, no instructions: anything reading this page gets
			     clean numbers instead of a scraped table. Rendered only for
			     someone who can already see the dashboard. */ }
			<script
				type="application/json"
				id="newspack-nodes-page-facts"
				// eslint-disable-next-line react/no-danger
				dangerouslySetInnerHTML={ {
					__html: factsJson(
						pageFacts( {
							urlTotals,
							urlSlowest,
							urlFilters,
							selectedSubject: selectedSubject && {
								...selectedSubject,
								url: ruleUrl,
							},
							urlDetail,
							detailChips,
							detailFilters,
							selectedRequest,
							requestDetail,
						} )
					),
				} }
			/>

			<ComposedPageFrame
				context={ PERFORMANCE_CONTEXT }
				page={ PERFORMANCE_PAGE }
				pages={ PERFORMANCE_PAGES }
				group={ GROUPS.performance }
				canTune={ canTune }
				canManage={ canManage }
				layout={ compositions[ PERFORMANCE_CONTEXT ] }
				views={ BLOCK_VIEWS }
				standing={ standing }
				headerControlsSlot={ headerControlsSlot }
				controls={
					<PerformanceHeader
						ask={ ask }
						searchQuery={ searchQuery }
						setSearchQuery={ changeSearchQuery }
						searchLoading={ requestSearchPending || grepPending }
						searchError={ searchAnswer.error }
						onSearch={ handleSearch }
					/>
				}
			>
				<SearchFindings
					results={ searchAnswer.results }
					truncated={ searchAnswer.truncated }
					unparseableLines={ searchAnswer.unparseableLines }
					onSelect={ searchRequest }
				/>
			</ComposedPageFrame>

			{ holdingWindow && <HeldClick onCancel={ cancelHold } /> }

			{ /* URL/Request Detail Modal */ }
			{ /* The SELECTION opens this, never a slice: each pane below owns
			     its own empty state, and a modal that can close is the only way
			     back out of one. A one-request row holds it until its request
			     is known, so it opens on the request. */ }
			{ selectedSubject && ! holdingWindow && (
				<Modal
					title={
						selectedRequest && requestDetail
							? sprintf(
									// translators: %s: the request ID.
									__(
										'Request: %s',
										'newspack-event-logger-nodes'
									),
									selectedRequest
							  )
							: requestedUrl( ruleUrl )
					}
					onRequestClose={ () => {
						// Keep URL modal open while the nested editor is open.
						if ( ruleDraft ) {
							return;
						}
						leaveWindow( () => {
							selectSubject( null );
							selectRequest( null );
						} );
					} }
					className={ `event-logger-performance-modal newspack-nodes-modal ${ SKIN_ROOT_CLASSES }` }
					headerActions={
						selectedRequest ? (
							<>
								<AskButton ask={ ask } />
								<button
									type="button"
									className="button is-plain event-logger-modal-back-button"
									onClick={ () =>
										leaveWindow( () =>
											selectRequest( null )
										)
									}
									aria-label={ __(
										'Back to URL details',
										'newspack-event-logger-nodes'
									) }
								>
									&larr;
								</button>
								{ windowSlotBox }
							</>
						) : (
							<>
								{ urlDetail && (
									<WindowStats
										model={ urlDetailSlice.model }
									/>
								) }
								<AskButton ask={ ask } />
								<div className="event-logger-rule-control">
									<button
										type="button"
										className="button"
										// @longform Disabled until the
										// ruleset is KNOWN: with `rules`
										// still null the button reads "Log
										// this URL" and opens a blank draft,
										// and an id-less upsert matches by
										// PATTERN — so saving it would
										// replace a configured rule's hooks
										// and thresholds with nothing.
										disabled={
											! canLogUrl || null === rules
										}
										onClick={ openRuleEditor }
									>
										{ existingRule
											? __(
													'Edit logging rule',
													'newspack-event-logger-nodes'
											  )
											: __(
													'Log this URL',
													'newspack-event-logger-nodes'
											  ) }
									</button>
									{ ruleError && (
										<span className="event-logger-rule-error newspack-nodes-status is-error">
											{ ruleError }
										</span>
									) }
								</div>
								{ windowSlotBox }
							</>
						)
					}
				>
					{ /* URL Details View */ }
					{ ! selectedRequest && ! urlDetail && (
						<p
							className={ `newspack-nodes-status${
								urlDetailSlice?.error ? ' is-error' : ''
							}` }
						>
							{ urlDetailSlice?.error
								? sprintf(
										// translators: %s: the error message.
										__(
											'Could not load this URL: %s',
											'newspack-event-logger-nodes'
										),
										urlDetailSlice.error
								  )
								: __(
										'Loading URL…',
										'newspack-event-logger-nodes'
								  ) }
						</p>
					) }

					{ /* Each window's layout stands while the window does. */ }
					{ ! selectedRequest && (
						<div
							className="event-logger-sections"
							data-ask={ subjectDescriptor( selectedSubject ) }
						>
							{ windowLayout(
								URL_WINDOW_CONTEXT,
								URL_WINDOW,
								GROUPS.url
							) }
						</div>
					) }

					{ /* A selected request with nothing to show is a state,
					     never a blank panel. */ }
					{ selectedRequest && ! requestDetail && (
						<p
							className={ `newspack-nodes-status${
								requestDetailSlice?.error ? ' is-error' : ''
							}` }
						>
							{ requestDetailSlice?.error
								? sprintf(
										// translators: %s: the error message.
										__(
											'Could not load this request: %s',
											'newspack-event-logger-nodes'
										),
										requestDetailSlice.error
								  )
								: __(
										'Loading request…',
										'newspack-event-logger-nodes'
								  ) }
						</p>
					) }
					{ /* The picker chain: DOM nesting names the request. */ }
					{ selectedRequest && (
						<div
							className="event-logger-request-detail event-logger-sections"
							data-ask={ `request:${ selectedRequest }` }
						>
							{ windowLayout(
								REQUEST_WINDOW_CONTEXT,
								REQUEST_WINDOW,
								GROUPS.request
							) }
						</div>
					) }
				</Modal>
			) }

			{ /* Inline rule editor for "Log this URL" (URL-detail view only). */ }
			{ selectedSubject && ! selectedRequest && ruleDraft && (
				<RuleEditModal
					rule={ ruleDraft }
					onSave={ saveRule }
					onCancel={ () => setRuleDraft( null ) }
					onDelete={ ruleDraft.id ? deleteRule : undefined }
				/>
			) }

			{ /* The `?` picker's answer, last: it is summoned from the modals
			     above and has to paint over whichever one is open. */ }
			<AskPanel ask={ ask } />
		</div>
	);
}
