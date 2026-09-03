/**
 * usePerformanceGraph — the Performance Dashboard's data layer, expressed as a
 * node graph on the substrate batched-poll toolkit (`useBatchedPoll` +
 * `addSliceFetcher`). This hook owns the dashboard's four slices;
 * `PerformanceDashboard` reads each one back through `useNodeState` rather than
 * fetching it.
 *
 * POLLED slices. `useBatchedPoll` owns the Timer, the Tee, `_shell`/`_http` and
 * the page-visibility gate. It brackets nothing itself: the Router owns the lock
 * and flush around a tick, and that is what puts one tick into one POST:
 *
 *   perf:timer (Timer) → perf:tee (Tee) → overview:fetch, urls:fetch (Fetchers)
 *                                       → _shell/_http/performance
 *   overview:in (Tee) → overview:view (OverviewView)
 *   urls:in     (Tee) → urls:view     (UrlsView)
 *
 * Each Fetcher carries an `argsFn` fire-time getter that reads the CURRENT React
 * UI state, so a filter, sort, or page change rides the very next tick without
 * re-wiring the graph. A `serverFilter` or `chartBreakdown` change fires the
 * batched tick immediately rather than waiting out the cadence.
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
 * mints from its own receiver Tee like every other slice: minting at the view
 * would make one node both the control origin and the reply address.
 *
 * ON-DEMAND verbs are NOT here. The deep-link reads, the search box, the rules
 * writes, the grep and the chart's breakdown each live beside the state their
 * reply sets — in `PerformanceDashboard` or in `UrlDetailView` — because a
 * command held one layer above the state it feeds has to hand its answer back
 * down, and that hand-back is what a correlation table is made of.
 *
 * This hook returns `handleUrlParamsChange` and nothing else; data reaches
 * React through each slice's own `useNodeState( '<slice>:view', 'view' )`.
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import {
	Core,
	newMessage,
	TYPE,
	FROM,
	TO,
	VALUE,
	TM_STRUCT,
	formatCommandArgs,
} from '@newspack-nodes/runtime';

import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { views } from '../nodes/register';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

/**
 * The server CI mount that owns every verb this dashboard sends.
 *
 * Exported because `PerformanceDashboard` and `UrlDetailView` send their own
 * one-shot commands to the same CI through `useCommandOnce`.
 */
export const SERVER = 'performance';

/** The egress path the Fetchers and the on-demand commands target. */
const TARGET = egressPath( SERVER );

/**
 * The ruleset CI reached by the inline rule editor's `list`, `upsert` and
 * `delete`. Exported for the same reason `SERVER` is.
 */
export const RULES_CI = 'rules';

/**
 * The cadence a caller passing no `refreshInterval` polls at, and the fallback
 * for a setting `parseInt` cannot read.
 */
const DEFAULT_REFRESH_INTERVAL_MS = 15000;

/**
 * Matched-request cap `PerformanceDashboard` sends with `request_grep`. It
 * matches the verb's own default, and the server clamps anything larger to 50.
 */
export const GREP_RESULT_LIMIT = 20;

/** View node for the polled `overview` slice. */
const OVERVIEW_VIEW = 'overview:view';
/** Reply-address Tee for `overview`, and its Fetcher's FROM. */
const OVERVIEW_RECV = 'overview:in';
/** Fetcher turning each `perf:tee` tick into one `overview` command. */
const OVERVIEW_FETCHER = 'overview:fetch';
/** View node for the polled `urls` slice. */
const URLS_VIEW = 'urls:view';
/** Reply-address Tee for `urls`, and its Fetcher's FROM. */
const URLS_RECV = 'urls:in';
/** Fetcher turning each `perf:tee` tick into one `urls` command. */
const URLS_FETCHER = 'urls:fetch';
/** View node for the on-demand URL detail modal. */
const URLDETAIL_VIEW = 'urldetail:view';
/** Reply-address Tee for `url_detail`, for the Fetcher and the modal alike. */
const URLDETAIL_RECV = 'urldetail:in';
/** Merge transform on the `urldetail:in` to `urldetail:view` edge. */
const URLDETAIL_MERGE = 'urldetail:merge';
/** The URL detail slice's OWN Timer, armed only while that modal is visible. */
const URLDETAIL_TIMER = 'urldetail:timer';
/** Fetcher the URL detail auto-refresh tick fans to. */
const URLDETAIL_FETCHER = 'urldetail:fetch';
/** View node for the on-demand request detail modal. */
const REQUESTDETAIL_VIEW = 'requestdetail:view';
/** Reply-address Tee for `request_detail`; the modal's fetch mints at it. */
const REQUESTDETAIL_RECV = 'requestdetail:in';

