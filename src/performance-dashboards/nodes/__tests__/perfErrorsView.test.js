/**
 * perferrors:view tests — owns the Error Log view model.
 *
 * Two cadences (matching requestLogView): the HIGH-frequency error buffer
 * (node.entries) lives on the instance and is NOT published — the React view's
 * rAF reads it directly each frame. The LOW-frequency control model
 * ({ paused, connectionError, lastEventTime }) publishes via setState('view', …).
 */

import {
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createPerfErrorsView } from '../perfErrorsView';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A row message from perferrors:transform: TM_STRUCT carrying the mapped row.
const rowMsg = ( row ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = row;
	return m;
};

// A control message: TM_STRUCT carrying { action, ... }.
const controlMsg = ( payload ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = payload;
	return m;
};

test( 'appends rows newest-first with seq + isEven, capped', () => {
	const v = createPerfErrorsView( 'perferrors:view', { maxEntries: 2 } );
	v.fill( rowMsg( { rid: 'a', ts: 1, k: 'error', m: 'x' } ) );
	v.fill( rowMsg( { rid: 'b', ts: 2, k: 'warning', m: 'y' } ) );
	v.fill( rowMsg( { rid: 'c', ts: 3, k: 'error', m: 'z' } ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'c', 'b' ] );
	expect( v.entries[ 0 ].seq ).toBe( 3 );
} );

test( 'enriches each row with seq, id (= seq), rid, ts, k, m and an even/odd flag', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	v.fill( rowMsg( { rid: 'first', ts: 111, k: 'error', m: 'one' } ) );
	v.fill( rowMsg( { rid: 'second', ts: 222, k: 'warning', m: 'two' } ) );
	expect( v.entries[ 0 ] ).toEqual( {
		seq: 2,
		id: 2,
		rid: 'second',
		ts: 222,
		k: 'warning',
		m: 'two',
		isEven: true,
	} );
	expect( v.entries[ 1 ] ).toEqual( {
		seq: 1,
		id: 1,
		rid: 'first',
		ts: 111,
		k: 'error',
		m: 'one',
		isEven: false,
	} );
} );

test( 'appending rows does NOT publish setState (no per-row React re-render)', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( rowMsg( { rid: 'a', ts: 1, k: 'error', m: 'x' } ) );
	v.fill( rowMsg( { rid: 'b', ts: 2, k: 'error', m: 'y' } ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'touches lastEventTime on each appended row', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	expect( v.lastEventTime ).toBeNull();
	v.fill( rowMsg( { rid: 'a', ts: 1, k: 'error', m: 'x' } ) );
	expect( typeof v.lastEventTime ).toBe( 'number' );
} );

test( 'pause stops appends and publishes paused', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( { rid: 'a', ts: 1, k: 'error', m: 'x' } ) );
	expect( v.entries ).toHaveLength( 0 );
	expect( Core.node( 'perferrors:view' ).setStateCache.view.paused ).toBe(
		true
	);
} );

test( 'connection control publishes connectionError', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect(
		Core.node( 'perferrors:view' ).setStateCache.view.connectionError
	).toBe( true );
} );

test( 'clear empties the buffer', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	v.fill( rowMsg( { rid: 'a', ts: 1, k: 'error', m: 'x' } ) );
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.entries ).toHaveLength( 0 );
	// Counter reset: the next row is seq 1 / odd again.
	v.fill( rowMsg( { rid: 'after', ts: 2, k: 'error', m: 'y' } ) );
	expect( v.entries[ 0 ].seq ).toBe( 1 );
	expect( v.entries[ 0 ].isEven ).toBe( false );
} );

test( 'publishes an initial view model on construction', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	expect( v.setStateCache.view ).toEqual( {
		paused: false,
		connectionError: false,
		lastEventTime: null,
	} );
} );

test( 'names the node', () => {
	const v = createPerfErrorsView( 'perferrors:view' );
	expect( v.name ).toBe( 'perferrors:view' );
} );
