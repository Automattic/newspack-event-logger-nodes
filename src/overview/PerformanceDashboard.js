/* global requestAnimationFrame */
/**
 * Performance Dashboard — the orchestrator over the dashboard's node graph.
 *
 * `usePerformanceGraph` mounts the graph and owns every fetch; this component
 * owns none. The graph publishes its data through FOUR independent per-slice
 * view nodes — `overview:view`, `urls:view`, `url-detail:view`,
 * `request-detail:view`. This component reads each slice with its own
 * `useNodeField`, derives the render-time values, and hands the URL table's
 * paging back through `handleUrlParamsChange`, the one callback the hook
 * returns.
 *
 * What it does own is the UI state the graph's fetchers read at fire time: the
 * server filter, the chart metric and breakdown dimension, the refresh cadence,
 * the partition a located request was found in, the search box and its results,
 * the request-table sort, and the inline "Log this URL" rule editor. The server
 * filter, metric and breakdown also live in the address bar, as `?server=`,
 * `?metric=` and `?breakdown=`, so a shared link opens the same view. The
 * component also runs every command whose reply sets that state: the `?url=`
 * and `?request=` deep-link resolvers, the title lookup for a hash the loaded
 * catalog page does not carry, the search box's exact-rid lookup and its
 * pattern search, and the ruleset reads and writes behind "Log this URL". It
 * renders the URL / request detail modal and the Ask panel over it, and
 * preserves the modal's scroll position across the URL-detail ↔ request-detail
 * switch.
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
} from './constants';
import {
	usePerformanceGraph,
	SERVER,
	RULES_CI,
	GREP_RESULT_LIMIT,
} from './hooks/usePerformanceGraph';
import useUrlNavigation from './hooks/useUrlNavigation';
import { useQueryParamChoice } from '@newspack-nodes/shared/hooks/useQueryParamState';
import { setQueryParams } from '@newspack-nodes/shared/utils/queryParams';
import { usePersistedChoice } from '@newspack-nodes/shared/hooks/usePersistedState';
import OverviewSection from './components/OverviewSection';
import UrlDetailView from './components/UrlDetailView';
import RequestDetailView from './components/RequestDetailView';
import AskPanel, { AskButton, useAsk } from './components/AskPanel';
import { headlineStats } from './components/HeadlineStats';
import { errorSummary } from '../components/errorStatus';
import { pageFacts, factsJson } from './pageFacts';
import RuleEditModal from '../rules/RuleEditModal';
import { BLANK_RULE } from '../rules/constants';

import UrlTable from './UrlTable';
import { breakdownState } from './AggregateTimeChart';

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

/**
 * Order two request rows by one column in one direction. A null duration,
 * one `dump_url` measured none of (decision 24), ranks LAST either way, as
 * `Stats_Store::rank_order()` ranks a URL no timed request reached; such rows
 * keep their order among themselves. A row missing any other field counts as
 * 0.
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

/** The URL modal header's stats over every request, and over its errors. */
const URL_HEADER_STATS = [ 'requests_per_second', 'avg_ms', 'avg_peak_mb' ];
const ERRORS_HEADER_STATS = [
	'errors',
	'timeouts',
	'fatals',
	'fatal_avg_ms',
	'fatal_max_ms',
	'avg_peak_mb',
];

