/* global KeyboardEvent */
/**
 * Tests for RequestProfile — pure render component, no hooks or
 * network. Sorts profiles by time desc, hides zero-time entries,
 * collapses callback-breakdown rows (containing " @N "), and limits
 * to 10 visible rows until the "Show more" button is clicked.
 */

import * as React from 'react';
import RequestProfile from '../RequestProfile';
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
		const totalRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.includes( 'Total Profiled' ) );
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
		const totalRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.includes( 'Total Profiled' ) );
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
} );
