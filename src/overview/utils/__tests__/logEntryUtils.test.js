/**
 * Tests for logEntryUtils — the indent-and-collapse engine that turns
 * a flat list of log entries into the nested view in the request log
 * dashboard.
 *
 * Three exports:
 *   - formatDots(n)              — string of bullets grouped in threes
 *   - computeIndentedEntries(es) — adds indent + pairId + gap placeholders
 *   - computeVisibleEntries     — folds collapsed pairs into merged rows
 *   - getAncestorPairIds       — set of pair ids that must be expanded
 */

import {
	formatDots,
	formatFullTimestamp,
	hasPair,
	isEmptyPairStart,
	computeIndentedEntries,
	computeVisibleEntries,
	getAncestorPairIds,
	isFoldablePairStart,
	spliceFoldedSpans,
} from '../logEntryUtils';

describe( 'isFoldablePairStart', () => {
	it( 'is false for the outermost pair, which is the request itself', () => {
		expect( isFoldablePairStart( 'process (start)' ) ).toBe( false );
	} );

	it( 'is true for any other pair start', () => {
		expect( isFoldablePairStart( 'db (start)' ) ).toBe( true );
		expect( isFoldablePairStart( 'render (start)' ) ).toBe( true );
	} );

	it( 'is true for a base name that merely begins with "process"', () => {
		// A startsWith( 'process ' ) spelling excluded this from Unfold All
		// while the table still drew it foldable and toggled it on click.
		expect( isFoldablePairStart( 'process queue (start)' ) ).toBe( true );
	} );

	it( 'is false for anything that is not a pair start', () => {
		expect( isFoldablePairStart( 'db (complete)' ) ).toBe( false );
		expect( isFoldablePairStart( 'plain message' ) ).toBe( false );
		expect( isFoldablePairStart( '' ) ).toBe( false );
		expect( isFoldablePairStart( undefined ) ).toBe( false );
	} );
} );

describe( 'formatFullTimestamp', () => {
	it( 'renders HH:MM:SS with the hundredths of the timestamp', () => {
		expect( formatFullTimestamp( 1700000000.42 ) ).toMatch(
			/^\d{2}:\d{2}:\d{2}\.42$/
		);
	} );

	it( 'is empty for a missing timestamp, not the epoch', () => {
		expect( formatFullTimestamp( 0 ) ).toBe( '' );
		expect( formatFullTimestamp( undefined ) ).toBe( '' );
	} );
} );

describe( 'hasPair', () => {
	it( 'is true for any pairId, including the falsy zero', () => {
		expect( hasPair( { pairId: 0 } ) ).toBe( true );
		expect( hasPair( { pairId: 37 } ) ).toBe( true );
	} );

	it( 'is false when the entry belongs to no pair', () => {
		expect( hasPair( { pairId: null } ) ).toBe( false );
		expect( hasPair( {} ) ).toBe( false );
	} );
} );

describe( 'isEmptyPairStart', () => {
	const start = { k: 'db (start)', pairId: 37 };
	const complete = { k: 'db (complete)', pairId: 37 };

	it( 'is true for a start its own complete immediately follows', () => {
		expect( isEmptyPairStart( [ start, complete ], 0 ) ).toBe( true );
	} );

	it( 'is false once the pair holds a child', () => {
		const child = { k: 'query', pairId: 37 };
		expect( isEmptyPairStart( [ start, child, complete ], 0 ) ).toBe(
			false
		);
	} );

	it( 'is false for a leaf that merely inherits the enclosing pairId', () => {
		// A leaf carries its parent's pairId, so "next is my complete" alone
		// would call the row before a complete an empty pair start.
		const child = { k: 'query', pairId: 37 };
		expect( isEmptyPairStart( [ start, child, complete ], 1 ) ).toBe(
			false
		);
	} );

	it( 'is false for an unpaired row and past the end of the list', () => {
		expect( isEmptyPairStart( [ { k: 'note', pairId: null } ], 0 ) ).toBe(
			false
		);
		expect( isEmptyPairStart( [ start ], 0 ) ).toBe( false );
	} );
} );

describe( 'formatDots', () => {
	it( 'returns empty string for zero or negative counts', () => {
		expect( formatDots( 0 ) ).toBe( '' );
		expect( formatDots( -3 ) ).toBe( '' );
	} );

	it( 'returns bullets grouped in threes with spaces', () => {
		expect( formatDots( 1 ) ).toBe( '•' );
		expect( formatDots( 3 ) ).toBe( '•••' );
		expect( formatDots( 4 ) ).toBe( '••• •' );
		expect( formatDots( 6 ) ).toBe( '••• •••' );
		expect( formatDots( 9 ) ).toBe( '••• ••• •••' );
	} );
} );

describe( 'an unclosed pair', () => {
	it( 'collapses only its own children, not the rest of the request', () => {
		// A folded request's head is cut positionally, so it routinely ends on
		// a `(start)` whose `(complete)` went with the middle. Scanning forward
		// for a complete that never comes swallowed every remaining row — the
		// marker and the whole kept tail — into one collapsed group.
		const { entries } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'query hook (start)', ts: 1 },
			{ n: 3, k: 'entries (aggregated)', m: '63 merged', ts: 1 },
			{ n: 4, k: 'metadatacache', ts: 2 },
			{ n: 5, k: 'process (complete)', ts: 2 },
		] );
		const visible = computeVisibleEntries( entries, new Set() )
			.filter( ( e ) => e.k )
			.map( ( e ) => e.k );

		expect( visible ).toContain( 'entries (aggregated)' );
		expect( visible ).toContain( 'metadatacache' );
		expect( visible ).toContain( 'process (complete)' );
	} );
} );

