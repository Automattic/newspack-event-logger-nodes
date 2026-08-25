/**
 * Response Time Chart
 *
 * D3 scatter plot of individual request response times, mounted by the
 * overview dashboard's URL detail view. One dot per request on a continuous
 * time axis — not the slot-bucketed series its `AreaTimeChart` siblings draw —
 * colored by HTTP status class and clickable to open that request; a monotone
 * trend line and a dashed mean line sit behind them, and a legend lists the
 * status classes actually present.
 *
 * D3 owns this SVG subtree, not React: `useTimeChart` calls the render
 * function on mount, on data change and on container resize, and it redraws
 * from an emptied container each time. Every DOM write
 * belongs inside that function — React never reconciles anything below the
 * container.
 */

import { useCallback, useEffect, useRef, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import {
	drawAxes,
	drawLegend,
	openFrame,
	useTimeChart,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import {
	getStatusCategory,
	getStatusColor,
	STATUS_COLORS,
} from '@newspack-nodes/shared/utils/formatUtils';
import { integerTicks } from '@newspack-nodes/shared/utils/axis-ticks';

/**
 * Total SVG height in pixels. Only the width responds to resize.
 */
const CHART_HEIGHT = 250;

/**
 * Durations to the millisecond, which is also what the axis ticks in — half a
 * millisecond would print the same label twice.
 *
 * @param {number} ms Duration in milliseconds.
 * @return {string} Formatted duration.
 */
const formatMs = ( ms ) => `${ Math.round( ms ) }ms`;
formatMs.tickValues = integerTicks;

/**
 * Response Time Chart component.
 *
 * Renders null while `requests` is empty; the render function draws as soon as
 * data arrives, so mounting it empty is fine.
 *
 * @param {Object}   props                Component props.
 * @param {Array}    props.requests       Request index entries: { rid, timestamp (seconds), duration_ms, status_code }.
 * @param {Function} props.onRequestClick Called with the clicked dot's rid and partition.
 * @return {import('react').ReactElement|null} The chart, or null without data.
 */
export default function ResponseTimeChart( { requests, onRequestClick } ) {
	const onRequestClickRef = useRef( onRequestClick );

	// Ref-held so a new callback never re-runs the render.
	useEffect( () => {
		onRequestClickRef.current = onRequestClick;
	}, [ onRequestClick ] );

	/**
	 * Plottable points, ascending by time.
	 *
	 * A request needs both a timestamp and a duration to place a dot; the
	 * truthiness test also drops a `duration_ms` of exactly 0. Timestamps
	 * arrive in seconds and become `Date` objects for the time scale.
	 *
	 * @type {Array<{time: Date, duration: number, rid: string, partition: number, status: number}>}
	 */
	const chartData = useMemo( () => {
		if ( ! requests || requests.length === 0 ) {
			return [];
		}
		return requests
			.filter( ( r ) => r.timestamp && r.duration_ms )
			.map( ( r ) => ( {
				time: new Date( r.timestamp * 1000 ),
				duration: r.duration_ms,
				rid: r.rid,
				partition: r.partition,
				status: r.status_code || 0,
			} ) )
			.sort( ( a, b ) => a.time.getTime() - b.time.getTime() );
	}, [ requests ] );

	const renderFn = useCallback(
		( { containerRef } ) => {
			if ( ! containerRef.current || 0 === chartData.length ) {
				return;
			}

			const { svg, g, width, innerW, innerH } = openFrame(
				containerRef.current,
				CHART_HEIGHT
			);

			const x = d3
				.scaleTime()
				.domain( d3.extent( chartData, ( d ) => d.time ) )
				.range( [ 0, innerW ] );
			const y = d3
				.scaleLinear()
				.domain( [ 0, d3.max( chartData, ( d ) => d.duration ) * 1.1 ] )
				.range( [ innerH, 0 ] );

			drawAxes( g, {
				x,
				y,
				innerH,
				tickCount: chartData.length,
				yFormat: formatMs,
				yLabel: __( 'Response Time', 'newspack-event-logger-nodes' ),
			} );

			if ( chartData.length > 1 ) {
				g.append( 'path' )
					.datum( chartData )
					.attr( 'fill', 'none' )
					.attr( 'stroke', '#4a90d9' )
					.attr( 'stroke-width', 1.5 )
					.attr( 'stroke-opacity', 0.5 )
					.attr(
						'd',
						d3
							.line()
							.x( ( d ) => x( d.time ) )
							.y( ( d ) => y( d.duration ) )
							.curve( d3.curveMonotoneX )
					);
			}

			const avgDuration = d3.mean( chartData, ( d ) => d.duration );
			g.append( 'line' )
				.attr( 'x1', 0 )
				.attr( 'x2', innerW )
				.attr( 'y1', y( avgDuration ) )
				.attr( 'y2', y( avgDuration ) )
				.attr( 'stroke', '#e57373' )
				.attr( 'stroke-width', 1 )
				.attr( 'stroke-dasharray', '5,5' );
			g.append( 'text' )
				.attr( 'x', innerW - 5 )
				.attr( 'y', y( avgDuration ) - 5 )
				.attr( 'text-anchor', 'end' )
				.style( 'font-size', '11px' )
				.style( 'fill', '#e57373' )
				.text(
					sprintf(
						// translators: %d: average response time in milliseconds.
						__( 'avg: %dms', 'newspack-event-logger-nodes' ),
						Math.round( avgDuration )
					)
				);

			g.append( 'g' )
				.selectAll( 'circle' )
				.data( chartData )
				.join( 'circle' )
				.attr( 'r', 5 )
				.attr( 'cx', ( d ) => x( d.time ) )
				.attr( 'cy', ( d ) => y( d.duration ) )
				.attr( 'fill', ( d ) => getStatusColor( d.status ) )
				.attr( 'stroke', '#fff' )
				.attr( 'stroke-width', 1 )
				.style( 'cursor', 'pointer' )
				.on( 'mouseover', function () {
					d3.select( this ).attr( 'r', 7 ).attr( 'opacity', 0.8 );
				} )
				.on( 'mouseout', function () {
					d3.select( this ).attr( 'r', 5 ).attr( 'opacity', 1 );
				} )
				.on( 'click', ( _, d ) => {
					if ( onRequestClickRef.current && d.rid ) {
						onRequestClickRef.current( d.rid, d.partition );
					}
				} )
				.append( 'title' )
				.text( ( d ) =>
					[
						d.time.toLocaleString(),
						sprintf(
							// translators: %s: HTTP status code.
							__( 'Status: %s', 'newspack-event-logger-nodes' ),
							d.status ||
								__( 'N/A', 'newspack-event-logger-nodes' )
						),
						sprintf(
							// translators: %d: duration in milliseconds.
							__(
								'Duration: %dms',
								'newspack-event-logger-nodes'
							),
							Math.round( d.duration )
						),
						__(
							'Click to view details',
							'newspack-event-logger-nodes'
						),
					].join( '\n' )
				);

			const present = new Set(
				chartData.map( ( d ) => getStatusCategory( d.status ) )
			);
			drawLegend(
				svg,
				[ '2xx', '3xx', '4xx', '5xx' ]
					.filter( ( key ) => present.has( key ) )
					.map( ( key ) => ( {
						color: STATUS_COLORS[ key ],
						label: key,
					} ) ),
				width
			);
		},
		[ chartData ]
	);

	const { containerRef } = useTimeChart( renderFn );

	if ( ! requests || requests.length === 0 ) {
		return null;
	}

	return (
		<div className="event-logger-response-chart">
			<h3>
				{ __(
					'Response Times (Recent Requests)',
					'newspack-event-logger-nodes'
				) }
			</h3>
			<div
				ref={ containerRef }
				style={ { width: '100%', minHeight: `${ CHART_HEIGHT }px` } }
			/>
		</div>
	);
}
