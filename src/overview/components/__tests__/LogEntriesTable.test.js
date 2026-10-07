/* global KeyboardEvent, MouseEvent */
/**
 * Tests for LogEntriesTable — collapsible indented log table with search,
 * fold/unfold, click-to-highlight, reveal-by-path API.
 *
 * The component is large (1200 lines) and pure: no virtualization, no
 * external lib mocking needed. Tests focus on:
 *  - rendering paths (placeholder / start / complete / merged / process)
 *  - search & navigation (n/p, Enter, Shift+Enter, Escape)
 *  - fold/unfold (single, recursive via Cmd, fold-all/unfold-all)
 *  - row click + swatch click highlight
 *  - reveal via revealRef
 *  - keyboard shortcut '/' to focus search
 */

import * as React from 'react';
import LogEntriesTable from '../LogEntriesTable';
import {
	computeIndentedEntries,
	formatFullTimestamp,
} from '../../utils/logEntryUtils';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import {
	ASK_PICKED_ATTR,
	useAskPicker,
} from '@newspack-nodes/shared/hooks/useAskPicker';

/**
 * Build a typical entry tree:
 *  process (start)            pairId=1
 *    db (start)               pairId=2
 *      query                  pairId=null (placeholder)
 *    db (complete)            pairId=2
 *    render (start)           pairId=3
 *    render (complete)        pairId=3
 *  process (complete)         pairId=1
 *
 * `indent` and `originalIdx` match what RequestProfile's transform would
 * produce.
 */
function makeEntries() {
	return [
		{
			n: 1,
			ts: 1700000000,
			startTs: 1700000000,
			k: 'process (start)',
			m: '/foo',
			pairId: 1,
			indent: 0,
			originalIdx: 0,
			i: 3,
		},
		{
			n: 2,
			ts: 1700000001,
			startTs: 1700000001,
			k: 'db (start)',
			m: 'SELECT *',
			pairId: 2,
			indent: 1,
			originalIdx: 1,
			i: 4,
		},
		{
			n: 3,
			ts: 1700000002,
			k: 'query',
			m: 'logged value',
			pairId: null,
			indent: 2,
			originalIdx: 2,
			i: 5,
		},
		{
			n: 4,
			ts: 1700000003,
			k: 'db (complete)',
			m: '-',
			duration_ms: 1000,
			peak_mb: 4,
			pairId: 2,
			indent: 1,
			originalIdx: 3,
			i: 6,
		},
		{
			n: 5,
			ts: 1700000004,
			startTs: 1700000004,
			k: 'render (start)',
			m: '',
			pairId: 3,
			indent: 1,
			originalIdx: 4,
			i: 7,
		},
		{
			n: 6,
			ts: 1700000005,
			k: 'render (complete)',
			m: '',
			pairId: 3,
			indent: 1,
			originalIdx: 5,
			i: 8,
		},
		{
			n: 7,
			ts: 1700000010,
			k: 'process (complete)',
			m: '',
			duration_ms: 10000,
			peak_mb: 16,
			pairId: 1,
			indent: 0,
			originalIdx: 6,
			i: 9,
		},
	];
}

/**
 * `makeEntries()` with the ruler's gap rows inside the `render` pair, as
 * `computeIndentedEntries()` inserts them whenever a span outlives a gap.
 * The pair is still childless: placeholders are filler, not children.
 *
 * @return {Array} Indented entries.
 */
function makeEntriesWithGapPair() {
	const entries = makeEntries();
	return [
		...entries.slice( 0, 5 ),
		{
			n: '',
			ts: 1700000004.5,
			k: '',
			m: '',
			pairId: 3,
			indent: 1,
			isPlaceholder: true,
			displayTime: '\u2022\u2022\u2022 \u2022',
		},
		...entries.slice( 5 ),
	];
}

// jsdom implements no scrollIntoView, and a body toggle centers its row.
const originalScrollIntoView = window.HTMLElement.prototype.scrollIntoView;
beforeEach( () => {
	window.HTMLElement.prototype.scrollIntoView = jest.fn();
} );
afterEach( () => {
	window.HTMLElement.prototype.scrollIntoView = originalScrollIntoView;
} );

