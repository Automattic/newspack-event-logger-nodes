/**
 * Error Log node-class registration.
 *
 * Imported for its side effect: it merges this dashboard's view class into
 * `CommandInterpreterNode.includeNodes`, the flat `make_node` type→class table
 * the browser runtime resolves against. `src/error-log/index.js` imports it
 * before anything builds a graph, so `useErrorLogGraph`'s
 * `interpreter.makeNode( 'PerfErrorsView', … )` finds the class.
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from './perf-errors-view-node';

CommandInterpreterNode.registerNodeClasses( {
	PerfErrorsView: PerfErrorsViewNode,
} );
