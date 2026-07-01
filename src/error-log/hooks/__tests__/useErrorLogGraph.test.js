/**
 * useErrorLogGraph tests — the Error Log dashboard graph migrated onto the
 * substrate's canonical rule-#2 backbone (`_command_interpreter → _router`) via
 * a SINGLE `RemoteLink` node plus the single `perferrors:view` view-model node.
 *
 * RemoteLink composes the three I/O children every SSE dashboard used to wire by
 * hand — `perferrors:link:sse-in` (SseIn), `perferrors:link:http` (HttpOut) and
 * `perferrors:link:heartbeat` (Heartbeat) — and wires the `connected → slot`
 * bridge to its own heartbeat. The dead `perferrors:route` classifier and the
 * `perferrors:transform` Callback are gone — the view's `fill()` shapes raw
 * envelopes inline.
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic is
 * unmocked here — we drive a `msg` event through the fake EventSource and
 * assert it actually routes the composed sse-in → view. The slot keep-alive
 * bridge mirrors useRequestLogGraph exactly: a `connected` envelope populates
 * the composed heartbeat's `{slot,partition}`, and the Router TIMER drives
 * `heartbeat.fire` (via notify_timer) so the slot keep-alive actually fires.
 *
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
	useNodeState,
} from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useErrorLogGraph } from '../useErrorLogGraph';

// Minimal FakeEventSource — same shape as the substrate's sse_connector tests.
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
const LINK = 'perferrors:link';
// RemoteLink composes an UNNAMED SseIn (held internally, not registered in Core)
// and SHARES the reserved-name `_http` (HttpOut) + `_heartbeat` (Heartbeat)
// singletons — the old per-link `{link}:sse-in/http/heartbeat` names are gone.
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'perferrors:view';
const TEE = 'perferrors:stream';
const COMPOSED_NAMES = [ HTTP, HEARTBEAT ];
// Names that MUST NOT be registered any more — the dead route/transform nodes.
const REMOVED_NODE_NAMES = [ 'perferrors:route', 'perferrors:transform' ];

// Build a `connected` envelope as SseInNode recognizes it: a flat `KEY VALUE`
// string (the SSE rework). SLOT is omitted when null so the bridge leaves
// heartbeat.slot null. `partition` was removed end-to-end.
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

// An error envelope as the wire delivers it (KEY=rid, VALUE=row).
function errorEnvelope( rid, value ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
}

describe( 'useErrorLogGraph — exospine + RemoteLink wiring', () => {
	test( 'mounts the backbone + one RemoteLink (sharing the reserved _http/_heartbeat) + the view', () => {
		renderHook( () => useErrorLogGraph() );
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
		expect( Core.node( 'perferrors:link:sse-in' ) ).toBeNull();
	} );

	test( 'does not mount the retired perferrors:route / perferrors:transform nodes', () => {
		renderHook( () => useErrorLogGraph() );
		for ( const name of REMOVED_NODE_NAMES ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'inserts an inspectable Tee on the stream edge: link → tee → view', () => {
		renderHook( () => useErrorLogGraph() );
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
		renderHook( () => useErrorLogGraph() );
		const watcher = new Node();
		watcher.name = 'watcher';
		const seen = [];
		watcher.fill = ( m ) => seen.push( m[ KEY ] );
		Core.node( TEE ).connectNode( 'watcher' );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					errorEnvelope( 'r-watch', { ts: 1, k: 'error', m: 'x' } )
				)
			);
		} );
		// The watcher saw the raw stream AND the view appended the entry.
		expect( seen ).toContain( 'r-watch' );
		expect( Core.node( VIEW ).entries ).toHaveLength( 1 );
		expect( Core.node( VIEW ).entries[ 0 ].rid ).toBe( 'r-watch' );
	} );

	test( 'steers flow with targets: the unnamed sse-in subscribes on `errors` and routes to view; heartbeat → _http/workers', () => {
		renderHook( () => useErrorLogGraph() );
		// The unnamed SseIn opened against the `errors` subscribe topic (its
		// target → view is proven by the end-to-end routing test below).
		expect( FakeEventSource.last.url ).toContain( 'subscribe=errors' );
		expect( Core.node( HEARTBEAT ).target ).toBe( `${ HTTP }/workers` );
	} );

	test( 'opens an EventSource against /messages/stream?subscribe=errors when visible', () => {
		renderHook( () => useErrorLogGraph() );
		expect( FakeEventSource.last ).toBeTruthy();
		expect( FakeEventSource.last.url ).toBe(
			'/wp-json/newspack-nodes/v1/messages/stream?subscribe=errors&_wpnonce=NONCE'
		);
	} );

	test( 'does not open an EventSource on mount when the page is hidden', () => {
		mockPageVisible = false;
		renderHook( () => useErrorLogGraph() );
		expect( FakeEventSource.last ).toBeNull();
	} );

	test( 'the composed HttpOut has a CommandClient client wired (the POST boundary is constructable)', () => {
		renderHook( () => useErrorLogGraph() );
		const http = Core.node( HTTP );
		expect( http.client ).toBeTruthy();
		expect( typeof http.client.buildMessage ).toBe( 'function' );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useErrorLogGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot', () => {
		renderHook( () => useErrorLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBe( 5 );
	} );

	test( 'a `connected` envelope with no slot leaves heartbeat slot null', () => {
		renderHook( () => useErrorLogGraph() );
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
			renderHook( () => useErrorLogGraph() );
			const http = Core.node( HTTP );
			const postBatch = jest.fn().mockResolvedValue( [] );
			http.client = { buildMessage: () => newMessage(), postBatch };
			act( () => {
				FakeEventSource.last.dispatch(
					'msg',
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

describe( 'useErrorLogGraph — end-to-end routing through the exospine', () => {
	test( 'an errors envelope from the EventSource flows into perferrors:view', () => {
		renderHook( () => useErrorLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					errorEnvelope( 'r-flow', {
						ts: 1,
						k: 'error',
						m: 'boom',
					} )
				)
			);
		} );
		const view = Core.node( VIEW );
		expect( view.entries ).toHaveLength( 1 );
		expect( view.entries[ 0 ].rid ).toBe( 'r-flow' );
	} );
} );

describe( 'useErrorLogGraph — page visibility / pause lifecycle', () => {
	test( 'hiding the page closes the EventSource AND clears the heartbeat slot', () => {
		const { rerender } = renderHook( () => useErrorLogGraph() );
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
		const { rerender } = renderHook( () => useErrorLogGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
	} );

	test( 'reopening on refocus RESUMES from the last streamed offset (carries &positions=), not a blind tail', () => {
		const { rerender } = renderHook( () => useErrorLogGraph() );
		// A real tailed record: seg:off breadcrumb in ID, partition dir in FROM.
		const rec = errorEnvelope( 'r1', { ts: 1, k: 'error', m: 'x' } );
		rec[ FROM ] = 'errors.p0';
		rec[ ID ] = '4:512';
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
		expect( positions ).toEqual( { 'errors.p0': { seg: 4, off: 512 } } );
	} );

	test( 'setPaused(true) closes the EventSource and clears the heartbeat slot', () => {
		const { result } = renderHook( () => useErrorLogGraph() );
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
		const { result } = renderHook( () => useErrorLogGraph() );
		act( () => result.current.setPaused( true ) );
		const before = FakeEventSource.instances.length;
		act( () => result.current.setPaused( false ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( false );
	} );

	test( 'clear() empties the view buffer', () => {
		const { result } = renderHook( () => useErrorLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( errorEnvelope( 'r1', { ts: 1, k: 'error', m: 'x' } ) )
			);
		} );
		expect( Core.node( VIEW ).entries ).toHaveLength( 1 );
		act( () => result.current.clear() );
		expect( Core.node( VIEW ).entries ).toHaveLength( 0 );
	} );
} );

describe( 'useErrorLogGraph — teardown', () => {
	test( 'unmount tears down the RemoteLink children + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useErrorLogGraph() );
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
		const { unmount } = renderHook( () => useErrorLogGraph() );
		const source = FakeEventSource.last;
		unmount();
		expect( () =>
			source.dispatch(
				'msg',
				pack( errorEnvelope( 'late', { ts: 1, k: 'error', m: 'x' } ) )
			)
		).not.toThrow();
	} );
} );

describe( 'useErrorLogGraph — Core.reinit (Reset Graph)', () => {
	test( 'Core.reinit rebuilds the graph nodes fresh (backbone preserved)', () => {
		renderHook( () => useErrorLogGraph() );
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
		// The rebuilt link reopened the unnamed SseIn on the `errors` topic.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=errors' );
		expect( Core.node( VIEW ).sink ).toBe( Core.node( INTERPRETER ) );
		expect( Core.node( INTERPRETER ) ).toBe( backbone );
	} );

	test( 'Core.reinit re-renders the consumer so useNodeState re-subscribes to the fresh view', () => {
		const { result } = renderHook( () => {
			useErrorLogGraph();
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
		const { result } = renderHook( () => useErrorLogGraph() );
		act( () => result.current.setPaused( true ) );
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );

		act( () => {
			Core.reinit();
		} );

		// The rebuilt view's constructor defaults paused:false; the hook must
		// re-apply the surviving pause so the button / empty-state don't show
		// "live" while the connection effect (gating on the surviving isPaused)
		// keeps _sse closed.
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );
	} );
} );
