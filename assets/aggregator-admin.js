/* global jQuery */
/**
 * Aggregator Admin JavaScript
 *
 * Handles the Remote Servers UI in the Event Logger settings page:
 * Test/Toggle/Remove buttons on existing rows, plus the Add New Server form.
 *
 * Ported from the legacy newspack-event-aggregator/assets/admin.js. Same
 * REST shape; only the namespace base differs (was event-aggregator/v1,
 * now newspack-nodes/v1 — passed in via window.eventAggregatorAdmin.restUrl).
 *
 * @param {Object} $ - jQuery instance.
 */
( function ( $ ) {
	'use strict';

	const config = window.eventAggregatorAdmin || {};
	const i18n = config.i18n || {};

	/**
	 * Test server connection.
	 */
	$( document ).on( 'click', '.event-aggregator-test', function ( e ) {
		e.preventDefault();
		const $button = $( this );
		const serverId = $button.data( 'server-id' );
		const $row = $button.closest( 'tr' );
		const $status = $row.find( '.test-status' );

		$button.prop( 'disabled', true );
		$status.text( i18n.testing || 'Testing...' ).css( 'color', '' );

		$.ajax( {
			url:
				config.restUrl +
				'servers/' +
				encodeURIComponent( serverId ) +
				'/test',
			method: 'POST',
			beforeSend( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
			},
			success() {
				$status
					.text( i18n.success || 'Connected!' )
					.css( 'color', 'green' );
			},
			error( xhr ) {
				const msg =
					( xhr.responseJSON && xhr.responseJSON.message ) ||
					xhr.statusText;
				$status
					.text( ( i18n.failed || 'Failed' ) + ': ' + msg )
					.css( 'color', 'red' );
			},
			complete() {
				$button.prop( 'disabled', false );
			},
		} );
	} );

	/**
	 * Toggle server enabled/disabled.
	 */
	$( document ).on( 'click', '.event-aggregator-toggle', function ( e ) {
		e.preventDefault();
		const $button = $( this );
		const serverId = $button.data( 'server-id' );
		const currentlyEnabled = $button.data( 'enabled' ) === 1;

		$button.prop( 'disabled', true );

		$.ajax( {
			url: config.restUrl + 'servers/' + encodeURIComponent( serverId ),
			method: 'PUT',
			headers: {
				'X-WP-Nonce': config.nonce,
			},
			data: JSON.stringify( {
				enabled: ! currentlyEnabled,
			} ),
			contentType: 'application/json',
			success() {
				// Reload page to show updated state.
				window.location.reload();
			},
			error( xhr ) {
				// eslint-disable-next-line no-alert
				window.alert(
					( i18n.error || 'Error' ) +
						': ' +
						( xhr.responseJSON?.message || xhr.statusText )
				);
				$button.prop( 'disabled', false );
			},
		} );
	} );

	/**
	 * Remove server.
	 */
	$( document ).on( 'click', '.event-aggregator-remove', function ( e ) {
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

		$.ajax( {
			url: config.restUrl + 'servers/' + encodeURIComponent( serverId ),
			method: 'DELETE',
			headers: {
				'X-WP-Nonce': config.nonce,
			},
			success() {
				window.location.reload();
			},
			error( xhr ) {
				// eslint-disable-next-line no-alert
				window.alert(
					( i18n.error || 'Error' ) +
						': ' +
						( xhr.responseJSON?.message || xhr.statusText )
				);
				$button.prop( 'disabled', false );
			},
		} );
	} );

	/**
	 * Add new server.
	 */
	$( document ).on( 'click', '#event-aggregator-add-server', function ( e ) {
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

		$.ajax( {
			url: config.restUrl + 'servers',
			method: 'POST',
			headers: {
				'X-WP-Nonce': config.nonce,
			},
			data: JSON.stringify( {
				id: serverId,
				url: serverUrl,
				auth_username: authUsername,
				auth_password: authPassword,
				enabled: true,
			} ),
			contentType: 'application/json',
			success() {
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
			},
			error( xhr ) {
				$status
					.text(
						( i18n.error || 'Error' ) +
							': ' +
							( xhr.responseJSON?.message || xhr.statusText )
					)
					.css( 'color', 'red' );
				$button.prop( 'disabled', false );
			},
		} );
	} );
} )( jQuery );
