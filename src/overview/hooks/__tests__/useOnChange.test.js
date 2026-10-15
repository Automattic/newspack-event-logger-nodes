/**
 * Tests for useOnChange — an effect that answers a change of its deps and
 * never the mount that first set them.
 */

import { renderHook, cleanupMounts } from '../../../test-helpers/renderHook';
import { useOnChange } from '../useOnChange';

afterEach( () => cleanupMounts() );

describe( 'useOnChange', () => {
	it( 'runs nothing on mount', () => {
		const effect = jest.fn();

		renderHook( ( { dep } ) => useOnChange( effect, [ dep ] ), {
			initialProps: { dep: 'kea' },
		} );

		expect( effect ).not.toHaveBeenCalled();
	} );

	it( 'runs once per change, never on a render that changes nothing', () => {
		const seen = [];
		const { rerender } = renderHook(
			( { dep } ) => useOnChange( () => seen.push( dep ), [ dep ] ),
			{ initialProps: { dep: 'kea' } }
		);

		rerender( { dep: 'kea' } );
		rerender( { dep: 'tui' } );
		rerender( { dep: 'tui' } );
		rerender( { dep: 'weka' } );

		expect( seen ).toEqual( [ 'tui', 'weka' ] );
	} );
} );
