/**
 * URL Detail View Component
 *
 * Displays URL detail content including response time chart, aggregate flame graph,
 * aggregate profile breakdown, and virtualized recent requests table.
 */

import {
	lazy,
	Suspense,
	useRef,
	memo,
	useMemo,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { CHART_METRIC_OPTIONS, CHART_BREAKDOWN_OPTIONS } from '../constants';

// Lazy load FlameGraph (heaviest component - uses d3-flame-graph).
const FlameGraph = lazy( () => import( '../FlameGraph' ) );

import ResponseTimeChart from '../ResponseTimeChart';
import RequestProfile from '../RequestProfile';
import AggregateTimeChart from '../AggregateTimeChart';
import CategoryTimeChart from '../CategoryTimeChart';
import { getStatusColor } from '../../shared/utils/formatUtils';
import useVirtualization from '../../shared/hooks/useVirtualization';

const ROW_HEIGHT = 40;

/**
 * Get color for error status indicator.
 *
 * @param {string} errorStatus Error status code ('F', 'T', or falsy).
 * @return {string|null} Color hex or null.
 */
const getErrorStatusColor = ( errorStatus ) => {
	if ( errorStatus === 'F' ) {
		return '#d63638';
	}
	if ( errorStatus === 'T' ) {
		return '#dba617';
	}
	return null;
};

/**
 * Memoized request row component.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.req      Request data object.
 * @param {Function} props.onSelect Selection callback.
 * @return {import('react').ReactElement} Rendered row.
 */
const RequestRow = memo( function RequestRow( {
	req,
	onSelect,
	maxBar,
	metric,
} ) {
	const barField = metric === 'memory' ? 'peak_mb' : 'duration_ms';
	const barValue = req[ barField ] || 0;
	const barPct = maxBar > 0 ? ( barValue / maxBar ) * 100 : 0;
	const statusColor =
		getErrorStatusColor( req.error_status ) ||
		getStatusColor( req.status_code );
	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			onSelect( req.rid );
		}
	};

	return (
		<div
			role="button"
			tabIndex={ 0 }
			className="event-logger-table__row"
			style={ { height: ROW_HEIGHT } }
			onClick={ () => onSelect( req.rid ) }
			onKeyDown={ handleKeyDown }
		>
			<div className="event-logger-table__cell">
				{ new Date( req.timestamp * 1000 ).toLocaleString() }
			</div>
			<div className="event-logger-table__cell">
				{ req.method || '-' }
			</div>
			<div
				className="event-logger-table__cell event-logger-table__cell--mono"
				style={ {
					background: `linear-gradient(to right, rgba(100, 181, 246, 0.15) ${ barPct }%, transparent ${ barPct }%)`,
				} }
			>
				<code>{ req.rid }</code>
			</div>
			<div
				className="event-logger-table__cell event-logger-table__cell--status"
				style={ { color: statusColor } }
			>
				{ req.error_status === 'F' && (
					<span title="Fatal error">F</span>
				) }
				{ req.error_status === 'T' && <span title="Timed out">T</span> }
				{ ! req.error_status && ( req.status_code || '-' ) }
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ req.duration_ms?.toFixed( 0 ) || 0 }ms
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ req.peak_mb > 0 ? `${ req.peak_mb }MB` : '-' }
			</div>
		</div>
	);
} );

