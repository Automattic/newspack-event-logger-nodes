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
 *   - mounts the leaderboard section when the overview's leaderboard exists
 *   - offers the Server breakdown only while `canBreakDownByServer` says the
 *     page can chart that axis. Choosing the active dimension against that
 *     rule belongs to PerformanceDashboard, and is tested there.
 */

// The chart is mocked; its `breakdownState` resolver is NOT — the panel and
// the chart read the same one.
jest.mock( '../../AggregateTimeChart', () => ( {
	...jest.requireActual( '../../AggregateTimeChart' ),
	__esModule: true,
	default: ( props ) => {
		const {
			metric,
			dims,
			serverFilter,
			series,
			slots,
			onSlotClick,
			onSlotRange,
			selectedBuckets,
		} = props;
		globalThis.__aggregateStorageKey = props.storageKey;
		globalThis.__aggregateSlots = slots;
		globalThis.__aggregateSlotClick = onSlotClick;
		globalThis.__aggregateSlotRange = onSlotRange;
		globalThis.__aggregateSelected = selectedBuckets;
		return `AGGREGATE[metric=${ metric },dims=${ dims.join(
			'+'
		) },server=${ serverFilter || '' },names=${
			series?.names.length ?? 'none'
		}] slots:${ slots?.[ 0 ] ?? 'none' }`;
	},
} ) );
jest.mock( '../../CategoryTimeChart', () => ( {
	__esModule: true,
	default: ( props ) => {
		const { data, slots, onSlotClick, onSlotRange, selectedBuckets } =
			props;
		globalThis.__categoryStorageKey = props.storageKey;
		globalThis.__categorySlots = slots;
		globalThis.__categorySlotClick = onSlotClick;
		globalThis.__categorySlotRange = onSlotRange;
		globalThis.__categorySelected = selectedBuckets;
		return `CATEGORY rows:${ data?.rows.length ?? 'none' } slots:${
			slots?.[ 0 ] ?? 'none'
		}`;
	},
} ) );

import * as React from 'react';
import OverviewSection from '../OverviewSection';
import { SERIES_MEASURES } from '../../constants';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { rowsWire, slotsEndingAt } from '../../../test-helpers/chartWire';

/**
 * A view slice holding a `query` answer, as `useNodeField` hands one over.
 *
 * @param {?Object} data   The answer, or null before one.
 * @param {Object}  [more] Status fields, as `loading` or `error`.
 * @return {Object} The slice.
 */
const sliceOf = ( data, more = {} ) => ( {
	data,
	loading: false,
	error: null,
	...more,
} );

/**
 * A chart answer grouped by `bucket` and `ua`, over the given selection.
 *
 * @param {Array[]} rows   Its rows.
 * @param {string}  bucket The selection it echoes.
 * @return {Object} The answer.
 */
const uaAnswer = ( rows, bucket = '2026-09-29-12-40..2026-09-29-14-40' ) =>
	rowsWire( [ 'bucket', 'ua', ...SERIES_MEASURES ], rows, {
		filters: { filter: {}, bucket, search: '' },
	} );

const baseTotals = {
	urls: 7,
	requests: 1500,
	avg_ms: 42,
	avg_peak_mb: 0,
	requests_per_second: 1.25,
};

/**
 * The section's props over `overview`, with `overrides` set. An overview
 * names 24 slots unless it names its own, as every reply names some.
 *
 * @param {?Object} overview  The overview slice payload.
 * @param {Object}  overrides Props to set.
 * @return {Object} The props.
 */
function mountProps( overview, overrides = {} ) {
	return {
		overview: overview && {
			slots: slotsEndingAt( '2026-09-29-14-35', 24 ),
			...overview,
		},
		urlTotals: baseTotals,
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
		chartBreakdown: 'ua',
		setChartBreakdown: jest.fn(),
		chartThenBy: '',
		setChartThenBy: jest.fn(),
		chartChips: [],
		setChartChips: jest.fn(),
		canBreakDownByServer: true,
		chartSlice: sliceOf( null ),
		categoriesSlice: sliceOf( null ),
		selectedBuckets: [],
		ask: { active: false, start: jest.fn(), cancel: jest.fn() },
		...overrides,
	};
}

/**
 * Mount the section over `overview`, with `overrides` set.
 *
 * @param {?Object} overview  The overview slice payload.
 * @param {Object}  overrides Props to set.
 * @return {Object} The mounted section.
 */
function mount( overview, overrides = {} ) {
	return renderComponent(
		React.createElement(
			OverviewSection,
			mountProps( overview, overrides )
		)
	);
}

