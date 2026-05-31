/**
 * useRequestLogGraph — mounts the Request Log dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's I/O boundary nodes — the same ones the topology console uses:
 *
 *   _sse        (SseInNode — EventSource ingress, args `'completed {restUrl} {nonce}'`)
 *   _http       (HttpOutNode — POST /command boundary; .client = CommandClient)
 *   _heartbeat  (HeartbeatNode — slot keep-alive; target = `_sse/workers`)
 *
 * Plus the single view node:
 *
 *   requestlog:view        (the view-model node the React view reads)
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * chain collapsed in May 2026 from `_sse → requestlog:route →
 * requestlog:transform → requestlog:view` to a direct `_sse → requestlog:view`.
 * The route node was a pass-through (the substrate's SseConnector snoops the
 * `connected` envelope off before routing, so the control branch was
 * unreachable), and the transform's defensive shaping (drop missing-url, clip
 * url@2000 + UA@500, default-fill) moved into the view's `_appendRow()` — the
 * single place that knows envelope → render-entry mapping.
 *
 * The graph build is handed to `mountExospine( build )`, which snapshots Core so
 * the soft nodes can be torn down + rebuilt on `reinit()` ("Reset Graph"). The
 * slot bridge mirrors topology-console's `useConsoleGraph.js`: a
 * `connected`-event subscriber on `_sse` reads `payload.slot` / `.partition` and
 * pushes them into `_heartbeat`. The page-visibility / pause effect drives
 * `sse.start()` / `sse.close()` (and `heartbeat.clearSlot()` on close).
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import {
	mountExospine,
	SseInNode,
	HttpOutNode,
	HeartbeatNode,
	CommandClient,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createRequestLogView } from '../nodes/request-log-view-node';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// The I/O boundary nodes mounted from the substrate runtime.
const SSE = '_sse';
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
// The view-model node the React view reads.
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
	const sseRef = useRef( null );
	const heartbeatRef = useRef( null );
	const viewRef = useRef( null );

	// Paused state drives BOTH the view control (published for the button/label)
	// and the connection effect below (paused closes the SSE stream).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mirror isPaused into a ref so build() (created once on mount) reads the
	// CURRENT pause when reinit re-runs it — the fresh view defaults paused:false.
	const isPausedRef = useRef( isPaused );
	isPausedRef.current = isPaused;

	// Bumped on every (re)build so the connection effect re-runs against the
	// fresh _sse and a consumer's useNodeState re-subscribes to the freshly-
	// registered view node. A monotonic counter, not a boolean latch — reinit()'s
	// second build must still force a render.
	const [ buildCount, bumpBuild ] = useState( 0 );

	// Mount the graph once onto the exospine.
	useEffect( () => {
		// The soft view-nodes the backbone clips onto. mountExospine snapshots
		// Core around this so reinit() removes exactly these and rebuilds them.
		const build = ( { interpreter, router } ) => {
			const { maxEntries } = optsRef.current;
			const data =
				( typeof window !== 'undefined' && window.NewspackNodesData ) ||
				{};

			// I/O boundary nodes — the same ones useConsoleGraph mounts.
			// SseConnector's three-token positional config: `subscribe baseUrl nonce`.
			const sse = new SseInNode();
			sse.arguments = `completed ${ data.restUrl || '/wp-json/' } ${
				data.nonce || ''
			}`;
			sse.setName( SSE );
			sse.sink = interpreter;
			sse.target = VIEW;

			const http = new HttpOutNode();
			http.client = new CommandClient( {
				baseUrl: data.restUrl || '/wp-json/',
				nonce: data.nonce || '',
			} );
			http.setName( HTTP );
			http.sink = interpreter;

			const heartbeat = new HeartbeatNode();
			heartbeat.setName( HEARTBEAT );
			heartbeat.sink = interpreter;
			// `_http/workers` — the SSE_Slot_Pool's `heartbeat` verb lives on the
			// request-scope `workers` CI. Bypass the _sse pid-pivot: the reply is
			// discarded by HeartbeatNode.fill anyway, so broadcast routing is fine.
			heartbeat.target = `${ HTTP }/workers`;

			// The view node — the single dashboard consumer of `_sse`.
			const view = createRequestLogView( VIEW, { maxEntries } );
			view.sink = interpreter;

			// Slot bridge: a `connected`-event subscriber on `_sse` pushes the live
			// slot into `_heartbeat`. Mirrors useConsoleGraph.js:157-175.
			sse.register( 'connected', 'useRequestLogGraph', ( payload ) => {
				const slot =
					payload && Number.isInteger( payload.slot )
						? payload.slot
						: null;
				const partition =
					payload && Number.isInteger( payload.partition )
						? payload.partition
						: -1;
				if ( null !== slot && slot >= 0 ) {
					heartbeat.setSlot( slot, partition );
				} else {
					heartbeat.clearSlot();
				}
				return true;
			} );

			// HeartbeatNode hitchhikes the backbone's TIMER (started in mountExospine).
			router.register( 'TIMER', HEARTBEAT, () => heartbeat.onTimer() );

			sseRef.current = sse;
			heartbeatRef.current = heartbeat;
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

			// Non-node side effects undone before the nodes are removed.
			return () => {
				heartbeat.clearSlot();
				sse.unregister( 'connected', 'useRequestLogGraph' );
				sse.close();
				sseRef.current = null;
				heartbeatRef.current = null;
				viewRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// Own the live SSE connection: open while visible AND not paused, else close.
	// On close, clear the heartbeat slot so timer firings (if any subscriber is
	// driving them) become no-ops. Re-runs on every (re)build via buildCount.
	useEffect( () => {
		const sse = sseRef.current;
		const heartbeat = heartbeatRef.current;
		if ( ! buildCount || ! sse ) {
			return undefined;
		}
		if ( isPageVisible && ! isPaused ) {
			sse.start();
		} else {
			sse.close();
			heartbeat?.clearSlot();
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

	// clear: empty the view buffer (matches RequestStream's handleClear).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill( controlMsg( { action: 'clear' } ) );
		}
	};

	return { setPaused, clear };
}
