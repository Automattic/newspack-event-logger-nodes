/**
 * logTable — the vocabulary the In-Flight, Request Log and Error Log tables
 * share. The cell helpers take explicit values, never a row, because the three
 * dashboards spell the same field differently (`est_ms`/`time_ms` vs
 * `duration_ms`, `ts` vs `timestamp`).
 */

import {
	cellRenderer,
	countLabel,
	durationCell,
	ipCell,
	logColumns,
	logListHeader,
	rateLabel,
	ridCell,
	statusCell,
	timeCell,
	uaCell,
	urlCell,
} from '../logTable';
import { renderComponent } from '../../test-helpers/renderHook';

// Render one cell element and hand back its root <span>.
const draw = ( element ) => {
	const { container } = renderComponent( element );
	return container.firstElementChild;
};

describe( 'cellRenderer', () => {
	// A dashboard's own column set, one key of which has no case below.
	const COLUMNS = logColumns( {
		rid: {},
		sentinel_col: {
			label: 'Sentinel',
			tooltip: 'A column no case renders',
			width: '128px',
		},
		remote_addr: {},
	} );

	const renderCell = cellRenderer( {
		rid: ( row, col ) => <span key={ col }>{ row.rid }</span>,
		remote_addr: ( row, col ) => (
			<span key={ col }>{ row.remote_addr }</span>
		),
	} );

	it( 'draws a placeholder for a declared column no case renders', () => {
		const cell = draw(
			renderCell( 'sentinel_col', { rid: 'rid-8471-zed' } )
		);
		expect( cell.getAttribute( 'role' ) ).toBe( 'cell' );
		expect( cell.className ).toBe( 'newspack-nodes-table__cell' );
		expect( cell.textContent ).toBe( '-' );
	} );

	it( 'keeps every later column in its own slot', () => {
		const row = { rid: 'rid-8471-zed', remote_addr: '203.0.113.77' };
		const cells = Object.keys( COLUMNS ).map( ( col ) =>
			renderCell( col, row )
		);
		expect( cells ).toHaveLength( 3 );
		expect( cells.map( ( cell ) => cell.key ) ).toEqual( [
			'rid',
			'sentinel_col',
			'remote_addr',
		] );
	} );
} );

describe( 'log table cells', () => {
	it( 'links the request id to its trace', () => {
		const cell = draw( ridCell( 'rid-8471-zed' ) );
		expect( cell.getAttribute( 'role' ) ).toBe( 'cell' );
		expect( cell.className ).toBe( 'newspack-nodes-table__cell' );
		const link = cell.querySelector( 'a.entry-rid' );
		expect( link.textContent ).toBe( 'rid-8471-zed' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'admin.php?page=event-logger-overview&request=rid-8471-zed'
		);
		expect( link.getAttribute( 'title' ) ).toBe( 'View request trace' );
	} );

	it( 'percent-encodes a request id carrying URL syntax', () => {
		const cell = draw( ridCell( 'rid/8471?zed' ) );
		expect(
			cell.querySelector( 'a.entry-rid' ).getAttribute( 'href' )
		).toBe(
			'admin.php?page=event-logger-overview&request=rid%2F8471%3Fzed'
		);
	} );

	it( 'draws the method beside a url deep-linked by the hash it is given', () => {
		const cell = draw( urlCell( 'PATCH', '/quixotic/route', 'hash-8842' ) );
		expect( cell.className ).toBe( 'newspack-nodes-table__cell entry-url' );
		expect( cell.getAttribute( 'title' ) ).toBe( '/quixotic/route' );
		expect( cell.querySelector( '.entry-method' ).textContent ).toBe(
			'PATCH'
		);
		const link = cell.querySelector( 'a.entry-url-link' );
		expect( link.textContent ).toBe( '/quixotic/route' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'admin.php?page=event-logger-overview&url=hash-8842'
		);
		expect( link.getAttribute( 'title' ) ).toBe( 'View URL stats' );
	} );

	it( 'leaves the url cell empty when the entry carried no url', () => {
		const cell = draw( urlCell( 'PATCH', undefined, 'hash-8842' ) );
		expect( cell.textContent ).toBe( '' );
		expect( cell.querySelector( 'a' ) ).toBeNull();
	} );

	it( 'exposes the status code as data-status', () => {
		const cell = draw( statusCell( 418 ) );
		expect( cell.className ).toBe(
			'newspack-nodes-table__cell entry-status'
		);
		expect( cell.getAttribute( 'data-status' ) ).toBe( '418' );
		expect( cell.textContent ).toBe( '418' );
	} );

	it( 'renders the client ip, dashed when absent', () => {
		expect( draw( ipCell( '203.0.113.77' ) ).textContent ).toBe(
			'203.0.113.77'
		);
		expect( draw( ipCell( '203.0.113.77' ) ).className ).toBe(
			'newspack-nodes-table__cell entry-ip'
		);
		expect( draw( ipCell( '' ) ).textContent ).toBe( '-' );
	} );

	it( 'renders the user agent with its full text as the tooltip', () => {
		const cell = draw( uaCell( 'Quixote/9.9 (windmill)' ) );
		expect( cell.className ).toBe( 'newspack-nodes-table__cell entry-ua' );
		expect( cell.getAttribute( 'title' ) ).toBe( 'Quixote/9.9 (windmill)' );
		expect( cell.textContent ).toBe( 'Quixote/9.9 (windmill)' );
		expect( draw( uaCell( '' ) ).textContent ).toBe( '-' );
	} );

	it( 'grades a duration by the shared threshold classes', () => {
		const cell = draw( durationCell( 7321 ) );
		expect( cell.className ).toBe(
			'newspack-nodes-table__cell entry-duration entry-duration--critical'
		);
		expect( cell.textContent ).toBe( '7.32s' );
		expect( draw( durationCell( 2400 ) ).className ).toContain(
			'entry-duration--slow'
		);
		expect( draw( durationCell( 42.5 ) ).className ).toContain(
			'entry-duration--fast'
		);
	} );

	it( 'renders a timestamp as a local wall clock', () => {
		const ts = new Date( 2031, 4, 17, 13, 42, 7, 913 ).getTime() / 1000;
		const cell = draw( timeCell( ts ) );
		expect( cell.className ).toBe(
			'newspack-nodes-table__cell entry-time'
		);
		expect( cell.textContent ).toBe( '13:42:07.913' );
	} );

	it( 'takes the React key from the caller, and has none without one', () => {
		expect( ridCell( 'rid-8471-zed', 'rid' ).key ).toBe( 'rid' );
		expect( urlCell( 'PATCH', '/q', 'h-1', 'url' ).key ).toBe( 'url' );
		expect( statusCell( 418, 'status' ).key ).toBe( 'status' );
		expect( ipCell( '203.0.113.77', 'remote_addr' ).key ).toBe(
			'remote_addr'
		);
		expect( uaCell( 'Quixote/9.9', 'user_agent' ).key ).toBe(
			'user_agent'
		);
		expect( durationCell( 7321, 'est' ).key ).toBe( 'est' );
		expect( timeCell( 1, 'time' ).key ).toBe( 'time' );
		expect( ridCell( 'rid-8471-zed' ).key ).toBeNull();
	} );
} );

