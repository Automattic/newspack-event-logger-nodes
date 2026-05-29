/**
 * gyroscope:view tests — owns the in-flight request model.
 *
 * Like rawLogsView / requestlog/view, two cadences: the HIGH-frequency in-flight
 * map (node.requests) + RPS (node.rps) live on the instance and are NOT published
 * — the React view's refresh tick calls node.snapshot() each interval to reap
 * completed entries and read the sorted+capped render list. The LOW-frequency
 * control model publishes via setState('view', …).
 *
 * The accumulation/expiry logic is ported verbatim from Inflight.js's
 * requestsRef-map handleMessage + renderRequests reaper:
 *  - inflight snapshot envelope (KEY='inflight', VALUE=array) → upsert each req
 *    by rid; NEVER overwrite a req already marked complete (the snapshot
 *    predates a completion already in the map).
 *  - completion envelope (VALUE=object with rid) → merge {...existing, ...req,
 *    state:'complete', time_ms, est_ms}.
 *  - snapshot() → count + DELETE complete entries (they show for one tick then
 *    reap), update RPS from that count, sort remaining by est_ms desc, cap.
 *
 * The view consumes raw envelopes directly (no upstream transform): it
 * dispatches on KEY/VALUE shape — `KEY === 'inflight'` + array VALUE is a
 * snapshot, an object VALUE with `rid` is a completion, `KEY === 'connected'`
 * and anything else is dropped. Local TM_STRUCT control messages (clear /
 * connection) still ride VALUE.action.
 */

import {
	KEY,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createGyroscopeView } from '../gyroscopeView';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A wire envelope as the EventSource delivers it: KEY='inflight', VALUE=array.
function inflightEnvelope( requests ) {
	const m = newMessage();
	m[ KEY ] = 'inflight';
	m[ VALUE ] = requests;
	return m;
}

// A wire envelope as the EventSource delivers it: KEY=<rid>, VALUE=object with rid.
function completeEnvelope( request ) {
	const m = newMessage();
	m[ KEY ] = request.rid;
	m[ VALUE ] = request;
	return m;
}

// A substrate `connected` envelope — KEY='connected', VALUE={pid,slot,partition}.
function connectedEnvelope( payload = { pid: 1, slot: 0, partition: 0 } ) {
	const m = newMessage();
	m[ KEY ] = 'connected';
	m[ VALUE ] = payload;
	return m;
}

// A local TM_STRUCT control message dispatched from the React layer (action ride).
function controlMsg( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = payload;
	return m;
}

test( 'upserts inflight requests from a wire envelope into node.requests keyed by rid', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill(
		inflightEnvelope( [
			{ rid: 'a', url: '/a', state: 'process' },
			{ rid: 'b', url: '/b', state: 'query' },
		] )
	);
	expect( v.requests.size ).toBe( 2 );
	expect( v.requests.get( 'a' ).url ).toBe( '/a' );
} );

test( 'appending inflight rows does NOT publish setState (no per-row re-render)', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( inflightEnvelope( [ { rid: 'a', url: '/a', state: 'process' } ] ) );
	v.fill( completeEnvelope( { rid: 'a', url: '/a', duration_ms: 5 } ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'a later inflight snapshot updates an in-flight request', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( [ { rid: 'a', url: '/a', state: 'process' } ] ) );
	v.fill( inflightEnvelope( [ { rid: 'a', url: '/a', state: 'render' } ] ) );
	expect( v.requests.get( 'a' ).state ).toBe( 'render' );
} );

test( 'an inflight snapshot never overwrites a request already marked complete', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( completeEnvelope( { rid: 'a', url: '/a', duration_ms: 7 } ) );
	// A late snapshot (produced before the completion) must not resurrect it.
	v.fill( inflightEnvelope( [ { rid: 'a', url: '/a', state: 'process' } ] ) );
	expect( v.requests.get( 'a' ).state ).toBe( 'complete' );
} );

test( 'completion merges into an existing entry and sets time_ms/est_ms', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill(
		inflightEnvelope( [
			{ rid: 'a', url: '/a', method: 'GET', state: 'process' },
		] )
	);
	v.fill(
		completeEnvelope( { rid: 'a', duration_ms: 33, status_code: 200 } )
	);
	const req = v.requests.get( 'a' );
	expect( req.state ).toBe( 'complete' );
	expect( req.method ).toBe( 'GET' ); // preserved from the inflight entry
	expect( req.url ).toBe( '/a' );
	expect( req.time_ms ).toBe( 33 );
	expect( req.est_ms ).toBe( 33 );
	expect( req.status_code ).toBe( 200 );
} );

