/**
 * hookCatalogCommand tests — the `hookcatalog:command` transport node. It owns the
 * single `performance.hooks_registered` fetch behind an injectable command-client
 * seam; `fetch()` emits a synchronous `loading` control then a `catalog` control
 * with the unwrapped `hooks_by_category` map, each stamped TO=target so the exospine
 * router delivers them to `hookcatalog:view` (rule #2). A rejected send falls back
 * to an empty catalog (NOT an error state — matches the old modal's `.catch(() => {})`).
 * After `close()` a late-resolving send emits nothing. Mirrors servers:command's
 * tests (fake sink, faked command boundary).
 */

import {
	Core,
	TYPE,
	TO,
	VALUE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { createHookCatalogCommand } from '../hookCatalogCommand';

// A sink that records every message it's filled with (action lives at VALUE).
function makeSink() {
	return {
		fills: [],
		actions: [],
		fill( message ) {
			this.fills.push( message );
			this.actions.push( message[ VALUE ]?.action );
		},
	};
}

// Build a real Message-shaped reply so the production unwrapCommandResponse runs
// (only the network boundary is faked — VALUE holds the { payload } envelope).
function replyWith( payload ) {
	const m = newMessage();
	m[ VALUE ] = { payload };
	return m;
}

// A fake command client matching the node's seam: send resolves a canned reply.
function makeFakeClient( reply ) {
	return {
		calls: [],
		send( args ) {
			this.calls.push( args );
			return Promise.resolve( reply );
		},
	};
}

beforeEach( () => {
	Core.reset();
} );

describe( 'createHookCatalogCommand', () => {
	test( 'emits loading synchronously, then catalog with hooks_by_category mapped to hooksByCategory', async () => {
		const hooks = {
			Lifecycle: [ 'init' ],
			'REST API': [ 'rest_api_init' ],
		};
		const client = makeFakeClient(
			replyWith( { hooks_by_category: hooks } )
		);
		const node = createHookCatalogCommand( 'hookcatalog:command', {
			commandClient: client,
		} );
		const sink = makeSink();
		node.sink = sink;

		node.fetch();
		// Loading is emitted before any await resolves.
		expect( sink.actions ).toEqual( [ 'loading' ] );

		await Promise.resolve();
		await Promise.resolve();

		expect( client.calls[ 0 ] ).toEqual( {
			to: 'performance',
			verb: 'hooks_registered',
		} );
		expect( sink.actions ).toEqual( [ 'loading', 'catalog' ] );
		expect( sink.fills[ 1 ][ VALUE ].hooksByCategory ).toEqual( hooks );
	} );

	test( 'stamps each emitted message TO with its target (rule #2 routing)', async () => {
		const client = makeFakeClient( replyWith( { hooks_by_category: {} } ) );
		const node = createHookCatalogCommand( 'hookcatalog:command', {
			commandClient: client,
		} );
		node.target = 'hookcatalog:view';
		const sink = makeSink();
		node.sink = sink;

		node.fetch();
		await Promise.resolve();
		await Promise.resolve();

		// Both the synchronous loading and the resolved catalog carry TO=target.
		expect( sink.fills ).toHaveLength( 2 );
		expect( sink.fills[ 0 ][ TO ] ).toBe( 'hookcatalog:view' );
		expect( sink.fills[ 1 ][ TO ] ).toBe( 'hookcatalog:view' );
	} );

	test( 'reject falls back to a catalog with an empty map (still clears loading)', async () => {
		const client = {
			send: () => Promise.reject( new Error( 'boom' ) ),
		};
		const node = createHookCatalogCommand( 'hookcatalog:command', {
			commandClient: client,
		} );
		const sink = makeSink();
		node.sink = sink;

		node.fetch();
		await Promise.resolve();
		await Promise.resolve();

		expect( sink.actions ).toEqual( [ 'loading', 'catalog' ] );
		expect( sink.fills[ 1 ][ VALUE ].hooksByCategory ).toEqual( {} );
	} );

	test( 'emits TM_STRUCT controls', async () => {
		const client = makeFakeClient( replyWith( { hooks_by_category: {} } ) );
		const node = createHookCatalogCommand( 'hookcatalog:command', {
			commandClient: client,
		} );
		const sink = makeSink();
		node.sink = sink;

		node.fetch();
		await Promise.resolve();
		await Promise.resolve();

		expect( sink.fills[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( sink.fills[ 1 ][ TYPE ] ).toBe( TM_STRUCT );
	} );

	test( 'a send resolving after close() emits nothing', async () => {
		let resolveSend;
		const client = {
			send: () =>
				new Promise( ( resolve ) => {
					resolveSend = resolve;
				} ),
		};
		const node = createHookCatalogCommand( 'hookcatalog:command', {
			commandClient: client,
		} );
		const sink = makeSink();
		node.sink = sink;

		node.fetch();
		// Loading already emitted synchronously; close before the send resolves.
		node.close();
		resolveSend(
			replyWith( { hooks_by_category: { Lifecycle: [ 'init' ] } } )
		);
		await Promise.resolve();
		await Promise.resolve();

		// Only the synchronous loading made it through; the post-close catalog is swallowed.
		expect( sink.actions ).toEqual( [ 'loading' ] );
	} );

	test( 'defaults to the shared command client when no seam is injected', async () => {
		const shared = require( '../../../shared/utils/commandClient' );
		const send = jest.spyOn( shared, 'getCommandClient' ).mockReturnValue( {
			send: jest.fn( () =>
				Promise.resolve( replyWith( { hooks_by_category: {} } ) )
			),
		} );
		const node = createHookCatalogCommand( 'hookcatalog:command' );
		const sink = makeSink();
		node.sink = sink;

		node.fetch();
		await Promise.resolve();
		await Promise.resolve();

		expect( shared.getCommandClient ).toHaveBeenCalled();
		send.mockRestore();
	} );

	test( 'registers under the given name', () => {
		const node = createHookCatalogCommand( 'hookcatalog:command', {
			commandClient: makeFakeClient( {} ),
		} );
		expect( Core.node( 'hookcatalog:command' ) ).toBe( node );
	} );
} );
