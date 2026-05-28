/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useAggregatorStatusGraph tests — the Aggregator Status dashboard graph clipped
 * onto the exospine (`mountExospine`: _command_interpreter → _router). The two
 * graph nodes (`aggregator:poll`, `aggregator:view`) are REAL (their factories
 * register them in Core); only the poll's command client is injected so the hook
 * never touches the network. EVERY node sinks into the CI and steers via `target`
 * (the router peels TO and delivers); an end-to-end poll reply routes
 * poll → view through the real router into the view model. The hook owns the poll
 * interval (NO page-visibility gating — the old AggregatorStatus didn't have any)
 * and the refresh-interval control. Mirrors useWorkerStatusGraph's tests (real
 * graph, faked command boundary).
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, TIMESTAMP, VALUE, Core } from '@newspack-nodes/runtime';
import { useAggregatorStatusGraph } from '../useAggregatorStatusGraph';

const CI = '_command_interpreter';
const POLL = 'aggregator:poll';
const VIEW = 'aggregator:view';

// A fake command client matching the poll node's seam (send → resolves a canned
// reply); records every send + how many times it was called so we can assert the
// immediate poll + interval ticks. The reply VALUE is a { name, payload } envelope
// so the real unwrapCommandResponse extracts the payload as the status map.
function makeFakeClient( payload = {}, now = null ) {
	return {
		calls: [],
		send( args ) {
			this.calls.push( args );
			const m = newMessage();
			m[ VALUE ] = { name: args.verb, payload };
			if ( null !== now ) {
				m[ TIMESTAMP ] = now;
			}
			return Promise.resolve( m );
		},
	};
}

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
} );

describe( 'useAggregatorStatusGraph — exospine wiring', () => {
	test( 'mounts the backbone + two nodes, each sinking into the CI', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const ci = Core.node( CI );
		expect( ci ).toBeTruthy();
		expect( Core.node( '_router' ) ).toBeTruthy();
		for ( const n of [ POLL, VIEW ] ) {
			expect( Core.node( n ) ).toBeTruthy();
			expect( Core.node( n ).sink ).toBe( ci );
		}
	} );

	test( 'steers flow with the poll target, not a bespoke sink', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( Core.node( POLL ).target ).toBe( VIEW );
		// The poll does NOT sink directly into the view (rule #2: sink=ci only).
		expect( Core.node( POLL ).sink ).not.toBe( Core.node( VIEW ) );
	} );

	test( 'fires one immediate poll on mount (status command)', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( client.calls.length ).toBeGreaterThanOrEqual( 1 );
		expect( client.calls[ 0 ] ).toEqual( {
			to: 'aggregator',
			verb: 'status',
		} );
	} );

	test( 'returns the current refresh interval (defaults to 2000)', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( result.current.refreshInterval ).toBe( '2000' );
	} );
} );

describe( 'useAggregatorStatusGraph — end-to-end routing through the exospine', () => {
	test( 'an immediate poll reply routes poll → view through the real router and lands in the view model', async () => {
		const status = {
			server1: {
				id: 'server1',
				partitions: { 0: { last_connection_status: 'connected' } },
			},
			server2: { id: 'server2', partitions: {} },
		};
		const client = makeFakeClient( status, 1748960000 );
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		// Let the immediate poll's promise resolve + route through the router.
		await act( async () => {} );

		const view = Core.node( VIEW );
		expect( view.setStateCache.view.totalCount ).toBe( 2 );
		expect( view.setStateCache.view.connectedCount ).toBe( 1 );
		expect( view.setStateCache.view.serverNow ).toBe( 1748960000 );
		expect( view.setStateCache.view.loading ).toBe( false );
		expect( view.setStateCache.view.error ).toBeNull();
	} );
} );

describe( 'useAggregatorStatusGraph — poll interval', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	test( 'polls again after the configured interval elapses', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const afterMount = client.calls.length;
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( client.calls.length ).toBeGreaterThan( afterMount );
	} );

	test( 'does NOT gate the interval on page visibility (always polls)', () => {
		// The hook must keep polling regardless of document.hidden — the old
		// AggregatorStatus had no visibility gating, so we must not add it.
		Object.defineProperty( document, 'hidden', {
			configurable: true,
			get: () => true,
		} );
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const afterMount = client.calls.length;
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( client.calls.length ).toBeGreaterThan( afterMount );
		delete document.hidden;
	} );
} );

describe( 'useAggregatorStatusGraph — refresh interval control', () => {
	test( 'setRefreshInterval persists the choice to localStorage', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		act( () => result.current.setRefreshInterval( '5000' ) );
		expect( result.current.refreshInterval ).toBe( '5000' );
		expect(
			window.localStorage.getItem( 'aggregator-status-refresh' )
		).toBe( '5000' );
	} );

	test( 'seeds the interval from a previously-persisted localStorage value', () => {
		window.localStorage.setItem( 'aggregator-status-refresh', '10000' );
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( result.current.refreshInterval ).toBe( '10000' );
	} );

	test( 'ignores an invalid persisted value and falls back to the default', () => {
		window.localStorage.setItem( 'aggregator-status-refresh', '999' );
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( result.current.refreshInterval ).toBe( '2000' );
	} );
} );

describe( 'useAggregatorStatusGraph — teardown', () => {
	test( 'unmount closes the poll, then unregisters the graph + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const poll = Core.node( POLL );
		const closeSpy = jest.spyOn( poll, 'close' );
		unmount();
		expect( closeSpy ).toHaveBeenCalled();
		for ( const n of [ POLL, VIEW, CI, '_router' ] ) {
			expect( Core.node( n ) ).toBeNull();
		}
	} );

	test( 'a poll resolving after unmount does not throw (poll closed)', async () => {
		const client = makeFakeClient();
		let resolveReply;
		client.send = ( args ) => {
			client.calls.push( args );
			return new Promise( ( res ) => {
				resolveReply = res;
			} );
		};
		const { unmount } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		unmount();
		expect( () => resolveReply( newMessage() ) ).not.toThrow();
		await Promise.resolve();
	} );
} );
