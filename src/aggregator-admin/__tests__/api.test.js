/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
/**
 * Tests for the aggregator-admin api wrappers.
 *
 * The admin "Configured Servers" UI does 4 CRUD verbs against the
 * `servers` service CI. These tests verify the wrappers compose the right
 * args, dispatch through the injected CommandClient with `to: 'servers'`,
 * and unwrap the response so the jQuery handlers can stay terse.
 *
 * The implementation does NOT touch jQuery or the DOM — that lives in
 * index.js. These tests cover the pure dispatch + unwrap logic only.
 */

import { TM_COMMAND, TM_RESPONSE, TM_ERROR } from '@newspack-nodes/runtime';

import { addServer, updateServer, removeServer, testServer } from '../api';

function buildResponse( type, valueObject ) {
	return [
		type,
		1.23,
		'servers',
		'_http',
		'cmd-1',
		'',
		JSON.stringify( valueObject ),
	];
}

function buildOkResponse( payloadObject ) {
	return buildResponse( TM_COMMAND | TM_RESPONSE, {
		name: 'add',
		payload: JSON.stringify( payloadObject ),
	} );
}

function fakeClient( responseBuilder ) {
	const sent = [];
	const client = {
		sent,
		send: async ( params ) => {
			sent.push( params );
			return responseBuilder( params );
		},
	};
	return client;
}

describe( 'aggregator-admin/api', () => {
	describe( 'addServer', () => {
		it( 'dispatches servers.add with the supplied fields and returns the unwrapped payload', async () => {
			const client = fakeClient( () =>
				buildOkResponse( { id: 'spoke-01' } )
			);

			const result = await addServer( client, {
				id: 'spoke-01',
				url: 'https://spoke.example/',
				auth_username: 'admin',
				auth_password: 'secret',
			} );

			expect( client.sent ).toHaveLength( 1 );
			expect( client.sent[ 0 ] ).toMatchObject( {
				to: 'servers',
				verb: 'add',
			} );
			expect( client.sent[ 0 ].args ).toEqual( {
				id: 'spoke-01',
				url: 'https://spoke.example/',
				auth_username: 'admin',
				auth_password: 'secret',
				enabled: true,
			} );
			expect( result ).toEqual( { id: 'spoke-01' } );
		} );

		it( 'throws when the response carries TM_ERROR', async () => {
			const client = fakeClient( () =>
				buildResponse( TM_COMMAND | TM_ERROR, {
					name: 'add',
					payload: 'server already exists: spoke-01',
				} )
			);

			await expect(
				addServer( client, {
					id: 'spoke-01',
					url: 'https://spoke.example/',
					auth_username: '',
					auth_password: '',
				} )
			).rejects.toThrow( 'server already exists: spoke-01' );
		} );
	} );

	describe( 'updateServer', () => {
		it( 'dispatches servers.update with the merged id + partial fields', async () => {
			const client = fakeClient( () =>
				buildOkResponse( { id: 'spoke-01' } )
			);

			await updateServer( client, 'spoke-01', { enabled: false } );

			expect( client.sent[ 0 ] ).toMatchObject( {
				to: 'servers',
				verb: 'update',
			} );
			expect( client.sent[ 0 ].args ).toEqual( {
				id: 'spoke-01',
				enabled: false,
			} );
		} );
	} );

	describe( 'removeServer', () => {
		it( 'dispatches servers.delete with just the id', async () => {
			const client = fakeClient( () =>
				buildOkResponse( { id: 'spoke-01' } )
			);

			await removeServer( client, 'spoke-01' );

			expect( client.sent[ 0 ] ).toMatchObject( {
				to: 'servers',
				verb: 'delete',
			} );
			expect( client.sent[ 0 ].args ).toEqual( { id: 'spoke-01' } );
		} );
	} );

	describe( 'testServer', () => {
		it( 'dispatches servers.test with just the id and returns the parsed probe payload', async () => {
			const probe = {
				id: 'spoke-01',
				status: 'connected',
				response: { lag: 42 },
			};
			const client = fakeClient( () => buildOkResponse( probe ) );

			const result = await testServer( client, 'spoke-01' );

			expect( client.sent[ 0 ] ).toMatchObject( {
				to: 'servers',
				verb: 'test',
				args: { id: 'spoke-01' },
			} );
			expect( result ).toEqual( probe );
		} );
	} );
} );
