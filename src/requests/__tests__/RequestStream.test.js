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

// A minimal stand-in for the requestlog:view node: the low-frequency model lives
// in setStateCache.view (what useNodeState subscribes to) and the high-frequency
// buffer / rps live directly on the instance (what the rAF reads). setState here
// notifies subscribers exactly like the real Node.setState.
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

	it( 'renders the placeholder time string for entries with a falsy timestamp', () => {
		// Drives the `if ( ! ts )` branch in formatTime — the production code
		// renders `--:--:--.---` for any entry whose timestamp is missing/zero.
		registerViewFixture( {
			entries: [ entry( { rid: 'r-noT', timestamp: 0 } ) ],
		} );
		const { container } = mount();
		tickFrame();
		expect( container.textContent ).toContain( 'r-noT' );
		expect( container.textContent ).toContain( '--:--:--.---' );
	} );

	it( 'toggleColumn removes a checked column when its checkbox is clicked', () => {
		// Open the column picker, click an already-visible column's checkbox,
		// and verify the column header disappears (visibleColumns shrank).
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
		// user_agent is NOT in DEFAULT_COLUMNS — clicking it adds it, and it
		// must be inserted in the original COLUMNS order (between remote_addr
		// and duration). This drives the `allCols.filter(...)` add branch.
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
		// Staleness now reflects CONNECTION liveness, owned by the shared link
		// connector — the rAF reads its lastEventTime, not the view node's.
		registerViewFixture( {
			entries: [ entry( { rid: 'r-stale' } ) ],
		} );
		Core.nodes.set( 'requestlog:link', {
			lastEventTime: () => Date.now() - 5000,
		} );
		const { container } = mount();
		tickFrame();
		// Find any sibling span inside the stats element whose text matches "Xs ago".
		const stats = container.querySelector(
			'.newspack-nodes-toolbar-stats'
		);
		expect( stats ).toBeTruthy();
		expect( stats.textContent ).toMatch( /\d+s ago/ );
	} );

	it( 'scrolling away from top and back restores the saved animation offset', () => {
		// Drives the handleScroll save (away-from-top) + restore (back-to-top)
		// branches: scrolling down saves the current offsetRef and clears it;
		// scrolling back restores it. No throw + correct state mutation is the
		// visible signal (no public API surfaces offset).
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
		// Away from top — fires save branch (offsetRef → savedOffsetRef,
		// then offsetRef = 0, animOffsetRows = 0).
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
		// Drives the `else if ( list ) { list.scrollTop += visibleNewCount * ROW_HEIGHT }`
		// branch — the rAF compensates for newly prepended entries so the user's
		// scroll position is preserved.
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
		// Append a new entry to the node buffer — the next frame should bump
		// scrollTop by ROW_HEIGHT to compensate (and set the adjusting flag).
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		expect( list.scrollTop ).toBeGreaterThan( before );
	} );

	it( 'rAF snaps the smooth-scroll offset to zero when it has decayed past the threshold', () => {
		// Drives the `else if ( content && offsetRef.current !== 0 )` branch:
		// once the |offset| < 0.5 threshold is hit, the next frame snaps to
		// exactly 0 and clears the transform.
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		// First frame establishes content / list refs.
		tickFrame();
		const content = container.querySelector(
			'.event-logger-request-stream-content'
		);
		// Append a row at the top so visibleNewCount > 0 → offsetRef becomes
		// -33 (compensation). Subsequent frames decay it toward zero.
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		// Now drive enough frames for the |offset| to decay below 0.5
		// (decay is 1% per frame). A loop of ~700 frames is plenty.
		for ( let i = 0; i < 800; i++ ) {
			tickFrame();
		}
		// After the snap, the transform must be cleared and offset is zero.
		expect( content.style.transform ).toBe( '' );
	} );

	it( 'keeps rendering the newest row after the buffer saturates its cap', () => {
		// At the cap the node rotates: newest unshifted, oldest dropped, so
		// buffer.length is constant while the newest seq keeps climbing. Change
		// detection must key off the newest seq, not the length — otherwise the
		// view freezes at the cap (the "stops scrolling at 1000" bug).
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
		// The compensation lives in a useLayoutEffect keyed on the committed
		// entries, so the offset lands in the SAME commit as the new row (no
		// jump-then-correct flicker). At commit the offset is a full ROW_HEIGHT
		// up — the rAF only decays it on subsequent frames.
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
		// Staleness reflects the link connection's liveness, not the displayed
		// rows — so a non-matching filter (which hides every row) still shows
		// "Xs ago" off the connector.
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
		// Clear empties the displayed rows, but the link connection is still
		// alive — so "Xs ago" must persist (Clear no longer touches staleness).
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
		// An idle stream (no new rows) whose link lastEventTime advances on a
		// heartbeat must reset "Xs ago" — that is the whole point of sourcing
		// staleness from the connector.
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
		// A heartbeat advances the connector's lastEventTime to now — "Xs ago"
		// must reset to a small value instead of climbing past 12s.
		Core.node( 'requestlog:link' ).lastEventTime = () => Date.now();
		tickFrame();
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( stats.textContent ).toMatch( /[01]s ago/ );
		jest.useRealTimers();
	} );

	it( 'baselines the first row after Clear (no slide), then slides the next', () => {
		// After Clear the node counter resets, so post-clear seqs restart at 1.
		// The first row re-establishes the baseline and must NOT slide; the next
		// genuinely-new row slides in.
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
