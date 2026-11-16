/**
 * The request window's blocks, each the section the window showed, over the
 * component that draws it: the summary line, the findings, the flame graph,
 * the flame lookup's note, the time breakdown with its owners, and the log
 * entries.
 *
 * Each reads `dump_request` for the request page state names, through the
 * window's one source (`./windowQuestions`), and hides itself where its part
 * of the record is empty. The flame graph and the log talk through the
 * page's `revealRef`: the log publishes its `reveal` there, and a Cmd/Ctrl
 * click on a frame calls it to unfold and scroll to the frame's entry.
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePage } from '@newspack-nodes/shared/composition/page';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import RequestSummary from '../../components/RequestSummary';
import { errorStatus } from '../../components/errorStatus';
import RequestTrace, { FlameCutNote } from '../components/RequestTrace';
import LogEntriesTable from '../components/LogEntriesTable';
import FindingList from '../components/FindingList';
import { ProfileWithOwners } from '../OwnerTable';
import { profileOrder } from '../RequestProfile';
import { requestEntries, useRequestDetail } from './windowQuestions';

/**
 * Whether the record carries a flame to draw.
 *
 * @param {Object} detail The `dump_request` answer.
 * @return {boolean} Whether it does.
 */
const hasFlame = ( detail ) => detail.flame_data?.children?.length > 0;

/**
 * The request's URL, time, duration, memory and status, and its error as a
 * badge.
 *
 * @return {?import('react').ReactElement} The summary, or nothing before an answer.
 */
function SummaryView() {
	const { data } = useRequestDetail();
	if ( ! data ) {
		return null;
	}
	const status = errorStatus( data.error_status );
	return (
		<div className="event-logger-request-info">
			<RequestSummary
				request={ data }
				errorRow={
					status && (
						<p>
							<strong>
								{ __(
									'Error:',
									'newspack-event-logger-nodes'
								) }
							</strong>{ ' ' }
							<span
								className={ `newspack-nodes-badge newspack-nodes-status ${ status.tone }` }
							>
								{ status.label }
							</span>
						</p>
					)
				}
			/>
		</div>
	);
}

/**
 * What is wrong, worst first: the answer before the evidence. A record the
 * detectors never ran on shows no findings; one with nothing to show says
 * so; a folded record says what the merge cost its sequence, unless its
 * findings already do.
 *
 * @return {?import('react').ReactElement} The findings, or nothing.
 */
function FindingsView() {
	const { data } = useRequestDetail();
	if ( ! data ) {
		return null;
	}
	const hasFindings = Array.isArray( data.findings );
	const isFolded = !! data.folded;
	// A folded record answers for itself in the banner, not as "no entries".
	const hasNoDetail =
		0 === requestEntries( data ).entries.length &&
		! hasFlame( data ) &&
		! data.profiles &&
		! isFolded;
	return (
		<>
			{ hasFindings && (
				<div>
					<h3 className="newspack-nodes-section-heading">
						{ __( 'Findings', 'newspack-event-logger-nodes' ) }
					</h3>
					<FindingList findings={ data.findings } />
				</div>
			) }
			{ hasNoDetail && (
				<p className="event-logger-request-detail-empty newspack-nodes-no-selection">
					{ __(
						'No log entries available for this request.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }
			{ isFolded && ! hasFindings && (
				<p className="newspack-nodes-banner is-warning">
					{ __(
						'Aggregated under load. This request logged more than the worker could hold alongside the others in flight, so repeated spans were merged into one frame each — counts and totals are exact, the sequence is not. Its opening and closing entries are kept in full, and the merged spans sit between them in the log, each showing how many instances it stands for.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }
		</>
	);
}

/**
 * The request's flame graph, built by `Flame_Builder_Node`, in its
 * breakdown's category order, with its memory track over its entries.
 *
 * @return {?import('react').ReactElement} The graph, or nothing for none.
 */
function TraceView() {
	const { data } = useRequestDetail();
	const { revealRef } =
		/** @type {import('../performancePageData').PerformancePageValue} */ (
			usePage()
		);
	const profiles = data?.profiles;
	const order = useMemo( () => profileOrder( profiles ), [ profiles ] );
	return data && hasFlame( data ) ? (
		<div>
			<RequestTrace
				flameData={ data.flame_data }
				order={ order }
				entries={ requestEntries( data ).entries }
				onRevealEntry={ ( n, path ) => revealRef.current?.( n, path ) }
			/>
		</div>
	) : null;
}

/**
 * The note standing where a flame lookup that ran out of time left none.
 *
 * @return {?import('react').ReactElement} The note, or nothing.
 */
function FlameCutView() {
	const { data } = useRequestDetail();
	return data && ! hasFlame( data ) && data.flame_cut ? (
		<FlameCutNote />
	) : null;
}

/**
 * The request's time breakdown, with the Time By Owner table beneath it.
 *
 * @return {?import('react').ReactElement} The profile, or nothing for none.
 */
function ProfileView() {
	const { data } = useRequestDetail();
	if ( ! data || ! ( data.profiles || data.owners?.length > 0 ) ) {
		return null;
	}
	return (
		<ProfileWithOwners
			profile={ data.profiles ? { categories: data.profiles } : null }
			totalMs={ data.duration_ms || 0 }
			owners={ data.owners }
			perRequest={ false }
		/>
	);
}

/**
 * The request's log, or what a folded request kept instead, mounted afresh
 * for each request, so a fold state never carries over.
 *
 * @return {?import('react').ReactElement} The log, or nothing for none.
 */
function EntriesView() {
	const { data } = useRequestDetail();
	const [ rid ] = usePageState( 'request' );
	const { revealRef } =
		/** @type {import('../performancePageData').PerformancePageValue} */ (
			usePage()
		);
	const { entries, realCount } = requestEntries( data );
	return entries.length > 0 ? (
		<LogEntriesTable
			key={ rid }
			entries={ entries }
			realCount={ realCount }
			revealRef={ revealRef }
			request={ data }
		/>
	) : null;
}

/**
 * Each request window block, by block name.
 *
 * @type {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>}
 */
export const REQUEST_WINDOW_VIEWS = {
	'newspack-event-logger-nodes/request-summary': { View: SummaryView },
	'newspack-event-logger-nodes/request-findings': { View: FindingsView },
	'newspack-event-logger-nodes/request-trace': { View: TraceView },
	'newspack-event-logger-nodes/request-flame-cut': { View: FlameCutView },
	'newspack-event-logger-nodes/request-profile': { View: ProfileView },
	'newspack-event-logger-nodes/request-entries': { View: EntriesView },
};
