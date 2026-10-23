/**
 * Tests for OwnerTable: the "Time by owner" table every performance view
 * mounts from its payload's owner rows.
 */

import fs from 'fs';
import path from 'path';
import * as React from 'react';
import OwnerTable from '../OwnerTable';
import { renderComponent } from '../../test-helpers/renderHook';

const ROWS = [
	{ owner: 'plugin:heron-gallery', ms: 187.5, share: 0.4237, calls: 41 },
	{ owner: '(unowned)', ms: 90, share: 0.2051, calls: 7 },
	{ owner: 'theme:kea', ms: 12.25, share: 0.0279, calls: 3 },
	{ owner: '(unmeasured)', ms: 5, share: 0.0114, calls: 0 },
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
			[ '(unmeasured)', '5.0ms', '1.1%', '0' ],
		] );
		unmount();
	} );

	it( 'heads the columns for one request', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		expect(
			Array.from( container.querySelectorAll( 'th' ) ).map(
				( th ) => th.textContent
			)
		).toEqual( [ 'Owner', 'ms', 'Share', 'Calls' ] );
		unmount();
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
			'13.7',
		] );
		expect(
			Array.from( container.querySelectorAll( 'th' ) ).map(
				( th ) => th.textContent
			)
		).toEqual( [
			'Owner',
			'ms per request',
			'Share',
			'Calls per request',
		] );
		unmount();
	} );

	it( 'italicises the pseudo-owners and no other owner', () => {
		const { container, unmount } = mount( { owners: ROWS } );
		expect(
			Array.from( container.querySelectorAll( 'em' ) ).map(
				( em ) => em.textContent
			)
		).toEqual( [ '(unowned)', '(unmeasured)' ] );
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

describe( 'PSEUDO_OWNERS', () => {
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

	it( 'carries the names Owner minds for unclaimed and unmeasured time', () => {
		const match = read( '..', 'OwnerTable.js' ).match(
			/const PSEUDO_OWNERS = \[([^\]]*)\];/
		);
		expect( match ).toBeTruthy();
		const mirrored = match[ 1 ]
			.split( ',' )
			.map( ( part ) => part.trim().replace( /^'|'$/g, '' ) )
			.filter( Boolean );
		expect( mirrored.sort() ).toEqual(
			[ phpConst( 'UNOWNED' ), phpConst( 'UNMEASURED' ) ].sort()
		);
	} );
} );
