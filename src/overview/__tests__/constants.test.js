/**
 * Tests for the dashboard's field vocabulary — the one table the breakdown
 * selects and the filter chips both read — held to the PHP list it mirrors.
 * The PHP is read as SOURCE, because a separate deploy unit cannot import it
 * and a hand-kept copy nobody re-reads is how a field drifts.
 */

import fs from 'fs';
import path from 'path';
import {
	CHART_BREAKDOWN_OPTIONS,
	CHART_TOP,
	FILTER_FIELD_OPTIONS,
	SCOPED_BREAKDOWN_OPTIONS,
} from '../constants';

const STATS_QUERY = fs.readFileSync(
	path.join(
		__dirname,
		'..',
		'..',
		'..',
		'includes',
		'app',
		'class-stats-query.php'
	),
	'utf8'
);

/**
 * The members of a `const NAME = [ 'a', … ];` list in `Stats_Query`.
 *
 * @param {string} name The constant's name.
 * @return {string[]} Its members, in declared order.
 */
const phpList = ( name ) => {
	const list = STATS_QUERY.match(
		new RegExp( `const ${ name } = \\[([^\\]]*)\\];` )
	);
	expect( list ).toBeTruthy();
	return list[ 1 ]
		.split( ',' )
		.map( ( part ) => part.trim().replace( /^'|'$/g, '' ) )
		.filter( Boolean );
};

/**
 * The members of one source's list in `Stats_Query::FIELDS`.
 *
 * @param {string} source The source's key.
 * @return {string[]} Its fields, in declared order.
 */
const phpFields = ( source ) => {
	const block = STATS_QUERY.match(
		/public const FIELDS = \[([\s\S]*?)\n\t\];/
	);
	expect( block ).toBeTruthy();
	const list = block[ 1 ].match(
		new RegExp( `'${ source }'\\s*=>\\s*\\[([^\\]]*)\\]` )
	);
	expect( list ).toBeTruthy();
	return list[ 1 ]
		.split( ',' )
		.map( ( part ) => part.trim().replace( /^'|'$/g, '' ) )
		.filter( Boolean );
};

describe( "Stats_Query::FIELDS['requests']", () => {
	it( 'is its time fields, the server the select owns, then every field a chip may name, in order', () => {
		expect( [
			...phpList( 'TIME_FIELDS' ),
			'server',
			...FILTER_FIELD_OPTIONS.map( ( option ) => option.value ),
		] ).toEqual( phpFields( 'requests' ) );
		expect( CHART_BREAKDOWN_OPTIONS.map( ( o ) => o.value ) ).toContain(
			'server'
		);
	} );

	it( 'labels every field a select or a chip offers', () => {
		for ( const option of [
			...CHART_BREAKDOWN_OPTIONS,
			...FILTER_FIELD_OPTIONS,
		] ) {
			expect( typeof option.label ).toBe( 'string' );
		}
	} );
} );

describe( 'the lists drawn from it', () => {
	it( 'breaks a chart down by every series field, the time axis never', () => {
		const values = CHART_BREAKDOWN_OPTIONS.map( ( o ) => o.value );

		expect( values ).toContain( 'status_class' );
		expect( values ).toContain( 'ua' );
		for ( const time of [ 'bucket', 'hour', 'day' ] ) {
			expect( values ).not.toContain( time );
		}
		expect( values ).not.toContain( 'url' );
	} );

	it( 'drops only the server from the scoped list', () => {
		expect( SCOPED_BREAKDOWN_OPTIONS ).toEqual(
			CHART_BREAKDOWN_OPTIONS.filter( ( o ) => 'server' !== o.value )
		);
	} );

	it( 'breaks a chart down by no field a chip alone names', () => {
		const values = CHART_BREAKDOWN_OPTIONS.map( ( o ) => o.value );

		expect( values ).not.toContain( 'worker' );
		expect(
			values.every(
				( value ) =>
					'server' === value ||
					FILTER_FIELD_OPTIONS.some( ( o ) => o.value === value )
			)
		).toBe( true );
	} );
} );

it( 'asks a chart for no more series than Stats_Query::MAX_TOP ranks', () => {
	const max = STATS_QUERY.match( /const MAX_TOP = (\d+);/ );

	expect( max ).toBeTruthy();
	expect( CHART_TOP ).toBe( Number( max[ 1 ] ) );
} );
