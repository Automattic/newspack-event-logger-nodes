/**
 * useRequestLogGraph tests — the Request Log dashboard graph now clips onto the
 * substrate's `_sse` / `_http` / `_heartbeat` I/O boundary nodes (the same I/O
 * boundary the topology console mounts) plus a single `requestlog:view` node,
 * all on the exospine backbone (`_command_interpreter` → `_router`). The dead
 * `requestlog:route` (the substrate snoops the `connected` envelope off so the
 * controlTarget branch was unreachable) and `requestlog:transform` (defensive
 * shaping inlined into the view) intermediate nodes were removed.
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic
 * (already covered by the substrate's `sse_connector.test.js`) is unmocked here
 * — we drive a `msg` event through the fake EventSource and assert it actually
 * routes _sse → view directly. usePageVisibility is mocked to a controllable
 * value so the visibility effect is deterministic under jsdom.
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import {
	newMessage,
	pack,
	VALUE,
	KEY,
	TYPE,
	TM_INFO,
	Core,
} from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '../../../shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useRequestLogGraph } from '../useRequestLogGraph';

// Minimal FakeEventSource — same shape as the substrate's `sse_connector.test.js`.
// Stores the last instance on a static for tests to drive `msg` dispatches and
// inspect closed-state.
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
const SSE = '_sse';
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'requestlog:view';
const ALL_GRAPH_NAMES = [ SSE, HTTP, HEARTBEAT, VIEW ];

// Build a `connected` envelope as the SseConnector recognizes it.
function connectedEnvelope( { pid = 4242, slot = 3, partition = 0 } = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_INFO;
	m[ KEY ] = 'connected';
	m[ VALUE ] = { pid, slot, partition };
	return m;
}

// A completed-request envelope as the wire delivers it (no special KEY — just
// a request row in VALUE).
function completedEnvelope( req ) {
	const m = newMessage();
	m[ VALUE ] = req;
	return m;
}

describe( 'useRequestLogGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + the four graph nodes, each sinking into the interpreter', () => {
		renderHook( () => useRequestLogGraph() );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		for ( const name of ALL_GRAPH_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
	} );

	test( 'steers flow with targets: _sse → view directly (and heartbeat → _http/workers)', () => {
		renderHook( () => useRequestLogGraph() );
		expect( Core.node( SSE ).target ).toBe( VIEW );
		expect( Core.node( HEARTBEAT ).target ).toBe( '_http/workers' );
	} );

	test( 'does not mount the dropped route or transform intermediate nodes', () => {
		renderHook( () => useRequestLogGraph() );
		expect( Core.node( 'requestlog:route' ) ).toBeNull();
		expect( Core.node( 'requestlog:transform' ) ).toBeNull();
	} );

	test( 'opens an EventSource against /messages/stream?subscribe=completed when visible', () => {
		renderHook( () => useRequestLogGraph() );
		expect( FakeEventSource.last ).toBeTruthy();
		expect( FakeEventSource.last.url ).toBe(
			'/wp-json/newspack-nodes/v1/messages/stream?subscribe=completed&_wpnonce=NONCE'
		);
	} );

	test( 'does not open an EventSource on mount when the page is hidden', () => {
		mockPageVisible = false;
		renderHook( () => useRequestLogGraph() );
		expect( FakeEventSource.last ).toBeNull();
	} );

	test( '_http has a CommandClient client wired (the POST boundary is constructable)', () => {
		renderHook( () => useRequestLogGraph() );
		const http = Core.node( HTTP );
		expect( http.client ).toBeTruthy();
		// The CommandClient surface: buildMessage + postBatch.
		expect( typeof http.client.buildMessage ).toBe( 'function' );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useRequestLogGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot and heartbeat.partition', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: 5, partition: 2 } ) )
			);
		} );
		const heartbeat = Core.node( HEARTBEAT );
		expect( heartbeat.slot ).toBe( 5 );
		expect( heartbeat.partition ).toBe( 2 );
	} );

	test( 'a `connected` envelope with no slot leaves heartbeat slot null', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					connectedEnvelope( {
						pid: 7,
						slot: null,
						partition: 2,
					} )
				)
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'the Router TIMER drives heartbeat.onTimer so the slot keep-alive actually fires', () => {
		jest.useFakeTimers();
		try {
			renderHook( () => useRequestLogGraph() );
			// Spy on _http.client.postBatch instead of fetch().
			const http = Core.node( HTTP );
			const postBatch = jest.fn().mockResolvedValue( [] );
			http.client = { buildMessage: () => newMessage(), postBatch };
			act( () => {
				FakeEventSource.last.dispatch(
					'msg',
					pack(
						connectedEnvelope( { pid: 7, slot: 5, partition: 0 } )
					)
				);
			} );
			// 1s Router TIMER × 5 = past the 5s throttle in HeartbeatNode.onTimer.
			act( () => {
				jest.advanceTimersByTime( 5000 );
			} );
			expect( Core.node( HEARTBEAT ).lastFired ).toBeGreaterThan( 0 );
			expect( postBatch ).toHaveBeenCalled();
		} finally {
			jest.useRealTimers();
		}
	} );
} );

describe( 'useRequestLogGraph — end-to-end routing through the exospine', () => {
	test( 'a completed envelope from the EventSource flows into requestlog:view', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					completedEnvelope( {
						rid: 'r-flow',
						url: '/x',
						method: 'GET',
						status_code: 200,
						duration_ms: 5,
						end_time: 1,
					} )
				)
			);
		} );
		const view = Core.node( VIEW );
		expect( view.entries ).toHaveLength( 1 );
		expect( view.entries[ 0 ].rid ).toBe( 'r-flow' );
	} );
} );

describe( 'useRequestLogGraph — page visibility / pause lifecycle', () => {
	test( 'hiding the page closes the EventSource AND clears the heartbeat slot', () => {
		const { rerender } = renderHook( () => useRequestLogGraph() );
		// Acquire a slot first so we can prove clearSlot fires.
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: 5, partition: 2 } ) )
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
		const { rerender } = renderHook( () => useRequestLogGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
	} );

	test( 'setPaused(true) closes the EventSource and clears the heartbeat slot', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: 5, partition: 2 } ) )
			);
		} );
		const openSource = FakeEventSource.last;
		act( () => result.current.setPaused( true ) );
		expect( openSource.closed ).toBe( true );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );
	} );

	test( 'setPaused(false) reopens the EventSource', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => result.current.setPaused( true ) );
		const before = FakeEventSource.instances.length;
		act( () => result.current.setPaused( false ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( false );
	} );

	test( 'clear() empties the view buffer', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					completedEnvelope( {
						rid: 'r1',
						url: '/x',
						method: 'GET',
						status_code: 200,
						end_time: 1,
					} )
				)
			);
		} );
		expect( Core.node( VIEW ).entries ).toHaveLength( 1 );
		act( () => result.current.clear() );
		expect( Core.node( VIEW ).entries ).toHaveLength( 0 );
	} );
} );

describe( 'useRequestLogGraph — teardown', () => {
	test( 'unmount unregisters all graph nodes + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useRequestLogGraph() );
		const sourceAtMount = FakeEventSource.last;
		unmount();
		for ( const name of [ ...ALL_GRAPH_NAMES, INTERPRETER, ROUTER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
		expect( sourceAtMount.closed ).toBe( true );
	} );

	test( 'late envelopes after unmount do not throw', () => {
		const { unmount } = renderHook( () => useRequestLogGraph() );
		const source = FakeEventSource.last;
		unmount();
		expect( () =>
			source.dispatch(
				'msg',
				pack( completedEnvelope( { rid: 'late', url: '/x' } ) )
			)
		).not.toThrow();
	} );
} );
