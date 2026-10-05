/**
 * usePerformanceGraph tests — the Performance Dashboard data graph rebuilt on the
 * substrate batched-poll toolkit (useBatchedPoll + addSliceFetcher), D1b.
 *
 * The graph:
 *   performance:timer (Timer) → performance:tee (Tee) → overview:fetch, urls:fetch (Fetchers,
 *     each with an argsFn getter reading current React UI state) → _shell/_http/performance
 *   overview:in (Tee) → overview:view (OverviewView)
 *   urls:in     (Tee) → urls:view     (UrlsView)
 *   url-detail:transform (UrlDetailMerge) → url-detail:view (UrlDetailView)   [on-demand]
 *   request-detail:view (RequestDetailView)                             [on-demand]
 *
 * overview + urls are POLLED (on the Timer, live args via the getters);
 * dump_url and dump_request are ON-DEMAND (modal-open → fetch). The verbs a
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
	TYPE,
	VALUE,
	TM_ERROR,
	newMessage,
	ensureSession,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { usePerformanceGraph } from '../usePerformanceGraph';

/**
 * The value a command's tokens carry for `--<name>=`, as the server's binder
 * reads a named arg.
 *
 * @param {string[]|undefined} tokens The command's argument tokens.
 * @param {string}             name   The arg's name.
 * @return {string|undefined} Its value, or undefined when the tokens omit it.
 */
const option = ( tokens, name ) =>
	tokens
		?.find( ( token ) => token.startsWith( `--${ name }=` ) )
		?.slice( name.length + 3 );

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
			'url-detail:view',
			'request-detail:view',
		] ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// The urlDetail merge transform sits on the receiver→view edge.
		expect( Core.node( 'url-detail:transform' ) ).toBeTruthy();
		// …and the receiver fans back to the Fetcher, which settles the ask.
		// Without it the refresh asks once and never again: the outbox holds
		// an ask that nothing answers until the fail-open window.
		expect( Core.node( 'url-detail:in' ).target ).toEqual( [
			'url-detail:in:current',
			'url-detail:fetch',
		] );
		// Every slice gates its edge on the Fetcher's outbox.
		expect( Core.node( 'url-detail:in:current' ).target ).toEqual( [
			'url-detail:transform',
		] );
		expect( Core.node( 'urls:in' ).target ).toEqual( [
			'urls:in:current',
			'urls:fetch',
		] );
		expect( Core.node( 'urls:in:current' ).fetcher ).toBe( 'urls:fetch' );
		// Every name is `<subject>:<role>`; the old spellings are gone.
		for ( const name of [
			'perf:timer',
			'perf:tee',
			'urldetail:view',
			'urldetail:merge',
			'requestdetail:view',
		] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'builds the on-demand detail nodes through an interpreter that never registered their names', () => {
		// ADR-16: the name map is a per-bundle static, so a station tab building
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
			expect( Core.node( 'url-detail:transform' ) ).toBeTruthy();
			expect( Core.node( 'url-detail:view' ) ).toBeTruthy();
			expect( Core.node( 'request-detail:view' ) ).toBeTruthy();
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
					option( m[ VALUE ]?.arguments, 'server' ) === 'web1'
			);
		expect( overview ).toBeTruthy();
	} );

	test( 'an overview reply lands in the overview:view slice', async () => {
		installWire( { overview: { total_requests: 7 } } );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );
		const view = Core.node( 'overview:view' );
		expect( view.view.data ).toEqual( { total_requests: 7 } );
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
		expect( view.view ).toEqual( {
			data: [ { hash: 'a' } ],
			totals: { urls: 12, requests: 340 },
			rows: 0,
			slowest: [],
			filters: null,
			ranked: false,
			provisional: false,
			as_of: 0,
			loading: false,
			error: null,
		} );
	} );

	// @longform A filter change is a new question. The answer to the old one,
	// still on the wire when the filter changed, must not render over the
	// loading state the new question put up, nor over its answer after.
	test( 'a reply to the old server filter never reaches the views', async () => {
		const held = [];
		installFakeCommandWire( ( m ) => {
			const name = m[ VALUE ]?.name;
			if ( 'overview' !== name && 'urls' !== name ) {
				return null;
			}
			if ( 'web7' === option( m[ VALUE ].arguments, 'server' ) ) {
				return 'overview' === name
					? { total_requests: 7317 }
					: { data: [ { hash: 'beef7317' } ], totals: { urls: 1 } };
			}
			return new Promise( ( resolve ) =>
				held.push( () =>
					resolve(
						'overview' === name
							? { total_requests: 4471 }
							: {
									data: [ { hash: 'dead4471' } ],
									totals: { urls: 1 },
							  }
					)
				)
			);
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		expect( held ).toHaveLength( 2 );

		await act( async () => {
			rerender( { serverFilter: 'web7' } );
		} );
		await act( async () => {
			held.forEach( ( release ) => release() );
		} );

		expect( Core.node( 'overview:view' ).view.data ).toEqual( {
			total_requests: 7317,
		} );
		expect( Core.node( 'urls:view' ).view.data ).toEqual( [
			{ hash: 'beef7317' },
		] );
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
		const timer = Core.node( 'performance:timer' );
		expect( timer.mode ).toBe( 'router' );
		expect( timer.interval_ms ).toBe( 30000 );
	} );

	test( 'changing refreshInterval re-arms the poll Timer to the new cadence', async () => {
		installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '5000' },
		} );
		await act( async () => {} );
		expect( Core.node( 'performance:timer' ).interval_ms ).toBe( 5000 );

		await act( async () => {
			rerender( { refreshInterval: '60000' } );
		} );
		expect( Core.node( 'performance:timer' ).interval_ms ).toBe( 60000 );
	} );
} );

