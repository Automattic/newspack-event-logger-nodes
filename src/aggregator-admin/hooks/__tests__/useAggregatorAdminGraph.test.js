/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * useAggregatorAdminGraph tests — the Configured-Servers admin graph clipped
 * onto the substrate's I/O boundary nodes (exospine + `_http` + `_output`,
 * `_uptime`, `_completion`, `_cwd`), plus the `servers:view` model node.
 * Migrated from the bespoke `servers:command` Node to the substrate's HttpOut:
 * the hook dispatches each verb as a TM_COMMAND through the CI
 * (FROM=`servers:view`, TO=`_http/servers`, verb in VALUE.name); the reply
 * routes via TO=FROM back into the view node, which unwraps `value.payload`.
 *
 * Every node sinks into the CI (rule #2); flow is steered ONLY by each node's
 * `target` (the router peels TO and delivers). _http.client is injected via
 * `opts.commandClient` so the hook never touches the network. Each CRUD
 * callback returns a Promise the view resolves by matching `message[ID]`
 * against `servers:view`'s `pending` map. Mirrors useAggregatorStatusGraph.
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import {
	newMessage,
	TIMESTAMP,
	ID,
	TO,
	FROM,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	Core,
} from '@newspack-nodes/runtime';
import { useAggregatorAdminGraph } from '../useAggregatorAdminGraph';

const CI = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const OUTPUT = '_output';
const UPTIME = '_uptime';
const COMPLETION = '_completion';
const CWD = '_cwd';
const VIEW = 'servers:view';
const ALL_GRAPH_NAMES = [ HTTP, OUTPUT, UPTIME, COMPLETION, CWD, VIEW ];

// A fake CommandClient matching HttpOut's seam: postBatch returns reply
// Messages addressed back along FROM (the server's reply pivot). The payload
// can be looked up by verb so a list reply yields a server map while a mutation
// reply yields { id }.
function makeFakeClient( payloadByVerb = {}, opts = {} ) {
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
				if ( opts.now ) {
					reply[ TIMESTAMP ] = opts.now;
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
} );

describe( 'useAggregatorAdminGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + the I/O boundary nodes + the view, each sinking into the CI', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
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
			useAggregatorAdminGraph( { commandClient: client } )
		);
		expect( Core.node( HTTP ).client ).toBe( client );
	} );

	test( 'fires one immediate list() on mount (list command via _http)', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		expect( client.batches.length ).toBeGreaterThanOrEqual( 1 );
		const msg = client.batches[ 0 ][ 0 ];
		expect( msg[ TO ] ).toBe( 'servers' );
		expect( msg[ FROM ] ).toBe( VIEW );
		expect( msg[ VALUE ].name ).toBe( 'list' );
	} );

	test( 'returns the four CRUD callbacks', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		expect( typeof result.current.addServer ).toBe( 'function' );
		expect( typeof result.current.updateServer ).toBe( 'function' );
		expect( typeof result.current.removeServer ).toBe( 'function' );
		expect( typeof result.current.testServer ).toBe( 'function' );
	} );
} );

describe( 'useAggregatorAdminGraph — end-to-end routing through the exospine', () => {
	test( 'an immediate list reply routes _http → CI → router → servers:view and lands in the view model', async () => {
		const servers = {
			'spoke-01': { id: 'spoke-01', url: 'https://a' },
			'spoke-02': { id: 'spoke-02', url: 'https://b' },
		};
		const client = makeFakeClient( { list: servers } );
		renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		// Let the immediate list's promise resolve + route through the router.
		await act( async () => {} );

		const view = Core.node( VIEW );
		expect( view.setStateCache.view.servers ).toHaveLength( 2 );
		expect( view.setStateCache.view.servers.map( ( s ) => s.id ) ).toEqual(
			[ 'spoke-01', 'spoke-02' ]
		);
		expect( view.setStateCache.view.loading ).toBe( false );
		expect( view.setStateCache.view.error ).toBeNull();
	} );
} );

