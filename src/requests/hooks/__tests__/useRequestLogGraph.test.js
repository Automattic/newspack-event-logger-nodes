/**
 * useRequestLogGraph tests — the Request Log dashboard graph now clips onto the
 * substrate's canonical rule-#2 backbone (`_command_interpreter` → `_router`)
 * via a SINGLE `RemoteLink` node plus a single `requestlog:view` node.
 *
 * RemoteLink composes the three I/O children every SSE dashboard used to wire by
 * hand — `requestlog:link:sse-in` (SseIn), `requestlog:link:http` (HttpOut) and
 * `requestlog:link:heartbeat` (Heartbeat) — and wires the `connected → slot`
 * bridge to its own heartbeat. The dead `requestlog:route` / `requestlog:transform`
 * intermediate nodes remain gone (defensive shaping inlined into the view).
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic
 * (already covered by the substrate's `sse_connector.test.js`) is unmocked here
 * — we drive a `msg` event through the fake EventSource and assert it actually
 * routes the composed sse-in → view directly. usePageVisibility is mocked to a
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
const LINK = 'requestlog:link';
// RemoteLink composes an UNNAMED SseIn (held internally, not registered in Core)
// and SHARES the reserved-name `_http` (HttpOut) + `_heartbeat` (Heartbeat)
// singletons — the old per-link `{link}:sse-in/http/heartbeat` names are gone.
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'requestlog:view';
const TEE = 'requestlog:stream';
const COMPOSED_NAMES = [ HTTP, HEARTBEAT ];

// Build a `connected` envelope as SseInNode recognizes it: a flat `KEY VALUE`
// string (the SSE rework). SLOT is omitted when null; `partition` was removed.
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

// A completed-request envelope as the wire delivers it (no special KEY — just
// a request row in VALUE).
function completedEnvelope( req ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = req;
	return m;
}

describe( 'useRequestLogGraph — exospine + RemoteLink wiring', () => {
	test( 'mounts the backbone + one RemoteLink (sharing the reserved _http/_heartbeat) + the view, each sinking into the interpreter', () => {
		renderHook( () => useRequestLogGraph() );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		// The view sinks into the interpreter.
		expect( Core.node( VIEW ) ).toBeTruthy();
		expect( Core.node( VIEW ).sink ).toBe( interpreter );
		// RemoteLink shares the reserved _http + _heartbeat singletons, each
		// sinking into the interpreter. (The SseIn is unnamed — proven below via
		// the FakeEventSource URL + the end-to-end routing into the view.)
		for ( const name of COMPOSED_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// The per-link SseIn name is no longer registered.
		expect( Core.node( 'requestlog:link:sse-in' ) ).toBeNull();
	} );

	test( 'steers flow with targets: the unnamed sse-in subscribes on `completed` and routes to view (and heartbeat → _http/workers)', () => {
		renderHook( () => useRequestLogGraph() );
		// The unnamed SseIn opened against the `completed` subscribe topic (its
		// target → view is proven by the end-to-end routing test below).
		expect( FakeEventSource.last.url ).toContain( 'subscribe=completed' );
		expect( Core.node( HEARTBEAT ).target ).toBe( `${ HTTP }/workers` );
	} );

	test( 'does not mount the dropped route or transform intermediate nodes', () => {
		renderHook( () => useRequestLogGraph() );
		expect( Core.node( 'requestlog:route' ) ).toBeNull();
		expect( Core.node( 'requestlog:transform' ) ).toBeNull();
	} );

	test( 'inserts an inspectable Tee on the stream edge: link → tee → view', () => {
		renderHook( () => useRequestLogGraph() );
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
		renderHook( () => useRequestLogGraph() );
		const watcher = new Node();
		watcher.name = 'watcher';
		const seen = [];
		watcher.fill = ( m ) => seen.push( m[ VALUE ].rid );
		Core.node( TEE ).connectNode( 'watcher' );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( completedEnvelope( { rid: 'r-watch', url: '/x' } ) )
			);
		} );
		// The watcher saw the raw stream AND the view appended the entry.
		expect( seen ).toContain( 'r-watch' );
		expect( Core.node( VIEW ).entries ).toHaveLength( 1 );
		expect( Core.node( VIEW ).entries[ 0 ].rid ).toBe( 'r-watch' );
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

	test( 'the composed HttpOut has a CommandClient client wired (the POST boundary is constructable)', () => {
		renderHook( () => useRequestLogGraph() );
		const http = Core.node( HTTP );
		expect( http.client ).toBeTruthy();
		// The CommandClient surface: buildMessage + postBatch.
		expect( typeof http.client.buildMessage ).toBe( 'function' );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useRequestLogGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBe( 5 );
	} );

	test( 'a `connected` envelope with no slot leaves heartbeat slot null', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: null } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'the Router TIMER drives heartbeat.fire (via notify_timer) so the slot keep-alive actually fires', () => {
		jest.useFakeTimers();
		try {
			renderHook( () => useRequestLogGraph() );
			// Spy on the composed HttpOut's client.postBatch instead of fetch().
			const http = Core.node( HTTP );
			const postBatch = jest.fn().mockResolvedValue( [] );
			http.client = { buildMessage: () => newMessage(), postBatch };
			act( () => {
				FakeEventSource.last.dispatch(
					'msg',
					pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
				);
			} );
			// 1s Router TIMER × 5 = past the 5s base-Timer throttle (lastFireTime).
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
		const { rerender } = renderHook( () => useRequestLogGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
	} );

	test( 'reopening on refocus RESUMES from the last streamed offset (carries &positions=), not a blind tail', () => {
		const { rerender } = renderHook( () => useRequestLogGraph() );
		// Stream a real tailed record: the server stamps a `seg:off` breadcrumb in
		// ID and the partition dir in FROM — that is what the SseIn tracks.
		const rec = completedEnvelope( { rid: 'r1', url: '/a' } );
		rec[ FROM ] = 'completed.p0';
		rec[ ID ] = '2:8800';
		act( () => {
			FakeEventSource.last.dispatch( 'msg', pack( rec ) );
		} );
		// Hide → close.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		// Show → reopen MUST seek the last offset (fill the hidden gap), not tail.
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
			'completed.p0': { seg: 2, off: 8800 },
		} );
	} );

	test( 'setPaused(true) closes the EventSource and clears the heartbeat slot', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
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
	test( 'unmount tears down the RemoteLink children + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useRequestLogGraph() );
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

describe( 'useRequestLogGraph — Core.reinit (Reset Graph)', () => {
	test( 'Core.reinit rebuilds the graph nodes fresh (backbone preserved)', () => {
		renderHook( () => useRequestLogGraph() );
		const firstView = Core.node( VIEW );
		const firstHttp = Core.node( HTTP );
		const backbone = Core.node( INTERPRETER );
		expect( firstView ).not.toBeNull();
		expect( typeof Core.reinit ).toBe( 'function' );

		act( () => {
			Core.reinit();
		} );

		// Soft nodes are fresh instances under the same names; backbone survives —
		// including the shared `_http` singleton it now owns (preserved, not rebuilt).
		expect( Core.node( VIEW ) ).not.toBe( firstView );
		expect( Core.node( HTTP ) ).toBe( firstHttp );
		// The rebuilt link reopened the unnamed SseIn on the `completed` topic.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=completed' );
		expect( Core.node( VIEW ).sink ).toBe( Core.node( INTERPRETER ) );
		expect( Core.node( INTERPRETER ) ).toBe( backbone );
	} );

	test( 'Core.reinit re-renders the consumer so useNodeState re-subscribes to the fresh view', () => {
		const { result } = renderHook( () => {
			useRequestLogGraph();
			return useNodeState( VIEW, 'view' );
		} );
		const firstView = Core.node( VIEW );

		act( () => {
			Core.reinit();
		} );
		const freshView = Core.node( VIEW );
		expect( freshView ).not.toBe( firstView );

		// The fresh view publishes state; the consumer must observe it (proving it
		// re-subscribed to freshView, not the removed firstView).
		act( () => {
			freshView.setState( 'view', { paused: true } );
		} );
		expect( result.current ).toEqual( { paused: true } );
	} );

	test( 'reinit while paused re-publishes paused:true so the UI matches the closed stream', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => result.current.setPaused( true ) );
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );

		act( () => {
			Core.reinit();
		} );

		// The rebuilt view's constructor defaults paused:false; the hook must
		// re-apply the surviving pause so the button / empty-state don't show
		// "live" while the connection effect (gating on the surviving isPaused)
		// keeps the stream closed.
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );
	} );
} );
