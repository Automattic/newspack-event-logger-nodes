/**
 * useErrorLogGraph — mounts the Error Log dashboard node graph (the JS-Node
 * conversion of the old ErrorLog data path) clipped onto the exospine (the
 * canonical rule-#2 backbone `_command_interpreter → _router`).
 *
 * Graph: `perferrors:stream` (SSE-in) → `perferrors:route` (classifier) →
 * `perferrors:transform` (envelope → row) and/or `perferrors:view` (view model).
 * EVERY node sinks into the CI; flow is steered ONLY by each node's `target` (the
 * router peels TO and delivers): the stream targets the route; the route stamps
 * data → the transform and connection-status control → the view; the transform
 * targets the view. There is no bespoke `sink`/`controlSink` wiring.
 *
 * A second effect owns the live connection: while the page is visible AND not
 * paused it subscribes the stream to the `errors` feed, otherwise it closes it
 * (mirrors ErrorLog's page-visibility / pause effect). The view publishes its
 * low-frequency model via `setState('view', …)`; the React view reads the
 * high-frequency buffer directly via `Core.node('perferrors:view')`.
 *
 * Returns the thin control callbacks the view calls — `setPaused` and `clear`.
 * These are dispatched HOOK-DIRECT to the view node (`viewRef.current.fill`), an
 * external bridge: they are NOT routed through the graph (only connection-status
 * is, via the route). Torn down on unmount: the stream is closed, the graph nodes
 * are unregistered, then the exospine.
 *
 * The transport boundary is injectable: tests pass `opts.connector` (the stream's
 * seam, mirroring perfErrorsStream) so the hook never touches a real EventSource.
 * Production lazily defaults the connector to the real-EventSource transport
 * inside `createPerfErrorsStream`.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	mountExospine,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createPerfErrorsStream } from '../nodes/perfErrorsStream';
import { createPerfErrorsRoute } from '../nodes/perfErrorsRoute';
import { createPerfErrorsTransform } from '../nodes/perfErrorsTransform';
import { createPerfErrorsView } from '../nodes/perfErrorsView';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// Every named node this graph mounts — unregistered on teardown (the exospine
// nodes are removed separately by its own teardown()). Names use a colon, not a
// slash: the router peels TO on '/', so a '/' in a node name would misroute.
const STREAM = 'perferrors:stream';
const ROUTE = 'perferrors:route';
const TRANSFORM = 'perferrors:transform';
const VIEW = 'perferrors:view';
const GRAPH_NODE_NAMES = [ STREAM, ROUTE, TRANSFORM, VIEW ];

// Build a TM_STRUCT control message the view's fill() routes on its `action`.
const controlMsg = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

/**
 * @param {Object} [opts]            Options (testing seams).
 * @param {Object} [opts.connector]  Stream transport seam (connect/close);
 *                                   defaults to the real-EventSource connector.
 * @param {number} [opts.maxEntries] View buffer cap (default 5000).
 * @return {{ setPaused: Function, clear: Function }} Control callbacks for the
 *   thin React view (the view's own state is read via useNodeState).
 */
export function useErrorLogGraph( opts = {} ) {
	// Stash the latest opts so the mount effect reads them without re-subscribing.
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live node handles for the connection effect + control callbacks.
	const streamRef = useRef( null );
	const viewRef = useRef( null );

	// Paused state drives BOTH the view control (published for the button/label)
	// and the connection effect below (paused closes the stream). Mirrors
	// ErrorLog's isPaused.
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Flipped true once the graph (and its view node) is mounted. The mount effect
	// runs AFTER the first render, by which point useNodeState has already captured
	// a null view node and bailed; setting this state forces the consumer to
	// re-render so useNodeState re-subscribes to the now-registered view node and
	// reads the published model. It is ALSO a dependency of the connection effect
	// below so that effect (which needs streamRef populated) runs once the mount
	// effect has built the graph.
	const [ viewReady, setViewReady ] = useState( false );

	// Mount the graph once onto the exospine: stream → route → transform → view.
	useEffect( () => {
		const { connector, maxEntries } = optsRef.current;

		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, teardown: teardownSpine } = mountExospine();

		// Build the graph nodes (the factories register them in Core).
		const stream = createPerfErrorsStream( STREAM, { connector } );
		const route = createPerfErrorsRoute( ROUTE, {
			dataTarget: TRANSFORM,
			controlTarget: VIEW,
		} );
		const transform = createPerfErrorsTransform( TRANSFORM );
		const view = createPerfErrorsView( VIEW, { maxEntries } );

		// Rule #2: every node sinks into the CI; flow is steered by `target`.
		stream.sink = ci;
		stream.target = ROUTE;
		route.sink = ci;
		transform.sink = ci;
		transform.target = VIEW;
		view.sink = ci;

		streamRef.current = stream;
		viewRef.current = view;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

		return () => {
			// Close the connection-owning stream first (its connector close is
			// idempotent), unregister the graph nodes, THEN tear the exospine down.
			stream.close();
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			streamRef.current = null;
			viewRef.current = null;
		};
	}, [] );

	// Own the live connection: subscribe while visible AND not paused, else close.
	// Re-runs when the graph mounts (viewReady) or visibility / paused flips.
	// Cleanup closes the stream (matches ErrorLog's `return () => closeSource()`).
	useEffect( () => {
		const stream = streamRef.current;
		if ( ! viewReady || ! stream ) {
			return undefined;
		}
		if ( isPageVisible && ! isPaused ) {
			stream.subscribe();
		} else {
			stream.close();
		}
		return () => stream.close();
	}, [ viewReady, isPageVisible, isPaused ] );

	// setPaused: flip the hook state (re-runs the connection effect) AND publish
	// the paused flag through the view so the button / empty-state label reflect it.
	const setPaused = ( paused ) => {
		setIsPaused( paused );
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'pause', paused } ) );
		}
	};

	// clear: empty the view buffer (matches ErrorLog's handleClear).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear' } ) );
		}
	};

	return { setPaused, clear };
}
