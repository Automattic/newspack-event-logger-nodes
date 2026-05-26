/**
 * hookCatalogView tests — the `hookcatalog/view` render-model node. It holds the
 * `{ hooksByCategory, loading }` model the modal reads via useNodeState. `fill()`
 * accepts the two TM_STRUCT controls the command node emits: `loading` (flips
 * loading true, preserves the prior map) and `catalog` (stores the map, clears
 * loading). Malformed messages are ignored. Mirrors aggregatorView's tests.
 */

import { Core, VALUE, newMessage } from '@newspack-nodes/runtime';
import { createHookCatalogView } from '../hookCatalogView';

// Build a TM_STRUCT control message carrying `value` at VALUE.
function control( value ) {
	const m = newMessage();
	m[ VALUE ] = value;
	return m;
}

beforeEach( () => {
	Core.reset();
} );

describe( 'createHookCatalogView', () => {
	test( 'publishes the initial model { hooksByCategory:{}, loading:false }', () => {
		const node = createHookCatalogView( 'hookcatalog/view' );
		expect( node.setStateCache.view ).toEqual( {
			hooksByCategory: {},
			loading: false,
		} );
	} );

	test( 'a loading control publishes loading:true and preserves the prior map', () => {
		const node = createHookCatalogView( 'hookcatalog/view' );
		const hooks = { Lifecycle: [ 'init' ] };
		node.fill( control( { action: 'catalog', hooksByCategory: hooks } ) );
		node.fill( control( { action: 'loading' } ) );
		expect( node.setStateCache.view ).toEqual( {
			hooksByCategory: hooks,
			loading: true,
		} );
	} );

	test( 'a catalog control publishes the map and clears loading', () => {
		const node = createHookCatalogView( 'hookcatalog/view' );
		const hooks = {
			Lifecycle: [ 'init' ],
			'REST API': [ 'rest_api_init' ],
		};
		node.fill( control( { action: 'loading' } ) );
		node.fill( control( { action: 'catalog', hooksByCategory: hooks } ) );
		expect( node.setStateCache.view ).toEqual( {
			hooksByCategory: hooks,
			loading: false,
		} );
	} );

	test( 'a malformed message (no VALUE / no action) is ignored', () => {
		const node = createHookCatalogView( 'hookcatalog/view' );
		const before = node.setStateCache.view;
		node.fill( newMessage() );
		node.fill( control( { foo: 'bar' } ) );
		expect( node.setStateCache.view ).toEqual( before );
	} );

	test( 'registers under the given name', () => {
		const node = createHookCatalogView( 'hookcatalog/view' );
		expect( Core.node( 'hookcatalog/view' ) ).toBe( node );
	} );
} );
