/**
 * Chart reply fixtures in the wire shapes the `performance` CI answers.
 */

import { decodeNameTable, DIM_FIELDS } from '../overview/chartSlots';

/**
 * Milliseconds one five-minute slot spans.
 *
 * @type {number}
 */
const SLOT_MS = 300000;

/**
 * The `slots` a reply names: `count` bucket keys ending at `last`, newest
 * first.
 *
 * @param {string} last    The newest bucket key, `YYYY-MM-DD-HH-MM`.
 * @param {number} [count] How many; 288 by default, as every reply sends.
 * @return {string[]} Bucket keys, newest first.
 */
export const slotsEndingAt = ( last, count = 288 ) => {
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
 * A `{ bucket: { name: sums } }` series in the name-table wire,
 * `{ names, buckets: { bucket: [ [ nameIndex, ...sums ] ] } }`.
 *
 * @param {Object<string,Object<string,number[]>>} series Bucket => name => sums.
 * @return {{names: string[], buckets: Object<string,Array<number[]>>}} The wire.
 */
export const nameTable = ( series ) => {
	const names = [];
	/** @type {Object<string,Array<number[]>>} */
	const buckets = {};
	Object.entries( series ).forEach( ( [ bucket, entries ] ) => {
		buckets[ bucket ] = Object.entries( entries ).map(
			( [ name, sums ] ) => {
				if ( ! names.includes( name ) ) {
					names.push( name );
				}
				return [ names.indexOf( name ), ...sums ];
			}
		);
	} );
	return { names, buckets };
};

/**
 * A `{ bucket: { name: sums } }` dimensional series decoded as a chart takes
 * it: through the wire, then `decodeNameTable()`.
 *
 * @param {Object<string,Object<string,number[]>>} series Bucket => name => `DIM_SUMS`.
 * @return {ReturnType<typeof decodeNameTable>} The decoded table.
 */
export const dimTable = ( series ) =>
	decodeNameTable( nameTable( series ), DIM_FIELDS );
