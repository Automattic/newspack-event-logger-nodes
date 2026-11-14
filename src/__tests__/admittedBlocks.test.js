/* eslint-env jest */
/**
 * The views a page bundle imports name exactly the shared blocks
 * `Admin\Compositions::ADMITS` admits, in its order, read from the PHP.
 */

import fs from 'fs';
import path from 'path';
import { ADMITTED_VIEWS } from '../admittedBlocks';

/** `ADMITS` as the PHP declares it. */
const admits = () => {
	const php = fs.readFileSync(
		path.resolve(
			__dirname,
			'../../includes/admin/class-compositions.php'
		),
		'utf8'
	);
	const list = php.match( /const ADMITS = \[([^\]]*)\]/ );
	if ( ! list ) {
		throw new Error( 'Compositions declares no ADMITS list' );
	}
	return [ ...list[ 1 ].matchAll( /'([^']+)'/g ) ].map( ( m ) => m[ 1 ] );
};

test( 'the views name the blocks the PHP admits', () => {
	expect( Object.keys( ADMITTED_VIEWS ) ).toEqual( admits() );
} );

test( 'neither admits the table', () => {
	expect( admits() ).not.toContain( 'newspack-nodes/table' );
} );
