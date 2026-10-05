/**
 * usePerformanceGraph — the Performance Dashboard's data layer, expressed as a
 * node graph on the substrate batched-poll toolkit (`useBatchedPoll` +
 * `addSliceFetcher`). This hook owns the dashboard's four slices;
 * `PerformanceDashboard` reads each one back through `useNodeField` rather than
 * fetching it.
 *
 * POLLED slices. `useBatchedPoll` owns the Timer, the Tee, `_shell`/`_http` and
 * the page-visibility gate. It brackets nothing itself: the Router owns the lock
 * and flush around a tick, and that is what puts one tick into one POST:
 *
 *   performance:timer (Timer) → performance:tee (Tee) → overview:fetch, urls:fetch (Fetchers)
 *                                       → _shell/_http/performance
 *   overview:in (Tee) → overview:in:current (Current) → overview:view (OverviewView)
 *   urls:in     (Tee) → urls:in:current (Current) → urls:view (UrlsView)
 *
 * Each Fetcher carries an `argsFn` fire-time getter that reads the CURRENT React
 * UI state, so a filter, sort, or page change rides the very next tick without
 * re-wiring the graph. A `serverFilter` or `chartBreakdown` change asks both
 * afresh, superseding the asks in flight, and fires the batched tick at once
 * rather than waiting out the cadence.
 *
 * ON-DEMAND slices. Opening a modal fetches; neither slice hangs off `performance:timer`,
 * and the overview/urls poll pauses while either modal is open:
 *
 *   url-detail:in (Tee) → url-detail:in:current (Current)
 *                       → url-detail:transform (UrlDetailMerge) → url-detail:view (UrlDetailView)
 *   url-detail:timer (Timer) → url-detail:fetch (Fetcher) → _shell/_http/performance
 *   request-detail:in (Tee) → request-detail:in:current (Current) → request-detail:view (RequestDetailView)
 *   request-detail:timer (Timer) → request-detail:fetch (Fetcher) → _shell/_http/performance
 *
 * The dump_url reply rides through `UrlDetailMergeNode` on the gate → view
 * edge: it merges each reply into the last one (dedup by rid, newest completion
 * first, 500 rows) and DROPS a reply carrying no request it lacks under an
 * unchanged `last_modified`, so an auto-refresh tick never re-renders the modal
 * for nothing. Opening the modal, rescoping it or flipping Errors Only asks
 * through that same Fetcher with `askNow()`, superseding every older ask,
 * exactly as a `urls` sort change does — never by minting at the receiver
 * beside it. Every slice's `Current` gate, the substrate's, drops the answer
 * to a superseded question before it reaches the merge or a view, and sends
 * a refusal to the view itself. `url-detail:timer` is armed only while URL
 * detail is the visible modal and the tab is visible. A request selection
 * asks `dump_request` through `request-detail:fetch` the same way, so the
 * late answer about the request the operator left never lands.
 *
 * ON-DEMAND verbs are NOT here. The deep-link reads, the search box, the rules
 * writes, the grep and the chart's breakdown each live beside the state their
 * reply sets — in `PerformanceDashboard` or in `UrlDetailView` — because a
 * command held one layer above the state it feeds has to hand its answer back
 * down, and that hand-back is what a correlation table is made of.
 *
 * This hook returns `handleUrlParamsChange` and nothing else; data reaches
 * React through each slice's own `useNodeField( '<slice>:view', 'view' )`.
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import { Core, formatCommandArgs } from '@newspack-nodes/runtime';

import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';
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
 * The ruleset CI reached by the inline rule editor's `dump`, `upsert` and
 * `delete`. Exported for the same reason `SERVER` is.
 */
export const RULES_CI = 'rules';

/**
 * The cadence a caller passing no `refreshInterval` polls at, and the fallback
 * for a setting `parseInt` cannot read.
 */
const DEFAULT_REFRESH_INTERVAL_MS = 15000;

/**
 * Matched-request cap `PerformanceDashboard` sends with `grep_requests`. It
 * matches the verb's own default, and the server clamps anything larger to 50.
 */
export const GREP_RESULT_LIMIT = 20;

