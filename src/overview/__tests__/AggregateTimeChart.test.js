/* global globalThis */
/**
 * Tests for AggregateTimeChart — overlaid areas, stacked on the toggle.
 *
 * Same approach as CategoryTimeChart: mock d3 chainable + useTimeChart's
 * setupTooltip, invoke captured formatEntry callbacks to
 * drive the formatSeconds + the per-metric value-computation branches.
 *
 * A dimension is always selected, so every case here is dimensional; the
 * chart holds no undifferentiated totals to fall back on.
 */

// Mock d3 — every call returns a shared chainable.
jest.mock( 'd3', () => {
	// Callable, so a scale the chart invokes answers a pixel.
	const chain = () => 0;
	const fnNames = [
		'select',
		'selectAll',
		'append',
		'attr',
		'style',
		'text',
		'datum',
		'data',
		'enter',
		'remove',
		'call',
		'on',
		'ticks',
		// A scale's ticks() is an array in d3; here it is the chain, so the
		// tick ladder's filter has to chain too.
		'filter',
		'tickFormat',
		'tickValues',
		'domain',
		'range',
		'x',
		'y',
		'y0',
		'y1',
		'defined',
		'curve',
		'keys',
	];
	fnNames.forEach( ( fn ) => {
		chain[ fn ] = jest.fn( () => chain );
	} );
	chain.node = jest.fn( () => null );
	const handler = {
		get: ( _t, prop ) => {
			if ( prop === '__esModule' ) {
				return true;
			}
			if ( prop === '__chain' ) {
				return chain;
			}
			if ( prop === 'stack' ) {
				// d3.stack().keys(k) returns a callable; return empty layers.
				return jest.fn( () => {
					const stacker = jest.fn( () => [] );
					stacker.keys = jest.fn( () => stacker );
					return stacker;
				} );
			}
			if ( prop === 'format' ) {
				// d3.format('d') returns an identity formatter.
				return jest.fn( () => ( v ) => String( v ) );
			}
			if ( chain[ prop ] !== undefined ) {
				return chain[ prop ];
			}
			const f = jest.fn( () => chain );
			chain[ prop ] = f;
			return f;
		},
	};
	return new Proxy( {}, handler );
} );

jest.mock( '@newspack-nodes/shared/hooks/useTimeChart', () => {
	const actual = jest.requireActual(
		'@newspack-nodes/shared/hooks/useTimeChart'
	);
	return {
		__esModule: true,
		...actual,
		setupTooltip: jest.fn(),
		useTimeChart: ( renderFn ) => {
			globalThis.__lastRenderFn = renderFn;
			const containerRef = {
				current: {
					clientWidth: 800,
					parentElement: { scrollLeft: 0, clientHeight: 200 },
				},
			};
			const tooltipRef = { current: { style: {} } };
			const lastMouseXRef = { current: null };
			renderFn( { containerRef, tooltipRef, lastMouseXRef } );
			return { containerRef, tooltipRef };
		},
	};
} );

import * as React from 'react';
import * as d3 from 'd3';
import AggregateTimeChart, { breakdownState } from '../AggregateTimeChart';
import {
	dimTable,
	nameTable,
	slotsEndingAt,
} from '../../test-helpers/chartWire';
import { renderComponent, act } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

/**
 * The slots every reply here names: 288 ending at 14:35.
 */
const SLOTS = slotsEndingAt( '2026-09-29-14-35' );

function bucketKeyNow() {
	return SLOTS[ 0 ];
}

function lastSlotIndex() {
	return SLOTS.length - 1;
}

/**
 * Stack a mounted chart through its corner toggle.
 *
 * @param {Element} container The rendered chart.
 */
function stack( container ) {
	act( () => {
		container.querySelector( '.newspack-nodes-chart__stack' ).click();
	} );
}

