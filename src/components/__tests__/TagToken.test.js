/**
 * Tests for TagToken — the removable token the rule editor's tag input and
 * the bucket filter both draw.
 */

import * as React from 'react';
import TagToken from '../TagToken';
import { renderComponent, act } from '../../test-helpers/renderHook';

const mountToken = ( props ) =>
	renderComponent(
		React.createElement( TagToken, {
			label: '/kakapo-4471',
			removeLabel: 'Drop kakapo',
			onRemove: jest.fn(),
			...props,
		} )
	);

describe( 'TagToken', () => {
	it( 'draws the label on the shared badge', () => {
		const { container, unmount } = mountToken();
		const token = container.firstElementChild;
		expect( [ ...token.classList ] ).toEqual( [
			'event-logger-tag-token',
			'newspack-nodes-badge',
		] );
		expect(
			token.querySelector( '.event-logger-tag-text' ).textContent
		).toBe( '/kakapo-4471' );
		unmount();
	} );

	it( 'removes through a close-icon button carrying the remove label', () => {
		const onRemove = jest.fn();
		const { container, unmount } = mountToken( { onRemove } );
		const remove = container.querySelector( 'button' );
		expect( [ ...remove.classList ] ).toEqual(
			expect.arrayContaining( [
				'components-button',
				'event-logger-tag-remove',
			] )
		);
		expect( remove.querySelector( 'svg' ) ).not.toBeNull();
		expect( remove.getAttribute( 'aria-label' ) ).toBe( 'Drop kakapo' );
		act( () => remove.click() );
		expect( onRemove ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );
} );
