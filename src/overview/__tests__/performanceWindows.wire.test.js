/**
 * The URL and request windows as composed layouts, on the real graph against
 * the fake command wire: each window's body is its context's stored layout,
 * a curator finds Edit layout in the window's header, and on an edit load
 * the window holds its context's editor, which a leave asks about first.
 */

import { useEffect } from '@wordpress/element';
import { Core, VALUE } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';
import { PAGE_CONTENT_CLASS } from '@newspack-nodes/shared/components/DashboardShell';
import { registerCompositionEditor } from '@newspack-nodes/shared/composition/editorRegistry';
import { editSearch } from '@newspack-nodes/shared/composition/page/editMode';
import {
	NOW_MS,
	RID,
	sentOn,
	windowReplyFor,
} from '../../test-helpers/performanceWire';
import { act, renderComponent } from '../../test-helpers/renderHook';
import {
	contextOf,
	localizeComposedPage,
} from '../../test-helpers/localizeComposedPage';
import { PERFORMANCE_PAGES } from '../performancePageData';
import Page from '../PerformancePage';

/** Every page a test mounted, so none outlives its test. */
const mounted = [];

/**
 * One block of a layout, with no attributes.
 *
 * @param {string} name The block, under the plugin's namespace.
 * @return {Object} The node.
 */
const block = ( name ) => ( {
	name: `newspack-event-logger-nodes/${ name }`,
	attributes: {},
	inner: [],
} );

/**
 * Mount the page on a link and answer its asks.
 *
 * @param {string} search The link's query.
 * @return {Promise<Element>} The window.
 */
async function windowOn( search ) {
	window.history.replaceState(
		null,
		'',
		`/wp-admin/admin.php?page=x&${ search }`
	);
	const mount = renderComponent(
		<div className={ PAGE_CONTENT_CLASS }>
			<Page onError={ () => {} } />
		</div>
	);
	mounted.push( mount );
	await ticks( 6 );
	return document.body.querySelector( '.event-logger-performance-modal' );
}

/**
 * Run the Router's tick `count` times.
 *
 * @param {number} count How many.
 */
async function ticks( count ) {
	for ( let tick = 0; tick < count; tick++ ) {
		await act( async () => {
			Core.node( '_router' )?.fireCb();
		} );
	}
}

/**
 * The text of every section heading in a window.
 *
 * @param {Element} modal The window.
 * @return {string[]} The headings.
 */
const headings = ( modal ) =>
	[ ...modal.querySelectorAll( '.components-modal__content h3' ) ].map(
		( one ) => one.textContent
	);

/**
 * Pick an option of a select the way React hears it.
 *
 * @param {HTMLSelectElement} select The select.
 * @param {string}            value  The option's value.
 */
function selectOption( select, value ) {
	const { set } = Object.getOwnPropertyDescriptor(
		window.HTMLSelectElement.prototype,
		'value'
	);
	act( () => {
		set.call( select, value );
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

/**
 * One param the bar holds now.
 *
 * @param {string} name The param.
 * @return {?string} Its value; null when absent.
 */
const barParam = ( name ) =>
	new URLSearchParams( window.location.search ).get( name );

/**
 * The URL window chart's latest `query`, from its source token on.
 *
 * @return {string[]} Its arguments.
 */
const urlChartAsked = () =>
	sentOn( wire, 'query' )
		.filter(
			( args ) =>
				'requests' === args[ 0 ] && args.includes( '--filter=url=a17e' )
		)
		.at( -1 );

/**
 * The rids the Recent Requests list shows, top to bottom.
 *
 * @param {Element} modal The window.
 * @return {string[]} The rids.
 */
const listed = ( modal ) =>
	[ ...modal.querySelectorAll( '.event-logger-table__row code' ) ].map(
		( code ) => code.textContent
	);

/**
 * Type a query into the page's search box and press Enter.
 *
 * @param {string} query The query.
 */
function searchFor( query ) {
	const input = document.body.querySelector(
		'input[placeholder="Request ID or /url pattern…"]'
	);
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, query );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	act( () => {
		input.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'Enter',
				bubbles: true,
			} )
		);
	} );
}

/**
 * Every URL window chart `query`, request and category alike.
 *
 * @return {Array<string[]>} Their arguments.
 */
const urlQueries = () =>
	sentOn( wire, 'query' ).filter( ( args ) =>
		args.includes( '--filter=url=a17e' )
	);

/** The fake wire, which records every POST. */
let wire;

beforeEach( () => {
	Core.reset();
	holdRouterCadence();
	window.localStorage.clear();
	jest.spyOn( Date, 'now' ).mockReturnValue( NOW_MS );
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = installFakeCommandWire( windowReplyFor );
} );

