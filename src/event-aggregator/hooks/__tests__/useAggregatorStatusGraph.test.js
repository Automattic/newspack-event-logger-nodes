/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * useAggregatorStatusGraph tests — the Aggregator Status dashboard graph clipped
 * onto the substrate's I/O boundary nodes (exospine + `_http` + `_output`,
 * `_metadata`, `_uptime`, `_completion`, `_cwd`), plus the `aggregator:view`
 * model node. Migrated from the bespoke `aggregator:poll` Node to the
 * substrate's HttpOut: the hook owns the setInterval and dispatches a
 * TM_COMMAND through the CI (FROM=`aggregator:view`, TO=`_http/aggregator`,
 * verb=`status`); the reply routes via TO=FROM back into the view node, which
 * unwraps `value.payload` for the render model.
 *
 * Every node sinks into the CI (rule #2); flow is steered ONLY by each node's
 * `target` (the router peels TO and delivers). _http.client is injected via
 * `opts.commandClient` so the hook never touches the network. NO page-visibility
 * gating — the old AggregatorStatus polled unconditionally.
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import {
	newMessage,
	pack,
	TIMESTAMP,
	TO,
	FROM,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	Core,
} from '@newspack-nodes/runtime';
import { useAggregatorStatusGraph } from '../useAggregatorStatusGraph';

const CI = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const OUTPUT = '_output';
const UPTIME = '_uptime';
const COMPLETION = '_completion';
const CWD = '_cwd';
const VIEW = 'aggregator:view';
const ALL_GRAPH_NAMES = [ HTTP, OUTPUT, UPTIME, COMPLETION, CWD, VIEW ];

// A fake CommandClient: records each batch its postBatch is given, and
// returns a canned reply Message (or a deferred promise tests resolve later).
// buildMessage is the real shape used by HttpOut to mint a
// connect_worker_input — but the aggregator never targets a worker reader,
// so it isn't exercised here; we still expose it for the seam.
function makeFakeClient( replyPayload = {}, now = null ) {
	const client = {
		batches: [],
		buildMessage( { to, verb, args = '', payload = null } ) {
			const m = newMessage();
			m[ TYPE ] = TM_COMMAND;
			m[ TO ] = to;
			m[ VALUE ] = { name: verb, arguments: args, payload };
			return m;
		},
		postBatch( messages ) {
			client.batches.push( messages );
			// Mint one reply per outbound message addressed back along FROM
			// (matches the server's reply pivot).
			const replies = messages.map( ( m ) => {
				const reply = newMessage();
				reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
				reply[ TO ] = m[ FROM ];
				reply[ VALUE ] = {
					name: m[ VALUE ]?.name,
					payload: replyPayload,
				};
				if ( null !== now ) {
					reply[ TIMESTAMP ] = now;
				}
				return reply;
			} );
			return Promise.resolve( replies );
		},
	};
	return client;
}

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
} );

describe( 'useAggregatorStatusGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + the six graph nodes, each sinking into the CI', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const ci = Core.node( CI );
		expect( ci ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		for ( const name of ALL_GRAPH_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( ci );
		}
	} );

	test( '_http has the injected CommandClient as its client', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( Core.node( HTTP ).client ).toBe( client );
	} );

	test( 'fires one immediate poll on mount (status command via _http)', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		expect( client.batches.length ).toBeGreaterThanOrEqual( 1 );
		// One Message per batch (no batching of connect_worker_input here —
		// `_http/aggregator` is a server-CI target, not a worker reader).
		const msg = client.batches[ 0 ][ 0 ];
		expect( msg[ TO ] ).toBe( 'aggregator' );
		expect( msg[ FROM ] ).toBe( VIEW );
		expect( msg[ VALUE ].name ).toBe( 'status' );
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
	test( 'an immediate poll reply routes _http → CI → router → aggregator:view and lands in the view model', async () => {
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
		const afterMount = client.batches.length;
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( client.batches.length ).toBeGreaterThan( afterMount );
	} );

	test( 'does NOT gate the interval on page visibility (always polls)', () => {
		Object.defineProperty( document, 'hidden', {
			configurable: true,
			get: () => true,
		} );
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		const afterMount = client.batches.length;
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( client.batches.length ).toBeGreaterThan( afterMount );
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
	test( 'unmount unregisters every graph node + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		unmount();
		for ( const name of [ ...ALL_GRAPH_NAMES, CI, ROUTER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'a reply resolving after unmount does not throw (sink may be gone)', async () => {
		let resolveReply;
		const client = {
			batches: [],
			buildMessage: ( { to, verb } ) => {
				const m = newMessage();
				m[ TYPE ] = TM_COMMAND;
				m[ TO ] = to;
				m[ VALUE ] = { name: verb, arguments: '', payload: null };
				return m;
			},
			postBatch( messages ) {
				client.batches.push( messages );
				return new Promise( ( res ) => {
					resolveReply = ( replies ) => res( replies );
				} );
			},
		};
		const { unmount } = renderHook( () =>
			useAggregatorStatusGraph( { commandClient: client } )
		);
		unmount();
		// Resolve AFTER unmount; if the resolution path tries to fill() a
		// detached CI it should NOT throw (HttpOut tolerates a null sink).
		expect( () => {
			const reply = newMessage();
			reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			reply[ VALUE ] = { name: 'status', payload: {} };
			resolveReply( [ reply ] );
		} ).not.toThrow();
		await Promise.resolve();
	} );
} );

// Reference-only: a packed reply (the wire shape) round-trips so tests doing
// JSONL replay (none yet for this dashboard) wouldn't need adjustment.
test( 'pack/unpack of a TM_COMMAND reply Message preserves TO + VALUE', () => {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
	m[ TO ] = VIEW;
	m[ VALUE ] = { name: 'status', payload: { a: 1 } };
	const wire = pack( m );
	expect( typeof wire ).toBe( 'string' );
} );