describe( 'computeIndentedEntries', () => {
	it( 'closes spans the fold cut open, so the kept tail is not nested under them', () => {
		// A folded request keeps its first and last entries. The head ends
		// wherever the cut fell — often on an unclosed `(start)` — and without
		// a barrier every kept tail row becomes that span's child, so folding
		// it swallows the end of the request into a single collapsed row.
		const { entries: out } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'query hook (start)', ts: 1 },
			{ n: 3, k: 'entries (aggregated)', ts: 1 },
			{ n: 4, k: 'resources', ts: 1 },
			{ n: 5, k: 'process (complete)', ts: 1 },
		] );

		// Inside the request, but no longer inside the severed `query hook`.
		expect( out[ 2 ].indent ).toBe( 1 );
		expect( out[ 3 ].indent ).toBe( 1 );
		expect( out[ 3 ].pairId ).toBe( out[ 0 ].pairId );
		// The request's own pair still matches across the break.
		expect( out[ 4 ].indent ).toBe( 0 );
		expect( out[ 4 ].pairId ).toBe( out[ 0 ].pairId );
	} );

	// The pressure fold merges entries out of the MIDDLE of a span. Both ends
	// survive, so `gyrobase` spans the break exactly as the request does.
	const CUT_INSIDE = [
		{ n: 1, k: 'process (start)', ts: 1 },
		{ n: 2, k: 'gyrobase (start)', ts: 1 },
		{ n: 3, k: 'entries (aggregated)', ts: 1 },
		{ n: 4, k: 'query_sql (start)', ts: 1 },
		{ n: 5, k: 'query_sql (complete)', ts: 1 },
		{ n: 6, k: 'gyrobase (complete)', ts: 1 },
		{ n: 7, k: 'process (complete)', ts: 1 },
	];

	it( 'keeps a span the record still closes after the break', () => {
		const { entries: out } = computeIndentedEntries( CUT_INSIDE );

		// Marker and kept children sit INSIDE gyrobase, not beside it.
		expect( out[ 2 ].indent ).toBe( 2 );
		expect( out[ 3 ].indent ).toBe( 2 );
		expect( out[ 4 ].indent ).toBe( 2 );
		// gyrobase closes at its own level, still paired with its start.
		expect( out[ 5 ].indent ).toBe( 1 );
		expect( out[ 5 ].pairId ).toBe( out[ 1 ].pairId );
		expect( out[ 6 ].indent ).toBe( 0 );
	} );

	it( 'folds a break-spanning pair down to its own merged row', () => {
		// With the children beside it rather than under it, the collapsed scan
		// stopped on the first one and the merged row reported no children.
		const { entries: out } = computeIndentedEntries( CUT_INSIDE );
		const visible = computeVisibleEntries( out, new Set() );

		expect( visible.map( ( e ) => e.k ) ).toEqual( [
			'process (start)',
			'gyrobase',
			'process (complete)',
		] );
		expect( visible[ 1 ].childCount ).toBe( 3 );
	} );

	it( 'keeps the request frame across a break no `(complete)` closes', () => {
		// `process (aborted)` is a terminal too, and matches neither regex, so
		// a rule keeping only spans the record CLOSES drops the request's own
		// frame and every row after the marker escapes it. Pins behaviour the
		// old code also had; it guards the rule, not a fix.
		const { entries: out } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'loop (start)', ts: 1 },
			{ n: 3, k: 'entries (lost)', ts: 1 },
			{ n: 4, k: 'shutdown', ts: 1 },
			{ n: 5, k: 'process (aborted)', ts: 1 },
		] );

		expect( out[ 2 ].indent ).toBe( 1 );
		expect( out[ 3 ].indent ).toBe( 1 );
		expect( out[ 4 ].indent ).toBe( 1 );
	} );

	it( 'ignores the spliced-in middle when deciding what straddles the break', () => {
		// The splice puts the merged middle back between the marker and the
		// tail. A tree node repeating an open span's own name emits a
		// start-only row there, which swallowed the real `(complete)` proving
		// the span straddles — so the head's `gyrobase` was severed after all.
		const flame = {
			name: 'request',
			t: null,
			children: [
				{
					name: 'process',
					count: 1,
					value: 900,
					t: 0,
					children: [
						{
							name: 'gyrobase',
							count: 1,
							value: 880,
							t: 1,
							children: [
								{
									name: 'shortcode',
									count: 1,
									value: 10,
									t: 2,
									children: [],
								},
								{
									name: 'gyrobase',
									count: 2,
									value: 20,
									t: 3,
									children: [],
								},
							],
						},
					],
				},
			],
		};
		const stored = [
			{ n: 1, k: 'process (start)', ts: 1000 },
			{ n: 2, k: 'gyrobase (start)', ts: 1000.01 },
			{ n: 3, k: 'entries (aggregated)', ts: 1000.01 },
			{ n: 4, k: 'gyrobase (complete)', ts: 1002 },
			{ n: 5, k: 'process (complete)', ts: 1002 },
		];
		const { entries: out } = computeIndentedEntries(
			spliceFoldedSpans( stored, flame )
		);
		const byKeyword = new Map(
			out.filter( ( e ) => e.k ).map( ( e ) => [ e.k, e.indent ] )
		);

		expect( byKeyword.get( 'entries (aggregated)' ) ).toBe( 2 );
		expect( byKeyword.get( 'shortcode (start)' ) ).toBe( 2 );
		expect( byKeyword.get( 'gyrobase (complete)' ) ).toBe( 1 );
	} );

	it( 'counts only real rows when a child outlives its parent', () => {
		// `A (start) B (start) A (complete)` leaves B open under a closed A, so
		// the two passes hold different stacks and the fold emits a start-only
		// `B`. Counting that synthetic row consumed the real `B (complete)`
		// and severed the frame it proves is still open.
		// The tree `Flame_Fold` actually builds from these rows: it opens B
		// inside A, so B's path is `process/A/B`, not `process/B`.
		const flame = {
			name: 'request',
			t: null,
			children: [
				{
					name: 'process',
					count: 1,
					value: 90,
					t: 0,
					children: [
						{
							name: 'A',
							count: 1,
							value: 30,
							t: 1,
							children: [
								{
									name: 'B',
									count: 2,
									value: 20,
									t: 1,
									children: [],
								},
							],
						},
					],
				},
			],
		};
		const { entries: out } = computeIndentedEntries(
			spliceFoldedSpans(
				[
					{ k: 'process (start)', ts: 1000 },
					{ k: 'A (start)', ts: 1000 },
					{ k: 'B (start)', ts: 1000 },
					{ k: 'A (complete)', ts: 1000 },
					{ k: 'entries (aggregated)', ts: 1000 },
					{ k: 'B (complete)', ts: 1002 },
					{ k: 'process (complete)', ts: 1002 },
				],
				flame
			)
		);
		const marker = out.find( ( e ) => 'entries (aggregated)' === e.k );

		// Still inside both `process` and the `B` the record has yet to close.
		expect( marker.indent ).toBe( 2 );
		// `Flame_Fold::close()` pops every frame above the match, so the tree
		// holds B at `process/A/B`. Reading it with the other unwind puts B at
		// `process/B`, and the fold then closes a span the tail already closes.
		// The tail's `(complete)` is owed to the B the DISPLAY leaves open —
		// the head's — not to the frame the merged middle opened.
		const realStart = out.find( ( e ) => 'B (start)' === e.k );
		const realClose = out.find(
			( e ) => 'B (complete)' === e.k && ! e.fromFold
		);
		expect( realClose.pairId ).toBe( realStart.pairId );
	} );

	it( 'severs the outer of two same-named frames when one complete follows', () => {
		// The record closes `gyrobase` once, so exactly one of the two open
		// frames straddles the break; the outer one was severed by it.
		const { entries: out } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'gyrobase (start)', ts: 1 },
			{ n: 3, k: 'gyrobase (start)', ts: 1 },
			{ n: 4, k: 'entries (aggregated)', ts: 1 },
			{ n: 5, k: 'query_sql (start)', ts: 1 },
			{ n: 6, k: 'query_sql (complete)', ts: 1 },
			{ n: 7, k: 'gyrobase (complete)', ts: 1 },
			{ n: 8, k: 'process (complete)', ts: 1 },
		] );

		expect( out[ 3 ].indent ).toBe( 2 );
		expect( out[ 4 ].indent ).toBe( 2 );
		expect( out[ 6 ].indent ).toBe( 1 );
		expect( out[ 6 ].pairId ).toBe( out[ 2 ].pairId );
		expect( out[ 7 ].indent ).toBe( 0 );
	} );

	it( 'renders an orphaned complete inside the spans still open', () => {
		// A `(complete)` whose `(start)` the fold merged away has nothing to
		// close. Showing it at indent 0 put it OUTSIDE the request, beside
		// `process (complete)`; it belongs wherever the record still is.
		const { entries: out } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'gyrobase (start)', ts: 1 },
			{ n: 3, k: 'sql (complete)', ts: 1 },
			{ n: 4, k: 'gyrobase (complete)', ts: 1 },
			{ n: 5, k: 'process (complete)', ts: 1 },
		] );

		expect( out[ 2 ].indent ).toBe( 2 );
	} );

	it( 'treats a lost-entries marker as the same kind of break', () => {
		const { entries: out } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'loop (start)', ts: 1 },
			{ n: 3, k: 'entries (lost)', ts: 1 },
			{ n: 4, k: 'process (complete)', ts: 1 },
		] );

		expect( out[ 2 ].indent ).toBe( 1 );
		expect( out[ 3 ].indent ).toBe( 0 );
	} );

	it( 'returns empty result for null/empty input', () => {
		expect( computeIndentedEntries( null ) ).toEqual( {
			entries: [],
			realCount: 0,
		} );
		expect( computeIndentedEntries( [] ) ).toEqual( {
			entries: [],
			realCount: 0,
		} );
	} );

	it( 'assigns indent based on nested (start)/(complete) pairs', () => {
		const out = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'b (start)', ts: 1.0001 },
			{ k: 'b (complete)', ts: 1.0002 },
			{ k: 'a (complete)', ts: 1.0003 },
		] );
		const indents = out.entries.map( ( e ) => e.indent );
		expect( indents ).toEqual( [ 0, 1, 1, 0 ] );
		expect( out.realCount ).toBe( 4 );
		// Matching start/complete share pairId.
		expect( out.entries[ 0 ].pairId ).toBe( out.entries[ 3 ].pairId );
		expect( out.entries[ 1 ].pairId ).toBe( out.entries[ 2 ].pairId );
	} );

	it( 'uses LIFO name matching for improperly nested pairs', () => {
		const out = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'b (start)', ts: 1.0001 },
			{ k: 'a (complete)', ts: 1.0002 }, // close a while b still open.
			{ k: 'b (complete)', ts: 1.0003 },
		] );
		// 'a (complete)' matches start at idx 0 → indent 0.
		expect( out.entries[ 2 ].indent ).toBe( 0 );
		expect( out.entries[ 0 ].pairId ).toBe( out.entries[ 2 ].pairId );
		// 'b (complete)' still has start on the stack.
		expect( out.entries[ 1 ].pairId ).toBe( out.entries[ 3 ].pairId );
	} );

	it( 'gives orphan completes pairId=null and indent=0', () => {
		const out = computeIndentedEntries( [ { k: 'a (complete)', ts: 1 } ] );
		expect( out.entries[ 0 ].indent ).toBe( 0 );
		expect( out.entries[ 0 ].pairId ).toBeNull();
	} );

	it( 'leaf entries take current stack depth and parent pairId', () => {
		const out = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'something', ts: 1.0001 }, // leaf at indent 1.
			{ k: 'a (complete)', ts: 1.0002 },
		] );
		expect( out.entries[ 1 ].indent ).toBe( 1 );
		expect( out.entries[ 1 ].pairId ).toBe( out.entries[ 0 ].pairId );
	} );

	it( 'inserts placeholder rows for time gaps between entries', () => {
		const out = computeIndentedEntries( [
			{ k: 'a', ts: 1.0 }, // hundredth=100
			{ k: 'b', ts: 1.5 }, // hundredth=150 → 49 placeholders between.
		] );
		const placeholders = out.entries.filter( ( e ) => e.isPlaceholder );
		expect( placeholders.length ).toBeGreaterThan( 0 );
		// realCount should ignore placeholders.
		expect( out.realCount ).toBe( 2 );
	} );
} );