/**
 * `url_detail` args for the open modal. The auto-refresh tick and the
 * selection fetch share this, so both ask for the same payload shape and the
 * merge node compares like with like.
 *
 * `categories` is always on: it is what adds the `category_time_series` the
 * modal's `CategoryTimeChart` draws.
 *
 * The server rides along for the same reason it rides on `urls`: this modal
 * opens from a row that filter scoped, and the two have to answer alike.
 *
 * `since` is the browser's watermark, and only the refresh tick carries one:
 * the open and rescope fetches clear the merge first, so there is nothing held
 * and the whole window is what they want.
 *
 * Named rather than positional, like its two siblings: only `hash` reaches the
 * positional token array, and a defaulted `since` in the third slot is how a
 * caller comes to pass the next argument in the wrong one.
 *
 * @param {Object} arg              Named arguments.
 * @param {string} arg.hash         The URL hash.
 * @param {string} arg.serverFilter Server scope; '' means every server.
 * @param {number} [arg.since]      Watermark (epoch seconds); 0 asks for all.
 * @return {string[]} The command token array.
 */
function urlDetailArgs( { hash, serverFilter, since = 0 } ) {
	const options = { categories: true };
	if ( serverFilter ) {
		options.server = serverFilter;
	}
	if ( since > 0 ) {
		options.since = since;
	}
	return formatCommandArgs( [ hash ], options );
}

/**
 * Whether a URL hash may go out as a `url_detail` token. A selection arrives
 * from a deep link as readily as from a clicked row, so it is user input, and
 * one that fails here drives the modal's error control instead of a command.
 *
 * @param {*} h The candidate hash.
 * @return {boolean} Whether it is a hex string.
 */
const isValidHash = ( h ) => 'string' === typeof h && /^[a-f0-9]+$/.test( h );

/**
 * Whether a request id may go out as a `request_detail` token, on the same
 * reasoning as `isValidHash`.
 *
 * @param {*} r The candidate rid.
 * @return {boolean} Whether it is alphanumeric with `_` and `-`.
 */
const isValidRequestId = ( r ) =>
	'string' === typeof r && /^[a-zA-Z0-9_-]+$/.test( r );

/**
 * Whether a partition index is well-formed enough to send. The server rejects
 * one no partition directory holds, so this guards the token's shape alone.
 *
 * @param {*} p The candidate index.
 * @return {boolean} Whether it is a non-negative integer.
 */
const isValidPartition = ( p ) => Number.isInteger( p ) && p >= 0;

/**
 * The dimension list `overview` asks for: always `server`, since the page's
 * server filter is built from that breakdown, plus the chart's active
 * dimension. A chart already showing `server` asks for that one alone.
 *
 * @param {string} currentBreakdown The chart's active dimension.
 * @return {string[]} Deduped dimension names.
 */
const breakdownsFor = ( currentBreakdown ) => {
	const set = new Set( [ 'server' ] );
	if ( currentBreakdown ) {
		set.add( currentBreakdown );
	}
	return Array.from( set );
};

/**
 * Build the `overview` args from live UI state.
 *
 * `categories` is always on: it is what adds the `category_time_series` the
 * overview card's `CategoryTimeChart` draws.
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

/**
 * Build the `urls` args from the table's own controls and the page's server
 * scope. One verb answers for the table and for the header totals summed from
 * it, so the scope reaches both or the two contradict each other.
 *
 * `limit` is fixed at 100 because `UrlTable` derives every `offset` it sends
 * from its own `URLS_PER_PAGE`; the two numbers are one page size, split across
 * two files.
 *
 * The last two options opt IN in opposite directions: `errors_only` narrows a
 * set the verb otherwise returns whole, while `include_workers` widens one the
 * verb excludes by default, because a long-running job would otherwise dominate
 * every average on the page.
 *
 * @param {Object} arg              Named arguments.
 * @param {Object} arg.urlParams    The table's live search, sort, page and
 *                                  filter state.
 * @param {string} arg.serverFilter Server scope; '' means every server.
 * @return {string[]} The command token array.
 */
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
	if ( urlParams.includeWorkers ) {
		options.include_workers = '1';
	}
	return formatCommandArgs( [], options );
}

