/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useHookCatalogGraph tests — the Performance Logger hook-catalog graph clipped onto
 * the exospine (`mountExospine`: _command_interpreter → _router). The two graph
 * nodes (`hookcatalog:command`, `hookcatalog:view`) are REAL (their factories
 * register them in Core); only the command's client is injected so the hook never
 * touches the network. EVERY node sinks into the CI and steers via `target` (the
 * router peels TO and delivers); an end-to-end fetch reply routes command → view
 * through the real router into the view model. Unlike the aggregator graph there is
 * NO interval — the trigger is fire-on-open: flipping `isOpen` true fires one fetch.
 * Mirrors useAggregatorAdminGraph's tests (real graph, faked command boundary).
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, Core, VALUE } from '@newspack-nodes/runtime';
import { useHookCatalogGraph } from '../useHookCatalogGraph';

const CI = '_command_interpreter';
const COMMAND = 'hookcatalog:command';
const VIEW = 'hookcatalog:view';

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

describe( 'useHookCatalogGraph — exospine wiring', () => {
	test( 'mounts the backbone + two nodes, each sinking into the CI', () => {
		const client = makeFakeClient( {} );
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		const ci = Core.node( CI );
		expect( ci ).toBeTruthy();
		expect( Core.node( '_router' ) ).toBeTruthy();
		for ( const n of [ COMMAND, VIEW ] ) {
			expect( Core.node( n ) ).toBeTruthy();
			expect( Core.node( n ).sink ).toBe( ci );
		}
	} );

	test( 'steers flow with the command target, not a bespoke sink', () => {
		const client = makeFakeClient( {} );
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( Core.node( COMMAND ).target ).toBe( VIEW );
		// The command does NOT sink directly into the view (rule #2: sink=ci only).
		expect( Core.node( COMMAND ).sink ).not.toBe( Core.node( VIEW ) );
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

	test( 'the resolved catalog routes command → view through the real router and lands in the model', async () => {
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
		// Confirm it actually landed in the view node's published model (routed,
		// not bypassed): the view's setState cache holds the same map.
		expect( Core.node( VIEW ).setStateCache.view.hooksByCategory ).toEqual(
			hooks
		);
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
	test( 'unmount closes the command then unregisters the graph + the backbone', () => {
		const client = makeFakeClient( {} );
		const { unmount } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		const command = Core.node( COMMAND );
		const closeSpy = jest.spyOn( command, 'close' );
		const unregisterSpy = jest.spyOn( Core, 'unregisterNode' );
		unmount();
		expect( closeSpy ).toHaveBeenCalled();
		// Assert the documented order: close() runs before the unregister loop.
		expect( closeSpy.mock.invocationCallOrder[ 0 ] ).toBeLessThan(
			unregisterSpy.mock.invocationCallOrder[ 0 ]
		);
		for ( const n of [ COMMAND, VIEW, CI, '_router' ] ) {
			expect( Core.node( n ) ).toBeNull();
		}
		unregisterSpy.mockRestore();
	} );
} );
