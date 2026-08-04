/**
 * usePerformanceGraph — the Performance Dashboard's data layer, expressed as a
 * node graph on the substrate batched-poll toolkit (`useBatchedPoll` +
 * `addSliceFetcher`). This hook owns every fetch the dashboard makes;
 * `PerformanceDashboard` reads each slice back through `useNodeState` and fetches
 * nothing itself.
 *
 * POLLED slices. `useBatchedPoll` owns the Timer, the Tee, `_shell`/`_http`, the
 * lock/flush bracket that puts one tick into one POST, and the page-visibility
 * gate:
 *
 *   perf:timer (Timer) → perf:tee (Tee) → fetch-overview, fetch-urls (Fetchers)
 *                                       → _shell/_http/performance
 *   overviewIn (Tee) → overview:view (OverviewView)
 *   urlsIn     (Tee) → urls:view     (UrlsView)
 *
 * Each Fetcher carries an `argsFn` fire-time getter that reads the CURRENT React
 * UI state, so a filter, sort, or page change rides the very next tick without
 * re-wiring the graph. A `serverFilter` or `chartBreakdown` change also pokes an
 * immediate out-of-band fetch instead of waiting out the tick.
 *
 * ON-DEMAND slices. Opening a modal fetches; neither slice hangs off `perf:timer`,
 * and the overview/urls poll pauses while either modal is open:
 *
 *   urldetailIn (Tee) → urldetail:merge (UrlDetailMerge) → urldetail:view (UrlDetailView)
 *   urldetail:timer (Timer) → fetch-urldetail (Fetcher) → _shell/_http/performance
 *   requestdetail:view (RequestDetailView)
 *
 * The url_detail reply rides through `UrlDetailMergeNode` on the receiver → view
 * edge: it merges each reply into the last one (dedup by rid, newest first, 500
 * rows) and DROPS a reply whose `last_modified` is unchanged, so an auto-refresh
 * tick never re-renders the modal for nothing. `urldetail:timer` is armed only
 * while URL detail is the visible modal and the tab is visible. `request_detail`
 * needs no receiver Tee — its command is minted FROM the view node, so the reply
 * lands there.
 *
 * AWAITED verbs — `resolveRequest`, `resolveUrlHash`, `fetchUrlBreakdown`, the
 * three `rules` verbs, and `requestGrep` — each go out through their OWN `Request`
 * node (`useRequestNode`). A node holding one in-flight command cannot mistake
 * whose reply arrived, so the addressing IS the correlation: no op-id, no table of
 * pending replies.
 *
 * The hook returns control callbacks only — `handleUrlParamsChange`,
 * `resolveRequest`, `resolveUrlHash`, `fetchUrlBreakdown`, `listRules`,
 * `upsertRule`, `removeRule`, `requestGrep`. Data reaches React through each
 * slice's own `useNodeState( '<slice>:view', 'view' )`. The command boundary is
 * injectable via `opts.commandClient`.
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import {
	Core,
	newMessage,
	TYPE,
	TO,
	ID,
	VALUE,
	TM_STRUCT,
	formatCommandArgs,
} from '@newspack-nodes/runtime';

import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import useRequestNode from '@newspack-nodes/shared/hooks/useRequestNode';
import '../nodes/register';

// The server CI mount + the egress path the Fetchers/on-demand commands target.
const SERVER = 'performance';
const TARGET = `_shell/_http/${ SERVER }`;
// The declared poll cadence; also the fallback for an unparseable setting.
const DEFAULT_REFRESH_INTERVAL_MS = 15000;

// Default matched-request cap for requestGrep (server clamps to its own max).
const GREP_RESULT_LIMIT = 20;

// Slice view + receiver names.
const OVERVIEW_VIEW = 'overview:view';
const URLS_VIEW = 'urls:view';
const URLDETAIL_VIEW = 'urldetail:view';
const URLDETAIL_RECV = 'urldetailIn';
const URLDETAIL_MERGE = 'urldetail:merge';
const URLDETAIL_TIMER = 'urldetail:timer';
const URLDETAIL_FETCHER = 'fetch-urldetail';
const REQUESTDETAIL_VIEW = 'requestdetail:view';

/**
 * `url_detail` args for the open modal. The auto-refresh tick and the
 * selection fetch share this, so both ask for the same payload shape and the
 * merge node compares like with like.
 *
 * @param {string} hash The URL hash.
 * @return {string[]} The command token array.
 */
