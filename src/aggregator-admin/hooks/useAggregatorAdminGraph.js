/**
 * useAggregatorAdminGraph — mounts the Configured-Servers admin node graph (the
 * full-React replacement for the old jQuery `aggregator-admin/index.js`). On mount
 * it builds two nodes — `servers/command` (the CRUD command transport) and
 * `servers/view` (the render model React reads) — wires the data path command →
 * view directly (the view node does the map→array derivation), and fires one
 * immediate `list()`. The view publishes its state via `setState('view', …)`; the
 * React view reads it separately with `useNodeState('servers/view','view')`.
 *
 * The hook exposes the four CRUD callbacks the view calls. add/update/remove each
 * await the node mutation then re-`list()` — this re-list is what REPLACES the old
 * jQuery `window.location.reload()`. test() returns its probe result to the caller
 * (per-row status) and does NOT re-list (a probe doesn't change the registry). A
 * mutation rejection is surfaced into the view model (an error control filled into
 * `servers/view`) AND re-thrown so the calling component can also react per-field.
 *
 * Torn down on unmount: the command node is closed (cancel any in-flight list)
 * BEFORE both nodes are unregistered from Core. The command boundary is injectable:
 * tests pass `opts.commandClient` (threaded to the command node) so the hook never
 * touches the network. Production lazily defaults to the shared CommandClient
 * singleton inside the command node. Mirrors useAggregatorStatusGraph.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createServersCommand } from '../nodes/serversCommand';
import { createServersView } from '../nodes/serversView';

// Every named node this graph mounts — unregistered on teardown.
const COMMAND = 'servers/command';
const VIEW = 'servers/view';
const GRAPH_NODE_NAMES = [ COMMAND, VIEW ];

/**
 * @param {Object} [opts]               Options (testing seams).
 * @param {Object} [opts.commandClient] Command-client seam threaded to the command
 *                                      node; defaults to the shared singleton.
 * @return {{ addServer: Function, updateServer: Function, removeServer: Function,
 *   testServer: Function }} CRUD callbacks for the thin React view (the model is
 *   read via useNodeState).
 */
export function useAggregatorAdminGraph( opts = {} ) {
	const { commandClient } = opts;

	// Stash the latest command client so the mount effect reads it without
	// re-subscribing (it only runs once).
	const commandClientRef = useRef( commandClient );
	commandClientRef.current = commandClient;

	// Live command-node handle for the CRUD callbacks.
	const commandRef = useRef( null );

	// Flipped true once the graph (and its view node) is mounted. The mount effect
	// runs AFTER the first render, by which point useNodeState has captured a null
	// view node and bailed; setting this state forces the consumer to re-render so
	// useNodeState re-subscribes to the now-registered view node. Mirrors
	// useAggregatorStatusGraph's setViewReady.
	const [ , setViewReady ] = useState( false );

	// Mount the graph once: command → view, then fire one immediate list.
	useEffect( () => {
		const command = createServersCommand( COMMAND, {
			commandClient: commandClientRef.current,
		} );
		const view = createServersView( VIEW );
		command.sink = view;
		commandRef.current = command;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );
		command.list();

		return () => {
			// Close the in-flight-cancel-owning command node first BEFORE
			// unregistering — mirrors useAggregatorStatusGraph calling poll.close()
			// before unregister.
			command.close();
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			commandRef.current = null;
			setViewReady( false );
		};
	}, [] );

	// Push a mutation failure into the view model so the table shows it, then
	// re-throw so the calling component can also react per-field. Fills an error
	// control directly into servers/view (whose fill() handles `action:'error'`).
	const surfaceError = useCallback( ( err ) => {
		const view = Core.node( VIEW );
		if ( view ) {
			const control = newMessage();
			control[ TYPE ] = TM_STRUCT;
			control[ VALUE ] = {
				action: 'error',
				error: err?.message || 'Operation failed',
			};
			view.fill( control );
		}
	}, [] );

	// Run a registry-mutating verb, re-list on success (replaces reload), and
	// surface a failure into the view model before re-throwing.
	const runMutation = useCallback(
		async ( fn ) => {
			const command = commandRef.current;
			if ( ! command ) {
				return undefined;
			}
			try {
				const result = await fn( command );
				await command.list();
				return result;
			} catch ( err ) {
				surfaceError( err );
				throw err;
			}
		},
		[ surfaceError ]
	);

	const addServer = useCallback(
		( fields ) => runMutation( ( command ) => command.add( fields ) ),
		[ runMutation ]
	);

	const updateServer = useCallback(
		( id, partial ) =>
			runMutation( ( command ) => command.update( id, partial ) ),
		[ runMutation ]
	);

	const removeServer = useCallback(
		( id ) => runMutation( ( command ) => command.remove( id ) ),
		[ runMutation ]
	);

	// test() is read-only: return its probe result to the caller for per-row
	// status; no re-list (a probe doesn't change the registry).
	const testServer = useCallback( ( id ) => {
		const command = commandRef.current;
		if ( ! command ) {
			return undefined;
		}
		return command.test( id );
	}, [] );

	return { addServer, updateServer, removeServer, testServer };
}
