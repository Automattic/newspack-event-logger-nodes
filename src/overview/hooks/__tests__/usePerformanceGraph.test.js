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
 * overview + urls are POLLED (on the Timer, live args via the getters);
 * url_detail and request_detail are ON-DEMAND (modal-open → fetch). The verbs a
 * click drives are NOT here — they live beside the state their replies set, and
 * are covered where they live.
 */

import {
	renderHook,
	act,
	cleanupMounts,
} from '../../../test-helpers/renderHook';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import {
	CommandInterpreterNode,
	Core,
	TO,
	FROM,
	VALUE,
	newMessage,
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

// Every verb here rides the router tick, so a dispatch takes a second rather
// than a microtask; jest's 5s default is not enough for a test that awaits
// more than a couple of them.
jest.setTimeout( 20000 );

// The graph POSTS on mount, so every test needs a wire — a test that wants a
// specific reply installs its own over this one.
beforeEach( () => {
	Core.reset();
	installWire();
} );

// A component left mounted keeps its graph effects live across the
// `Core.reset()` that opens the next test, and its next rebuild lands in THAT
// test's registry.
afterEach( () => cleanupMounts() );

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

	test( 'builds the on-demand detail nodes through an interpreter that never registered their names', () => {
		// ADR-16: the name map is a per-bundle static, so a hub tab building
		// this graph through ITS interpreter resolves none of these names.
		// Emptying the map is what that looks like from in here.
		const saved = {};
		for ( const name of [
			'UrlDetailMerge',
			'UrlDetailView',
			'RequestDetailView',
		] ) {
			saved[ name ] = CommandInterpreterNode.includeNodes[ name ];
			delete CommandInterpreterNode.includeNodes[ name ];
		}
		try {
			installWire();
			renderHook( () => usePerformanceGraph() );
			expect( Core.node( 'urldetail:merge' ) ).toBeTruthy();
			expect( Core.node( 'urldetail:view' ) ).toBeTruthy();
			expect( Core.node( 'requestdetail:view' ) ).toBeTruthy();
		} finally {
			Object.assign( CommandInterpreterNode.includeNodes, saved );
		}
	} );

	test( 'does NOT mount _output / _completion / _uptime / _cwd (dashboards are not REPLs)', () => {
		installWire();
		renderHook( () => usePerformanceGraph() );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	// The on-demand verbs live beside the state their replies set, so this
	// hook hands back the one callback that is genuinely its own.
	test( 'returns handleUrlParamsChange and nothing else', () => {
		installWire();
		const { result } = renderHook( () => usePerformanceGraph() );
		expect( Object.keys( result.current ) ).toEqual( [
			'handleUrlParamsChange',
		] );
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

	test( 'a urls reply lands in the urls:view slice (data + totals)', async () => {
		installWire( {
			urls: {
				data: [ { hash: 'a' } ],
				totals: { urls: 12, requests: 340 },
				limit: 100,
				offset: 0,
			},
		} );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );
		const view = Core.node( 'urls:view' );
		expect( view.setStateCache.view ).toEqual( {
			data: [ { hash: 'a' } ],
			totals: { urls: 12, requests: 340 },
			rows: 0,
			slowest: [],
			filters: null,
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
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
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
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
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

	test( 'the selected server is emitted in the url_detail args', async () => {
		// The modal opens from a row the server filter scoped, so it has to ask
		// the same question — otherwise one click puts the site's average under
		// that row's count.
		const wire = installWire( {
			url_detail: { last_modified: 1, requests: [] },
		} );
		renderHook( () =>
			usePerformanceGraph( {
				serverFilter: 'alpha.example',
				selectedUrl: { hash: 'abc' },
			} )
		);
		await act( async () => {} );
		const detail = findVerb( wire.batches, 'url_detail' );
		expect(
			parseCommandArgs( detail[ VALUE ].arguments ).options.server
		).toBe( 'alpha.example' );
	} );

	test( 'the selected server is emitted in the urls args', async () => {
		// The URL table is what the Overview header now counts, so the two have
		// to be asking the same question: a server the header is scoped to must
		// reach the `urls` verb as well as `overview`.
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		renderHook( () =>
			usePerformanceGraph( { serverFilter: 'alpha.example' } )
		);
		await act( async () => {} );
		const urls = findVerb( wire.batches, 'urls' );
		expect(
			parseCommandArgs( urls[ VALUE ].arguments ).options.server
		).toBe( 'alpha.example' );
	} );

	test( 'a non-zero offset is emitted in the urls args (immediate fetch on a page change)', async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
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
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
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
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
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

	test( 'an unresolved partition reports an error instead of doing nothing', async () => {
		// Silence here was the whole bug: no fetch, no loading state, no error,
		// so the modal rendered neither the request nor the URL sections.
		const wire = installWire( { request_detail: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'r1', requestPartition: null } );
		} );

		expect( findVerb( wire.batches, 'request_detail' ) ).toBeNull();
		expect( Core.node( 'requestdetail:view' ).model.error ).toBeTruthy();
	} );

	test( 'never reconstructs the partition from the recent-request window', async () => {
		// That window is a page of recent requests, not a source of truth about
		// one request: a deep link to an older rid simply is not in it.
		const wire = installWire( { request_detail: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				selectedRequest: 'r1',
				requestPartition: null,
				urlDetailData: { requests: [ { rid: 'r1', partition: 3 } ] },
			} );
		} );

		expect( findVerb( wire.batches, 'request_detail' ) ).toBeNull();
	} );
} );

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
		// Drive it the way the graph does: a reply, not a method call.
		const landed = newMessage();
		landed[ VALUE ] = {
			name: 'url_detail',
			payload: { last_modified: 9, requests: [ { rid: 'a' } ] },
		};
		view.fill( landed );
		expect( view.model.data ).not.toBeNull();

		selectedUrl = null;
		act( () => rerender() );
		expect( view.model.data ).toBeNull();
	} );
} );
