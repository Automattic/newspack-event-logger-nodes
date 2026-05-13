/**
 * Compute x/y positions for a parsed {nodes, edges} graph.
 *
 * Left-to-right column layout. A node's column index is one more than
 * the deepest incoming-edge predecessor (sources land in column 0).
 * Within a column, nodes stack vertically in arrival order.
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

	const columnCounts = new Map();
	const positioned = nodes.map( ( n ) => {
		const d = depth.get( n.id ) ?? 0;
		const row = columnCounts.get( d ) || 0;
		columnCounts.set( d, row + 1 );
		return {
			...n,
			position: { x: X_PAD + d * X_STEP, y: Y_PAD + row * Y_STEP },
		};
	} );

	return { nodes: positioned, edges };
}
