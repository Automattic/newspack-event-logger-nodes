/**
 * requestlog:view tests — the Request Log's LogStreamViewNode subclass.
 *
 * The shared base owns the ring (`lines`/`lineAt`/`linesCount`), the monotonic
 * `id` + `isEven` stamps, the paused belt + step budget, the decaying `lps`,
 * seek tracking, reply settling, and the shared control verbs; those have their
 * own suite in the substrate. Here we pin the subclass surface: `shapeRow`'s
 * defensive enrichment (+ the shared debug trio), the `select` seek arming,
 * and the published view model.
 */

import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import {
	KEY,
	VALUE,
	TYPE,
	ID,
	TM_STRUCT,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { LogStreamViewNode } from '@newspack-nodes/shared/nodes/log-stream-view-node';
import { RequestLogViewNode } from '../request-log-view-node';

// Naming registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// Construct + name directly (createX gone); bare-new + name= is the seam.
function makeView( name, opts = {} ) {
	const node = new RequestLogViewNode( opts.maxLines );
	node.name = name;
	return node;
}

// A completed-request envelope: TM_STRUCT, KEY=rid, VALUE=the raw summary.
function rowMsg( req ) {
	const { rid = '', ...value } = req;
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = rid;
	m[ VALUE ] = value;
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

test( 'extends the shared LogStreamViewNode base', () => {
	expect( makeView( 'requestlog:view' ) ).toBeInstanceOf( LogStreamViewNode );
} );

test( 'appends rows newest-first into lines (no publish)', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'a' } ) ) );
	v.fill( rowMsg( row( { rid: 'b' } ) ) );
	v.fill( rowMsg( row( { rid: 'c' } ) ) );
	expect( v.lines[ 0 ].rid ).toBe( 'c' ); // newest first
	expect( v.lines ).toHaveLength( 3 );
} );

test( 'appending rows does NOT publish setState (no per-row React re-render)', () => {
	const v = makeView( 'requestlog:view' );
	const spy = jest.spyOn( v, 'setState' );
	v.fill( rowMsg( row() ) );
	v.fill( rowMsg( row() ) );
	expect( spy ).not.toHaveBeenCalled();
} );

