/**
 * The "Time by owner" table: self time attributed to the code that ran it,
 * one row per owner, ranked by time. A single request supplies its own rows;
 * the URL modal and the Overview supply averages, whose time and calls
 * columns read per request.
 */

import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { formatDuration } from '@newspack-nodes/shared/utils/formatUtils';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';

import { ShareBar } from './RequestProfile';
import SortHeaderButton, { useColumnSort } from './components/SortHeaderButton';

/**
 * The owners the server names for time no code claims, each in a muted
 * neutral of its own: `(unowned)` the grey untyped spans take, `(unmeasured)`
 * the darker Neutral 600, receding furthest.
 */
const PSEUDO_OWNER_COLORS = {
	'(unowned)': '#9e9e9e',
	'(unmeasured)': '#6c6c6c',
};

/**
 * An owner's colour: its pseudo-owner neutral, else the series colour its
 * name hashes to, so an owner keeps one colour in every view and at every
 * rank.
 *
 * @param {string} owner The owner's name.
 * @return {string} A CSS colour value.
 */
const ownerColor = ( owner ) => {
	if ( Object.hasOwn( PSEUDO_OWNER_COLORS, owner ) ) {
		return PSEUDO_OWNER_COLORS[ owner ];
	}
	let hash = 0;
	for ( const char of owner ) {
		hash = ( hash * 31 + char.codePointAt( 0 ) ) >>> 0;
	}
	return chartColor( hash );
};

/**
 * @param {Object}                                                                                                                   props              Component props.
 * @param {?Array<{owner: string, ms?: number, ms_per_request?: number, share: number, calls?: number, calls_per_request?: number}>} props.owners       Rows, longest first; `share` is a fraction.
 * @param {boolean}                                                                                                                  [props.perRequest] True when rows carry `ms_per_request` and `calls_per_request` in place of `ms` and `calls`.
 * @return {import('react').ReactElement|null} The table, or null without rows.
 */
export default function OwnerTable( { owners, perRequest = false } ) {
	const headingId = useId();
	const { sorted, dirOf, onSort } = useColumnSort( 'ms' );

	if ( ! owners || 0 === owners.length ) {
		return null;
	}

	const msOf = ( row ) => ( perRequest ? row.ms_per_request : row.ms );
	const callsOf = ( row ) =>
		perRequest ? row.calls_per_request : row.calls;
	const rows = sorted( owners, ( row, field ) => {
		if ( 'owner' === field ) {
			return row.owner;
		}
		if ( 'calls' === field ) {
			return callsOf( row );
		}
		return 'share' === field ? row.share : msOf( row );
	} );
	// Everything a span covers: every owner but the time no span covers.
	const profiled = owners.filter( ( { owner } ) => '(unmeasured)' !== owner );
	const columns = [
		[ 'owner', __( 'Owner', 'newspack-event-logger-nodes' ) ],
		[
			'ms',
			perRequest
				? __( 'ms per request', 'newspack-event-logger-nodes' )
				: __( 'ms', 'newspack-event-logger-nodes' ),
		],
		[ 'share', __( 'Share', 'newspack-event-logger-nodes' ) ],
		[
			'calls',
			perRequest
				? __( 'Calls per request', 'newspack-event-logger-nodes' )
				: __( 'Calls', 'newspack-event-logger-nodes' ),
		],
	];

	return (
		<div className="event-logger-request-profile">
			<h3 id={ headingId }>
				{ __( 'Time by owner', 'newspack-event-logger-nodes' ) }
			</h3>
			<ShareBar
				segments={ owners.map( ( row ) => ( {
					key: row.owner,
					share: row.share * 100,
					title: `${ row.owner }: ${ formatDuration(
						perRequest ? row.ms_per_request : row.ms
					) } (${ ( row.share * 100 ).toFixed( 1 ) }%)`,
					color: ownerColor( row.owner ),
				} ) ) }
			/>
			<table
				className="newspack-nodes-table newspack-nodes-table--undivided"
				aria-labelledby={ headingId }
			>
				<thead>
					<tr>
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
					{ rows.map( ( row ) => {
						const { owner, share } = row;
						const ms = msOf( row );
						const calls = perRequest
							? callsOf( row ).toFixed( 1 )
							: Math.round( callsOf( row ) );
						return (
							<tr key={ owner }>
								<td>
									<span
										className="event-logger-profile-swatch"
										style={ {
											background: ownerColor( owner ),
										} }
									/>
									{ Object.hasOwn(
										PSEUDO_OWNER_COLORS,
										owner
									) ? (
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
							{ formatDuration(
								profiled.reduce(
									( sum, row ) => sum + msOf( row ),
									0
								)
							) }
						</td>
						<td className="newspack-nodes-table__terminal-data">
							{ `${ (
								profiled.reduce(
									( sum, row ) => sum + row.share,
									0
								) * 100
							).toFixed( 1 ) }%` }
						</td>
						<td />
					</tr>
				</tfoot>
			</table>
			<p
				className="newspack-nodes-status"
				style={ { fontSize: '12px', marginTop: '8px' } }
			>
				{ __(
					'Self time by the code that ran it. Listeners on significant hooks, queries and HTTP calls (to the code that asked) and plugin loads are attributed; time in an unmarked hook counts toward the enclosing owner.',
					'newspack-event-logger-nodes'
				) }
			</p>
		</div>
	);
}
