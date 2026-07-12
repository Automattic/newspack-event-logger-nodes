/**
 * useErrorLogGraph — mounts the Error Log dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using a SINGLE
 * substrate `RemoteLink` node instead of hand-wiring three I/O boundary nodes:
 *
 *   perferrors:link  (RemoteLink — composes + registers three children:
 *                     `perferrors:link:sse-in` (SseIn — EventSource ingress),
 *                     `perferrors:link:http` (HttpOut — POST /command boundary),
 *                     `perferrors:link:heartbeat` (Heartbeat — slot keep-alive),
 *                     and wires the `connected → slot` bridge to its own
 *                     heartbeat. `.client` is the injected CommandClient.)
 *
 * Plus the single dashboard node — the view-model:
 *
 *   perferrors:view  (the view-model node the React view reads)
 *
 * The link targets the view directly; the view's `fill()` shapes envelopes
 * into rows inline.
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * graph + connection lifecycle are handed to the shared `useVisibilityGatedLink` hook:
 * it mounts via `mountExospine` (snapshotting Core so the soft nodes tear down +
 * rebuild on `reinit()` — "Reset Graph"), closes the stream while hidden or paused,
 * and RECONNECTS from the last seen offset on refocus. The `connected → slot` bridge
 * and slot keep-alive live inside RemoteLink.
 *
 * Returns the thin control callbacks the view calls — `setPaused` and
 * `clear`. These are dispatched HOOK-DIRECT to the view node
 * (`viewRef.current.fill`), an external bridge: they are NOT routed through
 * the graph.
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
const LINK = 'perferrors:link';
const TEE = 'perferrors:stream';
const VIEW = 'perferrors:view';

// Build a TM_STRUCT control message the view's fill() routes on its `action`.
const controlMsg = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

/**
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View buffer cap (default 5000).
 * @return {{ setPaused: Function, clear: Function }} Control callbacks for the
 *   thin React view (the view's own state is read via useNodeState). Reset Graph
 *   is driven by the overlay via `Core.reinit`, stashed by mountExospine.
 */
export function useErrorLogGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Paused drives the view control and the connection lifecycle (closes SSE).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mirror isPaused into a ref so reinit mountNodes sees current pause.
	const isPausedRef = useRef( isPaused );
	isPausedRef.current = isPaused;

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
				`errors.* ${ baseUrl } ${ nonce }`
			);
			// Pass-through Tee on the stream edge; copies each frame to view.
			link.target = TEE;
			link.client = new CommandClient( { baseUrl, nonce } );

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// The view-model — shapes raw envelopes into rows inline.
			const view = interpreter.makeNode( 'PerfErrorsView', VIEW );
			if ( maxEntries ) {
				view.maxEntries = maxEntries;
			}

			// Re-publish a surviving pause to the fresh view on reinit.
			if ( isPausedRef.current ) {
				view.fill( controlMsg( { action: 'pause', paused: true } ) );
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

	// clear: empty the view buffer (matches ErrorLog's handleClear).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear' } ) );
		}
	};

	return { setPaused, clear };
}
