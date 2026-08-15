/**
 * Request Profile Component
 *
 * Displays aggregated timing breakdown from request profiles data.
 */

import { useState, useMemo, Fragment } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	getStateColor,
	formatDuration,
} from '@newspack-nodes/shared/utils/formatUtils';

import './styles/request-profile.scss';

/**
 * Default number of categories to show before collapsing.
 */
const DEFAULT_VISIBLE_COUNT = 10;

/**
 * Test whether a profile category is a per-callback breakdown entry.
 * These contain ` \@N ` (e.g. `the_content \@10 do_blocks`). The at-signs are
 * backslash-escaped: unescaped, the JSDoc parser reads `\@10` as a malformed
 * tag and fails the file.
 *
 * @param {string} state Category name.
 * @return {boolean} True if callback breakdown.
 */
const isCallbackCategory = ( state ) => / @\d+$/.test( state );

/**
 * Request Profile Component.
 *
 * @param {Object}      props                     Component props.
 * @param {Object}      props.profiles            Profiles data from request.
 * @param {number}      props.totalMs             Total request duration in ms.
 * @param {number}      [props.totalProfiledTime] Pre-calculated total profiled time; derived from `profiles` when omitted.
 * @param {string|null} [props.title]             Custom title (null to hide heading); defaults to "Time Breakdown".
 * @return {import('react').ReactElement|null} Rendered component or null if no data.
 */
