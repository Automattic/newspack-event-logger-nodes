/**
 * The Performance dashboard's profile-category panel.
 *
 * D3 area charts of profile-category timings over the last 24 hours, from a
 * `query` answer over the timing rows grouped by `bucket` and `category`,
 * the top categories by milliseconds — site-wide (or per-server) on the
 * overview, per-URL in the URL detail view. `AggregateTimeChart` plots
 * request-level metrics on the same `AreaTimeChart` frame; this one breaks the
 * window down by profile category.
 *
 * Each row carries `CATEGORY_MEASURES`: `ms` of wall time and the `calls`
 * fired. One answer answers three questions, so the panel draws all three —
 * "time" (seconds of category time per second of clock), "count" (events per
 * second) and "average" (milliseconds per event). Where the answer ranked
 * more categories than it holds, a line says how many it draws.
 *
 * Areas overlay rather than stack, and the tooltip carries no total row,
 * because category times overlap: a callback's time counts inside its hook's,
 * so summing the bands double-counts, and an average never adds up at all.
 * Each band reads against the axis instead of against its neighbors.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';
import { compactFixed } from '@newspack-nodes/shared/utils/formatters';
import AreaTimeChart from '@newspack-nodes/shared/components/AreaTimeChart';
import {
	buildChartSlots,
	decodeRows,
	hasRows,
	topNote,
	useSelectedSlots,
	useSlotClick,
	useSlotRange,
} from './chartSlots';
import { CATEGORY_MEASURES } from './constants';

/**
 * Height of one chart frame, in pixels. Three of them stack in one panel, so
 * each is shorter than the single breakdown chart above them.
 */
const CHART_HEIGHT = 200;

/**
 * The three views the panel takes of one series, in render order. `mode` picks
 * both the sampler in `buildSeries` and the unit in `formatYValue`; `title` is
 * the heading and `yLabel` the Y-axis title, translated here because
 * `AreaTimeChart` holds no wording of its own. The axis title names the
 * quantity alone: the ticks carry the unit.
 *
 * @type {Array<{mode: string, title: string, yLabel: string}>}
 */
const CATEGORY_VIEWS = [
	{
		mode: 'time',
		title: __( 'Time by Category', 'newspack-event-logger-nodes' ),
		yLabel: __( 'Time', 'newspack-event-logger-nodes' ),
	},
	{
		mode: 'count',
		title: __( 'Events by Category', 'newspack-event-logger-nodes' ),
		yLabel: __( 'Events', 'newspack-event-logger-nodes' ),
	},
	{
		mode: 'average',
		title: __( 'Average Time per Event', 'newspack-event-logger-nodes' ),
		yLabel: __( 'Time per Event', 'newspack-event-logger-nodes' ),
	},
];

/**
 * Format a Y-axis value in the unit its mode implies.
 *
 * The unit is mode-specific: "time" is a rate (µs/s, ms/s, s/s), "average" a
 * duration (µs, ms, s), and "count" a frequency (/s, K/s). A zero prints bare,
 * because a unit on it says nothing. Serves both the axis ticks and the
 * tooltip rows.
 *
 * @param {number} val  Value in the mode's native unit — seconds per second for 'time', milliseconds for 'average', events per second for 'count'.
 * @param {string} mode One of 'time', 'average', or 'count'.
 * @return {string} Formatted label.
 */
const formatYValue = ( val, mode ) => {
	if ( val === 0 ) {
		return '0';
	}
	if ( mode === 'time' ) {
		if ( val < 0.001 ) {
			return `${ compactFixed( val * 1000000 ) }µs/s`;
		}
		if ( val < 1 ) {
			return `${ compactFixed( val * 1000 ) }ms/s`;
		}
		return `${ compactFixed( val ) }s/s`;
	}
	if ( mode === 'average' ) {
		if ( val < 1 ) {
			return `${ compactFixed( val * 1000 ) }µs`;
		}
		if ( val >= 1000 ) {
			return `${ compactFixed( val / 1000 ) }s`;
		}
		return `${ compactFixed( val ) }ms`;
	}
	if ( val >= 1000 ) {
		return `${ compactFixed( val / 1000 ) }K/s`;
	}
	return `${ compactFixed( val ) }/s`;
};

/**
 * Rank categories by their whole-window total, then sample each across it.
 *
 * The rank fixes palette index and legend order, and it runs over the mode's
 * own field — `calls` for "count", `ms` otherwise — so "average" ranks by
 * total time rather than by the mean it plots, and one slow outlier cannot
 * take the top band. That also makes the "count" ranking its own, so a
 * category may wear a different color there than in the other two views.
 *
 * Every series gets a point in every slot, zero where the bucket holds nothing
 * (null for the average, which is then a gap),
 * because `AreaTimeChart` takes its x-domain from the first series alone and
 * reads the rest by that index. A slot is five minutes of the 288 the reply
 * drew (`buildChartSlots()`), and both rates divide by the seconds it spans.
 *
 * @param {{names: string[], byBucket: Object}}                decoded The category series, through `decodeRows()`.
 * @param {string}                                             mode    One of 'time', 'count', or 'average'.
 * @param {Array<{date:Date,bucketKey:string,seconds:number}>} axis    The axis, from the reply's slots.
 * @return {Array<{label:string,values:Array<{date:Date,value:?number}>}>} Series in rank order.
 */
