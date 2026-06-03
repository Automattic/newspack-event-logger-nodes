/**
 * performance:view tests — the sliced data model for the Performance Dashboard.
 *
 * Holds four slices (overview, urls, urlDetail, requestDetail) each with its own
 * { data, loading, error }, plus lastRefresh. `fill` routes loading|result|error|
 * clear by `slice`; errors are per-slice isolated; the view owns the stateful
 * `urlDetail` incremental merge + `last_modified` dedup (moved from the
 * orchestrator's mergeUrlDetail). Every change publishes via setState('view', …),
 * read here off Core.node('performance:view').setStateCache.view.
 */

import {
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { PerformanceViewNode } from '../performance-view-node';

beforeEach( () => Core.reset() );

// Construct + name the node directly — the createX factory is gone (make_node
// builds it in production); bare-new + setName is the test seam.
function makeView( name ) {
	const node = new PerformanceViewNode();
	node.setName( name );
	return node;
}

// A control/result message: TM_STRUCT carrying { action, slice, … }.
const ctrl = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

// The published view model.
const view = () => Core.node( 'performance:view' ).setStateCache.view;

test( 'publishes an initial model with four empty slices + lastRefresh null', () => {
	makeView( 'performance:view' );
	expect( view() ).toEqual( {
		overview: { data: null, loading: false, error: null },
		urls: { data: [], total: 0, loading: false, error: null },
		urlDetail: { data: null, loading: false, error: null },
		requestDetail: { data: null, loading: false, error: null },
		lastRefresh: null,
	} );
} );

test( 'loading sets the slice loading:true and leaves the others', () => {
	const v = makeView( 'performance:view' );
	v.fill( ctrl( { action: 'loading', slice: 'overview' } ) );
	expect( view().overview ).toEqual( {
		data: null,
		loading: true,
		error: null,
	} );
	expect( view().urls.loading ).toBe( false );
} );

test( 'overview result stores data, clears loading, stamps lastRefresh', () => {
	const v = makeView( 'performance:view' );
	v.fill( ctrl( { action: 'loading', slice: 'overview' } ) );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'overview',
			data: { total_requests: 7 },
		} )
	);
	expect( view().overview ).toEqual( {
		data: { total_requests: 7 },
		loading: false,
		error: null,
	} );
	expect( typeof view().lastRefresh ).toBe( 'number' );
} );

test( 'urls result stores data + total from the reply', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urls',
			data: { data: [ { hash: 'a' } ], total: 12, limit: 100, offset: 0 },
		} )
	);
	expect( view().urls ).toEqual( {
		data: [ { hash: 'a' } ],
		total: 12,
		loading: false,
		error: null,
	} );
} );

test( 'requestDetail result stores data', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'requestDetail',
			data: { rid: 'r1' },
		} )
	);
	expect( view().requestDetail ).toEqual( {
		data: { rid: 'r1' },
		loading: false,
		error: null,
	} );
} );

test( 'error on a slice sets error + clears loading, preserving prior data on OTHER slices', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'overview',
			data: { total_requests: 5 },
		} )
	);
	v.fill( ctrl( { action: 'loading', slice: 'urlDetail' } ) );
	v.fill( ctrl( { action: 'error', slice: 'urlDetail', error: 'boom' } ) );
	// urlDetail carries the error, loading cleared.
	expect( view().urlDetail.error ).toBe( 'boom' );
	expect( view().urlDetail.loading ).toBe( false );
	// Isolation: overview.data untouched.
	expect( view().overview.data ).toEqual( { total_requests: 5 } );
} );

test( 'urlDetail initial:true replaces and records last_modified', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: true,
			data: {
				last_modified: 100,
				requests: [ { rid: 'a', timestamp: 1 } ],
			},
		} )
	);
	expect( view().urlDetail.data.requests.map( ( r ) => r.rid ) ).toEqual( [
		'a',
	] );
	expect( view().urlDetail.error ).toBeNull();
} );

