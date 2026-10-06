/**
 * Aggregate time chart — the Performance dashboard's main time series.
 *
 * Plots the 288 five-minute slots the reply names (`buildChartSlots`), the
 * last 24 hours. One translucent area per series, overlaid; for the counts and
 * sums the chart's corner toggle stacks them, and the total row appears with
 * the stack. A slot with nothing to average is a gap.
 * `AreaTimeChart` owns the frame; this file owns the sampling.
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
import { __, _n, sprintf } from '@wordpress/i18n';
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

/**
 * Seconds in the hour the title counts the axis in.
 *
 * @type {number}
 */
const SECONDS_PER_HOUR = 3600;
import {
	BUCKET_SECONDS,
	chartColor,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import AreaTimeChart from '@newspack-nodes/shared/components/AreaTimeChart';
import {
	buildChartSlots,
	decodeNameTable,
	DIM_FIELDS,
	hasRows,
	useSelectedSlots,
	useSlotClick,
	useSlotRange,
} from './chartSlots';

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

/** The metrics whose series add up: a stack of means is no total. */
const STACKABLE_METRICS = [ 'volume', 'cumulative' ];

/** A slot no request reached: every sum and count 0. */
const EMPTY_SLOT = { count: 0, sumMs: 0, sumPeakMb: 0, timed: 0 };

/**
 * Reduce one slot's totals to the plotted value for a metric.
 *
 * @param {string}                metric 'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {Object<string,number>} row    The slot's `DIM_SUMS`, under `DIM_FIELDS`' names.
 * @return {?number} Requests for `volume`, mean milliseconds of the timed
 * requests for `avg`, summed seconds for `cumulative`, mean megabytes for
 * `memory`, each over the slot's five minutes. A mean over no requests is
 * null, unmeasured, and the chart draws a gap; a count or a sum of none is
 * a real 0.
 */
const slotValue = ( metric, { count, sumMs, sumPeakMb, timed } ) => {
	if ( 'memory' === metric ) {
		return count > 0 ? sumPeakMb / count : null;
	}
	if ( 'avg' === metric ) {
		return timed > 0 ? Math.round( sumMs / timed ) : null;
	}
	if ( 'cumulative' === metric ) {
		return sumMs / MS_PER_SECOND;
	}
	return count;
};

/**
 * Which of the selected dimension's three states its series is in, and the
 * table decoded to decide it.
 *
 * The panel reads the state and hands the chart the table, so the two come
 * from one decode and cannot hold different opinions about what to draw. `pending` and `empty` are
 * distinct answers with distinct wordings: the server always emits the key for
 * every dimension it was ASKED for, so an absent key means the payload in
 * state predates the dropdown switch, while a present-but-valueless one means
 * the dimension really has nothing in the window. Calling the first "no data"
 * is a lie that flickers. A reply `decodeNameTable()` refuses is `empty`: it
 * arrived, and it holds nothing this panel can draw.
 *
 * @param {Object|null} [breakdownData] The name-table series, or null.
 * @return {{state: 'pending'|'empty'|'series', series: ReturnType<typeof decodeNameTable>}}
 * What the dimension has, and its decoded table: null while pending, or for a
 * reply the decoder refuses.
 */
export function breakdownState( breakdownData = null ) {
	if ( null === breakdownData || undefined === breakdownData ) {
		return { state: 'pending', series: null };
	}
	const series = decodeNameTable( breakdownData, DIM_FIELDS );
	return { state: hasRows( series ) ? 'series' : 'empty', series };
}

/**
 * Samples the selected dimension into slot series and draws them.
 *
 * Renders nothing unless the selected dimension carries values, so a caller
 * may mount it before the first fetch returns — and must keep the dropdowns up
 * around it, since they are the only way to pick a dimension that does.
 *
 * @param {Object}                                                    props                 Component props.
 * @param {Object|null}                                               props.series          The dimension's table, as `breakdownState()` decoded it.
 * @param {string[]|null}                                             props.slots           The bucket keys the reply drew, newest first.
 * @param {string}                                                    [props.metric]        'volume' | 'avg' | 'cumulative' | 'memory'; defaults to 'volume'.
 * @param {string}                                                    [props.breakdown]     Dimension `series` was fetched for, defaulting to 'status'; picks the palette only.
 * @param {string}                                                    [props.serverFilter]  Server name for the heading; the caller has already filtered the data.
 * @param {(bucketKey: string, click: {additive: boolean}) => void}   [props.onSlotClick]   Receives the bucket key of a clicked slot, and whether cmd or ctrl was held; without it a click does nothing.
 * @param {(bucketKeys: string[], drag: {additive: boolean}) => void} [props.onSlotRange]   Receives the bucket keys a drag spans, ascending, and whether cmd or ctrl was held; without it a drag does nothing.
 * @param {string[]}                                                  props.selectedBuckets The selected bucket keys, shaded on the plot.
 * @return {import('react').ReactElement|null} Rendered chart, or null when the dimension has no series.
 */
export default function AggregateTimeChart( {
	series,
	slots,
	metric = 'volume',
	breakdown = 'status',
	serverFilter = '',
	onSlotClick,
	onSlotRange,
	selectedBuckets,
} ) {
	const axis = useMemo( () => buildChartSlots( slots ), [ slots ] );
	const chartState = useMemo( () => {
		if ( ! hasRows( series ) || 0 === axis.length ) {
			return { lines: [], colorMap: {}, hours: 0 };
		}

		// Status classes keep their semantic colours; the rest colour by rank.
		const colorMap = 'status' === breakdown ? STATUS_COLORS : {};

		const lines = series.names.map( ( label, index ) => ( {
			label,
			values: axis.map( ( slot ) => {
				const row = series.byBucket[ slot.bucketKey ]?.[ index ];
				return {
					date: slot.date,
					value: slotValue( metric, row ?? EMPTY_SLOT ),
				};
			} ),
		} ) );

		const hours = Math.round(
			( axis.length * BUCKET_SECONDS ) / SECONDS_PER_HOUR
		);
		return { lines, colorMap, hours };
	}, [ series, axis, metric, breakdown ] );

	// The unit follows the DOMAIN: the chart builds it from the peak it draws.
	const yFormatFor = Y_FORMATS[ metric ];

	const colorAt = useCallback(
		( label, index ) => chartState.colorMap[ label ] || chartColor( index ),
		[ chartState ]
	);

	const slotClick = useSlotClick( axis, onSlotClick );
	const slotRange = useSlotRange( axis, onSlotRange );
	const selectedSlots = useSelectedSlots( axis, selectedBuckets );

	// Guard sits below every hook; hoisting it would break hook order.
	if ( 0 === chartState.lines.length ) {
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

	// Keyed on the metric, so a pick retires with it.
	return (
		<AreaTimeChart
			key={ metric }
			stackable={ STACKABLE_METRICS.includes( metric ) }
			className="event-logger-aggregate-time-chart"
			series={ chartState.lines }
			colorAt={ colorAt }
			yFormatFor={ yFormatFor }
			yLabel={ yLabels[ metric ] }
			height={ CHART_HEIGHT }
			onSlotClick={ slotClick }
			onSlotRange={ slotRange }
			selectedSlots={ selectedSlots }
			totalLabel={ __( 'Total', 'newspack-event-logger-nodes' ) }
			title={
				sprintf(
					// translators: 1: metric name (e.g. Request Volume), 2: hours the axis spans.
					_n(
						'%1$s (Last %2$d Hour)',
						'%1$s (Last %2$d Hours)',
						chartState.hours,
						'newspack-event-logger-nodes'
					),
					metricLabels[ metric ] ||
						__( 'Chart', 'newspack-event-logger-nodes' ),
					chartState.hours
				) + titleSuffix
			}
		/>
	);
}