test( 'completion with no prior inflight entry still records the request', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( completeEnvelope( { rid: 'z', url: '/z', duration_ms: 10 } ) );
	expect( v.requests.get( 'z' ).state ).toBe( 'complete' );
	expect( v.requests.get( 'z' ).est_ms ).toBe( 10 );
} );

test( 'completion missing duration_ms defaults time_ms/est_ms to 0', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( completeEnvelope( { rid: 'z', url: '/z' } ) );
	expect( v.requests.get( 'z' ).time_ms ).toBe( 0 );
	expect( v.requests.get( 'z' ).est_ms ).toBe( 0 );
} );

test( 'drops the substrate `connected` envelope (never a gyroscope record)', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( connectedEnvelope() );
	expect( v.requests.size ).toBe( 0 );
	expect( v.lastEventTime ).toBeNull();
} );

test( 'drops an unrecognized wire envelope (string VALUE, no rid)', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	const m = newMessage();
	m[ KEY ] = 'x';
	m[ VALUE ] = 'string';
	v.fill( m );
	expect( v.requests.size ).toBe( 0 );
	expect( v.lastEventTime ).toBeNull();
} );

test( 'drops an object VALUE missing rid (defensive)', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	const m = newMessage();
	m[ KEY ] = 'x';
	m[ VALUE ] = { method: 'GET', url: '/y' };
	v.fill( m );
	expect( v.requests.size ).toBe( 0 );
} );

test( 'snapshot() reaps complete entries (shown one tick then deleted)', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( [ { rid: 'a', url: '/a', state: 'process' } ] ) );
	v.fill( completeEnvelope( { rid: 'b', url: '/b', duration_ms: 5 } ) );
	// First snapshot still includes the completed request.
	const first = v.snapshot( 100 );
	expect( first.map( ( r ) => r.rid ).sort() ).toEqual( [ 'a', 'b' ] );
	// It was deleted from the map during that snapshot.
	expect( v.requests.has( 'b' ) ).toBe( false );
	// Second snapshot no longer includes it.
	const second = v.snapshot( 100 );
	expect( second.map( ( r ) => r.rid ) ).toEqual( [ 'a' ] );
} );

test( 'snapshot() sorts by est_ms descending and caps to maxRows', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill(
		inflightEnvelope( [
			{ rid: 'a', url: '/a', state: 'process', est_ms: 10 },
			{ rid: 'b', url: '/b', state: 'process', est_ms: 90 },
			{ rid: 'c', url: '/c', state: 'process', est_ms: 50 },
		] )
	);
	const sorted = v.snapshot( 2 );
	expect( sorted ).toHaveLength( 2 ); // capped
	expect( sorted[ 0 ].rid ).toBe( 'b' ); // 90 first
	expect( sorted[ 1 ].rid ).toBe( 'c' ); // 50 next
} );

test( 'snapshot() updates rps from the reaped completed count', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	expect( v.rps ).toBe( 0 );
	v.fill( completeEnvelope( { rid: 'a', url: '/a', duration_ms: 5 } ) );
	v.fill( completeEnvelope( { rid: 'b', url: '/b', duration_ms: 5 } ) );
	v.snapshot( 100 );
	expect( v.rps ).toBeGreaterThan( 0 );
} );

test( 'touches lastEventTime on each processed envelope', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	expect( v.lastEventTime ).toBeNull();
	v.fill( inflightEnvelope( [ { rid: 'a', url: '/a', state: 'process' } ] ) );
	expect( typeof v.lastEventTime ).toBe( 'number' );
} );

test( 'clear empties the map, history and rps', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill(
		inflightEnvelope( [
			{ rid: 'a', url: '/a', state: 'process' },
			{ rid: 'b', url: '/b', state: 'process' },
		] )
	);
	v.fill( completeEnvelope( { rid: 'c', url: '/c', duration_ms: 5 } ) );
	v.snapshot( 100 ); // builds some rps history
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.requests.size ).toBe( 0 );
	const after = v.snapshot( 100 );
	expect( after ).toHaveLength( 0 );
	expect( v.rps ).toBe( 0 );
} );

test( 'connection control publishes connectionError', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'a connectionError:false control clears the published flag', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'connection', connectionError: false } ) );
	expect( v.setStateCache.view.connectionError ).toBe( false );
} );

test( 'an unrelated control leaves connectionError untouched', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'publishes an initial view model on construction', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	expect( v.setStateCache.view ).toEqual( { connectionError: false } );
} );

test( 'names the node', () => {
	const v = createGyroscopeView( 'gyroscope:view' );
	expect( v.name ).toBe( 'gyroscope:view' );
} );
