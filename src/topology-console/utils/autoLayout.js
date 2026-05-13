/**
 * Compute x/y positions for a parsed {nodes, edges} graph.
 *
 * Left-to-right column layout: a node's column index is one more than
 * the deepest incoming-edge predecessor (sources land in column 0).
 *
 * Within a column, nodes are ordered by the barycenter heuristic:
 * each node's row is informed by the average row of its predecessors
 * in the previous column. This minimizes edge crossings — a target
 * with one source at row N ends up near row N, not stranded in
 * alphabetical-arrival order. Mirrors the standard layered-digraph
 * (Sugiyama-style) one-sided crossing-reduction pass.
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
	for ( const e of edges ) {
		const list = incoming.get( e.to ) || [];
		list.push( e.from );
		incoming.set( e.to, list );
	}

	// Depth assignment via DFS with cycle break — a node's depth is one
	// more than the max of its predecessors; cycles are clamped to depth 0
	// at the back-edge to keep layout deterministic.
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

	// Bucket nodes by column.
	const byDepth = new Map();
	for ( const n of nodes ) {
		const d = depth.get( n.id ) ?? 0;
		if ( ! byDepth.has( d ) ) {
			byDepth.set( d, [] );
		}
		byDepth.get( d ).push( n );
	}

	// Row assignment, column by column. Sources (depth 0) keep their
	// arrival/alphabetical order from parseLsOutput. Subsequent columns
	// sort by the average row of each node's predecessors; ties break
	// alphabetically so the layout is stable across refreshes.
	const row = new Map();
	const sortedDepths = Array.from( byDepth.keys() ).sort( ( a, b ) => a - b );
	for ( const d of sortedDepths ) {
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
