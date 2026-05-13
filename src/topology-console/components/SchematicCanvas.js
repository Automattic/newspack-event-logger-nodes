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

import { useMemo, useState } from '@wordpress/element';

import { autoLayout } from '../utils/autoLayout';
import { inferType } from '../utils/inferType';

const NODE_W = 196;
const NODE_H = 84;
const PORT_R = 4.5;

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
} ) {
	const { nodes, edges } = useMemo( () => autoLayout( parsed ), [ parsed ] );
	const nodeById = useMemo( () => {
		const map = new Map();
		nodes.forEach( ( n ) => map.set( n.id, n ) );
		return map;
	}, [ nodes ] );
	const viewBox = useMemo( () => viewBoxFor( nodes ), [ nodes ] );

	// Hovered node id — drives edge highlighting. When the user is
	// pointing at a node, only its inbound + outbound edges keep the
	// active style; everything else dims so the user can read each
	// node's flow neighborhood at a glance. Same disambiguation trick
	// the live D3 visualizer uses at https://tucsonweekly.com.
	const [ hoveredId, setHoveredId ] = useState( null );

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
					const touches = hoveredId === e.from || hoveredId === e.to;
					const dimmed = hoveredId && ! touches;
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
				{ nodes.map( ( n, i ) => {
					const isSelected = n.id === selectedId;
					const isHovered = n.id === hoveredId;
					const isFaded = hoveredId && ! isHovered;
					return (
						<g
							key={ n.id }
							className={ `topology-node${
								isSelected ? ' is-selected' : ''
							}${ isHovered ? ' is-hovered' : '' }${
								isFaded ? ' is-faded' : ''
							}` }
							transform={ `translate(${ n.position.x },${ n.position.y })` }
							style={ { animationDelay: `${ i * 50 }ms` } }
							onClick={ ( ev ) => {
								ev.stopPropagation();
								if ( onSelect ) {
									onSelect( n.id );
								}
							} }
							onMouseEnter={ () => setHoveredId( n.id ) }
							onMouseLeave={ () => setHoveredId( null ) }
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
