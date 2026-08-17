/**
 * SegmentBrowseSidebar tests — the partition PICKER both the Error Log and
 * Request Log dashboards render above their segment rail. The rail itself is
 * the substrate's, handed over ready-made on `browse.sidebar`; what is asserted
 * here is the picker's two shapes and where the rail is placed.
 */

import * as React from 'react';
import SegmentBrowseSidebar from '../SegmentBrowseSidebar';
import { renderComponent, act } from '../../test-helpers/renderHook';

// A browse model shaped like useGlobBrowse's return; the rail stands in as a
// marker element, because the substrate builds and tests the real one.
function browseMock( overrides = {} ) {
	return {
		partitions: [],
		selectedPartition: '',
		sidebar: React.createElement( 'div', { className: 'the-rail' } ),
		...overrides,
	};
}

const mounted = [];
afterEach( () => {
	while ( mounted.length ) {
		mounted.pop().unmount();
	}
} );

function mount( props ) {
	const r = renderComponent(
		React.createElement( SegmentBrowseSidebar, props )
	);
	mounted.push( r );
	return r;
}

test( 'renders nothing when browse is undefined', () => {
	const { container } = mount( { onSelectPartition: jest.fn() } );
	expect( container.querySelector( '.newspack-nodes-select' ) ).toBeNull();
	expect( container.querySelector( '.the-rail' ) ).toBeNull();
} );

test( 'renders nothing when there are no partitions', () => {
	const { container } = mount( {
		browse: browseMock(),
		onSelectPartition: jest.fn(),
	} );
	expect( container.querySelector( '.newspack-nodes-select' ) ).toBeNull();
} );

test( 'a single partition renders a static label, not a dropdown', () => {
	// One dir makes "All partitions (live)" meaningless — the rail shows the
	// dir name and goes straight to its segments (useGlobBrowse auto-selects).
	const { container } = mount( {
		browse: browseMock( {
			partitions: [ { key: 'errors.p0', label: 'errors.p0' } ],
			selectedPartition: 'errors.p0',
		} ),
		onSelectPartition: jest.fn(),
	} );
	expect(
		container.querySelector( 'select.newspack-nodes-select' )
	).toBeNull();
	expect( container.textContent ).toContain( 'errors.p0' );
} );

test( 'renders the partition select (All + each dir) when partitions exist', () => {
	const { container } = mount( {
		browse: browseMock( {
			partitions: [
				{ key: 'errors.p1', label: 'errors.p1' },
				{ key: 'errors.p6', label: 'errors.p6' },
			],
		} ),
		onSelectPartition: jest.fn(),
	} );
	const select = container.querySelector( 'select.newspack-nodes-select' );
	expect( select ).toBeTruthy();
	expect( Array.from( select.options ).map( ( o ) => o.value ) ).toEqual( [
		'',
		'errors.p1',
		'errors.p6',
	] );
	// No partition selected → no rail yet.
	expect( container.querySelector( '.the-rail' ) ).toBeNull();
} );

test( 'a select change calls onSelectPartition with the chosen key', () => {
	const onSelectPartition = jest.fn();
	const { container } = mount( {
		browse: browseMock( {
			partitions: [
				{ key: 'errors.p1', label: 'errors.p1' },
				{ key: 'errors.p6', label: 'errors.p6' },
			],
		} ),
		onSelectPartition,
	} );
	const select = container.querySelector( 'select.newspack-nodes-select' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLSelectElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( select, 'errors.p6' );
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	expect( onSelectPartition ).toHaveBeenCalledWith( 'errors.p6' );
} );

test( 'a selected partition puts the rail under the picker', () => {
	const { container } = mount( {
		browse: browseMock( {
			partitions: [ { key: 'errors.p6', label: 'errors.p6' } ],
			selectedPartition: 'errors.p6',
		} ),
		onSelectPartition: jest.fn(),
	} );
	expect( container.querySelector( '.the-rail' ) ).toBeTruthy();
} );
