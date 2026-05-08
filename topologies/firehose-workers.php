<?php
/**
 * Firehose-workers topology.
 *
 * Returns a closure that builds the per-partition firehose worker graph:
 *
 *   Tail (firehose.log)  ─┐
 *                         ├─→ Tee → RequestBuilder ─→ Tee → Partition (requests.log)
 *                         │                              └→ FlameBuilder ─→ Partition (flames.log)
 *                         │       └→ Partition (errors.log)
 *                         │       └→ JobRouter → JobWorker
 *   Tail (jobintake.log) ─┘
 *
 * The RequestBuilder emits a JSON-encoded completed request which fans out via
 * a downstream Tee to (a) the requests.log Partition for durable storage and
 * (b) FlameBuilder for flame-tree + per-URL aggregation. FlameBuilder writes
 * its per-request flame JSON directly to a flames.log Partition (set as
 * `flames_sink`), and pushes aggregate sums to memcache via a Stats_Store
 * (set as `stats_store`).
 *
 * RequestBuilder's error/warning forwarding goes to a separate errors.log
 * Partition (set as `errors_sink`), separate from the main sink so writes
 * don't compete on the request flow.
 *
 * The graph is materialized via direct `new`-instantiation (not the shell
 * `make_node` path) because application Node subclasses aren't registered in
 * CommandInterpreter::$class_map. CI is still passed for parity with the
 * runtime topology contract.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return static function ( \Newspack_Nodes\CommandInterpreter $ci, int $partition ): array {
	$config         = \Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase::load_config();
	$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
	$logs_dir       = "{$base_dir}/logs";
	$firehose_path  = "{$logs_dir}/firehose.log";
	$jobintake_path = "{$logs_dir}/jobintake.log";
	$requests_path  = "{$logs_dir}/requests.log";
	$flames_path    = "{$logs_dir}/flames.log";
	$errors_path    = "{$logs_dir}/errors.log";

	if ( ! \is_dir( $logs_dir ) ) {
		@\mkdir( $logs_dir, 0755, true );
	}

	$num_partitions   = (int) ( $config['num_partitions'] ?? 4 );
	$segment_size     = (int) ( $config['segment_size'] ?? ( 16 * 1024 * 1024 ) );
	$num_segments     = (int) ( $config['num_segments'] ?? 8 );
	$max_lifespan     = (int) ( $config['max_lifespan'] ?? 86400 );
	$memcache_servers = $config['memcache_servers'] ?? \Newspack_Event_Logger_Nodes\Memcached_Cache::DEFAULT_SERVERS;
	if ( ! \is_array( $memcache_servers ) ) {
		$memcache_servers = \Newspack_Event_Logger_Nodes\Memcached_Cache::DEFAULT_SERVERS;
	}

	// Memcache + Stats_Store for this partition. FlameBuilder pushes 9-namespace
	// sums into memcache; PerformanceControllerBase reads them on dashboards.
	$cache       = new \Newspack_Event_Logger_Nodes\Memcached_Cache( $memcache_servers );
	$stats_store = new \Newspack_Event_Logger_Nodes\Stats_Store( $cache, $partition, $max_lifespan );

	// --- Output Partitions (requests.log, flames.log, errors.log) -----------
	// Each is its own per-partition append-only log with companion .idx.
	$requests_log = new \Newspack_Nodes\Partition( $requests_path, $partition, $segment_size, $num_segments, $max_lifespan );
	$requests_log->name( "requests-log.p{$partition}" );

	$flames_log = new \Newspack_Nodes\Partition( $flames_path, $partition, $segment_size, $num_segments, $max_lifespan );
	$flames_log->name( "flames-log.p{$partition}" );

	$errors_log = new \Newspack_Nodes\Partition( $errors_path, $partition, $segment_size, $num_segments, $max_lifespan );
	$errors_log->name( "errors-log.p{$partition}" );

	// --- FlameBuilder (consumes completed requests) -------------------------
	$flame_builder = new \Newspack_Event_Logger_Nodes\FlameBuilder();
	$flame_builder->name( "flame-builder.p{$partition}" );
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

	// --- Completed-request fan-out: requests.log + FlameBuilder -------------
	$completed_fanout = new \Newspack_Nodes\Tee();
	$completed_fanout->name( "completed-request-fanout.p{$partition}" );
	$completed_fanout->sink( $ci );
	$completed_fanout->connect_node( "requests-log.p{$partition}" );
	$completed_fanout->connect_node( "flame-builder.p{$partition}" );

	// --- RequestBuilder -----------------------------------------------------
	$request_builder = new \Newspack_Event_Logger_Nodes\RequestBuilder();
	$request_builder->name( "request-builder.p{$partition}" );
	$request_builder->sink( $completed_fanout );
	$request_builder->set_errors_sink( $errors_log );

	// --- JobRouter + JobWorker ----------------------------------------------
	$job_worker = new \Newspack_Event_Logger_Nodes\JobWorker();
	$job_worker->name( "job-worker.p{$partition}" );

	$job_router = new \Newspack_Event_Logger_Nodes\JobRouter();
	$job_router->name( "job-router.p{$partition}" );
	$job_router->sink( $job_worker );

	// --- Firehose fan-out: RequestBuilder + JobRouter -----------------------
	$firehose_fanout = new \Newspack_Nodes\Tee();
	$firehose_fanout->name( "firehose-fanout.p{$partition}" );
	$firehose_fanout->sink( $ci );
	$firehose_fanout->connect_node( "request-builder.p{$partition}" );
	$firehose_fanout->connect_node( "job-router.p{$partition}" );

	// --- Inputs: Tail per source --------------------------------------------
	$firehose_in = new \Newspack_Nodes\Tail( $firehose_path, 'line-buffered' );
	$firehose_in->name( "firehose-in.p{$partition}" );
	$firehose_in->sink( $firehose_fanout );

	$jobintake_in = new \Newspack_Nodes\Tail( $jobintake_path, 'line-buffered' );
	$jobintake_in->name( "jobintake-in.p{$partition}" );
	$jobintake_in->sink( $job_router );

	return [
		'partition'         => $partition,
		'firehose_in'       => $firehose_in,
		'jobintake_in'      => $jobintake_in,
		'firehose_fanout'   => $firehose_fanout,
		'request_builder'   => $request_builder,
		'completed_fanout'  => $completed_fanout,
		'flame_builder'     => $flame_builder,
		'job_router'        => $job_router,
		'job_worker'        => $job_worker,
		'requests_log'      => $requests_log,
		'flames_log'        => $flames_log,
		'errors_log'        => $errors_log,
		'stats_store'       => $stats_store,
	];
};
