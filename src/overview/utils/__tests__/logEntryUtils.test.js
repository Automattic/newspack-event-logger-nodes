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
} from '../logEntryUtils';

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

describe( 'computeIndentedEntries', () => {
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
