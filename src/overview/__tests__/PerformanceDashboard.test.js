/* global globalThis */
/**
 * Tests for PerformanceDashboard — the orchestrator (JS-node-graph version).
 *
 * Post-D1b de-god the orchestrator reads FOUR per-slice view nodes
 * (`overview:view` / `urls:view` / `urldetail:view` / `requestdetail:view`), each
 * via its own `useNodeState`. `usePerformanceGraph` mounts the polls and hands
 * back `handleUrlParamsChange` alone; the verbs a click drives are this
 * component's own one-shots, held beside the state each reply sets.
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
	// Faithful enough to assert on: positionals then `--key=value`, as the
	// real one emits, so a test sees the tokens the server would.
	formatCommandArgs: ( args, options = {} ) => [
		...args,
		...Object.entries( options ).map( ( [ k, v ] ) => `--${ k }=${ v }` ),
	],
	// The Ask panel prints the MCP endpoint under this site's REST root.
	nodesData: () => ( { restUrl: '/wp-json/', nonce: 'NONCE' } ),
} ) );

// @longform Every on-demand verb this page drives is a one-shot, and this
// suite has no graph for them. The double records each by scope and hands the
// test `answerCommand`, which fires that verb's own `onDone` — the same
// callback the real reply would land in, so the paths under test are the real
// ones and only the wire is stood in for.
const mockCommands = {};
jest.mock( '@newspack-nodes/shared/hooks/useCommandOnce', () => ( {
	__esModule: true,
	useCommandOnce: ( opts ) => {
		const key =
			opts.scope ||
			`${ opts.ci ? `${ opts.ci }:` : '' }${ opts.command }`;
		// `run` must be STABLE across renders, as the real useCallback one is:
		// an unstable identity re-runs every effect that lists it as a dep.
		const entry = ( mockCommands[ key ] ??= {
			sent: [],
			api: {
				run: ( args ) => mockCommands[ key ].sent.push( args ),
				result: null,
				error: null,
				errorData: null,
				answeredArgs: null,
				answerFor: () => null,
				pending: false,
			},
		} );
		entry.opts = opts;
		return entry.api;
	},
} ) );

/**
 * Deliver a reply to the verb registered under `key`.
 *
 * @param {string} key    Scope, or `<ci>:<command>`.
 * @param {Object} answer `{ result, error, args }`, as `onDone` receives it.
 */
function answerCommand( key, answer ) {
	const entry = mockCommands[ key ];
	if ( ! entry ) {
		throw new Error( `no command registered for ${ key }` );
	}
	act( () =>
		entry.opts.onDone?.( {
			result: null,
			error: null,
			errorData: null,
			args: [],
			...answer,
		} )
	);
}

/**
 * What a verb was asked, most recent last.
 *
 * @param {string} key Scope, or `<ci>:<command>`.
 * @return {Array[]} The argument arrays it was run with.
 */
const sentTo = ( key ) => mockCommands[ key ]?.sent ?? [];

// The scopes this page registers, spelled once.
const SEARCH = 'performance:request_search:search';
const LOOKUP = 'performance:url_detail:lookup';
const DEEP_REQUEST = 'performance:request_search:deeplink';
const DEEP_URL = 'performance:url_detail:deeplink';
const GREP = 'performance:request_grep';
const RULES_LIST = 'rules:list';
const RULES_UPSERT = 'rules:upsert';
const RULES_DELETE = 'rules:delete';

// The graph control callbacks usePerformanceGraph hands back.
const mockGraph = { handleUrlParamsChange: jest.fn() };
// Records what the dashboard hands the graph — the partition rides here.
let mockGraphOpts = null;
jest.mock( '../hooks/usePerformanceGraph', () => ( {
	__esModule: true,
	// The CI mounts are real: the scopes below are built from them.
	SERVER: 'performance',
	RULES_CI: 'rules',
	GREP_RESULT_LIMIT: 20,
	usePerformanceGraph: ( opts ) => {
		mockGraphOpts = opts;
		return mockGraph;
	},
} ) );

