/**
 * servers:command tests — the command-out node that owns the Configured-Servers
 * admin traffic, behind an injectable command-client seam.
 *
 * `list()` sends `{ to:'servers', verb:'list' }`, unwraps the reply (the
 * `{ id:public_shape }` map), and emits a TM_STRUCT `{ action:'servers', servers }`
 * to its sink (the exospine CI) stamped with TO=target (the router peels TO and
 * delivers to servers:view). Failures surface as `{ action:'error', error }`
 * (never swallowed). The mutation methods `add/update/remove/test` delegate to the
 * api.js wrappers with the node's client and return their result — they do NOT
 * emit; the hook re-`list()`s after a mutation to refresh the table.
 * `close()` drops a late list reply so an in-flight list resolving post-unmount
 * never fills a detached sink. Mirrors aggregator:poll + workerstatus:poll.
 *
 * The api.js wrappers are mocked so the test asserts delegation (which wrapper,
 * which client, which args) without re-testing api.js. The unwrap helper used by
 * `list()` is mocked so the reply shape is whatever the test hands back.
 */

import {
	newMessage,
	TYPE,
	TO,
	VALUE,
	TM_STRUCT,
	Core,
} from '@newspack-nodes/runtime';
import { createServersCommand } from '../serversCommand';

// The api.js CRUD wrappers are mocked: the command node is just supposed to
// delegate to them with its client, so we assert the delegation, not api.js.
jest.mock( '../../api', () => ( {
	addServer: jest.fn(),
	updateServer: jest.fn(),
	removeServer: jest.fn(),
	testServer: jest.fn(),
} ) );
const api = require( '../../api' );

// unwrapCommandResponse is mocked so a test controls the unwrapped payload the
// list() reply yields independently of the raw Message handed the fake client.
jest.mock( '../../../shared/utils/unwrapCommandResponse', () => ( {
	__esModule: true,
	default: jest.fn( ( msg ) => msg ),
} ) );
const unwrap = require( '../../../shared/utils/unwrapCommandResponse' ).default;

beforeEach( () => {
	Core.reset();
	api.addServer.mockReset();
	api.updateServer.mockReset();
	api.removeServer.mockReset();
	api.testServer.mockReset();
	unwrap.mockReset();
	unwrap.mockImplementation( ( msg ) => msg );
} );

// A fake command client matching the seam the node depends on: send() resolves
// the canned reply; tests can hold it pending (close-guard test) by handing a
// thenable. Records every send arg.
function makeFakeClient() {
	return {
		calls: [],
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

describe( 'servers:command — list', () => {
	test( 'sends the list command to the servers CI', async () => {
		const client = makeFakeClient();
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		await node.list();
		expect( client.calls ).toHaveLength( 1 );
		expect( client.calls[ 0 ] ).toEqual( {
			to: 'servers',
			verb: 'list',
		} );
	} );

	test( 'emits a TM_STRUCT servers control with the unwrapped map to its sink', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockReturnValue( { 'spoke-01': { id: 'spoke-01' } } );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.list();
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( got[ 0 ][ VALUE ].action ).toBe( 'servers' );
		expect( got[ 0 ][ VALUE ].servers ).toEqual( {
			'spoke-01': { id: 'spoke-01' },
		} );
	} );

	test( 'stamps the emitted message TO with its target (rule #2 routing)', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockReturnValue( {} );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.target = 'servers:view';
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.list();
		expect( got[ 0 ][ TO ] ).toBe( 'servers:view' );
	} );

	test( 'a null/empty unwrap yields an empty servers map (not undefined)', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockReturnValue( null );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.list();
		expect( got[ 0 ][ VALUE ].servers ).toEqual( {} );
	} );

	test( 'surfaces a send failure as a TM_STRUCT error control (does not swallow)', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = Promise.reject( new Error( 'registry down' ) );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.list();
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( got[ 0 ][ VALUE ].action ).toBe( 'error' );
		expect( got[ 0 ][ VALUE ].error ).toContain( 'registry down' );
	} );

	test( 'surfaces an unwrap throw (TM_ERROR reply) as an error control', async () => {
		const got = [];
		const client = makeFakeClient();
		client.reply = newMessage();
		unwrap.mockImplementation( () => {
			throw new Error( 'command failed' );
		} );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		await node.list();
		expect( got[ 0 ][ VALUE ].action ).toBe( 'error' );
		expect( got[ 0 ][ VALUE ].error ).toContain( 'command failed' );
	} );

	test( 'close() drops a list reply that resolves after unmount (no detached fill)', async () => {
		const got = [];
		const client = makeFakeClient();
		let resolveReply;
		client.reply = new Promise( ( res ) => {
			resolveReply = res;
		} );
		unwrap.mockReturnValue( {} );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		const pending = node.list();
		node.close();
		resolveReply( newMessage() );
		await pending;
		expect( got ).toHaveLength( 0 );
	} );

	test( 'close() drops a late list error too (no detached error fill)', async () => {
		const got = [];
		const client = makeFakeClient();
		let rejectReply;
		client.reply = new Promise( ( _res, rej ) => {
			rejectReply = rej;
		} );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		node.sink = { fill: ( m ) => got.push( m ) };
		const pending = node.list();
		node.close();
		rejectReply( new Error( 'late' ) );
		await pending;
		expect( got ).toHaveLength( 0 );
	} );
} );

describe( 'servers:command — mutations delegate to api.js with the node client', () => {
	test( 'add() calls api.addServer with the node client + fields and returns its result', async () => {
		const client = makeFakeClient();
		api.addServer.mockResolvedValue( { id: 'spoke-01' } );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		const fields = { id: 'spoke-01', url: 'https://x' };
		const result = await node.add( fields );
		expect( api.addServer ).toHaveBeenCalledWith( client, fields );
		expect( result ).toEqual( { id: 'spoke-01' } );
	} );

	test( 'update() calls api.updateServer with the node client + id + partial', async () => {
		const client = makeFakeClient();
		api.updateServer.mockResolvedValue( { id: 'spoke-01' } );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		await node.update( 'spoke-01', { enabled: false } );
		expect( api.updateServer ).toHaveBeenCalledWith( client, 'spoke-01', {
			enabled: false,
		} );
	} );

	test( 'remove() calls api.removeServer with the node client + id', async () => {
		const client = makeFakeClient();
		api.removeServer.mockResolvedValue( { id: 'spoke-01' } );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		await node.remove( 'spoke-01' );
		expect( api.removeServer ).toHaveBeenCalledWith( client, 'spoke-01' );
	} );

	test( 'test() calls api.testServer with the node client + id and returns the probe', async () => {
		const client = makeFakeClient();
		const probe = { id: 'spoke-01', status: 'connected', response: {} };
		api.testServer.mockResolvedValue( probe );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		const result = await node.test( 'spoke-01' );
		expect( api.testServer ).toHaveBeenCalledWith( client, 'spoke-01' );
		expect( result ).toEqual( probe );
	} );

	test( 'a mutation rejection propagates to the caller (the hook surfaces it)', async () => {
		const client = makeFakeClient();
		api.addServer.mockRejectedValue( new Error( 'duplicate' ) );
		const node = createServersCommand( 'servers:command', {
			commandClient: client,
		} );
		await expect( node.add( { id: 'x' } ) ).rejects.toThrow( 'duplicate' );
	} );
} );

test( 'names the node', () => {
	const client = makeFakeClient();
	const node = createServersCommand( 'servers:command', {
		commandClient: client,
	} );
	expect( node.name ).toBe( 'servers:command' );
} );