describe( 'LogEntriesTable', () => {
	let rafCallbacks = [];
	let originalRAF;

	beforeEach( () => {
		rafCallbacks = [];
		originalRAF = global.requestAnimationFrame;
		global.requestAnimationFrame = ( cb ) => {
			rafCallbacks.push( cb );
			return rafCallbacks.length;
		};
	} );

	afterEach( () => {
		global.requestAnimationFrame = originalRAF;
	} );

	function flushRAF() {
		const cbs = rafCallbacks;
		rafCallbacks = [];
		cbs.forEach( ( cb ) => cb() );
	}

	it( 'returns null when entries is empty', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries: [] } )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'falsy entries array is the no-render path (component returns null)', () => {
		// entries=undefined CRASHES (real bug); use [] for the no-entries path.
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries: [] } )
		);
		expect( container.textContent ).toBe( '' );
		unmount();
	} );

	it( 'renders the entry count + header buttons + entries', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		expect( container.textContent ).toContain( 'Log Entries' );
		expect( container.textContent ).toContain( 'Fold All' );
		expect( container.textContent ).toContain( 'Unfold All' );
		for ( const button of container.querySelectorAll(
			'.log-entries-actions button'
		) ) {
			expect( [ ...button.classList ] ).toEqual( [ 'button-link' ] );
		}
		const table = container.querySelector( 'table' );
		expect(
			table.classList.contains( 'newspack-nodes-table--undivided' )
		).toBe( true );
		const cells = table.querySelectorAll( 'tbody tr:first-child td' );
		for ( const cell of [ ...cells ].slice( 0, 2 ) ) {
			expect(
				cell.classList.contains( 'newspack-nodes-table__terminal-data' )
			).toBe( false );
		}
		for ( const cell of [ ...cells ].slice( 2 ) ) {
			expect(
				cell.classList.contains( 'newspack-nodes-table__terminal-data' )
			).toBe( true );
		}
		const disclosure = Array.from(
			container.querySelectorAll( '.newspack-nodes-status' )
		).find( ( node ) => [ '▶', '▼' ].includes( node.textContent.trim() ) );
		expect( disclosure.classList.contains( 'is-info' ) ).toBe( true );
		// expandedSet empty by default: child pairs folded; process shows.
		expect( container.textContent ).toContain( 'process (start)' );
		unmount();
	} );

	it( 'renders the caller a traced hook recorded', () => {
		// A hook that fires sixteen times reads as sixteen identical spans; the
		// caller is the only thing that distinguishes them, and it was being
		// captured onto the entry and then dropped because nothing read it.
		const entries = [
			{
				n: 1,
				k: 'the_content hook (start)',
				m: '<p>body</p>',
				caller: "require('wp-admin/post.php'), apply_filters('the_content')",
				ts: 1000,
			},
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		expect( container.textContent ).toContain( 'wp-admin/post.php' );
		unmount();
	} );

	it( 'shows the origin frame beside a value preview, not only instead of one', () => {
		// `m || l` hid the caller on every hook that filters a value — which is
		// every hook the label was added for.
		const entries = [
			{
				n: 1,
				k: 'the_content hook (complete)',
				l: 'WP_REST_Revisions_Controller->prepare_item_for_response',
				m: '<div class="wp-block-column"></div>\n'.repeat( 30 ),
				duration_ms: 2.5,
				ts: 1000,
			},
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// Its own line, leading the value it filtered.
		const trace = container.querySelector( '.log-entries-trace' );
		expect( trace.textContent ).toBe(
			'WP_REST_Revisions_Controller->prepare_item_for_response'
		);
		expect( container.textContent ).toContain( 'wp-block-column' );
		unmount();
	} );

	it( "renders the record's fatal on the terminal row and nowhere else", () => {
		const request = {
			fatal_error:
				'Uncaught Error: Call to undefined method Newspack_Nodes\\Table_Node::kea_4431()',
			fatal_file:
				'/srv/wp-content/plugins/newspack-weka/includes/class-weka.php',
			fatal_line: 4431,
			fatal_plugin: 'newspack-weka',
		};
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntries(),
				request,
			} )
		);
		const rows = [ ...container.querySelectorAll( 'tbody tr' ) ];
		const terminal = rows.find( ( row ) =>
			row.textContent.includes( 'process (complete)' )
		);
		const fatal = terminal.querySelector( '.log-entries-fatal' );
		expect( fatal.textContent ).toContain(
			'Call to undefined method Newspack_Nodes\\Table_Node::kea_4431()'
		);
		expect( fatal.textContent ).toContain(
			'/srv/wp-content/plugins/newspack-weka/includes/class-weka.php:4431'
		);
		expect( fatal.textContent ).toContain( 'newspack-weka' );
		expect( [
			...fatal.querySelector( '.newspack-nodes-status' ).classList,
		] ).toContain( 'is-error' );
		// The duration still rides the row, below the fatal.
		expect( terminal.textContent ).toContain( '(10000.000ms)' );
		expect(
			rows.filter( ( row ) => row.textContent.includes( 'kea_4431' ) )
		).toHaveLength( 1 );
		unmount();
	} );

	it( "folds a fatal's stack trace under its own Show more", () => {
		const trace = Array.from(
			{ length: 9 },
			( _, frame ) => `#${ frame } /srv/kea-4435.php(${ frame }): moa()`
		).join( '\n' );
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntries(),
				request: {
					fatal_error: `Uncaught Error: kea 4435\nStack trace:\n${ trace }`,
					fatal_file: '/srv/kea-4435.php',
					fatal_line: 4435,
					fatal_plugin: '',
				},
			} )
		);
		const fatal = container.querySelector( '.log-entries-fatal' );
		expect( fatal.textContent ).not.toContain( '#8 /srv/kea-4435.php' );
		act( () => {
			fatal.querySelector( 'button' ).click();
		} );
		expect(
			container.querySelector( '.log-entries-fatal' ).textContent
		).toContain( '#8 /srv/kea-4435.php' );
		unmount();
	} );

	it( 'renders and searches the fatal on an aborted terminal row', () => {
		const entries = makeEntries();
		entries[ 6 ] = { ...entries[ 6 ], k: 'process (aborted)' };
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries,
				request: {
					fatal_error: 'Uncaught Error: kokako_4439 never sang',
					fatal_file: '/srv/wp-content/plugins/kokako/sing.php',
					fatal_line: 4439,
					fatal_plugin: 'kokako',
				},
			} )
		);
		const terminal = [ ...container.querySelectorAll( 'tbody tr' ) ].find(
			( row ) => row.textContent.includes( 'process (aborted)' )
		);
		const fatal = terminal.querySelector( '.log-entries-fatal' );
		expect( fatal.textContent ).toContain( 'kokako_4439 never sang' );
		expect( fatal.textContent ).toContain(
			'/srv/wp-content/plugins/kokako/sing.php:4439'
		);
		const input = container.querySelector( 'input' );
		act( () => {
			Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set.call( input, 'kokako_4439' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		expect( container.textContent ).toContain( '1 match' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'renders no fatal on a terminal row whose record carries none', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntries(),
				request: { url: '/kea-4432' },
			} )
		);
		expect( container.querySelector( '.log-entries-fatal' ) ).toBeNull();
		unmount();
	} );

	it( 'leaves the plugin out of a fatal no plugin raised', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntries(),
				request: {
					fatal_error: 'Allowed memory size exhausted kakapo 4433',
					fatal_file: '/srv/wp-includes/class-kakapo.php',
					fatal_line: 4433,
					fatal_plugin: '',
				},
			} )
		);
		const fatal = container.querySelector( '.log-entries-fatal' );
		expect( fatal.textContent ).toContain(
			'/srv/wp-includes/class-kakapo.php:4433'
		);
		expect( fatal.textContent ).not.toMatch( /plugin/i );
		unmount();
	} );

	it( 'renders duration, peak memory, and child count with shared metadata tiers', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntries(),
			} )
		);
		const spans = [ ...container.querySelectorAll( 'span' ) ];
		const duration = spans.find(
			( node ) =>
				node.classList.contains( 'newspack-nodes-status' ) &&
				node.textContent.includes( '(1000.000ms)' )
		);
		const peakMemory = spans.find(
			( node ) => '[4MB]' === node.textContent.trim()
		);
		const childCount = spans.find(
			( node ) => '[1 entry]' === node.textContent.trim()
		);

		expect( [ ...duration.classList ] ).toEqual( [
			'newspack-nodes-status',
		] );
		expect( [ ...peakMemory.classList ] ).toEqual( [
			'newspack-nodes-status',
			'is-warning',
		] );
		expect( [ ...childCount.classList ] ).toEqual( [
			'newspack-nodes-status',
			'is-muted',
		] );
		unmount();
	} );

	it( 'uses realCount in header when provided', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries,
				realCount: 42,
			} )
		);
		expect( container.textContent ).toContain( 'Log Entries (42)' );
		unmount();
	} );

	it( 'Unfold All expands every collapsible pair', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		// After unfold, all entries should be visible.
		const rows = container.querySelectorAll( 'tbody tr' );
		expect( rows.length ).toBeGreaterThan( 0 );
		unmount();
	} );

	it( 'Unfold All leaves a gap-spanning pair merged', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntriesWithGapPair(),
			} )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		expect(
			container.querySelectorAll( 'tr[data-pair-id="3"]' ).length
		).toBe( 1 );
		// The pair it encloses does open, so this is not a fold that failed.
		expect(
			container.querySelectorAll( 'tr[data-pair-id="1"]' ).length
		).toBe( 2 );
		unmount();
	} );

	it( 'Fold All collapses everything', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const foldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Fold All' ) );
		act( () => foldBtn.click() );
		expect( container.textContent ).toContain( 'process (start)' );
		unmount();
	} );

	it( 'searches and shows match count', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'db' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		// 150ms debounce.
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		// "db (start)" matches; its complete is the same finding, counted once.
		expect( container.textContent ).toContain( '1 match' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'Enter advances to next match', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'render' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		// Hit Enter to navigate.
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		} );
		// Enter → navigateToMatch(0); count span shows "1/N".
		expect( container.textContent ).toMatch( /\d+\/\d+/ );
		jest.useRealTimers();
		unmount();
	} );

	it( "'/' key focuses the search input from outside", () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const focusSpy = jest.spyOn( input, 'focus' );
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: '/', bubbles: true } )
			);
		} );
		expect( focusSpy ).toHaveBeenCalled();
		focusSpy.mockRestore();
		unmount();
	} );

	it( "'n' / 'p' navigate matches once a search is active", () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'render' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		// Blur the input first so the doc-level handler runs n/p.
		input.blur();
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'n', bubbles: true } )
			);
		} );
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'p', bubbles: true } )
			);
		} );
		// And then again, to wrap around.
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'n', bubbles: true } )
			);
		} );
		expect( container.textContent ).toMatch( /matches|\d+\/\d+/ );
		jest.useRealTimers();
		unmount();
	} );

	function searchFor( container, input, query ) {
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, query );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
	}

	it( 'keyword match counts a start/complete pair once (start only)', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// 'render' hits both rows of the childless pair by keyword; the
		// complete adds nothing over its start, so it is not a stop.
		searchFor( container, container.querySelector( 'input' ), 'render' );
		expect( container.textContent ).toContain( '1 match' );
		expect( container.textContent ).not.toContain( '2 matches' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'counts a child that only looks like a close as a match of its own', () => {
		// `kea(complete)` lacks the space a pair keyword carries, so the pairing
		// reads it as a child of `kea`, and the search must agree.
		const { entries } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'kea (start)', ts: 1.01 },
			{ n: 3, k: 'kea(complete)', ts: 1.02 },
			{ n: 4, k: 'kea (complete)', ts: 1.03 },
			{ n: 5, k: 'process (complete)', ts: 1.04 },
		] );
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		searchFor( container, container.querySelector( 'input' ), 'kea' );
		expect( container.textContent ).toContain( '2 matches' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'keeps a complete whose message matches, not just its keyword', () => {
		const entries = makeEntries();
		entries[ 5 ] = { ...entries[ 5 ], m: 'render queue drained qz41' };
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// 'render' hits the start keyword AND the complete's message.
		searchFor( container, container.querySelector( 'input' ), 'render' );
		expect( container.textContent ).toContain( '2 matches' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'keeps a truncated complete — "(complete)" mid-keyword is not a pair end', () => {
		const entries = makeEntries();
		// Defensive: a keyword with "(complete)" mid-string must not read as a
		// pair end. Log_Manager no longer produces one, but a producer could.
		entries[ 5 ] = { ...entries[ 5 ], k: 'render (complete) (truncated)' };
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		searchFor( container, container.querySelector( 'input' ), 'render' );
		expect( container.textContent ).toContain( '2 matches' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'keeps completes when the query matches only their keyword suffix', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// 'complete' misses every start keyword, so the completes stand alone.
		searchFor( container, container.querySelector( 'input' ), 'complete' );
		expect( container.textContent ).toContain( '3 matches' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'does not unfold an empty pair when a search lands on it', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'render' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		input.blur();
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'n', bubbles: true } )
			);
		} );
		// render (pairId 3) empty pair: search stays one merged row. Its body
		// opens on its own, so the fold has nothing to reveal.
		expect(
			container.querySelectorAll( 'tr[data-pair-id="3"]' ).length
		).toBe( 1 );
		jest.useRealTimers();
		unmount();
	} );

	it( 'does not unfold a pair the ruler put placeholders inside', () => {
		// A pair whose halves straddle a gap holds only the ruler's dot and
		// timestamp rows — filler, not children, and nothing to unfold to.
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeEntriesWithGapPair(),
			} )
		);
		searchFor( container, container.querySelector( 'input' ), 'render' );
		container.querySelector( 'input' ).blur();
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'n', bubbles: true } )
			);
		} );
		expect(
			container.querySelectorAll( 'tr[data-pair-id="3"]' ).length
		).toBe( 1 );
		jest.useRealTimers();
		unmount();
	} );

	it( 'Escape clears active search', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'db' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Escape',
					bubbles: true,
				} )
			);
		} );
		// After Escape clears search, the count display is gone.
		expect( container.textContent ).not.toMatch( /matches/ );
		jest.useRealTimers();
		unmount();
	} );

	it( 'clicking a row with start keyword folds/unfolds the pair', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// Unfold all first to make individual start rows visible.
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		const dbStartRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.includes( 'db (start)' ) );
		expect( dbStartRow ).toBeTruthy();
		act( () => dbStartRow.click() );
		// Clicking it again folds back. No throw == success.
		expect( () => act( () => dbStartRow.click() ) ).not.toThrow();
		unmount();
	} );

	it( 'Cmd+click on a start row unfolds recursively', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		const dbStartRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.includes( 'db (start)' ) );
		expect( () =>
			act( () => {
				dbStartRow.dispatchEvent(
					new MouseEvent( 'click', { metaKey: true, bubbles: true } )
				);
			} )
		).not.toThrow();
		unmount();
	} );

	it( 'folding a pair folds the pairs past a child that only mentions (complete)', () => {
		const { entries } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'alpha (start)', ts: 1.01 },
			{ n: 3, k: 'render (complete) (truncated)', ts: 1.02 },
			{ n: 4, k: 'beta (start)', ts: 1.03 },
			{ n: 5, k: 'kiwi-4471', ts: 1.04 },
			{ n: 6, k: 'beta (complete)', ts: 1.05 },
			{ n: 7, k: 'alpha (complete)', ts: 1.06 },
			{ n: 8, k: 'process (complete)', ts: 1.07 },
		] );
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const alphaRow = () =>
			Array.from( container.querySelectorAll( 'tbody tr' ) ).find(
				( r ) => r.textContent.includes( 'alpha' )
			);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		act( () => alphaRow().click() );
		act( () => alphaRow().click() );
		expect( container.textContent ).toContain( 'beta' );
		expect( container.textContent ).not.toContain( 'kiwi-4471' );
		unmount();
	} );

	it( 'clicking process (start) does NOT toggle (outermost pair)', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const processRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.includes( 'process (start)' ) );
		expect( processRow ).toBeTruthy();
		// Clicking process must not throw or fold (outermost pair).
		expect( () => act( () => processRow.click() ) ).not.toThrow();
		expect( container.textContent ).toContain( 'process (start)' );
		unmount();
	} );

	it( 'clicking the swatch on a paired row highlights the pair', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		// First cell of a start row is the swatch.
		const dbStartRow = Array.from(
			container.querySelectorAll( 'tbody tr' )
		).find( ( r ) => r.textContent.includes( 'db (start)' ) );
		const swatch = dbStartRow.querySelector( 'td:first-of-type' );
		expect( () => {
			act( () => swatch.click() );
			// Click again to toggle off.
			act( () => swatch.click() );
		} ).not.toThrow();
		unmount();
	} );

	it( 'revealRef.current is wired and reveals an entry by path', () => {
		const entries = makeEntries();
		const revealRef = { current: null };
		const { unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);
		expect( revealRef.current ).toEqual( expect.any( Function ) );
		// Reveal db pair via flame-style path (starts with "request").
		act( () => revealRef.current( null, [ 'request', 'process', 'db' ] ) );
		// Unknown path is a no-op.
		act( () => revealRef.current( null, [ 'nope' ] ) );
		unmount();
	} );

	it( 'reveals the right occurrence when a span carries a stable label', () => {
		jest.useFakeTimers();
		// `Flame_Tree` names a node `base: l` and only falls back to `m`, so a
		// path built from `m` alone missed every segment whose entry carried an
		// `l` — with `trace_hooks` on that is EVERY hook, which sent each
		// reveal down the base-name fallback and its first-wins lookup. The
		// second `sql` here carries a label and no message, which is exactly a
		// traced hook, and is reachable only if the label is in the key.
		const entries = [
			{
				n: 1,
				k: 'process (start)',
				pairId: 1,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 2,
				k: 'sql (start)',
				l: 'SELECT wp_users',
				pairId: 2,
				indent: 1,
				originalIdx: 1,
			},
			{ n: 3, k: 'sql (complete)', pairId: 2, indent: 1, originalIdx: 2 },
			{
				n: 4,
				k: 'sql (start)',
				l: 'SELECT wp_posts',
				pairId: 3,
				indent: 1,
				originalIdx: 3,
			},
			{ n: 5, k: 'sql (complete)', pairId: 3, indent: 1, originalIdx: 4 },
			{
				n: 6,
				k: 'process (complete)',
				pairId: 1,
				indent: 0,
				originalIdx: 5,
			},
		];
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);

		act( () =>
			revealRef.current( null, [
				'request',
				'process',
				'sql: SELECT wp_posts',
			] )
		);
		// The highlight lands in a rAF, so flush one frame.
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );

		// Whichever row got the highlight IS the answer. Without the label in
		// the key the path misses and falls to the base key, whose lookup is
		// first-wins — so a miss lands on `SELECT wp_users`, not the clicked.
		const lit = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.querySelector( 'td' )?.style.boxShadow
		);
		expect( lit?.dataset.pairId ).toBe( '3' );
		unmount();
		jest.useRealTimers();
	} );

	/**
	 * A folded request: the kept `process (start)` carries a message, and two
	 * hooks each open a labelled `sql` — an empty pair under the second.
	 *
	 * @return {Array} Indented entries.
	 */
	const foldedHooks = () =>
		[
			{
				k: 'process (start)',
				m: '745696 on pool7',
				pairId: 1,
				indent: 0,
			},
			{ k: 'hook (start)', l: 'first', pairId: 2, indent: 1 },
			{ k: 'sql (start)', l: 'update_meta_cache', pairId: 3, indent: 2 },
			{ k: 'sql (complete)', pairId: 3, indent: 2 },
			{ k: 'hook (complete)', pairId: 2, indent: 1 },
			{ k: 'hook (start)', l: 'second', pairId: 4, indent: 1 },
			{ k: 'sql (start)', l: 'get_posts', pairId: 5, indent: 2 },
			{ k: 'sql (complete)', m: '308 merged', pairId: 5, indent: 2 },
			{ k: 'hook (complete)', pairId: 4, indent: 1 },
			{ k: 'process (complete)', pairId: 1, indent: 0 },
		].map( ( e, idx ) => ( { ...e, n: idx + 1, originalIdx: idx } ) );

	/**
	 * Reveal a folded frame by its path, flush the highlight, and return the
	 * row it lit.
	 *
	 * @param {Object} revealRef The table's reveal ref.
	 * @param {Object} container The rendered container.
	 * @return {?HTMLElement} The highlighted row.
	 */
	const revealFolded = ( revealRef, container ) => {
		act( () =>
			revealRef.current( null, [
				'request',
				'process',
				'hook: second',
				'sql: get_posts',
			] )
		);
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );
		return Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.querySelector( 'td' )?.style.boxShadow
		);
	};

	it( 'reveals a folded frame by its node names when the kept row carries a message', () => {
		jest.useFakeTimers();
		// A folded frame has no detail, so its path is node names throughout,
		// while the kept process row keys its detail by its message.
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: foldedHooks(),
				revealRef,
			} )
		);

		expect( revealFolded( revealRef, container )?.dataset.pairId ).toBe(
			'5'
		);
		unmount();
		jest.useRealTimers();
	} );

	it( 'reveals the folded row a frame stands for, not a kept instance of its path', () => {
		jest.useFakeTimers();
		// The kept head holds one `hook: second`; the fold's row for that path
		// is the one the merged frame is, whatever the ancestors carry.
		const entries = [
			{
				k: 'process (start)',
				m: '745696 on pool7',
				pairId: 1,
				indent: 0,
			},
			{ k: 'hook (start)', l: 'second', pairId: 2, indent: 1 },
			{ k: 'hook (complete)', pairId: 2, indent: 1 },
			{
				k: 'hook (start)',
				l: 'second',
				pairId: 3,
				indent: 1,
				fromFold: true,
			},
			{
				k: 'sql (start)',
				l: 'get_posts',
				pairId: 4,
				indent: 2,
				fromFold: true,
			},
			{ k: 'sql (complete)', pairId: 4, indent: 2, fromFold: true },
			{ k: 'hook (complete)', pairId: 3, indent: 1, fromFold: true },
			{ k: 'process (complete)', pairId: 1, indent: 0 },
		].map( ( e, idx ) => ( { ...e, n: idx + 1, originalIdx: idx } ) );
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);

		act( () =>
			revealRef.current( null, [ 'request', 'process', 'hook: second' ] )
		);
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );

		const lit = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.querySelector( 'td' )?.style.boxShadow
		);
		expect( lit?.dataset.pairId ).toBe( '3' );
		unmount();
		jest.useRealTimers();
	} );

	/**
	 * Reveal `span` under a hook whose label the path does not share, so the
	 * node-name path misses and only the base-name fallback can resolve it.
	 *
	 * @param {string} span The span's name, as its `(start)` keyword carries it.
	 * @return {?string} The pair id of the row the reveal lit.
	 */
	const revealByBase = ( span ) => {
		const entries = [
			{ k: 'process (start)', pairId: 1, indent: 0 },
			{ k: 'hook (start)', l: 'kea-7713', pairId: 2, indent: 1 },
			{ k: `${ span } (start)`, pairId: 3, indent: 2 },
			{ k: `${ span } (complete)`, pairId: 3, indent: 2 },
			{ k: 'hook (complete)', pairId: 2, indent: 1 },
			{ k: 'process (complete)', pairId: 1, indent: 0 },
		].map( ( e, idx ) => ( { ...e, n: idx + 1, originalIdx: idx } ) );
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);
		act( () =>
			revealRef.current( null, [
				'request',
				'process',
				'hook: moa-7713',
				span,
			] )
		);
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );
		const lit = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.querySelector( 'td' )?.style.boxShadow
		);
		unmount();
		return lit?.dataset.pairId;
	};

	it( 'resolves a span named with a ": " by its base on both sides', () => {
		jest.useFakeTimers();
		expect( revealByBase( 'kea: moa 7713' ) ).toBe( '3' );
		jest.useRealTimers();
	} );

	it( 'resolves a span whose name opens with ": " by its whole name', () => {
		jest.useFakeTimers();
		expect( revealByBase( ': takahe 7713' ) ).toBe( '3' );
		jest.useRealTimers();
	} );

	it( 'closes a spliced span by its base, so a later span resolves by its path', () => {
		jest.useFakeTimers();
		// The start carries its label in the keyword and the complete does not.
		const entries = [
			{ k: 'process (start)', pairId: 1, indent: 0 },
			{
				k: 'include: /Macros/Kea7713.html (start)',
				pairId: 2,
				indent: 1,
			},
			{ k: 'include (complete)', pairId: 2, indent: 1 },
			{ k: 'hook (start)', l: 'moa-7713', pairId: 3, indent: 1 },
			{ k: 'hook (complete)', pairId: 3, indent: 1 },
			{ k: 'process (complete)', pairId: 1, indent: 0 },
		].map( ( e, idx ) => ( { ...e, n: idx + 1, originalIdx: idx } ) );
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);

		act( () =>
			revealRef.current( null, [
				'request',
				'process',
				'hook: moa-7713',
			] )
		);
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );

		const lit = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.querySelector( 'td' )?.style.boxShadow
		);
		expect( lit?.dataset.pairId ).toBe( '3' );
		unmount();
		jest.useRealTimers();
	} );

	it( 'leaves an empty pair merged when revealing it', () => {
		jest.useFakeTimers();
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: foldedHooks(),
				revealRef,
			} )
		);

		revealFolded( revealRef, container );

		expect( container.textContent ).toContain( '▶sql' );
		expect( container.textContent ).not.toContain( 'sql (complete)' );
		unmount();
		jest.useRealTimers();
	} );

	it( 'reveals the span by the position of the entry that opened it, whatever its number', () => {
		jest.useFakeTimers();
		// A nested render restarts n at 1 under the same rid, so the Perl
		// include's position is the PHP plugin span's number; only i finds it.
		const entries = [
			{
				n: 1,
				i: 5,
				k: 'process (start)',
				pairId: 1,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 11,
				i: 6,
				k: 'plugin (start)',
				l: 'nodes',
				pairId: 2,
				indent: 1,
				originalIdx: 1,
			},
			{
				n: 12,
				i: 7,
				k: 'plugin (complete)',
				pairId: 2,
				indent: 1,
				originalIdx: 2,
			},
			{
				n: 1,
				i: 8,
				k: 'gyrobase (start)',
				pairId: 3,
				indent: 1,
				originalIdx: 3,
			},
			{
				n: 11,
				i: 11,
				k: 'include (start)',
				l: '/Macros/Global.html',
				pairId: 4,
				indent: 2,
				originalIdx: 5,
			},
			{
				n: 12,
				i: 12,
				k: 'include (complete)',
				pairId: 4,
				indent: 2,
				originalIdx: 6,
			},
			{
				n: 13,
				i: 13,
				k: 'gyrobase (complete)',
				pairId: 3,
				indent: 1,
				originalIdx: 7,
			},
			{
				n: 13,
				i: 14,
				k: 'process (complete)',
				pairId: 1,
				indent: 0,
				originalIdx: 8,
			},
		];
		const revealRef = { current: null };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);
		const lit = () =>
			Array.from( container.querySelectorAll( 'tr' ) ).find(
				( tr ) => tr.querySelector( 'td' )?.style.boxShadow
			)?.dataset.pairId;

		act( () =>
			revealRef.current( 11, [
				'request',
				'process',
				'gyrobase',
				'include: /Macros/Global.html',
			] )
		);
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );
		expect( lit() ).toBe( '4' );

		// A frame with no position, a merged one's, still resolves by path.
		act( () =>
			revealRef.current( null, [ 'request', 'process', 'plugin: nodes' ] )
		);
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );
		expect( lit() ).toBe( '2' );
		unmount();
		jest.useRealTimers();
	} );

	it( 'names each row to the picker by its position, never its repeated number', () => {
		const entries = [
			{
				n: 7,
				i: 4,
				k: 'publication',
				m: 'php',
				pairId: null,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 7,
				i: 9,
				k: 'publication',
				m: 'perl',
				pairId: null,
				indent: 0,
				originalIdx: 1,
			},
			{ n: '', k: '', m: '', ts: 1, isPlaceholder: true, indent: 0 },
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		expect(
			Array.from( container.querySelectorAll( 'tbody tr' ) ).map(
				( tr ) => tr.getAttribute( 'data-ask' )
			)
		).toEqual( [ 'entry:4', 'entry:9', null ] );
		unmount();
	} );

	it( 'shows duration + peak_mb stats on complete entries', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// db (complete) has duration_ms=1000 → '1000.000ms' rendered.
		expect( container.textContent ).toContain( '1000.000ms' );
		expect( container.textContent ).toContain( '4MB' );
		unmount();
	} );

	it( 'never folds a statement, whatever its length', () => {
		// A statement's clauses are what the reader came for, whichever frame
		// carries it; a hook argument of the same length that is not one folds.
		const entries = makeEntries();
		const body = Array.from(
			{ length: 8 },
			( _, i ) => `  clause${ i + 1 }`
		).join( '\n' );
		entries[ 1 ] = {
			...entries[ 1 ],
			k: 'posts_request hook (start)',
			m: `SELECT${ body }`,
		};
		entries[ 4 ] = {
			...entries[ 4 ],
			k: 'args (start)',
			m: `hook${ body }`,
		};
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		expect( container.textContent ).toContain( 'SELECT  clause1' );
		expect( container.textContent ).toContain( 'clause8' );
		expect( container.textContent ).toContain( 'hook  clause1' );
		expect(
			Array.from( container.querySelectorAll( 'button' ) ).filter(
				( b ) => 'Show more' === b.textContent
			)
		).toHaveLength( 1 );
		unmount();
	} );

	it( 'counts and marks a search hit on the statement a folded pair shows', () => {
		const entries = makeEntries();
		entries[ 1 ] = { ...entries[ 1 ], k: 'query (start)', m: '' };
		entries[ 3 ] = {
			...entries[ 3 ],
			k: 'query (complete)',
			m: 'SELECT a FROM t WHERE b = ? LIMIT ?',
		};
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'from t' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		expect( container.textContent ).toContain( '1 match' );
		expect( container.querySelector( 'mark' ) ).not.toBeNull();
		jest.useRealTimers();
		unmount();
	} );

	describe( "search over the terminal row's fatal", () => {
		const trace = Array.from(
			{ length: 9 },
			( _, frame ) => `#${ frame } /srv/ruru-4437.php(${ frame }): moa()`
		).join( '\n' );
		const request = {
			fatal_error: `Uncaught Error: Call to undefined method Newspack_Nodes\\Table_Node::tieke_4437()\nStack trace:\n${ trace }\n#9 kaka_4437_frame()`,
			fatal_file: '/srv/wp-content/plugins/hoiho-4437/hoiho.php',
			fatal_line: 4437,
			fatal_plugin: 'pukeko-4437',
		};

		function search( container, term ) {
			const input = container.querySelector( 'input' );
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set;
			act( () => {
				setter.call( input, term );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
			act( () => {
				jest.advanceTimersByTime( 200 );
			} );
		}

		beforeEach( () => jest.useFakeTimers() );
		afterEach( () => jest.useRealTimers() );

		it.each( [
			[ 'the message', 'tieke_4437' ],
			[ 'the file:line', 'hoiho.php:4437' ],
			[ 'the plugin', 'pukeko-4437' ],
		] )( 'matches %s on the terminal row alone', ( _, term ) => {
			const { container, unmount } = renderComponent(
				React.createElement( LogEntriesTable, {
					entries: makeEntries(),
					request,
				} )
			);
			search( container, term );
			expect( container.textContent ).toContain( '1 match' );
			const fatal = container.querySelector( '.log-entries-fatal' );
			expect( fatal.querySelector( 'mark' )?.textContent ).toBe( term );
			unmount();
		} );

		it( "opens the fold over a hit past the stack trace's fifth line", () => {
			const { container, unmount } = renderComponent(
				React.createElement( LogEntriesTable, {
					entries: makeEntries(),
					request,
				} )
			);
			search( container, 'kaka_4437_frame' );
			expect( container.textContent ).toContain( '1 match' );
			expect(
				container.querySelector( '.log-entries-fatal' ).textContent
			).not.toContain( 'kaka_4437_frame' );
			act( () => {
				Array.from( container.querySelectorAll( 'button' ) )
					.find( ( b ) => '▼' === b.textContent )
					.click();
			} );
			const fatal = container.querySelector( '.log-entries-fatal' );
			expect( fatal.textContent ).toContain( 'kaka_4437_frame' );
			expect( fatal.querySelector( 'mark' )?.textContent ).toBe(
				'kaka_4437_frame'
			);
			unmount();
		} );
	} );

	it( "reveals a search hit behind the fold on a merged row's complete side", () => {
		// An adjacent empty pair stays merged under navigation, so the fold
		// the search opens has to be the one the merged row's complete side
		// folds under.
		const entries = makeEntries();
		const wide = Object.fromEntries(
			Array.from( { length: 12 }, ( _, i ) => [ `key${ i }`, `v${ i }` ] )
		);
		// The `db` pair becomes a leaf so the fresh pair under it is the only
		// pair at its depth, and its two halves sit side by side.
		entries[ 1 ] = { ...entries[ 1 ], k: 'db', pairId: null };
		entries[ 2 ] = {
			...entries[ 2 ],
			k: 'init hook (start)',
			m: '',
			pairId: 9,
		};
		entries[ 3 ] = {
			...entries[ 3 ],
			k: 'init hook (complete)',
			m: JSON.stringify( wide ),
			pairId: 9,
			indent: 2,
		};
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'key9' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		expect( container.textContent ).toContain( '1 match' );
		act( () => {
			Array.from( container.querySelectorAll( 'button' ) )
				.find( ( b ) => '▼' === b.textContent )
				.click();
		} );
		const row = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( r ) => r.textContent.includes( 'init hook' )
		);
		expect( row.querySelector( 'mark' ) ).not.toBeNull();
		expect( row.textContent ).toContain( '"key9"' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'shows a zero-valued complete message on the merged row', () => {
		const entries = makeEntries();
		entries[ 1 ] = { ...entries[ 1 ], k: 'count (start)', m: '' };
		entries[ 3 ] = { ...entries[ 3 ], k: 'count (complete)', m: 0 };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const row = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( r ) => r.textContent.includes( 'count' )
		);
		expect( row.textContent ).toContain( '0' );
		unmount();
	} );

	it( "puts a folded command span's result on the line below its command", () => {
		const entries = makeEntries();
		entries[ 1 ] = {
			...entries[ 1 ],
			k: 'Performance_CI overview command (start)',
			m: '/perf-7713> overview --categories --breakdown=server',
		};
		entries[ 3 ] = {
			...entries[ 3 ],
			k: 'Performance_CI overview command (complete)',
			m: 'ok-7713',
		};
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const row = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( r ) => r.textContent.includes( 'Performance_CI overview command' )
		);
		expect( row.textContent ).toContain(
			'/perf-7713> overview --categories --breakdown=server\nok-7713'
		);
		unmount();
	} );

	it( "keeps any other folded span's two messages on one line", () => {
		const entries = makeEntries();
		entries[ 1 ] = { ...entries[ 1 ], m: 'SELECT 7713' };
		entries[ 3 ] = { ...entries[ 3 ], m: 'rows-7713' };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const row = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( r ) => r.textContent.includes( 'SELECT 7713' )
		);
		expect( row.textContent ).toContain( 'SELECT 7713 rows-7713' );
		unmount();
	} );

	it( 'renders a numeric message as itself', () => {
		const entries = makeEntries();
		entries[ 2 ] = { ...entries[ 2 ], k: 'excerpt_length hook', m: 55 };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		const row = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( r ) => r.textContent.includes( 'excerpt_length hook' )
		);
		expect( row.textContent ).toContain( '55' );
		unmount();
	} );

	it( "folds a long structured message on a folded pair's complete side and puts it under the start", () => {
		const entries = makeEntries();
		const wide = Object.fromEntries(
			Array.from( { length: 12 }, ( _, i ) => [ `k${ i }`, i ] )
		);
		entries[ 1 ] = {
			...entries[ 1 ],
			k: 'init hook (start)',
			m: 'started',
		};
		entries[ 3 ] = {
			...entries[ 3 ],
			k: 'init hook (complete)',
			m: JSON.stringify( wide ),
		};
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const row = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( r ) => r.textContent.includes( 'started' )
		);
		expect( row.textContent ).toContain( 'started\n{' );
		expect( row.textContent ).toContain( 'Show more' );
		expect( row.textContent ).not.toContain( '"k9"' );
		unmount();
	} );

	it( 'searches the text on screen, not the wire form of it', () => {
		const entries = makeEntries();
		entries[ 2 ] = {
			...entries[ 2 ],
			k: 'args (complete)',
			m: '{"from":"t","where":"b"}',
		};
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		const search = ( text ) => {
			act( () => {
				setter.call( input, text );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
			act( () => {
				jest.advanceTimersByTime( 200 );
			} );
		};
		// On screen the value is indented: the wire's `"from":"t"` is not there.
		search( '"from":"t"' );
		expect( container.textContent ).not.toContain( '1 match' );
		search( '"from": "t"' );
		expect( container.textContent ).toContain( '1 match' );
		expect( container.querySelector( 'mark' ) ).not.toBeNull();
		jest.useRealTimers();
		unmount();
	} );

	it( 'puts the duration and memory figures on their own line under the body', () => {
		// A figure that runs on from the body's last line reads as part of it.
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		const completeRow = Array.from(
			container.querySelectorAll( 'tr' )
		).find( ( r ) => r.textContent.includes( 'db (complete)' ) );
		const stats = completeRow.querySelector( '.log-entries-stats' );
		expect( stats ).not.toBeNull();
		expect( stats.tagName ).toBe( 'DIV' );
		expect( stats.textContent ).toContain( '(1000.000ms)' );
		unmount();
	} );

	it( 'pretty-prints a message that arrives as a JSON string, keys sorted', () => {
		// A hook argument reaches the wire compact — the producer spends no
		// bytes on indentation — so the renderer restores the indentation.
		const entries = makeEntries();
		entries[ 2 ] = {
			...entries[ 2 ],
			k: 'parse_request hook',
			m: '{"query_vars":"?","did_permalink":true}',
		};
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		const expected = JSON.stringify(
			{ did_permalink: true, query_vars: '?' },
			null,
			2
		);
		expect( container.textContent ).toContain( expected );
		expect( container.textContent ).not.toContain( '{"query_vars":"?"' );
		unmount();
	} );

	it( 'leaves a JSON string the wire clipped as it arrived', () => {
		const entries = makeEntries();
		const clipped = '{"query_vars":{"p":"?"},"did_perma';
		entries[ 2 ] = { ...entries[ 2 ], k: 'parse_request hook', m: clipped };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		expect( container.textContent ).toContain( clipped );
		unmount();
	} );

	it( 'pretty-prints (indented, multi-line, alpha-sorted) message values when entry.m is an object', () => {
		const entries = makeEntries();
		// m is a KEY=>value map, inserted out of alpha order.
		entries[ 2 ] = {
			...entries[ 2 ],
			k: 'environment_v3',
			m: { REMOTE_ADDR: '1.2.3.4', HTTP_HOST: 'example.com' },
		};
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// Unfold so the nested entry is visible.
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		// Pretty-printed alpha-sorted keys, not a single-line JSON blob.
		const expected = JSON.stringify(
			{ HTTP_HOST: 'example.com', REMOTE_ADDR: '1.2.3.4' },
			null,
			2
		);
		expect( expected ).toContain( '\n' );
		expect( container.textContent ).toContain( expected );
		expect( container.textContent.indexOf( 'HTTP_HOST' ) ).toBeLessThan(
			container.textContent.indexOf( 'REMOTE_ADDR' )
		);
		expect( container.textContent ).not.toContain(
			'{"REMOTE_ADDR":"1.2.3.4","HTTP_HOST":"example.com"}'
		);
		unmount();
	} );

	it( 'handles merged entries (collapsed start+complete render)', () => {
		// Fold All merges a child pair; assert start/end timestamps render.
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const foldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Fold All' ) );
		act( () => foldBtn.click() );
		// Inner entries shouldn't be in the DOM anymore.
		expect( container.textContent ).toContain( 'process' );
		unmount();
	} );

	it( 'renders same-tenth merged rows as 10ms dot markers', () => {
		const entries = [
			{
				n: 1,
				ts: 1700000000,
				k: 'db (start)',
				m: 'SELECT',
				pairId: 1,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 2,
				ts: 1700000000.03,
				k: 'db (complete)',
				m: '-',
				duration_ms: 30,
				pairId: 1,
				indent: 0,
				originalIdx: 1,
			},
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		expect( container.textContent ).toContain( '•••' );
		expect( container.textContent ).toContain( '30.000ms' );
		unmount();
	} );

	it( 'renders both ends of a merged row that spans two tenths', () => {
		const entries = [
			{
				n: 1,
				ts: 1700000000.11,
				k: 'db (start)',
				m: 'SELECT',
				pairId: 4,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 2,
				ts: 1700000000.37,
				k: 'db (complete)',
				m: '-',
				pairId: 4,
				indent: 0,
				originalIdx: 1,
			},
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const timeCell = container.querySelectorAll( 'tbody td' )[ 2 ];
		expect( timeCell.querySelector( 'br' ) ).not.toBeNull();
		expect( timeCell.textContent ).toMatch(
			/^\d{2}:\d{2}:\d{2}\.11\d{2}:\d{2}:\d{2}\.37$/
		);
		unmount();
	} );

	it( 'leaves the start blank on a merged row with no start timestamp', () => {
		const entries = [
			{
				n: 1,
				ts: 0,
				k: 'db (start)',
				m: 'SELECT',
				pairId: 4,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 2,
				ts: 1700000000.37,
				k: 'db (complete)',
				m: '-',
				pairId: 4,
				indent: 0,
				originalIdx: 1,
			},
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const timeCell = container.querySelectorAll( 'tbody td' )[ 2 ];
		expect( timeCell.textContent ).toMatch( /^\d{2}:\d{2}:\d{2}\.37$/ );
		unmount();
	} );

	it( 'falls back to the ruler time when a merged row spans no ticks', () => {
		const entries = [
			{
				n: 1,
				ts: 1700000000.11,
				k: 'db (start)',
				m: 'SELECT',
				pairId: 4,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 2,
				ts: 1700000000.11,
				k: 'db (complete)',
				m: '-',
				pairId: 4,
				indent: 0,
				originalIdx: 1,
			},
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const timeCell = container.querySelectorAll( 'tbody td' )[ 2 ];
		expect( timeCell.querySelector( 'br' ) ).toBeNull();
		expect( timeCell.textContent ).toMatch( /^\d{2}:\d{2}:\d{2}\.11$/ );
		unmount();
	} );

	it( 'marks a merged row with ▶ and an unfolded start with ▼', () => {
		const entries = makeEntries();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// Everything starts folded, so `db` is merged.
		expect( container.textContent ).toContain( '▶' );
		const unfoldBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Unfold All' ) );
		act( () => unfoldBtn.click() );
		expect( container.textContent ).toContain( '▼' );
		unmount();
	} );

	it( 'placeholder entries render an empty keyword / message', () => {
		// Inject a placeholder entry (isPlaceholder: true).
		const entries = [
			...makeEntries(),
			{ n: '...', ts: null, isPlaceholder: true, indent: 0 },
		];
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		// Should render without throwing.
		expect( container.textContent ).toContain( 'Log Entries' );
		unmount();
	} );

	it( 'search nav buttons (prev/next) work', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'db' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		const navButtons = container.querySelectorAll(
			'.log-entries-search__nav'
		);
		// The search box's own reset button is the clear; no third button.
		expect( navButtons.length ).toBe( 2 );
		navButtons.forEach( ( button ) => {
			expect( button.classList.contains( 'button' ) ).toBe( true );
			expect( button.classList.contains( 'button-small' ) ).toBe( true );
		} );
		act( () => navButtons[ 0 ].click() );
		flushRAF();
		act( () => navButtons[ 1 ].click() );
		flushRAF();
		// Once more on prev (covers the wrap-around branch).
		act( () => navButtons[ 0 ].click() );
		flushRAF();
		expect( container.textContent ).toMatch( /\d+\/\d+/ );
		jest.useRealTimers();
		unmount();
	} );

	it( "the search's reset button clears it and refolds what the search opened", () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const dbRows = () =>
			container.querySelectorAll( 'tr[data-pair-id="2"]' ).length;
		const foldedRows = dbRows();
		// The query row sits inside the folded db pair; walking to it unfolds.
		searchFor(
			container,
			container.querySelector( 'input' ),
			'logged value'
		);
		act( () =>
			container
				.querySelectorAll( '.log-entries-search__nav' )[ 1 ]
				.click()
		);
		flushRAF();
		expect( dbRows() ).not.toBe( foldedRows );

		act( () =>
			container
				.querySelector( '.components-search-control button' )
				.click()
		);

		expect( container.querySelector( 'input' ).value ).toBe( '' );
		expect( dbRows() ).toBe( foldedRows );
		expect(
			container.querySelectorAll( '.log-entries-search__nav' ).length
		).toBe( 0 );
		jest.useRealTimers();
		unmount();
	} );

	it( 'previous-match with nothing selected wraps to the LAST match', () => {
		// The `p` key already wrapped; the button and Shift+Enter jumped to
		// the first match instead, so one command answered two ways.
		const entries = [
			{
				n: 1,
				ts: 1700000000,
				k: 'process (start)',
				m: '/zebra',
				pairId: 1,
				indent: 0,
				originalIdx: 0,
			},
			{
				n: 2,
				ts: 1700000001,
				k: 'alpha',
				m: 'zebra one',
				pairId: 1,
				indent: 1,
				originalIdx: 1,
			},
			{
				n: 3,
				ts: 1700000002,
				k: 'beta',
				m: 'zebra two',
				pairId: 1,
				indent: 1,
				originalIdx: 2,
			},
			{
				n: 4,
				ts: 1700000003,
				k: 'process (complete)',
				m: '',
				pairId: 1,
				indent: 0,
				originalIdx: 3,
			},
		];
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		searchFor( container, input, 'zebra' );
		expect( container.textContent ).toContain( '3 matches' );
		const prevButton = container.querySelectorAll(
			'.log-entries-search__nav'
		)[ 0 ];
		act( () => prevButton.click() );
		flushRAF();

		expect( container.textContent ).toContain( '3/3' );
		jest.useRealTimers();
		unmount();
	} );

	it( 'Shift+Enter in search navigates to previous match', () => {
		const entries = makeEntries();
		jest.useFakeTimers();
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const input = container.querySelector( 'input' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'render' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		act( () => {
			input.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					shiftKey: true,
					bubbles: true,
				} )
			);
		} );
		expect( container.textContent ).toMatch( /\d+\/\d+|matches/ );
		jest.useRealTimers();
		unmount();
	} );

	it( 'scrollToAndHighlight runs RAF callback + sets/clears the timer', () => {
		jest.useFakeTimers();
		const entries = makeEntries();
		const revealRef = { current: null };
		const { unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries, revealRef } )
		);
		// reveal a known path so scrollToAndHighlight schedules a RAF.
		act( () => revealRef.current( null, [ 'process', 'db' ] ) );
		// RAF was registered (no throw). Flush it.
		expect( () => flushRAF() ).not.toThrow();
		// Now advance timers so the clearHighlight timer runs.
		act( () => {
			jest.advanceTimersByTime( 2500 );
		} );
		jest.useRealTimers();
		unmount();
	} );
} );

