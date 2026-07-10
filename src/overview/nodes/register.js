// Register this dashboard's node classes so makeNode can build them.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { OverviewViewNode } from './overview-view-node';
import { UrlsViewNode } from './urls-view-node';
import { UrlDetailMergeNode } from './url-detail-merge-node';
import { UrlDetailViewNode } from './url-detail-view-node';
import { RequestDetailViewNode } from './request-detail-view-node';

CommandInterpreterNode.registerNodeClasses( {
	// Per-slice decoded views + url_detail merge, wired by usePerformanceGraph.
	OverviewView: OverviewViewNode,
	UrlsView: UrlsViewNode,
	UrlDetailMerge: UrlDetailMergeNode,
	UrlDetailView: UrlDetailViewNode,
	RequestDetailView: RequestDetailViewNode,
} );
