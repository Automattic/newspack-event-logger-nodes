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
 * The chain collapsed in v0.x: the link targets the view directly. The old
 * `perferrors:route` classifier was dead (its `controlTarget` was never
 * reached — the substrate emits `KEY='connected'` AND snoops it off before it
 * reaches subscribers), and `perferrors:transform` was a one-line dispatch
 * that's now inline in the view's `fill()`.
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * graph build is handed to `mountExospine( build )`, which snapshots Core so the
 * soft nodes can be torn down + rebuilt on `reinit()` ("Reset Graph"). The
 * `connected → slot` bridge and slot keep-alive now live inside RemoteLink. The
 * page-visibility / pause effect drives `link.connect()` / `link.close()`.
 *
 * Returns the thin control callbacks the view calls — `setPaused` and
 * `clear`. These are dispatched HOOK-DIRECT to the view node
 * (`viewRef.current.fill`), an external bridge: they are NOT routed through
 * the graph.
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

	// Live node handles for the connection effect + control callbacks.
	const linkRef = useRef( null );
	const viewRef = useRef( null );

	// Paused state drives BOTH the view control (published for the button /
	// empty-state label) and the connection effect below (paused closes the SSE
	// stream).
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

			linkRef.current = link;
			viewRef.current = view;

			// On a reinit-while-paused, re-publish the surviving pause to the fresh
			// view so its `paused` flag matches the connection effect (which keeps
			// _sse closed while isPaused). No-op on first mount (isPaused=false).
			if ( isPausedRef.current ) {
				view.fill( controlMsg( { action: 'pause', paused: true } ) );
			}

			// Re-render so the connection effect re-runs against the fresh _sse and
			// useNodeState re-subscribes to the freshly-mounted view node.
			bumpBuild( ( n ) => n + 1 );

			// Tear down the RemoteLink (closes its stream + removes all three
			// children) before the exospine removes the rest.
			return () => {
				link.removeNode();
				linkRef.current = null;
				viewRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// Own the live SSE connection: open while visible AND not paused, else close.
	// link.close() also clears the heartbeat slot. Re-runs on every (re)build via
	// buildCount.
	useEffect( () => {
		const link = linkRef.current;
		if ( ! buildCount || ! link ) {
			return undefined;
		}
		if ( isPageVisible && ! isPaused ) {
			link.connect();
		} else {
			link.close();
		}
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

	// clear: empty the view buffer (matches ErrorLog's handleClear).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear' } ) );
		}
	};

	return { setPaused, clear };
}
