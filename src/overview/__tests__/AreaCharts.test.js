/**
 * Both area charts draw one chart frame, so their legends must land alike.
 *
 * Real d3 against jsdom on purpose: the mocked-d3 suites resolve every
 * selection to one shared chainable, so they cannot see WHERE a legend lands.
 */

import * as React from 'react';
import { MARGIN } from '@newspack-nodes/shared/hooks/useTimeChart';
import AggregateTimeChart from '../AggregateTimeChart';
import CategoryTimeChart from '../CategoryTimeChart';
import { renderComponent, act } from '../../test-helpers/renderHook';
import { STATUS_COLORS } from '@newspack-nodes/shared/utils/formatUtils';

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

/**
 * Smallest y coordinate in a path's `d`, i.e. how far up it reaches.
 *
 * @param {Element} path Path element.
 * @return {number} Highest point on the path.
 */
function highestPoint( path ) {
	const pairs = [
		...path.getAttribute( 'd' ).matchAll( /(-?[\d.]+),(-?[\d.]+)/g ),
	];
	return Math.min( ...pairs.map( ( pair ) => Number( pair[ 2 ] ) ) );
}

/**
 * The value-axis tick labels of the chart whose heading matches.
 *
 * @param {Element} container Mounted chart container.
 * @param {string}  title     Chart heading to read the axis of.
 * @return {Array<string>} The left axis's rendered label text.
 */
function valueLabels( container, title ) {
	const panel = [ ...container.querySelectorAll( 'div' ) ].find(
		( div ) => div.querySelector( 'h3' )?.textContent === title
	);
	const left = [ ...panel.querySelectorAll( 'svg g g' ) ].find(
		( group ) =>
			! group.getAttribute( 'transform' ) &&
			group.querySelector( '.tick' )
	);
	return [ ...left.querySelectorAll( 'g.tick text' ) ].map(
		( text ) => text.textContent
	);
}

