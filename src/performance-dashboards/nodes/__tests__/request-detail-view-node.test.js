/**
 * RequestDetailViewNode tests — the on-demand request_detail slice view (D1b).
 *
 * request_detail is fetched on request-selection (modal → request). The view
 * publishes `{ data, loading, error }` and handles loading/clear/error like the
 * other slice views. It ALSO owns a PendingReplies registry for the awaited
 * `resolveRequest` (request_search) navigation lookup, whose reply resolves the
 * caller's Promise without touching the request_detail data slice.
 */

import {
	VALUE,
	TYPE,
	ID,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { RequestDetailViewNode } from '../request-detail-view-node';

beforeEach( () => Core.reset() );

function makeView() {
	const node = new RequestDetailViewNode();
	node.name = 'requestdetail:view';
	return node;
}

const view = () => Core.node( 'requestdetail:view' ).setStateCache.view;

const reply = (
	payload,
	{ isError = false, id = '', name = 'request_detail' } = {}
) => {
	const m = newMessage();
	m[ TYPE ] = isError
		? TM_COMMAND | TM_RESPONSE | TM_ERROR
		: TM_COMMAND | TM_RESPONSE;
	if ( id ) {
		m[ ID ] = id;
	}
	m[ VALUE ] = { name, payload };
	return m;
};

const ctrl = ( value ) => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
};

test( 'publishes an empty initial slice', () => {
	makeView();
	expect( view() ).toEqual( { data: null, loading: false, error: null } );
} );

test( 'a reply stores the request payload as data and clears loading', () => {
	const v = makeView();
	v.fill( ctrl( { action: 'loading' } ) );
	v.fill( reply( { rid: 'r1', entries: [] } ) );
	expect( view() ).toEqual( {
		data: { rid: 'r1', entries: [] },
		loading: false,
		error: null,
	} );
} );

test( 'a clear control resets the slice to empty', () => {
	const v = makeView();
	v.fill( reply( { rid: 'r1', entries: [] } ) );
	v.fill( ctrl( { action: 'clear' } ) );
	expect( view().data ).toBeNull();
} );

test( 'a TM_ERROR reply keeps prior data + surfaces the error', () => {
	const v = makeView();
	v.fill( reply( { rid: 'r1', entries: [] } ) );
	v.fill( reply( 'nope', { isError: true } ) );
	expect( view().error ).toBe( 'nope' );
	expect( view().data ).toEqual( { rid: 'r1', entries: [] } );
} );

test( 'an awaited request_search resolves via PendingReplies without touching data', async () => {
	const v = makeView();
	v.fill( reply( { rid: 'r1', entries: [] } ) );
	const dataBefore = view().data;

	const promise = new Promise( ( resolve, reject ) => {
		v.replies.add( 'op-7', resolve, reject );
	} );
	v.fill(
		reply(
			{ url_hash: 'h', partition: 2 },
			{ id: 'op-7', name: 'request_search' }
		)
	);
	await expect( promise ).resolves.toEqual( { url_hash: 'h', partition: 2 } );
	expect( view().data ).toBe( dataBefore );
} );

test( 'an awaited request_search rejects on TM_ERROR', async () => {
	const v = makeView();
	const promise = new Promise( ( resolve, reject ) => {
		v.replies.add( 'op-8', resolve, reject );
	} );
	v.fill(
		reply( 'not found', {
			id: 'op-8',
			isError: true,
			name: 'request_search',
		} )
	);
	await expect( promise ).rejects.toThrow( 'not found' );
} );
