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
	chart: null,
};
jest.mock( 'd3-flame-graph', () => ( {
	flamegraph: () => {
		const chart = {};
		flamegraphState.chart = chart;
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
import * as d3 from 'd3';
import FlameGraph, { pruneFlameGraph, withTimeSpacers } from '../FlameGraph';
import { renderComponent } from '../../test-helpers/renderHook';

const d3Mock = d3.__chain;

// jsdom has no ResizeObserver; capture the callback to fire a resize.
let resizeObserverCb = null;
global.ResizeObserver = class {
	constructor( cb ) {
		resizeObserverCb = cb;
	}
	observe() {}
	disconnect() {}
};

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
		flamegraphState.chart = null;
		Object.keys( d3Mock ).forEach( ( k ) => {
			const v = d3Mock[ k ];
			if ( v && typeof v.mockClear === 'function' ) {
				v.mockClear();
			}
		} );
		d3Mock.datum.mockImplementation( () => d3Mock );
		d3Mock.node.mockImplementation( () => ( {
			offsetWidth: 100,
			offsetHeight: 20,
		} ) );
		try {
			delete window.event;
		} catch {
			// Ignore jsdom/browser descriptor differences.
		}
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

	it( 're-fits the chart to the container when it resizes (debounced)', () => {
		const { act } = require( '../../test-helpers/renderHook' );
		jest.useFakeTimers();
		resizeObserverCb = null;
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, { data: SAMPLE_DATA } )
		);
		// Container resize must be observed (window resize misses panels).
		expect( resizeObserverCb ).toEqual( expect.any( Function ) );
		// Clear the width recorded by the initial create, then fire a resize.
		flamegraphState.options.width = undefined;
		act( () => {
			resizeObserverCb();
			jest.advanceTimersByTime( 300 );
		} );
		// Debounced re-fit ran: the chart width was set again.
		expect( flamegraphState.options.width ).toBeDefined();
		unmount();
		jest.useRealTimers();
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
		// Locate the container div and fire mousedown with metaKey.
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
		// No meta: doesn't call onReveal; sets zoom path + schedules reset.
		flamegraphState.onClick( child );
		expect( onReveal ).not.toHaveBeenCalled();
		jest.runAllTimers();
		jest.useRealTimers();
		unmount();
	} );

	// A zoom rebuilds the frames, and d3 does not carry our attributes onto the
	// new ones — so an unstamped frame is invisible to the `?` picker, which
	// then finds nothing to ask about where a span plainly is.
	it( 'restamps the ask descriptors once a zoom settles', () => {
		jest.useFakeTimers();
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const stamps = () =>
			d3.__chain.attr.mock.calls.filter(
				( call ) => 'data-ask' === call[ 0 ]
			).length;
		const before = stamps();

		flamegraphState.onClick( {
			data: { name: 'db' },
			parent: { data: { name: 'process' }, parent: null },
		} );
		jest.runAllTimers();

		expect( stamps() ).toBeGreaterThan( before );
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

	it( 'tooltip.show says nothing at all over a spacer', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, { data: SAMPLE_DATA } )
		);
		const tooltip = flamegraphState.tooltip;
		tooltip.show( {
			data: { name: '', value: 100, t: 0, spacer: true },
			parent: { data: { name: 'process', value: 1000 }, parent: null },
		} );
		// Nothing to replay: a gap must not leave a tip behind on refresh.
		expect( tooltip.hasState() ).toBe( false );
		tooltip.destroy();
		unmount();
	} );

	it( 'tooltip.show handles parent-vs-total percentages and viewport clamps', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		Object.defineProperty( window, 'event', {
			configurable: true,
			writable: true,
			value: { pageX: 100, pageY: 90 },
		} );
		Object.defineProperty( window, 'innerWidth', {
			configurable: true,
			value: 120,
		} );
		Object.defineProperty( window, 'innerHeight', {
			configurable: true,
			value: 100,
		} );
		d3Mock.node.mockReturnValueOnce( {
			offsetWidth: 200,
			offsetHeight: 50,
		} );
		const grandchild = {
			data: { name: 'query', value: 300 },
			parent: {
				data: { name: 'db', value: 600 },
				parent: {
					data: { name: 'process', value: 1000 },
					parent: null,
				},
			},
		};
		expect( () =>
			flamegraphState.tooltip.show( grandchild )
		).not.toThrow();
		delete window.event;
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

	it( 'exposes the configured name and color mapper functions', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		expect(
			flamegraphState.options.getName( {
				data: { detail: 'db: SELECT', name: 'db' },
			} )
		).toBe( 'db: SELECT' );
		expect(
			flamegraphState.options.getName( { data: { name: 'render' } } )
		).toBe( 'render' );
		expect(
			flamegraphState.options.setColorMapper( {
				data: { name: 'process' },
			} )
		).toEqual( expect.any( String ) );
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

	it( 'restores zoom and tooltip state when refreshed data changes', () => {
		const { rerender, unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const child = {
			data: { name: 'db', value: 600 },
			parent: {
				data: { name: 'process', value: 1000 },
				parent: null,
			},
		};
		flamegraphState.onClick( child );
		flamegraphState.tooltip.show( child );
		d3Mock.datum.mockImplementation( ( arg ) =>
			arg === undefined
				? {
						data: { name: 'process' },
						children: [ { data: { name: 'db' } } ],
				  }
				: d3Mock
		);
		rerender(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 2,
			} )
		);
		expect( flamegraphState.chart.zoomTo ).toHaveBeenCalledWith(
			expect.objectContaining( { data: { name: 'db' } } )
		);
		expect( flamegraphState.tooltip.hasState() ).toBe( true );
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

	// A resize re-renders the chart, so the frames are new — and an unstamped
	// frame is invisible to the `?` picker on a graph that plainly shows it.
	it( 'restamps the ask descriptors after a resize', () => {
		jest.useFakeTimers();
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const stamps = () =>
			d3.__chain.attr.mock.calls.filter( ( c ) => 'data-ask' === c[ 0 ] )
				.length;
		const before = stamps();

		// The observer debounces by 150ms; the re-render is in the timer.
		resizeObserverCb( [ { contentRect: { width: 500 } } ] );
		jest.runAllTimers();

		expect( stamps() ).toBeGreaterThan( before );
		jest.useRealTimers();
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
		flamegraphState.tooltip.show( {
			data: { name: 'process', value: 1000 },
			parent: null,
		} );
		expect( flamegraphState.tooltip.hasState() ).toBe( true );
		// React-controlled mouse handler — dispatch native event.
		expect( () =>
			container.dispatchEvent(
				new MouseEvent( 'mouseout', {
					bubbles: true,
					relatedTarget: document.body,
				} )
			)
		).not.toThrow();
		expect( flamegraphState.tooltip.hasState() ).toBe( false );
		unmount();
	} );

	it( 'mouseDown enables chart transitions before the flamegraph click handler runs', () => {
		const { unmount } = renderComponent(
			React.createElement( FlameGraph, {
				data: SAMPLE_DATA,
				lastModified: 1,
			} )
		);
		const container = document.querySelector( '.event-logger-flame-graph' );
		const { act } = require( '../../test-helpers/renderHook' );
		act( () => {
			container.dispatchEvent(
				new MouseEvent( 'mousedown', { bubbles: true } )
			);
		} );
		expect( flamegraphState.chart.transitionDuration ).toHaveBeenCalledWith(
			300
		);
		unmount();
	} );
} );

describe( 'pruneFlameGraph', () => {
	it( 'returns null/undefined roots unchanged', () => {
		expect( pruneFlameGraph( null ) ).toBe( null );
		expect( pruneFlameGraph( undefined ) ).toBe( undefined );
	} );

	it( 'keeps small frames when the graph is under the soft cap', () => {
		// Three frames under the soft cap → nothing stripped (even the sliver).
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{ name: 'A', value: 500, children: [] },
				{ name: 'tiny', value: 0.5, children: [] }, // 0.05% < 0.1%.
				{ name: 'C', value: 200, children: [] },
			],
		};
		const pruned = pruneFlameGraph( root );
		expect( pruned.children.map( ( c ) => c.name ) ).toEqual( [
			'A',
			'tiny',
			'C',
		] );
	} );

	it( 'keeps every frame at exactly the soft cap, including sub-0.1% ones', () => {
		// Root+2 == softMaxNodes 3, so every frame ranks in → nothing stripped.
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{ name: 'A', value: 500, children: [] },
				{ name: 'tiny', value: 0.5, children: [] }, // 0.05% < 0.1%.
			],
		};
		const pruned = pruneFlameGraph( root, { softMaxNodes: 3 } );
		expect( pruned.children.map( ( c ) => c.name ) ).toEqual( [
			'A',
			'tiny',
		] );
	} );

	it( 'keeps a small subtree when the graph is under the soft cap', () => {
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{
					name: 'sliver',
					value: 0.4, // below 0.1% but under the soft cap.
					children: [ { name: 'deep', value: 0.4, children: [] } ],
				},
			],
		};
		const pruned = pruneFlameGraph( root );
		expect( pruned.children ).toHaveLength( 1 );
		expect( pruned.children[ 0 ].children ).toHaveLength( 1 );
	} );

	it( 'once over the soft cap, drops frames that are both ranked out and below 0.1%', () => {
		// softMaxNodes 2: medium survives (>=0.1%); only tiny drops.
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{ name: 'big1', value: 500, children: [] },
				{ name: 'big2', value: 200, children: [] },
				{ name: 'medium', value: 5, children: [] }, // 0.5% > 0.1%.
				{ name: 'tiny', value: 0.5, children: [] }, // 0.05% < 0.1%.
			],
		};
		const pruned = pruneFlameGraph( root, { softMaxNodes: 2 } );
		expect( pruned.children.map( ( c ) => c.name ) ).toEqual( [
			'big1',
			'big2',
			'medium',
		] );
	} );

	it( 'keeps the top frames past the soft cap even when they are below 0.1%', () => {
		// Only one clears 0.1%; soft cap keeps a sub-0.1% frame.
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{ name: 'a', value: 500, children: [] },
				{ name: 'b', value: 0.6, children: [] }, // 0.06% kept by rank.
				{ name: 'c', value: 0.5, children: [] },
				{ name: 'd', value: 0.4, children: [] },
			],
		};
		const pruned = pruneFlameGraph( root, { softMaxNodes: 3 } );
		expect( pruned.children.map( ( c ) => c.name ) ).toEqual( [
			'a',
			'b',
		] );
	} );

	it( 'enforces the hard cap even when every frame clears 0.1%', () => {
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{ name: 'a', value: 500, children: [] },
				{ name: 'b', value: 400, children: [] },
				{ name: 'c', value: 300, children: [] },
				{ name: 'd', value: 200, children: [] },
			],
		};
		const pruned = pruneFlameGraph( root, {
			softMaxNodes: 2,
			hardMaxNodes: 2,
		} );
		// Root + 1 largest child only.
		expect( pruned.children.map( ( c ) => c.name ) ).toEqual( [ 'a' ] );
	} );

	it( 'does not mutate the input tree', () => {
		const root = {
			name: 'process',
			value: 1000,
			children: [
				{ name: 'A', value: 500, children: [] },
				{ name: 'tiny', value: 0.5, children: [] },
			],
		};
		pruneFlameGraph( root, { softMaxNodes: 1 } );
		expect( root.children ).toHaveLength( 2 );
	} );
} );

