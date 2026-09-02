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
 *   - keeps the chart panel mounted whatever the selected dimension holds
 *   - mounts the global-leaderboard section when global_leaderboard exists
 *   - resets breakdown to 'status' when serverFilter activates while the
 *     current breakdown is 'server'.
 */

// The chart is mocked; its `breakdownState` resolver is NOT — the panel and
// the chart read the same one.
jest.mock( '../../AggregateTimeChart', () => ( {
	...jest.requireActual( '../../AggregateTimeChart' ),
	__esModule: true,
	default: ( { metric, breakdown, serverFilter, data } ) =>
		`AGGREGATE[metric=${ metric },breakdown=${ breakdown },server=${
			serverFilter || ''
		},totals=${ undefined === data ? 'none' : 'given' }]`,
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
		const trigger = container.querySelector( '[data-ask-trigger]' );
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
		// Read the stat grid, not the whole card: the Metric dropdown offers
		// an "Avg Peak Memory" option whatever the numbers say.
		const statLabels = ( container ) =>
			Array.from(
				container.querySelectorAll( '.newspack-nodes-stat-label' )
			).map( ( label ) => label.textContent );

		const { container: a, unmount: ua } = mount( {} );
		expect( statLabels( a ) ).not.toContain( 'Avg Peak Memory' );
		ua();

		const { container: b, unmount: ub } = mount(
			{ global_avg_peak_mb: 12.3 },
			{ urlTotals: { ...baseTotals, avg_peak_mb: 4.7 } }
		);
		expect( statLabels( b ) ).toContain( 'Avg Peak Memory' );
		expect( b.textContent ).toContain( '4.7' );
		expect( b.textContent ).not.toContain( '12.3' );
		ub();
	} );

	it.each( [
		[ 'has not arrived', null ],
		[ 'arrived with no values', {} ],
	] )(
		'keeps the chart panel up when the dimension %s',
		( _label, breakdownData ) => {
			// The Metric, Breakdown and Server selectors live in that panel,
			// and they are the only way to pick a dimension that draws.
			const { container, unmount } = mount(
				{},
				{ breakdownData, chartBreakdown: 'ua' }
			);
			expect( container.textContent ).toContain( 'AGGREGATE' );
			expect( container.textContent ).toContain( 'Breakdown' );
			unmount();
		}
	);

	it( 'offers the Server selector under a filter with nothing to draw', () => {
		// That selector is the only way to clear a filter which still scopes
		// the stats above and the table below, and a window carrying only
		// worker traffic empties the chart with no error at all.
		const { container, unmount } = mount(
			{},
			{
				breakdownData: {},
				serverFilter: 'edge-01',
				serverNames: [ 'edge-01', 'edge-02' ],
			}
		);
		expect( container.textContent ).toContain( 'Server' );
		unmount();
	} );

	it( 'hands the chart no totals series to legend "Total"', () => {
		// A breakdown is ALWAYS selected here, so the totals are never the
		// requested view — and the chart drew them as "Total" regardless.
		const { container, unmount } = mount(
			{},
			{
				breakdownData: {
					'2026-08-25-09-15': { 'curl/8.7.1': { c: 313 } },
				},
			}
		);
		expect( container.textContent ).toContain( 'totals=none' );
		unmount();
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

	it( 'submits the request search from Enter, with no submit button', () => {
		const onSearch = jest.fn();
		const { container, unmount } = mount(
			{},
			{
				searchQuery: 'rid-123',
				onSearch,
			}
		);
		const input = container.querySelector( 'input[type="text"]' );
		// No sibling dashboard's search carries a submit button; nor does this.
		expect(
			Array.from( container.querySelectorAll( 'button' ) ).some(
				( b ) => 'Find' === b.textContent
			)
		).toBe( false );
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		expect( onSearch ).toHaveBeenCalledTimes( 1 );
		expect( onSearch ).toHaveBeenCalledWith( 'rid-123' );
		unmount();
	} );

	it( 'shows a muted Searching… status while a search is in flight', () => {
		const { container, unmount } = mount(
			{},
			{ searchQuery: 'rid-123', searchLoading: true }
		);
		const status = Array.from(
			container.querySelectorAll( '.newspack-nodes-status' )
		).find( ( n ) => n.textContent.startsWith( 'Searching' ) );
		expect( status ).not.toBeUndefined();
		expect( status.className ).toContain( 'is-muted' );
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
