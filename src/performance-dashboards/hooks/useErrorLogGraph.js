/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * useErrorLogGraph — mounts the Error Log dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's I/O boundary nodes — the same ones useRequestLogGraph mounts:
 *
 *   _sse        (SseIn — EventSource ingress, args `'errors {restUrl} {nonce}'`)
 *   _http       (HttpOut — POST /command boundary; .client = CommandClient)
 *   _heartbeat  (Heartbeat — slot keep-alive; target = `_http/workers`)
 *
 * Plus the existing dashboard chain (unchanged factories, only retargeted):
 *
 *   perferrors:route       (data → transform, control → view)
 *   perferrors:transform   (target = view)
 *   perferrors:view        (the view-model node the React view reads)
 *
 * Every node sinks into the CI; flow is steered by each node's `target`. The
 * bespoke `perferrors:stream` Node and its inlined slot-heartbeat loop are
 * gone — `_sse` owns the EventSource, `_heartbeat` owns the slot poke.
 *
 * The slot bridge mirrors useRequestLogGraph (and useConsoleGraph): a
 * `connected`-event subscriber on `_sse` reads `payload.slot` / `.partition`
 * and pushes them into `_heartbeat`. The page-visibility / pause effect drives
 * `sse.start()` / `sse.close()` (and `heartbeat.clearSlot()` on close).
 *
 * Returns the thin control callbacks the view calls — `setPaused` and
 * `clear`. These are dispatched HOOK-DIRECT to the view node
 * (`viewRef.current.fill`), an external bridge: they are NOT routed through
 * the graph (only connection-status would be, via the route — and `_sse` no
 * longer synthesizes a connection-status control, so that vestigial route is
 * dormant). Torn down on unmount: the SSE source is closed, the graph nodes
 * are unregistered, then the exospine.
 *
 * Exospine isolation: this hook calls `mountExospine()` for its own React
 * tree root. `ErrorLog` and `PerformanceDashboard` are mounted into SEPARATE
 * DOM containers (`event-logger-errors` vs `event-logger-admin`), so each
 * hook's exospine is naturally isolated by React-root scope.
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
import { createPerfErrorsRoute } from '../nodes/perfErrorsRoute';
import { createPerfErrorsTransform } from '../nodes/perfErrorsTransform';
import { createPerfErrorsView } from '../nodes/perfErrorsView';
import usePageVisibility from '../../shared/hooks/usePageVisibility';

// I/O boundary nodes mounted from the substrate runtime.
const SSE = '_sse';
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
// Dashboard chain.
const ROUTE = 'perferrors:route';
const TRANSFORM = 'perferrors:transform';
const VIEW = 'perferrors:view';
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
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View buffer cap (default 5000).
 * @return {{ setPaused: Function, clear: Function }} Control callbacks for the
 *   thin React view (the view's own state is read via useNodeState).
 */
export function useErrorLogGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live node handles for the connection effect + control callbacks.
	const sseRef = useRef( null );
	const heartbeatRef = useRef( null );
	const viewRef = useRef( null );

	// Paused state drives BOTH the view control (published for the button /
	// empty-state label) and the connection effect below (paused closes the SSE
	// stream).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Flipped true once the graph (and its view node) is mounted, so the
	// connection effect runs once the mount effect has built the graph and so a
	// consumer using useNodeState re-subscribes to the now-registered view node.
	const [ viewReady, setViewReady ] = useState( false );

	// Mount the graph once onto the exospine.
	useEffect( () => {
		const { maxEntries } = optsRef.current;
		const data =
			( typeof window !== 'undefined' && window.NewspackNodesData ) || {};

		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, router, teardown: teardownSpine } = mountExospine();

		// I/O boundary nodes — the same ones useRequestLogGraph mounts.
		// SseConnector's three-token positional config: `subscribe baseUrl nonce`.
		const sse = new SseIn();
		sse.arguments = `errors ${ data.restUrl || '/wp-json/' } ${
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

		// Dashboard chain — unchanged factories (controlTarget points at the view
		// even though `_sse` no longer synthesizes a connection-status control;
		// the dormant route stays wired for substrate uniformity).
		const route = createPerfErrorsRoute( ROUTE, {
			dataTarget: TRANSFORM,
			controlTarget: VIEW,
		} );
		const transform = createPerfErrorsTransform( TRANSFORM );
		const view = createPerfErrorsView( VIEW, { maxEntries } );
		route.sink = ci;
		transform.sink = ci;
		transform.target = VIEW;
		view.sink = ci;

		// Slot bridge: a `connected`-event subscriber on `_sse` pushes the live
		// slot into `_heartbeat`. Mirrors useRequestLogGraph.js.
		sse.register( 'connected', 'useErrorLogGraph', ( payload ) => {
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
			sse.unregister( 'connected', 'useErrorLogGraph' );
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

	// Own the live SSE connection: open while visible AND not paused, else close.
	// On close, clear the heartbeat slot so timer firings (if any subscriber is
	// driving them) become no-ops.
	useEffect( () => {
		const sse = sseRef.current;
		const heartbeat = heartbeatRef.current;
		if ( ! viewReady || ! sse ) {
			return undefined;
		}
		if ( isPageVisible && ! isPaused ) {
			sse.start();
		} else {
			sse.close();
			heartbeat?.clearSlot();
		}
		return undefined;
	}, [ viewReady, isPageVisible, isPaused ] );

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
