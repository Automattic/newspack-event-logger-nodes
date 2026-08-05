/**
 * gyroscope:view tests — owns the in-flight request model.
 *
 * Two cadences: the HIGH-frequency in-flight map (node.requests) + RPS (node.rps)
 * live on the instance and are NOT published — the React view's refresh tick calls
 * node.snapshot() each interval to reap completed entries, age out stragglers, and
 * read the sorted+capped render list. The LOW-frequency control model publishes via
 * setState('view', …).
 *
 * The producer now emits ONE record per in-flight request (KEY='inflight', rid in
 * VALUE), not one batched list. The view is written ONCE, correct under BOTH producer
 * modes (full re-emit / delta) with no mode awareness:
 *  - inflight record (KEY='inflight', object with rid) → upsert by rid, stamping a
 *    freshness time; NEVER overwrite a req already marked complete.
 *  - completion (KEY=rid, object with rid) → merge {state:'complete', time/est_ms}.
 *  - snapshot() → show+reap complete entries (one tick), age out in-flight rows not
 *    refreshed within the staleness window (crash/eviction backstop under delta),
 *    update RPS, sort by est_ms desc, cap.
 */

import {
	KEY,
	FROM,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { GyroscopeViewNode } from '../gyroscope-view-node';

// Naming registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

function makeView( name ) {
	const node = new GyroscopeViewNode();
	node.name = name;
	// What the graph does: the dashboard drives controls under the view's name.
	node.controlFrom = name;
	return node;
}

// A wire envelope: ONE in-flight record — the rid rides KEY ONLY, never VALUE.
function inflightEnvelope( request ) {
	const { rid = '', ...row } = request;
	const m = newMessage();
	m[ KEY ] = rid;
	m[ VALUE ] = row;
	return m;
}

// A completion as produced: KEY=rid, VALUE stamped state:'complete', no rid.
function completeEnvelope( request ) {
	const { rid = '', ...row } = request;
	const m = newMessage();
	m[ KEY ] = rid;
	m[ VALUE ] = { state: 'complete', ...row };
	return m;
}

// A `connected` envelope — KEY='connected', VALUE={pid,slot,partition}.
function connectedEnvelope( payload = { pid: 1, slot: 0, partition: 0 } ) {
	const m = newMessage();
	m[ KEY ] = 'connected';
	m[ VALUE ] = payload;
	return m;
}

// A control from the dashboard; recognised by FROM, not by payload shape.
function controlMsg( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ FROM ] = 'gyroscope:view';
	m[ VALUE ] = payload;
	return m;
}

// A control is recognised by its FROM; an `action` field means nothing.
test( 'a record from another origin is never applied as a control', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );

	const record = newMessage();
	record[ TYPE ] = TM_STRUCT;
	record[ FROM ] = 'gyroscope.p0';
	record[ VALUE ] = { action: 'clear' };
	v.fill( record );

	expect( v.requests.size ).toBe( 1 );
} );

test( 'upserts a per-record inflight envelope into node.requests keyed by rid', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );
	v.fill( inflightEnvelope( { rid: 'b', url: '/b', state: 'query' } ) );
	expect( v.requests.size ).toBe( 2 );
	expect( v.requests.get( 'a' ).url ).toBe( '/a' );
	// An inflight record is NOT a completion — state stays as delivered.
	expect( v.requests.get( 'a' ).state ).toBe( 'process' );
} );

test( 'appending inflight rows does NOT publish setState (no per-row re-render)', () => {
	const v = makeView( 'gyroscope:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );
	v.fill( completeEnvelope( { rid: 'a', url: '/a', duration_ms: 5 } ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'a later inflight record updates an in-flight request', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'render' } ) );
	expect( v.requests.get( 'a' ).state ).toBe( 'render' );
} );

test( 'an inflight record never overwrites a request already marked complete', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( completeEnvelope( { rid: 'a', url: '/a', duration_ms: 7 } ) );
	// A late record (produced before the completion) must not resurrect it.
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );
	expect( v.requests.get( 'a' ).state ).toBe( 'complete' );
} );

test( 'a completion retires the matching in-flight entry, merging + marking complete', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill(
		inflightEnvelope( {
			rid: 'a',
			url: '/a',
			method: 'GET',
			state: 'process',
		} )
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

test( 'a rid literally named "inflight" routes purely by state', () => {
	// No sentinel exists to collide with: KEY is always the rid and the
	// server-owned state field alone discriminates.
	const view = makeView( 'gyroscope:view' );
	view.fill(
		inflightEnvelope( { rid: 'inflight', state: 'render', url: '/x' } )
	);
	expect( view.requests.get( 'inflight' ).state ).toBe( 'render' );
	view.fill( completeEnvelope( { rid: 'inflight', duration_ms: 4321 } ) );
	expect( view.requests.get( 'inflight' ).state ).toBe( 'complete' );
	expect( view.requests.get( 'inflight' ).est_ms ).toBe( 4321 );
} );

test( 'completion with no prior inflight entry still records the request', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( completeEnvelope( { rid: 'z', url: '/z', duration_ms: 10 } ) );
	expect( v.requests.get( 'z' ).state ).toBe( 'complete' );
	expect( v.requests.get( 'z' ).est_ms ).toBe( 10 );
} );

test( 'completion missing duration_ms defaults time_ms/est_ms to 0', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( completeEnvelope( { rid: 'z', url: '/z' } ) );
	expect( v.requests.get( 'z' ).time_ms ).toBe( 0 );
	expect( v.requests.get( 'z' ).est_ms ).toBe( 0 );
} );

test( 'drops the substrate `connected` envelope (never a gyroscope record)', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( connectedEnvelope() );
	expect( v.requests.size ).toBe( 0 );
} );

