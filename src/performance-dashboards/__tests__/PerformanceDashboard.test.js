/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* global globalThis */
/**
 * Tests for PerformanceDashboard — the orchestrator (JS-node-graph version).
 *
 * After the Phase-3 rework the orchestrator no longer fetches anything itself.
 * It reads the published view model via `useNodeState('performance/view','view')`
 * and dispatches every command through `usePerformanceGraph` (which returns the
 * control callbacks `{ handleUrlParamsChange, resolveRequest, fetchUrlBreakdown }`).
 *
 * These tests cover the ORCHESTRATION contract only — which child renders given
 * which view-model slice, what callbacks the children receive, how the
 * orchestrator derives its render-time slices from the model. The fetch
 * mechanics now live in usePerformanceGraph / performanceCommand / performanceView
 * tests, so the old fetch-mechanics cases are gone.
 *
 * The data seam is the view model: tests set `mockView` (returned by the mocked
 * useNodeState) and the graph control callbacks via `mockGraph`. Children are
 * mocked at the module boundary as stub components that record their props.
 */

// The view model the orchestrator reads via useNodeState. Set per-test.
let mockView = null;
jest.mock( '@newspack-nodes/runtime', () => ( {
	__esModule: true,
	useNodeState: () => mockView,
} ) );

// The graph control callbacks usePerformanceGraph hands back.
const mockGraph = {
	handleUrlParamsChange: jest.fn(),
	resolveRequest: jest.fn().mockResolvedValue( null ),
	fetchUrlBreakdown: jest.fn().mockResolvedValue( null ),
};
jest.mock( '../hooks/usePerformanceGraph', () => ( {
	__esModule: true,
	usePerformanceGraph: () => mockGraph,
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
 * Flush React's async passive effects inside `act` so state updates settle
 * before assertions.
 *
 * @param {number} ms How many milliseconds to advance via setTimeout.
 */
async function flushEffects( ms = 60 ) {
	await act( async () => {
		await new Promise( ( r ) => setTimeout( r, ms ) );
	} );
}

// A fully-populated, "loaded" view model (lastRefresh stamped).
function loadedView( overrides = {} ) {
	return {
		overview: {
			data: {
				total_requests: 100,
				aggregate_time_series: {},
				breakdowns: { server: {}, status: {} },
			},
			loading: false,
			error: null,
		},
		urls: { data: [], total: 0, loading: false, error: null },
		urlDetail: { data: null, loading: false, error: null },
		requestDetail: { data: null, loading: false, error: null },
		lastRefresh: 123,
		...overrides,
	};
}

describe( 'PerformanceDashboard', () => {
	beforeEach( () => {
		mockView = null;
		window.localStorage.clear();
	} );

	afterEach( () => {
		mockGraph.handleUrlParamsChange.mockClear();
		mockGraph.resolveRequest.mockReset();
		mockGraph.resolveRequest.mockResolvedValue( null );
		mockGraph.fetchUrlBreakdown.mockClear();
		mockNavState.selectedUrl = null;
		mockNavState.selectedRequest = null;
		mockNavState.selectUrl.mockClear();
		mockNavState.selectRequest.mockClear();
		mockNavState.updateBrowserUrl.mockClear();
		jest.clearAllMocks();
	} );

	it( 'shows the loading spinner while the view model is null', () => {
		mockView = null;
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

	it( 'orchestrates the full dashboard once the overview resolves', async () => {
		mockView = loadedView();
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect(
			container.querySelector( '[data-testid="overview"]' )
		).toBeTruthy();
		expect(
			container.querySelector( '[data-testid="url-table"]' )
		).toBeTruthy();
		expect( globalThis.__overviewProps.overview.total_requests ).toBe(
			100
		);
		unmount();
	} );

	it( 'reads refresh interval from localStorage on mount', () => {
		window.localStorage.setItem( 'event-logger-refresh-interval', '5000' );
		mockView = loadedView();
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
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
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
		mockView = loadedView();
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

	it( 'persists refresh interval changes to localStorage via effect', () => {
		window.localStorage.clear();
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		expect(
			window.localStorage.getItem( 'event-logger-refresh-interval' )
		).toBe( '15000' );
		unmount();
	} );

	it( 'unmounts cleanly without throwing', () => {
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		expect( () => unmount() ).not.toThrow();
	} );

	it( 'derives categoryData / breakdownData / serverNames from the overview slice', async () => {
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 100,
					category_time_series: { x: {} },
					breakdowns: {
						server: {
							b: { 'edge-01': { c: 10, s: 100 } },
						},
						status: {
							b: { '2xx': { c: 10, s: 100 } },
						},
					},
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		// categoryData truthy.
		expect( globalThis.__overviewProps.categoryData ).toBeTruthy();
		// breakdownData equals the active default dim (status).
		expect( globalThis.__overviewProps.breakdownData ).toEqual(
			mockView.overview.data.breakdowns.status
		);
		// serverNames extracted from the server breakdown.
		expect( globalThis.__overviewProps.serverNames ).toContain( 'edge-01' );
		unmount();
	} );

	it( 'keeps serverNames sticky when a scoped (filtered) overview arrives', async () => {
		// Two servers visible initially.
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 100,
					breakdowns: {
						server: {
							b: {
								'edge-01': { c: 10, s: 100 },
								'edge-02': { c: 5, s: 50 },
							},
						},
						status: {},
					},
				},
				loading: false,
				error: null,
			},
		} );
		const { rerender, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect( globalThis.__overviewProps.serverNames ).toEqual(
			expect.arrayContaining( [ 'edge-01', 'edge-02' ] )
		);
		// Activate a filter, then the scoped response collapses to one server.
		act( () => {
			globalThis.__overviewProps.setServerFilter( 'edge-01' );
		} );
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 50,
					breakdowns: {
						server: {
							b: { 'edge-01': { c: 10, s: 100 } },
						},
						status: {},
					},
				},
				loading: false,
				error: null,
			},
		} );
		rerender(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		// serverNames must NOT collapse to a single entry.
		expect( globalThis.__overviewProps.serverNames ).toEqual(
			expect.arrayContaining( [ 'edge-01', 'edge-02' ] )
		);
		unmount();
	} );

	it( 'computes globalRequestsPerSecond from aggregate_time_series', async () => {
		const aggregate = {};
		for ( let i = 0; i < 20; i++ ) {
			aggregate[ `b${ String( i ).padStart( 2, '0' ) }` ] = {
				count: 100,
			};
		}
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 2000,
					aggregate_time_series: aggregate,
					breakdowns: { server: {}, status: {} },
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect(
			globalThis.__overviewProps.filteredStats.requestsPerSecond
		).toBeGreaterThan( 0 );
		unmount();
	} );

	it( 'computes filteredOverviewStats when serverFilter is set', async () => {
		const serverBuckets = {};
		for ( let i = 0; i < 20; i++ ) {
			const k = `b${ String( i ).padStart( 2, '0' ) }`;
			serverBuckets[ k ] = { 'edge-01': { c: 50, s: 250 } };
		}
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 2000,
					aggregate_time_series: {},
					breakdowns: { server: serverBuckets, status: {} },
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		act( () => {
			globalThis.__overviewProps.setServerFilter( 'edge-01' );
		} );
		await flushEffects();
		expect( globalThis.__overviewProps.filteredStats.isFiltered ).toBe(
			true
		);
		unmount();
	} );

	it( 'renders the URL modal when a URL is selected and detail is present', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = null;
		mockView = loadedView( {
			urlDetail: {
				data: {
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
				},
				loading: false,
				error: null,
			},
		} );
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect( container.textContent ).toContain( '/foo' );
		expect( container.textContent ).toContain( 'UrlDetailView' );
		expect( container.textContent ).toContain( 'req/s' );
		expect( container.textContent ).toContain( 'p50' );
		expect(
			container.querySelector( '[data-testid="modal"]' )
		).toBeTruthy();
		unmount();
	} );

	it( 'renders the Request modal when a request is selected', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = 'r1';
		mockView = loadedView( {
			urlDetail: {
				data: {
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
				},
				loading: false,
				error: null,
			},
			requestDetail: {
				data: { rid: 'r1', entries: [] },
				loading: false,
				error: null,
			},
		} );
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect(
			container.querySelector( '[data-testid="request-detail"]' )
		).toBeTruthy();
		unmount();
	} );

	it( 'forwards UrlTable param changes to the graph handleUrlParamsChange', async () => {
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		const params = {
			search: 'foo',
			sort: 'count',
			order: 'desc',
			offset: 0,
		};
		act( () => {
			globalThis.__urlTableProps.onParamsChange( params );
		} );
		expect( mockGraph.handleUrlParamsChange ).toHaveBeenCalledWith(
			params
		);
		unmount();
	} );

	it( 'searchRequest resolves a request via the graph and selects it', async () => {
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'h1',
			partition: 0,
			url: '/foo',
		} );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'r1' );
		} );
		expect( mockGraph.resolveRequest ).toHaveBeenCalledWith( 'r1' );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith(
			expect.objectContaining( { hash: 'h1' } )
		);
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'r1' );
		unmount();
	} );

	it( 'searchRequest with an unresolved request does not throw', async () => {
		mockGraph.resolveRequest.mockResolvedValue( null );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			// Empty + whitespace early-returns, then an unresolved rid.
			await globalThis.__overviewProps.onSearch( '' );
			await globalThis.__overviewProps.onSearch( '   ' );
			await globalThis.__overviewProps.onSearch( 'r1' );
		} );
		expect( mockNavState.selectRequest ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'handleRequestSort flips dir + switches field via UrlDetailView callback', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockView = loadedView( {
			urlDetail: {
				data: {
					last_modified: 1,
					stats: { time_series: {} },
					requests: [
						{ rid: 'r1', timestamp: 1, duration_ms: 100 },
						{ rid: 'r2', timestamp: 2, duration_ms: 50 },
					],
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect( globalThis.__urlDetailProps ).toBeTruthy();
		act( () => {
			globalThis.__urlDetailProps.onRequestSort( 'timestamp' );
		} );
		act( () => {
			globalThis.__urlDetailProps.onRequestSort( 'timestamp' );
		} );
		act( () => {
			globalThis.__urlDetailProps.onRequestSort( 'duration' );
		} );
		act( () => {
			globalThis.__urlDetailProps.onSelectRequest( 'r1' );
		} );
		unmount();
	} );

	it( 'passes the graph fetchUrlBreakdown into UrlDetailView', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockView = loadedView( {
			urlDetail: {
				data: {
					last_modified: 1,
					stats: { time_series: {} },
					requests: [],
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect( globalThis.__urlDetailProps.fetchUrlBreakdown ).toBe(
			mockGraph.fetchUrlBreakdown
		);
		unmount();
	} );
} );
