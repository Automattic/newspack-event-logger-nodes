/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * usePerformanceGraph tests — the Performance Dashboard graph clipped onto the
 * substrate's I/O boundary node (exospine + `_http`), plus the application's
 * `performance:command` (the slice-tagging command-builder) and
 * `performance:view` (the render model + pending-Promise registry).
 *
 * Post-migration: `performance:command` no longer owns the network. Each
 * fetch* dispatches a TM_COMMAND through the CI (FROM=`performance:view`,
 * TO=`_http/performance`, verb in VALUE.name) and stashes a slice-tagged
 * pending entry on `performance:view`; HttpOut POSTs; the server pivots the
 * reply TO=FROM, the router peels `performance:view`, and the view's `fill()`
 * matches `message[ID]` against `pending` and applies the result to the
 * registered slice (or resolves a resolveOnly Promise).
 *
 * Every node sinks into the CI (rule #2); flow is steered ONLY by each node's
 * `target` (the router peels TO and delivers). `_http.client` is injected via
 * `opts.commandClient` so the hook never touches the network.
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import {
	Core,
	newMessage,
	TYPE,
	ID,
	TO,
	FROM,
	VALUE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
} from '@newspack-nodes/runtime';
import { usePerformanceGraph } from '../usePerformanceGraph';

const CI = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const COMMAND = 'performance:command';
const VIEW = 'performance:view';
const ALL_GRAPH_NAMES = [ HTTP, COMMAND, VIEW ];

// A fake CommandClient matching HttpOut's seam: postBatch returns reply
// Messages addressed back along FROM (the server's reply pivot). The payload
// can be looked up by verb so url_detail / overview / urls / request_detail /
// request_search each yield the right canned shape.
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
				return reply;
			} );
			return Promise.resolve( replies );
		},
	};
	return client;
}

beforeEach( () => Core.reset() );

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

describe( 'usePerformanceGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + _http + the command + view, each sinking into the CI', () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
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
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( '_http has the injected CommandClient as its client', () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		expect( Core.node( HTTP ).client ).toBe( client );
	} );

	test( 'performance:command targets the view (router routes loading → view)', () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		expect( Core.node( COMMAND ).target ).toBe( VIEW );
	} );

	test( 'fires the initial overview + urls TM_COMMANDs via _http on mount', async () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		await act( async () => {} );
		const overview = findVerb( client.batches, 'overview' );
		expect( overview ).toBeTruthy();
		expect( overview[ TO ] ).toBe( 'performance' );
		expect( overview[ FROM ] ).toBe( VIEW );
		const urls = findVerb( client.batches, 'urls' );
		expect( urls ).toBeTruthy();
		expect( urls[ FROM ] ).toBe( VIEW );
	} );

	test( 'returns the three control callbacks', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			usePerformanceGraph( { commandClient: client } )
		);
		expect( typeof result.current.handleUrlParamsChange ).toBe(
			'function'
		);
		expect( typeof result.current.resolveRequest ).toBe( 'function' );
		expect( typeof result.current.fetchUrlBreakdown ).toBe( 'function' );
	} );
} );

describe( 'usePerformanceGraph — end-to-end routing through the exospine', () => {
	test( 'an overview reply lands in the view.overview slice via the router', async () => {
		const client = makeFakeClient( { overview: { total_requests: 7 } } );
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		await act( async () => {} );
		const view = Core.node( VIEW );
		expect( view.setStateCache.view.overview.data ).toEqual( {
			total_requests: 7,
		} );
	} );

	test( 'a urls reply lands in the view.urls slice (data + total)', async () => {
		const client = makeFakeClient( {
			urls: {
				data: [ { hash: 'a' } ],
				total: 12,
				limit: 100,
				offset: 0,
			},
		} );
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		await act( async () => {} );
		const view = Core.node( VIEW );
		expect( view.setStateCache.view.urls ).toEqual( {
			data: [ { hash: 'a' } ],
			total: 12,
			loading: false,
			error: null,
		} );
	} );
} );

