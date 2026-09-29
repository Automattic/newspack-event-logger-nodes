/**
 * The time axis the Performance charts draw, from the read plan the
 * `performance` CI names in its reply: one slot an hour for every hour it read
 * whole, keyed `YYYY-MM-DD-HH` as the server keys an hour, and one slot per
 * five minutes for the hour it read in buckets, keyed `YYYY-MM-DD-HH-MM`.
 * Every slot carries the seconds it spans, so a chart plots a rate, or a
 * per-bucket mean, that reads the same on both. Nothing here reads a clock:
 * the reply said where it split the window, and the axis splits there too.
 */

import { BUCKET_SECONDS } from '@newspack-nodes/shared/hooks/useTimeChart';

/**
 * Seconds one hour slot spans.
 *
 * @type {number}
 */
const HOUR_SECONDS = 3600;

/**
 * The first instant of an hour or bucket key, in UTC, which is how the
 * server keys both.
 *
 * @param {string} key `YYYY-MM-DD-HH` or `YYYY-MM-DD-HH-MM`.
 * @return {Date} The span's first instant.
 */
const keyStart = ( key ) => {
	const [ y, m, d, h, i = 0 ] = key.split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d, h, i ) );
};

/**
 * Build the slots a chart's time axis is drawn over, oldest first.
 *
 * @param {{fine: string[], hours: string[]}|null} plan The reply's read plan,
 *                                                      each list newest first.
 * @return {Array<{date:Date,bucketKey:string,seconds:number}>} An hour slot
 * per hour read whole, then a five-minute slot per bucket; none before a
 * reply names its plan.
 */
export const buildChartSlots = ( plan ) => [
	...[ ...( plan?.hours ?? [] ) ].reverse().map( ( hour ) => ( {
		date: keyStart( hour ),
		bucketKey: hour,
		seconds: HOUR_SECONDS,
	} ) ),
	...[ ...( plan?.fine ?? [] ) ].reverse().map( ( bucket ) => ( {
		date: keyStart( bucket ),
		bucketKey: bucket,
		seconds: BUCKET_SECONDS,
	} ) ),
];

/**
 * A slot's total read as a five-minute bucket's, so an hour's point sits on
 * the same scale as the buckets beside it: its mean per bucket.
 *
 * @param {number}            total A sum over the slot.
 * @param {{seconds: number}} slot  The slot it was summed over.
 * @return {number} The total per five minutes of the slot.
 */
export const perBucket = ( total, slot ) =>
	( total * BUCKET_SECONDS ) / slot.seconds;
