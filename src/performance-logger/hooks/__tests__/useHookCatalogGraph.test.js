/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * useHookCatalogGraph tests — the Performance Logger hook-catalog graph clipped
 * onto the substrate's I/O boundary node (exospine + `_http`), plus the
 * `hookcatalog:view` model node.
 *
 * Migrated from the bespoke `hookcatalog:command` Node to the substrate's
 * HttpOut: the hook dispatches the `hooks_registered` verb as a TM_COMMAND
 * through the CI (FROM=`hookcatalog:view`, TO=`_http/performance`); the reply
 * routes via TO=FROM back into the view, which extracts hooks_by_category.
 *
 * Every node sinks into the CI (rule #2); flow is steered ONLY by each node's
 * `target`. _http.client is injected via `opts.commandClient` so the hook never
 * touches the network. The trigger is fire-on-open: flipping `isOpen` true
 * dispatches one fetch (re-fetches on every re-open). Mirrors
 * useAggregatorAdminGraph (real graph, faked command boundary).
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
import { useHookCatalogGraph } from '../useHookCatalogGraph';

const CI = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const VIEW = 'hookcatalog:view';
const ALL_GRAPH_NAMES = [ HTTP, VIEW ];

// A fake CommandClient matching HttpOut's seam: postBatch returns reply
// Messages addressed back along FROM (the server's reply pivot). The payload
// can be looked up by verb so a hooks_registered reply yields the catalog dict.
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

describe( 'useHookCatalogGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + _http + the view, each sinking into the CI', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
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

	test( 'does NOT mount _output / _completion / _uptime / _cwd (dashboards are not REPLs)', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( '_http has the injected CommandClient as its client', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( Core.node( HTTP ).client ).toBe( client );
	} );

	test( 'does NOT fire any command while isOpen is false', () => {
		const client = makeFakeClient();
		renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( client.batches.length ).toBe( 0 );
	} );

	test( 'returns the default render model before any fetch', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
		);
		expect( result.current.hooksByCategory ).toEqual( {} );
		expect( result.current.loading ).toBe( true );
	} );
} );

describe( 'useHookCatalogGraph — fire on open routes through the exospine', () => {
	test( 'flipping isOpen true dispatches a hooks_registered command via _http', async () => {
		const client = makeFakeClient( {
			hooks_registered: { hooks_by_category: {} },
		} );
		const { rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		expect( client.batches.length ).toBe( 0 );
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		expect( client.batches.length ).toBeGreaterThanOrEqual( 1 );
		const msg = client.batches[ 0 ][ 0 ];
		expect( msg[ TO ] ).toBe( 'performance' );
		expect( msg[ FROM ] ).toBe( VIEW );
		expect( msg[ VALUE ].name ).toBe( 'hooks_registered' );
	} );

	test( 'the resolved catalog routes _http → CI → router → hookcatalog:view and lands in the model', async () => {
		const hooks = {
			Lifecycle: [ 'init' ],
			'REST API': [ 'rest_api_init' ],
		};
		const client = makeFakeClient( {
			hooks_registered: { hooks_by_category: hooks, total_hooks: 2 },
		} );
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		expect( result.current.hooksByCategory ).toEqual( hooks );
		expect( result.current.loading ).toBe( false );
		// Confirm it actually landed in the view node's published model.
		expect( Core.node( VIEW ).setStateCache.view.hooksByCategory ).toEqual(
			hooks
		);
	} );

	test( 'loading is true between dispatch and resolve', async () => {
		// A client whose postBatch never resolves: loading stays true.
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
				return new Promise( () => {} );
			},
		};
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		expect( result.current.loading ).toBe( true );
	} );

	test( 're-opening fires a fresh fetch on every isOpen flip', async () => {
		const client = makeFakeClient( {
			hooks_registered: { hooks_by_category: {} },
		} );
		const { rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		const afterFirst = client.batches.length;
		await act( async () => {
			rerender( { isOpen: false, commandClient: client } );
		} );
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		expect( client.batches.length ).toBeGreaterThan( afterFirst );
	} );
} );

describe( 'useHookCatalogGraph — fetch errors fall back to an empty map (mirrors old modal)', () => {
	// The legacy modal's `.catch(() => setHookCategories({}))` model: a fetch
	// failure leaves the spinner cleared and the category list empty. There's
	// no error UI in HookSelectorModal — just an empty modal. So a TM_ERROR
	// reply must not throw out of the hook and must clear loading.
	test( 'an error reply clears loading without throwing', async () => {
		const client = makeFakeClient(
			{ hooks_registered: 'capability check failed' },
			{ errorVerbs: [ 'hooks_registered' ] }
		);
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		expect( result.current.loading ).toBe( false );
		// Caller's catch handled the rejection — no global error to surface.
		// hooksByCategory stays at its default empty map.
		expect( result.current.hooksByCategory ).toEqual( {} );
	} );
} );

describe( 'useHookCatalogGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false, commandClient: client } )
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
		const { unmount, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false, commandClient: client } }
		);
		await act( async () => {
			rerender( { isOpen: true, commandClient: client } );
		} );
		unmount();
		expect( () => {
			const reply = newMessage();
			reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			reply[ VALUE ] = {
				name: 'hooks_registered',
				payload: { hooks_by_category: {} },
			};
			resolveReply( [ reply ] );
		} ).not.toThrow();
		await Promise.resolve();
	} );
} );
