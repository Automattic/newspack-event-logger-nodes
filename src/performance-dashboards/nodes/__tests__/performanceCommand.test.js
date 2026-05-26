/**
 * performance/command tests — the multi-verb command-out node behind an
 * injectable command-client seam. Each fetch* emits a synchronous `loading`
 * control then a `result`/`error`, tagged with its slice id; `resolveRequest`
 * RETURNS the unwrapped reply (navigation, no emit). The fake client resolves a
 * real Message-shaped reply so the production unwrapCommandResponse runs (mirrors
 * hookCatalogCommand.test.js — only the network boundary is faked).
 */

import { Core, newMessage, VALUE } from '@newspack-nodes/runtime';
import { createPerformanceCommand } from '../performanceCommand';

beforeEach( () => Core.reset() );

const reply = ( payload ) => {
	const m = newMessage();
	m[ VALUE ] = { payload };
	return m;
};

function fakeClient( payload ) {
	return {
		calls: [],
		send( a ) {
			this.calls.push( a );
			return Promise.resolve( reply( payload ) );
		},
	};
}

// A client whose send() always rejects — drives the open-state catch → error.
function rejectingClient( error ) {
	return {
		calls: [],
		send( a ) {
			this.calls.push( a );
			return Promise.reject( error );
		},
	};
}

test( 'fetchOverview emits loading then overview result with the right verb/args', async () => {
	const client = fakeClient( { total_requests: 3 } );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchOverview( 'web1', [ 'server', 'status' ] );
	expect( client.calls[ 0 ] ).toEqual( {
		to: 'performance',
		verb: 'overview',
		payload: {
			categories: true,
			server: 'web1',
			breakdown: 'server,status',
		},
	} );
	expect( got[ 0 ] ).toEqual( { action: 'loading', slice: 'overview' } );
	expect( got[ 1 ] ).toEqual( {
		action: 'result',
		slice: 'overview',
		data: { total_requests: 3 },
	} );
} );

test( 'fetchUrls forwards only present params + limit, result carries the full reply', async () => {
	const client = fakeClient( {
		data: [ { hash: 'a' } ],
		total: 1,
		limit: 100,
		offset: 0,
	} );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchUrls( { search: 'x', offset: 20 } );
	expect( client.calls[ 0 ].payload ).toEqual( {
		limit: 100,
		search: 'x',
		offset: 20,
	} );
	expect( got[ 1 ] ).toEqual( {
		action: 'result',
		slice: 'urls',
		data: { data: [ { hash: 'a' } ], total: 1, limit: 100, offset: 0 },
	} );
} );

test( 'fetchUrlDetail with invalid hash emits error and does NOT send', async () => {
	const client = fakeClient( {} );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchUrlDetail( 'NOT-HEX!' );
	expect( client.calls ).toHaveLength( 0 );
	expect( got ).toEqual( [
		{ action: 'loading', slice: 'urlDetail' },
		{
			action: 'error',
			slice: 'urlDetail',
			error: 'Invalid URL hash format',
		},
	] );
} );

test( 'fetchUrlDetail threads the initial flag', async () => {
	const client = fakeClient( { requests: [] } );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchUrlDetail( 'abc123', { categories: true, initial: true } );
	expect( client.calls[ 0 ].payload ).toEqual( {
		hash: 'abc123',
		categories: true,
	} );
	expect( got[ 1 ] ).toEqual( {
		action: 'result',
		slice: 'urlDetail',
		data: { requests: [] },
		initial: true,
	} );
} );

test( 'fetchRequestDetail validates rid and partition', async () => {
	const client = fakeClient( {} );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchRequestDetail( 'ok-rid', -1 );
	expect( client.calls ).toHaveLength( 0 );
	expect( got[ 1 ] ).toEqual( {
		action: 'error',
		slice: 'requestDetail',
		error: 'Invalid partition number',
	} );
} );

test( 'resolveRequest RETURNS the unwrapped reply and does NOT emit', async () => {
	const client = fakeClient( { url_hash: 'abc', partition: 2, url: '/x' } );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	const out = await n.resolveRequest( 'rid-9' );
	expect( client.calls[ 0 ] ).toEqual( {
		to: 'performance',
		verb: 'request_search',
		payload: { rid: 'rid-9' },
	} );
	expect( out ).toEqual( { url_hash: 'abc', partition: 2, url: '/x' } );
	expect( got ).toHaveLength( 0 );
} );

