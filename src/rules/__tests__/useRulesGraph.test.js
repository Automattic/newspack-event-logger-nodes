/**
 * useRulesGraph tests — the per-URL logging-ruleset editor graph, clipped onto
 * the substrate's I/O boundary node (exospine + `_http`) with ONE receiver Tee
 * (`rules:in`) in front of the `rules:view` model node. Modeled on
 * useVaultGraph: each verb dispatches a TM_COMMAND through the interpreter
 * (FROM = `rules:in`, TO = `_http/rules`, verb in VALUE.name); the reply routes
 * TO=FROM back into the Tee, which fans it to the view. `_http.client` is
 * injected via `opts.commandClient` so the hook never touches the network.
 *
 * The wire contract mirrors Rules_CI_Node: `save`/`upsert` take the RAW JSON as
 * the command arguments string (the handler json_decodes the whole arg);
 * `delete` takes the id as a positional token; `list` takes no args.
 */

import { renderHook, act } from '../../test-helpers/renderHook';
import {
	newMessage,
	ID,
	TO,
	FROM,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	Core,
	formatCommandArgs,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { useRulesGraph } from '../useRulesGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const RECV = 'rules:in';
const VIEW = 'rules:view';
const ALL_GRAPH_NAMES = [ HTTP, RECV, VIEW ];

const SAMPLE_RULES = [
	{
		id: 'r1',
		pattern: '/blog',
		action: 'log',
		auto_disable_threshold: 0,
		auto_protect_time_threshold: 0,
		significant_events: [],
		custom_events: [],
		hooks: [ 'init' ],
		hooks_in: 'inline',
	},
	{
		id: 'r2',
		pattern: '/admin',
		action: 'skip',
		auto_disable_threshold: 0,
		auto_protect_time_threshold: 0,
		significant_events: [],
		custom_events: [],
		hooks: [],
		hooks_in: 'inline',
	},
];

// A fake CommandClient (HttpOutNode seam): postBatch echoes TO=FROM replies.
function makeFakeClient( payloadByVerb = {}, opts = {} ) {
	const client = {
		batches: [],
		buildMessage( { to, verb, args = '' } ) {
			const m = newMessage();
			m[ TYPE ] = TM_COMMAND;
			m[ TO ] = to;
			m[ VALUE ] = { name: verb, arguments: args };
			return m;
		},
		postBatch( messages ) {
			client.batches.push( messages );
			const replies = messages.map( ( m ) => {
				const reply = newMessage();
				reply[ TYPE ] =
					opts.errorVerbs &&
					opts.errorVerbs.includes( m[ VALUE ]?.name )
						? TM_COMMAND | TM_RESPONSE | TM_ERROR
						: TM_COMMAND | TM_RESPONSE;
				reply[ TO ] = m[ FROM ];
				reply[ ID ] = m[ ID ];
				reply[ VALUE ] = {
					name: m[ VALUE ]?.name,
					payload:
						payloadByVerb[ m[ VALUE ]?.name ] ??
						payloadByVerb._default ??
						null,
				};
				return reply;
			} );
			return Promise.resolve( replies );
		},
	};
	return client;
}

function findVerb( batches, verb ) {
	for ( const batch of batches ) {
		for ( const m of batch ) {
			if ( m[ VALUE ]?.name === verb ) {
				return m;
			}
		}
	}
	return null;
}

function countVerbs( batches, verb ) {
	let count = 0;
	for ( const batch of batches ) {
		for ( const m of batch ) {
			if ( m[ VALUE ]?.name === verb ) {
				count += 1;
			}
		}
	}
	return count;
}

beforeEach( () => {
	Core.reset();
} );

describe( 'useRulesGraph — exospine + receiver wiring', () => {
	test( 'mounts the backbone + _http + the receiver Tee + the view, each sinking into the interpreter', async () => {
		const client = makeFakeClient();
		renderHook( () => useRulesGraph( { commandClient: client } ) );
		await act( async () => {} );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		for ( const name of ALL_GRAPH_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
	} );

	test( 'the receiver Tee fans to exactly the view', async () => {
		const client = makeFakeClient();
		renderHook( () => useRulesGraph( { commandClient: client } ) );
		await act( async () => {} );
		expect( Core.node( RECV ).target ).toEqual( [ VIEW ] );
	} );

	test( 'does NOT mount the REPL-only nodes', async () => {
		const client = makeFakeClient();
		renderHook( () => useRulesGraph( { commandClient: client } ) );
		await act( async () => {} );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( '_http has the injected CommandClient as its client', async () => {
		const client = makeFakeClient();
		renderHook( () => useRulesGraph( { commandClient: client } ) );
		await act( async () => {} );
		expect( Core.node( HTTP ).client ).toBe( client );
	} );

	test( 'fires one immediate list() on mount, FROM the receiver, TO _http/rules', async () => {
		const client = makeFakeClient();
		renderHook( () => useRulesGraph( { commandClient: client } ) );
		await act( async () => {} );
		expect( client.batches.length ).toBeGreaterThanOrEqual( 1 );
		const msg = client.batches[ 0 ][ 0 ];
		expect( msg[ TO ] ).toBe( 'rules' );
		expect( msg[ FROM ] ).toBe( RECV );
		expect( msg[ VALUE ].name ).toBe( 'list' );
	} );

	/**
	 * Mount races /auth: the graph is built synchronously, the session arrives a
	 * round trip later. Firing the list before then mints it UNSIGNED and the
	 * server refuses it — the page then looked "half-working", alive only because
	 * a later user-triggered refresh happened to run after auth landed.
	 */
	test( 'holds the mount-time list until the session is established', async () => {
		forgetSession();
		__setAuthFetch( async () => ( {
			handle: 'cccc3333cccc3333cccc3333cccc3333',
			key: 'key-late-auth',
			expires_in: 3600,
			now: 1771000000,
		} ) );
		const client = makeFakeClient();

		renderHook( () => useRulesGraph( { commandClient: client } ) );
		await act( async () => {} );

		expect( client.batches.length ).toBeGreaterThanOrEqual( 1 );
		expect( client.batches[ 0 ][ 0 ][ VALUE ].auth ).toBeDefined();
	} );

	/** A click during the /auth round trip must still mint a signed command. */
	test( 'signs a dispatch fired while the session is still in flight', async () => {
		forgetSession();
		let landAuth;
		const inFlight = new Promise( ( resolve ) => {
			landAuth = resolve;
		} );
		__setAuthFetch( () =>
			inFlight.then( () => ( {
				handle: 'aaaa9999aaaa9999aaaa9999aaaa9999',
				key: 'key-rules-in-flight',
				expires_in: 3600,
				now: 1771000000,
			} ) )
		);
		const client = makeFakeClient( { list: { rules: [] } } );
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );

		await act( async () => {
			const pending = result.current.list();
			landAuth();
			await pending;
		} );

		const listed = client.batches
			.flat()
			.filter( ( m ) => 'list' === m[ VALUE ]?.name );
		expect( listed.length ).toBeGreaterThanOrEqual( 1 );
		expect( listed[ 0 ][ VALUE ].auth ).toBeDefined();
	} );

	test( 'exposes the model + CRUD callbacks', async () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );
		expect( Array.isArray( result.current.rules ) ).toBe( true );
		expect( typeof result.current.list ).toBe( 'function' );
		expect( typeof result.current.saveAll ).toBe( 'function' );
		expect( typeof result.current.upsert ).toBe( 'function' );
		expect( typeof result.current.remove ).toBe( 'function' );
	} );
} );

describe( 'useRulesGraph — list populates rules', () => {
	test( 'an immediate list reply lands in the view model and the hook return', async () => {
		const client = makeFakeClient( { list: { rules: SAMPLE_RULES } } );
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );

		const view = Core.node( VIEW );
		expect( view.setStateCache.view.rules ).toHaveLength( 2 );
		expect( view.setStateCache.view.loading ).toBe( false );
		expect( view.setStateCache.view.error ).toBeNull();
		expect( result.current.rules.map( ( r ) => r.id ) ).toEqual( [
			'r1',
			'r2',
		] );
		expect( result.current.loading ).toBe( false );
	} );
} );