describe( 'computeVisibleEntries', () => {
	it( 'returns [] for empty input', () => {
		expect( computeVisibleEntries( [], new Set() ) ).toEqual( [] );
		expect( computeVisibleEntries( null, new Set() ) ).toEqual( [] );
	} );

	it( 'merges a collapsed start/complete pair into one row', () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'leaf', ts: 1.001 },
			{ k: 'a (complete)', ts: 1.002, duration_ms: 2 },
		] );
		const visible = computeVisibleEntries( entries, new Set() );
		const merged = visible.find( ( e ) => e.isMerged );
		expect( merged ).toBeDefined();
		expect( merged.k ).toBe( 'a' );
		expect( merged.childCount ).toBe( 1 );
		expect( merged.duration_ms ).toBe( 2 );
		// The complete row is consumed.
		expect(
			visible.find( ( e ) => e.k === 'a (complete)' )
		).toBeUndefined();
	} );

	it( 'expands a pair when its pairId is in the set', () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'leaf', ts: 1.001 },
			{ k: 'a (complete)', ts: 1.002 },
		] );
		const aPairId = entries[ 0 ].pairId;
		const visible = computeVisibleEntries(
			entries,
			new Set( [ aPairId ] )
		);
		// Both start and complete are visible.
		expect( visible.some( ( e ) => e.k === 'a (start)' ) ).toBe( true );
		expect( visible.some( ( e ) => e.k === 'a (complete)' ) ).toBe( true );
		expect( visible.some( ( e ) => e.isMerged ) ).toBe( false );
	} );

	it( "never collapses the outermost 'process' pair", () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'process (start)', ts: 1 },
			{ k: 'process (complete)', ts: 1.001 },
		] );
		const visible = computeVisibleEntries( entries, new Set() );
		// process should appear as its start row, never merged.
		expect( visible.some( ( e ) => e.k === 'process (start)' ) ).toBe(
			true
		);
		expect( visible.some( ( e ) => e.isMerged ) ).toBe( false );
	} );
} );

