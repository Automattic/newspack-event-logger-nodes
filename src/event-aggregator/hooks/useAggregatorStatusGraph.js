/* global localStorage */
/**
 * useAggregatorStatusGraph — mounts the Aggregator Status dashboard node graph
 * clipped onto the exospine (the canonical rule-#2 backbone `_command_interpreter
 * → _router`). On mount it builds two nodes — `aggregator:poll` (the status-command
 * transport) and `aggregator:view` (the render model React reads). EVERY node sinks
 * into the CI; flow is steered ONLY by each node's `target` (the router peels TO
 * and delivers): the poll targets the view (the view node does the map→array +
 * connected-count derivation, so no separate transform node is needed). There is
 * no bespoke `poll.sink=view` wiring. It fires one immediate `poll()`. The view
 * publishes its state via `setState('view', …)`; the React view reads it separately
 * with `useNodeState('aggregator:view','view')`.
 *
 * The hook OWNS the poll interval: a setInterval at the current refresh ms that
 * fires `poll.poll()`. It is NOT gated on page visibility — the old
 * AggregatorStatus polled unconditionally, so this preserves that exactly. It
 * re-times when the interval changes and clears on unmount. (The 1s "ago" tick
 * that refreshes relative timestamps stays in the thin view — pure display.)
 *
 * Returns the thin control callbacks the view calls — `setRefreshInterval`
 * (persists to localStorage + re-times the interval) and the current
 * `refreshInterval`. There are NO action verbs (the old AggregatorStatus had no
 * restart/refresh buttons — only the cadence selector). Torn down on unmount: the
 * interval is cleared, the poll node is closed (cancel any in-flight poll) BEFORE
 * both nodes are unregistered from Core.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (threaded
 * to the poll node) so the hook never touches the network. Production lazily
 * defaults to the shared CommandClient singleton inside the poll node.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { Core, mountExospine } from '@newspack-nodes/runtime';
import { createAggregatorPoll } from '../nodes/aggregatorPoll';
import { createAggregatorView } from '../nodes/aggregatorView';

// Refresh-interval options offered to the user (the select in the dashboard).
export const REFRESH_OPTIONS = [
	{ label: '1s', value: '1000' },
	{ label: '2s', value: '2000' },
	{ label: '5s', value: '5000' },
	{ label: '10s', value: '10000' },
];

export const DEFAULT_REFRESH_MS = '2000';
const REFRESH_KEY = 'aggregator-status-refresh';

// Every named node this graph mounts — unregistered on teardown (the exospine
// nodes are removed separately by its own teardown()). Names use a colon, not a
// slash: the router peels TO on '/', so a '/' in a node name would misroute.
const POLL = 'aggregator:poll';
const VIEW = 'aggregator:view';
const GRAPH_NODE_NAMES = [ POLL, VIEW ];

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
 * @param {Object} [opts]               Options (testing seams).
 * @param {Object} [opts.commandClient] Command-client seam threaded to the poll
 *                                      node; defaults to the shared singleton.
 * @return {{ setRefreshInterval: Function, refreshInterval: string }} Control
 *   callbacks for the thin React view (the model is read via useNodeState).
 */
export function useAggregatorStatusGraph( opts = {} ) {
	const { commandClient } = opts;

	// The persisted refresh interval (string ms); seeds from localStorage.
	const [ refreshInterval, setRefreshIntervalState ] =
		useState( initialRefresh );

	// Stash the latest command client so the mount effect reads it without
	// re-subscribing (it only runs once).
	const commandClientRef = useRef( commandClient );
	commandClientRef.current = commandClient;

	// Live poll-node handle for the interval effect.
	const pollRef = useRef( null );

	// Flipped true once the graph (and its view node) is mounted. The mount
	// effect runs AFTER the first render, by which point useNodeState has already
	// captured a null view node and bailed; setting this state forces the
	// consumer to re-render so useNodeState re-subscribes to the now-registered
	// view node and reads the published model. Without it the dashboard stays
	// stuck on the loading placeholder. Mirrors useWorkerStatusGraph's setViewReady.
	const [ , setViewReady ] = useState( false );

	// Mount the graph once: clip it onto the exospine, then fire one immediate poll.
	useEffect( () => {
		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, teardown: teardownSpine } = mountExospine();

		const poll = createAggregatorPoll( POLL, {
			commandClient: commandClientRef.current,
		} );
		const view = createAggregatorView( VIEW );

		// Rule #2: every node sinks into the CI; flow is steered by `target`.
		poll.sink = ci;
		poll.target = VIEW;
		view.sink = ci;
		pollRef.current = poll;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );
		poll.poll();

		return () => {
			// Close the in-flight-cancel-owning poll node first BEFORE
			// unregistering — mirrors useWorkerStatusGraph calling poll.close()
			// before unregister. Unregister the graph nodes, THEN tear down the
			// exospine (which removes the CI + router).
			poll.close();
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			pollRef.current = null;
			setViewReady( false );
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
			if ( pollRef.current ) {
				pollRef.current.poll();
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
