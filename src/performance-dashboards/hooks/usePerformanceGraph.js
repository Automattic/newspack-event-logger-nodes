/**
 * usePerformanceGraph — mounts the Performance Dashboard's data graph
 * (`performance:command` → `performance:view`) clipped onto the exospine (the
 * canonical rule-#2 backbone `_command_interpreter → _router`) and owns every
 * fetch. Mirrors useAggregatorAdminGraph: a mount effect mounts the backbone,
 * builds the two nodes, sinks BOTH into the CI and targets the command at the
 * view (the router peels TO and delivers — no bespoke `command.sink=view`), flips
 * `viewReady` (so the consumer's useNodeState re-subscribes to the freshly-
 * registered view node), and on teardown closes the command (cancel guard),
 * unregisters both graph nodes, THEN tears down the exospine.
 *
 * The CONSUMER reads the model itself via useNodeState('performance:view',
 * 'view') — this hook returns ONLY control callbacks, exactly like
 * useErrorLogGraph returns { setPaused, clear }. Reading the model in the
 * consumer (not here) lets the orchestrator derive `urls` for useUrlNavigation
 * BEFORE this hook consumes the resulting selection.
 *
 * Effects owned here (each gated on `viewReady`):
 *  - initial load + server-filter/breakdown re-fetch: one effect keyed on
 *    [viewReady, serverFilter, chartBreakdown] fires fetchOverview + fetchUrls.
 *    Runs once on mount (initial load) and again on filter/breakdown change.
 *  - refresh interval: localStorage-cadence setInterval, stale-immediate,
 *    gated `!selectedUrl && isPageVisible`.
 *  - selection-driven url-detail: fetchUrlDetail(hash,{categories,initial:true})
 *    on select; a non-initial SILENT auto-refresh interval while !selectedRequest
 *    && visible; clears the slice on deselect.
 *  - selection-driven request-detail: fetchRequestDetail on select (partition
 *    from requestPartition or looked up in urlDetailData.requests); clears on deselect.
 *
 * The debounced URL-params fetch is a returned CALLBACK (handleUrlParamsChange).
 *
 * @param {Object}   opts
 * @param {string}   [opts.serverFilter]
 * @param {string}   [opts.chartBreakdown]
 * @param {string}   [opts.refreshInterval]
 * @param {number}   [opts.requestPartition]
 * @param {Object}   [opts.selectedUrl]
 * @param {string}   [opts.selectedRequest]
 * @param {Object}   [opts.urlDetailData]
 * @param {Function} [opts.onError]
 * @param {Object}   [opts.commandClient]
 * @return {{ handleUrlParamsChange: Function, resolveRequest: Function, fetchUrlBreakdown: Function }}
 */
import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import {
	Core,
	mountExospine,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createPerformanceCommand } from '../nodes/performanceCommand';
import { createPerformanceView } from '../nodes/performanceView';
import usePageVisibility from '../../shared/hooks/usePageVisibility';
import { getCommandClient } from '../../shared/utils/commandClient';
import unwrapCommandResponse from '../../shared/utils/unwrapCommandResponse';

// Names use a colon, not a slash: the router peels TO on '/', so a '/' in a node
// name would misroute.
const COMMAND = 'performance:command';
const VIEW = 'performance:view';

// Build a TM_STRUCT control the view's fill() routes on its `action`.
const controlMsg = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

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

	// Mount the graph once onto the exospine: command → view.
	useEffect( () => {
		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, teardown: teardownSpine } = mountExospine();

		const command = createPerformanceCommand( COMMAND, {
			commandClient: optsRef.current.commandClient,
			onError: optsRef.current.onError,
		} );
		const view = createPerformanceView( VIEW );

		// Rule #2: every node sinks into the CI; flow is steered by `target`.
		command.sink = ci;
		command.target = VIEW;
		view.sink = ci;

		commandRef.current = command;
		viewRef.current = view;
		setViewReady( true );

		return () => {
			if ( urlFetchTimerRef.current ) {
				clearTimeout( urlFetchTimerRef.current );
			}
			// Close the in-flight-cancel-owning command first, unregister the
			// graph nodes, THEN tear the exospine down (removes the CI + router).
			command.close();
			Core.unregisterNode( COMMAND );
			Core.unregisterNode( VIEW );
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
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear', slice } ) );
		}
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
	// which fires from useUrlNavigation's mount effect — BEFORE this hook's mount
	// effect populates commandRef. When the node isn't mounted yet, resolve via the
	// client directly (request_search is a stateless pass-through, no sink/view emit).
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
