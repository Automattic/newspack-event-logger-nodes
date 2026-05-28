/**
 * useGyroscopeGraph — mounts the Gyroscope dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's I/O boundary nodes — the same ones request-log + topology console
 * use:
 *
 *   _sse        (SseIn — EventSource ingress, args `'gyroscope {restUrl} {nonce}'`)
 *   _http       (HttpOut — POST /command boundary; .client = CommandClient)
 *   _heartbeat  (Heartbeat — slot keep-alive; target = `_http/workers`)
 *
 * Plus the existing dashboard chain (unchanged factories, only retargeted):
 *
 *   gyroscope:route       (data → transform, control → view)
 *   gyroscope:transform   (target = view)
 *   gyroscope:view        (the in-flight model the React view samples)
 *
 * Every node sinks into the CI; flow is steered by each node's `target`. The
 * bespoke `gyroscope:stream` Node and its inlined slot-heartbeat loop are
 * gone — `_sse` owns the EventSource, `_heartbeat` owns the slot poke.
 *
 * The slot bridge mirrors useRequestLogGraph: a `connected`-event subscriber on
 * `_sse` reads `payload.slot` / `.partition` and pushes them into `_heartbeat`.
 * The page-visibility effect drives `sse.start()` / `sse.close()` (and
 * `heartbeat.clearSlot()` on close). On each (re)connect the view map is reset
 * (mirrors the legacy Inflight `onBeforeConnect` reset).
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	mountExospine,
	SseIn,
	HttpOut,
	Heartbeat,
	CommandClient,
	TYPE,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createGyroscopeRoute } from '../nodes/gyroscopeRoute';
import { createGyroscopeTransform } from '../nodes/gyroscopeTransform';
import { createGyroscopeView } from '../nodes/gyroscopeView';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// The I/O boundary nodes mounted from the substrate runtime.
const SSE = '_sse';
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
// The dashboard chain.
const ROUTE = 'gyroscope:route';
const TRANSFORM = 'gyroscope:transform';
const VIEW = 'gyroscope:view';
// Every named node this graph mounts — unregistered on teardown (exospine
// nodes are removed separately by `teardownSpine()`).
const GRAPH_NODE_NAMES = [ SSE, HTTP, HEARTBEAT, ROUTE, TRANSFORM, VIEW ];

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
 */
export function useGyroscopeGraph() {
	// Live node handles for the connection effect.
	const sseRef = useRef( null );
	const heartbeatRef = useRef( null );
	const viewRef = useRef( null );

	const isPageVisible = usePageVisibility();

	// Flipped true once the graph (and its view node) is mounted, so the
	// connection effect runs once the mount effect has built the graph and so a
	// consumer using useNodeState re-subscribes to the now-registered view node.
	const [ viewReady, setViewReady ] = useState( false );

	// Mount the graph once onto the exospine.
	useEffect( () => {
		const data =
			( typeof window !== 'undefined' && window.NewspackNodesData ) || {};

		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, router, teardown: teardownSpine } = mountExospine();

		// I/O boundary nodes — the same ones useRequestLogGraph + useConsoleGraph mount.
		// SseConnector's three-token positional config: `subscribe baseUrl nonce`.
		const sse = new SseIn();
		sse.arguments = `gyroscope ${ data.restUrl || '/wp-json/' } ${
			data.nonce || ''
		}`;
		sse.setName( SSE );
		sse.sink = ci;
		sse.target = ROUTE;

		const http = new HttpOut();
		http.client = new CommandClient( {
			baseUrl: data.restUrl || '/wp-json/',
			nonce: data.nonce || '',
		} );
		http.setName( HTTP );
		http.sink = ci;

		const heartbeat = new Heartbeat();
		heartbeat.setName( HEARTBEAT );
		heartbeat.sink = ci;
		// `_http/workers` — the SSE_Slot_Pool's `heartbeat` verb lives on the
		// request-scope `workers` CI. Bypass the _sse pid-pivot: the reply is
		// discarded by Heartbeat.fill anyway, so broadcast routing is fine.
		heartbeat.target = `${ HTTP }/workers`;

		// Dashboard chain — unchanged factories.
		const route = createGyroscopeRoute( ROUTE, {
			dataTarget: TRANSFORM,
			controlTarget: VIEW,
		} );
		const transform = createGyroscopeTransform( TRANSFORM );
		const view = createGyroscopeView( VIEW );
		route.sink = ci;
		transform.sink = ci;
		transform.target = VIEW;
		view.sink = ci;

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

		// Heartbeat hitchhikes the backbone's TIMER (started in mountExospine).
		router.register( 'TIMER', HEARTBEAT, () => heartbeat.onTimer() );

		sseRef.current = sse;
		heartbeatRef.current = heartbeat;
		viewRef.current = view;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

		return () => {
			heartbeat.clearSlot();
			sse.unregister( 'connected', 'useGyroscopeGraph' );
			sse.close();
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			sseRef.current = null;
			heartbeatRef.current = null;
			viewRef.current = null;
		};
	}, [] );

	// Own the live SSE connection: open while visible, else close. On (re)connect
	// clear the view map first (mirrors Inflight's onBeforeConnect reset). On
	// close, clear the heartbeat slot so timer firings become no-ops.
	useEffect( () => {
		const sse = sseRef.current;
		const heartbeat = heartbeatRef.current;
		if ( ! viewReady || ! sse ) {
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
	}, [ viewReady, isPageVisible ] );

	return {};
}
