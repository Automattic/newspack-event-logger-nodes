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
// The chart is mocked; its `breakdownState` resolver is NOT — the panel and
// the chart read the same one.
jest.mock( '../../AggregateTimeChart', () => ( {
	...jest.requireActual( '../../AggregateTimeChart' ),
	__esModule: true,
	default: ( { breakdown, breakdownData } ) =>
		`AGGREGATE[breakdown=${ breakdown },series=${
			breakdownData ? 'set' : 'none'
		}] keys:${ Object.values( breakdownData ?? {} )
			.flatMap( ( bucket ) => Object.keys( bucket ) )
			.join( ',' ) }`,
} ) );
jest.mock( '../../CategoryTimeChart', () => ( {
	__esModule: true,
	default: () => 'CATEGORY',
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

// The view holds its own `url_breakdown` read, so every render mounts a
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

// Drive a SelectControl the way React hears it: the native value setter,
// then a bubbling change the root listener picks up.
function selectOption( select, value ) {
	const { set } = Object.getOwnPropertyDescriptor(
		window.HTMLSelectElement.prototype,
		'value'
	);
	act( () => {
		set.call( select, value );
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

// The Metric and Breakdown labels, in order, of whatever panel is mounted.
const dropdownLabels = ( container ) =>
	Array.from( container.querySelectorAll( 'label' ) ).map(
		( label ) => label.textContent
	);

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

	it( 'labels an incomplete (I) request and keeps it under "Errors Only"', async () => {
		const { container, unmount } = mount( {
			sortedRequests: [
				{
					rid: 'gap-9714',
					timestamp: 1748960777,
					method: 'GET',
					duration_ms: 77,
					peak_mb: 3,
					status_code: 200,
					error_status: 'I',
				},
			],
		} );
		await act( async () => {} );
		const cell = container.querySelector(
			'.event-logger-table__cell--status'
		);
		expect( cell.className ).toContain(
			'newspack-nodes-status is-warning'
		);
		expect( cell.textContent ).toBe( 'I' );
		expect(
			cell.querySelector( 'span' ).getAttribute( 'title' )
		).toContain( 'Incomplete' );

		const button = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Errors Only' );
		act( () => {
			button.click();
		} );
		expect( container.textContent ).toContain( 'Recent Requests (1)' );
		expect( container.textContent ).toContain( 'gap-9714' );
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

	it( 'never charts a series carried by the url_detail payload', () => {
		// `url_detail` sends no series; a view that would draw one anyway is
		// a server change away from the first paint nobody asked for.
		const { container, unmount } = mount( {
			urlDetail: { ...baseUrlDetail, stats: { time_series: { a: 1 } } },
		} );
		expect( container.textContent ).toContain( 'series=none' );
		unmount();
	} );

	it( 'charts the breakdown reply it asked for', async () => {
		wire = installFakeCommandWire( () => ( {
			breakdown_time_series: { 1748960000: { '5xx': { c: 7 } } },
		} ) );
		const { container, unmount } = mount();
		await waitFor(
			() =>
				expect( container.textContent ).toContain(
					'AGGREGATE[breakdown=status,series=set]'
				),
			{ timeout: 6000 }
		);
		unmount();
	}, 20000 );

	it( 'drops the held series the moment the dimension changes', async () => {
		// Otherwise the status series is drawn under a dropdown reading JA4
		// for the round trip it takes the new dimension to arrive — plausible
		// numbers under the wrong question, which is worse than a blank.
		wire = installFakeCommandWire( () => ( {
			breakdown_time_series: { 1748960000: { '5xx': { c: 7 } } },
		} ) );
		const { container, unmount } = mount( { urlHash: '7f3c19ab52d0' } );
		await waitFor(
			() =>
				expect( container.textContent ).toContain(
					'AGGREGATE[breakdown=status,series=set]'
				),
			{ timeout: 6000 }
		);
		const [ , breakdown ] = container.querySelectorAll( 'select' );
		selectOption( breakdown, 'ja4' );
		expect( container.textContent ).toContain(
			'AGGREGATE[breakdown=ja4,series=none]'
		);
		unmount();
	}, 20000 );

	it( 'answers the dimension it asked about, never the superseded one', async () => {
		// Two reads about one URL are ONE subject unless the dimension
		// addresses them apart. Undifferentiated, the status answer retires
		// the User Agent ask it does not answer: status series under a User
		// Agent label, and the User Agent reply dropped for good after it.
		const deferred = new Map();
		const dimOf = ( message ) =>
			( message[ VALUE ]?.arguments ?? [] )
				.find( ( token ) => token.startsWith( '--breakdown=' ) )
				?.slice( '--breakdown='.length );
		const askFor = ( dim ) => {
			if ( ! deferred.has( dim ) ) {
				let settle;
				const reply = new Promise( ( r ) => {
					settle = r;
				} );
				deferred.set( dim, { reply, settle } );
			}
			return deferred.get( dim ).reply;
		};
		// A re-ask for a dimension answers with the same held promise, so the
		// retry cadence cannot smuggle in an answer the test did not release.
		wire = installFakeCommandWire( ( message ) =>
			askFor( dimOf( message ) )
		);
		const flush = async () => {
			await act( async () => {
				for ( let i = 0; i < 8; i++ ) {
					await Promise.resolve();
				}
			} );
		};

		const { container, unmount } = mount( { urlHash: '3b9a77c1de40' } );
		await waitFor( () => expect( deferred.has( 'status' ) ).toBe( true ), {
			timeout: 6000,
		} );
		const [ , breakdown ] = container.querySelectorAll( 'select' );
		selectOption( breakdown, 'ua' );
		await waitFor( () => expect( deferred.has( 'ua' ) ).toBe( true ), {
			timeout: 6000,
		} );

		// The superseded status answer lands while User Agent is outstanding.
		deferred.get( 'status' ).settle( {
			breakdown_time_series: { 1748960000: { '5xx': { c: 7 } } },
		} );
		await flush();
		expect( container.textContent ).toContain(
			'AGGREGATE[breakdown=ua,series=none]'
		);

		deferred.get( 'ua' ).settle( {
			breakdown_time_series: { 1748960000: { 'curl/8.4': { c: 3 } } },
		} );
		await waitFor(
			() => expect( container.textContent ).toContain( 'series=set' ),
			{ timeout: 6000 }
		);
		expect( container.textContent ).toContain(
			'AGGREGATE[breakdown=ua,series=set] keys:curl/8.4'
		);
		unmount();
	}, 20000 );

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
					categories: { hooks: { time: 10, count: 4 } },
					count: 100,
					total_time: 10,
				},
			},
		} );
		expect( container.textContent ).toContain( 'Total Profiled' );
		expect( container.textContent ).toContain(
			'Average breakdown across 100 requests'
		);
		unmount();
	} );

	it( 'hands the category series to CategoryTimeChart', () => {
		const { container, unmount } = mount( {
			urlDetail: {
				...baseUrlDetail,
				category_time_series: { hooks: [] },
			},
		} );
		expect( container.textContent ).toContain( 'CATEGORY' );
		unmount();
	} );

	// The breakdown series is this view's OWN read now, so what it sends is
	// asserted on the wire rather than through an injected fetcher.
	it( 'asks url_breakdown for the initial breakdown on mount', async () => {
		// It keeps only the series, so it must not ask the verb that walks
		// every partition's index to build a request list it discards.
		const { unmount } = mount();
		await waitFor(
			() =>
				expect(
					wire.batches
						.flat()
						.filter( ( m ) => 'url_breakdown' === m[ VALUE ]?.name )
						.length
				).toBeGreaterThan( 0 ),
			{ timeout: 6000 }
		);
		const [ msg ] = wire.batches
			.flat()
			.filter( ( m ) => 'url_breakdown' === m[ VALUE ]?.name );
		expect( msg[ VALUE ].arguments ).toEqual( [
			'deadbeef',
			'--breakdown=status',
		] );
		expect(
			wire.batches
				.flat()
				.filter( ( m ) => 'url_detail' === m[ VALUE ]?.name )
		).toHaveLength( 0 );
		unmount();
	}, 20000 );

	it( 'shows "No requests" when sortedRequests is empty', () => {
		const { container, unmount } = mount( { sortedRequests: [] } );
		expect( container.textContent ).toContain( 'No requests to display' );
		unmount();
	} );

	it( 'says the scan stopped rather than that the URL has no requests', () => {
		const { container, unmount } = mount( {
			urlDetail: { ...baseUrlDetail, scan_stopped_early: true },
			sortedRequests: [],
		} );
		expect( container.textContent ).toContain( 'stopped early' );
		expect( container.textContent ).not.toContain(
			'No requests to display'
		);
		unmount();
	} );

	it( 'keeps its dropdowns and says so when the breakdown is refused', async () => {
		wire = installFakeCommandWire(
			() => new Error( 'index scan budget spent' )
		);
		const { container, unmount } = mount( { urlHash: '7f3c19ab52d0' } );
		await waitFor(
			() =>
				expect( container.textContent ).toContain(
					'index scan budget spent'
				),
			{ timeout: 6000 }
		);
		expect( dropdownLabels( container ) ).toEqual( [
			'Metric',
			'Breakdown',
		] );
		unmount();
	}, 20000 );

	it( 'keeps its dropdowns when the dimension has no rows to chart', async () => {
		wire = installFakeCommandWire( () => ( {
			breakdown_time_series: {},
		} ) );
		const { container, unmount } = mount( { urlHash: '7f3c19ab52d0' } );
		await waitFor(
			() => expect( container.textContent ).not.toContain( 'Loading…' ),
			{ timeout: 6000 }
		);
		expect( dropdownLabels( container ) ).toEqual( [
			'Metric',
			'Breakdown',
		] );

		// The dropdowns are the only way out, so they have to still work.
		const [ , breakdown ] = container.querySelectorAll( 'select' );
		selectOption( breakdown, 'ja4' );
		await waitFor(
			() =>
				expect(
					wire.batches
						.flat()
						.some( ( m ) =>
							m[ VALUE ]?.arguments?.includes( '--breakdown=ja4' )
						)
				).toBe( true ),
			{ timeout: 6000 }
		);
		unmount();
	}, 20000 );

	it( 'notes a list the scan cut short even with rows in hand', () => {
		const { container, unmount } = mount( {
			urlDetail: { ...baseUrlDetail, scan_stopped_early: true },
		} );
		expect( container.textContent ).toContain( 'Recent Requests (3)' );
		expect( container.textContent ).toContain( 'stopped early' );
		unmount();
	} );
} );
