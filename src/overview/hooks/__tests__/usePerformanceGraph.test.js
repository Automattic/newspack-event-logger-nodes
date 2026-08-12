/**
 * usePerformanceGraph tests — the Performance Dashboard data graph rebuilt on the
 * substrate batched-poll toolkit (useBatchedPoll + addSliceFetcher), D1b.
 *
 * The graph:
 *   perf:timer (Timer) → perf:tee (Tee) → overview:fetch, urls:fetch (Fetchers,
 *     each with an argsFn getter reading current React UI state) → _shell/_http/performance
 *   overview:in (Tee) → overview:view (OverviewView)
 *   urls:in     (Tee) → urls:view     (UrlsView)
 *   urldetail:merge (UrlDetailMerge) → urldetail:view (UrlDetailView)   [on-demand]
 *   requestdetail:view (RequestDetailView)                             [on-demand]
 *
 * overview + urls are POLLED (on the Timer, live args via the getters); url_detail
 * and request_detail are ON-DEMAND (modal-open → fetch); resolveRequest /
 * fetchUrlBreakdown are awaited Promises settled via the relevant view's
 * via its own useNodeState.
 */

import { renderHook, act } from '../../../test-helpers/renderHook';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import {
	Core,
	ID,
	TO,
	FROM,
	KEY,
	VALUE,
	parseCommandArgs,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { usePerformanceGraph } from '../usePerformanceGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';

// Fake transport: postBatch returns TO=FROM replies keyed by verb.
// The seam is the WIRE: the graph packs, POSTs and unpacks for real, so
// HttpOut, the router and the interpreter all run. `wire.batches` is what was
// posted; a verb in `errorVerbs` answers TM_ERROR carrying its payload.
function installWire( payloadByVerb = {}, opts = {} ) {
	return installFakeCommandWire( ( m ) => {
		const name = m[ VALUE ]?.name;
		const payload = payloadByVerb[ name ] ?? payloadByVerb._default ?? null;
		if ( ! opts.errorVerbs?.includes( name ) ) {
			return payload;
		}
		// answerBatch ships an Error as its `.message`, so unwrap first.
		return new Error( payload?.message ?? payload ?? name );
	} );
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
		installWire();
		renderHook( () => usePerformanceGraph() );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		expect( Core.node( HTTP ) ).toBeTruthy();
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
		installWire();
		renderHook( () => usePerformanceGraph() );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'returns the three control callbacks', () => {
		installWire();
		const { result } = renderHook( () => usePerformanceGraph() );
		expect( typeof result.current.handleUrlParamsChange ).toBe(
			'function'
		);
		expect( typeof result.current.resolveRequest ).toBe( 'function' );
		expect( typeof result.current.fetchUrlBreakdown ).toBe( 'function' );
	} );
} );

describe( 'usePerformanceGraph — poll slices fire live args', () => {
	test( 'fires overview + urls on the first poll, TO=performance via the egress', async () => {
		const wire = installWire();
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );
		const overview = findVerb( wire.batches, 'overview' );
		expect( overview ).toBeTruthy();
		expect( overview[ TO ] ).toBe( 'performance' );
		const urls = findVerb( wire.batches, 'urls' );
		expect( urls ).toBeTruthy();
	} );

	test( 'the overview Fetcher emits the CURRENT serverFilter as a live arg', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { serverFilter: 'web1' } );
		} );
		const overview = wire.batches
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
		installWire( { overview: { total_requests: 7 } } );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );
		const view = Core.node( 'overview:view' );
		expect( view.setStateCache.view.data ).toEqual( { total_requests: 7 } );
	} );

	test( 'a urls reply lands in the urls:view slice (data + total)', async () => {
		installWire( {
			urls: { data: [ { hash: 'a' } ], total: 12, limit: 100, offset: 0 },
		} );
		renderHook( () => usePerformanceGraph() );
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
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'overview' );
		await act( async () => {
			rerender( { serverFilter: 'web2' } );
		} );
		expect( countVerbs( wire.batches, 'overview' ) ).toBeGreaterThan(
			before
		);
	} );

	/**
	 * Unauthenticated, every poke would mint an UNSIGNED command the server
	 * refuses — and a dashboard pokes on every filter change, modal close and
	 * poll tick. Holding is what keeps a dead session from becoming a flood.
	 */
	test( 'sends nothing while unauthenticated', async () => {
		const wire = installWire();
		// AFTER installWire: it installs a valid auth stub of its own, and an
		// absent session is this test's whole subject.
		forgetSession();
		__setAuthFetch( async () => null );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { serverFilter: 'web7' } );
		} );

		expect( countVerbs( wire.batches, 'overview' ) ).toBe( 0 );
	} );
} );

