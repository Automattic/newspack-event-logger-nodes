/**
 * Tests for detailSubject — what the detail window is about, a URL or a whole
 * page, and every spelling of it derived from its field.
 */

import {
	SUBJECT_PARAMS,
	findSubject,
	linkedSubject,
	sameSubject,
	subjectArgs,
	subjectDescriptor,
	subjectLink,
	subjectOfRow,
} from '../detailSubject';

/** A page asked three ways on one server: a page window opens from it. */
const KAHU = {
	page_hash: 'c0de5a1e7701',
	page: 'moa-7701.example/kahu-7701/',
	server: 'moa-7701.example',
	shapes: 3,
	hash: null,
	url: 'https://moa-7701.example/kahu-7701/',
	count: 47,
};

/** A page asked one way, whose row is that one URL. */
const TIEKE = {
	page_hash: 'c0de5a1e7702',
	page: 'moa-7701.example/tieke-7701/',
	server: 'moa-7701.example',
	shapes: 1,
	hash: 'fade0bee7702',
	url: 'https://moa-7701.example/Tieke-7701/?huia=?',
	count: 9,
};

/** A shape beneath a page, as `urls --page=` lists it. */
const SHAPE = {
	hash: 'fade0bee7703',
	url: 'https://moa-7701.example/kahu-7701/?kiwi=?',
	count: 12,
};

describe( 'subjectOfRow', () => {
	it( 'makes a page of several shapes a page subject, keyed by its page hash', () => {
		expect( subjectOfRow( KAHU ) ).toEqual( {
			field: 'page',
			hash: 'c0de5a1e7701',
			url: 'https://moa-7701.example/kahu-7701/',
		} );
	} );

	it( 'makes a one-shape page and a shape row the URL they name', () => {
		expect( subjectOfRow( TIEKE ) ).toEqual( {
			field: 'url',
			hash: 'fade0bee7702',
			url: 'https://moa-7701.example/Tieke-7701/?huia=?',
		} );
		expect( subjectOfRow( SHAPE ) ).toEqual( {
			field: 'url',
			hash: 'fade0bee7703',
			url: 'https://moa-7701.example/kahu-7701/?kiwi=?',
		} );
	} );
} );

describe( 'sameSubject', () => {
	it( 'matches on the field and the hash, never the hash alone', () => {
		const page = { field: 'page', hash: 'c0de5a1e7701' };
		expect(
			sameSubject( page, { ...page, url: 'https://moa.example/' } )
		).toBe( true );
		expect( sameSubject( page, { field: 'url', hash: page.hash } ) ).toBe(
			false
		);
		expect( sameSubject( page, null ) ).toBe( false );
		expect( sameSubject( null, null ) ).toBe( false );
	} );
} );

describe( 'findSubject', () => {
	it( 'finds a page subject only on a page row of several shapes', () => {
		expect(
			findSubject( [ TIEKE, KAHU ], {
				field: 'page',
				hash: 'c0de5a1e7701',
			} )
		).toEqual( subjectOfRow( KAHU ) );
		// A one-shape page's row is its URL, so its page is not on the page.
		expect(
			findSubject( [ TIEKE ], { field: 'page', hash: 'c0de5a1e7702' } )
		).toBeNull();
	} );

	it( 'finds a URL subject by its hash, and nothing for no subject', () => {
		expect(
			findSubject( [ KAHU, TIEKE ], {
				field: 'url',
				hash: 'fade0bee7702',
			} )
		).toEqual( subjectOfRow( TIEKE ) );
		expect( findSubject( [ KAHU, TIEKE ], null ) ).toBeNull();
	} );
} );

describe( 'the spellings', () => {
	it( 'asks dump_url for each subject by its own field, --url= or --page=', () => {
		expect(
			subjectArgs(
				{ field: 'url', hash: 'fade0bee7702' },
				{ server: 'moa-7701.example' }
			)
		).toEqual( [ '--url=fade0bee7702', '--server=moa-7701.example' ] );
		expect(
			subjectArgs(
				{ field: 'page', hash: 'c0de5a1e7701' },
				{ server: 'moa-7701.example' }
			)
		).toEqual( [ '--page=c0de5a1e7701', '--server=moa-7701.example' ] );
	} );

	it( 'names a subject to the picker as <field>:<hash>', () => {
		expect(
			subjectDescriptor( { field: 'page', hash: 'c0de5a1e7701' } )
		).toBe( 'page:c0de5a1e7701' );
		expect(
			subjectDescriptor( { field: 'url', hash: 'fade0bee7702' } )
		).toBe( 'url:fade0bee7702' );
	} );

	it( 'links a page as ?page_hash=, since ?page= names the admin screen', () => {
		expect( SUBJECT_PARAMS ).toEqual( { url: 'url', page: 'page_hash' } );
		expect(
			subjectLink( { field: 'page', hash: 'c0de5a1e7701' } )
		).toEqual( { url: null, page_hash: 'c0de5a1e7701' } );
		expect( subjectLink( { field: 'url', hash: 'fade0bee7702' } ) ).toEqual(
			{ url: 'fade0bee7702', page_hash: null }
		);
		expect( subjectLink( null ) ).toEqual( { url: null, page_hash: null } );
	} );
} );

describe( 'linkedSubject', () => {
	afterEach( () =>
		window.history.replaceState( null, '', '/wp-admin/admin.php' )
	);

	it( 'reads the subject the address bar links, page or URL', () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=perf&page_hash=c0de5a1e7701'
		);
		expect( linkedSubject() ).toEqual( {
			field: 'page',
			hash: 'c0de5a1e7701',
		} );

		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=perf&url=fade0bee7702'
		);
		expect( linkedSubject() ).toEqual( {
			field: 'url',
			hash: 'fade0bee7702',
		} );

		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=perf'
		);
		expect( linkedSubject() ).toBeNull();
	} );
} );
