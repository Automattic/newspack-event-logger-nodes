/**
 * Tests for the dashboard "Page" wrappers — GyroscopePage, RequestStreamPage.
 * They're identical thin shells: fixed-position dark div + the heavy dashboard
 * child, with useAdminMenuWidth controlling the left offset.
 *
 * Each child is mocked so we don't pay the SSE / d3 / canvas tax just
 * to assert the wrapper renders. (The Aggregator Status dashboard moved to the
 * newspack-nodes substrate as a DevTools hub tab; the hub supplies its page
 * chrome there, so there is no longer an ELN page wrapper for it.)
 */

jest.mock( '../gyroscope/Inflight', () => ( {
	__esModule: true,
	default: ( { maxRows } ) => `INFLIGHT[${ maxRows }]`,
} ) );
jest.mock( '../requests/RequestStream', () => ( {
	__esModule: true,
	default: ( { maxEntries } ) => `REQUEST_STREAM[${ maxEntries }]`,
} ) );

import * as React from 'react';
import GyroscopePage from '../gyroscope/GyroscopePage';
import RequestStreamPage from '../requests/RequestStreamPage';
import { renderComponent } from '../test-helpers/renderHook';

describe( 'page wrappers', () => {
	it( 'GyroscopePage mounts <Inflight maxRows={100}>', () => {
		const { container, unmount } = renderComponent(
			React.createElement( GyroscopePage )
		);
		expect( container.textContent ).toContain( 'INFLIGHT[100]' );
		unmount();
	} );

	it( 'RequestStreamPage mounts <RequestStream maxEntries={1000}>', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestStreamPage )
		);
		expect( container.textContent ).toContain( 'REQUEST_STREAM[1000]' );
		unmount();
	} );

	// The dashboards reference var(--np-*) tokens defined on the
	// `.newspack-nodes-theme` root class (the shared newspack-nodes-theme
	// stylesheet). Each full-page wrapper must carry it so those resolve.
	it( 'GyroscopePage root carries .newspack-nodes-theme', () => {
		const { container, unmount } = renderComponent(
			React.createElement( GyroscopePage )
		);
		expect(
			container.querySelector( '.newspack-nodes-theme' )
		).not.toBeNull();
		unmount();
	} );

	it( 'RequestStreamPage root carries .newspack-nodes-theme', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestStreamPage )
		);
		expect(
			container.querySelector( '.newspack-nodes-theme' )
		).not.toBeNull();
		unmount();
	} );

	// Each wrapper sits inside a ThemedRoot token-provider so a
	// console-selected skin's universal tokens scope the dashboard.
	it( 'GyroscopePage wraps its root in a ThemedRoot token provider', () => {
		const { container, unmount } = renderComponent(
			React.createElement( GyroscopePage )
		);
		const provider = container.querySelector(
			'.topology-app.newspack-nodes-theme'
		);
		expect( provider ).not.toBeNull();
		expect( provider.style.display ).toBe( 'contents' );
		unmount();
	} );

	it( 'RequestStreamPage wraps its root in a ThemedRoot token provider', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestStreamPage )
		);
		const provider = container.querySelector(
			'.topology-app.newspack-nodes-theme'
		);
		expect( provider ).not.toBeNull();
		expect( provider.style.display ).toBe( 'contents' );
		unmount();
	} );
} );
