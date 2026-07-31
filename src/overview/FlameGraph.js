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
import { shadeForDepth, pickLabelColor, isColorParseable } from './flameColors';

/**
 * Get tooltip text for a flame graph node.
 *
 * @param {Object} d D3 hierarchy node.
 * @return {string} Tooltip text.
 */
const getTooltipText = ( d ) => {
	// Use 'detail' if available, else 'name' (stable label).
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
	let lastState = null; // tooltip state, restored after updates.

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
		// Don't clear lastState here; we still restore on autorefresh.
	};

	// Restore tooltip after a data update (else it vanishes on autorefresh).
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
 * Read the active theme's accent + background tokens off a container.
 *
 * Falls back through the hub-overlay universal tokens, then the standalone
 * dashboard's `--np-*` tokens, then hardcoded CRT-green defaults, so the ramp
 * works in both contexts.
 *
 * @param {Element} container Element whose computed style carries the tokens.
 * @return {{accent: string, bg: string}} Resolved hex colors.
 */
const readThemeTokens = ( container ) => {
	const style = window.getComputedStyle( container );
	const read = ( name ) => style.getPropertyValue( name ).trim();
	// First parseable color (skips named / color-mix() tokens).
	const pick = ( ...candidates ) => candidates.find( isColorParseable );
	const accent = pick( read( '--cyan' ), read( '--np-primary' ), '#41e07a' );
	const bg = pick( read( '--paper' ), read( '--np-surface' ), '#ffffff' );
	return { accent, bg };
};

/**
 * Color frames by stack depth in shades of the active theme's accent.
 *
 * @param {string} accent Theme accent hex.
 * @param {string} bg     Theme background hex.
 * @return {Function} d3-flame-graph color mapper: (d) => 'rgb(...)'.
 */
const createColorMapper = ( accent, bg ) => ( d ) =>
	shadeForDepth( d.depth, accent, bg );

/**
 * Set each frame's label color for contrast against its (depth-shaded) fill.
 *
 * d3-flame-graph renders the label as a `div.d3-flame-graph-label` inside the
 * frame's `<foreignObject>`; the fill lives on the frame's `<rect>`. Re-run
 * after every chart render/update since the labels are recreated.
 *
 * @param {Element} container Flame graph container element.
 */