/**
 * URL Detail View component.
 *
 * @param {Object}   props                   Component props.
 * @param {Object}   props.urlDetail         URL detail data object.
 * @param {Array}    props.sortedRequests    Sorted array of recent requests.
 * @param {Object}   props.requestSort       Current sort {field, dir}.
 * @param {Function} props.onRequestSort     Sort handler callback.
 * @param {Function} props.onSelectRequest   Request selection callback.
 * @param {Function} props.fetchUrlBreakdown Fetch per-URL breakdown data.
 * @param {string}   props.urlHash           URL hash identifier.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlDetailView( {
	urlDetail,
	sortedRequests,
	requestSort,
	onRequestSort,
	onSelectRequest,
	fetchUrlBreakdown,
	urlHash,
} ) {
	const listRef = useRef( null );
	const [ errorsOnly, setErrorsOnly ] = useState( false );

	const filteredRequests = useMemo( () => {
		if ( ! errorsOnly ) {
			return sortedRequests;
		}
		return sortedRequests.filter(
			( r ) => r.error_status === 'F' || r.error_status === 'T'
		);
	}, [ sortedRequests, errorsOnly ] );

	// Virtualize based on modal scroll position.
	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization(
			listRef,
			ROW_HEIGHT,
			filteredRequests.length,
			'.components-modal__content'
		);
	const visibleRequests = filteredRequests.slice( startIndex, endIndex );

	// --- Breakdown chart controls ---
	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	// Calculate max value for bar chart backgrounds (metric-aware).
	const maxBar = useMemo( () => {
		const field = chartMetric === 'memory' ? 'peak_mb' : 'duration_ms';
		return filteredRequests.reduce(
			( max, r ) => Math.max( max, r[ field ] || 0 ),
			0
		);
	}, [ filteredRequests, chartMetric ] );
	const [ chartBreakdown, setChartBreakdown ] = useState( 'status' );
	const [ breakdownData, setBreakdownData ] = useState( null );
	const [ breakdownLoading, setBreakdownLoading ] = useState( false );

	const loadBreakdown = useCallback(
		async ( breakdown ) => {
			if ( ! fetchUrlBreakdown || ! urlHash ) {
				setBreakdownData( null );
				return;
			}
			setBreakdownLoading( true );
			const data = await fetchUrlBreakdown( urlHash, breakdown );
			setBreakdownData( data );
			setBreakdownLoading( false );
		},
		[ fetchUrlBreakdown, urlHash ]
	);

	useEffect( () => {
		loadBreakdown( chartBreakdown );
	}, [ chartBreakdown, loadBreakdown ] );

	// Re-fetch breakdown data every 5 minutes to keep charts current.
	useEffect( () => {
		const id = setInterval( () => {
			loadBreakdown( chartBreakdown );
		}, 300000 );
		return () => clearInterval( id );
	}, [ chartBreakdown, loadBreakdown ] );

	/**
	 * Render sortable column header.
	 *
	 * @param {string} field   Field name.
	 * @param {string} label   Display label.
	 * @param {string} variant Optional modifier class variant.
	 * @return {import('react').ReactElement} Header element.
	 */
	const renderSortHeader = ( field, label, variant = '' ) => (
		<button
			type="button"
			className={ `event-logger-table__header-btn${
				variant ? ` event-logger-table__header-btn--${ variant }` : ''
			}` }
			onClick={ () => onRequestSort( field ) }
		>
			{ label }
			{ requestSort.field === field && (
				<span style={ { marginLeft: '4px' } }>
					{ requestSort.dir === 'desc' ? '\u25BC' : '\u25B2' }
				</span>
			) }
		</button>
	);

	return (
		<>
			{ /* Time Series Chart with Breakdown Controls */ }
			{ urlDetail.stats?.time_series &&
				Object.keys( urlDetail.stats.time_series ).length > 0 && (
					<div className="event-logger-aggregate-chart">
						<div
							style={ {
								display: 'flex',
								gap: '16px',
								marginBottom: '12px',
								alignItems: 'flex-end',
								flexWrap: 'wrap',
							} }
						>
							<SelectControl
								label="Metric"
								value={ chartMetric }
								options={ CHART_METRIC_OPTIONS }
								onChange={ setChartMetric }
								__nextHasNoMarginBottom
								style={ { minWidth: '180px' } }
							/>
							<SelectControl
								label="Breakdown"
								value={ chartBreakdown }
								options={ CHART_BREAKDOWN_OPTIONS }
								onChange={ setChartBreakdown }
								__nextHasNoMarginBottom
								style={ { minWidth: '140px' } }
							/>
							{ breakdownLoading && (
								<span
									style={ {
										fontSize: '12px',
										color: '#757575',
										paddingBottom: '8px',
									} }
								>
									Loading...
								</span>
							) }
						</div>
						<AggregateTimeChart
							data={ urlDetail.stats.time_series }
							breakdownData={ breakdownData }
							metric={ chartMetric }
							breakdown={ chartBreakdown }
						/>
					</div>
				) }

			{ /* Category Time Series Charts */ }
			{ urlDetail?.category_time_series && (
				<>
					<CategoryTimeChart
						data={ urlDetail.category_time_series }
						mode="time"
						title="Time by Category"
					/>
					<CategoryTimeChart
						data={ urlDetail.category_time_series }
						mode="count"
						title="Events by Category"
					/>
					<CategoryTimeChart
						data={ urlDetail.category_time_series }
						mode="average"
						title="Average Time per Event"
					/>
				</>
			) }

			{ /* Response Time Chart (individual requests) */ }
			{ urlDetail.requests?.length > 0 && (
				<ResponseTimeChart
					requests={ urlDetail.requests }
					onRequestClick={ onSelectRequest }
				/>
			) }

			{ /* Flame Graph */ }
			{ urlDetail.aggregate_flame &&
				urlDetail.aggregate_flame.children?.length > 0 && (
					<div
						className="event-logger-flame-container"
						style={ { marginTop: '20px' } }
					>
						<h3>Aggregate Flame Graph</h3>
						<Suspense fallback={ <div>Loading chart...</div> }>
							<FlameGraph
								data={ urlDetail.aggregate_flame }
								lastModified={ urlDetail.last_modified }
							/>
						</Suspense>
					</div>
				) }

			{ /* Aggregate Profile Breakdown */ }
			{ urlDetail.aggregate_profiles?.categories && (
				<div style={ { marginTop: '20px' } }>
					<RequestProfile
						profiles={ urlDetail.aggregate_profiles.categories }
						totalMs={ urlDetail.stats?.avg_ms || 0 }
						totalProfiledTime={
							urlDetail.aggregate_profiles?.total_time
						}
					/>
					<p
						style={ {
							fontSize: '12px',
							color: '#666',
							marginTop: '8px',
						} }
					>
						Average breakdown across{ ' ' }
						{ urlDetail.aggregate_profiles?.count || 0 } requests
					</p>
				</div>
			) }

			{ /* Virtualized Recent Requests */ }
			<div className="event-logger-table event-logger-table--requests">
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '12px',
						marginBottom: '8px',
					} }
				>
					<h3 style={ { margin: 0 } }>
						Recent Requests ({ filteredRequests.length })
					</h3>
					<Button
						variant={ errorsOnly ? 'primary' : 'secondary' }
						isSmall
						onClick={ () => setErrorsOnly( ! errorsOnly ) }
					>
						{ errorsOnly ? 'Showing Errors' : 'Errors Only' }
					</Button>
				</div>

				{ /* Header outside scroll container */ }
				<div className="event-logger-table__header">
					{ renderSortHeader( 'timestamp', 'Time' ) }
					<div className="event-logger-table__cell">Method</div>
					<div className="event-logger-table__cell">Request ID</div>
					{ renderSortHeader( 'status_code', 'Status', 'center' ) }
					{ renderSortHeader( 'duration_ms', 'Duration', 'numeric' ) }
					{ renderSortHeader( 'peak_mb', 'Mem', 'numeric' ) }
					<div
						className="event-logger-table__cell event-logger-table__cell--center"
						style={ { width: '30px' } }
					></div>
				</div>

				<div ref={ listRef } className="event-logger-table__list">
					{ filteredRequests.length === 0 ? (
						<div className="event-logger-table__empty">
							No requests to display
						</div>
					) : (
						<>
							<div style={ { height: paddingTop } } />
							{ visibleRequests.map( ( req ) => (
								<RequestRow
									key={ req.rid }
									req={ req }
									onSelect={ onSelectRequest }
									maxBar={ maxBar }
									metric={ chartMetric }
								/>
							) ) }
							<div style={ { height: paddingBottom } } />
						</>
					) }
				</div>
			</div>
		</>
	);
}
