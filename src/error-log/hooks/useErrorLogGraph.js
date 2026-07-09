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

// The single RemoteLink node, the inspectable stream Tee, and the view-model node.
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

	// Paused state drives BOTH the view control (published for the button /
	// empty-state label) and the shared connection lifecycle (paused closes the
	// SSE stream).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mirror isPaused into a ref so mountNodes (which reads it at (re)build time)
	// sees the CURRENT pause on a reinit — the fresh view defaults paused:false.
	const isPausedRef = useRef( isPaused );
	isPausedRef.current = isPaused;

	// The shared lifecycle owns close-while-hidden/paused + resume-on-refocus. We
	// supply the graph and a plain connect: the FIRST connect of a link live-follows
	// (null = tail), a RECONNECT resumes from the last seen offset so the gap
	// accumulated while hidden/paused streams in instead of tail-dropping.
	const { viewRef } = useVisibilityGatedLink( {
		mountNodes: ( interpreter ) => {
			const { maxEntries } = optsRef.current;
			const data =
				( typeof window !== 'undefined' && window.NewspackNodesData ) ||
				{};

			const baseUrl = data.restUrl || '/wp-json/';
			const nonce = data.nonce || '';

			// ONE RemoteLink composes the SseIn + HttpOut + Heartbeat children and
			// the `connected → slot` bridge. SseConnector's three-token positional
			// config: `subscribe baseUrl nonce`.
			const link = interpreter.makeNode(
				'RemoteLink',
				LINK,
				`errors ${ baseUrl } ${ nonce }`
			);
			// A pure pass-through Tee on the stream edge: the link re-homes received
			// frames to it, it copies each to the view. `connect perferrors:stream` in
			// the debug overlay appends a second target to inspect the live stream.
			link.target = TEE;
			link.client = new CommandClient( { baseUrl, nonce } );

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// The view-model — shapes raw envelopes into rows inline.
			const view = interpreter.makeNode( 'PerfErrorsView', VIEW );
			if ( maxEntries ) {
				view.maxEntries = maxEntries;
			}

			// On a reinit-while-paused, re-publish the surviving pause to the fresh
			// view so its `paused` flag matches the connection lifecycle (which keeps
			// the stream closed while isPaused). No-op on first mount (isPaused=false).
			if ( isPausedRef.current ) {
				view.fill( controlMsg( { action: 'pause', paused: true } ) );
			}

			return { link, view };
		},
		isActive: isPageVisible && ! isPaused,
		onConnect: ( link, { isReconnect } ) =>
			link.connect( isReconnect ? link.resumePositions() : null ),
	} );

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
