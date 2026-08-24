/* global KeyboardEvent */
/**
 * Tests for RequestProfile — pure render component, no hooks or
 * network. Sorts profiles by time desc, hides zero-time entries,
 * collapses callback-breakdown rows (containing " @N "), and limits
 * to 10 visible rows until the "Show more" button is clicked.
 *
 * `ProfileWithCaption` wraps it for the two averaged views, so its cases run
 * against the real profile rather than a sentinel: what the caption says and
 * which heading survives are both answers the pair gives together.
 */

import * as React from 'react';
import RequestProfile, { ProfileWithCaption } from '../RequestProfile';
import { renderComponent, act } from '../../test-helpers/renderHook';

const baseProfiles = {
	hooks: { count: 5, time: 80 },
	db: { count: 3, time: 30 },
	template: { count: 2, time: 10 },
};

describe( 'RequestProfile', () => {
	it( 'returns null when profiles is undefined / empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: undefined,
				totalMs: 100,
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();

		const second = renderComponent(
			React.createElement( RequestProfile, {
				profiles: {},
				totalMs: 100,
			} )
		);
		expect( second.container.textContent ).toBe( '' );
		second.unmount();
	} );

	it( 'renders rows sorted by time descending', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: baseProfiles,
				totalMs: 120,
			} )
		);
		const rows = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).filter( ( r ) => r.querySelectorAll( 'td' ).length === 4 );
		const firstCellTexts = rows.map( ( r ) =>
			r.querySelector( 'td' ).textContent.trim()
		);
		// hooks (80), db (30), template (10), Total Profiled
		expect( firstCellTexts[ 0 ] ).toContain( 'hooks' );
		expect( firstCellTexts[ 1 ] ).toContain( 'db' );
		expect( firstCellTexts[ 2 ] ).toContain( 'template' );
		unmount();
	} );

	it( 'computes Total Profiled by summing non-callback categories', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: {
					hooks: { count: 1, time: 50 },
					'the_content @10 do_blocks': { count: 1, time: 100 }, // callback - skipped.
				},
				totalMs: 200,
			} )
		);
		// Total Profiled row shows 50ms only, callback ignored from the sum.
		const totalRow = container.querySelector( 'tfoot tr' );
		expect( totalRow.textContent ).toContain( '50' );
		unmount();
	} );

	it( 'uses totalProfiledTime override when provided', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: baseProfiles,
				totalMs: 100,
				totalProfiledTime: 999,
			} )
		);
		const totalRow = container.querySelector( 'tfoot tr' );
		expect( totalRow.textContent ).toContain( '999' );
		unmount();
	} );

	it( 'hides the title when title=null', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: baseProfiles,
				totalMs: 100,
				title: null,
			} )
		);
		expect( container.querySelector( 'h3' ) ).toBeNull();
		unmount();
	} );

	it( 'caps visible rows at 10 and shows the "more" button', () => {
		const many = {};
		for ( let i = 0; i < 15; i++ ) {
			many[ `cat${ i }` ] = { count: 1, time: 100 - i };
		}
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: many,
				totalMs: 1500,
			} )
		);
		const dataRows = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).filter( ( r ) => {
			const t = r.textContent;
			return ! t.includes( 'Total Profiled' ) && ! t.includes( 'more' );
		} );
		expect( dataRows.length ).toBe( 10 );
		// Show-more button exists.
		const more = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'more' )
		);
		expect( more ).toBeDefined();
		act( () => {
			more.click();
		} );
		// After clicking, all 15 rows visible.
		const rowsAfter = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).filter( ( r ) => {
			const t = r.textContent;
			return ! t.includes( 'Total Profiled' ) && ! t.includes( 'less' );
		} );
		expect( rowsAfter.length ).toBe( 15 );
		unmount();
	} );

	it( 'expands a category to show its callback entries on click', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: {
					hooks: {
						count: 2,
						time: 80,
						entries: {
							my_callback: [ 50, 1 ],
							another: [ 30, 1 ],
						},
					},
				},
				totalMs: 100,
			} )
		);
		// Row with "hooks" should be clickable.
		const hooksRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.trim().startsWith( 'hooks' ) );
		act( () => {
			hooksRow.click();
		} );
		expect( container.textContent ).toContain( 'my_callback' );
		expect( container.textContent ).toContain( 'another' );
		unmount();
	} );

	it( 'expands a category from summary-bar keyboard activation', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: {
					hooks: {
						count: 1,
						time: 80,
						entries: {
							my_callback: [ 80, 1 ],
						},
					},
				},
				totalMs: 100,
			} )
		);
		const barSegment = container.querySelector(
			'.event-logger-profile-bar [role="button"]'
		);
		act( () => {
			barSegment.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		expect( container.textContent ).toContain( 'my_callback' );
		unmount();
	} );

	it( 'uses the quiet-link and undivided native-table roles', () => {
		const many = {};
		for ( let i = 0; i < 15; i++ ) {
			many[ `cat${ i }` ] = { count: 1, time: 100 - i };
		}
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: many,
				totalMs: 1500,
			} )
		);
		// The profile bar's static surface paint lives in its semantic stylesheet.
		const bar = container.querySelector( '.event-logger-profile-bar' );
		expect( bar.style.background ).toBe( '' );
		// One shared quiet-link role owns the "Show N more" control.
		const more = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'more' )
		);
		expect( [ ...more.classList ] ).toEqual( [
			'button-link',
			'event-logger-profile-expansion',
		] );
		expect( more.style.color ).toBe( '' );
		const table = container.querySelector( ':scope > table' );
		expect( table.classList.contains( 'newspack-nodes-table' ) ).toBe(
			true
		);
		expect(
			table.classList.contains( 'newspack-nodes-table--undivided' )
		).toBe( true );
		const dataCells = table.querySelectorAll(
			':scope > tbody > tr:first-child > td'
		);
		expect(
			dataCells[ 0 ].classList.contains(
				'newspack-nodes-table__terminal-data'
			)
		).toBe( false );
		for ( const cell of [ ...dataCells ].slice( 1 ) ) {
			expect(
				cell.classList.contains( 'newspack-nodes-table__terminal-data' )
			).toBe( true );
		}
		// Canonical summary semantics own the Total Profiled row.
		const totalRow = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.textContent.includes( 'Total Profiled' )
		);
		expect( totalRow.style.background ).toBe( '' );
		expect( totalRow.parentElement.tagName ).toBe( 'TFOOT' );
		expect(
			totalRow.classList.contains( 'newspack-nodes-table__summary' )
		).toBe( true );
		unmount();
	} );

	it( 'delegates status and nested-table appearance to canonical classes', () => {
		const entries = {};
		for ( let i = 0; i < 12; i++ ) {
			entries[ `cb${ i }` ] = [ 12 - i, 1 ];
		}
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: { the_content: { count: 1, time: 50, entries } },
				totalMs: 100,
			} )
		);
		// Shared status chrome owns the expand caret.
		const caret = Array.from( container.querySelectorAll( 'span' ) ).find(
			( s ) => s.textContent === '▶' || s.textContent === '▼'
		);
		expect( caret.classList.contains( 'newspack-nodes-status' ) ).toBe(
			true
		);
		expect( caret.classList.contains( 'is-info' ) ).toBe( false );
		expect( caret.style.color ).toBe( '' );
		// Expand the row to reveal the callback breakdown sub-row.
		const row = Array.from( container.querySelectorAll( 'tbody tr' ) ).find(
			( r ) => r.textContent.trim().startsWith( 'the_content' )
		);
		act( () => {
			row.click();
		} );
		// The canonical nested table owns callback-row chrome.
		const subCell = container.querySelector( 'td[colspan="4"]' );
		expect( subCell.style.background ).toBe( '' );
		expect(
			subCell.parentElement.classList.contains(
				'newspack-nodes-table__details'
			)
		).toBe( true );
		const nestedTable = subCell.querySelector( 'table' );
		expect(
			nestedTable.classList.contains( 'newspack-nodes-table--undivided' )
		).toBe( true );
		const nestedCells = nestedTable.querySelectorAll(
			'tbody tr:first-child td'
		);
		expect(
			nestedCells[ 0 ].classList.contains(
				'newspack-nodes-table__terminal-data'
			)
		).toBe( false );
		for ( const cell of [ ...nestedCells ].slice( 1 ) ) {
			expect(
				cell.classList.contains( 'newspack-nodes-table__terminal-data' )
			).toBe( true );
		}
		// Shared status chrome owns the "… and N more" message.
		const moreCell = Array.from(
			container.querySelectorAll( 'td[colspan="3"]' )
		).find( ( td ) => td.textContent.includes( 'more' ) );
		expect( moreCell.classList.contains( 'newspack-nodes-status' ) ).toBe(
			true
		);
		expect( moreCell.style.color ).toBe( '' );
		unmount();
	} );

	it( 'leaves the callback breakdown geometry to its stylesheet', () => {
		const entries = {};
		for ( let i = 0; i < 13; i++ ) {
			entries[ `cb${ i }` ] = [ 13 - i, 1 ];
		}
		const { container, unmount } = renderComponent(
			React.createElement( RequestProfile, {
				profiles: { shortcodes: { count: 3, time: 42, entries } },
				totalMs: 90,
			} )
		);
		const row = Array.from( container.querySelectorAll( 'tbody tr' ) ).find(
			( r ) => r.textContent.trim().startsWith( 'shortcodes' )
		);
		act( () => {
			row.click();
		} );

		// The category swatch keeps only the colour it computes.
		const swatch = container.querySelector(
			'.event-logger-profile-swatch'
		);
		expect( swatch.style.width ).toBe( '' );
		expect( swatch.style.background ).not.toBe( '' );

		// Every cell of the nested table is named, and none carries geometry.
		const nested = container.querySelector(
			'.event-logger-profile-entries'
		);
		expect( nested.style.width ).toBe( '' );
		const nameCell = nested.querySelector(
			'.event-logger-profile-entries__name'
		);
		expect( nameCell.style.padding ).toBe( '' );
		expect( nameCell.style.textOverflow ).toBe( '' );
		expect(
			nested.querySelector( '.event-logger-profile-entries__time' ).style
				.width
		).toBe( '' );
		expect(
			nested.querySelector( '.event-logger-profile-entries__count' ).style
				.width
		).toBe( '' );
		expect(
			nested.querySelector( '.event-logger-profile-entries__more' ).style
				.fontStyle
		).toBe( '' );

		// The three trailing entries are summarized, not drawn.
		expect( container.textContent ).toContain( 'and 3 more' );
		expect( container.textContent ).not.toContain( 'cb12' );
		unmount();
	} );
} );

