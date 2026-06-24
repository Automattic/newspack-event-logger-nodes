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
 * the "still processing" state, with a Refresh to re-poll.
 */

import {
	useState,
	useEffect,
	useCallback,
	useRef,
	lazy,
	Suspense,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getCommandClient } from '@newspack-nodes/shared/utils/commandClient';
import unwrapCommandResponse from '@newspack-nodes/shared/utils/unwrapCommandResponse';
// Reuse the performance dashboard's flame graph + profile breakdown so the tab
// shows the same trace, not a reimplementation. FlameGraph is d3-heavy → lazy.
import RequestProfile from '../performance-dashboards/RequestProfile';

const FlameGraph = lazy( () =>
	import( '../performance-dashboards/FlameGraph' )
);

// The page-injected summary anchor: the rid of the request that rendered this
// page + the perf-dashboard base URL for the deep link.
function currentRequestData() {
	return (
		( typeof window !== 'undefined' &&
			window.NewspackEventLoggerNodes &&
			window.NewspackEventLoggerNodes.currentRequest ) ||
		{}
	);
}

// error_status codes the request-builder stamps; '-' / '' means a clean finish.
function statusLabel( errorStatus ) {
	switch ( errorStatus ) {
		case 'F':
			return __( 'fatal error', 'newspack-event-logger-nodes' );
		case 'T':
			return __( 'timed out', 'newspack-event-logger-nodes' );
		case '-':
		case '':
			return __( 'ok', 'newspack-event-logger-nodes' );
		default:
			return errorStatus;
	}
}

/**
 * @param {Object} [props]               Props.
 * @param {Object} [props.commandClient] Injected one-shot command client (tests); defaults to the page singleton.
 * @return {import('react').ReactElement} The tab.
 */
export default function CurrentRequestTab( { commandClient } = {} ) {
	const { rid = '', partition = 0, perfUrl = '' } = currentRequestData();
	const [ state, setState ] = useState(
		rid ? { status: 'loading' } : { status: 'idle' }
	);
	// The tab unmounts when the user switches overlay tabs; guard the async
	// resolution so a late reply never setStates a torn-down component.
	const mountedRef = useRef( true );
	useEffect( () => {
		mountedRef.current = true;
		return () => {
			mountedRef.current = false;
		};
	}, [] );

	const load = useCallback( () => {
		if ( ! rid ) {
			return;
		}
		setState( { status: 'loading' } );
		const client = commandClient || getCommandClient();
		client
			.send( {
				to: 'performance',
				verb: 'request_detail',
				args: `${ rid } --partition=${ partition }`,
			} )
			.then( ( reply ) => {
				if ( ! mountedRef.current ) {
					return;
				}
				// request_detail throws (TM_ERROR) until the request-builder has
				// written the request to requests.log — treat as "still
				// processing" so a Refresh picks it up moments later.
				const request = unwrapCommandResponse( reply );
				setState(
					request
						? { status: 'found', request }
						: { status: 'processing' }
				);
			} )
			.catch( () => {
				if ( mountedRef.current ) {
					setState( { status: 'processing' } );
				}
			} );
	}, [ rid, partition, commandClient ] );

	useEffect( () => {
		load();
	}, [ load ] );

	if ( 'idle' === state.status ) {
		return (
			<div className="eln-current-request eln-current-request--empty">
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
			<div className="eln-current-request eln-current-request--loading">
				<p>{ __( 'Loading…', 'newspack-event-logger-nodes' ) }</p>
			</div>
		);
	}

	if ( 'processing' === state.status ) {
		return (
			<div className="eln-current-request eln-current-request--processing">
				<p>
					{ __(
						'This request is still processing — the request builder hasn’t logged it yet.',
						'newspack-event-logger-nodes'
					) }
				</p>
				<button type="button" className="button" onClick={ load }>
					{ __( 'Refresh', 'newspack-event-logger-nodes' ) }
				</button>
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
			<div className="eln-current-request__url" title={ request.url }>
				{ request.url }
			</div>
			<div className="nodes-cards">
				<Card label={ __( 'Duration', 'newspack-event-logger-nodes' ) }>
					{ sprintf(
						// translators: %d: request duration in milliseconds.
						__( '%d ms', 'newspack-event-logger-nodes' ),
						Number( request.duration_ms ) || 0
					) }
				</Card>
				<Card label={ __( 'Status', 'newspack-event-logger-nodes' ) }>
					{ Number( request.status_code ) || 0 }
				</Card>
				<Card
					label={ __( 'Result', 'newspack-event-logger-nodes' ) }
					tone={ isError ? 'error' : 'ok' }
				>
					{ statusLabel( errorStatus ) }
				</Card>
				<Card label={ __( 'Peak mem', 'newspack-event-logger-nodes' ) }>
					{ sprintf(
						// translators: %s: peak memory in megabytes.
						__( '%s MB', 'newspack-event-logger-nodes' ),
						Number( request.peak_mb ) || 0
					) }
				</Card>
				<Card label={ __( 'Time', 'newspack-event-logger-nodes' ) }>
					{ timestamp
						? new Date( timestamp * 1000 ).toLocaleTimeString()
						: '—' }
				</Card>
			</div>
			<a
				className="button button-secondary eln-current-request__trace"
				href={ traceUrl }
			>
				{ __( 'View full trace', 'newspack-event-logger-nodes' ) }
			</a>
			{ hasFlame && (
				<div className="eln-current-request__flame">
					<h3>
						{ __( 'Request Trace', 'newspack-event-logger-nodes' ) }
					</h3>
					<Suspense
						fallback={
							<p>
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

function Card( { label, tone, children } ) {
	return (
		<div
			className={ `nodes-card${ tone ? ` nodes-card--${ tone }` : '' }` }
		>
			<div className="nodes-card__label">{ label }</div>
			<div className="nodes-card__value">{ children }</div>
		</div>
	);
}
