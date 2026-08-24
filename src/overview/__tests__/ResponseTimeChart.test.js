/**
 * Tests for ResponseTimeChart — D3 scatter plot on the shared useTimeChart.
 *
 * Real d3 against jsdom, the way the sibling `AreaTimeChart` suite runs it.
 * This chart is very nearly nothing but accessors — `.attr( 'cx', ( d ) =>
 * x( d.time ) )`, the trend line's `.x`/`.y`, the tooltip `.text`, the axis
 * `yFormat` — and d3 invokes those only when it draws a non-empty selection.
 * A chainable mock resolves every one of them to the same stub, so it can
 * assert that d3 was CALLED and nothing about what lands in the SVG; these
 * tests assert the rendered DOM instead.
 *
 * Covered here: the null render before data arrives, the data transform
 * (filter + map + sort), dot geometry and status colour, the trend line, the
 * mean line, the tooltip title, the legend, hover and click, redraw from an
 * emptied container, and a container resize.
 */

import { readFileSync } from 'fs';
import { resolve as resolvePath } from 'path';
import * as React from 'react';
import { MARGIN } from '@newspack-nodes/shared/hooks/useTimeChart';
import { STATUS_COLORS } from '@newspack-nodes/shared/utils/formatUtils';
import ResponseTimeChart from '../ResponseTimeChart';
import { renderComponent, act } from '../../test-helpers/renderHook';

// jsdom lays nothing out and reports clientWidth 0, so `openFrame` falls back.
const CHART_WIDTH = 800;
// `CHART_HEIGHT` in the component under test; it is not exported.
const CHART_HEIGHT = 250;
const INNER_W = CHART_WIDTH - MARGIN.left - MARGIN.right;
const INNER_H = CHART_HEIGHT - MARGIN.top - MARGIN.bottom;

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

/**
 * Seed requests, deliberately out of time order so the sort is exercised.
 *
 * Every duration, timestamp and status is distinct from anything an empty or
 * default render could produce: no zeroes, no round hundreds shared with a
 * scale tick, one status per colour class, and two rows the filter must drop.
 */
const REQUESTS = [
	{
		rid: 'req-charlie',
		partition: 5,
		timestamp: 1755001200,
		duration_ms: 68,
		status_code: 404,
	},
	{
		rid: 'req-alpha',
		partition: 3,
		timestamp: 1755000000,
		duration_ms: 137,
		status_code: 200,
	},
	{
		rid: 'req-delta',
		partition: 7,
		timestamp: 1755001800,
		duration_ms: 954,
		status_code: 503,
	},
	{
		rid: 'req-bravo',
		partition: 3,
		timestamp: 1755000600,
		duration_ms: 421,
		status_code: 301,
	},
	// Filtered out — no timestamp:
	{ rid: 'req-echo', duration_ms: 512, status_code: 200 },
	// Filtered out — no duration:
	{ rid: 'req-foxtrot', timestamp: 1755002400, status_code: 200 },
];

// The four plottable seeds, ascending by time — the order the dots must take.
const PLOTTED = [
	{ ts: 1755000000, ms: 137, status: 200, rid: 'req-alpha', partition: 3 },
	{ ts: 1755000600, ms: 421, status: 301, rid: 'req-bravo', partition: 3 },
	{ ts: 1755001200, ms: 68, status: 404, rid: 'req-charlie', partition: 5 },
	{ ts: 1755001800, ms: 954, status: 503, rid: 'req-delta', partition: 7 },
];

const T_MIN = PLOTTED[ 0 ].ts * 1000;
const T_MAX = PLOTTED[ PLOTTED.length - 1 ].ts * 1000;
const Y_MAX = 954 * 1.1;
const MEAN_MS = ( 137 + 421 + 68 + 954 ) / 4;

/**
 * Where the time scale puts a seed timestamp.
 *
 * @param {number} ts Epoch seconds.
 * @return {number} X offset inside the plot area.
 */
const expectedCx = ( ts ) =>
	( ( ts * 1000 - T_MIN ) / ( T_MAX - T_MIN ) ) * INNER_W;

