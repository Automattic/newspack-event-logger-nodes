<?php
/**
 * Remote_Job_Rewrite_Node — hub-side `k:"job"` -> `k:"remote_job"` rewrite on
 * aggregated firehose entries.
 *
 * The rewrite is a graph node the hub topology wires between the aggregated
 * source and the jobs partition, keeping the substrate Remote_Source / SSE_In
 * application-agnostic. A spoke-produced `k:"job"` entry, once
 * aggregated on the hub, becomes `k:"remote_job"` so it dispatches through the
 * `newspack_nodes/remote_job_handlers` filter (centrally on the hub) rather than
 * locally. Non-`job` entries and non-array VALUEs pass through untouched.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Partition_Node;

\defined( 'ABSPATH' ) || exit;

class Remote_Job_Rewrite_Node extends Node {

	/**
	 * Rewrite an aggregated firehose entry's kind in place, then forward.
	 *
	 * VALUE is the firehose entry as an array (Log_Manager writes dicts;
	 * Request_Builder_Node reads them). When it's a `k:"job"` entry, flip the
	 * kind to `remote_job`. The rewrite can only grow the message by the
	 * 6-byte `job` -> `remote_job` delta, but the post-rewrite packed size is
	 * still guarded against the PIPE_BUF cap a downstream Partition write would
	 * enforce — an oversized line is dropped (rate-limited) rather than
	 * forwarded to corrupt the segment.
	 *
	 * @api Entry point invoked by the substrate Router / upstream sink.
	 * @param array<int, mixed> $message Message reference; VALUE is the entry array.
	 */
	public function fill( array $message ): void {
		$value = $message[ Message::VALUE ];
		if ( \is_array( $value ) && 'job' === ( $value['k'] ?? null ) ) {
			$value['k']                = 'remote_job';
			$message[ Message::VALUE ] = $value;
			if ( Message::packed_size( $message ) > Partition_Node::MAX_LINE_SIZE ) {
				Core::print_less_often( 'Remote_Job_Rewrite: dropping entry > ' . Partition_Node::MAX_LINE_SIZE . ' bytes after rewrite' );
				return;
			}
		}
		parent::fill( $message );
	}

	/**
	 * Topology console manifest: a pass-through transform with a sink target.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Transform',
			'description' => 'Rewrites aggregated firehose k:"job" entries to k:"remote_job" so they dispatch on the hub.',
			'arguments'   => [],
			'commands'    => [],
			'has_target'  => true,
		] );
	}
}
