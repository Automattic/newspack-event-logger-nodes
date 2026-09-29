/**
 * Aggregate time chart — the Performance dashboard's main time series.
 *
 * Plots the window the reply read (`buildChartSlots`): an hour a point for
 * each whole hour before the current one and five minutes a point inside it,
 * each sum read per five minutes so the two resolutions share one scale. One
 * translucent area per series, overlaid; the chart's own corner toggle
 * stacks them, and the total row appears with the stack. `AreaTimeChart` owns
 * the frame; this file owns the sampling.
 *
 * A breakdown dimension is ALWAYS selected — there is no "None" — so the
 * selected dimension's series is the only thing this chart ever draws. It
 * holds no undifferentiated totals to fall back on, because a series legended
 * "Total" under a dropdown reading "User Agent" answers a question nobody
 * asked. `BreakdownControls` mounts it under the Metric and Breakdown selects
 * that set both props — for the global series in the Overview card, and for
 * one URL's in the detail modal.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import { STATUS_COLORS } from '@newspack-nodes/shared/utils/formatUtils';
import {
	axisDuration,
	integerTicks,
} from '@newspack-nodes/shared/utils/axis-ticks';

/**
 * Milliseconds per second, converting the seconds the `cumulative` metric
 * plots back into the milliseconds `axisDuration` picks a unit from.
 *
 * @type {number}
 */
const MS_PER_SECOND = 1000;
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';
import AreaTimeChart from '@newspack-nodes/shared/components/AreaTimeChart';
import { buildChartSlots, perBucket } from './chartSlots';

/**
 * Total SVG height in pixels, margins included, handed to `AreaTimeChart`.
 *
 * This panel draws ONE chart where `CategoryTimeChart` stacks three, so it
 * can afford the taller frame.
 *
 * @type {number}
 */
const CHART_HEIGHT = 280;

/**
 * Requests, whole ones, and the integer tick ladder to match.
 *
 * `drawAxes` ticks the axis with whatever `tickValues` the formatter carries,
 * so the ladder rides on the formatter. Without it a short axis takes d3's
 * fractional ticks and prints the same rounded label twice.
 *
 * @param {number} count Requests in the bucket.
 * @return {string} Formatted count.
 */
const formatRequests = d3.format( 'd' );
formatRequests.tickValues = integerTicks;

/**
 * Average peak memory, already in megabytes.
 *
 * @param {number} mb Megabytes.
 * @return {string} Formatted size.
 */
const formatMemoryMb = ( mb ) => `${ Number( mb.toFixed( 1 ) ) }MB`;

/**
 * One Y-axis formatter per metric, BUILT from the axis domain — the unit each
 * prints is the unit its axis ticks in, and for a duration that unit follows
 * the data: pinned to milliseconds, a slow site's ticks run to five digits and
 * collide with the axis title beside them.
 *
 * Each entry takes the largest value its axis has to show and returns the
 * formatter for it. `volume` and `memory` ignore that domain, because a count
 * and a megabyte have one unit each.
 *
 * @type {Object<string,(max: number) => import('@newspack-nodes/shared/utils/axis-ticks').AxisFormatter>}
 */
const Y_FORMATS = {
	volume: () => formatRequests,
	avg: ( maxMs ) => axisDuration( maxMs ),
	// Cumulative plots SECONDS; the shared formatter counts milliseconds.
	cumulative: ( maxSeconds ) => {
		const format = axisDuration( maxSeconds * MS_PER_SECOND );
		const inSeconds = ( seconds ) => format( seconds * MS_PER_SECOND );
		inSeconds.tickValues = format.tickValues;
		return inSeconds;
	},
	memory: () => formatMemoryMb,
};

/**
 * Reduce one slot's totals to the plotted value for a metric.
 *
 * @param {string}            metric    'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {number}            count     Requests in the slot.
 * @param {number}            sumMs     Milliseconds of response time in the slot.
 * @param {number}            sumPeakMb Megabytes of peak memory in the slot.
 * @param {{seconds: number}} slot      The slot, an hour or five minutes.
 * @return {number} Requests per five minutes for `volume`, mean milliseconds
 * for `avg`, summed seconds per five minutes for `cumulative`, mean megabytes
 * for `memory`: a sum reads per bucket, so an hour sits on the buckets'
 * scale. An empty slot averages to 0 rather than dividing by zero.
 */
const slotValue = ( metric, count, sumMs, sumPeakMb, slot ) => {
	if ( 'memory' === metric ) {
		return count > 0 ? sumPeakMb / count : 0;
	}
	if ( 'avg' === metric ) {
		return count > 0 ? Math.round( sumMs / count ) : 0;
	}
	if ( 'cumulative' === metric ) {
		return perBucket( sumMs / 1000, slot );
	}
	return perBucket( count, slot );
};

/**
 * True when a bucketed source carries anything to draw.
 *
 * `CategoryTimeChart` gates on this too, so the two charts agree on what an
 * empty source is.
 *
 * `for…in` rather than `Object.keys().length`: the URL modal re-renders on
 * every scroll event, and a key array per frame is an allocation per frame.
 *
 * @param {Object|null} source Bucket-keyed series, or null.
 * @return {boolean} True when it holds at least one bucket.
 */
export const hasBuckets = ( source ) => {
	for ( const key in source ) {
		if ( Object.hasOwn( source, key ) ) {
			return true;
		}
	}
	return false;
};

/**
 * True when a dimensional source carries a value to draw a series for.
 *
 * Buckets alone are not content: a dimension key that merges to an empty map
 * leaves `{ '<bucket>': {} }`, which has a bucket and no series.
 *
 * @param {Object|null} source Bucket key => dimension value => totals, or null.
 * @return {boolean} True when at least one bucket names a dimension value.
 */
