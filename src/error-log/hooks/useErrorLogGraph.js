/**
 * useErrorLogGraph — the Error Log dashboard's stream graph.
 *
 * The graph itself is `useGlobStreamGraph`; this declares the three values that
 * make it this dashboard's — the node-name prefix, the partition glob it tails,
 * and the view-model class its React view reads.
 */

import { views } from '../nodes/register';
import { useGlobStreamGraph } from '../../hooks/useGlobStreamGraph';

/**
 * Mount the Error Log graph and return the React view's controls.
 *
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View ring cap (default 5000).
 * @return {{ setPaused: Function, clear: () => void, step: () => void, browse: Object, setFilter: (term: string) => void }}
 *   Control callbacks plus the browse model for the thin React view.
 */
export function useErrorLogGraph( opts = {} ) {
	return useGlobStreamGraph(
		{
			prefix: 'perferrors',
			glob: 'errors.*',
			viewClass: views.PerfErrorsView,
		},
		opts
	);
}