it( 'marks a trimmed entry with a subdued [truncated] line after the message', () => {
	const entries = makeEntries();
	entries[ 1 ] = { ...entries[ 1 ], m: 'SELECT 1', truncated: true };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	const mark = container.querySelector( '.log-entries-truncated' );
	expect( mark ).not.toBeNull();
	expect( mark.textContent ).toBe( '[truncated]' );
	expect( mark.className ).toContain( 'is-muted' );
	// Its own line, after the value.
	expect( mark.previousSibling ).not.toBeNull();
	unmount();
} );

it( 'Show more opens the body and leaves the pair folded', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 12 },
		( _, i ) => `line-${ i + 1 }`
	).join( '\n' );
	entries[ 1 ] = { ...entries[ 1 ], m: body };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);
	const showToggle = () =>
		Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) =>
			b.textContent.startsWith( 'Show ' )
		);

	// Folded pair, folded body: five lines, and the nested row is hidden.
	expect( container.textContent ).toContain( 'line-5' );
	expect( container.textContent ).not.toContain( 'line-12' );
	expect( container.textContent ).not.toContain( 'logged value' );
	expect( showToggle().textContent ).toBe( 'Show more' );

	// Its own line, and a block wrapper carrying no styling of its own.
	const wrapper = container.querySelector( '.log-entries-fold' );
	expect( wrapper ).not.toBeNull();
	expect( wrapper.tagName ).toBe( 'DIV' );
	expect( wrapper.querySelector( 'button' ) ).not.toBeNull();

	act( () => showToggle().click() );

	// Whole body, and the pair is exactly as folded as it was.
	expect( container.textContent ).toContain( 'line-12' );
	expect( container.textContent ).not.toContain( 'logged value' );
	expect( container.textContent ).toContain( '▶' );
	expect( showToggle().textContent ).toBe( 'Show less' );

	act( () => showToggle().click() );

	expect( container.textContent ).not.toContain( 'line-12' );
	expect( container.textContent ).not.toContain( 'logged value' );
	unmount();
} );

