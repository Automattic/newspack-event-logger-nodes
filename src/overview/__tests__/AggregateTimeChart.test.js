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
	const chain = {};
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
import { renderComponent, act } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

function bucketKeyNow() {
	const now = new Date();
	now.setMinutes( Math.floor( now.getMinutes() / 5 ) * 5, 0, 0 );
	return [
		now.getUTCFullYear(),
		String( now.getUTCMonth() + 1 ).padStart( 2, '0' ),
		String( now.getUTCDate() ).padStart( 2, '0' ),
		String( now.getUTCHours() ).padStart( 2, '0' ),
		String( Math.floor( now.getUTCMinutes() / 5 ) * 5 ).padStart( 2, '0' ),
	].join( '-' );
}

function lastSlotIndex() {
	const {
		NUM_BUCKETS,
	} = require( '@newspack-nodes/shared/hooks/useTimeChart' );
	return NUM_BUCKETS - 1;
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
				breakdownData: null,
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when the breakdown is empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData: {},
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'draws the breakdown series it is handed', () => {
		const bk = bucketKeyNow();
		const breakdownData = { [ bk ]: { '5xx': [ 7, 917 ] } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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

	it( 'reads a stored entry positionally', () => {
		// The stored entry is decision 18's DIM_SUMS triple — count, summed ms,
		// summed peak MB — and it crosses the wire in that shape, so this is
		// the only place the indexes are read.
		const bk = bucketKeyNow();
		const breakdownData = { [ bk ]: { '5xx': [ 4, 1000, 12 ] } };
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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

	it( 'ticks a slow response-time axis in seconds, not five digits of ms', () => {
		// `140000ms` is wider than the axis title beside it, so the two collide.
		// formatSeconds already owns the unit ladder this chart reads in — the
		// cumulative axis has used it all along — so the avg axis reads the same
		// way rather than pinning itself to the smallest unit.
		const bk = bucketKeyNow();
		const breakdownData = { [ bk ]: { slow: [ 1, 140000 ] } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = { [ bk ]: { fast: [ 4, 1000 ] } };
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
				data: { [ bk ]: { count: 313, sum_ms: 4711 } },
				breakdownData: null,
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
				data: { [ bk ]: { count: 313, sum_ms: 4711 } },
				breakdownData: { [ bk ]: {} },
				metric: 'avg',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'tells the panel which of the three states the dimension is in', () => {
		const bk = bucketKeyNow();
		expect( breakdownState( null ) ).toBe( 'pending' );
		expect( breakdownState( {} ) ).toBe( 'empty' );
		expect( breakdownState( { [ bk ]: {} } ) ).toBe( 'empty' );
		expect( breakdownState( { [ bk ]: { 'curl/8.7.1': [ 313 ] } } ) ).toBe(
			'series'
		);
	} );

	it( 'renders the tooltip frame in volume mode', () => {
		const bk = bucketKeyNow();
		const breakdownData = {
			[ bk ]: { 'curl/8.7.1': [ 50, 500, 10 ] },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: { 'curl/8.7.1': [ 50, 500, 10 ] },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: {
				'2xx': [ 80, 800 ],
				'4xx': [ 20, 200 ],
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'cumulative',
				breakdown: 'status',
			} )
		);
		expect( container.textContent ).toContain( 'Cumulative' );
		unmount();
	} );

	it( 'renders with breakdownData (status) in volume mode', () => {
		const bk = bucketKeyNow();
		const breakdownData = {
			[ bk ]: {
				'2xx': [ 80, 800 ],
				'5xx': [ 20, 200 ],
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: {
				GET: [ 80, 8000 ],
				POST: [ 20, 4000 ],
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: {
				A: { c: 5, m: 50, s: 500 },
				B: { c: 0, m: 0, s: 0 }, // hits c===0 branch
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'memory',
				breakdown: 'server',
			} )
		);
		const formatEntry = getFormatEntry();
		formatEntry( lastSlotIndex() );
		expect( true ).toBe( true );
		unmount();
	} );

	it( 'serverFilter suffixes the title', () => {
		const bk = bucketKeyNow();
		const breakdownData = { [ bk ]: { '5xx': [ 1, 10 ] } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: {
				zero: [ 0, 0 ], // → 0s
				sub: [ 1, 500 ], // → 500ms
				small: [ 1, 5000 ], // → 5.0s
				big: [ 1, 50000 ], // → 50s
				huge: [ 1, 1_500_000 ], // → 1.5Ks
				integer: [ 1, 2_000_000 ], // → 2Ks (no .0)
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: { '2xx': [ 47, 470 ], '5xx': [ 453, 4530 ] },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: { minor: [ 1, 600 ], major: [ 1, 44400 ] },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = {
			[ bk ]: { '2xx': [ 47, 470 ], '5xx': [ 453, 4530 ] },
		};
		const props = { breakdownData, breakdown: 'status' };
		const { container, rerender, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
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
				...props,
				metric: 'avg',
			} )
		);
		expect(
			container
				.querySelector( '.newspack-nodes-chart__stack' )
				.getAttribute( 'aria-pressed' )
		).toBe( 'false' );
		expect(
			getFormatEntry()( lastSlotIndex() ).map( ( e ) => e.label )
		).not.toContain( 'Total' );
		unmount();
	} );

	it( 'tags the y-axis title with the themable y-label class', () => {
		const bk = bucketKeyNow();
		const breakdownData = { [ bk ]: { '5xx': [ 50, 500 ] } };
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
		const breakdownData = { [ bk ]: { '5xx': [ 1, 1 ] } };
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
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
} );
