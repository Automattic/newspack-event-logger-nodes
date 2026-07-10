// Register this dashboard's node classes so makeNode can build them.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from './perf-errors-view-node';

CommandInterpreterNode.registerNodeClasses( {
	// The Error Log's per-slice errors view.
	PerfErrorsView: PerfErrorsViewNode,
} );