// Each emitting fetch* must, on a rejected send, emit loading then an error
// tagged with ITS OWN slice id — this is what catches a wrong-slice copy-paste
// in the catch block. Args are valid so the send is actually attempted.
test( 'fetchOverview surfaces a send rejection as an overview error', async () => {
	const client = rejectingClient( new Error( 'overview down' ) );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchOverview();
	expect( client.calls ).toHaveLength( 1 );
	expect( got[ 0 ] ).toEqual( { action: 'loading', slice: 'overview' } );
	expect( got[ 1 ] ).toEqual( {
		action: 'error',
		slice: 'overview',
		error: 'overview down',
	} );
} );

test( 'fetchUrls surfaces a send rejection as a urls error', async () => {
	const client = rejectingClient( new Error( 'urls down' ) );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchUrls();
	expect( client.calls ).toHaveLength( 1 );
	expect( got[ 0 ] ).toEqual( { action: 'loading', slice: 'urls' } );
	expect( got[ 1 ] ).toEqual( {
		action: 'error',
		slice: 'urls',
		error: 'urls down',
	} );
} );

test( 'fetchUrlDetail (valid hash) surfaces a send rejection as a urlDetail error', async () => {
	const client = rejectingClient( new Error( 'detail down' ) );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchUrlDetail( 'abc123' );
	expect( client.calls ).toHaveLength( 1 );
	expect( got[ 0 ] ).toEqual( { action: 'loading', slice: 'urlDetail' } );
	expect( got[ 1 ] ).toEqual( {
		action: 'error',
		slice: 'urlDetail',
		error: 'detail down',
	} );
} );

test( 'fetchRequestDetail (valid rid+partition) surfaces a send rejection as a requestDetail error', async () => {
	const client = rejectingClient( new Error( 'req down' ) );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchRequestDetail( 'ok-rid', 1 );
	expect( client.calls ).toHaveLength( 1 );
	expect( got[ 0 ] ).toEqual( {
		action: 'loading',
		slice: 'requestDetail',
	} );
	expect( got[ 1 ] ).toEqual( {
		action: 'error',
		slice: 'requestDetail',
		error: 'req down',
	} );
} );

test( 'fetchRequestDetail with an invalid rid emits error and does NOT send', async () => {
	const client = fakeClient( {} );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchRequestDetail( 'bad rid!', 0 );
	expect( client.calls ).toHaveLength( 0 );
	expect( got ).toEqual( [
		{ action: 'loading', slice: 'requestDetail' },
		{
			action: 'error',
			slice: 'requestDetail',
			error: 'Invalid request ID format',
		},
	] );
} );

test( 'fetchRequestDetail success sends { rid, partition } and emits the result (partition 0 accepted)', async () => {
	const client = fakeClient( { rid: 'ok-rid', url: '/x' } );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	await n.fetchRequestDetail( 'ok-rid', 0 );
	expect( client.calls[ 0 ] ).toEqual( {
		to: 'performance',
		verb: 'request_detail',
		payload: { rid: 'ok-rid', partition: 0 },
	} );
	expect( got[ 0 ] ).toEqual( {
		action: 'loading',
		slice: 'requestDetail',
	} );
	expect( got[ 1 ] ).toEqual( {
		action: 'result',
		slice: 'requestDetail',
		data: { rid: 'ok-rid', url: '/x' },
	} );
} );

test( 'resolveRequest returns null on a send rejection and emits nothing', async () => {
	const client = rejectingClient( new Error( 'search down' ) );
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	const out = await n.resolveRequest( 'rid-9' );
	expect( out ).toBeNull();
	expect( got ).toHaveLength( 0 );
} );

test( 'a send rejecting after close() emits nothing', async () => {
	let rej;
	const client = {
		send: () =>
			new Promise( ( _, r ) => {
				rej = r;
			} ),
	};
	const n = createPerformanceCommand( 'performance/command', {
		commandClient: client,
	} );
	const got = [];
	n.sink = { fill: ( m ) => got.push( m[ VALUE ] ) };
	const p = n.fetchOverview();
	n.close();
	rej( new Error( 'boom' ) );
	await p.catch( () => {} );
	expect( got ).toEqual( [ { action: 'loading', slice: 'overview' } ] );
} );
