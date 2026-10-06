/**
 * Tests for the chart axis — one five-minute slot per bucket key the reply
 * names, oldest first, the window the reply's and never the browser clock's —
 * and for the one decoder every chart reads the name-table wire through.
 */

import * as React from 'react';
import fs from 'fs';
import path from 'path';
import {
	bucketLabel,
	bucketSpan,
	bucketSpelling,
	bucketsOf,
	buildChartSlots,
	CAT_FIELDS,
	decodeNameTable,
	DIM_FIELDS,
	hasRows,
	runsOf,
	runSpan,
	useBucketSelection,
	useSelectedSlots,
	useSlotClick,
	useSlotRange,
} from '../chartSlots';
import { nameTable, slotsEndingAt } from '../../test-helpers/chartWire';
import { act, cleanupMounts, renderHook } from '../../test-helpers/renderHook';

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

describe( 'bucketSpan', () => {
	it( "names a bucket key as its five minutes in the viewer's zone", () => {
		expect( bucketSpan( '2026-10-05-18-40' ) ).toBe( '11:40–11:45 AM' );
		expect( bucketSpan( '2026-10-05-06-55' ) ).toBe( '11:55 PM–12:00 AM' );
	} );

	it( 'answers null for anything not shaped as a bucket key', () => {
		expect( bucketSpan( 'kea-junk' ) ).toBeNull();
		expect( bucketSpan( '2026-10-04-13' ) ).toBeNull();
		expect( bucketSpan( '' ) ).toBeNull();
	} );

	it( 'names each run of a selection as its local span, in order', () => {
		expect(
			bucketSpan( '2026-10-05-16-55..2026-10-05-17-10,2026-10-05-18-30' )
		).toBe( '9:55–10:15 AM, 11:30–11:35 AM' );
	} );

	it( 'answers null for a selection the server would refuse', () => {
		expect( bucketSpan( '2026-10-05-17-10..2026-10-05-16-55' ) ).toBeNull();
	} );
} );

describe( 'bucketLabel', () => {
	it( 'names a bucket key by its span', () => {
		expect( bucketLabel( '2026-10-05-18-40' ) ).toBe( '11:40–11:45 AM' );
	} );

	it( 'names a key of another shape as it arrived', () => {
		expect( bucketLabel( 'kea-junk' ) ).toBe( 'kea-junk' );
	} );
} );

/**
 * The shared fixture the PHP pair answers to as well: one spelling, two
 * implementations, held to each other.
 */
const FIXTURE = JSON.parse(
	fs.readFileSync(
		path.resolve(
			__dirname,
			'../../../tests/fixtures/bucket-selection.json'
		),
		'utf8'
	)
);

/**
 * The key `buckets` five-minute buckets after `key`.
 *
 * @param {string} key     `YYYY-MM-DD-HH-MM`.
 * @param {number} buckets Buckets to step.
 * @return {string} The later key.
 */
const keyAfter = ( key, buckets ) => {
	const [ y, m, d, h, i ] = key.split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d, h, i ) + buckets * 300000 )
		.toISOString()
		.slice( 0, 16 )
		.replace( /[T:]/g, '-' );
};

describe( 'bucketsOf and bucketSpelling', () => {
	it.each( FIXTURE.canonical.map( ( c ) => [ c.spelling, c.keys ] ) )(
		'reads %j as its keys and spells them back',
		( spelling, keys ) => {
			expect( bucketsOf( spelling ) ).toEqual( keys );
			expect( bucketSpelling( keys ) ).toBe( spelling );
		}
	);

	it.each( FIXTURE.normalizes.map( ( c ) => [ c.spelling, c.keys ] ) )(
		'reads the loose spelling %j as ascending keys, each once',
		( spelling, keys ) => {
			expect( bucketsOf( spelling ) ).toEqual( keys );
		}
	);

	it.each( FIXTURE.refused )( 'selects nothing for %j', ( spelling ) => {
		expect( bucketsOf( spelling ) ).toEqual( [] );
	} );

	it( "holds a selection to the fixture's limit, as the server does", () => {
		const first = '2026-10-03-06-40';
		const full = `${ first }..${ keyAfter( first, FIXTURE.max - 1 ) }`;

		expect( bucketsOf( full ) ).toHaveLength( FIXTURE.max );
		expect(
			bucketsOf( `${ first }..${ keyAfter( first, FIXTURE.max ) }` )
		).toEqual( [] );
		expect( bucketsOf( `${ full },2026-10-05-23-55` ) ).toEqual( [] );
	} );
} );

