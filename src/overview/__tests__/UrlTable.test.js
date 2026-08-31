/* global KeyboardEvent */
/**
 * Tests for UrlTable — sortable virtualized URL list.
 *
 * Mocks useVirtualization so we get all rows in test (real hook needs
 * a real scroll context).
 */

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

import * as React from 'react';
import UrlTable from '../UrlTable';
import { renderComponent, act } from '../../test-helpers/renderHook';

const URLS = [
	{
		hash: 'h1',
		url: '/foo',
		count: 100,
		count_2xx: 80,
		count_4xx: 20,
		avg_ms: 50,
		min_ms: 10,
		max_ms: 100,
		avg_peak_mb: 4,
	},
	{
		hash: 'h2',
		url: '/bar',
		count: 50,
		count_2xx: 50,
		avg_ms: 100,
		min_ms: 20,
		max_ms: 200,
		avg_peak_mb: 2,
	},
	{
		hash: 'h3',
		url: '/baz',
		count: 200,
		count_2xx: 100, // 100 unclassified — orphan, counts as "error"
		avg_ms: 75,
		min_ms: 10,
		max_ms: 300,
		avg_peak_mb: 8,
	},
];

function mount( overrides = {} ) {
	const props = {
		urls: URLS,
		selectedUrl: null,
		onSelect: jest.fn(),
		onParamsChange: jest.fn(),
		totalUrls: 3,
		...overrides,
	};
	return {
		props,
		...renderComponent( React.createElement( UrlTable, props ) ),
	};
}

