/**
 * Both area charts draw one chart frame, so their legends must land alike.
 *
 * Real d3 against jsdom on purpose: the mocked-d3 suites resolve every
 * selection to one shared chainable, so they cannot see WHERE a legend lands.
 */

import { readFileSync } from 'fs';
import { resolve as resolvePath } from 'path';
import * as React from 'react';
import { MARGIN } from '@newspack-nodes/shared/hooks/useTimeChart';
import AggregateTimeChart from '../../AggregateTimeChart';
import CategoryTimeChart from '../../CategoryTimeChart';
import { renderComponent } from '../../../test-helpers/renderHook';

// jsdom reports clientWidth 0, so both charts fall back to this width.
const CHART_WIDTH = 800;

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
 * Sum every `translate()` between a node and the SVG root.
 *
 * @param {Element} node Node to locate.
 * @return {{x: number, y: number}} Offset from the SVG origin.
 */
function absoluteOffset( node ) {
	let x = 0;
	let y = 0;
	for ( let el = node; el && 'svg' !== el.tagName; el = el.parentNode ) {
		const match = /translate\(\s*([-\d.]+)\s*,\s*([-\d.]+)\s*\)/.exec(
			el.getAttribute( 'transform' ) || ''
		);
		if ( match ) {
			x += Number( match[ 1 ] );
			y += Number( match[ 2 ] );
		}
	}
	return { x, y };
}

/**
 * Locate the legend group by its 10x10 swatch and report where it sits.
 *
 * @param {Element} container Mounted chart container.
 * @return {{x: number, y: number}} Legend offset from the SVG origin.
 */
function legendOffset( container ) {
	const groups = [ ...container.querySelectorAll( 'svg g' ) ];
	const legend = groups.find( ( group ) =>
		[ ...group.children ].some(
			( child ) =>
				'rect' === child.tagName &&
				'10' === child.getAttribute( 'width' ) &&
				'10' === child.getAttribute( 'height' )
		)
	);
	return absoluteOffset( legend );
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
	const expected = { x: CHART_WIDTH - MARGIN.right + 10, y: MARGIN.top };

	it( 'places the aggregate legend inside the right margin', () => {
		const breakdownData = {
			[ bucketKeyNow() ]: { 'curl/8.7.1': { c: 137, s: 4213, m: 91 } },
		};
		const { container, unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				breakdownData,
				metric: 'volume',
				breakdown: 'ua',
			} )
		);

		expect( legendOffset( container ) ).toEqual( expected );
		unmount();
	} );

	it( 'places the category legend at the same offset', () => {
		const data = {
			[ bucketKeyNow() ]: { db: { c: 43, t: 2711 } },
		};
		const { container, unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data } )
		);

		// The panel draws three views; the first one answers for the frame.
		expect( legendOffset( container ) ).toEqual( expected );
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

		const areas = [ ...container.querySelectorAll( 'svg path' ) ].filter(
			( path ) => path.getAttribute( 'fill' )?.startsWith( '#' )
		);
		expect( areas ).toHaveLength( 2 );
		// The top band peaks at the stack total, which the axis pads by 1.1.
		const innerH = 280 - MARGIN.top - MARGIN.bottom;
		const ceiling = innerH * ( 1 - 1 / 1.1 );
		expect( highestPoint( areas[ 1 ] ) ).toBeCloseTo( ceiling, 3 );
		expect( highestPoint( areas[ 0 ] ) ).toBeGreaterThan( ceiling );
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
			[ bucketKeyNow() ]: { render: { c: 2, t: 6 } },
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
	it( 'draws its axes through the shared frame, not a private copy', () => {
		const source = readFileSync(
			resolvePath( __dirname, '../AreaTimeChart.js' ),
			'utf8'
		);
		expect( source ).toContain( 'drawAxes' );
		expect( source ).not.toContain( 'axisBottom' );
		expect( source ).not.toContain( 'axisLeft' );
	} );

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
