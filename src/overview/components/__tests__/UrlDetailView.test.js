/* global globalThis, KeyboardEvent */
/**
 * Tests for UrlDetailView — heavy child components mocked at the
 * module boundary. We exercise:
 *   - sortable column headers fire onRequestSort
 *   - no "Errors Only" toggle; the chart's chips narrow the header and list
 *   - clicking a request row fires onSelectRequest
 *   - aggregate flame / breakdown / category time series sections
 *     mount when their data is present, each chart drawn from the answer
 *     its owner's graph asked
 *   - the chart's selects and chips hand their edits to the owner
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
	default: ( props ) => {
		const {
			dims,
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
		return `AGGREGATE[dims=${ dims.join( '+' ) },series=${
			series ? 'set' : 'none'
		}] keys:${ ( series?.names ?? [] ).join( ',' ) } slots:${
			slots?.[ 0 ] ?? 'none'
		} `;
	},
} ) );
jest.mock( '../../CategoryTimeChart', () => ( {
	__esModule: true,
	default: ( props ) => {
		const { data, slots, onSlotClick, onSlotRange, selectedBuckets } =
			props;
		globalThis.__categoryStorageKey = props.storageKey;
		globalThis.__categorySlots = slots;
		globalThis.__categoryData = data;
		globalThis.__categorySlotClick = onSlotClick;
		globalThis.__categorySlotRange = onSlotRange;
		globalThis.__categorySelected = selectedBuckets;
		return `CATEGORY slots:${ slots?.[ 0 ] ?? 'none' }`;
	},
} ) );

import * as React from 'react';
import UrlDetailView from '../UrlDetailView';
import { NO_DETAIL_CHART } from '../../hooks/usePerformanceGraph';
import { SERIES_MEASURES } from '../../constants';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { rowsWire } from '../../../test-helpers/chartWire';

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
 * The URL chart's answer by status class, over the given selection.
 *
 * @param {Array[]} rows   Its rows: `[ bucket, class, ...SERIES_MEASURES ]`.
 * @param {string}  bucket The selection it echoes.
 * @return {Object} The answer.
 */
const classAnswer = ( rows, bucket = '2026-09-28-14-40..2026-09-29-14-35' ) =>
	rowsWire( [ 'bucket', 'status_class', ...SERIES_MEASURES ], rows, {
		filters: { filter: {}, bucket, search: '' },
	} );

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

beforeEach( () => {
	globalThis.__aggregateStorageKey = undefined;
	globalThis.__categoryStorageKey = undefined;
	globalThis.__categoryData = undefined;
} );

