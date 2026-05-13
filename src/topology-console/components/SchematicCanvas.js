/**
 * SchematicCanvas — raw SVG drafting-room canvas.
 *
 * Renders {nodes, edges} from parseLsOutput + autoLayout as an
 * engineering schematic: rectangular cards with a TYPE band header, an
 * id row, a status LED, a counter cell, input/output ports on the
 * sides, and orthogonal flow-dashed edges connecting them.
 *
 * Inspect-only in v1 — no drag, no palette drop. Click selects a node;
 * background click deselects. Layout is recomputed whenever the parsed
 * graph changes (node added/removed), but persists otherwise to keep
 * the canvas stable while counters tick.
 */

import { useMemo, useRef, useState } from '@wordpress/element';

import { autoLayout, X_STEP, Y_STEP, X_PAD, Y_PAD } from '../utils/autoLayout';
import { inferType } from '../utils/inferType';

const NODE_W = 196;
const NODE_H = 84;
const PORT_R = 4.5;
// Movement threshold (SVG units) before a pointer-down + drag is
// treated as a drag rather than a click. Anything under suppresses
// the drag and lets the click handler fire (node selection).
const DRAG_THRESHOLD = 3;

// Convert a viewport-coords pointer event to SVG-coords. SchematicCanvas
// uses viewBox so screen pixels and SVG units differ by the CTM scale;
// without this conversion the dragged node lags behind the cursor at
// any non-1:1 viewport size.
function screenToSvg( svg, clientX, clientY ) {
	const pt = svg.createSVGPoint();
	pt.x = clientX;
	pt.y = clientY;
	const ctm = svg.getScreenCTM();
	return ctm
		? pt.matrixTransform( ctm.inverse() )
		: { x: clientX, y: clientY };
}

function compactCount( count ) {
	if ( count === null || count === undefined ) {
		return '—';
	}
	return count.toLocaleString();
}

function edgePath( a, b ) {
	const x1 = a.position.x + NODE_W;
	const y1 = a.position.y + NODE_H / 2;
	const x2 = b.position.x;
	const y2 = b.position.y + NODE_H / 2;
	// Cubic bezier — control points pulled horizontally from each
	// port so the curve eases in/out of the node. Orthogonal-elbow
	// routing made long vertical drops read as column separators
	// rather than directed edges; a smooth S-curve makes the source/
	// destination of each edge unmistakable even when several edges
	// converge on the same input port.
	const dx = Math.max( 60, Math.abs( x2 - x1 ) * 0.5 );
	const c1x = x1 + dx;
	const c2x = x2 - dx;
	return `M ${ x1 },${ y1 } C ${ c1x },${ y1 } ${ c2x },${ y2 } ${
		x2 - 6
	},${ y2 }`;
}

function viewBoxFor( nodes ) {
	if ( ! nodes.length ) {
		return '0 0 1280 720';
	}
	let maxX = 0;
	let maxY = 0;
	for ( const n of nodes ) {
		maxX = Math.max( maxX, n.position.x + NODE_W );
		maxY = Math.max( maxY, n.position.y + NODE_H );
	}
	return `0 0 ${ Math.max( 1280, maxX + 60 ) } ${ Math.max(
		720,
		maxY + 80
	) }`;
}

