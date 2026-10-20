/**
 * usePerformanceGraph tests — the Performance Dashboard data graph on the
 * substrate batched-poll toolkit (useBatchedPoll + addSliceFetcher).
 *
 * The graph:
 *   performance:timer (Timer) → performance:tee (Tee) → overview, urls, chart
 *     and categories Fetchers (each with an argsFn getter reading current React
 *     UI state) → overview:shell/_http/performance
 *   <slice>:in (Tee) → <slice>:in:current → <slice>:view
 *   url-detail:timer → url-detail:tee → url-detail, url-chart, url-categories  [on-demand]
 *   request-detail:view (RequestDetailView)                                    [on-demand]
 *
 * overview, urls and the two charts are POLLED (on the Timer, live args via
 * the getters); the URL modal's three slices and dump_request are ON-DEMAND
 * (modal-open → fetch). The verbs a
 * click drives are NOT here — they live beside the state their replies set, and
 * are covered where they live.
 */

import { createElement, useEffect } from 'react';
import {
	renderHook,
	renderComponent,
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
import storedAnswers from '../../../../includes/app/stored-answers.json';
import { DEFAULT_CHART_BREAKDOWN } from '../../constants';
import { activeChart } from '../../chartSlots';
import { nextTask as oneTask } from '@newspack-nodes/shared/test-utils/nextTask';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';

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

// An ask made outside a Router tick leaves on the next task, on either clock.
const nextTask = () => act( oneTask );

// Every verb here rides the router tick, so a dispatch takes a second rather
// than a microtask; jest's 5s default is not enough for a test that awaits
// more than a couple of them.
jest.setTimeout( 20000 );

// The graph POSTS on mount, so every test needs a wire — a test that wants a
// specific reply installs its own over this one.
beforeEach( () => {
	Core.reset();
	// Every ask counted here is one a test asked for.
	holdRouterCadence();
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

/**
 * The URL modal's next refresh: its Timer reaches a grid boundary, and the
 * Router's tick fires it. Opening the modal fires that Timer at once, so the
 * grid's next boundary is a whole interval away.
 */
function refreshTick() {
	Core.node( 'url-detail:timer' ).markDue();
	Core.node( ROUTER ).fireCb();
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
			'chart:view',
			'categories:view',
			'url-detail:view',
			'url-chart:view',
			'url-categories:view',
			'request-detail:view',
		] ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// No transform: each answer reaches its view whole.
		expect( Core.node( 'url-detail:transform' ) ).toBeNull();
		// The receiver fans back to the Fetcher, which settles the ask.
		// Without it the refresh asks once and never again: the outbox holds
		// an ask that nothing answers until the fail-open window.
		expect( Core.node( 'url-detail:in' ).target ).toEqual( [
			'url-detail:in:current',
			'url-detail:fetch',
		] );
		// Every slice gates its edge on the Fetcher's outbox.
		expect( Core.node( 'url-detail:in:current' ).target ).toEqual( [
			'url-detail:view',
		] );
		// The modal's Timer fans to its three Fetchers through one Tee.
		expect( Core.node( 'url-detail:tee' ).target ).toEqual( [
			'url-detail:fetch',
			'url-chart:fetch',
			'url-categories:fetch',
		] );
		expect( Core.node( 'urls:in' ).target ).toEqual( [
			'urls:in:current',
			'urls:fetch',
		] );
		expect( Core.node( 'urls:in:current' ).fetcher ).toBe( 'urls:fetch' );
		// Every name is `<subject>:<role>`; no other spelling is registered.
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

	test( 'never re-asks a stats slice: an unanswered ask lapses and the next poll asks', () => {
		installWire();
		renderHook( () => usePerformanceGraph() );
		for ( const fetcher of [
			'overview:fetch',
			'urls:fetch',
			'chart:fetch',
			'categories:fetch',
			'url-detail:fetch',
			'url-chart:fetch',
			'url-categories:fetch',
		] ) {
			expect( Core.node( fetcher ).retry_after_s ).toBe( 0 );
		}
		expect( Core.node( 'request-detail:fetch' ).retry_after_s ).toBe( 15 );
	} );

	test( "routes each slice through its family's Tap, all three declared by the poll", () => {
		installWire();
		renderHook( () => usePerformanceGraph() );
		const interpreter = Core.node( INTERPRETER );
		for ( const [ fetcher, group ] of [
			[ 'overview:fetch', 'overview' ],
			[ 'urls:fetch', 'overview' ],
			[ 'url-detail:fetch', 'url' ],
			[ 'request-detail:fetch', 'request' ],
		] ) {
			expect( Core.node( fetcher ).target ).toBe(
				`${ group }:shell/_http/performance`
			);
			expect( Core.node( `${ group }:shell` ).sink ).toBe( interpreter );
		}
		expect( Core.node( '_shell' ).counter ).toBe( 0 );
	} );

	test( 'builds the on-demand detail nodes through an interpreter that never registered their names', () => {
		// ADR-16: the name map is a per-bundle static, so a station tab building
		// this graph through ITS interpreter resolves none of these names.
		// Emptying the map is what that looks like from in here.
		const saved = {};
		for ( const name of [
			'QueryView',
			'UrlDetailView',
			'RequestDetailView',
		] ) {
			saved[ name ] = CommandInterpreterNode.includeNodes[ name ];
			delete CommandInterpreterNode.includeNodes[ name ];
		}
		try {
			installWire();
			renderHook( () => usePerformanceGraph() );
			expect( Core.node( 'chart:view' ) ).toBeTruthy();
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
		installWire( { overview: { totals: { requests: 7 } } } );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );
		const view = Core.node( 'overview:view' );
		expect( view.view.data ).toEqual( { totals: { requests: 7 } } );
	} );

	test( 'a urls reply lands in the urls:view slice (data + totals)', async () => {
		installWire( {
			urls: {
				data: [ { hash: 'a' } ],
				totals: { urls: 12, requests: 340 },
				limit: 100,
				offset: 200,
			},
		} );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );
		const view = Core.node( 'urls:view' );
		expect( view.view ).toEqual( {
			data: [ { hash: 'a' } ],
			totals: { urls: 12, requests: 340 },
			rows: 0,
			offset: 200,
			slowest: [],
			filters: null,
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
					? { totals: { requests: 7317 } }
					: { data: [ { hash: 'beef7317' } ], totals: { urls: 1 } };
			}
			return new Promise( ( resolve ) =>
				held.push( () =>
					resolve(
						'overview' === name
							? { totals: { requests: 4471 } }
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
			totals: { requests: 7317 },
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
	 * A server change is a new set, asked from its first page. The URL table
	 * reports its own return to offset 0 in a child effect, which runs before
	 * this hook's poke, and the two together put ONE urls ask on the wire.
	 */
	test( 'a server change from page 3 asks urls once, at offset 0', async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		const base = {
			search: '',
			sort: 'count',
			order: 'desc',
			includeWorkers: false,
			bucket: '',
		};
		function Table( { onParamsChange, offset } ) {
			useEffect( () => {
				onParamsChange( { ...base, offset } );
			}, [ onParamsChange, offset ] );
			return null;
		}
		function Page( { serverFilter, offset } ) {
			const { handleUrlParamsChange } = usePerformanceGraph( {
				serverFilter,
			} );
			return createElement( Table, {
				onParamsChange: handleUrlParamsChange,
				offset,
			} );
		}
		const { rerender, unmount } = renderComponent(
			createElement( Page, { serverFilter: '', offset: 200 } )
		);
		await act( async () => {} );
		const before = countVerbs( wire.batches, 'urls' );

		await act( async () => {
			rerender(
				createElement( Page, { serverFilter: 'web5', offset: 0 } )
			);
		} );

		const asked = wire.batches
			.flat()
			.filter( ( m ) => 'urls' === m[ VALUE ]?.name )
			.slice( before )
			.map( ( m ) => [
				option( m[ VALUE ].arguments, 'server' ),
				option( m[ VALUE ].arguments, 'offset' ),
			] );
		expect( asked ).toEqual( [ [ 'web5', undefined ] ] );
		unmount();
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
		await nextTask();

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
			dump_url: { stats: {}, requests: [] },
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
			stats: {},
			requests: [],
		} );
	} );

	// No view is both minter and reply sink: dump_request goes out FROM a
	// receiver, never `request-detail:view`, so one node never carries two
	// protocols — its own controls and a command reply. Every slice mints from
	// a receiver and forwards to its view.
	test( 'dump_request is asked through its Fetcher, answered at the receiver', async () => {
		const wire = installWire( { dump_request: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'r1' } );
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
			rerender( { selectedRequest: 'kea4471' } );
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
			rerender( { selectedRequest: 'kea4471' } );
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
			rerender( { selectedRequest: 'kea4471' } );
		} );
		await nextTask();
		await act( async () => {
			rerender( { selectedRequest: 'kahu9932' } );
		} );
		await nextTask();
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
			rerender( { selectedRequest: 'kea4471' } );
		} );
		expect( Core.node( 'request-detail:view' ).view.data ).toEqual( {
			rid: 'kea4471',
		} );

		await act( async () => {
			rerender( { selectedRequest: 'kahu9932' } );
		} );

		const view = Core.node( 'request-detail:view' ).view;
		expect( view.loading ).toBe( true );
		expect( view.data ).not.toEqual( { rid: 'kea4471' } );
	} );

	test( 'selecting a request fires dump_request with its rid alone', async () => {
		const wire = installWire( { dump_request: { rid: 'r1' } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedRequest: 'rid-ruru-6630' } );
		} );
		const req = findVerb( wire.batches, 'dump_request' );
		expect( req ).toBeTruthy();
		expect( req[ VALUE ].arguments ).toEqual( [ 'rid-ruru-6630' ] );
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
		await nextTask();
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
		jest.advanceTimersByTime( 299 );
		// Past the task an undebounced ask would have left in.
		await nextTask();
		expect( countVerbs( wire.batches, 'urls' ) ).toBe( before );
		jest.advanceTimersByTime( 1 );
		await nextTask();
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
		await nextTask();

		const sent = wire.batches
			.flat()
			.filter( ( m ) => m[ VALUE ]?.name === 'urls' );
		const asked = sent[ sent.length - 1 ];
		expect( option( asked[ VALUE ].arguments, 'search' ) ).toBe( search );
		expect( asked[ FROM ] ).toBe( 'urls:in' );
		unmount();
		jest.useRealTimers();
	} );
} );

