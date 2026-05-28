/**
 * gyroscope:stream tests — the SSE-in node that owns the live connection to the
 * gyroscope firehose. `subscribe()` connects an SSE source; each inbound `msg`
 * envelope is emitted through the ONE sink (the exospine CI) stamped TO the
 * route. Connection-status changes emit the SAME way — through that one sink,
 * stamped KEY='connection' + TO=route — so the route node (not a bespoke
 * controlSink) does the data/control split. `close()` tears the connection down.
 *
 * Two seams are exercised:
 *  - The INJECTED connector (`opts.connector`): a fake whose `connect()` records
 *    the subscription + the envelope handler + the status handler so a test can
 *    deliver them and assert close/open bookkeeping.
 *  - The DEFAULT connector (no `opts.connector`): built on `global.EventSource`
 *    with the slot-heartbeat poke + reconnect backoff ported from useMessageStream.
 */

import {
	newMessage,
	TYPE,
	TO,
	KEY,
	VALUE,
	TM_INFO,
	TM_STRUCT,
	Core,
} from '@newspack-nodes/runtime';
import { createGyroscopeStream } from '../gyroscopeStream';

// getCommandClient is mocked so the default connector's slot heartbeat poke is
// observable without a real CommandClient (matches the reference suites).
jest.mock( '../../../shared/utils/commandClient', () => ( {
	getCommandClient: jest.fn(),
} ) );
const { getCommandClient } = require( '../../../shared/utils/commandClient' );

// setName registers in the per-process Core registry; clear it between tests so
// re-creating the same-named node doesn't collide.
beforeEach( () => Core.reset() );

// A fake connector matching the seam the node depends on: connect( subscription,
// onEnvelope, onStatus ) opens a source (recording the subscription + the envelope
// handler + the status handler), close() tears it down. deliverMessage() /
// deliverStatus() invoke the recorded handlers as the wire would.
function makeFakeConnector() {
	return {
		closeCount: 0,
		lastSubscription: null,
		_onEnvelope: null,
		_onStatus: null,
		connect( subscription, onEnvelope, onStatus ) {
			this.lastSubscription = subscription;
			this._onEnvelope = onEnvelope;
			this._onStatus = onStatus;
		},
		close() {
			this.closeCount += 1;
			this._onEnvelope = null;
		},
		deliverMessage( envelope ) {
			if ( this._onEnvelope ) {
				this._onEnvelope( envelope );
			}
		},
		deliverStatus( status ) {
			if ( this._onStatus ) {
				this._onStatus( status );
			}
		},
	};
}

// A stream wired sink → a capture node, target → 'gyroscope:route'.
function makeStream( fake, got ) {
	const s = createGyroscopeStream( 'gyroscope:stream', { connector: fake } );
	s.sink = { fill: ( m ) => got.push( m ) };
	s.target = 'gyroscope:route';
	return s;
}

describe( 'gyroscope:stream', () => {
	test( 'emits one message envelope per SSE msg event to its sink, stamped TO the route', () => {
		const got = [];
		const fake = makeFakeConnector();
		const s = makeStream( fake, got );
		s.subscribe();
		const env = newMessage();
		env[ VALUE ] = { rid: 'r1', url: '/x' };
		fake.deliverMessage( env );
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ VALUE ] ).toEqual( { rid: 'r1', url: '/x' } );
		expect( got[ 0 ][ TO ] ).toBe( 'gyroscope:route' );
	} );

	test( 'subscribes to the gyroscope firehose', () => {
		const fake = makeFakeConnector();
		const s = makeStream( fake, [] );
		s.subscribe();
		expect( fake.lastSubscription ).toBe( 'gyroscope' );
	} );

	test( 'a connection status emits a control through the one sink, stamped KEY=connection + TO=route', () => {
		const got = [];
		const fake = makeFakeConnector();
		const s = makeStream( fake, got );
		s.subscribe();
		fake.deliverStatus( { connectionError: true } );
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( got[ 0 ][ KEY ] ).toBe( 'connection' );
		expect( got[ 0 ][ TO ] ).toBe( 'gyroscope:route' );
		expect( got[ 0 ][ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: true,
		} );
	} );

	test( 'a connectionError:false status clears the banner via the same sink', () => {
		const got = [];
		const fake = makeFakeConnector();
		const s = makeStream( fake, got );
		s.subscribe();
		fake.deliverStatus( { connectionError: false } );
		expect( got[ 0 ][ KEY ] ).toBe( 'connection' );
		expect( got[ 0 ][ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: false,
		} );
	} );

	test( 'a connection status with no sink does not throw', () => {
		const fake = makeFakeConnector();
		const s = createGyroscopeStream( 'gyroscope:stream', {
			connector: fake,
		} );
		s.subscribe();
		expect( () =>
			fake.deliverStatus( { connectionError: true } )
		).not.toThrow();
	} );

	test( 'the first subscribe does not close anything (nothing open yet)', () => {
		const fake = makeFakeConnector();
		const s = createGyroscopeStream( 'gyroscope:stream', {
			connector: fake,
		} );
		s.subscribe();
		expect( fake.closeCount ).toBe( 0 );
	} );

	test( 're-subscribing closes the old source before opening the new one', () => {
		const fake = makeFakeConnector();
		const s = createGyroscopeStream( 'gyroscope:stream', {
			connector: fake,
		} );
		s.subscribe();
		s.subscribe();
		expect( fake.closeCount ).toBe( 1 );
	} );

	test( 'close() tears down the connector', () => {
		const fake = makeFakeConnector();
		const s = createGyroscopeStream( 'gyroscope:stream', {
			connector: fake,
		} );
		s.subscribe();
		s.close();
		expect( fake.closeCount ).toBe( 1 );
	} );

	test( 'envelopes delivered before any subscribe are not emitted', () => {
		const got = [];
		const fake = makeFakeConnector();
		makeStream( fake, got );
		fake.deliverMessage( newMessage() );
		expect( got ).toHaveLength( 0 );
	} );

	test( 'names the node', () => {
		const fake = makeFakeConnector();
		const s = createGyroscopeStream( 'gyroscope:stream', {
			connector: fake,
		} );
		expect( s.name ).toBe( 'gyroscope:stream' );
	} );
} );