describe( 'placeholder runs in computeVisibleEntries', () => {
	it( 'collapses a placeholder gap into dot + timestamp rows', () => {
		// 1.0s→1.5s = 50 hundredths → collapser emits dot + timestamp rows.
		const { entries } = computeIndentedEntries( [
			{ k: 'a', ts: 1.0 },
			{ k: 'b', ts: 1.5 },
		] );
		const visible = computeVisibleEntries( entries, new Set() );
		const placeholders = visible.filter( ( e ) => e.isPlaceholder );
		expect( placeholders.length ).toBeGreaterThan( 0 );
		const dotRows = placeholders.filter( ( e ) =>
			( e.displayTime || '' ).includes( '•' )
		);
		const tsRows = placeholders.filter( ( e ) =>
			( e.displayTime || '' ).match( /\d+:\d+:\d+\.\d+/ )
		);
		expect( dotRows.length ).toBeGreaterThan( 0 );
		expect( tsRows.length ).toBeGreaterThan( 0 );
	} );

	it( 'collapses a long placeholder gap into escalating timestamps (Phase 2)', () => {
		// 1.0s→100.0s = 9900 hundredths: Phase 1 emits 2, then Phase 2.
		const { entries } = computeIndentedEntries( [
			{ k: 'a', ts: 1.0 },
			{ k: 'b', ts: 100.0 },
		] );
		const visible = computeVisibleEntries( entries, new Set() );
		const placeholders = visible.filter( ( e ) => e.isPlaceholder );
		expect( placeholders.length ).toBeGreaterThan( 0 );
		expect( placeholders.length ).toBeLessThan( 1000 );
	} );

	it( 'ensures the last visible row carries a timestamp', () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'a', ts: 1.0 },
			{ k: 'b', ts: 1.05 },
		] );
		const visible = computeVisibleEntries( entries, new Set() );
		const last = visible[ visible.length - 1 ];
		expect( last.displayTime ).toBeTruthy();
		expect( last.displayTime.startsWith( '•' ) ).toBe( false );
	} );
} );

describe( 'getAncestorPairIds', () => {
	it( 'returns an empty set for an out-of-bounds index', () => {
		expect( getAncestorPairIds( 99, [] ).size ).toBe( 0 );
	} );

	it( 'walks back to collect ancestor pair ids for a nested start', () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'b (start)', ts: 1.001 },
			{ k: 'b (complete)', ts: 1.002 },
			{ k: 'a (complete)', ts: 1.003 },
		] );
		// 'b (start)' at indent 1: own pairId + walk back to 'a (start)'.
		const bIdx = entries.findIndex( ( e ) => e.k === 'b (start)' );
		const ids = getAncestorPairIds( bIdx, entries );
		expect( ids.has( entries[ 0 ].pairId ) ).toBe( true );
		expect( ids.has( entries[ 1 ].pairId ) ).toBe( true );
	} );

	it( 'expands the containing pair for a nested leaf (error) entry', () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'include (start)', ts: 1 },
			{ k: 'include (error)', ts: 1.001 },
			{ k: 'include (complete)', ts: 1.002 },
		] );
		// The error line is a CHILD of include; walk one indent up.
		const errIdx = entries.findIndex( ( e ) => e.k === 'include (error)' );
		const parentPairId = entries.find(
			( e ) => e.k === 'include (start)'
		).pairId;
		expect(
			getAncestorPairIds( errIdx, entries ).has( parentPairId )
		).toBe( true );
	} );

	it( "includes the start entry's own pairId", () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
			{ k: 'a (complete)', ts: 1.001 },
		] );
		const ids = getAncestorPairIds( 0, entries );
		expect( ids.has( entries[ 0 ].pairId ) ).toBe( true );
	} );

	it( 'returns an empty set when targetIdx is null/out-of-range', () => {
		const { entries } = computeIndentedEntries( [
			{ k: 'a (start)', ts: 1 },
		] );
		expect( getAncestorPairIds( 100, entries ).size ).toBe( 0 );
	} );
} );

