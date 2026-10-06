/**
 * The Time field: the five-minute buckets a list is narrowed to. The URL table
 * and the URL modal each hold one ahead of their Errors Only toggle; each
 * edits its own selection, which a chart click edits too.
 *
 * The field speaks the viewer's local time, as the charts' axis and tooltips
 * do; the bucket keys it edits stay UTC. A token is a run of adjacent
 * buckets, named by its local span and removable. Typing `16:55` and Enter
 * adds the latest charted bucket opening at that local time, and
 * `16:55-17:30` the latest run from 16:55 through the bucket opening at
 * 17:30. A bare time reads on a 24-hour clock; where the locale's clock is
 * 12-hour, `4:55 PM` and `4:55pm` read too, and a range writes AM or PM on
 * both ends or neither. A local time the clocks pass twice selects the later
 * bucket, and one they skip is uncharted. The suggestions are the charted
 * buckets, and picking one adds it at once. A range whose end falls before
 * its start crosses midnight when the wrapped run is 12 hours or less, as
 * `23:50-00:05` does; longer, it reads as typed backwards, so `17:30-16:55`
 * is refused and offered the right way round. A forward range of any length,
 * such as `04:00-17:30`, is taken. A time the field cannot read, or one no
 * charted bucket opens at, is refused beneath it and stays in the box. Each
 * token's × removes it, and Backspace in an empty box removes the last;
 * Escape drops the typed text and its refusal. No clear-everything button
 * sits beside the search box, where it would read as clearing the search.
 *
 * Built on `TokenInput` rather than `FormTokenField`: that one announces a
 * refusal without showing it, matches suggestions against stored values
 * rather than what is typed, and wears chrome the shared skins do not paint.
 */

import { __, sprintf } from '@wordpress/i18n';
import TokenInput from '../../components/TokenInput';
import {
	bucketSpelling,
	bucketsOf,
	buildChartSlots,
	runsOf,
	runSpan,
	twelveHourClock,
} from '../chartSlots';
import '../../settings/styles/tag-input.scss';

/** A typed time: hour, colon, two digits of minute, then AM or PM if any. */
const TIME = /^(\d{1,2}):([0-5]\d)(?:\s?([ap])m)?$/i;

/** A typed time naming its day period. */
const PERIOD = /m$/i;

/** The longest run a range crossing midnight names, in minutes. */
const MAX_RUN_MINUTES = 12 * 60;

/** Minutes in a day, which a range crossing midnight wraps through. */
const DAY_MINUTES = 24 * 60;

/** What to type, as typed: input syntax rather than translatable prose. */
const TYPED_TIME = '16:55';
const TYPED_TWELVE = '4:55 PM';
const TYPED_RANGE = '16:55-17:30';

/**
 * Minutes past local midnight of a slot's opening.
 *
 * @param {{date: Date}} slot A chart slot.
 * @return {number} Its local minutes past midnight.
 */
const minutesOf = ( { date } ) => date.getHours() * 60 + date.getMinutes();

/**
 * A slot's opening as the field reads it back: 12-hour on a 12-hour clock.
 *
 * @param {{date: Date}} slot   A chart slot.
 * @param {boolean}      twelve Whether the locale's clock is 12-hour.
 * @return {string} `4:55 PM`, or `16:55`.
 */
const typedAt = ( { date }, twelve ) => {
	const hour = date.getHours();
	const minute = String( date.getMinutes() ).padStart( 2, '0' );
	return twelve
		? `${ hour % 12 || 12 }:${ minute } ${ hour < 12 ? 'AM' : 'PM' }`
		: `${ String( hour ).padStart( 2, '0' ) }:${ minute }`;
};

/**
 * The minutes past local midnight a typed time names: 24-hour bare, 12-hour
 * with AM or PM where the locale's clock is.
 *
 * @param {string}  text   One typed time.
 * @param {boolean} twelve Whether the locale's clock is 12-hour.
 * @return {?number} Its minutes past midnight; null for no time.
 */
const readTime = ( text, twelve ) => {
	const match = TIME.exec( text );
	if ( ! match ) {
		return null;
	}
	const hour = Number( match[ 1 ] );
	const minute = Number( match[ 2 ] );
	if ( ! match[ 3 ] ) {
		return hour < 24 ? hour * 60 + minute : null;
	}
	if ( ! twelve || hour < 1 || hour > 12 ) {
		return null;
	}
	const pm = 'p' === match[ 3 ].toLowerCase();
	return ( ( hour % 12 ) + ( pm ? 12 : 0 ) ) * 60 + minute;
};

/**
 * The buckets a typed local time or range names: the latest charted bucket
 * opening at its end, back to the latest at its start before that.
 *
 * @param {string}                              text What was typed.
 * @param {Array<{date:Date,bucketKey:string}>} axis The chart's slots, oldest first.
 * @return {{keys: string[], refusal: ?string}} The run, oldest first, and
 * null; or no keys and why the text names none.
 */
