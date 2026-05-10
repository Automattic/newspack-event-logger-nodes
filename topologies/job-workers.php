<?php
/**
 * Job-workers topology — group 3 of 3.
 *
 *   Consumer (jobs.log) ─→ JobWorker
 *
 * Tails the `jobs.log` partition produced by JobRouter in the
 * `firehose-workers` group. JobWorker dispatches each line to the registered
 * `newspack_nodes/job_handlers` (and `newspack_nodes/remote_job_handlers` on
 * the hub) — per-job try/catch, between-jobs gc_collect_cycles + wp_cache_flush
 * discipline.
 *
 * Wiring: every node sinks to `$interpreter` (automatic via `make_node`),
 * named routing via `connect_node`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return static function ( \Newspack_Nodes\CommandInterpreter $interpreter, int $partition ): array {
	$config    = \Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase::load_config();
	$base_dir  = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
	$logs_dir  = "{$base_dir}/logs";
	$jobs_path = "{$logs_dir}/jobs.log";

	if ( ! \is_dir( $logs_dir ) ) {
		@\mkdir( $logs_dir, 0755, true );
	}

	// --- JobWorker (terminal: dispatches to handlers, no connect_node) ------
	$job_worker = $interpreter->make_node( 'JobWorker', 'job-worker' );

	// --- Input: Consumer of jobs.log ----------------------------------------
	$jobs_offsets = "{$base_dir}/offsets/jobs.p{$partition}";
	$jobs_in      = $interpreter->make_node( 'Consumer', 'jobs:consumer', $jobs_path, $partition, $jobs_offsets );
	$jobs_in->connect_node( 'job-worker' );

	return [
		'partition'  => $partition,
		'jobs_in'    => $jobs_in,
		'job_worker' => $job_worker,
	];
};
