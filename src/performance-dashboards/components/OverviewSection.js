/**
 * Overview Section Component
 *
 * Displays overview stats, search, refresh controls, aggregate chart, and global leaderboard.
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';

import {
	Card,
	CardBody,
	CardHeader,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import {
	DASHBOARD_REFRESH_OPTIONS,
	CHART_METRIC_OPTIONS,
	CHART_BREAKDOWN_OPTIONS,
} from '../constants';
import AggregateTimeChart from '../AggregateTimeChart';
import CategoryTimeChart from '../CategoryTimeChart';
import RequestProfile from '../RequestProfile';

/**
 * Overview Section component.
 *
 * @param {Object}   props                    Component props.
 * @param {Object}   props.overview           Overview data object.
 * @param {Object}   props.filteredStats      Filtered overview stats from parent.
 * @param {string}   props.serverFilter       Current server filter value.
 * @param {Function} props.setServerFilter    Server filter setter.
 * @param {string[]} props.serverNames        Available server names.
 * @param {string}   props.searchQuery        Search query state.
 * @param {Function} props.setSearchQuery     Search query setter.
 * @param {boolean}  props.searchLoading      Search loading state.
 * @param {string}   props.searchError        Search error message.
 * @param {Function} props.onSearch           Search handler callback.
 * @param {string}   props.refreshInterval    Refresh interval state.
 * @param {Function} props.setRefreshInterval Refresh interval setter.
 * @param {Function} props.fetchBreakdown     Fetch dimensional breakdown data.
 * @param {number}   props.refreshTick        Counter incremented on each main refresh cycle.
 * @param {string}   props.chartMetric        Selected chart metric (lifted from parent).
 * @param {Function} props.setChartMetric     Chart metric setter.
 * @param {Object}   props.categoryData       Category time series data.
 * @return {JSX.Element|null} Rendered component or null if no overview data.
 */
