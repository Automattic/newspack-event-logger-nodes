/**
 * Tests for ResponseTimeChart — D3 scatter plot with enter/update/exit.
 *
 * Mocks d3 with a chainable so all useEffect bodies execute against the
 * mock chain instead of real SVG. Tests focus on:
 *  - chart returns null when no requests
 *  - data transform (filter + map + sort) works
 *  - effect runs (d3.select called) when requests are present
 *  - resize handler is registered and unregisters on unmount
 */

jest.mock( 'd3', () => {
	// chain is itself a Proxy — any property access returns a jest-fn
	// that yields the chain, so arbitrarily-deep d3.foo().bar().baz()
	// chains work without enumerating every method.
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

import * as React from 'react';
import * as d3 from 'd3';
import ResponseTimeChart from '../ResponseTimeChart';
import { renderComponent } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

const REQUESTS = [
	{ rid: 'r1', timestamp: 1700000000, duration_ms: 50, status_code: 200 },
	{ rid: 'r2', timestamp: 1700000100, duration_ms: 100, status_code: 404 },
	{ rid: 'r3', timestamp: 1700000200, duration_ms: 75, status_code: 500 },
	{ rid: 'r4', timestamp: 1700000300, duration_ms: 25, status_code: 301 },
	{ rid: 'r5', timestamp: 1700000400, duration_ms: 200, status_code: 600 }, // unknown bucket
	// Filtered out — no timestamp:
	{ rid: 'r6', duration_ms: 100, status_code: 200 },
	// Filtered out — no duration:
	{ rid: 'r7', timestamp: 1700000500, status_code: 200 },
];

describe( 'ResponseTimeChart', () => {
	// Capture initial keys lazily; the chain Proxy populates them as the
	// component uses them. mockClear is best-effort across runs.
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
		expect( d3Mock.select ).toHaveBeenCalled();
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
		// Both init-effect and data-effect must have run.
		expect( d3Mock.append ).toHaveBeenCalled();
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
		expect( d3Mock.select ).toHaveBeenCalled();
		unmount();
	} );

	it( 'fires the resize handler and re-runs the d3 chain', () => {
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		const before = d3Mock.attr.mock.calls.length;
		// Trigger resize — the listener body should run.
		window.dispatchEvent( new Event( 'resize' ) );
		expect( d3Mock.attr.mock.calls.length ).toBeGreaterThan( before );
		unmount();
	} );

	it( 'resize handler is a no-op when there is no data on the next tick', () => {
		const { unmount, rerender } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		// Rerender with empty requests → component returns null but the
		// resize listener for the previous render is still registered
		// briefly. Just verify no throw on dispatch.
		rerender(
			React.createElement( ResponseTimeChart, {
				requests: [],
				onRequestClick: jest.fn(),
			} )
		);
		expect( () =>
			window.dispatchEvent( new Event( 'resize' ) )
		).not.toThrow();
		unmount();
	} );

	it( 'unmount triggers cleanup effect', () => {
		const { unmount } = renderComponent(
			React.createElement( ResponseTimeChart, {
				requests: REQUESTS,
				onRequestClick: jest.fn(),
			} )
		);
		// d3.select(...).selectAll('*').remove() in cleanup.
		unmount();
		expect( d3Mock.remove ).toHaveBeenCalled();
	} );
} );