describe( 'usePerformanceGraph — selection-driven fetches', () => {
	test( 're-fetches when serverFilter changes', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		const before = client.batches.flat().length;
		await act( async () => {
			rerender( { commandClient: client, serverFilter: 'web1' } );
		} );
		const after = client.batches.flat().length;
		expect( after ).toBeGreaterThan( before );
		const overview = client.batches
			.flat()
			.find(
				( m ) =>
					m[ VALUE ]?.name === 'overview' &&
					m[ VALUE ]?.payload?.server === 'web1'
			);
		expect( overview ).toBeTruthy();
	} );

	test( 'fires fetchUrlDetail with the selected hash when a URL is selected', async () => {
		const client = makeFakeClient( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedUrl: { hash: 'abc' },
			} );
		} );
		const detail = findVerb( client.batches, 'url_detail' );
		expect( detail ).toBeTruthy();
		expect( detail[ VALUE ].payload.hash ).toBe( 'abc' );
	} );

	test( 'fires fetchRequestDetail using the provided partition', async () => {
		const client = makeFakeClient( {
			request_detail: { rid: 'r1' },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedRequest: 'r1',
				requestPartition: 2,
			} );
		} );
		const req = findVerb( client.batches, 'request_detail' );
		expect( req ).toBeTruthy();
		expect( req[ VALUE ].payload.partition ).toBe( 2 );
	} );
} );

describe( 'usePerformanceGraph — handleUrlParamsChange', () => {
	test( 'debounces a search change (300ms)', async () => {
		jest.useFakeTimers();
		const client = makeFakeClient( {
			urls: { data: [], total: 0 },
		} );
		let api;
		const { unmount } = renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client } }
		);
		await act( async () => {} );
		const before = countVerbs( client.batches, 'urls' );
		api.handleUrlParamsChange( {
			search: 'x',
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		expect( countVerbs( client.batches, 'urls' ) ).toBe( before );
		jest.advanceTimersByTime( 300 );
		expect( countVerbs( client.batches, 'urls' ) ).toBe( before + 1 );
		unmount();
		jest.useRealTimers();
	} );
} );

describe( 'usePerformanceGraph — resolveRequest & fetchUrlBreakdown', () => {
	test( 'resolveRequest returns the unwrapped reply payload', async () => {
		const client = makeFakeClient( {
			request_search: { url_hash: 'h', partition: 1 },
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client } }
		);
		await act( async () => {} );
		let resolved;
		await act( async () => {
			resolved = await api.resolveRequest( 'r' );
		} );
		expect( resolved ).toEqual( { url_hash: 'h', partition: 1 } );
	} );

	test( 'resolveRequest falls back to the shared client when the node is not mounted (deep-link timing)', async () => {
		// useUrlNavigation fires the `?request=`-only resolver from its mount
		// effect, which runs BEFORE this hook's mount effect populates
		// commandRef. Dropping the mounted node (unmount → commandRef.current
		// null) stands in for that pre-mount state: resolveRequest must still
		// resolve via the shared client directly. request_search is a stateless
		// lookup with no view-model side effects.
		// Support both HttpOut's postBatch (for mount-time overview/urls) AND
		// the .send fallback path resolveRequest uses pre-mount.
		const fallbackClient = {
			calls: [],
			buildMessage: ( { to, verb, args = '', payload = null } ) => {
				const m = newMessage();
				m[ TYPE ] = TM_COMMAND;
				m[ TO ] = to;
				m[ VALUE ] = { name: verb, arguments: args, payload };
				return m;
			},
			postBatch: () => Promise.resolve( [] ),
			send( a ) {
				this.calls.push( a );
				const m = newMessage();
				m[ VALUE ] = { payload: { url_hash: 'h', partition: 0 } };
				return Promise.resolve( m );
			},
		};
		let api;
		const { unmount } = renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: fallbackClient } }
		);
		await act( async () => {} );
		unmount();
		fallbackClient.calls.length = 0;
		const out = await api.resolveRequest( 'rid-7' );
		expect( out ).toEqual( { url_hash: 'h', partition: 0 } );
		expect(
			fallbackClient.calls.some( ( c ) => c.verb === 'request_search' )
		).toBe( true );
	} );

	test( 'fetchUrlBreakdown returns breakdown_time_series via the transform pending', async () => {
		const client = makeFakeClient( {
			url_detail: { breakdown_time_series: { a: 1 } },
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client } }
		);
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await api.fetchUrlBreakdown( 'abc123', 'method' );
		} );
		expect( result ).toEqual( { a: 1 } );
	} );
} );

describe( 'usePerformanceGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			usePerformanceGraph( { commandClient: client } )
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
			usePerformanceGraph( { commandClient: client } )
		);
		unmount();
		expect( () => {
			const r = newMessage();
			r[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			r[ VALUE ] = { name: 'overview', payload: {} };
			resolveReply( [ r ] );
		} ).not.toThrow();
		await Promise.resolve();
	} );
} );
