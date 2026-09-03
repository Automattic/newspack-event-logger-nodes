/**
 * The time-breakdown panel: a request's `profiles{}` drawn as a proportional
 * summary bar over a table of categories ranked by time, either of which
 * opens a category to the origins that raised it.
 *
 * One component serves two shapes. A single request supplies its own record,
 * and `RequestDetailView` and `CurrentRequestTab` mount `RequestProfile`
 * directly. The two averaged views — the Overview card and the URL modal — go
 * through `ProfileWithCaption`, which captions the same panel with how many
 * requests the average covers.
 */

import { useState, useMemo, Fragment } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	getStateColor,
	formatDuration,
} from '@newspack-nodes/shared/utils/formatUtils';

import './styles/request-profile.scss';

/**
 * Categories the table draws before it truncates; the rest wait behind the
 * Show-more button. The summary bar above is never truncated, so a category
 * held back from the table still has a segment.
 */
const DEFAULT_VISIBLE_COUNT = 10;

/**
 * Slowest origins drawn inside an expanded category; the rest are counted.
 */
const VISIBLE_ENTRY_COUNT = 10;

/**
 * Test whether a profile category names one wrapped listener rather than a
 * span of its own. `App\Core::wrap_callbacks()` mints those as
 * `<callable> @N` for priority N, and a listener's time is already inside the
 * hook that dispatched it, so counting one into Total Profiled or giving it a
 * segment of the summary bar bills the same work twice.
 *
 * @param {string} state Category name.
 * @return {boolean} True when the name ends in the ` @N` priority suffix.
 */
const isCallbackCategory = ( state ) => / @\d+$/.test( state );

/**
 * The breakdown of one expanded category by the code that raised it: the
 * slowest origins by time, then a count of what was left out.
 *
 * An origin is the `Class::method` frame `App\Core` records as a span's `l`
 * label, so a hook fired from four places splits into four rows rather than
 * one total. An empty origin renders as "(anonymous)".
 *
 * Counts are rounded because the averaged views divide their sums by the
 * request count before sending them, which lands most origins on a fraction.
 *
 * @param {Object}                  props         Component props.
 * @param {Object<string,number[]>} props.entries Per-origin `[ time, count ]`, keyed by origin; the averaged shape appends a sample count this table ignores.
 * @return {import('react').ReactElement} The nested breakdown table.
 */
function ProfileEntries( { entries } ) {
	const hiddenCount = Object.keys( entries ).length - VISIBLE_ENTRY_COUNT;

	return (
		<table className="newspack-nodes-table newspack-nodes-table--undivided event-logger-profile-entries">
			<tbody>
				{ Object.entries( entries )
					.sort( ( a, b ) => b[ 1 ][ 0 ] - a[ 1 ][ 0 ] )
					.slice( 0, VISIBLE_ENTRY_COUNT )
					.map( ( [ name, [ time, count ] ] ) => (
						<tr key={ name }>
							<td
								className="event-logger-profile-entries__name"
								title={ name }
							>
								{ name ||
									__(
										'(anonymous)',
										'newspack-event-logger-nodes'
									) }
							</td>
							<td className="newspack-nodes-table__terminal-data event-logger-profile-entries__time">
								{ formatDuration( time ) }
							</td>
							<td className="newspack-nodes-table__terminal-data event-logger-profile-entries__count">
								×{ Math.round( count ) }
							</td>
						</tr>
					) ) }
				{ hiddenCount > 0 && (
					<tr>
						<td
							colSpan={ 3 }
							className="newspack-nodes-status event-logger-profile-entries__more"
						>
							{ sprintf(
								// translators: %d: number of additional entries not shown.
								__(
									'… and %d more',
									'newspack-event-logger-nodes'
								),
								hiddenCount
							) }
						</td>
					</tr>
				) }
			</tbody>
		</table>
	);
}

/**
 * The time breakdown for one request, or for an average of many.
 *
 * Every percentage divides by `totalMs`, the wall clock, rather than by the
 * profiled total. The bar's unfilled remainder is therefore the time nothing
 * instrumented accounts for, and the footer reads as how much of the request
 * the logger saw.
 *
 * A category's time is exclusive of its children, `Request_Builder_Node`
 * having subtracted each closing span from its ancestors, so the segments
 * tile rather than nest and Total Profiled is a plain sum. Wrapped listeners
 * are what that sum and that bar leave out, their time being already inside
 * the hook that dispatched them. They keep their table rows, which is where a
 * hook's slowest callback is found, so the percentage column does not add up
 * to the footer's.
 *
 * @param {Object}                props                     Component props.
 * @param {Object<string,Object>} props.profiles            Per-category `{ count, time, entries }`, keyed by category name.
 * @param {number}                props.totalMs             Wall-clock duration in ms — the denominator for every percentage.
 * @param {number}                [props.totalProfiledTime] Profiled total the averaged views compute server-side; summed from the non-listener categories when omitted.
 * @param {string|null}           [props.title]             Heading text, or null for no heading; defaults to "Time Breakdown".
 * @return {import('react').ReactElement|null} The panel, or null when every category carries zero time and zero count.
 */