describe( 'getAncestorPairIds for an orphaned complete', () => {
	it( 'reveals only the spans that actually contain it', () => {
		// An orphan has no `(start)`, so nothing at its own level encloses it.
		// Looking there matched a preceding SIBLING pair, and clicking a search
		// hit unfolded a span the row was never inside.
		const { entries } = computeIndentedEntries( [
			{ n: 1, k: 'process (start)', ts: 1 },
			{ n: 2, k: 'gyrobase (start)', ts: 1 },
			{ n: 3, k: 'template (start)', ts: 1 },
			{ n: 4, k: 'template (complete)', ts: 1 },
			{ n: 5, k: 'sql (complete)', ts: 1 },
			{ n: 6, k: 'gyrobase (complete)', ts: 1 },
			{ n: 7, k: 'process (complete)', ts: 1 },
		] );
		const orphan = entries.findIndex( ( e ) => 'sql (complete)' === e.k );
		const ids = getAncestorPairIds( orphan, entries );

		// process and gyrobase enclose it; the closed template does not.
		expect( ids.size ).toBe( 2 );
		expect( ids.has( entries[ 2 ].pairId ) ).toBe( false );
	} );
} );

describe( 'a merged span beside the one the head left open', () => {
	const node = ( name, count, children = [] ) => ( {
		name,
		count,
		value: 10,
		t: 1,
		children,
	} );
	const nest = ( children ) => ( {
		name: 'request',
		t: null,
		children: [ node( 'process', 1, children ) ],
	} );
	const indentsOf = ( stored, flame ) =>
		computeIndentedEntries( spliceFoldedSpans( stored, flame ) )
			.entries.filter( ( e ) => e.k )
			.map( ( e ) => [ e.k, e.indent ] );

	it( "emits the siblings that only share the open span's base name", () => {
		// `Flame_Fold` names nodes `base: label`, so `template: Home.html` and
		// `template: Nav.html` are distinct spans with one base. Skipping by
		// base dissolved BOTH, and Nav's children became Home's.
		const rows = indentsOf(
			[
				{ k: 'process (start)', ts: 1000 },
				{ k: 'template (start)', l: 'Home.html', ts: 1000 },
				{ k: 'entries (aggregated)', ts: 1000 },
				{ k: 'template (complete)', ts: 1002 },
				{ k: 'process (complete)', ts: 1002 },
			],
			nest( [
				node( 'template: Home.html', 1, [ node( 'sql', 4 ) ] ),
				node( 'template: Nav.html', 1, [ node( 'shortcode', 2 ) ] ),
			] )
		);
		const keywords = rows.map( ( [ k ] ) => k );

		expect( keywords ).toContain( 'template: Nav.html (start)' );
		// Nav's child is inside Nav, not beside Home's.
		expect( rows ).toContainEqual( [ 'sql (start)', 2 ] );
		expect( rows ).toContainEqual( [ 'shortcode (start)', 3 ] );
	} );

	it( 'never reports a negative or phantom merge count', () => {
		// `kept` counts complete pairs by path, and labelled siblings share one,
		// so it is spent rather than re-read: otherwise the second says
		// "0 merged" for a span nothing merged and the third goes negative.
		// Unreachable before these siblings were emitted at all, so this pins
		// the rule rather than proving a fix.
		const rows = computeIndentedEntries(
			spliceFoldedSpans(
				[
					{ k: 'process (start)', ts: 1000 },
					{ k: 'template (start)', ts: 1000 },
					{ k: 'template (complete)', ts: 1000 },
					{ k: 'template (start)', ts: 1000 },
					{ k: 'template (complete)', ts: 1000 },
					{ k: 'template (start)', ts: 1000 },
					{ k: 'entries (aggregated)', ts: 1000 },
					{ k: 'template (complete)', ts: 1002 },
					{ k: 'process (complete)', ts: 1002 },
				],
				nest( [
					node( 'template: Home.html', 1, [ node( 'sql', 4 ) ] ),
					node( 'template: Nav.html', 1, [ node( 'shortcode', 2 ) ] ),
					node( 'template: Foot.html', 1, [ node( 'menu', 2 ) ] ),
				] )
			)
		).entries;
		const merges = rows
			.filter( ( e ) => ( e.m || '' ).includes( 'merged' ) )
			.map( ( e ) => e.m );

		// Neither a negative count nor a merge that never happened.
		expect( merges.some( ( m ) => m.startsWith( '-' ) ) ).toBe( false );
		expect( merges ).not.toContain( '0 merged' );
	} );

	it( 'claims the labelled sibling the head actually left open', () => {
		// `Flame_Fold::open()` names a node `base: label` and paths it by that
		// name, though its stack still MATCHES on the base. Reading the open
		// path as bare bases hands the claim to the first sibling sharing it —
		// here the Nav that already closed — so Nav's children reparent onto
		// the real open span and Home is re-emitted inside itself.
		const rows = computeIndentedEntries(
			spliceFoldedSpans(
				[
					{ k: 'process (start)', ts: 1000 },
					{ k: 'template (start)', l: 'Nav.html', ts: 1000 },
					{ k: 'template (complete)', ts: 1000 },
					{ k: 'template (start)', l: 'Home.html', ts: 1000 },
					{ k: 'entries (aggregated)', ts: 1000 },
					{ k: 'template (complete)', ts: 1002 },
					{ k: 'process (complete)', ts: 1002 },
				],
				nest( [
					node( 'template: Nav.html', 1, [ node( 'shortcode', 2 ) ] ),
					node( 'template: Home.html', 1, [ node( 'sql', 4 ) ] ),
				] )
			)
		).entries;
		const keywords = rows.map( ( e ) => e.k );

		// Home is the span on screen, so the tree must not re-emit it.
		expect( keywords ).not.toContain( 'template: Home.html (start)' );
		// Nav is a different span and keeps its own frame.
		expect( keywords ).toContain( 'template: Nav.html (start)' );
	} );

	it( 'stops carrying the chain at the end of the claimed subtree', () => {
		// The claim is per sibling LIST, so every sibling after the claimed one
		// used to inherit the open chain and dissolve a same-named node in its
		// own branch. `other`'s `template` must keep its frame, and `cache`
		// must stay inside it rather than reparenting onto `other`.
		const rows = indentsOf(
			[
				{ k: 'process (start)', ts: 1000 },
				{ k: 'gyrobase (start)', ts: 1000 },
				{ k: 'template (start)', ts: 1000 },
				{ k: 'entries (aggregated)', ts: 1000 },
				{ k: 'template (complete)', ts: 1002 },
				{ k: 'gyrobase (complete)', ts: 1002 },
				{ k: 'process (complete)', ts: 1002 },
			],
			nest( [
				node( 'gyrobase', 1, [
					node( 'template', 1, [ node( 'sql', 2 ) ] ),
				] ),
				node( 'other', 2, [
					node( 'template', 0, [ node( 'cache', 3 ) ] ),
				] ),
			] )
		);
		const keywords = rows.map( ( [ k ] ) => k );

		// Two `template` rows: the head's own, and `other`'s merged one.
		expect(
			keywords.filter( ( k ) => k.startsWith( 'template (' ) )
		).toHaveLength( 4 );
		expect( rows ).toContainEqual( [ 'cache (start)', 5 ] );
	} );

	it( 'reads kept paths the way the merged tree built them', () => {
		// `Flame_Fold::close()` pops every frame above the match, so closing X
		// drops Y and the later Z opens at `process/Z`. Reading the log with
		// the other unwind leaves Y on the stack and keys the kept pair
		// `process/Y/Z`, so the tree's Z finds nothing on screen to deduct.
		const rows = computeIndentedEntries(
			spliceFoldedSpans(
				[
					{ k: 'process (start)', ts: 1000 },
					{ k: 'X (start)', ts: 1000 },
					{ k: 'Y (start)', ts: 1000 },
					{ k: 'X (complete)', ts: 1000 },
					{ k: 'Z (start)', ts: 1000 },
					{ k: 'Z (complete)', ts: 1000 },
					{ k: 'entries (aggregated)', ts: 1000 },
					{ k: 'process (complete)', ts: 1002 },
				],
				nest( [ node( 'X', 1, [ node( 'Y', 1 ) ] ), node( 'Z', 2 ) ] )
			)
		).entries;
		const merged = rows.find(
			( e ) => e.fromFold && 'Z (complete)' === e.k
		);

		// Two ran at that path and one is on screen, so one was merged away.
		expect( merged.m ).toBe( '1 merged' );
	} );

	it( 'attributes a kept pair to the node whose path it ran at', () => {
		// The kept `sql` pair ran INSIDE gyrobase. Spending the count on the
		// first same-base node visited gave it to the shallow one, dropping
		// that span from the record and over-counting the deep one.
		const rows = computeIndentedEntries(
			spliceFoldedSpans(
				[
					{ k: 'process (start)', ts: 1000 },
					{ k: 'gyrobase (start)', ts: 1000 },
					{ k: 'sql (start)', ts: 1000 },
					{ k: 'sql (complete)', ts: 1000 },
					{ k: 'entries (aggregated)', ts: 1000 },
					{ k: 'gyrobase (complete)', ts: 1002 },
					{ k: 'process (complete)', ts: 1002 },
				],
				nest( [
					node( 'sql', 1 ),
					node( 'gyrobase', 1, [ node( 'sql', 3 ) ] ),
				] )
			)
		).entries;
		const starts = rows.filter( ( e ) => 'sql (start)' === e.k );

		// The kept row, plus a frame for each node: base keying cancelled the
		// shallow one against a pair that ran at the deep path.
		expect( starts ).toHaveLength( 3 );
		// Three ran at the deep path, one of them already on screen.
		expect( rows.map( ( e ) => e.m ) ).toContain( '2 merged' );
	} );

	it( 'keeps claiming the open chain below a span with merged instances', () => {
		// When the open span's node has merged instances it emits a frame
		// rather than being skipped, and the chain has to carry on below it or
		// the `template` the head still has open is re-emitted as a duplicate.
		// Unreachable before that node could emit, so this pins the rule.
		const rows = computeIndentedEntries(
			spliceFoldedSpans(
				[
					{ k: 'process (start)', ts: 1000 },
					{ k: 'gyrobase (start)', ts: 1000 },
					{ k: 'template (start)', ts: 1000 },
					{ k: 'entries (aggregated)', ts: 1000 },
					{ k: 'template (complete)', ts: 1002 },
					{ k: 'gyrobase (complete)', ts: 1002 },
					{ k: 'gyrobase (complete)', ts: 1002 },
					{ k: 'process (complete)', ts: 1002 },
				],
				nest( [
					node( 'gyrobase', 3, [
						node( 'template', 1, [ node( 'sql', 2 ) ] ),
					] ),
				] )
			)
		).entries;

		expect(
			rows.filter( ( e ) => 'template (start)' === e.k )
		).toHaveLength( 1 );
	} );

	it( 'claims the open span only inside the subtree that is on the path', () => {
		// `onScreen` resets for every sibling's child list, so a node in an
		// unrelated subtree matched `openPath[ depth ]` and was dissolved —
		// its frame vanished and its children rendered one level too shallow.
		const rows = indentsOf(
			[
				{ k: 'process (start)', ts: 1000 },
				{ k: 'gyrobase (start)', ts: 1000 },
				{ k: 'entries (aggregated)', ts: 1000 },
				{ k: 'gyrobase (complete)', ts: 1002 },
				{ k: 'process (complete)', ts: 1002 },
			],
			{
				name: 'request',
				t: null,
				children: [
					node( 'process', 1, [ node( 'gyrobase', 1 ) ] ),
					node( 'bootstrap', 1, [
						node( 'gyrobase', 1, [ node( 'cache', 2 ) ] ),
					] ),
				],
			}
		);
		const keywords = rows.map( ( [ k ] ) => k );

		// bootstrap's own gyrobase is not the span the head left open.
		expect( keywords ).toContain( 'bootstrap (start)' );
		expect(
			keywords.filter( ( k ) => 'gyrobase (start)' === k )
		).toHaveLength( 2 );
	} );

	it( 'unwinds the stack when reading which spans the head left open', () => {
		// Three readers share one LIFO walk, so its unwind is load-bearing:
		// leave a CLOSED span in `openPath` and every depth shifts, so the span
		// actually open is re-emitted as a merged frame. Pins the rule.
		const rows = indentsOf(
			[
				{ k: 'process (start)', ts: 1000 },
				{ k: 'template (start)', ts: 1000 },
				{ k: 'template (complete)', ts: 1000 },
				{ k: 'gyrobase (start)', ts: 1000 },
				{ k: 'entries (aggregated)', ts: 1000 },
				{ k: 'gyrobase (complete)', ts: 1002 },
				{ k: 'process (complete)', ts: 1002 },
			],
			nest( [ node( 'gyrobase', 1, [ node( 'sql', 4 ) ] ) ] )
		);
		const keywords = rows.map( ( [ k ] ) => k );

		expect( keywords ).toContain( 'sql (start)' );
		// One real `gyrobase (start)`; the tree's is the same span, not a new one.
		expect(
			keywords.filter( ( k ) => 'gyrobase (start)' === k )
		).toHaveLength( 1 );
	} );

	it( 'gives a middle-born span of the same name its own frame', () => {
		// Three `gyrobase` instances merge into one node: the head's, plus two
		// from the middle. Skipping the node whole left the second tail
		// `(complete)` with nothing to close, orphaning it outside the request.
		const rows = indentsOf(
			[
				{ k: 'process (start)', ts: 1000 },
				{ k: 'gyrobase (start)', ts: 1000 },
				{ k: 'entries (aggregated)', ts: 1000 },
				{ k: 'gyrobase (complete)', ts: 1002 },
				{ k: 'gyrobase (complete)', ts: 1002 },
				{ k: 'process (complete)', ts: 1002 },
			],
			nest( [ node( 'gyrobase', 3, [ node( 'sql', 5 ) ] ) ] )
		);

		expect(
			rows.filter( ( [ k ] ) => 'gyrobase (start)' === k )
		).toHaveLength( 2 );
		// Both completes close a frame inside the request; neither escapes it.
		expect(
			rows.filter( ( [ k ] ) => 'gyrobase (complete)' === k )
		).toEqual( [
			[ 'gyrobase (complete)', 2 ],
			[ 'gyrobase (complete)', 1 ],
		] );
	} );
} );

