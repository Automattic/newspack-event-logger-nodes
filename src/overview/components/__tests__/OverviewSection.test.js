/* global KeyboardEvent, MouseEvent, Node */
/**
 * Tests for OverviewSection — render-side branches.
 *
 * Children mocked at the module boundary:
 *   - AggregateTimeChart / CategoryTimeChart (heavy D3). RequestProfile is
 *     real: it owns the captioned wrapper this section renders.
 *   - @wordpress/components: lightweight stubs so we can drive state from
 *     the test without pulling in the full component library's CSS-in-JS.
 *
 * What we cover:
 *   - returns null when overview is null
 *   - renders the stats grid (URLs, requests, avg ms, req/s)
 *   - shows the optional peak-memory stat only when > 0
 *   - mounts AggregateTimeChart only when aggregate_time_series is present
 *   - mounts the global-leaderboard section when global_leaderboard exists
 *   - resets breakdown to 'status' when serverFilter activates while the
 *     current breakdown is 'server'.
 */

jest.mock( '../../AggregateTimeChart', () => ( {
	__esModule: true,
	default: ( { metric, breakdown, serverFilter } ) =>
		`AGGREGATE[metric=${ metric },breakdown=${ breakdown },server=${
			serverFilter || ''
		}]`,
} ) );
jest.mock( '../../CategoryTimeChart', () => ( {
	__esModule: true,
	default: () => 'CATEGORY',
} ) );

import * as React from 'react';
import OverviewSection from '../OverviewSection';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const baseTotals = {
	urls: 7,
	requests: 1500,
	avg_ms: 42,
	avg_peak_mb: 0,
	requests_per_second: 1.25,
};

function mount( overview, overrides = {} ) {
	const props = {
		overview,
		urlTotals: baseTotals,
		breakdownAvgMs: 42,
		serverFilter: '',
		setServerFilter: jest.fn(),
		serverNames: [],
		searchQuery: '',
		setSearchQuery: jest.fn(),
		searchLoading: false,
		searchError: '',
		onSearch: jest.fn(),
		searchResults: null,
		searchResultsTruncated: false,
		onSelectResult: jest.fn(),
		refreshInterval: '5000',
		setRefreshInterval: jest.fn(),
		chartMetric: 'volume',
		setChartMetric: jest.fn(),
		chartBreakdown: 'status',
		setChartBreakdown: jest.fn(),
		breakdownData: null,
		categoryData: null,
		ask: { active: false, start: jest.fn(), cancel: jest.fn() },
		...overrides,
	};
	return renderComponent( React.createElement( OverviewSection, props ) );
}

