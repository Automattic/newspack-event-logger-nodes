/**
 * The Performance Dashboard's node classes, declared once for two consumers.
 *
 * `usePerformanceGraph` imports `views` and hands `makeNode` the CLASS, because
 * the name table is a static per bundle and another bundle's interpreter cannot
 * resolve a name registered here. `src/overview/index.js` imports the module
 * for its side effect alone: `registerSliceViews` and `registerNodeClasses`
 * merge these classes into `CommandInterpreterNode.includeNodes`, which is what
 * lets the debug overlay's console resolve `make_node <Type>` and `help <Type>`
 * by name.
 *
 * `usePerformanceGraph` builds one node per class:
 *   - `OverviewView` → `overview:view` and `UrlsView` → `urls:view`, the two
 *     slices `addSliceFetcher` polls off the shared tick;
 *   - `UrlDetailView` → `urldetail:view` and `RequestDetailView` →
 *     `requestdetail:view`, the two on-demand modal slices;
 *   - `UrlDetailMerge` → `urldetail:merge`, the incremental-merge transform on
 *     the `urldetail:in` Tee → `urldetail:view` edge.
 *
 * The `performance` CI verbs answer PHP arrays rather than JSON strings, so a
 * payload reaches `parse` already decoded and no declaration sets `json`.
 *
 * The graph drives every node here through its own `controlFrom`, never by
 * payload shape. The polled pair takes `loading` when a filter change pokes
 * the tick; the modal slices take `loading`, `clear` and `error` as a
 * selection opens, closes or fails validation; and the merge takes `clear`, so
 * a reopened modal's reply is not discarded as a duplicate.
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';
import { UrlDetailMergeNode } from './url-detail-merge-node';

/**
 * A slice whose payload IS its data, plus the status fields the graph drives.
 *
 * A reply carrying no payload at all keeps the slice already on screen, so a
 * verb that answers nothing never blanks an open modal. Every other value
 * publishes, which leaves a `null` the server actually sent rendering as empty
 * rather than as stale.
 *
 * @param {string} description What this slice holds, shown by `help <Type>`.
 *                             The palette never lists one: `SliceViewNode`
 *                             marks every view `Hidden`.
 * @return {Object} The `sliceView` declaration.
 */
const dataSlice = ( description ) => ( {
	description,
	empty: { data: null, loading: false, error: null },
	parse: ( payload ) => ( undefined === payload ? null : { data: payload } ),
} );

/** The node classes `usePerformanceGraph` hands `makeNode`. */
export const views = {
	...registerSliceViews( {
		/** `overview:view` — the always-on overview slice, polled. */
		OverviewView: dataSlice(
			'Owns the always-on overview slice for its React widget.'
		),

		/**
		 * `urls:view` — the always-on URL leaderboard.
		 *
		 * The `urls` verb answers an envelope, `{ data, rows, totals, slowest,
		 * filters, limit, offset }`, so the payload is not the slice. `rows`
		 * counts every row the filters left and is what `<UrlTable>` paginates
		 * on, while `totals.urls` counts only the distinct URLs among them: the
		 * two synthetic overflow rows are sliceable but each stands for many
		 * URLs, so the pager takes `rows` and the header takes `totals`.
		 * `slowest` is the same set ranked by `avg_ms` for the facts block, and
		 * `limit` / `offset` are dropped because the fetcher's own args
		 * produced them. `filters` says what the totals are OF, echoed by the
		 * verb rather than read back off the client, so it describes the data
		 * in hand and not what was typed since.
		 *
		 * A malformed envelope publishes an empty table rather than throwing,
		 * and no totals rather than zeroes: a zero here reads as a measurement.
		 * Absent totals are a routine answer too — the verb sends `null` for a
		 * server scope whose stored rows carry no per-server split.
		 */
		UrlsView: {
			description: 'Owns the URL leaderboard slice for its React widget.',
			empty: {
				data: [],
				totals: null,
				rows: 0,
				slowest: [],
				filters: null,
				loading: false,
				error: null,
			},
			parse: ( payload ) =>
				undefined === payload
					? null
					: {
							data: ( payload && payload.data ) || [],
							totals: ( payload && payload.totals ) || null,
							rows: ( payload && payload.rows ) || 0,
							slowest: ( payload && payload.slowest ) || [],
							filters: ( payload && payload.filters ) || null,
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

		/**
		 * `requestdetail:view` — one selected request: its record, flame data
		 * and findings.
		 */
		RequestDetailView: dataSlice(
			'Owns the on-demand request-detail slice for its React widget.'
		),
	} ),
	...CommandInterpreterNode.registerNodeClasses( {
		/**
		 * The one node here that is not a slice view: it forwards a merged
		 * message rather than publishing a slice, and it holds the retained
		 * payload `usePerformanceGraph` reads the `since` watermark off.
		 */
		UrlDetailMerge: UrlDetailMergeNode,
	} ),
};