// The `?metric=` and `?breakdown=` whitelists: what the dropdowns offer.
const CHART_METRICS = CHART_METRIC_OPTIONS.map( ( option ) => option.value );
const CHART_BREAKDOWNS = CHART_BREAKDOWN_OPTIONS.map(
	( option ) => option.value
);

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
	// UI and control state only; the four view-node slices own every datum.
	const [ requestSort, setRequestSort ] = useState( {
		field: 'timestamp',
		dir: 'desc',
	} );
	// Held here, not in the URL modal, which unmounts for every request.
	const [ detailErrorsOnly, setDetailErrorsOnly ] = useState( false );
	const [ chartMetric, setChartMetric ] = useQueryParamChoice(
		'metric',
		CHART_METRICS,
		'volume'
	);

	// Every reply names every server; null until the first one lands.
	const [ serverNames, setServerNames ] = useState( null );
	// The server filter is page-wide scope, not the overview card's own.
	const [ serverFilter, setServerFilter ] = useQueryParamChoice(
		'server',
		serverNames,
		''
	);

	// The breakdown dimension rides the `overview` fetch, so it lives here.
	const [ chartBreakdown, setChartBreakdown ] = useQueryParamChoice(
		'breakdown',
		CHART_BREAKDOWNS,
		DEFAULT_CHART_BREAKDOWN
	);

	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchError, setSearchError ] = useState( null );
	const [ searchLoading, setSearchLoading ] = useState( false );
	// grep_requests result rows, and whether the server capped them.
	const [ searchResults, setSearchResults ] = useState( null );
	const [ searchResultsTruncated, setSearchResultsTruncated ] =
		useState( false );
	// Lines the last grep skipped as unparseable; undefined until it answers.
	const [ searchUnparseableLines, setSearchUnparseableLines ] = useState();
	const [ requestPartition, setRequestPartition ] = useState( null );
	const [ refreshInterval, setRefreshInterval ] = usePersistedChoice(
		'event-logger-refresh-interval',
		DASHBOARD_REFRESH_OPTIONS,
		'15000'
	);

	// Read, never depended on: these keep the callbacks below stable.
	const selectUrlRef = useRef(
		/** @type {( url: Object|null ) => void} */ ( () => {} )
	);
	const selectRequestRef = useRef(
		/** @type {( rid: string|null ) => void} */ ( () => {} )
	);
	// The catalog is a fresh array on every poll; `applyFoundRequest` is not.
	const urlsRef = useRef( [] );

	// Read each slice from its own per-slice view node (null until mounted).
	const overviewSlice = useNodeField( 'overview:view', 'view' );
	const urlsSlice = useNodeField( 'urls:view', 'view' );
	const urlDetailSlice = useNodeField( 'url-detail:view', 'view' );
	const requestDetailSlice = useNodeField( 'request-detail:view', 'view' );

	const overview = overviewSlice?.data ?? null;
	const urls = useMemo( () => urlsSlice?.data ?? [], [ urlsSlice?.data ] );
	// The filtered set's own numbers, computed once, server-side.
	const urlTotals = urlsSlice?.totals ?? null;
	const urlsProvisional = urlsSlice?.provisional ?? false;
	// The same set's slowest, and what the set is, as the server applied it.
	const urlSlowest = urlsSlice?.slowest ?? null;
	const urlFilters = urlsSlice?.filters ?? null;
	const urlDetail = urlDetailSlice?.data ?? null;
	const requestDetail = requestDetailSlice?.data ?? null;
	urlsRef.current = urls;

	// The overview reply's own series, read straight off the slice.
	const categoryData = useMemo(
		() => overview?.category_time_series ?? null,
		[ overview ]
	);
	const serverBreakdownData = useMemo(
		() => overview?.breakdowns?.server ?? null,
		[ overview ]
	);
	// @longform Read once: the server names, the Time Breakdown and a chart
	// broken down by server all share it.
	const serverRead = useMemo(
		() => breakdownState( serverBreakdownData ),
		[ serverBreakdownData ]
	);
	useEffect( () => {
		if ( 'pending' === serverRead.state ) {
			return;
		}
		// A reply the decoder refuses landed, and names no server.
		if ( ! serverRead.series ) {
			setServerNames( [] );
			return;
		}
		setServerNames( [ ...serverRead.series.names ].sort() );
	}, [ serverRead ] );

	// @longform One server draws a single bar, and a server filter draws that
	// server against itself, so neither can chart this axis. Derived rather
	// than written back: a reply carrying one server before its sibling
	// reports would otherwise strand the choice for the session. `null` is a
	// reply not yet landed, which is neither. The selector shows this derived
	// value while the state keeps the operator's own choice, so a fallback is
	// undone by the axis returning rather than by them choosing again.
	const canBreakDownByServer =
		! serverFilter && ( serverNames === null || serverNames.length >= 2 );
	const activeBreakdown =
		'server' === chartBreakdown && ! canBreakDownByServer
			? 'status'
			: chartBreakdown;

	/**
	 * The drawn dimension's `breakdownState()` read.
	 *
	 * Keyed off `activeBreakdown` rather than the operator's choice, so the
	 * chart and the dropdown never disagree when `server` falls back. An absent
	 * key is a dropdown switch the reply has not caught up with, which
	 * `breakdownState` reads as `pending` instead of as an empty dimension. The
	 * server axis takes `serverRead` itself, so that reply decodes once.
	 */
	const chartBreakdownRead = useMemo(
		() =>
			'server' === activeBreakdown
				? serverRead
				: breakdownState(
						overview?.breakdowns?.[ activeBreakdown ] ?? null
				  ),
		[ overview, activeBreakdown, serverRead ]
	);

	const {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		deepLink,
		clearDeepLink,
	} = useUrlNavigation( urls );

	selectUrlRef.current = selectUrl;
	selectRequestRef.current = baseSelectRequest;
	// @longform What is open RIGHT NOW, for the reply handlers below: a reply
	// must not yank the operator back to a URL they have already left. Written
	// where the selection is MADE as well as on render, because a reply can
	// land before React has re-rendered the selection that preceded it.
	const selectedUrlRef = useRef( selectedUrl );
	selectedUrlRef.current = selectedUrl;
	/**
	 * Select a URL, recording it in `selectedUrlRef` in the same call.
	 *
	 * @param {?Object} urlObj The `{hash, url}` entry to open, or null to close.
	 */
	const selectUrlNow = useCallback( ( urlObj ) => {
		selectedUrlRef.current = urlObj;
		selectUrlRef.current( urlObj );
	}, [] );

	// @longform One picker, several doors. A `url:` or `request:` brief is
	// about what is selected NOW, as `dump_url` is. The page's own brief is
	// about the rows on screen, so it takes the echoed `urlFilters` — the
	// filters those rows were fetched under — and the live pick only until
	// the first reply. An open URL modal lists under its own Errors Only, so
	// a brief asked there narrows as its list does. Above the graph, which
	// holds its poll while armed.
	const askFilters = useMemo(
		() =>
			selectedUrl
				? { ...urlFilters, errors_only: detailErrorsOnly }
				: urlFilters,
		[ selectedUrl, urlFilters, detailErrorsOnly ]
	);
	const ask = useAsk( { onError, serverFilter, urlFilters: askFilters } );

	// The graph polls and publishes; this page's own verbs are below.
	const { handleUrlParamsChange } = usePerformanceGraph( {
		serverFilter,
		chartBreakdown: activeBreakdown,
		refreshInterval,
		requestPartition,
		selectedUrl,
		selectedRequest,
		urlErrorsOnly: detailErrorsOnly,
		askActive: ask.active,
	} );

	// Reset the search-sourced partition when leaving request detail.
	useEffect( () => {
		if ( ! selectedRequest ) {
			setRequestPartition( null );
		}
	}, [ selectedRequest ] );

	// Where the URL detail was scrolled to, restored when the request closes.
	const urlDetailScrollRef = useRef( 0 );

	/**
	 * Open one of the URL's requests inside the modal, or return to the URL
	 * detail.
	 *
	 * @param {?string} rid         The request id to open, or null to go back.
	 * @param {number}  [partition] The partition the rid was located in. Omit it
	 *                              to keep the partition already selected.
	 */
	const selectRequest = useCallback(
		( rid, partition ) => {
			if ( rid ) {
				// Entering request detail — save current scroll position.
				const modalContent = document.querySelector(
					'.event-logger-performance-modal .components-modal__content'
				);
				if ( modalContent ) {
					urlDetailScrollRef.current = modalContent.scrollTop;
				}
			}
			// The partition rides WITH the selection, never recovered later.
			if ( undefined !== partition ) {
				setRequestPartition( partition );
			}
			baseSelectRequest( rid );
		},
		[ baseSelectRequest ]
	);

	/**
	 * Open a URL's modal, leaving behind whatever request was open inside the
	 * previous one.
	 *
	 * @param {Object} url The `{hash, url}` catalog entry to open.
	 */
	// A URL picked from an errors-only table opens narrowed to its errors.
	const openUrl = useCallback(
		( url ) => {
			selectRequest( null );
			setDetailErrorsOnly( !! urlFilters?.errors_only );
			selectUrlNow( url );
		},
		[ selectRequest, selectUrlNow, urlFilters ]
	);

	/**
	 * Sort the URL modal's request table by one column. Clicking the column
	 * already sorted descending flips it to ascending; every other click sorts
	 * descending.
	 *
	 * @param {string} field Field key to sort by.
	 */
	const handleRequestSort = useCallback( ( field ) => {
		setRequestSort( ( prev ) => ( {
			field,
			dir: prev.field === field && prev.dir === 'desc' ? 'asc' : 'desc',
		} ) );
	}, [] );

	/**
	 * Ask `dump_url` for the title of a hash the loaded catalog page does not
	 * carry.
	 *
	 * A hash becomes a title in two steps that need no pairing: select what is
	 * already known, then ask for the name. The reply names the hash it
	 * answered, which is how the guard below tells whose title it is.
	 */
	const { run: lookupUrl } = useCommandOnce( {
		ci: SERVER,
		command: 'dump_url',
		scope: 'url-lookup',
		retry: true,
		onDone: ( { result, args } ) => {
			const url = result?.stats?.url;
			// @longform Only the selection this answers: the operator may
			// have opened another URL while it was in flight, and yanking
			// them back to the one they left beats an untitled hash.
			if ( url && selectedUrlRef.current?.hash === args[ 0 ] ) {
				selectUrlNow( { hash: args[ 0 ], url } );
			}
		},
	} );

	/**
	 * Open a request the server has just located, showing it now and letting the
	 * title fill in when `lookupUrl` answers.
	 *
	 * @param {string} rid  The request id that was found.
	 * @param {Object} data The `search_requests` reply, carrying `url_hash` and
	 *                      `partition`.
	 */
	const applyFoundRequest = useCallback(
		( rid, data ) => {
			const known = urlsRef.current.find(
				( u ) => u.hash === data.url_hash
			);
			// @longform A rid names ONE request; the server filter is a
			// browsing scope, and `dump_url` honours it. A search landing
			// outside it would ask for a row the scope excludes and answer
			// "URL not found" for a URL plainly on screen. The navigation
			// wins, and the select resets so nothing is hidden.
			setServerFilter( '' );
			setRequestPartition( data.partition );
			selectUrlNow(
				known ?? { hash: data.url_hash, url: UNKNOWN_URL() }
			);
			selectRequestRef.current( rid );
			if ( ! known ) {
				lookupUrl( formatCommandArgs( [ data.url_hash ] ) );
			}
		},
		[ lookupUrl, selectUrlNow, setServerFilter ]
	);

	/**
	 * Whether a `search_requests` reply located the request.
	 *
	 * @param {?Object} data The reply payload.
	 * @return {boolean} True when it carries both a URL hash and a partition.
	 */
	const found = ( data ) =>
		data && data.url_hash && undefined !== data.partition;

	/**
	 * Resolve a `?request=` deep link. Only the server can answer for a rid,
	 * whose partition nothing on the page knows.
	 *
	 * A RETRIED read: an undelivered send is asked again while the link still
	 * stands, and a not-found is an answer that ends it.
	 */
	const { run: askDeepLinkRequest } = useCommandOnce( {
		ci: SERVER,
		command: 'search_requests',
		scope: 'request-deeplink',
		retry: true,
		onDone: ( { result, args } ) => {
			if ( deepLinkRef.current.requestId !== args[ 0 ] ) {
				return;
			}
			if ( found( result ) ) {
				applyFoundRequest( args[ 0 ], result );
				clearDeepLinkRef.current();
				return;
			}
			// Not-found is an ANSWER; let the ?url= hash have its turn.
			clearDeepLinkRef.current( 'request' );
		},
	} );

	/**
	 * Resolve a `?url=` deep link, which needs the server whenever the hash
	 * falls outside the loaded catalog page and so carries no title.
	 */
	const { run: askDeepLinkUrl } = useCommandOnce( {
		ci: SERVER,
		command: 'dump_url',
		scope: 'url-deeplink',
		retry: true,
		onDone: ( { result, args } ) => {
			if ( deepLinkRef.current.urlHash !== args[ 0 ] ) {
				return;
			}
			selectUrlNow( {
				hash: args[ 0 ],
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

	// The rid answers the hash AND the partition; `?url=` is the fallback.
	useEffect( () => {
		if ( deepLink.requestId ) {
			askDeepLinkRequest( formatCommandArgs( [ deepLink.requestId ] ) );
			return;
		}
		if ( deepLink.urlHash ) {
			askDeepLinkUrl( formatCommandArgs( [ deepLink.urlHash ] ) );
		}
	}, [ deepLink, askDeepLinkRequest, askDeepLinkUrl ] );

	/**
	 * Look one exact request id up in the request index and open it. One ask per
	 * submit, and a miss is an answer: it fills the search box's error instead
	 * of being re-asked.
	 */
	const { run: searchForRequest } = useCommandOnce( {
		ci: SERVER,
		command: 'search_requests',
		scope: 'request-search',
		onDone: ( { result, args } ) => {
			setSearchLoading( false );
			if ( ! found( result ) ) {
				setSearchError(
					sprintf(
						// translators: %s: the request ID that was searched for.
						__(
							'Request "%s" not found — prefix with / to search recent traffic',
							'newspack-event-logger-nodes'
						),
						args[ 0 ]
					)
				);
				return;
			}
			applyFoundRequest( args[ 0 ], result );
			setSearchQuery( '' );
			setQueryParams(
				{ search: null, url: result.url_hash, request: args[ 0 ] },
				{ push: true }
			);
		},
	} );

	/**
	 * Run the exact-id search, clearing the previous answer first so a stale
	 * error or result list never sits under a search still in flight.
	 *
	 * @param {string} rid The request id to look up.
	 */
	const searchRequest = useCallback(
		( rid ) => {
			if ( ! rid || ! rid.trim() ) {
				return;
			}
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			setSearchUnparseableLines( undefined );
			searchForRequest( formatCommandArgs( [ rid.trim() ] ) );
		},
		[ searchForRequest ]
	);

	/**
	 * Scan the recent firehose window for a pattern, capped at
	 * `GREP_RESULT_LIMIT` matching requests. No match reports through the search
	 * box's error line, so an empty list never sits there unexplained.
	 */
	const { run: requestGrep } = useCommandOnce( {
		ci: SERVER,
		command: 'grep_requests',
		// A search pattern is free text the operator typed, not an identity.
		subjectOf: () => null,
		onDone: ( { result, error } ) => {
			setSearchLoading( false );
			setSearchUnparseableLines( result?.unparseable_lines );
			const results = result?.results ?? [];
			if ( results.length > 0 ) {
				setSearchResults( results );
				setSearchResultsTruncated( !! result?.truncated );
				return;
			}
			setSearchError(
				error ||
					__(
						'No matches in recent traffic',
						'newspack-event-logger-nodes'
					)
			);
		},
	} );

	/**
	 * Pattern-search recent firehose traffic and render the matching-request
	 * list.
	 *
	 * @param {string} pattern The search pattern.
	 */
	const patternSearch = useCallback(
		( pattern ) => {
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			setSearchResultsTruncated( false );
			setSearchUnparseableLines( undefined );
			requestGrep(
				// Named, so a pattern opening `--` is never read as an option.
				formatCommandArgs( [], {
					pattern: pattern.trim(),
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
			if ( /^[a-zA-Z0-9_-]+$/.test( trimmed ) ) {
				searchRequest( trimmed );
			} else {
				patternSearch( trimmed );
			}
		},
		[ searchRequest, patternSearch ]
	);

	/**
	 * Open a pattern-search result by re-running the exact-rid path. A grep row
	 * carries the rid alone, and the URL hash and partition the modal needs come
	 * only from `search_requests`.
	 *
	 * @param {string} rid The request id of the clicked row.
	 */
	const selectSearchResult = useCallback(
		( rid ) => {
			setSearchResults( null );
			setSearchResultsTruncated( false );
			searchRequest( rid );
		},
		[ searchRequest ]
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

	/**
	 * Under the modal's Errors Only, the `errorSummary()` of the errors
	 * listed, which the header, the list and the facts block take beside the
	 * exact `stats.errors` in place of the whole URL's numbers, which describe
	 * other traffic. Null while every request is listed.
	 */
	const detailErrors = useMemo(
		() =>
			detailErrorsOnly && urlDetail
				? errorSummary( urlDetail.requests ?? [] )
				: null,
		[ detailErrorsOnly, urlDetail ]
	);

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

	/**
	 * The wall clock the Time Breakdown divides by: the board's own `avg_ms`.
	 *
	 * Its categories come from `build_leaderboard( server )`, over the board's
	 * 25 hour keys, so the average is that board's, over those same keys — a
	 * server's under a server filter — and never the charts' 288 slots.
	 */
	const breakdownAvgMs = overview?.global_leaderboard?.avg_ms;

	// Inline "Log this URL" state: the open draft, the ruleset, and the error.
	const [ ruleDraft, setRuleDraft ] = useState( null );
	// The whole ruleset as the server last reported it; null until it answers.
	const [ rules, setRules ] = useState( null );
	const [ ruleError, setRuleError ] = useState( null );

	const ruleUrl = selectedUrl?.url;
	const canLogUrl = !! ruleUrl && UNKNOWN_URL() !== ruleUrl;
	// Strip origin so exact rule matches REQUEST_URI ('?' = match sentinel).
	const rulePath = ruleUrl ? ruleUrl.replace( /^https?:\/\/[^/]+/, '' ) : '';
	// A path already carrying a query is the exact-plus-query-prefix form.
	const needsSentinel = ruleUrl && ! rulePath.includes( '?' );
	const exactPattern = needsSentinel ? `${ rulePath }?` : rulePath;

	/**
	 * Read the whole ruleset. A retried READ, and `existingRule` is DERIVED from
	 * the answer on every render rather than copied into state, so the button's
	 * label and the draft it opens always agree with the ruleset last read.
	 */
	const { run: dumpRules } = useCommandOnce( {
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
							selectedUrl,
							urlDetail,
							detailErrors,
							selectedRequest,
							requestPartition,
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
				urlsProvisional={ urlsProvisional }
				breakdownAvgMs={ breakdownAvgMs }
				serverFilter={ serverFilter }
				setServerFilter={ setServerFilter }
				serverNames={ serverNames }
				searchQuery={ searchQuery }
				setSearchQuery={ setSearchQuery }
				searchLoading={ searchLoading }
				searchError={ searchError }
				onSearch={ handleSearch }
				searchResults={ searchResults }
				searchResultsTruncated={ searchResultsTruncated }
				searchUnparseableLines={ searchUnparseableLines }
				onSelectResult={ selectSearchResult }
				refreshInterval={ refreshInterval }
				setRefreshInterval={ setRefreshInterval }
				headerControlsSlot={ headerControlsSlot }
				chartMetric={ chartMetric }
				setChartMetric={ setChartMetric }
				chartBreakdown={ activeBreakdown }
				canBreakDownByServer={ canBreakDownByServer }
				setChartBreakdown={ setChartBreakdown }
				breakdownRead={ chartBreakdownRead }
				categoryData={ categoryData }
			/>

			{ /* Main Content */ }
			<div className="event-logger-performance-content">
				{ /* URL List */ }
				<div className="event-logger-performance-urls">
					<Card>
						<CardHeader>
							<h2>
								{ __(
									'URLs by Request Count',
									'newspack-event-logger-nodes'
								) }
							</h2>
						</CardHeader>
						<CardBody>
							<UrlTable
								urls={ urls }
								selectedUrl={ selectedUrl }
								onSelect={ openUrl }
								onParamsChange={ handleUrlParamsChange }
								totalUrls={ urlsSlice?.rows }
								metric={ chartMetric }
								ranked={ urlsSlice?.ranked }
								now={ urlsSlice?.as_of }
								errorCounts={ !! urlFilters?.errors_only }
								error={ urlsSlice?.error ?? null }
								loading={ !! urlsSlice?.loading }
							/>
						</CardBody>
					</Card>
				</div>
			</div>

			{ /* URL/Request Detail Modal */ }
			{ /* The SELECTION opens this, never a slice: each pane below owns
			     its own empty state, and a modal that can close is the only way
			     back out of one. */ }
			{ selectedUrl && (
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
							: selectedUrl.url
					}
					onRequestClose={ () => {
						// Keep URL modal open while the nested editor is open.
						if ( ruleDraft ) {
							return;
						}
						selectUrl( null );
						selectRequest( null );
						// Only the table opens a URL narrowed; forget it here.
						setDetailErrorsOnly( false );
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
											{
												...urlDetail.stats,
												...detailErrors,
											},
											detailErrors
												? ERRORS_HEADER_STATS
												: URL_HEADER_STATS
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
							urlHash={ selectedUrl.hash }
							onErrorsOnlyChange={ setDetailErrorsOnly }
							detailErrors={ detailErrors }
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
							partition={ requestPartition }
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
			{ selectedUrl && ! selectedRequest && ruleDraft && (
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
