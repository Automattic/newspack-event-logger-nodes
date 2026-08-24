/**
 * Tests for ResponseTimeChart — D3 scatter plot on the shared useTimeChart.
 *
 * Mocks d3 with a chainable so the render function executes against the mock
 * chain instead of real SVG. Tests focus on:
 *  - chart returns null when no requests
 *  - data transform (filter + map + sort) works
 *  - the render runs, and redraws from an emptied container
 *  - a container resize re-runs it
 */

jest.mock( 'd3', () => {
	// chain is a Proxy: any access yields a jest-fn returning the chain.
	const chainHandler = {
		get: ( target, prop ) => {
			if ( prop === 'then' ) {
				// jest may try to check for thenable.
				return undefined;
			}
			if ( target[ prop ] !== undefined ) {
				return target[ prop ];
			}
			const f = jest.fn( () => chain );
			target[ prop ] = f;
			return f;
		},
	};
	const chain = new Proxy( {}, chainHandler );

	const topHandler = {
		get: ( _t, prop ) => {
			if ( prop === '__esModule' ) {
				return true;
			}
			if ( prop === '__chain' ) {
				return chain;
			}
			if ( prop === 'scaleTime' || prop === 'scaleLinear' ) {
				const scale = jest.fn( ( v ) => v );
				scale.range = jest.fn( () => scale );
				scale.domain = jest.fn( () => scale );
				return jest.fn( () => scale );
			}
			if ( prop === 'extent' ) {
				return jest.fn( ( arr, accessor ) => {
					if ( ! arr || arr.length === 0 ) {
						return [ undefined, undefined ];
					}
					const vals = arr.map( ( d ) => accessor( d ) );
					return [ Math.min( ...vals ), Math.max( ...vals ) ];
				} );
			}
			if ( prop === 'max' || prop === 'mean' ) {
				return jest.fn( ( arr, accessor ) => {
					if ( ! arr || arr.length === 0 ) {
						return 0;
					}
					const vals = arr.map( ( d ) => accessor( d ) );
					return prop === 'max'
						? Math.max( ...vals )
						: vals.reduce( ( s, v ) => s + v, 0 ) / vals.length;
				} );
			}
			if ( prop === 'line' ) {
				return jest.fn( () => {
					const lineFn = jest.fn( () => '' );
					lineFn.x = jest.fn( () => lineFn );
					lineFn.y = jest.fn( () => lineFn );
					lineFn.curve = jest.fn( () => lineFn );
					return lineFn;
				} );
			}
			// Default: a factory that yields the chain.
			return jest.fn( () => chain );
		},
	};
	return new Proxy( {}, topHandler );
} );

import { readFileSync } from 'fs';
import { resolve as resolvePath } from 'path';
import * as React from 'react';
import * as d3 from 'd3';
import ResponseTimeChart from '../ResponseTimeChart';
import { renderComponent, act } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

// jsdom has no ResizeObserver; capture the callback to fire a container resize.
let resizeObserverCb = null;
global.ResizeObserver = class {
	constructor( cb ) {
		resizeObserverCb = cb;
	}
	observe() {
		// The spec seeds lastReportedSize to 0x0 and jsdom lays nothing
		// out, so a real observer would deliver nothing here.
	}
	disconnect() {}
};

const REQUESTS = [
	{ rid: 'r1', timestamp: 1700000000, duration_ms: 50, status_code: 200 },
	{ rid: 'r2', timestamp: 1700000100, duration_ms: 100, status_code: 404 },
	{ rid: 'r3', timestamp: 1700000200, duration_ms: 75, status_code: 500 },
	{ rid: 'r4', timestamp: 1700000300, duration_ms: 25, status_code: 301 },
	// Out of range; shares the 5xx color and the 5xx legend entry.
	{ rid: 'r5', timestamp: 1700000400, duration_ms: 200, status_code: 600 },
	// Filtered out — no timestamp:
	{ rid: 'r6', duration_ms: 100, status_code: 200 },
	// Filtered out — no duration:
	{ rid: 'r7', timestamp: 1700000500, status_code: 200 },
];