// `mock` prefix permits cross-scope reference in jest.mock factories.
const mockNavState = {
	selectedUrl: null,
	selectedRequest: null,
	// Faithful: the real hook HOLDS the selection, and the dashboard reads it
	// back to decide whether a reply still belongs to what is open.
	selectUrl: jest.fn( ( url ) => {
		mockNavState.selectedUrl = url;
	} ),
	selectRequest: jest.fn( ( rid ) => {
		mockNavState.selectedRequest = rid;
	} ),
	initialSearchQuery: '',
	setInitialSearchQuery: jest.fn(),
	updateBrowserUrl: jest.fn(),
	deepLink: { requestId: null, urlHash: null },
	clearDeepLink: jest.fn(),
};
jest.mock( '../hooks/useUrlNavigation', () => ( {
	__esModule: true,
	default: () => mockNavState,
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
		Object.keys( mockCommands ).forEach(
			( k ) => delete mockCommands[ k ]
		);
		globalThis.__ruleEditProps = null;
		mockNavState.deepLink = { requestId: null, urlHash: null };
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
					total_urls: 264,
					global_avg_ms: 91,
					global_avg_peak_mb: 12.3,
					aggregate_time_series: {},
					breakdowns: { server: serverBuckets, status: {} },
				},
				loading: false,
				error: null,
			},
			// The TABLE's filtered total; distinct from the site's 264.
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
			// The site's count. A server filter scopes the request totals it
			// has a breakdown for; it does not make the site smaller.
			siteUrlCount: 264,
			isFiltered: true,
		} );
		expect( stats.globalAvgMs ).toBeCloseTo( 86200 / 780 );
		expect( stats.globalAvgPeakMb ).toBeCloseTo( 5870 / 780 );
		unmount();
	} );

	it( "counts the site's URLs, not the ones a table filter left", async () => {
		// "Unique URLs" read the URL TABLE's total, which the server filters by
		// search/errors/server — so a filter matching nothing rendered
		// `0 Unique URLs` beside a global, unfiltered request count. The
		// unfiltered figure is in the overview payload all along.
		mockView = loadedView( {
			overview: {
				data: {
					total_urls: 412,
					total_requests: 33049,
					aggregate_time_series: {},
					breakdowns: { server: {}, status: {} },
				},
				loading: false,
				error: null,
			},
			urls: { data: [], total: 0, loading: false, error: null },
		} );
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();

		expect( globalThis.__overviewProps.filteredStats ).toMatchObject( {
			siteUrlCount: 412,
			totalRequests: 33049,
		} );
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

	it( 'searchRequest asks request_search and selects what it answers', async () => {
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
		expect( sentTo( SEARCH ) ).toContainEqual( [ 'r1' ] );

		answerCommand( SEARCH, {
			result: { url_hash: 'h1', partition: 0 },
			args: [ 'r1' ],
		} );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith(
			expect.objectContaining( { hash: 'h1' } )
		);
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'r1' );
		unmount();
	} );

	it( 'a rid-shaped miss hints at the /pattern syntax', async () => {
		// 'checkout' is rid-shaped so it routes to exact lookup; the miss must
		// teach the text-searcher the escape hatch instead of dead-ending.
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, { onError: jest.fn() } )
		);
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'checkout' );
		} );
		answerCommand( SEARCH, { result: null, args: [ 'checkout' ] } );
		expect( globalThis.__overviewProps.searchError ).toContain(
			'prefix with / to search recent traffic'
		);
		unmount();
	} );

	// A ?request= deep link asks on its OWN node, and its reply carries the
	// partition — the whole reason the rid outranks the ?url= hash.
	it( 'a ?request= deep link selects the URL, the request and the partition', async () => {
		mockNavState.deepLink = { requestId: 'rid-deep', urlHash: null };
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
		expect( sentTo( DEEP_REQUEST ) ).toContainEqual( [ 'rid-deep' ] );

		answerCommand( DEEP_REQUEST, {
			result: { url_hash: 'h-known', partition: 2 },
			args: [ 'rid-deep' ],
		} );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith(
			expect.objectContaining( { hash: 'h-known' } )
		);
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'rid-deep' );
		expect( mockNavState.clearDeepLink ).toHaveBeenCalled();
		unmount();
	} );

	// request_search answers {rid, partition, url_hash} — never a url. A
	// deep-linked request whose URL is off the loaded page therefore has no
	// title until the hash is looked up, which is what this asserts.
	it( 'a deep link asks url_detail for a hash outside the loaded page', async () => {
		mockNavState.deepLink = { requestId: 'rid-offpage', urlHash: null };
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
		answerCommand( DEEP_REQUEST, {
			result: { url_hash: 'h-offpage', partition: 3 },
			args: [ 'rid-offpage' ],
		} );
		expect( sentTo( LOOKUP ) ).toContainEqual( [ 'h-offpage' ] );

		answerCommand( LOOKUP, {
			result: { stats: { url: '/quokka/census-2026' } },
			args: [ 'h-offpage' ],
		} );
		expect( mockNavState.selectUrl ).toHaveBeenLastCalledWith( {
			hash: 'h-offpage',
			url: '/quokka/census-2026',
		} );
		unmount();
	} );

	// The sentinel is load-bearing: canLogUrl compares against it to disable
	// "Log this URL". Titling with the raw hash would re-enable the button and
	// offer to write a rule whose pattern is a hash.
	it( 'keeps the Unknown URL sentinel when the hash will not resolve', async () => {
		mockNavState.deepLink = { requestId: 'rid-offpage', urlHash: null };
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		answerCommand( DEEP_REQUEST, {
			result: { url_hash: 'h-offpage', partition: 3 },
			args: [ 'rid-offpage' ],
		} );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith( {
			hash: 'h-offpage',
			url: 'Unknown URL',
		} );

		// A lookup that answers no url leaves the sentinel standing.
		answerCommand( LOOKUP, { result: null, args: [ 'h-offpage' ] } );
		expect( mockNavState.selectUrl ).toHaveBeenLastCalledWith( {
			hash: 'h-offpage',
			url: 'Unknown URL',
		} );
		unmount();
	} );

	// A hash title is not merely ugly: canLogUrl only rejects the sentinel, so
	// a hash would offer to write a rule keyed on `<hash>?`.
	it( 'a ?url= deep link applies the sentinel, never a hash title', async () => {
		mockNavState.deepLink = { requestId: null, urlHash: 'h-empty' };
		mockView = loadedView();
		const { unmount } = renderComponent(
			React.createElement( PerformanceDashboard, {
				onError: jest.fn(),
			} )
		);
		await flushEffects();
		expect( sentTo( DEEP_URL ) ).toContainEqual( [ 'h-empty' ] );

		answerCommand( DEEP_URL, {
			result: { stats: { url: '' } },
			args: [ 'h-empty' ],
		} );
		expect( mockNavState.selectUrl ).toHaveBeenCalledWith( {
			hash: 'h-empty',
			url: 'Unknown URL',
		} );
		unmount();
	} );

	it( 'searchRequest selects nothing when the request is not found', async () => {
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
		answerCommand( SEARCH, { result: null, args: [ 'r1' ] } );
		expect( mockNavState.selectRequest ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'a /url-pattern search runs request_grep and renders the result list', async () => {
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
		// Pattern (has '/') → grep, NOT the exact-rid request_search.
		expect( sentTo( GREP ) ).toContainEqual( [
			'/calendar',
			'--limit=20',
		] );
		expect( sentTo( SEARCH ) ).toEqual( [] );

		answerCommand( GREP, {
			result: {
				results: [
					{
						rid: 'r1',
						url: '/calendar',
						method: 'GET',
						match_count: 2,
					},
				],
				truncated: true,
			},
			args: [ '/calendar' ],
		} );
		expect( globalThis.__overviewProps.searchResults ).toEqual( [
			{ rid: 'r1', url: '/calendar', method: 'GET', match_count: 2 },
		] );
		expect( globalThis.__overviewProps.searchResultsTruncated ).toBe(
			true
		);
		unmount();
	} );

	it( 'an empty grep result surfaces the no-matches message', async () => {
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
		answerCommand( GREP, {
			result: { results: [], truncated: false },
			args: [ '/nope' ],
		} );
		expect( globalThis.__overviewProps.searchResults ).toBeNull();
		expect(
			( globalThis.__overviewProps.searchError || '' ).toLowerCase()
		).toContain( 'no matches in recent traffic' );
		unmount();
	} );

	it( 'selecting a grep result deep-links via the exact-rid path', async () => {
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
		answerCommand( GREP, {
			result: {
				results: [
					{
						rid: 'grep-rid',
						url: '/x',
						method: 'GET',
						match_count: 1,
					},
				],
				truncated: false,
			},
			args: [ '/x' ],
		} );
		await act( async () => {
			await globalThis.__overviewProps.onSelectResult( 'grep-rid' );
		} );
		expect( sentTo( SEARCH ) ).toContainEqual( [ 'grep-rid' ] );

		answerCommand( SEARCH, {
			result: { url_hash: 'h9', partition: 0 },
			args: [ 'grep-rid' ],
		} );
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

	// @longform The brief is summoned FROM the request-detail modal, so it has
	// to paint over it. That modal is a `@wordpress/components` one, portaled
	// to the body on z-index 100000; this dialog renders inside the dashboard's
	// own root, so document order can never win it and the backdrop — where the
	// layer lives — carries the class that raises it.
	it( 'raises the assembled brief above the modal that summoned it', async () => {
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
		answerCommand( 'performance:ask', {
			result: { subject: 'request', url: '/foo', findings: [] },
		} );

		expect(
			document.querySelector( '.event-logger-performance-modal' )
		).toBeTruthy();
		const backdrop = document.querySelector(
			'.newspack-nodes-modal__backdrop'
		);
		expect( backdrop ).toBeTruthy();
		expect( backdrop.className ).toContain( 'event-logger-ask__backdrop' );
		unmount();
	} );

	it( 'renders the Ask trigger immediately before the request-detail back button', async () => {
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
		const trigger = container.querySelector( '.event-logger-ask__trigger' );
		expect( trigger ).toBeTruthy();
		expect( trigger.nextElementSibling ).toBe(
			container.querySelector( '.event-logger-modal-back-button' )
		);
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

		// @longform It waits for the ruleset. Enabled before the `list` reply
		// lands, the button reads "Log this URL" and opens a BLANK draft — and
		// an id-less upsert matches by pattern, so saving it would replace a
		// configured rule's hooks and thresholds with nothing.
		it( 'enables "Log this URL" only once the ruleset is known', async () => {
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
			expect( btn.disabled ).toBe( true );

			answerCommand( RULES_LIST, { result: { rules: [] } } );
			expect( btn.textContent ).toContain( 'Log this URL' );
			expect( btn.disabled ).toBe( false );
			unmount();
		} );

		it( 'renders the Ask trigger immediately before the rule control', async () => {
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			const trigger = container.querySelector(
				'.event-logger-ask__trigger'
			);
			const control = container.querySelector(
				'.event-logger-rule-control'
			);
			expect( trigger ).toBeTruthy();
			expect( trigger.nextElementSibling ).toBe( control );
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
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [ existing ] } } );
			expect( sentTo( RULES_LIST ) ).not.toEqual( [] );
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
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [] } } );
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
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [ existing ] } } );
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
			expect( sentTo( RULES_DELETE ) ).toContainEqual( [ 'r-77' ] );
			answerCommand( RULES_DELETE, { result: { deleted: true } } );
			// The URL modal stays open; only the RuleEditModal closed.
			expect(
				container.querySelector( '[data-testid="rule-edit-modal"]' )
			).toBeNull();
			unmount();
		} );

		it( 'a blank add draft gets no onDelete', async () => {
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [] } } );
			await act( async () => {
				container
					.querySelector( '.event-logger-rule-control button' )
					.click();
			} );
			expect( globalThis.__ruleEditProps.onDelete ).toBeUndefined();
			unmount();
		} );

		it( 'does not append a second ? on a nodes/ELN URL that already has one', async () => {
			mockView = urlModalView( '/jobs/x?reconcile' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [] } } );
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
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [] } } );
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
			expect( sentTo( RULES_UPSERT ) ).toContainEqual( [
				JSON.stringify( draft ),
			] );
			answerCommand( RULES_UPSERT, {
				result: { rule: { id: 'new-1', pattern: '/foo?' } },
			} );
			// The ruleset re-reads, so the label follows the SERVER.
			answerCommand( RULES_LIST, {
				result: {
					rules: [ { id: 'new-1', pattern: '/foo?', action: 'log' } ],
				},
			} );
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
			mockView = urlModalView( '/foo' );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			answerCommand( RULES_LIST, { result: { rules: [] } } );
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
			// A REFUSAL is an answer; it fills the banner and closes only the
			// rule editor.
			answerCommand( RULES_UPSERT, {
				result: null,
				error: 'unparseable rule',
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
	// @longform A rid found by search carries its own record. Gating the modal
	// on the URL slice made a found request render NOTHING when that URL had no
	// stats to answer with — and left the rid selected, so the next URL the
	// operator opened rendered the stale request instead of itself.
	describe( 'a found request does not wait on the URL slice', () => {
		it( 'opens the request detail when only the URL slice is missing', async () => {
			mockNavState.selectedUrl = {
				hash: '905b81442680',
				url: 'https://tucsonweekly.example/jobs/filmtimes/import-film-times',
			};
			mockNavState.selectedRequest = 'bki3lhqsa3bkfvp63qw1ws1k2qx9sxp0';
			mockView = loadedView( {
				urlDetail: { data: null, loading: false, error: null },
				requestDetail: {
					data: {
						rid: 'bki3lhqsa3bkfvp63qw1ws1k2qx9sxp0',
						entries: [],
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
			expect(
				container.querySelector( '[data-testid="request-detail"]' )
			).toBeTruthy();
			unmount();
		} );

		// Back out of that request and the URL pane has nothing to show — but
		// the modal must stay up, or the operator is left on a paused dashboard
		// with the selection still set and no close button to clear it.
		it( 'keeps the modal up when the request is closed and no URL slice came', async () => {
			mockNavState.selectedUrl = {
				hash: '905b81442680',
				url: 'https://tucsonweekly.example/jobs/filmtimes/import-film-times',
			};
			mockNavState.selectedRequest = null;
			// A REFUSAL, distinct from the empty default: the URL pane must say
			// so inside a modal that is still there to be closed.
			mockView = loadedView( {
				urlDetail: {
					data: null,
					loading: false,
					error: 'no stats for this url',
				},
			} );
			const { container, unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			expect(
				container.querySelector( '[data-testid="modal"]' )
			).toBeTruthy();
			expect( container.textContent ).toContain(
				'no stats for this url'
			);
			unmount();
		} );

		it( 'drops the open request when another URL is selected', async () => {
			mockNavState.selectedRequest = 'bki3lhqsa3bkfvp63qw1ws1k2qx9sxp0';
			mockView = loadedView();
			const { unmount } = renderComponent(
				React.createElement( PerformanceDashboard, {
					onError: jest.fn(),
				} )
			);
			await flushEffects();
			act( () =>
				globalThis.__urlTableProps.onSelect( {
					hash: 'fac69dee1c14',
					url: 'https://community.example/charlotte/MovieTimes',
				} )
			);
			expect( mockNavState.selectRequest ).toHaveBeenCalledWith( null );
			expect( mockNavState.selectUrl ).toHaveBeenCalledWith( {
				hash: 'fac69dee1c14',
				url: 'https://community.example/charlotte/MovieTimes',
			} );
			unmount();
		} );
	} );
} );