describe( 'runsOf and runSpan', () => {
	it( 'splits ascending keys into runs of adjacent buckets', () => {
		expect(
			runsOf( [
				'2026-10-04-23-55',
				'2026-10-05-00-00',
				'2026-10-05-18-30',
			] )
		).toEqual( [
			[ '2026-10-04-23-55', '2026-10-05-00-00' ],
			[ '2026-10-05-18-30' ],
		] );
	} );

	it( 'names a run by the local span from its first bucket to its last', () => {
		expect( runSpan( [ '2026-10-05-16-55', '2026-10-05-17-00' ] ) ).toBe(
			'9:55–10:05 AM'
		);
		expect( runSpan( [ '2026-10-05-18-55', '2026-10-05-19-00' ] ) ).toBe(
			'11:55 AM–12:05 PM'
		);
	} );

	it( 'dates a run crossing local midnight, and no run ending on it', () => {
		expect( runSpan( [ '2026-10-05-06-55', '2026-10-05-07-00' ] ) ).toBe(
			'Oct 4, 11:55 PM–Oct 5, 12:05 AM'
		);
		expect( runSpan( [ '2026-10-05-06-50', '2026-10-05-06-55' ] ) ).toBe(
			'11:50 PM–12:00 AM'
		);
	} );

	it( 'names the zone at each end of a run the clocks change inside', () => {
		expect( runSpan( [ '2026-11-01-08-55' ] ) ).toBe(
			'1:55 AM PDT–1:00 AM PST'
		);
	} );
} );

describe( 'useSlotClick', () => {
	const axis = buildChartSlots( slotsEndingAt( '2026-10-04-13-35', 4 ) );

	afterEach( cleanupMounts );

	it( "maps the frame's slot index to that slot's bucket key", () => {
		const onSlotClick = jest.fn();
		const { result } = renderHook( () =>
			useSlotClick( axis, onSlotClick )
		);

		result.current( 3, { additive: false } );
		result.current( 1, { additive: true } );

		expect( onSlotClick.mock.calls ).toEqual( [
			[ '2026-10-04-13-35', { additive: false } ],
			[ '2026-10-04-13-25', { additive: true } ],
		] );
	} );

	it( 'hands the frame no callback when nothing listens', () => {
		const { result } = renderHook( () => useSlotClick( axis, undefined ) );

		expect( result.current ).toBeUndefined();
	} );
} );

describe( 'useSlotRange', () => {
	const axis = buildChartSlots( slotsEndingAt( '2026-10-04-13-35', 6 ) );

	afterEach( cleanupMounts );

	it( 'maps a drag over three slots to those three bucket keys', () => {
		const onSlotRange = jest.fn();
		const { result } = renderHook( () =>
			useSlotRange( axis, onSlotRange )
		);

		result.current( 1, 3, { additive: false } );
		result.current( 4, 2, { additive: true } );

		expect( onSlotRange.mock.calls ).toEqual( [
			[
				[ '2026-10-04-13-15', '2026-10-04-13-20', '2026-10-04-13-25' ],
				{ additive: false },
			],
			[
				[ '2026-10-04-13-20', '2026-10-04-13-25', '2026-10-04-13-30' ],
				{ additive: true },
			],
		] );
	} );

	it( 'hands the frame no callback when nothing listens', () => {
		const { result } = renderHook( () => useSlotRange( axis, undefined ) );

		expect( result.current ).toBeUndefined();
	} );
} );

