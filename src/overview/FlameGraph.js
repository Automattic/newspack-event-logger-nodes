/**
 * Flame graph for the request, per-URL, and current-request detail views.
 *
 * Wraps `d3-flame-graph` in a React component and adds what the dashboards
 * need on top of it: frames shaded by stack depth in the active theme's
 * accent, a viewport-aware tooltip, zoom and tooltip that both survive an
 * auto-refresh re-render, Cmd/Ctrl+Click to reveal a frame in the log table,
 * and a refit when the container (not the window) resizes. `pruneFlameGraph`
 * caps the rendered frame count so a pathological aggregate cannot lock up
 * the browser.
 *
 * D3 owns the SVG; React owns only the container element and two auxiliary
 * pointer handlers.
 *
 * Frames arrive from `Flame_Tree` as `{ name, value, children[], detail? }`,
 * where `value` is milliseconds and a child's value never exceeds its
 * parent's.
 */

import { useEffect, useRef, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as d3 from 'd3';
import { flamegraph } from 'd3-flame-graph';
import 'd3-flame-graph/dist/d3-flamegraph.css';
import './styles/flame-graph.scss';
import { shadeForDepth, pickLabelColor, isColorParseable } from './flameColors';

/**
 * Build the tooltip text for a frame: name, shares of parent and total, duration.
 *
 * A frame whose share of its parent matches its share of the total (within
 * 0.1 point) shows one percentage rather than repeating itself.
 *
 * @param {Object} d D3 hierarchy node.
 * @return {string} Tooltip text.
 */
const getTooltipText = ( d ) => {
	// 'detail' carries the message; 'name' is the stable label.
	const name = d.data?.detail || d.data?.name || 'unknown';
	const value = d.data?.value || 0;

	let root = d;
	while ( root.parent ) {
		root = root.parent;
	}
	const rootValue = root.data?.value || 0;

	const pctTotal = rootValue > 0 ? ( value / rootValue ) * 100 : 100;

	const parentValue = d.parent?.data?.value || 0;
	const pctParent = parentValue > 0 ? ( value / parentValue ) * 100 : 100;

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
 * Build a tooltip satisfying d3-flame-graph's tooltip contract.
 *
 * The chart calls the returned function once to create the element, then
 * `.show(d)` and `.hide()` on hover. Positioning prefers the space right of
 * and below the cursor, flipping or clamping when the tip would overflow a
 * viewport edge.
 *
 * `.show()` also records what it rendered so `.restore()` can replay it: an
 * auto-refresh destroys the hovered frame, and without the replay the tooltip
 * vanishes mid-read. `.clearState()` drops that record when the pointer
 * leaves the graph.
 *
 * @return {Function} Tooltip exposing .show(), .hide(), .restore(), .hasState(), .clearState() and .destroy().
 */
const createTooltip = () => {
	let tooltipEl = null;
	let lastState = null;

	/**
	 * Create the tooltip element. d3-flame-graph calls this once, on attach.
	 */
	const tip = function () {
		tooltipEl = d3
			.select( 'body' )
			.append( 'div' )
			.attr( 'class', 'd3-flame-graph-tip' )
			.style( 'display', 'none' )
			.style( 'position', 'absolute' )
			.style( 'pointer-events', 'none' );
	};

	/**
	 * Show the tooltip for a frame, positioned near the cursor.
	 *
	 * @param {Object} d D3 hierarchy node under the pointer.
	 */
	tip.show = function ( d ) {
		if ( ! tooltipEl ) {
			tip();
		}

		const text = getTooltipText( d );

		// d3-flame-graph passes no event; window.event is the only source.
		const mouseX = window.event?.pageX || 0;
		const mouseY = window.event?.pageY || 0;
		const viewportWidth = window.innerWidth;

		const spaceOnRight = viewportWidth - ( mouseX - window.scrollX ) - 25;

		const maxWidth = Math.min( 500, Math.max( 250, spaceOnRight ) );

		let left = mouseX + 15;
		const rightEdge = window.scrollX + viewportWidth - 10;

		// Text and styles must land before the element can be measured.
		tooltipEl
			.style( 'max-width', maxWidth + 'px' )
			.style( 'white-space', 'pre-wrap' )
			.style( 'word-wrap', 'break-word' )
			.style( 'overflow-wrap', 'break-word' )
			.text( text )
			.style( 'display', 'block' );

		const tipNode = tooltipEl.node();
		const tipWidth = tipNode.offsetWidth;
		const tipHeight = tipNode.offsetHeight;

		if ( left + tipWidth > rightEdge ) {
			left = rightEdge - tipWidth;
		}

		if ( left < window.scrollX + 10 ) {
			left = window.scrollX + 10;
		}

		// Flip above the cursor rather than run off the bottom.
		let top = mouseY + 15;
		if ( mouseY + tipHeight + 20 > window.innerHeight + window.scrollY ) {
			top = mouseY - tipHeight - 15;
		}

		tooltipEl.style( 'left', left + 'px' ).style( 'top', top + 'px' );

		lastState = { text, left, top };
	};

	/**
	 * Hide the tooltip, keeping its state so `restore()` can bring it back.
	 */
	tip.hide = function () {
		if ( tooltipEl ) {
			tooltipEl.style( 'display', 'none' );
		}
	};

	/**
	 * Re-show the last tooltip after a data update destroyed its frame.
	 */
	tip.restore = function () {
		if ( lastState && tooltipEl ) {
			tooltipEl
				.text( lastState.text )
				.style( 'display', 'block' )
				.style( 'left', lastState.left + 'px' )
				.style( 'top', lastState.top + 'px' );
		}
	};

	/**
	 * Whether a tooltip state exists to restore — not whether one is displayed.
	 *
	 * @return {boolean} True when `restore()` has something to replay.
	 */
	tip.hasState = function () {
		return lastState !== null;
	};

	/**
	 * Forget the last tooltip, so a refresh does not resurrect it.
	 */
	tip.clearState = function () {
		lastState = null;
	};

	/**
	 * Remove the tooltip element and drop its state.
	 */
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
 * Build the root-to-node path, one segment per frame.
 *
 * Segments prefer `detail` ("name: message") over `name`, which is what
 * `LogEntriesTable.revealPath()` expects — it matches on the detail path and
 * falls back to the base names.
 *
 * @param {Object} d D3 hierarchy node.
 * @return {Array} Frame labels from the root down to this node.
 */
const getNodePath = ( d ) => {
	const path = [];
	let current = d;
	while ( current ) {
		path.unshift( current.data?.detail || current.data?.name || 'unknown' );
		current = current.parent;
	}
	return path;
};

/**
 * Walk a path of frame names down from the root and return the node it names.
 *
 * Matching is on `data.name` alone, so a path segment that `getNodePath()`
 * took from `detail` will not match. Zoom restoration therefore resolves only
 * for detail-less frames.
 *
 * @param {Object} node D3 hierarchy node (root).
 * @param {Array}  path Names to follow, root first.
 * @return {Object|null} The named node, or null when the path does not resolve.
 */
const findNodeByPath = ( node, path ) => {
	if ( ! path || path.length === 0 ) {
		return null;
	}

	if ( node.data?.name !== path[ 0 ] ) {
		return null;
	}

	if ( path.length === 1 ) {
		return node;
	}

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
 * @testonly Exported for FlameGraph.test.js; the component prunes internally.
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
 * Flame graph component.
 *
 * The chart is built once and updated in place afterwards, so an auto-refresh
 * neither flickers nor loses the viewer's zoom and tooltip. Omitting
 * `lastModified` (a single request, whose flame never changes) makes every
 * render count as a change.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.data          Flame tree root: { name, value, children[] }.
 * @param {number}   props.lastModified  Server timestamp gating updates; omit for single requests.
 * @param {Function} props.onRevealEntry Called with the frame's path on Cmd/Ctrl+Click.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function FlameGraph( { data, lastModified, onRevealEntry } ) {
	const containerRef = useRef( null );
	const chartRef = useRef( null );
	const tooltipRef = useRef( null );
	const metaClickRef = useRef( false ); // Cmd/Ctrl held at the last mousedown.
	const zoomedNodeRef = useRef( null ); // zoomed path across refreshes.
	const lastChangeKeyRef = useRef( '' ); // last rendered lastModified.

	const prunedData = useMemo( () => pruneFlameGraph( data ), [ data ] );

	// Build the chart on first run; update it in place on every later one.
	useEffect( () => {
		if ( ! prunedData || ! containerRef.current ) {
			return;
		}

		const container = containerRef.current;
		const width = container.clientWidth || 800;

		// Depth-shaded palette in theme accent; re-read each render (reskins).
		const { accent, bg } = readThemeTokens( container );
		const colorMapper = createColorMapper( accent, bg );

		const dataChanged = lastModified
			? String( lastModified ) !== lastChangeKeyRef.current
			: true; // No timestamp: a single request's flame never changes.

		if ( chartRef.current ) {
			if ( dataChanged ) {
				if ( lastModified ) {
					lastChangeKeyRef.current = String( lastModified );
				}

				const tooltipHasState = tooltipRef.current?.hasState?.();

				// Update in place — rebuilding the chart flickers.
				chartRef.current
					.width( width )
					.setColorMapper( colorMapper )
					.transitionDuration( 0 );
				chartRef.current.update( prunedData );
				applyLabelContrast( container );

				// Re-zoom to where the viewer was before the data changed.
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
			// First run: build the chart and its tooltip.
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

					// Remember the zoom so a refresh can return to it.
					zoomedNodeRef.current = d ? getNodePath( d ) : null;

					// 305ms: just past the 300ms zoom mousedown enabled.
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

	// Tear down on unmount; tooltips live on <body>, outside React's reach.
	useEffect( () => {
		const container = containerRef.current;
		return () => {
			if ( chartRef.current?.destroy ) {
				chartRef.current.destroy();
			}
			if ( container ) {
				d3.select( container ).selectAll( '*' ).remove();
			}
			tooltipRef.current?.destroy?.();
			// Sweep any tip d3-flame-graph created behind our back.
			d3.selectAll( '.d3-flame-graph-tip' ).remove();
			chartRef.current = null;
			tooltipRef.current = null;
			zoomedNodeRef.current = null;
		};
	}, [] );

	// Refit on CONTAINER resize; a window listener misses panel resizes.
	useEffect( () => {
		const container = containerRef.current;
		if ( ! container || typeof window.ResizeObserver === 'undefined' ) {
			return undefined;
		}
		let timer = null;
		// Debounced 150ms: a drag fires the observer on every frame.
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
	 * Forget the tooltip when the pointer leaves, so no refresh revives it.
	 */
	const handleMouseLeave = () => {
		tooltipRef.current?.clearState?.();
	};

	/**
	 * Animate the zoom about to happen: d3-flame-graph reads the duration when
	 * it handles the click, and the chart's own onClick zeroes it again once
	 * the animation has run.
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
