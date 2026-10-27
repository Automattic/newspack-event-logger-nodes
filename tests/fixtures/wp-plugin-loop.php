<?php
/**
 * A stand-in for WordPress's `wp_get_active_and_valid_plugins()`, which reads
 * `active_plugins` ahead of the plugin loop. The profiler drop-in narrows only
 * a read made from inside it, so a test calls this to make that read.
 *
 * @package Newspack_Event_Logger_Nodes
 */

if ( ! function_exists( 'wp_get_active_and_valid_plugins' ) ) {
	/**
	 * Read `active_plugins` through its filter, as the plugin loop does.
	 *
	 * @param list<string> $stored The stored option value.
	 * @return mixed What the filter returns.
	 */
	function wp_get_active_and_valid_plugins( array $stored = [] ): mixed {
		return apply_filters( 'option_active_plugins', $stored );
	}
}