afterEach( () => {
	mounted.splice( 0 ).forEach( ( mount ) => mount.unmount() );
	document.body.innerHTML = '';
	jest.restoreAllMocks();
} );

it( 'lays the URL window out as its stored layout reads', async () => {
	localizeComposedPage( 'performance', {
		layouts: {
			'url-detail': [ block( 'url-requests' ), block( 'url-profile' ) ],
		},
	} );
	const modal = await windowOn( 'url=a17e' );
	expect( headings( modal ) ).toEqual( [
		'Recent Requests (2)',
		'Time Breakdown',
		'Time By Owner',
	] );
} );

it( 'reads the URL window’s subject in a shared stats block laid out on it', async () => {
	localizeComposedPage( 'performance', {
		layouts: {
			'url-detail': [
				{
					name: 'newspack-nodes/stats',
					attributes: {
						source: 'eln/url-detail',
						fields: [ 'p95_ms', 'errors' ],
						columns: 0,
					},
					inner: [],
				},
			],
		},
	} );
	const modal = await windowOn( 'url=a17e' );
	const tiles = [
		...modal.querySelectorAll(
			'.components-modal__content .newspack-nodes-stat-tile'
		),
	].map( ( one ) => one.textContent );
	expect( tiles ).toEqual( [ '700msp95', '1errors' ] );
} );

it( 'lays the request window out as its stored layout reads', async () => {
	localizeComposedPage( 'performance', {
		layouts: {
			'request-detail': [
				block( 'request-entries' ),
				block( 'request-findings' ),
			],
		},
	} );
	const modal = await windowOn( `url=a17e&request=${ RID }` );
	expect( headings( modal ) ).toEqual( [ 'Log Entries (4)', 'Findings' ] );
	expect( modal.textContent ).not.toContain( 'URL:' );
} );

it( 'names each window to the ask picker, every request row nested inside the URL', async () => {
	localizeComposedPage( 'performance' );
	const url = await windowOn( 'url=a17e' );
	const root = url.querySelector( '[data-ask="url:a17e"]' );
	expect( root ).not.toBeNull();
	expect(
		root.querySelectorAll( `[data-ask="request:${ RID }"]` )
	).toHaveLength( 1 );
	mounted.splice( 0 ).forEach( ( mount ) => mount.unmount() );
	document.body.innerHTML = '';

	const request = await windowOn( `url=a17e&request=${ RID }` );
	expect(
		request.querySelector(
			`.event-logger-request-detail[data-ask="request:${ RID }"]`
		)
	).not.toBeNull();
} );

it( 'asks the URL window’s chart no Then by that repeats its Breakdown', async () => {
	localizeComposedPage( 'performance' );
	await windowOn( 'url=a17e&url_breakdown=method&url_then=method' );
	const asked = sentOn( wire, 'query' ).filter(
		( args ) =>
			'requests' === args[ 0 ] && args.includes( '--filter=url=a17e' )
	);
	expect( asked.length ).toBeGreaterThan( 0 );
	expect( asked.at( -1 ).slice( 0, 3 ) ).toEqual( [
		'requests',
		'bucket',
		'method',
	] );
} );

it( 'reads a linked Then by repeating its Breakdown as none, and a new Breakdown does not bring it back', async () => {
	localizeComposedPage( 'performance' );
	const modal = await windowOn(
		'url=a17e&url_breakdown=method&url_then=method'
	);
	const [ , breakdown, thenBy ] = modal.querySelectorAll(
		'.components-modal__content select'
	);
	expect( thenBy.value ).toBe( '' );
	expect( barParam( 'url_then' ) ).toBeNull();

	selectOption( breakdown, 'ja4' );
	await ticks( 2 );
	// No ask ever carried the repeated dimension.
	expect(
		urlQueries().filter(
			( args ) => 'method' === args[ 2 ] && 'method' === args[ 3 ]
		)
	).toEqual( [] );
	expect( thenBy.value ).toBe( '' );
	expect( barParam( 'url_then' ) ).toBeNull();
	expect( urlChartAsked().slice( 0, 4 ) ).toEqual( [
		'requests',
		'bucket',
		'ja4',
		'--top=20',
	] );
} );

