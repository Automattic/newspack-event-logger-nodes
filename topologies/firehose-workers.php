<?php
/**
 * Firehose-workers topology.
 *
 * Returns a closure that builds the per-partition firehose worker graph:
 *
 *   Tail (firehose.log)  ─┐
 *                          ├─→ Tee → RequestBuilder
 *                          │       └→ JobRouter → JobWorker
 *   Tail (jobintake.log) ─┘
 *
 * Spec reference: section 5 (Multi-input handler pattern). The Tail/Consumer
 * inputs stamp FROM at the I/O boundary so JobRouter can branch on source
 * (firehose entries vs jobintake entries) once the multi-input dispatch lands.
 *
 * The closure receives:
 *   - CommandInterpreter $ci  Graph-building shell vocabulary.
 *   - int                $partition  Which partition this worker owns.
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
	$config        = \Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase::load_config();
	$base_dir      = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
	$logs_dir      = "{$base_dir}/logs";
	$firehose_path = "{$logs_dir}/firehose.log";
	$jobintake_path = "{$logs_dir}/jobintake.log";

	if ( ! \is_dir( $logs_dir ) ) {
		@\mkdir( $logs_dir, 0755, true );
	}

	// Application nodes — instantiate directly. Each is wired into the runtime
	// graph below; CommandInterpreter's auto-sink is the implicit fallback.
	$request_builder = new \Newspack_Event_Logger_Nodes\RequestBuilder();
	$request_builder->name( "request-builder.p{$partition}" );

	$job_router = new \Newspack_Event_Logger_Nodes\JobRouter();
	$job_router->name( "job-router.p{$partition}" );

	$job_worker = new \Newspack_Event_Logger_Nodes\JobWorker();
	$job_worker->name( "job-worker.p{$partition}" );
	$job_router->sink( $job_worker );

	// Fan-out: one input feeds RequestBuilder AND JobRouter.
	$firehose_fanout = new \Newspack_Nodes\Tee();
	$firehose_fanout->name( "firehose-fanout.p{$partition}" );
	$firehose_fanout->sink( $ci ); // Replies go through CI's sink (the router).
	$firehose_fanout->connect_node( "request-builder.p{$partition}" );
	$firehose_fanout->connect_node( "job-router.p{$partition}" );

	// Inputs: Tail per source. Buffer mode line-buffered so each entry arrives
	// as its own message — matches the firehose JSONL contract.
	$firehose_in = new \Newspack_Nodes\Tail( $firehose_path, 'line-buffered' );
	$firehose_in->name( "firehose-in.p{$partition}" );
	$firehose_in->sink( $firehose_fanout );

	$jobintake_in = new \Newspack_Nodes\Tail( $jobintake_path, 'line-buffered' );
	$jobintake_in->name( "jobintake-in.p{$partition}" );
	$jobintake_in->sink( $job_router );

	return [
		'partition'      => $partition,
		'firehose_in'    => $firehose_in,
		'jobintake_in'   => $jobintake_in,
		'firehose_fanout' => $firehose_fanout,
		'request_builder' => $request_builder,
		'job_router'     => $job_router,
		'job_worker'     => $job_worker,
	];
};
