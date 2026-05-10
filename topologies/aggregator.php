<?php
/**
 * Aggregator topology (hub side).
 *
 * Returns a closure that builds the hub-side aggregation graph:
 *
 *   StreamMerger  ─→  Topic (firehose.log)
 *
 * StreamMerger pulls remote firehoses via SSE (one cURL handle per remote,
 * driven from a shared multi-handle) and fans them into the local Topic.
 * The local Topic distributes by KEY (URL hash) across partitions so workers
 * downstream can independently process their slice.
 *
 * Per spec section 4 (Application Node subclasses): StreamMerger does NOT
 * perform the `k:"job"` → `k:"remote_job"` rewrite itself; the rewrite is a
 * registered filter applied during ingest.
 *
 * Always single-partition (the StreamMerger is itself a fan-in; partition
 * count comes from the destination Topic, not the merger).
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

return static function ( \Newspack_Nodes\CommandInterpreter $interpreter, int $partition ): array {
	$config         = \Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase::load_config();
	$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
	$logs_dir       = "{$base_dir}/logs";
	$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
	$segment_size   = (int) ( $config['segment_size'] ?? ( 16 * 1024 * 1024 ) );
	$num_segments   = (int) ( $config['num_segments'] ?? 8 );
	$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );
	$firehose_dir   = "{$logs_dir}/firehose.log";

	if ( ! \is_dir( $firehose_dir ) ) {
		@\mkdir( $firehose_dir, 0755, true );
	}

	// Destination topic — multi-partition, KEY-routed.
	$firehose_topic = $interpreter->make_node(
		'Topic',
		'firehose:topic',
		$firehose_dir,
		\max( 1, $num_partitions ),
		$segment_size,
		$num_segments,
		$max_lifespan
	);

	// StreamMerger named-routes to the firehose Topic.
	$stream_merger = $interpreter->make_node( 'StreamMerger', 'stream-merger' );
	$stream_merger->connect_node( 'firehose:topic' );

	return [
		'partition'      => $partition,
		'firehose_topic' => $firehose_topic,
		'stream_merger'  => $stream_merger,
	];
};
