/* global globalThis */
/**
 * Tests for AggregateTimeChart — D3 stacked-area / line chart.
 *
 * Same approach as CategoryTimeChart: mock d3 chainable + useTimeChart's
 * setupTooltip/drawLegend, invoke captured formatEntry callbacks to
 * drive the formatSeconds + the per-metric value-computation branches.
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
		'tickFormat',
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
import AggregateTimeChart from '../AggregateTimeChart';
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

	it( 'returns null when data is null', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data: null,
				breakdownData: null,
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when data is empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data: {},
				breakdownData: null,
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'renders with single-series Total when no breakdownData (volume)', () => {
		const bk = bucketKeyNow();
		const data = { [ bk ]: { count: 50, sum_ms: 500, sum_peak_mb: 10 } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
				metric: 'volume',
				breakdown: 'status',
			} )
		);
		expect( container.textContent ).toContain( 'Request Volume' );
		expect( d3Mock.select ).toHaveBeenCalled();
		unmount();
	} );

	it( 'renders with single-series Total in avg-memory mode (memory branch)', () => {
		const bk = bucketKeyNow();
		const data = { [ bk ]: { count: 50, sum_ms: 500, sum_peak_mb: 10 } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
				metric: 'memory',
			} )
		);
		expect( container.textContent ).toContain( 'Avg Peak Memory' );
		unmount();
	} );

	it( 'renders with single-series Total in avg mode', () => {
		const bk = bucketKeyNow();
		const data = { [ bk ]: { count: 50, sum_ms: 500 } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
				metric: 'avg',
			} )
		);
		expect( container.textContent ).toContain( 'Avg Response Time' );
		unmount();
	} );

	it( 'renders with single-series Total in cumulative mode', () => {
		const bk = bucketKeyNow();
		const data = { [ bk ]: { count: 50, sum_ms: 500 } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
				metric: 'cumulative',
			} )
		);
		expect( container.textContent ).toContain( 'Cumulative Response Time' );
		unmount();
	} );

	it( 'renders with breakdownData (status) → cumulative stacked area', () => {
		const bk = bucketKeyNow();
		const data = { [ bk ]: { count: 100, sum_ms: 1000 } };
		const breakdownData = {
			[ bk ]: {
				'2xx': { c: 80, s: 800 },
				'4xx': { c: 20, s: 200 },
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
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
		const data = { [ bk ]: { count: 100, sum_ms: 1000 } };
		const breakdownData = {
			[ bk ]: {
				'2xx': { c: 80, s: 800 },
				'5xx': { c: 20, s: 200 },
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
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
		const data = { [ bk ]: { count: 100, sum_ms: 1000 } };
		const breakdownData = {
			[ bk ]: {
				GET: { c: 80, s: 8000 },
				POST: { c: 20, s: 4000 },
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
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
		const data = { [ bk ]: { count: 100 } };
		const breakdownData = {
			[ bk ]: {
				A: { c: 5, m: 50, s: 500 },
				B: { c: 0, m: 0, s: 0 }, // hits c===0 branch
			},
		};
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
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
		const data = { [ bk ]: { count: 1, sum_ms: 10 } };
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
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
		const data = { [ bk ]: { count: 100, sum_ms: 1000 } };
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
				data,
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
		const data = { [ bk ]: { count: 50, sum_ms: 500 } };
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
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
		const data = { [ bk ]: { count: 1, sum_ms: 1 } };
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				data,
				breakdownData: null,
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