describe( 'usePerformanceGraph — the chips ride urls and dump_url', () => {
	const urlsSent = ( wire ) =>
		wire.batches.flat().filter( ( m ) => m[ VALUE ]?.name === 'urls' );

	test( "the Overview chips ride the urls question, one --filter each, spelled as the chart's", async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		renderHook( () =>
			usePerformanceGraph( {
				chartChips: [ 'status_class=5', 'country=Kiribati' ],
			} )
		);
		await act( async () => {} );

		const urls = findVerb( wire.batches, 'urls' );
		expect( options( urls[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'status_class=5',
			'country=Kiribati',
		] );
		expect(
			options(
				queryFrom( wire.batches, 'chart:in' )[ VALUE ].arguments,
				'filter'
			).slice( 1 )
		).toEqual( options( urls[ VALUE ].arguments, 'filter' ) );
	} );

	test( 'no chip sends no --filter, so the default question stays the stored one', async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );

		expect(
			options(
				findVerb( wire.batches, 'urls' )[ VALUE ].arguments,
				'filter'
			)
		).toEqual( [] );
	} );

	test( 'a chip change asks urls afresh from its first page, and the chart beside it', async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		const { rerender, getApi } = renderGraph( { chartChips: [] } );
		await act( async () => {} );
		const base = { search: '', sort: 'avg_ms', order: 'asc', offset: 400 };
		await act( async () => {
			getApi().handleUrlParamsChange( base );
		} );
		await nextTask();
		wire.batches.length = 0;

		await act( async () => {
			rerender( { chartChips: [ 'method=PATCH' ] } );
		} );
		await nextTask();

		const asked = urlsSent( wire );
		expect( asked ).toHaveLength( 1 );
		expect( options( asked[ 0 ][ VALUE ].arguments, 'filter' ) ).toEqual( [
			'method=PATCH',
		] );
		expect(
			option( asked[ 0 ][ VALUE ].arguments, 'offset' )
		).toBeUndefined();
		expect( option( asked[ 0 ][ VALUE ].arguments, 'sort' ) ).toBe(
			'avg_ms'
		);
		expect(
			options(
				queryFrom( wire.batches, 'chart:in' )[ VALUE ].arguments,
				'filter'
			)
		).toEqual( [ 'worker=0', 'method=PATCH' ] );

		// The table's own report of its first page then asks nothing more.
		await act( async () => {
			getApi().handleUrlParamsChange( { ...base, offset: 0 } );
		} );
		await nextTask();
		expect( urlsSent( wire ) ).toHaveLength( 1 );
	} );

	test( 'a dimension change asks the chart alone, never urls', async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		const chips = [ 'country=Tuvalu' ];
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { chartBreakdown: 'ua', chartChips: chips },
		} );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			rerender( { chartBreakdown: 'ja4', chartChips: chips } );
		} );
		await nextTask();

		expect( queryFrom( wire.batches, 'chart:in' ) ).toBeTruthy();
		expect( urlsSent( wire ) ).toEqual( [] );
	} );

	test( 'the detail chips ride dump_url, its refresh, and its chart', async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'beef5512' },
				detailChart: {
					breakdown: 'method',
					thenBy: '',
					chips: [ 'status_class=5', 'country=Kiribati' ],
				},
			} );
		} );

		const opened = findVerb( wire.batches, 'dump_url' );
		expect( positional( opened[ VALUE ].arguments ) ).toEqual( [
			'beef5512',
		] );
		expect( options( opened[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'status_class=5',
			'country=Kiribati',
		] );

		wire.batches.length = 0;
		await act( async () => {
			refreshTick();
		} );
		expect(
			options(
				findVerb( wire.batches, 'dump_url' )[ VALUE ].arguments,
				'filter'
			)
		).toEqual( [ 'status_class=5', 'country=Kiribati' ] );
	} );

	test( 'opening a URL with its chart question asks each slice once', async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '60000' },
		} );
		await act( async () => {} );
		wire.batches.length = 0;

		// The commit an open makes: a selection and a fresh chart question.
		await act( async () => {
			rerender( {
				refreshInterval: '60000',
				selectedUrl: { hash: 'beef6631' },
				detailChart: {
					breakdown: 'status_class',
					thenBy: '',
					chips: [ 'country=Kiribati' ],
				},
			} );
		} );

		const from = ( receiver ) =>
			wire.batches
				.flat()
				.filter(
					( m ) =>
						'query' === m[ VALUE ]?.name && receiver === m[ FROM ]
				);
		expect( from( 'url-chart:in' ) ).toHaveLength( 1 );
		expect( from( 'url-categories:in' ) ).toHaveLength( 1 );
		expect( countVerbs( wire.batches, 'dump_url' ) ).toBe( 1 );
	} );

	test( 'a bucket change asks the list alone, and a dimension change the chart alone', async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		const selectedUrl = { hash: 'beef6631' };
		const chips = [ 'country=Kiribati' ];
		const chartOf = ( breakdown ) => ( { breakdown, thenBy: '', chips } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: chartOf( 'method' ),
			},
		} );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			rerender( {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: chartOf( 'method' ),
				detailFilters: { bucket: '2026-10-04-13-35' },
			} );
		} );
		await nextTask();
		expect( countVerbs( wire.batches, 'dump_url' ) ).toBe( 1 );
		expect( queryFrom( wire.batches, 'url-chart:in' ) ).toBeNull();

		wire.batches.length = 0;
		await act( async () => {
			rerender( {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: chartOf( 'ua' ),
				detailFilters: { bucket: '2026-10-04-13-35' },
			} );
		} );
		await nextTask();
		expect( countVerbs( wire.batches, 'dump_url' ) ).toBe( 0 );
		expect(
			positional(
				queryFrom( wire.batches, 'url-chart:in' )[ VALUE ].arguments
			)
		).toEqual( [ 'requests', 'bucket', 'ua' ] );
	} );

	test( 'a detail chip change asks dump_url and the URL chart, keeping the list up, and not the categories', async () => {
		const wire = installWire( {
			dump_url: { stats: { count: 5512 }, requests: [] },
		} );
		const selectedUrl = { hash: 'beef5512' };
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: { breakdown: 'method', thenBy: '', chips: [] },
			},
		} );
		await act( async () => {} );
		const seen = [];
		const view = Core.node( 'url-detail:view' );
		const fill = view.fill.bind( view );
		view.fill = ( m ) => {
			if ( m[ VALUE ]?.action ) {
				seen.push( m[ VALUE ] );
			}
			fill( m );
		};
		wire.batches.length = 0;

		await act( async () => {
			rerender( {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: {
					breakdown: 'method',
					thenBy: '',
					chips: [ 'status_class=Unknown' ],
				},
			} );
		} );
		await nextTask();

		expect(
			options(
				findVerb( wire.batches, 'dump_url' )[ VALUE ].arguments,
				'filter'
			)
		).toEqual( [ 'status_class=Unknown' ] );
		expect(
			options(
				queryFrom( wire.batches, 'url-chart:in' )[ VALUE ].arguments,
				'filter'
			)
		).toEqual( [ 'url=beef5512', 'status_class=Unknown' ] );
		expect( queryFrom( wire.batches, 'url-categories:in' ) ).toBeNull();
		// Loading, never cleared: the header stays up until the answer lands.
		expect( seen ).toEqual( [ { action: 'loading' } ] );
	} );

	test( 'a reply to the dump_url ask a chip change superseded never lands', async () => {
		const held = [];
		installFakeCommandWire( ( m ) => {
			if ( 'dump_url' !== m[ VALUE ]?.name ) {
				return null;
			}
			if ( ! options( m[ VALUE ].arguments, 'filter' ).length ) {
				return new Promise( ( resolve ) => held.push( resolve ) );
			}
			return {
				stats: {},
				requests: [ { rid: 'kotare-504', timestamp: 1787000300 } ],
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
		await nextTask();
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'f00d7731' },
				detailChart: {
					breakdown: 'status_class',
					thenBy: '',
					chips: [ 'status_class=5' ],
				},
			} );
		} );
		await nextTask();
		await act( async () => {
			held.forEach( ( resolve ) =>
				resolve( {
					stats: {},
					requests: [ { rid: 'clean-1', timestamp: 1787000900 } ],
				} )
			);
		} );

		expect(
			Core.node( 'url-detail:view' ).view.data.requests.map(
				( r ) => r.rid
			)
		).toEqual( [ 'kotare-504' ] );
	} );
} );

