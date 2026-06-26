/**
 * UrlsViewNode tests — the focused custom slice view for the always-on urls
 * poll slice (D1b de-god). Its reply payload is the urls envelope
 * `{ data, total, limit, offset }`; the published slice exposes `data` (the URL
 * rows) + `total` (for the table footer), plus loading/error. Mirrors the old
 * performance-view-node `urls` slice shape so <UrlTable> reads it unchanged.
 */

import {
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { UrlsViewNode } from '../urls-view-node';

beforeEach( () => Core.reset() );

function makeView() {
	const node = new UrlsViewNode();
	node.name = 'urls:view';
	return node;
}

const view = () => Core.node( 'urls:view' ).setStateCache.view;

const reply = ( payload, isError = false ) => {
	const m = newMessage();
	m[ TYPE ] = isError
		? TM_COMMAND | TM_RESPONSE | TM_ERROR
		: TM_COMMAND | TM_RESPONSE;
	m[ VALUE ] = { name: 'urls', payload };
	return m;
};

test( 'publishes an empty initial slice with data:[] and total:0', () => {
	makeView();
	expect( view() ).toEqual( {
		data: [],
		total: 0,
		loading: false,
		error: null,
	} );
} );

test( 'a reply stores data + total from the envelope', () => {
	const v = makeView();
	v.fill(
		reply( { data: [ { hash: 'a' } ], total: 12, limit: 100, offset: 0 } )
	);
	expect( view() ).toEqual( {
		data: [ { hash: 'a' } ],
		total: 12,
		loading: false,
		error: null,
	} );
} );

test( 'a reply with a missing data/total falls back to [] / 0', () => {
	const v = makeView();
	v.fill( reply( {} ) );
	expect( view().data ).toEqual( [] );
	expect( view().total ).toBe( 0 );
} );

test( 'a TM_ERROR reply keeps prior data + total', () => {
	const v = makeView();
	v.fill( reply( { data: [ { hash: 'a' } ], total: 5 } ) );
	v.fill( reply( 'boom', true ) );
	expect( view().error ).toBe( 'boom' );
	expect( view().data ).toEqual( [ { hash: 'a' } ] );
	expect( view().total ).toBe( 5 );
} );