beforeEach( () => {
	globalThis.__aggregateStorageKey = undefined;
	globalThis.__categoryStorageKey = undefined;
} );

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

	it( 'names its two charts by the overview placement keys', () => {
		const { unmount } = mount( {} );
		expect( globalThis.__aggregateStorageKey ).toBe(
			'event-logger-overview-chart-aggregate'
		);
		expect( globalThis.__categoryStorageKey ).toBe(
			'event-logger-overview-chart-category'
		);
		unmount();
	} );

	it( 'hands both charts the click that narrows the table to a bucket', () => {
		const onSlotClick = jest.fn();
		const { unmount } = mount( {}, { onSlotClick } );
		expect( globalThis.__aggregateSlotClick ).toBe( onSlotClick );
		expect( globalThis.__categorySlotClick ).toBe( onSlotClick );
		unmount();
	} );

	it( 'hands both charts the drag that selects a span of buckets', () => {
		const onSlotRange = jest.fn();
		const { unmount } = mount( {}, { onSlotRange } );
		expect( globalThis.__aggregateSlotRange ).toBe( onSlotRange );
		expect( globalThis.__categorySlotRange ).toBe( onSlotRange );
		unmount();
	} );

	it( "hands both charts the table's selection to shade", () => {
		const selectedBuckets = [ '2026-10-04-13-25', '2026-10-04-13-35' ];
		const { unmount } = mount( {}, { selectedBuckets } );
		expect( globalThis.__aggregateSelected ).toBe( selectedBuckets );
		expect( globalThis.__categorySelected ).toBe( selectedBuckets );
		unmount();
	} );

	it( 'hands each chart the buckets its own answer read', () => {
		const { container, unmount } = mount(
			{},
			{
				chartSlice: sliceOf(
					uaAnswer( [], '2026-09-29-14-25..2026-09-29-14-40' )
				),
				categoriesSlice: sliceOf(
					uaAnswer( [], '2026-09-29-13-05..2026-09-29-13-20' )
				),
			}
		);
		expect( container.textContent ).toContain( '] slots:2026-09-29-14-35' );
		expect( container.textContent ).toContain(
			'CATEGORY rows:0 slots:2026-09-29-13-15'
		);
		unmount();
	} );

	it( "keeps each chart's slots across a re-render with the same answers", () => {
		const props = {
			chartSlice: sliceOf( uaAnswer( [] ) ),
			categoriesSlice: sliceOf(
				uaAnswer( [], '2026-09-29-13-05..2026-09-29-13-20' )
			),
		};
		const { rerender, unmount } = mount( {}, props );
		const [ aggregate, category ] = [
			globalThis.__aggregateSlots,
			globalThis.__categorySlots,
		];

		rerender(
			React.createElement( OverviewSection, {
				...mountProps( {}, props ),
				searchQuery: 'kea',
			} )
		);

		expect( globalThis.__aggregateSlots ).toBe( aggregate );
		expect( globalThis.__categorySlots ).toBe( category );
		unmount();
	} );

	it( 'charts one dimension where Then by repeats Breakdown', () => {
		const { container, unmount } = mount(
			{},
			{
				chartThenBy: 'ua',
				chartSlice: sliceOf(
					uaAnswer( [ [ '2026-09-29-14-35', 'kea/7', 2, 9, 1, 2 ] ] )
				),
			}
		);
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=volume,dims=ua,server=,names=1]'
		);
		unmount();
	} );

	it( 'hands the chart the series its answer holds, by both dimensions', () => {
		const { container, unmount } = mount(
			{},
			{
				chartThenBy: 'server',
				chartSlice: sliceOf(
					rowsWire(
						[ 'bucket', 'ua', 'server', ...SERIES_MEASURES ],
						[
							[
								'2026-09-29-14-35',
								'kea/7',
								'a.example',
								2,
								9,
								1,
								2,
							],
							[
								'2026-09-29-14-35',
								'tui/2',
								'a.example',
								1,
								4,
								1,
								1,
							],
						]
					)
				),
			}
		);
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=volume,dims=ua+server,server=,names=2]'
		);
		unmount();
	} );

	it( 'says the chart is loading, and prints what refused it', () => {
		const loading = mount(
			{},
			{ chartSlice: sliceOf( null, { loading: true } ) }
		);
		expect( loading.container.textContent ).toContain( 'Loading…' );
		loading.unmount();

		const refused = mount(
			{},
			{
				chartSlice: sliceOf( null, {
					error: 'unknown requests field plumage',
				} ),
			}
		);
		expect(
			refused.container.querySelector( '.newspack-nodes-status.is-error' )
				.textContent
		).toBe( 'unknown requests field plumage' );
		refused.unmount();
	} );

	it( 'hands the chips their setter, and offers them every filter field', () => {
		const setChartChips = jest.fn();
		const { container, unmount } = mount(
			{},
			{ chartChips: [ 'country=NZ' ], setChartChips }
		);
		expect( container.textContent ).toContain( 'Country = NZ' );
		act( () => {
			container
				.querySelector( 'button[aria-label="Remove Country = NZ"]' )
				.click();
		} );
		expect( setChartChips ).toHaveBeenCalledWith( [] );
		unmount();
	} );

	it( "captions the stats grid with the span its chart's answer read", () => {
		const { container, unmount } = mount(
			{ slots: slotsEndingAt( '2026-09-29-14-35', 24 ) },
			{
				chartSlice: sliceOf(
					uaAnswer( [], '2026-09-29-11-40..2026-09-29-14-40' )
				),
			}
		);

		expect(
			container
				.querySelector( '.event-logger-overview-stats' )
				.getAttribute( 'title' )
		).toBe(
			'The last 3 hours in five-minute buckets, the current one so far.'
		);
		unmount();
	} );

	it( 'captions the stats grid with no span before its chart answers', () => {
		const { container, unmount } = mount( {} );

		expect(
			container
				.querySelector( '.event-logger-overview-stats' )
				.hasAttribute( 'title' )
		).toBe( false );
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

	it( 'shows Total Errors on every page, zero included', () => {
		const { container, unmount } = mount(
			{},
			{ urlTotals: { ...baseTotals, errors: 0 } }
		);
		const stat = Array.from(
			container.querySelectorAll( '.newspack-nodes-stat' )
		).find( ( el ) => el.textContent.includes( 'Total Errors' ) );
		expect( stat.textContent ).toBe( '0Total Errors' );
		unmount();
	} );

	it( 'holds Total Errors as a dash until the reply counts it', () => {
		const { container, unmount } = mount( {} );
		const stat = Array.from(
			container.querySelectorAll( '.newspack-nodes-stat' )
		).find( ( el ) => el.textContent.includes( 'Total Errors' ) );
		expect( stat.textContent ).toBe( '—Total Errors' );
		unmount();
	} );

	it( "divides a server-scoped breakdown by that server's average", () => {
		// The card's heading is "Time Breakdown (edge-01)" and its categories
		// come from build_leaderboard( server ), so the denominator has to be
		// that server's average — not the site's, and not the filtered URL
		// set's, which is a narrower question than the card is asking.
		const { container } = mount(
			{
				leaderboard: {
					categories: { db: { time: 95.1, count: 3 } },
					total_time: 95.1,
					count: 1,
					avg_ms: 317,
				},
			},
			{ serverFilter: 'edge-01' }
		);

		// 95.1ms of db time against a 317ms average reads as 30.0%.
		expect( container.textContent ).toContain( '30.0%' );
	} );

	it( 'counts the URLs the filters actually selected', () => {
		// Every headline number describes the set the table below lists, so a
		// server filter narrows this count instead of standing apart from it.
		const { container } = mount(
			{},
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
		// A plausible zero hides the gap: `0 Unique URLs` beside
		// 33,049 requests reads as a fact. Before the first reply lands there is
		// no answer yet, and saying so is the honest render.
		const { container } = mount( {}, { urlTotals: null } );

		expect( container.textContent ).toContain( '—' );
		expect( container.textContent ).not.toContain( '0Unique URLs' );
	} );

	it( 'labels the rate per second over the window', () => {
		const { container } = mount( {}, { urlTotals: baseTotals } );

		expect(
			Array.from(
				container.querySelectorAll( '.newspack-nodes-stat-label' )
			).map( ( label ) => label.textContent )
		).toContain( 'Req/s' );
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
			{ totals: { avg_peak_mb: 12.3 } },
			{ urlTotals: { ...baseTotals, avg_peak_mb: 4.7 } }
		);
		expect( statLabels( b ) ).toContain( 'Avg Peak Memory' );
		expect( b.textContent ).toContain( '4.7' );
		expect( b.textContent ).not.toContain( '12.3' );
		ub();
	} );

	it.each( [
		[ 'has not arrived', null ],
		[ 'arrived with no values', uaAnswer( [] ) ],
	] )(
		'keeps the chart panel up when the dimension %s',
		( _label, answer ) => {
			// The Metric, Breakdown and Server selectors live in that panel,
			// and they are the only way to pick a dimension that draws.
			const { container, unmount } = mount(
				{},
				{ chartSlice: sliceOf( answer ) }
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
				chartSlice: sliceOf( uaAnswer( [] ) ),
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

	it( "mounts the leaderboard section from the overview's leaderboard", () => {
		const { container, unmount } = mount( {
			leaderboard: {
				categories: { hooks: { time: 10, count: 4 } },
				total_time: 10,
				count: 50,
				avg_ms: 30,
			},
		} );
		expect( container.textContent ).toContain( 'Total Profiled' );
		expect( container.textContent ).toContain( 'Average breakdown' );
		expect( container.textContent ).toContain( '50' );
		unmount();
	} );

	it( 'captions no breakdown over 0 requests when the window profiled none', () => {
		// A PHP empty array arrives as `[]`, which is truthy.
		const { container, unmount } = mount( {
			leaderboard: {
				categories: [],
				total_time: null,
				count: 0,
				avg_ms: 30,
			},
		} );
		expect( container.textContent ).not.toContain( 'Average breakdown' );
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
