/**
 * perferrors:view tests — owns the Error Log view model.
 *
 * Two cadences (matching requestLogView): the HIGH-frequency error buffer
 * (node.entries) lives on the instance and is NOT published — the React view's
 * rAF reads it directly each frame. The LOW-frequency control model
 * ({ paused, connectionError }) publishes via setState('view', …).
 *
 * As of the chain collapse, `_sse` targets the view directly: fill() now
 * receives the raw 7-field envelope
 * (KEY=rid, VALUE={ts, k, m, n, method, url}) and shapes it into a row inline
 * (no perferrors:transform). Control messages still come HOOK-DIRECT from
 * useErrorLogGraph (VALUE.action set, no KEY) and re-publish.
 */

import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import {
	KEY,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { PerfErrorsViewNode } from '../perf-errors-view-node';

// Naming registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Construct + name directly — createX factory is gone; bare-new is the seam.
function makeView( name, opts = {} ) {
	const node = new PerfErrorsViewNode( opts.maxEntries );
	node.name = name;
	return node;
}

// An envelope as the wire delivers it: KEY=rid, VALUE={ts, k, m, n, method, url}.
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

test( 'appends rows newest-first with seq, capped', () => {
	const v = makeView( 'perferrors:view', { maxEntries: 2 } );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'warning', m: 'y' } ) );
	v.fill( envMsg( 'c', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'c', 'b' ] );
	expect( v.entries[ 0 ].seq ).toBe( 3 );
} );

test( 'filters envelopes before they enter the buffer', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'before-filter', { k: 'old', m: 'old' } ) );
	v.fill( controlMsg( { action: 'filter', filter: 'needle-317' } ) );
	v.fill( envMsg( 'miss', { k: 'other', m: 'other' } ) );
	v.fill( envMsg( 'needle-317-rid', { k: 'other', m: 'other' } ) );
	v.fill( envMsg( 'keyword-match', { k: 'NEEDLE-317', m: 'other' } ) );
	v.fill( envMsg( 'message-match', { k: 'other', m: 'needle-317 text' } ) );
	v.fill(
		envMsg( 'method-only-match', {
			k: 'other',
			m: 'other',
			method: 'NEEDLE-317',
			url: '/method-only',
		} )
	);
	v.fill(
		envMsg( 'url-match', {
			k: 'other',
			m: 'other',
			method: 'PATCH',
			url: '/NEEDLE-317/path',
		} )
	);

	expect( v.entries.map( ( entry ) => entry.rid ) ).toEqual( [
		'url-match',
		'message-match',
		'keyword-match',
		'needle-317-rid',
	] );
	expect( v.entries.map( ( entry ) => entry.seq ) ).toEqual( [ 4, 3, 2, 1 ] );
} );

test( 'rejects a non-string admission filter', () => {
	const v = makeView( 'perferrors:view' );
	expect( () =>
		v.fill( controlMsg( { action: 'filter', filter: { bad: 317 } } ) )
	).toThrow( 'error log filter must be a string' );
} );

test( 'RPS tracking aggregates per second, not one entry per error (bounded window)', () => {
	// Perf: the errors/sec window is per-second buckets, not one per error.
	const v = makeView( 'perferrors:view', { maxEntries: 100000 } );
	for ( let i = 0; i < 500; i++ ) {
		v.fill( envMsg( `r${ i }`, { ts: i, k: 'error', m: `m${ i }` } ) );
	}
	expect( Array.isArray( v.rpsBuckets ) ).toBe( true );
	expect( v.rpsBuckets.length ).toBeLessThanOrEqual( 12 );
} );

test( 'a read mid-stream then more appends keeps newest-first across the coalesce boundary', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'error', m: 'y' } ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'b', 'a' ] );
	v.fill( envMsg( 'c', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.entries.map( ( e ) => e.rid ) ).toEqual( [ 'c', 'b', 'a' ] );
} );

test( 'exposes O(1) windowed reads — entriesCount + entryAt (newest-first) — for the virtual list', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'error', m: 'y' } ) );
	v.fill( envMsg( 'c', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.entriesCount ).toBe( 3 );
	expect( v.entryAt( 0 ).rid ).toBe( 'c' ); // newest
	expect( v.entryAt( 2 ).rid ).toBe( 'a' ); // oldest
	expect( v.entryAt( 3 ) ).toBeUndefined();
} );

test( 'entryAt + entriesCount respect the cap (oldest overwritten) on a small ring', () => {
	const v = makeView( 'perferrors:view', { maxEntries: 3 } );
	for ( let i = 0; i < 10; i++ ) {
		v.fill( envMsg( `r${ i }`, { ts: i, k: 'error', m: `m${ i }` } ) );
	}
	expect( v.entriesCount ).toBe( 3 );
	expect( v.entryAt( 0 ).rid ).toBe( 'r9' ); // newest
	expect( v.entryAt( 2 ).rid ).toBe( 'r7' ); // oldest in cap
} );

test( 'enriches each row with seq, id (= seq), rid, ts, k, and m', () => {
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
	} );
	expect( v.entries[ 1 ] ).toEqual( {
		seq: 1,
		id: 1,
		rid: 'first',
		ts: 111,
		k: 'error',
		m: 'one',
	} );
} );

test( 'retains request URL context and hashes it for the URL detail link', () => {
	const v = makeView( 'perferrors:view' );
	const url = '/error-context-731?errors-worker-731';
	v.fill(
		envMsg( 'url-context-rid-731', {
			ts: 731,
			k: 'error',
			m: 'sentinel failure 731',
			method: 'PATCH',
			url,
		} )
	);

	expect( v.entries[ 0 ] ).toEqual(
		expect.objectContaining( {
			method: 'PATCH',
			url,
			urlHash: fnv1a( url ),
		} )
	);
} );

test( 'hashes the full request URL while clipping only its display value', () => {
	const v = makeView( 'perferrors:view' );
	const url = `/long-error-url-731/${ 'x731'.repeat(
		525
	) }?errors-worker-731`;
	v.fill(
		envMsg( 'long-url-rid-731', {
			k: 'error',
			m: 'long URL failure 731',
			method: 'PATCH',
			url,
		} )
	);

	expect( v.entries[ 0 ].url ).toHaveLength( 2003 );
	expect( v.entries[ 0 ].urlHash ).toBe( fnv1a( url ) );
	expect( v.entries[ 0 ].urlHash ).not.toBe( fnv1a( v.entries[ 0 ].url ) );
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
	// The `connected` sentinel must NOT land in the error buffer.
	v.fill( envMsg( 'connected', { slot: 0, partition: 0, pid: 1 } ) );
	// Either dropped or ignored — either way entries buffer stays empty.
	expect( v.entries ).toHaveLength( 0 );
} );

test( 'appending rows does NOT publish setState (no per-row React re-render)', () => {
	const v = makeView( 'perferrors:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'error', m: 'y' } ) );
	expect( spy ).not.toHaveBeenCalled();
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
	// Counter reset: the next row is seq 1 again.
	v.fill( envMsg( 'after', { ts: 2, k: 'error', m: 'y' } ) );
	expect( v.entries[ 0 ].seq ).toBe( 1 );
} );

test( 'publishes an initial view model on construction', () => {
	const v = makeView( 'perferrors:view' );
	expect( v.setStateCache.view ).toEqual( {
		paused: false,
		connectionError: false,
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
