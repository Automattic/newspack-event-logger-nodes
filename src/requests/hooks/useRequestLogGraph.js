/**
 * useRequestLogGraph — the Request Log dashboard's stream graph.
 *
 * The graph itself is `useGlobStreamGraph`; this declares the three values that
 * make it this dashboard's — the node-name prefix, the partition glob it tails,
 * and the view-model class its React view reads.
 */

import { views } from '../nodes/request-log-view-node';
import { useGlobStreamGraph } from '../../hooks/useGlobStreamGraph';

/**
 * Mount the Request Log graph and return the React view's controls.
 *
 * Controls only: the rows, the pause flag and the connection error are the view
 * node's state, which the view reads through `useNodeState`.
 *
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View ring cap; `RequestLogViewNode`'s own
 *                                   1000 when unset.
 * @return {{ setPaused: (paused: boolean) => void, clear: () => void, step: ?() => void, browse: Object, setFilter: (term: string) => void }}
 *   Control callbacks plus the browse model for the thin React view. `step` is
 *   undefined until `browse` selects one partition dir, because a step walks
 *   within a dir and there is none to walk across the glob.
 */
export function useRequestLogGraph( opts = {} ) {
	return useGlobStreamGraph(
		{
			prefix: 'requestlog',
			glob: 'completed.*',
			viewClass: views.RequestLogView,
		},
		opts
	);
}
