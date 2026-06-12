/**
 * ErrorLog UI-surface tests — the thin view over the perferrors node graph.
 *
 * The graph is owned by useErrorLogGraph (tested separately); here we mock it to
 * hand back spy control callbacks, and we register a fixture `perferrors:view`
 * node in Core so the view can read its low-frequency model via useNodeState
 * ({ paused, connectionError, lastEventTime }) and its high-frequency buffer
 * (entries) directly off the node in the rAF. Mirrors RequestStream.test.js.
 */

jest.mock( '../hooks/useErrorLogGraph', () => ( {
	useErrorLogGraph: jest.fn(),
} ) );
jest.mock( '@newspack-nodes/shared/hooks/useVirtualization', () => ( {
	__esModule: true,
	default: ( _ref, _row, total ) => ( {
		startIndex: 0,
		endIndex: total,
		paddingTop: 0,
		paddingBottom: 0,
		offsetTop: 0,
		totalHeight: total * 33,
	} ),
} ) );

import * as React from 'react';
import { Core } from '@newspack-nodes/runtime';
import ErrorLog from '../ErrorLog';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useErrorLogGraph } = require( '../hooks/useErrorLogGraph' );

// A minimal stand-in for the perferrors:view node: the low-frequency model lives
// in setStateCache.view (what useNodeState subscribes to) and the high-frequency
// buffer lives directly on the instance (what the rAF reads). setState here
// notifies subscribers exactly like the real Node.setState.
function registerViewFixture( {
	paused = false,
	connectionError = false,
	lastEventTime = null,
	entries = [],
} = {} ) {
	const node = {
		registrations: { view: {} },
		setStateCache: {},
		entries,
		register( event, listener, cb ) {
			this.registrations[ event ][ listener ] = cb;
			if ( event in this.setStateCache ) {
				cb( this.setStateCache[ event ] );
			}
		},
		unregister( event, listener ) {
			delete this.registrations[ event ]?.[ listener ];
		},
		setState( event, payload ) {
			this.setStateCache[ event ] = payload;
			Object.values( this.registrations[ event ] || {} ).forEach(
				( cb ) => cb( payload )
			);
		},
	};
	node.setState( 'view', { paused, connectionError, lastEventTime } );
	Core.nodes.set( 'perferrors:view', node );
	return node;
}

// Production assigns each entry a unique `id` (perf-errors-view-node's
// entryCounter), and ErrorRow keys on it — so the factory must mint a unique id
// per call, else multi-entry fixtures collide on `id:1` (duplicate React keys).
let nextEntryId = 0;
function entry( overrides = {} ) {
	nextEntryId += 1;
	return {
		seq: 1,
		id: nextEntryId,
		rid: 'r1',
		ts: 1748960000,
		k: 'error',
		m: 'boom',
		isEven: false,
		...overrides,
	};
}

