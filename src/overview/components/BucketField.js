/**
 * The Time field: the five-minute buckets a list is narrowed to. The URL table
 * and the URL modal each hold one ahead of their Errors Only toggle; each
 * edits its own selection, which a chart click edits too.
 *
 * A token is a run of adjacent buckets, named by its UTC span and removable.
 * Typing `16:55` and Enter adds the latest charted bucket opening at that UTC
 * time, and `16:55-17:30` the latest run from 16:55 through the bucket opening
 * at 17:30; the suggestions are the charted buckets, and picking one adds it
 * at once. A range whose end falls before its start crosses midnight when
 * the wrapped run is 12 hours or less, as `23:50-00:05` does; longer, it
 * reads as typed backwards, so `17:30-16:55` is refused and offered the right
 * way round. A forward range of any length, such as `04:00-17:30`, is taken.
 * A time the field cannot read, or one no charted bucket opens at, is refused
 * beneath it and stays in the box. Each token's × removes it, and Backspace
 * in an empty box removes the last; Escape drops the typed text and its
 * refusal. No clear-everything button sits beside the search box, where it
 * would read as clearing the search.
 *
 * Built on `TokenInput` rather than `FormTokenField`: that one announces a
 * refusal without showing it, matches suggestions against stored values
 * rather than what is typed, and wears chrome the shared skins do not paint.
 */

import { __, sprintf } from '@wordpress/i18n';
import TokenInput from '../../components/TokenInput';
import { bucketSpelling, bucketsOf, runsOf, runSpan } from '../chartSlots';
import '../../settings/styles/tag-input.scss';

/** A typed UTC time: an hour, a colon and two digits of minute. */
const TIME = /^(\d{1,2}):([0-5]\d)$/;

/** The longest run a range crossing midnight names, in minutes. */
const MAX_RUN_MINUTES = 12 * 60;

/** Minutes in a day, which a range crossing midnight wraps through. */
const DAY_MINUTES = 24 * 60;

/** What to type, as typed: input syntax rather than translatable prose. */
const TYPED_TIME = '16:55';
const TYPED_RANGE = '16:55-17:30';

/**
 * The UTC time a bucket key opens at.
 *
 * @param {string} key `YYYY-MM-DD-HH-MM`.
 * @return {string} `HH:MM`.
 */
const opensAt = ( key ) => key.slice( 11 ).replace( '-', ':' );

/**
 * A typed time as a bucket would open at it, or null for one it is not.
 *
 * @param {string} text One typed time.
 * @return {?string} `HH:MM`, the hour zero-padded.
 */
const readTime = ( text ) => {
	const match = TIME.exec( text );
	return match && Number( match[ 1 ] ) < 24
		? `${ match[ 1 ].padStart( 2, '0' ) }:${ match[ 2 ] }`
		: null;
};

/**
 * Minutes past midnight of a read time.
 *
 * @param {string} time `HH:MM`.
 * @return {number} Its minutes past midnight.
 */
const minutesOf = ( time ) =>
	Number( time.slice( 0, 2 ) ) * 60 + Number( time.slice( 3 ) );

/**
 * The buckets a typed time or range names: the latest charted bucket opening
 * at its end, back to the latest at its start before that.
 *
 * @param {string}        text  What was typed.
 * @param {string[]|null} slots The charted bucket keys, newest first.
 * @return {{keys: string[], refusal: ?string}} The run, oldest first, and
 * null; or no keys and why the text names none.
 */
const typedRun = ( text, slots ) => {
	const ends = text.split( /\s*[-–]\s*/ ).map( readTime );
	if ( ends.length > 2 || ends.includes( null ) ) {
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
	const span =
		( minutesOf( to ) - minutesOf( from ) + DAY_MINUTES ) % DAY_MINUTES;
	if ( minutesOf( from ) > minutesOf( to ) && span > MAX_RUN_MINUTES ) {
		return {
			keys: [],
			refusal: sprintf(
				// translators: 1: the range as typed, 2: the range reversed.
				__(
					'%1$s runs backwards; did you mean %2$s?',
					'newspack-event-logger-nodes'
				),
				`${ from }-${ to }`,
				`${ to }-${ from }`
			),
		};
	}
	const axis = [ ...( slots ?? [] ) ].reverse();
	const times = axis.map( opensAt );
	const last = times.lastIndexOf( to );
	const first = -1 === last ? -1 : times.lastIndexOf( from, last );
	const uncharted = -1 === last ? to : from;
	return -1 === first
		? {
				keys: [],
				refusal: sprintf(
					// translators: %s: a UTC time, e.g. 16:55.
					__(
						'No charted bucket opens at %s UTC.',
						'newspack-event-logger-nodes'
					),
					uncharted
				),
		  }
		: { keys: axis.slice( first, last + 1 ), refusal: null };
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
		const { keys } = typedRun( draft, slots );
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
				validate={ ( draft ) => typedRun( draft, slots ).refusal }
				refuseWhileTyping={ false }
				onCommit={ add }
				commitOnBlur={ false }
				suggestions={ ( slots ?? [] ).map( ( key ) => ( {
					value: opensAt( key ),
					label: runSpan( [ key ] ),
				} ) ) }
				aria-label={ __( 'Time (UTC)', 'newspack-event-logger-nodes' ) }
				placeholder={ sprintf(
					// translators: %s: a time.
					__( 'Time, e.g. %s', 'newspack-event-logger-nodes' ),
					TYPED_TIME
				) }
				title={ sprintf(
					// translators: 1: a time, 2: a range of times.
					__( '%1$s or %2$s, UTC', 'newspack-event-logger-nodes' ),
					TYPED_TIME,
					TYPED_RANGE
				) }
			/>
		</div>
	);
}