describe( 'spliceFoldedSpans', () => {
	const FLAME = {
		name: 'request',
		t: null,
		children: [
			{
				name: 'process',
				count: 1,
				value: 900,
				t: 0,
				children: [
					{
						name: 'pyrobase',
						count: 1,
						value: 880,
						t: 8.7,
						children: [
							{
								name: 'function',
								count: 200,
								value: 56,
								t: 1373.3,
								children: [],
							},
						],
					},
				],
			},
		],
	};

	// A head cut mid-request: `process` and `pyrobase` are both still open when
	// the fold takes over, and the tail closes them.
	const STRADDLING = [
		{ n: 1, k: 'process (start)', ts: 1000 },
		{ n: 2, k: 'pyrobase (start)', ts: 1000.01 },
		{ n: 3, k: 'entries (aggregated)', m: '63 merged', ts: 1000.01 },
		{ n: 4, k: 'pyrobase (complete)', ts: 1002 },
		{ n: 5, k: 'process (complete)', ts: 1002 },
	];

	it( 'emits no second pair for a span the head already opened', () => {
		// `pyrobase` straddles the gap: opened in the kept head, closed in the
		// kept tail. Emitting it again from the tree showed it twice.
		const out = spliceFoldedSpans( STRADDLING, FLAME );
		const keywords = out.map( ( e ) => e.k );

		expect(
			keywords.filter( ( k ) => 'pyrobase (complete)' === k )
		).toHaveLength( 1 );
		expect(
			keywords.filter( ( k ) => 'pyrobase (start)' === k )
		).toHaveLength( 1 );
		// Its merged child is what the gap actually contributes.
		expect( keywords ).toContain( 'function (start)' );
	} );

	it( 'emits no complete for a span the kept tail closes', () => {
		// `pyrobase` opens in the FOLDED MIDDLE and closes in the kept tail, so
		// the tree supplied a merged pair AND the tail supplied a real complete
		// — two `pyrobase (complete)` rows for one span. The tail's is the real
		// one; the tree contributes only the opening.
		const straddlesFooter = [
			{ n: 1, k: 'process (start)', ts: 1000 },
			{ n: 2, k: 'query hook (start)', ts: 1000.01 },
			{ n: 3, k: 'entries (aggregated)', m: '40249 merged', ts: 1000.01 },
			{ n: 40266, k: 'pyrobase (complete)', ts: 1044 },
			{ n: 40269, k: 'process (complete)', ts: 1044 },
		];

		const out = spliceFoldedSpans( straddlesFooter, FLAME );
		const keywords = out.map( ( e ) => e.k );

		expect(
			keywords.filter( ( k ) => 'pyrobase (complete)' === k )
		).toHaveLength( 1 );
		expect( keywords ).toContain( 'pyrobase (start)' );
		// Its wholly-inside-the-gap child keeps both halves.
		expect(
			keywords.filter( ( k ) => 'function (complete)' === k )
		).toHaveLength( 1 );
	} );

	it( 'does not re-emit a span that lives wholly inside the kept head', () => {
		// The fold replays the kept head into the tree and adds every tail
		// entry to it, so a pair that opens AND closes inside either end is in
		// the merged tree too — shown once for real and once as a "1 merged"
		// ghost of itself.
		const flame = {
			name: 'request',
			t: null,
			children: [
				{
					name: 'process',
					count: 1,
					value: 900,
					t: 0,
					children: [
						{
							name: 'locale hook',
							count: 1,
							value: 0.005,
							t: 1,
							children: [],
						},
						{
							name: 'loop',
							count: 5674,
							value: 27,
							t: 500,
							children: [],
						},
					],
				},
			],
		};
		const entries = [
			{ n: 1, k: 'process (start)', ts: 1000 },
			{ n: 2, k: 'locale hook (start)', ts: 1000.001 },
			{ n: 3, k: 'locale hook (complete)', ts: 1000.001 },
			{
				n: 4,
				k: 'entries (aggregated)',
				m: '40249 merged',
				ts: 1000.001,
			},
			{ n: 40269, k: 'process (complete)', ts: 1044 },
		];

		const keywords = spliceFoldedSpans( entries, flame ).map(
			( e ) => e.k
		);

		expect(
			keywords.filter( ( k ) => 'locale hook (complete)' === k )
		).toHaveLength( 1 );
		// The one that really is in the gap still comes through.
		expect( keywords ).toContain( 'loop (complete)' );
	} );

	it( 'stamps merged rows with a real timestamp off the request origin', () => {
		// Not a "+1373.3ms" caption: a ts the view can gap and rule like any
		// other row. Origin is the first kept entry, so 1000 + 1.3733s.
		const out = spliceFoldedSpans( STRADDLING, FLAME );
		const opened = out.find( ( e ) => 'function (start)' === e.k );

		expect( opened.ts ).toBeCloseTo( 1001.3733, 4 );
	} );

	it( 'leaves an unfolded request alone', () => {
		const plain = [ { n: 1, k: 'process (start)', ts: 1000 } ];
		expect( spliceFoldedSpans( plain, null ) ).toBe( plain );
	} );
} );