/**
 * Where the linear scale puts a duration.
 *
 * @param {number} ms Duration in milliseconds.
 * @return {number} Y offset inside the plot area.
 */
const expectedCy = ( ms ) => INNER_H - ( ms / Y_MAX ) * INNER_H;

/**
 * Mount the chart with a set of requests.
 *
 * @param {Array}    requests       Request index entries.
 * @param {Function} onRequestClick Dot click handler.
 * @return {Object} The `renderComponent` handle.
 */
const mountChart = ( requests, onRequestClick = jest.fn() ) =>
	renderComponent(
		React.createElement( ResponseTimeChart, { requests, onRequestClick } )
	);

/**
 * The dots, in document order.
 *
 * @param {Element} container Mounted chart container.
 * @return {Array<Element>} Circle elements.
 */
const dots = ( container ) => [ ...container.querySelectorAll( 'circle' ) ];

/**
 * Every text string the chart drew.
 *
 * @param {Element} container Mounted chart container.
 * @return {Array<string>} Text contents.
 */
const texts = ( container ) =>
	[ ...container.querySelectorAll( 'svg text' ) ].map(
		( node ) => node.textContent
	);

describe( 'ResponseTimeChart', () => {
	it( 'returns null when requests prop is missing', () => {
		const { container, unmount } = mountChart( undefined );
		expect( container.textContent ).toBe( '' );
		expect( container.querySelector( 'svg' ) ).toBeNull();
		unmount();
	} );

	it( 'returns null when requests prop is empty array', () => {
		const { container, unmount } = mountChart( [] );
		expect( container.textContent ).toBe( '' );
		expect( container.querySelector( 'svg' ) ).toBeNull();
		unmount();
	} );

	it( 'draws one dot per plottable request, in time order', () => {
		const { container, unmount } = mountChart( REQUESTS );

		expect( container.textContent ).toContain( 'Response Times' );
		const circles = dots( container );
		// req-echo has no timestamp and req-foxtrot no duration.
		expect( circles ).toHaveLength( 4 );
		expect(
			circles.map( ( c ) => c.querySelector( 'title' ).textContent )
		).toEqual(
			PLOTTED.map( ( p ) =>
				expect.stringContaining( `Duration: ${ p.ms }ms` )
			)
		);
		unmount();
	} );

	it( 'places each dot at its own time and duration', () => {
		const { container, unmount } = mountChart( REQUESTS );

		dots( container ).forEach( ( circle, i ) => {
			expect( Number( circle.getAttribute( 'cx' ) ) ).toBeCloseTo(
				expectedCx( PLOTTED[ i ].ts ),
				6
			);
			expect( Number( circle.getAttribute( 'cy' ) ) ).toBeCloseTo(
				expectedCy( PLOTTED[ i ].ms ),
				6
			);
			expect( circle.getAttribute( 'r' ) ).toBe( '5' );
		} );
		unmount();
	} );

	it( 'paints each dot the colour of its status class', () => {
		const { container, unmount } = mountChart( REQUESTS );

		expect(
			dots( container ).map( ( c ) => c.getAttribute( 'fill' ) )
		).toEqual( [
			STATUS_COLORS[ '2xx' ],
			STATUS_COLORS[ '3xx' ],
			STATUS_COLORS[ '4xx' ],
			STATUS_COLORS[ '5xx' ],
		] );
		unmount();
	} );

	it( 'paints a request with no status code the unknown swatch', () => {
		const { container, unmount } = mountChart( [
			{
				rid: 'req-golf',
				partition: 2,
				timestamp: 1755003000,
				duration_ms: 76,
			},
		] );

		const [ circle ] = dots( container );
		expect( circle.getAttribute( 'fill' ) ).toBe( STATUS_COLORS.unknown );
		// `d.status || 'N/A'` — a 0 status has no code to show.
		expect( circle.querySelector( 'title' ).textContent ).toContain(
			'Status: N/A'
		);
		unmount();
	} );

	it( 'labels each dot with its time, status and duration', () => {
		const { container, unmount } = mountChart( REQUESTS );

		const title = dots( container )[ 2 ].querySelector( 'title' );
		expect( title.textContent.split( '\n' ) ).toEqual( [
			new Date( 1755001200 * 1000 ).toLocaleString(),
			'Status: 404',
			'Duration: 68ms',
			'Click to view details',
		] );
		unmount();
	} );

	it( 'formats the value axis in milliseconds', () => {
		const { container, unmount } = mountChart( REQUESTS );

		// `yFormat` is the only thing on this chart that appends 'ms' to a
		// bare tick value.
		expect( texts( container ) ).toEqual(
			expect.arrayContaining( [ '0ms', '200ms', '1000ms' ] )
		);
		unmount();
	} );

	it( 'runs the trend line through every plotted point', () => {
		const { container, unmount } = mountChart( REQUESTS );

		const trend = container.querySelector( 'path[stroke="#4a90d9"]' );
		const points = [
			...trend.getAttribute( 'd' ).matchAll( /([-\d.]+),([-\d.]+)/g ),
		];
		// curveMonotoneX emits control points too; the first and last are the
		// data's own endpoints.
		expect( Number( points[ 0 ][ 1 ] ) ).toBeCloseTo(
			expectedCx( 1755000000 ),
			2
		);
		expect( Number( points[ 0 ][ 2 ] ) ).toBeCloseTo(
			expectedCy( 137 ),
			2
		);
		const last = points[ points.length - 1 ];
		expect( Number( last[ 1 ] ) ).toBeCloseTo(
			expectedCx( 1755001800 ),
			2
		);
		expect( Number( last[ 2 ] ) ).toBeCloseTo( expectedCy( 954 ), 2 );
		unmount();
	} );

	it( 'draws the mean as a dashed line with its own label', () => {
		const { container, unmount } = mountChart( REQUESTS );

		const mean = container.querySelector( 'line[stroke-dasharray="5,5"]' );
		expect( Number( mean.getAttribute( 'y1' ) ) ).toBeCloseTo(
			expectedCy( MEAN_MS ),
			6
		);
		expect( Number( mean.getAttribute( 'x2' ) ) ).toBe( INNER_W );
		expect( texts( container ) ).toContain( `avg: ${ MEAN_MS }ms` );
		unmount();
	} );

	it( 'legends only the status classes actually present', () => {
		const { container, unmount } = mountChart( REQUESTS );

		expect( texts( container ) ).toEqual(
			expect.arrayContaining( [ '2xx', '3xx', '4xx', '5xx' ] )
		);
		unmount();
	} );

	it( 'legends an out-of-range status as the class its dot is painted', () => {
		const { container, unmount } = mountChart( [
			{
				rid: 'req-hotel',
				partition: 1,
				timestamp: 1755003600,
				duration_ms: 43,
				status_code: 601,
			},
		] );

		const [ circle ] = dots( container );
		expect( circle.getAttribute( 'fill' ) ).toBe( STATUS_COLORS[ '5xx' ] );
		expect( texts( container ) ).toContain( '5xx' );
		expect( texts( container ) ).not.toContain( '2xx' );
		unmount();
	} );

	it( 'grows a dot on hover and restores it on leave', () => {
		const { container, unmount } = mountChart( REQUESTS );
		const [ , circle ] = dots( container );

		act( () => {
			circle.dispatchEvent( new window.MouseEvent( 'mouseover' ) );
		} );
		expect( circle.getAttribute( 'r' ) ).toBe( '7' );
		expect( circle.getAttribute( 'opacity' ) ).toBe( '0.8' );

		act( () => {
			circle.dispatchEvent( new window.MouseEvent( 'mouseout' ) );
		} );
		expect( circle.getAttribute( 'r' ) ).toBe( '5' );
		expect( circle.getAttribute( 'opacity' ) ).toBe( '1' );
		unmount();
	} );

	it( 'opens the request a clicked dot was bound to', () => {
		const onClick = jest.fn();
		const { container, unmount } = mountChart( REQUESTS, onClick );

		act( () => {
			dots( container )[ 2 ].dispatchEvent(
				new window.MouseEvent( 'click' )
			);
		} );

		expect( onClick ).toHaveBeenCalledWith( 'req-charlie', 5 );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );

	it( 'ignores a click on a dot with no rid', () => {
		const onClick = jest.fn();
		const { container, unmount } = mountChart(
			[
				{
					rid: '',
					partition: 9,
					timestamp: 1755004200,
					duration_ms: 88,
					status_code: 200,
				},
			],
			onClick
		);

		act( () => {
			dots( container )[ 0 ].dispatchEvent(
				new window.MouseEvent( 'click' )
			);
		} );

		expect( onClick ).not.toHaveBeenCalled();
		unmount();
	} );

	it( 'survives a click with no handler supplied', () => {
		const { container, unmount } = mountChart( REQUESTS, undefined );

		expect( () =>
			act( () => {
				dots( container )[ 0 ].dispatchEvent(
					new window.MouseEvent( 'click' )
				);
			} )
		).not.toThrow();
		unmount();
	} );

	it( 'calls the newest handler without redrawing the chart', () => {
		// The handler is ref-held precisely so a new callback identity does
		// not re-run the render.
		const first = jest.fn();
		const second = jest.fn();
		const { container, rerender, unmount } = mountChart( REQUESTS, first );
		const drawn = container.querySelector( 'svg' );

		rerender(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: second,
			} )
		);
		expect( container.querySelector( 'svg' ) ).toBe( drawn );

		act( () => {
			dots( container )[ 3 ].dispatchEvent(
				new window.MouseEvent( 'click' )
			);
		} );
		expect( first ).not.toHaveBeenCalled();
		expect( second ).toHaveBeenCalledWith( 'req-delta', 7 );
		unmount();
	} );

	it( 'handles single-request data (trend-line branch falsy)', () => {
		// Only 1 valid request → chartData.length === 1 → skip trend-line.
		const { container, unmount } = mountChart( [ REQUESTS[ 1 ] ] );

		expect( dots( container ) ).toHaveLength( 1 );
		expect(
			container.querySelector( 'path[stroke="#4a90d9"]' )
		).toBeNull();
		unmount();
	} );

	it( 'resize handler is a no-op when there is no data on the next tick', () => {
		jest.useFakeTimers();
		resizeObserverCb = null;
		const { container, unmount, rerender } = mountChart( REQUESTS );
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
		expect( container.querySelector( 'svg' ) ).toBeNull();

		unmount();
		jest.useRealTimers();
	} );

	it( 're-fits when its own container resizes, not just the window', () => {
		// The chart sits inside the URL detail panel, which resizes without the
		// window ever doing so — opening the panel, or the flame graph above it
		// growing, left the plot drawn to a width that no longer existed.
		jest.useFakeTimers();
		resizeObserverCb = null;
		const { container, unmount } = mountChart( REQUESTS );
		expect( resizeObserverCb ).toEqual( expect.any( Function ) );

		const before = container.querySelector( 'svg' );
		act( () => {
			resizeObserverCb( [
				{ contentRect: { width: 700, height: 220 } },
			] );
			jest.advanceTimersByTime( 300 );
		} );

		// A refit redraws from scratch, so the old frame is gone entirely.
		expect( container.querySelector( 'svg' ) ).not.toBe( before );
		expect( dots( container ) ).toHaveLength( 4 );
		unmount();
		jest.useRealTimers();
	} );

	it( 'clears the container before each redraw', () => {
		// One render path draws everything, so it must start from an empty
		// container: leaving the last pass in place stacked a second <svg>
		// under the first on every data refresh.
		const { container, rerender, unmount } = mountChart( REQUESTS );

		rerender(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS.slice( 0, 2 ),
				onRequestClick: jest.fn(),
			} )
		);

		expect( container.querySelectorAll( 'svg' ) ).toHaveLength( 1 );
		expect( dots( container ) ).toHaveLength( 2 );
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
