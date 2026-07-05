/**
 * RuleEditModal tests — the single-rule draft editor. Renders a @wordpress/components
 * Modal editing pattern / action / (for log rules) hooks / custom events /
 * significant events / auto-tune thresholds. `onSave(draft)` emits the edited
 * rule; the parent decides upsert vs save. Skip rules hide the log-only fields.
 *
 * The reused pickers (HookSelectorModal / CustomEventSelectorModal) are mocked to
 * a trivial "apply a fixed selection" button so this suite tests the modal's own
 * draft wiring, not the pickers (which have their own suites).
 */

jest.mock( '../../settings/settings/HookSelectorModal', () => ( {
	__esModule: true,
	default: ( { isOpen, onSelect, onClose } ) =>
		isOpen
			? ( () => {
					const el = require( 'react' ).createElement;
					return el(
						'button',
						{
							type: 'button',
							'data-testid': 'mock-apply-hooks',
							onClick: () => {
								onSelect( [ 'init', 'wp_loaded' ] );
								onClose();
							},
						},
						'apply-hooks'
					);
			  } )()
			: null,
} ) );

jest.mock( '../../settings/settings/CustomEventSelectorModal', () => ( {
	__esModule: true,
	default: ( { isOpen, onSelect, onClose } ) =>
		isOpen
			? ( () => {
					const el = require( 'react' ).createElement;
					return el(
						'button',
						{
							type: 'button',
							'data-testid': 'mock-apply-custom',
							onClick: () => {
								onSelect( [ 'cache_hit' ] );
								onClose();
							},
						},
						'apply-custom'
					);
			  } )()
			: null,
} ) );

import { renderComponent, act } from '../../test-helpers/renderHook';
import RuleEditModal from '../RuleEditModal';

const LOG_RULE = {
	id: 'r1',
	pattern: '/blog',
	action: 'log',
	auto_disable_threshold: 5,
	auto_protect_time_threshold: 250,
	significant_events: [ 'checkout' ],
	custom_events: [],
	hooks: [ 'init' ],
	hooks_in: 'inline',
};

function click( el ) {
	act( () => {
		el.dispatchEvent( new Event( 'click', { bubbles: true } ) );
	} );
}

