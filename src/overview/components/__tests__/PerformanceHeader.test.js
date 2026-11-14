/**
 * The Performance page's header controls sit in the shell header, whose
 * subtitle never yields width: the controls' own box wraps instead, so a
 * narrow header stacks them rather than drawing them over the page's name.
 * The page state and the Ask trigger are mocked; only the box is asserted.
 */

import * as React from 'react';
import PerformanceHeader, { SearchFindings } from '../PerformanceHeader';
import { act, renderComponent } from '../../../test-helpers/renderHook';

jest.mock( '@newspack-nodes/shared/composition/page/pageState', () => ( {
	...jest.requireActual(
		'@newspack-nodes/shared/composition/page/pageState'
	),
	usePageState: () => [ 7, () => {} ],
} ) );
jest.mock( '../AskPanel', () => ( {
	AskButton: () => null,
} ) );

describe( 'PerformanceHeader', () => {
	it( 'wraps its controls, packed to the end, rather than overflowing', () => {
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceHeader, {
				ask: {},
				searchQuery: 'okapi',
				setSearchQuery: () => {},
				searchLoading: false,
				searchError: null,
				onSearch: () => {},
			} )
		);

		const box = container.firstElementChild;
		expect( box.style.flexWrap ).toBe( 'wrap' );
		expect( box.style.justifyContent ).toBe( 'flex-end' );
		unmount();
	} );

	it( 'runs the search on Enter with the box text, and on no other key', () => {
		const onSearch = jest.fn();
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceHeader, {
				ask: {},
				searchQuery: '/kea-7990',
				setSearchQuery: () => {},
				searchLoading: true,
				searchError: 'search refused by partition 4',
				onSearch,
			} )
		);
		const input = container.querySelector( 'input[type="search"]' );
		act( () => {
			input.dispatchEvent(
				new window.KeyboardEvent( 'keydown', {
					key: 'a',
					bubbles: true,
				} )
			);
		} );
		expect( onSearch ).not.toHaveBeenCalled();
		act( () => {
			input.dispatchEvent(
				new window.KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		expect( onSearch ).toHaveBeenCalledWith( '/kea-7990' );
		expect( container.textContent ).toContain( 'Searching…' );
		expect( container.textContent ).toContain(
			'search refused by partition 4'
		);
		unmount();
	} );
} );

describe( 'SearchFindings', () => {
	const results = [
		{
			rid: 'r-okarito',
			method: 'POST',
			url: 'https://kea.example/wp-json/x',
			match_count: 3,
		},
		{ rid: 'r-bare', method: 'GET', url: '', match_count: 1 },
	];

	it( 'lists each match, opens one by its rid, and notes a capped list', () => {
		const onSelect = jest.fn();
		const { container, unmount } = renderComponent(
			React.createElement( SearchFindings, {
				results,
				truncated: true,
				unparseableLines: undefined,
				onSelect,
			} )
		);
		const buttons = container.querySelectorAll( 'button' );
		expect( buttons ).toHaveLength( 2 );
		expect( buttons[ 0 ].textContent ).toContain( 'POST' );
		expect( buttons[ 0 ].textContent ).toContain( '3 matches' );
		expect( buttons[ 1 ].textContent ).toContain( 'r-bare' );
		expect( buttons[ 1 ].textContent ).toContain( '1 match' );
		act( () => buttons[ 1 ].click() );
		expect( onSelect ).toHaveBeenCalledWith( 'r-bare' );
		expect( container.textContent ).toContain( 'Showing first results' );
		unmount();
	} );

	it( 'draws no list for no match, and no cap note when uncapped', () => {
		const { container, unmount } = renderComponent(
			React.createElement( SearchFindings, {
				results: [],
				truncated: false,
				unparseableLines: undefined,
				onSelect: () => {},
			} )
		);
		expect( container.querySelector( 'ul' ) ).toBeNull();
		unmount();
		const uncapped = renderComponent(
			React.createElement( SearchFindings, {
				results,
				truncated: false,
				unparseableLines: undefined,
				onSelect: () => {},
			} )
		);
		expect( uncapped.container.textContent ).not.toContain(
			'Showing first results'
		);
		uncapped.unmount();
	} );
} );
