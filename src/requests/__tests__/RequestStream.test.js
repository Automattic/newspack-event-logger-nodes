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
import RequestStream from '../RequestStream';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useRequestLogGraph } = require( '../hooks/useRequestLogGraph' );

// requestlog:view stand-in: view model + high-freq buffer/rps on instance.
function registerViewFixture( {
	paused = false,
	connectionError = false,
	entries = [],
	rps = 0,
} = {} ) {
	const node = {
		registrations: { view: {} },
		setStateCache: {},
		entries,
		rps,
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

		// Capture rAF callbacks so a test can drive exactly one frame.
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
		const pauseBtn = container.querySelector( 'button.button' );
		expect( pauseBtn.textContent ).toContain( '⏸' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( true );
	} );

	it( 'pause button shows ▶ when the view model is paused', () => {
		registerViewFixture( { paused: true } );
		const { container } = mount();
		const pauseBtn = container.querySelector( 'button.button' );
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
			container.querySelector( '.newspack-nodes-column-picker' )
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
		const input = container.querySelector( '.newspack-nodes-search-input' );
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
			'.newspack-nodes-toolbar-stats__rps'
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
		// No fixture → useNodeState undefined; view still renders (Waiting…).
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

	it( 'renders the placeholder time string for entries with a falsy timestamp', () => {
		// Drives formatTime's !ts branch → renders --:--:--.--- for zero ts.
		registerViewFixture( {
			entries: [ entry( { rid: 'r-noT', timestamp: 0 } ) ],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-noT' );
		expect( container.textContent ).toContain( '--:--:--.---' );
	} );

	it( 'toggleColumn removes a checked column when its checkbox is clicked', () => {
		// Uncheck a visible column in the picker → its header disappears.
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		act( () => colsBtn.click() );
		// rid is in DEFAULT_COLUMNS, so its column header should be present.
		const headersBefore = Array.from(
			container.querySelectorAll(
				'.event-logger-request-stream-header-row [role="columnheader"]'
			)
		).map( ( n ) => n.textContent );
		expect( headersBefore ).toContain( 'Request ID' );
		const checkbox = container.querySelector( '#col-rid' );
		expect( checkbox ).toBeTruthy();
		expect( checkbox.checked ).toBe( true );
		act( () => checkbox.click() );
		const headersAfter = Array.from(
			container.querySelectorAll(
				'.event-logger-request-stream-header-row [role="columnheader"]'
			)
		).map( ( n ) => n.textContent );
		expect( headersAfter ).not.toContain( 'Request ID' );
	} );

	it( 'toggleColumn adds an unchecked column when its checkbox is clicked', () => {
		// Adding user_agent inserts it in COLUMNS order (IP..duration).
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		act( () => colsBtn.click() );
		const uaCheckbox = container.querySelector( '#col-user_agent' );
		expect( uaCheckbox ).toBeTruthy();
		expect( uaCheckbox.checked ).toBe( false );
		act( () => uaCheckbox.click() );
		const headers = Array.from(
			container.querySelectorAll(
				'.event-logger-request-stream-header-row [role="columnheader"]'
			)
		).map( ( n ) => n.textContent );
		expect( headers ).toContain( 'UA' );
		// Order check: UA sits between IP (remote_addr) and Duration.
		const ipIdx = headers.indexOf( 'IP' );
		const uaIdx = headers.indexOf( 'UA' );
		const durIdx = headers.indexOf( 'Duration' );
		expect( ipIdx ).toBeLessThan( uaIdx );
		expect( uaIdx ).toBeLessThan( durIdx );
	} );

	it( 'sources the staleness display from the link connector lastEventTime', () => {
		// Staleness = connection liveness; rAF reads the link's lastEventTime.
		registerViewFixture( {
			entries: [ entry( { rid: 'r-stale' } ) ],
		} );
		Core.nodes.set( 'requestlog:link', {
			lastEventTime: () => Date.now() - 5000,
		} );
		const { container } = mount();
		tickFrame();
		// Find a sibling span in stats whose text matches "Xs ago".
		const stats = container.querySelector(
			'.newspack-nodes-toolbar-stats'
		);
		expect( stats ).toBeTruthy();
		expect( stats.textContent ).toMatch( /\d+s ago/ );
	} );

	it( 'scrolling away from top and back restores the saved animation offset', () => {
		// Scroll away saves offsetRef; back-to-top restores it.
		registerViewFixture( {
			entries: Array.from( { length: 20 }, ( _, i ) =>
				entry( { seq: i + 1, rid: `r-${ i }` } )
			),
		} );
		const { container } = mount();
		tickFrame();
		const list = container.querySelector(
			'.event-logger-request-stream-list'
		);
		expect( list ).toBeTruthy();
		// Away from top → save branch (offsetRef saved, then zeroed).
		act( () => {
			list.scrollTop = 500;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		// Back to top — fires restore branch.
		act( () => {
			list.scrollTop = 0;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		// Component must still be alive and rendering entries.
		expect( container.textContent ).toContain( 'r-0' );
	} );

	it( 'rAF maintains scroll position when buffer grows and the list is scrolled down', () => {
		// rAF bumps scrollTop for newly prepended entries (scroll preserved).
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		const list = container.querySelector(
			'.event-logger-request-stream-list'
		);
		// Scroll down so isAtTop is false on the next frame.
		act( () => {
			list.scrollTop = 500;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		const before = list.scrollTop;
		// New buffer entry → next frame bumps scrollTop by ROW_HEIGHT.
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		expect( list.scrollTop ).toBeGreaterThan( before );
	} );

	it( 'rAF snaps the smooth-scroll offset to zero when it has decayed past the threshold', () => {
		// Once |offset| < 0.5 the next frame snaps to 0, clears transform.
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		// First frame establishes content / list refs.
		tickFrame();
		const content = container.querySelector(
			'.event-logger-request-stream-content'
		);
		// Row at top → offsetRef = -33; later frames decay it to zero.
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		// Drive enough frames for |offset| to decay below 0.5 (~1%/frame).
		for ( let i = 0; i < 800; i++ ) {
			tickFrame();
		}
		// After the snap, the transform must be cleared and offset is zero.
		expect( content.style.transform ).toBe( '' );
	} );

	it( 'keeps rendering the newest row after the buffer saturates its cap', () => {
		// At the cap the buffer rotates; key change off newest seq, not length.
		const rotated = ( top ) =>
			[ top, top - 1, top - 2 ].map( ( s ) =>
				entry( { seq: s, rid: `r-${ s }`, url: `/u/${ s }` } )
			);
		const node = registerViewFixture( { entries: rotated( 3 ) } );
		const { container } = mount( { maxEntries: 3 } );
		tickFrame();
		expect( container.textContent ).toContain( 'r-3' );
		// Buffer rotates: length still 3, newest seq is now 4, oldest (1) gone.
		node.entries = rotated( 4 );
		tickFrame();
		expect( container.textContent ).toContain( 'r-4' );
		expect( container.textContent ).not.toContain( 'r-1' );
	} );

	it( 'applies the full one-row offset when a new row is committed at the top', () => {
		// Offset lands in the same commit as the new row (no flicker).
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		tickFrame(); // baseline render — no scroll for the first row.
		const content = container.querySelector(
			'.event-logger-request-stream-content'
		);
		expect( content.style.transform ).toBe( '' );
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		expect( content.style.transform ).toBe( 'translate3d(0,-33px,0)' );
	} );

	it( 'staleness is connection-driven, so a filter never affects it', () => {
		// Connection-driven staleness: a non-matching filter keeps "Xs ago".
		registerViewFixture( { entries: [] } );
		Core.nodes.set( 'requestlog:link', {
			lastEventTime: () => Date.now() - 3000,
		} );
		const { container } = mount();
		tickFrame();
		const input = container.querySelector( '.newspack-nodes-search-input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'zzz-no-match' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		tickFrame();
		const stats = container.querySelector(
			'.newspack-nodes-toolbar-stats'
		);
		expect( stats.textContent ).toMatch( /\d+s ago/ );
	} );

	it( 'Clear keeps the live-stream staleness (connection still alive)', () => {
		// Clear empties rows but the connection lives → "Xs ago" persists.
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		Core.nodes.set( 'requestlog:link', {
			lastEventTime: () => Date.now() - 8000,
		} );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.newspack-nodes-toolbar-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
		// Clear empties the log; the node buffer empties too.
		node.entries = [];
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => clearBtn.click() );
		tickFrame();
		expect(
			container.querySelector( '.newspack-nodes-toolbar-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
	} );

	it( 'resets "Xs ago" when an idle stream gets a heartbeat (connector lastEventTime advances)', () => {
		// Idle stream: a heartbeat advancing lastEventTime resets "Xs ago".
		jest.useFakeTimers();
		registerViewFixture( { entries: [] } );
		Core.nodes.set( 'requestlog:link', {
			lastEventTime: () => Date.now() - 12000,
		} );
		const { container } = mount();
		tickFrame();
		// Advance the 1s display timer so the ticking "now" re-reads the ref.
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		const stats = container.querySelector(
			'.newspack-nodes-toolbar-stats'
		);
		expect( stats.textContent ).toMatch( /1[123]s ago/ );
		// Heartbeat advances lastEventTime → "Xs ago" resets, not past 12s.
		Core.node( 'requestlog:link' ).lastEventTime = () => Date.now();
		tickFrame();
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( stats.textContent ).toMatch( /[01]s ago/ );
		jest.useRealTimers();
	} );

	it( 'baselines the first row after Clear (no slide), then slides the next', () => {
		// Post-clear: first row baselines (no slide); the next one slides.
		const node = registerViewFixture( {
			entries: [
				entry( { seq: 3, rid: 'r-3' } ),
				entry( { seq: 2, rid: 'r-2' } ),
				entry( { seq: 1, rid: 'r-1' } ),
			],
		} );
		const { container } = mount();
		tickFrame();
		const content = container.querySelector(
			'.event-logger-request-stream-content'
		);
		node.entries = [];
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => clearBtn.click() );
		tickFrame();
		expect( content.style.transform ).toBe( '' );
		// First post-clear row — baseline, no slide.
		node.entries = [ entry( { seq: 1, rid: 'a' } ) ];
		tickFrame();
		expect( content.style.transform ).toBe( '' );
		// Second post-clear row — slides.
		node.entries = [
			entry( { seq: 2, rid: 'b' } ),
			entry( { seq: 1, rid: 'a' } ),
		];
		tickFrame();
		expect( content.style.transform ).toBe( 'translate3d(0,-33px,0)' );
	} );
} );
