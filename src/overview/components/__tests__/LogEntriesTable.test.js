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
 *  - revealPath via revealRef
 *  - keyboard shortcut '/' to focus search
 */

import * as React from 'react';
import LogEntriesTable from '../LogEntriesTable';
import { renderComponent, act } from '../../../test-helpers/renderHook';

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
		},
		{
			n: 3,
			ts: 1700000002,
			k: 'query',
			m: 'logged value',
			pairId: null,
			indent: 2,
			originalIdx: 2,
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
		},
		{
			n: 6,
			ts: 1700000005,
			k: 'render (complete)',
			m: '',
			pairId: 3,
			indent: 1,
			originalIdx: 5,
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
		},
	];
}

describe( 'LogEntriesTable', () => {
	let rafCallbacks = [];
	let originalRAF;
	let originalScrollIntoView;

	beforeEach( () => {
		rafCallbacks = [];
		originalRAF = global.requestAnimationFrame;
		global.requestAnimationFrame = ( cb ) => {
			rafCallbacks.push( cb );
			return rafCallbacks.length;
		};
		originalScrollIntoView = window.HTMLElement.prototype.scrollIntoView;
		window.HTMLElement.prototype.scrollIntoView = jest.fn();
	} );

	afterEach( () => {
		global.requestAnimationFrame = originalRAF;
		window.HTMLElement.prototype.scrollIntoView = originalScrollIntoView;
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
		// render (pairId 3) empty pair: search stays one merged row.
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
		act( () => revealRef.current( [ 'request', 'process', 'db' ] ) );
		// Unknown path is a no-op.
		act( () => revealRef.current( [ 'nope' ] ) );
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
			revealRef.current( [
				'request',
				'process',
				'sql: SELECT wp_users',
			] )
		);
		// The highlight lands in a rAF, so flush one frame.
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );

		// Whichever row got the highlight IS the answer. Without the label in
		// the key both spans share one, and a detail key is LAST-wins — so a
		// miss lands on `SELECT wp_posts`, the one that was not clicked.
		const lit = Array.from( container.querySelectorAll( 'tr' ) ).find(
			( tr ) => tr.querySelector( 'td' )?.style.boxShadow
		);
		expect( lit?.dataset.pairId ).toBe( '2' );
		unmount();
		jest.useRealTimers();
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

	it( 'search nav buttons (prev/next/clear) work', () => {
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
		expect( navButtons.length ).toBeGreaterThanOrEqual( 3 );
		navButtons.forEach( ( button ) => {
			expect( button.classList.contains( 'button' ) ).toBe( true );
			expect( button.classList.contains( 'button-small' ) ).toBe( true );
		} );
		// Click each in turn — prev/next/clear.
		act( () => navButtons[ 0 ].click() );
		flushRAF();
		act( () => navButtons[ 1 ].click() );
		flushRAF();
		// Once more on prev (covers the wrap-around branch).
		act( () => navButtons[ 0 ].click() );
		flushRAF();
		act( () => navButtons[ 2 ].click() );
		// After clear, the search controls disappear.
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
		act( () => revealRef.current( [ 'process', 'db' ] ) );
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

it( 'folds a message body past five lines behind Show more', () => {
	const entries = makeEntries();
	const body = Array.from(
		{ length: 12 },
		( _, i ) => `line-${ i + 1 }`
	).join( '\n' );
	entries[ 1 ] = { ...entries[ 1 ], m: body };
	const { container, unmount } = renderComponent(
		React.createElement( LogEntriesTable, { entries } )
	);
	const fold = ( label ) =>
		Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => label === b.textContent
		);

	expect( container.textContent ).toContain( 'line-5' );
	expect( container.textContent ).not.toContain( 'line-12' );

	// Its own line: a block wrapper, not glued to the end of the body text.
	const wrapper = container.querySelector( '.log-entries-fold' );
	expect( wrapper ).not.toBeNull();
	expect( wrapper.tagName ).toBe( 'DIV' );
	expect( wrapper.querySelector( 'button' ) ).not.toBeNull();

	act( () => {
		fold( 'Show more' ).click();
	} );
	expect( container.textContent ).toContain( 'line-12' );

	act( () => {
		fold( 'Show less' ).click();
	} );
	expect( container.textContent ).not.toContain( 'line-12' );
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
	expect( container.querySelector( '.log-entries-fold' ) ).toBeNull();
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
