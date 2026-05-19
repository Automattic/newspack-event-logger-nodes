/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * Tests for CustomEventSelectorModal — pure modal with no network
 * calls. Loads the event list from window.newspackNodesCustomColors at
 * module import time, so we set that before importing.
 */

// CUSTOM_COLORS in CustomEventSelectorModal is captured at module load.
// We set the global FIRST, then require() the SUT — jest's import
// hoisting can't reorder this.
beforeAll( () => {
	window.newspackNodesCustomColors = {
		'wp.cron.event': '#aabbcc',
		'wp.template.render': '#112233',
		'wp.query.run': '#445566',
	};
} );

let React;
let CustomEventSelectorModal;
beforeAll( () => {
	React = require( 'react' );
	CustomEventSelectorModal = require( '../CustomEventSelectorModal' ).default;
} );
import {
	renderComponent,
	act,
} from '../../../shared/hooks/__tests__/renderHook';

describe( 'CustomEventSelectorModal', () => {
	const mounted = [];

	function mount( props ) {
		const r = renderComponent(
			React.createElement( CustomEventSelectorModal, props )
		);
		mounted.push( r );
		return r;
	}

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	it( 'renders nothing when isOpen=false', () => {
		const { container } = mount( {
			isOpen: false,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		expect( container.textContent ).toBe( '' );
	} );

	it( 'renders each event with its name as a checkbox label', () => {
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		const text = document.body.textContent;
		expect( text ).toContain( 'wp.cron.event' );
		expect( text ).toContain( 'wp.template.render' );
		expect( text ).toContain( 'wp.query.run' );
		expect( text ).toContain( '0 of 3 events selected' );
	} );

	it( 'pre-checks the selected events when the modal opens', () => {
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [ 'wp.cron.event' ],
			onSelect: jest.fn(),
		} );
		const checked = document.querySelectorAll(
			'input[type="checkbox"]:checked'
		);
		expect( checked.length ).toBe( 1 );
		expect( checked[ 0 ].id ).toBe( 'event-wp-cron-event' );
	} );

	it( 'fires onSelect with selected names when Apply is clicked', () => {
		const onSelect = jest.fn();
		const onClose = jest.fn();
		mount( {
			isOpen: true,
			onClose,
			selected: [],
			onSelect,
		} );
		const checkbox = document.querySelector( 'input[type="checkbox"]' );
		act( () => {
			checkbox.click();
		} );
		const applyBtn = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Apply' ) );
		act( () => {
			applyBtn.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith( [ 'wp.cron.event' ] );
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'Select All adds every visible event', () => {
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		const selectAll = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Select All' );
		act( () => {
			selectAll.click();
		} );
		const checked = document.querySelectorAll(
			'input[type="checkbox"]:checked'
		);
		expect( checked.length ).toBe( 3 );
	} );

	it( 'Clear All resets selection', () => {
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [ 'wp.cron.event', 'wp.query.run' ],
			onSelect: jest.fn(),
		} );
		expect(
			document.querySelectorAll( 'input[type="checkbox"]:checked' ).length
		).toBe( 2 );
		const clearAll = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear All' );
		act( () => {
			clearAll.click();
		} );
		expect(
			document.querySelectorAll( 'input[type="checkbox"]:checked' ).length
		).toBe( 0 );
	} );
} );
