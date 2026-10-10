/**
 * useGyroscopeGraph tests — the Gyroscope dashboard graph clipped onto the
 * substrate's canonical rule-#2 backbone (`_command_interpreter` → `_router`):
 * the `gyroscope:stream` Tee and the `gyroscope:view` node, riding the page's
 * one stream link, `_stream`.
 *
 * The page link composes its own `_stream:sse-in` (SseIn) and shares the
 * `_http` (HttpOut) and `_heartbeat` (Heartbeat) singletons, wiring the
 * `connected → slot` bridge to that heartbeat. `gyroscope:view.fill()`
 * dispatches envelopes itself, so no route or transform hop sits before it.
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic
 * (covered by the substrate's own suite) is unmocked here — we drive a `msg`
 * event through the fake EventSource and assert it routes link → Tee → view.
 * The link opens once per tick, so a test flushes the microtask before it
 * reads the fake, and each record carries the stamp the server gives it.
 * usePageVisibility is mocked to a controllable value so the visibility effect
 * is deterministic under jsdom.
 */

import { renderHook, act } from '../../../test-helpers/renderHook';
import {
	newMessage,
	pack,
	VALUE,
	KEY,
	TYPE,
	FROM,
	ID,
	TM_INFO,
	TM_STRUCT,
	Core,
	Node,
	useNodeField,
	mountExospine,
	reservedNames,
} from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useGyroscopeGraph } from '../useGyroscopeGraph';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';

// Minimal FakeEventSource — same shape as the substrate's sse_connector test.
class FakeEventSource {
	constructor( url ) {
		this.url = url;
		this.listeners = {};
		this.closed = false;
		FakeEventSource.last = this;
		FakeEventSource.instances.push( this );
	}
	addEventListener( name, cb ) {
		( this.listeners[ name ] ||= [] ).push( cb );
	}
	close() {
		this.closed = true;
	}
	dispatch( name, data ) {
		( this.listeners[ name ] || [] ).forEach( ( cb ) => cb( { data } ) );
	}
}

beforeEach( () => {
	Core.reset();
	mockPageVisible = true;
	FakeEventSource.last = null;
	FakeEventSource.instances = [];
	global.EventSource = FakeEventSource;
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
} );

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const LINK = reservedNames.STREAM;
// The FROM the server sends a gyroscope record under: the reader's stamp,
// then the producer's name.
const STAMPED = 'gyroscope.p0/request-builder';
// The page link owns a `:sse-in` + shares the reserved _http/_heartbeat.
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'gyroscope:view';
const TEE = 'gyroscope:stream';
const COMPOSED_NAMES = [ HTTP, HEARTBEAT ];
const LEASE_OWNER = '9007199254740993';

// The handle jest.setup.js issues every test's command session under.
const HARNESS_SESSION = 'e2e11111e2e22222e2e33333e2e44444';

// A `connected` envelope as a flat `KEY VALUE` string (SseInNode shape).
// The server sends its own frames FROM the reserved `_stream`.
function connectedEnvelope( { slot = 3 } = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_INFO;
	m[ FROM ] = LINK;
	m[ KEY ] = 'connected';
	const parts = [ `SESSION ${ HARNESS_SESSION }` ];
	if ( null !== slot && undefined !== slot ) {
		parts.push( `SLOT ${ slot } OWNER ${ LEASE_OWNER }` );
	}
	parts.push( 'SUBSCRIPTIONS x INTERVAL 2000' );
	m[ VALUE ] = parts.join( ' ' );
	return m;
}

// A gyroscope per-record inflight envelope as the wire delivers it:
// KEY carries the rid, the row VALUE never duplicates it.
function inflightEnvelope( request ) {
	const { rid = '', ...row } = request;
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ FROM ] = STAMPED;
	m[ KEY ] = rid;
	m[ VALUE ] = row;
	return m;
}

