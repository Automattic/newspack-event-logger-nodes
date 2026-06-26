// Register this dashboard's node classes into the interpreter's includeNodes
// map so they're createable via interpreter.makeNode — mirrors PHP's per-plugin
// namespace registration. Imported (for its side effect) by the hooks and the
// bundle entry, so registration runs before any graph build.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from './perf-errors-view-node';
import { PerformanceCommandNode } from './performance-command-node';
import { PerformanceViewNode } from './performance-view-node';
import { OverviewViewNode } from './overview-view-node';
import { UrlsViewNode } from './urls-view-node';
import { UrlDetailMergeNode } from './url-detail-merge-node';

CommandInterpreterNode.registerNodeClasses( {
	PerfErrorsView: PerfErrorsViewNode,
	PerformanceCommand: PerformanceCommandNode,
	PerformanceView: PerformanceViewNode,
	// D1b de-god: the per-slice decoded-object views + the url_detail merge
	// transform node (wired onto useBatchedPoll/addSliceFetcher in the
	// follow-up integration split).
	OverviewView: OverviewViewNode,
	UrlsView: UrlsViewNode,
	UrlDetailMerge: UrlDetailMergeNode,
} );
