/**
 * The aggregator-admin entry mounts on the operator-facing SETTINGS page
 * (the Configured Servers section), not a technical dashboard — so it must NOT
 * render the debug overlay, even when the sticky `?nodes-debug=1` dev flag is
 * on. Every dashboard bundle keeps its overlay; this one was the lone leak.
 */

jest.mock( '../nodes/register', () => ( {} ) );
jest.mock( '../hooks/useAggregatorAdminGraph', () => ( {
	__esModule: true,
	useAggregatorAdminGraph: () => ( {
		addServer: jest.fn(),
		updateServer: jest.fn(),
		removeServer: jest.fn(),
		testServer: jest.fn(),
	} ),
} ) );

import { act } from '../../test-helpers/renderHook';

describe( 'aggregator-admin settings entry', () => {
	beforeEach( () => {
		document.body.innerHTML = '<div id="event-aggregator-servers"></div>';
		// Turn the sticky dev flag on so the overlay WOULD render if present.
		window.localStorage.setItem( 'newspack-nodes:debug', '1' );
	} );

	afterEach( () => {
		window.localStorage.removeItem( 'newspack-nodes:debug' );
		document.body.innerHTML = '';
	} );

	it( 'mounts ServersAdmin but never the debug overlay', async () => {
		await act( async () => {
			require( '../index' );
		} );
		expect(
			document.querySelector( '.event-aggregator-servers-admin' )
		).toBeTruthy();
		expect( document.querySelector( '.nodes-debug__fab' ) ).toBeNull();
	} );
} );
