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
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * chain collapsed in May 2026 from `_sse → requestlog:route →
 * requestlog:transform → requestlog:view` to a direct sse-in → requestlog:view.
 * The route node was a pass-through (the substrate's SseConnector snoops the
 * `connected` envelope off before routing, so the control branch was
 * unreachable), and the transform's defensive shaping (drop missing-url, clip
 * url@2000 + UA@500, default-fill) moved into the view's `_appendRow()` — the
 * single place that knows envelope → render-entry mapping.
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

// The single RemoteLink node, the inspectable stream Tee, and the view-model node.
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
 * @return {{ setPaused: Function, clear: Function }} Control callbacks for the
 *   thin React view (the view's own state is read via useNodeState). Reset Graph
 *   is driven by the overlay via `Core.reinit`, stashed by mountExospine.
 */
export function useRequestLogGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Paused state drives BOTH the view control (published for the button/label)
	// and the shared connection lifecycle (paused closes the SSE stream).
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
			// the `connected → slot` bridge. The positional `arguments` carry the
			// fixed `completed` subscribe plus baseUrl/nonce.
			const link = interpreter.makeNode(
				'RemoteLink',
				LINK,
				`completed ${ baseUrl } ${ nonce }`
			);
			// A pure pass-through Tee on the stream edge: the link re-homes received
			// frames to it, it copies each to the view. `connect requestlog:stream` in
			// the debug overlay appends a second target to inspect the live stream.
			link.target = TEE;
			link.client = new CommandClient( { baseUrl, nonce } );

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// The view node — the single dashboard consumer of the stream.
			const view = interpreter.makeNode( 'RequestLogView', VIEW );
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

	// clear: empty the view buffer (matches RequestStream's handleClear).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear' } ) );
		}
	};

	return { setPaused, clear };
}