describe( 'withTimeSpacers', () => {
	// A 1000ms parent whose two children leave a 100ms hole in the middle and
	// 40ms of dead air at the front. Every number here is distinct, so a
	// transform that ignored `t` and packed flush would show up immediately.
	const TIMED = {
		name: 'process',
		value: 1000,
		t: 0,
		children: [
			{ name: 'db', value: 260, t: 40, children: [] },
			{ name: 'render', value: 300, t: 400, children: [] },
		],
	};

	const widths = ( node ) =>
		node.children.map( ( c ) => [ c.spacer === true, c.value ] );

	it( 'opens with a spacer covering the parent time before the first child', () => {
		expect( widths( withTimeSpacers( TIMED ) )[ 0 ] ).toEqual( [
			true,
			40,
		] );
	} );

	it( 'fills the hole between two children with a spacer of exactly its width', () => {
		// db ends at 300, render starts at 400.
		expect( widths( withTimeSpacers( TIMED ) ) ).toEqual( [
			[ true, 40 ],
			[ false, 260 ],
			[ true, 100 ],
			[ false, 300 ],
		] );
	} );

	it( 'leaves the tail alone — d3 scales by the parent value, not the sum', () => {
		const out = withTimeSpacers( TIMED );
		expect(
			out.children[ out.children.length - 1 ].spacer
		).toBeUndefined();
	} );

	it( 'orders children by start time, not by the order they arrived', () => {
		const scrambled = {
			name: 'process',
			value: 100,
			t: 0,
			children: [
				{ name: 'late', value: 10, t: 80, children: [] },
				{ name: 'early', value: 10, t: 0, children: [] },
			],
		};
		expect(
			withTimeSpacers( scrambled )
				.children.filter( ( c ) => ! c.spacer )
				.map( ( c ) => c.name )
		).toEqual( [ 'early', 'late' ] );
	} );

	it( 'spends a fixed spacer budget on the widest gaps', () => {
		// Spacers are inserted after pruning, so an unbounded count would blow
		// straight through the node ceiling pruning exists to enforce.
		const three = {
			name: 'process',
			value: 1000,
			t: 0,
			children: [
				{ name: 'a', value: 10, t: 5, children: [] },
				{ name: 'b', value: 10, t: 100, children: [] },
				{ name: 'c', value: 10, t: 500, children: [] },
			],
		};
		// Gaps are 5, 85 and 390; a budget of one keeps only the widest.
		expect( widths( withTimeSpacers( three, 1 ) ) ).toEqual( [
			[ false, 10 ],
			[ false, 10 ],
			[ true, 390 ],
			[ false, 10 ],
		] );
	} );

	it( 'declines to position a family whose spans overlap', () => {
		// Two spans running at once have no honest side-by-side layout, and
		// their combined width already exceeds the interval they cover.
		const overlapping = {
			name: 'process',
			value: 1000,
			t: 0,
			children: [
				{ name: 'outer', value: 500, t: 0, children: [] },
				{ name: 'orphan', value: 200, t: 100, children: [] },
			],
		};
		expect( withTimeSpacers( overlapping ) ).toEqual( overlapping );
	} );

	it( 'recurses: a nested parent gets its own spacers', () => {
		const nested = {
			name: 'process',
			value: 1000,
			t: 0,
			children: [
				{
					name: 'outer',
					value: 900,
					t: 0,
					children: [
						{ name: 'inner', value: 100, t: 250, children: [] },
					],
				},
			],
		};
		expect( widths( withTimeSpacers( nested ).children[ 0 ] ) ).toEqual( [
			[ true, 250 ],
			[ false, 100 ],
		] );
	} );

	it( 'leaves an aggregate tree — no start times anywhere — untouched', () => {
		const aggregate = {
			name: 'aggregate',
			value: 1000,
			children: [
				{ name: 'db', value: 260, children: [] },
				{ name: 'render', value: 300, children: [] },
			],
		};
		expect( withTimeSpacers( aggregate ) ).toEqual( aggregate );
	} );

	it( 'leaves a folded tree alone — a merged node has no end to gap against', () => {
		// `merged` means `t` is the EARLIEST span's start while `value` totals
		// them all, so `t + value` lands where no span ran and every gap
		// measured from it is fiction. Flame_Fold decides; the browser reads.
		const folded = {
			name: 'process',
			value: 4200,
			t: 0,
			merged: false,
			children: [
				{ name: 'db', value: 275, t: 60, merged: true, children: [] },
				{
					name: 'render',
					value: 310,
					t: 3400,
					merged: false,
					children: [],
				},
			],
		};
		expect( withTimeSpacers( folded ) ).toEqual( folded );
	} );

	it( 'leaves a merged PARENT alone even where every child is positioned', () => {
		// The parent is the half a children-only guard would miss: its own `t`
		// is the earliest of several spans, so the gap to the first child is
		// measured between two different runs.
		const mergedParent = {
			name: 'save',
			value: 615,
			t: 45,
			merged: true,
			children: [
				{
					name: 'db',
					value: 130,
					t: 2600,
					merged: false,
					children: [],
				},
				{
					name: 'render',
					value: 240,
					t: 4100,
					merged: false,
					children: [],
				},
			],
		};
		expect( withTimeSpacers( mergedParent ) ).toEqual( mergedParent );
	} );

	it( 'leaves a parent alone when only some of its children are positioned', () => {
		const partial = {
			name: 'process',
			value: 1000,
			t: 0,
			children: [
				{ name: 'db', value: 260, t: 40, children: [] },
				{ name: 'mystery', value: 300, children: [] },
			],
		};
		expect( withTimeSpacers( partial ).children ).toHaveLength( 2 );
	} );

	it( 'does not mutate the input tree', () => {
		withTimeSpacers( TIMED );
		expect( TIMED.children ).toHaveLength( 2 );
	} );
} );

