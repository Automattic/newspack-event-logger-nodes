/**
 * Overview Section Component
 *
 * Displays overview stats, search, refresh controls, aggregate chart, and global leaderboard.
 */

import { useEffect, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

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
 * @param {Object}     props                        Component props.
 * @param {Object}     props.overview               Overview data object.
 * @param {Object}     props.filteredStats          Filtered overview stats from parent.
 * @param {string}     props.serverFilter           Current server filter value.
 * @param {Function}   props.setServerFilter        Server filter setter.
 * @param {string[]}   props.serverNames            Available server names.
 * @param {string}     props.searchQuery            Search query state.
 * @param {Function}   props.setSearchQuery         Search query setter.
 * @param {boolean}    props.searchLoading          Search loading state.
 * @param {string}     props.searchError            Search error message.
 * @param {Function}   props.onSearch               Search handler callback.
 * @param {Array|null} props.searchResults          Pattern-search result rows, or null.
 * @param {boolean}    props.searchResultsTruncated Whether the result set was capped.
 * @param {Function}   props.onSelectResult         Row-click handler (deep-links by rid).
 * @param {string}     props.refreshInterval        Refresh interval state.
 * @param {Function}   props.setRefreshInterval     Refresh interval setter.
 * @param {string}     props.chartMetric            Selected chart metric (lifted from parent).
 * @param {Function}   props.setChartMetric         Chart metric setter.
 * @param {string}     props.chartBreakdown         Selected breakdown dim (lifted from parent).
 * @param {Function}   props.setChartBreakdown      Breakdown dim setter.
 * @param {Object}     props.breakdownData          Breakdown time series for current dim.
 * @param {Object}     props.categoryData           Category time series data.
 * @return {Object|null} Rendered component or null if no overview data.
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
	searchResults,
	searchResultsTruncated,
	onSelectResult,
	refreshInterval,
	setRefreshInterval,
	chartMetric,
	setChartMetric,
	chartBreakdown,
	setChartBreakdown,
	breakdownData,
	categoryData,
} ) {
	// breakdownData/chartBreakdown lifted so the dim rides combined /overview.
	const breakdownLoading = false;

	// Show server dropdown when 2+ servers detected (hub mode).
	const isMultiServer = serverNames.length >= 2;

	// Build server dropdown options.
	const serverOptions = useMemo( () => {
		if ( ! isMultiServer ) {
			return [];
		}
		return [
			{
				label: __( 'All Servers', 'newspack-event-logger-nodes' ),
				value: '',
			},
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

	// Reset breakdown 'server'→'status' when server filter activates.
	useEffect( () => {
		if ( serverFilter && chartBreakdown === 'server' ) {
			setChartBreakdown( 'status' );
		}
	}, [ serverFilter, chartBreakdown, setChartBreakdown ] );

	if ( ! overview ) {
		return null;
	}

	return (
		<div className="event-logger-performance-overview">
			<Card>
				<CardHeader>
					<h2>{ __( 'Overview', 'newspack-event-logger-nodes' ) }</h2>
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
								__next40pxDefaultSize
								placeholder={ __(
									'Request ID or /url pattern…',
									'newspack-event-logger-nodes'
								) }
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
								{ searchLoading
									? __(
											'Searching…',
											'newspack-event-logger-nodes'
									  )
									: __(
											'Find',
											'newspack-event-logger-nodes'
									  ) }
							</button>
						</div>
						{ searchError && (
							<span
								className="newspack-nodes-status is-error"
								style={ {
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
								className="newspack-nodes-status"
								style={ {
									fontSize: '13px',
								} }
							>
								{ __(
									'Refresh:',
									'newspack-event-logger-nodes'
								) }
							</span>
							<SelectControl
								__next40pxDefaultSize
								className="newspack-nodes-select"
								value={ refreshInterval }
								options={ DASHBOARD_REFRESH_OPTIONS }
								onChange={ setRefreshInterval }
								__nextHasNoMarginBottom
								style={ { minWidth: '80px' } }
							/>
						</div>
					</div>
				</CardHeader>
				{ Array.isArray( searchResults ) &&
					searchResults.length > 0 && (
						<div className="event-logger-search-results">
							<p className="event-logger-search-results-caption newspack-nodes-status">
								{ __(
									'Matches in recent traffic',
									'newspack-event-logger-nodes'
								) }
							</p>
							<ul>
								{ searchResults.map( ( result ) => (
									<li key={ result.rid }>
										<button
											type="button"
											className="button button-small event-logger-search-result"
											onClick={ () =>
												onSelectResult( result.rid )
											}
										>
											<span className="event-logger-search-result-method newspack-nodes-status">
												{ result.method }
											</span>
											<span className="event-logger-search-result-url">
												{ result.url || result.rid }
											</span>
											<span className="event-logger-search-result-count newspack-nodes-status">
												{ sprintf(
													// translators: %d: number of matching lines in the request.
													_n(
														'%d match',
														'%d matches',
														result.match_count || 0,
														'newspack-event-logger-nodes'
													),
													result.match_count || 0
												) }
											</span>
										</button>
									</li>
								) ) }
							</ul>
							{ searchResultsTruncated && (
								<p className="event-logger-search-results-note newspack-nodes-status">
									{ __(
										'Showing first results — narrow your search for more.',
										'newspack-event-logger-nodes'
									) }
								</p>
							) }
						</div>
					) }
				<CardBody>
					<div className="newspack-nodes-stats-grid event-logger-overview-stats">
						<div className="newspack-nodes-stat">
							<span className="newspack-nodes-stat-value">
								{ filteredStats.totalUrls }
							</span>
							<span className="newspack-nodes-stat-label">
								{ __(
									'Unique URLs',
									'newspack-event-logger-nodes'
								) }
							</span>
						</div>
						<div className="newspack-nodes-stat">
							<span className="newspack-nodes-stat-value">
								{ filteredStats.totalRequests.toLocaleString() }
							</span>
							<span className="newspack-nodes-stat-label">
								{ __(
									'Total Requests',
									'newspack-event-logger-nodes'
								) }
							</span>
						</div>
						<div className="newspack-nodes-stat">
							<span className="newspack-nodes-stat-value">
								{ filteredStats.globalAvgMs.toFixed( 0 ) }
								ms
							</span>
							<span className="newspack-nodes-stat-label">
								{ __(
									'Avg Response',
									'newspack-event-logger-nodes'
								) }
							</span>
						</div>
						<div className="newspack-nodes-stat">
							<span className="newspack-nodes-stat-value">
								{ filteredStats.requestsPerSecond.toFixed( 2 ) }
							</span>
							<span className="newspack-nodes-stat-label">
								{ __(
									'Req/s (last hour)',
									'newspack-event-logger-nodes'
								) }
							</span>
						</div>
						{ filteredStats.globalAvgPeakMb > 0 && (
							<div className="newspack-nodes-stat">
								<span className="newspack-nodes-stat-value">
									{ filteredStats.globalAvgPeakMb.toFixed(
										1
									) }
									MB
								</span>
								<span className="newspack-nodes-stat-label">
									{ __(
										'Avg Peak Memory',
										'newspack-event-logger-nodes'
									) }
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
											__next40pxDefaultSize
											label={ __(
												'Server',
												'newspack-event-logger-nodes'
											) }
											value={ serverFilter }
											options={ serverOptions }
											onChange={ setServerFilter }
											__nextHasNoMarginBottom
											style={ { minWidth: '180px' } }
										/>
									) }
									<SelectControl
										__next40pxDefaultSize
										label={ __(
											'Metric',
											'newspack-event-logger-nodes'
										) }
										value={ chartMetric }
										options={ CHART_METRIC_OPTIONS }
										onChange={ setChartMetric }
										__nextHasNoMarginBottom
										style={ { minWidth: '180px' } }
									/>
									<SelectControl
										__next40pxDefaultSize
										label={ __(
											'Breakdown',
											'newspack-event-logger-nodes'
										) }
										value={ chartBreakdown }
										options={ breakdownOptions }
										onChange={ setChartBreakdown }
										__nextHasNoMarginBottom
										style={ { minWidth: '140px' } }
									/>
									{ breakdownLoading && (
										<span
											className="newspack-nodes-status"
											style={ {
												fontSize: '12px',
												paddingBottom: '8px',
											} }
										>
											{ __(
												'Loading…',
												'newspack-event-logger-nodes'
											) }
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
								title={ __(
									'Time by Category',
									'newspack-event-logger-nodes'
								) }
							/>
							<CategoryTimeChart
								data={ categoryData }
								mode="count"
								title={ __(
									'Events by Category',
									'newspack-event-logger-nodes'
								) }
							/>
							<CategoryTimeChart
								data={ categoryData }
								mode="average"
								title={ __(
									'Average Time per Event',
									'newspack-event-logger-nodes'
								) }
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
									? sprintf(
											// translators: %s: the server name being filtered by.
											__(
												'Time Breakdown (%s)',
												'newspack-event-logger-nodes'
											),
											serverFilter
									  )
									: __(
											'Global Time Breakdown',
											'newspack-event-logger-nodes'
									  ) }
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
								className="newspack-nodes-status"
								style={ {
									fontSize: '12px',
									marginTop: '8px',
								} }
							>
								{ serverFilter
									? sprintf(
											// translators: 1: number of requests, 2: the server name.
											_n(
												'Average breakdown across %1$d request on %2$s',
												'Average breakdown across %1$d requests on %2$s',
												overview.global_leaderboard
													.count || 0,
												'newspack-event-logger-nodes'
											),
											overview.global_leaderboard.count ||
												0,
											serverFilter
									  )
									: sprintf(
											// translators: %d: number of requests.
											_n(
												'Average breakdown across %d request',
												'Average breakdown across %d requests',
												overview.global_leaderboard
													.count || 0,
												'newspack-event-logger-nodes'
											),
											overview.global_leaderboard.count ||
												0
									  ) }
							</p>
						</div>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
