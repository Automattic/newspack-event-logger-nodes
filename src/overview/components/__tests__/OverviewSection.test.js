/* global globalThis, KeyboardEvent, MouseEvent, Node */
/**
 * Tests for OverviewSection — render-side branches.
 *
 * Children mocked at the module boundary:
 *   - AggregateTimeChart / CategoryTimeChart (heavy D3). RequestProfile is
 *     real: it owns the captioned wrapper this section renders.
 *   - Nothing else: the real SelectControl renders, which is what lets the
 *     option-list tests read `sel.options` straight off the DOM.
 *
 * What we cover:
 *   - returns null when overview is null
 *   - renders the stats grid (URLs, requests, avg ms, req/s)
 *   - shows the optional peak-memory stat only when > 0
 *   - keeps the chart panel mounted whatever the selected dimension holds
 *   - mounts the global-leaderboard section when global_leaderboard exists
 *   - offers the Server breakdown only while `canBreakDownByServer` says the
 *     page can chart that axis. Choosing the active dimension against that
 *     rule belongs to PerformanceDashboard, and is tested there.
 */

// The chart is mocked; its `breakdownState` resolver is NOT — the panel and
// the chart read the same one.
jest.mock( '../../AggregateTimeChart', () => ( {
	...jest.requireActual( '../../AggregateTimeChart' ),
	__esModule: true,
	default: ( {
		metric,
		breakdown,
		serverFilter,
		data,
		slots,
		onSlotClick,
		selectedBuckets,
	} ) => {
		globalThis.__aggregateSlotClick = onSlotClick;
		globalThis.__aggregateSelected = selectedBuckets;
		return `AGGREGATE[metric=${ metric },breakdown=${ breakdown },server=${
			serverFilter || ''
		},totals=${ undefined === data ? 'none' : 'given' }] slots:${
			slots?.[ 0 ] ?? 'none'
		}`;
	},
} ) );
jest.mock( '../../CategoryTimeChart', () => ( {
	__esModule: true,
	default: ( { slots, onSlotClick, selectedBuckets } ) => {
		globalThis.__categorySlotClick = onSlotClick;
		globalThis.__categorySelected = selectedBuckets;
		return `CATEGORY slots:${ slots?.[ 0 ] ?? 'none' }`;
	},
} ) );

