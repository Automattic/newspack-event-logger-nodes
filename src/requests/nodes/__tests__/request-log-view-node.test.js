/**
 * requestlog:view tests — owns the Request Log view model.
 *
 * Two cadences (matching rawLogsView): the HIGH-frequency request buffer
 * (node.entries) + RPS (node.rps) live on the instance and are NOT published —
 * the React view's rAF reads them directly each frame. The LOW-frequency control
 * model ({ paused }) publishes via setState('view', …).
 */

import {
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { RequestLogViewNode } from '../request-log-view-node';

// Naming registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Construct + name the node directly — the createX factory is gone (make_node
// builds it in production); bare-new + name= is the test seam.
function makeView( name, opts = {} ) {
	const node = new RequestLogViewNode( opts.maxEntries );
	node.name = name;
	return node;
}

// A row message from requestlog:transform: TM_STRUCT carrying the mapped row.
function rowMsg( req ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = req;
	return m;
}

// A control message: TM_STRUCT carrying { action, ... }.
function controlMsg( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = payload;
	return m;
}

function row( overrides = {} ) {
	return {
		rid: 'r1',
		url: '/foo',
		method: 'GET',
		duration_ms: 50,
		status_code: 200,
		end_time: 1748960000,
		remote_addr: '10.0.0.1',
		user_agent: 'curl/7',
		...overrides,
	};
}

test( 'appends rows newest-first to node.entries (no publish)', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'a' } ) ) );
	v.fill( rowMsg( row( { rid: 'b' } ) ) );
	v.fill( rowMsg( row( { rid: 'c' } ) ) );
	expect( v.entries[ 0 ].rid ).toBe( 'c' ); // newest first (unshift)
	expect( v.entries ).toHaveLength( 3 );
} );

test( 'appending rows does NOT publish setState (no per-row React re-render)', () => {
	const v = makeView( 'requestlog:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( rowMsg( row() ) );
	v.fill( rowMsg( row() ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'caps the buffer at maxEntries (newest kept)', () => {
	const v = makeView( 'requestlog:view', { maxEntries: 3 } );
	for ( let i = 0; i < 5; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }` } ) ) );
	}
	expect( v.entries ).toHaveLength( 3 );
	expect( v.entries[ 0 ].rid ).toBe( 'r4' ); // newest
	expect( v.entries[ 2 ].rid ).toBe( 'r2' ); // oldest still in cap
} );

test( 'enriches each row with seq, urlHash, timestamp and an even/odd flag', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'first', url: '/a', end_time: 111 } ) ) );
	v.fill( rowMsg( row( { rid: 'second', url: '/b', end_time: 222 } ) ) );
	expect( v.entries[ 0 ] ).toMatchObject( {
		seq: 2,
		rid: 'second',
		url: '/b',
		timestamp: 222,
		isEven: true,
	} );
	expect( typeof v.entries[ 0 ].urlHash ).toBe( 'string' );
	expect( v.entries[ 1 ] ).toMatchObject( {
		seq: 1,
		rid: 'first',
		timestamp: 111,
		isEven: false,
	} );
} );

test( 'exposes a numeric rps on the node instance', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row() ) );
	expect( typeof v.rps ).toBe( 'number' );
	expect( v.rps ).toBeGreaterThan( 0 );
} );

test( 'RPS tracking aggregates per second, not one entry per request (bounded window)', () => {
	// Perf contract: the requests/second window must NOT grow one entry per
	// request (the old `completedHistory.push`-per-request + full filter+reduce
	// was O(n) per request). A 10s window collapses to per-second buckets, so a
	// burst of 500 synchronous requests stays a handful of buckets — never 500.
	const v = makeView( 'requestlog:view', { maxEntries: 100000 } );
	for ( let i = 0; i < 500; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }`, url: `/p/${ i }` } ) ) );
	}
	expect( Array.isArray( v.rpsBuckets ) ).toBe( true );
	expect( v.rpsBuckets.length ).toBeLessThanOrEqual( 12 );
} );

test( 'a read mid-stream then more appends keeps newest-first across the coalesce boundary', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'a' } ) ) );
	v.fill( rowMsg( row( { rid: 'b' } ) ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'b', 'a' ] );
	v.fill( rowMsg( row( { rid: 'c' } ) ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'c', 'b', 'a' ] );
} );

test( 'exposes O(1) windowed reads — entriesCount + entryAt (newest-first) — for the virtual list', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'a' } ) ) );
	v.fill( rowMsg( row( { rid: 'b' } ) ) );
	v.fill( rowMsg( row( { rid: 'c' } ) ) );
	expect( v.entriesCount ).toBe( 3 );
	expect( v.entryAt( 0 ).rid ).toBe( 'c' ); // newest
	expect( v.entryAt( 2 ).rid ).toBe( 'a' ); // oldest
	expect( v.entryAt( 3 ) ).toBeUndefined();
} );