export default function OverviewSection( {
	overview,
	filteredStats,
	serverFilter,
	setServerFilter,
	serverNames,
	searchQuery,
	setSearchQuery,
	searchLoading,
	searchError,
	onSearch,
	refreshInterval,
	setRefreshInterval,
	fetchBreakdown,
	refreshTick,
	chartMetric,
	setChartMetric,
	categoryData,
} ) {
	const [ chartBreakdown, setChartBreakdown ] = useState( 'status' );
	const [ breakdownData, setBreakdownData ] = useState( null );
	const [ breakdownLoading, setBreakdownLoading ] = useState( false );

	// Show server dropdown when 2+ servers detected (hub mode).
	const isMultiServer = serverNames.length >= 2;

	// Build server dropdown options.
	const serverOptions = useMemo( () => {
		if ( ! isMultiServer ) {
			return [];
		}
		return [
			{ label: 'All Servers', value: '' },
			...serverNames.map( ( name ) => ( {
				label: name,
				value: name,
			} ) ),
		];
	}, [ isMultiServer, serverNames ] );

	// When server filter active, hide 'Server' breakdown (redundant).
	const breakdownOptions = useMemo( () => {
		if ( serverFilter ) {
			return CHART_BREAKDOWN_OPTIONS.filter(
				( opt ) => opt.value !== 'server'
			);
		}
		return CHART_BREAKDOWN_OPTIONS;
	}, [ serverFilter ] );

	// Reset breakdown to 'status' if it was 'server' when server filter activates.
	useEffect( () => {
		if ( serverFilter && chartBreakdown === 'server' ) {
			setChartBreakdown( 'status' );
		}
	}, [ serverFilter, chartBreakdown ] );

	// Fetch breakdown data when breakdown or server filter changes.
	const loadBreakdown = useCallback(
		async ( breakdown, server ) => {
			if ( ! fetchBreakdown ) {
				setBreakdownData( null );
				return;
			}
			setBreakdownLoading( true );
			const data = await fetchBreakdown( breakdown, server );
			setBreakdownData( data );
			setBreakdownLoading( false );
		},
		[ fetchBreakdown ]
	);

	// Re-fetch breakdown on every main refresh tick, or when breakdown/server changes.
	useEffect( () => {
		loadBreakdown( chartBreakdown, serverFilter );
	}, [ chartBreakdown, serverFilter, refreshTick, loadBreakdown ] );

	if ( ! overview ) {
		return null;
	}

	return (
		<div className="event-logger-performance-overview">
			<Card>
				<CardHeader>
					<h2>Overview</h2>
					<div
						style={ {
							marginLeft: 'auto',
							display: 'flex',
							alignItems: 'center',
							gap: '16px',
						} }
					>
						{ /* Request ID Search */ }
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '8px',
							} }
						>
							<TextControl
								placeholder="Search request ID..."
								value={ searchQuery }
								onChange={ setSearchQuery }
								onKeyDown={ ( e ) => {
									if ( e.key === 'Enter' ) {
										onSearch( searchQuery );
									}
								} }
								__nextHasNoMarginBottom
								style={ {
									width: '250px',
									marginBottom: 0,
								} }
							/>
							<button
								className="button"
								onClick={ () => onSearch( searchQuery ) }
								disabled={
									searchLoading || ! searchQuery.trim()
								}
								style={ { height: '30px' } }
							>
								{ searchLoading ? 'Searching...' : 'Find' }
							</button>
						</div>
						{ searchError && (
							<span
								style={ {
									color: '#dc3232',
									fontSize: '12px',
								} }
							>
								{ searchError }
							</span>
						) }
						{ /* Refresh Interval */ }
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '8px',
							} }
						>
							<span
								style={ {
									fontSize: '13px',
									color: '#757575',
								} }
							>
								Refresh:
							</span>
							<SelectControl
								value={ refreshInterval }
								options={ DASHBOARD_REFRESH_OPTIONS }
								onChange={ setRefreshInterval }
								__nextHasNoMarginBottom
								style={ { minWidth: '80px' } }
							/>
						</div>
					</div>
				</CardHeader>
				<CardBody>
					<div className="event-logger-stats-grid">
						<div className="event-logger-stat">
							<span className="event-logger-stat-value">
								{ filteredStats.totalUrls }
							</span>
							<span className="event-logger-stat-label">
								Unique URLs
							</span>
						</div>
						<div className="event-logger-stat">
							<span className="event-logger-stat-value">
								{ filteredStats.totalRequests.toLocaleString() }
							</span>
							<span className="event-logger-stat-label">
								Total Requests
							</span>
						</div>
						<div className="event-logger-stat">
							<span className="event-logger-stat-value">
								{ filteredStats.globalAvgMs.toFixed( 0 ) }
								ms
							</span>
							<span className="event-logger-stat-label">
								Avg Response
							</span>
						</div>
						<div className="event-logger-stat">
							<span className="event-logger-stat-value">
								{ filteredStats.requestsPerSecond.toFixed( 2 ) }
							</span>
							<span className="event-logger-stat-label">
								Req/s (last hour)
							</span>
						</div>
						{ overview.global_avg_peak_mb > 0 && (
							<div className="event-logger-stat">
								<span className="event-logger-stat-value">
									{ overview.global_avg_peak_mb.toFixed( 1 ) }
									MB
								</span>
								<span className="event-logger-stat-label">
									Avg Peak Memory
								</span>
							</div>
						) }
					</div>

					{ /* Chart Controls + Chart */ }
					{ overview.aggregate_time_series &&
						Object.keys( overview.aggregate_time_series ).length >
							0 && (
							<div
								className="event-logger-aggregate-chart"
								style={ { marginTop: '20px' } }
							>
								<div
									style={ {
										display: 'flex',
										gap: '16px',
										marginBottom: '12px',
										alignItems: 'flex-end',
										flexWrap: 'wrap',
									} }
								>
									{ isMultiServer && (
										<SelectControl
											label="Server"
											value={ serverFilter }
											options={ serverOptions }
											onChange={ setServerFilter }
											__nextHasNoMarginBottom
											style={ { minWidth: '180px' } }
										/>
									) }
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
										options={ breakdownOptions }
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
									data={ overview.aggregate_time_series }
									breakdownData={ breakdownData }
									metric={ chartMetric }
									breakdown={ chartBreakdown }
									serverFilter={ serverFilter }
								/>
							</div>
						) }

					{ categoryData && (
						<>
							<CategoryTimeChart
								data={ categoryData }
								mode="time"
								title="Time by Category"
							/>
							<CategoryTimeChart
								data={ categoryData }
								mode="count"
								title="Events by Category"
							/>
							<CategoryTimeChart
								data={ categoryData }
								mode="average"
								title="Average Time per Event"
							/>
						</>
					) }

					{ /* Global / Per-Server Leaderboard */ }
					{ overview.global_leaderboard?.categories && (
						<div
							className="event-logger-request-profile"
							style={ { marginTop: '20px' } }
						>
							<h3>
								{ serverFilter
									? `Time Breakdown (${ serverFilter })`
									: 'Global Time Breakdown' }
							</h3>
							<RequestProfile
								profiles={
									overview.global_leaderboard.categories
								}
								totalMs={ overview.global_avg_ms || 0 }
								totalProfiledTime={
									overview.global_leaderboard.total_time
								}
								title={ null }
							/>
							<p
								style={ {
									fontSize: '12px',
									color: '#666',
									marginTop: '8px',
								} }
							>
								Average breakdown across{ ' ' }
								{ overview.global_leaderboard.count || 0 }{ ' ' }
								requests
								{ serverFilter ? ` on ${ serverFilter }` : '' }
							</p>
						</div>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
