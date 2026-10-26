/**
 * The "Time By Owner" table: self time attributed to the code that ran it,
 * one row per owner, ranked by time. A single request supplies its own rows;
 * the URL modal and the Overview supply averages, whose time and calls
 * columns read per request.
 */

import { useId, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatDuration } from '@newspack-nodes/shared/utils/formatUtils';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';

import RequestProfile, {
	averageCaption,
	ProfileWithCaption,
	profiledTotal,
	ShareBar,
	ShowMoreRow,
	useShowMore,
} from './RequestProfile';
import SortHeaderButton, { useColumnSort } from './components/SortHeaderButton';

/** The owner the server names for time no code claims. */
const UNOWNED = '(unowned)';

/**
 * @param {Object}                                                                                                                   props              Component props.
 * @param {?Array<{owner: string, ms?: number, ms_per_request?: number, share: number, calls?: number, calls_per_request?: number}>} props.owners       Rows, longest first; `share` is a fraction.
 * @param {boolean}                                                                                                                  [props.perRequest] True when rows carry `ms_per_request` and `calls_per_request` in place of `ms` and `calls`.
 * @param {{ms: ?number, share: ?number, caption: string}}                                                                           [props.summary]    A total and caption to close the table with in place of its own, as a breakdown above it hands down; a null figure reads `—`.
 * @return {import('react').ReactElement|null} The table, or null without rows.
 */
