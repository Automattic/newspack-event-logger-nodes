/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * Tests for AggregatorStatus — fetches server connection status via
 * the substrate CommandClient and renders a per-server / per-partition
 * grid. Auto-refreshes on an interval driven by a select control.
 */

jest.mock( '../../shared/utils/commandClient', () => {
	const send = jest.fn();
	return {
		__esModule: true,
		getCommandClient: jest.fn( () => ( { send } ) ),
		__send: send,
	};
} );
jest.mock( '../../shared/utils/unwrapCommandResponse', () => ( {
	__esModule: true,
	default: jest.fn( ( msg ) => msg ),
} ) );

import * as React from 'react';
import AggregatorStatus from '../AggregatorStatus';
import { __send as mockSend } from '../../shared/utils/commandClient';
import unwrap from '../../shared/utils/unwrapCommandResponse';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

const SAMPLE = {
	server1: {
		id: 'server1',
		url: 'https://a.example.test',
		enabled: true,
		partitions: {
			0: {
				last_connection_status: 'connected',
				last_heartbeat_response_status: 'success',
				last_heartbeat_rtt: 42,
				last_connection_attempt: 1748960000,
				last_sse_heartbeat: 1748960010,
				last_heartbeat_response: 1748960010,
			},
			1: {
				last_connection_status: 'disconnected',
				last_connection_error: 'timeout',
				last_connection_response: 504,
			},
		},
	},
	server2: {
		id: 'server2',
		url: 'https://b.example.test',
		enabled: false,
		partitions: {},
	},
};

describe( 'AggregatorStatus', () => {
	const mounted = [];

	function mount() {
		const r = renderComponent( React.createElement( AggregatorStatus ) );
		mounted.push( r );
		return r;
	}

	beforeEach( () => {
		mockSend.mockReset();
		unwrap.mockReset();
		unwrap.mockImplementation( ( msg ) => msg );
		jest.useFakeTimers();
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
		jest.useRealTimers();
	} );

	async function flush() {
		await act( async () => {} );
	}

	it( 'shows the loading state before the first fetch resolves', () => {
		mockSend.mockReturnValue( new Promise( () => {} ) );
		const { container } = mount();
		expect( container.textContent ).toContain( 'Loading server status' );
	} );

	it( 'fetches aggregator.status on mount and renders server cards', async () => {
		mockSend.mockResolvedValue( SAMPLE );
		const { container } = mount();
		await flush();
		expect( mockSend ).toHaveBeenCalledWith( {
			to: 'aggregator',
			verb: 'status',
		} );
		// Both servers visible.
		expect( container.textContent ).toContain( 'server1' );
		expect( container.textContent ).toContain( 'server2' );
		// Partition labels p0/p1 for server1.
		expect( container.textContent ).toContain( 'p0' );
		expect( container.textContent ).toContain( 'p1' );
		// Connected summary: only server1 has any connected partition.
		expect( container.textContent ).toContain( '1 / 2 connected' );
	} );

	it( 'shows the empty state when no servers are returned', async () => {
		mockSend.mockResolvedValue( {} );
		const { container } = mount();
		await flush();
		expect( container.textContent ).toContain( 'No servers configured' );
	} );

	it( 'shows the error state when the fetch fails', async () => {
		mockSend.mockRejectedValue( new Error( 'aggregator down' ) );
		const { container } = mount();
		await flush();
		expect( container.textContent ).toContain( 'aggregator down' );
	} );

	it( 'shows the connection error info for a disconnected partition', async () => {
		mockSend.mockResolvedValue( SAMPLE );
		const { container } = mount();
		await flush();
		expect( container.textContent ).toContain( 'HTTP 504' );
		expect( container.textContent ).toContain( 'timeout' );
	} );

	it( 'shows the RTT badge for the heartbeat', async () => {
		mockSend.mockResolvedValue( SAMPLE );
		const { container } = mount();
		await flush();
		// formatRtt(42) → "42.0" (between 1 and 100).
		expect( container.textContent ).toContain( '42.0ms' );
	} );

	it( 'auto-refreshes on the configured interval', async () => {
		mockSend.mockResolvedValue( SAMPLE );
		mount();
		await flush();
		expect( mockSend ).toHaveBeenCalledTimes( 1 );
		act( () => {
			jest.advanceTimersByTime( 2000 ); // DEFAULT_REFRESH_MS.
		} );
		await flush();
		expect( mockSend.mock.calls.length ).toBeGreaterThanOrEqual( 2 );
	} );
} );
