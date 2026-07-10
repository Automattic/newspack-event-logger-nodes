// Register this dashboard's node class so interpreter.makeNode can build it.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { HookCatalogViewNode } from './hook-catalog-view-node';

CommandInterpreterNode.registerNodeClasses( {
	HookCatalogView: HookCatalogViewNode,
} );
