/**
 * usePerformanceGraph tests — the Performance Dashboard data graph rebuilt on the
 * substrate batched-poll toolkit (useBatchedPoll + addSliceFetcher), D1b.
 *
 * The graph:
 *   perf:timer (Timer) → perf:tee (Tee) → fetch-overview, fetch-urls (Fetchers,
 *     each with an argsFn getter reading current React UI state) → _shell/_http/performance
 *   overviewIn (Tee) → overview:view (OverviewView)
 *   urlsIn     (Tee) → urls:view     (UrlsView)
 *   urldetail:merge (UrlDetailMerge) → urldetail:view (UrlDetailView)   [on-demand]
 *   requestdetail:view (RequestDetailView)                             [on-demand]
 *
 * overview + urls are POLLED (on the Timer, live args via the getters); url_detail
 * and request_detail are ON-DEMAND (modal-open → fetch); resolveRequest /
 * fetchUrlBreakdown are awaited Promises settled via the relevant view's
 * PendingReplies. The hook returns ONLY control callbacks; React reads each slice
 * via its own useNodeState.
 */

import { renderHook, act } from '../../../test-helpers/renderHook';
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
	parseCommandArgs,
} from '@newspack-nodes/runtime';
import { usePerformanceGraph } from '../usePerformanceGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';

// A fake CommandClient matching HttpOutNode's seam: postBatch returns reply
// Messages addressed back along FROM (the server's reply pivot), payload looked
// up by verb.
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

describe( 'usePerformanceGraph — toolkit wiring', () => {
	test( 'mounts the backbone + _http + the slice views, each sinking into the interpreter', () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		expect( Core.node( HTTP ) ).toBeTruthy();
		expect( Core.node( HTTP ).client ).toBe( client );
		for ( const name of [
			'overview:view',
			'urls:view',
			'urldetail:view',
			'requestdetail:view',
		] ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// The urlDetail merge transform sits on the receiver→view edge.
		expect( Core.node( 'urldetail:merge' ) ).toBeTruthy();
	} );

	test( 'does NOT mount _output / _completion / _uptime / _cwd (dashboards are not REPLs)', () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
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

describe( 'usePerformanceGraph — poll slices fire live args', () => {
	test( 'fires overview + urls on the first poll, TO=performance via the egress', async () => {
		const client = makeFakeClient();
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		await act( async () => {} );
		const overview = findVerb( client.batches, 'overview' );
		expect( overview ).toBeTruthy();
		expect( overview[ TO ] ).toBe( 'performance' );
		const urls = findVerb( client.batches, 'urls' );
		expect( urls ).toBeTruthy();
	} );

	test( 'the overview Fetcher emits the CURRENT serverFilter as a live arg', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { commandClient: client, serverFilter: 'web1' } );
		} );
		const overview = client.batches
			.flat()
			.find(
				( m ) =>
					m[ VALUE ]?.name === 'overview' &&
					parseCommandArgs( m[ VALUE ]?.arguments ).options.server ===
						'web1'
			);
		expect( overview ).toBeTruthy();
	} );

	test( 'an overview reply lands in the overview:view slice', async () => {
		const client = makeFakeClient( { overview: { total_requests: 7 } } );
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		await act( async () => {} );
		const view = Core.node( 'overview:view' );
		expect( view.setStateCache.view.data ).toEqual( { total_requests: 7 } );
	} );

	test( 'a urls reply lands in the urls:view slice (data + total)', async () => {
		const client = makeFakeClient( {
			urls: { data: [ { hash: 'a' } ], total: 12, limit: 100, offset: 0 },
		} );
		renderHook( () => usePerformanceGraph( { commandClient: client } ) );
		await act( async () => {} );
		const view = Core.node( 'urls:view' );
		expect( view.setStateCache.view ).toEqual( {
			data: [ { hash: 'a' } ],
			total: 12,
			loading: false,
			error: null,
		} );
	} );

	test( 'a serverFilter change fires an immediate poke (not just next tick)', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		const before = countVerbs( client.batches, 'overview' );
		await act( async () => {
			rerender( { commandClient: client, serverFilter: 'web2' } );
		} );
		expect( countVerbs( client.batches, 'overview' ) ).toBeGreaterThan(
			before
		);
	} );
} );