describe( 'usePerformanceGraph — the bucket selection', () => {
	// Two runs, as the address bar and `--bucket` carry them.
	const RUNS = '2026-10-04-13-40..2026-10-04-13-50,2026-10-04-14-15';

	test( 'asks urls for the selection by its spelling, and again when only it moves', async () => {
		const wire = installWire( { urls: { data: [], totals: { urls: 0 } } } );
		let api;
		renderHook( () => {
			api = usePerformanceGraph();
			return api;
		} );
		await act( async () => {} );
		const base = {
			search: '',
			sort: 'count',
			order: 'desc',
			offset: 0,
			bucket: '2026-10-04-13-35',
		};
		api.handleUrlParamsChange( base );
		await nextTask();
		const asked = () =>
			wire.batches.flat().filter( ( m ) => m[ VALUE ]?.name === 'urls' );
		expect( option( asked().at( -1 )[ VALUE ].arguments, 'bucket' ) ).toBe(
			'2026-10-04-13-35'
		);
		const before = asked().length;

		api.handleUrlParamsChange( { ...base, bucket: RUNS } );
		await nextTask();

		expect( asked() ).toHaveLength( before + 1 );
		expect( option( asked().at( -1 )[ VALUE ].arguments, 'bucket' ) ).toBe(
			RUNS
		);

		api.handleUrlParamsChange( { ...base, bucket: '' } );
		await nextTask();
		expect(
			option( asked().at( -1 )[ VALUE ].arguments, 'bucket' )
		).toBeUndefined();
	} );

	test( 'asks dump_url for the modal selection, and again on a change', async () => {
		const wire = installWire( {
			dump_url: { stats: {}, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'beef4471' },
				detailFilters: {
					bucket: '2026-10-04-13-35',
				},
			} );
		} );
		await nextTask();
		const opened = findVerb( wire.batches, 'dump_url' );
		expect( option( opened[ VALUE ].arguments, 'bucket' ) ).toBe(
			'2026-10-04-13-35'
		);

		wire.batches.length = 0;
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				selectedUrl: { hash: 'beef4471' },
				detailFilters: {
					bucket: RUNS,
				},
			} );
		} );
		await nextTask();
		const moved = findVerb( wire.batches, 'dump_url' );
		expect( option( moved[ VALUE ].arguments, 'bucket' ) ).toBe( RUNS );

		wire.batches.length = 0;
		await act( async () => {
			refreshTick();
		} );
		const refreshed = findVerb( wire.batches, 'dump_url' );
		expect( option( refreshed[ VALUE ].arguments, 'bucket' ) ).toBe( RUNS );
	} );
} );

