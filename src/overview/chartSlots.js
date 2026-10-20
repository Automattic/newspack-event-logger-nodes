/**
 * What the Performance charts read off a `performance` reply: the time axis
 * and the series drawn over it.
 *
 * The axis is the buckets a reply read — the `overview` reply's `slots`, or a
 * `query` answer's echoed selection through `answerSlots()`: one five-minute
 * slot per bucket key, `YYYY-MM-DD-HH-MM`, 288 of them ending at the bucket
 * the reply read the clock in. Every slot carries the seconds it spans.
 * Nothing here reads a clock: the reply said which buckets it read, and the
 * axis is those.
 *
 * A series is a `query` answer grouped by `bucket` and the dimensions it
 * breaks down by, one positional row per bucket and tuple. `decodeRows()` is
 * the one reader of that shape: it checks it once and hands every row back
 * under its measures' names, so no chart indexes a row by number.
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
 * Buckets a selection may hold: the chart's 288, `Bucket::MAX_SELECTION`.
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
 * Where one piece of a spelling opens: the twin of `Bucket::of_key()`, so a
 * key passes only when it is the key of its own first instant.
 *
 * @param {string} key A piece of a spelling.
 * @return {?number} The bucket's first instant, in milliseconds; null for a
 * piece that is not a five-minute key.
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
 * twin of `Bucket::selection()`, selecting nothing where that one refuses.
 *
 * A spelling is comma-separated runs over bucket keys, each a lone key, its
 * one bucket, or `start..end`, the buckets from `start` up to the one `end`
 * opens, `end` not among them; `bucketSpelling()` writes the canonical one.
 * '' selects nothing, and so does a piece that is not a five-minute key, a
 * run not ending after it starts, or more than 288 buckets.
 *
 * @param {string} spelling A selection's spelling.
 * @return {string[]} Its keys, ascending; [] when refused.
 */
