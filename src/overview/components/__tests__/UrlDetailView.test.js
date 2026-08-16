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
import { Core, VALUE } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import UrlDetailView from '../UrlDetailView';
import {
	renderComponent,
	waitFor,
	act,
} from '../../../test-helpers/renderHook';

const baseUrlDetail = {
	stats: { avg_ms: 100 },
	requests: [],
};

const REQUESTS = [
	{
		rid: 'r1',
		partition: 5,
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

// The view holds its own `url_detail` breakdown read, so every render mounts a
// graph and needs a wire — not just the test that asserts what it sends.
let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = installFakeCommandWire( () => ( { breakdown_time_series: {} } ) );
} );

function mount( overrides = {} ) {
	const props = {
		urlDetail: baseUrlDetail,
		sortedRequests: REQUESTS,
		requestSort: { field: 'timestamp', dir: 'desc' },
		onRequestSort: jest.fn(),
		onSelectRequest: jest.fn(),
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

	it( 'keeps Recent Requests controls outside the canonical bordered list surface', () => {
		const { container, unmount } = mount();
		const root = container.querySelector( '.event-logger-table--requests' );
		const toolbar = root.querySelector( 'h3' ).parentElement;
		const header = root.querySelector( '.newspack-nodes-table__header' );
		const list = root.querySelector( '.event-logger-table__list' );

		expect( root.classList.contains( 'newspack-nodes-table' ) ).toBe(
			false
		);
		expect( toolbar.closest( '.newspack-nodes-table' ) ).toBeNull();
		expect( list.classList.contains( 'newspack-nodes-table' ) ).toBe(
			true
		);
		expect( list.previousElementSibling ).toBe( header );
		unmount();
	} );

	it( 'the header has exactly as many cells as the grid has columns', () => {
		// A 7th child wrapped onto an implicit 8px-gapped grid row and
		// inflated the header height (the --requests template is 6 tracks).
		const { container, unmount } = mount();
		const header = container.querySelector( '.event-logger-table__header' );
		expect( header.children.length ).toBe( 6 );
		for ( const sortButton of header.querySelectorAll(
			'.event-logger-table__header-btn'
		) ) {
			expect(
				sortButton.classList.contains(
					'newspack-nodes-sortable-header-button'
				)
			).toBe( true );
			expect(
				sortButton.classList.contains( 'newspack-nodes-table__cell' )
			).toBe( true );
			expect( sortButton.classList.contains( 'button' ) ).toBe( false );
			expect( sortButton.classList.contains( 'button-small' ) ).toBe(
				false
			);
		}
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

	it( 'binds every request status kind to the shared CSS contract', async () => {
		const { container, unmount } = mount( {
			sortedRequests: [
				{
					rid: 'success-218',
					timestamp: 1748960101,
					status_code: 218,
				},
				{
					rid: 'redirect-307',
					timestamp: 1748960102,
					status_code: 307,
				},
				{
					rid: 'client-418',
					timestamp: 1748960103,
					status_code: 418,
				},
				{
					rid: 'server-599',
					timestamp: 1748960104,
					status_code: 599,
				},
				{
					rid: 'timeout',
					timestamp: 1748960105,
					error_status: 'T',
				},
				{
					rid: 'fatal',
					timestamp: 1748960106,
					error_status: 'F',
				},
			],
		} );
		await act( async () => {} );
		const statuses = Object.fromEntries(
			[ ...container.querySelectorAll( '.event-logger-table__row' ) ].map(
				( row ) => {
					const cell = row.querySelector(
						'.event-logger-table__cell--status'
					);
					return [
						row.querySelector( 'code' ).textContent,
						{
							className: cell.className,
							color: cell.style.color,
							status: cell.dataset.status,
						},
					];
				}
			)
		);

		for ( const [ rid, status ] of [
			[ 'success-218', '218' ],
			[ 'redirect-307', '307' ],
			[ 'client-418', '418' ],
			[ 'server-599', '599' ],
		] ) {
			expect( statuses[ rid ].status ).toBe( status );
			expect( statuses[ rid ].className ).toContain( 'entry-status' );
			expect( statuses[ rid ].className ).not.toContain(
				'newspack-nodes-status'
			);
			expect( statuses[ rid ].color ).toBe( '' );
		}
		expect( statuses.timeout ).toEqual( {
			className:
				'event-logger-table__cell newspack-nodes-table__cell event-logger-table__cell--status entry-status newspack-nodes-status is-warning',
			color: '',
			status: undefined,
		} );
		expect( statuses.fatal ).toEqual( {
			className:
				'event-logger-table__cell newspack-nodes-table__cell event-logger-table__cell--status entry-status newspack-nodes-status is-error',
			color: '',
			status: undefined,
		} );
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
		// The partition rides with the rid; the detail cannot recover it.
		expect( onSelectRequest ).toHaveBeenCalledWith( 'r1', 5 );
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
		// The partition rides with the rid; the detail cannot recover it.
		expect( onSelectRequest ).toHaveBeenCalledWith( 'r1', 5 );
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

	// The breakdown series is this view's OWN read now, so what it sends is
	// asserted on the wire rather than through an injected fetcher.
	it( 'asks url_detail for the initial breakdown on mount', async () => {
		const { unmount } = mount();
		await waitFor(
			() =>
				expect(
					wire.batches
						.flat()
						.filter( ( m ) => 'url_detail' === m[ VALUE ]?.name )
						.length
				).toBeGreaterThan( 0 ),
			{ timeout: 6000 }
		);
		const [ msg ] = wire.batches
			.flat()
			.filter( ( m ) => 'url_detail' === m[ VALUE ]?.name );
		expect( msg[ VALUE ].arguments ).toEqual( [
			'deadbeef',
			'--breakdown=status',
		] );
		unmount();
	}, 20000 );

	it( 'renders the aggregate series when there is no urlHash to break down', () => {
		const { container, unmount } = mount( {
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
