/* global globalThis */
/**
 * Tests for CategoryTimeChart — D3-driven overlaid area chart.
 *
 * D3 is mocked at the module boundary with a deeply-chainable fluent
 * builder so every `d3.select().append().attr()` chain in the render
 * callback resolves without touching real SVG / DOM. The chainable also
 * records every call so we can assert what data flowed into d3.
 *
 * Mocking strategy:
 * - `jest.mock('d3', ...)` returns a Proxy that yields a chainable for
 *   any property access, plus calls (factories like `d3.scaleTime()`)
 *   return chainables too.
 * - `useTimeChart` is mocked to return real refs (a DOM div + tooltip
 *   div) and to invoke the supplied renderFn synchronously — that way
 *   the render body runs against the d3 chainable AND coverage flows.
 */

// Mock d3: every call returns the shared chainable (jest.fn for asserts).
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
			if ( chain[ prop ] !== undefined ) {
				return chain[ prop ];
			}
			// Factories / helpers — return a callable that yields the chain.
			const f = jest.fn( () => chain );
			chain[ prop ] = f;
			return f;
		},
	};
	return new Proxy( {}, handler );
} );

// useTimeChart mock: real refs + sync renderFn; stubs setupTooltip.
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
import CategoryTimeChart from '../CategoryTimeChart';
import { renderComponent } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

// The panel's views, in render order: time, count, average.
const [ TIME_VIEW, COUNT_VIEW, AVERAGE_VIEW ] = [ 0, 1, 2 ];
const VIEW_COUNT = 3;