const urlDetailArgs = ( hash ) =>
	formatCommandArgs( [ hash ], { categories: true } );

/**
 * `url_detail` args for a bare hash → URL lookup. Deliberately NOT
 * `urlDetailArgs`: the resolver reads one string out of the reply, and
 * selecting the URL refetches with the categories anyway.
 *
 * @param {string} hash The URL hash.
 * @return {string[]} The command token array.
 */
const urlLookupArgs = ( hash ) => formatCommandArgs( [ hash ] );

// Validation guards for command args.
const isValidHash = ( h ) => 'string' === typeof h && /^[a-f0-9]+$/.test( h );
const isValidRequestId = ( r ) =>
	'string' === typeof r && /^[a-zA-Z0-9_-]+$/.test( r );
const isValidPartition = ( p ) => Number.isInteger( p ) && p >= 0;

/**
 * The dimension list `overview` asks for: always `server`, since the page's
 * server filter is built from that breakdown, plus the chart's active
 * dimension. A chart already on `server` leaves one dimension, so `status`
 * pads it back to two.
 *
 * @param {string} currentBreakdown The chart's active dimension.
 * @return {string[]} Deduped dimension names.
 */
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

/**
 * Build the `overview` args from live UI state.
 *
 * @param {Object} ui                UI state, read at fire time.
 * @param {string} ui.serverFilter   Server scope; '' means every server.
 * @param {string} ui.chartBreakdown The chart's active dimension.
 * @return {string[]} The command token array.
 */
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

/**
 * Every option is live UI state: the Fetchers read the filter/sort/breakdown
 * refs at FIRE time, so a change rides the next tick without re-wiring, and the
 * two selections arm or disarm the on-demand slices.
 *
 * The awaited callbacks never reject. `fetchUrlBreakdown` and the three rule
 * verbs report through `onError` and resolve null; `resolveRequest` and
 * `resolveUrlHash` swallow the failure entirely and resolve null, because a
 * deep link that cannot be resolved is not the user's error to see.
 *
 * @param {Object}               [opts]                  Live dashboard state + seams.
 * @param {string}               [opts.serverFilter]     Server scope; '' means every
 *                                                       server.
 * @param {string}               [opts.chartBreakdown]   The chart's active dimension.
 * @param {string}               [opts.refreshInterval]  Poll cadence in ms, as a STRING
 *                                                       (it comes straight off a select).
 *                                                       Anything at or below 1000 — 0
 *                                                       included, which is what an
 *                                                       unparseable value becomes — fires
 *                                                       on every router tick.
 * @param {?number}              [opts.requestPartition] Partition of the selected request;
 *                                                       null falls back to looking the rid
 *                                                       up in `urlDetailData.requests`.
 * @param {?Object}              [opts.selectedUrl]      `{ hash, url }` of the open URL
 *                                                       detail modal; null closes and
 *                                                       clears the slice.
 * @param {?string}              [opts.selectedRequest]  Rid of the open request detail
 *                                                       modal; null closes and clears it.
 * @param {?Object}              [opts.urlDetailData]    The url_detail slice React holds,
 *                                                       read only for its `requests` rows.
 * @param {(err: Error) => void} [opts.onError]          Receives a failed awaited verb.
 * @param {Object}               [opts.commandClient]    Transport seam handed to
 *                                                       `useBatchedPoll`; production lets
 *                                                       HttpOut default it.
 * @return {{ handleUrlParamsChange: (params: Object) => void,
 *   resolveRequest: (rid: string) => Promise<Object|null>,
 *   resolveUrlHash: (hash: string) => Promise<{url: string}|null>,
 *   fetchUrlBreakdown: (hash: string, breakdown: string) => Promise<Object|null>,
 *   listRules: () => Promise<Object|null>,
 *   upsertRule: (rule: Object) => Promise<Object|null>,
 *   removeRule: (id: string) => Promise<Object|null>,
 *   requestGrep: (pattern: string, limit?: number) => Promise<Object|null> }}
 *   Control callbacks ONLY — no data. Every slice reaches React through its own
 *   `useNodeState( '<slice>:view', 'view' )`. `handleUrlParamsChange` takes the
 *   URL table's `{ search, sort, order, offset }` and debounces a search change
 *   by 300ms while sending sort/page changes immediately.
 */