describe( 'usePerformanceGraph — timer suspension on modal open / tab visibility', () => {
	test( 'pauses performance:timer while a URL detail is open, re-arms when it closes', async () => {
		installWire( {
			dump_url: { stats: {}, requests: [] },
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
			dump_url: { stats: {}, requests: [] },
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
			refreshTick();
		} );
		const detail = findVerb( wire.batches, 'dump_url' );
		expect( detail ).toBeTruthy();
		expect( detail[ VALUE ].arguments[ 0 ] ).toBe( 'abc' );
	} );

	// The arguments ARE the question, so the ask needs no subject to name it.
	test( 'the open fetch asks through url-detail:fetch, addressed bare', async () => {
		const wire = installWire( {
			dump_url: { stats: {}, requests: [] },
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

	// A refusal of the modal's ask goes to its view.
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
			dump_url: { stats: {}, requests: [] },
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
			dump_url: { stats: {}, requests: [] },
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
			dump_url: { stats: {}, requests: [] },
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

	// A minute's cadence: no grid boundary falls inside the test.
	test( 'a hidden URL modal shown again asks dump_url at once', async () => {
		const wire = installWire( {
			dump_url: { stats: {}, requests: [] },
		} );
		const props = {
			refreshInterval: '60000',
			selectedUrl: { hash: 'abc' },
		};
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '60000' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( props );
		} );
		await act( async () => setVisibility( 'hidden' ) );
		wire.batches.length = 0;

		await act( async () => setVisibility( 'visible' ) );

		expect( countVerbs( wire.batches, 'dump_url' ) ).toBe( 1 );
		expect(
			findVerb( wire.batches, 'dump_url' )[ VALUE ].arguments[ 0 ]
		).toBe( 'abc' );
	} );

	test( 'Reset Graph with the URL modal open re-arms its Timer at the interval, not every tick', async () => {
		const wire = installWire( {
			dump_url: { stats: {}, requests: [] },
		} );
		const props = {
			refreshInterval: '60000',
			selectedUrl: { hash: 'abc' },
		};
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '60000' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( props );
		} );

		await act( async () => Core.bumpGraphGeneration() );
		wire.batches.length = 0;
		for ( let i = 0; i < 5; i++ ) {
			await act( async () => Core.node( ROUTER ).fireCb() );
		}

		expect( Core.node( 'url-detail:timer' ).interval_ms ).toBe( 60000 );
		expect( Core.node( 'request-detail:timer' ).mode ).toBe( 'inactive' );
		expect( countVerbs( wire.batches, 'dump_url' ) ).toBeLessThanOrEqual(
			1
		);
	} );

	test( 'a cadence change while the URL modal is open asks no extra dump_url', async () => {
		const wire = installWire( {
			dump_url: { stats: {}, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '60000' },
		} );
		await act( async () => {} );
		// One selection object, as the dashboard's state holds it.
		const selectedUrl = { hash: 'abc' };
		await act( async () => {
			rerender( { refreshInterval: '60000', selectedUrl } );
		} );
		wire.batches.length = 0;

		await act( async () => {
			rerender( { refreshInterval: '120000', selectedUrl } );
		} );
		await nextTask();

		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );
		expect( Core.node( 'url-detail:timer' ).interval_ms ).toBe( 120000 );
		expect( countVerbs( wire.batches, 'dump_url' ) ).toBe( 0 );
	} );

	test( 'closing the URL modal stops the url-detail:timer', async () => {
		installWire( {
			dump_url: { stats: {}, requests: [] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {},
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( { selectedUrl: { hash: 'abc' } } );
		} );
		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'router' );

		await act( async () => {
			rerender( { selectedUrl: null } );
		} );

		expect( Core.node( 'url-detail:timer' ).mode ).toBe( 'inactive' );
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
	test( 'asks overview for its server alone: the series ride query', async () => {
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				serverFilter: 'ruru.example',
				chartBreakdown: 'ja4',
			} )
		);
		await act( async () => {} );
		const overview = findVerb( wire.batches, 'overview' );
		expect( overview[ VALUE ].arguments ).toEqual( [
			'--server=ruru.example',
		] );
	} );

	test( 'the selected server is emitted in the dump_url args', async () => {
		// The modal opens from a row the server filter scoped, so it has to ask
		// the same question — otherwise one click puts the site's average under
		// that row's count.
		const wire = installWire( {
			dump_url: { stats: {}, requests: [] },
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
		// The URL table is what the Overview header counts, so the two have
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
		await nextTask();
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
				includeWorkers: false,
				bucket: '',
			} );
		} );
		await nextTask();
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
		await nextTask();
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
		await nextTask();
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
		await nextTask();
		expect( findVerb( wire.batches, 'dump_request' ) ).toBeNull();
		expect( Core.node( 'request-detail:view' ).view.error ).toBe(
			'Invalid request ID format'
		);
	} );
} );