import * as React from 'react';
import OverviewSection from '../OverviewSection';
import { breakdownState } from '../../AggregateTimeChart';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { nameTable, slotsEndingAt } from '../../../test-helpers/chartWire';

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
		canBreakDownByServer: true,
		breakdownRead: breakdownState( null ),
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
		const search = container.querySelector( 'input[type="search"]' );
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

	it( 'hands both charts the click that narrows the table to a bucket', () => {
		const onSlotClick = jest.fn();
		const { unmount } = mount(
			{ slots: slotsEndingAt( '2026-10-04-13-35' ) },
			{ onSlotClick }
		);
		expect( globalThis.__aggregateSlotClick ).toBe( onSlotClick );
		expect( globalThis.__categorySlotClick ).toBe( onSlotClick );
		unmount();
	} );

	it( "hands both charts the table's selection to shade", () => {
		const selectedBuckets = [ '2026-10-04-13-25', '2026-10-04-13-35' ];
		const { unmount } = mount(
			{ slots: slotsEndingAt( '2026-10-04-13-35' ) },
			{ selectedBuckets }
		);
		expect( globalThis.__aggregateSelected ).toBe( selectedBuckets );
		expect( globalThis.__categorySelected ).toBe( selectedBuckets );
		unmount();
	} );

	it( 'hands both charts the slots the overview reply named', () => {
		const { container, unmount } = mount( {
			slots: slotsEndingAt( '2026-09-29-14-35' ),
		} );
		expect( container.textContent ).toContain( '] slots:2026-09-29-14-35' );
		expect( container.textContent ).toContain(
			'CATEGORY slots:2026-09-29-14-35'
		);
		unmount();
	} );

	it( 'says the totals start on the hour', () => {
		const { container, unmount } = mount( {} );
		expect(
			container
				.querySelector( '.event-logger-overview-stats' )
				.getAttribute( 'title' )
		).toBe(
			'The current hour so far and the whole hours before it: the window starts on the hour.'
		);
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

	it( 'shows an errors-only total beside the traffic it came from', () => {
		const { container, unmount } = mount(
			{},
			{ urlTotals: { ...baseTotals, errors: 3 } }
		);
		expect( container.textContent ).toContain( 'Total Errors' );
		expect( container.textContent ).toContain( 'Total Requests' );
		unmount();
	} );

	it( 'shows no error total where the reply counts none', () => {
		const { container, unmount } = mount( {} );
		expect( container.textContent ).not.toContain( 'Total Errors' );
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

	it( 'says the totals are provisional when the writer still owes a fold or a ranking', () => {
		// The header skipped an hour the writer has yet to fold or rank, or
		// a read that went unanswered, so the totals run short, and say so.
		const { container: provisional, unmount } = mount(
			{ total_requests: 33049 },
			{
				urlTotals: { ...baseTotals, requests: 9001 },
				urlsProvisional: true,
			}
		);
		// The canonical info banner, a live region, and no bespoke class.
		expect(
			provisional.querySelector(
				'.newspack-nodes-banner.is-info[role="status"]'
			)?.textContent
		).toMatch( /not yet folded or ranked, or a read went unanswered/ );
		unmount();

		const { container: settled } = mount(
			{ total_requests: 33049 },
			{
				urlTotals: { ...baseTotals, requests: 9001 },
				urlsProvisional: false,
			}
		);
		expect( settled.querySelector( '.newspack-nodes-banner' ) ).toBeNull();
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

	it( 'labels the rate recent, since its window straddles the hour', () => {
		const { container } = mount( {}, { urlTotals: baseTotals } );

		expect(
			Array.from(
				container.querySelectorAll( '.newspack-nodes-stat-label' )
			).map( ( label ) => label.textContent )
		).toContain( 'Req/s (recent)' );
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
		[ 'arrived with no values', { names: [], buckets: {} } ],
	] )(
		'keeps the chart panel up when the dimension %s',
		( _label, breakdownData ) => {
			// The Metric, Breakdown and Server selectors live in that panel,
			// and they are the only way to pick a dimension that draws.
			const { container, unmount } = mount(
				{},
				{
					breakdownRead: breakdownState( breakdownData ),
					chartBreakdown: 'ua',
				}
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
				breakdownRead: breakdownState( { names: [], buckets: {} } ),
				serverFilter: 'edge-01',
				serverNames: [ 'edge-01', 'edge-02' ],
			}
		);
		expect( container.textContent ).toContain( 'Server' );
		unmount();
	} );

	it.each( [
		[ 'has not loaded', null ],
		[ 'came back empty', [] ],
		[ 'names one other server', [ 'edge-01' ] ],
	] )(
		'offers a Server select to clear a filter while the list %s',
		( _label, serverNames ) => {
			// A linked filter the list cannot judge still scopes the page.
			const setServerFilter = jest.fn();
			const { container, unmount } = mount(
				{},
				{ serverFilter: 'edge-02', serverNames, setServerFilter }
			);
			const select = Array.from(
				container.querySelectorAll( 'select' )
			).find( ( sel ) =>
				Array.from( sel.options ).some(
					( o ) => 'All Servers' === o.textContent
				)
			);

			expect( select ).toBeTruthy();
			expect( select.value ).toBe( 'edge-02' );
			act( () => {
				select.value = '';
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			} );
			expect( setServerFilter.mock.calls.at( -1 )[ 0 ] ).toBe( '' );
			unmount();
		}
	);

	it( 'hands the chart no totals series to legend "Total"', () => {
		// A breakdown is ALWAYS selected here, so the totals are never the
		// requested view — and the chart drew them as "Total" regardless.
		const { container, unmount } = mount(
			{},
			{
				breakdownRead: breakdownState(
					nameTable( {
						'2026-08-25-09-15': {
							'curl/8.7.1': [ 313, 3130, 3, 313 ],
						},
					} )
				),
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

	it( 'offers the Server breakdown when the page can chart that axis', () => {
		const { container, unmount } = mount(
			{},
			{
				serverNames: [ 'web01', 'web02' ],
				canBreakDownByServer: true,
			}
		);
		const labels = Array.from(
			container.querySelectorAll( 'select' )
		).flatMap( ( sel ) =>
			Array.from( sel.options ).map( ( o ) => o.textContent )
		);

		expect( labels ).toContain( 'Server' );
		unmount();
	} );

	it( 'offers no Server breakdown when the page cannot chart that axis', () => {
		const { container, unmount } = mount(
			{},
			// Two servers, so `isMultiServer` alone would KEEP the option:
			// only the passed rule (a server filter is active) removes it.
			{ serverNames: [ 'web01', 'web02' ], canBreakDownByServer: false }
		);
		const labels = Array.from(
			container.querySelectorAll( 'select' )
		).flatMap( ( sel ) =>
			Array.from( sel.options ).map( ( o ) => o.textContent )
		);

		// One server is not a hub; breaking down by it is a single bar.
		expect( labels ).not.toContain( 'Server' );
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
		const input = container.querySelector( 'input[type="search"]' );
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

	it( "the request search's reset button empties the box through its setter", () => {
		const setSearchQuery = jest.fn();
		const { container, unmount } = mount(
			{},
			{ searchQuery: 'rid-kea-29', setSearchQuery }
		);
		const control = container.querySelector( '.components-search-control' );
		expect( control.querySelector( 'input' ).labels[ 0 ].textContent ).toBe(
			'Request ID or /url pattern'
		);

		act( () => control.querySelector( 'button' ).click() );

		expect( setSearchQuery ).toHaveBeenLastCalledWith( '' );
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

	it( 'shows the lines the search skipped as unparseable, even with no results', () => {
		const { container, unmount } = mount(
			{},
			{ searchResults: null, searchUnparseableLines: 3 }
		);
		expect(
			container.querySelector( '.newspack-nodes-banner.is-warning' )
				.textContent
		).toBe( '3 lines would not parse and were skipped.' );
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
