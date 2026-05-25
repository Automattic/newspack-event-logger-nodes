/**
 * requestlog/transform tests — wraps transformCompletedLine, dropping the
 * `connected` sentinel and any envelope the transform rejects (no url), and
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
import { createRequestLogTransform } from '../requestLogTransform';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Capture sink: a minimal node whose fill() records every message it receives.
function capture() {
	const got = [];
	return { node: { fill: ( m ) => got.push( m ) }, got };
}

// Build a completed-request envelope (KEY='completed', VALUE=req object).
function completedEnvelope( req ) {
	const env = newMessage();
	env[ KEY ] = 'completed';
	env[ VALUE ] = req;
	return env;
}

test( 'emits one row message for a completed-request envelope', () => {
	const sink = capture();
	const t = createRequestLogTransform( 'requestlog/transform' );
	t.sink = sink.node;
	t.fill(
		completedEnvelope( {
			rid: 'r1',
			url: '/foo',
			method: 'GET',
			status_code: 200,
			duration_ms: 50,
			end_time: 1748960000,
		} )
	);
	expect( sink.got ).toHaveLength( 1 );
	expect( sink.got[ 0 ][ VALUE ] ).toMatchObject( {
		rid: 'r1',
		url: '/foo',
	} );
} );

test( 'emitted row message is TM_STRUCT carrying the mapped row', () => {
	const sink = capture();
	const t = createRequestLogTransform( 'requestlog/transform' );
	t.sink = sink.node;
	t.fill(
		completedEnvelope( {
			rid: 'r2',
			url: 'https://example.com/x',
			method: 'POST',
			status_code: 201,
			duration_ms: 12,
			end_time: 5,
		} )
	);
	expect( sink.got[ 0 ][ TYPE ] ).toBe( TM_STRUCT );
	expect( sink.got[ 0 ][ VALUE ] ).toMatchObject( {
		rid: 'r2',
		method: 'POST',
		url: 'https://example.com/x',
		status_code: 201,
		duration_ms: 12,
		end_time: 5,
	} );
} );

test( 'drops the connected sentinel', () => {
	const sink = capture();
	const t = createRequestLogTransform( 'requestlog/transform' );
	t.sink = sink.node;
	const env = newMessage();
	env[ KEY ] = 'connected';
	env[ VALUE ] = { slot: 0 };
	t.fill( env );
	expect( sink.got ).toHaveLength( 0 );
} );

test( 'drops a malformed envelope whose transform returns null (no url)', () => {
	const sink = capture();
	const t = createRequestLogTransform( 'requestlog/transform' );
	t.sink = sink.node;
	t.fill( completedEnvelope( { rid: 'no-url' } ) );
	expect( sink.got ).toHaveLength( 0 );
} );

test( 'does not throw when sink is unset', () => {
	const t = createRequestLogTransform( 'requestlog/transform' );
	expect( () =>
		t.fill( completedEnvelope( { rid: 'r', url: '/x' } ) )
	).not.toThrow();
} );

test( 'names the node', () => {
	const t = createRequestLogTransform( 'requestlog/transform' );
	expect( t.name ).toBe( 'requestlog/transform' );
} );
