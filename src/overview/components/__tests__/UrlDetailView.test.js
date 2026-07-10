/* global KeyboardEvent */
/**
 * Tests for UrlDetailView — heavy child components mocked at the
 * module boundary. We exercise:
 *   - sortable column headers fire onRequestSort
 *   - "Errors Only" toggle filters the request list
 *   - clicking a request row fires onSelectRequest
 *   - aggregate flame / breakdown / category time series sections
 *     mount when their data is present.
 *   - fetchUrlBreakdown is called with the urlHash + initial breakdown
 *     on first render
 */

// Mock useVirtualization: no modal ancestor in tests → real hook crashes.
jest.mock( '@newspack-nodes/shared/hooks/useVirtualization', () => ( {
	__esModule: true,
	default: ( _ref, _row, total ) => ( {
		startIndex: 0,
		endIndex: total,
		paddingTop: 0,
		paddingBottom: 0,
		offsetTop: 0,
		totalHeight: total * 40,
	} ),
} ) );

jest.mock( '../../FlameGraph', () => ( {
	__esModule: true,
	default: () => 'FLAME_GRAPH',
} ) );
jest.mock( '../../ResponseTimeChart', () => ( {
	__esModule: true,
	default: () => 'RESPONSE_TIME_CHART',
} ) );
jest.mock( '../../RequestProfile', () => ( {
	__esModule: true,
	default: () => 'REQUEST_PROFILE',
} ) );
jest.mock( '../../AggregateTimeChart', () => ( {
	__esModule: true,
	default: () => 'AGGREGATE',
} ) );
jest.mock( '../../CategoryTimeChart', () => ( {
	__esModule: true,
	default: ( { title } ) => `CATEGORY[${ title }]`,
} ) );

import * as React from 'react';
import UrlDetailView from '../UrlDetailView';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const baseUrlDetail = {
	stats: { avg_ms: 100 },
	requests: [],
};

const REQUESTS = [
	{
		rid: 'r1',
		timestamp: 1748960000,
		method: 'GET',
		duration_ms: 100,
		peak_mb: 4,
		status_code: 200,
	},
	{
		rid: 'r2',
		timestamp: 1748960001,
		method: 'POST',
		duration_ms: 250,
		peak_mb: 8,
		status_code: 500,
		error_status: 'F',
	},
	{
		rid: 'r3',
		timestamp: 1748960002,
		method: 'GET',
		duration_ms: 50,
		peak_mb: 2,
		status_code: 200,
	},
];

function mount( overrides = {} ) {
	const props = {
		urlDetail: baseUrlDetail,
		sortedRequests: REQUESTS,
		requestSort: { field: 'timestamp', dir: 'desc' },
		onRequestSort: jest.fn(),
		onSelectRequest: jest.fn(),
		fetchUrlBreakdown: jest.fn().mockResolvedValue( null ),
		urlHash: 'deadbeef',
		...overrides,
	};
	return {
		props,
		...renderComponent( React.createElement( UrlDetailView, props ) ),
	};
}

