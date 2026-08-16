/**
 * Performance Dashboard node-class registration.
 *
 * Imported for its side effect: it merges this dashboard's node classes into
 * `CommandInterpreterNode.includeNodes`, the flat `make_node` type→class table
 * the browser runtime resolves against. Both `src/overview/index.js` and
 * `usePerformanceGraph` import it before building a graph, so every
 * `interpreter.makeNode( '<Type>', … )` below finds its class.
 *
 * `usePerformanceGraph` builds one node per registered name:
 *   - `OverviewView` → `overview:view`, and `UrlsView` → `urls:view`, both
 *     created by `addSliceFetcher` from its `viewClass` slot (polled slices);
 *   - `UrlDetailMerge` → `urldetail:merge`, the incremental-merge transform on
 *     the `urldetail:in` Tee → `urldetail:view` edge;
 *   - `UrlDetailView` → `urldetail:view` and `RequestDetailView` →
 *     `requestdetail:view`, the two on-demand modal slices.
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { OverviewViewNode } from './overview-view-node';
import { UrlsViewNode } from './urls-view-node';
import { UrlDetailMergeNode } from './url-detail-merge-node';
import { UrlDetailViewNode } from './url-detail-view-node';
import { RequestDetailViewNode } from './request-detail-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = CommandInterpreterNode.registerNodeClasses( {
	OverviewView: OverviewViewNode,
	UrlsView: UrlsViewNode,
	UrlDetailMerge: UrlDetailMergeNode,
	UrlDetailView: UrlDetailViewNode,
	RequestDetailView: RequestDetailViewNode,
} );