export default function RequestProfile( {
	profiles,
	totalMs,
	totalProfiledTime,
	title = __( 'Time Breakdown', 'newspack-event-logger-nodes' ),
} ) {
	const [ expandedState, setExpandedState ] = useState( null );
	const [ showAll, setShowAll ] = useState( false );

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
		// Single request: sum category times, skip " @N" listeners.
		return sortedProfiles
			.filter( ( p ) => ! isCallbackCategory( p.state ) )
			.reduce( ( sum, p ) => sum + p.time, 0 );
	}, [ sortedProfiles, totalProfiledTime ] );

	const visibleProfiles = useMemo( () => {
		if ( showAll || sortedProfiles.length <= DEFAULT_VISIBLE_COUNT ) {
			return sortedProfiles;
		}
		return sortedProfiles.slice( 0, DEFAULT_VISIBLE_COUNT );
	}, [ sortedProfiles, showAll ] );

	const hiddenCount = sortedProfiles.length - DEFAULT_VISIBLE_COUNT;
	const hasHiddenProfiles = hiddenCount > 0;

	if ( sortedProfiles.length === 0 ) {
		return null;
	}

	const toggleExpand = ( state ) => {
		setExpandedState( expandedState === state ? null : state );
	};

	return (
		<div className="event-logger-request-profile">
			{ title && <h3>{ title }</h3> }

			<div className="event-logger-profile-bar">
				{ sortedProfiles.map( ( { state, time } ) => {
					if ( isCallbackCategory( state ) ) {
						return null;
					}
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

			<table className="newspack-nodes-table newspack-nodes-table--undivided">
				<thead>
					<tr>
						<th>
							{ __( 'Category', 'newspack-event-logger-nodes' ) }
						</th>
						<th>{ __( 'Time', 'newspack-event-logger-nodes' ) }</th>
						<th>
							{ __(
								'% of Total',
								'newspack-event-logger-nodes'
							) }
						</th>
						<th>
							{ __( 'Count', 'newspack-event-logger-nodes' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ visibleProfiles.map(
						( { state, count, time, entries } ) => {
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
												className="event-logger-profile-swatch"
												style={ {
													background:
														getStateColor( state ),
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
										<td className="newspack-nodes-table__terminal-data">
											{ formatDuration( time ) }
										</td>
										<td className="newspack-nodes-table__terminal-data">
											{ pct.toFixed( 1 ) }%
										</td>
										<td className="newspack-nodes-table__terminal-data">
											{ Math.round( count ) }
										</td>
									</tr>
									{ isExpanded && hasEntries && (
										<tr className="newspack-nodes-table__details">
											<td colSpan={ 4 }>
												<ProfileEntries
													entries={ entries }
												/>
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
						<td className="newspack-nodes-table__terminal-data">
							{ formatDuration( profiledTime ) }
						</td>
						<td className="newspack-nodes-table__terminal-data">
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

/**
 * Averaged profile breakdown over the caption naming how many requests it
 * averages — and, under a server filter, which server they ran on.
 *
 * The caption is the panel's own wording, so both plural forms live here as
 * literal `_n()` calls the `serverName` prop selects between. The heading is
 * the caller's, because only the caller knows what its scope is called; giving
 * one replaces the profile's built-in title rather than stacking on it.
 *
 * @param {Object}                props                     Component props.
 * @param {Object<string,Object>} props.profiles            Per-category `{ count, time, entries }`, already averaged over `count` requests.
 * @param {number}                props.totalMs             Average wall-clock duration in ms — the denominator for every percentage.
 * @param {number}                [props.totalProfiledTime] Profiled total computed server-side; summed from `profiles` when omitted.
 * @param {number}                [props.count]             Requests the average covers.
 * @param {string|null}           [props.heading]           Already-translated heading; omitted leaves the profile's own title.
 * @param {string}                [props.serverName]        Server the breakdown is scoped to; '' captions it site-wide.
 * @return {import('react').ReactElement} Rendered panel.
 */
export function ProfileWithCaption( {
	profiles,
	totalMs,
	totalProfiledTime,
	count = 0,
	heading = null,
	serverName = '',
} ) {
	return (
		<div
			className="event-logger-request-profile"
			style={ { marginTop: '20px' } }
		>
			{ heading && <h3>{ heading }</h3> }
			<RequestProfile
				profiles={ profiles }
				totalMs={ totalMs }
				totalProfiledTime={ totalProfiledTime }
				title={ heading ? null : undefined }
			/>
			<p
				className="newspack-nodes-status"
				style={ { fontSize: '12px', marginTop: '8px' } }
			>
				{ serverName
					? sprintf(
							// translators: 1: number of requests, 2: the server name.
							_n(
								'Average breakdown across %1$d request on %2$s',
								'Average breakdown across %1$d requests on %2$s',
								count,
								'newspack-event-logger-nodes'
							),
							count,
							serverName
					  )
					: sprintf(
							// translators: %d: number of requests.
							_n(
								'Average breakdown across %d request',
								'Average breakdown across %d requests',
								count,
								'newspack-event-logger-nodes'
							),
							count
					  ) }
			</p>
		</div>
	);
}
