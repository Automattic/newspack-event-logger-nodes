/**
 * Tests for the inline AdminApp + ErrorLogPage page components defined in
 * performance-dashboards/index.js. The DOMContentLoaded → createRoot mount path
 * is covered by mount-entrypoints.test.js; here we render the components
 * DIRECTLY so we can drive AdminApp's error state machine (handleError + the 5s
 * auto-clear effect) and the Suspense LoadingFallback without the document-level
 * listener-accumulation / multi-root hazards of re-requiring the entry module.
 */

let mockOnError = null;
// When set, the lazy dashboard suspends forever so the Suspense fallback shows.
let mockStallDashboard = false;
jest.mock( '../PerformanceDashboard', () => ( {
	__esModule: true,
	default: ( props ) => {
		const React = require( 'react' );
		if ( mockStallDashboard ) {
			// eslint-disable-next-line no-throw-literal
			throw new Promise( () => {} );
		}
		mockOnError = props.onError;
		return React.createElement( 'div', null, 'PERFORMANCE_DASHBOARD' );
	},
} ) );

jest.mock( '../ErrorLog', () => ( {
	__esModule: true,
	default: () => {
		const React = require( 'react' );
		return React.createElement( 'div', null, 'ERROR_LOG' );
	},
} ) );

import { act, renderComponent } from '../../test-helpers/renderHook';
import { AdminApp, ErrorLogPage } from '../index';

describe( 'performance-dashboards — AdminApp + ErrorLogPage', () => {
	let views;

	beforeEach( () => {
		jest.useRealTimers();
		mockOnError = null;
		mockStallDashboard = false;
		views = [];
	} );

	afterEach( () => {
		views.forEach( ( v ) => v.unmount() );
	} );

	// Mount a component and flush the lazy import microtask so the
	// PerformanceDashboard mock renders and captures its onError prop.
	async function mount( element ) {
		const view = renderComponent( element );
		views.push( view );
		await act( async () => {} );
		return view;
	}

	it( 'AdminApp displays an error Notice and auto-clears it after 5s', async () => {
		const { container } = await mount( <AdminApp /> );
		expect( mockOnError ).toEqual( expect.any( Function ) );
		jest.useFakeTimers();
		await act( async () => {
			mockOnError( new Error( 'oh no' ) );
		} );
		expect( container.textContent ).toContain( 'oh no' );
		// Advance past the effect's 5s auto-clear timeout.
		await act( async () => {
			jest.advanceTimersByTime( 6000 );
		} );
		expect( container.textContent ).not.toContain( 'oh no' );
		jest.useRealTimers();
	} );

	it( 'AdminApp falls back to "An error occurred" when error has no message', async () => {
		const { container } = await mount( <AdminApp /> );
		await act( async () => {
			mockOnError( {} );
		} );
		expect( container.textContent ).toContain( 'An error occurred' );
	} );

	it( 'AdminApp shows the LoadingFallback while the dashboard chunk loads', () => {
		mockStallDashboard = true;
		const view = renderComponent( <AdminApp /> );
		views.push( view );
		expect( view.container.textContent ).toContain( 'Loading' );
	} );

	it( 'ErrorLogPage renders the error log view', async () => {
		const { container } = await mount( <ErrorLogPage /> );
		expect( container.textContent ).toContain( 'ERROR_LOG' );
	} );
} );