describe( 'usePerformanceGraph — control origins', () => {
	test( 'wires controlFrom on every control-taking node', () => {
		renderHook( () => usePerformanceGraph( {} ) );
		for ( const name of [
			'overview:view',
			'urls:view',
			'chart:view',
			'categories:view',
			'url-detail:view',
			'url-chart:view',
			'url-categories:view',
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
			payload: { stats: {}, requests: [ { rid: 'a' } ] },
		};
		view.fill( landed );
		expect( view.view.data ).not.toBeNull();

		selectedUrl = null;
		act( () => rerender() );
		expect( view.view.data ).toBeNull();
	} );
} );

/**
 * The newest `query` a receiver asked, by the FROM its answer routes back to.
 *
 * @param {Array[]} batches Every POST body the wire saw.
 * @param {string}  from    The receiver, as `chart:in`.
 * @return {?Array} The message, or null when it asked none.
 */
const queryFrom = ( batches, from ) =>
	batches
		.flat()
		.filter( ( m ) => 'query' === m[ VALUE ]?.name && from === m[ FROM ] )
		.at( -1 ) ?? null;

/**
 * Every value a command's tokens carry for a repeated `--<name>=`.
 *
 * @param {string[]} tokens The command's argument tokens.
 * @param {string}   name   The arg's name.
 * @return {string[]} Its values, in order.
 */
const options = ( tokens, name ) =>
	tokens
		.filter( ( token ) => token.startsWith( `--${ name }=` ) )
		.map( ( token ) => token.slice( name.length + 3 ) );

/**
 * The positional tokens of a command: its source and its group.
 *
 * @param {string[]} tokens The command's argument tokens.
 * @return {string[]} Every token that is not an option.
 */
const positional = ( tokens ) =>
	tokens.filter( ( token ) => ! token.startsWith( '--' ) );

describe( 'usePerformanceGraph — chart questions', () => {
	test( 'asks query for the chart and no longer sends after', async () => {
		const wire = installWire( {
			dump_url: { stats: {}, requests: [ { rid: 'tui-4' } ] },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0', chartBreakdown: 'country' },
		} );
		await act( async () => {} );

		const chart = queryFrom( wire.batches, 'chart:in' );
		expect( positional( chart[ VALUE ].arguments ) ).toEqual( [
			'requests',
			'bucket',
			'country',
		] );
		expect( options( chart[ VALUE ].arguments, 'measure' ) ).toEqual( [
			'count',
			'sum_ms',
			'sum_peak_mb',
			'timed',
		] );
		expect( options( chart[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'worker=0',
		] );
		expect( option( chart[ VALUE ].arguments, 'top' ) ).toBe( '20' );
		expect( option( chart[ VALUE ].arguments, 'top_by' ) ).toBe( 'count' );

		await act( async () => {
			rerender( {
				refreshInterval: '0',
				chartBreakdown: 'country',
				selectedUrl: { hash: 'f00d9921' },
			} );
		} );
		wire.batches.length = 0;
		await act( async () => {
			refreshTick();
		} );
		const refreshed = findVerb( wire.batches, 'dump_url' );
		expect( refreshed[ VALUE ].arguments ).toEqual( [ 'f00d9921' ] );
	} );

	test( 'asks the chart by both dimensions and its chips, a chip replacing the worker scope', async () => {
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				serverFilter: 'kea.example',
				chartBreakdown: 'status_class',
				chartThenBy: 'method',
				chartChips: [ 'worker=1', 'country=NZ' ],
			} )
		);
		await act( async () => {} );

		const chart = queryFrom( wire.batches, 'chart:in' );
		expect( positional( chart[ VALUE ].arguments ) ).toEqual( [
			'requests',
			'bucket',
			'status_class',
			'method',
		] );
		expect( options( chart[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'server=kea.example',
			'worker=1',
			'country=NZ',
		] );
	} );

	test( 'lets a negated worker chip replace the worker scope', async () => {
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				chartBreakdown: 'status_class',
				chartChips: [ 'worker!=0' ],
			} )
		);
		await act( async () => {} );

		const chart = queryFrom( wire.batches, 'chart:in' );
		expect( options( chart[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'worker!=0',
		] );
	} );

	test( 'asks one dimension where Then by repeats Breakdown, on the page and in the modal', async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		renderHook( () =>
			usePerformanceGraph( {
				refreshInterval: '60000',
				chartBreakdown: 'ja4',
				chartThenBy: 'ja4',
				selectedUrl: { hash: 'beef7743' },
				detailChart: {
					breakdown: 'method',
					thenBy: 'method',
					chips: [],
				},
			} )
		);
		await act( async () => {} );

		expect(
			positional(
				queryFrom( wire.batches, 'chart:in' )[ VALUE ].arguments
			)
		).toEqual( [ 'requests', 'bucket', 'ja4' ] );
		expect(
			positional(
				queryFrom( wire.batches, 'url-chart:in' )[ VALUE ].arguments
			)
		).toEqual( [ 'requests', 'bucket', 'method' ] );
	} );

	test( 'asks the category chart for the top categories by milliseconds', async () => {
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( { serverFilter: 'egret.example' } )
		);
		await act( async () => {} );

		const cats = queryFrom( wire.batches, 'categories:in' );
		expect( positional( cats[ VALUE ].arguments ) ).toEqual( [
			'timings',
			'bucket',
			'category',
		] );
		expect( options( cats[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'worker=0',
			'server=egret.example',
			'entry=',
		] );
		expect( options( cats[ VALUE ].arguments, 'measure' ) ).toEqual( [
			'ms',
			'calls',
		] );
		expect( option( cats[ VALUE ].arguments, 'top_by' ) ).toBe( 'ms' );
		expect( option( cats[ VALUE ].arguments, 'top' ) ).toBe( '20' );
	} );

	test( 'lands each chart answer in its own view', async () => {
		const answer = {
			source: 'requests',
			table: 'requests',
			columns: [ 'bucket', 'server', 'count' ],
			rows: [ [ '2026-10-15-07-15', 'weka.example', 81 ] ],
			groups: 3,
			available: true,
			filters: { filter: {}, bucket: '', search: '' },
		};
		installWire( { query: answer } );
		renderHook( () => usePerformanceGraph() );
		await act( async () => {} );

		expect( Core.node( 'chart:view' ).view.data ).toEqual( answer );
		expect( Core.node( 'categories:view' ).view.data ).toEqual( answer );
	} );

	test( 'a dimension or chip change asks the chart afresh, and nothing else', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { chartBreakdown: 'ua', chartChips: [] },
		} );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			rerender( { chartBreakdown: 'ja4', chartChips: [] } );
		} );
		await nextTask();
		expect(
			positional(
				queryFrom( wire.batches, 'chart:in' )[ VALUE ].arguments
			)
		).toEqual( [ 'requests', 'bucket', 'ja4' ] );
		expect( findVerb( wire.batches, 'overview' ) ).toBeNull();
		expect( queryFrom( wire.batches, 'categories:in' ) ).toBeNull();

		wire.batches.length = 0;
		await act( async () => {
			rerender( { chartBreakdown: 'ja4', chartChips: [ 'method=PUT' ] } );
		} );
		await nextTask();
		expect(
			options(
				queryFrom( wire.batches, 'chart:in' )[ VALUE ].arguments,
				'filter'
			)
		).toEqual( [ 'worker=0', 'method=PUT' ] );
	} );

	test( 'opening a URL asks its stats and its two charts, each tick again, worker traffic counted', async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '0' },
		} );
		await act( async () => {} );
		wire.batches.length = 0;
		await act( async () => {
			rerender( {
				refreshInterval: '0',
				serverFilter: 'kea.example',
				selectedUrl: { hash: 'beef7743' },
				detailChart: {
					breakdown: 'method',
					thenBy: 'country',
					chips: [ 'status_class=4' ],
				},
			} );
		} );

		const chart = queryFrom( wire.batches, 'url-chart:in' );
		expect( positional( chart[ VALUE ].arguments ) ).toEqual( [
			'requests',
			'bucket',
			'method',
			'country',
		] );
		expect( options( chart[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'url=beef7743',
			'server=kea.example',
			'status_class=4',
		] );
		expect( option( chart[ VALUE ].arguments, 'top' ) ).toBe( '20' );
		const cats = queryFrom( wire.batches, 'url-categories:in' );
		expect( options( cats[ VALUE ].arguments, 'filter' ) ).toEqual( [
			'url=beef7743',
			'server=kea.example',
			'entry=',
		] );

		wire.batches.length = 0;
		await act( async () => {
			refreshTick();
		} );
		expect( findVerb( wire.batches, 'dump_url' ) ).toBeTruthy();
		expect( queryFrom( wire.batches, 'url-chart:in' ) ).toBeTruthy();
		expect( queryFrom( wire.batches, 'url-categories:in' ) ).toBeTruthy();
	} );

	test( "a modal chart's dimension change asks that chart alone", async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		const selectedUrl = { hash: 'beef7743' };
		// A fresh array each render: the modal compares the chips by value.
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: {
					breakdown: 'method',
					thenBy: '',
					chips: [ 'country=Tonga' ],
				},
			},
		} );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			rerender( {
				refreshInterval: '60000',
				selectedUrl,
				detailChart: {
					breakdown: 'ja4',
					thenBy: '',
					chips: [ 'country=Tonga' ],
				},
			} );
		} );
		await nextTask();

		expect(
			positional(
				queryFrom( wire.batches, 'url-chart:in' )[ VALUE ].arguments
			)
		).toEqual( [ 'requests', 'bucket', 'ja4' ] );
		expect( findVerb( wire.batches, 'dump_url' ) ).toBeNull();
		expect( queryFrom( wire.batches, 'url-categories:in' ) ).toBeNull();
	} );

	test( 'a bucket flip asks the list, never the charts, which describe every request', async () => {
		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		const selectedUrl = { hash: 'beef7743' };
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '60000', selectedUrl },
		} );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			rerender( {
				refreshInterval: '60000',
				selectedUrl,
				detailFilters: { bucket: '2026-10-04-13-35' },
			} );
		} );
		await nextTask();

		expect( findVerb( wire.batches, 'dump_url' ) ).toBeTruthy();
		expect( queryFrom( wire.batches, 'url-chart:in' ) ).toBeNull();
		expect( queryFrom( wire.batches, 'url-categories:in' ) ).toBeNull();
	} );

	test( 'each dump_url answer replaces the list whole', async () => {
		let replies = 0;
		installFakeCommandWire( ( m ) =>
			'dump_url' === m[ VALUE ]?.name
				? { stats: {}, requests: [ { rid: `kahu-${ ++replies }` } ] }
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
			refreshTick();
		} );

		expect( Core.node( 'url-detail:view' ).view.data.requests ).toEqual( [
			{ rid: 'kahu-2' },
		] );
	} );
} );