/** View node for the polled `overview` slice. */
const OVERVIEW_VIEW = 'overview:view';
/** Reply-address Tee for `overview`, and its Fetcher's FROM. */
const OVERVIEW_RECV = 'overview:in';
/** Fetcher turning each `performance:tee` tick into one `overview` command. */
const OVERVIEW_FETCHER = 'overview:fetch';
/** View node for the polled `urls` slice. */
const URLS_VIEW = 'urls:view';
/** Reply-address Tee for `urls`, and its Fetcher's FROM. */
const URLS_RECV = 'urls:in';
/** Fetcher turning each `performance:tee` tick into one `urls` command. */
const URLS_FETCHER = 'urls:fetch';
/** View node for the on-demand URL detail modal. */
const URLDETAIL_VIEW = 'url-detail:view';
/** Reply-address Tee for `dump_url`, and its Fetcher's FROM. */
const URLDETAIL_RECV = 'url-detail:in';
/** Merge transform on the `url-detail:in` to `url-detail:view` edge. */
const URLDETAIL_TRANSFORM = 'url-detail:transform';
/** The URL detail slice's OWN Timer, armed only while that modal is visible. */
const URLDETAIL_TIMER = 'url-detail:timer';
/** Fetcher the URL detail auto-refresh tick fans to. */
const URLDETAIL_FETCHER = 'url-detail:fetch';
/** View node for the on-demand request detail modal. */
const REQUESTDETAIL_VIEW = 'request-detail:view';
/** Reply-address Tee for `dump_request`, and its Fetcher's FROM. */
const REQUESTDETAIL_RECV = 'request-detail:in';
/** Fetcher a selection asks `dump_request` through. */
const REQUESTDETAIL_FETCHER = 'request-detail:fetch';
/** The request modal's OWN Timer, armed only while that modal is visible. */
const REQUESTDETAIL_TIMER = 'request-detail:timer';
/**
 * Every Router tick: an ask parked before a session existed goes out on the
 * first tick after one does, and an unanswered one is asked again.
 */
const REQUESTDETAIL_TICK_MS = 1000;

/**
 * `dump_url` args for the open modal. The auto-refresh tick and the
 * selection fetch share this, so both ask for the same payload shape and the
 * merge node compares like with like.
 *
 * `categories` is always on: it is what adds the `category_time_series` the
 * modal's `CategoryTimeChart` draws.
 *
 * The server rides along for the same reason it rides on `urls`: this modal
 * opens from a row that filter scoped, and the two have to answer alike.
 *
 * `after` is the browser's cursor, and only the refresh tick carries one: the
 * open and rescope asks clear the merge first, so there is nothing held and
 * the whole window is what they want.
 *
 * Named rather than positional, like its two siblings: only `hash` reaches the
 * positional token array, and a defaulted `after` in the third slot is how a
 * caller comes to pass the next argument in the wrong one.
 *
 * @param {Object}      arg              Named arguments.
 * @param {string}      arg.hash         The URL hash.
 * @param {string}      arg.serverFilter Server scope; '' means every server.
 * @param {boolean}     arg.errorsOnly   Ask for the URL's errors alone.
 * @param {Object|null} [arg.after]      Partition => `{ segment, offset }`;
 *                                       null asks for the whole window.
 * @return {string[]} The command token array.
 */
function urlDetailArgs( { hash, serverFilter, errorsOnly, after = null } ) {
	const options = { categories: true };
	if ( serverFilter ) {
		options.server = serverFilter;
	}
	if ( errorsOnly ) {
		options.errors_only = true;
	}
	if ( after ) {
		options.after = JSON.stringify( after );
	}
	return formatCommandArgs( [ hash ], options );
}

/**
 * Whether a URL hash may go out as a `dump_url` token. A selection arrives
 * from a deep link as readily as from a clicked row, so it is user input, and
 * one that fails here drives the modal's error control instead of a command.
 *
 * @param {*} h The candidate hash.
 * @return {boolean} Whether it is a hex string.
 */
const isValidHash = ( h ) => 'string' === typeof h && /^[a-f0-9]+$/.test( h );

