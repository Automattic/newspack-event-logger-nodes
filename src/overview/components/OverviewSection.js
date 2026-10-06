/**
 * Overview Section Component
 *
 * The top card of the Performance Dashboard: the Ask trigger, request-ID /
 * pattern search, the refresh-interval picker, the headline stat grid, the
 * aggregate time chart with its metric / breakdown / server selectors, the
 * three category charts, and the global — or, under a server filter,
 * per-server — time breakdown.
 *
 * The component is presentational. Every value and every setter arrives from
 * `PerformanceDashboard`, which reads the per-slice view nodes and owns all
 * fetching; nothing here talks to the command graph. Rendering short-circuits
 * to null until the `overview:view` slice carries data.
 */

import { useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	CardBody,
	SearchControl,
	SelectControl,
} from '@wordpress/components';

import {
	DASHBOARD_REFRESH_OPTIONS,
	CHART_BREAKDOWN_OPTIONS,
	SCOPED_BREAKDOWN_OPTIONS,
} from '../constants';
import CategoryTimeChart from '../CategoryTimeChart';
import { ProfileWithCaption } from '../RequestProfile';
import BreakdownControls from './BreakdownControls';
import { AskButton } from './AskPanel';
import { HeaderSlot } from '@newspack-nodes/shared/components/HeaderSlot';
import UnparseableLinesNotice from '@newspack-nodes/shared/components/UnparseableLinesNotice';
import HeadlineStats from './HeadlineStats';

/**
 * Overview Section component.
 *
 * @param {Object}                                              props                        Component props.
 * @param {Object|null}                                         props.overview               Overview slice payload; it supplies `global_leaderboard` and gates the card, so null renders nothing.
 * @param {Object|null}                                         props.urlTotals              Headline numbers for the URL set the filters selected; null until the first reply.
 * @param {boolean}                                             props.urlsProvisional        Whether `urlTotals` is short of an hour or record the writer has yet to fold or rank, or of an index read that went unanswered.
 * @param {?number}                                             props.breakdownAvgMs         Average the Time Breakdown divides by — the selected server's, or the site's; null where none was timed.
 * @param {string}                                              props.serverFilter           Selected server name, or '' for all servers; it also captions the Time Breakdown.
 * @param {(value: string) => void}                             props.setServerFilter        Server filter setter.
 * @param {string[]|null}                                       props.serverNames            Server names seen in the breakdown data; null until the first reply lands. Fewer than two withholds the Server select, which would offer no choice, unless a filter is applied: the select then lists it, so it can be cleared.
 * @param {string}                                              props.searchQuery            Search box value.
 * @param {(value: string) => void}                             props.setSearchQuery         Search box setter.
 * @param {boolean}                                             props.searchLoading          True while a search is in flight.
 * @param {string|null}                                         props.searchError            Already-translated search error, or null.
 * @param {(query: string) => void}                             props.onSearch               Search submit handler, given the raw query.
 * @param {Array|null}                                          props.searchResults          Pattern-search rows — `{ rid, method, url, match_count }` — or null before a search.
 * @param {boolean}                                             props.searchResultsTruncated Whether the server capped the result set.
 * @param {number|undefined}                                    props.searchUnparseableLines Lines the last pattern search skipped as unparseable; undefined before one answers.
 * @param {(rid: string) => void}                               props.onSelectResult         Row-click handler; deep-links by request id.
 * @param {string}                                              props.refreshInterval        Poll interval in milliseconds, as a string.
 * @param {(value: string) => void}                             props.setRefreshInterval     Refresh interval setter.
 * @param {string}                                              props.chartMetric            Aggregate chart metric: 'volume', 'avg', 'cumulative' or 'memory'.
 * @param {(value: string) => void}                             props.setChartMetric         Chart metric setter.
 * @param {string}                                              props.chartBreakdown         Aggregate chart breakdown dimension, already resolved by the caller against `canBreakDownByServer`.
 * @param {(value: string) => void}                             props.setChartBreakdown      Breakdown dimension setter.
 * @param {boolean}                                             props.canBreakDownByServer   Whether the page can chart the server axis: no server filter, and the server list either unknown or holding two or more names. A filter would split one server against itself, and withholding the axis before the first reply lands would strand the `server` default on `status` for the session.
 * @param {Object}                                              props.breakdownRead          The `breakdownState()` read of the selected dimension's reply, `{ state, series }`.
 * @param {Object|null}                                         props.categoryData           Category time series, or null.
 * @param {(key: string, click: {additive: boolean}) => void}   props.onSlotClick            Edits the URL table's selection with the bucket key a click on either chart lands on, and `{ additive }`.
 * @param {(keys: string[], drag: {additive: boolean}) => void} props.onSlotRange            Edits the URL table's selection with the bucket keys a drag on either chart spans, and `{ additive }`.
 * @param {string[]}                                            props.selectedBuckets        The URL table's selected bucket keys, which both charts shade.
 * @param {Object}                                              props.ask                    The `useAsk` state driving the Ask trigger.
 * @param {?Element}                                            [props.headerControlsSlot]   Shell header slot to portal the Ask, search and refresh controls into; null while it is pending, undefined renders them inline.
 * @return {import('react').ReactElement|null} Rendered section, or null without overview data.
 */