describe( 'usePerformanceGraph — on-demand dump_url / dump_request', () => {
	test( 'selecting a URL fires dump_url with the hash, routes the reply to url-detail:view', async () => {
		const wire = installWire( {
			dump_url: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		const detail = findVerb( wire.batches, 'dump_url' );
		expect( detail ).toBeTruthy();
		expect( detail[ VALUE ].arguments[ 0 ] ).toBe( 'abc' );
		const view = Core.node( 'url-detail:view' );
		expect( view.view.data ).toEqual( {
			last_modified: 1,
			requests: [],
		} );
	} );

	// The view was BOTH the minter and the reply sink: dump_request went out
	// FROM `request-detail:view`, so one node carried two protocols — its own
	// controls and a command reply. Every other slice mints from a receiver and
	// forwards to its view; this one now does too.
	test( 'dump_request is asked through its Fetcher, answered at the receiver', async () => {
		const wire = installWire( { dump_request: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'r1', requestPartition: 2 } );
		} );

		const req = findVerb( wire.batches, 'dump_request' );
		expect( req[ FROM ] ).toBe( 'request-detail:in' );
		expect( req[ FROM ] ).not.toBe( 'request-detail:view' );

		const receiver = Core.node( 'request-detail:in' );
		expect( receiver ).toBeTruthy();
		expect( receiver.target ).toEqual( [
			'request-detail:in:current',
			'request-detail:fetch',
		] );
		// The reply still reaches the view, through the receiver.
		expect( Core.node( 'request-detail:view' ).view.data ).toEqual( {
			rid: 'r1',
		} );
	} );

	// The request modal's own Timer runs only while a request is open to see.
	test( 'arms request-detail:timer while a request is selected and visible', async () => {
		installWire( { dump_request: { rid: 'kea4471' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		expect( Core.node( 'request-detail:timer' ).mode ).toBe( 'inactive' );

		await act( async () => {
			rerender( { selectedRequest: 'kea4471', requestPartition: 3 } );
		} );
		expect( Core.node( 'request-detail:timer' ).mode ).toBe( 'router' );

		await act( async () => setVisibility( 'hidden' ) );
		expect( Core.node( 'request-detail:timer' ).mode ).toBe( 'inactive' );
		await act( async () => setVisibility( 'visible' ) );
		expect( Core.node( 'request-detail:timer' ).mode ).toBe( 'router' );

		await act( async () => {
			rerender( {} );
		} );
		expect( Core.node( 'request-detail:timer' ).mode ).toBe( 'inactive' );
	} );

	// @longform A selection made with no session parks its ask: nothing can
	// sign it yet. The Timer's next tick after the session appears sends it,
	// so the modal does not spin on an ask that never went out.
	test( 'sends an ask parked with no session on the tick after one appears', async () => {
		installWire( { dump_request: { rid: 'kea4471' } } );
		forgetSession();
		__setAuthFetch( async () => null );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'kea4471', requestPartition: 3 } );
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		expect( Core.node( 'request-detail:fetch' ).outbox ).toEqual( [
			expect.objectContaining( { askedAt: 0 } ),
		] );

		const wire = installWire( { dump_request: { rid: 'kea4471' } } );
		// The failed /auth left a backoff; a fresh start mints at once.
		forgetSession();
		await act( async () => {
			await ensureSession();
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );

		expect( countVerbs( wire.batches, 'dump_request' ) ).toBe( 1 );
		expect( Core.node( 'request-detail:view' ).view.data ).toEqual( {
			rid: 'kea4471',
		} );
	} );

	// A late answer about the request the operator has left never overwrites
	// the one now open.
	test( 'a late dump_request for the previous rid never reaches the view', async () => {
		const held = [];
		installFakeCommandWire( ( m ) => {
			if ( 'dump_request' !== m[ VALUE ]?.name ) {
				return null;
			}
			const rid = m[ VALUE ].arguments[ 0 ];
			return 'kea4471' === rid
				? new Promise( ( resolve ) =>
						held.push( () => resolve( { rid } ) )
				  )
				: { rid };
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'kea4471', requestPartition: 3 } );
		} );
		await act( async () => {
			rerender( { selectedRequest: 'kahu9932', requestPartition: 3 } );
		} );
		expect( Core.node( 'request-detail:view' ).view.data ).toEqual( {
			rid: 'kahu9932',
		} );

		await act( async () => {
			held.forEach( ( release ) => release() );
		} );

		expect( Core.node( 'request-detail:view' ).view.data ).toEqual( {
			rid: 'kahu9932',
		} );
	} );

	// Loading keeps the slice, so an uncleared modal shows the old record.
	test( 'opening another request empties the modal before it loads', async () => {
		installFakeCommandWire( ( m ) => {
			if ( 'dump_request' !== m[ VALUE ]?.name ) {
				return null;
			}
			const rid = m[ VALUE ].arguments[ 0 ];
			return 'kahu9932' === rid ? new Promise( () => {} ) : { rid };
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'kea4471', requestPartition: 3 } );
		} );
		expect( Core.node( 'request-detail:view' ).view.data ).toEqual( {
			rid: 'kea4471',
		} );

		await act( async () => {
			rerender( { selectedRequest: 'kahu9932', requestPartition: 3 } );
		} );

		const view = Core.node( 'request-detail:view' ).view;
		expect( view.loading ).toBe( true );
		expect( view.data ).not.toEqual( { rid: 'kea4471' } );
	} );

	test( 'selecting a request fires dump_request with the partition', async () => {
		const wire = installWire( { dump_request: { rid: 'r1' } } );
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
		const req = findVerb( wire.batches, 'dump_request' );
		expect( req ).toBeTruthy();
		expect( option( req[ VALUE ].arguments, 'partition' ) ).toBe( '2' );
	} );
} );

