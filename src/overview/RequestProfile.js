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

import { useState, useMemo, useId, Fragment } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	formatDuration,
	phosphorColor,
} from '@newspack-nodes/shared/utils/formatUtils';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';

import SortHeaderButton, { useColumnSort } from './components/SortHeaderButton';
import './styles/request-profile.scss';

/**
 * Rows a ranked table draws before it truncates; the rest wait behind the
 * Show-more button. The summary bar above is never truncated, so a row held
 * back from the table still has a segment.
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
 * @return {boolean} True when the name ends in the ` @N` priority suffix, N signed.
 */
const isCallbackCategory = ( state ) => / @-?\d+$/.test( state );

/**
 * Test whether a category OVERLAYS time the others already count, as
 * `Flame_Tree::is_overlay_span()` reads it: a wrapped listener, whose time is
 * its hook's, or a Query Monitor timer (`qm`), laid over whatever it timed.
 * Neither joins Total Profiled nor takes a segment of the bar, or the bar's
 * segments would no longer tile the time measured.
 *
 * @param {string} state Category name.
 * @return {boolean} True for a listener or a Query Monitor timer.
 */
const isOverlayCategory = ( state ) =>
	isCallbackCategory( state ) || 'qm' === state.split( ': ' )[ 0 ];

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
 * @param {Object}                  props          Component props.
 * @param {Object<string,number[]>} props.entries  Per-origin `[ time, count ]`, keyed by origin; the averaged shape appends a sample count this table ignores.
 * @param {string}                  props.category The category it breaks down, which names the table.
 * @return {import('react').ReactElement} The nested breakdown table.
 */
function ProfileEntries( { entries, category } ) {
	const hiddenCount = Object.keys( entries ).length - VISIBLE_ENTRY_COUNT;

	return (
		<table
			className="newspack-nodes-table newspack-nodes-table--undivided event-logger-profile-entries"
			aria-label={ sprintf(
				// translators: %s: the category the origins break down.
				__( '%s by origin', 'newspack-event-logger-nodes' ),
				category
			) }
		>
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
 * A profile's categories as its table lists them: longest first, leaving out
 * one that took no time and made no call.
 *
 * @param {?Object} profiles Category name to `{ time, count, entries }`.
 * @return {Array<{state: string, count: number, time: number, entries: Object}>} The rows.
 */
const sortProfiles = ( profiles ) => {
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
};

/**
 * The order the Time Breakdown hands out its colours in: the bar's segments
 * longest first, then the rows with no segment, longest first. Neighbouring
 * segments take neighbouring chart slots, which the palette keeps apart, and
 * a flame graph beside the breakdown gives each category the same CRT green.
 *
 * @param {?Object} profiles Category name to `{ time, count, entries }`.
 * @return {Array<string>} Category names.
 */
export const profileOrder = ( profiles ) => {
	const states = sortProfiles( profiles ).map( ( p ) => p.state );
	return [
		...states.filter( ( state ) => ! isOverlayCategory( state ) ),
		...states.filter( isOverlayCategory ),
	];
};

/**
 * A row of segments, each as wide as its share, coloured by its caller.
 *
 * A segment that takes `onSelect` is a button, reached by click, Enter or
 * Space. A bar with no such segment only pictures numbers its table also
 * carries, so it is hidden from a screen reader.
 *
 * @param {Object}                                                                                  props          Component props.
 * @param {Array<{key: string, share: number, title: string, fill: string, onSelect?: () => void}>} props.segments Segments in drawing order; `share` is a percentage.
 * @return {import('react').ReactElement} The bar.
 */
export function ShareBar( { segments } ) {
	const decorative = segments.every( ( { onSelect } ) => ! onSelect );
	return (
		<div
			className="event-logger-profile-bar"
			aria-hidden={ decorative ? 'true' : undefined }
		>
			{ segments.map( ( { key, share, title, fill, onSelect } ) =>
				onSelect ? (
					<div
						key={ key }
						role="button"
						tabIndex={ 0 }
						title={ title }
						style={ {
							width: `${ share }%`,
							background: fill,
							cursor: 'pointer',
						} }
						onClick={ onSelect }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								onSelect();
							}
						} }
					/>
				) : (
					<div
						key={ key }
						title={ title }
						style={ { width: `${ share }%`, background: fill } }
					/>
				)
			) }
		</div>
	);
}

/**
 * A ranked table's first `DEFAULT_VISIBLE_COUNT` rows, and a toggle to the
 * rest; `ShowMoreRow` draws the toggle.
 *
 * @template T
 * @param {Array<T>} rows The table's rows, in drawing order.
 * @return {{visible: Array<T>, hidden: number, showAll: boolean, toggle: () => void}} The rows to draw, how many wait behind the toggle, and its state.
 */
