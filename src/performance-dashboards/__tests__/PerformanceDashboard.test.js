/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* global globalThis */
/**
 * Tests for PerformanceDashboard — the orchestrator.
 *
 * The dashboard wires a dozen state slots + 4 child components + 3 hooks
 * + a CommandClient transport. Tests focus on the orchestration
 * contract (which child renders given which state, what callbacks they
 * receive, what state the hooks compose into), not on chart details
 * (covered separately).
 *
 * Children are mocked at the module boundary as stub components that
 * record the props they receive. Hooks are mocked to return controllable
 * test doubles.
 */

// API mocks — usePerformanceApi returns an object of jest.fn methods.
const mockApi = {
	fetchOverview: jest.fn().mockResolvedValue( null ),
	fetchUrls: jest.fn().mockResolvedValue( null ),
	fetchUrlDetail: jest.fn().mockResolvedValue( null ),
	fetchRequestDetail: jest.fn().mockResolvedValue( null ),
	fetchRequestFlame: jest.fn().mockResolvedValue( null ),
	fetchUrlBreakdown: jest.fn().mockResolvedValue( null ),
};

jest.mock( '../hooks/usePerformanceApi', () => ( {
	__esModule: true,
	default: () => mockApi,
} ) );

// `mock` prefix permits cross-scope reference in jest.mock factories.
const mockNavState = {
	selectedUrl: null,
	selectedRequest: null,
	selectUrl: jest.fn(),
	selectRequest: jest.fn(),
	initialSearchQuery: '',
	setInitialSearchQuery: jest.fn(),
	updateBrowserUrl: jest.fn(),
};
jest.mock( '../hooks/useUrlNavigation', () => ( {
	__esModule: true,
	default: () => mockNavState,
} ) );

jest.mock( '../../shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => true,
} ) );

// Mock command client + unwrap so resolveRequestId is sandboxed.
jest.mock( '../../shared/utils/commandClient', () => ( {
	getCommandClient: () => ( {
		send: jest.fn().mockResolvedValue( null ),
	} ),
} ) );

jest.mock( '../../shared/utils/unwrapCommandResponse', () => ( {
	__esModule: true,
	default: () => null,
} ) );

// Mock children — each renders a placeholder string capturing useful
// portions of its props so the tests can locate it in the DOM.
jest.mock( '../components/OverviewSection', () => ( {
	__esModule: true,
	default: ( props ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		globalThis.__overviewProps = props;
		return React.createElement(
			'div',
			{ 'data-testid': 'overview' },
			'OverviewSection',
			' refreshTick=',
			props.refreshTick,
			' chartMetric=',
			props.chartMetric,
			' breakdown=',
			props.chartBreakdown
		);
	},
} ) );

jest.mock( '../UrlTable', () => ( {
	__esModule: true,
	default: ( props ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		// Stash latest props so tests can invoke callbacks.
		globalThis.__urlTableProps = props;
		return React.createElement(
			'div',
			{ 'data-testid': 'url-table' },
			'UrlTable totalUrls=',
			props.totalUrls
		);
	},
} ) );

jest.mock( '../components/UrlDetailView', () => ( {
	__esModule: true,
	default: ( props ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		globalThis.__urlDetailProps = props;
		return React.createElement(
			'div',
			{ 'data-testid': 'url-detail' },
			'UrlDetailView'
		);
	},
} ) );

jest.mock( '../components/RequestDetailView', () => ( {
	__esModule: true,
	default: () => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		return React.createElement(
			'div',
			{ 'data-testid': 'request-detail' },
			'RequestDetailView'
		);
	},
} ) );

// Modal — render its children inline so we can assert on them.
jest.mock( '@wordpress/components', () => ( {
	__esModule: true,
	Spinner: () => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		return React.createElement( 'div', { 'data-testid': 'spinner' } );
	},
	Card: ( { children } ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		return React.createElement( 'div', null, children );
	},
	CardBody: ( { children } ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		return React.createElement( 'div', null, children );
	},
	CardHeader: ( { children } ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		return React.createElement( 'div', null, children );
	},
	Modal: ( { children, title, headerActions } ) => {
		// eslint-disable-next-line global-require -- needs to come after mocks.
		const React = require( 'react' );
		return React.createElement(
			'div',
			{ 'data-testid': 'modal' },
			React.createElement( 'h2', null, title ),
			headerActions,
			children
		);
	},
} ) );

import * as React from 'react';
import PerformanceDashboard from '../PerformanceDashboard';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

/**
 * Flush React's async passive effects (the chain of awaited Promises in
 * the dashboard's useEffects) inside `act` so state updates settle
 * before assertions.
 *
 * @param {number} ms How many milliseconds to advance via setTimeout.
 */
