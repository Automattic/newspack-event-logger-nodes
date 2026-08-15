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

import { lazy, Suspense, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The flame graph drags in d3 and d3-flame-graph, the heaviest dependency in
 * this bundle, so it loads only when a request that has flame data is open.
 *
 * knip cannot parse JSX in a `.js` file and so never sees this `import()`;
 * `FlameGraph.js` is therefore listed as an entry in `knip.json` to keep the
 * dead-code audit from claiming it.
 */
const FlameGraph = lazy( () => import( '../FlameGraph' ) );

import RequestProfile from '../RequestProfile';
import LogEntriesTable from './LogEntriesTable';

/** Vertical rhythm between the detail's sections. */
const SECTION_STYLE = { marginBottom: '20px' };

/**
 * Request Detail View Component.
 *
 * `requestDetail` is the decoded request body: `url`, `timestamp` (seconds),
 * `duration_ms`, `peak_mb`, `status_code`, `profiles`, and `error_status`.
 * `Request_Builder_Node` stamps `error_status` as one character — `-` for a
 * clean request, `T` for one evicted before it completed, `F` for a fatal — and
 * only the last two get a badge. The durable body names the HTTP verb
 * `request_method`; the compact summary that `build_compact_summary()` writes
 * names it `method`, so both are read.
 *
 * @param {Object} props                 Component props.
 * @param {Object} props.requestDetail   Decoded request body; see above.
 * @param {Object} props.flameData       Server-built flame tree, or null.
 * @param {Array}  props.indentedEntries Log entries from computeIndentedEntries().
 * @param {number} props.realEntryCount  Count of real (non-placeholder) log entries.
 * @param {string} [props.rid]           Request id, so the picker can scope what is inside.
 * @param {number} [props.partition]     Its partition; nothing downstream can recover it.
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
	const isTimedOut = requestDetail.error_status === 'T';
	const isFatal = requestDetail.error_status === 'F';
	const isFolded = !! requestDetail.folded;
	const hasEntries = indentedEntries.length > 0;
	const hasFlame = flameData && flameData.children?.length > 0;
	const hasProfiles = !! requestDetail.profiles;
	const hasNoDetail =
		! hasEntries && ! hasFlame && ! hasProfiles && ! isFolded;

	return (
		// The picker chain: DOM nesting says which request this belongs to.
		<div
			className="event-logger-request-detail"
			data-ask={
				rid ? `request:${ rid }:${ partition ?? 0 }` : undefined
			}
		>
			<div className="event-logger-request-info" style={ SECTION_STYLE }>
				<p>
					<strong>
						{ __( 'URL:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ requestDetail.request_method ||
						requestDetail.method ||
						'' }{ ' ' }
					{ requestDetail.url }
				</p>
				<p>
					<strong>
						{ __( 'Time:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ new Date(
						requestDetail.timestamp * 1000
					).toLocaleString() }
				</p>
				<p>
					<strong>
						{ __( 'Duration:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ requestDetail.duration_ms?.toFixed( 2 ) }
					ms
				</p>
				{ requestDetail.peak_mb > 0 && (
					<p>
						<strong>
							{ __( 'Memory:', 'newspack-event-logger-nodes' ) }
						</strong>{ ' ' }
						{ requestDetail.peak_mb } MB
					</p>
				) }
				{ requestDetail.status_code > 0 && (
					<p>
						<strong>
							{ __( 'Status:', 'newspack-event-logger-nodes' ) }
						</strong>{ ' ' }
						{ requestDetail.status_code }
					</p>
				) }
				{ ( isTimedOut || isFatal ) && (
					<p>
						<strong>
							{ __( 'Error:', 'newspack-event-logger-nodes' ) }
						</strong>{ ' ' }
						<span
							className={ `newspack-nodes-badge newspack-nodes-status ${
								isTimedOut ? 'is-warning' : 'is-error'
							}` }
						>
							{ isTimedOut
								? __(
										'Timed out (orphaned request)',
										'newspack-event-logger-nodes'
								  )
								: __(
										'Fatal error',
										'newspack-event-logger-nodes'
								  ) }
						</span>
					</p>
				) }
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
				<div
					className="event-logger-flame-container"
					style={ SECTION_STYLE }
				>
					<h3 className="newspack-nodes-section-heading">
						{ __( 'Request Trace', 'newspack-event-logger-nodes' ) }
					</h3>
					<Suspense
						fallback={
							<div className="event-logger-detail-loading newspack-nodes-performance-loading">
								{ __(
									'Loading chart…',
									'newspack-event-logger-nodes'
								) }
							</div>
						}
					>
						<FlameGraph
							data={ flameData }
							onRevealEntry={ ( path ) => {
								if ( revealRef.current ) {
									revealRef.current( path );
								}
							} }
						/>
					</Suspense>
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
