/**
 * aggregator/poll tests — the command-out node that owns the aggregator status
 * traffic, behind an injectable command-client seam.
 *
 * `poll()` sends `{ to:'aggregator', verb:'status' }`, unwraps the reply, and
 * emits a TM_STRUCT `{ action:'status', status, now }` to its sink (→
 * aggregator/view). `now` is the response Message's TIMESTAMP — the hub's serve
 * clock, which the view drives "ago" off. Failures surface as a TM_STRUCT
 * `{ action:'error', error }` (never swallowed). `close()` drops a late reply so
 * an in-flight poll resolving post-unmount never fills a detached sink.
 *
 * The command boundary is the injected fake (`opts.commandClient`); the unwrap
 * helper is mocked so the reply shape is whatever the test hands back.
 */

import {
	newMessage,
	TYPE,
	TIMESTAMP,
	VALUE,
	TM_STRUCT,
	Core,
} from '@newspack-nodes/runtime';
import { createAggregatorPoll } from '../aggregatorPoll';

// unwrapCommandResponse is mocked so a test controls the unwrapped payload
// independently of the raw Message it hands the fake client (matches the
// reference AggregatorStatus suite, which mocked the same helper).
jest.mock( '../../../shared/utils/unwrapCommandResponse', () => ( {
	__esModule: true,
	default: jest.fn( ( msg ) => msg ),
} ) );
const unwrap = require( '../../../shared/utils/unwrapCommandResponse' ).default;

// setName registers in the per-process Core registry; clear it between tests so
// re-creating the same-named node doesn't collide.
beforeEach( () => {
	Core.reset();
	unwrap.mockReset();
	unwrap.mockImplementation( ( msg ) => msg );
} );

// A fake command client matching the seam the node depends on: send() resolves
// the canned reply; tests can hold it pending (close-guard test) by resolving a
// deferred. Records every send arg.
function makeFakeClient() {
	return {
		calls: [],
		_resolve: null,
		reply: null,
		send( args ) {
			this.calls.push( args );
			if ( this.reply && this.reply.then ) {
				return this.reply;
			}
			return Promise.resolve( this.reply );
		},
	};
}

describe( 'aggregator/poll', () => {
	test( 'sends the status command to the aggregator', async () => {
		const client = makeFakeClient();
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		await node.poll();
		expect( client.calls ).toHaveLength( 1 );
		expect( client.calls[ 0 ] ).toEqual( {
			to: 'aggregator',
			verb: 'status',
		} );
	} );

	test( 'emits a TM_STRUCT status control with the unwrapped status to its sink', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockReturnValue( { server1: { id: 'server1' } } );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.poll();
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( got[ 0 ][ VALUE ].action ).toBe( 'status' );
		expect( got[ 0 ][ VALUE ].status ).toEqual( {
			server1: { id: 'server1' },
		} );
	} );

	test( 'passes the response Message TIMESTAMP as `now` for ago-calc', async () => {
		const got = [];
		const client = makeFakeClient();
		const message = newMessage();
		message[ TIMESTAMP ] = 2000;
		client.reply = message;
		unwrap.mockReturnValue( {} );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.poll();
		expect( got[ 0 ][ VALUE ].now ).toBe( 2000 );
	} );

	test( 'a null/empty unwrap yields an empty status map (not undefined)', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockReturnValue( null );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.poll();
		expect( got[ 0 ][ VALUE ].status ).toEqual( {} );
	} );

	test( 'surfaces a send failure as a TM_STRUCT error control (does not swallow)', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = Promise.reject( new Error( 'aggregator down' ) );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.poll();
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( got[ 0 ][ VALUE ].action ).toBe( 'error' );
		expect( got[ 0 ][ VALUE ].error ).toContain( 'aggregator down' );
	} );

	test( 'surfaces an unwrap throw (TM_ERROR reply) as an error control', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockImplementation( () => {
			throw new Error( 'command failed' );
		} );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.poll();
		expect( got[ 0 ][ VALUE ].action ).toBe( 'error' );
		expect( got[ 0 ][ VALUE ].error ).toContain( 'command failed' );
	} );

	test( 'close() drops a reply that resolves after unmount (no detached fill)', async () => {
		const got = [];
		const client = makeFakeClient();
		let resolveReply;
		client.reply = new Promise( ( res ) => {
			resolveReply = res;
		} );
		unwrap.mockReturnValue( {} );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		const pending = node.poll();
		node.close();
		resolveReply( newMessage() );
		await pending;
		expect( got ).toHaveLength( 0 );
	} );

	test( 'close() drops a late error too (no detached error fill)', async () => {
		const got = [];
		const client = makeFakeClient();
		let rejectReply;
		client.reply = new Promise( ( _res, rej ) => {
			rejectReply = rej;
		} );
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		const pending = node.poll();
		node.close();
		rejectReply( new Error( 'late' ) );
		await pending;
		expect( got ).toHaveLength( 0 );
	} );

	test( 'names the node', () => {
		const client = makeFakeClient();
		const node = createAggregatorPoll( 'aggregator/poll', {
			commandClient: client,
		} );
		expect( node.name ).toBe( 'aggregator/poll' );
	} );
} );
