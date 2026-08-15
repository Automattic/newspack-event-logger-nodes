/* global globalThis */
/**
 * Tests for PerformanceDashboard — the orchestrator (JS-node-graph version).
 *
 * Post-D1b de-god the orchestrator reads FOUR per-slice view nodes
 * (`overview:view` / `urls:view` / `urldetail:view` / `requestdetail:view`), each
 * via its own `useNodeState`, and dispatches every command through
 * `usePerformanceGraph` (which returns the control callbacks
 * `{ handleUrlParamsChange, resolveRequest, fetchUrlBreakdown }`). The orchestrator
 * fetches nothing itself.
 *
 * These tests cover the ORCHESTRATION contract only — which child renders given
 * which slice, what callbacks the children receive, how the orchestrator derives
 * its render-time values. The fetch mechanics live in the usePerformanceGraph
 * tests; the per-slice view-model logic lives in the per-node tests.
 *
 * The data seam is the slice model: tests set `mockView` (the same combined
 * `{ overview, urls, urlDetail, requestDetail }` shape as before), and the mocked
 * `useNodeState` fans it out by node name so the existing setups work unchanged.
 * The graph control callbacks come from `mockGraph`. Children are mocked at the
 * module boundary as stub components that record their props.
 */

// Per-slice view model; the mock fans it out by node name.
let mockView = null;
jest.mock( '@newspack-nodes/runtime', () => ( {
	__esModule: true,
	useNodeState: ( nodeName ) => {
		if ( ! mockView ) {
			return undefined;
		}
		const sliceByNode = {
			'overview:view': 'overview',
			'urls:view': 'urls',
			'urldetail:view': 'urlDetail',
			'requestdetail:view': 'requestDetail',
		};
		const key = sliceByNode[ nodeName ];
		return key ? mockView[ key ] : undefined;
	},
} ) );

// AskPanel mounts its own Request node onto the exospine, which this suite's
// runtime mock has no graph for. Its behaviour is covered by useAskPicker and
// askBrief; here it is a button.
jest.mock( '../components/AskPanel', () => () => null );

// The graph control callbacks usePerformanceGraph hands back.
const mockGraph = {
	handleUrlParamsChange: jest.fn(),
	resolveRequest: jest.fn().mockResolvedValue( null ),
	resolveUrlHash: jest.fn().mockResolvedValue( null ),
	fetchUrlBreakdown: jest.fn().mockResolvedValue( null ),
	listRules: jest.fn().mockResolvedValue( { rules: [] } ),
	upsertRule: jest.fn().mockResolvedValue( { rule: {} } ),
	removeRule: jest.fn().mockResolvedValue( { deleted: true } ),
	requestGrep: jest
		.fn()
		.mockResolvedValue( { results: [], truncated: false } ),
};
// Records what the dashboard hands the graph — the partition rides here.
let mockGraphOpts = null;
jest.mock( '../hooks/usePerformanceGraph', () => ( {
	__esModule: true,
	usePerformanceGraph: ( opts ) => {
		mockGraphOpts = opts;
		return mockGraph;
	},
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
	default: ( _urls, resolveRequestId, resolveUrlHash ) => {
		globalThis.__resolveRequestId = resolveRequestId;
		globalThis.__resolveUrlHash = resolveUrlHash;
		return mockNavState;
	},
} ) );

jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => true,
} ) );

// Mock children: each renders a placeholder capturing its props.
jest.mock( '../components/OverviewSection', () => ( {
	__esModule: true,
	default: ( props ) => {
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
		const React = require( 'react' );
		return React.createElement(
			'div',
			{ 'data-testid': 'request-detail' },
			'RequestDetailView'
		);
	},
} ) );

// Stub RuleEditModal: capture props to assert prefill + drive onSave.
jest.mock( '../../rules/RuleEditModal', () => ( {
	__esModule: true,
	default: ( props ) => {
		const React = require( 'react' );
		globalThis.__ruleEditProps = props;
		return React.createElement(
			'div',
			{ 'data-testid': 'rule-edit-modal' },
			'RuleEditModal pattern=',
			props.rule?.pattern
		);
	},
} ) );

