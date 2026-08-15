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
 *   perf:timer (Timer) → perf:tee (Tee) → overview:fetch, urls:fetch (Fetchers)
 *                                       → _shell/_http/performance
 *   overview:in (Tee) → overview:view (OverviewView)
 *   urls:in     (Tee) → urls:view     (UrlsView)
 *
 * Each Fetcher carries an `argsFn` fire-time getter that reads the CURRENT React
 * UI state, so a filter, sort, or page change rides the very next tick without
 * re-wiring the graph. A `serverFilter` or `chartBreakdown` change also pokes an
 * immediate out-of-band fetch instead of waiting out the tick.
 *
 * ON-DEMAND slices. Opening a modal fetches; neither slice hangs off `perf:timer`,
 * and the overview/urls poll pauses while either modal is open:
 *
 *   urldetail:in (Tee) → urldetail:merge (UrlDetailMerge) → urldetail:view (UrlDetailView)
 *   urldetail:timer (Timer) → urldetail:fetch (Fetcher) → _shell/_http/performance
 *   requestdetail:in (Tee) → requestdetail:view (RequestDetailView)
 *
 * The url_detail reply rides through `UrlDetailMergeNode` on the receiver → view
 * edge: it merges each reply into the last one (dedup by rid, newest first, 500
 * rows) and DROPS a reply whose `last_modified` is unchanged, so an auto-refresh
 * tick never re-renders the modal for nothing. `urldetail:timer` is armed only
 * while URL detail is the visible modal and the tab is visible. `request_detail`
 * mints from its own receiver Tee like every other slice: a view that is also the
 * reply address carries two protocols at once.
 *
 * AWAITED verbs — `resolveRequest`, `resolveUrlHash`, `fetchUrlBreakdown`, the
 * three `rules` verbs, and `requestGrep` — each go out through their OWN node
 * (`useAwaitableCommand`), on the same batched tick as the polls. A node
 * holding one in-flight command cannot mistake whose reply arrived, so the
 * addressing IS the correlation: no op-id, no table of pending replies.
 *
 * The hook returns control callbacks only — `handleUrlParamsChange`,
 * `resolveRequest`, `resolveUrlHash`, `fetchUrlBreakdown`, `listRules`,
 * `upsertRule`, `removeRule`, `requestGrep`. Data reaches React through each
 * slice's own `useNodeState( '<slice>:view', 'view' )`. The command boundary is
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import {
	Core,
	newMessage,
	TYPE,
	FROM,
	TO,
	ID,
	VALUE,
	TM_STRUCT,
	formatCommandArgs,
} from '@newspack-nodes/runtime';

import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { useAwaitableCommand } from '@newspack-nodes/shared/hooks/useAwaitableCommand';
import '../nodes/register';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

// The server CI mount + the egress path the Fetchers/on-demand commands target.
const SERVER = 'performance';
const TARGET = egressPath( SERVER );

// The ruleset CI the two rule-editing verbs reach.
const RULES_CI = 'rules';
// The declared poll cadence; also the fallback for an unparseable setting.
const DEFAULT_REFRESH_INTERVAL_MS = 15000;

// Default matched-request cap for requestGrep (server clamps to its own max).
const GREP_RESULT_LIMIT = 20;

// Slice view + receiver names.
const OVERVIEW_VIEW = 'overview:view';
const OVERVIEW_RECV = 'overview:in';
const OVERVIEW_FETCHER = 'overview:fetch';
const URLS_VIEW = 'urls:view';
const URLS_RECV = 'urls:in';
const URLS_FETCHER = 'urls:fetch';
const URLDETAIL_VIEW = 'urldetail:view';
const URLDETAIL_RECV = 'urldetail:in';
const URLDETAIL_MERGE = 'urldetail:merge';
const URLDETAIL_TIMER = 'urldetail:timer';
const URLDETAIL_FETCHER = 'urldetail:fetch';
const REQUESTDETAIL_VIEW = 'requestdetail:view';
const REQUESTDETAIL_RECV = 'requestdetail:in';

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