describe( 'ErrorLog', () => {
	let setPaused;
	let clear;
	let rafCbs;
	const mounted = [];

	beforeEach( () => {
		Core.reset();
		setPaused = jest.fn();
		clear = jest.fn();
		useErrorLogGraph.mockClear();
		useErrorLogGraph.mockReturnValue( { setPaused, clear } );

		// Capture rAF callbacks so a test can drive exactly one frame (the rAF
		// reads node.entries and pushes them into React state).
		rafCbs = [];
		global.requestAnimationFrame = ( cb ) => {
			rafCbs.push( cb );
			return rafCbs.length;
		};
		global.cancelAnimationFrame = () => {};
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount( props = {} ) {
		const r = renderComponent( React.createElement( ErrorLog, props ) );
		mounted.push( r );
		return r;
	}

	// Run a single queued animation frame.
	const tickFrame = () => {
		const cbs = rafCbs;
		rafCbs = [];
		act( () => cbs.forEach( ( cb ) => cb( performance.now() ) ) );
	};

	it( 'renders the Error Log heading', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toMatch( /Error Log/i );
	} );

	it( 'renders an "empty" message initially', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent.toLowerCase() ).toMatch(
			/no|empty|wait/i
		);
	} );

	it( 'renders entries read from the node buffer in the rAF', () => {
		registerViewFixture( {
			entries: [
				entry( { rid: 'rid_xyz', k: 'fatal_error', m: 'boom' } ),
			],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'rid_xyz' );
		expect( container.textContent ).toContain( 'fatal_error' );
		expect( container.textContent ).toContain( 'boom' );
	} );

	it( 'pause button reflects the view model and calls setPaused on click', () => {
		registerViewFixture( { paused: false } );
		const { container } = mount();
		const pauseBtn = container.querySelector(
			'.event-logger-error-log-btn'
		);
		expect( pauseBtn.textContent ).toContain( '⏸' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( true );
	} );

	it( 'pause button shows ▶ when the view model is paused', () => {
		registerViewFixture( { paused: true } );
		const { container } = mount();
		const pauseBtn = container.querySelector(
			'.event-logger-error-log-btn'
		);
		expect( pauseBtn.textContent ).toContain( '▶' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( false );
	} );

	it( 'Clear button calls the graph clear callback', () => {
		registerViewFixture( { entries: [ entry( { rid: 'r-foo' } ) ] } );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-foo' );
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => clearBtn.click() );
		expect( clear ).toHaveBeenCalled();
	} );

	it( 'shows the reconnect banner when the view model reports connectionError', () => {
		registerViewFixture( { connectionError: true } );
		const { container } = mount();
		expect(
			container.querySelector( '.newspack-nodes-connection-banner' )
		).toBeTruthy();
	} );

	it( 'does not show the reconnect banner when connected', () => {
		registerViewFixture( { connectionError: false } );
		const { container } = mount();
		expect(
			container.querySelector( '.newspack-nodes-connection-banner' )
		).toBeNull();
	} );

	it( 'filter input narrows matching entries', () => {
		registerViewFixture( {
			entries: [
				entry( { seq: 2, id: 2, rid: 'r2', k: 'warn', m: 'second' } ),
				entry( {
					seq: 1,
					id: 1,
					rid: 'r1',
					k: 'fatal_error',
					m: 'first',
				} ),
			],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r1' );
		expect( container.textContent ).toContain( 'r2' );
		const input = container.querySelector( 'input[type="text"]' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'warn' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		tickFrame();
		expect( container.textContent ).toContain( 'r2' );
		expect( container.textContent ).not.toContain( 'r1' );
	} );

	it( 'classifies error/warning/info keywords via CSS class', () => {
		registerViewFixture( {
			entries: [
				entry( { seq: 1, id: 1, rid: 'r_info', ts: 0, k: 'notice' } ),
				entry( {
					seq: 2,
					id: 2,
					rid: 'r_warn',
					ts: 1,
					k: 'something (warning)',
				} ),
				entry( {
					seq: 3,
					id: 3,
					rid: 'r_err',
					ts: 2,
					k: 'error',
				} ),
			],
		} );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.entry-keyword--error' )
		).toBeTruthy();
		expect(
			container.querySelector( '.entry-keyword--warning' )
		).toBeTruthy();
		expect(
			container.querySelector( '.entry-keyword--info' )
		).toBeTruthy();
		// formatTime(0 → falsy) yields the placeholder.
		expect( container.textContent ).toContain( '--:--:--' );
	} );

	it( 'falls back to an empty model when the view node is absent', () => {
		// No fixture registered — useNodeState yields undefined; the view must
		// still render (Waiting…) without throwing.
		const { container } = mount();
		expect( container.textContent.toLowerCase() ).toMatch(
			/wait|no|empty/i
		);
	} );

	it( 'keeps rendering the newest row after the buffer saturates its cap', () => {
		// At the cap the node rotates at constant length while the newest seq
		// climbs — change detection must key off seq, not length (the freeze bug).
		const rotated = ( top ) =>
			[ top, top - 1, top - 2 ].map( ( s ) =>
				entry( { seq: s, rid: `r-${ s }`, m: `m-${ s }` } )
			);
		const node = registerViewFixture( { entries: rotated( 3 ) } );
		const { container } = mount( { maxEntries: 3 } );
		tickFrame();
		expect( container.textContent ).toContain( 'r-3' );
		node.entries = rotated( 4 );
		tickFrame();
		expect( container.textContent ).toContain( 'r-4' );
		expect( container.textContent ).not.toContain( 'r-1' );
	} );

	it( 'applies the full one-row offset when a new row is committed at the top', () => {
		// Compensation lives in a useLayoutEffect keyed on committed entries, so
		// the offset lands in the same commit as the new row (no flicker).
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		const content = container.querySelector(
			'.event-logger-error-log-content'
		);
		expect( content.style.transform ).toBe( '' );
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		expect( content.style.transform ).toBe( 'translate3d(0,-33px,0)' );
	} );

	it( 'sources the staleness display from the _sse connector lastEventTime', () => {
		// Staleness now reflects CONNECTION liveness, owned by the shared _sse
		// connector — the rAF reads its lastEventTime, not the view node's.
		registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1', k: 'error', m: 'boom' } ) ],
		} );
		Core.nodes.set( '_sse', { lastEventTime: Date.now() - 5000 } );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.event-logger-error-log-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
	} );

	it( 'staleness is connection-driven, so a filter never affects it', () => {
		// Staleness reflects the _sse connection's liveness, not the displayed
		// rows — so a non-matching filter (which hides every row) still shows
		// "Xs ago" off the connector.
		registerViewFixture( { entries: [] } );
		Core.nodes.set( '_sse', { lastEventTime: Date.now() - 3000 } );
		const { container } = mount();
		tickFrame();
		const input = container.querySelector(
			'.event-logger-error-log-search'
		);
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'zzz-no-match' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		tickFrame();
		expect(
			container.querySelector( '.event-logger-error-log-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
	} );

	it( 'Clear keeps the live-stream staleness (connection still alive)', () => {
		// Clear empties the displayed rows, but the _sse connection is still
		// alive — so "Xs ago" must persist (Clear no longer touches staleness).
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		Core.nodes.set( '_sse', { lastEventTime: Date.now() - 8000 } );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.event-logger-error-log-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
		node.entries = [];
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => clearBtn.click() );
		tickFrame();
		expect(
			container.querySelector( '.event-logger-error-log-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
	} );

	it( 'resets "Xs ago" when an idle stream gets a heartbeat (connector lastEventTime advances)', () => {
		// An idle stream (no new rows) whose _sse lastEventTime advances on a
		// heartbeat must reset "Xs ago" — that is the whole point of sourcing
		// staleness from the connector.
		jest.useFakeTimers();
		registerViewFixture( { entries: [] } );
		Core.nodes.set( '_sse', { lastEventTime: Date.now() - 12000 } );
		const { container } = mount();
		tickFrame();
		// Advance the 1s display timer so the ticking "now" re-reads the ref.
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		const stats = container.querySelector(
			'.event-logger-error-log-stats'
		);
		expect( stats.textContent ).toMatch( /1[123]s ago/ );
		// A heartbeat advances the connector's lastEventTime to now — "Xs ago"
		// must reset to a small value instead of climbing past 12s.
		Core.node( '_sse' ).lastEventTime = Date.now();
		tickFrame();
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( stats.textContent ).toMatch( /[01]s ago/ );
		jest.useRealTimers();
	} );
} );
