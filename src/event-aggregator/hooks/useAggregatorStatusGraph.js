/* global localStorage */
/**
 * useAggregatorStatusGraph — mounts the Aggregator Status dashboard node graph
 * onto the canonical rule-#2 backbone (`_command_interpreter → _router`) using
 * the substrate's I/O boundary nodes — the same ones the topology console uses,
 * minus the SSE pieces this poll-only dashboard doesn't need:
 *
 *   _http       (HttpOut — POST /command boundary; .client = CommandClient)
 *   _output     (Dumper — terminal output / log lines)
 *   _uptime     (uptime reply receiver)
 *   _completion (tab-completion receiver)
 *   _cwd        (current-working-directory indirection)
 *
 * (`_metadata` is omitted: it's only used by the topology console for dump_metadata
 * replies, isn't exported from the substrate's runtime index, and this dashboard
 * doesn't emit dump_metadata.)
 *
 * Plus the application's render-model node:
 *
 *   aggregator:view (the view-model node the React view reads)
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
	Node,
	HttpOut,
	Dumper,
	Uptime,
	Completion,
	CommandClient,
	newMessage,
	TYPE,
	TO,
	FROM,
	VALUE,
	TM_COMMAND,
} from '@newspack-nodes/runtime';
import { createAggregatorView } from '../nodes/aggregatorView';

// The I/O boundary nodes mounted from the substrate runtime.
const HTTP = '_http';
const OUTPUT = '_output';
const UPTIME = '_uptime';
const COMPLETION = '_completion';
const CWD = '_cwd';
// The application's render-model node.
const VIEW = 'aggregator:view';
// Every named node this graph mounts — unregistered on teardown (the exospine
// nodes are removed separately by teardownSpine()).
const GRAPH_NODE_NAMES = [ HTTP, OUTPUT, UPTIME, COMPLETION, CWD, VIEW ];

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

		// I/O boundary nodes — the same ones useConsoleGraph mounts (minus _sse /
		// _heartbeat, which this poll-only dashboard doesn't need).
		const http = new HttpOut();
		http.client =
			optsRef.current.commandClient ||
			new CommandClient( {
				baseUrl: data.restUrl || '/wp-json/',
				nonce: data.nonce || '',
			} );
		http.setName( HTTP );
		http.sink = ci;

		const output = new Dumper();
		output.setName( OUTPUT );
		output.sink = ci;

		const uptime = new Uptime();
		uptime.setName( UPTIME );
		uptime.sink = ci;

		const completion = new Completion();
		completion.setName( COMPLETION );
		completion.sink = ci;

		// `_cwd` is a plain Node — when a poll addresses it, base Node.fill
		// re-stamps `_cwd.target` into TO (or leaves it empty for the local root).
		// Not exercised by this dashboard's poll, but mounted for substrate
		// uniformity (the topology console's pattern). Default cwd points at the
		// aggregator endpoint so the substrate convention "polls target _cwd" still
		// resolves to a real address even if a future caller leans on it.
		const cwd = new Node();
		cwd.setName( CWD );
		cwd.sink = ci;
		cwd.target = `${ HTTP }/aggregator`;

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
