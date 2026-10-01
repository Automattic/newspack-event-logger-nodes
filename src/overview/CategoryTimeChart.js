/**
 * The Performance dashboard's profile-category panel.
 *
 * D3 area charts of profile-category timings over the last 24 hours, from the
 * `category_time_series` payload the `performance` CI merges out of
 * `Stats_Store`'s slotted category hours — site-wide (or per-server) on the
 * overview, per-URL in the URL detail view. `AggregateTimeChart` plots
 * request-level metrics on the same `AreaTimeChart` frame; this one breaks the
 * window down by profile category.
 *
 * The payload is `{ names, buckets }`: each bucket holds one positional row per
 * category, `[ nameIndex, t, c, n ]` — `t` milliseconds of wall time, `c`
 * events fired, `n` requests the category appeared in. The panel reads `t` and
 * `c`, and one payload answers three questions, so it draws all three — "time"
 * (seconds of category time per second of clock), "count" (events per second)
 * and "average" (milliseconds per event).
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
	CAT_FIELDS,
	decodeNameTable,
	hasRows,
} from './chartSlots';

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
 * own field — `c` for "count", `t` otherwise — so "average" ranks by total
 * time rather than by the mean it plots, and one slow outlier cannot take the
 * top band. That also makes the "count" ranking its own, so a category may
 * wear a different color there than in the other two views.
 *
 * The `total` pseudo-category is dropped: it carries the request's own wall
 * time rather than any category's, so as a band it would swamp every other one.
 *
 * Every series gets a point in every slot, zero where the bucket holds nothing,
 * because `AreaTimeChart` takes its x-domain from the first series alone and
 * reads the rest by that index. A slot is five minutes of the 288 the reply
 * drew (`buildChartSlots()`), and both rates divide by the seconds it spans.
 *
 * @param {{names: string[], byBucket: Object}}                decoded The category series, through `decodeNameTable()`.
 * @param {string}                                             mode    One of 'time', 'count', or 'average'.
 * @param {Array<{date:Date,bucketKey:string,seconds:number}>} axis    The axis, from the reply's slots.
 * @return {Array<{label:string,values:Array<{date:Date,value:number}>}>} Series in rank order.
 */
const buildSeries = ( { names, byBucket }, mode, axis ) => {
	const totals = {};
	Object.values( byBucket ).forEach( ( row ) => {
		Object.entries( row ).forEach( ( [ index, { t, c } ] ) => {
			if ( 'total' !== names[ index ] ) {
				totals[ index ] =
					( totals[ index ] || 0 ) + ( mode === 'count' ? c : t );
			}
		} );
	} );
	const ranked = Object.keys( totals ).sort(
		( a, b ) => totals[ b ] - totals[ a ]
	);

	return ranked.map( ( index ) => ( {
		label: names[ index ],
		values: axis.map( ( slot ) => {
			const stats = byBucket[ slot.bucketKey ]?.[ index ];
			if ( ! stats ) {
				return { date: slot.date, value: 0 };
			}
			const { t, c } = stats;
			let value;
			if ( mode === 'average' ) {
				value = c > 0 ? t / c : 0;
			} else if ( mode === 'time' ) {
				value = t / 1000 / slot.seconds;
			} else {
				value = c / slot.seconds;
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
 * @param {Object}        props       Component props.
 * @param {Object|null}   props.data  Category series — `{ names, buckets: { bucket: [ [ nameIndex, t, c, n ], … ] } }`, `t` in milliseconds.
 * @param {string[]|null} props.slots The bucket keys the reply drew, newest first.
 * @return {import('react').ReactElement[]|null} One chart per view, or null when data is empty.
 */
export default function CategoryTimeChart( { data, slots } ) {
	const axis = useMemo( () => buildChartSlots( slots ), [ slots ] );
	const decoded = useMemo(
		() => decodeNameTable( data, CAT_FIELDS ),
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

	// Emptiness asks about the ROWS, and below every hook: order matters.
	if ( ! hasRows( decoded ) || 0 === axis.length ) {
		return null;
	}

	return CATEGORY_VIEWS.map( ( { mode, title, yLabel }, index ) => (
		<AreaTimeChart
			key={ mode }
			series={ series[ index ] }
			colorAt={ colorAt }
			yFormatFor={ yFormatsFor[ index ] }
			yLabel={ yLabel }
			title={ title }
			height={ CHART_HEIGHT }
			stackable={ false }
		/>
	) );
}
