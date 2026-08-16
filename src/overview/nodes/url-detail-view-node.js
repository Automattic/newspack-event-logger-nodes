import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `urldetail:view` — owns the on-demand `url_detail` slice behind the URL modal.
 *
 * The slice is fetched when the modal opens, then refreshed by its own
 * `urldetail:timer` → `urldetail:fetch` Fetcher, which `usePerformanceGraph`
 * arms only while a valid URL is selected, no request modal covers it, and the
 * tab is visible. It never rides the shared `perf:timer` poll that drives the
 * overview and urls slices.
 *
 * Replies land here already merged. The graph edge runs `urldetail:in` (Tee) →
 * `urldetail:merge` (UrlDetailMergeNode) → this node, and the merge node does
 * the incremental request-list merge, the `last_modified` dedup, and the
 * 500-request cap. So this subclass adds nothing to DecodedSliceViewNode: the
 * inherited `emptySlice()` and `storeResult()` (`data` = the merged payload)
 * are exactly right, and the inherited loading / clear / error controls serve
 * the modal's open, close, and invalid-hash paths.
 *
 * `PerformanceDashboard` reads the slice with
 * `useNodeState( 'urldetail:view', 'view' )` and hands `data` to
 * `<UrlDetailView>`.
 *
 * The other `url_detail` reads — the chart's breakdown series, and the two
 * hash lookups — each have their OWN node under their own scope, so their
 * replies address those nodes and never disturb this slice.
 */
export class UrlDetailViewNode extends DecodedSliceViewNode {}