test( 'drops an unrecognized wire envelope (string VALUE, no rid)', () => {
	const v = makeView( 'gyroscope:view' );
	const m = newMessage();
	m[ KEY ] = 'x';
	m[ VALUE ] = 'string';
	v.fill( m );
	expect( v.requests.size ).toBe( 0 );
} );

test( 'drops an object VALUE missing rid (defensive)', () => {
	const v = makeView( 'gyroscope:view' );
	const m = newMessage();
	m[ KEY ] = 'x';
	m[ VALUE ] = { method: 'GET', url: '/y' };
	v.fill( m );
	expect( v.requests.size ).toBe( 0 );
} );

test( 'drops an inflight record missing rid (defensive)', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( { url: '/no-rid', state: 'process' } ) );
	expect( v.requests.size ).toBe( 0 );
} );

test( 'snapshot() reaps complete entries (shown one tick then deleted)', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );
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

test( 'snapshot() ages out an in-flight row not refreshed within the staleness window', () => {
	const nowSpy = jest.spyOn( Date, 'now' );
	try {
		nowSpy.mockReturnValue( 10_000 );
		const v = makeView( 'gyroscope:view' );
		v.fill(
			inflightEnvelope( {
				rid: 'stale-8201',
				url: '/s',
				state: 'process',
			} )
		);
		v.fill(
			inflightEnvelope( {
				rid: 'fresh-8202',
				url: '/f',
				state: 'process',
			} )
		);
		// 16 min later, refresh ONLY 'fresh'; 'stale' passes the aging window.
		nowSpy.mockReturnValue( 10_000 + 16 * 60 * 1000 );
		v.fill(
			inflightEnvelope( {
				rid: 'fresh-8202',
				url: '/f',
				state: 'render',
			} )
		);
		const rows = v.snapshot( 100 ).map( ( r ) => r.rid );
		expect( rows ).toEqual( [ 'fresh-8202' ] );
		expect( v.requests.has( 'stale-8201' ) ).toBe( false );
	} finally {
		nowSpy.mockRestore();
	}
} );

test( 'snapshot() sorts by est_ms descending and caps to maxRows', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill(
		inflightEnvelope( {
			rid: 'a',
			url: '/a',
			state: 'process',
			est_ms: 10,
		} )
	);
	v.fill(
		inflightEnvelope( {
			rid: 'b',
			url: '/b',
			state: 'process',
			est_ms: 90,
		} )
	);
	v.fill(
		inflightEnvelope( {
			rid: 'c',
			url: '/c',
			state: 'process',
			est_ms: 50,
		} )
	);
	const sorted = v.snapshot( 2 );
	expect( sorted ).toHaveLength( 2 ); // capped
	expect( sorted[ 0 ].rid ).toBe( 'b' ); // 90 first
	expect( sorted[ 1 ].rid ).toBe( 'c' ); // 50 next
} );

test( 'snapshot() updates rps from the reaped completed count', () => {
	const v = makeView( 'gyroscope:view' );
	expect( v.rps ).toBe( 0 );
	v.fill( completeEnvelope( { rid: 'a', url: '/a', duration_ms: 5 } ) );
	v.fill( completeEnvelope( { rid: 'b', url: '/b', duration_ms: 5 } ) );
	v.snapshot( 100 );
	expect( v.rps ).toBeGreaterThan( 0 );
} );

test( 'RPS tracking aggregates per second, not one entry per tick (bounded window)', () => {
	// Perf: requests/sec window is per-second buckets, not one per tick.
	const v = makeView( 'gyroscope:view' );
	for ( let i = 0; i < 500; i++ ) {
		v.fill(
			completeEnvelope( { rid: `r${ i }`, url: '/x', duration_ms: 5 } )
		);
		v.snapshot( 100 );
	}
	expect( Array.isArray( v.rpsBuckets ) ).toBe( true );
	expect( v.rpsBuckets.length ).toBeLessThanOrEqual( 12 );
} );

test( 'clear empties the map, history and rps', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( inflightEnvelope( { rid: 'a', url: '/a', state: 'process' } ) );
	v.fill( inflightEnvelope( { rid: 'b', url: '/b', state: 'process' } ) );
	v.fill( completeEnvelope( { rid: 'c', url: '/c', duration_ms: 5 } ) );
	v.snapshot( 100 ); // builds some rps history
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.requests.size ).toBe( 0 );
	const after = v.snapshot( 100 );
	expect( after ).toHaveLength( 0 );
	expect( v.rps ).toBe( 0 );
} );

test( 'connection control publishes connectionError', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'a connectionError:false control clears the published flag', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'connection', connectionError: false } ) );
	expect( v.setStateCache.view.connectionError ).toBe( false );
} );

test( 'an unrelated control leaves connectionError untouched', () => {
	const v = makeView( 'gyroscope:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'publishes an initial view model on construction', () => {
	const v = makeView( 'gyroscope:view' );
	expect( v.setStateCache.view ).toEqual( { connectionError: false } );
} );

test( 'names the node', () => {
	const v = makeView( 'gyroscope:view' );
	expect( v.name ).toBe( 'gyroscope:view' );
} );

describe( 'gyroscope:view — nodeSchema', () => {
	test( 'is a Hidden, terminal (no output port) node', () => {
		const schema = makeView( 'gyroscope:view' ).constructor.nodeSchema();
		expect( schema.has_target ).toBe( false );
		expect( schema.category ).toBe( 'Hidden' );
		expect( typeof schema.description ).toBe( 'string' );
		expect( schema.description.length ).toBeGreaterThan( 0 );
		expect( schema.arguments ).toEqual( [] );
		expect( schema.commands ).toEqual( [] );
	} );
} );
