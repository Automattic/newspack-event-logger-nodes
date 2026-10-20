<?php
/**
 * Stands in for core's `WP_Object_Cache`, at the path core keeps it, so
 * `Object_Cache_Counts` can tell it by its class and its file.
 *
 * @package Newspack_Event_Logger_Nodes
 */

if ( ! class_exists( 'WP_Object_Cache', false ) ) {
	/** Core's counters, as `wp-includes/class-wp-object-cache.php` declares them. */
	class WP_Object_Cache {
		/** @var int Runtime-cache hits. */
		public $cache_hits = 0;
		/** @var int Runtime-cache misses. */
		public $cache_misses = 0;
	}
}