describe( 'usePerformanceGraph — a newer urls question retires the older', () => {
	/**
	 * A wire that holds every `urls` reply sorted by count open until the test
	 * releases it, and answers any other sort at once.
	 *
	 * @return {Array<Function>} The resolvers of the held replies.
	 */
	function holdCountSortedUrls() {
		const held = [];
		installFakeCommandWire( ( m ) => {
			if ( 'urls' !== m[ VALUE ]?.name ) {
				return null;
			}
			if ( 'url' === option( m[ VALUE ].arguments, 'sort' ) ) {
				return {
					data: [ { hash: 'c0ffee7731aa' } ],
					totals: { urls: 1 },
				};
			}
			return new Promise( ( resolve ) => held.push( resolve ) );
		} );
		return held;
	}

	const bySort = { search: '', order: 'desc', offset: 0 };

	test( 'a sort change aborts the pending request for the old sort', async () => {
		holdCountSortedUrls();
		let api;
		renderHook( () => {
			api = usePerformanceGraph();
			return api;
		} );
		await act( async () => {} );
		const fetcher = Core.node( 'urls:fetch' );
		expect(
			fetcher.outbox.map( ( ask ) => option( ask.args, 'sort' ) )
		).toEqual( [ 'count' ] );

		api.handleUrlParamsChange( { ...bySort, sort: 'url' } );

		expect(
			fetcher.outbox.map( ( ask ) => option( ask.args, 'sort' ) )
		).toEqual( [ 'url' ] );
	} );

	test( 'a slower stale reply arriving after the newer one is ignored', async () => {
		const held = holdCountSortedUrls();
		let api;
		renderHook( () => {
			api = usePerformanceGraph();
			return api;
		} );
		await act( async () => {} );
		await act( async () => {
			api.handleUrlParamsChange( { ...bySort, sort: 'url' } );
		} );
		const view = Core.node( 'urls:view' );
		expect( view.view.data ).toEqual( [ { hash: 'c0ffee7731aa' } ] );

		await act( async () => {
			held.forEach( ( resolve ) =>
				resolve( {
					data: [ { hash: 'dead0beef111' } ],
					totals: { urls: 1 },
				} )
			);
		} );

		expect( view.view.data ).toEqual( [ { hash: 'c0ffee7731aa' } ] );
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

	// @longform A search is the question, not its address: an ask naming its
	// arguments as a subject put the whole search on FROM, and one long
	// enough passed MAX_FROM_SIZE, so the command was dropped unsent.
	test( 'a long search goes out, addressed bare', async () => {
		jest.useFakeTimers();
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		let api;
		const { unmount } = renderHook( () => {
			api = usePerformanceGraph();
			return api;
		} );
		await act( async () => {} );
		const search = 'kakapo-'.repeat( 200 );
		api.handleUrlParamsChange( {
			search,
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		jest.advanceTimersByTime( 300 );

		const sent = wire.batches
			.flat()
			.filter( ( m ) => m[ VALUE ]?.name === 'urls' );
		const asked = sent[ sent.length - 1 ];
		expect( option( asked[ VALUE ].arguments, 'search' ) ).toBe( search );
		expect( asked[ FROM ] ).toBe( 'urls:in' );
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
	test( 'pauses performance:timer while a URL detail is open, re-arms when it closes', async () => {
		installWire( {
			dump_url: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		expect( Core.node( 'performance:timer' ).mode ).toBe( 'router' );

		// Open the URL detail modal — the overview/urls poll must suspend.
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'performance:timer' ).mode ).toBe( 'inactive' );

		// Close it — the overview/urls poll resumes.
		await act( async () => {
			rerender( { selectedUrl: null } );
		} );
		expect( Core.node( 'performance:timer' ).mode ).toBe( 'router' );
	} );

	test( 'dump_url auto-refresh rides a url-detail:timer + url-detail:fetch Fetcher (a router tick re-fires dump_url with the hash)', async () => {
		const wire = installWire( {
			dump_url: { last_modified: 1, requests: [] },
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
		expect( Core.node( 'url-detail:timer' ) ).toBeTruthy();
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );
		expect( Core.node( 'url-detail:fetch' ) ).toBeTruthy();

		wire.batches.length = 0;
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		const detail = findVerb( wire.batches, 'dump_url' );
		expect( detail ).toBeTruthy();
		expect( detail[ VALUE ].arguments[ 0 ] ).toBe( 'abc' );
	} );

	/**
	 * The refresh tick carries the browser's cursor — the newest log position
	 * it holds per partition — so each partition's reverse scan stops at known
	 * ground instead of re-reading the whole retained window. The OPEN fetch
	 * must not: the merge is cleared first, so nothing is held and the full
	 * window is wanted.
	 */
	test( 'the refresh tick sends --after from the merge, the open fetch does not', async () => {
		const wire = installWire( {
			dump_url: {
				last_modified: 1,
				positions: {
					2: { segment: 5, offset: 8192 },
					3: { segment: 1, offset: 612 },
				},
				requests: [
					{
						rid: 'a',
						timestamp: 1787000900,
						partition: 2,
						segment: 5,
						offset: 4096,
					},
				],
			},
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

		// The open fetch reads the whole window.
		const opened = findVerb( wire.batches, 'dump_url' );
		expect( option( opened[ VALUE ].arguments, 'after' ) ).toBeUndefined();

		// The reply is now retained, so the next tick asks only for what is new.
		wire.batches.length = 0;
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		const refreshed = findVerb( wire.batches, 'dump_url' );
		expect(
			JSON.parse( option( refreshed[ VALUE ].arguments, 'after' ) )
		).toEqual( {
			2: { segment: 5, offset: 8192 },
			3: { segment: 1, offset: 612 },
		} );
	} );

	// The arguments ARE the question, so the ask needs no subject to name it.
	test( 'the open fetch asks through url-detail:fetch, addressed bare', async () => {
		const wire = installWire( {
			dump_url: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'f00d7731' },
			} );
		} );

		const opened = findVerb( wire.batches, 'dump_url' );
		expect( opened[ FROM ] ).toBe( 'url-detail:in' );
		// Answered, so nothing stands and the next tick mints the refresh.
		expect( Core.node( 'url-detail:fetch' ).outbox ).toEqual( [] );
	} );

	test( "a refresh tick's reply reaches the view", async () => {
		let replies = 0;
		installFakeCommandWire( ( m ) =>
			'dump_url' === m[ VALUE ]?.name
				? {
						last_modified: 1,
						positions: { 4: { segment: 2, offset: 913 } },
						requests: [
							{
								rid: `kahu-${ ++replies }`,
								timestamp: 1787000000 + replies,
							},
						],
				  }
				: null
		);
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'f00d7731' },
			} );
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );

		expect(
			Core.node( 'url-detail:view' ).view.data.requests.map(
				( r ) => r.rid
			)
		).toEqual( [ 'kahu-2', 'kahu-1' ] );
	} );

	test( 'a reply to the ask an Errors Only flip superseded never lands', async () => {
		const held = [];
		installFakeCommandWire( ( m ) => {
			if ( 'dump_url' !== m[ VALUE ]?.name ) {
				return null;
			}
			if (
				! m[ VALUE ].arguments.some( ( t ) =>
					t.startsWith( '--errors_only' )
				)
			) {
				return new Promise( ( resolve ) => held.push( resolve ) );
			}
			return {
				last_modified: 1,
				requests: [
					{ rid: 'tout-1', timestamp: 1787000300, error_status: 'T' },
				],
			};
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'f00d7731' },
			} );
		} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'f00d7731' },
				urlErrorsOnly: true,
			} );
		} );
		await act( async () => {
			held.forEach( ( resolve ) =>
				resolve( {
					last_modified: 1,
					requests: [ { rid: 'clean-1', timestamp: 1787000900 } ],
				} )
			);
		} );

		expect(
			Core.node( 'url-detail:view' ).view.data.requests.map(
				( r ) => r.rid
			)
		).toEqual( [ 'tout-1' ] );
	} );

	// A refusal of the modal's ask goes to its view, around the merge.
	test( 'a NOT_AVAILABLE bounce for the open modal shows on its view', async () => {
		installFakeCommandWire( ( m ) =>
			'dump_url' === m[ VALUE ]?.name ? undefined : null
		);
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'f00d4471' },
			} );
		} );
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'url-detail:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( INTERPRETER ).fill( bounce );
		} );

		expect( Core.node( 'url-detail:view' ).view.error ).toMatch(
			/NOT_AVAILABLE/
		);
	} );

	test( 'stops the url-detail:timer when a request detail opens, re-arms when it closes', async () => {
		installWire( {
			dump_url: { last_modified: 1, requests: [] },
			dump_request: { rid: 'r1' },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );

		// Drill into a request — the dump_url poll must stop.
		await act( async () => {
			rerender( {
				selectedUrl: { hash: 'abc' },
				selectedRequest: 'r1',
				requestPartition: 0,
			} );
		} );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'inactive' );

		// Back out to the URL detail — the dump_url poll resumes.
		await act( async () => {
			rerender( {
				selectedUrl: { hash: 'abc' },
				selectedRequest: null,
			} );
		} );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );
	} );

	// The picker holds the poll for the modal's reason: a page that repaints
	// under a stationary pointer swaps the row being aimed at, strands the `?`
	// cursor on a node that no longer exists, and answers about numbers the
	// reader never saw. Asserted on the Timer's own mode, as the modal case
	// above is — the poll is the hitchhike, so stopping it IS the hold.
	test( 'an armed picker holds the overview poll, and disarming resumes it', async () => {
		const wire = installWire( {} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { askActive: false },
		} );
		await act( async () => {} );
		expect( Core.node( 'performance:timer' ).mode ).toBe( 'router' );

		await act( async () => {
			rerender( { askActive: true } );
		} );
		expect( Core.node( 'performance:timer' ).mode ).not.toBe( 'router' );

		wire.batches.length = 0;
		await act( async () => {
			rerender( { askActive: false } );
		} );
		expect( Core.node( 'performance:timer' ).mode ).toBe( 'router' );
		// A held poll leaves the visible page stale, so release refreshes it.
		expect( findVerb( wire.batches, 'overview' ) ).toBeTruthy();
	} );

	test( 'closing the last detail modal immediately re-fetches overview + urls (performance:timer was paused)', async () => {
		const wire = installWire( {
			dump_url: { last_modified: 1, requests: [] },
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

	test( 'a hidden tab stops the url-detail:timer; returning to visible re-arms it', async () => {
		installWire( {
			dump_url: { last_modified: 1, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );

		await act( async () => setVisibility( 'hidden' ) );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'inactive' );

		await act( async () => setVisibility( 'visible' ) );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );
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
			'url-detail:view',
			'request-detail:view',
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
	test( 'asks about the dimensions the page reads, and no filler', async () => {
		// The server answers about exactly the dimensions it was asked for,
		// whatever the count, so a dimension nothing reads is a memcache walk
		// per poll for a series no chart draws.
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				chartBreakdown: '',
			} )
		);
		await act( async () => {} );
		const overview = findVerb( wire.batches, 'overview' );
		expect( option( overview[ VALUE ].arguments, 'breakdown' ) ).toBe(
			'server'
		);
	} );

	/**
	 * A busy URL's newest requests bury its errors, so "Errors Only" is the
	 * server's question: the walk then passes the clean requests to reach
	 * them. Turning it on starts the list again, so the ask carries no cursor,
	 * and every refresh after it keeps asking for errors.
	 */
	test( 'Errors Only asks dump_url for errors, from a cleared list', async () => {
		const wire = installWire( {
			dump_url: {
				last_modified: 1,
				positions: { 1: { segment: 4, offset: 7319 } },
				requests: [],
			},
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'beef4471' },
			} );
		} );
		const opened = findVerb( wire.batches, 'dump_url' );
		expect( opened[ VALUE ].arguments ).not.toContain( '--errors_only' );

		// The flip restarts the list under the new ask, keeping the flame, and
		// the view drops the old list rather than show it under the new toggle.
		const recordControls = ( name ) => {
			const seen = [];
			const node = Core.node( name );
			const fill = node.fill.bind( node );
			node.fill = ( m ) => {
				if ( m[ VALUE ]?.action ) {
					seen.push( m[ VALUE ] );
				}
				fill( m );
			};
			return seen;
		};
		const controls = recordControls( 'url-detail:transform' );
		const viewControls = recordControls( 'url-detail:view' );
		wire.batches.length = 0;
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'beef4471' },
				urlErrorsOnly: true,
			} );
		} );
		const asked = findVerb( wire.batches, 'dump_url' );
		expect( asked[ VALUE ].arguments ).toContain( '--errors_only' );
		expect( option( asked[ VALUE ].arguments, 'after' ) ).toBeUndefined();
		expect( controls ).toEqual( [ { action: 'relist' } ] );
		expect( viewControls ).toEqual( [
			{ action: 'clear' },
			{ action: 'loading' },
		] );

		wire.batches.length = 0;
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		const refreshed = findVerb( wire.batches, 'dump_url' );
		expect( refreshed[ VALUE ].arguments ).toContain( '--errors_only' );
	} );

	test( 'the selected server is emitted in the dump_url args', async () => {
		// The modal opens from a row the server filter scoped, so it has to ask
		// the same question — otherwise one click puts the site's average under
		// that row's count.
		const wire = installWire( {
			dump_url: { last_modified: 1, requests: [] },
		} );
		renderHook( () =>
			usePerformanceGraph( {
				serverFilter: 'alpha.example',
				selectedUrl: { hash: 'abc' },
			} )
		);
		await act( async () => {} );
		const detail = findVerb( wire.batches, 'dump_url' );
		expect( option( detail[ VALUE ].arguments, 'server' ) ).toBe(
			'alpha.example'
		);
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
		expect( option( urls[ VALUE ].arguments, 'server' ) ).toBe(
			'alpha.example'
		);
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
		expect( option( urls[ VALUE ].arguments, 'offset' ) ).toBe( '50' );
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
	test( 'an invalid URL hash sends no dump_url command', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'dump_url' );
		await act( async () => {
			rerender( {
				selectedUrl: { hash: 'NOT-HEX!' },
			} );
		} );
		expect( countVerbs( wire.batches, 'dump_url' ) ).toBe( before );
		expect( Core.node( 'url-detail:view' ).view.error ).toBe(
			'Invalid URL hash format'
		);
	} );

	test( 'an invalid request id sends no dump_request command', async () => {
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
		expect( findVerb( wire.batches, 'dump_request' ) ).toBeNull();
		expect( Core.node( 'request-detail:view' ).view.error ).toBe(
			'Invalid request ID format'
		);
	} );

	test( 'an unresolved partition reports an error instead of doing nothing', async () => {
		// Silence here was the whole bug: no fetch, no loading state, no error,
		// so the modal rendered neither the request nor the URL sections.
		const wire = installWire( { dump_request: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'r1', requestPartition: null } );
		} );

		expect( findVerb( wire.batches, 'dump_request' ) ).toBeNull();
		expect( Core.node( 'request-detail:view' ).view.error ).toBeTruthy();
	} );

	test( 'never reconstructs the partition from the recent-request window', async () => {
		// That window is a page of recent requests, not a source of truth about
		// one request: a deep link to an older rid simply is not in it.
		const wire = installWire( { dump_request: { rid: 'r1' } } );
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

		expect( findVerb( wire.batches, 'dump_request' ) ).toBeNull();
	} );
} );

describe( 'usePerformanceGraph — control origins', () => {
	test( 'wires controlFrom on every control-taking node', () => {
		renderHook( () => usePerformanceGraph( {} ) );
		for ( const name of [
			'overview:view',
			'urls:view',
			'url-detail:view',
			'url-detail:transform',
			'request-detail:view',
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
		const view = Core.node( 'url-detail:view' );
		// Drive it the way the graph does: a reply, not a method call.
		const landed = newMessage();
		landed[ VALUE ] = {
			name: 'dump_url',
			payload: { last_modified: 9, requests: [ { rid: 'a' } ] },
		};
		view.fill( landed );
		expect( view.view.data ).not.toBeNull();

		selectedUrl = null;
		act( () => rerender() );
		expect( view.view.data ).toBeNull();
	} );
} );