function mount( overrides = {} ) {
	const props = {
		urlDetail: baseUrlDetail,
		sortedRequests: REQUESTS,
		requestSort: { field: 'timestamp', dir: 'desc' },
		onRequestSort: jest.fn(),
		onSelectRequest: jest.fn(),
		urlHash: 'deadbeef',
		filters: { bucket: '' },
		onFilterChange: jest.fn(),
		chartSlice: sliceOf( null ),
		categoriesSlice: sliceOf( null ),
		chart: NO_DETAIL_CHART,
		onChartChange: jest.fn(),
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
	it( 'marks its whole body as the URL for the ask picker, with each request row nested inside', () => {
		// The picker walks [data-ask] ancestors innermost first, so a click on
		// the body asks about the URL and a click on a row still asks about
		// that request.
		const { container, unmount } = mount( { urlHash: 'cafe0123beef' } );
		const root = container.firstElementChild;
		expect( root.getAttribute( 'data-ask' ) ).toBe( 'url:cafe0123beef' );
		const row = container.querySelector( '[data-ask^="request:"]' );
		expect( row ).not.toBeNull();
		expect( root.contains( row ) ).toBe( true );
		unmount();
	} );

	it( 'offers no server breakdown, since a URL already names its host', () => {
		// The server is the URL's host, and the hash is over the whole URL,
		// so one URL split by server is one line: the axis repeats the scope.
		const { container, unmount } = mount();
		const [ , breakdown ] = container.querySelectorAll( 'select' );
		const values = Array.from( breakdown.options ).map( ( o ) => o.value );
		expect( values ).not.toContain( 'server' );
		expect( values ).toContain( 'status_class' );
		unmount();
	} );

	it( 'says the aggregate flame ran out of time when its rebuild was cut', () => {
		const cut = mount( {
			urlDetail: {
				...baseUrlDetail,
				aggregate_flame: null,
				flame_cut: true,
			},
		} );
		expect( cut.container.textContent ).toContain(
			'The flame lookup ran out of time, so no trace is shown here; that does not mean none was recorded.'
		);
		cut.unmount();
		const whole = mount( {
			urlDetail: {
				...baseUrlDetail,
				aggregate_flame: null,
				flame_cut: false,
			},
		} );
		expect( whole.container.textContent ).not.toContain(
			'ran out of time'
		);
		whole.unmount();
	} );

	it( 'renders the recent-requests heading with the full count', () => {
		const { container, unmount } = mount();
		expect( container.textContent ).toContain( 'Recent Requests (3)' );
		unmount();
	} );

	it( 'counts nothing in the heading while the list is asked afresh', () => {
		const { container, unmount } = mount( { listing: true } );
		expect( container.querySelector( 'h3' ).textContent ).toBe(
			'Recent Requests'
		);
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

	it( 'offers no Errors Only toggle', () => {
		const { container, unmount } = mount();
		const labels = Array.from(
			container.querySelectorAll( 'button' ),
			( b ) => b.textContent
		);
		expect( labels ).not.toContain( 'Errors Only' );
		expect( labels ).not.toContain( 'Showing Errors' );
		unmount();
	} );

	it( 'shows an unmeasured duration as a dash and bars it nothing', () => {
		// A timeout's duration is the builder's eviction wait (decision 24).
		const { container, unmount } = mount( {
			sortedRequests: [
				{
					rid: 'tout-864',
					timestamp: 1748960301,
					duration_ms: null,
					peak_mb: 37,
					status_code: 0,
					error_status: 'T',
				},
				{
					rid: 'fatal-2417',
					timestamp: 1748960302,
					duration_ms: 2417,
					peak_mb: 91,
					status_code: 500,
					error_status: 'F',
				},
			],
		} );
		const row = ( rid ) =>
			[
				...container.querySelectorAll( '.event-logger-table__row' ),
			].find( ( r ) => r.querySelector( 'code' ).textContent === rid );
		const cells = ( rid ) =>
			row( rid ).querySelectorAll( '.event-logger-table__cell' );

		expect( cells( 'tout-864' )[ 4 ].textContent ).toBe( '—' );
		expect( cells( 'tout-864' )[ 2 ].style.background ).toContain( ' 0%' );
		expect( cells( 'fatal-2417' )[ 4 ].textContent ).toBe( '2417ms' );
		expect( cells( 'fatal-2417' )[ 2 ].style.background ).toContain(
			' 100%'
		);
		unmount();
	} );

	it( 'notes under chips that they narrow the chart, the header and the list, and nothing else', () => {
		const note = 'describe every request to this URL';
		const chipped = mount( {
			chart: { ...NO_DETAIL_CHART, chips: [ 'status_class=5' ] },
		} );
		const banner = chipped.container.querySelector( '[role="status"]' );
		expect( banner.textContent ).toContain(
			'The filters narrow the chart beneath them, the header and the list'
		);
		expect( banner.textContent ).toContain( note );
		expect( chipped.container.textContent ).not.toContain(
			'errors listed'
		);
		chipped.unmount();

		const all = mount();
		expect( all.container.textContent ).not.toContain( note );
		all.unmount();
	} );

	it( 'replaces its selection on a plain chart click, and toggles on an additive one', () => {
		const onFilterChange = jest.fn();
		const { unmount } = mount( {
			filters: { bucket: '2026-10-04-13-35' },
			onFilterChange,
		} );
		globalThis.__aggregateSlotClick( '2026-10-04-13-50', {
			additive: false,
		} );
		globalThis.__categorySlotClick( '2026-10-04-13-40', {
			additive: true,
		} );
		globalThis.__categorySlotClick( '2026-10-04-13-35', {
			additive: true,
		} );
		// Each click hands an updater, applied here to the selection held.
		expect(
			onFilterChange.mock.calls.map( ( [ field, update ] ) => [
				field,
				update( '2026-10-04-13-35' ),
			] )
		).toEqual( [
			[ 'bucket', '2026-10-04-13-50' ],
			[ 'bucket', '2026-10-04-13-35..2026-10-04-13-40' ],
			[ 'bucket', '' ],
		] );
		unmount();
	} );

	it( 'replaces its selection on a plain chart drag, and adds the span on an additive one', () => {
		const onFilterChange = jest.fn();
		const { unmount } = mount( {
			filters: { bucket: '2026-10-04-13-35' },
			onFilterChange,
		} );
		globalThis.__aggregateSlotRange(
			[ '2026-10-04-13-45', '2026-10-04-13-50', '2026-10-04-13-55' ],
			{ additive: false }
		);
		globalThis.__categorySlotRange(
			[ '2026-10-04-13-45', '2026-10-04-13-50', '2026-10-04-13-55' ],
			{ additive: true }
		);
		expect(
			onFilterChange.mock.calls.map( ( [ field, update ] ) => [
				field,
				update( '2026-10-04-13-35' ),
			] )
		).toEqual( [
			[ 'bucket', '2026-10-04-13-45..2026-10-04-13-55' ],
			[ 'bucket', '2026-10-04-13-35,2026-10-04-13-45..2026-10-04-13-55' ],
		] );
		unmount();
	} );

	it( 'names its two charts by the url-detail placement keys', () => {
		const { unmount } = mount();
		expect( globalThis.__aggregateStorageKey ).toBe(
			'event-logger-url-detail-chart-aggregate'
		);
		expect( globalThis.__categoryStorageKey ).toBe(
			'event-logger-url-detail-chart-category'
		);
		unmount();
	} );

	it( "shades the modal's own selection on both charts", () => {
		const { unmount } = mount( {
			filters: {
				bucket: '2026-10-04-13-30..2026-10-04-13-35',
			},
		} );
		expect( globalThis.__aggregateSelected ).toEqual( [
			'2026-10-04-13-30',
			'2026-10-04-13-35',
		] );
		expect( globalThis.__categorySelected ).toBe(
			globalThis.__aggregateSelected
		);
		unmount();
	} );

	it( 'holds its selection in a Time field after the heading', () => {
		const onFilterChange = jest.fn();
		const { container, unmount } = mount( {
			chartSlice: sliceOf(
				classAnswer( [], '2026-10-04-13-05..2026-10-04-14-00' )
			),
			filters: {
				bucket: '2026-10-04-13-30,2026-10-04-13-40',
			},
			onFilterChange,
		} );
		const field = container.querySelector(
			'.event-logger-table--requests .event-logger-tag-container'
		);
		expect(
			Array.from(
				field.parentElement.children,
				( child ) => child.tagName
			)
		).toEqual( [ 'H3', 'DIV' ] );
		expect( field.previousElementSibling.tagName ).toBe( 'H3' );
		expect( field.querySelectorAll( 'datalist option' ) ).toHaveLength(
			12
		);
		act( () =>
			field
				.querySelector( 'button[aria-label="Remove 6:30–6:35 AM"]' )
				.click()
		);
		expect( onFilterChange ).toHaveBeenCalledWith(
			'bucket',
			'2026-10-04-13-40'
		);
		unmount();
	} );

	it( 'notes under a bucket that the charts and profile cover every request', () => {
		const note = 'describe every request to this URL';
		const bucketed = mount( {
			filters: { bucket: '2026-10-04-13-35' },
		} );
		const banner = bucketed.container.querySelector( '[role="status"]' );
		expect( banner.textContent ).toContain( note );
		expect( banner.textContent ).toContain( '6:35–6:40 AM' );
		expect( banner.textContent ).not.toContain( 'flame' );
		bucketed.unmount();

		const both = mount( {
			filters: { bucket: '2026-10-04-13-35' },
			chart: { ...NO_DETAIL_CHART, chips: [ 'country=Kiribati' ] },
		} );
		const bothBanner = both.container.querySelector( '[role="status"]' );
		expect( bothBanner.textContent ).toContain( 'The filters narrow' );
		expect( bothBanner.textContent ).toContain( '6:35–6:40 AM' );
		both.unmount();
	} );

	it( 'labels an incomplete (I) request', async () => {
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
		// The rid alone names the request; its row names the rest.
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
		// The rid alone names the request; its row names the rest.
		expect( onSelectRequest ).toHaveBeenCalledWith( 'r1' );
		unmount();
	} );

	it( 'renders timed-out request status rows', () => {
		const { container, unmount } = mount( {
			sortedRequests: [
				{
					rid: 'r-timeout',
					timestamp: 1748960003,
					duration_ms: null,
					peak_mb: 0,
					error_status: 'T',
				},
			],
		} );
		expect( container.textContent ).toContain( 'r-timeout' );
		expect( container.textContent ).toContain( 'T' );
		unmount();
	} );

	it( 'never charts a series carried by the dump_url payload', () => {
		const { container, unmount } = mount( {
			urlDetail: { ...baseUrlDetail, stats: { time_series: { a: 1 } } },
		} );
		expect( container.textContent ).toContain( 'series=none' );
		unmount();
	} );

	it( 'charts the answer its owner asked by its dimensions', () => {
		const { container, unmount } = mount( {
			chartSlice: sliceOf(
				classAnswer( [ [ '2026-09-29-14-35', 5, 7, 70, 3, 7 ] ] )
			),
		} );
		expect( container.textContent ).toContain(
			'AGGREGATE[dims=status_class,series=set] keys:5xx'
		);
		unmount();
	} );

	it( 'draws each chart on the buckets its own answer read', () => {
		const { container, unmount } = mount( {
			chartSlice: sliceOf(
				classAnswer( [], '2026-09-29-14-30..2026-09-29-14-40' )
			),
			categoriesSlice: sliceOf(
				classAnswer( [], '2026-09-29-14-25..2026-09-29-14-35' )
			),
		} );
		expect( container.textContent ).toContain( 'slots:2026-09-29-14-40 ' );
		expect( container.textContent ).toContain(
			'CATEGORY slots:2026-09-29-14-35'
		);
		unmount();
	} );

	it( 'draws no series under dimensions its answer was not asked by', () => {
		// The answer in hand is the status classes'; the dropdown reads JA4.
		const { container, unmount } = mount( {
			chart: { breakdown: 'ja4', thenBy: '', chips: [] },
			chartSlice: sliceOf(
				classAnswer( [ [ '2026-09-29-14-35', 5, 7, 70, 3, 7 ] ] )
			),
		} );
		expect( container.textContent ).toContain(
			'AGGREGATE[dims=ja4,series=none]'
		);
		expect( container.textContent ).toContain( 'Loading…' );
		unmount();
	} );

	it( "keeps each chart's slots across a re-render with the same answers", () => {
		const { props, rerender, unmount } = mount( {
			chartSlice: sliceOf( classAnswer( [] ) ),
			categoriesSlice: sliceOf(
				classAnswer( [], '2026-09-29-13-05..2026-09-29-13-15' )
			),
		} );
		const [ aggregate, category ] = [
			globalThis.__aggregateSlots,
			globalThis.__categorySlots,
		];

		rerender(
			React.createElement( UrlDetailView, { ...props, listing: true } )
		);

		expect( globalThis.__aggregateSlots ).toBe( aggregate );
		expect( globalThis.__categorySlots ).toBe( category );
		unmount();
	} );

	it( 'charts Method alone after Then by Method, then Breakdown Method', () => {
		const answer = rowsWire(
			[ 'bucket', 'method', ...SERIES_MEASURES ],
			[ [ '2026-09-29-14-35', 'PURGE', 3, 30, 1, 3 ] ]
		);
		const { container, unmount } = mount( {
			chart: { breakdown: 'method', thenBy: 'method', chips: [] },
			chartSlice: sliceOf( answer ),
		} );
		expect( container.textContent ).toContain(
			'AGGREGATE[dims=method,series=set] keys:PURGE'
		);
		unmount();
	} );

	it( 'hands its owner each edit of the chart question', () => {
		const onChartChange = jest.fn();
		const { container, unmount } = mount( {
			chart: {
				breakdown: 'status_class',
				thenBy: '',
				chips: [ 'method=PUT' ],
			},
			onChartChange,
		} );
		const [ , breakdown, thenBy ] = container.querySelectorAll( 'select' );
		selectOption( breakdown, 'ja4' );
		selectOption( thenBy, 'country' );
		act( () => {
			container
				.querySelector( 'button[aria-label="Remove Method = PUT"]' )
				.click();
		} );
		expect(
			onChartChange.mock.calls.map( ( c ) => c.slice( 0, 2 ) )
		).toEqual( [
			[ 'breakdown', 'ja4' ],
			[ 'thenBy', 'country' ],
			[ 'chips', [] ],
		] );
		unmount();
	} );

	it( 'offers no URL chip, since the modal is one URL, and no Server chip, since the select owns it', () => {
		const { container, unmount } = mount();
		const box = container.querySelector( 'input[aria-label="Filter"]' );
		const list = container.querySelector(
			`datalist[id="${ box.getAttribute( 'list' ) }"]`
		);
		const offered = Array.from(
			list.querySelectorAll( 'option' ),
			( option ) => option.value
		);
		expect( offered ).toContain( 'ja4=' );
		expect( offered ).toContain( 'status_class=' );
		expect( offered ).not.toContain( 'url=' );
		expect( offered ).not.toContain( 'server=' );
		unmount();
	} );

	it( 'keeps its charts, flame and profile on a bucket flip, marking the list alone', async () => {
		const urlDetail = {
			...baseUrlDetail,
			requests: REQUESTS,
			aggregate_flame: { children: [ { name: 'heron' } ] },
			aggregate_profiles: {
				categories: { hooks: { time: 13, count: 6 } },
				count: 37,
				total_time: 13,
			},
		};
		const props = {
			urlDetail,
			sortedRequests: REQUESTS,
			requestSort: { field: 'timestamp', dir: 'desc' },
			onRequestSort: jest.fn(),
			onSelectRequest: jest.fn(),
			urlHash: 'deadbeef',
			filters: { bucket: '' },
			onFilterChange: jest.fn(),
			listing: false,
			chartSlice: sliceOf( classAnswer( [] ) ),
			categoriesSlice: sliceOf( classAnswer( [] ) ),
			chart: NO_DETAIL_CHART,
			onChartChange: jest.fn(),
		};
		const { container, rerender, unmount } = renderComponent(
			React.createElement( UrlDetailView, props )
		);
		await act( async () => {} );
		/**
		 * The whole-URL elements, each the deepest one holding its text.
		 *
		 * @return {Element[]} Charts, flame container and profile caption.
		 */
		const wholeUrl = () => [
			...[ 'AGGREGATE[', 'CATEGORY', 'RESPONSE_TIME_CHART' ].map(
				( text ) =>
					Array.from( container.querySelectorAll( '*' ) )
						.filter( ( el ) => el.textContent.includes( text ) )
						.at( -1 )
			),
			container.querySelector( '.event-logger-flame-container' ),
			Array.from( container.querySelectorAll( '*' ) )
				.filter( ( el ) =>
					el.textContent.includes( 'Average breakdown across 37' )
				)
				.at( -1 ),
		];
		const before = wholeUrl();
		expect( before.every( Boolean ) ).toBe( true );
		const rows = () =>
			Array.from(
				container.querySelectorAll( '[data-ask^="request:"]' ),
				( row ) => row.getAttribute( 'data-ask' )
			);
		expect( rows() ).toHaveLength( 3 );

		rerender(
			React.createElement( UrlDetailView, {
				...props,
				filters: { bucket: '2026-10-04-13-35' },
				listing: true,
			} )
		);
		await act( async () => {} );

		expect( wholeUrl() ).toEqual( before );
		wholeUrl().forEach( ( el, i ) => expect( el ).toBe( before[ i ] ) );
		expect( rows() ).toEqual( [] );
		expect(
			container.querySelector( '.event-logger-table__empty' ).textContent
		).toBe( 'Loading requests…' );

		const fatal = REQUESTS[ 1 ];
		rerender(
			React.createElement( UrlDetailView, {
				...props,
				urlDetail: { ...urlDetail, requests: [ fatal ] },
				sortedRequests: [ fatal ],
				filters: { bucket: '2026-10-04-13-35' },
				listing: false,
			} )
		);
		await act( async () => {} );

		wholeUrl().forEach( ( el, i ) => expect( el ).toBe( before[ i ] ) );
		expect( rows() ).toEqual( [ 'request:r2' ] );
		unmount();
	} );

	it( 'says over the list why its last ask was refused', () => {
		const { container, unmount } = mount( {
			listError: 'bucket 2026-10-04-09-07 must be a five-minute key',
		} );
		const line = container.querySelector(
			'.event-logger-table--requests .newspack-nodes-status.is-error'
		);
		expect( line.textContent ).toBe(
			'Could not list requests: bucket 2026-10-04-09-07 must be a five-minute key'
		);
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

	it( 'captions no breakdown over 0 requests above a listed request', () => {
		// A PHP empty array arrives as `[]`, which is truthy.
		const { container, unmount } = mount( {
			urlDetail: {
				...baseUrlDetail,
				requests: REQUESTS.slice( 0, 1 ),
				aggregate_profiles: {
					categories: [],
					count: 0,
					total_time: null,
				},
			},
			sortedRequests: REQUESTS.slice( 0, 1 ),
		} );
		expect( container.textContent ).not.toContain( 'Average breakdown' );
		expect( container.textContent ).not.toContain( 'Total Profiled' );
		unmount();
	} );

	it( 'divides the averaged breakdown by no wall clock when no timed request reached the URL', () => {
		const { container, unmount } = mount( {
			urlDetail: {
				stats: { avg_ms: null },
				requests: [],
				aggregate_profiles: {
					categories: { hooks: { time: 10, count: 4 } },
					count: 7,
					total_time: 10,
				},
			},
		} );
		const footer = container.querySelector( 'tfoot tr' );

		expect( footer.querySelectorAll( 'td' )[ 2 ].textContent ).toBe( '—' );
		expect( container.textContent ).not.toContain( '0.0%' );
		unmount();
	} );

	it( 'hands the category answer to CategoryTimeChart', () => {
		const answer = classAnswer( [] );
		const { unmount } = mount( { categoriesSlice: sliceOf( answer ) } );
		expect( globalThis.__categoryData ).toBe( answer );
		unmount();
	} );

	it( 'shows "No requests" when sortedRequests is empty', () => {
		const { container, unmount } = mount( { sortedRequests: [] } );
		expect( container.textContent ).toContain( 'No requests to display' );
		unmount();
	} );

	it( 'keeps its dropdowns and says so when the breakdown is refused', () => {
		const { container, unmount } = mount( {
			chartSlice: sliceOf( null, {
				error: 'unknown requests field plumage',
			} ),
		} );
		expect( container.textContent ).toContain(
			'unknown requests field plumage'
		);
		expect( dropdownLabels( container ) ).toEqual( [
			'Metric',
			'Breakdown',
			'Then by',
		] );
		unmount();
	} );

	it( 'keeps its dropdowns when the dimension has no rows to chart', () => {
		const onChartChange = jest.fn();
		const { container, unmount } = mount( {
			chartSlice: sliceOf( classAnswer( [] ) ),
			onChartChange,
		} );
		expect( container.textContent ).toContain(
			'No Status Class data in this window.'
		);
		const [ , breakdown ] = container.querySelectorAll( 'select' );
		selectOption( breakdown, 'ja4' );
		expect( onChartChange.mock.calls[ 0 ].slice( 0, 2 ) ).toEqual( [
			'breakdown',
			'ja4',
		] );
		unmount();
	} );
} );