test( 'urlDetail non-initial with a NEW last_modified merges new rids newest-first', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: true,
			data: {
				last_modified: 100,
				requests: [ { rid: 'a', timestamp: 1 } ],
			},
		} )
	);
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: false,
			data: {
				last_modified: 200,
				requests: [ { rid: 'b', timestamp: 5 } ],
			},
		} )
	);
	// Newest-first: b (ts 5) before a (ts 1).
	expect( view().urlDetail.data.requests.map( ( r ) => r.rid ) ).toEqual( [
		'b',
		'a',
	] );
} );

test( 'urlDetail merge caps the requests list at 500 newest-first', () => {
	const v = makeView( 'performance:view' );
	const prev = [];
	for ( let i = 0; i < 500; i++ ) {
		prev.push( { rid: `old-${ i }`, timestamp: i } );
	}
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: true,
			data: { last_modified: 1, requests: prev },
		} )
	);
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: false,
			data: {
				last_modified: 2,
				requests: [ { rid: 'fresh', timestamp: 99999 } ],
			},
		} )
	);
	const merged = view().urlDetail.data.requests;
	expect( merged ).toHaveLength( 500 );
	expect( merged[ 0 ].rid ).toBe( 'fresh' );
} );

test( 'urlDetail non-initial with the SAME last_modified is a no-op (model reference unchanged)', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: true,
			data: {
				last_modified: 100,
				requests: [ { rid: 'a', timestamp: 1 } ],
			},
		} )
	);
	const before = view();
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: false,
			data: {
				last_modified: 100,
				requests: [ { rid: 'a', timestamp: 1 } ],
			},
		} )
	);
	// Skipped: no republish, so the same model object reference is still cached.
	expect( view() ).toBe( before );
} );

test( 'clear of urlDetail resets it and clears stored last_modified (next non-initial is fresh)', () => {
	const v = makeView( 'performance:view' );
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: true,
			data: {
				last_modified: 100,
				requests: [ { rid: 'a', timestamp: 1 } ],
			},
		} )
	);
	v.fill( ctrl( { action: 'clear', slice: 'urlDetail' } ) );
	expect( view().urlDetail ).toEqual( {
		data: null,
		loading: false,
		error: null,
	} );
	// last_modified was cleared, so a non-initial payload with the OLD value is
	// treated as fresh (no prior requests → it becomes the data verbatim).
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: false,
			data: {
				last_modified: 100,
				requests: [ { rid: 'z', timestamp: 9 } ],
			},
		} )
	);
	expect( view().urlDetail.data.requests.map( ( r ) => r.rid ) ).toEqual( [
		'z',
	] );
} );

test( 'urlDetail initial result with null data is a safe no-op (does not crash)', () => {
	const v = makeView( 'performance:view' );
	const before = view();
	expect( () =>
		v.fill(
			ctrl( {
				action: 'result',
				slice: 'urlDetail',
				initial: true,
				data: null,
			} )
		)
	).not.toThrow();
	// No-op: urlDetail unchanged from its empty prior state, no republish.
	expect( view() ).toBe( before );
} );

test( 'urlDetail non-initial result with null data is a safe no-op (does not crash)', () => {
	const v = makeView( 'performance:view' );
	// Seed a prior urlDetail so we can assert it survives the null payload.
	v.fill(
		ctrl( {
			action: 'result',
			slice: 'urlDetail',
			initial: true,
			data: {
				last_modified: 100,
				requests: [ { rid: 'a', timestamp: 1 } ],
			},
		} )
	);
	const before = view();
	expect( () =>
		v.fill(
			ctrl( {
				action: 'result',
				slice: 'urlDetail',
				initial: false,
				data: null,
			} )
		)
	).not.toThrow();
	// No-op: the prior urlDetail is preserved and no republish happened.
	expect( view() ).toBe( before );
	expect( view().urlDetail.data.requests.map( ( r ) => r.rid ) ).toEqual( [
		'a',
	] );
} );

test( 'a malformed message (no VALUE/action) is ignored', () => {
	const v = makeView( 'performance:view' );
	const before = view();
	v.fill( ctrl( undefined ) );
	v.fill( ctrl( { slice: 'overview' } ) );
	expect( view() ).toBe( before );
} );