describe( 'UrlDetailView', () => {
	it( 'renders the recent-requests heading with the full count', () => {
		const { container, unmount } = mount();
		expect( container.textContent ).toContain( 'Recent Requests (3)' );
		unmount();
	} );

	it( 'renders REQUEST_ID, method, status, duration, memory cells', () => {
		const { container, unmount } = mount();
		const text = container.textContent;
		expect( text ).toContain( 'r1' );
		expect( text ).toContain( 'r2' );
		expect( text ).toContain( 'r3' );
		expect( text ).toContain( 'GET' );
		expect( text ).toContain( 'POST' );
		expect( text ).toContain( '4MB' );
		unmount();
	} );

	it( 'toggles "Errors Only" filter on click', () => {
		const { container, unmount } = mount();
		const button = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Errors Only' );
		act( () => {
			button.click();
		} );
		// Only r2 (error_status=F) remains.
		expect( container.textContent ).toContain( 'Recent Requests (1)' );
		expect( container.textContent ).toContain( 'r2' );
		expect( container.textContent ).not.toContain( 'r1' );
		unmount();
	} );

	it( 'fires onRequestSort when a sort header is clicked', () => {
		const onRequestSort = jest.fn();
		const { container, unmount } = mount( { onRequestSort } );
		const durationHeader = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Duration' ) );
		act( () => {
			durationHeader.click();
		} );
		expect( onRequestSort ).toHaveBeenCalledWith( 'duration_ms' );
		unmount();
	} );

	it( 'fires onSelectRequest when a row is clicked', () => {
		const onSelectRequest = jest.fn();
		const { container, unmount } = mount( { onSelectRequest } );
		const r1Row = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		).find( ( r ) => r.textContent.includes( 'r1' ) );
		act( () => {
			r1Row.click();
		} );
		expect( onSelectRequest ).toHaveBeenCalledWith( 'r1' );
		unmount();
	} );

	it( 'fires onSelectRequest from keyboard activation on a request row', () => {
		const onSelectRequest = jest.fn();
		const { container, unmount } = mount( { onSelectRequest } );
		const r1Row = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		).find( ( r ) => r.textContent.includes( 'r1' ) );
		act( () => {
			r1Row.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		expect( onSelectRequest ).toHaveBeenCalledWith( 'r1' );
		unmount();
	} );

	it( 'renders timed-out request status rows', () => {
		const { container, unmount } = mount( {
			sortedRequests: [
				{
					rid: 'r-timeout',
					timestamp: 1748960003,
					duration_ms: 0,
					peak_mb: 0,
					error_status: 'T',
				},
			],
		} );
		expect( container.textContent ).toContain( 'r-timeout' );
		expect( container.textContent ).toContain( 'T' );
		unmount();
	} );

	it( 'mounts AggregateTimeChart when stats.time_series is populated', () => {
		const { container, unmount } = mount( {
			urlDetail: { ...baseUrlDetail, stats: { time_series: { a: 1 } } },
		} );
		expect( container.textContent ).toContain( 'AGGREGATE' );
		unmount();
	} );

	it( 'mounts FlameGraph when aggregate_flame has children', async () => {
		const { container, unmount } = mount( {
			urlDetail: {
				...baseUrlDetail,
				aggregate_flame: { children: [ { name: 'x' } ] },
			},
		} );
		// FlameGraph is lazy; flush its import in act so it doesn't warn.
		await act( async () => {} );
		expect( container.textContent ).toContain( 'Aggregate Flame Graph' );
		unmount();
	} );

	it( 'mounts RequestProfile when aggregate_profiles.categories is present', () => {
		const { container, unmount } = mount( {
			urlDetail: {
				...baseUrlDetail,
				aggregate_profiles: {
					categories: { hooks: 1 },
					count: 100,
					total_time: 10,
				},
			},
		} );
		expect( container.textContent ).toContain( 'REQUEST_PROFILE' );
		expect( container.textContent ).toContain(
			'Average breakdown across 100 requests'
		);
		unmount();
	} );

	it( 'mounts CategoryTimeChart triple when category_time_series is present', () => {
		const { container, unmount } = mount( {
			urlDetail: {
				...baseUrlDetail,
				category_time_series: { hooks: [] },
			},
		} );
		expect( container.textContent ).toContain(
			'CATEGORY[Time by Category]'
		);
		expect( container.textContent ).toContain(
			'CATEGORY[Events by Category]'
		);
		expect( container.textContent ).toContain(
			'CATEGORY[Average Time per Event]'
		);
		unmount();
	} );

	it( 'calls fetchUrlBreakdown(urlHash, initialBreakdown) on mount', () => {
		const fetchUrlBreakdown = jest.fn().mockResolvedValue( null );
		const { unmount } = mount( { fetchUrlBreakdown } );
		expect( fetchUrlBreakdown ).toHaveBeenCalledWith(
			'deadbeef',
			'status'
		);
		unmount();
	} );

	it( 're-fetches the selected breakdown on the five-minute interval', () => {
		jest.useFakeTimers();
		const fetchUrlBreakdown = jest.fn().mockResolvedValue( null );
		const { unmount } = mount( { fetchUrlBreakdown } );
		act( () => {
			jest.advanceTimersByTime( 300000 );
		} );
		expect( fetchUrlBreakdown ).toHaveBeenCalledTimes( 2 );
		expect( fetchUrlBreakdown ).toHaveBeenLastCalledWith(
			'deadbeef',
			'status'
		);
		unmount();
		jest.useRealTimers();
	} );

	it( 'clears breakdown data when no breakdown fetcher is available', () => {
		const { container, unmount } = mount( {
			fetchUrlBreakdown: null,
			urlHash: null,
			urlDetail: { ...baseUrlDetail, stats: { time_series: { a: 1 } } },
		} );
		expect( container.textContent ).toContain( 'AGGREGATE' );
		unmount();
	} );

	it( 'shows "No requests" when sortedRequests is empty', () => {
		const { container, unmount } = mount( { sortedRequests: [] } );
		expect( container.textContent ).toContain( 'No requests to display' );
		unmount();
	} );
} );
