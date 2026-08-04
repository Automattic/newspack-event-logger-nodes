/**
 * Category Time Chart Component
 *
 * D3 overlaid-area chart of profile-category timings across the retention
 * window. The series come from the `category_time_series` payload the
 * `performance` CI merges out of `Stats_Store`'s per-bucket category blobs —
 * site-wide (or per-server) on the overview, per-URL in the URL detail view.
 *
 * Each 5-minute bucket carries `{ t, c }` per category: `t` milliseconds of
 * wall time, `c` events. The `mode` prop chooses what to plot — "time"
 * (seconds of category time per second of clock), "count" (events per second),
 * or "average" (milliseconds per event). Areas overlay rather than stack, so
 * each band reads against the axis instead of against its neighbors.
 *
 * The sibling `AggregateTimeChart` plots request-level metrics; this one
 * breaks the same window down by profile category.
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
} from '@newspack-nodes/shared/hooks/useTimeChart';

/**
 * Format a Y-axis value in the unit its mode implies.
 *
 * The unit is mode-specific: "time" is a rate (µs/s, ms/s, s/s), "average" a
 * duration (µs, ms, s), and "count" a frequency (/s, K/s). Serves both the
 * axis ticks and the tooltip rows.
 *
 * @param {number} val  Value in the mode's native unit — seconds per second for 'time', milliseconds for 'average', events per second for 'count'.
 * @param {string} mode One of 'time', 'average', or 'count'.
 * @return {string} Formatted label.
 */
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

/**
 * Category time chart component.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.data  Category series keyed by bucket — `{ bucket: { category: { t, c } } }`, `t` in milliseconds.
 * @param {string} props.mode  'time' | 'count' | 'average'.
 * @param {string} props.title Heading rendered above the chart.
 * @return {import('react').ReactElement|null} Rendered chart, or null when data is empty.
 */
export default function CategoryTimeChart( { data, mode, title } ) {
	/**
	 * Rank categories, then sample each one across the retention window.
	 *
	 * Ranking sums the whole window per category: event counts in "count"
	 * mode, milliseconds otherwise — so "average" orders by total time, not by
	 * its own plotted average. The rank drives both palette assignment and
	 * legend order. The synthetic `total` category is dropped; buckets with no
	 * sample for a category contribute a zero, which keeps every series the
	 * same length as the slot list.
	 */
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

	/**
	 * Draw the whole chart from scratch into the container.
	 *
	 * `useTimeChart` calls this on mount, on every data change, and on each
	 * resize, so it tears the previous SVG down first and rebuilds. Memoizing
	 * it is mandatory — an unstable callback re-renders forever.
	 *
	 * @param {Object} refs The refs `useTimeChart` owns: containerRef, tooltipRef, lastMouseXRef.
	 */
	const renderFn = useCallback(
		( refs ) => {
			if (
				! refs.containerRef.current ||
				chartState.series.length === 0
			) {
				return;
			}

			const { series, slots } = chartState;

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

			// Areas overlay rather than stack; the palette cycles past 20.
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

			// Tooltip lists the 10 largest non-zero categories, first largest.
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

	// The empty check sits below every hook; hoisting it breaks hook order.
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
			<div ref={ tooltipRef } className="event-logger-chart-tooltip" />
		</div>
	);
}
