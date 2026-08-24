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
 * Aggregate Time Chart component.
 *
 * Renders nothing until `data` holds at least one bucket, so a caller may
 * mount it before the first fetch returns. `breakdownData`, when present,
 * supplants `data` as the series source; `data` then only gates that render.
 *
 * @param {Object}      props                Component props.
 * @param {Object}      props.data           Bucket key => `{ count, sum_ms, sum_peak_mb }`, the single-series source.
 * @param {Object|null} props.breakdownData  Bucket key => dimension value => `{ c, s, m }` (count, sum ms, sum peak MB).
 * @param {string}      props.metric         'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {string}      props.breakdown      Dimension `breakdownData` was fetched for; picks the palette only.
 * @param {string}      [props.serverFilter] Server name for the heading; the caller has already filtered the data.
 * @return {import('react').ReactElement|null} Rendered chart, or null while no data has arrived.
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
		if ( ! data ) {
			return { series: [], colorMap: {}, stacked };
		}

		const slots = buildTimeSlots( RETENTION_SECONDS );

		if ( ! breakdownData ) {
			// No dimensional data — single-series "Total" chart.
			const values = slots.map( ( { date, bucketKey } ) => {
				const b = data[ bucketKey ] || {};
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
	if ( ! data || Object.keys( data ).length === 0 ) {
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
