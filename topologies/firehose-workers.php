<?php
/**
 * Firehose-workers topology — group 1 of 3.
 *
 * Shape depends on which application flags are enabled (gated_by guarantees
 * at least one is true, otherwise the supervisor wouldn't spawn this fleet):
 *
 *   enable_workers && enable_jobs (default):
 *     Tail (firehose.log)  ──┐
 *                            ├─→ Tee → RequestBuilder ─→ Partition (requests.log)
 *                            │                       └→ Partition (errors.log)
 *                            └─→ JobRouter ─→ Partition (jobs.log)
 *     Tail (jobintake.log) ───────────→ JobRouter
 *
 *   enable_workers only:
 *     Tail (firehose.log) → RequestBuilder ─→ Partition (requests.log)
 *                                          └→ Partition (errors.log)
 *
 *   enable_jobs only:
 *     Tail (firehose.log)  ──┐
 *                            ├─→ JobRouter ─→ Partition (jobs.log)
 *     Tail (jobintake.log) ──┘
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
	// Application Config (not PerformanceControllerBase::load_config) because
	// this topology reads application-only keys (enable_workers, enable_jobs)
	// alongside substrate keys. Config::load_config('full') merges file
	// overlays + WP options + the schema, returning real PHP bools for the
	// gates so `=== true` comparisons are safe.
	$config         = \Newspack_Event_Logger_Nodes\Config::load_config( 'full' );
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

	$enable_workers = true === ( $config['enable_workers'] ?? false );
	$enable_jobs    = true === ( $config['enable_jobs'] ?? false );

	$requests_log    = null;
	$errors_log      = null;
	$jobs_log        = null;
	$request_builder = null;
	$job_router      = null;
	$firehose_fanout = null;
	$jobintake_in    = null;

	// --- Workers branch: RequestBuilder + requests/errors partitions --------
	// Completed-request JSON regularly exceeds PIPE_BUF (4KB) for pages with
	// many timed hooks; same for errors with stack traces. allow_large_writes
	// lifts the per-message cap to 10MB and acquires a per-Partition lock.
	if ( $enable_workers ) {
		$requests_log = $interpreter->make_node( 'Partition', 'requests:partition', $requests_path, $partition, $segment_size, $num_segments, $max_lifespan );
		$requests_log->allow_large_writes();
		// Custom index entries — RequestBuilder's per-URL drilldown reads back
		// `{rid, url_hash, segment_id, offset, length, status_code, duration_ms,
		// peak_mb, error_status, timestamp, method}` via `parse_request_index`.
		$requests_log->with_index(
			static fn ( $line, $position, &$data = null ) => \Newspack_Event_Logger_Nodes\RequestBuilder::format_index_entry( $line, $position, $data )
		);
		$errors_log = $interpreter->make_node( 'Partition', 'errors:partition', $errors_path, $partition, $segment_size, $num_segments, $max_lifespan );
		$errors_log->allow_large_writes();

		$request_builder = $interpreter->make_node( 'RequestBuilder', 'request-builder' );
		$request_builder->connect_node( 'requests:partition' );
		$request_builder->set_errors_target( 'errors:partition' );
	}

	// --- Jobs branch: JobRouter + jobs partition + jobintake consumer -------
	if ( $enable_jobs ) {
		$jobs_log = $interpreter->make_node( 'Partition', 'jobs:partition', $jobs_path, $partition, $segment_size, $num_segments, $max_lifespan );
		$jobs_log->allow_large_writes();

		$job_router = $interpreter->make_node( 'JobRouter', 'job-router' );
		$job_router->connect_node( 'jobs:partition' );
	}

	// --- Firehose input wiring ----------------------------------------------
	// Both branches: Tee fan-out. Single branch: connect directly to the one
	// downstream node so we don't pay the Tee allocation/dispatch overhead.
	$firehose_offsets = "{$base_dir}/offsets/firehose.p{$partition}";
	$firehose_in      = $interpreter->make_node( 'Consumer', 'firehose:consumer', $firehose_path, $partition, $firehose_offsets );

	if ( $enable_workers && $enable_jobs ) {
		$firehose_fanout = $interpreter->make_node( 'Tee', 'firehose:tee' );
		$firehose_fanout->connect_node( 'request-builder' );
		$firehose_fanout->connect_node( 'job-router' );
		$firehose_in->connect_node( 'firehose:tee' );
	} elseif ( $enable_workers ) {
		$firehose_in->connect_node( 'request-builder' );
	} else {
		$firehose_in->connect_node( 'job-router' );
	}

	// --- jobintake input: only relevant when JobRouter is wired -------------
	if ( $enable_jobs ) {
		$jobintake_offsets = "{$base_dir}/offsets/jobintake.p{$partition}";
		$jobintake_in      = $interpreter->make_node( 'Consumer', 'jobintake:consumer', $jobintake_path, $partition, $jobintake_offsets );
		$jobintake_in->connect_node( 'job-router' );
	}

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
