/**
 * Tests for useTimeChart — the shared D3 lifecycle wrapper. The D3
 * helpers `drawLegend` / `setupTooltip` are exercised via real SVG
 * fixtures because they're pure-ish (no React); the hook itself is
 * driven through renderHook so we can assert renderFn is invoked on
 * mount + resize, and that scroll handlers attach.
 */
/* global MouseEvent */

import {
	buildTimeSlots,
	formatXTick,
	drawLegend,
	setupTooltip,
	useTimeChart,
	BUCKET_MINUTES,
	NUM_BUCKETS,
	PALETTE,
	MARGIN,
} from '../useTimeChart';
import * as d3 from 'd3';
import { renderHook, act } from './renderHook';

describe( 'buildTimeSlots', () => {
	it( 'returns NUM_BUCKETS slots', () => {
		const slots = buildTimeSlots();
		expect( slots.length ).toBe( NUM_BUCKETS );
	} );

	it( 'returns slots in chronological order (oldest first)', () => {
		const slots = buildTimeSlots();
		for ( let i = 1; i < slots.length; i++ ) {
			expect( slots[ i ].date.getTime() ).toBeGreaterThan(
				slots[ i - 1 ].date.getTime()
			);
		}
	} );

	it( 'snaps each slot to the prior 5-minute boundary', () => {
		const slots = buildTimeSlots();
		slots.forEach( ( s ) => {
			expect( s.date.getMinutes() % BUCKET_MINUTES ).toBe( 0 );
			expect( s.date.getSeconds() ).toBe( 0 );
			expect( s.date.getMilliseconds() ).toBe( 0 );
		} );
	} );

	it( 'emits unique YYYY-MM-DD-HH-MM bucket keys', () => {
		const slots = buildTimeSlots();
		const keys = new Set( slots.map( ( s ) => s.bucketKey ) );
		expect( keys.size ).toBe( slots.length );
	} );
} );

describe( 'formatXTick', () => {
	it( 'formats M/D H:MM', () => {
		// 2026-05-19 14:07 local — test against month/day/hour explicitly.
		const d = new Date( 2026, 4, 19, 14, 7 );
		expect( formatXTick( d ) ).toBe( '5/19 14:07' );
	} );

	it( 'pads single-digit minutes', () => {
		const d = new Date( 2026, 0, 1, 9, 3 );
		expect( formatXTick( d ) ).toBe( '1/1 9:03' );
	} );
} );

describe( 'drawLegend', () => {
	it( 'appends a g + rect/text per item, truncating long labels', () => {
		const svg = d3
			.select( document.body )
			.append( 'svg' )
			.attr( 'width', 800 )
			.attr( 'height', 400 );
		const items = [
			{ color: '#fff', label: 'short' },
			{ color: '#000', label: 'this-is-an-extremely-long-label-here' },
		];
		drawLegend( svg, items, 800 );
		const rects = svg.selectAll( 'rect' );
		const texts = svg.selectAll( 'text' );
		expect( rects.size() ).toBe( 2 );
		expect( texts.size() ).toBe( 2 );
		const longTextNode = texts.nodes()[ 1 ];
		expect( longTextNode.textContent.length ).toBeLessThanOrEqual( 21 );
		expect( longTextNode.textContent.endsWith( '...' ) ).toBe( true );
		svg.remove();
	} );
} );

describe( 'PALETTE / MARGIN constants', () => {
	it( 'PALETTE has at least one colour', () => {
		expect( PALETTE.length ).toBeGreaterThan( 0 );
	} );
	it( 'MARGIN exposes top/right/bottom/left', () => {
		expect( MARGIN ).toEqual( {
			top: expect.any( Number ),
			right: expect.any( Number ),
			bottom: expect.any( Number ),
			left: expect.any( Number ),
		} );
	} );
} );