const typedRun = ( text, axis ) => {
	const pieces = text.split( /\s*[-–]\s*/ );
	const twelve = twelveHourClock();
	const ends = pieces.map( ( piece ) => readTime( piece, twelve ) );
	const periods = new Set( pieces.map( ( piece ) => PERIOD.test( piece ) ) );
	if ( pieces.length > 2 || ends.includes( null ) || periods.size > 1 ) {
		return {
			keys: [],
			refusal: sprintf(
				// translators: 1: what was typed, 2: a time, 3: a range of times.
				__(
					'"%1$s" is not a time: type %2$s, or %3$s.',
					'newspack-event-logger-nodes'
				),
				text,
				TYPED_TIME,
				TYPED_RANGE
			),
		};
	}
	const [ from, to = from ] = ends;
	if (
		from > to &&
		( to - from + DAY_MINUTES ) % DAY_MINUTES > MAX_RUN_MINUTES
	) {
		return {
			keys: [],
			refusal: sprintf(
				// translators: 1: the range as typed, 2: the range reversed.
				__(
					'%1$s runs backwards; did you mean %2$s?',
					'newspack-event-logger-nodes'
				),
				pieces.join( '-' ),
				[ ...pieces ].reverse().join( '-' )
			),
		};
	}
	const times = axis.map( minutesOf );
	const last = times.lastIndexOf( to );
	const first = -1 === last ? -1 : times.lastIndexOf( from, last );
	return -1 === first
		? {
				keys: [],
				refusal: sprintf(
					// translators: %s: a local time as typed, e.g. 16:55.
					__(
						'No charted bucket opens at %s.',
						'newspack-event-logger-nodes'
					),
					-1 === last ? pieces.at( -1 ) : pieces[ 0 ]
				),
		  }
		: {
				keys: axis
					.slice( first, last + 1 )
					.map( ( slot ) => slot.bucketKey ),
				refusal: null,
		  };
};

/**
 * The charted buckets to pick from, newest first. A time the window holds
 * twice, as a DST change or a full day can, is offered once, as the latest:
 * the bucket typing it selects.
 *
 * @param {Array<{date:Date,bucketKey:string}>} axis   The chart's slots, oldest first.
 * @param {boolean}                             twelve Whether the locale's clock is 12-hour.
 * @return {Array<{value: string, label: string}>} Each pick and its span.
 */
const suggestions = ( axis, twelve ) => {
	const byValue = new Map();
	for ( const slot of [ ...axis ].reverse() ) {
		const value = typedAt( slot, twelve );
		if ( ! byValue.has( value ) ) {
			byValue.set( value, {
				value,
				label: runSpan( [ slot.bucketKey ] ),
			} );
		}
	}
	return [ ...byValue.values() ];
};

/**
 * The Time field.
 *
 * @param {Object}                     props          Component props.
 * @param {string}                     props.value    The selection's spelling; '' for none.
 * @param {string[]|null}              props.slots    The charted bucket keys, newest first, as the reply names them.
 * @param {(spelling: string) => void} props.onChange Receives the edited selection's canonical spelling.
 * @return {import('react').ReactElement} The field.
 */
export default function BucketField( { value, slots, onChange } ) {
	const held = bucketsOf( value );
	const axis = buildChartSlots( slots );
	const twelve = twelveHourClock();
	// A spelling the server would refuse shows whole, so it can be removed.
	const stray = ! held.length && value;
	const tokens = stray ? [ [ value ] ] : runsOf( held );
	const label = ( run ) => ( stray ? value : runSpan( run ) );

	/**
	 * Take one token's buckets out of the selection.
	 *
	 * @param {string[]} run The token's run.
	 */
	const remove = ( run ) => {
		const gone = new Set( run );
		onChange(
			bucketSpelling( held.filter( ( key ) => ! gone.has( key ) ) )
		);
	};

	/**
	 * Add the run a typed time names; `validate` has already accepted it.
	 *
	 * @param {string} draft The trimmed typed time or range.
	 */
	const add = ( draft ) => {
		const { keys } = typedRun( draft, axis );
		onChange(
			bucketSpelling( [ ...new Set( [ ...held, ...keys ] ) ].sort() )
		);
	};

	return (
		<div className="event-logger-tag-container horizontal">
			<TokenInput
				tokens={ tokens.map( label ) }
				onRemove={ ( index ) => remove( tokens[ index ] ) }
				value=""
				validate={ ( draft ) => typedRun( draft, axis ).refusal }
				refuseWhileTyping={ false }
				onCommit={ add }
				commitOnBlur={ false }
				suggestions={ suggestions( axis, twelve ) }
				aria-label={ __( 'Time', 'newspack-event-logger-nodes' ) }
				placeholder={ sprintf(
					// translators: %s: a time.
					__( 'Time, e.g. %s', 'newspack-event-logger-nodes' ),
					TYPED_TIME
				) }
				title={
					twelve
						? sprintf(
								// translators: 1: a time, 2: a 12-hour time, 3: a range of times.
								__(
									'%1$s, %2$s or %3$s, in local time',
									'newspack-event-logger-nodes'
								),
								TYPED_TIME,
								TYPED_TWELVE,
								TYPED_RANGE
						  )
						: sprintf(
								// translators: 1: a time, 2: a range of times.
								__(
									'%1$s or %2$s, in local time',
									'newspack-event-logger-nodes'
								),
								TYPED_TIME,
								TYPED_RANGE
						  )
				}
			/>
		</div>
	);
}
