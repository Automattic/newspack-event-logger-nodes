/**
 * useErrorLogGraph tests — the Error Log dashboard graph migrated onto the
 * substrate's `_sse` / `_http` / `_heartbeat` I/O boundary nodes (same boundary
 * useRequestLogGraph uses). The chain collapsed to `_sse → perferrors:view`
 * directly; the dead `perferrors:route` classifier and the
 * `perferrors:transform` Callback are gone — the view's `fill()` shapes raw
 * envelopes inline.
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic is
 * unmocked here — we drive a `msg` event through the fake EventSource and
 * assert it actually routes _sse → view. The slot keep-alive bridge mirrors
 * useRequestLogGraph exactly: a `connected` envelope populates
 * `_heartbeat.{slot,partition}`, and the Router TIMER drives
 * `heartbeat.fire` (via notify_timer) so the slot keep-alive actually fires.
 *
 * usePageVisibility is mocked to a controllable value so the visibility effect
 * is deterministic under jsdom.
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
	useNodeState,
} from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '../../../shared/hooks/usePageVisibility', () => ( {
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
const SSE = '_sse';
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'perferrors:view';
const ALL_GRAPH_NAMES = [ SSE, HTTP, HEARTBEAT, VIEW ];
// Names that MUST NOT be registered any more — the dead route/transform nodes.
const REMOVED_NODE_NAMES = [ 'perferrors:route', 'perferrors:transform' ];

// Build a `connected` envelope as the SseConnector recognizes it.
function connectedEnvelope( { pid = 4242, slot = 3, partition = 0 } = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_INFO;
	m[ KEY ] = 'connected';
	m[ VALUE ] = { pid, slot, partition };
	return m;
}

// An error envelope as the wire delivers it (KEY=rid, VALUE=row).
function errorEnvelope( rid, value ) {
	const m = newMessage();
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
}

describe( 'useErrorLogGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + the four graph nodes, each sinking into the interpreter', () => {
		renderHook( () => useErrorLogGraph() );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		for ( const name of ALL_GRAPH_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
	} );

	test( 'does not mount the retired perferrors:route / perferrors:transform nodes', () => {
		renderHook( () => useErrorLogGraph() );
		for ( const name of REMOVED_NODE_NAMES ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'steers flow with targets: _sse → view directly (and heartbeat → _http/workers)', () => {
		renderHook( () => useErrorLogGraph() );
		expect( Core.node( SSE ).target ).toBe( VIEW );
		expect( Core.node( HEARTBEAT ).target ).toBe( '_http/workers' );
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

	test( '_http has a CommandClient client wired (the POST boundary is constructable)', () => {
		renderHook( () => useErrorLogGraph() );
		const http = Core.node( HTTP );
		expect( http.client ).toBeTruthy();
		expect( typeof http.client.buildMessage ).toBe( 'function' );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useErrorLogGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot and heartbeat.partition', () => {
		renderHook( () => useErrorLogGraph() );
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
		renderHook( () => useErrorLogGraph() );
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
					pack(
						connectedEnvelope( { pid: 7, slot: 5, partition: 0 } )
					)
				);
			} );
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
		const { rerender } = renderHook( () => useErrorLogGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
	} );

	test( 'setPaused(true) closes the EventSource and clears the heartbeat slot', () => {
		const { result } = renderHook( () => useErrorLogGraph() );
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
	test( 'unmount unregisters all graph nodes + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useErrorLogGraph() );
		const sourceAtMount = FakeEventSource.last;
		unmount();
		for ( const name of [ ...ALL_GRAPH_NAMES, INTERPRETER, ROUTER ] ) {
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

		// Soft nodes are fresh instances under the same names; backbone survives.
		expect( Core.node( VIEW ) ).not.toBe( firstView );
		expect( Core.node( HTTP ) ).not.toBe( firstHttp );
		expect( Core.node( SSE ).target ).toBe( VIEW );
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
