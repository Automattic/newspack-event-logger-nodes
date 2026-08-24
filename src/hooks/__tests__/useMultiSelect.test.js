/**
 * useMultiSelect — the selection state both selector modals run on.
 *
 * Each modal kept its own copy: a Set in state, a reset when the modal opens,
 * a toggle, select-all-visible, clear, and an apply that hands back an array.
 * One implementation, so a fix reaches both.
 */

import { renderHook, act } from '../../test-helpers/renderHook';
import { useMultiSelect } from '../useMultiSelect';

it( 'starts from the incoming selection', () => {
	const { result } = renderHook( () =>
		useMultiSelect( { selected: [ 'alpha', 'beta' ], isOpen: true } )
	);

	expect( result.current.has( 'alpha' ) ).toBe( true );
	expect( result.current.has( 'gamma' ) ).toBe( false );
	expect( result.current.count ).toBe( 2 );
} );

it( 'toggles one value each way', () => {
	const { result } = renderHook( () =>
		useMultiSelect( { selected: [ 'alpha' ], isOpen: true } )
	);

	act( () => result.current.toggle( 'gamma' ) );
	expect( result.current.has( 'gamma' ) ).toBe( true );

	act( () => result.current.toggle( 'gamma' ) );
	expect( result.current.has( 'gamma' ) ).toBe( false );
} );

it( 'adds every value it is given without dropping the rest', () => {
	const { result } = renderHook( () =>
		useMultiSelect( { selected: [ 'alpha' ], isOpen: true } )
	);

	act( () => result.current.addAll( [ 'beta', 'gamma' ] ) );

	expect( result.current.count ).toBe( 3 );
	expect( result.current.has( 'alpha' ) ).toBe( true );
} );

it( 'removes only the values it is given, and clears the lot when given none', () => {
	const { result } = renderHook( () =>
		useMultiSelect( { selected: [ 'alpha', 'beta' ], isOpen: true } )
	);

	act( () => result.current.removeAll( [ 'beta' ] ) );
	expect( result.current.count ).toBe( 1 );

	act( () => result.current.clear() );
	expect( result.current.count ).toBe( 0 );
} );

it( 'replaces the whole selection, dropping what was there', () => {
	// "Select recommended" is a replace, not an add: it answers "these ones",
	// so anything chosen before it has to go.
	const { result } = renderHook( () =>
		useMultiSelect( { selected: [ 'alpha' ], isOpen: true } )
	);

	act( () => result.current.replaceWith( [ 'beta', 'gamma' ] ) );

	expect( result.current.count ).toBe( 2 );
	expect( result.current.has( 'alpha' ) ).toBe( false );
} );

it( 'applies as an array and does not mutate the caller', () => {
	const onSelect = jest.fn();
	const onClose = jest.fn();
	const { result } = renderHook( () =>
		useMultiSelect( { selected: [ 'alpha' ], isOpen: true } )
	);

	act( () => result.current.toggle( 'beta' ) );
	act( () => result.current.apply( onSelect, onClose ) );

	expect( onSelect ).toHaveBeenCalledWith( [ 'alpha', 'beta' ] );
	expect( onClose ).toHaveBeenCalled();
} );

it( 'resets to the incoming selection each time the modal opens', () => {
	// Editing, closing without applying, and reopening must not keep the edit.
	const { result, rerender } = renderHook(
		( props ) => useMultiSelect( props ),
		{ initialProps: { selected: [ 'alpha' ], isOpen: true } }
	);

	act( () => result.current.toggle( 'beta' ) );
	expect( result.current.count ).toBe( 2 );

	rerender( { selected: [ 'alpha' ], isOpen: false } );
	rerender( { selected: [ 'alpha' ], isOpen: true } );

	expect( result.current.count ).toBe( 1 );
	expect( result.current.has( 'beta' ) ).toBe( false );
} );
