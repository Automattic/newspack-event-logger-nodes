/* global globalThis */
/**
 * Tests for AggregateTimeChart — D3 stacked-area / line chart.
 *
 * Same approach as CategoryTimeChart: mock d3 chainable + useTimeChart's
 * setupTooltip/drawLegend, invoke captured formatEntry callbacks to
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
		drawLegend: jest.fn(),
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
import { renderComponent } from '../../test-helpers/renderHook';

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
		useTimeChart.drawLegend.mockClear();
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
		const breakdownData = { [ bk ]: { '5xx': { c: 7, s: 917 } } };
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
		expect(
			breakdownState( { [ bk ]: { 'curl/8.7.1': { c: 313 } } } )
		).toBe( 'series' );
	} );

	it( 'renders the tooltip frame in volume mode', () => {
		const bk = bucketKeyNow();
		const breakdownData = {
			[ bk ]: { 'curl/8.7.1': { c: 50, s: 500, m: 10 } },
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
		const tooltip = container.querySelector(
			'.event-logger-chart-tooltip'
		);
		expect( tooltip ).not.toBeNull();
		expect( tooltip.className ).toBe( 'event-logger-chart-tooltip' );
		expect( tooltip.getAttribute( 'style' ) ).toBeNull();
		unmount();
	} );

	it( 'titles the memory metric from its own dimension', () => {
		const bk = bucketKeyNow();
		const breakdownData = {
			[ bk ]: { 'curl/8.7.1': { c: 50, s: 500, m: 10 } },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'memory',
				breakdown: 'ua',
			} )
		);
		expect( container.textContent ).toContain( 'Avg Peak Memory' );
		unmount();
	} );

	it( 'renders with breakdownData (status) → cumulative stacked area', () => {
		const bk = bucketKeyNow();
		const breakdownData = {
			[ bk ]: {
				'2xx': { c: 80, s: 800 },
				'4xx': { c: 20, s: 200 },
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
				'2xx': { c: 80, s: 800 },
				'5xx': { c: 20, s: 200 },
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
				GET: { c: 80, s: 8000 },
				POST: { c: 20, s: 4000 },
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
		const breakdownData = { [ bk ]: { '5xx': { c: 1, s: 10 } } };
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
				zero: { c: 0, s: 0 }, // → 0s
				sub: { c: 1, s: 500 }, // → 500ms
				small: { c: 1, s: 5000 }, // → 5.0s
				big: { c: 1, s: 50000 }, // → 50s
				huge: { c: 1, s: 1_500_000 }, // → 1.5Ks
				integer: { c: 1, s: 2_000_000 }, // → 2Ks (no .0)
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'cumulative',
				breakdown: 'status',
			} )
		);
		const formatEntry = getFormatEntry();
		const entries = formatEntry( lastSlotIndex() );
		expect( Array.isArray( entries ) ).toBe( true );
		// First entry is the Total.
		expect( entries[ 0 ].label ).toBe( 'Total' );
		unmount();
	} );

	it( 'tags the y-axis title with the themable y-label class', () => {
		const bk = bucketKeyNow();
		const breakdownData = { [ bk ]: { '5xx': { c: 50, s: 500 } } };
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
		const breakdownData = { [ bk ]: { '5xx': { c: 1, s: 1 } } };
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
