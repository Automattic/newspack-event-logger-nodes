<?php
/**
 * Job Router
 *
 * Pure router: extracts job-shaped entries from firehose.log and jobintake.log,
 * normalizes them to a single shape, and forwards to its target (jobs.log).
 * The actual handler dispatch happens later in JobWorker, which consumes
 * jobs.log in a separate worker pool.
 *
 * Why split: keeping route + execute in different processes lets the
 * execute side respawn and gc/cache-flush without dropping firehose
 * throughput, and lets the executor live-load handler registrations from
 * `newspack_nodes/{job,remote_job}_handlers` filters without coordinating
 * with the firehose-workers fleet.
 *
 * Output shape (one wire form for jobs.log, regardless of source) — the kind
 * stays under `k`, the same field Job_Intake writes and Job_Worker dispatches
 * on, so nothing downstream has to rename it:
 *   { k, handler, parameters, ts }
 *
 * SECURITY:
 * - Handler name must match HANDLER_NAME_PATTERN before reaching disk.
 * - Parameters must be array-shaped or absent.
 * - Staleness is the Age_Sieve's job: the topology wires
 *   `job-router → jobs:sieve (Age_Sieve) → jobs:partition`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Router_Node extends Node {
	use \Newspack_Nodes\Schema_Reflection;

	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	public const KIND_JOB        = 'job';
	public const KIND_REMOTE_JOB = 'remote_job';

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

		// First-class identity: the id lives IN the job body (jobstats key).
		$id = Core::as_string( $body['id'] ?? '' );
		if ( '' !== $id ) {
			$normalized['id'] = $id;
		}

		// Forward normalized VALUE; Node::fill stamps TO from $this->target.
		$message[ Message::VALUE ] = $normalized;
		parent::fill( $message );
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return [
			'category'    => 'Routing',
			'description' => 'Normalizes nested or flat job entries and routes them to jobs:partition.',
			'arguments'   => [],
			'commands'    => [],
		];
	}
}
