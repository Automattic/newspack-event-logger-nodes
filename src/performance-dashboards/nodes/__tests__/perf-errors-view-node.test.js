/**
 * perferrors:view tests — owns the Error Log view model.
 *
 * Two cadences (matching requestLogView): the HIGH-frequency error buffer
 * (node.entries) lives on the instance and is NOT published — the React view's
 * rAF reads it directly each frame. The LOW-frequency control model
 * ({ paused, connectionError, lastEventTime }) publishes via setState('view', …).
 *
 * As of the chain collapse, `_sse` targets the view directly: fill() now
 * receives the raw 7-field envelope (KEY=rid, VALUE={ts, k, m, n}) and shapes
 * it into a row inline (no perferrors:transform). Control messages still come
 * HOOK-DIRECT from useErrorLogGraph (VALUE.action set, no KEY) and re-publish.
 */

import {
	KEY,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from '../perf-errors-view-node';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Construct + name the node directly — the createX factory is gone (make_node
// builds it in production); bare-new + setName is the test seam.
function makeView( name, opts = {} ) {
	const node = new PerfErrorsViewNode( opts.maxEntries );
	node.setName( name );
	return node;
}

// An envelope as the wire delivers it: KEY=rid, VALUE={ts, k, m, n}.
const envMsg = ( rid, value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
};

// A control message: VALUE = { action, … }, KEY left empty.
const controlMsg = ( payload ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = payload;
	return m;
};

test( 'appends rows newest-first with seq + isEven, capped', () => {
	const v = makeView( 'perferrors:view', { maxEntries: 2 } );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'warning', m: 'y' } ) );
	v.fill( envMsg( 'c', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'c', 'b' ] );
	expect( v.entries[ 0 ].seq ).toBe( 3 );
} );

test( 'enriches each row with seq, id (= seq), rid, ts, k, m and an even/odd flag', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'first', { ts: 111, k: 'error', m: 'one' } ) );
	v.fill( envMsg( 'second', { ts: 222, k: 'warning', m: 'two' } ) );
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

test( 'defaults missing optional VALUE fields (ts=0, k="", m="")', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', {} ) );
	expect( v.entries[ 0 ] ).toEqual(
		expect.objectContaining( { rid: 'rid', ts: 0, k: '', m: '' } )
	);
} );

test( 'clips long m at 1000 chars with ellipsis', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', { ts: 1, k: 'X', m: 'x'.repeat( 2000 ), n: 0 } ) );
	expect( v.entries[ 0 ].m.length ).toBe( 1003 );
	expect( v.entries[ 0 ].m.endsWith( '...' ) ).toBe( true );
} );

test( 'drops envelopes with no rid (KEY empty)', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( '', { ts: 1, k: 'error', m: 'x' } ) );
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'drops envelopes whose VALUE is a string (not an object)', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', 'just a string' ) );
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'drops envelopes whose VALUE is an array', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', [ 1, 2, 3 ] ) );
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'drops the `connected` sentinel (which the SseInNode would otherwise stream through)', () => {
	const v = makeView( 'perferrors:view' );
	// The connected sentinel uses KEY='connected' with a structured VALUE
	// (slot/partition/pid). It must NOT land in the error buffer.
	v.fill( envMsg( 'connected', { slot: 0, partition: 0, pid: 1 } ) );
	// Allowed to be either dropped outright or treated as "not an error row".
	// Either way the entries buffer must remain empty.
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'appending rows does NOT publish setState (no per-row React re-render)', () => {
	const v = makeView( 'perferrors:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'error', m: 'y' } ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'touches lastEventTime on each appended row', () => {
	const v = makeView( 'perferrors:view' );
	expect( v.lastEventTime ).toBeNull();
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	expect( typeof v.lastEventTime ).toBe( 'number' );
} );

test( 'pause stops appends and publishes paused', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	expect( v.entries ).toHaveLength( 0 );
	expect( Core.node( 'perferrors:view' ).setStateCache.view.paused ).toBe(
		true
	);
} );

test( 'connection control publishes connectionError', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect(
		Core.node( 'perferrors:view' ).setStateCache.view.connectionError
	).toBe( true );
} );

test( 'clear empties the buffer', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.entries ).toHaveLength( 0 );
	// Counter reset: the next row is seq 1 / odd again.
	v.fill( envMsg( 'after', { ts: 2, k: 'error', m: 'y' } ) );
	expect( v.entries[ 0 ].seq ).toBe( 1 );
	expect( v.entries[ 0 ].isEven ).toBe( false );
} );

test( 'publishes an initial view model on construction', () => {
	const v = makeView( 'perferrors:view' );
	expect( v.setStateCache.view ).toEqual( {
		paused: false,
		connectionError: false,
		lastEventTime: null,
	} );
} );

test( 'names the node', () => {
	const v = makeView( 'perferrors:view' );
	expect( v.name ).toBe( 'perferrors:view' );
} );

describe( 'perferrors:view — nodeSchema', () => {
	test( 'is a Hidden, terminal (no output port) node', () => {
		const schema = makeView( 'perferrors:view' ).constructor.nodeSchema();
		expect( schema.has_target ).toBe( false );
		expect( schema.category ).toBe( 'Hidden' );
		expect( typeof schema.description ).toBe( 'string' );
		expect( schema.description.length ).toBeGreaterThan( 0 );
		expect( schema.arguments ).toEqual( [] );
		expect( schema.commands ).toEqual( [] );
	} );
} );
