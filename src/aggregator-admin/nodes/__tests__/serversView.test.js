/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * servers:view tests — owns the Configured-Servers admin view model after the
 * substrate-canonical migration.
 *
 * Post-migration, `fill()` receives the raw reply Messages HttpOut feeds back
 * from POST /command: the router peels the reply's TO (= `servers:view`, stamped
 * from the outbound FROM by the server's reply pivot) and delivers them here.
 * VALUE is the `{ name, payload }` envelope; the node unwraps `value.payload`.
 *
 * On a `list` reply it updates the render model. On `add/update/delete/test`
 * replies (or TM_ERROR), it resolves/rejects the pending promise keyed by
 * `message[ID]` (the hook stamps the ID and stores the resolver before fill).
 * TM_ERROR also surfaces the error into the view model. Mirrors aggregator:view.
 */

import {
	VALUE,
	ID,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createServersView } from '../serversView';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

const SAMPLE = {
	'spoke-01': {
		id: 'spoke-01',
		url: 'https://a.example.test',
		enabled: true,
		logs: [ 'firehose.log' ],
		has_credentials: true,
		is_config: false,
	},
	'spoke-02': {
		id: 'spoke-02',
		url: 'https://b.example.test',
		enabled: false,
		logs: [],
		has_credentials: false,
		is_config: true,
	},
};

// Build the verb-reply Message HttpOut feeds back (TO already peeled by router).
function replyMsg( {
	name,
	payload,
	type = TM_COMMAND | TM_RESPONSE,
	id = '',
} ) {
	const m = newMessage();
	m[ TYPE ] = type;
	m[ ID ] = id;
	m[ VALUE ] = { name, payload };
	return m;
}

describe( 'servers:view — initial model', () => {
	test( 'publishes an initial loading model on construction', () => {
		const v = createServersView( 'servers:view' );
		expect( v.setStateCache.view ).toMatchObject( {
			servers: null,
			loading: true,
			error: null,
		} );
	} );

	test( 'names the node', () => {
		const v = createServersView( 'servers:view' );
		expect( v.name ).toBe( 'servers:view' );
	} );

	test( 'has a `pending` Map for hook-side promise resolution', () => {
		const v = createServersView( 'servers:view' );
		expect( v.pending ).toBeInstanceOf( Map );
		expect( v.pending.size ).toBe( 0 );
	} );
} );

describe( 'servers:view — list reply updates the render model', () => {
	test( 'a list reply converts the server map to an array of servers', () => {
		const v = createServersView( 'servers:view' );
		v.fill( replyMsg( { name: 'list', payload: SAMPLE } ) );
		const model = v.setStateCache.view;
		expect( Array.isArray( model.servers ) ).toBe( true );
		expect( model.servers ).toHaveLength( 2 );
		expect( model.servers.map( ( s ) => s.id ) ).toEqual( [
			'spoke-01',
			'spoke-02',
		] );
	} );

	test( 'a list reply clears loading and any prior error', () => {
		const v = createServersView( 'servers:view' );
		// Simulate an error first.
		const errMsg = replyMsg( {
			name: 'list',
			payload: 'boom',
			type: TM_COMMAND | TM_ERROR,
		} );
		v.fill( errMsg );
		expect( v.setStateCache.view.error ).toBe( 'boom' );
		v.fill( replyMsg( { name: 'list', payload: SAMPLE } ) );
		expect( v.setStateCache.view.loading ).toBe( false );
		expect( v.setStateCache.view.error ).toBeNull();
	} );

	test( 'an empty list payload yields an empty servers array', () => {
		const v = createServersView( 'servers:view' );
		v.fill( replyMsg( { name: 'list', payload: {} } ) );
		expect( v.setStateCache.view.servers ).toEqual( [] );
		expect( v.setStateCache.view.loading ).toBe( false );
	} );

	test( 'a null list payload yields an empty servers array', () => {
		const v = createServersView( 'servers:view' );
		v.fill( replyMsg( { name: 'list', payload: null } ) );
		expect( v.setStateCache.view.servers ).toEqual( [] );
	} );
} );

