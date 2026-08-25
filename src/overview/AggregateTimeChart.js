/**
 * Aggregate time chart — the Performance dashboard's main time series.
 *
 * Plots the whole retention window in 5-minute buckets (`buildTimeSlots`) as
 * one of two D3 shapes. `volume` and `cumulative` stack their series; `avg`
 * and `memory` overlay one translucent area per series, because averages do
 * not add up. `AreaTimeChart` owns the frame; this file owns the sampling.
 *
 * A breakdown dimension is ALWAYS selected — there is no "None" — so the
 * selected dimension's series is the only thing this chart ever draws. It
 * holds no undifferentiated totals to fall back on, because a series legended
 * "Total" under a dropdown reading "User Agent" answers a question nobody
 * asked. `OverviewSection` mounts it for the global series, `UrlDetailView`
 * for one URL's.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import { STATUS_COLORS } from '@newspack-nodes/shared/utils/formatUtils';
import { integerTicks } from '@newspack-nodes/shared/utils/axis-ticks';
import {
	PALETTE,
	buildTimeSlots,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import AreaTimeChart from './components/AreaTimeChart';
import { RETENTION_SECONDS } from './retention';

const CHART_HEIGHT = 280;

/**
 * Format a duration in seconds for the cumulative axis and its tooltip.
 * Sub-second values read as milliseconds, values past 1000s as kiloseconds.
 *
 * @param {number} seconds Duration in seconds.
 * @return {string} Formatted duration, e.g. `250ms`, `4.2s`, `1.5Ks`.
 */
const formatSeconds = ( seconds ) => {
	if ( seconds === 0 ) {
		return '0s';
	}
	if ( seconds < 1 ) {
		return `${ Math.round( seconds * 1000 ) }ms`;
	}
	if ( seconds >= 1000 ) {
		const ks = seconds / 1000;
		return ks === Math.floor( ks ) ? `${ ks }Ks` : `${ ks.toFixed( 1 ) }Ks`;
	}
	return seconds < 10
		? `${ seconds.toFixed( 1 ) }s`
		: `${ Math.round( seconds ) }s`;
};

/**
 * Requests, whole ones — and the axis ticks in the same unit.
 *
 * @param {number} count Requests in the bucket.
 * @return {string} Formatted count.
 */
const formatRequests = d3.format( 'd' );
formatRequests.tickValues = integerTicks;

/**
 * Average response time, rounded to the millisecond by `bucketValue`.
 *
 * @param {number} ms Milliseconds.
 * @return {string} Formatted duration.
 */
const formatAvgMs = ( ms ) => `${ ms }ms`;
formatAvgMs.tickValues = integerTicks;

/**
 * Average peak memory, already in megabytes.
 *
 * @param {number} mb Megabytes.
 * @return {string} Formatted size.
 */
const formatMemoryMb = ( mb ) => `${ Number( mb.toFixed( 1 ) ) }MB`;

/**
 * One Y-axis formatter per metric — the unit each one prints is the unit its
 * axis ticks in.
 */
const Y_FORMATS = {
	volume: formatRequests,
	avg: formatAvgMs,
	cumulative: formatSeconds,
	memory: formatMemoryMb,
};

/**
 * Reduce one bucket's totals to the plotted value for a metric.
 *
 * @param {string} metric    'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {number} count     Requests in the bucket.
 * @param {number} sumMs     Milliseconds of response time in the bucket.
 * @param {number} sumPeakMb Megabytes of peak memory in the bucket.
 * @return {number} Value to plot.
 */
