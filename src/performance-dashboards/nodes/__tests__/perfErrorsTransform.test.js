/**
 * perferrors/transform tests — wraps transformErrorLine, dropping the
 * `connected` sentinel and any envelope the transform rejects (no rid), and
 * emits a fresh TM_STRUCT row to its sink.
 */

import {
	KEY,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createPerfErrorsTransform } from '../perfErrorsTransform';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A 7-field error envelope: KEY=rid, VALUE=value object.
const env = ( rid, value ) => {
	const m = newMessage();
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
};

test( 'drops the connected sentinel', () => {
	const t = createPerfErrorsTransform( 'perferrors/transform' );
	const got = [];
	t.sink = { fill: ( m ) => got.push( m ) };
	t.fill( env( 'connected', { slot: 0 } ) );
	expect( got ).toHaveLength( 0 );
} );

test( 'emits a TM_STRUCT row for a valid error envelope', () => {
	const t = createPerfErrorsTransform( 'perferrors/transform' );
	const got = [];
	t.sink = { fill: ( m ) => got.push( m ) };
	t.fill( env( 'rid-1', { ts: 5, k: 'error', m: 'boom', n: 2 } ) );
	expect( got ).toHaveLength( 1 );
	expect( got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
	expect( got[ 0 ][ VALUE ] ).toEqual( {
		rid: 'rid-1',
		ts: 5,
		k: 'error',
		m: 'boom',
		n: 2,
	} );
} );

test( 'drops an envelope transformErrorLine rejects (no rid)', () => {
	const t = createPerfErrorsTransform( 'perferrors/transform' );
	const got = [];
	t.sink = { fill: ( m ) => got.push( m ) };
	t.fill( env( '', { ts: 1 } ) );
	expect( got ).toHaveLength( 0 );
} );

test( 'does not throw when sink is unset', () => {
	const t = createPerfErrorsTransform( 'perferrors/transform' );
	expect( () =>
		t.fill( env( 'rid-2', { ts: 1, k: 'error', m: 'x' } ) )
	).not.toThrow();
} );

test( 'names the node', () => {
	const t = createPerfErrorsTransform( 'perferrors/transform' );
	expect( t.name ).toBe( 'perferrors/transform' );
} );