describe( 'servers:view — TM_ERROR replies surface the error', () => {
	test( 'a TM_ERROR with no matching pending entry sets the error and clears loading (prior servers preserved)', () => {
		const v = createServersView( 'servers:view' );
		v.fill( replyMsg( { name: 'list', payload: SAMPLE } ) );
		v.fill(
			replyMsg( {
				name: 'list',
				payload: 'registry down',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		const model = v.setStateCache.view;
		expect( model.error ).toBe( 'registry down' );
		expect( model.loading ).toBe( false );
		expect( model.servers ).toHaveLength( 2 );
	} );

	test( 'TM_ERROR without a message defaults the error string', () => {
		const v = createServersView( 'servers:view' );
		v.fill(
			replyMsg( {
				name: 'add',
				payload: null,
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( typeof v.setStateCache.view.error ).toBe( 'string' );
		expect( v.setStateCache.view.error.length ).toBeGreaterThan( 0 );
	} );

	test( 'TM_ERROR matching a pending entry does NOT pollute global view.error (caller handles it)', () => {
		// Per-row test() probes catch their own errors and dispatch a local
		// snackbar — surfacing them globally would also paint a table-wide red
		// banner for a single-row failure. The caller's rejection IS the error
		// surface; the global view.error is reserved for un-correlated failures
		// (initial list, broadcast errors).
		const v = createServersView( 'servers:view' );
		v.fill( replyMsg( { name: 'list', payload: SAMPLE } ) );
		expect( v.setStateCache.view.error ).toBeNull();
		const resolve = jest.fn();
		const reject = jest.fn();
		v.pending.set( 'probe-7', { resolve, reject } );
		v.fill(
			replyMsg( {
				id: 'probe-7',
				name: 'test',
				payload: 'connection refused',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( reject ).toHaveBeenCalledTimes( 1 );
		expect( v.setStateCache.view.error ).toBeNull();
	} );

	test( 'TM_ERROR with a structured {message} payload extracts the message field', () => {
		// The server-side service-CI / interpret() catch may pack a structured
		// error (e.g. { message, code, field }) into VALUE.payload mirroring the
		// success path's structured shape; the view should still surface the
		// human-readable message rather than falling back to 'Operation failed'.
		const v = createServersView( 'servers:view' );
		const reject = jest.fn();
		v.pending.set( 'op-9', { resolve: jest.fn(), reject } );
		v.fill(
			replyMsg( {
				id: 'op-9',
				name: 'add',
				payload: { message: 'duplicate id', code: 'E_DUP' },
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( reject ).toHaveBeenCalledTimes( 1 );
		expect( reject.mock.calls[ 0 ][ 0 ].message ).toBe( 'duplicate id' );
	} );
} );

describe( 'servers:view — pending-promise resolution', () => {
	test( 'a successful reply resolves the pending promise with the payload', async () => {
		const v = createServersView( 'servers:view' );
		const resolve = jest.fn();
		const reject = jest.fn();
		v.pending.set( 'op-1', { resolve, reject } );
		v.fill(
			replyMsg( { id: 'op-1', name: 'add', payload: { id: 'spoke-01' } } )
		);
		expect( resolve ).toHaveBeenCalledWith( { id: 'spoke-01' } );
		expect( reject ).not.toHaveBeenCalled();
		// Pending entry cleared.
		expect( v.pending.has( 'op-1' ) ).toBe( false );
	} );

	test( 'a TM_ERROR reply rejects the pending promise and clears the entry', () => {
		const v = createServersView( 'servers:view' );
		const resolve = jest.fn();
		const reject = jest.fn();
		v.pending.set( 'op-2', { resolve, reject } );
		v.fill(
			replyMsg( {
				id: 'op-2',
				name: 'add',
				payload: 'duplicate id',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( reject ).toHaveBeenCalledTimes( 1 );
		expect( reject.mock.calls[ 0 ][ 0 ] ).toBeInstanceOf( Error );
		expect( reject.mock.calls[ 0 ][ 0 ].message ).toContain(
			'duplicate id'
		);
		expect( resolve ).not.toHaveBeenCalled();
		expect( v.pending.has( 'op-2' ) ).toBe( false );
	} );

	test( 'a list reply still updates the render model when also resolving a pending promise', () => {
		const v = createServersView( 'servers:view' );
		const resolve = jest.fn();
		v.pending.set( 'op-3', { resolve, reject: jest.fn() } );
		v.fill( replyMsg( { id: 'op-3', name: 'list', payload: SAMPLE } ) );
		expect( v.setStateCache.view.servers ).toHaveLength( 2 );
		expect( resolve ).toHaveBeenCalledWith( SAMPLE );
	} );

	test( 'a reply without a matching pending entry is handled normally (no throw)', () => {
		const v = createServersView( 'servers:view' );
		expect( () =>
			v.fill(
				replyMsg( {
					id: 'no-such-op',
					name: 'add',
					payload: { id: 'x' },
				} )
			)
		).not.toThrow();
	} );

	test( 'a reply with no ID is handled normally (no pending lookup)', () => {
		const v = createServersView( 'servers:view' );
		expect( () =>
			v.fill( replyMsg( { name: 'list', payload: SAMPLE } ) )
		).not.toThrow();
		expect( v.setStateCache.view.servers ).toHaveLength( 2 );
	} );
} );

describe( 'servers:view — malformed input', () => {
	test( 'ignores a message with no VALUE', () => {
		const v = createServersView( 'servers:view' );
		const initial = v.setStateCache.view;
		const m = newMessage();
		m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
		v.fill( m );
		expect( v.setStateCache.view ).toBe( initial );
	} );

	test( 'ignores a message with a non-object VALUE', () => {
		const v = createServersView( 'servers:view' );
		const initial = v.setStateCache.view;
		const m = newMessage();
		m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
		m[ VALUE ] = 'not-an-object';
		v.fill( m );
		expect( v.setStateCache.view ).toBe( initial );
	} );
} );
