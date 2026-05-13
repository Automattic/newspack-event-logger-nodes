/**
 * Compute x/y positions for a parsed {nodes, edges} graph.
 *
 * Left-to-right column layout: a node's column index is one more than
 * the deepest incoming-edge predecessor (sources land in column 0).
 *
 * Three-pass row assignment minimizes the two kinds of visual
 * ambiguity that wreck a layered graph drawing:
 *
 *   1. Edge crossings — sort each column's nodes by barycenter (avg
 *      predecessor row) so successor positions roughly follow the
 *      sources that feed them.
 *   2. False adjacency — snap each producer's row to its first
 *      target's row by walking right-to-left after barycenter. This
 *      shifts producers DOWN past their siblings' fan-out rows, so
 *      a producer never sits beside a target it doesn't write to.
 *   3. Conflicts — if the snap collapses two same-column nodes onto
 *      one row, resolve by walking that column top-down and bumping
 *      duplicates to the next free row.
 *
 * Returns a new array of node objects extended with `position: {x, y}`
 * — does not mutate the input. Edges are returned unchanged.
 */

const X_STEP = 240;
const Y_STEP = 110;
const X_PAD = 60;
const Y_PAD = 80;

export function autoLayout( parsed ) {
	const nodes = parsed?.nodes ?? [];
	const edges = parsed?.edges ?? [];

	const incoming = new Map();
	const outgoing = new Map();
	for ( const e of edges ) {
		const inList = incoming.get( e.to ) || [];
		inList.push( e.from );
		incoming.set( e.to, inList );
		const outList = outgoing.get( e.from ) || [];
		outList.push( e.to );
		outgoing.set( e.from, outList );
	}

	// Depth assignment via DFS with cycle break.
	const depth = new Map();
	const visit = ( name, stack = new Set() ) => {
		if ( depth.has( name ) ) {
			return depth.get( name );
		}
		if ( stack.has( name ) ) {
			depth.set( name, 0 );
			return 0;
		}
		stack.add( name );
		const preds = incoming.get( name ) || [];
		const d =
			preds.length === 0
				? 0
				: 1 + Math.max( 0, ...preds.map( ( p ) => visit( p, stack ) ) );
		stack.delete( name );
		depth.set( name, d );
		return d;
	};
	nodes.forEach( ( n ) => visit( n.id ) );

	// Bucket nodes by depth.
	const byDepth = new Map();
	for ( const n of nodes ) {
		const d = depth.get( n.id ) ?? 0;
		if ( ! byDepth.has( d ) ) {
			byDepth.set( d, [] );
		}
		byDepth.get( d ).push( n );
	}
	const depthsAscending = Array.from( byDepth.keys() ).sort(
		( a, b ) => a - b
	);

	// Pass 1: barycenter row assignment (left-to-right).
	const row = new Map();
	for ( const d of depthsAscending ) {
		const columnNodes = byDepth.get( d );
		if ( d === 0 ) {
			columnNodes.forEach( ( n, i ) => row.set( n.id, i ) );
			continue;
		}
		const scored = columnNodes.map( ( n ) => {
			const preds = incoming.get( n.id ) || [];
			const predRows = preds
				.map( ( p ) => row.get( p ) )
				.filter( ( r ) => r !== undefined );
			const bary = predRows.length
				? predRows.reduce( ( a, b ) => a + b, 0 ) / predRows.length
				: Number.POSITIVE_INFINITY;
			return { node: n, bary };
		} );
		scored.sort( ( a, b ) => {
			if ( a.bary !== b.bary ) {
				return a.bary - b.bary;
			}
			return a.node.id.localeCompare( b.node.id );
		} );
		scored.forEach( ( s, i ) => row.set( s.node.id, i ) );
	}

	// Pass 2: snap each producer's row to the minimum row of its
	// targets, right-to-left. This shifts producers DOWN past their
	// own siblings' targets so a producer never visually sits at the
	// row of a target it doesn't feed.
	for ( let i = depthsAscending.length - 1; i >= 0; i-- ) {
		const d = depthsAscending[ i ];
		for ( const n of byDepth.get( d ) ) {
			const targets = outgoing.get( n.id ) || [];
			const targetRows = targets
				.map( ( t ) => row.get( t ) )
				.filter( ( r ) => r !== undefined );
			if ( targetRows.length ) {
				row.set( n.id, Math.min( ...targetRows ) );
			}
		}
	}

	// Pass 3: deconflict — within each column, two nodes may now share
	// a row (e.g. two producers whose first targets are both at row 0).
	// Walk each column in row order and bump duplicates down to the
	// next free row, ties broken alphabetically for stable layout.
	for ( const d of depthsAscending ) {
		const columnNodes = byDepth.get( d ).slice();
		columnNodes.sort( ( a, b ) => {
			const ra = row.get( a.id );
			const rb = row.get( b.id );
			if ( ra !== rb ) {
				return ra - rb;
			}
			return a.id.localeCompare( b.id );
		} );
		const seen = new Set();
		for ( const n of columnNodes ) {
			let r = row.get( n.id );
			while ( seen.has( r ) ) {
				r++;
			}
			row.set( n.id, r );
			seen.add( r );
		}
	}

	const positioned = nodes.map( ( n ) => {
		const d = depth.get( n.id ) ?? 0;
		const r = row.get( n.id ) ?? 0;
		return {
			...n,
			position: { x: X_PAD + d * X_STEP, y: Y_PAD + r * Y_STEP },
		};
	} );

	return { nodes: positioned, edges };
}
