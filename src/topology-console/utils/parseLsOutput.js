/**
 * Parse the `ls -al` text body emitted by Newspack_Nodes CommandInterpreter.
 *
 * Input shape (string), one node per line:
 *
 *   COUNT NAME                 TARGET
 *    1334 firehose:consumer    -> firehose:tee
 *    1334 firehose:tee         -> request-builder, job-router
 *    1335 job-router           -> jobs:partition
 *
 * Returns { nodes: [{ id, count }], edges: [{ from, to }] }. Comma-separated
 * targets become multiple edges. The `_command_interpreter`, `_router`,
 * `_output`, and `_repl` scaffolding nodes are excluded — they're substrate
 * plumbing the user doesn't need to see on the canvas.
 */

const SCAFFOLDING = new Set( [
	'_command_interpreter',
	'_router',
	'_output',
	'_repl',
] );

export function parseLsOutput( text ) {
	const nodes = [];
	const edges = [];
	const lines = ( text || '' ).split( '\n' );
	for ( const raw of lines ) {
		const line = raw.trimEnd();
		if ( ! line || /^COUNT\b/.test( line ) ) {
			continue;
		}
		const match = line.match( /^\s*(\d+)\s+(\S+)(?:\s+->\s*(.*))?$/ );
		if ( ! match ) {
			continue;
		}
		const [ , countStr, name, targetsStr ] = match;
		if ( SCAFFOLDING.has( name ) ) {
			continue;
		}
		nodes.push( { id: name, count: parseInt( countStr, 10 ) } );
		if ( targetsStr ) {
			for ( const target of targetsStr
				.split( ',' )
				.map( ( t ) => t.trim() ) ) {
				if ( target && target !== '-' ) {
					edges.push( { from: name, to: target } );
				}
			}
		}
	}
	return { nodes, edges };
}
