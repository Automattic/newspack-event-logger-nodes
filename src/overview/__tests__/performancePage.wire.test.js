/* global globalThis */
/**
 * The composed Performance page on the real graph, against the fake command
 * wire: what its blocks ask, through page state, and what they show.
 *
 * The time charts record the props the frame hands them, so a test drags a
 * slot as the frame would; everything else, the sources, the page state and
 * the blocks, runs as the page does.
 */

jest.mock( '@newspack-nodes/shared/components/AreaTimeChart', () => ( {
	__esModule: true,
	default: ( props ) => {
		( globalThis.__areaCharts ??= {} )[ props.storageKey ] = props;
		return null;
	},
} ) );

import { Core, VALUE } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';
import { PAGE_CONTENT_CLASS } from '@newspack-nodes/shared/components/DashboardShell';
import {
	NOW_MS,
	SERVERS,
	URL_ROWS,
	URLS,
	filtersIn,
	option,
	replyFor,
	sentOn,
} from '../../test-helpers/performanceWire';
import {
	PERFORMANCE_BLOCKS,
	localizeComposedPage,
} from '../../test-helpers/localizeComposedPage';
import { act, renderComponent } from '../../test-helpers/renderHook';
import Page from '../PerformancePage';

/** The URL table's answer under the chip `status_class=4`. */
const CHIPPED = {
	...URLS,
	data: [ URLS.data[ 0 ] ],
	rows: 1,
	totals: { ...URLS.totals, urls: 1, requests: 17, errors: 17 },
};

let wire;

/** Every page a test mounted, so none outlives its test. */
const mounted = [];

/**
 * Render the page on its layout, and answer its first asks.
 *
 * @return {Promise<Element>} Its container.
 */
async function rendered() {
	const mount = renderComponent(
		<div className={ PAGE_CONTENT_CLASS }>
			<Page onError={ () => {} } />
		</div>
	);
	mounted.push( mount );
	await ticks( 4 );
	const { container } = mount;
	return container;
}

/**
 * Run the Router's tick `count` times, each a second on.
 *
 * @param {number} count How many.
 */
async function ticks( count ) {
	for ( let tick = 0; tick < count; tick++ ) {
		at += 1;
		await act( async () => {
			Core.node( '_router' )?.fireCb();
		} );
	}
}

/** The page's clock, in seconds. */
let at;

/**
 * Every `urls` ask for the table, not an expanded page's shapes.
 *
 * @return {Array<string[]>} Their arguments.
 */
const tableAsks = () =>
	sentOn( wire, 'urls' ).filter(
		( args ) => null === option( args, 'page' )
	);

/**
 * Every `query` ask for one source, `requests` or `timings`.
 *
 * @param {string} source The source.
 * @return {Array<string[]>} Their arguments.
 */
const queryAsks = ( source ) =>
	sentOn( wire, 'query' ).filter( ( args ) => source === args[ 0 ] );

/**
 * A headline tile's value, by its label.
 *
 * @param {Element} container The page.
 * @param {string}  label     The tile's label.
 * @return {?string} Its value.
 */
const tile = ( container, label ) =>
	[ ...container.querySelectorAll( '.newspack-nodes-stat-tile' ) ]
		.find(
			( one ) =>
				label ===
				one.querySelector( '.newspack-nodes-stat-label' ).textContent
		)
		?.querySelector( '.newspack-nodes-stat-value' ).textContent ?? null;

/**
 * Type a chip into the bar's filter box and commit it.
 *
 * @param {Element} container The page.
 * @param {string}  chip      The chip.
 */