test( 'caps the ring at maxLines (newest kept)', () => {
	const v = makeView( 'requestlog:view', { maxLines: 3 } );
	for ( let i = 0; i < 5; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }` } ) ) );
	}
	expect( v.lines ).toHaveLength( 3 );
	expect( v.lines[ 0 ].rid ).toBe( 'r4' ); // newest
	expect( v.lines[ 2 ].rid ).toBe( 'r2' ); // oldest still in cap
} );

test( 'urlHash keeps the ?worker marker so nodes/ELN URLs deep-link (matches PHP url_hash)', () => {
	const v = makeView( 'requestlog:view' );
	v.fill(
		rowMsg( row( { rid: 'w', url: '/jobs/x?supervisor', end_time: 1 } ) )
	);
	// PHP url_hash hashes the full string incl. ?worker; don't strip at '?'.
	expect( v.lines[ 0 ].urlHash ).toBe( fnv1a( '/jobs/x?supervisor' ) );
	expect( v.lines[ 0 ].urlHash ).not.toBe( fnv1a( '/jobs/x' ) );
} );

test( 'stamps each row with the base monotonic id + isEven stripe', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'first', url: '/a', end_time: 111 } ) ) );
	v.fill( rowMsg( row( { rid: 'second', url: '/b', end_time: 222 } ) ) );
	expect( v.lines[ 0 ] ).toMatchObject( {
		id: 2,
		isEven: true,
		rid: 'second',
		url: '/b',
		timestamp: 222,
	} );
	expect( typeof v.lines[ 0 ].urlHash ).toBe( 'string' );
	expect( v.lines[ 1 ] ).toMatchObject( {
		id: 1,
		isEven: false,
		rid: 'first',
		timestamp: 111,
	} );
} );

test( 'carries the shared debug trio + a searchable content line on each row', () => {
	const v = makeView( 'requestlog:view' );
	const m = rowMsg(
		row( { rid: 'r-dbg-407', url: '/dbg-407', status_code: 503 } )
	);
	m[ ID ] = '7:120:30';
	v.fill( m );
	const shaped = v.lines[ 0 ];
	expect( shaped.msgId ).toBe( '7:120:30' );
	expect( shaped.key ).toBe( 'r-dbg-407' );
	expect( shaped.struct ).toBe( true );
	expect( JSON.parse( shaped.raw ) ).toMatchObject( {
		url: '/dbg-407',
		status_code: 503,
	} );
	expect( shaped.content ).toBe( 'GET /dbg-407 503 r-dbg-407' );
} );

test( 'clips the debug raw JSON at 8192 chars + ellipsis', () => {
	const v = makeView( 'requestlog:view' );
	v.fill(
		rowMsg(
			row( { rid: 'r-raw', url: '/raw', user_agent: 'u'.repeat( 9000 ) } )
		)
	);
	expect( v.lines[ 0 ].raw.length ).toBe( 8195 );
	expect( v.lines[ 0 ].raw.endsWith( '...' ) ).toBe( true );
} );

test( 'exposes a decaying lps rate on the node instance', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row() ) );
	expect( typeof v.lps ).toBe( 'number' );
	expect( v.lps ).toBeGreaterThan( 0 );
} );

test( 'exposes O(1) windowed reads — linesCount + lineAt (newest-first) — for the virtual list', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( row( { rid: 'a' } ) ) );
	v.fill( rowMsg( row( { rid: 'b' } ) ) );
	v.fill( rowMsg( row( { rid: 'c' } ) ) );
	expect( v.linesCount ).toBe( 3 );
	expect( v.lineAt( 0 ).rid ).toBe( 'c' ); // newest
	expect( v.lineAt( 2 ).rid ).toBe( 'a' ); // oldest
	expect( v.lineAt( 3 ) ).toBeUndefined();
} );

test( 'pause stops appends and the published model reflects paused', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( row( { rid: 'ignored' } ) ) );
	expect( v.lines ).toHaveLength( 0 );
	expect( v.setStateCache.view.paused ).toBe( true );
} );

test( 'a step budget admits exactly that many rows through the paused belt', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( controlMsg( { action: 'step', frames: 2 } ) );
	v.fill( rowMsg( row( { rid: 'stepped-1' } ) ) );
	v.fill( rowMsg( row( { rid: 'stepped-2' } ) ) );
	v.fill( rowMsg( row( { rid: 'dropped-3' } ) ) );
	expect( v.lines.map( ( e ) => e.rid ) ).toEqual( [
		'stepped-2',
		'stepped-1',
	] );
} );

test( 'resume after pause lets rows through again', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: true } ) );
	v.fill( rowMsg( row( { rid: 'dropped' } ) ) );
	v.fill( controlMsg( { action: 'pause', paused: false } ) );
	v.fill( rowMsg( row( { rid: 'kept' } ) ) );
	expect( v.setStateCache.view.paused ).toBe( false );
	expect( v.lines ).toHaveLength( 1 );
	expect( v.lines[ 0 ].rid ).toBe( 'kept' );
} );

test( 'clear empties the ring and resets the id counter', () => {
	const v = makeView( 'requestlog:view' );
	for ( let i = 0; i < 10; i++ ) {
		v.fill( rowMsg( row( { rid: `r${ i }` } ) ) );
	}
	v.fill( controlMsg( { action: 'clear' } ) );
	expect( v.lines ).toHaveLength( 0 );
	// Counter reset: the next row is id 1 again.
	v.fill( rowMsg( row( { rid: 'after' } ) ) );
	expect( v.lines[ 0 ].id ).toBe( 1 );
} );

test( 'the published model carries paused, connectionError, and seek feedback', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( controlMsg( { action: 'pause', paused: false } ) );
	expect( Object.keys( v.setStateCache.view ).sort() ).toEqual( [
		'connectionError',
		'lastReceivedSegment',
		'mode',
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
		mode: 'live',
		lastReceivedSegment: null,
	} );
} );

test( 'names the node', () => {
	const v = makeView( 'requestlog:view' );
	expect( v.name ).toBe( 'requestlog:view' );
} );

// Defensive shaping inlined from the dropped requestlog:transform node.

test( 'drops a raw envelope whose VALUE has no url (defensive)', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( { rid: 'no-url' } ) );
	expect( v.lines ).toHaveLength( 0 );
} );

test( 'drops a raw envelope whose VALUE is not an object', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( 'string' ) );
	v.fill( rowMsg( [ 1, 2, 3 ] ) );
	expect( v.lines ).toHaveLength( 0 );
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
	expect( v.lines ).toHaveLength( 1 );
	expect( v.lines[ 0 ].url.length ).toBe( 2003 );
	expect( v.lines[ 0 ].url.endsWith( '...' ) ).toBe( true );
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
	expect( v.lines ).toHaveLength( 1 );
	expect( v.lines[ 0 ].user_agent.length ).toBe( 503 );
	expect( v.lines[ 0 ].user_agent.endsWith( '...' ) ).toBe( true );
} );

test( 'fills sensible defaults for missing fields on the appended row', () => {
	const v = makeView( 'requestlog:view' );
	v.fill( rowMsg( { url: 'https://x' } ) );
	expect( v.lines ).toHaveLength( 1 );
	const e = v.lines[ 0 ];
	expect( e.rid ).toBe( '' );
	expect( e.method ).toBe( 'GET' );
	expect( e.duration_ms ).toBe( 0 );
	expect( e.status_code ).toBe( 0 );
	expect( e.remote_addr ).toBe( '' );
	expect( e.user_agent ).toBe( '' );
	expect( e.timestamp ).toBe( 0 );
} );

// A raw-logs catalog reply: TM_COMMAND|TM_RESPONSE, VALUE={ name, payload }.
const catalogReply = ( id, name, payload, { error = false } = {} ) => {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE | ( error ? TM_ERROR : 0 );
	m[ ID ] = id;
	m[ VALUE ] = { name, payload };
	return m;
};

describe( 'requestlog:view — catalog-reply correlation (PendingReplies)', () => {
	test( 'settles a pending reply by message ID and does NOT append it as a row', () => {
		const v = makeView( 'requestlog:view' );
		const seen = [];
		v.replies.add(
			'reqlog-op-801',
			( payload ) => seen.push( payload ),
			() => {}
		);
		v.fill(
			catalogReply( 'reqlog-op-801', 'log_status', {
				log_id: 'completed.p4',
				segments: [ { id: 5, size: 42 } ],
			} )
		);
		expect( seen ).toEqual( [
			{ log_id: 'completed.p4', segments: [ { id: 5, size: 42 } ] },
		] );
		expect( v.lines ).toHaveLength( 0 );
	} );

	test( 'a TM_ERROR reply rejects the pending Promise', async () => {
		const v = makeView( 'requestlog:view' );
		const promise = new Promise( ( resolve, reject ) =>
			v.replies.add( 'reqlog-op-802', resolve, reject )
		);
		v.fill(
			catalogReply( 'reqlog-op-802', 'list_logs', 'nope-802', {
				error: true,
			} )
		);
		await expect( promise ).rejects.toThrow( 'nope-802' );
	} );

	test( 'a completed-request row carrying a seek ID breadcrumb is still appended', () => {
		const v = makeView( 'requestlog:view' );
		const m = rowMsg( row( { rid: 'r-seek-803', url: '/seek-803' } ) );
		m[ ID ] = '9:1024:256';
		v.fill( m );
		expect( v.lines.map( ( e ) => e.rid ) ).toEqual( [ 'r-seek-803' ] );
	} );
} );

// Seek/live feedback: only meaningful while browsing ONE partition dir, so it is
// gated on `seekActive` (armed by a `select` control carrying a dir). Distinct
// values (segments 98/105, offsets 1200) so a silently-dropped change fails loud.
describe( 'requestlog:view — seek feedback (single-dir browse)', () => {
	const rowWithId = ( id, overrides = {} ) => {
		const m = rowMsg( row( overrides ) );
		m[ ID ] = id;
		return m;
	};

	test( 'does not track breadcrumbs under the glob-live default (seekActive off)', () => {
		const v = makeView( 'requestlog:view' );
		v.fill( rowWithId( '98:0:40' ) );
		expect( v.seekActive ).toBe( false );
		expect( v.lastReceivedSegment ).toBe( null );
		expect( v.mode ).toBe( 'live' );
	} );

	test( 'a select control with a dir arms tracking, clears the ring, and follows the segment', () => {
		const v = makeView( 'requestlog:view' );
		v.fill( rowMsg( row( { rid: 'pre-select' } ) ) );
		v.fill( controlMsg( { action: 'select', dir: 'completed.p4' } ) );
		expect( v.seekActive ).toBe( true );
		expect( v.lines ).toHaveLength( 0 );
		v.fill( rowWithId( '98:0:40' ) );
		expect( v.lastReceivedSegment ).toBe( 98 );
		expect( v.setStateCache.view.lastReceivedSegment ).toBe( 98 );
	} );

	test( 'a select control with an empty dir disarms tracking and resets it', () => {
		const v = makeView( 'requestlog:view' );
		v.fill( controlMsg( { action: 'select', dir: 'completed.p4' } ) );
		v.fill( rowWithId( '98:0:40' ) );
		v.fill( controlMsg( { action: 'select', dir: '' } ) );
		expect( v.seekActive ).toBe( false );
		expect( v.lastReceivedSegment ).toBe( null );
		v.fill( rowWithId( '77:0:40' ) );
		expect( v.lastReceivedSegment ).toBe( null );
	} );

	test( 'browse enters replay from a clean slate and flips to live at the end', () => {
		const v = makeView( 'requestlog:view' );
		v.fill( controlMsg( { action: 'select', dir: 'completed.p4' } ) );
		v.fill( rowWithId( '97:0:40', { rid: 'pre-browse' } ) );
		v.fill(
			controlMsg( { action: 'browse', endSegment: 105, endOffset: 1200 } )
		);
		// A rewind starts clean: replays must not mix into the live tail.
		expect( v.lines ).toHaveLength( 0 );
		expect( v.mode ).toBe( 'replay' );
		expect( v.setStateCache.view.mode ).toBe( 'replay' );
		v.fill( rowWithId( '98:100:20' ) ); // behind the end segment
		expect( v.mode ).toBe( 'replay' );
		v.fill( rowWithId( '105:1160:40' ) ); // 1160 + 40 = 1200 → caught up
		expect( v.mode ).toBe( 'live' );
		expect( v.setStateCache.view.mode ).toBe( 'live' );
	} );

	test( 'follow returns the view to live', () => {
		const v = makeView( 'requestlog:view' );
		v.fill( controlMsg( { action: 'select', dir: 'completed.p4' } ) );
		v.fill(
			controlMsg( { action: 'browse', endSegment: 105, endOffset: 1200 } )
		);
		v.fill( controlMsg( { action: 'follow' } ) );
		expect( v.mode ).toBe( 'live' );
	} );
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
