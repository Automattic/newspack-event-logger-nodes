/**
 * Registration test — importing the dashboard's node module registers its
 * class into the interpreter's includeNodes map so it's createable via
 * interpreter.makeNode (mirrors PHP's per-plugin namespace registration).
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import '../register';

it( 'registers RequestLogView for make_node', () => {
	expect( CommandInterpreterNode.includeNodes.RequestLogView ).toBeDefined();
} );