describe( 'useRulesGraph — mutations dispatch the verb then re-list', () => {
	test( 'upsert sends the raw-JSON rule as arguments then re-lists', async () => {
		const client = makeFakeClient( {
			list: { rules: [] },
			upsert: { rule: SAMPLE_RULES[ 0 ] },
		} );
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		let returned;
		await act( async () => {
			returned = await result.current.upsert( SAMPLE_RULES[ 0 ] );
		} );
		expect( returned ).toEqual( { rule: SAMPLE_RULES[ 0 ] } );

		const up = findVerb( client.batches, 'upsert' );
		expect( up ).toBeTruthy();
		expect( up[ TO ] ).toBe( 'rules' );
		expect( up[ FROM ] ).toBe( RECV );
		// The whole raw JSON is one arg token the CI json_decodes ($args[0]).
		expect( up[ VALUE ].arguments ).toEqual( [
			JSON.stringify( SAMPLE_RULES[ 0 ] ),
		] );
		expect( JSON.parse( up[ VALUE ].arguments[ 0 ] ).pattern ).toBe(
			'/blog'
		);

		expect( countVerbs( client.batches, 'list' ) ).toBeGreaterThan(
			listsBefore
		);
	} );

	test( 'saveAll sends the whole-list raw JSON then re-lists', async () => {
		const client = makeFakeClient( {
			list: { rules: SAMPLE_RULES },
			save: { saved: 2 },
		} );
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		await act( async () => {
			await result.current.saveAll( SAMPLE_RULES );
		} );

		const save = findVerb( client.batches, 'save' );
		expect( save ).toBeTruthy();
		expect( save[ VALUE ].arguments ).toEqual( [
			JSON.stringify( SAMPLE_RULES ),
		] );
		expect( countVerbs( client.batches, 'list' ) ).toBeGreaterThan(
			listsBefore
		);
	} );

	test( 'remove sends delete with the id as a positional token then re-lists', async () => {
		const client = makeFakeClient( {
			list: { rules: [] },
			delete: { deleted: true },
		} );
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		await act( async () => {
			await result.current.remove( 'r1' );
		} );

		const del = findVerb( client.batches, 'delete' );
		expect( del ).toBeTruthy();
		expect( del[ FROM ] ).toBe( RECV );
		expect( del[ VALUE ].arguments ).toEqual(
			formatCommandArgs( [ 'r1' ] )
		);
		expect( countVerbs( client.batches, 'list' ) ).toBeGreaterThan(
			listsBefore
		);
	} );
} );

describe( 'useRulesGraph — errors', () => {
	test( 'an uncorrelated list error surfaces as view.error without throwing', async () => {
		const client = makeFakeClient(
			{ list: 'ruleset unavailable' },
			{ errorVerbs: [ 'list' ] }
		);
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );
		expect( result.current.error ).toBe( 'ruleset unavailable' );
		expect( result.current.loading ).toBe( false );
	} );

	test( 'a failed upsert rejects to the caller without polluting the table banner', async () => {
		const client = makeFakeClient(
			{ list: { rules: [] }, upsert: 'invalid rule' },
			{ errorVerbs: [ 'upsert' ] }
		);
		const { result } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		await act( async () => {} );

		await act( async () => {
			await expect(
				result.current.upsert( SAMPLE_RULES[ 0 ] )
			).rejects.toThrow( 'invalid rule' );
		} );
		expect( Core.node( VIEW ).setStateCache.view.error ).toBeNull();
	} );
} );

describe( 'useRulesGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useRulesGraph( { commandClient: client } )
		);
		unmount();
		for ( const name of [ ...ALL_GRAPH_NAMES, INTERPRETER, ROUTER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );
} );
