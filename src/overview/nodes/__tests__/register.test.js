/**
 * Registration test — importing the dashboard's node module registers its
 * class(es) into the interpreter's includeNodes map so they're createable via
 * interpreter.makeNode (mirrors PHP's per-plugin namespace registration).
 */
import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import '../register';

it( 'registers the D1b slice-view + transform node classes for make_node', () => {
	expect( CommandInterpreterNode.includeNodes.OverviewView ).toBeDefined();
	expect( CommandInterpreterNode.includeNodes.UrlsView ).toBeDefined();
	expect( CommandInterpreterNode.includeNodes.UrlDetailMerge ).toBeDefined();
	expect( CommandInterpreterNode.includeNodes.UrlDetailView ).toBeDefined();
	expect(
		CommandInterpreterNode.includeNodes.RequestDetailView
	).toBeDefined();
} );

it( 'no longer registers the retired god command/view nodes', () => {
	expect(
		CommandInterpreterNode.includeNodes.PerformanceCommand
	).toBeUndefined();
	expect(
		CommandInterpreterNode.includeNodes.PerformanceView
	).toBeUndefined();
} );
