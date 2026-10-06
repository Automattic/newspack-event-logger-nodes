/**
 * UrlDetailMergeNode tests — the transform Node that hosts the dump_url
 * incremental merge on the receiver-Tee → view graph EDGE (the addSliceFetcher
 * `transform` slot), out of view state.
 *
 * It receives the raw command reply (VALUE = { name, payload } where payload is
 * the dump_url object), merges the new payload against the payload it last
 * forwarded, and forwards a message whose VALUE.payload is the merged object —
 * EXCEPT when the reply carries no request it does not hold, its aggregate's
 * `last_modified` is unchanged AND its stats, slots, breakdown series and
 * window start are unchanged, in which case it drops the message.
 *
 * It also holds the tail cursor: the newest log position each partition's
 * replies have carried, which the next refresh asks past.
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

/**
 * Build the transform wired to a recording sink (the view stand-in).
 *
 * @return {{node: UrlDetailMergeNode, sink: RecordingSink}} The pair.
 */
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

// A command-reply message carrying a dump_url payload.
function reply( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
	m[ VALUE ] = {
		name: 'dump_url',
		payload,
		arguments: [ 'beef4471', '--categories=1' ],
	};
	return m;
}

// A control from the node's own origin.
function control( value ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ FROM ] = 'urlDetail:merge';
	m[ VALUE ] = value;
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