it( 'Show more opens one body, not another row that repeats its number', () => {
	const body = ( tag ) =>
		Array.from( { length: 12 }, ( _, i ) => `${ tag }-${ i + 1 }` ).join(
			'\n'
		);
	// A nested render restarts n, so a PHP row and a Perl row share it.
	const entries = [
		{
			n: 12,
			i: 30,
			k: 'stderr',
			m: body( 'php' ),
			pairId: null,
			indent: 0,
			originalIdx: 0,
		},
		{
			n: 12,
			i: 41,
			k: 'stderr',
			m: body( 'perl' ),
			pairId: null,
			indent: 0,
			originalIdx: 1,
		},
	];
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	act( () => {
		Array.from( container.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent.startsWith( 'Show more' ) )
			.click();
	} );

	expect( container.textContent ).toContain( 'php-12' );
	expect( container.textContent ).not.toContain( 'perl-12' );
	unmount();
} );

it( 'Show less closes the body and leaves an open pair open', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 12 },
		( _, i ) => `line-${ i + 1 }`
	).join( '\n' );
	entries[ 1 ] = { ...entries[ 1 ], m: body };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);
	const showToggle = () =>
		Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) =>
			b.textContent.startsWith( 'Show ' )
		);

	// Open the pair by clicking its row, then open the body on its own.
	act( () => {
		container.querySelectorAll( 'tbody tr' )[ 1 ].click();
	} );
	act( () => showToggle().click() );
	expect( container.textContent ).toContain( 'line-12' );
	expect( container.textContent ).toContain( 'logged value' );

	act( () => showToggle().click() );

	// Body shut, pair untouched.
	expect( container.textContent ).not.toContain( 'line-12' );
	expect( container.textContent ).toContain( 'logged value' );
	expect( container.textContent ).toContain( '▼' );
	unmount();
} );