export function useShowMore( rows ) {
	const [ showAll, setShowAll ] = useState( false );
	const hidden = Math.max( 0, rows.length - DEFAULT_VISIBLE_COUNT );
	return {
		visible: showAll ? rows : rows.slice( 0, DEFAULT_VISIBLE_COUNT ),
		hidden,
		showAll,
		toggle: () => setShowAll( ! showAll ),
	};
}

/**
 * The table row that toggles a `useShowMore()` table; nothing when no row
 * is held back.
 *
 * @param {Object}                         props         Component props.
 * @param {ReturnType<typeof useShowMore>} props.more    The table's `useShowMore()`.
 * @param {string}                         props.label   Already-translated "Show N more" text, read while rows are held back.
 * @param {number}                         props.colSpan The table's column count.
 * @return {import('react').ReactElement|null} The row.
 */
export function ShowMoreRow( { more, label, colSpan } ) {
	if ( 0 === more.hidden ) {
		return null;
	}
	return (
		<tr>
			<td colSpan={ colSpan } style={ { textAlign: 'center' } }>
				<button
					type="button"
					className="button-link event-logger-profile-expansion"
					onClick={ more.toggle }
				>
					{ more.showAll
						? __( 'Show less', 'newspack-event-logger-nodes' )
						: label }
				</button>
			</td>
		</tr>
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
 * tile rather than nest and Total Profiled is a plain sum. The overlays —
 * wrapped listeners and Query Monitor timers — are what that sum and that
 * bar leave out, their time being already inside the spans they ran in. They
 * keep their table rows, which is where a hook's slowest callback is found,
 * so the percentage column does not add up
 * to the footer's. With no wall clock measured every share reads `—`.
 *
 * @param {Object}                props                     Component props.
 * @param {Object<string,Object>} props.profiles            Per-category `{ count, time, entries }`, keyed by category name.
 * @param {?number}               props.totalMs             Wall-clock duration in ms — the denominator for every percentage; null or 0 where unmeasured.
 * @param {?number}               [props.totalProfiledTime] Profiled total the averaged views compute server-side, null where unmeasured; summed from the non-listener categories when omitted.
 * @param {string|null}           [props.title]             Heading text, or null for no heading; defaults to "Time Breakdown".
 * @param {string}                [props.labelledBy]        Id of the heading that names the table when `title` is null and a wrapper carries it.
 * @param {boolean}               [props.totaled]           False to leave the Total Profiled row to a table below that closes for both.
 * @return {import('react').ReactElement|null} The panel, or null when every category carries zero time and zero count.
 */
export default function RequestProfile( {
	profiles,
	totalMs,
	totalProfiledTime,
	title = __( 'Time Breakdown', 'newspack-event-logger-nodes' ),
	labelledBy,
	totaled = true,
} ) {
	const headingId = useId();
	const [ expandedState, setExpandedState ] = useState( null );

	const sortedProfiles = useMemo(
		() => sortProfiles( profiles ),
		[ profiles ]
	);

	// Use the pre-calculated total (aggregated) or sum it (single request).
	const profiledTime = useMemo( () => {
		if ( totalProfiledTime !== undefined ) {
			return totalProfiledTime;
		}
		// Single request: sum category times, skip the overlays.
		return sortedProfiles
			.filter( ( p ) => ! isOverlayCategory( p.state ) )
			.reduce( ( sum, p ) => sum + p.time, 0 );
	}, [ sortedProfiles, totalProfiledTime ] );

	const { sorted, dirOf, onSort } = useColumnSort( 'time' );

	// Colours follow the bar, so a sorted row keeps its segment's.
	const colourOf = useMemo(
		() =>
			new Map(
				profileOrder( profiles ).map( ( state, index ) => [
					state,
					phosphorColor( index, chartColor( index ) ),
				] )
			),
		[ profiles ]
	);

	const tableRows = sorted( sortedProfiles, ( row, field ) =>
		'category' === field
			? row.state
			: row[ 'count' === field ? 'count' : 'time' ]
	);
	const more = useShowMore( tableRows );

	if ( sortedProfiles.length === 0 ) {
		return null;
	}

	// A share of the wall clock, or null where none was measured.
	const shareOf = ( time ) =>
		totalMs > 0 && null !== time ? ( time / totalMs ) * 100 : null;
	const shareText = ( share ) =>
		null === share ? '—' : `${ share.toFixed( 1 ) }%`;

	const toggleExpand = ( state ) => {
		setExpandedState( expandedState === state ? null : state );
	};

	return (
		<div className="event-logger-request-profile">
			{ title && <h3 id={ headingId }>{ title }</h3> }

			<ShareBar
				segments={ sortedProfiles.flatMap( ( { state, time } ) => {
					if ( isOverlayCategory( state ) ) {
						return [];
					}
					const share = shareOf( time );
					return [
						{
							key: state,
							share: share ?? 0,
							title: `${ state }: ${ formatDuration(
								time
							) } (${ shareText( share ) })`,
							fill: colourOf.get( state ),
							onSelect: () => toggleExpand( state ),
						},
					];
				} ) }
			/>

			<table
				className="newspack-nodes-table newspack-nodes-table--undivided"
				aria-labelledby={ title ? headingId : labelledBy }
			>
				<thead>
					<tr className="newspack-nodes-table__header">
						{ [
							[
								'category',
								__( 'Category', 'newspack-event-logger-nodes' ),
							],
							[
								'time',
								__( 'Time', 'newspack-event-logger-nodes' ),
							],
							[
								'share',
								__(
									'% of Total',
									'newspack-event-logger-nodes'
								),
							],
							[
								'count',
								__( 'Count', 'newspack-event-logger-nodes' ),
							],
						].map( ( [ field, label ] ) => (
							<th scope="col" key={ field }>
								<SortHeaderButton
									field={ field }
									label={ label }
									dir={ dirOf( field ) }
									variant={
										'category' === field ? '' : 'numeric'
									}
									onSort={ onSort }
								/>
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ more.visible.map( ( { state, count, time, entries } ) => {
						const share = shareOf( time );
						const hasEntries = Object.keys( entries ).length > 0;
						const isExpanded = expandedState === state;

						return (
							<Fragment key={ state }>
								<tr
									data-ask={
										isCallbackCategory( state )
											? undefined
											: `category:${ state }`
									}
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
													colourOf.get( state ),
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
										{ shareText( share ) }
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
												category={ state }
											/>
										</td>
									</tr>
								) }
							</Fragment>
						);
					} ) }
					<ShowMoreRow
						more={ more }
						colSpan={ 4 }
						label={ sprintf(
							// translators: %d: number of additional categories that can be shown.
							_n(
								'Show %d more category',
								'Show %d more categories',
								more.hidden,
								'newspack-event-logger-nodes'
							),
							more.hidden
						) }
					/>
				</tbody>
				{ totaled && (
					<tfoot>
						<tr className="newspack-nodes-table__summary">
							<td>
								{ __(
									'Total Profiled',
									'newspack-event-logger-nodes'
								) }
							</td>
							<td className="newspack-nodes-table__terminal-data">
								{ null === profiledTime
									? '—'
									: formatDuration( profiledTime ) }
							</td>
							<td className="newspack-nodes-table__terminal-data">
								{ shareText( shareOf( profiledTime ) ) }
							</td>
							<td />
						</tr>
					</tfoot>
				) }
			</table>
		</div>
	);
}

/**
 * Averaged profile breakdown over the caption naming how many requests it
 * averages — and, under a server filter, which server they ran on.
 *
 * The caption is the panel's own wording, so both plural forms live here as
 * literal `_n()` calls the `serverName` prop selects between. The caller
 * supplies the heading, because only the caller knows what its scope is called; giving
 * one replaces the profile's built-in title rather than stacking on it.
 *
 * @param {Object}                props                     Component props.
 * @param {Object<string,Object>} props.profiles            Per-category `{ count, time, entries }`, already averaged over `count` requests.
 * @param {?number}               props.totalMs             Average wall-clock duration in ms — the denominator for every percentage; null or 0 where unmeasured.
 * @param {?number}               [props.totalProfiledTime] Profiled total computed server-side, null where unmeasured; summed from `profiles` when omitted.
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
	const headingId = useId();
	return (
		<div className="event-logger-request-profile">
			{ heading && <h3 id={ headingId }>{ heading }</h3> }
			<RequestProfile
				profiles={ profiles }
				totalMs={ totalMs }
				totalProfiledTime={ totalProfiledTime }
				title={ heading ? null : undefined }
				labelledBy={ heading ? headingId : undefined }
			/>
			<p
				className="newspack-nodes-status"
				style={ { fontSize: '12px', marginTop: '8px' } }
			>
				{ averageCaption( count, serverName ) }
			</p>
		</div>
	);
}

/**
 * How many requests an averaged breakdown covers, and on which server.
 *
 * @param {number} count      Requests the average covers.
 * @param {string} serverName Server it is scoped to; '' captions it site-wide.
 * @return {string} The caption.
 */
export function averageCaption( count, serverName ) {
	return serverName
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
		  );
}
