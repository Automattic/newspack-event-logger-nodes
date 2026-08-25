/**
 * Tests for BreakdownControls — the aggregate chart and its selectors, drawn
 * by both the Overview card and the URL modal.
 *
 * The D3 chart is mocked at the module boundary; what matters here is which
 * selectors mount and what the chart is handed.
 */

// The chart is mocked; its `chartSource` resolver is NOT — the wrapper
// gates through the real one.
jest.mock( '../../AggregateTimeChart', () => ( {
	...jest.requireActual( '../../AggregateTimeChart' ),
	__esModule: true,
	default: ( { metric, breakdown, serverFilter } ) =>
		`AGGREGATE[metric=${ metric },breakdown=${ breakdown },server=${
			serverFilter || ''
		}]`,
} ) );

import * as React from 'react';
import BreakdownControls from '../BreakdownControls';
import { renderComponent } from '../../../test-helpers/renderHook';

const SERIES = { 1748960000: { count: 3 } };

function mountBreakdown( overrides = {} ) {
	return renderComponent(
		React.createElement( BreakdownControls, {
			series: SERIES,
			breakdownData: null,
			metric: 'memory',
			setMetric: jest.fn(),
			breakdown: 'method',
			setBreakdown: jest.fn(),
			...overrides,
		} )
	);
}

describe( 'BreakdownControls', () => {
	it( 'keeps the panel up when the breakdown reply is empty and the series is not', () => {
		// The wrapper and the chart must not hold different opinions about
		// what there is to draw: gating here on `breakdownData` alone hid a
		// populated series behind a panel that never mounted.
		const { container, unmount } = mountBreakdown( { breakdownData: {} } );
		expect( container.textContent ).toContain( 'AGGREGATE[' );
		unmount();
	} );

	it( 'holds no opinion about whether it should be here', () => {
		// The caller mounts it, so an empty dimension still leaves the
		// dropdowns the URL modal's operator has to pick another one with.
		const { container, unmount } = mountBreakdown( {
			series: {},
			breakdownData: {},
		} );
		const labels = Array.from( container.querySelectorAll( 'label' ) ).map(
			( label ) => label.textContent
		);
		expect( labels ).toEqual( [ 'Metric', 'Breakdown' ] );
		unmount();
	} );

	it( 'prints the refusal it was handed rather than swallowing it', () => {
		const { container, unmount } = mountBreakdown( {
			series: undefined,
			breakdownData: null,
			error: 'index scan budget spent',
		} );
		const shown = container.querySelector(
			'.newspack-nodes-status.is-error'
		);
		expect( shown.textContent ).toBe( 'index scan budget spent' );
		unmount();
	} );

	it( 'drives the chart from the Metric and Breakdown selectors', () => {
		const { container, unmount } = mountBreakdown();
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=memory,breakdown=method,server=]'
		);
		const labels = Array.from( container.querySelectorAll( 'label' ) ).map(
			( label ) => label.textContent
		);
		expect( labels ).toEqual( [ 'Metric', 'Breakdown' ] );
		unmount();
	} );

	it( 'adds the Server selector and its note only when asked', () => {
		const { container, unmount } = mountBreakdown( {
			serverOptions: [
				{ label: 'All Servers', value: '' },
				{ label: 'edge-77', value: 'edge-77' },
			],
			serverFilter: 'edge-77',
			setServerFilter: jest.fn(),
			note: 'Workers are counted above but not charted here.',
		} );
		const labels = Array.from( container.querySelectorAll( 'label' ) ).map(
			( label ) => label.textContent
		);
		expect( labels ).toEqual( [ 'Server', 'Metric', 'Breakdown' ] );
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=memory,breakdown=method,server=edge-77]'
		);
		expect( container.textContent ).toContain(
			'Workers are counted above but not charted here.'
		);
		unmount();
	} );

	it( 'stays up with no series of its own while the read is in flight', () => {
		// The URL modal has no undifferentiated series to fall back on, so the
		// selectors have to survive the wait for the first breakdown reply.
		const { container, unmount } = mountBreakdown( {
			series: undefined,
			loading: true,
		} );
		expect( container.textContent ).toContain( 'Loading…' );
		const labels = Array.from( container.querySelectorAll( 'label' ) ).map(
			( label ) => label.textContent
		);
		expect( labels ).toEqual( [ 'Metric', 'Breakdown' ] );
		unmount();
	} );

	it( 'draws the breakdown series with no series of its own', () => {
		const { container, unmount } = mountBreakdown( {
			series: undefined,
			breakdownData: { 1748960000: { '5xx': { c: 7 } } },
		} );
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=memory,breakdown=method,server=]'
		);
		unmount();
	} );

	it( 'says so while its own breakdown read is in flight', () => {
		const { container, unmount } = mountBreakdown( { loading: true } );
		expect( container.textContent ).toContain( 'Loading…' );
		unmount();
	} );
} );
