/**
 * Chart reply fixtures in the shapes the `performance` CI's `query` answers.
 */

import { decodeRows } from '../overview/chartSlots';
import { SERIES_MEASURES } from '../overview/constants';

/**
 * Milliseconds one five-minute slot spans.
 *
 * @type {number}
 */
const SLOT_MS = 300000;

/**
 * The `slots` a reply names: `count` bucket keys ending at `last`, newest
 * first. A reply sends as many as the server's window holds, so every
 * fixture names its own count.
 *
 * @param {string} last  The newest bucket key, `YYYY-MM-DD-HH-MM`.
 * @param {number} count How many.
 * @return {string[]} Bucket keys, newest first.
 */
export const slotsEndingAt = ( last, count ) => {
	const [ y, m, d, h, i ] = last.split( '-' ).map( Number );
	const end = Date.UTC( y, m - 1, d, h, i );
	return Array.from( { length: count }, ( _, back ) =>
		new Date( end - back * SLOT_MS )
			.toISOString()
			.slice( 0, 16 )
			.replace( /[T:]/g, '-' )
	);
};

/**
 * A `query` answer holding `rows` under `columns`, over the selection it
 * echoes; by default the 24 buckets ending at 2026-09-29 14:35 UTC.
 *
 * @param {string[]} columns The answer's columns, groups then measures.
 * @param {Array[]}  rows    Its rows, each positional in `columns` order.
 * @param {Object}   [extra] Fields to set over the defaults, such as `groups`.
 * @return {Object} The answer as the `query` verb sends it.
 */
export const rowsWire = ( columns, rows, extra = {} ) => ( {
	source: 'requests',
	table: 'requests',
	columns,
	rows,
	groups: null,
	available: true,
	filters: {
		filter: {},
		bucket: '2026-09-29-12-40..2026-09-29-14-40',
		search: '',
	},
	...extra,
} );

/**
 * A `{ bucket: { name: measures } }` series as a `query` answer grouped by
 * `bucket` and one dimension, each name a value of it.
 *
 * @param {string}                                 dim      The dimension's column.
 * @param {string[]}                               measures The measures each row carries.
 * @param {Object<string,Object<string,number[]>>} series   Bucket => name => values in `measures` order.
 * @param {Object}                                 [extra]  Answer fields to set, as `groups`.
 * @return {Object} The answer.
 */
export const seriesWire = ( dim, measures, series, extra = {} ) =>
	rowsWire(
		[ 'bucket', dim, ...measures ],
		Object.entries( series ).flatMap( ( [ bucket, names ] ) =>
			Object.entries( names ).map( ( [ name, values ] ) => [
				bucket,
				name,
				...values,
			] )
		),
		extra
	);

/**
 * A `{ bucket: { name: [ count, sum_ms, sum_peak_mb, timed ] } }` series
 * decoded as the aggregate chart takes it: through the answer, then
 * `decodeRows()`.
 *
 * @param {Object<string,Object<string,number[]>>} series Bucket => name => `SERIES_MEASURES`.
 * @return {ReturnType<typeof decodeRows>} The decoded series.
 */
export const dimSeries = ( series ) =>
	decodeRows(
		seriesWire( 'series', SERIES_MEASURES, series ),
		[ 'series' ],
		SERIES_MEASURES
	);
