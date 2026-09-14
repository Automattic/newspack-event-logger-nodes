/**
 * The one area-chart frame both slot-bucketed Performance series draw on.
 *
 * Axes, areas, tooltip and legend over a series list already sampled at the
 * caller's slot resolution: `AggregateTimeChart` hands it request metrics,
 * `CategoryTimeChart` profile-category timings. `stacked` picks the mark —
 * stacked bands where the series add up, overlaid translucent areas where they
 * do not (averages). The legend beside the plot picks series through the
 * shared `useLegend`; a picked series is drawn alone, the axis
 * rescaled to it, in the colour its place in the full list gave it.
 *
 * Every label arrives already translated. This component holds no wording of
 * its own, so `__()` keeps its literal arguments at the call site. That is
 * also why the tooltip's column total rides on `totalLabel` rather than on a
 * flag: naming the row is the caller's job, so the name is the switch.
 */

import { useCallback, useMemo } from '@wordpress/element';
import * as d3 from 'd3';
import {
	drawAxes,
	openFrame,
	setupTooltip,
	useTimeChart,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import ChartLegend from '@newspack-nodes/shared/components/ChartLegend';
import { useLegend } from '@newspack-nodes/shared/hooks/useSeriesSelection';

/**
 * One slot's value on one series; a missing slot reads as 0.
 *
 * @param {{values: Array<{value?: number}>}} s   The series.
 * @param {number}                            idx The slot.
 * @return {number} The value.
 */
const valueAt = ( s, idx ) => s.values[ idx ]?.value || 0;

/**
 * The peak a series list reaches across its slots: the stack's total where
 * the series add up, else the tallest single band. Plain arithmetic rather
 * than `d3.max`, so the formatter this feeds always gets a number.
 *
 * @param {Array<{values: Array<{value?: number}>}>} list    The series, sharing one slot list.
 * @param {boolean}                                  stacked Whether the series add up.
 * @return {number} The peak; 0 for an empty list.
 */
const peakOf = ( list, stacked ) => {
	const slots = list[ 0 ]?.values.length ?? 0;
	let peak = 0;
	for ( let idx = 0; idx < slots; idx++ ) {
		let total = 0;
		for ( const s of list ) {
			const v = valueAt( s, idx );
			total += v;
			if ( ! stacked && v > peak ) {
				peak = v;
			}
		}
		if ( stacked && total > peak ) {
			peak = total;
		}
	}
	return peak;
};

/**
 * Draws one already-sampled series list as areas under a shared frame.
 *
 * The tooltip carries the ten largest non-zero series for the hovered column,
 * largest first. One series arrives per breakdown value with no ceiling here,
 * and a series contributing nothing to that column would crowd out the ones
 * that do.
 *
 * @param {Object}                                                                              props              Component props.
 * @param {Array}                                                                               props.series       `[ { label, values: [ { date, value } ] } ]`; every series shares one slot list.
 * @param {( peak: number ) => import('@newspack-nodes/shared/utils/axis-ticks').AxisFormatter} props.yFormatFor   Builds a formatter for a peak. Called for the DRAWN peak, which the axis and the series rows read, so a picked series takes its own unit; and, where `totalLabel` is set, once more for the whole list's peak, which the tooltip's total row reads.
 * @param {( label: string, index: number ) => string}                                          props.colorAt      The colour for a series at its place in the full list: area, stroke and legend swatch.
 * @param {string}                                                                              props.title        Translated heading.
 * @param {number}                                                                              props.height       Total SVG height in pixels.
 * @param {string}                                                                              [props.yLabel]     Translated Y-axis title; omitted leaves the axis unlabelled.
 * @param {boolean}                                                                             [props.stacked]    Stack the series instead of overlaying them.
 * @param {string}                                                                              [props.totalLabel] Translated label for the tooltip's leading column-total row; omitted drops the row.
 * @param {string}                                                                              [props.className]  Class for the wrapper element.
 * @return {import('react').ReactElement} Rendered chart.
 */
export default function AreaTimeChart( {
	series,
	yFormatFor,
	colorAt,
	title,
	height,
	yLabel = '',
	stacked = false,
	totalLabel = '',
	className,
} ) {
	const { legendItems, drawn, selected, onSelect } = useLegend(
		series,
		colorAt
	);
	// The total row's unit follows the whole list, whatever is drawn.
	const totalFormat = useMemo(
		() => ( totalLabel ? yFormatFor( peakOf( series, stacked ) ) : null ),
		[ totalLabel, yFormatFor, series, stacked ]
	);

	const renderFn = useCallback(
		( refs ) => {
			if ( ! refs.containerRef.current || 0 === drawn.length ) {
				return;
			}

			const { g, innerW, innerH } = openFrame(
				refs.containerRef.current,
				height
			);

			const dates = drawn[ 0 ].values.map( ( v ) => v.date );
			// Over the drawn stack, or the whole bucket for the tooltip.
			const totalAt = ( idx, over ) =>
				over.reduce( ( sum, s ) => sum + valueAt( s, idx ), 0 );

			const x = d3
				.scaleTime()
				.domain( d3.extent( dates ) )
				.range( [ 0, innerW ] );

			// The axis and its unit follow what is drawn.
			const peak = peakOf( drawn, stacked );
			const yFormat = yFormatFor( peak );
			// 10% headroom clears the peak band; an all-zero one gets [0,1].
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

			// The overlaid mark trades fill for outline, so bands stay apart.
			drawn.forEach( ( s ) => {
				const band = s.values.map( ( v, idx ) => {
					const y0 = stacked ? baseline[ idx ] : 0;
					baseline[ idx ] = y0 + ( v.value || 0 );
					return { date: v.date, y0, y1: baseline[ idx ] };
				} );
				g.append( 'path' )
					.datum( band )
					.style( 'fill', s.color )
					.style( 'stroke', s.color )
					.attr( 'fill-opacity', stacked ? 0.7 : 0.5 )
					.attr( 'stroke-width', stacked ? 0.5 : 1 )
					.attr( 'd', area );
			} );

			setupTooltip( g, {
				innerW,
				innerH,
				dates,
				x,
				formatEntry: ( idx ) => {
					const entries = drawn
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
									value: totalFormat(
										totalAt( idx, series )
									),
								},
								...entries,
						  ]
						: entries;
				},
				tooltipRef: refs.tooltipRef,
				lastMouseXRef: refs.lastMouseXRef,
				containerRef: refs.containerRef,
			} );
		},
		[
			series,
			drawn,
			yFormatFor,
			totalFormat,
			height,
			yLabel,
			stacked,
			totalLabel,
		]
	);

	const { containerRef, tooltipRef } = useTimeChart( renderFn );

	// `position: relative` makes this wrapper the tooltip's offset parent.
	return (
		<div className={ className } style={ { position: 'relative' } }>
			<h3>{ title }</h3>
			<div className="newspack-nodes-chart">
				<div
					ref={ containerRef }
					className="newspack-nodes-chart__plot"
					style={ { minHeight: `${ height }px` } }
				/>
				<ChartLegend
					items={ legendItems }
					selected={ selected }
					onSelect={ onSelect }
					height={ height }
				/>
			</div>
			<div ref={ tooltipRef } className="event-logger-chart-tooltip" />
		</div>
	);
}
