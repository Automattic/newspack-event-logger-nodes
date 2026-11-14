/**
 * logTable — the vocabulary the In-Flight, Request Log and Error Log tables
 * share. The cell helpers take explicit values, never a row, because the three
 * dashboards spell the same field differently (`est_ms`/`time_ms` vs
 * `duration_ms`, `ts` vs `timestamp`).
 */

import {
	countLabel,
	durationCell,
	ipCell,
	logColumns,
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

	test( 'urlCell shows a grouped key as the URL its request asked for', () => {
		const cell = draw(
			urlCell(
				'GET',
				'https://kea.test/author/sociales?/page/2/&moa=?',
				'hash-5108'
			)
		);
		const asked = 'https://kea.test/author/sociales/page/2/?moa=?';
		expect( cell.getAttribute( 'title' ) ).toBe( asked );
		expect( cell.querySelector( 'a.entry-url-link' ).textContent ).toBe(
			asked
		);
		expect(
			cell.querySelector( 'a.entry-url-link' ).getAttribute( 'href' )
		).toBe( 'admin.php?page=event-logger-overview&url=hash-5108' );
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
} );

describe( 'logColumns', () => {
	it( 'declares each column a rows field, merging a page’s spelling over the shared one', () => {
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
			kind: 'rows',
			label: 'Request ID',
			tooltip: 'Click to view the trace',
			width: '240px',
		} );
		expect( columns.url ).toEqual( {
			kind: 'rows',
			label: 'URL',
			tooltip: 'Request method and URL - click to view URL stats',
			width: 'minmax(0, 7fr)',
		} );
		expect( columns.keyword ).toEqual( {
			kind: 'rows',
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