describe( 'usePerformanceGraph — refresh interval wiring', () => {
	test( 'arms the poll Timer at the selected refreshInterval (hitchhike + throttle)', () => {
		installWire();
		renderHook( () =>
			usePerformanceGraph( {
				refreshInterval: '30000',
			} )
		);
		const timer = Core.node( 'perf:timer' );
		expect( timer.mode ).toBe( 'router' );
		expect( timer.interval_ms ).toBe( 30000 );
	} );

	test( 'changing refreshInterval re-arms the poll Timer to the new cadence', async () => {
		installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '5000' },
		} );
		await act( async () => {} );
		expect( Core.node( 'perf:timer' ).interval_ms ).toBe( 5000 );

		await act( async () => {
			rerender( { refreshInterval: '60000' } );
		} );
		expect( Core.node( 'perf:timer' ).interval_ms ).toBe( 60000 );
	} );
} );

describe( 'usePerformanceGraph — on-demand url_detail / request_detail', () => {
	test( 'selecting a URL fires url_detail with the hash, routes the reply to urldetail:view', async () => {
		const wire = installWire( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		const detail = findVerb( wire.batches, 'url_detail' );
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

	// The view was BOTH the minter and the reply sink: request_detail went out
	// FROM `requestdetail:view`, so one node carried two protocols — its own
	// controls and a command reply. Every other slice mints from a receiver and
	// forwards to its view; this one now does too.
	test( 'request_detail is minted from the receiver, not from the view', async () => {
		const wire = installWire( { request_detail: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'r1', requestPartition: 2 } );
		} );

		const req = findVerb( wire.batches, 'request_detail' );
		expect( req[ FROM ] ).toBe( 'requestdetail:in' );
		expect( req[ FROM ] ).not.toBe( 'requestdetail:view' );

		const receiver = Core.node( 'requestdetail:in' );
		expect( receiver ).toBeTruthy();
		expect( receiver.target ).toContain( 'requestdetail:view' );
		// The reply still reaches the view, through the receiver.
		expect(
			Core.node( 'requestdetail:view' ).setStateCache.view.data
		).toEqual( { rid: 'r1' } );
	} );

	test( 'selecting a request fires request_detail with the partition', async () => {
		const wire = installWire( { request_detail: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				selectedRequest: 'r1',
				requestPartition: 2,
			} );
		} );
		const req = findVerb( wire.batches, 'request_detail' );
		expect( req ).toBeTruthy();
		expect(
			parseCommandArgs( req[ VALUE ].arguments ).options.partition
		).toBe( '2' );
	} );
} );

describe( 'usePerformanceGraph — handleUrlParamsChange', () => {
	test( 'debounces a search change (300ms)', async () => {
		jest.useFakeTimers();
		const wire = installWire( { urls: { data: [], total: 0 } } );
		let api;
		const { unmount } = renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'urls' );
		api.handleUrlParamsChange( {
			search: 'x',
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before );
		jest.advanceTimersByTime( 300 );
		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before + 1 );
		unmount();
		jest.useRealTimers();
	} );

	test( 'refetches when only errorsOnly changes', async () => {
		// The early-return compared search/sort/order/offset only, so toggling
		// the errors filter — which changes nothing else — returned before
		// sending anything. The button flipped to "Showing Errors" and the
		// table kept showing every URL.
		const wire = installWire( { urls: { data: [], total: 0 } } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		const base = { search: '', sort: 'count', order: 'desc', offset: 0 };
		api.handleUrlParamsChange( base );
		const before = countVerbs( wire.batches, 'urls' );

		api.handleUrlParamsChange( { ...base, errorsOnly: true } );

		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before + 1 );
		const sent = wire.batches
			.flat()
			.filter( ( m ) => m[ VALUE ]?.name === 'urls' );
		expect( sent[ sent.length - 1 ][ VALUE ].arguments ).toContain(
			'--errors_only=1'
		);
	} );
} );