test( 'names the node', () => {
	const v = makeView( 'performance:view' );
	expect( v.name ).toBe( 'performance:view' );
} );

// Below: the substrate-canonical reply path. The view now also receives raw
// TM_COMMAND|TM_RESPONSE replies pivoted via TO=FROM by HttpOutNode. It matches
// `message[ID]` against `pending` and applies the result to the registered
// slice (or resolves a resolveOnly promise).

const { TM_COMMAND, TM_RESPONSE, TM_ERROR, ID, FROM, TO } = jest.requireActual(
	'@newspack-nodes/runtime'
);

// Build a reply Message: TM_COMMAND|TM_RESPONSE (optionally |TM_ERROR).
function reply( id, name, payload, opts = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE | ( opts.error ? TM_ERROR : 0 );
	m[ ID ] = id;
	m[ VALUE ] = { name, payload };
	return m;
}

describe( 'performance:view — pending-matched reply routing', () => {
	test( 'an overview reply matched against pending applies the result to the overview slice', () => {
		const v = makeView( 'performance:view' );
		v.pending.set( 'op-1', { slice: 'overview' } );
		v.fill( reply( 'op-1', 'overview', { total_requests: 9 } ) );
		expect( view().overview ).toEqual( {
			data: { total_requests: 9 },
			loading: false,
			error: null,
		} );
		expect( v.pending.has( 'op-1' ) ).toBe( false );
	} );

	test( 'a urls reply matched against pending applies data + total to the urls slice', () => {
		const v = makeView( 'performance:view' );
		v.pending.set( 'op-2', { slice: 'urls' } );
		v.fill(
			reply( 'op-2', 'urls', {
				data: [ { hash: 'a' } ],
				total: 3,
				limit: 100,
				offset: 0,
			} )
		);
		expect( view().urls ).toEqual( {
			data: [ { hash: 'a' } ],
			total: 3,
			loading: false,
			error: null,
		} );
	} );

	test( 'a urlDetail reply with initial:true replaces and records last_modified', () => {
		const v = makeView( 'performance:view' );
		v.pending.set( 'op-3', { slice: 'urlDetail', initial: true } );
		v.fill(
			reply( 'op-3', 'url_detail', {
				last_modified: 50,
				requests: [ { rid: 'a', timestamp: 1 } ],
			} )
		);
		expect( view().urlDetail.data.requests.map( ( r ) => r.rid ) ).toEqual(
			[ 'a' ]
		);
	} );

	test( 'a urlDetail reply non-initial with NEW last_modified merges newest-first', () => {
		const v = makeView( 'performance:view' );
		v.pending.set( 'op-3a', { slice: 'urlDetail', initial: true } );
		v.fill(
			reply( 'op-3a', 'url_detail', {
				last_modified: 50,
				requests: [ { rid: 'a', timestamp: 1 } ],
			} )
		);
		v.pending.set( 'op-3b', { slice: 'urlDetail', initial: false } );
		v.fill(
			reply( 'op-3b', 'url_detail', {
				last_modified: 60,
				requests: [ { rid: 'b', timestamp: 5 } ],
			} )
		);
		expect( view().urlDetail.data.requests.map( ( r ) => r.rid ) ).toEqual(
			[ 'b', 'a' ]
		);
	} );

	test( 'a requestDetail reply applies the data to the requestDetail slice', () => {
		const v = makeView( 'performance:view' );
		v.pending.set( 'op-4', { slice: 'requestDetail' } );
		v.fill( reply( 'op-4', 'request_detail', { rid: 'r1', url: '/x' } ) );
		expect( view().requestDetail ).toEqual( {
			data: { rid: 'r1', url: '/x' },
			loading: false,
			error: null,
		} );
	} );

	test( 'a TM_ERROR reply matched against pending applies an error to the slice', () => {
		const v = makeView( 'performance:view' );
		v.pending.set( 'op-5', { slice: 'overview' } );
		v.fill(
			reply( 'op-5', 'overview', 'something failed', { error: true } )
		);
		expect( view().overview ).toMatchObject( {
			loading: false,
			error: 'something failed',
		} );
	} );

	test( 'a resolveOnly pending resolves the Promise with the raw payload (no view-model update)', async () => {
		const v = makeView( 'performance:view' );
		const p = new Promise( ( resolve, reject ) => {
			v.pending.set( 'op-6', { resolveOnly: true, resolve, reject } );
		} );
		const before = view();
		v.fill(
			reply( 'op-6', 'request_search', {
				url_hash: 'h',
				partition: 2,
			} )
		);
		const resolved = await p;
		expect( resolved ).toEqual( { url_hash: 'h', partition: 2 } );
		// View model unchanged — resolveOnly doesn't update a slice.
		expect( view() ).toBe( before );
	} );

	test( 'a resolveOnly pending with a transform pipes the payload through it', async () => {
		const v = makeView( 'performance:view' );
		const transform = ( d ) => ( d && d.breakdown_time_series ) || null;
		const p = new Promise( ( resolve, reject ) => {
			v.pending.set( 'op-7', {
				resolveOnly: true,
				resolve,
				reject,
				transform,
			} );
		} );
		v.fill(
			reply( 'op-7', 'url_detail', {
				breakdown_time_series: { a: 1 },
			} )
		);
		expect( await p ).toEqual( { a: 1 } );
	} );

	test( 'a resolveOnly TM_ERROR rejects the Promise without updating any slice', async () => {
		const v = makeView( 'performance:view' );
		const before = view();
		const p = new Promise( ( resolve, reject ) => {
			v.pending.set( 'op-8', { resolveOnly: true, resolve, reject } );
		} );
		v.fill(
			reply( 'op-8', 'request_search', 'not found', { error: true } )
		);
		await expect( p ).rejects.toThrow( 'not found' );
		expect( view() ).toBe( before );
	} );

	test( 'an unmatched-ID reply is ignored (no pending entry → no slice update)', () => {
		const v = makeView( 'performance:view' );
		const before = view();
		v.fill( reply( 'op-unknown', 'overview', { total_requests: 1 } ) );
		expect( view() ).toBe( before );
	} );
} );

