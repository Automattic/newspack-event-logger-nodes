// Register this dashboard's node classes into the interpreter's includeNodes
// map so they're createable via interpreter.makeNode — mirrors PHP's per-plugin
// namespace registration. Imported (for its side effect) by the hooks and the
// bundle entry, so registration runs before any graph build.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { OverviewViewNode } from './overview-view-node';
import { UrlsViewNode } from './urls-view-node';
import { UrlDetailMergeNode } from './url-detail-merge-node';
import { UrlDetailViewNode } from './url-detail-view-node';
import { RequestDetailViewNode } from './request-detail-view-node';

CommandInterpreterNode.registerNodeClasses( {
	// D1b de-god: the per-slice decoded-object views (overview/urls polled,
	// urldetail/requestdetail on-demand) + the url_detail merge transform node,
	// all wired by usePerformanceGraph onto useBatchedPoll/addSliceFetcher. The
	// god PerformanceCommand/PerformanceView nodes they replaced are gone.
	OverviewView: OverviewViewNode,
	UrlsView: UrlsViewNode,
	UrlDetailMerge: UrlDetailMergeNode,
	UrlDetailView: UrlDetailViewNode,
	RequestDetailView: RequestDetailViewNode,
} );