const applyLabelContrast = ( container ) => {
	d3.select( container )
		.selectAll( 'g.frame' )
		.each( function () {
			const frame = d3.select( this );
			const fill = frame.select( 'rect' ).attr( 'fill' );
			if ( ! fill ) {
				return;
			}
			frame
				.select( '.d3-flame-graph-label' )
				.style( 'color', pickLabelColor( fill ) );
		} );
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

// Pruning thresholds: keep top-N or >= min fraction; hard cap is the ceiling.
const PRUNE_MIN_FRACTION = 0.001;
const PRUNE_SOFT_MAX_NODES = 1000;
const PRUNE_HARD_MAX_NODES = 5000;

/**
 * Clone a flame node, keeping only descendants whose value >= cutoff.
 *
 * Children are nested within their parent, so a node's value never exceeds its
 * parent's — dropping a node below cutoff safely drops its whole subtree.
 *
 * @param {Object} node   Flame node.
 * @param {number} cutoff Minimum value (in ms) a child must reach to survive.
 * @return {Object} New node with pruned children.
 */
const cloneAboveCutoff = ( node, cutoff ) => {
	const children = ( node.children || [] )
		.filter( ( child ) => ( child.value || 0 ) >= cutoff )
		.map( ( child ) => cloneAboveCutoff( child, cutoff ) );
	return { ...node, children };
};

/**
 * Count nodes in a flame tree.
 *
 * @param {Object} node Flame node.
 * @return {number} Total node count including this node.
 */
const countNodes = ( node ) =>
	( node.children || [] ).reduce(
		( sum, child ) => sum + countNodes( child ),
		1
	);

/**
 * Collect the values of every non-root node into the given array.
 *
 * @param {Object}  node   Flame node.
 * @param {Array}   values Accumulator.
 * @param {boolean} isRoot Whether this node is the root (excluded).
 */
const collectNonRootValues = ( node, values, isRoot ) => {
	if ( ! isRoot ) {
		values.push( node.value || 0 );
	}
	( node.children || [] ).forEach( ( child ) =>
		collectNonRootValues( child, values, false )
	);
};

/**
 * Prune a flame graph tree for rendering.
 *
 * A frame is kept if it is among the largest `softMaxNodes` frames OR is at
 * least `minFraction` of the total (root) time — so small frames are never
 * stripped while the graph is under the soft cap, and stay visible past it as
 * long as they clear the fraction. `hardMaxNodes` is an absolute ceiling that
 * raises the cutoff to the largest frames if survivors still exceed it. The
 * root is always preserved. Frame values are monotonic down the tree (a child
 * never exceeds its parent), so a single value cutoff prunes consistently.
 *
 * @param {Object} root                   Flame graph root (value = total time).
 * @param {Object} [options]              Tuning.
 * @param {number} [options.minFraction]  Min fraction of total kept past the soft cap (default 0.001).
 * @param {number} [options.softMaxNodes] Frames ranked within this are always kept (default 1000).
 * @param {number} [options.hardMaxNodes] Absolute ceiling on rendered node count (default 5000).
 * @return {Object} Pruned tree (a copy; the input is not mutated).
 */
export const pruneFlameGraph = ( root, options = {} ) => {
	if ( ! root ) {
		return root;
	}
	const minFraction = options.minFraction ?? PRUNE_MIN_FRACTION;
	const softMaxNodes = options.softMaxNodes ?? PRUNE_SOFT_MAX_NODES;
	const hardMaxNodes = options.hardMaxNodes ?? PRUNE_HARD_MAX_NODES;
	const total = root.value || 0;

	// Under the soft cap, keep all frames (common case; skips the sort). Clone.
	if ( countNodes( root ) <= softMaxNodes ) {
		return cloneAboveCutoff( root, 0 );
	}

	const values = [];
	collectNonRootValues( root, values, true );
	values.sort( ( a, b ) => b - a );

	// Value of the frame at rank maxNodes; 0 = fewer frames, keep everything.
	const valueAtRank = ( maxNodes ) => values[ maxNodes - 2 ] ?? 0;

	// Keep frames in the top softMaxNodes OR >= minFraction of total.
	let cutoff = Math.min( total * minFraction, valueAtRank( softMaxNodes ) );
	let pruned = cloneAboveCutoff( root, cutoff );

	if ( countNodes( pruned ) > hardMaxNodes ) {
		// Still too many — raise cutoff to the top hardMaxNodes frames.
		cutoff = Math.max( cutoff, valueAtRank( hardMaxNodes ) );
		pruned = cloneAboveCutoff( root, cutoff );
	}

	return pruned;
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
	const metaClickRef = useRef( false ); // tooltip meta across refreshes.
	const zoomedNodeRef = useRef( null ); // zoomed path across refreshes.
	const lastChangeKeyRef = useRef( '' ); // skip redundant updates.

	// Keep top frames + everything >= 0.1% of total; cap before rendering.
	const prunedData = useMemo( () => pruneFlameGraph( data ), [ data ] );

	// Create/update chart when data changes.
	useEffect( () => {
		if ( ! prunedData || ! containerRef.current ) {
			return;
		}

		const container = containerRef.current;
		const width = container.clientWidth || 800;

		// Depth-shaded palette in theme accent; re-read each render (reskins).
		const { accent, bg } = readThemeTokens( container );
		const colorMapper = createColorMapper( accent, bg );

		// Skip update if data unchanged (server timestamp for aggregates).
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
				chartRef.current.update( prunedData );
				applyLabelContrast( container );

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

				// Restore tooltip state (else it vanishes on refresh/zoom).
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
				.transitionDuration( 0 ) // no transitions for auto-refresh.
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
			d3.select( container ).datum( prunedData ).call( chart );
			applyLabelContrast( container );

			// Track Cmd/Ctrl on mousedown (more reliable than click on Mac).
			container.addEventListener( 'mousedown', ( e ) => {
				metaClickRef.current = e.metaKey || e.ctrlKey;
			} );
		}
	}, [ prunedData, lastModified, onRevealEntry ] );

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

	// Re-fit on CONTAINER resize (window resize misses panels); debounced.
	useEffect( () => {
		const container = containerRef.current;
		if ( ! container || typeof window.ResizeObserver === 'undefined' ) {
			return undefined;
		}
		let timer = null;
		const ro = new window.ResizeObserver( () => {
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( () => {
				if ( chartRef.current && containerRef.current && prunedData ) {
					const width = containerRef.current.clientWidth || 800;
					chartRef.current.width( width );
					d3.select( containerRef.current )
						.datum( prunedData )
						.call( chartRef.current );
					applyLabelContrast( containerRef.current );
				}
			}, 150 );
		} );
		ro.observe( container );
		return () => {
			if ( timer ) {
				clearTimeout( timer );
			}
			ro.disconnect();
		};
	}, [ prunedData ] );

	// Gate the empty state on original data; pruning must not fake "no data".
	if ( ! data || ! data.children || data.children.length === 0 ) {
		return (
			<div className="event-logger-flame-empty newspack-nodes-empty-state">
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

	// Handlers are auxiliary (tooltip/transition); D3 owns the interactive SVG.
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