describe( 'usePerformanceGraph — the stored answers', () => {
	// The two `query` asks differ in their first token, the source.
	const askedOn = ( wire, verb, source ) => {
		const sent = source
			? wire.batches
					.flat()
					.find(
						( m ) =>
							verb === m[ VALUE ]?.name &&
							source === m[ VALUE ].arguments[ 0 ]
					)
			: findVerb( wire.batches, verb );
		return { verb, tokens: sent?.[ VALUE ].arguments };
	};

	/**
	 * The questions the overview-prefetch job answers are the ones a default
	 * Overview poll puts on the wire, token for token: the request chart's
	 * default breakdown resolved as the page resolves it, for a fleet that
	 * can chart by server and for one that cannot.
	 */
	test.each( [
		[ 'two servers', [ 'kea.example', 'tui.example' ], 'chart_by_server' ],
		[ 'one server', [ 'kea.example' ], 'chart_by_status_class' ],
	] )(
		'a default poll over %s asks what the job stores',
		async ( _fleet, serverNames, chart ) => {
			const { breakdown, thenBy } = activeChart( {
				breakdown: DEFAULT_CHART_BREAKDOWN,
				thenBy: '',
				serverFilter: '',
				serverNames,
			} );
			const wire = installWire();
			renderHook( () =>
				usePerformanceGraph( {
					chartBreakdown: breakdown,
					chartThenBy: thenBy,
				} )
			);
			await act( async () => {} );

			expect( Object.keys( storedAnswers ) ).toEqual( [
				'overview',
				'chart_by_server',
				'chart_by_status_class',
				'categories',
				'server_overview',
				'server_chart_by_status_class',
				'server_categories',
			] );
			expect( askedOn( wire, 'overview' ) ).toEqual(
				storedAnswers.overview
			);
			expect( askedOn( wire, 'query', 'requests' ) ).toEqual(
				storedAnswers[ chart ]
			);
			expect( askedOn( wire, 'query', 'timings' ) ).toEqual(
				storedAnswers.categories
			);
		}
	);

	test( 'a scoped poll asks what the job stores for its server', async () => {
		const serverFilter = 'kea-7030.example';
		const { breakdown, thenBy } = activeChart( {
			breakdown: DEFAULT_CHART_BREAKDOWN,
			thenBy: '',
			serverFilter,
			serverNames: [ serverFilter, 'tui-7030.example' ],
		} );
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				serverFilter,
				chartBreakdown: breakdown,
				chartThenBy: thenBy,
			} )
		);
		await act( async () => {} );
		const bound = ( { verb, tokens } ) => ( {
			verb,
			tokens: tokens.map( ( token ) =>
				token.replace( '{server}', serverFilter )
			),
		} );

		expect( askedOn( wire, 'overview' ) ).toEqual(
			bound( storedAnswers.server_overview )
		);
		expect( askedOn( wire, 'query', 'requests' ) ).toEqual(
			bound( storedAnswers.server_chart_by_status_class )
		);
		expect( askedOn( wire, 'query', 'timings' ) ).toEqual(
			bound( storedAnswers.server_categories )
		);
	} );
} );

