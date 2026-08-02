/**
 * perferrors:view tests — the Error Log's LogStreamViewNode subclass.
 *
 * The shared base owns the ring (`lines`/`lineAt`/`linesCount`), the monotonic
 * `id` + `isEven` stamps, the paused belt + step budget, the decaying `lps`,
 * seek tracking, reply settling, and the shared control verbs; those have their
 * own suite in the substrate. Here we pin the subclass surface: `shapeRow`'s
 * envelope validation + enrichment (KEY=rid, VALUE={ts, k, m, n, method, url},
 * plus the shared debug trio), the `select` seek arming, and the published
 * view model.
 */

import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import {
	KEY,
	VALUE,
	TYPE,
	ID,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { LogStreamViewNode } from '@newspack-nodes/shared/nodes/log-stream-view-node';
import { PerfErrorsViewNode } from '../perf-errors-view-node';

// Naming registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Construct + name directly — createX factory is gone; bare-new is the seam.
function makeView( name, opts = {} ) {
	const node = new PerfErrorsViewNode( opts.maxLines );
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

test( 'extends the shared LogStreamViewNode base', () => {
	expect( makeView( 'perferrors:view' ) ).toBeInstanceOf( LogStreamViewNode );
} );

test( 'appends rows newest-first with the base monotonic id, capped', () => {
	const v = makeView( 'perferrors:view', { maxLines: 2 } );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'warning', m: 'y' } ) );
	v.fill( envMsg( 'c', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.lines.map( ( e ) => e.rid ) ).toEqual( [ 'c', 'b' ] );
	expect( v.lines[ 0 ].id ).toBe( 3 );
	expect( v.lines[ 0 ].isEven ).toBe( false );
} );

test( 'exposes a decaying lps rate on the node instance', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	expect( typeof v.lps ).toBe( 'number' );
	expect( v.lps ).toBeGreaterThan( 0 );
} );

test( 'exposes O(1) windowed reads — linesCount + lineAt (newest-first) — for the virtual list', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'b', { ts: 2, k: 'error', m: 'y' } ) );
	v.fill( envMsg( 'c', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.linesCount ).toBe( 3 );
	expect( v.lineAt( 0 ).rid ).toBe( 'c' ); // newest
	expect( v.lineAt( 2 ).rid ).toBe( 'a' ); // oldest
	expect( v.lineAt( 3 ) ).toBeUndefined();
} );

test( 'lineAt + linesCount respect the cap (oldest overwritten) on a small ring', () => {
	const v = makeView( 'perferrors:view', { maxLines: 3 } );
	for ( let i = 0; i < 10; i++ ) {
		v.fill( envMsg( `r${ i }`, { ts: i, k: 'error', m: `m${ i }` } ) );
	}
	expect( v.linesCount ).toBe( 3 );
	expect( v.lineAt( 0 ).rid ).toBe( 'r9' ); // newest
	expect( v.lineAt( 2 ).rid ).toBe( 'r7' ); // oldest in cap
} );

test( 'enriches each row with rid, ts, k, m + the shared debug trio', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'first', { ts: 111, k: 'error', m: 'one' } ) );
	const m2 = envMsg( 'second', { ts: 222, k: 'warning', m: 'two' } );
	m2[ ID ] = '4:640:80';
	v.fill( m2 );
	expect( v.lines[ 0 ] ).toMatchObject( {
		id: 2,
		rid: 'second',
		ts: 222,
		k: 'warning',
		m: 'two',
		msgId: '4:640:80',
		key: 'second',
		struct: true,
	} );
	expect( JSON.parse( v.lines[ 0 ].raw ) ).toEqual( {
		ts: 222,
		k: 'warning',
		m: 'two',
	} );
	expect( v.lines[ 0 ].content ).toContain( 'second' );
	expect( v.lines[ 0 ].content ).toContain( 'warning' );
	expect( v.lines[ 0 ].content ).toContain( 'two' );
	expect( v.lines[ 1 ] ).toMatchObject( {
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

	expect( v.lines[ 0 ] ).toEqual(
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

	expect( v.lines[ 0 ].url ).toHaveLength( 2003 );
	expect( v.lines[ 0 ].urlHash ).toBe( fnv1a( url ) );
	expect( v.lines[ 0 ].urlHash ).not.toBe( fnv1a( v.lines[ 0 ].url ) );
} );

test( 'defaults missing optional VALUE fields (ts=0, k="", m="")', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', {} ) );
	expect( v.lines[ 0 ] ).toEqual(
		expect.objectContaining( { rid: 'rid', ts: 0, k: '', m: '' } )
	);
} );

test( 'clips long m at 1000 chars with ellipsis', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', { ts: 1, k: 'X', m: 'x'.repeat( 2000 ), n: 0 } ) );
	expect( v.lines[ 0 ].m.length ).toBe( 1003 );
	expect( v.lines[ 0 ].m.endsWith( '...' ) ).toBe( true );
} );

test( 'clips the debug raw JSON at 8192 chars + ellipsis', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid-raw', { ts: 1, k: 'X', m: 'y'.repeat( 9000 ) } ) );
	expect( v.lines[ 0 ].raw.length ).toBe( 8195 );
	expect( v.lines[ 0 ].raw.endsWith( '...' ) ).toBe( true );
} );

test( 'drops envelopes with no rid (KEY empty)', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( '', { ts: 1, k: 'error', m: 'x' } ) );
	expect( v.lines ).toHaveLength( 0 );
} );

test( 'drops envelopes whose VALUE is a string (not an object)', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', 'just a string' ) );
	expect( v.lines ).toHaveLength( 0 );
} );

test( 'drops envelopes whose VALUE is an array', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'rid', [ 1, 2, 3 ] ) );
	expect( v.lines ).toHaveLength( 0 );
} );