function setInput( input, value ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

// The @wordpress/components Modal portals to document.body; scope queries there.
function inDialog( selector ) {
	const dialog = document.querySelector( '[role="dialog"]' ) || document;
	return dialog.querySelector( selector );
}

function saveButton() {
	const dialog = document.querySelector( '[role="dialog"]' ) || document;
	return Array.from( dialog.querySelectorAll( 'button' ) ).find(
		( b ) => b.textContent.trim() === 'Save rule'
	);
}

describe( 'RuleEditModal — log rule fields', () => {
	let onSave;
	let onCancel;
	const mounted = [];

	beforeEach( () => {
		onSave = jest.fn();
		onCancel = jest.fn();
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount( rule ) {
		const r = renderComponent(
			<RuleEditModal
				rule={ rule }
				onSave={ onSave }
				onCancel={ onCancel }
			/>
		);
		mounted.push( r );
		return r;
	}

	test( 'renders the pattern input prefilled from the rule', () => {
		mount( LOG_RULE );
		const input = inDialog( 'input[name="rule-pattern"]' );
		expect( input ).toBeTruthy();
		expect( input.value ).toBe( '/blog' );
	} );

	test( 'a log rule shows the log-only fields (hooks / custom events / significant / thresholds)', () => {
		mount( LOG_RULE );
		expect( inDialog( '.rule-edit-hooks-field' ) ).toBeTruthy();
		expect( inDialog( '.rule-edit-custom-field' ) ).toBeTruthy();
		// Significant events is a TagInputField (pill input), not a plain control.
		expect(
			inDialog( '.rule-edit-tag-field .event-logger-tag-input' )
		).toBeTruthy();
		expect(
			inDialog( 'input[name="rule-auto-disable-threshold"]' )
		).toBeTruthy();
		expect(
			inDialog( 'input[name="rule-auto-protect-time-threshold"]' )
		).toBeTruthy();
	} );

	test( 'shows the current hooks count', () => {
		mount( LOG_RULE );
		expect( inDialog( '.rule-edit-hooks-field' ).textContent ).toContain(
			'1'
		);
	} );

	test( 'onSave emits the draft with pattern / action / hooks / thresholds', () => {
		mount( LOG_RULE );
		click( saveButton() );
		expect( onSave ).toHaveBeenCalledTimes( 1 );
		const draft = onSave.mock.calls[ 0 ][ 0 ];
		expect( draft.pattern ).toBe( '/blog' );
		expect( draft.action ).toBe( 'log' );
		expect( draft.hooks ).toEqual( [ 'init' ] );
		expect( draft.auto_disable_threshold ).toBe( 5 );
		expect( draft.auto_protect_time_threshold ).toBe( 250 );
		expect( draft.significant_events ).toEqual( [ 'checkout' ] );
		// The id round-trips so the parent's upsert can preserve it.
		expect( draft.id ).toBe( 'r1' );
	} );

	test( 'editing the pattern is reflected in the saved draft', () => {
		mount( LOG_RULE );
		setInput( inDialog( 'input[name="rule-pattern"]' ), '/news' );
		click( saveButton() );
		expect( onSave.mock.calls[ 0 ][ 0 ].pattern ).toBe( '/news' );
	} );

	test( 'applying hooks from the picker updates the saved draft', () => {
		mount( LOG_RULE );
		click( inDialog( '.rule-edit-hooks-field button' ) );
		click( document.querySelector( '[data-testid="mock-apply-hooks"]' ) );
		click( saveButton() );
		expect( onSave.mock.calls[ 0 ][ 0 ].hooks ).toEqual( [
			'init',
			'wp_loaded',
		] );
	} );

	test( 'applying custom events from the picker updates the saved draft', () => {
		mount( LOG_RULE );
		click( inDialog( '.rule-edit-custom-field button' ) );
		click( document.querySelector( '[data-testid="mock-apply-custom"]' ) );
		click( saveButton() );
		expect( onSave.mock.calls[ 0 ][ 0 ].custom_events ).toEqual( [
			'cache_hit',
		] );
	} );

	test( 'onCancel fires when Cancel is clicked and does not save', () => {
		mount( LOG_RULE );
		const dialog = document.querySelector( '[role="dialog"]' );
		const cancel = Array.from( dialog.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Cancel'
		);
		click( cancel );
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
		expect( onSave ).not.toHaveBeenCalled();
	} );
} );

describe( 'RuleEditModal — skip rule hides log-only fields', () => {
	const SKIP_RULE = {
		id: 'r2',
		pattern: '/admin',
		action: 'skip',
		auto_disable_threshold: 0,
		auto_protect_time_threshold: 0,
		significant_events: [],
		custom_events: [],
		hooks: [],
		hooks_in: 'inline',
	};
	let onSave;
	const mounted = [];

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount( rule ) {
		onSave = jest.fn();
		const r = renderComponent(
			<RuleEditModal
				rule={ rule }
				onSave={ onSave }
				onCancel={ jest.fn() }
			/>
		);
		mounted.push( r );
		return r;
	}

	test( 'a skip rule hides the hooks / custom / significant / threshold fields', () => {
		mount( SKIP_RULE );
		expect( inDialog( '.rule-edit-hooks-field' ) ).toBeNull();
		expect( inDialog( '.rule-edit-custom-field' ) ).toBeNull();
		expect( inDialog( '.rule-edit-tag-field' ) ).toBeNull();
		expect(
			inDialog( 'input[name="rule-auto-disable-threshold"]' )
		).toBeNull();
	} );

	test( 'toggling a log rule to skip hides the log-only fields', () => {
		mount( LOG_RULE );
		expect( inDialog( '.rule-edit-hooks-field' ) ).toBeTruthy();
		// The action control is a native select rendered by SelectControl.
		const select = inDialog( 'select[name="rule-action"]' );
		expect( select ).toBeTruthy();
		act( () => {
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLSelectElement.prototype,
				'value'
			).set;
			setter.call( select, 'skip' );
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		expect( inDialog( '.rule-edit-hooks-field' ) ).toBeNull();
	} );

	test( 'onSave for a skip rule emits action skip', () => {
		mount( SKIP_RULE );
		click( saveButton() );
		expect( onSave.mock.calls[ 0 ][ 0 ].action ).toBe( 'skip' );
	} );
} );

describe( 'RuleEditModal — validation', () => {
	let onSave;
	const mounted = [];

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount( rule ) {
		onSave = jest.fn();
		const r = renderComponent(
			<RuleEditModal
				rule={ rule }
				onSave={ onSave }
				onCancel={ jest.fn() }
			/>
		);
		mounted.push( r );
		return r;
	}

	test( 'an empty pattern blocks save and shows a validation message', () => {
		mount( { ...LOG_RULE, pattern: '' } );
		click( saveButton() );
		expect( onSave ).not.toHaveBeenCalled();
		expect(
			document.querySelector( '[role="dialog"]' ).textContent
		).toContain( 'Pattern is required' );
	} );
} );
