/**
 * The questions the Performance page's sources ask, as command tokens: each
 * scope, narrowing and page reaching the wire as the `performance` CI binds
 * it, and nothing a default already answers.
 */

import {
	categoryArgs,
	chartArgs,
	detailArgs,
	isValidHash,
	isValidRequestId,
	overviewArgs,
	urlsArgs,
} from '../questions';
import storedAnswers from '../../../includes/app/stored-answers.json';

/** The URL table's question, every control moved off its default. */
const TABLE = {
	server: 'kea-7920.example',
	chips: [ 'method=POST', 'status_class!=2' ],
	buckets: '2026-09-29-14-30..2026-09-29-14-40',
	sort: 'max_ms',
	order: 'asc',
	q: 'kokako',
	workers: true,
	paged: 3,
};

describe( 'urlsArgs', () => {
	it( 'asks a table page from its paged offset, every control by name', () => {
		expect( urlsArgs( TABLE ) ).toEqual( [
			'--sort=max_ms',
			'--order=asc',
			'--limit=100',
			'--offset=200',
			'--search=kokako',
			'--server=kea-7920.example',
			'--include_workers=1',
			'--bucket=2026-09-29-14-30..2026-09-29-14-40',
			'--filter=method=POST',
			'--filter=status_class!=2',
		] );
	} );

	it( 'asks the first page with no offset, and no default it leaves out', () => {
		expect(
			urlsArgs( {
				server: '',
				chips: [],
				buckets: '',
				sort: 'count',
				order: 'desc',
				q: '',
				workers: false,
				paged: 1,
			} )
		).toEqual( [ '--sort=count', '--order=desc', '--limit=100' ] );
	} );

	it( "asks an expanded page's shapes by --page, from their own offset", () => {
		expect(
			urlsArgs( { ...TABLE, page: 'b17e7921', offset: 1500 } )
		).toEqual( [
			'--page=b17e7921',
			'--sort=max_ms',
			'--order=asc',
			'--limit=1000',
			'--offset=1500',
			'--search=kokako',
			'--server=kea-7920.example',
			'--include_workers=1',
			'--bucket=2026-09-29-14-30..2026-09-29-14-40',
			'--filter=method=POST',
			'--filter=status_class!=2',
		] );
	} );
} );

describe( 'chartArgs', () => {
	it( "asks the site's series by both dimensions, under the scope and chips", () => {
		expect(
			chartArgs( {
				server: 'kea-7920.example',
				chips: [ 'country=NZ' ],
				breakdown: 'method',
				then: 'ja4',
			} )
		).toEqual( [
			'requests',
			'bucket',
			'method',
			'ja4',
			'--top=20',
			'--top_by=count',
			'--filter=worker=0',
			'--filter=server=kea-7920.example',
			'--filter=country=NZ',
			'--measure=count',
			'--measure=sum_ms',
			'--measure=sum_peak_mb',
			'--measure=timed',
		] );
	} );

	it( "lets a worker chip replace the site's worker scope", () => {
		const args = chartArgs( {
			server: '',
			chips: [ 'worker!=0' ],
			breakdown: 'status_class',
			then: '',
		} );
		expect( args ).toContain( '--filter=worker!=0' );
		expect( args ).not.toContain( '--filter=worker=0' );
	} );

	it( "asks a window's series under its subject, worker traffic counted", () => {
		const args = chartArgs( {
			subject: { field: 'page', hash: 'c4f7921a' },
			server: '',
			chips: [],
			breakdown: 'status_class',
			then: 'status_class',
		} );
		expect( args.slice( 0, 3 ) ).toEqual( [
			'requests',
			'bucket',
			'status_class',
		] );
		expect( args ).toContain( '--filter=page=c4f7921a' );
		expect( args ).not.toContain( '--filter=worker=0' );
	} );
} );

describe( 'categoryArgs', () => {
	it( "asks each category's own totals, dearest first, under the scope", () => {
		expect( categoryArgs( { server: 'tui-7922.example' } ) ).toEqual( [
			'timings',
			'bucket',
			'category',
			'--top=20',
			'--top_by=ms',
			'--filter=worker=0',
			'--filter=server=tui-7922.example',
			'--filter=entry=',
			'--measure=ms',
			'--measure=calls',
		] );
	} );

	it( "asks a window's categories under its subject", () => {
		expect(
			categoryArgs( {
				subject: { field: 'url', hash: 'd00d7923' },
				server: '',
			} )
		).toContain( '--filter=url=d00d7923' );
	} );
} );

describe( 'overviewArgs and detailArgs', () => {
	it( 'asks the overview under a server alone', () => {
		expect( overviewArgs( { server: 'kea-7920.example' } ) ).toEqual( [
			'--server=kea-7920.example',
		] );
		expect( overviewArgs( { server: '' } ) ).toEqual( [] );
	} );

	it( "asks dump_url for the window's subject and its own narrowing", () => {
		expect(
			detailArgs( {
				subject: { field: 'page', hash: 'ab7924' },
				server: 'kea-7920.example',
				buckets: '2026-09-29-14-35',
				chips: [ 'method=GET' ],
			} )
		).toEqual( [
			'--page=ab7924',
			'--server=kea-7920.example',
			'--bucket=2026-09-29-14-35',
			'--filter=method=GET',
		] );
	} );
} );

describe( 'what may go out as a token', () => {
	it.each( [
		[ 'c0ffee7925', true ],
		[ 'C0FFEE', false ],
		[ 'h1', false ],
		[ null, false ],
	] )( 'a hash %p is valid: %p', ( hash, valid ) => {
		expect( isValidHash( hash ) ).toBe( valid );
	} );

	it.each( [
		[ 'rid_7926-ab', true ],
		[ 'rid 7926', false ],
		[ '', false ],
		[ undefined, false ],
	] )( 'a rid %p is valid: %p', ( rid, valid ) => {
		expect( isValidRequestId( rid ) ).toBe( valid );
	} );
} );

describe( 'the questions the overview-prefetch job answers', () => {
	/**
	 * A chart's question with no chip, by one dimension.
	 *
	 * @param {string} server    The server scope.
	 * @param {string} breakdown The dimension.
	 * @return {Object} Its params.
	 */
	const chart = ( server, breakdown ) => ( {
		server,
		chips: [],
		breakdown,
		then: '',
	} );

	it.each( [
		[ 'overview', () => overviewArgs( { server: '' } ) ],
		[ 'chart_by_server', () => chartArgs( chart( '', 'server' ) ) ],
		[
			'chart_by_status_class',
			() => chartArgs( chart( '', 'status_class' ) ),
		],
		[ 'categories', () => categoryArgs( { server: '' } ) ],
		[ 'server_overview', () => overviewArgs( { server: '{server}' } ) ],
		[
			'server_chart_by_status_class',
			() => chartArgs( chart( '{server}', 'status_class' ) ),
		],
		[ 'server_categories', () => categoryArgs( { server: '{server}' } ) ],
	] )( 'asks %s token for token as the job stores it', ( name, ask ) => {
		expect( ask() ).toEqual( storedAnswers[ name ].tokens );
	} );

	it( 'leaves no stored question unasked', () => {
		expect( Object.keys( storedAnswers ).sort() ).toEqual(
			[
				'categories',
				'chart_by_server',
				'chart_by_status_class',
				'overview',
				'server_categories',
				'server_chart_by_status_class',
				'server_overview',
			].sort()
		);
	} );
} );
