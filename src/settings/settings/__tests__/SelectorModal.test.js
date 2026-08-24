/**
 * SelectorModal tests — the chrome both picker modals render: the framed
 * dialog with its product classes, the header search box, the actions row, the
 * scrolling body, and the optional footer. What is being selected, and every
 * translator call, stays with each picker.
 */

import React from 'react';
import SelectorModal from '../SelectorModal';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { compileLocal, resolveCascade } from '../../../test-helpers/cascade';

/**
 * Every stylesheet a bundle rendering a picker emits, in import order:
 * RuleEditModal pulls the pickers (and with them the shared chrome) before its
 * own sheet, so this is the order that decides any tie.
 */
const PICKER_STYLESHEETS = [
	'settings/styles/selector-modal.scss',
	'settings/styles/hook-selector.scss',
	'settings/styles/custom-event-selector.scss',
	'rules/rule-edit-modal.scss',
].map( compileLocal );

function productRootClasses( element ) {
	return Array.from( element.classList ).filter(
		( className ) =>
			'topology-app' === className ||
			className.startsWith( 'theme-' ) ||
			className.startsWith( 'newspack-nodes-' )
	);
}

describe( 'SelectorModal', () => {
	const mounted = [];

	function mount( props ) {
		const r = renderComponent(
			React.createElement( SelectorModal, {
				title: 'Choose Instruments',
				className: 'event-logger-instrument-modal',
				onClose: jest.fn(),
				search: '',
				onSearch: jest.fn(),
				placeholder: 'Search instruments…',
				...props,
			} )
		);
		mounted.push( r );
		return r;
	}

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	it( 'frames the dialog with its own class beside the shared contract', () => {
		mount( { className: 'event-logger-instrument-modal theme-brass' } );
		const frame = document.querySelector(
			'.event-logger-instrument-modal'
		);
		expect( frame ).toBeTruthy();
		expect(
			frame.classList.contains( 'event-logger-selector-modal' )
		).toBe( true );
		expect( productRootClasses( frame ) ).toEqual( [
			'theme-brass',
			'newspack-nodes-modal',
			'newspack-nodes-theme',
			'newspack-nodes-ui',
		] );
		expect( document.body.textContent ).toContain( 'Choose Instruments' );
	} );

	it( 'leaves no trailing space when no skin class is passed', () => {
		mount( {} );
		expect(
			document.querySelector( '.event-logger-instrument-modal' ).className
		).toBe(
			'components-modal__frame event-logger-instrument-modal event-logger-selector-modal newspack-nodes-modal newspack-nodes-theme newspack-nodes-ui'
		);
	} );

	// The rules editor opens both pickers under the dashboard skin; the
	// settings page opens them bare. One component, one dialog geometry.
	it( 'resolves one header geometry whether or not the caller adds the skin', () => {
		const header = () =>
			resolveCascade(
				document.querySelector( '.components-modal__header' ),
				PICKER_STYLESHEETS
			);
		const heading = () =>
			resolveCascade(
				document.querySelector( '.components-modal__header-heading' ),
				PICKER_STYLESHEETS
			);

		mount( { className: 'event-logger-theremin-modal' } );
		const bare = { header: header(), heading: heading() };
		mounted.pop().unmount();

		mount( {
			className:
				'event-logger-theremin-modal newspack-nodes-skin-root theme-brass',
		} );
		expect( { header: header(), heading: heading() } ).toEqual( bare );
		expect( bare.header.padding ).toBe( '16px 20px' );
		expect( bare.header.height ).toBeUndefined();
		expect( bare.heading[ 'font-size' ] ).toBe( '15px' );
	} );

	it( 'renders the search box and reports what was typed', () => {
		const onSearch = jest.fn();
		mount( { search: 'theremin', onSearch } );
		const input = document.querySelector(
			'.event-logger-selector-modal__header input[type="search"]'
		);
		expect( input.value ).toBe( 'theremin' );
		expect( input.placeholder ).toBe( 'Search instruments…' );
		act( () => {
			// React tracks the value node-side; set it through the prototype
			// setter or the synthetic change event is swallowed as a no-op.
			Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set.call( input, 'ondes' );
			input.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );
		expect( onSearch ).toHaveBeenCalledWith( 'ondes' );
	} );

	it( 'puts the actions in the header row and the list in the body', () => {
		mount( {
			actions: React.createElement(
				'button',
				{ type: 'button' },
				'Apply (4)'
			),
			children: React.createElement( 'p', null, 'ondes martenot' ),
		} );
		expect(
			document.querySelector( '.event-logger-selector-modal__actions' )
				.textContent
		).toBe( 'Apply (4)' );
		expect(
			document.querySelector( '.event-logger-selector-modal__body' )
				.textContent
		).toBe( 'ondes martenot' );
	} );

	it( 'renders a footer only when one is given', () => {
		mount( {} );
		expect(
			document.querySelector( '.event-logger-selector-modal__footer' )
		).toBeNull();
		mounted.pop().unmount();
		mount( {
			footer: React.createElement( 'span', null, '4 of 9 selected' ),
		} );
		expect(
			document.querySelector( '.event-logger-selector-modal__footer' )
				.textContent
		).toBe( '4 of 9 selected' );
	} );
} );