export const bucketsOf = ( spelling ) => {
	const keys = new Set();
	for ( const run of '' === spelling ? [] : spelling.split( ',' ) ) {
		const cut = run.indexOf( '..' );
		const first = selectedStart( -1 === cut ? run : run.slice( 0, cut ) );
		const close = -1 === cut ? null : selectedStart( run.slice( cut + 2 ) );
		const last = -1 === cut ? first : close - BUCKET_MS;
		if (
			null === first ||
			( -1 !== cut && null === close ) ||
			last < first
		) {
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
 * A clock time in the viewer's locale and zone, `11:40 AM` or `11:40`.
 *
 * @type {Intl.DateTimeFormat}
 */
const CLOCK = new Intl.DateTimeFormat( undefined, {
	hour: 'numeric',
	minute: '2-digit',
} );

/**
 * A clock time with its date, for a run crossing local midnight.
 *
 * @type {Intl.DateTimeFormat}
 */
const DATED = new Intl.DateTimeFormat( undefined, {
	month: 'short',
	day: 'numeric',
	hour: 'numeric',
	minute: '2-digit',
} );

/**
 * A clock time with its zone, for a run the clocks change inside.
 *
 * @type {Intl.DateTimeFormat}
 */
const ZONED = new Intl.DateTimeFormat( undefined, {
	hour: 'numeric',
	minute: '2-digit',
	timeZoneName: 'short',
} );

/**
 * Whether the viewer's locale tells time on a 12-hour clock.
 *
 * @return {boolean} True where `CLOCK` writes AM and PM.
 */
export const twelveHourClock = () => true === CLOCK.resolvedOptions().hour12;

/**
 * A pair of formatted ends with a trailing day period they share written
 * once, at the end, as `11:40–11:45 AM`.
 *
 * @param {Intl.DateTimeFormatPart[][]} ends The start's parts and the end's.
 * @return {Intl.DateTimeFormatPart[][]} The pair, a shared period once.
 */
const sharePeriod = ( [ from, to ] ) =>
	'dayPeriod' === from.at( -1 ).type &&
	from.at( -1 ).value === to.at( -1 ).value
		? [ from.slice( 0, -2 ), to ]
		: [ from, to ];

/**
 * A run as the span it covers in the viewer's zone, from its first bucket's
 * opening to its last bucket's close: dated where it crosses local midnight,
 * zoned where the clocks change inside it.
 *
 * @param {string[]} run Adjacent bucket keys, ascending.
 * @return {string} `11:40–11:45 AM`, in the viewer's locale.
 */
export const runSpan = ( run ) => {
	const start = keyStart( run[ 0 ] );
	const end = new Date( keyStart( run.at( -1 ) ).getTime() + BUCKET_MS );
	const lastMinute = new Date( end.getTime() - 60000 );
	const parts = ( format ) =>
		[ start, end ].map( ( at ) => format.formatToParts( at ) );
	const written = ( ends ) =>
		ends
			.map( ( side ) => side.map( ( part ) => part.value ).join( '' ) )
			.join( '–' );
	if ( start.toDateString() !== lastMinute.toDateString() ) {
		return written( parts( DATED ) );
	}
	if ( start.getTimezoneOffset() !== end.getTimezoneOffset() ) {
		return written( parts( ZONED ) );
	}
	return written( sharePeriod( parts( CLOCK ) ) );
};

/**
 * A selection's canonical spelling: its keys as runs, each adjacent pair
 * merged, a lone bucket spelled as its key and a run of several as its first
 * key and the key closing it. The twin of `Bucket::spelling()`.
 *
 * @param {string[]} keys Bucket keys, ascending, each once.
 * @return {string} The spelling; '' for none.
 */
export const bucketSpelling = ( keys ) =>
	runsOf( keys )
		.map( ( run ) =>
			1 === run.length
				? run[ 0 ]
				: `${ run[ 0 ] }..${ keyAt(
						keyStart( run.at( -1 ) ).getTime() + BUCKET_MS
				  ) }`
		)
		.join( ',' );

/**
 * The selection a chart click leaves: a plain click selects its bucket
 * alone, or clears the selection when that bucket is already held; an
 * additive one adds or removes it.
 *
 * @param {string}  spelling The held selection.
 * @param {string}  key      The clicked bucket.
 * @param {boolean} additive Whether cmd or ctrl was held.
 * @return {string} The new selection's spelling.
 */
const clickSelection = ( spelling, key, additive ) => {
	const held = bucketsOf( spelling );
	if ( ! additive ) {
		return held.includes( key ) ? '' : key;
	}
	return bucketSpelling(
		held.includes( key )
			? held.filter( ( k ) => k !== key )
			: [ ...held, key ].sort()
	);
};

/**
 * The selection a chart drag leaves: a plain drag selects its span alone, an
 * additive one adds the span to what is held.
 *
 * @param {string}   spelling The held selection.
 * @param {string[]} keys     The dragged buckets, ascending.
 * @param {boolean}  additive Whether cmd or ctrl was held.
 * @return {string} The new selection's spelling.
 */
const rangeSelection = ( spelling, keys, additive ) =>
	bucketSpelling(
		[
			...new Set( [
				...( additive ? bucketsOf( spelling ) : [] ),
				...keys,
			] ),
		].sort()
	);

/**
 * A selection as local spans, one per run, the way a filter names it.
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
 * Map the slot range `AreaTimeChart` reports for a drag to the bucket keys
 * from `axis[ from ]` to `axis[ to ]`, ascending, whichever end the drag
 * started from, passing on whether it was additive.
 *
 * @param {Array<{bucketKey:string}>}                                 axis          The axis the series sample.
 * @param {(bucketKeys: string[], drag: {additive: boolean}) => void} [onSlotRange] The chart's listener.
 * @return {((from: number, to: number, drag: {additive: boolean}) => void)|undefined} The frame's drag, held stable; none without a listener.
 */
export const useSlotRange = ( axis, onSlotRange ) =>
	useMemo(
		() =>
			onSlotRange &&
			( ( from, to, { additive } ) =>
				onSlotRange(
					axis
						.slice( Math.min( from, to ), Math.max( from, to ) + 1 )
						.map( ( slot ) => slot.bucketKey ),
					{ additive }
				) ),
		[ axis, onSlotRange ]
	);

/**
 * A selection held as its spelling, read as the keys a chart shades and
 * edited by a chart click or drag. Each edit is an updater over the held
 * spelling, so every edit of one batch lands on the selection the one before
 * it left.
 *
 * @param {string}                                              spelling The held selection's spelling.
 * @param {(update: string|((held: string) => string)) => void} onChange The holder's setter, taking a spelling or an updater of one.
 * @return {[string[], (bucketKey: string, click: {additive: boolean}) => void, (bucketKeys: string[], drag: {additive: boolean}) => void]} The selection's keys, the click that edits it and the drag that edits it, both held stable.
 */
export const useBucketSelection = ( spelling, onChange ) => [
	useMemo( () => bucketsOf( spelling ), [ spelling ] ),
	useCallback(
		( key, { additive } ) =>
			onChange( ( held ) => clickSelection( held, key, additive ) ),
		[ onChange ]
	),
	useCallback(
		( keys, { additive } ) =>
			onChange( ( held ) => rangeSelection( held, keys, additive ) ),
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
 * How a field's value reads in a series name where the answer's own spelling
 * would not, and how a typed one reads back: a status class as `5xx`, and
 * the class of a request that answered no status as `Unknown` in any case.
 *
 * @type {Object<string,{name: (value: *) => string, read: (typed: string) => string}>}
 */
const SPELLINGS = {
	status_class: {
		name: ( value ) =>
			/^\d$/.test( String( value ) ) ? `${ value }xx` : String( value ),
		read: ( typed ) =>
			typed
				.replace( /^(\d)xx$/i, '$1' )
				.replace( /^unknown$/i, 'Unknown' ),
	},
};

/**
 * A field's value as a series name or a chip spells it: a status class as
 * `5xx`, anything else as it is.
 *
 * @param {string} field The field it is a value of.
 * @param {*}      value The value, as an answer or a chip holds it.
 * @return {string} Its name.
 */
export const valueName = ( field, value ) =>
	( SPELLINGS[ field ]?.name ?? String )( value );

/**
 * A typed filter value as the answer spells it, so `status_class=5xx`
 * filters what a `5xx` series names.
 *
 * @param {string} field The field it filters.
 * @param {string} typed The value as typed.
 * @return {string} The value as `query` matches it.
 */
export const filterValue = ( field, typed ) =>
	SPELLINGS[ field ]?.read( typed ) ?? typed;

/**
 * A chip's parts: the field it names, whether it negates, as
 * `field!=value` does, and its value. A chip with no `=` names no field.
 *
 * @param {string} chip A chip, `field=value` or `field!=value`.
 * @return {{field: string, negated: boolean, value: string}} Its parts.
 */
export const chipParts = ( chip ) => {
	const cut = chip.indexOf( '=' );
	const negated = cut > 0 && '!' === chip[ cut - 1 ];
	return {
		field: cut < 0 ? '' : chip.slice( 0, negated ? cut - 1 : cut ),
		negated,
		value: cut < 0 ? '' : chip.slice( cut + 1 ),
	};
};

/**
 * One `--filter=` token for each filter, since the verbs take the option
 * repeated where `formatCommandArgs()` would join a list on commas.
 *
 * @param {string[]} filters Each filter as `field=value`.
 * @return {string[]} The tokens.
 */
export const filterTokens = ( filters ) =>
	filters.map( ( filter ) => `--filter=${ filter }` );

/**
 * The chips a one-URL question keeps: every one but a chip on `url`, which
 * names the URLs a table was narrowed to rather than a narrowing of one URL.
 *
 * @param {string[]} chips Each chip as `field=value` or `field!=value`.
 * @return {string[]} The chips, less any on `url`.
 */
export const withoutUrlChips = ( chips ) =>
	chips.filter( ( chip ) => 'url' !== chipParts( chip ).field );

/**
 * One row's series name: its dimensions' values, ` · `-joined.
 *
 * @param {Array}    row  The answer's row.
 * @param {string[]} dims The dimensions, in name order.
 * @param {number[]} at   Each dimension's column.
 * @return {string} The name.
 */
const seriesName = ( row, dims, at ) =>
	dims.map( ( dim, i ) => valueName( dim, row[ at[ i ] ] ) ).join( ' · ' );

/**
 * The axes the aggregate chart draws for the axes chosen. One server draws a
 * single bar, and a server filter draws that server against itself, so
 * neither can chart `server`: a first `server` axis falls back to
 * `status_class` and a second one falls away. The fallback is derived, never
 * written back over the choice, so a reply carrying one server before its
 * sibling reports does not strand the choice for the session; `serverNames`
 * null is a reply not yet landed, which is neither.
 *
 * @param {Object}    arg              Named arguments.
 * @param {string}    arg.breakdown    The chosen first axis.
 * @param {string}    arg.thenBy       The chosen second axis; '' for none.
 * @param {string}    arg.serverFilter Server scope; '' means every server.
 * @param {?string[]} arg.serverNames  The servers the overview names, or
 *                                     null before it answers.
 * @return {{breakdown: string, thenBy: string, canBreakDownByServer: boolean}} The axes drawn, and whether `server` is one it can draw.
 */
export const activeChart = ( {
	breakdown,
	thenBy,
	serverFilter,
	serverNames,
} ) => {
	const canBreakDownByServer =
		! serverFilter && ( serverNames === null || serverNames.length >= 2 );
	const fallsBack = ( axis ) => 'server' === axis && ! canBreakDownByServer;
	return {
		breakdown: fallsBack( breakdown ) ? 'status_class' : breakdown,
		thenBy: fallsBack( thenBy ) ? '' : thenBy,
		canBreakDownByServer,
	};
};

/**
 * The dimensions a chart breaks down by: its first, and its second where one
 * is chosen and differs from the first.
 *
 * @param {{breakdown: string, thenBy: string}} chart The chart's dimensions.
 * @return {string[]} One or two field names.
 */
export const chartDims = ( { breakdown, thenBy } ) =>
	thenBy && thenBy !== breakdown ? [ breakdown, thenBy ] : [ breakdown ];

/**
 * Decode a `query` answer grouped by `bucket` and `dims` into one series per
 * tuple of `dims`, checking the shape once. An answer that breaks it anywhere
 * is refused whole, so a caller draws its empty state rather than a series
 * with holes it cannot see.
 *
 * @param {*}        answer   The `query` answer: `{ columns, rows }`.
 * @param {string[]} dims     The non-time fields it groups by.
 * @param {string[]} measures The measures each series reads.
 * @return {{names: string[], byBucket: Object<string,Object<string,Object<string,number>>>}|null}
 * `names` in the order the answer ranked them, and `byBucket[ bucket ][ name ]`
 * one row's measures by name; null for a malformed answer.
 */
export const decodeRows = ( answer, dims, measures ) => {
	const columns = answer?.columns;
	if ( ! Array.isArray( columns ) || ! Array.isArray( answer.rows ) ) {
		return null;
	}
	const bucketAt = columns.indexOf( 'bucket' );
	const dimAt = dims.map( ( dim ) => columns.indexOf( dim ) );
	const measureAt = measures.map( ( measure ) => columns.indexOf( measure ) );
	if ( [ bucketAt, ...dimAt, ...measureAt ].includes( -1 ) ) {
		return null;
	}
	const names = [];
	/** @type {Object<string,Object<string,Object<string,number>>>} */
	const byBucket = {};
	for ( const row of answer.rows ) {
		if (
			! Array.isArray( row ) ||
			columns.length !== row.length ||
			! /^\d{4}(-\d{2}){4}$/.test( row[ bucketAt ] ) ||
			! measureAt.every( ( at ) => Number.isFinite( row[ at ] ) )
		) {
			return null;
		}
		const name = seriesName( row, dims, dimAt );
		if ( ! names.includes( name ) ) {
			names.push( name );
		}
		byBucket[ row[ bucketAt ] ] = {
			...byBucket[ row[ bucketAt ] ],
			[ name ]: Object.fromEntries(
				measures.map( ( measure, i ) => [
					measure,
					row[ measureAt[ i ] ],
				] )
			),
		};
	}
	return { names, byBucket };
};

/**
 * True when a decoded answer holds a series to draw.
 *
 * @param {{names: string[]}|null} decoded A `decodeRows()` result.
 * @return {boolean} True when it names at least one series.
 */
export const hasRows = ( decoded ) =>
	null !== decoded && decoded.names.length > 0;

/**
 * The buckets a `query` answer read, as its echoed selection names them:
 * the axis its series are drawn over.
 *
 * @param {?{filters: {bucket: string}}} answer A `query` answer, or null.
 * @return {string[]|null} Bucket keys, newest first; null before an answer.
 */
export const answerSlots = ( answer ) =>
	answer ? bucketsOf( answer.filters.bucket ).reverse() : null;

/**
 * Whether a chart's answer ranked more series than it holds.
 *
 * @param {{names: string[]}|null} decoded The chart's decoded answer.
 * @param {?number}                groups  The tuples its selection holds.
 * @return {boolean} True when the top left some series out.
 */
export const leavesSeriesOut = ( decoded, groups ) =>
	hasRows( decoded ) && groups > decoded.names.length;