describe( 'usePerformanceGraph — refresh interval wiring', () => {
	test( 'arms the poll Timer at the selected refreshInterval (hitchhike + throttle)', () => {
		const client = makeFakeClient();
		renderHook( () =>
			usePerformanceGraph( {
				commandClient: client,
				refreshInterval: '30000',
			} )
		);
		const timer = Core.node( 'perf:timer' );
		expect( timer.mode ).toBe( 'router' );
		expect( timer.interval_ms ).toBe( 30000 );
	} );

	test( 'changing refreshInterval re-arms the poll Timer to the new cadence', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client, refreshInterval: '5000' },
		} );
		await act( async () => {} );
		expect( Core.node( 'perf:timer' ).interval_ms ).toBe( 5000 );

		await act( async () => {
			rerender( { commandClient: client, refreshInterval: '60000' } );
		} );
		expect( Core.node( 'perf:timer' ).interval_ms ).toBe( 60000 );
	} );
} );

describe( 'usePerformanceGraph — on-demand url_detail / request_detail', () => {
	test( 'selecting a URL fires url_detail with the hash, routes the reply to urldetail:view', async () => {
		const client = makeFakeClient( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { commandClient: client, selectedUrl: { hash: 'abc' } } );
		} );
		const detail = findVerb( client.batches, 'url_detail' );
		expect( detail ).toBeTruthy();
		expect(
			parseCommandArgs( detail[ VALUE ].arguments ).positional[ 0 ]
		).toBe( 'abc' );
		const view = Core.node( 'urldetail:view' );
		expect( view.setStateCache.view.data ).toEqual( {
			last_modified: 1,
			requests: [],
		} );
	} );

	test( 'selecting a request fires request_detail with the partition', async () => {
		const client = makeFakeClient( { request_detail: { rid: 'r1' } } );
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
		expect(
			parseCommandArgs( req[ VALUE ].arguments ).options.partition
		).toBe( '2' );
	} );
} );

describe( 'usePerformanceGraph — handleUrlParamsChange', () => {
	test( 'debounces a search change (300ms)', async () => {
		jest.useFakeTimers();
		const client = makeFakeClient( { urls: { data: [], total: 0 } } );
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

describe( 'usePerformanceGraph — resolveRequest & fetchUrlBreakdown (awaited)', () => {
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

	test( 'fetchUrlBreakdown returns breakdown_time_series via the transform', async () => {
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

	test( 'fetchUrlBreakdown returns null on invalid hash without sending a breakdown command', async () => {
		const client = makeFakeClient();
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client } }
		);
		await act( async () => {} );
		const before = client.batches.flat().length;
		let result;
		await act( async () => {
			result = await api.fetchUrlBreakdown( 'NO', 'method' );
		} );
		expect( result ).toBeNull();
		const breakdowns = client.batches
			.flat()
			.filter(
				( m ) =>
					m[ VALUE ]?.name === 'url_detail' &&
					parseCommandArgs( m[ VALUE ]?.arguments ).options.breakdown
			);
		expect( breakdowns ).toHaveLength( 0 );
		expect( client.batches.flat().length ).toBe( before );
	} );
} );

describe( 'usePerformanceGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		const client = makeFakeClient();
		const { unmount } = renderHook( () =>
			usePerformanceGraph( { commandClient: client } )
		);
		unmount();
		for ( const name of [
			HTTP,
			'overview:view',
			'urls:view',
			'urldetail:view',
			'requestdetail:view',
			INTERPRETER,
			ROUTER,
		] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );
} );
