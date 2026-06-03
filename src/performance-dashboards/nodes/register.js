// Register this dashboard's node classes into the interpreter's includeNodes
// map so they're createable via interpreter.makeNode — mirrors PHP's per-plugin
// namespace registration. Imported (for its side effect) by the hooks and the
// bundle entry, so registration runs before any graph build.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from './perf-errors-view-node';
import { PerformanceCommandNode } from './performance-command-node';
import { PerformanceViewNode } from './performance-view-node';

CommandInterpreterNode.registerNodeClasses( {
	PerfErrorsView: PerfErrorsViewNode,
	PerformanceCommand: PerformanceCommandNode,
	PerformanceView: PerformanceViewNode,
} );
