/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useHookCatalogGraph tests — the Performance Logger hook-catalog graph. The two
 * nodes (`hookcatalog/command`, `hookcatalog/view`) are REAL (their factories
 * register them in Core); only the command's client is injected so the hook never
 * touches the network. Unlike the aggregator graph there is NO interval — the
 * trigger is fire-on-open: flipping `isOpen` true fires one fetch. Mirrors
 * useAggregatorStatusGraph's tests (real graph, faked command boundary).
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, Core, VALUE } from '@newspack-nodes/runtime';
import { useHookCatalogGraph } from '../useHookCatalogGraph';

// A fake command client matching the command node's seam. send records the call
// and resolves a Message-shaped reply ({ payload } at VALUE) so production unwrap
// runs for real.
function makeFakeClient( hooks ) {
	return {
		calls: [],
		send( args ) {
			this.calls.push( args );
			const m = newMessage();
			m[ VALUE ] = { payload: { hooks_by_category: hooks } };
			return Promise.resolve( m );
		},
	};
}

beforeEach( () => {
	Core.reset();
} );

describe( 'useHookCatalogGraph — mount + wiring', () => {
	test( 'mounts the two nodes wired command→view', () => {
		const client = makeFakeClient( {} );
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( Core.node( 'hookcatalog/command' ) ).toBeTruthy();
		expect( Core.node( 'hookcatalog/view' ) ).toBeTruthy();
		expect( Core.node( 'hookcatalog/command' ).sink ).toBe(
			Core.node( 'hookcatalog/view' )
		);
	} );

	test( 'does NOT fetch while isOpen is false', () => {
		const client = makeFakeClient( {} );
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( client.calls.length ).toBe( 0 );
	} );

	test( 'returns the default model before any fetch', () => {
		const client = makeFakeClient( {} );
		const { result } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( result.current.hooksByCategory ).toEqual( {} );
		expect( result.current.loading ).toBe( false );
	} );
} );

describe( 'useHookCatalogGraph — fire on open', () => {
	test( 'flipping isOpen true fires the hooks_registered command', async () => {
		const client = makeFakeClient( {} );
		const { rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		expect( client.calls.length ).toBe( 0 );
		act( () => rerender( { isOpen: true, commandClient: client } ) );
		expect( client.calls.length ).toBeGreaterThanOrEqual( 1 );
		expect( client.calls[ 0 ] ).toEqual( {
			to: 'performance',
			verb: 'hooks_registered',
		} );
		// Settle the catalog publish inside act so it doesn't escape the test.
		await act( async () => {
			await Promise.resolve();
			await Promise.resolve();
		} );
	} );

	test( 'the resolved catalog flows into hooksByCategory', async () => {
		const hooks = {
			Lifecycle: [ 'init' ],
			'REST API': [ 'rest_api_init' ],
		};
		const client = makeFakeClient( hooks );
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		act( () => rerender( { isOpen: true, commandClient: client } ) );
		// Flush the send microtasks (and the resulting catalog publish) inside act.
		await act( async () => {
			await Promise.resolve();
			await Promise.resolve();
		} );
		expect( result.current.hooksByCategory ).toEqual( hooks );
		expect( result.current.loading ).toBe( false );
	} );

	test( 'loading is true between fire and resolve', () => {
		// A client whose send never resolves: loading stays true.
		const client = {
			calls: [],
			send( args ) {
				this.calls.push( args );
				return new Promise( () => {} );
			},
		};
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		act( () => rerender( { isOpen: true, commandClient: client } ) );
		expect( result.current.loading ).toBe( true );
	} );
} );

describe( 'useHookCatalogGraph — teardown', () => {
	test( 'unmount closes the command BEFORE unregistering either node', () => {
		const client = makeFakeClient( {} );
		const { unmount } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		const command = Core.node( 'hookcatalog/command' );
		const closeSpy = jest.spyOn( command, 'close' );
		const unregisterSpy = jest.spyOn( Core, 'unregisterNode' );
		unmount();
		expect( closeSpy ).toHaveBeenCalled();
		// Assert the documented order: close() runs before the unregister loop.
		expect( closeSpy.mock.invocationCallOrder[ 0 ] ).toBeLessThan(
			unregisterSpy.mock.invocationCallOrder[ 0 ]
		);
		expect( Core.node( 'hookcatalog/command' ) ).toBeNull();
		expect( Core.node( 'hookcatalog/view' ) ).toBeNull();
		unregisterSpy.mockRestore();
	} );
} );
