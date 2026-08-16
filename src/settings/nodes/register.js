// Register the settings-tree node classes so interpreter.makeNode finds them.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { HookCatalogViewNode } from './hook-catalog-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = CommandInterpreterNode.registerNodeClasses( {
	HookCatalogView: HookCatalogViewNode,
} );
