/**
 * useGlobStreamGraph — the stream dashboard whose subscription is a GLOB across
 * partitions (`errors.*` / `completed.*`), declared per dashboard.
 *
 * The graph, the pause/visibility gate, the recorded reopen target and the
 * paused single-step are the substrate's `useStreamGraph`; what this adds is
 * `useGlobBrowse`, the two-level pick a glob needs — the whole glob tailed live,
 * or one partition dir with a segment rail.
 */

import {
	useStreamGraph,
	useSteppedRead,
} from '@newspack-nodes/shared/hooks/useStreamGraph';
import useGlobBrowse from './useGlobBrowse';

// The substrate service CI whose `read_message` answers a paused Step.
const RAW_LOGS = 'raw-logs';

/**
 * Mount one dashboard's stream graph and return its React view's controls.
 *
 * @param {Object}   spec              The dashboard's declaration.
 * @param {string}   spec.prefix       Node-name prefix for the three soft nodes.
 * @param {string}   spec.glob         Partition glob this dashboard tails.
 * @param {Function} spec.viewClass    View-model node class to mount.
 * @param {Object}   [opts]            Options.
 * @param {number}   [opts.maxEntries] View ring cap; the view class's own default when unset.
 * @return {{ setPaused: Function, clear: () => void, step: ?() => void, browse: Object, setFilter: (term: string) => void }}
 *   Control callbacks plus the browse model for the thin React view; the view's
 *   own state is read via useNodeState.
 */
export function useGlobStreamGraph( { prefix, glob, viewClass }, opts = {} ) {
	const graph = useStreamGraph( {
		prefix,
		subscribe: glob,
		viewClass,
		maxEntries: opts.maxEntries,
	} );
	const step = useSteppedRead( {
		graph,
		ci: RAW_LOGS,
		command: 'read_message',
	} );
	const browse = useGlobBrowse( { glob, graph, step } );
	const { setPaused, setFilter, clear } = graph;

	return {
		setPaused,
		// A step walks WITHIN a dir; there is none to walk across the glob.
		step: browse.selectedPartition ? step : undefined,
		browse,
		setFilter,
		clear,
	};
}
