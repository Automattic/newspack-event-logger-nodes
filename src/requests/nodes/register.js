// Register this dashboard's node class so interpreter.makeNode can build it.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { RequestLogViewNode } from './request-log-view-node';

CommandInterpreterNode.registerNodeClasses( {
	RequestLogView: RequestLogViewNode,
} );
