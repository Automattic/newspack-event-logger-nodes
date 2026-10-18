/**
 * Aggregate time chart — the Performance dashboard's main time series.
 *
 * Plots the 288 five-minute slots its `query` answer read (`answerSlots`),
 * the last 24 hours. One translucent area per series — one tuple of the
 * selected dimensions, the top ones by request count — overlaid; for the
 * counts and sums the chart's corner toggle stacks them, and the total row
 * appears with the stack. A slot with nothing to average is a gap.
 * `AreaTimeChart` owns the frame; this file owns the sampling.
 *
 * A first breakdown dimension is ALWAYS selected — it has no "None" — so the
 * selected dimensions' series are the only thing this chart ever draws. It
 * holds no undifferentiated totals to fall back on, because a series legended
 * "Total" under a dropdown reading "User Agent" answers a question nobody
 * asked. `BreakdownControls` mounts it under the Metric and Breakdown selects
 * that set its props — for the site's series in the Overview card, and for
 * one URL's in the detail modal.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import {
	phosphorColor,
	STATUS_COLORS,
} from '@newspack-nodes/shared/utils/formatUtils';
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
	decodeRows,
	hasRows,
	useSelectedSlots,
	useSlotClick,
	useSlotRange,
} from './chartSlots';
import { SERIES_MEASURES } from './constants';

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
const EMPTY_SLOT = { count: 0, sum_ms: 0, sum_peak_mb: 0, timed: 0 };

/**
 * Reduce one slot's totals to the plotted value for a metric.
 *
 * @param {string}                metric 'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {Object<string,number>} row    The slot's `SERIES_MEASURES`, by name.
 * @return {?number} Requests for `volume`, mean milliseconds of the timed
 * requests for `avg`, summed seconds for `cumulative`, mean megabytes for
 * `memory`, each over the slot's five minutes. A mean over no requests is
 * null, unmeasured, and the chart draws a gap; a count or a sum of none is
 * a real 0.
 */
const slotValue = ( metric, { count, sum_ms: ms, sum_peak_mb: mb, timed } ) => {
	if ( 'memory' === metric ) {
		return count > 0 ? mb / count : null;
	}
	if ( 'avg' === metric ) {
		return timed > 0 ? Math.round( ms / timed ) : null;
	}
	if ( 'cumulative' === metric ) {
		return ms / MS_PER_SECOND;
	}
	return count;
};

/**
 * Which of the selected dimensions' three states their answer is in, the
 * series decoded to decide it, and how many tuples the answer ranked.
 *
 * The panel reads the state and hands the chart the series, so the two come
 * from one decode and cannot hold different opinions about what to draw.
 * `pending` and `empty` are distinct answers with distinct wordings: an
 * answer whose columns are not the ones these dimensions ask for predates
 * the dropdown switch, while one carrying no rows means the dimensions
 * really have nothing in the window. Calling the first "no data" is a lie
 * that flickers. An answer `decodeRows()` refuses is `empty`: it arrived,
 * and it holds nothing this panel can draw; so is one whose stats rows did
 * not open, which answers no rows.
 *
 * @param {Object|null} answer The `query` answer, or null before one.
 * @param {string[]}    dims   The dimensions the chart breaks down by.
 * @return {{state: 'pending'|'empty'|'series', series: ReturnType<typeof decodeRows>, groups: ?number}}
 * What the dimensions have, the decoded series (null while pending or
 * refused) and the tuples the answer's selection holds.
 */
export function breakdownState( answer, dims ) {
	const asked = [ 'bucket', ...dims, ...SERIES_MEASURES ];
	if (
		! answer ||
		asked.join( '\n' ) !== ( answer.columns ?? [] ).join( '\n' )
	) {
		return { state: 'pending', series: null, groups: null };
	}
	const series = decodeRows( answer, dims, SERIES_MEASURES );
	return {
		state: hasRows( series ) ? 'series' : 'empty',
		series,
		groups: answer.groups,
	};
}

/**
 * Samples the selected dimension into slot series and draws them.
 *
 * Renders nothing unless the selected dimension carries values, so a caller
 * may mount it before the first fetch returns — and must keep the dropdowns up
 * around it, since they are the only way to pick a dimension that does.
 *
 * @param {Object}                                                    props                 Component props.
 * @param {Object|null}                                               props.series          The dimensions' series, as `breakdownState()` decoded it.
 * @param {string[]|null}                                             props.slots           The bucket keys the reply drew, newest first.
 * @param {string}                                                    props.storageKey      Untranslated placement name, one for every metric, so the expanded state and the stack hold across a metric change; see AreaTimeChart.
 * @param {string}                                                    [props.metric]        'volume' | 'avg' | 'cumulative' | 'memory'; defaults to 'volume'.
 * @param {string[]}                                                  props.dims            The dimensions `series` was asked by; a status class alone takes the status palette.
 * @param {boolean}                                                   [props.partial]       Whether the answer ranked more series than it holds, so a stacked total is of the shown ones alone.
 * @param {string}                                                    [props.serverFilter]  Server name for the heading; the caller has already filtered the data.
 * @param {(bucketKey: string, click: {additive: boolean}) => void}   [props.onSlotClick]   Receives the bucket key of a clicked slot, and whether cmd or ctrl was held; without it a click does nothing.
 * @param {(bucketKeys: string[], drag: {additive: boolean}) => void} [props.onSlotRange]   Receives the bucket keys a drag spans, ascending, and whether cmd or ctrl was held; without it a drag does nothing.
 * @param {string[]}                                                  props.selectedBuckets The selected bucket keys, shaded on the plot.
 * @return {import('react').ReactElement|null} Rendered chart, or null when the dimension has no series.
 */
export default function AggregateTimeChart( {
	series,
	slots,
	storageKey,
	metric = 'volume',
	dims,
	partial = false,
	serverFilter = '',
	onSlotClick,
	onSlotRange,
	selectedBuckets,
} ) {
	if ( ! storageKey ) {
		throw new TypeError( 'AggregateTimeChart: storageKey is required' );
	}
	const axis = useMemo( () => buildChartSlots( slots ), [ slots ] );
	const chartState = useMemo( () => {
		if ( ! hasRows( series ) || 0 === axis.length ) {
			return { lines: [], colorMap: {}, hours: 0 };
		}

		// Status classes take semantic colours, the rest the skin's by rank.
		const byClass = 1 === dims.length && 'status_class' === dims[ 0 ];
		const colorMap = byClass ? STATUS_COLORS : {};

		const lines = series.names.map( ( label ) => ( {
			label,
			values: axis.map( ( slot ) => {
				const row = series.byBucket[ slot.bucketKey ]?.[ label ];
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
	}, [ series, axis, metric, dims ] );

	// The unit follows the DOMAIN: the chart builds it from the peak it draws.
	const yFormatFor = Y_FORMATS[ metric ];

	// CRT paints each series its green by rank over that colour.
	const colorAt = useCallback(
		( label, index ) =>
			phosphorColor(
				index,
				chartState.colorMap[ label ] || chartColor( index )
			),
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

	return (
		<AreaTimeChart
			storageKey={ storageKey }
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
			totalLabel={
				partial
					? __( 'Total of shown', 'newspack-event-logger-nodes' )
					: __( 'Total', 'newspack-event-logger-nodes' )
			}
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
