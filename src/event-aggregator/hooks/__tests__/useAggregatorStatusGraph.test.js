/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useAggregatorStatusGraph tests — the Aggregator Status dashboard graph. The two
 * nodes (`aggregator/poll`, `aggregator/view`) are REAL (their factories register
 * them in Core); only the poll's command client is injected so the hook never
 * touches the network. The hook owns the poll interval (NO page-visibility gating
 * — the old AggregatorStatus didn't have any) and the refresh-interval control.
 * Mirrors useWorkerStatusGraph's tests (real graph, faked command boundary).
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, Core } from '@newspack-nodes/runtime';
import { useAggregatorStatusGraph } from '../useAggregatorStatusGraph';

// A fake command client matching the poll node's seam (send → resolves a canned
// reply); records every send + how many times it was called so we can assert the
// immediate poll + interval ticks.
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
	window.localStorage.clear();
} );

describe( 'useAggregatorStatusGraph — mount + wiring', () => {
	test( 'mounts the two nodes wired poll→view', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( Core.node( 'aggregator/poll' ) ).toBeTruthy();
		expect( Core.node( 'aggregator/view' ) ).toBeTruthy();
		expect( Core.node( 'aggregator/poll' ).sink ).toBe(
			Core.node( 'aggregator/view' )
		);
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
	test( 'unmount closes the poll then unregisters both nodes', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const poll = Core.node( 'aggregator/poll' );
		const closeSpy = jest.spyOn( poll, 'close' );
		unmount();
		expect( closeSpy ).toHaveBeenCalled();
		expect( Core.node( 'aggregator/poll' ) ).toBeNull();
		expect( Core.node( 'aggregator/view' ) ).toBeNull();
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
