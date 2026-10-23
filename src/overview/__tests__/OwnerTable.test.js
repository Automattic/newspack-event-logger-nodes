/**
 * Tests for OwnerTable: the "Time By Owner" table every performance view
 * mounts from its payload's owner rows.
 */

import fs from 'fs';
import path from 'path';
import * as React from 'react';
import OwnerTable from '../OwnerTable';
import { renderComponent, act } from '../../test-helpers/renderHook';

const ROWS = [
	{ owner: 'plugin:heron-gallery', ms: 187.5, share: 0.4237, calls: 41 },
	{ owner: '(unowned)', ms: 90, share: 0.2051, calls: 7 },
	{ owner: 'theme:kea', ms: 12.25, share: 0.0279, calls: 3 },
];

const mount = ( props ) =>
	renderComponent( React.createElement( OwnerTable, props ) );

const cells = ( container ) =>
	Array.from( container.querySelectorAll( 'tbody tr' ) ).map( ( tr ) =>
		Array.from( tr.querySelectorAll( 'td' ) ).map(
			( td ) => td.textContent
		)
	);

describe( 'OwnerTable', () => {
	it( 'renders rows in the given order with share to one decimal', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		expect( cells( container ) ).toEqual( [
			[ 'plugin:heron-gallery', '187.5ms', '42.4%', '41' ],
			[ '(unowned)', '90.0ms', '20.5%', '7' ],
			[ 'theme:kea', '12.3ms', '2.8%', '3' ],
		] );
		unmount();
	} );

	it( 'draws the ten dearest owners and holds the rest behind Show more', () => {
		const owners = Array.from( { length: 13 }, ( _, i ) => ( {
			owner: `kea-${ i }`,
			ms: 130 - i,
			share: 0.07,
			calls: 2,
		} ) );
		const { container, unmount } = mount( { owners } );
		const button = () => container.querySelector( 'tbody button' );
		const drawn = () =>
			cells( container )
				.filter( ( row ) => row.length > 1 )
				.map( ( [ owner ] ) => owner );

		expect( drawn() ).toEqual(
			owners.slice( 0, 10 ).map( ( { owner } ) => owner )
		);
		expect( button().textContent ).toBe( 'Show 3 more owners' );
		// The bar is never truncated: every owner keeps its segment.
		expect(
			container.querySelectorAll( '.event-logger-profile-bar > *' )
		).toHaveLength( 13 );

		act( () => button().click() );
		expect( drawn() ).toHaveLength( 13 );
		expect( button().textContent ).toBe( 'Show less' );
		unmount();
	} );

	it( 'heads the columns for one request', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		expect(
			Array.from( container.querySelectorAll( 'th' ) ).map( ( th ) =>
				// The sorted column carries its caret; the label is the rest.
				th.textContent.replace( /[▲▼]/g, '' )
			)
		).toEqual( [ 'Owner', 'Time', '% of Total', 'Count' ] );
		unmount();
	} );

	it( 'says its numbers are per request in an average, as the breakdown says of its own', () => {
		const averaged = mount( {
			owners: [
				{
					owner: 'kea-7601',
					ms_per_request: 4.5,
					share: 1,
					calls_per_request: 2,
				},
			],
			perRequest: true,
		} );
		expect( averaged.container.textContent ).toContain(
			'Times and counts are per request.'
		);
		averaged.unmount();
		const single = mount( { owners: ROWS } );
		expect( single.container.textContent ).not.toContain( 'per request' );
		single.unmount();
	} );

	it( 'heads the ms and calls columns per request for an average', () => {
		const { container, unmount } = mount( {
			owners: ROWS.map( ( { ms, calls, ...row } ) => ( {
				...row,
				ms_per_request: ms + 1,
				calls_per_request: calls / 3,
			} ) ),
			perRequest: true,
		} );
		expect( cells( container )[ 0 ] ).toEqual( [
			'plugin:heron-gallery',
			'188.5ms',
			'42.4%',
			// A whole count, as the Time Breakdown rounds its averages.
			'14',
		] );
		expect(
			Array.from( container.querySelectorAll( 'th' ) ).map( ( th ) =>
				// The sorted column carries its caret; the label is the rest.
				th.textContent.replace( /[▲▼]/g, '' )
			)
		).toEqual( [ 'Owner', 'Time', '% of Total', 'Count' ] );
		unmount();
	} );

	it( 'italicises the pseudo-owner and no other owner', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		expect(
			Array.from( container.querySelectorAll( 'em' ) ).map(
				( em ) => em.textContent
			)
		).toEqual( [ '(unowned)' ] );
		unmount();
	} );

	it( 'captions what the table measures', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		expect( container.textContent ).toContain(
			'Self time by the code that ran it. Listeners on significant hooks, queries and HTTP calls (to the code that asked) and plugin loads are attributed; time in an unmarked hook counts toward the enclosing owner.'
		);
		unmount();
	} );

	it.each( [ [ [] ], [ undefined ] ] )(
		'renders nothing for %p',
		( owners ) => {
			const { container, unmount } = mount( { owners } );
			expect( container.textContent ).toBe( '' );
			unmount();
		}
	);

	it( 'labels the table by its heading and scopes each column header', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		const heading = container.querySelector( 'h3' );
		expect(
			container.querySelector( 'table' ).getAttribute( 'aria-labelledby' )
		).toBe( heading.id );
		expect( heading.id ).not.toBe( '' );
		const heads = Array.from( container.querySelectorAll( 'th' ) );
		expect( heads ).toHaveLength( 4 );
		heads.forEach( ( th ) =>
			expect( th.getAttribute( 'scope' ) ).toBe( 'col' )
		);
		unmount();
	} );
} );

