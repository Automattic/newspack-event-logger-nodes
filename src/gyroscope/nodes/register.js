/**
 * Gyroscope node-class registration.
 *
 * Imported for its side effect: it merges this dashboard's view class into
 * `CommandInterpreterNode.includeNodes`, the flat `make_node` type→class table
 * the browser runtime resolves against. Both `src/gyroscope/index.js` and
 * `useGyroscopeGraph` import it before building a graph, so the hook's
 * `interpreter.makeNode( 'GyroscopeView', … )` finds the class.
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { GyroscopeViewNode } from './gyroscope-view-node';

CommandInterpreterNode.registerNodeClasses( {
	GyroscopeView: GyroscopeViewNode,
} );
