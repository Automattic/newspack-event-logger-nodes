/**
 * useRequestLogGraph — mounts the Request Log dashboard node graph (the JS-Node
 * conversion of the old RequestStream component). On mount it builds three nodes —
 * `requestlog/stream` (SSE-in), `requestlog/transform` (envelope → row),
 * `requestlog/view` (view model) — and wires the data path stream → transform →
 * view, plus `stream.controlSink = view` so connection-status controls reach the
 * view directly (the transform would drop them). A second effect owns the live
 * connection: while the page is visible AND not paused it subscribes the stream to
 * the `completed` feed, otherwise it closes it (this mirrors RequestStream's
 * page-visibility / pause effect). The view publishes its state via
 * `setState('view', …)`; the React view reads it separately with
 * `useNodeState('requestlog/view','view')`.
 *
 * Returns the thin control callbacks the view calls — `setPaused` (flips the
 * hook's paused state, which the view's control fill + the connection effect both
 * key off) and `clear` (empties the view buffer). Torn down on unmount: the stream
 * is closed, then all three nodes are unregistered from Core.
 *
 * The transport boundary is injectable: tests pass `opts.connector` (the stream's
 * seam, mirroring requestLogStream) so the hook never touches a real EventSource.
 * Production lazily defaults the connector to the real-EventSource transport
 * inside `createRequestLogStream`.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createRequestLogStream } from '../nodes/requestLogStream';
import { createRequestLogTransform } from '../nodes/requestLogTransform';
import { createRequestLogView } from '../nodes/requestLogView';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// Every named node this graph mounts — unregistered on teardown.
const STREAM = 'requestlog/stream';
const TRANSFORM = 'requestlog/transform';
const VIEW = 'requestlog/view';
const GRAPH_NODE_NAMES = [ STREAM, TRANSFORM, VIEW ];

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
 * @param {number} [opts.maxEntries] View buffer cap (default 1000).
 * @return {{ setPaused: Function, clear: Function }} Control callbacks for the
 *   thin React view (the view's own state is read via useNodeState).
 */
export function useRequestLogGraph( opts = {} ) {
	// Stash the latest opts so the mount effect reads them without re-subscribing.
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live node handles for the connection effect + control callbacks.
	const streamRef = useRef( null );
	const viewRef = useRef( null );

	// Paused state drives BOTH the view control (published for the button/label)
	// and the connection effect below (paused closes the stream). Mirrors
	// RequestStream's isPaused.
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Flipped true once the graph (and its view node) is mounted. The mount effect
	// runs AFTER the first render, by which point useNodeState has already captured
	// a null view node and bailed; setting this state forces the consumer to
	// re-render so useNodeState re-subscribes to the now-registered view node and
	// reads the published model. Without it the dashboard's pause button / empty-
	// state stays stuck on the initial fallback (RequestStream's rAF drives the
	// canvas, NOT useNodeState, so it can't mask the gap). Mirrors
	// useWorkerStatusGraph's setViewReady. It is ALSO a dependency of the
	// connection effect below so that effect (which needs streamRef populated)
	// runs once the mount effect has built the graph.
	const [ viewReady, setViewReady ] = useState( false );

	// Mount the graph once: stream → transform → view (+ stream.controlSink = view).
	useEffect( () => {
		const { connector, maxEntries } = optsRef.current;
		const stream = createRequestLogStream( STREAM, { connector } );
		const transform = createRequestLogTransform( TRANSFORM );
		const view = createRequestLogView( VIEW, { maxEntries } );
		stream.sink = transform;
		transform.sink = view;
		// Connection-status controls bypass the transform (which would drop them).
		stream.controlSink = view;
		streamRef.current = stream;
		viewRef.current = view;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

		return () => {
			// Close the connection-owning stream first (its connector close is
			// idempotent), THEN unregister — mirrors useRawLogsGraph's
			// stream.close() before unregister.
			stream.close();
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			streamRef.current = null;
			viewRef.current = null;
		};
	}, [] );

	// Own the live connection: subscribe while visible AND not paused, else close.
	// Re-runs when the graph mounts (viewReady) or visibility / paused flips.
	// Cleanup closes the stream (matches RequestStream's `return () => closeSource()`).
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

	// clear: empty the view buffer (matches RequestStream's handleClear).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear' } ) );
		}
	};

	return { setPaused, clear };
}
