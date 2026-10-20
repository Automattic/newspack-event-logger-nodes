/**
 * The Performance Dashboard's slice views, declared in `register.js`.
 *
 * The base contract — the empty slice, the loading/clear/error controls, the
 * TM_ERROR path, control-origin discipline — belongs to `SliceViewNode` and is
 * covered by its own suite. These cover what the DECLARATIONS add: the names
 * `makeNode` resolves, each slice's shape, the `urls` envelope unwrap, and the
 * rule that a reply carrying no payload leaves the slice alone.
 */

import {
	VALUE,
	TYPE,
	FROM,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	TM_STRUCT,
	newMessage,
	Core,
	CommandInterpreterNode,
} from '@newspack-nodes/runtime';
import { views } from '../register';

beforeEach( () => Core.reset() );

/**
 * Build one declared view under the name the graph gives it.
 *
 * @param {string} type The key in `views`.
 * @param {string} name The node name the graph would give it.
 * @return {Object} The constructed view node.
 */
function makeView( type, name ) {
	const node = new views[ type ]();
	node.name = name;
	// What the graph does: controls ride under the view's own name.
	node.controlFrom = name;
	return node;
}

const reply = ( verb, payload, isError = false ) => {
	const m = newMessage();
	m[ TYPE ] = isError
		? TM_COMMAND | TM_RESPONSE | TM_ERROR
		: TM_COMMAND | TM_RESPONSE;
	m[ VALUE ] = { name: verb, payload };
	return m;
};

const control = ( from, value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ FROM ] = from;
	m[ VALUE ] = value;
	return m;
};

describe( 'registration', () => {
	test( 'every dashboard node resolves by the name make_node uses', () => {
		for ( const type of [
			'OverviewView',
			'UrlsView',
			'QueryView',
			'UrlDetailView',
			'RequestDetailView',
		] ) {
			expect( CommandInterpreterNode.includeNodes[ type ] ).toBe(
				views[ type ]
			);
		}
	} );

	test( 'declares no merge: each answer replaces the slice whole', () => {
		expect( views ).not.toHaveProperty( 'UrlDetailMerge' );
		expect( CommandInterpreterNode.includeNodes ).not.toHaveProperty(
			'UrlDetailMerge'
		);
	} );

	test( 'each view carries its own palette description', () => {
		const described = [
			'OverviewView',
			'UrlsView',
			'QueryView',
			'UrlDetailView',
			'RequestDetailView',
		].map( ( t ) => views[ t ].nodeSchema().description );

		expect( new Set( described ).size ).toBe( described.length );
	} );
} );

describe( 'the data slices', () => {
	test.each( [
		[ 'OverviewView', 'overview:view', 'overview' ],
		[ 'QueryView', 'chart:view', 'query' ],
		[ 'UrlDetailView', 'url-detail:view', 'dump_url' ],
		[ 'RequestDetailView', 'request-detail:view', 'dump_request' ],
	] )(
		'%s publishes an empty slice, then its payload',
		( type, name, verb ) => {
			const v = makeView( type, name );
			expect( v.view ).toEqual( {
				data: null,
				loading: false,
				error: null,
			} );

			v.fill( reply( verb, { rid: 'r-4219' } ) );

			expect( v.view ).toEqual( {
				data: { rid: 'r-4219' },
				loading: false,
				error: null,
			} );
		}
	);

	test( 'a reply carrying no payload keeps the slice already on screen', () => {
		const v = makeView( 'UrlDetailView', 'url-detail:view' );
		v.fill( reply( 'dump_url', { requests: [ { rid: 'a' } ] } ) );
		v.fill( reply( 'dump_url', undefined ) );

		expect( v.view.data ).toEqual( {
			requests: [ { rid: 'a' } ],
		} );
	} );

	test( 'a TM_ERROR reply surfaces the error and keeps the data', () => {
		const v = makeView( 'OverviewView', 'overview:view' );
		v.fill( reply( 'overview', { hits: 8264 } ) );
		v.fill( reply( 'overview', 'upstream exploded', true ) );

		expect( v.view ).toEqual( {
			data: { hits: 8264 },
			loading: false,
			error: 'upstream exploded',
		} );
	} );

	test( 'the graph clears a modal slice through the control path', () => {
		const v = makeView( 'RequestDetailView', 'request-detail:view' );
		v.fill( reply( 'dump_request', { rid: 'r-4219' } ) );
		v.fill( control( 'request-detail:view', { action: 'clear' } ) );

		expect( v.view.data ).toBeNull();
	} );
} );

