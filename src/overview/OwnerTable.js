/**
 * The "Time by owner" table: self time attributed to the code that ran it,
 * one row per owner, ranked by time. A single request supplies its own rows;
 * the URL modal and the Overview supply averages, whose time and calls
 * columns read per request.
 */

import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { formatDuration } from '@newspack-nodes/shared/utils/formatUtils';

/**
 * Owners the server names for time no listener claims; they are not code.
 */
const PSEUDO_OWNERS = [ '(unowned)', '(unmeasured)' ];

/**
 * @param {Object}                                                                                                                   props              Component props.
 * @param {?Array<{owner: string, ms?: number, ms_per_request?: number, share: number, calls?: number, calls_per_request?: number}>} props.owners       Rows, longest first; `share` is a fraction.
 * @param {boolean}                                                                                                                  [props.perRequest] True when rows carry `ms_per_request` and `calls_per_request` in place of `ms` and `calls`.
 * @return {import('react').ReactElement|null} The table, or null without rows.
 */
export default function OwnerTable( { owners, perRequest = false } ) {
	const headingId = useId();

	if ( ! owners || 0 === owners.length ) {
		return null;
	}

	return (
		<div
			className="event-logger-request-profile"
			style={ { marginTop: '40px' } }
		>
			<h3 id={ headingId }>
				{ __( 'Time by owner', 'newspack-event-logger-nodes' ) }
			</h3>
			<table
				className="newspack-nodes-table newspack-nodes-table--undivided"
				aria-labelledby={ headingId }
			>
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Owner', 'newspack-event-logger-nodes' ) }
						</th>
						<th scope="col">
							{ perRequest
								? __(
										'ms per request',
										'newspack-event-logger-nodes'
								  )
								: __( 'ms', 'newspack-event-logger-nodes' ) }
						</th>
						<th scope="col">
							{ __( 'Share', 'newspack-event-logger-nodes' ) }
						</th>
						<th scope="col">
							{ perRequest
								? __(
										'Calls per request',
										'newspack-event-logger-nodes'
								  )
								: __( 'Calls', 'newspack-event-logger-nodes' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ owners.map( ( row ) => {
						const { owner, share } = row;
						const ms = perRequest ? row.ms_per_request : row.ms;
						const calls = perRequest
							? row.calls_per_request.toFixed( 1 )
							: Math.round( row.calls );
						return (
							<tr key={ owner }>
								<td>
									{ PSEUDO_OWNERS.includes( owner ) ? (
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