describe( 'useGyroscopeGraph — exospine + page link wiring', () => {
	test( 'mounts the backbone (sharing the reserved _http/_heartbeat) + the view', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		// The view sinks into the interpreter.
		expect( Core.node( VIEW ) ).toBeTruthy();
		expect( Core.node( VIEW ).sink ).toBe( interpreter );
		// The page link shares _http + _heartbeat, each sinking into it.
		for ( const name of COMPOSED_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// No link of its own: the page link's SseIn, registered for `trace`.
		expect( Core.node( 'gyroscope:link' ) ).toBeNull();
		expect( Core.node( `${ LINK }:sse-in` ) ).toBe(
			Core.node( LINK ).sseIn
		);
	} );

	test( 'the gyroscope rides the page link with its glob', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		expect( Core.node( LINK ).graphs.get( TEE ) ).toMatchObject( {
			subscribe: [ 'gyroscope.*' ],
			parked: false,
		} );
	} );

	test( 'steers flow with targets: the page link subscribes on `gyroscope` and routes to view; heartbeat → _http/workers', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		expect( FakeEventSource.last.url ).toContain( 'subscribe=gyroscope.*' );
		expect( Core.node( HEARTBEAT ).target ).toBe( `${ HTTP }/workers` );
	} );

	test( 'does not mount the retired route/transform nodes', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		expect( Core.node( 'gyroscope:route' ) ).toBeNull();
		expect( Core.node( 'gyroscope:transform' ) ).toBeNull();
	} );

	test( 'inserts an inspectable Tee on the stream edge: link → tee → view', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const interpreter = Core.node( INTERPRETER );
		const tee = Core.node( TEE );
		expect( tee ).toBeTruthy();
		expect( tee.constructor.name ).toBe( 'TeeNode' );
		expect( tee.sink ).toBe( interpreter );
		// The link routes this graph's frames to the Tee, which fans to the view.
		expect( Core.node( LINK ).graphs.has( TEE ) ).toBe( true );
		expect( tee.target ).toEqual( [ VIEW ] );
	} );

	test( 'fans the live stream to a debug-overlay watcher without disturbing the view', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const watcher = new Node();
		watcher.name = 'watcher';
		const seen = [];
		watcher.fill = ( m ) => seen.push( m[ KEY ] );
		Core.node( TEE ).connectNode( 'watcher' );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					inflightEnvelope( {
						rid: 'watched',
						url: '/x',
						state: 'process',
					} )
				)
			);
		} );
		// The watcher saw the raw stream AND the view accumulated the request.
		expect( seen ).toContain( 'watched' );
		expect( Core.node( VIEW ).requests.has( 'watched' ) ).toBe( true );
	} );

	test( 'opens an EventSource against /messages/stream?subscribe=gyroscope.* when visible', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		expect( FakeEventSource.last ).toBeTruthy();
		expect( FakeEventSource.last.url ).toBe(
			'/wp-json/newspack-nodes/v1/messages/stream?subscribe=gyroscope.*&_wpnonce=NONCE&session=e2e11111e2e22222e2e33333e2e44444&stream=_stream%3Asse-in'
		);
	} );

	test( 'does not open an EventSource on mount when the page is hidden', async () => {
		mockPageVisible = false;
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		expect( FakeEventSource.last ).toBeNull();
	} );

	test( 'the composed HttpOut defaults its transport on the first POST', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const http = Core.node( HTTP );
		// HttpOut defaults its transport on the first POST, so nothing is
		// wired until something is sent — which is what makes a palette drop
		// need no nonce threaded through construction.
		installFakeCommandWire( () => undefined );
		http.fill( newMessage() );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useGyroscopeGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { slot: 5 } ) )
			);
		} );
		const heartbeat = Core.node( HEARTBEAT );
		expect( heartbeat.slot ).toBe( 5 );
	} );

	test( 'a `connected` envelope with no slot leaves heartbeat slot null', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		expectConsoleWarn(
			'ERROR: SseInNode: connected envelope missing or invalid SLOT'
		);
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { slot: null } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'the Router TIMER drives heartbeat.fire (via notify_timer) so the slot keep-alive actually fires', async () => {
		// The page link opens on a microtask; only the clock is faked.
		jest.useFakeTimers( { doNotFake: [ 'queueMicrotask' ] } );
		try {
			renderHook( () => useGyroscopeGraph() );
			await act( async () => {} );
			const http = Core.node( HTTP );
			const postBatch = jest.fn().mockResolvedValue( [] );
			http.client = { buildMessage: () => newMessage(), postBatch };
			act( () => {
				FakeEventSource.last.dispatch(
					'connected',
					pack( connectedEnvelope( { slot: 5 } ) )
				);
			} );
			act( () => {
				jest.advanceTimersByTime( 5000 );
			} );
			expect( Core.node( HEARTBEAT ).lastFireTime ).toBeGreaterThan( 0 );
			expect( postBatch ).toHaveBeenCalled();
		} finally {
			jest.useRealTimers();
		}
	} );
} );

describe( 'useGyroscopeGraph — end-to-end routing through the exospine', () => {
	test( 'an inflight envelope from the EventSource flows into gyroscope:view', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					inflightEnvelope( {
						rid: 'r-flow',
						url: '/x',
						state: 'process',
					} )
				)
			);
		} );
		const view = Core.node( VIEW );
		expect( view.requests.has( 'r-flow' ) ).toBe( true );
	} );

	test( 'a completion envelope flows through transform into gyroscope:view', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					( () => {
						const m = newMessage();
						m[ TYPE ] = TM_STRUCT;
						m[ FROM ] = STAMPED;
						m[ KEY ] = 'rid-done';
						m[ VALUE ] = {
							state: 'complete',
							url: '/done',
							duration_ms: 42,
						};
						return m;
					} )()
				)
			);
		} );
		const view = Core.node( VIEW );
		expect( view.requests.get( 'rid-done' ).state ).toBe( 'complete' );
	} );
} );

describe( 'useGyroscopeGraph — skipped lines', () => {
	test( "the server's skipped-line frame lands on the page link by stamp", async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		// Lines skipped on a dir this graph carries, and on one it does not.
		const frame = newMessage();
		frame[ TYPE ] = TM_INFO;
		frame[ FROM ] = LINK;
		frame[ KEY ] = 'unparseable_lines';
		frame[ VALUE ] = 'COUNT 8 COUNTS gyroscope.p0=3,errors.p0=5';
		act( () => {
			FakeEventSource.last.dispatch( 'unparseable_lines', pack( frame ) );
		} );
		expect( Core.node( LINK ).unparseableByStamp ).toEqual( {
			'gyroscope.p0': 3,
			'errors.p0': 5,
		} );
	} );
} );