describe( 'OwnerTable totals and sorting', () => {
	const owners = ( container ) =>
		[ ...container.querySelectorAll( 'tbody tr' ) ].map( ( tr ) =>
			tr.querySelector( 'td' ).textContent.trim()
		);
	const header = ( container, label ) =>
		[ ...container.querySelectorAll( 'thead button' ) ].find( ( b ) =>
			b.textContent.startsWith( label )
		);
	const click = ( el ) =>
		act( () => {
			el.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );

	it( 'totals every owner it lists', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		const total = [ ...container.querySelectorAll( 'tfoot td' ) ].map(
			( td ) => td.textContent
		);
		expect( total.slice( 0, 3 ) ).toEqual( [
			'Total Profiled',
			'289.8ms',
			'65.7%',
		] );
		unmount();
	} );

	it( 'sorts by a header, flipping on a second click', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		click( header( container, 'Owner' ) );
		expect( owners( container ) ).toEqual( [
			'theme:kea',
			'plugin:heron-gallery',
			'(unowned)',
		] );
		click( header( container, 'Owner' ) );
		expect( owners( container ) ).toEqual( [
			'(unowned)',
			'plugin:heron-gallery',
			'theme:kea',
		] );
		click( header( container, 'Count' ) );
		expect( owners( container ) ).toEqual( [
			'plugin:heron-gallery',
			'(unowned)',
			'theme:kea',
		] );
		unmount();
	} );
} );

describe( 'OwnerTable share bar', () => {
	const segments = ( container ) => [
		...container.querySelectorAll( '.event-logger-profile-bar > div' ),
	];
	const swatchOf = ( container, owner ) =>
		[ ...container.querySelectorAll( 'tbody tr' ) ]
			.find( ( tr ) => tr.textContent.startsWith( owner ) )
			.querySelector( '.event-logger-profile-swatch' );

	it( 'draws one segment per owner at its share, beside the table that carries the numbers', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		const bar = container.querySelector( '.event-logger-profile-bar' );
		expect( bar.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		const widths = segments( container ).map( ( s ) =>
			parseFloat( s.style.width )
		);
		[ 42.37, 20.51, 2.79 ].forEach( ( width, at ) =>
			expect( widths[ at ] ).toBeCloseTo( width, 6 )
		);
		unmount();
	} );

	it( 'gives the pseudo-owner a muted neutral', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		const [ , unowned ] = segments( container );
		expect( unowned.style.background ).toBe( 'rgb(158, 158, 158)' );
		unmount();
	} );

	it( "keeps an owner's colour whatever its rank, and its swatch matches its segment", () => {
		const first = mount( { owners: ROWS } );
		const colour = segments( first.container )[ 0 ].style.background;
		expect(
			swatchOf( first.container, 'plugin:heron-gallery' ).style.background
		).toBe( colour );
		first.unmount();

		const second = mount( { owners: [ ...ROWS ].reverse() } );
		expect(
			swatchOf( second.container, 'plugin:heron-gallery' ).style
				.background
		).toBe( colour );
		second.unmount();
	} );
} );

describe( 'UNOWNED', () => {
	const read = ( ...parts ) =>
		fs.readFileSync( path.join( __dirname, ...parts ), 'utf8' );
	const phpConst = ( name ) => {
		const match = read(
			'..',
			'..',
			'..',
			'includes',
			'class-owner.php'
		).match( new RegExp( `const ${ name } = '([^']*)';` ) );
		expect( match ).toBeTruthy();
		return match[ 1 ];
	};

	it( 'carries the name Owner minds for unclaimed time', () => {
		const match = read( '..', 'OwnerTable.js' ).match(
			/const UNOWNED = '([^']*)';/
		);
		expect( match ).toBeTruthy();
		expect( match[ 1 ] ).toBe( phpConst( 'UNOWNED' ) );
	} );
} );
