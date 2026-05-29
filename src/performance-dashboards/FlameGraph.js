/**
 * Flame Graph Component
 *
 * D3-based flame graph visualization using d3-flame-graph.
 */

import { useEffect, useRef, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import { flamegraph } from 'd3-flame-graph';
import 'd3-flame-graph/dist/d3-flamegraph.css';
import './styles/flame-graph.scss';
import { getStateColor } from '../shared/utils/formatUtils';

/**
 * Get tooltip text for a flame graph node.
 *
 * @param {Object} d D3 hierarchy node.
 * @return {string} Tooltip text.
 */
const getTooltipText = ( d ) => {
	// Use 'detail' (with volatile message) if available, else 'name' (stable label).
	const name = d.data?.detail || d.data?.name || 'unknown';
	const value = d.data?.value || 0;

	// Find root by traversing up.
	let root = d;
	while ( root.parent ) {
		root = root.parent;
	}
	const rootValue = root.data?.value || 0;

	// Calculate % of total.
	const pctTotal = rootValue > 0 ? ( value / rootValue ) * 100 : 100;

	// Calculate % of parent.
	const parentValue = d.parent?.data?.value || 0;
	const pctParent = parentValue > 0 ? ( value / parentValue ) * 100 : 100;

	// Format: show both percentages for non-root nodes.
	if ( d.parent && Math.abs( pctParent - pctTotal ) > 0.1 ) {
		return sprintf(
			// translators: 1: node name, 2: percent of parent, 3: percent of total, 4: duration in milliseconds.
			__(
				'%1$s - %2$s%% of parent, %3$s%% of total, %4$sms',
				'newspack-event-logger-nodes'
			),
			name,
			pctParent.toFixed( 1 ),
			pctTotal.toFixed( 1 ),
			value.toFixed( 3 )
		);
	}
	return sprintf(
		// translators: 1: node name, 2: percent of total, 3: duration in milliseconds.
		__( '%1$s - %2$s%%, %3$sms', 'newspack-event-logger-nodes' ),
		name,
		pctTotal.toFixed( 1 ),
		value.toFixed( 3 )
	);
};

/**
 * Create tooltip with viewport-aware positioning and state persistence.
 *
 * @return {Function} Tooltip with .show(), .hide(), .restore() methods.
 */
const createTooltip = () => {
	let tooltipEl = null;
	let lastState = null; // Track tooltip state for restoration after data updates.

	const tip = function () {
		tooltipEl = d3
			.select( 'body' )
			.append( 'div' )
			.attr( 'class', 'd3-flame-graph-tip' )
			.style( 'display', 'none' )
			.style( 'position', 'absolute' )
			.style( 'pointer-events', 'none' );
	};

	tip.show = function ( d ) {
		if ( ! tooltipEl ) {
			tip();
		}

		const text = getTooltipText( d );

		// Get mouse position and viewport dimensions.
		const mouseX = window.event?.pageX || 0;
		const mouseY = window.event?.pageY || 0;
		const viewportWidth = window.innerWidth;

		// Calculate available space to the right of cursor.
		const spaceOnRight = viewportWidth - ( mouseX - window.scrollX ) - 25;

		// Use a reasonable max width, clamped to available space.
		const maxWidth = Math.min( 500, Math.max( 250, spaceOnRight ) );

		// Position: prefer right of cursor, but clamp to viewport.
		let left = mouseX + 15;
		const rightEdge = window.scrollX + viewportWidth - 10;

		// Apply styles, set text, then measure.
		tooltipEl
			.style( 'max-width', maxWidth + 'px' )
			.style( 'white-space', 'pre-wrap' )
			.style( 'word-wrap', 'break-word' )
			.style( 'overflow-wrap', 'break-word' )
			.text( text )
			.style( 'display', 'block' );

		// Measure actual size after text and styles applied.
		const tipNode = tooltipEl.node();
		const tipWidth = tipNode.offsetWidth;
		const tipHeight = tipNode.offsetHeight;

		// If tooltip would overflow right edge, shift left to fit.
		if ( left + tipWidth > rightEdge ) {
			left = rightEdge - tipWidth;
		}

		// Ensure tooltip doesn't go off left edge.
		if ( left < window.scrollX + 10 ) {
			left = window.scrollX + 10;
		}

		// Keep tooltip above mouse if near bottom.
		let top = mouseY + 15;
		if ( mouseY + tipHeight + 20 > window.innerHeight + window.scrollY ) {
			top = mouseY - tipHeight - 15;
		}

		tooltipEl.style( 'left', left + 'px' ).style( 'top', top + 'px' );

		// Store state for restoration after data updates.
		lastState = { text, left, top };
	};

	tip.hide = function () {
		if ( tooltipEl ) {
			tooltipEl.style( 'display', 'none' );
		}
		// Don't clear lastState here - zoom/click events trigger hide,
		// but we still want to restore on autorefresh.
	};

	// Restore tooltip after chart data update (prevents disappearing on autorefresh).
	tip.restore = function () {
		if ( lastState && tooltipEl ) {
			tooltipEl
				.text( lastState.text )
				.style( 'display', 'block' )
				.style( 'left', lastState.left + 'px' )
				.style( 'top', lastState.top + 'px' );
		}
	};

	// Check if we have state to restore (not whether currently displayed).
	tip.hasState = function () {
		return lastState !== null;
	};

	// Clear state explicitly (e.g., when user moves mouse away).
	tip.clearState = function () {
		lastState = null;
	};

	tip.destroy = function () {
		if ( tooltipEl ) {
			tooltipEl.remove();
			tooltipEl = null;
		}
		lastState = null;
	};

	return tip;
};

/**
 * Create color mapper for flame graph nodes.
 *
 * Flame data structure from PHP build_flame_data():
 * - name: "{base_name}: {label}" or just "{base_name}"
 *   e.g. "memcached: /path/to/file.php", "include: /Templates/foo.html", "process"
 * - value: duration in ms (pre-scaled by seen_count/total_count for aggregate graphs)
 * - children: array of child nodes
 *
 * @return {Function} Color mapper function.
 */
const createColorMapper = () => ( d ) => {
	const name = d.data.name || '';
	return getStateColor( name );
};

/**
 * Get the path from root to a node (array of names).
 *
 * @param {Object} d D3 hierarchy node.
 * @return {Array} Array of node names from root to this node.
 */
const getNodePath = ( d ) => {
	const path = [];
	let current = d;
	while ( current ) {
		// Use detail (with message) if available, else name.
		path.unshift( current.data?.detail || current.data?.name || 'unknown' );
		current = current.parent;
	}
	return path;
};

/**
 * Find a node by following a path of names.
 *
 * @param {Object} node D3 hierarchy node (root).
 * @param {Array}  path Array of names to follow.
 * @return {Object|null} Found node or null.
 */
const findNodeByPath = ( node, path ) => {
	if ( ! path || path.length === 0 ) {
		return null;
	}

	// Check if current node matches first path element.
	if ( node.data?.name !== path[ 0 ] ) {
		return null;
	}

	// If this is the last element, we found it.
	if ( path.length === 1 ) {
		return node;
	}

	// Search children for next path element.
	if ( node.children ) {
		const remainingPath = path.slice( 1 );
		for ( const child of node.children ) {
			const found = findNodeByPath( child, remainingPath );
			if ( found ) {
				return found;
			}
		}
	}

	return null;
};

/**
 * Flame Graph component.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.data          Flame graph data structure.
 * @param {number}   props.lastModified  Server-side timestamp for change detection (optional).
 * @param {Function} props.onRevealEntry Callback with node path on Cmd/Ctrl+Click.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function FlameGraph( { data, lastModified, onRevealEntry } ) {
	const containerRef = useRef( null );
	const chartRef = useRef( null );
	const tooltipRef = useRef( null );
	const metaClickRef = useRef( false ); // Track tooltip for restoration across refreshes.
	const zoomedNodeRef = useRef( null ); // Track zoomed node path for preservation across refreshes.
	const lastChangeKeyRef = useRef( '' ); // Track change key to skip unnecessary updates.

	// Memoize color mapper (stable reference since it no longer depends on data).
	const colorMapper = useMemo( () => createColorMapper(), [] );

	// Create/update chart when data changes.
	useEffect( () => {
		if ( ! data || ! containerRef.current ) {
			return;
		}

		const container = containerRef.current;
		const width = container.clientWidth || 800;

		// Skip update if data hasn't changed (use server-side timestamp for aggregates).
		const dataChanged = lastModified
			? String( lastModified ) !== lastChangeKeyRef.current
			: true; // No timestamp = single request, always render on mount.

		if ( chartRef.current ) {
			// Only update if data actually changed.
			if ( dataChanged ) {
				if ( lastModified ) {
					lastChangeKeyRef.current = String( lastModified );
				}

				// Check if tooltip has state to restore after update.
				const tooltipHasState = tooltipRef.current?.hasState?.();

				// Update existing chart - no flicker.
				chartRef.current
					.width( width )
					.setColorMapper( colorMapper )
					.transitionDuration( 0 );
				chartRef.current.update( data );

				// Restore zoom to previously zoomed node after update.
				if ( zoomedNodeRef.current ) {
					const selection = d3.select( container ).datum();
					const targetNode = findNodeByPath(
						selection,
						zoomedNodeRef.current
					);
					if ( targetNode ) {
						chartRef.current.zoomTo( targetNode );
					}
				}

				// Restore tooltip if it had state (prevents disappearing on autorefresh/zoom).
				if ( tooltipHasState ) {
					tooltipRef.current?.restore?.();
				}
			}
		} else {
			// Create new chart only on first render.
			if ( lastModified ) {
				lastChangeKeyRef.current = String( lastModified );
			}
			const tip = createTooltip();
			tooltipRef.current = tip;

			const chart = flamegraph()
				.width( width )
				.cellHeight( 20 )
				.transitionDuration( 0 ) // Start with no transitions for auto-refresh efficiency.
				.minFrameSize( 0 )
				.sort( true )
				.title( '' )
				.getName( ( d ) => d.data?.detail || d.data?.name || '' )
				.tooltip( tip )
				.selfValue( false )
				.setColorMapper( colorMapper )
				.onClick( ( d ) => {
					// Cmd/Ctrl+Click: reveal in log table.
					if ( onRevealEntry && d && metaClickRef.current ) {
						metaClickRef.current = false;
						onRevealEntry( getNodePath( d ) );
						return;
					}

					// Track zoomed node path for preservation across refreshes.
					zoomedNodeRef.current = d ? getNodePath( d ) : null;

					// Disable transitions after animation completes.
					setTimeout( () => {
						if ( chartRef.current ) {
							chartRef.current.transitionDuration( 0 );
						}
					}, 305 );
				} );

			chartRef.current = chart;
			d3.select( container ).datum( data ).call( chart );

			// Track Cmd/Ctrl state on mousedown (more reliable than click
			// which Mac browsers may intercept for Cmd+Click).
			container.addEventListener( 'mousedown', ( e ) => {
				metaClickRef.current = e.metaKey || e.ctrlKey;
			} );
		}
	}, [ data, lastModified, colorMapper, onRevealEntry ] );

	// Cleanup on unmount - reset zoom state so navigation resets view.
	useEffect( () => {
		const container = containerRef.current;
		return () => {
			// Destroy chart instance if it has a destroy method.
			if ( chartRef.current?.destroy ) {
				chartRef.current.destroy();
			}
			// Remove all D3 elements from container.
			if ( container ) {
				d3.select( container ).selectAll( '*' ).remove();
			}
			// Destroy tooltip state properly before removing elements.
			tooltipRef.current?.destroy?.();
			// Remove any tooltip elements that d3-flame-graph may have created.
			d3.selectAll( '.d3-flame-graph-tip' ).remove();
			// Clear refs.
			chartRef.current = null;
			tooltipRef.current = null;
			zoomedNodeRef.current = null;
		};
	}, [] );

	// Handle resize.
	useEffect( () => {
		const handleResize = () => {
			if ( chartRef.current && containerRef.current && data ) {
				const width = containerRef.current.clientWidth || 800;
				chartRef.current.width( width );
				d3.select( containerRef.current )
					.datum( data )
					.call( chartRef.current );
			}
		};

		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [ data ] );

	if ( ! data || ! data.children || data.children.length === 0 ) {
		return (
			<div className="event-logger-flame-empty">
				<p>
					{ __(
						'No flame graph data available.',
						'newspack-event-logger-nodes'
					) }
				</p>
			</div>
		);
	}

	/**
	 * Handle mouse leave to clear tooltip state.
	 */
	const handleMouseLeave = () => {
		tooltipRef.current?.clearState?.();
	};

	/**
	 * Handle mousedown to enable transitions before d3-flame-graph processes the click.
	 */
	const handleMouseDown = () => {
		if ( chartRef.current ) {
			chartRef.current.transitionDuration( 300 );
		}
	};

	// Handlers are for auxiliary behavior (tooltip cleanup, transition timing).
	// D3 flame graph creates its own interactive SVG elements inside.
	return (
		<div
			ref={ containerRef }
			role="presentation"
			className="event-logger-flame-graph"
			style={ { width: '100%', minHeight: '200px' } }
			onMouseLeave={ handleMouseLeave }
			onMouseDown={ handleMouseDown }
		/>
	);
}