test( 'entryAt + entriesCount respect the cap (oldest overwritten) on a small ring', () => {
	const v = makeView( 'requestlog:view', { maxEntries: 3 } );
	for ( let i = 0; i < 10; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }` } ) ) );
	}
	expect( v.entriesCount ).toBe( 3 );
	expect( v.entryAt( 0 ).rid ).toBe( 'r9' ); // newest
	expect( v.entryAt( 2 ).rid ).toBe( 'r7' ); // oldest in cap
} );

test( 'pause stops appends and the published model reflects paused', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( row( { rid: 'ignored' } ) ) );
	expect( v.entries ).toHaveLength( 0 );
	expect( v.setStateCache.view.paused ).toBe( true );
} );

test( 'resume after pause lets rows through again', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( row( { rid: 'dropped' } ) ) );
	v.fill( controlMsg( { action: 'pause', paused: false } ) );
	v.fill( rowMsg( row( { rid: 'kept' } ) ) );
	expect( v.setStateCache.view.paused ).toBe( false );
	expect( v.entries ).toHaveLength( 1 );
	expect( v.entries[ 0 ].rid ).toBe( 'kept' );
} );

test( 'clear empties the buffer, counter and rps', () => {
	const v = makeView( 'requestlog:view' );
	for ( let i = 0; i < 10; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }` } ) ) );
	}
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.entries ).toHaveLength( 0 );
	expect( v.rps ).toBe( 0 );
	// Counter reset: the next row is seq 1 / odd again.
	v.fill( rowMsg( row( { rid: 'after' } ) ) );
	expect( v.entries[ 0 ].seq ).toBe( 1 );
	expect( v.entries[ 0 ].isEven ).toBe( false );
} );

test( 'the published model carries paused and connectionError', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: false } ) );
	expect( Object.keys( v.setStateCache.view ).sort() ).toEqual( [
		'connectionError',
		'paused',
	] );
} );

test( 'connection control publishes connectionError', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'a connectionError:false control clears the published flag', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'connection', connectionError: false } ) );
	expect( v.setStateCache.view.connectionError ).toBe( false );
} );

test( 'an unrelated control leaves connectionError untouched', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'publishes an initial view model on construction', () => {
	const v = makeView( 'requestlog:view' );
	expect( v.setStateCache.view ).toEqual( {
		paused: false,
		connectionError: false,
	} );
} );

test( 'names the node', () => {
	const v = makeView( 'requestlog:view' );
	expect( v.name ).toBe( 'requestlog:view' );
} );

// --- Defensive shaping inlined from the dropped requestlog:transform node. ---
// The view now consumes the raw envelope VALUE (a completed-request blob)
// directly from `_sse`, so the per-field defaults + url/user-agent clip live
// here. Mirrors what `transformCompletedLine` used to do.

test( 'drops a raw envelope whose VALUE has no url (defensive)', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( { rid: 'no-url' } ) );
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'drops a raw envelope whose VALUE is not an object', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( 'string' ) );
	v.fill( rowMsg( [ 1, 2, 3 ] ) );
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'clips url at 2000 chars + ellipsis when appending', () => {
	const v = makeView( 'requestlog:view' );
	const longUrl = 'https://x/' + 'a'.repeat( 5000 );
	v.fill(
		rowMsg( {
			rid: 'r-long',
			method: 'GET',
			url: longUrl,
			duration_ms: 1,
		} )
	);
	expect( v.entries ).toHaveLength( 1 );
	expect( v.entries[ 0 ].url.length ).toBe( 2003 );
	expect( v.entries[ 0 ].url.endsWith( '...' ) ).toBe( true );
} );

test( 'clips user_agent at 500 chars + ellipsis when appending', () => {
	const v = makeView( 'requestlog:view' );
	const longUA = 'a'.repeat( 1000 );
	v.fill(
		rowMsg( {
			rid: 'r-ua',
			method: 'GET',
			url: 'https://x',
			user_agent: longUA,
		} )
	);
	expect( v.entries ).toHaveLength( 1 );
	expect( v.entries[ 0 ].user_agent.length ).toBe( 503 );
	expect( v.entries[ 0 ].user_agent.endsWith( '...' ) ).toBe( true );
} );

test( 'fills sensible defaults for missing fields on the appended entry', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( { url: 'https://x' } ) );
	expect( v.entries ).toHaveLength( 1 );
	const e = v.entries[ 0 ];
	expect( e.rid ).toBe( '' );
	expect( e.method ).toBe( 'GET' );
	expect( e.duration_ms ).toBe( 0 );
	expect( e.status_code ).toBe( 0 );
	expect( e.remote_addr ).toBe( '' );
	expect( e.user_agent ).toBe( '' );
	expect( e.timestamp ).toBe( 0 );
} );

describe( 'requestlog:view — nodeSchema', () => {
	test( 'is a Hidden, terminal (no output port) node', () => {
		const schema = makeView( 'requestlog:view' ).constructor.nodeSchema();
		expect( schema.has_target ).toBe( false );
		expect( schema.category ).toBe( 'Hidden' );
		expect( typeof schema.description ).toBe( 'string' );
		expect( schema.description.length ).toBeGreaterThan( 0 );
		expect( schema.arguments ).toEqual( [] );
		expect( schema.commands ).toEqual( [] );
	} );
} );
