/* global requestAnimationFrame */
/**
 * Performance Dashboard — the orchestrator over the dashboard's node graph.
 *
 * `usePerformanceGraph` mounts the graph and owns every fetch; this component
 * owns none. The graph publishes its data through independent per-slice view
 * nodes — `overview:view`, `urls:view`, `chart:view`, `categories:view`,
 * `url-detail:view`, `url-chart:view`, `url-categories:view` and
 * `request-detail:view`. This component reads each slice with its own
 * `useNodeField`, derives the render-time values, and hands the URL table's
 * paging back through `handleUrlParamsChange`, the one callback the hook
 * returns.
 *
 * What it does own is the UI state the graph's fetchers read at fire time: the
 * server filter, the chart metric, dimensions and chips, the refresh cadence,
 * the search box and its results, the request-table sort, the bucket
 * selections that narrow the URL table and the URL modal, the URL modal
 * chart's question and metric, and the inline "Log this URL" rule editor. The
 * server filter, metric, both dimensions, the chart's chips, the table
 * selection and the pages the table expands also live in the address bar, as
 * `?server=`, `?metric=`, `?breakdown=`, `?then=`, one `?chip=` per chip,
 * `?bucket=` and one `?expand=` per page, so a shared link opens the same
 * view. While a detail modal is open, on a URL or on a whole page, or a link
 * is opening one, its own controls ride there too under a `url_` prefix, as
 * `docs/dashboards.md` lists. The component also runs every command whose
 * reply sets that state: the `?url=` / `?page_hash=` and `?request=`
 * deep-link resolvers, the
 * title lookup for a hash the loaded catalog page does not carry, the search
 * box's exact-rid lookup and its pattern search, and the ruleset reads and
 * writes behind "Log this URL". It renders the URL / request detail modal and
 * the Ask panel over it, and preserves the modal's scroll position across the
 * URL-detail ↔ request-detail switch.
 */