describe( 'useGyroscopeGraph — page visibility lifecycle', () => {
	test( 'hiding the page closes the EventSource AND clears the heartbeat slot', async () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { slot: 5 } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBe( 5 );
		const beforeHide = FakeEventSource.last;
		mockPageVisible = false;
		await act( async () => rerender( { n: 1 } ) );
		expect( beforeHide.closed ).toBe( true );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'showing the page reopens the EventSource', async () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		mockPageVisible = false;
		await act( async () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		await act( async () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBe( before + 1 );
	} );

	test( 'reopening on refocus RESUMES from the last streamed offset (carries &positions=), not a blind tail', async () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		// A tailed record: segment:offset:length in ID, the dir's stamp in FROM.
		const rec = inflightEnvelope( { rid: 'r1' } );
		rec[ ID ] = '1:64:20';
		act( () => {
			FakeEventSource.last.dispatch( 'msg', pack( rec ) );
		} );
		mockPageVisible = false;
		await act( async () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		await act( async () => rerender( { n: 2 } ) );
		const url = FakeEventSource.last.url;
		expect( url ).toContain( 'positions=' );
		const positions = JSON.parse(
			decodeURIComponent(
				url.split( 'positions=' )[ 1 ].split( '&' )[ 0 ]
			)
		);
		expect( positions ).toEqual( {
			'gyroscope.p0': { segment: 1, offset: 64 + 20 },
		} );
	} );

	test( 'resets the view map when the graph rides again on refocus', async () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		// Seed the map with an in-flight request.
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					inflightEnvelope( {
						rid: 'old',
						url: '/x',
						state: 'process',
					} )
				)
			);
		} );
		expect( Core.node( VIEW ).requests.size ).toBe( 1 );
		// Hide then show → the re-subscribe path clears the map first.
		mockPageVisible = false;
		await act( async () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		await act( async () => rerender( { n: 2 } ) );
		expect( Core.node( VIEW ).requests.size ).toBe( 0 );
	} );
} );

describe( 'useGyroscopeGraph — teardown', () => {
	test( 'unmount tears down the page link + the backbone and closes the EventSource', async () => {
		const { unmount } = renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const sourceAtMount = FakeEventSource.last;
		unmount();
		// The ROUTER is the page's heartbeat and is never torn down.
		for ( const name of [ ...COMPOSED_NAMES, LINK, VIEW, INTERPRETER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
		expect( sourceAtMount.closed ).toBe( true );
	} );

	test( 'late envelopes after unmount do not throw', async () => {
		const { unmount } = renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const source = FakeEventSource.last;
		unmount();
		expect( () =>
			source.dispatch(
				'msg',
				pack(
					inflightEnvelope( {
						rid: 'late',
						url: '/x',
						state: 'process',
					} )
				)
			)
		).not.toThrow();
	} );
} );

describe( 'useGyroscopeGraph — graphGeneration Reset Graph', () => {
	// Overlay owns the backbone; this dashboard is a reused mount whose
	// spine.reinit is subscribed to graphGeneration (the real Reset trigger).
	beforeEach( () => {
		mountExospine();
	} );
	test( 'a graphGeneration bump rebuilds the graph nodes fresh (backbone preserved)', async () => {
		renderHook( () => useGyroscopeGraph() );
		await act( async () => {} );
		const firstView = Core.node( VIEW );
		const firstHttp = Core.node( HTTP );
		const backbone = Core.node( INTERPRETER );
		expect( firstView ).not.toBeNull();

		await act( async () => {
			Core.bumpGraphGeneration();
		} );

		// Soft nodes rebuild fresh; backbone (incl shared `_http`) survives.
		expect( Core.node( VIEW ) ).not.toBe( firstView );
		expect( Core.node( HTTP ) ).toBe( firstHttp );
		// The rebuilt graph rides the page link on the `gyroscope` topic again.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=gyroscope.*' );
		expect( Core.node( VIEW ).sink ).toBe( Core.node( INTERPRETER ) );
		expect( Core.node( INTERPRETER ) ).toBe( backbone );
	} );

	test( 'a graphGeneration bump re-renders the consumer so useNodeField re-subscribes to the fresh view', async () => {
		const { result } = renderHook( () => {
			useGyroscopeGraph();
			return useNodeField( VIEW, 'view' );
		} );
		const firstView = Core.node( VIEW );

		await act( async () => {
			Core.bumpGraphGeneration();
		} );
		const freshView = Core.node( VIEW );
		expect( freshView ).not.toBe( firstView );

		// Fresh view publishes; consumer observes it (proves re-subscribe).
		act( () => {
			freshView.setField( 'view', { sampled: true } );
		} );
		expect( result.current ).toEqual( { sampled: true } );
	} );
} );
