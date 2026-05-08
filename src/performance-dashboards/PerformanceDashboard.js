/* global localStorage, requestAnimationFrame */
/**
 * Performance Dashboard Component
 *
 * Main container for performance monitoring UI.
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
import apiFetch from '@wordpress/api-fetch';

import { computeIndentedEntries } from './utils/logEntryUtils';
import { DASHBOARD_REFRESH_OPTIONS } from './constants';
import usePerformanceApi from './hooks/usePerformanceApi';
import useUrlNavigation from './hooks/useUrlNavigation';
import usePageVisibility from './shared/hooks/usePageVisibility';
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
 * @param {Object}   props         Component props.
 * @param {Function} props.onError Error handler callback.
 * @return {JSX.Element} Rendered component.
 */
export default function PerformanceDashboard( { onError } ) {
	// Core data state.
	const [ loading, setLoading ] = useState( true );
	const [ overview, setOverview ] = useState( null );
	const [ urls, setUrls ] = useState( [] );
	const [ totalUrls, setTotalUrls ] = useState( 0 );
	const [ urlDetail, setUrlDetail ] = useState( null );
	const [ categoryData, setCategoryData ] = useState( null );

	const [ requestDetail, setRequestDetail ] = useState( null );
	const urlDetailLastModifiedRef = useRef( null );

	// Server-side URL table params (search/sort/pagination).
	const urlParamsRef = useRef( {
		search: '',
		sort: 'count',
		order: 'desc',
		offset: 0,
	} );
	const urlFetchTimerRef = useRef( null );

	// UI state.
	const [ requestSort, setRequestSort ] = useState( {
		field: 'timestamp',
		dir: 'desc',
	} );
	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	// Page-wide server filter state (lifted from OverviewSection).
	const [ serverFilter, setServerFilter ] = useState( '' );
	const serverFilterRef = useRef( '' );
	serverFilterRef.current = serverFilter;
	const [ serverBreakdownData, setServerBreakdownData ] = useState( null );
	const [ serverNames, setServerNames ] = useState( [] );

	// Incremented on each main refresh so child components can sync.
	const [ refreshTick, setRefreshTick ] = useState( 0 );

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
	const isPageVisible = usePageVisibility();

	// Custom hooks.
	const api = usePerformanceApi( onError );
	const apiRef = useRef( api );
	apiRef.current = api;
	const {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
	} = useUrlNavigation( urls );

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
	 * Handle URL table param changes (search/sort/pagination).
	 * Debounces search, fetches immediately for sort/page changes.
	 *
	 * @param {Object} params New params from UrlTable.
	 */
	const handleUrlParamsChange = useCallback( ( params ) => {
		const prev = urlParamsRef.current;
		if (
			prev.search === params.search &&
			prev.sort === params.sort &&
			prev.order === params.order &&
			prev.offset === params.offset
		) {
			return;
		}

		const searchChanged = prev.search !== params.search;
		urlParamsRef.current = params;

		if ( urlFetchTimerRef.current ) {
			clearTimeout( urlFetchTimerRef.current );
		}

		const doFetch = async () => {
			const result = await apiRef.current.fetchUrls( {
				...params,
				server: serverFilterRef.current,
			} );
			if ( result ) {
				setUrls( result.data );
				setTotalUrls( result.total );
			}
		};

		if ( searchChanged ) {
			urlFetchTimerRef.current = setTimeout( doFetch, 300 );
		} else {
			doFetch();
		}
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

			try {
				// Search for request to get partition and URL hash.
				const data = await apiFetch( {
					path: `/newspack-nodes/v1/performance/requests/search/${ encodeURIComponent(
						rid.trim()
					) }`,
				} );

				if ( data && data.url_hash && data.partition !== undefined ) {
					// Find or create a URL object for this hash.
					let urlObj = urls.find( ( u ) => u.hash === data.url_hash );
					if ( ! urlObj ) {
						// URL not in current list - create minimal object.
						urlObj = {
							hash: data.url_hash,
							url: data.url || 'Unknown URL',
						};
					}

					// Store partition for fetching request detail.
					setRequestPartition( data.partition );

					// Open the URL detail modal and select the request.
					selectUrl( urlObj );
					selectRequest( rid.trim() );
					setSearchQuery( '' );

					// Update browser URL with search cleared.
					updateBrowserUrl( {
						search: null,
						url: urlObj.hash,
						request: rid.trim(),
					} );
				} else {
					setSearchError( `Request "${ rid }" not found` );
				}
			} catch {
				setSearchError( `Request "${ rid }" not found` );
			} finally {
				setSearchLoading( false );
			}
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

	// Cleanup URL fetch timer on unmount.
	useEffect( () => {
		return () => {
			if ( urlFetchTimerRef.current ) {
				clearTimeout( urlFetchTimerRef.current );
			}
		};
	}, [] );

	// Fetch overview and URLs on mount.
	useEffect( () => {
		const loadData = async () => {
			const [ overviewData, urlsResult ] = await Promise.all( [
				apiRef.current.fetchOverview( serverFilterRef.current ),
				apiRef.current.fetchUrls( {
					...urlParamsRef.current,
					server: serverFilterRef.current,
				} ),
			] );
			if ( overviewData ) {
				setOverview( overviewData );
				if ( overviewData.category_time_series ) {
					setCategoryData( overviewData.category_time_series );
				}
			}
			if ( urlsResult ) {
				setUrls( urlsResult.data );
				setTotalUrls( urlsResult.total );
			}
			setLoading( false );
		};

		loadData();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- refs are stable.

	// Re-fetch URLs and overview when server filter changes.
	useEffect( () => {
		if ( loading ) {
			return;
		}
		( async () => {
			const [ overviewData, result ] = await Promise.all( [
				apiRef.current.fetchOverview( serverFilter ),
				apiRef.current.fetchUrls( {
					...urlParamsRef.current,
					server: serverFilter,
				} ),
			] );
			if ( overviewData ) {
				setOverview( overviewData );
				if ( overviewData.category_time_series ) {
					setCategoryData( overviewData.category_time_series );
				}
			}
			if ( result ) {
				setUrls( result.data );
				setTotalUrls( result.total );
			}
		} )();
	}, [ serverFilter ] ); // eslint-disable-line react-hooks/exhaustive-deps -- refs are stable.

	// Auto-refresh dashboard only when modal is closed and page is visible.
	const lastRefreshRef = useRef( 0 );
	useEffect( () => {
		// Skip refreshes when URL detail modal is open or page is hidden.
		if ( selectedUrl || ! isPageVisible ) {
			return;
		}

		const doRefresh = async () => {
			lastRefreshRef.current = Date.now();
			const [ overviewData, urlsResult, serverData ] = await Promise.all(
				[
					apiRef.current.fetchOverview( serverFilterRef.current ),
					apiRef.current.fetchUrls( {
						...urlParamsRef.current,
						server: serverFilterRef.current,
					} ),
					apiRef.current.fetchBreakdown( 'server' ),
				]
			);
			if ( overviewData ) {
				setOverview( overviewData );
				if ( overviewData.category_time_series ) {
					setCategoryData( overviewData.category_time_series );
				}
			}
			if ( urlsResult ) {
				setUrls( urlsResult.data );
				setTotalUrls( urlsResult.total );
			}
			if ( serverData ) {
				setServerBreakdownData( serverData );
				const names = new Set();
				Object.values( serverData ).forEach( ( bucket ) => {
					Object.keys( bucket ).forEach( ( n ) => names.add( n ) );
				} );
				setServerNames( Array.from( names ).sort() );
			}
			setRefreshTick( ( t ) => t + 1 );
		};

		const intervalMs = parseInt( refreshInterval, 10 );

		// Refresh immediately if stale (last refresh older than the interval).
		if ( Date.now() - lastRefreshRef.current >= intervalMs ) {
			doRefresh();
		}

		const interval = setInterval( doRefresh, intervalMs );

		return () => clearInterval( interval );
	}, [ refreshInterval, selectedUrl, isPageVisible ] );

	// Fetch server dimensional data to populate server filter and compute per-server stats.
	useEffect( () => {
		let cancelled = false;
		( async () => {
			const data = await apiRef.current.fetchBreakdown( 'server' );
			if ( cancelled || ! data ) {
				return;
			}
			setServerBreakdownData( data );
			const names = new Set();
			Object.values( data ).forEach( ( bucket ) => {
				Object.keys( bucket ).forEach( ( n ) => names.add( n ) );
			} );
			setServerNames( Array.from( names ).sort() );
		} )();
		return () => {
			cancelled = true;
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- apiRef is stable.

	// Merge new requests incrementally to avoid full table re-renders.
	const mergeUrlDetail = useCallback( ( data, isInitial = false ) => {
		if ( ! data ) {
			return;
		}
		urlDetailLastModifiedRef.current = data.last_modified;

		if ( isInitial ) {
			setUrlDetail( data );
			return;
		}

		setUrlDetail( ( prev ) => {
			if ( ! prev?.requests?.length ) {
				return data;
			}

			// Build set of existing rids.
			const existingRids = new Set( prev.requests.map( ( r ) => r.rid ) );

			// Find truly new requests.
			const newRequests =
				data.requests?.filter( ( r ) => ! existingRids.has( r.rid ) ) ||
				[];

			if ( newRequests.length === 0 ) {
				// No new requests - keep existing array reference, just update stats.
				return { ...data, requests: prev.requests };
			}

			// Merge, sort by timestamp descending, take newest 500.
			const merged = [ ...newRequests, ...prev.requests ]
				.sort( ( a, b ) => ( b.timestamp || 0 ) - ( a.timestamp || 0 ) )
				.slice( 0, 500 );
			return { ...data, requests: merged };
		} );
	}, [] );

	// Fetch URL detail when selection changes + auto-refresh.
	useEffect( () => {
		if ( selectedUrl ) {
			const load = async () => {
				const data = await apiRef.current.fetchUrlDetail(
					selectedUrl.hash
				);
				mergeUrlDetail( data, true );
			};
			load();

			// Only auto-refresh URL detail when not viewing a specific request and page is visible.
			if ( ! selectedRequest && isPageVisible ) {
				const intervalMs = parseInt( refreshInterval, 10 );
				const interval = setInterval( async () => {
					const data = await apiRef.current.fetchUrlDetail(
						selectedUrl.hash
					);
					// Skip if data hasn't changed (compare last_modified via ref).
					if (
						data &&
						data.last_modified !== urlDetailLastModifiedRef.current
					) {
						mergeUrlDetail( data );
					}
				}, intervalMs );

				return () => clearInterval( interval );
			}
		} else {
			urlDetailLastModifiedRef.current = null;
			setUrlDetail( null );
		}
	}, [
		selectedUrl,
		selectedRequest,
		refreshInterval,
		isPageVisible,
		mergeUrlDetail,
	] );

	// Fetch request detail when selection changes.
	useEffect( () => {
		if ( selectedRequest ) {
			// Use stored partition from search, or look up from urlDetail.requests.
			let partition = requestPartition;
			if ( partition === null && urlDetail?.requests ) {
				const reqInfo = urlDetail.requests.find(
					( r ) => r.rid === selectedRequest
				);
				partition = reqInfo?.partition;
			}

			if ( partition !== undefined && partition !== null ) {
				const load = async () => {
					const data = await apiRef.current.fetchRequestDetail(
						selectedRequest,
						partition
					);
					if ( data ) {
						setRequestDetail( data );
					}
				};
				load();
			}
		} else {
			setRequestDetail( null );
			setRequestPartition( null );
		}
	}, [ selectedRequest, urlDetail, requestPartition ] );

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

	// Handle initial search query from URL parameter.
	useEffect( () => {
		if ( initialSearchQuery ) {
			searchRequest( initialSearchQuery );
			setInitialSearchQuery( null );
		}
	}, [ initialSearchQuery, searchRequest, setInitialSearchQuery ] );

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

	if ( loading && ! overview ) {
		return (
			<div className="event-logger-performance-loading">
				<Spinner />
				<p>Loading performance data...</p>
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
				fetchBreakdown={ api.fetchBreakdown }
				refreshTick={ refreshTick }
				chartMetric={ chartMetric }
				setChartMetric={ setChartMetric }
				categoryData={ categoryData }
			/>

			{ /* Main Content */ }
			<div className="event-logger-performance-content">
				{ /* URL List */ }
				<div className="event-logger-performance-urls">
					<Card>
						<CardHeader>
							<h2>URLs by Request Count</h2>
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
							? `Request: ${ selectedRequest }`
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
							aria-label="Back to URL details"
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
							fetchUrlBreakdown={ api.fetchUrlBreakdown }
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
