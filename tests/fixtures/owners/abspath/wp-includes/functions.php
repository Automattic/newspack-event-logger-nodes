<?php
/**
 * Fixture: WordPress's deprecation and misuse helpers, as `wp-includes/functions.php`
 * declares them. Each fires its action whatever `WP_DEBUG` says and, only under
 * it, raises the error through `wp_trigger_error()`. `$GLOBALS['kea_7746_wp_debug']`
 * stands in for the constant, which a test cannot undefine. A WordPress older
 * than 7.0, as `get_bloginfo( 'version' )` says, fires no
 * `wp_trigger_error_always_run`. The core APIs below stand in for the
 * functions that call each helper.
 *
 * @package Newspack_Event_Logger_Nodes
 */

/** Whether the stand-in `WP_DEBUG` is on. */
function kea_7746_wp_debug(): bool {
	return ! empty( $GLOBALS['kea_7746_wp_debug'] );
}

/** As `_deprecated_function()`. */
function _deprecated_function( $function_name, $version, $replacement = '' ) {
	do_action( 'deprecated_function_run', $function_name, $replacement, $version );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( '', "Function {$function_name} is deprecated since version {$version}!", E_USER_DEPRECATED );
	}
}

/** As `_deprecated_constructor()`. */
function _deprecated_constructor( $class_name, $version, $parent_class = '' ) {
	do_action( 'deprecated_constructor_run', $class_name, $version, $parent_class );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( '', "The called constructor method for {$class_name} is deprecated since version {$version}!", E_USER_DEPRECATED );
	}
}

/** As `_deprecated_class()`. */
function _deprecated_class( $class_name, $version, $replacement = '' ) {
	do_action( 'deprecated_class_run', $class_name, $replacement, $version );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( '', "Class {$class_name} is deprecated since version {$version}!", E_USER_DEPRECATED );
	}
}

/** As `_deprecated_file()`. */
function _deprecated_file( $file, $version, $replacement = '', $message = '' ) {
	do_action( 'deprecated_file_included', $file, $replacement, $version, $message );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( '', "File {$file} is deprecated since version {$version}!", E_USER_DEPRECATED );
	}
}

/** As `_deprecated_argument()`. */
function _deprecated_argument( $function_name, $version, $message = '' ) {
	do_action( 'deprecated_argument_run', $function_name, $message, $version );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( '', "Function {$function_name} was called with an argument that is deprecated since version {$version}!", E_USER_DEPRECATED );
	}
}

/** As `_deprecated_hook()`. */
function _deprecated_hook( $hook, $version, $replacement = '', $message = '' ) {
	do_action( 'deprecated_hook_run', $hook, $replacement, $version, $message );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( '', "Hook {$hook} is deprecated since version {$version}!", E_USER_DEPRECATED );
	}
}

/** As `_doing_it_wrong()`. */
function _doing_it_wrong( $function_name, $message, $version ) {
	do_action( 'doing_it_wrong_run', $function_name, $message, $version );
	if ( kea_7746_wp_debug() ) {
		wp_trigger_error( $function_name, $message );
	}
}

/** As `wp_trigger_error()`, whose own error raises only under `WP_DEBUG`. */
function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
	if ( version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ) {
		do_action( 'wp_trigger_error_always_run', $function_name, $message, $error_level );
	}
	if ( ! kea_7746_wp_debug() ) {
		return;
	}
	do_action( 'wp_trigger_error_run', $function_name, $message, $error_level );
	if ( '' !== $function_name ) {
		$message = "{$function_name}(): {$message}";
	}
	trigger_error( $message, $error_level ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- as core raises it.
}

/** A core function deprecated since 6.4.0. */
function kea_7746_deprecated_api(): void {
	_deprecated_function( __FUNCTION__, '6.4.0', 'kea_7746_current_api' );
}

/** A core class's PHP4-style constructor. */
function kea_7746_old_constructor(): void {
	_deprecated_constructor( 'Kea_7746_Widget', '4.3.0', 'Kea_7746_Parent' );
}

/** A core class deprecated since 6.4.0. */
function kea_7746_old_class(): void {
	_deprecated_class( 'Kea_7746_Legacy', '6.4.0', 'Kea_7746_Modern' );
}

/** A core file deprecated since 6.4.0. */
function kea_7746_old_file(): void {
	_deprecated_file( 'kea-7746-legacy.php', '6.4.0', 'kea-7746-modern.php', 'kea moved' );
}

/** A core function one of whose arguments is deprecated. */
function kea_7746_old_argument(): void {
	_deprecated_argument( __FUNCTION__, '6.4.0', 'kea $flag is ignored' );
}

/** A deprecated hook, fired as `do_action_deprecated()` fires one. */
function kea_7746_old_hook(): void {
	_deprecated_hook( 'kea_7746_old_hook', '6.4.0', 'kea_7746_new_hook', 'kea hook moved' );
}

/** A core function called too early. */
function kea_7746_checked_api(): void {
	_doing_it_wrong( __FUNCTION__, 'kea 7746 called too early', '6.5.0' );
}

/** A core function reporting a misuse of its own through `wp_trigger_error()`. */
function kea_7746_triggering_api(): void {
	wp_trigger_error( __FUNCTION__, 'kea 7746 trigger', E_USER_WARNING );
}

/** As `wp_enqueue_script()` called before `wp_enqueue_scripts`. */
function kea_7746_enqueue_script(): void {
	_kea_7746_scripts_maybe_doing_it_wrong( __FUNCTION__ );
}

/** As `_wp_scripts_maybe_doing_it_wrong()`. */
function _kea_7746_scripts_maybe_doing_it_wrong( $function_name ) {
	_doing_it_wrong( $function_name, 'kea 7746 enqueued too early', '3.3.0' );
}

/** A core function whose misuse is found three core frames down. */
function kea_7746_deep_api(): void {
	_kea_7746_deep_1();
}

/** The first of three core frames between the API and its helper. */
function _kea_7746_deep_1(): void {
	_kea_7746_deep_2();
}

/** The second. */
function _kea_7746_deep_2(): void {
	_kea_7746_deep_3();
}

/** The third, which reports the misuse. */
function _kea_7746_deep_3(): void {
	_doing_it_wrong( 'kea_7746_deep_api', 'kea 7746 deep', '6.6.0' );
}