describe( 'useAggregatorAdminGraph — CRUD callbacks dispatch the verb then re-list', () => {
	test( 'addServer dispatches an add command then re-lists', async () => {
		const client = makeFakeClient( {
			list: {},
			add: { id: 'spoke-01' },
		} );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		// Drain the immediate list before counting.
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		let returned;
		await act( async () => {
			returned = await result.current.addServer( {
				id: 'spoke-01',
				url: 'https://x',
				auth_username: 'u',
				auth_password: 'p',
			} );
		} );

		// Mutation resolves to the verb's payload.
		expect( returned ).toEqual( { id: 'spoke-01' } );

		// An `add` was dispatched with the fields in the payload + enabled:true.
		const add = findVerb( client.batches, 'add' );
		expect( add ).toBeTruthy();
		expect( add[ TO ] ).toBe( 'servers' );
		expect( add[ FROM ] ).toBe( VIEW );
		expect( add[ VALUE ].payload ).toMatchObject( {
			id: 'spoke-01',
			url: 'https://x',
			auth_username: 'u',
			auth_password: 'p',
			enabled: true,
		} );

		// A re-list ran after the mutation (replaces window.location.reload()).
		const listsAfter = countVerbs( client.batches, 'list' );
		expect( listsAfter ).toBeGreaterThan( listsBefore );
	} );

	test( 'updateServer dispatches an update command then re-lists', async () => {
		const client = makeFakeClient( {
			list: {},
			update: { id: 'spoke-01' },
		} );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		await act( async () => {
			await result.current.updateServer( 'spoke-01', { enabled: false } );
		} );

		const update = findVerb( client.batches, 'update' );
		expect( update ).toBeTruthy();
		expect( update[ VALUE ].payload ).toEqual( {
			id: 'spoke-01',
			enabled: false,
		} );
		expect( countVerbs( client.batches, 'list' ) ).toBeGreaterThan(
			listsBefore
		);
	} );

	test( 'removeServer dispatches a delete command then re-lists', async () => {
		const client = makeFakeClient( {
			list: {},
			delete: { id: 'spoke-01' },
		} );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		await act( async () => {
			await result.current.removeServer( 'spoke-01' );
		} );

		const del = findVerb( client.batches, 'delete' );
		expect( del ).toBeTruthy();
		expect( del[ VALUE ].payload ).toEqual( { id: 'spoke-01' } );
		expect( countVerbs( client.batches, 'list' ) ).toBeGreaterThan(
			listsBefore
		);
	} );

	test( 'testServer dispatches a test command and resolves to the probe result (no re-list)', async () => {
		const probe = { id: 'spoke-01', status: 'connected', response: {} };
		const client = makeFakeClient( {
			list: {},
			test: probe,
		} );
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {} );
		const listsBefore = countVerbs( client.batches, 'list' );

		let returned;
		await act( async () => {
			returned = await result.current.testServer( 'spoke-01' );
		} );

		const t = findVerb( client.batches, 'test' );
		expect( t ).toBeTruthy();
		expect( t[ VALUE ].payload ).toEqual( { id: 'spoke-01' } );
		expect( returned ).toEqual( probe );
		// The test verb is read-only — no re-list expected.
		expect( countVerbs( client.batches, 'list' ) ).toBe( listsBefore );
	} );
} );

describe( 'useAggregatorAdminGraph — mutation errors reject to the caller', () => {
	// Pending-matched TM_ERROR replies reject the Promise the caller is
	// awaiting; the global view.error is reserved for un-correlated failures
	// (initial list, broadcasts). The CRUD UIs catch per-call and surface
	// locally — a row-level snackbar or in-form notice — so a single failed
	// mutation doesn't repaint a table-wide red banner.
	test( 'a failed addServer rejects without polluting global view.error', async () => {
		const client = makeFakeClient(
			{ list: {}, add: 'duplicate id' },
			{ errorVerbs: [ 'add' ] }
		);
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {} );

		await act( async () => {
			await expect(
				result.current.addServer( {
					id: 'dup',
					url: 'https://x',
					auth_username: 'u',
					auth_password: 'p',
				} )
			).rejects.toThrow( 'duplicate id' );
		} );
		expect( Core.node( VIEW ).setStateCache.view.error ).toBeNull();
	} );

	test( 'a failed removeServer rejects without polluting global view.error', async () => {
		const client = makeFakeClient(
			{ list: {}, delete: 'in-use' },
			{ errorVerbs: [ 'delete' ] }
		);
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {} );

		await act( async () => {
			await expect(
				result.current.removeServer( 'spoke-01' )
			).rejects.toThrow( 'in-use' );
		} );
		expect( Core.node( VIEW ).setStateCache.view.error ).toBeNull();
	} );

	test( 'a failed testServer rejects (per-row status surface)', async () => {
		const client = makeFakeClient(
			{ list: {}, test: 'unauthorized' },
			{ errorVerbs: [ 'test' ] }
		);
		const { result } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
		);
		await act( async () => {} );

		await act( async () => {
			await expect(
				result.current.testServer( 'spoke-01' )
			).rejects.toThrow( 'unauthorized' );
		} );
	} );
} );

describe( 'useAggregatorAdminGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useAggregatorAdminGraph( { commandClient: client } )
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
			useAggregatorAdminGraph( { commandClient: client } )
		);
		unmount();
		expect( () => {
			const reply = newMessage();
			reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			reply[ VALUE ] = { name: 'list', payload: {} };
			resolveReply( [ reply ] );
		} ).not.toThrow();
		await Promise.resolve();
	} );
} );

// Helpers — iterate the recorded batches for a verb-bearing message.
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