function addChip( container, chip ) {
	const input = container.querySelector( 'input[aria-label="Filter"]' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, chip );
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

beforeEach( () => {
	Core.reset();
	holdRouterCadence();
	globalThis.__areaCharts = {};
	at = NOW_MS / 1000;
	jest.spyOn( Core, 'now' ).mockImplementation( () => at );
	window.localStorage.clear();
	window.history.replaceState( null, '', '/wp-admin/admin.php?page=x' );
	jest.spyOn( Date, 'now' ).mockReturnValue( NOW_MS );
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	localizeComposedPage( 'performance' );
	wire = installFakeCommandWire( ( m ) => {
		const args = m[ VALUE ]?.arguments ?? [];
		if (
			'urls' === m[ VALUE ]?.name &&
			filtersIn( args ).includes( 'status_class=4' )
		) {
			return CHIPPED;
		}
		return replyFor( m );
	} );
} );

afterEach( () => {
	mounted.splice( 0 ).forEach( ( mount ) => mount.unmount() );
	document.body.innerHTML = '';
	jest.restoreAllMocks();
} );

it( 'asks urls once for the URL table and the headline totals', async () => {
	const container = await rendered();
	expect( tableAsks() ).toHaveLength( 1 );
	expect(
		[ ...Core.nodes.keys() ].filter( ( name ) =>
			/^eln:urls:\d+:fetch$/.test( name )
		)
	).toHaveLength( 1 );
	expect( tile( container, 'Total Requests' ) ).toBe( '138' );
	expect( container.textContent ).toContain(
		'https://kea-7911.example/piwakawaka-7915/'
	);
} );

it( 'narrows the chart, the URLs and the totals by a chip the bar writes', async () => {
	const container = await rendered();
	addChip( container, 'status_class=4xx' );
	await ticks( 3 );

	// The editor spells a status class as the answer names it.
	expect( filtersIn( queryAsks( 'requests' ).at( -1 ) ) ).toContain(
		'status_class=4'
	);
	expect( filtersIn( tableAsks().at( -1 ) ) ).toContain( 'status_class=4' );
	expect( filtersIn( queryAsks( 'timings' ).at( -1 ) ) ).not.toContain(
		'status_class=4'
	);
	expect( tile( container, 'Total Requests' ) ).toBe( '17' );
	expect( container.textContent ).not.toContain(
		'https://tui-7912.example/ruru-7916/'
	);
	expect(
		new URLSearchParams( window.location.search ).getAll( 'chip' )
	).toEqual( [ 'status_class=4' ] );
} );

it( 'filters the URL table by the slots a drag on the chart spans', async () => {
	await rendered();
	const chart =
		globalThis.__areaCharts[ 'event-logger-overview-chart-aggregate' ];
	const last = chart.series[ 0 ].values.length - 1;
	act( () => chart.onSlotRange( last - 1, last, { additive: false } ) );
	await ticks( 3 );

	const spelled = option( tableAsks().at( -1 ), 'bucket' );
	expect( spelled ).toBe( '2026-09-29-14-30..2026-09-29-14-40' );
	expect(
		new URLSearchParams( window.location.search ).get( 'bucket' )
	).toBe( spelled );
	expect( option( queryAsks( 'requests' ).at( -1 ), 'bucket' ) ).toBeNull();
} );

it( 'opens a ?url= link on a layout that holds no URL table', async () => {
	localizeComposedPage( 'performance', {
		blocks: PERFORMANCE_BLOCKS.filter(
			( block ) => 'newspack-event-logger-nodes/url-table' !== block.name
		),
	} );
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=x&url=c0ffee7917'
	);
	wire = installFakeCommandWire( ( m ) =>
		'dump_url' === m[ VALUE ]?.name
			? {
					stats: {
						hash: 'c0ffee7917',
						url: 'https://kea-7911.example/kokako-7917/',
						count: 3,
						requests_per_second: 0.02,
					},
					requests: [],
			  }
			: replyFor( m )
	);
	const container = await rendered();

	expect( container.textContent ).not.toContain( 'Pages by Request Count' );
	expect( sentOn( wire, 'dump_url' ).at( -1 ) ).toContain(
		'--url=c0ffee7917'
	);
	const modal = document.body.querySelector(
		'.event-logger-performance-modal'
	);
	expect( modal ).not.toBeNull();
	expect( modal.textContent ).toContain(
		'https://kea-7911.example/kokako-7917/'
	);
} );

it( 'shows each block its empty state when every answer is null', async () => {
	wire = installFakeCommandWire( () => null );
	const container = await rendered();

	expect( tile( container, 'Total Requests' ) ).toBe( '—' );
	expect( tile( container, 'Avg Peak Memory' ) ).toBeNull();
	expect( container.textContent ).toContain(
		'No Server data in this window.'
	);
	expect( container.textContent ).toContain(
		'No category data in this window.'
	);
	expect( container.textContent ).toContain(
		'No request was profiled in this window.'
	);
	expect( container.textContent ).toContain( 'No URLs to display' );
	expect( container.querySelector( '.newspack-nodes-skeleton' ) ).toBeNull();
} );