function getFormatEntry() {
	const {
		setupTooltip,
	} = require( '@newspack-nodes/shared/hooks/useTimeChart' );
	const calls = setupTooltip.mock.calls;
	return calls[ calls.length - 1 ][ 1 ].formatEntry;
}

describe( 'AggregateTimeChart', () => {
	beforeEach( () => {
		globalThis.__lastRenderFn = null;
		Object.values( d3Mock ).forEach( ( v ) => {
			if ( v && typeof v.mockClear === 'function' ) {
				v.mockClear();
			}
		} );
		const useTimeChart = require( '@newspack-nodes/shared/hooks/useTimeChart' );
		useTimeChart.setupTooltip.mockClear();
	} );

	it( 'returns null when the breakdown is null', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series: null,
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when the breakdown is empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series: dimTable( {} ),
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'draws nothing before a reply names the window it read', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: null,
				series: dimTable( {
					[ bucketKeyNow() ]: { GET: [ 37, 740, 3, 37 ] },
				} ),
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'draws the breakdown series it is handed', () => {
		const bk = bucketKeyNow();
		const series = dimTable( { [ bk ]: { '5xx': [ 7, 917, 3, 7 ] } } );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'avg',
				breakdown: 'status',
			} )
		);
		expect( container.textContent ).toContain( 'Avg Response Time' );
		const labels = getFormatEntry()( lastSlotIndex() ).map(
			( entry ) => entry.label
		);
		expect( labels ).toEqual( [ '5xx' ] );
		unmount();
	} );

	it( 'titles the chart the last 24 hours', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series: dimTable( {
					[ bucketKeyNow() ]: { 'kea-ua/7': [ 41, 820, 3, 41 ] },
				} ),
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain(
			'Request Volume (Last 24 Hours)'
		);
		unmount();
	} );

	it( 'plots the oldest slot and the newest at their own five minutes', () => {
		const series = dimTable( {
			'2026-09-28-14-40': { 'kea-ua/7': [ 43, 4300, 4, 43 ] },
			[ bucketKeyNow() ]: { 'kea-ua/7': [ 41, 820, 3, 41 ] },
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		const valueAt = ( index ) =>
			getFormatEntry()( index ).find(
				( entry ) => 'kea-ua/7' === entry.label
			)?.value;
		expect( valueAt( 0 ) ).toBe( '43' );
		expect( valueAt( lastSlotIndex() ) ).toBe( '41' );
		unmount();
	} );

	it( 'titles a one-hour axis in the singular', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: slotsEndingAt( '2026-09-29-14-35', 12 ),
				series: dimTable( {
					[ bucketKeyNow() ]: { 'kea-ua/7': [ 41, 820, 3, 41 ] },
				} ),
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain(
			'Request Volume (Last 1 Hour)'
		);
		unmount();
	} );

	it( 'titles a fractional axis in whole hours, rounded', () => {
		// Twenty slots are 100 minutes: two hours, never "1 Hours".
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: slotsEndingAt( '2026-09-29-14-35', 20 ),
				series: dimTable( {
					[ bucketKeyNow() ]: { 'kea-ua/7': [ 41, 820, 3, 41 ] },
				} ),
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain(
			'Request Volume (Last 2 Hours)'
		);
		unmount();
	} );

	it( 'titles the hours its slots span', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: slotsEndingAt( '2026-09-29-14-35', 144 ),
				series: dimTable( {
					[ bucketKeyNow() ]: { 'kea-ua/7': [ 41, 820, 3, 41 ] },
				} ),
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain(
			'Request Volume (Last 12 Hours)'
		);
		unmount();
	} );

	it( 'reads a malformed reply as the empty state and draws nothing', () => {
		const bk = bucketKeyNow();
		// A row one field short: a reply the decoder refuses whole.
		const malformed = {
			names: [ 'kea-ua/7' ],
			buckets: { [ bk ]: [ [ 0, 41, 820, 3 ] ] },
		};
		const { state, series } = breakdownState( malformed );
		expect( state ).toBe( 'empty' );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'reads the name-table wire for its state', () => {
		const bk = bucketKeyNow();
		const stateOf = ( wire ) => breakdownState( wire ).state;
		expect( stateOf( null ) ).toBe( 'pending' );
		expect( stateOf( { names: [], buckets: {} } ) ).toBe( 'empty' );
		expect( stateOf( { names: [], buckets: { [ bk ]: [] } } ) ).toBe(
			'empty'
		);
		const wire = nameTable( {
			[ bk ]: { 'curl/8.7.1': [ 313, 3130, 3, 313 ] },
		} );
		expect( breakdownState( wire ) ).toEqual( {
			state: 'series',
			series: dimTable( {
				[ bk ]: { 'curl/8.7.1': [ 313, 3130, 3, 313 ] },
			} ),
		} );
	} );

	it( 'hands down no table while the dimension is pending', () => {
		expect( breakdownState( null ).series ).toBeNull();
	} );

	it( 'titles a summed axis with the five minutes each point stands for', () => {
		const series = dimTable( {
			[ bucketKeyNow() ]: { GET: [ 37, 740, 3, 37 ] },
		} );
		for ( const [ metric, title ] of [
			[ 'volume', 'Requests per 5 min' ],
			[ 'cumulative', 'Time per 5 min' ],
		] ) {
			const { unmount } = renderComponent(
				React.createElement( AggregateTimeChart, {
					slots: SLOTS,
					series,
					metric,
					breakdown: 'method',
				} )
			);
			expect( d3Mock.text ).toHaveBeenCalledWith( title );
			unmount();
		}
	} );

	it( 'reads a stored entry positionally', () => {
		// The stored entry is decision 18's DIM_SUMS row — count, summed ms,
		// summed peak MB — and it crosses the wire in that shape, so this is
		// the only place the indexes are read.
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { '5xx': [ 4, 1000, 12, 4 ] },
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'avg',
				breakdown: 'status',
			} )
		);
		const values = getFormatEntry()( lastSlotIndex() ).map(
			( entry ) => entry.value
		);
		expect( values ).toContain( '250ms' );
		unmount();
	} );

	it( 'averages over the timed requests alone', () => {
		// Three requests, two timed at 40 and 60ms: the timeout is no sample.
		const series = dimTable( {
			[ bucketKeyNow() ]: { 'kea-ua/7': [ 3, 100, 3, 2 ] },
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'avg',
				breakdown: 'ua',
			} )
		);
		const values = getFormatEntry()( lastSlotIndex() ).map(
			( entry ) => entry.value
		);
		expect( values ).toContain( '50ms' );
		unmount();
	} );

	it( 'ticks a slow response-time axis in seconds, not five digits of ms', () => {
		// `140000ms` is wider than the axis title beside it, so the two collide.
		// formatSeconds already owns the unit ladder this chart reads in — the
		// cumulative axis has used it all along — so the avg axis reads the same
		// way rather than pinning itself to the smallest unit.
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { slow: [ 1, 140000, 3, 1 ] },
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'avg',
				breakdown: 'status',
			} )
		);

		const values = getFormatEntry()( lastSlotIndex() ).map(
			( entry ) => entry.value
		);
		expect( values ).toContain( '140s' );
		// The ticks carry the unit, so the title must not also claim one — and
		// must not claim the WRONG one once the ticks have rescaled.
		expect( container.textContent ).not.toContain( '(ms)' );
		unmount();
	} );

	it( 'still reads a fast response-time axis in milliseconds', () => {
		const bk = bucketKeyNow();
		const series = dimTable( { [ bk ]: { fast: [ 4, 1000, 3, 4 ] } } );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'avg',
				breakdown: 'status',
			} )
		);

		const values = getFormatEntry()( lastSlotIndex() ).map(
			( entry ) => entry.value
		);
		expect( values ).toContain( '250ms' );
		unmount();
	} );

	it( 'draws nothing while the selected dimension has not arrived', () => {
		// The payload still in state predates the dropdown switch, so the new
		// dimension's key is absent. A totals series legended "Total" under a
		// dropdown reading "User Agent" answers a question nobody asked, so
		// the totals are handed over here and must still draw nothing.
		const bk = bucketKeyNow();
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				data: { [ bk ]: { count: 313, sum_ms: 4711 } },
				series: null,
				metric: 'avg',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'draws nothing when the dimension arrived carrying no values', () => {
		// Fetched and genuinely empty. Still not the totals: the panel says
		// which dimension came back empty, and the chart draws no series.
		const bk = bucketKeyNow();
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				data: { [ bk ]: { count: 313, sum_ms: 4711 } },
				series: dimTable( { [ bk ]: {} } ),
				metric: 'avg',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'renders the tooltip frame in volume mode', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { 'curl/8.7.1': [ 50, 500, 10, 50 ] },
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain( 'Request Volume' );
		expect( d3Mock.select ).toHaveBeenCalled();
		// The tooltip is the substrate's elevated card, painted by its role.
		const tooltip = container.querySelector(
			'.newspack-nodes-chart__tooltip'
		);
		expect( tooltip ).not.toBeNull();
		expect(
			tooltip.classList.contains( 'newspack-nodes-card--elevated' )
		).toBe( true );
		expect( tooltip.getAttribute( 'style' ) ).toBeNull();
		unmount();
	} );

	it( 'titles the memory metric from its own dimension', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { 'curl/8.7.1': [ 50, 500, 10, 50 ] },
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'memory',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain( 'Avg Peak Memory' );
		// The ticks already print MB; the axis title names the quantity alone.
		expect( d3Mock.text ).toHaveBeenCalledWith( 'Avg Peak Memory' );
		unmount();
	} );

	it( 'renders with breakdownData (status) → cumulative overlaid area', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: {
				'2xx': [ 80, 800, 3, 80 ],
				'4xx': [ 20, 200, 3, 20 ],
			},
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'cumulative',
				breakdown: 'status',
			} )
		);
		expect( container.textContent ).toContain( 'Cumulative' );
		unmount();
	} );

	it( 'renders with breakdownData (status) in volume mode', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: {
				'2xx': [ 80, 800, 3, 80 ],
				'5xx': [ 20, 200, 3, 20 ],
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
				breakdown: 'status',
			} )
		);
		const formatEntry = getFormatEntry();
		// Drive the formatEntry to invoke saFmt and total computation.
		const entries = formatEntry( lastSlotIndex() );
		expect( Array.isArray( entries ) ).toBe( true );
		unmount();
	} );

	it( 'renders with breakdownData (method) line chart in avg mode', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: {
				GET: [ 80, 8000, 3, 80 ],
				POST: [ 20, 4000, 3, 20 ],
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'avg',
				breakdown: 'method',
			} )
		);
		const formatEntry = getFormatEntry();
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'renders memory line chart with breakdownData', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: {
				A: [ 5, 500, 50, 5 ],
				B: [ 0, 0, 0, 0 ], // hits the zero-count branch
			},
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'memory',
				breakdown: 'server',
			} )
		);
		const values = getFormatEntry()( lastSlotIndex() ).map(
			( entry ) => entry.value
		);
		// B's zero-count slot averages to 0, which the tooltip leaves out.
		expect( values ).toEqual( [ '10MB' ] );
		unmount();
	} );

	it( 'serverFilter suffixes the title', () => {
		const bk = bucketKeyNow();
		const series = dimTable( { [ bk ]: { '5xx': [ 1, 10, 3, 1 ] } } );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
				serverFilter: 'edge-01',
			} )
		);
		expect( container.textContent ).toContain( 'edge-01' );
		unmount();
	} );

	it( 'formatSeconds covers all five magnitude branches', () => {
		// cumulative mode drives every formatSeconds branch via saFmt.
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: {
				zero: [ 0, 0, 3, 0 ], // → 0s
				sub: [ 1, 500, 3, 1 ], // → 500ms
				small: [ 1, 5000, 3, 1 ], // → 5.0s
				big: [ 1, 50000, 3, 1 ], // → 50s
				huge: [ 1, 1_500_000, 3, 1 ], // → 1.5Ks
				integer: [ 1, 2_000_000, 3, 1 ], // → 2Ks (no .0)
			},
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'cumulative',
				breakdown: 'status',
			} )
		);
		// Overlaid by default: no total row until the reader stacks.
		expect(
			getFormatEntry()( lastSlotIndex() ).map( ( e ) => e.label )
		).not.toContain( 'Total' );
		stack( container );
		const entries = getFormatEntry()( lastSlotIndex() );
		expect( Array.isArray( entries ) ).toBe( true );
		// First entry is the Total.
		expect( entries[ 0 ].label ).toBe( 'Total' );
		unmount();
	} );

	it( "the tooltip total stays the bucket's whole total under a pick", () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { '2xx': [ 47, 470, 3, 47 ], '5xx': [ 453, 4530, 3, 453 ] },
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
				breakdown: 'status',
			} )
		);
		stack( container );
		act( () => {
			container
				.querySelectorAll( '.newspack-nodes-chart-legend button' )[ 0 ]
				.click();
		} );
		const entries = getFormatEntry()( lastSlotIndex() );
		expect( entries[ 0 ].label ).toBe( 'Total' );
		// 2xx alone is drawn; the bucket still held 500 requests.
		expect( entries[ 0 ].value ).toBe( '500' );
		expect( entries.slice( 1 ).map( ( e ) => e.label ) ).toEqual( [
			'2xx',
		] );
		unmount();
	} );

	it( 'the tooltip total keeps its own unit under a pick that changed the axis unit', () => {
		// A 45s bucket total, a picked series at 600ms: the axis reads ms,
		// the total is still 45s, not 45000ms.
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { minor: [ 1, 600, 3, 1 ], major: [ 1, 44400, 3, 1 ] },
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'cumulative',
				breakdown: 'ua',
			} )
		);
		stack( container );
		act( () => {
			container
				.querySelectorAll( '.newspack-nodes-chart-legend button' )[ 0 ]
				.click();
		} );
		const entries = getFormatEntry()( lastSlotIndex() );
		expect( entries[ 0 ].label ).toBe( 'Total' );
		expect( entries[ 0 ].value ).toBe( '45s' );
		expect( entries[ 1 ] ).toEqual(
			expect.objectContaining( { label: 'minor', value: '600ms' } )
		);
		unmount();
	} );

	it( 'a stack picked under one metric does not carry into the next', () => {
		// A stack of request counts is a total; a stack of means is not.
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { '2xx': [ 47, 470, 3, 47 ], '5xx': [ 453, 4530, 3, 453 ] },
		} );
		const props = { series, breakdown: 'status' };
		const { container, rerender, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				...props,
				metric: 'volume',
			} )
		);
		stack( container );
		expect( getFormatEntry()( lastSlotIndex() )[ 0 ].label ).toBe(
			'Total'
		);
		rerender(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				...props,
				metric: 'avg',
			} )
		);
		expect(
			container.querySelector( '.newspack-nodes-chart__stack' )
		).toBeNull();
		expect(
			getFormatEntry()( lastSlotIndex() ).map( ( e ) => e.label )
		).not.toContain( 'Total' );
		unmount();
	} );

	it.each( [
		[ 'volume', true ],
		[ 'cumulative', true ],
		[ 'avg', false ],
		[ 'memory', false ],
	] )( 'offers the stack toggle under %s: %s', ( metric, offered ) => {
		// A stack of counts or sums is a total; a stack of means is not.
		const series = dimTable( {
			[ bucketKeyNow() ]: { '2xx': [ 47, 470, 3, 47 ] },
		} );
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric,
				breakdown: 'status',
			} )
		);
		expect(
			null !== container.querySelector( '.newspack-nodes-chart__stack' )
		).toBe( offered );
		unmount();
	} );

	it( 'tags the y-axis title with the themable y-label class', () => {
		const bk = bucketKeyNow();
		const series = dimTable( {
			[ bk ]: { '5xx': [ 50, 500, 3, 50 ] },
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
			} )
		);
		expect( d3Mock.attr.mock.calls ).toContainEqual( [
			'class',
			'y-label',
		] );
		unmount();
	} );

	it( 'renderFn no-ops on null container', () => {
		const bk = bucketKeyNow();
		const series = dimTable( { [ bk ]: { '5xx': [ 1, 1, 3, 1 ] } } );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'volume',
			} )
		);
		expect( () =>
			globalThis.__lastRenderFn( {
				containerRef: { current: null },
				tooltipRef: { current: null },
				lastMouseXRef: { current: null },
			} )
		).not.toThrow();
		unmount();
	} );

	it.each( [
		// Metric, then whether slot 41 (all timed out) and slot 43 (no
		// requests) are measured: a mean over none is a gap, a count is a 0.
		[ 'avg', false, false ],
		[ 'memory', true, false ],
		[ 'volume', true, true ],
		[ 'cumulative', true, true ],
	] )(
		'plots %s with slot 41 measured: %s, slot 43 measured: %s',
		( metric, timedOutMeasured, emptyMeasured ) => {
			const at = ( index ) => SLOTS[ SLOTS.length - 1 - index ];
			const series = dimTable( {
				[ at( 40 ) ]: { 'edge-kea': [ 6, 900, 24, 3 ] },
				[ at( 41 ) ]: { 'edge-kea': [ 7, 0, 0, 0 ] },
				[ at( 42 ) ]: { 'edge-kea': [ 2, 500, 10, 2 ] },
			} );
			const { unmount } = renderComponent(
				React.createElement( AggregateTimeChart, {
					slots: SLOTS,
					series,
					metric,
					breakdown: 'server',
				} )
			);
			const [ band ] = d3Mock.datum.mock.calls.map(
				( call ) => call[ 0 ]
			);

			expect( band[ 40 ].defined ).toBe( true );
			expect( band[ 42 ].defined ).toBe( true );
			expect( band[ 41 ].defined ).toBe( timedOutMeasured );
			expect( band[ 43 ].defined ).toBe( emptyMeasured );
			unmount();
		}
	);

	it( 'draws a memory slot with no requests as a gap, never as 0 MB', () => {
		const at = ( index ) => SLOTS[ SLOTS.length - 1 - index ];
		const series = dimTable( {
			[ at( 38 ) ]: { '2xx': [ 2, 500, 96, 2 ] },
			[ at( 40 ) ]: {
				'5xx': [ 3, 900, 231, 3 ],
				'2xx': [ 2, 500, 96, 2 ],
			},
			[ at( 52 ) ]: { '5xx': [ 1, 300, 77, 1 ] },
		} );
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				slots: SLOTS,
				series,
				metric: 'memory',
				breakdown: 'status',
			} )
		);
		const bands = d3Mock.datum.mock.calls.map( ( call ) => call[ 0 ] );
		expect( bands ).toHaveLength( 2 );
		const [ ok, err ] = bands;
		expect( err ).toHaveLength( 288 );
		expect( err[ 40 ].y1 ).toBe( 77 );
		expect( err[ 52 ].y1 ).toBe( 77 );
		expect( [ err[ 40 ].defined, err[ 52 ].defined ] ).toEqual( [
			true,
			true,
		] );
		for ( let index = 41; index <= 51; index++ ) {
			expect( err[ index ].defined ).toBe( false );
			expect( ok[ index ].defined ).toBe( false );
		}
		unmount();
	} );
} );