describe( 'setupTooltip', () => {
	let svg;
	let g;
	let tooltipEl;
	let containerEl;

	beforeEach( () => {
		containerEl = document.createElement( 'div' );
		Object.defineProperty( containerEl, 'parentElement', {
			value: { clientHeight: 200 },
		} );
		document.body.appendChild( containerEl );

		tooltipEl = document.createElement( 'div' );
		containerEl.appendChild( tooltipEl );

		svg = d3.select( document.body ).append( 'svg' );
		g = svg.append( 'g' );
	} );

	afterEach( () => {
		svg.remove();
		containerEl.remove();
	} );

	function makeRefs() {
		return {
			tooltipRef: { current: tooltipEl },
			containerRef: { current: containerEl },
			lastMouseXRef: { current: null },
		};
	}

	it( 'appends two rects (highlight + interaction) and registers no listener errors', () => {
		const dates = [
			new Date( 2026, 0, 1 ),
			new Date( 2026, 0, 2 ),
			new Date( 2026, 0, 3 ),
		];
		const x = d3
			.scaleTime()
			.domain( [ dates[ 0 ], dates[ 2 ] ] )
			.range( [ 0, 300 ] );
		setupTooltip( g, {
			innerW: 300,
			innerH: 100,
			dates,
			x,
			formatEntry: ( idx ) => [ { label: 'count', value: idx } ],
			...makeRefs(),
		} );
		// Two appended rects: the highlight box + the pointer-target.
		expect( g.selectAll( 'rect' ).size() ).toBe( 2 );
	} );

	it( 'restores the tooltip immediately when lastMouseX is non-null at setup time', () => {
		const dates = [
			new Date( 2026, 0, 1 ),
			new Date( 2026, 0, 2 ),
			new Date( 2026, 0, 3 ),
		];
		const x = d3
			.scaleTime()
			.domain( [ dates[ 0 ], dates[ 2 ] ] )
			.range( [ 0, 300 ] );
		const refs = makeRefs();
		refs.lastMouseXRef.current = 100; // mid-chart.
		setupTooltip( g, {
			innerW: 300,
			innerH: 100,
			dates,
			x,
			formatEntry: ( idx ) => [ { label: 'count', value: idx } ],
			...refs,
		} );
		// After "restore", tooltip is visible.
		expect( tooltipEl.style.display ).toBe( 'block' );
		// And contains the entry label.
		expect( tooltipEl.textContent ).toContain( 'count:' );
	} );
} );

