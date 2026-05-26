/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * ErrorLog UI-surface tests — the thin view over the perferrors node graph.
 *
 * The graph is owned by useErrorLogGraph (tested separately); here we mock it to
 * hand back spy control callbacks, and we register a fixture `perferrors/view`
 * node in Core so the view can read its low-frequency model via useNodeState
 * ({ paused, connectionError, lastEventTime }) and its high-frequency buffer
 * (entries) directly off the node in the rAF. Mirrors RequestStream.test.js.
 */

jest.mock( '../hooks/useErrorLogGraph', () => ( {
	useErrorLogGraph: jest.fn(),
} ) );
jest.mock( '../../shared/hooks/useVirtualization', () => ( {
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
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

const { useErrorLogGraph } = require( '../hooks/useErrorLogGraph' );

// A minimal stand-in for the perferrors/view node: the low-frequency model lives
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
	Core.nodes.set( 'perferrors/view', node );
	return node;
}

function entry( overrides = {} ) {
	return {
		seq: 1,
		id: 1,
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
} );