describe( 'usePerformanceGraph — an expanded page asks its shapes', () => {
	/** A page of three shapes on one server, as a `urls` row names it. */
	const KOKAKO = {
		page_hash: 'c4f7a2e19b05',
		page: 'hoiho-5172.example/kokako-5172/',
		server: 'hoiho-5172.example',
		path: '/kokako-5172/',
		shapes: 3,
		hash: null,
	};
	const SUBJECT = 'shapes:c4f7a2e19b05:hoiho-5172.example';
	const SHAPE_ROWS = [
		{
			hash: 'e0a1c4f75172',
			url: 'https://hoiho-5172.example/Kokako-5172/',
		},
	];

	/**
	 * Every `urls` ask the named receiver sent, oldest first.
	 *
	 * @param {Object} wire The fake wire.
	 * @param {string} from The receiver the ask names as FROM.
	 * @return {string[][]} Each ask's tokens.
	 */
	const urlsFrom = ( wire, from ) =>
		wire.batches
			.flat()
			.filter(
				( m ) => 'urls' === m[ VALUE ]?.name && from === m[ FROM ]
			)
			.map( ( m ) => m[ VALUE ].arguments );

	test( 'asks the page through one slice of its own, by --page on the page server', async () => {
		const wire = installWire( {
			urls: { data: SHAPE_ROWS, rows: 1, totals: { urls: 1 } },
		} );
		renderHook( () =>
			usePerformanceGraph( {
				serverFilter: '',
				chartChips: [ 'method=PATCH' ],
				expandedPages: [ KOKAKO ],
			} )
		);
		await act( async () => {} );

		const [ asked ] = urlsFrom( wire, `${ SUBJECT }:in` );
		expect( option( asked, 'page' ) ).toBe( 'c4f7a2e19b05' );
		expect( options( asked, 'filter' ) ).toEqual( [ 'method=PATCH' ] );
		expect( option( asked, 'server' ) ).toBe( 'hoiho-5172.example' );
		expect( option( asked, 'limit' ) ).toBe( '1000' );
		expect( option( asked, 'offset' ) ).toBeUndefined();
		// The table's own question names no page.
		expect( options( urlsFrom( wire, 'urls:in' )[ 0 ], 'filter' ) ).toEqual(
			[ 'method=PATCH' ]
		);
		expect( Core.node( `${ SUBJECT }:view` ).view.data ).toEqual(
			SHAPE_ROWS
		);
		expect( Core.node( `${ SUBJECT }:fetch` ).retry_after_s ).toBe( 0 );
		expect( Core.node( 'performance:tee' ).target ).toContain(
			`${ SUBJECT }:fetch`
		);
	} );

	test( 'expanding a page asks at once, and collapsing it removes the slice', async () => {
		const wire = installWire( {
			urls: { data: SHAPE_ROWS, rows: 1, totals: { urls: 1 } },
		} );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { expandedPages: [] },
		} );
		await act( async () => {} );
		expect( Core.node( `${ SUBJECT }:fetch` ) ).toBeNull();

		await act( async () => {
			rerender( { expandedPages: [ KOKAKO ] } );
		} );
		await nextTask();
		expect( urlsFrom( wire, `${ SUBJECT }:in` ) ).toHaveLength( 1 );
		expect( Core.node( `${ SUBJECT }:view` ).view.data ).toEqual(
			SHAPE_ROWS
		);

		await act( async () => {
			rerender( { expandedPages: [] } );
		} );
		for ( const role of [ 'fetch', 'in', 'in:current', 'view' ] ) {
			expect( Core.node( `${ SUBJECT }:${ role }` ) ).toBeNull();
		}
		expect( Core.node( 'performance:tee' ).liveTargets() ).not.toContain(
			`${ SUBJECT }:fetch`
		);
	} );

	test( "asks again under the table's sort, order, search and Include Workers", async () => {
		jest.useFakeTimers();
		const wire = installWire();
		const { getApi } = renderGraph( { expandedPages: [ KOKAKO ] } );
		await act( async () => {} );
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'max_ms',
				order: 'asc',
				offset: 300,
				includeWorkers: true,
				bucket: '2026-10-04-13-35',
			} );
		} );
		await nextTask();

		const asked = urlsFrom( wire, `${ SUBJECT }:in` ).at( -1 );
		expect( option( asked, 'sort' ) ).toBe( 'max_ms' );
		expect( option( asked, 'order' ) ).toBe( 'asc' );
		expect( option( asked, 'include_workers' ) ).toBe( '1' );
		expect( option( asked, 'bucket' ) ).toBe( '2026-10-04-13-35' );
		expect( option( asked, 'offset' ) ).toBeUndefined();
		const before = urlsFrom( wire, `${ SUBJECT }:in` ).length;

		// A page turn moves the table alone.
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'max_ms',
				order: 'asc',
				offset: 400,
				includeWorkers: true,
				bucket: '2026-10-04-13-35',
			} );
		} );
		await nextTask();
		expect( urlsFrom( wire, `${ SUBJECT }:in` ) ).toHaveLength( before );

		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: 'kokako',
				sort: 'max_ms',
				order: 'asc',
				offset: 0,
				includeWorkers: true,
				bucket: '2026-10-04-13-35',
			} );
		} );
		jest.advanceTimersByTime( 300 );
		await nextTask();
		expect(
			option( urlsFrom( wire, `${ SUBJECT }:in` ).at( -1 ), 'search' )
		).toBe( 'kokako' );
		jest.useRealTimers();
	} );

	test( 'asks the window of shapes the table scrolls to, and nothing else', async () => {
		const wire = installWire();
		const { getApi } = renderGraph( {
			expandedPages: [ { ...KOKAKO, shapes: 2600 } ],
		} );
		await act( async () => {} );
		const table = {
			search: '',
			sort: 'count',
			order: 'desc',
			offset: 0,
			includeWorkers: false,
			bucket: '',
		};
		await act( async () => {
			getApi().handleUrlParamsChange( {
				...table,
				shapeOffsets: { 'c4f7a2e19b05:hoiho-5172.example': 0 },
			} );
		} );
		await nextTask();
		const shapesAsked = urlsFrom( wire, `${ SUBJECT }:in` ).length;
		const tableAsked = urlsFrom( wire, 'urls:in' ).length;

		await act( async () => {
			getApi().handleUrlParamsChange( {
				...table,
				shapeOffsets: { 'c4f7a2e19b05:hoiho-5172.example': 1500 },
			} );
		} );
		await nextTask();

		const asked = urlsFrom( wire, `${ SUBJECT }:in` );
		expect( asked ).toHaveLength( shapesAsked + 1 );
		expect( option( asked.at( -1 ), 'offset' ) ).toBe( '1500' );
		expect( option( asked.at( -1 ), 'limit' ) ).toBe( '1000' );
		expect( urlsFrom( wire, 'urls:in' ) ).toHaveLength( tableAsked );

		// A window report naming no page leaves the last one standing.
		await act( async () => {
			getApi().handleUrlParamsChange( { ...table, shapeOffsets: {} } );
		} );
		await nextTask();
		expect( urlsFrom( wire, `${ SUBJECT }:in` ) ).toHaveLength(
			shapesAsked + 1
		);
		await act( async () => {
			getApi().handleUrlParamsChange( { ...table, sort: 'max_ms' } );
		} );
		await nextTask();
		expect(
			option( urlsFrom( wire, `${ SUBJECT }:in` ).at( -1 ), 'offset' )
		).toBe( '1500' );
	} );

	test( 'a scroll inside the search debounce leaves the search ask to go', async () => {
		jest.useFakeTimers();
		const wire = installWire();
		const { getApi } = renderGraph( {
			expandedPages: [ { ...KOKAKO, shapes: 2600 } ],
		} );
		await act( async () => {} );
		const searched = {
			search: 'tieke',
			sort: 'count',
			order: 'desc',
			offset: 0,
			includeWorkers: false,
			bucket: '',
		};
		getApi().handleUrlParamsChange( searched );
		jest.advanceTimersByTime( 100 );
		getApi().handleUrlParamsChange( {
			...searched,
			shapeOffsets: { 'c4f7a2e19b05:hoiho-5172.example': 500 },
		} );
		await nextTask();
		expect(
			option( urlsFrom( wire, `${ SUBJECT }:in` ).at( -1 ), 'offset' )
		).toBe( '500' );

		jest.advanceTimersByTime( 200 );
		await nextTask();
		expect( option( urlsFrom( wire, 'urls:in' ).at( -1 ), 'search' ) ).toBe(
			'tieke'
		);
		jest.useRealTimers();
	} );

	test( 'a page shut and opened again asks its shapes from the first', async () => {
		const wire = installWire();
		const { rerender, getApi } = renderGraph( {
			expandedPages: [ { ...KOKAKO, shapes: 2600 } ],
		} );
		await act( async () => {} );
		await act( async () => {
			getApi().handleUrlParamsChange( {
				search: '',
				sort: 'count',
				order: 'desc',
				offset: 0,
				includeWorkers: false,
				bucket: '',
				shapeOffsets: { 'c4f7a2e19b05:hoiho-5172.example': 1500 },
			} );
		} );
		await nextTask();
		await act( async () => rerender( { expandedPages: [] } ) );
		await act( async () =>
			rerender( { expandedPages: [ { ...KOKAKO, shapes: 2600 } ] } )
		);
		await nextTask();

		expect(
			option( urlsFrom( wire, `${ SUBJECT }:in` ).at( -1 ), 'offset' )
		).toBeUndefined();
	} );

	test( 'a chip change asks the shapes again', async () => {
		const wire = installWire();
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { expandedPages: [ KOKAKO ] },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				expandedPages: [ KOKAKO ],
				chartChips: [ 'country=Tuvalu' ],
			} );
		} );
		await nextTask();

		expect(
			options( urlsFrom( wire, `${ SUBJECT }:in` ).at( -1 ), 'filter' )
		).toEqual( [ 'country=Tuvalu' ] );
	} );

	test( "a page chip stays off the page's own --page question", async () => {
		const wire = installWire();
		renderHook( () =>
			usePerformanceGraph( {
				chartChips: [
					'page=c4f7a2e19b05',
					'page!=9a9a9a9a9a9a',
					'method=GET',
				],
				expandedPages: [ KOKAKO ],
			} )
		);
		await act( async () => {} );

		const [ asked ] = urlsFrom( wire, `${ SUBJECT }:in` );
		expect( option( asked, 'page' ) ).toBe( 'c4f7a2e19b05' );
		expect( options( asked, 'filter' ) ).toEqual( [ 'method=GET' ] );
		expect( options( urlsFrom( wire, 'urls:in' )[ 0 ], 'filter' ) ).toEqual(
			[ 'page=c4f7a2e19b05', 'page!=9a9a9a9a9a9a', 'method=GET' ]
		);
	} );

	test( 'unmount removes every shapes slice with the graph', async () => {
		installWire();
		const { rerender, unmount } = renderHook(
			( p ) => usePerformanceGraph( p ),
			{ initialProps: { expandedPages: [] } }
		);
		await act( async () => {} );
		await act( async () => {
			rerender( { expandedPages: [ KOKAKO ] } );
		} );
		expect( Core.node( `${ SUBJECT }:view` ) ).toBeTruthy();
		unmount();

		for ( const role of [ 'fetch', 'in', 'in:current', 'view' ] ) {
			expect( Core.node( `${ SUBJECT }:${ role }` ) ).toBeNull();
		}
	} );
} );

