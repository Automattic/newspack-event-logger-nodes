/**
 * SelectorModal tests — the chrome both picker modals render: the shared
 * medium dialog under a compact header, the search box, the actions row, the
 * scrolling body, and the optional footer. What is being selected, and every
 * translator call, stays with each picker.
 */

import React from 'react';
import SelectorModal from '../SelectorModal';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { MODAL_EXIT_MS } from '@newspack-nodes/shared/hooks/useModalExit';
import {
	compileLocal,
	compileShared,
	resolved,
} from '../../../test-helpers/cascade';

/**
 * Every stylesheet a bundle rendering a picker emits, in import order:
 * RuleEditModal pulls the pickers (and with them the shared chrome) before its
 * own sheet, so this is the order that decides any tie.
 */
const PICKER_STYLESHEETS = [
	compileShared( 'components/Modal.scss' ),
	...[
		'settings/styles/selector-modal.scss',
		'settings/styles/hook-selector.scss',
		'settings/styles/custom-event-selector.scss',
		'rules/rule-edit-modal.scss',
	].map( compileLocal ),
];

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

	it( 'frames the dialog as the shared medium box carrying its own classes', () => {
		mount( { className: 'event-logger-instrument-modal theme-brass' } );
		const frame = document.querySelector(
			'.event-logger-instrument-modal'
		);
		expect( frame.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( productRootClasses( frame ) ).toEqual( [
			'newspack-nodes-modal',
			'newspack-nodes-modal--medium',
			'theme-brass',
		] );
		expect(
			frame.classList.contains( 'event-logger-selector-modal' )
		).toBe( true );
	} );

	it( 'leaves no stray space in the class list', () => {
		mount( {} );
		expect(
			document.querySelector( '.event-logger-instrument-modal' ).className
		).toBe(
			'newspack-nodes-modal newspack-nodes-modal--medium event-logger-instrument-modal event-logger-selector-modal'
		);
	} );

	it( 'heads a selector with the compact shared header', () => {
		mount( {} );
		const header = document.querySelector(
			'.event-logger-instrument-modal > .newspack-nodes-modal__header'
		);
		expect(
			header.classList.contains( 'newspack-nodes-modal__header--compact' )
		).toBe( true );
		expect(
			header.querySelector( '.newspack-nodes-modal__title' ).textContent
		).toBe( 'Choose Instruments' );
		expect(
			header.querySelector( '.newspack-nodes-modal__close' )
		).not.toBeNull();
	} );

	it( 'plays its exit before Escape hands the close back', () => {
		jest.useFakeTimers();
		try {
			const onClose = jest.fn();
			mount( { onClose } );
			act( () => {
				document.dispatchEvent(
					new window.KeyboardEvent( 'keydown', {
						key: 'Escape',
						bubbles: true,
					} )
				);
			} );
			expect( onClose ).not.toHaveBeenCalled();
			act( () => jest.advanceTimersByTime( MODAL_EXIT_MS ) );
			expect( onClose ).toHaveBeenCalledTimes( 1 );
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'hands the close to the caller through handleRef', () => {
		jest.useFakeTimers();
		try {
			const onClose = jest.fn();
			const handleRef = { current: null };
			mount( { onClose, handleRef } );
			act( () => handleRef.current.close() );
			expect( onClose ).not.toHaveBeenCalled();
			act( () => jest.advanceTimersByTime( MODAL_EXIT_MS ) );
			expect( onClose ).toHaveBeenCalledTimes( 1 );
		} finally {
			jest.useRealTimers();
		}
	} );

	// The settings page and the rules editor open the same picker; each
	// caps its height and scrolls only the list inside the cap.
	it.each( [
		[ 'event-logger-theremin-modal', '85vh' ],
		[ 'event-logger-hook-selector-modal', '70vh' ],
	] )(
		'caps the %s box at %s and scrolls its list alone',
		( className, cap ) => {
			mount( { className } );
			const box = document.querySelector( `.${ className }` );
			const style = resolved( box, PICKER_STYLESHEETS );
			expect( style[ 'max-height' ] ).toBe( cap );
			expect( style.display ).toBe( 'flex' );
			expect( style[ 'flex-direction' ] ).toBe( 'column' );
			expect( style[ 'overflow-y' ] ).toBe( 'hidden' );
			expect(
				resolved(
					box.querySelector( '.event-logger-selector-modal__body' ),
					PICKER_STYLESHEETS
				)[ 'overflow-y' ]
			).toBe( 'auto' );
		}
	);

	// Each picker keeps its own width over the shared medium box's cap.
	it.each( [
		[ 'event-logger-theremin-modal', '680px' ],
		[ 'event-logger-hook-selector-modal', '1100px' ],
	] )( 'widens the %s box to %s', ( className, width ) => {
		mount( { className } );
		const style = resolved(
			document.querySelector( `.${ className }` ),
			PICKER_STYLESHEETS
		);
		expect( style.width ).toBe( width );
		expect( style[ 'max-width' ] ).toBe( '95vw' );
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
