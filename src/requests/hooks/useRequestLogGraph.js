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
 * The graph build is handed to `mountExospine( build )`, which snapshots Core so
 * the soft nodes can be torn down + rebuilt on `reinit()` ("Reset Graph"). The
 * `connected → slot` bridge now lives inside RemoteLink. The page-visibility /
 * pause effect drives `link.connect()` / `link.close()`.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import {
	mountExospine,
	CommandClient,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import '../nodes/register';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';

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

	// Live node handles for the connection effect + control callbacks.
	const linkRef = useRef( null );
	const viewRef = useRef( null );

	// First connect of a link live-follows; a RECONNECT of the SAME link (hide→show,
	// unpause) resumes from the last seen offset so the hidden gap streams instead of
	// tail-dropping it. `connectedLinkRef` records which link is currently streaming
	// so a re-render never tears a live seek into a tail reconnect; `hasConnectedRef`
	// (reset per build — a rebuilt link's SseIn has no tracked offset) picks tail-vs-resume.
	const hasConnectedRef = useRef( false );
	const connectedLinkRef = useRef( null );

	// Paused state drives BOTH the view control (published for the button/label)
	// and the connection effect below (paused closes the SSE stream).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mirror isPaused into a ref so build() (created once on mount) reads the
	// CURRENT pause when reinit re-runs it — the fresh view defaults paused:false.
	const isPausedRef = useRef( isPaused );
	isPausedRef.current = isPaused;

	// Bumped on every (re)build so the connection effect re-runs against the
	// fresh link and a consumer's useNodeState re-subscribes to the freshly-
	// registered view node. A monotonic counter, not a boolean latch — reinit()'s
	// second build must still force a render.
	const [ buildCount, bumpBuild ] = useState( 0 );

	// Mount the graph once onto the exospine.
	useEffect( () => {
		// The soft view-nodes the backbone clips onto. mountExospine snapshots
		// Core around this so reinit() removes exactly these and rebuilds them.
		const build = ( { interpreter } ) => {
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

			linkRef.current = link;
			viewRef.current = view;
			// A fresh link's SseIn has no tracked offset — first connect live-follows.
			hasConnectedRef.current = false;

			// On a reinit-while-paused, re-publish the surviving pause to the fresh
			// view so its `paused` flag matches the connection effect (which keeps
			// the stream closed while isPaused). No-op on first mount (isPaused=false).
			if ( isPausedRef.current ) {
				view.fill( controlMsg( { action: 'pause', paused: true } ) );
			}

			// Re-render so the connection effect re-runs against the fresh link and
			// useNodeState re-subscribes to the freshly-mounted view node.
			bumpBuild( ( n ) => n + 1 );

			// Tear down the RemoteLink (closes its stream + removes all three
			// children) before the exospine removes the rest.
			return () => {
				link.removeNode();
				linkRef.current = null;
				viewRef.current = null;
				connectedLinkRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// Own the live SSE connection: open while visible AND not paused, else close.
	// link.close() forgets the slot so timer firings become no-ops. Re-runs on
	// every (re)build via buildCount.
	useEffect( () => {
		const link = linkRef.current;
		if ( ! buildCount || ! link ) {
			return undefined;
		}
		if ( ! isPageVisible || isPaused ) {
			link.close();
			connectedLinkRef.current = null;
			return undefined;
		}
		// Already streaming this exact link — a re-render must NOT tear the seek
		// down into a tail reconnect.
		if ( connectedLinkRef.current === link ) {
			return undefined;
		}
		// First connect live-follows; a reconnect resumes from the last seen offset
		// so the gap accumulated while hidden/paused streams instead of tail-dropping.
		const positions = hasConnectedRef.current
			? link.resumePositions()
			: null;
		hasConnectedRef.current = true;
		connectedLinkRef.current = link;
		link.connect( positions );
		return undefined;
	}, [ buildCount, isPageVisible, isPaused ] );

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
