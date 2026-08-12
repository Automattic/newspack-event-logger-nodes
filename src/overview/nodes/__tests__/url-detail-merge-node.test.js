/**
 * UrlDetailMergeNode tests — the net-new transform Node that hosts the
 * url_detail incremental-merge + last_modified dedup on the receiver-Tee → view
 * graph EDGE (the addSliceFetcher `transform` slot), out of view state.
 *
 * It receives the raw command reply (VALUE = { name, payload } where payload is
 * the url_detail object), merges the new payload against the payload it last
 * forwarded, and forwards a message whose VALUE.payload is the merged object —
 * EXCEPT when last_modified is unchanged from the prior forward, in which case it
 * drops the message (no republish, matching the old _mergeUrlDetail no-op).
 *
 * The merge logic is lifted verbatim from performance-view-node's _mergeUrlDetail:
 *   - first reply (no prior state): forwards as-is, records last_modified;
 *   - unchanged last_modified: DROP (no forward);
 *   - changed last_modified: dedup new requests by rid, prepend newest-first,
 *     cap 500, forward the merged payload.
 *
 * A clear control (TM_STRUCT { action:'clear' }) resets the retained state so the
 * next reply is treated as fresh (modal close → reopen).
 */

import {
	VALUE,
	TYPE,
	FROM,
	TM_COMMAND,
	TM_RESPONSE,
	TM_STRUCT,
	newMessage,
	Core,
	Node,
	CommandInterpreterNode,
} from '@newspack-nodes/runtime';
import { UrlDetailMergeNode } from '../url-detail-merge-node';

// A sink that records forwarded messages for assertions.
class RecordingSink extends Node {
	constructor() {
		super();
		this.received = [];
	}
	fill( message ) {
		this.received.push( message );
	}
}

beforeEach( () => Core.reset() );

// Build the transform wired to a recording sink (the view stand-in).
function makeMerge() {
	const node = new UrlDetailMergeNode();
	node.name = 'urlDetail:merge';
	// What the graph does: the dashboard drives controls under the node's name.
	node.controlFrom = 'urlDetail:merge';
	const sink = new RecordingSink();
	sink.name = '_recv';
	node.sink = sink;
	return { node, sink };
}

// A command-reply message carrying a url_detail payload.
function reply( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
	m[ VALUE ] = { name: 'url_detail', payload };
	return m;
}

// The published payload of the Nth forwarded message.
const forwardedPayload = ( sink, n = 0 ) => sink.received[ n ][ VALUE ].payload;

describe( 'UrlDetailMergeNode — registration', () => {
	test( 'is registerable + makeNode-able under the interpreter', () => {
		CommandInterpreterNode.registerNodeClasses( {
			UrlDetailMerge: UrlDetailMergeNode,
		} );
		const interpreter = new CommandInterpreterNode();
		interpreter.name = '_command_interpreter';
		const node = interpreter.makeNode(
			'UrlDetailMerge',
			'urlDetail:merge'
		);
		expect( node ).toBeInstanceOf( UrlDetailMergeNode );
		expect( node.sink ).toBe( interpreter );
	} );
} );

describe( 'UrlDetailMergeNode — first reply', () => {
	test( 'forwards the first payload as-is and records its last_modified', () => {
		const { node, sink } = makeMerge();
		const data = {
			last_modified: 10,
			requests: [ { rid: 'a', timestamp: 1 } ],
		};
		node.fill( reply( data ) );
		expect( sink.received ).toHaveLength( 1 );
		expect( forwardedPayload( sink ) ).toEqual( data );
	} );

	test( 'an empty/null payload is a no-op (no forward)', () => {
		const { node, sink } = makeMerge();
		node.fill( reply( null ) );
		expect( sink.received ).toHaveLength( 0 );
	} );
} );

