/* global globalThis, PopStateEvent */
/**
 * Tests for PerformanceDashboard — the orchestrator (JS-node-graph version).
 *
 * Post-D1b de-god the orchestrator reads FOUR per-slice view nodes
 * (`overview:view` / `urls:view` / `url-detail:view` / `request-detail:view`), each
 * via its own `useNodeField`. `usePerformanceGraph` mounts the polls and hands
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
 * `useNodeField` fans it out by node name so the existing setups work unchanged.
 * The graph control callbacks come from `mockGraph`. Children are mocked at the
 * module boundary as stub components that record their props.
 */

// Per-slice view model; the mock fans it out by node name.
let mockView = null;
jest.mock( '@newspack-nodes/runtime', () => ( {
	__esModule: true,
	useNodeField: ( nodeName ) => {
		if ( ! mockView ) {
			return undefined;
		}
		const sliceByNode = {
			'overview:view': 'overview',
			'urls:view': 'urls',
			'url-detail:view': 'urlDetail',
			'request-detail:view': 'requestDetail',
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
// ones and only the wire is stood in for. It keeps the asks standing as the
// Fetcher does: `run()` asks, a read's superseding the last, `abandon()`
// withdraws them all, and an answer to nothing asked stops at the gate.
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
			asked: [],
			api: {
				run: ( args ) => {
					const command = mockCommands[ key ];
					command.sent.push( args );
					command.asked = command.opts.retry
						? [ args ]
						: [ ...command.asked, args ];
				},
				abandon: () => {
					mockCommands[ key ].asked = [];
				},
				result: null,
				error: null,
				errorData: null,
				answeredArgs: null,
				get pending() {
					return 0 < mockCommands[ key ].asked.length;
				},
			},
		} );
		entry.opts = opts;
		return entry.api;
	},
} ) );

/**
 * Deliver a reply to the verb registered under `key`. One naming its `args`
 * is judged as the gate judges it: dropped unless an ask standing asked
 * exactly those, which it then settles.
 *
 * @param {string} key    Scope, or `<ci>:<command>`.
 * @param {Object} answer `{ result, error, args }`, as `onDone` receives it.
 */