it( 'keeps the list’s order off the bar while the window is shut, and back on it when one opens', async () => {
	localizeComposedPage( 'performance' );
	const modal = await windowOn( 'url=a17e' );
	expect( listed( modal ) ).toEqual( [ 'rid-hoiho-7922', RID ] );
	act( () =>
		[ ...modal.querySelectorAll( 'button' ) ]
			.find( ( one ) => one.textContent.includes( 'Duration' ) )
			.click()
	);
	expect( barParam( 'url_sort' ) ).toBe( 'duration_ms' );

	await act( async () => {
		modal.querySelector( 'button[aria-label="Close"]' ).click();
		await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );
	} );
	expect(
		document.body.querySelector( '.event-logger-performance-modal' )
	).toBeNull();
	expect( barParam( 'url_sort' ) ).toBeNull();
	expect( barParam( 'url_order' ) ).toBeNull();

	await act( async () =>
		document.body
			.querySelector(
				'.event-logger-performance-urls [data-ask="url:a17e"]'
			)
			.click()
	);
	await ticks( 3 );
	const reopened = document.body.querySelector(
		'.event-logger-performance-modal'
	);
	expect( barParam( 'url_sort' ) ).toBe( 'duration_ms' );
	expect( listed( reopened ) ).toEqual( [ RID, 'rid-hoiho-7922' ] );
} );

it( 'shows the loading line, not the last request’s record, when the request changes', async () => {
	localizeComposedPage( 'performance' );
	installFakeCommandWire( ( m ) => {
		const { name, arguments: args = [] } = m[ VALUE ] ?? {};
		// The second request's record never answers, so it stays loading.
		return 'dump_request' === name && RID !== args[ 0 ]
			? undefined
			: windowReplyFor( m );
	} );
	const modal = await windowOn( `url=a17e&request=${ RID }` );
	expect( modal.textContent ).toContain( 'kea 7925' );

	searchFor( 'rid-hoiho-7922' );
	await ticks( 3 );
	const moved = document.body.querySelector(
		'.event-logger-performance-modal'
	);
	expect( barParam( 'request' ) ).toBe( 'rid-hoiho-7922' );
	expect( moved.textContent ).toContain( 'Loading request…' );
	expect( moved.textContent ).not.toContain( 'kea 7925' );
	expect( moved.textContent ).not.toContain( '/piwakawaka-7915/?kea=7924' );
} );

it( 'asks no URL chart while a link’s request is still being found', async () => {
	localizeComposedPage( 'performance' );
	wire = installFakeCommandWire( ( m ) =>
		'search_requests' === m[ VALUE ]?.name ? undefined : windowReplyFor( m )
	);
	const modal = await windowOn( `url=a17e&request=${ RID }` );
	expect( headings( modal ) ).toContain( 'Recent Requests (2)' );
	expect( urlQueries() ).toEqual( [] );
} );

it( 'asks the URL charts beside its detail, not behind it', async () => {
	localizeComposedPage( 'performance' );
	wire = installFakeCommandWire( ( m ) =>
		'dump_url' === m[ VALUE ]?.name ? undefined : windowReplyFor( m )
	);
	await windowOn( 'url=a17e' );
	expect( sentOn( wire, 'dump_url' ).length ).toBeGreaterThan( 0 );
	expect(
		urlQueries()
			.map( ( args ) => args[ 0 ] )
			.sort()
	).toEqual( expect.arrayContaining( [ 'requests', 'timings' ] ) );
} );

it( 'offers a curator Edit layout in each window’s header, and a reader none', async () => {
	localizeComposedPage( 'performance', { canTune: true } );
	const url = await windowOn( 'url=a17e' );
	const menu = url.querySelector(
		'.components-modal__header [aria-label="Layout actions"]'
	);
	expect( menu ).not.toBeNull();
	// Blocks portal block-level controls into the box the menu closes.
	expect( menu.closest( '[style*="display: contents"]' ).tagName ).toBe(
		'DIV'
	);
	mounted.splice( 0 ).forEach( ( mount ) => mount.unmount() );
	document.body.innerHTML = '';

	localizeComposedPage( 'performance' );
	const read = await windowOn( `url=a17e&request=${ RID }` );
	expect( read.querySelector( '[aria-label="Layout actions"]' ) ).toBeNull();
} );

it( 'edits a window’s layout on its subject, every other link kept', () => {
	expect(
		editSearch(
			'?page=x&url=a17e&url_bucket=2026-09-29-14-30',
			{ page: 'url-detail' },
			PERFORMANCE_PAGES
		)
	).toBe( '?page=x&url=a17e&url_bucket=2026-09-29-14-30&edit=url-detail' );
	expect(
		editSearch(
			`?page=x&page_hash=a17e0&request=${ RID }`,
			{ page: 'request-detail' },
			PERFORMANCE_PAGES
		)
	).toBe( `?page=x&page_hash=a17e0&request=${ RID }&edit=request-detail` );
} );

