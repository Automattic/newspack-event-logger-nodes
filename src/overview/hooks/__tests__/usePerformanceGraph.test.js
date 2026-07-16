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

// Fake CommandClient: postBatch returns TO=FROM replies keyed by verb.
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
		// One-shot send seam for resolveRequest's no-graph fallback path.
		send: jest.fn( ( { verb } ) => {
			const reply = newMessage();
			reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			reply[ VALUE ] = {
				name: verb,
				payload:
					payloadByVerb[ verb ] ?? payloadByVerb._default ?? null,
			};
			return Promise.resolve( reply );
		} ),
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

// Drive document.visibilityState (matches the visibility tests).
function setVisibility( state ) {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => state,
	} );
	document.dispatchEvent( new Event( 'visibilitychange' ) );
}

// Restore default visibility without dispatching into a mounted hook.
afterEach( () => {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => 'visible',
	} );
} );

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

describe( 'usePerformanceGraph — rules commands (_http/rules)', () => {
	test( 'exposes listRules + upsertRule callbacks', () => {
		const client = makeFakeClient();
		const { result } = renderHook( () =>
			usePerformanceGraph( { commandClient: client } )
		);
		expect( typeof result.current.listRules ).toBe( 'function' );
		expect( typeof result.current.upsertRule ).toBe( 'function' );
	} );

	test( 'listRules sends the list verb to the rules CI and resolves { rules }', async () => {
		const rules = [ { id: 'a', pattern: '/x?', action: 'log' } ];
		const client = makeFakeClient( { list: { rules } } );
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
			result = await api.listRules();
		} );
		const list = findVerb( client.batches, 'list' );
		expect( list ).toBeTruthy();
		expect( list[ TO ] ).toBe( 'rules' );
		expect( result ).toEqual( { rules } );
	} );

	test( 'upsertRule sends the upsert verb with the RAW JSON rule as arguments and resolves { rule }', async () => {
		const saved = { id: 'abc', pattern: '/blog?', action: 'log' };
		const client = makeFakeClient( { upsert: { rule: saved } } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client } }
		);
		await act( async () => {} );
		const input = { id: '', pattern: '/blog?', action: 'log' };
		let result;
		await act( async () => {
			result = await api.upsertRule( input );
		} );
		const upsert = findVerb( client.batches, 'upsert' );
		expect( upsert ).toBeTruthy();
		expect( upsert[ TO ] ).toBe( 'rules' );
		expect( upsert[ VALUE ].arguments ).toEqual( [
			JSON.stringify( input ),
		] );
		expect( result ).toEqual( { rule: saved } );
	} );

	test( 'listRules returns null after teardown (no graph)', async () => {
		const client = makeFakeClient( { list: { rules: [] } } );
		let api;
		const { unmount } = renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client } }
		);
		await act( async () => {} );
		unmount();
		let result;
		await act( async () => {
			result = await api.listRules();
		} );
		expect( result ).toBeNull();
	} );

	test( 'upsertRule reports the error and returns null when the reply rejects', async () => {
		const onError = jest.fn();
		const client = makeFakeClient(
			{ upsert: { rule: {} } },
			{ errorVerbs: [ 'upsert' ] }
		);
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { commandClient: client, onError } }
		);
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await api.upsertRule( { id: '', pattern: '/x?' } );
		} );
		expect( result ).toBeNull();
		expect( onError ).toHaveBeenCalled();
	} );
} );

