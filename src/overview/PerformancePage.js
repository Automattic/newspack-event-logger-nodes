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
 * The window's own controls live in the address bar under a `url_` prefix
 * while it is open, as `docs/dashboards.md` lists. The page runs every
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
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import ComposedPageFrame from '@newspack-nodes/shared/composition/page/ComposedPageFrame';
import { PageStateProvider } from '@newspack-nodes/shared/composition/page/pageState';
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
import {
	computeIndentedEntries,
	spliceFoldedSpans,
} from './utils/logEntryUtils';
import {
	CHART_METRIC_OPTIONS,
	DEFAULT_CHART_METRIC,
	GROUPS,
	SCOPED_BREAKDOWN_OPTIONS,
	SORT_ORDERS,
	SUBJECT_CHIP_OPTIONS,
} from './constants';
import { BLOCK_VIEWS, NO_CHIPS, RULES_CI, SERVER } from './sources';
import { isValidHash, isValidRequestId } from './questions';
import { useFilter, useSite, useUrlsParams } from './blocks/pageQuestions';
import {
	PERFORMANCE_CONTEXT,
	PERFORMANCE_PAGE,
	PERFORMANCE_PAGES,
} from './performancePageData';
import { composedPage } from '../composedPageData';
import useUrlNavigation from './hooks/useUrlNavigation';
import {
	findSubject,
	linkedSubject,
	sameSubject,
	subjectArgs,
	subjectLink,
	subjectOfRow,
} from './detailSubject';
import {
	useQueryParamChoice,
	useQueryParamList,
	useQueryParamState,
} from '@newspack-nodes/shared/hooks/useQueryParamState';
import { setQueryParams } from '@newspack-nodes/shared/utils/queryParams';
import { requestedUrl, rulePath } from '../log-table/urlIdentity';
import { useDismissable } from '@newspack-nodes/shared/hooks/useDismissable';
import { chipRefusal, chipsOf } from './components/BreakdownControls';
import UrlDetailView, {
	DEFAULT_REQUEST_SORT,
	REQUEST_SORT_FIELDS,
} from './components/UrlDetailView';
import RequestDetailView from './components/RequestDetailView';
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
 * Order two request rows by one column in one direction. A null duration,
 * one `dump_url` measured none of (decision 24), ranks LAST either way, as
 * the `urls` command ranks a URL without a timed request; such rows keep their
 * order among themselves. A row missing any other field counts as 0.
 *
 * @param {Object}                       a    A `dump_url` request row.
 * @param {Object}                       b    Another.
 * @param {{field: string, dir: string}} sort The column and `asc` or `desc`.
 * @return {number} The comparator's answer.
 */
const compareRequests = ( a, b, { field, dir } ) => {
	if ( 'duration_ms' === field ) {
		const aMeasured = 'number' === typeof a.duration_ms;
		if ( aMeasured !== ( 'number' === typeof b.duration_ms ) ) {
			return aMeasured ? -1 : 1;
		}
		if ( ! aMeasured ) {
			return 0;
		}
	}
	const aVal = a[ field ] ?? 0;
	const bVal = b[ field ] ?? 0;
	if ( aVal === bVal ) {
		return 0;
	}
	return ( aVal > bVal ? 1 : -1 ) * ( 'asc' === dir ? 1 : -1 );
};

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
 * The URL window chart's question until someone changes it: its status
 * classes, no second dimension and no chip.
 */
const NO_DETAIL_CHART = {
	breakdown: 'status_class',
	thenBy: '',
	chips: NO_CHIPS,
};

// The address-bar whitelists: what the dropdowns offer.
const CHART_METRICS = CHART_METRIC_OPTIONS.map( ( option ) => option.value );
const DETAIL_BREAKDOWNS = SCOPED_BREAKDOWN_OPTIONS.map(
	( option ) => option.value
);

/**
 * The Then by choices under a Breakdown, as its select offers them: none, or
 * any other dimension.
 *
 * @param {string[]} breakdowns Every dimension the chart offers.
 * @param {string}   breakdown  The Breakdown chosen.
 * @return {string[]} The Then by whitelist.
 */
const thenBysUnder = ( breakdowns, breakdown ) => [
	'',
	...breakdowns.filter( ( axis ) => axis !== breakdown ),
];

/**
 * The linked chip values the chip editor would take, spelled as it commits
 * them and each once; the rest drop, a `server=` among them, since the
 * Server select owns the server.
 *
 * @param {string[]}                              raw     Every value the
 *                                                        param holds, each
 *                                                        `field=value`.
 * @param {Array<{label: string, value: string}>} options The fields the
 *                                                        editor offers.
 * @return {string[]} The chips kept, or `NO_CHIPS` for none.
 */
const restoreChips = ( raw, options ) => {
	const kept = chipsOf(
		raw.filter( ( chip ) => null === chipRefusal( chip, options ) )
	);
	return kept.length ? kept : NO_CHIPS;
};

