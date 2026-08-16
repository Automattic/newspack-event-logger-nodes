/* global localStorage, requestAnimationFrame */
/**
 * Performance Dashboard — the orchestrator over the dashboard's node graph.
 *
 * `usePerformanceGraph` mounts the graph and owns every fetch; this component
 * owns none. The graph publishes its data through FOUR independent per-slice
 * view nodes — `overview:view`, `urls:view`, `urldetail:view`,
 * `requestdetail:view`. This component reads each slice with its own
 * `useNodeState`, derives the render-time values, and drives control through the
 * callbacks the hook returns.
 *
 * What it does own is the UI state those callbacks read at fire time: the server
 * filter, the chart metric and breakdown dimension, the refresh cadence, the
 * search box and its results, the request-table sort, and the inline "Log this
 * URL" rule editor. It renders the URL / request detail modal too, and preserves
 * the modal's scroll position across the URL-detail ↔ request-detail switch.
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

import { useNodeState, formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import {
	computeIndentedEntries,
	spliceFoldedSpans,
} from './utils/logEntryUtils';
import { DASHBOARD_REFRESH_OPTIONS } from './constants';
import {
	usePerformanceGraph,
	SERVER,
	RULES_CI,
	GREP_RESULT_LIMIT,
} from './hooks/usePerformanceGraph';
import useUrlNavigation from './hooks/useUrlNavigation';
import OverviewSection from './components/OverviewSection';
import UrlDetailView from './components/UrlDetailView';
import RequestDetailView from './components/RequestDetailView';
import AskPanel, { AskButton, useAsk } from './components/AskPanel';
import { pageFacts, factsJson } from './pageFacts';
import RuleEditModal from '../rules/RuleEditModal';
import { BLANK_RULE } from '../rules/constants';

import UrlTable from './UrlTable';

// No URL to name. canLogUrl tests for it — a hash here would offer a rule.
const UNKNOWN_URL = () => __( 'Unknown URL', 'newspack-event-logger-nodes' );

import './styles/modal.scss';
import './styles/tables.scss';
import './styles/charts.scss';

/**
 * Performance Dashboard component.
 *
 * Renders a spinner until the `overview:view` slice resolves — until then the
 * graph may not even be mounted, and an empty dashboard would read as no data.
 *
 * @param {Object}               props         Component props.
 * @param {(err: Error) => void} props.onError Error handler callback. Reported
 *                                             failures surface as the page's
 *                                             dismissible notice.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function PerformanceDashboard( { onError } ) {
	// UI and control state only; the four view-node slices own every datum.
	const [ requestSort, setRequestSort ] = useState( {
		field: 'timestamp',
		dir: 'desc',
	} );
	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	// Page-wide server filter state (lifted from OverviewSection).
	const [ serverFilter, setServerFilter ] = useState( '' );

	// Breakdown selector state (lifted so the dim rides combined /overview).
	const [ chartBreakdown, setChartBreakdown ] = useState( 'status' );

	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchError, setSearchError ] = useState( null );
	const [ searchLoading, setSearchLoading ] = useState( false );
	// request_grep result rows + whether the server capped them.
	const [ searchResults, setSearchResults ] = useState( null );
	const [ searchResultsTruncated, setSearchResultsTruncated ] =
		useState( false );
	const [ requestPartition, setRequestPartition ] = useState( null );
	const [ refreshInterval, setRefreshInterval ] = useState( () => {
		// Load from localStorage, validated against allowed dropdown values.
		const validValues = DASHBOARD_REFRESH_OPTIONS.map(
			( opt ) => opt.value
		);
		const saved = localStorage.getItem( 'event-logger-refresh-interval' );
		if ( saved && validValues.includes( saved ) ) {
			return saved;
		}
		return '15000';
	} );

	// Refs break the resolve/navigation ↔ selection cycle.
	const selectUrlRef = useRef(
		/** @type {( url: Object|null ) => void} */ ( () => {} )
	);
	const selectRequestRef = useRef(
		/** @type {( rid: string|null ) => void} */ ( () => {} )
	);
	const urlsRef = useRef( [] );
	const setRequestPartitionRef = useRef(
		/** @type {( partition: number|null ) => void} */ ( () => {} )
	);

	// Read each slice from its own per-slice view node (null until mounted).
	const overviewSlice = useNodeState( 'overview:view', 'view' );
	const urlsSlice = useNodeState( 'urls:view', 'view' );
	const urlDetailSlice = useNodeState( 'urldetail:view', 'view' );
	const requestDetailSlice = useNodeState( 'requestdetail:view', 'view' );

	// Derive the locals the orchestrator renders from (same names as before).
	const overview = overviewSlice?.data ?? null;
	const urls = useMemo( () => urlsSlice?.data ?? [], [ urlsSlice?.data ] );
	const totalUrls = urlsSlice?.total ?? 0;
	const urlDetail = urlDetailSlice?.data ?? null;
	const requestDetail = requestDetailSlice?.data ?? null;
	urlsRef.current = urls;

	// Overview fan-out (replaces applyOverviewBreakdowns' setState calls).
	const categoryData = useMemo(
		() => overview?.category_time_series ?? null,
		[ overview ]
	);
	const serverBreakdownData = useMemo(
		() => overview?.breakdowns?.server ?? null,
		[ overview ]
	);
	const chartBreakdownData = useMemo( () => {
		if ( ! overview?.breakdowns || ! chartBreakdown ) {
			return null;
		}
		return overview.breakdowns[ chartBreakdown ] ?? null;
	}, [ overview, chartBreakdown ] );

	// serverNames stays sticky: don't overwrite on one-server scoped reply.
	const [ serverNames, setServerNames ] = useState( [] );
	useEffect( () => {
		if ( ! serverBreakdownData || serverFilter ) {
			return;
		}
		const names = new Set();
		Object.values( serverBreakdownData ).forEach( ( bucket ) =>
			Object.keys( bucket ).forEach( ( n ) => names.add( n ) )
		);
		setServerNames( Array.from( names ).sort() );
	}, [ serverBreakdownData, serverFilter ] );

	const {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
		deepLink,
		clearDeepLink,
	} = useUrlNavigation( urls );

	selectUrlRef.current = selectUrl;
	selectRequestRef.current = baseSelectRequest;
	setRequestPartitionRef.current = setRequestPartition;

	// The graph polls and publishes; this page's own verbs are below.
	const { handleUrlParamsChange } = usePerformanceGraph( {
		serverFilter,
		chartBreakdown,
		refreshInterval,
		requestPartition,
		selectedUrl,
		selectedRequest,
	} );

	// One picker, several doors — the triggers live in each header below.
	const ask = useAsk( { onError } );

	// Reset the search-sourced partition when leaving request detail.
	useEffect( () => {
		if ( ! selectedRequest ) {
			setRequestPartition( null );
		}
	}, [ selectedRequest ] );

	const urlDetailScrollRef = useRef( 0 );

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
	 * Handle sorting of request columns.
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
	 * Search for a request by ID and open its modal.
	 *
	 * @param {string} rid Request ID to search for.
	 */
	// @longform A hash becomes a title here, in two steps that need no pairing:
	// select what is already known, then ask `url_detail` for the title if the
	// hash is off the loaded page. The lookup's reply names the hash it
	// answered, so it upgrades that selection and no other.
	const { run: lookupUrl } = useCommandOnce( {
		ci: SERVER,
		command: 'url_detail',
		scope: `${ SERVER }:url_detail:lookup`,
		retry: true,
		onDone: ( { result, args } ) => {
			const url = result?.stats?.url;
			if ( url ) {
				selectUrlRef.current( { hash: args[ 0 ], url } );
			}
		},
	} );

	// Show the request now; the title fills in when the lookup answers.
	const applyFoundRequest = useCallback(
		( rid, data ) => {
			const known = urlsRef.current.find(
				( u ) => u.hash === data.url_hash
			);
			setRequestPartitionRef.current( data.partition );
			selectUrlRef.current(
				known ?? { hash: data.url_hash, url: UNKNOWN_URL() }
			);
			selectRequestRef.current( rid );
			if ( ! known ) {
				lookupUrl( formatCommandArgs( [ data.url_hash ] ) );
			}
		},
		[ lookupUrl ]
	);

	const found = ( data ) =>
		data && data.url_hash && undefined !== data.partition;

	// @longform The deep link is a RETRIED read: it keeps asking while the
	// intent stands, so a link opened against a dashboard whose catalog has
	// not moved still converges. That is the whole of the old one-at-a-time
	// latch and its 1s-to-30s backoff — the substrate's read does both.
	const { run: askDeepLinkRequest } = useCommandOnce( {
		ci: SERVER,
		command: 'request_search',
		scope: `${ SERVER }:request_search:deeplink`,
		retry: true,
		onDone: ( { result, args } ) => {
			if ( found( result ) ) {
				applyFoundRequest( args[ 0 ], result );
				clearDeepLinkRef.current();
				return;
			}
			// Not-found is an ANSWER; let the ?url= hash have its turn.
			clearDeepLinkRef.current( 'request' );
		},
	} );

	const { run: askDeepLinkUrl } = useCommandOnce( {
		ci: SERVER,
		command: 'url_detail',
		scope: `${ SERVER }:url_detail:deeplink`,
		retry: true,
		onDone: ( { result, args } ) => {
			selectUrlRef.current( {
				hash: args[ 0 ],
				url: result?.stats?.url || UNKNOWN_URL(),
			} );
			clearDeepLinkRef.current();
		},
	} );

	const clearDeepLinkRef = useRef( clearDeepLink );
	clearDeepLinkRef.current = clearDeepLink;

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

	// The search box: one ask per submit, and a miss is an answer.
	const { run: searchForRequest } = useCommandOnce( {
		ci: SERVER,
		command: 'request_search',
		scope: `${ SERVER }:request_search:search`,
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
			updateBrowserUrl( {
				search: null,
				url: result.url_hash,
				request: args[ 0 ],
			} );
		},
	} );

	const searchRequest = useCallback(
		( rid ) => {
			if ( ! rid || ! rid.trim() ) {
				return;
			}
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			searchForRequest( formatCommandArgs( [ rid.trim() ] ) );
		},
		[ searchForRequest ]
	);

	/**
	 * Pattern-search recent firehose traffic and render the matching-request list.
	 *
	 * @param {string} pattern The search pattern.
	 */
	const { run: requestGrep } = useCommandOnce( {
		ci: SERVER,
		command: 'request_grep',
		onDone: ( { result, error } ) => {
			setSearchLoading( false );
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

	const patternSearch = useCallback(
		( pattern ) => {
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			setSearchResultsTruncated( false );
			requestGrep(
				formatCommandArgs( [ pattern.trim() ], {
					limit: GREP_RESULT_LIMIT,
				} )
			);
		},
		[ requestGrep ]
	);

	/**
	 * Search box submit: a rid-shaped token keeps today's exact request lookup;
	 * anything else (a URL or text pattern) runs a recent-traffic pattern search.
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

	// Clicking a pattern-search result deep-links via the exact-rid path.
	const selectSearchResult = useCallback(
		( rid ) => {
			setSearchResults( null );
			setSearchResultsTruncated( false );
			searchRequest( rid );
		},
		[ searchRequest ]
	);

	// Save refresh interval to localStorage.
	useEffect( () => {
		localStorage.setItem(
			'event-logger-refresh-interval',
			refreshInterval
		);
	}, [ refreshInterval ] );

	// Sort recent requests.
	const sortedRequests = useMemo( () => {
		if ( ! urlDetail?.requests ) {
			return [];
		}
		const sorted = [ ...urlDetail.requests ];
		sorted.sort( ( a, b ) => {
			const aVal = a[ requestSort.field ] ?? 0;
			const bVal = b[ requestSort.field ] ?? 0;
			if ( requestSort.dir === 'asc' ) {
				return aVal > bVal ? 1 : -1;
			}
			return aVal < bVal ? 1 : -1;
		} );
		return sorted;
	}, [ urlDetail?.requests, requestSort ] );

	// Use server-built flame_data from Flame Builder.
	const requestFlameData = requestDetail?.flame_data ?? null;

	// A folded request's merged tree splices in where its entries were.
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

	// Requests/sec from the last hour of complete 5-minute buckets.
	const globalRequestsPerSecond = useMemo( () => {
		if ( ! overview?.aggregate_time_series ) {
			return 0;
		}
		const buckets = Object.keys( overview.aggregate_time_series ).sort();
		if ( buckets.length < 2 ) {
			return 0;
		}
		// Skip the accumulating bucket; use up to 12 complete buckets (1 hour).
		const complete = buckets.slice( -13, -1 );
		let total = 0;
		for ( const key of complete ) {
			total += overview.aggregate_time_series[ key ]?.count || 0;
		}
		return total / ( complete.length * 300 );
	}, [ overview?.aggregate_time_series ] );

	// Compute filtered overview stats when a server is selected.
	const filteredOverviewStats = useMemo( () => {
		if ( ! serverFilter || ! serverBreakdownData ) {
			return {
				totalRequests: overview?.total_requests ?? 0,
				globalAvgMs: overview?.global_avg_ms ?? 0,
				globalAvgPeakMb: overview?.global_avg_peak_mb ?? 0,
				requestsPerSecond: globalRequestsPerSecond,
				totalUrls,
				isFiltered: false,
			};
		}
		const buckets = Object.keys( serverBreakdownData ).sort();
		let totalC = 0;
		let totalS = 0;
		let totalM = 0;
		for ( const key of buckets ) {
			const entry = serverBreakdownData[ key ]?.[ serverFilter ];
			if ( entry ) {
				totalC += entry.c || 0;
				totalS += entry.s || 0;
				totalM += entry.m || 0;
			}
		}
		// Req/s: use up to 12 complete buckets, skip the most recent.
		let reqPerSec = 0;
		if ( buckets.length >= 2 ) {
			const complete = buckets.slice( -13, -1 );
			let recent = 0;
			for ( const key of complete ) {
				recent += serverBreakdownData[ key ]?.[ serverFilter ]?.c || 0;
			}
			reqPerSec = recent / ( complete.length * 300 );
		}
		return {
			totalRequests: totalC,
			globalAvgMs: totalC > 0 ? totalS / totalC : 0,
			globalAvgPeakMb: totalC > 0 ? totalM / totalC : 0,
			requestsPerSecond: reqPerSec,
			totalUrls,
			isFiltered: true,
		};
	}, [
		serverFilter,
		serverBreakdownData,
		overview,
		globalRequestsPerSecond,
		totalUrls,
	] );

	// Calculate requests per second for the selected URL.
	const urlRequestsPerSecond = useMemo( () => {
		if ( ! urlDetail?.stats?.time_series ) {
			return 0;
		}
		const buckets = Object.keys( urlDetail.stats.time_series ).sort();
		if ( buckets.length < 2 ) {
			return 0;
		}
		// Skip the accumulating bucket; use up to 12 complete buckets (1 hour).
		const complete = buckets.slice( -13, -1 );
		let total = 0;
		for ( const key of complete ) {
			total += urlDetail.stats.time_series[ key ]?.count || 0;
		}
		return total / ( complete.length * 300 );
	}, [ urlDetail?.stats?.time_series ] );

	// Inline "Log this URL" state: ruleDraft = open rule, existingRule = label.
	const [ ruleDraft, setRuleDraft ] = useState( null );
	// The whole ruleset as the server last reported it; null until it answers.
	const [ rules, setRules ] = useState( null );
	const [ ruleError, setRuleError ] = useState( null );

	const ruleUrl = selectedUrl?.url;
	const canLogUrl = !! ruleUrl && UNKNOWN_URL() !== ruleUrl;
	// Strip origin so exact rule matches REQUEST_URI ('?' = match sentinel).
	const rulePath = ruleUrl ? ruleUrl.replace( /^https?:\/\/[^/]+/, '' ) : '';
	// Append the sentinel only for a URL that lacks a '?'; else use path as-is.
	const needsSentinel = ruleUrl && ! rulePath.includes( '?' );
	const exactPattern = needsSentinel ? `${ rulePath }?` : rulePath;

	// A retried READ; this modal's rule is DERIVED from it, never a copy.
	const { run: listRules } = useCommandOnce( {
		ci: RULES_CI,
		command: 'list',
		retry: true,
		onDone: ( { result } ) => setRules( result?.rules ?? null ),
	} );
	const existingRule =
		canLogUrl && ! selectedRequest && rules
			? rules.find( ( r ) => r.pattern === exactPattern ) ?? null
			: null;

	useEffect( () => {
		setRuleError( null );
		setRuleDraft( null );
		if ( canLogUrl && ! selectedRequest ) {
			listRules( [] );
		}
	}, [ ruleUrl, exactPattern, canLogUrl, selectedRequest, listRules ] );

	// Open RuleEditModal on the exact rule (edit) or a blank seeded rule (add).
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

	// The reply closes the editor or fills the banner; the ruleset re-reads.
	const { run: upsertRule } = useCommandOnce( {
		ci: RULES_CI,
		command: 'upsert',
		onDone: ( { result, error } ) => {
			setRuleDraft( null );
			if ( result ) {
				listRules( [] );
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
	const saveRule = useCallback(
		( draft ) => upsertRule( [ JSON.stringify( draft ) ] ),
		[ upsertRule ]
	);

	const { run: removeRule } = useCommandOnce( {
		ci: RULES_CI,
		command: 'delete',
		onDone: ( { result, error } ) => {
			setRuleDraft( null );
			if ( result ) {
				listRules( [] );
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
			{ /* The `?` picker's answer. Its triggers sit in the headers. */ }
			<AskPanel ask={ ask } />

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
							overview,
							selectedUrl,
							urlDetail,
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
				filteredStats={ filteredOverviewStats }
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
				onSelectResult={ selectSearchResult }
				refreshInterval={ refreshInterval }
				setRefreshInterval={ setRefreshInterval }
				chartMetric={ chartMetric }
				setChartMetric={ setChartMetric }
				chartBreakdown={ chartBreakdown }
				setChartBreakdown={ setChartBreakdown }
				breakdownData={ chartBreakdownData }
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
								onSelect={ selectUrl }
								onParamsChange={ handleUrlParamsChange }
								totalUrls={ totalUrls }
								metric={ chartMetric }
							/>
						</CardBody>
					</Card>
				</div>
			</div>

			{ /* URL/Request Detail Modal */ }
			{ selectedUrl && urlDetail && (
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
								<div className="event-logger-header-stats newspack-nodes-stats-grid">
									<span className="newspack-nodes-stat">
										{ urlRequestsPerSecond.toFixed( 2 ) }
										<small className="newspack-nodes-stat-label">
											req/s
										</small>
									</span>
									<span className="newspack-nodes-stat">
										{ urlDetail.stats?.avg_ms?.toFixed(
											0
										) || 0 }
										ms
										<small className="newspack-nodes-stat-label">
											avg
										</small>
									</span>
									<span className="newspack-nodes-stat">
										{ urlDetail.stats?.p50_ms?.toFixed(
											0
										) || 0 }
										ms
										<small className="newspack-nodes-stat-label">
											p50
										</small>
									</span>
									<span className="newspack-nodes-stat">
										{ urlDetail.stats?.p95_ms?.toFixed(
											0
										) || 0 }
										ms
										<small className="newspack-nodes-stat-label">
											p95
										</small>
									</span>
									<span className="newspack-nodes-stat">
										{ urlDetail.stats?.p99_ms?.toFixed(
											0
										) || 0 }
										ms
										<small className="newspack-nodes-stat-label">
											p99
										</small>
									</span>
									{ ( urlDetail.stats?.avg_peak_mb || 0 ) >
										0 && (
										<span className="newspack-nodes-stat">
											{ urlDetail.stats?.avg_peak_mb?.toFixed(
												1
											) || 0 }
											MB
											<small className="newspack-nodes-stat-label">
												mem
											</small>
										</span>
									) }
								</div>
								<AskButton ask={ ask } />
								<div className="event-logger-rule-control">
									<button
										type="button"
										className="button"
										disabled={ ! canLogUrl }
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
					{ ! selectedRequest && (
						<UrlDetailView
							urlDetail={ urlDetail }
							sortedRequests={ sortedRequests }
							requestSort={ requestSort }
							onRequestSort={ handleRequestSort }
							onSelectRequest={ selectRequest }
							urlHash={ selectedUrl.hash }
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
		</div>
	);
}
