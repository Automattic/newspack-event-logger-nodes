/**
 * Aggregate Time Chart Component
 *
 * D3-based chart with dropdown-controlled metric and breakdown dimensions.
 * Stacked bars for volume/cumulative metrics, line chart for avg response time.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import { STATUS_COLORS } from '../shared/utils/formatUtils';
import {
	RETENTION_SECONDS,
	MARGIN,
	PALETTE,
	buildTimeSlots,
	drawLegend,
	formatXTick,
	setupTooltip,
	useTimeChart,
} from '../shared/hooks/useTimeChart';

/**
 * Format a value in seconds to human-readable form.
 *
 * @param {number} seconds Value in seconds.
 * @return {string} Formatted string.
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
 * @param {Object}      props               Component props.
 * @param {Object}      props.data          Status code time series (aggregate_time_series).
 * @param {Object|null} props.breakdownData Dimensional time series or null.
 * @param {string}      props.metric        'volume' | 'avg' | 'cumulative'.
 * @param {string}      props.breakdown     'status' | 'method' | 'server' | etc.
 * @param {string}      props.serverFilter  Server name to filter by, or '' for all.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function AggregateTimeChart( {
	data,
	breakdownData,
	metric = 'volume',
	breakdown = 'status',
	serverFilter = '',
} ) {
	// Pre-compute chart data.
	const chartState = useMemo( () => {
		if ( ! data ) {
			return { chartData: [], keys: [], colorMap: {}, isLine: false };
		}

		const isLine = metric === 'avg' || metric === 'memory';
		const effectiveBreakdown = breakdownData;
		const slots = buildTimeSlots();

		if ( ! effectiveBreakdown ) {
			// No dimensional data — use base data for a single-series "Total" chart.
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

		// Build color map: use STATUS_COLORS for status breakdown, PALETTE otherwise.
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

			// Clear previous chart.
			d3.select( refs.containerRef.current ).selectAll( '*' ).remove();

			// Dimensions.
			const width =
				( refs.containerRef.current.clientWidth || 800 ) -
				MARGIN.left -
				MARGIN.right;
			const height = 280 - MARGIN.top - MARGIN.bottom;

			// Create SVG.
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

			// X scale.
			const x = d3
				.scaleTime()
				.domain( d3.extent( chartData, ( d ) => d.date ) )
				.range( [ 0, width ] );

			// X axis.
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
				// LINE CHART for avg response time / memory.
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

				// Draw overlaid areas.
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

				// Tooltip.
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

				// Legend.
				drawLegend(
					svg,
					keys.map( ( k ) => ( {
						color: colorMap[ k ] || PALETTE[ 0 ],
						label: k,
					} ) ),
					width + MARGIN.left + MARGIN.right
				);
			} else {
				// STACKED AREA CHART for volume/cumulative.
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

				// Draw stacked areas.
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

				// Tooltip.
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

				// Legend.
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
			<div
				ref={ tooltipRef }
				style={ {
					display: 'none',
					position: 'absolute',
					background: 'rgba(0,0,0,0.85)',
					color: '#fff',
					padding: '6px 10px',
					borderRadius: '4px',
					fontSize: '11px',
					fontFamily: 'monospace',
					pointerEvents: 'none',
					whiteSpace: 'nowrap',
					zIndex: 10,
				} }
			/>
		</div>
	);
}
