/* @jest-environment node */

/**
 * One modal: every dialog draws through the shared shell, never through
 * `@wordpress/components`' `Modal`, so its look, exit and focus handling live
 * in one place.
 */

import path from 'path';
import { wordPressModalImports } from '@newspack-nodes/shared/test-utils/noWordPressModal';

it( 'imports no Modal from @wordpress/components anywhere in src', () => {
	expect( wordPressModalImports( path.resolve( __dirname, '..' ) ) ).toEqual(
		[]
	);
} );
