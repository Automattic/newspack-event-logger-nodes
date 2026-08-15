// Register the current-request node class so interpreter.makeNode finds it.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { CurrentRequestViewNode } from './current-request-view-node';

CommandInterpreterNode.registerNodeClasses( {
	CurrentRequestView: CurrentRequestViewNode,
} );
