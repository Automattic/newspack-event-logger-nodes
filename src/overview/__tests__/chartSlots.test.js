/**
 * Tests for the chart axis — one five-minute slot per bucket key the reply
 * names, oldest first, the window the reply's and never the browser clock's —
 * and for the one decoder every chart reads the name-table wire through.
 */

import {
	buildChartSlots,
	CAT_FIELDS,
	decodeNameTable,
	DIM_FIELDS,
	hasRows,
} from '../chartSlots';
import { nameTable, slotsEndingAt } from '../../test-helpers/chartWire';

/**
 * A reply's slots at 14:37:11 UTC, newest first as the server sends them.
 */
const SLOTS = slotsEndingAt( '2026-09-29-14-35' );

describe( 'buildChartSlots', () => {
	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'draws one five-minute slot per key the reply named, oldest first', () => {
		const slots = buildChartSlots( SLOTS );

		expect( slots ).toHaveLength( 288 );
		expect( slots[ 0 ].bucketKey ).toBe( '2026-09-28-14-40' );
		expect( slots[ 287 ].bucketKey ).toBe( '2026-09-29-14-35' );
		expect( slots[ 287 ].date.toISOString() ).toBe(
			'2026-09-29T14:35:00.000Z'
		);
		expect( new Set( slots.map( ( slot ) => slot.seconds ) ) ).toEqual(
			new Set( [ 300 ] )
		);
	} );

	it( 'draws the window the reply named whatever the browser clock says', () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2026-09-29T15:02:00Z' ) );

		expect( buildChartSlots( SLOTS ).at( -1 ).bucketKey ).toBe(
			'2026-09-29-14-35'
		);
	} );

	it( 'draws no axis before a reply names its slots', () => {
		expect( buildChartSlots( null ) ).toEqual( [] );
	} );
} );

describe( 'decodeNameTable', () => {
	it( 'holds each dimensional row under named fields, by bucket and name index', () => {
		const decoded = decodeNameTable(
			nameTable( {
				'2026-09-29-14-35': {
					'kea-ua/7': [ 41, 820, 3, 41 ],
					'weka-ua/9': [ 43, 4300, 4, 37 ],
				},
			} ),
			DIM_FIELDS
		);

		expect( decoded.names ).toEqual( [ 'kea-ua/7', 'weka-ua/9' ] );
		expect( decoded.byBucket[ '2026-09-29-14-35' ][ 1 ] ).toEqual( {
			count: 43,
			sumMs: 4300,
			sumPeakMb: 4,
			timed: 37,
		} );
	} );

	it( 'names a category row as the category chart reads it', () => {
		const decoded = decodeNameTable(
			nameTable( {
				'2026-09-29-14-35': { memcache: [ 8123, 419, 37 ] },
			} ),
			CAT_FIELDS
		);

		expect( decoded.byBucket[ '2026-09-29-14-35' ][ 0 ] ).toEqual( {
			t: 8123,
			c: 419,
			n: 37,
		} );
	} );

	it( "reads PHP's empty map, a JSON list, as no buckets", () => {
		const decoded = decodeNameTable(
			{ names: [], buckets: [] },
			DIM_FIELDS
		);

		expect( decoded ).not.toBeNull();
		expect( hasRows( decoded ) ).toBe( false );
	} );

	it.each( [
		[ 'no name table', { buckets: {} } ],
		[ 'no buckets', { names: [ 'kea-ua/7' ] } ],
		[ 'a null bucket map', { names: [], buckets: null } ],
		[
			'a bucket map that is a non-empty list',
			{ names: [ 'kea-ua/7' ], buckets: [ [ [ 0, 41, 820, 3, 41 ] ] ] },
		],
		[
			'a bucket that is not a list',
			{ names: [ 'kea-ua/7' ], buckets: { '2026-09-29-14-35': {} } },
		],
		[
			'a row missing a field',
			{
				names: [ 'kea-ua/7' ],
				buckets: { '2026-09-29-14-35': [ [ 0, 41, 820, 3 ] ] },
			},
		],
		[
			'a row naming no name',
			{
				names: [ 'kea-ua/7' ],
				buckets: { '2026-09-29-14-35': [ [ 1, 41, 820, 3, 41 ] ] },
			},
		],
		[
			'a field that is no number',
			{
				names: [ 'kea-ua/7' ],
				buckets: { '2026-09-29-14-35': [ [ 0, '41', 820, 3, 41 ] ] },
			},
		],
		[
			'a name that is no string',
			{
				names: [ 7 ],
				buckets: { '2026-09-29-14-35': [ [ 0, 41, 820, 3, 41 ] ] },
			},
		],
	] )( 'refuses a reply with %s', ( _, wire ) => {
		expect( decodeNameTable( wire, DIM_FIELDS ) ).toBeNull();
	} );
} );

describe( 'hasRows', () => {
	it( 'answers true only when some bucket holds a row', () => {
		expect( hasRows( null ) ).toBe( false );
		expect(
			hasRows(
				decodeNameTable(
					{ names: [], buckets: { '2026-09-29-14-35': [] } },
					DIM_FIELDS
				)
			)
		).toBe( false );
		expect(
			hasRows(
				decodeNameTable(
					nameTable( {
						'2026-09-29-14-35': { '5xx': [ 7, 917, 2, 7 ] },
					} ),
					DIM_FIELDS
				)
			)
		).toBe( true );
	} );
} );
