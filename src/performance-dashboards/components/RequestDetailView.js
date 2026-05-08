/**
 * Request Detail View Component
 *
 * Displays detailed information for a selected request including
 * request info, flame graph, profile breakdown, and log entries.
 */

import { lazy, Suspense, useRef } from '@wordpress/element';

// Lazy load FlameGraph (heaviest component - uses d3-flame-graph).
const FlameGraph = lazy( () => import( '../FlameGraph' ) );

import RequestProfile from '../RequestProfile';
import LogEntriesTable from './LogEntriesTable';

const SECTION_STYLE = { marginBottom: '20px' };

/**
 * Request Detail View Component.
 *
 * @param {Object} props                 Component props.
 * @param {Object} props.requestDetail   Request detail data object.
 * @param {Object} props.flameData       Flame graph data.
 * @param {Array}  props.indentedEntries Processed log entries array.
 * @param {number} props.realEntryCount  Count of real (non-placeholder) log entries.
 * @return {JSX.Element} Request detail view.
 */
export default function RequestDetailView( {
	requestDetail,
	flameData,
	indentedEntries,
	realEntryCount,
} ) {
	const revealRef = useRef( null );
	const isTimedOut = requestDetail.error_status === 'T';
	const isFatal = requestDetail.error_status === 'F';
	const hasEntries = indentedEntries.length > 0;
	const hasFlame = flameData && flameData.children?.length > 0;
	const hasProfiles = !! requestDetail.profiles;
	const hasNoDetail = ! hasEntries && ! hasFlame && ! hasProfiles;

	return (
		<div className="event-logger-request-detail">
			<div className="event-logger-request-info" style={ SECTION_STYLE }>
				<p>
					<strong>URL:</strong>{ ' ' }
					{ requestDetail.request_method ||
						requestDetail.method ||
						'' }{ ' ' }
					{ requestDetail.url }
				</p>
				<p>
					<strong>Time:</strong>{ ' ' }
					{ new Date(
						requestDetail.timestamp * 1000
					).toLocaleString() }
				</p>
				<p>
					<strong>Duration:</strong>{ ' ' }
					{ requestDetail.duration_ms?.toFixed( 2 ) }
					ms
				</p>
				{ requestDetail.peak_mb > 0 && (
					<p>
						<strong>Memory:</strong> { requestDetail.peak_mb } MB
					</p>
				) }
				{ requestDetail.status_code > 0 && (
					<p>
						<strong>Status:</strong> { requestDetail.status_code }
					</p>
				) }
				{ ( isTimedOut || isFatal ) && (
					<p>
						<strong>Error:</strong>{ ' ' }
						<span
							style={ {
								color: isTimedOut ? '#dba617' : '#d63638',
								fontWeight: 600,
							} }
						>
							{ isTimedOut
								? 'Timed out (orphaned request)'
								: 'Fatal error' }
						</span>
					</p>
				) }
			</div>

			{ hasNoDetail && (
				<p style={ { color: '#757575', fontStyle: 'italic' } }>
					No log entries available for this request.
				</p>
			) }

			{ /* Request Flame Graph (built client-side from entries) */ }
			{ hasFlame && (
				<div
					className="event-logger-flame-container"
					style={ SECTION_STYLE }
				>
					<h3>Request Trace</h3>
					<Suspense fallback={ <div>Loading chart...</div> }>
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

			{ /* Log Entries Table */ }
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
