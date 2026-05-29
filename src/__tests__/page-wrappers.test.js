/**
 * Tests for the three dashboard "Page" wrappers — AggregatorStatusPage,
 * GyroscopePage, RequestStreamPage. They're identical thin shells:
 * fixed-position dark div + the heavy dashboard child, with
 * useAdminMenuWidth controlling the left offset.
 *
 * Each child is mocked so we don't pay the SSE / d3 / canvas tax just
 * to assert the wrapper renders.
 */

jest.mock( '../event-aggregator/AggregatorStatus', () => ( {
	__esModule: true,
	default: () => 'AGGREGATOR_STATUS',
} ) );
jest.mock( '../performance-gyroscope/Inflight', () => ( {
	__esModule: true,
	default: ( { maxRows } ) => `INFLIGHT[${ maxRows }]`,
} ) );
jest.mock( '../performance-request-log/RequestStream', () => ( {
	__esModule: true,
	default: ( { maxEntries } ) => `REQUEST_STREAM[${ maxEntries }]`,
} ) );

import * as React from 'react';
import AggregatorStatusPage from '../event-aggregator/AggregatorStatusPage';
import GyroscopePage from '../performance-gyroscope/GyroscopePage';
import RequestStreamPage from '../performance-request-log/RequestStreamPage';
import { renderComponent } from '../shared/hooks/__tests__/renderHook';

describe( 'page wrappers', () => {
	it( 'AggregatorStatusPage mounts <AggregatorStatus>', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregatorStatusPage )
		);
		expect( container.textContent ).toContain( 'AGGREGATOR_STATUS' );
		const root = container.firstChild;
		expect( root.style.position ).toBe( 'fixed' );
		expect( root.style.zIndex ).toBe( '99' );
		unmount();
	} );

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
