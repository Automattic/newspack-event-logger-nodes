/**
 * Tests for the ErrorLogPage component defined in error-log/index.js. The
 * DOMContentLoaded → createRoot mount path is covered by mount-entrypoints;
 * here we render the component DIRECTLY (the lazy ErrorLog is mocked).
 */

jest.mock( '../ErrorLog', () => ( {
	__esModule: true,
	default: () => {
		const React = require( 'react' );
		return React.createElement( 'div', null, 'ERROR_LOG' );
	},
} ) );

import { act, renderComponent } from '../../test-helpers/renderHook';
import { ErrorLogPage } from '../index';

describe( 'error-log — ErrorLogPage', () => {
	let views;

	beforeEach( () => {
		views = [];
	} );

	afterEach( () => {
		views.forEach( ( v ) => v.unmount() );
	} );

	// Mount a component and flush lazy-import microtask so ErrorLog renders.
	async function mount( element ) {
		const view = renderComponent( element );
		views.push( view );
		await act( async () => {} );
		return view;
	}

	it( 'renders the error log view', async () => {
		const { container } = await mount( <ErrorLogPage /> );
		expect( container.textContent ).toContain( 'ERROR_LOG' );
	} );
} );
