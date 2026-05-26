/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useAggregatorAdminGraph tests — the Configured-Servers admin graph. The two
 * nodes (`servers/command`, `servers/view`) are REAL (their factories register
 * them in Core); only the command's client is injected so the hook never touches
 * the network. The hook fires one `list()` on mount, exposes the four CRUD
 * callbacks (each awaits the mutation then re-lists), surfaces mutation errors into
 * the view model, and tears down close()-before-unregister. Mirrors
 * useAggregatorStatusGraph's tests (real graph, faked command boundary).
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, Core } from '@newspack-nodes/runtime';
import { useAggregatorAdminGraph } from '../useAggregatorAdminGraph';

// The api.js wrappers are mocked so the hook's CRUD callbacks resolve/reject
// deterministically without exercising the command protocol again.
jest.mock( '../../api', () => ( {
	addServer: jest.fn(),
	updateServer: jest.fn(),
	removeServer: jest.fn(),
	testServer: jest.fn(),
} ) );
const api = require( '../../api' );

// A fake command client matching the command node's seam (send → resolves a
// canned reply); records every send + how many times so we can assert the
// immediate list + the re-list after a mutation.
function makeFakeClient() {
	return {
		calls: [],
		reply: newMessage(),
		send( args ) {
			this.calls.push( args );
			return Promise.resolve( this.reply );
		},
	};
}

beforeEach( () => {
	Core.reset();
	api.addServer.mockReset().mockResolvedValue( { id: 'x' } );
	api.updateServer.mockReset().mockResolvedValue( { id: 'x' } );
	api.removeServer.mockReset().mockResolvedValue( { id: 'x' } );
	api.testServer
		.mockReset()
		.mockResolvedValue( { id: 'x', status: 'connected', response: {} } );
} );

describe( 'useAggregatorAdminGraph — mount + wiring', () => {
	test( 'mounts the two nodes wired command→view', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		expect( Core.node( 'servers/command' ) ).toBeTruthy();
		expect( Core.node( 'servers/view' ) ).toBeTruthy();
		expect( Core.node( 'servers/command' ).sink ).toBe(
			Core.node( 'servers/view' )
		);
	} );

	test( 'fires one immediate list() on mount (list command)', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		expect( client.calls.length ).toBeGreaterThanOrEqual( 1 );
		expect( client.calls[ 0 ] ).toEqual( { to: 'servers', verb: 'list' } );
	} );

	test( 'returns the four CRUD callbacks', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		expect( typeof result.current.addServer ).toBe( 'function' );
		expect( typeof result.current.updateServer ).toBe( 'function' );
		expect( typeof result.current.removeServer ).toBe( 'function' );
		expect( typeof result.current.testServer ).toBe( 'function' );
	} );
} );

describe( 'useAggregatorAdminGraph — CRUD callbacks fire the verb then re-list', () => {
	test( 'addServer dispatches the add then re-lists', async () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		const listsAfterMount = client.calls.filter(
			( c ) => 'list' === c.verb
		).length;
		await act( async () => {
			await result.current.addServer( {
				id: 'spoke-01',
				url: 'https://x',
			} );
		} );
		expect( api.addServer ).toHaveBeenCalledWith( client, {
			id: 'spoke-01',
			url: 'https://x',
		} );
		// A re-list ran after the mutation (replaces window.location.reload()).
		const listsAfter = client.calls.filter(
			( c ) => 'list' === c.verb
		).length;
		expect( listsAfter ).toBeGreaterThan( listsAfterMount );
	} );

	test( 'updateServer dispatches the update then re-lists', async () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		const before = client.calls.filter( ( c ) => 'list' === c.verb ).length;
		await act( async () => {
			await result.current.updateServer( 'spoke-01', { enabled: false } );
		} );
		expect( api.updateServer ).toHaveBeenCalledWith( client, 'spoke-01', {
			enabled: false,
		} );
		const after = client.calls.filter( ( c ) => 'list' === c.verb ).length;
		expect( after ).toBeGreaterThan( before );
	} );

	test( 'removeServer dispatches the delete then re-lists', async () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		const before = client.calls.filter( ( c ) => 'list' === c.verb ).length;
		await act( async () => {
			await result.current.removeServer( 'spoke-01' );
		} );
		expect( api.removeServer ).toHaveBeenCalledWith( client, 'spoke-01' );
		const after = client.calls.filter( ( c ) => 'list' === c.verb ).length;
		expect( after ).toBeGreaterThan( before );
	} );

	test( 'testServer returns the probe result to the caller (per-row status)', async () => {
		const client = makeFakeClient();
		const probe = { id: 'spoke-01', status: 'connected', response: {} };
		api.testServer.mockResolvedValue( probe );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		let returned;
		await act( async () => {
			returned = await result.current.testServer( 'spoke-01' );
		} );
		expect( api.testServer ).toHaveBeenCalledWith( client, 'spoke-01' );
		expect( returned ).toEqual( probe );
	} );

	test( 'testServer does NOT re-list (a probe is read-only, no registry change)', async () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		const before = client.calls.filter( ( c ) => 'list' === c.verb ).length;
		await act( async () => {
			await result.current.testServer( 'spoke-01' );
		} );
		const after = client.calls.filter( ( c ) => 'list' === c.verb ).length;
		expect( after ).toBe( before );
	} );
} );

describe( 'useAggregatorAdminGraph — mutation errors surface into the view model', () => {
	test( 'a failed addServer surfaces the error message into servers/view', async () => {
		const client = makeFakeClient();
		api.addServer.mockRejectedValue( new Error( 'duplicate id' ) );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {
			await expect(
				result.current.addServer( { id: 'dup' } )
			).rejects.toThrow( 'duplicate id' );
		} );
		const view = Core.node( 'servers/view' );
		expect( view.setStateCache.view.error ).toContain( 'duplicate id' );
	} );

	test( 'a failed removeServer surfaces the error into servers/view', async () => {
		const client = makeFakeClient();
		api.removeServer.mockRejectedValue( new Error( 'in-use' ) );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {
			await expect(
				result.current.removeServer( 'spoke-01' )
			).rejects.toThrow( 'in-use' );
		} );
		expect(
			Core.node( 'servers/view' ).setStateCache.view.error
		).toContain( 'in-use' );
	} );
} );

describe( 'useAggregatorAdminGraph — teardown', () => {
	test( 'unmount closes the command node then unregisters both nodes', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		const command = Core.node( 'servers/command' );
		const closeSpy = jest.spyOn( command, 'close' );
		unmount();
		expect( closeSpy ).toHaveBeenCalled();
		expect( Core.node( 'servers/command' ) ).toBeNull();
		expect( Core.node( 'servers/view' ) ).toBeNull();
	} );

	test( 'a list resolving after unmount does not throw (command closed)', async () => {
		const client = makeFakeClient();
		let resolveReply;
		client.send = ( args ) => {
			client.calls.push( args );
			return new Promise( ( res ) => {
				resolveReply = res;
			} );
		};
		const { unmount } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		unmount();
		expect( () => resolveReply( newMessage() ) ).not.toThrow();
		await Promise.resolve();
	} );
} );