const hasDimValues = ( source ) => {
	for ( const key in source ) {
		if ( Object.hasOwn( source, key ) && hasBuckets( source[ key ] ) ) {
			return true;
		}
	}
	return false;
};

/**
 * Which of the selected dimension's three states its series is in.
 *
 * The chart and the panel that wraps it both read this, so neither can hold a
 * different opinion about what there is to draw. `pending` and `empty` are
 * distinct answers with distinct wordings: the server always emits the key for
 * every dimension it was ASKED for, so an absent key means the payload in
 * state predates the dropdown switch, while a present-but-valueless one means
 * the dimension really has nothing in the window. Calling the first "no data"
 * is a lie that flickers.
 *
 * @param {Object|null} [breakdownData] Bucket key => dimension value => `[ count, sumMs, sumPeakMb ]`, or null.
 * @return {'pending'|'empty'|'series'} What the dimension has.
 */
export function breakdownState( breakdownData = null ) {
	if ( null === breakdownData || undefined === breakdownData ) {
		return 'pending';
	}
	return hasDimValues( breakdownData ) ? 'series' : 'empty';
}

/**
 * Samples the selected dimension into slot series and draws them.
 *
 * Renders nothing unless the selected dimension carries values, so a caller
 * may mount it before the first fetch returns — and must keep the dropdowns up
 * around it, since they are the only way to pick a dimension that does.
 *
 * @param {Object}                                 props                Component props.
 * @param {Object|null}                            props.breakdownData  Bucket key => dimension value => `[ count, sumMs, sumPeakMb ]`.
 * @param {{fine: string[], hours: string[]}|null} props.plan           The read plan the reply named; the axis splits where it did.
 * @param {string}                                 [props.metric]       'volume' | 'avg' | 'cumulative' | 'memory'; defaults to 'volume'.
 * @param {string}                                 [props.breakdown]    Dimension `breakdownData` was fetched for, defaulting to 'status'; picks the palette only.
 * @param {string}                                 [props.serverFilter] Server name for the heading; the caller has already filtered the data.
 * @return {import('react').ReactElement|null} Rendered chart, or null when the dimension has no series.
 */
export default function AggregateTimeChart( {
	breakdownData,
	plan,
	metric = 'volume',
	breakdown = 'status',
	serverFilter = '',
} ) {
	const chartState = useMemo( () => {
		const slots = buildChartSlots( plan );
		if (
			'series' !== breakdownState( breakdownData ) ||
			0 === slots.length
		) {
			return { series: [], colorMap: {} };
		}

		const valueSet = new Set();
		Object.values( breakdownData ).forEach( ( bucket ) => {
			Object.keys( bucket ).forEach( ( v ) => valueSet.add( v ) );
		} );
		const dimValues = Array.from( valueSet );

		// Status classes keep their semantic colours; the rest colour by rank.
		const colorMap = 'status' === breakdown ? STATUS_COLORS : {};

		const series = dimValues.map( ( label ) => ( {
			label,
			values: slots.map( ( slot ) => {
				// DIM_SUMS, positional from the store to here: decision 18.
				const s = breakdownData[ slot.bucketKey ]?.[ label ] || [];
				return {
					date: slot.date,
					value: slotValue(
						metric,
						s[ 0 ] || 0,
						s[ 1 ] || 0,
						s[ 2 ] || 0,
						slot
					),
				};
			} ),
		} ) );

		return { series, colorMap };
	}, [ breakdownData, plan, metric, breakdown ] );

	// The unit follows the DOMAIN: the chart builds it from the peak it draws.
	const yFormatFor = Y_FORMATS[ metric ];

	const colorAt = useCallback(
		( label, index ) => chartState.colorMap[ label ] || chartColor( index ),
		[ chartState ]
	);

	// Guard sits below every hook; hoisting it would break hook order.
	if ( 0 === chartState.series.length ) {
		return null;
	}

	const metricLabels = {
		volume: __( 'Request Volume', 'newspack-event-logger-nodes' ),
		avg: __( 'Avg Response Time', 'newspack-event-logger-nodes' ),
		cumulative: __(
			'Cumulative Response Time',
			'newspack-event-logger-nodes'
		),
		memory: __( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
	};

	// A title names a summed point's span; the ticks carry the unit.
	const yLabels = {
		...metricLabels,
		volume: __( 'Requests per 5 min', 'newspack-event-logger-nodes' ),
		cumulative: __( 'Time per 5 min', 'newspack-event-logger-nodes' ),
	};

	const titleSuffix = serverFilter ? ` — ${ serverFilter }` : '';

	// Keyed on the metric: a stack of means is no total, so a pick retires.
	return (
		<AreaTimeChart
			key={ metric }
			className="event-logger-aggregate-time-chart"
			series={ chartState.series }
			colorAt={ colorAt }
			yFormatFor={ yFormatFor }
			yLabel={ yLabels[ metric ] }
			height={ CHART_HEIGHT }
			totalLabel={ __( 'Total', 'newspack-event-logger-nodes' ) }
			title={
				sprintf(
					// translators: 1: metric name (e.g. Request Volume), 2: whole hours read before the current one.
					__(
						'%1$s (This Hour and the %2$d Before It)',
						'newspack-event-logger-nodes'
					),
					metricLabels[ metric ] ||
						__( 'Chart', 'newspack-event-logger-nodes' ),
					plan?.hours?.length ?? 0
				) + titleSuffix
			}
		/>
	);
}
