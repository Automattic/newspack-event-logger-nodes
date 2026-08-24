/**
 * The one area-chart frame both Performance time series draw on.
 *
 * Axes, areas, tooltip and legend over a series list already sampled at the
 * caller's slot resolution: `AggregateTimeChart` hands it request metrics,
 * `CategoryTimeChart` profile-category timings. `stacked` picks the mark —
 * stacked bands where the series add up, overlaid translucent areas where they
 * do not (averages).
 *
 * Every label arrives already translated. This component holds no wording of
 * its own, so `__()` keeps its literal arguments at the call site.
 */

import { useCallback } from '@wordpress/element';
import * as d3 from 'd3';
import {
	drawAxes,
	drawLegend,
	openFrame,
	setupTooltip,
	useTimeChart,
} from '@newspack-nodes/shared/hooks/useTimeChart';

/**
 * Area time chart component.
 *
 * @param {Object}   props              Component props.
 * @param {Array}    props.series       `[ { label, values: [ { date, value } ] } ]`; every series shares one slot list.
 * @param {Function} props.yFormat      Formats a value for the Y axis and the tooltip.
 * @param {Function} props.colorAt      `( label, index ) => color` for area, stroke and legend swatch.
 * @param {string}   props.title        Translated heading.
 * @param {number}   props.height       Total SVG height in pixels.
 * @param {string}   [props.yLabel]     Translated Y-axis title; omitted leaves the axis unlabelled.
 * @param {boolean}  [props.stacked]    Stack the series instead of overlaying them.
 * @param {string}   [props.totalLabel] Translated label for the tooltip's leading column-total row; omitted drops the row.
 * @param {string}   [props.className]  Class for the wrapper element.
 * @return {import('react').ReactElement} Rendered chart.
 */
export default function AreaTimeChart( {
	series,
	yFormat,
	colorAt,
	title,
	height,
	yLabel = '',
	stacked = false,
	totalLabel = '',
	className,
} ) {
	const renderFn = useCallback(
		( refs ) => {
			if ( ! refs.containerRef.current || 0 === series.length ) {
				return;
			}

			const { svg, g, width, innerW, innerH } = openFrame(
				refs.containerRef.current,
				height
			);

			const dates = series[ 0 ].values.map( ( v ) => v.date );
			const colors = series.map( ( s, i ) => colorAt( s.label, i ) );
			const valueAt = ( s, idx ) => s.values[ idx ]?.value || 0;
			const totalAt = ( idx ) =>
				series.reduce( ( sum, s ) => sum + valueAt( s, idx ), 0 );

			const x = d3
				.scaleTime()
				.domain( d3.extent( dates ) )
				.range( [ 0, innerW ] );

			const peak = stacked
				? d3.max( dates, ( _d, idx ) => totalAt( idx ) )
				: d3.max( series, ( s ) =>
						d3.max( s.values, ( v ) => v.value )
				  );
			const y = d3
				.scaleLinear()
				.domain( [ 0, peak * 1.1 || 1 ] )
				.range( [ innerH, 0 ] );

			drawAxes( g, {
				x,
				y,
				innerH,
				tickCount: dates.length,
				yFormat,
				yLabel,
			} );

			// Stacked bands ride on the running baseline; overlaid ones on 0.
			const baseline = dates.map( () => 0 );
			const area = d3
				.area()
				.x( ( d ) => x( d.date ) )
				.y0( ( d ) => y( d.y0 ) )
				.y1( ( d ) => y( d.y1 ) )
				.curve( d3.curveMonotoneX );

			series.forEach( ( s, i ) => {
				const band = s.values.map( ( v, idx ) => {
					const y0 = stacked ? baseline[ idx ] : 0;
					baseline[ idx ] = y0 + ( v.value || 0 );
					return { date: v.date, y0, y1: baseline[ idx ] };
				} );
				g.append( 'path' )
					.datum( band )
					.attr( 'fill', colors[ i ] )
					.attr( 'fill-opacity', stacked ? 0.7 : 0.5 )
					.attr( 'stroke', colors[ i ] )
					.attr( 'stroke-width', stacked ? 0.5 : 1 )
					.attr( 'd', area );
			} );

			// The tooltip lists the 10 largest non-zero series, largest first.
			setupTooltip( g, {
				innerW,
				innerH,
				dates,
				x,
				formatEntry: ( idx ) => {
					const entries = series
						.map( ( s ) => ( {
							label: s.label,
							value: yFormat( valueAt( s, idx ) ),
							raw: valueAt( s, idx ),
						} ) )
						.filter( ( e ) => e.raw > 0 )
						.sort( ( a, b ) => b.raw - a.raw )
						.slice( 0, 10 );
					return totalLabel
						? [
								{
									label: totalLabel,
									value: yFormat( totalAt( idx ) ),
								},
								...entries,
						  ]
						: entries;
				},
				tooltipRef: refs.tooltipRef,
				lastMouseXRef: refs.lastMouseXRef,
				containerRef: refs.containerRef,
			} );

			// The legend hangs off the OUTER svg, inside the right margin.
			drawLegend(
				svg,
				colors.map( ( color, i ) => ( {
					color,
					label: series[ i ].label,
				} ) ),
				width
			);
		},
		[ series, yFormat, colorAt, height, yLabel, stacked, totalLabel ]
	);

	const { containerRef, tooltipRef } = useTimeChart( renderFn );

	return (
		<div className={ className } style={ { position: 'relative' } }>
			<h3>{ title }</h3>
			<div
				ref={ containerRef }
				style={ { width: '100%', minHeight: `${ height }px` } }
			/>
			<div ref={ tooltipRef } className="event-logger-chart-tooltip" />
		</div>
	);
}