// @longform A URL opened with no session parks its asks: nothing can sign
// them yet. The url-detail Timer stays due, so the tick the session's
// arrival asks for sends them, rather than its 120s grid holding them back.
describe( 'usePerformanceGraph — a url-detail tick with no session', () => {
	test( 'stays due, and the tick the session landing asks for sends its asks', async () => {
		installWire( { dump_url: { stats: {}, requests: [] } } );
		forgetSession();
		__setAuthFetch( async () => null );
		const { rerender } = renderHook( ( p ) => usePerformanceGraph( p ), {
			initialProps: { refreshInterval: '120000' },
		} );
		await act( async () => {} );
		await act( async () => {
			rerender( {
				refreshInterval: '120000',
				selectedUrl: { hash: 'b7e3c9a104d2' },
			} );
		} );
		const timer = Core.node( 'url-detail:timer' );
		expect( timer.fireCount ).toBe( 1 );
		expect( timer.due ).toBe( true );

		const wire = installWire( { dump_url: { stats: {}, requests: [] } } );
		forgetSession();
		await act( async () => {
			await ensureSession();
		} );
		await nextTask();

		const asked = findVerb( wire.batches, 'dump_url' );
		expect( asked ).toBeTruthy();
		expect( asked[ VALUE ].arguments[ 0 ] ).toBe( 'b7e3c9a104d2' );
	} );
} );
