/**
 * useGyroscopeGraph — mounts the Gyroscope dashboard node graph (the JS-Node
 * conversion of the old Inflight component) clipped onto the exospine (the
 * canonical rule-#2 backbone `_command_interpreter → _router`).
 *
 * Graph: `gyroscope:stream` (SSE-in) → `gyroscope:route` (classifier) →
 * `gyroscope:transform` (envelope → dispatch) and/or `gyroscope:view` (in-flight
 * model). EVERY node sinks into the CI; flow is steered ONLY by each node's
 * `target` (the router peels TO and delivers): the stream targets the route; the
 * route stamps data → the transform and connection-status control → the view; the
 * transform targets the view. There is no bespoke `sink`/`controlSink` wiring.
 *
 * A second effect owns the live connection: while the page is visible it resets
 * the view map and subscribes the stream to the `gyroscope` feed, otherwise it
 * closes it (this mirrors Inflight's page-visibility connect/close effect + its
 * `onBeforeConnect` map reset). The view publishes its low-frequency model via
 * `setState('view', …)`; the React view reads the high-frequency in-flight model
 * directly via `Core.node('gyroscope:view').snapshot()` each refresh tick.
 *
 * Torn down on unmount: the stream is closed, the graph nodes are unregistered,
 * then the exospine.
 *
 * The transport boundary is injectable: tests pass `opts.connector` (the stream's
 * seam, mirroring gyroscopeStream) so the hook never touches a real EventSource.
 * Production lazily defaults the connector to the real-EventSource transport
 * inside `createGyroscopeStream`.
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
import { createGyroscopeStream } from '../nodes/gyroscopeStream';
import { createGyroscopeRoute } from '../nodes/gyroscopeRoute';
import { createGyroscopeTransform } from '../nodes/gyroscopeTransform';
import { createGyroscopeView } from '../nodes/gyroscopeView';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// Every named node this graph mounts — unregistered on teardown (the exospine
// nodes are removed separately by its own teardown()).
const STREAM = 'gyroscope:stream';
const ROUTE = 'gyroscope:route';
const TRANSFORM = 'gyroscope:transform';
const VIEW = 'gyroscope:view';
const GRAPH_NODE_NAMES = [ STREAM, ROUTE, TRANSFORM, VIEW ];

// Build a TM_STRUCT control message the view's fill() routes on its `action`.
const controlMsg = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

/**
 * @param {Object} [opts]           Options (testing seams).
 * @param {Object} [opts.connector] Stream transport seam (connect/close);
 *                                  defaults to the real-EventSource connector.
 * @return {Object} Empty — the view reads its model via useNodeState +
 *   Core.node(VIEW).snapshot(); the gyroscope dashboard has no control callbacks.
 */
export function useGyroscopeGraph( opts = {} ) {
	// Stash the latest opts so the mount effect reads them without re-subscribing.
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live node handles for the connection effect.
	const streamRef = useRef( null );
	const viewRef = useRef( null );

	const isPageVisible = usePageVisibility();

	// Flipped true once the graph (and its view node) is mounted. The mount effect
	// runs AFTER the first render, by which point useNodeState has already captured
	// a null view node and bailed; setting this state forces the consumer to
	// re-render so useNodeState re-subscribes to the now-registered view node.
	// Without it the dashboard stays stuck on the initial fallback. Mirrors
	// useRequestLogGraph's viewReady. It is ALSO a dependency of the connection
	// effect below so that effect (which needs streamRef populated) runs once the
	// mount effect has built the graph.
	const [ viewReady, setViewReady ] = useState( false );

	// Mount the graph once onto the exospine: stream → route → transform → view.
	useEffect( () => {
		const { connector } = optsRef.current;

		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, teardown: teardownSpine } = mountExospine();

		// Build the graph nodes (the factories register them in Core).
		const stream = createGyroscopeStream( STREAM, { connector } );
		const route = createGyroscopeRoute( ROUTE, {
			dataTarget: TRANSFORM,
			controlTarget: VIEW,
		} );
		const transform = createGyroscopeTransform( TRANSFORM );
		const view = createGyroscopeView( VIEW );

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

	// Own the live connection: subscribe while visible, else close. Re-runs when
	// the graph mounts (viewReady) or visibility flips. On (re)connect it clears
	// the view map first (mirrors Inflight's onBeforeConnect reset). Cleanup closes
	// the stream (matches Inflight's `return () => closeSource()`).
	useEffect( () => {
		const stream = streamRef.current;
		if ( ! viewReady || ! stream ) {
			return undefined;
		}
		if ( isPageVisible ) {
			if ( viewRef.current ) {
				viewRef.current.fill( controlMsg( { action: 'clear' } ) );
			}
			stream.subscribe();
		} else {
			stream.close();
		}
		return () => stream.close();
	}, [ viewReady, isPageVisible ] );

	return {};
}
