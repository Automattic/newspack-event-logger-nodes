/**
 * aggregator:view tests — owns the Aggregator Status view model.
 *
 * The poll node emits a TM_STRUCT `{ action:'status', status, now }` (or
 * `{ action:'error', error }`); this node turns the raw `{ server_id:{} }` status
 * map into the render model — `servers` (array via Object.values), `serverNow`,
 * `connectedCount` / `totalCount`, `error`, `loading`, `lastRefresh` — and
 * publishes it via `setState('view', model)`. The React view reads it with
 * `useNodeState('aggregator:view','view')`. The map→array + connected-count
 * derivation moved here verbatim from AggregatorStatus's render.
 */

import {
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createAggregatorView } from '../aggregatorView';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A control message: TM_STRUCT carrying { action, ... }.
function controlMsg( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = payload;
	return m;
}

const SAMPLE = {
	server1: {
		id: 'server1',
		url: 'https://a.example.test',
		enabled: true,
		partitions: {
			0: { last_connection_status: 'connected' },
			1: { last_connection_status: 'disconnected' },
		},
	},
	server2: {
		id: 'server2',
		url: 'https://b.example.test',
		enabled: false,
		partitions: {},
	},
};

test( 'publishes an initial loading model on construction', () => {
	const v = createAggregatorView( 'aggregator:view' );
	expect( v.setStateCache.view ).toMatchObject( {
		servers: null,
		loading: true,
		error: null,
	} );
} );

test( 'a status control converts the server map to an array of servers', () => {
	const v = createAggregatorView( 'aggregator:view' );
	v.fill( controlMsg( { action: 'status', status: SAMPLE, now: 100 } ) );
	const model = v.setStateCache.view;
	expect( Array.isArray( model.servers ) ).toBe( true );
	expect( model.servers ).toHaveLength( 2 );
	expect( model.servers.map( ( s ) => s.id ) ).toEqual( [
		'server1',
		'server2',
	] );
} );

test( 'a status control stores serverNow from the poll', () => {
	const v = createAggregatorView( 'aggregator:view' );
	v.fill(
		controlMsg( { action: 'status', status: SAMPLE, now: 1748960000 } )
	);
	expect( v.setStateCache.view.serverNow ).toBe( 1748960000 );
} );

test( 'computes connectedCount (servers with >=1 connected partition) and totalCount', () => {
	const v = createAggregatorView( 'aggregator:view' );
	v.fill( controlMsg( { action: 'status', status: SAMPLE, now: 1 } ) );
	const model = v.setStateCache.view;
	// Only server1 has a connected partition.
	expect( model.connectedCount ).toBe( 1 );
	expect( model.totalCount ).toBe( 2 );
} );

test( 'a status control clears loading and any prior error', () => {
	const v = createAggregatorView( 'aggregator:view' );
	v.fill( controlMsg( { action: 'error', error: 'boom' } ) );
	expect( v.setStateCache.view.error ).toBe( 'boom' );
	v.fill( controlMsg( { action: 'status', status: SAMPLE, now: 1 } ) );
	expect( v.setStateCache.view.loading ).toBe( false );
	expect( v.setStateCache.view.error ).toBeNull();
} );

test( 'a status control sets lastRefresh (a browser-clock ms number)', () => {
	const v = createAggregatorView( 'aggregator:view' );
	const before = Date.now();
	v.fill( controlMsg( { action: 'status', status: SAMPLE, now: 1 } ) );
	const { lastRefresh } = v.setStateCache.view;
	expect( typeof lastRefresh ).toBe( 'number' );
	expect( lastRefresh ).toBeGreaterThanOrEqual( before );
} );

test( 'an empty status map yields an empty servers array, connected 0 / total 0', () => {
	const v = createAggregatorView( 'aggregator:view' );
	v.fill( controlMsg( { action: 'status', status: {}, now: 1 } ) );
	const model = v.setStateCache.view;
	expect( model.servers ).toEqual( [] );
	expect( model.connectedCount ).toBe( 0 );
	expect( model.totalCount ).toBe( 0 );
} );

test( 'an error control sets the error and clears loading (servers untouched)', () => {
	const v = createAggregatorView( 'aggregator:view' );
	v.fill( controlMsg( { action: 'status', status: SAMPLE, now: 1 } ) );
	v.fill( controlMsg( { action: 'error', error: 'aggregator down' } ) );
	const model = v.setStateCache.view;
	expect( model.error ).toBe( 'aggregator down' );
	expect( model.loading ).toBe( false );
	// Prior servers are preserved across a transient error (matches the old
	// fetchStatus catch, which only set error and never cleared servers).
	expect( model.servers ).toHaveLength( 2 );
} );

test( 'ignores a message with no action', () => {
	const v = createAggregatorView( 'aggregator:view' );
	const initial = v.setStateCache.view;
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = { status: SAMPLE };
	v.fill( m );
	// Unchanged: still the initial loading model.
	expect( v.setStateCache.view ).toBe( initial );
} );

test( 'names the node', () => {
	const v = createAggregatorView( 'aggregator:view' );
	expect( v.name ).toBe( 'aggregator:view' );
} );
