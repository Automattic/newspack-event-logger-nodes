/**
 * Aggregate time chart — the Performance dashboard's main time series.
 *
 * Plots the whole retention window in 5-minute buckets (`buildTimeSlots`) as
 * one of two D3 shapes. `volume` and `cumulative` stack their series into a
 * stacked area chart; `avg` and `memory` overlay one translucent area per
 * series, because averages do not add up.
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
	RETENTION_SECONDS,
	MARGIN,
	PALETTE,
	buildTimeSlots,
	drawLegend,
	formatXTick,
	setupTooltip,
	useTimeChart,
} from '@newspack-nodes/shared/hooks/useTimeChart';

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
		if ( ! data ) {
			return { chartData: [], keys: [], colorMap: {}, isLine: false };
		}

		// isLine: averages don't add up, so they overlay instead of stacking.
		const isLine = metric === 'avg' || metric === 'memory';
		const effectiveBreakdown = breakdownData;
		const slots = buildTimeSlots();

		if ( ! effectiveBreakdown ) {
			// No dimensional data — single-series "Total" chart.
			const key = 'Total';
			const chartData = slots.map( ( { date, bucketKey } ) => {
				const b = data[ bucketKey ];
				const row = { date };
				if ( metric === 'memory' ) {
					row[ key ] =
						b && b.count > 0 ? ( b.sum_peak_mb || 0 ) / b.count : 0;
				} else if ( metric === 'avg' ) {
					row[ key ] =
						b && b.count > 0 ? Math.round( b.sum_ms / b.count ) : 0;
				} else if ( metric === 'cumulative' ) {
					row[ key ] = b ? b.sum_ms / 1000 : 0;
				} else {
					row[ key ] = b ? b.count : 0;
				}
				return row;
			} );
			return {
				chartData,
				keys: [ key ],
				colorMap: { [ key ]: PALETTE[ 0 ] },
				isLine,
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
				breakdown === 'status' && STATUS_COLORS[ v ]
					? STATUS_COLORS[ v ]
					: PALETTE[ i % PALETTE.length ];
		} );

		const chartData = slots.map( ( { date, bucketKey } ) => {
			const bucket = breakdownData[ bucketKey ] || {};
			const row = { date };

			if ( isLine ) {
				dimValues.forEach( ( v ) => {
					const s = bucket[ v ];
					if ( metric === 'memory' ) {
						row[ v ] = s && s.c > 0 ? ( s.m || 0 ) / s.c : 0;
					} else {
						row[ v ] = s && s.c > 0 ? Math.round( s.s / s.c ) : 0;
					}
				} );
			} else {
				dimValues.forEach( ( v ) => {
					const s = bucket[ v ];
					if ( metric === 'volume' ) {
						row[ v ] = s ? s.c : 0;
					} else {
						row[ v ] = s ? s.s / 1000 : 0;
					}
				} );
			}
			return row;
		} );

		return { chartData, keys: dimValues, colorMap, isLine };
	}, [ data, breakdownData, metric, breakdown ] );

	const renderFn = useCallback(
		( refs ) => {
			if (
				! refs.containerRef.current ||
				chartState.chartData.length === 0
			) {
				return;
			}

			const { chartData, keys, colorMap, isLine } = chartState;

			// Every render redraws from scratch; d3 holds no update join.
			d3.select( refs.containerRef.current ).selectAll( '*' ).remove();

			const width =
				( refs.containerRef.current.clientWidth || 800 ) -
				MARGIN.left -
				MARGIN.right;
			const height = 280 - MARGIN.top - MARGIN.bottom;

			const svg = d3
				.select( refs.containerRef.current )
				.append( 'svg' )
				.attr( 'width', width + MARGIN.left + MARGIN.right )
				.attr( 'height', height + MARGIN.top + MARGIN.bottom )
				.append( 'g' )
				.attr(
					'transform',
					`translate(${ MARGIN.left },${ MARGIN.top })`
				);

			const x = d3
				.scaleTime()
				.domain( d3.extent( chartData, ( d ) => d.date ) )
				.range( [ 0, width ] );

			svg.append( 'g' )
				.attr( 'transform', `translate(0,${ height })` )
				.call(
					d3
						.axisBottom( x )
						.ticks( Math.min( chartData.length, 8 ) )
						.tickFormat( formatXTick )
				)
				.selectAll( 'text' )
				.attr( 'transform', 'rotate(-45)' )
				.style( 'text-anchor', 'end' );

			if ( isLine ) {
				// Overlaid areas for avg response time / avg peak memory.
				let yMax = 0;
				chartData.forEach( ( d ) => {
					keys.forEach( ( k ) => {
						if ( ( d[ k ] || 0 ) > yMax ) {
							yMax = d[ k ];
						}
					} );
				} );
				yMax = ( yMax || 100 ) * 1.2;
				const y = d3
					.scaleLinear()
					.domain( [ 0, yMax ] )
					.range( [ height, 0 ] );

				svg.append( 'g' ).call(
					d3
						.axisLeft( y )
						.ticks( 5 )
						.tickFormat( ( d ) =>
							metric === 'memory'
								? `${ Number( d.toFixed( 1 ) ) }MB`
								: `${ d }ms`
						)
				);

				svg.append( 'text' )
					.attr( 'class', 'y-label' )
					.attr( 'transform', 'rotate(-90)' )
					.attr( 'y', 0 - MARGIN.left )
					.attr( 'x', 0 - height / 2 )
					.attr( 'dy', '1em' )
					.style( 'text-anchor', 'middle' )
					.style( 'font-size', '12px' )
					.text(
						metric === 'memory'
							? __(
									'Avg Peak Memory (MB)',
									'newspack-event-logger-nodes'
							  )
							: __(
									'Avg Response Time (ms)',
									'newspack-event-logger-nodes'
							  )
					);

				const area = d3
					.area()
					.x( ( d ) => x( d.date ) )
					.y0( height )
					.y1( ( d ) => y( d.value ) )
					.curve( d3.curveMonotoneX );

				keys.forEach( ( key ) => {
					const areaColor = colorMap[ key ] || PALETTE[ 0 ];
					const areaData = chartData.map( ( d ) => ( {
						date: d.date,
						value: d[ key ] || 0,
					} ) );
					svg.append( 'path' )
						.datum( areaData )
						.attr( 'fill', areaColor )
						.attr( 'fill-opacity', 0.5 )
						.attr( 'stroke', areaColor )
						.attr( 'stroke-width', 1 )
						.attr( 'd', area );
				} );

				const ttFmt =
					metric === 'memory'
						? ( v ) => `${ v.toFixed( 1 ) }MB`
						: ( v ) => `${ v }ms`;

				const dates = chartData.map( ( d ) => d.date );
				setupTooltip( svg, {
					innerW: width,
					innerH: height,
					dates,
					x,
					formatEntry: ( idx ) =>
						keys
							.map( ( k ) => ( {
								label: k,
								value: ttFmt( chartData[ idx ][ k ] || 0 ),
								raw: chartData[ idx ][ k ] || 0,
							} ) )
							.filter( ( e ) => e.raw > 0 )
							.sort( ( a, b ) => b.raw - a.raw )
							.slice( 0, 10 ),
					tooltipRef: refs.tooltipRef,
					lastMouseXRef: refs.lastMouseXRef,
					containerRef: refs.containerRef,
				} );

				drawLegend(
					svg,
					keys.map( ( k ) => ( {
						color: colorMap[ k ] || PALETTE[ 0 ],
						label: k,
					} ) ),
					width + MARGIN.left + MARGIN.right
				);
			} else {
				// Stacked areas for request volume / cumulative time.
				const stack = d3.stack().keys( keys );
				const stackedData = stack( chartData );

				const yMax =
					d3.max( chartData, ( d ) =>
						keys.reduce( ( sum, k ) => sum + ( d[ k ] || 0 ), 0 )
					) * 1.1 || 10;
				const y = d3
					.scaleLinear()
					.domain( [ 0, yMax ] )
					.range( [ height, 0 ] );

				const yFormat =
					metric === 'cumulative' ? formatSeconds : d3.format( 'd' );
				svg.append( 'g' ).call(
					d3.axisLeft( y ).ticks( 5 ).tickFormat( yFormat )
				);

				svg.append( 'text' )
					.attr( 'class', 'y-label' )
					.attr( 'transform', 'rotate(-90)' )
					.attr( 'y', 0 - MARGIN.left )
					.attr( 'x', 0 - height / 2 )
					.attr( 'dy', '1em' )
					.style( 'text-anchor', 'middle' )
					.style( 'font-size', '12px' )
					.text(
						metric === 'cumulative'
							? __(
									'Cumulative Time',
									'newspack-event-logger-nodes'
							  )
							: __( 'Requests', 'newspack-event-logger-nodes' )
					);

				const stackArea = d3
					.area()
					.x( ( d ) => x( d.data.date ) )
					.y0( ( d ) => y( d[ 0 ] ) )
					.y1( ( d ) => y( d[ 1 ] ) )
					.curve( d3.curveMonotoneX );

				svg.selectAll( '.layer' )
					.data( stackedData )
					.enter()
					.append( 'path' )
					.attr( 'class', 'layer' )
					.attr( 'fill', ( d ) => colorMap[ d.key ] || '#999' )
					.attr( 'fill-opacity', 0.7 )
					.attr( 'stroke', ( d ) => colorMap[ d.key ] || '#999' )
					.attr( 'stroke-width', 0.5 )
					.attr( 'd', stackArea );

				// The stacked tooltip leads with the column total.
				const saFmt =
					metric === 'cumulative'
						? ( v ) => formatSeconds( v )
						: ( v ) => String( Math.round( v ) );

				const dates = chartData.map( ( d ) => d.date );
				setupTooltip( svg, {
					innerW: width,
					innerH: height,
					dates,
					x,
					formatEntry: ( idx ) => {
						const total = keys.reduce(
							( sum, k ) => sum + ( chartData[ idx ][ k ] || 0 ),
							0
						);
						const entries = keys
							.map( ( k ) => ( {
								label: k,
								value: saFmt( chartData[ idx ][ k ] || 0 ),
								raw: chartData[ idx ][ k ] || 0,
							} ) )
							.filter( ( e ) => e.raw > 0 )
							.sort( ( a, b ) => b.raw - a.raw )
							.slice( 0, 10 );
						return [
							{
								label: __(
									'Total',
									'newspack-event-logger-nodes'
								),
								value: saFmt( total ),
							},
							...entries,
						];
					},
					tooltipRef: refs.tooltipRef,
					lastMouseXRef: refs.lastMouseXRef,
					containerRef: refs.containerRef,
				} );

				drawLegend(
					svg,
					keys.map( ( k ) => ( {
						color: colorMap[ k ] || '#999',
						label: k,
					} ) ),
					width + MARGIN.left + MARGIN.right
				);
			}
		},
		[ chartState, metric ]
	);

	const { containerRef, tooltipRef } = useTimeChart( renderFn );

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
		<div
			className="event-logger-aggregate-time-chart"
			style={ { position: 'relative' } }
		>
			<h3>
				{ sprintf(
					// translators: 1: metric name (e.g. Request Volume), 2: retention window (e.g. 24 Hours).
					__( '%1$s (Last %2$s)', 'newspack-event-logger-nodes' ),
					metricLabels[ metric ] ||
						__( 'Chart', 'newspack-event-logger-nodes' ),
					retentionLabel
				) }
				{ titleSuffix }
			</h3>
			<div
				ref={ containerRef }
				style={ { width: '100%', minHeight: '284px' } }
			/>
			<div ref={ tooltipRef } className="event-logger-chart-tooltip" />
		</div>
	);
}