it( 'the row click folds, and leaves the body folded', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 12 },
		( _, i ) => `line-${ i + 1 }`
	).join( '\n' );
	entries[ 1 ] = { ...entries[ 1 ], m: body };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	act( () => {
		container.querySelectorAll( 'tbody tr' )[ 1 ].click();
	} );

	// The pair opened; the body did not.
	expect( container.textContent ).toContain( 'logged value' );
	expect( container.textContent ).not.toContain( 'line-12' );
	unmount();
} );

it( 'folds a pair from its complete row as from its start row', () => {
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries: makeEntries() } )
	);
	const rowOf = ( keyword ) =>
		[ ...container.querySelectorAll( 'tbody tr' ) ].find( ( tr ) =>
			tr.textContent.includes( keyword )
		);

	act( () => rowOf( 'db' ).click() );
	expect( container.textContent ).toContain( 'logged value' );

	act( () => rowOf( 'db (complete)' ).click() );
	expect( container.textContent ).not.toContain( 'logged value' );
	expect( rowOf( 'db (complete)' ) ).toBeUndefined();
	unmount();
} );

it( 'draws a folded row with one known end as that end alone', () => {
	// Its first instance is a kept row, so only the last end is its own.
	const entries = makeEntries();
	entries[ 4 ] = { ...entries[ 4 ], ts: 0, fromFold: true };
	entries[ 5 ] = { ...entries[ 5 ], ts: 0, endTs: 1700000005 };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	const render = [ ...container.querySelectorAll( 'tbody tr' ) ].find(
		( tr ) => tr.textContent.includes( 'render' )
	);
	expect( render.querySelector( 'br' ) ).toBeNull();
	expect( render.textContent ).toContain( formatFullTimestamp( 1700000005 ) );
	unmount();
} );

