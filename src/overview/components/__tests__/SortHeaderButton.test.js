/**
 * The one sortable column header both overview tables draw: a label, and on
 * the sorted column a caret that cannot wrap away from it.
 */

import * as React from 'react';
import SortHeaderButton from '../SortHeaderButton';
import { renderComponent, act } from '../../../test-helpers/renderHook';

function mount( props ) {
	const onSort = jest.fn();
	const view = renderComponent(
		React.createElement( SortHeaderButton, {
			field: 'okapi_ms',
			label: 'Okapis',
			onSort,
			...props,
		} )
	);
	return {
		...view,
		onSort,
		button: view.container.querySelector( 'button' ),
	};
}

describe( 'SortHeaderButton', () => {
	it( 'keeps the caret beside its label, with no space to break at', () => {
		const { button, unmount } = mount( { dir: 'desc' } );

		const caret = button.querySelector( '.event-logger-table__sort-caret' );
		expect( caret.textContent ).toBe( '▼' );
		expect( button.textContent ).toBe( 'Okapis▼' );
		unmount();
	} );

	it( 'points the caret up for an ascending sort', () => {
		const { button, unmount } = mount( { dir: 'asc' } );

		expect(
			button.querySelector( '.event-logger-table__sort-caret' )
				.textContent
		).toBe( '▲' );
		unmount();
	} );

	it( 'draws no caret on a column that holds no sort', () => {
		const { button, unmount } = mount( { dir: null } );

		expect( button.textContent ).toBe( 'Okapis' );
		expect(
			button.querySelector( '.event-logger-table__sort-caret' )
		).toBeNull();
		unmount();
	} );

	it( 'carries the canonical header roles and its alignment modifier', () => {
		const { button, unmount } = mount( { variant: 'numeric' } );

		for ( const className of [
			'newspack-nodes-sortable-header-button',
			'event-logger-table__header-btn',
			'newspack-nodes-table__cell',
			'event-logger-table__header-btn--numeric',
		] ) {
			expect( button.classList.contains( className ) ).toBe( true );
		}
		expect( button.getAttribute( 'data-field' ) ).toBe( 'okapi_ms' );
		unmount();
	} );

	it( 'reports its field when clicked', () => {
		const { button, onSort, unmount } = mount();

		act( () => button.click() );

		expect( onSort ).toHaveBeenCalledWith( 'okapi_ms' );
		unmount();
	} );
} );
