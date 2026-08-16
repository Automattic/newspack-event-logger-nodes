/**
 * useGyroscopeGraph — mounts the Gyroscope dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`), using a SINGLE
 * substrate `RemoteLink` node instead of hand-wiring the I/O boundary:
 *
 *   gyroscope:link    RemoteLink — the `gyroscope.*` subscription. It composes an
 *                     patron-owned `:sse-in` (EventSource ingress) and reuses the
 *                     backbone singletons `_http` (the POST /command boundary)
 *                     and `_heartbeat` (slot keep-alive), bridging the SseIn's
 *                     `connected` handshake to the heartbeat's slot lease. This
 *                     dashboard injects no transport, so `_http` defaults its
 *                     own on the first POST.
 *
 *   gyroscope:stream  Tee — the inspectable stream edge. The link re-homes every
 *                     received frame here and the Tee copies each one to the
 *                     view, so another node (a debug-overlay watcher) can
 *                     `connectNode` onto the live stream without disturbing it.
 *
 *   gyroscope:view    GyroscopeView — the in-flight model the React view samples
 *                     through `snapshot()`. It consumes wire envelopes directly:
 *                     the rid rides KEY and the VALUE's `state` says what the
 *                     record is. The retired `gyroscope:route` and
 *                     `gyroscope:transform` hops collapsed into this `fill()`.
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`.
 *
 * `useVisibilityGatedLink` owns the graph and the connection lifecycle: it mounts
 * via `mountExospine` (which snapshots Core, so the soft nodes tear down and
 * rebuild on `reinit()` — "Reset Graph"), closes the stream while the page is
 * hidden, and RECONNECTS from the last seen offsets on refocus. `onConnect`
 * clears the view's request map first, because rows that predate a gap are stale.
 */

import { views } from '../nodes/register';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { useVisibilityGatedLink } from '@newspack-nodes/shared/hooks/useVisibilityGatedLink';
import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';

// The RemoteLink node, the inspectable stream Tee, and the view-model node.
const LINK = 'gyroscope:link';
const TEE = 'gyroscope:stream';
const VIEW = 'gyroscope:view';

/**
 * Build a control the view applies because it came FROM the dashboard driving
 * it; `action` picks the verb once inside. A control is recognised by WHO SENT
 * IT, never by what its payload looks like.
 *
 * @param {Object} value Control payload; `action` selects the view's branch.
 * @return {Array} A 7-field TM_STRUCT message.
 */

/**
 * Mount the Gyroscope graph and own its SSE connection while the page is visible.
 *
 * @return {Object} Empty — the view reads its model via useNodeState +
 *   Core.node(VIEW).snapshot(); the gyroscope dashboard has no control callbacks.
 *   Reset Graph is driven by a `Core.bumpGraphGeneration()` bump — mountExospine
 *   subscribes this reused mount's rebuild to it.
 */
export function useGyroscopeGraph() {
	const isPageVisible = usePageVisibility();

	useVisibilityGatedLink( {
		mountNodes: ( interpreter ) => {
			// The subscribe glob is RemoteLink's only ctor token.
			const link = interpreter.makeNode( 'RemoteLink', LINK, [
				'gyroscope.*',
			] );
			// Re-home received frames onto the Tee, not straight to the view.
			link.target = TEE;

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// Dashboard view — consumes wire envelopes directly.
			const view = interpreter.makeNode( views.GyroscopeView, VIEW );
			// The view applies controls from this FROM; records never match.
			view.controlFrom = VIEW;

			return { link, view };
		},
		isActive: isPageVisible,
		onConnect: ( link, { isReconnect, view } ) => {
			if ( view ) {
				view.fill( controlMsg( view, { action: 'clear' } ) );
			}
			// A null seed tails; a reconnect resumes the last seen offsets.
			link.connect( isReconnect ? link.resumePositions() : null );
		},
	} );

	return {};
}
