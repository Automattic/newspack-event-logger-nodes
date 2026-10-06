/* global KeyboardEvent */
/**
 * Tests for TokenInput — a row of removable tokens above the shared
 * CommitInput, and the two key rules both token fields share: Backspace in an
 * empty box removes the last token, and only when a token exists; Enter on a
 * box holding only spaces is held, and clears it, which CommitInput owns.
 */

import * as React from 'react';
import TokenInput from '../TokenInput';
import { renderComponent, act } from '../../test-helpers/renderHook';

/**
 * Set a controlled input's value through the native setter, so React sees it.
 *
 * @param {HTMLInputElement} input The input.
 * @param {string}           value Its new value.
 */
function type( input, value ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

/**
 * Press a key on an element.
 *
 * @param {Element} target The element.
 * @param {string}  key    KeyboardEvent key name.
 * @return {KeyboardEvent} The dispatched event.
 */
function press( target, key ) {
	const event = new KeyboardEvent( 'keydown', {
		key,
		bubbles: true,
		cancelable: true,
	} );
	act( () => {
		target.dispatchEvent( event );
	} );
	return event;
}

/**
 * Mount the input over the given tokens, recording every removal.
 *
 * @param {string[]} tokens The token labels.
 * @param {Object}   props  CommitInput props to pass through.
 * @return {{container: Element, removed: number[], committed: string[], input: () => HTMLInputElement, unmount: Function}} The mount.
 */
function mountInput( tokens, props = {} ) {
	const removed = [];
	const committed = [];
	const mount = renderComponent(
		React.createElement( TokenInput, {
			tokens,
			onRemove: ( index ) => removed.push( index ),
			rowClassName: 'kiwi-row',
			value: '',
			onCommit: ( draft ) => committed.push( draft ),
			placeholder: 'Name a kiwi',
			...props,
		} )
	);
	return {
		...mount,
		removed,
		committed,
		input: () => mount.container.querySelector( 'input' ),
	};
}

describe( 'TokenInput', () => {
	it( 'draws one token per label in its row, above the box', () => {
		const field = mountInput( [ '/kea-31', '/weka-77' ] );
		const row = field.container.querySelector( '.kiwi-row' );

		expect(
			Array.from( row.querySelectorAll( '.event-logger-tag-text' ) ).map(
				( token ) => token.textContent
			)
		).toEqual( [ '/kea-31', '/weka-77' ] );
		expect(
			row.compareDocumentPosition( field.input() ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		field.unmount();
	} );

	it( 'draws no row while it holds no token', () => {
		const field = mountInput( [] );
		expect( field.container.querySelector( '.kiwi-row' ) ).toBeNull();
		field.unmount();
	} );

	it( "sets its tokens in the caller's own row when given no row class", () => {
		const field = mountInput( [ '/kea-31', '/weka-77' ], {
			rowClassName: undefined,
		} );

		expect(
			Array.from( field.container.children ).map(
				( child ) => child.className || child.tagName
			)
		).toEqual( [
			'event-logger-tag-token newspack-nodes-badge',
			'event-logger-tag-token newspack-nodes-badge',
			'INPUT',
		] );
		field.unmount();
	} );

	it( "removes a token by index through its ×, named for the token's label", () => {
		const field = mountInput( [ '/kea-31', '/weka-77', '/tui-12' ] );
		act( () =>
			field.container
				.querySelector( 'button[aria-label="Remove /weka-77"]' )
				.click()
		);

		expect( field.removed ).toEqual( [ 1 ] );
		field.unmount();
	} );

	it( 'hands its CommitInput props to the box', () => {
		const field = mountInput( [ '/kea-31' ] );
		type( field.input(), ' /pukeko-5 ' );
		press( field.input(), 'Enter' );

		expect( field.input().placeholder ).toBe( 'Name a kiwi' );
		expect( field.committed ).toEqual( [ '/pukeko-5' ] );
		field.unmount();
	} );

	it( 'removes the last token on Backspace in an empty box, holding the key', () => {
		const field = mountInput( [ '/kea-31', '/weka-77', '/tui-12' ] );
		const event = press( field.input(), 'Backspace' );

		expect( field.removed ).toEqual( [ 2 ] );
		expect( event.defaultPrevented ).toBe( true );
		field.unmount();
	} );

	it( 'leaves the tokens alone on Backspace while text is typed', () => {
		const field = mountInput( [ '/kea-31', '/weka-77' ] );
		type( field.input(), '/ru' );
		const event = press( field.input(), 'Backspace' );

		expect( field.removed ).toEqual( [] );
		expect( event.defaultPrevented ).toBe( false );
		field.unmount();
	} );

	it( 'swallows Enter on a box holding only spaces, clearing it in place', () => {
		const field = mountInput( [ '/kea-31' ] );
		act( () => field.input().focus() );
		type( field.input(), '   ' );
		const event = press( field.input(), 'Enter' );

		expect( event.defaultPrevented ).toBe( true );
		expect( field.committed ).toEqual( [] );
		expect( field.input().value ).toBe( '' );
		expect( field.container.ownerDocument.activeElement ).toBe(
			field.input()
		);
		field.unmount();
	} );

	it( 'lets Enter through on an empty box', () => {
		const field = mountInput( [ '/kea-31' ] );
		const event = press( field.input(), 'Enter' );

		expect( event.defaultPrevented ).toBe( false );
		expect( field.committed ).toEqual( [] );
		field.unmount();
	} );

	it( 'lets Backspace through in an empty box holding no token', () => {
		const field = mountInput( [] );
		const event = press( field.input(), 'Backspace' );

		expect( field.removed ).toEqual( [] );
		expect( event.defaultPrevented ).toBe( false );
		field.unmount();
	} );
} );
