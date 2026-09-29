/**
 * Tests for the mixed-resolution chart axis: an hour a slot for every hour
 * the reply read whole, five minutes a slot for the hour it read in buckets.
 * The split is the reply's, never the browser clock's.
 */

import { buildChartSlots, perBucket } from '../chartSlots';

/**
 * A reply's read plan at 07:00:40 UTC, newest first as the server sends it:
 * the current hour's one bucket, and the three whole hours before it.
 */
const PLAN = {
	fine: [ '2026-09-29-07-00' ],
	hours: [ '2026-09-29-06', '2026-09-29-05', '2026-09-29-04' ],
};

describe( 'buildChartSlots', () => {
	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'keys every hour the reply read whole once, from its UTC start', () => {
		const hours = buildChartSlots( PLAN ).filter(
			( slot ) => 3600 === slot.seconds
		);

		expect( hours.map( ( slot ) => slot.bucketKey ) ).toEqual( [
			'2026-09-29-04',
			'2026-09-29-05',
			'2026-09-29-06',
		] );
		expect( hours[ 2 ].date.toISOString() ).toBe(
			'2026-09-29T06:00:00.000Z'
		);
	} );

	it( 'keeps the hour the reply read in buckets in five-minute slots', () => {
		const fine = buildChartSlots( PLAN ).filter(
			( slot ) => 300 === slot.seconds
		);

		expect( fine.map( ( slot ) => slot.bucketKey ) ).toEqual( [
			'2026-09-29-07-00',
		] );
		expect( fine[ 0 ].date.toISOString() ).toBe(
			'2026-09-29T07:00:00.000Z'
		);
	} );

	it.each( [
		[ 'sixty seconds behind', '2026-09-29T06:59:40Z' ],
		[ 'sixty seconds ahead', '2026-09-29T07:01:40Z' ],
	] )(
		'splits where the reply did with the browser clock %s',
		( _, clock ) => {
			jest.useFakeTimers();
			jest.setSystemTime( new Date( clock ) );

			const slots = buildChartSlots( PLAN );

			expect(
				slots.map( ( slot ) => [ slot.bucketKey, slot.seconds ] )
			).toEqual( [
				[ '2026-09-29-04', 3600 ],
				[ '2026-09-29-05', 3600 ],
				[ '2026-09-29-06', 3600 ],
				[ '2026-09-29-07-00', 300 ],
			] );
		}
	);

	it( 'draws no axis before a reply names its plan', () => {
		expect( buildChartSlots( null ) ).toEqual( [] );
	} );

	it( 'reads a slot as a five-minute mean, so an hour and a bucket share a scale', () => {
		expect( perBucket( 1200, { seconds: 3600 } ) ).toBe( 100 );
		expect( perBucket( 37, { seconds: 300 } ) ).toBe( 37 );
	} );
} );
