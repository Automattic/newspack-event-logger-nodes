/* global KeyboardEvent */
/**
 * Tests for TagInputField — the controlled multi-value tag input.
 *
 * The component reports every change through `onChange`; the tag list it
 * renders and the values it reports must not diverge.
 */
import * as React from 'react';
import TagInputField from '../TagInputField';
import { renderComponent, act } from '../../../test-helpers/renderHook';

/**
 * Set a controlled-input's value AND dispatch a React-friendly change.
 *
 * React tracks `value` on the native HTMLInputElement prototype and skips
 * events when the cached value matches — assigning the property directly
 * leaves React in stale state. The standard workaround: write via the
 * native descriptor setter, then dispatch the input event.
 *
 * @param {HTMLInputElement} input Input element to update.
 * @param {string}           value New value to assign.
 */
function setControlledValue( input, value ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	setter.call( input, value );
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

/**
 * Press a key on the tag input, the way the component listens for it.
 *
 * @param {HTMLInputElement} input Input element to key.
 * @param {string}           key   KeyboardEvent key name.
 */
function pressKey( input, key ) {
	act( () => {
		input.dispatchEvent(
			new KeyboardEvent( 'keydown', { key, bubbles: true } )
		);
	} );
}

describe( 'TagInputField', () => {
	it( 'renders existing values as tags', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/alpha', '/beta' ],
			} )
		);

		const tags = container.querySelectorAll( '.event-logger-tag-text' );
		expect( Array.from( tags ).map( ( t ) => t.textContent ) ).toEqual( [
			'/alpha',
			'/beta',
		] );
		unmount();
	} );

	it( 'adds a value when Enter is pressed in the text input', () => {
		const seen = [];
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [],
				onChange: ( v ) => seen.push( v ),
			} )
		);

		const input = container.querySelector( 'input[type="text"]' );
		act( () => setControlledValue( input, '/gamma' ) );
		pressKey( input, 'Enter' );

		expect(
			Array.from(
				container.querySelectorAll( '.event-logger-tag-text' )
			).map( ( t ) => t.textContent )
		).toEqual( [ '/gamma' ] );
		expect( seen[ seen.length - 1 ] ).toEqual( [ '/gamma' ] );
		unmount();
	} );

	it( 'adds a value on blur, so a typed tag is not lost', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, { initialValues: [] } )
		);

		const input = container.querySelector( 'input[type="text"]' );
		act( () => setControlledValue( input, '/delta' ) );
		// React's onBlur listens for the bubbling focusout, not native blur.
		act( () => {
			input.dispatchEvent( new Event( 'focusout', { bubbles: true } ) );
		} );

		expect(
			container.querySelector( '.event-logger-tag-text' ).textContent
		).toBe( '/delta' );
		unmount();
	} );

	it( 'refuses a blank value', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, { initialValues: [] } )
		);

		const input = container.querySelector( 'input[type="text"]' );
		act( () => setControlledValue( input, '   ' ) );
		pressKey( input, 'Enter' );

		expect(
			container.querySelectorAll( '.event-logger-tag-text' )
		).toHaveLength( 0 );
		unmount();
	} );

	it( 'does not add duplicate values', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/alpha' ],
			} )
		);

		const input = container.querySelector( 'input[type="text"]' );
		act( () => setControlledValue( input, '/alpha' ) );
		pressKey( input, 'Enter' );

		expect(
			container.querySelectorAll( '.event-logger-tag-text' )
		).toHaveLength( 1 );
		unmount();
	} );

	it( 'removes the last tag on Backspace when the input is empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/alpha', '/beta' ],
			} )
		);

		pressKey(
			container.querySelector( 'input[type="text"]' ),
			'Backspace'
		);

		expect(
			Array.from(
				container.querySelectorAll( '.event-logger-tag-text' )
			).map( ( t ) => t.textContent )
		).toEqual( [ '/alpha' ] );
		unmount();
	} );

	it( 'leaves the tags alone on Backspace while the input has text', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/alpha', '/beta' ],
			} )
		);

		const input = container.querySelector( 'input[type="text"]' );
		act( () => setControlledValue( input, 'x' ) );
		pressKey( input, 'Backspace' );

		expect(
			container.querySelectorAll( '.event-logger-tag-text' )
		).toHaveLength( 2 );
		unmount();
	} );

	it( 'removes a clicked tag by index', () => {
		const seen = [];
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/alpha', '/beta', '/gamma' ],
				onChange: ( v ) => seen.push( v ),
			} )
		);

		act( () => {
			container
				.querySelectorAll( '.event-logger-tag-remove' )[ 1 ]
				.click();
		} );

		expect( seen[ seen.length - 1 ] ).toEqual( [ '/alpha', '/gamma' ] );
		unmount();
	} );

	it( 'does not report the initial render as an edit', () => {
		const seen = [];
		const { unmount } = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/alpha' ],
				onChange: ( v ) => seen.push( v ),
			} )
		);

		expect( seen ).toHaveLength( 0 );
		unmount();
	} );

	it( 'lays tags out horizontally only when asked', () => {
		const vertical = renderComponent(
			React.createElement( TagInputField, { initialValues: [ '/a' ] } )
		);
		expect(
			vertical.container.querySelector( '.event-logger-tag-container' )
				.className
		).toContain( 'vertical' );
		vertical.unmount();

		const horizontal = renderComponent(
			React.createElement( TagInputField, {
				initialValues: [ '/a' ],
				horizontal: true,
			} )
		);
		expect(
			horizontal.container.querySelector( '.event-logger-tag-container' )
				.className
		).toContain( 'horizontal' );
		horizontal.unmount();
	} );
} );
