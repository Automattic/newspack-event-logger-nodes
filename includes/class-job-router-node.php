<?php
/**
 * Job Router
 *
 * Pure router: it extracts job-shaped entries from the two ingress logs —
 * firehose.p0 (small runtime jobs, body nested under `m`) and jobintake.log
 * (large jobs written by `\Newspack_Nodes\Job_Intake`, body flat) — normalizes
 * both to one shape, and forwards them to its target. Handler dispatch happens
 * later, in the substrate's `\Newspack_Nodes\Job_Worker_Node`, which consumes
 * jobs.log in a separate worker pool.
 *
 * Why split route from execute: two processes let the execute side respawn and
 * flush its caches without costing firehose throughput, and let the executor
 * live-load handler registrations from the `newspack_nodes/job_handlers` and
 * `newspack_nodes/remote_job_handlers` filters without coordinating with the
 * firehose-worker fleet.
 *
 * Output shape (one wire form for jobs.log, regardless of source) — the kind
 * stays under `k`, the same field Job_Intake writes and Job_Worker dispatches
 * on, so nothing downstream has to rename it:
 *   { k, handler, parameters, ts }, plus `id` when the body carries one, plus
 *   whichever of `Job_Intake::DISPATCH_FIELDS` the body carries. Those last
 *   are load-bearing, not decoration: Job_Worker reads them back to decide a
 *   retry and to settle a batch, so dropping them disables both silently.
 *
 * SECURITY:
 * - Handler name must match HANDLER_NAME_PATTERN before reaching disk.
 * - Parameters must be array-shaped or absent.
 * - Staleness is the Age_Sieve's job: `job-router.tsl` wires
 *   `job-router → jobs:sieve (Age_Sieve) → jobs:partition`, so this node holds
 *   no age threshold and takes no arguments.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Job_Intake;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes job entries from firehose.p0 and jobintake.log onto jobs.log.
 *
 * Stateless and argument-free: every message is judged on its own contents, and
 * the sending node's name never gates the decision. Anything that is not a
 * well-formed job — wrong type flags, non-array VALUE, a kind other than
 * `job`/`remote_job`, a handler name failing HANDLER_NAME_PATTERN, or non-array
 * parameters — is dropped here and never reaches disk.
 */
class Job_Router_Node extends Node {
	use \Newspack_Nodes\Schema_Reflection;

	/** Handler names: a leading letter then up to 63 word/dash characters. Mirrors `\Newspack_Nodes\Job_Worker_Node::HANDLER_NAME_PATTERN`. */
	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/** Kind dispatched locally on every node, against `newspack_nodes/job_handlers`. */
	public const KIND_JOB = 'job';

	/** Kind dispatched on the hub only, against `newspack_nodes/remote_job_handlers`. */
	public const KIND_REMOTE_JOB = 'remote_job';

	/**
	 * Normalize one ingress entry and forward it, or drop it.
	 *
	 * Firehose entries wrap the job body under `m`; jobintake entries are flat,
	 * so the entry is its own body. Two fields are read from the ENTRY rather
	 * than the body, and the distinction matters: the kind comes from the entry
	 * `k` so the hub's `Remote_Job_Rewrite_Node` rewrite of `job` → `remote_job`
	 * wins over a stale `type` left in the body, and `ts` falls back to the entry
	 * timestamp only when the body carries none. The `id` is read from the body,
	 * where producers put it, and is omitted when empty so `Job_Worker_Node` keys
	 * jobstats by handler alone.
	 *
	 * The timestamp is copied verbatim, never coerced — a garbage `ts` travels
	 * through as data for the downstream reader to zero out.
	 *
	 * @api Entry point invoked by the substrate Router / upstream sink.
	 * @param array<int,mixed> $message Message whose VALUE is the ingress entry array.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		/** @var int $type_flags */
		$type_flags = $message[ Message::TYPE ];
		if ( ! ( $type_flags & Message::TM_STRUCT ) ) {
			return;
		}

		$entry = $message[ Message::VALUE ];
		if ( ! \is_array( $entry ) ) {
			return;
		}

		// Pluck the job body. Firehose wraps it under `m`; jobintake is flat.
		$body = \is_array( $entry['m'] ?? null ) ? $entry['m'] : $entry;

		// Dispatch kind = entry `k` (not body) so hub job→remote_job wins.
		$type = Core::as_string( $entry['k'] ?? '' );
		if ( self::KIND_JOB !== $type && self::KIND_REMOTE_JOB !== $type ) {
			return;
		}

		$handler = Core::as_string( $body['handler'] ?? '' );
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			$this->drop_message( $message, "invalid handler name: {$handler}" );
			return;
		}

		$parameters = $body['parameters'] ?? [];
		if ( ! \is_array( $parameters ) ) {
			$this->drop_message( $message, "{$handler} has non-array parameters" );
			return;
		}

		$raw_timestamp = $body['ts'] ?? $entry['ts'] ?? null;
		$normalized    = [
			'k'          => $type,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => $raw_timestamp,
		];

		// Job_Worker reads these back — dropping them disabled retry.
		foreach ( Job_Intake::DISPATCH_FIELDS as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$normalized[ $field ] = $body[ $field ];
			}
		}

		// First-class identity: the id lives IN the job body (jobstats key).
		$id = Core::as_string( $body['id'] ?? '' );
		if ( '' !== $id ) {
			$normalized['id'] = $id;
		}

		// Forward normalized VALUE; Node::fill stamps TO from $this->target.
		$message[ Message::VALUE ] = $normalized;
		parent::fill( $message );
	}

	/**
	 * Topology console manifest: a router with a sink target and no arguments.
	 *
	 * Age gating lives in the Age_Sieve downstream, so the empty `arguments` list
	 * is load-bearing — a stale-timeout argument here would be a regression.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 * @return array<string,mixed> Schema consumed by `Command_Interpreter_Node`.
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Routing',
			'description' => 'Normalizes nested or flat job entries and routes them to jobs:partition.',
			'arguments'   => [],
			'commands'    => [],
		];
	}
}