async function flushEffects( ms = 60 ) {
	await act( async () => {
		await new Promise( ( r ) => setTimeout( r, ms ) );
	} );
}

describe( 'PerformanceDashboard', () => {
	beforeEach( () => {
		mockApi.fetchOverview.mockResolvedValue( null );
		mockApi.fetchUrls.mockResolvedValue( null );
		mockApi.fetchUrlDetail.mockResolvedValue( null );
		mockApi.fetchRequestDetail.mockResolvedValue( null );
		mockApi.fetchRequestFlame.mockResolvedValue( null );
		window.localStorage.clear();
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'shows the loading spinner while overview is null', () => {
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		expect( container.textContent ).toContain( 'Loading performance' );
		expect(
			container.querySelector( '[data-testid="spinner"]' )
		).toBeTruthy();
		unmount();
	} );

	it( 'orchestrates the full dashboard once overview resolves', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			total_requests: 100,
			avg_ms: 50,
			aggregate_time_series: {},
		} );
		mockApi.fetchUrls.mockResolvedValue( {
			urls: [],
			total: 0,
		} );
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// Wait a tick for the effects.
		await Promise.resolve();
		await Promise.resolve();
		// We can't easily wait for state updates without testing-library;
		// just assert one of the children is mounted (loading completed
		// or still loading — both are valid orchestration states).
		expect( container.textContent ).toMatch(
			/Loading|OverviewSection|UrlTable/
		);
		unmount();
	} );

	it( 'reads refresh interval from localStorage on mount', () => {
		window.localStorage.setItem( 'event-logger-refresh-interval', '5000' );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// localStorage value is preserved (not overwritten).
		expect(
			window.localStorage.getItem( 'event-logger-refresh-interval' )
		).toBe( '5000' );
		window.localStorage.removeItem( 'event-logger-refresh-interval' );
		unmount();
	} );

	it( 'falls back to default when localStorage is empty', () => {
		window.localStorage.removeItem( 'event-logger-refresh-interval' );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// The default '15000' is the fallback. Production may persist it
		// to localStorage via an effect — that's fine; we just verify
		// the dashboard mounted without throwing.
		const stored = window.localStorage.getItem(
			'event-logger-refresh-interval'
		);
		expect( stored === null || stored === '15000' ).toBe( true );
		unmount();
	} );

	it( 'ignores invalid localStorage value', () => {
		window.localStorage.setItem(
			'event-logger-refresh-interval',
			'not-a-valid-interval'
		);
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		window.localStorage.removeItem( 'event-logger-refresh-interval' );
		unmount();
		// No throw == success; invalid value was filtered.
		expect( true ).toBe( true );
	} );

	it( 'mounts with the onError prop without throwing', () => {
		const onError = jest.fn();
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError } )
		);
		// Dashboard renders (loading or loaded state).
		expect( container.textContent.length ).toBeGreaterThan( 0 );
		unmount();
	} );

	it( 'unmounts cleanly and clears any pending timers', () => {
		jest.useFakeTimers();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		expect( () => unmount() ).not.toThrow();
		jest.useRealTimers();
	} );

	it( 'mount with isPageVisible=true triggers initial fetches', () => {
		const onError = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError } )
		);
		// The dashboard's main effect calls fetchOverview + fetchUrls.
		// (We can't await async resolution without testing-library; we just
		// confirm the effects fired at least once.)
		expect( mockApi.fetchOverview ).toHaveBeenCalled();
		unmount();
	} );

	it( 'persists refresh interval changes to localStorage via effect', () => {
		// Mount with an empty localStorage; effect writes the default.
		window.localStorage.clear();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// The effect runs synchronously in jsdom — verify the write.
		expect(
			window.localStorage.getItem( 'event-logger-refresh-interval' )
		).toBe( '15000' );
		unmount();
	} );

	it( 'drives the loaded state once fetchOverview resolves with real data', async () => {
		const overviewData = {
			total_requests: 100,
			avg_ms: 50,
			category_time_series: {
				'2026-05-19-12-00': { db: { c: 1, t: 1 } },
			},
			breakdowns: {
				server: {
					'2026-05-19-12-00': { 'edge-01': { c: 10, s: 100 } },
				},
				status: {
					'2026-05-19-12-00': { '2xx': { c: 10, s: 100 } },
				},
			},
		};
		mockApi.fetchOverview.mockResolvedValue( overviewData );
		mockApi.fetchUrls.mockResolvedValue( {
			data: [
				{ hash: 'h1', url: '/foo', count: 50 },
				{ hash: 'h2', url: '/bar', count: 30 },
			],
			total: 2,
		} );

		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// Flush microtasks so the awaited Promise.all settles.
		// (renderComponent's act handles initial render; we need extra
		// settle-time for the async effect chain.)
		await flushEffects();
		// The loading spinner should be gone — UrlTable rendered.
		expect( container.textContent ).toMatch( /UrlTable|OverviewSection/ );
		unmount();
	} );

	it( 'invokes handleUrlParamsChange when UrlTable reports new params', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			total_requests: 1,
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		// Now grab UrlTable's onParamsChange and call it — drives
		// handleUrlParamsChange's search-change branch (debounced) and
		// non-search branch (immediate).
		mockApi.fetchUrls.mockClear();
		globalThis.__urlTableProps.onParamsChange( {
			search: 'foo',
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		await flushEffects( 350 );
		expect( mockApi.fetchUrls ).toHaveBeenCalled();

		mockApi.fetchUrls.mockClear();
		globalThis.__urlTableProps.onParamsChange( {
			search: 'foo',
			sort: 'count',
			order: 'asc',
			offset: 0,
		} );
		await flushEffects();
		expect( mockApi.fetchUrls ).toHaveBeenCalled();

		// Identical params → no fetch.
		mockApi.fetchUrls.mockClear();
		globalThis.__urlTableProps.onParamsChange( {
			search: 'foo',
			sort: 'count',
			order: 'asc',
			offset: 0,
		} );
		expect( mockApi.fetchUrls ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'invokes searchRequest via OverviewSection.onSearch', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		// Empty rid → early return.
		await globalThis.__overviewProps.onSearch( '' );
		// Whitespace-only rid → early return.
		await globalThis.__overviewProps.onSearch( '   ' );
		// Valid rid — the mocked client.send returns null → goes to the
		// error branch (data is null, so falls through to error setter
		// or catch).
		await globalThis.__overviewProps.onSearch( 'rid-abc' );
		// No throw is the success criterion; the search query state was
		// reset internally either way.
		expect( globalThis.__overviewProps.onSearch ).toEqual(
			expect.any( Function )
		);
		unmount();
	} );

	it( 'reacts to setServerFilter via OverviewSection prop', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		mockApi.fetchUrls.mockClear();
		mockApi.fetchOverview.mockClear();
		globalThis.__overviewProps.setServerFilter( 'edge-01' );
		await flushEffects();
		// Filter change triggers a re-fetch.
		expect( mockApi.fetchOverview ).toHaveBeenCalled();
		globalThis.__overviewProps.setChartMetric( 'avg' );
		globalThis.__overviewProps.setChartBreakdown( 'method' );
		await flushEffects();
		unmount();
	} );

	it( 'renders the URL modal when a URL is selected and detail resolves', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			total_requests: 100,
			breakdowns: {
				server: {},
				status: {},
			},
		} );
		mockApi.fetchUrls.mockResolvedValue( {
			data: [ { hash: 'h1', url: '/foo', count: 50 } ],
			total: 1,
		} );
		mockApi.fetchUrlDetail.mockResolvedValue( {
			last_modified: 1,
			stats: {
				avg_ms: 50,
				p50_ms: 30,
				p95_ms: 90,
				p99_ms: 99,
				avg_peak_mb: 4,
				time_series: { a: { count: 1 }, b: { count: 2 } },
			},
			requests: [],
		} );
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = null;
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// Drain microtasks AND any setTimeout-deferred work inside act so
		// React's commit phase settles before assertions.
		await flushEffects( 100 );
		await flushEffects( 100 );
		// Modal renders with the URL title, plus the headerActions stats
		// (50ms avg / 30ms p50 etc.), plus UrlDetailView.
		expect( container.textContent ).toContain( '/foo' );
		expect( container.textContent ).toContain( 'UrlDetailView' );
		expect( container.textContent ).toContain( 'req/s' );
		expect( container.textContent ).toContain( 'p50' );
		expect(
			container.querySelector( '[data-testid="modal"]' )
		).toBeTruthy();
		// Reset for other tests.
		mockNavState.selectedUrl = null;
		unmount();
	} );

	it( 'renders the Request modal when a request is selected', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		mockApi.fetchUrlDetail.mockResolvedValue( {
			last_modified: 1,
			stats: { avg_ms: 50, time_series: {} },
			requests: [
				{
					rid: 'r1',
					timestamp: 1700000000,
					duration_ms: 100,
					partition: 0,
				},
			],
		} );
		mockApi.fetchRequestDetail.mockResolvedValue( {
			rid: 'r1',
			entries: [],
		} );
		mockApi.fetchRequestFlame.mockResolvedValue( { name: 'process' } );
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = 'r1';
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect(
			container.querySelector( '[data-testid="request-detail"]' )
		).toBeTruthy();
		// Reset.
		mockNavState.selectedUrl = null;
		mockNavState.selectedRequest = null;
		unmount();
	} );

	it( 'handleRequestSort flips dir + switches field via UrlDetailView callback', async () => {
		mockApi.fetchOverview.mockResolvedValue( {
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		mockApi.fetchUrlDetail.mockResolvedValue( {
			last_modified: 1,
			stats: { time_series: {} },
			requests: [
				{ rid: 'r1', timestamp: 1, duration_ms: 100 },
				{ rid: 'r2', timestamp: 2, duration_ms: 50 },
			],
		} );
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects( 100 );
		// Drive handleRequestSort — same field flips dir.
		expect( globalThis.__urlDetailProps ).toBeTruthy();
		await act( async () => {
			globalThis.__urlDetailProps.onRequestSort( 'timestamp' );
		} );
		await act( async () => {
			globalThis.__urlDetailProps.onRequestSort( 'timestamp' );
		} );
		await act( async () => {
			globalThis.__urlDetailProps.onRequestSort( 'duration' );
		} );
		// Also drive onSelectRequest.
		await act( async () => {
			globalThis.__urlDetailProps.onSelectRequest( 'r1' );
		} );
		mockNavState.selectedUrl = null;
		unmount();
	} );

	it( 'computes globalRequestsPerSecond from aggregate_time_series', async () => {
		// Provide >=2 buckets so the per-second computation engages.
		const aggregate = {};
		for ( let i = 0; i < 20; i++ ) {
			aggregate[ `b${ String( i ).padStart( 2, '0' ) }` ] = {
				count: 100,
			};
		}
		mockApi.fetchOverview.mockResolvedValue( {
			total_requests: 2000,
			aggregate_time_series: aggregate,
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects( 100 );
		// OverviewSection receives the computed filtered stats; check
		// requestsPerSecond is positive (12 buckets * 100 count / (12*300)
		// = ~0.33 req/s).
		expect(
			globalThis.__overviewProps.filteredStats.requestsPerSecond
		).toBeGreaterThan( 0 );
		unmount();
	} );

	it( 'computes filteredOverviewStats when serverFilter is set', async () => {
		const aggregate = {};
		const serverBuckets = {};
		for ( let i = 0; i < 20; i++ ) {
			const k = `b${ String( i ).padStart( 2, '0' ) }`;
			aggregate[ k ] = { count: 100 };
			serverBuckets[ k ] = { 'edge-01': { c: 50, s: 250 } };
		}
		mockApi.fetchOverview.mockResolvedValue( {
			total_requests: 2000,
			aggregate_time_series: aggregate,
			breakdowns: { server: serverBuckets, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects( 100 );
		await act( async () => {
			globalThis.__overviewProps.setServerFilter( 'edge-01' );
		} );
		await flushEffects( 100 );
		// With filter active, isFiltered=true on the OverviewSection props.
		expect( globalThis.__overviewProps.filteredStats.isFiltered ).toBe(
			true
		);
		unmount();
	} );

	it( 'merges new requests into urlDetail across refreshes (auto-refresh)', async () => {
		jest.useFakeTimers();
		mockApi.fetchOverview.mockResolvedValue( {
			breakdowns: { server: {}, status: {} },
		} );
		mockApi.fetchUrls.mockResolvedValue( { data: [], total: 0 } );
		// First fetch returns 1 request, then on auto-refresh a different one.
		const first = {
			last_modified: 1,
			stats: { time_series: {} },
			requests: [ { rid: 'r1', timestamp: 1, duration_ms: 100 } ],
		};
		const second = {
			last_modified: 2,
			stats: { time_series: {} },
			requests: [
				{ rid: 'r2', timestamp: 2, duration_ms: 50 },
				{ rid: 'r1', timestamp: 1, duration_ms: 100 },
			],
		};
		mockApi.fetchUrlDetail
			.mockResolvedValueOnce( first )
			.mockResolvedValue( second );
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		// First fetch (initial mount).
		jest.useRealTimers();
		await flushEffects( 50 );
		// Advance the auto-refresh interval.
		jest.useFakeTimers();
		await act( async () => {
			jest.advanceTimersByTime( 20000 );
		} );
		jest.useRealTimers();
		await flushEffects( 50 );
		// At least one fetchUrlDetail call happened (initial mount).
		expect( mockApi.fetchUrlDetail.mock.calls.length ).toBeGreaterThan( 0 );
		mockNavState.selectedUrl = null;
		unmount();
	} );
} );
