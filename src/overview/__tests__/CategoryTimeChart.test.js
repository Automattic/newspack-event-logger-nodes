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

	it( 'returns null when data is null', () => {
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data: null,
				mode: 'time',
				title: 'X',
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when data is empty object', () => {
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data: {},
				mode: 'time',
				title: 'X',
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'renders title + container divs when data has categories', () => {
		// Compute today's UTC bucket key so the chart finds a matching bucket.
		const now = new Date();
		now.setMinutes( Math.floor( now.getMinutes() / 5 ) * 5, 0, 0 );
		const bucketKey = [
			now.getUTCFullYear(),
			String( now.getUTCMonth() + 1 ).padStart( 2, '0' ),
			String( now.getUTCDate() ).padStart( 2, '0' ),
			String( now.getUTCHours() ).padStart( 2, '0' ),
			String( Math.floor( now.getUTCMinutes() / 5 ) * 5 ).padStart(
				2,
				'0'
			),
		].join( '-' );

		const data = {
			[ bucketKey ]: {
				total: { c: 100, t: 5000 },
				db: { c: 30, t: 1500 },
				render: { c: 70, t: 3500 },
			},
		};

		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'time',
				title: 'Time per second',
			} )
		);

		expect( container.textContent ).toContain( 'Time per second' );
		// renderFn must have been wired up by useTimeChart.
		expect( globalThis.__lastRenderFn ).toEqual( expect.any( Function ) );
		// d3.select must have been called (chart actually rendered).
		expect( d3Mock.select ).toHaveBeenCalled();
		unmount();
	} );

	it( 'covers mode=count (counts per second branch)', () => {
		const now = new Date();
		now.setMinutes( Math.floor( now.getMinutes() / 5 ) * 5, 0, 0 );
		const bucketKey = [
			now.getUTCFullYear(),
			String( now.getUTCMonth() + 1 ).padStart( 2, '0' ),
			String( now.getUTCDate() ).padStart( 2, '0' ),
			String( now.getUTCHours() ).padStart( 2, '0' ),
			String( Math.floor( now.getUTCMinutes() / 5 ) * 5 ).padStart(
				2,
				'0'
			),
		].join( '-' );

		const data = {
			[ bucketKey ]: {
				total: { c: 100, t: 5000 },
				db: { c: 30, t: 1500 },
			},
		};

		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'count',
				title: 'Counts per second',
			} )
		);
		expect( d3Mock.select ).toHaveBeenCalled();
		unmount();
	} );

	it( 'covers mode=average (ms per event branch)', () => {
		const now = new Date();
		now.setMinutes( Math.floor( now.getMinutes() / 5 ) * 5, 0, 0 );
		const bucketKey = [
			now.getUTCFullYear(),
			String( now.getUTCMonth() + 1 ).padStart( 2, '0' ),
			String( now.getUTCDate() ).padStart( 2, '0' ),
			String( now.getUTCHours() ).padStart( 2, '0' ),
			String( Math.floor( now.getUTCMinutes() / 5 ) * 5 ).padStart(
				2,
				'0'
			),
		].join( '-' );

		const data = {
			[ bucketKey ]: {
				db: { c: 30, t: 1500 },
				cache: { c: 0, t: 0 }, // edge: count=0 path → value=0
			},
		};

		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'average',
				title: 'Avg ms',
			} )
		);
		expect( d3Mock.select ).toHaveBeenCalled();
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
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'time',
				title: 'Stale',
			} )
		);
		expect( d3Mock.select ).toHaveBeenCalled();
		unmount();
	} );

	/**
	 * Capture the formatEntry callback handed to setupTooltip on the most
	 * recent render — that's the function that calls formatYValue (the
	 * top-level helper) for each series value, so invoking it drives
	 * coverage of formatYValue's branches without exporting it.
	 */
	function getFormatEntry() {
		const {
			setupTooltip,
		} = require( '@newspack-nodes/shared/hooks/useTimeChart' );
		const calls = setupTooltip.mock.calls;
		const last = calls[ calls.length - 1 ];
		return last[ 1 ].formatEntry;
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
		const bucketKey = bucketKeyNow();
		// varying magnitudes drive the <0.001 / <1 / >=1 time-mode branches.
		const data = {
			[ bucketKey ]: {
				tiny: { c: 1, t: 0.0001 },
				mid: { c: 1, t: 150000 },
				big: { c: 1, t: 900000 },
			},
		};
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'time',
				title: 'time',
			} )
		);
		const formatEntry = getFormatEntry();
		// invoke formatEntry at the latest slot index (where data lives).
		const entries = formatEntry( lastSlotIndex() );
		expect( Array.isArray( entries ) ).toBe( true );
		// All three categories had positive values → all are kept.
		expect( entries.length ).toBeGreaterThan( 0 );
		unmount();
	} );

	it( 'formatYValue covers average-mode microsecond / second / ms branches', () => {
		const bucketKey = bucketKeyNow();
		// average mode: value = t/c (in same units).
		const data = {
			[ bucketKey ]: {
				submicro: { c: 1000, t: 0.5 }, // 0.0005ms → microsecond
				bigsec: { c: 1, t: 2000 }, // value=2000ms → s branch
				normal: { c: 1, t: 5 }, // value=5ms → ms branch
			},
		};
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'average',
				title: 'avg',
			} )
		);
		const formatEntry = getFormatEntry();
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'formatYValue covers count-mode K/s and per-second branches', () => {
		const bucketKey = bucketKeyNow();
		// count mode: value = c/BUCKET_SECONDS.
		const data = {
			[ bucketKey ]: {
				high: { c: 1_000_000, t: 1 }, // → K/s branch (~3333/s)
				low: { c: 5, t: 1 }, // → per-second branch
				zero: { c: 0, t: 0 }, // → '0' branch
			},
		};
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				mode: 'count',
				title: 'count',
			} )
		);
		const formatEntry = getFormatEntry();
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'renderFn no-ops when containerRef is null', () => {
		// Re-invoke captured renderFn with null containerRef → early return.
		const now = new Date();
		now.setMinutes( Math.floor( now.getMinutes() / 5 ) * 5, 0, 0 );
		const bucketKey = [
			now.getUTCFullYear(),
			String( now.getUTCMonth() + 1 ).padStart( 2, '0' ),
			String( now.getUTCDate() ).padStart( 2, '0' ),
			String( now.getUTCHours() ).padStart( 2, '0' ),
			String( Math.floor( now.getUTCMinutes() / 5 ) * 5 ).padStart(
				2,
				'0'
			),
		].join( '-' );

		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data: { [ bucketKey ]: { foo: { c: 1, t: 10 } } },
				mode: 'time',
				title: 'X',
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
