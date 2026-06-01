/**
 * useGyroscopeGraph — mounts the Gyroscope dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's I/O boundary nodes — the same ones request-log + topology console
 * use:
 *
 *   _sse        (SseInNode — EventSource ingress, args `'gyroscope {restUrl} {nonce}'`)
 *   _http       (HttpOutNode — POST /command boundary; .client = CommandClient)
 *   _heartbeat  (HeartbeatNode — slot keep-alive; target = `_http/workers`)
 *
 * Plus the single dashboard node:
 *
 *   gyroscope:view        (the in-flight model the React view samples; consumes
 *                          wire envelopes directly — KEY/VALUE dispatch inlined)
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * bespoke `gyroscope:stream` Node and its inlined slot-heartbeat loop are
 * gone — `_sse` owns the EventSource, `_heartbeat` owns the slot poke. The
 * `gyroscope:route` + `gyroscope:transform` hops were collapsed into
 * `gyroscope:view.fill()` directly: route was dead (KEY='connection' check,
 * substrate uses 'connected' AND snoops it off before routing) and transform
 * was just an envelope-shape dispatcher the view can now do itself.
 *
 * The graph build is handed to `mountExospine( build )`, which snapshots Core so
 * the soft nodes can be torn down + rebuilt on `reinit()` ("Reset Graph"). The
 * slot bridge mirrors useRequestLogGraph: a `connected`-event subscriber on
 * `_sse` reads `payload.slot` / `.partition` and pushes them into `_heartbeat`.
 * The page-visibility effect drives `sse.start()` / `sse.close()` (and
 * `heartbeat.clearSlot()` on close). On each (re)connect the view map is reset
 * (mirrors the legacy Inflight `onBeforeConnect` reset).
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
import { createGyroscopeView } from '../nodes/gyroscope-view-node';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// The I/O boundary nodes mounted from the substrate runtime.
const SSE = '_sse';
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
// The dashboard node.
const VIEW = 'gyroscope:view';

// Build a TM_STRUCT control message the view's fill() routes on its `action`.
const controlMsg = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

/**
 * @return {Object} Empty — the view reads its model via useNodeState +
 *   Core.node(VIEW).snapshot(); the gyroscope dashboard has no control callbacks.
 *   Reset Graph is driven by the overlay via `Core.reinit`, stashed by mountExospine.
 */
export function useGyroscopeGraph() {
	// Live node handles for the connection effect.
	const sseRef = useRef( null );
	const heartbeatRef = useRef( null );
	const viewRef = useRef( null );

	const isPageVisible = usePageVisibility();

	// Bumped on every (re)build so the connection effect re-runs against the
	// fresh _sse and a consumer's useNodeState re-subscribes to the freshly-
	// registered view node. A monotonic counter, not a boolean latch — reinit()'s
	// second build must still force a render.
	const [ buildCount, bumpBuild ] = useState( 0 );

	// Mount the graph once onto the exospine.
	useEffect( () => {
		// The soft view-nodes the backbone clips onto. mountExospine snapshots
		// Core around this so reinit() removes exactly these and rebuilds them.
		const build = ( { interpreter } ) => {
			const data =
				( typeof window !== 'undefined' && window.NewspackNodesData ) ||
				{};

			// I/O boundary nodes — the same ones useRequestLogGraph + useConsoleGraph mount.
			// SseConnector's three-token positional config: `subscribe baseUrl nonce`.
			const sse = new SseInNode();
			sse.arguments = `gyroscope ${ data.restUrl || '/wp-json/' } ${
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

			// Dashboard view — consumes wire envelopes directly.
			const view = createGyroscopeView( VIEW );
			view.sink = interpreter;

			// Slot bridge: a `connected`-event subscriber on `_sse` pushes the live
			// slot into `_heartbeat`. Mirrors useRequestLogGraph.js / useConsoleGraph.js.
			sse.register( 'connected', 'useGyroscopeGraph', ( payload ) => {
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

			// HeartbeatNode hitchhikes the backbone's TIMER (set_timer() with no args):
			// the _router's notify_timer calls heartbeat.fireCb -> fire each tick.
			heartbeat.setTimer();

			sseRef.current = sse;
			heartbeatRef.current = heartbeat;
			viewRef.current = view;

			// Re-render so the connection effect re-runs against the fresh _sse and
			// useNodeState re-subscribes to the freshly-mounted view node.
			bumpBuild( ( n ) => n + 1 );

			// Non-node side effects undone before the nodes are removed.
			return () => {
				heartbeat.clearSlot();
				sse.unregister( 'connected', 'useGyroscopeGraph' );
				sse.close();
				sseRef.current = null;
				heartbeatRef.current = null;
				viewRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// Own the live SSE connection: open while visible, else close. On (re)connect
	// clear the view map first (mirrors Inflight's onBeforeConnect reset). On
	// close, clear the heartbeat slot so timer firings become no-ops. Re-runs on
	// every (re)build via buildCount.
	useEffect( () => {
		const sse = sseRef.current;
		const heartbeat = heartbeatRef.current;
		if ( ! buildCount || ! sse ) {
			return undefined;
		}
		if ( isPageVisible ) {
			if ( viewRef.current ) {
				viewRef.current.fill( controlMsg( { action: 'clear' } ) );
			}
			sse.start();
		} else {
			sse.close();
			heartbeat?.clearSlot();
		}
		return undefined;
	}, [ buildCount, isPageVisible ] );

	return {};
}
