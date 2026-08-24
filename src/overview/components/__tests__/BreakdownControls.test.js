/**
 * Tests for BreakdownControls — the aggregate chart and its selectors, drawn
 * by both the Overview card and the URL modal.
 *
 * The D3 chart is mocked at the module boundary; what matters here is which
 * selectors mount and what the chart is handed.
 */

jest.mock( '../../AggregateTimeChart', () => ( {
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
	it( 'renders nothing until the series carries buckets', () => {
		const { container, unmount } = mountBreakdown( { series: {} } );
		expect( container.textContent ).toBe( '' );
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

	it( 'says so while its own breakdown read is in flight', () => {
		const { container, unmount } = mountBreakdown( { loading: true } );
		expect( container.textContent ).toContain( 'Loading…' );
		unmount();
	} );
} );
