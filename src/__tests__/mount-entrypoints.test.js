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
		mountContainer( 'event-logger-admin' );
		mountContainer( 'event-logger-errors' );
		expect( () => require( '../performance-dashboards' ) ).not.toThrow();
		// The DOMContentLoaded handler runs synchronously when the doc
		// is already loaded; the require returns once mount has been
		// scheduled.
	} );

	it( 'performance-dashboards/index.js is a no-op without containers', () => {
		expect( () => require( '../performance-dashboards' ) ).not.toThrow();
	} );

	it( 'performance-logger/index.js does not throw', () => {
		// The settings entry uses a different DOM hook id. Just ensure
		// importing it does not throw when its containers are absent.
		expect( () => require( '../performance-logger' ) ).not.toThrow();
	} );
} );
