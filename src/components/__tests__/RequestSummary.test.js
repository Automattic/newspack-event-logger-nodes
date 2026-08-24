/**
 * Tests for RequestSummary — the URL/Time/Duration/Memory/Status rows the
 * performance dashboard's detail modal and the current-request overlay both
 * render. It emits bare rows; the caller owns the container and the wording of
 * anything that varies between the two.
 */

import * as React from 'react';
import RequestSummary from '../RequestSummary';
import { renderComponent } from '../../test-helpers/renderHook';

const request = {
	request_method: 'PATCH',
	url: '/wp-json/newspack-nodes/v1/command',
	timestamp: 1234567890, // 2009-02-13 23:31:30 UTC.
	duration_ms: 777.777,
	peak_mb: 313,
	status_code: 451,
};

const rows = ( container ) => Array.from( container.querySelectorAll( 'p' ) );

describe( 'RequestSummary', () => {
	it( 'renders method, url, time, duration, memory and status', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, { request } )
		);
		const text = container.textContent;
		expect( text ).toContain( 'PATCH' );
		expect( text ).toContain( '/wp-json/newspack-nodes/v1/command' );
		expect( text ).toContain(
			new Date( 1234567890 * 1000 ).toLocaleString()
		);
		expect( text ).toContain( '777.78 ms' );
		expect( text ).toContain( '313 MB' );
		expect( text ).toContain( '451' );
		unmount();
	} );

	it( 'falls back to the compact summary spelling of the HTTP verb', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request: {
					...request,
					request_method: undefined,
					method: 'HEAD',
				},
			} )
		);
		expect( container.textContent ).toContain( 'HEAD' );
		unmount();
	} );

	it( 'places a dash rather than the epoch when there is no timestamp', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request: { ...request, timestamp: 0 },
			} )
		);
		const timeRow = rows( container ).find( ( p ) =>
			p.querySelector( 'strong' ).textContent.includes( 'Time' )
		);
		expect( timeRow.textContent ).toContain( '—' );
		expect( timeRow.textContent ).not.toContain( '1970' );
		unmount();
	} );

	it( 'omits memory and status when the record carries neither', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request: { ...request, peak_mb: 0, status_code: 0 },
			} )
		);
		expect( container.textContent ).not.toContain( 'Memory' );
		expect( container.textContent ).not.toContain( 'Status' );
		unmount();
	} );

	it( 'appends the caller-formatted note and marks the status row an error', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request,
				statusNote: 'went sideways',
			} )
		);
		const statusRow = rows( container ).find( ( p ) =>
			p.querySelector( 'strong' ).textContent.includes( 'Status' )
		);
		expect( statusRow.textContent ).toContain( '451 — went sideways' );
		expect( statusRow.className ).toBe( 'newspack-nodes-status is-error' );
		unmount();
	} );

	it( 'leaves the status row unmarked without a note', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, { request } )
		);
		const statusRow = rows( container ).find( ( p ) =>
			p.querySelector( 'strong' ).textContent.includes( 'Status' )
		);
		expect( statusRow.className ).toBe( 'newspack-nodes-status' );
		unmount();
	} );

	it( 'renders the caller row after the status', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request,
				errorRow: React.createElement(
					'p',
					{ id: 'badge' },
					'ORPHANED'
				),
			} )
		);
		const all = rows( container );
		expect( all[ all.length - 1 ].id ).toBe( 'badge' );
		expect( container.textContent ).toContain( 'ORPHANED' );
		unmount();
	} );
} );