describe( 'useBucketSelection', () => {
	afterEach( cleanupMounts );

	/**
	 * Mount the hook over a selection held in React state, as both owners do.
	 *
	 * @param {string} first The selection's first spelling.
	 * @return {{current: Array}} `[ spelling, selectedBuckets, pickBucket ]`.
	 */
	const mountSelection = ( first ) =>
		renderHook( () => {
			const [ spelling, setSpelling ] = React.useState( first );
			return [ spelling, ...useBucketSelection( spelling, setSpelling ) ];
		} ).result;

	/**
	 * The spelling one click leaves on a held selection.
	 *
	 * @param {string}  held     The held selection's spelling.
	 * @param {string}  key      The clicked bucket.
	 * @param {boolean} additive Whether cmd or ctrl was held.
	 * @return {string} The selection after the click.
	 */
	const afterClick = ( held, key, additive ) => {
		const result = mountSelection( held );
		act( () => result.current[ 2 ]( key, { additive } ) );
		return result.current[ 0 ];
	};

	const HELD = '2026-10-05-16-55..2026-10-05-17-00';

	it( 'replaces the selection with the bucket a plain click names', () => {
		expect( afterClick( HELD, '2026-10-05-18-30', false ) ).toBe(
			'2026-10-05-18-30'
		);
	} );

	it( 'clears the whole selection when a plain click names a held bucket', () => {
		expect( afterClick( HELD, '2026-10-05-17-00', false ) ).toBe( '' );
		expect(
			afterClick( '2026-10-05-18-30', '2026-10-05-18-30', false )
		).toBe( '' );
	} );

	it( 'adds the bucket an additive click names, merging a neighbour', () => {
		expect( afterClick( HELD, '2026-10-05-17-05', true ) ).toBe(
			'2026-10-05-16-55..2026-10-05-17-05'
		);
	} );

	it( 'removes a held bucket a second additive click names', () => {
		expect( afterClick( HELD, '2026-10-05-16-55', true ) ).toBe(
			'2026-10-05-17-00'
		);
		expect(
			afterClick( '2026-10-05-17-00', '2026-10-05-17-00', true )
		).toBe( '' );
	} );

	it( 'starts afresh when the held spelling would be refused', () => {
		expect( afterClick( 'kea-junk', '2026-10-05-18-30', true ) ).toBe(
			'2026-10-05-18-30'
		);
	} );

	/**
	 * The spelling one drag leaves on a held selection.
	 *
	 * @param {string}   held     The held selection's spelling.
	 * @param {string[]} keys     The dragged buckets, ascending.
	 * @param {boolean}  additive Whether cmd or ctrl was held.
	 * @return {string} The selection after the drag.
	 */
	const afterDrag = ( held, keys, additive ) => {
		const result = mountSelection( held );
		act( () => result.current[ 3 ]( keys, { additive } ) );
		return result.current[ 0 ];
	};

	const DRAGGED = [
		'2026-10-05-18-25',
		'2026-10-05-18-30',
		'2026-10-05-18-35',
	];

	it( 'replaces the selection with the buckets a plain drag spans', () => {
		expect( afterDrag( HELD, DRAGGED, false ) ).toBe(
			'2026-10-05-18-25..2026-10-05-18-35'
		);
	} );

	it( 'adds the span an additive drag covers, keeping what was held', () => {
		expect( afterDrag( HELD, DRAGGED, true ) ).toBe(
			'2026-10-05-16-55..2026-10-05-17-00,2026-10-05-18-25..2026-10-05-18-35'
		);
		expect(
			afterDrag( HELD, [ '2026-10-05-17-00', '2026-10-05-17-05' ], true )
		).toBe( '2026-10-05-16-55..2026-10-05-17-05' );
	} );

	it( "reads the held spelling's keys for the charts to shade", () => {
		const result = mountSelection( '2026-10-05-16-55..2026-10-05-17-00' );

		expect( result.current[ 1 ] ).toEqual( [
			'2026-10-05-16-55',
			'2026-10-05-17-00',
		] );
	} );

	it( 'lands every click of one batch, each on the selection the last left', () => {
		const result = mountSelection( '2026-10-05-16-55' );
		const pick = result.current[ 2 ];

		act( () => {
			pick( '2026-10-05-17-00', { additive: true } );
			pick( '2026-10-05-18-30', { additive: true } );
		} );

		expect( result.current[ 0 ] ).toBe(
			'2026-10-05-16-55..2026-10-05-17-00,2026-10-05-18-30'
		);
		expect( result.current[ 2 ] ).toBe( pick );

		act( () => pick( '2026-10-05-17-05', { additive: false } ) );
		expect( result.current[ 0 ] ).toBe( '2026-10-05-17-05' );
	} );
} );

describe( 'useSelectedSlots', () => {
	const axis = buildChartSlots( slotsEndingAt( '2026-10-04-13-35', 4 ) );

	afterEach( cleanupMounts );

	it( 'names the axis indexes of the selected buckets', () => {
		const { result } = renderHook( () =>
			useSelectedSlots( axis, [ '2026-10-04-13-25', '2026-10-04-13-35' ] )
		);

		expect( [ ...result.current ] ).toEqual( [ 1, 3 ] );
	} );

	it( 'ignores a selected bucket the axis does not draw', () => {
		const { result } = renderHook( () =>
			useSelectedSlots( axis, [ '2026-10-03-09-00' ] )
		);

		expect( result.current.size ).toBe( 0 );
	} );
} );
