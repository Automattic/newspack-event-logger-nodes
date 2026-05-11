/**
 * Request Profile Component
 *
 * Displays aggregated timing breakdown from request profiles data.
 */

import { useState, useMemo, Fragment } from '@wordpress/element';

import { getStateColor, formatDuration } from '../shared/utils/formatUtils';

/**
 * Default number of categories to show before collapsing.
 */
const DEFAULT_VISIBLE_COUNT = 10;

/**
 * Test whether a profile category is a per-callback breakdown entry.
 * These contain " @N " (e.g. "the_content @10 do_blocks").
 *
 * @param {string} state Category name.
 * @return {boolean} True if callback breakdown.
 */
const isCallbackCategory = ( state ) => / @\d+$/.test( state );

/**
 * Request Profile Component.
 *
 * @param {Object}      props                   Component props.
 * @param {Object}      props.profiles          Profiles data from request.
 * @param {number}      props.totalMs           Total request duration in ms.
 * @param {number}      props.totalProfiledTime Pre-calculated total profiled time (optional).
 * @param {string|null} props.title             Custom title (null to hide heading).
 * @return {import('react').ReactElement|null} Rendered component or null if no data.
 */
export default function RequestProfile( {
	profiles,
	totalMs,
	totalProfiledTime,
	title = 'Time Breakdown',
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

	// Use pre-calculated total if provided (aggregated views), otherwise sum (single request view).
	const profiledTime = useMemo( () => {
		if ( totalProfiledTime !== undefined ) {
			return totalProfiledTime;
		}
		// Single request: sum category times, skipping per-callback profiling
		// entries (contain " @N ") which are breakdowns of their parent hook.
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
			<div
				className="event-logger-profile-bar"
				style={ {
					display: 'flex',
					height: '24px',
					borderRadius: '4px',
					overflow: 'hidden',
					marginBottom: '16px',
					background: '#ecf0f1',
				} }
			>
				{ sortedProfiles.map( ( { state, time } ) => {
					if ( isCallbackCategory( state ) ) {
						return null;
					}
					// Use wall clock as the denominator so the bar visually
					// shows the unprofiled gap as empty background. Chunks
					// sum to (profiledTime / totalMs) * 100% of bar width.
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
			<table className="widefat striped" style={ { fontSize: '13px' } }>
				<thead>
					<tr>
						<th style={ { width: '40%' } }>Category</th>
						<th style={ { width: '20%', textAlign: 'right' } }>
							Time
						</th>
						<th style={ { width: '20%', textAlign: 'right' } }>
							% of Total
						</th>
						<th style={ { width: '20%', textAlign: 'right' } }>
							Count
						</th>
					</tr>
				</thead>
				<tbody>
					{ visibleProfiles.map(
						( { state, count, time, entries } ) => {
							// Use wall clock so row percentages match the
							// summary bar widths and sum to ≤ Total Profiled.
							const pct =
								totalMs > 0 ? ( time / totalMs ) * 100 : 0;
							const hasEntries =
								Object.keys( entries ).length > 0;
							const isExpanded = expandedState === state;

							return (
								<Fragment key={ state }>
									<tr
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
													style={ {
														marginLeft: '6px',
														color: '#999',
													} }
												>
													{ isExpanded ? '▼' : '▶' }
												</span>
											) }
										</td>
										<td
											style={ {
												textAlign: 'right',
												fontFamily: 'monospace',
											} }
										>
											{ formatDuration( time ) }
										</td>
										<td
											style={ {
												textAlign: 'right',
												fontFamily: 'monospace',
											} }
										>
											{ pct.toFixed( 1 ) }%
										</td>
										<td
											style={ {
												textAlign: 'right',
												fontFamily: 'monospace',
											} }
										>
											{ Math.round( count ) }
										</td>
									</tr>
									{ isExpanded && hasEntries && (
										<tr key={ `${ state }-entries` }>
											<td
												colSpan={ 4 }
												style={ {
													padding: '0 0 0 30px',
													background: '#f9f9f9',
												} }
											>
												<table
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
																				'(anonymous)' }
																		</td>
																		<td
																			style={ {
																				textAlign:
																					'right',
																				fontFamily:
																					'monospace',
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
																			style={ {
																				textAlign:
																					'right',
																				fontFamily:
																					'monospace',
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
																	style={ {
																		color: '#999',
																		fontStyle:
																			'italic',
																		padding:
																			'4px 8px',
																	} }
																>
																	... and{ ' ' }
																	{ Object.keys(
																		entries
																	).length -
																		10 }{ ' ' }
																	more
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
									className="button-link"
									onClick={ () => setShowAll( ! showAll ) }
									style={ {
										color: '#0073aa',
										cursor: 'pointer',
										padding: '4px 8px',
									} }
								>
									{ showAll
										? 'Show less'
										: `Show ${ hiddenCount } more categories` }
								</button>
							</td>
						</tr>
					) }
					<tr style={ { fontWeight: 'bold', background: '#f5f5f5' } }>
						<td>Total Profiled</td>
						<td
							style={ {
								textAlign: 'right',
								fontFamily: 'monospace',
							} }
						>
							{ formatDuration( profiledTime ) }
						</td>
						<td
							style={ {
								textAlign: 'right',
								fontFamily: 'monospace',
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
				</tbody>
			</table>
		</div>
	);
}
