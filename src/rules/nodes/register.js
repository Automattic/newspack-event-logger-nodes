// Registered for TSL and the palette; a hook hands `makeNode` the class.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { RulesViewNode } from './rules-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = { RulesView: RulesViewNode };
CommandInterpreterNode.registerNodeClasses( views );
