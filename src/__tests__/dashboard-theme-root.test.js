/**
 * Tests that the overview's page root, AdminApp, sits below one exact skinned
 * non-graph provider. Child roots must not repeat token-provider classes; the
 * log pages' shell holds the same in `page-wrappers.test.js`.
 *
 * The root renders a lazy-loaded child; the assertion is on the
 * synchronously-rendered wrapper, then we flush the pending lazy resolution
 * inside act() (before unmount) so its Suspense settle doesn't trip the
 * "not wrapped in act" warning the shared jest.setup escalates to a failure.
 */

jest.mock( '../overview/PerformancePage', () => ( {
	__esModule: true,
	default: () => 'PERFORMANCE_DASHBOARD',
} ) );

import * as React from 'react';
import { AdminApp } from '../overview';
import { renderComponent, act } from '../test-helpers/renderHook';

const flushLazy = async () =>
	act( async () => {
		await Promise.resolve();
	} );

const TOKEN_ROOT_CLASSES = [
	'topology-app',
	'newspack-nodes-skin-root',
	'newspack-nodes-theme',
	'newspack-nodes-ui',
];

function expectNoTokenRoot( element ) {
	for ( const className of TOKEN_ROOT_CLASSES ) {
		expect( element.classList.contains( className ) ).toBe( false );
	}
}

describe( 'overview theme root', () => {
	it( 'AdminApp has one exact skinned provider and no repeated child provider', async () => {
		const { container, unmount } = renderComponent(
			React.createElement( AdminApp )
		);
		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expectNoTokenRoot( provider.firstElementChild );
		await flushLazy();
		unmount();
	} );
} );
