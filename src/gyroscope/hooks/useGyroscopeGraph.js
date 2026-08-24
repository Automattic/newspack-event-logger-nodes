/**
 * useGyroscopeGraph — the Gyroscope dashboard's stream graph.
 *
 * The graph and its connection lifecycle are the substrate's `useStreamGraph`;
 * this declares the three values that make it this dashboard's — the node-name
 * prefix, the `gyroscope.*` subscription, and the view-model class.
 *
 * `gyroscope:view` consumes wire envelopes directly: the rid rides KEY and the
 * VALUE's `state` says what the record is. React samples it through
 * `snapshot()`. Rows that predate a connection gap are stale, so the view is
 * cleared before every open.
 */

import { views } from '../nodes/gyroscope-view-node';
import { useStreamGraph } from '@newspack-nodes/shared/hooks/useStreamGraph';

/**
 * Mount the Gyroscope graph and own its SSE connection while the page is visible.
 *
 * Returns nothing: the view reads its model via useNodeState +
 * Core.node(VIEW).snapshot(), and the gyroscope dashboard has no control
 * callbacks. Reset Graph is driven by a `Core.bumpGraphGeneration()` bump —
 * mountExospine subscribes this reused mount's rebuild to it.
 */
export function useGyroscopeGraph() {
	useStreamGraph( {
		prefix: 'gyroscope',
		subscribe: 'gyroscope.*',
		viewClass: views.GyroscopeView,
		// Rows that predate a connection gap are stale.
		clearOnOpen: true,
	} );
}
