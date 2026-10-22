<?php
/**
 * Fixture: a plugin named `kea-7746` misusing core, for the PHP error tests.
 * Each misuse is one call on one line, so a row's file and line name it.
 *
 * @package Newspack_Event_Logger_Nodes
 */

/**
 * Call one misused core API from this plugin.
 *
 * @param string $api The core function to call.
 */
function kea_7746_misuse( string $api ): void {
	$api();
}

/** A listener of this plugin's reporting a misuse through `wp_trigger_error()` itself. */
function kea_7746_callback(): void {
	wp_trigger_error( __FUNCTION__, 'kea 7746 direct', E_USER_WARNING );
}