describe( 'ProfileWithCaption', () => {
	const averaged = {
		profiles: { db: { time: 95.1, count: 3 } },
		totalMs: 317,
		totalProfiledTime: 95.1,
		count: 7331,
	};

	it( 'captions the site-wide average with the request count', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ProfileWithCaption, averaged )
		);
		// The denominator is the caller's average, not the profiled total.
		expect( container.textContent ).toContain( '30.0%' );
		expect( container.textContent ).toContain(
			'Average breakdown across 7331 requests'
		);
		// No heading of its own, so the profile keeps its built-in title.
		const headings = [ ...container.querySelectorAll( 'h3' ) ].map(
			( heading ) => heading.textContent
		);
		expect( headings ).toEqual( [ 'Time Breakdown' ] );
		unmount();
	} );

	it( 'names the server in both the heading and the caption', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ProfileWithCaption, {
				...averaged,
				heading: 'Time Breakdown (edge-77)',
				serverName: 'edge-77',
			} )
		);
		// The caller's heading replaces the profile's own rather than stacking.
		const headings = [ ...container.querySelectorAll( 'h3' ) ].map(
			( heading ) => heading.textContent
		);
		expect( headings ).toEqual( [ 'Time Breakdown (edge-77)' ] );
		expect( container.textContent ).toContain(
			'Average breakdown across 7331 requests on edge-77'
		);
		unmount();
	} );

	it( 'keeps the caption singular for one request', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ProfileWithCaption, {
				...averaged,
				count: 1,
			} )
		);
		expect( container.textContent ).toContain(
			'Average breakdown across 1 request'
		);
		expect( container.textContent ).not.toContain( '1 requests' );
		unmount();
	} );
} );