describe( 'CategoryTimeChart', () => {
	beforeEach( () => {
		globalThis.__lastRenderFn = null;
		// Reset every recorded call on the d3 chainable.
		Object.values( d3Mock ).forEach( ( v ) => {
			if ( v && typeof v.mockClear === 'function' ) {
				v.mockClear();
			}
		} );
	} );

	it( 'draws exactly one chart per declared view, in render order', () => {
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data: { '2019-07-04-13-45': { redis: { c: 419, t: 8123 } } },
			} )
		);
		const headings = [ ...container.querySelectorAll( 'h3' ) ].map(
			( heading ) => heading.textContent
		);
		expect( headings ).toEqual( [
			'Time by Category',
			'Events by Category',
			'Average Time per Event',
		] );
		unmount();
	} );

	it( 'returns null when data is null', () => {
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data: null } )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when data is empty object', () => {
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data: {} } )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'gives every view its own chart frame and tooltip', () => {
		const data = {
			[ bucketKeyNow() ]: {
				total: { c: 100, t: 5000 },
				db: { c: 30, t: 1500 },
				render: { c: 70, t: 3500 },
				// count=0 drives the average-mode divide-by-zero path.
				cache: { c: 0, t: 0 },
			},
		};

		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);

		// renderFn must have been wired up by useTimeChart.
		expect( globalThis.__lastRenderFn ).toEqual( expect.any( Function ) );
		// d3.select must have been called (chart actually rendered).
		expect( d3Mock.select ).toHaveBeenCalled();
		const tooltips = container.querySelectorAll(
			'.event-logger-chart-tooltip'
		);
		expect( tooltips ).toHaveLength( VIEW_COUNT );
		expect( tooltips[ 0 ].className ).toBe( 'event-logger-chart-tooltip' );
		expect( tooltips[ 0 ].getAttribute( 'style' ) ).toBeNull();
		unmount();
	} );

	it( 'handles buckets where category stats are missing', () => {
		// Bucket key matches no slot → exercises the value=0 fallback.
		const data = {
			'1970-01-01-00-00': {
				db: { c: 1, t: 10 },
			},
		};

		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);
		expect( d3Mock.select ).toHaveBeenCalled();
		unmount();
	} );

	/**
	 * Capture one view's formatEntry callback from the most recent render —
	 * that's the function that calls formatYValue (the top-level helper) for
	 * each series value, so invoking it drives coverage of formatYValue's
	 * branches without exporting it. The render mounts every view in order, so
	 * the last VIEW_COUNT calls are this render's, indexed the same way.
	 *
	 * @param {number} view Index into the panel's views: time, count, average.
	 * @return {Function} That view's formatEntry callback.
	 */
	function getFormatEntry( view ) {
		const {
			setupTooltip,
		} = require( '@newspack-nodes/shared/hooks/useTimeChart' );
		const calls = setupTooltip.mock.calls;
		return calls[ calls.length - VIEW_COUNT + view ][ 1 ].formatEntry;
	}

	function bucketKeyNow() {
		const now = new Date();
		now.setMinutes( Math.floor( now.getMinutes() / 5 ) * 5, 0, 0 );
		return [
			now.getUTCFullYear(),
			String( now.getUTCMonth() + 1 ).padStart( 2, '0' ),
			String( now.getUTCDate() ).padStart( 2, '0' ),
			String( now.getUTCHours() ).padStart( 2, '0' ),
			String( Math.floor( now.getUTCMinutes() / 5 ) * 5 ).padStart(
				2,
				'0'
			),
		].join( '-' );
	}

	/**
	 * Last slot index = the bucket for "now" — that's the bucket the test
	 * data populates, so invoking formatEntry at this index runs
	 * formatYValue with real (nonzero) values.
	 */
	function lastSlotIndex() {
		const {
			NUM_BUCKETS,
		} = require( '@newspack-nodes/shared/hooks/useTimeChart' );
		return NUM_BUCKETS - 1;
	}

	it( 'formatYValue covers all branches via tooltip formatEntry callback', () => {
		// varying magnitudes drive the <0.001 / <1 / >=1 time-mode branches.
		const data = {
			[ bucketKeyNow() ]: {
				tiny: { c: 1, t: 0.0001 },
				mid: { c: 1, t: 150000 },
				big: { c: 1, t: 900000 },
			},
		};
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);
		const entries = getFormatEntry( TIME_VIEW )( lastSlotIndex() );
		expect( Array.isArray( entries ) ).toBe( true );
		// All three categories had positive values → all are kept.
		expect( entries.length ).toBeGreaterThan( 0 );
		unmount();
	} );

	it( 'formatYValue covers average-mode microsecond / second / ms branches', () => {
		// average mode: value = t/c (in same units).
		const data = {
			[ bucketKeyNow() ]: {
				submicro: { c: 1000, t: 0.5 }, // 0.0005ms → microsecond
				bigsec: { c: 1, t: 2000 }, // value=2000ms → s branch
				normal: { c: 1, t: 5 }, // value=5ms → ms branch
			},
		};
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);
		const formatEntry = getFormatEntry( AVERAGE_VIEW );
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'formatYValue covers count-mode K/s and per-second branches', () => {
		// count mode: value = c/BUCKET_SECONDS.
		const data = {
			[ bucketKeyNow() ]: {
				high: { c: 1_000_000, t: 1 }, // → K/s branch (~3333/s)
				low: { c: 5, t: 1 }, // → per-second branch
				zero: { c: 0, t: 0 }, // → '0' branch
			},
		};
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);
		const formatEntry = getFormatEntry( COUNT_VIEW );
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'renderFn no-ops when containerRef is null', () => {
		// Re-invoke captured renderFn with null containerRef → early return.
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data: { [ bucketKeyNow() ]: { foo: { c: 1, t: 10 } } },
			} )
		);

		expect( globalThis.__lastRenderFn ).toEqual( expect.any( Function ) );
		// Re-invoke with a null container — returns immediately, no throw.
		expect( () =>
			globalThis.__lastRenderFn( {
				containerRef: { current: null },
				tooltipRef: { current: null },
				lastMouseXRef: { current: 0 },
			} )
		).not.toThrow();
		unmount();
	} );
} );
