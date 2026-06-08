/**
 * useGyroscopeGraph tests — post-collapse: the Gyroscope dashboard graph now
 * clips onto the substrate's `_sse` / `_http` / `_heartbeat` I/O boundary nodes
 * (the same I/O boundary the topology console and request-log mount) plus a
 * single `gyroscope:view` node, all on the exospine backbone
 * (`_command_interpreter` → `_router`). The bespoke `gyroscope:stream` Node and
 * its inlined slot-heartbeat loop were retired earlier; the `gyroscope:route`
 * and `gyroscope:transform` hops were collapsed into `gyroscope:view.fill()`
 * directly (route was dead, transform was an envelope-shape dispatcher the view
 * can do itself).
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic
 * (already covered by the substrate's `sse_connector.test.js`) is unmocked here
 * — we drive a `msg` event through the fake EventSource and assert it actually
 * routes _sse → view. usePageVisibility is mocked to a controllable value so
 * the visibility effect is deterministic under jsdom.
 */

import { renderHook, act } from '../../../test-helpers/renderHook';
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
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useGyroscopeGraph } from '../useGyroscopeGraph';

// Minimal FakeEventSource — same shape as the substrate's `sse_connector.test.js`.
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
const VIEW = 'gyroscope:view';
const ALL_GRAPH_NAMES = [ SSE, HTTP, HEARTBEAT, VIEW ];

// A `connected` envelope as SseConnector recognizes it.
function connectedEnvelope( { pid = 4242, slot = 3, partition = 0 } = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_INFO;
	m[ KEY ] = 'connected';
	m[ VALUE ] = { pid, slot, partition };
	return m;
}

// A gyroscope inflight-snapshot envelope as the wire delivers it.
function inflightEnvelope( requests ) {
	const m = newMessage();
	m[ KEY ] = 'inflight';
	m[ VALUE ] = requests;
	return m;
}

describe( 'useGyroscopeGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + the four graph nodes, each sinking into the interpreter', () => {
		renderHook( () => useGyroscopeGraph() );
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
		renderHook( () => useGyroscopeGraph() );
		expect( Core.node( SSE ).target ).toBe( VIEW );
		expect( Core.node( HEARTBEAT ).target ).toBe( '_http/workers' );
	} );

	test( 'does not mount the retired route/transform nodes', () => {
		renderHook( () => useGyroscopeGraph() );
		expect( Core.node( 'gyroscope:route' ) ).toBeNull();
		expect( Core.node( 'gyroscope:transform' ) ).toBeNull();
	} );

	test( 'opens an EventSource against /messages/stream?subscribe=gyroscope when visible', () => {
		renderHook( () => useGyroscopeGraph() );
		expect( FakeEventSource.last ).toBeTruthy();
		expect( FakeEventSource.last.url ).toBe(
			'/wp-json/newspack-nodes/v1/messages/stream?subscribe=gyroscope&_wpnonce=NONCE'
		);
	} );

	test( 'does not open an EventSource on mount when the page is hidden', () => {
		mockPageVisible = false;
		renderHook( () => useGyroscopeGraph() );
		expect( FakeEventSource.last ).toBeNull();
	} );

	test( '_http has a CommandClient client wired (the POST boundary is constructable)', () => {
		renderHook( () => useGyroscopeGraph() );
		const http = Core.node( HTTP );
		expect( http.client ).toBeTruthy();
		expect( typeof http.client.buildMessage ).toBe( 'function' );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useGyroscopeGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot and heartbeat.partition', () => {
		renderHook( () => useGyroscopeGraph() );
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
		renderHook( () => useGyroscopeGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					connectedEnvelope( { pid: 7, slot: null, partition: 2 } )
				)
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
		const { rerender } = renderHook( () => useGyroscopeGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
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
	test( 'unmount unregisters all graph nodes + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useGyroscopeGraph() );
		const sourceAtMount = FakeEventSource.last;
		unmount();
		for ( const name of [ ...ALL_GRAPH_NAMES, INTERPRETER, ROUTER ] ) {
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

		// Soft nodes are fresh instances under the same names; backbone survives.
		expect( Core.node( VIEW ) ).not.toBe( firstView );
		expect( Core.node( HTTP ) ).not.toBe( firstHttp );
		expect( Core.node( SSE ).target ).toBe( VIEW );
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

		// The fresh view publishes state; the consumer must observe it (proving it
		// re-subscribed to freshView, not the removed firstView).
		act( () => {
			freshView.setState( 'view', { sampled: true } );
		} );
		expect( result.current ).toEqual( { sampled: true } );
	} );
} );