describe( 'area chart frame', () => {
	/**
	 * The legend rows beside the plot, in order.
	 *
	 * @param {Element} container Mounted chart container.
	 * @return {Array<Element>} Its legend rows.
	 */
	const legendRows = ( container ) => [
		...container.querySelectorAll( '.newspack-nodes-chart-legend li' ),
	];
	/**
	 * The areas the plot holds.
	 *
	 * @param {Element} container Mounted chart container.
	 * @return {Array<Element>} Its area paths.
	 */
	// Painted through `style`, never a presentation attribute: a rank's
	// `chartColor()` value is a `var()`, which only a style resolves.
	const areas = ( container ) =>
		[ ...container.querySelectorAll( 'svg path' ) ].filter( ( path ) =>
			/^(?:#|var\()/.test( path.style.fill )
		);

	it( 'legends the aggregate beside the plot, one whole label per series', () => {
		const breakdownData = {
			[ bucketKeyNow() ]: {
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36':
					{ c: 137, s: 4213, m: 91 },
				'curl/8.7.1': { c: 12, s: 400, m: 30 },
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);

		const row = container.querySelector( '.newspack-nodes-chart__row' );
		expect(
			row.querySelector( '.newspack-nodes-chart__plot svg' )
		).not.toBeNull();
		expect( legendRows( container ).map( ( r ) => r.textContent ) ).toEqual(
			[
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
				'curl/8.7.1',
			]
		);
		// No legend inside the plot's SVG any more.
		expect(
			container.querySelector(
				'.newspack-nodes-chart__plot svg rect[width="10"]'
			)
		).toBeNull();
		unmount();
	} );

	it( 'a picked series is drawn alone, and the axis rescales to it', () => {
		const breakdownData = {
			[ bucketKeyNow() ]: {
				'2xx': { c: 47, s: 5900 },
				'4xx': { c: 3, s: 300 },
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'volume',
				breakdown: 'status',
			} )
		);
		expect( areas( container ) ).toHaveLength( 2 );
		const innerH = 280 - MARGIN.top - MARGIN.bottom;
		const ceiling = innerH * ( 1 - 1 / 1.1 );

		act( () => {
			legendRows( container )[ 1 ].querySelector( 'button' ).click();
		} );
		const [ only ] = areas( container );
		expect( areas( container ) ).toHaveLength( 1 );
		// 4xx keeps its own colour and now peaks at the axis ceiling.
		expect( only.style.fill ).toBe( STATUS_COLORS[ '4xx' ] );
		expect( highestPoint( only ) ).toBeCloseTo( ceiling, 3 );
		expect(
			legendRows( container )[ 1 ]
				.querySelector( 'button' )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		unmount();
	} );

	it( "a picked series takes its own axis unit, not the full list's", () => {
		// A slow bot beside a 44ms series: full max 12s, picked max 44ms.
		const breakdownData = {
			[ bucketKeyNow() ]: {
				'SlowBot/1.0': { c: 1, s: 12000 },
				'curl/8.7.1': { c: 2, s: 88 },
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'avg',
				breakdown: 'ua',
			} )
		);
		act( () => {
			legendRows( container )[ 1 ].querySelector( 'button' ).click();
		} );
		const title = container.querySelector( 'h3' ).textContent;
		const labels = valueLabels( container, title );
		expect( labels ).toContain( '40ms' );
		expect( labels ).not.toContain( '0s' );
		unmount();
	} );

	it( 'legends the category chart the same way', () => {
		const data = {
			names: [ 'db', 'http' ],
			buckets: {
				[ bucketKeyNow() ]: [
					[ 0, 2711, 43, 43 ],
					[ 1, 900, 9, 9 ],
				],
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);
		// The panel draws three views; the first answers for the frame.
		expect( legendRows( container ).length ).toBeGreaterThanOrEqual( 2 );
		expect( legendRows( container )[ 0 ].textContent ).toBe( 'db' );
		unmount();
	} );

	it( 'stacks a second series on top of the first', () => {
		const breakdownData = {
			[ bucketKeyNow() ]: {
				'2xx': { c: 47, s: 5900 },
				'4xx': { c: 14, s: 1400 },
			},
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'cumulative',
				breakdown: 'status',
			} )
		);

		const bands = areas( container );
		expect( bands ).toHaveLength( 2 );
		// The top band peaks at the stack total, which the axis pads by 1.1.
		const innerH = 280 - MARGIN.top - MARGIN.bottom;
		const ceiling = innerH * ( 1 - 1 / 1.1 );
		expect( highestPoint( bands[ 1 ] ) ).toBeCloseTo( ceiling, 3 );
		expect( highestPoint( bands[ 0 ] ) ).toBeGreaterThan( ceiling );
		unmount();
	} );

	it( 'ticks a request-volume axis in whole requests', () => {
		const breakdownData = {
			[ bucketKeyNow() ]: { 'curl/8.7.1': { c: 3, s: 51 } },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);

		expect(
			valueLabels( container, 'Request Volume (Last 24 Hours)' )
		).toEqual( [ '0', '1', '2', '3' ] );
		unmount();
	} );

	it( 'labels a few-millisecond category average without repeating', () => {
		const data = {
			names: [ 'render' ],
			buckets: { [ bucketKeyNow() ]: [ [ 0, 6, 2, 2 ] ] },
		};
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);

		expect( valueLabels( container, 'Average Time per Event' ) ).toEqual( [
			'0',
			'500µs',
			'1ms',
			'1.5ms',
			'2ms',
			'2.5ms',
			'3ms',
		] );
		unmount();
	} );
} );

describe( 'chart frame', () => {
	it( 'still renders both axes and the rotated Y title', () => {
		const breakdownData = {
			[ bucketKeyNow() ]: { 'curl/8.7.1': { c: 61, s: 7300 } },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);
		const label = container.querySelector( 'svg text.y-label' );
		expect( label.getAttribute( 'transform' ) ).toBe( 'rotate(-90)' );
		expect( label.getAttribute( 'y' ) ).toBe( String( 0 - MARGIN.left ) );
		const ticks = [ ...container.querySelectorAll( 'svg g.tick' ) ];
		expect( ticks.length ).toBeGreaterThan( 0 );
		const rotated = ticks.filter(
			( tick ) =>
				tick.querySelector( 'text' )?.getAttribute( 'transform' ) ===
				'rotate(-45)'
		);
		expect( rotated.length ).toBeGreaterThan( 0 );
		unmount();
	} );
} );
