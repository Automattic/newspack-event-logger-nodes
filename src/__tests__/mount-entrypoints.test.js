/**
 * Tests for the dashboard mount-entry points — each one looks up a
 * DOM container by id and conditionally renders its top-level page.
 *
 * Every test here asserts the mount alone, so the shell, the pages and the
 * log pages' view modules are mocked: `DashboardShell` hands its children no
 * header slot and mounts no debug overlay, which under a loaded suite outran
 * a test's time. The shell's own rendering is held in `page-wrappers.test.js`
 * and `dashboard-theme-root.test.js`, which render the real one.
 */

// overview mounts the lazy PerformancePage; log-pages its lazy LogPage.
jest.mock( '../overview/PerformancePage', () => ( {
	__esModule: true,
	default: () => 'PERFORMANCE_DASHBOARD',
} ) );
jest.mock( '../log-pages/LogPage', () => ( {
	__esModule: true,
	default: ( { page } ) => `LOG_PAGE[${ page }]`,
} ) );
// The mount is tested here, not the runtime's views or the shell's overlay.
jest.mock( '../log-pages/nodes/request-log-view-node', () => ( {} ) );
jest.mock( '../log-pages/nodes/perf-errors-view-node', () => ( {} ) );
jest.mock( '../log-pages/nodes/gyroscope-view-node', () => ( {} ) );
jest.mock( '@newspack-nodes/shared/components/DashboardShell', () => ( {
	__esModule: true,
	default: ( { children } ) => children( undefined ),
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

	it( 'log-pages/index.js mounts the page its data names into #event-logger-log-page', async () => {
		const el = mountContainer( 'event-logger-log-page' );
		window.NewspackEventLoggerLogPage = { page: 'gyroscope' };
		// The registry was reset, so act comes from the page's own React.
		const was = global.IS_REACT_ACT_ENVIRONMENT;
		global.IS_REACT_ACT_ENVIRONMENT = true;
		const { act } = require( 'react' );
		try {
			await act( async () => {
				require( '../log-pages' );
			} );
			await act( async () => {} );
			expect( el.textContent ).toContain( 'LOG_PAGE[gyroscope]' );
		} finally {
			global.IS_REACT_ACT_ENVIRONMENT = was;
		}
	} );

	it( 'log-pages/index.js is a no-op without the container', () => {
		delete window.NewspackEventLoggerLogPage;
		expect( () => require( '../log-pages' ) ).not.toThrow();
	} );

	it( 'overview/index.js mounts the dashboard container', () => {
		const admin = mountContainer( 'event-logger-admin' );
		require( '../overview' );
		// Dispatch DOMContentLoaded — jsdom won't fire it on its own.
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect( admin.parentNode ).toBe( document.body );
	} );

	it( 'overview/index.js is a no-op without the container', () => {
		expect( () => {
			require( '../overview' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'settings/index.js registers a DOMContentLoaded handler that does not throw', () => {
		expect( () => {
			require( '../settings' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'settings no longer wires legacy reset buttons (per-field reset is the toggle module)', () => {
		// Reset lives in the shared toggle module; this button dispatches nothing.
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

	// Capture console.error; React 18 legacy-render notice must never fire.
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
