/* global jQuery */
/**
 * Aggregator Admin entry — wires the "Configured Servers" UI's
 * Test/Toggle/Remove/Add buttons through the M5 CommandClient.
 *
 * Ported from the legacy `assets/aggregator-admin.js` jQuery script, which
 * used raw `$.ajax` against `/wp-json/newspack-nodes/v1/servers/*` REST
 * routes. M5.2 deletes those routes; this entry dispatches the same 4
 * verbs against the `servers` service CI through the shared
 * CommandClient — exact same UX, single unified `/command` transport.
 *
 * Why jQuery: the surrounding admin page (Admin::configured_servers_callback)
 * is server-rendered HTML — there's no React tree to mount into. jQuery
 * stays for the DOM glue but the actual CRUD path lives in `api.js` and
 * is unit-tested independent of the DOM.
 *
 * Why a wp-scripts build entry: lets `api.js` import the substrate
 * `CommandClient` via the `@newspack-nodes/runtime` alias. The legacy
 * jQuery file couldn't — `import` doesn't work in raw browser scripts
 * without a bundler.
 */

import { getCommandClient } from '../shared/utils/commandClient';
import { addServer, updateServer, removeServer, testServer } from './api';

( function ( $ ) {
	'use strict';

	const config = window.eventAggregatorAdmin || {};
	const i18n = config.i18n || {};

	function client() {
		return getCommandClient();
	}

	/**
	 * Extract a human-readable error message from a thrown CommandClient
	 * error. CommandClient unwrap throws `Error(payload)` for TM_ERROR
	 * responses, where `payload` is the verb's exception message — already
	 * sanitized server-side.
	 *
	 * @param {Error} err Thrown error from the api wrappers.
	 * @return {string} Display message.
	 */
	function errorMessage( err ) {
		return ( err && err.message ) || i18n.error || 'Error';
	}

	/**
	 * Test server connection.
	 */
	$( document ).on( 'click', '.event-aggregator-test', async function ( e ) {
		e.preventDefault();
		const $button = $( this );
		const serverId = $button.data( 'server-id' );
		const $row = $button.closest( 'tr' );
		const $status = $row.find( '.test-status' );

		$button.prop( 'disabled', true );
		$status.text( i18n.testing || 'Testing...' ).css( 'color', '' );

		try {
			await testServer( client(), serverId );
			$status
				.text( i18n.success || 'Connected!' )
				.css( 'color', 'green' );
		} catch ( err ) {
			$status
				.text(
					( i18n.failed || 'Failed' ) + ': ' + errorMessage( err )
				)
				.css( 'color', 'red' );
		} finally {
			$button.prop( 'disabled', false );
		}
	} );

	/**
	 * Toggle server enabled/disabled.
	 */
	$( document ).on(
		'click',
		'.event-aggregator-toggle',
		async function ( e ) {
			e.preventDefault();
			const $button = $( this );
			const serverId = $button.data( 'server-id' );
			const currentlyEnabled = $button.data( 'enabled' ) === 1;

			$button.prop( 'disabled', true );

			try {
				await updateServer( client(), serverId, {
					enabled: ! currentlyEnabled,
				} );
				window.location.reload();
			} catch ( err ) {
				// eslint-disable-next-line no-alert
				window.alert(
					( i18n.error || 'Error' ) + ': ' + errorMessage( err )
				);
				$button.prop( 'disabled', false );
			}
		}
	);

	/**
	 * Remove server.
	 */
	$( document ).on(
		'click',
		'.event-aggregator-remove',
		async function ( e ) {
			e.preventDefault();

			const confirmMsg =
				i18n.confirmRemove ||
				'Are you sure you want to remove this server?';
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			const $button = $( this );
			const serverId = $button.data( 'server-id' );
			$button.prop( 'disabled', true );

			try {
				await removeServer( client(), serverId );
				window.location.reload();
			} catch ( err ) {
				// eslint-disable-next-line no-alert
				window.alert(
					( i18n.error || 'Error' ) + ': ' + errorMessage( err )
				);
				$button.prop( 'disabled', false );
			}
		}
	);

	/**
	 * Add new server.
	 */
	$( document ).on(
		'click',
		'#event-aggregator-add-server',
		async function ( e ) {
			e.preventDefault();

			const serverId = $( '#new-server-id' ).val().trim();
			if ( ! serverId ) {
				$( '#add-server-status' )
					.text( 'Server ID is required' )
					.css( 'color', 'red' );
				return;
			}

			const serverUrl = $( '#new-server-url' ).val().trim();
			if ( ! serverUrl ) {
				$( '#add-server-status' )
					.text( 'Server URL is required' )
					.css( 'color', 'red' );
				return;
			}
			if ( ! serverUrl.startsWith( 'https://' ) ) {
				$( '#add-server-status' )
					.text( 'URL must start with https://' )
					.css( 'color', 'red' );
				return;
			}

			const $button = $( this );
			const $status = $( '#add-server-status' );
			const authUsername = $( '#new-server-username' ).val().trim();
			const authPassword = $( '#new-server-password' ).val();

			$button.prop( 'disabled', true );
			$status.text( i18n.adding || 'Adding...' ).css( 'color', '' );

			try {
				await addServer( client(), {
					id: serverId,
					url: serverUrl,
					auth_username: authUsername,
					auth_password: authPassword,
				} );
				$status
					.text( i18n.added || 'Server added! Reloading...' )
					.css( 'color', 'green' );
				$( '#new-server-id' ).val( '' );
				$( '#new-server-url' ).val( '' );
				$( '#new-server-username' ).val( '' );
				$( '#new-server-password' ).val( '' );
				setTimeout( function () {
					window.location.reload();
				}, 500 );
			} catch ( err ) {
				$status
					.text(
						( i18n.error || 'Error' ) + ': ' + errorMessage( err )
					)
					.css( 'color', 'red' );
				$button.prop( 'disabled', false );
			}
		}
	);
} )( jQuery );
