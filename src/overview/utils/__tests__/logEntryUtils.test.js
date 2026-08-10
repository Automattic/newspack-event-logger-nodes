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