describe( 'ResponseTimeChart', () => {
	// mockClear the lazily-populated chain keys, best-effort across runs.
	beforeEach( () => {
		Object.keys( d3Mock ).forEach( ( k ) => {
			const v = d3Mock[ k ];
			if ( v && typeof v.mockClear === 'function' ) {
				v.mockClear();
			}
		} );
	} );

	it( 'returns null when requests prop is missing', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: undefined,
				onRequestClick: jest.fn(),
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when requests prop is empty array', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: [],
				onRequestClick: jest.fn(),
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'renders the chart container when requests are present', () => {
		const { container, unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		expect( container.textContent ).toContain( 'Response Times' );
		expect( d3Mock.append ).toHaveBeenCalledWith( 'svg' );
		unmount();
	} );

	it( 'covers data update path (extent / max / line / dots)', () => {
		const onClick = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: onClick,
			} )
		);
		// The trend line is the only mark bound with datum().
		expect( d3Mock.datum ).toHaveBeenCalled();
		unmount();
	} );

	it( 'legends an out-of-range status as the class its dot is painted', () => {
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: [
					{
						rid: 'r9',
						timestamp: 1700000600,
						duration_ms: 40,
						status_code: 601,
					},
				],
				onRequestClick: jest.fn(),
			} )
		);
		// getStatusColor paints 601 with the 5xx swatch; the legend must agree.
		expect( d3Mock.text ).toHaveBeenCalledWith( '5xx' );
		unmount();
	} );

	it( 'wires dot hover and click handlers to the rendered request data', () => {
		const onClick = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: onClick,
			} )
		);
		const calls = d3Mock.on.mock.calls;
		const mouseover = calls.find(
			( call ) => call[ 0 ] === 'mouseover'
		)[ 1 ];
		const mouseout = calls.find(
			( call ) => call[ 0 ] === 'mouseout'
		)[ 1 ];
		const click = calls.find( ( call ) => call[ 0 ] === 'click' )[ 1 ];
		expect( () => mouseover.call( {} ) ).not.toThrow();
		expect( () => mouseout.call( {} ) ).not.toThrow();
		click( null, { rid: 'r2', partition: 4 } );
		click( null, { rid: '' } );
		expect( onClick ).toHaveBeenCalledWith( 'r2', 4 );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );

	it( 'handles single-request data (trend-line branch falsy)', () => {
		// Only 1 valid request → chartData.length === 1 → skip trend-line.
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: [ REQUESTS[ 0 ] ],
				onRequestClick: jest.fn(),
			} )
		);
		expect( d3Mock.append ).toHaveBeenCalledWith( 'svg' );
		expect( d3Mock.datum ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'resize handler is a no-op when there is no data on the next tick', () => {
		jest.useFakeTimers();
		resizeObserverCb = null;
		const { unmount, rerender } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		const observed = resizeObserverCb;
		// Empty requests → null render; a stale observer must not throw.
		rerender(
			React.createElement( ResponseTimeChart, {
				requests: [],
				onRequestClick: jest.fn(),
			} )
		);
		expect( () =>
			act( () => {
				observed();
				jest.advanceTimersByTime( 300 );
			} )
		).not.toThrow();
		unmount();
		jest.useRealTimers();
	} );

	it( 're-fits when its own container resizes, not just the window', () => {
		// The chart sits inside the URL detail panel, which resizes without the
		// window ever doing so — opening the panel, or the flame graph above it
		// growing, left the plot drawn to a width that no longer existed.
		jest.useFakeTimers();
		resizeObserverCb = null;
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		expect( resizeObserverCb ).toEqual( expect.any( Function ) );

		const before = d3Mock.attr.mock.calls.length;
		act( () => {
			resizeObserverCb( [
				{ contentRect: { width: 700, height: 220 } },
			] );
			jest.advanceTimersByTime( 300 );
		} );

		expect( d3Mock.attr.mock.calls.length ).toBeGreaterThan( before );
		unmount();
		jest.useRealTimers();
	} );

	it( 'clears the container before each redraw', () => {
		// One render path draws everything, so it must start from an empty
		// container: leaving the last pass in place stacked a second <svg>
		// under the first on every data refresh.
		const { rerender, unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		d3Mock.remove.mockClear();

		rerender(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS.slice( 0, 3 ),
				onRequestClick: jest.fn(),
			} )
		);

		expect( d3Mock.selectAll ).toHaveBeenCalledWith( '*' );
		expect( d3Mock.remove ).toHaveBeenCalled();
		unmount();
	} );
} );

describe( 'ResponseTimeChart frame', () => {
	it( 'draws its axes through the shared frame, not a private copy', () => {
		const source = readFileSync(
			resolvePath( __dirname, '../ResponseTimeChart.js' ),
			'utf8'
		);
		expect( source ).toContain( 'drawAxes' );
		expect( source ).not.toContain( 'axisBottom' );
		expect( source ).not.toContain( 'axisLeft' );
	} );
} );
