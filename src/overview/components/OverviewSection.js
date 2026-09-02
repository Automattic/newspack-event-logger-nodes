/**
 * Overview Section Component
 *
 * The top card of the Performance Dashboard: request-ID / pattern search, the
 * refresh-interval picker, the headline stat grid, the aggregate time chart
 * with its metric / breakdown / server selectors, the three category charts,
 * and the global — or, under a server filter, per-server — time breakdown.
 *
 * The component is presentational. Every value and every setter arrives from
 * `PerformanceDashboard`, which reads the per-slice view nodes and owns all
 * fetching; nothing here talks to the command graph. Rendering short-circuits
 * to null until the `overview:view` slice carries data.
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
	CHART_BREAKDOWN_OPTIONS,
} from '../constants';
import CategoryTimeChart from '../CategoryTimeChart';
import { ProfileWithCaption } from '../RequestProfile';
import BreakdownControls from './BreakdownControls';
import { AskButton } from './AskPanel';

/**
 * Overview Section component.
 *
 * @param {Object}                  props                        Component props.
 * @param {Object|null}             props.overview               Overview slice payload; null renders nothing.
 * @param {Object|null}             props.urlTotals              Headline numbers for the URL set the filters selected; null until the first reply.
 * @param {number}                  props.breakdownAvgMs         Average the Time Breakdown divides by — the selected server's, or the site's.
 * @param {string}                  props.serverFilter           Selected server name, or '' for all servers.
 * @param {(value: string) => void} props.setServerFilter        Server filter setter.
 * @param {string[]}                props.serverNames            Server names seen in the breakdown data.
 * @param {string}                  props.searchQuery            Search box value.
 * @param {(value: string) => void} props.setSearchQuery         Search box setter.
 * @param {boolean}                 props.searchLoading          True while a search is in flight.
 * @param {string|null}             props.searchError            Search error message, or null.
 * @param {(query: string) => void} props.onSearch               Search submit handler, given the raw query.
 * @param {Array|null}              props.searchResults          Pattern-search result rows, or null.
 * @param {boolean}                 props.searchResultsTruncated Whether the server capped the result set.
 * @param {(rid: string) => void}   props.onSelectResult         Row-click handler; deep-links by request id.
 * @param {string}                  props.refreshInterval        Poll interval in milliseconds, as a string.
 * @param {(value: string) => void} props.setRefreshInterval     Refresh interval setter.
 * @param {string}                  props.chartMetric            Aggregate chart metric, e.g. 'volume'.
 * @param {(value: string) => void} props.setChartMetric         Chart metric setter.
 * @param {string}                  props.chartBreakdown         Aggregate chart breakdown dimension.
 * @param {(value: string) => void} props.setChartBreakdown      Breakdown dimension setter.
 * @param {Object|null}             props.breakdownData          Time series for the selected breakdown dim.
 * @param {Object|null}             props.categoryData           Category time series, or null.
 * @param {Object}                  props.ask                    The `useAsk` state driving the Ask trigger.
 * @return {import('react').ReactElement|null} Rendered section, or null without overview data.
 */
export default function OverviewSection( {
	overview,
	urlTotals,
	breakdownAvgMs,
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
	ask,
} ) {
	// Show server dropdown when 2+ servers detected (hub mode).
	const isMultiServer = serverNames.length >= 2;

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

	// @longform Every headline number describes the URL set the filters
	// selected — the same set the table below lists — so they come from one
	// payload rather than from whichever namespace happened to hold each
	// figure. A number that has not arrived renders as absent; a plausible
	// zero beside a real total is exactly how the last defect hid.
	const headlineStats = [
		{
			key: 'urls',
			label: __( 'Unique URLs', 'newspack-event-logger-nodes' ),
			format: ( n ) => n.toLocaleString(),
		},
		{
			key: 'requests',
			label: __( 'Total Requests', 'newspack-event-logger-nodes' ),
			format: ( n ) => n.toLocaleString(),
		},
		{
			key: 'avg_ms',
			label: __( 'Avg Response', 'newspack-event-logger-nodes' ),
			format: ( n ) => `${ n.toFixed( 0 ) }ms`,
		},
		{
			key: 'requests_per_second',
			label: __( 'Req/s (last hour)', 'newspack-event-logger-nodes' ),
			format: ( n ) => n.toFixed( 2 ),
		},
		{
			key: 'avg_peak_mb',
			label: __( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
			format: ( n ) => `${ n.toFixed( 1 ) }MB`,
			// Absent on installs that do not sample peak memory.
			onlyWhenPositive: true,
		},
	]
		.filter(
			( { key, onlyWhenPositive } ) =>
				! onlyWhenPositive || urlTotals?.[ key ] > 0
		)
		.map( ( { key, label, format } ) => ( {
			key,
			label,
			value:
				'number' === typeof urlTotals?.[ key ]
					? format( urlTotals[ key ] )
					: '—',
		} ) );

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
						<AskButton ask={ ask } />
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
						</div>
						{ searchLoading && (
							<span
								className="newspack-nodes-status is-muted"
								style={ { fontSize: '12px' } }
							>
								{ __(
									'Searching…',
									'newspack-event-logger-nodes'
								) }
							</span>
						) }
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
						{ headlineStats.map( ( { key, label, value } ) => (
							<div className="newspack-nodes-stat" key={ key }>
								<span className="newspack-nodes-stat-value">
									{ value }
								</span>
								<span className="newspack-nodes-stat-label">
									{ label }
								</span>
							</div>
						) ) }
					</div>

					{ /* Unconditional: the Metric, Breakdown and Server
					     selectors are the only way out of a dimension with
					     nothing to draw, and the panel says which kind of
					     nothing it is. */ }
					<BreakdownControls
						breakdownData={ breakdownData }
						metric={ chartMetric }
						setMetric={ setChartMetric }
						breakdown={ chartBreakdown }
						setBreakdown={ setChartBreakdown }
						breakdownOptions={ breakdownOptions }
						serverOptions={ isMultiServer ? serverOptions : null }
						serverFilter={ serverFilter }
						setServerFilter={ setServerFilter }
					/>

					<CategoryTimeChart data={ categoryData } />

					{ overview.global_leaderboard?.categories && (
						<ProfileWithCaption
							profiles={ overview.global_leaderboard.categories }
							totalMs={ breakdownAvgMs }
							totalProfiledTime={
								overview.global_leaderboard.total_time
							}
							count={ overview.global_leaderboard.count || 0 }
							heading={
								serverFilter
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
									  )
							}
							serverName={ serverFilter }
						/>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