const bucketValue = ( metric, count, sumMs, sumPeakMb ) => {
	if ( 'memory' === metric ) {
		return count > 0 ? sumPeakMb / count : 0;
	}
	if ( 'avg' === metric ) {
		return count > 0 ? Math.round( sumMs / count ) : 0;
	}
	if ( 'cumulative' === metric ) {
		return sumMs / 1000;
	}
	return count;
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
 * @param {Object|null} [breakdownData] Bucket key => dimension value => `{ c, s, m }`, or null.
 * @return {'pending'|'empty'|'series'} What the dimension has.
 */
export function breakdownState( breakdownData = null ) {
	if ( null === breakdownData || undefined === breakdownData ) {
		return 'pending';
	}
	return hasDimValues( breakdownData ) ? 'series' : 'empty';
}

/**
 * Aggregate Time Chart component.
 *
 * Renders nothing unless the selected dimension carries values, so a caller
 * may mount it before the first fetch returns — and must keep the dropdowns up
 * around it, since they are the only way to pick a dimension that does.
 *
 * @param {Object}      props                Component props.
 * @param {Object|null} props.breakdownData  Bucket key => dimension value => `{ c, s, m }` (count, sum ms, sum peak MB).
 * @param {string}      props.metric         'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {string}      props.breakdown      Dimension `breakdownData` was fetched for; picks the palette only.
 * @param {string}      [props.serverFilter] Server name for the heading; the caller has already filtered the data.
 * @return {import('react').ReactElement|null} Rendered chart, or null when the dimension has no series.
 */
export default function AggregateTimeChart( {
	breakdownData,
	metric = 'volume',
	breakdown = 'status',
	serverFilter = '',
} ) {
	const chartState = useMemo( () => {
		// Averages don't add up, so they overlay instead of stacking.
		const stacked = 'avg' !== metric && 'memory' !== metric;
		if ( 'series' !== breakdownState( breakdownData ) ) {
			return { series: [], colorMap: {}, stacked };
		}

		const slots = buildTimeSlots( RETENTION_SECONDS );

		const valueSet = new Set();
		Object.values( breakdownData ).forEach( ( bucket ) => {
			Object.keys( bucket ).forEach( ( v ) => valueSet.add( v ) );
		} );
		const dimValues = Array.from( valueSet );

		// Color map: STATUS_COLORS for status breakdown, PALETTE otherwise.
		const colorMap = {};
		dimValues.forEach( ( v, i ) => {
			colorMap[ v ] =
				'status' === breakdown && STATUS_COLORS[ v ]
					? STATUS_COLORS[ v ]
					: PALETTE[ i % PALETTE.length ];
		} );

		const series = dimValues.map( ( label ) => ( {
			label,
			values: slots.map( ( { date, bucketKey } ) => {
				const s = breakdownData[ bucketKey ]?.[ label ] || {};
				return {
					date,
					value: bucketValue( metric, s.c || 0, s.s || 0, s.m || 0 ),
				};
			} ),
		} ) );

		return { series, colorMap, stacked };
	}, [ breakdownData, metric, breakdown ] );

	const yFormat = Y_FORMATS[ metric ];

	const colorAt = useCallback(
		( label, index ) =>
			chartState.colorMap[ label ] || PALETTE[ index % PALETTE.length ],
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

	const yLabels = {
		volume: __( 'Requests', 'newspack-event-logger-nodes' ),
		avg: __( 'Avg Response Time (ms)', 'newspack-event-logger-nodes' ),
		cumulative: __( 'Cumulative Time', 'newspack-event-logger-nodes' ),
		memory: __( 'Avg Peak Memory (MB)', 'newspack-event-logger-nodes' ),
	};

	const titleSuffix = serverFilter ? ` — ${ serverFilter }` : '';
	const retentionLabel =
		RETENTION_SECONDS >= 3600
			? sprintf(
					// translators: %d: number of hours of data retention shown.
					__( '%d Hours', 'newspack-event-logger-nodes' ),
					Math.round( RETENTION_SECONDS / 3600 )
			  )
			: sprintf(
					// translators: %d: number of minutes of data retention shown.
					__( '%d Minutes', 'newspack-event-logger-nodes' ),
					Math.round( RETENTION_SECONDS / 60 )
			  );

	return (
		<AreaTimeChart
			className="event-logger-aggregate-time-chart"
			series={ chartState.series }
			stacked={ chartState.stacked }
			colorAt={ colorAt }
			yFormat={ yFormat }
			yLabel={ yLabels[ metric ] }
			height={ CHART_HEIGHT }
			totalLabel={
				chartState.stacked
					? __( 'Total', 'newspack-event-logger-nodes' )
					: ''
			}
			title={
				sprintf(
					// translators: 1: metric name (e.g. Request Volume), 2: retention window (e.g. 24 Hours).
					__( '%1$s (Last %2$s)', 'newspack-event-logger-nodes' ),
					metricLabels[ metric ] ||
						__( 'Chart', 'newspack-event-logger-nodes' ),
					retentionLabel
				) + titleSuffix
			}
		/>
	);
}