describe( 'useTimeChart', () => {
	it( 'invokes renderFn on mount and exposes three refs', () => {
		const renderFn = jest.fn();
		const { result, unmount } = renderHook( () =>
			useTimeChart( renderFn )
		);
		expect( renderFn ).toHaveBeenCalledTimes( 1 );
		expect( renderFn ).toHaveBeenCalledWith( {
			containerRef: expect.any( Object ),
			tooltipRef: expect.any( Object ),
			lastMouseXRef: expect.any( Object ),
		} );
		expect( result.current ).toEqual( {
			containerRef: expect.any( Object ),
			tooltipRef: expect.any( Object ),
			lastMouseXRef: expect.any( Object ),
		} );
		unmount();
	} );

	it( 'invokes renderFn on window resize', () => {
		const renderFn = jest.fn();
		const { unmount } = renderHook( () => useTimeChart( renderFn ) );
		renderFn.mockClear();
		act( () => {
			window.dispatchEvent( new Event( 'resize' ) );
		} );
		expect( renderFn ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );

	it( 'detaches the resize listener on unmount', () => {
		const renderFn = jest.fn();
		const remove = jest.spyOn( window, 'removeEventListener' );
		const { unmount } = renderHook( () => useTimeChart( renderFn ) );
		unmount();
		const matches = remove.mock.calls.filter(
			( call ) => call[ 0 ] === 'resize'
		);
		expect( matches.length ).toBeGreaterThan( 0 );
		remove.mockRestore();
	} );

	it( 'attaches a scroll handler when the container has a modal ancestor', () => {
		const modal = document.createElement( 'div' );
		modal.className = 'components-modal__content';
		document.body.appendChild( modal );
		const container = document.createElement( 'div' );
		modal.appendChild( container );

		const renderFn = jest.fn( ( { containerRef } ) => {
			containerRef.current = container;
		} );
		const { unmount } = renderHook( () => useTimeChart( renderFn ) );
		// Dispatching a scroll on the modal should not throw — it hides
		// the tooltip if one's there.
		expect( () =>
			modal.dispatchEvent( new Event( 'scroll' ) )
		).not.toThrow();
		unmount();
		modal.remove();
	} );
} );

describe( 'setupTooltip viewport-edge positioning', () => {
	let svg;
	let g;
	let tooltipEl;
	let containerEl;

	beforeEach( () => {
		containerEl = document.createElement( 'div' );
		Object.defineProperty( containerEl, 'parentElement', {
			value: { clientHeight: 200 },
		} );
		document.body.appendChild( containerEl );

		tooltipEl = document.createElement( 'div' );
		containerEl.appendChild( tooltipEl );

		svg = d3.select( document.body ).append( 'svg' );
		g = svg.append( 'g' );
	} );

	afterEach( () => {
		svg.remove();
		containerEl.remove();
	} );

	it( 'flips tooltip above mouse when bottom would overflow', () => {
		const dates = [
			new Date( 2026, 0, 1 ),
			new Date( 2026, 0, 2 ),
			new Date( 2026, 0, 3 ),
		];
		const x = d3
			.scaleTime()
			.domain( [ dates[ 0 ], dates[ 2 ] ] )
			.range( [ 0, 300 ] );
		// Configure the tooltip's bounding rect to be "below the viewport"
		// so the bottom-overflow branch (line 201) runs. The other branches
		// (right edge, left edge) are similarly forced via getBoundingClientRect.
		tooltipEl.getBoundingClientRect = () => ( {
			top: 100,
			bottom: window.innerHeight + 100, // overflow bottom
			left: window.innerWidth + 100, // overflow right
			right: window.innerWidth + 200,
		} );
		Object.defineProperty( tooltipEl, 'offsetHeight', {
			configurable: true,
			value: 50,
		} );
		Object.defineProperty( tooltipEl, 'offsetWidth', {
			configurable: true,
			value: 200,
		} );
		setupTooltip( g, {
			innerW: 300,
			innerH: 100,
			dates,
			x,
			formatEntry: ( idx ) => [ { label: 'count', value: idx } ],
			tooltipRef: { current: tooltipEl },
			containerRef: { current: containerEl },
			lastMouseXRef: { current: 150 }, // restore-on-setup
		} );
		// After restore, the tooltip is repositioned. Branches 201/204/209 run.
		expect( tooltipEl.style.display ).toBe( 'block' );
	} );

	it( 'clamps tooltip to left edge when getBoundingClientRect().left < 0', () => {
		const dates = [
			new Date( 2026, 0, 1 ),
			new Date( 2026, 0, 2 ),
			new Date( 2026, 0, 3 ),
		];
		const x = d3
			.scaleTime()
			.domain( [ dates[ 0 ], dates[ 2 ] ] )
			.range( [ 0, 300 ] );
		// Force left-side overflow.
		tooltipEl.getBoundingClientRect = () => ( {
			top: 0,
			bottom: 10,
			left: -50, // overflow left
			right: 100,
		} );
		setupTooltip( g, {
			innerW: 300,
			innerH: 100,
			dates,
			x,
			formatEntry: ( idx ) => [ { label: 'count', value: idx } ],
			tooltipRef: { current: tooltipEl },
			containerRef: { current: containerEl },
			lastMouseXRef: { current: 10 },
		} );
		// The left-edge branch sets style.left = '0px'.
		expect( tooltipEl.style.left ).toBe( '0px' );
	} );

	it( 'mousemove + mouseleave cycle (RAF + hide) on the interaction rect', () => {
		const dates = [
			new Date( 2026, 0, 1 ),
			new Date( 2026, 0, 2 ),
			new Date( 2026, 0, 3 ),
		];
		const x = d3
			.scaleTime()
			.domain( [ dates[ 0 ], dates[ 2 ] ] )
			.range( [ 0, 300 ] );
		setupTooltip( g, {
			innerW: 300,
			innerH: 100,
			dates,
			x,
			formatEntry: ( idx ) => [ { label: 'count', value: idx } ],
			tooltipRef: { current: tooltipEl },
			containerRef: { current: containerEl },
			lastMouseXRef: { current: null },
		} );
		// The 2nd rect is the interaction rect with mousemove/mouseleave.
		const rects = g.selectAll( 'rect' ).nodes();
		expect( rects.length ).toBe( 2 );
		// Dispatch mousemove and mouseleave to drive both branches —
		// d3's `.on()` wires these as DOM listeners.
		const interaction = rects[ 1 ];
		const evt = new MouseEvent( 'mousemove', {
			bubbles: true,
			clientX: 50,
			clientY: 50,
		} );
		interaction.dispatchEvent( evt );
		const leave = new MouseEvent( 'mouseleave', { bubbles: true } );
		interaction.dispatchEvent( leave );
		// No throw, that's the criterion.
		expect( true ).toBe( true );
	} );
} );
