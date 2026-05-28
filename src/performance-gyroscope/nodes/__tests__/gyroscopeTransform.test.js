/**
 * gyroscope:transform tests — wraps transformGyroscopeLine, dropping the
 * `connected` sentinel and any envelope the transform rejects (returns null for
 * non-gyroscope / unrecognized shapes), and emits a fresh TM_STRUCT message
 * carrying the dispatch object ({type:'inflight',requests} | {type:'complete',
 * request}) to its sink.
 */

import {
	KEY,
	TO,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createGyroscopeTransform } from '../gyroscopeTransform';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Capture sink: a minimal node whose fill() records every message it receives.
function capture() {
	const got = [];
	return { node: { fill: ( m ) => got.push( m ) }, got };
}

// Build a gyroscope envelope (KEY + VALUE) as the wire would deliver it.
function envelope( key, value ) {
	const env = newMessage();
	env[ KEY ] = key;
	env[ VALUE ] = value;
	return env;
}

test( 'emits an inflight dispatch for a KEY="inflight" + array-VALUE envelope', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.fill(
		envelope( 'inflight', [
			{ rid: 'a', method: 'GET', url: '/x', state: 'process' },
			{ rid: 'b', method: 'POST', url: '/y', state: 'query' },
		] )
	);
	expect( sink.got ).toHaveLength( 1 );
	expect( sink.got[ 0 ][ VALUE ] ).toMatchObject( { type: 'inflight' } );
	expect( sink.got[ 0 ][ VALUE ].requests ).toHaveLength( 2 );
	expect( sink.got[ 0 ][ VALUE ].requests[ 0 ].rid ).toBe( 'a' );
} );

test( 'emits a complete dispatch for a single-object VALUE with rid', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.fill(
		envelope( 'rid-1', {
			rid: 'rid-1',
			method: 'POST',
			url: '/done',
			duration_ms: 42,
			status_code: 200,
		} )
	);
	expect( sink.got ).toHaveLength( 1 );
	expect( sink.got[ 0 ][ VALUE ] ).toMatchObject( { type: 'complete' } );
	expect( sink.got[ 0 ][ VALUE ].request.rid ).toBe( 'rid-1' );
} );

test( 'the emitted message is TM_STRUCT', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.fill( envelope( 'rid-2', { rid: 'rid-2', url: '/x' } ) );
	expect( sink.got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
} );

test( 'the emitted message is stamped TO the node target', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.target = 'gyroscope:view';
	t.fill( envelope( 'rid-2', { rid: 'rid-2', url: '/x' } ) );
	expect( sink.got[ 0 ][ TO ] ).toBe( 'gyroscope:view' );
} );

test( 'drops the connected sentinel', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.fill( envelope( 'connected', { slot: 0 } ) );
	expect( sink.got ).toHaveLength( 0 );
} );

test( 'drops an unrecognized envelope whose transform returns null', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.fill( envelope( 'x', 'a-string' ) );
	expect( sink.got ).toHaveLength( 0 );
} );

test( 'drops an object VALUE missing rid', () => {
	const sink = capture();
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	t.sink = sink.node;
	t.fill( envelope( 'x', { method: 'GET', url: '/y' } ) );
	expect( sink.got ).toHaveLength( 0 );
} );

test( 'does not throw when sink is unset', () => {
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	expect( () =>
		t.fill( envelope( 'rid', { rid: 'rid', url: '/x' } ) )
	).not.toThrow();
} );

test( 'names the node', () => {
	const t = createGyroscopeTransform( 'gyroscope:transform' );
	expect( t.name ).toBe( 'gyroscope:transform' );
} );
