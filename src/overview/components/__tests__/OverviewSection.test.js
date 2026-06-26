/* global KeyboardEvent */
/**
 * Tests for OverviewSection — render-side branches.
 *
 * Children mocked at the module boundary:
 *   - AggregateTimeChart / CategoryTimeChart / RequestProfile (heavy D3).
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
	default: ( { title, mode } ) => `CATEGORY[${ title }:${ mode }]`,
} ) );
jest.mock( '../../RequestProfile', () => ( {
	__esModule: true,
	default: ( { totalMs } ) => `PROFILE[total=${ totalMs }]`,
} ) );

import * as React from 'react';
import OverviewSection from '../OverviewSection';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const baseStats = {
	totalUrls: 7,
	totalRequests: 1500,
	globalAvgMs: 42,
	requestsPerSecond: 1.25,
};

function mount( overview, overrides = {} ) {
	const props = {
		overview,
		filteredStats: baseStats,
		serverFilter: '',
		setServerFilter: jest.fn(),
		serverNames: [],
		searchQuery: '',
		setSearchQuery: jest.fn(),
		searchLoading: false,
		searchError: '',
		onSearch: jest.fn(),
		refreshInterval: '5000',
		setRefreshInterval: jest.fn(),
		chartMetric: 'volume',
		setChartMetric: jest.fn(),
		chartBreakdown: 'status',
		setChartBreakdown: jest.fn(),
		breakdownData: null,
		categoryData: null,
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

	it( 'shows the peak-memory stat only when global_avg_peak_mb > 0', () => {
		const { container: a, unmount: ua } = mount( {} );
		expect( a.textContent ).not.toContain( 'Avg Peak Memory' );
		ua();

		const { container: b, unmount: ub } = mount( {
			global_avg_peak_mb: 12.3,
		} );
		expect( b.textContent ).toContain( 'Avg Peak Memory' );
		expect( b.textContent ).toContain( '12.3' );
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
				categories: { hooks: 1 },
				total_time: 10,
				count: 50,
			},
			global_avg_ms: 30,
		} );
		expect( container.textContent ).toContain( 'PROFILE' );
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
} );
