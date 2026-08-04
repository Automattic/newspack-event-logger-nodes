import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `urls:view` — owns the always-on urls slice.
 *
 * The `urls` verb returns an envelope, `{ data, total, limit, offset }`, so the
 * payload is NOT the slice data and the inherited `DecodedSliceViewNode`
 * behavior does not apply unchanged. This subclass unwraps the envelope into a
 * slice of `{ data, total, loading, error }`: `data` holds the URL leaderboard
 * rows and `total` the unpaginated match count that drives `<UrlTable>`'s
 * pagination. `limit` and `offset` are dropped — the fetcher already knows
 * them, since its args produced them.
 *
 * `usePerformanceGraph` builds it through `addSliceFetcher` under the
 * registered class name `UrlsView`, on the polled `fetch-urls` → `urlsIn` →
 * `urls:view` path. `PerformanceDashboard` reads the slice with
 * useNodeState('urls:view','view') and passes `data` and `total` down to the
 * presentational <UrlTable> as its `urls` and `totalUrls` props.
 */
export class UrlsViewNode extends DecodedSliceViewNode {
	/**
	 * The shaped-but-empty slice: an empty table, not a null one.
	 *
	 * @return {Object} `{ data: [], total: 0, loading: false, error: null }`.
	 */
	emptySlice() {
		return { data: [], total: 0, loading: false, error: null };
	}

	/**
	 * Unwrap a successful `urls` envelope onto the slice model.
	 *
	 * A missing or malformed envelope publishes an empty table rather than
	 * throwing, so a truncated reply clears the rows instead of the dashboard.
	 *
	 * @param {?Object} payload The decoded `urls` envelope, or a falsy value.
	 */
	storeResult( payload ) {
		this.model = {
			data: ( payload && payload.data ) || [],
			total: ( payload && payload.total ) || 0,
			loading: false,
			error: null,
		};
	}
}