export function usePerformanceGraph( opts = {} ) {
	const {
		serverFilter = '',
		chartBreakdown = 'status',
		refreshInterval = String( DEFAULT_REFRESH_INTERVAL_MS ),
		requestPartition = null,
		selectedUrl = null,
		selectedRequest = null,
		urlDetailData = null,
		onError,
	} = opts;

	const optsRef = useRef( opts );
	optsRef.current = opts;

	// One node per awaited verb; each reply is addressed back to it.
	const requestSearch = useRequestNode(
		`${ SERVER }:request_search`,
		SERVER
	);
	const urlDetail = useRequestNode( `${ SERVER }:url_detail`, SERVER );
	const grep = useRequestNode( `${ SERVER }:request_grep`, SERVER );
	const rulesList = useRequestNode( 'rules:list', 'rules' );
	const rulesUpsert = useRequestNode( 'rules:upsert', 'rules' );
	const rulesDelete = useRequestNode( 'rules:delete', 'rules' );

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

	// Poll cadence (ms); an unparseable setting takes the declared default.
	const intervalMs =
		parseInt( refreshInterval, 10 ) || DEFAULT_REFRESH_INTERVAL_MS;

	// Poll graph: overview+urls on Timer; on-demand url/request detail views.
	const { interpreterRef, pollNow } = useBatchedPoll( {
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

			// On-demand url_detail: Tee → merge → view; merge lives on edge.
			const urldetailIn = interpreter.makeNode( 'Tee', URLDETAIL_RECV );
			const merge = interpreter.makeNode(
				'UrlDetailMerge',
				URLDETAIL_MERGE
			);
			merge.connectNode( URLDETAIL_VIEW );
			urldetailIn.connectNode( URLDETAIL_MERGE );
			interpreter.makeNode( 'UrlDetailView', URLDETAIL_VIEW );

			// url_detail auto-refresh Timer → Fetcher; armed by selection.
			const udFetcher = interpreter.makeNode(
				'Fetcher',
				URLDETAIL_FETCHER,
				[ URLDETAIL_RECV, 'url_detail' ]
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
		// Suspend offscreen overview/urls poll while any detail modal is open.
		paused: !! ( selectedUrl || selectedRequest ),
		commandClient: opts.commandClient,
		intervalMs,
	} );

	// Fire a TM_COMMAND via interpreter; FROM = reply target, HttpOut POSTs.
	const sendCommand = useCallback(
		( verb, args, from, id, target = TARGET ) => {
			const interpreter = interpreterRef.current;
			if ( ! interpreter ) {
				return false;
			}
			// The receiver mints; null = unauthenticated (asks for a session).
			const m = Core.node( from )?.command( verb, args ) ?? null;
			if ( null === m ) {
				return false;
			}
			m[ TO ] = target;
			if ( id ) {
				m[ ID ] = id;
			}
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

	// Immediate overview+urls poke with current args (filter/breakdown change).
	const pokeOverviewUrls = useCallback( () => {
		sendControl( OVERVIEW_VIEW, { action: 'loading' } );
		sendControl( URLS_VIEW, { action: 'loading' } );
		// The tick fans to both slices, whose argsFn read these same refs.
		pollNow();
	}, [ sendControl, pollNow ] );

	// Re-poke overview+urls on filter/breakdown change (skip first run).
	const firstFilterRun = useRef( true );
	useEffect( () => {
		if ( firstFilterRun.current ) {
			firstFilterRun.current = false;
			return;
		}
		pokeOverviewUrls();
	}, [ serverFilter, chartBreakdown, pokeOverviewUrls ] );

	// Resume-refresh overview/urls when last modal closes (timer was paused).
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

	// Arm url_detail refresh Timer only while URL detail is the visible view.
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
			timer.setTimer( intervalMs );
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
			// One command: a lock/flush bracket would coalesce nothing.
			const doFetch = () => {
				sendControl( URLS_VIEW, { action: 'loading' } );
				sendCommand(
					'urls',
					urlsArgs( {
						urlParams: urlParamsRef.current,
						serverFilter: serverFilterRef.current,
					} ),
					'urlsIn'
				);
			};
			if ( searchChanged ) {
				urlFetchTimerRef.current = setTimeout( doFetch, 300 );
			} else {
				doFetch();
			}
		},
		[ sendCommand, sendControl ]
	);

	// resolveRequest — request_search for deep links.
	const resolveRequest = useCallback(
		async ( rid ) => {
			try {
				return await requestSearch(
					'request_search',
					formatCommandArgs( [ rid ] )
				);
			} catch ( err ) {
				return null;
			}
		},
		[ requestSearch ]
	);

	// resolveUrlHash — url_detail for a ?url= hash outside the loaded page.
	const resolveUrlHash = useCallback(
		async ( hash ) => {
			if ( ! isValidHash( hash ) ) {
				return null;
			}
			try {
				const payload = await urlDetail(
					'url_detail',
					urlLookupArgs( hash )
				);
				// A reply settles the intent, even one that names no URL.
				return { url: payload?.stats?.url || '' };
			} catch ( err ) {
				// A TM_ERROR is a reply too, and a final one.
				return err?.fromServer ? { url: '' } : null;
			}
		},
		[ urlDetail ]
	);

	// fetchUrlBreakdown — per-URL dimensional series; null on bad hash/error.
	const fetchUrlBreakdown = useCallback(
		async ( hash, breakdown ) => {
			if ( ! isValidHash( hash ) ) {
				return null;
			}
			try {
				const payload = await urlDetail(
					'url_detail',
					formatCommandArgs( [ hash ], { breakdown } )
				);
				return ( payload && payload.breakdown_time_series ) || null;
			} catch ( err ) {
				onError?.( err );
				return null;
			}
		},
		[ urlDetail, onError ]
	);

	// Await one verb through its own node; a failure is reported, not thrown.
	const awaitReply = useCallback(
		async ( request, verb, args ) => {
			try {
				return await request( verb, args );
			} catch ( err ) {
				onError?.( err );
				return null;
			}
		},
		[ onError ]
	);

	// listRules — current ruleset for the modal; resolves { rules }.
	const listRules = useCallback(
		() => awaitReply( rulesList, 'list', [] ),
		[ awaitReply, rulesList ]
	);

	// upsertRule: whole raw JSON is one arg token (CI json_decodes $args[0]).
	const upsertRule = useCallback(
		( ruleObject ) =>
			awaitReply( rulesUpsert, 'upsert', [
				JSON.stringify( ruleObject ),
			] ),
		[ awaitReply, rulesUpsert ]
	);

	// removeRule — delete by rule id; resolves the CI's { deleted } reply.
	const removeRule = useCallback(
		( id ) => awaitReply( rulesDelete, 'delete', [ id ] ),
		[ awaitReply, rulesDelete ]
	);

	// requestGrep: pattern-search recent firehose; resolves the grep summary.
	const requestGrep = useCallback(
		( pattern, limit = GREP_RESULT_LIMIT ) =>
			awaitReply(
				grep,
				'request_grep',
				formatCommandArgs( [ pattern ], { limit } )
			),
		[ awaitReply, grep ]
	);

	return {
		handleUrlParamsChange,
		resolveRequest,
		resolveUrlHash,
		fetchUrlBreakdown,
		listRules,
		upsertRule,
		removeRule,
		requestGrep,
	};
}
