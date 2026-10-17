/**
 * The Performance Dashboard's view classes, declared once for two consumers.
 *
 * `usePerformanceGraph` imports `views` and hands `makeNode` the CLASS, because
 * the name table is a static per bundle and another bundle's interpreter cannot
 * resolve a name registered here. `src/overview/index.js` imports the module
 * for its side effect alone: `registerSliceViews` merges these classes into
 * `CommandInterpreterNode.includeNodes`, which is what lets the debug
 * overlay's console resolve `make_node <Type>` and `help <Type>` by name.
 *
 * `usePerformanceGraph` builds:
 *   - `OverviewView` → `overview:view` and `UrlsView` → `urls:view`, the two
 *     slices `addSliceFetcher` polls off the shared tick;
 *   - `QueryView` → `chart:view`, `categories:view`, `url-chart:view` and
 *     `url-categories:view`, one per chart, each holding one `query` answer;
 *   - `UrlDetailView` → `url-detail:view` and `RequestDetailView` →
 *     `request-detail:view`, the two on-demand modal slices.
 *
 * The graph drives every view here through its own `controlFrom`, never by
 * payload shape: `loading` when a filter or a dimension change asks afresh,
 * and `clear` and `error` as a modal opens, closes or fails validation.
 *
 * No class here decides whether a reply is still wanted: the substrate's
 * `Current` gate on each slice's receiver edge passes only the answer to an
 * ask its Fetcher still holds, and sends a refusal to the view itself. Each
 * answer replaces its slice whole.
 */
import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

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

/** The view classes `usePerformanceGraph` hands `makeNode`. */
export const views = registerSliceViews( {
	/** `overview:view` — the always-on overview slice, polled. */
	OverviewView: dataSlice(
		'Owns the always-on overview slice for its React widget.'
	),

	/**
	 * `urls:view` — the always-on URL leaderboard.
	 *
	 * The `urls` verb answers an envelope, `{ data, rows, totals, slowest,
	 * filters, limit, offset }`, so the payload is not the slice. `rows`
	 * counts the URLs the filters left, which `<UrlTable>` paginates on, and
	 * `totals` is the same set's headline numbers. `slowest` is the set ranked
	 * by `avg_ms` for the facts block, and `limit` / `offset` are dropped
	 * because the fetcher's own args produced them. `filters` says what the
	 * totals are OF, echoed by the verb rather than read back off the client,
	 * so it describes the data in hand and not what was typed since.
	 *
	 * A malformed envelope publishes an empty table rather than throwing,
	 * and no totals rather than zeroes: a zero here reads as a measurement.
	 * `rows` starts null for the same reason, so the pager clamps a linked
	 * page only once a reply has counted the set.
	 */
	UrlsView: {
		description: 'Owns the URL leaderboard slice for its React widget.',
		empty: {
			data: [],
			totals: null,
			rows: null,
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
	 * A chart's `query` answer: its columns and rows, the tuples its top
	 * ranked among, whether the stats rows opened, and the selection it read.
	 */
	QueryView: dataSlice(
		"Owns one chart's query answer for its React widget."
	),

	/** `url-detail:view` — the on-demand URL modal slice, asked afresh each tick. */
	UrlDetailView: dataSlice(
		'Owns the on-demand URL-detail slice for its React widget.'
	),

	/**
	 * `request-detail:view` — one selected request: its record, flame data
	 * and findings.
	 */
	RequestDetailView: dataSlice(
		'Owns the on-demand request-detail slice for its React widget.'
	),
} );
