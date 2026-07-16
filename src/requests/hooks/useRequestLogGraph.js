/**
 * useRequestLogGraph — mounts the Request Log dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using a SINGLE
 * substrate `RemoteLink` node instead of hand-wiring three I/O boundary nodes:
 *
 *   requestlog:link        (RemoteLink — composes + registers three children:
 *                          `requestlog:link:sse-in` (SseIn — EventSource ingress,
 *                          args `'completed {restUrl} {nonce}'`),
 *                          `requestlog:link:http` (HttpOut — POST /command boundary),
 *                          `requestlog:link:heartbeat` (Heartbeat — slot keep-alive),
 *                          and wires the `connected → slot` bridge to its own
 *                          heartbeat. `.client` is the injected CommandClient.)
 *
 * Plus the single view node:
 *
 *   requestlog:view        (the view-model node the React view reads)
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`.
 * The graph is a direct sse-in → requestlog:view. The view's `_appendRow()` does
 * the defensive shaping (drop missing-url, clip url@2000 + UA@500, default-fill) —
 * the single place that knows envelope → render-entry mapping.
 *
 * The graph + connection lifecycle are handed to the shared
 * `useVisibilityGatedLink` hook: it mounts via `mountExospine` (snapshotting Core so
 * the soft nodes tear down + rebuild on `reinit()` — "Reset Graph"), closes the
 * stream while hidden or paused, and RECONNECTS from the last seen offset on refocus.
 * The `connected → slot` bridge lives inside RemoteLink.
 */

import { useRef, useState } from '@wordpress/element';
import {
	CommandClient,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import '../nodes/register';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { useVisibilityGatedLink } from '@newspack-nodes/shared/hooks/useVisibilityGatedLink';

// The RemoteLink node, the inspectable stream Tee, and the view-model node.
const LINK = 'requestlog:link';
const TEE = 'requestlog:stream';
const VIEW = 'requestlog:view';

// Build a TM_STRUCT control message the view's fill() routes on its `action`.
const controlMsg = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

/**
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View buffer cap (default 1000).
 * @return {{ setPaused: Function, clear: Function, setFilter: Function }}
 *   Control callbacks for the thin React view (the view's own state is read via
 *   useNodeState). Reset Graph is driven by the overlay via `Core.reinit`,
 *   stashed by mountExospine.
 */
export function useRequestLogGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Paused drives the view control and the connection lifecycle (closes SSE).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mirror isPaused into a ref so reinit mountNodes sees current pause.
	const isPausedRef = useRef( isPaused );
	isPausedRef.current = isPaused;
	const filterRef = useRef( '' );

	// First connect tails; a reconnect resumes from last offset (no gap-drop).
	const { viewRef } = useVisibilityGatedLink( {
		mountNodes: ( interpreter ) => {
			const { maxEntries } = optsRef.current;
			const data =
				( typeof window !== 'undefined' && window.NewspackNodesData ) ||
				{};
			const baseUrl = data.restUrl || '/wp-json/';
			const nonce = data.nonce || '';

			// RemoteLink composes SseIn + HttpOut + Heartbeat children.
			const link = interpreter.makeNode(
				'RemoteLink',
				LINK,
				`completed.* ${ baseUrl } ${ nonce }`
			);
			// Pass-through Tee on the stream edge; copies each frame to view.
			link.target = TEE;
			link.client = CommandClient.fromGlobal();

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// The view node — the single dashboard consumer of the stream.
			const view = interpreter.makeNode( 'RequestLogView', VIEW );
			if ( maxEntries ) {
				view.maxEntries = maxEntries;
			}

			// Re-publish a surviving pause to the fresh view on reinit.
			if ( isPausedRef.current ) {
				view.fill( controlMsg( { action: 'pause', paused: true } ) );
			}
			if ( filterRef.current ) {
				view.fill(
					controlMsg( {
						action: 'filter',
						filter: filterRef.current,
					} )
				);
			}

			return { link, view };
		},
		isActive: isPageVisible && ! isPaused,
		onConnect: ( link, { isReconnect } ) =>
			link.connect( isReconnect ? link.resumePositions() : null ),
	} );

	// setPaused: flip hook state (re-runs effect) and publish it to view.
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

	const setFilter = ( filter ) => {
		if ( 'string' !== typeof filter ) {
			throw new TypeError( 'request log filter must be a string' );
		}
		filterRef.current = filter;
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'filter', filter } ) );
		}
	};

	return { setPaused, clear, setFilter };
}