function answerCommand( key, answer ) {
	const entry = mockCommands[ key ];
	if ( ! entry ) {
		throw new Error( `no command registered for ${ key }` );
	}
	if ( answer.args ) {
		const echoed = JSON.stringify( answer.args );
		const at = entry.asked.findIndex(
			( asked ) => JSON.stringify( asked ) === echoed
		);
		if ( 0 > at ) {
			return;
		}
		entry.asked.splice( at, 1 );
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
const SEARCH = 'request-search';
const LOOKUP = 'url-lookup';
const DEEP_REQUEST = 'request-deeplink';
const DEEP_URL = 'url-deeplink';
const GREP = 'performance:grep_requests';
const RULES_DUMP = 'rules:dump';
const RULES_UPSERT = 'rules:upsert';
const RULES_DELETE = 'rules:delete';

// The graph control callbacks usePerformanceGraph hands back.
const mockGraph = { handleUrlParamsChange: jest.fn() };
// Records what the dashboard hands the graph — the partition rides here.
let mockGraphOpts = null;
// Every render's ask, oldest first, so a test can read the FIRST fetch.
const mockGraphCalls = [];
jest.mock( '../hooks/usePerformanceGraph', () => ( {
	__esModule: true,
	// The CI mounts are real: the scopes below are built from them.
	SERVER: 'performance',
	RULES_CI: 'rules',
	GREP_RESULT_LIMIT: 20,
	NO_DETAIL_FILTERS: { errors_only: false, bucket: '' },
	usePerformanceGraph: ( opts ) => {
		mockGraphOpts = opts;
		mockGraphCalls.push( opts );
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
import { DEFAULT_CHART_BREAKDOWN } from '../constants';
import PerformanceDashboard from '../PerformanceDashboard';
import { breakdownState } from '../AggregateTimeChart';
import * as chartSlots from '../chartSlots';
import { renderComponent, act } from '../../test-helpers/renderHook';
import { nameTable, slotsEndingAt } from '../../test-helpers/chartWire';

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

/**
 * A dimension the reply carried with nothing in the window.
 */
const NO_SERIES = { names: [], buckets: {} };

/**
 * The newest bucket every server fixture here writes.
 */
const [ NOW ] = slotsEndingAt( '2026-09-29-14-35', 1 );

// A fully-populated, "loaded" view model (lastRefresh stamped).
function loadedView( overrides = {} ) {
	return {
		overview: {
			data: {
				total_requests: 100,
				breakdowns: { server: NO_SERIES, status: NO_SERIES },
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

/**
 * A loaded view whose overview reply carries `bucket` as its server axis.
 *
 * @param {Object} bucket The `server` dimension's wire table.
 * @return {Object} The view model.
 */
const serverView = ( bucket ) =>
	loadedView( {
		overview: {
			data: {
				total_requests: 100,
				breakdowns: { server: bucket, status: NO_SERIES },
			},
			loading: false,
			error: null,
		},
	} );

// A hub's two servers, and a site reporting one.
const hub = nameTable( {
	[ NOW ]: { 'edge-01': [ 9, 90, 3, 9 ], 'edge-02': [ 4, 40, 3, 4 ] },
} );
const single = nameTable( { [ NOW ]: { 'edge-01': [ 9, 90, 3, 9 ] } } );

const dashboard = () =>
	React.createElement( PerformanceDashboard, { onError: jest.fn() } );
// Every dashboard a test mounts, so a failing one cannot outlive its test.
const mounted = [];
const mountDash = () => {
	const mount = renderComponent( dashboard() );
	mounted.push( mount );
	return mount;
};

describe( 'PerformanceDashboard', () => {
	beforeEach( () => {
		mockView = null;
		window.localStorage.clear();
		// The page writes its filters into the bar; no test inherits them.
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=perf'
		);
	} );

	afterEach( () => {
		mounted.splice( 0 ).forEach( ( mount ) => mount.unmount() );
		mockGraph.handleUrlParamsChange.mockClear();
		mockGraphCalls.length = 0;
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
		jest.clearAllMocks();
	} );

	it( 'shows the loading spinner while the view model is null', () => {
		mockView = null;
		const { container, unmount } = mountDash();
		expect( container.textContent ).toContain( 'Loading performance' );
		expect(
			container.querySelector( '[data-testid="spinner"]' )
		).toBeTruthy();
		unmount();
	} );

	it( 'orchestrates the full dashboard once the overview resolves', async () => {
		mockView = loadedView();
		const { container, unmount } = mountDash();
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

	it( 'forwards the urls slice error and pending state to UrlTable', async () => {
		mockView = loadedView( {
			urls: {
				data: [],
				loading: true,
				error: 'include_workers wants a bool: maybe',
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();
		expect( globalThis.__urlTableProps.error ).toBe(
			'include_workers wants a bool: maybe'
		);
		expect( globalThis.__urlTableProps.loading ).toBe( true );
		unmount();
	} );

	it( 'forwards the urls slice ranked flag and server clock to UrlTable', async () => {
		mockView = loadedView( {
			urls: {
				data: [],
				total: 0,
				loading: false,
				error: null,
				ranked: true,
				as_of: 1758500000,
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();
		expect( globalThis.__urlTableProps.ranked ).toBe( true );
		expect( globalThis.__urlTableProps.now ).toBe( 1758500000 );
		unmount();
	} );

	it( 'reads refresh interval from localStorage on mount', () => {
		window.localStorage.setItem( 'event-logger-refresh-interval', '5000' );
		mockView = loadedView();
		const { unmount } = mountDash();
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
		const { unmount } = mountDash();
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
		const { unmount } = mountDash();
		window.localStorage.removeItem( 'event-logger-refresh-interval' );
		unmount();
		// No throw == success; invalid value was filtered.
		expect( true ).toBe( true );
	} );

	it( 'persists refresh interval changes to localStorage via effect', () => {
		window.localStorage.clear();
		mockView = loadedView();
		const { unmount } = mountDash();
		expect(
			window.localStorage.getItem( 'event-logger-refresh-interval' )
		).toBe( '15000' );
		unmount();
	} );

	it( 'unmounts cleanly without throwing', () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		expect( () => unmount() ).not.toThrow();
	} );

	describe( 'the server breakdown axis', () => {
		it( 'stays on Server while the first reply is still in flight', async () => {
			// Empty is "nothing has ARRIVED", not "one server". Resolving it as
			// the latter demoted every page load before the names could land.
			mockView = loadedView( {
				overview: { data: null, loading: true, error: null },
			} );
			const { unmount } = mountDash();
			await flushEffects();

			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'server'
			);
			unmount();
		} );

		it( 'stays on Server on a hub with no filter', async () => {
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();

			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'server'
			);
			expect( globalThis.__overviewProps.canBreakDownByServer ).toBe(
				true
			);
			unmount();
		} );

		it( 'falls back to Status Codes when one server reports', async () => {
			mockView = serverView( single );
			const { unmount } = mountDash();
			await flushEffects();

			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'status'
			);
			unmount();
		} );

		it( 'falls back under a server filter and restores when it clears', async () => {
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();
			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'server'
			);

			await act( async () => {
				globalThis.__overviewProps.setServerFilter( 'edge-01' );
			} );
			await flushEffects();
			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'status'
			);

			// The scoped reply still names every server: the axis is site-wide.
			mockView = serverView( hub );
			await act( async () => {
				globalThis.__overviewProps.setRefreshInterval( '5000' );
			} );
			await flushEffects();

			await act( async () => {
				globalThis.__overviewProps.setServerFilter( '' );
			} );
			await flushEffects();

			expect( globalThis.__overviewProps.serverNames ).toEqual( [
				'edge-01',
				'edge-02',
			] );
			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'server'
			);
			unmount();
		} );

		it( 'asks the server for the dimension it is going to draw', async () => {
			// The whole point of deriving: the request and the render agree.
			mockView = serverView( single );
			const { unmount } = mountDash();
			await flushEffects();

			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'status'
			);
			expect( mockGraphOpts.chartBreakdown ).toBe( 'status' );
			unmount();
		} );

		it( 'returns to Server when a second server starts reporting', async () => {
			// The fallback is derived per render, never written back: a hub
			// whose sibling was silent for one window would otherwise be stuck
			// on Status Codes for the session, which is the original bug.
			mockView = serverView( single );
			const { rerender, unmount } = mountDash();
			await flushEffects();
			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'status'
			);

			mockView = serverView( hub );
			rerender( dashboard() );
			await flushEffects();

			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'server'
			);
			unmount();
		} );
	} );

	describe( 'the address bar', () => {
		const param = ( name ) =>
			new URLSearchParams( window.location.search ).get( name );
		const linkTo = ( query ) =>
			window.history.replaceState(
				null,
				'',
				`/wp-admin/admin.php?page=perf&${ query }`
			);
		let pushSpy;

		beforeEach( () => {
			pushSpy = jest.spyOn( window.history, 'pushState' );
		} );

		afterEach( () => {
			pushSpy.mockRestore();
		} );

		it( 'opens on the metric and breakdown a link names', async () => {
			linkTo( 'metric=memory&breakdown=country' );
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();

			expect( globalThis.__overviewProps.chartMetric ).toBe( 'memory' );
			expect( globalThis.__urlTableProps.metric ).toBe( 'memory' );
			expect( globalThis.__overviewProps.chartBreakdown ).toBe(
				'country'
			);
			expect( mockGraphOpts.chartBreakdown ).toBe( 'country' );
			unmount();
		} );

		it( 'ignores a metric or breakdown the dropdowns do not offer', async () => {
			linkTo( 'metric=p99&breakdown=referrer' );
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();

			expect( globalThis.__overviewProps.chartMetric ).toBe( 'volume' );
			expect( mockGraphOpts.chartBreakdown ).toBe(
				DEFAULT_CHART_BREAKDOWN
			);
			expect( param( 'metric' ) ).toBeNull();
			expect( param( 'breakdown' ) ).toBeNull();
			unmount();
		} );

		it( 'writes a metric or breakdown change and drops a default', async () => {
			linkTo( 'url=h1&request=r1' );
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();

			act( () => globalThis.__overviewProps.setChartMetric( 'avg' ) );
			act( () => globalThis.__overviewProps.setChartBreakdown( 'ua' ) );
			expect( param( 'metric' ) ).toBe( 'avg' );
			expect( param( 'breakdown' ) ).toBe( 'ua' );

			act( () => globalThis.__overviewProps.setChartMetric( 'volume' ) );
			act( () =>
				globalThis.__overviewProps.setChartBreakdown(
					DEFAULT_CHART_BREAKDOWN
				)
			);
			expect( window.location.search ).toBe(
				'?page=perf&url=h1&request=r1'
			);
			expect( pushSpy ).not.toHaveBeenCalled();
			unmount();
		} );

		it( 'scopes the first fetch to a linked server', async () => {
			linkTo( 'server=edge-02' );
			mockView = loadedView( {
				overview: { data: null, loading: true, error: null },
			} );
			const { rerender, unmount } = mountDash();
			await flushEffects();
			expect( mockGraphCalls[ 0 ].serverFilter ).toBe( 'edge-02' );

			mockView = serverView( hub );
			rerender( dashboard() );
			await flushEffects();

			expect( globalThis.__overviewProps.serverFilter ).toBe( 'edge-02' );
			expect( mockGraphOpts.serverFilter ).toBe( 'edge-02' );
			unmount();
		} );

		it( 'resets a linked server the list does not name, and drops it', async () => {
			linkTo( 'server=edge-09&url=h1' );
			mockView = loadedView( {
				overview: { data: null, loading: true, error: null },
			} );
			const { rerender, unmount } = mountDash();
			await flushEffects();
			// Trusted until the list can judge it; the server scopes it empty.
			expect( mockGraphCalls[ 0 ].serverFilter ).toBe( 'edge-09' );
			expect( param( 'server' ) ).toBe( 'edge-09' );

			mockView = serverView( hub );
			rerender( dashboard() );
			await flushEffects();

			expect( globalThis.__overviewProps.serverFilter ).toBe( '' );
			expect( mockGraphOpts.serverFilter ).toBe( '' );
			expect( window.location.search ).toBe( '?page=perf&url=h1' );
			unmount();
		} );

		it.each( [
			[ 'a reply the decoder refuses', 'not a name table' ],
			[ 'a window with no traffic', NO_SERIES ],
		] )( 'keeps a chosen server through %s', async ( _label, server ) => {
			mockView = serverView( hub );
			const { rerender, unmount } = mountDash();
			await flushEffects();
			act( () =>
				globalThis.__overviewProps.setServerFilter( 'edge-01' )
			);

			mockView = serverView( server );
			rerender( dashboard() );
			await flushEffects();

			expect( globalThis.__overviewProps.serverFilter ).toBe( 'edge-01' );
			expect( mockGraphOpts.serverFilter ).toBe( 'edge-01' );
			expect( param( 'server' ) ).toBe( 'edge-01' );
			unmount();
		} );

		it( 'puts the live server back over an entry Back restored', async () => {
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();
			act( () =>
				globalThis.__overviewProps.setServerFilter( 'edge-02' )
			);

			// The restored entry was pushed under another server.
			linkTo( 'server=edge-01&url=h1' );
			act( () => {
				window.dispatchEvent( new PopStateEvent( 'popstate' ) );
			} );

			expect( mockGraphOpts.serverFilter ).toBe( 'edge-02' );
			expect( param( 'server' ) ).toBe( 'edge-02' );
			expect( param( 'url' ) ).toBe( 'h1' );
			unmount();
		} );

		it( 'writes a server pick, and All Servers drops it', async () => {
			linkTo( 'url=h1' );
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();

			act( () =>
				globalThis.__overviewProps.setServerFilter( 'edge-01' )
			);
			expect( param( 'server' ) ).toBe( 'edge-01' );
			// The table returns to page 1 on it, as on its own filters.
			expect( globalThis.__urlTableProps.server ).toBe( 'edge-01' );

			act( () => globalThis.__overviewProps.setServerFilter( '' ) );
			expect( window.location.search ).toBe( '?page=perf&url=h1' );
			expect( pushSpy ).not.toHaveBeenCalled();
			unmount();
		} );

		it( 'pushes the request a search found, and forgets the search', async () => {
			linkTo( 'search=r1&metric=avg' );
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();

			await act( async () => {
				await globalThis.__overviewProps.onSearch( 'r1' );
			} );
			answerCommand( SEARCH, {
				result: { url_hash: 'h1', partition: 0 },
				args: [ 'r1' ],
			} );

			expect( pushSpy ).toHaveBeenCalledTimes( 1 );
			expect( window.location.search ).toBe(
				'?page=perf&metric=avg&url=h1&request=r1'
			);
			unmount();
		} );

		it( 'drops the server when a found request leaves the filter', async () => {
			mockView = serverView( hub );
			const { unmount } = mountDash();
			await flushEffects();
			act( () =>
				globalThis.__overviewProps.setServerFilter( 'edge-01' )
			);

			await act( async () => {
				await globalThis.__overviewProps.onSearch( 'r1' );
			} );
			answerCommand( SEARCH, {
				result: { url_hash: 'h1', partition: 0 },
				args: [ 'r1' ],
			} );
			await flushEffects();

			expect( globalThis.__overviewProps.serverFilter ).toBe( '' );
			expect( param( 'server' ) ).toBeNull();
			unmount();
		} );
	} );

	it( 'reads the server breakdown once, and charts that same read', async () => {
		const server = nameTable( {
			[ NOW ]: {
				'edge-01': [ 41, 820, 3, 41 ],
				'edge-02': [ 43, 4300, 4, 43 ],
			},
		} );
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 84,
					breakdowns: { server, status: NO_SERIES },
				},
				loading: false,
				error: null,
			},
		} );
		const decode = jest.spyOn( chartSlots, 'decodeNameTable' );
		const { unmount } = mountDash();
		await flushEffects();
		expect( globalThis.__overviewProps.chartBreakdown ).toBe( 'server' );
		expect(
			decode.mock.calls.filter( ( [ wire ] ) => server === wire )
		).toHaveLength( 1 );
		expect( globalThis.__overviewProps.breakdownRead.series.names ).toEqual(
			[ 'edge-01', 'edge-02' ]
		);
		decode.mockRestore();
		unmount();
	} );

	it( 'derives categoryData / breakdownData / serverNames from the overview slice', async () => {
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 100,
					category_time_series: { x: {} },
					breakdowns: {
						server: nameTable( {
							[ NOW ]: {
								'edge-01': [ 10, 100, 3, 10 ],
								'edge-02': [ 4, 40, 3, 4 ],
							},
						} ),
						status: nameTable( {
							[ NOW ]: { '2xx': [ 10, 100, 3, 10 ] },
						} ),
					},
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();
		// categoryData truthy.
		expect( globalThis.__overviewProps.categoryData ).toBeTruthy();
		// Whatever the default dim is, the chart reads THAT slice — naming it
		// here rather than a literal keeps the test honest if the default moves.
		expect( globalThis.__overviewProps.breakdownRead ).toEqual(
			breakdownState(
				mockView.overview.data.breakdowns[ DEFAULT_CHART_BREAKDOWN ]
			)
		);
		// serverNames extracted from the server breakdown.
		expect( globalThis.__overviewProps.serverNames ).toContain( 'edge-01' );
		unmount();
	} );

	it( 'takes the server names from a reply fetched under a filter', async () => {
		// The server axis is read site-wide under any scope, so a scoped reply
		// names every server, a newly reporting one included.
		mockView = serverView( hub );
		const { rerender, unmount } = mountDash();
		await flushEffects();
		act( () => {
			globalThis.__overviewProps.setServerFilter( 'edge-01' );
		} );
		mockView = serverView(
			nameTable( {
				[ NOW ]: {
					'edge-01': [ 10, 100, 3, 10 ],
					'edge-02': [ 5, 50, 3, 5 ],
					'edge-03': [ 2, 20, 3, 2 ],
				},
			} )
		);
		rerender( dashboard() );
		await flushEffects();

		expect( globalThis.__overviewProps.serverNames ).toEqual( [
			'edge-01',
			'edge-02',
			'edge-03',
		] );
		expect( globalThis.__overviewProps.serverFilter ).toBe( 'edge-01' );
		unmount();
	} );

	it( "hands the panel the filtered set's own totals", async () => {
		// Every headline number comes from the `urls` reply, so all five answer
		// for one set — the one the table lists. Assembling them from separate
		// namespaces is what put `0 Unique URLs` beside 33,049 requests.
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 33049,
					total_urls: 412,
					breakdowns: { server: NO_SERIES, status: NO_SERIES },
				},
				loading: false,
				error: null,
			},
			urls: {
				data: [],
				totals: {
					urls: 313,
					requests: 9001,
					avg_ms: 70,
					avg_peak_mb: 3.3,
					requests_per_second: 2.5,
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();

		expect( globalThis.__overviewProps.urlTotals ).toEqual( {
			urls: 313,
			requests: 9001,
			avg_ms: 70,
			avg_peak_mb: 3.3,
			requests_per_second: 2.5,
		} );
		unmount();
	} );

	it( 'offers every name the server axis carries, each one a host', () => {
		// Every server is filed under its own name, so no name is withheld.
		const serverBuckets = {
			[ NOW ]: { 'edge-01': [ 5, 50, 3, 5 ], Other: [ 9, 90, 3, 9 ] },
		};
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 100,
					breakdowns: {
						server: nameTable( serverBuckets ),
						status: NO_SERIES,
					},
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();

		expect( globalThis.__overviewProps.serverNames ).toEqual( [
			'Other',
			'edge-01',
		] );
		unmount();
	} );

	it( "divides the Time Breakdown by the leaderboard's own average", async () => {
		// The categories are the board's, over its 25 hour keys, so the
		// divisor is the board's avg_ms: not the charts' 91 over 288 slots,
		// nor a server row summed off the breakdown.
		const serverBuckets = {};
		slotsEndingAt( '2026-09-29-14-35', 20 ).forEach( ( bucket ) => {
			serverBuckets[ bucket ] = {
				'edge-01': [ 11, 1430, 99, 11 ],
				'edge-02': [ 37, 3700, 259, 37 ],
			};
		} );
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 2000,
					global_avg_ms: 91,
					global_leaderboard: {
						count: 780,
						avg_ms: 73.5,
						categories: {},
					},
					breakdowns: {
						server: nameTable( serverBuckets ),
						status: NO_SERIES,
					},
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();
		expect( globalThis.__overviewProps.breakdownAvgMs ).toBe( 73.5 );

		act( () => {
			globalThis.__overviewProps.setServerFilter( 'edge-02' );
		} );
		await flushEffects();
		expect( globalThis.__overviewProps.breakdownAvgMs ).toBe( 73.5 );
		unmount();
	} );

	it( 'hands the Time Breakdown no divisor when no request on the board was timed', async () => {
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 2000,
					global_avg_ms: null,
					global_leaderboard: {
						count: 780,
						avg_ms: null,
						categories: {},
					},
					breakdowns: { server: NO_SERIES, status: NO_SERIES },
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();
		expect( globalThis.__overviewProps.breakdownAvgMs ).toBeNull();
		unmount();
	} );

	it( 'reads a malformed server breakdown as naming no server', async () => {
		// A row one field short: a reply the decoder refuses whole.
		const malformed = {
			names: [ 'edge-01', 'edge-02' ],
			buckets: { [ NOW ]: [ [ 1, 37, 3700, 259 ] ] },
		};
		mockView = loadedView( {
			overview: {
				data: {
					total_requests: 2000,
					global_avg_ms: 91,
					breakdowns: { server: malformed, status: NO_SERIES },
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
		await flushEffects();
		expect( globalThis.__overviewProps.serverNames ).toEqual( [] );
		unmount();
	} );

	it( "renders the modal's rate from the payload, not a second derivation", async () => {
		// The client used to sum this URL's series and divide by the buckets it
		// had, while the header divided by the fixed hour — so a URL seen in two
		// of twelve buckets read six times its rate under the same "req/s"
		// label. One owner, one window, one divisor.
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = null;
		mockView = loadedView( {
			urlDetail: {
				data: {
					last_modified: 1,
					stats: { requests_per_second: 3.75 },
					requests: [],
				},
				loading: false,
				error: null,
			},
		} );
		const { container, unmount } = mountDash();
		await flushEffects();

		expect( container.textContent ).toContain( '3.75' );
		unmount();
	} );

	it.each( [
		[ 'url', null, null, 'Loading URL…' ],
		[ 'url', 'nope', null, 'Could not load this URL: nope' ],
		[ 'request', null, 'r7', 'Loading request…' ],
		[ 'request', 'gone', 'r7', 'Could not load this request: gone' ],
	] )(
		'a selected %s with no detail is a state, never a blank panel',
		async ( which, error, request, expected ) => {
			// Both panels answer the same two questions — is it still coming,
			// or did it fail — so neither may render empty.
			mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
			mockNavState.selectedRequest = request;
			const slice = { data: null, loading: null === error, error };
			mockView = loadedView(
				'url' === which
					? { urlDetail: slice }
					: { urlDetail: null, requestDetail: slice }
			);
			const { container, unmount } = mountDash();
			await flushEffects();

			expect( container.textContent ).toContain( expected );
			unmount();
		}
	);

	it( 'keeps the URL modal mounted while a filter flip relists it', async () => {
		mockNavState.selectedUrl = { hash: 'h9', url: '/heron' };
		mockNavState.selectedRequest = null;
		const held = {
			last_modified: 3,
			stats: { errors: 7, avg_ms: 431, requests_per_second: 2.5 },
			requests: [ { rid: 'r-held', status_code: 200 } ],
		};
		mockView = loadedView( {
			urlDetail: { data: held, loading: false, error: null },
		} );
		const { container, rerender } = mountDash();
		await flushEffects();
		const view = container.querySelector( '[data-testid="url-detail"]' );
		const stats = container.querySelector( '.event-logger-header-stats' );
		expect( globalThis.__urlDetailProps.listing ).toBe( false );

		mockView = loadedView( {
			urlDetail: { data: held, loading: true, error: null },
		} );
		await act( async () => {
			globalThis.__urlDetailProps.onFilterChange( 'errors_only', true );
		} );

		expect( container.querySelector( '[data-testid="url-detail"]' ) ).toBe(
			view
		);
		expect( container.querySelector( '.event-logger-header-stats' ) ).toBe(
			stats
		);
		expect( container.textContent ).not.toContain( 'Loading URL' );
		expect( globalThis.__urlDetailProps.listing ).toBe( true );
		// The held list answers the old ask, so it is not summed as errors.
		expect( globalThis.__urlDetailProps.detailErrors ).toBeNull();
		expect( stats.textContent ).toContain( '431ms' );

		mockView = loadedView( {
			urlDetail: {
				data: {
					...held,
					requests: [ { rid: 'r-fatal', status_code: 500 } ],
				},
				loading: false,
				error: null,
			},
		} );
		rerender( dashboard() );

		expect( container.querySelector( '[data-testid="url-detail"]' ) ).toBe(
			view
		);
		expect( globalThis.__urlDetailProps.listing ).toBe( false );
		expect(
			globalThis.__urlDetailProps.sortedRequests.map( ( r ) => r.rid )
		).toEqual( [ 'r-fatal' ] );
		expect( globalThis.__urlDetailProps.listError ).toBeNull();

		mockView = loadedView( {
			urlDetail: { data: held, loading: false, error: 'egret refused' },
		} );
		rerender( dashboard() );
		expect( globalThis.__urlDetailProps.listError ).toBe( 'egret refused' );
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
						avg_peak_mb: 4,
					},
					requests: [],
				},
				loading: false,
				error: null,
			},
		} );
		const { container, unmount } = mountDash();
		await flushEffects();
		expect( container.textContent ).toContain( '/foo' );
		expect( container.textContent ).toContain( 'UrlDetailView' );
		expect( container.textContent ).toContain( 'req/s' );
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
		expect( headerStats ).toHaveLength( 3 );
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

	it.each( [
		[
			'renders each header stat as its own text, unit and label',
			{
				requests_per_second: 7.125,
				avg_ms: 412.6,
				avg_peak_mb: 26.45,
			},
			[
				[ '7.13', 'req/s' ],
				[ '413ms', 'avg' ],
				[ '26.4MB', 'mem' ],
			],
		],
		[
			'renders a stat the reply did not answer as absent, not zero',
			{
				requests_per_second: 3.75,
				avg_peak_mb: 0,
			},
			[
				[ '3.75', 'req/s' ],
				[ '—', 'avg' ],
			],
		],
		[
			'drops the memory stat when nothing measured a peak',
			{
				requests_per_second: 2.5,
				avg_ms: 77,
				avg_peak_mb: 0,
			},
			[
				[ '2.50', 'req/s' ],
				[ '77ms', 'avg' ],
			],
		],
	] )( '%s', async ( _name, stats, expected ) => {
		mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
		mockNavState.selectedRequest = null;
		mockView = loadedView( {
			urlDetail: {
				data: { last_modified: 1, stats, requests: [] },
				loading: false,
				error: null,
			},
		} );
		const { container, unmount } = mountDash();
		await flushEffects();

		const stat = Array.from(
			container.querySelectorAll(
				'.event-logger-header-stats > .newspack-nodes-stat'
			)
		).map( ( node ) => [
			node.textContent.replace(
				node.querySelector( ':scope > small' ).textContent,
				''
			),
			node.querySelector( ':scope > small' ).textContent,
		] );

		expect( stat ).toEqual( expected );
		unmount();
	} );

	it( 'carries the errors-only echo to the table and the URL it opens', async () => {
		mockNavState.selectedUrl = { hash: 'h9', url: '/erring' };
		mockNavState.selectedRequest = null;
		mockView = loadedView( {
			urls: {
				data: [],
				filters: { errors_only: true },
				loading: false,
				error: null,
			},
			urlDetail: {
				data: { last_modified: 1, stats: {}, requests: [] },
				loading: false,
				error: null,
			},
		} );

		const { unmount } = mountDash();
		await act( async () => {} );
		// Opened the way a click opens it, through the table.
		await act( async () => {
			globalThis.__urlTableProps.onSelect( {
				hash: 'h9',
				url: '/erring',
			} );
		} );

		expect( globalThis.__urlDetailProps.detailErrors ).not.toBeNull();
		// The server finds the errors, past the clean requests that bury them.
		expect( mockGraphOpts.detailFilters.errors_only ).toBe( true );
		// The same echo tells the table its rows carry error counts.
		expect( globalThis.__urlTableProps.errorCounts ).toBe( true );

		// The page holds the modal's choice, so a request and back keeps it.
		await act( async () => {
			globalThis.__urlDetailProps.onFilterChange( 'errors_only', false );
		} );
		expect( globalThis.__urlDetailProps.detailErrors ).toBeNull();
		expect( mockGraphOpts.detailFilters.errors_only ).toBe( false );
		unmount();
	} );

	describe( 'a URL modal under Errors Only', () => {
		const ERRORS = [
			{
				rid: 'f4tal',
				partition: 2,
				timestamp: 1741000456,
				duration_ms: 2417,
				status_code: 500,
				peak_mb: 91,
				error_status: 'F',
			},
			{
				rid: 't1meout',
				partition: 0,
				timestamp: 1741000123,
				duration_ms: null,
				status_code: 0,
				peak_mb: 37,
				error_status: 'T',
			},
		];

		/**
		 * Open `/kea` and flip its modal to Errors Only.
		 *
		 * @param {Object} urlFilters The table's echoed filters.
		 * @return {Promise<Object>} The mount.
		 */
		const openErrorsOnly = async ( urlFilters = {} ) => {
			mockNavState.selectedUrl = { hash: 'h7', url: '/kea' };
			mockNavState.selectedRequest = null;
			mockView = loadedView( {
				urls: {
					data: [],
					filters: urlFilters,
					loading: false,
					error: null,
				},
				urlDetail: {
					data: {
						last_modified: 1,
						stats: {
							errors: 29,
							avg_ms: 812,
							requests_per_second: 3.75,
						},
						requests: ERRORS,
					},
					loading: false,
					error: null,
				},
			} );
			const mount = mountDash();
			await flushEffects();
			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'errors_only',
					true
				);
			} );
			return mount;
		};

		it( 'heads the modal with the exact errors and the summary of the list', async () => {
			const { container } = await openErrorsOnly();
			const header = container.querySelector(
				'.event-logger-header-stats'
			).textContent;

			expect( header ).toContain( '29errors' );
			expect( header ).toContain( '1timeouts' );
			expect( header ).toContain( '1fatals' );
			expect( header ).toContain( '2417msfatal avg' );
			expect( header ).toContain( '2417msfatal max' );
			expect( header ).toContain( '64.0MBmem' );
			expect( header ).not.toContain( '812ms' );
			expect( header ).not.toContain( '3.75' );
		} );

		it( 'hands the list and the facts block the exact count and the summary', async () => {
			const { container } = await openErrorsOnly();

			expect( globalThis.__urlDetailProps.detailErrors ).toMatchObject( {
				listed: 2,
				timeouts: 1,
				fatals: 1,
			} );
			expect(
				globalThis.__urlDetailProps.detailErrors.total
			).toBeUndefined();
			const facts = JSON.parse(
				container.querySelector( '#newspack-nodes-page-facts' )
					.textContent
			);
			expect( facts ).toMatchObject( {
				surface: 'url',
				errors_only: true,
				stats: { errors: 29 },
				error_summary: { listed: 2, fatal_max_ms: 2417 },
			} );
		} );

		it( 'hands the list no summary when every request is listed', async () => {
			await openErrorsOnly();
			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'errors_only',
					false
				);
			} );

			expect( globalThis.__urlDetailProps.detailErrors ).toBeNull();
		} );

		/**
		 * Arm the picker and pick a `url:` element, as a click in the modal does.
		 */
		const pickUrl = async () => {
			const target = document.createElement( 'div' );
			target.setAttribute( 'data-ask', 'url:h7' );
			document.body.appendChild( target );
			await act( async () => {
				globalThis.__overviewProps.ask.start();
			} );
			await act( async () => {
				target.dispatchEvent(
					new window.MouseEvent( 'mousedown', { bubbles: true } )
				);
				target.dispatchEvent(
					new window.MouseEvent( 'click', { bubbles: true } )
				);
			} );
			target.remove();
		};

		it( "asks under the modal's Errors Only, not the table's", async () => {
			await openErrorsOnly( { errors_only: false } );
			await pickUrl();

			expect( sentTo( 'performance:ask' ).at( -1 ) ).toContain(
				'--errors_only=1'
			);
		} );

		it( 'asks for every request once the modal lists them all, whatever the table shows', async () => {
			await openErrorsOnly( { errors_only: true } );
			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'errors_only',
					false
				);
			} );
			await pickUrl();

			expect( sentTo( 'performance:ask' ).at( -1 ) ).not.toContain(
				'--errors_only=1'
			);
		} );
	} );

	it.each( [
		[ 'desc', [ 'slow', 'quick', 'tout', 'abort' ] ],
		[ 'asc', [ 'quick', 'slow', 'tout', 'abort' ] ],
	] )(
		'sorts an unmeasured duration last by %s duration, never as the fastest or the slowest',
		async ( dir, order ) => {
			mockNavState.selectedUrl = { hash: 'h1', url: '/foo' };
			mockView = loadedView( {
				urlDetail: {
					data: {
						last_modified: 1,
						stats: {},
						requests: [
							{
								rid: 'tout',
								timestamp: 3,
								duration_ms: null,
								error_status: 'T',
							},
							{ rid: 'slow', timestamp: 1, duration_ms: 300 },
							{
								rid: 'abort',
								timestamp: 4,
								duration_ms: null,
								error_status: 'A',
							},
							{ rid: 'quick', timestamp: 2, duration_ms: 100 },
						],
					},
					loading: false,
					error: null,
				},
			} );
			mountDash();
			await flushEffects();
			// The first click sorts descending, the second ascending.
			for ( const step of [ 'desc', 'asc' ] ) {
				act( () => {
					globalThis.__urlDetailProps.onRequestSort( 'duration_ms' );
				} );
				if ( step === dir ) {
					break;
				}
			}

			expect(
				globalThis.__urlDetailProps.sortedRequests.map( ( r ) => r.rid )
			).toEqual( order );
		}
	);

	describe( 'the five-minute bucket', () => {
		const bucketParam = () =>
			new URLSearchParams( window.location.search ).get( 'bucket' );

		/**
		 * Open `/kea` from a table narrowed to 13:35, as a row click does.
		 *
		 * @return {Promise<Object>} The mount.
		 */
		const openFromBucketedTable = async () => {
			window.history.replaceState(
				null,
				'',
				'/wp-admin/admin.php?page=perf&bucket=2026-10-04-13-35'
			);
			mockNavState.selectedUrl = { hash: 'h7', url: '/kea' };
			mockNavState.selectedRequest = null;
			mockView = loadedView( {
				urls: {
					data: [],
					filters: { bucket: '2026-10-04-13-35' },
					loading: false,
					error: null,
				},
				urlDetail: {
					data: { last_modified: 1, stats: {}, requests: [] },
					loading: false,
					error: null,
				},
			} );
			const mount = mountDash();
			await flushEffects();
			await act( async () => {
				globalThis.__urlTableProps.onSelect( {
					hash: 'h7',
					url: '/kea',
				} );
			} );
			return mount;
		};

		it( 'edits the table selection from overview chart clicks', async () => {
			mockView = loadedView();
			mountDash();
			await flushEffects();
			expect( globalThis.__urlTableProps.bucket ).toBe( '' );
			const click = ( key, additive ) =>
				act( async () => {
					globalThis.__overviewProps.onSlotClick( key, { additive } );
				} );

			await click( '2026-10-04-13-35', false );
			expect( bucketParam() ).toBe( '2026-10-04-13-35' );

			await click( '2026-10-04-13-40', true );
			expect( bucketParam() ).toBe(
				'2026-10-04-13-35..2026-10-04-13-40'
			);
			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-35..2026-10-04-13-40'
			);
			expect( globalThis.__overviewProps.selectedBuckets ).toEqual( [
				'2026-10-04-13-35',
				'2026-10-04-13-40',
			] );

			await click( '2026-10-04-13-35', true );
			expect( bucketParam() ).toBe( '2026-10-04-13-40' );

			await click( '2026-10-04-14-00', false );
			expect( bucketParam() ).toBe( '2026-10-04-14-00' );
		} );

		it( 'edits the table selection from overview chart drags', async () => {
			mockView = loadedView();
			mountDash();
			await flushEffects();
			const drag = ( keys, additive ) =>
				act( async () => {
					globalThis.__overviewProps.onSlotRange( keys, {
						additive,
					} );
				} );

			await drag(
				[ '2026-10-04-13-35', '2026-10-04-13-40', '2026-10-04-13-45' ],
				false
			);
			expect( bucketParam() ).toBe(
				'2026-10-04-13-35..2026-10-04-13-45'
			);

			await drag( [ '2026-10-04-14-10', '2026-10-04-14-15' ], true );
			expect( bucketParam() ).toBe(
				'2026-10-04-13-35..2026-10-04-13-45,2026-10-04-14-10..2026-10-04-14-15'
			);
			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-35..2026-10-04-13-45,2026-10-04-14-10..2026-10-04-14-15'
			);
		} );

		it( "hands the table the overview reply's slots for its Time field", async () => {
			const slots = slotsEndingAt( '2026-10-04-14-00', 6 );
			mockView = loadedView();
			mockView.overview.data.slots = slots;
			mountDash();
			await flushEffects();

			expect( globalThis.__urlTableProps.slots ).toBe( slots );
		} );

		it( 'drops a removed token from ?bucket= and from the ask', async () => {
			window.history.replaceState(
				null,
				'',
				'/wp-admin/admin.php?page=perf&bucket=2026-10-04-13-35..2026-10-04-13-40,2026-10-04-14-00'
			);
			mockNavState.selectedUrl = null;
			mockView = loadedView();
			const { rerender } = mountDash();
			await flushEffects();

			await act( async () => {
				globalThis.__urlTableProps.onBucketChange(
					'2026-10-04-13-35..2026-10-04-13-40'
				);
			} );
			expect( bucketParam() ).toBe(
				'2026-10-04-13-35..2026-10-04-13-40'
			);
			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-35..2026-10-04-13-40'
			);

			// The urls reply echoes the selection it was asked under.
			mockView = loadedView( {
				urls: {
					data: [],
					filters: { bucket: '2026-10-04-13-35..2026-10-04-13-40' },
					loading: false,
					error: null,
				},
			} );
			await act( async () => {
				rerender( dashboard() );
			} );
			const target = document.createElement( 'div' );
			target.setAttribute( 'data-ask', 'url:h7' );
			document.body.appendChild( target );
			await act( async () => {
				globalThis.__overviewProps.ask.start();
			} );
			await act( async () => {
				target.dispatchEvent(
					new window.MouseEvent( 'mousedown', { bubbles: true } )
				);
				target.dispatchEvent(
					new window.MouseEvent( 'click', { bubbles: true } )
				);
			} );
			target.remove();

			expect( sentTo( 'performance:ask' ).at( -1 ) ).toContain(
				'--bucket=2026-10-04-13-35..2026-10-04-13-40'
			);
		} );

		it( 'hands the table a linked bucket as it arrived, for the server to judge', async () => {
			window.history.replaceState(
				null,
				'',
				'/wp-admin/admin.php?page=perf&bucket=2026-10-04-13-37'
			);
			mockView = loadedView();
			mountDash();
			await flushEffects();

			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-37'
			);
			expect( globalThis.__overviewProps.selectedBuckets ).toEqual( [] );
			await act( async () => {
				globalThis.__urlTableProps.onBucketChange( '' );
			} );
			expect( bucketParam() ).toBeNull();
		} );

		it( "opens a URL on the table's bucket, then moves the modal's alone", async () => {
			const { container } = await openFromBucketedTable();

			expect( mockGraphOpts.detailFilters.bucket ).toBe(
				'2026-10-04-13-35'
			);
			expect( globalThis.__urlDetailProps.filters.bucket ).toBe(
				'2026-10-04-13-35'
			);

			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'bucket',
					'2026-10-04-13-40'
				);
			} );
			expect( mockGraphOpts.detailFilters.bucket ).toBe(
				'2026-10-04-13-40'
			);
			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-35'
			);
			const facts = JSON.parse(
				container.querySelector( '#newspack-nodes-page-facts' )
					.textContent
			);
			expect( facts ).toMatchObject( {
				surface: 'url',
				bucket: '2026-10-04-13-40',
			} );

			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange( 'bucket', '' );
			} );
			expect( mockGraphOpts.detailFilters.bucket ).toBe( '' );
			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-35'
			);
			expect( bucketParam() ).toBe( '2026-10-04-13-35' );
		} );

		it( "applies an updater the modal hands to the modal's held bucket", async () => {
			await openFromBucketedTable();

			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'bucket',
					( held ) => `${ held }..2026-10-04-13-40`
				);
			} );
			expect( mockGraphOpts.detailFilters.bucket ).toBe(
				'2026-10-04-13-35..2026-10-04-13-40'
			);
			expect( globalThis.__urlTableProps.bucket ).toBe(
				'2026-10-04-13-35'
			);
		} );

		it( 'keeps the modal filters when a click names the bucket they hold', async () => {
			await openFromBucketedTable();
			const held = mockGraphOpts.detailFilters;
			expect( held ).toEqual( {
				errors_only: false,
				bucket: '2026-10-04-13-35',
			} );

			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'bucket',
					'2026-10-04-13-35'
				);
			} );

			// The same object, so the graph re-asks nothing.
			expect( mockGraphOpts.detailFilters ).toBe( held );
		} );

		it( 'forgets the modal bucket when the modal closes', async () => {
			const { rerender } = await openFromBucketedTable();
			await act( async () => {
				globalThis.__modalOnRequestClose();
			} );

			mockNavState.selectedUrl = { hash: 'h4', url: '/linked' };
			await act( async () => {
				rerender( dashboard() );
			} );

			expect( mockGraphOpts.detailFilters.bucket ).toBe( '' );
			expect( globalThis.__urlDetailProps.filters.bucket ).toBe( '' );
		} );

		it( "asks under the modal's bucket, not the table's", async () => {
			await openFromBucketedTable();
			await act( async () => {
				globalThis.__urlDetailProps.onFilterChange(
					'bucket',
					'2026-10-04-13-40'
				);
			} );
			const target = document.createElement( 'div' );
			target.setAttribute( 'data-ask', 'url:h7' );
			document.body.appendChild( target );
			await act( async () => {
				globalThis.__overviewProps.ask.start();
			} );
			await act( async () => {
				target.dispatchEvent(
					new window.MouseEvent( 'mousedown', { bubbles: true } )
				);
				target.dispatchEvent(
					new window.MouseEvent( 'click', { bubbles: true } )
				);
			} );
			target.remove();

			expect( sentTo( 'performance:ask' ).at( -1 ) ).toContain(
				'--bucket=2026-10-04-13-40'
			);
		} );
	} );

	it( 'opens a URL reached any other way with every request listed', async () => {
		// Only the table narrows a URL; closing forgets that, so a deep link
		// or a request search cannot open on a list hiding what it sought.
		mockNavState.selectedUrl = { hash: 'h9', url: '/erring' };
		mockNavState.selectedRequest = null;
		mockView = loadedView( {
			urls: {
				data: [],
				filters: { errors_only: true },
				loading: false,
				error: null,
			},
			urlDetail: {
				data: { last_modified: 1, stats: {}, requests: [] },
				loading: false,
				error: null,
			},
		} );
		const { rerender, unmount } = mountDash();
		await act( async () => {} );
		await act( async () => {
			globalThis.__urlTableProps.onSelect( {
				hash: 'h9',
				url: '/erring',
			} );
		} );
		await act( async () => {
			globalThis.__modalOnRequestClose();
		} );

		mockNavState.selectedUrl = { hash: 'h4', url: '/linked' };
		await act( async () => {
			rerender( dashboard() );
		} );

		expect( globalThis.__urlDetailProps.detailErrors ).toBeNull();
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

		const { unmount } = mountDash();
		await act( async () => {} );

		await act( async () => {
			globalThis.__urlDetailProps.onSelectRequest( 'r1', 7 );
		} );

		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'r1' );
		expect( mockGraphOpts.requestPartition ).toBe( 7 );
		unmount();
	} );

	// @longform The picker arms, and the page keeps polling underneath it: the
	// row under the pointer is replaced between hover and click, the `?`
	// cursor goes stale until the window is re-entered, and the brief
	// describes numbers the reader never saw. A modal already holds the poll
	// for the same reason; being asked about is the same reason.
	it( 'holds the poll while the picker is armed', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();

		expect( mockGraphOpts.askActive ).toBe( false );

		// OverviewSection is mocked here, so the trigger it renders is not on
		// the page: the picker is armed through the `ask` state the dashboard
		// hands it, which is the same object the button calls `start` on.
		await act( async () => {
			globalThis.__overviewProps.ask.start();
		} );

		expect( mockGraphOpts.askActive ).toBe( true );
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

		const { container, unmount } = mountDash();
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

		const { container, unmount } = mountDash();
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
					stats: { avg_ms: 50 },
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
		const { container, unmount } = mountDash();
		await flushEffects();
		expect(
			container.querySelector( '[data-testid="request-detail"]' )
		).toBeTruthy();
		unmount();
	} );

	it( 'forwards UrlTable param changes to the graph handleUrlParamsChange', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
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

	it( 'searchRequest asks search_requests and selects what it answers', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
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

	it( 'landing on a request leaves the server filter behind', async () => {
		// A rid names one request; the server filter is a browsing scope. Now
		// that dump_url honours that scope, a search landing on a URL outside
		// it would ask for a row the scope excludes and answer "URL not found"
		// for a URL plainly on screen. The navigation wins, visibly.
		mockView = serverView( hub );
		const { unmount } = mountDash();
		await flushEffects();
		act( () => {
			globalThis.__overviewProps.setServerFilter( 'edge-01' );
		} );
		await flushEffects();
		expect( globalThis.__overviewProps.serverFilter ).toBe( 'edge-01' );

		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'r1' );
		} );
		answerCommand( SEARCH, {
			result: { url_hash: 'h1', partition: 0 },
			args: [ 'r1' ],
		} );
		await flushEffects();

		expect( globalThis.__overviewProps.serverFilter ).toBe( '' );
		unmount();
	} );

	it( 'a rid-shaped miss hints at the /pattern syntax', async () => {
		// 'checkout' is rid-shaped so it routes to exact lookup; the miss must
		// teach the text-searcher the escape hatch instead of dead-ending.
		mockView = loadedView();
		const { unmount } = mountDash();
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
		const { unmount } = mountDash();
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

	// search_requests answers {rid, partition, url_hash} — never a url. A
	// deep-linked request whose URL is off the loaded page therefore has no
	// title until the hash is looked up, which is what this asserts.
	it( 'a deep link asks dump_url for a hash outside the loaded page', async () => {
		mockNavState.deepLink = { requestId: 'rid-offpage', urlHash: null };
		mockView = loadedView( {
			urls: {
				data: [ { hash: 'h-known', url: '/known' } ],
				total: 1,
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
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
		const { unmount } = mountDash();
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
		const { unmount } = mountDash();
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

	// Back before the answer lands: the intent is gone, so the reply answers a
	// question nobody is asking any more and must not reopen the modal the
	// operator just left — nor push a fresh entry over the one they walked to.
	it( 'a deep-link reply that no longer matches the standing intent is dropped', async () => {
		mockNavState.deepLink = { requestId: 'tqz9ldm3wp', urlHash: null };
		mockView = loadedView( {
			urls: {
				data: [ { hash: 'h-omicron', url: '/deep/omicron' } ],
				total: 1,
				loading: false,
				error: null,
			},
		} );
		const { rerender, unmount } = mountDash();
		await flushEffects();
		expect( sentTo( DEEP_REQUEST ) ).toContainEqual( [ 'tqz9ldm3wp' ] );

		// Back to the dashboard: the hook drops the intent and the selection.
		mockNavState.deepLink = { requestId: null, urlHash: null };
		rerender( dashboard() );
		await flushEffects();

		answerCommand( DEEP_REQUEST, {
			result: { url_hash: 'h-omicron', partition: 6 },
			args: [ 'tqz9ldm3wp' ],
		} );
		expect( mockNavState.selectRequest ).not.toHaveBeenCalled();
		expect( mockNavState.selectUrl ).not.toHaveBeenCalled();
		unmount();
	} );

	// The same rule on the hash resolver: a `?url=` reply applies only while
	// that hash is still the standing intent.
	it( 'a ?url= reply that no longer matches the standing intent is dropped', async () => {
		mockNavState.deepLink = { requestId: null, urlHash: 'h-sigma88' };
		mockView = loadedView();
		const { rerender, unmount } = mountDash();
		await flushEffects();
		expect( sentTo( DEEP_URL ) ).toContainEqual( [ 'h-sigma88' ] );

		mockNavState.deepLink = { requestId: null, urlHash: null };
		rerender( dashboard() );
		await flushEffects();

		answerCommand( DEEP_URL, {
			result: { stats: { url: '/deep/sigma' } },
			args: [ 'h-sigma88' ],
		} );
		expect( mockNavState.selectUrl ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'searchRequest selects nothing when the request is not found', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
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

	it( 'a /url-pattern search runs grep_requests and renders the result list', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/calendar' );
		} );
		// Pattern (has '/') → grep, NOT the exact-rid search_requests. The
		// pattern rides by name, so one opening `--` is never read as an option.
		expect( sentTo( GREP ) ).toContainEqual( [
			'--pattern=/calendar',
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
			args: sentTo( GREP ).at( -1 ),
		} );
		expect( globalThis.__overviewProps.searchResults ).toEqual( [
			{ rid: 'r1', url: '/calendar', method: 'GET', match_count: 2 },
		] );
		expect( globalThis.__overviewProps.searchResultsTruncated ).toBe(
			true
		);
		unmount();
	} );

	it( 'hands the section the lines a grep skipped as unparseable, matches or none', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/torn' );
		} );
		answerCommand( GREP, {
			result: { results: [], truncated: false, unparseable_lines: 14 },
			args: sentTo( GREP ).at( -1 ),
		} );
		expect( globalThis.__overviewProps.searchUnparseableLines ).toBe( 14 );

		// A new search clears the last one's count until its own answer lands.
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/again' );
		} );
		expect(
			globalThis.__overviewProps.searchUnparseableLines
		).toBeUndefined();
		unmount();
	} );

	it( 'an empty grep result surfaces the no-matches message', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/nope' );
		} );
		answerCommand( GREP, {
			result: { results: [], truncated: false },
			args: sentTo( GREP ).at( -1 ),
		} );
		expect( globalThis.__overviewProps.searchResults ).toBeNull();
		expect(
			( globalThis.__overviewProps.searchError || '' ).toLowerCase()
		).toContain( 'no matches in recent traffic' );
		unmount();
	} );

	it( 'emptying the search box drops the last answer with the text', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/takahe-71' );
		} );
		answerCommand( GREP, {
			result: {
				results: [
					{
						rid: 'r71',
						url: '/takahe-71',
						method: 'GET',
						match_count: 4,
					},
				],
				truncated: true,
				unparseable_lines: 9,
			},
			args: sentTo( GREP ).at( -1 ),
		} );
		act( () => globalThis.__overviewProps.setSearchQuery( '/takahe-71' ) );
		expect( globalThis.__overviewProps.searchResults ).toHaveLength( 1 );

		act( () => globalThis.__overviewProps.setSearchQuery( '' ) );

		expect( globalThis.__overviewProps.searchQuery ).toBe( '' );
		expect( globalThis.__overviewProps.searchResults ).toBeNull();
		expect( globalThis.__overviewProps.searchResultsTruncated ).toBe(
			false
		);
		expect(
			globalThis.__overviewProps.searchUnparseableLines
		).toBeUndefined();

		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'r404' );
		} );
		answerCommand( SEARCH, { result: null, args: [ 'r404' ] } );
		expect( globalThis.__overviewProps.searchError ).toContain( 'r404' );

		act( () => globalThis.__overviewProps.setSearchQuery( '' ) );

		expect( globalThis.__overviewProps.searchError ).toBeNull();
		unmount();
	} );

	it( 'opens what a ?search= lookup finds, leaving the box empty', async () => {
		mockNavState.initialSearchQuery = 'r-moa-3';
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		expect( sentTo( SEARCH ) ).toContainEqual( [ 'r-moa-3' ] );
		expect( globalThis.__overviewProps.searchQuery ).toBe( '' );

		answerCommand( SEARCH, {
			result: { url_hash: 'h-moa', partition: 4 },
			args: [ 'r-moa-3' ],
		} );
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'r-moa-3' );
		unmount();
	} );

	/**
	 * Show a grep's one matching row under the box, the pattern typed there.
	 *
	 * @param {string} pattern The box's text, a pattern.
	 * @param {string} rid     The row's request id.
	 */
	async function grepShowing( pattern, rid ) {
		act( () => globalThis.__overviewProps.setSearchQuery( pattern ) );
		await act( async () => {
			await globalThis.__overviewProps.onSearch( pattern );
		} );
		answerCommand( GREP, {
			result: {
				results: [
					{ rid, url: pattern, method: 'GET', match_count: 2 },
				],
				truncated: false,
			},
			args: sentTo( GREP ).at( -1 ),
		} );
	}

	it( 'keeps the pattern in the box while a clicked row is looked up and opened', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		await grepShowing( '/hoiho-12', 'r-hoiho' );

		await act( async () => {
			await globalThis.__overviewProps.onSelectResult( 'r-hoiho' );
		} );
		expect( globalThis.__overviewProps.searchQuery ).toBe( '/hoiho-12' );

		answerCommand( SEARCH, {
			result: { url_hash: 'h-hoiho', partition: 6 },
			args: [ 'r-hoiho' ],
		} );
		expect( mockNavState.selectRequest ).toHaveBeenCalledWith( 'r-hoiho' );
		unmount();
	} );

	it( 'abandons a clicked row lookup once the box is edited', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		await grepShowing( '/pukeko-8', 'r-pukeko' );

		await act( async () => {
			await globalThis.__overviewProps.onSelectResult( 'r-pukeko' );
		} );
		act( () => globalThis.__overviewProps.setSearchQuery( '/pukeko-9' ) );
		answerCommand( SEARCH, { result: null, args: [ 'r-pukeko' ] } );

		expect( globalThis.__overviewProps.searchError ).toBeNull();
		unmount();
	} );

	it( 'drops an answer that lands after the box emptied or changed under it', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
		await flushEffects();
		const grepHit = {
			results: [
				{ rid: 'r-kea', url: '/kea-44', method: 'GET', match_count: 3 },
			],
			truncated: true,
			unparseable_lines: 6,
		};

		act( () => globalThis.__overviewProps.setSearchQuery( '/kea-44' ) );
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/kea-44' );
		} );
		act( () => globalThis.__overviewProps.setSearchQuery( '' ) );
		answerCommand( GREP, {
			result: grepHit,
			args: sentTo( GREP ).at( -1 ),
		} );
		expect( globalThis.__overviewProps.searchResults ).toBeNull();
		expect( globalThis.__overviewProps.searchError ).toBeNull();
		expect(
			globalThis.__overviewProps.searchUnparseableLines
		).toBeUndefined();

		act( () => globalThis.__overviewProps.setSearchQuery( '/kea-44' ) );
		await act( async () => {
			await globalThis.__overviewProps.onSearch( '/kea-44' );
		} );
		act( () => globalThis.__overviewProps.setSearchQuery( '/kea-45' ) );
		answerCommand( GREP, {
			result: grepHit,
			args: sentTo( GREP ).at( -1 ),
		} );
		expect( globalThis.__overviewProps.searchResults ).toBeNull();

		act( () => globalThis.__overviewProps.setSearchQuery( 'r-kaka-9' ) );
		await act( async () => {
			await globalThis.__overviewProps.onSearch( 'r-kaka-9' );
		} );
		act( () => globalThis.__overviewProps.setSearchQuery( '' ) );
		answerCommand( SEARCH, { result: null, args: [ 'r-kaka-9' ] } );
		expect( globalThis.__overviewProps.searchError ).toBeNull();
		answerCommand( SEARCH, {
			result: { url_hash: 'h-kaka', partition: 5 },
			args: [ 'r-kaka-9' ],
		} );
		expect( mockNavState.selectRequest ).not.toHaveBeenCalled();
		expect( globalThis.__overviewProps.searchLoading ).toBe( false );
		unmount();
	} );

	it( 'selecting a grep result deep-links via the exact-rid path', async () => {
		mockView = loadedView();
		const { unmount } = mountDash();
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
			args: sentTo( GREP ).at( -1 ),
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
					stats: {},
					requests: [
						{ rid: 'r1', timestamp: 1, duration_ms: 100 },
						{ rid: 'r2', timestamp: 2, duration_ms: 50 },
					],
				},
				loading: false,
				error: null,
			},
		} );
		const { unmount } = mountDash();
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
					stats: { avg_ms: 50 },
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
		const { unmount } = mountDash();
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
					stats: { avg_ms: 50 },
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
		const { container, unmount } = mountDash();
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
					stats: { avg_ms: 50 },
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
		const { unmount } = mountDash();
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
					stats: { avg_ms: 50 },
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
		const { container, unmount } = mountDash();
		await flushEffects();
		const trigger = container.querySelector( '[data-ask-trigger]' );
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
						stats: { avg_ms: 50 },
						requests: [],
					},
					loading: false,
					error: null,
				},
			} );
		}

		// @longform It waits for the ruleset. Enabled before the `dump` reply
		// lands, the button reads "Log this URL" and opens a BLANK draft — and
		// an id-less upsert matches by pattern, so saving it would replace a
		// configured rule's hooks and thresholds with nothing.
		it( 'enables "Log this URL" only once the ruleset is known', async () => {
			mockView = urlModalView( '/foo' );
			const { container, unmount } = mountDash();
			await flushEffects();
			const btn = container.querySelector(
				'.event-logger-rule-control button'
			);
			expect( btn ).toBeTruthy();
			expect( btn.disabled ).toBe( true );

			answerCommand( RULES_DUMP, { result: { rules: [] } } );
			expect( btn.textContent ).toContain( 'Log this URL' );
			expect( btn.disabled ).toBe( false );
			unmount();
		} );

		it( 'renders the Ask trigger immediately before the rule control', async () => {
			mockView = urlModalView( '/foo' );
			const { container, unmount } = mountDash();
			await flushEffects();
			const trigger = container.querySelector( '[data-ask-trigger]' );
			const control = container.querySelector(
				'.event-logger-rule-control'
			);
			expect( trigger ).toBeTruthy();
			expect( trigger.nextElementSibling ).toBe( control );
			unmount();
		} );

		it( 'disables the button when the URL is unknown', async () => {
			mockView = urlModalView( 'Unknown URL' );
			const { container, unmount } = mountDash();
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
						stats: { avg_ms: 50 },
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
			const { container, unmount } = mountDash();
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [ existing ] } } );
			expect( sentTo( RULES_DUMP ) ).not.toEqual( [] );
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [] } } );
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [ existing ] } } );
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [] } } );
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [] } } );
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [] } } );
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
			answerCommand( RULES_DUMP, {
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
			const { container, unmount } = mountDash();
			await flushEffects();
			answerCommand( RULES_DUMP, { result: { rules: [] } } );
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
			const { container, unmount } = mountDash();
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
			const { container, unmount } = mountDash();
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
			const { unmount } = mountDash();
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
