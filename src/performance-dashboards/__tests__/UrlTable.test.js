/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * Tests for UrlTable — sortable virtualized URL list.
 *
 * Mocks useVirtualization so we get all rows in test (real hook needs
 * a real scroll context).
 */

jest.mock( '../../shared/hooks/useVirtualization', () => ( {
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
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

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
		p95_ms: 90,
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
		p95_ms: 180,
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
		p95_ms: 220,
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
	it( 'renders each URL row', () => {
		const { container, unmount } = mount();
		expect( container.textContent ).toContain( '/foo' );
		expect( container.textContent ).toContain( '/bar' );
		expect( container.textContent ).toContain( '/baz' );
		unmount();
	} );

	it( 'sorts rows by count desc by default', () => {
		const { container, unmount } = mount();
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		// Default sort = count desc → /baz (200), /foo (100), /bar (50).
		expect( rows[ 0 ].textContent ).toContain( '/baz' );
		expect( rows[ 1 ].textContent ).toContain( '/foo' );
		expect( rows[ 2 ].textContent ).toContain( '/bar' );
		unmount();
	} );

	it( 'toggles direction when clicking the active sort header', () => {
		const { container, unmount } = mount();
		const reqsHeader = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Reqs' ) );
		// First click on already-active 'count' → flips to asc.
		act( () => {
			reqsHeader.click();
		} );
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		expect( rows[ 0 ].textContent ).toContain( '/bar' );
		expect( rows[ 2 ].textContent ).toContain( '/baz' );
		unmount();
	} );

	it( 'switches sort field to URL when URL header clicked', () => {
		const { container, unmount } = mount();
		const urlHeader = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => /^URL/.test( b.textContent ) );
		act( () => {
			urlHeader.click();
		} );
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		// URL desc: /foo, /baz, /bar.
		expect( rows[ 0 ].textContent ).toContain( '/foo' );
		expect( rows[ 2 ].textContent ).toContain( '/bar' );
		unmount();
	} );

	it( 'filters by search term', () => {
		const { container, unmount } = mount();
		const input = container.querySelector( 'input[type="text"]' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'baz' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		expect( rows.length ).toBe( 1 );
		expect( rows[ 0 ].textContent ).toContain( '/baz' );
		unmount();
	} );

	it( 'shows the search empty state when nothing matches', () => {
		const { container, unmount } = mount();
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

	it( '"Errors Only" toggle keeps only rows with unclassified requests', () => {
		const { container, unmount } = mount();
		const btn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === 'Errors Only'
		);
		act( () => {
			btn.click();
		} );
		const rows = Array.from(
			container.querySelectorAll( '.event-logger-table__row' )
		);
		// /baz has count_2xx=100, count=200 → 100 unclassified → kept.
		// /foo, /bar fully classified → dropped.
		expect( rows.length ).toBe( 1 );
		expect( rows[ 0 ].textContent ).toContain( '/baz' );
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

	it( 'reports params via onParamsChange on mount', () => {
		const onParamsChange = jest.fn();
		const { unmount } = mount( { onParamsChange } );
		expect( onParamsChange ).toHaveBeenCalledWith( {
			search: '',
			sort: 'count',
			order: 'desc',
			offset: 0,
		} );
		unmount();
	} );

	it( 'shows total count summary when total <= URLS_PER_PAGE (100)', () => {
		const { container, unmount } = mount( { totalUrls: 3 } );
		expect( container.textContent ).toContain( '3 URLs' );
		expect( container.textContent ).not.toContain( 'Prev' );
		unmount();
	} );

	it( 'shows prev/next pagination when totalUrls > URLS_PER_PAGE', () => {
		const { container, unmount } = mount( { totalUrls: 250 } );
		expect( container.textContent ).toContain( 'Prev' );
		expect( container.textContent ).toContain( 'Next' );
		expect( container.textContent ).toContain( 'Page 1 of 3' );
		unmount();
	} );
} );
