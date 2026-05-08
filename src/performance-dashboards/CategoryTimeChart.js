/**
 * Category Time Chart Component
 *
 * D3-based overlaid area chart showing profile category breakdowns over time.
 * Three modes: "time" (seconds per second), "count" (events per second), "average" (ms per event).
 */

import { useCallback, useMemo } from '@wordpress/element';
import * as d3 from 'd3';
import {
	BUCKET_SECONDS,
	MARGIN,
	PALETTE,
	buildTimeSlots,
	drawLegend,
	formatXTick,
	setupTooltip,
	useTimeChart,
} from './shared/hooks/useTimeChart';

const formatYValue = ( val, mode ) => {
	if ( val === 0 ) {
		return '0';
	}
	if ( mode === 'time' ) {
		if ( val < 0.001 ) {
			return `${ ( val * 1000000 ).toFixed( 0 ) }\u00B5s/s`;
		}
		if ( val < 1 ) {
			return `${ ( val * 1000 ).toFixed( 0 ) }ms/s`;
		}
		return `${ val.toFixed( 1 ) }s/s`;
	}
	if ( mode === 'average' ) {
		if ( val < 1 ) {
			return `${ ( val * 1000 ).toFixed( 0 ) }\u00B5s`;
		}
		if ( val >= 1000 ) {
			return `${ ( val / 1000 ).toFixed( 1 ) }s`;
		}
		return `${ val.toFixed( 0 ) }ms`;
	}
	if ( val >= 1000 ) {
		return `${ ( val / 1000 ).toFixed( 1 ) }K/s`;
	}
	return `${ val.toFixed( val >= 10 ? 0 : 1 ) }/s`;
};

export default function CategoryTimeChart( { data, mode, title } ) {
	// Pre-compute chart data.
	const chartState = useMemo( () => {
		if ( ! data ) {
			return { series: [], slots: [] };
		}

		const totals = {};
		Object.values( data ).forEach( ( bucket ) => {
			Object.entries( bucket ).forEach( ( [ cat, stats ] ) => {
				if ( 'total' === cat ) {
					return;
				}
				const val = mode === 'count' ? stats.c || 0 : stats.t || 0;
				totals[ cat ] = ( totals[ cat ] || 0 ) + val;
			} );
		} );
		const categories = Object.keys( totals ).sort(
			( a, b ) => totals[ b ] - totals[ a ]
		);

		const slots = buildTimeSlots();

		const series = categories.map( ( cat ) => ( {
			cat,
			values: slots.map( ( slot ) => {
				const bucket = data[ slot.bucketKey ];
				const stats = bucket?.[ cat ];
				if ( ! stats ) {
					return { date: slot.date, value: 0 };
				}
				let value;
				if ( mode === 'average' ) {
					value = stats.c > 0 ? stats.t / stats.c : 0;
				} else if ( mode === 'time' ) {
					value = stats.t / 1000 / BUCKET_SECONDS;
				} else {
					value = stats.c / BUCKET_SECONDS;
				}
				return { date: slot.date, value };
			} ),
		} ) );

		return { series, slots };
	}, [ data, mode ] );

	const renderFn = useCallback(
		( refs ) => {
			if (
				! refs.containerRef.current ||
				chartState.series.length === 0
			) {
				return;
			}

			const { series, slots } = chartState;

			// Clear previous chart.
			d3.select( refs.containerRef.current ).selectAll( '*' ).remove();

			// Dimensions.
			const width = refs.containerRef.current.clientWidth || 800;
			const height = 200;
			const innerW = width - MARGIN.left - MARGIN.right;
			const innerH = height - MARGIN.top - MARGIN.bottom;

			const svg = d3
				.select( refs.containerRef.current )
				.append( 'svg' )
				.attr( 'width', width )
				.attr( 'height', height );

			const g = svg
				.append( 'g' )
				.attr(
					'transform',
					`translate(${ MARGIN.left },${ MARGIN.top })`
				);

			const x = d3
				.scaleTime()
				.domain( d3.extent( slots, ( s ) => s.date ) )
				.range( [ 0, innerW ] );

			const maxVal =
				d3.max( series, ( s ) =>
					d3.max( s.values, ( v ) => v.value )
				) || 1;

			const y = d3
				.scaleLinear()
				.domain( [ 0, maxVal * 1.1 ] )
				.range( [ innerH, 0 ] );

			// X axis.
			g.append( 'g' )
				.attr( 'transform', `translate(0,${ innerH })` )
				.call( d3.axisBottom( x ).ticks( 8 ).tickFormat( formatXTick ) )
				.selectAll( 'text' )
				.attr( 'transform', 'rotate(-45)' )
				.style( 'text-anchor', 'end' );

			// Y axis.
			g.append( 'g' )
				.call(
					d3
						.axisLeft( y )
						.ticks( 5 )
						.tickFormat( ( v ) => formatYValue( v, mode ) )
				)
				.selectAll( 'text' )
				.style( 'font-size', '10px' );

			// Overlaid areas.
			const area = d3
				.area()
				.x( ( d ) => x( d.date ) )
				.y0( innerH )
				.y1( ( d ) => y( d.value ) )
				.curve( d3.curveMonotoneX );

			series.forEach( ( s, i ) => {
				const color = PALETTE[ i % PALETTE.length ];
				g.append( 'path' )
					.datum( s.values )
					.attr( 'fill', color )
					.attr( 'fill-opacity', 0.5 )
					.attr( 'stroke', color )
					.attr( 'stroke-width', 1 )
					.attr( 'd', area );
			} );

			// Interactive tooltip.
			const dates = slots.map( ( s ) => s.date );
			setupTooltip( g, {
				innerW,
				innerH,
				dates,
				x,
				formatEntry: ( idx ) =>
					series
						.map( ( s ) => ( {
							label: s.cat,
							value: formatYValue(
								s.values[ idx ]?.value || 0,
								mode
							),
							raw: s.values[ idx ]?.value || 0,
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
				series.map( ( s, i ) => ( {
					color: PALETTE[ i % PALETTE.length ],
					label: s.cat,
				} ) ),
				width
			);
		},
		[ chartState, mode ]
	);

	const { containerRef, tooltipRef } = useTimeChart( renderFn );

	if ( ! data || Object.keys( data ).length === 0 ) {
		return null;
	}

	return (
		<div style={ { position: 'relative' } }>
			<h3>{ title }</h3>
			<div
				ref={ containerRef }
				style={ { width: '100%', minHeight: '200px' } }
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
