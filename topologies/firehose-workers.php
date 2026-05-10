<?php
/**
 * Firehose-workers topology — group 1 of 3.
 *
 *   Tail (firehose.log)  ──┐
 *                          ├─→ Tee → RequestBuilder ─→ Partition (requests.log)   [→ request-workers]
 *                          │                       └→ Partition (errors.log)
 *                          ├─→ JobRouter ─→ Partition (jobs.log)                  [→ job-workers]
 *   Tail (jobintake.log) ──┘
 *
 * Wiring follows the canonical Tachikoma pattern: every node sinks to the
 * CommandInterpreter (which `make_node` does automatically), and inter-node
 * routing happens by NAME via `connect_node($target)` — `_router` then
 * dispatches based on the message's TO field.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return static function ( \Newspack_Nodes\CommandInterpreter $interpreter, int $partition ): array {
	$config         = \Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase::load_config();
	$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
	$logs_dir       = "{$base_dir}/logs";
	$firehose_path  = "{$logs_dir}/firehose.log";
	$jobintake_path = "{$logs_dir}/jobintake.log";
	$requests_path  = "{$logs_dir}/requests.log";
	$errors_path    = "{$logs_dir}/errors.log";
	$jobs_path      = "{$logs_dir}/jobs.log";

	if ( ! \is_dir( $logs_dir ) ) {
		@\mkdir( $logs_dir, 0755, true );
	}

	$segment_size = (int) ( $config['segment_size'] ?? ( 16 * 1024 * 1024 ) );
	$num_segments = (int) ( $config['num_segments'] ?? 8 );
	$max_lifespan = (int) ( $config['max_lifespan'] ?? 86400 );

	// --- Output Partitions (terminal: no connect_node) ----------------------
	// Completed-request JSON regularly exceeds PIPE_BUF (4KB) for pages with
	// many timed hooks; same for errors with stack traces. allow_large_writes
	// lifts the per-message cap to 10MB and acquires a per-Partition lock.
	$requests_log = $interpreter->make_node( 'Partition', 'requests:partition', $requests_path, $partition, $segment_size, $num_segments, $max_lifespan );
	$requests_log->allow_large_writes();
	// Custom index entries — RequestBuilder's per-URL drilldown reads back
	// `{rid, url_hash, segment_id, offset, length, status_code, duration_ms,
	// peak_mb, error_status, timestamp, method}` via `parse_request_index`.
	$requests_log->with_index(
		static fn ( $line, $position, &$data = null ) => \Newspack_Event_Logger_Nodes\RequestBuilder::format_index_entry( $line, $position, $data )
	);
	$errors_log   = $interpreter->make_node( 'Partition', 'errors:partition',   $errors_path,   $partition, $segment_size, $num_segments, $max_lifespan );
	$errors_log->allow_large_writes();
	$jobs_log     = $interpreter->make_node( 'Partition', 'jobs:partition',     $jobs_path,     $partition, $segment_size, $num_segments, $max_lifespan );
	$jobs_log->allow_large_writes();

	// --- RequestBuilder: routes named to requests-log -----------------------
	$request_builder = $interpreter->make_node( 'RequestBuilder', 'request-builder' );
	$request_builder->connect_node( 'requests:partition' );
	$request_builder->set_errors_target( 'errors:partition' );

	// --- JobRouter: routes named to jobs-log --------------------------------
	$job_router = $interpreter->make_node( 'JobRouter', 'job-router' );
	$job_router->connect_node( 'jobs:partition' );

	// --- Firehose fan-out (Tee multi-target) --------------------------------
	$firehose_fanout = $interpreter->make_node( 'Tee', 'firehose:tee' );
	$firehose_fanout->connect_node( 'request-builder' );
	$firehose_fanout->connect_node( 'job-router' );

	// --- Inputs: Consumers tail source logs and named-route to next node ----
	$firehose_offsets  = "{$base_dir}/offsets/firehose.p{$partition}";
	$jobintake_offsets = "{$base_dir}/offsets/jobintake.p{$partition}";

	$firehose_in = $interpreter->make_node( 'Consumer', 'firehose:consumer', $firehose_path, $partition, $firehose_offsets );
	$firehose_in->connect_node( 'firehose:tee' );

	$jobintake_in = $interpreter->make_node( 'Consumer', 'jobintake:consumer', $jobintake_path, $partition, $jobintake_offsets );
	$jobintake_in->connect_node( 'job-router' );

	return [
		'partition'       => $partition,
		'firehose_in'     => $firehose_in,
		'jobintake_in'    => $jobintake_in,
		'firehose_fanout' => $firehose_fanout,
		'request_builder' => $request_builder,
		'job_router'      => $job_router,
		'requests_log'    => $requests_log,
		'errors_log'      => $errors_log,
		'jobs_log'        => $jobs_log,
	];
};
