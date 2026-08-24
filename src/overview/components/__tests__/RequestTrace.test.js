/**
 * Tests for RequestTrace — the "Request Trace" section wrapping the lazily
 * imported flame graph. The performance dashboard's detail modal and the
 * current-request overlay both render it; only the modal wires a reveal
 * handler back to its log-entries table.
 */

jest.mock( '../../FlameGraph', () => ( {
	__esModule: true,
	default: ( props ) => {
		global.__requestTraceFlameProps = props;
		return 'FLAME_GRAPH';
	},
} ) );

import * as React from 'react';
import RequestTrace from '../RequestTrace';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const flameData = {
	name: 'trace-root',
	value: 4242,
	children: [ { name: 'shutdown' } ],
};

async function renderAsync( element ) {
	const result = renderComponent( element );
	// The flame graph is lazy; its chunk resolves a microtask later.
	await act( async () => {} );
	return result;
}

describe( 'RequestTrace', () => {
	it( 'draws the shared loading fallback while the chunk resolves', () => {
		// Read the fallback off the Suspense element rather than mounting:
		// the lazy chunk resolves before a mounted fallback can be observed.
		const tree = RequestTrace( { flameData } );
		const suspense = tree.props.children.find(
			( child ) => child?.props?.fallback
		);
		const { container, unmount } = renderComponent(
			suspense.props.fallback
		);
		const fallback = container.querySelector(
			'.newspack-nodes-performance-loading'
		);

		expect( fallback.textContent ).toContain( 'Loading chart…' );
		expect(
			fallback.querySelector( '.components-spinner' )
		).not.toBeNull();
		expect( fallback.className ).not.toContain(
			'event-logger-detail-loading'
		);
		unmount();
	} );

	it( 'renders the heading and the lazily imported flame graph', async () => {
		const { container, unmount } = await renderAsync(
			React.createElement( RequestTrace, { flameData } )
		);
		expect( container.textContent ).toContain( 'Request Trace' );
		expect( container.textContent ).toContain( 'FLAME_GRAPH' );
		expect( global.__requestTraceFlameProps.data ).toBe( flameData );
		expect(
			container.querySelector( '.event-logger-flame-container' )
		).not.toBeNull();
		unmount();
	} );

	it( 'takes the heading and the redraw stamp from the caller', async () => {
		const { container, unmount } = await renderAsync(
			React.createElement( RequestTrace, {
				flameData,
				title: 'Aggregate Flame Graph',
				lastModified: 1771234567,
			} )
		);
		expect( container.textContent ).toContain( 'Aggregate Flame Graph' );
		expect( container.textContent ).not.toContain( 'Request Trace' );
		expect( global.__requestTraceFlameProps.lastModified ).toBe(
			1771234567
		);
		unmount();
	} );

	it( 'hands the reveal callback through to the flame graph', async () => {
		const onRevealEntry = jest.fn();
		const { unmount } = await renderAsync(
			React.createElement( RequestTrace, { flameData, onRevealEntry } )
		);
		act( () => {
			global.__requestTraceFlameProps.onRevealEntry( [
				'process',
				'db',
			] );
		} );
		expect( onRevealEntry ).toHaveBeenCalledWith( [ 'process', 'db' ] );
		unmount();
	} );
} );
