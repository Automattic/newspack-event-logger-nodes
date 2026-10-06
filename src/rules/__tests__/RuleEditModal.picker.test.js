/**
 * The rule editor's form against the real custom-event picker it opens.
 *
 * The picker portals to the body, so no DOM form owns its search box, and it
 * sits beside the editor's form in the React tree, so a submit rising from it
 * reaches no form React would bubble it to. Enter in the search therefore
 * neither applies the picker nor saves the rule.
 */

jest.mock( '../../settings/settings/HookSelectorModal', () => ( {
	__esModule: true,
	default: () => null,
} ) );

import { renderComponent, act } from '../../test-helpers/renderHook';
import RuleEditModal from '../RuleEditModal';

const LOG_RULE = {
	id: 'r9',
	pattern: '/heron',
	action: 'log',
	significant_events: [],
	custom_events: [],
	hooks: [],
};

describe( 'RuleEditModal — the custom-event picker', () => {
	let onSave;
	let mounted;

	beforeEach( () => {
		onSave = jest.fn();
		mounted = renderComponent(
			<RuleEditModal
				rule={ LOG_RULE }
				onSave={ onSave }
				onCancel={ jest.fn() }
			/>
		);
		const open = [ ...document.querySelectorAll( 'button' ) ].find(
			( b ) => 'Select Events' === b.textContent.trim()
		);
		act( () => open.click() );
	} );

	afterEach( () => mounted.unmount() );

	const search = () =>
		document.querySelector(
			'.event-logger-custom-event-modal .event-logger-selector-modal__header input'
		);

	test( 'its search box belongs to no form', () => {
		expect( search() ).toBeTruthy();
		expect( search().form ).toBeNull();
	} );

	test( 'a submit rising from its search saves nothing and keeps it open', () => {
		act( () => {
			search().dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
		} );
		act( () => {
			search().dispatchEvent(
				new window.KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
					cancelable: true,
				} )
			);
		} );
		expect( onSave ).not.toHaveBeenCalled();
		expect( search() ).toBeTruthy();
	} );
} );
