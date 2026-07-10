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
import { __ } from '@wordpress/i18n';
import { getCommandClient } from '@newspack-nodes/shared/utils/commandClient';
import unwrapCommandResponse from '@newspack-nodes/shared/utils/unwrapCommandResponse';
// Reuse the perf dashboard's flame + profile; FlameGraph is d3-heavy (lazy).
import RequestProfile from '../overview/RequestProfile';

const FlameGraph = lazy( () => import( '../overview/FlameGraph' ) );

// Page-injected anchor: rendering rid + perf base URL for the deep link.
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
	// Guard async resolution so a late reply never setStates a torn-down tab.
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
				// request_detail throws until requests.log has it (still processing).
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
		<div className="eln-current-request newspack-nodes-theme">
			<div className="eln-current-request__head">
				<h2 className="eln-current-request__title">
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
					<p>
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
