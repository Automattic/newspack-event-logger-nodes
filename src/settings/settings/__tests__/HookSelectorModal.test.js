/* global KeyboardEvent */
/**
 * Tests for HookSelectorModal — renders a category-grouped checkbox tree from
 * the render model published by useHookCatalogGraph. Post-migration the modal
 * is purely presentational over the hook's `{ hooksByCategory, loading }`
 * shape, so these tests mock the hook directly. The hook's own tests
 * (../../hooks/__tests__/useHookCatalogGraph.test.js) cover the graph wiring.
 */

// Mock useHookCatalogGraph; state on globalThis (jest.mock can't see locals).
jest.mock( '../../hooks/useHookCatalogGraph', () => ( {
	__esModule: true,
	useHookCatalogGraph: jest.fn( ( opts ) => {
		global.__hookcatalogLastIsOpen = opts ? opts.isOpen : undefined;
		return (
			global.__hookcatalogMockModel || {
				hooksByCategory: {},
				loading: false,
			}
		);
	} ),
} ) );

beforeAll( () => {
	window.newspackNodesRecommendedHooks = [ 'init', 'shutdown' ];
} );

let React;
let HookSelectorModal;
beforeAll( () => {
	React = require( 'react' );
	HookSelectorModal = require( '../HookSelectorModal' ).default;
} );

const HOOKS = {
	Lifecycle: [ 'init', 'shutdown', 'wp_loaded' ],
	'REST API': [ 'rest_api_init' ],
};

function productRootClasses( element ) {
	return Array.from( element.classList ).filter(
		( className ) =>
			'topology-app' === className ||
			className.startsWith( 'theme-' ) ||
			className.startsWith( 'newspack-nodes-' )
	);
}

describe( 'HookSelectorModal', () => {
	const mounted = [];

	function mount( props ) {
		const {
			renderComponent,
		} = require( '../../../test-helpers/renderHook' );
		const r = renderComponent(
			React.createElement( HookSelectorModal, props )
		);
		mounted.push( r );
		return r;
	}

	beforeEach( () => {
		global.__hookcatalogMockModel = { hooksByCategory: {}, loading: false };
		global.__hookcatalogLastIsOpen = undefined;
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
	} );

	it( 'passes isOpen through to useHookCatalogGraph', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		expect( global.__hookcatalogLastIsOpen ).toBe( true );
	} );

	it( 'uses the exact standalone product modal root classes', () => {
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		const frame = document.querySelector(
			'.event-logger-hook-selector-modal'
		);
		expect( frame ).toBeTruthy();
		expect( productRootClasses( frame ) ).toEqual( [
			'newspack-nodes-modal',
			'newspack-nodes-theme',
			'newspack-nodes-ui',
		] );
	} );

	it( 'renders categories from the hook model', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		expect( document.body.textContent ).toContain( 'Lifecycle' );
		expect( document.body.textContent ).toContain( 'REST API' );
		const category = document.querySelector( '.hook-selector-category' );
		expect( category.classList.contains( 'newspack-nodes-card' ) ).toBe(
			false
		);
		expect(
			category
				.querySelector( '.hook-selector-category-header' )
				.classList.contains( 'button' )
		).toBe( false );
	} );

	it( 'shows a spinner while loading', () => {
		global.__hookcatalogMockModel = { hooksByCategory: {}, loading: true };
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		expect( document.body.textContent ).toContain(
			'Loading registered hooks'
		);
	} );

	it( 'pre-selects passed-in hooks (Apply emits exactly them)', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		const onSelect = jest.fn();
		const onClose = jest.fn();
		mount( {
			isOpen: true,
			onClose,
			selected: [ 'init' ],
			onSelect,
		} );
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		const { act } = require( '../../../test-helpers/renderHook' );
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith( [ 'init' ] );
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'Recommended button replaces selection with recommended set', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [ 'rest_api_init' ],
			onSelect,
		} );
		const rec = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === 'Recommended'
		);
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		const { act } = require( '../../../test-helpers/renderHook' );
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

	it( 'expands a category and toggles individual hook checkboxes', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect,
		} );
		const categoryHeader = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		const { act } = require( '../../../test-helpers/renderHook' );
		act( () => {
			categoryHeader.click();
		} );
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

	it( 'Select All adds visible hooks; Clear Matches removes filtered subset', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect,
		} );
		const { act } = require( '../../../test-helpers/renderHook' );
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

	it( 'unchecks a pre-selected hook on click; Clear All wipes everything', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [ 'init', 'shutdown', 'wp_loaded' ],
			onSelect,
		} );
		const { act } = require( '../../../test-helpers/renderHook' );
		const header = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		act( () => {
			header.click();
		} );
		const initCheckbox = document.querySelector( '#hook-init' );
		act( () => {
			initCheckbox.click();
		} );
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

	it( 'category checkbox selects and clears every hook in that category', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: {
				...HOOKS,
				Broken: null,
			},
			loading: false,
		};
		const onSelect = jest.fn();
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect,
		} );
		const lifecycleHeader = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		const categoryCheckbox = lifecycleHeader.querySelector(
			'input[type="checkbox"]'
		);
		const apply = Array.from( document.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Apply' )
		);
		const { act } = require( '../../../test-helpers/renderHook' );
		act( () => {
			categoryCheckbox.click();
		} );
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenLastCalledWith(
			expect.arrayContaining( [ 'init', 'shutdown', 'wp_loaded' ] )
		);

		onSelect.mockClear();
		act( () => {
			categoryCheckbox.click();
		} );
		act( () => {
			apply.click();
		} );
		expect( onSelect ).toHaveBeenLastCalledWith( [] );
	} );

	it( 'expands and collapses a category from keyboard activation', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		const header = Array.from(
			document.querySelectorAll( '.hook-selector-category-header' )
		).find( ( el ) => el.textContent.includes( 'Lifecycle' ) );
		const { act } = require( '../../../test-helpers/renderHook' );
		act( () => {
			header.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		expect( document.querySelector( '#hook-init' ) ).toBeTruthy();
		act( () => {
			header.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: ' ',
					bubbles: true,
				} )
			);
		} );
		expect( document.querySelector( '#hook-init' ) ).toBeNull();
	} );

	it( 'collapses an expanded category when its header is clicked twice', () => {
		global.__hookcatalogMockModel = {
			hooksByCategory: HOOKS,
			loading: false,
		};
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		const { act } = require( '../../../test-helpers/renderHook' );
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

	it( 'renders an empty modal when the hook returns an empty map', () => {
		global.__hookcatalogMockModel = { hooksByCategory: {}, loading: false };
		mount( {
			isOpen: true,
			onClose: jest.fn(),
			selected: [],
			onSelect: jest.fn(),
		} );
		// No category names from HOOKS appear.
		expect( document.body.textContent ).not.toContain( 'Lifecycle' );
	} );
} );
