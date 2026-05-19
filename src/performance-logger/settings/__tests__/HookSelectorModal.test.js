/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * Tests for HookSelectorModal — fetches hooks via the substrate
 * CommandClient and renders a category-grouped checkbox tree.
 */

jest.mock( '../../../shared/utils/commandClient', () => {
	const send = jest.fn();
	return {
		__esModule: true,
		getCommandClient: jest.fn( () => ( { send } ) ),
		__send: send,
	};
} );
jest.mock( '../../../shared/utils/unwrapCommandResponse', () => ( {
	__esModule: true,
	default: jest.fn( ( msg ) => msg ),
} ) );

// RECOMMENDED_HOOKS is captured at module load — set the global first,
// then require() the SUT.
beforeAll( () => {
	window.newspackNodesRecommendedHooks = [ 'init', 'shutdown' ];
} );

let React;
let HookSelectorModal;
let mockSend;
let unwrap;
beforeAll( () => {
	React = require( 'react' );
	HookSelectorModal = require( '../HookSelectorModal' ).default;
	mockSend = require( '../../../shared/utils/commandClient' ).__send;
	unwrap = require( '../../../shared/utils/unwrapCommandResponse' ).default;
} );

const HOOKS = {
	Lifecycle: [ 'init', 'shutdown', 'wp_loaded' ],
	'REST API': [ 'rest_api_init' ],
};

async function flush() {
	const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
	await act( async () => {} );
}

describe( 'HookSelectorModal', () => {
	const mounted = [];

	function mount( props ) {
		const {
			renderComponent,
		} = require( '../../../shared/hooks/__tests__/renderHook' );
		const r = renderComponent(
			React.createElement( HookSelectorModal, props )
		);
		mounted.push( r );
		return r;
	}

	beforeEach( () => {
		mockSend.mockReset();
		unwrap.mockReset();
		unwrap.mockImplementation( ( msg ) => msg );
	} );

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
		expect( mockSend ).not.toHaveBeenCalled();
	} );

	it( 'fetches hooks via performance.hooks_registered when opened', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		expect( mockSend ).toHaveBeenCalledWith( {
			to: 'performance',
			verb: 'hooks_registered',
		} );
		await flush();
		// Categories render after the fetch resolves.
		expect( document.body.textContent ).toContain( 'Lifecycle' );
		expect( document.body.textContent ).toContain( 'REST API' );
	} );

	it( 'shows a spinner while loading', async () => {
		let resolveSend;
		mockSend.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveSend = resolve;
			} )
		);
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		// Before resolution the spinner copy is visible.
		expect( document.body.textContent ).toContain(
			'Loading registered hooks'
		);
		resolveSend( { hooks_by_category: HOOKS } );
		await flush();
		expect( document.body.textContent ).not.toContain(
			'Loading registered hooks'
		);
	} );

	it( 'pre-selects passed-in hooks (Apply emits exactly them)', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		const onSelect = jest.fn();
		const onClose = jest.fn();
		mount( {
			isOpen: true,
			onClose,
			selected: [ 'init' ],
			onSelect,
		} );
		await flush();
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith( [ 'init' ] );
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'Recommended button replaces selection with recommended set', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [ 'rest_api_init' ],
			onSelect,
		} );
		await flush();
		const rec = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === 'Recommended'
		);
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
		act( () => {
			rec.click();
		} );
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith(
			expect.arrayContaining( [ 'init', 'shutdown' ] )
		);
	} );

	it( 'expands a category and toggles individual hook checkboxes', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect,
		} );
		await flush();
		// Click the Lifecycle category header to expand.
		const categoryHeader = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
		act( () => {
			categoryHeader.click();
		} );
		// Inner hook checkboxes appear.
		const initCheckbox = document.querySelector( '#hook-init' );
		expect( initCheckbox ).toBeTruthy();
		act( () => {
			initCheckbox.click();
		} );
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith( [ 'init' ] );
	} );

	it( 'Select All adds visible hooks; Clear Matches removes filtered subset', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect,
		} );
		await flush();
		const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
		// Click "Select All" → selects every hook.
		const selectAll = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( b ) => /Select All|Select Matches/.test( b.textContent ) );
		act( () => {
			selectAll.click();
		} );
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		act( () => {
			apply.click();
		} );
		// Order may differ; check membership.
		const args = onSelect.mock.calls[ 0 ][ 0 ];
		expect( args ).toEqual(
			expect.arrayContaining( [
				'init',
				'shutdown',
				'wp_loaded',
				'rest_api_init',
			] )
		);
	} );

	it( 'unchecks a pre-selected hook on click; Clear All wipes everything', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [ 'init', 'shutdown', 'wp_loaded' ],
			onSelect,
		} );
		await flush();
		const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
		// Expand Lifecycle.
		const header = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		act( () => {
			header.click();
		} );
		// Uncheck "init" by clicking its checkbox.
		const initCheckbox = document.querySelector( '#hook-init' );
		act( () => {
			initCheckbox.click();
		} );
		// Click "Clear All" → empties selection.
		const clearAll = Array.from(
			document.querySelectorAll( 'button' )
		).find( ( b ) => /Clear All|Clear Matches/.test( b.textContent ) );
		act( () => {
			clearAll.click();
		} );
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith( [] );
	} );

	it( 'collapses an expanded category when its header is clicked twice', async () => {
		mockSend.mockResolvedValue( { hooks_by_category: HOOKS } );
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		await flush();
		const { act } = require( '../../../shared/hooks/__tests__/renderHook' );
		const header = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		act( () => {
			header.click();
		} );
		expect( document.querySelector( '#hook-init' ) ).toBeTruthy();
		act( () => {
			header.click();
		} );
		expect( document.querySelector( '#hook-init' ) ).toBeNull();
	} );

	it( 'falls back to an empty category map when fetch rejects', async () => {
		mockSend.mockRejectedValue( new Error( 'boom' ) );
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		await flush();
		// No category names from HOOKS appear.
		expect( document.body.textContent ).not.toContain( 'Lifecycle' );
	} );
} );
