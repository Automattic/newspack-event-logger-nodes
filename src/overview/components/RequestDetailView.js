/**
 * Request Detail View Component
 *
 * Everything known about one logged request: the summary line, the flame
 * graph, the profile breakdown, and the log entries table. `PerformanceDashboard`
 * renders this inside the URL modal once a request row is selected.
 *
 * The data arrives already assembled. The `requestdetail:view` node holds the
 * slice the `performance` CI's `request_detail` verb returns — the durable
 * request body read out of a `requests.log` partition, with the matching
 * `flames.log` entry merged in as `flame_data`. Nothing here fetches: each
 * section renders its prop and hides itself when that prop is empty.
 *
 * The flame graph and the entries table talk to each other through `revealRef`:
 * `LogEntriesTable` publishes its `revealPath` function there, and a
 * Cmd/Ctrl+click on a flame frame calls it to unfold and scroll to the matching
 * row.
 */

import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import RequestSummary from '../../components/RequestSummary';
import RequestProfile from '../RequestProfile';
import RequestTrace from './RequestTrace';
import LogEntriesTable from './LogEntriesTable';
import { errorStatus } from '../../components/errorStatus';

/** Vertical rhythm between the detail's sections. */
const SECTION_STYLE = { marginBottom: '20px' };

/**
 * `requestDetail` is the durable request body `record_of()` writes and
 * `request_detail` reads back: `url`, `request_method`, `timestamp` (seconds),
 * `duration_ms`, `peak_mb`, `status_code`, `profiles`, `error_status` and
 * `folded`.
 *
 * `Request_Builder_Node` stamps `error_status` as one character — `-` for a
 * clean request, and one of the shared `ERROR_STATUSES` codes for anything
 * else, which is what gets the badge. It sets `folded` on a request it merged
 * under memory pressure, and the banner is where that record says what the
 * merge cost its sequence.
 *
 * @param {Object}  props                 Component props.
 * @param {Object}  props.requestDetail   Decoded request body; see above.
 * @param {?Object} props.flameData       Server-built flame tree; null whenever the builder has not written one, which is normal for an unprofiled request.
 * @param {Array}   props.indentedEntries Log entries from computeIndentedEntries().
 * @param {number}  props.realEntryCount  Count of real (non-placeholder) log entries.
 * @param {string}  [props.rid]           Request id, so the picker can scope what is inside.
 * @param {number}  [props.partition]     Partition the record was read from, riding the `request:` descriptor as the brief's first place to look; the rid's own hash names it too, so an absent one costs a fallback rather than the answer.
 * @return {import('react').ReactElement} Request detail view.
 */
export default function RequestDetailView( {
	requestDetail,
	flameData,
	indentedEntries,
	realEntryCount,
	rid,
	partition,
} ) {
	const revealRef = useRef( null );
	const status = errorStatus( requestDetail.error_status );
	const isFolded = !! requestDetail.folded;
	const hasEntries = indentedEntries.length > 0;
	const hasFlame = flameData && flameData.children?.length > 0;
	const hasProfiles = !! requestDetail.profiles;
	// A folded record answers for itself in the banner, not as "no entries".
	const hasNoDetail =
		! hasEntries && ! hasFlame && ! hasProfiles && ! isFolded;
	const errorRow = status && (
		<p>
			<strong>{ __( 'Error:', 'newspack-event-logger-nodes' ) }</strong>{ ' ' }
			<span
				className={ `newspack-nodes-badge newspack-nodes-status ${ status.tone }` }
			>
				{ status.label }
			</span>
		</p>
	);

	return (
		// The picker chain: DOM nesting says which request this belongs to.
		<div
			className="event-logger-request-detail"
			data-ask={
				rid ? `request:${ rid }:${ partition ?? 0 }` : undefined
			}
		>
			<div className="event-logger-request-info" style={ SECTION_STYLE }>
				<RequestSummary
					request={ requestDetail }
					errorRow={ errorRow }
				/>
			</div>

			{ hasNoDetail && (
				<p className="event-logger-request-detail-empty newspack-nodes-no-selection">
					{ __(
						'No log entries available for this request.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }

			{ isFolded && (
				<p className="newspack-nodes-banner is-warning">
					{ __(
						'Aggregated under load. This request logged more than the worker could hold alongside the others in flight, so repeated spans were merged into one frame each — counts and totals are exact, the sequence is not. Its opening and closing entries are kept in full, and the merged spans sit between them in the log, each showing how many instances it stands for.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }

			{ /* Request Flame Graph (built by Flame_Builder_Node, read here) */ }
			{ hasFlame && (
				<div style={ SECTION_STYLE }>
					<RequestTrace
						flameData={ flameData }
						entries={ indentedEntries }
						onRevealEntry={ ( path ) =>
							revealRef.current?.( path )
						}
					/>
				</div>
			) }

			{ /* Profile Breakdown */ }
			{ hasProfiles && (
				<div style={ SECTION_STYLE }>
					<RequestProfile
						profiles={ requestDetail.profiles }
						totalMs={ requestDetail.duration_ms || 0 }
					/>
				</div>
			) }

			{ /* Log Entries Table, or what a folded request kept instead */ }
			{ hasEntries && (
				<LogEntriesTable
					entries={ indentedEntries }
					realCount={ realEntryCount }
					revealRef={ revealRef }
				/>
			) }
		</div>
	);
}