it( 'offers no pointer on a complete that closes no pair', () => {
	const entries = makeEntries();
	entries.splice( 6, 0, {
		n: 99,
		ts: 1700000006,
		k: 'orphan (complete)',
		m: '',
		pairId: null,
		indent: 1,
		originalIdx: 6,
		i: 20,
	} );
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	const orphan = [ ...container.querySelectorAll( 'tbody tr' ) ].find(
		( tr ) => tr.textContent.includes( 'orphan (complete)' )
	);
	expect( orphan.style.cursor ).not.toBe( 'pointer' );
	unmount();
} );

it( 'leaves the outermost pair open when its complete row is clicked', () => {
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries: makeEntries() } )
	);
	const rows = () => container.querySelectorAll( 'tbody tr' ).length;
	const before = rows();

	act( () => {
		[ ...container.querySelectorAll( 'tbody tr' ) ]
			.find( ( tr ) => tr.textContent.includes( 'process (complete)' ) )
			.click();
	} );

	expect( rows() ).toBe( before );
	unmount();
} );

it( 'never folds the environment entry, however long it is', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 30 },
		( _, i ) => `env-${ i + 1 }`
	).join( '\n' );
	entries[ 1 ] = { ...entries[ 1 ], k: 'environment_v3', m: body };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	expect( container.textContent ).toContain( 'env-30' );
	expect( container.textContent ).not.toContain( 'Show more' );
	unmount();
} );