describe( 'usePerformanceGraph — timer suspension on modal open / tab visibility', () => {
	test( 'pauses perf:timer while a URL detail is open, re-arms when it closes', async () => {
		const client = makeFakeClient( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		expect( Core.node( 'perf:timer' ).mode ).toBe( 'router' );

		// Open the URL detail modal — the overview/urls poll must suspend.
		await act( async () => {
			rerender( { commandClient: client, selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'perf:timer' ).mode ).toBe( 'inactive' );

		// Close it — the overview/urls poll resumes.
		await act( async () => {
			rerender( { commandClient: client, selectedUrl: null } );
		} );
		expect( Core.node( 'perf:timer' ).mode ).toBe( 'router' );
	} );

	test( 'url_detail auto-refresh rides a urldetail:timer + fetch-urldetail Fetcher (a router tick re-fires url_detail with the hash)', async () => {
		const client = makeFakeClient( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client, refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				commandClient: client,
				refreshInterval: '0',
				selectedUrl: { hash: 'abc' },
			} );
		} );
		// On-demand slice runs on a real Timer + Fetcher, not setInterval.
		expect( Core.node( 'urldetail:timer' ) ).toBeTruthy();
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );
		expect( Core.node( 'fetch-urldetail' ) ).toBeTruthy();

		client.batches.length = 0;
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		const detail = findVerb( client.batches, 'url_detail' );
		expect( detail ).toBeTruthy();
		expect(
			parseCommandArgs( detail[ VALUE ].arguments ).positional[ 0 ]
		).toBe( 'abc' );
	} );

	test( 'stops the urldetail:timer when a request detail opens, re-arms when it closes', async () => {
		const client = makeFakeClient( {
			url_detail: { last_modified: 1, requests: [] },
			request_detail: { rid: 'r1' },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { commandClient: client, selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );

		// Drill into a request — the url_detail poll must stop.
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedUrl: { hash: 'abc' },
				selectedRequest: 'r1',
				requestPartition: 0,
			} );
		} );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'inactive' );

		// Back out to the URL detail — the url_detail poll resumes.
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedUrl: { hash: 'abc' },
				selectedRequest: null,
			} );
		} );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );
	} );

	test( 'closing the last detail modal immediately re-fetches overview + urls (perf:timer was paused)', async () => {
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
		// While the modal is open the overview/urls poll is suspended.
		client.batches.length = 0;

		// Closing must refresh the now-visible overview at once.
		await act( async () => {
			rerender( { commandClient: client, selectedUrl: null } );
		} );
		expect( findVerb( client.batches, 'overview' ) ).toBeTruthy();
		expect( findVerb( client.batches, 'urls' ) ).toBeTruthy();
	} );

	test( 'a hidden tab stops the urldetail:timer; returning to visible re-arms it', async () => {
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
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );

		await act( async () => setVisibility( 'hidden' ) );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'inactive' );

		await act( async () => setVisibility( 'visible' ) );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );
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

// Mount the hook, capture its returned control API, and flush the first poll.
function renderGraph( props = {} ) {
	let api;
	const handle = renderHook(
		( p ) => {
			api = usePerformanceGraph( p );
			return api;
		},
		{ initialProps: props }
	);
	return { ...handle, getApi: () => api };
}

