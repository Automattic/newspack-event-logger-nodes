/**
 * What the Performance charts read off a `performance` reply: the time axis
 * and the series drawn over it.
 *
 * The axis is the reply's `slots`: one five-minute slot per bucket key,
 * `YYYY-MM-DD-HH-MM`, 288 of them ending at the bucket the reply read the
 * clock in. Every slot carries the seconds it spans. Nothing here reads a
 * clock: the reply said which slots it drew, and the axis is those.
 *
 * A series is a name table, `{ names, buckets: { bucket: [ [ nameIndex,
 * ...fields ] ] } }` (decision 18 at the wire). `decodeNameTable()` is the
 * one reader of that shape: it checks it once and hands every row back under
 * named fields, so no chart indexes a row by number.
 */

import { BUCKET_SECONDS } from '@newspack-nodes/shared/hooks/useTimeChart';

/**
 * The first instant of a bucket key, in UTC, which is how the server keys it.
 *
 * @param {string} key `YYYY-MM-DD-HH-MM`.
 * @return {Date} The bucket's first instant.
 */
const keyStart = ( key ) => {
	const [ y, m, d, h, i ] = key.split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d, h, i ) );
};

/**
 * Build the slots a chart's time axis is drawn over, oldest first.
 *
 * @param {string[]|null} slots The reply's bucket keys, newest first.
 * @return {Array<{date:Date,bucketKey:string,seconds:number}>} A five-minute
 * slot per key; none before a reply names its slots.
 */
export const buildChartSlots = ( slots ) =>
	[ ...( slots ?? [] ) ].reverse().map( ( bucket ) => ( {
		date: keyStart( bucket ),
		bucketKey: bucket,
		seconds: BUCKET_SECONDS,
	} ) );

/**
 * A dimensional row's fields, in wire order: `DIM_SUMS` — requests, the
 * timed ones' summed milliseconds, summed peak MB, and the timed requests,
 * which an average divides by (decision 24).
 *
 * @type {string[]}
 */
export const DIM_FIELDS = [ 'count', 'sumMs', 'sumPeakMb', 'timed' ];

/**
 * A category row's fields, in wire order: the `CAT_SUMS` triple — `t`
 * milliseconds of wall time, `c` events fired, `n` requests it appeared in.
 *
 * @type {string[]}
 */
export const CAT_FIELDS = [ 't', 'c', 'n' ];

/**
 * True when a reply's bucket map has the shape a name table promises. PHP
 * encodes an EMPTY map as a JSON list, so `[]` is a bucket map with nothing
 * in it, and any other list is not a bucket map at all.
 *
 * @param {*} buckets The reply's `buckets`.
 * @return {boolean} True for an object, or an empty list.
 */
const isBucketMap = ( buckets ) =>
	Array.isArray( buckets )
		? 0 === buckets.length
		: null !== buckets && 'object' === typeof buckets;

/**
 * Decode a name-table series into rows under named fields, checking the
 * shape once. A reply that breaks it anywhere is refused whole, so a caller
 * draws its empty state rather than a series with holes it cannot see.
 *
 * @param {*}        wire   The reply's series.
 * @param {string[]} fields The row's field names, in wire order.
 * @return {{names: string[], byBucket: Object<string,Object<number,Object<string,number>>>, rows: number}|null}
 * `byBucket[ bucket ][ nameIndex ]` is one row's fields by name, and `rows`
 * counts them; null for a malformed reply.
 */
export const decodeNameTable = ( wire, fields ) => {
	const names = wire?.names;
	if (
		! Array.isArray( names ) ||
		! names.every( ( name ) => 'string' === typeof name ) ||
		! isBucketMap( wire.buckets )
	) {
		return null;
	}
	const width = fields.length + 1;
	/** @type {Object<string,Object<number,Object<string,number>>>} */
	const byBucket = {};
	let rows = 0;
	for ( const [ bucket, list ] of Object.entries( wire.buckets ) ) {
		if ( ! Array.isArray( list ) ) {
			return null;
		}
		/** @type {Object<number,Object<string,number>>} */
		const row = {};
		for ( const values of list ) {
			if (
				! Array.isArray( values ) ||
				width !== values.length ||
				! values.every( Number.isFinite ) ||
				undefined === names[ values[ 0 ] ]
			) {
				return null;
			}
			row[ values[ 0 ] ] = Object.fromEntries(
				fields.map( ( field, at ) => [ field, values[ at + 1 ] ] )
			);
			rows++;
		}
		byBucket[ bucket ] = row;
	}
	return { names, byBucket, rows };
};

/**
 * True when a decoded series holds a row to draw. Buckets alone are not
 * content: a bucket with no rows has nothing to plot.
 *
 * @param {{rows: number}|null} decoded A `decodeNameTable()` result.
 * @return {boolean} True when at least one bucket holds a row.
 */
export const hasRows = ( decoded ) => null !== decoded && decoded.rows > 0;