describe( 'usePerformanceGraph — resolveRequest & fetchUrlBreakdown (awaited)', () => {
	test( 'resolveRequest returns the unwrapped reply payload', async () => {
		installWire( {
			request_search: { url_hash: 'h', partition: 1 },
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let resolved;
		await act( async () => {
			resolved = await api.resolveRequest( 'r' );
		} );
		expect( resolved ).toEqual( { url_hash: 'h', partition: 1 } );
	} );

	test( 'fetchUrlBreakdown returns breakdown_time_series via the transform', async () => {
		installWire( {
			url_detail: { breakdown_time_series: { a: 1 } },
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await api.fetchUrlBreakdown( 'abc123', 'method' );
		} );
		expect( result ).toEqual( { a: 1 } );
	} );

	test( 'fetchUrlBreakdown returns null on invalid hash without sending a breakdown command', async () => {
		const wire = installWire();
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		const before = wire.batches.flat().length;
		let result;
		await act( async () => {
			result = await api.fetchUrlBreakdown( 'NO', 'method' );
		} );
		expect( result ).toBeNull();
		const breakdowns = wire.batches
			.flat()
			.filter(
				( m ) =>
					m[ VALUE ]?.name === 'url_detail' &&
					parseCommandArgs( m[ VALUE ]?.arguments ).options.breakdown
			);
		expect( breakdowns ).toHaveLength( 0 );
		expect( wire.batches.flat().length ).toBe( before );
	} );

	test( 'resolveUrlHash returns the URL, which url_detail nests under stats', async () => {
		// useUrlNavigation reads `.url` and falls back to the hash, so a raw
		// payload here titles the modal with the hash. The URL below must not
		// equal that fallback, or the bug passes.
		installWire( {
			url_detail: {
				stats: { hash: 'b4dc0ffee', url: '/quokka/census-2026' },
				requests: [],
			},
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let resolved;
		await act( async () => {
			resolved = await api.resolveUrlHash( 'b4dc0ffee' );
		} );
		expect( resolved.url ).toBe( '/quokka/census-2026' );
	} );

	test( 'resolveUrlHash settles on a known-empty URL instead of retrying forever', async () => {
		// An indexed entry whose url is '' is answerable — null here would
		// re-issue url_detail every refresh tick and never open the modal.
		installWire( {
			url_detail: { stats: { hash: 'b4dc0ffee', url: '' } },
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let resolved;
		await act( async () => {
			resolved = await api.resolveUrlHash( 'b4dc0ffee' );
		} );
		expect( resolved ).toEqual( { url: '' } );
	} );

	test( 'resolveUrlHash does not ask for the category series it throws away', async () => {
		// It reads one string. Selecting the URL immediately refetches with
		// the full arg set, so asking for categories here pays for it twice.
		const wire = installWire( {
			url_detail: { stats: { hash: 'b4dc0ffee', url: '/x' } },
		} );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		await act( async () => {
			await api.resolveUrlHash( 'b4dc0ffee' );
		} );
		const detail = findVerb( wire.batches, 'url_detail' );
		expect(
			parseCommandArgs( detail[ VALUE ].arguments ).options.categories
		).toBeUndefined();
	} );

	test( 'resolveUrlHash settles when the server says the hash is unknown', async () => {
		// A bookmarked hash aged out of the index makes url_detail THROW.
		// Holding the intent there re-polls every tick and never opens.
		installWire( {}, { errorVerbs: [ 'url_detail' ] } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );

		let resolved;
		await act( async () => {
			resolved = await api.resolveUrlHash( 'b4dc0ffee' );
		} );

		expect( resolved ).toEqual( { url: '' } );
	} );

	test( 'resolveUrlHash holds the intent when no reply arrives at all', async () => {
		// Torn-down graph: the request rejects without ever reaching a server,
		// which is the case the ?url= intent is SUPPOSED to outlive.
		installWire();
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		Core.reset();

		let resolved;
		await act( async () => {
			resolved = await api.resolveUrlHash( 'b4dc0ffee' );
		} );

		expect( resolved ).toBeNull();
	} );
} );

describe( 'usePerformanceGraph — rules commands (_http/rules)', () => {
	test( 'exposes listRules + upsertRule callbacks', () => {
		installWire();
		const { result } = renderHook( () => usePerformanceGraph() );
		expect( typeof result.current.listRules ).toBe( 'function' );
		expect( typeof result.current.upsertRule ).toBe( 'function' );
	} );

	test( 'listRules sends the list verb to the rules CI and resolves { rules }', async () => {
		const rules = [ { id: 'a', pattern: '/x?', action: 'log' } ];
		const wire = installWire( { list: { rules } } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await api.listRules();
		} );
		const list = findVerb( wire.batches, 'list' );
		expect( list ).toBeTruthy();
		expect( list[ TO ] ).toBe( 'rules' );
		expect( result ).toEqual( { rules } );
	} );

	test( 'removeRule sends the delete verb with the rule id and resolves', async () => {
		const wire = installWire( { delete: { deleted: true } } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await api.removeRule( 'r-del-42' );
		} );
		const del = findVerb( wire.batches, 'delete' );
		expect( del ).toBeTruthy();
		expect( del[ TO ] ).toBe( 'rules' );
		expect( del[ VALUE ].arguments ).toEqual( [ 'r-del-42' ] );
		expect( result ).toEqual( { deleted: true } );
	} );

	test( 'upsertRule sends the upsert verb with the RAW JSON rule as arguments and resolves { rule }', async () => {
		const saved = { id: 'abc', pattern: '/blog?', action: 'log' };
		const wire = installWire( { upsert: { rule: saved } } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		const input = { id: '', pattern: '/blog?', action: 'log' };
		let result;
		await act( async () => {
			result = await api.upsertRule( input );
		} );
		const upsert = findVerb( wire.batches, 'upsert' );
		expect( upsert ).toBeTruthy();
		expect( upsert[ TO ] ).toBe( 'rules' );
		expect( upsert[ VALUE ].arguments ).toEqual( [
			JSON.stringify( input ),
		] );
		expect( result ).toEqual( { rule: saved } );
	} );

	test( 'listRules returns null after teardown (no graph)', async () => {
		installWire( { list: { rules: [] } } );
		let api;
		const { unmount } = renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
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
		installWire( { upsert: { rule: {} } }, { errorVerbs: [ 'upsert' ] } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: { onError } }
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

describe( 'usePerformanceGraph — requestGrep (recent-firehose pattern search)', () => {
	test( 'exposes the requestGrep callback', () => {
		installWire();
		const { result } = renderHook( () => usePerformanceGraph() );
		expect( typeof result.current.requestGrep ).toBe( 'function' );
	} );

	test( 'requestGrep sends request_grep to the performance CI and resolves the summary', async () => {
		const payload = {
			pattern: '/calendar',
			scope: 'recent',
			truncated: false,
			results: [
				{ rid: 'r1', url: '/calendar', method: 'GET', match_count: 2 },
			],
		};
		const wire = installWire( { request_grep: payload } );
		let api;
		renderHook(
			( p ) => {
				api = usePerformanceGraph( p );
				return api;
			},
			{ initialProps: {} }
		);
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await api.requestGrep( '/calendar', 25 );
		} );
		const grep = findVerb( wire.batches, 'request_grep' );
		expect( grep ).toBeTruthy();
		expect( grep[ TO ] ).toBe( 'performance' );
		expect( grep[ VALUE ].arguments ).toContain( '/calendar' );
		expect( grep[ VALUE ].arguments ).toContain( '--limit=25' );
		expect( result ).toEqual( payload );
	} );
} );

describe( 'usePerformanceGraph — timer suspension on modal open / tab visibility', () => {
	test( 'pauses perf:timer while a URL detail is open, re-arms when it closes', async () => {
		installWire( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		expect( Core.node( 'perf:timer' ).mode ).toBe( 'router' );

		// Open the URL detail modal — the overview/urls poll must suspend.
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'perf:timer' ).mode ).toBe( 'inactive' );

		// Close it — the overview/urls poll resumes.
		await act( async () => {
			rerender( { selectedUrl: null } );
		} );
		expect( Core.node( 'perf:timer' ).mode ).toBe( 'router' );
	} );

	test( 'url_detail auto-refresh rides a urldetail:timer + urldetail:fetch Fetcher (a router tick re-fires url_detail with the hash)', async () => {
		const wire = installWire( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'abc' },
			} );
		} );
		// On-demand slice runs on a real Timer + Fetcher, not setInterval.
		expect( Core.node( 'urldetail:timer' ) ).toBeTruthy();
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );
		expect( Core.node( 'urldetail:fetch' ) ).toBeTruthy();

		wire.batches.length = 0;
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		const detail = findVerb( wire.batches, 'url_detail' );
		expect( detail ).toBeTruthy();
		expect(
			parseCommandArgs( detail[ VALUE ].arguments ).positional[ 0 ]
		).toBe( 'abc' );
	} );

	test( 'stops the urldetail:timer when a request detail opens, re-arms when it closes', async () => {
		installWire( {
			url_detail: { last_modified: 1, requests: [] },
			request_detail: { rid: 'r1' },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );

		// Drill into a request — the url_detail poll must stop.
		await act( async () => {
			rerender( {
				selectedUrl: { hash: 'abc' },
				selectedRequest: 'r1',
				requestPartition: 0,
			} );
		} );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'inactive' );

		// Back out to the URL detail — the url_detail poll resumes.
		await act( async () => {
			rerender( {
				selectedUrl: { hash: 'abc' },
				selectedRequest: null,
			} );
		} );
		expect( Core.node( 'urldetail:timer' ).mode ).toBe( 'router' );
	} );

	test( 'closing the last detail modal immediately re-fetches overview + urls (perf:timer was paused)', async () => {
		const wire = installWire( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		// While the modal is open the overview/urls poll is suspended.
		wire.batches.length = 0;

		// Closing must refresh the now-visible overview at once.
		await act( async () => {
			rerender( { selectedUrl: null } );
		} );
		expect( findVerb( wire.batches, 'overview' ) ).toBeTruthy();
		expect( findVerb( wire.batches, 'urls' ) ).toBeTruthy();
	} );

	test( 'a hidden tab stops the urldetail:timer; returning to visible re-arms it', async () => {
		installWire( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
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
		installWire();
		const { unmount } = renderHook( () => usePerformanceGraph() );
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
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				chartBreakdown: '',
			} )
		);
		await act( async () => {} );
		const overview = findVerb( wire.batches, 'overview' );
		expect(
			parseCommandArgs( overview[ VALUE ].arguments ).options.breakdown
		).toBe( 'server,status' );
	} );

	test( 'a non-zero offset is emitted in the urls args (immediate fetch on a page change)', async () => {
		const wire = installWire( { urls: { data: [], total: 0 } } );
		const { getApi } = renderGraph( {} );
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'urls' );
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'count',
				order: 'desc',
				offset: 50,
			} );
		} );
		// A non-search change fetches immediately (no debounce).
		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before + 1 );
		const urls = wire.batches
			.flat()
			.reverse()
			.find( ( m ) => m[ VALUE ]?.name === 'urls' );
		expect(
			parseCommandArgs( urls[ VALUE ].arguments ).options.offset
		).toBe( '50' );
	} );

	test( 'an unchanged params object is a no-op (no extra urls fetch)', async () => {
		const wire = installWire( { urls: { data: [], total: 0 } } );
		const { getApi } = renderGraph( {} );
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'urls' );
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'count',
				order: 'desc',
				offset: 0,
			} );
		} );
		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before );
	} );

	test( 'a second search change clears the first pending debounce timer', async () => {
		jest.useFakeTimers();
		const wire = installWire( { urls: { data: [], total: 0 } } );
		const { getApi, unmount } = renderGraph( {} );
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'urls' );
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
		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before + 1 );
		unmount();
		jest.useRealTimers();
	} );
} );

