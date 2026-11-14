/**
 * The Request Log, Error Log and Gyroscope against the pages they replace,
 * each mounted whole in its dashboard shell, so the header's controls slot is
 * real: on the seeded server (`test-helpers/logPagesWire`), with the stream
 * dropped, each page's header slot and its body read to a reader of their
 * role trees as they read before, by default and with the column picker open
 * and a column turned on.
 */

import { Core } from '@newspack-nodes/runtime';
import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';
import { PAGE_CONTENT_CLASS } from '@newspack-nodes/shared/components/DashboardShell';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { steppedClock } from '@newspack-nodes/shared/test-utils/steppedClock';
import { roleTree } from '@newspack-nodes/shared/test-utils/roleTree';
import { act, renderComponent, waitFor } from '../test-helpers/renderHook';
import {
	ERRORS,
	FLIGHTS,
	REQUESTS,
	feed,
	replyFor,
} from '../test-helpers/logPagesWire';
import {
	GyroscopeViewNode,
	LOG_PAGES,
	PerfErrorsViewNode,
	RequestLogViewNode,
} from '../test-helpers/logPagesUnderTest';

jest.setTimeout( 20000 );

class FakeEventSource {
	addEventListener() {}
	close() {}
}

let clock;

beforeEach( () => {
	Core.reset();
	clock = steppedClock();
	window.localStorage.clear();
	window.history.replaceState( null, '', '/wp-admin/admin.php?page=x' );
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	global.EventSource = FakeEventSource;
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => 'visible',
	} );
	installFakeCommandWire( replyFor );
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * One region's tree with each wall-clock time masked, since a cell writes its
 * time in the host's own zone and locale, and each stream's rate, which
 * decays with the wall clock.
 *
 * @param {Element} region The region.
 * @return {string} The tree.
 */
const tree = ( region ) =>
	roleTree( region )
		.replace( /\d{1,2}:\d{2}:\d{2}(\s?[AP]M)?/g, '<time>' )
		.replace( /"\d+\.\d (req|entries)\/s"/g, '"<rate>"' );

/**
 * A page's header slot and body, each as its own tree.
 *
 * @param {Element} container The page.
 * @return {{header: string, body: string}} The two trees.
 */
const regions = ( container ) => ( {
	header: tree( container.querySelector( '.topology-header__controls' ) ),
	body: tree( container.querySelector( `.${ PAGE_CONTENT_CLASS }` ) ),
} );

/**
 * Mount a page in its shell, let its catalog answer, fill its view, and drop
 * its stream so the reconnect banner shows.
 *
 * @param {string}   page      The page.
 * @param {Function} ViewClass Its view's class.
 * @param {Array[]}  records   What its stream delivers.
 * @return {Promise<Object>} The mount.
 */
async function mount( page, ViewClass, records ) {
	let mounted;
	await act( async () => {
		mounted = renderComponent( LOG_PAGES[ page ]() );
	} );
	for ( let at = 0; at < 3; at++ ) {
		await clock.tick( 1 );
	}
	await act( async () => {
		feed( ViewClass, records );
		const view = [ ...Core.nodes.values() ].find(
			( node ) => node instanceof ViewClass
		);
		view.fill(
			controlMsg( view, { action: 'connection', connectionError: true } )
		);
	} );
	await clock.tick( 2 );
	await clock.tick( 2 );
	return mounted;
}

/**
 * Open the column picker and turn on the column labelled `label`.
 *
 * @param {Element} container The page.
 * @param {string}  label     The column.
 */
async function pickColumn( container, label ) {
	const cols = [ ...container.querySelectorAll( 'button' ) ].find(
		( button ) => 'Cols' === button.textContent
	);
	await act( async () => cols.click() );
	const box = [ ...container.querySelectorAll( 'label' ) ]
		.find( ( one ) => label === one.textContent.trim() )
		.querySelector( 'input' );
	await act( async () => box.click() );
	await clock.tick( 2 );
}

it( 'reads the Request Log as it read', async () => {
	const { container, unmount } = await mount(
		'request-log',
		RequestLogViewNode,
		REQUESTS
	);
	await waitFor( () =>
		expect( container.textContent ).toContain( '3 requests' )
	);
	expect( regions( container ) ).toMatchSnapshot( 'default' );
	await pickColumn( container, 'UA' );
	await waitFor( () =>
		expect( container.textContent ).toContain( 'Pipipi/7903' )
	);
	expect( regions( container ) ).toMatchSnapshot( 'a column picked' );
	unmount();
} );

it( 'reads the Error Log as it read', async () => {
	const { container, unmount } = await mount(
		'error-log',
		PerfErrorsViewNode,
		ERRORS
	);
	await waitFor( () =>
		expect( container.textContent ).toContain( '3 entries' )
	);
	expect( regions( container ) ).toMatchSnapshot( 'default' );
	unmount();
} );

it( 'reads the Gyroscope as it read', async () => {
	const { container, unmount } = await mount(
		'gyroscope',
		GyroscopeViewNode,
		FLIGHTS
	);
	await waitFor( () =>
		expect( container.textContent ).toContain( '2 requests' )
	);
	expect( regions( container ) ).toMatchSnapshot( 'default' );
	await pickColumn( container, 'IP' );
	await waitFor( () =>
		expect( container.textContent ).toContain( '10.79.0.21' )
	);
	await waitFor( () =>
		expect( container.textContent ).toContain( '2 requests' )
	);
	expect( regions( container ) ).toMatchSnapshot( 'a column picked' );
	unmount();
} );
