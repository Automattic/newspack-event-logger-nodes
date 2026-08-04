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

import { useNodeState } from '@newspack-nodes/runtime';
import { computeIndentedEntries } from './utils/logEntryUtils';
import { DASHBOARD_REFRESH_OPTIONS } from './constants';
import { usePerformanceGraph } from './hooks/usePerformanceGraph';
import useUrlNavigation from './hooks/useUrlNavigation';
import OverviewSection from './components/OverviewSection';
import UrlDetailView from './components/UrlDetailView';
import RequestDetailView from './components/RequestDetailView';
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
 * @param {Object}               props                 Component props.
 * @param {(err: Error) => void} props.onError         Error handler callback. Reported
 *                                                     failures surface as the page's
 *                                                     dismissible notice.
 * @param {Object}               [props.commandClient] Optional transport (the graph
 *                                                     lazily defaults it in production;
 *                                                     tests inject a double).
 * @return {import('react').ReactElement} Rendered component.
 */
export default function PerformanceDashboard( { onError, commandClient } ) {
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
	const commandResolveRef = useRef( null );
	const commandResolveUrlRef = useRef( null );
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

	// The one place a hash becomes a title; request_search sends no URL.
	const urlObjForHash = useCallback( async ( hash ) => {
		const known = urlsRef.current.find( ( u ) => u.hash === hash );
		if ( known ) {
			return known;
		}
		const resolved = await commandResolveUrlRef.current?.( hash );
		return { hash, url: resolved?.url || UNKNOWN_URL() };
	}, [] );

	// resolveRequestId for ?request= deep links; reaches resolveRequest by ref.
	const resolveRequestId = useCallback(
		async ( rid ) => {
			const data = await commandResolveRef.current?.( rid );
			if ( ! data || ! data.url_hash || data.partition === undefined ) {
				// Report the miss so the caller holds the intent to retry.
				return false;
			}
			setRequestPartitionRef.current( data.partition );
			selectUrlRef.current( await urlObjForHash( data.url_hash ) );
			selectRequestRef.current( rid );
			return true;
		},
		[ urlObjForHash ]
	);

	// ?url= deep links; null = no answer, so the caller holds the intent.
	const resolveUrlHash = useCallback( async ( hash ) => {
		const resolved = await commandResolveUrlRef.current?.( hash );
		return resolved ? { url: resolved.url || UNKNOWN_URL() } : null;
	}, [] );

	const {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
	} = useUrlNavigation( urls, resolveRequestId, resolveUrlHash );

	selectUrlRef.current = selectUrl;
	selectRequestRef.current = baseSelectRequest;
	setRequestPartitionRef.current = setRequestPartition;

	// Mount the data graph + own all fetching.
	const {
		handleUrlParamsChange,
		resolveRequest,
		resolveUrlHash: graphResolveUrlHash,
		fetchUrlBreakdown,
		listRules,
		upsertRule,
		removeRule,
		requestGrep,
	} = usePerformanceGraph( {
		serverFilter,
		chartBreakdown,
		refreshInterval,
		requestPartition,
		selectedUrl,
		selectedRequest,
		urlDetailData: urlDetail,
		onError,
		commandClient,
	} );
	commandResolveRef.current = resolveRequest;
	commandResolveUrlRef.current = graphResolveUrlHash;

	// Reset the search-sourced partition when leaving request detail.
	useEffect( () => {
		if ( ! selectedRequest ) {
			setRequestPartition( null );
		}
	}, [ selectedRequest ] );

	const urlDetailScrollRef = useRef( 0 );

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
	const searchRequest = useCallback(
		async ( rid ) => {
			if ( ! rid || ! rid.trim() ) {
				return;
			}
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			const data = await commandResolveRef.current?.( rid.trim() );
			if ( data && data.url_hash && data.partition !== undefined ) {
				const urlObj = await urlObjForHash( data.url_hash );
				setRequestPartition( data.partition );
				selectUrl( urlObj );
				selectRequest( rid.trim() );
				setSearchQuery( '' );
				updateBrowserUrl( {
					search: null,
					url: urlObj.hash,
					request: rid.trim(),
				} );
			} else {
				setSearchError(
					sprintf(
						// translators: %s: the request ID that was searched for.
						__(
							'Request "%s" not found — prefix with / to search recent traffic',
							'newspack-event-logger-nodes'
						),
						rid
					)
				);
			}
			setSearchLoading( false );
		},
		[ urlObjForHash, selectUrl, selectRequest, updateBrowserUrl ]
	);

	/**
	 * Pattern-search recent firehose traffic and render the matching-request list.
	 *
	 * @param {string} pattern The search pattern.
	 */
	const patternSearch = useCallback(
		async ( pattern ) => {
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			setSearchResultsTruncated( false );
			const data = await requestGrep( pattern.trim() );
			const results = data?.results ?? [];
			if ( results.length > 0 ) {
				setSearchResults( results );
				setSearchResultsTruncated( !! data?.truncated );
			} else {
				setSearchError(
					__(
						'No matches in recent traffic',
						'newspack-event-logger-nodes'
					)
				);
			}
			setSearchLoading( false );
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

	// Compute indented log entries for display.
	const { entries: indentedEntries, realCount: realEntryCount } = useMemo(
		() => computeIndentedEntries( requestDetail?.entries ),
		[ requestDetail?.entries ]
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
	const [ existingRule, setExistingRule ] = useState( null );
	const [ ruleError, setRuleError ] = useState( null );

	const ruleUrl = selectedUrl?.url;
	const canLogUrl = !! ruleUrl && UNKNOWN_URL() !== ruleUrl;
	// Strip origin so exact rule matches REQUEST_URI ('?' = match sentinel).
	const rulePath = ruleUrl ? ruleUrl.replace( /^https?:\/\/[^/]+/, '' ) : '';
	// Append the sentinel only for a URL that lacks a '?'; else use path as-is.
	const needsSentinel = ruleUrl && ! rulePath.includes( '?' );
	const exactPattern = needsSentinel ? `${ rulePath }?` : rulePath;

	// On modal open, detect an existing exact rule (button label + prefill).
	useEffect( () => {
		setRuleError( null );
		setRuleDraft( null );
		if ( ! canLogUrl || selectedRequest ) {
			setExistingRule( null );
			return undefined;
		}
		let cancelled = false;
		const pattern = exactPattern;
		listRules()
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				const found = ( res?.rules ?? [] ).find(
					( r ) => r.pattern === pattern
				);
				setExistingRule( found ?? null );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setExistingRule( null );
				}
			} );
		return () => {
			cancelled = true;
		};
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

	// Save: upsert exact rule, close only RuleEditModal, error inline on fail.
	const saveRule = useCallback(
		async ( draft ) => {
			const res = await upsertRule( draft );
			setRuleDraft( null );
			if ( ! res ) {
				setRuleError(
					__(
						'Could not save the logging rule.',
						'newspack-event-logger-nodes'
					)
				);
				return;
			}
			setExistingRule( res.rule ?? draft );
		},
		[ upsertRule ]
	);

	// Delete: remove the open rule, close only RuleEditModal, error inline.
	const deleteRule = useCallback( async () => {
		const id = ruleDraft?.id;
		const res = await removeRule( id );
		setRuleDraft( null );
		if ( ! res ) {
			setRuleError(
				__(
					'Could not delete the logging rule.',
					'newspack-event-logger-nodes'
				)
			);
			return;
		}
		setExistingRule( null );
	}, [ removeRule, ruleDraft ] );

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
			{ /* Overview Stats */ }
			<OverviewSection
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
						! selectedRequest && (
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
					{ /* Back button for request view */ }
					{ selectedRequest && requestDetail && (
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
					) }

					{ /* URL Details View */ }
					{ ! selectedRequest && (
						<UrlDetailView
							urlDetail={ urlDetail }
							sortedRequests={ sortedRequests }
							requestSort={ requestSort }
							onRequestSort={ handleRequestSort }
							onSelectRequest={ selectRequest }
							fetchUrlBreakdown={ fetchUrlBreakdown }
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
						/>
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
					className="newspack-nodes-skin-root"
				/>
			) }
		</div>
	);
}
