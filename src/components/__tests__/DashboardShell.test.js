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
			React.createElement(
				DashboardShell,
				{
					storageKey: 'newspack-nodes:debug:probe-shell',
					overflowY: 'scroll',
				},
				'CHILD_MARKER'
			)
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

	it( 'scrolls on the axis the page asks for', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
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
			} )
		);

		expect( container.textContent ).toContain(
			'OVERLAY[newspack-nodes:debug:probe-shell]'
		);
		unmount();
	} );
} );