it( 'asks the chart by the dimension the page can draw, the Breakdown showing it', async () => {
	wire = installFakeCommandWire( ( m ) =>
		'overview' === m[ VALUE ]?.name
			? { ...replyFor( m ), servers: [ 'kea-7911.example' ] }
			: replyFor( m )
	);
	const container = await rendered();

	expect( queryAsks( 'requests' ).at( -1 ).slice( 0, 3 ) ).toEqual( [
		'requests',
		'bucket',
		'status_class',
	] );
	const breakdown = [ ...container.querySelectorAll( 'select' ) ].find(
		( select ) =>
			[ ...select.options ].some(
				( one ) => 'User Agent' === one.text
			) && ! [ ...select.options ].some( ( one ) => 'None' === one.text )
	);
	expect( breakdown.value ).toBe( 'status_class' );
	expect(
		[ ...breakdown.options ].map( ( one ) => one.value )
	).not.toContain( 'server' );
} );

it( 'opens on the chart a link names, dropping what the controls do not offer', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=x&metric=avg&breakdown=ja4&then=method&chip=Status_Class=4xx&chip=nope=1&chip=status_class=4'
	);
	const container = await rendered();

	expect( queryAsks( 'requests' ).at( -1 ).slice( 0, 4 ) ).toEqual( [
		'requests',
		'bucket',
		'ja4',
		'method',
	] );
	expect( filtersIn( tableAsks().at( -1 ) ) ).toEqual( [ 'status_class=4' ] );
	const metric = [ ...container.querySelectorAll( 'select' ) ].find(
		( select ) =>
			[ ...select.options ].some(
				( one ) => 'Avg Peak Memory' === one.text
			)
	);
	expect( metric.value ).toBe( 'avg' );
	expect(
		new URLSearchParams( window.location.search ).getAll( 'chip' )
	).toEqual( [ 'status_class=4' ] );
} );