export default function OwnerTable( { owners, perRequest = false, summary } ) {
	const headingId = useId();
	const { sorted, dirOf, onSort } = useColumnSort( 'ms' );
	// Neighbouring segments take neighbouring chart slots, which stay apart.
	const colourOf = useMemo(
		() =>
			new Map(
				( owners ?? [] ).map( ( { owner }, index ) => [
					owner,
					chartColor( index ),
				] )
			),
		[ owners ]
	);
	const msOf = ( row ) => ( perRequest ? row.ms_per_request : row.ms );
	const callsOf = ( row ) =>
		perRequest ? row.calls_per_request : row.calls;
	const more = useShowMore(
		sorted( owners ?? [], ( row, field ) => {
			if ( 'owner' === field ) {
				return row.owner;
			}
			if ( 'calls' === field ) {
				return callsOf( row );
			}
			return 'share' === field ? row.share : msOf( row );
		} )
	);

	if ( ! owners || 0 === owners.length ) {
		return null;
	}

	const total = summary ?? {
		ms: owners.reduce( ( sum, row ) => sum + msOf( row ), 0 ),
		share: owners.reduce( ( sum, row ) => sum + row.share, 0 ),
	};
	// The Time Breakdown's labels; an average says per request in its caption.
	const columns = [
		[ 'owner', __( 'Owner', 'newspack-event-logger-nodes' ) ],
		[ 'ms', __( 'Time', 'newspack-event-logger-nodes' ) ],
		[ 'share', __( '% of Total', 'newspack-event-logger-nodes' ) ],
		[ 'calls', __( 'Count', 'newspack-event-logger-nodes' ) ],
	];

	return (
		<div className="event-logger-request-profile">
			<h3 id={ headingId }>
				{ __( 'Time By Owner', 'newspack-event-logger-nodes' ) }
			</h3>
			<ShareBar
				segments={ owners.map( ( row ) => ( {
					key: row.owner,
					share: row.share * 100,
					title: `${ row.owner }: ${ formatDuration(
						perRequest ? row.ms_per_request : row.ms
					) } (${ ( row.share * 100 ).toFixed( 1 ) }%)`,
					fill: colourOf.get( row.owner ),
				} ) ) }
			/>
			<table
				className="newspack-nodes-table newspack-nodes-table--undivided"
				aria-labelledby={ headingId }
			>
				<thead>
					<tr className="newspack-nodes-table__header">
						{ columns.map( ( [ field, label ] ) => (
							<th scope="col" key={ field }>
								<SortHeaderButton
									field={ field }
									label={ label }
									dir={ dirOf( field ) }
									variant={
										'owner' === field ? '' : 'numeric'
									}
									onSort={ onSort }
								/>
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ more.visible.map( ( row ) => {
						const { owner, share } = row;
						const ms = msOf( row );
						const calls = Math.round( callsOf( row ) );
						return (
							<tr key={ owner }>
								<td>
									<span
										className="event-logger-profile-swatch"
										style={ {
											background: colourOf.get( owner ),
										} }
									/>
									{ UNOWNED === owner ? (
										<em>{ owner }</em>
									) : (
										owner
									) }
								</td>
								<td className="newspack-nodes-table__terminal-data">
									{ formatDuration( ms ) }
								</td>
								<td className="newspack-nodes-table__terminal-data">
									{ `${ ( share * 100 ).toFixed( 1 ) }%` }
								</td>
								<td className="newspack-nodes-table__terminal-data">
									{ calls }
								</td>
							</tr>
						);
					} ) }
					<ShowMoreRow
						more={ more }
						colSpan={ columns.length }
						label={ sprintf(
							// translators: %d: number of additional owners that can be shown.
							_n(
								'Show %d more owner',
								'Show %d more owners',
								more.hidden,
								'newspack-event-logger-nodes'
							),
							more.hidden
						) }
					/>
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
							{ null === total.ms
								? '—'
								: formatDuration( total.ms ) }
						</td>
						<td className="newspack-nodes-table__terminal-data">
							{ null === total.share
								? '—'
								: `${ ( total.share * 100 ).toFixed( 1 ) }%` }
						</td>
						<td />
					</tr>
				</tfoot>
			</table>
			<p
				className="newspack-nodes-status"
				style={ { fontSize: '12px', marginTop: '8px' } }
			>
				{ summary?.caption ?? (
					<>
						{ __(
							'Self time by the code that ran it. Listeners on significant hooks, queries and HTTP calls (to the code that asked) and plugin loads are attributed; time in an unmarked hook counts toward the enclosing owner.',
							'newspack-event-logger-nodes'
						) }
						{ perRequest &&
							` ${ __(
								'Times and counts are per request.',
								'newspack-event-logger-nodes'
							) }` }
					</>
				) }
			</p>
		</div>
	);
}

/**
 * A Time Breakdown and the Time By Owner table straight below it, as one
 * section, for one request or an average. With owners listed, their table
 * closes for both, carrying the breakdown's Total Profiled figures, and an
 * average's caption; without, the breakdown keeps them.
 *
 * @param {Object}  props              Component props.
 * @param {?Object} props.profile      `{ categories }` for one request; an average adds `count` and `total_time`.
 * @param {?number} props.totalMs      Wall-clock duration, an average's per request, every share's denominator.
 * @param {?Array}  props.owners       Owner rows as `OwnerTable` takes them.
 * @param {boolean} [props.perRequest] False for one request, whose total is its categories summed.
 * @param {string}  [props.heading]    Already-translated breakdown heading; omitted reads "Time Breakdown".
 * @param {string}  [props.serverName] Server the averages are scoped to; '' is site-wide.
 * @return {import('react').ReactElement} The section.
 */
export function ProfileWithOwners( {
	profile,
	totalMs,
	owners,
	perRequest = true,
	heading,
	serverName = '',
} ) {
	const profiled = ( perRequest ? profile?.count > 0 : profile )
		? profile
		: null;
	const owned = null !== profiled && owners?.length > 0;
	let total = null;
	if ( null !== profiled ) {
		total = perRequest
			? profiled.total_time
			: profiledTotal( profiled.categories );
	}
	const share =
		owned && null !== total && totalMs > 0 ? total / totalMs : null;
	let breakdown = null;
	if ( profiled && ( owned || ! perRequest ) ) {
		breakdown = (
			<RequestProfile
				profiles={ profiled.categories }
				totalMs={ totalMs }
				totalProfiledTime={ total }
				title={ heading }
				totaled={ ! owned }
			/>
		);
	} else if ( profiled ) {
		breakdown = (
			<ProfileWithCaption
				profiles={ profiled.categories }
				totalMs={ totalMs }
				totalProfiledTime={ total }
				count={ profiled.count }
				heading={ heading }
				serverName={ serverName }
			/>
		);
	}
	return (
		<div>
			{ breakdown }
			<OwnerTable
				owners={ owners }
				perRequest={ perRequest }
				summary={
					owned
						? {
								ms: total,
								share,
								caption: perRequest
									? averageCaption(
											profiled.count,
											serverName
									  )
									: undefined,
						  }
						: undefined
				}
			/>
		</div>
	);
}
