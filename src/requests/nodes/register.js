// Registered for TSL and the palette; a hook hands `makeNode` the class.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { RequestLogViewNode } from './request-log-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = { RequestLogView: RequestLogViewNode };
CommandInterpreterNode.registerNodeClasses( views );