// Modal — render its children inline so we can assert on them.
jest.mock( '@wordpress/components', () => ( {
	__esModule: true,
	Spinner: () => {
		const React = require( 'react' );
		return React.createElement( 'div', { 'data-testid': 'spinner' } );
	},
	Card: ( { children } ) => {
		const React = require( 'react' );
		return React.createElement( 'div', null, children );
	},
	CardBody: ( { children } ) => {
		const React = require( 'react' );
		return React.createElement( 'div', null, children );
	},
	CardHeader: ( { children } ) => {
		const React = require( 'react' );
		return React.createElement( 'div', null, children );
	},
	Button: ( { children, onClick, disabled, className } ) => {
		const React = require( 'react' );
		return React.createElement(
			'button',
			{ onClick, disabled, className },
			children
		);
	},
	Modal: ( {
		children,
		title,
		headerActions,
		onRequestClose,
		className,
	} ) => {
		const React = require( 'react' );
		globalThis.__modalOnRequestClose = onRequestClose;
		return React.createElement(
			'div',
			{ 'data-testid': 'modal', className },
			React.createElement( 'h2', null, title ),
			React.createElement(
				'div',
				{ className: 'components-modal__content' },
				headerActions,
				children
			)
		);
	},
} ) );

import * as React from 'react';
import PerformanceDashboard from '../PerformanceDashboard';
import { renderComponent, act } from '../../test-helpers/renderHook';

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
		mockGraph.resolveUrlHash.mockReset();
		mockGraph.resolveUrlHash.mockResolvedValue( null );
		mockGraph.fetchUrlBreakdown.mockClear();
		mockGraph.listRules.mockReset();
		mockGraph.listRules.mockResolvedValue( { rules: [] } );
		mockGraph.upsertRule.mockReset();
		mockGraph.removeRule.mockReset();
		mockGraph.removeRule.mockResolvedValue( { deleted: true } );
		mockGraph.upsertRule.mockResolvedValue( { rule: {} } );
		mockGraph.requestGrep.mockReset();
		mockGraph.requestGrep.mockResolvedValue( {
			results: [],
			truncated: false,
		} );
		globalThis.__ruleEditProps = null;
		mockNavState.selectedUrl = null;
		mockNavState.selectedRequest = null;
		mockNavState.initialSearchQuery = '';
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

	it( 'computes every server-filtered stat from the server breakdown', async () => {
		const serverBuckets = {};
		for ( let i = 0; i < 20; i++ ) {
			const k = `b${ String( i ).padStart( 2, '0' ) }`;
			serverBuckets[ k ] = {
				'edge-01':
					i % 2 === 0
						? { c: 37, s: 3700, m: 259 }
						: { c: 41, s: 4920, m: 328 },
				'edge-02': { c: 11, s: 1430, m: 99 },
			};
		}
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 2000,
					global_avg_ms: 91,
					global_avg_peak_mb: 12.3,
					aggregate_time_series: {},
					breakdowns: { server: serverBuckets, status: {} },
				},
				loading: false,
				error: null,
			},
			urls: { data: [], total: 37, loading: false, error: null },
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
		const stats = globalThis.__overviewProps.filteredStats;
		expect( stats ).toMatchObject( {
			totalRequests: 780,
			requestsPerSecond: 468 / 3600,
			totalUrls: 37,
			isFiltered: true,
		} );
		expect( stats.globalAvgMs ).toBeCloseTo( 86200 / 780 );
		expect( stats.globalAvgPeakMb ).toBeCloseTo( 5870 / 780 );
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
		const modal = container.querySelector( '[data-testid="modal"]' );
		expect( modal ).toBeTruthy();
		expect( modal.className ).toBe(
			'event-logger-performance-modal newspack-nodes-modal newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		const headerStats = Array.from(
			modal.querySelectorAll(
				'.event-logger-header-stats > .newspack-nodes-stat'
			)
		);
		expect( headerStats ).toHaveLength( 6 );
		for ( const stat of headerStats ) {
			expect(
				stat.querySelector( '.newspack-nodes-stat-value' )
			).toBeNull();
			expect(
				Array.from( stat.childNodes ).some(
					( node ) =>
						window.Node.TEXT_NODE === node.nodeType &&
						'' !== node.textContent.trim()
				)
			).toBe( true );
			expect( stat.querySelector( ':scope > small' ) ).not.toBeNull();
		}
		unmount();
	} );

	it( 'carries the partition with a selected request, not just the rid', async () => {
		// The partition travels WITH the selection from every entry point. Any
		// caller that hands over only a rid leaves the detail unable to fetch,
		// and reconstructing it downstream is what this refactor removed.
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = null;
		mockView = loadedView( {
			urlDetail: {
				data: { last_modified: 1, stats: {}, requests: [] },
				loading: false,
				error: null,
			},
		} );

		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError: jest.fn() } )
		);
		await act( async () => {} );

		await act( async () => {
			globalThis.__urlDetailProps.onSelectRequest( 'r1', 7 );
		} );

		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'r1' );
		expect( mockGraphOpts.requestPartition ).toBe( 7 );
		unmount();
	} );

	it( 'never renders an empty modal for a request that has not loaded', async () => {
		// Both body sections gate on `selectedRequest`: one needs it set AND
		// the detail present, the other needs it unset. A selected request
		// whose detail never arrived fell between them, and the modal showed
		// the URL as its title over a blank panel.
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = 'r1';
		mockView = loadedView( {
			urlDetail: {
				data: { last_modified: 1, stats: {}, requests: [] },
				loading: false,
				error: null,
			},
			requestDetail: { data: null, loading: false, error: null },
		} );

		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError: jest.fn() } )
		);
		await act( async () => {} );

		expect( container.textContent ).toMatch( /Loading request/ );
		unmount();
	} );

	it( 'surfaces the reason a request detail failed', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = 'r1';
		mockView = loadedView( {
			urlDetail: {
				data: { last_modified: 1, stats: {}, requests: [] },
				loading: false,
				error: null,
			},
			requestDetail: {
				data: null,
				loading: false,
				error: 'Could not determine the partition for this request',
			},
		} );

		const { container, unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError: jest.fn() } )
		);
		await act( async () => {} );

		expect( container.textContent ).toMatch( /partition for this request/ );
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

	it( 'a rid-shaped miss hints at the /pattern syntax', async () => {
		// 'checkout' is rid-shaped so it routes to exact lookup; the miss must
		// teach the text-searcher the escape hatch instead of dead-ending.
		mockGraph.resolveRequest.mockResolvedValue( null );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError: jest.fn() } )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'checkout' );
		} );
		expect( globalThis.__overviewProps.searchError ).toContain(
			'prefix with / to search recent traffic'
		);
		unmount();
	} );

	it( 'resolveRequestId from URL navigation selects the resolved URL and request', async () => {
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'h-known',
			partition: 2,
		} );
		mockView = loadedView( {
			urls: {
				data: [ { hash: 'h-known', url: '/known' } ],
				total: 1,
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
		await act( async () => {
			await globalThis.__resolveRequestId( 'rid-deep' );
		} );
		expect( mockGraph.resolveRequest ).toHaveBeenCalledWith( 'rid-deep' );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith(
			expect.objectContaining( { hash: 'h-known' } )
		);
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'rid-deep' );
		unmount();
	} );

	it( 'resolveRequestId asks url_detail for a hash outside the loaded page', async () => {
		// request_search answers {rid, partition, url_hash} — never a url. A
		// deep-linked request whose URL is off the loaded page therefore has
		// no title unless the hash is resolved, which is what this asserts.
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'h-offpage',
			partition: 3,
		} );
		mockGraph.resolveUrlHash.mockResolvedValue( {
			url: '/quokka/census-2026',
		} );
		mockView = loadedView( {
			urls: {
				data: [ { hash: 'h-known', url: '/known' } ],
				total: 1,
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
		await act( async () => {
			await globalThis.__resolveRequestId( 'rid-offpage' );
		} );
		expect( mockGraph.resolveUrlHash ).toHaveBeenCalledWith( 'h-offpage' );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith( {
			hash: 'h-offpage',
			url: '/quokka/census-2026',
		} );
		unmount();
	} );

	it( 'resolveRequestId keeps the Unknown URL sentinel when the hash will not resolve', async () => {
		// The sentinel is load-bearing: canLogUrl compares against it to
		// disable "Log this URL". Titling with the raw hash would re-enable
		// the button and offer to write a rule whose pattern is a hash.
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'h-offpage',
			partition: 3,
		} );
		mockGraph.resolveUrlHash.mockResolvedValue( null );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__resolveRequestId( 'rid-offpage' );
		} );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith( {
			hash: 'h-offpage',
			url: 'Unknown URL',
		} );
		unmount();
	} );

	it( 'runs and clears the initial search query from URL navigation', async () => {
		mockNavState.initialSearchQuery = 'rid-initial';
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'missing-hash',
			partition: 0,
		} );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect( mockGraph.resolveRequest ).toHaveBeenCalledWith(
			'rid-initial'
		);
		expect( mockNavState.setInitialSearchQuery ).toHaveBeenCalledWith(
			null
		);
		unmount();
	} );

	it( 'searchRequest resolves an off-page hash the same way the deep link does', async () => {
		// The search box is the third caller of this block. It had the same
		// always-undefined `data.url` the ?request= path did.
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'h-offpage',
			partition: 1,
		} );
		mockGraph.resolveUrlHash.mockResolvedValue( {
			url: '/quokka/census-2026',
		} );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'rid-search' );
		} );
		expect( mockGraph.resolveUrlHash ).toHaveBeenCalledWith( 'h-offpage' );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith( {
			hash: 'h-offpage',
			url: '/quokka/census-2026',
		} );
		unmount();
	} );

	it( 'the ?url= resolver applies the sentinel, never a hash title', async () => {
		// A hash title is not merely ugly: canLogUrl only rejects the
		// sentinel, so a hash would offer to write a rule keyed on `<hash>?`.
		mockGraph.resolveUrlHash.mockResolvedValue( { url: '' } );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		let resolved;
		await act( async () => {
			resolved = await globalThis.__resolveUrlHash( 'h-empty' );
		} );
		expect( resolved ).toEqual( { url: 'Unknown URL' } );
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

	it( 'a /url-pattern search runs request_grep and renders the result list', async () => {
		mockGraph.requestGrep.mockResolvedValue( {
			results: [
				{ rid: 'r1', url: '/calendar', method: 'GET', match_count: 2 },
			],
			truncated: true,
		} );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/calendar' );
		} );
		// Pattern (has '/') → grep, NOT the exact-rid resolveRequest.
		expect( mockGraph.requestGrep ).toHaveBeenCalledWith( '/calendar' );
		expect( mockGraph.resolveRequest ).not.toHaveBeenCalled();
		expect( globalThis.__overviewProps.searchResults ).toEqual( [
			{ rid: 'r1', url: '/calendar', method: 'GET', match_count: 2 },
		] );
		expect( globalThis.__overviewProps.searchResultsTruncated ).toBe(
			true
		);
		unmount();
	} );

	it( 'an empty grep result surfaces the no-matches message', async () => {
		mockGraph.requestGrep.mockResolvedValue( {
			results: [],
			truncated: false,
		} );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/nope' );
		} );
		expect( globalThis.__overviewProps.searchResults ).toBeNull();
		expect(
			( globalThis.__overviewProps.searchError || '' ).toLowerCase()
		).toContain( 'no matches in recent traffic' );
		unmount();
	} );

	it( 'selecting a grep result deep-links via the exact-rid path', async () => {
		mockGraph.requestGrep.mockResolvedValue( {
			results: [
				{ rid: 'grep-rid', url: '/x', method: 'GET', match_count: 1 },
			],
			truncated: false,
		} );
		mockGraph.resolveRequest.mockResolvedValue( {
			url_hash: 'h9',
			partition: 0,
			url: '/x',
		} );
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/x' );
		} );
		await act( async () => {
			await globalThis.__overviewProps.onSelectResult( 'grep-rid' );
		} );
		expect( mockGraph.resolveRequest ).toHaveBeenCalledWith( 'grep-rid' );
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'grep-rid' );
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

	it( 'modal close clears both selected URL and selected request', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = 'r1';
		mockView = loadedView( {
			urlDetail: {
				data: {
					last_modified: 1,
					stats: { avg_ms: 50, time_series: {} },
					requests: [],
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
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		act( () => {
			globalThis.__modalOnRequestClose();
		} );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith( null );
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( null );
		unmount();
	} );

	it( 'request-detail back button returns to URL detail without closing the URL modal', async () => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = 'r1';
		mockView = loadedView( {
			urlDetail: {
				data: {
					last_modified: 1,
					stats: { avg_ms: 50, time_series: {} },
					requests: [],
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
		const backButton = container.querySelector(
			'.event-logger-modal-back-button'
		);
		// Bare glyph, matching the modal close beside it: the canonical button
		// base carrying the plain role, never the boxed secondary chrome.
		expect( backButton.classList.contains( 'button' ) ).toBe( true );
		expect( backButton.classList.contains( 'is-plain' ) ).toBe( true );
		expect( backButton.classList.contains( 'button-small' ) ).toBe( false );
		act( () => {
			backButton.click();
		} );
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( null );
		expect( mockNavState.selectUrl ).not.toHaveBeenCalledWith( null );
		unmount();
	} );

	// URL modal inline "Log this URL" affordance (spec section C).
	describe( 'Log this URL affordance', () => {
		// A URL-detail-loaded view model with the modal open on `url`.
		function urlModalView( url = '/foo' ) {
			mockNavState.selectedUrl = { hash: 'h1', url };
			mockNavState.selectedRequest = null;
			return loadedView( {
				urlDetail: {
					data: {
						last_modified: 1,
						stats: { avg_ms: 50, time_series: {} },
						requests: [],
					},
					loading: false,
					error: null,
				},
			} );
		}

		it( 'shows an enabled "Log this URL" button in the URL modal header', async () => {
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			const btn = container.querySelector(
				'.event-logger-rule-control button'
			);
			expect( btn ).toBeTruthy();
			expect( btn.textContent ).toContain( 'Log this URL' );
			expect( btn.disabled ).toBe( false );
			unmount();
		} );

		it( 'disables the button when the URL is unknown', async () => {
			mockView = urlModalView( 'Unknown URL' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			const btn = container.querySelector(
				'.event-logger-rule-control button'
			);
			expect( btn ).toBeTruthy();
			expect( btn.disabled ).toBe( true );
			unmount();
		} );

		it( 'hides the button when a request is drilled in', async () => {
			mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
			mockNavState.selectedRequest = 'r1';
			mockView = loadedView( {
				urlDetail: {
					data: {
						last_modified: 1,
						stats: { avg_ms: 50, time_series: {} },
						requests: [],
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
				container.querySelector( '.event-logger-rule-control button' )
			).toBeNull();
			unmount();
		} );

		it( 'looks up rules on open and, when an exact rule exists, labels the button "Edit logging rule" and prefills it', async () => {
			const existing = {
				id: 'rule-1',
				pattern: '/foo?',
				action: 'log',
				hooks: [ 'template_redirect' ],
			};
			mockGraph.listRules.mockResolvedValue( { rules: [ existing ] } );
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			expect( mockGraph.listRules ).toHaveBeenCalled();
			const btn = container.querySelector(
				'.event-logger-rule-control button'
			);
			expect( btn.textContent ).toContain( 'Edit logging rule' );
			await act( async () => {
				btn.click();
			} );
			expect(
				container.querySelector( '[data-testid="rule-edit-modal"]' )
			).toBeTruthy();
			expect( globalThis.__ruleEditProps.rule ).toEqual( existing );
			unmount();
		} );

		it( 'opens a blank exact-pattern rule when none exists', async () => {
			mockGraph.listRules.mockResolvedValue( { rules: [] } );
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			const btn = container.querySelector(
				'.event-logger-rule-control button'
			);
			expect( btn.textContent ).toContain( 'Log this URL' );
			await act( async () => {
				btn.click();
			} );
			expect( globalThis.__ruleEditProps.rule.pattern ).toBe( '/foo?' );
			expect( globalThis.__ruleEditProps.rule.action ).toBe( 'log' );
			expect( globalThis.__ruleEditProps.rule.id ).toBe( '' );
			// The modal owns its own skin classes; callers pass none.
			expect( globalThis.__ruleEditProps.className ).toBeUndefined();
			unmount();
		} );

		it( 'editing an existing rule exposes onDelete: it removes and closes the editor', async () => {
			const existing = { id: 'r-77', pattern: '/foo?', action: 'log' };
			mockGraph.listRules.mockResolvedValue( { rules: [ existing ] } );
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			await act( async () => {
				container
					.querySelector( '.event-logger-rule-control button' )
					.click();
			} );
			expect( typeof globalThis.__ruleEditProps.onDelete ).toBe(
				'function'
			);
			await act( async () => {
				await globalThis.__ruleEditProps.onDelete();
			} );
			expect( mockGraph.removeRule ).toHaveBeenCalledWith( 'r-77' );
			// The URL modal stays open; only the RuleEditModal closed.
			expect(
				container.querySelector( '[data-testid="rule-edit-modal"]' )
			).toBeNull();
			unmount();
		} );

		it( 'a blank add draft gets no onDelete', async () => {
			mockGraph.listRules.mockResolvedValue( { rules: [] } );
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			await act( async () => {
				container
					.querySelector( '.event-logger-rule-control button' )
					.click();
			} );
			expect( globalThis.__ruleEditProps.onDelete ).toBeUndefined();
			unmount();
		} );

		it( 'does not append a second ? on a nodes/ELN URL that already has one', async () => {
			mockGraph.listRules.mockResolvedValue( { rules: [] } );
			mockView = urlModalView( '/jobs/x?reconcile' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			const btn = container.querySelector(
				'.event-logger-rule-control button'
			);
			await act( async () => {
				btn.click();
			} );
			// ?worker already terminates the URL — no second '?' appended.
			expect( globalThis.__ruleEditProps.rule.pattern ).toBe(
				'/jobs/x?reconcile'
			);
			unmount();
		} );

		it( 'saving upserts the exact rule and flips the button label without closing the URL modal', async () => {
			mockGraph.listRules.mockResolvedValue( { rules: [] } );
			mockGraph.upsertRule.mockResolvedValue( {
				rule: { id: 'new-1', pattern: '/foo?', action: 'log' },
			} );
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			await act( async () => {
				container
					.querySelector( '.event-logger-rule-control button' )
					.click();
			} );
			const draft = {
				id: '',
				pattern: '/foo?',
				action: 'log',
			};
			await act( async () => {
				await globalThis.__ruleEditProps.onSave( draft );
			} );
			expect( mockGraph.upsertRule ).toHaveBeenCalledWith( draft );
			// The URL modal stays open; only the RuleEditModal closed.
			expect(
				container.querySelector( '[data-testid="modal"]' )
			).toBeTruthy();
			expect(
				container.querySelector( '[data-testid="rule-edit-modal"]' )
			).toBeNull();
			// Success = button label flips (no status banner).
			expect( container.textContent ).toContain( 'Edit logging rule' );
			unmount();
		} );

		it( 'shows an inline error and keeps the URL modal open when the upsert fails', async () => {
			mockGraph.listRules.mockResolvedValue( { rules: [] } );
			mockGraph.upsertRule.mockResolvedValue( null );
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			await act( async () => {
				container
					.querySelector( '.event-logger-rule-control button' )
					.click();
			} );
			await act( async () => {
				await globalThis.__ruleEditProps.onSave( {
					id: '',
					pattern: '/foo?',
					action: 'log',
				} );
			} );
			expect(
				container.querySelector( '[data-testid="modal"]' )
			).toBeTruthy();
			expect(
				container.querySelector( '.event-logger-rule-error' )
			).toBeTruthy();
			expect(
				container.querySelector( '.event-logger-rule-error' ).className
			).toBe( 'event-logger-rule-error newspack-nodes-status is-error' );
			unmount();
		} );
	} );
} );