describe( 'on an edit load', () => {
	beforeEach( () => {
		registerCompositionEditor(
			contextOf( 'url-detail' ),
			( { onDirty } ) => {
				useEffect( () => onDirty( true ), [ onDirty ] );
				return <p>url window editor 7926</p>;
			}
		);
		registerCompositionEditor(
			contextOf( 'request-detail' ),
			( { onDirty } ) => {
				useEffect( () => onDirty( true ), [ onDirty ] );
				return <p>request window editor 7927</p>;
			}
		);
		localizeComposedPage( 'performance', { canTune: true } );
	} );

	it( 'puts the URL window’s editor in its body, its header and the page standing', async () => {
		const modal = await windowOn( 'url=a17e&edit=url-detail' );
		expect( modal.textContent ).toContain( 'url window editor 7926' );
		expect( headings( modal ) ).toEqual( [] );
		expect( modal.textContent ).toContain( 'Log this URL' );
		expect(
			document.body.querySelector( '.event-logger-performance-dashboard' )
				.textContent
		).toContain( 'Pages by Request Count' );
	} );

	it( 'opens the URL window’s editor while its detail is refused', async () => {
		installFakeCommandWire( ( m ) =>
			'dump_url' === m[ VALUE ]?.name
				? new Error( 'kea 7933 refused' )
				: windowReplyFor( m )
		);
		const modal = await windowOn( 'url=a17e&edit=url-detail' );
		expect( modal.textContent ).toContain( 'kea 7933 refused' );
		expect( modal.textContent ).toContain( 'url window editor 7926' );
	} );

	it( 'asks before the browser’s Back leaves a window whose editor holds a change', async () => {
		window.history.replaceState( null, '', '/wp-admin/admin.php?page=x' );
		window.history.pushState(
			null,
			'',
			'/wp-admin/admin.php?page=x&url=a17e&edit=url-detail'
		);
		const mount = renderComponent(
			<div className={ PAGE_CONTENT_CLASS }>
				<Page onError={ () => {} } />
			</div>
		);
		mounted.push( mount );
		await ticks( 6 );
		await act( async () => {
			window.history.back();
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		} );
		expect( document.body.textContent ).toContain(
			'Discard your changes to this layout?'
		);
		expect( document.body.textContent ).toContain(
			'url window editor 7926'
		);
	} );

	it( 'asks before Back to the URL leaves a request window whose editor holds a change', async () => {
		const modal = await windowOn(
			`url=a17e&request=${ RID }&edit=request-detail`
		);
		expect( modal.textContent ).toContain( 'request window editor 7927' );
		act( () =>
			modal
				.querySelector( 'button[aria-label="Back to URL details"]' )
				.click()
		);
		expect( document.body.textContent ).toContain(
			'Discard your changes to this layout?'
		);
		expect( document.body.textContent ).toContain(
			'request window editor 7927'
		);
	} );

	it( 'asks before a search leaves a window whose editor holds a change', async () => {
		const modal = await windowOn( 'url=a17e&edit=url-detail' );
		searchFor( RID );
		await ticks( 2 );
		expect( document.body.textContent ).toContain(
			'Discard your changes to this layout?'
		);
		expect( modal.textContent ).toContain( 'url window editor 7926' );
		expect( barParam( 'request' ) ).toBeNull();
	} );

	it( 'opens the URL window’s editor on a link naming a request, resolving no request', async () => {
		window.history.replaceState( null, '', '/wp-admin/admin.php?page=x' );
		const before = window.history.length;
		const modal = await windowOn(
			`url=a17e&request=rid-kaka-7938&edit=url-detail`
		);
		expect( modal.textContent ).toContain( 'url window editor 7926' );
		expect( sentOn( wire, 'search_requests' ) ).toEqual( [] );
		expect( sentOn( wire, 'dump_request' ) ).toEqual( [] );
		expect( barParam( 'request' ) ).toBeNull();
		expect( barParam( 'edit' ) ).toBe( 'url-detail' );
		expect( window.history.length ).toBe( before );
	} );

	it( 'asks before closing a window whose editor holds a change', async () => {
		const modal = await windowOn( 'url=a17e&edit=url-detail' );
		await act( async () => {
			modal.querySelector( 'button[aria-label="Close"]' ).click();
			await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );
		} );
		expect( document.body.textContent ).toContain(
			'Discard your changes to this layout?'
		);
		expect(
			document.body.querySelector( '.event-logger-performance-modal' )
		).not.toBeNull();
	} );
} );
