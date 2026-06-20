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

jest.mock( '../performance-gyroscope/Inflight', () => ( {
	__esModule: true,
	default: ( { maxRows } ) => `INFLIGHT[${ maxRows }]`,
} ) );
jest.mock( '../performance-request-log/RequestStream', () => ( {
	__esModule: true,
	default: ( { maxEntries } ) => `REQUEST_STREAM[${ maxEntries }]`,
} ) );

import * as React from 'react';
import GyroscopePage from '../performance-gyroscope/GyroscopePage';
import RequestStreamPage from '../performance-request-log/RequestStreamPage';
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
} );