describe( 'logColumns', () => {
	it( 'merges a dashboard spelling over the shared declaration', () => {
		const columns = logColumns( {
			rid: { tooltip: 'Click to view the trace' },
			url: { width: 'minmax(0, 7fr)' },
			keyword: {
				label: 'Keyword',
				tooltip: 'Error/warning keyword',
				width: '248px',
			},
		} );

		expect( columns.rid ).toEqual( {
			label: 'Request ID',
			tooltip: 'Click to view the trace',
			width: '240px',
		} );
		expect( columns.url ).toEqual( {
			label: 'URL',
			tooltip: 'Request method and URL - click to view URL stats',
			width: 'minmax(0, 7fr)',
		} );
		expect( columns.keyword ).toEqual( {
			label: 'Keyword',
			tooltip: 'Error/warning keyword',
			width: '248px',
		} );
	} );

	it( 'keeps the declared order, which is the table order', () => {
		expect(
			Object.keys(
				logColumns( { user_agent: {}, rid: {}, remote_addr: {} } )
			)
		).toEqual( [ 'user_agent', 'rid', 'remote_addr' ] );
	} );
} );

describe( 'logListHeader', () => {
	it( 'publishes the grid template and heads each column in order', () => {
		const { container } = renderComponent(
			logListHeader( {
				className: 'quixote-columns',
				columns: logColumns( {
					rid: {
						tooltip: 'Click to view the trace',
						width: '248px',
					},
					remote_addr: { width: '128px' },
				} ),
				order: [ 'remote_addr', 'rid' ],
			} )
		);

		const wrapper = container.firstElementChild;
		expect( wrapper.className ).toBe( 'quixote-columns' );
		expect(
			wrapper.style.getPropertyValue( '--stream-grid-template' )
		).toBe( '128px 248px' );
		const ths = [
			...wrapper.querySelectorAll( '.newspack-nodes-log-header__th' ),
		];
		expect( ths.map( ( th ) => th.textContent ) ).toEqual( [
			'IP',
			'Request ID',
		] );
		expect( ths.map( ( th ) => th.getAttribute( 'title' ) ) ).toEqual( [
			'Client IP address',
			'Click to view the trace',
		] );
	} );
} );

describe( 'toolbar labels', () => {
	it( 'names shown over held only while a filter hides rows', () => {
		expect(
			countLabel(
				{ visible: 17, total: 84 },
				'%d quixote',
				'%1$d / %2$d quixote'
			)
		).toBe( '17 / 84 quixote' );
		expect(
			countLabel(
				{ visible: 84, total: 84 },
				'%d quixote',
				'%1$d / %2$d quixote'
			)
		).toBe( '84 quixote' );
	} );

	it( 'names the plain total for a table that holds nothing back', () => {
		expect( countLabel( { total: 37 }, '%d quixote' ) ).toBe(
			'37 quixote'
		);
	} );

	it( 'renders a rate to one decimal place in the unit it is given', () => {
		expect( rateLabel( '%s windmills/s' )( 7.3218 ) ).toBe(
			'7.3 windmills/s'
		);
	} );
} );
