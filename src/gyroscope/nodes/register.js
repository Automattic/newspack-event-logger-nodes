// Register this dashboard's node class so interpreter.makeNode can build it.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { GyroscopeViewNode } from './gyroscope-view-node';

CommandInterpreterNode.registerNodeClasses( {
	GyroscopeView: GyroscopeViewNode,
} );
