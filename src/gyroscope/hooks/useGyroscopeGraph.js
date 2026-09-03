/**
 * useGyroscopeGraph — the Gyroscope dashboard's stream graph.
 *
 * The nodes, the SSE connection and the visibility gate are the substrate's
 * `useStreamGraph`. This file declares only what makes that graph this
 * dashboard's: the node-name prefix, the subscription, the view class and the
 * pre-open clear.
 *
 * Nothing sits between the stream and the view, because `gyroscope:view`
 * consumes wire envelopes as they arrive — the rid rides KEY and the VALUE's
 * `state` says what the record is. React samples the model off that node
 * through `snapshot()` on its own refresh tick rather than re-rendering per
 * message.
 */

import { views } from '../nodes/gyroscope-view-node';
import { useStreamGraph } from '@newspack-nodes/shared/hooks/useStreamGraph';

/**
 * Mount the Gyroscope graph and hold its SSE connection while the page is
 * visible.
 *
 * The prefix is a contract rather than a label: it names the three nodes, and
 * `Inflight.js` reaches `gyroscope:view` by that literal string — the
 * reconnect banner through `useNodeState`, the row list through the
 * `snapshot()` its refresh tick calls. The view class is handed over rather
 * than named because the interpreter that builds the graph may belong to
 * another bundle, whose `includeNodes` never registered `GyroscopeView`
 * (ADR-16).
 *
 * Returns nothing: this dashboard offers no pause, step or filter control, and
 * React reads the model off the node. Reset Graph needs no wiring here either
 * — `useStreamGraph` mounts through `mountExospine`, which subscribes the
 * rebuild to `Core.bumpGraphGeneration()`.
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