const buildSeries = ( { names, byBucket }, mode, axis ) => {
	const totals = Object.fromEntries( names.map( ( name ) => [ name, 0 ] ) );
	Object.values( byBucket ).forEach( ( row ) => {
		Object.entries( row ).forEach( ( [ name, { ms, calls } ] ) => {
			totals[ name ] += mode === 'count' ? calls : ms;
		} );
	} );
	const ranked = [ ...names ].sort( ( a, b ) => totals[ b ] - totals[ a ] );

	return ranked.map( ( name ) => ( {
		label: name,
		values: axis.map( ( slot ) => {
			const stats = byBucket[ slot.bucketKey ]?.[ name ];
			if ( ! stats ) {
				// A mean over no calls is unmeasured; a rate of none is 0.
				return {
					date: slot.date,
					value: mode === 'average' ? null : 0,
				};
			}
			const { ms, calls } = stats;
			let value;
			if ( mode === 'average' ) {
				value = calls > 0 ? ms / calls : null;
			} else if ( mode === 'time' ) {
				value = ms / 1000 / slot.seconds;
			} else {
				value = calls / slot.seconds;
			}
			return { date: slot.date, value };
		} ),
	} ) );
};

/**
 * Category time charts — one per view over the same category series.
 *
 * The URL modal re-renders on every scroll event, so the memos decode the
 * reply once per reply rather than once per frame, and hold `series`,
 * `yFormatFor` and `colorAt` still, since `AreaTimeChart` redraws whenever
 * one of them changes.
 *
 * @param {Object}                                                    props                 Component props.
 * @param {Object|null}                                               props.data            The `query` answer grouped by `bucket` and `category` over `CATEGORY_MEASURES`, or null before one.
 * @param {string[]|null}                                             props.slots           The bucket keys the answer read, newest first.
 * @param {string}                                                    props.storageKey      Untranslated placement name; each view takes `<storageKey>:<mode>`, see AreaTimeChart.
 * @param {(bucketKey: string, click: {additive: boolean}) => void}   [props.onSlotClick]   Receives the bucket key of a slot clicked in any of the three charts, and whether cmd or ctrl was held; without it a click does nothing.
 * @param {(bucketKeys: string[], drag: {additive: boolean}) => void} [props.onSlotRange]   Receives the bucket keys a drag spans in any of the three charts, ascending, and whether cmd or ctrl was held; without it a drag does nothing.
 * @param {string[]}                                                  props.selectedBuckets The selected bucket keys, shaded on every view.
 * @return {import('react').ReactElement|null} One chart per view and the top line, or null when data is empty.
 */
export default function CategoryTimeChart( {
	data,
	slots,
	storageKey,
	onSlotClick,
	onSlotRange,
	selectedBuckets,
} ) {
	if ( ! storageKey ) {
		throw new TypeError( 'CategoryTimeChart: storageKey is required' );
	}
	const axis = useMemo( () => buildChartSlots( slots ), [ slots ] );
	const decoded = useMemo(
		() => data && decodeRows( data, [ 'category' ], CATEGORY_MEASURES ),
		[ data ]
	);
	const series = useMemo(
		() =>
			CATEGORY_VIEWS.map( ( { mode } ) =>
				decoded ? buildSeries( decoded, mode, axis ) : []
			),
		[ decoded, axis ]
	);

	// Each mode's unit is fixed, so the peak the chart draws changes nothing.
	const yFormatsFor = useMemo(
		() =>
			CATEGORY_VIEWS.map(
				( { mode } ) =>
					() =>
					( val ) =>
						formatYValue( val, mode )
			),
		[]
	);

	// Colour by rank through the skin's own tokens.
	const colorAt = useCallback( ( _label, index ) => chartColor( index ), [] );

	const slotClick = useSlotClick( axis, onSlotClick );
	const slotRange = useSlotRange( axis, onSlotRange );
	const selectedSlots = useSelectedSlots( axis, selectedBuckets );

	// Emptiness asks about the ROWS, and below every hook: order matters.
	if ( ! hasRows( decoded ) || 0 === axis.length ) {
		return null;
	}

	const top = topNote( decoded, data.groups );
	return (
		<>
			{ CATEGORY_VIEWS.map( ( { mode, title, yLabel }, index ) => (
				<AreaTimeChart
					key={ mode }
					storageKey={ `${ storageKey }:${ mode }` }
					series={ series[ index ] }
					colorAt={ colorAt }
					yFormatFor={ yFormatsFor[ index ] }
					yLabel={ yLabel }
					title={ title }
					height={ CHART_HEIGHT }
					stackable={ false }
					onSlotClick={ slotClick }
					onSlotRange={ slotRange }
					selectedSlots={ selectedSlots }
				/>
			) ) }
			{ top && <p className="newspack-nodes-status">{ top }</p> }
		</>
	);
}
