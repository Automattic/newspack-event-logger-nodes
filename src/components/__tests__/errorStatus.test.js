/**
 * Tests for errorStatus — the four terminal markers, and the PHP constant it
 * duplicates. The parity test is the point: `A` and then `I` each shipped
 * writable by the node and unreadable by the dashboards, because the JS lists
 * were hand-kept copies nobody re-read.
 */

import fs from 'fs';
import path from 'path';
import { ERROR_STATUSES, errorStatus } from '../errorStatus';

describe( 'errorStatus', () => {
	it( 'covers every code in Request_Builder_Node::ERROR_STATUSES', () => {
		const php = fs.readFileSync(
			path.join(
				__dirname,
				'..',
				'..',
				'..',
				'includes',
				'class-request-builder-node.php'
			),
			'utf8'
		);
		const match = php.match( /const ERROR_STATUSES = \[([^\]]*)\];/ );
		expect( match ).toBeTruthy();
		const codes = match[ 1 ]
			.split( ',' )
			.map( ( part ) => part.trim().replace( /^'|'$/g, '' ) )
			.filter( Boolean );
		expect( codes.sort() ).toEqual( Object.keys( ERROR_STATUSES ).sort() );
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