describe( 'usePerformanceGraph — invalid selection guards', () => {
	test( 'an invalid URL hash sends no url_detail command', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'url_detail' );
		await act( async () => {
			rerender( {
				selectedUrl: { hash: 'NOT-HEX!' },
			} );
		} );
		expect( countVerbs( wire.batches, 'url_detail' ) ).toBe( before );
		expect( Core.node( 'urldetail:view' ).setStateCache.view.error ).toBe(
			'Invalid URL hash format'
		);
	} );

	test( 'an invalid request id sends no request_detail command', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				selectedRequest: 'bad id!',
			} );
		} );
		expect( findVerb( wire.batches, 'request_detail' ) ).toBeNull();
		expect(
			Core.node( 'requestdetail:view' ).setStateCache.view.error
		).toBe( 'Invalid request ID format' );
	} );

	test( 'a valid request with no resolvable partition sends no request_detail command', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				selectedRequest: 'r1',
				requestPartition: null,
				urlDetailData: { requests: [] },
			} );
		} );
		expect( findVerb( wire.batches, 'request_detail' ) ).toBeNull();
	} );

	test( 'resolves the partition from urlDetailData.requests when requestPartition is null', async () => {
		const wire = installWire( { request_detail: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				selectedRequest: 'r1',
				requestPartition: null,
				urlDetailData: {
					requests: [ { rid: 'r1', partition: 3 } ],
				},
			} );
		} );
		const req = findVerb( wire.batches, 'request_detail' );
		expect( req ).toBeTruthy();
		expect(
			parseCommandArgs( req[ VALUE ].arguments ).options.partition
		).toBe( '3' );
	} );
} );