/**
 * Mount the graph the module overview describes, and hold it in step with the
 * page: a cadence change re-arms both Timers, and the two selections arm or
 * disarm the on-demand slices.
 *
 * Every option is live UI state. The Fetchers read the filter, sort and
 * breakdown refs at FIRE time, so a change rides the next tick without
 * re-wiring the graph.
 *
 * @param {Object}  [opts]                  Live dashboard state.
 * @param {string}  [opts.serverFilter]     Server scope; '' means every
 *                                          server.
 * @param {string}  [opts.chartBreakdown]   The chart's active dimension.
 * @param {string}  [opts.refreshInterval]  Poll cadence in ms, as a STRING —
 *                                          `SelectControl` compares its option
 *                                          values as strings. A value
 *                                          `parseInt` cannot read takes
 *                                          `DEFAULT_REFRESH_INTERVAL_MS`; the
 *                                          dropdown's floor of 1000 fires on
 *                                          every router tick, and anything
 *                                          below it throws in
 *                                          `useBatchedPoll`, where only a
 *                                          Timer riding the router sits
 *                                          inside the batch bracket.
 * @param {?number} [opts.requestPartition] Partition of the selected request,
 *                                          supplied WITH the selection; null
 *                                          is reported, never reconstructed.
 * @param {?Object} [opts.selectedUrl]      `{ hash, url }` of the open URL
 *                                          detail modal; null closes and
 *                                          clears the slice.
 * @param {?string} [opts.selectedRequest]  Rid of the open request detail
 *                                          modal; null closes and clears it.
 * @return {{ handleUrlParamsChange: (params: Object) => void }} The URL table's params
 *         callback, and nothing else.
 */
