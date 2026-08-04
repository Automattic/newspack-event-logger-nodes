<?php
/**
 * Remote_Job_Rewrite_Node — hub-side `k:"job"` -> `k:"remote_job"` rewrite on
 * aggregated firehose entries.
 *
 * The rewrite is a graph node the `aggregator` topology wires between the
 * per-spoke substrate `Remote_Source_Node`s and the firehose `Topic`, keeping
 * the substrate Remote_Source / SSE_In application-agnostic. A spoke-produced
 * `k:"job"` entry, once aggregated on the hub, becomes `k:"remote_job"` so it
 * dispatches through the `newspack_nodes/remote_job_handlers` filter (centrally
 * on the hub) rather than locally. Non-`job` entries and non-array VALUEs pass
 * through untouched.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

\defined( 'ABSPATH' ) || exit;

/**
 * Stateless pass-through transform: one `k` rewrite, then the base forward.
 *
 * The node takes no constructor arguments, exposes no verbs, and holds no
 * state, so a hub may wire one instance behind every spoke's Remote_Source.
 */
class Remote_Job_Rewrite_Node extends Node {

	/**
	 * Rewrite an aggregated firehose entry's kind in place, then forward.
	 *
	 * VALUE is the firehose entry as an array (Log_Manager writes dicts;
	 * Request_Builder_Node reads them). When it's a `k:"job"` entry, flip the
	 * kind to `remote_job`. The rewrite can only grow the message by the 7-byte
	 * `job` -> `remote_job` delta, so it does no size check of its own: the
	 * Topic's Partition enforces its own line cap and drops an oversize record
	 * there.
	 *
	 * `parent::fill()` stamps TO from the target when TO is empty and throws
	 * when no sink is wired, so an unwired rewrite fails loudly on first entry.
	 *
	 * @api Entry point invoked by the substrate Router / upstream sink.
	 * @param array<int, mixed> $message Message reference; VALUE is the entry array.
	 */
	public function fill( array $message ): void {
		$value = $message[ Message::VALUE ];
		if ( \is_array( $value ) && 'job' === ( $value['k'] ?? null ) ) {
			$value['k']                = 'remote_job';
			$message[ Message::VALUE ] = $value;
		}
		parent::fill( $message );
	}

	/**
	 * Topology console manifest: a pass-through transform with a sink target.
	 *
	 * Merging over the base keeps `registrations` and `accepts_fill` inherited.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 * @return array<string, mixed> Palette entry and node configuration form.
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