describe( 'usePerformanceGraph — no-graph fallbacks & awaited rejections', () => {
	test( 'resolveRequest falls back to its own Request node when the detail view is gone', async () => {
		const wire = installWire( {
			request_search: { url_hash: 'h', partition: 1 },
		} );
		const { getApi } = renderGraph( {} );
		await act( async () => {} );
		// Drop the view the primary path needs; the fallback verb remains.
		Core.node( 'requestdetail:view' ).removeNode();
		let resolved;
		await act( async () => {
			resolved = await getApi().resolveRequest( 'r9' );
		} );
		const sent = wire.batches
			.flat()
			.find( ( m ) => 'request_search' === m[ VALUE ]?.name );
		expect( sent ).toBeTruthy();
		// Addressed, not correlated: FROM is the node, ID and KEY stay empty.
		expect( sent[ FROM ] ).toBe( 'performance:request_search' );
		expect( sent[ ID ] ).toBe( '' );
		expect( sent[ KEY ] ).toBe( '' );
		expect( resolved ).toEqual( { url_hash: 'h', partition: 1 } );
	} );

	test( 'fetchUrlBreakdown returns null when the graph is gone', async () => {
		installWire();
		const { getApi, unmount } = renderGraph( {} );
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
		installWire(
			{ url_detail: { breakdown_time_series: { a: 1 } } },
			{ errorVerbs: [ 'url_detail' ] }
		);
		const { getApi } = renderGraph( { onError } );
		await act( async () => {} );
		let result;
		await act( async () => {
			result = await getApi().fetchUrlBreakdown( 'abc123', 'method' );
		} );
		expect( result ).toBeNull();
		expect( onError ).toHaveBeenCalled();
	} );

	test( 'control callbacks no-op after unmount (interpreter detached)', async () => {
		installWire( { urls: { data: [], total: 0 } } );
		const { getApi, unmount } = renderGraph( {} );
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

// Every node here that takes a local control must declare the origin it
// trusts. Drop one assignment and sendControl throws rather than minting a
// FROM that matches nothing — which used to blank the slice in silence.
describe( 'usePerformanceGraph — control origins', () => {
	test( 'wires controlFrom on every control-taking node', () => {
		renderHook( () => usePerformanceGraph( {} ) );
		for ( const name of [
			'overview:view',
			'urls:view',
			'urldetail:view',
			'urldetail:merge',
			'requestdetail:view',
		] ) {
			expect( Core.node( name ).controlFrom ).toBe( name );
		}
	} );

	test( 'closing the url modal clears the slice through the control path', () => {
		let selectedUrl = { hash: 'a'.repeat( 32 ) };
		const { rerender } = renderHook( () =>
			usePerformanceGraph( {
				selectedUrl,
			} )
		);
		const view = Core.node( 'urldetail:view' );
		view.storeResult( { last_modified: 9, requests: [ { rid: 'a' } ] } );
		expect( view.model.data ).not.toBeNull();

		selectedUrl = null;
		act( () => rerender() );
		expect( view.model.data ).toBeNull();
	} );
} );
