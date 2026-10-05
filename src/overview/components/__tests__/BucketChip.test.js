/**
 * Tests for BucketChip — the removable token naming the five-minute bucket a
 * chart click narrowed a list to.
 */

import * as React from 'react';
import BucketChip from '../BucketChip';
import { renderComponent, act } from '../../../test-helpers/renderHook';

const mountChip = ( props ) =>
	renderComponent(
		React.createElement( BucketChip, { onClear: jest.fn(), ...props } )
	);

describe( 'BucketChip', () => {
	it( 'renders nothing without a bucket', () => {
		const { container, unmount } = mountChip( { bucket: '' } );
		expect( container.innerHTML ).toBe( '' );
		unmount();
	} );

	it( 'reads the bucket as a UTC span on the removable token', () => {
		const { container, unmount } = mountChip( {
			bucket: '2026-10-04-13-35',
		} );
		const token = container.querySelector( '.event-logger-tag-token' );
		expect( token.textContent ).toBe( '13:35–13:40 UTC' );
		unmount();
	} );

	it( 'spans midnight into the next day', () => {
		const { container, unmount } = mountChip( {
			bucket: '2026-10-04-23-55',
		} );
		expect( container.textContent ).toContain( '23:55–00:00 UTC' );
		unmount();
	} );

	it( 'clears through the token button, labelled with the span', () => {
		const onClear = jest.fn();
		const { container, unmount } = mountChip( {
			bucket: '2026-10-04-13-35',
			onClear,
		} );
		const clear = container.querySelector( 'button' );
		expect( clear.getAttribute( 'aria-label' ) ).toBe(
			'Clear the 13:35–13:40 UTC filter'
		);
		act( () => clear.click() );
		expect( onClear ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );

	it( 'shows a malformed key as it arrived, and still clears it', () => {
		const onClear = jest.fn();
		const { container, unmount } = mountChip( {
			bucket: 'kea-junk',
			onClear,
		} );
		expect( container.textContent ).toContain( 'kea-junk' );
		act( () => container.querySelector( 'button' ).click() );
		expect( onClear ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );
} );