export function usePerformanceGraph( opts = {} ) {
	const {
		serverFilter = '',
		chartBreakdown = 'status',
		refreshInterval = String( DEFAULT_REFRESH_INTERVAL_MS ),
		requestPartition = null,
		selectedUrl = null,
		selectedRequest = null,
	} = opts;

	// The whole opts object, for the url_detail getter's fire-time selection.
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live UI state read at fire time by the getters and on-demand fetches.
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
		includeWorkers: false,
	} );
	// Search-debounce handle; the build's cleanup clears it on teardown.
	const urlFetchTimerRef = useRef( null );

	const isPageVisible = usePageVisibility();

	// Poll cadence (ms); an unparseable setting takes the declared default.
	const intervalMs =
		parseInt( refreshInterval, 10 ) || DEFAULT_REFRESH_INTERVAL_MS;

	// The graph: overview and urls poll; the two detail views are on demand.
	const { interpreterRef, pollNow } = useBatchedPoll( {
		build: ( { interpreter, tee } ) => {
			addSliceFetcher( interpreter, {
				fetcher: OVERVIEW_FETCHER,
				receiver: OVERVIEW_RECV,
				command: 'overview',
				view: OVERVIEW_VIEW,
				viewClass: views.OverviewView,
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
				viewClass: views.UrlsView,
				controlFrom: URLS_VIEW,
				tee,
				target: TARGET,
				argsFn: () =>
					urlsArgs( {
						urlParams: urlParamsRef.current,
						serverFilter: serverFilterRef.current,
					} ),
			} );

			// @longform On-demand url_detail: an ordinary slice, on its OWN
			// Timer rather than the shared tick — the modal arms it by
			// selection. The merge rides the transform slot, so it lands on
			// the receiver→view edge.
			addSliceFetcher( interpreter, {
				fetcher: URLDETAIL_FETCHER,
				receiver: URLDETAIL_RECV,
				command: 'url_detail',
				view: URLDETAIL_VIEW,
				viewClass: views.UrlDetailView,
				controlFrom: URLDETAIL_VIEW,
				tee: interpreter.makeNode( 'Timer', URLDETAIL_TIMER ),
				target: TARGET,
				transform: {
					name: URLDETAIL_MERGE,
					nodeClass: views.UrlDetailMerge,
					controlFrom: URLDETAIL_MERGE,
				},
				// @longform
				// No hash, nothing to ask: a null return sends nothing at
				// all. The Timer is armed by default when `make_node Timer`
				// takes no interval, so a tick can reach this before the
				// effect that owns the arming has disarmed it.
				argsFn: () => {
					const hash = optsRef.current.selectedUrl?.hash;
					if ( ! isValidHash( hash ) ) {
						return null;
					}
					return urlDetailArgs( {
						hash,
						serverFilter: serverFilterRef.current,
						since: Core.node( URLDETAIL_MERGE ).watermark(),
					} );
				},
			} );

			// On-demand request_detail: Tee → view, like every other slice.
			interpreter.makeNode(
				views.RequestDetailView,
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

	/**
	 * Mint one TM_COMMAND at `from` and fill it into the interpreter, which
	 * carries it out through `_http` as a POST. Minting at the RECEIVER is what
	 * addresses the reply: FROM is the reply address, so the answer lands on
	 * that Tee and fans to the view exactly as a polled reply does.
	 *
	 * @param {string}   verb     The command verb.
	 * @param {string[]} args     Command argument tokens.
	 * @param {string}   from     Receiver node that mints it, and the address
	 *                            its reply comes back to.
	 * @param {string}   [target] Egress path; the `performance` CI by default.
	 * @return {boolean} Whether the command went. False covers an unmounted
	 *                   graph, a `from` no node answers to, and a mint with no
	 *                   session — which asks for one, leaving the next tick to
	 *                   carry the command.
	 */
	const sendCommand = useCallback(
		( verb, args, from, target = TARGET ) => {
			const interpreter = interpreterRef.current;
			if ( ! interpreter ) {
				return false;
			}
			const m = Core.node( from )?.command( verb, args ) ?? null;
			if ( null === m ) {
				return false;
			}
			m[ TO ] = target;
			interpreter.fill( m );
			return true;
		},
		[ interpreterRef ]
	);

	/**
	 * Fire a control straight into a view's `fill`, stamped with the origin
	 * that view was told to trust: a view takes its control branch on FROM,
	 * never on payload shape.
	 *
	 * A view declaring no `controlFrom` is a wiring bug, so this throws. The
	 * alternative is a FROM matching nothing, and the control then falls
	 * through to the reply branch and blanks the slice, saying nothing. A name
	 * no node answers to is a no-op instead, since every caller here names a
	 * node this hook built: the graph is torn down, and so is the slice.
	 *
	 * @param {string} viewName The view or transform node to control.
	 * @param {Object} value    The control, such as `{ action: 'loading' }`.
	 */
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

	/**
	 * Show both polled slices as loading, then fire the batched tick now rather
	 * than at the next cadence. The tick fans to both Fetchers, whose `argsFn`
	 * read the same refs, so this asks with the args the cadence would have.
	 */
	const pokeOverviewUrls = useCallback( () => {
		sendControl( OVERVIEW_VIEW, { action: 'loading' } );
		sendControl( URLS_VIEW, { action: 'loading' } );
		pollNow();
	}, [ sendControl, pollNow ] );

	// Re-poke the polled slices on a filter or breakdown change, not on mount.
	const firstFilterRun = useRef( true );
	useEffect( () => {
		if ( firstFilterRun.current ) {
			firstFilterRun.current = false;
			return;
		}
		pokeOverviewUrls();
	}, [ serverFilter, chartBreakdown, pokeOverviewUrls ] );

	// The poll pauses while a modal is open, so refresh when the last closes.
	const modalWasOpen = useRef( false );
	useEffect( () => {
		const modalOpen = !! ( selectedUrl || selectedRequest );
		if ( modalWasOpen.current && ! modalOpen && isPageVisible ) {
			pokeOverviewUrls();
		}
		modalWasOpen.current = modalOpen;
	}, [ selectedUrl, selectedRequest, isPageVisible, pokeOverviewUrls ] );

	// Selection-driven url_detail fetch on open, and on a change of scope.
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
		// @longform The merge node drops a reply whose `last_modified` matches
		// the one it holds, and that stamp is the URL's flame mtime — the same
		// for every scope. Uncleared, a rescoped reply is discarded and
		// the modal keeps the previous server's numbers.
		sendControl( URLDETAIL_MERGE, { action: 'clear' } );
		sendCommand(
			'url_detail',
			urlDetailArgs( { hash: selectedUrl.hash, serverFilter } ),
			URLDETAIL_RECV
		);
	}, [ selectedUrl, serverFilter, sendCommand, sendControl ] );

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
		// resolver and a clicked row both supply it. Reconstructing it here
		// from a page of RECENT requests answers nothing for an older rid and
		// returns before even the loading state, leaving the modal to render
		// neither section.
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
		// 0 is the verb's default, and the option is a hint, not a filter.
		if ( partition ) {
			options.partition = partition;
		}
		sendCommand(
			'request_detail',
			formatCommandArgs( [ selectedRequest ], options ),
			REQUESTDETAIL_RECV
		);
	}, [ selectedRequest, requestPartition, sendCommand, sendControl ] );

	/**
	 * Fetch the page of the URL table these params describe. Params matching
	 * the ones in hand fetch nothing, a changed search waits out 300ms, and
	 * every other change fetches at once: a sort or a page turn is one
	 * deliberate click, while a search is a keystroke per character.
	 *
	 * @param {Object}  params                The table's live controls.
	 * @param {string}  params.search         Substring filter; '' matches all.
	 * @param {string}  params.sort           Sort field.
	 * @param {string}  params.order          Sort direction.
	 * @param {number}  params.offset         First row of the page.
	 * @param {boolean} params.errorsOnly     Narrow to erroring URLs.
	 * @param {boolean} params.includeWorkers Include worker traffic.
	 */
	const handleUrlParamsChange = useCallback(
		( params ) => {
			const prev = urlParamsRef.current;
			if (
				prev.search === params.search &&
				prev.sort === params.sort &&
				prev.order === params.order &&
				prev.offset === params.offset &&
				!! prev.errorsOnly === !! params.errorsOnly &&
				!! prev.includeWorkers === !! params.includeWorkers
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

	return { handleUrlParamsChange };
}
