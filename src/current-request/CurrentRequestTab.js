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
 * the "still processing" state. There is no Refresh: the tab asks again every
 * tick, so the only thing a button could do is what is already happening.
 */

import { useState, useEffect, lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatCommandArgs, useNodeState } from '@newspack-nodes/runtime';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import { views } from './nodes/register';
// Reuse the perf dashboard's flame + profile; FlameGraph is d3-heavy (lazy).
import RequestProfile from '../overview/RequestProfile';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

const VIEW = 'currentrequest:view';

/** Every router tick: the record lands the moment the worker writes it. */
const POLL_INTERVAL_MS = 1000;

const FlameGraph = lazy( () => import( '../overview/FlameGraph' ) );

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
 * Label the `error_status` code `Request_Builder_Node` stamps on the record.
 *
 * `-` and `''` both mean a clean finish. An unrecognized code passes through
 * unchanged rather than being flattened into "ok" and hidden.
 *
 * @param {string} errorStatus The stamped code — `F`, `T`, `A`, `-`, or ''.
 * @return {string} The label to render.
 */
function statusLabel( errorStatus ) {
	switch ( errorStatus ) {
		case 'F':
			return __( 'fatal error', 'newspack-event-logger-nodes' );
		case 'T':
			return __( 'timed out', 'newspack-event-logger-nodes' );
		case 'A':
			return __( 'aborted', 'newspack-event-logger-nodes' );
		case '-':
		case '':
			return __( 'ok', 'newspack-event-logger-nodes' );
		default:
			return errorStatus;
	}
}

/**
 * The overlay's "Request" tab.
 *
 * Four states, in the order they render: `idle` when the page localized no rid
 * (logging off, running as root, or no matching `log` rule), `loading` for the
 * first paint, `processing` while the request-builder has yet to write the
 * record, and `found` once `request_detail` answers.
 *
 * The load is desired state rather than an event: the tab POLLS for its own
 * record until one arrives, so a record written a second after the page
 * rendered — or a session refused and later renewed — converges on its own,
 * and the ask rides the same tick as everything else on the page.
 *
 * `flame_data` is a separate write: the verb merges it in only once
 * `Flame_Builder_Node` has produced it, and the loop settles on the record
 * alone, so a request found ahead of its flame renders without one.
 *
 * @return {import('react').ReactElement} The tab.
 */
export default function CurrentRequestTab() {
	const { rid = '', partition = 0, perfUrl = '' } = currentRequestData();
	// The whole lifecycle in one type; `request` only lands in the found state.
	const [ state, setState ] = useState(
		/** @type {{status: string, request?: Object}} */ (
			rid ? { status: 'loading' } : { status: 'idle' }
		)
	);

	// @longform
	// The ask keeps going until the record exists. That serves both failure
	// modes at once: request_detail legitimately answers nothing until
	// requests.log has the record, and a refused command answered the same way
	// — the old catch collapsed both into a permanent "processing" that never
	// retried, so an expired session looked exactly like a slow write.
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
		enabled: Boolean( rid ) && 'found' !== state.status,
		intervalMs: POLL_INTERVAL_MS,
	} );

	const model = useNodeState( VIEW, 'view' );

	useEffect( () => {
		if ( model?.request ) {
			setState( { status: 'found', request: model.request } );
		}
	}, [ model ] );

	// Anything short of found, with a rid in hand, is still on its way.
	useEffect( () => {
		if ( rid && ! model?.request ) {
			setState( { status: 'processing' } );
		}
	}, [ rid, model ] );

	if ( 'idle' === state.status ) {
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

	if ( 'loading' === state.status ) {
		return (
			<div className="eln-current-request eln-current-request--loading newspack-nodes-performance-loading">
				<p>{ __( 'Loading…', 'newspack-event-logger-nodes' ) }</p>
			</div>
		);
	}

	if ( 'processing' === state.status ) {
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

	const { request } = state;
	const errorStatus = request.error_status ?? '-';
	const isError = '-' !== errorStatus && '' !== errorStatus;
	const traceUrl = `${ perfUrl }&request=${ encodeURIComponent( rid ) }`;
	const timestamp = Number( request.timestamp ) || 0;
	const flameData = request.flame_data;
	const hasFlame = !! ( flameData && flameData.children?.length > 0 );
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
				<p>
					<strong>
						{ __( 'URL:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ request.request_method || request.method || '' }{ ' ' }
					{ request.url }
				</p>
				<p>
					<strong>
						{ __( 'Time:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ timestamp
						? new Date( timestamp * 1000 ).toLocaleString()
						: '—' }
				</p>
				<p>
					<strong>
						{ __( 'Duration:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ ( Number( request.duration_ms ) || 0 ).toFixed( 2 ) } ms
				</p>
				{ Number( request.peak_mb ) > 0 && (
					<p>
						<strong>
							{ __( 'Memory:', 'newspack-event-logger-nodes' ) }
						</strong>{ ' ' }
						{ Number( request.peak_mb ) } MB
					</p>
				) }
				{ Number( request.status_code ) > 0 && (
					<p
						className={ `newspack-nodes-status${
							isError ? ' is-error' : ''
						}` }
					>
						<strong>
							{ __( 'Status:', 'newspack-event-logger-nodes' ) }
						</strong>{ ' ' }
						{ Number( request.status_code ) }
						{ isError ? ` — ${ statusLabel( errorStatus ) }` : '' }
					</p>
				) }
			</div>
			{ hasFlame && (
				<div className="eln-current-request__flame">
					<h3 className="newspack-nodes-section-heading">
						{ __( 'Request Trace', 'newspack-event-logger-nodes' ) }
					</h3>
					<Suspense
						fallback={
							<p className="eln-current-request__chart-loading newspack-nodes-performance-loading">
								{ __(
									'Loading chart…',
									'newspack-event-logger-nodes'
								) }
							</p>
						}
					>
						<FlameGraph data={ flameData } />
					</Suspense>
				</div>
			) }
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
