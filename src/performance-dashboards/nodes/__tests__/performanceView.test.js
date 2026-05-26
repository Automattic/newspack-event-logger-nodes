/**
 * performance/view tests — the sliced data model for the Performance Dashboard.
 *
 * Holds four slices (overview, urls, urlDetail, requestDetail) each with its own
 * { data, loading, error }, plus lastRefresh. `fill` routes loading|result|error|
 * clear by `slice`; errors are per-slice isolated; the view owns the stateful
 * `urlDetail` incremental merge + `last_modified` dedup (moved from the
 * orchestrator's mergeUrlDetail). Every change publishes via setState('view', …),
 * read here off Core.node('performance/view').setStateCache.view.
 */

import {
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createPerformanceView } from '../performanceView';

beforeEach( () => Core.reset() );

// A control/result message: TM_STRUCT carrying { action, slice, … }.
const ctrl = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

// The published view model.
const view = () => Core.node( 'performance/view' ).setStateCache.view;

test( 'publishes an initial model with four empty slices + lastRefresh null', () => {
	createPerformanceView( 'performance/view' );
	expect( view() ).toEqual( {
		overview: { data: null, loading: false, error: null },
		urls: { data: [], total: 0, loading: false, error: null },
		urlDetail: { data: null, loading: false, error: null },
		requestDetail: { data: null, loading: false, error: null },
		lastRefresh: null,
	} );
} );

test( 'loading sets the slice loading:true and leaves the others', () => {
	const v = createPerformanceView( 'performance/view' );
	v.fill( ctrl( { action: 'loading', slice: 'overview' } ) );
	expect( view().overview ).toEqual( {
		data: null,
		loading: true,
		error: null,
	} );
	expect( view().urls.loading ).toBe( false );
} );

test( 'overview result stores data, clears loading, stamps lastRefresh', () => {
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
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
	const v = createPerformanceView( 'performance/view' );
	const before = view();
	v.fill( ctrl( undefined ) );
	v.fill( ctrl( { slice: 'overview' } ) );
	expect( view() ).toBe( before );
} );

test( 'names the node', () => {
	const v = createPerformanceView( 'performance/view' );
	expect( v.name ).toBe( 'performance/view' );
} );