describe( 'UrlsView — the envelope slice', () => {
	test( 'an outage reply publishes no totals, never zeroes', () => {
		const v = makeView( 'UrlsView', 'urls:view' );

		v.fill(
			reply( 'urls', {
				data: [],
				rows: 0,
				totals: null,
				slowest: [],
				filters: { server: '', search: '' },
				limit: 50,
				offset: 0,
				available: false,
			} )
		);

		expect( v.view.totals ).toBeNull();
	} );

	test( 'unwraps data, totals and the offset its rows start at, dropping limit', () => {
		const v = makeView( 'UrlsView', 'urls:view' );
		expect( v.view ).toEqual( {
			data: [],
			totals: null,
			rows: null,
			offset: 0,
			slowest: [],
			filters: null,
			loading: false,
			error: null,
		} );

		v.fill(
			reply( 'urls', {
				data: [ { url: '/a' } ],
				totals: { urls: 7331, requests: 90210 },
				rows: 7331,
				slowest: [ { url: '/slow', avg_ms: 2600 } ],
				filters: { server: 'edge-01', search: '', filter: [] },
				limit: 20,
				offset: 0,
			} )
		);

		expect( v.view ).toEqual( {
			data: [ { url: '/a' } ],
			totals: { urls: 7331, requests: 90210 },
			rows: 7331,
			offset: 0,
			slowest: [ { url: '/slow', avg_ms: 2600 } ],
			filters: { server: 'edge-01', search: '', filter: [] },
			loading: false,
			error: null,
		} );
	} );

	test( 'carries nothing but its slice, whatever else a reply holds', () => {
		const v = makeView( 'UrlsView', 'urls:view' );
		v.fill(
			reply( 'urls', {
				data: [ { url: '/a' } ],
				totals: { urls: 7331 },
				rows: 7331,
				slowest: [],
				filters: null,
				ranked: true,
				provisional: true,
				as_of: 1758500000,
			} )
		);

		expect( Object.keys( v.view ).sort() ).toEqual( [
			'data',
			'error',
			'filters',
			'loading',
			'offset',
			'rows',
			'slowest',
			'totals',
		] );
	} );

	test( 'a malformed envelope publishes an empty table rather than throwing', () => {
		const v = makeView( 'UrlsView', 'urls:view' );
		v.fill(
			reply( 'urls', {
				data: [ { url: '/a' } ],
				totals: { urls: 7331 },
			} )
		);
		v.fill( reply( 'urls', { nonsense: true } ) );

		expect( v.view.data ).toEqual( [] );
		expect( v.view.totals ).toBe( null );
	} );

	test( 'a TM_ERROR reply keeps the rows already on screen', () => {
		const v = makeView( 'UrlsView', 'urls:view' );
		v.fill(
			reply( 'urls', {
				data: [ { url: '/a' } ],
				totals: { urls: 7331 },
			} )
		);
		v.fill( reply( 'urls', 'query timed out', true ) );

		expect( v.view.data ).toEqual( [ { url: '/a' } ] );
		expect( v.view.totals ).toEqual( { urls: 7331 } );
		expect( v.view.error ).toBe( 'query timed out' );
	} );
} );

describe( "UrlsView — an expanded page's window of shapes", () => {
	test( 'keeps the offset its rows start at, beside the envelope', () => {
		const v = makeView(
			'UrlsView',
			'shapes:c0de4417aa01:moa.example:view'
		);
		expect( v.view.offset ).toBe( 0 );
		expect( v.view.rows ).toBeNull();

		v.fill(
			reply( 'urls', {
				data: [
					{ hash: 'b4417c0de001', url: 'https://moa.example/?k=?' },
				],
				totals: { urls: 4417 },
				rows: 4417,
				slowest: [],
				filters: null,
				limit: 1000,
				offset: 3500,
			} )
		);

		expect( v.view ).toEqual( {
			data: [ { hash: 'b4417c0de001', url: 'https://moa.example/?k=?' } ],
			totals: { urls: 4417 },
			rows: 4417,
			offset: 3500,
			slowest: [],
			filters: null,
			loading: false,
			error: null,
		} );
	} );
} );
