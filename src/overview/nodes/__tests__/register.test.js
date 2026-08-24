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
			'UrlDetailView',
			'RequestDetailView',
			'UrlDetailMerge',
		] ) {
			expect( CommandInterpreterNode.includeNodes[ type ] ).toBe(
				views[ type ]
			);
		}
	} );

	test( 'each view carries its own palette description', () => {
		const described = [
			'OverviewView',
			'UrlsView',
			'UrlDetailView',
			'RequestDetailView',
		].map( ( t ) => views[ t ].nodeSchema().description );

		expect( new Set( described ).size ).toBe( described.length );
	} );
} );

describe( 'the data slices', () => {
	test.each( [
		[ 'OverviewView', 'overview:view', 'overview' ],
		[ 'UrlDetailView', 'urldetail:view', 'url_detail' ],
		[ 'RequestDetailView', 'requestdetail:view', 'request_detail' ],
	] )(
		'%s publishes an empty slice, then its payload',
		( type, name, verb ) => {
			const v = makeView( type, name );
			expect( v.setStateCache.view ).toEqual( {
				data: null,
				loading: false,
				error: null,
			} );

			v.fill( reply( verb, { rid: 'r-4219' } ) );

			expect( v.setStateCache.view ).toEqual( {
				data: { rid: 'r-4219' },
				loading: false,
				error: null,
			} );
		}
	);

	test( 'a reply carrying no payload keeps the slice already on screen', () => {
		const v = makeView( 'UrlDetailView', 'urldetail:view' );
		v.fill( reply( 'url_detail', { requests: [ { rid: 'a' } ] } ) );
		v.fill( reply( 'url_detail', undefined ) );

		expect( v.setStateCache.view.data ).toEqual( {
			requests: [ { rid: 'a' } ],
		} );
	} );

	test( 'a TM_ERROR reply surfaces the error and keeps the data', () => {
		const v = makeView( 'OverviewView', 'overview:view' );
		v.fill( reply( 'overview', { hits: 8264 } ) );
		v.fill( reply( 'overview', 'upstream exploded', true ) );

		expect( v.setStateCache.view ).toEqual( {
			data: { hits: 8264 },
			loading: false,
			error: 'upstream exploded',
		} );
	} );

	test( 'the graph clears a modal slice through the control path', () => {
		const v = makeView( 'RequestDetailView', 'requestdetail:view' );
		v.fill( reply( 'request_detail', { rid: 'r-4219' } ) );
		v.fill( control( 'requestdetail:view', { action: 'clear' } ) );

		expect( v.setStateCache.view.data ).toBeNull();
	} );
} );

describe( 'UrlsView — the envelope slice', () => {
	test( 'unwraps data and totals, dropping limit and offset', () => {
		const v = makeView( 'UrlsView', 'urls:view' );
		expect( v.setStateCache.view ).toEqual( {
			data: [],
			totals: null,
			rows: 0,
			slowest: [],
			filters: null,
			loading: false,
			error: null,
		} );

		v.fill(
			reply( 'urls', {
				data: [ { url: '/a' } ],
				totals: { urls: 7331, requests: 90210 },
				// One more row than URLs: the folded row is sliceable but is
				// not a unique URL, and the pager slices.
				rows: 7332,
				slowest: [ { url: '/slow', p95_ms: 2600 } ],
				filters: { server: 'edge-01', search: '', errors_only: false },
				limit: 20,
				offset: 0,
			} )
		);

		expect( v.setStateCache.view ).toEqual( {
			data: [ { url: '/a' } ],
			totals: { urls: 7331, requests: 90210 },
			rows: 7332,
			slowest: [ { url: '/slow', p95_ms: 2600 } ],
			filters: { server: 'edge-01', search: '', errors_only: false },
			loading: false,
			error: null,
		} );
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

		expect( v.setStateCache.view.data ).toEqual( [] );
		expect( v.setStateCache.view.totals ).toBe( null );
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

		expect( v.setStateCache.view.data ).toEqual( [ { url: '/a' } ] );
		expect( v.setStateCache.view.totals ).toEqual( { urls: 7331 } );
		expect( v.setStateCache.view.error ).toBe( 'query timed out' );
	} );
} );
