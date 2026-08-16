// Register the current-request node class so interpreter.makeNode finds it.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { CurrentRequestViewNode } from './current-request-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = CommandInterpreterNode.registerNodeClasses( {
	CurrentRequestView: CurrentRequestViewNode,
} );