describe( 'UrlDetailMergeNode — a reply is news when its rows or its aggregate moved', () => {
	test( 'forwards a reply carrying an unseen request under an unchanged stamp', () => {
		const { node, sink } = makeMerge();
		node.fill(
			reply( {
				last_modified: 1790754996,
				requests: [ { rid: 'r-4417', timestamp: 1790754996 } ],
			} )
		);
		node.fill(
			reply( {
				last_modified: 1790754996,
				requests: [ { rid: 'r-4418', timestamp: 1790754998 } ],
			} )
		);
		expect( sink.received ).toHaveLength( 2 );
		expect(
			forwardedPayload( sink, 1 ).requests.map( ( r ) => r.rid )
		).toEqual( [ 'r-4418', 'r-4417' ] );
	} );

	test( 'a tail with no flame and nothing new is dropped, its stamp included', () => {
		// A null flame means "keep yours", and the stamp it would carry with it.
		const { node, sink } = makeMerge();
		node.fill(
			reply( {
				last_modified: 1790755041,
				aggregate_flame: { name: 'aggregate', value: 61, children: [] },
				requests: [ { rid: 'r-5501', timestamp: 1790755040 } ],
			} )
		);
		node.fill(
			reply( {
				last_modified: 0,
				aggregate_flame: null,
				requests: [],
			} )
		);
		expect( sink.received ).toHaveLength( 1 );
		node.fill(
			reply( {
				last_modified: 1790755049,
				aggregate_flame: null,
				requests: [ { rid: 'r-5502', timestamp: 1790755049 } ],
			} )
		);
		expect( forwardedPayload( sink, 1 ).last_modified ).toBe( 1790755041 );
	} );

	test( "orders the list by the server's finished_at, not start or duration", () => {
		// A timeout's duration is null (decision 24); its wait is in finished_at.
		const { node, sink } = makeMerge();
		node.fill(
			reply( {
				last_modified: 3,
				requests: [
					{
						rid: 'short',
						timestamp: 1790755103,
						duration_ms: 12,
						finished_at: 1790755103.012,
					},
					{
						rid: 'long',
						timestamp: 1790755100,
						duration_ms: null,
						finished_at: 1790755107.3,
					},
				],
			} )
		);
		expect(
			forwardedPayload( sink ).requests.map( ( r ) => r.rid )
		).toEqual( [ 'long', 'short' ] );
	} );

	test( 'drops a reply holding nothing new under an unchanged stamp', () => {
		const { node, sink } = makeMerge();
		node.fill( reply( { last_modified: 5, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 1 );
		// Same last_modified → no republish.
		node.fill( reply( { last_modified: 5, requests: [ { rid: 'a' } ] } ) );
		expect( sink.received ).toHaveLength( 1 );
	} );

	test.each( [
		[ 'stats', { count: 4417 }, { count: 4418 } ],
		[ 'slots', [ 'b-0905' ], [ 'b-0905', 'b-0910' ] ],
		[ 'breakdown_time_series', { s: [ 1 ] }, { s: [ 1, 2 ] } ],
		[ 'requests_window_start', 6631, 6632 ],
	] )(
		'forwards a reply whose %s moved with no new row or stamp',
		( field, before, after ) => {
			const { node, sink } = makeMerge();
			const base = { last_modified: 5, requests: [ { rid: 'a' } ] };
			node.fill( reply( { ...base, [ field ]: before } ) );
			node.fill( reply( { ...base, [ field ]: after } ) );
			expect( sink.received ).toHaveLength( 2 );
			expect( forwardedPayload( sink, 1 )[ field ] ).toEqual( after );
		}
	);
} );

describe( 'UrlDetailMergeNode — incremental merge on change', () => {
	test( 'dedups new requests by rid, prepends newest-first, caps 500', () => {
		const { node, sink } = makeMerge();
		// Seed with two requests.
		node.fill(
			reply( {
				last_modified: 1,
				requests: [
					{ rid: 'a', finished_at: 100 },
					{ rid: 'b', finished_at: 90 },
				],
			} )
		);
		// A newer payload: one repeat (a) + one new (c, newest).
		node.fill(
			reply( {
				last_modified: 2,
				requests: [
					{ rid: 'c', finished_at: 110 },
					{ rid: 'a', finished_at: 100 },
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
			prev.push( { rid: `p${ i }`, finished_at: i } );
		}
		node.fill( reply( { last_modified: 1, requests: prev } ) );
		const next = [];
		for ( let i = 0; i < 200; i++ ) {
			next.push( { rid: `n${ i }`, finished_at: 100000 + i } );
		}
		node.fill( reply( { last_modified: 2, requests: next } ) );
		const merged = forwardedPayload( sink, 1 );
		expect( merged.requests ).toHaveLength( 500 );
		// Newest-first: the 200 new ones (latest completions) lead.
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
		impostor[ FROM ] = 'url-detail:in';
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

// The list is the union of every walk, so the flag has to be the union too.
describe( 'UrlDetailMergeNode — scan_stopped_early describes the merged list', () => {
	test( 'a truncated walk keeps its note over a later complete one', () => {
		const { node, sink } = makeMerge();
		node.fill(
			reply( {
				last_modified: 31,
				scan_stopped_early: true,
				requests: [ { rid: 'q7', timestamp: 771 } ],
			} )
		);
		node.fill(
			reply( {
				last_modified: 32,
				requests: [ { rid: 'q8', timestamp: 772 } ],
			} )
		);
		expect( forwardedPayload( sink, 1 ).requests ).toHaveLength( 2 );
		expect( forwardedPayload( sink, 1 ).scan_stopped_early ).toBe( true );
	} );

	test( 'a tailing reply with no flame keeps the one held', () => {
		// The server rebuilds a cold URL's flame only on a full read.
		const { node, sink } = makeMerge();
		const flame = { name: 'aggregate', value: 50, children: [] };
		const profiles = { count: 3 };
		node.fill(
			reply( {
				last_modified: 41,
				aggregate_flame: flame,
				aggregate_profiles: profiles,
				requests: [ { rid: 'f1', timestamp: 841 } ],
			} )
		);
		node.fill(
			reply( {
				last_modified: 42,
				aggregate_flame: null,
				aggregate_profiles: null,
				requests: [ { rid: 'f2', timestamp: 842 } ],
			} )
		);
		expect( forwardedPayload( sink, 1 ).aggregate_flame ).toBe( flame );
		expect( forwardedPayload( sink, 1 ).aggregate_profiles ).toBe(
			profiles
		);
		expect( forwardedPayload( sink, 1 ).requests ).toHaveLength( 2 );
	} );

	test( 'a clear drops the note with the list it described', () => {
		const { node, sink } = makeMerge();
		node.fill(
			reply( {
				last_modified: 31,
				scan_stopped_early: true,
				requests: [ { rid: 'q7', timestamp: 771 } ],
			} )
		);

		const clear = newMessage();
		clear[ TYPE ] = TM_STRUCT;
		clear[ FROM ] = 'urlDetail:merge';
		clear[ VALUE ] = { action: 'clear' };
		node.fill( clear );

		node.fill(
			reply( {
				last_modified: 33,
				requests: [ { rid: 'q9', timestamp: 773 } ],
			} )
		);
		expect( forwardedPayload( sink, 1 ).scan_stopped_early ).toBeFalsy();
	} );
} );

describe( 'UrlDetailMergeNode — relist', () => {
	test( 'a relist restarts the list but keeps the held flame', () => {
		// An errors-only reply rebuilds no flame; the one held still stands.
		const { node, sink } = makeMerge();
		const flame = { name: 'aggregate', value: 61, children: [] };
		node.fill(
			reply( {
				last_modified: 17,
				aggregate_flame: flame,
				scan_stopped_early: true,
				positions: { 1: { segment: 6, offset: 4471 } },
				requests: [ { rid: 'clean-2', timestamp: 911 } ],
			} )
		);

		node.fill( control( { action: 'relist' } ) );
		expect( node.cursor() ).toBeNull();
		node.fill(
			reply( {
				last_modified: 17,
				aggregate_flame: null,
				requests: [ { rid: 'err-2', timestamp: 409 } ],
			} )
		);

		const relisted = forwardedPayload( sink, 1 );
		expect( relisted.requests.map( ( r ) => r.rid ) ).toEqual( [
			'err-2',
		] );
		expect( relisted.aggregate_flame ).toBe( flame );
		expect( relisted.scan_stopped_early ).toBeFalsy();
	} );

	test( 'a relist keeps the held profile whole, its count beside its categories', () => {
		const { node, sink } = makeMerge();
		const profiles = {
			count: 41,
			total_time: 88,
			categories: { render: { time: 61, count: 3 } },
		};
		node.fill(
			reply( {
				last_modified: 23,
				aggregate_profiles: profiles,
				requests: [ { rid: 'clean-7', timestamp: 913 } ],
			} )
		);

		node.fill( control( { action: 'relist' } ) );
		node.fill(
			reply( {
				last_modified: 23,
				aggregate_profiles: null,
				requests: [ { rid: 'err-7', timestamp: 412 } ],
			} )
		);

		const relisted = forwardedPayload( sink, 1 );
		expect( relisted.aggregate_profiles ).toEqual( profiles );
	} );
} );

describe( 'cursor', () => {
	/**
	 * The position each partition's walk reached, as the server reported it,
	 * whether or not that partition held a row for the URL. The next refresh
	 * asks past it, so a partition with nothing new costs one index line.
	 */
	it( 'holds each partition at the position its last reply reported', () => {
		const { node } = makeMerge();
		node.fill(
			reply( {
				last_modified: 1,
				positions: {
					0: { segment: 3, offset: 4096 },
					2: { segment: 11, offset: 77 },
				},
				requests: [
					{
						rid: 'a',
						timestamp: 8,
						partition: 0,
						segment: 3,
						offset: 1024,
					},
				],
			} )
		);
		node.fill(
			reply( {
				last_modified: 1,
				positions: { 0: { segment: 4, offset: 0 } },
				requests: [],
			} )
		);

		expect( node.cursor() ).toEqual( {
			0: { segment: 4, offset: 0 },
			2: { segment: 11, offset: 77 },
		} );
	} );

	it( 'never moves on a row, so a partition the budget cut keeps its position', () => {
		// The rows came from the lines the walk reached before it stopped;
		// the lines between them and the held position are still unread.
		const { node } = makeMerge();
		node.fill(
			reply( {
				last_modified: 1,
				positions: { 1: { segment: 6, offset: 5120 } },
				requests: [],
			} )
		);
		node.fill(
			reply( {
				last_modified: 1,
				positions: {},
				scan_stopped_early: true,
				requests: [
					{
						rid: 'cut',
						timestamp: 1790756000,
						partition: 1,
						segment: 9,
						offset: 88,
					},
				],
			} )
		);

		expect( node.cursor() ).toEqual( { 1: { segment: 6, offset: 5120 } } );
	} );

	it( 'is null with nothing retained, so the first ask reads the whole window', () => {
		expect( new UrlDetailMergeNode().cursor() ).toBeNull();
	} );

	it( 'is null again after a clear, so a reopened modal reads the whole window', () => {
		const { node } = makeMerge();
		node.fill(
			reply( {
				last_modified: 1,
				positions: { 3: { segment: 2, offset: 9 } },
				requests: [ { rid: 'a', timestamp: 1 } ],
			} )
		);
		node.fill( control( { action: 'clear' } ) );

		expect( node.cursor() ).toBeNull();
	} );
} );
