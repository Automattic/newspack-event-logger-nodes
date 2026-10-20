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
	url: 'https://kea.test/',
	request_url: 'https://kea.test/?rest_route=%2Fzz%2Fv9&fields=a%2Cb',
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
		expect( text ).toContain(
			'PATCH https://kea.test/?rest_route=%2Fzz%2Fv9&fields=a%2Cb'
		);
		expect( text ).toContain(
			new Date( 1234567890 * 1000 ).toLocaleString()
		);
		expect( text ).toContain( '777.78 ms' );
		expect( text ).toContain( '313 MB' );
		expect( text ).toContain( '451' );
		unmount();
	} );

	it.each( [
		[ 'absent', undefined ],
		[ 'empty', '' ],
	] )(
		'says the URL was not recorded when request_url is %s',
		( _, value ) => {
			const { container, unmount } = renderComponent(
				React.createElement( RequestSummary, {
					request: {
						...request,
						request_method: undefined,
						request_url: value,
					},
				} )
			);
			expect( rows( container )[ 0 ].textContent ).toBe(
				'URL: not recorded'
			);
			unmount();
		}
	);

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
	it( "names core's object cache hits, misses and hit ratio", () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request: {
					...request,
					object_cache: { kind: 'core', hits: 4815, misses: 162 },
				},
			} )
		);
		expect( container.textContent ).toContain(
			'Object cache: 4815 hits, 162 misses (96.7% hit ratio)'
		);
		unmount();
	} );

	it( "names the memcached drop-in's counts as exactly what they are", () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request: {
					...request,
					object_cache: {
						kind: 'memcached',
						local: 900,
						gets: 120,
						hits: 100,
						misses: 20,
						multi_keys: 37,
					},
				},
			} )
		);
		const text = container.textContent;
		expect( text ).toContain( 'Object cache: 900 answered locally' );
		expect( text ).toContain(
			'120 memcached gets: 100 found, 20 missed (83.3% memcached hit ratio)'
		);
		expect( text ).toContain( '37 keys by get_multiple' );
		unmount();
	} );

	it( 'leaves the cache row out when the record carries no counts', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, { request } )
		);
		expect( container.textContent ).not.toContain( 'Object cache' );
		unmount();
	} );

	it( 'claims no ratio where nothing was looked up', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestSummary, {
				request: {
					...request,
					object_cache: {
						kind: 'memcached',
						local: 4,
						gets: 0,
						hits: 0,
						misses: 0,
						multi_keys: 0,
					},
				},
			} )
		);
		expect( container.textContent ).toContain(
			'Object cache: 4 answered locally; 0 memcached gets'
		);
		expect( container.textContent ).not.toContain( 'hit ratio' );
		unmount();
	} );
} );