export default function SchematicCanvas( {
	parsed,
	selectedId,
	onSelect,
	onDeselect,
	hoveredId,
	onHover,
	positionOverrides,
	onPositionChange,
} ) {
	// Apply user-pinned position overrides on top of the auto-layout
	// output. autoLayout still runs every poll (so newly-added nodes
	// get sensible defaults), but any node the user has dragged keeps
	// its dragged position — keyed by node name, so the override
	// survives substrate restarts that re-seed counters.
	const { nodes: laidOutNodes, edges } = useMemo(
		() => autoLayout( parsed ),
		[ parsed ]
	);
	const nodes = useMemo( () => {
		if ( ! positionOverrides ) {
			return laidOutNodes;
		}
		return laidOutNodes.map( ( n ) =>
			positionOverrides[ n.id ]
				? { ...n, position: positionOverrides[ n.id ] }
				: n
		);
	}, [ laidOutNodes, positionOverrides ] );

	// Active-drag state. Held in a single object so the dragged node
	// can render at its current (un-snapped) position while everyone
	// else stays put. Snap happens on pointerup; that's when the
	// committed override is sent back to the parent.
	const [ drag, setDrag ] = useState( null );
	// Whether the most recent pointer-down crossed the drag threshold.
	// Click handler reads this to suppress selection after a real drag.
	const draggedRef = useRef( false );

	const displayNodes = useMemo( () => {
		if ( ! drag ) {
			return nodes;
		}
		return nodes.map( ( n ) =>
			n.id === drag.nodeId ? { ...n, position: drag.currentPos } : n
		);
	}, [ nodes, drag ] );

	const nodeById = useMemo( () => {
		const map = new Map();
		displayNodes.forEach( ( n ) => map.set( n.id, n ) );
		return map;
	}, [ displayNodes ] );
	const viewBox = useMemo(
		() => viewBoxFor( displayNodes ),
		[ displayNodes ]
	);

	// hoveredId is lifted to the parent so the Inspector can drive
	// it too (hovering a `target` / `← from` value in the inspector
	// highlights the same edges as hovering the node on the canvas).
	const setHovered = ( id ) => {
		if ( onHover ) {
			onHover( id );
		}
	};

	const beginDrag = ( e, node ) => {
		// Only left-button drags; right/middle reserved for browser.
		if ( e.button !== 0 ) {
			return;
		}
		e.stopPropagation();
		draggedRef.current = false;
		const svg = e.currentTarget.ownerSVGElement;
		const startSvg = screenToSvg( svg, e.clientX, e.clientY );
		setDrag( {
			nodeId: node.id,
			startSvg,
			originalPos: node.position,
			currentPos: node.position,
		} );
		e.currentTarget.setPointerCapture( e.pointerId );
	};

	const updateDrag = ( e ) => {
		if ( ! drag ) {
			return;
		}
		const svg = e.currentTarget.ownerSVGElement;
		const cur = screenToSvg( svg, e.clientX, e.clientY );
		const dx = cur.x - drag.startSvg.x;
		const dy = cur.y - drag.startSvg.y;
		if (
			Math.abs( dx ) > DRAG_THRESHOLD ||
			Math.abs( dy ) > DRAG_THRESHOLD
		) {
			draggedRef.current = true;
		}
		setDrag( {
			...drag,
			currentPos: {
				x: drag.originalPos.x + dx,
				y: drag.originalPos.y + dy,
			},
		} );
	};

	const endDrag = ( e ) => {
		if ( ! drag ) {
			return;
		}
		try {
			e.currentTarget.releasePointerCapture( e.pointerId );
		} catch ( _err ) {
			// Pointer capture may already be released; ignore.
		}
		if ( draggedRef.current && onPositionChange ) {
			// Snap to HALF-steps of the auto-layout grid (X_STEP / 2,
			// Y_STEP / 2). Whole-step snap kept dragged nodes aligned
			// with auto-placed neighbors but didn't leave room to
			// "nudge between columns" — half-steps give that finer
			// control while still landing on a predictable lattice
			// (every other slot is a real auto-layout slot). Anchored
			// at X_PAD / Y_PAD so n=0 still matches the algorithm.
			// Math.max(0, ...) so a drag past the top/left edge
			// doesn't produce negative grid indices.
			const halfX = X_STEP / 2;
			const halfY = Y_STEP / 2;
			const xi = Math.max(
				0,
				Math.round( ( drag.currentPos.x - X_PAD ) / halfX )
			);
			const yi = Math.max(
				0,
				Math.round( ( drag.currentPos.y - Y_PAD ) / halfY )
			);
			onPositionChange( drag.nodeId, {
				x: X_PAD + xi * halfX,
				y: Y_PAD + yi * halfY,
			} );
		}
		setDrag( null );
		// Reset the click-suppress flag on the next microtask so the
		// click handler that fires immediately after pointerup can
		// still see the "we just dragged" signal.
		const wasDragged = draggedRef.current;
		setTimeout( () => {
			draggedRef.current = wasDragged ? true : false;
		}, 0 );
	};

	return (
		<svg
			className="topology-canvas-svg"
			viewBox={ viewBox }
			preserveAspectRatio="xMidYMid meet"
			onClick={ onDeselect }
		>
			<defs>
				<marker
					id="topology-arrow"
					viewBox="0 0 10 10"
					refX="9"
					refY="5"
					markerWidth="6"
					markerHeight="6"
					orient="auto"
				>
					<path
						d="M0,0 L10,5 L0,10 z"
						className="topology-arrow-head"
					/>
				</marker>
				<marker
					id="topology-arrow-active"
					viewBox="0 0 10 10"
					refX="9"
					refY="5"
					markerWidth="6"
					markerHeight="6"
					orient="auto"
				>
					<path
						d="M0,0 L10,5 L0,10 z"
						className="topology-arrow-head topology-arrow-head--active"
					/>
				</marker>
			</defs>

			<g className="topology-edges">
				{ edges.map( ( e, i ) => {
					const a = nodeById.get( e.from );
					const b = nodeById.get( e.to );
					if ( ! a || ! b ) {
						return null;
					}
					const hoverTouches =
						hoveredId === e.from || hoveredId === e.to;
					const selectTouches =
						! hoveredId &&
						( selectedId === e.from || selectedId === e.to );
					// Hover applies the bold highlight + dims everything
					// else. Selection applies the same bold highlight to
					// the selected node's edges but DOESN'T dim — the rest
					// of the graph stays at full intensity so the user can
					// still see the surrounding context.
					const touches = hoverTouches || selectTouches;
					const dimmed = hoveredId && ! hoverTouches;
					return (
						<path
							key={ `edge-${ i }-${ e.from }-${ e.to }` }
							className={ `topology-edge topology-edge--active${
								touches ? ' is-touched' : ''
							}${ dimmed ? ' is-dimmed' : '' }` }
							d={ edgePath( a, b ) }
							markerEnd="url(#topology-arrow-active)"
							style={ { animationDelay: `${ 200 + i * 80 }ms` } }
						/>
					);
				} ) }
			</g>

			<g className="topology-nodes">
				{ displayNodes.map( ( n, i ) => {
					const isSelected = n.id === selectedId;
					const isHovered = n.id === hoveredId;
					const isFaded = hoveredId && ! isHovered;
					const isDragging = drag && drag.nodeId === n.id;
					return (
						<g
							key={ n.id }
							className={ `topology-node${
								isSelected ? ' is-selected' : ''
							}${ isHovered ? ' is-hovered' : '' }${
								isFaded ? ' is-faded' : ''
							}${ isDragging ? ' is-dragging' : '' }` }
							transform={ `translate(${ n.position.x },${ n.position.y })` }
							style={ { animationDelay: `${ i * 50 }ms` } }
							onClick={ ( ev ) => {
								ev.stopPropagation();
								// Suppress selection after a real drag —
								// pointer-up sets draggedRef to true in
								// that case. The flag is reset on the
								// next microtask so subsequent clicks
								// (without intervening drags) work.
								if ( draggedRef.current ) {
									draggedRef.current = false;
									return;
								}
								if ( onSelect ) {
									onSelect( n.id );
								}
							} }
							onPointerDown={ ( ev ) => beginDrag( ev, n ) }
							onPointerMove={ updateDrag }
							onPointerUp={ endDrag }
							onPointerCancel={ endDrag }
							onMouseEnter={ () => setHovered( n.id ) }
							onMouseLeave={ () => setHovered( null ) }
						>
							<rect
								className="topology-node__shadow"
								x={ 3 }
								y={ 3 }
								width={ NODE_W }
								height={ NODE_H }
							/>
							<rect
								className="topology-node__bg"
								width={ NODE_W }
								height={ NODE_H }
							/>
							<line
								className="topology-node__divider"
								x1={ 0 }
								y1={ 22 }
								x2={ NODE_W }
								y2={ 22 }
							/>
							<text
								className="topology-node__type"
								x={ 11 }
								y={ 15 }
							>
								{ inferType( n.id ) }
							</text>
							<circle
								className="topology-node__led"
								cx={ NODE_W - 12 }
								cy={ 13 }
								r={ 3.5 }
							/>
							<text
								className="topology-node__id"
								x={ 11 }
								y={ 44 }
							>
								{ n.id }
							</text>
							<text
								className="topology-node__meta"
								x={ 11 }
								y={ 60 }
							>
								live
							</text>
							<text
								className="topology-node__counter"
								x={ NODE_W - 11 }
								y={ 76 }
								textAnchor="end"
							>
								{ compactCount( n.count ) }
							</text>
							<circle
								className="topology-port topology-port--in"
								cx={ 0 }
								cy={ NODE_H / 2 }
								r={ PORT_R }
							/>
							<circle
								className="topology-port topology-port--out"
								cx={ NODE_W }
								cy={ NODE_H / 2 }
								r={ PORT_R }
							/>
						</g>
					);
				} ) }
			</g>
		</svg>
	);
}
