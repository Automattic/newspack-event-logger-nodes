/**
 * UrlDetailViewNode tests — the on-demand url_detail slice view (D1b de-god).
 *
 * url_detail is fetched on modal-open (not polled). The reply arrives already
 * merged by UrlDetailMergeNode on the receiver→view edge, so this view's job is
 * just to publish the merged payload as `{ data, loading, error }`. It ALSO
 * handles:
 *   - a TM_STRUCT { action:'loading' } control (modal open → spinner);
 *   - a TM_STRUCT { action:'clear' } control (modal close → reset to empty);
 *   - a TM_ERROR reply (keep prior data, surface error);
 *   - an awaited resolveOnly verb via PendingReplies (fetchUrlBreakdown), which
 *     does NOT touch the data slice — it resolves the caller's Promise.
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
import { UrlDetailViewNode } from '../url-detail-view-node';

beforeEach( () => Core.reset() );

function makeView() {
	const node = new UrlDetailViewNode();
	node.name = 'urldetail:view';
	return node;
}

const view = () => Core.node( 'urldetail:view' ).setStateCache.view;

const reply = ( payload, { isError = false, id = '' } = {} ) => {
	const m = newMessage();
	m[ TYPE ] = isError
		? TM_COMMAND | TM_RESPONSE | TM_ERROR
		: TM_COMMAND | TM_RESPONSE;
	if ( id ) {
		m[ ID ] = id;
	}
	m[ VALUE ] = { name: 'url_detail', payload };
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

test( 'a loading control sets loading:true and clears error', () => {
	const v = makeView();
	v.fill( ctrl( { action: 'loading' } ) );
	expect( view() ).toEqual( { data: null, loading: true, error: null } );
} );

test( 'an error control surfaces the error string + clears loading, keeps prior data', () => {
	const v = makeView();
	v.fill( reply( { last_modified: 1, requests: [] } ) );
	v.fill( ctrl( { action: 'error', error: 'Invalid URL hash format' } ) );
	expect( view().error ).toBe( 'Invalid URL hash format' );
	expect( view().loading ).toBe( false );
	// A client-side validation error must NOT clobber prior data.
	expect( view().data ).toEqual( { last_modified: 1, requests: [] } );
} );

test( 'a merged reply stores the payload as data and clears loading', () => {
	const v = makeView();
	v.fill( ctrl( { action: 'loading' } ) );
	v.fill( reply( { last_modified: 1, requests: [ { rid: 'a' } ] } ) );
	expect( view() ).toEqual( {
		data: { last_modified: 1, requests: [ { rid: 'a' } ] },
		loading: false,
		error: null,
	} );
} );

test( 'a clear control resets the slice to empty', () => {
	const v = makeView();
	v.fill( reply( { last_modified: 1, requests: [] } ) );
	v.fill( ctrl( { action: 'clear' } ) );
	expect( view() ).toEqual( { data: null, loading: false, error: null } );
} );

test( 'a TM_ERROR reply keeps prior data + surfaces the error', () => {
	const v = makeView();
	v.fill( reply( { last_modified: 1, requests: [ { rid: 'a' } ] } ) );
	v.fill( reply( 'boom', { isError: true } ) );
	expect( view().error ).toBe( 'boom' );
	expect( view().data ).toEqual( {
		last_modified: 1,
		requests: [ { rid: 'a' } ],
	} );
} );

test( 'an awaited resolveOnly verb resolves via PendingReplies without touching data', async () => {
	const v = makeView();
	v.fill( reply( { last_modified: 1, requests: [] } ) );
	const dataBefore = view().data;

	const promise = new Promise( ( resolve, reject ) => {
		v.replies.add( 'op-1', resolve, reject );
	} );
	v.fill( reply( { breakdown_time_series: { x: 1 } }, { id: 'op-1' } ) );
	await expect( promise ).resolves.toEqual( {
		breakdown_time_series: { x: 1 },
	} );
	// The pending-matched reply did NOT clobber the data slice.
	expect( view().data ).toBe( dataBefore );
} );