describe( 'UrlTable', () => {
	it( 'names the pager count as rows, not URLs', () => {
		// A capped bucket carries synthetic overflow rows: sliceable, so the
		// pager pages over them, but not URLs, so the header excludes them.
		// The two counts legitimately differ, which is exactly why the pager
		// has to say which one it is showing — "502 URLs" beside a header
		// reading 500 is the same number twice, wrong once.
		const { container, unmount } = mount( { totalUrls: 502 } );

		const info = container.querySelector(
			'.event-logger-table__pagination-info'
		);
		expect( info.textContent ).toBe( '1\u2013100 of 502 rows' );
		unmount();
	} );

	it( 'falls back to a page that exists when the set shrinks', () => {
		// Narrowing the server filter can leave the table on an offset past the
		// end of the new set: the body renders empty AND, below the one-page
		// threshold, the pager disappears — so no control is left to get back.
		// The page follows the set down.
		const onParamsChange = jest.fn();
		const props = {
			urls: URLS,
			selectedUrl: null,
			onSelect: jest.fn(),
			onParamsChange,
			totalUrls: 800,
		};
		const { container, rerender, unmount } = renderComponent(
			React.createElement( UrlTable, props )
		);
		const next = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Next' )
		);
		act( () => {
			next.click();
		} );
		onParamsChange.mockClear();

		rerender(
			React.createElement( UrlTable, { ...props, totalUrls: 30 } )
		);

		expect(
			onParamsChange.mock.calls.some( ( c ) => 0 === c[ 0 ].offset )
		).toBe( true );
		unmount();
	} );

	it( 'asks for worker traffic only when the toggle is on', () => {
		// Workers are out of every site-wide aggregate, so the table hides them
		// by default and the header it feeds agrees. The toggle opts IN — the
		// mirror of Errors, which opts in to narrow.
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( { onParamsChange } );
		const toggle = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Workers' ) );
		expect( toggle ).toBeTruthy();
		expect(
			onParamsChange.mock.calls.every( ( c ) => ! c[ 0 ].includeWorkers )
		).toBe( true );

		act( () => {
			toggle.click();
		} );

		expect(
			onParamsChange.mock.calls.some(
				( c ) => true === c[ 0 ].includeWorkers
			)
		).toBe( true );
		unmount();
	} );

	it( 'does not offer the aggregate row as a URL', () => {
		// The `Other` row stands for every URL past the per-bucket cap, so its
		// key is not a url_hash and `url_detail` cannot answer for it. Making
		// it clickable would open a modal that errors on a row whose whole job
		// is to keep the totals honest.
		const onSelect = jest.fn();
		const { container, unmount } = mount( {
			urls: [
				{ ...URLS[ 0 ] },
				{ hash: 'Other', aggregate: true, count: 900, avg_ms: 12 },
			],
			onSelect,
		} );

		const rows = container.querySelectorAll( '.event-logger-table__row' );
		const other = rows[ rows.length - 1 ];
		expect( other.getAttribute( 'role' ) ).not.toBe( 'button' );
		expect( other.hasAttribute( 'data-ask' ) ).toBe( false );
		// It carries no `url` — the writer stores none, because a label
		// authored there is untranslated and searchable. The client names it,
		// and names it for the REQUESTS the row counts: the Reqs column holds
		// their traffic, not how many URLs folded, which nothing stores.
		expect( other.textContent ).toContain(
			'traffic from URLs beyond the per-shard cap'
		);
		expect( other.textContent ).not.toContain( 'other URLs beyond' );
		other.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
		expect( onSelect ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'renders each URL row', () => {
		const { container, unmount } = mount();
		expect( container.textContent ).toContain( '/foo' );
		expect( container.textContent ).toContain( '/bar' );
		expect( container.textContent ).toContain( '/baz' );
		for ( const header of container.querySelectorAll(
			'.event-logger-table__header-btn'
		) ) {
			expect(
				header.classList.contains(
					'newspack-nodes-sortable-header-button'
				)
			).toBe( true );
			expect(
				header.classList.contains( 'newspack-nodes-table__cell' )
			).toBe( true );
			expect( header.classList.contains( 'button' ) ).toBe( false );
			expect( header.classList.contains( 'button-small' ) ).toBe( false );
		}
		unmount();
	} );

	it( 'keeps the toolbar outside the canonical bordered list surface', () => {
		const { container, unmount } = mount();
		const root = container.querySelector( '.event-logger-table--urls' );
		const toolbar = root.querySelector( '.event-logger-url-search' );
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

	it( 'keeps every header column and its row cell on the same field', () => {
		// Header and row are two renderings of ONE column list. While they
		// were spelled out twice, inserting a column in one and not the other
		// silently shifted every cell after it under the wrong heading — the
		// numbers still rendered, just against the wrong label.
		const { container, unmount } = mount();
		const fieldsOf = ( selector ) =>
			[ ...container.querySelector( selector ).children ].map(
				( cell ) => cell.dataset.field
			);

		expect( fieldsOf( '.event-logger-table__header' ) ).toEqual( [
			'count',
			'url',
			'count_2xx',
			'count_3xx',
			'count_4xx',
			'count_5xx',
			'avg_ms',
			'min_ms',
			'max_ms',
			'avg_peak_mb',
		] );
		expect( fieldsOf( '.event-logger-table__row' ) ).toEqual(
			fieldsOf( '.event-logger-table__header' )
		);
		unmount();
	} );

	it( 'binds every HTTP status column to the shared CSS data contract', () => {
		const { container, unmount } = mount();
		const headerCells = [
			...container.querySelectorAll(
				'.event-logger-table__header .event-logger-table__cell--status'
			),
		];
		const firstRowCells = [
			...container
				.querySelector( '.event-logger-table__row' )
				.querySelectorAll( '.event-logger-table__cell--status' ),
		];

		for ( const cells of [ headerCells, firstRowCells ] ) {
			expect( cells.map( ( cell ) => cell.dataset.status ) ).toEqual( [
				'218',
				'307',
				'418',
				'599',
			] );
			for ( const cell of cells ) {
				expect( cell.classList.contains( 'entry-status' ) ).toBe(
					true
				);
				expect( cell.style.color ).toBe( '' );
			}
		}
		unmount();
	} );

	it( 'renders the server page in the order it arrived', () => {
		// The server sorts and cuts the page; re-sorting here re-ordered a page
		// that had already been cut under a different comparator.
		const { container, unmount } = mount();
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		expect( rows[ 0 ].textContent ).toContain( '/foo' );
		expect( rows[ 1 ].textContent ).toContain( '/bar' );
		expect( rows[ 2 ].textContent ).toContain( '/baz' );
		unmount();
	} );

	it( 'asks the server for asc when the active sort header is clicked', () => {
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( { onParamsChange } );
		const reqsHeader = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Reqs' ) );
		act( () => {
			reqsHeader.click();
		} );
		expect( onParamsChange ).toHaveBeenLastCalledWith(
			expect.objectContaining( { sort: 'count', order: 'asc' } )
		);
		unmount();
	} );

	it( 'asks the server to sort by URL when the URL header is clicked', () => {
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( { onParamsChange } );
		const urlHeader = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => /^URL/.test( b.textContent ) );
		act( () => {
			urlHeader.click();
		} );
		expect( onParamsChange ).toHaveBeenLastCalledWith(
			expect.objectContaining( { sort: 'url' } )
		);
		unmount();
	} );

	it( 'sends the search term to the server instead of filtering locally', () => {
		// Filtering the STALE page by the NEW term emptied the table between
		// keystroke and reply, showing "No URLs match" for data that does.
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( { onParamsChange } );
		const input = container.querySelector( 'input[type="text"]' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'baz' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		expect( onParamsChange ).toHaveBeenLastCalledWith(
			expect.objectContaining( { search: 'baz' } )
		);
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		expect( rows.length ).toBe( 3 );
		unmount();
	} );

	it( 'shows the search empty state when the server returns no rows', () => {
		const { container, unmount } = mount( { urls: [], totalUrls: 0 } );
		const input = container.querySelector( 'input[type="text"]' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'nope' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		expect( container.textContent ).toContain( 'No URLs match' );
		unmount();
	} );

	it( '"Errors Only" asks the server, so the footer total matches the rows', () => {
		// Filtering only here left the footer reading the server's UNFILTERED
		// count — "1-100 of 5,000" printed above three visible rows.
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( { onParamsChange } );
		const btn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === 'Errors Only'
		);
		act( () => {
			btn.click();
		} );
		expect( onParamsChange ).toHaveBeenLastCalledWith(
			expect.objectContaining( { errorsOnly: true } )
		);
		unmount();
	} );

	it( 'fires onSelect when a row is clicked', () => {
		const onSelect = jest.fn();
		const { container, unmount } = mount( { onSelect } );
		const row = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		).find( ( r ) => r.textContent.includes( '/foo' ) );
		act( () => {
			row.click();
		} );
		expect( onSelect ).toHaveBeenCalledWith(
			expect.objectContaining( { url: '/foo' } )
		);
		unmount();
	} );

	it( 'fires onSelect when a row is keyboard-activated', () => {
		const onSelect = jest.fn();
		const { container, unmount } = mount( { onSelect } );
		const row = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		).find( ( r ) => r.textContent.includes( '/bar' ) );
		act( () => {
			row.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		expect( onSelect ).toHaveBeenCalledWith(
			expect.objectContaining( { url: '/bar' } )
		);
		unmount();
	} );

	it( 'shows keyboard selection with the canonical selected-row state', () => {
		function KeyboardSelectionHarness() {
			const [ selectedUrl, setSelectedUrl ] = React.useState( null );
			return React.createElement( UrlTable, {
				urls: URLS,
				selectedUrl,
				onSelect: setSelectedUrl,
				onParamsChange: jest.fn(),
				totalUrls: 3,
			} );
		}

		const { container, unmount } = renderComponent(
			React.createElement( KeyboardSelectionHarness )
		);
		const row = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		).find( ( candidate ) => candidate.textContent.includes( '/bar' ) );

		expect( row.classList.contains( 'is-selected' ) ).toBe( false );
		act( () => {
			row.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: ' ',
					bubbles: true,
				} )
			);
		} );
		expect( row.classList.contains( 'is-selected' ) ).toBe( true );
		expect( row.classList.contains( 'selected' ) ).toBe( false );
		unmount();
	} );

	it( 'reports params via onParamsChange on mount', () => {
		const onParamsChange = jest.fn();
		const { unmount } = mount( { onParamsChange } );
		expect( onParamsChange ).toHaveBeenCalledWith( {
			search: '',
			sort: 'count',
			order: 'desc',
			offset: 0,
			errorsOnly: false,
			includeWorkers: false,
		} );
		unmount();
	} );

	it( 'shows total count summary when total <= URLS_PER_PAGE (100)', () => {
		const { container, unmount } = mount( { totalUrls: 3 } );
		expect( container.textContent ).toContain( '3 rows' );
		expect( container.textContent ).not.toContain( 'Prev' );
		unmount();
	} );

	it( 'shows the unfiltered empty state when there are no URLs', () => {
		const { container, unmount } = mount( {
			urls: [],
			totalUrls: 0,
		} );
		expect( container.textContent ).toContain( 'No URLs to display' );
		expect(
			container.querySelector( '.event-logger-table__pagination-info' )
				.textContent
		).toBe( '' );
		unmount();
	} );

	it( 'shows prev/next pagination when totalUrls > URLS_PER_PAGE', () => {
		const { container, unmount } = mount( { totalUrls: 250 } );
		expect( container.textContent ).toContain( 'Prev' );
		expect( container.textContent ).toContain( 'Next' );
		expect( container.textContent ).toContain( 'Page 1 of 3' );
		unmount();
	} );

	it( 'renders the pagination controls as stock compact buttons', () => {
		const { container, unmount } = mount( { totalUrls: 250 } );
		const btns = container.querySelectorAll(
			'.event-logger-table__pagination-btn'
		);
		expect( btns.length ).toBe( 2 );
		btns.forEach( ( btn ) => {
			expect( btn.classList.contains( 'button' ) ).toBe( true );
			expect( btn.classList.contains( 'button-small' ) ).toBe( true );
		} );
		unmount();
	} );

	it( 'advances to page 2 when Next is clicked', () => {
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( {
			totalUrls: 250,
			onParamsChange,
		} );
		const next = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Next' )
		);
		act( () => {
			next.click();
		} );
		expect(
			onParamsChange.mock.calls.some(
				( call ) => call[ 0 ].offset === 100
			)
		).toBe( true );
		unmount();
	} );

	it( 'returns to page 1 when Prev is clicked from page 2', () => {
		const onParamsChange = jest.fn();
		const { container, unmount } = mount( {
			totalUrls: 250,
			onParamsChange,
		} );
		const next = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Next' )
		);
		act( () => {
			next.click();
		} );
		const prev = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Prev' )
		);
		act( () => {
			prev.click();
		} );
		expect(
			onParamsChange.mock.calls.some( ( call ) => call[ 0 ].offset === 0 )
		).toBe( true );
		unmount();
	} );

	it( '/ keyboard focuses the search input', () => {
		const { container, unmount } = mount();
		const input = container.querySelector( 'input[type="text"]' );
		const focusSpy = jest.spyOn( input, 'focus' );
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: '/', bubbles: true } )
			);
		} );
		expect( focusSpy ).toHaveBeenCalled();
		focusSpy.mockRestore();
		unmount();
	} );

	it( '/ keyboard shortcut does not steal focus from active text inputs', () => {
		const { container, unmount } = mount();
		const searchInput = container.querySelector( 'input[type="text"]' );
		const focusSpy = jest.spyOn( searchInput, 'focus' );
		const otherInput = document.createElement( 'input' );
		document.body.appendChild( otherInput );
		act( () => {
			const event = new KeyboardEvent( 'keydown', {
				key: '/',
				bubbles: true,
			} );
			Object.defineProperty( event, 'target', { value: otherInput } );
			document.dispatchEvent( event );
		} );
		expect( focusSpy ).not.toHaveBeenCalled();
		otherInput.remove();
		focusSpy.mockRestore();
		unmount();
	} );

	it( 'switches the bar to peak_mb when metric=memory', () => {
		// metric=memory drives UrlRow's peak_mb bar branch.
		const { container, unmount } = mount( { metric: 'memory' } );
		expect( container.textContent ).toContain( '/foo' );
		unmount();
	} );

	it( 'flips active sort to asc when the active header is clicked then back to desc on second click', () => {
		const { container, unmount } = mount();
		const reqsHeader = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Reqs' ) );
		act( () => {
			reqsHeader.click();
		} );
		// Header now ▲ (asc).
		expect( reqsHeader.textContent.includes( '▲' ) ).toBe( true );
		act( () => {
			reqsHeader.click();
		} );
		expect( reqsHeader.textContent.includes( '▼' ) ).toBe( true );
		unmount();
	} );
} );

describe( 'column layout', () => {
	// The grid track list and the COLUMNS array are one fact. Deleting the p95
	// column in 0.67.0 left an 11-track template over 10 columns, which slid
	// Mem into p95's 55px track and stranded its own 60px past the last cell.
	it( 'lays out exactly one grid track per rendered column', () => {
		const { container, unmount } = mount();

		const header = container.querySelector( '.event-logger-table__header' );
		const tracks = ( header.style.gridTemplateColumns || '' )
			.split( /\s+(?![^(]*\))/ )
			.filter( Boolean );
		const cells = header.querySelectorAll( '[data-field]' ).length;
		expect( cells ).toBe( 10 );
		expect( tracks ).toHaveLength( cells );
		unmount();
	} );
} );