/**
 * Whether a request id may go out as a `dump_request` token, on the same
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
 * @param {boolean} [opts.askActive]        The `?` picker is armed, which holds
 *                                          the poll exactly as an open modal
 *                                          does: the reader is aiming at a row,
 *                                          and a repaint moves it.
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
 * @param {boolean} [opts.urlErrorsOnly]    The modal lists its URL's errors
 *                                          alone, which the server walks past
 *                                          the clean requests to find.
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
		askActive = false,
		requestPartition = null,
		selectedUrl = null,
		selectedRequest = null,
		urlErrorsOnly = false,
	} = opts;

	// The whole opts object, for the dump_url getter's fire-time selection.
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

	/** The overview question the page asks now. */
	const overviewNow = useCallback(
		() =>
			overviewArgs( {
				serverFilter: serverFilterRef.current,
				chartBreakdown: chartBreakdownRef.current,
			} ),
		[]
	);
	/** The URL-table question the page asks now. */
	const urlsNow = useCallback(
		() =>
			urlsArgs( {
				urlParams: urlParamsRef.current,
				serverFilter: serverFilterRef.current,
			} ),
		[]
	);

	// Poll cadence (ms); an unparseable setting takes the declared default.
	const intervalMs =
		parseInt( refreshInterval, 10 ) || DEFAULT_REFRESH_INTERVAL_MS;

	// The graph: overview and urls poll; the two detail views are on demand.
	const { pollNow } = useBatchedPoll( {
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
				argsFn: overviewNow,
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
				argsFn: urlsNow,
			} );

			// @longform On-demand dump_url: an ordinary slice, on its OWN
			// Timer rather than the shared tick — the modal arms it by
			// selection. The merge rides the transform slot, so it lands on
			// the receiver→view edge.
			addSliceFetcher( interpreter, {
				fetcher: URLDETAIL_FETCHER,
				receiver: URLDETAIL_RECV,
				command: 'dump_url',
				view: URLDETAIL_VIEW,
				viewClass: views.UrlDetailView,
				controlFrom: URLDETAIL_VIEW,
				tee: interpreter.makeNode( 'Timer', URLDETAIL_TIMER ),
				target: TARGET,
				transform: {
					name: URLDETAIL_TRANSFORM,
					nodeClass: views.UrlDetailMerge,
					controlFrom: URLDETAIL_TRANSFORM,
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
						errorsOnly: !! optsRef.current.urlErrorsOnly,
						after: Core.node( URLDETAIL_TRANSFORM ).cursor(),
					} );
				},
			} );

			// @longform On-demand dump_request: a selection asks through
			// `askNow()`, and the slice's own Timer sends what that could not
			// and re-asks what went unanswered. A record never changes, so
			// the getter mints nothing: the Timer only drives the asks.
			addSliceFetcher( interpreter, {
				fetcher: REQUESTDETAIL_FETCHER,
				receiver: REQUESTDETAIL_RECV,
				command: 'dump_request',
				view: REQUESTDETAIL_VIEW,
				viewClass: views.RequestDetailView,
				controlFrom: REQUESTDETAIL_VIEW,
				tee: interpreter.makeNode( 'Timer', REQUESTDETAIL_TIMER ),
				target: TARGET,
				argsFn: () => null,
			} );

			return () => {
				if ( urlFetchTimerRef.current ) {
					clearTimeout( urlFetchTimerRef.current );
				}
			};
		},
		timerName: 'performance:timer',
		teeName: 'performance:tee',
		// @longform Suspend the offscreen overview/urls poll while a detail
		// modal is open, and while the `?` picker is armed: a page repainting
		// under a stationary pointer swaps the row being aimed at.
		paused: !! ( selectedUrl || selectedRequest || askActive ),
		intervalMs,
	} );

	/**
	 * Fire a control straight into a view's `fill` through the shared
	 * minter, which stamps the origin that view trusts and throws for a view
	 * declaring none. A name no node answers to is a no-op, since every caller
	 * here names a node this hook built: the graph is torn down, and so is the
	 * slice.
	 *
	 * @param {string} viewName The view or transform node to control.
	 * @param {Object} value    The control, such as `{ action: 'loading' }`.
	 */
	const sendControl = useCallback( ( viewName, value ) => {
		const view = Core.node( viewName );
		if ( view ) {
			view.fill( controlMsg( view, value ) );
		}
	}, [] );

	/**
	 * Show both polled slices as loading and ask both questions afresh,
	 * superseding the asks in flight, so the gate drops their late answers;
	 * then fire the batched tick, which sends both in one POST. A held poll
	 * keeps them parked until its release pokes again.
	 */
	const pokeOverviewUrls = useCallback( () => {
		sendControl( OVERVIEW_VIEW, { action: 'loading' } );
		sendControl( URLS_VIEW, { action: 'loading' } );
		Core.node( OVERVIEW_FETCHER )?.send( overviewNow(), null, true );
		Core.node( URLS_FETCHER )?.send( urlsNow(), null, true );
		pollNow();
	}, [ sendControl, pollNow, overviewNow, urlsNow ] );

	// Re-poke the polled slices on a filter or breakdown change, not on mount.
	const firstFilterRun = useRef( true );
	useEffect( () => {
		if ( firstFilterRun.current ) {
			firstFilterRun.current = false;
			return;
		}
		pokeOverviewUrls();
	}, [ serverFilter, chartBreakdown, pokeOverviewUrls ] );

	// Every hold leaves the page stale, so each refreshes on release.
	const wasHeld = useRef( false );
	useEffect( () => {
		const held = !! ( selectedUrl || selectedRequest || askActive );
		if ( wasHeld.current && ! held && isPageVisible ) {
			pokeOverviewUrls();
		}
		wasHeld.current = held;
	}, [
		selectedUrl,
		selectedRequest,
		askActive,
		isPageVisible,
		pokeOverviewUrls,
	] );

	// The URL and server the modal's list was last asked under.
	const detailScopeRef = useRef( null );

	// dump_url on open, and afresh on a change of scope or of Errors Only.
	useEffect( () => {
		if ( ! selectedUrl ) {
			detailScopeRef.current = null;
			sendControl( URLDETAIL_VIEW, { action: 'clear' } );
			sendControl( URLDETAIL_TRANSFORM, { action: 'clear' } );
			return;
		}
		if ( ! isValidHash( selectedUrl.hash ) ) {
			sendControl( URLDETAIL_VIEW, {
				action: 'error',
				error: 'Invalid URL hash format',
			} );
			return;
		}
		const ask = urlDetailArgs( {
			hash: selectedUrl.hash,
			serverFilter,
			errorsOnly: urlErrorsOnly,
		} );
		const scope = JSON.stringify( [ selectedUrl.hash, serverFilter ] );
		const relist = scope === detailScopeRef.current;
		detailScopeRef.current = scope;
		// The held list answers the old ask; showing it under the new one lies.
		sendControl( URLDETAIL_VIEW, { action: 'clear' } );
		sendControl( URLDETAIL_VIEW, { action: 'loading' } );
		// @longform The merge node drops a reply holding no request it lacks
		// under the stamp it holds, and both read the same under every scope.
		// Uncleared, a rescoped reply is discarded and the modal keeps the
		// previous server's numbers; its cursor would skip the full read. An
		// Errors Only flip keeps the URL, so it relists and keeps the flame.
		sendControl( URLDETAIL_TRANSFORM, {
			action: relist ? 'relist' : 'clear',
		} );
		Core.node( URLDETAIL_FETCHER )?.askNow( ask );
	}, [ selectedUrl, serverFilter, urlErrorsOnly, sendControl ] );

	// Arm dump_url refresh Timer only while URL detail is the visible view.
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

	// Arm dump_request's Timer only while a request is the visible modal.
	useEffect( () => {
		const timer = Core.node( REQUESTDETAIL_TIMER );
		if ( ! timer ) {
			return undefined;
		}
		if ( selectedRequest && isPageVisible ) {
			timer.setTimer( REQUESTDETAIL_TICK_MS );
			return () => timer.stopTimer();
		}
		timer.stopTimer();
		return undefined;
	}, [ selectedRequest, isPageVisible ] );

	// Selection-driven dump_request.
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
		// The held record answers the old rid, not the one now loading.
		sendControl( REQUESTDETAIL_VIEW, { action: 'clear' } );
		sendControl( REQUESTDETAIL_VIEW, { action: 'loading' } );
		const options = {};
		// 0 is the verb's default, and the option is a hint, not a filter.
		if ( partition ) {
			options.partition = partition;
		}
		// Supersedes the ask about the request the operator left.
		Core.node( REQUESTDETAIL_FETCHER )?.askNow(
			formatCommandArgs( [ selectedRequest ], options )
		);
	}, [ selectedRequest, requestPartition, sendControl ] );

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
			// `urls:in:current` drops the answer to every ask this supersedes.
			const doFetch = () => {
				sendControl( URLS_VIEW, { action: 'loading' } );
				Core.node( URLS_FETCHER )?.askNow( urlsNow() );
			};
			if ( searchChanged ) {
				urlFetchTimerRef.current = setTimeout( doFetch, 300 );
			} else {
				doFetch();
			}
		},
		[ sendControl, urlsNow ]
	);

	return { handleUrlParamsChange };
}