test( 'drops the `connected` sentinel (which the SseInNode would otherwise stream through)', () => {
	const v = makeView( 'perferrors:view' );
	// The `connected` sentinel must NOT land in the error buffer.
	v.fill( envMsg( 'connected', { slot: 0, partition: 0, pid: 1 } ) );
	expect( v.lines ).toHaveLength( 0 );
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
	expect( v.lines ).toHaveLength( 0 );
	expect( Core.node( 'perferrors:view' ).setStateCache.view.paused ).toBe(
		true
	);
} );

test( 'a step budget admits exactly that many rows through the paused belt', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( controlMsg( { action: 'step', frames: 2 } ) );
	v.fill( envMsg( 'stepped-1', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( envMsg( 'stepped-2', { ts: 2, k: 'error', m: 'y' } ) );
	v.fill( envMsg( 'dropped-3', { ts: 3, k: 'error', m: 'z' } ) );
	expect( v.lines.map( ( e ) => e.rid ) ).toEqual( [
		'stepped-2',
		'stepped-1',
	] );
} );

test( 'connection control publishes connectionError', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( controlMsg( { action: 'connection', connectionError: true } ) );
	expect(
		Core.node( 'perferrors:view' ).setStateCache.view.connectionError
	).toBe( true );
} );

test( 'clear empties the ring and resets the id counter', () => {
	const v = makeView( 'perferrors:view' );
	v.fill( envMsg( 'a', { ts: 1, k: 'error', m: 'x' } ) );
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.lines ).toHaveLength( 0 );
	// Counter reset: the next row is id 1 again.
	v.fill( envMsg( 'after', { ts: 2, k: 'error', m: 'y' } ) );
	expect( v.lines[ 0 ].id ).toBe( 1 );
} );

test( 'publishes an initial view model on construction', () => {
	const v = makeView( 'perferrors:view' );
	expect( v.setStateCache.view ).toEqual( {
		paused: false,
		connectionError: false,
		mode: 'live',
		lastReceivedSegment: null,
	} );
} );

test( 'names the node', () => {
	const v = makeView( 'perferrors:view' );
	expect( v.name ).toBe( 'perferrors:view' );
} );

// Seek/live feedback: only meaningful while browsing ONE partition dir, so it is
// gated on `seekActive` (armed by a `select` control carrying a dir). Distinct
// values (segments 98/105, offset 1200) so a silently-dropped change fails loud.
describe( 'perferrors:view — seek feedback (single-dir browse)', () => {
	const envWithId = ( id, rid = 'e1' ) => {
		const m = envMsg( rid, { ts: 1, k: 'error', m: 'x' } );
		m[ ID ] = id;
		return m;
	};

	test( 'does not track breadcrumbs under the glob-live default (seekActive off)', () => {
		const v = makeView( 'perferrors:view' );
		v.fill( envWithId( '98:0:40' ) );
		expect( v.seekActive ).toBe( false );
		expect( v.lastReceivedSegment ).toBe( null );
		expect( v.mode ).toBe( 'live' );
	} );

	test( 'a select control with a dir arms tracking, clears the ring, and follows the segment', () => {
		const v = makeView( 'perferrors:view' );
		v.fill( envMsg( 'pre-select', { ts: 1, k: 'error', m: 'x' } ) );
		v.fill( controlMsg( { action: 'select', dir: 'errors.p3' } ) );
		expect( v.seekActive ).toBe( true );
		expect( v.lines ).toHaveLength( 0 );
		v.fill( envWithId( '98:0:40' ) );
		expect( v.lastReceivedSegment ).toBe( 98 );
		expect( v.setStateCache.view.lastReceivedSegment ).toBe( 98 );
	} );

	test( 'a select control with an empty dir disarms tracking and resets it', () => {
		const v = makeView( 'perferrors:view' );
		v.fill( controlMsg( { action: 'select', dir: 'errors.p3' } ) );
		v.fill( envWithId( '98:0:40' ) );
		v.fill( controlMsg( { action: 'select', dir: '' } ) );
		expect( v.seekActive ).toBe( false );
		expect( v.lastReceivedSegment ).toBe( null );
		v.fill( envWithId( '77:0:40' ) );
		expect( v.lastReceivedSegment ).toBe( null );
	} );

	test( 'browse enters replay from a clean slate and flips to live at the end', () => {
		const v = makeView( 'perferrors:view' );
		v.fill( controlMsg( { action: 'select', dir: 'errors.p3' } ) );
		v.fill( envWithId( '97:0:40', 'pre-browse' ) );
		v.fill(
			controlMsg( { action: 'browse', endSegment: 105, endOffset: 1200 } )
		);
		// A rewind starts clean: replays must not mix into the live tail.
		expect( v.lines ).toHaveLength( 0 );
		expect( v.mode ).toBe( 'replay' );
		expect( v.setStateCache.view.mode ).toBe( 'replay' );
		v.fill( envWithId( '98:100:20' ) ); // behind the end segment
		expect( v.mode ).toBe( 'replay' );
		v.fill( envWithId( '105:1160:40' ) ); // 1160 + 40 = 1200 → caught up
		expect( v.mode ).toBe( 'live' );
		expect( v.setStateCache.view.mode ).toBe( 'live' );
	} );

	test( 'follow returns the view to live', () => {
		const v = makeView( 'perferrors:view' );
		v.fill( controlMsg( { action: 'select', dir: 'errors.p3' } ) );
		v.fill(
			controlMsg( { action: 'browse', endSegment: 105, endOffset: 1200 } )
		);
		v.fill( controlMsg( { action: 'follow' } ) );
		expect( v.mode ).toBe( 'live' );
	} );
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
