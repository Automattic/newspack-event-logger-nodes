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

import { useCallback, useMemo } from '@wordpress/element';
import {
	BUCKET_MS,
	BUCKET_SECONDS,
} from '@newspack-nodes/shared/hooks/useTimeChart';

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
 * Buckets a selection may hold: the chart's 288, `MAX_READ_BUCKETS` in PHP.
 *
 * @type {number}
 */
const SELECTION_MAX = 288;

/**
 * The bucket key an instant falls in, `YYYY-MM-DD-HH-MM` UTC.
 *
 * @param {number} ms A bucket's first instant, in milliseconds.
 * @return {string} Its key.
 */
const keyAt = ( ms ) =>
	new Date( ms ).toISOString().slice( 0, 16 ).replace( /[T:]/g, '-' );

/**
 * Where one piece of a spelling opens: the twin of
 * `Stats_Store::selected_start()`, so a key passes only when it is the key of
 * its own first instant.
 *
 * @param {string} key A piece of a spelling.
 * @return {?number} The bucket's first instant, in milliseconds; null for a
 * piece that is no five-minute key.
 */
const selectedStart = ( key ) => {
	if ( ! /^\d{4}(-\d{2}){4}$/.test( key ) ) {
		return null;
	}
	const ms = keyStart( key ).getTime();
	return keyAt( ms ) === key && 0 === ms % BUCKET_MS ? ms : null;
};

/**
 * The bucket keys a selection's spelling names, ascending, each once: the
 * twin of `Stats_Store::bucket_selection()`, selecting nothing where that
 * one refuses.
 *
 * A spelling is comma-separated runs, each `start` or `start..end` over
 * bucket keys; `bucketSpelling()` writes the canonical one. '' selects
 * nothing, and so does a piece that is no five-minute key, a run ending
 * before it starts, or more than 288 buckets.
 *
 * @param {string} spelling A selection's spelling.
 * @return {string[]} Its keys, ascending; [] when refused.
 */
export const bucketsOf = ( spelling ) => {
	const keys = new Set();
	for ( const run of '' === spelling ? [] : spelling.split( ',' ) ) {
		const cut = run.indexOf( '..' );
		const first = selectedStart( -1 === cut ? run : run.slice( 0, cut ) );
		const last = -1 === cut ? first : selectedStart( run.slice( cut + 2 ) );
		if ( null === first || null === last || last < first ) {
			return [];
		}
		for (
			let at = first;
			at <= last && keys.size <= SELECTION_MAX;
			at += BUCKET_MS
		) {
			keys.add( keyAt( at ) );
		}
		if ( keys.size > SELECTION_MAX ) {
			return [];
		}
	}
	return [ ...keys ].sort();
};

/**
 * Split ascending keys into runs of adjacent buckets.
 *
 * @param {string[]} keys Bucket keys, ascending, each once.
 * @return {string[][]} The runs, each its keys in order.
 */
export const runsOf = ( keys ) =>
	keys.reduce( ( runs, key ) => {
		const run = runs.at( -1 );
		if (
			run &&
			keyStart( key ).getTime() ===
				keyStart( run.at( -1 ) ).getTime() + BUCKET_MS
		) {
			run.push( key );
		} else {
			runs.push( [ key ] );
		}
		return runs;
	}, /** @type {string[][]} */ ( [] ) );

/**
 * A run as the UTC span it covers, from its first bucket's opening to its
 * last bucket's close.
 *
 * @param {string[]} run Adjacent bucket keys, ascending.
 * @return {string} `HH:MM–HH:MM UTC`.
 */
export const runSpan = ( run ) => {
	const hhmm = ( ms ) => new Date( ms ).toISOString().slice( 11, 16 );
	return `${ hhmm( keyStart( run[ 0 ] ).getTime() ) }–${ hhmm(
		keyStart( run.at( -1 ) ).getTime() + BUCKET_MS
	) } UTC`;
};

/**
 * A selection's canonical spelling: its keys as runs, each adjacent pair
 * merged, a lone bucket spelled alone. The twin of
 * `Stats_Store::bucket_spelling()`.
 *
 * @param {string[]} keys Bucket keys, ascending, each once.
 * @return {string} The spelling; '' for none.
 */
