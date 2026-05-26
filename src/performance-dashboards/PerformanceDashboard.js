/* global localStorage, requestAnimationFrame */
/**
 * Performance Dashboard Component
 *
 * Main container for performance monitoring UI.
 *
 * This is the orchestrator over the `performance/*` node graph (mounted by
 * `usePerformanceGraph`). The graph owns all data: `performance/command` issues
 * fetches via the CommandClient and `performance/view` holds the view model.
 * This component reads the published model via
 * `useNodeState('performance/view','view')`, derives its render-time slices, and
 * dispatches control through the hook's returned callbacks. It owns no fetching.
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

import UrlTable from './UrlTable';

import './styles/modal.scss';
import './styles/tables.scss';
import './styles/charts.scss';

/**
 * Performance Dashboard component.
 *
 * @param {Object}   props                 Component props.
 * @param {Function} props.onError         Error handler callback.
 * @param {Object}   [props.commandClient] Optional CommandClient (the graph
 *                                         lazily defaults it in production;
 *                                         tests inject a double).
 * @return {import('react').ReactElement} Rendered component.
 */
export default function PerformanceDashboard( { onError, commandClient } ) {
	// UI / control state (no data state — data comes from the view model).
	const [ requestSort, setRequestSort ] = useState( {
		field: 'timestamp',
		dir: 'desc',
	} );
	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	// Page-wide server filter state (lifted from OverviewSection).
	const [ serverFilter, setServerFilter ] = useState( '' );

	// Breakdown selector state (lifted from OverviewSection so the active
	// dimension rides along on the combined /overview fetch).
	const [ chartBreakdown, setChartBreakdown ] = useState( 'status' );

	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchError, setSearchError ] = useState( null );
	const [ searchLoading, setSearchLoading ] = useState( false );
	const [ requestPartition, setRequestPartition ] = useState( null );
	const [ refreshInterval, setRefreshInterval ] = useState( () => {
		// Load from localStorage with validation against allowed dropdown values.
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
	const selectUrlRef = useRef( () => {} );
	const selectRequestRef = useRef( () => {} );
	const urlsRef = useRef( [] );
	const setRequestPartitionRef = useRef( () => {} );

	// Read the published view model directly (Error Log pattern). Null until the
	// hook mounts the node; the hook's setViewReady forces a re-render to subscribe.
	const view = useNodeState( 'performance/view', 'view' );

	// Derive the slices the orchestrator renders/derives from — SAME NAMES as the
	// deleted state, so every surviving useMemo + the JSX reference them unchanged.
	const overview = view?.overview?.data ?? null;
	const urls = useMemo( () => view?.urls?.data ?? [], [ view?.urls?.data ] );
	const totalUrls = view?.urls?.total ?? 0;
	const urlDetail = view?.urlDetail?.data ?? null;
	const requestDetail = view?.requestDetail?.data ?? null;
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

	// serverNames stays STICKY state: when a filter is active the scoped response
	// collapses to one server, so DON'T overwrite the names list (it would drop the
	// dropdown below 2 entries and trap the user). Mirrors applyServerBreakdown.
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

	// resolveRequestId — used by useUrlNavigation for `?request=`-only deep links.
	// Stable []; reaches the command's resolveRequest via a ref populated post-hook.
	const resolveRequestId = useCallback( async ( rid ) => {
		const data = await commandResolveRef.current?.( rid );
		if ( ! data || ! data.url_hash || data.partition === undefined ) {
			return;
		}
		let urlObj = urlsRef.current.find( ( u ) => u.hash === data.url_hash );
		if ( ! urlObj ) {
			urlObj = {
				hash: data.url_hash,
				url:
					data.url ||
					__( 'Unknown URL', 'newspack-event-logger-nodes' ),
			};
		}
		setRequestPartitionRef.current( data.partition );
		selectUrlRef.current( urlObj );
		selectRequestRef.current( rid );
	}, [] );

	const {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
	} = useUrlNavigation( urls, resolveRequestId );

	selectUrlRef.current = selectUrl;
	selectRequestRef.current = baseSelectRequest;
	setRequestPartitionRef.current = setRequestPartition;

	// Mount the data graph + own all fetching.
	const { handleUrlParamsChange, resolveRequest, fetchUrlBreakdown } =
		usePerformanceGraph( {
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

	// Reset the search-sourced partition when leaving request detail (the old
	// request-detail effect did setRequestPartition(null) here).
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
			const data = await commandResolveRef.current?.( rid.trim() );
			if ( data && data.url_hash && data.partition !== undefined ) {
				let urlObj = urls.find( ( u ) => u.hash === data.url_hash );
				if ( ! urlObj ) {
					urlObj = {
						hash: data.url_hash,
						url:
							data.url ||
							__( 'Unknown URL', 'newspack-event-logger-nodes' ),
					};
				}
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
							'Request "%s" not found',
							'newspack-event-logger-nodes'
						),
						rid
					)
				);
			}
			setSearchLoading( false );
		},
		[ urls, selectUrl, selectRequest, updateBrowserUrl ]
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

	// Calculate requests per second from the last hour of complete 5-minute buckets.
	const globalRequestsPerSecond = useMemo( () => {
		if ( ! overview?.aggregate_time_series ) {
			return 0;
		}
		const buckets = Object.keys( overview.aggregate_time_series ).sort();
		if ( buckets.length < 2 ) {
			return 0;
		}
		// Skip the most recent bucket (still accumulating). Use up to 12 complete buckets = 1 hour.
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
				requestsPerSecond: globalRequestsPerSecond,
				totalUrls,
				isFiltered: false,
			};
		}
		const buckets = Object.keys( serverBreakdownData ).sort();
		let totalC = 0;
		let totalS = 0;
		for ( const key of buckets ) {
			const entry = serverBreakdownData[ key ]?.[ serverFilter ];
			if ( entry ) {
				totalC += entry.c || 0;
				totalS += entry.s || 0;
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
		// Skip the most recent bucket (still accumulating). Use up to 12 complete buckets = 1 hour.
		const complete = buckets.slice( -13, -1 );
		let total = 0;
		for ( const key of complete ) {
			total += urlDetail.stats.time_series[ key ]?.count || 0;
		}
		return total / ( complete.length * 300 );
	}, [ urlDetail?.stats?.time_series ] );

	// Handle initial search query from URL parameter.
	useEffect( () => {
		if ( initialSearchQuery ) {
			searchRequest( initialSearchQuery );
			setInitialSearchQuery( null );
		}
	}, [ initialSearchQuery, searchRequest, setInitialSearchQuery ] );

	// Manage modal scroll position when switching between URL detail and request detail.
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

	// "Initial load not done": no view yet, OR no slice has resolved (lastRefresh
	// null) and overview hasn't errored. true→false once, then stays false — matches
	// the old `loading` flag (cleared after the first attempt, even null/memcache-down
	// data, which still stamps lastRefresh via a 'result').
	const showLoadingScreen =
		! view || ( null === view.lastRefresh && ! view.overview?.error );
	if ( showLoadingScreen ) {
		return (
			<div className="event-logger-performance-loading">
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
				onSearch={ searchRequest }
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
						selectUrl( null );
						selectRequest( null );
					} }
					className="event-logger-performance-modal"
					headerActions={
						! selectedRequest && (
							<div className="event-logger-header-stats">
								<span>
									{ urlRequestsPerSecond.toFixed( 2 ) }
									<small>req/s</small>
								</span>
								<span>
									{ urlDetail.stats?.avg_ms?.toFixed( 0 ) ||
										0 }
									ms
									<small>avg</small>
								</span>
								<span>
									{ urlDetail.stats?.p50_ms?.toFixed( 0 ) ||
										0 }
									ms
									<small>p50</small>
								</span>
								<span>
									{ urlDetail.stats?.p95_ms?.toFixed( 0 ) ||
										0 }
									ms
									<small>p95</small>
								</span>
								<span>
									{ urlDetail.stats?.p99_ms?.toFixed( 0 ) ||
										0 }
									ms
									<small>p99</small>
								</span>
								{ ( urlDetail.stats?.avg_peak_mb || 0 ) > 0 && (
									<span>
										{ urlDetail.stats?.avg_peak_mb?.toFixed(
											1
										) || 0 }
										MB
										<small>mem</small>
									</span>
								) }
							</div>
						)
					}
				>
					{ /* Back button for request view */ }
					{ selectedRequest && requestDetail && (
						<button
							type="button"
							className="event-logger-modal-back-button"
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
		</div>
	);
}
