<?php
/**
 * Remote_Job_Rewrite_Node — hub-side `k:"job"` -> `k:"remote_job"` rewrite on
 * aggregated firehose entries.
 *
 * The rewrite is a graph node the `aggregator` topology wires between the
 * per-spoke substrate `Remote_Source_Node`s and the firehose `Topic`, keeping
 * the substrate Remote_Source / SSE_In application-agnostic.
 *
 * `Job_Worker_Node` selects its handler map off the entry kind alone —
 * `newspack_nodes/job_handlers` for `job`, `newspack_nodes/remote_job_handlers`
 * for `remote_job` — so the kind is the only thing telling a spoke's work apart
 * from the hub's own. `Job_Router_Node` carries that kind verbatim into the
 * job record, so without the rewrite the hub takes an aggregated spoke entry
 * for one of its own and runs it locally. The spoke cannot label the entry
 * itself: its own `Job_Worker_Node` reads that same firehose line and
 * dispatches `job` locally, so the kind is relative to the reader and only the
 * hub's ingress can set it. Non-`job` entries and non-array VALUEs pass
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
 * The node takes no constructor arguments, exposes no verbs and adds no state
 * of its own, so the topology makes a single `remote-job-rewrite` and every
 * spoke's Remote_Source connects to that one instance.
 */
class Remote_Job_Rewrite_Node extends Node {

	/**
	 * Rewrite an aggregated firehose entry's kind, then forward the message.
	 *
	 * VALUE is the firehose entry as an associative array — the shape
	 * `Log_Manager` writes and `Request_Builder_Node` reads. When it is a
	 * `k:"job"` entry, flip the kind to `remote_job` and leave every other key
	 * as it stands, `id` included, so the hub keeps per-job identity. The
	 * rewrite can only grow the message by the 7-byte `job` -> `remote_job`
	 * delta, so it does no size check of its own: the Topic's Partition
	 * enforces its own line cap and drops an oversize record there.
	 *
	 * `parent::fill()` stamps TO from the target when TO is empty, so the
	 * topology's `connect_node` line is what addresses the forwarded entry.
	 *
	 * @api Entry point invoked by the substrate Router / upstream sink.
	 * @param array<int,mixed> $message Message reference; VALUE is the entry array.
	 * @throws \RuntimeException When no sink is wired, which the first entry surfaces.
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
	 * @return array<string,mixed> Palette entry and node configuration form.
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