describe( 'placeholder gap rows across a sequence break', () => {
	// A folded record: kept head, the marker, then rows the fold selected out
	// of the middle — non-contiguous, so the interval between them is missing
	// detail rather than elapsed time.
	const FOLDED = [
		{ n: 71, k: 'process (start)', ts: 4242.07 },
		{
			n: 72,
			k: 'entries (aggregated)',
			m: '5312 entries merged into the flame graph under memory pressure',
			ts: 4242.07,
		},
		{ n: 73, k: 'shortcode (start)', ts: 4242.53 },
		{ n: 74, k: 'shortcode (complete)', ts: 4242.61, duration_ms: 83 },
		{ n: 75, k: 'process (complete)', ts: 4242.88 },
	];

	it( 'draws no ruler anywhere in a folded record', () => {
		const { entries } = computeIndentedEntries( FOLDED );

		expect( entries.filter( ( e ) => e.isPlaceholder ) ).toHaveLength( 0 );
	} );

	it( "leaves a folded record's real rows exactly as they were", () => {
		const { entries, realCount } = computeIndentedEntries( FOLDED );

		expect( entries.map( ( e ) => e.k ) ).toEqual( [
			'process (start)',
			'entries (aggregated)',
			'shortcode (start)',
			'shortcode (complete)',
			'process (complete)',
		] );
		expect( entries.map( ( e ) => e.indent ) ).toEqual( [ 0, 1, 1, 1, 0 ] );
		expect( entries[ 2 ].pairId ).toBe( entries[ 3 ].pairId );
		expect( realCount ).toBe( 5 );
	} );

	it( 'still draws it for the same gaps without the fold marker', () => {
		// The one that proves the ruler was scoped rather than deleted.
		const unfolded = FOLDED.map( ( e ) =>
			'entries (aggregated)' === e.k ? { ...e, k: 'metadatacache' } : e
		);
		const { entries } = computeIndentedEntries( unfolded );

		expect(
			entries.filter( ( e ) => e.isPlaceholder ).length
		).toBeGreaterThan( 0 );
	} );

	// A record that merely LOST entries keeps a real timestamp on every
	// survivor, so only the interval touching the marker is unmeasurable.
	const LOST = [
		{ n: 71, k: 'process (start)', ts: 4242.07 },
		{ n: 72, k: 'template', ts: 4242.53 },
		{
			n: 73,
			k: 'entries (lost)',
			m: 'discarded entries after #72',
			ts: 4242.61,
		},
		{ n: 74, k: 'process (complete)', ts: 4243.44 },
	];

	it( 'draws no ruler across a lost-entries marker', () => {
		const { entries } = computeIndentedEntries( LOST );
		const spanned = entries
			.filter( ( e ) => e.isPlaceholder )
			.map( ( e ) => e.ts );

		expect( spanned.filter( ( ts ) => ts > 4242.53 ) ).toHaveLength( 0 );
	} );

	it( 'still draws it between two survivors of a lost-entries record', () => {
		const { entries } = computeIndentedEntries( LOST );

		expect(
			entries.filter( ( e ) => e.isPlaceholder ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'rules the visible rows of a folded record with no placeholder run', () => {
		const { entries } = computeIndentedEntries( FOLDED );
		const visible = computeVisibleEntries( entries, new Set() );

		expect( visible.filter( ( e ) => e.isPlaceholder ) ).toHaveLength( 0 );
		const merged = visible.find( ( e ) => e.isMerged );
		expect( merged.k ).toBe( 'shortcode' );
		expect( merged.duration_ms ).toBe( 83 );
		expect( visible[ visible.length - 1 ].displayTime ).toMatch(
			/^\d{2}:\d{2}:\d{2}\.88$/
		);
	} );
} );
