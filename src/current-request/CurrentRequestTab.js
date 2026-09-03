/**
 * Current-Request overlay tab (Debugbar/Telescope-toolbar pattern): a compact
 * summary of THE request the overlay is riding — duration, status, errors, peak
 * memory — and a deep link to the full performance trace. It does NOT duplicate
 * the dashboard; it summarizes in-page and links out for history.
 *
 * The page localizes `{ rid, partition, perfUrl }` into
 * `window.NewspackEventLoggerNodes.currentRequest`; the summary, flame graph,
 * and profile breakdown are fetched from the `performance` CI's `request_detail`
 * verb (by rid + partition). The request-builder processes the firehose
 * asynchronously, so a just-loaded page won't be in the log for a beat — that's
 * the "still processing" state. There is no Refresh: the tab asks each tick
 * until the record and its flame land, so a button would only repeat an ask
 * the tick already makes.
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatCommandArgs, useNodeState } from '@newspack-nodes/runtime';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import { views } from './nodes/register';
import RequestSummary from '../components/RequestSummary';
import { errorStatus } from '../components/errorStatus';
// Reuse the perf dashboard's trace + profile; its flame graph is lazy.
import RequestTrace from '../overview/components/RequestTrace';
import RequestProfile from '../overview/RequestProfile';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

/** The view node's name: the poll fills it, `useNodeState` reads it. */
const VIEW = 'currentrequest:view';

/** Every router tick: the record lands the moment the worker writes it. */
const POLL_INTERVAL_MS = 1000;

/** Replies carrying the record the poll waits out for the flame write. */
const FLAME_RETRY_TICKS = 5;

/**
 * The page-injected anchor for THIS request.
 *
 * `Current_Request_Overlay::enqueue_inline_data()` writes it, and only on a
 * page that enqueued this bundle, so an empty object is a normal miss — as is
 * an empty `rid`, which is what an unlogged request leaves behind.
 *
 * @return {{rid?: string, partition?: number, perfUrl?: string}} The blob, or {}.
 */
function currentRequestData() {
	return (
		( typeof window !== 'undefined' &&
			window.NewspackEventLoggerNodes &&
			window.NewspackEventLoggerNodes.currentRequest ) ||
		{}
	);
}

/**
 * The overlay's "Request" tab.
 *
 * Four states, in the order they render: `idle` when the page localized no rid
 * (logging off, running as root, or no matching `log` rule), `loading` until
 * the poll graph mounts the view node, `processing` while the request-builder
 * has yet to write the record, and `found` once `request_detail` answers with
 * one.
 *
 * The load is desired state rather than an event: the tab POLLS for its own
 * record until one arrives, so a record written a second after the page
 * rendered — or a session refused and later renewed — converges on its own,
 * and the ask rides the same tick as everything else on the page.
 *
 * A found record then holds the poll open a few more ticks for `flame_data`,
 * which `Flame_Builder_Node` writes after the record it is built from.
 *
 * @return {import('react').ReactElement} The tab.
 */
export default function CurrentRequestTab() {
	const { rid = '', partition = 0, perfUrl = '' } = currentRequestData();

	// The record lives on the view node for as long as the tab is mounted.
	const model = useNodeState( VIEW, 'view' );
	const request = model?.request ?? null;
	const flameData = request?.flame_data;
	const hasFlame = !! ( flameData && flameData.children?.length > 0 );

	// @longform
	// The flame is a SECOND write: Flame_Builder consumes requests.log after
	// Request_Builder writes the record, so the reply that finds the request
	// almost always predates its flame. Asking on past the record delivers the
	// trace without a reload, and the retry bound keeps a site running no
	// flame-builder topology from asking for one forever.
	const [ asksSinceFound, setAsksSinceFound ] = useState( 0 );
	useEffect( () => {
		if ( model?.request ) {
			setAsksSinceFound( ( n ) => n + 1 );
		}
	}, [ model ] );

	// @longform
	// The ask keeps going until the record exists, which serves both failure
	// modes at once: request_detail answers nothing until requests.log has the
	// record, and a refused command answers the same way, so an expired session
	// recovers on the same retry a slow write does. Settling PAUSES rather than
	// disabling: `enabled` tears the graph down, taking the view node that owns
	// the answer with it.
	useBatchedPoll( {
		build: ( { interpreter, tee } ) =>
			addSliceFetcher( interpreter, {
				fetcher: 'currentrequest:fetch',
				receiver: 'currentrequest:in',
				command: 'request_detail',
				argsFn: () => formatCommandArgs( [ rid ], { partition } ),
				view: VIEW,
				viewClass: views.CurrentRequestView,
				tee,
				target: egressPath( 'performance' ),
			} ),
		timerName: 'currentrequest:timer',
		teeName: 'currentrequest:tee',
		enabled: Boolean( rid ),
		paused: hasFlame || asksSinceFound > FLAME_RETRY_TICKS,
		intervalMs: POLL_INTERVAL_MS,
	} );

	if ( ! rid ) {
		return (
			<div className="eln-current-request eln-current-request--empty newspack-nodes-empty-state">
				<p>
					{ __(
						'No request to inspect on this page yet.',
						'newspack-event-logger-nodes'
					) }
				</p>
			</div>
		);
	}

	if ( undefined === model ) {
		return (
			<div className="eln-current-request eln-current-request--loading newspack-nodes-performance-loading">
				<p>{ __( 'Loading…', 'newspack-event-logger-nodes' ) }</p>
			</div>
		);
	}

	if ( ! request ) {
		return (
			<div className="eln-current-request eln-current-request--processing newspack-nodes-status">
				<p>
					{ __(
						'This request is still processing — the request builder hasn’t logged it yet.',
						'newspack-event-logger-nodes'
					) }
				</p>
			</div>
		);
	}

	const stamped = request.error_status ?? '-';
	const status = errorStatus( stamped );
	// An unrecognized code shows itself rather than being hidden as "ok".
	const isError = '-' !== stamped && '' !== stamped;
	const traceUrl = `${ perfUrl }&request=${ encodeURIComponent( rid ) }`;
	const hasProfiles = !! request.profiles;

	return (
		<div className="eln-current-request">
			<div className="eln-current-request__head">
				<h2 className="eln-current-request__title newspack-dashboard-title">
					{ __( 'Request:', 'newspack-event-logger-nodes' ) } { rid }
				</h2>
				<a
					className="button button-secondary eln-current-request__trace"
					href={ traceUrl }
				>
					{ __( 'View full trace', 'newspack-event-logger-nodes' ) }
				</a>
			</div>
			<div className="eln-current-request__info">
				<RequestSummary
					request={ request }
					statusNote={ isError ? status?.label ?? stamped : '' }
				/>
			</div>
			{ hasFlame && <RequestTrace flameData={ flameData } /> }
			{ hasProfiles && (
				<div className="eln-current-request__profiles">
					<RequestProfile
						profiles={ request.profiles }
						totalMs={ Number( request.duration_ms ) || 0 }
					/>
				</div>
			) }
		</div>
	);
}
