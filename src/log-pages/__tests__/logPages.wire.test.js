/**
 * The log pages on the real graph against the fake wire: the Gyroscope's
 * cadence picker and its 0–9 keys drive how often its view takes the
 * snapshot it serves, and the browser keeps the choice; each page's column
 * picker keeps a reader's columns under the key it always kept them; each
 * page says so when it holds nothing; and a `tune` reader is offered the
 * layout editor.
 */

import { Core } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { steppedClock } from '@newspack-nodes/shared/test-utils/steppedClock';
import { act, renderComponent, waitFor } from '../../test-helpers/renderHook';
import { replyFor } from '../../test-helpers/logPagesWire';
import { localizeComposedPage } from '../../test-helpers/localizeComposedPage';
import { GyroscopeViewNode } from '../nodes/gyroscope-view-node';
import LogPage from '../LogPage';

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
 * Mount a page on its default layout.
 *
 * @param {string} page     The page.
 * @param {Object} [reader] Who reads it.
 * @return {Promise<Object>} The mount.
 */
async function mount( page, reader ) {
	localizeComposedPage( page, reader );
	const mounted = renderComponent( <LogPage page={ page } /> );
	await clock.tick( 1 );
	return mounted;
}

/**
 * The snapshots the Gyroscope's view took over `seconds` one-second ticks.
 *
 * @param {Object} serve   The spy on the view's snapshot.
 * @param {number} seconds How long.
 * @return {Promise<number>} How many.
 */
async function snapshotsOver( serve, seconds ) {
	const before = serve.mock.calls.length;
	for ( let at = 0; at < seconds; at++ ) {
		await clock.tick( 1 );
	}
	return serve.mock.calls.length - before;
}

/**
 * The Gyroscope's cadence picker.
 *
 * @param {Element} container The page.
 * @return {HTMLSelectElement} It.
 */
const picker = ( container ) =>
	container.querySelector( 'select[title^="Refresh interval"]' );

describe( 'the Gyroscope’s cadence', () => {
	it( 'takes a snapshot each second the picker asks for, and keeps the choice', async () => {
		const serve = jest.spyOn( GyroscopeViewNode.prototype, '_serve' );
		const { container, unmount } = await mount( 'gyroscope' );
		expect( picker( container ).value ).toBe( '2' );

		await act( async () => {
			const select = picker( container );
			select.value = '1';
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await clock.tick( 1 );

		expect( await snapshotsOver( serve, 5 ) ).toBe( 5 );
		expect(
			window.localStorage.getItem( 'event-logger-inflight-refresh' )
		).toBe( '1' );
		unmount();
	} );

	it( 'takes one every ten seconds on the 8 key', async () => {
		const serve = jest.spyOn( GyroscopeViewNode.prototype, '_serve' );
		const { container, unmount } = await mount( 'gyroscope' );
		await act( async () => {
			window.dispatchEvent(
				new window.KeyboardEvent( 'keydown', { key: '8' } )
			);
		} );
		await clock.tick( 1 );

		expect( picker( container ).value ).toBe( '10' );
		expect( await snapshotsOver( serve, 30 ) ).toBe( 3 );
		expect(
			window.localStorage.getItem( 'event-logger-inflight-refresh' )
		).toBe( '10' );
		unmount();
	} );

	it( 'leaves a key pressed in the cadence select to the select', async () => {
		const { container, unmount } = await mount( 'gyroscope' );
		await act( async () => {
			picker( container ).dispatchEvent(
				new window.KeyboardEvent( 'keydown', {
					key: '5',
					bubbles: true,
				} )
			);
		} );
		expect( picker( container ).value ).toBe( '2' );
		unmount();
	} );

	it( 'offers no filter', async () => {
		const { container, unmount } = await mount( 'gyroscope' );
		expect( container.querySelector( 'input[type="search"]' ) ).toBeNull();
		unmount();
	} );

	it( 'opens at the cadence the browser kept', () => {
		window.localStorage.setItem( 'event-logger-inflight-refresh', '5' );
		// The cadence's default is read as the page's modules load.
		jest.isolateModules( () => {
			const { createElement } = require( 'react' );
			const render = require( '../../test-helpers/renderHook' );
			const page = require( '../LogPage' ).default;
			require( '../../test-helpers/localizeComposedPage' ).localizeComposedPage(
				'gyroscope'
			);
			const { container, unmount } = render.renderComponent(
				createElement( page, { page: 'gyroscope' } )
			);
			expect( picker( container ).value ).toBe( '5' );
			unmount();
		} );
	} );
} );

describe( 'the column picker', () => {
	it.each( [
		[ 'request-log', 'UA', 'event-logger-stream-columns', 'user_agent' ],
		[ 'gyroscope', 'Lag', 'event-logger-columns', 'lag' ],
	] )(
		'keeps the %s page’s columns under the key they were always kept',
		async ( page, label, key, column ) => {
			const { container, unmount } = await mount( page );
			const cols = [ ...container.querySelectorAll( 'button' ) ].find(
				( button ) => 'Cols' === button.textContent
			);
			await act( async () => cols.click() );
			const box = [ ...container.querySelectorAll( 'label' ) ]
				.find( ( one ) => label === one.textContent.trim() )
				.querySelector( 'input' );
			await act( async () => box.click() );
			expect(
				JSON.parse( window.localStorage.getItem( key ) )
			).toContain( column );
			unmount();
		}
	);

	it( 'restores the columns a reader kept', async () => {
		window.localStorage.setItem(
			'event-logger-columns',
			JSON.stringify( [ 'rid', 'age', 'lag' ] )
		);
		const { container, unmount } = await mount( 'gyroscope' );
		const heads = [
			...container.querySelectorAll( '[role="columnheader"]' ),
		].map( ( one ) => one.textContent );
		expect( heads ).toEqual( [ 'Request ID', 'Age', 'Lag' ] );
		unmount();
	} );

	it( 'is not offered on the Error Log, whose layout names every column', async () => {
		const { container, unmount } = await mount( 'error-log' );
		expect(
			[ ...container.querySelectorAll( 'button' ) ].some(
				( button ) => 'Cols' === button.textContent
			)
		).toBe( false );
		unmount();
	} );
} );

describe( 'an empty page', () => {
	it.each( [
		[ 'gyroscope', 'No active requests.' ],
		[ 'request-log', 'Waiting for log lines…' ],
		[ 'error-log', 'Waiting for log lines…' ],
	] )( 'the %s page says it holds nothing', async ( page, words ) => {
		const { container, unmount } = await mount( page );
		await waitFor( () =>
			expect( container.textContent ).toContain( words )
		);
		unmount();
	} );
} );

describe( 'a tune reader', () => {
	it( 'is offered the layout’s actions; a reader is not', async () => {
		const tuned = await mount( 'error-log', { canTune: true } );
		expect(
			tuned.container.querySelector( '[aria-label="Layout actions"]' )
		).toBeTruthy();
		tuned.unmount();

		const read = await mount( 'error-log' );
		expect(
			read.container.querySelector( '[aria-label="Layout actions"]' )
		).toBeNull();
		read.unmount();
	} );
} );
