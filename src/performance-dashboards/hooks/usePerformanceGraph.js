/**
 * usePerformanceGraph — mounts the Performance Dashboard's data graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's HTTP I/O boundary node, plus the application's
 * `performance:command` (slice-tagging command-builder) and `performance:view`
 * (render model + pending-Promise registry):
 *
 *   _http              (HttpOutNode — POST /command boundary; .client = CommandClient)
 *   performance:command (slice-tagging command-builder)
 *   performance:view    (the view-model node React reads + pending Map)
 *
 * Dashboards aren't REPLs: no transcript window, no tab-completion input, no
 * uptime display, no `cd` navigation. So `_output` / `_completion` / `_uptime` /
 * `_cwd` are NOT mounted here — they'd be dead weight and would collide with
 * the debug-overlay's REPL when it opens on this page.
 *
 * Every node sinks into the interpreter (rule #2); flow is steered by each node's
 * `target`. The hook owns the orchestration effects (initial load, refresh
 * interval, selection-driven fetches, debounced URL-params change). Each
 * fetch flows `performance:command` → interpreter → router → `_http` → POST → server
 * pivots TO=FROM → router → `performance:view`, which matches `message[ID]`
 * to its pending Map and applies the result to the registered slice.
 *
 * The consumer reads the model itself via `useNodeState('performance:view',
 * 'view')` — this hook returns ONLY control callbacks
 * (`handleUrlParamsChange`, `resolveRequest`, `fetchUrlBreakdown`), exactly
 * like useErrorLogGraph returns `{ setPaused, clear }`.
 *
 * The command boundary is injectable: tests pass `opts.commandClient`
 * (assigned to `_http.client`) so the hook never touches the network.
 * Production lazily defaults to a freshly-constructed CommandClient.
 *
 * Exospine isolation: this hook calls `mountExospine()` for its own React
 * tree root. `PerformanceDashboard` and `ErrorLog` are mounted into
 * SEPARATE DOM containers (`event-logger-admin` vs `event-logger-errors`),
 * so each hook's exospine is naturally isolated by React-root scope. A page
 * that ever rendered both would still get one exospine per hook instance —
 * `Core` is a per-page singleton, but each `mountExospine` registers the
 * canonical `_command_interpreter`/`_router` names by design; the matching
 * teardown removes them. The two performance-dashboards hooks aren't used on
 * the same page in this plugin.
 */
import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import {
	Core,
	mountExospine,
	HttpOutNode,
	CommandClient,
	newMessage,
	TYPE,
	VALUE,
	TM_STRUCT,
} from '@newspack-nodes/runtime';
import { createPerformanceCommand } from '../nodes/performance-command-node';
import { createPerformanceView } from '../nodes/performance-view-node';
import usePageVisibility from '../../shared/hooks/usePageVisibility';
import { getCommandClient } from '../../shared/utils/commandClient';
import unwrapCommandResponse from '../../shared/utils/unwrapCommandResponse';

// I/O boundary node.
const HTTP = '_http';
// Application nodes. Names use a colon, not a slash: the router peels TO on
// '/', so a '/' in a node name would misroute.
const COMMAND = 'performance:command';
const VIEW = 'performance:view';
const GRAPH_NODE_NAMES = [ HTTP, COMMAND, VIEW ];

// Dedup `server` (always — feeds the filter dropdown) with the active chart
// dimension into one comma arg. < 2 dims collapses the controller's nested
// response shape, so pad with `status`. (From the orchestrator.)
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