it( 'keeps the cadence the picker chose, and polls at it', async () => {
	const container = await rendered();
	const picker = container.querySelector(
		'select[aria-label="Refresh interval"]'
	);
	act( () => {
		picker.value = '60000';
		picker.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	expect(
		window.localStorage.getItem( 'event-logger-refresh-interval' )
	).toBe( '60000' );
	const before = sentOn( wire, 'overview' ).length;
	await ticks( 30 );
	expect( sentOn( wire, 'overview' ) ).toHaveLength( before );
	await ticks( 31 );
	expect( sentOn( wire, 'overview' ).length ).toBeGreaterThan( before );
} );

/**
 * Install a wire whose `overview` names `servers`, and whose `urls` under a
 * chip answers as `chipped` says.
 *
 * @param {() => ?string[]} servers   The servers it names now; null leaves
 *                                    `overview` unanswered.
 * @param {() => *}         [chipped] The chipped `urls` answer; undefined
 *                                    leaves it unanswered.
 */
function wireNaming( servers, chipped = () => CHIPPED ) {
	wire = installFakeCommandWire( ( m ) => {
		const { name, arguments: args = [] } = m[ VALUE ] ?? {};
		if ( 'overview' === name ) {
			const named = servers();
			return null === named
				? undefined
				: { ...replyFor( m ), servers: named };
		}
		if ( 'urls' === name && filtersIn( args ).length ) {
			return chipped();
		}
		return replyFor( m );
	} );
}

/**
 * The dimensions the aggregate chart asked last.
 *
 * @return {string[]} Its group tokens past `bucket`.
 */
const chartDimsAsked = () => {
	const args = queryAsks( 'requests' ).at( -1 );
	return args.slice(
		2,
		args.findIndex( ( one ) => one.startsWith( '--' ) )
	);
};

/**
 * Pick a value on the select whose options include `label`.
 *
 * @param {Element} container The page.
 * @param {string}  label     An option only that select offers.
 * @param {string}  value     The value to pick.
 */
function pick( container, label, value ) {
	const select = [ ...container.querySelectorAll( 'select' ) ].find(
		( one ) => [ ...one.options ].some( ( entry ) => label === entry.text )
	);
	act( () => {
		select.value = value;
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

it( 'titles the headline with the window the server names', async () => {
	localizeComposedPage( 'performance', { window: 18 } );
	const container = await rendered();
	expect(
		container.querySelector( '.newspack-nodes-stats-grid' ).title
	).toBe(
		'The last 90 minutes in five-minute buckets, the current one so far.'
	);
} );

it( 'titles the headline with the window refusal the server sends', async () => {
	localizeComposedPage( 'performance', {
		window: { error: 'min_lifetime 120 is below the floor 7964' },
	} );
	const container = await rendered();
	expect( tile( container, 'Total Requests' ) ).toBe( '138' );
	expect(
		container.querySelector( '.newspack-nodes-stats-grid' ).title
	).toBe( 'min_lifetime 120 is below the floor 7964' );
} );

it( 'shows the headline totals when the category query fails', async () => {
	localizeComposedPage( 'performance', { window: 18 } );
	wire = installFakeCommandWire( ( m ) => {
		const args = m[ VALUE ]?.arguments ?? [];
		if ( 'query' === m[ VALUE ]?.name && 'timings' === args[ 0 ] ) {
			return new Error( 'timings refused 7963' );
		}
		return replyFor( m );
	} );
	const container = await rendered();
	const grid = container.querySelector( '.newspack-nodes-stats-grid' );
	expect( tile( container, 'Total Requests' ) ).toBe( '138' );
	expect( grid.title ).toBe(
		'The last 90 minutes in five-minute buckets, the current one so far.'
	);
	expect( container.textContent ).toContain( 'timings refused 7963' );
	expect( grid.textContent ).not.toContain( 'timings refused 7963' );
} );

it( 'keeps the headline numbers on screen while a chip’s answer loads', async () => {
	wireNaming(
		() => SERVERS,
		() => undefined
	);
	const container = await rendered();
	addChip( container, 'status_class=4xx' );
	await ticks( 3 );
	expect( filtersIn( tableAsks().at( -1 ) ) ).toContain( 'status_class=4' );
	expect( tile( container, 'Total Requests' ) ).toBe( '138' );
} );

describe( 'the URL window over the page', () => {
	/** Open the window on a URL by its link, its `dump_url` answered. */
	const openOnLink = () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=x&url=a17e'
		);
		wire = installFakeCommandWire( ( m ) =>
			'dump_url' === m[ VALUE ]?.name
				? {
						stats: {
							hash: 'a17e',
							url: URL_ROWS[ 0 ].url,
							count: 137,
						},
						requests: [],
				  }
				: replyFor( m )
		);
	};

	it( 'asks the page nothing while it stands, and keeps asking for itself', async () => {
		openOnLink();
		await rendered();
		const page = {
			overview: sentOn( wire, 'overview' ).length,
			urls: tableAsks().length,
			requests: queryAsks( 'requests' ).filter(
				( args ) => ! filtersIn( args ).includes( 'url=a17e' )
			).length,
		};
		const window = sentOn( wire, 'dump_url' ).length;
		await ticks( 30 );
		expect( sentOn( wire, 'overview' ) ).toHaveLength( page.overview );
		expect( tableAsks() ).toHaveLength( page.urls );
		expect(
			queryAsks( 'requests' ).filter(
				( args ) => ! filtersIn( args ).includes( 'url=a17e' )
			)
		).toHaveLength( page.requests );
		expect( sentOn( wire, 'dump_url' ).length ).toBeGreaterThan( window );
	} );

	it( 'asks the page again on the next tick once it closes', async () => {
		openOnLink();
		await rendered();
		await ticks( 3 );
		const page = {
			overview: sentOn( wire, 'overview' ).length,
			urls: tableAsks().length,
		};
		const close = document.body.querySelector(
			'.event-logger-performance-modal button[aria-label="Close"]'
		);
		// The modal closes once its exit animation's timeout runs out.
		await act( async () => {
			close.click();
			await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );
		} );
		await ticks( 1 );
		expect(
			document.body.querySelector( '.event-logger-performance-modal' )
		).toBeNull();
		expect( sentOn( wire, 'overview' ) ).toHaveLength( page.overview + 1 );
		expect( tableAsks() ).toHaveLength( page.urls + 1 );
	} );

	it( 'asks its URL and both its charts on the same ticks', async () => {
		openOnLink();
		await rendered();
		/**
		 * What the window has asked so far.
		 *
		 * @return {number[]} Its `dump_url`, request-chart and category-chart asks.
		 */
		const asked = () => [
			sentOn( wire, 'dump_url' ).length,
			...[ 'requests', 'timings' ].map(
				( source ) =>
					queryAsks( source ).filter( ( args ) =>
						filtersIn( args ).includes( 'url=a17e' )
					).length
			),
		];
		const before = asked();
		for ( let tick = 0; tick < 30; tick++ ) {
			await ticks( 1 );
			const moved = asked().map(
				( count, place ) => count - before[ place ]
			);
			expect( new Set( moved ).size ).toBe( 1 );
		}
		expect( asked()[ 0 ] ).toBeGreaterThan( before[ 0 ] );
	} );

	it( 'closes on Back to the entry before a row opened it', async () => {
		const container = await rendered();
		const row = container.querySelector(
			'.event-logger-table__row[role="button"]'
		);
		act( () => row.click() );
		await ticks( 2 );
		expect(
			new URLSearchParams( window.location.search ).get( 'url' )
		).toBe( 'a17e' );
		expect(
			document.body.querySelector( '.event-logger-performance-modal' )
		).not.toBeNull();
		await act( async () => {
			window.history.back();
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		} );
		await ticks( 1 );
		expect(
			new URLSearchParams( window.location.search ).get( 'url' )
		).toBeNull();
		expect(
			document.body.querySelector( '.event-logger-performance-modal' )
		).toBeNull();
	} );
} );

describe( 'the aggregate chart’s dimensions', () => {
	it( 'drops a linked Then by that repeats the Breakdown', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=x&breakdown=ja4&then=ja4'
		);
		const container = await rendered();
		expect( chartDimsAsked() ).toEqual( [ 'ja4' ] );
		const thenBy = [ ...container.querySelectorAll( 'select' ) ].find(
			( one ) =>
				[ ...one.options ].some( ( entry ) => 'None' === entry.text )
		);
		expect( thenBy.value ).toBe( '' );
	} );

	it( 'clears the Then by when the Breakdown takes its value', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=x&then=method'
		);
		const container = await rendered();
		pick( container, 'Avg Response Time', 'volume' );
		const breakdown = [ ...container.querySelectorAll( 'select' ) ].find(
			( one ) =>
				[ ...one.options ].some(
					( entry ) => 'User Agent' === entry.text
				) &&
				! [ ...one.options ].some( ( entry ) => 'None' === entry.text )
		);
		act( () => {
			breakdown.value = 'method';
			breakdown.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await ticks( 2 );
		expect(
			new URLSearchParams( window.location.search ).get( 'then' )
		).toBeNull();
		expect( chartDimsAsked() ).toEqual( [ 'method' ] );
	} );

	it( 'stays on Server while the first overview is in flight', async () => {
		wireNaming( () => null );
		await rendered();
		expect( chartDimsAsked() ).toEqual( [ 'server' ] );
	} );

	it( 'returns to Server when a second server starts reporting', async () => {
		let servers = [ 'kea-7911.example' ];
		wireNaming( () => servers );
		await rendered();
		expect( chartDimsAsked() ).toEqual( [ 'status_class' ] );
		servers = SERVERS;
		await ticks( 32 );
		expect( chartDimsAsked() ).toEqual( [ 'server' ] );
	} );

	it( 'falls back under a server filter and restores when it clears', async () => {
		const container = await rendered();
		expect( chartDimsAsked() ).toEqual( [ 'server' ] );
		pick( container, 'All Servers', SERVERS[ 1 ] );
		await ticks( 2 );
		expect( chartDimsAsked() ).toEqual( [ 'status_class' ] );
		expect( filtersIn( queryAsks( 'requests' ).at( -1 ) ) ).toContain(
			`server=${ SERVERS[ 1 ] }`
		);
		pick( container, 'All Servers', '' );
		await ticks( 2 );
		expect( chartDimsAsked() ).toEqual( [ 'server' ] );
	} );

	it( 'keeps a stored Then by through a poll that answers one server', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=x&then=method'
		);
		let servers = [ 'kea-7911.example' ];
		wireNaming( () => servers );
		await rendered();
		expect( chartDimsAsked() ).toEqual( [ 'status_class', 'method' ] );
		servers = SERVERS;
		await ticks( 32 );
		expect( chartDimsAsked() ).toEqual( [ 'server', 'method' ] );
		expect(
			new URLSearchParams( window.location.search ).get( 'then' )
		).toBe( 'method' );
	} );
} );