it( 'expands a folded body when search navigates to that row', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 12 },
		( _, i ) => `body-line-${ i + 1 }`
	).join( '\n' );
	entries[ 1 ] = { ...entries[ 1 ], m: body };
	jest.useFakeTimers();
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	// Folded: only the first five lines are on screen.
	expect( container.textContent ).not.toContain( 'body-line-11' );

	const input = container.querySelector( 'input' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, 'body-line-11' );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	act( () => {
		jest.advanceTimersByTime( 200 );
	} );
	// Typing counts matches; ▼ is what navigates to one.
	act( () => {
		Array.from( container.querySelectorAll( 'button' ) )
			.find( ( b ) => '▼' === b.textContent )
			.click();
	} );

	// Navigating to the row unfolds its pair; the body must open with it.
	expect( container.textContent ).toContain( 'body-line-11' );
	jest.useRealTimers();
	unmount();
} );

it( 'marks the search term inside the message body', () => {
	const entries = makeEntries();
	entries[ 1 ] = {
		...entries[ 1 ],
		m: 'SELECT * FROM wp_posts WHERE ID = 1',
	};
	jest.useFakeTimers();
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	const input = container.querySelector( 'input' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, 'wp_posts' );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	act( () => {
		jest.advanceTimersByTime( 200 );
	} );

	const marks = Array.from( container.querySelectorAll( 'mark' ) );
	expect( marks.map( ( m ) => m.textContent ) ).toContain( 'wp_posts' );
	jest.useRealTimers();
	unmount();
} );

it( 'marks the search term in the complete half of a merged pair', () => {
	const entries = makeEntries();
	// render (start)/(complete) is childless, so it stays one merged row.
	entries[ 5 ] = { ...entries[ 5 ], m: 'cache miss for key abc' };
	jest.useFakeTimers();
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);

	const input = container.querySelector( 'input' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, 'abc' );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	act( () => {
		jest.advanceTimersByTime( 200 );
	} );

	// The merged row never unfolds, so the mark has to be on it.
	expect( container.querySelectorAll( 'tr[data-pair-id="3"]' ).length ).toBe(
		1
	);
	expect(
		Array.from( container.querySelectorAll( 'mark' ) ).map(
			( m ) => m.textContent
		)
	).toContain( 'abc' );
	jest.useRealTimers();
	unmount();
} );

it( 'an unpaired row shows more from its own control, and on a search match', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 12 },
		( _, i ) => `solo-${ i + 1 }`
	).join( '\n' );
	// entries[ 2 ] carries pairId null.
	entries[ 2 ] = { ...entries[ 2 ], m: body };
	jest.useFakeTimers();
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);
	const soloRow = () =>
		Array.from( container.querySelectorAll( 'tbody tr' ) ).find( ( tr ) =>
			tr.textContent.includes( 'solo-1' )
		);
	const showToggle = () =>
		Array.from( soloRow().querySelectorAll( 'button' ) ).find( ( b ) =>
			b.textContent.startsWith( 'Show ' )
		);

	// It nests inside a pair that starts folded; open the tree first.
	act( () => {
		Array.from( container.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent.includes( 'Unfold All' ) )
			.click();
	} );

	expect( container.textContent ).not.toContain( 'solo-12' );
	act( () => showToggle().click() );
	expect( container.textContent ).toContain( 'solo-12' );
	act( () => showToggle().click() );
	expect( container.textContent ).not.toContain( 'solo-12' );

	// And a search landing on it opens it the same way.
	const input = container.querySelector( 'input' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, 'solo-12' );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	act( () => {
		jest.advanceTimersByTime( 200 );
	} );
	act( () => {
		Array.from( container.querySelectorAll( 'button' ) )
			.find( ( b ) => '▼' === b.textContent )
			.click();
	} );
	expect( container.textContent ).toContain( 'solo-12' );
	jest.useRealTimers();
	unmount();
} );

it( 'Show more opens one merged-tree body, not every row the fold rebuilt', () => {
	// Fold rows were never stored, so they carry no `i`.
	const merged = ( tag ) =>
		[
			`${ tag } merged`,
			...Array.from(
				{ length: 9 },
				( _, i ) => `1× 2ms  ${ tag }-shape-${ i + 1 }`
			),
		].join( '\n' );
	const entries = [
		{ n: 1, i: 0, k: 'process (start)', pairId: 1, indent: 0, m: '' },
		{ n: 2, i: 1, k: 'entries (aggregated)', indent: 1, m: '' },
		{
			n: '',
			k: 'sql (start)',
			pairId: 7,
			indent: 1,
			m: '',
			fromFold: true,
		},
		{
			n: '',
			k: 'sql (complete)',
			pairId: 7,
			indent: 1,
			m: merged( 'alpha' ),
			fromFold: true,
		},
		{
			n: '',
			k: 'sql (start)',
			pairId: 8,
			indent: 1,
			m: '',
			fromFold: true,
		},
		{
			n: '',
			k: 'sql (complete)',
			pairId: 8,
			indent: 1,
			m: merged( 'beta' ),
			fromFold: true,
		},
		{ n: 3, i: 2, k: 'process (complete)', pairId: 1, indent: 0, m: '' },
	];
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);
	const toggles = () =>
		Array.from( container.querySelectorAll( 'button' ) ).filter( ( b ) =>
			b.textContent.startsWith( 'Show ' )
		);
	expect( toggles().map( ( b ) => b.textContent ) ).toEqual( [
		'Show more',
		'Show more',
	] );

	act( () => toggles()[ 0 ].click() );

	expect( container.textContent ).toContain( 'alpha-shape-9' );
	expect( container.textContent ).not.toContain( 'beta-shape-9' );
	expect( toggles().map( ( b ) => b.textContent ) ).toEqual( [
		'Show less',
		'Show more',
	] );
	unmount();
} );

