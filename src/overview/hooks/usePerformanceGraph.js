/**
 * usePerformanceGraph — the Performance Dashboard data graph as a GENUINE node
 * graph on the substrate batched-poll toolkit (useBatchedPoll + addSliceFetcher),
 * D1b de-god. Replaces the single `performance:command` god command-builder + the
 * 4-slice `performance:view` god view with independent per-slice graph paths:
 *
 *   perf:timer (Timer) → perf:tee (Tee) → fetch-overview, fetch-urls (Fetchers,
 *     each given an argsFn fire-time getter reading the CURRENT React UI state) →
 *     _shell/_http/performance
 *   overviewIn (Tee) → overview:view (OverviewView)
 *   urlsIn     (Tee) → urls:view     (UrlsView)
 *
 * overview + urls are POLLED — useBatchedPoll owns the Timer/Tee/_shell/_http +
 * lock-flush batching + page-visibility gate, and each Fetcher's getter makes the
 * tick emit live filter/sort/page args (so the data tracks UI state with no
 * re-wiring). A serverFilter/breakdown change also fires an immediate poke.
 *
 * url_detail + request_detail are ON-DEMAND (modal-open → fetch), NOT on the
 * Timer. The url_detail reply rides through the committed UrlDetailMergeNode
 * (incremental merge + last_modified/500-cap dedup) on the receiver→view edge:
 *
 *   urldetailIn (Tee) → urldetail:merge (UrlDetailMerge) → urldetail:view (UrlDetailView)
 *   requestdetailIn (Tee) → requestdetail:view (RequestDetailView)
 *
 * resolveRequest (request_search, navigation) + fetchUrlBreakdown (url_detail
 * breakdown) are AWAITED Promises settled via the relevant view's PendingReplies
 * (the useHookCatalogGraph / hook-catalog-view-node pattern): the hook stashes a
 * resolver under message[ID], the server replies TO=FROM=that view, and the view's
 * PendingReplies.settle resolves/rejects without touching its data slice.
 *
 * The hook returns ONLY control callbacks (`handleUrlParamsChange`,
 * `resolveRequest`, `fetchUrlBreakdown`); React reads each slice via its own
 * useNodeState('<slice>:view','view'). The command boundary is injectable via
 * `opts.commandClient`.
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import {
	Core,
	newMessage,
	TYPE,
	TO,
	FROM,
	ID,
	VALUE,
	TM_COMMAND,
	TM_STRUCT,
	formatCommandArgs,
} from '@newspack-nodes/runtime';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { getCommandClient } from '@newspack-nodes/shared/utils/commandClient';
import unwrapCommandResponse from '@newspack-nodes/shared/utils/unwrapCommandResponse';
import '../nodes/register';

// The server CI mount + the egress path the Fetchers/on-demand commands target.
const SERVER = 'performance';
const TARGET = `_shell/_http/${ SERVER }`;
const HTTP = '_http';

// Per-URL ruleset CI via the same exospine (the "Log this URL" affordance).
const RULES_TARGET = '_shell/_http/rules';

// Slice view + receiver names.
const OVERVIEW_VIEW = 'overview:view';
const URLS_VIEW = 'urls:view';
const URLDETAIL_VIEW = 'urldetail:view';
const URLDETAIL_RECV = 'urldetailIn';
const URLDETAIL_MERGE = 'urldetail:merge';
const URLDETAIL_TIMER = 'urldetail:timer';
const URLDETAIL_FETCHER = 'fetch-urldetail';
const REQUESTDETAIL_VIEW = 'requestdetail:view';

// Arm a Timer hitchhike at intervalMs: >1000 throttles, 0 fires each tick.
function armTimer( timer, intervalMs ) {
	if ( intervalMs > 1000 ) {
		timer.setTimer( intervalMs );
	} else {
		timer.setTimer();
	}
}

// url_detail args from selectedUrl; tick + open fetch must match byte-for-byte.
const urlDetailArgs = ( hash ) =>
	formatCommandArgs( [ hash ], { categories: true } );

// Validation guards for command args.
const isValidHash = ( h ) => 'string' === typeof h && /^[a-f0-9]+$/.test( h );
const isValidRequestId = ( r ) =>
	'string' === typeof r && /^[a-zA-Z0-9_-]+$/.test( r );
const isValidPartition = ( p ) => Number.isInteger( p ) && p >= 0;

// Monotonic op-id: correlates an awaited reply to a pending Promise.
let nextOpId = 0;
function makeOpId() {
	nextOpId += 1;
	return `performance-op-${ Date.now() }-${ nextOpId }`;
}

// Dedup server + active chart dim into the breakdown list; pad with status.
const breakdownsFor = ( currentBreakdown ) => {
	const set = new Set( [ 'server' ] );
	if ( currentBreakdown ) {
		set.add( currentBreakdown );
	}
	if ( set.size < 2 ) {
		set.add( 'status' );
	}
	return Array.from( set );
};

// Build overview args from UI state (server + breakdown + categories).
function overviewArgs( { serverFilter, chartBreakdown } ) {
	const options = { categories: true };
	if ( serverFilter ) {
		options.server = serverFilter;
	}
	const dims = breakdownsFor( chartBreakdown );
	if ( dims.length > 0 ) {
		options.breakdown = dims.join( ',' );
	}
	return formatCommandArgs( [], options );
}

// Build urls args from UI state (sort/order/limit/offset/search/server).
function urlsArgs( { urlParams, serverFilter } ) {
	const options = {};
	if ( urlParams.sort ) {
		options.sort = urlParams.sort;
	}
	if ( urlParams.order ) {
		options.order = urlParams.order;
	}
	options.limit = 100;
	if ( urlParams.offset ) {
		options.offset = urlParams.offset;
	}
	if ( urlParams.search ) {
		options.search = urlParams.search;
	}
	if ( serverFilter ) {
		options.server = serverFilter;
	}
	return formatCommandArgs( [], options );
}

export function usePerformanceGraph( opts = {} ) {
	const {
		serverFilter = '',
		chartBreakdown = 'status',
		refreshInterval = '15000',
		requestPartition = null,
		selectedUrl = null,
		selectedRequest = null,
		urlDetailData = null,
		onError,
	} = opts;

	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live UI state the Fetcher getters + on-demand fetches read at fire time.
	const serverFilterRef = useRef( serverFilter );
	serverFilterRef.current = serverFilter;
	const chartBreakdownRef = useRef( chartBreakdown );
	chartBreakdownRef.current = chartBreakdown;
	const urlParamsRef = useRef( {
		search: '',
		sort: 'count',
		order: 'desc',
		offset: 0,
	} );
	const urlFetchTimerRef = useRef( null );

	const isPageVisible = usePageVisibility();

	// Poll cadence (ms): >1000 throttles the router TIMER; 0 every tick.
	const intervalMs = parseInt( refreshInterval, 10 ) || 0;

	// Poll graph: overview+urls on the Timer; on-demand url/request detail views.
	const { interpreterRef } = useBatchedPoll( {
		build: ( { interpreter, tee } ) => {
			addSliceFetcher( interpreter, {
				fetcher: 'fetch-overview',
				receiver: 'overviewIn',
				command: 'overview',
				view: OVERVIEW_VIEW,
				viewClass: 'OverviewView',
				tee,
				target: TARGET,
				argsFn: () =>
					overviewArgs( {
						serverFilter: serverFilterRef.current,
						chartBreakdown: chartBreakdownRef.current,
					} ),
			} );
			addSliceFetcher( interpreter, {
				fetcher: 'fetch-urls',
				receiver: 'urlsIn',
				command: 'urls',
				view: URLS_VIEW,
				viewClass: 'UrlsView',
				tee,
				target: TARGET,
				argsFn: () =>
					urlsArgs( {
						urlParams: urlParamsRef.current,
						serverFilter: serverFilterRef.current,
					} ),
			} );

			// On-demand url_detail: Tee → merge → view; merge lives on the edge.
			const urldetailIn = interpreter.makeNode( 'Tee', URLDETAIL_RECV );
			const merge = interpreter.makeNode(
				'UrlDetailMerge',
				URLDETAIL_MERGE
			);
			merge.connectNode( URLDETAIL_VIEW );
			urldetailIn.connectNode( URLDETAIL_MERGE );
			interpreter.makeNode( 'UrlDetailView', URLDETAIL_VIEW );

			// url_detail auto-refresh Timer → Fetcher; armed by the selection effect.
			const udFetcher = interpreter.makeNode(
				'Fetcher',
				URLDETAIL_FETCHER,
				`${ URLDETAIL_RECV } url_detail`
			);
			udFetcher.command_args = () =>
				urlDetailArgs( optsRef.current.selectedUrl?.hash );
			udFetcher.connectNode( TARGET );
			interpreter
				.makeNode( 'Timer', URLDETAIL_TIMER )
				.connectNode( URLDETAIL_FETCHER );

			// On-demand request_detail: its view receives replies directly.
			interpreter.makeNode( 'RequestDetailView', REQUESTDETAIL_VIEW );

			return () => {
				if ( urlFetchTimerRef.current ) {
					clearTimeout( urlFetchTimerRef.current );
				}
			};
		},
		timerName: 'perf:timer',
		teeName: 'perf:tee',
		// Suspend the offscreen overview/urls poll while any detail modal is open.
		paused: !! ( selectedUrl || selectedRequest ),
		commandClient: opts.commandClient,
		intervalMs,
	} );

	// Fire a TM_COMMAND via the interpreter; FROM = reply target, HttpOut POSTs.
	const sendCommand = useCallback(
		( verb, args, from, id, target = TARGET ) => {
			const interpreter = interpreterRef.current;
			if ( ! interpreter ) {
				return false;
			}
			const m = newMessage();
			m[ TYPE ] = TM_COMMAND;
			m[ FROM ] = from;
			m[ TO ] = target;
			if ( id ) {
				m[ ID ] = id;
			}
			m[ VALUE ] = { name: verb, arguments: args };
			interpreter.fill( m );
			return true;
		},
		[ interpreterRef ]
	);

	// Fire a TM_STRUCT control (loading/clear) directly into a view's fill.
	const sendControl = useCallback( ( viewName, value ) => {
		const view = Core.node( viewName );
		if ( ! view ) {
			return;
		}
		const m = newMessage();
		m[ TYPE ] = TM_STRUCT;
		m[ VALUE ] = value;
		view.fill( m );
	}, [] );

	// Immediate overview+urls poke with current args (on filter/breakdown change).
	const pokeOverviewUrls = useCallback( () => {
		sendControl( OVERVIEW_VIEW, { action: 'loading' } );
		sendControl( URLS_VIEW, { action: 'loading' } );
		const interpreter = interpreterRef.current;
		if ( ! interpreter ) {
			return;
		}
		const http = Core.node( HTTP );
		if ( http ) {
			http.lock();
		}
		sendCommand(
			'overview',
			overviewArgs( {
				serverFilter: serverFilterRef.current,
				chartBreakdown: chartBreakdownRef.current,
			} ),
			'overviewIn'
		);
		sendCommand(
			'urls',
			urlsArgs( {
				urlParams: urlParamsRef.current,
				serverFilter: serverFilterRef.current,
			} ),
			'urlsIn'
		);
		if ( http ) {
			http.flush();
		}
	}, [ sendCommand, sendControl, interpreterRef ] );

	// Re-poke overview+urls on filter/breakdown change (skip first run).
	const firstFilterRun = useRef( true );
	useEffect( () => {
		if ( firstFilterRun.current ) {
			firstFilterRun.current = false;
			return;
		}
		pokeOverviewUrls();
	}, [ serverFilter, chartBreakdown, pokeOverviewUrls ] );

	// Resume-refresh overview/urls when the last modal closes (timer was paused).
	const modalWasOpen = useRef( false );
	useEffect( () => {
		const modalOpen = !! ( selectedUrl || selectedRequest );
		if ( modalWasOpen.current && ! modalOpen && isPageVisible ) {
			pokeOverviewUrls();
		}
		modalWasOpen.current = modalOpen;
	}, [ selectedUrl, selectedRequest, isPageVisible, pokeOverviewUrls ] );

	// Selection-driven url_detail fetch on open; keyed on selectedUrl only.
	useEffect( () => {
		if ( ! selectedUrl ) {
			sendControl( URLDETAIL_VIEW, { action: 'clear' } );
			sendControl( URLDETAIL_MERGE, { action: 'clear' } );
			return;
		}
		if ( ! isValidHash( selectedUrl.hash ) ) {
			sendControl( URLDETAIL_VIEW, {
				action: 'error',
				error: 'Invalid URL hash format',
			} );
			return;
		}
		sendControl( URLDETAIL_VIEW, { action: 'loading' } );
		sendCommand(
			'url_detail',
			urlDetailArgs( selectedUrl.hash ),
			URLDETAIL_RECV
		);
	}, [ selectedUrl, sendCommand, sendControl ] );

	// Arm the url_detail refresh Timer only while URL detail is the visible view.
	useEffect( () => {
		const timer = Core.node( URLDETAIL_TIMER );
		if ( ! timer ) {
			return undefined;
		}
		if (
			selectedUrl &&
			isValidHash( selectedUrl.hash ) &&
			! selectedRequest &&
			isPageVisible
		) {
			armTimer( timer, intervalMs );
			return () => timer.stopTimer();
		}
		timer.stopTimer();
		return undefined;
	}, [ selectedUrl, selectedRequest, isPageVisible, intervalMs ] );

	// Selection-driven request_detail.
	useEffect( () => {
		if ( ! selectedRequest ) {
			sendControl( REQUESTDETAIL_VIEW, { action: 'clear' } );
			return;
		}
		if ( ! isValidRequestId( selectedRequest ) ) {
			sendControl( REQUESTDETAIL_VIEW, {
				action: 'error',
				error: 'Invalid request ID format',
			} );
			return;
		}
		let partition = requestPartition;
		if (
			( partition === null || partition === undefined ) &&
			urlDetailData?.requests
		) {
			const reqInfo = urlDetailData.requests.find(
				( r ) => r.rid === selectedRequest
			);
			partition = reqInfo?.partition;
		}
		if (
			partition === undefined ||
			partition === null ||
			! isValidPartition( partition )
		) {
			return;
		}
		sendControl( REQUESTDETAIL_VIEW, { action: 'loading' } );
		const options = {};
		if ( partition ) {
			options.partition = partition;
		}
		sendCommand(
			'request_detail',
			formatCommandArgs( [ selectedRequest ], options ),
			REQUESTDETAIL_VIEW
		);
	}, [
		selectedRequest,
		requestPartition,
		urlDetailData,
		sendCommand,
		sendControl,
	] );

	// Debounced URL-table fetch (search 300ms; sort/page immediate).
	const handleUrlParamsChange = useCallback(
		( params ) => {
			const prev = urlParamsRef.current;
			if (
				prev.search === params.search &&
				prev.sort === params.sort &&
				prev.order === params.order &&
				prev.offset === params.offset
			) {
				return;
			}
			const searchChanged = prev.search !== params.search;
			urlParamsRef.current = params;
			if ( urlFetchTimerRef.current ) {
				clearTimeout( urlFetchTimerRef.current );
			}
			const doFetch = () => {
				sendControl( URLS_VIEW, { action: 'loading' } );
				const http = Core.node( HTTP );
				if ( http ) {
					http.lock();
				}
				sendCommand(
					'urls',
					urlsArgs( {
						urlParams: urlParamsRef.current,
						serverFilter: serverFilterRef.current,
					} ),
					'urlsIn'
				);
				if ( http ) {
					http.flush();
				}
			};
			if ( searchChanged ) {
				urlFetchTimerRef.current = setTimeout( doFetch, 300 );
			} else {
				doFetch();
			}
		},
		[ sendCommand, sendControl ]
	);

	// resolveRequest — request_search for deep links; shared-client fallback.
	const resolveRequest = useCallback(
		async ( rid ) => {
			const view = Core.node( REQUESTDETAIL_VIEW );
			if ( interpreterRef.current && view && view.replies ) {
				const id = makeOpId();
				const promise = new Promise( ( resolve, reject ) => {
					view.replies.add( id, resolve, reject );
				} );
				const http = Core.node( HTTP );
				if ( http ) {
					http.lock();
				}
				sendCommand(
					'request_search',
					formatCommandArgs( [ rid ] ),
					REQUESTDETAIL_VIEW,
					id
				);
				if ( http ) {
					http.flush();
				}
				return promise.catch( () => null );
			}
			try {
				const client =
					optsRef.current.commandClient || getCommandClient();
				return unwrapCommandResponse(
					await client.send( {
						to: SERVER,
						verb: 'request_search',
						args: rid,
					} )
				);
			} catch ( err ) {
				return null;
			}
		},
		[ sendCommand, interpreterRef ]
	);

	// fetchUrlBreakdown — per-URL dimensional series; null on invalid hash/error.
	const fetchUrlBreakdown = useCallback(
		async ( hash, breakdown ) => {
			if ( ! isValidHash( hash ) ) {
				return null;
			}
			const view = Core.node( URLDETAIL_VIEW );
			if ( ! interpreterRef.current || ! view || ! view.replies ) {
				return null;
			}
			const id = makeOpId();
			const promise = new Promise( ( resolve, reject ) => {
				view.replies.add( id, resolve, reject );
			} );
			const http = Core.node( HTTP );
			if ( http ) {
				http.lock();
			}
			sendCommand(
				'url_detail',
				formatCommandArgs( [ hash ], { breakdown } ),
				URLDETAIL_VIEW,
				id
			);
			if ( http ) {
				http.flush();
			}
			try {
				const payload = await promise;
				return ( payload && payload.breakdown_time_series ) || null;
			} catch ( err ) {
				onError?.( err );
				return null;
			}
		},
		[ sendCommand, onError, interpreterRef ]
	);

	// Send a correlated command; the view settles it via PendingReplies.
	const awaitReply = useCallback(
		async ( viewName, verb, args, target ) => {
			const view = Core.node( viewName );
			if ( ! interpreterRef.current || ! view || ! view.replies ) {
				return null;
			}
			const id = makeOpId();
			const promise = new Promise( ( resolve, reject ) => {
				view.replies.add( id, resolve, reject );
			} );
			const http = Core.node( HTTP );
			if ( http ) {
				http.lock();
			}
			sendCommand( verb, args, viewName, id, target );
			if ( http ) {
				http.flush();
			}
			try {
				return await promise;
			} catch ( err ) {
				onError?.( err );
				return null;
			}
		},
		[ sendCommand, onError, interpreterRef ]
	);

	// listRules — current ruleset for the modal; resolves { rules }.
	const listRules = useCallback(
		() => awaitReply( URLDETAIL_VIEW, 'list', '', RULES_TARGET ),
		[ awaitReply ]
	);

	// upsertRule — replace-by-pattern/append; args is raw JSON. Resolves { rule }.
	const upsertRule = useCallback(
		( ruleObject ) =>
			awaitReply(
				URLDETAIL_VIEW,
				'upsert',
				JSON.stringify( ruleObject ),
				RULES_TARGET
			),
		[ awaitReply ]
	);

	return {
		handleUrlParamsChange,
		resolveRequest,
		fetchUrlBreakdown,
		listRules,
		upsertRule,
	};
}
