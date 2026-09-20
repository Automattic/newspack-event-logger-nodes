/**
 * Tests for DashboardShell — the fixed full-viewport chrome the Gyroscope,
 * Request Log and Error Log dashboards share.
 *
 * The debug overlay is mocked so the storage key each page hands the shell is
 * observable in the rendered text; a shared key would silently merge two
 * dashboards' persisted panel layouts.
 */

jest.mock( '@newspack-nodes/debug-overlay', () => ( {
	__esModule: true,
	default: ( { storageKey } ) => `OVERLAY[${ storageKey }]`,
} ) );

import * as React from 'react';
import DashboardShell from '../DashboardShell';
import { renderComponent } from '../../test-helpers/renderHook';

describe( 'DashboardShell', () => {
	it( 'wraps its children in one skinned provider over a fixed viewport box', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				children: () => 'CHILD_MARKER',
			} )
		);

		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( provider.style.display ).toBe( 'contents' );

		const box = provider.firstElementChild;
		expect( box.style.position ).toBe( 'fixed' );
		expect( box.style.top ).toBe( '32px' );
		expect( box.style.overflowX ).toBe( 'hidden' );
		expect( container.textContent ).toContain( 'CHILD_MARKER' );
		unmount();
	} );

	it( 'paints an opaque backdrop, so nothing behind the box shows through', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				children: () => null,
			} )
		);

		// The box is positioned, not flowed, so an admin notice WordPress
		// relocates above it keeps its own place in the document and shows
		// through a transparent backdrop. The substrate's shared page-surface
		// class is what paints it; appearance stays out of the inline style.
		expect( container.firstElementChild.firstElementChild.className ).toBe(
			'newspack-nodes-page-surface'
		);
		unmount();
	} );

	it( 'heads every dashboard with one wordmark naming its own surface', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				subtitle: 'Request Log',
				children: () => null,
			} )
		);

		// One header, the substrate wordmark, and a subtitle naming THIS
		// dashboard rather than the station the shared component came from.
		expect( container.querySelectorAll( '.topology-header' ) ).toHaveLength(
			1
		);
		expect(
			container.querySelector( '.topology-brand' ).textContent
		).toContain( 'NEWSPACK' );
		const subtitle = container.querySelector( '.topology-subtitle' );
		expect( subtitle.textContent ).toContain( 'Request Log' );
		unmount();
	} );

	it( 'lays the box out as a column, so the header takes no page height', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				subtitle: 'Request Log',
				children: () => null,
			} )
		);

		// A block box would leave the dashboard root's `height: 100%` resolving
		// against the WHOLE box while the header pushed it down 64px — clipped
		// under overflow hidden, a permanent scrollbar under auto. The station
		// box this copies is a flex column for the same reason.
		const box = container.firstElementChild.firstElementChild;
		expect( box.style.display ).toBe( 'flex' );
		expect( box.style.flexDirection ).toBe( 'column' );
		unmount();
	} );

	it( 'scrolls on the axis the page asks for', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				children: () => null,
			} )
		);

		expect(
			container.firstElementChild.firstElementChild.style.overflowY
		).toBe( 'scroll' );
		unmount();
	} );

	it( "hands the debug overlay the page's own storage key", () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'auto',
				children: () => null,
			} )
		);

		expect( container.textContent ).toContain(
			'OVERLAY[newspack-nodes:debug:probe-shell]'
		);
		unmount();
	} );
} );
