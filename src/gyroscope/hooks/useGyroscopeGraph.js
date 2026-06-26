/**
 * useGyroscopeGraph — mounts the Gyroscope dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using a SINGLE
 * substrate `RemoteLink` node instead of hand-wiring three I/O boundary nodes:
 *
 *   gyroscope:link        (RemoteLink — composes + registers three children:
 *                          `gyroscope:link:sse-in` (SseIn — EventSource ingress),
 *                          `gyroscope:link:http` (HttpOut — POST /command boundary),
 *                          `gyroscope:link:heartbeat` (Heartbeat — slot keep-alive),
 *                          and wires the `connected → slot` bridge to its own
 *                          heartbeat. `.client` is the injected CommandClient.)
 *
 * Plus the single dashboard node:
 *
 *   gyroscope:view        (the in-flight model the React view samples; consumes
 *                          wire envelopes directly — KEY/VALUE dispatch inlined)
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * `gyroscope:route` + `gyroscope:transform` hops were collapsed into
 * `gyroscope:view.fill()` directly: route was dead (KEY='connection' check,
 * substrate uses 'connected' AND snoops it off before routing) and transform
 * was just an envelope-shape dispatcher the view can now do itself.
 *
 * The graph build is handed to `mountExospine( build )`, which snapshots Core so
 * the soft nodes can be torn down + rebuilt on `reinit()` ("Reset Graph"). The
 * `connected → slot` bridge lives inside RemoteLink now. The page-visibility
 * effect drives `link.connect()` / `link.close()` (close clears the slot too).
 * On each (re)connect the view map is reset (mirrors the legacy Inflight
 * `onBeforeConnect` reset).
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
const LINK = 'gyroscope:link';
const TEE = 'gyroscope:stream';
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
	const linkRef = useRef( null );
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
			const baseUrl = data.restUrl || '/wp-json/';
			const nonce = data.nonce || '';

			// ONE RemoteLink composes the SseIn + HttpOut + Heartbeat children and
			// the `connected → slot` bridge. The positional `arguments` carry the
			// `gyroscope` subscribe plus baseUrl/nonce; the children build lazily on
			// the first connect(), so `.target` / `.client` are assigned first.
			const link = interpreter.makeNode(
				'RemoteLink',
				LINK,
				`gyroscope ${ baseUrl } ${ nonce }`
			);
			// A pure pass-through Tee on the stream edge: the link re-homes received
			// frames to it, it copies each to the view. `connect gyroscope:stream` in
			// the debug overlay appends a second target to inspect the live stream.
			link.target = TEE;
			link.client = new CommandClient( { baseUrl, nonce } );

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// Dashboard view — consumes wire envelopes directly.
			const view = interpreter.makeNode( 'GyroscopeView', VIEW );

			linkRef.current = link;
			viewRef.current = view;

			// Re-render so the connection effect re-runs against the fresh link and
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

	// Own the live SSE connection: open while visible, else close. On (re)connect
	// clear the view map first (mirrors Inflight's onBeforeConnect reset). The
	// link's close() clears its heartbeat slot too. Re-runs on every (re)build
	// via buildCount.
	useEffect( () => {
		const link = linkRef.current;
		if ( ! buildCount || ! link ) {
			return undefined;
		}
		if ( isPageVisible ) {
			if ( viewRef.current ) {
				viewRef.current.fill( controlMsg( { action: 'clear' } ) );
			}
			link.connect();
		} else {
			link.close();
		}
		return undefined;
	}, [ buildCount, isPageVisible ] );

	return {};
}
