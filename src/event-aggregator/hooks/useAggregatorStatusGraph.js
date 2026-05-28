/* global localStorage */
/**
 * useAggregatorStatusGraph — mounts the Aggregator Status dashboard node graph
 * onto the canonical rule-#2 backbone (`_command_interpreter → _router`) using
 * the substrate's HTTP I/O boundary node — the minimal mount surface a
 * poll-only dashboard needs:
 *
 *   _http       (HttpOut — POST /command boundary; .client = CommandClient)
 *
 * Plus the application's render-model node:
 *
 *   aggregator:view (the view-model node the React view reads)
 *
 * Dashboards aren't REPLs: no transcript window, no tab-completion input, no
 * uptime display, no `cd` navigation. So `_output` / `_completion` / `_uptime` /
 * `_cwd` are NOT mounted here — they'd be dead weight and would collide with
 * the debug-overlay's REPL when it opens on this page.
 *
 * Every node sinks into the CI; flow is steered by each node's `target`. The
 * hook owns the poll setInterval — on every tick it builds a TM_COMMAND
 * (FROM=`aggregator:view`, TO=`_http/aggregator`, verb=`status`) and fills it
 * into the CI. The router peels `_http`, HttpOut POSTs the command, the server
 * pivots the reply TO=FROM, the router peels `aggregator:view`, and the view
 * unwraps the payload into its render model. No bespoke `aggregator:poll` node.
 *
 * NOT gated on page visibility — the old AggregatorStatus polled
 * unconditionally, so the migration preserves that exactly. The 1s "ago" tick
 * that refreshes relative timestamps stays in the thin view — pure display.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (assigned
 * to `_http.client`) so the hook never touches the network. Production lazily
 * defaults to the shared CommandClient singleton.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	mountExospine,
	HttpOut,
	CommandClient,
	newMessage,
	TYPE,
	TO,
	FROM,
	VALUE,
	TM_COMMAND,
} from '@newspack-nodes/runtime';
import { createAggregatorView } from '../nodes/aggregatorView';

// The I/O boundary node mounted from the substrate runtime.
const HTTP = '_http';
// The application's render-model node.
const VIEW = 'aggregator:view';
// Every named node this graph mounts — unregistered on teardown (the exospine
// nodes are removed separately by teardownSpine()).
const GRAPH_NODE_NAMES = [ HTTP, VIEW ];

// Refresh-interval options offered to the user (the select in the dashboard).
export const REFRESH_OPTIONS = [
	{ label: '1s', value: '1000' },
	{ label: '2s', value: '2000' },
	{ label: '5s', value: '5000' },
	{ label: '10s', value: '10000' },
];

export const DEFAULT_REFRESH_MS = '2000';
const REFRESH_KEY = 'aggregator-status-refresh';

/**
 * Resolve the initial refresh interval from localStorage (matches the old
 * AggregatorStatus useState initializer).
 *
 * @return {string} A valid REFRESH_OPTIONS value, or DEFAULT_REFRESH_MS.
 */
function initialRefresh() {
	const validValues = REFRESH_OPTIONS.map( ( opt ) => opt.value );
	const saved = localStorage.getItem( REFRESH_KEY );
	if ( saved && validValues.includes( saved ) ) {
		return saved;
	}
	return DEFAULT_REFRESH_MS;
}

/**
 * Build the poll TM_COMMAND: FROM=`aggregator:view` so the server's reply pivot
 * lands on the view; TO=`_http/aggregator` so the router peels `_http` and
 * HttpOut POSTs the bare `aggregator.status` command (no worker indirection).
 *
 * @return {Array} A 7-field positional Message.
 */
function buildPollMessage() {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = VIEW;
	m[ TO ] = `${ HTTP }/aggregator`;
	m[ VALUE ] = { name: 'status', arguments: '', payload: null };
	return m;
}

/**
 * @param {Object} [opts]               Options (testing seams).
 * @param {Object} [opts.commandClient] CommandClient seam assigned to `_http.client`;
 *                                      defaults to a freshly-constructed CommandClient.
 * @return {{ setRefreshInterval: Function, refreshInterval: string }} Control
 *   callbacks for the thin React view (the model is read via useNodeState).
 */
export function useAggregatorStatusGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// The persisted refresh interval (string ms); seeds from localStorage.
	const [ refreshInterval, setRefreshIntervalState ] =
		useState( initialRefresh );

	// Live CI handle for the poll-interval effect.
	const ciRef = useRef( null );

	// Flipped true once the graph (and its view node) is mounted, so the React
	// view's useNodeState re-subscribes to the now-registered view node.
	const [ , setViewReady ] = useState( false );

	// Mount the graph once: clip it onto the exospine, then fire one immediate poll.
	useEffect( () => {
		const data =
			( typeof window !== 'undefined' && window.NewspackNodesData ) || {};

		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, teardown: teardownSpine } = mountExospine();

		// I/O boundary node — HttpOut is the only one this poll-only dashboard
		// needs.
		const http = new HttpOut();
		http.client =
			optsRef.current.commandClient ||
			new CommandClient( {
				baseUrl: data.restUrl || '/wp-json/',
				nonce: data.nonce || '',
			} );
		http.setName( HTTP );
		http.sink = ci;

		// The application view-model node — the receiver of the poll reply via the
		// server's TO=FROM pivot.
		const view = createAggregatorView( VIEW );
		view.sink = ci;

		ciRef.current = ci;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

		// Fire one immediate poll: the canonical "everything sinks into the CI"
		// path — CI forwards (non-command, non-empty-TO) to router → router peels
		// `_http` → HttpOut.fill POSTs the command.
		ci.fill( buildPollMessage() );

		return () => {
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			ciRef.current = null;
		};
	}, [] );

	// Persist the refresh choice (matches the old save-to-localStorage effect).
	useEffect( () => {
		localStorage.setItem( REFRESH_KEY, refreshInterval );
	}, [ refreshInterval ] );

	// Own the poll interval: re-timed on interval change, cleared on unmount. NOT
	// gated on visibility — the old AggregatorStatus polled unconditionally.
	useEffect( () => {
		const intervalMs = parseInt( refreshInterval, 10 );
		const id = setInterval( () => {
			if ( ciRef.current ) {
				ciRef.current.fill( buildPollMessage() );
			}
		}, intervalMs );
		return () => clearInterval( id );
	}, [ refreshInterval ] );

	// Change + persist the refresh interval; the interval effect re-times.
	const setRefreshInterval = ( value ) => {
		setRefreshIntervalState( value );
	};

	return { setRefreshInterval, refreshInterval };
}
