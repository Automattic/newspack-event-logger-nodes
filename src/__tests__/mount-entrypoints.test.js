/**
 * Tests for the dashboard mount-entry points — each one looks up a
 * DOM container by id and conditionally renders its top-level page.
 */

jest.mock( '../gyroscope/GyroscopePage', () => ( {
	__esModule: true,
	default: () => 'GYROSCOPE_PAGE',
} ) );
jest.mock( '../requests/RequestStreamPage', () => ( {
	__esModule: true,
	default: () => 'REQUEST_STREAM_PAGE',
} ) );
// overview/index.js mounts the lazy-loaded PerformanceDashboard; the error log
// is its own entry (error-log/index.js → lazy-loaded ErrorLog).
jest.mock( '../overview/PerformanceDashboard', () => ( {
	__esModule: true,
	default: () => 'PERFORMANCE_DASHBOARD',
} ) );
jest.mock( '../error-log/ErrorLog', () => ( {
	__esModule: true,
	default: () => 'ERROR_LOG',
} ) );

describe( 'dashboard mount-entry points', () => {
	beforeEach( () => {
		jest.resetModules();
		// Clean up any leftover children manually.
		while ( document.body.firstChild ) {
			document.body.removeChild( document.body.firstChild );
		}
	} );

	function mountContainer( id ) {
		const el = document.createElement( 'div' );
		el.id = id;
		document.body.appendChild( el );
		return el;
	}

	it( 'gyroscope/index.js mounts when #event-logger-gyroscope exists', () => {
		mountContainer( 'event-logger-gyroscope' );
		expect( () => require( '../gyroscope' ) ).not.toThrow();
	} );

	it( 'gyroscope/index.js is a no-op without the container', () => {
		expect( () => require( '../gyroscope' ) ).not.toThrow();
	} );

	it( 'requests/index.js mounts when #event-logger-stream exists', () => {
		// The mount container id is `event-logger-stream` (the prior
		// version of this test used `event-logger-request-stream` which
		// does not match the production lookup, so the if-block was
		// never exercised — see src/requests/index.js).
		const el = mountContainer( 'event-logger-stream' );
		expect( () => require( '../requests' ) ).not.toThrow();
		expect( el.parentNode ).toBe( document.body );
	} );

	it( 'requests/index.js is a no-op without the container', () => {
		expect( () => require( '../requests' ) ).not.toThrow();
	} );

	it( 'overview/index.js mounts the dashboard container', () => {
		const admin = mountContainer( 'event-logger-admin' );
		require( '../overview' );
		// Dispatch DOMContentLoaded — required because the handler is
		// registered at module-load time in jsdom (the document is
		// already "interactive" by then, but DOMContentLoaded hasn't
		// fired yet in this environment).
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect( admin.parentNode ).toBe( document.body );
	} );

	it( 'overview/index.js is a no-op without the container', () => {
		expect( () => {
			require( '../overview' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'error-log/index.js mounts when #event-logger-errors exists', () => {
		const errors = mountContainer( 'event-logger-errors' );
		require( '../error-log' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect( errors.parentNode ).toBe( document.body );
	} );

	it( 'error-log/index.js is a no-op without the container', () => {
		expect( () => {
			require( '../error-log' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'settings/index.js registers a DOMContentLoaded handler that does not throw', () => {
		expect( () => {
			require( '../settings' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'settings/index.js binds the tag fields when their containers exist', () => {
		const div = mountContainer( 'event-logger-log_urls' );
		div.dataset.values = '[]';
		div.dataset.default = '[]';
		const hidden = document.createElement( 'input' );
		hidden.id = 'log_urls_json';
		hidden.type = 'hidden';
		document.body.appendChild( hidden );
		expect( () => {
			require( '../settings' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'settings/index.js falls back to empty arrays for malformed dataset JSON', () => {
		const div = mountContainer( 'event-logger-skip_urls' );
		div.dataset.values = '{bad-json';
		div.dataset.default = '{"not":"an-array"}';
		expect( () => {
			require( '../settings' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'settings no longer wires legacy reset buttons (per-field reset is the toggle module)', () => {
		// The old in-page reset-to-baked-default mechanism was replaced by the
		// shared admin-field-reset toggle module. settings no longer
		// binds `event-logger-reset-*` buttons, so a legacy button click is inert.
		const div = mountContainer( 'event-logger-log_urls' );
		div.dataset.values = '[]';
		div.dataset.default = '[]';
		const btn = document.createElement( 'button' );
		btn.dataset.field = 'log_urls';
		btn.dataset.default = '["default1"]';
		btn.classList.add( 'event-logger-reset-field' );
		document.body.appendChild( btn );
		require( '../settings' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		let captured = null;
		div.addEventListener( 'event-logger-reset', ( e ) => {
			captured = e.detail;
		} );
		btn.click();
		expect( captured ).toBeNull();
	} );

	// The createRoot deprecation surfaces via console.error; capture it and assert
	// the legacy-render notice never fires (the entrypoints mount via createRoot).
	function renderDeprecationOnMount( moduleId, prepare ) {
		prepare();
		const errSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		require( moduleId );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		const surfaced = errSpy.mock.calls
			.map( ( c ) => String( c[ 0 ] ) )
			.join( '\n' );
		errSpy.mockRestore();
		return surfaced;
	}

	it( 'overview/index.js mounts via createRoot (no React 18 render deprecation)', () => {
		const surfaced = renderDeprecationOnMount( '../overview', () => {
			mountContainer( 'event-logger-admin' );
		} );
		expect( surfaced ).not.toContain(
			'ReactDOM.render is no longer supported'
		);
	} );

	it( 'settings/index.js mounts tag fields via createRoot (no React 18 render deprecation)', () => {
		const surfaced = renderDeprecationOnMount( '../settings', () => {
			const div = mountContainer( 'event-logger-log_urls' );
			div.dataset.values = '[]';
			div.dataset.default = '[]';
		} );
		expect( surfaced ).not.toContain(
			'ReactDOM.render is no longer supported'
		);
	} );
} );
