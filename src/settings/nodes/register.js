// Register the settings-tree node classes so interpreter.makeNode finds them.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { HookCatalogViewNode } from './hook-catalog-view-node';

CommandInterpreterNode.registerNodeClasses( {
	HookCatalogView: HookCatalogViewNode,
} );