// Suppress unused-warning for FROM/TO if linter complains; they're imported for
// symmetry with the canonical view tests.
void FROM;
void TO;

describe( 'performance:view — removeNode settles in-flight resolveOnly pending', () => {
	test( 'removeNode resolves resolveOnly pending with null and drops slice-tagged entries', async () => {
		const v = makeView( 'performance:view' );
		// A resolveOnly Promise (resolveRequest / fetchUrlBreakdown). On teardown
		// it must SETTLE so the caller's await completes — resolved with null (the
		// methods' canonical "no data" return), NOT rejected, so fetchUrlBreakdown's
		// _onError banner doesn't pop on a Reset-Graph reinit.
		const awaited = new Promise( ( resolve, reject ) =>
			v.pending.set( 'op-resolve', {
				resolveOnly: true,
				resolve,
				reject,
			} )
		);
		// A slice-tagged fire-and-forget entry (fetchOverview / fetchUrls) — no
		// Promise awaiting it, so it is simply dropped.
		v.pending.set( 'op-slice', { slice: 'overview' } );

		expect( () => v.removeNode() ).not.toThrow();

		expect( await awaited ).toBeNull();
		expect( v.pending.size ).toBe( 0 );
	} );
} );

describe( 'performance:view — nodeSchema', () => {
	test( 'is a Hidden, terminal (no output port) node', () => {
		const schema = makeView( 'performance:view' ).constructor.nodeSchema();
		expect( schema.has_target ).toBe( false );
		expect( schema.category ).toBe( 'Hidden' );
		expect( typeof schema.description ).toBe( 'string' );
		expect( schema.description.length ).toBeGreaterThan( 0 );
		expect( schema.arguments ).toEqual( [] );
		expect( schema.commands ).toEqual( [] );
	} );
} );
