/* global KeyboardEvent */
/**
 * Tests for TagInputField — multi-value tag input with three modes:
 *   - standard (text input + tags)
 *   - hook selector (modal-driven)
 *   - custom event selector (modal-driven)
 *
 * The two selector children are mocked so we can drive their onSelect
 * callbacks directly to assert the parent updates its values.
 */

jest.mock( '../HookSelectorModal', () => ( {
	__esModule: true,
	default: ( { isOpen } ) =>
		isOpen ? 'HOOK_MODAL_OPEN' : 'HOOK_MODAL_CLOSED',
} ) );
jest.mock( '../CustomEventSelectorModal', () => ( {
	__esModule: true,
	default: ( { isOpen } ) =>
		isOpen ? 'CUSTOM_MODAL_OPEN' : 'CUSTOM_MODAL_CLOSED',
} ) );

import * as React from 'react';
import TagInputField from '../TagInputField';
import {
	renderComponent,
	act,
} from '../../../shared/hooks/__tests__/renderHook';

/**
 * Set a controlled-input's value AND dispatch a React-friendly change.
 *
 * React tracks `value` on the native HTMLInputElement prototype and skips
 * events when the cached value matches — assigning the property directly
 * leaves React in stale state. The standard workaround: write via the
 * native descriptor setter, then dispatch the input event.
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

function setUpHiddenInput( name ) {
	const input = document.createElement( 'input' );
	input.id = `${ name }_json`;
	input.type = 'hidden';
	document.body.appendChild( input );
	return input;
}

function setUpContainer( name ) {
	const div = document.createElement( 'div' );
	div.id = `event-logger-${ name }`;
	document.body.appendChild( div );
	return div;
}

describe( 'TagInputField', () => {
	afterEach( () => {
		document
			.querySelectorAll( 'input, div[id^="event-logger-"]' )
			.forEach( ( n ) => n.remove() );
	} );

	it( 'renders existing values as tags', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'urls',
				initialValues: [ '/foo', '/bar' ],
			} )
		);
		const text = container.textContent;
		expect( text ).toContain( '/foo' );
		expect( text ).toContain( '/bar' );
		unmount();
	} );

	it( 'writes the JSON-encoded values to the hidden input on mount', () => {
		const hidden = setUpHiddenInput( 'urls' );
		const { unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'urls',
				initialValues: [ '/foo' ],
			} )
		);
		expect( hidden.value ).toBe( '["/foo"]' );
		unmount();
	} );

	it( 'adds a value when Enter is pressed in the text input', () => {
		const hidden = setUpHiddenInput( 'urls' );
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'urls',
				initialValues: [],
			} )
		);
		const input = container.querySelector( 'input[type="text"]' );
		act( () => {
			setControlledValue( input, '/baz' );
		} );
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
			);
		} );
		expect( hidden.value ).toBe( '["/baz"]' );
		unmount();
	} );

	it( 'removes the last tag on Backspace when the input is empty', () => {
		const hidden = setUpHiddenInput( 'urls' );
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'urls',
				initialValues: [ '/a', '/b' ],
			} )
		);
		const input = container.querySelector( 'input[type="text"]' );
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Backspace',
					bubbles: true,
				} )
			);
		} );
		expect( hidden.value ).toBe( '["/a"]' );
		unmount();
	} );

	it( 'does not add duplicate values', () => {
		const hidden = setUpHiddenInput( 'urls' );
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'urls',
				initialValues: [ '/foo' ],
			} )
		);
		const input = container.querySelector( 'input[type="text"]' );
		act( () => {
			setControlledValue( input, '/foo' );
		} );
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
			);
		} );
		expect( hidden.value ).toBe( '["/foo"]' );
		unmount();
	} );

	it( 'ignores legacy event-logger-reset DOM events (per-field reset is now the toggle module)', () => {
		// The old PHP reset button dispatched `event-logger-reset` to reset the
		// field to baked-in defaults. That mechanism was replaced by the shared
		// admin-field-reset toggle (clears + marks for server-side delete), so
		// TagInputField no longer listens for the event: its hidden value stays
		// at the current values regardless of the (now-orphaned) event.
		const hidden = setUpHiddenInput( 'urls' );
		const containerDiv = setUpContainer( 'urls' );
		const { unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'urls',
				initialValues: [ '/old' ],
				defaultValues: [ '/default1', '/default2' ],
			} )
		);
		act( () => {
			containerDiv.dispatchEvent(
				new CustomEvent( 'event-logger-reset', {
					detail: {
						field: 'urls',
						defaultValues: [ '/default1', '/default2' ],
					},
				} )
			);
		} );
		expect( hidden.value ).toBe( '["/old"]' );
		unmount();
	} );

	it( 'renders hook-selector mode with a count and the modal closed initially', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'hooks',
				initialValues: [ 'init', 'wp_loaded' ],
				showHookSelector: true,
			} )
		);
		expect( container.textContent ).toContain( '2 hooks selected' );
		expect( container.textContent ).toContain( 'HOOK_MODAL_CLOSED' );
		unmount();
	} );

	it( 'pluralizes correctly for one hook', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'hooks',
				initialValues: [ 'init' ],
				showHookSelector: true,
			} )
		);
		expect( container.textContent ).toContain( '1 hook selected' );
		expect( container.textContent ).not.toContain( '1 hooks' );
		unmount();
	} );

	it( 'renders custom-event-selector mode', () => {
		const { container, unmount } = renderComponent(
			React.createElement( TagInputField, {
				fieldName: 'events',
				initialValues: [],
				showCustomSelector: true,
			} )
		);
		expect( container.textContent ).toContain( '0 events selected' );
		expect( container.textContent ).toContain( 'CUSTOM_MODAL_CLOSED' );
		unmount();
	} );
} );