export function usePerformanceGraph( opts = {} ) {
	const {
		serverFilter = '',
		chartBreakdown = 'status',
		refreshInterval = '15000',
		requestPartition = null,
		selectedUrl = null,
		selectedRequest = null,
		urlDetailData = null,
	} = opts;

	const optsRef = useRef( opts );
	optsRef.current = opts;

	const commandRef = useRef( null );
	const viewRef = useRef( null );

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
	const lastRefreshRef = useRef( 0 );

	const [ viewReady, setViewReady ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mount the graph once onto the exospine: I/O boundary + command + view.
	useEffect( () => {
		const data =
			( typeof window !== 'undefined' && window.NewspackNodesData ) || {};

		// The canonical backbone every node clips onto: everything → interpreter → router.
		const { interpreter, teardown: teardownSpine } = mountExospine();

		// I/O boundary node — HttpOutNode is the only one this dashboard needs.
		const http = new HttpOutNode();
		http.client =
			optsRef.current.commandClient ||
			new CommandClient( {
				baseUrl: data.restUrl || '/wp-json/',
				nonce: data.nonce || '',
			} );
		http.setName( HTTP );
		http.sink = interpreter;

		// The application view-model node — receiver of every reply via TO=FROM pivot.
		const view = createPerformanceView( VIEW );
		view.sink = interpreter;

		// The slice-tagging command-builder. sink = interpreter (rule #2); target = view
		// so `loading`/`error` controls route to the view via the router peeling
		// TO. viewName = VIEW so the command can stash pending entries there.
		const command = createPerformanceCommand( COMMAND, {
			onError: optsRef.current.onError,
			viewName: VIEW,
		} );
		command.sink = interpreter;
		command.target = VIEW;

		commandRef.current = command;
		viewRef.current = view;
		setViewReady( true );

		return () => {
			if ( urlFetchTimerRef.current ) {
				clearTimeout( urlFetchTimerRef.current );
			}
			// Close the command (cancel guard) first so a late reply doesn't fire
			// emissions against a torn-down view; unregister the graph nodes; THEN
			// tear the exospine down (removes the interpreter + router).
			command.close();
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			commandRef.current = null;
			viewRef.current = null;
		};
	}, [] );

	// Initial load + re-fetch on server-filter / breakdown change.
	useEffect( () => {
		if ( ! viewReady ) {
			return;
		}
		const dims = breakdownsFor( chartBreakdown );
		commandRef.current.fetchOverview( serverFilter, dims );
		commandRef.current.fetchUrls( {
			...urlParamsRef.current,
			server: serverFilter,
		} );
	}, [ viewReady, serverFilter, chartBreakdown ] );

	// Auto-refresh body while the modal is closed and the page is visible.
	useEffect( () => {
		if ( ! viewReady || selectedUrl || ! isPageVisible ) {
			return undefined;
		}
		const intervalMs = parseInt( refreshInterval, 10 );
		const doRefresh = () => {
			lastRefreshRef.current = Date.now();
			const dims = breakdownsFor( chartBreakdownRef.current );
			commandRef.current.fetchOverview( serverFilterRef.current, dims );
			commandRef.current.fetchUrls( {
				...urlParamsRef.current,
				server: serverFilterRef.current,
			} );
		};
		if ( Date.now() - lastRefreshRef.current >= intervalMs ) {
			doRefresh();
		}
		const interval = setInterval( doRefresh, intervalMs );
		return () => clearInterval( interval );
	}, [ viewReady, refreshInterval, selectedUrl, isPageVisible ] );

	// Clear a view slice (resets urlDetail / requestDetail to empty).
	const clearSlice = useCallback( ( slice ) => {
		if ( ! viewRef.current ) {
			return;
		}
		// Fire a TM_STRUCT control directly into the view's fill — no router
		// hop, but the canonical view fill() handles the action. This is the
		// same pattern useErrorLogGraph uses for hook-direct view control
		// (setPaused / clear).
		viewRef.current.fill(
			buildControlMessage( { action: 'clear', slice } )
		);
	}, [] );

	// Selection-driven url-detail: initial fetch + silent auto-refresh.
	useEffect( () => {
		if ( ! viewReady ) {
			return undefined;
		}
		if ( ! selectedUrl ) {
			clearSlice( 'urlDetail' );
			return undefined;
		}
		commandRef.current.fetchUrlDetail( selectedUrl.hash, {
			categories: true,
			initial: true,
		} );
		if ( ! selectedRequest && isPageVisible ) {
			const intervalMs = parseInt( refreshInterval, 10 );
			const interval = setInterval( () => {
				commandRef.current?.fetchUrlDetail( selectedUrl.hash, {
					categories: true,
				} );
			}, intervalMs );
			return () => clearInterval( interval );
		}
		return undefined;
	}, [
		viewReady,
		selectedUrl,
		selectedRequest,
		refreshInterval,
		isPageVisible,
		clearSlice,
	] );

	// Selection-driven request-detail.
	useEffect( () => {
		if ( ! viewReady ) {
			return;
		}
		if ( ! selectedRequest ) {
			clearSlice( 'requestDetail' );
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
		if ( partition !== undefined && partition !== null ) {
			commandRef.current.fetchRequestDetail( selectedRequest, partition );
		}
	}, [
		viewReady,
		selectedRequest,
		requestPartition,
		urlDetailData,
		clearSlice,
	] );

	// Debounced URL-table params fetch (search debounced 300ms; sort/page immediate).
	const handleUrlParamsChange = useCallback( ( params ) => {
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
		const doFetch = () =>
			commandRef.current?.fetchUrls( {
				...params,
				server: serverFilterRef.current,
			} );
		if ( searchChanged ) {
			urlFetchTimerRef.current = setTimeout( doFetch, 300 );
		} else {
			doFetch();
		}
	}, [] );

	// resolveRequest drives deep-link navigation (`?request=` without `?url=`),
	// which fires from useUrlNavigation's mount effect — BEFORE this hook's
	// mount effect populates commandRef. When the node isn't mounted yet, fall
	// back to the shared CommandClient directly: request_search is a
	// stateless lookup with no view-model side effects, so the substrate hop is
	// just plumbing in that pre-mount window. Once mounted, the substrate path
	// owns it.
	const resolveRequest = useCallback( async ( rid ) => {
		if ( commandRef.current ) {
			return commandRef.current.resolveRequest( rid );
		}
		try {
			const client = optsRef.current.commandClient || getCommandClient();
			return unwrapCommandResponse(
				await client.send( {
					to: 'performance',
					verb: 'request_search',
					payload: { rid },
				} )
			);
		} catch ( err ) {
			return null;
		}
	}, [] );

	const fetchUrlBreakdown = useCallback(
		( hash, breakdown ) =>
			commandRef.current?.fetchUrlBreakdown( hash, breakdown ) ??
			Promise.resolve( null ),
		[]
	);

	return { handleUrlParamsChange, resolveRequest, fetchUrlBreakdown };
}

// Build a TM_STRUCT control message — used for the hook-direct view clear()
// pattern. Mirrors useErrorLogGraph's controlMsg helper.
function buildControlMessage( value ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
}
