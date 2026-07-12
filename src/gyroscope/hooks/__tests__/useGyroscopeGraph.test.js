/**
 * useGyroscopeGraph tests — the Gyroscope dashboard graph clips onto the
 * substrate's canonical rule-#2 backbone (`_command_interpreter` → `_router`)
 * via a SINGLE `RemoteLink` node plus a single `gyroscope:view` node.
 *
 * RemoteLink composes the three I/O children every SSE dashboard used to wire by
 * hand — `gyroscope:link:sse-in` (SseIn), `gyroscope:link:http` (HttpOut) and
 * `gyroscope:link:heartbeat` (Heartbeat) — and wires the `connected → slot`
 * bridge to its own heartbeat. The bespoke `gyroscope:route` / `gyroscope:transform`
 * hops were collapsed into `gyroscope:view.fill()` directly (route was dead,
 * transform was an envelope-shape dispatcher the view can do itself).
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic
 * (already covered by the substrate's `sse_connector.test.js`) is unmocked here
 * — we drive a `msg` event through the fake EventSource and assert it actually
 * routes the composed sse-in → view. usePageVisibility is mocked to a
 * controllable value so the visibility effect is deterministic under jsdom.
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
	useNodeState,
} from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useGyroscopeGraph } from '../useGyroscopeGraph';

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
const LINK = 'gyroscope:link';
// RemoteLink has an unnamed SseIn + shares the reserved _http/_heartbeat.
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'gyroscope:view';
const TEE = 'gyroscope:stream';
const COMPOSED_NAMES = [ HTTP, HEARTBEAT ];

// A `connected` envelope as a flat `KEY VALUE` string (SseInNode shape).
function connectedEnvelope( { pid = 4242, slot = 3 } = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_INFO;
	m[ KEY ] = 'connected';
	const parts = [ `PID ${ pid }` ];
	if ( null !== slot && undefined !== slot ) {
		parts.push( `SLOT ${ slot }` );
	}
	parts.push( 'SUBSCRIPTIONS x INTERVAL 2000' );
	m[ VALUE ] = parts.join( ' ' );
	return m;
}

// A gyroscope inflight-snapshot envelope as the wire delivers it.
function inflightEnvelope( requests ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = 'inflight';
	m[ VALUE ] = requests;
	return m;
}

describe( 'useGyroscopeGraph — exospine + RemoteLink wiring', () => {
	test( 'mounts the backbone + one RemoteLink (sharing the reserved _http/_heartbeat) + the view', () => {
		renderHook( () => useGyroscopeGraph() );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		// The view sinks into the interpreter.
		expect( Core.node( VIEW ) ).toBeTruthy();
		expect( Core.node( VIEW ).sink ).toBe( interpreter );
		// RemoteLink shares _http + _heartbeat, each sinking into interpreter.
		for ( const name of COMPOSED_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// The per-link SseIn name is no longer registered.
		expect( Core.node( 'gyroscope:link:sse-in' ) ).toBeNull();
	} );

	test( 'steers flow with targets: the unnamed sse-in subscribes on `gyroscope` and routes to view; heartbeat → _http/workers', () => {
		renderHook( () => useGyroscopeGraph() );
		// The unnamed SseIn opened against the `gyroscope` subscribe topic.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=gyroscope.*' );
		expect( Core.node( HEARTBEAT ).target ).toBe( `${ HTTP }/workers` );
	} );

	test( 'does not mount the retired route/transform nodes', () => {
		renderHook( () => useGyroscopeGraph() );
		expect( Core.node( 'gyroscope:route' ) ).toBeNull();
		expect( Core.node( 'gyroscope:transform' ) ).toBeNull();
	} );

	test( 'inserts an inspectable Tee on the stream edge: link → tee → view', () => {
		renderHook( () => useGyroscopeGraph() );
		const interpreter = Core.node( INTERPRETER );
		const tee = Core.node( TEE );
		expect( tee ).toBeTruthy();
		expect( tee.constructor.name ).toBe( 'TeeNode' );
		expect( tee.sink ).toBe( interpreter );
		// The link re-homes received frames to the Tee, which fans to the view.
		expect( Core.node( LINK ).sseIn.target ).toBe( TEE );
		expect( tee.target ).toEqual( [ VIEW ] );
	} );

	test( 'fans the live stream to a debug-overlay watcher without disturbing the view', () => {
		renderHook( () => useGyroscopeGraph() );
		const watcher = new Node();
		watcher.name = 'watcher';
		const seen = [];
		watcher.fill = ( m ) => seen.push( m[ KEY ] );
		Core.node( TEE ).connectNode( 'watcher' );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					inflightEnvelope( [
						{ rid: 'watched', url: '/x', state: 'process' },
					] )
				)
			);
		} );
		// The watcher saw the raw stream AND the view accumulated the request.
		expect( seen ).toContain( 'inflight' );
		expect( Core.node( VIEW ).requests.has( 'watched' ) ).toBe( true );
	} );

	test( 'opens an EventSource against /messages/stream?subscribe=gyroscope.* when visible', () => {
		renderHook( () => useGyroscopeGraph() );
		expect( FakeEventSource.last ).toBeTruthy();
		expect( FakeEventSource.last.url ).toBe(
			'/wp-json/newspack-nodes/v1/messages/stream?subscribe=gyroscope.*&_wpnonce=NONCE'
		);
	} );

	test( 'does not open an EventSource on mount when the page is hidden', () => {
		mockPageVisible = false;
		renderHook( () => useGyroscopeGraph() );
		expect( FakeEventSource.last ).toBeNull();
	} );

	test( 'the composed HttpOut has a CommandClient client wired (the POST boundary is constructable)', () => {
		renderHook( () => useGyroscopeGraph() );
		const http = Core.node( HTTP );
		expect( http.client ).toBeTruthy();
		expect( typeof http.client.buildMessage ).toBe( 'function' );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useGyroscopeGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot', () => {
		renderHook( () => useGyroscopeGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		const heartbeat = Core.node( HEARTBEAT );
		expect( heartbeat.slot ).toBe( 5 );
	} );

	test( 'a `connected` envelope with no slot leaves heartbeat slot null', () => {
		renderHook( () => useGyroscopeGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: null } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'the Router TIMER drives heartbeat.fire (via notify_timer) so the slot keep-alive actually fires', () => {
		jest.useFakeTimers();
		try {
			renderHook( () => useGyroscopeGraph() );
			const http = Core.node( HTTP );
			const postBatch = jest.fn().mockResolvedValue( [] );
			http.client = { buildMessage: () => newMessage(), postBatch };
			act( () => {
				FakeEventSource.last.dispatch(
					'connected',
					pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
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
	test( 'an inflight envelope from the EventSource flows into gyroscope:view', () => {
		renderHook( () => useGyroscopeGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					inflightEnvelope( [
						{ rid: 'r-flow', url: '/x', state: 'process' },
					] )
				)
			);
		} );
		const view = Core.node( VIEW );
		expect( view.requests.has( 'r-flow' ) ).toBe( true );
	} );

	test( 'a completion envelope flows through transform into gyroscope:view', () => {
		renderHook( () => useGyroscopeGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					( () => {
						const m = newMessage();
						m[ TYPE ] = TM_STRUCT;
						m[ KEY ] = 'rid-done';
						m[ VALUE ] = {
							rid: 'rid-done',
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

describe( 'useGyroscopeGraph — page visibility lifecycle', () => {
	test( 'hiding the page closes the EventSource AND clears the heartbeat slot', () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBe( 5 );
		const beforeHide = FakeEventSource.last;
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		expect( beforeHide.closed ).toBe( true );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'showing the page reopens the EventSource', () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
	} );

	test( 'reopening on refocus RESUMES from the last streamed offset (carries &positions=), not a blind tail', () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		// A tailed record: segment:offset:length in ID, partition dir in FROM.
		const rec = inflightEnvelope( [ { rid: 'r1' } ] );
		rec[ FROM ] = 'gyroscope.p0';
		rec[ ID ] = '1:64:20';
		act( () => {
			FakeEventSource.last.dispatch( 'msg', pack( rec ) );
		} );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
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

	test( 'resets the view map on (re)connect', () => {
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		// Seed the map with an in-flight request.
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					inflightEnvelope( [
						{ rid: 'old', url: '/x', state: 'process' },
					] )
				)
			);
		} );
		expect( Core.node( VIEW ).requests.size ).toBe( 1 );
		// Hide then show → the re-subscribe path clears the map first.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( Core.node( VIEW ).requests.size ).toBe( 0 );
	} );
} );

describe( 'useGyroscopeGraph — teardown', () => {
	test( 'unmount tears down the RemoteLink children + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useGyroscopeGraph() );
		const sourceAtMount = FakeEventSource.last;
		unmount();
		for ( const name of [
			...COMPOSED_NAMES,
			LINK,
			VIEW,
			INTERPRETER,
			ROUTER,
		] ) {
			expect( Core.node( name ) ).toBeNull();
		}
		expect( sourceAtMount.closed ).toBe( true );
	} );

	test( 'late envelopes after unmount do not throw', () => {
		const { unmount } = renderHook( () => useGyroscopeGraph() );
		const source = FakeEventSource.last;
		unmount();
		expect( () =>
			source.dispatch(
				'msg',
				pack(
					inflightEnvelope( [
						{ rid: 'late', url: '/x', state: 'process' },
					] )
				)
			)
		).not.toThrow();
	} );
} );

describe( 'useGyroscopeGraph — Core.reinit (Reset Graph)', () => {
	test( 'Core.reinit rebuilds the graph nodes fresh (backbone preserved)', () => {
		renderHook( () => useGyroscopeGraph() );
		const firstView = Core.node( VIEW );
		const firstHttp = Core.node( HTTP );
		const backbone = Core.node( INTERPRETER );
		expect( firstView ).not.toBeNull();
		expect( typeof Core.reinit ).toBe( 'function' );

		act( () => {
			Core.reinit();
		} );

		// Soft nodes rebuild fresh; backbone (incl shared `_http`) survives.
		expect( Core.node( VIEW ) ).not.toBe( firstView );
		expect( Core.node( HTTP ) ).toBe( firstHttp );
		// The rebuilt link reopened the unnamed SseIn on the `gyroscope` topic.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=gyroscope.*' );
		expect( Core.node( VIEW ).sink ).toBe( Core.node( INTERPRETER ) );
		expect( Core.node( INTERPRETER ) ).toBe( backbone );
	} );

	test( 'Core.reinit re-renders the consumer so useNodeState re-subscribes to the fresh view', () => {
		const { result } = renderHook( () => {
			useGyroscopeGraph();
			return useNodeState( VIEW, 'view' );
		} );
		const firstView = Core.node( VIEW );

		act( () => {
			Core.reinit();
		} );
		const freshView = Core.node( VIEW );
		expect( freshView ).not.toBe( firstView );

		// Fresh view publishes; consumer observes it (proves re-subscribe).
		act( () => {
			freshView.setState( 'view', { sampled: true } );
		} );
		expect( result.current ).toEqual( { sampled: true } );
	} );
} );