export default function OverviewSection( {
	overview,
	urlTotals,
	urlsProvisional,
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
	searchUnparseableLines,
	onSelectResult,
	refreshInterval,
	setRefreshInterval,
	chartMetric,
	setChartMetric,
	chartBreakdown,
	setChartBreakdown,
	canBreakDownByServer,
	breakdownRead,
	categoryData,
	onSlotClick,
	onSlotRange,
	selectedBuckets,
	ask,
	headerControlsSlot,
} ) {
	// One server offers no choice, but an applied filter needs a way out.
	const serverOptions = useMemo( () => {
		const names = serverNames ?? [];
		if ( names.length < 2 && '' === serverFilter ) {
			return null;
		}
		const offered =
			'' === serverFilter || names.includes( serverFilter )
				? names
				: [ ...names, serverFilter ];
		return [
			{
				label: __( 'All Servers', 'newspack-event-logger-nodes' ),
				value: '',
			},
			...offered.map( ( name ) => ( { label: name, value: name } ) ),
		];
	}, [ serverNames, serverFilter ] );

	// Dropping 'server' cannot strand the selection: the caller resolved it.
	const breakdownOptions = canBreakDownByServer
		? CHART_BREAKDOWN_OPTIONS
		: SCOPED_BREAKDOWN_OPTIONS;

	if ( ! overview ) {
		return null;
	}

	return (
		<div className="event-logger-performance-overview">
			<Card>
				<HeaderSlot slot={ headerControlsSlot }>
					<div
						style={ {
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
							<SearchControl
								__nextHasNoMarginBottom
								label={ __(
									'Request ID or /url pattern',
									'newspack-event-logger-nodes'
								) }
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
								style={ { width: '250px' } }
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
				</HeaderSlot>
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
				<UnparseableLinesNotice count={ searchUnparseableLines } />
				<CardBody>
					<HeadlineStats
						totals={ urlTotals }
						provisional={ urlsProvisional }
					/>

					{ /* Unconditional: the Metric, Breakdown and Server
					     selectors are the only way out of a dimension with
					     nothing to draw, and the panel says which kind of
					     nothing it is. */ }
					<BreakdownControls
						breakdownRead={ breakdownRead }
						slots={ overview.slots ?? null }
						metric={ chartMetric }
						setMetric={ setChartMetric }
						breakdown={ chartBreakdown }
						setBreakdown={ setChartBreakdown }
						breakdownOptions={ breakdownOptions }
						serverOptions={ serverOptions }
						serverFilter={ serverFilter }
						setServerFilter={ setServerFilter }
						onSlotClick={ onSlotClick }
						onSlotRange={ onSlotRange }
						selectedBuckets={ selectedBuckets }
					/>

					<CategoryTimeChart
						data={ categoryData }
						slots={ overview.slots ?? null }
						onSlotClick={ onSlotClick }
						onSlotRange={ onSlotRange }
						selectedBuckets={ selectedBuckets }
					/>

					{ overview.global_leaderboard?.count > 0 && (
						<ProfileWithCaption
							profiles={ overview.global_leaderboard.categories }
							totalMs={ breakdownAvgMs }
							totalProfiledTime={
								overview.global_leaderboard.total_time
							}
							count={ overview.global_leaderboard.count }
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
