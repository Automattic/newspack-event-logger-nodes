/**
 * Tests for RequestDetailView — render-side branches.
 *
 * Children mocked at the module boundary:
 *   - FlameGraph (lazy)
 *   - RequestProfile
 *   - LogEntriesTable
 *
 * Each mock renders a marker element so we can assert sections appear /
 * are omitted based on the request payload shape.
 */

jest.mock( '../../FlameGraph', () => ( {
	__esModule: true,
	default: () => 'FLAME_GRAPH',
} ) );
jest.mock( '../../RequestProfile', () => ( {
	__esModule: true,
	default: () => 'REQUEST_PROFILE',
} ) );
jest.mock( '../LogEntriesTable', () => ( {
	__esModule: true,
	default: () => 'LOG_ENTRIES_TABLE',
} ) );

import * as React from 'react';
import RequestDetailView from '../RequestDetailView';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const baseRequest = {
	request_method: 'GET',
	url: '/foo',
	timestamp: 1748960000, // 2025-06-03 14:13:20 UTC.
	duration_ms: 123.456,
	peak_mb: 4,
	status_code: 200,
};

async function renderAsync( element ) {
	const result = renderComponent( element );
	// Suspense-children won't appear synchronously; flush microtasks.
	await act( async () => {} );
	return result;
}

describe( 'RequestDetailView', () => {
	it( 'renders URL, time, duration, memory, status', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const text = container.textContent;
		expect( text ).toContain( '/foo' );
		expect( text ).toContain( 'GET' );
		expect( text ).toContain( '123.46' );
		expect( text ).toContain( '4 MB' );
		expect( text ).toContain( '200' );
		unmount();
	} );

	it( 'omits memory + status when both are zero', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: {
					...baseRequest,
					peak_mb: 0,
					status_code: 0,
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).not.toContain( 'Memory' );
		expect( container.textContent ).not.toContain( 'Status' );
		unmount();
	} );

	it( 'shows a "Timed out" error for error_status=T', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: { ...baseRequest, error_status: 'T' },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Timed out' );
		unmount();
	} );

	it( 'shows a "Fatal error" for error_status=F', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: { ...baseRequest, error_status: 'F' },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Fatal error' );
		unmount();
	} );

	it( 'renders the "no log entries" hint when there is nothing to show', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'No log entries available' );
		unmount();
	} );

	it( 'renders FlameGraph + RequestProfile + LogEntriesTable when all sources present', async () => {
		const { container, unmount } = await renderAsync(
			React.createElement( RequestDetailView, {
				requestDetail: { ...baseRequest, profiles: { foo: 1 } },
				flameData: { children: [ { name: 'x' } ] },
				indentedEntries: [ { k: 'leaf', ts: 1 } ],
				realEntryCount: 1,
			} )
		);
		expect( container.textContent ).toContain( 'FLAME_GRAPH' );
		expect( container.textContent ).toContain( 'REQUEST_PROFILE' );
		expect( container.textContent ).toContain( 'LOG_ENTRIES_TABLE' );
		unmount();
	} );
} );