export const bucketSpelling = ( keys ) =>
	runsOf( keys )
		.map( ( run ) =>
			1 === run.length ? run[ 0 ] : `${ run[ 0 ] }..${ run.at( -1 ) }`
		)
		.join( ',' );

/**
 * The selection a chart click leaves: a plain click selects its bucket
 * alone, an additive one adds or removes it.
 *
 * @param {string}  spelling The held selection.
 * @param {string}  key      The clicked bucket.
 * @param {boolean} additive Whether cmd or ctrl was held.
 * @return {string} The new selection's spelling.
 */
const clickSelection = ( spelling, key, additive ) => {
	if ( ! additive ) {
		return key;
	}
	const held = bucketsOf( spelling );
	return bucketSpelling(
		held.includes( key )
			? held.filter( ( k ) => k !== key )
			: [ ...held, key ].sort()
	);
};

/**
 * A selection as UTC spans, one per run, the way a filter names it.
 *
 * @param {?string} spelling A candidate selection's spelling.
 * @return {?string} Each run's `runSpan()`, comma-joined; null for none or
 * for a spelling the server would refuse.
 */
export const bucketSpan = ( spelling ) => {
	const runs = runsOf(
		'string' === typeof spelling ? bucketsOf( spelling ) : []
	);
	return runs.length ? runs.map( runSpan ).join( ', ' ) : null;
};

/**
 * A selection as a reader sees it. A spelling of another shape — a stale or
 * hand-edited `?bucket=` the server refuses — reads as it arrived, so the
 * control that clears it can still name it.
 *
 * @param {string} spelling A candidate selection's spelling.
 * @return {string} Its `bucketSpan()`, or the spelling itself.
 */
export const bucketLabel = ( spelling ) => bucketSpan( spelling ) ?? spelling;

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
 * Map the slot index `AreaTimeChart` reports back to that slot's bucket key,
 * passing on whether the click was additive.
 *
 * @param {Array<{bucketKey:string}>}                               axis          The axis the series sample.
 * @param {(bucketKey: string, click: {additive: boolean}) => void} [onSlotClick] The chart's listener.
 * @return {((index: number, click: {additive: boolean}) => void)|undefined} The frame's click, held stable; none without a listener.
 */
export const useSlotClick = ( axis, onSlotClick ) =>
	useMemo(
		() =>
			onSlotClick &&
			( ( at, { additive } ) =>
				onSlotClick( axis[ at ].bucketKey, { additive } ) ),
		[ axis, onSlotClick ]
	);

/**
 * A selection held as its spelling, read as the keys a chart shades and
 * edited by a chart click. The edit is an updater over the held spelling, so
 * every click of one batch lands on the selection the click before it left.
 *
 * @param {string}                                              spelling The held selection's spelling.
 * @param {(update: string|((held: string) => string)) => void} onChange The holder's setter, taking a spelling or an updater of one.
 * @return {[string[], (bucketKey: string, click: {additive: boolean}) => void]} The selection's keys, and the click that edits it, held stable.
 */
export const useBucketSelection = ( spelling, onChange ) => [
	useMemo( () => bucketsOf( spelling ), [ spelling ] ),
	useCallback(
		( key, { additive } ) =>
			onChange( ( held ) => clickSelection( held, key, additive ) ),
		[ onChange ]
	),
];

/**
 * The axis indexes a chart shades for the selected buckets.
 *
 * @param {Array<{bucketKey:string}>} axis            The axis the series sample.
 * @param {string[]}                  selectedBuckets The selection's keys.
 * @return {Set<number>} The indexes of the selected buckets the axis draws.
 */
export const useSelectedSlots = ( axis, selectedBuckets ) =>
	useMemo( () => {
		const selected = new Set( selectedBuckets );
		return new Set(
			axis.flatMap( ( slot, at ) =>
				selected.has( slot.bucketKey ) ? [ at ] : []
			)
		);
	}, [ axis, selectedBuckets ] );

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