export default function RequestProfile( {
	profiles,
	totalMs,
	totalProfiledTime,
	title = __( 'Time Breakdown', 'newspack-event-logger-nodes' ),
} ) {
	const [ expandedState, setExpandedState ] = useState( null );
	const [ showAll, setShowAll ] = useState( false );

	// Process profiles into sorted array.
	const sortedProfiles = useMemo( () => {
		if ( ! profiles || typeof profiles !== 'object' ) {
			return [];
		}

		return Object.entries( profiles )
			.map( ( [ state, data ] ) => ( {
				state,
				count: data.count || 0,
				time: data.time || 0,
				entries: data.entries || {},
			} ) )
			.filter( ( p ) => p.time > 0 || p.count > 0 )
			.sort( ( a, b ) => b.time - a.time );
	}, [ profiles ] );

	// Use the pre-calculated total (aggregated) or sum it (single request).
	const profiledTime = useMemo( () => {
		if ( totalProfiledTime !== undefined ) {
			return totalProfiledTime;
		}
		// Single request: sum category times, skip " @N " breakdowns.
		return sortedProfiles
			.filter( ( p ) => ! isCallbackCategory( p.state ) )
			.reduce( ( sum, p ) => sum + p.time, 0 );
	}, [ sortedProfiles, totalProfiledTime ] );

	// Determine which profiles to display.
	const visibleProfiles = useMemo( () => {
		if ( showAll || sortedProfiles.length <= DEFAULT_VISIBLE_COUNT ) {
			return sortedProfiles;
		}
		return sortedProfiles.slice( 0, DEFAULT_VISIBLE_COUNT );
	}, [ sortedProfiles, showAll ] );

	// Check if there are hidden profiles.
	const hiddenCount = sortedProfiles.length - DEFAULT_VISIBLE_COUNT;
	const hasHiddenProfiles = hiddenCount > 0;

	if ( sortedProfiles.length === 0 ) {
		return null;
	}

	// Toggle expanded state.
	const toggleExpand = ( state ) => {
		setExpandedState( expandedState === state ? null : state );
	};

	return (
		<div className="event-logger-request-profile">
			{ title && <h3>{ title }</h3> }

			{ /* Summary bar */ }
			<div className="event-logger-profile-bar">
				{ sortedProfiles.map( ( { state, time } ) => {
					if ( isCallbackCategory( state ) ) {
						return null;
					}
					// Wall-clock denominator: unprofiled gap shows as empty bg.
					const pct = totalMs > 0 ? ( time / totalMs ) * 100 : 0;
					return (
						<div
							key={ state }
							role="button"
							tabIndex={ 0 }
							title={ `${ state }: ${ formatDuration(
								time
							) } (${ pct.toFixed( 1 ) }%)` }
							style={ {
								width: `${ pct }%`,
								background: getStateColor( state ),
								cursor: 'pointer',
							} }
							onClick={ () => toggleExpand( state ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									e.preventDefault();
									toggleExpand( state );
								}
							} }
						/>
					);
				} ) }
			</div>

			{ /* Profile table */ }
			<table className="newspack-nodes-table newspack-nodes-table--undivided">
				<thead>
					<tr>
						<th style={ { width: '40%' } }>
							{ __( 'Category', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '20%', textAlign: 'right' } }>
							{ __( 'Time', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '20%', textAlign: 'right' } }>
							{ __(
								'% of Total',
								'newspack-event-logger-nodes'
							) }
						</th>
						<th style={ { width: '20%', textAlign: 'right' } }>
							{ __( 'Count', 'newspack-event-logger-nodes' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ visibleProfiles.map(
						( { state, count, time, entries } ) => {
							// Wall clock so row % match the summary bars.
							const pct =
								totalMs > 0 ? ( time / totalMs ) * 100 : 0;
							const hasEntries =
								Object.keys( entries ).length > 0;
							const isExpanded = expandedState === state;

							return (
								<Fragment key={ state }>
									<tr
										data-ask={ `category:${ state }` }
										style={ {
											cursor: hasEntries
												? 'pointer'
												: 'default',
										} }
										onClick={ () =>
											hasEntries && toggleExpand( state )
										}
									>
										<td>
											<span
												style={ {
													display: 'inline-block',
													width: '12px',
													height: '12px',
													borderRadius: '2px',
													background:
														getStateColor( state ),
													marginRight: '8px',
													verticalAlign: 'middle',
												} }
											/>
											{ state }
											{ hasEntries && (
												<span
													className="newspack-nodes-status"
													style={ {
														marginLeft: '6px',
													} }
												>
													{ isExpanded ? '▼' : '▶' }
												</span>
											) }
										</td>
										<td
											className="newspack-nodes-table__terminal-data"
											style={ {
												textAlign: 'right',
											} }
										>
											{ formatDuration( time ) }
										</td>
										<td
											className="newspack-nodes-table__terminal-data"
											style={ {
												textAlign: 'right',
											} }
										>
											{ pct.toFixed( 1 ) }%
										</td>
										<td
											className="newspack-nodes-table__terminal-data"
											style={ {
												textAlign: 'right',
											} }
										>
											{ Math.round( count ) }
										</td>
									</tr>
									{ isExpanded && hasEntries && (
										<tr
											key={ `${ state }-entries` }
											className="newspack-nodes-table__details"
										>
											<td
												colSpan={ 4 }
												style={ {
													padding: '0 0 0 30px',
												} }
											>
												<table
													className="newspack-nodes-table newspack-nodes-table--undivided"
													style={ {
														width: '100%',
														fontSize: '12px',
													} }
												>
													<tbody>
														{ Object.entries(
															entries
														)
															.sort(
																( a, b ) =>
																	b[ 1 ][ 0 ] -
																	a[ 1 ][ 0 ]
															)
															.slice( 0, 10 )
															.map(
																( [
																	name,
																	[
																		entryTime,
																		entryCount,
																	],
																] ) => (
																	<tr
																		key={
																			name
																		}
																	>
																		<td
																			style={ {
																				maxWidth:
																					'400px',
																				overflow:
																					'hidden',
																				textOverflow:
																					'ellipsis',
																				whiteSpace:
																					'nowrap',
																				padding:
																					'4px 8px',
																			} }
																			title={
																				name
																			}
																		>
																			{ name ||
																				__(
																					'(anonymous)',
																					'newspack-event-logger-nodes'
																				) }
																		</td>
																		<td
																			className="newspack-nodes-table__terminal-data"
																			style={ {
																				textAlign:
																					'right',
																				padding:
																					'4px 8px',
																				width: '80px',
																			} }
																		>
																			{ formatDuration(
																				entryTime
																			) }
																		</td>
																		<td
																			className="newspack-nodes-table__terminal-data"
																			style={ {
																				textAlign:
																					'right',
																				padding:
																					'4px 8px',
																				width: '60px',
																			} }
																		>
																			×
																			{ Math.round(
																				entryCount
																			) }
																		</td>
																	</tr>
																)
															) }
														{ Object.keys( entries )
															.length > 10 && (
															<tr>
																<td
																	colSpan={
																		3
																	}
																	className="newspack-nodes-status"
																	style={ {
																		fontStyle:
																			'italic',
																		padding:
																			'4px 8px',
																	} }
																>
																	{ sprintf(
																		// translators: %d: number of additional entries not shown.
																		__(
																			'… and %d more',
																			'newspack-event-logger-nodes'
																		),
																		Object.keys(
																			entries
																		)
																			.length -
																			10
																	) }
																</td>
															</tr>
														) }
													</tbody>
												</table>
											</td>
										</tr>
									) }
								</Fragment>
							);
						}
					) }
					{ hasHiddenProfiles && (
						<tr>
							<td colSpan={ 4 } style={ { textAlign: 'center' } }>
								<button
									type="button"
									className="button-link event-logger-profile-expansion"
									onClick={ () => setShowAll( ! showAll ) }
								>
									{ showAll
										? __(
												'Show less',
												'newspack-event-logger-nodes'
										  )
										: sprintf(
												// translators: %d: number of additional categories that can be shown.
												_n(
													'Show %d more category',
													'Show %d more categories',
													hiddenCount,
													'newspack-event-logger-nodes'
												),
												hiddenCount
										  ) }
								</button>
							</td>
						</tr>
					) }
				</tbody>
				<tfoot>
					<tr className="newspack-nodes-table__summary">
						<td>
							{ __(
								'Total Profiled',
								'newspack-event-logger-nodes'
							) }
						</td>
						<td
							className="newspack-nodes-table__terminal-data"
							style={ {
								textAlign: 'right',
							} }
						>
							{ formatDuration( profiledTime ) }
						</td>
						<td
							className="newspack-nodes-table__terminal-data"
							style={ {
								textAlign: 'right',
							} }
						>
							{ totalMs > 0
								? ( ( profiledTime / totalMs ) * 100 ).toFixed(
										1
								  )
								: '0.0' }
							%
						</td>
						<td />
					</tr>
				</tfoot>
			</table>
		</div>
	);
}
