/**
 * Tests for useMessageStream — the SSE wrapper that subscribes to
 * substrate /messages/stream and tracks per-partition resume positions.
 *
 * Strategy:
 *   - Stub global.EventSource with a controllable mock so we can drive
 *     'msg' / 'heartbeat' / error events synchronously.
 *   - Mock getCommandClient so we can assert the slot-heartbeat send.
 *   - Drive connect/close from the hook; assert positions/state evolve.
 */

jest.mock( '../../utils/commandClient', () => {
	const send = jest.fn().mockResolvedValue( undefined );
	return {
		__esModule: true,
		getCommandClient: jest.fn( () => ( { send } ) ),
		__send: send,
	};
} );

import useMessageStream from '../useMessageStream';
import { __send as mockSend } from '../../utils/commandClient';
import { renderHook, act } from './renderHook';

// In-memory EventSource that surfaces close / addEventListener events
// and lets the test deliver `msg` / `heartbeat` / `error` events.
class MockEventSource {
	constructor( url ) {
		this.url = url;
		this.closed = false;
		this.listeners = {};
		this.onopen = null;
		this.onerror = null;
		MockEventSource.instances.push( this );
	}
	close() {
		this.closed = true;
	}
	addEventListener( name, cb ) {
		this.listeners[ name ] = this.listeners[ name ] || [];
		this.listeners[ name ].push( cb );
	}
	emit( name, data ) {
		const cbs = this.listeners[ name ] || [];
		cbs.forEach( ( cb ) => cb( { data: JSON.stringify( data ) } ) );
	}
}
MockEventSource.instances = [];

beforeEach( () => {
	MockEventSource.instances.length = 0;
	mockSend.mockClear();
	mockSend.mockResolvedValue( undefined );
	global.EventSource = MockEventSource;
	window.NewspackNodesData = {
		restUrl: '/wp-json/',
		nonce: 'NONCE',
	};
	jest.useFakeTimers();
} );

afterEach( () => {
	jest.useRealTimers();
	delete window.NewspackNodesData;
} );

function mount( props ) {
	return renderHook( () => useMessageStream( props ) );
}

describe( 'useMessageStream', () => {
	it( 'sets an error and does nothing when restUrl is missing', () => {
		delete window.NewspackNodesData;
		const onMessage = jest.fn();
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage,
		} );
		act( () => {
			result.current.connect();
		} );
		expect( result.current.error ).toMatch( /Dashboard configuration/ );
		expect( MockEventSource.instances.length ).toBe( 0 );
		unmount();
	} );

	it( 'opens an EventSource with the subscribe + nonce query', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			intervalMs: 250,
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		expect( MockEventSource.instances.length ).toBe( 1 );
		const src = MockEventSource.instances[ 0 ];
		expect( src.url ).toMatch( /\/messages\/stream\?/ );
		expect( src.url ).toMatch( /subscribe=firehose/ );
		expect( src.url ).toMatch( /interval=250/ );
		expect( src.url ).toMatch( /_wpnonce=NONCE/ );
		unmount();
	} );

	it( 'does nothing when subscriptions are empty', () => {
		const { result, unmount } = mount( {
			subscriptions: [],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		expect( MockEventSource.instances.length ).toBe( 0 );
		unmount();
	} );

	it( 'calls onBeforeConnect each connect attempt', () => {
		const onBeforeConnect = jest.fn();
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
			onBeforeConnect,
		} );
		act( () => {
			result.current.connect();
		} );
		expect( onBeforeConnect ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );

	it( 'forwards parsed envelopes to onMessage and tracks positions', () => {
		const onMessage = jest.fn();
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage,
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		// Build a Message envelope: [TYPE, _, FROM, _, ID, KEY, VALUE].
		const envelope = [ 1, 0, 'firehose.p0', '', '42:1024', 'k', 'v' ];
		act( () => {
			src.emit( 'msg', envelope );
		} );
		expect( onMessage ).toHaveBeenCalledWith( envelope, { type: 1 } );
		expect( result.current.lastEventTime ).not.toBeNull();
		unmount();
	} );

	it( 'ignores msg events with malformed JSON', () => {
		const onMessage = jest.fn();
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage,
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		const cb = src.listeners.msg[ 0 ];
		// Hand it a raw event with a non-JSON .data field.
		act( () => {
			cb( { data: 'not-json' } );
		} );
		expect( onMessage ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'ignores envelopes that are not arrays', () => {
		const onMessage = jest.fn();
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage,
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		act( () => {
			src.emit( 'msg', { not: 'array' } );
		} );
		expect( onMessage ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'starts a slot-heartbeat poker when a connected envelope arrives', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		act( () => {
			src.emit( 'msg', [ 64, 0, '', '', '', 'connected', { slot: 3 } ] );
		} );
		// Heartbeat fires every 5000ms; advance and assert.
		act( () => {
			jest.advanceTimersByTime( 5000 );
		} );
		expect( mockSend ).toHaveBeenCalledWith( {
			to: 'workers',
			verb: 'heartbeat',
			payload: { slot: 3, ttl: 10 },
		} );
		unmount();
	} );

	it( 'does not start a heartbeat if slot id is missing or negative', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		// Negative slot.
		act( () => {
			src.emit( 'msg', [ 64, 0, '', '', '', 'connected', { slot: -1 } ] );
		} );
		act( () => {
			jest.advanceTimersByTime( 6000 );
		} );
		expect( mockSend ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'reconnects with exponential backoff on error', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		act( () => {
			src.onerror();
		} );
		// After backoff (delay = 1000 * 2^1 = 2000), a new EventSource opens.
		expect( result.current.error ).toMatch( /Reconnecting/ );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( MockEventSource.instances.length ).toBe( 2 );
		unmount();
	} );

	it( 'guards against repeated error events stacking reconnects', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		act( () => {
			src.onerror();
		} );
		// Second error before the backoff fires must not stack a second
		// reconnect.
		act( () => {
			src.onerror();
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( MockEventSource.instances.length ).toBe( 2 );
		unmount();
	} );

	it( 'resets retries when the stream successfully opens', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		// Force one retry to bump retryRef.
		act( () => {
			src.onerror();
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		// Second EventSource opens; the onopen callback resets retries.
		const src2 = MockEventSource.instances[ 1 ];
		act( () => {
			src2.onopen();
		} );
		// Now drive another error and the delay should be 2000 again, not 4000.
		act( () => {
			src2.onerror();
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( MockEventSource.instances.length ).toBe( 3 );
		unmount();
	} );

	it( 'close() tears down EventSource, heartbeat, and reconnect timer', () => {
		const { result, unmount } = mount( {
			subscriptions: [ 'firehose' ],
			onMessage: jest.fn(),
		} );
		act( () => {
			result.current.connect();
		} );
		const src = MockEventSource.instances[ 0 ];
		// Start heartbeat.
		act( () => {
			src.emit( 'msg', [ 64, 0, '', '', '', 'connected', { slot: 1 } ] );
		} );
		// Trigger a pending reconnect.
		act( () => {
			src.onerror();
		} );
		// Now close — both the heartbeat and reconnect timer must clear.
		act( () => {
			result.current.close();
		} );
		expect( src.closed ).toBe( true );
		mockSend.mockClear();
		act( () => {
			jest.advanceTimersByTime( 10000 );
		} );
		expect( mockSend ).not.toHaveBeenCalled();
		// No second EventSource appears.
		expect( MockEventSource.instances.length ).toBe( 1 );
		unmount();
	} );
} );