/**
 * The window's charts: the subject's requests by the window chart's
 * dimensions, and its categories, each read only while the URL view shows.
 *
 * @param {Omit<Parameters<typeof UrlDetailView>[0], 'chartSlice'|'categoriesSlice'> & {serverFilter: string}} props `UrlDetailView`'s props, less the two chart slices, and the page's server filter.
 * @return {import('react').ReactElement} The URL view.
 */
function UrlWindow( { serverFilter, ...props } ) {
	const { subject, chart } = props;
	const scope = { field: subject.field, hash: subject.hash };
	const series = useSource( 'eln/url-chart', {
		subject: scope,
		server: serverFilter,
		chips: chart.chips,
		breakdown: chart.breakdown,
		then: chart.thenBy,
	} );
	const categories = useSource( 'eln/url-categories', {
		subject: scope,
		server: serverFilter,
	} );
	return (
		<UrlDetailView
			{ ...props }
			chartSlice={ {
				data: series.model?.data ?? null,
				loading: series.loading,
				error: series.error,
			} }
			categoriesSlice={ {
				data: categories.model?.data ?? null,
				loading: categories.loading,
				error: categories.error,
			} }
		/>
	);
}

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
	// The window's state stays off the bar while it is shut.
	const windowParam = { written: windowOpen };
	const [ detailBucket, setDetailBucket ] = useQueryParamState(
		'url_bucket',
		( raw ) => raw ?? '',
		String,
		windowParam
	);
	const [ detailMetric, setDetailMetric ] = useQueryParamChoice(
		'url_metric',
		CHART_METRICS,
		DEFAULT_CHART_METRIC,
		windowParam
	);
	const [ detailBreakdown, setDetailBreakdown ] = useQueryParamChoice(
		'url_breakdown',
		DETAIL_BREAKDOWNS,
		NO_DETAIL_CHART.breakdown,
		windowParam
	);
	const [ detailThenBy, setDetailThenBy ] = useQueryParamChoice(
		'url_then',
		thenBysUnder( DETAIL_BREAKDOWNS, detailBreakdown ),
		NO_DETAIL_CHART.thenBy,
		windowParam
	);
	// A linked window's chips are judged by the field its link keys on.
	const [ detailChips, setDetailChips ] = useQueryParamList(
		'url_chip',
		( raw ) =>
			restoreChips(
				raw,
				SUBJECT_CHIP_OPTIONS[ linkedSubject()?.field ?? 'url' ]
			),
		( chips ) => chips,
		windowParam
	);
	const [ sortField, setSortField ] = useQueryParamChoice(
		'url_sort',
		REQUEST_SORT_FIELDS,
		DEFAULT_REQUEST_SORT.field,
		windowParam
	);
	const [ sortDir, setSortDir ] = useQueryParamChoice(
		'url_order',
		SORT_ORDERS,
		DEFAULT_REQUEST_SORT.dir,
		windowParam
	);
	// Same values, same object: the graph re-asks on a new one.
	const detailFilters = useMemo(
		() => ( { bucket: detailBucket } ),
		[ detailBucket ]
	);
	const detailChart = useMemo(
		() => ( {
			breakdown: detailBreakdown,
			thenBy: detailThenBy,
			chips: detailChips,
		} ),
		[ detailBreakdown, detailThenBy, detailChips ]
	);
	const requestSort = useMemo(
		() => ( { field: sortField, dir: sortDir } ),
		[ sortField, sortDir ]
	);

	// @longform The window's reads, asking nothing while it is shut: the URL's
	// answer stays on screen while a narrowed one is asked, but never under
	// another subject's title, and a hash or rid no command may carry shows
	// as the window's refusal instead of asking.
	const detailRead = useSource( 'eln/url-detail', {
		subject: selectedSubject && {
			field: selectedSubject.field,
			hash: selectedSubject.hash,
		},
		server: serverFilter,
		buckets: detailBucket,
		chips: detailChips,
	} );
	const badHash = !! selectedSubject && ! isValidHash( selectedSubject.hash );
	const urlDetailSlice = useMemo(
		() => ( {
			data: badHash ? null : detailRead.model?.data ?? null,
			loading: detailRead.loading,
			error: badHash ? 'Invalid URL hash format' : detailRead.error,
		} ),
		[ badHash, detailRead.model, detailRead.loading, detailRead.error ]
	);
	const requestRead = useSource( 'eln/request-detail', {
		request: selectedRequest,
	} );
	const badRid = !! selectedRequest && ! isValidRequestId( selectedRequest );
	const requestDetailSlice = useMemo(
		() => ( {
			data: badRid ? null : requestRead.model,
			error: badRid ? 'Invalid request ID format' : requestRead.error,
		} ),
		[ badRid, requestRead.model, requestRead.error ]
	);
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
			setDetailBreakdown( NO_DETAIL_CHART.breakdown );
			setDetailThenBy( NO_DETAIL_CHART.thenBy );
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
						filter: detailChart.chips,
				  }
				: urlFilters,
		[ selectedSubject, urlFilters, detailFilters, detailChart.chips ]
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
		( row ) => {
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
		},
		[ selectRequest, selectSubjectNow, urlFilters, resetDetail ]
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
	 * Sort the URL modal's request table by one column. Clicking the column
	 * already sorted descending flips it to ascending; every other click sorts
	 * descending.
	 *
	 * @param {string} field Field key to sort by.
	 */
	const handleRequestSort = useCallback(
		( field ) => {
			setSortDir(
				sortField === field && 'desc' === sortDir ? 'asc' : 'desc'
			);
			setSortField( field );
		},
		[ sortField, sortDir, setSortField, setSortDir ]
	);

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
			applyFoundRequest( args[ 0 ], result );
			// A narrowed window could hide the very request searched for.
			resetDetail( '', NO_CHIPS );
			setSearchQuery( '' );
			// Drops ?search= too; the selection's push then adds nothing.
			setQueryParams(
				{
					search: null,
					...subjectLink( { field: 'url', hash: result.url_hash } ),
					request: args[ 0 ],
				},
				{ push: true }
			);
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

	/**
	 * The URL's requests in the order the modal's table shows them.
	 *
	 * Sorting happens here rather than on the server because the modal already
	 * holds the rows `dump_url` returned, so a column click costs no fetch. A
	 * row missing the sort field counts as 0 and still takes a position; a
	 * duration that was never measured ranks last (`compareRequests()`).
	 */
	const sortedRequests = useMemo( () => {
		if ( ! urlDetail?.requests ) {
			return [];
		}
		const sorted = [ ...urlDetail.requests ];
		sorted.sort( ( a, b ) => compareRequests( a, b, requestSort ) );
		return sorted;
	}, [ urlDetail?.requests, requestSort ] );

	// The list is asked afresh, so the rows it holds answer the old filters.
	const detailListing = !! urlDetailSlice?.loading;

	// `Flame_Builder_Node` builds the tree; nothing here derives one.
	const requestFlameData = requestDetail?.flame_data ?? null;

	/**
	 * The entry rows the table renders, and how many of them are real.
	 *
	 * Every stored entry is numbered `i` first, which the table finds rows by,
	 * and a folded request's merged tree splices in where its entries were, so
	 * the indent walk reads one list either way. `realEntryCount` excludes the
	 * placeholder rows `computeIndentedEntries` inserts to span time gaps,
	 * which is what the header counts.
	 */
	const { entries: indentedEntries, realCount: realEntryCount } = useMemo(
		() =>
			computeIndentedEntries(
				spliceFoldedSpans(
					requestDetail?.entries,
					requestDetail?.flame
				)
			),
		[ requestDetail?.entries, requestDetail?.flame ]
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
							detailChips: detailChart.chips,
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
						selectSubject( null );
						selectRequest( null );
					} }
					className="event-logger-performance-modal newspack-nodes-modal newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui"
					headerActions={
						selectedRequest ? (
							<>
								<AskButton ask={ ask } />
								<button
									type="button"
									className="button is-plain event-logger-modal-back-button"
									onClick={ () => selectRequest( null ) }
									aria-label={ __(
										'Back to URL details',
										'newspack-event-logger-nodes'
									) }
								>
									&larr;
								</button>
							</>
						) : (
							<>
								{ urlDetail && (
									<WindowStats model={ detailRead.model } />
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

					{ ! selectedRequest && urlDetail && (
						<UrlWindow
							serverFilter={ serverFilter }
							urlDetail={ urlDetail }
							sortedRequests={ sortedRequests }
							requestSort={ requestSort }
							onRequestSort={ handleRequestSort }
							onSelectRequest={ selectRequest }
							subject={ selectedSubject }
							filters={ detailFilters }
							onBucketChange={ setDetailBucket }
							listing={ detailListing }
							listError={ urlDetailSlice.error }
							chart={ detailChart }
							onBreakdownChange={ setDetailBreakdown }
							onThenByChange={ setDetailThenBy }
							onChipsChange={ setDetailChips }
							metric={ detailMetric }
							onMetricChange={ setDetailMetric }
						/>
					) }

					{ /* Request Details View */ }
					{ selectedRequest && requestDetail && (
						<RequestDetailView
							requestDetail={ requestDetail }
							flameData={ requestFlameData }
							indentedEntries={ indentedEntries }
							realEntryCount={ realEntryCount }
							rid={ selectedRequest }
						/>
					) }

					{ /* A selected request with nothing to show is a state,
					     never a blank panel: both sections gate on the pair. */ }
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
