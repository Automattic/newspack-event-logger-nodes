/**
 * MemoryTrack — the peak-memory staircase under the flame graph.
 *
 * `log_memory` appends `peak_mb` to every complete() entry, and the flame's X
 * axis is already time, so the two line up over one request.
 */

import React from 'react';
import { renderComponent } from '../../../test-helpers/renderHook';
import MemoryTrack from '../MemoryTrack';

const ENTRIES = [
	{ ts: 1000, k: 'request (start)' },
	{ ts: 1000.5, k: 'init hook (complete)', peak_mb: 12 },
	{ ts: 1002, k: 'the_content hook (complete)', peak_mb: 61.5 },
	{ ts: 1004, k: 'request (complete)', peak_mb: 94.25 },
];

describe( 'MemoryTrack', () => {
	it( 'draws a point per complete entry that recorded memory', () => {
		const { container, unmount } = renderComponent(
			React.createElement( MemoryTrack, {
				entries: ENTRIES,
				totalMs: 4000,
			} )
		);

		const line = container.querySelector( 'polyline' );
		expect( line ).not.toBeNull();
		// Step-after: each reading holds until the next one, so three readings
		// draw five vertices.
		expect(
			line.getAttribute( 'points' ).trim().split( /\s+/ )
		).toHaveLength( 5 );
		// The peak is the high-water mark, not the last reading.
		expect( container.textContent ).toContain( '94.25 MB' );
		unmount();
	} );

	it( 'reads as a chart: filled area, gridlines, and a labelled axis', () => {
		// A bare stroke under the flame is not a graph. The area is what makes
		// the shape legible, and the gridlines are what give the height a scale.
		const { container, unmount } = renderComponent(
			React.createElement( MemoryTrack, {
				entries: ENTRIES,
				totalMs: 4000,
			} )
		);

		expect(
			container.querySelector( 'path.event-logger-memory-track__area' )
		).not.toBeNull();
		expect(
			container.querySelectorAll(
				'line.event-logger-memory-track__gridline'
			).length
		).toBeGreaterThanOrEqual( 2 );
		expect( container.textContent ).toContain( 'Peak memory' );
		// The floor is labelled, or the height means nothing.
		expect( container.textContent ).toContain( '0' );
		unmount();
	} );

	it( 'plots memory against the same milliseconds the flame does', () => {
		const { container, unmount } = renderComponent(
			React.createElement( MemoryTrack, {
				entries: ENTRIES,
				totalMs: 4000,
			} )
		);

		const xs = container
			.querySelector( 'polyline' )
			.getAttribute( 'points' )
			.trim()
			.split( /\s+/ )
			.map( ( p ) => Number( p.split( ',' )[ 0 ] ) );

		// 0.5s, 2s and 4s into a 4s request, over a 0..1000 viewBox.
		expect( xs[ 0 ] ).toBeCloseTo( 125, 3 );
		expect( xs[ xs.length - 1 ] ).toBeCloseTo( 1000, 3 );
		unmount();
	} );

	it( 'renders nothing when the rule logged no memory', () => {
		const { container, unmount } = renderComponent(
			React.createElement( MemoryTrack, {
				entries: [ { ts: 1000, k: 'init hook (complete)' } ],
				totalMs: 4000,
			} )
		);

		expect( container.querySelector( 'polyline' ) ).toBeNull();
		unmount();
	} );
} );
