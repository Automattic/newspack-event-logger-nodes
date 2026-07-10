/**
 * hookCatalogView tests — owns the Performance Logger hook-catalog view model
 * after the substrate-canonical migration. Mirrors servers:view.
 *
 * Post-migration, `fill()` receives the raw reply Messages HttpOutNode feeds back
 * from POST /command: the router peels the reply's TO (= `hookcatalog:view`,
 * stamped from the outbound FROM by the server's TO=FROM reply) and delivers them
 * here. VALUE is the `{ name, payload }` envelope.
 *
 * On a `hooks_registered` reply the node turns the raw
 * `{ hooks_by_category }` payload into the render model — `hooksByCategory`,
 * clears `loading` + `error`. The node also matches `message[ID]` against
 * `pending` so the hook's Promise resolves. TM_ERROR rejects the matching
 * pending Promise; pending-matched errors do NOT pollute global view.error
 * (caller's catch is the error surface for correlated failures).
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
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';
import { HookCatalogViewNode } from '../hook-catalog-view-node';

beforeEach( () => Core.reset() );

// Construct + name directly — createX factory is gone; bare-new is the seam.
function makeView( name ) {
	const node = new HookCatalogViewNode();
	node.name = name;
	return node;
}

const SAMPLE = {
	Lifecycle: [ 'init', 'shutdown' ],
	'REST API': [ 'rest_api_init' ],
};

// Build the verb-reply Message HttpOutNode feeds back (TO peeled by router).
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

describe( 'hookcatalog:view — initial model', () => {
	test( 'publishes an initial loading model on construction', () => {
		const v = makeView( 'hookcatalog:view' );
		expect( v.setStateCache.view ).toMatchObject( {
			hooksByCategory: {},
			loading: true,
			error: null,
		} );
	} );

	test( 'names the node', () => {
		const v = makeView( 'hookcatalog:view' );
		expect( v.name ).toBe( 'hookcatalog:view' );
	} );

	test( 'has a `replies` registry for hook-side promise resolution', () => {
		const v = makeView( 'hookcatalog:view' );
		expect( v.replies ).toBeInstanceOf( PendingReplies );
		expect( v.replies.size ).toBe( 0 );
	} );
} );

describe( 'hookcatalog:view — hooks_registered reply updates the render model', () => {
	test( 'extracts hooks_by_category from the reply payload', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: { hooks_by_category: SAMPLE, total_hooks: 3 },
			} )
		);
		const model = v.setStateCache.view;
		expect( model.hooksByCategory ).toEqual( SAMPLE );
		expect( model.loading ).toBe( false );
		expect( model.error ).toBeNull();
	} );

	test( 'an empty hooks_by_category yields an empty map', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: { hooks_by_category: {} },
			} )
		);
		expect( v.setStateCache.view.hooksByCategory ).toEqual( {} );
		expect( v.setStateCache.view.loading ).toBe( false );
	} );

	test( 'a null hooks_by_category yields an empty map', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: { hooks_by_category: null },
			} )
		);
		expect( v.setStateCache.view.hooksByCategory ).toEqual( {} );
	} );

	test( 'a missing payload yields an empty map (still clears loading)', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill( replyMsg( { name: 'hooks_registered', payload: null } ) );
		expect( v.setStateCache.view.hooksByCategory ).toEqual( {} );
		expect( v.setStateCache.view.loading ).toBe( false );
	} );

	test( 'a reply clears a prior error', () => {
		const v = makeView( 'hookcatalog:view' );
		// Surface a non-pending error first.
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: 'boom',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( v.setStateCache.view.error ).toBe( 'boom' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: { hooks_by_category: SAMPLE },
			} )
		);
		expect( v.setStateCache.view.error ).toBeNull();
	} );
} );

describe( 'hookcatalog:view — TM_ERROR replies surface the error', () => {
	test( 'an un-correlated TM_ERROR sets the error and clears loading (prior map preserved)', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: { hooks_by_category: SAMPLE },
			} )
		);
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: 'service down',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		const model = v.setStateCache.view;
		expect( model.error ).toBe( 'service down' );
		expect( model.loading ).toBe( false );
		expect( model.hooksByCategory ).toEqual( SAMPLE );
	} );

	test( 'TM_ERROR without a message defaults the error string', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: null,
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( typeof v.setStateCache.view.error ).toBe( 'string' );
		expect( v.setStateCache.view.error.length ).toBeGreaterThan( 0 );
	} );

	test( 'TM_ERROR matching a pending entry does NOT pollute global view.error', () => {
		const v = makeView( 'hookcatalog:view' );
		v.fill(
			replyMsg( {
				name: 'hooks_registered',
				payload: { hooks_by_category: SAMPLE },
			} )
		);
		expect( v.setStateCache.view.error ).toBeNull();
		const resolve = jest.fn();
		const reject = jest.fn();
		v.replies.add( 'op-7', resolve, reject );
		v.fill(
			replyMsg( {
				id: 'op-7',
				name: 'hooks_registered',
				payload: 'forbidden',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( reject ).toHaveBeenCalledTimes( 1 );
		expect( v.setStateCache.view.error ).toBeNull();
	} );

	test( 'TM_ERROR with a structured {message} payload extracts the message field', () => {
		const v = makeView( 'hookcatalog:view' );
		const reject = jest.fn();
		v.replies.add( 'op-9', jest.fn(), reject );
		v.fill(
			replyMsg( {
				id: 'op-9',
				name: 'hooks_registered',
				payload: { message: 'capability check failed', code: 'E_PERM' },
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( reject ).toHaveBeenCalledTimes( 1 );
		expect( reject.mock.calls[ 0 ][ 0 ].message ).toBe(
			'capability check failed'
		);
	} );
} );

describe( 'hookcatalog:view — pending-promise resolution', () => {
	test( 'a successful reply resolves the pending promise with the payload', () => {
		const v = makeView( 'hookcatalog:view' );
		const resolve = jest.fn();
		const reject = jest.fn();
		v.replies.add( 'op-1', resolve, reject );
		const payload = { hooks_by_category: SAMPLE, total_hooks: 3 };
		v.fill( replyMsg( { id: 'op-1', name: 'hooks_registered', payload } ) );
		expect( resolve ).toHaveBeenCalledWith( payload );
		expect( reject ).not.toHaveBeenCalled();
		expect( v.replies.has( 'op-1' ) ).toBe( false );
	} );

	test( 'a TM_ERROR reply rejects the pending promise and clears the entry', () => {
		const v = makeView( 'hookcatalog:view' );
		const resolve = jest.fn();
		const reject = jest.fn();
		v.replies.add( 'op-2', resolve, reject );
		v.fill(
			replyMsg( {
				id: 'op-2',
				name: 'hooks_registered',
				payload: 'boom',
				type: TM_COMMAND | TM_ERROR,
			} )
		);
		expect( reject ).toHaveBeenCalledTimes( 1 );
		expect( reject.mock.calls[ 0 ][ 0 ] ).toBeInstanceOf( Error );
		expect( reject.mock.calls[ 0 ][ 0 ].message ).toContain( 'boom' );
		expect( resolve ).not.toHaveBeenCalled();
		expect( v.replies.has( 'op-2' ) ).toBe( false );
	} );

	test( 'a hooks_registered reply still updates the render model when also resolving a pending promise', () => {
		const v = makeView( 'hookcatalog:view' );
		const resolve = jest.fn();
		v.replies.add( 'op-3', resolve, jest.fn() );
		v.fill(
			replyMsg( {
				id: 'op-3',
				name: 'hooks_registered',
				payload: { hooks_by_category: SAMPLE },
			} )
		);
		expect( v.setStateCache.view.hooksByCategory ).toEqual( SAMPLE );
		expect( resolve ).toHaveBeenCalled();
	} );

	test( 'a reply without a matching pending entry is handled normally (no throw)', () => {
		const v = makeView( 'hookcatalog:view' );
		expect( () =>
			v.fill(
				replyMsg( {
					id: 'no-such-op',
					name: 'hooks_registered',
					payload: { hooks_by_category: {} },
				} )
			)
		).not.toThrow();
	} );

	test( 'a reply with no ID is handled normally (no pending lookup)', () => {
		const v = makeView( 'hookcatalog:view' );
		expect( () =>
			v.fill(
				replyMsg( {
					name: 'hooks_registered',
					payload: { hooks_by_category: SAMPLE },
				} )
			)
		).not.toThrow();
		expect( v.setStateCache.view.hooksByCategory ).toEqual( SAMPLE );
	} );
} );

describe( 'hookcatalog:view — malformed input', () => {
	test( 'ignores a message with no VALUE', () => {
		const v = makeView( 'hookcatalog:view' );
		const initial = v.setStateCache.view;
		const m = newMessage();
		m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
		v.fill( m );
		expect( v.setStateCache.view ).toBe( initial );
	} );

	test( 'ignores a message with a non-object VALUE', () => {
		const v = makeView( 'hookcatalog:view' );
		const initial = v.setStateCache.view;
		const m = newMessage();
		m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
		m[ VALUE ] = 'not-an-object';
		v.fill( m );
		expect( v.setStateCache.view ).toBe( initial );
	} );
} );

describe( 'hookcatalog:view — registration', () => {
	test( 'registers under the given name', () => {
		const node = makeView( 'hookcatalog:view' );
		expect( Core.node( 'hookcatalog:view' ) ).toBe( node );
	} );
} );

describe( 'hookcatalog:view — nodeSchema', () => {
	test( 'is a Hidden, terminal (no output port) node', () => {
		const schema = makeView( 'hookcatalog:view' ).constructor.nodeSchema();
		expect( schema.has_target ).toBe( false );
		expect( schema.category ).toBe( 'Hidden' );
		expect( typeof schema.description ).toBe( 'string' );
		expect( schema.description.length ).toBeGreaterThan( 0 );
		expect( schema.arguments ).toEqual( [] );
		expect( schema.commands ).toEqual( [] );
	} );
} );
