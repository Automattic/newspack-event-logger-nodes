/**
 * Aggregate time chart — the Performance dashboard's main time series.
 *
 * Plots the whole retention window in 5-minute buckets (`buildTimeSlots`) as
 * one of two D3 shapes. `volume` and `cumulative` stack their series; `avg`
 * and `memory` overlay one translucent area per series, because averages do
 * not add up. `AreaTimeChart` owns the frame; this file owns the sampling.
 *
 * The parent owns the metric and breakdown dropdowns, refetches the breakdown
 * series when either changes, and filters by server before the data arrives —
 * this component draws what it is handed and computes no totals of its own.
 * `OverviewSection` mounts it for the global series, `UrlDetailView` for one
 * URL's series.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import { STATUS_COLORS } from '@newspack-nodes/shared/utils/formatUtils';
import {
	PALETTE,
	buildTimeSlots,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import AreaTimeChart from './components/AreaTimeChart';
import { RETENTION_SECONDS } from './retention';

const CHART_HEIGHT = 280;
const formatCount = d3.format( 'd' );

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
 * `for…in` rather than `Object.keys().length`: the URL modal re-renders on
 * every scroll event, and a key array per frame is an allocation per frame.
 *
 * @param {Object|null} source Bucket-keyed series, or null.
 * @return {boolean} True when it holds at least one bucket.
 */
const hasBuckets = ( source ) => {
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
 * The bucketed source this chart will actually draw, and whether it carries
 * dimensions rather than totals.
 *
 * A dimensional series wins when it names a dimension value; one that names
 * none falls back to the totals, because the server sets
 * `breakdown_time_series` whenever the dimension is VALID rather than when it
 * has rows. That emptiness is the SAME one the chart computes, so a wrapper
 * gating on `null === source` cannot hold a different opinion than the chart
 * it wraps: a dimensional source here always yields at least one series, and
 * a totals source always yields exactly one.
 *
 * @param {Object}      props                 Both sources.
 * @param {Object|null} [props.data]          Bucket key => `{ count, sum_ms, sum_peak_mb }`.
 * @param {Object|null} [props.breakdownData] Bucket key => dimension value => `{ c, s, m }`.
 * @return {{source: Object|null, dimensional: boolean}} What to draw, and in which shape.
 */
export function chartSource( { data = null, breakdownData = null } ) {
	if ( hasDimValues( breakdownData ) ) {
		return { source: breakdownData, dimensional: true };
	}
	return { source: hasBuckets( data ) ? data : null, dimensional: false };
}

/**
 * Aggregate Time Chart component.
 *
 * Renders nothing until one of its two sources carries a bucket, so a caller
 * may mount it before the first fetch returns. `breakdownData`, when present,
 * is the series source outright: the URL modal passes no `data` at all, and
 * the Overview card passes both.
 *
 * @param {Object}      props                Component props.
 * @param {Object|null} props.data           Bucket key => `{ count, sum_ms, sum_peak_mb }`, the single-series source.
 * @param {Object|null} props.breakdownData  Bucket key => dimension value => `{ c, s, m }` (count, sum ms, sum peak MB).
 * @param {string}      props.metric         'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {string}      props.breakdown      Dimension `breakdownData` was fetched for; picks the palette only.
 * @param {string}      [props.serverFilter] Server name for the heading; the caller has already filtered the data.
 * @return {import('react').ReactElement|null} Rendered chart, or null while neither source has arrived.
 */
export default function AggregateTimeChart( {
	data,
	breakdownData,
	metric = 'volume',
	breakdown = 'status',
	serverFilter = '',
} ) {
	const chartState = useMemo( () => {
		// Averages don't add up, so they overlay instead of stacking.
		const stacked = 'avg' !== metric && 'memory' !== metric;
		const { source, dimensional } = chartSource( { data, breakdownData } );
		if ( null === source ) {
			return { series: [], colorMap: {}, stacked };
		}

		const slots = buildTimeSlots( RETENTION_SECONDS );

		if ( ! dimensional ) {
			// No dimensional data — single-series "Total" chart.
			const values = slots.map( ( { date, bucketKey } ) => {
				const b = source[ bucketKey ] || {};
				return {
					date,
					value: bucketValue(
						metric,
						b.count || 0,
						b.sum_ms || 0,
						b.sum_peak_mb || 0
					),
				};
			} );
			return {
				series: [ { label: 'Total', values } ],
				colorMap: { Total: PALETTE[ 0 ] },
				stacked,
			};
		}

		// Dimensional breakdown (all breakdowns including status).
		const valueSet = new Set();
		Object.values( source ).forEach( ( bucket ) => {
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
				const s = source[ bucketKey ]?.[ label ] || {};
				return {
					date,
					value: bucketValue( metric, s.c || 0, s.s || 0, s.m || 0 ),
				};
			} ),
		} ) );

		return { series, colorMap, stacked };
	}, [ data, breakdownData, metric, breakdown ] );

	const yFormat = useCallback(
		( value ) => {
			if ( 'memory' === metric ) {
				return `${ Number( value.toFixed( 1 ) ) }MB`;
			}
			if ( 'avg' === metric ) {
				return `${ value }ms`;
			}
			return 'cumulative' === metric
				? formatSeconds( value )
				: formatCount( value );
		},
		[ metric ]
	);

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