describe( 'UrlDetailMergeNode — unchanged last_modified dedup', () => {
	test( 'drops a reply whose last_modified is unchanged from the prior forward', () => {
		const { node, sink } = makeMerge();
		node.fill( reply( { last_modified: 5, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 1 );
		// Same last_modified → no republish.
		node.fill( reply( { last_modified: 5, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 1 );
	} );
} );

describe( 'UrlDetailMergeNode — incremental merge on change', () => {
	test( 'dedups new requests by rid, prepends newest-first, caps 500', () => {
		const { node, sink } = makeMerge();
		// Seed with two requests.
		node.fill(
			reply( {
				last_modified: 1,
				requests: [
					{ rid: 'a', timestamp: 100 },
					{ rid: 'b', timestamp: 90 },
				],
			} )
		);
		// A newer payload: one repeat (a) + one new (c, newest).
		node.fill(
			reply( {
				last_modified: 2,
				requests: [
					{ rid: 'c', timestamp: 110 },
					{ rid: 'a', timestamp: 100 },
				],
			} )
		);
		expect( sink.received ).toHaveLength( 2 );
		const merged = forwardedPayload( sink, 1 );
		// c (new, newest) prepended; a + b retained; a NOT duplicated.
		expect( merged.requests.map( ( r ) => r.rid ) ).toEqual( [
			'c',
			'a',
			'b',
		] );
	} );

	test( 'a changed last_modified with no NEW rids keeps the prior request list', () => {
		const { node, sink } = makeMerge();
		node.fill(
			reply( {
				last_modified: 1,
				requests: [ { rid: 'a', timestamp: 1 } ],
			} )
		);
		node.fill(
			reply( {
				last_modified: 2,
				requests: [ { rid: 'a', timestamp: 1 } ],
			} )
		);
		expect( sink.received ).toHaveLength( 2 );
		// New last_modified forwarded, but request list unchanged (only 'a').
		const merged = forwardedPayload( sink, 1 );
		expect( merged.last_modified ).toBe( 2 );
		expect( merged.requests.map( ( r ) => r.rid ) ).toEqual( [ 'a' ] );
	} );

	test( 'caps the merged request list at 500 newest-first', () => {
		const { node, sink } = makeMerge();
		const prev = [];
		for ( let i = 0; i < 400; i++ ) {
			prev.push( { rid: `p${ i }`, timestamp: i } );
		}
		node.fill( reply( { last_modified: 1, requests: prev } ) );
		const next = [];
		for ( let i = 0; i < 200; i++ ) {
			next.push( { rid: `n${ i }`, timestamp: 100000 + i } );
		}
		node.fill( reply( { last_modified: 2, requests: next } ) );
		const merged = forwardedPayload( sink, 1 );
		expect( merged.requests ).toHaveLength( 500 );
		// Newest-first: the 200 new ones (highest timestamps) lead.
		expect( merged.requests[ 0 ].rid ).toBe( 'n199' );
	} );
} );

describe( 'UrlDetailMergeNode — clear resets retained state', () => {
	test( 'after a clear, the next reply is treated as fresh (forwards even on same last_modified)', () => {
		const { node, sink } = makeMerge();
		node.fill( reply( { last_modified: 7, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 1 );

		const clear = newMessage();
		clear[ TYPE ] = TM_STRUCT;
		clear[ FROM ] = 'urlDetail:merge';
		clear[ VALUE ] = { action: 'clear' };
		node.fill( clear );

		// Same last_modified as before the clear, but state reset → forwards.
		node.fill( reply( { last_modified: 7, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 2 );
	} );
} );

// A control is recognised by its FROM; an `action` field means nothing.
describe( 'UrlDetailMergeNode — control origin', () => {
	test( 'a reply from another origin is never applied as a clear', () => {
		const { node, sink } = makeMerge();
		node.fill( reply( { last_modified: 7, requests: [ { rid: 'a' } ] } ) );

		const impostor = newMessage();
		impostor[ TYPE ] = TM_STRUCT;
		impostor[ FROM ] = 'urldetail:in';
		impostor[ VALUE ] = { action: 'clear' };
		node.fill( impostor );

		// The retained last_modified survived: an unchanged reply still drops.
		node.fill( reply( { last_modified: 7, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 1 );
	} );
} );

// A control is routed by origin alone; `action` picks the verb once inside.
// ANDing the origin with a shape test let an unknown verb from the trusted
// origin fall through and be merged as if it were a reply.
test( 'an unknown verb from the control origin is never merged as a reply', () => {
	const { node, sink } = makeMerge();
	node.fill( reply( { last_modified: 7, requests: [ { rid: 'a' } ] } ) );

	const unknown = newMessage();
	unknown[ TYPE ] = TM_STRUCT;
	unknown[ FROM ] = 'urlDetail:merge';
	unknown[ VALUE ] = { action: 'refresh' };
	node.fill( unknown );

	expect( sink.received ).toHaveLength( 1 );
} );
