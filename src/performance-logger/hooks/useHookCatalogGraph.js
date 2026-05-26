/**
 * useHookCatalogGraph — mounts the Performance Logger hook-catalog node graph (the
 * JS-Node conversion of the old HookSelectorModal fetch). On mount it builds two
 * nodes — `hookcatalog/command` (the hooks_registered-command transport) and
 * `hookcatalog/view` (the render model React reads) — wires the data path
 * command → view directly, and reads the published model on the modal's behalf.
 *
 * The trigger is fire-on-OPEN, not an interval: a separate effect keyed on
 * `isOpen` fires one `command.fetch()` whenever isOpen flips true (re-fetching on
 * every re-open, exactly like the old modal's `useEffect([isOpen])`). There is NO
 * polling — the catalog is read once per open. Loading starts false (a closed modal
 * shows no spinner); the sole caller opens from closed, so a mount-while-already-open
 * — which would paint one no-spinner frame before the fetch fires — is unreached.
 *
 * The hook returns the model itself — `{ hooksByCategory, loading }` — rather than
 * leaving the component to call useNodeState. HookSelectorModal is a leaf consumer
 * that IS the thin view (its render is already pure presentation), so encapsulating
 * the useNodeState read here keeps the modal presentational; it's the same
 * node-graph contract, just read on the component's behalf.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (threaded to
 * the command node) so the hook never touches the network. Production lazily
 * defaults to the shared CommandClient singleton inside the command node. Torn down
 * on unmount: the command node is closed (cancel any in-flight fetch) BEFORE both
 * nodes are unregistered from Core.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { Core, useNodeState } from '@newspack-nodes/runtime';
import { createHookCatalogCommand } from '../nodes/hookCatalogCommand';
import { createHookCatalogView } from '../nodes/hookCatalogView';

// Every named node this graph mounts — unregistered on teardown.
const COMMAND = 'hookcatalog/command';
const VIEW = 'hookcatalog/view';
const GRAPH_NODE_NAMES = [ COMMAND, VIEW ];

/**
 * @param {Object}  [opts]               Options (testing seams).
 * @param {boolean} [opts.isOpen]        When true, fires one hook-catalog fetch.
 * @param {Object}  [opts.commandClient] Command-client seam threaded to the command
 *                                       node; defaults to the shared singleton.
 * @return {{ hooksByCategory: Object, loading: boolean }} The render model.
 */
export function useHookCatalogGraph( opts = {} ) {
	const { isOpen, commandClient } = opts;

	// Stash the latest command client so the mount effect reads it without
	// re-subscribing (it only runs once).
	const commandClientRef = useRef( commandClient );
	commandClientRef.current = commandClient;

	// Live command-node handle for the fire-on-open effect.
	const commandRef = useRef( null );

	// Flipped true once the graph (and its view node) is mounted. The mount
	// effect runs AFTER the first render, by which point useNodeState has already
	// captured a null view node and bailed; setting this state forces the
	// consumer to re-render so useNodeState re-subscribes to the now-registered
	// view node and reads the published model. Without it the dashboard stays
	// stuck on the loading placeholder. Mirrors useWorkerStatusGraph's setViewReady.
	const [ , setViewReady ] = useState( false );

	// Mount the graph once: command → view.
	useEffect( () => {
		const command = createHookCatalogCommand( COMMAND, {
			commandClient: commandClientRef.current,
		} );
		const view = createHookCatalogView( VIEW );
		command.sink = view;
		commandRef.current = command;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

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

	// Fire one fetch whenever the modal opens (re-fetches on every re-open).
	useEffect( () => {
		if ( isOpen && commandRef.current ) {
			commandRef.current.fetch();
		}
	}, [ isOpen ] );

	// Read the published model on the modal's behalf (keeps the modal presentational).
	const view = useNodeState( VIEW, 'view' );
	return {
		hooksByCategory: view?.hooksByCategory || {},
		loading: view?.loading || false,
	};
}
