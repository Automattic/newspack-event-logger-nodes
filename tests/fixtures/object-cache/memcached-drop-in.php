<?php
/**
 * Mirrors the stats fields of the production memcached drop-in
 * (`services/pyrobase/setup/wp-cache/object-cache.memcached.php`,
 * wp-ok-cache 2.0.0): a `WP_Object_Cache_Stats` helper whose `stats` and
 * `group_ops` the cache shares by reference, and `cache_hits` and
 * `cache_misses` aliased to the `get` and `add` counters.
 *
 * @package Newspack_Event_Logger_Nodes
 */

if ( ! class_exists( 'WP_Object_Cache_Stats', false ) ) {
	/** The drop-in's stats helper, fields only. */
	class WP_Object_Cache_Stats {
		/** @var array<string,int> */
		public array $stats = [
			'get'          => 0,
			'get_local'    => 0,
			'get_multi'    => 0,
			'set'          => 0,
			'set_local'    => 0,
			'add'          => 0,
			'delete'       => 0,
			'delete_local' => 0,
			'slow-ops'     => 0,
		];
		/** @var array<string,list<array<int,mixed>>> */
		public array $group_ops = [];
	}
}

/** The drop-in's `WP_Object_Cache`, its stats fields and its recording rule. */
class Memcached_Drop_In_Fixture {
	/** @var WP_Object_Cache_Stats The helper the drop-in declares, by type. */
	private WP_Object_Cache_Stats $stats_helper;
	/** @var array<string,int> */
	public array $stats = [];
	/** @var array<string,list<array<int,mixed>>> */
	public array $group_ops = [];
	/** @var int Aliased to `stats['get']`, as the drop-in aliases it. */
	public int $cache_hits = 0;
	/** @var int Aliased to `stats['add']`, as the drop-in aliases it. */
	public int $cache_misses = 0;

	/** Share the helper's fields, as the drop-in's constructor does. */
	public function __construct() {
		$this->stats_helper = new WP_Object_Cache_Stats();
		$this->stats        =& $this->stats_helper->stats;
		$this->group_ops    =& $this->stats_helper->group_ops;
		$this->cache_hits   =& $this->stats['get'];
		$this->cache_misses =& $this->stats['add'];
	}

	/**
	 * Record one operation as `WP_Object_Cache_Stats::group_ops_stats()`
	 * does: count it, keep no detail for a `_local` one, and keep a slow
	 * one twice, once under `slow-ops`.
	 *
	 * @param string          $op      The operation.
	 * @param string|string[] $keys    Its key or keys.
	 * @param string          $group   Its group.
	 * @param string          $comment Its comment.
	 * @param bool            $slow    Whether it ran past the slow-op limit.
	 */
	public function op( string $op, $keys, string $group, string $comment = '', bool $slow = false ): void {
		$this->stats[ $op ] = ( $this->stats[ $op ] ?? 0 ) + 1;
		if ( false !== strpos( $op, '_local' ) ) {
			return;
		}
		if ( $slow ) {
			$this->group_ops['slow-ops'][] = [ $op, $keys, null, 0.01, $comment, $group, 'trace' ];
		}
		$this->group_ops[ $group ][] = [ $op, $keys, null, 0.001, $comment ];
	}
}
