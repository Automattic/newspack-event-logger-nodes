/**
 * requestlog/view tests — owns the Request Log view model.
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
import { createRequestLogView } from '../requestLogView';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A row message from requestlog/transform: TM_STRUCT carrying the mapped row.
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
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( rowMsg( row( { rid: 'a' } ) ) );
	v.fill( rowMsg( row( { rid: 'b' } ) ) );
	v.fill( rowMsg( row( { rid: 'c' } ) ) );
	expect( v.entries[ 0 ].rid ).toBe( 'c' ); // newest first (unshift)
	expect( v.entries ).toHaveLength( 3 );
} );

test( 'appending rows does NOT publish setState (no per-row React re-render)', () => {
	const v = createRequestLogView( 'requestlog/view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( rowMsg( row() ) );
	v.fill( rowMsg( row() ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'caps the buffer at maxEntries (newest kept)', () => {
	const v = createRequestLogView( 'requestlog/view', { maxEntries: 3 } );
	for ( let i = 0; i < 5; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }` } ) ) );
	}
	expect( v.entries ).toHaveLength( 3 );
	expect( v.entries[ 0 ].rid ).toBe( 'r4' ); // newest
	expect( v.entries[ 2 ].rid ).toBe( 'r2' ); // oldest still in cap
} );

test( 'enriches each row with seq, urlHash, timestamp and an even/odd flag', () => {
	const v = createRequestLogView( 'requestlog/view' );
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
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( rowMsg( row() ) );
	expect( typeof v.rps ).toBe( 'number' );
	expect( v.rps ).toBeGreaterThan( 0 );
} );

test( 'touches lastEventTime on each appended row', () => {
	const v = createRequestLogView( 'requestlog/view' );
	expect( v.lastEventTime ).toBeNull();
	v.fill( rowMsg( row() ) );
	expect( typeof v.lastEventTime ).toBe( 'number' );
} );

test( 'pause stops appends and the published model reflects paused', () => {
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( row( { rid: 'ignored' } ) ) );
	expect( v.entries ).toHaveLength( 0 );
	expect( v.setStateCache.view.paused ).toBe( true );
} );

test( 'resume after pause lets rows through again', () => {
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( row( { rid: 'dropped' } ) ) );
	v.fill( controlMsg( { action: 'pause', paused: false } ) );
	v.fill( rowMsg( row( { rid: 'kept' } ) ) );
	expect( v.setStateCache.view.paused ).toBe( false );
	expect( v.entries ).toHaveLength( 1 );
	expect( v.entries[ 0 ].rid ).toBe( 'kept' );
} );

test( 'clear empties the buffer, counter and rps', () => {
	const v = createRequestLogView( 'requestlog/view' );
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
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( controlMsg( { action: 'pause', paused: false } ) );
	expect( Object.keys( v.setStateCache.view ).sort() ).toEqual( [
		'connectionError',
		'paused',
	] );
} );

test( 'connection control publishes connectionError', () => {
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'a connectionError:false control clears the published flag', () => {
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'connection', connectionError: false } ) );
	expect( v.setStateCache.view.connectionError ).toBe( false );
} );

test( 'an unrelated control leaves connectionError untouched', () => {
	const v = createRequestLogView( 'requestlog/view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	expect( v.setStateCache.view.connectionError ).toBe( true );
} );

test( 'publishes an initial view model on construction', () => {
	const v = createRequestLogView( 'requestlog/view' );
	expect( v.setStateCache.view ).toEqual( {
		paused: false,
		connectionError: false,
	} );
} );

test( 'names the node', () => {
	const v = createRequestLogView( 'requestlog/view' );
	expect( v.name ).toBe( 'requestlog/view' );
} );
