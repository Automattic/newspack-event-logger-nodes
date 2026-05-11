/**
 * Response Time Chart Component
 *
 * D3-based scatter plot showing individual request response times.
 * Uses D3 enter/update/exit pattern for efficient updates.
 */

import { useEffect, useRef, useMemo } from '@wordpress/element';
import * as d3 from 'd3';
import { getStatusColor, STATUS_COLORS } from '../shared/utils/formatUtils';

/**
 * Chart dimensions and margins.
 */
const MARGIN = { top: 20, right: 160, bottom: 50, left: 60 };
const HEIGHT = 250 - MARGIN.top - MARGIN.bottom;

/**
 * Response Time Chart component.
 *
 * @param {Object}   props                Component props.
 * @param {Array}    props.requests       Individual request data [{ rid, timestamp, duration_ms }].
 * @param {Function} props.onRequestClick Callback when a request dot is clicked.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function ResponseTimeChart( { requests, onRequestClick } ) {
	const containerRef = useRef( null );
	const svgRef = useRef( null );
	const scalesRef = useRef( null );
	const onRequestClickRef = useRef( onRequestClick );

	// Keep callback ref updated without causing re-renders.
	useEffect( () => {
		onRequestClickRef.current = onRequestClick;
	}, [ onRequestClick ] );

	// Process data into chart format.
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
				status: r.status_code || 0,
			} ) )
			.sort( ( a, b ) => a.time - b.time );
	}, [ requests ] );

	// Initialize SVG structure once.
	useEffect( () => {
		if ( ! containerRef.current || svgRef.current ) {
			return;
		}

		const width =
			( containerRef.current.clientWidth || 800 ) -
			MARGIN.left -
			MARGIN.right;

		const svg = d3
			.select( containerRef.current )
			.append( 'svg' )
			.attr( 'width', width + MARGIN.left + MARGIN.right )
			.attr( 'height', HEIGHT + MARGIN.top + MARGIN.bottom );

		const g = svg
			.append( 'g' )
			.attr( 'transform', `translate(${ MARGIN.left },${ MARGIN.top })` );

		// Create groups for different elements (order matters for z-index).
		g.append( 'g' )
			.attr( 'class', 'x-axis' )
			.attr( 'transform', `translate(0,${ HEIGHT })` );
		g.append( 'g' ).attr( 'class', 'y-axis' );
		g.append( 'text' )
			.attr( 'class', 'y-label' )
			.attr( 'transform', 'rotate(-90)' )
			.attr( 'y', 0 - MARGIN.left )
			.attr( 'x', 0 - HEIGHT / 2 )
			.attr( 'dy', '1em' )
			.style( 'text-anchor', 'middle' )
			.style( 'font-size', '12px' )
			.text( 'Response Time' );
		g.append( 'path' ).attr( 'class', 'trend-line' );
		g.append( 'line' ).attr( 'class', 'avg-line' );
		g.append( 'text' ).attr( 'class', 'avg-label' );
		g.append( 'g' ).attr( 'class', 'dots' );

		// Store refs.
		svgRef.current = { svg, g, width };
		scalesRef.current = {
			x: d3.scaleTime().range( [ 0, width ] ),
			y: d3.scaleLinear().range( [ HEIGHT, 0 ] ),
		};
	}, [] );

	// Update chart when data changes.
	useEffect( () => {
		if (
			! svgRef.current ||
			! scalesRef.current ||
			chartData.length === 0
		) {
			return;
		}

		const { g, width } = svgRef.current;
		const { x, y } = scalesRef.current;

		// Update scales.
		const xExtent = d3.extent( chartData, ( d ) => d.time );
		const yMax = d3.max( chartData, ( d ) => d.duration ) * 1.1;
		x.domain( xExtent );
		y.domain( [ 0, yMax ] );

		// Update X axis.
		g.select( '.x-axis' ).call(
			d3
				.axisBottom( x )
				.ticks( Math.min( chartData.length, 8 ) )
				.tickFormat( d3.timeFormat( '%H:%M' ) )
		);
		g.select( '.x-axis' )
			.selectAll( 'text' )
			.attr( 'transform', 'rotate(-45)' )
			.style( 'text-anchor', 'end' );

		// Update Y axis.
		g.select( '.y-axis' ).call(
			d3
				.axisLeft( y )
				.ticks( 5 )
				.tickFormat( ( d ) => Math.round( d ) + 'ms' )
		);

		// Update trend line.
		if ( chartData.length > 1 ) {
			const line = d3
				.line()
				.x( ( d ) => x( d.time ) )
				.y( ( d ) => y( d.duration ) )
				.curve( d3.curveMonotoneX );

			g.select( '.trend-line' )
				.datum( chartData )
				.attr( 'fill', 'none' )
				.attr( 'stroke', '#4a90d9' )
				.attr( 'stroke-width', 1.5 )
				.attr( 'stroke-opacity', 0.5 )
				.attr( 'd', line );
		}

		// Update average line.
		const avgDuration = d3.mean( chartData, ( d ) => d.duration );
		g.select( '.avg-line' )
			.attr( 'x1', 0 )
			.attr( 'x2', width )
			.attr( 'y1', y( avgDuration ) )
			.attr( 'y2', y( avgDuration ) )
			.attr( 'stroke', '#e57373' )
			.attr( 'stroke-width', 1 )
			.attr( 'stroke-dasharray', '5,5' );

		g.select( '.avg-label' )
			.attr( 'x', width - 5 )
			.attr( 'y', y( avgDuration ) - 5 )
			.attr( 'text-anchor', 'end' )
			.style( 'font-size', '11px' )
			.style( 'fill', '#e57373' )
			.text( `avg: ${ Math.round( avgDuration ) }ms` );

		// Update dots using enter/update/exit pattern.
		const dots = g
			.select( '.dots' )
			.selectAll( '.dot' )
			.data( chartData, ( d ) => d.rid );

		// Remove old dots.
		dots.exit().remove();

		// Add new dots.
		const newDots = dots
			.enter()
			.append( 'circle' )
			.attr( 'class', 'dot' )
			.attr( 'r', 5 )
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
					onRequestClickRef.current( d.rid );
				}
			} );

		// Update positions and colors for all dots (new and existing).
		dots.merge( newDots )
			.attr( 'cx', ( d ) => x( d.time ) )
			.attr( 'cy', ( d ) => y( d.duration ) )
			.attr( 'fill', ( d ) => getStatusColor( d.status ) );

		// Update tooltips.
		g.select( '.dots' ).selectAll( '.dot' ).select( 'title' ).remove();
		g.select( '.dots' )
			.selectAll( '.dot' )
			.append( 'title' )
			.text(
				( d ) =>
					`${ d.time.toLocaleString() }\nStatus: ${
						d.status || 'N/A'
					}\nDuration: ${ Math.round(
						d.duration
					) }ms\nClick to view details`
			);

		// Draw status code legend.
		g.selectAll( '.legend' ).remove();
		const statusCategories = new Set(
			chartData.map( ( d ) => {
				const cat = Math.floor( d.status / 100 );
				return cat >= 2 && cat <= 5 ? `${ cat }xx` : 'unknown';
			} )
		);
		const legendItems = [ '2xx', '3xx', '4xx', '5xx' ].filter( ( k ) =>
			statusCategories.has( k )
		);
		const legend = g
			.append( 'g' )
			.attr( 'class', 'legend' )
			.attr( 'transform', `translate(${ width + 10 }, 0)` );
		legendItems.forEach( ( key, i ) => {
			const ly = i * 16;
			legend
				.append( 'circle' )
				.attr( 'cx', 5 )
				.attr( 'cy', ly + 5 )
				.attr( 'r', 5 )
				.attr( 'fill', STATUS_COLORS[ key ] );
			legend
				.append( 'text' )
				.attr( 'x', 14 )
				.attr( 'y', ly + 9 )
				.text( key )
				.style( 'font-size', '11px' )
				.style( 'fill', '#888' );
		} );
	}, [ chartData ] );

	// Handle resize.
	useEffect( () => {
		const handleResize = () => {
			if ( ! containerRef.current || ! svgRef.current ) {
				return;
			}

			const width =
				( containerRef.current.clientWidth || 800 ) -
				MARGIN.left -
				MARGIN.right;

			// Update SVG and stored width.
			svgRef.current.svg.attr(
				'width',
				width + MARGIN.left + MARGIN.right
			);
			svgRef.current.width = width;

			// Update scale range.
			if ( scalesRef.current ) {
				scalesRef.current.x.range( [ 0, width ] );
			}

			// Trigger data update by re-running the effect.
			// Force update by dispatching a custom event or just re-render.
			if ( chartData.length > 0 && scalesRef.current ) {
				const { g } = svgRef.current;
				const { x, y } = scalesRef.current;

				// Update X axis.
				g.select( '.x-axis' ).call(
					d3
						.axisBottom( x )
						.ticks( Math.min( chartData.length, 8 ) )
						.tickFormat( d3.timeFormat( '%H:%M' ) )
				);
				g.select( '.x-axis' )
					.selectAll( 'text' )
					.attr( 'transform', 'rotate(-45)' )
					.style( 'text-anchor', 'end' );

				// Update trend line.
				if ( chartData.length > 1 ) {
					const line = d3
						.line()
						.x( ( d ) => x( d.time ) )
						.y( ( d ) => y( d.duration ) )
						.curve( d3.curveMonotoneX );

					g.select( '.trend-line' ).attr( 'd', line );
				}

				// Update average line.
				const avgDuration = d3.mean( chartData, ( d ) => d.duration );
				g.select( '.avg-line' ).attr( 'x2', width );
				g.select( '.avg-label' )
					.attr( 'x', width - 5 )
					.attr( 'y', y( avgDuration ) - 5 );

				// Update dot positions.
				g.select( '.dots' )
					.selectAll( '.dot' )
					.attr( 'cx', ( d ) => x( d.time ) );
			}
		};

		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [ chartData ] );

	// Cleanup on unmount.
	useEffect( () => {
		const container = containerRef.current;
		return () => {
			if ( container ) {
				d3.select( container ).selectAll( '*' ).remove();
			}
			svgRef.current = null;
			scalesRef.current = null;
		};
	}, [] );

	const hasData = requests && requests.length > 0;

	if ( ! hasData ) {
		return null;
	}

	return (
		<div className="event-logger-response-chart">
			<h3>Response Times (Recent Requests)</h3>
			<div
				ref={ containerRef }
				style={ { width: '100%', minHeight: '250px' } }
			/>
		</div>
	);
}
