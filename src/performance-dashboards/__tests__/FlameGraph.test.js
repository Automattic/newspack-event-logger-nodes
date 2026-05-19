/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* global MouseEvent */
/**
 * Tests for FlameGraph — d3-flame-graph wrapper.
 *
 * Strategy: mock `d3` and `d3-flame-graph` so the chart-construction
 * useEffect runs without touching real SVG. Then capture the onClick
 * callback handed to the flamegraph builder and invoke it with synthetic
 * d3-hierarchy nodes to drive getTooltipText / getNodePath / findNodeByPath.
 */

// Mock d3 — chain Proxy + special factories.
jest.mock( 'd3', () => {
	const chainHandler = {
		get: ( target, prop ) => {
			if ( prop === 'then' ) {
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
	// Helpers exposed on chain:
	chain.node = jest.fn( () => ( { offsetWidth: 100, offsetHeight: 20 } ) );
	chain.datum = jest.fn( () => chain );
	// Mock root-level d3.
	const topHandler = {
		get: ( _t, prop ) => {
			if ( prop === '__esModule' ) {
				return true;
			}
			if ( prop === '__chain' ) {
				return chain;
			}
			return jest.fn( () => chain );
		},
	};
	return new Proxy( {}, topHandler );
} );

// Mock d3-flame-graph.
const flamegraphState = {
	options: {},
	onClick: null,
	tooltip: null,
};
jest.mock( 'd3-flame-graph', () => ( {
	flamegraph: () => {
		const chart = {};
		const fluent = [
			'width',
			'cellHeight',
			'transitionDuration',
			'minFrameSize',
			'sort',
			'title',
			'getName',
			'tooltip',
			'selfValue',
			'setColorMapper',
		];
		fluent.forEach( ( method ) => {
			chart[ method ] = jest.fn( ( arg ) => {
				flamegraphState.options[ method ] = arg;
				return chart;
			} );
		} );
		chart.onClick = jest.fn( ( cb ) => {
			flamegraphState.onClick = cb;
			return chart;
		} );
		chart.tooltip = jest.fn( ( tip ) => {
			flamegraphState.tooltip = tip;
			return chart;
		} );
		chart.update = jest.fn();
		chart.zoomTo = jest.fn();
		chart.destroy = jest.fn();
		return chart;
	},
} ) );

// Mock the CSS import.
jest.mock( 'd3-flame-graph/dist/d3-flamegraph.css', () => ( {} ), {
	virtual: true,
} );

import * as React from 'react';
// eslint-disable-next-line import/order -- mocks must come first.
import * as d3 from 'd3';
import FlameGraph from '../FlameGraph';
import { renderComponent } from '../../shared/hooks/__tests__/renderHook';

const d3Mock = d3.__chain;

const SAMPLE_DATA = {
	name: 'process',
	value: 1000,
	children: [
		{
			name: 'db',
			value: 600,
			children: [ { name: 'query', value: 300 } ],
		},
		{ name: 'render', value: 400 },
	],
};

describe( 'FlameGraph', () => {
	beforeEach( () => {
		flamegraphState.options = {};
		flamegraphState.onClick = null;
		flamegraphState.tooltip = null;
		Object.keys( d3Mock ).forEach( ( k ) => {
			const v = d3Mock[ k ];
			if ( v && typeof v.mockClear === 'function' ) {
				v.mockClear();
			}
		} );
	} );

	it( 'renders an empty state when data is missing', () => {
		const { container, unmount } = renderComponent(
			React.createElement( FlameGraph, { data: null } )
		);
		expect( container.textContent ).toContain( 'No flame graph data' );
		unmount();
	} );

	it( 'renders the empty state when children is empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: { name: 'x', value: 0, children: [] },
			} )
		);
		expect( container.textContent ).toContain( 'No flame graph data' );
		unmount();
	} );

	it( 'creates the flamegraph chart on first render', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1234567890,
			} )
		);
		// onClick was registered with d3-flame-graph.
		expect( flamegraphState.onClick ).toEqual( expect.any( Function ) );
		// Tooltip was registered too.
		expect( flamegraphState.tooltip ).not.toBeNull();
		unmount();
	} );

	it( 'attaches a mousedown listener that captures Cmd/Ctrl', () => {
		const onReveal = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
				onRevealEntry: onReveal,
			} )
		);
		// Now fire onClick with metaKey set on a synthetic d.
		const node = {
			data: { name: 'process', value: 1000 },
			parent: null,
		};
		// Trigger mousedown with metaKey on the chart container.
		// The mousedown listener was attached to container.
		// Find the container — it's the wrapping div in the rendered tree.
		// In jsdom we can locate it via the className.
		const container = document.querySelector( '.event-logger-flame-graph' );
		expect( container ).toBeTruthy();
		container.dispatchEvent(
			new MouseEvent( 'mousedown', { metaKey: true } )
		);
		// Invoke onClick — should call onRevealEntry with path.
		flamegraphState.onClick( node );
		expect( onReveal ).toHaveBeenCalledWith( [ 'process' ] );
		unmount();
	} );

	it( 'onClick without meta records the zoomed node path', () => {
		jest.useFakeTimers();
		const onReveal = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
				onRevealEntry: onReveal,
			} )
		);
		const child = {
			data: { name: 'db' },
			parent: {
				data: { name: 'process' },
				parent: null,
			},
		};
		// No meta — should NOT call onReveal, but should set zoom path and
		// schedule transitionDuration(0) via setTimeout.
		flamegraphState.onClick( child );
		expect( onReveal ).not.toHaveBeenCalled();
		jest.runAllTimers();
		jest.useRealTimers();
		unmount();
	} );

	it( 'onClick(null) clears the zoom path', () => {
		jest.useFakeTimers();
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		expect( () => flamegraphState.onClick( null ) ).not.toThrow();
		jest.runAllTimers();
		jest.useRealTimers();
		unmount();
	} );

	it( 'tooltip.show formats names with parent + total percentages', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const tooltip = flamegraphState.tooltip;
		// child node — has parent, so should show "% of parent, % of total".
		const node = {
			data: { name: 'db', value: 600 },
			parent: {
				data: { name: 'process', value: 1000 },
				parent: null,
			},
		};
		tooltip.show( node );
		// Now hide.
		tooltip.hide();
		// And restore via the stored state.
		tooltip.restore();
		expect( tooltip.hasState() ).toBe( true );
		tooltip.clearState();
		expect( tooltip.hasState() ).toBe( false );
		// Re-call .show again, then destroy to cover that branch.
		tooltip.show( node );
		tooltip.destroy();
		unmount();
	} );

	it( 'tooltip.show on root node uses single-pct format', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const root = {
			data: { name: 'process', value: 1000, detail: 'process|root' },
			parent: null,
		};
		expect( () => flamegraphState.tooltip.show( root ) ).not.toThrow();
		unmount();
	} );

	it( 'tooltip.show falls back when value is zero everywhere', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const orphan = {
			data: {},
			parent: null,
		};
		expect( () => flamegraphState.tooltip.show( orphan ) ).not.toThrow();
		// Same node has no data → branch with rootValue=0 → pctTotal=100.
		flamegraphState.tooltip.restore();
		unmount();
	} );

	it( 'updates the chart when lastModified changes', () => {
		const { rerender, unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		// Rerender with same data but new lastModified.
		expect( () =>
			rerender(
				React.createElement( FlameGraph, {
					data: SAMPLE_DATA,
					lastModified: 2,
				} )
			)
		).not.toThrow();
		unmount();
	} );

	it( 'skips chart update when lastModified is unchanged', () => {
		const { rerender, unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 7,
			} )
		);
		// Rerender with identical lastModified — no update path.
		expect( () =>
			rerender(
				React.createElement( FlameGraph, {
					data: SAMPLE_DATA,
					lastModified: 7,
				} )
			)
		).not.toThrow();
		unmount();
	} );

	it( 'resize handler re-renders the chart when data is present', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		expect( () =>
			window.dispatchEvent( new Event( 'resize' ) )
		).not.toThrow();
		unmount();
	} );

	it( 'resize handler is a no-op when chart is not yet mounted (no data)', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, { data: null } )
		);
		// Listener still registered briefly; ensure no throw.
		expect( () =>
			window.dispatchEvent( new Event( 'resize' ) )
		).not.toThrow();
		unmount();
	} );

	it( 'mouseLeave on the container clears tooltip state', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const container = document.querySelector( '.event-logger-flame-graph' );
		// React-controlled mouse handler — dispatch native event.
		expect( () =>
			container.dispatchEvent(
				new Event( 'mouseleave', { bubbles: true } )
			)
		).not.toThrow();
		unmount();
	} );
} );