import {
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import {
	Spinner,
	Card,
	CardBody,
	CardHeader,
	Modal,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { useNodeField, formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import {
	computeIndentedEntries,
	spliceFoldedSpans,
} from './utils/logEntryUtils';
import {
	DASHBOARD_REFRESH_OPTIONS,
	CHART_METRIC_OPTIONS,
	CHART_BREAKDOWN_OPTIONS,
	DEFAULT_CHART_BREAKDOWN,
	DEFAULT_CHART_METRIC,
	FILTER_FIELD_OPTIONS,
	GROUPS,
	SCOPED_BREAKDOWN_OPTIONS,
	SORT_ORDERS,
	SUBJECT_CHIP_OPTIONS,
	NO_PAGE_KEYS,
	PAGE_KEY,
	pageKey,
} from './constants';
import {
	usePerformanceGraph,
	SERVER,
	RULES_CI,
	GREP_RESULT_LIMIT,
	NO_CHIPS,
	NO_DETAIL_CHART,
} from './hooks/usePerformanceGraph';
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
import { requestedUrl, rulePath, serverName } from '../log-table/urlIdentity';
import { usePersistedChoice } from '@newspack-nodes/shared/hooks/usePersistedState';
import { useDismissable } from '@newspack-nodes/shared/hooks/useDismissable';
import OverviewSection from './components/OverviewSection';
import { chipRefusal, chipsOf } from './components/BreakdownControls';
import UrlDetailView, {
	DEFAULT_REQUEST_SORT,
	REQUEST_SORT_FIELDS,
} from './components/UrlDetailView';
import RequestDetailView from './components/RequestDetailView';
import AskPanel, { AskButton, useAsk } from './components/AskPanel';
import { headlineStats } from './components/HeadlineStats';
import { pageFacts, factsJson } from './pageFacts';
import RuleEditModal from '../rules/RuleEditModal';
import { BLANK_RULE } from '../rules/constants';

import UrlTable from './UrlTable';
import {
	activeChart,
	answerSlots,
	useBucketSelection,
	withoutChips,
} from './chartSlots';

/**
 * The stand-in title for a URL whose hash is selected but whose name has not
 * arrived. `canLogUrl` compares against this exact string, so it stays the one
 * place the placeholder is spelled: put the hash here instead and the modal
 * offers a logging rule for a URL nobody has named.
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
 * the `urls` verb ranks a URL no timed request reached; such rows keep their
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

/** The URL modal header's stats, over what its chips and bucket keep. */
const URL_HEADER_STATS = [
	'requests_per_second',
	'errors',
	'error_rate',
	'avg_ms',
	'p50_ms',
	'p95_ms',
	'p99_ms',
	'avg_peak_mb',
];

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
 * The linked `?expand=` values that name a page, each once, its server
 * spelled as the list names it.
 *
 * @param {string[]} raw Every value the param holds.
 * @return {string[]} The page keys kept, or `NO_PAGE_KEYS` for none.
 */
const restoreExpanded = ( raw ) => {
	const kept = [
		...new Set(
			raw
				.filter( ( key ) => PAGE_KEY.test( key ) )
				.map( ( key ) => {
					const at = key.indexOf( ':' ) + 1;
					return key.slice( 0, at ) + serverName( key.slice( at ) );
				} )
		),
	];
	return kept.length ? kept : NO_PAGE_KEYS;
};

// The address-bar whitelists: what the dropdowns offer.
const CHART_METRICS = CHART_METRIC_OPTIONS.map( ( option ) => option.value );
const CHART_BREAKDOWNS = CHART_BREAKDOWN_OPTIONS.map(
	( option ) => option.value
);
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
 * The dashboard's one component: the overview card, the URL table, and the
 * detail modal over them.
 *
 * It renders a spinner until the `overview:view` slice resolves — until then the
 * graph may not even be mounted, and an empty dashboard would read as no data.
 *
 * @param {Object}                    props                      Component props.
 * @param {(message: string) => void} props.onError              Ask-failure reporter, handed
 *                                                               straight to `useAsk`, which
 *                                                               calls it with the reason the
 *                                                               ask failed. The page renders
 *                                                               that as a dismissible notice.
 * @param {?Element}                  [props.headerControlsSlot] Shell header slot to portal the Ask, search and refresh controls into; null while it is pending, undefined renders them inline.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function PerformanceDashboard( {
	onError,
	headerControlsSlot,
} ) {
	// @longform The table's bucket selection, as its spelling. A linked one
	// goes out as it arrived: the server judges it against the charted slots,
	// and the Time field's token that shows its refusal is the way out.
	const [ tableBucket, setTableBucket ] = useQueryParamState(
		'bucket',
		( raw ) => raw ?? '',
		String
	);
	const [ chartMetric, setChartMetric ] = useQueryParamChoice(
		'metric',
		CHART_METRICS,
		DEFAULT_CHART_METRIC
	);

	// Read each slice from its own per-slice view node (null until mounted).
	const overviewSlice = useNodeField( 'overview:view', 'view' );
	const urlsSlice = useNodeField( 'urls:view', 'view' );
	const chartSlice = useNodeField( 'chart:view', 'view' );
	const categoriesSlice = useNodeField( 'categories:view', 'view' );
	const urlDetailSlice = useNodeField( 'url-detail:view', 'view' );
	const urlChartSlice = useNodeField( 'url-chart:view', 'view' );
	const urlCategoriesSlice = useNodeField( 'url-categories:view', 'view' );
	const requestDetailSlice = useNodeField( 'request-detail:view', 'view' );
	const overview = overviewSlice?.data ?? null;
	// The live chart the selection is edited on bounds it.
	const chartAnswer = chartSlice?.data ?? null;
	const chartSlots = useMemo(
		() => answerSlots( chartAnswer ),
		[ chartAnswer ]
	);
	const [ tableBuckets, pickTableBucket, pickTableRange ] =
		useBucketSelection( tableBucket, setTableBucket, chartSlots );

	// @longform Every overview answer names every server with traffic, busiest
	// first; the select lists them by name. Null until the first one lands,
	// which is neither one server nor several.
	const answeredServers = overview?.servers;
	const serverNames = useMemo(
		() => ( answeredServers ? [ ...answeredServers ].sort() : null ),
		[ answeredServers ]
	);
	// @longform The server filter is page-wide scope, not the overview card's
	// own. A server linked in other capitals is the one the list names, and
	// one the list does not name is dropped once the list can judge it.
	const [ linkedServer, setServerFilter ] = useQueryParamState(
		'server',
		( raw ) => serverName( raw ?? '' ),
		( value ) => value || null
	);
	const serverFilter =
		! serverNames?.length || serverNames.includes( linkedServer )
			? linkedServer
			: '';
	useEffect( () => {
		if ( serverFilter !== linkedServer ) {
			setServerFilter( serverFilter );
		}
	}, [ serverFilter, linkedServer, setServerFilter ] );

	// The chart's question rides the `chart` fetch, so it lives here.
	const [ chartBreakdown, setChartBreakdown ] = useQueryParamChoice(
		'breakdown',
		CHART_BREAKDOWNS,
		DEFAULT_CHART_BREAKDOWN
	);
	const [ chartThenBy, setChartThenBy ] = useQueryParamChoice(
		'then',
		thenBysUnder( CHART_BREAKDOWNS, chartBreakdown ),
		''
	);
	const [ chartChips, setChartChips ] = useQueryParamList(
		'chip',
		( raw ) => restoreChips( raw, FILTER_FIELD_OPTIONS ),
		( chips ) => chips
	);
	const [ expanded, setExpanded ] = useQueryParamList(
		'expand',
		restoreExpanded,
		( keys ) => keys
	);
	/**
	 * Expand a page of the URL table, or shut it.
	 *
	 * @param {string} key The page's `pageKey()`.
	 */
	const toggleExpanded = useCallback(
		( key ) =>
			setExpanded( ( keys ) =>
				keys.includes( key )
					? keys.filter( ( other ) => other !== key )
					: [ ...keys, key ]
			),
		[ setExpanded ]
	);

	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchAnswer, setSearchAnswer ] = useState( NO_SEARCH_ANSWER );
	const [ refreshInterval, setRefreshInterval ] = usePersistedChoice(
		'event-logger-refresh-interval',
		DASHBOARD_REFRESH_OPTIONS,
		'15000'
	);

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
	const urlDetail = urlDetailSlice?.data ?? null;
	const requestDetail = requestDetailSlice?.data ?? null;
	urlsRef.current = urls;
	// The URL window's model on screen, which a one-request click records.
	const urlDetailSliceRef = useRef( urlDetailSlice );
	urlDetailSliceRef.current = urlDetailSlice;
	// @longform The pages whose shapes the graph asks: those expanded, listed
	// and of several shapes. A key naming a page off this page asks nothing.
	const expandedPages = useMemo(
		() =>
			urls.filter(
				( page ) =>
					1 < page.shapes && expanded.includes( pageKey( page ) )
			),
		[ urls, expanded ]
	);

	// The selector shows the derived axes; the state keeps the choice made.
	const {
		breakdown: activeBreakdown,
		thenBy: activeThenBy,
		canBreakDownByServer,
	} = activeChart( {
		breakdown: chartBreakdown,
		thenBy: chartThenBy,
		serverFilter,
		serverNames,
	} );

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

	// The graph polls and publishes; this page's own verbs are below.
	const { handleUrlParamsChange } = usePerformanceGraph( {
		serverFilter,
		chartBreakdown: activeBreakdown,
		chartThenBy: activeThenBy,
		chartChips,
		refreshInterval,
		selectedSubject,
		selectedRequest,
		detailFilters,
		detailChart,
		askActive: ask.active,
		expandedPages,
	} );

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
	/** Cancel a held click: no window opens, and the bar drops `?url=`. */
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
	 * subject a link named beside it, and inside its own URL when none did.
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
	 * whenever no row of the loaded catalog page opens it, and so titles it.
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
	 * duration nobody measured ranks last (`compareRequests()`).
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
	 * seeded with its pattern when no rule matches.
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

	// Initial load pending: no data, no error, and no fetch started yet.
	const overviewResolved =
		!! overviewSlice &&
		( null !== overviewSlice.data ||
			!! overviewSlice.error ||
			overviewSlice.loading );
	if ( ! overviewResolved ) {
		return (
			<div className="newspack-nodes-performance-loading">
				<Spinner />
				<p>
					{ __(
						'Loading performance data…',
						'newspack-event-logger-nodes'
					) }
				</p>
			</div>
		);
	}

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

			{ /* Overview Stats */ }
			<OverviewSection
				ask={ ask }
				overview={ overview }
				urlTotals={ urlTotals }
				serverFilter={ serverFilter }
				setServerFilter={ setServerFilter }
				serverNames={ serverNames }
				searchQuery={ searchQuery }
				setSearchQuery={ changeSearchQuery }
				searchLoading={ requestSearchPending || grepPending }
				searchError={ searchAnswer.error }
				onSearch={ handleSearch }
				searchResults={ searchAnswer.results }
				searchResultsTruncated={ searchAnswer.truncated }
				searchUnparseableLines={ searchAnswer.unparseableLines }
				onSelectResult={ searchRequest }
				refreshInterval={ refreshInterval }
				setRefreshInterval={ setRefreshInterval }
				headerControlsSlot={ headerControlsSlot }
				chartMetric={ chartMetric }
				setChartMetric={ setChartMetric }
				chartBreakdown={ activeBreakdown }
				canBreakDownByServer={ canBreakDownByServer }
				setChartBreakdown={ setChartBreakdown }
				chartThenBy={ activeThenBy }
				setChartThenBy={ setChartThenBy }
				chartChips={ chartChips }
				setChartChips={ setChartChips }
				chartSlice={ chartSlice }
				categoriesSlice={ categoriesSlice }
				onSlotClick={ pickTableBucket }
				onSlotRange={ pickTableRange }
				selectedBuckets={ tableBuckets }
			/>

			{ /* Main Content */ }
			<div className="event-logger-performance-content">
				{ /* URL List */ }
				<div className="event-logger-performance-urls">
					<Card>
						<CardHeader>
							<h2>
								{ __(
									'Pages by Request Count',
									'newspack-event-logger-nodes'
								) }
							</h2>
						</CardHeader>
						<CardBody>
							<UrlTable
								urls={ urls }
								selectedSubject={ selectedSubject }
								onSelect={ openSubject }
								expanded={ expanded }
								onToggleExpand={ toggleExpanded }
								busy={
									holdingWindow ? oneRequest.subject : null
								}
								onParamsChange={ handleUrlParamsChange }
								bucket={ tableBucket }
								slots={ chartSlots }
								server={ serverFilter }
								onBucketChange={ setTableBucket }
								totalRows={ urlsSlice?.rows }
								chips={ chartChips }
								metric={ chartMetric }
								error={ urlsSlice?.error ?? null }
								loading={ !! urlsSlice?.loading }
							/>
						</CardBody>
					</Card>
				</div>
			</div>

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
									<div className="event-logger-header-stats newspack-nodes-stats-grid">
										{ headlineStats(
											urlDetail.stats,
											URL_HEADER_STATS
										).map( ( { key, short, value } ) => (
											<span
												key={ key }
												className="newspack-nodes-stat"
											>
												{ value }
												<small className="newspack-nodes-stat-label">
													{ short }
												</small>
											</span>
										) ) }
									</div>
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
						<UrlDetailView
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
							chartSlice={ urlChartSlice }
							categoriesSlice={ urlCategoriesSlice }
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