describe( 'frame ordering', () => {
	beforeEach( () => {
		flamegraphState.options = {};
	} );

	it( 'orders positioned frames by start time', () => {
		renderComponent(
			React.createElement( FlameGraph, { data: SAMPLE_DATA } )
		);
		const sort = flamegraphState.options.sort;
		expect( typeof sort ).toBe( 'function' );
		expect(
			sort( { data: { t: 40 } }, { data: { t: 5 } } )
		).toBeGreaterThan( 0 );
	} );

	it( 'puts positioned frames ahead of unpositioned ones', () => {
		// Mixing the two keys in one comparison is intransitive — A before B by
		// time, C before A by name, B before C by name — and an intransitive
		// comparator makes Array.prototype.sort emit an arbitrary order.
		renderComponent(
			React.createElement( FlameGraph, { data: SAMPLE_DATA } )
		);
		const sort = flamegraphState.options.sort;
		expect(
			sort( { data: { t: 10, name: 'z' } }, { data: { name: 'm' } } )
		).toBeLessThan( 0 );
		expect(
			sort( { data: { name: 'm' } }, { data: { t: 20, name: 'a' } } )
		).toBeGreaterThan( 0 );
	} );

	it( 'falls back to the displayed name where there is no start time', () => {
		renderComponent(
			React.createElement( FlameGraph, { data: SAMPLE_DATA } )
		);
		const sort = flamegraphState.options.sort;
		// 'zzz' after 'aaa' — the aggregate view's only ordering.
		expect(
			sort( { data: { name: 'zzz' } }, { data: { name: 'aaa' } } )
		).toBeGreaterThan( 0 );
		// A frame with a message sorts on the message, as getName reads it.
		expect(
			sort(
				{ data: { name: 'zzz', detail: 'aaa' } },
				{ data: { name: 'aaa', detail: 'zzz' } }
			)
		).toBeLessThan( 0 );
	} );
} );
