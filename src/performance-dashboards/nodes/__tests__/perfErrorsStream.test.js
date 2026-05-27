/**
 * perferrors:stream tests — the SSE-in node that owns the live connection to the
 * errors firehose. `subscribe()` connects an SSE source; each inbound `msg`
 * envelope is emitted through the node's `sink` (the exospine CI) stamped
 * `TO = target` (→ the route). Connection-status changes are emitted the SAME way,
 * as a `{ action:'connection' }` TM_STRUCT stamped `KEY = 'connection'` — there is
 * NO controlSink; the route node does the data/control split keyed on that marker.
 *
 * Two seams are exercised:
 *  - The INJECTED connector (`opts.connector`): a fake whose `connect()` records
 *    the subscription + the envelope handler + the status handler.
 *  - The DEFAULT connector (no `opts.connector`): built on `global.EventSource`
 *    with the slot-heartbeat poke + reconnect backoff + onStatus wiring.
 */

import {
	newMessage,
	TYPE,
	KEY,
	TO,
	VALUE,
	TM_STRUCT,
	TM_INFO,
	Core,
} from '@newspack-nodes/runtime';
import { createPerfErrorsStream } from '../perfErrorsStream';

// getCommandClient is mocked so the default connector's slot heartbeat poke is
// observable without a real CommandClient (matches the reference suites).
jest.mock( '../../../shared/utils/commandClient', () => ( {
	getCommandClient: jest.fn(),
} ) );
const { getCommandClient } = require( '../../../shared/utils/commandClient' );

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A fake connector matching the seam the node depends on: connect( subscription,
// onEnvelope, onStatus ) records all three; close() tears it down.
function fakeConnector() {
	return {
		calls: [],
		lastOnEnvelope: null,
		lastOnStatus: null,
		closed: 0,
		connect( sub, onEnvelope, onStatus ) {
			this.calls.push( sub );
			this.lastOnEnvelope = onEnvelope;
			this.lastOnStatus = onStatus;
		},
		close() {
			this.closed += 1;
		},
	};
}

describe( 'perferrors:stream', () => {
	test( 'subscribe() connects the errors feed and forwards envelopes to sink stamped TO target', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		s.target = 'perferrors:route';
		const got = [];
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		expect( c.calls ).toEqual( [ 'errors' ] );
		const env = newMessage();
		env[ KEY ] = 'rid-1';
		env[ VALUE ] = { ts: 1 };
		c.lastOnEnvelope( env );
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ VALUE ] ).toEqual( { ts: 1 } );
		expect( got[ 0 ][ TO ] ).toBe( 'perferrors:route' );
	} );

	test( 'connection status emits a KEY=connection control through the sink (no controlSink)', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		s.target = 'perferrors:route';
		const got = [];
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		c.lastOnStatus( { connectionError: true } );
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
		expect( got[ 0 ][ KEY ] ).toBe( 'connection' );
		expect( got[ 0 ][ TO ] ).toBe( 'perferrors:route' );
		expect( got[ 0 ][ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: true,
		} );
	} );

	test( 'a connectionError:false status clears the banner through the sink', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		const got = [];
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		c.lastOnStatus( { connectionError: false } );
		expect( got[ 0 ][ KEY ] ).toBe( 'connection' );
		expect( got[ 0 ][ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: false,
		} );
	} );

	test( 'a connection status with no sink does not throw', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		s.subscribe();
		expect( () =>
			c.lastOnStatus( { connectionError: true } )
		).not.toThrow();
	} );

	test( 're-subscribing closes the old source before opening the new one', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		s.subscribe();
		s.subscribe();
		expect( c.closed ).toBe( 1 );
	} );

	test( 'close() tears the connector down', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		s.subscribe();
		s.close();
		expect( c.closed ).toBeGreaterThanOrEqual( 1 );
	} );

	test( 'envelopes delivered before any subscribe are not emitted', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		const got = [];
		s.sink = { fill: ( m ) => got.push( m ) };
		expect( c.lastOnEnvelope ).toBeNull();
		expect( got ).toHaveLength( 0 );
	} );

	test( 'names the node', () => {
		const c = fakeConnector();
		const s = createPerfErrorsStream( 'perferrors:stream', {
			connector: c,
		} );
		expect( s.name ).toBe( 'perferrors:stream' );
	} );
} );

// The DEFAULT connector (no injected connector): EventSource + heartbeat +
// backoff + the onStatus wiring (onerror→connectionError:true, onopen→false).
describe( 'perferrors:stream default connector', () => {
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

	test( 'opens a real EventSource at /messages/stream for the errors subscription', () => {
		const s = createPerfErrorsStream( 'perferrors:stream' );
		s.subscribe();
		const es = FakeEventSource.last();
		expect( es.url ).toContain( 'newspack-nodes/v1/messages/stream' );
		expect( es.url ).toContain( 'subscribe=errors' );
		expect( es.url ).toContain( '_wpnonce=N' );
	} );

	test( 'forwards each parsed msg envelope to the sink', () => {
		const got = [];
		const s = createPerfErrorsStream( 'perferrors:stream' );
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		FakeEventSource.last().dispatch( 'msg', [
			1,
			0,
			'errors.p0',
			'',
			'1:0',
			'rid_xyz',
			{ ts: 1, k: 'error', m: 'boom' },
		] );
		expect( got ).toHaveLength( 1 );
		expect( got[ 0 ][ VALUE ] ).toEqual( {
			ts: 1,
			k: 'error',
			m: 'boom',
		} );
	} );

	test( 'onerror emits a KEY=connection control through the sink and reconnects', () => {
		const got = [];
		const s = createPerfErrorsStream( 'perferrors:stream' );
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

	test( 'onopen emits a KEY=connection connectionError:false control through the sink', () => {
		const got = [];
		const s = createPerfErrorsStream( 'perferrors:stream' );
		s.sink = { fill: ( m ) => got.push( m ) };
		s.subscribe();
		FakeEventSource.last().onopen();
		const last = got[ got.length - 1 ];
		expect( last[ KEY ] ).toBe( 'connection' );
		expect( last[ VALUE ] ).toEqual( {
			action: 'connection',
			connectionError: false,
		} );
	} );

	test( 'pokes the slot heartbeat after the connected envelope', () => {
		const s = createPerfErrorsStream( 'perferrors:stream' );
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

	test( 'close() stops the heartbeat poke and the reconnect timer', () => {
		const s = createPerfErrorsStream( 'perferrors:stream' );
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
