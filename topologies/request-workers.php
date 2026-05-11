<?php
/**
 * Request-workers topology — group 2 of 3.
 *
 *   Consumer (requests.log) ─→ FlameBuilder ─→ Partition (flames.log)
 *                                            └→ Stats_Store (memcache)
 *
 * Tails the `requests.log` partition produced by the `firehose-workers` group
 * (each line is a JSON-encoded completed request). FlameBuilder aggregates
 * count + sum_time per event-name into the 9-namespace memcache schema and
 * writes per-request flame JSON to `flames.log`.
 *
 * Wiring: every node sinks to `$interpreter` (automatic via `make_node`),
 * named routing via `connect_node`. FlameBuilder uses a SEPARATE
 * `flames_sink` for its main write path (a direct Partition reference) — its
 * primary sink stays $interpreter so any acks/responses route through
 * `_router`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return static function ( \Newspack_Nodes\CommandInterpreter $interpreter, int $partition ): array {
	// Application Config (not PerformanceControllerBase::load_config) because
	// this topology reads application-only keys: auto_disable_threshold,
	// auto_protect_time_threshold, significant_events. The controller-base
	// loader only layers in substrate options + a substrate-shape default,
	// so calling it returns 0 for the thresholds even when the operator has
	// set them via the Settings UI — auto-tune silently never fires.
	$config        = \Newspack_Event_Logger_Nodes\Config::load_config( 'full' );
	$base_dir      = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
	$logs_dir      = "{$base_dir}/logs";
	$requests_path = "{$logs_dir}/requests.log";
	$flames_path   = "{$logs_dir}/flames.log";

	if ( ! \is_dir( $logs_dir ) ) {
		@\mkdir( $logs_dir, 0755, true );
	}

	$segment_size     = (int) ( $config['segment_size'] ?? ( 16 * 1024 * 1024 ) );
	$num_segments     = (int) ( $config['num_segments'] ?? 8 );
	$max_lifespan     = (int) ( $config['max_lifespan'] ?? 86400 );
	$memcache_servers = $config['memcache_servers'] ?? \Newspack_Event_Logger_Nodes\Memcached_Cache::DEFAULT_SERVERS;
	if ( ! \is_array( $memcache_servers ) ) {
		$memcache_servers = \Newspack_Event_Logger_Nodes\Memcached_Cache::DEFAULT_SERVERS;
	}

	$cache       = new \Newspack_Event_Logger_Nodes\Memcached_Cache( $memcache_servers );
	$stats_store = new \Newspack_Event_Logger_Nodes\Stats_Store( $cache, $partition, $max_lifespan );

	// --- Output Partition: flames.log (terminal, no connect_node) -----------
	// Per-request flame trees can run multi-KB; allow_large_writes lifts
	// the cap to 10MB and acquires a per-Partition lock.
	$flames_log = $interpreter->make_node( 'Partition', 'flames:partition', $flames_path, $partition, $segment_size, $num_segments, $max_lifespan );
	$flames_log->allow_large_writes();
	// FlameBuilder's drilldown index — `{rid, url_hash, segment_id, offset, length}`
	// in fixed-width text format. Without this, `find_flame_for_rid` can't
	// resolve `requests.log` -> matching flame entry.
	$flames_log->with_index(
		static fn ( $line, $position, &$data = null ) => \Newspack_Event_Logger_Nodes\FlameBuilder::format_index_entry( $line, $position, $data )
	);

	// --- FlameBuilder -------------------------------------------------------
	$flame_builder = $interpreter->make_node( 'FlameBuilder', 'flame-builder' );
	$flame_builder->set_stats_store( $stats_store );
	$flame_builder->set_flames_sink( $flames_log );
	$flame_builder->set_is_hub( ! empty( $config['aggregator_servers'] ) );
	$flame_builder->set_auto_tune(
		(int) ( $config['auto_disable_threshold'] ?? 0 ),
		(float) ( $config['auto_protect_time_threshold'] ?? 0 )
	);
	if ( isset( $config['significant_events'] ) && \is_array( $config['significant_events'] ) ) {
		$flame_builder->set_significant_events( $config['significant_events'] );
	}

	// --- Input: Consumer of requests.log ------------------------------------
	$requests_offsets = "{$base_dir}/offsets/requests.p{$partition}";
	$requests_in      = $interpreter->make_node( 'Consumer', 'requests:consumer', $requests_path, $partition, $requests_offsets );
	$requests_in->connect_node( 'flame-builder' );

	return [
		'partition'     => $partition,
		'requests_in'   => $requests_in,
		'flame_builder' => $flame_builder,
		'flames_log'    => $flames_log,
		'stats_store'   => $stats_store,
	];
};
