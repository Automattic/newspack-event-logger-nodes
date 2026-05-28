/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * RequestStream UI-surface tests — the thin view over the requestlog node graph.
 *
 * The graph is owned by useRequestLogGraph (tested separately); here we mock it to
 * hand back spy control callbacks, and we register a fixture `requestlog:view`
 * node in Core so the view can read its low-frequency model via useNodeState and
 * its high-frequency buffer (entries/rps/lastEventTime) directly off the node in
 * the rAF.
 */

jest.mock( '../hooks/useRequestLogGraph', () => ( {
	useRequestLogGraph: jest.fn(),
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
import RequestStream from '../RequestStream';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

const { useRequestLogGraph } = require( '../hooks/useRequestLogGraph' );

// A minimal stand-in for the requestlog:view node: the low-frequency model lives
// in setStateCache.view (what useNodeState subscribes to) and the high-frequency
// buffer / rps / lastEventTime live directly on the instance (what the rAF reads).
// setState here notifies subscribers exactly like the real Node.setState.
function registerViewFixture( {
	paused = false,
	connectionError = false,
	entries = [],
	rps = 0,
	lastEventTime = null,
} = {} ) {
	const node = {
		registrations: { view: {} },
		setStateCache: {},
		entries,
		rps,
		lastEventTime,
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
	node.setState( 'view', { paused, connectionError } );
	Core.nodes.set( 'requestlog:view', node );
	return node;
}

function entry( overrides = {} ) {
	return {
		seq: 1,
		rid: 'r1',
		url: '/foo',
		urlHash: 'abc123',
		method: 'GET',
		duration_ms: 50,
		status_code: 200,
		timestamp: 1748960000,
		remote_addr: '10.0.0.1',
		user_agent: 'curl/7',
		isEven: false,
		...overrides,
	};
}

describe( 'RequestStream', () => {
	let setPaused;
	let clear;
	let rafCbs;
	const mounted = [];

	beforeEach( () => {
		Core.reset();
		window.localStorage.clear();
		setPaused = jest.fn();
		clear = jest.fn();
		useRequestLogGraph.mockClear();
		useRequestLogGraph.mockReturnValue( { setPaused, clear } );

		// Capture rAF callbacks so a test can drive exactly one frame (the rAF
		// reads node.entries / node.rps and pushes them into React state).
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
		const r = renderComponent(
			React.createElement( RequestStream, { maxEntries: 100, ...props } )
		);
		mounted.push( r );
		return r;
	}

	// Run a single queued animation frame.
	const tickFrame = () => {
		const cbs = rafCbs;
		rafCbs = [];
		act( () => cbs.forEach( ( cb ) => cb( performance.now() ) ) );
	};

	it( 'renders the Request Log heading', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toMatch( /Request/i );
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
			entries: [ entry( { rid: 'r-flow', url: '/foo' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-flow' );
	} );

	it( 'pause button reflects the view model and calls setPaused on click', () => {
		registerViewFixture( { paused: false } );
		const { container } = mount();
		const pauseBtn = container.querySelector(
			'.event-logger-request-stream-btn'
		);
		expect( pauseBtn.textContent ).toContain( '⏸' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( true );
	} );

	it( 'pause button shows ▶ when the view model is paused', () => {
		registerViewFixture( { paused: true } );
		const { container } = mount();
		const pauseBtn = container.querySelector(
			'.event-logger-request-stream-btn'
		);
		expect( pauseBtn.textContent ).toContain( '▶' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( false );
	} );

	it( 'Clear button calls the graph clear callback', () => {
		registerViewFixture( {
			entries: [ entry( { rid: 'r-foo' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-foo' );
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => clearBtn.click() );
		expect( clear ).toHaveBeenCalled();
	} );

	it( 'toggles the column picker on Cols button click', () => {
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		expect( colsBtn ).toBeTruthy();
		act( () => colsBtn.click() );
		expect(
			container.querySelector(
				'.event-logger-request-stream-column-picker'
			)
		).toBeTruthy();
	} );

	it( 'filter input narrows URL-matching entries', () => {
		registerViewFixture( {
			entries: [
				entry( { seq: 2, rid: 'rB', url: '/baz', isEven: true } ),
				entry( { seq: 1, rid: 'rA', url: '/foo/bar' } ),
			],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'rA' );
		expect( container.textContent ).toContain( 'rB' );
		const input = container.querySelector(
			'.event-logger-request-stream-search'
		);
		expect( input ).toBeTruthy();
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'foo' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		tickFrame();
		expect( container.textContent ).toContain( 'rA' );
		expect( container.textContent ).not.toContain( 'rB' );
	} );

	it( 'renders user_agent and remote_addr columns when entry carries them', () => {
		window.localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( [
				'time',
				'rid',
				'url',
				'status',
				'remote_addr',
				'user_agent',
				'duration',
			] )
		);
		registerViewFixture( {
			entries: [
				entry( {
					rid: 'r-ua',
					url: '/x',
					user_agent: 'Mozilla/5.0',
					remote_addr: '192.168.1.1',
				} ),
			],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-ua' );
		expect( container.textContent ).toContain( 'Mozilla/5.0' );
		window.localStorage.removeItem( 'event-logger-stream-columns' );
	} );

	it( 'displays the requests/second read from the node in the rAF', () => {
		registerViewFixture( {
			entries: [ entry( { rid: 'r-rps' } ) ],
			rps: 4.2,
		} );
		const { container } = mount();
		tickFrame();
		const rps = container.querySelector(
			'.event-logger-request-stream-rps'
		);
		expect( rps ).not.toBeNull();
		expect( rps.textContent ).toMatch( /4\.2 req\/s/ );
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

	it( 'falls back to an empty model when the view node is absent', () => {
		// No fixture registered — useNodeState yields undefined; the view must
		// still render (Waiting…) without throwing.
		const { container } = mount();
		expect( container.textContent.toLowerCase() ).toMatch(
			/wait|no|empty/i
		);
	} );

	it( 'does not throw rendering an entry with no user_agent (defaults to "-")', () => {
		window.localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( [ 'time', 'rid', 'url', 'user_agent' ] )
		);
		registerViewFixture( {
			entries: [ entry( { rid: 'r-no-ua', user_agent: '' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-no-ua' );
		window.localStorage.removeItem( 'event-logger-stream-columns' );
	} );
} );
