/**
 * SegmentBrowseSidebar tests — the shared browse rail both the Error Log and
 * Request Log dashboards render from their `useGlobBrowse` model. It owns the
 * partition <select>, the LogBrowser segment list, and byte formatting so the
 * two dashboards don't copy-paste ~70 lines of presentation.
 */

import * as React from 'react';
import SegmentBrowseSidebar from '../SegmentBrowseSidebar';
import { renderComponent, act } from '../../test-helpers/renderHook';

// A browse model shaped like useGlobBrowse's return.
function browseMock( overrides = {} ) {
	return {
		partitions: [],
		selectedPartition: '',
		segments: [],
		mode: 'live',
		segmentId: null,
		follow: jest.fn(),
		replay: jest.fn(),
		browseSegment: jest.fn(),
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
	expect(
		container.querySelector( '.newspack-nodes-log-browser' )
	).toBeNull();
} );

test( 'renders nothing when there are no partitions', () => {
	const { container } = mount( {
		browse: browseMock(),
		onSelectPartition: jest.fn(),
	} );
	expect( container.querySelector( '.newspack-nodes-select' ) ).toBeNull();
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
	// No partition selected → no segment sidebar yet.
	expect(
		container.querySelector( '.newspack-nodes-log-browser' )
	).toBeNull();
} );

test( 'a select change calls onSelectPartition with the chosen key', () => {
	const onSelectPartition = jest.fn();
	const { container } = mount( {
		browse: browseMock( {
			partitions: [ { key: 'errors.p6', label: 'errors.p6' } ],
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

test( 'renders the segment sidebar with formatted sizes + Live/Replay when a partition is selected', () => {
	const { container } = mount( {
		browse: browseMock( {
			partitions: [ { key: 'errors.p6', label: 'errors.p6' } ],
			selectedPartition: 'errors.p6',
			segments: [
				{ id: 9, size: 2048 },
				{ id: 8, size: 512 },
			],
			mode: 'browse',
			segmentId: 9,
		} ),
		onSelectPartition: jest.fn(),
	} );
	const sidebar = container.querySelector( '.newspack-nodes-log-browser' );
	expect( sidebar ).toBeTruthy();
	expect( sidebar.textContent ).toContain( 'Segment 9' );
	expect( sidebar.textContent ).toContain( 'Segment 8' );
	// formatBytes: 2048 → 2.0 KB, 512 → 512 B.
	expect( sidebar.textContent ).toContain( '2.0 KB' );
	expect( sidebar.textContent ).toContain( '512 B' );
	expect( sidebar.textContent ).toMatch( /Live/ );
	expect( sidebar.textContent ).toMatch( /Replay/ );
} );

test( 'clicking a segment calls browse.browseSegment; Live/Replay call follow/replay', () => {
	const browse = browseMock( {
		partitions: [ { key: 'errors.p6', label: 'errors.p6' } ],
		selectedPartition: 'errors.p6',
		segments: [ { id: 9, size: 2048 } ],
	} );
	const { container } = mount( {
		browse,
		onSelectPartition: jest.fn(),
	} );
	const sidebar = container.querySelector( '.newspack-nodes-log-browser' );
	act( () =>
		sidebar.querySelector( '.newspack-nodes-log-browser__item' ).click()
	);
	expect( browse.browseSegment ).toHaveBeenCalledWith( {
		id: 9,
		size: 2048,
	} );
	const buttons = Array.from( sidebar.querySelectorAll( 'button' ) );
	act( () => buttons.find( ( b ) => /Live/.test( b.textContent ) ).click() );
	expect( browse.follow ).toHaveBeenCalled();
	act( () =>
		buttons.find( ( b ) => /Replay/.test( b.textContent ) ).click()
	);
	expect( browse.replay ).toHaveBeenCalled();
} );

test( 'formats a zero-byte segment as "0 B"', () => {
	const { container } = mount( {
		browse: browseMock( {
			partitions: [ { key: 'errors.p6', label: 'errors.p6' } ],
			selectedPartition: 'errors.p6',
			segments: [ { id: 0, size: 0 } ],
		} ),
		onSelectPartition: jest.fn(),
	} );
	expect(
		container.querySelector( '.newspack-nodes-log-browser' ).textContent
	).toContain( '0 B' );
} );

test( 'formats a megabyte-scale segment size', () => {
	const { container } = mount( {
		browse: browseMock( {
			partitions: [ { key: 'errors.p6', label: 'errors.p6' } ],
			selectedPartition: 'errors.p6',
			segments: [ { id: 1, size: 5 * 1024 * 1024 } ],
		} ),
		onSelectPartition: jest.fn(),
	} );
	expect(
		container.querySelector( '.newspack-nodes-log-browser' ).textContent
	).toContain( '5.0 MB' );
} );