// Build urls args; the server is the sole filter/sort/page authority.
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
	if ( urlParams.errorsOnly ) {
		options.errors_only = '1';
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
 * @param {?number}              [opts.requestPartition] Partition of the selected request,
 *                                                       supplied WITH the selection; null
 *                                                       is reported, never reconstructed.
 * @param {?Object}              [opts.selectedUrl]      `{ hash, url }` of the open URL
 *                                                       detail modal; null closes and
 *                                                       clears the slice.
 * @param {?string}              [opts.selectedRequest]  Rid of the open request detail
 *                                                       modal; null closes and clears it.
 * @param {(err: Error) => void} [opts.onError]          Receives a failed awaited verb.
 */
export function usePerformanceGraph( opts = {} ) {
	const {
		serverFilter = '',
		chartBreakdown = 'status',
		refreshInterval = String( DEFAULT_REFRESH_INTERVAL_MS ),
		requestPartition = null,
		selectedUrl = null,
		selectedRequest = null,
		onError,
	} = opts;

	const optsRef = useRef( opts );
	optsRef.current = opts;

	// One node per awaited verb, each riding the same tick as the polls.
	const requestSearch = useAwaitableCommand( {
		ci: SERVER,
		command: 'request_search',
	} );
	// Its own scope: the poll owns `<SERVER>:url_detail`.
	const urlDetail = useAwaitableCommand( {
		ci: SERVER,
		command: 'url_detail',
		scope: `${ SERVER }:url_detail:lookup`,
	} );
	const grep = useAwaitableCommand( {
		ci: SERVER,
		command: 'request_grep',
	} );
	const rulesList = useAwaitableCommand( { ci: RULES_CI, command: 'list' } );
	const rulesUpsert = useAwaitableCommand( {
		ci: RULES_CI,
		command: 'upsert',
	} );
	const rulesDelete = useAwaitableCommand( {
		ci: RULES_CI,
		command: 'delete',
	} );

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
		errorsOnly: false,
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
				fetcher: OVERVIEW_FETCHER,
				receiver: OVERVIEW_RECV,
				command: 'overview',
				view: OVERVIEW_VIEW,
				viewClass: 'OverviewView',
				controlFrom: OVERVIEW_VIEW,
				tee,
				target: TARGET,
				argsFn: () =>
					overviewArgs( {
						serverFilter: serverFilterRef.current,
						chartBreakdown: chartBreakdownRef.current,
					} ),
			} );
			addSliceFetcher( interpreter, {
				fetcher: URLS_FETCHER,
				receiver: URLS_RECV,
				command: 'urls',
				view: URLS_VIEW,
				viewClass: 'UrlsView',
				controlFrom: URLS_VIEW,
				tee,
				target: TARGET,
				argsFn: () =>
					urlsArgs( {
						urlParams: urlParamsRef.current,
						serverFilter: serverFilterRef.current,
					} ),
			} );

			// On-demand url_detail: Tee → merge → view; merge lives on edge.
			const urlDetailRecv = interpreter.makeNode( 'Tee', URLDETAIL_RECV );
			const merge = interpreter.makeNode(
				'UrlDetailMerge',
				URLDETAIL_MERGE
			);
			merge.controlFrom = URLDETAIL_MERGE;
			merge.connectNode( URLDETAIL_VIEW );
			urlDetailRecv.connectNode( URLDETAIL_MERGE );
			interpreter.makeNode(
				'UrlDetailView',
				URLDETAIL_VIEW
			).controlFrom = URLDETAIL_VIEW;

			// url_detail auto-refresh Timer → Fetcher; armed by selection.
			const udFetcher = interpreter.makeNode(
				'Fetcher',
				URLDETAIL_FETCHER,
				[ URLDETAIL_RECV, 'url_detail' ]
			);
			// @longform
			// No hash, nothing to ask: a null return sends nothing at all.
			// The Timer is armed by default when `make_node Timer` takes no
			// interval, so a tick can reach this before the effect that owns
			// the arming has disarmed it.
			udFetcher.command_args = () => {
				const hash = optsRef.current.selectedUrl?.hash;
				return isValidHash( hash ) ? urlDetailArgs( hash ) : null;
			};
			udFetcher.connectNode( TARGET );
			interpreter
				.makeNode( 'Timer', URLDETAIL_TIMER )
				.connectNode( URLDETAIL_FETCHER );

			// On-demand request_detail: Tee → view, like every other slice.
			interpreter.makeNode(
				'RequestDetailView',
				REQUESTDETAIL_VIEW
			).controlFrom = REQUESTDETAIL_VIEW;
			interpreter
				.makeNode( 'Tee', REQUESTDETAIL_RECV )
				.connectNode( REQUESTDETAIL_VIEW );

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

	// @longform Fire a control into a view's fill, stamped with the origin
	// that view was told to trust — it applies on FROM, never on payload
	// shape. A view with no controlFrom is a wiring bug that throws here:
	// the alternative is a FROM matching nothing, so the control falls
	// through to the reply branch and blanks the slice, saying nothing.
	const sendControl = useCallback( ( viewName, value ) => {
		const view = Core.node( viewName );
		if ( ! view ) {
			return;
		}
		if ( ! view.controlFrom ) {
			throw new Error( `${ viewName } declares no controlFrom` );
		}
		const m = newMessage();
		m[ TYPE ] = TM_STRUCT;
		m[ FROM ] = view.controlFrom;
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
		// @longform The partition arrives WITH the selection — the deep-link
		// resolver and a clicked row both supply it. It was also reconstructed
		// here from `urlDetailData.requests`, a page of RECENT requests, which
		// silently answered nothing for an older rid and returned before even
		// the loading state: the modal then rendered neither section.
		const partition = requestPartition;
		if (
			partition === undefined ||
			partition === null ||
			! isValidPartition( partition )
		) {
			sendControl( REQUESTDETAIL_VIEW, {
				action: 'error',
				error: 'Could not determine the partition for this request',
			} );
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
			REQUESTDETAIL_RECV
		);
	}, [ selectedRequest, requestPartition, sendCommand, sendControl ] );

	// Debounced URL-table fetch (search 300ms; sort/page immediate).
	const handleUrlParamsChange = useCallback(
		( params ) => {
			const prev = urlParamsRef.current;
			if (
				prev.search === params.search &&
				prev.sort === params.sort &&
				prev.order === params.order &&
				prev.offset === params.offset &&
				!! prev.errorsOnly === !! params.errorsOnly
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
					URLS_RECV
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
				return await requestSearch( formatCommandArgs( [ rid ] ) );
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
				const payload = await urlDetail( urlLookupArgs( hash ) );
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
		async ( send, args ) => {
			try {
				return await send( args );
			} catch ( err ) {
				onError?.( err );
				return null;
			}
		},
		[ onError ]
	);

	// listRules — current ruleset for the modal; resolves { rules }.
	const listRules = useCallback(
		() => awaitReply( rulesList, [] ),
		[ awaitReply, rulesList ]
	);

	// upsertRule: whole raw JSON is one arg token (CI json_decodes $args[0]).
	const upsertRule = useCallback(
		( ruleObject ) =>
			awaitReply( rulesUpsert, [ JSON.stringify( ruleObject ) ] ),
		[ awaitReply, rulesUpsert ]
	);

	// removeRule — delete by rule id; resolves the CI's { deleted } reply.
	const removeRule = useCallback(
		( id ) => awaitReply( rulesDelete, [ id ] ),
		[ awaitReply, rulesDelete ]
	);

	// requestGrep: pattern-search recent firehose; resolves the grep summary.
	const requestGrep = useCallback(
		( pattern, limit = GREP_RESULT_LIMIT ) =>
			awaitReply( grep, formatCommandArgs( [ pattern ], { limit } ) ),
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
