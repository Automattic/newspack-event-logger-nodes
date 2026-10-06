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
	default: ( props ) => {
		global.__requestDetailFlameProps = props;
		return 'FLAME_GRAPH';
	},
} ) );
jest.mock( '../../RequestProfile', () => ( {
	__esModule: true,
	default: () => 'REQUEST_PROFILE',
} ) );
jest.mock( '../LogEntriesTable', () => ( {
	__esModule: true,
	default: function MockLogEntriesTable( { revealRef } ) {
		const React = jest.requireActual( 'react' );
		React.useEffect( () => {
			global.__requestDetailTableMounts =
				( global.__requestDetailTableMounts || 0 ) + 1;
		}, [] );
		global.__requestDetailReveal = jest.fn();
		if ( revealRef ) {
			revealRef.current = global.__requestDetailReveal;
		}
		return 'LOG_ENTRIES_TABLE';
	},
} ) );

import * as React from 'react';
import RequestDetailView from '../RequestDetailView';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const baseRequest = {
	request_method: 'GET',
	url: '/foo',
	request_url: '/foo?kea=4417',
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
	it( 'mounts a fresh log table for each request, so no fold state carries over', () => {
		global.__requestDetailTableMounts = 0;
		const props = ( rid ) =>
			React.createElement( RequestDetailView, {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [ { k: 'leaf', ts: 1 } ],
				realEntryCount: 1,
				rid,
			} );
		const { rerender, unmount } = renderComponent( props( 'r-417' ) );
		rerender( props( 'r-417' ) );
		expect( global.__requestDetailTableMounts ).toBe( 1 );
		rerender( props( 'r-928' ) );
		expect( global.__requestDetailTableMounts ).toBe( 2 );
		unmount();
	} );

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
		expect( text ).toContain( 'GET /foo?kea=4417' );
		expect( text ).toContain( '123.46' );
		expect( text ).toContain( '4 MB' );
		expect( text ).toContain( '200' );
		unmount();
	} );

	it( 'shows the findings the record carries, with the hooks a proposal names', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: {
					...baseRequest,
					findings: [
						{
							kind: 'insufficient_instrumentation',
							severity: 'high',
							title: 'The governing rule registers no hooks, so nothing inside the request is measured',
							measured: 'rule + record',
							proposal: {
								action: 'add_hooks',
								direction: 'more',
								hooks: [ 'init', 'template_redirect' ],
								why: 'Nothing watches the slow part.',
							},
						},
						{
							kind: 'truncation',
							severity: 'info',
							title: 'This record was folded under memory pressure',
							detail: 'Repeated spans were merged.',
							measured: 'record markers',
							proposal: {
								action: 'trim_hooks',
								direction: 'less',
							},
						},
						{
							kind: 'fatal',
							severity: 'high',
							title: 'The request died in the sample plugin',
							measured: 'php fatal',
							proposal: { action: 'none' },
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const text = container.textContent;
		expect( text ).toContain( 'Findings' );
		expect( text ).toContain( 'Repeated spans were merged.' );
		expect( text ).toContain( 'less noise' );
		// A fatal proposes nothing: no rule edit fixes a crash.
		expect( text ).not.toContain( 'none' );
		expect( text ).toContain( 'nothing inside the request is measured' );
		expect( text ).toContain( 'init, template_redirect' );
		unmount();
	} );

	it( 'names what a proposal acts on, and how to take it back', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: {
					...baseRequest,
					findings: [
						{
							kind: 'dominant_span',
							severity: 'high',
							title: 'the_content hook holds 81% of the request',
							measured: 'profiles',
							proposal: {
								action: 'mark_significant',
								direction: 'more',
								value: 'the_content',
								why: 'Per-callback timing names the callback.',
								undo: 'Remove the_content from significant events.',
							},
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const proposal = container.querySelector(
			'.event-logger-findings__proposal'
		).textContent;
		expect( proposal ).toContain( 'the_content' );
		expect( container.textContent ).toContain(
			'Remove the_content from significant events.'
		);
		unmount();
	} );

	it( 'says a fold once, as a finding, when the record carries findings', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: {
					...baseRequest,
					folded: true,
					findings: [
						{
							kind: 'truncation',
							severity: 'info',
							title: 'This record was folded under memory pressure',
							measured: 'record markers',
							proposal: { action: 'none' },
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).not.toContain(
			'Aggregated under load'
		);
		unmount();
	} );

	it( 'says nothing stands out when the detector found nothing', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: { ...baseRequest, findings: [] },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Nothing stands out' );
		unmount();
	} );

	it( 'draws no findings section for a record no detector ran over', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).not.toContain( 'Findings' );
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
		const badge = container.querySelector( '.newspack-nodes-badge' );
		expect( badge.className ).toBe(
			'newspack-nodes-badge newspack-nodes-status is-warning'
		);
		expect( badge.style.color ).toBe( '' );
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
		const badge = container.querySelector( '.newspack-nodes-badge' );
		expect( badge.className ).toBe(
			'newspack-nodes-badge newspack-nodes-status is-error'
		);
		expect( badge.style.color ).toBe( '' );
		unmount();
	} );

	it( 'shows an "Incomplete" error for error_status=I', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestDetailView, {
				requestDetail: { ...baseRequest, error_status: 'I' },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Incomplete' );
		const badge = container.querySelector( '.newspack-nodes-badge' );
		expect( badge.className ).toBe(
			'newspack-nodes-badge newspack-nodes-status is-warning'
		);
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
		act( () => {
			global.__requestDetailFlameProps.onRevealEntry( 233, [
				'process',
				'db',
			] );
		} );
		expect( global.__requestDetailReveal ).toHaveBeenCalledWith( 233, [
			'process',
			'db',
		] );
		unmount();
	} );
} );
