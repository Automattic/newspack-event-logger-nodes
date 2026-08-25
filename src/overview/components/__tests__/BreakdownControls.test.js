/**
 * Tests for BreakdownControls — the aggregate chart and its selectors, drawn
 * by both the Overview card and the URL modal.
 *
 * The D3 chart is mocked at the module boundary; what matters here is which
 * selectors mount and what the chart is handed.
 */

// The chart is mocked; its `breakdownState` resolver is NOT — the panel and
// the chart read the same one.
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

function mountBreakdown( overrides = {} ) {
	return renderComponent(
		React.createElement( BreakdownControls, {
			breakdownData: { 1748960000: { '5xx': { c: 7 } } },
			metric: 'memory',
			setMetric: jest.fn(),
			breakdown: 'method',
			setBreakdown: jest.fn(),
			...overrides,
		} )
	);
}

describe( 'BreakdownControls', () => {
	it( 'names the dimension the reply came back empty for', () => {
		// A blank frame under a dropdown reading "User Agent" says nothing;
		// the panel has to say WHICH dimension has no values in the window,
		// and keep the dropdowns that pick another one.
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: {},
		} );
		expect( container.textContent ).toContain(
			'No User Agent data in this window.'
		);
		const labels = Array.from( container.querySelectorAll( 'label' ) ).map(
			( label ) => label.textContent
		);
		expect( labels ).toEqual( [ 'Metric', 'Breakdown' ] );
		unmount();
	} );

	it( 'says the read is still out rather than calling it empty', () => {
		// The dimension's key is absent because the payload predates the
		// switch. "No User Agent data" there is a lie that flickers.
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: null,
		} );
		expect( container.textContent ).toContain( 'Loading…' );
		expect( container.textContent ).not.toContain( 'No User Agent' );
		unmount();
	} );

	it( 'prints the refusal it was handed rather than swallowing it', () => {
		// `useCommandOnce` treats a refusal as an answer, so the read is over
		// and "Loading…" beside the dropdowns would never clear.
		const { container, unmount } = mountBreakdown( {
			breakdownData: null,
			error: 'index scan budget spent',
		} );
		const shown = container.querySelector(
			'.newspack-nodes-status.is-error'
		);
		expect( shown.textContent ).toBe( 'index scan budget spent' );
		expect( container.textContent ).not.toContain( 'Loading…' );
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

	it( 'stays up while the read is in flight', () => {
		// The selectors have to survive the wait for the first reply — they
		// are the only way to ask for a different dimension.
		const { container, unmount } = mountBreakdown( { loading: true } );
		expect( container.textContent ).toContain( 'Loading…' );
		const labels = Array.from( container.querySelectorAll( 'label' ) ).map(
			( label ) => label.textContent
		);
		expect( labels ).toEqual( [ 'Metric', 'Breakdown' ] );
		unmount();
	} );
} );
