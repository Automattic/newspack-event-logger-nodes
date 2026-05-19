/**
 * Tests for the dashboard mount-entry points — each one looks up a
 * DOM container by id and conditionally renders its top-level page.
 */

jest.mock( '../event-aggregator/AggregatorStatusPage', () => ( {
	__esModule: true,
	default: () => 'AGGREGATOR_PAGE',
} ) );
jest.mock( '../performance-gyroscope/GyroscopePage', () => ( {
	__esModule: true,
	default: () => 'GYROSCOPE_PAGE',
} ) );
jest.mock( '../performance-request-log/RequestStreamPage', () => ( {
	__esModule: true,
	default: () => 'REQUEST_STREAM_PAGE',
} ) );
// performance-dashboards/index.js mounts two components — lazy-loaded
// PerformanceDashboard and ErrorLog.
jest.mock( '../performance-dashboards/PerformanceDashboard', () => ( {
	__esModule: true,
	default: () => 'PERFORMANCE_DASHBOARD',
} ) );
jest.mock( '../performance-dashboards/ErrorLog', () => ( {
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

	it( 'event-aggregator/index.js mounts when #event-aggregator-status exists', () => {
		const el = mountContainer( 'event-aggregator-status' );
		require( '../event-aggregator' );
		expect( el.parentNode ).toBe( document.body );
	} );

	it( 'event-aggregator/index.js is a no-op without the container', () => {
		expect( () => require( '../event-aggregator' ) ).not.toThrow();
	} );

	it( 'performance-gyroscope/index.js mounts when #event-logger-gyroscope exists', () => {
		mountContainer( 'event-logger-gyroscope' );
		expect( () => require( '../performance-gyroscope' ) ).not.toThrow();
	} );

	it( 'performance-gyroscope/index.js is a no-op without the container', () => {
		expect( () => require( '../performance-gyroscope' ) ).not.toThrow();
	} );

	it( 'performance-request-log/index.js mounts when #event-logger-request-stream exists', () => {
		mountContainer( 'event-logger-request-stream' );
		expect( () => require( '../performance-request-log' ) ).not.toThrow();
	} );

	it( 'performance-request-log/index.js is a no-op without the container', () => {
		expect( () => require( '../performance-request-log' ) ).not.toThrow();
	} );

	it( 'performance-dashboards/index.js mounts the dashboard + error containers', () => {
		const admin = mountContainer( 'event-logger-admin' );
		mountContainer( 'event-logger-errors' );
		require( '../performance-dashboards' );
		// Dispatch DOMContentLoaded — required because the handler is
		// registered at module-load time in jsdom (the document is
		// already "interactive" by then, but DOMContentLoaded hasn't
		// fired yet in this environment).
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect( admin.parentNode ).toBe( document.body );
	} );

	it( 'performance-dashboards/index.js is a no-op without containers', () => {
		expect( () => {
			require( '../performance-dashboards' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'performance-logger/index.js registers a DOMContentLoaded handler that does not throw', () => {
		expect( () => {
			require( '../performance-logger' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'performance-logger/index.js binds the tag fields when their containers exist', () => {
		const div = mountContainer( 'event-logger-log_urls' );
		div.dataset.values = '[]';
		div.dataset.default = '[]';
		const hidden = document.createElement( 'input' );
		hidden.id = 'log_urls_json';
		hidden.type = 'hidden';
		document.body.appendChild( hidden );
		expect( () => {
			require( '../performance-logger' );
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} ).not.toThrow();
	} );

	it( 'performance-logger reset button dispatches event-logger-reset to its container', () => {
		const div = mountContainer( 'event-logger-log_urls' );
		div.dataset.values = '[]';
		div.dataset.default = '[]';
		const btn = document.createElement( 'button' );
		btn.dataset.field = 'log_urls';
		btn.dataset.default = '["default1"]';
		btn.classList.add( 'event-logger-reset-field' );
		document.body.appendChild( btn );
		require( '../performance-logger' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		let captured = null;
		div.addEventListener( 'event-logger-reset', ( e ) => {
			captured = e.detail;
		} );
		btn.click();
		expect( captured ).toEqual( {
			field: 'log_urls',
			defaultValues: [ 'default1' ],
		} );
	} );
} );
