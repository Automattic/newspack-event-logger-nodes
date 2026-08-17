/**
 * Performance Dashboard node-class registration.
 *
 * Imported for its side effect: `registerSliceViews` merges this dashboard's
 * view classes into `CommandInterpreterNode.includeNodes`, the flat `make_node`
 * type→class table the browser runtime resolves against. Both
 * `src/overview/index.js` and `usePerformanceGraph` import it before building a
 * graph, so every `interpreter.makeNode( '<Type>', … )` below finds its class.
 *
 * `usePerformanceGraph` builds one node per registered name:
 *   - `OverviewView` → `overview:view`, and `UrlsView` → `urls:view`, both
 *     created by `addSliceFetcher` from its `viewClass` slot (polled slices);
 *   - `UrlDetailMerge` → `urldetail:merge`, the incremental-merge transform on
 *     the `urldetail:in` Tee → `urldetail:view` edge;
 *   - `UrlDetailView` → `urldetail:view` and `RequestDetailView` →
 *     `requestdetail:view`, the two on-demand modal slices.
 *
 * The `performance` CI verbs answer live PHP arrays, so every payload arrives
 * already decoded and no declaration sets `json`. The graph drives the modal
 * slices with `loading` / `clear` / `error` controls through each view's
 * `controlFrom`.
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';
import { UrlDetailMergeNode } from './url-detail-merge-node';

/**
 * A slice whose payload IS its data, plus the status fields the graph drives.
 *
 * @param {string} description What this slice holds, for the node palette.
 * @return {Object} The `sliceView` declaration.
 */
const dataSlice = ( description ) => ( {
	description,
	empty: { data: null, loading: false, error: null },
	parse: ( payload ) => ( undefined === payload ? null : { data: payload } ),
} );

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = {
	...registerSliceViews( {
		/** `overview:view` — the always-on overview slice, polled. */
		OverviewView: dataSlice(
			'Owns the always-on overview slice for its React widget.'
		),

		/**
		 * `urls:view` — the always-on URL leaderboard.
		 *
		 * The `urls` verb answers an envelope, `{ data, total, limit, offset }`,
		 * so the payload is not the slice: `total` is the unpaginated match
		 * count `<UrlTable>` paginates on, and `limit` / `offset` are dropped
		 * because the fetcher's own args produced them. A malformed envelope
		 * publishes an empty table rather than throwing.
		 */
		UrlsView: {
			description: 'Owns the URL leaderboard slice for its React widget.',
			empty: { data: [], total: 0, loading: false, error: null },
			parse: ( payload ) =>
				undefined === payload
					? null
					: {
							data: ( payload && payload.data ) || [],
							total: ( payload && payload.total ) || 0,
					  },
		},

		/**
		 * `urldetail:view` — the on-demand URL modal slice.
		 *
		 * Replies land here already merged: the graph runs `urldetail:in` (Tee)
		 * → `urldetail:merge` → this node, and the merge node owns the
		 * incremental request-list merge, the `last_modified` dedup and the
		 * 500-request cap.
		 */
		UrlDetailView: dataSlice(
			'Owns the on-demand URL-detail slice for its React widget.'
		),

		/** `requestdetail:view` — one selected request, body plus flame data. */
		RequestDetailView: dataSlice(
			'Owns the on-demand request-detail slice for its React widget.'
		),
	} ),
	...CommandInterpreterNode.registerNodeClasses( {
		UrlDetailMerge: UrlDetailMergeNode,
	} ),
};
