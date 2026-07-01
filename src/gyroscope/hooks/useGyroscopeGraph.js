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
 * The graph + connection lifecycle are handed to the shared `useVisibilityGatedLink`
 * hook: it mounts via `mountExospine` (snapshotting Core so the soft nodes tear down +
 * rebuild on `reinit()` — "Reset Graph"), closes the stream while hidden, and
 * RECONNECTS from the last seen offset on refocus. The `connected → slot` bridge lives
 * inside RemoteLink. `onConnect` resets the view map before each (re)connect (mirrors
 * the legacy Inflight `onBeforeConnect` reset).
 */

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
	const isPageVisible = usePageVisibility();

	// The shared lifecycle owns close-while-hidden + resume-on-refocus. On each
	// genuine (re)connect we clear the stale in-flight map first, then connect: the
	// FIRST connect live-follows (null = tail); a RECONNECT resumes from the last
	// seen offset so the gap accumulated while hidden replays into a correct
	// in-flight snapshot instead of tail-dropping.
	useVisibilityGatedLink( {
		mountNodes: ( interpreter ) => {
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

			return { link, view };
		},
		isActive: isPageVisible,
		onConnect: ( link, { isReconnect, view } ) => {
			if ( view ) {
				view.fill( controlMsg( { action: 'clear' } ) );
			}
			link.connect( isReconnect ? link.resumePositions() : null );
		},
	} );

	return {};
}
