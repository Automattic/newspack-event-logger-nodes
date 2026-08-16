/**
 * Error Log node-class registration — merged into
 * `CommandInterpreterNode.includeNodes`, the flat `make_node` type→class table
 * TSL and the palette resolve names against.
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from './perf-errors-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = { PerfErrorsView: PerfErrorsViewNode };
CommandInterpreterNode.registerNodeClasses( views );
