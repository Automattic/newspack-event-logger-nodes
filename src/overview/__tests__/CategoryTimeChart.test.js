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
		'tickFormat',
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
import { slotsEndingAt } from '../../test-helpers/chartWire';
import { renderComponent } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

/**
 * The slots every reply here names: 288 ending at 14:35.
 */
const SLOTS = slotsEndingAt( '2026-09-29-14-35' );

// A CategoryTimeChart on SLOTS under a test storage key.
const categoryChart = ( props ) =>
	React.createElement( CategoryTimeChart, {
		storageKey: 'test:category',
		slots: SLOTS,
		...props,
	} );

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
		window.localStorage.clear();
	} );

	it( 'refuses to draw without a storageKey', () => {
		const quiet = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			expect( () =>
				renderComponent(
					categoryChart( { storageKey: undefined, data: null } )
				)
			).toThrow( 'CategoryTimeChart: storageKey is required' );
		} finally {
			quiet.mockRestore();
		}
	} );

	it( 'keeps each view’s corner choices under <storageKey>:<mode>', () => {
		window.localStorage.setItem( 'test:category:count:expanded', '1' );
		const { container, unmount } = renderComponent(
			categoryChart( {
				data: {
					names: [ 'db' ],
					buckets: { [ bucketKeyNow() ]: [ [ 0, 1500, 30, 30 ] ] },
				},
			} )
		);
		expect(
			[
				...container.querySelectorAll(
					'.newspack-nodes-chart__expand'
				),
			].map( ( b ) => b.getAttribute( 'aria-expanded' ) )
		).toEqual( [ 'false', 'true', 'false' ] );
		unmount();
	} );

	it( 'draws exactly one chart per declared view, in render order', () => {
		const { container, unmount } = renderComponent(
			categoryChart( {
				data: {
					names: [ 'redis' ],
					buckets: { '2019-07-04-13-45': [ [ 0, 8123, 419, 419 ] ] },
				},
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

	it( 'titles every view Y-axis with the quantity it plots', () => {
		const { unmount } = renderComponent(
			categoryChart( {
				data: {
					names: [ 'redis' ],
					buckets: { [ bucketKeyNow() ]: [ [ 0, 8123, 419, 419 ] ] },
				},
			} )
		);
		const expected = [ 'Time', 'Events', 'Time per Event' ];
		const yLabels = d3Mock.text.mock.calls
			.map( ( [ text ] ) => text )
			.filter( ( text ) => expected.includes( text ) );
		expect( yLabels ).toEqual( expected );
		unmount();
	} );

	it( 'draws nothing before a reply names the window it read', () => {
		const { container, unmount } = renderComponent(
			categoryChart( {
				slots: null,
				data: {
					names: [ 'redis' ],
					buckets: { [ bucketKeyNow() ]: [ [ 0, 8123, 419, 419 ] ] },
				},
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when data is null', () => {
		const { container, unmount } = renderComponent(
			categoryChart( {
				data: null,
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'draws nothing for a malformed reply', () => {
		const { container, unmount } = renderComponent(
			categoryChart( {
				// A row one field short: a reply the decoder refuses whole.
				data: {
					names: [ 'memcache' ],
					buckets: { [ bucketKeyNow() ]: [ [ 0, 8123, 419 ] ] },
				},
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'returns null when data is empty object', () => {
		const { container, unmount } = renderComponent(
			categoryChart( {
				data: {},
			} )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'draws from the compact wire shape and treats an empty one as no data', () => {
		// The series arrives as a name TABLE plus positional rows: one category
		// name rides once rather than once per bucket, which is what keeps the
		// overview reply deliverable. An empty payload still has both keys, so
		// emptiness is a question about the buckets, not about the envelope.
		const empty = renderComponent(
			categoryChart( {
				data: { names: [], buckets: {} },
			} )
		);
		expect( empty.container.textContent ).toBe( '' );
		empty.unmount();

		const drawn = renderComponent(
			categoryChart( {
				data: {
					names: [ 'total', 'db' ],
					buckets: {
						[ bucketKeyNow() ]: [
							[ 0, 5000, 100, 100 ],
							[ 1, 1500, 30, 30 ],
						],
					},
				},
			} )
		);
		expect(
			drawn.container.querySelectorAll( '.newspack-nodes-chart__tooltip' )
		).toHaveLength( VIEW_COUNT );
		drawn.unmount();
	} );

	it( 'gives every view its own chart frame and tooltip', () => {
		const data = {
			names: [ 'total', 'db', 'render', 'cache' ],
			buckets: {
				[ bucketKeyNow() ]: [
					[ 0, 5000, 100, 100 ],
					[ 1, 1500, 30, 30 ],
					[ 2, 3500, 70, 70 ],
					// count=0 drives the average-mode divide-by-zero path.
					[ 3, 0, 0, 0 ],
				],
			},
		};

		const { container, unmount } = renderComponent(
			categoryChart( {
				data,
			} )
		);

		// renderFn must have been wired up by useTimeChart.
		expect( globalThis.__lastRenderFn ).toEqual( expect.any( Function ) );
		// d3.select must have been called (chart actually rendered).
		expect( d3Mock.select ).toHaveBeenCalled();
		const tooltips = container.querySelectorAll(
			'.newspack-nodes-chart__tooltip'
		);
		expect( tooltips ).toHaveLength( VIEW_COUNT );
		expect(
			tooltips[ 0 ].classList.contains( 'newspack-nodes-card--elevated' )
		).toBe( true );
		expect( tooltips[ 0 ].getAttribute( 'style' ) ).toBeNull();
		unmount();
	} );

	it( 'plots a mean over no calls as a gap, where a rate over them is a real 0', () => {
		const data = {
			names: [ 'db' ],
			buckets: {
				[ SLOTS[ 0 ] ]: [ [ 0, 900, 0, 0 ] ],
				[ SLOTS[ 1 ] ]: [ [ 0, 3000, 12, 12 ] ],
			},
		};
		const { unmount } = renderComponent(
			categoryChart( {
				data,
			} )
		);
		const [ time, count, average ] = d3Mock.datum.mock.calls
			.slice( -VIEW_COUNT )
			.map( ( call ) => call[ 0 ] );
		const last = SLOTS.length - 1;
		const defined = ( band ) => [
			band[ last - 1 ].defined,
			band[ last ].defined,
			band[ 0 ].defined,
		];

		expect( defined( time ) ).toEqual( [ true, true, true ] );
		expect( defined( count ) ).toEqual( [ true, true, true ] );
		// Measured, then c 0, then a slot with no row.
		expect( defined( average ) ).toEqual( [ true, false, false ] );
		unmount();
	} );

	it( 'handles buckets where category stats are missing', () => {
		// Bucket key matches no slot → exercises the value=0 fallback.
		const data = {
			names: [ 'db' ],
			buckets: { '1970-01-01-00-00': [ [ 0, 10, 1, 1 ] ] },
		};

		const { unmount } = renderComponent(
			categoryChart( {
				data,
			} )
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
		return SLOTS[ 0 ];
	}

	/**
	 * Last slot index = the bucket for "now" — that's the bucket the test
	 * data populates, so invoking formatEntry at this index runs
	 * formatYValue with real (nonzero) values.
	 */
	function lastSlotIndex() {
		return SLOTS.length - 1;
	}

	it( 'reads the oldest slot and the newest as rates over their five minutes', () => {
		const data = {
			names: [ 'memcache' ],
			buckets: {
				'2026-09-28-14-40': [ [ 0, 1, 900, 900 ] ],
				[ bucketKeyNow() ]: [ [ 0, 1, 1500, 1500 ] ],
			},
		};
		const { unmount } = renderComponent(
			categoryChart( {
				data,
			} )
		);
		const rateAt = ( index ) =>
			getFormatEntry( COUNT_VIEW )( index ).find(
				( entry ) => 'memcache' === entry.label
			)?.value;
		expect( rateAt( 0 ) ).toBe( '3/s' );
		expect( rateAt( lastSlotIndex() ) ).toBe( '5/s' );
		unmount();
	} );

	it( 'formatYValue covers all branches via tooltip formatEntry callback', () => {
		// varying magnitudes drive the <0.001 / <1 / >=1 time-mode branches.
		const data = {
			names: [ 'tiny', 'mid', 'big' ],
			buckets: {
				[ bucketKeyNow() ]: [
					[ 0, 0.0001, 1, 1 ],
					[ 1, 150000, 1, 1 ],
					[ 2, 900000, 1, 1 ],
				],
			},
		};
		const { unmount } = renderComponent(
			categoryChart( {
				data,
			} )
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
			names: [ 'submicro', 'bigsec', 'normal' ],
			buckets: {
				[ bucketKeyNow() ]: [
					[ 0, 0.5, 1000, 1000 ], // 0.0005ms → microsecond
					[ 1, 2000, 1, 1 ], // value=2000ms → s branch
					[ 2, 5, 1, 1 ], // value=5ms → ms branch
				],
			},
		};
		const { unmount } = renderComponent(
			categoryChart( {
				data,
			} )
		);
		const formatEntry = getFormatEntry( AVERAGE_VIEW );
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'formatYValue covers count-mode K/s and per-second branches', () => {
		// count mode: value = c/BUCKET_SECONDS.
		const data = {
			names: [ 'high', 'low', 'zero' ],
			buckets: {
				[ bucketKeyNow() ]: [
					[ 0, 1, 1_000_000, 1 ], // → K/s branch (~3333/s)
					[ 1, 1, 5, 1 ], // → per-second branch
					[ 2, 0, 0, 0 ], // → '0' branch
				],
			},
		};
		const { unmount } = renderComponent(
			categoryChart( {
				data,
			} )
		);
		const formatEntry = getFormatEntry( COUNT_VIEW );
		expect( Array.isArray( formatEntry( lastSlotIndex() ) ) ).toBe( true );
		unmount();
	} );

	it( 'renderFn no-ops when containerRef is null', () => {
		// Re-invoke captured renderFn with null containerRef → early return.
		const { unmount } = renderComponent(
			categoryChart( {
				data: {
					names: [ 'foo' ],
					buckets: { [ bucketKeyNow() ]: [ [ 0, 10, 1, 1 ] ] },
				},
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

	it( 'offers no stack toggle: a callback counts inside its hook', () => {
		const { container, unmount } = renderComponent(
			categoryChart( {
				data: {
					names: [ 'db', 'http' ],
					buckets: {
						[ bucketKeyNow() ]: [
							[ 0, 5000, 100, 100 ],
							[ 1, 1500, 30, 30 ],
						],
					},
				},
			} )
		);
		expect(
			container.querySelector( '.newspack-nodes-chart__stack' )
		).toBeNull();
		unmount();
	} );
} );