describe( 'OverviewSection', () => {
	it( 'returns null when overview is null', () => {
		const { container, unmount } = mount( null );
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'renders the Ask trigger immediately before the search box', () => {
		const start = jest.fn();
		const { container, unmount } = mount(
			{},
			{ ask: { active: false, start, cancel: jest.fn() } }
		);
		const trigger = container.querySelector( '.event-logger-ask__trigger' );
		const search = container.querySelector( 'input[type="text"]' );
		expect( trigger ).toBeTruthy();
		expect( search ).toBeTruthy();
		expect(
			trigger.compareDocumentPosition( search ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		trigger.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( start ).toHaveBeenCalled();
		unmount();
	} );

	it( 'renders the stats grid values', () => {
		const { container, unmount } = mount( {} );
		const text = container.textContent;
		expect( text ).toContain( '7' );
		expect( text ).toContain( '1,500' );
		expect( text ).toContain( '42ms' );
		expect( text ).toContain( '1.25' );
		expect( text ).toContain( 'Unique URLs' );
		expect( text ).toContain( 'Total Requests' );
		unmount();
	} );

	it( "divides a server-scoped breakdown by that server's average", () => {
		// The card's heading is "Time Breakdown (edge-01)" and its categories
		// come from build_leaderboard( server ), so the denominator has to be
		// that server's average — not the site's, and not the filtered URL
		// set's, which is a narrower question than the card is asking.
		const { container } = mount(
			{
				total_requests: 33049,
				global_avg_ms: 42,
				global_leaderboard: {
					categories: { db: { time: 95.1, count: 3 } },
					total_time: 95.1,
					count: 1,
				},
			},
			{ serverFilter: 'edge-01', breakdownAvgMs: 317 }
		);

		// 95.1ms of db time against a 317ms average reads as 30.0%.
		expect( container.textContent ).toContain( '30.0%' );
	} );

	it( 'says the charts cannot follow the worker toggle', () => {
		// `$count_global` keeps worker traffic out of `hourly`, `dim` and the
		// leaderboards entirely, so with Include Workers ON the header counts
		// requests the chart and the Time Breakdown beneath it structurally
		// cannot. The panel says so rather than letting the two disagree in
		// silence — which is the defect the toggle was built to remove.
		const { container } = mount(
			{
				total_requests: 33049,
				aggregate_time_series: { b1: { count: 5 } },
			},
			{ urlFilters: { include_workers: true } }
		);

		expect( container.textContent ).toContain( 'excludes worker' );
	} );

	it( 'counts the URLs the filters actually selected', () => {
		// Every headline number describes the set the table below lists, so a
		// server filter narrows this count instead of standing apart from it.
		const { container } = mount(
			{ total_requests: 33049 },
			{
				serverFilter: 'edge-01',
				urlTotals: { ...baseTotals, urls: 313, requests: 9001 },
			}
		);

		expect( container.textContent ).toContain( '313' );
		expect( container.textContent ).toContain( '9,001' );
		expect( container.textContent ).toContain( 'Unique URLs' );
		expect( container.textContent ).not.toContain( 'all servers' );
	} );

	it( 'says an absent total is absent, not zero', () => {
		// A plausible zero is how the original bug hid: `0 Unique URLs` beside
		// 33,049 requests read as a fact. Before the first reply lands there is
		// no answer yet, and saying so is the honest render.
		const { container } = mount(
			{ total_requests: 33049 },
			{ urlTotals: null }
		);

		expect( container.textContent ).toContain( '—' );
		expect( container.textContent ).not.toContain( '0Unique URLs' );
	} );

	it( 'shows the peak-memory stat only when the displayed stats include it', () => {
		const { container: a, unmount: ua } = mount( {} );
		expect( a.textContent ).not.toContain( 'Avg Peak Memory' );
		ua();

		const { container: b, unmount: ub } = mount(
			{ global_avg_peak_mb: 12.3 },
			{ urlTotals: { ...baseTotals, avg_peak_mb: 4.7 } }
		);
		expect( b.textContent ).toContain( 'Avg Peak Memory' );
		expect( b.textContent ).toContain( '4.7' );
		expect( b.textContent ).not.toContain( '12.3' );
		ub();
	} );

	it( 'mounts AggregateTimeChart only when aggregate_time_series is populated', () => {
		const { container: a, unmount: ua } = mount( {} );
		expect( a.textContent ).not.toContain( 'AGGREGATE' );
		ua();

		const { container: b, unmount: ub } = mount( {
			aggregate_time_series: { bucket1: { count: 1 } },
		} );
		expect( b.textContent ).toContain( 'AGGREGATE' );
		ub();
	} );

	it( 'mounts the global-leaderboard section when global_leaderboard.categories is present', () => {
		const { container, unmount } = mount( {
			global_leaderboard: {
				categories: { hooks: { time: 10, count: 4 } },
				total_time: 10,
				count: 50,
			},
			global_avg_ms: 30,
		} );
		expect( container.textContent ).toContain( 'Total Profiled' );
		expect( container.textContent ).toContain( 'Average breakdown' );
		expect( container.textContent ).toContain( '50' );
		unmount();
	} );

	it( 'resets breakdown to "status" when serverFilter activates with breakdown=server', () => {
		const setChartBreakdown = jest.fn();
		const { unmount } = mount(
			{},
			{
				serverFilter: 'web01',
				chartBreakdown: 'server',
				setChartBreakdown,
				serverNames: [ 'web01', 'web02' ],
			}
		);
		expect( setChartBreakdown ).toHaveBeenCalledWith( 'status' );
		unmount();
	} );

	it( 'submits the request search from Enter and the Find button', () => {
		const onSearch = jest.fn();
		const { container, unmount } = mount(
			{},
			{
				searchQuery: 'rid-123',
				onSearch,
			}
		);
		const input = container.querySelector( 'input[type="text"]' );
		const findButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Find' );
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		act( () => {
			findButton.click();
		} );
		expect( onSearch ).toHaveBeenCalledTimes( 2 );
		expect( onSearch ).toHaveBeenNthCalledWith( 1, 'rid-123' );
		expect( onSearch ).toHaveBeenNthCalledWith( 2, 'rid-123' );
		unmount();
	} );

	it( 'renders request-search failures as compact inline status text', () => {
		const { container, unmount } = mount(
			{},
			{ searchError: 'Search unavailable' }
		);
		const error = Array.from( container.querySelectorAll( 'span' ) ).find(
			( element ) => 'Search unavailable' === element.textContent
		);
		expect( error.className ).toBe( 'newspack-nodes-status is-error' );
		unmount();
	} );

	it( 'renders pattern-search results and deep-links on a row click', () => {
		const onSelectResult = jest.fn();
		const { container, unmount } = mount(
			{},
			{
				searchResults: [
					{
						rid: 'r1',
						url: '/calendar',
						method: 'GET',
						match_count: 3,
					},
					{
						rid: 'r2',
						url: '/feed',
						method: 'POST',
						match_count: 1,
					},
				],
				onSelectResult,
			}
		);
		expect( container.textContent ).toContain( '/calendar' );
		expect( container.textContent ).toContain( 'GET' );
		// The panel labels its scope so the "recent traffic" limit is honest.
		expect( container.textContent.toLowerCase() ).toContain(
			'recent traffic'
		);
		const row = container.querySelector(
			'.event-logger-search-results button'
		);
		expect( row.className ).toBe(
			'button button-small event-logger-search-result'
		);
		act( () => {
			row.click();
		} );
		expect( onSelectResult ).toHaveBeenCalledWith( 'r1' );
		unmount();
	} );

	it( 'shows a truncation note when the result set is capped', () => {
		const { container, unmount } = mount(
			{},
			{
				searchResults: [
					{ rid: 'r1', url: '/x', method: 'GET', match_count: 1 },
				],
				searchResultsTruncated: true,
			}
		);
		expect( container.textContent.toLowerCase() ).toContain(
			'showing first'
		);
		unmount();
	} );

	it( 'renders no results list when searchResults is empty', () => {
		const { container, unmount } = mount( {}, { searchResults: [] } );
		expect(
			container.querySelector( '.event-logger-search-results' )
		).toBeNull();
		unmount();
	} );
} );
