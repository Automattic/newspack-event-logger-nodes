/**
 * ErrorLog UI-surface tests — the thin view over the perferrors node graph.
 *
 * The graph is owned by useErrorLogGraph (tested separately); here we mock it to
 * hand back spy control callbacks, and we register a fixture `perferrors:view`
 * node in Core so the view can read its low-frequency model via useNodeState
 * ({ paused, connectionError }) and its high-frequency buffer (entries) directly
 * off the node in the rAF. Mirrors RequestStream.test.js.
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

import fs from 'fs';
import path from 'path';
import * as React from 'react';
import { Core } from '@newspack-nodes/runtime';
import ErrorLog from '../ErrorLog';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useErrorLogGraph } = require( '../hooks/useErrorLogGraph' );

// Stand-in perferrors:view node: low-freq in setStateCache, buffer on instance.
function registerViewFixture( {
	paused = false,
	connectionError = false,
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
	node.setState( 'view', { paused, connectionError } );
	Core.nodes.set( 'perferrors:view', node );
	return node;
}

// Each entry needs a unique `id` (ErrorRow keys on it) or React keys collide.
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
		...overrides,
	};
}

describe( 'ErrorLog', () => {
	let setPaused;
	let clear;
	let setStreamFilter;
	let rafCbs;
	const mounted = [];

	beforeEach( () => {
		Core.reset();
		setPaused = jest.fn();
		clear = jest.fn();
		setStreamFilter = jest.fn();
		useErrorLogGraph.mockClear();
		useErrorLogGraph.mockReturnValue( {
			setPaused,
			clear,
			setFilter: setStreamFilter,
		} );

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

	it( 'renders the Request Log URL column with method and URL detail link', () => {
		registerViewFixture( {
			entries: [
				entry( {
					rid: 'url-context-rid-731',
					method: 'PATCH',
					url: '/error-context-731?errors-worker-731',
					urlHash: 'url-hash-731',
				} ),
			],
		} );
		const { container } = mount();
		tickFrame();

		const headers = Array.from(
			container.querySelectorAll( '[role="columnheader"]' )
		).map( ( header ) => header.textContent );
		expect( headers.slice( 0, 3 ) ).toEqual( [
			'Time',
			'Request ID',
			'URL',
		] );
		const link = container.querySelector( '.entry-url-link' );
		expect( link.textContent ).toBe(
			'/error-context-731?errors-worker-731'
		);
		expect( link.getAttribute( 'href' ) ).toBe(
			'admin.php?page=event-logger-overview&url=url-hash-731'
		);
		expect( link.parentElement.textContent ).toContain( 'PATCH' );
	} );

	it( 'keeps the URL and message columns proportional regardless of content length', () => {
		const longUrl = `/${ 'long-url-segment-731/'.repeat( 12 ) }`;
		const longMessage = 'long-message-segment-947 '.repeat( 12 ).trim();
		registerViewFixture( {
			entries: [
				entry( {
					seq: 2,
					rid: 'long-url-short-message-731',
					method: 'GET',
					url: longUrl,
					urlHash: 'long-url-hash-731',
					m: 'short-731',
				} ),
				entry( {
					seq: 1,
					rid: 'short-url-long-message-947',
					method: 'POST',
					url: '/s-947',
					urlHash: 'short-url-hash-947',
					m: longMessage,
				} ),
			],
		} );
		const { container } = mount();
		tickFrame();

		const rows = Array.from(
			container.querySelectorAll( '.event-logger-error-log-entry' )
		);
		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ].textContent ).toContain( longUrl );
		expect( rows[ 1 ].textContent ).toContain( longMessage );

		const expectedTemplate =
			'100px 240px minmax(0, 2fr) 240px minmax(0, 3fr)';
		const templates = [
			container.querySelector( '.event-logger-error-log-header-row' ),
			...rows,
		].map( ( row ) => row.style.gridTemplateColumns );
		expect( templates ).toEqual( [
			expectedTemplate,
			expectedTemplate,
			expectedTemplate,
		] );
		expect( templates.join( ' ' ) ).not.toContain( 'auto' );
	} );

	it( 'uses the canonical spacing token for horizontal row padding', () => {
		const styles = fs.readFileSync(
			path.join( __dirname, '..', 'styles', 'error-log.scss' ),
			'utf8'
		);
		const horizontalPaddings = [
			styles.match(
				/\.event-logger-error-log-header-row\s*\{[^}]*padding:\s*\d+px\s+(\S+)\s*;/
			)?.[ 1 ],
			styles.match(
				/\.event-logger-error-log-entry\s*\{[^}]*padding:\s*\d+px\s+(\S+)\s*;/
			)?.[ 1 ],
		];

		expect( horizontalPaddings ).toEqual( [
			'base.$space-md',
			'base.$space-md',
		] );
	} );

	it( 'refreshes equal-shaped rows when the view node is rebuilt', () => {
		registerViewFixture( {
			entries: [
				entry( { seq: 2, id: 2, rid: 'old-second' } ),
				entry( { seq: 1, id: 1, rid: 'old-first' } ),
			],
		} );
		const { container } = mount();
		tickFrame();

		registerViewFixture( {
			entries: [
				entry( { seq: 2, id: 22, rid: 'rebuilt-second-733' } ),
				entry( { seq: 1, id: 11, rid: 'rebuilt-first-521' } ),
			],
		} );
		tickFrame();

		expect( container.textContent ).toContain( 'rebuilt-first-521' );
		expect( container.textContent ).toContain( 'rebuilt-second-733' );
		expect( container.textContent ).not.toContain( 'old-first' );
		expect( container.textContent ).not.toContain( 'old-second' );
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

	it( 'renders the replacement buffer after sending a filter to the view node', () => {
		const node = registerViewFixture( {
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
		setStreamFilter.mockImplementation( () => {
			node.entries = [
				entry( {
					seq: 2,
					id: 22,
					rid: 'replacementB',
					k: 'warn',
				} ),
				entry( {
					seq: 1,
					id: 11,
					rid: 'replacementA',
					k: 'warn',
				} ),
			];
		} );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'warn' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		tickFrame();
		expect( setStreamFilter ).toHaveBeenCalledWith( 'warn' );
		expect( container.textContent ).toContain( 'replacementA' );
		expect( container.textContent ).toContain( 'replacementB' );
		expect( container.textContent ).not.toContain( 'r1' );
		expect( container.textContent ).not.toContain( 'r2' );
	} );

	it( 'keeps buffered stripes stable on a live prepend while scrolled', () => {
		const firstAnchor = entry( {
			seq: 2,
			id: 2,
			rid: 'first-anchor',
		} );
		const secondAnchor = entry( {
			seq: 1,
			id: 1,
			rid: 'second-anchor',
		} );
		const node = registerViewFixture( {
			entries: [ firstAnchor, secondAnchor ],
		} );
		const { container } = mount();
		tickFrame();
		const list = container.querySelector( '.event-logger-error-log-list' );
		list.scrollTop = 99;
		act( () => list.dispatchEvent( new Event( 'scroll' ) ) );
		const findRow = ( rid ) =>
			[
				...container.querySelectorAll(
					'.event-logger-error-log-entry'
				),
			].find( ( row ) => row.textContent.includes( rid ) );
		const firstStripe = findRow( firstAnchor.rid ).classList.contains(
			'row-even'
		);
		const secondStripe = findRow( secondAnchor.rid ).classList.contains(
			'row-even'
		);

		node.entries = [
			entry( {
				seq: 3,
				id: 3,
				rid: 'new-buffered-entry',
			} ),
			firstAnchor,
			secondAnchor,
		];
		tickFrame();

		expect(
			findRow( firstAnchor.rid ).classList.contains( 'row-even' )
		).toBe( firstStripe );
		expect(
			findRow( secondAnchor.rid ).classList.contains( 'row-even' )
		).toBe( secondStripe );
		const rows = container.querySelectorAll(
			'.event-logger-error-log-entry[role="row"]'
		);
		expect( rows ).toHaveLength( 3 );
		expect( rows[ 0 ].classList.contains( 'row-even' ) ).not.toBe(
			rows[ 1 ].classList.contains( 'row-even' )
		);
		expect( rows[ 1 ].classList.contains( 'row-even' ) ).not.toBe(
			rows[ 2 ].classList.contains( 'row-even' )
		);
	} );

	it( 'rebases scroll animation state when the admission filter changes', () => {
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, id: 1, rid: 'before-rebase' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		node.entries = [
			entry( { seq: 2, id: 2, rid: 'sliding-row' } ),
			entry( { seq: 1, id: 1, rid: 'before-rebase' } ),
		];
		tickFrame();
		const list = container.querySelector( '.event-logger-error-log-list' );
		const content = container.querySelector(
			'.event-logger-error-log-content'
		);
		expect( content.style.transform ).toBe( 'translate3d(0,-33px,0)' );
		act( () => {
			list.scrollTop = 99;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		setStreamFilter.mockImplementation( () => {
			node.entries = [];
		} );
		const input = container.querySelector( '.newspack-nodes-search-input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'rebase-733' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		expect( list.scrollTop ).toBe( 0 );
		expect( content.style.transform ).toBe( '' );
		act( () => list.dispatchEvent( new Event( 'scroll' ) ) );
		expect( content.style.transform ).toBe( '' );
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

	it( 'gives alert a distinct accent and stderr a muted one', () => {
		registerViewFixture( {
			entries: [
				entry( { seq: 1, id: 1, rid: 'r_alert', ts: 1, k: 'alert' } ),
				entry( { seq: 2, id: 2, rid: 'r_stderr', ts: 2, k: 'stderr' } ),
			],
		} );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.entry-keyword--alert' )
		).toBeTruthy();
		expect(
			container.querySelector( '.entry-keyword--stderr' )
		).toBeTruthy();
		// An alert must NOT fall through to the generic info accent.
		expect(
			container.querySelector(
				'.entry-keyword--alert.entry-keyword--info'
			)
		).toBeFalsy();
	} );

	it( 'falls back to an empty model when the view node is absent', () => {
		// No fixture — useNodeState yields undefined; the view still renders.
		const { container } = mount();
		expect( container.textContent.toLowerCase() ).toMatch(
			/wait|no|empty/i
		);
	} );

	it( 'keeps rendering the newest row after the buffer saturates its cap', () => {
		// At the cap, key change detection off seq not length (freeze bug).
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
		// useLayoutEffect keyed on committed entries lands offset in-commit.
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

	it( 'clears smooth-scroll offset while reading history and handles returning to top', () => {
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		const content = container.querySelector(
			'.event-logger-error-log-content'
		);
		const list = container.querySelector( '.event-logger-error-log-list' );
		expect( content.style.transform ).toBe( 'translate3d(0,-33px,0)' );
		act( () => {
			list.scrollTop = 100;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		expect( content.style.transform ).toBe( '' );
		act( () => {
			list.scrollTop = 0;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		expect( content.style.transform ).toBe( '' );
	} );

	it( 'keeps scroll position stable when a new row arrives below the top', () => {
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		const { container } = mount();
		tickFrame();
		const list = container.querySelector( '.event-logger-error-log-list' );
		act( () => {
			list.scrollTop = 100;
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		node.entries = [
			entry( { seq: 2, rid: 'r-2' } ),
			entry( { seq: 1, rid: 'r-1' } ),
		];
		tickFrame();
		expect( list.scrollTop ).toBe( 133 );
		act( () => {
			list.dispatchEvent( new Event( 'scroll', { bubbles: true } ) );
		} );
		expect( list.scrollTop ).toBe( 133 );
	} );

	// A browse model the mocked graph hook hands back for the browse-UI tests.
	function browseMock( overrides = {} ) {
		return {
			partitions: [],
			selectedPartition: '',
			selectPartition: jest.fn(),
			segments: [],
			mode: 'live',
			segmentId: null,
			follow: jest.fn(),
			replay: jest.fn(),
			browseSegment: jest.fn(),
			...overrides,
		};
	}

	describe( 'glob browse UI', () => {
		it( 'does not render the partition selector or sidebar in the default live view', () => {
			registerViewFixture();
			useErrorLogGraph.mockReturnValue( {
				setPaused,
				clear,
				setFilter: setStreamFilter,
				browse: browseMock(),
			} );
			const { container } = mount();
			expect(
				container.querySelector( 'select.newspack-nodes-select' )
			).toBeNull();
			expect(
				container.querySelector( '.newspack-nodes-log-browser' )
			).toBeNull();
		} );

		it( 'renders a partition selector (All + each dir) once partitions are cataloged', () => {
			registerViewFixture();
			useErrorLogGraph.mockReturnValue( {
				setPaused,
				clear,
				setFilter: setStreamFilter,
				browse: browseMock( {
					partitions: [
						{ key: 'errors.p0', label: 'errors.p0' },
						{ key: 'errors.p4', label: 'errors.p4' },
					],
				} ),
			} );
			const { container } = mount();
			const select = container.querySelector(
				'select.newspack-nodes-select'
			);
			expect( select ).toBeTruthy();
			const options = Array.from( select.options ).map(
				( o ) => o.value
			);
			expect( options ).toEqual( [ '', 'errors.p0', 'errors.p4' ] );
		} );

		it( 'selecting a partition drives browse.selectPartition', () => {
			registerViewFixture();
			const selectPartition = jest.fn();
			useErrorLogGraph.mockReturnValue( {
				setPaused,
				clear,
				setFilter: setStreamFilter,
				browse: browseMock( {
					partitions: [ { key: 'errors.p4', label: 'errors.p4' } ],
					selectPartition,
				} ),
			} );
			const { container } = mount();
			const select = container.querySelector(
				'select.newspack-nodes-select'
			);
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLSelectElement.prototype,
				'value'
			).set;
			act( () => {
				setter.call( select, 'errors.p4' );
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			} );
			expect( selectPartition ).toHaveBeenCalledWith( 'errors.p4' );
		} );

		it( 'renders the segment sidebar with Live/Replay + segments when a partition is selected', () => {
			registerViewFixture();
			useErrorLogGraph.mockReturnValue( {
				setPaused,
				clear,
				setFilter: setStreamFilter,
				browse: browseMock( {
					partitions: [ { key: 'errors.p4', label: 'errors.p4' } ],
					selectedPartition: 'errors.p4',
					segments: [
						{ id: 9, size: 2048 },
						{ id: 8, size: 512 },
					],
					mode: 'browse',
					segmentId: 9,
				} ),
			} );
			const { container } = mount();
			const sidebar = container.querySelector(
				'.newspack-nodes-log-browser'
			);
			expect( sidebar ).toBeTruthy();
			expect( sidebar.textContent ).toContain( 'Segment 9' );
			expect( sidebar.textContent ).toContain( 'Segment 8' );
			expect( sidebar.textContent ).toMatch( /Live/ );
			expect( sidebar.textContent ).toMatch( /Replay/ );
		} );

		it( 'clicking a segment drives browse.browseSegment; Live/Replay drive follow/replay', () => {
			registerViewFixture();
			const browse = browseMock( {
				partitions: [ { key: 'errors.p4', label: 'errors.p4' } ],
				selectedPartition: 'errors.p4',
				segments: [ { id: 9, size: 2048 } ],
			} );
			useErrorLogGraph.mockReturnValue( {
				setPaused,
				clear,
				setFilter: setStreamFilter,
				browse,
			} );
			const { container } = mount();
			const sidebar = container.querySelector(
				'.newspack-nodes-log-browser'
			);
			const segBtn = sidebar.querySelector(
				'.newspack-nodes-log-browser__item'
			);
			act( () => segBtn.click() );
			expect( browse.browseSegment ).toHaveBeenCalledWith( {
				id: 9,
				size: 2048,
			} );
			const buttons = Array.from( sidebar.querySelectorAll( 'button' ) );
			act( () =>
				buttons.find( ( b ) => /Live/.test( b.textContent ) ).click()
			);
			expect( browse.follow ).toHaveBeenCalled();
			act( () =>
				buttons.find( ( b ) => /Replay/.test( b.textContent ) ).click()
			);
			expect( browse.replay ).toHaveBeenCalled();
		} );
	} );

	it( 'sources the staleness display from the link connector lastEventTime', () => {
		// Staleness reads the link's lastEventTime, not the view node's.
		registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1', k: 'error', m: 'boom' } ) ],
		} );
		Core.nodes.set( 'perferrors:link', {
			lastEventTime: () => Date.now() - 5000,
		} );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.newspack-nodes-toolbar-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
	} );

	it( 'staleness is connection-driven, so a filter never affects it', () => {
		// Connection-driven staleness: a filter hiding all rows shows it.
		registerViewFixture( { entries: [] } );
		Core.nodes.set( 'perferrors:link', {
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
		expect(
			container.querySelector( '.newspack-nodes-toolbar-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
	} );

	it( 'Clear keeps the live-stream staleness (connection still alive)', () => {
		// Clear empties rows but connection is alive, so staleness persists.
		const node = registerViewFixture( {
			entries: [ entry( { seq: 1, rid: 'r-1' } ) ],
		} );
		Core.nodes.set( 'perferrors:link', {
			lastEventTime: () => Date.now() - 8000,
		} );
		const { container } = mount();
		tickFrame();
		expect(
			container.querySelector( '.newspack-nodes-toolbar-stats' )
				.textContent
		).toMatch( /\d+s ago/ );
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
		// An idle stream's advancing link lastEventTime resets "Xs ago".
		jest.useFakeTimers();
		registerViewFixture( { entries: [] } );
		Core.nodes.set( 'perferrors:link', {
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
		// A heartbeat resets "Xs ago" by advancing lastEventTime to now.
		Core.node( 'perferrors:link' ).lastEventTime = () => Date.now();
		tickFrame();
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( stats.textContent ).toMatch( /[01]s ago/ );
		jest.useRealTimers();
	} );
} );