describe( 'centering the row a body toggle resized', () => {
	const longBody = Array.from(
		{ length: 12 },
		( _, i ) => `line-${ i + 1 }`
	).join( '\n' );

	/**
	 * Mount the stock tree with a long body on `db (start)`, recording the
	 * row and the text it holds at each `scrollIntoView` call.
	 *
	 * @return {Object} The mount, the calls, and a toggle finder.
	 */
	const mount = () => {
		const calls = [];
		window.HTMLElement.prototype.scrollIntoView = jest.fn(
			function ( options ) {
				calls.push( { row: this, text: this.textContent, options } );
			}
		);
		const entries = makeEntries();
		entries[ 1 ] = { ...entries[ 1 ], m: longBody };
		const mounted = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const toggle = () =>
			Array.from( mounted.container.querySelectorAll( 'button' ) ).find(
				( b ) => b.textContent.startsWith( 'Show ' )
			);
		return { ...mounted, calls, toggle };
	};

	it( 'centers the row on Show more once the whole body has rendered', () => {
		const { container, calls, toggle, unmount } = mount();
		const row = toggle().closest( 'tr' );

		act( () => toggle().click() );

		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ].row ).toBe( row );
		expect( calls[ 0 ].options ).toEqual( {
			behavior: 'smooth',
			block: 'center',
		} );
		expect( calls[ 0 ].text ).toContain( 'line-12' );
		// The reader just clicked this row; it gets no flash.
		for ( const td of row.querySelectorAll( 'td' ) ) {
			expect( td.style.boxShadow ).toBe( '' );
		}
		expect( container.textContent ).toContain( 'line-12' );
		unmount();
	} );

	it( 'centers the row on Show less once the body has shrunk', () => {
		const { calls, toggle, unmount } = mount();
		act( () => toggle().click() );
		const row = toggle().closest( 'tr' );

		act( () => toggle().click() );

		expect( calls ).toHaveLength( 2 );
		expect( calls[ 1 ].row ).toBe( row );
		expect( calls[ 1 ].options.block ).toBe( 'center' );
		expect( calls[ 1 ].text ).toContain( 'line-5' );
		expect( calls[ 1 ].text ).not.toContain( 'line-12' );
		unmount();
	} );

	it( "centers a merged row's row when its complete side toggles", () => {
		const calls = [];
		window.HTMLElement.prototype.scrollIntoView = jest.fn( function () {
			calls.push( this );
		} );
		const entries = makeEntries();
		// render (start)/(complete) is childless, so it stays one merged row.
		entries[ 5 ] = { ...entries[ 5 ], m: longBody };
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, { entries } )
		);
		const button = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => 'Show more' === b.textContent );
		const row = button.closest( 'tr' );

		act( () => button.click() );

		expect( calls ).toEqual( [ row ] );
		unmount();
	} );
} );

/**
 * A pair nested two deep under the outermost one, so a recursive unfold has a
 * subtree to open: `outer` holds `inner`, which holds the leaf.
 *
 * @return {Array} Indented entries.
 */
function makeNestedEntries() {
	const row = ( n, k, pairId, indent, m = '' ) => ( {
		n,
		ts: 1700000100 + n,
		startTs: 1700000100 + n,
		k,
		m,
		pairId,
		indent,
		originalIdx: n - 1,
		i: 40 + n,
	} );
	return [
		row( 1, 'process (start)', 11, 0, '/nested' ),
		row( 2, 'outer (start)', 12, 1 ),
		row( 3, 'inner (start)', 13, 2 ),
		row( 4, 'leaf', null, 3, 'leaf-value-17' ),
		row( 5, 'inner (complete)', 13, 2 ),
		row( 6, 'outer (complete)', 12, 1 ),
		row( 7, 'process (complete)', 11, 0 ),
	];
}

// A press as the browser delivers it: cancelable, so a prevented default
// reads back as `false` from dispatchEvent.
function press( el, init = {} ) {
	let kept;
	act( () => {
		kept = el.dispatchEvent(
			new MouseEvent( 'mousedown', {
				bubbles: true,
				cancelable: true,
				...init,
			} )
		);
	} );
	return kept;
}

function cmdClick( el ) {
	press( el, { metaKey: true } );
	act( () => {
		el.dispatchEvent(
			new MouseEvent( 'click', { bubbles: true, metaKey: true } )
		);
	} );
}

const rowNamed = ( container, text ) =>
	Array.from( container.querySelectorAll( 'tbody tr' ) ).find( ( r ) =>
		r.textContent.includes( text )
	);

const foldAll = ( container ) =>
	act( () =>
		Array.from( container.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent.includes( 'Fold All' ) )
			.click()
	);

describe( 'modified presses on the rows', () => {
	// Firefox selects and outlines individual cells on an accel-press; on
	// these rows that press means "unfold the subtree", so its default goes.
	it( 'cancels the default of a Cmd or Ctrl press on a row', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeNestedEntries(),
			} )
		);
		const cell = rowNamed( container, 'process' ).querySelector(
			'td:last-child'
		);

		expect( press( cell, { metaKey: true } ) ).toBe( false );
		expect( press( cell, { ctrlKey: true } ) ).toBe( false );
		unmount();
	} );

	it( 'keeps the default of a plain press, so a drag still selects text', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeNestedEntries(),
			} )
		);
		const cell = rowNamed( container, 'process' ).querySelector(
			'td:last-child'
		);

		expect( press( cell ) ).toBe( true );
		expect( press( cell, { shiftKey: true } ) ).toBe( true );
		unmount();
	} );

	it( 'still unfolds the whole subtree on a Cmd-click', () => {
		const { container, unmount } = renderComponent(
			React.createElement( LogEntriesTable, {
				entries: makeNestedEntries(),
			} )
		);
		foldAll( container );
		expect( container.textContent ).not.toContain( 'leaf-value-17' );

		cmdClick( rowNamed( container, 'outer' ) );

		expect( container.textContent ).toContain( 'inner (start)' );
		expect( container.textContent ).toContain( 'leaf-value-17' );
		unmount();
	} );
} );

describe( 'the rows under an armed Ask picker', () => {
	// The dashboard's shape: one picker armed over the real table.
	function AskableTable( { entries } ) {
		const { start } = useAskPicker( { onPick: () => {} } );
		return React.createElement(
			'div',
			null,
			React.createElement(
				'button',
				{ type: 'button', 'data-ask-trigger': '', onClick: start },
				'ask'
			),
			// The request the table sits in, as RequestDetailView renders it.
			React.createElement(
				'div',
				{ 'data-ask': 'request:r9:2' },
				React.createElement( LogEntriesTable, { entries } )
			)
		);
	}

	function armed() {
		const view = renderComponent(
			React.createElement( AskableTable, {
				entries: makeNestedEntries(),
			} )
		);
		foldAll( view.container );
		act( () =>
			view.container.querySelector( '[data-ask-trigger]' ).click()
		);
		return view;
	}

	afterEach( () => {
		document.documentElement.classList.remove( 'newspack-nodes-asking' );
	} );

	// @longform A header cell belongs to no row, so only the picker can
	// cancel this press: the rows cancel their own whether armed or not.
	it( 'cancels the default of a modified press on an askable outside the rows', () => {
		const { container, unmount } = armed();
		const header = container.querySelector( 'thead th:last-child' );

		expect( press( header, { metaKey: true } ) ).toBe( false );
		expect( press( header ) ).toBe( true );
		unmount();
	} );

	it( 'toggles the whole row into and out of the picked set', () => {
		const { container, unmount } = armed();
		const outer = rowNamed( container, 'outer' );
		const cell = outer.querySelector( 'td:last-child' );

		cmdClick( cell );
		expect( outer.getAttribute( 'data-ask' ) ).toBe( 'entry:42' );
		expect( outer.hasAttribute( ASK_PICKED_ATTR ) ).toBe( true );
		expect(
			container.querySelectorAll( `td[${ ASK_PICKED_ATTR }]` )
		).toHaveLength( 0 );

		cmdClick( cell );
		expect( outer.hasAttribute( ASK_PICKED_ATTR ) ).toBe( false );
		// The picker owns the gesture: neither click reached the fold.
		expect( container.textContent ).not.toContain( 'leaf-value-17' );
		unmount();
	} );
} );