// The DEFAULT connector (no injected connector): EventSource + heartbeat + backoff.
describe( 'gyroscope:stream default connector', () => {
	class FakeEventSource {
		constructor( url ) {
			this.url = url;
			this.listeners = {};
			this.closed = false;
			FakeEventSource.instances.push( this );
		}
		addEventListener( type, fn ) {
			( this.listeners[ type ] ||= [] ).push( fn );
		}
		dispatch( type, data ) {
			( this.listeners[ type ] || [] ).forEach( ( fn ) =>
				fn( {
					data:
						'string' === typeof data
							? data
							: JSON.stringify( data ),
				} )
			);
		}
		close() {
			this.closed = true;
		}
	}
	FakeEventSource.instances = [];
	FakeEventSource.last = () =>
		FakeEventSource.instances[ FakeEventSource.instances.length - 1 ];

	const originalEventSource = global.EventSource;
	const originalData = window.NewspackNodesData;
	let sendMock;

	beforeEach( () => {
		global.EventSource = FakeEventSource;
		window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'N' };
		FakeEventSource.instances = [];
		sendMock = jest.fn().mockResolvedValue( null );
		getCommandClient.mockReturnValue( { send: sendMock } );
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		global.EventSource = originalEventSource;
		window.NewspackNodesData = originalData;
	} );

	test( 'opens a real EventSource at /messages/stream for the gyroscope subscription', () => {
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.subscribe();
		const es = FakeEventSource.last();
		expect( es.url ).toContain( 'newspack-nodes/v1/messages/stream' );
		expect( es.url ).toContain( 'subscribe=gyroscope' );
		expect( es.url ).toContain( '_wpnonce=N' );
	} );

	test( 'forwards each parsed msg envelope to the sink', () => {
		const got = [];
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		FakeEventSource.last().dispatch( 'msg', [
			1,
			0,
			'gyroscope.p0',
			'',
			'5:100',
			'',
			{ rid: 'r2', url: '/y' },
		] );
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ VALUE ] ).toEqual( { rid: 'r2', url: '/y' } );
	} );

	test( 'pokes the slot heartbeat after the connected envelope', () => {
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.subscribe();
		const m = newMessage();
		m[ TYPE ] = TM_INFO;
		m[ KEY ] = 'connected';
		m[ VALUE ] = { slot: 3 };
		FakeEventSource.last().dispatch( 'msg', JSON.stringify( m ) );
		jest.advanceTimersByTime( 5000 );
		expect( sendMock ).toHaveBeenCalledWith( {
			to: 'workers',
			verb: 'heartbeat',
			args: '3 10',
		} );
	} );

	test( 'reconnects with exponential backoff on error', () => {
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.subscribe();
		const first = FakeEventSource.last();
		first.onerror();
		expect( first.closed ).toBe( true );
		jest.advanceTimersByTime( 2000 );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( 1 );
	} );

	test( 'onerror emits connectionError:true through the sink and reconnects', () => {
		const got = [];
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		const first = FakeEventSource.last();
		first.onerror();
		expect( got[ 0 ][ KEY ] ).toBe( 'connection' );
		expect( got[ 0 ][ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: true,
		} );
		expect( first.closed ).toBe( true );
		jest.advanceTimersByTime( 2000 );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( 1 );
	} );

	test( 'onopen emits connectionError:false through the sink', () => {
		const got = [];
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		FakeEventSource.last().onopen();
		expect( got[ got.length - 1 ][ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: false,
		} );
	} );

	test( 'close() stops the heartbeat poke and the reconnect timer', () => {
		const s = createGyroscopeStream( 'gyroscope:stream' );
		s.subscribe();
		const m = newMessage();
		m[ TYPE ] = TM_INFO;
		m[ KEY ] = 'connected';
		m[ VALUE ] = { slot: 0 };
		FakeEventSource.last().dispatch( 'msg', JSON.stringify( m ) );
		s.close();
		const before = sendMock.mock.calls.length;
		jest.advanceTimersByTime( 10000 );
		expect( sendMock.mock.calls.length ).toBe( before );
	} );
} );
