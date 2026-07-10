// Register the rules editor's view class so interpreter.makeNode can build it.
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { RulesViewNode } from './rules-view-node';

CommandInterpreterNode.registerNodeClasses( {
	RulesView: RulesViewNode,
} );
