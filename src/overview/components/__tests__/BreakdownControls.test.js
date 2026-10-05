/* global globalThis */
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
	default: ( {
		metric,
		breakdown,
		serverFilter,
		slots,
		series,
		onSlotClick,
	} ) => {
		globalThis.__aggregateSlotClick = onSlotClick;
		return `AGGREGATE[metric=${ metric },breakdown=${ breakdown },server=${
			serverFilter || ''
		}] slots:${ slots?.[ 0 ] ?? 'none' } rows:${ series?.rows ?? 'none' }`;
	},
} ) );

import * as React from 'react';
import BreakdownControls from '../BreakdownControls';
import { breakdownState } from '../../AggregateTimeChart';
import * as chartSlots from '../../chartSlots';
import { CHART_BREAKDOWN_OPTIONS } from '../../constants';
import { renderComponent } from '../../../test-helpers/renderHook';
import { nameTable, slotsEndingAt } from '../../../test-helpers/chartWire';

/**
 * Mount the panel on the read its caller would take of `breakdownData`.
 *
 * @param {Object}      [props]               The panel's props, but for the read.
 * @param {Object|null} [props.breakdownData] The raw reply the caller reads.
 * @return {Object} The mounted panel.
 */
function mountBreakdown( {
	breakdownData = nameTable( {
		'2026-09-29-14-35': { '5xx': [ 7, 70, 3, 7 ] },
	} ),
	...overrides
} = {} ) {
	return renderComponent(
		React.createElement( BreakdownControls, {
			breakdownRead: breakdownState( breakdownData ),
			metric: 'memory',
			setMetric: jest.fn(),
			breakdown: 'method',
			setBreakdown: jest.fn(),
			breakdownOptions: CHART_BREAKDOWN_OPTIONS,
			...overrides,
		} )
	);
}

describe( 'BreakdownControls', () => {
	it( 'hands the chart the slots its reply named', () => {
		const { container, unmount } = mountBreakdown( {
			slots: slotsEndingAt( '2026-09-29-14-35' ),
		} );
		expect( container.textContent ).toContain( 'slots:2026-09-29-14-35' );
		unmount();
	} );

	it( 'draws from the read it is handed, decoding nothing itself', () => {
		const read = breakdownState(
			nameTable( {
				'2026-09-29-14-35': {
					'kea-ua/7': [ 41, 820, 3, 41 ],
					'weka-ua/9': [ 43, 4300, 4, 43 ],
					'tui-ua/2': [ 37, 370, 2, 37 ],
				},
			} )
		);
		const decode = jest.spyOn( chartSlots, 'decodeNameTable' );
		const { container, unmount } = renderComponent(
			React.createElement( BreakdownControls, {
				breakdownRead: read,
				metric: 'volume',
				setMetric: jest.fn(),
				breakdown: 'ua',
				setBreakdown: jest.fn(),
				breakdownOptions: CHART_BREAKDOWN_OPTIONS,
			} )
		);
		expect( container.textContent ).toContain( 'rows:3' );
		expect( decode ).not.toHaveBeenCalled();
		decode.mockRestore();
		unmount();
	} );

	it( 'hands the chart the table it decoded, so the reply decodes once', () => {
		const { container, unmount } = mountBreakdown( {
			breakdownData: nameTable( {
				'2026-09-29-14-35': {
					'kea-ua/7': [ 41, 820, 3, 41 ],
					'weka-ua/9': [ 43, 4300, 4, 43 ],
				},
			} ),
		} );
		expect( container.textContent ).toContain( 'rows:2' );
		unmount();
	} );

	it( 'says the dimension is empty when its reply is malformed', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: {
				names: [ 'kea-ua/7' ],
				buckets: { '2026-09-29-14-35': [ [ 3, 41, 820, 3 ] ] },
			},
		} );
		expect( container.textContent ).toContain(
			'No User Agent data in this window.'
		);
		unmount();
	} );

	it( 'names the dimension the reply came back empty for', () => {
		// A blank frame under a dropdown reading "User Agent" says nothing;
		// the panel has to say WHICH dimension has no values in the window,
		// and keep the dropdowns that pick another one.
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: { names: [], buckets: {} },
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

	it( 'offers exactly the dimensions its caller hands it', () => {
		// Each scope decides what still splits inside it; the panel carries
		// no list of its own to fall back on.
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ja4',
			breakdownOptions: [
				{ label: 'Country', value: 'country' },
				{ label: 'JA4 Hash', value: 'ja4' },
			],
		} );
		const [ , breakdown ] = container.querySelectorAll( 'select' );
		expect(
			Array.from( breakdown.options ).map( ( o ) => o.value )
		).toEqual( [ 'country', 'ja4' ] );
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

	it( 'hands the chart the click that narrows to a bucket', () => {
		const onSlotClick = jest.fn();
		const { unmount } = mountBreakdown( { onSlotClick } );
		expect( globalThis.__aggregateSlotClick ).toBe( onSlotClick );
		unmount();
	} );
} );
