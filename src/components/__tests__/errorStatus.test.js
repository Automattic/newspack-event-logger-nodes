/**
 * Tests for errorStatus — the four terminal markers, and the PHP constant it
 * duplicates. The parity test is the point: `A` and then `I` each shipped
 * writable by the node and unreadable by the dashboards, because the JS lists
 * were hand-kept copies nobody re-read.
 */

import fs from 'fs';
import path from 'path';
import { ERROR_STATUSES, errorStatus, errorSummary } from '../errorStatus';

const PLUGIN_ROOT = path.join( __dirname, '..', '..', '..' );

/**
 * The codes a PHP `const NAME = [ 'X', … ];` list declares.
 *
 * @param {string} file Path under the plugin root.
 * @param {string} name The constant's name.
 * @return {string[]} Its codes, sorted.
 */
function phpCodes( file, name ) {
	const php = fs.readFileSync( path.join( PLUGIN_ROOT, file ), 'utf8' );
	const match = php.match(
		new RegExp( `const ${ name } = \\[([^\\]]*)\\];` )
	);
	expect( match ).toBeTruthy();
	return match[ 1 ]
		.split( ',' )
		.map( ( part ) => part.trim().replace( /^'|'$/g, '' ) )
		.filter( Boolean )
		.sort();
}

describe( 'errorStatus', () => {
	it( 'covers every code in Request_Builder_Node::ERROR_STATUSES', () => {
		expect(
			phpCodes(
				'includes/class-request-builder-node.php',
				'ERROR_STATUSES'
			)
		).toEqual( Object.keys( ERROR_STATUSES ).sort() );
	} );

	it( 'carries only what a view reads: label and tone', () => {
		for ( const entry of Object.values( ERROR_STATUSES ) ) {
			expect( Object.keys( entry ).sort() ).toEqual( [
				'label',
				'tone',
			] );
		}
	} );

	it( 'labels an incomplete request and tones it as a warning', () => {
		expect( errorStatus( 'I' ).label ).toContain( 'Incomplete' );
		expect( errorStatus( 'I' ).tone ).toBe( 'is-warning' );
	} );

	it( 'returns null for a clean or unknown code', () => {
		expect( errorStatus( '-' ) ).toBeNull();
		expect( errorStatus( '' ) ).toBeNull();
		expect( errorStatus( undefined ) ).toBeNull();
		expect( errorStatus( 'toString' ) ).toBeNull();
	} );
} );

describe( 'errorSummary', () => {
	// @longform The case list Ask_Assembler::error_summary() also reads, so the
	// header and the brief cannot summarize one list two ways.
	const cases = JSON.parse(
		fs.readFileSync(
			path.join( PLUGIN_ROOT, 'tests', 'fixtures', 'error-summary.json' ),
			'utf8'
		)
	);

	it.each( cases.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		expect( errorSummary( c.requests ) ).toEqual( c.expected );
	} );
} );