describe( 'usePerformanceGraph — overview/urls arg edge cases', () => {
	test( 'an empty chartBreakdown pads the overview breakdown list with status', async () => {
		const client = makeFakeClient();
		renderHook( () =>
			usePerformanceGraph( {
				commandClient: client,
				chartBreakdown: '',
			} )
		);
		await act( async () => {} );
		const overview = findVerb( client.batches, 'overview' );
		expect(
			parseCommandArgs( overview[ VALUE ].arguments ).options.breakdown
		).toBe( 'server,status' );
	} );

	test( 'a non-zero offset is emitted in the urls args (immediate fetch on a page change)', async () => {
		const client = makeFakeClient( { urls: { data: [], total: 0 } } );
		const { getApi } = renderGraph( { commandClient: client } );
		await act( async () => {} );
		const before = countVerbs( client.batches, 'urls' );
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'count',
				order: 'desc',
				offset: 50,
			} );
		} );
		// A non-search change fetches immediately (no debounce).
		expect( countVerbs( client.batches, 'urls' ) ).toBe( before + 1 );
		const urls = client.batches
			.flat()
			.reverse()
			.find( ( m ) => m[ VALUE ]?.name === 'urls' );
		expect(
			parseCommandArgs( urls[ VALUE ].arguments ).options.offset
		).toBe( '50' );
	} );

	test( 'an unchanged params object is a no-op (no extra urls fetch)', async () => {
		const client = makeFakeClient( { urls: { data: [], total: 0 } } );
		const { getApi } = renderGraph( { commandClient: client } );
		await act( async () => {} );
		const before = countVerbs( client.batches, 'urls' );
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'count',
				order: 'desc',
				offset: 0,
			} );
		} );
		expect( countVerbs( client.batches, 'urls' ) ).toBe( before );
	} );

	test( 'a second search change clears the first pending debounce timer', async () => {
		jest.useFakeTimers();
		const client = makeFakeClient( { urls: { data: [], total: 0 } } );
		const { getApi, unmount } = renderGraph( { commandClient: client } );
		await act( async () => {} );
		const before = countVerbs( client.batches, 'urls' );
		getApi().handleUrlParamsChange( {
			search: 'a',
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		// Second search before the first debounce fires → first timer cleared.
		getApi().handleUrlParamsChange( {
			search: 'ab',
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		jest.advanceTimersByTime( 300 );
		// Exactly one fetch despite two changes — the first was cancelled.
		expect( countVerbs( client.batches, 'urls' ) ).toBe( before + 1 );
		unmount();
		jest.useRealTimers();
	} );
} );

describe( 'usePerformanceGraph — invalid selection guards', () => {
	test( 'an invalid URL hash sends no url_detail command', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		const before = countVerbs( client.batches, 'url_detail' );
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedUrl: { hash: 'NOT-HEX!' },
			} );
		} );
		expect( countVerbs( client.batches, 'url_detail' ) ).toBe( before );
		expect( Core.node( 'urldetail:view' ).setStateCache.view.error ).toBe(
			'Invalid URL hash format'
		);
	} );

	test( 'an invalid request id sends no request_detail command', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedRequest: 'bad id!',
			} );
		} );
		expect( findVerb( client.batches, 'request_detail' ) ).toBeNull();
		expect(
			Core.node( 'requestdetail:view' ).setStateCache.view.error
		).toBe( 'Invalid request ID format' );
	} );

	test( 'a valid request with no resolvable partition sends no request_detail command', async () => {
		const client = makeFakeClient();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedRequest: 'r1',
				requestPartition: null,
				urlDetailData: { requests: [] },
			} );
		} );
		expect( findVerb( client.batches, 'request_detail' ) ).toBeNull();
	} );

	test( 'resolves the partition from urlDetailData.requests when requestPartition is null', async () => {
		const client = makeFakeClient( { request_detail: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { commandClient: client },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				commandClient: client,
				selectedRequest: 'r1',
				requestPartition: null,
				urlDetailData: {
					requests: [ { rid: 'r1', partition: 3 } ],
				},
			} );
		} );
		const req = findVerb( client.batches, 'request_detail' );
		expect( req ).toBeTruthy();
		expect(
			parseCommandArgs( req[ VALUE ].arguments ).options.partition
		).toBe( '3' );
	} );
} );

describe( 'usePerformanceGraph — no-graph fallbacks & awaited rejections', () => {
	test( 'resolveRequest falls back to the one-shot client when the graph is gone', async () => {
		const client = makeFakeClient( {
			request_search: { url_hash: 'h', partition: 1 },
		} );
		const { getApi, unmount } = renderGraph( { commandClient: client } );
		await act( async () => {} );
		unmount(); // interpreterRef.current → null, view nodes removed
		let resolved;
		await act( async () => {
			resolved = await getApi().resolveRequest( 'r9' );
		} );
		expect( client.send ).toHaveBeenCalledWith( {
			to: 'performance',
			verb: 'request_search',
			args: [ 'r9' ],
		} );
		expect( resolved ).toEqual( { url_hash: 'h', partition: 1 } );
	} );

	test( 'fetchUrlBreakdown returns null when the graph is gone', async () => {
		const client = makeFakeClient();
		const { getApi, unmount } = renderGraph( { commandClient: client } );
		await act( async () => {} );
		unmount();
		let result;
		await act( async () => {
			result = await getApi().fetchUrlBreakdown( 'abc123', 'method' );
		} );
		expect( result ).toBeNull();
	} );

	test( 'fetchUrlBreakdown reports the error and returns null when the reply rejects', async () => {
		const onError = jest.fn();
		const client = makeFakeClient(
			{ url_detail: { breakdown_time_series: { a: 1 } } },
			{ errorVerbs: [ 'url_detail' ] }
		);
		const { getApi } = renderGraph( { commandClient: client, onError } );
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await getApi().fetchUrlBreakdown( 'abc123', 'method' );
		} );
		expect( result ).toBeNull();
		expect( onError ).toHaveBeenCalled();
	} );

	test( 'control callbacks no-op after unmount (interpreter detached)', async () => {
		const client = makeFakeClient( { urls: { data: [], total: 0 } } );
		const { getApi, unmount } = renderGraph( { commandClient: client } );
		await act( async () => {} );
		unmount();
		// Param change after unmount must not throw (detached interpreter).
		expect( () =>
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'count',
				order: 'desc',
				offset: 7,
			} )
		).not.toThrow();
	} );
} );
